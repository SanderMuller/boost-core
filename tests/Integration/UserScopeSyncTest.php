<?php declare(strict_types=1);

use SanderMuller\BoostCore\Agents\ClaudeCodeTarget;
use SanderMuller\BoostCore\Agents\CursorTarget;
use SanderMuller\BoostCore\Sync\InstalledPackages;
use SanderMuller\BoostCore\Sync\PackageInfo;
use SanderMuller\BoostCore\Sync\SyncEngine;
use SanderMuller\BoostCore\Sync\WriteAction;
use SanderMuller\BoostCore\Sync\WrittenFile;

/**
 * @return array{package: string, home: string}
 */
function makeUserScopeTempDirs(): array
{
    $packageRoot = sys_get_temp_dir() . '/boost-userscope-pkg-' . bin2hex(random_bytes(8));
    $homeRoot = sys_get_temp_dir() . '/boost-userscope-home-' . bin2hex(random_bytes(8));
    mkdir($packageRoot . '/resources/boost/skills', 0o755, recursive: true);
    mkdir($homeRoot, 0o755, recursive: true);

    return ['package' => $packageRoot, 'home' => $homeRoot];
}

function rmTreeUserScope(string $path): void
{
    if (! is_dir($path)) {
        return;
    }

    $entries = scandir($path);
    if ($entries === false) {
        return;
    }

    foreach ($entries as $entry) {
        if ($entry === '.') {
            continue;
        }

        if ($entry === '..') {
            continue;
        }

        $full = $path . '/' . $entry;
        if (is_dir($full) && ! is_link($full)) {
            rmTreeUserScope($full);
        } else {
            unlink($full);
        }
    }

    rmdir($path);
}

it('migrates legacy basename dir to vendor-namespaced slug on first sync (0.3 → 0.4)', function (): void {
    $dirs = makeUserScopeTempDirs();
    $pkg = $dirs['package'];
    $home = $dirs['home'];

    try {
        // Pre-0.4 layout: THIS package's own older sync wrote
        // `current-skill/SKILL.md` under its basename dir. The ownership
        // check passes (file matches what plan() would produce), so the
        // dir migrates; the fresh sync then overwrites the body in place.
        mkdir($home . '/.claude/skills/legacy-tool/current-skill', 0o755, recursive: true);
        file_put_contents(
            $home . '/.claude/skills/legacy-tool/current-skill/SKILL.md',
            "---\nname: current-skill\n---\nStale body.\n",
        );

        file_put_contents(
            $pkg . '/composer.json',
            json_encode(['name' => 'acme/legacy-tool'], JSON_THROW_ON_ERROR),
        );
        file_put_contents(
            $pkg . '/resources/boost/skills/current-skill.md',
            "---\nname: current-skill\n---\nFresh body.\n",
        );

        (new SyncEngine([
            new ClaudeCodeTarget(),
        ], installedPackages: new InstalledPackages([])))->syncUser($pkg, homeRoot: $home);

        $migrated = (string) file_get_contents($home . '/.claude/skills/acme__legacy-tool/current-skill/SKILL.md');

        expect(is_dir($home . '/.claude/skills/legacy-tool'))->toBeFalse('old basename dir should be renamed away')
            ->and(is_dir($home . '/.claude/skills/acme__legacy-tool'))->toBeTrue('new vendor-namespaced slug dir should exist')
            ->and($migrated)->toContain('Fresh body.');
    } finally {
        rmTreeUserScope($pkg);
        rmTreeUserScope($home);
    }
});

it('migration is idempotent — second sync no-ops cleanly', function (): void {
    $dirs = makeUserScopeTempDirs();
    $pkg = $dirs['package'];
    $home = $dirs['home'];

    try {
        mkdir($home . '/.claude/skills/legacy-tool/current-skill', 0o755, recursive: true);
        file_put_contents(
            $home . '/.claude/skills/legacy-tool/current-skill/SKILL.md',
            "---\nname: current-skill\n---\nStale.\n",
        );

        file_put_contents($pkg . '/composer.json', json_encode(['name' => 'acme/legacy-tool'], JSON_THROW_ON_ERROR));
        file_put_contents($pkg . '/resources/boost/skills/current-skill.md', "---\nname: current-skill\n---\nBody.\n");

        $engine = new SyncEngine([new ClaudeCodeTarget()], installedPackages: new InstalledPackages([]));

        $engine->syncUser($pkg, homeRoot: $home);
        $afterFirst = (string) file_get_contents($home . '/.claude/skills/acme__legacy-tool/current-skill/SKILL.md');

        // Second sync must not re-migrate or corrupt.
        $engine->syncUser($pkg, homeRoot: $home);

        expect(is_dir($home . '/.claude/skills/legacy-tool'))->toBeFalse()
            ->and((string) file_get_contents($home . '/.claude/skills/acme__legacy-tool/current-skill/SKILL.md'))->toBe($afterFirst);
    } finally {
        rmTreeUserScope($pkg);
        rmTreeUserScope($home);
    }
});

