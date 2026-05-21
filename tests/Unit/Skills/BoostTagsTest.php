<?php declare(strict_types=1);

use SanderMuller\BoostCore\Skills\BoostTags;

it('returns untagged-valid when there is no metadata', function (): void {
    expect(BoostTags::parse([]))->toBe([[], true]);
});

it('returns untagged-valid when metadata is not a map', function (): void {
    expect(BoostTags::parse(['metadata' => 'nope']))->toBe([[], true]);
});

it('returns untagged-valid when the boost-tags key is absent', function (): void {
    expect(BoostTags::parse(['metadata' => ['other' => 'x']]))->toBe([[], true]);
});

it('parses a space-delimited boost-tags string', function (): void {
    expect(BoostTags::parse(['metadata' => ['boost-tags' => 'php jira']]))->toBe([['php', 'jira'], true]);
});

it('normalizes and dedupes tags', function (): void {
    expect(BoostTags::parse(['metadata' => ['boost-tags' => '  PHP   jira  php ']]))->toBe([['php', 'jira'], true]);
});

it('fails closed when boost-tags is not a string', function (): void {
    expect(BoostTags::parse(['metadata' => ['boost-tags' => ['php']]]))->toBe([[], false]);
});

it('treats an all-whitespace boost-tags string as untagged-valid', function (): void {
    expect(BoostTags::parse(['metadata' => ['boost-tags' => '   ']]))->toBe([[], true]);
});
