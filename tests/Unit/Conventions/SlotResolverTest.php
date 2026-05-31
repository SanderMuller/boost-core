<?php declare(strict_types=1);

use SanderMuller\BoostCore\Conventions\SlotResolution;
use SanderMuller\BoostCore\Conventions\SlotResolver;

/**
 * @return array<string, mixed>
 */
function conventionsTestSchema(): array
{
    /** @var array<string, mixed> $schema */
    $schema = json_decode((string) file_get_contents(__DIR__ . '/../../Fixtures/conventions/conventions-schema.json'), true, 512, JSON_THROW_ON_ERROR);

    return $schema;
}

/**
 * @param  array<string, mixed>  $conventions
 */
function resolver(array $conventions): SlotResolver
{
    return new SlotResolver($conventions, conventionsTestSchema());
}

// --- A1: declared scalar inlines the value -----------------------------------
it('A1: inlines a declared scalar value', function (): void {
    $r = resolver(['github' => ['default_base_branch' => 'develop']])->resolve('github.default_base_branch', 'inline', null);

    expect($r->ok)->toBeTrue()
        ->and($r->output)->toBe('develop')
        ->and($r->provenance)->toBe(SlotResolution::PROVENANCE_DECLARED);
});

// --- A2: unset + schema default ----------------------------------------------
it('A2: falls back to the schema default when unset', function (): void {
    $r = resolver([])->resolve('codex.invocation_mode', 'inline', null);

    expect($r->ok)->toBeTrue()
        ->and($r->output)->toBe('plugin')
        ->and($r->provenance)->toBe(SlotResolution::PROVENANCE_SCHEMA_DEFAULT);
});

// --- A3: unset + no default + inline fallback --------------------------------
it('A3: emits the inline fallback when unset and no schema default', function (): void {
    $r = resolver([])->resolve('testing.backend_framework', 'inline', 'detect from composer.json');

    expect($r->ok)->toBeTrue()
        ->and($r->output)->toBe('detect from composer.json')
        ->and($r->provenance)->toBe(SlotResolution::PROVENANCE_FALLBACK);
});

// --- A4: unset + no default + NO fallback → error ----------------------------
it('A4: errors when unset, no schema default, and no fallback (never a silent vanish)', function (): void {
    $r = resolver([])->resolve('testing.backend_framework', 'inline', null);

    expect($r->ok)->toBeFalse()
        ->and($r->error)->toContain('unset');
});

// --- A5: unknown path → error ------------------------------------------------
it('A5: errors on an unknown slot path', function (): void {
    $r = resolver([])->resolve('jira.bogus_key', 'inline', null);

    expect($r->ok)->toBeFalse()
        ->and($r->error)->toContain('unknown convention slot');
});

// --- A6: declared FALSY is declared, not missing (D2) ------------------------
it('A6: a declared falsy scalar is DECLARED (path-existence, not truthiness)', function (): void {
    // mcp.jira has a schema default ("mcp-atlassian"); a declared "" must NOT
    // fall through to that default.
    $r = resolver(['mcp' => ['jira' => '']])->resolve('mcp.jira', 'inline', null);

    expect($r->ok)->toBeTrue()
        ->and($r->output)
        ->toBeEmpty()
        ->and($r->provenance)->toBe(SlotResolution::PROVENANCE_DECLARED);
});

// --- A7: declared empty list, bullets → "none" (3ncrxzev-C) ------------------
it('A7: a declared empty list renders the literal "none" in bullets (no stray dash)', function (): void {
    $r = resolver(['branches' => ['patterns' => []]])->resolve('branches.patterns', 'bullets', null);

    expect($r->ok)->toBeTrue()
        ->and($r->output)->toBe('none')
        ->and($r->provenance)->toBe(SlotResolution::PROVENANCE_DECLARED);
});

// --- A8/A9: structured list renders ------------------------------------------
it('A8/A9: a list-of-maps renders as a bullet list and as yaml', function (): void {
    $patterns = [['pattern' => 'feature/HPB-XXXX', 'base' => 'develop']];

    $bullets = resolver(['branches' => ['patterns' => $patterns]])->resolve('branches.patterns', 'bullets', null);
    expect($bullets->ok)->toBeTrue()->and($bullets->output)->toContain('feature/HPB-XXXX');

    $yaml = resolver(['branches' => ['patterns' => $patterns]])->resolve('branches.patterns', 'yaml', null);
    expect($yaml->ok)->toBeTrue()->and($yaml->output)->toContain('pattern: feature/HPB-XXXX');
});

// --- A10: structured slot in mode=inline → error -----------------------------
it('A10: a structured slot in mode=inline is a token error', function (): void {
    // branches.patterns is render-pinned [bullets, yaml], so inline is rejected
    // at the pin gate (which fires before the matrix); the matrix path itself is
    // covered independently by A12. Either way: inline on a structured slot errors.
    $r = resolver(['branches' => ['patterns' => [['pattern' => 'x', 'base' => 'y']]]])->resolve('branches.patterns', 'inline', null);

    expect($r->ok)->toBeFalse()
        ->and($r->error)->toContain('inline');
});

