<?php declare(strict_types=1);

namespace SanderMuller\BoostCore\Tests\Doubles\Wrappers\GuidelineClaim;

use SanderMuller\BoostCore\Contracts\BoostWrapperContract;

/**
 * Test double for 0.11.0 codex-review P1 — wrapper claims guideline file
 * paths that use ManagedRegion + operator-tracking (not full-file cleanup).
 * Engine must filter these from the gitignore-managed manifest to prevent
 * the data-loss path (wholesale-deletion on next stale sync would destroy
 * operator-authored content outside boost-core's markers).
 */
final class BoostWrapper implements BoostWrapperContract
{
    public static function injectedEmitPaths(string $projectRoot): array
    {
        return [
            'CLAUDE.md',
            'AGENTS.md',
            'GEMINI.md',
            '.agents/skills/legitimate-wrapper-file/SKILL.md',
        ];
    }
}
