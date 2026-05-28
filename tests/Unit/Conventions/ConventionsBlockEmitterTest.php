<?php declare(strict_types=1);

use SanderMuller\BoostCore\Conventions\ConventionsBlockEmitter;
use SanderMuller\BoostCore\Conventions\VendorSchemaSource;

/**
 * @param  list<string>  $required
 */
function source(string $name, ?string $schemaRequired = null, array $required = ['jira']): VendorSchemaSource
{
    return new VendorSchemaSource(
        vendorName: $name,
        schemaPath: "/fake/{$name}/conventions-schema.json",
        schema: [
            'type' => 'object',
            'properties' => ['jira' => ['type' => 'object']],
            'required' => $required,
            'metadata' => $schemaRequired === null ? [] : ['schema-required' => $schemaRequired],
        ],
    );
}

it('extract returns null when CLAUDE.md is null', function (): void {
    expect((new ConventionsBlockEmitter())->extract(null))->toBeNull();
});

it('extract returns null when markers are missing', function (): void {
    expect((new ConventionsBlockEmitter())->extract("# heading\n\nno markers here"))->toBeNull();
});

it('extract returns the YAML body between markers', function (): void {
    $claudeMd = "## Project Conventions\n\n<!-- boost-core:conventions:start -->\nschema-version: 1\njira:\n  project_key: HPB\n<!-- boost-core:conventions:end -->\n";

    expect((new ConventionsBlockEmitter())->extract($claudeMd))->toBe("schema-version: 1\njira:\n  project_key: HPB");
});

it('extract strips a ```yaml ... ``` markdown code fence around the body', function (): void {
    $claudeMd = "<!-- boost-core:conventions:start -->\n```yaml\nschema-version: 1\njira:\n  project_key: HPB\n```\n<!-- boost-core:conventions:end -->\n";

    expect((new ConventionsBlockEmitter())->extract($claudeMd))->toBe("schema-version: 1\njira:\n  project_key: HPB");
});

it('syncBlock handles H2 at EOF with no trailing newline without corruption', function (): void {
    $claudeMd = "# Project\n\n## Project Conventions";
    $result = (new ConventionsBlockEmitter())->syncBlock($claudeMd, [source('vendor/a')]);

    expect($result['contents'])->not->toBeNull()
        ->toContain("## Project Conventions\n\n<!-- Managed by boost-core");
});

it('parse returns null values when region missing', function (): void {
    $result = (new ConventionsBlockEmitter())->parse('# no conventions block here');

    expect($result['values'])->toBeNull()
        ->and($result['diagnostics'])
        ->toBeEmpty();
});

it('parse returns values when YAML is valid', function (): void {
    $claudeMd = "<!-- boost-core:conventions:start -->\nschema-version: 1\njira:\n  project_key: HPB\n<!-- boost-core:conventions:end -->";
    $result = (new ConventionsBlockEmitter())->parse($claudeMd);
    expect($result)
        ->toMatchArray(['values' => [
            'schema-version' => 1,
            'jira' => ['project_key' => 'HPB'],
        ], 'diagnostics' => []]);
});

it('parse returns error diagnostic when YAML decodes to a scalar', function (): void {
    $claudeMd = "<!-- boost-core:conventions:start -->\nfoo\n<!-- boost-core:conventions:end -->";
    $result = (new ConventionsBlockEmitter())->parse($claudeMd);

    expect($result['values'])->toBeNull()
        ->and($result['diagnostics'])
        ->toHaveCount(1)
        ->and($result['diagnostics'][0]->level)
        ->toBe('error')
        ->and($result['diagnostics'][0]->message)
        ->toContain('mapping');
});

it('parse returns error diagnostic on invalid YAML', function (): void {
    $claudeMd = "<!-- boost-core:conventions:start -->\nschema-version: 1\n  invalid: yaml: indentation\n<!-- boost-core:conventions:end -->";
    $result = (new ConventionsBlockEmitter())->parse($claudeMd);

    expect($result['values'])->toBeNull()
        ->and($result['diagnostics'])
        ->toHaveCount(1)
        ->and($result['diagnostics'][0]->level)
        ->toBe('error');
});

it('syncBlock no-ops when CLAUDE.md is missing', function (): void {
    $result = (new ConventionsBlockEmitter())->syncBlock(null, [source('vendor/a')]);

    expect($result['contents'])->toBeNull()
        ->and($result['diagnostics'])
        ->toBeEmpty();
});

