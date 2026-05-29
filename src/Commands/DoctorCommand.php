<?php declare(strict_types=1);

namespace SanderMuller\BoostCore\Commands;

use JsonException;
use SanderMuller\BoostCore\Config\BoostConfig;
use SanderMuller\BoostCore\Conventions\ConventionsSchema;
use SanderMuller\BoostCore\Conventions\Diagnostic;
use SanderMuller\BoostCore\Conventions\SchemaDiscovery;
use SanderMuller\BoostCore\Conventions\VendorSchemaSource;
use SanderMuller\BoostCore\Discovery\PackagistVersionLookup;
use SanderMuller\BoostCore\Discovery\PathRepoDetector;
use SanderMuller\BoostCore\Discovery\VendorScanner;
use SanderMuller\BoostCore\Enums\Agent;
use SanderMuller\BoostCore\Skills\Remote\RemoteSkillCache;
use SanderMuller\BoostCore\Skills\Remote\RemoteSkillSource;
use SanderMuller\BoostCore\Sync\InstalledPackages;
use SanderMuller\BoostCore\Sync\SyncEngine;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Component\Finder\Finder;
use Throwable;

/**
 * Aggregated diagnostics for a boost-core install.
 */
final class DoctorCommand extends BoostBaseCommand
{
    public function __construct(
        private readonly TagReporter $reporter = new TagReporter(),
        private readonly PackagistVersionLookup $packagist = new PackagistVersionLookup(),
        // Injection seam for `--check-versions` tests — null means "read
        // the real Composer runtime via InstalledPackages::fromComposer()".
        // Other reporter methods read Composer directly; keeping scope
        // narrow here avoids broader DI churn.
        private readonly ?InstalledPackages $injectedPackages = null,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->setName('boost:doctor')
            ->setDescription('Diagnose a boost-core install. Reports config, allowlist, drift, etc.');
        $this->addWorkingDirOption();
        $this->addOption(
            'check-versions',
            null,
            InputOption::VALUE_NONE,
            'Compare boost-* family path-repo installs against Packagist. Opt-in — adds one HTTP call per shadowed family package.',
        );
        $this->addOption(
            'check-conventions',
            null,
            InputOption::VALUE_NONE,
            'Report Project Conventions slot status (missing required, unknown slots, schema-version mismatches, path-typed file existence).',
        );
        $this->addOption(
            'check-stale-paths',
            null,
            InputOption::VALUE_NONE,
            'List retired-paths-registry entries still present on disk. Read-only audit for legacy-migration triage post-0.9.6.',
        );
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $projectRoot = $this->resolveProjectRoot($input);

        $io->title('boost-core doctor');
        $io->writeln(sprintf('Project root: <info>%s</info>', $projectRoot));
        $io->newLine();

        $config = $this->loadConfig($io, $projectRoot);
        if (! $config instanceof BoostConfig) {
            return self::FAILURE;
        }

        $io->success(sprintf('boost.php at %s parses cleanly.', $projectRoot . '/boost.php'));

        $this->reportEntryPointMismatch($io);
        $this->reportAgents($io, $config);
        $this->reportSourcePaths($io, $config);
        $this->reportCommandLimitations($io, $config);
        $this->reportAllowlist($io, $config);
        $this->reportRemoteSkills($io, $config);
        $this->reportTags($io, $config);
        $this->reportDrift($io, $projectRoot);
        if ($input->getOption('check-versions') === true) {
            $this->reportPathRepoShadows($io, $projectRoot);
        }

        if ($input->getOption('check-conventions') === true) {
            $this->reportConventions($io, $projectRoot, $config);
        }

        if ($input->getOption('check-stale-paths') === true) {
            $this->reportStalePaths($io, $projectRoot, $config);
        }

        return self::SUCCESS;
    }

