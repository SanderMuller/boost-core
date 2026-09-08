<?php declare(strict_types=1);

use SanderMuller\BoostCore\Conventions\Diagnostic;
use SanderMuller\BoostCore\Sync\InstalledPackages;
use SanderMuller\BoostCore\Sync\PackageInfo;
use SanderMuller\BoostCore\Sync\SyncEngine;
use SanderMuller\BoostCore\Sync\WriteAction;
use SanderMuller\BoostCore\Sync\WrittenFile;

/**
 * End-to-end ownership contract for emitted subagents.
 *
 * The invariant under test is the one the whole layout exists to protect: boost
 * owns `.claude/agents/boost/` outright and nothing above it. A hand-written
 * definition at the root survives every sync and every reap, while an emitted
 * one is recorded, rewritten and eventually reaped like any other boost file.
 */
function subagentSyncRoot(string $configBody = "BoostConfig::configure()->withAgents([Agent::CLAUDE_CODE])->withAllowedVendors(['acme/pack'])"): string
{
    $root = sys_get_temp_dir() . '/boost-subagent-sync-' . bin2hex(random_bytes(8));
    mkdir($root . '/.ai/skills', 0o755, recursive: true);
    file_put_contents(
        $root . '/boost.php',
        "<?php\n\ndeclare(strict_types=1);\n\nuse SanderMuller\\BoostCore\\Config\\BoostConfig;\nuse SanderMuller\\BoostCore\\Enums\\Agent;\n\nreturn {$configBody};\n",
    );

    return $root;
}

function subagentVendorDir(string ...$names): string
{
    $dir = sys_get_temp_dir() . '/boost-subagent-vendor-' . bin2hex(random_bytes(8));
    mkdir($dir . '/resources/boost/subagents', 0o755, recursive: true);
    file_put_contents($dir . '/composer.json', json_encode(['name' => 'acme/pack'], JSON_THROW_ON_ERROR));
    foreach ($names as $name) {
        file_put_contents(
            $dir . '/resources/boost/subagents/' . $name . '.md',
            "---\nname: {$name}\ndescription: Fixture.\n---\nBody for {$name}.\n",
        );
    }

    return $dir;
}

function subagentPackages(string $vendorDir): InstalledPackages
{
    return new InstalledPackages([
        'acme/pack' => new PackageInfo('acme/pack', '1.0.0', $vendorDir),
    ]);
}

function rmTreeSubagent(string $path): void
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
            rmTreeSubagent($full);
        } else {
            unlink($full);
        }
    }

    rmdir($path);
}

/**
 * @param  list<WrittenFile>  $writes
 * @return list<string>
 */
function subagentDeletedPaths(array $writes): array
{
    $deleted = [];
    foreach ($writes as $write) {
        if ($write->action === WriteAction::DELETED || $write->action === WriteAction::WOULD_DELETE) {
            $deleted[] = $write->relativePath;
        }
    }

    return $deleted;
}

/**
 * One manifest entry, or null when boost claims no ownership of that path.
 *
 * @return array<string, mixed>|null
 */
function subagentManifestEntry(string $root, string $relativePath): ?array
{
    /** @var array{emitted?: array<string, array<string, mixed>>} $manifest */
    $manifest = json_decode((string) file_get_contents($root . '/.boost/manifest.json'), true, flags: JSON_THROW_ON_ERROR);

    return $manifest['emitted'][$relativePath] ?? null;
}

/**
 * @param  list<Diagnostic>  $diagnostics
 */
function subagentMessages(array $diagnostics): string
{
    return implode("\n", array_map(
        static fn (Diagnostic $diagnostic): string => $diagnostic->message,
        $diagnostics,
    ));
}

