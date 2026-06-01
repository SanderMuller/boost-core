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

        $files = [];
        $finder = (new Finder())
            ->files()
            ->in($skillsDir)
            ->name(AgentTarget::SKILL_FILE)
            ->ignoreDotFiles(false);

        foreach ($finder as $file) {
            $absolute = $file->getRealPath();
            if ($absolute === false) {
                continue;
            }

            $relative = $skillsDirRelative . '/' . str_replace('\\', '/', $file->getRelativePathname());
            $files[$relative] = $absolute;
        }

        return $files;
    }
}
