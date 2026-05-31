<?php declare(strict_types=1);

use SanderMuller\BoostCore\Conventions\ConventionsAudit;

/**
 * @return array<string, mixed>
 */
function auditSchema(): array
{
    /** @var array<string, mixed> $schema */
    $schema = json_decode((string) file_get_contents(__DIR__ . '/../../Fixtures/conventions/conventions-schema.json'), true, 512, JSON_THROW_ON_ERROR);

    return $schema;
}

it('reports per-slot provenance: declared / schema-default / missing', function (): void {
    $rows = (new ConventionsAudit())->audit(
        ['github' => ['default_base_branch' => 'develop'], 'mcp' => ['jira' => false]],
        auditSchema(),
    );

    $byPath = [];
    foreach ($rows as $row) {
        $byPath[$row['path']] = $row;
    }

    // declared (even falsy — path-existence)
    expect($byPath['github.default_base_branch'])->toMatchArray(['provenance' => ConventionsAudit::DECLARED, 'value' => 'develop'])
        ->and($byPath['mcp.jira'])->toMatchArray(['provenance' => ConventionsAudit::DECLARED, 'value' => false])
        // schema-default (codex.invocation_mode has a default, unset here)
        ->and($byPath['codex.invocation_mode'])->toMatchArray(['provenance' => ConventionsAudit::SCHEMA_DEFAULT, 'value' => 'plugin'])
        // missing (testing.backend_framework has no default, unset)
        ->and($byPath['testing.backend_framework'])->toMatchArray(['provenance' => ConventionsAudit::MISSING, 'value' => null]);
});

it('enumerates leaf slots only (sorted), skipping container + schema-version', function (): void {
    $rows = (new ConventionsAudit())->audit([], auditSchema());
    $paths = array_column($rows, 'path');

    expect($paths)->toContain('github.default_base_branch')
        ->and($paths)->toContain('branches.patterns')
        ->and($paths)->not->toContain('github')          // container, not a leaf
        ->and($paths)->not->toContain('schema-version')
        ->and($paths)->toBe([...$paths] === array_values($paths) ? $paths : $paths) // list
        ->and($paths === array_values($paths))->toBeTrue();

    $sorted = $paths;
    sort($sorted);
    expect($paths)->toBe($sorted);
});
