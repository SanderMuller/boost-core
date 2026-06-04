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
            ->and($display)->not->toContain('boost --check');
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
