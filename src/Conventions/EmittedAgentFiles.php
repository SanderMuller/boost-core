<?php declare(strict_types=1);

namespace SanderMuller\BoostCore\Conventions;

use SanderMuller\BoostCore\Agents\AgentTarget;
use SanderMuller\BoostCore\Agents\AmpTarget;
use SanderMuller\BoostCore\Agents\ClaudeCodeTarget;
use SanderMuller\BoostCore\Agents\CodexTarget;
use SanderMuller\BoostCore\Agents\CopilotTarget;
use SanderMuller\BoostCore\Agents\CursorTarget;
use SanderMuller\BoostCore\Agents\GeminiTarget;
use SanderMuller\BoostCore\Agents\JunieTarget;
use SanderMuller\BoostCore\Agents\KiroTarget;
use SanderMuller\BoostCore\Agents\OpenCodeTarget;
use SanderMuller\BoostCore\Config\BoostConfig;
use Symfony\Component\Finder\Finder;

/**
 * Enumerates the EMITTED files a conventions-token leak scan should inspect.
 *
 * Scope = the surfaces the inliner writes tokens INTO: per-agent guidance files
 * (CLAUDE.md / AGENTS.md / GEMINI.md) + per-agent emitted skill files
 * (`<skillsDir>/**​/SKILL.md`), for the active agent set only. Includes gitignored
 * per-agent copies (`.claude/skills/**`, …) — a leak there is invisible to a
 * tracked-files-only grep but is exactly what a consumer's agent reads (6tizs57y).
 *
 * Deliberately EXCLUDES `.ai/` sources (they legitimately carry tokens) and agent
 * command directories (commands are not a conventions-inlining target — the
 * inliner only runs over skills + guidelines, so a token in a command never
 * resolves and is a separate, out-of-scope concern).
 *
 * @internal
 */
final readonly class EmittedAgentFiles
{
    /**
     * @param  list<AgentTarget>  $targets
     */
    public function __construct(
        private array $targets,
    ) {}

    public static function default(): self
    {
        return new self([
            new ClaudeCodeTarget(),
            new CursorTarget(),
            new CopilotTarget(),
            new CodexTarget(),
            new GeminiTarget(),
            new JunieTarget(),
            new KiroTarget(),
            new OpenCodeTarget(),
            new AmpTarget(),
        ]);
    }

    /**
     * Existing emitted files to scan, as `{absolute, relative}` pairs. Relative
     * paths are project-root-relative for `<file>:<line>` reporting. De-duplicated
     * by relative path (agents that share a guidance file — e.g. several map to
     * AGENTS.md — are scanned once).
     *
     * @return list<array{absolute: string, relative: string}>
     */
    public function forConfig(string $projectRoot, BoostConfig $config): array
    {
        $root = rtrim($projectRoot, '/');
        $byRelative = [];

        foreach ($this->targets as $target) {
            if (! $config->hasAgent($target->agent())) {
                continue;
            }

            $guidance = $target->guidelinesFileRelative();
            if ($guidance !== null) {
                $absolute = $root . '/' . $guidance;
                if (is_file($absolute)) {
                    $byRelative[$guidance] = $absolute;
                }
            }

            foreach ($this->skillFiles($root, $target->skillsDirectoryRelative()) as $relative => $absolute) {
                $byRelative[$relative] = $absolute;
            }
        }

        ksort($byRelative);

        $out = [];
        foreach ($byRelative as $relative => $absolute) {
            $out[] = ['absolute' => $absolute, 'relative' => $relative];
        }

        return $out;
    }

    /**
     * @return array<string, string>  relative => absolute, for every SKILL.md
     *                                 under the agent's skills directory
     */
    private function skillFiles(string $root, string $skillsDirRelative): array
    {
        $skillsDir = $root . '/' . $skillsDirRelative;
        if (! is_dir($skillsDir)) {
            return [];
        }

        // Real-file skills under the tree. Links are NOT followed here: a cyclic
        // symlink beneath the skills root (e.g. `<skill>/loop -> ..`) would make
        // recursive link-following spin forever, so the broad walk stays link-free.
        $files = $this->scanSkillFiles($skillsDir, $skillsDirRelative);

        // A host shadow is often a user-placed directory symlink at the immediate
        // skill level (`.claude/skills/<name> -> ../../.ai/skills/<name>`). boost
        // declines to WRITE through such a symlink (FileWriter), so a raw token in
        // the symlinked source survives on the emitted surface — what the agent
        // actually reads. The walk above does not descend the symlink, so resolve
        // each ONE hop and scan its real target (still without following further
        // links — a cycle in the target cannot loop). Report each SKILL.md under
        // its EMITTED path, not the resolved `.ai/` realpath.
        foreach ($this->immediateSymlinkedDirs($skillsDir) as $name => $target) {
            foreach ($this->scanSkillFiles($target, $skillsDirRelative . '/' . $name) as $relative => $absolute) {
                $files[$relative] = $absolute;
            }
        }

        return $files;
    }

    /**
     * SKILL.md files under $dir (recursively), WITHOUT following symlinks, keyed by
     * `<relativePrefix>/<pathname>` => absolute realpath.
     *
     * @return array<string, string>
     */
    private function scanSkillFiles(string $dir, string $relativePrefix): array
    {
        $files = [];
        $finder = (new Finder())
            ->files()
            ->in($dir)
            ->name(AgentTarget::SKILL_FILE)
            ->ignoreDotFiles(false);

        foreach ($finder as $file) {
            $absolute = $file->getRealPath();
            if ($absolute === false) {
                continue;
            }

            $relative = $relativePrefix . '/' . str_replace('\\', '/', $file->getRelativePathname());
            $files[$relative] = $absolute;
        }

        return $files;
    }

    /**
     * Immediate children of $skillsDir that are symlinks to directories, as
     * `basename => resolved-target-absolute`. The one-hop host-shadow case;
     * recursive link-following is deliberately avoided (cycle-safe).
     *
     * @return array<string, string>
     */
    private function immediateSymlinkedDirs(string $skillsDir): array
    {
        $out = [];
        $entries = @scandir($skillsDir);
        if ($entries === false) {
            return $out;
        }

        foreach ($entries as $entry) {
            if ($entry === '.') {
                continue;
            }

            if ($entry === '..') {
                continue;
            }

            $path = $skillsDir . '/' . $entry;
            if (! is_link($path)) {
                continue;
            }

            $target = realpath($path);
            if ($target === false) {
                continue;
            }

            if (! is_dir($target)) {
                continue;
            }

            $out[$entry] = $target;
        }

        return $out;
    }
}
