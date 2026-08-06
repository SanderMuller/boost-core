<?php declare(strict_types=1);

use SanderMuller\BoostCore\Commands\RemoteCommand;
use SanderMuller\BoostCore\Config\BoostConfig;
use SanderMuller\BoostCore\Skills\Remote\BundleExtractor;
use SanderMuller\BoostCore\Skills\Remote\DiscoveredSkill;
use SanderMuller\BoostCore\Skills\Remote\RemoteSelection;
use SanderMuller\BoostCore\Skills\Remote\RemoteSkillCache;
use SanderMuller\BoostCore\Skills\Remote\RemoteSkillDiscoverer;
use SanderMuller\BoostCore\Skills\Remote\RemoteSkillRef;
use SanderMuller\BoostCore\Skills\Remote\RemoteSkillSource;
use SanderMuller\BoostCore\Tests\Doubles\Remote\FakeRemoteFetcher;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Tester\CommandTester;

/**
 * A project with a config the command can load, plus a cleanup callback.
 *
 * @return array{0: string, 1: callable(): void}
 */
function remoteProject(string $configBody = "<?php declare(strict_types=1);\n\nreturn \\SanderMuller\\BoostCore\\Config\\BoostConfig::configure()->withAgents([]);\n"): array
{
    $dir = sys_get_temp_dir() . '/boost-remote-' . bin2hex(random_bytes(8));
    mkdir($dir, 0o755, recursive: true);
    file_put_contents($dir . '/boost.php', $configBody);

    // cleanupTestDir, not rmdir: an accepted sync prompt writes agent dirs
    // into the temp project, so a shallow cleanup would leak them.
    return [$dir, function () use ($dir): void {
        cleanupTestDir($dir);
    }];
}

/**
 * `boost remote` — target resolution (spec phase 1).
 *
 * The pure helpers are tested directly (same shape as InstallCommandMergeTest)
 * so the rules survive the interactive picker landing on top of them.
 */
it('normalizeSource: accepts the bare owner/repo slug', function (): void {
    expect(RemoteCommand::normalizeSource('peterfox/agent-skills'))->toBe('peterfox/agent-skills')
        ->and(RemoteCommand::normalizeSource('  peterfox/agent-skills  '))->toBe('peterfox/agent-skills');
});

it('normalizeSource: strips the GitHub URL shapes people paste', function (string $input): void {
    expect(RemoteCommand::normalizeSource($input))->toBe('peterfox/agent-skills');
})->with([
    'https URL' => 'https://github.com/peterfox/agent-skills',
    'http URL' => 'http://github.com/peterfox/agent-skills',
    'www host' => 'https://www.github.com/peterfox/agent-skills',
    'trailing slash' => 'https://github.com/peterfox/agent-skills/',
    'dot-git suffix' => 'https://github.com/peterfox/agent-skills.git',
    'deep link' => 'https://github.com/peterfox/agent-skills/tree/main/skills',
    'ssh remote' => 'git@github.com:peterfox/agent-skills.git',
    'bare host prefix' => 'github.com/peterfox/agent-skills',
]);

it('normalizeSource: rejects anything that is not a repo reference', function (string $input): void {
    expect(RemoteCommand::normalizeSource($input))->toBeNull();
})->with([
    'empty' => '',
    'whitespace' => '   ',
    'owner only' => 'peterfox',
    'illegal characters' => 'peter fox/agent skills',
    'not github' => 'https://gitlab.com/peterfox',
]);

it('resolveVersion: a new source gets the moving-ref default', function (): void {
    expect(RemoteCommand::resolveVersion(null, null))->toBe('latest')
        ->and(RemoteCommand::DEFAULT_VERSION)->toBe('latest');
});

it('resolveVersion: --ref wins over both the default and an existing pin', function (): void {
    $pinned = RemoteSkillSource::githubPath('acme/skills', 'v1.2.0', ['a' => 'skills/a']);

    expect(RemoteCommand::resolveVersion(null, 'v2.0.0'))->toBe('v2.0.0')
        ->and(RemoteCommand::resolveVersion($pinned, 'v3.0.0'))->toBe('v3.0.0')
        ->and(RemoteCommand::resolveVersion($pinned, '  v3.0.0  '))->toBe('v3.0.0');
});

