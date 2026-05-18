<?php

declare(strict_types=1);

namespace SanderMuller\BoostCore;

use Composer\Composer;
use Composer\EventDispatcher\EventSubscriberInterface;
use Composer\IO\IOInterface;
use Composer\Plugin\Capability\CommandProvider;
use Composer\Plugin\Capable;
use Composer\Plugin\PluginInterface;
use Composer\Script\Event;
use Composer\Script\ScriptEvents;
use SanderMuller\BoostCore\Sync\SyncEngine;
use Throwable;

final class BoostCorePlugin implements Capable, EventSubscriberInterface, PluginInterface
{
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

        if (getenv('BOOST_SKIP_AUTOSYNC') !== false) {
            return;
        }

        $projectRoot = getcwd();
        if ($projectRoot === false) {
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
}
