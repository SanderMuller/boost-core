<?php declare(strict_types=1);

use SanderMuller\BoostCore\Sync\FileWriter;
use SanderMuller\BoostCore\Sync\PathTraversalException;
use SanderMuller\BoostCore\Sync\PendingWrite;
use SanderMuller\BoostCore\Sync\WriteAction;

function tempProjectRoot(): string
{
    $root = sys_get_temp_dir() . '/boost-core-test-' . bin2hex(random_bytes(8));
    mkdir($root, 0o755, recursive: true);

    return $root;
}

/**
 * @return list<string>
 */
function rmTree(string $path): array
{
    $deleted = [];
    if (! is_dir($path)) {
        return $deleted;
    }

    $entries = scandir($path);
    if ($entries === false) {
        return $deleted;
    }

    foreach ($entries as $entry) {
        if ($entry === '.') {
            continue;
        }

        if ($entry === '..') {
            continue;
        }

        $full = $path . '/' . $entry;
        if (is_dir($full) && ! is_link($full)) {
            $deleted = [...$deleted, ...rmTree($full)];
        } else {
            unlink($full);
            $deleted[] = $full;
        }
    }

    rmdir($path);

    return $deleted;
}

it('writes content to the resolved absolute path', function (): void {
    $root = tempProjectRoot();
    try {
        $writer = new FileWriter();
        $result = $writer->write($root, new PendingWrite('foo/bar.md', 'hello'));

        expect($result->action)->toBe(WriteAction::WROTE)
            ->and($result->absolutePath)
            ->toBe($root . '/foo/bar.md')
            ->and(file_get_contents($result->absolutePath))
            ->toBe('hello');
    } finally {
        rmTree($root);
    }
});

it('creates parent directories as needed', function (): void {
    $root = tempProjectRoot();
    try {
        $writer = new FileWriter();
        $writer->write($root, new PendingWrite('a/b/c/d.md', 'deep'));

        expect($root . '/a/b/c')
            ->toBeDirectory()
            ->and(file_get_contents($root . '/a/b/c/d.md'))
            ->toBe('deep');
    } finally {
        rmTree($root);
    }
});

it('reports `unchanged` when existing content is byte-identical', function (): void {
    $root = tempProjectRoot();
    try {
        $writer = new FileWriter();
        $writer->write($root, new PendingWrite('x.md', 'content'));
        $second = $writer->write($root, new PendingWrite('x.md', 'content'));

        expect($second->action)->toBe(WriteAction::UNCHANGED);
    } finally {
        rmTree($root);
    }
});

it('reports `would-write` in check mode when content differs', function (): void {
    $root = tempProjectRoot();
    try {
        $writer = new FileWriter();
        $result = $writer->write($root, new PendingWrite('new.md', 'fresh'), checkOnly: true);

        expect($result->action)->toBe(WriteAction::WOULD_WRITE)
            ->and(file_exists($root . '/new.md'))
            ->toBeFalse();
    } finally {
        rmTree($root);
    }
});

it('reports `unchanged` in check mode when content matches', function (): void {
    $root = tempProjectRoot();
    try {
        $writer = new FileWriter();
        $writer->write($root, new PendingWrite('x.md', 'same'));
        $result = $writer->write($root, new PendingWrite('x.md', 'same'), checkOnly: true);

        expect($result->action)->toBe(WriteAction::UNCHANGED);
    } finally {
        rmTree($root);
    }
});

it('rejects absolute paths', function (): void {
    (new FileWriter())->write('/some/root', new PendingWrite('/etc/passwd', 'x'));
})->throws(PathTraversalException::class);

it('rejects `..` segments anywhere in the path', function (): void {
    (new FileWriter())->write('/some/root', new PendingWrite('foo/../../escaped.md', 'x'));
})->throws(PathTraversalException::class);

it('rejects leading `..`', function (): void {
    (new FileWriter())->write('/some/root', new PendingWrite('../escape.md', 'x'));
})->throws(PathTraversalException::class);

it('rejects empty paths', function (): void {
    (new FileWriter())->write('/some/root', new PendingWrite('', 'x'));
})->throws(PathTraversalException::class);

it('rejects backslash-leading paths (Windows-ish)', function (): void {
    (new FileWriter())->write('/some/root', new PendingWrite('\\evil.md', 'x'));
})->throws(PathTraversalException::class);

it('skips writes whose immediate parent dir is a user-placed symlink', function (): void {
    // Repro the collectiq dogfood layout: `.claude/skills/<name>` is a
    // symlink to `../../.ai/skills/<name>/`. A sync write of
    // `.claude/skills/<name>/SKILL.md` resolves through the link and
    // overwrites the source. FileWriter must refuse.
    $root = tempProjectRoot();
    try {
        // Set up the source tree the symlink points to.
        mkdir($root . '/.ai/skills/livewire-development', 0o755, recursive: true);
        file_put_contents($root . '/.ai/skills/livewire-development/SKILL.md', 'USER SOURCE');

        // Create the symlinked agent dir.
        mkdir($root . '/.claude/skills', 0o755, recursive: true);
        symlink('../../.ai/skills/livewire-development', $root . '/.claude/skills/livewire-development');

        $written = (new FileWriter())->write(
            $root,
            new PendingWrite('.claude/skills/livewire-development/SKILL.md', 'SYNC OVERWRITE'),
        );

        expect($written->action)->toBe(WriteAction::SKIPPED_SYMLINK)
            ->and(file_get_contents($root . '/.ai/skills/livewire-development/SKILL.md'))->toBe('USER SOURCE');
    } finally {
        rmTree($root);
    }
});

it('skips writes when any deeper path segment is a symlink', function (): void {
    // Less-common case: `.claude/skills/` itself is a symlink. Walks each
    // segment so this still gets caught.
    $root = tempProjectRoot();
    try {
        mkdir($root . '/.ai-skills/foo', 0o755, recursive: true);
        file_put_contents($root . '/.ai-skills/foo/SKILL.md', 'USER SOURCE');

        mkdir($root . '/.claude', 0o755, recursive: true);
        symlink('../.ai-skills', $root . '/.claude/skills');

        $written = (new FileWriter())->write(
            $root,
            new PendingWrite('.claude/skills/foo/SKILL.md', 'SYNC OVERWRITE'),
        );

        expect($written->action)->toBe(WriteAction::SKIPPED_SYMLINK)
            ->and(file_get_contents($root . '/.ai-skills/foo/SKILL.md'))->toBe('USER SOURCE');
    } finally {
        rmTree($root);
    }
});

it('writes normally when no path segment is a symlink', function (): void {
    // Regression guard — make sure the new check does not break the
    // happy path.
    $root = tempProjectRoot();
    try {
        $written = (new FileWriter())->write(
            $root,
            new PendingWrite('.claude/skills/foo/SKILL.md', 'happy'),
        );

        expect($written->action)->toBe(WriteAction::WROTE)
            ->and(file_get_contents($root . '/.claude/skills/foo/SKILL.md'))->toBe('happy');
    } finally {
        rmTree($root);
    }
});