// --- A12: scalar in mode=bullets → error -------------------------------------
it('A12: a scalar in mode=bullets is a token error', function (): void {
    $r = resolver(['github' => ['default_base_branch' => 'develop']])->resolve('github.default_base_branch', 'bullets', null);

    expect($r->ok)->toBeFalse()
        ->and($r->error)->toContain('scalar value cannot render in mode "bullets"');
});

// --- A14: multi-line scalar in inline → error --------------------------------
it('A14: a multi-line scalar cannot render inline', function (): void {
    $r = resolver(['github' => ['default_base_branch' => "line1\nline2"]])->resolve('github.default_base_branch', 'inline', null);

    expect($r->ok)->toBeFalse()
        ->and($r->error)->toContain('multi-line');
});

// --- A15: token mode violates schema-pinned render → error -------------------
it('A15: a mode outside the schema-pinned render set is a token error', function (): void {
    // branches.patterns pins render: [bullets, yaml]; json is not allowed.
    $r = resolver(['branches' => ['patterns' => [['pattern' => 'x', 'base' => 'y']]]])->resolve('branches.patterns', 'json', null);

    expect($r->ok)->toBeFalse()
        ->and($r->error)->toContain('schema pins');
});

// --- k5m15b0p: list renders INLINE comma-joined ------------------------------
it('renders a scalar list inline as a comma-joined clause (k5m15b0p — testing.forbid)', function (): void {
    $r = resolver(['testing' => ['forbid' => ['dusk', 'cypress', 'playwright']]])->resolve('testing.forbid', 'inline', null);

    expect($r->ok)->toBeTrue()
        ->and($r->output)->toBe('dusk, cypress, playwright');
});

it('renders a declared empty list inline as "none" (no dangling clause — k5m15b0p nit)', function (): void {
    $r = resolver(['testing' => ['forbid' => []]])->resolve('testing.forbid', 'inline', null);

    expect($r->ok)->toBeTrue()
        ->and($r->output)->toBe('none');
});

it('errors when a list of STRUCTURED items is asked to render inline', function (): void {
    $r = resolver(['testing' => ['forbid' => [['a' => 1]]]])->resolve('testing.forbid', 'inline', null);

    expect($r->ok)->toBeFalse()
        ->and($r->error)->toContain('cannot render inline');
});

// --- unknown mode ------------------------------------------------------------
it('errors on an unknown render mode', function (): void {
    $r = resolver([])->resolve('codex.invocation_mode', 'sideways', null);

    expect($r->ok)->toBeFalse()
        ->and($r->error)->toContain('unknown render mode');
});

// --- AP: open-vocab (additionalProperties) map sub-key resolution (0.16.0) ----
// Regression for the gap k5m15b0p hit: `path="mcp.jira"` into an additionalProperties
// map errored as "unknown slot" because resolve() short-circuited on a null schema
// leaf — map sub-keys were not addressable at all (declared OR defaulted).

/**
 * @return array<string, mixed>
 */
function apMapSchema(): array
{
    return [
        'properties' => [
            'mcp' => [
                'type' => 'object',
                'additionalProperties' => ['type' => 'string'],
                'default' => ['jira' => 'mcp-atlassian'],
            ],
        ],
    ];
}

it('AP1: resolves an additionalProperties map sub-key from the ancestor default map', function (): void {
    $r = (new SlotResolver([], apMapSchema()))->resolve('mcp.jira', 'inline', null);

    expect($r->ok)->toBeTrue()
        ->and($r->output)->toBe('mcp-atlassian')
        ->and($r->provenance)->toBe(SlotResolution::PROVENANCE_SCHEMA_DEFAULT);
});

it('AP2: resolves a DECLARED additionalProperties map sub-key', function (): void {
    $r = (new SlotResolver(['mcp' => ['jira' => 'custom-mcp']], apMapSchema()))->resolve('mcp.jira', 'inline', null);

    expect($r->ok)->toBeTrue()
        ->and($r->output)->toBe('custom-mcp')
        ->and($r->provenance)->toBe(SlotResolution::PROVENANCE_DECLARED);
});

it('AP3: a map sub-key absent from the default map AND undeclared errors (no silent vanish)', function (): void {
    $r = (new SlotResolver([], apMapSchema()))->resolve('mcp.database', 'inline', null);

    expect($r->ok)->toBeFalse();
});

it('AP4: a map sub-key absent from the default map uses the inline fallback', function (): void {
    $r = (new SlotResolver([], apMapSchema()))->resolve('mcp.database', 'inline', 'none configured');

    expect($r->ok)->toBeTrue()
        ->and($r->output)->toBe('none configured')
        ->and($r->provenance)->toBe(SlotResolution::PROVENANCE_FALLBACK);
});
