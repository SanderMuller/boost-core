<?php declare(strict_types=1);

namespace SanderMuller\BoostCore\Skills;

use SanderMuller\BoostCore\Config\BoostConfig;

/**
 * Pure tag-diagnostics used by `boost:doctor`'s tag report — the
 * classification and hygiene heuristics, kept IO-free and testable apart
 * from the command's rendering.
 *
 * @internal
 */
final class SkillTagDiagnostics
{
    /**
     * The tag-layer verdict for one vendor skill. Reports ONLY what the tag
     * filter decides — never claims the skill ships (a `tag-eligible` skill
     * can still be host-shadowed or lose a vendor collision; that is the
     * doctor Drift section's concern, not this one).
     */
    public function status(Skill $skill, BoostConfig $config): string
    {
        if (! $skill->tagsValid) {
            return 'invalid tags (ships nowhere — fix metadata.boost-tags)';
        }

        $excludeKey = $skill->excludeKey();
        if ($excludeKey !== null && $config->excludesSkill($excludeKey)) {
            return 'excluded (withExcludedSkills)';
        }

        $missing = array_values(array_diff($skill->tags, $config->tags));
        if ($missing !== []) {
            return 'filtered (declare: ' . implode(', ', $missing) . ')';
        }

        return 'tag-eligible';
    }

    /**
     * The tag-layer verdict for one vendor guideline — `status()`'s
     * guideline counterpart, including the `excluded` verdict for the
     * `withExcludedGuidelines()` deny-list.
     */
    public function guidelineStatus(Guideline $guideline, BoostConfig $config): string
    {
        if (! $guideline->tagsValid) {
            return 'invalid tags (ships nowhere — fix metadata.boost-tags)';
        }

        $excludeKey = $guideline->excludeKey();
        if ($excludeKey !== null && $config->excludesGuideline($excludeKey)) {
            return 'excluded (withExcludedGuidelines)';
        }

        $missing = array_values(array_diff($guideline->tags, $config->tags));
        if ($missing !== []) {
            return 'filtered (declare: ' . implode(', ', $missing) . ')';
        }

        return 'tag-eligible';
    }

    /**
     * Skills currently filtered out purely because the consumer has not
     * declared one or more of their tags, grouped by the exact tag set that
     * `withTags()` would need to add to make each group ship.
     *
     * Invalid-tag and excluded skills are omitted — neither becomes shippable
     * by declaring a tag (a malformed `metadata.boost-tags` is fixed at the
     * skill; an exclude is removed from `withExcludedSkills()`).
     *
     * @param  iterable<Skill>  $skills
     * @return list<array{tags: list<string>, skills: list<string>}>
     *         One entry per distinct missing-tag set, sorted; each `skills`
     *         holds the sorted `vendor/package:name` keys the set unlocks.
     */
    public function filteredSkillsByMissingTags(iterable $skills, BoostConfig $config): array
    {
        /** @var array<string, list<string>> $groups */
        $groups = [];

        foreach ($skills as $skill) {
            if (! $skill->tagsValid) {
                continue;
            }

            $excludeKey = $skill->excludeKey();
            if ($excludeKey !== null && $config->excludesSkill($excludeKey)) {
                continue;
            }

            $missing = array_values(array_diff($skill->tags, $config->tags));
            if ($missing === []) {
                continue;
            }

            sort($missing);
            $groups[implode(' ', $missing)][] = $excludeKey ?? $skill->name;
        }

        ksort($groups);

        $result = [];
        foreach ($groups as $key => $skillKeys) {
            sort($skillKeys);
            $result[] = [
                'tags' => explode(' ', $key),
                'skills' => array_values(array_unique($skillKeys)),
            ];
        }

        return $result;
    }

    /**
     * Tags a consumer declared (`withTags()`) that no installed skill uses —
     * a likely typo or a tag for a not-yet-installed package.
     *
     * @param  list<string>  $skillTagUnion  Every tag declared by an installed allowlisted skill.
     * @return list<string>
     */
    public function declaredButUnusedTags(BoostConfig $config, array $skillTagUnion): array
    {
        return array_values(array_diff($config->tags, $skillTagUnion));
    }

    /**
     * Flag tag pairs where one string contains the other (`jira` vs
     * `jira-cloud`) — a cheap heuristic for accidental tag drift.
     *
     * @param  list<string>  $tags
     * @return list<array{0: string, 1: string}>
     */
    public function nearDuplicates(array $tags): array
    {
        $tags = array_values(array_unique($tags));

        /** @var list<array{0: string, 1: string}> $pairs */
        $pairs = [];
        $count = count($tags);
        for ($i = 0; $i < $count; ++$i) {
            for ($j = $i + 1; $j < $count; ++$j) {
                if (str_contains($tags[$i], $tags[$j]) || str_contains($tags[$j], $tags[$i])) {
                    $pairs[] = [$tags[$i], $tags[$j]];
                }
            }
        }

        return $pairs;
    }
}
