<?php declare(strict_types=1);

use SanderMuller\BoostCore\Agents\ClaudeCodeTarget;
use SanderMuller\BoostCore\Scripts\BoostAutoSync;
use SanderMuller\BoostCore\Sync\InstalledPackages;
use SanderMuller\BoostCore\Sync\PackageInfo;
use SanderMuller\BoostCore\Sync\SyncEngine;
use Symfony\Component\Process\Process;

/**
 * `~/.boost/user-scope.php` selection: which skills each package publishes.
 */
function selectionDir(string $kind): string
{
    $dir = sys_get_temp_dir() . '/boost-select-' . $kind . '-' . bin2hex(random_bytes(8));
    mkdir($dir, 0o755, recursive: true);

    return $dir;
}

/**
 * @param  array<string, string>  $skills  skill name => extra frontmatter lines
 */
function selectionPackage(string $name, array $skills): string
{
    $pkg = selectionDir('pkg');
    file_put_contents($pkg . '/composer.json', json_encode(['name' => $name], JSON_THROW_ON_ERROR));

    foreach ($skills as $skill => $extraFrontmatter) {
        mkdir($pkg . '/resources/boost/skills/' . $skill, 0o755, recursive: true);
        file_put_contents(
            $pkg . '/resources/boost/skills/' . $skill . '/SKILL.md',
            "---\nname: {$skill}\ndescription: Skill {$skill}.\n{$extraFrontmatter}---\nBody of {$skill}. See `clarify`.\n",
        );
    }

    return $pkg;
}

function selectionConfig(string $home, string $php): void
{
    if (! is_dir($home . '/.boost')) {
        mkdir($home . '/.boost', 0o755, recursive: true);
    }

    file_put_contents($home . '/.boost/user-scope.php', $php);
}

function selectionEngine(): SyncEngine
{
    return new SyncEngine([new ClaudeCodeTarget()], installedPackages: new InstalledPackages([]));
}

/**
 * @return list<string>
 */
function selectionPublished(string $home): array
{
    $dirs = glob($home . '/.claude/skills/*-user', GLOB_ONLYDIR);
    $names = array_map('basename', $dirs === false ? [] : $dirs);
    sort($names);

    return $names;
}

function selectionRm(string ...$paths): void
{
    foreach ($paths as $path) {
        if (is_link($path) || is_file($path)) {
            unlink($path);

            continue;
        }

        if (! is_dir($path)) {
            continue;
        }

        foreach (array_diff((array) scandir($path), ['.', '..']) as $entry) {
            selectionRm($path . '/' . $entry);
        }

        rmdir($path);
    }
}

it('publishes only the selected skills', function (): void {
    $pkg = selectionPackage('acme/kit', ['alpha' => '', 'beta' => '', 'gamma' => '']);
    $home = selectionDir('home');

    try {
        selectionConfig($home, "<?php return ['skills' => ['acme/kit' => ['alpha', 'gamma']]];");

        $result = selectionEngine()->syncUser($pkg, homeRoot: $home);

        expect($result->errors)->toBe([])
            ->and($result->warnings)->toBe([])
            ->and(selectionPublished($home))->toBe(['alpha-user', 'gamma-user']);
    } finally {
        selectionRm($pkg, $home);
    }
});

it('publishes every skill of a package that has no entry', function (): void {
    $pkg = selectionPackage('acme/kit', ['alpha' => '', 'beta' => '']);
    $home = selectionDir('home');

    try {
        selectionConfig($home, "<?php return ['skills' => ['other/pkg' => ['x']]];");

        selectionEngine()->syncUser($pkg, homeRoot: $home);

        expect(selectionPublished($home))->toBe(['alpha-user', 'beta-user']);
    } finally {
        selectionRm($pkg, $home);
    }
});

