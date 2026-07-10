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
