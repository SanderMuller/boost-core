<?php declare(strict_types=1);

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

it('prunes dead symlinks in managed agent skills dirs (vendor-rename migration)', function (): void {
    $root = makeEndToEndProject();
    try {
        writeBoostPhp($root, "return BoostConfig::configure()\n    ->withAgents([Agent::CLAUDE_CODE]);");
        file_put_contents($root . '/.ai/skills/foo.md', "---\nname: foo\n---\nbody\n");

        // Simulate the pre-migration shape: package-boost's testbench sync
        // (or an earlier boost-core run) left symlinks under
        // .claude/skills/oldvendor/ pointing into a vendor dir that's now
        // gone. find -L -type l would catch these as dead links.
        mkdir($root . '/.claude/skills/oldvendor', 0o755, recursive: true);
        $nonexistentTarget = sys_get_temp_dir() . '/boost-e2e-nonexistent-' . bin2hex(random_bytes(6));
        $deadLink1 = $root . '/.claude/skills/oldvendor/skill-one';
        $deadLink2 = $root . '/.claude/skills/oldvendor/skill-two';
        symlink($nonexistentTarget, $deadLink1);
        symlink($nonexistentTarget, $deadLink2);

        // Sanity: PHP confirms they're dead links right now.
        expect(is_link($deadLink1))->toBeTrue()
            ->and(file_exists($deadLink1))->toBeFalse();

        $result = SyncEngine::default(emptyInstalledPackages())->sync($root);

        expect($result->hasErrors())->toBeFalse()
            ->and(is_link($deadLink1))->toBeFalse('dead symlink #1 should be pruned')
            ->and(is_link($deadLink2))->toBeFalse('dead symlink #2 should be pruned')
            ->and(file_exists($root . '/.claude/skills/foo/SKILL.md'))
            ->toBeTrue('new skill should still emit cleanly alongside the prune');
    } finally {
        rmTreeE2E($root);
    }
});

