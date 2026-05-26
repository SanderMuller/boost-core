<?php declare(strict_types=1);

namespace SanderMuller\BoostCore\Commands;

use SanderMuller\BoostCore\Config\BoostConfigNotFoundException;
use SanderMuller\BoostCore\Skills\Command as BoostCommand;
use SanderMuller\BoostCore\Skills\Guideline;
use SanderMuller\BoostCore\Skills\Skill;
use SanderMuller\BoostCore\Sync\InstalledPackages;
use SanderMuller\BoostCore\Sync\SyncEngine;
use SebastianBergmann\Diff\Differ;
use SebastianBergmann\Diff\Output\UnifiedDiffOutputBuilder;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use Throwable;

/**
 * `boost where` — list every skill, guideline, and command that would
 * land in agent dirs, grouped by origin (host `.ai/`, scanned vendor
 * package, remote skill source). Skills also surface host-vs-vendor
 * shadowing inline so consumers using `withAllowedVendors` + host
 * overrides can audit which copy actually ships.
 *
 * Resolution path is the same as `boost sync --check` — tag-filtered,
 * collision-resolved. Caller-injected vendors (the wrapper-package
 * pattern that `project-boost-laravel` uses for laravel/boost-bundled
 * skills) are NOT visible from this command — they are runtime-only
 * inputs to `SyncEngine::sync(injectedVendorSkills: ...)` and require
 * the wrapper package's own CLI surface to enumerate.
 */
final class WhereCommand extends BoostBaseCommand
{
    public function __construct(
        // Injection seam for tests — null means "read the real Composer
        // runtime via InstalledPackages::fromComposer()" inside
        // SyncEngine::default($this->injectedPackages). Production calls go through default().
        private readonly ?InstalledPackages $injectedPackages = null,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->setName('boost:where')
            ->setDescription('Show every skill, guideline, and command grouped by its origin: host `.ai/`, scanned vendor packages, remote skill sources, and host overrides.');
        $this->addWorkingDirOption();
        $this->addOption(
            'diff',
            null,
            InputOption::VALUE_REQUIRED,
            'For a single host skill that shadows an allowlisted vendor copy, print a unified diff between the host file and the vendor file. Pass the skill name as the value: `--diff=deploy`.',
        );
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $projectRoot = $this->resolveProjectRoot($input);

        $diffSkill = $input->getOption('diff');
        if (is_string($diffSkill) && $diffSkill !== '') {
            return $this->executeDiff($io, $projectRoot, $diffSkill);
        }

        try {
            $result = SyncEngine::default($this->injectedPackages)->sync($projectRoot, checkOnly: true);
            $inspection = SyncEngine::default($this->injectedPackages)->resolveForInspection($projectRoot);
        } catch (BoostConfigNotFoundException $e) {
            $io->error($e->getMessage());

            return self::FAILURE;
        } catch (Throwable $e) {
            $io->error('boost:where failed: ' . $e->getMessage());

            return self::FAILURE;
        }

        // Sync may have caught + converted errors (collisions, render
        // failures, remote-source issues) into `SyncResult::errors`
        // rather than throwing — surface them so the operator sees what
        // the live sync would surface, not a falsely-clean origin map.
        if ($result->hasErrors()) {
            foreach ($result->errors as $error) {
                $io->error($error);
            }

            return self::FAILURE;
        }

        if ($inspection['skills'] === [] && $inspection['guidelines'] === [] && $inspection['commands'] === []) {
            $io->success('Nothing resolved. (Did you run `boost install`? Is `.ai/` populated and are vendors allowlisted in `boost.php`?)');

            return self::SUCCESS;
        }

        $remoteKeys = array_flip($inspection['remoteSourceKeys']);
        $scannedSkillKeys = array_flip($inspection['scannedSkillVendorKeys']);
        $scannedGuidelineKeys = array_flip($inspection['scannedGuidelineVendorKeys']);
        $shadowedBy = $this->shadowIndex($result->hostShadows);

        // Per-category label inputs — keeps each section from
        // mislabeling an origin based on a sibling pipeline:
        //  - SKILLS: scanned skill vendors + remote sources.
        //  - GUIDELINES: scanned guideline vendors only (no remote-
        //    guideline pipeline exists today, so isRemote = false).
        //  - COMMANDS: Phase 1 host-only (vendor commands deferred to
        //    Phase 4 of agent-commands-sync), so both flags = false.
        $this->renderCategory($io, 'SKILLS', '.ai/skills/ (host)', $this->groupSkillsByOrigin($inspection['skills']), $remoteKeys, $scannedSkillKeys, $shadowedBy, 'skill');
        $this->renderCategory($io, 'GUIDELINES', '.ai/guidelines/ (host)', $this->groupGuidelinesByOrigin($inspection['guidelines']), [], $scannedGuidelineKeys, [], 'guideline');
        $this->renderCategory($io, 'COMMANDS', '.ai/commands/ (host)', $this->groupCommandsByOrigin($inspection['commands']), [], [], [], 'command');

        if ($result->hostShadows !== []) {
            $io->newLine();
            $io->note(sprintf(
                '%d host skill(s) shadow allowlisted-vendor copies. Listed inline with `(shadows <vendor>)`.',
                count($result->hostShadows),
            ));
        }

        return self::SUCCESS;
    }

