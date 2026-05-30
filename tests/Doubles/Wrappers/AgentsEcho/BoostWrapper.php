<?php declare(strict_types=1);

namespace SanderMuller\BoostCore\Tests\Doubles\Wrappers\AgentsEcho;

use SanderMuller\BoostCore\Contracts\BoostWrapperContract;

/**
 * Test double for 0.11.0 codex-review P1.2 — echoes the received
 * `$activeAgents` into the emit paths so the test can assert the engine
 * forwards the active-agent context (the input wrappers need to compute
 * per-agent emit paths).
 */
final class BoostWrapper implements BoostWrapperContract
{
    /**
     * @return list<string>
     */
    public static function injectedEmitPaths(string $projectRoot, array $activeAgents): array
    {
        $paths = [];
        foreach ($activeAgents as $agent) {
            $paths[] = '.agents/skills/echo-' . $agent . '/SKILL.md';
        }

        return $paths;
    }
}
