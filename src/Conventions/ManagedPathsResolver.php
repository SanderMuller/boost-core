<?php declare(strict_types=1);

namespace SanderMuller\BoostCore\Conventions;

use SanderMuller\BoostCore\Agents\AgentTarget;
use SanderMuller\BoostCore\Agents\AmpTarget;
use SanderMuller\BoostCore\Agents\ClaudeCodeTarget;
use SanderMuller\BoostCore\Agents\CodexTarget;
use SanderMuller\BoostCore\Agents\CopilotTarget;
use SanderMuller\BoostCore\Agents\CursorTarget;
use SanderMuller\BoostCore\Agents\GeminiTarget;
use SanderMuller\BoostCore\Agents\JunieTarget;
use SanderMuller\BoostCore\Agents\KiroTarget;
use SanderMuller\BoostCore\Agents\OpenCodeTarget;
use SanderMuller\BoostCore\Config\BoostConfig;

/**
 * Enumerates the path globs boost-core writes to in a project.
 *
 * Source of truth: each AgentTarget's `gitignorePatterns()` for currently
 * allowlisted agents (per BoostConfig). Vendor skills consume this via the
 * `boost paths` CLI to resolve "since the most recent code change" semantics
 * for pr.gates[].window (see spec §3.10).
 *
 * @internal
 */
final readonly class ManagedPathsResolver
{
    /**
     * @param  list<AgentTarget>  $agentTargets
     */
    public function __construct(
        private array $agentTargets,
    ) {}

    public static function default(): self
    {
        return new self([
            new ClaudeCodeTarget(),
            new CursorTarget(),
            new CopilotTarget(),
            new CodexTarget(),
            new GeminiTarget(),
            new JunieTarget(),
            new KiroTarget(),
            new OpenCodeTarget(),
            new AmpTarget(),
        ]);
    }

    /**
     * @return list<string>  managed path globs for the active agent set
     */
    public function patterns(BoostConfig $config): array
    {
        $patterns = [];
        foreach ($this->agentTargets as $target) {
            if (! $config->hasAgent($target->agent())) {
                continue;
            }

            foreach ($target->gitignorePatterns() as $pattern) {
                $patterns[] = $pattern;
            }
        }

        return array_values(array_unique($patterns));
    }
}