it('resolveVersion: a re-run without --ref keeps the declared pin instead of un-pinning it', function (): void {
    $pinned = RemoteSkillSource::githubPath('acme/skills', 'v1.2.0', ['a' => 'skills/a']);

    expect(RemoteCommand::resolveVersion($pinned, null))->toBe('v1.2.0')
        ->and(RemoteCommand::resolveVersion($pinned, ''))->toBe('v1.2.0');
});

it('findEntry: matches on source + mode and ignores the version', function (): void {
    $declared = [
        RemoteSkillSource::githubPath('other/repo', 'main', ['x' => 'x']),
        RemoteSkillSource::githubPath('acme/skills', 'v1.2.0', ['a' => 'skills/a']),
    ];

    $match = RemoteCommand::findEntry($declared, 'acme/skills', 'path');

    expect($match?->version)->toBe('v1.2.0')
        ->and(RemoteCommand::findEntry($declared, 'acme/skills', 'bundle'))->toBeNull()
        ->and(RemoteCommand::findEntry($declared, 'nobody/nothing', null))->toBeNull();
});

it('findEntry: without --mode a single declared entry wins whatever its mode', function (): void {
    $declared = [RemoteSkillSource::githubBundle('acme/skills', 'v1.2.0', ['a'])];

    expect(RemoteCommand::findEntry($declared, 'acme/skills', null)?->mode())->toBe('bundle');
});

it('findEntry: without --mode a repo declared in both modes resolves to the bundle entry', function (): void {
    $declared = [
        RemoteSkillSource::githubPath('acme/skills', 'main', ['a' => 'skills/a']),
        RemoteSkillSource::githubBundle('acme/skills', 'v1.2.0', ['b']),
    ];

    expect(RemoteCommand::findEntry($declared, 'acme/skills', null)?->version)->toBe('v1.2.0');
});

it('resolveMode: --mode wins, else the declared entry keeps its mode, else discovery decides', function (): void {
    $path = RemoteSkillSource::githubPath('acme/skills', 'main', ['a' => 'skills/a']);

    expect(RemoteCommand::resolveMode($path, 'bundle'))->toBe('bundle')
        ->and(RemoteCommand::resolveMode($path, null))->toBe('path')
        ->and(RemoteCommand::resolveMode(null, null))->toBeNull();
});

it('rejects an unknown --mode before touching the config', function (): void {
    $dir = sys_get_temp_dir() . '/boost-remote-mode-' . bin2hex(random_bytes(8));
    mkdir($dir, 0o755, recursive: true);

    try {
        $tester = new CommandTester(new RemoteCommand());
        $tester->execute(['source' => 'acme/skills', '--mode' => 'zip', '--working-dir' => $dir]);

        expect($tester->getStatusCode())->toBe(Command::FAILURE)
            ->and($tester->getDisplay())->toContain('--mode must be `bundle` or `path`')
            // Bailing before the scaffold keeps a bad flag from creating files.
            ->and($dir . '/boost.php')->not->toBeFile();
    } finally {
        @rmdir($dir);
    }
});

it('scaffolds a starter boost.php for a project that has none, then fails fast without a TTY', function (): void {
    $dir = sys_get_temp_dir() . '/boost-remote-scaffold-' . bin2hex(random_bytes(8));
    mkdir($dir, 0o755, recursive: true);

    try {
        $tester = new CommandTester(new RemoteCommand());
        $tester->execute(['source' => 'acme/skills', '--working-dir' => $dir], ['interactive' => false]);

        expect($tester->getStatusCode())->toBe(Command::FAILURE)
            ->and(preg_replace('/\s+/', ' ', $tester->getDisplay()))->toContain('interactive terminal')
            ->and($tester->getDisplay())->toContain('--no-interaction')
            ->and($dir . '/boost.php')->toBeFile()
            ->and(file_get_contents($dir . '/boost.php'))->toContain('BoostConfig::configure()');
    } finally {
        @unlink($dir . '/boost.php');
        @rmdir($dir);
    }
});

