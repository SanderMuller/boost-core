<?php declare(strict_types=1);

namespace SanderMuller\BoostCore\Tests\Doubles\Wrappers\Throwing;

use RuntimeException;
use SanderMuller\BoostCore\Contracts\BoostWrapperContract;

/**
 * Test double for 0.11.0 exception-safety — `injectedEmitPaths()` throws.
 * Used to verify the engine catches Throwable and emits the per-package
 * exception warning instead of crashing the sync.
 */
final class BoostWrapper implements BoostWrapperContract
{
    public static function injectedEmitPaths(string $projectRoot): array
    {
        throw new RuntimeException('wrapper exploded on path resolution');
    }
}
