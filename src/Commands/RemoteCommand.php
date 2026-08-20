<?php declare(strict_types=1);

namespace SanderMuller\BoostCore\Commands;

use SanderMuller\BoostCore\Config\BoostConfig;
use SanderMuller\BoostCore\Config\BoostConfigLoader;
use SanderMuller\BoostCore\Config\BoostConfigPath;
use SanderMuller\BoostCore\Config\BoostConfigWriter;
use SanderMuller\BoostCore\Config\ConfigScaffolder;
use SanderMuller\BoostCore\Config\RemoteSkillsWriter;
use SanderMuller\BoostCore\Discovery\VendorScanner;
use SanderMuller\BoostCore\Skills\FrontmatterParser;
use SanderMuller\BoostCore\Skills\Remote\BundleExtractor;
use SanderMuller\BoostCore\Skills\Remote\CurlHttpTransport;
use SanderMuller\BoostCore\Skills\Remote\DiscoveredSkill;
use SanderMuller\BoostCore\Skills\Remote\DiscoveryPlan;
use SanderMuller\BoostCore\Skills\Remote\GitHubFetcher;
use SanderMuller\BoostCore\Skills\Remote\RemoteExtractException;
use SanderMuller\BoostCore\Skills\Remote\RemoteFetchException;
use SanderMuller\BoostCore\Skills\Remote\RemoteSelection;
use SanderMuller\BoostCore\Skills\Remote\RemoteSkillCache;
use SanderMuller\BoostCore\Skills\Remote\RemoteSkillDiscoverer;
use SanderMuller\BoostCore\Skills\Remote\RemoteSkillSource;
use SanderMuller\BoostCore\Skills\SkillLoader;
use SanderMuller\BoostCore\Sync\InstalledPackages;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use Throwable;

use function Laravel\Prompts\confirm;
use function Laravel\Prompts\multiselect;
use function Laravel\Prompts\text;

/**
 * Interactive picker for a remote (GitHub) skill source: fetch what a repo
 * publishes, let the operator check what they want, and persist the choice as
 * a `withRemoteSkills(...)` entry in `boost.php`.
 *
 * Fills the gap between the engine (which has fetched remote skills since
 * 1.3) and the config: until now the operator had to know a repo's skill
 * names or subdirectories BEFORE they could declare them.
 *
 * @internal
 */
final class RemoteCommand extends BoostBaseCommand
{
    /**
     * Version written for a source the project doesn't declare yet.
     *
     * `latest` — not `main` — because it is the only moving ref both modes
     * understand: {@see RemoteSkillSource::githubBundle()} resolves it to the
     * newest release tag, and the path-mode fetcher maps it to `HEAD`, which
     * is correct regardless of whether the repo's default branch is `main`,
     * `master` or `trunk`. An existing entry's version is never overwritten
     * by this default — see {@see resolveVersion()}.
     */
    public const DEFAULT_VERSION = 'latest';

    /**
     * Above this many bundle downloads, discovery asks before spending them.
     *
     * Bundle metadata only exists inside each `.skill` archive, so a rich
     * picker costs one request per skill. 25 leaves an anonymous caller (60
     * requests/hour) room for more than one run, while a repo of ordinary size
     * never sees the prompt.
     */
    public const CONFIRM_DOWNLOAD_THRESHOLD = 25;

