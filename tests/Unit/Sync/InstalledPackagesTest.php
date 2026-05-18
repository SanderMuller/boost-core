<?php

declare(strict_types=1);

use SanderMuller\BoostCore\Sync\InstalledPackages;
use SanderMuller\BoostCore\Sync\PackageInfo;

function fakePackages(): InstalledPackages
{
    return new InstalledPackages([
        'foo/bar' => new PackageInfo('foo/bar', '1.2.3', '/tmp/foo-bar'),
        'baz/qux' => new PackageInfo('baz/qux', '2.0.0', '/tmp/baz-qux'),
    ]);
}

it('reports installed packages via has()', function (): void {
    $packages = fakePackages();
    expect($packages->has('foo/bar'))->toBeTrue()
        ->and($packages->has('baz/qux'))
        ->toBeTrue()
        ->and($packages->has('not/installed'))
        ->toBeFalse();
});

it('returns version() for installed packages, null otherwise', function (): void {
    $packages = fakePackages();
    expect($packages->version('foo/bar'))->toBe('1.2.3')
        ->and($packages->version('not/installed'))
        ->toBeNull();
});

it('returns path() for installed packages', function (): void {
    $packages = fakePackages();
    expect($packages->path('foo/bar'))->toBe('/tmp/foo-bar');
});

it('throws on path() for uninstalled packages', function (): void {
    fakePackages()->path('not/installed');
})->throws(LogicException::class, 'not/installed');

it('returns all() as iterable of PackageInfo', function (): void {
    $packages = fakePackages();
    $names = [];
    foreach ($packages->all() as $pkg) {
        expect($pkg)->toBeInstanceOf(PackageInfo::class);
        $names[] = $pkg->name;
    }

    expect($names)->toEqual(['foo/bar', 'baz/qux']);
});

it('builds from composer runtime API', function (): void {
    $packages = InstalledPackages::fromComposer();
    // boost-core's dev deps include phpunit and laravel/prompts; assert at least one known dep is present.
    expect($packages->has('laravel/prompts'))->toBeTrue();
    expect($packages->path('laravel/prompts'))->toBeDirectory();
});
