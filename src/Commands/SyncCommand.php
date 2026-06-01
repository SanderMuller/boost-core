<?php declare(strict_types=1);

namespace SanderMuller\BoostCore\Commands;

use SanderMuller\BoostCore\Config\BoostConfigNotFoundException;
use SanderMuller\BoostCore\Sync\EmitterAction;
use SanderMuller\BoostCore\Sync\SyncEngine;
use SanderMuller\BoostCore\Sync\SyncResult;
use SanderMuller\BoostCore\Sync\UserScopeResult;
use SanderMuller\BoostCore\Sync\WriteAction;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use Throwable;

final class SyncCommand extends BoostBaseCommand
{
    protected function configure(): void
    {
        $this
            ->setName('boost:sync')
            ->setDescription('Generate agent-specific skill and guideline files from .ai/ + allowlisted vendors.')
            ->addOption(
                'check',
                null,
                InputOption::VALUE_NONE,
                'Report drift without writing. Non-zero exit if any file would change.',
            )
            ->addOption(
                'force',
                null,
                InputOption::VALUE_NONE,
                'Resolve vendor-vs-vendor skill collisions silently by declaration order.',
            )
            ->addOption(
                'scope',
                null,
                InputOption::VALUE_REQUIRED,
                "Sync scope: `project` (default, reads .ai/ + boost.php) or `user` (publishes a package's resources/boost/skills/ wholesale into ~/.{agent}/skills/<pkg>/ — no boost.php, so no tag or allowlist filtering).",
                'project',
            )
            ->addOption(
                'all',
                null,
                InputOption::VALUE_NONE,
                'With `--scope=user`: sync every installed package that ships skills — run once after `composer global require`.',
            );
        $this->addWorkingDirOption();
        $this->addConfigOption();
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $projectRoot = $this->resolveProjectRoot($input);

        $checkOnly = (bool) $input->getOption('check');
        $force = (bool) $input->getOption('force');
        $scope = $input->getOption('scope');

        if ($scope === 'user') {
            return (bool) $input->getOption('all')
                ? $this->runUserScopeAll($io, $checkOnly)
                : $this->runUserScope($io, $projectRoot, $checkOnly);
        }

        if ($scope !== 'project') {
            $io->error(sprintf('Unknown --scope value "%s". Expected "project" or "user".', is_string($scope) ? $scope : ''));

            return self::FAILURE;
        }

        try {
            $result = SyncEngine::default(configFile: $this->configFileOption($input))->sync($projectRoot, $checkOnly, $force);
        } catch (BoostConfigNotFoundException $e) {
            $io->error($e->getMessage());

            return self::FAILURE;
        } catch (Throwable $e) {
            $io->error('boost:sync failed: ' . $e->getMessage());

            return self::FAILURE;
        }

        return $this->report($io, $result, $checkOnly);
    }

    private function runUserScope(SymfonyStyle $io, string $packageRoot, bool $checkOnly): int
    {
        try {
            $result = SyncEngine::default()->syncUser($packageRoot, $checkOnly);
        } catch (Throwable $throwable) {
            $io->error('boost:sync --scope=user failed: ' . $throwable->getMessage());

            return self::FAILURE;
        }

        return $this->reportUserScope($io, $result, $checkOnly);
    }

    /**
     * `--scope=user --all`: sync every installed package that ships skills.
     * The explicit-command replacement for the retired plugin's global
     * autosync — run once after `composer global require`.
     */
    private function runUserScopeAll(SymfonyStyle $io, bool $checkOnly): int
    {
        try {
            $results = SyncEngine::default()->syncUserAll($checkOnly);
        } catch (Throwable $throwable) {
            $io->error('boost:sync --scope=user --all failed: ' . $throwable->getMessage());

            return self::FAILURE;
        }

        if ($results === []) {
            $io->success('No installed package ships skills — nothing to user-scope sync.');

            return self::SUCCESS;
        }

        $exit = self::SUCCESS;
        foreach ($results as $result) {
            if ($this->reportUserScope($io, $result, $checkOnly) === self::FAILURE) {
                $exit = self::FAILURE;
            }
        }

        return $exit;
    }