    /**
     * @param  array<string, list<string>>  $byOrigin
     * @param  array<string, int>  $remoteKeys
     * @param  array<string, int>  $scannedKeys
     * @param  array<string, string>  $shadowedBy
     */
    private function renderCategory(
        SymfonyStyle $io,
        string $title,
        string $hostOrigin,
        array $byOrigin,
        array $remoteKeys,
        array $scannedKeys,
        array $shadowedBy,
        string $itemNoun,
    ): void {
        if ($byOrigin === []) {
            return;
        }

        $io->newLine();
        $io->writeln(sprintf('<fg=blue;options=bold>%s</>', $title));
        $io->writeln(str_repeat('═', mb_strlen($title)));

        ksort($byOrigin);
        foreach ($byOrigin as $origin => $names) {
            $isRemote = isset($remoteKeys[$origin]);
            $isVendor = isset($scannedKeys[$origin]);
            $io->newLine();
            $io->writeln($this->renderOrigin($origin, count($names), $isRemote, $isVendor, $hostOrigin, $itemNoun));
            sort($names);
            foreach ($names as $name) {
                $line = '  • ' . $name;
                if ($origin === $hostOrigin && isset($shadowedBy[$name])) {
                    $line .= sprintf(' <fg=gray>(shadows %s)</>', $shadowedBy[$name]);
                }

                $io->writeln($line);
            }
        }
    }

    /**
     * @param  list<Skill>  $skills
     * @return array<string, list<string>>
     */
    private function groupSkillsByOrigin(array $skills): array
    {
        $byOrigin = [];
        foreach ($skills as $skill) {
            $origin = $skill->sourceVendor ?? '.ai/skills/ (host)';
            $byOrigin[$origin] ??= [];
            $byOrigin[$origin][] = $skill->name;
        }

        return $byOrigin;
    }

    /**
     * @param  list<Guideline>  $guidelines
     * @return array<string, list<string>>
     */
    private function groupGuidelinesByOrigin(array $guidelines): array
    {
        $byOrigin = [];
        foreach ($guidelines as $guideline) {
            $origin = $guideline->sourceVendor ?? '.ai/guidelines/ (host)';
            $byOrigin[$origin] ??= [];
            $byOrigin[$origin][] = $guideline->name;
        }

        return $byOrigin;
    }

