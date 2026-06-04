<?php declare(strict_types=1);

use SanderMuller\BoostCore\Commands\SyncCommand;
use Symfony\Component\Console\Tester\CommandTester;

/**
 * Hidden-contract guard: `BoostAutoSync::summaryReportsChange()` regex-parses
 * the binary's `wrote=<n>, …, deleted=<n>` summary to decide whether to stream
 * output or stay silent on a no-op install. That couples the post-install hook
 * to SyncCommand's exact summary phrasing — an innocent wording edit would
 * silently break the silent-on-no-op behavior. The parser is tested in
 * BoostAutoSyncTest with a fake binary; this pins the PRODUCER side so the two
 * can't drift.
 */
it('emits the wrote=/unchanged=/deleted= summary BoostAutoSync parses', function (): void {
    $dir = sys_get_temp_dir() . '/boost-sync-summary-' . bin2hex(random_bytes(8));
    mkdir($dir, 0o755, recursive: true);
    file_put_contents(
        $dir . '/boost.php',
        "<?php\nuse SanderMuller\\BoostCore\\Config\\BoostConfig;\nuse SanderMuller\\BoostCore\\Enums\\Agent;\nreturn BoostConfig::configure()->withAgents([Agent::CLAUDE_CODE]);\n",
    );

    try {
        $tester = new CommandTester(new SyncCommand());
        $tester->execute(['--working-dir' => $dir]);

        // The exact shape `BoostAutoSync::summaryReportsChange()` matches.
        expect($tester->getDisplay())->toMatch('/wrote=\d+, unchanged=\d+, deleted=\d+/');
    } finally {
        $it = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($dir, FilesystemIterator::SKIP_DOTS),
            RecursiveIteratorIterator::CHILD_FIRST,
        );
        /** @var SplFileInfo $f */
        foreach ($it as $f) {
            $f->isDir() ? @rmdir($f->getPathname()) : @unlink($f->getPathname());
        }

        @rmdir($dir);
    }
});

it('lists the skipped symlink paths inline and references no nonexistent command (laravel-queue-insights)', function (): void {
    $dir = sys_get_temp_dir() . '/boost-symlink-warn-' . bin2hex(random_bytes(8));
    mkdir($dir . '/.ai/skills', 0o755, recursive: true);
    file_put_contents(
        $dir . '/boost.php',
        "<?php\nuse SanderMuller\\BoostCore\\Config\\BoostConfig;\nuse SanderMuller\\BoostCore\\Enums\\Agent;\nreturn BoostConfig::configure()->withAgents([Agent::CLAUDE_CODE]);\n",
    );
    file_put_contents($dir . '/.ai/skills/foo.md', "---\nname: foo\n---\nbody\n");

    // Make `.claude` a user-placed symlink: the write target `.claude/skills/foo/SKILL.md`
    // has a symlinked path segment, so FileWriter skips it (WriteAction::SKIPPED_SYMLINK).
    mkdir($dir . '/elsewhere', 0o755, recursive: true);
    symlink($dir . '/elsewhere', $dir . '/.claude');

    try {
        $tester = new CommandTester(new SyncCommand());
        $tester->execute(['--working-dir' => $dir]);
        $display = $tester->getDisplay();

        // 0.23.0: a live symlink skip is a NOTE ("preserved by design"), not a warning.
        expect($display)->toContain('preserved by design')
            // names the actual skipped path inline …
            ->and($display)->toContain('.claude/skills/foo/SKILL.md')
            // … instead of pointing at a command that does not exist.
            ->and($display)->not->toContain('boost --check')
            // 1.0: the cleanup `find` targets the ACTUAL root of the skipped path
            // (`.claude` here), not a hardcoded `.claude .agents .cursor` triplet
            // that would mislead on other agent roots. Absence assertions are
            // wrap-robust (Symfony's NOTE block injects `! ` line-prefixes that
            // split a positive substring match unpredictably by terminal width).
            ->and($display)->not->toContain('.agents')
            ->and($display)->not->toContain('.cursor');
    } finally {
        $it = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($dir, FilesystemIterator::SKIP_DOTS),
            RecursiveIteratorIterator::CHILD_FIRST,
        );
        /** @var SplFileInfo $f */
        foreach ($it as $f) {
            $f->isLink() || $f->isFile() ? @unlink($f->getPathname()) : @rmdir($f->getPathname());
        }

        @rmdir($dir);
    }
});

it('0.23.x sync --check FAILS on a conv-token leaked through a symlinked skill output (#146, hihaho)', function (): void {
    // A skill whose OUTPUT path is a symlink is SKIPPED_SYMLINK by the writer, so
    // the symlink's target (here a source carrying a raw boost:conv token) is what
    // the agent reads — the token leaks verbatim. `sync --check` must catch it
    // (parity with `validate --strict`), even though the engine's inline self-check
    // can't see it (that content was never written).
    $dir = sys_get_temp_dir() . '/boost-leak-symlink-' . bin2hex(random_bytes(8));
    mkdir($dir . '/.ai/skills/foo', 0o755, recursive: true);
    file_put_contents(
        $dir . '/boost.php',
        "<?php\nuse SanderMuller\\BoostCore\\Config\\BoostConfig;\nuse SanderMuller\\BoostCore\\Enums\\Agent;\nreturn BoostConfig::configure()->withAgents([Agent::CLAUDE_CODE]);\n",
    );
    // Clean source → a clean first sync (no leak from the engine).
    file_put_contents($dir . '/.ai/skills/foo/SKILL.md', "---\nname: foo\n---\n# Foo\n");

    try {
        (new CommandTester(new SyncCommand()))->execute(['--working-dir' => $dir]);

        // Now make the EMITTED Claude output a symlink to a source that DOES carry a
        // raw boost:conv token — the leak vector. The next sync skips it (symlink),
        // so the token never resolves; --check's on-disk scan (symlink-following)
        // must surface it.
        $leakSource = $dir . '/.ai/skills/foo/leaked-source.md';
        file_put_contents($leakSource, "---\nname: foo\n---\n<!--boost:conv path=\"x.y\" mode=\"inline\"-->\n");
        $emitted = $dir . '/.claude/skills/foo/SKILL.md';
        @unlink($emitted);
        symlink($leakSource, $emitted);

        $tester = new CommandTester(new SyncCommand());
        $exit = $tester->execute(['--working-dir' => $dir, '--check' => true]);

        expect($exit)->toBe(1)
            ->and($tester->getDisplay())->toContain('leaked conventions token');
    } finally {
        $it = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($dir, FilesystemIterator::SKIP_DOTS),
            RecursiveIteratorIterator::CHILD_FIRST,
        );
        /** @var SplFileInfo $f */
        foreach ($it as $f) {
            $f->isLink() || $f->isFile() ? @unlink($f->getPathname()) : @rmdir($f->getPathname());
        }

        @rmdir($dir);
    }
});
