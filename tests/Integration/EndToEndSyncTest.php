<?php

declare(strict_types=1);

use SanderMuller\BoostCore\Sync\InstalledPackages;
use SanderMuller\BoostCore\Sync\PackageInfo;
use SanderMuller\BoostCore\Sync\SyncEngine;
use SanderMuller\BoostCore\Sync\WriteAction;

function makeEndToEndProject(): string
{
    $root = sys_get_temp_dir() . '/boost-e2e-' . bin2hex(random_bytes(8));
    mkdir($root . '/.ai/skills', 0o755, recursive: true);
    mkdir($root . '/.ai/guidelines', 0o755, recursive: true);

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
        if ($entry === '.') {
            continue;
        }
        if ($entry === '..') {
            continue;
        }

        $full = $path . '/' . $entry;
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
    file_put_contents($root . '/boost.php', "<?php\n\ndeclare(strict_types=1);\n\nuse SanderMuller\\BoostCore\\Config\\BoostConfig;\nuse SanderMuller\\BoostCore\\Enums\\Agent;\n\n{$body}\n");
}

function emptyInstalledPackages(): InstalledPackages
{
    return new InstalledPackages([]);
}

it('end-to-end: host skill + guideline → Claude Code files committed to disk', function (): void {
    $root = makeEndToEndProject();
    try {
        writeBoostPhp($root, "return BoostConfig::configure()\n    ->withAgents([Agent::CLAUDE_CODE]);");

        file_put_contents($root . '/.ai/skills/foo.md', "---\nname: foo\ndescription: A foo skill.\n---\n# Foo body\n");
        file_put_contents($root . '/.ai/guidelines/conventions.md', "---\nname: conventions\n---\n# Conventions\n\nUse strict types.");

        $result = SyncEngine::default(emptyInstalledPackages())->sync($root);

        expect($result->hasErrors())->toBeFalse()
            ->and($result->countByAction(WriteAction::WROTE))
            ->toBe(3); // skill + guidelines + managed .gitignore

        expect(file_exists($root . '/.claude/skills/foo/SKILL.md'))->toBeTrue();
        expect(file_exists($root . '/CLAUDE.md'))->toBeTrue()
            ->and(file_get_contents($root . '/.gitignore'))
            ->toContain('.claude/skills/');

        $skillContent = file_get_contents($root . '/.claude/skills/foo/SKILL.md');
        expect($skillContent)->toContain('name: foo')
            ->toContain('# Foo body');

        $claudeMd = file_get_contents($root . '/CLAUDE.md');
        expect($claudeMd)->toContain('# Conventions')
            ->toContain('strict types');
    } finally {
        rmTreeE2E($root);
    }
});

it('check mode reports drift without writing', function (): void {
    $root = makeEndToEndProject();
    try {
        writeBoostPhp($root, "return BoostConfig::configure()\n    ->withAgents([Agent::CLAUDE_CODE]);");
        file_put_contents($root . '/.ai/skills/foo.md', "---\nname: foo\n---\nBody.");

        $result = SyncEngine::default(emptyInstalledPackages())->sync($root, checkOnly: true);

        expect($result->hasDrift())->toBeTrue()
            ->and($result->countByAction(WriteAction::WOULD_WRITE))
            ->toBeGreaterThan(0)
            ->and(file_exists($root . '/.claude/skills/foo/SKILL.md'))
            ->toBeFalse();
    } finally {
        rmTreeE2E($root);
    }
});

it('check mode reports no drift after a successful write', function (): void {
    $root = makeEndToEndProject();
    try {
        writeBoostPhp($root, "return BoostConfig::configure()\n    ->withAgents([Agent::CLAUDE_CODE]);");
        file_put_contents($root . '/.ai/skills/foo.md', "---\nname: foo\n---\nBody.");

        $engine = SyncEngine::default(emptyInstalledPackages());
        $engine->sync($root);
        $second = $engine->sync($root, checkOnly: true);

        expect($second->hasDrift())->toBeFalse();
    } finally {
        rmTreeE2E($root);
    }
});