it('re-uses the declared pin for a source already in boost.php', function (): void {
    [$dir, $cleanup] = remoteProject(<<<'PHP'
        <?php declare(strict_types=1);

        use SanderMuller\BoostCore\Config\BoostConfig;
        use SanderMuller\BoostCore\Skills\Remote\RemoteSkillSource;

        return BoostConfig::configure()
            ->withAgents([])
            ->withRemoteSkills([
                RemoteSkillSource::githubPath('acme/skills', 'v1.2.0', ['alpha' => 'skills/alpha']),
            ]);

        PHP);

    $fetcher = (new FakeRemoteFetcher())
        ->withResolvedRef('acme/skills', 'v1.2.0', RemoteSkillSource::MODE_PATH, 'abc123')
        ->withTarball('acme/skills', 'abc123', discoveryTarballBytes('acme-skills-abc123', [
            'skills/alpha' => 'name: alpha',
        ]));

    try {
        $tester = new CommandTester(new RemoteCommand(discoverer: new RemoteSkillDiscoverer($fetcher)));
        $tester->execute(['source' => 'https://github.com/acme/skills', '--working-dir' => $dir]);

        $display = preg_replace('/\s+/', ' ', $tester->getDisplay()) ?? '';

        expect($tester->getStatusCode())->toBe(Command::SUCCESS)
            // The pin drives discovery — a re-run never silently re-reads `latest`.
            ->and($display)->toContain('acme/skills@v1.2.0')
            ->and($display)->toContain('already declared at version `v1.2.0`');
    } finally {
        $cleanup();
    }
});

it('falls back to the moving-ref default for a source the project does not declare yet', function (): void {
    [$dir, $cleanup] = remoteProject();

    $fetcher = (new FakeRemoteFetcher())
        ->withResolvedRef('acme/skills', 'latest', RemoteSkillSource::MODE_PATH, 'abc123')
        ->withTarball('acme/skills', 'abc123', discoveryTarballBytes('acme-skills-abc123', [
            'skills/alpha' => 'name: alpha',
        ]));

    try {
        $tester = new CommandTester(new RemoteCommand(discoverer: new RemoteSkillDiscoverer($fetcher)));
        $tester->execute(['source' => 'acme/skills', '--working-dir' => $dir]);

        $display = preg_replace('/\s+/', ' ', $tester->getDisplay()) ?? '';

        expect($tester->getStatusCode())->toBe(Command::SUCCESS)
            ->and($display)->toContain('acme/skills@latest')
            ->and($display)->not->toContain('already declared');
    } finally {
        $cleanup();
    }
});

it('rejects a source argument that is not a repo reference', function (): void {
    [$dir, $cleanup] = remoteProject();

    try {
        $tester = new CommandTester(new RemoteCommand());
        $tester->execute(['source' => 'not-a-repo', '--working-dir' => $dir]);

        expect($tester->getStatusCode())->toBe(Command::FAILURE)
            ->and(preg_replace('/\s+/', ' ', $tester->getDisplay()))->toContain('Could not read `not-a-repo` as a GitHub repo');
    } finally {
        $cleanup();
    }
});

// ---------- Phase 2: discovery ----------

/**
 * A config declaring one path-mode source, so the picker's defaults are
 * non-empty. Under test the prompt falls back to its default, which makes the
 * declared set stand in for the operator's selection.
 */
/**
 * @return array{0: string, 1: callable(): void}
 */
function remoteProjectDeclaring(string $version = 'latest', string $skills = "'alpha' => 'skills/alpha'"): array
{
    return remoteProject(<<<PHP
        <?php declare(strict_types=1);

        use SanderMuller\\BoostCore\\Config\\BoostConfig;
        use SanderMuller\\BoostCore\\Skills\\Remote\\RemoteSkillSource;

        return BoostConfig::configure()
            ->withAgents([])
            ->withRemoteSkills([
                RemoteSkillSource::githubPath('acme/skills', '{$version}', [{$skills}]),
            ]);

        PHP);
}