it('migration is safe — does not overwrite an existing new-slug dir', function (): void {
    $dirs = makeUserScopeTempDirs();
    $pkg = $dirs['package'];
    $home = $dirs['home'];

    try {
        // Both old AND new dirs present — partial migration scenario, or
        // user manually created the new dir. Don't clobber.
        mkdir($home . '/.claude/skills/legacy-tool/some-skill', 0o755, recursive: true);
        file_put_contents($home . '/.claude/skills/legacy-tool/some-skill/SKILL.md', "---\nname: some-skill\n---\nOLD.\n");
        mkdir($home . '/.claude/skills/acme__legacy-tool', 0o755, recursive: true);
        file_put_contents($home . '/.claude/skills/acme__legacy-tool/preexisting.md', "NEW\n");

        file_put_contents($pkg . '/composer.json', json_encode(['name' => 'acme/legacy-tool'], JSON_THROW_ON_ERROR));
        file_put_contents($pkg . '/resources/boost/skills/some-skill.md', "---\nname: some-skill\n---\nBody.\n");

        (new SyncEngine([new ClaudeCodeTarget()], installedPackages: new InstalledPackages([])))
            ->syncUser($pkg, homeRoot: $home);

        // Old dir untouched — migration skipped because new dir already existed.
        expect(is_dir($home . '/.claude/skills/legacy-tool'))->toBeTrue('legacy dir preserved when new dir exists')
            ->and(file_exists($home . '/.claude/skills/legacy-tool/some-skill/SKILL.md'))->toBeTrue()
            ->and(file_exists($home . '/.claude/skills/acme__legacy-tool/preexisting.md'))->toBeTrue()
            ->and(file_exists($home . '/.claude/skills/acme__legacy-tool/some-skill/SKILL.md'))->toBeTrue();
    } finally {
        rmTreeUserScope($pkg);
        rmTreeUserScope($home);
    }
});

it('migration is skipped when legacy dir contains foreign content (pre-0.2 collision state)', function (): void {
    $dirs = makeUserScopeTempDirs();
    $pkg = $dirs['package'];
    $home = $dirs['home'];

    try {
        // Pre-0.2 collision state: another package with the same basename
        // wrote a skill to `~/.claude/skills/legacy-tool/` that THIS package
        // cannot produce. Ownership check must refuse the rename — moving
        // it under this package's vendor slug would mis-attribute the data.
        mkdir($home . '/.claude/skills/legacy-tool/foreign-skill', 0o755, recursive: true);
        file_put_contents(
            $home . '/.claude/skills/legacy-tool/foreign-skill/SKILL.md',
            "---\nname: foreign-skill\n---\nWritten by some other package.\n",
        );

        file_put_contents($pkg . '/composer.json', json_encode(['name' => 'acme/legacy-tool'], JSON_THROW_ON_ERROR));
        file_put_contents($pkg . '/resources/boost/skills/my-skill.md', "---\nname: my-skill\n---\nMine.\n");

        (new SyncEngine([new ClaudeCodeTarget()], installedPackages: new InstalledPackages([])))
            ->syncUser($pkg, homeRoot: $home);

        expect(is_dir($home . '/.claude/skills/legacy-tool'))->toBeTrue('foreign-owned legacy dir preserved for manual cleanup')
            ->and(file_exists($home . '/.claude/skills/legacy-tool/foreign-skill/SKILL.md'))->toBeTrue('foreign content not moved')
            ->and(file_exists($home . '/.claude/skills/acme__legacy-tool/my-skill/SKILL.md'))->toBeTrue('fresh sync still landed at new slug');
    } finally {
        rmTreeUserScope($pkg);
        rmTreeUserScope($home);
    }
});

it('user-scope sync does NOT prune the legacy sibling if the new write fails', function (): void {
    $dirs = makeUserScopeTempDirs();
    $pkg = $dirs['package'];
    $home = $dirs['home'];

    try {
        file_put_contents(
            $pkg . '/composer.json',
            json_encode(['name' => 'test-vendor/sample-tool'], JSON_THROW_ON_ERROR),
        );
        file_put_contents(
            $pkg . '/resources/boost/skills/sample-skill.md',
            "---\nname: sample-skill\n---\nNew body.\n",
        );

        // Legacy flat sibling + a blocker file at the target dir path so
        // FileWriter can't mkdir/write the new SKILL.md.
        mkdir($home . '/.claude/skills/test-vendor__sample-tool', 0o755, recursive: true);
        file_put_contents($home . '/.claude/skills/test-vendor__sample-tool/sample-skill.md', "last good copy\n");
        file_put_contents($home . '/.claude/skills/test-vendor__sample-tool/sample-skill', "blocker\n");

        $result = (new SyncEngine([
            new ClaudeCodeTarget(),
        ], installedPackages: new InstalledPackages([])))->syncUser($pkg, homeRoot: $home);

        expect($result->hasErrors())->toBeTrue()
            ->and(file_exists($home . '/.claude/skills/test-vendor__sample-tool/sample-skill.md'))
            ->toBeTrue()
            ->and(file_get_contents($home . '/.claude/skills/test-vendor__sample-tool/sample-skill.md'))
            ->toContain('last good copy');
    } finally {
        rmTreeUserScope($pkg);
        rmTreeUserScope($home);
    }
});

it('user-scope sync does NOT double-nest when the skill dir name matches the package basename', function (): void {
    $dirs = makeUserScopeTempDirs();
    $pkg = $dirs['package'];
    $home = $dirs['home'];

    try {
        // Common single-skill tooling shape: package `vendor/repo-init` ships its
        // one skill at `resources/boost/skills/repo-init/SKILL.md`. Before the
        // dedupe in rewriteForUserScope, this landed at
        // `~/.claude/skills/repo-init/repo-init/SKILL.md` — package suffix and
        // skill dir both injected. Expected shape is one level only.
        file_put_contents(
            $pkg . '/composer.json',
            json_encode(['name' => 'vendor/repo-init'], JSON_THROW_ON_ERROR),
        );
        mkdir($pkg . '/resources/boost/skills/repo-init', 0o755, recursive: true);
        file_put_contents(
            $pkg . '/resources/boost/skills/repo-init/SKILL.md',
            "---\nname: repo-init\n---\nBody.\n",
        );

        (new SyncEngine([
            new ClaudeCodeTarget(),
        ], installedPackages: new InstalledPackages([])))->syncUser($pkg, homeRoot: $home);

        expect(file_exists($home . '/.claude/skills/vendor__repo-init/SKILL.md'))->toBeTrue()
            ->and(file_exists($home . '/.claude/skills/vendor__repo-init/repo-init/SKILL.md'))
            ->toBeFalse();
    } finally {
        rmTreeUserScope($pkg);
        rmTreeUserScope($home);
    }
});