    public function __construct(
        private readonly BoostConfigLoader $loader = new BoostConfigLoader(),
        private readonly ?RemoteSkillDiscoverer $discoverer = null,
        private readonly ?RemoteSkillCache $cache = null,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->setName('boost:remote')
            ->setDescription('Load a GitHub repo of skills and interactively pick which ones to add to withRemoteSkills().')
            ->addArgument('source', InputArgument::OPTIONAL, 'The repo to load, as `<owner>/<repo>` or a GitHub URL. Prompted for when omitted.')
            ->addOption('ref', null, InputOption::VALUE_REQUIRED, "Pin the source to a tag, branch or SHA. Defaults to the existing entry's version, else `latest`.")
            ->addOption('mode', null, InputOption::VALUE_REQUIRED, 'Force `bundle` (release assets) or `path` (repo directories) instead of auto-detecting.');
        $this->addWorkingDirOption();
        $this->addConfigOption();
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $projectRoot = $this->resolveProjectRoot($input);
        $configOverride = $this->configFileOption($input);

        $rawMode = $input->getOption('mode');
        $mode = is_string($rawMode) && $rawMode !== '' ? $rawMode : null;
        if ($mode !== null && ! in_array($mode, [RemoteSkillSource::MODE_BUNDLE, RemoteSkillSource::MODE_PATH], true)) {
            $io->error(sprintf('--mode must be `bundle` or `path`, got `%s`.', $mode));

            return self::FAILURE;
        }

        $configPath = $this->prepareConfig($io, $projectRoot, $configOverride);
        if ($configPath === null) {
            return self::FAILURE;
        }

        try {
            $config = $this->loader->load($projectRoot, $configOverride);
        } catch (Throwable $throwable) {
            $io->error($throwable->getMessage());

            return self::FAILURE;
        }

        // Everything below prompts. Fail fast (after any scaffold above) rather
        // than hanging on a picker under CI / --no-interaction.
        if (! $this->isInteractiveOrExplain($input, $io, "`boost remote`'s skill picker needs an interactive terminal. Declare the source by hand in boost.php (->withRemoteSkills([RemoteSkillSource::githubPath(...)])) and run `boost sync`, or run remote without --no-interaction.")) {
            return self::FAILURE;
        }

        $source = $this->askForSource($io, $input);
        if ($source === null) {
            return self::FAILURE;
        }

        $match = self::findEntry($config->remoteSkills, $source, $mode);
        $version = self::resolveVersion($match, is_string($input->getOption('ref')) ? $input->getOption('ref') : null);
        $mode = self::resolveMode($match, $mode);

        if ($match instanceof RemoteSkillSource) {
            $io->note(sprintf(
                '`%s` is already declared at version `%s` (%s mode) with %d skill(s). The picker starts from that selection.',
                $source,
                $match->version,
                $match->mode(),
                count($match->skills),
            ));
        }

        $io->writeln(sprintf('Reading <info>%s@%s</info> …', $source, $version));

        $discoverer = $this->discoverer ?? new RemoteSkillDiscoverer(new GitHubFetcher(new CurlHttpTransport()));

        try {
            $plan = $discoverer->plan($source, $version, $mode);
        } catch (RemoteFetchException $remoteFetchException) {
            $io->error($this->explainFetchFailure($remoteFetchException, $source, $version));

            return self::FAILURE;
        }

        if (! $this->confirmDownloads($io, $source, $plan)) {
            $io->writeln('Nothing was downloaded and boost.php is unchanged.');

            return self::SUCCESS;
        }

        $cache = $this->cache ?? new RemoteSkillCache(new GitHubFetcher(new CurlHttpTransport()));
        $workDir = $this->createWorkspace($cache);

        if ($workDir === null) {
            $io->error('Could not create a working directory to unpack into.');

            return self::FAILURE;
        }

        try {
            $discovered = $this->runDiscovery($io, $discoverer, $source, $version, $plan, $workDir);
            if ($discovered === []) {
                return self::FAILURE;
            }

            $io->section(sprintf('%s @ %s (%s mode) — %d skill(s)', $source, $plan->ref->resolved, $plan->mode, count($discovered)));

            $selectable = array_values(array_filter(
                $discovered,
                static fn (DiscoveredSkill $skill): bool => $skill->isSelectable(),
            ));

            $this->reportUnselectable($io, $discovered);

            if ($selectable === []) {
                $io->warning('None of the skills found can be declared, so nothing was cached and boost.php is unchanged.');

                return self::SUCCESS;
            }

            $selected = $this->select($io, $config, $discovered, $selectable, $match);

            if ($selected === []) {
                return $this->writeRemoval($io, $configPath, $source, $plan->mode, $match);
            }

            if (! $this->write($io, $configPath, $source, $version, $plan->mode, $selected)) {
                return self::FAILURE;
            }

            $this->closeTagGap($io, $configPath, $config, $selected);
            $this->cacheSelection($io, $cache, $source, $plan, $workDir, $selected);

            return $this->offerSync($io, $input, $output, $projectRoot);
        } finally {
            BundleExtractor::recursivelyRemove($workDir);
        }
    }

