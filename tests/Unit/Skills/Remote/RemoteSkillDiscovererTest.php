<?php declare(strict_types=1);

use SanderMuller\BoostCore\Skills\Remote\BundleExtractor;
use SanderMuller\BoostCore\Skills\Remote\RemoteFetcher;
use SanderMuller\BoostCore\Skills\Remote\RemoteFetchException;
use SanderMuller\BoostCore\Skills\Remote\RemoteSkillDiscoverer;
use SanderMuller\BoostCore\Skills\Remote\RemoteSkillSource;
use SanderMuller\BoostCore\Skills\Remote\ResolvedRef;
use SanderMuller\BoostCore\Tests\Doubles\Remote\FakeRemoteFetcher;

function discoveryWorkDir(): string
{
    $dir = sys_get_temp_dir() . '/boost-discovery-' . bin2hex(random_bytes(6));
    mkdir($dir, 0o755, recursive: true);

    return $dir;
}

/**
 * A `.skill` ZIP wrapping `<wrapper>/SKILL.md`, with caller-supplied
 * frontmatter so tests can exercise names, tags and dependencies.
 */
function discoveryBundleBytes(string $wrapper, string $frontmatter): string
{
    $tmpZip = sys_get_temp_dir() . '/boost-discovery-zip-' . bin2hex(random_bytes(6)) . '.zip';
    $zip = new ZipArchive();
    $zip->open($tmpZip, ZipArchive::CREATE);
    $zip->addFromString($wrapper . '/SKILL.md', "---\n{$frontmatter}\n---\nBody.");
    $zip->close();

    $bytes = (string) file_get_contents($tmpZip);
    @unlink($tmpZip);

    return $bytes;
}

// ---------- plan(): mode detection ----------

it('plans bundle mode when the release publishes .skill assets', function (): void {
    $fetcher = (new FakeRemoteFetcher())
        ->withResolvedRef('acme/skills', 'latest', RemoteSkillSource::MODE_BUNDLE, 'v1.2.0')
        ->withReleaseAssets('acme/skills', 'v1.2.0', ['alpha.skill', 'beta.skill', 'notes.txt']);

    $plan = (new RemoteSkillDiscoverer($fetcher))->plan('acme/skills', 'latest');

    expect($plan->mode)->toBe('bundle')
        ->and($plan->ref->resolved)->toBe('v1.2.0')
        // `notes.txt` is not a skill bundle and must not become a download.
        ->and($plan->downloadCount())->toBe(2)
        ->and(array_column($plan->assets, 'name'))->toBe(['alpha', 'beta']);
});

it('falls back to path mode when the repo has no releases', function (): void {
    $fetcher = (new FakeRemoteFetcher())
        ->withResolvedRef('acme/skills', 'latest', RemoteSkillSource::MODE_PATH, 'abc123');

    $plan = (new RemoteSkillDiscoverer($fetcher))->plan('acme/skills', 'latest');

    expect($plan->mode)->toBe('path')
        ->and($plan->ref->resolved)->toBe('abc123')
        ->and($plan->downloadCount())->toBe(1);
});

it('falls back to path mode when a release exists but carries no .skill assets', function (): void {
    $fetcher = (new FakeRemoteFetcher())
        ->withResolvedRef('acme/skills', 'latest', RemoteSkillSource::MODE_BUNDLE, 'v1.2.0')
        ->withReleaseAssets('acme/skills', 'v1.2.0', ['source.zip'])
        ->withResolvedRef('acme/skills', 'latest', RemoteSkillSource::MODE_PATH, 'abc123');

    expect((new RemoteSkillDiscoverer($fetcher))->plan('acme/skills', 'latest')->mode)->toBe('path');
});

it('honours an explicit --mode=path even when the repo publishes bundles', function (): void {
    $fetcher = (new FakeRemoteFetcher())
        ->withResolvedRef('acme/skills', 'latest', RemoteSkillSource::MODE_BUNDLE, 'v1.2.0')
        ->withReleaseAssets('acme/skills', 'v1.2.0', ['alpha.skill'])
        ->withResolvedRef('acme/skills', 'latest', RemoteSkillSource::MODE_PATH, 'abc123');

    $plan = (new RemoteSkillDiscoverer($fetcher))->plan('acme/skills', 'latest', RemoteSkillSource::MODE_PATH);

    expect($plan->mode)->toBe('path');
});

