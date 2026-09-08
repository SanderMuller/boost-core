<?php declare(strict_types=1);

namespace SanderMuller\BoostCore\Skills;

use SanderMuller\BoostCore\Config\BoostConfig;

/**
 * Drops vendor subagents a project does not want, before collision resolution.
 * The {@see SkillTagFilter} rules, applied to subagents:
 *
 *  1. **Malformed tags** — `tagsValid` false fails closed and ships nowhere.
 *  2. **Explicit exclude** — matches a `vendor/package:name` entry in the
 *     consumer's `withExcludedSkills()` deny-list. Subagents share that
 *     deny-list rather than getting a second config surface; see
 *     {@see Subagent::excludeKey()}.
 *  3. **Tag mismatch** — the subagent's tags are not a subset of the
 *     consumer's `withTags()`.
 *
 * The drop groups mirror the skill filter's because the dependency rescue in
 * `boost-requires: subagent:<name>` consumes them the same way: tag-mismatch
 * drops are rescue-eligible, excluded drops exist to classify an unsatisfiable
 * demand as `excluded` rather than `missing`, and malformed drops are in
 * neither — rescue must never resurrect broken content.
 *
 * @internal
 */
final class SubagentTagFilter
{
    private const KEEP = 'keep';

    private const DROP_MALFORMED = 'malformed';

    private const DROP_EXCLUDED = 'excluded';

    private const DROP_TAG_MISMATCH = 'tag-mismatch';

    /**
     * @param  iterable<Subagent>  $subagents
     * @return array{kept: list<Subagent>, droppedNames: list<string>, droppedByTag: int, tagMismatchDrops: list<Subagent>, excludedDrops: list<Subagent>}
     */
    public function filter(iterable $subagents, BoostConfig $config): array
    {
        /** @var list<Subagent> $kept */
        $kept = [];
        /** @var list<string> $droppedNames */
        $droppedNames = [];
        $droppedByTag = 0;
        /** @var list<Subagent> $tagMismatchDrops */
        $tagMismatchDrops = [];
        /** @var list<Subagent> $excludedDrops */
        $excludedDrops = [];

        foreach ($subagents as $subagent) {
            $verdict = $this->classify($subagent, $config);
            if ($verdict === self::KEEP) {
                $kept[] = $subagent;

                continue;
            }

            $droppedNames[] = $subagent->name;
            if ($verdict === self::DROP_TAG_MISMATCH) {
                ++$droppedByTag;
                $tagMismatchDrops[] = $subagent;
            } elseif ($verdict === self::DROP_EXCLUDED) {
                $excludedDrops[] = $subagent;
            }
        }

        return [
            'kept' => $kept,
            'droppedNames' => $droppedNames,
            'droppedByTag' => $droppedByTag,
            'tagMismatchDrops' => $tagMismatchDrops,
            'excludedDrops' => $excludedDrops,
        ];
    }

    private function classify(Subagent $subagent, BoostConfig $config): string
    {
        if (! $subagent->tagsValid) {
            return self::DROP_MALFORMED;
        }

        $key = $subagent->excludeKey();
        if ($key !== null && $config->excludesSkill($key)) {
            return self::DROP_EXCLUDED;
        }

        if (array_diff($subagent->tags, $config->tags) !== []) {
            return self::DROP_TAG_MISMATCH;
        }

        return self::KEEP;
    }
}
