<?php declare(strict_types=1);

namespace SanderMuller\BoostCore\Tests\Doubles\Wrappers\HappyPath;

use SanderMuller\BoostCore\Contracts\BoostWrapperContract;

/**
 * Test double for 0.11.0 wrapper-discovery — implements the contract,
 * returns a deterministic list of injected emit-paths. Used as the
 * "wrapper-installed bare-CLI happy path" fixture across SyncEngine and
 * WrapperEmitDiscovery tests.
 */
final class BoostWrapper implements BoostWrapperContract
{
    public static function injectedEmitPaths(string $projectRoot): array
    {
        return [
            '.agents/skills/wrapper-injected-foo/SKILL.md',
            '.agents/skills/wrapper-injected-bar/SKILL.md',
        ];
    }
}
