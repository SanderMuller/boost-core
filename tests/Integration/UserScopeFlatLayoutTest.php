<?php declare(strict_types=1);

use SanderMuller\BoostCore\Agents\ClaudeCodeTarget;
use SanderMuller\BoostCore\Agents\CursorTarget;
use SanderMuller\BoostCore\Sync\InstalledPackages;
use SanderMuller\BoostCore\Sync\PackageInfo;
use SanderMuller\BoostCore\Sync\SyncEngine;
use SanderMuller\BoostCore\Sync\UserScopeResult;

/**
 * Flat `<skill>-user/` user-scope layout: collision guard, migration from the
 * nested `<vendor>__<pkg>/` layout, and cross-package ownership.
 */
function flatLayoutDir(string $kind): string
{
    $dir = sys_get_temp_dir() . '/boost-flat-' . $kind . '-' . bin2hex(random_bytes(8));
    mkdir($dir, 0o755, recursive: true);

    return $dir;
}

/**
 * @param  array<string, string>  $skills  skill name => body
 */
function flatLayoutPackage(string $name, array $skills): string
{
    $pkg = flatLayoutDir('pkg');
    file_put_contents($pkg . '/composer.json', json_encode(['name' => $name], JSON_THROW_ON_ERROR));

    foreach ($skills as $skill => $body) {
        mkdir($pkg . '/resources/boost/skills/' . $skill, 0o755, recursive: true);
        file_put_contents(
            $pkg . '/resources/boost/skills/' . $skill . '/SKILL.md',
            "---\nname: {$skill}\ndescription: Skill {$skill}.\n---\n{$body}\n",
        );
    }

    return $pkg;
}

function flatLayoutEngine(): SyncEngine
{
    return new SyncEngine([new ClaudeCodeTarget()], installedPackages: new InstalledPackages([]));
}

function flatLayoutRm(string ...$paths): void
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
            flatLayoutRm($path . '/' . $entry);
        }

        rmdir($path);
    }
}

it('publishes each skill flat as <skill>-user with references between them rewritten', function (): void {
    $pkg = flatLayoutPackage('acme/kit', [
        'interview' => 'Read `clarify` first. Then hand off to `write-spec`.',
        'clarify' => 'Standalone: /clarify <ask>.',
    ]);
    $home = flatLayoutDir('home');

    try {
        $result = flatLayoutEngine()->syncUser($pkg, homeRoot: $home);

        $interview = (string) file_get_contents($home . '/.claude/skills/interview-user/SKILL.md');
        $clarify = (string) file_get_contents($home . '/.claude/skills/clarify-user/SKILL.md');

        expect($result->errors)
            ->toBeEmpty()
            ->and($interview)->toContain('name: interview-user')
            ->and($interview)->toContain('Read `clarify-user` first. Then hand off to `write-spec`.')
            ->and($clarify)->toContain('Standalone: /clarify-user <ask>.')
            ->and($home . '/.claude/skills/acme__kit')->not->toBeDirectory('no nested package dir');
    } finally {
        flatLayoutRm($pkg, $home);
    }
});

it('refuses the whole package when a planned path holds an unowned file', function (): void {
    $pkg = flatLayoutPackage('acme/kit', ['alpha' => 'Alpha.', 'beta' => 'Beta.']);
    $home = flatLayoutDir('home');

    try {
        mkdir($home . '/.claude/skills/beta-user', 0o755, recursive: true);
        file_put_contents($home . '/.claude/skills/beta-user/SKILL.md', "the operator's own beta\n");

        $result = flatLayoutEngine()->syncUser($pkg, homeRoot: $home);

        expect($result->errors)->toHaveCount(1)
            ->and($result->errors[0])->toContain('beta-user/SKILL.md exists and is not owned by acme/kit')
            ->and($home . '/.claude/skills/alpha-user/SKILL.md')->not->toBeFile('nothing is written on a refused run')
            ->and((string) file_get_contents($home . '/.claude/skills/beta-user/SKILL.md'))->toBe("the operator's own beta\n")
            ->and($home . '/.boost/manifests/acme__kit.json')->not->toBeFile();
    } finally {
        flatLayoutRm($pkg, $home);
    }
});