    /**
     * Opt-in (via `--check-stale-paths`) audit of the retired-paths registry
     * — paths boost-core emitted in past versions but no longer maintains.
     *
     * Read-only by contract: sync owns the cleanup (deletes on next run when
     * Copilot is active); doctor lists registry-tracked paths still present
     * on disk for legacy-migration triage. Operators upgrading across the
     * 0.9.0 / 0.9.1 retirement boundaries can see at a glance what next sync
     * would delete without running sync.
     *
     * Gated on Copilot in active agents, matching sync's gate exactly:
     * registry entries are Copilot-emitted, so absence of Copilot in
     * `withAgents()` means absence of sync-side cleanup intent.
     */
    private function reportStalePaths(SymfonyStyle $io, string $projectRoot, BoostConfig $config): void
    {
        $io->section('Stale paths (retired-paths registry)');

        if (! $config->hasAgent(Agent::COPILOT)) {
            $io->writeln('<info>Copilot not in active agents. Retired-paths registry is Copilot-scoped — nothing to audit.</info>');

            return;
        }

        $present = [];
        foreach (SyncEngine::RETIRED_COPILOT_PATHS as $relativePath) {
            $absolute = $projectRoot . '/' . $relativePath;
            if (file_exists($absolute) || is_link($absolute)) {
                $present[] = $relativePath;
            }
        }

        if ($present === []) {
            $io->writeln('<info>No retired-registry paths present on disk. Clean.</info>');

            return;
        }

        $io->writeln('<comment>Retired paths still present on disk. Next `vendor/bin/boost sync` will delete:</comment>');
        $io->listing($present);
    }

    /**
     * Opt-in (via `--check-versions`) Packagist comparison for family
     * packages installed from a Composer `path` repo. Surfaces the
     * "path repo silently shadows newer published version" foot-gun.
     * Adds one HTTP call per shadowed package — gated explicitly so the
     * routine offline `doctor` invocation stays network-free (CI-safe).
     */
    private function reportPathRepoShadows(SymfonyStyle $io, string $projectRoot): void
    {
        $packages = $this->injectedPackages ?? InstalledPackages::fromComposer();
        $shadows = (new PathRepoDetector($packages))->findShadowingPackages($projectRoot);

        $io->section('Path-repo version check');

        if ($shadows === []) {
            $io->writeln('<info>No family packages installed from a path repo. Nothing to compare.</info>');

            return;
        }

        $rows = [];
        foreach ($shadows as $name) {
            $installedVersion = $packages->version($name);
            $installedDisplay = $installedVersion ?? '<comment>unknown</comment>';
            $latest = $this->packagist->latestStable($name);
            $latestDisplay = $latest ?? '<comment>lookup failed</comment>';
            // Compare against the raw nullable — '?' display sentinel
            // must not couple into the comparison logic.
            $flag = ($latest !== null && $installedVersion !== null && $latest !== $installedVersion)
                ? '<comment>⚠ Packagist newer</comment>'
                : '';
            $rows[] = [$name, $installedDisplay, $latestDisplay, $flag];
        }

        $io->table(['Package', 'Installed (path repo)', 'Packagist latest stable', ''], $rows);
        $io->writeln('<comment>Path repos silently override Packagist resolution for matching constraints. Remove unused `repositories[]` entries from composer.json + re-run `composer update` to pull from Packagist.</comment>');
    }

    /**
     * Surface the wrong-entry-point ergonomics gap when boost-core runs as
     * bare CLI in a Laravel-wrapper project. Detection: `sandermuller/
     * project-boost-laravel` installed → the consumer adopted the wrapper
     * → the canonical entry point is `php artisan project-boost:sync`,
     * not `vendor/bin/boost`. Bare CLI bypasses the wrapper's skill-
     * injection pipeline + produces cross-agent capability asymmetry:
     * Claude Code may mask the absence locally via laravel/boost's MCP
     * server, but Cursor / Copilot / Codex silently miss the bundled
     * skills (file-fanout is their only delivery mechanism).
     *
     * Emits a banner-warning only when this very command is invoked under
     * bare CLI in such a project. The Laravel wrapper's own command
     * surfaces (project-boost:doctor etc.) wouldn't reach this code path
     * unless they delegate here — and if they do, they'd be in the
     * "doing-it-right" context where the banner is noise. So the gate is
     * "wrapper installed AND we got here", which is the wrong-entry-point
     * footprint by construction.
     */
    private function reportEntryPointMismatch(SymfonyStyle $io): void
    {
        $packages = $this->injectedPackages ?? InstalledPackages::fromComposer();
        if (! $packages->has('sandermuller/project-boost-laravel')) {
            return;
        }

        $io->section('Entry-point mismatch');
        $io->warning(
            'Detected `sandermuller/project-boost-laravel` installed alongside boost-core. '
            . "Bare CLI bypasses the wrapper's skill-injection pipeline, producing cross-agent "
            . 'capability asymmetry — Claude Code may mask the absence locally via laravel/boost\'s '
            . 'MCP server, but Cursor / Copilot / Codex silently miss bundled skills (file-fanout '
            . 'is their only delivery mechanism). For full per-agent skill set, use '
            . '`php artisan project-boost:sync` instead. See project-boost-laravel install guide '
            . 'for the canonical composer.json scripts shape.',
        );
    }

