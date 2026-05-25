<?php declare(strict_types=1);

namespace SanderMuller\BoostCore\Commands;

use SanderMuller\BoostCore\Config\BoostConfigNotFoundException;
use SanderMuller\BoostCore\Skills\Skill;
use SanderMuller\BoostCore\Sync\SyncEngine;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use Throwable;

/**
 * `boost where` — list every skill that would land in agent dirs, grouped
 * by its origin (host `.ai/`, scanned vendor package, remote skill source).
 * Also lists host-shadowed allowlisted-vendor skills so consumers using
 * `withAllowedVendors` + host overrides can audit which copy actually
 * ships.
 *
 * Resolution path is the same as `boost sync --check` — same tag filter,
 * same collision rules. Skills shadowed by the host show under the host
 * group with a `(shadows: <vendor>)` annotation.
 *
 * Caller-injected vendor skills (the companion-package pattern that
 * project-boost-laravel uses for laravel/boost-bundled skills) are NOT
 * visible from this command — they are runtime-only inputs to
 * `SyncEngine::sync(injectedVendorSkills: ...)` and require the wrapper
 * package's own CLI surface to enumerate.
 */
final class WhereCommand extends BoostBaseCommand
{
    protected function configure(): void
    {
        $this
            ->setName('boost:where')
            ->setDescription('Show every skill grouped by its origin: host `.ai/`, scanned vendor packages, remote skill sources, and host overrides.');
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

        $resolved = $this->collectResolvedSkills($projectRoot);
        if ($resolved === []) {
            $io->success('No skills resolved. (Did you run `boost install`? Is `.ai/skills/` populated and are vendors allowlisted in `boost.php`?)');

            return self::SUCCESS;
        }

        $byOrigin = $this->groupByOrigin($resolved);
        $shadowedBy = $this->shadowIndex($result->hostShadows);

        ksort($byOrigin);
        foreach ($byOrigin as $origin => $skills) {
            $io->section($this->renderOrigin($origin, count($skills)));
            sort($skills);
            foreach ($skills as $skillName) {
                $line = '  • ' . $skillName;
                if ($origin === '.ai/skills/ (host)' && isset($shadowedBy[$skillName])) {
                    $line .= sprintf(' <fg=gray>(shadows %s)</>', $shadowedBy[$skillName]);
                }

                $io->writeln($line);
            }
        }

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
     * Reuse the resolved-skill set from a check-mode sync via a second
     * pass through the engine. Cheaper alternative would be exposing
     * resolveSkills publicly, but the cost difference is negligible and
     * one private entry point keeps the contract tight.
     *
     * @return list<Skill>
     */
    private function collectResolvedSkills(string $projectRoot): array
    {
        return SyncEngine::default()->resolveSkillsForInspection($projectRoot);
    }

    /**
     * @param  list<Skill>  $skills
     * @return array<string, list<string>>
     */
    private function groupByOrigin(array $skills): array
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

    private function renderOrigin(string $origin, int $count): string
    {
        $tag = match (true) {
            $origin === '.ai/skills/ (host)' => '<fg=green>host</>',
            str_contains($origin, '/') => '<fg=cyan>vendor or remote</>',
            default => '<fg=yellow>unknown</>',
        };

        return sprintf('%s · %s · %d skill(s)', $tag, $origin, $count);
    }
}
