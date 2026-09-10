<?php declare(strict_types=1);

namespace SanderMuller\BoostCore\Commands;

use SanderMuller\BoostCore\Config\BoostConfigNotFoundException;
use SanderMuller\BoostCore\Sync\SyncEngine;
use SanderMuller\BoostCore\Sync\SyncReporter;
use SanderMuller\BoostCore\Sync\SyncResult;
use SanderMuller\BoostCore\Sync\UserScopeResult;
use SanderMuller\BoostCore\Sync\WriteAction;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use Throwable;

/**
 * @internal
 */
final class SyncCommand extends BoostBaseCommand implements TouchesResolutionPipeline
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
                "Sync scope: `project` (default, reads .ai/ + boost.php) or `user` (publishes a package's resources/boost/skills/ wholesale into ~/.{agent}/skills/<pkg>/, plus the guidelines its author marked user-scope eligible into ~/.claude/boost/<pkg>.md — no boost.php, so no tag or allowlist filtering).",
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

        return $this->report($io, $result, $checkOnly, $projectRoot, $this->configFileOption($input));
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
            // Count BOTH would-write and would-reap: a dropped/removed skill whose
            // only pending change is a reap must not report "0 file(s) would
            // change" now that hasDrift() treats WOULD_DELETE as drift (codex 0.19.0).
            $io->warning(sprintf(
                '[%s → %s] Drift detected: %d file(s) would change (%d write, %d reap).',
                $result->packageName,
                $result->homeRoot,
                $result->countByAction(WriteAction::WOULD_WRITE) + $result->countByAction(WriteAction::WOULD_DELETE),
                $result->countByAction(WriteAction::WOULD_WRITE),
                $result->countByAction(WriteAction::WOULD_DELETE),
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

    /**
     * Delegates to the `@api` {@see SyncReporter} so this binary and a wrapper
     * package's own command render one result identically — see that class for
     * why the rendering is not private to the CLI.
     */
    private function report(SymfonyStyle $io, SyncResult $result, bool $checkOnly, string $projectRoot, ?string $configFile): int
    {
        return (new SyncReporter())->report($io, $result, $checkOnly, $projectRoot, $configFile);
    }
}
