<?php declare(strict_types=1);

namespace SanderMuller\BoostCore\Tests\Doubles\Wrappers\WrongType;

use SanderMuller\BoostCore\Contracts\BoostWrapperContract;

/**
 * Test double for 0.11.0 type-validation — `injectedEmitPaths()` returns
 * non-string entries (violates the declared `list<string>` contract). The
 * return-type-violation is intentional for the test fixture — engine should
 * detect it at runtime and emit the type-validation warning.
 *
 * Implements the interface to satisfy contract-check; the wrong-type signal
 * is engine-side runtime validation.
 */
final class BoostWrapper implements BoostWrapperContract
{
    public static function injectedEmitPaths(string $projectRoot): array
    {
        /** @phpstan-ignore-next-line return.type  -- intentional contract violation for test fixture */
        return ['ok', 12345, 'also-ok'];
    }
}