it('shows the repo contents and flags the rows nothing may select', function (): void {
    [$dir, $cleanup] = remoteProject();

    $fetcher = (new FakeRemoteFetcher())
        ->withResolvedRef('acme/skills', 'latest', RemoteSkillSource::MODE_PATH, 'abc123')
        ->withTarball('acme/skills', 'abc123', discoveryTarballBytes('acme-skills-abc123', [
            'skills/alpha' => "name: alpha\ndescription: Does alpha things.",
            'skills/broken' => 'description: nameless',
        ]));

    try {
        $tester = new CommandTester(new RemoteCommand(discoverer: new RemoteSkillDiscoverer($fetcher)));
        $tester->execute(['source' => 'acme/skills', '--working-dir' => $dir]);

        $display = preg_replace('/\s+/', ' ', $tester->getDisplay()) ?? '';

        expect($tester->getStatusCode())->toBe(Command::SUCCESS)
            ->and($display)->toContain('2 skill(s)')
            ->and($display)->toContain('✗ broken — SKILL.md frontmatter declares no `name`')
            // Nothing picked (no declared entry to pre-check), so nothing is written.
            ->and($display)->toContain('Nothing selected, so boost.php is unchanged')
            ->and(file_get_contents($dir . '/boost.php'))->not->toContain('withRemoteSkills');
    } finally {
        $cleanup();
    }
});

// ---------- Phase 5: picking, closure, writing ----------

it('writes the picked selection back to boost.php', function (): void {
    [$dir, $cleanup] = remoteProjectDeclaring();

    $fetcher = (new FakeRemoteFetcher())
        ->withResolvedRef('acme/skills', 'latest', RemoteSkillSource::MODE_PATH, 'abc123')
        ->withTarball('acme/skills', 'abc123', discoveryTarballBytes('acme-skills-abc123', [
            'skills/alpha' => 'name: alpha',
            'skills/beta' => 'name: beta',
        ]));

    try {
        $tester = new CommandTester(new RemoteCommand(discoverer: new RemoteSkillDiscoverer($fetcher)));
        $tester->execute(['source' => 'acme/skills', '--working-dir' => $dir]);

        $display = preg_replace('/\s+/', ' ', $tester->getDisplay()) ?? '';
        $config = BoostConfig::load($dir);

        expect($tester->getStatusCode())->toBe(Command::SUCCESS)
            ->and($display)->toContain('Declared 1 skill(s) from acme/skills@latest')
            // A moving ref hands control of future syncs to the repo owner — say so.
            ->and($display)->toContain('is a moving ref')
            ->and($config->remoteSkills)->toHaveCount(1)
            ->and($config->remoteSkills[0]->skills)->toHaveCount(1)
            ->and($config->remoteSkills[0]->skills[0]->name)->toBe('alpha');
    } finally {
        $cleanup();
    }
});

it('stays quiet about moving refs when the source is pinned', function (): void {
    [$dir, $cleanup] = remoteProjectDeclaring('v1.2.0');

    $fetcher = (new FakeRemoteFetcher())
        ->withResolvedRef('acme/skills', 'v1.2.0', RemoteSkillSource::MODE_PATH, 'abc123')
        ->withTarball('acme/skills', 'abc123', discoveryTarballBytes('acme-skills-abc123', [
            'skills/alpha' => 'name: alpha',
        ]));

    try {
        $tester = new CommandTester(new RemoteCommand(discoverer: new RemoteSkillDiscoverer($fetcher)));
        $tester->execute(['source' => 'acme/skills', '--working-dir' => $dir]);

        expect(preg_replace('/\s+/', ' ', $tester->getDisplay()))->not->toContain('is a moving ref');
    } finally {
        $cleanup();
    }
});

it('pulls in a dependency the picked skill needs from the same repo', function (): void {
    [$dir, $cleanup] = remoteProjectDeclaring();

    $fetcher = (new FakeRemoteFetcher())
        ->withResolvedRef('acme/skills', 'latest', RemoteSkillSource::MODE_PATH, 'abc123')
        ->withTarball('acme/skills', 'abc123', discoveryTarballBytes('acme-skills-abc123', [
            'skills/alpha' => "name: alpha\nmetadata:\n  boost-requires: \"beta\"",
            'skills/beta' => 'name: beta',
        ]));

    try {
        $tester = new CommandTester(new RemoteCommand(discoverer: new RemoteSkillDiscoverer($fetcher)));
        $tester->execute(['source' => 'acme/skills', '--working-dir' => $dir]);

        $display = preg_replace('/\s+/', ' ', $tester->getDisplay()) ?? '';
        $declared = array_map(
            static fn (RemoteSkillRef $ref): string => $ref->name,
            BoostConfig::load($dir)->remoteSkills[0]->skills,
        );

        expect($display)->toContain('+ beta — added because `alpha` requires it')
            ->and($declared)->toContain('alpha')
            ->and($declared)->toContain('beta');
    } finally {
        $cleanup();
    }
});

