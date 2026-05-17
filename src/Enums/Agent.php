<?php

declare(strict_types=1);

namespace SanderMuller\BoostCore\Enums;

/**
 * The nine AI agents boost-core fans out to.
 *
 * Per the architecture plan's "all 9 from day one" decision. Per-agent
 * transformation details (directory layout, naming, frontmatter quirks)
 * live with each AgentTarget implementation.
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
}
