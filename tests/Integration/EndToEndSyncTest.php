<?php

declare(strict_types=1);

use SanderMuller\BoostCore\Sync\InstalledPackages;
use SanderMuller\BoostCore\Sync\PackageInfo;
use SanderMuller\BoostCore\Sync\SyncEngine;
use SanderMuller\BoostCore\Sync\WriteAction;

function makeEndToEndProject(): string
{
    $root = sys_get_temp_dir().'/boost-e2e-'.bin2hex(random_bytes(8));
    mkdir($root.'/.ai/skills', 0o755, recursive: true);
    mkdir($root.'/.ai/guidelines', 0o755, recursive: true);

    return $root;
}

/**
 * @return list<string>
 */
function rmTreeE2E(string $path): array
{
    $deleted = [];
    if (! is_dir($path)) {
        return $deleted;
    }

    $entries = scandir($path);
    if ($entries === false) {
        return $deleted;
    }

    foreach ($entries as $entry) {
        if ($entry === '.' || $entry === '..') {
            continue;
        }
        $full = $path.'/'.$entry;
        if (is_dir($full) && ! is_link($full)) {
            $deleted = [...$deleted, ...rmTreeE2E($full)];
        } else {
            unlink($full);
            $deleted[] = $full;
        }
    }
    rmdir($path);

    return $deleted;
}

function writeBoostPhp(string $root, string $body): void
{
    file_put_contents($root.'/boost.php', "<?php\n\ndeclare(strict_types=1);\n\nuse SanderMuller\\BoostCore\\Config\\BoostConfig;\nuse SanderMuller\\BoostCore\\Enums\\Agent;\n\n$body\n");
}

function emptyInstalledPackages(): InstalledPackages
{
    return new InstalledPackages([]);
}

it('end-to-end: host skill + guideline → Claude Code files committed to disk', function (): void {
    $root = makeEndToEndProject();
    try {
        writeBoostPhp($root, "return BoostConfig::configure()\n    ->withAgents([Agent::CLAUDE_CODE]);");

        file_put_contents($root.'/.ai/skills/foo.md', "---\nname: foo\ndescription: A foo skill.\n---\n# Foo body\n");
        file_put_contents($root.'/.ai/guidelines/conventions.md', "---\nname: conventions\n---\n# Conventions\n\nUse strict types.");

        $result = SyncEngine::default(emptyInstalledPackages())->sync($root);

        expect($result->hasErrors())->toBeFalse();
        expect($result->countByAction(WriteAction::WROTE))->toBe(2); // skill + guidelines

        expect(file_exists($root.'/.claude/skills/foo.md'))->toBeTrue();
        expect(file_exists($root.'/CLAUDE.md'))->toBeTrue();

        $skillContent = file_get_contents($root.'/.claude/skills/foo.md');
        expect($skillContent)->toContain('name: foo');
        expect($skillContent)->toContain('# Foo body');

        $claudeMd = file_get_contents($root.'/CLAUDE.md');
        expect($claudeMd)->toContain('# Conventions');
        expect($claudeMd)->toContain('strict types');
    } finally {
        rmTreeE2E($root);
    }
});

it('check mode reports drift without writing', function (): void {
    $root = makeEndToEndProject();
    try {
        writeBoostPhp($root, "return BoostConfig::configure()\n    ->withAgents([Agent::CLAUDE_CODE]);");
        file_put_contents($root.'/.ai/skills/foo.md', "---\nname: foo\n---\nBody.");

        $result = SyncEngine::default(emptyInstalledPackages())->sync($root, checkOnly: true);

        expect($result->hasDrift())->toBeTrue();
        expect($result->countByAction(WriteAction::WOULD_WRITE))->toBeGreaterThan(0);
        expect(file_exists($root.'/.claude/skills/foo.md'))->toBeFalse();
    } finally {
        rmTreeE2E($root);
    }
});

it('check mode reports no drift after a successful write', function (): void {
    $root = makeEndToEndProject();
    try {
        writeBoostPhp($root, "return BoostConfig::configure()\n    ->withAgents([Agent::CLAUDE_CODE]);");
        file_put_contents($root.'/.ai/skills/foo.md', "---\nname: foo\n---\nBody.");

        $engine = SyncEngine::default(emptyInstalledPackages());
        $engine->sync($root);
        $second = $engine->sync($root, checkOnly: true);

        expect($second->hasDrift())->toBeFalse();
    } finally {
        rmTreeE2E($root);
    }
});

it('skips agent fan-out when no agents are configured', function (): void {
    $root = makeEndToEndProject();
    try {
        writeBoostPhp($root, 'return BoostConfig::configure();');
        file_put_contents($root.'/.ai/skills/foo.md', "---\nname: foo\n---\nBody.");

        $result = SyncEngine::default(emptyInstalledPackages())->sync($root);

        expect($result->writes)->toBe([]);
        expect($result->hasErrors())->toBeFalse();
    } finally {
        rmTreeE2E($root);
    }
});

it('discovers allowlisted vendor skills alongside host skills', function (): void {
    $root = makeEndToEndProject();
    $vendorPath = $root.'/vendor/test-vendor/with-skills';
    mkdir($vendorPath.'/resources/boost/skills', 0o755, recursive: true);
    try {
        file_put_contents(
            $vendorPath.'/composer.json',
            '{"name":"test-vendor/with-skills","type":"library"}',
        );
        file_put_contents(
            $vendorPath.'/resources/boost/skills/vendor-skill.md',
            "---\nname: vendor-skill\n---\nVendor body.",
        );
        file_put_contents($root.'/.ai/skills/host-skill.md', "---\nname: host-skill\n---\nHost body.");

        writeBoostPhp(
            $root,
            "return BoostConfig::configure()\n"
            .'    ->withAgents([Agent::CLAUDE_CODE])'."\n"
            .'    ->withAllowedVendors(["test-vendor/with-skills"]);',
        );

        $fakePackages = new InstalledPackages([
            'test-vendor/with-skills' => new PackageInfo(
                'test-vendor/with-skills',
                '1.0.0',
                $vendorPath,
            ),
        ]);

        $result = SyncEngine::default($fakePackages)->sync($root);

        expect($result->hasErrors())->toBeFalse();
        expect(file_exists($root.'/.claude/skills/host-skill.md'))->toBeTrue();
        expect(file_exists($root.'/.claude/skills/vendor-skill.md'))->toBeTrue();
    } finally {
        rmTreeE2E($root);
    }
});

it('respects the allowlist: non-allowlisted vendor skills are skipped', function (): void {
    $root = makeEndToEndProject();
    $vendorPath = $root.'/vendor/forbidden/vendor';
    mkdir($vendorPath.'/resources/boost/skills', 0o755, recursive: true);
    try {
        file_put_contents($vendorPath.'/composer.json', '{"name":"forbidden/vendor","type":"library"}');
        file_put_contents(
            $vendorPath.'/resources/boost/skills/should-not-appear.md',
            "---\nname: should-not-appear\n---\nForbidden body.",
        );

        writeBoostPhp($root, "return BoostConfig::configure()\n    ->withAgents([Agent::CLAUDE_CODE]);");

        $fakePackages = new InstalledPackages([
            'forbidden/vendor' => new PackageInfo('forbidden/vendor', '1.0.0', $vendorPath),
        ]);

        $result = SyncEngine::default($fakePackages)->sync($root);

        expect(file_exists($root.'/.claude/skills/should-not-appear.md'))->toBeFalse();
    } finally {
        rmTreeE2E($root);
    }
});
