<?php

declare(strict_types=1);

use Composer\Console\Application as ComposerApplication;
use SanderMuller\BoostCore\Commands\InitCommand;
use SanderMuller\BoostCore\Config\BoostConfigBuilder;
use SanderMuller\BoostCore\Config\BoostConfigLoader;
use Symfony\Component\Console\Tester\CommandTester;

/**
 * @return array{exit: int, tester: CommandTester}
 */
function runInit(string $dir, bool $force = false): array
{
    $command = new InitCommand;
    $app = new ComposerApplication;
    $app->addCommand($command);

    $tester = new CommandTester($command);
    $args = ['--working-dir' => $dir];
    if ($force) {
        $args['--force'] = true;
    }
    $exit = $tester->execute($args);

    return ['exit' => $exit, 'tester' => $tester];
}

function initTempDir(): string
{
    $dir = sys_get_temp_dir().'/boost-init-'.bin2hex(random_bytes(8));
    mkdir($dir, 0o755, recursive: true);

    return $dir;
}

function rmTreeInit(string $path): void
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
        $full = $path.'/'.$entry;
        if (is_dir($full) && ! is_link($full)) {
            rmTreeInit($full);
        } else {
            unlink($full);
        }
    }
    rmdir($path);
}

it('generates a boost.php at the project root', function (): void {
    $dir = initTempDir();
    try {
        $result = runInit($dir);

        expect($result['exit'])->toBe(0);
        expect(file_exists($dir.'/boost.php'))->toBeTrue();

        $contents = (string) file_get_contents($dir.'/boost.php');
        expect($contents)->toContain('BoostConfig::configure()');
        expect($contents)->toContain('withAgents');
        expect($contents)->toContain('withAllowedVendors');
    } finally {
        rmTreeInit($dir);
    }
});

it('the generated file loads as a valid BoostConfigBuilder', function (): void {
    $dir = initTempDir();
    try {
        runInit($dir);

        $config = (new BoostConfigLoader)->load($dir);

        expect($config->agents)->toBe([]);
        expect($config->allowedVendors)->toBe([]);
        expect($config->disabledEmitters)->toBe([]);
    } finally {
        rmTreeInit($dir);
    }
});

it('refuses to overwrite an existing boost.php without --force', function (): void {
    $dir = initTempDir();
    try {
        file_put_contents($dir.'/boost.php', '<?php // existing content');

        $result = runInit($dir);

        expect($result['exit'])->toBe(1);
        expect(file_get_contents($dir.'/boost.php'))->toBe('<?php // existing content');
    } finally {
        rmTreeInit($dir);
    }
});

it('overwrites with --force', function (): void {
    $dir = initTempDir();
    try {
        file_put_contents($dir.'/boost.php', '<?php // existing content');

        $result = runInit($dir, force: true);

        expect($result['exit'])->toBe(0);
        expect((string) file_get_contents($dir.'/boost.php'))->toContain('BoostConfig::configure()');
    } finally {
        rmTreeInit($dir);
    }
});

it('starter config builder is usable (no syntax errors)', function (): void {
    $dir = initTempDir();
    try {
        runInit($dir);

        $result = require $dir.'/boost.php';
        expect($result)->toBeInstanceOf(BoostConfigBuilder::class);
    } finally {
        rmTreeInit($dir);
    }
});