it('reports the collision in --check mode without writing', function (): void {
    $pkg = flatLayoutPackage('acme/kit', ['alpha' => 'Alpha.']);
    $home = flatLayoutDir('home');

    try {
        mkdir($home . '/.claude/skills/alpha-user', 0o755, recursive: true);
        file_put_contents($home . '/.claude/skills/alpha-user/SKILL.md', "mine\n");

        $result = flatLayoutEngine()->syncUser($pkg, checkOnly: true, homeRoot: $home);

        expect($result->errors)->toHaveCount(1)
            ->and((string) file_get_contents($home . '/.claude/skills/alpha-user/SKILL.md'))->toBe("mine\n");
    } finally {
        flatLayoutRm($pkg, $home);
    }
});

it('adopts an unowned file whose content already equals the planned content', function (): void {
    $pkg = flatLayoutPackage('acme/kit', ['alpha' => 'Alpha.']);
    $home = flatLayoutDir('home');

    try {
        flatLayoutEngine()->syncUser($pkg, homeRoot: $home);
        // Lose the manifest: the file on disk is identical but unrecorded.
        unlink($home . '/.boost/manifests/acme__kit.json');

        $result = flatLayoutEngine()->syncUser($pkg, homeRoot: $home);

        /** @var array{emitted: array<string, string>} $manifest */
        $manifest = json_decode((string) file_get_contents($home . '/.boost/manifests/acme__kit.json'), true, 512, JSON_THROW_ON_ERROR);

        expect($result->errors)
            ->toBeEmpty()
            ->and($manifest['emitted'])->toHaveKey('.claude/skills/alpha-user/SKILL.md');
    } finally {
        flatLayoutRm($pkg, $home);
    }
});

it('refuses an operator symlink at the skill dir itself', function (): void {
    $pkg = flatLayoutPackage('acme/kit', ['alpha' => 'Alpha.']);
    $home = flatLayoutDir('home');
    $elsewhere = flatLayoutDir('elsewhere');

    try {
        mkdir($home . '/.claude/skills', 0o755, recursive: true);
        symlink($elsewhere, $home . '/.claude/skills/alpha-user');

        $result = flatLayoutEngine()->syncUser($pkg, homeRoot: $home);

        expect($result->errors)->toHaveCount(1)
            ->and($elsewhere . '/SKILL.md')->not->toBeFile('nothing written through the symlink');
    } finally {
        @unlink($home . '/.claude/skills/alpha-user');
        flatLayoutRm($pkg, $home, $elsewhere);
    }
})->skip(DIRECTORY_SEPARATOR !== '/', 'POSIX-only symlink semantics.');

it('lets the first package by name win when two packages publish the same skill name', function (): void {
    $zeta = flatLayoutPackage('zeta/tools', ['shared' => 'From zeta.']);
    $alpha = flatLayoutPackage('alpha/tools', ['shared' => 'From alpha.']);
    $home = flatLayoutDir('home');

    try {
        // Installed order puts zeta first; the bulk sync sorts by name.
        $results = (new SyncEngine([new ClaudeCodeTarget()], installedPackages: new InstalledPackages([
            'zeta/tools' => new PackageInfo('zeta/tools', '1.0.0', $zeta),
            'alpha/tools' => new PackageInfo('alpha/tools', '1.0.0', $alpha),
        ])))->syncUserAll(homeRoot: $home);

        $byPackage = [];
        foreach ($results as $result) {
            $byPackage[$result->packageName] = $result;
        }

        expect((string) file_get_contents($home . '/.claude/skills/shared-user/SKILL.md'))->toContain('From alpha.')
            ->and($byPackage['alpha/tools']->errors)
            ->toBeEmpty()
            ->and($byPackage['zeta/tools']->errors)->toHaveCount(1)
            ->and($byPackage['zeta/tools']->errors[0])->toContain('is owned by alpha/tools');
    } finally {
        flatLayoutRm($zeta, $alpha, $home);
    }
});