it('propagates a rate limit instead of reading it as "no releases here"', function (): void {
    $fetcher = new class implements RemoteFetcher {
        public function resolveRef(string $source, string $version, string $mode): never
        {
            throw new RemoteFetchException('Rate-limited by GitHub.', RemoteFetchException::RATE_LIMITED);
        }

        public function fetchAsset(string $source, ResolvedRef $ref, string $assetName, string $destinationPath): never
        {
            throw new LogicException('unreachable');
        }

        public function fetchTarball(string $source, ResolvedRef $ref, string $destinationPath): never
        {
            throw new LogicException('unreachable');
        }

        public function listReleaseAssets(string $source, ResolvedRef $ref): never
        {
            throw new LogicException('unreachable');
        }
    };

    expect(fn () => (new RemoteSkillDiscoverer($fetcher))->plan('acme/skills', 'latest'))
        ->toThrow(RemoteFetchException::class, 'Rate-limited');
});

// ---------- discover(): bundle mode ----------

it('describes bundle skills from their frontmatter and lays them out by name', function (): void {
    $fetcher = (new FakeRemoteFetcher())
        ->withResolvedRef('acme/skills', 'latest', RemoteSkillSource::MODE_BUNDLE, 'v1.2.0')
        ->withReleaseAssets('acme/skills', 'v1.2.0', ['alpha.skill'])
        ->withAsset('acme/skills', 'v1.2.0', 'alpha.skill', discoveryBundleBytes(
            'alpha',
            "name: alpha\ndescription: Does alpha things.\nmetadata:\n  boost-tags: \"php jira\"\n  boost-requires: \"beta\"",
        ));

    $work = discoveryWorkDir();

    try {
        $discoverer = new RemoteSkillDiscoverer($fetcher);
        $skills = $discoverer->discover('acme/skills', $discoverer->plan('acme/skills', 'latest'), $work);

        expect($skills)->toHaveCount(1)
            ->and($skills[0]->name)->toBe('alpha')
            ->and($skills[0]->description)->toBe('Does alpha things.')
            ->and($skills[0]->tags)->toBe(['php', 'jira'])
            ->and($skills[0]->requires)->toBe(['beta'])
            ->and($skills[0]->asset)->toBe('alpha.skill')
            ->and($skills[0]->path)->toBeNull()
            ->and($skills[0]->isSelectable())->toBeTrue()
            // Laid out under the frontmatter name, ready for cache promotion.
            ->and($work . '/alpha/SKILL.md')->toBeFile();
    } finally {
        BundleExtractor::recursivelyRemove($work);
    }
});

it('refuses a bundle whose frontmatter name does not match its asset name', function (): void {
    $fetcher = (new FakeRemoteFetcher())
        ->withResolvedRef('acme/skills', 'latest', RemoteSkillSource::MODE_BUNDLE, 'v1.2.0')
        ->withReleaseAssets('acme/skills', 'v1.2.0', ['alpha.skill'])
        ->withAsset('acme/skills', 'v1.2.0', 'alpha.skill', discoveryBundleBytes('alpha', 'name: renamed-alpha'));

    $work = discoveryWorkDir();

    try {
        $discoverer = new RemoteSkillDiscoverer($fetcher);
        $skills = $discoverer->discover('acme/skills', $discoverer->plan('acme/skills', 'latest'), $work);

        expect($skills[0]->isSelectable())->toBeFalse()
            ->and($skills[0]->problem)->toContain('does not match asset `alpha.skill`')
            ->and($work . '/renamed-alpha')->not->toBeDirectory();
    } finally {
        BundleExtractor::recursivelyRemove($work);
    }
});

// ---------- discover(): path mode ----------

it('finds every SKILL.md directory in the repo tarball', function (): void {
    $fetcher = (new FakeRemoteFetcher())
        ->withResolvedRef('acme/skills', 'main', RemoteSkillSource::MODE_PATH, 'abc123')
        ->withTarball('acme/skills', 'abc123', discoveryTarballBytes('acme-skills-abc123', [
            'skills/alpha' => "name: alpha\ndescription: First.",
            'skills/nested/beta' => 'name: beta',
        ]));

    $work = discoveryWorkDir();

    try {
        $discoverer = new RemoteSkillDiscoverer($fetcher);
        $skills = $discoverer->discover('acme/skills', $discoverer->plan('acme/skills', 'main'), $work);

        expect(array_column($skills, 'name'))->toBe(['alpha', 'beta'])
            ->and($skills[0]->path)->toBe('skills/alpha')
            ->and($skills[1]->path)->toBe('skills/nested/beta')
            ->and($skills[0]->asset)->toBeNull()
            ->and($work . '/alpha/SKILL.md')->toBeFile()
            ->and($work . '/beta/SKILL.md')->toBeFile();
    } finally {
        BundleExtractor::recursivelyRemove($work);
    }
});

