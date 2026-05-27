<?php declare(strict_types=1);

use SanderMuller\BoostCore\Conventions\SchemaDiscovery;
use SanderMuller\BoostCore\Sync\InstalledPackages;
use SanderMuller\BoostCore\Sync\PackageInfo;

function makeTempVendor(string $name, ?string $schemaJson): string
{
    $root = sys_get_temp_dir() . '/schema-discovery-' . bin2hex(random_bytes(8));
    $vendorPath = $root . '/vendor/' . $name;
    if (! is_dir($vendorPath)) {
        mkdir($vendorPath, 0o777, true);
    }

    file_put_contents($vendorPath . '/composer.json', json_encode([
        'name' => $name,
        'extra' => ['boost' => ['skills' => 'resources/boost/skills']],
    ]));

    if ($schemaJson !== null) {
        $schemaDir = $vendorPath . '/resources/boost';
        if (! is_dir($schemaDir)) {
            mkdir($schemaDir, 0o777, true);
        }

        file_put_contents($schemaDir . '/conventions-schema.json', $schemaJson);
    }

    return $vendorPath;
}

/**
 * @param  array<string, string>  $vendors  name → installPath
 */
function makeStubPackages(array $vendors): InstalledPackages
{
    /** @var array<string, PackageInfo> $packages */
    $packages = [];
    foreach ($vendors as $name => $installPath) {
        $packages[$name] = new PackageInfo(
            name: $name,
            version: '1.0.0',
            installPath: $installPath,
        );
    }

    return new InstalledPackages($packages);
}

it('returns empty when no vendors are allowlisted', function (): void {
    $vendorPath = makeTempVendor('vendor/foo', '{}');
    $scanner = makeStubPackages(['vendor/foo' => $vendorPath]);

    $result = (new SchemaDiscovery($scanner))->discover([]);
    expect($result)
        ->toMatchArray(['sources' => [], 'diagnostics' => []]);
});

it('loads a vendor schema present at the conventional path', function (): void {
    $schemaJson = (string) json_encode([
        'type' => 'object',
        'properties' => ['foo' => ['type' => 'string']],
    ]);

    $vendorPath = makeTempVendor('vendor/foo', $schemaJson);
    $scanner = makeStubPackages(['vendor/foo' => $vendorPath]);

    $result = (new SchemaDiscovery($scanner))->discover(['vendor/foo']);

    expect($result['sources'])->toHaveCount(1)
        ->and($result['sources'][0]->vendorName)
        ->toBe('vendor/foo')
        ->and($result['sources'][0]->schema)
        ->toHaveKey('properties.foo')
        ->and($result['diagnostics'])
        ->toBeEmpty();
});

it('emits an info diagnostic for a vendor without conventions-schema.json', function (): void {
    $vendorPath = makeTempVendor('vendor/foo', null);
    $scanner = makeStubPackages(['vendor/foo' => $vendorPath]);

    $result = (new SchemaDiscovery($scanner))->discover(['vendor/foo']);

    expect($result['sources'])
        ->toBeEmpty()
        ->and($result['diagnostics'])->toHaveCount(1)
        ->and($result['diagnostics'][0]->level)->toBe('info')
        ->and($result['diagnostics'][0]->vendor)->toBe('vendor/foo');
});

it('returns a warning diagnostic for malformed JSON, skipping that vendor', function (): void {
    $vendorPath = makeTempVendor('vendor/bad', '{ not valid json');
    $scanner = makeStubPackages(['vendor/bad' => $vendorPath]);

    $result = (new SchemaDiscovery($scanner))->discover(['vendor/bad']);

    expect($result['sources'])
        ->toBeEmpty()
        ->and($result['diagnostics'])
        ->toHaveCount(1)
        ->and($result['diagnostics'][0]->level)
        ->toBe('warning')
        ->and($result['diagnostics'][0]->vendor)
        ->toBe('vendor/bad');
});

it('preserves allowlist order in returned sources', function (): void {
    $schemaJson = '{"type":"object"}';
    $a = makeTempVendor('vendor/a', $schemaJson);
    $b = makeTempVendor('vendor/b', $schemaJson);
    $scanner = makeStubPackages(['vendor/a' => $a, 'vendor/b' => $b]);

    $result = (new SchemaDiscovery($scanner))->discover(['vendor/b', 'vendor/a']);

    expect($result['sources'][0]->vendorName)->toBe('vendor/b')
        ->and($result['sources'][1]->vendorName)
        ->toBe('vendor/a');
});
