<?php declare(strict_types=1);

namespace SanderMuller\BoostCore\Agents;

use SanderMuller\BoostCore\Enums\Agent;

final class CodexTarget extends AgentTarget
{
    public function agent(): Agent
    {
        return Agent::CODEX;
    }

    public function skillsDirectoryRelative(): string
    {
        return '.agents/skills';
    }

    public function guidelinesFileRelative(): string
    {
        return 'AGENTS.md';
    }
}
