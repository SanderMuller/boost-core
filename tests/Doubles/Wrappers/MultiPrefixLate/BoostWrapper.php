<?php declare(strict_types=1);

namespace SanderMuller\BoostCore\Tests\Doubles\Wrappers\MultiPrefixLate;

use SanderMuller\BoostCore\Contracts\BoostWrapperContract;

/**
 * Test fixture for 0.11.0 multi-prefix discovery — the LATE PSR-4 prefix's
 * `BoostWrapper` class that DOES implement the contract. Paired with the
 * non-implementing `MultiPrefixEarly` class to verify the engine picks the
 * valid implementation regardless of declaration order.
 */
final class BoostWrapper implements BoostWrapperContract
{
    public static function injectedEmitPaths(string $projectRoot): array
    {
        return ['.agents/skills/multi-prefix-found/SKILL.md'];
    }
}
