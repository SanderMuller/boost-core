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
