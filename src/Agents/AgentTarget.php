<?php

declare(strict_types=1);

namespace SanderMuller\BoostCore\Agents;

use SanderMuller\BoostCore\Enums\Agent;
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
                relativePath: $this->skillsDirectoryRelative() . '/' . $this->skillFilename($skill->name),
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

    public function skillFilename(string $skillName): string
    {
        return $skillName . '.md';
    }

    public function formatSkillContent(Skill $skill): string
    {
        return $this->renderFrontmatter($skill->frontmatter) . $skill->body;
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