    private function reportAgents(SymfonyStyle $io, BoostConfig $config): void
    {
        $agents = array_map(static fn (Agent $a): string => $a->value, $config->agents);
        $io->section('Agents');
        if ($agents === []) {
            $io->warning('No agents configured. Run `vendor/bin/boost install` to pick.');

            return;
        }

        $io->listing($agents);
    }

    private function reportSourcePaths(SymfonyStyle $io, BoostConfig $config): void
    {
        $io->section('Source paths');
        $io->table(['Key', 'Path', 'Status'], [
            ['skillsPath', $config->skillsPath, is_dir($config->skillsPath) ? 'exists' : 'MISSING'],
            ['guidelinesPath', $config->guidelinesPath, is_dir($config->guidelinesPath) ? 'exists' : 'MISSING'],
            ['commandsPath', $config->commandsPath, is_dir($config->commandsPath) ? 'exists' : 'MISSING'],
        ]);
    }

    /**
     * Surface per-agent command-emit gaps when `.ai/commands/` is in play.
     *
     * boost-core writes commands to seven of the nine agents — the six
     * dedicated-command-dir agents (Phase 1) and Kiro (which emits each
     * command as a skill-shaped `.kiro/skills/<name>/SKILL.md` via its
     * native slash-command surface). Two agents have no committable
     * command target boost-core can write into:
     *
     *  - Codex: deprecated personal-only prompts at `~/.codex/prompts/`.
     *  - Gemini: TOML format; boost-core does not hand-roll a serializer.
     *
     * When the project has a `.ai/commands/` directory AND one of those
     * agents is in `withAgents()`, point the operator at the manual
     * authoring path instead of silently shipping nothing.
     */
    private function reportCommandLimitations(SymfonyStyle $io, BoostConfig $config): void
    {
        if (! is_dir($config->commandsPath)) {
            return;
        }

        // Mirror `CommandLoader`'s scan exactly: Finder, recursive,
        // `*.md`, ignore dotfiles. Anything that loader would emit, this
        // surface must consider — otherwise doctor's limitation note
        // disagrees with what sync actually does for a `.ai/commands/sub/`
        // layout.
        $hasCommands = (new Finder())
            ->files()
            ->in($config->commandsPath)
            ->name('*.md')
            ->ignoreDotFiles(true)
            ->hasResults();

        if (! $hasCommands) {
            return;
        }

        // Resolve to a project-root-relative source path for the operator
        // message — works for the default `.ai/commands` AND any
        // `withCommandsPath(...)` override, and accurately covers nested
        // `<commands>/sub/*.md` layouts because CommandLoader recurses.
        $sourcePath = $config->commandsPath;
        $lines = [];
        if ($config->hasAgent(Agent::CODEX)) {
            $lines[] = sprintf(
                'Codex: prompts are deprecated and personal-only (`~/.codex/prompts/`). boost-core does not write there. To use these commands in Codex, copy your `%s/**/*.md` files into `~/.codex/prompts/` manually.',
                $sourcePath,
            );
        }

        if ($config->hasAgent(Agent::GEMINI)) {
            $lines[] = 'Gemini: command files use TOML; boost-core does not generate them. Author Gemini commands directly in `.gemini/commands/<name>.toml` or use a skill instead.';
        }

        if ($lines === []) {
            return;
        }

        $io->section('Command-emit limitations');
        foreach ($lines as $line) {
            $io->writeln('<comment>•</comment> ' . $line);
        }
    }

