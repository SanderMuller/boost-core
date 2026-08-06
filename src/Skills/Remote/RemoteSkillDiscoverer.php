<?php declare(strict_types=1);

namespace SanderMuller\BoostCore\Skills\Remote;

use SanderMuller\BoostCore\Skills\BoostRequires;
use SanderMuller\BoostCore\Skills\BoostTags;
use SanderMuller\BoostCore\Skills\FrontmatterParser;

/**
 * Reads what a GitHub repo offers, before anything is declared in `boost.php`.
 *
 * The counterpart to {@see RemoteSkillCache}, which can only fetch skills a
 * config already names. Discovery runs the other way round: fetch first, then
 * let the operator pick.
 *
 * Two steps on purpose. {@see plan()} spends only cheap API calls and reports
 * how many downloads {@see discover()} would cost, so the caller can confirm
 * with the operator before a repo with dozens of release assets eats an
 * anonymous rate limit.
 *
 * `discover()` lays its results out as `<workDir>/<skill-name>/…` — the same
 * shape a cache slot uses — so the selection can be promoted into the cache
 * without a second fetch.
 *
 * @internal
 */
final readonly class RemoteSkillDiscoverer
{
    /** The Anthropic Claude Code Skills bundle extension. */
    private const ASSET_SUFFIX = '.skill';

    public function __construct(
        private RemoteFetcher $fetcher,
        private BundleExtractor $bundleExtractor = new BundleExtractor(),
        private TarballExtractor $tarballExtractor = new TarballExtractor(),
        private FrontmatterParser $frontmatterParser = new FrontmatterParser(),
    ) {}

    /**
     * Decide the mode and resolve the ref, without downloading skill content.
     *
     * With no `$modeOverride`, bundle wins when the repo publishes `.skill`
     * release assets: those are the maintainer's deliberate publication form.
     * A repo with no releases — or a release carrying no `.skill` assets —
     * falls through to path mode. Anything other than a not-found (a rate
     * limit, an auth failure) propagates instead of being read as "no
     * releases here".
     *
     * @throws RemoteFetchException
     */
    public function plan(string $source, string $version, ?string $modeOverride = null): DiscoveryPlan
    {
        if ($modeOverride === RemoteSkillSource::MODE_PATH) {
            return new DiscoveryPlan(
                mode: RemoteSkillSource::MODE_PATH,
                ref: $this->fetcher->resolveRef($source, $version, RemoteSkillSource::MODE_PATH),
            );
        }

        if ($modeOverride === RemoteSkillSource::MODE_BUNDLE) {
            $ref = $this->fetcher->resolveRef($source, $version, RemoteSkillSource::MODE_BUNDLE);

            return new DiscoveryPlan(
                mode: RemoteSkillSource::MODE_BUNDLE,
                ref: $ref,
                assets: $this->fetcher->listReleaseAssets($source, $ref),
            );
        }

        try {
            $ref = $this->fetcher->resolveRef($source, $version, RemoteSkillSource::MODE_BUNDLE);
            $assets = $this->fetcher->listReleaseAssets($source, $ref);

            if ($assets !== []) {
                return new DiscoveryPlan(mode: RemoteSkillSource::MODE_BUNDLE, ref: $ref, assets: $assets);
            }
        } catch (RemoteFetchException $exception) {
            if ($exception->reason !== RemoteFetchException::NOT_FOUND) {
                throw $exception;
            }
        }

        return new DiscoveryPlan(
            mode: RemoteSkillSource::MODE_PATH,
            ref: $this->fetcher->resolveRef($source, $version, RemoteSkillSource::MODE_PATH),
        );
    }

    /**
     * Fetch and describe every skill the plan covers.
     *
     * `$workDir` must exist and be writable; the caller owns it (and its
     * removal). Selectable skills end up at `<workDir>/<name>/`; unselectable
     * ones stay in their staging directory, since nothing may declare them.
     *
     * @return list<DiscoveredSkill>
     *
     * @throws RemoteFetchException
     * @throws RemoteExtractException
     */
    public function discover(string $source, DiscoveryPlan $plan, string $workDir): array
    {
        $staged = $plan->isBundle()
            ? $this->stageBundles($source, $plan, $workDir)
            : $this->stagePaths($source, $plan, $workDir);

        return $this->layOut($this->markDuplicates($staged), $workDir);
    }

    /**
     * @return list<array{dir: string, skill: DiscoveredSkill}>
     *
     * @throws RemoteFetchException
     * @throws RemoteExtractException
     */
    private function stageBundles(string $source, DiscoveryPlan $plan, string $workDir): array
    {
        $staged = [];

        foreach ($plan->assets as $index => $asset) {
            $archivePath = sprintf('%s/.dl-%d%s', $workDir, $index, self::ASSET_SUFFIX);
            $this->fetcher->fetchAsset($source, $plan->ref, $asset['asset'], $archivePath);

            // One staging dir per asset: the ZIP's wrapper directory name is
            // unknown until it unpacks, so side-by-side extracts are ambiguous.
            $stageDir = sprintf('%s/.stage-%d', $workDir, $index);
            $this->makeDirectory($stageDir);
            $this->bundleExtractor->extract($archivePath, $stageDir);
            @unlink($archivePath);

            $skillDir = RemoteSkillCacheFilesystem::firstSingleSubdir($stageDir);
            $skill = $this->describe($skillDir, $asset['name'], null, $asset['asset']);

            $staged[] = ['dir' => $skillDir, 'skill' => $this->checkAssetName($skill, $asset['asset'])];
        }

        return $staged;
    }

    /**
     * @return list<array{dir: string, skill: DiscoveredSkill}>
     *
     * @throws RemoteFetchException
     * @throws RemoteExtractException
     */
    private function stagePaths(string $source, DiscoveryPlan $plan, string $workDir): array
    {
        $archivePath = $workDir . '/.dl-tarball.tar.gz';
        $this->fetcher->fetchTarball($source, $plan->ref, $archivePath);

        $repoDir = $workDir . '/.repo';
        $this->tarballExtractor->extract($archivePath, $repoDir);
        @unlink($archivePath);

        $root = RemoteSkillCacheFilesystem::firstSingleSubdir($repoDir);

        $staged = [];
        foreach ($this->findSkillDirectories($root) as $relativePath) {
            $absolute = $relativePath === '.' ? $root : $root . '/' . $relativePath;
            $fallbackName = $relativePath === '.' ? basename($root) : basename($relativePath);

            $staged[] = [
                'dir' => $absolute,
                'skill' => $this->describe($absolute, $fallbackName, $relativePath, null),
            ];
        }

        return $staged;
    }

    /**
     * Every directory holding a `SKILL.md`, repo-relative, `.` for the root.
     *
     * A `SKILL.md` nested UNDER one that was already found is skipped: it
     * belongs to the parent skill's bundled resources (a reference doc,
     * an example), not a separate skill the repo publishes.
     *
     * @return list<string>
     */
    private function findSkillDirectories(string $root): array
    {
        if (is_file($root . '/SKILL.md')) {
            return ['.'];
        }

        $found = [];
        $this->walkForSkills($root, '', $found);
        sort($found);

        return $found;
    }

    /**
     * @param  list<string>  $found
     */
    private function walkForSkills(string $dir, string $prefix, array &$found): void
    {
        $entries = @scandir($dir);
        if ($entries === false) {
            return;
        }

        foreach ($entries as $entry) {
            if ($entry === '.' || $entry === '..') {
                continue;
            }

            $absolute = $dir . '/' . $entry;
            if (! is_dir($absolute)) {
                continue;
            }

            $relative = $prefix === '' ? $entry : $prefix . '/' . $entry;
            if (RemoteSkillCacheFilesystem::isBlockedEntry($relative)) {
                continue;
            }

            if (is_file($absolute . '/SKILL.md')) {
                $found[] = $relative;

                continue; // Don't descend — nested SKILL.md files are this skill's own resources.
            }

            $this->walkForSkills($absolute, $relative, $found);
        }
    }

    /**
     * Read one skill directory's `SKILL.md` into a {@see DiscoveredSkill}.
     *
     * Every defect produces a row with a `$problem` rather than an omission:
     * an operator who can't find the skill they came for needs to know it was
     * seen and rejected, and why.
     */
    private function describe(string $directory, string $fallbackName, ?string $path, ?string $asset): DiscoveredSkill
    {
        $skillFile = $directory . '/SKILL.md';
        if (! is_file($skillFile)) {
            return new DiscoveredSkill(
                name: $fallbackName,
                description: null,
                tags: [],
                requires: [],
                path: $path,
                asset: $asset,
                problem: 'no SKILL.md found',
            );
        }

        $parsed = $this->frontmatterParser->parse((string) file_get_contents($skillFile));
        $frontmatter = $parsed->frontmatter;

        $name = $frontmatter['name'] ?? null;
        if (! is_string($name) || trim($name) === '') {
            return new DiscoveredSkill(
                name: $fallbackName,
                description: null,
                tags: [],
                requires: [],
                path: $path,
                asset: $asset,
                problem: 'SKILL.md frontmatter declares no `name`',
            );
        }

        $name = trim($name);
        $description = $frontmatter['description'] ?? null;
        [$tags, $tagsValid] = BoostTags::parse($frontmatter);
        [$requires] = BoostRequires::parse($frontmatter);

        return new DiscoveredSkill(
            name: $name,
            description: is_string($description) ? trim($description) : null,
            tags: $tags,
            requires: $requires,
            path: $path,
            asset: $asset,
            // Malformed tags are fail-closed in the engine — the skill would
            // never ship. Malformed requires only degrade dependency pulling.
            problem: $tagsValid ? null : 'malformed `metadata.boost-tags` — the skill would be filtered out at sync',
        );
    }

    /**
     * `githubBundle()` derives each asset name as `<skill-name>.skill`, so a
     * bundle whose frontmatter name doesn't match its asset can't be expressed
     * in the config this command writes.
     */
    private function checkAssetName(DiscoveredSkill $skill, string $asset): DiscoveredSkill
    {
        if (! $skill->isSelectable() || $skill->name . self::ASSET_SUFFIX === $asset) {
            return $skill;
        }

        return $skill->withProblem(sprintf(
            'frontmatter name `%s` does not match asset `%s`, so it cannot be declared via githubBundle()',
            $skill->name,
            $asset,
        ));
    }

    /**
     * Two skills claiming one name would make `RemoteSkillSource` throw on the
     * very next config load, so neither is offered.
     *
     * @param  list<array{dir: string, skill: DiscoveredSkill}>  $staged
     * @return list<array{dir: string, skill: DiscoveredSkill}>
     */
    private function markDuplicates(array $staged): array
    {
        $counts = [];
        foreach ($staged as $entry) {
            $counts[$entry['skill']->name] = ($counts[$entry['skill']->name] ?? 0) + 1;
        }

        foreach ($staged as $index => $entry) {
            if ($entry['skill']->isSelectable() && ($counts[$entry['skill']->name] ?? 0) > 1) {
                $staged[$index]['skill'] = $entry['skill']->withProblem(
                    sprintf('the repo publishes more than one skill named `%s`', $entry['skill']->name),
                );
            }
        }

        return $staged;
    }

    /**
     * Move every selectable skill to `<workDir>/<name>/` so the caller can
     * promote a selection into a cache slot verbatim.
     *
     * Each mode copies the way {@see RemoteSkillCache} copies for that mode, or
     * a promoted slot would hold different bytes than a plain fetch: a bundle
     * is unzipped whole, while a repo subdirectory goes through the file
     * blocklist that strips VCS and project-metadata noise.
     *
     * @param  list<array{dir: string, skill: DiscoveredSkill}>  $staged
     * @return list<DiscoveredSkill>
     *
     * @throws RemoteExtractException
     */
    private function layOut(array $staged, string $workDir): array
    {
        $skills = [];
        foreach ($staged as $entry) {
            $skill = $entry['skill'];
            $skills[] = $skill;

            if (! $skill->isSelectable()) {
                continue;
            }

            $target = $workDir . '/' . $skill->name;
            if ($entry['dir'] === $target) {
                continue;
            }

            if ($skill->asset !== null) {
                $this->moveDirectory($entry['dir'], $target);

                continue;
            }

            RemoteSkillCacheFilesystem::copyTreeFiltered($entry['dir'], $target);
        }

        return $skills;
    }

    /**
     * @throws RemoteExtractException
     */
    private function moveDirectory(string $from, string $to): void
    {
        if (! @rename($from, $to)) {
            throw new RemoteExtractException(
                sprintf('Cannot move `%s` to `%s`.', $from, $to),
                RemoteExtractException::DISK_FULL,
            );
        }
    }

    /**
     * @throws RemoteExtractException
     */
    private function makeDirectory(string $dir): void
    {
        if (! is_dir($dir) && ! @mkdir($dir, 0o755, recursive: true) && ! is_dir($dir)) {
            throw new RemoteExtractException(
                sprintf('Cannot create directory `%s`.', $dir),
                RemoteExtractException::DISK_FULL,
            );
        }
    }
}
