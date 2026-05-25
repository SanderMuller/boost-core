<?php declare(strict_types=1);

namespace SanderMuller\BoostCore\Commands;

use JsonException;
use SanderMuller\BoostCore\Config\BoostConfig;
use SanderMuller\BoostCore\Discovery\VendorScanner;
use SanderMuller\BoostCore\Enums\Agent;
use SanderMuller\BoostCore\Skills\Remote\RemoteSkillCache;
use SanderMuller\BoostCore\Skills\Remote\RemoteSkillSource;
use SanderMuller\BoostCore\Sync\InstalledPackages;
use SanderMuller\BoostCore\Sync\SyncEngine;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use Throwable;

/**
 * Aggregated diagnostics for a boost-core install.
 */
final class DoctorCommand extends BoostBaseCommand
{
    public function __construct(
        private readonly TagReporter $reporter = new TagReporter(),
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->setName('boost:doctor')
            ->setDescription('Diagnose a boost-core install. Reports config, allowlist, drift, etc.');
        $this->addWorkingDirOption();
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

        $this->reportAgents($io, $config);
        $this->reportSourcePaths($io, $config);
        $this->reportCommandLimitations($io, $config);
        $this->reportAllowlist($io, $config);
        $this->reportRemoteSkills($io, $config);
        $this->reportTags($io, $config);
        $this->reportDrift($io, $projectRoot);

        return self::SUCCESS;
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

        $entries = @scandir($config->commandsPath);
        $hasCommands = false;
        if (is_array($entries)) {
            foreach ($entries as $entry) {
                if ($entry === '.') {
                    continue;
                }
                if ($entry === '..') {
                    continue;
                }

                if (str_ends_with($entry, '.md')) {
                    $hasCommands = true;

                    break;
                }
            }
        }

        if (! $hasCommands) {
            return;
        }

        $lines = [];
        if (in_array(Agent::CODEX, $config->agents, true)) {
            $lines[] = 'Codex: prompts are deprecated and personal-only (`~/.codex/prompts/`). boost-core does not write there. To use these commands in Codex, copy `.ai/commands/*.md` into `~/.codex/prompts/` manually.';
        }

        if (in_array(Agent::GEMINI, $config->agents, true)) {
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
}
