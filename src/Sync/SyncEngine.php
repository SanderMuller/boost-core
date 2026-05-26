<?php declare(strict_types=1);

namespace SanderMuller\BoostCore\Sync;

use LogicException;
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
use SanderMuller\BoostCore\Discovery\DiscoveredEmitter;
use SanderMuller\BoostCore\Discovery\DiscoveredVendor;
use SanderMuller\BoostCore\Discovery\EmitterDiscovery;
use SanderMuller\BoostCore\Discovery\VendorScanner;
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
     *   `.github/copilot-instructions.md`      → null
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
     * Resolve the same skill set `sync()` would emit, without writing
     * anything. Powers `boost where` (origin tracing) — same pipeline as
     * the live sync (host + scanned vendors + remote, tag-filtered,
     * collision-resolved), just exposed as a read-only inspection.
     *
     * Returns the resolved skills along with both classification keys
     * the caller needs to label each origin precisely:
     *
     *  - `scannedVendorKeys` — Composer-installed allowlisted vendors
     *    that publish skills.
     *  - `remoteSourceKeys` — `<owner>/<repo>` source keys declared via
     *    `withRemoteSkills(...)`.
     *
     * These overlap is legal (a vendor name + a remote source can share
     * the same `<owner>/<repo>` key as long as their skill names don't
     * collide). `WhereCommand` consumes both lists to render unambiguous
     * labels — a key in both is "vendor+remote", not just one or the other.
     *
     * Caller-injected vendor skills (the wrapper-package pattern) are
     * NOT included — those are runtime-only inputs to `sync()` and the
     * wrapper owns its own inspection surface.
     *
     * @return array{skills: list<Skill>, remoteSourceKeys: list<string>, scannedVendorKeys: list<string>}
     */
    public function resolveSkillsForInspection(string $projectRoot): array
    {
        $projectRoot = rtrim($projectRoot, '/');
        $config = $this->configLoader->load($projectRoot);
        $allowedVendors = $this->discoverAllowedVendors($config);

        $remoteSourceKeys = array_map(
            static fn (RemoteSkillSource $source): string => $source->source,
            $config->remoteSkills,
        );

        // Scanned vendor keys = packages on the allowlist that the
        // vendor scanner can actually see publishing — the precise set
        // whose sourceVendor will be labeled `vendor` in `boost where`.
        $scannedVendorKeys = array_map(
            static fn (DiscoveredVendor $vendor): string => $vendor->name,
            $allowedVendors,
        );

        return [
            'skills' => $this->resolveSkills($config, $allowedVendors, false, [], true)['skills'],
            'remoteSourceKeys' => array_values($remoteSourceKeys),
            'scannedVendorKeys' => array_values($scannedVendorKeys),
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
        );

        $remoteOrphanWrites = $this->remoteCoordinator->applyOrphanPruning(
            $projectRoot,
            $this->agentTargets,
            $config,
            $resolvedSkills,
            $checkOnly,
        );

        $gitignoreWrite = ($config->manageGitignore && getenv(Env::SKIP_GITIGNORE) === false)
            ? $this->updateGitignore($projectRoot, $config, $checkOnly)
            : null;

        $writes = array_merge($fanOutWrites, $remoteOrphanWrites);
        if ($gitignoreWrite instanceof WrittenFile) {
            $writes[] = $gitignoreWrite;
        }

        return new SyncResult(
            writes: $writes,
            emitters: $emitterResults,
            errors: array_merge($fanOutErrors, $remoteErrors),
            check: $checkOnly,
            tagFilteredSkillsCount: TagFilterNudge::count($config, $tagFilteredCount),
            hostShadows: $skillResolution['hostShadows'],
        );
    }

    private function updateGitignore(string $projectRoot, BoostConfig $config, bool $checkOnly): ?WrittenFile
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
    private function fanOut(
        string $projectRoot,
        BoostConfig $config,
        array $skills,
        array $guidelines,
        array $commands,
        array $droppedSkillNames,
        bool $checkOnly,
    ): array {
        /** @var list<WrittenFile> $writes */
        $writes = [];
        /** @var list<string> $errors */
        $errors = [];

        $toPrune = $this->filteredSkillPruner->candidates($skills, $droppedSkillNames);

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

            foreach ($target->plan($skills, $guidelines) as $pending) {
                $this->writeAndPrune($projectRoot, $pending, $target, $checkOnly, $writes, $errors);
            }

            foreach ($target->planCommands($commands) as $pending) {
                $this->writeAndPrune($projectRoot, $pending, $target, $checkOnly, $writes, $errors);
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
