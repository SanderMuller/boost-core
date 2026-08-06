<?php declare(strict_types=1);

namespace SanderMuller\BoostCore\Skills\Remote;

/**
 * One skill a remote repo offers, as seen by `boost remote` BEFORE anything is
 * declared in `boost.php`.
 *
 * `$name` is the frontmatter `name` — the identity the engine verifies at
 * ingest time, not the directory or asset filename. When that name can't be
 * used, `$problem` explains why and the picker renders the row unselectable
 * instead of dropping it silently: a skill vanishing from the list with no
 * reason is indistinguishable from a repo that never published it.
 *
 * @internal
 */
final readonly class DiscoveredSkill
{
    /**
     * @param  string  $name  frontmatter `name`, or the asset/directory fallback when unreadable
     * @param  list<string>  $tags  normalized `metadata.boost-tags`
     * @param  list<string>  $requires  `metadata.boost-requires` names
     * @param  string|null  $path  repo-relative directory (path mode), `.` for the repo root
     * @param  string|null  $asset  release asset filename (bundle mode)
     * @param  string|null  $problem  why this row can't be picked; null = selectable
     */
    public function __construct(
        public string $name,
        public ?string $description,
        public array $tags,
        public array $requires,
        public ?string $path = null,
        public ?string $asset = null,
        public ?string $problem = null,
    ) {}

    public function isSelectable(): bool
    {
        return $this->problem === null;
    }

    /**
     * Copy with `$problem` set. Used when a defect only becomes visible after
     * the whole list is known (a duplicate name, for instance).
     */
    public function withProblem(string $problem): self
    {
        return new self(
            name: $this->name,
            description: $this->description,
            tags: $this->tags,
            requires: $this->requires,
            path: $this->path,
            asset: $this->asset,
            problem: $problem,
        );
    }
}
