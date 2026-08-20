<?php declare(strict_types=1);

use SanderMuller\BoostCore\Skills\Remote\BundleExtractor;
use SanderMuller\BoostCore\Skills\Remote\DiscoveredSkill;
use SanderMuller\BoostCore\Skills\Remote\RemoteExtractException;
use SanderMuller\BoostCore\Skills\Remote\RemoteSkillCache;
use SanderMuller\BoostCore\Skills\Remote\RemoteSkillDiscoverer;
use SanderMuller\BoostCore\Skills\Remote\RemoteSkillSource;
use SanderMuller\BoostCore\Skills\Remote\ResolvedRef;
use SanderMuller\BoostCore\Tests\Doubles\Remote\FakeRemoteFetcher;

function cacheTempRoot(): string
{
    return sys_get_temp_dir() . '/boost-cache-' . bin2hex(random_bytes(6));
}

/**
 * Build a minimal `.skill` ZIP (SKILL.md only) into a string and return it.
 * Used by the FakeRemoteFetcher's `withAsset` body.
 */
function cacheMakeBundleBytes(string $skillName, string $body = 'Body.'): string
{
    $tmpZip = sys_get_temp_dir() . '/boost-cache-bundle-build-' . bin2hex(random_bytes(6)) . '.zip';
    $zip = new ZipArchive();
    $zip->open($tmpZip, ZipArchive::CREATE);
    $zip->addFromString($skillName . '/SKILL.md', "---\nname: {$skillName}\n---\n{$body}");
    $zip->close();

    $bytes = (string) file_get_contents($tmpZip);
    @unlink($tmpZip);

    return $bytes;
}

/**
 * Build a minimal `.tar.gz` representing a github tarball-shape:
 * top-level wrapper dir (`<owner>-<repo>-<short-sha>/`) containing one
 * skill directory with `SKILL.md`.
 */
function cacheMakeTarballBytes(string $wrapperDir, string $skillName, string $skillPath, string $body = 'Body.'): string
{
    $base = sys_get_temp_dir() . '/boost-cache-tar-build-' . bin2hex(random_bytes(6));
    $tar = $base . '.tar';
    @unlink($tar);
    @unlink($base . '.tar.gz');

    $phar = new PharData($tar);
    $entryPath = $wrapperDir . '/' . ($skillPath === '.' ? '' : rtrim($skillPath, '/') . '/');
    $phar->addFromString($entryPath . 'SKILL.md', "---\nname: {$skillName}\n---\n{$body}");
    $phar->compress(Phar::GZ);
    unset($phar);
    @unlink($tar);

    $bytes = (string) file_get_contents($base . '.tar.gz');
    @unlink($base . '.tar.gz');

    return $bytes;
}

// ---------- Bundle-mode cache miss → write → hit round-trip ----------

it('cache miss → fetches asset → extracts → writes .meta.json → returns slot dir', function (): void {
    $root = cacheTempRoot();
    $fetcher = (new FakeRemoteFetcher())
        ->withResolvedRef('peterfox/agent-skills', 'v1.2.0', RemoteSkillSource::MODE_BUNDLE, 'v1.2.0')
        ->withAsset('peterfox/agent-skills', 'v1.2.0', 'composer-upgrade.skill', cacheMakeBundleBytes('composer-upgrade'));

    try {
        $cache = new RemoteSkillCache(fetcher: $fetcher, cacheRoot: $root);
        $source = RemoteSkillSource::githubBundle('peterfox/agent-skills', 'v1.2.0', ['composer-upgrade']);

        $cached = $cache->ensureCached($source);

        expect($cached->resolvedRef)->toBe('v1.2.0')
            ->and(is_dir($cached->slotDir))->toBeTrue()
            ->and(is_file($cached->slotDir . '/.meta.json'))->toBeTrue()
            ->and(is_file($cached->skillPath($source->skills[0]) . '/SKILL.md'))->toBeTrue();
    } finally {
        BundleExtractor::recursivelyRemove($root);
    }
});

