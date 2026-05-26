<?php declare(strict_types=1);

namespace SanderMuller\BoostCore\Commands;

use SanderMuller\BoostCore\Config\BoostConfigNotFoundException;
use SanderMuller\BoostCore\Skills\Command as BoostCommand;
use SanderMuller\BoostCore\Skills\Guideline;
use SanderMuller\BoostCore\Skills\Skill;
use SanderMuller\BoostCore\Sync\SyncEngine;
use Symfony\Component\Console\Input\InputInterface;
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
    protected function configure(): void
    {
        $this
            ->setName('boost:where')
            ->setDescription('Show every skill, guideline, and command grouped by its origin: host `.ai/`, scanned vendor packages, remote skill sources, and host overrides.');
        $this->addWorkingDirOption();
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $projectRoot = $this->resolveProjectRoot($input);

        try {
            $result = SyncEngine::default()->sync($projectRoot, checkOnly: true);
        } catch (BoostConfigNotFoundException $e) {
            $io->error($e->getMessage());

            return self::FAILURE;
        } catch (Throwable $e) {
            $io->error('boost:where failed: ' . $e->getMessage());

            return self::FAILURE;
        }

        $inspection = SyncEngine::default()->resolveForInspection($projectRoot);

        if ($inspection['skills'] === [] && $inspection['guidelines'] === [] && $inspection['commands'] === []) {
            $io->success('Nothing resolved. (Did you run `boost install`? Is `.ai/` populated and are vendors allowlisted in `boost.php`?)');

            return self::SUCCESS;
        }

        $remoteKeys = array_flip($inspection['remoteSourceKeys']);
        $scannedKeys = array_flip($inspection['scannedVendorKeys']);
        $shadowedBy = $this->shadowIndex($result->hostShadows);

        $this->renderCategory($io, 'SKILLS', '.ai/skills/ (host)', $this->groupSkillsByOrigin($inspection['skills']), $remoteKeys, $scannedKeys, $shadowedBy, 'skill');
        $this->renderCategory($io, 'GUIDELINES', '.ai/guidelines/ (host)', $this->groupGuidelinesByOrigin($inspection['guidelines']), $remoteKeys, $scannedKeys, [], 'guideline');
        $this->renderCategory($io, 'COMMANDS', '.ai/commands/ (host)', $this->groupCommandsByOrigin($inspection['commands']), $remoteKeys, $scannedKeys, [], 'command');

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
}