it('warns when a dependency is not in this repo', function (): void {
    [$dir, $cleanup] = remoteProjectDeclaring();

    $fetcher = (new FakeRemoteFetcher())
        ->withResolvedRef('acme/skills', 'latest', RemoteSkillSource::MODE_PATH, 'abc123')
        ->withTarball('acme/skills', 'abc123', discoveryTarballBytes('acme-skills-abc123', [
            'skills/alpha' => "name: alpha\nmetadata:\n  boost-requires: \"elsewhere\"",
        ]));

    try {
        $tester = new CommandTester(new RemoteCommand(discoverer: new RemoteSkillDiscoverer($fetcher)));
        $tester->execute(['source' => 'acme/skills', '--working-dir' => $dir]);

        expect(preg_replace('/\s+/', ' ', $tester->getDisplay()))
            ->toContain('`elsewhere` is required by `alpha` but this repo does not publish it');
    } finally {
        $cleanup();
    }
});

it('drops the entry when the declared skill no longer exists in the repo', function (): void {
    [$dir, $cleanup] = remoteProjectDeclaring('latest', "'gone' => 'skills/gone'");

    $fetcher = (new FakeRemoteFetcher())
        ->withResolvedRef('acme/skills', 'latest', RemoteSkillSource::MODE_PATH, 'abc123')
        ->withTarball('acme/skills', 'abc123', discoveryTarballBytes('acme-skills-abc123', [
            'skills/alpha' => 'name: alpha',
        ]));

    try {
        $tester = new CommandTester(new RemoteCommand(discoverer: new RemoteSkillDiscoverer($fetcher)));
        $tester->execute(['source' => 'acme/skills', '--working-dir' => $dir]);

        $display = preg_replace('/\s+/', ' ', $tester->getDisplay()) ?? '';

        expect($tester->getStatusCode())->toBe(Command::SUCCESS)
            ->and($display)->toContain('Removed acme/skills from')
            ->and(BoostConfig::load($dir)->remoteSkills)->toBe([]);
    } finally {
        $cleanup();
    }
});

it('promotes the written selection into the cache so the next sync needs no network', function (): void {
    [$dir, $cleanup] = remoteProjectDeclaring();
    $cacheRoot = sys_get_temp_dir() . '/boost-remote-cache-' . bin2hex(random_bytes(6));

    $fetcher = (new FakeRemoteFetcher())
        ->withResolvedRef('acme/skills', 'latest', RemoteSkillSource::MODE_PATH, 'abc123')
        ->withTarball('acme/skills', 'abc123', discoveryTarballBytes('acme-skills-abc123', [
            'skills/alpha' => 'name: alpha',
            'skills/beta' => 'name: beta',
        ]));

    try {
        $tester = new CommandTester(new RemoteCommand(
            discoverer: new RemoteSkillDiscoverer($fetcher),
            cache: new RemoteSkillCache(fetcher: $fetcher, cacheRoot: $cacheRoot),
        ));
        $tester->execute(['source' => 'acme/skills', '--working-dir' => $dir]);

        $declared = BoostConfig::load($dir)->remoteSkills[0];

        expect($cacheRoot . '/acme__skills/abc123/alpha/SKILL.md')->toBeFile()
            // Only what was declared is kept on disk.
            ->and($cacheRoot . '/acme__skills/abc123/beta')->not->toBeDirectory()
            ->and((new RemoteSkillCache(fetcher: $fetcher, cacheRoot: $cacheRoot))->isReadyOffline($declared))->toBeTrue();
    } finally {
        BundleExtractor::recursivelyRemove($cacheRoot);
        $cleanup();
    }
});

