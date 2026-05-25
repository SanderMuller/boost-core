<?php declare(strict_types=1);

use SanderMuller\BoostCore\Agents\ClaudeCodeTarget;
use SanderMuller\BoostCore\Contracts\SkillRenderer;
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
