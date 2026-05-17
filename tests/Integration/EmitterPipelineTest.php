<?php

declare(strict_types=1);

use SanderMuller\BoostCore\Sync\EmitterAction;
use SanderMuller\BoostCore\Sync\InstalledPackages;
use SanderMuller\BoostCore\Sync\PackageInfo;
use SanderMuller\BoostCore\Sync\SyncEngine;
use SanderMuller\BoostCore\Tests\Fixtures\Emitters\DummyEmitter;
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
        if ($entry === '.' || $entry === '..') {
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

        expect($result->emitters)->toHaveCount(1);
        expect($result->emitters[0]->action)->toBe(EmitterAction::WROTE);
        expect($result->emitters[0]->fqcn)->toBe(DummyEmitter::class);
        expect($result->emitters[0]->vendor)->toBe('test/dummy-pkg');
        expect(file_exists($root . '/.dummy/output.txt'))->toBeTrue();
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

        expect($result->emitters)->toHaveCount(1);
        expect($result->emitters[0]->action)->toBe(EmitterAction::SKIPPED);
        expect($result->hasErrors())->toBeFalse();
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

        expect($result->emitters)->toHaveCount(1);
        expect($result->emitters[0]->action)->toBe(EmitterAction::ERRORED);
        expect($result->emitters[0]->reason)->toContain('Deliberate failure');
        expect($result->hasErrors())->toBeTrue(); // errored emitters count as errors
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

        expect($result->emitters)->toHaveCount(1);
        expect($result->emitters[0]->action)->toBe(EmitterAction::DISABLED);
        expect(file_exists($root . '/.dummy/output.txt'))->toBeFalse();
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

        expect($result->emitters)->toHaveCount(1);
        expect($result->emitters[0]->action)->toBe(EmitterAction::WOULD_WRITE);
        expect($result->hasDrift())->toBeTrue();
        expect(file_exists($root . '/.dummy/output.txt'))->toBeFalse();
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

        expect($result->emitters)->toBe([]);
        expect(file_exists($root . '/.dummy/output.txt'))->toBeFalse();
    } finally {
        rmTreeEmit($root);
    }
});