it('never adopts a path another package records, even with identical content', function (): void {
    $first = flatLayoutPackage('acme/first', ['shared' => 'Same.']);
    $second = flatLayoutPackage('acme/second', ['shared' => 'Same.']);
    $home = flatLayoutDir('home');

    try {
        flatLayoutEngine()->syncUser($first, homeRoot: $home);
        $result = flatLayoutEngine()->syncUser($second, homeRoot: $home);

        expect($result->errors)->toHaveCount(1)
            ->and($result->errors[0])->toContain('shared-user/SKILL.md is owned by acme/first');
    } finally {
        flatLayoutRm($first, $second, $home);
    }
});

it('never reaps a flat path another package records', function (): void {
    $old = flatLayoutPackage('acme/old', ['shared' => 'Shared.']);
    $home = flatLayoutDir('home');

    try {
        flatLayoutEngine()->syncUser($old, homeRoot: $home);
        $sha = hash_file('sha256', $home . '/.claude/skills/shared-user/SKILL.md');

        // Another package's manifest records the same path (for example after an
        // operator hand-merged two manifests).
        file_put_contents($home . '/.boost/manifests/acme__other.json', json_encode([
            'version' => 1,
            'package' => 'acme/other',
            'installPath' => $old,
            'scope' => 'user',
            'emitted' => ['.claude/skills/shared-user/SKILL.md' => $sha],
        ], JSON_THROW_ON_ERROR));

        // acme/old drops the skill: its clean-slate reap must leave the path alone.
        flatLayoutRm($old . '/resources/boost/skills/shared');
        mkdir($old . '/resources/boost/skills/other', 0o755, recursive: true);
        file_put_contents($old . '/resources/boost/skills/other/SKILL.md', "---\nname: other\n---\nOther.\n");
        flatLayoutEngine()->syncUser($old, homeRoot: $home);

        expect($home . '/.claude/skills/shared-user/SKILL.md')->toBeFile()
            ->and($home . '/.claude/skills/other-user/SKILL.md')->toBeFile();
    } finally {
        flatLayoutRm($old, $home);
    }
});

it('migrates the nested layout: writes flat files and reaps the recorded nested copies', function (): void {
    $pkg = flatLayoutPackage('acme/kit', ['alpha' => 'Alpha.', 'beta' => 'Beta.']);
    $home = flatLayoutDir('home');

    try {
        // A pre-flat sync left nested copies, recorded in the manifest.
        $nested = [
            '.claude/skills/acme__kit/alpha/SKILL.md' => "old alpha\n",
            '.claude/skills/acme__kit/beta/SKILL.md' => "old beta\n",
        ];
        $emitted = [];
        foreach ($nested as $relative => $content) {
            mkdir(dirname($home . '/' . $relative), 0o755, recursive: true);
            file_put_contents($home . '/' . $relative, $content);
            $emitted[$relative] = hash('sha256', $content);
        }

        // The operator edited beta after the last sync: its sha no longer matches.
        file_put_contents($home . '/.claude/skills/acme__kit/beta/SKILL.md', "edited beta\n");

        mkdir($home . '/.boost/manifests', 0o755, recursive: true);
        file_put_contents($home . '/.boost/manifests/acme__kit.json', json_encode([
            'version' => 1,
            'package' => 'acme/kit',
            'installPath' => $pkg,
            'scope' => 'user',
            'emitted' => $emitted,
        ], JSON_THROW_ON_ERROR));

        $result = flatLayoutEngine()->syncUser($pkg, homeRoot: $home);

        /** @var array{emitted: array<string, string>} $manifest */
        $manifest = json_decode((string) file_get_contents($home . '/.boost/manifests/acme__kit.json'), true, 512, JSON_THROW_ON_ERROR);

        expect($result->errors)
            ->toBeEmpty()
            ->and($home . '/.claude/skills/alpha-user/SKILL.md')->toBeFile()
            ->and($home . '/.claude/skills/beta-user/SKILL.md')->toBeFile()
            ->and($home . '/.claude/skills/acme__kit/alpha')->not->toBeDirectory('unchanged nested copy reaped')
            ->and((string) file_get_contents($home . '/.claude/skills/acme__kit/beta/SKILL.md'))->toBe("edited beta\n")
            ->and($manifest['emitted'])->toHaveKeys([
                '.claude/skills/alpha-user/SKILL.md',
                '.claude/skills/beta-user/SKILL.md',
            ])
            ->and($manifest['emitted'])->not->toHaveKey('.claude/skills/acme__kit/alpha/SKILL.md');
    } finally {
        flatLayoutRm($pkg, $home);
    }
});