it('user-scope sync prunes a legacy flat `<skill>.md` sibling alongside the new dir', function (): void {
    $dirs = makeUserScopeTempDirs();
    $pkg = $dirs['package'];
    $home = $dirs['home'];

    try {
        file_put_contents(
            $pkg . '/composer.json',
            json_encode(['name' => 'test-vendor/sample-tool'], JSON_THROW_ON_ERROR),
        );
        file_put_contents(
            $pkg . '/resources/boost/skills/sample-skill.md',
            "---\nname: sample-skill\n---\nNew body.\n",
        );

        // Pre-existing flat output from an earlier sync — should be deleted
        // when the new `<skill>/SKILL.md` is written successfully.
        mkdir($home . '/.claude/skills/test-vendor__sample-tool', 0o755, recursive: true);
        file_put_contents($home . '/.claude/skills/test-vendor__sample-tool/sample-skill.md', "stale\n");

        (new SyncEngine([
            new ClaudeCodeTarget(),
        ], installedPackages: new InstalledPackages([])))->syncUser($pkg, homeRoot: $home);

        expect(file_exists($home . '/.claude/skills/test-vendor__sample-tool/sample-skill/SKILL.md'))->toBeTrue()
            ->and(file_exists($home . '/.claude/skills/test-vendor__sample-tool/sample-skill.md'))
            ->toBeFalse();
    } finally {
        rmTreeUserScope($pkg);
        rmTreeUserScope($home);
    }
});

it('user-scope sync fans skills into ~/.{agent}/skills/<package>/ under HOME', function (): void {
    $dirs = makeUserScopeTempDirs();
    $pkg = $dirs['package'];
    $home = $dirs['home'];

    try {
        file_put_contents(
            $pkg . '/composer.json',
            json_encode(['name' => 'test-vendor/sample-tool'], JSON_THROW_ON_ERROR),
        );
        file_put_contents(
            $pkg . '/resources/boost/skills/sample-skill.md',
            "---\nname: sample-skill\ndescription: Test skill.\n---\nBody.\n",
        );

        $result = (new SyncEngine([
            new ClaudeCodeTarget(),
            new CursorTarget(),
        ], installedPackages: new InstalledPackages([])))->syncUser($pkg, homeRoot: $home);

        expect($result->hasErrors())->toBeFalse()
            ->and($result->packageName)
            ->toBe('test-vendor/sample-tool')
            ->and($result->homeRoot)
            ->toBe($home)
            ->and(file_exists($home . '/.claude/skills/test-vendor__sample-tool/sample-skill/SKILL.md'))
            ->toBeTrue()
            ->and(file_exists($home . '/.cursor/skills/test-vendor__sample-tool/sample-skill/SKILL.md'))
            ->toBeTrue();

        $written = (string) file_get_contents($home . '/.claude/skills/test-vendor__sample-tool/sample-skill/SKILL.md');
        expect($written)->toContain('Body.')
            ->toContain('name: sample-skill');
    } finally {
        rmTreeUserScope($pkg);
        rmTreeUserScope($home);
    }
});

it('user-scope skips guideline files (CLAUDE.md, AGENTS.md) — no home-dir pollution', function (): void {
    $dirs = makeUserScopeTempDirs();
    $pkg = $dirs['package'];
    $home = $dirs['home'];

    try {
        file_put_contents($pkg . '/composer.json', json_encode(['name' => 'test/pkg'], JSON_THROW_ON_ERROR));
        mkdir($pkg . '/resources/boost/guidelines', 0o755, recursive: true);
        file_put_contents(
            $pkg . '/resources/boost/skills/x.md',
            "---\nname: x\ndescription: A skill.\n---\nBody.\n",
        );

        (new SyncEngine([
            new ClaudeCodeTarget(),
        ], installedPackages: new InstalledPackages([])))->syncUser($pkg, homeRoot: $home);

        expect(file_exists($home . '/.claude/skills/test__pkg/x/SKILL.md'))->toBeTrue()
            ->and(file_exists($home . '/CLAUDE.md'))
            ->toBeFalse()
            ->and(file_exists($home . '/AGENTS.md'))
            ->toBeFalse()
            ->and(file_exists($home . '/GEMINI.md'))
            ->toBeFalse();
    } finally {
        rmTreeUserScope($pkg);
        rmTreeUserScope($home);
    }
});

it('user-scope check mode reports drift without writing', function (): void {
    $dirs = makeUserScopeTempDirs();
    $pkg = $dirs['package'];
    $home = $dirs['home'];

    try {
        file_put_contents($pkg . '/composer.json', json_encode(['name' => 'test/pkg'], JSON_THROW_ON_ERROR));
        file_put_contents($pkg . '/resources/boost/skills/x.md', "---\nname: x\n---\nBody.\n");

        $result = (new SyncEngine([
            new ClaudeCodeTarget(),
        ], installedPackages: new InstalledPackages([])))->syncUser($pkg, checkOnly: true, homeRoot: $home);

        expect($result->hasDrift())->toBeTrue()
            ->and($result->countByAction(WriteAction::WOULD_WRITE))
            ->toBe(1)
            ->and(file_exists($home . '/.claude/skills/test__pkg/x.md'))
            ->toBeFalse();
    } finally {
        rmTreeUserScope($pkg);
        rmTreeUserScope($home);
    }
});

it('user-scope errors when composer.json is missing', function (): void {
    $dirs = makeUserScopeTempDirs();
    $pkg = $dirs['package'];
    $home = $dirs['home'];

    try {
        $result = (new SyncEngine([], installedPackages: new InstalledPackages([])))
            ->syncUser($pkg, homeRoot: $home);

        expect($result->hasErrors())->toBeTrue()
            ->and($result->errors[0])
            ->toContain('composer.json not found');
    } finally {
        rmTreeUserScope($pkg);
        rmTreeUserScope($home);
    }
});

