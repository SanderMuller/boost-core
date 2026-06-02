<?php declare(strict_types=1);

namespace SanderMuller\BoostCore\Agents;

use SanderMuller\BoostCore\Enums\Agent;
use SanderMuller\BoostCore\Skills\Command;

/**
 * @internal
 */
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

    public function commandsDirectoryRelative(): string
    {
        return '.agents/commands';
    }

    /**
     * Amp commands carry no frontmatter — the whole file is the prompt.
     * Emit body only, so a frontmatter block does not leak into it.
     */
    public function formatCommandContent(Command $command): string
    {
        return $command->body;
    }

    /**
     * Same body-only contract for the `planCommands()` transpile path:
     * drop the source frontmatter, keep only the transpiled body. The
     * base `transpileCommandBody()` emits placeholders verbatim AND
     * surfaces an "Amp has no placeholder syntax" warning per command.
     */
    protected function wrapTranspiledBody(Command $command, string $transpiledBody): string
    {
        return $transpiledBody;
    }
}
