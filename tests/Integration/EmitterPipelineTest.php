<?php declare(strict_types=1);

use SanderMuller\BoostCore\Agents\CursorTarget;
use SanderMuller\BoostCore\Conventions\Diagnostic;
use SanderMuller\BoostCore\Sync\EmitterAction;
use SanderMuller\BoostCore\Sync\InstalledPackages;
use SanderMuller\BoostCore\Sync\PackageInfo;
use SanderMuller\BoostCore\Sync\SyncEngine;
use SanderMuller\BoostCore\Sync\WriteAction;
use SanderMuller\BoostCore\Tests\Fixtures\Emitters\CaseRenameEmitter;
use SanderMuller\BoostCore\Tests\Fixtures\Emitters\DotAliasEmitter;
use SanderMuller\BoostCore\Tests\Fixtures\Emitters\DotAliasReservedEmitter;
use SanderMuller\BoostCore\Tests\Fixtures\Emitters\DummyEmitter;
use SanderMuller\BoostCore\Tests\Fixtures\Emitters\GeneratorThrowingEmitter;
use SanderMuller\BoostCore\Tests\Fixtures\Emitters\InactiveAgentRootEmitter;
use SanderMuller\BoostCore\Tests\Fixtures\Emitters\LowerCaseReservedEmitter;
use SanderMuller\BoostCore\Tests\Fixtures\Emitters\MultiFileEmitter;
use SanderMuller\BoostCore\Tests\Fixtures\Emitters\ReservedPathEmitter;
use SanderMuller\BoostCore\Tests\Fixtures\Emitters\SkippingEmitter;
use SanderMuller\BoostCore\Tests\Fixtures\Emitters\ThrowingEmitter;

function makeEmitterProject(): string
{
    $root = sys_get_temp_dir() . '/boost-emit-pipeline-' . bin2hex(random_bytes(8));
    mkdir($root, 0o755, recursive: true);

    return $root;
}

function rmTreeEmit(string $path): void
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
            rmTreeEmit($full);
        } else {
            unlink($full);
        }
    }

    rmdir($path);
}

function fakeVendorWithEmitter(string $vendorName, string $emitterClass, string $vendorDir): InstalledPackages
{
    mkdir($vendorDir, 0o755, recursive: true);
    file_put_contents($vendorDir . '/composer.json', json_encode([
        'name' => $vendorName,
        'extra' => ['boost' => ['emitters' => [$emitterClass]]],
    ]));

    return new InstalledPackages([
        $vendorName => new PackageInfo($vendorName, '1.0.0', $vendorDir),
    ]);
}

function writeBoostPhpForEmitter(string $root, string $vendor): void
{
    file_put_contents(
        $root . '/boost.php',
        "<?php\n\ndeclare(strict_types=1);\n\nuse SanderMuller\\BoostCore\\Config\\BoostConfig;\n\nreturn BoostConfig::configure()\n    ->withAllowedVendors([\"{$vendor}\"]);\n",
    );
}

it('runs a discovered emitter and writes its file', function (): void {
    $root = makeEmitterProject();
    try {
        $packages = fakeVendorWithEmitter(
            'test/dummy-pkg',
            DummyEmitter::class,
            $root . '/vendor/test/dummy-pkg',
        );

        writeBoostPhpForEmitter($root, 'test/dummy-pkg');

        $result = (new SyncEngine([], installedPackages: $packages))->sync($root);

        expect($result->emitters)->toHaveCount(1)
            ->and($result->emitters[0]->action)
            ->toBe(EmitterAction::WROTE)
            ->and($result->emitters[0]->fqcn)
            ->toBe(DummyEmitter::class)
            ->and($result->emitters[0]->vendor)
            ->toBe('test/dummy-pkg')
            ->and(file_exists($root . '/.dummy/output.txt'))
            ->toBeTrue();
    } finally {
        rmTreeEmit($root);
    }
});