it('syncUserAll syncs every installed package that ships skills', function (): void {
    $home = sys_get_temp_dir() . '/boost-uall-home-' . bin2hex(random_bytes(8));
    $pkgA = sys_get_temp_dir() . '/boost-uall-a-' . bin2hex(random_bytes(8));
    $pkgB = sys_get_temp_dir() . '/boost-uall-b-' . bin2hex(random_bytes(8));
    mkdir($home, 0o755, recursive: true);
    mkdir($pkgA . '/resources/boost/skills', 0o755, recursive: true);
    mkdir($pkgB . '/resources/boost/skills', 0o755, recursive: true);

    try {
        file_put_contents($pkgA . '/composer.json', json_encode(['name' => 'acme/pack-a'], JSON_THROW_ON_ERROR));
        file_put_contents($pkgA . '/resources/boost/skills/skill-a.md', "---\nname: skill-a\n---\nA body.\n");
        file_put_contents($pkgB . '/composer.json', json_encode(['name' => 'acme/pack-b'], JSON_THROW_ON_ERROR));
        file_put_contents($pkgB . '/resources/boost/skills/skill-b.md', "---\nname: skill-b\n---\nB body.\n");

        $results = (new SyncEngine([new ClaudeCodeTarget()], installedPackages: new InstalledPackages([
            'acme/pack-a' => new PackageInfo('acme/pack-a', '1.0.0', $pkgA),
            'acme/pack-b' => new PackageInfo('acme/pack-b', '1.0.0', $pkgB),
        ])))->syncUserAll(homeRoot: $home);

        expect($results)->toHaveCount(2)
            ->and(file_exists($home . '/.claude/skills/acme__pack-a/skill-a/SKILL.md'))->toBeTrue()
            ->and(file_exists($home . '/.claude/skills/acme__pack-b/skill-b/SKILL.md'))->toBeTrue();
    } finally {
        rmTreeUserScope($home);
        rmTreeUserScope($pkgA);
        rmTreeUserScope($pkgB);
    }
});

it('syncUserAll skips a package that ships no skills', function (): void {
    $home = sys_get_temp_dir() . '/boost-uall-home-' . bin2hex(random_bytes(8));
    $withSkills = sys_get_temp_dir() . '/boost-uall-s-' . bin2hex(random_bytes(8));
    $noSkills = sys_get_temp_dir() . '/boost-uall-n-' . bin2hex(random_bytes(8));
    mkdir($home, 0o755, recursive: true);
    mkdir($withSkills . '/resources/boost/skills', 0o755, recursive: true);
    mkdir($noSkills, 0o755, recursive: true);

    try {
        file_put_contents($withSkills . '/composer.json', json_encode(['name' => 'acme/has-skills'], JSON_THROW_ON_ERROR));
        file_put_contents($withSkills . '/resources/boost/skills/x.md', "---\nname: x\n---\nBody.\n");
        file_put_contents($noSkills . '/composer.json', json_encode(['name' => 'acme/no-skills'], JSON_THROW_ON_ERROR));

        $results = (new SyncEngine([new ClaudeCodeTarget()], installedPackages: new InstalledPackages([
            'acme/has-skills' => new PackageInfo('acme/has-skills', '1.0.0', $withSkills),
            'acme/no-skills' => new PackageInfo('acme/no-skills', '1.0.0', $noSkills),
        ])))->syncUserAll(homeRoot: $home);

        expect($results)->toHaveCount(1)
            ->and($results[0]->packageName)->toBe('acme/has-skills');
    } finally {
        rmTreeUserScope($home);
        rmTreeUserScope($withSkills);
        rmTreeUserScope($noSkills);
    }
});

it('0.19.0: user-scope sync writes a per-package manifest recording emitted paths + install path', function (): void {
    $dirs = makeUserScopeTempDirs();
    $pkg = $dirs['package'];
    $home = $dirs['home'];

    try {
        file_put_contents($pkg . '/composer.json', json_encode(['name' => 'acme/multi'], JSON_THROW_ON_ERROR));
        mkdir($pkg . '/resources/boost/skills/alpha', 0o755, recursive: true);
        file_put_contents($pkg . '/resources/boost/skills/alpha/SKILL.md', "---\nname: alpha\ndescription: A.\n---\nAlpha body.\n");

        (new SyncEngine([new ClaudeCodeTarget()], installedPackages: new InstalledPackages([])))->syncUser($pkg, homeRoot: $home);

        $manifestPath = $home . '/.boost/manifests/acme__multi.json';
        expect($manifestPath)->toBeFile()
            ->and($home . '/.claude/skills/acme__multi/alpha/SKILL.md')->toBeFile();

        /** @var array{installPath: string, scope: string, emitted: array<string, string>} $manifest */
        $manifest = json_decode((string) file_get_contents($manifestPath), true, 512, JSON_THROW_ON_ERROR);
        expect($manifest['installPath'])->toBe($pkg)
            ->and($manifest['scope'])->toBe('user')
            ->and($manifest['emitted'])->toHaveKey('.claude/skills/acme__multi/alpha/SKILL.md');
    } finally {
        rmTreeUserScope($pkg);
        rmTreeUserScope($home);
    }
});

