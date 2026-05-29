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

    // 0.10.1 noise collapse: per-vendor INFO replaced by a single summary
    // INFO. The vendor-name field is null on the summary; the count phrasing
    // ("1 of 1 allowlisted vendor(s)") carries the equivalent signal.
    expect($result['sources'])
        ->toBeEmpty()
        ->and($result['diagnostics'])->toHaveCount(1)
        ->and($result['diagnostics'][0]->level)->toBe('info')
        ->and($result['diagnostics'][0]->vendor)->toBeNull()
        ->and($result['diagnostics'][0]->message)->toContain('1 of 1 allowlisted vendor(s)')
        ->and($result['diagnostics'][0]->message)->toContain('ship no conventions-schema.json');
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

it('0.9.7 self-referential guard: boost-core in the allowlist does NOT produce a "no conventions-schema.json" INFO diagnostic — the engine ships no catalog, so the absence-of-schema is not signal', function (): void {
    // boost-core is the engine. When self-allowlisted (the common case for
    // dogfood + tooling-author projects), every sync was emitting a noise
    // INFO diagnostic for boost-core's own absence-of-schema. The guard skips
    // boost-core's vendor name before the schema-presence check fires.
    $boostCorePath = makeTempVendor('sandermuller/boost-core', null);
    $otherVendorPath = makeTempVendor('vendor/other', null);
    $scanner = makeStubPackages([
        'sandermuller/boost-core' => $boostCorePath,
        'vendor/other' => $otherVendorPath,
    ]);

    $result = (new SchemaDiscovery($scanner))->discover([
        'sandermuller/boost-core',
        'vendor/other',
    ]);

    // vendor/other still produces the INFO (it's a non-engine vendor in the
    // allowlist that genuinely doesn't ship a schema — legitimate signal).
    // boost-core is silenced. 0.10.1 noise-collapse: the count reflects only
    // non-engine vendors inspected — boost-core is skipped pre-count so the
    // "1 of 1" phrasing is honest (only vendor/other was actually inspected).
    expect($result['diagnostics'])->toHaveCount(1)
        ->and($result['diagnostics'][0]->vendor)->toBeNull()
        ->and($result['diagnostics'][0]->message)->toContain('1 of 1 allowlisted vendor(s)');
});

it('0.10.1 noise collapse: emits ONE summary INFO instead of N per-vendor INFOs when multiple allowlisted vendors lack a schema', function (): void {
    // Three vendors, none ship a schema → previously 3 per-vendor INFO lines
    // fired on every sync, inverting the signal/noise ratio. 0.10.1 collapses
    // to ONE summary INFO naming the count, leaving per-vendor detail to
    // `boost doctor` (vendor allowlist section).
    $a = makeTempVendor('vendor/a', null);
    $b = makeTempVendor('vendor/b', null);
    $c = makeTempVendor('vendor/c', null);
    $scanner = makeStubPackages([
        'vendor/a' => $a,
        'vendor/b' => $b,
        'vendor/c' => $c,
    ]);

    $result = (new SchemaDiscovery($scanner))->discover(['vendor/a', 'vendor/b', 'vendor/c']);

    expect($result['diagnostics'])->toHaveCount(1)
        ->and($result['diagnostics'][0]->level)->toBe('info')
        ->and($result['diagnostics'][0]->vendor)->toBeNull()
        ->and($result['diagnostics'][0]->message)->toContain('3 of 3 allowlisted vendor(s)')
        ->and($result['diagnostics'][0]->message)->toContain('ship no conventions-schema.json')
        ->and($result['diagnostics'][0]->message)->toContain('boost doctor');
});

it('0.10.1 noise collapse: no summary INFO when all vendors ship a schema (all-clean path stays silent)', function (): void {
    // The summary INFO is opt-in to "actually has a no-schema vendor."
    // Project with every vendor shipping a schema produces zero diagnostics —
    // signal preserved for the rare-but-clean case.
    $schemaJson = '{"type":"object"}';
    $a = makeTempVendor('vendor/a', $schemaJson);
    $b = makeTempVendor('vendor/b', $schemaJson);
    $scanner = makeStubPackages(['vendor/a' => $a, 'vendor/b' => $b]);

    $result = (new SchemaDiscovery($scanner))->discover(['vendor/a', 'vendor/b']);

    expect($result['diagnostics'])->toBeEmpty()
        ->and($result['sources'])->toHaveCount(2);
});
