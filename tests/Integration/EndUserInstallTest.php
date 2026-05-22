<?php declare(strict_types=1);

use Symfony\Component\Process\Process;

/**
 * Regression guard: bin/boost must run in end-user (non-dev) installs
 * where composer/composer is absent from vendor/. The 0.1.1 standalone
 * bin fataled with "Interface Composer\Plugin\Capability\CommandProvider
 * not found" because it transitively loaded composer-only classes — and
 * since the Pattern C migration (0.6.0) boost-core is a plain `library`,
 * so the standalone bin is the *only* command surface. This also drives
 * a real `vendor/bin/boost sync` end-to-end against the installed copy.
 */
it('bin/boost runs and syncs in an end-user install without composer/composer in vendor', function (): void {
    $packageRoot = dirname(__DIR__, 2);
    $fixture = sys_get_temp_dir() . '/boost-end-user-' . bin2hex(random_bytes(6));
    mkdir($fixture, 0o755, recursive: true);

    try {
        file_put_contents($fixture . '/composer.json', json_encode([
            'name' => 'boost-core/end-user-fixture',
            'require' => [
                'sandermuller/boost-core' => '*',
            ],
            'repositories' => [
                [
                    'type' => 'path',
                    'url' => $packageRoot,
                    'options' => ['symlink' => false],
                ],
            ],
            'minimum-stability' => 'dev',
        ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));

        $install = Process::fromShellCommandline(
            'cd ' . escapeshellarg($fixture) . ' && composer install --no-dev --no-interaction --quiet 2>&1',
        );
        $install->run();

        if (! $install->isSuccessful()) {
            throw new RuntimeException('composer install --no-dev failed: ' . $install->getOutput() . $install->getErrorOutput());
        }

        expect($fixture . '/vendor/composer/composer')->not->toBeDirectory();

        $list = Process::fromShellCommandline(escapeshellarg($fixture) . '/vendor/bin/boost list 2>&1');
        $list->run();
        $outputStr = $list->getOutput() . $list->getErrorOutput();

        expect($list->getExitCode())->toBe(0, 'bin/boost exited non-zero: ' . $outputStr)
            ->and($outputStr)
            ->toContain('sync')
            ->toContain('install')
            ->toContain('scan')
            ->toContain('doctor')
            ->toContain('new')->not->toContain('Fatal error');

        // The installed boost-core syncs end-to-end via its own vendor/bin/boost.
        file_put_contents($fixture . '/boost.php', <<<'PHP'
            <?php declare(strict_types=1);

            use SanderMuller\BoostCore\Config\BoostConfig;
            use SanderMuller\BoostCore\Enums\Agent;

            return BoostConfig::configure()->withAgents([Agent::CLAUDE_CODE]);
            PHP);
        mkdir($fixture . '/.ai/skills', 0o755, recursive: true);
        file_put_contents(
            $fixture . '/.ai/skills/dispatched.md',
            "---\nname: dispatched\n---\nProves an installed boost-core syncs.\n",
        );

        $sync = Process::fromShellCommandline(
            escapeshellarg($fixture) . '/vendor/bin/boost sync --working-dir=' . escapeshellarg($fixture) . ' 2>&1',
        );
        $sync->run();
        $syncOutput = $sync->getOutput() . $sync->getErrorOutput();

        expect($sync->getExitCode())->toBe(0, 'vendor/bin/boost sync failed: ' . $syncOutput)
            ->and(file_exists($fixture . '/.claude/skills/dispatched/SKILL.md'))->toBeTrue();
    } finally {
        cleanupTestDir($fixture);
    }
});
