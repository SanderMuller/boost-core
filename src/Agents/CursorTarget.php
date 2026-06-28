<?php declare(strict_types=1);

namespace SanderMuller\BoostCore\Agents;

use Override;
use SanderMuller\BoostCore\Enums\Agent;
use SanderMuller\BoostCore\Skills\Command;

/**
 * @internal
 */
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
    #[Override]
    public function formatCommandContent(Command $command): string
    {
        return $command->body;
    }

    /**
     * Same body-only contract for the `planCommands()` transpile path:
     * drop the source frontmatter, keep only the transpiled body. The
     * base `transpileCommandBody()` emits placeholders verbatim AND
     * surfaces a "Cursor has no placeholder syntax" warning per command.
     */
    #[Override]
    protected function wrapTranspiledBody(Command $command, string $transpiledBody): string
    {
        return $transpiledBody;
    }
}