it('cache hit on second call — does not re-fetch', function (): void {
    $root = cacheTempRoot();
    $fetcher = (new FakeRemoteFetcher())
        ->withResolvedRef('a/b', 'v1.0', RemoteSkillSource::MODE_BUNDLE, 'v1.0')
        ->withAsset('a/b', 'v1.0', 'foo.skill', cacheMakeBundleBytes('foo'));

    try {
        $cache = new RemoteSkillCache(fetcher: $fetcher, cacheRoot: $root);
        $source = RemoteSkillSource::githubBundle('a/b', 'v1.0', ['foo']);

        $cache->ensureCached($source);

        // Second call: if the cache hit didn't work, FakeRemoteFetcher would
        // throw NOT_FOUND on a second fetchAsset for the same (key, asset)
        // — but it doesn't pop, so we can't directly assert "didn't refetch."
        // Instead: delete the asset registration, then call ensureCached
        // again. A successful return proves cache was used.
        $emptyFetcher = new FakeRemoteFetcher();
        $cacheReused = new RemoteSkillCache(fetcher: $emptyFetcher, cacheRoot: $root);
        $cached = $cacheReused->ensureCached($source);

        expect($cached->slotDir . '/.meta.json')
            ->toBeFile();
    } finally {
        BundleExtractor::recursivelyRemove($root);
    }
});

it('SHA verification rejects tampered cache content — treats as miss', function (): void {
    $root = cacheTempRoot();
    $fetcher = (new FakeRemoteFetcher())
        ->withResolvedRef('a/b', 'v1.0', RemoteSkillSource::MODE_BUNDLE, 'v1.0')
        ->withAsset('a/b', 'v1.0', 'foo.skill', cacheMakeBundleBytes('foo'));

    try {
        $cache = new RemoteSkillCache(fetcher: $fetcher, cacheRoot: $root);
        $source = RemoteSkillSource::githubBundle('a/b', 'v1.0', ['foo']);

        $cached = $cache->ensureCached($source);

        // Tamper: rewrite SKILL.md.
        file_put_contents($cached->skillPath($source->skills[0]) . '/SKILL.md', 'TAMPERED');

        // Re-register the asset (so the second fetch can succeed) and
        // call again. Cache should detect tampering via SHA verify and re-fetch.
        $refreshedFetcher = (new FakeRemoteFetcher())
            ->withResolvedRef('a/b', 'v1.0', RemoteSkillSource::MODE_BUNDLE, 'v1.0')
            ->withAsset('a/b', 'v1.0', 'foo.skill', cacheMakeBundleBytes('foo'));
        $cache2 = new RemoteSkillCache(fetcher: $refreshedFetcher, cacheRoot: $root);
        $cached2 = $cache2->ensureCached($source);

        expect((string) file_get_contents($cached2->skillPath($source->skills[0]) . '/SKILL.md'))
            ->toContain('Body.')
            ->not->toBe('TAMPERED');
    } finally {
        BundleExtractor::recursivelyRemove($root);
    }
});

// ---------- Path-mode round trip ----------

it('path-mode cache miss → fetches tarball → extracts subdir → returns slot dir', function (): void {
    $root = cacheTempRoot();
    $fetcher = (new FakeRemoteFetcher())
        ->withResolvedRef('mattpocock/skills', 'main', RemoteSkillSource::MODE_PATH, 'abc123')
        ->withTarball(
            'mattpocock/skills',
            'abc123',
            cacheMakeTarballBytes('mattpocock-skills-abc123', 'grill-with-docs', 'skills/engineering/grill-with-docs'),
        );

    try {
        $cache = new RemoteSkillCache(fetcher: $fetcher, cacheRoot: $root);
        $source = RemoteSkillSource::githubPath('mattpocock/skills', 'main', [
            'grill-with-docs' => 'skills/engineering/grill-with-docs',
        ]);

        $cached = $cache->ensureCached($source);

        expect($cached->resolvedRef)->toBe('abc123')
            ->and(is_file($cached->skillPath($source->skills[0]) . '/SKILL.md'))->toBeTrue();
    } finally {
        BundleExtractor::recursivelyRemove($root);
    }
});

