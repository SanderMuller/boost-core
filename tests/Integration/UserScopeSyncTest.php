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
        if ($entry === '.' || $entry === '..') {
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

        expect($result->hasErrors())->toBeFalse();
        expect($result->packageName)->toBe('test-vendor/sample-tool');
        expect($result->homeRoot)->toBe($home);
        expect(file_exists($home . '/.claude/skills/sample-tool/sample-skill.md'))->toBeTrue();
        expect(file_exists($home . '/.cursor/skills/sample-tool/sample-skill.md'))->toBeTrue();

        $written = (string) file_get_contents($home . '/.claude/skills/sample-tool/sample-skill.md');
        expect($written)->toContain('Body.');
        expect($written)->toContain('name: sample-skill');
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

        expect(file_exists($home . '/.claude/skills/pkg/x.md'))->toBeTrue();
        expect(file_exists($home . '/CLAUDE.md'))->toBeFalse();
        expect(file_exists($home . '/AGENTS.md'))->toBeFalse();
        expect(file_exists($home . '/GEMINI.md'))->toBeFalse();
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

        expect($result->hasDrift())->toBeTrue();
        expect($result->countByAction(WriteAction::WOULD_WRITE))->toBe(1);
        expect(file_exists($home . '/.claude/skills/pkg/x.md'))->toBeFalse();
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

        expect($result->hasErrors())->toBeTrue();
        expect($result->errors[0])->toContain('composer.json not found');
    } finally {
        rmTreeUserScope($pkg);
        rmTreeUserScope($home);
    }
});