it('treats a whole-repo-is-one-skill layout as the `.` path', function (): void {
    $fetcher = (new FakeRemoteFetcher())
        ->withResolvedRef('acme/one-skill', 'main', RemoteSkillSource::MODE_PATH, 'abc123')
        ->withTarball('acme/one-skill', 'abc123', discoveryTarballBytes('acme-one-skill-abc123', [
            '.' => 'name: solo',
        ]));

    $work = discoveryWorkDir();

    try {
        $discoverer = new RemoteSkillDiscoverer($fetcher);
        $skills = $discoverer->discover('acme/one-skill', $discoverer->plan('acme/one-skill', 'main'), $work);

        expect($skills)->toHaveCount(1)
            ->and($skills[0]->name)->toBe('solo')
            ->and($skills[0]->path)->toBe('.');
    } finally {
        BundleExtractor::recursivelyRemove($work);
    }
});

it('does not offer a SKILL.md nested under a skill it already found', function (): void {
    $fetcher = (new FakeRemoteFetcher())
        ->withResolvedRef('acme/skills', 'main', RemoteSkillSource::MODE_PATH, 'abc123')
        ->withTarball('acme/skills', 'abc123', discoveryTarballBytes('acme-skills-abc123', [
            'skills/alpha' => 'name: alpha',
            'skills/alpha/examples/sample' => 'name: sample',
        ]));

    $work = discoveryWorkDir();

    try {
        $discoverer = new RemoteSkillDiscoverer($fetcher);
        $skills = $discoverer->discover('acme/skills', $discoverer->plan('acme/skills', 'main'), $work);

        expect(array_column($skills, 'name'))->toBe(['alpha']);
    } finally {
        BundleExtractor::recursivelyRemove($work);
    }
});

it('skips VCS and dependency directories while scanning', function (): void {
    $fetcher = (new FakeRemoteFetcher())
        ->withResolvedRef('acme/skills', 'main', RemoteSkillSource::MODE_PATH, 'abc123')
        ->withTarball('acme/skills', 'abc123', discoveryTarballBytes('acme-skills-abc123', [
            'skills/alpha' => 'name: alpha',
            'node_modules/pkg/skills/junk' => 'name: junk',
            'vendor/acme/pack/skills/dep' => 'name: dep',
        ]));

    $work = discoveryWorkDir();

    try {
        $discoverer = new RemoteSkillDiscoverer($fetcher);
        $skills = $discoverer->discover('acme/skills', $discoverer->plan('acme/skills', 'main'), $work);

        expect(array_column($skills, 'name'))->toBe(['alpha']);
    } finally {
        BundleExtractor::recursivelyRemove($work);
    }
});

it('returns an empty list for a repo with no SKILL.md anywhere', function (): void {
    $fetcher = (new FakeRemoteFetcher())
        ->withResolvedRef('acme/empty', 'main', RemoteSkillSource::MODE_PATH, 'abc123')
        ->withTarball('acme/empty', 'abc123', discoveryTarballBytes('acme-empty-abc123', [], ['docs/intro.md']));

    $work = discoveryWorkDir();

    try {
        $discoverer = new RemoteSkillDiscoverer($fetcher);

        expect($discoverer->discover('acme/empty', $discoverer->plan('acme/empty', 'main'), $work))
            ->toBeEmpty();
    } finally {
        BundleExtractor::recursivelyRemove($work);
    }
});

// ---------- describe(): defective skills stay visible ----------

