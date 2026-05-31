<?php declare(strict_types=1);

use SanderMuller\BoostCore\Conventions\ConventionsInliner;
use SanderMuller\BoostCore\Conventions\ConventionTokenLeakScanner;
use SanderMuller\BoostCore\Conventions\LeakHit;
use SanderMuller\BoostCore\Conventions\SlotResolver;

/**
 * ConventionTokenLeakScanner — resolve-to-classify cause attribution (0.16.0).
 *
 * @param  array<string, mixed>  $conventions
 */
function leakScanner(array $conventions = []): ConventionTokenLeakScanner
{
    /** @var array<string, mixed> $schema */
    $schema = json_decode((string) file_get_contents(__DIR__ . '/../../Fixtures/conventions/conventions-schema.json'), true, 512, JSON_THROW_ON_ERROR);
    $resolver = new SlotResolver($conventions, $schema);

    return new ConventionTokenLeakScanner(
        new ConventionsInliner($resolver, ['github', 'testing', 'codex', 'mcp', 'branches']),
        $resolver,
    );
}

it('classifies a prose token that RESOLVES as a stale/old-engine leak (mode A)', function (): void {
    // github.default_base_branch resolves to its schema default ("main"), yet sits
    // raw on disk → emitted by an engine that could not resolve it, or stale.
    $leaks = leakScanner()->scan('CLAUDE.md', 'Base: <!--boost:conv path="github.default_base_branch" mode="inline"-->');

    expect($leaks)->toHaveCount(1)
        ->and($leaks[0]->path)
        ->toBe('github.default_base_branch')
        ->and($leaks[0]->cause)
        ->toContain('left unresolved')
        ->toContain('re-sync with boost-core')
        ->and($leaks[0]->location())
        ->toBe('CLAUDE.md:1');
});

it('surfaces the resolver message for an unknown slot (mode B)', function (): void {
    $leaks = leakScanner()->scan('CLAUDE.md', '<!--boost:conv path="bogus.slot" mode="inline"-->');

    expect($leaks)->toHaveCount(1)
        ->and($leaks[0]->cause)
        ->toContain('unknown convention slot');
});

it('surfaces a schema render-pin violation (mode B)', function (): void {
    // branches.patterns pins render modes [bullets, yaml]; inline is rejected.
    $leaks = leakScanner()->scan('CLAUDE.md', '<!--boost:conv path="branches.patterns" mode="inline"-->');

    expect($leaks)->toHaveCount(1)
        ->and($leaks[0]->cause)
        ->toContain('not allowed for slot "branches.patterns"');
});

it('surfaces a type×mode mismatch for a map value rendered inline (mode B)', function (): void {
    $leaks = leakScanner(['mcp' => ['jira' => 'mcp-atlassian']])
        ->scan('CLAUDE.md', '<!--boost:conv path="mcp" mode="inline"-->');

    expect($leaks)->toHaveCount(1)
        ->and($leaks[0]->cause)
        ->toContain('cannot render in mode "inline"');
});

it('reports a malformed token missing the mode attribute', function (): void {
    $leaks = leakScanner()->scan('CLAUDE.md', '<!--boost:conv path="github.default_base_branch"-->');

    expect($leaks)->toHaveCount(1)
        ->and($leaks[0]->cause)
        ->toContain('missing required "mode"');
});

it('reports a malformed token missing the path attribute', function (): void {
    $leaks = leakScanner()->scan('CLAUDE.md', '<!--boost:conv mode="inline"-->');

    expect($leaks)->toHaveCount(1)
        ->and($leaks[0]->cause)
        ->toContain('missing required "path"');
});

it('classifies a surviving fence opener as an unprocessed-fence leak', function (): void {
    $leaks = leakScanner()->scan('.claude/skills/pr/SKILL.md', "```yaml boost:conv\npatterns:\n  - main\n```");

    expect($leaks)->toHaveCount(1)
        ->and($leaks[0]->kind)
        ->toBe(LeakHit::KIND_FENCE_OPENER)
        ->and($leaks[0]->cause)
        ->toContain('surviving `boost:conv` fence')
        ->toContain('re-sync with boost-core');
});

it('returns nothing for clean emitted content', function (): void {
    expect(leakScanner()->scan('CLAUDE.md', "# Project\n\nNo tokens here.\n"))
        ->toBeEmpty();
});
