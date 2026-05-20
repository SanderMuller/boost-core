<?php declare(strict_types=1);

namespace SanderMuller\BoostCore\Scripts;

use Composer\Script\Event;
use SanderMuller\BoostCore\Env;
use Symfony\Component\Process\Process;

/**
 * Cross-platform Composer script callbacks for boost-core's sync command,
 * wired from consumer packages' `composer.json` `scripts` block.
 *
 * Two callables, differing only in success-path output:
 *
 *  - {@see run()} — silent on success. Designed for `post-install-cmd` /
 *    `post-update-cmd` hooks where any per-install output is noise.
 *  - {@see runWithSummary()} — streams the binary's one-line success
 *    summary through Composer's IO. Designed for user-invoked scripts
 *    like `composer sync-ai` where silence reads as a no-op.
 *
 * Both replace the bash one-liner:
 *
 *     "if [ \"$COMPOSER_DEV_MODE\" = \"1\" ]; then vendor/bin/boost sync 2>/dev/null || true; fi"
 *
 * which breaks on Windows cmd.exe, swallows real errors via `2>/dev/null`,
 * hardcodes `vendor/bin/` (ignoring `config.bin-dir` overrides), and reads
 * the leaky `$COMPOSER_DEV_MODE` env var instead of `Event::isDevMode()`.
 *
 * Both callables share the same skip/error/exit semantics:
 *  - Skip silently when `BOOST_SKIP_AUTOSYNC` env var is set (matches
 *    the plugin's `onPostAutoloadDump` escape hatch). Lets a single
 *    env-var disable auto-sync across every entry point — plugin hook,
 *    `post-install-cmd` script, `post-update-cmd` script.
 *  - Skip silently when `Event::isDevMode()` is false (`--no-dev` install).
 *  - Skip silently when the resolved binary at `config.bin-dir/boost` is
 *    not executable (e.g. boost-core not installed in this project).
 *  - On non-zero exit, emit a warning via `$event->getIO()->writeError()`
 *    pointing the user at the manual `vendor/bin/boost sync` for details.
 */
final class BoostAutoSync
{
    /**
     * Silent on success. Wire into auto-firing hooks
     * (`post-install-cmd` / `post-update-cmd`).
     */
    public static function run(Event $event): void
    {
        $process = self::resolveAndRun($event);
        if (! $process instanceof Process) {
            return;
        }

        self::reportFailureIfAny($event, $process);
    }

    /**
     * Emits the binary's one-line success summary through Composer's IO.
     * Wire into user-invoked scripts (`composer sync-ai`, etc.) where
     * silence on success would read as a no-op.
     */
    public static function runWithSummary(Event $event): void
    {
        $process = self::resolveAndRun($event);
        if (! $process instanceof Process) {
            return;
        }

        if ($process->getExitCode() === 0) {
            $output = trim($process->getOutput());
            if ($output !== '') {
                $event->getIO()->write($output);
            }

            return;
        }

        self::reportFailureIfAny($event, $process);
    }

    /**
     * Returns the completed Process, or null if the run was skipped
     * (not dev mode, or binary not present).
     */
    private static function resolveAndRun(Event $event): ?Process
    {
        if (getenv(Env::SKIP_AUTOSYNC) !== false) {
            return null;
        }

        if (! $event->isDevMode()) {
            return null;
        }

        $binary = self::resolveBinary($event);
        if ($binary === null) {
            return null;
        }

        $process = new Process([$binary, 'sync']);
        $process->run();

        return $process;
    }

    /**
     * Resolve the boost binary, with a fallback for self-sync.
     *
     * Composer only symlinks dependency bins into `vendor/bin/`, never the
     * root package's own bins. Consumer projects find boost-core's bin at
     * `<config.bin-dir>/boost` (the symlinked one). Boost-core's own dev
     * tree doesn't have that symlink — `bin/boost` lives directly at the
     * project root. The fallback picks up that case so boost-core's own
     * `composer install` triggers self-sync through the same callable
     * consumers wire, with all the same guards (`--no-dev`,
     * `BOOST_SKIP_AUTOSYNC`) intact.
     */
    private static function resolveBinary(Event $event): ?string
    {
        $config = $event->getComposer()->getConfig();
        $binDirBinary = $config->get('bin-dir') . '/boost';

        if (is_executable($binDirBinary)) {
            return $binDirBinary;
        }

        $rootBinary = dirname($config->get('vendor-dir')) . '/bin/boost';

        if (is_executable($rootBinary)) {
            return $rootBinary;
        }

        return null;
    }

    private static function reportFailureIfAny(Event $event, Process $process): void
    {
        $exit = $process->getExitCode();
        if ($exit === 0) {
            return;
        }

        $event->getIO()->writeError(sprintf(
            '<warning>boost: auto-sync exited %d. Run `vendor/bin/boost sync` manually for details.</warning>',
            $exit ?? -1,
        ));
    }
}
