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
use SanderMuller\BoostCore\Conventions\ConventionsSchema;
use SanderMuller\BoostCore\Conventions\Diagnostic;
use SanderMuller\BoostCore\Conventions\SchemaDiscovery;
use SanderMuller\BoostCore\Conventions\VendorSchemaSource;
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
use Symfony\Component\Yaml\Exception\ParseException;
use Symfony\Component\Yaml\Yaml;
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
            agentTargets: [
                new ClaudeCodeTarget(),
                new CursorTarget(),
                new CopilotTarget(),
                new CodexTarget(),
                new GeminiTarget(),
                new JunieTarget(),
                new KiroTarget(),
                new OpenCodeTarget(),
                new AmpTarget(),
            ],
            installedPackages: $installedPackages,
        );
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
        try {
            $skillResolution = $this->resolveSkills($config, $allowedVendors, $force, $injectedVendorSkills, $checkOnly);
            $resolvedGuidelines = $this->resolveGuidelines($config, $allowedVendors, $force, $injectedVendorGuidelines, $guidelineRenderErrors);
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

        $context = new SyncContext(
            projectRoot: $projectRoot,
            packages: $this->installedPackages,
            config: $config,
        );

        $emitterResults = $this->runEmitters($projectRoot, $config, $context, $checkOnly);

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

        // 0.11.0: discover wrapper-claimed paths BEFORE updateGitignore so the
        // managed `.gitignore` block can include them. Otherwise bare-CLI
        // sync drops the wrapper's emit-paths from the managed block (codex-
        // review caught this in the initial implementation), the next sync
        // doesn't see them in priorManagedFiles, and the wrapper-injected
        // files leak into git tracking until the next wrapper-driven sync.
        $wrapperEmits = (new WrapperEmitDiscovery($this->installedPackages))->discover($projectRoot);

        $gitignoreWrite = ($config->manageGitignore && getenv(Env::SKIP_GITIGNORE) === false)
            ? $this->updateGitignore($projectRoot, $config, $checkOnly, array_keys($wrapperEmits['paths']))
            : null;

        $writes = array_merge($fanOutWrites, $remoteOrphanWrites);
        if ($gitignoreWrite instanceof WrittenFile) {
            $writes[] = $gitignoreWrite;
        }

        $conventionsResult = $this->syncConventions($projectRoot, $config, $checkOnly);
        if ($conventionsResult['write'] instanceof WrittenFile) {
            $writes[] = $conventionsResult['write'];
        }

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
        $hasAnyError = $fanOutErrors !== [] || $remoteErrors !== [];
        foreach ($emitterResults as $emitterResult) {
            if ($emitterResult->action === EmitterAction::ERRORED) {
                $hasAnyError = true;

                break;
            }
        }

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

        // Diagnostic surface for the render-fail-then-write safety gate
        // (0.9.3): operator-visible signal that guideline writes were
        // skipped, naming each failed source so they know which renderer
        // / file to investigate.
        $renderFailDiagnostics = [];
        foreach ($guidelineRenderErrors as $errorMessage) {
            $renderFailDiagnostics[] = Diagnostic::warning(
                null,
                sprintf(
                    'Guideline render failed; content between `<!-- boost-core:guidelines:start -->` and `<!-- boost-core:guidelines:end -->` preserved at prior state. Run `vendor/bin/boost sync` again after resolving the render failure. Source: %s',
                    $errorMessage,
                ),
            );
        }

        return new SyncResult(
            writes: $writes,
            emitters: $emitterResults,
            errors: array_merge($fanOutErrors, $remoteErrors),
            check: $checkOnly,
            tagFilteredSkillsCount: TagFilterNudge::count($config, $tagFilteredCount),
            hostShadows: $skillResolution['hostShadows'],
            diagnostics: [
                ...$conventionsResult['diagnostics'],
                ...$cleanupResult['diagnostics'],
                ...$wrapperEmits['diagnostics'],
                ...$renderFailDiagnostics,
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
     * @param  array<string, true>  $wrapperExcludedPaths  0.11.0: paths
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
     * @param  array<string, true>  $wrapperExcludedPaths
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
     * Runs schema discovery for allowlisted vendors, optionally scaffolds the
     * Project Conventions block in CLAUDE.md, and returns a write + diagnostics
     * to thread into the SyncResult.
     *
     * Diagnostics always route through SyncResult::diagnostics (NEW in 0.8.0).
     * Never affects sync exit code — error-level diagnostics are still visible
     * via SyncCommand's render but do not trigger FAILURE. See spec §14.
     *
     * @return array{write: ?WrittenFile, diagnostics: list<Diagnostic>}
     */
    private function syncConventions(string $projectRoot, BoostConfig $config, bool $checkOnly): array
    {
        $discovery = new SchemaDiscovery($this->installedPackages);
        ['sources' => $sources, 'diagnostics' => $diagnostics] = $discovery->discover($config->allowedVendors);

        if ($sources === []) {
            return ['write' => null, 'diagnostics' => $diagnostics];
        }

        $claudeMdPath = $projectRoot . '/CLAUDE.md';
        $claudeMd = is_file($claudeMdPath) ? @file_get_contents($claudeMdPath) : null;
        $claudeMd = $claudeMd === false ? null : $claudeMd;

        $emitter = new ConventionsBlockEmitter();
        $hasMarkerRegion = $claudeMd !== null && str_contains($claudeMd, ConventionsBlockEmitter::START_MARKER);
        $existingBody = $hasMarkerRegion ? $emitter->extract($claudeMd) : null;
        $existingBodyHasFilledContent = $this->existingBodyIsOperatorContent($existingBody);

        // 0.9.0 fail-closed contract — surfaces when CLAUDE.md has filled
        // marker YAML but boost.php has no `->withConventions(...)` call. This
        // is the migration case (operator hasn't yet run convert-conventions).
        // Sync MUST NOT overwrite the YAML to avoid silent destruction.
        if ($existingBodyHasFilledContent && $config->conventions === []) {
            $diagnostics[] = Diagnostic::warning(
                null,
                'Project Conventions YAML in CLAUDE.md but boost.php has no ->withConventions([...]) call. Run `vendor/bin/boost convert-conventions` to migrate the YAML into boost.php. Sync is leaving the existing CLAUDE.md region intact for now — no values lost.',
            );

            // Still validate the existing YAML so the operator sees its current state.
            $diagnostics = [...$diagnostics, ...$this->validateExisting($emitter, $claudeMd, $sources)];

            return ['write' => null, 'diagnostics' => $diagnostics];
        }

        // Validate the source-of-truth values (BoostConfig::$conventions) regardless of render path.
        $schema = new ConventionsSchema($sources);
        $diagnostics = [...$diagnostics, ...$schema->validate($config->conventions)];

        // 0.9.x reconcile semantics — boost.php is canonical source of truth.
        // Three cases when both sources non-empty AND existing body has content:
        //  - Parseable body, values equal: no-op (renderFromValues returns null)
        //  - Parseable body, values differ (incl. key removal): warning +
        //    proceed. boost.php wins, CLAUDE.md re-renders. The 0.9.0 fail-
        //    closed-on-divergence behavior was too strict — caught legitimate
        //    edits to boost.php (operator removes a slot key → sync stalls
        //    silently with no write because the parsed bodies differ). Warning
        //    preserves the visibility signal without blocking the canonical flow.
        //  - Unparseable body: still fail-closed. Unparseable YAML means the
        //    body was hand-edited into a broken state; silently overwriting
        //    risks destroying recovery context. Operator reconciles manually.
        if ($config->conventions !== [] && $existingBodyHasFilledContent) {
            $existingParsed = $this->parseMarkerBodyForCompare($existingBody);
            $rendered = ['schema-version' => $emitter->scaffoldSeed($sources), ...$config->conventions];
            $renderedForCompare = $rendered;
            unset($renderedForCompare['schema-version']);
            $existingForCompare = $existingParsed ?? [];
            unset($existingForCompare['schema-version']);

            if ($existingParsed === null) {
                $diagnostics[] = Diagnostic::error(
                    null,
                    "Project Conventions: CLAUDE.md's marker body could not be parsed as YAML. Sync refuses to overwrite a malformed body to avoid destroying recovery context. Open CLAUDE.md, find the section between <!-- boost-core:conventions:start --> and <!-- boost-core:conventions:end --> markers, and replace the content there with just `schema-version: 1` (leave the markers themselves in place) to let boost.php take over on the next sync.",
                );

                return ['write' => null, 'diagnostics' => $diagnostics];
            }

            if ($existingForCompare !== $renderedForCompare) {
                $diagnostics[] = Diagnostic::warning(
                    null,
                    'Project Conventions: CLAUDE.md\'s marker body differs from boost.php\'s ->withConventions([...]). boost.php is canonical; CLAUDE.md is being re-rendered to match. If you intentionally edited CLAUDE.md, that change is being overwritten — make the edit in boost.php\'s ->withConventions([...]) chain instead.',
                );
            }
        }

        ['contents' => $newContents, 'diagnostics' => $emitterDiagnostics] = $emitter->renderFromValues($claudeMd, $sources, $config->conventions);
        $diagnostics = [...$diagnostics, ...$emitterDiagnostics];

        if ($newContents === null) {
            return ['write' => null, 'diagnostics' => $diagnostics];
        }

        if ($checkOnly) {
            return [
                'write' => new WrittenFile(
                    relativePath: 'CLAUDE.md',
                    absolutePath: $claudeMdPath,
                    action: WriteAction::WOULD_WRITE,
                ),
                'diagnostics' => $diagnostics,
            ];
        }

        @file_put_contents($claudeMdPath, $newContents);

        return [
            'write' => new WrittenFile(
                relativePath: 'CLAUDE.md',
                absolutePath: $claudeMdPath,
                action: WriteAction::WROTE,
            ),
            'diagnostics' => $diagnostics,
        ];
    }

    /**
     * Is the marker body operator-filled (real values) vs scaffold-only?
     * Heuristic: non-empty body that contains a key other than `schema-version`.
     * Matches the spec's "filled vs scaffold-only" distinction in §3.2.
     */
    private function existingBodyIsOperatorContent(?string $body): bool
    {
        if ($body === null) {
            return false;
        }

        $trimmed = trim($body);
        if ($trimmed === '') {
            return false;
        }

        try {
            $parsed = Yaml::parse($trimmed);
        } catch (ParseException) {
            // Unparseable body — treat as "has content" to be safe (operator
            // wrote SOMETHING; sync should not overwrite it).
            return true;
        }

        if (! is_array($parsed)) {
            return true;
        }

        foreach (array_keys($parsed) as $key) {
            if ($key !== 'schema-version') {
                return true;
            }
        }

        return false;
    }

    /**
     * Parse the existing marker body YAML for comparison against the
     * source-of-truth values. Returns null on parse failure (treat as
     * "incomparable" rather than as "different" — silent destruction risk
     * stays the operator's call via the migration-warning path above).
     *
     * @return array<mixed, mixed>|null
     */
    private function parseMarkerBodyForCompare(?string $body): ?array
    {
        if ($body === null) {
            return null;
        }

        $trimmed = trim($body);
        if ($trimmed === '') {
            return null;
        }

        try {
            $parsed = Yaml::parse($trimmed);
        } catch (ParseException) {
            return null;
        }

        return is_array($parsed) ? $parsed : null;
    }

    /**
     * @param list<VendorSchemaSource> $sources
     * @return list<Diagnostic>
     */
    private function validateExisting(ConventionsBlockEmitter $emitter, ?string $claudeMd, array $sources): array
    {
        if ($claudeMd === null) {
            return [];
        }

        ['values' => $values, 'diagnostics' => $parseDiagnostics] = $emitter->parse($claudeMd);
        if ($values === null) {
            return $parseDiagnostics;
        }

        $schema = new ConventionsSchema($sources);

        return [...$parseDiagnostics, ...$schema->validate($values)];
    }

    /**
     * @param  list<string>  $wrapperClaimedPaths  0.11.0: paths declared by
     *   `BoostWrapper` classes from installed wrapper packages — included in
     *   the managed `.gitignore` block so bare-CLI sync doesn't drop wrapper-
     *   emitted files from gitignore tracking (which would leak them into
     *   the operator's git working set).
     */
    private function updateGitignore(string $projectRoot, BoostConfig $config, bool $checkOnly, array $wrapperClaimedPaths = []): ?WrittenFile
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
     * @return list<Guideline>
     */
    private function resolveGuidelines(BoostConfig $config, array $allowedVendors, bool $force, array $injectedVendorGuidelines = [], array &$renderErrors = []): array
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

        return $this->guidelineResolver->resolve($hostGuidelines, $vendorGuidelines, $force);
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
    private function runEmitters(string $projectRoot, BoostConfig $config, SyncContext $context, bool $checkOnly): array
    {
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

            $results[] = $this->runOneEmitter($emitter, $context, $projectRoot, $checkOnly, $claimedPaths);
        }

        return $results;
    }

    /**
     * @param  array<string, string>  $claimedPaths
     */
    private function runOneEmitter(
        DiscoveredEmitter $emitter,
        SyncContext $context,
        string $projectRoot,
        bool $checkOnly,
        array &$claimedPaths,
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

        if (isset($claimedPaths[$emitted->relativePath])) {
            return new EmitterResult(
                fqcn: $emitter->fqcn,
                vendor: $emitter->vendor,
                action: EmitterAction::ERRORED,
                relativePath: $emitted->relativePath,
                reason: sprintf(
                    'Path "%s" already claimed by emitter %s.',
                    $emitted->relativePath,
                    $claimedPaths[$emitted->relativePath],
                ),
            );
        }

        $claimedPaths[$emitted->relativePath] = $emitter->fqcn;

        try {
            $write = $this->writer->write(
                $projectRoot,
                new PendingWrite($emitted->relativePath, $emitted->content),
                $checkOnly,
            );
        } catch (Throwable $throwable) {
            return new EmitterResult(
                fqcn: $emitter->fqcn,
                vendor: $emitter->vendor,
                action: EmitterAction::ERRORED,
                relativePath: $emitted->relativePath,
                reason: $throwable->getMessage(),
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