it('keeps going when the cache cannot be written — the config is what matters', function (): void {
    [$dir, $cleanup] = remoteProjectDeclaring();

    $fetcher = (new FakeRemoteFetcher())
        ->withResolvedRef('acme/skills', 'latest', RemoteSkillSource::MODE_PATH, 'abc123')
        ->withTarball('acme/skills', 'abc123', discoveryTarballBytes('acme-skills-abc123', [
            'skills/alpha' => 'name: alpha',
        ]));

    try {
        $tester = new CommandTester(new RemoteCommand(
            discoverer: new RemoteSkillDiscoverer($fetcher),
            // A cache root under a regular file: every mkdir below it fails.
            cache: new RemoteSkillCache(fetcher: $fetcher, cacheRoot: $dir . '/boost.php/cache'),
        ));
        $tester->execute(['source' => 'acme/skills', '--working-dir' => $dir]);

        $display = preg_replace('/\s+/', ' ', $tester->getDisplay()) ?? '';

        expect($tester->getStatusCode())->toBe(Command::SUCCESS)
            ->and($display)->toContain('Could not cache what was downloaded')
            ->and(BoostConfig::load($dir)->remoteSkills)->toHaveCount(1);
    } finally {
        $cleanup();
    }
});

// ---------- Phase 5: pure selection logic ----------

it('closeDependencies: pulls transitive requires and records who demanded them', function (): void {
    $discovered = [
        new DiscoveredSkill(name: 'alpha', description: null, tags: [], requires: ['beta']),
        new DiscoveredSkill(name: 'beta', description: null, tags: [], requires: ['gamma']),
        new DiscoveredSkill(name: 'gamma', description: null, tags: [], requires: []),
        new DiscoveredSkill(name: 'unrelated', description: null, tags: [], requires: []),
    ];

    $closure = RemoteSelection::closeDependencies($discovered, ['alpha']);

    expect($closure['names'])->toBe(['alpha', 'beta', 'gamma'])
        ->and($closure['pulled'])->toBe(['beta' => 'alpha', 'gamma' => 'beta'])
        ->and($closure['missing'])->toBe([]);
});

it('closeDependencies: a dependency cycle co-ships instead of looping', function (): void {
    $discovered = [
        new DiscoveredSkill(name: 'a', description: null, tags: [], requires: ['b']),
        new DiscoveredSkill(name: 'b', description: null, tags: [], requires: ['a']),
    ];

    expect(RemoteSelection::closeDependencies($discovered, ['a'])['names'])->toBe(['a', 'b']);
});

it('closeDependencies: reports a dependency this repo does not publish', function (): void {
    $discovered = [
        new DiscoveredSkill(name: 'alpha', description: null, tags: [], requires: ['elsewhere']),
        new DiscoveredSkill(name: 'beta', description: null, tags: [], requires: ['elsewhere']),
    ];

    $closure = RemoteSelection::closeDependencies($discovered, ['alpha', 'beta']);

    expect($closure['missing'])->toBe(['elsewhere' => ['alpha', 'beta']])
        ->and($closure['names'])->toBe(['alpha', 'beta']);
});

it('closeDependencies: never pulls an unselectable skill', function (): void {
    $discovered = [
        new DiscoveredSkill(name: 'alpha', description: null, tags: [], requires: ['broken']),
        new DiscoveredSkill(name: 'broken', description: null, tags: [], requires: [], problem: 'duplicate name'),
    ];

    $closure = RemoteSelection::closeDependencies($discovered, ['alpha']);

    expect($closure['names'])->toBe(['alpha'])
        ->and($closure['missing'])->toHaveKey('broken');
});

it('missingTags: only tags the project has not declared, and nothing when filtering is off', function (): void {
    $tagged = [new DiscoveredSkill(name: 'alpha', description: null, tags: ['php', 'jira'], requires: [])];

    expect(RemoteSelection::missingTags($tagged, ['php']))->toBe(['jira'])
        ->and(RemoteSelection::missingTags($tagged, ['php', 'jira']))->toBe([])
        // No withTags() at all means no filtering, so nothing can be missing.
        ->and(RemoteSelection::missingTags($tagged, []))->toBe([]);
});

