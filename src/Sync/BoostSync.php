<?php declare(strict_types=1);

namespace SanderMuller\BoostCore\Sync;

use SanderMuller\BoostCore\Contracts\SkillRenderer;
use SanderMuller\BoostCore\Skills\Guideline;
use SanderMuller\BoostCore\Skills\Skill;

/**
 * The supported entry point for a WRAPPER package to drive a sync with injected
 * vendor skills/guidelines and extra skill renderers — e.g. the
 * `project-boost-laravel` bridge registering a Blade renderer and injecting
 * `laravel/boost`'s skills + guidelines.
 *
 * A thin façade over the engine: it exists so this wrapper-integration surface
 * can be frozen at 1.0 while the engine behind it stays free to evolve. A plain
 * consumer never needs this — `vendor/bin/boost sync` (the CLI) and the
 * `BoostAutoSync` composer hooks cover the normal path. Reach for `BoostSync`
 * only when you are building a wrapper that injects content into the sync.
 *
 * @api Stable as of 1.0. Build with {@see make()}, then {@see sync()}; the
 * result is an `@api` {@see SyncResult}. New `sync()` parameters, if ever added,
 * append with a default (non-breaking).
 */
final readonly class BoostSync
{
    private function __construct(private SyncEngine $engine) {}

    public static function make(?InstalledPackages $installedPackages = null, ?string $configFile = null): self
    {
        return new self(SyncEngine::default($installedPackages, $configFile));
    }

    /**
     * Drive a sync. `injectedVendorSkills` / `injectedVendorGuidelines` are keyed
     * by composer vendor/package name (e.g. `['laravel/boost' => [$skill, …]]`);
     * `extraSkillRenderers` are registered for this run only (e.g. a Blade
     * renderer for `.blade.php` skills).
     *
     * @param  array<string, list<Skill>>  $injectedVendorSkills
     * @param  list<SkillRenderer>  $extraSkillRenderers
     * @param  array<string, list<Guideline>>  $injectedVendorGuidelines
     */
    public function sync(
        string $projectRoot,
        bool $checkOnly = false,
        array $injectedVendorSkills = [],
        array $extraSkillRenderers = [],
        array $injectedVendorGuidelines = [],
    ): SyncResult {
        return $this->engine->sync(
            projectRoot: $projectRoot,
            checkOnly: $checkOnly,
            injectedVendorSkills: $injectedVendorSkills,
            extraSkillRenderers: $extraSkillRenderers,
            injectedVendorGuidelines: $injectedVendorGuidelines,
        );
    }
}
