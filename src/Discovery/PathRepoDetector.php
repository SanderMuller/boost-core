<?php declare(strict_types=1);

namespace SanderMuller\BoostCore\Discovery;

use SanderMuller\BoostCore\Sync\InstalledPackages;

/**
 * Identifies installed packages whose source lives outside the project's
 * `vendor/` directory — the signature of a Composer `path` repo (or a
 * VCS repo with symlinks).
 *
 * Path repos silently win over Packagist resolution for the matching
 * constraint, so a stale path repo from an earlier dogfood window can
 * shadow newer published versions. Surfaced by `boost doctor
 * --check-versions` for the family-package allowlist.
 */
final readonly class PathRepoDetector
{
    public function __construct(
        private InstalledPackages $packages,
        private FirstPartyPrefixes $prefixes = new FirstPartyPrefixes(),
    ) {}

    /**
     * Return the names of installed first-party packages whose resolved
     * install path is OUTSIDE `<projectRoot>/vendor/`. Empty list means
     * the routine Packagist-resolved layout — nothing to flag.
     *
     * @return list<string>
     */
    public function findShadowingPackages(string $projectRoot): array
    {
        $vendorRoot = rtrim($projectRoot, '/') . '/vendor';
        $vendorRootReal = realpath($vendorRoot);
        if ($vendorRootReal === false) {
            return [];
        }

        $vendorRootReal = rtrim($vendorRootReal, '/') . '/';

        $shadowing = [];
        foreach ($this->packages->all() as $package) {
            if (! $this->prefixes->matches($package->name)) {
                continue;
            }

            $installPathReal = realpath($package->installPath);
            if ($installPathReal === false) {
                continue;
            }

            if (! str_starts_with($installPathReal . '/', $vendorRootReal)) {
                $shadowing[] = $package->name;
            }
        }

        return $shadowing;
    }
}
