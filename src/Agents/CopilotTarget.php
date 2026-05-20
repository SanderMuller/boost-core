<?php declare(strict_types=1);

namespace SanderMuller\BoostCore\Agents;

use SanderMuller\BoostCore\Enums\Agent;

final class CopilotTarget extends AgentTarget
{
    public function agent(): Agent
    {
        return Agent::COPILOT;
    }

    public function skillsDirectoryRelative(): string
    {
        return '.github/skills';
    }

    public function guidelinesFileRelative(): string
    {
        return '.github/copilot-instructions.md';
    }
}