// ---------- Pinned vs moving ref TTL behavior ----------

it('pinned tag bypasses the resolution cache (no fetcher call for resolveRef)', function (): void {
    $root = cacheTempRoot();
    // Note: only `fetchAsset` is registered, NOT `resolveRef`. For pinned
    // versions the cache must NOT call the fetcher's resolveRef — would
    // throw NOT_FOUND otherwise.
    $fetcher = (new FakeRemoteFetcher())
        ->withAsset('a/b', 'v1.0', 'foo.skill', cacheMakeBundleBytes('foo'));

    try {
        $cache = new RemoteSkillCache(fetcher: $fetcher, cacheRoot: $root);
        $source = RemoteSkillSource::githubBundle('a/b', 'v1.0', ['foo']);

        $cached = $cache->ensureCached($source);

        expect($cached->resolvedRef)->toBe('v1.0');
    } finally {
        BundleExtractor::recursivelyRemove($root);
    }
});

it('moving ref (`latest`) calls resolveRef and caches the resolution', function (): void {
    $root = cacheTempRoot();
    $fetcher = (new FakeRemoteFetcher())
        ->withResolvedRef('a/b', 'latest', RemoteSkillSource::MODE_BUNDLE, 'v2.3.4')
        ->withAsset('a/b', 'v2.3.4', 'foo.skill', cacheMakeBundleBytes('foo'));

    try {
        $cache = new RemoteSkillCache(fetcher: $fetcher, cacheRoot: $root);
        $source = RemoteSkillSource::githubBundle('a/b', 'latest', ['foo']);

        $cached = $cache->ensureCached($source);

        expect($cached->resolvedRef)->toBe('v2.3.4')
            ->and(is_file($root . '/a__b/.resolution-cache.json'))->toBeTrue();
    } finally {
        BundleExtractor::recursivelyRemove($root);
    }
});

// ---------- Cache root resolution ----------

it('resolveCacheRoot honors BOOST_CACHE_HOME env var', function (): void {
    putenv('BOOST_CACHE_HOME=/some/override');
    try {
        expect(RemoteSkillCache::resolveCacheRoot())->toBe('/some/override/boost/remote-skills');
    } finally {
        putenv('BOOST_CACHE_HOME');
    }
});

it('resolveCacheRoot falls back to XDG_CACHE_HOME, then HOME/.cache, then sys_get_temp_dir', function (): void {
    putenv('BOOST_CACHE_HOME');
    putenv('XDG_CACHE_HOME=/xdg');
    putenv('HOME=/home/x');
    try {
        expect(RemoteSkillCache::resolveCacheRoot())->toBe('/xdg/boost/remote-skills');
    } finally {
        putenv('XDG_CACHE_HOME');
        putenv('HOME');
    }
});

// ---------- promoteDiscovery: adopting a discovery run as the slot ----------

/**
 * Run a real discovery against a canned repo and promote a subset of it,
 * the way `boost remote` does.
 *
 * @param  array<string, string>  $repoSkills  repo-relative path => SKILL.md frontmatter
 * @param  list<string>  $keep  frontmatter names to promote
 * @return array{0: RemoteSkillCache, 1: FakeRemoteFetcher, 2: string}  cache, fetcher, cache root
 */
