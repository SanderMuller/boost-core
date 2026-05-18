<?php

declare(strict_types=1);

use Symfony\Component\Process\Process;

/**
 * Regression guard: bin/boost must run in end-user (non-dev) installs
 * where composer/composer is absent from vendor/. The 0.1.1 standalone
 * bin fataled with "Interface Composer\Plugin\Capability\CommandProvider
 * not found" because it transitively loaded composer-only classes.
 */
it('bin/boost runs in an end-user install without composer/composer in vendor', function (): void {
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
            'config' => [
                'allow-plugins' => [
                    'sandermuller/boost-core' => true,
                ],
            ],
        ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));

        $install = Process::fromShellCommandline(
            'cd ' . escapeshellarg($fixture) . ' && composer install --no-dev --no-interaction --quiet 2>&1',
        );
        $install->run();

        if (! $install->isSuccessful()) {
            throw new RuntimeException('composer install --no-dev failed: ' . $install->getOutput() . $install->getErrorOutput());
        }

        expect(is_dir($fixture . '/vendor/composer/composer'))->toBeFalse();

        $list = Process::fromShellCommandline(escapeshellarg($fixture) . '/vendor/bin/boost list 2>&1');
        $list->run();
        $outputStr = $list->getOutput() . $list->getErrorOutput();

        expect($list->getExitCode())->toBe(0, 'bin/boost exited non-zero: ' . $outputStr);
        expect($outputStr)
            ->toContain('sync')
            ->toContain('init')
            ->toContain('install')
            ->toContain('scan')
            ->toContain('doctor')
            ->toContain('new')
            ->not->toContain('Fatal error');
    } finally {
        cleanupTestDir($fixture);
    }
});
