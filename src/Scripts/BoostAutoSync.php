<?php

declare(strict_types=1);

namespace SanderMuller\BoostCore\Scripts;

use Composer\Script\Event;
use Symfony\Component\Process\Process;

/**
 * Cross-platform Composer script callback for `post-install-cmd` /
 * `post-update-cmd` hooks in consumer packages.
 *
 * Wire it into a consumer's composer.json:
 *
 *     "scripts": {
 *         "post-install-cmd": [
 *             "SanderMuller\\BoostCore\\Scripts\\BoostAutoSync::run"
 *         ],
 *         "post-update-cmd": [
 *             "SanderMuller\\BoostCore\\Scripts\\BoostAutoSync::run"
 *         ]
 *     }
 *
 * Replaces the bash one-liner:
 *
 *     "if [ \"$COMPOSER_DEV_MODE\" = \"1\" ]; then vendor/bin/boost sync 2>/dev/null || true; fi"
 *
 * Why the PHP shape wins:
 *  - Cross-platform (the bash form breaks on Windows cmd.exe).
 *  - `Event::isDevMode()` is the official Composer API for `--no-dev`
 *    detection, more reliable than reading `$COMPOSER_DEV_MODE`.
 *  - `Composer\Config::get('bin-dir')` honors a project's
 *    `config.bin-dir` override (default `vendor/bin/` is hardcoded in
 *    the bash form).
 *  - Errors surface through Composer's IO instead of being swallowed
 *    by `2>/dev/null || true`.
 */
final class BoostAutoSync
{
    public static function run(Event $event): void
    {
        if (! $event->isDevMode()) {
            return;
        }

        $config = $event->getComposer()->getConfig();
        $binary = $config->get('bin-dir') . '/boost';

        if (! is_executable($binary)) {
            return;
        }

        $process = new Process([$binary, 'sync']);
        $exit = $process->run();

        if ($exit === 0) {
            return;
        }

        $event->getIO()->writeError(sprintf(
            '<warning>boost: auto-sync via post-install-cmd exited %d. Run `vendor/bin/boost sync` manually for details.</warning>',
            $exit,
        ));
    }
}