it('emits a vendor subagent into the boost subtree and gitignores that subtree only', function (): void {
    $root = subagentSyncRoot();
    $vendorDir = subagentVendorDir('reviewer');

    try {
        SyncEngine::default(subagentPackages($vendorDir))->sync($root);

        expect(file_get_contents($root . '/.claude/agents/boost/acme__pack/reviewer.md'))
            ->toContain('Body for reviewer.');

        $gitignore = file_get_contents($root . '/.gitignore');
        expect($gitignore)->toContain('.claude/agents/boost/')
            // The scanned root must stay tracked — it holds the operator's own files.
            ->and($gitignore)->not->toContain(".claude/agents/\n");
    } finally {
        rmTreeSubagent($root);
        rmTreeSubagent($vendorDir);
    }
});

it('leaves a hand-written subagent at the agents root untouched and unreaped', function (): void {
    $root = subagentSyncRoot();
    $vendorDir = subagentVendorDir('reviewer');

    try {
        SyncEngine::default(subagentPackages($vendorDir))->sync($root);

        // The hihaho case: 18 hand-maintained definitions at the top level.
        file_put_contents(
            $root . '/.claude/agents/tech-lead-reviewer.md',
            "---\nname: tech-lead-reviewer\n---\nHand-written.\n",
        );

        $result = SyncEngine::default(subagentPackages($vendorDir))->sync($root);

        expect(file_get_contents($root . '/.claude/agents/tech-lead-reviewer.md'))->toContain('Hand-written.')
            ->and(subagentDeletedPaths($result->writes))->not->toContain('.claude/agents/tech-lead-reviewer.md');
    } finally {
        rmTreeSubagent($root);
        rmTreeSubagent($vendorDir);
    }
});

it('reaps an emitted subagent once the package stops shipping it', function (): void {
    $root = subagentSyncRoot();
    $vendorDir = subagentVendorDir('reviewer', 'auditor');

    try {
        SyncEngine::default(subagentPackages($vendorDir))->sync($root);
        expect(file_exists($root . '/.claude/agents/boost/acme__pack/auditor.md'))->toBeTrue();

        // The package drops one subagent in its next release.
        unlink($vendorDir . '/resources/boost/subagents/auditor.md');
        SyncEngine::default(subagentPackages($vendorDir))->sync($root);

        expect(file_exists($root . '/.claude/agents/boost/acme__pack/auditor.md'))->toBeFalse()
            ->and(file_exists($root . '/.claude/agents/boost/acme__pack/reviewer.md'))->toBeTrue();
    } finally {
        rmTreeSubagent($root);
        rmTreeSubagent($vendorDir);
    }
});

it('warns when an emitted name is also declared by a file boost does not own', function (): void {
    $root = subagentSyncRoot();
    $vendorDir = subagentVendorDir('reviewer');

    try {
        SyncEngine::default(subagentPackages($vendorDir))->sync($root);

        // Same `name`, different filename — Claude Code collides on the name.
        file_put_contents(
            $root . '/.claude/agents/my-own-reviewer.md',
            "---\nname: reviewer\n---\nHand-written.\n",
        );

        $result = SyncEngine::default(subagentPackages($vendorDir))->sync($root);
        $messages = subagentMessages($result->diagnostics);

        expect($messages)->toContain('subagent `reviewer`')
            ->and($messages)->toContain('.claude/agents/my-own-reviewer.md')
            ->and($messages)->toContain('boost does not arbitrate')
            // Advisory only: the file is still emitted and nothing is deleted.
            ->and(file_exists($root . '/.claude/agents/boost/acme__pack/reviewer.md'))->toBeTrue()
            ->and($result->errors)->toBe([]);
    } finally {
        rmTreeSubagent($root);
        rmTreeSubagent($vendorDir);
    }
});

it('stays silent when the only holder of a name is boost itself', function (): void {
    $root = subagentSyncRoot();
    $vendorDir = subagentVendorDir('reviewer');

    try {
        SyncEngine::default(subagentPackages($vendorDir))->sync($root);
        // Second sync: the emitted file now exists on disk and must not be
        // reported as colliding with the emission that produced it.
        $result = SyncEngine::default(subagentPackages($vendorDir))->sync($root);

        expect(subagentMessages($result->diagnostics))->not->toContain('subagent `reviewer`');
    } finally {
        rmTreeSubagent($root);
        rmTreeSubagent($vendorDir);
    }
});