    /**
     * @param  list<BoostCommand>  $commands
     * @return array<string, list<string>>
     */
    private function groupCommandsByOrigin(array $commands): array
    {
        $byOrigin = [];
        foreach ($commands as $command) {
            $origin = $command->sourceVendor ?? '.ai/commands/ (host)';
            $byOrigin[$origin] ??= [];
            $byOrigin[$origin][] = $command->name;
        }

        return $byOrigin;
    }

    /**
     * @param  list<array{skill: string, shadowedVendor: string}>  $shadows
     * @return array<string, string>
     */
    private function shadowIndex(array $shadows): array
    {
        $idx = [];
        foreach ($shadows as $shadow) {
            $idx[$shadow['skill']] = $shadow['shadowedVendor'];
        }

        return $idx;
    }

    private function renderOrigin(string $origin, int $count, bool $isRemote, bool $isVendor, string $hostOrigin, string $itemNoun): string
    {
        // A `<vendor>/<package>` key can legally belong to both a
        // scanned Composer vendor AND a `withRemoteSkills(...)` entry
        // (their item names must still be unique upstream), so the
        // label must name the mixed case rather than pick one side and
        // lie. The host case beats everything.
        $tag = match (true) {
            $origin === $hostOrigin => '<fg=green>host</>',
            $isRemote && $isVendor => '<fg=magenta>vendor+remote</>',
            $isRemote => '<fg=magenta>remote</>',
            $isVendor => '<fg=cyan>vendor</>',
            default => '<fg=yellow>unknown</>',
        };

        return sprintf('%s · %s · %d %s(s)', $tag, $origin, $count, $itemNoun);
    }

    /**
     * `boost where --diff=<name>` — show a unified diff between a host
     * skill that shadows an allowlisted vendor copy and the vendor's
     * upstream version. Answers "what exactly differs in this override"
     * so a maintainer can decide whether the host copy still earns its
     * keep vs upstream, or whether it can be dropped + replaced with
     * the vendor version.
     */
    private function executeDiff(SymfonyStyle $io, string $projectRoot, string $skillName): int
    {
        try {
            $paths = SyncEngine::default($this->injectedPackages)->resolveSkillShadowPaths($projectRoot, $skillName);
        } catch (BoostConfigNotFoundException $e) {
            $io->error($e->getMessage());

            return self::FAILURE;
        } catch (Throwable $e) {
            $io->error('boost:where --diff failed: ' . $e->getMessage());

            return self::FAILURE;
        }

        if ($paths === null) {
            $io->error(sprintf(
                'Skill `%s` is not shadowing an allowlisted vendor copy. Either the host skill is missing from `.ai/skills/`, or no allowlisted vendor publishes a skill of the same name. Run `boost where` (no flag) to see the resolved origin map.',
                $skillName,
            ));

            return self::FAILURE;
        }

        $hostContent = @file_get_contents($paths['hostPath']);
        $vendorContent = @file_get_contents($paths['vendorPath']);

        if ($hostContent === false || $vendorContent === false) {
            $io->error('Could not read one or both skill source files for diff.');

            return self::FAILURE;
        }

        // Identical content is a legitimate "no override needed" signal.
        if ($hostContent === $vendorContent) {
            $io->success(sprintf(
                'Host skill `%s` is byte-identical to the `%s` vendor copy. The override earns nothing — consider removing `%s` and shipping the vendor version.',
                $skillName,
                $paths['vendor'],
                $paths['hostPath'],
            ));

            return self::SUCCESS;
        }

        $io->writeln(sprintf('<fg=blue;options=bold>Shadow diff — `%s` (host) vs `%s` (vendor)</>', $skillName, $paths['vendor']));
        $io->writeln('<fg=gray>--- vendor: ' . $paths['vendorPath'] . '</>');
        $io->writeln('<fg=gray>+++ host:   ' . $paths['hostPath'] . '</>');
        $io->newLine();

        $differ = new Differ(new UnifiedDiffOutputBuilder('', false));
        $io->write($differ->diff($vendorContent, $hostContent));

        return self::SUCCESS;
    }
}
