<?php declare(strict_types=1);

namespace SanderMuller\BoostCore\Config;

use RuntimeException;

/**
 * Thrown when a project has BOTH a root `boost.php` and a `.config/boost.php`.
 * boost-core uses exactly one config; silently picking one would risk the
 * operator editing the file boost ignores, so resolution fails loud.
 */
final class AmbiguousBoostConfigException extends RuntimeException
{
    public function __construct(
        public readonly string $rootPath,
        public readonly string $configDirPath,
    ) {
        parent::__construct(sprintf(
            "Two boost configs found:\n  - %s\n  - %s\n\nboost-core uses exactly one. Keep the one you want and delete the other.",
            $rootPath,
            $configDirPath,
        ));
    }
}
