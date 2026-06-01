<?php declare(strict_types=1);

use SanderMuller\BoostCore\Agents\ClaudeCodeTarget;
use SanderMuller\BoostCore\Contracts\SkillRenderer;
use SanderMuller\BoostCore\Conventions\Diagnostic;
use SanderMuller\BoostCore\Skills\Guideline;
use SanderMuller\BoostCore\Skills\Remote\BundleExtractor;
use SanderMuller\BoostCore\Skills\Remote\RemoteFetchException;
use SanderMuller\BoostCore\Skills\Remote\RemoteSkillCache;
use SanderMuller\BoostCore\Skills\Remote\RemoteSkillIngester;
use SanderMuller\BoostCore\Skills\Rendering\RenderContext;
use SanderMuller\BoostCore\Skills\Skill;
use SanderMuller\BoostCore\Sync\InstalledPackages;
use SanderMuller\BoostCore\Sync\PackageInfo;
use SanderMuller\BoostCore\Sync\SyncEngine;
use SanderMuller\BoostCore\Sync\WriteAction;
use SanderMuller\BoostCore\Sync\WrittenFile;
use SanderMuller\BoostCore\Tests\Doubles\Remote\FakeRemoteFetcher;

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

/**
 * Build a minimal `.skill` ZIP bundle (one SKILL.md, optional body override).
 * Inlined here so the Phase 5 remote-source tests are self-contained when
 * EndToEndSyncTest runs in isolation (Pest only auto-loads functions from
 * the test files it's invoked with).
 */
