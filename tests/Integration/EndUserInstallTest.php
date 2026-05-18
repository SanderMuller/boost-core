<?php

declare(strict_types=1);

/**
 * Verifies bin/boost works in an end-user install where composer/composer
 * is NOT in vendor/ (it's require-dev of boost-core, dropped by --no-dev).
 *
 * Regression guard for 0.1.1: standalone bin loaded BoostCoreCommandProvider,
 * which `implements Composer\Plugin\Capability\CommandProvider` → fatal in
 * non-dev installs.
 */
function rmDirRecursive(string $path): void
{
    if (! is_dir($path)) {
        return;
    }
    $items = scandir($path) ?: [];
    foreach ($items as $item) {
        if ($item === '.' || $item === '..') {
            continue;
        }
        $full = $path . '/' . $item;
        is_dir($full) && ! is_link($full) ? rmDirRecursive($full) : @unlink($full);
    }
    @rmdir($path);
}

it('bin/boost runs in an end-user install without composer/composer in vendor', function () {
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

    $install = [];
    $exit = 0;
    exec(
        sprintf('cd %s && composer install --no-dev --no-interaction --quiet 2>&1', escapeshellarg($fixture)),
        $install,
        $exit,
    );

    if ($exit !== 0) {
        rmDirRecursive($fixture);
        $this->fail('composer install --no-dev failed: ' . implode("\n", $install));
    }

    // composer/composer must NOT be in vendor/ (require-dev was dropped)
    expect(is_dir($fixture . '/vendor/composer/composer'))->toBeFalse();

    $output = [];
    $exit = 0;
    exec(sprintf('%s/vendor/bin/boost list 2>&1', escapeshellarg($fixture)), $output, $exit);
    $outputStr = implode("\n", $output);

    rmDirRecursive($fixture);

    expect($exit)->toBe(0, 'bin/boost exited non-zero: ' . $outputStr);
    expect($outputStr)
        ->toContain('sync')
        ->toContain('init')
        ->toContain('install')
        ->toContain('scan')
        ->toContain('doctor')
        ->toContain('new')
        ->not->toContain('Fatal error');
});
