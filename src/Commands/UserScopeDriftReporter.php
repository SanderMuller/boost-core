<?php declare(strict_types=1);

namespace SanderMuller\BoostCore\Commands;

use Composer\InstalledVersions;
use JsonException;
use SanderMuller\BoostCore\Sync\SyncEngine;
use SanderMuller\BoostCore\Sync\UserScopeManifest;
use SanderMuller\BoostCore\Sync\WriteAction;
use Symfony\Component\Console\Style\SymfonyStyle;
use Throwable;

/**
 * `boost doctor`'s check of the user-scope copies (`~/.{agent}/skills/*-user/`).
 *
 * Only `boost sync --scope=user` refreshes them. A project sync never does, and
 * `composer global update` runs no sync, so an updated global package leaves
 * stale copies with no signal. For each user-scope manifest whose package is
 * still installed, this runs that package's user-scope sync in CHECK mode and,
 * on drift, prints the command that fixes it.
 *
 * Check mode writes nothing: no file, no manifest. Silent when nothing drifted.
 *
 * A package whose global Composer install holds a different boost-core than
 * this one is skipped: another engine version can render differently, so the
 * verdict would be a false drift that the printed command cannot clear.
 *
 * @internal
 */
final readonly class UserScopeDriftReporter
{
    public const string FIX_COMMAND = 'composer global exec -- boost sync --scope=user --all';

    private const string CORE_PACKAGE = 'sandermuller/boost-core';

    /**
     * @param  string|null  $runningCoreVersion  this boost-core's version; null reads it from Composer
     */
    public function __construct(private ?string $runningCoreVersion = null) {}

    public function report(SymfonyStyle $io, string $home): void
    {
        $manifestFiles = glob($home . '/' . UserScopeManifest::DIR . '/*.json');
        if ($manifestFiles === false || $manifestFiles === []) {
            return;
        }

        $drifted = [];
        $unchecked = [];
        $skipped = [];
        foreach ($manifestFiles as $file) {
            $manifest = UserScopeManifest::fromFile($file);
            if ($manifest->installPath === '' || ! is_dir($manifest->installPath)) {
                continue;
            }

            $label = $manifest->package !== '' ? $manifest->package : basename($file, '.json');

            $globalCore = $this->installedCoreVersion($manifest->installPath);
            $runningCore = $this->runningCoreVersion();
            if ($globalCore !== null && $runningCore !== null && $globalCore !== $runningCore) {
                $skipped[] = sprintf('%s (installed beside boost-core %s; this is %s)', $label, $globalCore, $runningCore);

                continue;
            }

            try {
                $result = SyncEngine::default()->syncUser($manifest->installPath, checkOnly: true, homeRoot: $home);
            } catch (Throwable $throwable) {
                $unchecked[] = sprintf('%s: %s', $label, $throwable->getMessage());

                continue;
            }

            if ($result->hasErrors()) {
                $unchecked[] = sprintf('%s: %s', $label, implode('; ', $result->errors));

                continue;
            }

            if ($result->hasDrift()) {
                $drifted[] = sprintf(
                    '%s (%d file(s) would change)',
                    $label,
                    $result->countByAction(WriteAction::WOULD_WRITE) + $result->countByAction(WriteAction::WOULD_DELETE),
                );
            }
        }

        $this->render($io, $drifted, $unchecked, $skipped);
    }

    /**
     * @param  list<string>  $drifted
     * @param  list<string>  $unchecked
     * @param  list<string>  $skipped
     */
    private function render(SymfonyStyle $io, array $drifted, array $unchecked, array $skipped): void
    {
        $verboseSkips = $skipped !== [] && $io->isVerbose();
        if ($drifted === [] && $unchecked === [] && ! $verboseSkips) {
            return;
        }

        $io->section('User-scope skills');

        if ($drifted !== []) {
            $io->warning('User-scope copies are out of date: ' . implode(', ', $drifted) . '. Run: ' . self::FIX_COMMAND);
        }

        if ($unchecked !== []) {
            $io->note('Could not check the user-scope copies of ' . implode('; ', $unchecked) . '. Run `' . self::FIX_COMMAND . ' --check` to see the full result.');
        }

        if ($verboseSkips) {
            $io->writeln('Not checked, because a different boost-core would render them: ' . implode(', ', $skipped) . '.');
        }
    }

    private function runningCoreVersion(): ?string
    {
        if ($this->runningCoreVersion !== null) {
            return $this->runningCoreVersion;
        }

        return InstalledVersions::isInstalled(self::CORE_PACKAGE)
            ? InstalledVersions::getPrettyVersion(self::CORE_PACKAGE)
            : null;
    }

    /**
     * The boost-core version in the Composer install that holds the package —
     * the one `composer global exec -- boost` runs. Null when it cannot be read
     * (no `vendor/composer/installed.json` two levels up, say), in which case
     * the check runs anyway.
     */
    private function installedCoreVersion(string $installPath): ?string
    {
        $installed = dirname($installPath, 2) . '/composer/installed.json';
        $raw = is_file($installed) ? @file_get_contents($installed) : false;
        if ($raw === false) {
            return null;
        }

        try {
            $decoded = json_decode($raw, true, flags: JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            return null;
        }

        $packages = is_array($decoded) ? ($decoded['packages'] ?? $decoded) : [];
        if (! is_array($packages)) {
            return null;
        }

        foreach ($packages as $package) {
            if (is_array($package) && ($package['name'] ?? null) === self::CORE_PACKAGE && is_string($package['version'] ?? null)) {
                return $package['version'];
            }
        }

        return null;
    }
}