it('emits a host subagent under the host segment and lets it shadow the vendor one', function (): void {
    $root = subagentSyncRoot();
    $vendorDir = subagentVendorDir('reviewer');

    try {
        mkdir($root . '/.ai/subagents', 0o755, recursive: true);
        file_put_contents(
            $root . '/.ai/subagents/reviewer.md',
            "---\nname: reviewer\n---\nHost version.\n",
        );

        SyncEngine::default(subagentPackages($vendorDir))->sync($root);

        expect(file_get_contents($root . '/.claude/agents/boost/host/reviewer.md'))->toContain('Host version.')
            // The vendor copy loses outright — emitting both would collide on the name.
            ->and(file_exists($root . '/.claude/agents/boost/acme__pack/reviewer.md'))->toBeFalse();
    } finally {
        rmTreeSubagent($root);
        rmTreeSubagent($vendorDir);
    }
});

it('emits nothing for a project whose agent has no subagent surface', function (): void {
    $root = subagentSyncRoot("BoostConfig::configure()->withAgents([Agent::CURSOR])->withAllowedVendors(['acme/pack'])");
    $vendorDir = subagentVendorDir('reviewer');

    try {
        $result = SyncEngine::default(subagentPackages($vendorDir))->sync($root);

        expect(is_dir($root . '/.claude/agents'))->toBeFalse()
            // Silent: no per-run warning for a target that simply cannot use them.
            ->and(subagentMessages($result->diagnostics))->not->toContain('subagent');
    } finally {
        rmTreeSubagent($root);
        rmTreeSubagent($vendorDir);
    }
});

it('warns about a source that declares no name instead of emitting it', function (): void {
    $root = subagentSyncRoot();
    $vendorDir = subagentVendorDir('reviewer');

    try {
        file_put_contents(
            $vendorDir . '/resources/boost/subagents/nameless.md',
            "---\ndescription: no name\n---\nBody.\n",
        );

        $result = SyncEngine::default(subagentPackages($vendorDir))->sync($root);

        expect(subagentMessages($result->diagnostics))->toContain('nameless.md')
            ->and(file_exists($root . '/.claude/agents/boost/acme__pack/nameless.md'))->toBeFalse();
    } finally {
        rmTreeSubagent($root);
        rmTreeSubagent($vendorDir);
    }
});

it('records each emitted subagent in the manifest under the subagent category', function (): void {
    $root = subagentSyncRoot();
    $vendorDir = subagentVendorDir('reviewer');

    try {
        SyncEngine::default(subagentPackages($vendorDir))->sync($root);

        $entry = subagentManifestEntry($root, '.claude/agents/boost/acme__pack/reviewer.md');

        // Ownership is what the manifest records: without this entry the file
        // is on disk with nothing claiming it.
        expect($entry)->not->toBeNull()
            ->and($entry['category'] ?? null)->toBe('subagent')
            ->and($entry['provenance'] ?? null)->toBe('engine');

        // The hand-written sibling at the root is owned by nobody, by design.
        file_put_contents($root . '/.claude/agents/mine.md', "---\nname: mine\n---\nHand-written.\n");
        SyncEngine::default(subagentPackages($vendorDir))->sync($root);

        expect(subagentManifestEntry($root, '.claude/agents/mine.md'))->toBeNull();
    } finally {
        rmTreeSubagent($root);
        rmTreeSubagent($vendorDir);
    }
});

