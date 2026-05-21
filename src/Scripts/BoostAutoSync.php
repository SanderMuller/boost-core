<?php declare(strict_types=1);

namespace SanderMuller\BoostCore\Scripts;

use Composer\InstalledVersions;
use Composer\Script\Event;
use OutOfBoundsException;
use SanderMuller\BoostCore\Env;
use SanderMuller\BoostCore\Sync\SyncEngine;
use Symfony\Component\Process\Process;
use Throwable;

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
 *
 * Separately, {@see syncUserScope()} / {@see syncUserScopeOnce()} are an
 * event-free pair for globally-installed CLI tools that self-sync their
 * own bundled skills from their bin script. There is no Composer event in
 * that context, so they drive {@see SyncEngine} in-process rather than
 * resolving and spawning the `boost` binary.
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
     * Run user-scope sync for `$packageRoot`'s bundled skills, in-process —
     * for a globally-installed CLI tool that wants its own skills current
     * on the machine, invoked from the tool's plain bin script where there
     * is no `Composer\Script\Event`.
     *
     * Blocks until sync completes. Returns 0 on success, 1 on failure.
     * Honors `BOOST_SKIP_AUTOSYNC` (returns 0 without syncing). Never
     * throws — a tool must keep running even if its self-sync fails; a
     * failure emits a one-line stderr warning instead.
     */
    public static function syncUserScope(string $packageRoot): int
    {
        if (getenv(Env::SKIP_AUTOSYNC) !== false) {
            return 0;
        }

        try {
            $result = SyncEngine::default()->syncUser($packageRoot);
        } catch (Throwable $throwable) {
            self::warnUserScopeFailure($throwable->getMessage());

            return 1;
        }

        if ($result->hasErrors()) {
            self::warnUserScopeFailure(implode('; ', $result->errors));

            return 1;
        }

        return 0;
    }

    /**
     * Sentinel-gated {@see syncUserScope()}: runs the sync only when a
     * per-version sentinel for `$packageName` is absent, then writes the
     * sentinel so later invocations are free. Drop it on a tool's bin's
     * first line and forget.
     *
     * `$packageName` namespaces the sentinel; its version resolves via
     * `Composer\InstalledVersions`, so the sentinel auto-invalidates on
     * every version bump — a patched tool re-syncs once, on its next run.
     *
     * Returns true when the sync ran (sentinel absent), false when it was
     * skipped (sentinel present, or `BOOST_SKIP_AUTOSYNC` set). The
     * sentinel is written only after a clean sync, so a failed sync is
     * retried on the next invocation rather than cached as done.
     */
    public static function syncUserScopeOnce(string $packageRoot, string $packageName): bool
    {
        if (getenv(Env::SKIP_AUTOSYNC) !== false) {
            return false;
        }

        $sentinel = self::sentinelPath($packageName);
        if ($sentinel !== null && is_file($sentinel)) {
            return false;
        }

        $ranClean = self::syncUserScope($packageRoot) === 0;

        if ($ranClean && $sentinel !== null) {
            self::writeSentinel($sentinel);
        }

        return true;
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

    /**
     * Per-version sentinel path for `$packageName`, XDG-compliant:
     * `${XDG_CACHE_HOME:-$HOME/.cache}/boost/synced/<vendor>-<package>@<version>`.
     * Returns null when `$packageName`'s version cannot be resolved — the
     * caller then degrades to an ungated sync on every invocation.
     */
    private static function sentinelPath(string $packageName): ?string
    {
        $version = self::resolvePackageVersion($packageName);
        if ($version === null) {
            return null;
        }

        $slug = str_replace('/', '-', $packageName) . '@' . $version;
        $slug = preg_replace('/[^A-Za-z0-9._@-]+/', '-', $slug) ?? $slug;

        return self::cacheDirectory() . '/boost/synced/' . $slug;
    }

    private static function resolvePackageVersion(string $packageName): ?string
    {
        if (! InstalledVersions::isInstalled($packageName)) {
            return null;
        }

        try {
            $version = InstalledVersions::getPrettyVersion($packageName);
        } catch (OutOfBoundsException) {
            return null;
        }

        return $version !== null && $version !== '' ? $version : null;
    }

    /**
     * Cache root for sentinels: `$XDG_CACHE_HOME`, else `$HOME/.cache`,
     * else `%USERPROFILE%/.cache` (Windows, where `HOME` is usually
     * unset), else the system temp dir. The `USERPROFILE` rung mirrors
     * `SyncEngine`'s home resolution, so the sentinel lands beside the
     * skills it gates rather than in an ephemeral temp dir.
     */
    private static function cacheDirectory(): string
    {
        $xdg = getenv('XDG_CACHE_HOME');
        if (is_string($xdg) && $xdg !== '') {
            return rtrim($xdg, '/');
        }

        $home = getenv('HOME');
        if (is_string($home) && $home !== '') {
            return rtrim($home, '/') . '/.cache';
        }

        $userProfile = getenv('USERPROFILE');
        if (is_string($userProfile) && $userProfile !== '') {
            return rtrim($userProfile, '/\\') . '/.cache';
        }

        return rtrim(sys_get_temp_dir(), '/');
    }

    private static function writeSentinel(string $path): void
    {
        $directory = dirname($path);
        if (! is_dir($directory) && ! @mkdir($directory, 0o777, true) && ! is_dir($directory)) {
            return;
        }

        @file_put_contents($path, gmdate('c') . PHP_EOL);
    }

    private static function warnUserScopeFailure(string $detail): void
    {
        $stderr = fopen('php://stderr', 'w');
        if ($stderr === false) {
            return;
        }

        fwrite($stderr, 'boost: user-scope auto-sync failed — ' . $detail . PHP_EOL);
        fclose($stderr);
    }
}
