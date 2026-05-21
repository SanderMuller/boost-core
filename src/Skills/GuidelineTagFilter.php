<?php declare(strict_types=1);

namespace SanderMuller\BoostCore\Skills;

use SanderMuller\BoostCore\Config\BoostConfig;

/**
 * Drops vendor guidelines a project does not want, before collision
 * resolution — the guideline counterpart of `SkillTagFilter`.
 *
 * A guideline is dropped when its `metadata.boost-tags` are not a subset of
 * the project's declared tags (`boost.php` `withTags()`), or when those tags
 * are invalid (`tagsValid` false — a malformed `metadata.boost-tags`, which
 * fails closed and ships nowhere). Untagged guidelines carry the empty set
 * and pass the subset check trivially, so the filter is inert until
 * guidelines and projects opt in.
 *
 * Two deliberate differences from `SkillTagFilter`:
 *  - **No exclude step** — `withExcludedSkills()` is a skill-only deny-list;
 *    there is no guideline equivalent.
 *  - **No dropped-name tracking or pruning** — guidelines compose into the
 *    shared agent instruction files (`CLAUDE.md`, `AGENTS.md`, ...),
 *    regenerated whole on every sync, so a dropped guideline is simply
 *    absent from the rewritten file. The one edge: when filtering empties
 *    an agent's guideline set *entirely*, `AgentTarget::plan()` emits no
 *    write and a previously-generated guideline file lingers stale. Safely
 *    auto-deleting it needs an ownership signal boost-core lacks (a
 *    generated-file marker, or a sync manifest) — a hand-authored
 *    `CLAUDE.md` must never be deleted. Known limitation: the file is
 *    gitignored and `rm`-able; see `internal/TODO.md`.
 */
final class GuidelineTagFilter
{
    /**
     * @param  iterable<Guideline>  $guidelines
     * @return list<Guideline>
     */
    public function filter(iterable $guidelines, BoostConfig $config): array
    {
        $kept = [];
        foreach ($guidelines as $guideline) {
            if ($this->isKept($guideline, $config)) {
                $kept[] = $guideline;
            }
        }

        return $kept;
    }

    private function isKept(Guideline $guideline, BoostConfig $config): bool
    {
        // Fail closed: a malformed `metadata.boost-tags` ships to no project.
        if (! $guideline->tagsValid) {
            return false;
        }

        // Tag subset: every tag the guideline declares must be a project tag.
        return array_diff($guideline->tags, $config->tags) === [];
    }
}
