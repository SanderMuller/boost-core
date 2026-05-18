<?php

declare(strict_types=1);

use SanderMuller\BoostCore\Discovery\DiscoveredVendor;
use SanderMuller\BoostCore\Discovery\VendorScanner;
use SanderMuller\BoostCore\Sync\InstalledPackages;
use SanderMuller\BoostCore\Sync\PackageInfo;

function fixturePath(string $name): string
{
    return __DIR__ . '/../../Fixtures/vendor-packages/' . $name;
}

function packagesFromFixtures(string ...$names): InstalledPackages
{
    $packages = [];
    foreach ($names as $name) {
        $packages['test-fixture/' . $name] = new PackageInfo(
            'test-fixture/' . $name,
            '1.0.0',
            fixturePath($name),
        );
    }

    return new InstalledPackages($packages);
}

/**
 * @return list<DiscoveredVendor>
 */
function discoverAll(InstalledPackages $packages): array
{
    /** @var list<DiscoveredVendor> $found */
    $found = iterator_to_array(
        (new VendorScanner($packages))->discover(),
        preserve_keys: false,
    );

    return $found;
}

it('discovers skills at the convention path', function (): void {
    $found = discoverAll(packagesFromFixtures('with-skills-default-path'));

    expect($found)->toHaveCount(1)
        ->and($found[0])
        ->toBeInstanceOf(DiscoveredVendor::class)
        ->and($found[0]->name)
        ->toBe('test-fixture/with-skills-default-path')
        ->and($found[0]->publishesSkills())
        ->toBeTrue()
        ->and($found[0]->publishesGuidelines())
        ->toBeFalse()
        ->and($found[0]->skillsPath)
        ->toEndWith('/resources/boost/skills');
});

it('discovers content at custom paths declared via extra.boost.skills', function (): void {
    $found = discoverAll(packagesFromFixtures('with-custom-skills-path'));

    expect($found)->toHaveCount(1)
        ->and($found[0]->publishesSkills())
        ->toBeTrue()
        ->and($found[0]->publishesGuidelines())
        ->toBeTrue()
        ->and($found[0]->skillsPath)
        ->toEndWith('/ai/custom-skills')
        ->and($found[0]->guidelinesPath)
        ->toEndWith('/ai/custom-guidelines');
});

it('discovers guidelines-only packages', function (): void {
    $found = discoverAll(packagesFromFixtures('with-guidelines-only'));

    expect($found)->toHaveCount(1)
        ->and($found[0]->publishesSkills())
        ->toBeFalse()
        ->and($found[0]->publishesGuidelines())
        ->toBeTrue();
});

it('skips packages with no boost content', function (): void {
    $found = discoverAll(packagesFromFixtures('no-boost-content'));

    expect($found)->toBeEmpty();
});

it('skips packages with invalid composer.json without erroring', function (): void {
    $found = discoverAll(packagesFromFixtures('invalid-composer-json'));

    expect($found)->toBeEmpty();
});

it('returns multiple discovered vendors from a mixed set', function (): void {
    $found = discoverAll(packagesFromFixtures(
        'with-skills-default-path',
        'no-boost-content',
        'with-guidelines-only',
        'invalid-composer-json',
    ));

    expect($found)->toHaveCount(2);
    $names = array_map(fn (DiscoveredVendor $v): string => $v->name, $found);
    expect($names)->toEqual([
        'test-fixture/with-skills-default-path',
        'test-fixture/with-guidelines-only',
    ]);
});

it('does NOT filter by allowlist — that is the SyncEngine concern', function (): void {
    // VendorScanner returns all candidates with content. Filtering happens downstream.
    $found = discoverAll(packagesFromFixtures(
        'with-skills-default-path',
        'with-custom-skills-path',
    ));

    expect($found)->toHaveCount(2);
});
