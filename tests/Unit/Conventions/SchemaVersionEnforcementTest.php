<?php declare(strict_types=1);

use SanderMuller\BoostCore\Conventions\ConventionsSchema;
use SanderMuller\BoostCore\Conventions\Diagnostic;
use SanderMuller\BoostCore\Conventions\VendorSchemaSource;

/**
 * Schema-version handshake enforcement (spec §3.9) — the gate that was dead code
 * (VersionMatcher::satisfies had no caller) until 0.22.0. An out-of-range vendor
 * is dropped from `applied` + gets an error diagnostic; a null/'*' range never
 * trips (the no-false-trip common case).
 */
function schemaSource(string $vendor, ?string $required): VendorSchemaSource
{
    $schema = ['properties' => ["{$vendor}_slot" => ['type' => 'string']]];
    if ($required !== null) {
        $schema['metadata'] = ['schema-required' => $required];
    }

    return new VendorSchemaSource($vendor, "/fake/{$vendor}/conventions-schema.json", $schema);
}

it('drops an out-of-range vendor and emits an error naming it', function (): void {
    // sources ^1 + ^2 → seed=2; ^1 excludes 2 → out of range.
    $result = ConventionsSchema::enforceSchemaVersion(
        [schemaSource('acme/old', '^1'), schemaSource('acme/new', '^2')],
        hostVersion: 2,
    );

    $appliedVendors = array_map(static fn (VendorSchemaSource $s): string => $s->vendorName, $result['applied']);
    expect($appliedVendors)->toBe(['acme/new'])
        ->and($result['diagnostics'])->toHaveCount(1);

    $diagnostic = $result['diagnostics'][0];
    expect($diagnostic->isError())->toBeTrue()
        ->and($diagnostic->vendor)->toBe('acme/old')
        ->and($diagnostic->message)->toContain('schema-version')
        ->and($diagnostic->message)->toContain('^1')
        ->and($diagnostic->message)->toContain('2');
});

it('applies every vendor + emits nothing when all ranges are satisfied', function (): void {
    $result = ConventionsSchema::enforceSchemaVersion(
        [schemaSource('acme/a', '^2'), schemaSource('acme/b', '>=1')],
        hostVersion: 2,
    );

    expect($result['applied'])->toHaveCount(2)
        ->and($result['diagnostics'])
        ->toBeEmpty();
});

it('never trips on a vendor with no schema-required (null / * / empty)', function (): void {
    foreach ([null, '*', ''] as $required) {
        $result = ConventionsSchema::enforceSchemaVersion([schemaSource('acme/free', $required)], hostVersion: 99);
        expect($result['applied'])->toHaveCount(1)
            ->and($result['diagnostics'])
            ->toBeEmpty();
    }
});

it('keeps the highest-major vendor, drops a lower-major one (seed direction)', function (): void {
    // ^1 + >=3 → seed=3; satisfies(3,"^1")=false (out), satisfies(3,">=3")=true (in).
    $result = ConventionsSchema::enforceSchemaVersion(
        [schemaSource('acme/v1', '^1'), schemaSource('acme/v3', '>=3')],
        hostVersion: 3,
    );

    $appliedVendors = array_map(static fn (VendorSchemaSource $s): string => $s->vendorName, $result['applied']);
    expect($appliedVendors)->toBe(['acme/v3'])
        ->and($result['diagnostics'][0]->vendor)->toBe('acme/v1');
});

it('emits error-level diagnostics (gates validate --strict / sync --check)', function (): void {
    $result = ConventionsSchema::enforceSchemaVersion([schemaSource('acme/old', '^1'), schemaSource('acme/new', '^2')], hostVersion: 2);

    expect($result['diagnostics'][0])->toBeInstanceOf(Diagnostic::class)
        ->and($result['diagnostics'][0]->isError())->toBeTrue();
});
