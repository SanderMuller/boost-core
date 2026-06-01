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
     * @param  list<string>  $tags  Normalized tags from the `metadata.boost-tags` frontmatter field. Empty = untagged.
     * @param  bool  $tagsValid  False when `metadata.boost-tags` is present but malformed.
     * @param  list<string>  $argumentDeclarations  Names declared in the `arguments:` frontmatter list — drives per-agent named-arg emit (e.g. Junie's all-required mode, Claude's `arguments:` frontmatter mirror). Empty = no named args declared.
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
        public array $argumentDeclarations = [],
    ) {}

    public function isHostAuthored(): bool
    {
        return $this->sourceVendor === null;
    }
}
