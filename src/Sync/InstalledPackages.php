<?php

declare(strict_types=1);

namespace SanderMuller\BoostCore\Sync;

use Composer\InstalledVersions;
use LogicException;

final readonly class InstalledPackages
{
    /**
     * @param  array<string, PackageInfo>  $packages  keyed by composer package name
     */
    public function __construct(private array $packages) {}

    /**
     * Build from the host project's Composer runtime API.
     */
    public static function fromComposer(): self
    {
        $packages = [];

        foreach (InstalledVersions::getInstalledPackages() as $name) {
            $path = InstalledVersions::getInstallPath($name);
            if ($path === null) {
                continue;
            }

            $packages[$name] = new PackageInfo(
                name: $name,
                version: InstalledVersions::getVersion($name) ?? '',
                installPath: rtrim($path, '/'),
            );
        }

        return new self($packages);
    }

    public function has(string $name): bool
    {
        return isset($this->packages[$name]);
    }

    public function version(string $name): ?string
    {
        return $this->packages[$name]->version ?? null;
    }

    /**
     * Absolute install path for the named package. Throws if has() would return false —
     * the contract guarantees consistency within a single sync run.
     */
    public function path(string $name): string
    {
        if (! $this->has($name)) {
            throw new LogicException(sprintf(
                'Package "%s" is not installed; check has() before calling path().',
                $name,
            ));
        }

        return $this->packages[$name]->installPath;
    }

    /**
     * @return iterable<PackageInfo>
     */
    public function all(): iterable
    {
        return array_values($this->packages);
    }
}