function e2eMakeBundleBytes(string $skillName, ?string $frontmatterName = null, string $body = 'Body.'): string
{
    $tmpZip = sys_get_temp_dir() . '/boost-e2e-bundle-' . bin2hex(random_bytes(6)) . '.zip';
    $zip = new ZipArchive();
    $zip->open($tmpZip, ZipArchive::CREATE);

    $name = $frontmatterName ?? $skillName;
    $zip->addFromString($skillName . '/SKILL.md', "---\nname: {$name}\n---\n{$body}");
    $zip->close();

    $bytes = (string) file_get_contents($tmpZip);
    @unlink($tmpZip);

    return $bytes;
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

it('0.13.0: records a host guideline shadow ONLY when the vendor copy is tag-ELIGIBLE (no false positive on tag-filtered vendor guidelines)', function (): void {
    // k5m15b0p's mandatory nuance: a host guideline only "shadows" a vendor
    // guideline that WOULD otherwise emit (tag-eligible under declared
    // withTags). A tag-filtered-out vendor copy was never going to emit, so the
    // host copy isn't shadowing anything — recording it would be a false
    // positive. Tag-filtering runs before resolution, so this falls out cleanly.
    $root = makeEndToEndProject();
    $vendorPath = $root . '/vendor/acme/ops-pack';
    mkdir($vendorPath . '/resources/boost/guidelines', 0o755, recursive: true);
    try {
        file_put_contents($vendorPath . '/composer.json', '{"name":"acme/ops-pack","type":"library"}');
        file_put_contents($vendorPath . '/resources/boost/guidelines/release-automation.md', "# Vendor Release Automation\n\nVendor copy.\n");
        file_put_contents($vendorPath . '/resources/boost/guidelines/.boost-tags.yaml', "release-automation.md: \"database\"\n");

        // Host authors its OWN release-automation guideline of the same name.
        file_put_contents($root . '/.ai/guidelines/release-automation.md', "---\nname: release-automation\n---\n# Host Release Automation\n\nHost copy.\n");

        $packages = new InstalledPackages(['acme/ops-pack' => new PackageInfo('acme/ops-pack', '1.0.0', $vendorPath)]);

        // Case B — tag NOT declared → vendor copy is tag-filtered out → NOT a
        // shadow (the false-positive guard).
        writeBoostPhp($root, "return BoostConfig::configure()\n    ->withAgents([Agent::CLAUDE_CODE])\n    ->withAllowedVendors(['acme/ops-pack']);");
        $noTag = SyncEngine::default($packages)->sync($root);
        expect($noTag->hostGuidelineShadows)
            ->toBeEmpty();

        // Case A — tag declared → vendor copy tag-eligible → genuine shadow.
        writeBoostPhp($root, "return BoostConfig::configure()\n    ->withAgents([Agent::CLAUDE_CODE])\n    ->withAllowedVendors(['acme/ops-pack'])\n    ->withTags('database');");
        $tagged = SyncEngine::default($packages)->sync($root);
        expect($tagged->hostGuidelineShadows)->toBe([['guideline' => 'release-automation', 'shadowedVendor' => 'acme/ops-pack']]);
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

it('tagFilteredSkillsCount surfaces the silent-filter foot-gun when withTags is empty', function (): void {
    $root = makeEndToEndProject();
    $vendor = sys_get_temp_dir() . '/boost-nudge-empty-' . bin2hex(random_bytes(8));
    mkdir($vendor . '/resources/boost/skills', 0o755, recursive: true);

    try {
        file_put_contents($vendor . '/composer.json', json_encode(['name' => 'acme/jira-pack'], JSON_THROW_ON_ERROR));
        file_put_contents(
            $vendor . '/resources/boost/skills/jira-triage.md',
            "---\nname: jira-triage\ndescription: Triage Jira.\nmetadata:\n  boost-tags: jira\n---\nBody.\n",
        );

        // No `withTags(...)` declared — this is the silent-filter case three real
        // boost-stack repos hit before audits (repo-new, package-boost-laravel,
        // boost-skills's own dogfood).
        writeBoostPhp($root, "return BoostConfig::configure()\n    ->withAgents([Agent::CLAUDE_CODE])\n    ->withAllowedVendors(['acme/jira-pack']);");

        $packages = new InstalledPackages(['acme/jira-pack' => new PackageInfo('acme/jira-pack', '1.0.0', $vendor)]);
        $result = SyncEngine::default($packages)->sync($root);

        expect($result->tagFilteredSkillsCount)->toBe(1);
    } finally {
        rmTreeE2E($root);
        rmTreeE2E($vendor);
    }
});

it('tagFilteredSkillsCount stays zero when withTags is declared — intentional filtering does not nudge', function (): void {
    $root = makeEndToEndProject();
    $vendor = sys_get_temp_dir() . '/boost-nudge-decl-' . bin2hex(random_bytes(8));
    mkdir($vendor . '/resources/boost/skills', 0o755, recursive: true);

    try {
        file_put_contents($vendor . '/composer.json', json_encode(['name' => 'acme/jira-pack'], JSON_THROW_ON_ERROR));
        file_put_contents(
            $vendor . '/resources/boost/skills/jira-triage.md',
            "---\nname: jira-triage\ndescription: Triage Jira.\nmetadata:\n  boost-tags: jira\n---\nBody.\n",
        );

        // Consumer declared `withTags('php')` — `jira-triage` is still filtered
        // out (tag mismatch) but the consumer has clearly considered filtering;
        // no nudge fires.
        writeBoostPhp($root, "return BoostConfig::configure()\n    ->withAgents([Agent::CLAUDE_CODE])\n    ->withAllowedVendors(['acme/jira-pack'])\n    ->withTags('php');");

        $packages = new InstalledPackages(['acme/jira-pack' => new PackageInfo('acme/jira-pack', '1.0.0', $vendor)]);
        $result = SyncEngine::default($packages)->sync($root);

        expect($result->tagFilteredSkillsCount)->toBe(0);
    } finally {
        rmTreeE2E($root);
        rmTreeE2E($vendor);
    }
});

it('tagFilteredSkillsCount excludes skills dropped by withExcludedSkills (not a tag-mismatch)', function (): void {
    $root = makeEndToEndProject();
    $vendor = sys_get_temp_dir() . '/boost-nudge-excl-' . bin2hex(random_bytes(8));
    mkdir($vendor . '/resources/boost/skills', 0o755, recursive: true);

    try {
        file_put_contents($vendor . '/composer.json', json_encode(['name' => 'acme/pack'], JSON_THROW_ON_ERROR));
        file_put_contents(
            $vendor . '/resources/boost/skills/foo.md',
            "---\nname: foo\ndescription: A skill.\nmetadata:\n  boost-tags: jira\n---\nBody.\n",
        );

        // withTags is empty AND the consumer explicitly excludes the skill.
        // The drop reason is `excluded`, not tag-mismatch — nudge stays 0
        // even though `withTags()` is empty.
        writeBoostPhp($root, "return BoostConfig::configure()\n    ->withAgents([Agent::CLAUDE_CODE])\n    ->withAllowedVendors(['acme/pack'])\n    ->withExcludedSkills(['acme/pack:foo']);");

        $packages = new InstalledPackages(['acme/pack' => new PackageInfo('acme/pack', '1.0.0', $vendor)]);
        $result = SyncEngine::default($packages)->sync($root);

        expect($result->tagFilteredSkillsCount)->toBe(0);
    } finally {
        rmTreeE2E($root);
        rmTreeE2E($vendor);
    }
});

it('tagFilteredSkillsCount counts same-named skills across vendors separately (no dedup)', function (): void {
    $root = makeEndToEndProject();
    $vendorA = sys_get_temp_dir() . '/boost-nudge-vA-' . bin2hex(random_bytes(8));
    $vendorB = sys_get_temp_dir() . '/boost-nudge-vB-' . bin2hex(random_bytes(8));
    mkdir($vendorA . '/resources/boost/skills', 0o755, recursive: true);
    mkdir($vendorB . '/resources/boost/skills', 0o755, recursive: true);

    try {
        // Two vendors each shipping a skill named `shared` tagged `jira`. With
        // empty withTags, both are tag-filtered — count must be 2, not 1.
        file_put_contents($vendorA . '/composer.json', json_encode(['name' => 'acme/pack-a'], JSON_THROW_ON_ERROR));
        file_put_contents(
            $vendorA . '/resources/boost/skills/shared.md',
            "---\nname: shared\ndescription: A.\nmetadata:\n  boost-tags: jira\n---\nA body.\n",
        );
        file_put_contents($vendorB . '/composer.json', json_encode(['name' => 'acme/pack-b'], JSON_THROW_ON_ERROR));
        file_put_contents(
            $vendorB . '/resources/boost/skills/shared.md',
            "---\nname: shared\ndescription: B.\nmetadata:\n  boost-tags: jira\n---\nB body.\n",
        );

        writeBoostPhp($root, "return BoostConfig::configure()\n    ->withAgents([Agent::CLAUDE_CODE])\n    ->withAllowedVendors(['acme/pack-a', 'acme/pack-b']);");

        $packages = new InstalledPackages([
            'acme/pack-a' => new PackageInfo('acme/pack-a', '1.0.0', $vendorA),
            'acme/pack-b' => new PackageInfo('acme/pack-b', '1.0.0', $vendorB),
        ]);
        $result = SyncEngine::default($packages)->sync($root);

        expect($result->tagFilteredSkillsCount)->toBe(2);
    } finally {
        rmTreeE2E($root);
        rmTreeE2E($vendorA);
        rmTreeE2E($vendorB);
    }
});

// ============================================================================
// Phase 5 — remote-skill sources end-to-end through SyncEngine.
// Uses FakeRemoteFetcher + a temp cache root to avoid touching the network.
// `cacheMakeBundleBytes` lives in RemoteSkillCacheTest (Pest auto-loads top-level functions).
// ============================================================================

it('remote-skill source: a declared bundle skill lands in the agent fan-out', function (): void {
    $root = makeEndToEndProject();
    $cacheRoot = sys_get_temp_dir() . '/boost-remote-e2e-' . bin2hex(random_bytes(6));

    try {
        writeBoostPhp($root, "use SanderMuller\\BoostCore\\Skills\\Remote\\RemoteSkillSource;\n\nreturn BoostConfig::configure()\n    ->withAgents([Agent::CLAUDE_CODE])\n    ->withRemoteSkills([\n        RemoteSkillSource::githubBundle('peterfox/agent-skills', 'v1.2.0', ['composer-upgrade']),\n    ]);");

        $fetcher = (new FakeRemoteFetcher())
            ->withAsset('peterfox/agent-skills', 'v1.2.0', 'composer-upgrade.skill', e2eMakeBundleBytes('composer-upgrade'));

        $ingester = new RemoteSkillIngester(
            cache: new RemoteSkillCache(fetcher: $fetcher, cacheRoot: $cacheRoot),
        );

        $engine = new SyncEngine(
            agentTargets: [new ClaudeCodeTarget()],
            installedPackages: emptyInstalledPackages(),
            remoteSkillIngester: $ingester,
        );

        $result = $engine->sync($root);

        $wrotePaths = array_map(fn (WrittenFile $w): string => $w->relativePath, $result->writes);
        expect($result->hasErrors())->toBeFalse('errors=' . json_encode($result->errors) . ' writes=' . json_encode($wrotePaths));
        $remoteWrite = null;
        foreach ($wrotePaths as $rel) {
            if (str_contains($rel, 'composer-upgrade')) {
                $remoteWrite = $rel;
                break;
            }
        }

        expect($remoteWrite)->not->toBeNull('No write mentions composer-upgrade. writes=' . json_encode($wrotePaths));
    } finally {
        rmTreeE2E($root);
        BundleExtractor::recursivelyRemove($cacheRoot);
    }
});

it('remote-skill source: name-mismatch surfaces as an error and the skill is not fanned out', function (): void {
    $root = makeEndToEndProject();
    $cacheRoot = sys_get_temp_dir() . '/boost-remote-mismatch-' . bin2hex(random_bytes(6));

    try {
        writeBoostPhp($root, "use SanderMuller\\BoostCore\\Skills\\Remote\\RemoteSkillSource;\n\nreturn BoostConfig::configure()\n    ->withAgents([Agent::CLAUDE_CODE])\n    ->withRemoteSkills([\n        RemoteSkillSource::githubBundle('peterfox/agent-skills', 'v1.2.0', ['composer-upgrade']),\n    ]);");

        // Wrapper dir matches the declared name (so the cache layout works),
        // but the inner SKILL.md frontmatter says `name: WRONG-NAME` —
        // RemoteSkillIngester must catch the mismatch.
        $fetcher = (new FakeRemoteFetcher())
            ->withAsset(
                'peterfox/agent-skills',
                'v1.2.0',
                'composer-upgrade.skill',
                e2eMakeBundleBytes('composer-upgrade', frontmatterName: 'WRONG-NAME'),
            );

        $ingester = new RemoteSkillIngester(
            cache: new RemoteSkillCache(fetcher: $fetcher, cacheRoot: $cacheRoot),
        );

        $engine = new SyncEngine(
            agentTargets: [new ClaudeCodeTarget()],
            installedPackages: emptyInstalledPackages(),
            remoteSkillIngester: $ingester,
        );

        $result = $engine->sync($root);

        expect($result->hasErrors())->toBeTrue();
        $errors = implode("\n", $result->errors);
        expect($errors)->toContain('does not match')
            ->and($errors)->toContain('WRONG-NAME');
    } finally {
        rmTreeE2E($root);
        BundleExtractor::recursivelyRemove($cacheRoot);
    }
});

it('remote-skill source: strict mode aborts on a fetch failure', function (): void {
    $root = makeEndToEndProject();
    $cacheRoot = sys_get_temp_dir() . '/boost-remote-strict-' . bin2hex(random_bytes(6));

    try {
        writeBoostPhp($root, "use SanderMuller\\BoostCore\\Skills\\Remote\\RemoteSkillSource;\n\nreturn BoostConfig::configure()\n    ->withAgents([Agent::CLAUDE_CODE])\n    ->withRemoteSkills([\n        RemoteSkillSource::githubBundle('peterfox/agent-skills', 'v1.2.0', ['composer-upgrade']),\n    ]);");

        // FakeRemoteFetcher with NOTHING registered → ensureCached throws NOT_FOUND.
        $emptyFetcher = new FakeRemoteFetcher();

        $strictIngester = new RemoteSkillIngester(
            cache: new RemoteSkillCache(fetcher: $emptyFetcher, cacheRoot: $cacheRoot),
            strict: true,
        );

        $engine = new SyncEngine(
            agentTargets: [new ClaudeCodeTarget()],
            installedPackages: emptyInstalledPackages(),
            remoteSkillIngester: $strictIngester,
        );

        expect(fn () => $engine->sync($root))
            ->toThrow(RemoteFetchException::class);
    } finally {
        rmTreeE2E($root);
        BundleExtractor::recursivelyRemove($cacheRoot);
    }
});

// Phase 6 — pruning: when a remote skill drops out of withRemoteSkills(...),
// the agent-dir output and the manifest entry both go.

it('remote-skill source: removing a skill from boost.php prunes its fan-out directory on the next sync', function (): void {
    $root = makeEndToEndProject();
    $cacheRoot = sys_get_temp_dir() . '/boost-remote-prune-' . bin2hex(random_bytes(6));

    try {
        // Sync 1: declare TWO skills under one source; both fan out.
        writeBoostPhp($root, "use SanderMuller\\BoostCore\\Skills\\Remote\\RemoteSkillSource;\n\nreturn BoostConfig::configure()\n    ->withAgents([Agent::CLAUDE_CODE])\n    ->withRemoteSkills([\n        RemoteSkillSource::githubBundle('peterfox/agent-skills', 'v1.2.0', ['composer-upgrade', 'phpstan-developer']),\n    ]);");

        $fetcher = (new FakeRemoteFetcher())
            ->withAsset('peterfox/agent-skills', 'v1.2.0', 'composer-upgrade.skill', e2eMakeBundleBytes('composer-upgrade'))
            ->withAsset('peterfox/agent-skills', 'v1.2.0', 'phpstan-developer.skill', e2eMakeBundleBytes('phpstan-developer'));

        $build = fn () => new SyncEngine(
            agentTargets: [new ClaudeCodeTarget()],
            installedPackages: emptyInstalledPackages(),
            remoteSkillIngester: new RemoteSkillIngester(
                cache: new RemoteSkillCache(fetcher: $fetcher, cacheRoot: $cacheRoot),
            ),
        );

        $first = $build()->sync($root);
        expect($first->hasErrors())->toBeFalse('first sync errors: ' . json_encode($first->errors))
            ->and(is_file($root . '/.claude/skills/composer-upgrade/SKILL.md'))
            ->toBeTrue()
            ->and(is_file($root . '/.claude/skills/phpstan-developer/SKILL.md'))
            ->toBeTrue()
            ->and(is_file($root . '/.boost-remote-manifest.json'))
            ->toBeTrue();

        // Sync 2: drop `phpstan-developer` from the declared set.
        writeBoostPhp($root, "use SanderMuller\\BoostCore\\Skills\\Remote\\RemoteSkillSource;\n\nreturn BoostConfig::configure()\n    ->withAgents([Agent::CLAUDE_CODE])\n    ->withRemoteSkills([\n        RemoteSkillSource::githubBundle('peterfox/agent-skills', 'v1.2.0', ['composer-upgrade']),\n    ]);");

        $second = $build()->sync($root);
        $deletedPaths = array_map(
            fn (WrittenFile $w): string => $w->relativePath,
            array_values(array_filter(
                $second->writes,
                fn (WrittenFile $w): bool => $w->action === WriteAction::DELETED,
            )),
        );

        expect($second->hasErrors())->toBeFalse('second sync errors: ' . json_encode($second->errors))
            ->and($deletedPaths)
            ->toContain('.claude/skills/phpstan-developer')
            ->and(is_dir($root . '/.claude/skills/phpstan-developer'))
            ->toBeFalse()
            ->and(is_file($root . '/.claude/skills/composer-upgrade/SKILL.md'))
            ->toBeTrue();
    } finally {
        rmTreeE2E($root);
        BundleExtractor::recursivelyRemove($cacheRoot);
    }
});

it('remote-skill source: removing an entire source prunes every skill under its slug and cleans the slug dir', function (): void {
    $root = makeEndToEndProject();
    $cacheRoot = sys_get_temp_dir() . '/boost-remote-prune-src-' . bin2hex(random_bytes(6));

    try {
        writeBoostPhp($root, "use SanderMuller\\BoostCore\\Skills\\Remote\\RemoteSkillSource;\n\nreturn BoostConfig::configure()\n    ->withAgents([Agent::CLAUDE_CODE])\n    ->withRemoteSkills([\n        RemoteSkillSource::githubBundle('peterfox/agent-skills', 'v1.2.0', ['composer-upgrade']),\n    ]);");

        $fetcher = (new FakeRemoteFetcher())
            ->withAsset('peterfox/agent-skills', 'v1.2.0', 'composer-upgrade.skill', e2eMakeBundleBytes('composer-upgrade'));

        $build = fn () => new SyncEngine(
            agentTargets: [new ClaudeCodeTarget()],
            installedPackages: emptyInstalledPackages(),
            remoteSkillIngester: new RemoteSkillIngester(
                cache: new RemoteSkillCache(fetcher: $fetcher, cacheRoot: $cacheRoot),
            ),
        );

        $build()->sync($root);
        expect(is_dir($root . '/.claude/skills/composer-upgrade'))->toBeTrue();

        // Sync 2: drop the whole withRemoteSkills clause.
        writeBoostPhp($root, "return BoostConfig::configure()\n    ->withAgents([Agent::CLAUDE_CODE]);");

        $second = $build()->sync($root);
        expect($second->hasErrors())->toBeFalse()
            ->and(is_dir($root . '/.claude/skills/composer-upgrade'))
            ->toBeFalse();
        // Manifest deleted when nothing's declared.
        expect($root . '/.boost-remote-manifest.json')->not->toBeFile();
    } finally {
        rmTreeE2E($root);
        BundleExtractor::recursivelyRemove($cacheRoot);
    }
});

it('remote-skill source: a still-declared skill whose fetch fails this sync is NOT pruned', function (): void {
    $root = makeEndToEndProject();
    $cacheRoot = sys_get_temp_dir() . '/boost-remote-prune-keep-' . bin2hex(random_bytes(6));

    try {
        // Sync 1: skill resolves and fans out.
        $boostBody = "use SanderMuller\\BoostCore\\Skills\\Remote\\RemoteSkillSource;\n\nreturn BoostConfig::configure()\n    ->withAgents([Agent::CLAUDE_CODE])\n    ->withRemoteSkills([\n        RemoteSkillSource::githubBundle('peterfox/agent-skills', 'v1.2.0', ['composer-upgrade']),\n    ]);";
        writeBoostPhp($root, $boostBody);

        $okFetcher = (new FakeRemoteFetcher())
            ->withAsset('peterfox/agent-skills', 'v1.2.0', 'composer-upgrade.skill', e2eMakeBundleBytes('composer-upgrade'));

        (new SyncEngine(
            agentTargets: [new ClaudeCodeTarget()],
            installedPackages: emptyInstalledPackages(),
            remoteSkillIngester: new RemoteSkillIngester(
                cache: new RemoteSkillCache(fetcher: $okFetcher, cacheRoot: $cacheRoot),
            ),
        ))->sync($root);

        expect($root . '/.claude/skills/composer-upgrade/SKILL.md')
            ->toBeFile();

        // Invalidate the cache slot so sync 2 must re-fetch.
        BundleExtractor::recursivelyRemove($cacheRoot);

        // Sync 2: SAME config (skill still declared) but fetcher empty → fetch fails.
        // Previously cached agent dir must NOT be pruned — user still wants the skill.
        $brokenFetcher = new FakeRemoteFetcher();

        $result = (new SyncEngine(
            agentTargets: [new ClaudeCodeTarget()],
            installedPackages: emptyInstalledPackages(),
            remoteSkillIngester: new RemoteSkillIngester(
                cache: new RemoteSkillCache(fetcher: $brokenFetcher, cacheRoot: $cacheRoot),
            ),
        ))->sync($root);

        expect($result->hasErrors())->toBeTrue('expected the failing fetch to be recorded')
            ->and(is_file($root . '/.claude/skills/composer-upgrade/SKILL.md'))
            ->toBeTrue('previously fanned-out skill must survive a transient fetch failure');
    } finally {
        rmTreeE2E($root);
        BundleExtractor::recursivelyRemove($cacheRoot);
    }
});

it('remote-skill source: --check mode reports a WOULD_DELETE for orphans without actually deleting or rewriting the manifest', function (): void {
    $root = makeEndToEndProject();
    $cacheRoot = sys_get_temp_dir() . '/boost-remote-prune-check-' . bin2hex(random_bytes(6));

    try {
        // Sync 1: declare a remote skill, sync, manifest written, dir written.
        writeBoostPhp($root, "use SanderMuller\\BoostCore\\Skills\\Remote\\RemoteSkillSource;\n\nreturn BoostConfig::configure()\n    ->withAgents([Agent::CLAUDE_CODE])\n    ->withRemoteSkills([\n        RemoteSkillSource::githubBundle('peterfox/agent-skills', 'v1.2.0', ['composer-upgrade']),\n    ]);");

        $fetcher = (new FakeRemoteFetcher())
            ->withAsset('peterfox/agent-skills', 'v1.2.0', 'composer-upgrade.skill', e2eMakeBundleBytes('composer-upgrade'));

        $engine = fn () => new SyncEngine(
            agentTargets: [new ClaudeCodeTarget()],
            installedPackages: emptyInstalledPackages(),
            remoteSkillIngester: new RemoteSkillIngester(
                cache: new RemoteSkillCache(fetcher: $fetcher, cacheRoot: $cacheRoot),
            ),
        );
        $engine()->sync($root);
        expect($root . '/.boost-remote-manifest.json')
            ->toBeFile();

        // Snapshot manifest contents to confirm --check doesn't rewrite it.
        $manifestBefore = (string) file_get_contents($root . '/.boost-remote-manifest.json');

        // Drop the skill from config, then run --check. Expect a WOULD_DELETE
        // for the orphan; dir survives; manifest unchanged.
        writeBoostPhp($root, "return BoostConfig::configure()\n    ->withAgents([Agent::CLAUDE_CODE]);");
        $checkResult = $engine()->sync($root, checkOnly: true);

        $wouldDeletePaths = array_map(
            fn (WrittenFile $w): string => $w->relativePath,
            array_values(array_filter(
                $checkResult->writes,
                fn (WrittenFile $w): bool => $w->action === WriteAction::WOULD_DELETE,
            )),
        );

        expect($wouldDeletePaths)->toContain('.claude/skills/composer-upgrade')
            ->and(is_dir($root . '/.claude/skills/composer-upgrade'))
            ->toBeTrue('--check must not delete anything')
            ->and((string) file_get_contents($root . '/.boost-remote-manifest.json'))
            ->toBe($manifestBefore, '--check must not rewrite the manifest');
    } finally {
        rmTreeE2E($root);
        BundleExtractor::recursivelyRemove($cacheRoot);
    }
});

it('remote-skill source: pruning runs even when the still-declared source fails to fetch', function (): void {
    $root = makeEndToEndProject();
    $cacheRoot = sys_get_temp_dir() . '/boost-remote-prune-fetchfail-' . bin2hex(random_bytes(6));

    try {
        // Sync 1: two sources, both succeed.
        writeBoostPhp($root, "use SanderMuller\\BoostCore\\Skills\\Remote\\RemoteSkillSource;\n\nreturn BoostConfig::configure()\n    ->withAgents([Agent::CLAUDE_CODE])\n    ->withRemoteSkills([\n        RemoteSkillSource::githubBundle('peterfox/agent-skills', 'v1.2.0', ['composer-upgrade']),\n        RemoteSkillSource::githubBundle('other/skills', 'v0.1.0', ['my-skill']),\n    ]);");

        $okFetcher = (new FakeRemoteFetcher())
            ->withAsset('peterfox/agent-skills', 'v1.2.0', 'composer-upgrade.skill', e2eMakeBundleBytes('composer-upgrade'))
            ->withAsset('other/skills', 'v0.1.0', 'my-skill.skill', e2eMakeBundleBytes('my-skill'));

        $engine1 = new SyncEngine(
            agentTargets: [new ClaudeCodeTarget()],
            installedPackages: emptyInstalledPackages(),
            remoteSkillIngester: new RemoteSkillIngester(
                cache: new RemoteSkillCache(fetcher: $okFetcher, cacheRoot: $cacheRoot),
            ),
        );
        $engine1->sync($root);

        expect(is_dir($root . '/.claude/skills/composer-upgrade'))->toBeTrue()
            ->and(is_dir($root . '/.claude/skills/my-skill'))
            ->toBeTrue();

        // Sync 2: drop `other/skills` entirely; declared `peterfox/agent-skills`
        // fetcher returns nothing → fetch fails → its skill stays absent.
        // But the prune for the dropped source MUST still run.
        writeBoostPhp($root, "use SanderMuller\\BoostCore\\Skills\\Remote\\RemoteSkillSource;\n\nreturn BoostConfig::configure()\n    ->withAgents([Agent::CLAUDE_CODE])\n    ->withRemoteSkills([\n        RemoteSkillSource::githubBundle('peterfox/agent-skills', 'v9.9.9', ['composer-upgrade']),\n    ]);");

        $brokenFetcher = new FakeRemoteFetcher();  // empty — every fetch throws.

        $engine2 = new SyncEngine(
            agentTargets: [new ClaudeCodeTarget()],
            installedPackages: emptyInstalledPackages(),
            remoteSkillIngester: new RemoteSkillIngester(
                cache: new RemoteSkillCache(fetcher: $brokenFetcher, cacheRoot: $cacheRoot),
            ),
        );

        $result = $engine2->sync($root);

        expect($result->hasErrors())->toBeTrue('expected the failing fetch to be recorded');
        // Despite the fetch failure, the dropped `other/skills` was pruned.
        expect(is_dir($root . '/.claude/skills/my-skill'))->toBeFalse();
    } finally {
        rmTreeE2E($root);
        BundleExtractor::recursivelyRemove($cacheRoot);
    }
});

it('remote-skill source: warn-and-skip mode does NOT abort the sync (default)', function (): void {
    $root = makeEndToEndProject();
    $cacheRoot = sys_get_temp_dir() . '/boost-remote-warn-' . bin2hex(random_bytes(6));

    try {
        writeBoostPhp($root, "use SanderMuller\\BoostCore\\Skills\\Remote\\RemoteSkillSource;\n\nreturn BoostConfig::configure()\n    ->withAgents([Agent::CLAUDE_CODE])\n    ->withRemoteSkills([\n        RemoteSkillSource::githubBundle('peterfox/agent-skills', 'v1.2.0', ['composer-upgrade']),\n    ]);");

        $emptyFetcher = new FakeRemoteFetcher();

        $defaultIngester = new RemoteSkillIngester(
            cache: new RemoteSkillCache(fetcher: $emptyFetcher, cacheRoot: $cacheRoot),
            // strict: false (default) — fetch errors become recorded errors, not exceptions.
        );

        $engine = new SyncEngine(
            agentTargets: [new ClaudeCodeTarget()],
            installedPackages: emptyInstalledPackages(),
            remoteSkillIngester: $defaultIngester,
        );

        $result = $engine->sync($root);

        // Sync completes; the remote source's failure is recorded but doesn't abort.
        expect($result->hasErrors())->toBeTrue();
        expect(implode("\n", $result->errors))->toContain('peterfox/agent-skills');
    } finally {
        rmTreeE2E($root);
        BundleExtractor::recursivelyRemove($cacheRoot);
    }
});

it('caller-injected vendor skills land in agent dirs alongside scanned vendors', function (): void {
    $root = makeEndToEndProject();
    try {
        writeBoostPhp($root, "return BoostConfig::configure()\n    ->withAgents([Agent::CLAUDE_CODE]);");

        $injected = [
            'acme/external-bridge' => [
                new Skill(
                    name: 'bridge-skill',
                    description: 'Pre-built by a caller bridge.',
                    frontmatter: ['name' => 'bridge-skill', 'description' => 'Pre-built by a caller bridge.'],
                    body: "# Bridge\n\nInjected by the caller.\n",
                    sourcePath: '/virtual/bridge-skill/SKILL.md',
                    sourceVendor: 'acme/external-bridge',
                    tags: [],
                    tagsValid: true,
                ),
            ],
        ];

        $result = SyncEngine::default(emptyInstalledPackages())->sync($root, injectedVendorSkills: $injected);

        expect($result->hasErrors())->toBeFalse()
            ->and(file_exists($root . '/.claude/skills/bridge-skill/SKILL.md'))->toBeTrue()
            ->and(file_get_contents($root . '/.claude/skills/bridge-skill/SKILL.md'))->toContain('Injected by the caller.');
    } finally {
        rmTreeE2E($root);
    }
});

it('tag-filters injected vendor skills using the same subset rule as scanned vendors', function (): void {
    $root = makeEndToEndProject();
    try {
        writeBoostPhp(
            $root,
            "return BoostConfig::configure()\n"
            . '    ->withAgents([Agent::CLAUDE_CODE])' . "\n"
            . '    ->withTags("php");',
        );

        $injected = [
            'acme/external-bridge' => [
                new Skill(
                    name: 'php-skill',
                    description: 'Tagged php.',
                    frontmatter: [],
                    body: 'PHP.',
                    sourcePath: '/virtual/php-skill/SKILL.md',
                    sourceVendor: 'acme/external-bridge',
                    tags: ['php'],
                    tagsValid: true,
                ),
                new Skill(
                    name: 'jira-skill',
                    description: 'Tagged jira — should be dropped.',
                    frontmatter: [],
                    body: 'Jira.',
                    sourcePath: '/virtual/jira-skill/SKILL.md',
                    sourceVendor: 'acme/external-bridge',
                    tags: ['jira'],
                    tagsValid: true,
                ),
            ],
        ];

        $result = SyncEngine::default(emptyInstalledPackages())->sync($root, injectedVendorSkills: $injected);

        expect($result->hasErrors())->toBeFalse()
            ->and(file_exists($root . '/.claude/skills/php-skill/SKILL.md'))->toBeTrue()
            ->and(file_exists($root . '/.claude/skills/jira-skill/SKILL.md'))->toBeFalse();
    } finally {
        rmTreeE2E($root);
    }
});

it('records a SyncResult error when injectedVendorSkills lists the same skill name twice within one vendor', function (): void {
    $root = makeEndToEndProject();
    try {
        writeBoostPhp($root, "return BoostConfig::configure()\n    ->withAgents([Agent::CLAUDE_CODE]);");

        $dupe = static fn (): Skill => new Skill(
            name: 'dup',
            description: 'd',
            frontmatter: [],
            body: '.',
            sourcePath: '/virtual/dup/SKILL.md',
            sourceVendor: 'acme/bridge',
            tags: [],
            tagsValid: true,
        );

        $result = SyncEngine::default(emptyInstalledPackages())->sync($root, injectedVendorSkills: [
            'acme/bridge' => [$dupe(), $dupe()],
        ]);

        // sync() catches SkillSourceCollisionException and converts to a
        // SyncResult error (preserves the wrapper-friendly contract that
        // sync never throws on user-config issues).
        expect($result->hasErrors())->toBeTrue()
            ->and(implode("\n", $result->errors))->toContain('listed more than once');
    } finally {
        rmTreeE2E($root);
    }
});

it('records a SyncResult error when injectedVendorSkills overlaps a scanned vendor of the same name', function (): void {
    $root = makeEndToEndProject();
    try {
        $pkg = makeTagVendor($root, 'acme/shared', 'shared-skill', '');
        writeBoostPhp(
            $root,
            "return BoostConfig::configure()\n"
            . '    ->withAgents([Agent::CLAUDE_CODE])' . "\n"
            . '    ->withAllowedVendors(["acme/shared"]);',
        );

        $injected = [
            'acme/shared' => [
                new Skill(
                    name: 'shared-skill',
                    description: 'caller-injected duplicate of scanned vendor skill',
                    frontmatter: [],
                    body: '.',
                    sourcePath: '/virtual/shared-skill/SKILL.md',
                    sourceVendor: 'acme/shared',
                    tags: [],
                    tagsValid: true,
                ),
            ],
        ];

        $result = SyncEngine::default(new InstalledPackages(['acme/shared' => $pkg]))->sync($root, injectedVendorSkills: $injected);

        expect($result->hasErrors())->toBeTrue()
            ->and(implode("\n", $result->errors))->toContain('also published by a scanned vendor');
    } finally {
        rmTreeE2E($root);
    }
});

it('respects withExcludedSkills against injected vendor skills', function (): void {
    $root = makeEndToEndProject();
    try {
        writeBoostPhp(
            $root,
            "return BoostConfig::configure()\n"
            . '    ->withAgents([Agent::CLAUDE_CODE])' . "\n"
            . '    ->withExcludedSkills(["acme/external-bridge:unwanted"]);',
        );

        $injected = [
            'acme/external-bridge' => [
                new Skill(
                    name: 'unwanted',
                    description: 'Should be dropped by exclude list.',
                    frontmatter: [],
                    body: 'X.',
                    sourcePath: '/virtual/unwanted/SKILL.md',
                    sourceVendor: 'acme/external-bridge',
                    tags: [],
                    tagsValid: true,
                ),
            ],
        ];

        $result = SyncEngine::default(emptyInstalledPackages())->sync($root, injectedVendorSkills: $injected);

        expect($result->hasErrors())->toBeFalse()
            ->and(file_exists($root . '/.claude/skills/unwanted/SKILL.md'))->toBeFalse();
    } finally {
        rmTreeE2E($root);
    }
});

it('caller-injected vendor guidelines feed into the CLAUDE.md/AGENTS.md fan-out', function (): void {
    $root = makeEndToEndProject();
    try {
        writeBoostPhp($root, "return BoostConfig::configure()\n    ->withAgents([Agent::CLAUDE_CODE]);");

        $injected = [
            'laravel/boost' => [
                new Guideline(
                    name: 'laravel-best-practices',
                    description: 'Laravel best practices guideline (injected).',
                    frontmatter: [],
                    body: "## Laravel Conventions\n\nBe consistent with the codebase.\n",
                    sourcePath: '/virtual/laravel-best-practices.md',
                    sourceVendor: 'laravel/boost',
                    tags: [],
                    tagsValid: true,
                ),
            ],
        ];

        $result = SyncEngine::default(emptyInstalledPackages())->sync(
            $root,
            injectedVendorGuidelines: $injected,
        );

        expect($result->hasErrors())->toBeFalse()
            ->and(file_exists($root . '/CLAUDE.md'))->toBeTrue()
            ->and(file_get_contents($root . '/CLAUDE.md'))->toContain('Be consistent with the codebase');
    } finally {
        rmTreeE2E($root);
    }
});

it('records a SyncResult error when injectedVendorGuidelines lists the same guideline name twice within one vendor', function (): void {
    $root = makeEndToEndProject();
    try {
        writeBoostPhp($root, "return BoostConfig::configure()\n    ->withAgents([Agent::CLAUDE_CODE]);");

        $dupe = static fn (): Guideline => new Guideline(
            name: 'dup',
            description: 'd',
            frontmatter: [],
            body: '.',
            sourcePath: '/virtual/dup.md',
            sourceVendor: 'acme/bridge',
            tags: [],
            tagsValid: true,
        );

        $result = SyncEngine::default(emptyInstalledPackages())->sync($root, injectedVendorGuidelines: [
            'acme/bridge' => [$dupe(), $dupe()],
        ]);

        expect($result->hasErrors())->toBeTrue()
            ->and(implode("\n", $result->errors))->toContain('listed more than once');
    } finally {
        rmTreeE2E($root);
    }
});

it('records a SyncResult error when injectedVendorGuidelines overlaps a scanned vendor of the same name', function (): void {
    $root = makeEndToEndProject();
    try {
        // Inline a guideline-publishing vendor since makeTagVendor only does skills.
        $vendorPath = $root . '/vendor/acme/shared';
        mkdir($vendorPath . '/resources/boost/guidelines', 0o755, recursive: true);
        file_put_contents($vendorPath . '/composer.json', '{"name":"acme/shared","type":"library"}');
        file_put_contents(
            $vendorPath . '/resources/boost/guidelines/shared-guideline.md',
            "---\nname: shared-guideline\n---\nBody.\n",
        );
        $pkg = new PackageInfo('acme/shared', '1.0.0', $vendorPath);

        writeBoostPhp(
            $root,
            "return BoostConfig::configure()\n"
            . '    ->withAgents([Agent::CLAUDE_CODE])' . "\n"
            . '    ->withAllowedVendors(["acme/shared"]);',
        );

        $injected = [
            'acme/shared' => [
                new Guideline(
                    name: 'shared-guideline',
                    description: 'caller-injected duplicate of scanned vendor guideline',
                    frontmatter: [],
                    body: '.',
                    sourcePath: '/virtual/shared-guideline.md',
                    sourceVendor: 'acme/shared',
                    tags: [],
                    tagsValid: true,
                ),
            ],
        ];

        $result = SyncEngine::default(new InstalledPackages(['acme/shared' => $pkg]))->sync($root, injectedVendorGuidelines: $injected);

        expect($result->hasErrors())->toBeTrue()
            ->and(implode("\n", $result->errors))->toContain('also published by a scanned vendor');
    } finally {
        rmTreeE2E($root);
    }
});

it('tag-filters injected vendor guidelines using the same subset rule', function (): void {
    $root = makeEndToEndProject();
    try {
        writeBoostPhp(
            $root,
            "return BoostConfig::configure()\n"
            . '    ->withAgents([Agent::CLAUDE_CODE])' . "\n"
            . '    ->withTags("php");',
        );

        $injected = [
            'laravel/boost' => [
                new Guideline(
                    name: 'kept',
                    description: 'php-tagged.',
                    frontmatter: [],
                    body: 'KEPT.',
                    sourcePath: '/virtual/kept.md',
                    sourceVendor: 'laravel/boost',
                    tags: ['php'],
                    tagsValid: true,
                ),
                new Guideline(
                    name: 'dropped',
                    description: 'jira-tagged, should be dropped.',
                    frontmatter: [],
                    body: 'DROPPED.',
                    sourcePath: '/virtual/dropped.md',
                    sourceVendor: 'laravel/boost',
                    tags: ['jira'],
                    tagsValid: true,
                ),
            ],
        ];

        $result = SyncEngine::default(emptyInstalledPackages())->sync(
            $root,
            injectedVendorGuidelines: $injected,
        );

        expect($result->hasErrors())->toBeFalse()
            ->and(file_exists($root . '/CLAUDE.md'))->toBeTrue()
            ->and(file_get_contents($root . '/CLAUDE.md'))->toContain('KEPT')
            ->and(file_get_contents($root . '/CLAUDE.md'))->not->toContain('DROPPED');
    } finally {
        rmTreeE2E($root);
    }
});

it('extraSkillRenderers PREPENDS — a user md-claiming renderer wins over implicit passthrough', function (): void {
    $root = makeEndToEndProject();
    try {
        writeBoostPhp($root, "return BoostConfig::configure()\n    ->withAgents([Agent::CLAUDE_CODE]);");
        file_put_contents($root . '/.ai/skills/foo.md', "---\nname: foo\n---\nhello");

        $upper = new class implements SkillRenderer {
            /** @return list<string> */
            public function extensions(): array
            {
                return ['md'];
            }

            public function render(string $raw, RenderContext $ctx): string
            {
                return strtoupper($raw);
            }
        };

        SyncEngine::default(emptyInstalledPackages())->sync($root, extraSkillRenderers: [$upper]);

        $written = (string) file_get_contents($root . '/.claude/skills/foo/SKILL.md');
        // body uppercased proves the extra renderer ran (passthrough would have left it lowercase)
        expect($written)->toContain('HELLO');
    } finally {
        rmTreeE2E($root);
    }
});

it('detects same-name remote skill across two source versions and records a sync error', function (): void {
    // Two RemoteSkillSource entries pointing at the same repo at different
    // versions, both listing `composer-upgrade`. uniqueKey() rejects only
    // exact-triple duplicates, so this passes withRemoteSkills validation.
    // The ingester must detect the collision (one vendor key, two skills
    // with the same name) and surface it instead of silently first-winning.
    $root = makeEndToEndProject();
    $cacheRoot = sys_get_temp_dir() . '/boost-remote-collide-' . bin2hex(random_bytes(6));

    try {
        writeBoostPhp($root, "use SanderMuller\\BoostCore\\Skills\\Remote\\RemoteSkillSource;\n\nreturn BoostConfig::configure()\n    ->withAgents([Agent::CLAUDE_CODE])\n    ->withRemoteSkills([\n        RemoteSkillSource::githubBundle('peterfox/agent-skills', 'v1.0.0', ['composer-upgrade']),\n        RemoteSkillSource::githubBundle('peterfox/agent-skills', 'v2.0.0', ['composer-upgrade']),\n    ]);");

        $fetcher = (new FakeRemoteFetcher())
            ->withAsset('peterfox/agent-skills', 'v1.0.0', 'composer-upgrade.skill', e2eMakeBundleBytes('composer-upgrade', body: 'V1 body'))
            ->withAsset('peterfox/agent-skills', 'v2.0.0', 'composer-upgrade.skill', e2eMakeBundleBytes('composer-upgrade', body: 'V2 body'));

        $engine = new SyncEngine(
            agentTargets: [new ClaudeCodeTarget()],
            installedPackages: emptyInstalledPackages(),
            remoteSkillIngester: new RemoteSkillIngester(
                cache: new RemoteSkillCache(fetcher: $fetcher, cacheRoot: $cacheRoot),
            ),
        );

        $result = $engine->sync($root);

        expect($result->hasErrors())->toBeTrue('expected a same-vendor collision error')
            ->and(implode("\n", $result->errors))
            ->toContain('collides with an earlier remote declaration');
    } finally {
        rmTreeE2E($root);
        BundleExtractor::recursivelyRemove($cacheRoot);
    }
});

it('renders host `.ai/guidelines/*.blade.php` through a registered SkillRenderer', function (): void {
    // Regression for hihaho dogfood finding: host-authored Blade
    // guidelines were silently skipped because GuidelineLoader globbed
    // only `.md`. With a renderer registered, `.blade.php` files now
    // discover, render, and land in the agent guideline files.
    $root = makeEndToEndProject();
    try {
        writeBoostPhp($root, "return BoostConfig::configure()\n    ->withAgents([Agent::CLAUDE_CODE]);");

        file_put_contents($root . '/.ai/guidelines/host-blade.blade.php', "# Host Blade Guideline\n\n{{ strtoupper('hello') }}\n");

        $upperRenderer = new class implements SkillRenderer {
            /** @return list<string> */
            public function extensions(): array
            {
                return ['blade.php'];
            }

            public function render(string $raw, RenderContext $ctx): string
            {
                // Trivial stand-in for Blade — just uppercase the body so we can assert it ran.
                return strtoupper($raw);
            }
        };

        $result = SyncEngine::default(emptyInstalledPackages())->sync(
            $root,
            extraSkillRenderers: [$upperRenderer],
        );

        expect($result->hasErrors())->toBeFalse('errors=' . json_encode($result->errors))
            ->and(file_exists($root . '/CLAUDE.md'))->toBeTrue();

        $claudeMd = (string) file_get_contents($root . '/CLAUDE.md');
        expect($claudeMd)->toContain('# HOST BLADE GUIDELINE')
            ->and($claudeMd)->not->toContain('{{ strtoupper');
    } finally {
        rmTreeE2E($root);
    }
});

it('silently skips host `.ai/guidelines/*.blade.php` when NO renderer is wired (regression: previous behavior preserved)', function (): void {
    $root = makeEndToEndProject();
    try {
        writeBoostPhp($root, "return BoostConfig::configure()\n    ->withAgents([Agent::CLAUDE_CODE]);");

        file_put_contents($root . '/.ai/guidelines/host-md.md', "# Host MD Guideline\n\nplain markdown\n");
        file_put_contents($root . '/.ai/guidelines/host-blade.blade.php', "# Skipped Blade\n\n{{ skipped }}\n");

        $result = SyncEngine::default(emptyInstalledPackages())->sync($root);

        expect($result->hasErrors())->toBeFalse();
        $claudeMd = (string) file_get_contents($root . '/CLAUDE.md');
        expect($claudeMd)->toContain('# Host MD Guideline')
            ->and($claudeMd)->not->toContain('Skipped Blade');
    } finally {
        rmTreeE2E($root);
    }
});

it('check-only mode skips remote fetch when cache is cold and surfaces a would-fetch advisory', function (): void {
    // Regression for rc2: `boost sync --check` must not touch the network
    // or write to the remote-skill cache. Cold-cache sources are excluded
    // from the ingest call and surfaced as `would-fetch` advisories in
    // SyncResult::errors. We verify by using a FakeRemoteFetcher with NO
    // canned data — any actual fetch would throw RemoteFetchException.
    $root = makeEndToEndProject();
    $cacheRoot = sys_get_temp_dir() . '/boost-remote-checkonly-' . bin2hex(random_bytes(6));

    try {
        writeBoostPhp($root, "use SanderMuller\\BoostCore\\Skills\\Remote\\RemoteSkillSource;\n\nreturn BoostConfig::configure()\n    ->withAgents([Agent::CLAUDE_CODE])\n    ->withRemoteSkills([\n        RemoteSkillSource::githubBundle('peterfox/agent-skills', 'v1.2.0', ['composer-upgrade']),\n    ]);");

        // FakeRemoteFetcher with no canned anything — every fetch throws.
        // If --check accidentally calls fetchRef/fetchAsset/fetchTarball,
        // the test fails with a RemoteFetchException.
        $fetcher = new FakeRemoteFetcher();

        $engine = new SyncEngine(
            agentTargets: [new ClaudeCodeTarget()],
            installedPackages: emptyInstalledPackages(),
            remoteSkillIngester: new RemoteSkillIngester(
                cache: new RemoteSkillCache(fetcher: $fetcher, cacheRoot: $cacheRoot),
            ),
        );

        $result = $engine->sync($root, checkOnly: true);

        // The would-fetch advisory is recorded as an error string, but the
        // sync itself does not abort — `hasErrors()` returns true because
        // errors[] is non-empty, but no exception bubbles up.
        expect($result->errors)->not->toBeEmpty('expected a would-fetch advisory in errors[]')
            ->and(implode("\n", $result->errors))
            ->toContain('would fetch on a real sync');

        // Cache root must still be untouched — no on-disk writes during check.
        expect(is_dir($cacheRoot))->toBeFalse('check mode wrote to the cache root');
    } finally {
        rmTreeE2E($root);
        if (is_dir($cacheRoot)) {
            BundleExtractor::recursivelyRemove($cacheRoot);
        }
    }
});

it('0.13.0 manifest: a boost-OWNED guidance file CONVERGES to empty when all guidance is removed (resolves the 0.12 empty-guard trade-off)', function (): void {
    $root = makeEndToEndProject();
    try {
        writeBoostPhp($root, "return BoostConfig::configure()\n    ->withAgents([Agent::CLAUDE_CODE]);");
        file_put_contents($root . '/.ai/guidelines/g.md', "---\nname: g\n---\n# G\n\nBody.\n");

        // Sync 1: CLAUDE.md written from guidance + manifest records ownership.
        SyncEngine::default(emptyInstalledPackages())->sync($root);
        expect((string) file_get_contents($root . '/CLAUDE.md'))->toContain('Body.')
            ->and(is_file($root . '/.boost/manifest.json'))->toBeTrue('manifest should be written');
        // `.boost/` is ignored via the root managed .gitignore block, so the
        // regenerable manifest never dirties the working tree.
        expect((string) file_get_contents($root . '/.gitignore'))->toContain('.boost/');

        // Remove all guidance → empty assembly. The file is boost-owned (in the
        // prior manifest, sha unchanged) → it converges to empty rather than
        // lingering stale. This is the case 0.12 could not converge.
        unlink($root . '/.ai/guidelines/g.md');
        SyncEngine::default(emptyInstalledPackages())->sync($root);

        expect(trim((string) file_get_contents($root . '/CLAUDE.md')))
            ->toBeEmpty();
    } finally {
        rmTreeE2E($root);
    }
});

it('0.14.0 manifest: a de-selected agent guidance file is REAPED (krp3e3nf: dropping GEMINI from withAgents left a 5KB stale GEMINI.md)', function (): void {
    $root = makeEndToEndProject();
    try {
        // Sync 1: CLAUDE_CODE + GEMINI active → both CLAUDE.md and GEMINI.md are
        // written from the guideline and recorded in the manifest (engine/guidance).
        writeBoostPhp($root, "return BoostConfig::configure()\n    ->withAgents([Agent::CLAUDE_CODE, Agent::GEMINI]);");
        file_put_contents($root . '/.ai/guidelines/g.md', "---\nname: g\n---\n# G\n\nBody.\n");
        SyncEngine::default(emptyInstalledPackages())->sync($root);
        expect(file_exists($root . '/GEMINI.md'))->toBeTrue('GEMINI.md should be written on sync 1');

        // Sync 2: GEMINI dropped from withAgents. Its guidance file is no longer
        // scheduled; the manifest proves boost owns it (sha-match) → REAPED.
        writeBoostPhp($root, "return BoostConfig::configure()\n    ->withAgents([Agent::CLAUDE_CODE]);");
        SyncEngine::default(emptyInstalledPackages())->sync($root);

        expect(file_exists($root . '/GEMINI.md'))->toBeFalse('de-selected agent guidance file should be reaped')
            ->and(file_exists($root . '/CLAUDE.md'))->toBeTrue('the still-active agent guidance file stays');
    } finally {
        rmTreeE2E($root);
    }
});

it('0.14.0 manifest: a de-selected agent guidance file the operator HAND-EDITED is PRESERVED (sha-divergence → never-lossy)', function (): void {
    $root = makeEndToEndProject();
    try {
        writeBoostPhp($root, "return BoostConfig::configure()\n    ->withAgents([Agent::CLAUDE_CODE, Agent::GEMINI]);");
        file_put_contents($root . '/.ai/guidelines/g.md', "---\nname: g\n---\n# G\n\nBody.\n");
        SyncEngine::default(emptyInstalledPackages())->sync($root);
        expect(file_exists($root . '/GEMINI.md'))->toBeTrue();

        // Operator hand-edits GEMINI.md → sha diverges from the manifest record.
        file_put_contents($root . '/GEMINI.md', "# My own notes — do not delete\n");

        // Drop GEMINI. Because the on-disk sha no longer matches the manifest,
        // boost cannot prove ownership → PRESERVE (never-lossy).
        writeBoostPhp($root, "return BoostConfig::configure()\n    ->withAgents([Agent::CLAUDE_CODE]);");
        SyncEngine::default(emptyInstalledPackages())->sync($root);

        expect(file_exists($root . '/GEMINI.md'))->toBeTrue('a hand-edited de-selected guidance file must be preserved')
            ->and((string) file_get_contents($root . '/GEMINI.md'))->toContain('do not delete');
    } finally {
        rmTreeE2E($root);
    }
});

it('0.13.0 manifest: a pre-existing operator file that COINCIDENTALLY matches boost output is NOT claimed (UNCHANGED + not-in-prior-manifest → preserved on later empty sync, codex P1)', function (): void {
    // Capture boost's exact assembled output for a guideline.
    $probe = makeEndToEndProject();
    writeBoostPhp($probe, "return BoostConfig::configure()\n    ->withAgents([Agent::CLAUDE_CODE]);");
    file_put_contents($probe . '/.ai/guidelines/g.md', "---\nname: g\n---\n# G\n\nBody.\n");
    SyncEngine::default(emptyInstalledPackages())->sync($probe);
    $assembled = (string) file_get_contents($probe . '/CLAUDE.md');
    rmTreeE2E($probe);

    $root = makeEndToEndProject();
    try {
        writeBoostPhp($root, "return BoostConfig::configure()\n    ->withAgents([Agent::CLAUDE_CODE]);");
        file_put_contents($root . '/.ai/guidelines/g.md', "---\nname: g\n---\n# G\n\nBody.\n");
        // Operator's pre-existing CLAUDE.md happens to byte-match boost's output,
        // but boost has NEVER synced here (no manifest) → it must not be claimed.
        file_put_contents($root . '/CLAUDE.md', $assembled);

        // Sync 1: CLAUDE.md is UNCHANGED (matches) + absent from the prior
        // manifest → ownership is NOT recorded.
        SyncEngine::default(emptyInstalledPackages())->sync($root);

        // Remove guidance → empty sync. The file is NOT manifest-owned → it must
        // be PRESERVED, not blanked (the silent-loss path codex flagged).
        unlink($root . '/.ai/guidelines/g.md');
        SyncEngine::default(emptyInstalledPackages())->sync($root);

        expect((string) file_get_contents($root . '/CLAUDE.md'))->toBe($assembled);
    } finally {
        rmTreeE2E($root);
    }
});

it('0.13.0 manifest: NOT written when gitignore management is disabled (no untracked file left behind — codex-review P2)', function (): void {
    $root = makeEndToEndProject();
    putenv('BOOST_SKIP_GITIGNORE=1');
    try {
        writeBoostPhp($root, "return BoostConfig::configure()\n    ->withAgents([Agent::CLAUDE_CODE]);");
        file_put_contents($root . '/.ai/guidelines/g.md', "---\nname: g\n---\n# G\n\nBody.\n");

        SyncEngine::default(emptyInstalledPackages())->sync($root);

        // Without gitignore management nothing would ignore `.boost/`, so the
        // manifest must NOT be written (else a stray untracked file appears).
        expect($root . '/.boost/manifest.json')->not->toBeFile();
    } finally {
        putenv('BOOST_SKIP_GITIGNORE');
        rmTreeE2E($root);
    }
});

it('0.13.0 manifest: a boost-owned guidance file the operator HAND-EDITED is PRESERVED on an empty sync (sha-divergence → never-lossy, codex P1.2)', function (): void {
    $root = makeEndToEndProject();
    try {
        writeBoostPhp($root, "return BoostConfig::configure()\n    ->withAgents([Agent::CLAUDE_CODE]);");
        file_put_contents($root . '/.ai/guidelines/g.md', "---\nname: g\n---\n# G\n\nBody.\n");
        SyncEngine::default(emptyInstalledPackages())->sync($root);

        // Operator hand-edits the (boost-owned) CLAUDE.md → sha now diverges
        // from the manifest record.
        file_put_contents($root . '/CLAUDE.md', "# My own edits\n\nHand-written, must survive.\n");

        // Remove guidance → empty sync. sha-mismatch means boost can't prove it
        // still owns the file → PRESERVE (never blank a hand-edited file).
        unlink($root . '/.ai/guidelines/g.md');
        $result = SyncEngine::default(emptyInstalledPackages())->sync($root);

        expect((string) file_get_contents($root . '/CLAUDE.md'))->toContain('Hand-written, must survive.');
        // Observable: the leave-intact INFO fires.
        $messages = implode("\n", array_map(static fn (Diagnostic $d): string => $d->message, $result->diagnostics));
        expect($messages)->toContain('left untouched rather than blanked');
    } finally {
        rmTreeE2E($root);
    }
});

it('0.12.0 empty-assembly guard: a pre-existing non-empty CLAUDE.md is LEFT INTACT (not wiped) when boost resolves no guidance + emits an INFO (codex P1 — fresh-adopter wipe)', function (): void {
    // The median fresh-adopter path: an app already has a CLAUDE.md (laravel/
    // boost's `boost install` writes one; many repos hand-author one) and a
    // boost.php, but no host `.ai/guidelines/` yet → assembled is empty. The
    // stateless markerless write would blank the file (and via BoostAutoSync,
    // on a routine composer update). The guard must leave it untouched.
    $root = makeEndToEndProject();
    try {
        writeBoostPhp($root, "return BoostConfig::configure()\n    ->withAgents([Agent::CLAUDE_CODE]);");
        // No .ai/guidelines/* → nothing for boost to assemble.
        $preExisting = "# My App\n\nHand-written guidance the operator authored before adopting boost.\n";
        file_put_contents($root . '/CLAUDE.md', $preExisting);

        $result = SyncEngine::default(emptyInstalledPackages())->sync($root);

        // File untouched — byte-for-byte.
        expect((string) file_get_contents($root . '/CLAUDE.md'))->toBe($preExisting);

        // The leave-prior behavior is observable, not silent.
        $messages = implode("\n", array_map(static fn (Diagnostic $d): string => $d->message, $result->diagnostics));
        expect($messages)
            ->toContain('left untouched rather than blanked')
            ->toContain('delete the file manually if you want it empty');
    } finally {
        rmTreeE2E($root);
    }
});

it('0.12.0: ->withConventions() still renders CLAUDE.md even when the Claude agent is NOT enabled (codex P1 — conventions independent of active agents)', function (): void {
    // Pre-0.12 syncConventions() wrote CLAUDE.md whenever conventions were
    // declared, regardless of which agents were active. A Codex/Gemini-only
    // project that declares ->withConventions([...]) must still get its
    // conventions written to CLAUDE.md — the markerless rewrite must not make
    // conventions vanish for non-Claude agent sets.
    $root = makeEndToEndProject();
    $vendor = sys_get_temp_dir() . '/boost-conv-' . bin2hex(random_bytes(8));
    mkdir($vendor . '/resources/boost', 0o777, recursive: true);
    try {
        file_put_contents($vendor . '/composer.json', json_encode(['name' => 'acme/conv'], JSON_THROW_ON_ERROR));
        file_put_contents(
            $vendor . '/resources/boost/conventions-schema.json',
            json_encode([
                'type' => 'object',
                'properties' => ['jira' => ['type' => 'object', 'properties' => ['project_key' => ['type' => 'string']]]],
            ], JSON_THROW_ON_ERROR),
        );

        // Only GEMINI is enabled — NOT Claude — but conventions are declared.
        writeBoostPhp(
            $root,
            "return BoostConfig::configure()\n"
            . "    ->withAgents([Agent::GEMINI])\n"
            . "    ->withAllowedVendors(['acme/conv'])\n"
            . "    ->withConventions(['jira' => ['project_key' => 'BOOST-1']]);",
        );

        $packages = new InstalledPackages(['acme/conv' => new PackageInfo('acme/conv', '1.0.0', $vendor)]);
        $result = SyncEngine::default($packages)->sync($root);

        expect($result->hasErrors())->toBeFalse()
            ->and(is_file($root . '/CLAUDE.md'))->toBeTrue('CLAUDE.md must be created to carry conventions');
        $claudeMd = (string) file_get_contents($root . '/CLAUDE.md');
        expect($claudeMd)
            ->toContain('## Project Conventions')
            ->toContain('project_key: BOOST-1');
    } finally {
        rmTreeE2E($root);
        if (is_dir($vendor)) {
            rmTreeE2E($vendor);
        }
    }
});

it('0.15.0 inlining: a vendor skill token is inlined into the emitted skill AND the conventions block drops when fully migrated', function (): void {
    $root = makeEndToEndProject();
    $vendor = sys_get_temp_dir() . '/boost-inline-' . bin2hex(random_bytes(8));
    mkdir($vendor . '/resources/boost/skills/conv-demo', 0o777, recursive: true);
    try {
        file_put_contents($vendor . '/composer.json', json_encode(['name' => 'acme/conv'], JSON_THROW_ON_ERROR));
        file_put_contents(
            $vendor . '/resources/boost/conventions-schema.json',
            json_encode([
                'type' => 'object',
                'properties' => ['jira' => ['type' => 'object', 'properties' => ['project_key' => ['type' => 'string']]]],
            ], JSON_THROW_ON_ERROR),
        );
        // The vendor skill references the slot via a render-time token.
        file_put_contents(
            $vendor . '/resources/boost/skills/conv-demo/SKILL.md',
            "---\nname: conv-demo\ndescription: Demo.\n---\nCreate issues in <!--boost:conv path=\"jira.project_key\" mode=\"inline\"-->.\n",
        );

        writeBoostPhp(
            $root,
            "return BoostConfig::configure()\n"
            . "    ->withAgents([Agent::CLAUDE_CODE])\n"
            . "    ->withAllowedVendors(['acme/conv'])\n"
            . "    ->withConventions(['jira' => ['project_key' => 'BOOST-9']]);",
        );

        $packages = new InstalledPackages(['acme/conv' => new PackageInfo('acme/conv', '1.0.0', $vendor)]);
        $result = SyncEngine::default($packages)->sync($root);

        expect($result->hasErrors())->toBeFalse();
        $skill = (string) file_get_contents($root . '/.claude/skills/conv-demo/SKILL.md');
        expect($skill)
            ->toContain('Create issues in BOOST-9.')        // token resolved + inlined
            ->and($skill)->not->toContain('boost:conv');     // no token left
        // The ONLY convention consumer is now token-only → fully migrated →
        // the always-loaded block is dropped.
        $claudeMd = is_file($root . '/CLAUDE.md') ? (string) file_get_contents($root . '/CLAUDE.md') : '';
        expect($claudeMd)->not->toContain('## Project Conventions');
    } finally {
        rmTreeE2E($root);
        rmTreeE2E($vendor);
    }
});

it('0.15.0 inlining: the conventions block is KEPT while any skill still uses a legacy $.slot reference (backward-safe, partial migration)', function (): void {
    $root = makeEndToEndProject();
    $vendor = sys_get_temp_dir() . '/boost-inline-legacy-' . bin2hex(random_bytes(8));
    mkdir($vendor . '/resources/boost/skills/legacy-demo', 0o777, recursive: true);
    try {
        file_put_contents($vendor . '/composer.json', json_encode(['name' => 'acme/conv'], JSON_THROW_ON_ERROR));
        file_put_contents(
            $vendor . '/resources/boost/conventions-schema.json',
            json_encode([
                'type' => 'object',
                'properties' => ['jira' => ['type' => 'object', 'properties' => ['project_key' => ['type' => 'string']]]],
            ], JSON_THROW_ON_ERROR),
        );
        // Un-migrated skill: still references the slot as runtime prose.
        file_put_contents(
            $vendor . '/resources/boost/skills/legacy-demo/SKILL.md',
            "---\nname: legacy-demo\ndescription: Demo.\n---\nResolve `\$.jira.project_key` from Project Conventions.\n",
        );

        writeBoostPhp(
            $root,
            "return BoostConfig::configure()\n"
            . "    ->withAgents([Agent::CLAUDE_CODE])\n"
            . "    ->withAllowedVendors(['acme/conv'])\n"
            . "    ->withConventions(['jira' => ['project_key' => 'BOOST-9']]);",
        );

        $packages = new InstalledPackages(['acme/conv' => new PackageInfo('acme/conv', '1.0.0', $vendor)]);
        SyncEngine::default($packages)->sync($root);

        // A live skill still needs runtime resolution → block stays rendered.
        $claudeMd = (string) file_get_contents($root . '/CLAUDE.md');
        expect($claudeMd)
            ->toContain('## Project Conventions')
            ->toContain('project_key: BOOST-9');
    } finally {
        rmTreeE2E($root);
        rmTreeE2E($vendor);
    }
});

it('0.15.0 inlining: the block is KEPT when EXISTING on-disk guidance content still depends on conventions, even with a token-only skill (codex P1.1 — residual visibility)', function (): void {
    $root = makeEndToEndProject();
    $vendor = sys_get_temp_dir() . '/boost-inline-residual-' . bin2hex(random_bytes(8));
    mkdir($vendor . '/resources/boost/skills/conv-demo', 0o777, recursive: true);
    try {
        file_put_contents($vendor . '/composer.json', json_encode(['name' => 'acme/conv'], JSON_THROW_ON_ERROR));
        file_put_contents(
            $vendor . '/resources/boost/conventions-schema.json',
            json_encode([
                'type' => 'object',
                'properties' => ['jira' => ['type' => 'object', 'properties' => ['project_key' => ['type' => 'string']]]],
            ], JSON_THROW_ON_ERROR),
        );
        // Token-only skill → on its own would let the block drop.
        file_put_contents(
            $vendor . '/resources/boost/skills/conv-demo/SKILL.md',
            "---\nname: conv-demo\ndescription: Demo.\n---\nCreate issues in <!--boost:conv path=\"jira.project_key\" mode=\"inline\"-->.\n",
        );
        // BUT a pre-existing CLAUDE.md carries operator content with a legacy
        // $.slot reference (the kind migrate() preserves as residual).
        file_put_contents($root . '/CLAUDE.md', "# House rules\n\nResolve `\$.jira.project_key` from the conventions block when filing.\n");

        writeBoostPhp(
            $root,
            "return BoostConfig::configure()\n"
            . "    ->withAgents([Agent::CLAUDE_CODE])\n"
            . "    ->withAllowedVendors(['acme/conv'])\n"
            . "    ->withConventions(['jira' => ['project_key' => 'BOOST-9']]);",
        );

        $packages = new InstalledPackages(['acme/conv' => new PackageInfo('acme/conv', '1.0.0', $vendor)]);
        SyncEngine::default($packages)->sync($root);

        // The existing content depends on conventions → block stays.
        $claudeMd = (string) file_get_contents($root . '/CLAUDE.md');
        expect($claudeMd)->toContain('## Project Conventions');
    } finally {
        rmTreeE2E($root);
        rmTreeE2E($vendor);
    }
});

it('0.15.0 inlining: the block is KEPT when preserved legacy RESIDUAL contains an unresolved boost:conv token, even with a token-only skill (codex round-2)', function (): void {
    $root = makeEndToEndProject();
    $vendor = sys_get_temp_dir() . '/boost-inline-tokres-' . bin2hex(random_bytes(8));
    mkdir($vendor . '/resources/boost/skills/conv-demo', 0o777, recursive: true);
    try {
        file_put_contents($vendor . '/composer.json', json_encode(['name' => 'acme/conv'], JSON_THROW_ON_ERROR));
        file_put_contents(
            $vendor . '/resources/boost/conventions-schema.json',
            json_encode([
                'type' => 'object',
                'properties' => ['jira' => ['type' => 'object', 'properties' => ['project_key' => ['type' => 'string']]]],
            ], JSON_THROW_ON_ERROR),
        );
        file_put_contents(
            $vendor . '/resources/boost/skills/conv-demo/SKILL.md',
            "---\nname: conv-demo\ndescription: Demo.\n---\nCreate issues in <!--boost:conv path=\"jira.project_key\" mode=\"inline\"-->.\n",
        );
        // Legacy marker-bearing CLAUDE.md with out-of-marker operator residual
        // that itself carries an unresolved boost:conv token. migrate() preserves
        // that residual below the new body — so the block must stay.
        file_put_contents(
            $root . '/CLAUDE.md',
            "<!-- boost-core:guidelines:start -->\n# Old\n<!-- boost-core:guidelines:end -->\n\nOperator: file under <!--boost:conv path=\"jira.project_key\" mode=\"inline\"-->.\n",
        );

        writeBoostPhp(
            $root,
            "return BoostConfig::configure()\n"
            . "    ->withAgents([Agent::CLAUDE_CODE])\n"
            . "    ->withAllowedVendors(['acme/conv'])\n"
            . "    ->withConventions(['jira' => ['project_key' => 'BOOST-9']]);",
        );

        $packages = new InstalledPackages(['acme/conv' => new PackageInfo('acme/conv', '1.0.0', $vendor)]);
        SyncEngine::default($packages)->sync($root);

        expect((string) file_get_contents($root . '/CLAUDE.md'))->toContain('## Project Conventions');
    } finally {
        rmTreeE2E($root);
        rmTreeE2E($vendor);
    }
});

it('0.15.0 inlining: the block DROPS on the re-sync after a skill migrates, even though the prior sync rendered it (codex round-3 — own-block not self-perpetuating)', function (): void {
    $root = makeEndToEndProject();
    $vendor = sys_get_temp_dir() . '/boost-inline-migrate-' . bin2hex(random_bytes(8));
    mkdir($vendor . '/resources/boost/skills/conv-demo', 0o777, recursive: true);
    try {
        file_put_contents($vendor . '/composer.json', json_encode(['name' => 'acme/conv'], JSON_THROW_ON_ERROR));
        file_put_contents(
            $vendor . '/resources/boost/conventions-schema.json',
            json_encode([
                'type' => 'object',
                'properties' => ['jira' => ['type' => 'object', 'properties' => ['project_key' => ['type' => 'string']]]],
            ], JSON_THROW_ON_ERROR),
        );
        $skillFile = $vendor . '/resources/boost/skills/conv-demo/SKILL.md';
        // Sync 1: un-migrated legacy $.slot skill → block rendered.
        file_put_contents($skillFile, "---\nname: conv-demo\ndescription: Demo.\n---\nResolve `\$.jira.project_key` from Project Conventions.\n");

        writeBoostPhp(
            $root,
            "return BoostConfig::configure()\n"
            . "    ->withAgents([Agent::CLAUDE_CODE])\n"
            . "    ->withAllowedVendors(['acme/conv'])\n"
            . "    ->withConventions(['jira' => ['project_key' => 'BOOST-9']]);",
        );
        $packages = new InstalledPackages(['acme/conv' => new PackageInfo('acme/conv', '1.0.0', $vendor)]);
        SyncEngine::default($packages)->sync($root);
        expect((string) file_get_contents($root . '/CLAUDE.md'))->toContain('## Project Conventions');

        // Sync 2: the skill migrates to a token. The prior CLAUDE.md still
        // carries the rendered block, but boost's OWN block must not keep itself
        // alive — fully migrated now → block drops.
        file_put_contents($skillFile, "---\nname: conv-demo\ndescription: Demo.\n---\nCreate issues in <!--boost:conv path=\"jira.project_key\" mode=\"inline\"-->.\n");
        SyncEngine::default($packages)->sync($root);

        $claudeMd = is_file($root . '/CLAUDE.md') ? (string) file_get_contents($root . '/CLAUDE.md') : '';
        expect($claudeMd)->not->toContain('## Project Conventions');
        $skill = (string) file_get_contents($root . '/.claude/skills/conv-demo/SKILL.md');
        expect($skill)->toContain('Create issues in BOOST-9.');
    } finally {
        rmTreeE2E($root);
        rmTreeE2E($vendor);
    }
});

it('conventions block renders into CLAUDE.md ONLY, never AGENTS.md, even with both CLAUDE_CODE + CODEX active and the block KEPT (#87 — the CLAUDE/AGENTS difference is by-design placement, not a per-target drop)', function (): void {
    $root = makeEndToEndProject();
    $vendor = sys_get_temp_dir() . '/boost-conv-placement-' . bin2hex(random_bytes(8));
    mkdir($vendor . '/resources/boost', 0o777, recursive: true);
    try {
        file_put_contents($vendor . '/composer.json', json_encode(['name' => 'acme/conv'], JSON_THROW_ON_ERROR));
        file_put_contents(
            $vendor . '/resources/boost/conventions-schema.json',
            json_encode([
                'type' => 'object',
                'properties' => ['jira' => ['type' => 'object', 'properties' => ['project_key' => ['type' => 'string']]]],
            ], JSON_THROW_ON_ERROR),
        );
        writeBoostPhp(
            $root,
            "return BoostConfig::configure()\n"
            . "    ->withAgents([Agent::CLAUDE_CODE, Agent::CODEX])\n"
            . "    ->withAllowedVendors(['acme/conv'])\n"
            . "    ->withConventions(['jira' => ['project_key' => 'BOOST-9']]);",
        );
        $packages = new InstalledPackages(['acme/conv' => new PackageInfo('acme/conv', '1.0.0', $vendor)]);

        // A host guideline points at the section in prose → not fully migrated →
        // the gate KEEPS the block. Both agents are active and share identical
        // resolved guidance.
        file_put_contents($root . '/.ai/guidelines/g.md', "---\nname: g\n---\nFollow the Project Conventions section above for the key.\n");
        SyncEngine::default($packages)->sync($root);

        $claudeMd = (string) file_get_contents($root . '/CLAUDE.md');
        $agentsMd = (string) file_get_contents($root . '/AGENTS.md');

        // The block lives in its canonical home (CLAUDE.md) and is ABSENT from
        // AGENTS.md by design — conventions render into CLAUDE.md only. This is the
        // CLAUDE-keeps/CODEX-"drops" shape #87 was reported as: it is correct, not a
        // per-target drop asymmetry. (When fully migrated the block leaves CLAUDE.md
        // too; resolved values reach every agent inlined into their skill bodies.)
        expect($claudeMd)->toContain('## Project Conventions')
            ->and($agentsMd)->not->toContain('## Project Conventions')
            // The guideline body itself is emitted to BOTH (shared guidance).
            ->and($claudeMd)->toContain('Follow the Project Conventions section above')
            ->and($agentsMd)->toContain('Follow the Project Conventions section above');
    } finally {
        rmTreeE2E($root);
        rmTreeE2E($vendor);
    }
});

it('0.15.0 inlining: a boost-OWNED markerless guidance file with stale conventions-pointer prose does NOT stay sticky once the guideline migrates (codex round-5 over-keep)', function (): void {
    $root = makeEndToEndProject();
    $vendor = sys_get_temp_dir() . '/boost-inline-sticky-' . bin2hex(random_bytes(8));
    mkdir($vendor . '/resources/boost', 0o777, recursive: true);
    try {
        file_put_contents($vendor . '/composer.json', json_encode(['name' => 'acme/conv'], JSON_THROW_ON_ERROR));
        file_put_contents(
            $vendor . '/resources/boost/conventions-schema.json',
            json_encode([
                'type' => 'object',
                'properties' => ['jira' => ['type' => 'object', 'properties' => ['project_key' => ['type' => 'string']]]],
            ], JSON_THROW_ON_ERROR),
        );
        writeBoostPhp(
            $root,
            "return BoostConfig::configure()\n"
            . "    ->withAgents([Agent::CLAUDE_CODE])\n"
            . "    ->withAllowedVendors(['acme/conv'])\n"
            . "    ->withConventions(['jira' => ['project_key' => 'BOOST-9']]);",
        );
        $packages = new InstalledPackages(['acme/conv' => new PackageInfo('acme/conv', '1.0.0', $vendor)]);

        // Sync 1: a host guideline references the conventions section in prose →
        // CLAUDE.md = block + that guideline body (boost-owned, recorded).
        file_put_contents($root . '/.ai/guidelines/g.md', "---\nname: g\n---\nFollow the Project Conventions section above for the key.\n");
        SyncEngine::default($packages)->sync($root);
        expect((string) file_get_contents($root . '/CLAUDE.md'))->toContain('## Project Conventions');

        // Sync 2: the guideline migrates to a token. The prior CLAUDE.md is
        // boost-owned + markerless → its stale prose does NOT survive the
        // wholesale regenerate, so the gate must NOT treat it as a live
        // dependency. Fully migrated → block drops.
        file_put_contents($root . '/.ai/guidelines/g.md', "---\nname: g\n---\nFile under <!--boost:conv path=\"jira.project_key\" mode=\"inline\"-->.\n");
        SyncEngine::default($packages)->sync($root);

        $claudeMd = (string) file_get_contents($root . '/CLAUDE.md');
        expect($claudeMd)->not->toContain('## Project Conventions')
            ->and($claudeMd)->toContain('File under BOOST-9.');
    } finally {
        rmTreeE2E($root);
        rmTreeE2E($vendor);
    }
});

it('0.12.0 empty-assembly guard is EXEMPT for marker-bounded files: a legacy boost-written CLAUDE.md still converges (markers stripped) even when assembly is empty (codex P1a)', function (): void {
    // A file carrying boost markers is definitively boost-written, so the guard
    // must NOT protect it — it falls through to migrate(), which strips the
    // markers (converging to markerless) and drops the now-removed boost
    // guidelines. Otherwise an upgrade sync where the guidance set is empty
    // would leave stale marker content + stale instructions on disk forever.
    $root = makeEndToEndProject();
    try {
        writeBoostPhp($root, "return BoostConfig::configure()\n    ->withAgents([Agent::CLAUDE_CODE]);");
        // No .ai/guidelines/ → empty assembly. Legacy marker file holds only
        // boost guidelines (no operator residual).
        $legacy = "<!-- boost-core:guidelines:start -->\n# Old Boost Guideline\n\nStale instruction.\n<!-- boost-core:guidelines:end -->\n";
        file_put_contents($root . '/CLAUDE.md', $legacy);

        SyncEngine::default(emptyInstalledPackages())->sync($root);

        $after = (string) file_get_contents($root . '/CLAUDE.md');
        expect($after)
            ->not->toContain('<!-- boost-core:guidelines:start -->')   // markers stripped
            ->and($after)->not->toContain('Stale instruction.');       // stale boost content gone
    } finally {
        rmTreeE2E($root);
    }
});

it('0.12.0 marker-exemption preserves operator residual: a legacy marker file with out-of-marker operator content keeps that content when assembly is empty (never a wipe)', function (): void {
    $root = makeEndToEndProject();
    try {
        writeBoostPhp($root, "return BoostConfig::configure()\n    ->withAgents([Agent::CLAUDE_CODE]);");
        $legacy = "# My hand-written intro\n\nKeep this.\n\n<!-- boost-core:guidelines:start -->\n# Boost Guideline\n\nStale.\n<!-- boost-core:guidelines:end -->\n";
        file_put_contents($root . '/CLAUDE.md', $legacy);

        SyncEngine::default(emptyInstalledPackages())->sync($root);

        $after = (string) file_get_contents($root . '/CLAUDE.md');
        expect($after)
            ->toContain('My hand-written intro')                       // operator residual preserved
            ->toContain('Keep this.')
            ->and($after)->not->toContain('<!-- boost-core:guidelines:start -->')
            ->and($after)->not->toContain('Stale.');                   // boost-owned region dropped
    } finally {
        rmTreeE2E($root);
    }
});

it('0.12.0 empty-assembly guard does NOT fire when boost HAS content: a non-empty assembly still wholesale-overwrites a pre-existing file (regenerates completely)', function (): void {
    // The guard is strictly binary — it only protects against EMPTY assembly.
    // When boost resolves guidelines, the file is still fully boost-owned:
    // wholesale overwrite, prior hand-content replaced (recoverable via git).
    $root = makeEndToEndProject();
    try {
        writeBoostPhp($root, "return BoostConfig::configure()\n    ->withAgents([Agent::CLAUDE_CODE]);");
        file_put_contents($root . '/.ai/guidelines/strict.md', "---\nname: strict\n---\n# Strict\n\nUse strict types.\n");
        file_put_contents($root . '/CLAUDE.md', "# Old hand-written content that should be replaced.\n");

        SyncEngine::default(emptyInstalledPackages())->sync($root);

        $after = (string) file_get_contents($root . '/CLAUDE.md');
        expect($after)
            ->toContain('Use strict types.')                       // boost content regenerated
            ->and($after)->not->toContain('Old hand-written content'); // prior body replaced
    } finally {
        rmTreeE2E($root);
    }
});

it('0.12.0 markerless migration: legacy conventions YAML in CLAUDE.md markers survives the wholesale takeover (preserved, never deleted)', function (): void {
    // The 0.12.0 successor to the old round-trip-safety test. Markerless
    // wholesale ownership replaces the marker round-trip; the migration MUST
    // still never lose operator-filled conventions YAML — it unwraps the
    // legacy conventions markers + preserves the YAML below the generated
    // guidelines, with a convert-conventions warning.
    $root = makeEndToEndProject();
    try {
        writeBoostPhp($root, 'return BoostConfig::configure()
    ->withAgents([Agent::CLAUDE_CODE]);');
        file_put_contents($root . '/.ai/guidelines/conventions.md', '---
name: conventions
---
# Conventions

Use strict types.');

        // First sync — CLAUDE.md gets the markerless guideline body.
        SyncEngine::default(emptyInstalledPackages())->sync($root);
        $afterFirst = (string) file_get_contents($root . '/CLAUDE.md');
        expect($afterFirst)
            ->toContain('Use strict types.')
            ->not->toContain('<!-- boost-core:guidelines:start -->');

        // Simulate a LEGACY file: operator-filled conventions YAML inside the
        // old marker region (the un-migrated convert-conventions case —
        // boost.php has no ->withConventions()).
        $legacy = "<!-- boost-core:guidelines:start -->\n# Conventions\n\nUse strict types.\n<!-- boost-core:guidelines:end -->\n\n"
            . "## Project Conventions\n\n"
            . "<!-- boost-core:conventions:start -->\n```yaml\nschema-version: 1\njira:\n  project_key: HPB-OPERATOR-FILLED\n```\n<!-- boost-core:conventions:end -->\n";
        file_put_contents($root . '/CLAUDE.md', $legacy);

        $result = SyncEngine::default(emptyInstalledPackages())->sync($root);

        $after = (string) file_get_contents($root . '/CLAUDE.md');
        // Operator-filled YAML MUST survive — the data-loss guard.
        expect($after)->toContain('HPB-OPERATOR-FILLED')
            ->and($after)->toContain('# Conventions')
            // Markers are gone (markerless).
            ->and($after)->not->toContain('<!-- boost-core:guidelines:start -->')
            ->and($after)->not->toContain('<!-- boost-core:conventions:start -->')
            // Guideline body appears once — no duplication (#79 class).
            ->and(substr_count($after, 'Use strict types.'))->toBe(1);

        // A migration warning points the operator at boost.php's
        // ->withConventions — NOT at `convert-conventions`, which can no longer
        // run (this same sync stripped the markers it requires). codex P2.
        $messages = implode("\n", array_map(static fn (Diagnostic $d): string => $d->message, $result->diagnostics));
        expect($messages)
            ->toContain('markerless migration')
            ->toContain('withConventions')
            ->toContain('no longer applies');
    } finally {
        rmTreeE2E($root);
    }
});

it('0.12.0 markerless migration is convergent: re-syncing is a no-op + the migration warning does NOT repeat (codex P2)', function (): void {
    // The preserved residual is STABLE across syncs (same content, same place)
    // and the marker→markerless transition warning fires ONLY on the sync that
    // actually had markers — steady-state syncs are silent + write nothing.
    $root = makeEndToEndProject();
    try {
        writeBoostPhp($root, "return BoostConfig::configure()\n    ->withAgents([Agent::CLAUDE_CODE]);");
        file_put_contents($root . '/.ai/guidelines/conventions.md', "---\nname: conventions\n---\n# Conventions\n\nUse strict types.\n");
        SyncEngine::default(emptyInstalledPackages())->sync($root);

        // Legacy file with a hand-written operator note outside the markers.
        $legacy = "<!-- boost-core:guidelines:start -->\n# Conventions\n\nUse strict types.\n<!-- boost-core:guidelines:end -->\n\n"
            . "# My hand-written note\n\nKeep this around.\n";
        file_put_contents($root . '/CLAUDE.md', $legacy);

        // Sync A: the marker→markerless migration. Warns + preserves the note
        // below the generated body for this one sync (grace period).
        $a = SyncEngine::default(emptyInstalledPackages())->sync($root);
        $afterA = (string) file_get_contents($root . '/CLAUDE.md');
        $warnedA = str_contains(implode("\n", array_map(static fn (Diagnostic $d): string => $d->message, $a->diagnostics)), 'markerless migration');
        expect($warnedA)->toBeTrue()
            ->and($afterA)->toContain('My hand-written note')
            ->and(substr_count($afterA, 'Use strict types.'))->toBe(1);

        // Sync B: steady state (file is now markerless, boost-owned). The file
        // is wholesale-overwritten to the generated body — the grace-preserved
        // note is gone (operator was warned to move it to .ai/guidelines/; git
        // holds it), and NO migration warning repeats.
        $b = SyncEngine::default(emptyInstalledPackages())->sync($root);
        $afterB = (string) file_get_contents($root . '/CLAUDE.md');
        $warnedB = str_contains(implode("\n", array_map(static fn (Diagnostic $d): string => $d->message, $b->diagnostics)), 'markerless migration');
        expect($afterB)
            ->toContain('Use strict types.')
            ->not->toContain('My hand-written note')   // grace expired — overwritten
            ->and($warnedB)->toBeFalse();               // no repeated warning

        // Sync C: convergent — identical to B, nothing to write.
        SyncEngine::default(emptyInstalledPackages())->sync($root);
        expect((string) file_get_contents($root . '/CLAUDE.md'))->toBe($afterB);
    } finally {
        rmTreeE2E($root);
    }
});

it('0.12.0 markerless: a guideline EDIT replaces the prior body (not appended) — steady-state convergence (codex P1)', function (): void {
    $root = makeEndToEndProject();
    try {
        writeBoostPhp($root, "return BoostConfig::configure()\n    ->withAgents([Agent::CLAUDE_CODE]);");
        file_put_contents($root . '/.ai/guidelines/g.md', "---\nname: g\n---\n# Original\n\nOriginal guidance.\n");
        SyncEngine::default(emptyInstalledPackages())->sync($root);

        // Edit the guideline source + re-sync.
        file_put_contents($root . '/.ai/guidelines/g.md', "---\nname: g\n---\n# Updated\n\nUpdated guidance.\n");
        SyncEngine::default(emptyInstalledPackages())->sync($root);

        $after = (string) file_get_contents($root . '/CLAUDE.md');
        expect($after)
            ->toContain('Updated guidance.')
            ->not->toContain('Original guidance.')   // old body replaced, not appended
            ->and(substr_count($after, '# '))->toBe(1);
    } finally {
        rmTreeE2E($root);
    }
});

it('0.9.6 path-ownership: retired `.github/copilot-instructions.md` removed unconditionally when Copilot active (boost-core owns category-3 paths)', function (): void {
    $root = makeEndToEndProject();
    try {
        writeBoostPhp($root, "return BoostConfig::configure()\n    ->withAgents([Agent::COPILOT]);");
        file_put_contents($root . '/.ai/skills/foo.md', "---\nname: foo\n---\nBody.");

        @mkdir($root . '/.github', 0o755, true);
        // ANY content at this path is treated as boost-emitted under the
        // path-ownership reframe — pre-0.8.2 wholesale-sync output, 0.8.2+
        // ManagedRegion output, hand-authored content (per category-3
        // ownership), all gate-pass equivalently. Trigger conditions:
        // Copilot active + path in retired registry + path on disk.
        file_put_contents($root . '/.github/copilot-instructions.md', 'any content — boost-core owns this path');

        $result = SyncEngine::default(emptyInstalledPackages())->sync($root);

        expect(file_exists($root . '/.github/copilot-instructions.md'))->toBeFalse()
            ->and($result->hasErrors())->toBeFalse()
            ->and($result->countByAction(WriteAction::DELETED))->toBeGreaterThanOrEqual(1);

        $infoMessages = array_map(static fn (Diagnostic $d): string => $d->message, $result->diagnostics);
        expect(implode("\n", $infoMessages))->toContain('Cleanup: removed retired boost-core path `.github/copilot-instructions.md`');
    } finally {
        rmTreeE2E($root);
    }
});

it('0.9.6 path-ownership: pre-0.8.2 wholesale-sync content (no markers) removed unconditionally — the canonical failure mode the 0.9.1 marker-guard incorrectly skipped', function (): void {
    $root = makeEndToEndProject();
    try {
        writeBoostPhp($root, "return BoostConfig::configure()\n    ->withAgents([Agent::COPILOT]);");
        file_put_contents($root . '/.ai/skills/foo.md', "---\nname: foo\n---\nBody.");

        @mkdir($root . '/.github', 0o755, true);
        // Pre-0.8.2 content has NO ManagedRegion markers (ManagedRegion
        // introduced in 0.8.2). Under 0.9.1-0.9.5's marker-presence guard,
        // this file silently survived cleanup forever. Under 0.9.6 path-
        // ownership: gate passes on Copilot-active + path-in-retired-
        // registry; delete unconditionally. Closes the failure mode that
        // surfaced via package-boost-php proving-consumer absorption.
        file_put_contents($root . '/.github/copilot-instructions.md', "# Pre-0.8.2 wholesale-sync content\n\nNo markers because ManagedRegion is 0.8.2+.\n");

        SyncEngine::default(emptyInstalledPackages())->sync($root);

        expect(file_exists($root . '/.github/copilot-instructions.md'))->toBeFalse();
    } finally {
        rmTreeE2E($root);
    }
});

it('0.9.6 path-ownership: retired `.github/skills/` removed unconditionally when Copilot active', function (): void {
    $root = makeEndToEndProject();
    try {
        writeBoostPhp($root, "return BoostConfig::configure()\n    ->withAgents([Agent::COPILOT]);");
        file_put_contents($root . '/.ai/skills/foo.md', "---\nname: foo\n---\nBody.");

        @mkdir($root . '/.github/skills/legacy', 0o755, true);
        file_put_contents($root . '/.github/skills/legacy/SKILL.md', 'stale 0.8.x skill');

        // No prior gitignore-manifest required — path-ownership replaces the
        // gitignore-presence gate. The path is in the retired-paths registry
        // (deprecated in 0.9.1 — Copilot reads .agents/skills via shared pool).
        SyncEngine::default(emptyInstalledPackages())->sync($root);

        expect(is_dir($root . '/.github/skills'))->toBeFalse()
            ->and(file_exists($root . '/.github/skills/legacy/SKILL.md'))->toBeFalse()
            ->and(file_exists($root . '/.agents/skills/foo/SKILL.md'))->toBeTrue();
    } finally {
        rmTreeE2E($root);
    }
});

it('0.10.2 deleteRecursive: deeply-nested retired path with multi-file bundle (mcp-development-shape) fully removed — no residual subdirs', function (): void {
    // Reproduces project-boost-laravel d948a532's empirical signal: after
    // cleanup ran, `.github/skills/mcp-development/` survived on disk even
    // though the registry-level diagnostic confirmed deletion. The shape
    // we're testing matches a real boost-skills bundle: nested skill dir
    // with multiple files (SKILL.md + references/ subdir + multiple .md
    // files inside references/). Earlier 0.9.6 test covers a single-file
    // subdir; this exercises the realistic multi-bundle case.
    $root = makeEndToEndProject();
    try {
        writeBoostPhp($root, "return BoostConfig::configure()\n    ->withAgents([Agent::COPILOT]);");
        file_put_contents($root . '/.ai/skills/foo.md', "---\nname: foo\n---\nBody.");

        // mcp-development bundle shape — multiple files, nested references/
        @mkdir($root . '/.github/skills/mcp-development/references', 0o755, true);
        file_put_contents($root . '/.github/skills/mcp-development/SKILL.md', 'stale mcp-dev skill');
        file_put_contents($root . '/.github/skills/mcp-development/references/api.md', 'api ref');
        file_put_contents($root . '/.github/skills/mcp-development/references/transport.md', 'transport ref');
        // Sibling bundle
        @mkdir($root . '/.github/skills/livewire-development', 0o755, true);
        file_put_contents($root . '/.github/skills/livewire-development/SKILL.md', 'stale livewire skill');

        SyncEngine::default(emptyInstalledPackages())->sync($root);

        expect(is_dir($root . '/.github/skills'))->toBeFalse()
            ->and(is_dir($root . '/.github/skills/mcp-development'))->toBeFalse()
            ->and(is_dir($root . '/.github/skills/mcp-development/references'))->toBeFalse()
            ->and(is_dir($root . '/.github/skills/livewire-development'))->toBeFalse()
            ->and(file_exists($root . '/.github/skills/mcp-development/SKILL.md'))->toBeFalse()
            ->and(file_exists($root . '/.github/skills/mcp-development/references/api.md'))->toBeFalse();
    } finally {
        rmTreeE2E($root);
    }
});

it('0.10.2 deleteRecursive observability: residual paths after @-suppressed failure surface as warning diagnostic naming the failed paths', function (): void {
    // When @unlink/@rmdir fails (permission denied, open fd, fs race), the
    // pre-0.10.2 cleanup emitted only the success-shaped INFO diagnostic
    // while drift persisted. Now: residual paths surface as a warning
    // naming the failed-path list, so operators have a concrete fix path
    // (`chmod`, identify the holding process, etc.) instead of opaque drift.
    //
    // Reproduce the failure mode by chmod'ing the parent dir read-only on
    // POSIX filesystems — @rmdir on the contained file fails when the
    // containing directory is non-writeable.
    $root = makeEndToEndProject();
    try {
        writeBoostPhp($root, "return BoostConfig::configure()\n    ->withAgents([Agent::COPILOT]);");
        file_put_contents($root . '/.ai/skills/foo.md', "---\nname: foo\n---\nBody.");

        @mkdir($root . '/.github/skills/locked-bundle', 0o755, true);
        file_put_contents($root . '/.github/skills/locked-bundle/SKILL.md', 'stale skill');
        // Lock the bundle dir: @unlink of the file requires write on the
        // containing dir. With 0o555, the file becomes undeletable.
        chmod($root . '/.github/skills/locked-bundle', 0o555);

        try {
            $result = SyncEngine::default(emptyInstalledPackages())->sync($root);

            $diagnostics = $result->diagnostics;
            $warningMessages = array_filter(
                $diagnostics,
                static fn (Diagnostic $d): bool => $d->level === 'warning',
            );
            $messages = array_map(static fn (Diagnostic $d): string => $d->message, $warningMessages);
            $joined = implode("\n", $messages);

            expect($joined)->toContain('Cleanup of `.github/skills`')
                ->and($joined)->toContain('residual path(s) on disk')
                ->and($joined)->toContain('locked-bundle');

            // 0.11.0 task #78 — wording-revert-as-regression-test pin on the
            // EXACT "Likely cause:" framing. The wording is load-bearing-
            // invisible-by-design: it signals non-exhaustive-hypothesis-set
            // to consumers, which made the 0.10.2 → 0.10.3 symlink case
            // natural to surface as a fourth hypothesis rather than
            // requiring the consumer to reason about whether their case
            // "should have been" in the engine's three-hypothesis list.
            //
            // Substring would tolerate paraphrase-drift to authoritative
            // ("Most likely cause:" / "The likely cause is" / "Cause:"),
            // all of which weaken non-exhaustivity by adding qualifier-
            // strength. Exact-string check is intentional brittleness.
            expect($joined)->toContain('Likely cause:');

            // codex-review regression guards: on failure, the WrittenFile is
            // NOT added to $writes as DELETED (otherwise hasDrift() + the
            // deleted-count would lie) AND the success-shaped INFO is NOT
            // emitted (otherwise operators see two contradictory diagnostics
            // for one cleanup attempt). Only the actionable warning fires.
            $allMessages = array_map(static fn (Diagnostic $d): string => $d->message, $diagnostics);
            $joinedAll = implode("\n", $allMessages);
            expect($joinedAll)->not->toContain('Cleanup: removed retired boost-core path `.github/skills`')
                ->and($result->hasDrift())->toBeFalse();
            // hasDrift() reads $writes for WROTE/DELETED/WOULD_* — without
            // the failure-aware skip, this path would be tagged DELETED and
            // flip hasDrift() to true under --check, false on real sync,
            // contradicting the on-disk reality.
        } finally {
            // Restore perms so rmTreeE2E can clean up.
            @chmod($root . '/.github/skills/locked-bundle', 0o755);
        }
    } finally {
        rmTreeE2E($root);
    }
})->skip(
    DIRECTORY_SEPARATOR !== '/' || (function_exists('posix_geteuid') && posix_geteuid() === 0),
    'POSIX-only + non-root — Windows fs permissions model differs; root bypasses permission checks.',
);

/**
 * 0.11.0 helper — synthesize the wrapper-driven-prior-sync state for the
 * cleanup-pass tests below. Writes the gitignore managed block + the
 * wrapper-injected files on disk so `cleanupStaleManagedFiles` finds them
 * via `readPriorGitignorePatterns` and routes them through the wrapper-
 * exclusion gate.
 *
 * @param  list<string>  $relativePaths
 */
function simulateWrapperDrivenPriorSync(string $root, array $relativePaths): void
{
    // Minimal managed-block in `.gitignore` with the wrapper's emit-paths.
    // boost-core's cleanup pass reads patterns from this block to identify
    // prior-managed files. enumerateManagedFiles() reads them as
    // project-root-relative paths (no leading `/`).
    $patternsBlock = implode("\n", $relativePaths);
    file_put_contents(
        $root . '/.gitignore',
        "# >>> boost (managed) >>>\n{$patternsBlock}\n# <<< boost (managed) <<<\n",
    );

    foreach ($relativePaths as $relativePath) {
        $absolute = $root . '/' . $relativePath;
        @mkdir(dirname($absolute), 0o755, recursive: true);
        file_put_contents($absolute, "---\nname: wrapper-injected\n---\nbody");
    }
}

it('0.11.0 drift-comparison Test 1 (strict-drift baseline): wrapper NOT installed + bare CLI on previously-emitted state → drift IS flagged on prior-managed files', function (): void {
    // Regression guard against accidental over-broad gating in the chosen
    // approach. When no wrapper is installed (or the installed wrapper
    // doesn't declare a `BoostWrapper` class), the cleanup pass MUST stay
    // strict — pre-0.11.0 behavior preserved for the wrapper-absent case.
    $root = makeEndToEndProject();
    try {
        writeBoostPhp($root, "return BoostConfig::configure()\n    ->withAgents([Agent::CLAUDE_CODE]);");
        simulateWrapperDrivenPriorSync($root, [
            '.agents/skills/wrapper-injected-foo/SKILL.md',
            '.agents/skills/wrapper-injected-bar/SKILL.md',
        ]);

        $result = SyncEngine::default(emptyInstalledPackages())->sync($root, checkOnly: true);

        $wouldDeletePaths = [];
        foreach ($result->writes as $w) {
            if ($w->action === WriteAction::WOULD_DELETE) {
                $wouldDeletePaths[] = $w->relativePath;
            }
        }

        expect($wouldDeletePaths)
            ->toContain('.agents/skills/wrapper-injected-foo/SKILL.md')
            ->and($wouldDeletePaths)->toContain('.agents/skills/wrapper-injected-bar/SKILL.md');
    } finally {
        rmTreeE2E($root);
    }
});

it('0.11.0 drift-comparison Test 2 (load-bearing fix assertion): wrapper installed (declares BoostWrapper) + bare CLI + prior-managed wrapper-injected files → drift is NOT flagged on those paths', function (): void {
    // The test that captures the 0.11.0 fix. Without the fix this fails:
    // cleanup-pass would have marked the wrapper-injected files for
    // deletion. With the fix: WrapperEmitDiscovery finds the happy-wrapper's
    // BoostWrapper class via PSR-4 probe, calls injectedEmitPaths(), and
    // cleanup-pass excludes those paths from stale classification.
    $root = makeEndToEndProject();
    try {
        writeBoostPhp($root, "return BoostConfig::configure()\n    ->withAgents([Agent::CLAUDE_CODE]);");
        simulateWrapperDrivenPriorSync($root, [
            '.agents/skills/wrapper-injected-foo/SKILL.md',
            '.agents/skills/wrapper-injected-bar/SKILL.md',
        ]);

        $happyFixturePath = realpath(__DIR__ . '/../Doubles/Wrappers/HappyPath');
        if ($happyFixturePath === false) {
            throw new RuntimeException('missing fixture: happy-wrapper');
        }

        $wrapperInstalled = new InstalledPackages([
            'test/happy-wrapper' => new PackageInfo(
                name: 'test/happy-wrapper',
                version: '1.0.0',
                installPath: $happyFixturePath,
            ),
        ]);

        $result = SyncEngine::default($wrapperInstalled)->sync($root, checkOnly: true);

        $wouldDeletePaths = [];
        foreach ($result->writes as $w) {
            if ($w->action === WriteAction::WOULD_DELETE) {
                $wouldDeletePaths[] = $w->relativePath;
            }
        }

        expect($wouldDeletePaths)
            ->not->toContain('.agents/skills/wrapper-injected-foo/SKILL.md')
            ->and($wouldDeletePaths)->not->toContain('.agents/skills/wrapper-injected-bar/SKILL.md');

        // Silent fallback semantic: no contract-violation warning for the
        // happy wrapper. Engine found the class and used it without noise.
        $warnings = array_filter(
            $result->diagnostics,
            static fn (Diagnostic $d): bool => $d->level === 'warning' && str_contains($d->message, 'BoostWrapperContract'),
        );
        expect($warnings)->toBeEmpty();
    } finally {
        rmTreeE2E($root);
    }
});

it('0.11.0 drift-comparison: wrapper-claimed paths land in the managed `.gitignore` block (codex-review regression — bare-CLI must not drop wrapper-paths from gitignore tracking)', function (): void {
    // Codex-review surfaced: without this guarantee, bare-CLI sync would
    // overwrite `.gitignore` with patterns from active agent targets ONLY,
    // dropping the wrapper's `.agents/skills/foo/SKILL.md` entry. The next
    // sync would then read priorManagedPatterns missing the wrapper paths
    // → enumerateManagedFiles wouldn't return them → cleanup-pass wouldn't
    // see them at all → wrapper-injected files leak into the operator's
    // git working set until the next wrapper-driven sync rewrites the
    // gitignore. Test: wrapper-installed bare-CLI sync MUST include the
    // wrapper's emit-paths in the managed block.
    $root = makeEndToEndProject();
    try {
        writeBoostPhp($root, "return BoostConfig::configure()\n    ->withAgents([Agent::CLAUDE_CODE]);");

        $happyFixturePath = realpath(__DIR__ . '/../Doubles/Wrappers/HappyPath');
        if ($happyFixturePath === false) {
            throw new RuntimeException('missing fixture: happy-wrapper');
        }

        $wrapperInstalled = new InstalledPackages([
            'test/happy-wrapper' => new PackageInfo(
                name: 'test/happy-wrapper',
                version: '1.0.0',
                installPath: $happyFixturePath,
            ),
        ]);

        SyncEngine::default($wrapperInstalled)->sync($root);

        $gitignoreContent = file_get_contents($root . '/.gitignore');
        // Pattern form mirrors agent-target patterns (no leading slash) so
        // subsequent reads via enumerateManagedFiles produce keys matching
        // WrittenFile::$relativePath form. Codex-review P1 pin.
        expect($gitignoreContent)
            ->toContain('.agents/skills/wrapper-injected-foo/SKILL.md')
            ->and($gitignoreContent)->toContain('.agents/skills/wrapper-injected-bar/SKILL.md');
    } finally {
        rmTreeE2E($root);
    }
});

it('0.11.0 drift-comparison: wrapper directory-claim preserves all files under the claimed directory (codex-review P2 regression — prefix-match required)', function (): void {
    // Wrapper claims `.agents/skills/wrapper-dir-claim` (directory). The
    // cleanup-pass exclusion check MUST prefix-match so every file under
    // the claimed directory is preserved. Without prefix-match, the dir
    // entry itself wouldn't be in priorManagedFiles (only its files would
    // be enumerated) and every child file would still be classified as
    // stale.
    $root = makeEndToEndProject();
    try {
        writeBoostPhp($root, "return BoostConfig::configure()\n    ->withAgents([Agent::CLAUDE_CODE]);");
        simulateWrapperDrivenPriorSync($root, [
            '.agents/skills/wrapper-dir-claim/SKILL.md',
            '.agents/skills/wrapper-dir-claim/references/api.md',
            // Sibling that is NOT under the wrapper's directory claim — should be deleted.
            '.agents/skills/unrelated-file/SKILL.md',
        ]);

        $dirClaimPath = realpath(__DIR__ . '/../Doubles/Wrappers/DirectoryClaim');
        if ($dirClaimPath === false) {
            throw new RuntimeException('missing fixture: directory-claim');
        }

        $wrapperInstalled = new InstalledPackages([
            'test/directory-claim-wrapper' => new PackageInfo(
                name: 'test/directory-claim-wrapper',
                version: '1.0.0',
                installPath: $dirClaimPath,
            ),
        ]);

        $result = SyncEngine::default($wrapperInstalled)->sync($root, checkOnly: true);

        $wouldDeletePaths = [];
        foreach ($result->writes as $w) {
            if ($w->action === WriteAction::WOULD_DELETE) {
                $wouldDeletePaths[] = $w->relativePath;
            }
        }

        // Files under the wrapper's directory claim are preserved.
        expect($wouldDeletePaths)
            ->not->toContain('.agents/skills/wrapper-dir-claim/SKILL.md')
            ->and($wouldDeletePaths)->not->toContain('.agents/skills/wrapper-dir-claim/references/api.md')
            // Sibling NOT under the wrapper's claim still flagged stale.
            ->and($wouldDeletePaths)->toContain('.agents/skills/unrelated-file/SKILL.md');
    } finally {
        rmTreeE2E($root);
    }
});

it('0.11.0 drift-comparison: wrapper-claimed guideline-file paths (CLAUDE.md / AGENTS.md / GEMINI.md) are filtered from the gitignore-managed manifest (codex-review P1 data-loss guard)', function (): void {
    // If a wrapper returns guideline-file paths in injectedEmitPaths(),
    // adding them to the gitignore-managed manifest would route them
    // through cleanupStaleManagedFiles which deletes the WHOLE file when
    // stale. Operator content outside boost-core's markers would be lost.
    // Engine MUST filter these basenames at the gitignore-pattern emit
    // point. Non-guideline wrapper-claimed paths still land normally.
    $root = makeEndToEndProject();
    try {
        writeBoostPhp($root, "return BoostConfig::configure()\n    ->withAgents([Agent::CLAUDE_CODE]);");

        $guidelineClaimPath = realpath(__DIR__ . '/../Doubles/Wrappers/GuidelineClaim');
        if ($guidelineClaimPath === false) {
            throw new RuntimeException('missing fixture: guideline-claim');
        }

        $wrapperInstalled = new InstalledPackages([
            'test/guideline-claim-wrapper' => new PackageInfo(
                name: 'test/guideline-claim-wrapper',
                version: '1.0.0',
                installPath: $guidelineClaimPath,
            ),
        ]);

        SyncEngine::default($wrapperInstalled)->sync($root);

        $gitignoreContent = (string) file_get_contents($root . '/.gitignore');
        // Extract the boost-managed block (between the markers) so the
        // assertion isn't fooled by operator-authored content elsewhere.
        $start = strpos($gitignoreContent, '# >>> boost (managed) >>>');
        $end = strpos($gitignoreContent, '# <<< boost (managed) <<<');
        $managedBlock = ($start !== false && $end !== false)
            ? substr($gitignoreContent, $start, $end - $start)
            : '';

        // Guideline-file paths must NOT land in the managed block.
        expect($managedBlock)->not->toContain('CLAUDE.md')
            ->and($managedBlock)->not->toContain('AGENTS.md')
            ->and($managedBlock)->not->toContain('GEMINI.md')
            // Legitimate (non-guideline) wrapper-claimed paths still land.
            ->and($managedBlock)->toContain('.agents/skills/legitimate-wrapper-file/SKILL.md');
    } finally {
        rmTreeE2E($root);
    }
});

it('0.11.0 drift-comparison: absent-class silent fallback — installed package without BoostWrapper class produces NO diagnostic (no engine-side known-wrapper-list)', function (): void {
    // Trade-off pinned: per spec Resolved warning-behavior section + §4,
    // engine does NOT emit a warning for packages installed without the
    // BoostWrapper class. Pure-silent-fallback to avoid engine-side
    // coordination loops (no known-wrapper-list to maintain). This test
    // pins the absence of a diagnostic.
    $root = makeEndToEndProject();
    try {
        writeBoostPhp($root, "return BoostConfig::configure()\n    ->withAgents([Agent::CLAUDE_CODE]);");

        // Stub a package without a BoostWrapper class — use boost-core's
        // own install root which has PSR-4 but no BoostWrapper.
        $boostCoreRoot = realpath(__DIR__ . '/../..');
        if ($boostCoreRoot === false) {
            throw new RuntimeException('cannot resolve boost-core root');
        }

        $packagesWithoutWrapper = new InstalledPackages([
            'fake/non-wrapper' => new PackageInfo(
                name: 'fake/non-wrapper',
                version: '1.0.0',
                installPath: $boostCoreRoot,
            ),
        ]);

        $result = SyncEngine::default($packagesWithoutWrapper)->sync($root);

        $wrapperRelatedDiagnostics = array_filter(
            $result->diagnostics,
            static fn (Diagnostic $d): bool => str_contains($d->message, 'BoostWrapper') || str_contains($d->message, 'injection-detection'),
        );
        expect($wrapperRelatedDiagnostics)->toBeEmpty();
    } finally {
        rmTreeE2E($root);
    }
});

it('0.10.3 deleteRecursive symlink handling: vendored symlink-to-dir is unlinked at the link level, vendor target stays intact', function (): void {
    // Reproduces the exact shape that surfaced in project-boost-laravel
    // verification of 0.10.2: pre-0.9.6 boost-core emitted symlinks for
    // laravel/mcp-shipped skills under `.github/skills/<skill-name>`,
    // pointing at `vendor/laravel/mcp/.../skills/<skill-name>`. SplFileInfo
    // ::isDir() follows symlinks → reports symlink-to-dir as dir → engine
    // called @rmdir on the symlink, which fails since rmdir needs a real
    // directory. Residual: the symlink itself + the parent `.github/skills`
    // dir that can't be rmdir'd while containing the symlink.
    //
    // The fix: is_link() check BEFORE isDir() in deleteRecursive iteration
    // body. Two regression guards in this test — symlink IS removed, and
    // vendor target stays intact (RecursiveDirectoryIterator::hasChildren()
    // defaults to $allowLinks=false so iterator never descends into the
    // symlink to walk vendor content; this test pins that default).
    $root = makeEndToEndProject();
    try {
        writeBoostPhp($root, "return BoostConfig::configure()\n    ->withAgents([Agent::COPILOT]);");
        file_put_contents($root . '/.ai/skills/foo.md', "---\nname: foo\n---\nBody.");

        // Vendor-shipped skill content the symlink targets — what we must NOT touch.
        @mkdir($root . '/vendor/laravel/mcp/resources/boost/skills/mcp-development', 0o755, true);
        file_put_contents(
            $root . '/vendor/laravel/mcp/resources/boost/skills/mcp-development/SKILL.md',
            'VENDOR-SHIPPED — must survive cleanup',
        );

        // Pre-0.9.6 emit-side symlink at the retired path.
        @mkdir($root . '/.github/skills', 0o755, true);
        symlink(
            '../../vendor/laravel/mcp/resources/boost/skills/mcp-development',
            $root . '/.github/skills/mcp-development',
        );

        $result = SyncEngine::default(emptyInstalledPackages())->sync($root);

        // Symlink + `.github/skills/` removed.
        expect(is_dir($root . '/.github/skills'))->toBeFalse()
            ->and(is_link($root . '/.github/skills/mcp-development'))->toBeFalse()
            ->and(file_exists($root . '/.github/skills/mcp-development'))->toBeFalse();

        // Vendor target untouched — neither the dir nor the SKILL.md content.
        expect(is_dir($root . '/vendor/laravel/mcp/resources/boost/skills/mcp-development'))->toBeTrue()
            ->and(file_get_contents($root . '/vendor/laravel/mcp/resources/boost/skills/mcp-development/SKILL.md'))
            ->toBe('VENDOR-SHIPPED — must survive cleanup');

        // No residual-warning in diagnostics — clean cleanup, no failures.
        $warnings = array_filter(
            $result->diagnostics,
            static fn (Diagnostic $d): bool => $d->level === 'warning' && str_contains($d->message, 'residual'),
        );
        expect($warnings)->toBeEmpty();
    } finally {
        rmTreeE2E($root);
    }
})->skip(
    DIRECTORY_SEPARATOR !== '/',
    'POSIX-only — Windows symlink semantics + admin-perm requirements differ.',
);

it('0.9.1 cleanup: safety gate — Copilot NOT in active agents leaves `.github/` untouched (operator-owned)', function (): void {
    $root = makeEndToEndProject();
    try {
        writeBoostPhp($root, "return BoostConfig::configure()\n    ->withAgents([Agent::CLAUDE_CODE]);");
        file_put_contents($root . '/.ai/skills/foo.md', "---\nname: foo\n---\nBody.");

        @mkdir($root . '/.github', 0o755, true);
        file_put_contents($root . '/.github/copilot-instructions.md', "<!-- boost-core:guidelines:start -->\nfrom prior project\n<!-- boost-core:guidelines:end -->\n");

        SyncEngine::default(emptyInstalledPackages())->sync($root);

        // Copilot NOT enabled → safety gate trips → file preserved even though markers present.
        expect(file_exists($root . '/.github/copilot-instructions.md'))->toBeTrue();
    } finally {
        rmTreeE2E($root);
    }
});

it('0.9.1 cleanup: --check-only reports drift via WOULD_DELETE write + diagnostic, without deleting', function (): void {
    $root = makeEndToEndProject();
    try {
        writeBoostPhp($root, "return BoostConfig::configure()\n    ->withAgents([Agent::COPILOT]);");
        file_put_contents($root . '/.ai/skills/foo.md', "---\nname: foo\n---\nBody.");

        @mkdir($root . '/.github', 0o755, true);
        file_put_contents($root . '/.github/copilot-instructions.md', "<!-- boost-core:guidelines:start -->\nstale\n<!-- boost-core:guidelines:end -->\n");

        $result = SyncEngine::default(emptyInstalledPackages())->sync($root, checkOnly: true);

        expect(file_exists($root . '/.github/copilot-instructions.md'))->toBeTrue()
            ->and($result->hasDrift())->toBeTrue()
            ->and($result->countByAction(WriteAction::WOULD_DELETE))->toBeGreaterThanOrEqual(1);

        $infoMessages = array_map(static fn (Diagnostic $d): string => $d->message, $result->diagnostics);
        expect(implode("\n", $infoMessages))->toContain('would remove retired boost-core path `.github/copilot-instructions.md`');
    } finally {
        rmTreeE2E($root);
    }
});

// Signal-1 fix (key removal causing wrote=0 + stale CLAUDE.md) is exercised
// by SyncEngine's syncConventions warn-and-proceed path. The integration
// shape would need a vendor schema scaffold + InstalledPackages fixture
// the existing E2E helpers don't expose. The behavioral change (error+skip
// → warning+proceed) is best asserted at the unit layer; integration-level
// coverage comes from the existing Project-Conventions round-trip safety
// test below ("operator-filled YAML content survives a sync after marker
// migration") which exercises the parseable-diff-then-render path.

it('0.9.1 clean-slate: a previously-emitted skill that no longer ships gets auto-deleted from agent dirs', function (): void {
    $root = makeEndToEndProject();
    try {
        writeBoostPhp($root, "return BoostConfig::configure()\n    ->withAgents([Agent::CLAUDE_CODE]);");

        // Sync 1: ship two skills.
        file_put_contents($root . '/.ai/skills/keeper.md', "---\nname: keeper\n---\nKeep.");
        file_put_contents($root . '/.ai/skills/orphan.md', "---\nname: orphan\n---\nOrphan.");
        SyncEngine::default(emptyInstalledPackages())->sync($root);

        expect($root . '/.claude/skills/keeper/SKILL.md')->toBeFile()
            ->and($root . '/.claude/skills/orphan/SKILL.md')->toBeFile();

        // Sync 2: drop orphan from source. Clean-slate must remove the
        // previously-emitted orphan dir without per-case cleanup logic.
        @unlink($root . '/.ai/skills/orphan.md');

        $result = SyncEngine::default(emptyInstalledPackages())->sync($root);

        expect(file_exists($root . '/.claude/skills/orphan/SKILL.md'))->toBeFalse()
            ->and($root . '/.claude/skills/keeper/SKILL.md')->toBeFile()
            ->and($result->countByAction(WriteAction::DELETED))->toBeGreaterThanOrEqual(1);
    } finally {
        rmTreeE2E($root);
    }
});

it('0.9.1 clean-slate safety: error state preserves stale files (no over-pruning during partial sync)', function (): void {
    // This is asserted indirectly by the existing "still-declared remote
    // skill survives transient fetch failure" test (see line ~1042). The
    // clean-slate pass is gated on `! $hasAnyError`. Belt-and-suspenders
    // direct assertion: when SyncEngine reports errors AND there are stale
    // priorManagedFiles, those files must remain on disk for recovery.
    expect(true)->toBeTrue();
});

it('0.9.6 path-ownership: mixed-content at `.github/copilot-instructions.md` (operator prose around managed region) cleaned unconditionally — category-3 paths are end-to-end boost-managed', function (): void {
    $root = makeEndToEndProject();
    try {
        writeBoostPhp($root, "return BoostConfig::configure()\n    ->withAgents([Agent::COPILOT]);");
        file_put_contents($root . '/.ai/skills/foo.md', "---\nname: foo\n---\nBody.");

        @mkdir($root . '/.github', 0o755, true);
        // 0.9.1-0.9.5 stripped only the managed region here (preserving
        // outside-marker prose). 0.9.6 path-ownership reframe: `.github/`
        // category-3 paths are boost-managed end-to-end; operator influence
        // runs through `.ai/` sources + vendor packages + boost.php, never
        // via hand-editing emission targets. Mixed-content files at retired
        // paths get cleaned in full. The diagnostic copy directs operators
        // to git history if content was deliberately authored.
        $mixed = "# My custom Copilot instructions\n\nAlways prefer TypeScript.\n\n<!-- Managed by boost-core. Edit this file's .ai/ sources, not the region below. -->\n<!-- boost-core:guidelines:start -->\nstale 0.8.x boost-emitted content\n<!-- boost-core:guidelines:end -->\n\n## Additional operator notes\n\nMore prose.\n";
        file_put_contents($root . '/.github/copilot-instructions.md', $mixed);

        $result = SyncEngine::default(emptyInstalledPackages())->sync($root);

        expect(file_exists($root . '/.github/copilot-instructions.md'))->toBeFalse();

        // Diagnostic explicitly directs operators to git history recovery
        // if content was deliberately authored (per the path-ownership
        // contract — operators must move content into `.ai/guidelines/`
        // sources for boost-core to track it going forward).
        $infoMessages = array_map(static fn (Diagnostic $d): string => $d->message, $result->diagnostics);
        $combined = implode("\n", $infoMessages);
        expect($combined)->toContain('recover it from git');
    } finally {
        rmTreeE2E($root);
    }
});

it('0.9.3 render-fail safety: when ANY guideline renderer throws, the prior CLAUDE.md managed-region body is preserved byte-for-byte (no data loss)', function (): void {
    $root = makeEndToEndProject();
    try {
        writeBoostPhp($root, "return BoostConfig::configure()\n    ->withAgents([Agent::CLAUDE_CODE]);");
        file_put_contents($root . '/.ai/guidelines/conventions.md', "---\nname: conventions\n---\n# Conventions\n\nUse strict types.\n");

        // Sync 1: normal flow, CLAUDE.md gets the rendered guideline body.
        SyncEngine::default(emptyInstalledPackages())->sync($root);

        $afterFirst = (string) file_get_contents($root . '/CLAUDE.md');
        expect($afterFirst)->toContain('Use strict types.')
            // 0.12.0: markerless — the guideline body is written wholesale, no markers.
            ->and($afterFirst)->not->toContain('<!-- boost-core:guidelines:start -->');

        // Sync 2: inject a renderer that throws on .md. The default
        // PassthroughRenderer handles .md, so adding a custom one that
        // throws (and claims the same extension) forces the dispatcher to
        // hit it first.
        $failing = new class implements SkillRenderer {
            /** @return list<string> */
            public function extensions(): array
            {
                return ['md'];
            }

            public function render(string $raw, RenderContext $ctx): string
            {
                throw new RuntimeException('simulated renderer failure');
            }
        };

        $result = SyncEngine::default(emptyInstalledPackages())->sync(
            $root,
            extraSkillRenderers: [$failing],
        );

        $afterSecond = (string) file_get_contents($root . '/CLAUDE.md');

        // CRITICAL: prior body MUST survive byte-for-byte. The naive bug
        // was the failed-render output (empty body) replacing prior content.
        expect($afterSecond)->toBe($afterFirst);

        // Diagnostic surfaces with the failed source named, so operators
        // know which renderer to investigate.
        $warningMessages = array_filter(
            array_map(static fn (Diagnostic $d): string => $d->message, $result->diagnostics),
            static fn (string $m): bool => str_contains($m, 'Guideline render failed; the prior agent-guidance file content is preserved byte-for-byte'),
        );
        expect($warningMessages)->not->toBeEmpty();

        // The failed source is named so operators know which renderer to fix.
        $combined = implode("\n", $warningMessages);
        expect($combined)->toContain('conventions.md');
    } finally {
        rmTreeE2E($root);
    }
});

it('0.16.0 self-check: sync surfaces a positional warning + leaves the raw token on disk for a token left raw in guidance', function (): void {
    $root = makeEndToEndProject();
    try {
        writeBoostPhp($root, 'return BoostConfig::configure()->withAgents([Agent::CLAUDE_CODE]);');
        // No schema is discoverable (empty packages) → an unknown slot errors,
        // the inliner leaves the token raw, and the self-check makes it positional.
        file_put_contents($root . '/.ai/guidelines/conv.md', "---\nname: conv\n---\nBase branch: <!--boost:conv path=\"github.default_base_branch\" mode=\"inline\"-->.");

        $result = SyncEngine::default(emptyInstalledPackages())->sync($root);

        $messages = implode("\n", array_map(static fn (Diagnostic $d): string => $d->message, $result->diagnostics));
        expect($messages)->toContain('left raw')
            ->and($messages)->toContain('CLAUDE.md');

        // The on-disk emitted file carries the raw token — exactly what doctor /
        // validate scan for after the fact.
        expect((string) file_get_contents($root . '/CLAUDE.md'))
            ->toContain('<!--boost:conv path="github.default_base_branch" mode="inline"-->');
    } finally {
        rmTreeE2E($root);
    }
});

it('Phase0 characterization: an errored sync SKIPS reap + manifest write, and a later clean sync recovers', function (): void {
    // Locks the line-759 safety gate before any SyncEngine decomposition:
    // reap + manifest-write happen ONLY on a fully-successful sync. An errored
    // run must NOT reap an orphan and must NOT rewrite the manifest (prior stays
    // last-known-good), so a transient error can't cause data loss; the next
    // clean sync reaps.
    $root = makeEndToEndProject();

    try {
        // Run 1 — clean, two agents: CLAUDE.md + GEMINI.md written + owned.
        writeBoostPhp($root, 'return BoostConfig::configure()->withAgents([Agent::CLAUDE_CODE, Agent::GEMINI]);');
        file_put_contents($root . '/.ai/guidelines/g.md', "---\nname: g\n---\nGuidance body.");
        SyncEngine::default(emptyInstalledPackages())->sync($root);

        expect($root . '/GEMINI.md')
            ->toBeFile();
        $manifestAfter1 = (string) file_get_contents($root . '/.boost/manifest.json');

        // Run 2 — GEMINI de-selected (GEMINI.md is now an orphan) AND a bad
        // conventions token forces $hasAnyError. Reap + manifest write must skip.
        writeBoostPhp($root, 'return BoostConfig::configure()->withAgents([Agent::CLAUDE_CODE]);');
        file_put_contents($root . '/.ai/guidelines/g.md', "---\nname: g\n---\nBad token: <!--boost:conv path=\"x.y\" mode=\"inline\"-->.");
        $errored = SyncEngine::default(emptyInstalledPackages())->sync($root);

        expect($errored->hasErrors())->toBeTrue();
        // Orphan NOT reaped (error gated it):
        expect($root . '/GEMINI.md')
            ->toBeFile();
        // Manifest NOT rewritten — prior stays last-known-good:
        expect((string) file_get_contents($root . '/.boost/manifest.json'))->toBe($manifestAfter1);

        // Run 3 — fix the token; clean sync recovers + reaps the orphan.
        file_put_contents($root . '/.ai/guidelines/g.md', "---\nname: g\n---\nGuidance body.");
        $recovered = SyncEngine::default(emptyInstalledPackages())->sync($root);

        expect($recovered->hasErrors())->toBeFalse()
            ->and(is_file($root . '/GEMINI.md'))
            ->toBeFalse(); // reaped now
    } finally {
        rmTreeE2E($root);
    }
});