    /**
     * List the rows the picker won't offer, with the reason. Dropping them
     * silently is indistinguishable from a repo that never published them.
     *
     * @param  list<DiscoveredSkill>  $discovered
     */
    private function reportUnselectable(SymfonyStyle $io, array $discovered): void
    {
        foreach ($discovered as $skill) {
            if (! $skill->isSelectable()) {
                $io->writeln(sprintf(' <comment>✗ %s — %s</comment>', $skill->name, (string) $skill->problem));
            }
        }
    }

    /**
     * Run the picker and close the result over its dependencies, reporting
     * what got pulled in and what could not be satisfied here.
     *
     * @param  list<DiscoveredSkill>  $discovered
     * @param  list<DiscoveredSkill>  $selectable
     * @return list<DiscoveredSkill>
     */
    private function select(SymfonyStyle $io, BoostConfig $config, array $discovered, array $selectable, ?RemoteSkillSource $match): array
    {
        $closure = RemoteSelection::closeDependencies($discovered, $this->pick($config, $selectable, $match));

        foreach ($closure['pulled'] as $name => $demandedBy) {
            $io->writeln(sprintf(' <info>+ %s</info> — added because `%s` requires it.', $name, $demandedBy));
        }

        foreach ($closure['missing'] as $name => $dependents) {
            $io->warning(sprintf(
                '`%s` is required by %s but this repo does not publish it. Declare it from another source, or sync will warn that the dependency is missing.',
                $name,
                implode(', ', array_map(static fn (string $dependent): string => '`' . $dependent . '`', $dependents)),
            ));
        }

        return array_values(array_filter(
            $discovered,
            static fn (DiscoveredSkill $skill): bool => in_array($skill->name, $closure['names'], true),
        ));
    }

    /**
     * Resolve where `boost.php` lives, scaffolding a starter when the project
     * has none. Returns null when the caller should bail; the reason is
     * already on `$io`.
     */
    private function prepareConfig(SymfonyStyle $io, string $projectRoot, ?string $configOverride): ?string
    {
        try {
            $resolved = BoostConfigPath::resolve($projectRoot, $configOverride);
        } catch (Throwable $throwable) {
            $io->error($throwable->getMessage());

            return null;
        }

        $configPath = ConfigScaffolder::target($resolved, false, $projectRoot);
        if ($resolved->exists) {
            return $configPath;
        }

        try {
            ConfigScaffolder::scaffold($configPath);
        } catch (Throwable $throwable) {
            $io->error($throwable->getMessage());

            return null;
        }

        $io->success(sprintf('Generated starter %s', $configPath));

        return $configPath;
    }

    /**
     * The repo to read, from the argument or a prompt. Null when what was
     * given isn't a repo reference at all.
     */
    private function askForSource(SymfonyStyle $io, InputInterface $input): ?string
    {
        $raw = $input->getArgument('source');
        if (! is_string($raw) || trim($raw) === '') {
            $raw = text(
                label: 'Which GitHub repo publishes the skills?',
                placeholder: 'owner/repo',
                hint: 'A full GitHub URL works too.',
            );
        }

        $source = self::normalizeSource($raw);
        if ($source === null) {
            $io->error(sprintf(
                'Could not read `%s` as a GitHub repo. Use `<owner>/<repo>` (e.g. `peterfox/agent-skills`) or a github.com URL.',
                $raw,
            ));
        }

        return $source;
    }