it('deletes a hand-edited emitted subagent when its source goes, exactly like a skill', function (): void {
    $root = subagentSyncRoot();
    $vendorDir = subagentVendorDir('reviewer');

    try {
        SyncEngine::default(subagentPackages($vendorDir))->sync($root);

        // The never-lossy sha rule belongs to the emitter/guidance categories,
        // NOT to skill-shaped emissions. The boost subtree is gitignored and
        // 100% generated, so the clean-slate sweep removes a stale file whether
        // or not the operator edited it — verified identical for an emitted
        // `.claude/skills/<name>/SKILL.md`. Edits belong in the source.
        file_put_contents($root . '/.claude/agents/boost/acme__pack/reviewer.md', "---\nname: reviewer\n---\nEdited by hand.\n");
        unlink($vendorDir . '/resources/boost/subagents/reviewer.md');

        SyncEngine::default(subagentPackages($vendorDir))->sync($root);

        expect(file_exists($root . '/.claude/agents/boost/acme__pack/reviewer.md'))->toBeFalse();
    } finally {
        rmTreeSubagent($root);
        rmTreeSubagent($vendorDir);
    }
});

it('rescues a tag-filtered subagent that a shipping skill requires, and says so', function (): void {
    $root = subagentSyncRoot("BoostConfig::configure()->withAgents([Agent::CLAUDE_CODE])->withAllowedVendors(['acme/pack'])");
    $vendorDir = subagentVendorDir();

    try {
        // Tagged `laravel`, which the project does not declare — filtered out
        // on its own, but rescued because a shipping skill declares it a hard
        // dependency.
        file_put_contents(
            $vendorDir . '/resources/boost/subagents/auditor.md',
            "---\nname: auditor\nmetadata:\n  boost-tags: \"laravel\"\n---\nBody.\n",
        );
        mkdir($root . '/.ai/skills/evaluate', 0o755, recursive: true);
        file_put_contents(
            $root . '/.ai/skills/evaluate/SKILL.md',
            "---\nname: evaluate\nmetadata:\n  boost-requires: \"subagent:auditor\"\n---\nBody.\n",
        );

        $result = SyncEngine::default(subagentPackages($vendorDir))->sync($root);

        expect(file_exists($root . '/.claude/agents/boost/acme__pack/auditor.md'))->toBeTrue()
            ->and(subagentMessages($result->diagnostics))->toContain('despite tag filtering')
            ->and($result->errors)->toBe([]);
    } finally {
        rmTreeSubagent($root);
        rmTreeSubagent($vendorDir);
    }
});

it('warns that a required subagent no package provides is missing, and still ships the skill', function (): void {
    $root = subagentSyncRoot();
    $vendorDir = subagentVendorDir('reviewer');

    try {
        mkdir($root . '/.ai/skills/evaluate', 0o755, recursive: true);
        file_put_contents(
            $root . '/.ai/skills/evaluate/SKILL.md',
            "---\nname: evaluate\nmetadata:\n  boost-requires: \"subagent:nope\"\n---\nBody.\n",
        );

        $result = SyncEngine::default(subagentPackages($vendorDir))->sync($root);
        $messages = subagentMessages($result->diagnostics);

        expect($messages)->toContain('subagent `nope`')
            ->and($messages)->toContain('not provided by any installed package')
            // Requires gate completeness, not scoping: never fatal.
            ->and($result->errors)->toBe([])
            ->and(file_exists($root . '/.claude/skills/evaluate/SKILL.md'))->toBeTrue();
    } finally {
        rmTreeSubagent($root);
        rmTreeSubagent($vendorDir);
    }
});

it('reports an excluded required subagent as excluded rather than missing', function (): void {
    $root = subagentSyncRoot("BoostConfig::configure()->withAgents([Agent::CLAUDE_CODE])->withAllowedVendors(['acme/pack'])->withExcludedSkills(['acme/pack:reviewer'])");
    $vendorDir = subagentVendorDir('reviewer');

    try {
        mkdir($root . '/.ai/skills/evaluate', 0o755, recursive: true);
        file_put_contents(
            $root . '/.ai/skills/evaluate/SKILL.md',
            "---\nname: evaluate\nmetadata:\n  boost-requires: \"subagent:reviewer\"\n---\nBody.\n",
        );

        $result = SyncEngine::default(subagentPackages($vendorDir))->sync($root);
        $messages = subagentMessages($result->diagnostics);

        expect($messages)->toContain('excluded by this project')
            ->and($messages)->not->toContain('not provided by any installed package')
            // An explicit deny is never overridden by rescue.
            ->and(file_exists($root . '/.claude/agents/boost/acme__pack/reviewer.md'))->toBeFalse();
    } finally {
        rmTreeSubagent($root);
        rmTreeSubagent($vendorDir);
    }
});