it('writes every file when an emitter returns multiple (0.21.0 iterable contract)', function (): void {
    $root = makeEmitterProject();
    try {
        $packages = fakeVendorWithEmitter(
            'test/multi-pkg',
            MultiFileEmitter::class,
            $root . '/vendor/test/multi-pkg',
        );

        writeBoostPhpForEmitter($root, 'test/multi-pkg');

        $result = (new SyncEngine([], installedPackages: $packages))->sync($root);

        // One EmitterResult per emitted file; both written.
        $actions = [];
        $paths = [];
        foreach ($result->emitters as $emitterResult) {
            $actions[] = $emitterResult->action;
            $paths[] = $emitterResult->relativePath;
        }

        sort($paths);

        expect($result->emitters)->toHaveCount(2)
            ->and($actions)->each->toBe(EmitterAction::WROTE)
            ->and($paths)->toBe(['.multi/a.txt', '.multi/b.txt'])
            ->and((string) file_get_contents($root . '/.multi/a.txt'))->toBe("alpha\n")
            ->and((string) file_get_contents($root . '/.multi/b.txt'))->toBe("beta\n");
    } finally {
        rmTreeEmit($root);
    }
});

it('records errored (not abort) when a generator emit() throws mid-iteration', function (): void {
    $root = makeEmitterProject();
    try {
        $packages = fakeVendorWithEmitter(
            'test/gen-throw-pkg',
            GeneratorThrowingEmitter::class,
            $root . '/vendor/test/gen-throw-pkg',
        );

        writeBoostPhpForEmitter($root, 'test/gen-throw-pkg');

        // Must not abort the sync — the lazy throw is caught + recorded.
        $result = (new SyncEngine([], installedPackages: $packages))->sync($root);

        expect($result->emitters)->toHaveCount(1)
            ->and($result->emitters[0]->action)->toBe(EmitterAction::ERRORED)
            ->and($result->emitters[0]->reason)->toContain('mid-generator')
            // The partial first yield is discarded — a failed emitter writes nothing.
            ->and(file_exists($root . '/.gen/first.txt'))->toBeFalse();
    } finally {
        rmTreeEmit($root);
    }
});

it('records skipped emitters when emit() returns null', function (): void {
    $root = makeEmitterProject();
    try {
        $packages = fakeVendorWithEmitter(
            'test/skip-pkg',
            SkippingEmitter::class,
            $root . '/vendor/test/skip-pkg',
        );

        writeBoostPhpForEmitter($root, 'test/skip-pkg');

        $result = (new SyncEngine([], installedPackages: $packages))->sync($root);

        expect($result->emitters)->toHaveCount(1)
            ->and($result->emitters[0]->action)
            ->toBe(EmitterAction::SKIPPED)
            ->and($result->hasErrors())
            ->toBeFalse();
    } finally {
        rmTreeEmit($root);
    }
});

it('records errored emitters when emit() throws and continues sync', function (): void {
    $root = makeEmitterProject();
    try {
        $packages = fakeVendorWithEmitter(
            'test/throw-pkg',
            ThrowingEmitter::class,
            $root . '/vendor/test/throw-pkg',
        );

        writeBoostPhpForEmitter($root, 'test/throw-pkg');

        $result = (new SyncEngine([], installedPackages: $packages))->sync($root);

        expect($result->emitters)->toHaveCount(1)
            ->and($result->emitters[0]->action)
            ->toBe(EmitterAction::ERRORED)
            ->and($result->emitters[0]->reason)
            ->toContain('Deliberate failure')
            ->and($result->hasErrors())
            ->toBeTrue(); // errored emitters count as errors
    } finally {
        rmTreeEmit($root);
    }
});

it('records disabled emitters when withDisabledEmitters lists them', function (): void {
    $root = makeEmitterProject();
    try {
        $packages = fakeVendorWithEmitter(
            'test/dummy-pkg',
            DummyEmitter::class,
            $root . '/vendor/test/dummy-pkg',
        );

        // Override boost.php to disable the emitter.
        file_put_contents(
            $root . '/boost.php',
            sprintf(
                "<?php\n\ndeclare(strict_types=1);\n\nuse SanderMuller\\BoostCore\\Config\\BoostConfig;\n\nreturn BoostConfig::configure()\n    ->withAllowedVendors([\"test/dummy-pkg\"])\n    ->withDisabledEmitters([\"%s\"]);\n",
                str_replace('\\', '\\\\', DummyEmitter::class),
            ),
        );

        $result = (new SyncEngine([], installedPackages: $packages))->sync($root);

        expect($result->emitters)->toHaveCount(1)
            ->and($result->emitters[0]->action)
            ->toBe(EmitterAction::DISABLED)
            ->and(file_exists($root . '/.dummy/output.txt'))
            ->toBeFalse();
    } finally {
        rmTreeEmit($root);
    }
});