it('pickerLabel: truncates the description and appends the notes that matter', function (): void {
    $long = str_repeat('a', 200);
    $skill = new DiscoveredSkill(name: 'alpha', description: $long, tags: ['jira'], requires: []);

    $label = RemoteSelection::pickerLabel($skill, ['alpha' => 'host'], ['php'], true);

    expect($label)->toContain(str_repeat('a', RemoteSelection::DESCRIPTION_WIDTH - 1) . '…')
        ->and($label)->not->toContain($long)
        ->and($label)->toContain('your own .ai/ skill of this name wins')
        ->and($label)->toContain('excluded via withExcludedSkills()')
        ->and($label)->toContain('tags jira are not in withTags()');
});

it('pickerLabel: names the vendor a pick would collide with', function (): void {
    $skill = new DiscoveredSkill(name: 'alpha', description: 'Short.', tags: [], requires: []);

    expect(RemoteSelection::pickerLabel($skill, ['alpha' => 'acme/pack'], [], false))
        ->toBe('alpha  Short. — collides with acme/pack, which fails the sync');
});

it('pickerLabel: a clean skill gets name and description only', function (): void {
    $skill = new DiscoveredSkill(name: 'alpha', description: 'Short.', tags: [], requires: []);

    expect(RemoteSelection::pickerLabel($skill, [], [], false))->toBe('alpha  Short.');
});

it('offers to add the tags a picked skill needs, and writes them', function (): void {
    [$dir, $cleanup] = remoteProject(<<<'PHP'
        <?php declare(strict_types=1);

        use SanderMuller\BoostCore\Config\BoostConfig;
        use SanderMuller\BoostCore\Skills\Remote\RemoteSkillSource;

        return BoostConfig::configure()
            ->withAgents([])
            ->withTags(['php'])
            ->withRemoteSkills([
                RemoteSkillSource::githubPath('acme/skills', 'latest', ['alpha' => 'skills/alpha']),
            ]);

        PHP);

    $fetcher = (new FakeRemoteFetcher())
        ->withResolvedRef('acme/skills', 'latest', RemoteSkillSource::MODE_PATH, 'abc123')
        ->withTarball('acme/skills', 'abc123', discoveryTarballBytes('acme-skills-abc123', [
            'skills/alpha' => "name: alpha\nmetadata:\n  boost-tags: \"php jira\"",
        ]));

    try {
        $tester = new CommandTester(new RemoteCommand(discoverer: new RemoteSkillDiscoverer($fetcher)));
        $tester->execute(['source' => 'acme/skills', '--working-dir' => $dir]);

        $display = preg_replace('/\s+/', ' ', $tester->getDisplay()) ?? '';

        // Without this the skill sits in boost.php and never ships — the silent
        // outcome the tag check exists to prevent. The prompt defaults to yes.
        expect($display)->toContain('These tags are missing from withTags()')
            ->and($display)->toContain('Added jira to withTags()')
            ->and(BoostConfig::load($dir)->tags)->toBe(['php', 'jira']);
    } finally {
        $cleanup();
    }
});

it('says nothing about tags when the project declares none', function (): void {
    [$dir, $cleanup] = remoteProjectDeclaring();

    $fetcher = (new FakeRemoteFetcher())
        ->withResolvedRef('acme/skills', 'latest', RemoteSkillSource::MODE_PATH, 'abc123')
        ->withTarball('acme/skills', 'abc123', discoveryTarballBytes('acme-skills-abc123', [
            'skills/alpha' => "name: alpha\nmetadata:\n  boost-tags: \"php jira\"",
        ]));

    try {
        $tester = new CommandTester(new RemoteCommand(discoverer: new RemoteSkillDiscoverer($fetcher)));
        $tester->execute(['source' => 'acme/skills', '--working-dir' => $dir]);

        // An empty withTags() means no filtering at all, so there is no gap.
        expect(preg_replace('/\s+/', ' ', $tester->getDisplay()))->not->toContain('missing from withTags()');
    } finally {
        $cleanup();
    }
});

it('pickerLabel: names only the tags that are actually missing', function (): void {
    $skill = new DiscoveredSkill(name: 'alpha', description: null, tags: ['php', 'jira'], requires: []);

    // Listing every tag would tell the operator to add `php`, which is already
    // declared — the note has to point at the gap, not at the skill's tag set.
    expect(RemoteSelection::pickerLabel($skill, [], ['php'], false))
        ->toContain('tags jira are not in withTags()')
        ->not->toContain('php, jira');
});