it('never reports a subagent demand as a missing SKILL', function (): void {
    // The regression this guards: if `subagent:auditor` leaked through
    // BoostRequires::parse(), the skill pipeline would hunt for a skill of that
    // literal name and warn about it.
    $root = subagentSyncRoot();
    $vendorDir = subagentVendorDir('auditor');

    try {
        mkdir($root . '/.ai/skills/evaluate', 0o755, recursive: true);
        file_put_contents(
            $root . '/.ai/skills/evaluate/SKILL.md',
            "---\nname: evaluate\nmetadata:\n  boost-requires: \"subagent:auditor\"\n---\nBody.\n",
        );

        $result = SyncEngine::default(subagentPackages($vendorDir))->sync($root);

        expect(subagentMessages($result->diagnostics))->not->toContain('subagent:auditor')
            ->and($result->errors)->toBe([]);
    } finally {
        rmTreeSubagent($root);
        rmTreeSubagent($vendorDir);
    }
});

it('moves an emitted subagent when it changes package, reaping the old path', function (): void {
    $root = subagentSyncRoot("BoostConfig::configure()->withAgents([Agent::CLAUDE_CODE])->withAllowedVendors(['acme/pack', 'other/pack'])");
    $vendorA = subagentVendorDir('reviewer');
    $vendorB = sys_get_temp_dir() . '/boost-subagent-vendorb-' . bin2hex(random_bytes(8));
    mkdir($vendorB . '/resources/boost/subagents', 0o755, recursive: true);
    file_put_contents($vendorB . '/composer.json', json_encode(['name' => 'other/pack'], JSON_THROW_ON_ERROR));

    try {
        $packagesA = new InstalledPackages([
            'acme/pack' => new PackageInfo('acme/pack', '1.0.0', $vendorA),
            'other/pack' => new PackageInfo('other/pack', '1.0.0', $vendorB),
        ]);
        SyncEngine::default($packagesA)->sync($root);
        expect(file_exists($root . '/.claude/agents/boost/acme__pack/reviewer.md'))->toBeTrue();

        // The subagent is handed over to the other package. Because the emitted
        // path carries the package suffix, the move is a delete plus a write.
        unlink($vendorA . '/resources/boost/subagents/reviewer.md');
        file_put_contents(
            $vendorB . '/resources/boost/subagents/reviewer.md',
            "---\nname: reviewer\ndescription: Fixture.\n---\nBody for reviewer.\n",
        );

        SyncEngine::default($packagesA)->sync($root);

        expect(file_exists($root . '/.claude/agents/boost/acme__pack/reviewer.md'))->toBeFalse()
            ->and(file_exists($root . '/.claude/agents/boost/other__pack/reviewer.md'))->toBeTrue();
    } finally {
        rmTreeSubagent($root);
        rmTreeSubagent($vendorA);
        rmTreeSubagent($vendorB);
    }
});

