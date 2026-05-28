<?php declare(strict_types=1);

namespace SanderMuller\BoostCore\Config;

use SanderMuller\BoostCore\Contracts\SkillRenderer;
use SanderMuller\BoostCore\Enums\Agent;
use SanderMuller\BoostCore\Skills\Remote\RemoteSkillSource;

/**
 * Resolved, immutable boost configuration.
 *
 * Users author this indirectly via `boost.php`:
 *
 *     return BoostConfig::configure()
 *         ->withAgents([Agent::CLAUDE_CODE, Agent::CURSOR])
 *         ->withAllowedVendors(['doctrine/orm', 'symfony/symfony'])
 *         ->withSkillsPath(__DIR__ . '/.ai/skills')
 *         ->withGuidelinesPath(__DIR__ . '/.ai/guidelines');
 *
 * `configure()` returns a BoostConfigBuilder; the builder accumulates state.
 * BoostConfigLoader calls `->build($projectRoot)` to produce this immutable
 * value object.
 */
final readonly class BoostConfig
{
    /**
     * @param  list<Agent>  $agents
     * @param  list<string>  $allowedVendors  Composer vendor/package names
     * @param  list<string>  $disabledEmitters  Fully-qualified class names
     * @param  list<string>  $tags  Project tags — a vendor skill ships only when its `metadata.boost-tags` ⊆ these
     * @param  list<string>  $excludedSkills  `vendor/package:skill-name` entries excluded regardless of tags
     * @param  list<string>  $excludedGuidelines  `vendor/package:guideline-name` entries excluded regardless of tags
     * @param  list<RemoteSkillSource>  $remoteSkills  Non-Composer skill sources (GitHub `.skill` bundles or repo subdirs)
     * @param  list<SkillRenderer>  $skillRenderers  Registered renderers; always carries the implicit PassthroughRenderer last unless a user-registered renderer claims `md`
     * @param  array<string, mixed>  $conventions  Operator-declared Project Conventions slot values (NEW in 0.9.0). Renders into CLAUDE.md's marker-bounded region at sync time. Defaults to `[]` (no conventions declared) — back-compat with consumers who construct BoostConfig positionally.
     */
    public function __construct(
        public array $agents,
        public array $allowedVendors,
        public string $skillsPath,
        public string $guidelinesPath,
        public string $commandsPath,
        public array $disabledEmitters,
        public bool $manageGitignore = true,
        public array $tags = [],
        public array $excludedSkills = [],
        public array $excludedGuidelines = [],
        public array $remoteSkills = [],
        public array $skillRenderers = [],
        public array $conventions = [],
    ) {}

    public static function configure(): BoostConfigBuilder
    {
        return new BoostConfigBuilder();
    }

    public function hasAgent(Agent $agent): bool
    {
        return in_array($agent, $this->agents, true);
    }

    public function hasTag(string $tag): bool
    {
        return in_array($tag, $this->tags, true);
    }

    public function isVendorAllowed(string $packageName): bool
    {
        return in_array($packageName, $this->allowedVendors, true);
    }

    public function isEmitterDisabled(string $fqcn): bool
    {
        return in_array($fqcn, $this->disabledEmitters, true);
    }

    /**
     * Whether a skill is on the `withExcludedSkills()` deny-list. The key is
     * a skill's `vendor/package:skill-name` identifier — `Skill::excludeKey()`
     * builds it (kept as a plain reference, not an `@see`, so this Config
     * class needs no `use` of the Skills namespace).
     */
    public function excludesSkill(string $excludeKey): bool
    {
        return in_array($excludeKey, $this->excludedSkills, true);
    }

    /**
     * Whether a guideline is on the `withExcludedGuidelines()` deny-list. The
     * key is a guideline's `vendor/package:guideline-name` identifier —
     * `Guideline::excludeKey()` builds it. The guideline counterpart of
     * `excludesSkill()`: the only lever for a guideline shipped without
     * `metadata.boost-tags`, which tag-filtering cannot reach.
     */
    public function excludesGuideline(string $excludeKey): bool
    {
        return in_array($excludeKey, $this->excludedGuidelines, true);
    }
}