    private function reportAllowlist(SymfonyStyle $io, BoostConfig $config): void
    {
        $io->section('Vendor allowlist');

        $packages = InstalledPackages::fromComposer();
        $scanner = new VendorScanner($packages);
        $discoveredNames = [];
        foreach ($scanner->discover() as $vendor) {
            $discoveredNames[] = $vendor->name;
        }

        $allowedAndPresent = [];
        $allowedButMissing = [];
        foreach ($config->allowedVendors as $vendor) {
            if (in_array($vendor, $discoveredNames, true)) {
                $allowedAndPresent[] = $vendor;
            } else {
                $allowedButMissing[] = $vendor;
            }
        }

        $discoveredButNotAllowed = array_values(array_diff($discoveredNames, $config->allowedVendors));

        $this->renderAllowlistGroup($io, 'Allowlisted and publishing', $allowedAndPresent, 'info');
        $this->renderAllowlistGroup($io, 'Allowlisted but not installed (or not publishing)', $allowedButMissing, 'comment');
        $this->renderAllowlistGroup($io, 'Discovered but NOT allowlisted (run `vendor/bin/boost scan` to opt in)', $discoveredButNotAllowed, 'comment');

        if ($allowedAndPresent === [] && $allowedButMissing === [] && $discoveredButNotAllowed === []) {
            $io->writeln('<info>No vendor publishers detected.</info>');
        }
    }

    /**
     * @param  list<string>  $items
     */
    private function renderAllowlistGroup(SymfonyStyle $io, string $label, array $items, string $tag): void
    {
        if ($items === []) {
            return;
        }

        $io->writeln(sprintf('<%s>%s:</%s>', $tag, $label, $tag));
        $io->listing($items);
    }

    /**
     * Report `withRemoteSkills(...)` sources — `source@version`, moving-ref
     * warnings, per-skill cache presence. Strictly offline — checks the
     * filesystem only, never the network.
     */
    private function reportRemoteSkills(SymfonyStyle $io, BoostConfig $config): void
    {
        if ($config->remoteSkills === []) {
            return;
        }

        $io->section('Remote skill sources');
        $cacheRoot = RemoteSkillCache::resolveCacheRoot();
        $io->writeln(sprintf('Cache root: <comment>%s</comment>', $cacheRoot));
        $io->newLine();

        $movingRefSeen = false;
        foreach ($config->remoteSkills as $source) {
            $pinned = RemoteSkillCache::isPinnedVersion($source->version, $source->mode());
            $movingRefSeen = $movingRefSeen || ! $pinned;
            $marker = $pinned ? '' : '  <comment>⚠ moving ref — may drift between syncs</comment>';
            $io->writeln(sprintf(
                '<info>%s@%s</info> (%s)%s',
                $source->source,
                $source->version,
                $source->mode(),
                $marker,
            ));

            $slugDir = $cacheRoot . '/' . RemoteSkillCache::slug($source->source);
            $rows = [];
            foreach ($source->skills as $ref) {
                $cached = $this->remoteSkillCachedForDeclaredRef($slugDir, $source, $ref->name);
                $rows[] = [
                    '  ' . $ref->name,
                    $cached ? '<info>cached</info>' : '<comment>not cached (will fetch on next sync)</comment>',
                ];
            }

            if ($rows !== []) {
                $io->table(['Skill', 'Cache'], $rows);
            }
        }

        if ($movingRefSeen) {
            $io->warning('Moving refs re-resolve every 24h and can drift silently. Pin to a tag (`v1.2.0`) or full SHA for reproducible builds.');
        }

        $token = getenv('BOOST_GITHUB_TOKEN');
        if (count($config->remoteSkills) > 3 && (! is_string($token) || $token === '')) {
            $io->note('Anonymous GitHub access caps at 60 requests/hour. Set BOOST_GITHUB_TOKEN to lift this to 5000/hour.');
        }
    }