it("reconcile-on-remove reaps a removed package's flat files", function (): void {
    $pkg = flatLayoutPackage('acme/gone', ['alpha' => 'Alpha.']);
    $home = flatLayoutDir('home');

    try {
        flatLayoutEngine()->syncUser($pkg, homeRoot: $home);
        expect($home . '/.claude/skills/alpha-user/SKILL.md')->toBeFile();

        flatLayoutRm($pkg);
        flatLayoutEngine()->syncUserAll(homeRoot: $home);

        expect($home . '/.claude/skills/alpha-user')->not->toBeDirectory()
            ->and($home . '/.boost/manifests/acme__gone.json')->not->toBeFile();
    } finally {
        flatLayoutRm($pkg, $home);
    }
});

it('never writes a legacy flat <skill>-user.md sibling or deletes one', function (): void {
    $pkg = flatLayoutPackage('acme/kit', ['alpha' => 'Alpha.']);
    $home = flatLayoutDir('home');

    try {
        mkdir($home . '/.claude/skills', 0o755, recursive: true);
        file_put_contents($home . '/.claude/skills/alpha-user.md', "someone else's file\n");

        flatLayoutEngine()->syncUser($pkg, homeRoot: $home);

        expect((string) file_get_contents($home . '/.claude/skills/alpha-user.md'))->toBe("someone else's file\n");
    } finally {
        flatLayoutRm($pkg, $home);
    }
});

it('warns and skips a skill whose -user name is over the limit', function (): void {
    $long = str_repeat('a', 60);
    $pkg = flatLayoutPackage('acme/kit', [$long => 'Long.', 'ok' => 'Ok.']);
    $home = flatLayoutDir('home');

    try {
        $result = flatLayoutEngine()->syncUser($pkg, homeRoot: $home);

        expect($result->errors)
            ->toBeEmpty()
            ->and($result->warnings)->toHaveCount(1)
            ->and($home . '/.claude/skills/ok-user/SKILL.md')->toBeFile()
            ->and($home . '/.claude/skills/' . $long . '-user')->not->toBeDirectory();
    } finally {
        flatLayoutRm($pkg, $home);
    }
});

it('publishes the same flat path for every agent', function (): void {
    $pkg = flatLayoutPackage('acme/kit', ['alpha' => 'Alpha.']);
    $home = flatLayoutDir('home');

    try {
        (new SyncEngine([new ClaudeCodeTarget(), new CursorTarget()], installedPackages: new InstalledPackages([])))
            ->syncUser($pkg, homeRoot: $home);

        expect($home . '/.claude/skills/alpha-user/SKILL.md')->toBeFile()
            ->and($home . '/.cursor/skills/alpha-user/SKILL.md')->toBeFile();
    } finally {
        flatLayoutRm($pkg, $home);
    }
});