it('marks a skill whose frontmatter declares no name as unselectable', function (): void {
    $fetcher = (new FakeRemoteFetcher())
        ->withResolvedRef('acme/skills', 'main', RemoteSkillSource::MODE_PATH, 'abc123')
        ->withTarball('acme/skills', 'abc123', discoveryTarballBytes('acme-skills-abc123', [
            'skills/broken' => 'description: no name here',
        ]));

    $work = discoveryWorkDir();

    try {
        $discoverer = new RemoteSkillDiscoverer($fetcher);
        $skills = $discoverer->discover('acme/skills', $discoverer->plan('acme/skills', 'main'), $work);

        expect($skills)->toHaveCount(1)
            ->and($skills[0]->name)->toBe('broken')
            ->and($skills[0]->isSelectable())->toBeFalse()
            ->and($skills[0]->problem)->toContain('declares no `name`');
    } finally {
        BundleExtractor::recursivelyRemove($work);
    }
});

it('marks a skill with malformed boost-tags as unselectable — it would never ship', function (): void {
    $fetcher = (new FakeRemoteFetcher())
        ->withResolvedRef('acme/skills', 'main', RemoteSkillSource::MODE_PATH, 'abc123')
        ->withTarball('acme/skills', 'abc123', discoveryTarballBytes('acme-skills-abc123', [
            'skills/alpha' => "name: alpha\nmetadata:\n  boost-tags:\n    - php\n    - jira",
        ]));

    $work = discoveryWorkDir();

    try {
        $discoverer = new RemoteSkillDiscoverer($fetcher);
        $skills = $discoverer->discover('acme/skills', $discoverer->plan('acme/skills', 'main'), $work);

        expect($skills[0]->isSelectable())->toBeFalse()
            ->and($skills[0]->problem)->toContain('boost-tags');
    } finally {
        BundleExtractor::recursivelyRemove($work);
    }
});

it('marks both skills unselectable when two claim the same name', function (): void {
    $fetcher = (new FakeRemoteFetcher())
        ->withResolvedRef('acme/skills', 'main', RemoteSkillSource::MODE_PATH, 'abc123')
        ->withTarball('acme/skills', 'abc123', discoveryTarballBytes('acme-skills-abc123', [
            'skills/one' => 'name: duplicate',
            'skills/two' => 'name: duplicate',
        ]));

    $work = discoveryWorkDir();

    try {
        $discoverer = new RemoteSkillDiscoverer($fetcher);
        $skills = $discoverer->discover('acme/skills', $discoverer->plan('acme/skills', 'main'), $work);

        expect($skills)->toHaveCount(2)
            ->and($skills[0]->isSelectable())->toBeFalse()
            ->and($skills[1]->isSelectable())->toBeFalse()
            ->and($skills[0]->problem)->toContain('more than one skill named `duplicate`')
            // Nothing is laid out, so nothing can be promoted under that name.
            ->and($work . '/duplicate')->not->toBeDirectory();
    } finally {
        BundleExtractor::recursivelyRemove($work);
    }
});

it('keeps every file a bundle ships, not just the ones a repo scan would keep', function (): void {
    // The cache's own bundle path unzips verbatim, so discovery must not apply
    // the repo-scan blocklist here: a skill whose bundle ships a README would
    // otherwise reach the agent dirs missing that file.
    $tmpZip = sys_get_temp_dir() . '/boost-discovery-zip-' . bin2hex(random_bytes(6)) . '.zip';
    $zip = new ZipArchive();
    $zip->open($tmpZip, ZipArchive::CREATE);
    $zip->addFromString('alpha/SKILL.md', "---\nname: alpha\n---\nBody.");
    $zip->addFromString('alpha/README.md', 'Bundled reference.');
    $zip->addFromString('alpha/examples/sample.txt', 'Sample.');
    $zip->close();

    $bytes = (string) file_get_contents($tmpZip);
    @unlink($tmpZip);

    $fetcher = (new FakeRemoteFetcher())
        ->withResolvedRef('acme/skills', 'latest', RemoteSkillSource::MODE_BUNDLE, 'v1.2.0')
        ->withReleaseAssets('acme/skills', 'v1.2.0', ['alpha.skill'])
        ->withAsset('acme/skills', 'v1.2.0', 'alpha.skill', $bytes);

    $work = discoveryWorkDir();

    try {
        $discoverer = new RemoteSkillDiscoverer($fetcher);
        $discoverer->discover('acme/skills', $discoverer->plan('acme/skills', 'latest'), $work);

        expect($work . '/alpha/SKILL.md')->toBeFile()
            ->and($work . '/alpha/README.md')->toBeFile()
            ->and($work . '/alpha/examples/sample.txt')->toBeFile();
    } finally {
        BundleExtractor::recursivelyRemove($work);
    }
});