it('reports would-write for emitters in check mode', function (): void {
    $root = makeEmitterProject();
    try {
        $packages = fakeVendorWithEmitter(
            'test/dummy-pkg',
            DummyEmitter::class,
            $root . '/vendor/test/dummy-pkg',
        );

        writeBoostPhpForEmitter($root, 'test/dummy-pkg');

        $result = (new SyncEngine([], installedPackages: $packages))->sync($root, checkOnly: true);

        expect($result->emitters)->toHaveCount(1)
            ->and($result->emitters[0]->action)
            ->toBe(EmitterAction::WOULD_WRITE)
            ->and($result->hasDrift())
            ->toBeTrue()
            ->and(file_exists($root . '/.dummy/output.txt'))
            ->toBeFalse();
    } finally {
        rmTreeEmit($root);
    }
});

it('0.14.0: records a live emitter output in the manifest and does NOT reap it on a re-sync while live', function (): void {
    $root = makeEmitterProject();
    try {
        $packages = fakeVendorWithEmitter('test/dummy-pkg', DummyEmitter::class, $root . '/vendor/test/dummy-pkg');
        writeBoostPhpForEmitter($root, 'test/dummy-pkg');

        // Sync 1: emitter writes + manifest records it as category `file`,
        // provenance `emitter:<fqcn>`.
        (new SyncEngine([], installedPackages: $packages))->sync($root);
        expect(file_exists($root . '/.dummy/output.txt'))->toBeTrue();

        /** @var array{emitted: array<string, array{category: string, provenance: string}>} $manifest */
        $manifest = json_decode((string) file_get_contents($root . '/.boost/manifest.json'), true, 512, JSON_THROW_ON_ERROR);
        expect($manifest['emitted']['.dummy/output.txt']['category'])->toBe('file')
            ->and($manifest['emitted']['.dummy/output.txt']['provenance'])->toBe('emitter:' . DummyEmitter::class);

        // Sync 2: emitter still live → output stays (in the intended set).
        (new SyncEngine([], installedPackages: $packages))->sync($root);
        expect(file_exists($root . '/.dummy/output.txt'))->toBeTrue();
    } finally {
        rmTreeEmit($root);
    }
});

it('0.14.0: reaps a dormant emitter output when its package is removed (a8u8tew6: .mcp.json left behind)', function (): void {
    $root = makeEmitterProject();
    try {
        $packages = fakeVendorWithEmitter('test/dummy-pkg', DummyEmitter::class, $root . '/vendor/test/dummy-pkg');
        writeBoostPhpForEmitter($root, 'test/dummy-pkg');

        // Sync 1: emitter live → file written + recorded.
        (new SyncEngine([], installedPackages: $packages))->sync($root);
        expect(file_exists($root . '/.dummy/output.txt'))->toBeTrue();

        // Sync 2: the emitter's package is GONE (dep removed). The prior manifest
        // owns the path (emitter:<fqcn>); the emitter no longer emits it →
        // reaped.
        $result = (new SyncEngine([], installedPackages: new InstalledPackages([])))->sync($root);

        expect(file_exists($root . '/.dummy/output.txt'))->toBeFalse('dormant emitter output should be reaped')
            ->and($result->countByAction(WriteAction::DELETED))->toBeGreaterThanOrEqual(1);
    } finally {
        rmTreeEmit($root);
    }
});