it('never clean-slates a pre-existing boost subagent subtree when no manifest covers it', function (): void {
    // `.claude/agents/` is a directory the OPERATOR also writes into, and the
    // docs name `boost/` as a path, so someone can have organised hand-written
    // definitions there before ever syncing this release. Every other managed
    // directory is boost's outright and stays clean-slate; this one cannot be,
    // because the loss is unrecoverable.
    $root = subagentSyncRoot();
    $vendorDir = subagentVendorDir('reviewer');

    try {
        mkdir($root . '/.claude/agents/boost', 0o755, recursive: true);
        file_put_contents($root . '/.claude/agents/boost/mine.md', "---\nname: mine\n---\nHand-written.\n");

        SyncEngine::default(subagentPackages($vendorDir))->sync($root);
        // Manifest gone (wiped `.boost/`, a lost checkout) while the managed
        // gitignore block still names the subtree — the ungated path.
        unlink($root . '/.boost/manifest.json');

        SyncEngine::default(subagentPackages($vendorDir))->sync($root);

        expect(file_get_contents($root . '/.claude/agents/boost/mine.md'))->toContain('Hand-written.');
    } finally {
        rmTreeSubagent($root);
        rmTreeSubagent($vendorDir);
    }
});

it('still clean-slates a foreign file in a fully boost-owned directory with no manifest', function (): void {
    // The control for the guard above: `.claude/skills/` is boost's outright,
    // so the historical behaviour there is deliberately unchanged.
    $root = subagentSyncRoot();
    $vendorDir = subagentVendorDir('reviewer');

    try {
        SyncEngine::default(subagentPackages($vendorDir))->sync($root);

        mkdir($root . '/.claude/skills/foreign', 0o755, recursive: true);
        file_put_contents($root . '/.claude/skills/foreign/SKILL.md', "---\nname: foreign\n---\nx\n");
        unlink($root . '/.boost/manifest.json');

        SyncEngine::default(subagentPackages($vendorDir))->sync($root);

        expect(file_exists($root . '/.claude/skills/foreign/SKILL.md'))->toBeFalse();
    } finally {
        rmTreeSubagent($root);
        rmTreeSubagent($vendorDir);
    }
});

it('warns when two host files claim one subagent name, and emits only the winner', function (): void {
    $root = subagentSyncRoot();
    $vendorDir = subagentVendorDir();

    try {
        mkdir($root . '/.ai/subagents/legacy', 0o755, recursive: true);
        file_put_contents($root . '/.ai/subagents/auditor.md', "---\nname: auditor\n---\nFirst.\n");
        file_put_contents($root . '/.ai/subagents/legacy/auditor.md', "---\nname: auditor\n---\nSecond.\n");

        $result = SyncEngine::default(subagentPackages($vendorDir))->sync($root);

        expect(subagentMessages($result->diagnostics))->toContain('is declared by two files')
            ->and($result->errors)->toBe([])
            ->and(file_get_contents($root . '/.claude/agents/boost/host/auditor.md'))->toContain('First.');
    } finally {
        rmTreeSubagent($root);
        rmTreeSubagent($vendorDir);
    }
});

it('fails the sync with a package-specific error when one package ships a duplicate name', function (): void {
    $root = subagentSyncRoot();
    $vendorDir = subagentVendorDir('auditor');

    try {
        mkdir($vendorDir . '/resources/boost/subagents/nested', 0o755, recursive: true);
        file_put_contents(
            $vendorDir . '/resources/boost/subagents/nested/other.md',
            "---\nname: auditor\n---\nDuplicate.\n",
        );

        $result = SyncEngine::default(subagentPackages($vendorDir))->sync($root);

        expect($result->errors)->toHaveCount(1)
            ->and($result->errors[0])->toContain('ships two subagents named')
            ->and($result->errors[0])->toContain('acme/pack');
    } finally {
        rmTreeSubagent($root);
        rmTreeSubagent($vendorDir);
    }
});

it('fails on a package-local duplicate even when tag filtering would drop one copy', function (): void {
    // The mistake ships to everyone, so it must not hide behind a tag the
    // consumer happens not to declare.
    $root = subagentSyncRoot();
    $vendorDir = subagentVendorDir('auditor');

    try {
        file_put_contents(
            $vendorDir . '/resources/boost/subagents/tagged-copy.md',
            "---\nname: auditor\nmetadata:\n  boost-tags: \"laravel\"\n---\nDuplicate.\n",
        );

        $result = SyncEngine::default(subagentPackages($vendorDir))->sync($root);

        expect($result->errors)->toHaveCount(1)
            ->and($result->errors[0])->toContain('ships two subagents named');
    } finally {
        rmTreeSubagent($root);
        rmTreeSubagent($vendorDir);
    }
});

