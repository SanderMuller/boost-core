<?php declare(strict_types=1);

namespace SanderMuller\BoostCore\Skills;

use SanderMuller\BoostCore\Config\BoostConfig;

/**
 * Drops vendor guidelines a project does not want, before collision
 * resolution — the guideline counterpart of `SkillTagFilter`.
 *
 * A guideline is dropped for one of two reasons:
 *  1. **Tag mismatch** — the guideline's `metadata.boost-tags` are not a
 *     subset of the project's declared tags (`boost.php` `withTags()`); or
 *     the tags are invalid (`tagsValid` false — a malformed
 *     `metadata.boost-tags`, which fails closed and ships nowhere).
 *  2. **Explicit exclude** — the guideline matches a
 *     `vendor/package:guideline-name` entry in the consumer's
 *     `withExcludedGuidelines()` deny-list. This is the only lever for a
 *     guideline that ships *without* `metadata.boost-tags`: untagged
 *     guidelines carry the empty set and pass the subset check trivially,
 *     so tag-filtering cannot reach them — e.g. the frontmatter-free
 *     guidelines a `laravel/boost`-compatible package publishes.
 *
 * Tag-filtering is inert until guidelines and projects opt into tags; the
 * deny-list is the tag-independent escape hatch.
 *
 * Unlike `SkillTagFilter`, this filter tracks no dropped names and triggers
 * no pruning — guidelines compose into the shared agent instruction files
 * (`CLAUDE.md`, `AGENTS.md`, ...), regenerated whole on every sync, so a
 * dropped guideline is simply absent from the rewritten file. The one edge:
 * when filtering empties an agent's guideline set *entirely*,
 * `AgentTarget::plan()` emits no write and a previously-generated guideline
 * file lingers stale. Safely auto-deleting it needs an ownership signal
 * boost-core lacks (a generated-file marker, or a sync manifest) — a
 * hand-authored `CLAUDE.md` must never be deleted. Known limitation: the
 * file is gitignored and `rm`-able; see `internal/TODO.md`.
 *
 * @internal
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

        if ($this->isExcluded($guideline, $config)) {
            return false;
        }

        // Tag subset: every tag the guideline declares must be a project tag.
        return array_diff($guideline->tags, $config->tags) === [];
    }

    private function isExcluded(Guideline $guideline, BoostConfig $config): bool
    {
        $key = $guideline->excludeKey();

        return $key !== null && $config->excludesGuideline($key);
    }
}
