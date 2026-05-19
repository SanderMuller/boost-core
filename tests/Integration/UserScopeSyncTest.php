<?php

declare(strict_types=1);

use SanderMuller\BoostCore\Agents\ClaudeCodeTarget;
use SanderMuller\BoostCore\Agents\CursorTarget;
use SanderMuller\BoostCore\Sync\InstalledPackages;
use SanderMuller\BoostCore\Sync\SyncEngine;
use SanderMuller\BoostCore\Sync\WriteAction;

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