    /**
     * Ask before spending a large number of bundle downloads. True = go ahead.
     */
    private function confirmDownloads(SymfonyStyle $io, string $source, DiscoveryPlan $plan): bool
    {
        if (! self::needsDownloadConfirmation($plan)) {
            return true;
        }

        $io->warning(sprintf(
            '%s publishes %d skill bundles at `%s`. Every one is downloaded so the picker can show its description, tags and dependencies. Anonymous GitHub requests are capped at 60/hour — set BOOST_GITHUB_TOKEN to raise that to 5000.',
            $source,
            $plan->downloadCount(),
            $plan->ref->resolved,
        ));

        return confirm(label: sprintf('Download %d bundles?', $plan->downloadCount()), default: true);
    }

    /**
     * Fetch and describe the repo's skills. An empty result means the caller
     * should bail — either nothing was found or a failure is already reported.
     *
     * @return list<DiscoveredSkill>
     */
    private function runDiscovery(
        SymfonyStyle $io,
        RemoteSkillDiscoverer $discoverer,
        string $source,
        string $version,
        DiscoveryPlan $plan,
        string $workDir,
    ): array {
        try {
            $discovered = $discoverer->discover($source, $plan, $workDir);
        } catch (RemoteFetchException $exception) {
            $io->error($this->explainFetchFailure($exception, $source, $version));

            return [];
        } catch (RemoteExtractException $exception) {
            $io->error(sprintf(
                'Refused the contents of `%s@%s`: %s Nothing was written.',
                $source,
                $plan->ref->resolved,
                $exception->getMessage(),
            ));

            return [];
        }

        if ($discovered === []) {
            $io->error(self::nothingFoundMessage($source, $plan));
        }

        return $discovered;
    }

    /**
     * The multiselect, pre-checked with whatever this source already declares
     * so the run is a diff of the existing entry rather than a fresh start.
     *
     * @param  list<DiscoveredSkill>  $selectable
     * @return list<string>
     */
    private function pick(BoostConfig $config, array $selectable, ?RemoteSkillSource $match): array
    {
        $existingSkills = $this->existingSkillNames($config);

        $declared = [];
        foreach ($match instanceof RemoteSkillSource ? $match->skills : [] as $ref) {
            $declared[$ref->name] = true;
        }

        $options = [];
        $defaults = [];
        foreach ($selectable as $skill) {
            $excluded = $config->excludesSkill($skill->name);
            $options[$skill->name] = RemoteSelection::pickerLabel($skill, $existingSkills, $config->tags, $excluded);
            if (isset($declared[$skill->name])) {
                $defaults[] = $skill->name;
            }
        }

        /** @var list<string> $picked */
        $picked = multiselect(
            label: 'Which skills should this project receive?',
            options: $options,
            default: $defaults,
            hint: 'Space to toggle, enter to confirm. Unchecking a skill removes it on the next sync.',
        );

        return $picked;
    }

    /**
     * Skill names the project already receives, mapped to where they come
     * from, so the picker can flag a pick that would shadow or collide.
     *
     * Host `.ai/` and vendor packages only — both are on local disk. Skills
     * from OTHER remote sources are deliberately not consulted: enumerating
     * them means fetching them, and a picker that hits the network to render
     * a label is worse than the rarer collision it would catch (which sync
     * still reports).
     *
     * @return array<string, string>  name => 'host' or vendor package name
     */
    private function existingSkillNames(BoostConfig $config): array
    {
        $loader = new SkillLoader(new FrontmatterParser());
        $names = [];

        try {
            foreach ($loader->load($config->skillsPath) as $skill) {
                $names[$skill->name] = 'host';
            }

            foreach ((new VendorScanner(InstalledPackages::fromComposer()))->discover() as $vendor) {
                if ($vendor->skillsPath === null) {
                    continue;
                }

                if (! $config->isVendorAllowed($vendor->name)) {
                    continue;
                }

                foreach ($loader->load($vendor->skillsPath, $vendor->name) as $skill) {
                    $names[$skill->name] ??= $vendor->name;
                }
            }
        } catch (Throwable) {
            // Annotations are advisory; a project whose sources can't be read
            // still gets a working picker, just without conflict labels.
        }

        return $names;
    }

