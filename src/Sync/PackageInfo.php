<?php

declare(strict_types=1);

namespace SanderMuller\BoostCore\Sync;

final readonly class PackageInfo
{
    public function __construct(
        public string $name,
        public string $version,
        public string $installPath,
    ) {}
}
