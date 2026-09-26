<?php declare(strict_types=1);

namespace SanderMuller\BoostCore\Sync;

use JsonException;

/**
 * The per-package user-scope ownership manifest — the user/global-scope
 * counterpart of the in-project {@see SyncManifest}. One file per skill-shipping
 * package at `$home/.boost/manifests/<vendor>__<pkg>.json`, recording every
 * user-scope path the package emitted (path → sha256) plus the package's
 * **install path**. The install path is the source-of-truth for "is this package
 * still installed" at reconcile time — a package is removed iff its install path
 * no longer exists on disk, NOT merely if it's absent from whatever Composer
 * context happened to run `--scope=user --all` (which could be a project-local
 * runtime that can't see the global install set).
 *
 * Absent or corrupt → empty (backward-safe: no ownership asserted, nothing reaped
 * — the lazy cold-start mirrors the 0.14.0 project manifest).
 *
 * @internal
 */
final readonly class UserScopeManifest
{
    public const VERSION = 1;

    public const DIR = '.boost/manifests';

    /**
     * @param  array<string, string>  $entries  user-scope relative path => sha256
     */
    private function __construct(
        public string $package,
        public string $installPath,
        public array $entries,
    ) {}

    public static function empty(): self
    {
        return new self('', '', []);
    }

    /**
     * Absolute path of the manifest file for a package under a home root.
     */
    public static function pathFor(string $home, string $packageName): string
    {
        return rtrim($home, '/') . '/' . self::DIR . '/' . SyncEngine::packageSuffix($packageName) . '.json';
    }

    public static function fromFile(string $path): self
    {
        $raw = is_file($path) ? @file_get_contents($path) : false;
        if ($raw === false) {
            return self::empty();
        }

        try {
            /** @var mixed $decoded */
            $decoded = json_decode($raw, true, 512, JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            return self::empty();
        }

        if (! is_array($decoded)) {
            return self::empty();
        }

        $package = isset($decoded['package']) && is_string($decoded['package']) ? $decoded['package'] : '';
        $installPath = isset($decoded['installPath']) && is_string($decoded['installPath']) ? $decoded['installPath'] : '';
        $emitted = isset($decoded['emitted']) && is_array($decoded['emitted']) ? $decoded['emitted'] : [];

        /** @var array<string, string> $entries */
        $entries = [];
        /** @var mixed $sha */
        foreach ($emitted as $relativePath => $sha) {
            if (is_string($relativePath) && is_string($sha) && $sha !== '') {
                $entries[$relativePath] = $sha;
            }
        }

        return new self($package, $installPath, $entries);
    }

    public function isEmpty(): bool
    {
        return $this->entries === [];
    }

    /**
     * @return list<string>
     */
    public function paths(): array
    {
        return array_keys($this->entries);
    }

    /**
     * Boost-owned + unchanged? Listed AND the current on-disk sha matches the
     * recorded one. A divergence means the operator edited it → NOT owned for
     * reaping (preserved), mirroring SyncManifest::ownsGuidance().
     */
    public function ownsPath(string $relativePath, string $currentSha): bool
    {
        return isset($this->entries[$relativePath]) && $this->entries[$relativePath] === $currentSha;
    }

    /**
     * The sha recorded for a path on the last sync, or null if untracked. Used
     * by the carry-forward path that re-records a retain-on-fail leftover with
     * its PRIOR sha so an operator-edited file stays un-owned.
     */
    public function recordedSha(string $relativePath): ?string
    {
        return $this->entries[$relativePath] ?? null;
    }

    public function withEntry(string $relativePath, string $sha256): self
    {
        $entries = $this->entries;
        $entries[$relativePath] = $sha256;

        return new self($this->package, $this->installPath, $entries);
    }

    /**
     * Every path recorded by the manifests of OTHER packages under `$home`,
     * mapped to the package that records it.
     *
     * Flat user-scope skill dirs (`<skillsDir>/<name>-user/`) share one
     * namespace across packages, so the package slug no longer fences a reap.
     * A path another package records is never this package's to write or
     * delete.
     *
     * @param  list<string>  $ignoredSlugs  manifests to skip (a `--check` run's would-be-deleted ones)
     * @return array<string, string>  path => owning package
     */
    public static function pathsRecordedByOthers(string $home, string $exceptSlug, array $ignoredSlugs = []): array
    {
        $files = glob(rtrim($home, '/') . '/' . self::DIR . '/*.json');
        if ($files === false) {
            return [];
        }

        $paths = [];
        foreach ($files as $file) {
            $slug = basename($file, '.json');
            if ($slug === $exceptSlug || in_array($slug, $ignoredSlugs, true)) {
                continue;
            }

            $manifest = self::fromFile($file);
            foreach ($manifest->paths() as $path) {
                $paths[$path] = $manifest->package !== '' ? $manifest->package : $slug;
            }
        }

        return $paths;
    }

    public function withInstallPath(string $installPath): self
    {
        return new self($this->package, $installPath, $this->entries);
    }

    public function toJson(string $packageName, string $generatedBy): string
    {
        $entries = $this->entries;
        ksort($entries);

        return json_encode([
            'version' => self::VERSION,
            'generatedBy' => $generatedBy,
            'package' => $packageName,
            'installPath' => $this->installPath,
            'scope' => 'user',
            'emitted' => $entries === [] ? (object) [] : $entries,
        ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) . "\n";
    }
}
