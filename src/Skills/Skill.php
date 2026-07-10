<?php declare(strict_types=1);

namespace SanderMuller\BoostCore\Skills;

/**
 * A resolved skill — name, frontmatter, body, and provenance.
 *
 * @api Stable as of 1.0 — the value type a wrapper package constructs to inject
 * skills via `BoostSync::sync(injectedVendorSkills: [...])`. The original eight
 * constructor properties are frozen; later additions (`$assets`, 1.3) append
 * with a default, per the same non-breaking rule.
 */
final readonly class Skill
{
    /**
     * @param  array<string, mixed>  $frontmatter  Loose v1 schema — pass-through. Ecosystem-driven evolution.
     * @param  string|null  $sourceVendor  Composer vendor/package name that published this skill. `null` = host-authored from .ai/skills/.
     * @param  list<string>  $tags  Normalized tags from the `metadata.boost-tags` frontmatter field. Empty = untagged = ships everywhere.
     * @param  bool  $tagsValid  False when `metadata.boost-tags` is present but malformed — the skill then fails closed (ships nowhere).
     * @param  list<SkillAsset>  $assets  Companion files from a nested skill dir (`scripts/`, `references/`, …), emitted beside SKILL.md in every agent target. Empty for flat-layout skills.
     * @param  list<string>  $requires  Bare skill names from the `metadata.boost-requires` frontmatter field — hard deps that must ship whenever this skill ships. Empty = no dependencies.
     * @param  bool  $requiresValid  False when `metadata.boost-requires` is present but malformed — unlike `tagsValid` this does NOT stop the skill from shipping (requires gate completeness, not scoping); it surfaces as a sync warning / validate error.
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
        public array $assets = [],
        public array $requires = [],
        public bool $requiresValid = true,
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
            $this->assets,
            $this->requires,
            $this->requiresValid,
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
