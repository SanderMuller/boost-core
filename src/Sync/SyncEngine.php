<?php declare(strict_types=1);

namespace SanderMuller\BoostCore\Sync;

use FilesystemIterator;
use LogicException;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use SanderMuller\BoostCore\Agents\AgentTarget;
use SanderMuller\BoostCore\Agents\AmpTarget;
use SanderMuller\BoostCore\Agents\ClaudeCodeTarget;
use SanderMuller\BoostCore\Agents\CodexTarget;
use SanderMuller\BoostCore\Agents\CopilotTarget;
use SanderMuller\BoostCore\Agents\CursorTarget;
use SanderMuller\BoostCore\Agents\GeminiTarget;
use SanderMuller\BoostCore\Agents\JunieTarget;
use SanderMuller\BoostCore\Agents\KiroTarget;
use SanderMuller\BoostCore\Agents\OpenCodeTarget;
use SanderMuller\BoostCore\Config\BoostConfig;
use SanderMuller\BoostCore\Config\BoostConfigLoader;
use SanderMuller\BoostCore\Contracts\SkillRenderer;
use SanderMuller\BoostCore\Conventions\ConventionsPass;
use SanderMuller\BoostCore\Conventions\Diagnostic;
use SanderMuller\BoostCore\Discovery\DiscoveredEmitter;
use SanderMuller\BoostCore\Discovery\DiscoveredVendor;
use SanderMuller\BoostCore\Discovery\EmitterDiscovery;
use SanderMuller\BoostCore\Discovery\VendorScanner;
use SanderMuller\BoostCore\Enums\Agent;
use SanderMuller\BoostCore\Env;
use SanderMuller\BoostCore\Skills\CollidingSkillsException;
use SanderMuller\BoostCore\Skills\Command;
use SanderMuller\BoostCore\Skills\CommandLoader;
use SanderMuller\BoostCore\Skills\FrontmatterParser;
use SanderMuller\BoostCore\Skills\Guideline;
use SanderMuller\BoostCore\Skills\GuidelineLoader;
use SanderMuller\BoostCore\Skills\GuidelineResolver;
use SanderMuller\BoostCore\Skills\GuidelineTagFilter;
use SanderMuller\BoostCore\Skills\Remote\CurlHttpTransport;
use SanderMuller\BoostCore\Skills\Remote\GitHubFetcher;
use SanderMuller\BoostCore\Skills\Remote\RemoteOrphanPruner;
use SanderMuller\BoostCore\Skills\Remote\RemoteSkillCache;
use SanderMuller\BoostCore\Skills\Remote\RemoteSkillIngester;
use SanderMuller\BoostCore\Skills\Remote\RemoteSkillSource;
use SanderMuller\BoostCore\Skills\Remote\RemoteSkillSyncCoordinator;
use SanderMuller\BoostCore\Skills\Rendering\SkillRendererDispatcher;
use SanderMuller\BoostCore\Skills\Skill;
use SanderMuller\BoostCore\Skills\SkillLoader;
use SanderMuller\BoostCore\Skills\SkillResolver;
use SanderMuller\BoostCore\Skills\SkillTagFilter;
use SplFileInfo;
use Throwable;

/**
 * Orchestrates a full boost:sync run.
 *
 * Pipeline:
 * 1. Load boost.php config.
 * 2. Snapshot installed Composer packages.
 * 3. Walk vendor publishers, filter by allowlist.
 * 4. Discover FileEmitter classes from allowlisted vendors.
 * 5. Load host + vendor skills/guidelines.
 * 6. Resolve collisions (host wins, vendor-vs-vendor strict unless --force).
 * 7. Run FileEmitters (before fan-out — one-way dependency).
 * 8. Agent fan-out: skills + guidelines per active agent.
 */
