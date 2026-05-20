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