    private function reportUserScope(SymfonyStyle $io, UserScopeResult $result, bool $checkOnly): int
    {
        if ($result->hasErrors()) {
            foreach ($result->errors as $error) {
                $io->error($error);
            }

            return self::FAILURE;
        }

        if ($checkOnly && $result->hasDrift()) {
            $io->warning(sprintf(
                '[%s → %s] Drift detected: %d file(s) would change.',
                $result->packageName,
                $result->homeRoot,
                $result->countByAction(WriteAction::WOULD_WRITE),
            ));

            return self::FAILURE;
        }

        $wrote = $result->countByAction(WriteAction::WROTE);
        $unchanged = $result->countByAction(WriteAction::UNCHANGED);
        $deleted = $result->countByAction(WriteAction::DELETED);

        if ($checkOnly) {
            $io->success(sprintf('[%s] No drift. %d file(s) unchanged.', $result->packageName, $unchanged));

            return self::SUCCESS;
        }

        $io->success(sprintf(
            '[%s → %s] Sync done. wrote=%d, unchanged=%d, deleted=%d.',
            $result->packageName,
            $result->homeRoot,
            $wrote,
            $unchanged,
            $deleted,
        ));

        return self::SUCCESS;
    }

    private function report(SymfonyStyle $io, SyncResult $result, bool $checkOnly): int
    {
        // Render diagnostics BEFORE the error short-circuit. Render-fail
        // warnings and other safety-gate diagnostics carry
        // operator-facing reassurance ("prior content preserved") that
        // must reach the operator even when SyncResult also carries
        // top-level errors.
        $this->renderConventionsDiagnostics($io, $result);

        if ($result->hasErrors()) {
            foreach ($result->errors as $error) {
                $io->error($error);
            }

            return self::FAILURE;
        }

        if ($checkOnly && $result->hasDrift()) {
            $io->warning(sprintf(
                'Drift detected: %d file(s) would change.',
                $result->countWouldChange(),
            ));

            foreach ($result->writes as $write) {
                if ($write->action === WriteAction::WOULD_WRITE) {
                    $io->writeln('  ~ ' . $write->relativePath);
                }

                if ($write->action === WriteAction::WOULD_DELETE) {
                    $io->writeln('  - ' . $write->relativePath);
                }
            }

            return self::FAILURE;
        }

        $wrote = $result->countByAction(WriteAction::WROTE);
        $unchanged = $result->countByAction(WriteAction::UNCHANGED);
        $deleted = $result->countByAction(WriteAction::DELETED);
        $skippedSymlink = $result->countByAction(WriteAction::SKIPPED_SYMLINK);
        $emittersWrote = $result->countEmittersByAction(EmitterAction::WROTE);
        $emittersSkipped = $result->countEmittersByAction(EmitterAction::SKIPPED);

        $emitterSummary = '';
        if ($result->emitters !== []) {
            $emitterSummary = sprintf(
                ' emitters(wrote=%d, skipped=%d)',
                $emittersWrote,
                $emittersSkipped,
            );
        }

        $symlinkSummary = $skippedSymlink > 0 ? sprintf(' skipped-symlink=%d', $skippedSymlink) : '';

        if ($skippedSymlink > 0) {
            $io->warning(sprintf(
                '%d file(s) skipped because a path segment is a user-placed symlink. Sync did not follow the link; the source file behind the symlink was not overwritten. Inspect with `vendor/bin/boost --check` to see which paths.',
                $skippedSymlink,
            ));
        }

        $this->noteDeletes($io, $result, $checkOnly, $deleted);
        $this->noteHostShadows($io, $result);

        if ($checkOnly) {
            $io->success(sprintf('No drift. %d file(s) unchanged.%s%s', $unchanged, $symlinkSummary, $emitterSummary));
            $this->noteTagFilterGap($io, $result);

            return self::SUCCESS;
        }

        $io->success(sprintf('Sync done. wrote=%d, unchanged=%d, deleted=%d.%s%s', $wrote, $unchanged, $deleted, $symlinkSummary, $emitterSummary));
        $this->noteTagFilterGap($io, $result);

        return self::SUCCESS;
    }

