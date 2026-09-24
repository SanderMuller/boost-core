<?php declare(strict_types=1);

namespace SanderMuller\BoostCore\Sync;

use SanderMuller\BoostCore\Agents\ClaudeCodeTarget;
use SanderMuller\BoostCore\Config\BoostConfig;
use SanderMuller\BoostCore\Conventions\Diagnostic;
use SanderMuller\BoostCore\Enums\Agent;

/**
 * Claude Code guidance lives in `AGENTS.md` since 1.12.0. By default Claude
 * Code reads `AGENTS.md` only when the project has no `CLAUDE.md`,
 * `.claude/CLAUDE.md` or `CLAUDE.local.md`; any of those makes it load the
 * `CLAUDE.md` files instead and skip `AGENTS.md`. This check spots such a
 * file so the operator learns boost's guidance is not reaching Claude.
 *
 * A file that `@`-imports `AGENTS.md` is fine: Claude Code then loads the
 * guidance through the import, on every Claude Code version.
 *
 * @internal
 */
final class ClaudeInstructionShadowCheck
{
    /** Files that make Claude Code skip `AGENTS.md` under its default setting. */
    public const SHADOWING_FILES = ['CLAUDE.md', '.claude/CLAUDE.md', 'CLAUDE.local.md'];

    private const MESSAGE_PREFIX = 'boost-core writes Claude Code guidance to `AGENTS.md`';

    /**
     * The shadowing files present in the project root, or an empty list when
     * none exist or one of them imports `AGENTS.md`.
     *
     * @param  list<string>  $deletedPaths  relative paths this sync deletes (or would delete under --check)
     * @return list<string>
     */
    public static function shadowingFiles(string $projectRoot, array $deletedPaths = []): array
    {
        $guidanceFile = (new ClaudeCodeTarget())->guidelinesFileRelative();
        if (in_array($guidanceFile, self::SHADOWING_FILES, true)) {
            return [];
        }

        $present = [];
        foreach (self::SHADOWING_FILES as $relative) {
            if (in_array($relative, $deletedPaths, true)) {
                continue;
            }

            $absolute = $projectRoot . '/' . $relative;
            if (! is_file($absolute)) {
                continue;
            }

            $content = @file_get_contents($absolute);
            if ($content !== false && self::importsGuidanceFile($content, self::importPath($relative, $guidanceFile))) {
                return [];
            }

            $present[] = $relative;
        }

        return $present;
    }

    /**
     * Sync-time WARNING when boost emits Claude Code guidance this sync and a
     * shadowing file survives it.
     *
     * @param  list<WrittenFile>  $writes
     * @return list<Diagnostic>
     */
    public static function diagnostics(string $projectRoot, BoostConfig $config, array $writes, bool $emitsGuidance): array
    {
        if (! $emitsGuidance || ! $config->hasAgent(Agent::CLAUDE_CODE)) {
            return [];
        }

        $deleted = [];
        foreach ($writes as $write) {
            if ($write->action === WriteAction::DELETED || $write->action === WriteAction::WOULD_DELETE) {
                $deleted[] = $write->relativePath;
            }
        }

        $files = self::shadowingFiles($projectRoot, $deleted);
        if ($files === []) {
            return [];
        }

        return [Diagnostic::warning(null, self::message($files))];
    }

    public static function isShadowDiagnostic(Diagnostic $diagnostic): bool
    {
        return str_starts_with($diagnostic->message, self::MESSAGE_PREFIX);
    }

    /**
     * @param  list<string>  $files
     */
    public static function message(array $files): string
    {
        return sprintf(
            self::MESSAGE_PREFIX . ', but %s exists. By default Claude Code then reads only the CLAUDE.md files and skips `AGENTS.md`, so the boost guidance does not reach Claude. '
            . 'Fix one of: add an `@AGENTS.md` line to `CLAUDE.md` (`@../AGENTS.md` in `.claude/CLAUDE.md`); move the content into `.ai/guidelines/` and delete the file; or set Claude Code\'s "Project instructions" setting to `claude-md-and-agents-md`.',
            implode(', ', array_map(static fn (string $file): string => '`' . $file . '`', $files)),
        );
    }

    /**
     * Claude Code resolves an `@` import relative to the importing file, so
     * `.claude/CLAUDE.md` must import `@../AGENTS.md`.
     */
    private static function importPath(string $importingFile, string $guidanceFile): string
    {
        return str_repeat('../', substr_count($importingFile, '/')) . $guidanceFile;
    }

    private static function importsGuidanceFile(string $content, string $importPath): bool
    {
        return preg_match('/(?:^|\s)@(?:\.\/)?' . preg_quote($importPath, '/') . '(?:\s|$)/m', $content) === 1;
    }
}
