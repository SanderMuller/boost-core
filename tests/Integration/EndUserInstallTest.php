<?php

declare(strict_types=1);

use Symfony\Component\Process\Process;

/**
 * Verifies bin/boost runs in an end-user install without composer/composer
 * in vendor — the bug that caused the original 1.0.0 release to fail for
 * users who installed without --dev (composer/composer is dev-only, but
 * boost-core's CommandProvider previously referenced
 * `Composer\Plugin\Capability\CommandProvider` directly, causing a fatal
 * in non-dev installs.
 */
function rmDirRecursive(string $path): void
{
    if (! is_dir($path)) {
        return;
    }
    $items = scandir($path);
    if ($items === false) {
        return;
    }
    foreach ($items as $item) {
        if ($item === '.' || $item === '..') {
            continue;
        }
        $full = $path . '/' . $item;
        if (is_dir($full) && ! is_link($full)) {
            rmDirRecursive($full);
        } else {
            @unlink($full);
        }
    }
    @rmdir($path);
}

it('bin/boost runs in an end-user install without composer/composer in vendor', function (): void {
    $packageRoot = dirname(__DIR__, 2);
    $fixture = sys_get_temp_dir() . '/boost-end-user-' . bin2hex(random_bytes(6));
    mkdir($fixture, 0o755, recursive: true);

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
        rmDirRecursive($fixture);
        throw new RuntimeException('composer install --no-dev failed: ' . $install->getOutput() . $install->getErrorOutput());
    }

    expect(is_dir($fixture . '/vendor/composer/composer'))->toBeFalse();

    $list = Process::fromShellCommandline(escapeshellarg($fixture) . '/vendor/bin/boost list 2>&1');
    $list->run();
    $outputStr = $list->getOutput() . $list->getErrorOutput();

    rmDirRecursive($fixture);

    expect($list->getExitCode())->toBe(0, 'bin/boost exited non-zero: ' . $outputStr);
    expect($outputStr)
        ->toContain('sync')
        ->toContain('init')
        ->toContain('install')
        ->toContain('scan')
        ->toContain('doctor')
        ->toContain('new')
        ->not->toContain('Fatal error');
});
