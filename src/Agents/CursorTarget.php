<?php declare(strict_types=1);

namespace SanderMuller\BoostCore\Agents;

use SanderMuller\BoostCore\Enums\Agent;
use SanderMuller\BoostCore\Skills\Command;

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

    public function commandsDirectoryRelative(): string
    {
        return '.cursor/commands';
    }

    /**
     * Cursor commands treat the whole file as the prompt — emit body only,
     * so a frontmatter block does not leak into it.
     */
    public function formatCommandContent(Command $command): string
    {
        return $command->body;
    }
}
