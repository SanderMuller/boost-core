<?php declare(strict_types=1);

namespace SanderMuller\BoostCore\Skills;

/**
 * A resolved guideline — name, frontmatter, body, and provenance.
 *
 * @api Stable as of 1.0 — the value type a wrapper package constructs to inject
 * guidelines via `BoostSync::sync(injectedVendorGuidelines: [...])`. The eight
 * constructor properties are frozen; new ones, if ever added, append with a
 * default (non-breaking).
 */
final readonly class Guideline
{
    /**
     * @param  array<string, mixed>  $frontmatter  Loose v1 schema — pass-through.
     * @param  string|null  $sourceVendor  Composer vendor/package name. `null` = host-authored.
     * @param  list<string>  $tags  Normalized tags from the `metadata.boost-tags` frontmatter field. Empty = untagged = ships everywhere.
     * @param  bool  $tagsValid  False when `metadata.boost-tags` is present but malformed — the guideline then fails closed (ships nowhere).
     * @param  bool  $userScopeEligible  True when the AUTHOR marked this guideline machine-wide safe — `metadata.boost-user-scope: true`, or its filename listed in the `.boost-user-scope.yaml` sidecar. Only an eligible guideline is published at user scope; project scope ignores the flag.
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
        public bool $userScopeEligible = false,
    ) {}

    public function isHostAuthored(): bool
    {
        return $this->sourceVendor === null;
    }

    /**
     * The `vendor/package:guideline-name` key by which a consumer's
     * `withExcludedGuidelines()` deny-list addresses this guideline — or null
     * for a host-authored guideline, which the deny-list has no form to name.
     */
    public function excludeKey(): ?string
    {
        return $this->sourceVendor === null
            ? null
            : $this->sourceVendor . ':' . $this->name;
    }
}
