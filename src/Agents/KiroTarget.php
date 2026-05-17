<?php

declare(strict_types=1);

namespace SanderMuller\BoostCore\Agents;

use SanderMuller\BoostCore\Enums\Agent;

final class KiroTarget extends AgentTarget
{
    public function agent(): Agent
    {
        return Agent::KIRO;
    }

    public function skillsDirectoryRelative(): string
    {
        return '.kiro/skills';
    }

    public function guidelinesFileRelative(): string
    {
        return 'AGENTS.md';
    }
}