    /**
     * Renders the SyncResult::diagnostics list. The list carries multiple
     * kinds — conventions warn/error, clean-slate stale-removal info,
     * copilot-instructions strip info, render-fail safety warnings. The
     * section is named "Diagnostics" to cover all of those without
     * misleading operators who'd otherwise scroll past expecting only
     * conventions content.
     */
    private function renderConventionsDiagnostics(SymfonyStyle $io, SyncResult $result): void
    {
        if ($result->diagnostics === []) {
            return;
        }

        $io->section('Diagnostics');
        foreach ($result->diagnostics as $diagnostic) {
            $glyph = match ($diagnostic->level) {
                'error' => '<fg=red>✗</>',
                'warning' => '<fg=yellow>⚠</>',
                'info' => '<fg=cyan>ℹ</>',
                default => ' ',
            };
            $slot = $diagnostic->slot === null ? '' : "{$diagnostic->slot}: ";
            $vendor = $diagnostic->vendor === null ? '' : " ({$diagnostic->vendor})";
            $io->writeln("{$glyph} {$slot}{$diagnostic->message}{$vendor}");
        }
    }

    /**
     * Nudge a consumer whose `withTags()` is empty AND some vendor skills were
     * tag-filtered out as a result — the silent-filter foot-gun. Three real
     * boost-stack repos hit it (repo-new, package-boost-laravel, boost-skills'
     * own dogfood) before being audited. Pointing the consumer at `boost tags`
     * is the cheapest discoverability fix.
     */
    private function noteTagFilterGap(SymfonyStyle $io, SyncResult $result): void
    {
        if ($result->tagFilteredSkillsCount > 0) {
            $io->note(sprintf(
                '%d tagged skill(s) currently filtered out — your `withTags()` is empty. Run `vendor/bin/boost tags` to see them.',
                $result->tagFilteredSkillsCount,
            ));
        }
    }

    /**
     * Log every host-vs-vendor shadow event. Silent override is the
     * documented behavior, but operators using `withAllowedVendors` +
     * symlinked host overrides have no way to tell which version
     * actually ships without this log. Each line names the skill and
     * the vendor whose copy was shadowed.
     */
    private function noteHostShadows(SymfonyStyle $io, SyncResult $result): void
    {
        if ($result->hostShadows !== []) {
            $io->note(sprintf(
                '%d host skill(s) shadowed allowlisted-vendor copies:',
                count($result->hostShadows),
            ));
            foreach ($result->hostShadows as $shadow) {
                $io->writeln(sprintf('  • <fg=cyan>%s</> shadows %s', $shadow['skill'], $shadow['shadowedVendor']));
            }
        }

        if ($result->hostGuidelineShadows !== []) {
            // Count UNIQUE host guidelines, not shadow events (one guideline can
            // shadow the same name across multiple vendors).
            $uniqueGuidelines = count(array_unique(array_column($result->hostGuidelineShadows, 'guideline')));
            $io->note(sprintf(
                '%d host guideline(s) shadowed allowlisted-vendor copies:',
                $uniqueGuidelines,
            ));
            foreach ($result->hostGuidelineShadows as $shadow) {
                $io->writeln(sprintf('  • <fg=cyan>%s</> shadows %s', $shadow['guideline'], $shadow['shadowedVendor']));
            }
        }
    }

    private function noteDeletes(SymfonyStyle $io, SyncResult $result, bool $checkOnly, int $deleted): void
    {
        if ($checkOnly || $deleted <= 0) {
            return;
        }

        // Delegate to the canonical attribution renderer so wrapper
        // commands (project-boost-laravel artisan, future custom CLIs)
        // produce identical text via `$result->renderDeleteAttribution()`.
        $attribution = $result->renderDeleteAttribution();
        if ($attribution !== null) {
            $io->warning($attribution);
        }
    }
}
