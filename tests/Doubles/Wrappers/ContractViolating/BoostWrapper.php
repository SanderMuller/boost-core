<?php declare(strict_types=1);

namespace SanderMuller\BoostCore\Tests\Doubles\Wrappers\ContractViolating;

/**
 * Test double for 0.11.0 contract-violation warning — declares a class
 * named `BoostWrapper` but does NOT implement `BoostWrapperContract`. Used
 * to verify the pinned contract-violation warning wording fires.
 */
final class BoostWrapper
{
    /**
     * @return array<int, string>
     */
    public static function injectedEmitPaths(): array
    {
        return ['anything'];
    }
}
