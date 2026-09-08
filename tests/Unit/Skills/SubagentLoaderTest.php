<?php declare(strict_types=1);

use SanderMuller\BoostCore\Skills\FrontmatterParser;
use SanderMuller\BoostCore\Skills\SubagentLoader;

function subagentSourceDir(): string
{
    $dir = sys_get_temp_dir() . '/boost-subagent-src-' . bin2hex(random_bytes(6));
    mkdir($dir, 0o755, recursive: true);

    return $dir;
}

function writeSubagentSource(string $dir, string $filename, string $contents): void
{
    file_put_contents($dir . '/' . $filename, $contents);
}

function subagentSourceCleanup(string $dir): void
{
    if (! is_dir($dir)) {
        return;
    }

    $iterator = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($dir, FilesystemIterator::SKIP_DOTS),
        RecursiveIteratorIterator::CHILD_FIRST,
    );
    /** @var SplFileInfo $entry */
    foreach ($iterator as $entry) {
        $entry->isDir() ? @rmdir($entry->getPathname()) : @unlink($entry->getPathname());
    }

    @rmdir($dir);
}

function subagentLoader(): SubagentLoader
{
    return new SubagentLoader(new FrontmatterParser());
}

it('loads a flat subagent file with name, description, body and provenance', function (): void {
    $dir = subagentSourceDir();

    try {
        writeSubagentSource($dir, 'simplification-auditor.md', <<<'MD'
            ---
            name: simplification-auditor
            description: Adversarial cut pass over a diff.
            tools: Read, Grep
            ---
            Judge the diff as code somebody else wrote.
            MD);

        $result = subagentLoader()->load($dir, 'sandermuller/boost-skills');

        expect($result['warnings'])
            ->toBeEmpty()
            ->and($result['subagents'])->toHaveCount(1);

        $subagent = $result['subagents'][0];
        expect($subagent->name)->toBe('simplification-auditor')
            ->and($subagent->description)->toBe('Adversarial cut pass over a diff.')
            ->and($subagent->sourceVendor)->toBe('sandermuller/boost-skills')
            ->and($subagent->isHostAuthored())->toBeFalse()
            ->and($subagent->body)->toContain('somebody else wrote');
    } finally {
        subagentSourceCleanup($dir);
    }
});

it('passes tools and disallowedTools through the frontmatter verbatim', function (): void {
    $dir = subagentSourceDir();

    try {
        // The engine cannot verify a consumer's permission surface, so it does
        // not validate or rewrite these keys.
        writeSubagentSource($dir, 'reader.md', <<<'MD'
            ---
            name: reader
            disallowedTools: Write, Edit
            model: opus
            ---
            body
            MD);

        $subagent = subagentLoader()->load($dir)['subagents'][0];

        expect($subagent->frontmatter['disallowedTools'])->toBe('Write, Edit')
            ->and($subagent->frontmatter['model'])->toBe('opus');
    } finally {
        subagentSourceCleanup($dir);
    }
});

it('skips a file that declares no name and warns, instead of using the filename', function (): void {
    $dir = subagentSourceDir();

    try {
        // Claude Code ignores a nameless agent file — "treats the file as
        // documentation kept beside your agents" — so emitting one would ship
        // a file the target never loads.
        writeSubagentSource($dir, 'no-name.md', "---\ndescription: nameless\n---\nbody\n");

        $result = subagentLoader()->load($dir);

        expect($result['subagents'])
            ->toBeEmpty()
            ->and($result['warnings'])->toHaveCount(1)
            ->and($result['warnings'][0])->toContain('no-name.md')
            ->and($result['warnings'][0])->toContain('declares no `name`');
    } finally {
        subagentSourceCleanup($dir);
    }
});

it('skips a file whose frontmatter does not parse, and keeps loading the rest', function (): void {
    $dir = subagentSourceDir();

    try {
        writeSubagentSource($dir, 'broken.md', "---\nname: [unclosed\n---\nbody\n");
        writeSubagentSource($dir, 'good.md', "---\nname: good\n---\nbody\n");

        $result = subagentLoader()->load($dir);

        expect($result['subagents'])->toHaveCount(1)
            ->and($result['subagents'][0]->name)->toBe('good')
            ->and($result['warnings'])->toHaveCount(1);
    } finally {
        subagentSourceCleanup($dir);
    }
});

it('parses boost-tags and marks a malformed value invalid', function (): void {
    $dir = subagentSourceDir();

    try {
        writeSubagentSource($dir, 'tagged.md', "---\nname: tagged\nmetadata:\n  boost-tags: \"php laravel\"\n---\nbody\n");
        writeSubagentSource($dir, 'broken-tags.md', "---\nname: broken-tags\nmetadata:\n  boost-tags: [1, 2]\n---\nbody\n");

        $byName = [];
        foreach (subagentLoader()->load($dir)['subagents'] as $subagent) {
            $byName[$subagent->name] = $subagent;
        }

        expect($byName['tagged']->tags)->toBe(['php', 'laravel'])
            ->and($byName['tagged']->tagsValid)->toBeTrue()
            ->and($byName['broken-tags']->tagsValid)->toBeFalse();
    } finally {
        subagentSourceCleanup($dir);
    }
});

it('marks a host-authored subagent with no vendor', function (): void {
    $dir = subagentSourceDir();

    try {
        writeSubagentSource($dir, 'mine.md', "---\nname: mine\n---\nbody\n");

        $subagent = subagentLoader()->load($dir)['subagents'][0];

        expect($subagent->isHostAuthored())->toBeTrue()
            ->and($subagent->excludeKey())->toBeNull();
    } finally {
        subagentSourceCleanup($dir);
    }
});

it('returns nothing for a directory that does not exist', function (): void {
    expect(subagentLoader()->load('/nope/does/not/exist'))
        ->toBe(['subagents' => [], 'warnings' => []]);
});