    /**
     * @param  list<DiscoveredSkill>  $selected
     */
    private function write(SymfonyStyle $io, string $configPath, string $source, string $version, string $mode, array $selected): bool
    {
        try {
            $declaration = $mode === RemoteSkillSource::MODE_BUNDLE
                ? RemoteSkillSource::githubBundle($source, $version, array_map(
                    static fn (DiscoveredSkill $skill): string => $skill->name,
                    $selected,
                ))
                : RemoteSkillSource::githubPath($source, $version, $this->pathMap($selected));

            (new RemoteSkillsWriter())->write($configPath, $declaration);
        } catch (Throwable $throwable) {
            $io->error($throwable->getMessage());

            return false;
        }

        $io->success(sprintf('Declared %d skill(s) from %s@%s in %s', count($selected), $source, $version, $configPath));

        if (! RemoteSkillCache::isPinnedVersion($version, $mode)) {
            $io->note(sprintf(
                '`%s` is a moving ref: whatever %s publishes there is what the next sync pulls in. Re-run with --ref=<tag> to pin it.',
                $version,
                $source,
            ));
        }

        return true;
    }

    /**
     * The `name => repo-relative path` map `githubPath()` takes.
     *
     * @param  list<DiscoveredSkill>  $selected
     * @return array<string, string>
     */
    private function pathMap(array $selected): array
    {
        $map = [];
        foreach ($selected as $skill) {
            $map[$skill->name] = (string) $skill->path;
        }

        return $map;
    }

    /**
     * Everything was unchecked — drop the entry rather than leaving a stale
     * one, and say so when there was nothing to drop.
     */
    private function writeRemoval(SymfonyStyle $io, string $configPath, string $source, string $mode, ?RemoteSkillSource $match): int
    {
        if (! $match instanceof RemoteSkillSource) {
            $io->writeln('Nothing selected, so boost.php is unchanged.');

            return self::SUCCESS;
        }

        try {
            (new RemoteSkillsWriter())->remove($configPath, $source, $mode);
        } catch (Throwable $throwable) {
            $io->error($throwable->getMessage());

            return self::FAILURE;
        }

        $io->success(sprintf('Removed %s from %s. The next sync deletes the files it produced.', $source, $configPath));

        return self::SUCCESS;
    }

    /**
     * Offer to declare the tags the selection needs, so a skill can't end up
     * declared-but-filtered.
     *
     * Writes through `update()` with the config's CURRENT agents, vendors and
     * emitters re-passed — the writer has no tags-only entry point, and
     * re-passing what was just loaded leaves those arrays as they were.
     *
     * @param  list<DiscoveredSkill>  $selected
     */
    private function closeTagGap(SymfonyStyle $io, string $configPath, BoostConfig $config, array $selected): void
    {
        $missing = RemoteSelection::missingTags($selected, $config->tags);
        if ($missing === []) {
            return;
        }

        $io->warning(sprintf(
            'These tags are missing from withTags(), so the skills declaring them will not ship: %s',
            implode(', ', $missing),
        ));

        if (! confirm(label: 'Add them to withTags()?', default: true)) {
            $io->writeln('Left withTags() alone. Run <info>vendor/bin/boost tags</info> to review tag filtering.');

            return;
        }

        try {
            (new BoostConfigWriter())->update(
                $configPath,
                $config->agents,
                $config->allowedVendors,
                $config->disabledEmitters,
                [...$config->tags, ...$missing],
            );
            $io->success(sprintf('Added %s to withTags().', implode(', ', $missing)));
        } catch (Throwable $throwable) {
            $io->error(sprintf('Could not update withTags(): %s Add %s by hand.', $throwable->getMessage(), implode(', ', $missing)));
        }
    }

