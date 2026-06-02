<?php declare(strict_types=1);

namespace SanderMuller\BoostCore\Skills;

use SanderMuller\BoostCore\Config\BoostConfig;

/**
 * Drops vendor skills a project does not want, before collision resolution.
 *
 * A skill is dropped for one of three reasons:
 *  1. **Malformed tags** — `tagsValid` false (`metadata.boost-tags` parsed
 *     into junk) fails closed and ships nowhere.
 *  2. **Explicit exclude** — the skill matches a `vendor/package:skill-name`
 *     entry in the consumer's `withExcludedSkills()` deny-list.
 *  3. **Tag mismatch** — the skill's tags are not a subset of the consumer's
 *     declared tags (`boost.php` `withTags()`).
 *
 * Untagged skills (empty tag set) pass the subset check trivially, so the
 * filter is inert until skills declare tags and consumers declare theirs.
 *
 * Applied per-vendor in `SyncEngine::resolveSkills()` BEFORE `SkillResolver`
 * runs, so only shippable skills enter collision resolution — see the spec
 * (`internal/specs/tag-skill-filtering.md`, §5) for why pre-resolve is
 * correctness, not convenience.
 *
 * The `droppedByTag` return count is the signal driving the post-sync
 * silent-filter nudge (consumed via {@see TagFilterNudge}): only
 * tag-mismatch drops are nudge-worthy. Excluded and malformed drops are
 * intentional / broken-content and would mislead the consumer if reported
 * as "your `withTags()` is empty."
 *
 * @internal
 */
final class SkillTagFilter
{
    private const KEEP = 'keep';

    private const DROP_MALFORMED = 'malformed';

    private const DROP_EXCLUDED = 'excluded';

    private const DROP_TAG_MISMATCH = 'tag-mismatch';

    /**
     * @param  iterable<Skill>  $skills
     * @return array{kept: list<Skill>, droppedNames: list<string>, droppedByTag: int}
     */
    public function filter(iterable $skills, BoostConfig $config): array
    {
        /** @var list<Skill> $kept */
        $kept = [];
        /** @var list<string> $droppedNames */
        $droppedNames = [];
        $droppedByTag = 0;

        foreach ($skills as $skill) {
            $verdict = $this->classify($skill, $config);
            if ($verdict === self::KEEP) {
                $kept[] = $skill;

                continue;
            }

            $droppedNames[] = $skill->name;
            if ($verdict === self::DROP_TAG_MISMATCH) {
                ++$droppedByTag;
            }
        }

        return ['kept' => $kept, 'droppedNames' => $droppedNames, 'droppedByTag' => $droppedByTag];
    }

    private function classify(Skill $skill, BoostConfig $config): string
    {
        // Fail closed: a malformed `metadata.boost-tags` ships to no project.
        if (! $skill->tagsValid) {
            return self::DROP_MALFORMED;
        }

        if ($this->isExcluded($skill, $config)) {
            return self::DROP_EXCLUDED;
        }

        // Tag subset: every tag the skill declares must be a consumer tag.
        if (array_diff($skill->tags, $config->tags) !== []) {
            return self::DROP_TAG_MISMATCH;
        }

        return self::KEEP;
    }

    private function isExcluded(Skill $skill, BoostConfig $config): bool
    {
        $key = $skill->excludeKey();

        return $key !== null && $config->excludesSkill($key);
    }
}