    /**
     * True when the cache holds `SKILL.md` for THIS source's currently-declared
     * ref — not just any earlier ref. The check is offline:
     *
     *  - Pinned versions (`v1.2.3`, full SHAs) resolve to themselves; the
     *    slot path is fully knowable without I/O. The skill is cached iff
     *    `<slugDir>/<version>/<skillName>/SKILL.md` exists.
     *  - Moving refs (`'main'`, `'latest'`, branches) need the
     *    resolution-cache file to map the declared ref to its last-resolved
     *    SHA. No cache file or no entry → "not cached" (offline contract:
     *    never call out to GitHub). With an entry, check the SHA slot.
     *
     * Reporting any-ref-matches would lie after a version bump — the
     * previous slot would mark the new ref "cached" even though the next
     * sync must fetch.
     */
    private function remoteSkillCachedForDeclaredRef(string $slugDir, RemoteSkillSource $source, string $skillName): bool
    {
        if (! is_dir($slugDir)) {
            return false;
        }

        if (RemoteSkillCache::isPinnedVersion($source->version, $source->mode())) {
            return is_file($slugDir . '/' . $source->version . '/' . $skillName . '/SKILL.md');
        }

        $resolved = $this->readResolutionCacheEntry($slugDir, $source);
        if ($resolved === null) {
            return false;
        }

        return is_file($slugDir . '/' . $resolved . '/' . $skillName . '/SKILL.md');
    }