it('0.14.0: PRESERVES a disabled emitter output (disabling means stop-regenerating, not delete — codex P2)', function (): void {
    $root = makeEmitterProject();
    try {
        $packages = fakeVendorWithEmitter('test/dummy-pkg', DummyEmitter::class, $root . '/vendor/test/dummy-pkg');
        writeBoostPhpForEmitter($root, 'test/dummy-pkg');

        // Sync 1: live → written + recorded.
        (new SyncEngine([], installedPackages: $packages))->sync($root);
        expect(file_exists($root . '/.dummy/output.txt'))->toBeTrue();

        // Sync 2: emitter DISABLED (still installed) → its prior output is
        // preserved, NOT reaped.
        file_put_contents(
            $root . '/boost.php',
            sprintf(
                "<?php\n\ndeclare(strict_types=1);\n\nuse SanderMuller\\BoostCore\\Config\\BoostConfig;\n\nreturn BoostConfig::configure()\n    ->withAllowedVendors([\"test/dummy-pkg\"])\n    ->withDisabledEmitters([\"%s\"]);\n",
                str_replace('\\', '\\\\', DummyEmitter::class),
            ),
        );
        (new SyncEngine([], installedPackages: $packages))->sync($root);

        expect(file_exists($root . '/.dummy/output.txt'))->toBeTrue('disabled emitter output must be preserved');
    } finally {
        rmTreeEmit($root);
    }
});

it('0.14.0: rejects an emitter that returns a reserved path (CLAUDE.md) and skips the write (codex P1 denylist)', function (): void {
    $root = makeEmitterProject();
    try {
        $packages = fakeVendorWithEmitter('test/reserved-pkg', ReservedPathEmitter::class, $root . '/vendor/test/reserved-pkg');
        writeBoostPhpForEmitter($root, 'test/reserved-pkg');

        $result = (new SyncEngine([], installedPackages: $packages))->sync($root);

        expect($result->emitters[0]->action)->toBe(EmitterAction::SKIPPED)
            ->and($result->emitters[0]->reason)->toContain('Reserved path')
            ->and(file_exists($root . '/CLAUDE.md'))->toBeFalse('emitter must not clobber the guidance file')
            ->and($result->hasErrors())->toBeFalse('a reserved-path rejection must not abort the sync');

        $diagnostics = implode("\n", array_map(static fn (Diagnostic $d): string => $d->message, $result->diagnostics));
        expect($diagnostics)->toContain('reserved path');
    } finally {
        rmTreeEmit($root);
    }
});

it('0.14.0: a `.`-segment path alias (./CLAUDE.md) cannot dodge the reserved-path denylist (codex high)', function (): void {
    $root = makeEmitterProject();
    try {
        $packages = fakeVendorWithEmitter('test/alias-pkg', DotAliasReservedEmitter::class, $root . '/vendor/test/alias-pkg');
        writeBoostPhpForEmitter($root, 'test/alias-pkg');

        $result = (new SyncEngine([], installedPackages: $packages))->sync($root);

        // `./CLAUDE.md` canonicalizes to `CLAUDE.md` BEFORE the denylist check,
        // so it is rejected and never written.
        expect($result->emitters[0]->action)->toBe(EmitterAction::SKIPPED)
            ->and($result->emitters[0]->reason)->toContain('Reserved path')
            ->and(file_exists($root . '/CLAUDE.md'))->toBeFalse('alias must not clobber the guidance file');
    } finally {
        rmTreeEmit($root);
    }
});

it('0.14.0: a `.`-aliased emitter path is canonicalized consistently — recorded + matched as one spelling, NOT reaped while live (codex high)', function (): void {
    $root = makeEmitterProject();
    try {
        $packages = fakeVendorWithEmitter('test/alias-pkg', DotAliasEmitter::class, $root . '/vendor/test/alias-pkg');
        writeBoostPhpForEmitter($root, 'test/alias-pkg');

        // Sync 1: `./.dummy/output.txt` is written to `.dummy/output.txt` and
        // recorded under the canonical key.
        (new SyncEngine([], installedPackages: $packages))->sync($root);
        expect(file_exists($root . '/.dummy/output.txt'))->toBeTrue();

        /** @var array{emitted: array<string, mixed>} $manifest */
        $manifest = json_decode((string) file_get_contents($root . '/.boost/manifest.json'), true, 512, JSON_THROW_ON_ERROR);
        expect($manifest['emitted'])->toHaveKey('.dummy/output.txt')
            ->and($manifest['emitted'])->not->toHaveKey('./.dummy/output.txt');

        // Sync 2: same emitter, same alias → canonical key matches the prior
        // entry → in the intended set → the live file is NOT reaped.
        (new SyncEngine([], installedPackages: $packages))->sync($root);
        expect(file_exists($root . '/.dummy/output.txt'))->toBeTrue('canonical spelling must not be seen as a dormant orphan');
    } finally {
        rmTreeEmit($root);
    }
});