it('refuses to rescue an ambiguous subagent two vendors both hold', function (): void {
    // Rescue must not be a back door around the collision rule: both copies are
    // tag-filtered out, so only the require brings them back.
    $root = subagentSyncRoot("BoostConfig::configure()->withAgents([Agent::CLAUDE_CODE])->withAllowedVendors(['acme/pack', 'other/pack'])");
    $vendorA = subagentVendorDir();
    $vendorB = sys_get_temp_dir() . '/boost-subagent-vendorc-' . bin2hex(random_bytes(8));
    mkdir($vendorB . '/resources/boost/subagents', 0o755, recursive: true);
    file_put_contents($vendorB . '/composer.json', json_encode(['name' => 'other/pack'], JSON_THROW_ON_ERROR));

    try {
        foreach ([$vendorA, $vendorB] as $dir) {
            file_put_contents(
                $dir . '/resources/boost/subagents/auditor.md',
                "---\nname: auditor\nmetadata:\n  boost-tags: \"laravel\"\n---\nBody.\n",
            );
        }

        mkdir($root . '/.ai/skills/evaluate', 0o755, recursive: true);
        file_put_contents(
            $root . '/.ai/skills/evaluate/SKILL.md',
            "---\nname: evaluate\nmetadata:\n  boost-requires: \"subagent:auditor\"\n---\nBody.\n",
        );

        $packages = new InstalledPackages([
            'acme/pack' => new PackageInfo('acme/pack', '1.0.0', $vendorA),
            'other/pack' => new PackageInfo('other/pack', '1.0.0', $vendorB),
        ]);
        $result = SyncEngine::default($packages)->sync($root);

        expect($result->errors)->toHaveCount(1)
            ->and($result->errors[0])->toContain('published by multiple vendors')
            ->and($result->errors[0])->toContain('acme/pack')
            ->and($result->errors[0])->toContain('other/pack');
    } finally {
        rmTreeSubagent($root);
        rmTreeSubagent($vendorA);
        rmTreeSubagent($vendorB);
    }
});

it('gitignores the manifest dir for a project whose only artefact is a subagent', function (): void {
    $root = subagentSyncRoot();
    $vendorDir = subagentVendorDir('reviewer');

    try {
        // No skills, guidelines, commands or conventions — the manifest is
        // still written, so it still has to be ignored.
        rmTreeSubagent($root . '/.ai/skills');

        SyncEngine::default(subagentPackages($vendorDir))->sync($root);

        expect(is_file($root . '/.boost/manifest.json'))->toBeTrue()
            ->and(file_get_contents($root . '/.gitignore'))->toContain('.boost/');
    } finally {
        rmTreeSubagent($root);
        rmTreeSubagent($vendorDir);
    }
});

it('does not claim a collision for a project whose agents cannot receive subagents', function (): void {
    // Cursor-only: boost writes no subagent, so a hand-written Claude
    // definition of the same name is not a conflict boost is creating.
    $root = subagentSyncRoot("BoostConfig::configure()->withAgents([Agent::CURSOR])->withAllowedVendors(['acme/pack'])");
    $vendorDir = subagentVendorDir('reviewer');

    try {
        mkdir($root . '/.claude/agents', 0o755, recursive: true);
        file_put_contents($root . '/.claude/agents/reviewer.md', "---\nname: reviewer\n---\nHand-written.\n");

        $result = SyncEngine::default(subagentPackages($vendorDir))->sync($root);

        expect(subagentMessages($result->diagnostics))->not->toContain('boost does not arbitrate')
            ->and(file_get_contents($root . '/.claude/agents/reviewer.md'))->toContain('Hand-written.');
    } finally {
        rmTreeSubagent($root);
        rmTreeSubagent($vendorDir);
    }
});
