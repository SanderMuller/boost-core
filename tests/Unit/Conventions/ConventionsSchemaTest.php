<?php declare(strict_types=1);

use SanderMuller\BoostCore\Conventions\ConventionsSchema;
use SanderMuller\BoostCore\Conventions\Diagnostic;
use SanderMuller\BoostCore\Conventions\SlotTypeMismatchException;
use SanderMuller\BoostCore\Conventions\VendorSchemaSource;

/**
 * @param  array<mixed, mixed>  $schema
 */
function vendorSource(string $name, array $schema): VendorSchemaSource
{
    return new VendorSchemaSource(
        vendorName: $name,
        schemaPath: "/fake/{$name}/conventions-schema.json",
        schema: $schema,
    );
}

it('composes a single vendor with synthetic schema-version injection', function (): void {
    $source = vendorSource('sandermuller/boost-skills', [
        'type' => 'object',
        'properties' => [
            'jira' => ['type' => 'object', 'properties' => ['project_key' => ['type' => 'string']]],
        ],
        'required' => ['jira'],
    ]);

    $composed = (new ConventionsSchema([$source]))->compose();

    expect($composed)->toHaveKey('properties.jira')
        ->and($composed['properties']['schema-version'])
        ->toBe(['type' => 'integer', 'minimum' => 1])
        ->and($composed['required'])
        ->toBe(['jira'])
        ->and($composed['additionalProperties'])
        ->toBeTrue();
});

it('strips root schema-version property declared by a vendor', function (): void {
    $source = vendorSource('vendor/a', [
        'type' => 'object',
        'properties' => [
            'schema-version' => ['const' => 1],
            'foo' => ['type' => 'string'],
        ],
        'required' => ['schema-version', 'foo'],
    ]);

    $composed = (new ConventionsSchema([$source]))->compose();

    expect($composed['properties']['schema-version'])->toBe(['type' => 'integer', 'minimum' => 1])
        ->and($composed['required'])
        ->toBe(['foo']);
});

it('does not strip nested properties named schema-version', function (): void {
    $source = vendorSource('vendor/a', [
        'type' => 'object',
        'properties' => [
            'nested' => [
                'type' => 'object',
                'properties' => [
                    'schema-version' => ['type' => 'string'],
                ],
            ],
        ],
    ]);

    $composed = (new ConventionsSchema([$source]))->compose();

    /** @var array{properties: array<string, array<mixed, mixed>>} $nested */
    $nested = $composed['properties']['nested'];
    expect($nested['properties']['schema-version'])->toBe(['type' => 'string']);
});

it('merges two vendors with disjoint properties', function (): void {
    $a = vendorSource('vendor/a', [
        'type' => 'object',
        'properties' => ['foo' => ['type' => 'string']],
    ]);
    $b = vendorSource('vendor/b', [
        'type' => 'object',
        'properties' => ['bar' => ['type' => 'integer']],
    ]);

    $composed = (new ConventionsSchema([$a, $b]))->compose();
    expect($composed['properties'])
        ->toMatchArray(['foo' => ['type' => 'string'], 'bar' => ['type' => 'integer']]);
});

it('first-allowlisted vendor wins on same-typed slot collision', function (): void {
    $a = vendorSource('vendor/a', [
        'type' => 'object',
        'properties' => ['foo' => ['type' => 'string', 'minLength' => 5]],
    ]);
    $b = vendorSource('vendor/b', [
        'type' => 'object',
        'properties' => ['foo' => ['type' => 'string', 'maxLength' => 10]],
    ]);

    $composed = (new ConventionsSchema([$a, $b]))->compose();

    expect($composed['properties']['foo'])->toBe(['type' => 'string', 'minLength' => 5]);
});

it('throws SlotTypeMismatchException on different-typed slot collision', function (): void {
    $a = vendorSource('vendor/a', [
        'type' => 'object',
        'properties' => ['foo' => ['type' => 'string']],
    ]);
    $b = vendorSource('vendor/b', [
        'type' => 'object',
        'properties' => ['foo' => ['type' => 'array']],
    ]);

    expect(fn () => (new ConventionsSchema([$a, $b]))->compose())
        ->toThrow(SlotTypeMismatchException::class);
});