final readonly class SyncEngine
{
    /**
     * Retired-paths registry. Paths boost-core has emitted to in past
     * versions but no longer maintains. Adding a path requires conscious
     * decision; the registry is the audit surface for "what cleanup
     * contract does sync enforce."
     *
     * Public so `boost doctor --check-stale-paths` can surface
     * registry-tracked paths read-only without duplicating the list. Sync
     * owns deletion, doctor owns read-only reporting.
     *
     * @var list<string>
     */
    public const RETIRED_COPILOT_PATHS = [
        '.github/copilot-instructions.md', // Copilot reads root AGENTS.md
        '.github/skills',                  // Copilot reads .agents/skills via shared pool
    ];

    private InstalledPackages $installedPackages;

    private SkillLoader $skillLoader;

    private GuidelineLoader $guidelineLoader;

    private CommandLoader $commandLoader;

    private VendorScanner $vendorScanner;

    private EmitterDiscovery $emitterDiscovery;

    private RemoteSkillIngester $remoteSkillIngester;

    private RemoteOrphanPruner $remoteOrphanPruner;

    private RemoteSkillSyncCoordinator $remoteCoordinator;

    private InjectedVendorMerger $injectedVendorMerger;

    /**
     * @param  list<AgentTarget>  $agentTargets
     */
    public function __construct(
        private array $agentTargets,
        private BoostConfigLoader $configLoader = new BoostConfigLoader(),
        private FrontmatterParser $frontmatterParser = new FrontmatterParser(),
        private SkillResolver $skillResolver = new SkillResolver(),
        private GuidelineResolver $guidelineResolver = new GuidelineResolver(),
        private FileWriter $writer = new FileWriter(),
        private GitignoreManager $gitignoreManager = new GitignoreManager(),
        private SkillTagFilter $skillTagFilter = new SkillTagFilter(),
        private GuidelineTagFilter $guidelineTagFilter = new GuidelineTagFilter(),
        private FilteredSkillPruner $filteredSkillPruner = new FilteredSkillPruner(),
        ?InstalledPackages $installedPackages = null,
        ?RemoteSkillIngester $remoteSkillIngester = null,
        ?RemoteOrphanPruner $remoteOrphanPruner = null,
    ) {
        $this->injectedVendorMerger = new InjectedVendorMerger($this->skillTagFilter, $this->guidelineTagFilter);
        $this->installedPackages = $installedPackages ?? InstalledPackages::fromComposer();
        $this->skillLoader = new SkillLoader($this->frontmatterParser);
        $this->guidelineLoader = new GuidelineLoader($this->frontmatterParser);
        $this->commandLoader = new CommandLoader($this->frontmatterParser);
        $this->vendorScanner = new VendorScanner($this->installedPackages);
        $this->emitterDiscovery = new EmitterDiscovery($this->installedPackages);
        $this->remoteSkillIngester = $remoteSkillIngester ?? new RemoteSkillIngester(
            cache: new RemoteSkillCache(
                fetcher: new GitHubFetcher(new CurlHttpTransport()),
            ),
            // Truthy-value check — `BOOST_REMOTE_STRICT=0` / `false` / `off`
            // must KEEP the documented warn-and-skip behavior. A bare presence
            // check would flip strict on for the no-op assignments users
            // reach for when they think they're disabling the flag.
            strict: Env::flagEnabled(Env::REMOTE_STRICT),
        );
        $this->remoteOrphanPruner = $remoteOrphanPruner ?? new RemoteOrphanPruner();
        $this->remoteCoordinator = new RemoteSkillSyncCoordinator(
            ingester: $this->remoteSkillIngester,
            orphanPruner: $this->remoteOrphanPruner,
            skillTagFilter: $this->skillTagFilter,
        );
    }

    public static function default(?InstalledPackages $installedPackages = null): self
    {
        return new self(
            agentTargets: self::allAgentTargets(),
            installedPackages: $installedPackages,
        );
    }

    /**
     * The canonical FULL catalog of every supported agent target. Used both by
     * default() and by the emitter reserved-path denylist — the latter must
     * reserve EVERY agent's emission roots regardless of the instance's fan-out
     * subset: a subset-constructed engine, e.g.
     * `new SyncEngine([new ClaudeCodeTarget()])`, must still reserve
     * `.cursor/…`, `.agents/skills/`, etc. against emitter writes.
     *
     * @return list<AgentTarget>
     */
    public static function allAgentTargets(): array
    {
        return [
            new ClaudeCodeTarget(),
            new CursorTarget(),
            new CopilotTarget(),
            new CodexTarget(),
            new GeminiTarget(),
            new JunieTarget(),
            new KiroTarget(),
            new OpenCodeTarget(),
            new AmpTarget(),
        ];
    }

    /**
     * Sync a globally-installed package's own `resources/boost/skills/` into
     * the user's home directory so the skills activate in every AI session
     * on the machine. Used primarily by Composer-global tools like
     * `sandermuller/repo-init`.
     *
     * Source: `$packageRoot/resources/boost/skills/`.
     * Target: `$HOME/.{agent}/skills/<package-suffix>/<skill-name>.md`.
     * Guidelines (CLAUDE.md, AGENTS.md, etc.) are NOT fanned out in user
     * scope — they'd pollute the home dir with project-specific instructions.
     *
     * No `boost.php` required: the invoking package itself is the source,
     * all 9 agents are activated by default.
     */
    public function syncUser(string $packageRoot, bool $checkOnly = false, ?string $homeRoot = null): UserScopeResult
    {
        $packageRoot = rtrim($packageRoot, '/');
        $home = $homeRoot !== null ? rtrim($homeRoot, '/') : self::resolveHomeDirectory();

        $composerJson = $packageRoot . '/composer.json';
        if (! is_file($composerJson)) {
            return new UserScopeResult(
                packageName: '',
                homeRoot: $home,
                writes: [],
                errors: [sprintf('composer.json not found at %s', $composerJson)],
                check: $checkOnly,
            );
        }

        $packageName = $this->extractPackageName($composerJson);
        if ($packageName === null) {
            return new UserScopeResult(
                packageName: '',
                homeRoot: $home,
                writes: [],
                errors: [sprintf('Could not read `name` from %s', $composerJson)],
                check: $checkOnly,
            );
        }

        $skillsDir = $packageRoot . '/resources/boost/skills';

        /** @var list<Skill> $skills */
        $skills = [];
        foreach ($this->skillLoader->load($skillsDir, $packageName) as $skill) {
            $skills[] = $skill;
        }

        /** @var list<WrittenFile> $writes */
        $writes = [];
        /** @var list<string> $errors */
        $errors = [];

        if (! $checkOnly) {
            (new UserScopeMigrator())->run($home, $packageName, $skills, $this->agentTargets);
        }

        foreach ($this->agentTargets as $target) {
            foreach ($target->plan($skills, []) as $pending) {
                $rewritten = $this->rewriteForUserScope($pending->relativePath, $packageName);
                if ($rewritten === null) {
                    continue;
                }

                $this->writeAndPrune(
                    $home,
                    new PendingWrite($rewritten, $pending->content),
                    $target,
                    $checkOnly,
                    $writes,
                    $errors,
                );
            }
        }

        return new UserScopeResult(
            packageName: $packageName,
            homeRoot: $home,
            writes: $writes,
            errors: $errors,
            check: $checkOnly,
        );
    }

    /**
     * User-scope sync EVERY installed package that ships
     * `resources/boost/skills/`. Surfaced as `boost sync --scope=user
     * --all`, run once after a `composer global require`.
     *
     * The per-package loop lives in {@see UserScopeBulkSync} — extracted
     * so it does not load this class's cognitive-complexity budget.
     *
     * @return list<UserScopeResult>  one per skill-shipping package
     */
    public function syncUserAll(bool $checkOnly = false, ?string $homeRoot = null): array
    {
        return (new UserScopeBulkSync())->run($this, $this->vendorScanner, $checkOnly, $homeRoot);
    }

    public static function resolveHomeDirectory(): string
    {
        $home = getenv('HOME');
        if (is_string($home) && $home !== '') {
            return rtrim($home, '/');
        }

        // Windows fallback
        $userprofile = getenv('USERPROFILE');
        if (is_string($userprofile) && $userprofile !== '') {
            return rtrim($userprofile, '/\\');
        }

        return sys_get_temp_dir();
    }

    private function extractPackageName(string $composerJsonPath): ?string
    {
        $raw = @file_get_contents($composerJsonPath);
        $decoded = $raw === false ? null : json_decode($raw, true);
        $name = is_array($decoded) ? ($decoded['name'] ?? null) : null;

        return is_string($name) && $name !== '' ? $name : null;
    }

    /**
     * Vendor-namespaced slug used as the user-scope path segment under
     * `~/.{agent}/skills/<suffix>/`.
     *
     * `acme/foo` → `acme__foo`. Names without a `/` (rare; non-Composer
     * packages) pass through unchanged.
     *
     * Separator choice: `__` (double underscore) is disallowed by the
     * Composer name spec — both vendor and project parts only permit a
     * single `_` between alphanumerics. So `__` cannot appear inside a
     * valid Composer name, which makes this mapping injective: distinct
     * package names always produce distinct slugs (no `vendor-a/foo` vs
     * `vendor/a-foo` style ambiguity that a `-` separator would admit).
     *
     * @see packageBasename for the bare-basename form used by the
     *   rewriteForUserScope dedupe.
     */
    public static function packageSuffix(string $packageName): string
    {
        return str_replace('/', '__', $packageName);
    }

    /**
     * Bare basename of a Composer package — the portion after the last `/`.
     * Used by rewriteForUserScope's dedupe to collapse `<slug>/<basename>/SKILL.md`
     * to `<slug>/SKILL.md` when the source skill directory is named after
     * the package itself (common for single-skill tooling distributions).
     */
    public static function packageBasename(string $packageName): string
    {
        $slash = strrpos($packageName, '/');

        return $slash === false ? $packageName : substr($packageName, $slash + 1);
    }

    /**
     * Inject the package-suffix between `skills/` and the filename so multiple
     * packages can publish user-scope skills without colliding. Returns null
     * for paths that aren't under a `skills/` directory (guideline files).
     *
     * When the first component of the filename already matches the package
     * suffix (common for single-skill tooling packages where the skill dir
     * is named after the package, e.g. `sandermuller/repo-init` shipping
     * `resources/boost/skills/repo-init/SKILL.md`), the redundant level is
     * dropped — output stays `.claude/skills/repo-init/SKILL.md` instead of
     * `.claude/skills/repo-init/repo-init/SKILL.md`. Multi-skill packages
     * and packages whose skill name differs from the package basename are
     * unaffected.
     *
     * Examples (with packageName `acme/repo-init`, slug `acme-repo-init`):
     *   `.claude/skills/foo.md`                → `.claude/skills/acme-repo-init/foo.md`
     *   `.claude/skills/foo/SKILL.md`          → `.claude/skills/acme-repo-init/foo/SKILL.md`
     *   `.claude/skills/repo-init/SKILL.md`    → `.claude/skills/acme-repo-init/SKILL.md`   (deduped: skill basename matches package basename)
     *   `CLAUDE.md`                            → null
     *   `AGENTS.md`                            → null
     */
    private function rewriteForUserScope(string $relativePath, string $packageName): ?string
    {
        $marker = '/skills/';
        $pos = strpos($relativePath, $marker);
        if ($pos === false) {
            return null;
        }

        $prefix = substr($relativePath, 0, $pos + strlen($marker));
        $filename = substr($relativePath, $pos + strlen($marker));

        $packageBasename = self::packageBasename($packageName);
        $firstSlash = strpos($filename, '/');
        if ($firstSlash !== false && substr($filename, 0, $firstSlash) === $packageBasename) {
            $filename = substr($filename, $firstSlash + 1);
        }

        return $prefix . self::packageSuffix($packageName) . '/' . $filename;
    }

    /**
     * Resolve the same skill / guideline / command set `sync()` would
     * emit, without writing anything. Powers `boost where` (origin
     * tracing) — same pipeline as the live sync (host + scanned vendors
     * + remote, tag-filtered, collision-resolved), exposed as a
     * read-only inspection.
     *
     * Each resolved item carries a `sourceVendor` field (or null for
     * host). The key lists let the caller label each origin precisely
     * PER CATEGORY — a vendor publishing only guidelines must not show
     * up as a `vendor` source for the SKILLS section, and the remote-
     * skills pipeline is skills-only so guidelines/commands never get
     * the `remote` label:
     *
     *  - `remoteSourceKeys` — `<owner>/<repo>` keys from `withRemoteSkills(...)`.
     *    SKILLS-only — there is no remote-guideline or remote-command pipeline.
     *  - `scannedSkillVendorKeys` — allowlisted vendors that publish skills.
     *  - `scannedGuidelineVendorKeys` — allowlisted vendors that publish guidelines.
     *
     * Overlap is legal (a `<vendor>/<package>` key can participate in
     * both `withRemoteSkills` and the scanned-vendor skill set as long
     * as skill names stay unique upstream). `WhereCommand` consumes
     * the per-category sets to render unambiguous labels.
     *
     * Caller-injected vendors (the wrapper-package pattern) are NOT
     * included — those are runtime-only inputs to `sync()` and the
     * wrapper owns its own inspection surface.
     *
     * @return array{skills: list<Skill>, guidelines: list<Guideline>, commands: list<Command>, remoteSourceKeys: list<string>, scannedSkillVendorKeys: list<string>, scannedGuidelineVendorKeys: list<string>}
     */
    public function resolveForInspection(string $projectRoot): array
    {
        $projectRoot = rtrim($projectRoot, '/');
        $config = $this->configLoader->load($projectRoot);
        $allowedVendors = $this->discoverAllowedVendors($config);

        $remoteSourceKeys = array_map(
            static fn (RemoteSkillSource $source): string => $source->source,
            $config->remoteSkills,
        );

        $scannedSkillVendorKeys = [];
        $scannedGuidelineVendorKeys = [];
        foreach ($allowedVendors as $vendor) {
            if ($vendor->publishesSkills()) {
                $scannedSkillVendorKeys[] = $vendor->name;
            }

            if ($vendor->guidelinesPath !== null) {
                $scannedGuidelineVendorKeys[] = $vendor->name;
            }
        }

        $renderErrors = [];

        return [
            'skills' => $this->resolveSkills($config, $allowedVendors, false, [], true)['skills'],
            'guidelines' => $this->resolveGuidelines($config, $allowedVendors, false, [], $renderErrors),
            'commands' => $this->resolveCommands($config),
            'remoteSourceKeys' => array_values($remoteSourceKeys),
            'scannedSkillVendorKeys' => $scannedSkillVendorKeys,
            'scannedGuidelineVendorKeys' => $scannedGuidelineVendorKeys,
        ];
    }

    /**
     * Back-compat thin wrapper around `resolveForInspection()`. New callers
     * should use the broader method directly.
     *
     * Preserves the `scannedVendorKeys` shape — the union of every
     * allowlisted vendor that publishes anything, regardless of category.
     *
     * @return array{skills: list<Skill>, remoteSourceKeys: list<string>, scannedVendorKeys: list<string>}
     */
    public function resolveSkillsForInspection(string $projectRoot): array
    {
        $full = $this->resolveForInspection($projectRoot);

        $scannedUnion = array_values(array_unique([
            ...$full['scannedSkillVendorKeys'],
            ...$full['scannedGuidelineVendorKeys'],
        ]));

        return [
            'skills' => $full['skills'],
            'remoteSourceKeys' => $full['remoteSourceKeys'],
            'scannedVendorKeys' => $scannedUnion,
        ];
    }

    /**
     * @param  array<string, list<Skill>>  $injectedVendorSkills  Pre-built Skills keyed by source vendor (e.g. `['laravel/boost' => $skills]`). Caller-controlled injection point — covers ecosystems whose layout boost-core's VendorScanner does not match (laravel/boost's `.ai/<pkg>/`, ad-hoc bridges). Tag filter applies the same as for scanned vendors.
     * @param  list<SkillRenderer>  $extraSkillRenderers  Additional renderers to append to `BoostConfig::skillRenderers` for this sync transaction. Caller-controlled — lets a wrapper package (e.g. project-boost-laravel) guarantee its BladeRenderer is registered without forcing users to wire it in `boost.php`. Conflict detection runs against the merged list.
     * @param  array<string, list<Guideline>>  $injectedVendorGuidelines  Pre-built Guidelines keyed by source vendor. Mirrors `$injectedVendorSkills` for the guideline pipeline.
     */
    public function sync(string $projectRoot, bool $checkOnly = false, bool $force = false, array $injectedVendorSkills = [], array $extraSkillRenderers = [], array $injectedVendorGuidelines = []): SyncResult
    {
        $projectRoot = rtrim($projectRoot, '/');
        $config = $this->configLoader->load($projectRoot);

        $config = $this->injectedVendorMerger->mergeExtraRenderers($config, $extraSkillRenderers);

        $allowedVendors = $this->discoverAllowedVendors($config);

        /** @var list<string> $guidelineRenderErrors */
        $guidelineRenderErrors = [];
        /** @var list<array{guideline: string, shadowedVendor: string}> $hostGuidelineShadows */
        $hostGuidelineShadows = [];
        try {
            $skillResolution = $this->resolveSkills($config, $allowedVendors, $force, $injectedVendorSkills, $checkOnly);
            $resolvedGuidelines = $this->resolveGuidelines($config, $allowedVendors, $force, $injectedVendorGuidelines, $guidelineRenderErrors, $hostGuidelineShadows);
        } catch (CollidingSkillsException $collidingSkillsException) {
            return new SyncResult(writes: [], emitters: [], errors: [$collidingSkillsException->getMessage()], check: $checkOnly);
        } catch (SkillSourceCollisionException $sourceCollisionException) {
            // Caller-config-error class (injected vendor map / remote source
            // overlapping a vendor key). Converted to a SyncResult error so
            // wrappers (BoostAutoSync, project-boost-laravel) that expect
            // sync() to never throw on user-config issues keep working.
            return new SyncResult(writes: [], emitters: [], errors: [$sourceCollisionException->getMessage()], check: $checkOnly);
        }

        $resolvedSkills = $skillResolution['skills'];
        $droppedSkillNames = $skillResolution['droppedNames'];
        $tagFilteredCount = $skillResolution['tagFilteredCount'];
        $remoteErrors = array_merge($skillResolution['remoteErrors'], $guidelineRenderErrors);
        $resolvedCommands = $this->resolveCommands($config);

        // Conventions inlining: build the slot inliner once + inline resolved
        // skills (vendor AND host) before fan-out. Each skill's body gets its
        // `<!--boost:conv …-->` tokens resolved to inlined values, plus the
        // per-skill self-check, owned by ConventionsPass.
        // $conventionsRequiresRuntime aggregates whether ANY live skill still
        // needs the rendered `## Project Conventions` block (a legacy `$.slot`
        // ref or an unresolved/errored token); $conventionsErrors are
        // render-class token errors (fail --check, keep the block).
        $conventionsPass = ConventionsPass::build($this->installedPackages, $config);
        $skillInline = $conventionsPass->inlineSkills($resolvedSkills);
        $resolvedSkills = $skillInline['skills'];
        /** @var list<Diagnostic> $conventionsDiagnostics */
        $conventionsDiagnostics = [...$conventionsPass->diagnostics(), ...$skillInline['selfCheck']];
        /** @var list<string> $conventionsErrors */
        $conventionsErrors = $skillInline['errors'];
        $conventionsRequiresRuntime = $skillInline['requiresRuntime'];
        $conventionsInlinedAny = $skillInline['inlinedAny'];

        $context = new SyncContext(
            projectRoot: $projectRoot,
            packages: $this->installedPackages,
            config: $config,
        );

        $gitignoreManaged = $config->manageGitignore && getenv(Env::SKIP_GITIGNORE) === false;

        // Load the PRIOR ownership manifest (state at sync start). All
        // destructive decisions (empty-guard clear, orphan reap) read this
        // prior snapshot; the NEW manifest is written only after a successful
        // sync. Absent/corrupt → empty → no ownership asserted.
        //
        // Gated on gitignore management: when it's disabled the manifest is no
        // longer UPDATED (see the write gate below), so reading a now-stale one
        // could blank/reap a file on ownership data that no longer tracks
        // reality. Disabling the READ too degrades ownership-based actions
        // cleanly to the markerless preserve behavior in that mode.
        //
        // Read BEFORE runEmitters: the emitter first-adoption warn consults it
        // to detect taking over a not-yet-owned file.
        $priorManifest = $gitignoreManaged
            ? SyncManifest::fromProjectRoot($projectRoot)
            : SyncManifest::empty();

        // Discover wrapper-claimed paths up-front — fed to the managed
        // gitignore block, the stale-cleanup exclusion, the manifest writer, and
        // the emitter reserved-path denylist so an emitter can't claim a
        // wrapper-owned path.
        $activeAgents = array_map(static fn (Agent $agent): string => $agent->value, $config->agents);
        $wrapperEmits = (new WrapperEmitDiscovery($this->installedPackages))->discover($projectRoot, $activeAgents);

        // An emitter must never write to a path boost-core or the operator owns
        // (arbitrary emitter paths could overwrite then later reap operator/core
        // files). Build the denylist before runEmitters.
        $reservedEmitterPaths = $this->reservedEmitterPaths(array_keys($wrapperEmits['paths']));

        /** @var list<Diagnostic> $emitterDiagnostics */
        $emitterDiagnostics = [];
        /** @var array<string, true> $ownableEmitterPaths */
        $ownableEmitterPaths = [];
        $emitterResults = $this->runEmitters(
            $projectRoot,
            $config,
            $context,
            $checkOnly,
            $priorManifest,
            $reservedEmitterPaths,
            $emitterDiagnostics,
            $ownableEmitterPaths,
        );

        // Derive the reap sets once — paths emitted this sync (kept),
        // FQCNs DISABLED/errored this sync (their files preserved), and whether
        // any live emitter output exists (a manifest entry → needs `.boost/`
        // gitignored even in an emitter-only project).
        [
            'intended' => $intendedEmitterPaths,
            'preserved' => $preservedEmitterFqcns,
            'hasLiveOutput' => $hasLiveEmitterOutput,
        ] = OrphanReaper::emitterReapSets($emitterResults);

        [$fanOutWrites, $fanOutErrors] = $this->fanOut(
            $projectRoot,
            $config,
            $resolvedSkills,
            $resolvedGuidelines,
            $resolvedCommands,
            $droppedSkillNames,
            $checkOnly,
            $guidelineRenderErrors,
        );

        $remoteOrphanWrites = $this->remoteCoordinator->applyOrphanPruning(
            $projectRoot,
            $this->agentTargets,
            $config,
            $resolvedSkills,
            $checkOnly,
        );

        // Snapshot prior boost-managed gitignore patterns BEFORE updateGitignore
        // overwrites them. cleanupStalePaths uses this manifest to distinguish
        // boost-emitted dirs (safe to delete) from operator-authored content.
        $priorManagedPatterns = $this->readPriorGitignorePatterns($projectRoot);
        $priorManagedFiles = $this->enumerateManagedFiles($projectRoot, $priorManagedPatterns);

        // Will this sync write a `.boost/manifest.json`? (Manifest is non-empty
        // when boost emits guidance/skills/commands, or there's a prior to
        // refresh.) If so, `.boost/` must be in the managed .gitignore so the
        // regenerable manifest never dirties the working tree. Computed here —
        // guidance + skills are already resolved — and passed to updateGitignore
        // so the ignore lands in the SAME write, gated to avoid adding `.boost/`
        // to an otherwise-empty project's gitignore. conventions is included
        // because it renders into CLAUDE.md (→ a manifest entry) even without
        // host guidelines. An unignored manifest breaks clean-tree / CI checks.
        // Skills/guidelines/commands only EMIT (→ manifest entries) when there
        // are active agents to receive them; with no agents nothing is written
        // regardless of what resolved. Conventions render into CLAUDE.md even
        // without the Claude agent, so they count on their own. A prior manifest
        // is refreshed regardless. A live FileEmitter output is also a manifest
        // entry ($hasLiveEmitterOutput, derived above), so it must trigger the
        // `.boost/` gitignore line on its own — an emitter-only project (no
        // agents/conventions) still needs the manifest ignored.
        $willWriteManifest = $gitignoreManaged && (
            ($config->agents !== [] && ($resolvedSkills !== [] || $resolvedGuidelines !== [] || $resolvedCommands !== []))
            || $config->conventions !== []
            || $hasLiveEmitterOutput
            || ! $priorManifest->isEmpty()
        );

        $gitignoreWrite = $gitignoreManaged
            ? $this->updateGitignore($projectRoot, $config, $checkOnly, array_keys($wrapperEmits['paths']), $willWriteManifest)
            : null;

        $writes = array_merge($fanOutWrites, $remoteOrphanWrites);
        if ($gitignoreWrite instanceof WrittenFile) {
            $writes[] = $gitignoreWrite;
        }

        // Agent-guidance files (CLAUDE.md/AGENTS.md/GEMINI.md) are written
        // wholesale + markerless here.
        $guidanceResult = (new GuidanceWriter($this->writer, $this->agentTargets))->write(
            $projectRoot,
            $config,
            $resolvedGuidelines,
            $checkOnly,
            $guidelineRenderErrors !== [],
            $priorManifest,
            $conventionsPass,
            $conventionsRequiresRuntime,
            $conventionsInlinedAny,
        );
        $writes = [...$writes, ...$guidanceResult['writes']];
        // Token errors (render-class) from skills + guidance fail --check and
        // were already kept-the-block by the gate; surface them as errors.
        $conventionsErrors = [...$conventionsErrors, ...$guidanceResult['conventionsErrors']];

        $cleanupResult = $this->cleanupStalePaths($projectRoot, $config, $checkOnly);
        $writes = [...$writes, ...$cleanupResult['writes']];

        // Generic stale-file cleanup — clean-slate model. Any file that
        // was inside a boost-managed gitignore pattern BEFORE this sync but
        // wasn't rewritten by this sync is stale. Delete it. Catches any
        // emission boost-core stopped making (vendor drops a skill, allowlist
        // changes, target emission location moves, etc.) without per-case
        // cleanup logic. Guideline files (CLAUDE.md/AGENTS.md/GEMINI.md) are
        // NOT in the gitignore manifest — they use ManagedRegion + are
        // operator-tracked, so this pass leaves them alone.
        //
        // Error-state safety: skip the clean-slate pass when any error
        // surfaced (fanOut, remote, or emitter). Errors signal partial
        // sync state — a still-declared remote skill that failed to fetch
        // this run is in priorManagedFiles but NOT in $writes; deleting it
        // would discard the previously-cached working copy, breaking the
        // "transient fetch failure preserves prior content" contract.
        // Conventions token errors (render-class) put the sync in a degraded
        // state — skip destructive cleanup/reap/manifest just like other errors.
        $hasAnyError = $fanOutErrors !== [] || $remoteErrors !== [] || $conventionsErrors !== [];
        foreach ($emitterResults as $emitterResult) {
            if ($emitterResult->action === EmitterAction::ERRORED) {
                $hasAnyError = true;

                break;
            }
        }

        /** @var list<Diagnostic> $reapDiagnostics */
        $reapDiagnostics = [];

        // Drift-comparison wrapper-injection awareness: when a wrapper
        // package (e.g., project-boost-laravel) injects skills/guidelines via
        // `injectedVendorSkills`/`injectedVendorGuidelines`, bare-CLI sync
        // would otherwise flag previously-emitted-from-injection files as
        // stale-to-delete because the resolve pass returns empty. The wrapper
        // declares its emit surface via a `BoostWrapper` class implementing
        // `BoostWrapperContract`; the discovery returns the union of those
        // declarations across all installed wrappers, and the cleanup pass
        // excludes those paths from stale-file classification.
        // ($wrapperEmits already computed above to feed the gitignore pass.)
        if (! $hasAnyError) {
            $writes = $this->cleanupStaleManagedFiles(
                $projectRoot,
                $priorManagedFiles,
                $writes,
                $checkOnly,
                $wrapperEmits['paths'],
            );
        }

        // Write the NEW ownership manifest LAST — only on a successful,
        // real (non-check) sync, AFTER all non-destructive writes + cleanup
        // succeeded. Never rewrite from a partial/failed sync, so the prior
        // manifest stays last-known-good. Gated on gitignore management:
        // without it nothing adds `.boost/` to the ignore list, so writing the
        // manifest would leave an untracked file behind and break
        // clean-working-tree / CI flows. When gitignore is unmanaged the
        // manifest simply isn't written → ownership features degrade to the
        // markerless preserve behavior (backward-safe).
        //
        // Also skip on a guideline render failure: writeGuidanceFiles() then
        // preserves the existing guidance files + returns no ownedGuidancePaths,
        // so rewriting the manifest would DROP guidance ownership and a later
        // empty sync could no longer converge previously-owned files. Leaving
        // the prior manifest untouched keeps it last-known-good.
        if (! $checkOnly && ! $hasAnyError && $gitignoreManaged && $guidelineRenderErrors === []) {
            // Reconcile-on-sync orphan reap — delete boost-owned files recorded
            // in the PRIOR manifest that this sync no longer intends to emit (a
            // dormant FileEmitter, a de-selected agent's guidance file).
            // Manifest-GATED: the delete predicate consults the prior manifest's
            // ownership, NOT raw gitignore membership. Runs after all
            // non-destructive writes + cleanup succeeded, before the new manifest
            // is written. The reap's targets are absent from the
            // ownedGuidancePaths / live-emitter sets, so the new manifest below
            // never re-records them.
            $reap = OrphanReaper::reapManifestOrphans(
                $projectRoot,
                $priorManifest,
                $intendedEmitterPaths,
                $preservedEmitterFqcns,
                $guidanceResult['ownedGuidancePaths'],
                $wrapperEmits['paths'],
            );
            $writes = [...$writes, ...$reap['writes']];

            // A failed orphan delete keeps its ownership: surface it and carry
            // the entry forward so the next sync retries.
            foreach ($reap['retained'] as $retainedPath) {
                $reapDiagnostics[] = Diagnostic::warning(
                    null,
                    sprintf(
                        'Could not delete orphaned boost-owned file "%s" (permission/filesystem error). Its ownership is retained so the next sync retries the cleanup; remove it by hand if it persists.',
                        $retainedPath,
                    ),
                );
            }

            $this->writeSyncManifest($projectRoot, $guidanceResult['ownedGuidancePaths'], $wrapperEmits['paths'], $emitterResults, $priorManifest, $reap['retained'], $ownableEmitterPaths);
        }

        // Diagnostic surface for the render-fail-then-write safety gate:
        // operator-visible signal that guideline writes were skipped, naming
        // each failed source so they know which renderer / file to investigate.
        $renderFailDiagnostics = [];
        foreach ($guidelineRenderErrors as $errorMessage) {
            $renderFailDiagnostics[] = Diagnostic::warning(
                null,
                sprintf(
                    'Guideline render failed; the prior agent-guidance file content is preserved byte-for-byte (the wholesale markerless write is skipped this run). Run `vendor/bin/boost sync` again after resolving the render failure. Source: %s',
                    $errorMessage,
                ),
            );
        }

        return new SyncResult(
            writes: $writes,
            emitters: $emitterResults,
            // Conventions token errors (render-class) join errors[] so they fail
            // `boost sync --check` and strict validation (D7).
            errors: array_merge($fanOutErrors, $remoteErrors, $conventionsErrors),
            check: $checkOnly,
            tagFilteredSkillsCount: TagFilterNudge::count($config, $tagFilteredCount),
            hostShadows: $skillResolution['hostShadows'],
            hostGuidelineShadows: $hostGuidelineShadows,
            diagnostics: [
                ...$conventionsDiagnostics,
                ...$guidanceResult['diagnostics'],
                ...$cleanupResult['diagnostics'],
                ...$wrapperEmits['diagnostics'],
                ...$renderFailDiagnostics,
                ...$emitterDiagnostics,
                ...$reapDiagnostics,
            ],
        );
    }

    /**
     * Remove paths boost-core retired entirely. boost-core IS the owner of
     * category-3 AI-agent paths (`.github/copilot-instructions.md`,
     * `.github/skills/`, agent-spec files, etc.). Operator-side influence
     * runs through `.ai/` sources, allowlisted vendor packages, remote
     * skills, and `boost.php` config — NOT through hand-editing emission
     * targets. When boost-core retires an emission path, the file is
     * boost-emitted output that no longer has a refresh path; delete it.
     *
     * Trigger conditions named explicitly:
     *  1. The agent owning the path is in the active agent set (e.g.,
     *     `.github/*` cleanup requires `Agent::COPILOT` — a project that
     *     never had Copilot active has no boost-emitted content at those
     *     paths, so cleanup would be wrong).
     *  2. The path is in the retired-paths registry (hardcoded list of
     *     paths boost-core has emitted to in past versions but no longer
     *     emits in the current version).
     *  3. The path exists on disk.
     *
     * All three must hold; meeting all three means the file is
     * unambiguously boost-emitted historical output. Delete unconditionally.
     *
     * Reports drift via `WrittenFile` entries (DELETED / WOULD_DELETE) so
     * `boost sync --check` and CI surface the upcoming cleanup as
     * countWouldChange > 0 + hasDrift() = true.
     *
     * @return array{writes: list<WrittenFile>, diagnostics: list<Diagnostic>}
     */
    private function cleanupStalePaths(string $projectRoot, BoostConfig $config, bool $checkOnly): array
    {
        if (! $config->hasAgent(Agent::COPILOT)) {
            return ['writes' => [], 'diagnostics' => []];
        }

        $writes = [];
        $diagnostics = [];

        foreach (self::RETIRED_COPILOT_PATHS as $relativePath) {
            $absolute = $projectRoot . '/' . $relativePath;
            if (! file_exists($absolute) && ! is_link($absolute)) {
                continue;
            }

            $failures = [];
            $write = $this->cleanupPath($absolute, $relativePath, $checkOnly, $failures);

            // When @-suppressed fs operations leave residual paths (permission
            // denied, open file descriptor, race with re-emission), surface it.
            //
            // On failure, do NOT mark this path as DELETED in $writes (would
            // poison SyncResult::hasDrift() + the "deleted=" summary count
            // for wrapper-side consumers reading the write log — both would
            // report cleanup success while the path persists on disk). Do
            // NOT emit the "removed" INFO either (contradicts the warning we
            // surface next). Only the actionable warning fires.
            if ($failures === []) {
                $writes[] = $write;
                $diagnostics[] = Diagnostic::info(null, $this->cleanupMessage($relativePath, $checkOnly));

                continue;
            }

            $diagnostics[] = Diagnostic::warning(
                null,
                sprintf(
                    'Cleanup of `%s` left %d residual path(s) on disk — drift will persist until removed manually. Likely cause: permission denied, open file descriptor, or concurrent re-emission. Residual: %s',
                    $relativePath,
                    count($failures),
                    implode(', ', array_slice($failures, 0, 5)) . (count($failures) > 5 ? sprintf(' (+%d more)', count($failures) - 5) : ''),
                ),
            );
        }

        return ['writes' => $writes, 'diagnostics' => $diagnostics];
    }

    /**
     * @param  list<string>  $failures  Paths that could not be removed (by ref)
     */
    private function cleanupPath(string $absolute, string $relative, bool $checkOnly, array &$failures): WrittenFile
    {
        if (! $checkOnly) {
            if (is_link($absolute)) {
                if (! @unlink($absolute)) {
                    $failures[] = $absolute;
                }
            } elseif (is_dir($absolute)) {
                $this->deleteRecursive($absolute, $failures);
            } elseif (! @unlink($absolute)) {
                $failures[] = $absolute;
            }
        }

        return new WrittenFile(
            relativePath: $relative,
            absolutePath: $absolute,
            action: $checkOnly ? WriteAction::WOULD_DELETE : WriteAction::DELETED,
        );
    }

    private function cleanupMessage(string $relativePath, bool $checkOnly): string
    {
        $verb = $checkOnly ? 'would remove' : 'removed';

        return sprintf(
            'Cleanup: %s retired boost-core path `%s`. boost-core generates this file, so once no emitter still produces it, sync removes it. Do not edit these files by hand; boost rewrites them on every sync. To change what gets emitted, edit your `.ai/` sources, allowlisted vendors (`withAllowedVendors`), remote skills (`withRemoteSkills`), or `boost.php`. If you wrote content here yourself, recover it from git before the next sync.',
            $verb,
            $relativePath,
        );
    }

    /**
     * Enumerate every file currently on disk under any of the given
     * boost-managed gitignore patterns. Used by the clean-slate post-sync
     * pass: anything in this list NOT rewritten by the current sync is
     * stale and gets deleted.
     *
     * Patterns ending with `/` are treated as directories — recursed.
     * Other patterns are treated as single file paths. Wildcard / glob
     * patterns are skipped (boost-managed gitignore only uses directory +
     * file patterns).
     *
     * Symlinks at the pattern root are skipped — never followed, never
     * deleted. Matches `FileWriter::anySegmentIsSymlink()` safety contract.
     *
     * @param  list<string>  $patterns
     * @return list<string>  relative file paths inside any pattern
     */
    private function enumerateManagedFiles(string $projectRoot, array $patterns): array
    {
        $files = [];
        foreach ($patterns as $pattern) {
            if ($pattern === '') {
                continue;
            }

            // The sync manifest dir is engine-internal state owned by
            // SyncManifest itself — never a stale-cleanup target. Skip it so the
            // cleanup pass doesn't delete the manifest it's about to rely on.
            if (rtrim($pattern, '/') === SyncManifest::DIR) {
                continue;
            }

            if (str_contains($pattern, '*')) {
                continue;
            }

            if (str_contains($pattern, '?')) {
                continue;
            }

            $relative = rtrim($pattern, '/');
            $absolute = $projectRoot . '/' . $relative;

            if (is_link($absolute)) {
                continue;
            }

            if (is_file($absolute)) {
                $files[] = $relative;

                continue;
            }

            if (! is_dir($absolute)) {
                continue;
            }

            $iter = new RecursiveIteratorIterator(
                new RecursiveDirectoryIterator($absolute, FilesystemIterator::SKIP_DOTS),
                RecursiveIteratorIterator::LEAVES_ONLY,
            );
            /** @var SplFileInfo $file */
            foreach ($iter as $file) {
                if (! $file->isFile()) {
                    continue;
                }

                if ($file->isLink()) {
                    continue;
                }

                $files[] = $relative . '/' . str_replace('\\', '/', substr($file->getPathname(), strlen($absolute) + 1));
            }
        }

        return $files;
    }

    /**
     * Delete files that were inside boost-managed patterns BEFORE this sync
     * but weren't rewritten this run. The clean-slate model: anything
     * boost-core no longer publishes is stale. Removes per-file then walks
     * up to clean empty parent directories so a directory that lost every
     * file disappears entirely.
     *
     * Skips files already deleted by other prune passes (FilteredSkillPruner,
     * RemoteOrphanPruner) — `file_exists()` returns false on already-gone
     * files, no double DELETED records.
     *
     * @param  list<string>  $priorManagedFiles
     * @param  list<WrittenFile>  $writes
     * @param  array<string, string>  $wrapperExcludedPaths  paths
     *   declared by `BoostWrapper` classes from installed wrapper packages —
     *   excluded from "stale-to-delete" classification so bare-CLI doesn't
     *   false-positive-flag wrapper-injected files for deletion.
     * @return list<WrittenFile>
     */
    private function cleanupStaleManagedFiles(string $projectRoot, array $priorManagedFiles, array $writes, bool $checkOnly, array $wrapperExcludedPaths = []): array
    {
        $writtenPaths = [];
        foreach ($writes as $w) {
            $writtenPaths[$w->relativePath] = true;
        }

        foreach ($priorManagedFiles as $relativePath) {
            if (isset($writtenPaths[$relativePath])) {
                continue;
            }

            // Wrapper-claimed paths get preserved. Wrapper-driven sync rewrites
            // them on next invocation; bare-CLI must NOT delete. Both exact-file
            // and directory-prefix match: a wrapper claim of `.agents/skills/foo`
            // (directory) should preserve every file under it. Without
            // prefix-match, wrapper dir claims would only preserve the dir entry
            // itself (which wouldn't be in priorManagedFiles anyway) while
            // children get false-positive-deleted.
            $canonicalRelative = ManagedFileOps::canonicalizeWrapperPath($relativePath);
            if (ManagedFileOps::isUnderWrapperClaim($canonicalRelative, $wrapperExcludedPaths)) {
                continue;
            }

            $absolute = $projectRoot . '/' . $relativePath;
            if (! file_exists($absolute) && ! is_link($absolute)) {
                continue;
            }

            if (! $checkOnly) {
                @unlink($absolute);
                ManagedFileOps::removeEmptyParentDirs($projectRoot, $absolute);
            }

            $writes[] = new WrittenFile(
                relativePath: $relativePath,
                absolutePath: $absolute,
                action: $checkOnly ? WriteAction::WOULD_DELETE : WriteAction::DELETED,
            );
        }

        return $writes;
    }

    /**
     * Guideline files (CLAUDE.md / AGENTS.md / GEMINI.md / similar) use
     * ManagedRegion + are operator-tracked, never wholesale-replaced. Adding
     * them to the gitignore-managed manifest would route them through
     * cleanupStaleManagedFiles which would delete the WHOLE file when stale,
     * destroying operator content outside boost-core's markers. Filter at
     * the gitignore-pattern emit point so wrapper-returned guideline-file
     * paths are silently dropped from the managed manifest.
     */
    private function isGuidelineFilePath(string $relativePath): bool
    {
        return in_array($relativePath, ['CLAUDE.md', 'AGENTS.md', 'GEMINI.md'], true);
    }

    /**
     * Extract the patterns boost-core previously gitignored, by reading
     * the managed block in `.gitignore`. Used by `cleanupStalePaths()` to
     * distinguish boost-emitted directories (safe to delete) from
     * operator-authored content (must be preserved).
     *
     * @return list<string>
     */
    private function readPriorGitignorePatterns(string $projectRoot): array
    {
        $gitignorePath = $projectRoot . '/.gitignore';
        if (! is_file($gitignorePath)) {
            return [];
        }

        $content = (string) @file_get_contents($gitignorePath);
        $start = strpos($content, GitignoreManager::START);
        if ($start === false) {
            return [];
        }

        $end = strpos($content, GitignoreManager::END, $start);
        if ($end === false) {
            return [];
        }

        $block = substr($content, $start, $end - $start);
        $patterns = [];
        $lines = preg_split('/\r?\n/', $block);
        foreach ($lines === false ? [] : $lines as $line) {
            $trimmed = trim($line);
            if ($trimmed === '') {
                continue;
            }

            if (str_starts_with($trimmed, '#')) {
                continue;
            }

            $patterns[] = $trimmed;
        }

        return $patterns;
    }

    /**
     * @param  list<string>  $failures  Paths that could not be removed (by ref)
     */
    private function deleteRecursive(string $path, array &$failures = []): void
    {
        // is_link() must be checked BEFORE is_dir() — PHP's is_dir() follows
        // symlinks and reports a symlink-to-directory as a directory, which
        // would route us into @rmdir($path). rmdir requires a real directory
        // and fails on a symlink, leaving residual drift. The engine can
        // encounter such symlinks (e.g. a skills dir symlinked into vendor
        // content) when cleaning a retired registry path.
        if (is_link($path)) {
            if (! @unlink($path)) {
                $failures[] = $path;
            }

            return;
        }

        if (! is_dir($path)) {
            if (! @unlink($path)) {
                $failures[] = $path;
            }

            return;
        }

        // RecursiveDirectoryIterator::hasChildren() defaults to
        // $allowLinks=false, so the iterator never descends INTO yielded
        // symlinks (which would otherwise walk vendor content through the
        // symlink target). Symlinks ARE yielded as top-level entries inside
        // their parent dir so the loop body can unlink them — see the
        // isLink() branch below.
        $iter = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($path, FilesystemIterator::SKIP_DOTS),
            RecursiveIteratorIterator::CHILD_FIRST,
        );
        /** @var SplFileInfo $file */
        foreach ($iter as $file) {
            $pathname = $file->getPathname();
            // Same is_link-before-is_dir ordering as the top of this method.
            // SplFileInfo::isDir() follows symlinks; a symlink-to-dir yielded
            // by the iterator would route into @rmdir without this guard.
            if ($file->isLink()) {
                $ok = @unlink($pathname);
            } elseif ($file->isDir()) {
                $ok = @rmdir($pathname);
            } else {
                $ok = @unlink($pathname);
            }

            if (! $ok) {
                $failures[] = $pathname;
            }
        }

        if (! @rmdir($path)) {
            $failures[] = $path;
        }
    }

    /**
     * Build the reserved-path denylist for FileEmitter outputs. An emitter must
     * emit only to a path it alone owns; returning any of these is a contract
     * violation (arbitrary emitter paths could otherwise overwrite then later
     * reap core/operator files). Exact matches: guidance basenames +
     * `.gitignore`; per-sync wrapper-claimed paths. Prefix matches: source dirs
     * + `.boost/` + EVERY agent's skill/command roots — reserved regardless of
     * which agents are active, since an emitter must not write into
     * `.claude/skills/…` even when Claude is inactive (that surface may already
     * hold tracked content and would otherwise be claimed + reaped).
     *
     * @param  list<string>  $wrapperClaimedPaths
     * @return array{exact: array<string, true>, prefixes: list<string>}
     */
    private function reservedEmitterPaths(array $wrapperClaimedPaths): array
    {
        // Keys stored LOWERCASED: on a case-insensitive filesystem
        // (default macOS) `claude.md` resolves to the same on-disk file as
        // `CLAUDE.md`, so a case-sensitive compare would let an emitter dodge
        // the denylist with a case variant. Fold case unconditionally — an
        // emitter has no business emitting a case-variant of a reserved name on
        // any platform.
        $exact = [
            'claude.md' => true,
            'agents.md' => true,
            'gemini.md' => true,
            '.gitignore' => true,
        ];
        $prefixes = ['.ai/', 'resources/boost/', strtolower(SyncManifest::DIR) . '/'];
        foreach ($wrapperClaimedPaths as $wrapperPath) {
            $canonical = strtolower(ManagedFileOps::canonicalizeWrapperPath($wrapperPath));
            // Exact match (file claim) AND a descendant prefix: a wrapper
            // DIRECTORY claim like `.agents/skills/foo` owns its whole
            // subtree, so `.agents/skills/foo/SKILL.md` must also be reserved —
            // an emitter must not write into a wrapper-owned directory. Mirrors
            // the prefix-aware preservation in the reap path.
            $exact[$canonical] = true;
            $prefixes[] = $canonical . '/';
        }

        // EVERY agent's roots, from the FULL static catalog — NOT $this->
        // agentTargets (which may be a subset under non-default construction)
        // and NOT gated on active agents.
        foreach (self::allAgentTargets() as $target) {
            foreach ($target->gitignorePatterns() as $pattern) {
                $prefixes[] = strtolower($pattern);
            }
        }

        return ['exact' => $exact, 'prefixes' => array_values(array_unique($prefixes))];
    }

    /**
     * Is this path already ignored by a dir-level managed pattern (one ending
     * in `/`)? Used to dedup per-file wrapper entries against the dir glob.
     *
     * @param  list<string>  $patterns
     */
    private function isCoveredByDirPattern(string $path, array $patterns): bool
    {
        foreach ($patterns as $pattern) {
            if ($pattern !== '' && str_ends_with($pattern, '/') && str_starts_with($path, $pattern)) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param  array{exact: array<string, true>, prefixes: list<string>}  $reserved
     */
    private function isReservedEmitterPath(string $relativePath, array $reserved): bool
    {
        // Case-fold the comparison for case-insensitive filesystems.
        $normalized = strtolower(ltrim($relativePath, '/'));
        if (isset($reserved['exact'][$normalized])) {
            return true;
        }

        foreach ($reserved['prefixes'] as $prefix) {
            if ($prefix !== '' && str_starts_with($normalized, $prefix)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Does the prior manifest own a `file` entry that is
     * the SAME physical file (inode) as $relativePath under a different path
     * spelling? On a case-insensitive filesystem an emitter that renames its
     * output by CASE only (`.Dummy/output.txt` → `.dummy/output.txt`) writes the
     * same inode; the prior entry is under the old casing so an exact
     * `has()` misses it. Matching by inode carries ownership forward to the new
     * spelling so the file is still reaped on a LATER dormant sync (rather than
     * leaking). INODE identity (not case-folding) keeps this SAFE on
     * case-sensitive filesystems: `.Dummy` and `.dummy` are distinct inodes
     * there, so a genuinely-separate operator file is never falsely claimed.
     * Where inodes are unavailable (`ino === 0`, some Windows volumes) this
     * returns false — ownership isn't transferred (benign leak, not a wrong
     * claim).
     */
    private function priorOwnsSameInode(SyncManifest $priorManifest, string $projectRoot, string $relativePath): bool
    {
        $stat = @stat($projectRoot . '/' . $relativePath);
        if ($stat === false || $stat['ino'] <= 0) {
            return false;
        }

        foreach ($priorManifest->entries as $priorPath => $entry) {
            if ($entry['category'] !== SyncManifest::CATEGORY_FILE) {
                continue;
            }

            if ($priorPath === $relativePath) {
                continue;
            }

            $priorStat = @stat($projectRoot . '/' . $priorPath);
            if ($priorStat !== false && $priorStat['ino'] === $stat['ino'] && $priorStat['dev'] === $stat['dev']) {
                return true;
            }
        }

        return false;
    }

    /**
     * Write the ownership manifest to `.boost/manifest.json`. Records
     * every emission target boost owns after this sync:
     *  - GUIDANCE files boost wrote with non-empty content (sha-gated ownership
     *    — a later sync proves ownership only if the on-disk sha still matches);
     *  - SKILL / COMMAND emission targets currently on disk (the gitignored
     *    managed files), tagged engine- or wrapper-provenance.
     *
     * Source dirs (`.ai/`, `resources/boost/`) can never appear — boost only
     * writes emission targets, and SyncManifest::withEntry() rejects source
     * prefixes defensively (the dual-role-publisher invariant).
     *
     * Record LIVE FileEmitter outputs (category `file`, provenance
     * `emitter:<fqcn>`) so a later sync can reap them when the emitter goes
     * dormant — and attribute a dormant vs DISABLED/errored emitter via the FQCN
     * in provenance. Records ONLY WROTE/UNCHANGED results that boost is allowed
     * to OWN — `$ownableEmitterPaths`, computed in runOneEmitter as "created
     * fresh OR already owned". A first-time takeover of a
     * pre-existing non-owned file, and a coincidentally-identical pre-existing
     * file, are both absent from that set → never recorded → never reaped.
     *
     * @param  list<EmitterResult>  $emitterResults
     * @param  array<string, true>  $ownableEmitterPaths
     */
    private function recordEmitterOutputs(SyncManifest $manifest, string $projectRoot, array $emitterResults, array $ownableEmitterPaths): SyncManifest
    {
        foreach ($emitterResults as $emitterResult) {
            if ($emitterResult->relativePath === null) {
                continue;
            }

            if (! in_array($emitterResult->action, [EmitterAction::WROTE, EmitterAction::UNCHANGED], true)) {
                continue;
            }

            if (! isset($ownableEmitterPaths[$emitterResult->relativePath])) {
                continue;
            }

            $sha = ManagedFileOps::fileSha($projectRoot, $emitterResult->relativePath);
            if ($sha === null) {
                continue;
            }

            $manifest = $manifest->withEntry(
                $emitterResult->relativePath,
                $sha,
                SyncManifest::CATEGORY_FILE,
                SyncManifest::PROVENANCE_EMITTER_PREFIX . $emitterResult->fqcn,
            );
        }

        return $manifest;
    }

    /**
     * @param  list<string>  $ownedGuidancePaths  relative paths from writeGuidanceFiles
     * @param  array<string, string>  $wrapperPaths  wrapper-claimed emit path => owning package
     * @param  list<EmitterResult>  $emitterResults  FileEmitter outcomes this sync
     * @param  list<string>  $retainedOrphans  reap targets whose delete FAILED — carry their PRIOR ownership forward so a later sync retries
     * @param  array<string, true>  $ownableEmitterPaths  emitter outputs boost may own
     */
    private function writeSyncManifest(string $projectRoot, array $ownedGuidancePaths, array $wrapperPaths, array $emitterResults, SyncManifest $priorManifest, array $retainedOrphans = [], array $ownableEmitterPaths = []): void
    {
        $manifest = SyncManifest::empty();

        foreach ($ownedGuidancePaths as $relativePath) {
            $sha = ManagedFileOps::fileSha($projectRoot, $relativePath);
            if ($sha !== null) {
                $manifest = $manifest->withEntry($relativePath, $sha, SyncManifest::CATEGORY_GUIDANCE, SyncManifest::PROVENANCE_ENGINE);
            }
        }

        $manifest = $this->recordEmitterOutputs($manifest, $projectRoot, $emitterResults, $ownableEmitterPaths);

        // Skill / command emission targets currently on disk. readPriorGitignore
        // here reads the JUST-WRITTEN managed block (this is the success path,
        // so updateGitignore already ran); enumerateManagedFiles skips `.boost/`.
        foreach ($this->enumerateManagedFiles($projectRoot, $this->readPriorGitignorePatterns($projectRoot)) as $relativePath) {
            $category = match (true) {
                str_contains($relativePath, '/skills/') => 'skill',
                str_contains($relativePath, '/commands/') => 'command',
                default => null,
            };
            if ($category === null) {
                continue;   // not a skill/command emission target (e.g. a manifest file) — skip
            }

            $sha = ManagedFileOps::fileSha($projectRoot, $relativePath);
            if ($sha === null) {
                continue;
            }

            // Wrapper-claimed paths carry `wrapper:<vendor/package>` provenance
            // (the owning package from WrapperEmitDiscovery) so callers can tell
            // wrappers apart; engine-native paths are `engine`.
            $provenance = isset($wrapperPaths[$relativePath])
                ? 'wrapper:' . $wrapperPaths[$relativePath]
                : SyncManifest::PROVENANCE_ENGINE;
            $manifest = $manifest->withEntry($relativePath, $sha, $category, $provenance);
        }

        // Carry forward the PRIOR ownership of any orphan whose reap delete
        // failed this run. Without this the entry would simply
        // be absent from the new manifest, dropping ownership of a file that is
        // still on disk — the next sync wouldn't know to retry. Re-add verbatim
        // from the prior manifest so the retry path stays alive.
        foreach ($retainedOrphans as $relativePath) {
            $entry = $priorManifest->entries[$relativePath] ?? null;
            if ($entry === null) {
                continue;
            }

            $manifest = $manifest->withEntry($relativePath, $entry['sha256'], $entry['category'], $entry['provenance'], $entry['scope']);
        }

        // Don't materialize `.boost/` for a project boost emits nothing into —
        // but DO update an existing manifest down to empty if everything was
        // removed (keeps it honest rather than leaving stale ownership).
        $manifestPath = $projectRoot . '/' . SyncManifest::RELATIVE_PATH;
        if ($manifest->isEmpty() && ! is_file($manifestPath)) {
            return;
        }

        $dir = $projectRoot . '/' . SyncManifest::DIR;
        if (! is_dir($dir)) {
            @mkdir($dir, 0o755, true);
        }

        // `.boost/` is ignored via the root managed .gitignore block (added in
        // updateGitignore when $willWriteManifest), so the regenerable manifest
        // never dirties the working tree. No self-contained .gitignore here —
        // that would itself be an untracked file.
        @file_put_contents($manifestPath, $manifest->toJson('boost-core'));
    }

    /**
     * @param  list<string>  $wrapperClaimedPaths  paths declared by
     *   `BoostWrapper` classes from installed wrapper packages — included in
     *   the managed `.gitignore` block so bare-CLI sync doesn't drop wrapper-
     *   emitted files from gitignore tracking (which would leak them into
     *   the operator's git working set).
     */
    private function updateGitignore(string $projectRoot, BoostConfig $config, bool $checkOnly, array $wrapperClaimedPaths = [], bool $includeManifestDir = false): ?WrittenFile
    {
        $patterns = [];
        foreach ($this->agentTargets as $target) {
            if (! $config->hasAgent($target->agent())) {
                continue;
            }

            foreach ($target->gitignorePatterns() as $pattern) {
                $patterns[] = $pattern;
            }
        }

        // The remote-skill manifest lives at the project root once any remote
        // source is declared. Keep it out of VCS so users don't fight merge
        // noise — it's auto-regenerated by every sync.
        if ($config->remoteSkills !== []) {
            $patterns[] = '/' . RemoteOrphanPruner::MANIFEST_FILE;
        }

        // Ignore the sync ownership manifest dir. Added ONLY when this
        // sync will actually write a manifest ($includeManifestDir) — so an
        // otherwise-empty project never gets a `.boost/` line for a dir that
        // won't exist. enumerateManagedFiles() skips this dir so the stale-
        // cleanup pass never deletes the manifest it relies on.
        if ($includeManifestDir) {
            $patterns[] = SyncManifest::DIR . '/';
        }

        // Wrapper-claimed paths land in the managed block so bare-CLI sync
        // doesn't silently drop them. Pattern format matches what agent targets
        // emit (no leading slash) so subsequent reads via
        // readPriorGitignorePatterns + enumerateManagedFiles produce the
        // same key form as `WrittenFile::$relativePath`. Without the form
        // match, cleanup-pass's `$writtenPaths` check misses files just
        // written by sync and falsely classifies them stale.
        //
        // Filter known guideline-file basenames (CLAUDE.md / AGENTS.md /
        // GEMINI.md): those use ManagedRegion + are operator-tracked, never
        // wholesale-replaced. Adding them to the gitignore-managed manifest
        // would route them through cleanupStaleManagedFiles, which deletes
        // the WHOLE file when stale — destroying operator-authored content
        // outside boost-core's managed markers. The wrapper contract is for
        // files that need stale-cleanup-exclusion; guideline files don't fit
        // that surface.
        foreach ($wrapperClaimedPaths as $wrapperPath) {
            $normalized = ltrim($wrapperPath, '/');
            if ($this->isGuidelineFilePath($normalized)) {
                continue;
            }

            // Dedup against dir-level patterns. The agent-target globs above
            // (e.g. `.claude/skills/`) already ignore everything beneath them,
            // so a per-file wrapper entry like `.claude/skills/foo/SKILL.md` is
            // pure bloat that re-grows the managed block on every sync (one line
            // per injected skill × every shared+dedicated root). Skip any path a
            // dir-level pattern already covers — keep the compact dir-level
            // shape.
            if ($this->isCoveredByDirPattern($normalized, $patterns)) {
                continue;
            }

            $patterns[] = $normalized;
        }

        $absolute = $projectRoot . '/.gitignore';
        $existing = is_file($absolute) ? @file_get_contents($absolute) : null;
        $existing = $existing === false ? null : $existing;

        $rendered = $this->gitignoreManager->render($existing, $patterns);
        if ($rendered === null) {
            return null;
        }

        try {
            return $this->writer->write(
                $projectRoot,
                new PendingWrite('.gitignore', $rendered),
                $checkOnly,
            );
        } catch (Throwable) {
            return null;
        }
    }

    /**
     * @return list<DiscoveredVendor>
     */
    private function discoverAllowedVendors(BoostConfig $config): array
    {
        $allowed = [];
        foreach ($this->vendorScanner->discover() as $vendor) {
            if ($config->isVendorAllowed($vendor->name)) {
                $allowed[] = $vendor;
            }
        }

        return $allowed;
    }

    /**
     * For a host skill that shadows an allowlisted vendor's skill of the
     * same name, return both source-file paths so `boost where --diff`
     * can compute a unified diff of the override against the upstream
     * copy. Returns null when:
     *
     *  - no host skill named `$skillName` exists, OR
     *  - the host skill is NOT shadowing a vendor (no allowlisted vendor
     *    publishes a skill of the same name), OR
     *  - the shadowed vendor's source file is unreadable.
     *
     * Remote skill sources are NOT considered — the shadow concept
     * applies to scanned Composer vendors (the `withAllowedVendors`
     * pipeline), not the `withRemoteSkills` cache-backed pipeline.
     *
     * @return array{hostPath: string, vendorPath: string, vendor: string}|null
     */
    public function resolveSkillShadowPaths(string $projectRoot, string $skillName): ?array
    {
        $projectRoot = rtrim($projectRoot, '/');
        $config = $this->configLoader->load($projectRoot);

        if (! is_dir($config->skillsPath)) {
            return null;
        }

        // Match what `resolveSkills()` does internally so `--diff` agrees
        // with `boost where` / `boost sync --check`: pass the configured
        // renderer dispatcher so `.blade.php`-style skills are discovered,
        // and tag-filter the vendor's skill set so a `withTags()` drop
        // doesn't leak into the diff.
        $dispatcher = new SkillRendererDispatcher($config->skillRenderers);

        $hostPath = null;
        foreach ($this->skillLoader->load($config->skillsPath, null, $dispatcher) as $hostSkill) {
            if ($hostSkill->name === $skillName) {
                $hostPath = $hostSkill->sourcePath;

                break;
            }
        }

        if ($hostPath === null) {
            return null;
        }

        $allowedVendors = $this->discoverAllowedVendors($config);
        foreach ($allowedVendors as $vendor) {
            if ($vendor->skillsPath === null) {
                continue;
            }

            $vendorSkills = [];
            foreach ($this->skillLoader->load($vendor->skillsPath, $vendor->name, $dispatcher) as $vendorSkill) {
                $vendorSkills[] = $vendorSkill;
            }

            $kept = $this->skillTagFilter->filter($vendorSkills, $config)['kept'];

            foreach ($kept as $vendorSkill) {
                if ($vendorSkill->name === $skillName) {
                    return [
                        'hostPath' => $hostPath,
                        'vendorPath' => $vendorSkill->sourcePath,
                        'vendor' => $vendor->name,
                    ];
                }
            }
        }

        return null;
    }

    /**
     * Guideline analogue of {@see resolveSkillShadowPaths()}. For a
     * host guideline that shadows a TAG-ELIGIBLE allowlisted-vendor guideline of
     * the same name, return both source-file paths so `boost where --diff` can
     * diff the override against the upstream copy. Returns null when no host
     * guideline named `$guidelineName` exists, or it isn't shadowing a
     * tag-eligible vendor guideline.
     *
     * Tag-eligibility is enforced exactly as `resolveGuidelines()` does — the
     * vendor's guidelines are tag-filtered before the name match, so a
     * tag-filtered-out vendor copy is NOT a diff target (it isn't being
     * shadowed). This keeps `--diff` consistent with what `boost where` reports.
     *
     * Returns ALL tag-eligible vendor matches (a host guideline can shadow the
     * same-named guideline from MULTIPLE allowlisted vendors) so the caller can
     * detect ambiguity — `--diff` can't pick one when more than one matches.
     * Empty list = not shadowing.
     *
     * @return list<array{hostPath: string, vendorPath: string, vendor: string}>
     */
    public function resolveGuidelineShadowPaths(string $projectRoot, string $guidelineName): array
    {
        $projectRoot = rtrim($projectRoot, '/');
        $config = $this->configLoader->load($projectRoot);

        if (! is_dir($config->guidelinesPath)) {
            return [];
        }

        $dispatcher = new SkillRendererDispatcher($config->skillRenderers);

        $hostPath = null;
        foreach ($this->guidelineLoader->load($config->guidelinesPath, null, $dispatcher) as $hostGuideline) {
            if ($hostGuideline->name === $guidelineName) {
                $hostPath = $hostGuideline->sourcePath;

                break;
            }
        }

        if ($hostPath === null) {
            return [];
        }

        /** @var list<array{hostPath: string, vendorPath: string, vendor: string}> $matches */
        $matches = [];
        $allowedVendors = $this->discoverAllowedVendors($config);
        foreach ($allowedVendors as $vendor) {
            if ($vendor->guidelinesPath === null) {
                continue;
            }

            $vendorGuidelines = [];
            foreach ($this->guidelineLoader->load($vendor->guidelinesPath, $vendor->name, $dispatcher) as $vendorGuideline) {
                $vendorGuidelines[] = $vendorGuideline;
            }

            // Tag-filter exactly as resolveGuidelines() does — only
            // tag-eligible vendor guidelines can be shadowed.
            foreach ($this->guidelineTagFilter->filter($vendorGuidelines, $config) as $vendorGuideline) {
                if ($vendorGuideline->name === $guidelineName) {
                    $matches[] = [
                        'hostPath' => $hostPath,
                        'vendorPath' => $vendorGuideline->sourcePath,
                        'vendor' => $vendor->name,
                    ];
                }
            }
        }

        return $matches;
    }

    /**
     * Load host + vendor skills, tag-filter each vendor's set, resolve
     * collisions. Vendor skills are filtered BEFORE resolution so only
     * shippable skills compete (see SkillTagFilter docblock); host skills
     * are never filtered — the project authored them.
     *
     * @param  list<DiscoveredVendor>  $allowedVendors
     * @param  array<string, list<Skill>>  $injectedVendorSkills  Caller-supplied pre-built skills keyed by source vendor. Tag-filtered before merging into the vendor map. Mirrors the remote-ingest path; see `sync()` docblock.
     * @return array{skills: list<Skill>, droppedNames: list<string>, tagFilteredCount: int, remoteErrors: list<string>, hostShadows: list<array{skill: string, shadowedVendor: string}>}  `remoteErrors` carries both per-source remote ingest failures (lenient mode) and per-file render failures. `hostShadows` records host `.ai/skills/<name>` shadowing an allowlisted-vendor skill of the same name.
     */
    private function resolveSkills(BoostConfig $config, array $allowedVendors, bool $force, array $injectedVendorSkills = [], bool $checkOnly = false): array
    {
        // Per-sync renderer dispatcher — built from the just-loaded config.
        // Cannot live as a ctor field on SyncEngine because BoostConfig is
        // only known inside sync() (lifecycle constraint).
        $dispatcher = new SkillRendererDispatcher($config->skillRenderers);

        /** @var list<string> $renderErrors */
        $renderErrors = [];

        $hostSkills = [];
        if (is_dir($config->skillsPath)) {
            foreach ($this->skillLoader->load($config->skillsPath, null, $dispatcher, $renderErrors) as $hostSkill) {
                $hostSkills[] = $hostSkill;
            }
        }

        /** @var array<string, list<Skill>> $vendorSkills */
        $vendorSkills = [];
        /** @var list<string> $droppedNames */
        $droppedNames = [];
        $tagFilteredCount = 0;
        foreach ($allowedVendors as $vendor) {
            if ($vendor->skillsPath === null) {
                continue;
            }

            // Eager-collect so the renderErrors out-param accumulates per
            // call site (Symfony Finder is lazy, but the SkillTagFilter
            // iterates the result once and the errors-out reference must
            // be wired through that iteration).
            $loaded = [];
            foreach ($this->skillLoader->load($vendor->skillsPath, $vendor->name, $dispatcher, $renderErrors) as $vendorSkill) {
                $loaded[] = $vendorSkill;
            }

            $filtered = $this->skillTagFilter->filter(
                $loaded,
                $config,
            );
            $vendorSkills[$vendor->name] = $filtered['kept'];
            foreach ($filtered['droppedNames'] as $name) {
                $droppedNames[] = $name;
            }

            // Separate accumulator for the nudge: sum per-vendor, NO
            // cross-vendor name dedup. Two vendors each dropping a
            // tag-filtered skill named `code-review` are two real hidden
            // skills, not one.
            $tagFilteredCount += $filtered['droppedByTag'];
        }

        $this->injectedVendorMerger->mergeSkills($injectedVendorSkills, $vendorSkills, $droppedNames, $tagFilteredCount, $config);

        $remote = $this->remoteCoordinator->ingestIntoVendorMap(
            $config,
            $vendorSkills,
            $droppedNames,
            $tagFilteredCount,
            $dispatcher,
            $checkOnly,
        );

        /** @var list<array{skill: string, shadowedVendor: string}> $hostShadows */
        $hostShadows = [];
        $resolvedSkillList = $this->skillResolver->resolve($hostSkills, $vendorSkills, $force, $hostShadows);

        return [
            'skills' => $resolvedSkillList,
            // Deduped — the pruner only needs each name once for lookup.
            'droppedNames' => array_values(array_unique($droppedNames)),
            // Summed — the nudge needs the real total per-vendor.
            'tagFilteredCount' => $tagFilteredCount,
            // Per-source remote ingest failures (warn-and-skip mode) + per-file render failures.
            'remoteErrors' => array_merge($remote['errors'], $renderErrors),
            // Host shadowed an allowlisted vendor skill of the same name —
            // surfaced for SyncCommand to log so consumers can audit which
            // version actually shipped.
            'hostShadows' => $hostShadows,
        ];
    }

    /**
     * Load host + vendor guidelines, tag-filter each vendor's set, resolve
     * collisions. Vendor guidelines are filtered BEFORE resolution so only
     * shippable ones compete; host guidelines are never filtered — the
     * project authored them. Mirrors `resolveSkills()`.
     *
     * @param  list<DiscoveredVendor>  $allowedVendors
     * @param  array<string, list<Guideline>>  $injectedVendorGuidelines  Caller-supplied pre-built guidelines keyed by source vendor. Tag-filtered before merging into the vendor map. Mirrors `resolveSkills()`'s injection path.
     * @param  list<string>  $renderErrors  Out-param: render failures (lenient mode) accumulate here for caller-side surfacing in SyncResult::errors.
     * @param  list<array{guideline: string, shadowedVendor: string}>  $hostGuidelineShadows  Out-param: host `.ai/guidelines/<name>` shadowing a tag-eligible allowlisted-vendor guideline of the same name. Surfaced in `boost where` / `boost sync`.
     * @return list<Guideline>
     */
    private function resolveGuidelines(BoostConfig $config, array $allowedVendors, bool $force, array $injectedVendorGuidelines = [], array &$renderErrors = [], array &$hostGuidelineShadows = []): array
    {
        $dispatcher = new SkillRendererDispatcher($config->skillRenderers);

        $hostGuidelines = [];
        if (is_dir($config->guidelinesPath)) {
            foreach ($this->guidelineLoader->load($config->guidelinesPath, null, $dispatcher, $renderErrors) as $hostGuideline) {
                $hostGuidelines[] = $hostGuideline;
            }
        }

        /** @var array<string, list<Guideline>> $vendorGuidelines */
        $vendorGuidelines = [];
        foreach ($allowedVendors as $vendor) {
            if ($vendor->guidelinesPath !== null) {
                $loaded = [];
                foreach ($this->guidelineLoader->load($vendor->guidelinesPath, $vendor->name, $dispatcher, $renderErrors) as $vendorGuideline) {
                    $loaded[] = $vendorGuideline;
                }

                $vendorGuidelines[$vendor->name] = $this->guidelineTagFilter->filter($loaded, $config);
            }
        }

        $this->injectedVendorMerger->mergeGuidelines($injectedVendorGuidelines, $vendorGuidelines, $config);

        return $this->guidelineResolver->resolve($hostGuidelines, $vendorGuidelines, $force, $hostGuidelineShadows);
    }

    /**
     * Load host commands from `.ai/commands/`. Host-only — vendor commands,
     * tag-filtering, and collision resolution are not yet supported.
     *
     * @return list<Command>
     */
    private function resolveCommands(BoostConfig $config): array
    {
        if (! is_dir($config->commandsPath)) {
            return [];
        }

        return iterator_to_array($this->commandLoader->load($config->commandsPath), false);
    }

    /**
     * @return list<EmitterResult>
     */
    /**
     * @param  array{exact: array<string, true>, prefixes: list<string>}  $reservedPaths
     * @param  list<Diagnostic>  $emitterDiagnostics  collected by-ref (reserved-path rejections + first-adoption warnings)
     * @param  array<string, true>  $ownableEmitterPaths  collected by-ref: emitter outputs boost may OWN (fresh writes or already-owned) — only these become manifest-recorded + reapable
     * @return list<EmitterResult>
     */
    private function runEmitters(
        string $projectRoot,
        BoostConfig $config,
        SyncContext $context,
        bool $checkOnly,
        SyncManifest $priorManifest,
        array $reservedPaths,
        array &$emitterDiagnostics,
        array &$ownableEmitterPaths,
    ): array {
        $allowedVendors = $config->allowedVendors;
        $discovered = $this->emitterDiscovery->discover($allowedVendors);

        /** @var list<EmitterResult> $results */
        $results = [];
        /** @var array<string, string> $claimedPaths Path → emitter FQCN, for collision detection. */
        $claimedPaths = [];

        foreach ($discovered as $emitter) {
            if ($config->isEmitterDisabled($emitter->fqcn)) {
                $results[] = new EmitterResult(
                    fqcn: $emitter->fqcn,
                    vendor: $emitter->vendor,
                    action: EmitterAction::DISABLED,
                    relativePath: null,
                    reason: 'Disabled via withDisabledEmitters() in boost.php.',
                );

                continue;
            }

            $results[] = $this->runOneEmitter($emitter, $context, $projectRoot, $checkOnly, $claimedPaths, $priorManifest, $reservedPaths, $emitterDiagnostics, $ownableEmitterPaths);
        }

        return $results;
    }

    /**
     * @param  array<string, string>  $claimedPaths
     * @param  array{exact: array<string, true>, prefixes: list<string>}  $reservedPaths
     * @param  list<Diagnostic>  $emitterDiagnostics
     * @param  array<string, true>  $ownableEmitterPaths
     */
    private function runOneEmitter(
        DiscoveredEmitter $emitter,
        SyncContext $context,
        string $projectRoot,
        bool $checkOnly,
        array &$claimedPaths,
        SyncManifest $priorManifest,
        array $reservedPaths,
        array &$emitterDiagnostics,
        array &$ownableEmitterPaths,
    ): EmitterResult {
        try {
            $emitted = $emitter->emitter->emit($context);
        } catch (Throwable $throwable) {
            return new EmitterResult(
                fqcn: $emitter->fqcn,
                vendor: $emitter->vendor,
                action: EmitterAction::ERRORED,
                relativePath: null,
                reason: $throwable->getMessage(),
            );
        }

        if (! $emitted instanceof EmittedFile) {
            return new EmitterResult(
                fqcn: $emitter->fqcn,
                vendor: $emitter->vendor,
                action: EmitterAction::SKIPPED,
                relativePath: null,
                reason: 'emit() returned null.',
            );
        }

        // Canonicalize the emitter path ONCE before every downstream use —
        // reserved-path check, collision tracking, manifest
        // recording, orphan matching. FileWriter resolves `./CLAUDE.md` and
        // `foo/./bar.md` to the same on-disk file, so without this an emitter
        // could (a) dodge the reserved-path denylist with a `.`-segment
        // spelling, and (b) make a later spelling change (`./foo.txt` →
        // `foo.txt`) look like a dormant orphan and reap the just-written live
        // file. Collapsing `.`/empty segments + normalizing separators here
        // makes the string boost stores and the string boost matches identical.
        $relativePath = ManagedFileOps::canonicalizeWrapperPath($emitted->relativePath);
        if ($relativePath === '') {
            return new EmitterResult(
                fqcn: $emitter->fqcn,
                vendor: $emitter->vendor,
                action: EmitterAction::SKIPPED,
                relativePath: $emitted->relativePath,
                reason: 'emit() returned an empty/invalid path; write skipped.',
            );
        }

        // Reserved-path denylist. An emitter must emit only to a path it alone
        // owns — never a guidance file, .gitignore, .boost/, a source dir, an
        // agent skill/command root, or a wrapper-claimed path. Reject (skip the
        // write) + surface a diagnostic; do NOT trip $hasAnyError (a misbehaving
        // emitter must not block the whole sync's reap/manifest), and never
        // track/reap the path.
        if ($this->isReservedEmitterPath($relativePath, $reservedPaths)) {
            $emitterDiagnostics[] = Diagnostic::warning(
                null,
                sprintf(
                    'Emitter %s returned reserved path "%s" (owned by boost-core or the operator); the write was skipped. Emit to a path unique to your package.',
                    $emitter->fqcn,
                    $emitted->relativePath,
                ),
            );

            return new EmitterResult(
                fqcn: $emitter->fqcn,
                vendor: $emitter->vendor,
                action: EmitterAction::SKIPPED,
                relativePath: $relativePath,
                reason: 'Reserved path — owned by boost-core or the operator; write skipped.',
            );
        }

        if (isset($claimedPaths[$relativePath])) {
            return new EmitterResult(
                fqcn: $emitter->fqcn,
                vendor: $emitter->vendor,
                action: EmitterAction::ERRORED,
                relativePath: $relativePath,
                reason: sprintf(
                    'Path "%s" already claimed by emitter %s.',
                    $relativePath,
                    $claimedPaths[$relativePath],
                ),
            );
        }

        $claimedPaths[$relativePath] = $emitter->fqcn;

        // First-adoption warn: capture pre-existence BEFORE the write so a
        // takeover of an operator file boost has no ownership record of is
        // surfaced (never silently adopted-then-later-reaped).
        $preExisted = is_file($projectRoot . '/' . $relativePath);

        try {
            $write = $this->writer->write(
                $projectRoot,
                new PendingWrite($relativePath, $emitted->content),
                $checkOnly,
            );
        } catch (Throwable $throwable) {
            return new EmitterResult(
                fqcn: $emitter->fqcn,
                vendor: $emitter->vendor,
                action: EmitterAction::ERRORED,
                relativePath: $relativePath,
                reason: $throwable->getMessage(),
            );
        }

        // Ownership gate: boost may OWN — and therefore later
        // REAP — an emitter output ONLY when it created the file fresh
        // (`! $preExisted`) or already owned it (in the prior manifest). A
        // first-time takeover of a pre-existing file boost has no record of is
        // NOT claimed: the emitter overwrote it this run (its prior content is in
        // git), but boost will never reap it on dormancy — that would delete an
        // operator file the emitter merely clobbered once. Only ownable paths are
        // recorded in the manifest (see recordEmitterOutputs).
        $ownsOutput = ! $preExisted
            || $priorManifest->has($relativePath)
            || $this->priorOwnsSameInode($priorManifest, $projectRoot, $relativePath);
        if ($ownsOutput && in_array($write->action, [WriteAction::WROTE, WriteAction::UNCHANGED], true)) {
            $ownableEmitterPaths[$relativePath] = true;
        }

        if ($write->action === WriteAction::WROTE && $preExisted && ! $priorManifest->has($relativePath)) {
            $emitterDiagnostics[] = Diagnostic::warning(
                null,
                sprintf(
                    'Emitter %s wrote over pre-existing file "%s" that boost-core has no ownership record of. The prior content is in git. boost-core will NOT manage or reap it — no ownership is claimed on a first-time takeover. To have boost own it, remove the pre-existing file before the emitter first runs.',
                    $emitter->fqcn,
                    $relativePath,
                ),
            );
        }

        return new EmitterResult(
            fqcn: $emitter->fqcn,
            vendor: $emitter->vendor,
            action: $this->mapWriteAction($write->action),
            relativePath: $write->relativePath,
            reason: null,
        );
    }

    private function mapWriteAction(WriteAction $action): EmitterAction
    {
        return match ($action) {
            WriteAction::WROTE => EmitterAction::WROTE,
            WriteAction::UNCHANGED => EmitterAction::UNCHANGED,
            WriteAction::WOULD_WRITE => EmitterAction::WOULD_WRITE,
            WriteAction::SKIPPED_SYMLINK => EmitterAction::SKIPPED,
            WriteAction::DELETED, WriteAction::WOULD_DELETE => throw new LogicException(
                'Emitter writes never produce a delete action.',
            ),
        };
    }

    /**
     * @param  list<Skill>  $skills
     * @param  list<Guideline>  $guidelines
     * @param  list<Command>  $commands
     * @param  list<string>  $droppedSkillNames  Names dropped by SkillTagFilter — candidates for pruning.
     * @return array{0: list<WrittenFile>, 1: list<string>}
     */
    /**
     * @param  list<Skill>  $skills
     * @param  list<Guideline>  $guidelines
     * @param  list<Command>  $commands
     * @param  list<string>  $droppedSkillNames
     * @param  list<string>  $guidelineRenderErrors  Render failures captured
     *         by `resolveGuidelines()`. When non-empty, the per-target
     *         guideline-file PendingWrite is skipped — preserves the prior
     *         managed-region body. Without this gate, a single failed
     *         renderer (Blade, custom, etc.) silently emits an incomplete
     *         concatenation that overwrites operator-visible content
     *         (CLAUDE.md guideline body). Matches the safety contract the
     *         clean-slate pass already gets via `$hasAnyError`.
     * @return array{0: list<WrittenFile>, 1: list<string>}
     */
    private function fanOut(
        string $projectRoot,
        BoostConfig $config,
        array $skills,
        array $guidelines,
        array $commands,
        array $droppedSkillNames,
        bool $checkOnly,
        array $guidelineRenderErrors = [],
    ): array {
        /** @var list<WrittenFile> $writes */
        $writes = [];
        /** @var list<string> $errors */
        $errors = [];

        $toPrune = $this->filteredSkillPruner->candidates($skills, $droppedSkillNames);
        $skipGuidelineWrites = $guidelineRenderErrors !== [];

        foreach ($this->agentTargets as $target) {
            if (! $config->hasAgent($target->agent())) {
                continue;
            }

            if (! $checkOnly) {
                $this->pruneDeadSymlinks($projectRoot . '/' . $target->skillsDirectoryRelative());
            }

            foreach ($toPrune as $name) {
                $pruned = $this->filteredSkillPruner->prune($projectRoot, $target, $name, $checkOnly);
                if ($pruned instanceof WrittenFile) {
                    $writes[] = $pruned;
                }
            }

            $targetGuidelineFile = $target->guidelinesFileRelative();
            foreach ($target->plan($skills, $guidelines) as $pending) {
                // Per-source render-error gate: when ANY guideline failed to
                // render, skip the concatenated guideline-file write to
                // preserve the prior managed-region body byte-for-byte.
                // Skills are emitted as individual files so a per-skill
                // render failure only affects that skill's emission — the
                // clean-slate pass's existing error gate covers their
                // preservation. Guidelines concat into one file, so a single
                // failure poisons the whole output without this gate.
                if ($skipGuidelineWrites && $targetGuidelineFile !== null && $pending->relativePath === $targetGuidelineFile) {
                    continue;
                }

                $this->writeAndPrune($projectRoot, $pending, $target, $checkOnly, $writes, $errors);
            }

            $planned = $target->planCommands($commands);
            foreach ($planned['writes'] as $pending) {
                $this->writeAndPrune($projectRoot, $pending, $target, $checkOnly, $writes, $errors);
            }

            foreach ($planned['warnings'] as $warning) {
                $errors[] = $warning;
            }
        }

        return [$writes, $errors];
    }

    /**
     * Best-effort: walk a managed agent skills dir, unlink every dead
     * symlink (link whose target no longer exists).
     *
     * Migrations that remove a previously-installed vendor (e.g. swapping
     * `sandermuller/package-boost` for the renamed `package-boost-php`)
     * leave behind dangling symlinks under `.{agent}/skills/<old-pkg>/`
     * that point into the now-absent `vendor/<old-pkg>/`. This prune treats
     * dead links in managed dirs as stale state that sync owns and cleans up.
     *
     * Live symlinks (target exists) are left alone and not recursed into,
     * so the prune is safe against link loops and against legitimate
     * symlinks the consumer may have placed there intentionally.
     */
    private function pruneDeadSymlinks(string $dir): void
    {
        if (! is_dir($dir)) {
            return;
        }

        $entries = @scandir($dir);
        if ($entries === false) {
            return;
        }

        foreach ($entries as $entry) {
            if ($entry === '.') {
                continue;
            }

            if ($entry === '..') {
                continue;
            }

            $path = $dir . '/' . $entry;

            if (is_link($path)) {
                if (! file_exists($path)) {
                    @unlink($path);
                }

                continue;
            }

            if (is_dir($path)) {
                $this->pruneDeadSymlinks($path);
            }
        }
    }

    /**
     * Write one PendingWrite and, on success, best-effort delete the obsolete
     * flat sibling left behind by an older boost-core run.
     *
     * @param  list<WrittenFile>  $writes  Mutated in place with the WrittenFile on success.
     * @param  list<string>  $errors  Mutated in place with a formatted message on failure.
     */
    private function writeAndPrune(
        string $baseDir,
        PendingWrite $pending,
        AgentTarget $target,
        bool $checkOnly,
        array &$writes,
        array &$errors,
    ): void {
        try {
            $writes[] = $this->writer->write($baseDir, $pending, $checkOnly);
            if (! $checkOnly) {
                $this->pruneLegacyFlatSibling($baseDir, $pending->relativePath);
            }
        } catch (Throwable $throwable) {
            $errors[] = sprintf(
                'Failed to write %s for %s: %s',
                $pending->relativePath,
                $target->agent()->value,
                $throwable->getMessage(),
            );
        }
    }

    /**
     * For a path ending in `/SKILL.md`, delete the obsolete flat sibling at
     * `<same-stem>.md` left behind by older boost-core runs. The structural
     * guard (`str_ends_with /SKILL.md`) is what limits scope — a guideline
     * file or non-skill output never matches, so unrelated `.md` siblings
     * are never touched.
     */
    private function pruneLegacyFlatSibling(string $baseDir, string $relativePath): void
    {
        $suffix = '/' . AgentTarget::SKILL_FILE;
        if (! str_ends_with($relativePath, $suffix)) {
            return;
        }

        $legacyRelative = substr($relativePath, 0, -strlen($suffix)) . '.md';
        @unlink($baseDir . '/' . $legacyRelative);
    }
}