it('--all publishes a replacement skill in one run when the removed package owned the name', function (): void {
    $gone = flatLayoutPackage('acme/gone', ['shared' => 'Old.']);
    $next = flatLayoutPackage('acme/next', ['shared' => 'New.']);
    $home = flatLayoutDir('home');

    try {
        flatLayoutEngine()->syncUser($gone, homeRoot: $home);
        flatLayoutRm($gone);

        $results = (new SyncEngine([new ClaudeCodeTarget()], installedPackages: new InstalledPackages([
            'acme/next' => new PackageInfo('acme/next', '1.0.0', $next),
        ])))->syncUserAll(homeRoot: $home);

        $errors = array_merge(...array_map(static fn (UserScopeResult $result): array => $result->errors, $results));

        expect($errors)
            ->toBeEmpty()
            ->and((string) file_get_contents($home . '/.claude/skills/shared-user/SKILL.md'))->toContain('New.')
            ->and($home . '/.boost/manifests/acme__gone.json')->not->toBeFile();
    } finally {
        flatLayoutRm($gone, $next, $home);
    }
});

it('never reaps a manifest path with a traversal segment', function (): void {
    $pkg = flatLayoutPackage('acme/kit', ['alpha' => 'Alpha.']);
    $home = flatLayoutDir('home');

    try {
        flatLayoutEngine()->syncUser($pkg, homeRoot: $home);

        // A hand-edited manifest points through a real -user dir at another file.
        file_put_contents($home . '/.claude/CLAUDE.md', "operator guidance\n");
        $manifestPath = $home . '/.boost/manifests/acme__kit.json';
        /** @var array{emitted: array<string, string>} $manifest */
        $manifest = json_decode((string) file_get_contents($manifestPath), true, 512, JSON_THROW_ON_ERROR);
        $manifest['emitted']['.claude/skills/alpha-user/../../CLAUDE.md'] = hash('sha256', "operator guidance\n");
        file_put_contents($manifestPath, json_encode($manifest, JSON_THROW_ON_ERROR));

        flatLayoutEngine()->syncUser($pkg, homeRoot: $home);

        expect($home . '/.claude/CLAUDE.md')->toBeFile();
    } finally {
        flatLayoutRm($pkg, $home);
    }
});

it('refuses a path another package records even after the file is deleted', function (): void {
    $first = flatLayoutPackage('acme/first', ['shared' => 'From first.']);
    $second = flatLayoutPackage('acme/second', ['shared' => 'From second.']);
    $home = flatLayoutDir('home');

    try {
        flatLayoutEngine()->syncUser($first, homeRoot: $home);
        flatLayoutRm($home . '/.claude/skills/shared-user');

        $result = flatLayoutEngine()->syncUser($second, homeRoot: $home);

        expect($result->errors)->toHaveCount(1)
            ->and($result->errors[0])->toContain('is owned by acme/first')
            ->and($home . '/.claude/skills/shared-user/SKILL.md')->not->toBeFile();
    } finally {
        flatLayoutRm($first, $second, $home);
    }
});

it('--check predicts a clash with a package synced earlier in the same run', function (): void {
    $zeta = flatLayoutPackage('zeta/tools', ['shared' => 'From zeta.']);
    $alpha = flatLayoutPackage('alpha/tools', ['shared' => 'From alpha.']);
    $home = flatLayoutDir('home');

    try {
        $results = (new SyncEngine([new ClaudeCodeTarget()], installedPackages: new InstalledPackages([
            'zeta/tools' => new PackageInfo('zeta/tools', '1.0.0', $zeta),
            'alpha/tools' => new PackageInfo('alpha/tools', '1.0.0', $alpha),
        ])))->syncUserAll(checkOnly: true, homeRoot: $home);

        $byPackage = [];
        foreach ($results as $result) {
            $byPackage[$result->packageName] = $result;
        }

        expect($byPackage['alpha/tools']->errors)
            ->toBeEmpty()
            ->and($byPackage['zeta/tools']->errors)->toHaveCount(1)
            ->and($byPackage['zeta/tools']->errors[0])->toContain('is owned by alpha/tools')
            ->and($home . '/.claude/skills/shared-user')->not->toBeDirectory();
    } finally {
        flatLayoutRm($zeta, $alpha, $home);
    }
});

