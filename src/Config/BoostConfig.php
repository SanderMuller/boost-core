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
 *         ->withAllowedVendors(['doctrine/orm', 'symfony/symfony']);
 *
 * Source paths default to the project root's `.ai/skills` and `.ai/guidelines`
 * (resolved against `$projectRoot`, NOT this file's directory), so the config is
 * location-independent — it works the same at the repo root or at
 * `.config/boost.php`. Override only with an ABSOLUTE path
 * (`->withSkillsPath('/abs/path')`); avoid `__DIR__`-relative paths, which break
 * if `boost.php` is moved.
 *
 * `configure()` returns a BoostConfigBuilder; the builder accumulates state.
 * BoostConfigLoader calls `->build($projectRoot)` to produce this immutable
 * value object.
 *
 * @api
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
     *
     * @internal Build via {@see BoostConfig::configure()}. The positional constructor is
     *           engine-internal and NOT covered by the 1.0 semver promise — fields may be
     *           added (always optional-with-default) without a major bump.
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

    /**
     * Load + build a project's `boost.php` into a resolved config.
     *
     * The `@api` entry point for a wrapper that needs to READ a project's declared
     * config — e.g. its `withAgents([...])` set via `$config->agents` — without
     * depending on the engine-internal loader. Resolves a root `boost.php` vs
     * `.config/boost.php` (or an explicit `$configFile`). Throws on a
     * missing / invalid / ambiguous config — the fail-loud behavior is
     * contractual (see PUBLIC_API.md), and the thrown classes are themselves
     * `@api` so a wrapper can catch them by name.
     *
     * @throws BoostConfigNotFoundException no `boost.php` at the expected path
     * @throws InvalidBoostConfigException  a `boost.php` that doesn't return a {@see self}
     * @throws AmbiguousBoostConfigException both a root and a `.config/boost.php` exist
     *
     * @api Stable as of 1.0.
     */
    public static function load(string $projectRoot, ?string $configFile = null): self
    {
        return (new BoostConfigLoader())->load($projectRoot, $configFile);
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
