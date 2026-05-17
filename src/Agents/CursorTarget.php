<?php

declare(strict_types=1);

namespace SanderMuller\BoostCore\Agents;

use SanderMuller\BoostCore\Enums\Agent;

final class CursorTarget extends AgentTarget
{
    public function agent(): Agent
    {
        return Agent::CURSOR;
    }

    public function skillsDirectoryRelative(): string
    {
        return '.cursor/skills';
    }

    public function guidelinesFileRelative(): string
    {
        return 'AGENTS.md';
    }
}
