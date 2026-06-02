<?php declare(strict_types=1);

namespace SanderMuller\BoostCore\Skills;

/**
 * @internal
 */
final readonly class Skill
{
    /**
     * @param  array<string, mixed>  $frontmatter  Loose v1 schema — pass-through. Ecosystem-driven evolution.
     * @param  string|null  $sourceVendor  Composer vendor/package name that published this skill. `null` = host-authored from .ai/skills/.
     * @param  list<string>  $tags  Normalized tags from the `metadata.boost-tags` frontmatter field. Empty = untagged = ships everywhere.
     * @param  bool  $tagsValid  False when `metadata.boost-tags` is present but malformed — the skill then fails closed (ships nowhere).
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

    /**
     * Return a copy with a replaced body — used by the conventions
     * inliner to splice resolved slot values into the skill before fan-out.
     */
    public function withBody(string $body): self
    {
        return new self(
            $this->name,
            $this->description,
            $this->frontmatter,
            $body,
            $this->sourcePath,
            $this->sourceVendor,
            $this->tags,
            $this->tagsValid,
        );
    }

    /**
     * The `vendor/package:skill-name` key by which a consumer's
     * `withExcludedSkills()` deny-list addresses this skill — or null for a
     * host-authored skill, which the deny-list has no form to name.
     */
    public function excludeKey(): ?string
    {
        return $this->sourceVendor === null
            ? null
            : $this->sourceVendor . ':' . $this->name;
    }
}