it('an empty entry publishes no skills, reaps prior copies, and keeps eligible guidelines', function (): void {
    $pkg = selectionPackage('acme/kit', ['alpha' => '', 'beta' => '']);
    $home = selectionDir('home');

    try {
        mkdir($pkg . '/resources/boost/guidelines', 0o755, recursive: true);
        file_put_contents($pkg . '/resources/boost/guidelines/voice.md', "Voice rules.\n");
        file_put_contents($pkg . '/resources/boost/guidelines/.boost-user-scope.yaml', "- voice.md\n");

        selectionEngine()->syncUser($pkg, homeRoot: $home);
        expect(selectionPublished($home))->toBe(['alpha-user', 'beta-user']);

        selectionConfig($home, "<?php return ['skills' => ['acme/kit' => []]];");
        $result = selectionEngine()->syncUser($pkg, homeRoot: $home);

        expect($result->errors)->toBe([])
            ->and(selectionPublished($home))->toBe([])
            ->and((string) file_get_contents($home . '/.claude/boost/acme__kit.md'))->toContain('Voice rules.');
    } finally {
        selectionRm($pkg, $home);
    }
});

it('narrowing the selection reaps the skills taken off the list', function (): void {
    $pkg = selectionPackage('acme/kit', ['alpha' => '', 'beta' => '']);
    $home = selectionDir('home');

    try {
        selectionEngine()->syncUser($pkg, homeRoot: $home);
        selectionConfig($home, "<?php return ['skills' => ['acme/kit' => ['alpha']]];");
        selectionEngine()->syncUser($pkg, homeRoot: $home);

        expect(selectionPublished($home))->toBe(['alpha-user']);
    } finally {
        selectionRm($pkg, $home);
    }
});

it('warns about an unknown skill name and publishes the rest', function (): void {
    $pkg = selectionPackage('acme/kit', ['alpha' => '', 'beta' => '']);
    $home = selectionDir('home');

    try {
        selectionConfig($home, "<?php return ['skills' => ['acme/kit' => ['alpha', 'alhpa']]];");

        $result = selectionEngine()->syncUser($pkg, homeRoot: $home);

        expect($result->errors)->toBe([])
            ->and($result->warnings)->toHaveCount(1)
            ->and($result->warnings[0])->toContain('Unknown skill "alhpa"')
            ->and(selectionPublished($home))->toBe(['alpha-user']);
    } finally {
        selectionRm($pkg, $home);
    }
});

it('pulls declared dependencies of the same package, transitively, and rewrites references to them', function (): void {
    $pkg = selectionPackage('acme/kit', [
        'interview' => "metadata:\n  boost-requires: \"write-spec\"\n",
        'write-spec' => "metadata:\n  boost-requires: \"clarify\"\n",
        'clarify' => '',
        'unrelated' => '',
    ]);
    $home = selectionDir('home');

    try {
        selectionConfig($home, "<?php return ['skills' => ['acme/kit' => ['interview']]];");

        $result = selectionEngine()->syncUser($pkg, homeRoot: $home);

        expect($result->errors)->toBe([])
            ->and(selectionPublished($home))->toBe(['clarify-user', 'interview-user', 'write-spec-user'])
            ->and($result->warnings)->toBe([
                'Published write-spec-user because interview requires it.',
                'Published clarify-user because write-spec requires it.',
            ])
            ->and((string) file_get_contents($home . '/.claude/skills/interview-user/SKILL.md'))->toContain('See `clarify-user`.');
    } finally {
        selectionRm($pkg, $home);
    }
});

it('warns when a declared dependency is not shipped, and still publishes the dependent', function (): void {
    $pkg = selectionPackage('acme/kit', ['interview' => "metadata:\n  boost-requires: \"elsewhere\"\n"]);
    $home = selectionDir('home');

    try {
        selectionConfig($home, "<?php return ['skills' => ['acme/kit' => ['interview']]];");

        $result = selectionEngine()->syncUser($pkg, homeRoot: $home);

        expect($result->errors)->toBe([])
            ->and($result->warnings)->toHaveCount(1)
            ->and($result->warnings[0])->toContain('Skill "elsewhere" is required by interview')
            ->and(selectionPublished($home))->toBe(['interview-user']);
    } finally {
        selectionRm($pkg, $home);
    }
});