it('0.14.0: a case-variant of a reserved path (claude.md) is rejected — case-insensitive FS bypass guard (codex high)', function (): void {
    $root = makeEmitterProject();
    try {
        $packages = fakeVendorWithEmitter('test/case-pkg', LowerCaseReservedEmitter::class, $root . '/vendor/test/case-pkg');
        writeBoostPhpForEmitter($root, 'test/case-pkg');

        $result = (new SyncEngine([], installedPackages: $packages))->sync($root);

        expect($result->emitters[0]->action)->toBe(EmitterAction::SKIPPED)
            ->and($result->emitters[0]->reason)->toContain('Reserved path')
            ->and(file_exists($root . '/claude.md'))->toBeFalse()
            ->and(file_exists($root . '/CLAUDE.md'))->toBeFalse();
    } finally {
        rmTreeEmit($root);
    }
});

it('0.14.0: reap NEVER recurses into a directory the operator placed at a former emitter-file path (codex high — type confusion)', function (): void {
    $root = makeEmitterProject();
    try {
        $packages = fakeVendorWithEmitter('test/dummy-pkg', DummyEmitter::class, $root . '/vendor/test/dummy-pkg');
        writeBoostPhpForEmitter($root, 'test/dummy-pkg');

        // Sync 1: emitter writes .dummy/output.txt + records it.
        (new SyncEngine([], installedPackages: $packages))->sync($root);
        expect($root . '/.dummy/output.txt')
            ->toBeFile();

        // Operator repurposes the path as a DIRECTORY tree with their own content.
        unlink($root . '/.dummy/output.txt');
        mkdir($root . '/.dummy/output.txt', 0o755, recursive: true);
        file_put_contents($root . '/.dummy/output.txt/operator-data.txt', "operator content\n");

        // Sync 2: emitter package removed (dormant). The reap must NOT recurse
        // into the operator's directory — it only deletes a regular file.
        (new SyncEngine([], installedPackages: new InstalledPackages([])))->sync($root);

        expect(is_dir($root . '/.dummy/output.txt'))->toBeTrue('operator directory must be preserved')
            ->and(file_exists($root . '/.dummy/output.txt/operator-data.txt'))->toBeTrue('operator content must survive');
    } finally {
        rmTreeEmit($root);
    }
});

it('0.14.0: a first-sync UNCHANGED emitter output (coincidental operator file) is NOT claimed and NOT later reaped (codex high — adoption guard)', function (): void {
    $root = makeEmitterProject();
    try {
        $packages = fakeVendorWithEmitter('test/dummy-pkg', DummyEmitter::class, $root . '/vendor/test/dummy-pkg');
        writeBoostPhpForEmitter($root, 'test/dummy-pkg');

        // Operator already has a byte-identical file at the emitter's path
        // (DummyEmitter's content is deterministic from the project root).
        mkdir($root . '/.dummy', 0o755, recursive: true);
        file_put_contents($root . '/.dummy/output.txt', "Dummy emitter output for project root: {$root}\n");

        // Sync 1: emitter result is UNCHANGED + the path is absent from the
        // (empty) prior manifest → ownership is NOT recorded.
        (new SyncEngine([], installedPackages: $packages))->sync($root);

        if (is_file($root . '/.boost/manifest.json')) {
            /** @var array{emitted: array<string, mixed>} $manifest */
            $manifest = json_decode((string) file_get_contents($root . '/.boost/manifest.json'), true, 512, JSON_THROW_ON_ERROR);
            expect($manifest['emitted'])->not->toHaveKey('.dummy/output.txt');
        }

        // Sync 2: emitter package removed. Because boost never claimed the
        // coincidental operator file, it must be PRESERVED.
        (new SyncEngine([], installedPackages: new InstalledPackages([])))->sync($root);

        expect(file_exists($root . '/.dummy/output.txt'))->toBeTrue('coincidental operator file must not be reaped');
    } finally {
        rmTreeEmit($root);
    }
});

