<?php declare(strict_types=1);

namespace SanderMuller\BoostCore\Agents;

use SanderMuller\BoostCore\Enums\Agent;

final class JunieTarget extends AgentTarget
{
    public function agent(): Agent
    {
        return Agent::JUNIE;
    }

    public function skillsDirectoryRelative(): string
    {
        return '.junie/skills';
    }

    public function guidelinesFileRelative(): string
    {
        return 'AGENTS.md';
    }
}
