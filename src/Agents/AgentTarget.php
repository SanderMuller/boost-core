<?php declare(strict_types=1);

namespace SanderMuller\BoostCore\Agents;

use SanderMuller\BoostCore\Enums\Agent;
use SanderMuller\BoostCore\Skills\ArgumentParser;
use SanderMuller\BoostCore\Skills\ArgumentToken;
use SanderMuller\BoostCore\Skills\Command;
use SanderMuller\BoostCore\Skills\CommandTranspileResult;
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
 *
 * @api Only the path/identity methods (`agent`, `skillsDirectoryRelative`,
 *      `guidelinesFileRelative`, `commandsDirectoryRelative`,
 *      `commandFileExtension`, `gitignorePatterns`) are the committed surface —
 *      wrapper packages read these to compute emit paths. The `plan`/`format*`/
 *      `transpile*` methods are internal engine machinery (each carries its own
 *      internal-tag). Subclassing AgentTarget is the engine's own agent-registry
 *      extension point, NOT a consumer seam. 1.x guarantee: no NEW abstract
 *      method will be added (any future hook ships with a default), so the
 *      in-tree targets and any subclass keep compiling.
 */
abstract class AgentTarget
{
    /**
     * Canonical filename inside each `<name>/` skill directory. Centralised
     * so the same literal isn't hard-coded in both the writer (this class)
     * and the prune logic in SyncEngine.
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
     * Skill + command directories ARE listed — 100% generated from `.ai/`.
     * The guideline file (CLAUDE.md, AGENTS.md, GEMINI.md) is NOT listed:
     * operators keep these files in version control to see what the agent
     * reads. The guidance file is wholesale boost-owned (markerless);
     * operator custom content lives in `.ai/guidelines/`, not in the
     * emission target. Keeping the file tracked (not gitignored) preserves
     * the operator's ability to review boost's output in diffs.
     *
     * @return list<string>
     */
    public function gitignorePatterns(): array
    {
        $patterns = [$this->skillsDirectoryRelative() . '/'];

        $commands = $this->commandsDirectoryRelative();
        if ($commands !== null) {
            $patterns[] = $commands . '/';
        }

        return $patterns;
    }

    /**
     * Produce the per-skill writes for this agent. The guideline file is NOT
     * planned here.
     *
     * The agent-guidance file (CLAUDE.md / AGENTS.md / GEMINI.md) is written
     * wholesale + markerless centrally by SyncEngine, which reads
     * `guidelinesFileRelative()` + `formatGuidelinesContent()` directly.
     * `$guidelines` is accepted for signature stability but unused here.
     *
     * @param  list<Skill>  $skills
     * @param  list<Guideline>  $guidelines
     * @return list<PendingWrite>
     *
     * @internal Engine emit-planning — operates on @internal Skill/PendingWrite types.
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

        return $writes;
    }

    /**
     * Produce the set of writes for the given commands — one file per command
     * in the agent's command directory. Empty when the agent has no command
     * directory ({@see commandsDirectoryRelative()} null).
     *
     * Returns `{writes, warnings}` so per-command transpile warnings
     * (e.g. "Cursor has no placeholder support; body emitted verbatim")
     * thread back up to `SyncResult::errors`. Warnings are lenient — they
     * surface to the operator but never abort the sync.
     *
     * @param  list<Command>  $commands
     * @return array{writes: list<PendingWrite>, warnings: list<string>}
     *
     * @internal Engine emit-planning — operates on @internal Command/PendingWrite types.
     */
    public function planCommands(array $commands): array
    {
        $directory = $this->commandsDirectoryRelative();
        if ($directory === null) {
            return ['writes' => [], 'warnings' => []];
        }

        $writes = [];
        $warnings = [];
        foreach ($commands as $command) {
            $transpiled = $this->transpileCommandBody($command);
            $writes[] = new PendingWrite(
                relativePath: $directory . '/' . $command->name . '.' . $this->commandFileExtension(),
                content: $this->wrapTranspiledBody($command, $transpiled->content),
            );
            foreach ($transpiled->warnings as $warning) {
                $warnings[] = sprintf('[%s] %s: %s', $this->agent()->value, $command->name, $warning);
            }
        }

        return ['writes' => $writes, 'warnings' => $warnings];
    }

