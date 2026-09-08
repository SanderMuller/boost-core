<?php declare(strict_types=1);

use SanderMuller\BoostCore\Skills\BoostRequires;

it('returns no-requires-valid when there is no metadata', function (): void {
    expect(BoostRequires::parse([]))->toBe([[], true]);
});

it('returns no-requires-valid when metadata is not a map', function (): void {
    expect(BoostRequires::parse(['metadata' => 'nope']))->toBe([[], true]);
});

it('returns no-requires-valid when the boost-requires key is absent', function (): void {
    expect(BoostRequires::parse(['metadata' => ['boost-tags' => 'php']]))->toBe([[], true]);
});

it('parses a space-delimited boost-requires string', function (): void {
    expect(BoostRequires::parse(['metadata' => ['boost-requires' => 'write-spec code-review']]))
        ->toBe([['write-spec', 'code-review'], true]);
});

it('dedupes names and drops empty tokens', function (): void {
    expect(BoostRequires::parse(['metadata' => ['boost-requires' => '  write-spec   code-review  write-spec ']]))
        ->toBe([['write-spec', 'code-review'], true]);
});

it('does not case-fold names — they compare exactly as skill names resolve', function (): void {
    expect(BoostRequires::parse(['metadata' => ['boost-requires' => 'Write-Spec']]))
        ->toBe([['Write-Spec'], true]);
});

it('marks invalid when boost-requires is not a string', function (): void {
    expect(BoostRequires::parse(['metadata' => ['boost-requires' => ['write-spec']]]))->toBe([[], false]);
});

it('marks invalid when boost-requires is declared but null (YAML key without value)', function (): void {
    expect(BoostRequires::parse(['metadata' => ['boost-requires' => null]]))->toBe([[], false]);
});

it('treats an all-whitespace boost-requires string as no-requires-valid', function (): void {
    expect(BoostRequires::parse(['metadata' => ['boost-requires' => '   ']]))->toBe([[], true]);
});

it('declaresRequires is true whenever the metadata.boost-requires key is present', function (): void {
    expect(BoostRequires::declaresRequires(['metadata' => ['boost-requires' => 'write-spec']]))->toBeTrue()
        ->and(BoostRequires::declaresRequires(['metadata' => ['boost-requires' => '']]))->toBeTrue()
        ->and(BoostRequires::declaresRequires(['metadata' => ['boost-requires' => ['malformed']]]))->toBeTrue()
        ->and(BoostRequires::declaresRequires(['metadata' => ['boost-tags' => 'php']]))->toBeFalse()
        ->and(BoostRequires::declaresRequires(['metadata' => 'not-a-map']))->toBeFalse()
        ->and(BoostRequires::declaresRequires([]))->toBeFalse();
});

it('keeps parse() returning bare skill names only, never a prefixed token', function (): void {
    // The @api contract a pinned wrapper depends on: it must not receive
    // `subagent:foo` and report it as a missing skill.
    [$requires, $valid] = BoostRequires::parse([
        'metadata' => ['boost-requires' => 'write-spec subagent:simplification-auditor code-review'],
    ]);

    expect($requires)->toBe(['write-spec', 'code-review'])
        ->and($valid)->toBeTrue();
});

it('returns subagent demands with the prefix stripped', function (): void {
    [$subagents, $valid] = BoostRequires::parseSubagents([
        'metadata' => ['boost-requires' => 'write-spec subagent:simplification-auditor subagent:tech-lead-reviewer'],
    ]);

    expect($subagents)->toBe(['simplification-auditor', 'tech-lead-reviewer'])
        ->and($valid)->toBeTrue();
});

it('returns no subagents when none are declared', function (): void {
    expect(BoostRequires::parseSubagents(['metadata' => ['boost-requires' => 'write-spec']])[0])->toBe([]);
    expect(BoostRequires::parseSubagents([])[0])->toBe([]);
});

it('marks an unknown prefix invalid without dropping the valid tokens', function (): void {
    // A bare skill name never contains a colon, so `foo:bar` is a typo, not a
    // new syntax. Invalid means "sync warns, validate --strict errors" — the
    // skill still ships, because requires gate completeness, not scoping.
    [$requires, $valid] = BoostRequires::parse([
        'metadata' => ['boost-requires' => 'write-spec foo:bar'],
    ]);

    expect($requires)->toBe(['write-spec'])
        ->and($valid)->toBeFalse();
});

it('marks a bare `subagent:` with no name invalid', function (): void {
    [$subagents, $valid] = BoostRequires::parseSubagents([
        'metadata' => ['boost-requires' => 'subagent:'],
    ]);

    expect($subagents)->toBe([])
        ->and($valid)->toBeFalse();
});

it('reports the same validity from both parsers', function (): void {
    $frontmatter = ['metadata' => ['boost-requires' => 'foo:bar']];

    expect(BoostRequires::parse($frontmatter)[1])->toBeFalse()
        ->and(BoostRequires::parseSubagents($frontmatter)[1])->toBeFalse();
});
