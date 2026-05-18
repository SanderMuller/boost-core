<?php

declare(strict_types=1);

use Composer\Composer;
use Composer\Config;
use Composer\IO\BufferIO;
use Composer\Script\Event;
use SanderMuller\BoostCore\Scripts\BoostAutoSync;

function makeAutoSyncEvent(bool $devMode, string $binDir): Event
{
    $config = new Config(useEnvironment: false);
    $config->merge(['config' => ['bin-dir' => $binDir]]);

    $composer = new Composer();
    $composer->setConfig($config);

    return new Event(
        name: 'post-install-cmd',
        composer: $composer,
        io: new BufferIO(),
        devMode: $devMode,
    );
}

it('no-ops when --no-dev install', function (): void {
    $event = makeAutoSyncEvent(devMode: false, binDir: sys_get_temp_dir());

    BoostAutoSync::run($event);

    expect(true)->toBeTrue();
});

it('no-ops gracefully when boost binary is missing from bin-dir', function (): void {
    $emptyBinDir = sys_get_temp_dir() . '/boost-autosync-test-' . bin2hex(random_bytes(6));
    mkdir($emptyBinDir, 0o755, recursive: true);

    try {
        $event = makeAutoSyncEvent(devMode: true, binDir: $emptyBinDir);
        BoostAutoSync::run($event);

        expect(true)->toBeTrue();
    } finally {
        rmdir($emptyBinDir);
    }
});

it('invokes the boost binary when present and dev-mode', function (): void {
    $binDir = sys_get_temp_dir() . '/boost-autosync-bin-' . bin2hex(random_bytes(6));
    mkdir($binDir, 0o755, recursive: true);
    $sentinel = $binDir . '/sentinel.flag';
    $fakeBoost = $binDir . '/boost';

    try {
        file_put_contents(
            $fakeBoost,
            sprintf("#!/usr/bin/env sh\ntouch %s\n", escapeshellarg($sentinel)),
        );
        chmod($fakeBoost, 0o755);

        $event = makeAutoSyncEvent(devMode: true, binDir: $binDir);
        BoostAutoSync::run($event);

        expect(file_exists($sentinel))->toBeTrue();
    } finally {
        @unlink($sentinel);
        @unlink($fakeBoost);
        @rmdir($binDir);
    }
});

it('honors BOOST_SKIP_AUTOSYNC env var (run + runWithSummary)', function (): void {
    // Build a fake bin-dir with a `boost` script that would touch a sentinel
    // file if invoked. If skip-autosync is honored, neither callable should
    // touch the sentinel.
    $binDir = sys_get_temp_dir() . '/boost-autosync-skip-' . bin2hex(random_bytes(6));
    mkdir($binDir, 0o755, recursive: true);
    $sentinel = $binDir . '/sentinel.flag';
    $fakeBoost = $binDir . '/boost';

    putenv('BOOST_SKIP_AUTOSYNC=1');
    try {
        file_put_contents(
            $fakeBoost,
            sprintf("#!/usr/bin/env sh\ntouch %s\n", escapeshellarg($sentinel)),
        );
        chmod($fakeBoost, 0o755);

        $eventRun = makeAutoSyncEvent(devMode: true, binDir: $binDir);
        BoostAutoSync::run($eventRun);
        expect(file_exists($sentinel))->toBeFalse();

        $eventSum = makeAutoSyncEvent(devMode: true, binDir: $binDir);
        BoostAutoSync::runWithSummary($eventSum);
        expect(file_exists($sentinel))->toBeFalse();
    } finally {
        putenv('BOOST_SKIP_AUTOSYNC');
        @unlink($sentinel);
        @unlink($fakeBoost);
        @rmdir($binDir);
    }
});

it('logs warning on non-zero binary exit', function (): void {
    $binDir = sys_get_temp_dir() . '/boost-autosync-fail-' . bin2hex(random_bytes(6));
    mkdir($binDir, 0o755, recursive: true);
    $fakeBoost = $binDir . '/boost';

    try {
        file_put_contents($fakeBoost, "#!/usr/bin/env sh\nexit 42\n");
        chmod($fakeBoost, 0o755);

        $io = new BufferIO();
        $config = new Config(useEnvironment: false);
        $config->merge(['config' => ['bin-dir' => $binDir]]);
        $composer = new Composer();
        $composer->setConfig($config);
        $event = new Event('post-install-cmd', $composer, $io, devMode: true);

        BoostAutoSync::run($event);

        expect($io->getOutput())
            ->toContain('boost: auto-sync exited 42');
    } finally {
        @unlink($fakeBoost);
        @rmdir($binDir);
    }
});

it('runWithSummary streams the binary stdout through Composer IO on success', function (): void {
    $binDir = sys_get_temp_dir() . '/boost-autosync-sum-' . bin2hex(random_bytes(6));
    mkdir($binDir, 0o755, recursive: true);
    $fakeBoost = $binDir . '/boost';

    try {
        file_put_contents(
            $fakeBoost,
            "#!/usr/bin/env sh\necho '[OK] Sync done. wrote=3, unchanged=42.'\n",
        );
        chmod($fakeBoost, 0o755);

        $io = new BufferIO();
        $config = new Config(useEnvironment: false);
        $config->merge(['config' => ['bin-dir' => $binDir]]);
        $composer = new Composer();
        $composer->setConfig($config);
        $event = new Event('sync-ai', $composer, $io, devMode: true);

        BoostAutoSync::runWithSummary($event);

        expect($io->getOutput())
            ->toContain('[OK] Sync done. wrote=3, unchanged=42.');
    } finally {
        @unlink($fakeBoost);
        @rmdir($binDir);
    }
});

it('runWithSummary still no-ops when --no-dev', function (): void {
    $io = new BufferIO();
    $config = new Config(useEnvironment: false);
    $config->merge(['config' => ['bin-dir' => sys_get_temp_dir()]]);
    $composer = new Composer();
    $composer->setConfig($config);
    $event = new Event('sync-ai', $composer, $io, devMode: false);

    BoostAutoSync::runWithSummary($event);

    expect($io->getOutput())->toBe('');
});

it('runWithSummary emits same warning on non-zero exit as run()', function (): void {
    $binDir = sys_get_temp_dir() . '/boost-autosync-sum-fail-' . bin2hex(random_bytes(6));
    mkdir($binDir, 0o755, recursive: true);
    $fakeBoost = $binDir . '/boost';

    try {
        file_put_contents($fakeBoost, "#!/usr/bin/env sh\nexit 7\n");
        chmod($fakeBoost, 0o755);

        $io = new BufferIO();
        $config = new Config(useEnvironment: false);
        $config->merge(['config' => ['bin-dir' => $binDir]]);
        $composer = new Composer();
        $composer->setConfig($config);
        $event = new Event('sync-ai', $composer, $io, devMode: true);

        BoostAutoSync::runWithSummary($event);

        expect($io->getOutput())
            ->toContain('boost: auto-sync exited 7');
    } finally {
        @unlink($fakeBoost);
        @rmdir($binDir);
    }
});
