<?php declare(strict_types=1);

namespace SanderMuller\BoostCore\Agents;

use SanderMuller\BoostCore\Enums\Agent;

final class OpenCodeTarget extends AgentTarget
{
    public function agent(): Agent
    {
        return Agent::OPENCODE;
    }

    public function skillsDirectoryRelative(): string
    {
        return '.opencode/skills';
    }

    public function guidelinesFileRelative(): string
    {
        return 'AGENTS.md';
    }
}
