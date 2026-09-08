<?php declare(strict_types=1);

use SanderMuller\BoostCore\Sync\SubagentNameScanner;

function subagentScanProject(): string
{
    $dir = sys_get_temp_dir() . '/boost-subagent-scan-' . bin2hex(random_bytes(6));
    mkdir($dir . '/.claude/agents', 0o755, recursive: true);

    return $dir;
}

function writeSubagent(string $dir, string $relativePath, string $contents): void
{
    $path = $dir . '/.claude/agents/' . $relativePath;
    $parent = dirname($path);
    if (! is_dir($parent)) {
        mkdir($parent, 0o755, recursive: true);
    }

    file_put_contents($path, $contents);
}

function subagentScanCleanup(string $dir): void
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

it('reports two files declaring the same name, with both paths', function (): void {
    $dir = subagentScanProject();

    try {
        writeSubagent($dir, 'tech-lead-reviewer.md', "---\nname: tech-lead-reviewer\n---\nhand-written\n");
        writeSubagent($dir, 'boost/vendor__pack/reviewer.md', "---\nname: tech-lead-reviewer\n---\nemitted\n");

        $overlaps = (new SubagentNameScanner())->scan($dir);

        expect($overlaps)->toHaveKey('tech-lead-reviewer')
            ->and($overlaps['tech-lead-reviewer'])->toBe([
                '.claude/agents/boost/vendor__pack/reviewer.md',
                '.claude/agents/tech-lead-reviewer.md',
            ]);
    } finally {
        subagentScanCleanup($dir);
    }
});

it('reports nothing when every name is distinct', function (): void {
    $dir = subagentScanProject();

    try {
        writeSubagent($dir, 'one.md', "---\nname: one\n---\n");
        writeSubagent($dir, 'nested/two.md', "---\nname: two\n---\n");

        expect((new SubagentNameScanner())->scan($dir))
            ->toBeEmpty();
    } finally {
        subagentScanCleanup($dir);
    }
});

it('ignores a file that declares no name — Claude Code never loads it', function (): void {
    $dir = subagentScanProject();

    try {
        // "No `name`: Claude Code treats the file as documentation kept beside
        // your agents." A file it never loads cannot collide, so the stem must
        // NOT stand in for a declared name.
        writeSubagent($dir, 'auditor.md', "---\ndescription: no name key\n---\n");
        writeSubagent($dir, 'nested/other.md', "---\nname: auditor\n---\n");

        expect((new SubagentNameScanner())->scan($dir))
            ->toBeEmpty();
    } finally {
        subagentScanCleanup($dir);
    }
});

it('ignores a file whose frontmatter is unparseable, without aborting the scan', function (): void {
    $dir = subagentScanProject();

    try {
        // FrontmatterParser returns EMPTY frontmatter for a broken YAML head
        // rather than throwing — which lands as "no declared name", matching
        // Claude Code's own handling. The scan must continue past it.
        writeSubagent($dir, 'broken.md', "---\nname: [unclosed\n---\nbody\n");
        writeSubagent($dir, 'nested/dup.md', "---\nname: broken\n---\n");
        writeSubagent($dir, 'a.md', "---\nname: real\n---\n");
        writeSubagent($dir, 'b.md', "---\nname: real\n---\n");

        // The malformed file contributes nothing; the pair after it is still found.
        expect((new SubagentNameScanner())->scan($dir))->toBe([
            'real' => ['.claude/agents/a.md', '.claude/agents/b.md'],
        ]);
    } finally {
        subagentScanCleanup($dir);
    }
});

it('treats a blank or non-string name as no name at all', function (): void {
    $dir = subagentScanProject();

    try {
        writeSubagent($dir, 'blank.md', "---\nname: '   '\n---\n");
        writeSubagent($dir, 'nested/blank.md', "---\nname: 42\n---\n");

        expect((new SubagentNameScanner())->scan($dir))
            ->toBeEmpty();
    } finally {
        subagentScanCleanup($dir);
    }
});

it('ignores non-markdown files', function (): void {
    $dir = subagentScanProject();

    try {
        writeSubagent($dir, 'notes.txt', "---\nname: dup\n---\n");
        writeSubagent($dir, 'dup.md', "---\nname: dup\n---\n");

        expect((new SubagentNameScanner())->scan($dir))
            ->toBeEmpty();
    } finally {
        subagentScanCleanup($dir);
    }
});

it('returns nothing when the subagent root does not exist', function (): void {
    $dir = sys_get_temp_dir() . '/boost-subagent-missing-' . bin2hex(random_bytes(6));
    mkdir($dir, 0o755, recursive: true);

    try {
        expect((new SubagentNameScanner())->scan($dir))
            ->toBeEmpty();
    } finally {
        subagentScanCleanup($dir);
    }
});
