<?php declare(strict_types=1);

namespace SanderMuller\BoostCore\Skills;

use SanderMuller\BoostCore\Config\BoostConfig;

/**
 * Pure tag-diagnostics used by `boost:doctor`'s tag report — the
 * classification and hygiene heuristics, kept IO-free and testable apart
 * from the command's rendering.
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
