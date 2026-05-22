<?php declare(strict_types=1);

namespace SanderMuller\BoostCore\Config;

use SanderMuller\BoostCore\Enums\Agent;
use SanderMuller\BoostCore\Enums\Tag;

/**
 * Fluent builder used in `boost.php`. Mutable — call chain accumulates state.
 *
 * BoostConfigLoader receives the builder from `require boost.php` and calls
 * `build($projectRoot)` to resolve defaults and produce an immutable BoostConfig.
 */
final class BoostConfigBuilder
{
    /** @var list<Agent> */
    private array $agents = [];

    /** @var list<string> */
    private array $allowedVendors = [];

    private ?string $skillsPath = null;

    private ?string $guidelinesPath = null;

    /** @var list<string> */
    private array $disabledEmitters = [];

    private bool $manageGitignore = true;

    /** @var list<string> */
    private array $tags = [];

    /** @var list<string> */
    private array $excludedSkills = [];

    /** @var list<string> */
    private array $excludedGuidelines = [];

    /**
     * @param  list<Agent>  $agents
     */
    public function withAgents(array $agents): self
    {
        $this->agents = array_values($agents);

        return $this;
    }

    /**
     * @param  list<string>  $vendors  Composer vendor/package names (e.g. "doctrine/orm")
     */
    public function withAllowedVendors(array $vendors): self
    {
        $this->allowedVendors = array_values($vendors);

        return $this;
    }

    public function withSkillsPath(string $path): self
    {
        $this->skillsPath = $path;

        return $this;
    }

    public function withGuidelinesPath(string $path): self
    {
        $this->guidelinesPath = $path;

        return $this;
    }

    /**
     * @param  list<string>  $fqcns  Fully-qualified emitter class names to skip during sync
     */
    public function withDisabledEmitters(array $fqcns): self
    {
        $this->disabledEmitters = array_values($fqcns);

        return $this;
    }

    /**
     * Control whether boost maintains a managed block in `.gitignore`. On by
     * default — generated agent files are ignored, edited only via `.ai/`.
     */
    public function withGitignoreManagement(bool $manage = true): self
    {
        $this->manageGitignore = $manage;

        return $this;
    }

    /**
     * Declare the project's skill tags. A vendor skill ships only when every
     * tag in its `metadata.boost-tags` is declared here. Accepts {@see Tag}
     * enum cases (autocomplete) and/or raw strings (the vocabulary is open).
     */
    public function withTags(Tag|string ...$tags): self
    {
        $normalized = array_map(
            static fn (Tag|string $tag): string => Tag::normalize($tag instanceof Tag ? $tag->value : $tag),
            $tags,
        );

        $this->tags = array_values(array_unique(array_filter(
            $normalized,
            static fn (string $tag): bool => $tag !== '',
        )));

        return $this;
    }

    /**
     * Exclude specific vendor skills regardless of tags. Each entry is a
     * `vendor/package:skill-name` string.
     *
     * @param  list<string>  $skills
     */
    public function withExcludedSkills(array $skills): self
    {
        $this->excludedSkills = array_values($skills);

        return $this;
    }

    /**
     * Exclude specific vendor guidelines regardless of tags. Each entry is a
     * `vendor/package:guideline-name` string. The guideline counterpart of
     * {@see withExcludedSkills()} — and the only lever for a vendor guideline
     * that ships without `metadata.boost-tags` (e.g. a frontmatter-free
     * guideline a `laravel/boost`-compatible package publishes), since
     * untagged guidelines pass tag-filtering trivially.
     *
     * @param  list<string>  $guidelines
     */
    public function withExcludedGuidelines(array $guidelines): self
    {
        $this->excludedGuidelines = array_values($guidelines);

        return $this;
    }

    public function build(string $projectRoot): BoostConfig
    {
        $projectRoot = rtrim($projectRoot, '/');

        return new BoostConfig(
            agents: $this->agents,
            allowedVendors: $this->allowedVendors,
            skillsPath: $this->skillsPath ?? $projectRoot . '/.ai/skills',
            guidelinesPath: $this->guidelinesPath ?? $projectRoot . '/.ai/guidelines',
            disabledEmitters: $this->disabledEmitters,
            manageGitignore: $this->manageGitignore,
            tags: $this->tags,
            excludedSkills: $this->excludedSkills,
            excludedGuidelines: $this->excludedGuidelines,
        );
    }
}