function promoteDiscoveredPathSource(array $repoSkills, array $keep, string $version = 'main'): array
{
    $root = cacheTempRoot();
    $fetcher = (new FakeRemoteFetcher())
        ->withResolvedRef('acme/skills', $version, RemoteSkillSource::MODE_PATH, 'abc123')
        ->withTarball('acme/skills', 'abc123', discoveryTarballBytes('acme-skills-abc123', $repoSkills));

    $work = sys_get_temp_dir() . '/boost-promote-' . bin2hex(random_bytes(6));
    mkdir($work, 0o755, recursive: true);

    $discoverer = new RemoteSkillDiscoverer($fetcher);
    $plan = $discoverer->plan('acme/skills', $version);
    $discovered = $discoverer->discover('acme/skills', $plan, $work);

    $selected = array_values(array_filter(
        $discovered,
        static fn (DiscoveredSkill $skill): bool => in_array($skill->name, $keep, true),
    ));

    $cache = new RemoteSkillCache(fetcher: $fetcher, cacheRoot: $root);
    $cache->promoteDiscovery('acme/skills', $plan->ref, $plan->mode, $work, $selected);

    return [$cache, $fetcher, $root];
}

it('promoteDiscovery: the promoted slot satisfies isReadyOffline for exactly what was declared', function (): void {
    [$cache, , $root] = promoteDiscoveredPathSource(
        ['skills/alpha' => 'name: alpha', 'skills/beta' => 'name: beta'],
        ['alpha'],
    );

    try {
        $declared = RemoteSkillSource::githubPath('acme/skills', 'main', ['alpha' => 'skills/alpha']);

        // The whole point of promotion: the sync `boost remote` offers to run
        // finds the content already there and touches no network.
        expect($cache->isReadyOffline($declared))->toBeTrue();
    } finally {
        BundleExtractor::recursivelyRemove($root);
    }
});

it('promoteDiscovery: unselected skills and discovery scratch dirs are gone from the slot', function (): void {
    [$cache, , $root] = promoteDiscoveredPathSource(
        ['skills/alpha' => 'name: alpha', 'skills/beta' => 'name: beta'],
        ['alpha'],
    );

    try {
        $slot = $root . '/acme__skills/abc123';

        expect($slot . '/alpha/SKILL.md')->toBeFile()
            ->and($slot . '/beta')->not->toBeDirectory()
            ->and($slot . '/.repo')->not->toBeDirectory()
            ->and($slot . '/.meta.json')->toBeFile();

        /** @var array{mode: string, resolved: string, skills: array<string, array{path: string}>} $meta */
        $meta = json_decode((string) file_get_contents($slot . '/.meta.json'), true, flags: JSON_THROW_ON_ERROR);

        expect(array_keys($meta['skills']))->toBe(['alpha'])
            ->and($meta['skills']['alpha']['path'])->toBe('skills/alpha')
            ->and($meta['mode'])->toBe('path')
            ->and($meta['resolved'])->toBe('abc123');

        // Unused so `$cache` isn't flagged; the assertion above is the subject.
        expect($cache)->toBeInstanceOf(RemoteSkillCache::class);
    } finally {
        BundleExtractor::recursivelyRemove($root);
    }
});

it('promoteDiscovery: a declared path that differs from the promoted one makes the slot stale', function (): void {
    [$cache, , $root] = promoteDiscoveredPathSource(['skills/alpha' => 'name: alpha'], ['alpha']);

    try {
        // Same skill name, different in-repo path — the cached content was built
        // for the other mapping, so it must not be served.
        $moved = RemoteSkillSource::githubPath('acme/skills', 'main', ['alpha' => 'other/place']);

        expect($cache->isReadyOffline($moved))->toBeFalse();
    } finally {
        BundleExtractor::recursivelyRemove($root);
    }
});

