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
            $result = SyncEngine::default()->sync($projectRoot, $checkOnly, $force);
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
}
