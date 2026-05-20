<?php declare(strict_types=1);

namespace SanderMuller\BoostCore\Skills;

use SanderMuller\BoostCore\Config\BoostConfig;

/**
 * Drops vendor skills a project does not want, before collision resolution.
 *
 * A skill is dropped for one of two reasons:
 *  1. **Tag mismatch** — the skill's tags are not a subset of the consumer's
 *     declared tags (`boost.php` `withTags()`); or the skill's tags are
 *     invalid (`tagsValid` false → a malformed `metadata.boost-tags`, which
 *     fails closed and ships nowhere).
 *  2. **Explicit exclude** — the skill matches a `vendor/package:skill-name`
 *     entry in the consumer's `withExcludedSkills()` deny-list.
 *
 * Untagged skills (empty tag set) pass the subset check trivially, so the
 * filter is inert until skills declare tags and consumers declare theirs.
 *
 * Applied per-vendor in `SyncEngine::resolveSkills()` BEFORE `SkillResolver`
 * runs, so only shippable skills enter collision resolution — see the spec
 * (`internal/specs/tag-skill-filtering.md`, §5) for why pre-resolve is
 * correctness, not convenience.
 */
final class SkillTagFilter
{
    /**
     * @param  iterable<Skill>  $skills
     * @return array{kept: list<Skill>, droppedNames: list<string>}
     */
    public function filter(iterable $skills, BoostConfig $config): array
    {
        /** @var list<Skill> $kept */
        $kept = [];
        /** @var list<string> $droppedNames */
        $droppedNames = [];

        foreach ($skills as $skill) {
            if ($this->isKept($skill, $config)) {
                $kept[] = $skill;
            } else {
                $droppedNames[] = $skill->name;
            }
        }

        return ['kept' => $kept, 'droppedNames' => $droppedNames];
    }

    private function isKept(Skill $skill, BoostConfig $config): bool
    {
        // Fail closed: a malformed `metadata.boost-tags` ships to no project.
        if (! $skill->tagsValid) {
            return false;
        }

        if ($this->isExcluded($skill, $config)) {
            return false;
        }

        // Tag subset: every tag the skill declares must be a consumer tag.
        return array_diff($skill->tags, $config->tags) === [];
    }

    private function isExcluded(Skill $skill, BoostConfig $config): bool
    {
        $key = $skill->excludeKey();

        return $key !== null && $config->excludesSkill($key);
    }
}
