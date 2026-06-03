<?php declare(strict_types=1);

namespace SanderMuller\BoostCore\Sync;

/**
 * One installed Composer package (name, version, install path) as exposed by
 * {@see InstalledPackages} to a {@see FileEmitter}.
 *
 * @api Stable as of 1.0 — part of the FileEmitter contract surface.
 */
final readonly class PackageInfo
{
    public function __construct(
        public string $name,
        public string $version,
        public string $installPath,
    ) {}
}
