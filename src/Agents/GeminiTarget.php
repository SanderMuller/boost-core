<?php declare(strict_types=1);

namespace SanderMuller\BoostCore\Agents;

use SanderMuller\BoostCore\Enums\Agent;

final class GeminiTarget extends AgentTarget
{
    public function agent(): Agent
    {
        return Agent::GEMINI;
    }

    public function skillsDirectoryRelative(): string
    {
        return '.gemini/skills';
    }

    public function guidelinesFileRelative(): string
    {
        return 'GEMINI.md';
    }
}
