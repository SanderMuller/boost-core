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
use SanderMuller\BoostCore\Conventions\ConventionsBlockEmitter;
use SanderMuller\BoostCore\Conventions\ConventionsInliner;
use SanderMuller\BoostCore\Conventions\ConventionsPass;
use SanderMuller\BoostCore\Conventions\Diagnostic;
use SanderMuller\BoostCore\Conventions\GuidanceComposer;
use SanderMuller\BoostCore\Conventions\LeakHit;
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
     * Public so `boost doctor --check-stale-paths` (0.10.1) can surface
     * registry-tracked paths read-only without duplicating the list. Sync
     * owns deletion, doctor owns read-only reporting.
     *
     * @var list<string>
     */
    public const RETIRED_COPILOT_PATHS = [
        '.github/copilot-instructions.md', // retired 0.9.0 — Copilot reads root AGENTS.md
        '.github/skills',                  // retired 0.9.1 — Copilot reads .agents/skills via shared pool
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
     * subset (codex high: a subset-constructed engine, e.g.
     * `new SyncEngine([new ClaudeCodeTarget()])`, must still reserve
     * `.cursor/…`, `.agents/skills/`, etc. against emitter writes).
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
     * `resources/boost/skills/` — the explicit-command form of what the
     * retired Composer plugin's `runGlobalSync` did automatically on a
     * `composer global` operation. Surfaced as `boost sync --scope=user
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
     * Back-compat thin wrapper around `resolveForInspection()`. Kept so
     * 0.7.1/0.7.2 external callers (none documented; internal-facing
     * inspection API) keep working. New callers should use the broader
     * method directly.
     *
     * Preserves the 0.7.2 `scannedVendorKeys` shape — the union of every
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

        // 0.15.0 conventions inlining: build the slot inliner once + inline
        // resolved skills (vendor AND host) before fan-out. Each skill's body
        // gets its `<!--boost:conv …-->` tokens resolved to inlined values;
        // $conventionsRequiresRuntime aggregates whether ANY live skill still
        // needs the rendered `## Project Conventions` block (a legacy `$.slot`
        // ref or an unresolved/errored token), and token errors are render-class
        // (fail --check, keep the block — D7/§2).
        $conventionsPass = ConventionsPass::build($this->installedPackages, $config);
        /** @var list<Diagnostic> $conventionsDiagnostics */
        $conventionsDiagnostics = $conventionsPass->diagnostics();
        /** @var list<string> $conventionsErrors */
        $conventionsErrors = [];
        $conventionsRequiresRuntime = false;
        $conventionsInlinedAny = false;
        $resolvedSkills = $this->inlineSkillBodies($resolvedSkills, $conventionsPass->inliner(), $conventionsRequiresRuntime, $conventionsErrors, $conventionsInlinedAny);

        // 0.16.0 self-check leg: scan each rendered skill body for tokens left
        // raw (a fresh mode-B leak — born here, made positional). Warnings only:
        // the authoritative gate is $conventionsErrors (fails --check); these add
        // the file:line the bare error lacks. The guidance half runs in
        // inlineGuidanceAndGate (where the assembled body lives).
        foreach ($resolvedSkills as $skill) {
            $conventionsDiagnostics = [
                ...$conventionsDiagnostics,
                ...$this->conventionsSelfCheck($conventionsPass->inliner(), 'skill: ' . $skill->name, $skill->body),
            ];
        }

        $context = new SyncContext(
            projectRoot: $projectRoot,
            packages: $this->installedPackages,
            config: $config,
        );

        $gitignoreManaged = $config->manageGitignore && getenv(Env::SKIP_GITIGNORE) === false;

        // 0.13.0: load the PRIOR ownership manifest (state at sync start).
        // All destructive decisions (empty-guard clear, 0.14.0 orphan reap) read
        // this prior snapshot; the NEW manifest is written only after a
        // successful sync. Absent/corrupt → empty → exact pre-0.13 behavior.
        //
        // Gated on gitignore management: when it's disabled the manifest is no
        // longer UPDATED (see the write gate below), so reading a now-stale one
        // could blank/reap a file on ownership data that no longer tracks
        // reality. Disabling the READ too degrades ownership-based actions
        // cleanly to the 0.12 preserve behavior in that mode (codex-review P1).
        //
        // Read BEFORE runEmitters (0.14.0): the emitter first-adoption warn
        // consults it to detect taking over a not-yet-owned file.
        $priorManifest = $gitignoreManaged
            ? SyncManifest::fromProjectRoot($projectRoot)
            : SyncManifest::empty();

        // 0.11.0: discover wrapper-claimed paths up-front — fed to the managed
        // gitignore block, the stale-cleanup exclusion, the manifest writer, and
        // (0.14.0) the emitter reserved-path denylist so an emitter can't claim a
        // wrapper-owned path.
        $activeAgents = array_map(static fn (Agent $agent): string => $agent->value, $config->agents);
        $wrapperEmits = (new WrapperEmitDiscovery($this->installedPackages))->discover($projectRoot, $activeAgents);

        // 0.14.0: an emitter must never write to a path boost-core or the
        // operator owns (codex P1 — arbitrary emitter paths could overwrite then
        // later reap operator/core files). Build the denylist before runEmitters.
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

        // 0.14.0: derive the reap sets once — paths emitted this sync (kept),
        // FQCNs DISABLED/errored this sync (their files preserved), and whether
        // any live emitter output exists (a manifest entry → needs `.boost/`
        // gitignored even in an emitter-only project).
        [
            'intended' => $intendedEmitterPaths,
            'preserved' => $preservedEmitterFqcns,
            'hasLiveOutput' => $hasLiveEmitterOutput,
        ] = $this->emitterReapSets($emitterResults);

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

        // ($priorManifest, $wrapperEmits, $gitignoreManaged, $activeAgents are
        // computed up-front before runEmitters — see the top of sync().)

        // Will this sync write a `.boost/manifest.json`? (Manifest is non-empty
        // when boost emits guidance/skills/commands, or there's a prior to
        // refresh.) If so, `.boost/` must be in the managed .gitignore so the
        // regenerable manifest never dirties the working tree (codex-review:
        // an unignored manifest breaks clean-tree / CI checks). Computed here —
        // guidance + skills are already resolved — and passed to updateGitignore
        // so the ignore lands in the SAME write, gated to avoid adding `.boost/`
        // to an otherwise-empty project's gitignore. conventions is included
        // because it renders into CLAUDE.md (→ a manifest entry) even without
        // host guidelines.
        // Skills/guidelines/commands only EMIT (→ manifest entries) when there
        // are active agents to receive them; with no agents nothing is written
        // regardless of what resolved. Conventions render into CLAUDE.md even
        // without the Claude agent, so they count on their own. A prior manifest
        // is refreshed regardless.
        // 0.14.0: a live FileEmitter output is also a manifest entry
        // ($hasLiveEmitterOutput, derived above), so it must trigger the
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

        // 0.12.0: agent-guidance files (CLAUDE.md/AGENTS.md/GEMINI.md) are
        // written wholesale + markerless here, replacing the former per-target
        // marker-bounded guideline write + the marker-based conventions write.
        $guidanceResult = $this->writeGuidanceFiles(
            $projectRoot,
            $config,
            $resolvedGuidelines,
            $checkOnly,
            $guidelineRenderErrors !== [],
            $priorManifest,
            $conventionsPass->section(),
            $conventionsPass->inliner(),
            $conventionsRequiresRuntime,
            $conventionsInlinedAny,
        );
        $writes = [...$writes, ...$guidanceResult['writes']];
        // Token errors (render-class) from skills + guidance fail --check and
        // were already kept-the-block by the gate; surface them as errors.
        $conventionsErrors = [...$conventionsErrors, ...$guidanceResult['conventionsErrors']];

        $cleanupResult = $this->cleanupStalePaths($projectRoot, $config, $checkOnly);
        $writes = [...$writes, ...$cleanupResult['writes']];

        // Generic stale-file cleanup — 0.9.1 clean-slate model. Any file that
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

        // 0.11.0 drift-comparison wrapper-injection awareness: when a wrapper
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

        // 0.13.0: write the NEW ownership manifest LAST — only on a successful,
        // real (non-check) sync, AFTER all non-destructive writes + cleanup
        // succeeded (codex round-2 P2 ordering; codex P1.3 — never rewrite from
        // a partial/failed sync, so the prior manifest stays last-known-good).
        // Gated on gitignore management: without it nothing adds `.boost/` to
        // the ignore list, so writing the manifest would leave an untracked file
        // behind and break clean-working-tree / CI flows (codex-review P2). When
        // gitignore is unmanaged the manifest simply isn't written → ownership
        // features degrade to exact 0.12 behavior (backward-safe).
        //
        // Also skip on a guideline render failure: writeGuidanceFiles() then
        // preserves the existing guidance files + returns no ownedGuidancePaths,
        // so rewriting the manifest would DROP guidance ownership and a later
        // empty sync could no longer converge previously-owned files. Leaving
        // the prior manifest untouched keeps it last-known-good (codex-review).
        if (! $checkOnly && ! $hasAnyError && $gitignoreManaged && $guidelineRenderErrors === []) {
            // 0.14.0: reconcile-on-sync orphan reap — delete boost-owned files
            // recorded in the PRIOR manifest that this sync no longer intends to
            // emit (a dormant FileEmitter, a de-selected agent's guidance file).
            // Manifest-GATED (codex P3): the delete predicate consults the prior
            // manifest's ownership, NOT raw gitignore membership. Runs after all
            // non-destructive writes + cleanup succeeded, before the new manifest
            // is written. The reap's targets are absent from the
            // ownedGuidancePaths / live-emitter sets, so the new manifest below
            // never re-records them. ($intendedEmitterPaths /
            // $preservedEmitterFqcns derived once via emitterReapSets above.)
            $reap = $this->reapManifestOrphans(
                $projectRoot,
                $priorManifest,
                $intendedEmitterPaths,
                $preservedEmitterFqcns,
                $guidanceResult['ownedGuidancePaths'],
                $wrapperEmits['paths'],
            );
            $writes = [...$writes, ...$reap['writes']];

            // A failed orphan delete keeps its ownership (codex medium): surface
            // it and carry the entry forward so the next sync retries.
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

        // Diagnostic surface for the render-fail-then-write safety gate
        // (0.9.3): operator-visible signal that guideline writes were
        // skipped, naming each failed source so they know which renderer
        // / file to investigate.
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
     * Remove paths boost-core retired entirely — the 0.9.6 path-ownership
     * reframe (per design clarification 2026-05-29): boost-core IS the
     * owner of category-3 AI-agent paths (`.github/copilot-instructions.md`,
     * `.github/skills/`, agent-spec files, etc.). Operator-side influence
     * runs through `.ai/` sources, allowlisted vendor packages, remote
     * skills, and `boost.php` config — NOT through hand-editing emission
     * targets. When boost-core retires an emission path, the file is
     * boost-emitted output that no longer has a refresh path; delete it.
     *
     * Replaces 0.9.1-0.9.5's marker-presence guard (`<!-- boost-core:
     * guidelines:start -->`) which conflated two distinct ownership
     * questions: "what content inside the file to preserve" (ManagedRegion
     * handles correctly via the marker, still load-bearing for active
     * guideline files) vs. "whether the file should exist at all" (path-
     * ownership; this method). The marker is right for the former scope,
     * wrong for the latter — pre-0.8.2 wholesale-sync output has no
     * markers and would silently survive the marker guard despite being
     * unambiguously boost-emitted.
     *
     * Trigger conditions named explicitly per the auto-X-must-name-trigger
     * rule (codified across the 0.9.x cycle):
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

            // 0.10.2 observability: when @-suppressed fs operations leave
            // residual paths (permission denied, open file descriptor, race
            // with re-emission), the cleanup diagnostic previously claimed
            // success regardless. Operators saw "removed retired path X"
            // while `boost sync --check` immediately re-flagged drift on X,
            // with no signal what failed.
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
            'Cleanup: %s retired boost-core path `%s`. Per the path-ownership contract (0.9.6+): boost-core owns category-3 AI-agent paths end-to-end. When an emitter retires, boost-emitted output at that path no longer has a refresh path and is cleaned automatically. Operator influence runs through `.ai/` sources, allowlisted vendor packages (`withAllowedVendors`), remote skills (`withRemoteSkills`), and `boost.php` config — never via hand-editing emission targets. If you intentionally authored content at this path outside boost-core, recover from git history before next sync. 0.9.x routes Copilot to shared `.agents/skills/` + `AGENTS.md` surfaces (per GitHub Changelog 2025-08-28 for instructions and 2025-12-18 for skills).',
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

            // 0.13.0: the sync manifest dir is engine-internal state owned by
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
     * @param  array<string, string>  $wrapperExcludedPaths  0.11.0: paths
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

            // 0.11.0: wrapper-claimed paths get preserved. Wrapper-driven
            // sync rewrites them on next invocation; bare-CLI must NOT delete.
            // Both exact-file and directory-prefix match: a wrapper claim of
            // `.agents/skills/foo` (directory) should preserve every file
            // under it. Codex-review P2 pin — without prefix-match, wrapper
            // dir claims would only preserve the dir entry itself (which
            // wouldn't be in priorManagedFiles anyway) while children get
            // false-positive-deleted.
            $canonicalRelative = $this->canonicalizeWrapperPath($relativePath);
            if ($this->isUnderWrapperClaim($canonicalRelative, $wrapperExcludedPaths)) {
                continue;
            }

            $absolute = $projectRoot . '/' . $relativePath;
            if (! file_exists($absolute) && ! is_link($absolute)) {
                continue;
            }

            if (! $checkOnly) {
                @unlink($absolute);
                $this->removeEmptyParentDirs($projectRoot, $absolute);
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
     * @param  array<string, string>  $wrapperExcludedPaths
     */
    private function isUnderWrapperClaim(string $canonicalRelative, array $wrapperExcludedPaths): bool
    {
        if (isset($wrapperExcludedPaths[$canonicalRelative])) {
            return true;
        }

        // Directory-prefix match: a wrapper claim like `.agents/skills/foo`
        // should preserve `.agents/skills/foo/SKILL.md`, `.agents/skills/foo/
        // references/api.md`, etc. The trailing `/` boundary check avoids
        // false-positive matches on path-prefixes that aren't actual
        // directory ancestors (e.g., `.agents/skills/foobar` should NOT
        // match a claim of `.agents/skills/foo`).
        foreach (array_keys($wrapperExcludedPaths) as $claim) {
            if (str_starts_with($canonicalRelative, $claim . '/')) {
                return true;
            }
        }

        return false;
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
     * Mirror of WrapperEmitDiscovery's canonical form so both sides of the
     * union comparison match byte-for-byte regardless of input form.
     * Segment-based (collapses empty + `.` segments) to stay identical to
     * the discovery side, which must collapse embedded `./` for the claim
     * to match the on-disk path. priorManagedFiles paths are already clean
     * on-disk relative paths, so this is mostly a slash-normalize for them.
     */
    private function canonicalizeWrapperPath(string $raw): string
    {
        $normalized = str_replace('\\', '/', $raw);

        $out = [];
        foreach (explode('/', $normalized) as $segment) {
            if ($segment === '') {
                continue;
            }

            if ($segment === '.') {
                continue;
            }

            $out[] = $segment;
        }

        return implode('/', $out);
    }

    /**
     * Walk up from `$absolute`'s parent toward `$projectRoot`, removing
     * each directory that's now empty. Stops at the first non-empty parent
     * or at the project root (never delete that).
     */
    private function removeEmptyParentDirs(string $projectRoot, string $absolute): void
    {
        $projectRoot = rtrim($projectRoot, '/');
        $parent = dirname($absolute);
        while ($parent !== $projectRoot && str_starts_with($parent, $projectRoot . '/')) {
            $entries = @scandir($parent);
            if ($entries === false) {
                return;
            }

            $remaining = array_values(array_diff($entries, ['.', '..']));
            if ($remaining !== []) {
                return;
            }

            if (! @rmdir($parent)) {
                return;
            }

            $parent = dirname($parent);
        }
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
        // and fails on a symlink, leaving residual drift. Pre-0.9.6 boost-
        // core emitted symlinks for vendor-shipped skills (laravel/mcp's
        // .github/skills/<name> → vendor/laravel/mcp/.../skills/<name>); the
        // engine encounters them when cleaning the retired registry path.
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
     * 0.14.0: build the reserved-path denylist for FileEmitter outputs. An
     * emitter must emit only to a path it alone owns; returning any of these is
     * a contract violation (codex P1 — arbitrary emitter paths could otherwise
     * overwrite then later reap core/operator files). Exact matches: guidance
     * basenames + `.gitignore`; per-sync wrapper-claimed paths. Prefix matches:
     * source dirs + `.boost/` + EVERY agent's skill/command roots (codex high:
     * reserved regardless of which agents are active — an emitter must not write
     * into `.claude/skills/…` even when Claude is inactive, since that surface
     * may already hold tracked content and would otherwise be claimed + reaped).
     *
     * @param  list<string>  $wrapperClaimedPaths
     * @return array{exact: array<string, true>, prefixes: list<string>}
     */
    private function reservedEmitterPaths(array $wrapperClaimedPaths): array
    {
        // Keys stored LOWERCASED (codex high): on a case-insensitive filesystem
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
            $canonical = strtolower($this->canonicalizeWrapperPath($wrapperPath));
            // Exact match (file claim) AND a descendant prefix (codex high): a
            // wrapper DIRECTORY claim like `.agents/skills/foo` owns its whole
            // subtree, so `.agents/skills/foo/SKILL.md` must also be reserved —
            // an emitter must not write into a wrapper-owned directory. Mirrors
            // the prefix-aware preservation in the reap path.
            $exact[$canonical] = true;
            $prefixes[] = $canonical . '/';
        }

        // EVERY agent's roots, from the FULL static catalog — NOT $this->
        // agentTargets (which may be a subset under non-default construction)
        // and NOT gated on active agents (codex high ×2).
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
        // Case-fold the comparison (codex high — case-insensitive filesystems).
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
     * 0.14.0: derive the FileEmitter reap inputs from this sync's results.
     *  - `intended`: paths emitted this sync (WROTE/UNCHANGED/WOULD_WRITE) —
     *    kept, never reaped;
     *  - `preserved`: FQCNs DISABLED or errored this sync — their prior output is
     *    preserved (disabling ≠ teardown; errored ≠ dormant);
     *  - `hasLiveOutput`: any real on-disk emitter output (WROTE/UNCHANGED) — a
     *    manifest entry, so it forces the `.boost/` gitignore line on its own.
     *
     * @param  list<EmitterResult>  $emitterResults
     * @return array{intended: array<string, true>, preserved: array<string, true>, hasLiveOutput: bool}
     */
    private function emitterReapSets(array $emitterResults): array
    {
        $intended = [];
        $preserved = [];
        $hasLiveOutput = false;

        foreach ($emitterResults as $emitterResult) {
            if (
                $emitterResult->relativePath !== null
                && in_array($emitterResult->action, [EmitterAction::WROTE, EmitterAction::UNCHANGED, EmitterAction::WOULD_WRITE], true)
            ) {
                $intended[$emitterResult->relativePath] = true;
            }

            if (
                $emitterResult->relativePath !== null
                && in_array($emitterResult->action, [EmitterAction::WROTE, EmitterAction::UNCHANGED], true)
            ) {
                $hasLiveOutput = true;
            }

            if (in_array($emitterResult->action, [EmitterAction::DISABLED, EmitterAction::ERRORED], true)) {
                $preserved[$emitterResult->fqcn] = true;
            }
        }

        return ['intended' => $intended, 'preserved' => $preserved, 'hasLiveOutput' => $hasLiveOutput];
    }

    /**
     * 0.14.0 (codex high): does the prior manifest own a `file` entry that is
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
     * 0.14.0: reconcile-on-sync orphan reap. Delete boost-owned files recorded
     * in the PRIOR manifest that this sync no longer intends to emit — covering
     * two instances of one shape:
     *  - a dormant FileEmitter's output (category `file`, provenance
     *    `emitter:<fqcn>`): reaped unless the producing emitter was DISABLED or
     *    errored this sync (preserve — disabling means "stop regenerating", not
     *    "delete"), or the path is wrapper-claimed;
     *  - a de-selected agent's guidance file (category `guidance`, engine
     *    provenance): reaped IFF still boost-owned (on-disk sha == recorded sha;
     *    a divergence means the operator hand-edited it → preserve, never-lossy).
     *
     * Manifest-GATED by construction (codex P3): the delete predicate consults
     * the prior manifest's ownership, not raw gitignore membership. Only called
     * on a successful, real (non-check) sync, after non-destructive writes.
     *
     * @param  array<string, true>  $intendedEmitterPaths  emitter paths emitted this sync (kept)
     * @param  array<string, true>  $preservedEmitterFqcns  FQCNs DISABLED/errored this sync (files preserved)
     * @param  list<string>  $ownedGuidancePaths  guidance files boost owns this sync (kept)
     * @param  array<string, string>  $wrapperPaths  wrapper-claimed paths (never reaped here)
     * @return array{writes: list<WrittenFile>, retained: list<string>}  `retained` = orphans whose delete FAILED — ownership is kept so the next sync retries (codex medium)
     */
    private function reapManifestOrphans(
        string $projectRoot,
        SyncManifest $priorManifest,
        array $intendedEmitterPaths,
        array $preservedEmitterFqcns,
        array $ownedGuidancePaths,
        array $wrapperPaths,
    ): array {
        $intendedGuidance = array_fill_keys($ownedGuidancePaths, true);

        /** @var list<string> $reaps */
        $reaps = [];
        foreach ($priorManifest->entries as $relativePath => $entry) {
            if ($this->isReapableOrphan($projectRoot, $priorManifest, $relativePath, $entry, $intendedEmitterPaths, $preservedEmitterFqcns, $intendedGuidance, $wrapperPaths)) {
                $reaps[] = $relativePath;
            }
        }

        // 0.14.0 (codex high): identify files boost wrote/kept LIVE this sync by
        // INODE. On a case-insensitive filesystem an emitter that renames its
        // output by CASE only (`.Dummy/output.txt` → `.dummy/output.txt`) leaves
        // a prior manifest entry under the old spelling that string-matches as an
        // orphan — but it is the SAME on-disk file (inode) as the just-written
        // live output. Inode is the reliable alias test (macOS `realpath()`
        // preserves the queried casing, so it does NOT collapse case): same
        // dev:ino ⇒ same file ⇒ never unlink it; distinct inodes (case-sensitive
        // FS) ⇒ a genuine orphan ⇒ reap (no leak). Where PHP can't read inodes
        // (`ino === 0`, some Windows volumes) fall back to a case-folded path
        // match — preserve rather than risk deleting an aliased live file.
        $liveInodes = [];
        $liveLowerPaths = [];
        foreach ([...array_keys($intendedEmitterPaths), ...$ownedGuidancePaths] as $livePath) {
            $liveAbsolute = $projectRoot . '/' . $livePath;
            $stat = @stat($liveAbsolute);
            if ($stat !== false && $stat['ino'] > 0) {
                $liveInodes[$stat['dev'] . ':' . $stat['ino']] = true;
            } else {
                $liveLowerPaths[strtolower($liveAbsolute)] = true;
            }
        }

        $writes = [];
        /** @var list<string> $retained */
        $retained = [];
        foreach ($reaps as $relativePath) {
            $absolute = $projectRoot . '/' . $relativePath;
            // Reap ONLY a regular file (codex high): boost's guidance/emitter
            // outputs are always plain files. If the operator has since
            // replaced the path with a directory tree or a symlink, that is
            // THEIR content — never recurse into it or unlink it, and drop
            // ownership (don't retain). (is_link first — is_file() follows
            // symlinks.)
            if (is_link($absolute)) {
                continue;
            }

            if (! is_file($absolute)) {
                continue;
            }

            // Never unlink a path that aliases a live output (codex high).
            $stat = @stat($absolute);
            if ($stat !== false && $stat['ino'] > 0) {
                if (isset($liveInodes[$stat['dev'] . ':' . $stat['ino']])) {
                    continue;
                }
            } elseif (isset($liveLowerPaths[strtolower($absolute)])) {
                continue;
            }

            if (@unlink($absolute)) {
                $this->removeEmptyParentDirs($projectRoot, $absolute);
                $writes[] = new WrittenFile($relativePath, $absolute, WriteAction::DELETED);

                continue;
            }

            // Delete FAILED (codex medium): a transient permission/filesystem
            // error must NOT silently drop the ownership record — that would
            // leak the stale file forever (the next sync wouldn't know to retry).
            // Retain it so writeSyncManifest carries the entry forward.
            $retained[] = $relativePath;
        }

        return ['writes' => $writes, 'retained' => $retained];
    }

    /**
     * Decide whether a single prior-manifest entry is a reapable orphan
     * (extracted from reapManifestOrphans for cognitive-complexity).
     *  - emitter `file`: orphan unless still emitted, wrapper-claimed, or its
     *    producing emitter was DISABLED/errored this sync (preserved); reaped
     *    only when the on-disk sha still matches the recorded sha (operator
     *    hand-edited → preserve, never-lossy);
     *  - engine `guidance`: orphan unless still scheduled; reap only when the
     *    on-disk sha still matches (operator-edited → preserve, never-lossy).
     *
     * @param  array{sha256: string, category: string, provenance: string, scope: string}  $entry
     * @param  array<string, true>  $intendedEmitterPaths
     * @param  array<string, true>  $preservedEmitterFqcns
     * @param  array<string, true>  $intendedGuidance
     * @param  array<string, string>  $wrapperPaths
     */
    private function isReapableOrphan(
        string $projectRoot,
        SyncManifest $priorManifest,
        string $relativePath,
        array $entry,
        array $intendedEmitterPaths,
        array $preservedEmitterFqcns,
        array $intendedGuidance,
        array $wrapperPaths,
    ): bool {
        $category = $entry['category'];
        $provenance = $entry['provenance'];

        if ($category === SyncManifest::CATEGORY_FILE && str_starts_with($provenance, SyncManifest::PROVENANCE_EMITTER_PREFIX)) {
            // Wrapper preservation must be PREFIX-aware (codex high): a wrapper
            // DIRECTORY claim (`.agents/skills/foo`) owns its whole subtree, so
            // a path under it is never reaped here — mirrors the prefix logic
            // cleanupStaleManagedFiles already uses (exact-match would leak a
            // delete on descendants).
            if (isset($intendedEmitterPaths[$relativePath]) || $this->isUnderWrapperClaim($this->canonicalizeWrapperPath($relativePath), $wrapperPaths)) {
                return false;
            }

            $fqcn = substr($provenance, strlen(SyncManifest::PROVENANCE_EMITTER_PREFIX));
            if (isset($preservedEmitterFqcns[$fqcn])) {
                return false;
            }

            // sha-revalidation (codex high — never-lossy): an emitter output the
            // operator hand-edited after boost wrote it (e.g. a tweaked
            // `.mcp.json`) must NOT be deleted on dormancy. Reap ONLY when the
            // on-disk content still matches what boost recorded — a divergence
            // means the operator took it over → preserve. Mirrors the guidance
            // ownership gate; emitter outputs are operator-editable too.
            $currentSha = $this->fileSha($projectRoot, $relativePath);

            return $currentSha !== null && $currentSha === $entry['sha256'];
        }

        if ($category === SyncManifest::CATEGORY_GUIDANCE && $provenance === SyncManifest::PROVENANCE_ENGINE) {
            if (isset($intendedGuidance[$relativePath])) {
                return false;
            }

            $currentSha = $this->fileSha($projectRoot, $relativePath);

            return $currentSha !== null && $priorManifest->ownsGuidance($relativePath, $currentSha);
        }

        return false;
    }

    /**
     * 0.12.0: write the agent-guidance files (CLAUDE.md / AGENTS.md /
     * GEMINI.md) wholesale + markerless. Consolidates the former per-target
     * guideline write + the marker-based conventions write into ONE wholesale
     * path per file.
     *
     * Conventions render markerless into CLAUDE.md (from `boost.php`'s
     * `->withConventions([...])`); guidelines render markerless into each
     * guidance file. `GuidanceComposer` migrates legacy marker-bounded files:
     * it strips the `boost-core:guidelines:*` + `boost-core:conventions:*`
     * marker regions, silently absorbs out-of-marker content that duplicates
     * the rendered body (the stale-inline-copy case — solves #79), and
     * preserves genuine operator content below the wholesale body once with a
     * warning pointing at `.ai/guidelines/`.
     *
     * Safety: the un-migrated conventions case (filled conventions YAML in
     * CLAUDE.md markers, no `->withConventions()` in `boost.php`) is handled
     * by the generic migration — the stripped YAML becomes non-duplicate
     * residual, preserved below with the warning, so no values are lost. The
     * warning names `convert-conventions` when the residual looks like a
     * conventions block.
     *
     * Each unique guidance file is written ONCE even when multiple agents
     * share it (e.g. the `AGENTS.md` shared pool), so the migration never
     * sees a half-written file from an earlier same-sync write.
     *
     * @param  list<Guideline>  $resolvedGuidelines
     * @param  SyncManifest  $priorManifest  ownership state at sync start (0.13.0).
     *   When it proves boost owns a markerless guidance file (listed + sha-match),
     *   the empty-assembly guard CLEARS it (converge); otherwise the file is
     *   preserved (the 0.12 never-lossy default for operator / unproven files).
     * @return array{writes: list<WrittenFile>, diagnostics: list<Diagnostic>, ownedGuidancePaths: list<string>}
     *   `ownedGuidancePaths` = relative paths boost wrote with NON-empty content
     *   this sync (boost-owned guidance), for recording in the new manifest so a
     *   later empty sync can prove ownership + converge. Excludes preserved
     *   operator files and empty/cleared files.
     */
    /**
     * 0.15.0: inline convention slot tokens into each skill body (vendor AND
     * host) before fan-out. Returns the rewritten skills; sets $requiresRuntime
     * true if ANY skill still needs the rendered block (legacy `$.slot` ref or
     * an unresolved/errored token), and accumulates render-class token errors.
     *
     * 0.16.0 self-check: warning-level diagnostics for any conventions token left
     * RAW in a freshly-rendered body (a mode-B leak born this sync), made
     * positional with a `<label>:<line>` locator the bare $conventionsErrors
     * string lacks. Reuses {@see ConventionsInliner::scanLeaks()} so the
     * sync-time, doctor, and validate legs classify identically. Warnings only —
     * the authoritative gate stays $conventionsErrors (which fails `--check`);
     * these just say WHERE. On a healthy sync (every token resolves) it emits
     * nothing.
     *
     * @return list<Diagnostic>
     */
    private function conventionsSelfCheck(ConventionsInliner $inliner, string $label, string $body): array
    {
        $out = [];
        foreach ($inliner->scanLeaks($body) as $hit) {
            $where = $label . ':' . $hit->line;
            $out[] = Diagnostic::warning($hit->path, $hit->kind === LeakHit::KIND_FENCE_OPENER
                ? sprintf('unprocessed `boost:conv` fence at %s', $where)
                : sprintf('conventions token%s left raw at %s', $hit->path !== null ? sprintf(' "%s"', $hit->path) : '', $where));
        }

        return $out;
    }

    /**
     * @param  list<Skill>  $skills
     * @param  list<string>  $errors
     * @return list<Skill>
     */
    private function inlineSkillBodies(array $skills, ConventionsInliner $inliner, bool &$requiresRuntime, array &$errors, bool &$anyInlined): array
    {
        $out = [];
        foreach ($skills as $skill) {
            $result = $inliner->inline($skill->body);
            if ($result->requiresRuntimeConventions) {
                $requiresRuntime = true;
            }

            if ($result->inlinedAny) {
                $anyInlined = true;
            }

            $errors = [...$errors, ...$result->errors];
            $out[] = $skill->withBody($result->body);
        }

        return $out;
    }

    /**
     * Remove boost's OWN rendered `## Project Conventions` block (heading + the
     * following ```yaml fence) from content, so the drop-gate's existing-content
     * scan doesn't treat boost's own prior render as a dependency (codex round-3
     * — that would make the block undroppable). Operator prose pointers ("the
     * conventions section above") and residual refs/tokens elsewhere survive.
     */
    private function withoutRenderedConventionsBlock(string $content): string
    {
        return (string) preg_replace('/##\s+Project Conventions\s*\R+```yaml\R.*?\R```[ \t]*\R?/su', '', $content);
    }

    /**
     * The portion of an existing guidance file that will SURVIVE this sync —
     * the only content the drop gate should scan for a conventions dependency
     * (codex round-5). Keys on OWNERSHIP, not just marker presence:
     *  - boost does NOT own the file (operator-authored / sha-diverged) → it is
     *    preserved wholesale → scan all of it (minus boost's own rendered block);
     *  - boost OWNS it (regenerated wholesale): a markerless owned file's prior
     *    body does NOT survive → scan nothing; a legacy marker-bearing owned file
     *    keeps only its OUT-OF-MARKER residual → scan that.
     * This drops the over-keep stickiness for owned-markerless guidance without
     * the wrong-drop codex's blanket "markerless → skip" would cause for an
     * operator-owned file that still carries a live conventions dependency.
     */
    private function survivingGuidanceForGate(string $content, bool $boostOwns): string
    {
        if (! $boostOwns) {
            return $this->withoutRenderedConventionsBlock($content);
        }

        if (! str_contains($content, '<!-- boost-core:')) {
            return '';
        }

        $residual = (string) preg_replace('/<!-- boost-core:[a-z]+:start -->.*?<!-- boost-core:[a-z]+:end -->/su', '', $content);

        return $this->withoutRenderedConventionsBlock($residual);
    }

    /**
     * 0.15.0: inline slot tokens into each guidance body + decide the conventions
     * block drop gate (§2). The block is KEPT when ANY live artifact still needs
     * it: a skill (skillRequiresRuntime), a guidance body with a legacy `$.slot`
     * ref / unresolved token, or a guidance body that still POINTS at the section
     * ("Project Conventions" heading-relative prose — §3b, conservative keep).
     * Only when everything is provably token-only does the block drop
     * (effectiveSection = null).
     *
     * @param  array<string, array{body: string, isClaude: bool}>  $guidanceFiles
     * @param  list<string>  $conventionsErrors
     * @return array{files: array<string, array{body: string, isClaude: bool}>, section: ?string, selfCheck: list<Diagnostic>}
     */
    private function inlineGuidanceAndGate(string $projectRoot, array $guidanceFiles, ConventionsInliner $inliner, bool $skillRequiresRuntime, bool $skillInlinedAny, ?string $conventionsSection, array &$conventionsErrors, SyncManifest $priorManifest): array
    {
        $guidanceRequiresRuntime = false;
        $anyInlined = $skillInlinedAny;
        /** @var list<Diagnostic> $selfCheck */
        $selfCheck = [];
        foreach ($guidanceFiles as $file => $info) {
            $result = $inliner->inline($info['body']);
            $guidanceFiles[$file]['body'] = $result->body;
            $conventionsErrors = [...$conventionsErrors, ...$result->errors];
            $selfCheck = [...$selfCheck, ...$this->conventionsSelfCheck($inliner, $file, $result->body)];
            if ($result->inlinedAny) {
                $anyInlined = true;
            }

            // KEEP the block if the emitted body needs runtime resolution OR
            // depends on the section by prose pointer (codex P1.2 — broad
            // heading-relative match, not an exact string) OR the EXISTING
            // on-disk content (which migrate() may preserve as residual) carries
            // a legacy ref / pointer (codex P1.1 — gate must see post-migration
            // content). Fail toward keep.
            // Scan only the content that will SURVIVE this sync (codex round-5):
            // a file boost owns is regenerated (scan its surviving residual, or
            // nothing for owned-markerless); a file boost doesn't own is
            // preserved wholesale (scan it). Strips boost's own rendered block
            // either way (codex round-3 — its heading must not self-perpetuate).
            $existing = is_file($projectRoot . '/' . $file) ? (string) @file_get_contents($projectRoot . '/' . $file) : '';
            $boostOwns = $existing !== '' && $priorManifest->ownsGuidance($file, hash('sha256', $existing));
            $existingResidual = $this->survivingGuidanceForGate($existing, $boostOwns);
            if ($result->requiresRuntimeConventions
                || $inliner->dependsOnConventions($result->body)
                || $inliner->dependsOnConventions($existingResidual)
            ) {
                $guidanceRequiresRuntime = true;
            }
        }

        // Drop the block ONLY on positive proof of full migration: conventions
        // are declared (section non-null), at least one token was actually
        // inlined this sync, nothing still needs the runtime block, and no token
        // errored. Otherwise KEEP it — a pure-conventions project with no token
        // skills (nothing inlined) renders the block exactly as pre-0.15
        // (backward-safe), and any uncertainty fails toward keep.
        $fullyMigrated = $anyInlined && ! $skillRequiresRuntime && ! $guidanceRequiresRuntime && $conventionsErrors === [];
        $effectiveSection = ($conventionsSection !== null && ! $fullyMigrated) ? $conventionsSection : null;

        return ['files' => $guidanceFiles, 'section' => $effectiveSection, 'selfCheck' => $selfCheck];
    }

    /**
     * @param  list<Guideline>  $resolvedGuidelines
     * @return array{writes: list<WrittenFile>, diagnostics: list<Diagnostic>, ownedGuidancePaths: list<string>, conventionsErrors: list<string>}
     */
    private function writeGuidanceFiles(string $projectRoot, BoostConfig $config, array $resolvedGuidelines, bool $checkOnly, bool $skipGuidelineWrites, SyncManifest $priorManifest, ?string $conventionsSection, ConventionsInliner $inliner, bool $skillRequiresRuntime, bool $skillInlinedAny): array
    {
        /** @var list<WrittenFile> $writes */
        $writes = [];
        /** @var list<Diagnostic> $diagnostics */
        $diagnostics = [];
        /** @var list<string> $ownedGuidancePaths */
        $ownedGuidancePaths = [];
        /** @var list<string> $conventionsErrors */
        $conventionsErrors = [];

        $composer = new GuidanceComposer();

        // Conventions discovery + the inliner are built once in sync()
        // (conventionsContext()) and passed in: $conventionsSection is the
        // prebuilt rendered block, $inliner is shared with the skill pass.

        if ($skipGuidelineWrites) {
            // A guideline render failed. Conventions + guidelines now assemble
            // into ONE wholesale file, so the render-fail safety gate (preserve
            // the prior guidance file byte-for-byte) skips the whole guidance
            // write — including the conventions section. This is a deliberate
            // trade-off of the unified markerless write: while a renderer is
            // broken, the guidance file (conventions + guidelines) holds at its
            // prior state. It is a TRANSIENT degraded window — once the operator
            // fixes the failing renderer, the next sync re-renders conventions +
            // guidelines together. Preserving everything is safer than writing a
            // partial file that drops the failed guideline's content.
            //
            // No ownedGuidancePaths reported on this gate — the render failed,
            // so the manifest write is skipped at the call site anyway (the
            // prior manifest stays last-known-good).
            return ['writes' => $writes, 'diagnostics' => $diagnostics, 'ownedGuidancePaths' => [], 'conventionsErrors' => $conventionsErrors];
        }

        $guidanceFiles = $this->collectGuidanceFiles($config, $resolvedGuidelines, $conventionsSection);

        // 0.15.0: inline slot tokens into each guidance body + decide the drop
        // gate (extracted to keep this method under the complexity budget).
        ['files' => $guidanceFiles, 'section' => $effectiveSection, 'selfCheck' => $guidanceSelfCheck] = $this->inlineGuidanceAndGate(
            $projectRoot,
            $guidanceFiles,
            $inliner,
            $skillRequiresRuntime,
            $skillInlinedAny,
            $conventionsSection,
            $conventionsErrors,
            $priorManifest,
        );
        $diagnostics = [...$diagnostics, ...$guidanceSelfCheck];

        foreach ($guidanceFiles as $file => $info) {
            $assembled = $composer->assemble($info['isClaude'] ? $effectiveSection : null, $info['body']);
            $absolute = $projectRoot . '/' . $file;
            $existing = is_file($absolute) ? @file_get_contents($absolute) : null;
            $existing = $existing === false ? null : $existing;

            // Empty-assembly guard (0.12.0): boost produced NO guidance content
            // this sync (no resolved guidelines + no conventions). The
            // markerless model is stateless — for a MARKERLESS file it cannot
            // tell a boost-owned file that should now go empty from a NEW
            // adopter's pre-existing CLAUDE.md (laravel/boost's `boost install`
            // writes one; many repos hand-author one) that boost-core has never
            // synced. Wholesale-writing the empty assembly would WIPE that file
            // — and via BoostAutoSync this can fire on a routine
            // `composer update` without the operator watching. So: never blank a
            // non-empty MARKERLESS guidance file (left untouched + recoverable;
            // an operator who genuinely wants it empty deletes it manually).
            //
            // A file still carrying boost MARKERS is exempt from the guard: the
            // markers prove boost wrote it, so it falls through to migrate(),
            // which strips the markers (converging it to markerless) and
            // preserves any genuine out-of-marker residual — never a wipe. That
            // keeps the legacy-marker upgrade path converging even when the
            // resolved guidance set is empty (codex-review: stale instructions
            // must not linger in a boost-owned file).
            //
            // 0.13.0 — the manifest resolves the 0.12 trade-off. A marker file
            // is exempt (markers prove authorship → falls through to migrate()).
            // For a MARKERLESS non-empty file under empty assembly, consult the
            // PRIOR manifest: if it proves boost owns this exact file (listed +
            // sha-match — i.e. unchanged since boost wrote it), CLEAR it
            // (converge — the "synced then removed all guidance" case now
            // converges correctly). Otherwise PRESERVE: operator-authored, or
            // sha-diverged (operator hand-edited), or no manifest yet (cold
            // start / pre-0.13) — the never-lossy default. The clear is gated
            // on the prior manifest only; the new manifest is written after a
            // successful sync, so the first 0.13 sync can never promote a
            // pre-existing file to owned mid-run and wipe it (codex P1.1).
            if ($assembled === '' && ! ($existing !== null && $composer->hasManagedMarkers($existing))) {
                $ownedByManifest = $existing !== null
                    && trim($existing) !== ''
                    && $priorManifest->ownsGuidance($file, hash('sha256', $existing));

                if (! $ownedByManifest) {
                    $diagnostics = $this->noteGuidanceLeftIntact($diagnostics, $file, $existing);

                    continue;
                }

                // boost-owned markerless file → fall through; migrate(existing,
                // '') returns '' → writes empty → converges to empty.
            }

            $migration = $composer->migrate($existing, $assembled);

            // Warn only on the actual marker→markerless transition with genuine
            // content (not on steady-state syncs).
            if ($migration['migrated'] && $migration['residual'] !== null) {
                $diagnostics[] = Diagnostic::warning(null, $this->guidanceReplacedMessage($file, $migration['residual']));
            }

            $written = $this->writer->write($projectRoot, new PendingWrite($file, $migration['content']), $checkOnly);
            if ($written->action !== WriteAction::UNCHANGED) {
                $writes[] = $written;
            }

            // Record boost-owned guidance for the manifest, but ONLY when boost
            // can actually prove ownership: either boost WROTE the file this run
            // (action != UNCHANGED → it's boost's fresh output), OR it was
            // UNCHANGED and the prior manifest still owns this EXACT content
            // (sha-match — genuine steady-state boost output).
            //
            // Crucially, an UNCHANGED file is re-claimed ONLY on a sha-match, not
            // mere presence (codex-review): a file boost once owned but the
            // operator later hand-edited has a DIVERGED prior sha; if a later
            // assembly happens to coincide with the operator's edit byte-for-
            // byte, `has()` alone would wrongly re-claim it → a subsequent empty
            // sync could blank an operator-edited file. Empty content is never
            // owned. (A NOT-listed UNCHANGED file is the first-sync coincidence
            // case — also not claimed.)
            $boostProvesOwnership = $written->action !== WriteAction::UNCHANGED
                || $priorManifest->ownsGuidance($file, hash('sha256', $migration['content']));
            if (trim($migration['content']) !== '' && $boostProvesOwnership) {
                $ownedGuidancePaths[] = $file;
            }
        }

        return ['writes' => $writes, 'diagnostics' => $diagnostics, 'ownedGuidancePaths' => $ownedGuidancePaths, 'conventionsErrors' => $conventionsErrors];
    }

    /**
     * Write the 0.13.0 ownership manifest to `.boost/manifest.json`. Records
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
     * 0.14.0: record LIVE FileEmitter outputs (category `file`, provenance
     * `emitter:<fqcn>`) so a later sync can reap them when the emitter goes
     * dormant — and attribute a dormant vs DISABLED/errored emitter via the FQCN
     * in provenance. Records ONLY WROTE/UNCHANGED results that boost is allowed
     * to OWN — `$ownableEmitterPaths`, computed in runOneEmitter as "created
     * fresh OR already owned" (codex high). A first-time takeover of a
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

            $sha = $this->fileSha($projectRoot, $emitterResult->relativePath);
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
     * @param  list<string>  $retainedOrphans  reap targets whose delete FAILED — carry their PRIOR ownership forward so a later sync retries (codex medium)
     * @param  array<string, true>  $ownableEmitterPaths  emitter outputs boost may own (codex high)
     */
    private function writeSyncManifest(string $projectRoot, array $ownedGuidancePaths, array $wrapperPaths, array $emitterResults, SyncManifest $priorManifest, array $retainedOrphans = [], array $ownableEmitterPaths = []): void
    {
        $manifest = SyncManifest::empty();

        foreach ($ownedGuidancePaths as $relativePath) {
            $sha = $this->fileSha($projectRoot, $relativePath);
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

            $sha = $this->fileSha($projectRoot, $relativePath);
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

        // 0.14.0 (codex medium): carry forward the PRIOR ownership of any orphan
        // whose reap delete failed this run. Without this the entry would simply
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

    private function fileSha(string $projectRoot, string $relativePath): ?string
    {
        $absolute = $projectRoot . '/' . $relativePath;
        $content = is_file($absolute) ? @file_get_contents($absolute) : false;

        return $content === false ? null : hash('sha256', $content);
    }

    /**
     * Warning fired once on the marker→markerless transition when a guidance
     * file carried genuine non-boost content. The content is PRESERVED below
     * the generated output (never deleted); the warning points the operator at
     * the durable home (`.ai/guidelines/`), and — when the content looks like a
     * legacy Project Conventions YAML stub — at `boost.php`'s ->withConventions.
     *
     * NB: it does NOT name `convert-conventions`. That command requires the
     * `boost-core:conventions:*` markers to read the legacy block, and THIS
     * sync already stripped them as part of the markerless migration — so the
     * advice would be dead-on-arrival (the command short-circuits with "no
     * marker region found"). The operator copies the preserved YAML values into
     * boost.php by hand instead.
     */
    private function guidanceReplacedMessage(string $file, string $residual): string
    {
        $looksLikeConventions = str_contains($residual, 'schema-version')
            || str_contains($residual, ConventionsBlockEmitter::H2_HEADING);

        $tail = $looksLikeConventions
            ? " If this is the legacy Project Conventions YAML, copy its slot values into `boost.php`'s ->withConventions([...]) chain (the conventions markers are gone, so `convert-conventions` no longer applies); otherwise move it into `.ai/guidelines/`."
            : ' Move it into `.ai/guidelines/` so boost-core assembles it into the guidance file on every sync.';

        return sprintf(
            "0.12.0 markerless migration: `%s` is now wholesale-owned by boost-core (no markers). Content found outside boost-core's generated output has been preserved below it.%s",
            $file,
            $tail,
        );
    }

    /**
     * Collect each unique guidance file to write, keyed by relative path. The
     * first active target owning a file defines its guideline body formatting
     * (shared-pool agents use the default formatter, so this is deterministic).
     *
     * Conventions render into CLAUDE.md specifically (its canonical home —
     * convert-conventions / validate all key off it). Pre-0.12
     * syncConventions() wrote CLAUDE.md whenever conventions were declared,
     * INDEPENDENT of which agents were enabled. Preserve that: if there is a
     * conventions section but the Claude agent isn't active (e.g. a Codex /
     * Copilot / Gemini-only project that still declares ->withConventions([...]),
     * schedule CLAUDE.md anyway with an empty guideline body so the conventions
     * don't silently vanish (codex-review regression fix). assemble() then
     * writes a conventions-only CLAUDE.md, matching the prior behavior.
     *
     * @param  list<Guideline>  $resolvedGuidelines
     * @return array<string, array{body: string, isClaude: bool}>
     */
    private function collectGuidanceFiles(BoostConfig $config, array $resolvedGuidelines, ?string $conventionsSection): array
    {
        $guidanceFiles = [];
        foreach ($this->agentTargets as $target) {
            if (! $config->hasAgent($target->agent())) {
                continue;
            }

            $file = $target->guidelinesFileRelative();
            if ($file === null) {
                continue;
            }

            if (isset($guidanceFiles[$file])) {
                continue;
            }

            $guidanceFiles[$file] = [
                'body' => $resolvedGuidelines === [] ? '' : $target->formatGuidelinesContent($resolvedGuidelines),
                'isClaude' => $file === 'CLAUDE.md',
            ];
        }

        if ($conventionsSection !== null && ! isset($guidanceFiles['CLAUDE.md'])) {
            $guidanceFiles['CLAUDE.md'] = ['body' => '', 'isClaude' => true];
        }

        return $guidanceFiles;
    }

    /**
     * Empty-assembly guard bookkeeping: when the existing guidance file is
     * non-empty, append the INFO recording that it was LEFT INTACT (the caller
     * skips the write). Returns the possibly-extended diagnostics list.
     *
     * @param  list<Diagnostic>  $diagnostics
     * @return list<Diagnostic>
     */
    private function noteGuidanceLeftIntact(array $diagnostics, string $file, ?string $existing): array
    {
        if ($existing !== null && trim($existing) !== '') {
            $diagnostics[] = Diagnostic::info(null, $this->guidanceLeftIntactMessage($file));
        }

        return $diagnostics;
    }

    /**
     * INFO fired by the empty-assembly guard: boost resolved no guidance
     * content this sync, and the existing guidance file is non-empty, so it was
     * LEFT INTACT rather than blanked (the stateless markerless model can't
     * distinguish a boost-owned-now-empty file from an operator's pre-existing
     * one, and wiping the latter is data loss). Makes the leave-prior behavior
     * observable instead of silent, and tells the operator how to reach an
     * empty file deliberately.
     */
    private function guidanceLeftIntactMessage(string $file): string
    {
        return sprintf(
            'boost-core resolved no guidelines or conventions this sync, so `%s` was left untouched rather than blanked. Add guidelines under `.ai/guidelines/` (or declare conventions in `boost.php`) to populate it; delete the file manually if you want it empty.',
            $file,
        );
    }

    /**
     * @param  list<string>  $wrapperClaimedPaths  0.11.0: paths declared by
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

        // 0.13.0: ignore the sync ownership manifest dir. Added ONLY when this
        // sync will actually write a manifest ($includeManifestDir) — so an
        // otherwise-empty project never gets a `.boost/` line for a dir that
        // won't exist. enumerateManagedFiles() skips this dir so the stale-
        // cleanup pass never deletes the manifest it relies on.
        if ($includeManifestDir) {
            $patterns[] = SyncManifest::DIR . '/';
        }

        // 0.11.0: wrapper-claimed paths land in the managed block so bare-CLI
        // sync doesn't silently drop them. Pattern format matches what agent
        // targets emit (no leading slash) so subsequent reads via
        // readPriorGitignorePatterns + enumerateManagedFiles produce the
        // same key form as `WrittenFile::$relativePath`. Without the form
        // match, cleanup-pass's `$writtenPaths` check misses files just
        // written by sync and falsely classifies them stale (codex-review
        // pin).
        //
        // Filter known guideline-file basenames (CLAUDE.md / AGENTS.md /
        // GEMINI.md): those use ManagedRegion + are operator-tracked, never
        // wholesale-replaced. Adding them to the gitignore-managed manifest
        // would route them through cleanupStaleManagedFiles, which deletes
        // the WHOLE file when stale — destroying operator-authored content
        // outside boost-core's managed markers (codex-review P1 pin). The
        // wrapper contract is for files that need stale-cleanup-exclusion;
        // guideline files don't fit that surface.
        foreach ($wrapperClaimedPaths as $wrapperPath) {
            $normalized = ltrim($wrapperPath, '/');
            if ($this->isGuidelineFilePath($normalized)) {
                continue;
            }

            // 0.14.0: dedup against dir-level patterns. The agent-target globs
            // above (e.g. `.claude/skills/`) already ignore everything beneath
            // them, so a per-file wrapper entry like
            // `.claude/skills/foo/SKILL.md` is pure bloat that re-grows the
            // managed block on every sync (one line per injected skill × every
            // shared+dedicated root). Skip any path a dir-level pattern already
            // covers — keep the compact dir-level shape. (Field report:
            // project-boost-laravel had 26 redundant per-file lines.)
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
     * Guideline analogue of {@see resolveSkillShadowPaths()} (0.13.0). For a
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
     * detect ambiguity — `--diff` can't pick one when more than one matches
     * (codex-review). Empty list = not shadowing.
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
        // only known inside sync(). See spec §5.1 (lifecycle constraint).
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
     * Load host commands from `.ai/commands/`. Phase 1 of the agent-commands
     * spec is host-only — vendor commands, tag-filtering, and collision
     * resolution are Phase 4.
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
     * @param  array<string, true>  $ownableEmitterPaths  collected by-ref: emitter outputs boost may OWN (fresh writes or already-owned) — only these become manifest-recorded + reapable (codex high)
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

        // 0.14.0 (codex high): canonicalize the emitter path ONCE before every
        // downstream use — reserved-path check, collision tracking, manifest
        // recording, orphan matching. FileWriter resolves `./CLAUDE.md` and
        // `foo/./bar.md` to the same on-disk file, so without this an emitter
        // could (a) dodge the reserved-path denylist with a `.`-segment
        // spelling, and (b) make a later spelling change (`./foo.txt` →
        // `foo.txt`) look like a dormant orphan and reap the just-written live
        // file. Collapsing `.`/empty segments + normalizing separators here
        // makes the string boost stores and the string boost matches identical.
        $relativePath = $this->canonicalizeWrapperPath($emitted->relativePath);
        if ($relativePath === '') {
            return new EmitterResult(
                fqcn: $emitter->fqcn,
                vendor: $emitter->vendor,
                action: EmitterAction::SKIPPED,
                relativePath: $emitted->relativePath,
                reason: 'emit() returned an empty/invalid path; write skipped.',
            );
        }

        // 0.14.0: reserved-path denylist (codex P1). An emitter must emit only
        // to a path it alone owns — never a guidance file, .gitignore, .boost/,
        // a source dir, an agent skill/command root, or a wrapper-claimed path.
        // Reject (skip the write) + surface a diagnostic; do NOT trip
        // $hasAnyError (a misbehaving emitter must not block the whole sync's
        // reap/manifest), and never track/reap the path.
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

        // 0.14.0 first-adoption warn (codex P1): capture pre-existence BEFORE
        // the write so a takeover of an operator file boost has no ownership
        // record of is surfaced (never silently adopted-then-later-reaped).
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

        // 0.14.0 ownership gate (codex high): boost may OWN — and therefore later
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
     * that point into the now-absent `vendor/<old-pkg>/`. Earlier sync
     * runs stumbled over them; this prune treats dead links in managed
     * dirs as stale state that sync owns and cleans up.
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
     * flat sibling left behind by a pre-0.2 boost-core run.
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
     * `<same-stem>.md` left behind by pre-0.2 boost-core runs. The structural
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