it('0.14.0: an emitter targeting an agent skill root is rejected even when that agent is INACTIVE (codex high)', function (): void {
    $root = makeEmitterProject();
    try {
        $packages = fakeVendorWithEmitter('test/root-pkg', InactiveAgentRootEmitter::class, $root . '/vendor/test/root-pkg');
        // boost.php declares NO agents — `.claude/skills/` is inactive here, but
        // the agent emission roots are reserved for emitters unconditionally.
        // Use default() so the real 9 agent targets populate the denylist (the
        // production construction path; the bare `new SyncEngine([])` harness has
        // no agent targets).
        writeBoostPhpForEmitter($root, 'test/root-pkg');

        $result = SyncEngine::default($packages)->sync($root);

        expect($result->emitters[0]->action)->toBe(EmitterAction::SKIPPED)
            ->and($result->emitters[0]->reason)->toContain('Reserved path')
            ->and(file_exists($root . '/.claude/skills/injected/SKILL.md'))->toBeFalse();

        // And it must NOT be recorded as emitter-owned in the manifest.
        if (is_file($root . '/.boost/manifest.json')) {
            /** @var array{emitted: array<string, mixed>} $manifest */
            $manifest = json_decode((string) file_get_contents($root . '/.boost/manifest.json'), true, 512, JSON_THROW_ON_ERROR);
            expect($manifest['emitted'])->not->toHaveKey('.claude/skills/injected/SKILL.md');
        }
    } finally {
        rmTreeEmit($root);
    }
});

it('0.14.0: reserved agent roots hold under SUBSET engine construction (not just default) — codex high', function (): void {
    $root = makeEmitterProject();
    try {
        // Emitter targets `.claude/skills/...`; engine is constructed with ONLY
        // a Cursor target (Claude is NOT in this instance's fan-out). The reserved
        // roots come from the full static catalog, so `.claude/skills/` is still
        // reserved and the write is rejected.
        $packages = fakeVendorWithEmitter('test/root-pkg', InactiveAgentRootEmitter::class, $root . '/vendor/test/root-pkg');
        writeBoostPhpForEmitter($root, 'test/root-pkg');

        $result = (new SyncEngine([new CursorTarget()], installedPackages: $packages))->sync($root);

        expect($result->emitters[0]->action)->toBe(EmitterAction::SKIPPED)
            ->and($result->emitters[0]->reason)->toContain('Reserved path')
            ->and(file_exists($root . '/.claude/skills/injected/SKILL.md'))->toBeFalse();
    } finally {
        rmTreeEmit($root);
    }
});

it('0.14.0: a case-only emitter path rename does NOT reap the just-written live file (codex high — case-insensitive FS)', function (): void {
    $root = makeEmitterProject();
    try {
        $packages = fakeVendorWithEmitter('test/case-pkg', CaseRenameEmitter::class, $root . '/vendor/test/case-pkg');
        writeBoostPhpForEmitter($root, 'test/case-pkg');

        // Sync 1: emits `.Dummy/output.txt`, recorded under that spelling.
        (new SyncEngine([], installedPackages: $packages))->sync($root);

        // Flip the emitter to the case-only-renamed spelling `.dummy/output.txt`.
        file_put_contents($root . '/.rename-marker', '1');

        // Sync 2: the new spelling is the intended output. The prior manifest
        // entry (`.Dummy/output.txt`) must be recognized as still-intended via
        // case-folded comparison — NOT reaped as a dormant orphan (which would
        // delete the live file on a case-insensitive filesystem).
        (new SyncEngine([], installedPackages: $packages))->sync($root);

        // The live, newly-emitted output must survive on every platform.
        expect(file_exists($root . '/.dummy/output.txt'))->toBeTrue('case-only rename must not reap the live file');

        // ...AND ownership must have carried forward across the alias (codex
        // high): when the emitter later goes dormant, the file is still reaped —
        // not leaked. (On a case-insensitive FS the inode transfer applies;
        // on a case-sensitive FS the old casing was already reaped as a distinct
        // orphan and the new file is owned by fresh-write.)
        (new SyncEngine([], installedPackages: new InstalledPackages([])))->sync($root);
        expect(file_exists($root . '/.dummy/output.txt'))->toBeFalse('dormant emitter output must be reaped after a case-only rename, not leaked');
    } finally {
        rmTreeEmit($root);
    }
});

