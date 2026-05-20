<?php declare(strict_types=1);

namespace SanderMuller\BoostCore\Agents;

use SanderMuller\BoostCore\Enums\Agent;

final class AmpTarget extends AgentTarget
{
    public function agent(): Agent
    {
        return Agent::AMP;
    }

    public function skillsDirectoryRelative(): string
    {
        return '.amp/skills';
    }

    public function guidelinesFileRelative(): string
    {
        return 'AGENTS.md';
    }
}
