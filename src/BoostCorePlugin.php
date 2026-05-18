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
    private ?Composer $composer = null;

    public function activate(Composer $composer, IOInterface $io): void
    {
        $this->composer = $composer;
    }

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

        if (getenv('BOOST_SKIP_AUTOSYNC') !== false) {
            return;
        }

        $projectRoot = getcwd();
        if ($projectRoot === false) {
            return;
        }

        if ($this->isGlobalContext($projectRoot)) {
            $this->runGlobalSync($io);

            return;
        }

        if (! is_file($projectRoot . '/boost.php')) {
            return;
        }

        try {
            $result = SyncEngine::default()->sync($projectRoot);
        } catch (Throwable $e) {
            $io->writeError('<warning>boost: auto-sync skipped — ' . $e->getMessage() . '</warning>');

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
    private function isGlobalContext(string $projectRoot): bool
    {
        if ($this->composer === null) {
            return false;
        }

        $home = $this->composer->getConfig()->get('home');
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
    private function runGlobalSync(IOInterface $io): void
    {
        if ($this->composer === null) {
            return;
        }

        $localRepo = $this->composer->getRepositoryManager()->getLocalRepository();
        $installManager = $this->composer->getInstallationManager();

        $engine = SyncEngine::default();

        foreach ($localRepo->getPackages() as $package) {
            if (! $package instanceof PackageInterface) {
                continue;
            }

            $installPath = $installManager->getInstallPath($package);
            if (! is_string($installPath) || $installPath === '') {
                continue;
            }
            if (! is_dir($installPath . '/resources/boost/skills')) {
                continue;
            }

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