it('prunes a legacy flat `<name>.md` sibling when emitting `<name>/SKILL.md`', function (): void {
    $root = makeEndToEndProject();
    try {
        writeBoostPhp($root, "return BoostConfig::configure()\n    ->withAgents([Agent::CLAUDE_CODE]);");
        file_put_contents($root . '/.ai/skills/foo.md', "---\nname: foo\n---\nNew body.\n");

        // Simulate the pre-0.2 layout: stale flat skill committed to disk.
        mkdir($root . '/.claude/skills', 0o755, recursive: true);
        file_put_contents($root . '/.claude/skills/foo.md', "stale content from older sync\n");

        SyncEngine::default(emptyInstalledPackages())->sync($root);

        expect(file_exists($root . '/.claude/skills/foo/SKILL.md'))->toBeTrue()
            ->and(file_exists($root . '/.claude/skills/foo.md'))
            ->toBeFalse();
    } finally {
        rmTreeE2E($root);
    }
});

it('does NOT prune the legacy flat sibling if the new write fails', function (): void {
    $root = makeEndToEndProject();
    try {
        writeBoostPhp($root, "return BoostConfig::configure()\n    ->withAgents([Agent::CLAUDE_CODE]);");
        file_put_contents($root . '/.ai/skills/foo.md', "---\nname: foo\n---\nNew body.\n");

        // Pre-existing flat skill we'd be migrating from.
        mkdir($root . '/.claude/skills', 0o755, recursive: true);
        file_put_contents($root . '/.claude/skills/foo.md', "last good copy\n");

        // Make the new target unwritable: replace foo/ with a regular file so
        // FileWriter can't mkdir/write inside it.
        file_put_contents($root . '/.claude/skills/foo', "blocker\n");

        $result = SyncEngine::default(emptyInstalledPackages())->sync($root);

        // The blocker MUST have actually caused a write failure — otherwise
        // a future refactor that silently no-ops on the blocker would still
        // pass the legacy-preservation assertion below.
        expect($result->hasErrors())->toBeTrue();
        // Legacy copy is the only good copy left; pruning it would mean data loss.
        expect(file_exists($root . '/.claude/skills/foo.md'))->toBeTrue();
        expect(file_get_contents($root . '/.claude/skills/foo.md'))->toContain('last good copy');
    } finally {
        rmTreeE2E($root);
    }
});

it('directory-form source skill is emitted as <name>/SKILL.md, not flattened', function (): void {
    $root = makeEndToEndProject();
    try {
        writeBoostPhp($root, "return BoostConfig::configure()\n    ->withAgents([Agent::CLAUDE_CODE]);");

        mkdir($root . '/.ai/skills/ai-guidelines', 0o755, recursive: true);
        file_put_contents(
            $root . '/.ai/skills/ai-guidelines/SKILL.md',
            "---\nname: ai-guidelines\ndescription: Guidelines skill.\n---\n# Body\n",
        );

        $result = SyncEngine::default(emptyInstalledPackages())->sync($root);

        expect($result->hasErrors())->toBeFalse()
            ->and(file_exists($root . '/.claude/skills/ai-guidelines/SKILL.md'))
            ->toBeTrue()
            ->and(file_exists($root . '/.claude/skills/ai-guidelines.md'))
            ->toBeFalse()
            ->and(file_get_contents($root . '/.claude/skills/ai-guidelines/SKILL.md'))
            ->toContain('name: ai-guidelines')
            ->toContain('# Body');
    } finally {
        rmTreeE2E($root);
    }
});

