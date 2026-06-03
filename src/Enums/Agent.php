<?php declare(strict_types=1);

namespace SanderMuller\BoostCore\Enums;

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

/**
 * The nine AI agents boost-core fans out to.
 *
 * Per-agent transformation details (directory layout, naming, frontmatter
 * quirks) live with each AgentTarget implementation.
 *
 * @api
 */
enum Agent: string
{
    case CLAUDE_CODE = 'claude-code';
    case CURSOR = 'cursor';
    case COPILOT = 'copilot';
    case CODEX = 'codex';
    case GEMINI = 'gemini';
    case JUNIE = 'junie';
    case KIRO = 'kiro';
    case OPENCODE = 'opencode';
    case AMP = 'amp';

    /**
     * The fan-out target for this agent — its directory layout + path/identity
     * methods. The `@api` bridge from an agent value to its target, so a wrapper
     * implementing `BoostWrapperContract` computes per-agent emit paths
     * (`skillsDirectoryRelative()`, `skillRelativePathForName()`, …) from a
     * `list<Agent>` without touching the engine-internal concrete target classes.
     *
     * @api Stable as of 1.0. The returned {@see AgentTarget}'s `@api` path/identity
     * surface is the contract; the concrete subclass behind it stays internal.
     */
    public function target(): AgentTarget
    {
        return match ($this) {
            self::CLAUDE_CODE => new ClaudeCodeTarget(),
            self::CURSOR => new CursorTarget(),
            self::COPILOT => new CopilotTarget(),
            self::CODEX => new CodexTarget(),
            self::GEMINI => new GeminiTarget(),
            self::JUNIE => new JunieTarget(),
            self::KIRO => new KiroTarget(),
            self::OPENCODE => new OpenCodeTarget(),
            self::AMP => new AmpTarget(),
        };
    }
}