it('0.19.0 clean-slate: dropping a skill reaps its user-scope copy on the next sync; siblings kept', function (): void {
    $dirs = makeUserScopeTempDirs();
    $pkg = $dirs['package'];
    $home = $dirs['home'];

    try {
        file_put_contents($pkg . '/composer.json', json_encode(['name' => 'acme/multi'], JSON_THROW_ON_ERROR));
        mkdir($pkg . '/resources/boost/skills/alpha', 0o755, recursive: true);
        mkdir($pkg . '/resources/boost/skills/beta', 0o755, recursive: true);
        file_put_contents($pkg . '/resources/boost/skills/alpha/SKILL.md', "---\nname: alpha\ndescription: A.\n---\nAlpha.\n");
        file_put_contents($pkg . '/resources/boost/skills/beta/SKILL.md', "---\nname: beta\ndescription: B.\n---\nBeta.\n");

        $engine = new SyncEngine([new ClaudeCodeTarget()], installedPackages: new InstalledPackages([]));
        $engine->syncUser($pkg, homeRoot: $home);
        expect($home . '/.claude/skills/acme__multi/beta/SKILL.md')->toBeFile();

        // Drop beta from the package, re-sync.
        rmTreeUserScope($pkg . '/resources/boost/skills/beta');
        $result = $engine->syncUser($pkg, homeRoot: $home);

        expect($home . '/.claude/skills/acme__multi/beta/SKILL.md')->not->toBeFile('dropped skill reaped')
            ->and($home . '/.claude/skills/acme__multi/alpha/SKILL.md')->toBeFile('sibling kept');
        $reaped = array_filter($result->writes, static fn (WrittenFile $w): bool => str_contains($w->relativePath, 'beta') && $w->action === WriteAction::DELETED);
        expect($reaped)->not->toBeEmpty();
    } finally {
        rmTreeUserScope($pkg);
        rmTreeUserScope($home);
    }
});

it('0.19.0 safety: an operator-edited user-scope file (sha diverged) is never reaped', function (): void {
    $dirs = makeUserScopeTempDirs();
    $pkg = $dirs['package'];
    $home = $dirs['home'];

    try {
        file_put_contents($pkg . '/composer.json', json_encode(['name' => 'acme/multi'], JSON_THROW_ON_ERROR));
        mkdir($pkg . '/resources/boost/skills/alpha', 0o755, recursive: true);
        file_put_contents($pkg . '/resources/boost/skills/alpha/SKILL.md', "---\nname: alpha\ndescription: A.\n---\nAlpha.\n");

        $engine = new SyncEngine([new ClaudeCodeTarget()], installedPackages: new InstalledPackages([]));
        $engine->syncUser($pkg, homeRoot: $home);

        // Operator hand-edits the emitted file (sha now diverges from the manifest).
        $emitted = $home . '/.claude/skills/acme__multi/alpha/SKILL.md';
        file_put_contents($emitted, "operator's own notes\n");

        // Drop the skill from source → clean-slate would reap it, but the sha
        // diverged so it must be preserved.
        rmTreeUserScope($pkg . '/resources/boost/skills/alpha');
        $engine->syncUser($pkg, homeRoot: $home);

        expect($emitted)->toBeFile('operator-edited file must survive')
            ->and(file_get_contents($emitted))->toBe("operator's own notes\n");
    } finally {
        rmTreeUserScope($pkg);
        rmTreeUserScope($home);
    }
});

it('0.19.0 reconcile-on-remove: --scope=user --all reaps a removed package and deletes its manifest', function (): void {
    $dirs = makeUserScopeTempDirs();
    $pkg = $dirs['package'];
    $home = $dirs['home'];

    try {
        file_put_contents($pkg . '/composer.json', json_encode(['name' => 'acme/gone'], JSON_THROW_ON_ERROR));
        mkdir($pkg . '/resources/boost/skills/alpha', 0o755, recursive: true);
        file_put_contents($pkg . '/resources/boost/skills/alpha/SKILL.md', "---\nname: alpha\ndescription: A.\n---\nAlpha.\n");

        // Install: user-scope sync the package → files + manifest.
        (new SyncEngine([new ClaudeCodeTarget()], installedPackages: new InstalledPackages([])))->syncUser($pkg, homeRoot: $home);
        expect($home . '/.claude/skills/acme__gone/alpha/SKILL.md')->toBeFile()
            ->and($home . '/.boost/manifests/acme__gone.json')->toBeFile();

        // Remove: the package install dir is gone (composer global remove).
        rmTreeUserScope($pkg);

        // --scope=user --all (nothing installed now) → reconcile reaps the orphan.
        (new SyncEngine([new ClaudeCodeTarget()], installedPackages: new InstalledPackages([])))->syncUserAll(homeRoot: $home);

        expect($home . '/.claude/skills/acme__gone/alpha/SKILL.md')->not->toBeFile('removed package files reaped')
            ->and($home . '/.claude/skills/acme__gone')->not->toBeDirectory('empty slug dir pruned')
            ->and($home . '/.boost/manifests/acme__gone.json')->not->toBeFile('stale manifest deleted');
    } finally {
        rmTreeUserScope($pkg);
        rmTreeUserScope($home);
    }
});

it('0.19.0 wrong-context safety: --all does NOT reap a package whose install path still exists, even if not discovered this run', function (): void {
    $dirs = makeUserScopeTempDirs();
    $pkg = $dirs['package'];
    $home = $dirs['home'];

    try {
        file_put_contents($pkg . '/composer.json', json_encode(['name' => 'acme/present'], JSON_THROW_ON_ERROR));
        mkdir($pkg . '/resources/boost/skills/alpha', 0o755, recursive: true);
        file_put_contents($pkg . '/resources/boost/skills/alpha/SKILL.md', "---\nname: alpha\ndescription: A.\n---\nAlpha.\n");

        (new SyncEngine([new ClaudeCodeTarget()], installedPackages: new InstalledPackages([])))->syncUser($pkg, homeRoot: $home);
        expect($home . '/.claude/skills/acme__present/alpha/SKILL.md')->toBeFile();

        // --all from a context that discovers NOTHING (e.g. a project-local
        // vendor/bin/boost) — but the package install dir still exists. It must
        // NOT be misclassified as removed.
        (new SyncEngine([new ClaudeCodeTarget()], installedPackages: new InstalledPackages([])))->syncUserAll(homeRoot: $home);

        expect($home . '/.claude/skills/acme__present/alpha/SKILL.md')->toBeFile('still-installed package not reaped')
            ->and($home . '/.boost/manifests/acme__present.json')->toBeFile('manifest kept');
    } finally {
        rmTreeUserScope($pkg);
        rmTreeUserScope($home);
    }
});