    /**
     * Wrap a transpiled body with this agent's frontmatter shape. Default
     * = `formatCommandContent` behaviour (frontmatter + body). Cursor and
     * Amp override to body-only since their formats can't carry
     * frontmatter without leaking it into the prompt.
     */
    protected function wrapTranspiledBody(Command $command, string $transpiledBody): string
    {
        return $this->renderFrontmatter($command->frontmatter) . $transpiledBody;
    }

    /**
     * Where the rendered skill lives, relative to `skillsDirectoryRelative()`.
     *
     * Always emits the directory form `<name>/SKILL.md`. Claude Code (and
     * the other agents boost-core targets) only auto-discover skills under
     * the nested-directory layout — flat `<name>.md` outputs are silently
     * ignored, so source-layout-mirror semantics aren't useful here.
     *
     * @internal Takes the engine-internal Skill type — wrappers call the
     * `@api` string-based {@see skillRelativePathForName()} instead.
     */
    public function skillRelativePath(Skill $skill): string
    {
        return $this->skillRelativePathForName($skill->name);
    }

    /**
     * Where a skill named `$skillName` is emitted, relative to
     * `skillsDirectoryRelative()` — always the directory form `<name>/SKILL.md`.
     *
     * The string-based companion to {@see skillRelativePath()}, so a wrapper
     * implementing {@see BoostWrapperContract}
     * can compute its injected skills' emit paths without constructing an
     * engine-internal Skill.
     *
     * @api Stable as of 1.0.
     */
    public function skillRelativePathForName(string $skillName): string
    {
        return $skillName . '/' . self::SKILL_FILE;
    }

    /**
     * @internal Takes the @internal Skill type.
     */
    public function formatSkillContent(Skill $skill): string
    {
        return $this->renderFrontmatter($skill->frontmatter) . $skill->body;
    }

    /**
     * Render one command to its on-disk form WITHOUT argument transpilation.
     * Kept for callers that want the raw body (tests, doctor diagnostics).
     * The `planCommands()` emit path goes through `transpileCommandBody()`
     * + `wrapTranspiledBody()` to apply per-agent argument rules.
     *
     * @internal Takes the @internal Command type.
     */
    public function formatCommandContent(Command $command): string
    {
        return $this->renderFrontmatter($command->frontmatter) . $command->body;
    }

    /**
     * Transpile the command body's canonical argument placeholders
     * (`$ARGUMENTS`, `$1`, `$name`) into this agent's native shape.
     *
     * Default = "no placeholder support" — every placeholder produces a
     * warning and is emitted verbatim. Cursor and Amp use this default
     * (their command formats document no placeholder syntax). Every
     * other emit-capable target overrides with its agent-specific rules.
     *
     * Spec: `internal/specs/agent-commands-sync.md` Phase 3.
     *
     * @internal Operates on @internal Command / CommandTranspileResult types.
     */
    public function transpileCommandBody(Command $command): CommandTranspileResult
    {
        $tokens = (new ArgumentParser())->parse($command->body);
        $hasPlaceholder = false;
        $out = '';

        foreach ($tokens as $token) {
            if ($token->kind === ArgumentToken::KIND_LITERAL) {
                $out .= $token->value;

                continue;
            }

            $hasPlaceholder = true;
            $out .= match ($token->kind) {
                ArgumentToken::KIND_ARGUMENTS => '$ARGUMENTS',
                ArgumentToken::KIND_POSITIONAL => '$' . $token->position,
                ArgumentToken::KIND_NAMED => '$' . $token->value,
                default => '',
            };
        }

        $warnings = $hasPlaceholder
            ? [sprintf('%s has no placeholder syntax; canonical placeholders emitted verbatim.', $this->agent()->value)]
            : [];

        return new CommandTranspileResult(content: $out, warnings: $warnings);
    }

    /**
     * Default: concat guideline bodies separated by `---` horizontal rules.
     *
     * @param  list<Guideline>  $guidelines
     *
     * @internal Operates on @internal Guideline types.
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
