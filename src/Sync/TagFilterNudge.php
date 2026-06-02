<?php declare(strict_types=1);

namespace SanderMuller\BoostCore\Sync;

use SanderMuller\BoostCore\Config\BoostConfig;

/**
 * Decides the post-sync nudge count for the silent-tag-filter foot-gun.
 *
 * Three real boost-stack repos (repo-new, package-boost-laravel, boost-skills'
 * own dogfood) shipped with `boost.php` that omitted `withTags(...)` and
 * silently filtered out every tagged vendor skill they were entitled to. The
 * fix: when sync drops vendor skills AND the consumer has not declared any
 * tags, surface a one-line note pointing at `vendor/bin/boost tags`.
 *
 * Decision lives here rather than in {@see SyncEngine} so the per-sync
 * counting stays out of the engine's cognitive-complexity budget. Pure
 * function — no state, no I/O.
 *
 * @internal
 */
final class TagFilterNudge
{
    /**
     * Nudge-worthy count of vendor skills dropped by the tag filter. Zero
     * when the consumer declared `withTags(...)` — filtering is then
     * intentional and a per-install reminder is noise.
     *
     * `$tagFilterDropCount` is the SUM of tag-mismatch drops across all
     * allowed vendors — NOT the total `SkillTagFilter` drop count (which
     * also includes `withExcludedSkills` and malformed-frontmatter drops;
     * neither belongs in this nudge). Callers should pass the
     * `droppedByTag` aggregate from {@see SkillTagFilter::filter}.
     */
    public static function count(BoostConfig $config, int $tagFilterDropCount): int
    {
        if ($config->tags !== []) {
            return 0;
        }

        return $tagFilterDropCount;
    }
}