it('syncBlock no-ops when no schemas declared', function (): void {
    $result = (new ConventionsBlockEmitter())->syncBlock('# existing', []);

    expect($result['contents'])->toBeNull()
        ->and($result['diagnostics'])
        ->toBeEmpty();
});

it('syncBlock no-ops when markers already present', function (): void {
    $claudeMd = "## Project Conventions\n<!-- boost-core:conventions:start -->\nbody\n<!-- boost-core:conventions:end -->\n";
    $result = (new ConventionsBlockEmitter())->syncBlock($claudeMd, [source('vendor/a')]);

    expect($result['contents'])->toBeNull();
});

it('syncBlock appends a fresh H2 + region at EOF when no H2 exists', function (): void {
    $claudeMd = "# Project README\n\nSome content.\n";
    $result = (new ConventionsBlockEmitter())->syncBlock($claudeMd, [source('vendor/a')]);

    expect($result['contents'])->not->toBeNull()
        ->toContain('## Project Conventions')
        ->toContain('<!-- boost-core:conventions:start -->')
        ->toContain('schema-version: 1')
        ->toContain('# vendor/a — required slots:');
});

it('syncBlock scaffolds under existing H2 when body is whitespace-only', function (): void {
    $claudeMd = "# Project\n\n## Project Conventions\n\n## Next Section\n";
    $result = (new ConventionsBlockEmitter())->syncBlock($claudeMd, [source('vendor/a')]);

    expect($result['contents'])->not->toBeNull()
        ->toContain('<!-- boost-core:conventions:start -->')
        ->toContain('## Next Section');
});

it('syncBlock emits warning + no-scaffold when H2 has pre-existing content', function (): void {
    $claudeMd = "## Project Conventions\n\nOperator already wrote prose here.\n\nMore content.\n";
    $result = (new ConventionsBlockEmitter())->syncBlock($claudeMd, [source('vendor/a')]);

    expect($result['contents'])->toBeNull()
        ->and($result['diagnostics'])
        ->toHaveCount(1)
        ->and($result['diagnostics'][0]->level)
        ->toBe('warning')
        ->and($result['diagnostics'][0]->message)
        ->toContain('pre-existing content');
});

it('scaffold seed is 1 when all vendors are wildcard', function (): void {
    $sources = [source('vendor/a'), source('vendor/b', '*')];
    expect((new ConventionsBlockEmitter())->scaffoldSeed($sources))->toBe(1);
});

it('scaffold seed is max(minRequired) across concrete vendors', function (): void {
    $sources = [source('vendor/a', '^1'), source('vendor/b', '^2')];
    expect((new ConventionsBlockEmitter())->scaffoldSeed($sources))->toBe(2);
});

it('scaffold seed ignores wildcard vendors when computing max', function (): void {
    $sources = [source('vendor/a', '*'), source('vendor/b', '^2')];
    expect((new ConventionsBlockEmitter())->scaffoldSeed($sources))->toBe(2);
});

it('scaffold seed handles transitional OR ranges', function (): void {
    $sources = [source('vendor/a', '^1||^2'), source('vendor/b', '^2')];
    expect((new ConventionsBlockEmitter())->scaffoldSeed($sources))->toBe(2);
});

it('renderFromValues bootstraps CLAUDE.md with H2 + rendered region when file does not exist and conventions are declared', function (): void {
    $emitter = new ConventionsBlockEmitter();
    $result = $emitter->renderFromValues(
        null,
        [source('vendor/a')],
        ['jira' => ['project_key' => 'HPB']],
    );

    expect($result['contents'])->not->toBeNull()
        ->and($result['contents'])->toStartWith('## Project Conventions')
        ->and($result['contents'])->toContain('<!-- boost-core:conventions:start -->')
        ->and($result['contents'])->toContain('<!-- boost-core:conventions:end -->')
        ->and($result['contents'])->toContain('project_key: HPB')
        ->and($result['diagnostics'])->toBeEmpty();
});

it('renderFromValues returns null when file does not exist AND no conventions declared (no schema-only scaffold from nothing)', function (): void {
    $emitter = new ConventionsBlockEmitter();
    $result = $emitter->renderFromValues(null, [source('vendor/a')], []);

    expect($result['contents'])->toBeNull()
        ->and($result['diagnostics'])->toBeEmpty();
});