it('0.19.0 --check: reports a would-reap without deleting the file or rewriting the manifest', function (): void {
    $dirs = makeUserScopeTempDirs();
    $pkg = $dirs['package'];
    $home = $dirs['home'];

    try {
        file_put_contents($pkg . '/composer.json', json_encode(['name' => 'acme/multi'], JSON_THROW_ON_ERROR));
        mkdir($pkg . '/resources/boost/skills/alpha', 0o755, recursive: true);
        mkdir($pkg . '/resources/boost/skills/beta', 0o755, recursive: true);
        file_put_contents($pkg . '/resources/boost/skills/alpha/SKILL.md', "---\nname: alpha\ndescription: A.\n---\nAlpha.\n");
        file_put_contents($pkg . '/resources/boost/skills/beta/SKILL.md', "---\nname: beta\ndescription: B.\n---\nBeta.\n");

        $engine = new SyncEngine([new ClaudeCodeTarget()], installedPackages: new InstalledPackages([]));
        $engine->syncUser($pkg, homeRoot: $home);

        // Drop beta from source, then run --check.
        rmTreeUserScope($pkg . '/resources/boost/skills/beta');
        $result = $engine->syncUser($pkg, checkOnly: true, homeRoot: $home);

        $wouldReap = array_filter($result->writes, static fn (WrittenFile $w): bool => str_contains($w->relativePath, 'beta') && $w->action === WriteAction::WOULD_DELETE);
        expect($wouldReap)->not->toBeEmpty('check reports the pending reap')
            // A pending reap IS drift — `--check` must not print "No drift" when a
            // dropped skill's user-scope copy is still on disk (codex 0.19.0).
            ->and($result->hasDrift())->toBeTrue('a would-reap counts as drift');
        // …but nothing mutated: the file is still there and the manifest still lists it.
        expect($home . '/.claude/skills/acme__multi/beta/SKILL.md')->toBeFile('check did not delete');
        // The manifest still lists beta → check did not rewrite it.
        /** @var array{emitted: array<string, string>} $manifest */
        $manifest = json_decode((string) file_get_contents($home . '/.boost/manifests/acme__multi.json'), true, 512, JSON_THROW_ON_ERROR);
        expect($manifest['emitted'])->toHaveKey('.claude/skills/acme__multi/beta/SKILL.md');
    } finally {
        rmTreeUserScope($pkg);
        rmTreeUserScope($home);
    }
});

it('0.19.0 P1 retain-on-fail: a dropped skill whose unlink fails stays in the manifest so the next sync retries', function (): void {
    $dirs = makeUserScopeTempDirs();
    $pkg = $dirs['package'];
    $home = $dirs['home'];

    try {
        file_put_contents($pkg . '/composer.json', json_encode(['name' => 'acme/multi'], JSON_THROW_ON_ERROR));
        mkdir($pkg . '/resources/boost/skills/alpha', 0o755, recursive: true);
        mkdir($pkg . '/resources/boost/skills/beta', 0o755, recursive: true);
        file_put_contents($pkg . '/resources/boost/skills/alpha/SKILL.md', "---\nname: alpha\ndescription: A.\n---\nAlpha.\n");
        file_put_contents($pkg . '/resources/boost/skills/beta/SKILL.md', "---\nname: beta\ndescription: B.\n---\nBeta.\n");

        $engine = new SyncEngine([new ClaudeCodeTarget()], installedPackages: new InstalledPackages([]));
        $engine->syncUser($pkg, homeRoot: $home);

        $betaDir = $home . '/.claude/skills/acme__multi/beta';
        $betaFile = $betaDir . '/SKILL.md';
        expect($betaFile)->toBeFile();

        // Drop beta from source, but make its parent dir read-only so the reaper's
        // unlink fails — a retain-on-fail leftover.
        rmTreeUserScope($pkg . '/resources/boost/skills/beta');
        chmod($betaDir, 0o555);
        $engine->syncUser($pkg, homeRoot: $home);
        chmod($betaDir, 0o755);

        // The file could not be deleted...
        expect($betaFile)->toBeFile('reap retained — unlink failed');
        // ...so it MUST stay tracked for the next clean run to retry. Dropping it
        // here would orphan the file permanently (codex 0.19.0 P1).
        /** @var array{emitted: array<string, string>} $manifest */
        $manifest = json_decode((string) file_get_contents($home . '/.boost/manifests/acme__multi.json'), true, 512, JSON_THROW_ON_ERROR);
        expect($manifest['emitted'])->toHaveKey('.claude/skills/acme__multi/beta/SKILL.md');

        // Perms restored → the next sync reaps it, proving the retry path works.
        $engine->syncUser($pkg, homeRoot: $home);
        expect($betaFile)->not->toBeFile('retried reap succeeds once writable');
    } finally {
        @chmod($home . '/.claude/skills/acme__multi/beta', 0o755);
        rmTreeUserScope($pkg);
        rmTreeUserScope($home);
    }
})->skip(
    DIRECTORY_SEPARATOR !== '/' || (function_exists('posix_geteuid') && posix_geteuid() === 0),
    'POSIX-only + non-root — Windows fs permissions model differs; root bypasses permission checks.',
);

