<?php

declare(strict_types=1);

use SanderMuller\BoostCore\Discovery\EmitterDiscovery;
use SanderMuller\BoostCore\Sync\InstalledPackages;
use SanderMuller\BoostCore\Sync\PackageInfo;
use SanderMuller\BoostCore\Tests\Fixtures\Emitters\DummyEmitter;
use SanderMuller\BoostCore\Tests\Fixtures\Emitters\SkippingEmitter;

/**
 * @param  array<string, mixed>  $composerJson
 */
function fixtureVendor(array $composerJson): string
{
    $dir = sys_get_temp_dir() . '/boost-emitter-disc-' . bin2hex(random_bytes(6));
    mkdir($dir, 0o755, recursive: true);
    file_put_contents($dir . '/composer.json', json_encode($composerJson, JSON_THROW_ON_ERROR));

    return $dir;
}

it('discovers emitters from allowlisted vendors', function (): void {
    $vendorDir = fixtureVendor([
        'name' => 'test/with-emitter',
        'extra' => [
            'boost' => [
                'emitters' => [DummyEmitter::class],
            ],
        ],
    ]);

    try {
        $packages = new InstalledPackages([
            'test/with-emitter' => new PackageInfo('test/with-emitter', '1.0.0', $vendorDir),
        ]);

        $discovered = (new EmitterDiscovery($packages))->discover(['test/with-emitter']);

        expect($discovered)->toHaveCount(1);
        expect($discovered[0]->vendor)->toBe('test/with-emitter');
        expect($discovered[0]->fqcn)->toBe(DummyEmitter::class);
        expect($discovered[0]->emitter)->toBeInstanceOf(DummyEmitter::class);
    } finally {
        @unlink($vendorDir . '/composer.json');
        @rmdir($vendorDir);
    }
});

it('skips non-allowlisted vendors even if they declare emitters', function (): void {
    $vendorDir = fixtureVendor([
        'name' => 'forbidden/vendor',
        'extra' => ['boost' => ['emitters' => [DummyEmitter::class]]],
    ]);

    try {
        $packages = new InstalledPackages([
            'forbidden/vendor' => new PackageInfo('forbidden/vendor', '1.0.0', $vendorDir),
        ]);

        // Allowlist does NOT include forbidden/vendor.
        $discovered = (new EmitterDiscovery($packages))->discover(['some/other-vendor']);

        expect($discovered)->toBe([]);
    } finally {
        @unlink($vendorDir . '/composer.json');
        @rmdir($vendorDir);
    }
});

it('silently skips emitters whose class does not autoload', function (): void {
    $vendorDir = fixtureVendor([
        'name' => 'test/missing-class',
        'extra' => ['boost' => ['emitters' => ['Acme\\NonExistent\\Emitter']]],
    ]);

    try {
        $packages = new InstalledPackages([
            'test/missing-class' => new PackageInfo('test/missing-class', '1.0.0', $vendorDir),
        ]);

        $discovered = (new EmitterDiscovery($packages))->discover(['test/missing-class']);

        expect($discovered)->toBe([]);
    } finally {
        @unlink($vendorDir . '/composer.json');
        @rmdir($vendorDir);
    }
});

it('discovers multiple emitters in one vendor', function (): void {
    $vendorDir = fixtureVendor([
        'name' => 'test/many-emitters',
        'extra' => [
            'boost' => [
                'emitters' => [DummyEmitter::class, SkippingEmitter::class],
            ],
        ],
    ]);

    try {
        $packages = new InstalledPackages([
            'test/many-emitters' => new PackageInfo('test/many-emitters', '1.0.0', $vendorDir),
        ]);

        $discovered = (new EmitterDiscovery($packages))->discover(['test/many-emitters']);

        expect($discovered)->toHaveCount(2);
    } finally {
        @unlink($vendorDir . '/composer.json');
        @rmdir($vendorDir);
    }
});

it('handles vendors with no emitters section gracefully', function (): void {
    $vendorDir = fixtureVendor([
        'name' => 'test/no-emitters',
        'type' => 'library',
    ]);

    try {
        $packages = new InstalledPackages([
            'test/no-emitters' => new PackageInfo('test/no-emitters', '1.0.0', $vendorDir),
        ]);

        $discovered = (new EmitterDiscovery($packages))->discover(['test/no-emitters']);

        expect($discovered)->toBe([]);
    } finally {
        @unlink($vendorDir . '/composer.json');
        @rmdir($vendorDir);
    }
});
