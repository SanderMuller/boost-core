<?php declare(strict_types=1);

namespace SanderMuller\BoostCore\Commands;

use SanderMuller\BoostCore\Config\BoostConfig;
use SanderMuller\BoostCore\Sync\InstalledPackages;
use SanderMuller\BoostCore\Sync\SubagentNameScanner;
use SanderMuller\BoostCore\Sync\SyncEngine;
use Symfony\Component\Console\Style\SymfonyStyle;
use Throwable;

/**
 * The `boost doctor` subagent reporter — extracted from {@see DoctorCommand},
 * like {@see RemoteSkillsReporter}, so the subagent diagnostics live in one
 * collaborator rather than growing the command class.
 *
 * Two things it surfaces, both quiet when there is nothing to say:
 *  - what a configured agent will NOT receive, because subagents are a Claude
 *    Code concept and every other target silently gets nothing;
 *  - definitions that share a `name`, which Claude Code resolves by filesystem
 *    read order.
 *
 * @internal
 */
final readonly class SubagentReporter
{
    /**
     * Report the subagent picture for this project: name overlaps on disk,
     * per-file load warnings, unsatisfiable `subagent:` demands, and how many
     * subagents a configured agent will NOT receive because it has no subagent
     * surface.
     *
     * Sync stays silent about this — a target that cannot use subagents emits
     * nothing and warns nothing, every run. Reporting it once here is what
     * stops a consumer being surprised that a package's review subagent never
     * appeared for their agent.
     */
    public function report(SymfonyStyle $io, string $projectRoot, BoostConfig $config, ?InstalledPackages $injectedPackages, ?string $configOverride): void
    {
        // First, and unconditionally: a project that ships no subagents at all
        // can still have hand-written definitions colliding with each other,
        // which is the case this check existed for before emission shipped.
        $this->reportNameOverlaps($io, $projectRoot);

        try {
            $inspection = SyncEngine::default($injectedPackages, $configOverride)->resolveForInspection($projectRoot);
        } catch (Throwable $throwable) {
            // Report rather than swallow, matching SkillDependencyReporter. A
            // duplicate subagent name throws here, and that is exactly the
            // failure someone runs `boost doctor` to understand.
            $io->section('Subagents');
            $io->warning('Could not resolve subagents: ' . $throwable->getMessage());

            return;
        }

        $subagentCount = count($inspection['subagents']);
        // An unmet demand still reports, even with nothing resolved.
        if ($subagentCount === 0 && $inspection['subagentDependencyWarnings'] === [] && $inspection['subagentWarnings'] === []) {
            return;
        }

        $skipped = [];
        foreach (SyncEngine::allAgentTargets() as $target) {
            if (! $config->hasAgent($target->agent())) {
                continue;
            }

            if ($target->subagentsDirectoryRelative() === null) {
                $skipped[] = $target->agent()->value;
            }
        }

        $io->section('Subagents');

        foreach ($inspection['subagentWarnings'] as $warning) {
            $io->warning($warning);
        }

        // Never an error: the dependent skill still ships, degraded.
        foreach ($inspection['subagentDependencyWarnings'] as $warning) {
            $io->warning(sprintf(
                'subagent `%s` is required by %s but is %s. Those skills ship degraded.',
                $warning['name'],
                implode(', ', $warning['dependents']),
                $warning['reason'] === 'excluded'
                    ? 'excluded by this project'
                    : 'not provided by any installed package',
            ));
        }

        if ($skipped === []) {
            $io->writeln(sprintf('<info>%d subagent(s) resolved. Every configured agent can receive them.</info>', $subagentCount));

            return;
        }

        $this->reportSkipNote($io, $subagentCount, $skipped);
    }

    /**
     * @param  list<string>  $skipped
     */
    private function reportSkipNote(SymfonyStyle $io, int $subagentCount, array $skipped): void
    {
        $io->note(sprintf(
            '%d subagent(s) resolved, skipped for %d configured agent(s) with no subagent surface: %s. Subagents are a Claude Code concept; these agents get nothing, which is expected, not an error.',
            $subagentCount,
            count($skipped),
            implode(', ', $skipped),
        ));
    }

    /**
     * Report subagent definitions that share a `name` under `.claude/agents/`.
     *
     * Claude Code resolves such a pair by filesystem read order, so which one
     * loads is undefined. boost reports and never arbitrates — at least one
     * side is normally a hand-written file boost does not own, and the operator
     * decides which to keep. Quiet when there are none.
     */
    private function reportNameOverlaps(SymfonyStyle $io, string $projectRoot): void
    {
        $overlaps = (new SubagentNameScanner())->scan($projectRoot);
        if ($overlaps === []) {
            return;
        }

        $io->section('Subagent name overlaps');

        $lines = [];
        foreach ($overlaps as $name => $paths) {
            $lines[] = sprintf('%s:', $name);
            foreach ($paths as $path) {
                $lines[] = '    - ' . $path;
            }
        }

        $io->warning(sprintf(
            "%d subagent name(s) declared by more than one file under %s. Claude Code loads only ONE of each, chosen by filesystem read order — the subfolder path does not namespace a name. boost does not arbitrate: rename or remove one side.\n  %s",
            count($overlaps),
            implode(', ', SubagentNameScanner::roots()),
            implode("\n  ", $lines),
        ));
    }
}
