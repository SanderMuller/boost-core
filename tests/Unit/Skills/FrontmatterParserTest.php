<?php

declare(strict_types=1);

use SanderMuller\BoostCore\Skills\FrontmatterParser;

it('parses standard frontmatter blocks', function (): void {
    $parser = new FrontmatterParser;
    $input = "---\nname: example\ndescription: An example skill.\n---\n# Body\n\nContent.\n";

    $doc = $parser->parse($input);

    expect($doc->frontmatter)->toEqual([
        'name' => 'example',
        'description' => 'An example skill.',
    ]);
    expect($doc->body)->toBe("# Body\n\nContent.\n");
});

it('passes through unknown frontmatter keys (loose v1 schema)', function (): void {
    $parser = new FrontmatterParser;
    $input = "---\nname: example\ncustom_field: anything\ntriggers:\n  - foo\n  - bar\n---\nBody.\n";

    $doc = $parser->parse($input);

    expect($doc->frontmatter)->toHaveKey('custom_field');
    expect($doc->frontmatter['custom_field'])->toBe('anything');
    expect($doc->frontmatter['triggers'])->toBe(['foo', 'bar']);
});

it('returns empty frontmatter when no fence is present', function (): void {
    $parser = new FrontmatterParser;
    $input = "# Just a markdown file\n\nNo frontmatter.\n";

    $doc = $parser->parse($input);

    expect($doc->frontmatter)->toBe([]);
    expect($doc->body)->toBe($input);
});

it('returns empty frontmatter when frontmatter is malformed YAML', function (): void {
    $parser = new FrontmatterParser;
    $input = "---\nname: [unclosed bracket\n---\nBody.\n";

    $doc = $parser->parse($input);

    expect($doc->frontmatter)->toBe([]);
});

it('handles CRLF line endings', function (): void {
    $parser = new FrontmatterParser;
    $input = "---\r\nname: crlf\r\n---\r\nBody.\r\n";

    $doc = $parser->parse($input);

    expect($doc->frontmatter)->toEqual(['name' => 'crlf']);
    expect($doc->body)->toContain('Body.');
});

it('returns empty frontmatter when frontmatter is a scalar not a map', function (): void {
    $parser = new FrontmatterParser;
    $input = "---\njust a string\n---\nBody.\n";

    $doc = $parser->parse($input);

    expect($doc->frontmatter)->toBe([]);
});