    /**
     * Ask whether to sync now. The config is already written either way, so a
     * failing sync is reported without being treated as a failure of this run.
     */
    private function offerSync(SymfonyStyle $io, InputInterface $input, OutputInterface $output, string $projectRoot): int
    {
        if (! confirm(label: 'Run `boost sync` now?', default: true)) {
            $io->writeln('Next: run <info>vendor/bin/boost sync</info> to generate the agent files.');

            return self::SUCCESS;
        }

        $sync = new SyncCommand();
        $sync->setApplication($this->getApplication());

        $arguments = ['--working-dir' => $projectRoot];
        $configOverride = $this->configFileOption($input);
        if ($configOverride !== null) {
            $arguments['--config'] = $configOverride;
        }

        $status = $sync->run(new ArrayInput($arguments), $output);

        if ($status !== self::SUCCESS) {
            $io->note('The sync did not finish cleanly, but boost.php is written — fix the reported problem and run `vendor/bin/boost sync` again.');
        }

        return self::SUCCESS;
    }

    /**
     * Where discovery unpacks. Inside the cache root when possible, so the
     * promotion afterwards is a rename rather than a cross-filesystem copy
     * that `rename()` refuses; the system temp dir is the fallback for an
     * unwritable cache, which then simply skips caching. Null = neither worked.
     */
    private function createWorkspace(RemoteSkillCache $cache): ?string
    {
        try {
            return $cache->createWorkspace();
        } catch (Throwable) {
            $fallback = sys_get_temp_dir() . '/boost-remote-' . bin2hex(random_bytes(8));

            return @mkdir($fallback, 0o755, recursive: true) || is_dir($fallback) ? $fallback : null;
        }
    }

    /**
     * Hand the selection to the cache so the sync offered next doesn't
     * re-download what discovery just read.
     *
     * Deliberately non-fatal: the selection is the operator's real work here,
     * and a cache that couldn't be written costs one re-fetch, not the run.
     *
     * @param  list<DiscoveredSkill>  $selected
     */
    private function cacheSelection(SymfonyStyle $io, RemoteSkillCache $cache, string $source, DiscoveryPlan $plan, string $workDir, array $selected): void
    {
        try {
            $cache->promoteDiscovery($source, $plan->ref, $plan->mode, $workDir, $selected);
        } catch (Throwable $throwable) {
            $io->note(sprintf(
                'Could not cache what was downloaded (%s). Nothing is lost — the next `boost sync` fetches it again.',
                $throwable->getMessage(),
            ));
        }
    }

    /**
     * Whether discovery is about to spend enough requests to be worth asking
     * about first.
     *
     * Path mode is always a single tarball, however large the repo, so it
     * never prompts — only bundle mode scales with the number of skills.
     */
    public static function needsDownloadConfirmation(DiscoveryPlan $plan): bool
    {
        return $plan->isBundle() && $plan->downloadCount() > self::CONFIRM_DOWNLOAD_THRESHOLD;
    }

    /**
     * Why a repo produced nothing, phrased for the mode discovery actually ran
     * in — "no releases" is useless advice for a repo that was scanned as a
     * directory tree.
     */
    public static function nothingFoundMessage(string $source, DiscoveryPlan $plan): string
    {
        return $plan->isBundle()
            ? sprintf(
                "The release `%s` of %s publishes no `.skill` assets. Try another --ref, or --mode=path to scan the repo's directories instead.",
                $plan->ref->resolved,
                $source,
            )
            : sprintf(
                '%s has no directory containing a SKILL.md at `%s`. Try another --ref, or check whether the repo publishes its skills as release assets (--mode=bundle).',
                $source,
                $plan->ref->resolved,
            );
    }

