<?php declare(strict_types=1);

namespace SanderMuller\BoostCore\Tests\Doubles\Wrappers\MessyPaths;

use SanderMuller\BoostCore\Contracts\BoostWrapperContract;

/**
 * Test double for 0.11.0 codex-review path-canonicalization pin — returns
 * paths in non-canonical forms (embedded `./`, leading `./`, backslashes,
 * duplicate slashes, trailing slash, and a `..`-traversal that must be
 * rejected). The engine MUST normalize to canonical project-root-relative
 * form so the cleanup-pass union comparison matches the on-disk path.
 */
final class BoostWrapper implements BoostWrapperContract
{
    public static function injectedEmitPaths(string $projectRoot): array
    {
        return [
            './.agents/skills/foo/./SKILL.md',          // leading + embedded ./
            '.agents\\skills\\bar\\SKILL.md',           // backslashes
            '.agents//skills//baz/SKILL.md',            // duplicate slashes
            '.agents/skills/qux/SKILL.md/',             // trailing slash
            '../escapes/outside.md',                    // .. traversal — must be rejected
        ];
    }
}
