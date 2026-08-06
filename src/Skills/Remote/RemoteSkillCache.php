<?php declare(strict_types=1);

namespace SanderMuller\BoostCore\Skills\Remote;

use JsonException;
use Throwable;

/**
 * Local cache for remote skill sources.
 *
 * Owns the directory layout under `${XDG_CACHE_HOME:-$HOME/.cache}/boost/remote-skills/`:
 *
 *   <root>/<owner>__<repo>/
 *     .resolution-cache.json     TTL'd ref → resolved-SHA map (moving refs only)
 *     <resolved-ref>/
 *       <skill-name>/...          extracted skill content
 *       _repo/                    (path mode) raw tarball extract; shared
 *       .meta.json                provenance + per-skill SHA-256 tree hashes
 *
 * Public entry point is {@see ensureCached()}: takes a {@see RemoteSkillSource},
 * returns a {@see CachedSource} pointing at the slot. Resolves the ref (with
 * a 24h TTL for moving refs), fetches + extracts on miss, and verifies
 * SHA-256 tree hashes on hit.
 *
 * @internal
 */
final readonly class RemoteSkillCache
{
    public const MOVING_REF_TTL_SECONDS = 24 * 60 * 60;

    private string $cacheRoot;

    public function __construct(
        private RemoteFetcher $fetcher,
        private BundleExtractor $bundleExtractor = new BundleExtractor(),
        private TarballExtractor $tarballExtractor = new TarballExtractor(),
        ?string $cacheRoot = null,
    ) {
        $this->cacheRoot = $cacheRoot ?? self::resolveCacheRoot();
    }

    public function ensureCached(RemoteSkillSource $source): CachedSource
    {
        $resolved = $this->resolveWithCaching($source);
        $slotDir = $this->slotPath($source->source, $resolved->resolved);

        if ($this->isSlotFresh($slotDir, $source)) {
            return new CachedSource(slotDir: $slotDir, resolvedRef: $resolved->resolved);
        }

        $this->populateSlot($source, $resolved, $slotDir);

        return new CachedSource(slotDir: $slotDir, resolvedRef: $resolved->resolved);
    }

    /**
     * True iff the source is fully cached AND the cache slot's manifest
     * matches the declared skill set — i.e. `ensureCached()` would return
     * without any network call or disk write. Used by callers in
     * `--check` mode to decide whether a sync would have side effects:
     * a cache miss in check mode is a "would-fetch" advisory rather than
     * an actual fetch.
     *
     * For pinned versions (tag, 40-char SHA), checks the slot directly.
     * For moving refs (`main`, branch names), the resolution cache TTL
     * determines whether `resolveWithCaching` would re-fetch — we treat
     * a stale moving-ref resolution as "not ready", since the next sync
     * would touch the network.
     */
    public function isReadyOffline(RemoteSkillSource $source): bool
    {
        if (! self::isPinnedVersion($source->version, $source->mode())) {
            // Moving ref: check resolution-cache TTL without network.
            $cached = $this->readResolutionCache($source->source);
            $key = $source->version . ':' . $source->mode();
            if (! isset($cached[$key])
                || ! is_array($cached[$key])
                || ! isset($cached[$key]['resolved'], $cached[$key]['resolved_at'])
                || ! is_string($cached[$key]['resolved'])
                || ! is_int($cached[$key]['resolved_at'])
                || (time() - $cached[$key]['resolved_at']) >= self::MOVING_REF_TTL_SECONDS
            ) {
                return false;
            }

            $resolved = $cached[$key]['resolved'];
        } else {
            $resolved = $source->version;
        }

        return $this->isSlotFresh($this->slotPath($source->source, $resolved), $source);
    }

    /**
     * An empty directory for a discovery run to unpack into.
     *
     * Deliberately under the cache root rather than the system temp dir:
     * {@see promoteDiscovery()} promotes by renaming, and `rename()` cannot
     * cross a filesystem boundary. With `/tmp` on its own mount — the norm on
     * Linux — a system-temp workspace would make every promotion fail, and the
     * caller would quietly re-download on the next sync. Same reason
     * {@see populateSlot()} stages inside the cache root.
     *
     * The caller owns the directory and its removal.
     *
     * @throws RemoteExtractException
     */
    public function createWorkspace(): string
    {
        $workspace = $this->cacheRoot . '/.tmp/' . bin2hex(random_bytes(8));

        if (! @mkdir($workspace, 0o755, recursive: true) && ! is_dir($workspace)) {
            throw new RemoteExtractException(
                sprintf('Cannot create a workspace at `%s`.', $workspace),
                RemoteExtractException::DISK_FULL,
            );
        }

        return $workspace;
    }

    /**
     * Adopt a completed discovery run as this source's cache slot.
     *
     * `boost remote` has already fetched and unpacked the repo to answer "what
     * does this publish?". Throwing that away would make the sync it offers to
     * run immediately re-download everything it just read, so the directory is
     * pruned to the operator's selection and promoted into the canonical slot
     * instead.
     *
     * `$tempDir` must be laid out as `<tempDir>/<skill-name>/…` (what
     * {@see RemoteSkillDiscoverer::discover()} produces). It is consumed:
     * on success it becomes the slot, on failure it is left for the caller
     * to clean up.
     *
     * The recorded `asset` / `path` per skill MUST match what lands in
     * `withRemoteSkills(...)`, or {@see isSlotFresh()} rejects the slot on the
     * next sync and the saving is lost.
     *
     * @param  list<DiscoveredSkill>  $selected  the skills that will be declared — never empty
     *
     * @throws RemoteExtractException
     */
    public function promoteDiscovery(
        string $source,
        ResolvedRef $resolved,
        string $mode,
        string $tempDir,
        array $selected,
    ): CachedSource {
        if ($selected === []) {
            throw new RemoteExtractException(
                sprintf('Refusing to promote an empty selection for `%s`.', $source),
                RemoteExtractException::MALFORMED,
            );
        }

        $this->pruneToSelection($tempDir, $selected);
        $this->writeDiscoveryMeta($tempDir, $source, $resolved, $mode, $selected);

        $slotDir = $this->slotPath($source, $resolved->resolved);
        $parent = dirname($slotDir);
        if (! is_dir($parent) && ! @mkdir($parent, 0o755, recursive: true) && ! is_dir($parent)) {
            throw new RemoteExtractException(
                sprintf('Cannot create slot parent `%s`.', $parent),
                RemoteExtractException::DISK_FULL,
            );
        }

        BundleExtractor::recursivelyRemove($slotDir);

        if (! @rename($tempDir, $slotDir)) {
            throw new RemoteExtractException(
                sprintf('Atomic rename failed from `%s` to `%s`.', $tempDir, $slotDir),
                RemoteExtractException::DISK_FULL,
            );
        }

        // Discovery resolved the ref through the fetcher directly, bypassing the
        // TTL'd resolution cache. Record it, or a moving ref would still cost a
        // resolve call on the very next sync — with the content already on disk.
        if (! self::isPinnedVersion($resolved->requested, $mode)) {
            $cached = $this->readResolutionCache($source);
            $cached[$resolved->requested . ':' . $mode] = [
                'resolved' => $resolved->resolved,
                'resolved_at' => time(),
            ];
            $this->writeResolutionCache($source, $cached);
        }

        return new CachedSource(slotDir: $slotDir, resolvedRef: $resolved->resolved);
    }

    /**
     * Drop everything the operator didn't pick — unselected skills plus
     * discovery's own scratch directories (`.repo`, `.stage-*`). The slot then
     * holds exactly what `boost.php` declares.
     *
     * @param  list<DiscoveredSkill>  $selected
     */
    private function pruneToSelection(string $tempDir, array $selected): void
    {
        $keep = [];
        foreach ($selected as $skill) {
            $keep[$skill->name] = true;
        }

        $entries = @scandir($tempDir);
        if ($entries === false) {
            return;
        }

        foreach ($entries as $entry) {
            if ($entry === '.' || $entry === '..' || isset($keep[$entry])) {
                continue;
            }

            BundleExtractor::recursivelyRemove($tempDir . '/' . $entry);
        }
    }

    /**
     * {@see writeMeta()} for a discovery-built slot: same on-disk shape, keyed
     * off {@see DiscoveredSkill}s rather than a declared source.
     *
     * @param  list<DiscoveredSkill>  $selected
     *
     * @throws RemoteExtractException
     */
    private function writeDiscoveryMeta(
        string $slotDir,
        string $source,
        ResolvedRef $resolved,
        string $mode,
        array $selected,
    ): void {
        $refs = array_map(
            static fn (DiscoveredSkill $skill): RemoteSkillRef => new RemoteSkillRef(
                name: $skill->name,
                asset: $skill->asset,
                path: $skill->path,
            ),
            $selected,
        );

        $this->writeMetaFile($slotDir, $source, $resolved->requested, $resolved->resolved, $mode, $refs);
    }

    private function resolveWithCaching(RemoteSkillSource $source): ResolvedRef
    {
        if (self::isPinnedVersion($source->version, $source->mode())) {
            return new ResolvedRef(requested: $source->version, resolved: $source->version);
        }

        $cached = $this->readResolutionCache($source->source);
        $key = $source->version . ':' . $source->mode();
        $now = time();

        if (isset($cached[$key])
            && is_array($cached[$key])
            && isset($cached[$key]['resolved'], $cached[$key]['resolved_at'])
            && is_string($cached[$key]['resolved'])
            && is_int($cached[$key]['resolved_at'])
            && $now - $cached[$key]['resolved_at'] < self::MOVING_REF_TTL_SECONDS
        ) {
            return new ResolvedRef(requested: $source->version, resolved: $cached[$key]['resolved']);
        }

        $fresh = $this->fetcher->resolveRef($source->source, $source->version, $source->mode());
        $cached[$key] = ['resolved' => $fresh->resolved, 'resolved_at' => $now];
        $this->writeResolutionCache($source->source, $cached);

        return $fresh;
    }

    private function isSlotFresh(string $slotDir, RemoteSkillSource $source): bool
    {
        $metaPath = $slotDir . '/.meta.json';
        if (! is_file($metaPath)) {
            return false;
        }

        try {
            $raw = (string) file_get_contents($metaPath);
            $meta = json_decode($raw, true, flags: JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            return false;
        }

        if (! is_array($meta) || ! isset($meta['skills']) || ! is_array($meta['skills'])) {
            return false;
        }

        /** @var array<string,array<string,mixed>> $metaSkills */
        $metaSkills = $meta['skills'];

        foreach ($source->skills as $ref) {
            if (! isset($metaSkills[$ref->name]) || ! is_array($metaSkills[$ref->name])) {
                return false;
            }

            $skillDir = $slotDir . '/' . $ref->name;
            if (! is_dir($skillDir)) {
                return false;
            }

            // The cached slot was built for a specific (asset|path) mapping.
            // A user who changes the declared asset or path at the same ref
            // must re-fetch — otherwise the cache silently serves the prior
            // mapping's contents. Compare the recorded mapping (written by
            // writeMeta) against the currently declared one.
            $recordedAsset = is_string($metaSkills[$ref->name]['asset'] ?? null) ? $metaSkills[$ref->name]['asset'] : null;
            $recordedPath = is_string($metaSkills[$ref->name]['path'] ?? null) ? $metaSkills[$ref->name]['path'] : null;
            if ($recordedAsset !== $ref->asset || $recordedPath !== $ref->path) {
                return false;
            }

            $recordedSha = $metaSkills[$ref->name]['sha256'] ?? null;
            if (! is_string($recordedSha) || $recordedSha === '') {
                return false;
            }

            $currentSha = RemoteSkillCacheFilesystem::treeHash($skillDir);
            if (! hash_equals($recordedSha, $currentSha)) {
                return false; // tampered on disk → re-fetch
            }
        }

        return true;
    }

    private function populateSlot(RemoteSkillSource $source, ResolvedRef $resolved, string $slotDir): void
    {
        $tempDir = $this->cacheRoot . '/.tmp/' . bin2hex(random_bytes(8));
        if (! mkdir($tempDir, 0o755, recursive: true) && ! is_dir($tempDir)) {
            throw new RemoteExtractException(
                sprintf('Cannot create temp directory at `%s`.', $tempDir),
                RemoteExtractException::DISK_FULL,
            );
        }

        try {
            if ($source->mode() === RemoteSkillSource::MODE_BUNDLE) {
                $this->populateBundleSlot($source, $resolved, $tempDir);
            } else {
                $this->populatePathSlot($source, $resolved, $tempDir);
            }

            $this->writeMeta($tempDir, $source, $resolved);

            // Atomic promotion: parent must exist before rename.
            $parent = dirname($slotDir);
            if (! is_dir($parent) && ! mkdir($parent, 0o755, recursive: true) && ! is_dir($parent)) {
                throw new RemoteExtractException(
                    sprintf('Cannot create slot parent `%s`.', $parent),
                    RemoteExtractException::DISK_FULL,
                );
            }

            // Remove any previous slot at this path (a tampered or partial one) before rename.
            BundleExtractor::recursivelyRemove($slotDir);

            if (! @rename($tempDir, $slotDir)) {
                throw new RemoteExtractException(
                    sprintf('Atomic rename failed from `%s` to `%s`.', $tempDir, $slotDir),
                    RemoteExtractException::DISK_FULL,
                );
            }
        } catch (Throwable $throwable) {
            BundleExtractor::recursivelyRemove($tempDir);
            throw $throwable;
        }
    }

    private function populateBundleSlot(RemoteSkillSource $source, ResolvedRef $resolved, string $tempDir): void
    {
        // The Anthropic `.skill` ZIP format wraps in `<skill-name>/...`; we
        // extract to the slot's tempDir so the wrapper lands as
        // `<temp>/<skill-name>/` and `skillPath()` resolves naturally.
        // (Wrapper-name vs declared `RemoteSkillRef::name` mismatch is
        // caught at ingest time via the frontmatter `name` check.)
        foreach ($source->skills as $ref) {
            $assetName = (string) $ref->asset;
            $assetPath = $tempDir . '/.dl-' . $ref->name . '.skill';
            $this->fetcher->fetchAsset($source->source, $resolved, $assetName, $assetPath);

            $this->bundleExtractor->extract($assetPath, $tempDir);
            @unlink($assetPath);
        }
    }

    private function populatePathSlot(RemoteSkillSource $source, ResolvedRef $resolved, string $tempDir): void
    {
        // One tarball serves every path-mode skill from this source. Shared
        // extract at `_repo/`; per-skill subdirs are copied from the
        // requested in-archive paths.
        $tarballPath = $tempDir . '/.dl-tarball.tar.gz';
        $this->fetcher->fetchTarball($source->source, $resolved, $tarballPath);

        $repoDir = $tempDir . '/.repo';
        $this->tarballExtractor->extract($tarballPath, $repoDir);
        @unlink($tarballPath);

        // The tarball extracts to `_repo/<owner>-<repo>-<short-sha>/...` —
        // strip the GitHub wrapper directory to land on the repo root.
        $unwrapped = RemoteSkillCacheFilesystem::firstSingleSubdir($repoDir);

        foreach ($source->skills as $ref) {
            $skillSourcePath = $unwrapped . '/' . ($ref->path === '.' ? '' : ltrim((string) $ref->path, '/'));
            $skillSourcePath = rtrim($skillSourcePath, '/');

            if (! is_dir($skillSourcePath)) {
                throw new RemoteExtractException(
                    sprintf('Path `%s` not found in `%s@%s`.', $ref->path, $source->source, $resolved->resolved),
                    RemoteExtractException::MALFORMED,
                );
            }

            $skillDestDir = $tempDir . '/' . $ref->name;
            RemoteSkillCacheFilesystem::copyTreeFiltered($skillSourcePath, $skillDestDir);
        }
    }

    private function writeMeta(string $slotDir, RemoteSkillSource $source, ResolvedRef $resolved): void
    {
        $this->writeMetaFile(
            $slotDir,
            $source->source,
            $source->version,
            $resolved->resolved,
            $source->mode(),
            $source->skills,
        );
    }

    /**
     * Write a slot's `.meta.json`: provenance plus a per-skill SHA-256 tree
     * hash and the `asset` / `path` mapping it was built for.
     *
     * {@see isSlotFresh()} reads all three back, so a slot built here is only
     * reusable by a config declaring the same mapping.
     *
     * @param  list<RemoteSkillRef>  $refs
     *
     * @throws RemoteExtractException
     */
    private function writeMetaFile(
        string $slotDir,
        string $source,
        string $version,
        string $resolved,
        string $mode,
        array $refs,
    ): void {
        /** @var array<string,array<string,mixed>> $skills */
        $skills = [];
        foreach ($refs as $ref) {
            $skillDir = $slotDir . '/' . $ref->name;
            if (! is_dir($skillDir)) {
                throw new RemoteExtractException(
                    sprintf('No directory for skill `%s` at `%s`.', $ref->name, $skillDir),
                    RemoteExtractException::MALFORMED,
                );
            }

            $entry = ['sha256' => RemoteSkillCacheFilesystem::treeHash($skillDir)];
            if ($ref->asset !== null) {
                $entry['asset'] = $ref->asset;
            }

            if ($ref->path !== null) {
                $entry['path'] = $ref->path;
            }

            $skills[$ref->name] = $entry;
        }

        $json = json_encode([
            'source' => $source,
            'version' => $version,
            'resolved' => $resolved,
            'mode' => $mode,
            'fetched_at' => gmdate('c'),
            'skills' => $skills,
        ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);

        if ($json === false) {
            throw new RemoteExtractException('Failed to encode .meta.json.', RemoteExtractException::MALFORMED);
        }

        if (@file_put_contents($slotDir . '/.meta.json', $json) === false) {
            throw new RemoteExtractException(
                sprintf('Failed to write .meta.json at `%s`.', $slotDir),
                RemoteExtractException::DISK_FULL,
            );
        }
    }

    private function slotPath(string $source, string $resolved): string
    {
        return $this->cacheRoot . '/' . self::slug($source) . '/' . $resolved;
    }

    /**
     * @return array<string, mixed>
     */
    private function readResolutionCache(string $source): array
    {
        $path = $this->cacheRoot . '/' . self::slug($source) . '/.resolution-cache.json';
        if (! is_file($path)) {
            return [];
        }

        try {
            $raw = (string) file_get_contents($path);
            $decoded = json_decode($raw, true, flags: JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            return [];
        }

        /** @var array<string, mixed> $result */
        $result = is_array($decoded) ? $decoded : [];

        return $result;
    }

    /**
     * @param  array<string, mixed>  $cache
     */
    private function writeResolutionCache(string $source, array $cache): void
    {
        $dir = $this->cacheRoot . '/' . self::slug($source);
        if (! is_dir($dir) && ! @mkdir($dir, 0o755, recursive: true) && ! is_dir($dir)) {
            return; // best-effort; non-writable cache degrades to re-resolve every sync.
        }

        $json = json_encode($cache, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
        if ($json === false) {
            return;
        }

        @file_put_contents($dir . '/.resolution-cache.json', $json);
    }

    /**
     * The on-disk slug for a `<owner>/<repo>` source. The `__` separator is
     * Composer-name-spec-disallowed, so distinct remote sources always produce
     * distinct slugs (and no slug collides with a Composer vendor's slug
     * shape). Public so callers outside the cache — `boost doctor`, the
     * orphan pruner — share one definition.
     */
    public static function slug(string $source): string
    {
        return str_replace('/', '__', $source);
    }

    /**
     * True when `$version` is content-addressed and cache-forever-eligible:
     * a tag (`v1.2.3`-shape) or a full 40-char SHA. Moving refs (`'main'`,
     * `'latest'`, branch names) re-resolve through the 24h TTL — public so
     * `boost doctor` can surface them with a warning indicator.
     */
    public static function isPinnedVersion(string $version, string $mode): bool
    {
        if ($version === 'latest') {
            return false;
        }

        if ($mode === RemoteSkillSource::MODE_BUNDLE) {
            // Bundle versions are tag or 'latest' (factory enforces); a tag is content-addressed.
            return true;
        }

        // Path mode: pinned iff full-SHA or tag-shape.
        if (preg_match('/^[0-9a-f]{40}$/i', $version) === 1) {
            return true;
        }

        return preg_match('/^v?\d+(?:\.\d+)*(?:[-.][\w.]+)?$/', $version) === 1;
    }

    public static function resolveCacheRoot(): string
    {
        $override = getenv('BOOST_CACHE_HOME');
        if (is_string($override) && $override !== '') {
            return rtrim($override, '/') . '/boost/remote-skills';
        }

        $xdg = getenv('XDG_CACHE_HOME');
        if (is_string($xdg) && $xdg !== '') {
            return rtrim($xdg, '/') . '/boost/remote-skills';
        }

        $home = getenv('HOME');
        if (is_string($home) && $home !== '') {
            return rtrim($home, '/') . '/.cache/boost/remote-skills';
        }

        $userProfile = getenv('USERPROFILE');
        if (is_string($userProfile) && $userProfile !== '') {
            return rtrim($userProfile, '/\\') . '/.cache/boost/remote-skills';
        }

        return rtrim(sys_get_temp_dir(), '/') . '/boost-remote-skills';
    }
}
