<?php

declare(strict_types=1);

namespace SanderMuller\BoostCore;

use Composer\Composer;
use Composer\EventDispatcher\EventSubscriberInterface;
use Composer\IO\IOInterface;
use Composer\Package\PackageInterface;
use Composer\Plugin\Capability\CommandProvider;
use Composer\Plugin\Capable;
use Composer\Plugin\PluginInterface;
use Composer\Script\Event;
use Composer\Script\ScriptEvents;
use SanderMuller\BoostCore\Sync\SyncEngine;
use SanderMuller\BoostCore\Sync\WriteAction;
use Throwable;

final class BoostCorePlugin implements Capable, EventSubscriberInterface, PluginInterface
{
    /**
     * Emitted once per package skipped due to user-scope basename collision.
     * Kept as a const so the format string is greppable from CHANGELOG /
     * docs and not buried inside a sprintf call.
     */
    private const string COLLISION_WARNING = '<warning>boost: skipping global auto-sync of %s — basename "%s" already claimed by %s. Remove one of the packages or run `composer boost:sync --scope=user --working-dir=<pkg>` manually.</warning>';

    public function activate(Composer $composer, IOInterface $io): void {}

    public function deactivate(Composer $composer, IOInterface $io): void {}

    public function uninstall(Composer $composer, IOInterface $io): void {}

    /**
     * @return array<class-string, class-string>
     */
    public function getCapabilities(): array
    {
        return [
            CommandProvider::class => BoostCoreCommandProvider::class,
        ];
    }

    /**
     * @return array<string, string>
     */
    public static function getSubscribedEvents(): array
    {
        return [
            ScriptEvents::POST_AUTOLOAD_DUMP => 'onPostAutoloadDump',
        ];
    }

    public function onPostAutoloadDump(Event $event): void
    {
        $io = $event->getIO();
        $composer = $event->getComposer();

        if (getenv(Env::SKIP_AUTOSYNC) !== false) {
            return;
        }

        $projectRoot = getcwd();
        if ($projectRoot === false) {
            return;
        }

        if ($this->isGlobalContext($composer, $projectRoot)) {
            $this->runGlobalSync($composer, $io);

            return;
        }

        if (! is_file($projectRoot . '/boost.php')) {
            return;
        }

        try {
            $result = SyncEngine::default()->sync($projectRoot);
        } catch (Throwable $throwable) {
            $io->writeError('<warning>boost: auto-sync skipped — ' . $throwable->getMessage() . '</warning>');

            return;
        }

        if ($result->hasErrors()) {
            $io->writeError('<warning>boost: auto-sync completed with errors. Run `composer boost:sync` for details.</warning>');
        }
    }

    /**
     * True iff Composer is running in `composer global ...` context.
     *
     * Requires BOTH signals to avoid false positives if a user happens to
     * `cd` into the composer home and run `composer install` for unrelated
     * reasons:
     * - cwd equals composer's configured home directory
     * - the first non-option argv is `global`
     */
    private function isGlobalContext(Composer $composer, string $projectRoot): bool
    {
        $home = $composer->getConfig()->get('home');
        if (! is_string($home) || $home === '') {
            return false;
        }

        $homeReal = realpath($home);
        $cwdReal = realpath($projectRoot);
        if ($homeReal === false || $cwdReal === false || $homeReal !== $cwdReal) {
            return false;
        }

        /** @var list<string> $argv */
        $argv = $_SERVER['argv'] ?? [];
        foreach ($argv as $i => $arg) {
            if ($i === 0) {
                continue;
            }

            if (str_starts_with($arg, '-')) {
                continue;
            }

            return $arg === 'global';
        }

        return false;
    }

    /**
     * Iterate every installed package; for each that ships
     * `resources/boost/skills/`, run user-scope sync into the agents'
     * home-directory skill folders.
     */
    private function runGlobalSync(Composer $composer, IOInterface $io): void
    {
        $localRepo = $composer->getRepositoryManager()->getLocalRepository();
        $installManager = $composer->getInstallationManager();

        $engine = SyncEngine::default();

        // Track which package "claimed" each user-scope suffix so we can warn
        // (and skip) on collisions. Basename-only namespacing in
        // SyncEngine::packageSuffix is the underlying limitation; collision
        // detection here prevents silent file-overwrites until that scheme
        // moves to a vendor-namespaced slug.
        /** @var array<string, string> $claimedSuffixes suffix => package name */
        $claimedSuffixes = [];

        foreach ($localRepo->getPackages() as $package) {
            if (! $package instanceof PackageInterface) {
                continue;
            }

            $installPath = $installManager->getInstallPath($package);
            if (! is_string($installPath)) {
                continue;
            }
            if ($installPath === '') {
                continue;
            }

            if (! is_dir($installPath . '/resources/boost/skills')) {
                continue;
            }

            $suffix = SyncEngine::packageSuffix($package->getName());
            if (isset($claimedSuffixes[$suffix])) {
                $io->writeError(sprintf(
                    self::COLLISION_WARNING,
                    $package->getName(),
                    $suffix,
                    $claimedSuffixes[$suffix],
                ));

                continue;
            }

            $claimedSuffixes[$suffix] = $package->getName();

            try {
                $result = $engine->syncUser($installPath);
            } catch (Throwable $e) {
                $io->writeError(sprintf(
                    '<warning>boost: global auto-sync of %s skipped — %s</warning>',
                    $package->getName(),
                    $e->getMessage(),
                ));

                continue;
            }

            if ($result->hasErrors()) {
                foreach ($result->errors as $err) {
                    $io->writeError(sprintf(
                        '<warning>boost: %s — %s</warning>',
                        $package->getName(),
                        $err,
                    ));
                }

                continue;
            }

            $wrote = $result->countByAction(WriteAction::WROTE);
            if ($wrote > 0) {
                $io->write(sprintf(
                    'boost: synced %s → %s (%d file%s)',
                    $result->packageName,
                    $result->homeRoot,
                    $wrote,
                    $wrote === 1 ? '' : 's',
                ));
            }
        }
    }
}