it('promoteDiscovery: a bundle slot is offline-ready for the asset name githubBundle derives', function (): void {
    $root = cacheTempRoot();
    $fetcher = (new FakeRemoteFetcher())
        ->withResolvedRef('acme/skills', 'v1.2.0', RemoteSkillSource::MODE_BUNDLE, 'v1.2.0')
        ->withReleaseAssets('acme/skills', 'v1.2.0', ['alpha.skill'])
        ->withAsset('acme/skills', 'v1.2.0', 'alpha.skill', cacheMakeBundleBytes('alpha'));

    $work = sys_get_temp_dir() . '/boost-promote-bundle-' . bin2hex(random_bytes(6));
    mkdir($work, 0o755, recursive: true);

    try {
        $discoverer = new RemoteSkillDiscoverer($fetcher);
        $plan = $discoverer->plan('acme/skills', 'v1.2.0');
        $discovered = $discoverer->discover('acme/skills', $plan, $work);

        $cache = new RemoteSkillCache(fetcher: $fetcher, cacheRoot: $root);
        $cache->promoteDiscovery('acme/skills', $plan->ref, $plan->mode, $work, $discovered);

        expect($cache->isReadyOffline(RemoteSkillSource::githubBundle('acme/skills', 'v1.2.0', ['alpha'])))->toBeTrue();
    } finally {
        BundleExtractor::recursivelyRemove($work);
        BundleExtractor::recursivelyRemove($root);
    }
});

it('promoteDiscovery: records a moving ref so the next sync does not re-resolve it', function (): void {
    [$cache, , $root] = promoteDiscoveredPathSource(['skills/alpha' => 'name: alpha'], ['alpha'], 'main');

    try {
        /** @var array<string, array{resolved: string}> $resolution */
        $resolution = json_decode((string) file_get_contents($root . '/acme__skills/.resolution-cache.json'), true, flags: JSON_THROW_ON_ERROR);

        expect($resolution['main:path']['resolved'])->toBe('abc123')
            ->and($cache->isReadyOffline(RemoteSkillSource::githubPath('acme/skills', 'main', ['alpha' => 'skills/alpha'])))->toBeTrue();
    } finally {
        BundleExtractor::recursivelyRemove($root);
    }
});

it('promoteDiscovery: refuses an empty selection rather than writing an empty slot', function (): void {
    $root = cacheTempRoot();
    $work = sys_get_temp_dir() . '/boost-promote-empty-' . bin2hex(random_bytes(6));
    mkdir($work, 0o755, recursive: true);

    try {
        $cache = new RemoteSkillCache(fetcher: new FakeRemoteFetcher(), cacheRoot: $root);

        expect(fn () => $cache->promoteDiscovery(
            'acme/skills',
            new ResolvedRef(requested: 'main', resolved: 'abc123'),
            RemoteSkillSource::MODE_PATH,
            $work,
            [],
        ))->toThrow(RemoteExtractException::class, 'empty selection');
    } finally {
        BundleExtractor::recursivelyRemove($work);
        BundleExtractor::recursivelyRemove($root);
    }
});

it('createWorkspace: hands out a directory on the same filesystem as the slots', function (): void {
    $root = cacheTempRoot();

    try {
        $workspace = (new RemoteSkillCache(fetcher: new FakeRemoteFetcher(), cacheRoot: $root))->createWorkspace();

        // promoteDiscovery() promotes by renaming, and rename() cannot cross a
        // filesystem boundary. A workspace in the system temp dir would make
        // every promotion fail wherever /tmp is a separate mount.
        expect($workspace)->toStartWith($root . '/')
            ->and(is_dir($workspace))->toBeTrue();
    } finally {
        BundleExtractor::recursivelyRemove($root);
    }
});

it('createWorkspace: a workspace can be promoted straight into a slot', function (): void {
    $root = cacheTempRoot();

    try {
        $cache = new RemoteSkillCache(fetcher: new FakeRemoteFetcher(), cacheRoot: $root);
        $workspace = $cache->createWorkspace();
        mkdir($workspace . '/alpha', 0o755, recursive: true);
        file_put_contents($workspace . '/alpha/SKILL.md', "---\nname: alpha\n---\nBody.");

        $cache->promoteDiscovery(
            'acme/skills',
            new ResolvedRef(requested: 'main', resolved: 'abc123'),
            RemoteSkillSource::MODE_PATH,
            $workspace,
            [new DiscoveredSkill(name: 'alpha', description: null, tags: [], requires: [], path: 'skills/alpha')],
        );

        expect($root . '/acme__skills/abc123/alpha/SKILL.md')->toBeFile();
    } finally {
        BundleExtractor::recursivelyRemove($root);
    }
});