    private function explainFetchFailure(RemoteFetchException $exception, string $source, string $version): string
    {
        return match ($exception->reason) {
            RemoteFetchException::NOT_FOUND => sprintf(
                'Could not read `%s` at `%s`: %s If the repo is private, BOOST_GITHUB_TOKEN needs a token with access to it.',
                $source,
                $version,
                $exception->getMessage(),
            ),
            default => sprintf('Could not read `%s` at `%s`: %s', $source, $version, $exception->getMessage()),
        };
    }

    /**
     * Read an operator-supplied repo reference into the canonical
     * `<owner>/<repo>` form, or null when it isn't one.
     *
     * Accepts the shapes people actually paste: the bare slug, an https/http
     * URL (with or without `www.`, `.git`, a trailing slash, or a deeper path
     * such as `/tree/main`), and the `git@github.com:owner/repo.git` SSH form.
     */
    public static function normalizeSource(string $input): ?string
    {
        $value = trim($input);
        if ($value === '') {
            return null;
        }

        $value = preg_replace('#^git@github\.com:#i', '', $value) ?? $value;
        $value = preg_replace('#^(?:https?://)?(?:www\.)?github\.com/#i', '', $value) ?? $value;
        $value = trim($value, '/');

        // First two segments only: a pasted deep link still names the repo.
        $segments = explode('/', $value);
        if (count($segments) < 2) {
            return null;
        }

        $owner = $segments[0];
        $repo = preg_replace('/\.git$/i', '', $segments[1]) ?? $segments[1];
        $candidate = $owner . '/' . $repo;

        return RemoteSkillSource::isValidSource($candidate) ? $candidate : null;
    }

    /**
     * The declared source this run should update, matched on `(source, mode)`
     * and deliberately IGNORING the version.
     *
     * `RemoteSkillSource::uniqueKey()` includes the version, so matching on it
     * would make a re-run at a different ref append a SECOND entry for the same
     * repo — exactly the shape `RemoteSkillIngester` rejects as a collision.
     *
     * With no `--mode`, a single declared entry wins regardless of its mode
     * (so a re-run of a path-mode source keeps updating that source); when the
     * project declares BOTH modes for one repo, bundle wins, mirroring the
     * bundle-first rule discovery applies.
     *
     * @param  list<RemoteSkillSource>  $declared
     */
    public static function findEntry(array $declared, string $source, ?string $mode): ?RemoteSkillSource
    {
        $forSource = array_values(array_filter(
            $declared,
            static fn (RemoteSkillSource $candidate): bool => $candidate->source === $source,
        ));

        if ($forSource === []) {
            return null;
        }

        if ($mode !== null) {
            foreach ($forSource as $candidate) {
                if ($candidate->mode() === $mode) {
                    return $candidate;
                }
            }

            return null;
        }

        if (count($forSource) === 1) {
            return $forSource[0];
        }

        foreach ($forSource as $candidate) {
            if ($candidate->mode() === RemoteSkillSource::MODE_BUNDLE) {
                return $candidate;
            }
        }

        return $forSource[0];
    }

    /**
     * `--ref` wins; otherwise an already-declared entry keeps its version.
     *
     * The moving-ref default applies to NEW sources only: silently rewriting a
     * `v1.2.0` pin to `latest` because someone re-ran the picker to tick one
     * extra skill would un-pin production config as a side effect.
     */
    public static function resolveVersion(?RemoteSkillSource $match, ?string $refOption): string
    {
        if ($refOption !== null && trim($refOption) !== '') {
            return trim($refOption);
        }

        return $match instanceof RemoteSkillSource ? $match->version : self::DEFAULT_VERSION;
    }

    /**
     * `--mode` wins; otherwise an already-declared entry keeps its mode, so a
     * re-run never silently migrates a source from path to bundle. Null means
     * "undecided" — discovery picks, bundle first.
     */
    public static function resolveMode(?RemoteSkillSource $match, ?string $modeOption): ?string
    {
        return $modeOption ?? $match?->mode();
    }
}
