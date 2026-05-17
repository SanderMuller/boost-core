<?php

declare(strict_types=1);

namespace SanderMuller\BoostCore\Discovery;

use JsonException;
use SanderMuller\BoostCore\Sync\InstalledPackages;
use SanderMuller\BoostCore\Sync\PackageInfo;

/**
 * Walks installed Composer packages looking for boost skill/guideline publishers.
 *
 * Discovery contract:
 * - Vendor packages declare publishing paths in composer.json `extra.boost.skills`
 *   and `extra.boost.guidelines`. Both default to convention paths under
 *   `resources/boost/` if absent.
 * - VendorScanner returns ALL packages with discoverable content. Allowlist
 *   filtering is the SyncEngine's responsibility downstream — keep concerns split.
 */
final class VendorScanner
{
    private const DEFAULT_SKILLS_PATH = 'resources/boost/skills';

    private const DEFAULT_GUIDELINES_PATH = 'resources/boost/guidelines';

    public function __construct(
        private readonly InstalledPackages $packages,
    ) {}

    /**
     * @return iterable<DiscoveredVendor>
     */
    public function discover(): iterable
    {
        foreach ($this->packages->all() as $package) {
            $vendor = $this->scanPackage($package);
            if ($vendor !== null && $vendor->publishesAnything()) {
                yield $vendor;
            }
        }
    }

    private function scanPackage(PackageInfo $package): ?DiscoveredVendor
    {
        $composerJson = $package->installPath . '/composer.json';
        if (! is_file($composerJson)) {
            return null;
        }

        $config = $this->readComposerJson($composerJson);
        if ($config === null) {
            return null;
        }

        $extraBoost = $this->extractExtraBoost($config);

        $skillsRel = is_string($extraBoost['skills'] ?? null)
            ? $extraBoost['skills']
            : self::DEFAULT_SKILLS_PATH;
        $guidelinesRel = is_string($extraBoost['guidelines'] ?? null)
            ? $extraBoost['guidelines']
            : self::DEFAULT_GUIDELINES_PATH;

        return new DiscoveredVendor(
            name: $package->name,
            installPath: $package->installPath,
            skillsPath: $this->resolveIfDir($package->installPath, $skillsRel),
            guidelinesPath: $this->resolveIfDir($package->installPath, $guidelinesRel),
        );
    }

    /**
     * @return array<mixed, mixed>|null
     */
    private function readComposerJson(string $path): ?array
    {
        $raw = @file_get_contents($path);
        if ($raw === false) {
            return null;
        }

        try {
            $decoded = json_decode($raw, true, flags: JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            return null;
        }

        return is_array($decoded) ? $decoded : null;
    }

    /**
     * @param  array<mixed, mixed>  $config
     * @return array<mixed, mixed>
     */
    private function extractExtraBoost(array $config): array
    {
        $extra = $config['extra'] ?? null;
        if (! is_array($extra)) {
            return [];
        }

        $boost = $extra['boost'] ?? null;

        return is_array($boost) ? $boost : [];
    }

    private function resolveIfDir(string $installPath, string $relative): ?string
    {
        $abs = $installPath . '/' . ltrim($relative, '/');

        return is_dir($abs) ? $abs : null;
    }
}
