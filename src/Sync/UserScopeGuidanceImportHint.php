<?php declare(strict_types=1);

namespace SanderMuller\BoostCore\Sync;

use SanderMuller\BoostCore\Agents\ClaudeCodeTarget;

/**
 * Tells the operator the import line a user-scope guidance file needs.
 *
 * Claude Code reads `~/.claude/boost/<slug>.md` only through an `@`-import in
 * `~/.claude/CLAUDE.md`. Boost never edits that file, so without this hint a
 * published guidance file reaches no session and nothing says why.
 *
 * Read-only: it reads `~/.claude/CLAUDE.md` and never writes it. Silent under
 * `--check`, when this run published no Claude Code guidance file, and once
 * the file imports the guidance in any form that ends in `boost/<slug>.md`.
 *
 * @internal
 */
final readonly class UserScopeGuidanceImportHint
{
    private const string CLAUDE_MD = '.claude/CLAUDE.md';

    /**
     * Zero or one hint, as a list so the caller can spread it into its warnings.
     *
     * @param  list<WrittenFile>  $writes  this run's writes
     * @return list<string>
     */
    public static function forRun(string $home, string $slug, array $writes, bool $checkOnly): array
    {
        if ($checkOnly) {
            return [];
        }

        $guidance = (new ClaudeCodeTarget())->userScopeGuidanceFileRelative($slug);
        if (! self::published($guidance, $writes)) {
            return [];
        }

        $claudeMd = $home . '/' . self::CLAUDE_MD;
        $contents = is_file($claudeMd) ? @file_get_contents($claudeMd) : '';
        if (is_string($contents) && self::imports($contents, $slug)) {
            return [];
        }

        return [sprintf(
            'Claude Code loads ~/%s only through an import. Add this line to ~/%s: @boost/%s.md',
            $guidance,
            self::CLAUDE_MD,
            $slug,
        )];
    }

    /**
     * @param  list<WrittenFile>  $writes
     */
    private static function published(string $guidance, array $writes): bool
    {
        foreach ($writes as $write) {
            if ($write->relativePath === $guidance && ($write->action === WriteAction::WROTE || $write->action === WriteAction::UNCHANGED)) {
                return true;
            }
        }

        return false;
    }

    private static function imports(string $contents, string $slug): bool
    {
        return preg_match('/(^|\s)@\S*boost\/' . preg_quote($slug, '/') . '\.md(\s|$)/m', $contents) === 1;
    }
}