it('--check predicts the hand-over from a removed package without a clash', function (): void {
    $gone = flatLayoutPackage('acme/gone', ['shared' => 'Old.']);
    $next = flatLayoutPackage('acme/next', ['shared' => 'New.']);
    $home = flatLayoutDir('home');

    try {
        flatLayoutEngine()->syncUser($gone, homeRoot: $home);
        flatLayoutRm($gone);

        $results = (new SyncEngine([new ClaudeCodeTarget()], installedPackages: new InstalledPackages([
            'acme/next' => new PackageInfo('acme/next', '1.0.0', $next),
        ])))->syncUserAll(checkOnly: true, homeRoot: $home);

        $errors = array_merge(...array_map(static fn (UserScopeResult $result): array => $result->errors, $results));

        expect($errors)
            ->toBeEmpty()
            ->and((string) file_get_contents($home . '/.claude/skills/shared-user/SKILL.md'))->toContain('Old.')
            ->and($home . '/.boost/manifests/acme__gone.json')->toBeFile();
    } finally {
        flatLayoutRm($gone, $next, $home);
    }
});

it('never reaps through a -user dir the operator replaced with a symlink', function (): void {
    $pkg = flatLayoutPackage('acme/kit', ['alpha' => 'Alpha.', 'beta' => 'Beta.']);
    $home = flatLayoutDir('home');
    $elsewhere = flatLayoutDir('elsewhere');

    try {
        flatLayoutEngine()->syncUser($pkg, homeRoot: $home);

        // The operator moves the published dir elsewhere and links it back.
        rename($home . '/.claude/skills/beta-user/SKILL.md', $elsewhere . '/SKILL.md');
        rmdir($home . '/.claude/skills/beta-user');
        symlink($elsewhere, $home . '/.claude/skills/beta-user');

        flatLayoutRm($pkg . '/resources/boost/skills/beta');
        flatLayoutEngine()->syncUser($pkg, homeRoot: $home);

        expect($elsewhere . '/SKILL.md')->toBeFile('the file behind the symlink survives');
    } finally {
        @unlink($home . '/.claude/skills/beta-user');
        flatLayoutRm($pkg, $home, $elsewhere);
    }
})->skip(DIRECTORY_SEPARATOR !== '/', 'POSIX-only symlink semantics.');

it('--all hands a skill over in one run when the outgoing owner sorts later', function (bool $checkOnly): void {
    $zeta = flatLayoutPackage('zeta/tools', ['shared' => 'From zeta.']);
    $alpha = flatLayoutPackage('alpha/tools', ['shared' => 'From alpha.']);
    $home = flatLayoutDir('home');

    try {
        // zeta owns `shared-user`; alpha is not yet selecting it.
        mkdir($home . '/.boost', 0o755, recursive: true);
        file_put_contents($home . '/.boost/user-scope.php', "<?php return ['skills' => ['alpha/tools' => []]];");
        $engine = new SyncEngine([new ClaudeCodeTarget()], installedPackages: new InstalledPackages([
            'zeta/tools' => new PackageInfo('zeta/tools', '1.0.0', $zeta),
            'alpha/tools' => new PackageInfo('alpha/tools', '1.0.0', $alpha),
        ]));
        $engine->syncUserAll(homeRoot: $home);
        expect((string) file_get_contents($home . '/.claude/skills/shared-user/SKILL.md'))->toContain('From zeta.');

        // Swap: alpha selects it, zeta drops it.
        file_put_contents($home . '/.boost/user-scope.php', "<?php return ['skills' => ['alpha/tools' => ['shared'], 'zeta/tools' => []]];");
        $results = $engine->syncUserAll(checkOnly: $checkOnly, homeRoot: $home);

        $errors = array_merge(...array_map(static fn (UserScopeResult $result): array => $result->errors, $results));

        expect($errors)
            ->toBeEmpty();
        if (! $checkOnly) {
            expect((string) file_get_contents($home . '/.claude/skills/shared-user/SKILL.md'))->toContain('From alpha.');
        }
    } finally {
        flatLayoutRm($zeta, $alpha, $home);
    }
})->with(['real run' => false, 'check' => true]);
