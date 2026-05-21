<?php declare(strict_types=1);

namespace SanderMuller\BoostCore\Skills;

final readonly class Guideline
{
    /**
     * @param  array<string, mixed>  $frontmatter  Loose v1 schema — pass-through.
     * @param  string|null  $sourceVendor  Composer vendor/package name. `null` = host-authored.
     * @param  list<string>  $tags  Normalized tags from the `metadata.boost-tags` frontmatter field. Empty = untagged = ships everywhere.
     * @param  bool  $tagsValid  False when `metadata.boost-tags` is present but malformed — the guideline then fails closed (ships nowhere).
     */
    public function __construct(
        public string $name,
        public ?string $description,
        public array $frontmatter,
        public string $body,
        public string $sourcePath,
        public ?string $sourceVendor,
        public array $tags = [],
        public bool $tagsValid = true,
    ) {}

    public function isHostAuthored(): bool
    {
        return $this->sourceVendor === null;
    }
}