it('0.19.0 P2: a narrowed-target re-sync does NOT reap a still-shipped skill copy belonging to an inactive agent', function (): void {
    $dirs = makeUserScopeTempDirs();
    $pkg = $dirs['package'];
    $home = $dirs['home'];

    try {
        file_put_contents($pkg . '/composer.json', json_encode(['name' => 'acme/multi'], JSON_THROW_ON_ERROR));
        mkdir($pkg . '/resources/boost/skills/alpha', 0o755, recursive: true);
        file_put_contents($pkg . '/resources/boost/skills/alpha/SKILL.md', "---\nname: alpha\ndescription: A.\n---\nAlpha.\n");

        // First sync drives BOTH agents → .claude AND .cursor copies; the manifest
        // records both.
        (new SyncEngine([new ClaudeCodeTarget(), new CursorTarget()], installedPackages: new InstalledPackages([])))
            ->syncUser($pkg, homeRoot: $home);

        $cursorSlugDir = $home . '/.cursor/skills/acme__multi';
        $cursorCopies = glob($cursorSlugDir . '/*/SKILL.md');
        expect($cursorCopies)
            ->toBeArray()
            ->not->toBeEmpty('first sync wrote the Cursor copy');

        // Re-sync the SAME (still-shipped) package with a Claude-ONLY engine. The
        // skill is unchanged, so its .cursor copy must survive — it belongs to an
        // agent this engine does not drive (codex 0.19.0 P2).
        (new SyncEngine([new ClaudeCodeTarget()], installedPackages: new InstalledPackages([])))
            ->syncUser($pkg, homeRoot: $home);

        expect($home . '/.claude/skills/acme__multi/alpha/SKILL.md')->toBeFile('active-agent copy kept')
            ->and((array) $cursorCopies)->each->toBeFile('inactive-agent copy must NOT be reaped');
    } finally {
        rmTreeUserScope($pkg);
        rmTreeUserScope($home);
    }
});

it('0.19.0 P2: a narrowed-target re-sync DOES reap a DROPPED skill copy belonging to an inactive agent', function (): void {
    $dirs = makeUserScopeTempDirs();
    $pkg = $dirs['package'];
    $home = $dirs['home'];

    try {
        file_put_contents($pkg . '/composer.json', json_encode(['name' => 'acme/multi'], JSON_THROW_ON_ERROR));
        mkdir($pkg . '/resources/boost/skills/alpha', 0o755, recursive: true);
        mkdir($pkg . '/resources/boost/skills/beta', 0o755, recursive: true);
        file_put_contents($pkg . '/resources/boost/skills/alpha/SKILL.md', "---\nname: alpha\ndescription: A.\n---\nAlpha.\n");
        file_put_contents($pkg . '/resources/boost/skills/beta/SKILL.md', "---\nname: beta\ndescription: B.\n---\nBeta.\n");

        // First sync drives BOTH agents → alpha + beta copies under .claude AND .cursor.
        (new SyncEngine([new ClaudeCodeTarget(), new CursorTarget()], installedPackages: new InstalledPackages([])))
            ->syncUser($pkg, homeRoot: $home);
        expect($home . '/.cursor/skills/acme__multi/beta/SKILL.md')->toBeFile()
            ->and($home . '/.claude/skills/acme__multi/beta/SKILL.md')->toBeFile();

        // Drop beta from source, re-sync with a Claude-ONLY engine. beta is gone
        // from the package, so its stale copy must be reaped under EVERY agent —
        // including the inactive .cursor — while alpha (still shipped) survives on
        // both (codex 0.19.0 P2).
        rmTreeUserScope($pkg . '/resources/boost/skills/beta');
        (new SyncEngine([new ClaudeCodeTarget()], installedPackages: new InstalledPackages([])))
            ->syncUser($pkg, homeRoot: $home);

        expect($home . '/.claude/skills/acme__multi/beta/SKILL.md')->not->toBeFile('dropped skill reaped (active agent)')
            ->and($home . '/.cursor/skills/acme__multi/beta/SKILL.md')->not->toBeFile('dropped skill reaped (inactive agent)')
            ->and($home . '/.claude/skills/acme__multi/alpha/SKILL.md')->toBeFile('shipped skill kept (active agent)')
            ->and($home . '/.cursor/skills/acme__multi/alpha/SKILL.md')->toBeFile('shipped skill kept (inactive agent)');
    } finally {
        rmTreeUserScope($pkg);
        rmTreeUserScope($home);
    }
});

it('0.19.0 P2: --all reaps a still-installed package that dropped ALL its skills', function (): void {
    $dirs = makeUserScopeTempDirs();
    $pkg = $dirs['package'];
    $home = $dirs['home'];

    try {
        file_put_contents($pkg . '/composer.json', json_encode(['name' => 'acme/shrank'], JSON_THROW_ON_ERROR));
        mkdir($pkg . '/resources/boost/skills/alpha', 0o755, recursive: true);
        file_put_contents($pkg . '/resources/boost/skills/alpha/SKILL.md', "---\nname: alpha\ndescription: A.\n---\nAlpha.\n");

        // Install: user-scope sync the package → files + manifest.
        (new SyncEngine([new ClaudeCodeTarget()], installedPackages: new InstalledPackages([])))->syncUser($pkg, homeRoot: $home);
        expect($home . '/.claude/skills/acme__shrank/alpha/SKILL.md')->toBeFile()
            ->and($home . '/.boost/manifests/acme__shrank.json')->toBeFile();

        // Package updated to ship NO skills: its `resources/boost/skills/` dir is
        // gone, but the install dir (composer.json) is still on disk. `--all` never
        // re-syncs it (no skills to discover), so reconcile must catch the orphan.
        rmTreeUserScope($pkg . '/resources/boost/skills');

        (new SyncEngine([new ClaudeCodeTarget()], installedPackages: new InstalledPackages([])))->syncUserAll(homeRoot: $home);

        expect($home . '/.claude/skills/acme__shrank/alpha/SKILL.md')->not->toBeFile('orphan of a skill-less package reaped')
            ->and($home . '/.claude/skills/acme__shrank')->not->toBeDirectory('empty slug dir pruned')
            ->and($home . '/.boost/manifests/acme__shrank.json')->not->toBeFile('stale manifest deleted');
    } finally {
        rmTreeUserScope($pkg);
        rmTreeUserScope($home);
    }
});

