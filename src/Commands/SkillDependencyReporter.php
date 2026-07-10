<?php declare(strict_types=1);

namespace SanderMuller\BoostCore\Commands;

use SanderMuller\BoostCore\Conventions\Diagnostic;
use SanderMuller\BoostCore\Skills\Skill;
use SanderMuller\BoostCore\Skills\SkillDependencyDiagnostics;
use SanderMuller\BoostCore\Sync\InstalledPackages;
use SanderMuller\BoostCore\Sync\SyncEngine;
use Symfony\Component\Console\Style\SymfonyStyle;
use Throwable;

/**
 * Doctor's "Skill dependencies" section — surfaces `metadata.boost-requires`
 * health over the inspection-resolved set (the exact demand outcome a sync
 * would produce): malformed declarations, unsatisfied (missing / excluded)
 * dependencies, and — under `-v` — cross-tag-boundary requires.
 *
 * Silent when no shipped skill declares requires and nothing is wrong, so a
 * dependency-free project gets no extra section (mirrors doctor's
 * exclude-keys early-out). Lives outside {@see DoctorCommand} to keep that
 * class under the cognitive-complexity cap.
 *
 * @internal
 */
final class SkillDependencyReporter
{
    public function report(SymfonyStyle $io, string $projectRoot, ?InstalledPackages $packages, ?string $configOverride): void
    {
        try {
            $inspection = SyncEngine::default($packages, $configOverride)->resolveForInspection($projectRoot);
        } catch (Throwable $throwable) {
            $io->section('Skill dependencies');
            $io->warning('Could not resolve skills: ' . $throwable->getMessage());

            return;
        }

        $diagnostics = SkillDependencyDiagnostics::diagnostics(
            $inspection['skills'],
            $inspection['skillDependencyWarnings'],
            $inspection['skillMalformedRequires'],
        );

        if (! $this->declaresAnyRequires($inspection['skills']) && $diagnostics === []) {
            return;
        }

        $io->section('Skill dependencies');

        $shown = 0;
        foreach ($diagnostics as $diagnostic) {
            if ($diagnostic->level === Diagnostic::LEVEL_INFO && ! $io->isVerbose()) {
                continue;
            }

            $glyph = match ($diagnostic->level) {
                Diagnostic::LEVEL_ERROR => '<fg=red>✗</>',
                Diagnostic::LEVEL_WARNING => '<comment>⚠</comment>',
                default => '<fg=cyan>ℹ</>',
            };
            $io->writeln("{$glyph} {$diagnostic->message}");
            ++$shown;
        }

        if ($shown === 0) {
            $io->writeln('<info>All declared skill dependencies are satisfied.</info>');
        }
    }

    /**
     * @param  list<Skill>  $skills
     */
    private function declaresAnyRequires(array $skills): bool
    {
        foreach ($skills as $skill) {
            if ($skill->requires !== []) {
                return true;
            }
        }

        return false;
    }
}
