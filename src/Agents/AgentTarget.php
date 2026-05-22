<?php declare(strict_types=1);

namespace SanderMuller\BoostCore\Agents;

use SanderMuller\BoostCore\Enums\Agent;
use SanderMuller\BoostCore\Skills\Command;
use SanderMuller\BoostCore\Skills\Guideline;
use SanderMuller\BoostCore\Skills\Skill;
use SanderMuller\BoostCore\Sync\PendingWrite;
use Symfony\Component\Yaml\Yaml;

/**
 * Abstract base for per-agent fan-out targets.
 *
 * Subclasses declare the directory layout (where skills go, where guidelines
 * concatenate) and inherit default formatters that pass frontmatter + body
 * through unchanged. Override formatters if the agent expects a transformed
 * shape.
 */
abstract class AgentTarget
{
    /**
     * Canonical filename inside each `<name>/` skill directory. Centralised
     * so the same literal isn't hard-coded in both the writer (this class)
     * and the legacy-prune logic in SyncEngine.
     */
    public const string SKILL_FILE = 'SKILL.md';

    abstract public function agent(): Agent;

    /**
     * Where skill files are written, relative to project root. Example: `.claude/skills`.
     */
    abstract public function skillsDirectoryRelative(): string;

    /**
     * Where the concatenated guidelines file goes, or null if the agent has no
     * single guidelines file. Example: `CLAUDE.md`.
     */
    abstract public function guidelinesFileRelative(): ?string;

    /**
     * Where command files are written, relative to project root — or null
     * when the agent has no dedicated command directory boost-core targets.
     * Example: `.claude/commands`. Base default is null (no command surface);
     * the command-capable targets override.
     */
    public function commandsDirectoryRelative(): ?string
    {
        return null;
    }

    /**
     * File extension for an emitted command, without the leading dot.
     * Copilot overrides → `prompt.md`.
     */
    public function commandFileExtension(): string
    {
        return 'md';
    }

    /**
     * Paths this target owns, suitable for a managed `.gitignore` block.
     * Returns directory entries with a trailing `/` and file entries verbatim.
     *
     * @return list<string>
     */
    public function gitignorePatterns(): array
    {
        $patterns = [$this->skillsDirectoryRelative() . '/'];
        $guidelines = $this->guidelinesFileRelative();
        if ($guidelines !== null) {
            $patterns[] = $guidelines;
        }

        $commands = $this->commandsDirectoryRelative();
        if ($commands !== null) {
            $patterns[] = $commands . '/';
        }

        return $patterns;
    }

    /**
     * Produce the set of writes for the given skills + guidelines.
     *
     * @param  list<Skill>  $skills
     * @param  list<Guideline>  $guidelines
     * @return list<PendingWrite>
     */
    public function plan(array $skills, array $guidelines): array
    {
        $writes = [];

        foreach ($skills as $skill) {
            $writes[] = new PendingWrite(
                relativePath: $this->skillsDirectoryRelative() . '/' . $this->skillRelativePath($skill),
                content: $this->formatSkillContent($skill),
            );
        }

        $guidelinesFile = $this->guidelinesFileRelative();
        if ($guidelinesFile !== null && $guidelines !== []) {
            $writes[] = new PendingWrite(
                relativePath: $guidelinesFile,
                content: $this->formatGuidelinesContent($guidelines),
            );
        }

        return $writes;
    }

    /**
     * Produce the set of writes for the given commands — one file per command
     * in the agent's command directory. Empty when the agent has no command
     * directory ({@see commandsDirectoryRelative()} null).
     *
     * @param  list<Command>  $commands
     * @return list<PendingWrite>
     */
    public function planCommands(array $commands): array
    {
        $directory = $this->commandsDirectoryRelative();
        if ($directory === null) {
            return [];
        }

        $writes = [];
        foreach ($commands as $command) {
            $writes[] = new PendingWrite(
                relativePath: $directory . '/' . $command->name . '.' . $this->commandFileExtension(),
                content: $this->formatCommandContent($command),
            );
        }

        return $writes;
    }

    /**
     * Where the rendered skill lives, relative to `skillsDirectoryRelative()`.
     *
     * Always emits the directory form `<name>/SKILL.md`. Claude Code (and
     * the other agents boost-core targets) only auto-discover skills under
     * the nested-directory layout — flat `<name>.md` outputs are silently
     * ignored, so source-layout-mirror semantics aren't useful here.
     */
    public function skillRelativePath(Skill $skill): string
    {
        return $skill->name . '/' . self::SKILL_FILE;
    }

    public function formatSkillContent(Skill $skill): string
    {
        return $this->renderFrontmatter($skill->frontmatter) . $skill->body;
    }

    /**
     * Render one command to its on-disk form. Default: frontmatter + body, as
     * the frontmatter-aware agents (Claude Code, Copilot, Junie, OpenCode)
     * expect. Cursor and Amp override to body-only — their command formats
     * treat the whole file as the prompt, so a frontmatter block would leak
     * into it.
     */
    public function formatCommandContent(Command $command): string
    {
        return $this->renderFrontmatter($command->frontmatter) . $command->body;
    }

    /**
     * Default: concat guideline bodies separated by `---` horizontal rules.
     *
     * @param  list<Guideline>  $guidelines
     */
    public function formatGuidelinesContent(array $guidelines): string
    {
        $bodies = array_map(static fn (Guideline $g): string => rtrim($g->body, "\n"), $guidelines);

        return implode("\n\n---\n\n", $bodies) . "\n";
    }

    /**
     * @param  array<string, mixed>  $frontmatter
     */
    protected function renderFrontmatter(array $frontmatter): string
    {
        if ($frontmatter === []) {
            return '';
        }

        return "---\n" . Yaml::dump($frontmatter, inline: 4, indent: 2) . '---' . "\n\n";
    }
}