it('a broken config fails every package closed and reaps nothing, not even a removed package', function (): void {
    $kept = selectionPackage('acme/kept', ['alpha' => '']);
    $gone = selectionPackage('acme/gone', ['beta' => '']);
    $home = selectionDir('home');

    try {
        selectionEngine()->syncUser($kept, homeRoot: $home);
        selectionEngine()->syncUser($gone, homeRoot: $home);
        selectionRm($gone);

        selectionConfig($home, "<?php return ['skill' => []];");

        $results = (new SyncEngine([new ClaudeCodeTarget()], installedPackages: new InstalledPackages([
            'acme/kept' => new PackageInfo('acme/kept', '1.0.0', $kept),
        ])))->syncUserAll(homeRoot: $home);

        expect($results)->toHaveCount(1)
            ->and($results[0]->errors[0])->toContain('unknown key "skill"')
            ->and(selectionPublished($home))->toBe(['alpha-user', 'beta-user']);
    } finally {
        selectionRm($kept, $gone, $home);
    }
});

it('reports warnings and the selection through the real CLI, including --check', function (): void {
    $pkg = selectionPackage('acme/kit', ['alpha' => '', 'beta' => '']);
    $home = selectionDir('home');

    try {
        selectionConfig($home, "<?php return ['skills' => ['acme/kit' => ['alpha', 'nope']]];");

        $check = Process::fromShellCommandline(
            'php ' . escapeshellarg(dirname(__DIR__, 2) . '/bin/boost') . ' sync --scope=user --check',
            cwd: $pkg,
            env: ['HOME' => $home],
        );
        $check->run();

        // SymfonyStyle wraps long lines; compare on collapsed whitespace.
        $output = (string) preg_replace('/\s+!?\s*/', ' ', $check->getOutput());

        expect($check->getExitCode())->toBe(1, 'drift is a failure in --check')
            ->and($output)->toContain('Unknown skill "nope"')
            ->and($output)->toContain('(9 write, 0 reap)') // one selected skill × 9 agents
            ->and(selectionPublished($home))->toBe([]);

        $sync = Process::fromShellCommandline(
            'php ' . escapeshellarg(dirname(__DIR__, 2) . '/bin/boost') . ' sync --scope=user',
            cwd: $pkg,
            env: ['HOME' => $home],
        );
        $sync->run();

        expect($sync->getExitCode())->toBe(0, $sync->getOutput() . $sync->getErrorOutput())
            ->and(selectionPublished($home))->toBe(['alpha-user']);
    } finally {
        selectionRm($pkg, $home);
    }
});

it('BoostAutoSync::syncUserScope() applies the selection and prints its warnings on stderr', function (): void {
    $pkg = selectionPackage('acme/kit', ['alpha' => '', 'beta' => '']);
    $home = selectionDir('home');
    $previousHome = getenv('HOME');
    $previousSkip = getenv('BOOST_SKIP_AUTOSYNC');

    try {
        selectionConfig($home, "<?php return ['skills' => ['acme/kit' => ['beta']]];");
        putenv('HOME=' . $home);
        putenv('BOOST_SKIP_AUTOSYNC');

        expect(BoostAutoSync::syncUserScope($pkg))->toBe(0)
            ->and(selectionPublished($home))->toBe(['beta-user']);
    } finally {
        putenv($previousHome === false ? 'HOME' : 'HOME=' . $previousHome);
        putenv($previousSkip === false ? 'BOOST_SKIP_AUTOSYNC' : 'BOOST_SKIP_AUTOSYNC=' . $previousSkip);
        selectionRm($pkg, $home);
    }
});

it('--all reports a broken config as an error even when no package ships skills', function (): void {
    $home = selectionDir('home');

    try {
        selectionConfig($home, "<?php return 'nope';");

        $results = (new SyncEngine([new ClaudeCodeTarget()], installedPackages: new InstalledPackages([])))
            ->syncUserAll(homeRoot: $home);

        expect($results)->toHaveCount(1)
            ->and($results[0]->errors[0])->toContain('must return an array');
    } finally {
        selectionRm($home);
    }
});