it('0.14.0: an emitter that OVERWRITES a pre-existing non-owned operator file does NOT claim it, so it is never reaped (codex high — takeover guard)', function (): void {
    $root = makeEmitterProject();
    try {
        $packages = fakeVendorWithEmitter('test/dummy-pkg', DummyEmitter::class, $root . '/vendor/test/dummy-pkg');
        writeBoostPhpForEmitter($root, 'test/dummy-pkg');

        // Operator already has a DIFFERENT file at the emitter's path.
        mkdir($root . '/.dummy', 0o755, recursive: true);
        file_put_contents($root . '/.dummy/output.txt', "operator's own content\n");

        // Sync 1: emitter overwrites it (WROTE) — a first-time takeover of a file
        // boost never owned. It must NOT be recorded as owned + a warning fires.
        $result = (new SyncEngine([], installedPackages: $packages))->sync($root);

        $diagnostics = implode("\n", array_map(static fn (Diagnostic $d): string => $d->message, $result->diagnostics));
        expect($diagnostics)->toContain('wrote over pre-existing file');

        if (is_file($root . '/.boost/manifest.json')) {
            /** @var array{emitted: array<string, mixed>} $manifest */
            $manifest = json_decode((string) file_get_contents($root . '/.boost/manifest.json'), true, 512, JSON_THROW_ON_ERROR);
            expect($manifest['emitted'])->not->toHaveKey('.dummy/output.txt');
        }

        // Sync 2: emitter package removed. The taken-over file was never claimed →
        // it must be PRESERVED, not reaped.
        (new SyncEngine([], installedPackages: new InstalledPackages([])))->sync($root);

        expect(file_exists($root . '/.dummy/output.txt'))->toBeTrue('a taken-over operator file must never be reaped');
    } finally {
        rmTreeEmit($root);
    }
});

it('0.14.0: a dormant emitter output the operator HAND-EDITED is PRESERVED, not reaped (codex high — sha-revalidation, never-lossy)', function (): void {
    $root = makeEmitterProject();
    try {
        $packages = fakeVendorWithEmitter('test/dummy-pkg', DummyEmitter::class, $root . '/vendor/test/dummy-pkg');
        writeBoostPhpForEmitter($root, 'test/dummy-pkg');

        // Sync 1: emitter writes + records .dummy/output.txt (boost-owned).
        (new SyncEngine([], installedPackages: $packages))->sync($root);
        expect(file_exists($root . '/.dummy/output.txt'))->toBeTrue();

        // Operator hand-edits the emitter output (e.g. tweaks an .mcp.json) →
        // its sha diverges from the manifest record.
        file_put_contents($root . '/.dummy/output.txt', "operator hand-tweaked this — keep it\n");

        // Sync 2: emitter package removed (dormant). Because the on-disk sha no
        // longer matches what boost recorded, ownership can't be proven → the
        // file is PRESERVED, not reaped.
        (new SyncEngine([], installedPackages: new InstalledPackages([])))->sync($root);

        expect(file_exists($root . '/.dummy/output.txt'))->toBeTrue('a hand-edited emitter output must not be reaped')
            ->and((string) file_get_contents($root . '/.dummy/output.txt'))->toContain('keep it');
    } finally {
        rmTreeEmit($root);
    }
});

it('emitters from non-allowlisted vendors never run', function (): void {
    $root = makeEmitterProject();
    try {
        $packages = fakeVendorWithEmitter(
            'forbidden/vendor',
            DummyEmitter::class,
            $root . '/vendor/forbidden/vendor',
        );

        // boost.php does NOT allowlist forbidden/vendor
        file_put_contents(
            $root . '/boost.php',
            "<?php\ndeclare(strict_types=1);\nuse SanderMuller\\BoostCore\\Config\\BoostConfig;\nreturn BoostConfig::configure();",
        );

        $result = (new SyncEngine([], installedPackages: $packages))->sync($root);

        expect($result->emitters)
            ->toBeEmpty()
            ->and(file_exists($root . '/.dummy/output.txt'))
            ->toBeFalse();
    } finally {
        rmTreeEmit($root);
    }
});
