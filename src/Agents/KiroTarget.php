<?php declare(strict_types=1);

namespace SanderMuller\BoostCore\Agents;

use SanderMuller\BoostCore\Enums\Agent;
use SanderMuller\BoostCore\Sync\PendingWrite;

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

    /**
     * Kiro has no dedicated command directory — committed skills under
     * `.kiro/skills/<name>/` are invocable as `/<name>` slash-commands, so
     * a "command" maps to a skill-shaped emit. `planCommands` writes each
     * command as `.kiro/skills/<name>/SKILL.md` instead of a per-command
     * file in a separate directory. `commandsDirectoryRelative()` stays
     * null so gitignore/listing logic doesn't double-count the path.
     *
     * @return list<PendingWrite>
     */
    public function planCommands(array $commands): array
    {
        $writes = [];
        foreach ($commands as $command) {
            $writes[] = new PendingWrite(
                relativePath: $this->skillsDirectoryRelative() . '/' . $command->name . '/' . self::SKILL_FILE,
                content: $this->renderFrontmatter($command->frontmatter) . $command->body,
            );
        }

        return $writes;
    }
}