it('unions required arrays across vendors', function (): void {
    $a = vendorSource('vendor/a', [
        'type' => 'object',
        'properties' => ['foo' => ['type' => 'string'], 'bar' => ['type' => 'string']],
        'required' => ['foo'],
    ]);
    $b = vendorSource('vendor/b', [
        'type' => 'object',
        'properties' => ['baz' => ['type' => 'string']],
        'required' => ['baz'],
    ]);

    $composed = (new ConventionsSchema([$a, $b]))->compose();

    sort($composed['required']);
    expect($composed['required'])->toBe(['baz', 'foo']);
});

it('validate returns empty diagnostics when host values satisfy composed schema', function (): void {
    $source = vendorSource('vendor/a', [
        'type' => 'object',
        'properties' => [
            'jira' => [
                'type' => 'object',
                'properties' => ['project_key' => ['type' => 'string', 'pattern' => '^[A-Z]+$']],
                'required' => ['project_key'],
            ],
        ],
        'required' => ['jira'],
    ]);

    $diagnostics = (new ConventionsSchema([$source]))->validate([
        'schema-version' => 1,
        'jira' => ['project_key' => 'HPB'],
    ]);

    expect($diagnostics)
        ->toBeEmpty();
});

it('validate accepts host YAML omitting schema-version (strip rule)', function (): void {
    $source = vendorSource('vendor/a', [
        'type' => 'object',
        'properties' => [
            'schema-version' => ['const' => 1],
            'jira' => [
                'type' => 'object',
                'properties' => ['project_key' => ['type' => 'string']],
            ],
        ],
        'required' => ['schema-version', 'jira'],
    ]);

    $diagnostics = (new ConventionsSchema([$source]))->validate([
        'jira' => ['project_key' => 'HPB'],
    ]);

    expect($diagnostics)
        ->toBeEmpty();
});

it('validate returns diagnostics for missing required slot', function (): void {
    $source = vendorSource('vendor/a', [
        'type' => 'object',
        'properties' => ['jira' => ['type' => 'object']],
        'required' => ['jira'],
    ]);

    $diagnostics = (new ConventionsSchema([$source]))->validate(['schema-version' => 1]);

    expect($diagnostics)->not->toBeEmpty()
        ->and($diagnostics[0]->level)
        ->toBe('error');
});

it('validate emits warning diagnostics for unknown host slots not declared by any vendor', function (): void {
    $source = vendorSource('vendor/a', [
        'type' => 'object',
        'properties' => ['jira' => ['type' => 'object', 'properties' => ['project_key' => ['type' => 'string']]]],
    ]);

    $diagnostics = (new ConventionsSchema([$source]))->validate([
        'schema-version' => 1,
        'jira' => ['project_key' => 'HPB'],
        'unknown_top_level' => 'something',
    ]);

    $warnings = array_filter($diagnostics, static fn (Diagnostic $d): bool => $d->level === 'warning');
    expect($warnings)->toHaveCount(1)
        ->and(array_values($warnings)[0]->slot)
        ->toBe('unknown_top_level')
        ->and(array_values($warnings)[0]->message)
        ->toContain('unknown slot');
});

it('validate surfaces type-mismatch in compose as a single error-level diagnostic', function (): void {
    $a = vendorSource('vendor/a', [
        'type' => 'object',
        'properties' => ['foo' => ['type' => 'string']],
    ]);
    $b = vendorSource('vendor/b', [
        'type' => 'object',
        'properties' => ['foo' => ['type' => 'array']],
    ]);

    $diagnostics = (new ConventionsSchema([$a, $b]))->validate([]);

    expect($diagnostics)->toHaveCount(1)
        ->and($diagnostics[0]->level)
        ->toBe('error')
        ->and($diagnostics[0]->slot)
        ->toBe('foo');
});

it('0.9.2 regression: empty conventions array validates clean against schema with no required keys (was: rejected as "array must match type: object")', function (): void {
    // PHP empty array `[]` serializes to JSON `[]`, but the schema type is
    // `object`. Without the empty-case cast to stdClass, opis rejects with
    // "The data (array) must match the type: object". Default-empty
    // `$config->conventions` (consumers without a `withConventions([...])`
    // chain) MUST validate cleanly when no required slots remain.
    $a = vendorSource('vendor/a', [
        'type' => 'object',
        'properties' => ['foo' => ['type' => 'string']],
        // No 'required' field — no required slots remaining
    ]);

    $diagnostics = (new ConventionsSchema([$a]))->validate([]);

    expect($diagnostics)->toBeEmpty();
});
