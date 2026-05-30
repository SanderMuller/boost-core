<?php declare(strict_types=1);

namespace SanderMuller\BoostCore\Tests\Doubles\Wrappers\DirectoryClaim;

use SanderMuller\BoostCore\Contracts\BoostWrapperContract;

/**
 * Test double for 0.11.0 codex-review P2 — wrapper claims a DIRECTORY
 * rather than individual files. The cleanup-pass exclusion check MUST
 * prefix-match so every file under the claimed directory is preserved.
 */
final class BoostWrapper implements BoostWrapperContract
{
    public static function injectedEmitPaths(string $projectRoot, array $activeAgents): array
    {
        return ['.agents/skills/wrapper-dir-claim'];
    }
}