    /**
     * Read the resolution-cache file for the source's slug and return the
     * SHA last resolved for `<version>:<mode>`, or null if not present. Pure
     * filesystem read — never hits the network.
     */
    private function readResolutionCacheEntry(string $slugDir, RemoteSkillSource $source): ?string
    {
        $path = $slugDir . '/.resolution-cache.json';
        if (! is_file($path)) {
            return null;
        }

        $raw = @file_get_contents($path);
        if ($raw === false) {
            return null;
        }

        try {
            $decoded = json_decode($raw, true, flags: JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            return null;
        }

        $key = $source->version . ':' . $source->mode();
        $entry = is_array($decoded) ? ($decoded[$key] ?? null) : null;
        if (! is_array($entry)) {
            return null;
        }

        $resolved = $entry['resolved'] ?? null;

        return is_string($resolved) && $resolved !== '' ? $resolved : null;
    }

    private function reportTags(SymfonyStyle $io, BoostConfig $config): void
    {
        $io->section('Skill tags');
        $this->reporter->report($io, $config);
    }

    private function reportDrift(SymfonyStyle $io, string $projectRoot): void
    {
        $io->section('Drift');

        try {
            $result = SyncEngine::default()->sync($projectRoot, checkOnly: true);
        } catch (Throwable $throwable) {
            $io->warning('Could not check drift: ' . $throwable->getMessage());

            return;
        }

        if ($result->hasDrift()) {
            $io->warning(sprintf(
                '%d file(s) would change. Run `vendor/bin/boost sync`.',
                $result->countWouldChange(),
            ));

            return;
        }

        $io->success('No drift detected. Generated files match sources.');
    }

    private function reportConventions(SymfonyStyle $io, string $projectRoot, BoostConfig $config): void
    {
        $io->section('Project Conventions');

        $discovery = new SchemaDiscovery(
            $this->injectedPackages ?? InstalledPackages::fromComposer(),
        );
        ['sources' => $sources, 'diagnostics' => $discoveryDiagnostics] = $discovery->discover($config->allowedVendors);

        if ($sources === []) {
            // 0.10.1: split malformed-declaration diagnostics (warning/error)
            // from the noise-collapse summary INFO. SchemaDiscovery's summary
            // INFO populates the diagnostics list even in the legitimately-
            // empty case ("no allowlisted vendor publishes a schema yet"),
            // so the pre-0.10.1 "any diagnostic → all malformed" branch was
            // false-positive: a clean no-schemas-published project triaged
            // as if every vendor shipped broken JSON. Filter by level.
            $malformed = array_values(array_filter(
                $discoveryDiagnostics,
                static fn (Diagnostic $d): bool => $d->level !== 'info',
            ));

            if ($malformed === []) {
                $io->writeln('No conventions schemas declared by allowlisted vendors.');
                foreach ($discoveryDiagnostics as $diagnostic) {
                    $io->writeln("ℹ {$diagnostic->message}");
                }

                return;
            }

            $io->writeln('No usable conventions schemas — all declarations malformed:');
            foreach ($malformed as $diagnostic) {
                $vendor = $diagnostic->vendor === null ? '' : "[{$diagnostic->vendor}] ";
                $io->writeln("⚠ {$vendor}{$diagnostic->message}");
            }

            return;
        }

        // 0.9.0: source of truth is BoostConfig::$conventions, not CLAUDE.md.
        $values = $config->conventions;
        $schema = new ConventionsSchema($sources);
        $diagnostics = [
            ...$discoveryDiagnostics,
            ...$schema->validate($values),
            ...$this->checkPathSlots($projectRoot, $sources, $values),
        ];

        if ($diagnostics === []) {
            $io->success('Project Conventions valid against all allowlisted vendor schemas.');

            return;
        }

        foreach ($diagnostics as $diagnostic) {
            $glyph = match ($diagnostic->level) {
                'error' => '✗',
                'warning' => '⚠',
                'info' => 'ℹ',
                default => ' ',
            };
            $slot = $diagnostic->slot === null ? '' : "{$diagnostic->slot}: ";
            $vendor = $diagnostic->vendor === null ? '' : " ({$diagnostic->vendor})";
            $io->writeln("{$glyph} {$slot}{$diagnostic->message}{$vendor}");
        }
    }

    /**
     * @param  list<VendorSchemaSource>  $sources
     * @param  array<mixed, mixed>  $values
     * @return list<Diagnostic>
     */
    private function checkPathSlots(string $projectRoot, array $sources, array $values): array
    {
        /** @var list<Diagnostic> $out */
        $out = [];
        $rootCanonical = realpath($projectRoot);
        if ($rootCanonical === false) {
            return $out;
        }

        foreach ($sources as $source) {
            $properties = is_array($source->schema['properties'] ?? null) ? $source->schema['properties'] : [];
            foreach ($properties as $name => $schema) {
                if (! is_string($name)) {
                    continue;
                }

                if (! is_array($schema)) {
                    continue;
                }

                foreach ($this->diagnosticsForSlot($projectRoot, $rootCanonical, $source->vendorName, $name, $schema, $values[$name] ?? null) as $diag) {
                    $out[] = $diag;
                }
            }
        }

        return $out;
    }

    /**
     * @param  array<mixed, mixed>  $schema
     * @return list<Diagnostic>
     */
    private function diagnosticsForSlot(string $projectRoot, string $rootCanonical, string $vendor, string $name, array $schema, mixed $value): array
    {
        $type = $schema['type'] ?? null;

        if ($type === 'string' && ($schema['format'] ?? null) === 'path' && is_string($value)) {
            $diagnostic = $this->checkSinglePath($projectRoot, $rootCanonical, $name, $value, $vendor);

            return $diagnostic instanceof Diagnostic ? [$diagnostic] : [];
        }

        if ($type !== 'array' || ! is_array($value)) {
            return [];
        }

        if (! is_array($schema['items'] ?? null) || ($schema['items']['format'] ?? null) !== 'path') {
            return [];
        }

        /** @var list<Diagnostic> $out */
        $out = [];
        foreach ($value as $index => $item) {
            if (! is_string($item)) {
                continue;
            }

            $diagnostic = $this->checkSinglePath($projectRoot, $rootCanonical, "{$name}[{$index}]", $item, $vendor);
            if ($diagnostic instanceof Diagnostic) {
                $out[] = $diagnostic;
            }
        }

        return $out;
    }

    private function checkSinglePath(string $projectRoot, string $rootCanonical, string $slot, string $value, string $vendor): ?Diagnostic
    {
        if ($value === '') {
            return Diagnostic::warning($slot, 'path slot has an empty value', $vendor);
        }

        $resolved = str_starts_with($value, '/') ? $value : $projectRoot . '/' . $value;
        $canonical = realpath($resolved);
        if ($canonical === false) {
            return Diagnostic::warning(
                $slot,
                "file '{$value}' not found",
                $vendor,
            );
        }

        if (! str_starts_with($canonical, $rootCanonical . '/') && $canonical !== $rootCanonical) {
            return Diagnostic::warning(
                $slot,
                "'{$value}' resolves outside project root ({$canonical})",
                $vendor,
            );
        }

        return null;
    }
}
