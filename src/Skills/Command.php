<?php declare(strict_types=1);

namespace SanderMuller\BoostCore\Skills;

/**
 * A user-invoked prompt template — boost-core's third synced content type,
 * beside {@see Skill} (model-invoked) and {@see Guideline} (always-on).
 * Authored in `.ai/commands/<name>.md`, fanned out to each agent's command
 * directory.
 *
 * @see Guideline — the structural sibling this mirrors.
 */
final readonly class Command
{
    /**
     * @param  array<string, mixed>  $frontmatter  Loose schema — pass-through.
     * @param  string|null  $sourceVendor  Composer vendor/package name. `null` = host-authored.
     * @param  list<string>  $tags  Normalized tags from the `metadata.boost-tags` frontmatter field. Empty = untagged. Inert until vendor commands ship (spec Phase 4).
     * @param  bool  $tagsValid  False when `metadata.boost-tags` is present but malformed.
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