it('0.19.0 P2: a manifest write failure surfaces as a sync error, not silent success', function (): void {
    $dirs = makeUserScopeTempDirs();
    $pkg = $dirs['package'];
    $home = $dirs['home'];

    try {
        file_put_contents($pkg . '/composer.json', json_encode(['name' => 'acme/multi'], JSON_THROW_ON_ERROR));
        mkdir($pkg . '/resources/boost/skills/alpha', 0o755, recursive: true);
        file_put_contents($pkg . '/resources/boost/skills/alpha/SKILL.md', "---\nname: alpha\ndescription: A.\n---\nAlpha.\n");

        // `.boost/` exists but is read-only → the `manifests/` subdir can't be
        // created, so the manifest can't be persisted. The cleanup feature
        // depends on it, so this must NOT report success (codex 0.19.0 P2).
        mkdir($home . '/.boost', 0o555, recursive: true);

        $result = (new SyncEngine([new ClaudeCodeTarget()], installedPackages: new InstalledPackages([])))->syncUser($pkg, homeRoot: $home);

        chmod($home . '/.boost', 0o755);

        expect($result->errors)->not->toBeEmpty('manifest write failure must be reported')
            ->and(implode("\n", $result->errors))->toContain('user-scope manifest')
            ->and($home . '/.boost/manifests/acme__multi.json')->not->toBeFile('nothing persisted');
    } finally {
        @chmod($home . '/.boost', 0o755);
        rmTreeUserScope($pkg);
        rmTreeUserScope($home);
    }
})->skip(
    DIRECTORY_SEPARATOR !== '/' || (function_exists('posix_geteuid') && posix_geteuid() === 0),
    'POSIX-only + non-root — Windows fs permissions model differs; root bypasses permission checks.',
);

it('0.19.0 P1: a symlinked user-scope target is never claimed in the manifest nor reaped', function (): void {
    $dirs = makeUserScopeTempDirs();
    $pkg = $dirs['package'];
    $home = $dirs['home'];

    try {
        file_put_contents($pkg . '/composer.json', json_encode(['name' => 'acme/multi'], JSON_THROW_ON_ERROR));
        mkdir($pkg . '/resources/boost/skills/alpha', 0o755, recursive: true);
        file_put_contents($pkg . '/resources/boost/skills/alpha/SKILL.md', "---\nname: alpha\ndescription: A.\n---\nAlpha.\n");

        // Operator points the user-scope target at their OWN file via a symlink.
        file_put_contents($home . '/operator-owned.md', "operator's own content\n");
        mkdir($home . '/.claude/skills/acme__multi/alpha', 0o755, recursive: true);
        $link = $home . '/.claude/skills/acme__multi/alpha/SKILL.md';
        symlink($home . '/operator-owned.md', $link);

        $engine = new SyncEngine([new ClaudeCodeTarget()], installedPackages: new InstalledPackages([]));
        $engine->syncUser($pkg, homeRoot: $home);

        // The symlinked path is SKIPPED_SYMLINK, so boost must not record it.
        /** @var array{emitted: array<string, string>} $manifest */
        $manifest = json_decode((string) file_get_contents($home . '/.boost/manifests/acme__multi.json'), true, 512, JSON_THROW_ON_ERROR);
        expect($manifest['emitted'])->not->toHaveKey('.claude/skills/acme__multi/alpha/SKILL.md', 'symlinked target not owned');

        // Drop the skill and re-sync: an unowned symlink must NOT be reaped.
        rmTreeUserScope($pkg . '/resources/boost/skills/alpha');
        $engine->syncUser($pkg, homeRoot: $home);

        expect(is_link($link))->toBeTrue('operator symlink survives the reap')
            ->and((string) file_get_contents($home . '/operator-owned.md'))->toBe("operator's own content\n");
    } finally {
        rmTreeUserScope($pkg);
        rmTreeUserScope($home);
    }
})->skip(
    DIRECTORY_SEPARATOR !== '/',
    'POSIX-only — Windows symlink semantics + admin-perm requirements differ.',
);

it('0.19.0 P2: --all reaps the old slug of a package renamed/replaced in place', function (): void {
    $dirs = makeUserScopeTempDirs();
    $pkg = $dirs['package'];
    $home = $dirs['home'];

    try {
        // Install acme/old at $pkg and user-scope sync it.
        file_put_contents($pkg . '/composer.json', json_encode(['name' => 'acme/old'], JSON_THROW_ON_ERROR));
        mkdir($pkg . '/resources/boost/skills/alpha', 0o755, recursive: true);
        file_put_contents($pkg . '/resources/boost/skills/alpha/SKILL.md', "---\nname: alpha\ndescription: A.\n---\nAlpha.\n");

        (new SyncEngine([new ClaudeCodeTarget()], installedPackages: new InstalledPackages([])))->syncUser($pkg, homeRoot: $home);
        expect($home . '/.claude/skills/acme__old/alpha/SKILL.md')->toBeFile()
            ->and($home . '/.boost/manifests/acme__old.json')->toBeFile();

        // Rename/replace IN PLACE: the same path now hosts acme/new.
        file_put_contents($pkg . '/composer.json', json_encode(['name' => 'acme/new'], JSON_THROW_ON_ERROR));

        // --all discovers acme/new at $pkg (syncs its slug); reconcile must reap
        // the orphaned acme__old slug rather than skip it because *some* skill-
        // bearing package still sits at the recorded install path.
        (new SyncEngine([new ClaudeCodeTarget()], installedPackages: new InstalledPackages([
            'acme/new' => new PackageInfo('acme/new', '1.0.0', $pkg),
        ])))->syncUserAll(homeRoot: $home);

        expect($home . '/.claude/skills/acme__old/alpha/SKILL.md')->not->toBeFile('renamed-away old slug reaped')
            ->and($home . '/.claude/skills/acme__old')->not->toBeDirectory('old slug dir pruned')
            ->and($home . '/.boost/manifests/acme__old.json')->not->toBeFile('old slug manifest deleted')
            ->and($home . '/.claude/skills/acme__new/alpha/SKILL.md')->toBeFile('new slug synced')
            ->and($home . '/.boost/manifests/acme__new.json')->toBeFile('new slug manifest written');
    } finally {
        rmTreeUserScope($pkg);
        rmTreeUserScope($home);
    }
});