it('BOOST_SKIP_GITIGNORE bypasses gitignore management even when boost.php enables it', function (): void {
    $root = makeEndToEndProject();
    putenv('BOOST_SKIP_GITIGNORE=1');
    try {
        writeBoostPhp($root, "return BoostConfig::configure()\n    ->withAgents([Agent::CLAUDE_CODE]);");
        file_put_contents($root . '/.ai/skills/foo.md', "---\nname: foo\n---\nBody.");

        $result = SyncEngine::default(emptyInstalledPackages())->sync($root);

        expect($result->hasErrors())->toBeFalse()
            ->and(file_exists($root . '/.claude/skills/foo/SKILL.md'))
            ->toBeTrue()
            ->and(file_exists($root . '/.gitignore'))
            ->toBeFalse();
    } finally {
        putenv('BOOST_SKIP_GITIGNORE');
        rmTreeE2E($root);
    }
});

it('skips agent fan-out when no agents are configured', function (): void {
    $root = makeEndToEndProject();
    try {
        writeBoostPhp($root, 'return BoostConfig::configure();');
        file_put_contents($root . '/.ai/skills/foo.md', "---\nname: foo\n---\nBody.");

        $result = SyncEngine::default(emptyInstalledPackages())->sync($root);

        expect($result->writes)
            ->toBeEmpty()
            ->and($result->hasErrors())
            ->toBeFalse();
    } finally {
        rmTreeE2E($root);
    }
});

it('discovers allowlisted vendor skills alongside host skills', function (): void {
    $root = makeEndToEndProject();
    $vendorPath = $root . '/vendor/test-vendor/with-skills';
    mkdir($vendorPath . '/resources/boost/skills', 0o755, recursive: true);
    try {
        file_put_contents(
            $vendorPath . '/composer.json',
            '{"name":"test-vendor/with-skills","type":"library"}',
        );
        file_put_contents(
            $vendorPath . '/resources/boost/skills/vendor-skill.md',
            "---\nname: vendor-skill\n---\nVendor body.",
        );
        file_put_contents($root . '/.ai/skills/host-skill.md', "---\nname: host-skill\n---\nHost body.");

        writeBoostPhp(
            $root,
            "return BoostConfig::configure()\n"
            . '    ->withAgents([Agent::CLAUDE_CODE])' . "\n"
            . '    ->withAllowedVendors(["test-vendor/with-skills"]);',
        );

        $fakePackages = new InstalledPackages([
            'test-vendor/with-skills' => new PackageInfo(
                'test-vendor/with-skills',
                '1.0.0',
                $vendorPath,
            ),
        ]);

        $result = SyncEngine::default($fakePackages)->sync($root);

        expect($result->hasErrors())->toBeFalse()
            ->and(file_exists($root . '/.claude/skills/host-skill/SKILL.md'))
            ->toBeTrue()
            ->and(file_exists($root . '/.claude/skills/vendor-skill/SKILL.md'))
            ->toBeTrue();
    } finally {
        rmTreeE2E($root);
    }
});

it('respects the allowlist: non-allowlisted vendor skills are skipped', function (): void {
    $root = makeEndToEndProject();
    $vendorPath = $root . '/vendor/forbidden/vendor';
    mkdir($vendorPath . '/resources/boost/skills', 0o755, recursive: true);
    try {
        file_put_contents($vendorPath . '/composer.json', '{"name":"forbidden/vendor","type":"library"}');
        file_put_contents(
            $vendorPath . '/resources/boost/skills/should-not-appear.md',
            "---\nname: should-not-appear\n---\nForbidden body.",
        );

        writeBoostPhp($root, "return BoostConfig::configure()\n    ->withAgents([Agent::CLAUDE_CODE]);");

        $fakePackages = new InstalledPackages([
            'forbidden/vendor' => new PackageInfo('forbidden/vendor', '1.0.0', $vendorPath),
        ]);

        $result = SyncEngine::default($fakePackages)->sync($root);

        expect(file_exists($root . '/.claude/skills/should-not-appear/SKILL.md'))->toBeFalse();
    } finally {
        rmTreeE2E($root);
    }
});
