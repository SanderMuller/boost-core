<?php declare(strict_types=1);

namespace SanderMuller\BoostCore\Agents;

use SanderMuller\BoostCore\Enums\Agent;

final class ClaudeCodeTarget extends AgentTarget
{
    public function agent(): Agent
    {
        return Agent::CLAUDE_CODE;
    }

    public function skillsDirectoryRelative(): string
    {
        return '.claude/skills';
    }

    public function guidelinesFileRelative(): string
    {
        return 'CLAUDE.md';
    }

    public function commandsDirectoryRelative(): string
    {
        return '.claude/commands';
    }
}