it('leaves live symlinks alone (does not chase or unlink valid links)', function (): void {
    $root = makeEndToEndProject();
    try {
        writeBoostPhp($root, "return BoostConfig::configure()\n    ->withAgents([Agent::CLAUDE_CODE]);");
        file_put_contents($root . '/.ai/skills/foo.md', "---\nname: foo\n---\nbody\n");

        // Plant a LIVE symlink in the managed dir. It points at a real
        // file outside the project — sync must not unlink it.
        mkdir($root . '/.claude/skills/livevendor', 0o755, recursive: true);
        $liveTarget = sys_get_temp_dir() . '/boost-live-target-' . bin2hex(random_bytes(6));
        file_put_contents($liveTarget, "real content\n");
        $liveLink = $root . '/.claude/skills/livevendor/skill';
        symlink($liveTarget, $liveLink);

        try {
            SyncEngine::default(emptyInstalledPackages())->sync($root);

            expect(is_link($liveLink))->toBeTrue('live symlink must survive the prune')
                ->and(file_exists($liveLink))->toBeTrue();
        } finally {
            @unlink($liveTarget);
        }
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

/**
 * Create a one-skill vendor package under $root/vendor and return its PackageInfo.
 * `$extraFrontmatter` is appended after the `name:` line (e.g. a metadata block).
 */
function makeTagVendor(string $root, string $vendor, string $skillName, string $extraFrontmatter = ''): PackageInfo
{
    $path = $root . '/vendor/' . $vendor;
    mkdir($path . '/resources/boost/skills', 0o755, recursive: true);
    file_put_contents($path . '/composer.json', '{"name":"' . $vendor . '","type":"library"}');
    file_put_contents(
        $path . '/resources/boost/skills/' . $skillName . '.md',
        "---\nname: {$skillName}\n{$extraFrontmatter}---\nBody.\n",
    );

    return new PackageInfo($vendor, '1.0.0', $path);
}

it('tag-filters out a vendor skill whose tag the consumer has not declared', function (): void {
    $root = makeEndToEndProject();
    try {
        $pkg = makeTagVendor($root, 'acme/jira-pack', 'jira-skill', "metadata:\n  boost-tags: jira\n");
        writeBoostPhp(
            $root,
            "return BoostConfig::configure()\n"
            . '    ->withAgents([Agent::CLAUDE_CODE])' . "\n"
            . '    ->withAllowedVendors(["acme/jira-pack"]);',
        );

        $result = SyncEngine::default(new InstalledPackages(['acme/jira-pack' => $pkg]))->sync($root);

        expect($result->hasErrors())->toBeFalse()
            ->and(file_exists($root . '/.claude/skills/jira-skill/SKILL.md'))->toBeFalse();
    } finally {
        rmTreeE2E($root);
    }
});

it('ships a tagged vendor skill once the consumer declares the tag', function (): void {
    $root = makeEndToEndProject();
    try {
        $pkg = makeTagVendor($root, 'acme/jira-pack', 'jira-skill', "metadata:\n  boost-tags: jira\n");
        writeBoostPhp(
            $root,
            "return BoostConfig::configure()\n"
            . '    ->withAgents([Agent::CLAUDE_CODE])' . "\n"
            . '    ->withAllowedVendors(["acme/jira-pack"])' . "\n"
            . '    ->withTags("jira");',
        );

        $result = SyncEngine::default(new InstalledPackages(['acme/jira-pack' => $pkg]))->sync($root);

        expect($result->hasErrors())->toBeFalse()
            ->and(file_exists($root . '/.claude/skills/jira-skill/SKILL.md'))->toBeTrue();
    } finally {
        rmTreeE2E($root);
    }
});

it('fails closed: a vendor skill with malformed metadata.boost-tags ships nowhere', function (): void {
    $root = makeEndToEndProject();
    try {
        // boost-tags as a YAML list where a string is required → tag-invalid.
        $pkg = makeTagVendor($root, 'acme/bad-pack', 'bad-skill', "metadata:\n  boost-tags:\n    - php\n");
        writeBoostPhp(
            $root,
            "return BoostConfig::configure()\n"
            . '    ->withAgents([Agent::CLAUDE_CODE])' . "\n"
            . '    ->withAllowedVendors(["acme/bad-pack"])' . "\n"
            . '    ->withTags("php");',
        );

        $result = SyncEngine::default(new InstalledPackages(['acme/bad-pack' => $pkg]))->sync($root);

        expect($result->hasErrors())->toBeFalse()
            ->and(file_exists($root . '/.claude/skills/bad-skill/SKILL.md'))->toBeFalse();
    } finally {
        rmTreeE2E($root);
    }
});

it('drops a vendor skill named in withExcludedSkills', function (): void {
    $root = makeEndToEndProject();
    try {
        $pkg = makeTagVendor($root, 'acme/pack', 'unwanted');
        writeBoostPhp(
            $root,
            "return BoostConfig::configure()\n"
            . '    ->withAgents([Agent::CLAUDE_CODE])' . "\n"
            . '    ->withAllowedVendors(["acme/pack"])' . "\n"
            . '    ->withExcludedSkills(["acme/pack:unwanted"]);',
        );

        $result = SyncEngine::default(new InstalledPackages(['acme/pack' => $pkg]))->sync($root);

        expect($result->hasErrors())->toBeFalse()
            ->and(file_exists($root . '/.claude/skills/unwanted/SKILL.md'))->toBeFalse();
    } finally {
        rmTreeE2E($root);
    }
});

it('prunes a previously-synced skill that a re-sync now tag-filters out', function (): void {
    $root = makeEndToEndProject();
    try {
        $pkg = makeTagVendor($root, 'acme/jira-pack', 'jira-skill', "metadata:\n  boost-tags: jira\n");
        $packages = new InstalledPackages(['acme/jira-pack' => $pkg]);

        // Sync 1: consumer declares `jira` → the skill ships.
        writeBoostPhp(
            $root,
            "return BoostConfig::configure()\n"
            . '    ->withAgents([Agent::CLAUDE_CODE])' . "\n"
            . '    ->withAllowedVendors(["acme/jira-pack"])' . "\n"
            . '    ->withTags("jira");',
        );
        SyncEngine::default($packages)->sync($root);
        expect(file_exists($root . '/.claude/skills/jira-skill/SKILL.md'))->toBeTrue();

        // Sync 2: consumer drops the tag → the skill is filtered and pruned.
        writeBoostPhp(
            $root,
            "return BoostConfig::configure()\n"
            . '    ->withAgents([Agent::CLAUDE_CODE])' . "\n"
            . '    ->withAllowedVendors(["acme/jira-pack"]);',
        );
        $result = SyncEngine::default($packages)->sync($root);

        expect($result->hasErrors())->toBeFalse()
            ->and(is_dir($root . '/.claude/skills/jira-skill'))->toBeFalse()
            ->and($result->countByAction(WriteAction::DELETED))->toBeGreaterThan(0);
    } finally {
        rmTreeE2E($root);
    }
});

it('check mode reports a would-be prune as drift without deleting', function (): void {
    $root = makeEndToEndProject();
    try {
        $pkg = makeTagVendor($root, 'acme/jira-pack', 'jira-skill', "metadata:\n  boost-tags: jira\n");
        $packages = new InstalledPackages(['acme/jira-pack' => $pkg]);

        writeBoostPhp(
            $root,
            "return BoostConfig::configure()\n"
            . '    ->withAgents([Agent::CLAUDE_CODE])' . "\n"
            . '    ->withAllowedVendors(["acme/jira-pack"])' . "\n"
            . '    ->withTags("jira");',
        );
        SyncEngine::default($packages)->sync($root);

        writeBoostPhp(
            $root,
            "return BoostConfig::configure()\n"
            . '    ->withAgents([Agent::CLAUDE_CODE])' . "\n"
            . '    ->withAllowedVendors(["acme/jira-pack"]);',
        );
        $result = SyncEngine::default($packages)->sync($root, checkOnly: true);

        expect($result->hasDrift())->toBeTrue()
            ->and($result->countByAction(WriteAction::WOULD_DELETE))->toBeGreaterThan(0)
            ->and(is_dir($root . '/.claude/skills/jira-skill'))->toBeTrue();
    } finally {
        rmTreeE2E($root);
    }
});

it('tag filtering disambiguates a would-be vendor-vs-vendor skill collision', function (): void {
    $root = makeEndToEndProject();
    try {
        // Two vendors both ship a skill named `deploy`. vendor-a's is jira-tagged.
        $pkgA = makeTagVendor($root, 'vendor-a/pack', 'deploy', "metadata:\n  boost-tags: jira\n");
        $pkgB = makeTagVendor($root, 'vendor-b/pack', 'deploy');
        $packages = new InstalledPackages([
            'vendor-a/pack' => $pkgA,
            'vendor-b/pack' => $pkgB,
        ]);

        // Consumer declares no tags → vendor-a's `deploy` is filtered out, so
        // only vendor-b's `deploy` remains and there is no collision to throw.
        writeBoostPhp(
            $root,
            "return BoostConfig::configure()\n"
            . '    ->withAgents([Agent::CLAUDE_CODE])' . "\n"
            . '    ->withAllowedVendors(["vendor-a/pack", "vendor-b/pack"]);',
        );

        $result = SyncEngine::default($packages)->sync($root);

        expect($result->hasErrors())->toBeFalse()
            ->and(file_exists($root . '/.claude/skills/deploy/SKILL.md'))->toBeTrue();
    } finally {
        rmTreeE2E($root);
    }
});

it('end-to-end: host command fans out to per-agent command files, gitignored', function (): void {
    $root = makeEndToEndProject();
    try {
        mkdir($root . '/.ai/commands', 0o755, recursive: true);
        writeBoostPhp(
            $root,
            "return BoostConfig::configure()\n"
            . '    ->withAgents([Agent::CLAUDE_CODE, Agent::COPILOT, Agent::GEMINI]);',
        );
        file_put_contents(
            $root . '/.ai/commands/deploy.md',
            "---\ndescription: Ship it.\n---\nRun the deploy.\n",
        );

        $result = SyncEngine::default(emptyInstalledPackages())->sync($root);

        expect($result->hasErrors())->toBeFalse()
            // Markdown command-dir agents receive the command...
            ->and(file_exists($root . '/.claude/commands/deploy.md'))->toBeTrue()
            ->and(file_exists($root . '/.github/prompts/deploy.prompt.md'))->toBeTrue()
            // ...Gemini has no Phase 1 command surface — nothing emitted.
            ->and(is_dir($root . '/.gemini/commands'))->toBeFalse()
            // the command directory joins the managed .gitignore block.
            ->and(file_get_contents($root . '/.gitignore'))->toContain('.claude/commands/')
            ->and(file_get_contents($root . '/.claude/commands/deploy.md'))
            ->toContain('Run the deploy.');
    } finally {
        rmTreeE2E($root);
    }
});

it('tag-filters a vendor guideline by its .boost-tags.yaml manifest entry', function (): void {
    $root = makeEndToEndProject();
    $vendorPath = $root . '/vendor/acme/db-pack';
    mkdir($vendorPath . '/resources/boost/guidelines', 0o755, recursive: true);
    try {
        file_put_contents($vendorPath . '/composer.json', '{"name":"acme/db-pack","type":"library"}');
        // A frontmatter-free guideline — laravel/boost-safe — tagged only via
        // the sidecar manifest, never inline.
        file_put_contents(
            $vendorPath . '/resources/boost/guidelines/db-safety.md',
            "# DB Safety\n\nNo destructive commands.\n",
        );
        file_put_contents(
            $vendorPath . '/resources/boost/guidelines/.boost-tags.yaml',
            "db-safety.md: \"database\"\n",
        );

        $packages = new InstalledPackages([
            'acme/db-pack' => new PackageInfo('acme/db-pack', '1.0.0', $vendorPath),
        ]);

        // No `database` tag declared → the manifest-tagged guideline is filtered out.
        writeBoostPhp(
            $root,
            "return BoostConfig::configure()\n"
            . '    ->withAgents([Agent::CLAUDE_CODE])' . "\n"
            . '    ->withAllowedVendors(["acme/db-pack"]);',
        );
        SyncEngine::default($packages)->sync($root);
        $withoutTag = is_file($root . '/CLAUDE.md') ? (string) file_get_contents($root . '/CLAUDE.md') : '';
        expect($withoutTag)->not->toContain('No destructive commands.');

        // Declare `database` → the manifest-tagged guideline now ships.
        writeBoostPhp(
            $root,
            "return BoostConfig::configure()\n"
            . '    ->withAgents([Agent::CLAUDE_CODE])' . "\n"
            . '    ->withAllowedVendors(["acme/db-pack"])' . "\n"
            . '    ->withTags("database");',
        );
        $result = SyncEngine::default($packages)->sync($root);

        expect($result->hasErrors())->toBeFalse()
            ->and((string) file_get_contents($root . '/CLAUDE.md'))->toContain('No destructive commands.');
    } finally {
        rmTreeE2E($root);
    }
});
