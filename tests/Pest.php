<?php declare(strict_types=1);
use PHPUnit\Framework\TestCase;

/*
|--------------------------------------------------------------------------
| Test Case
|--------------------------------------------------------------------------
*/

pest()->extend(TestCase::class)->in('Unit', 'Integration');

/*
|--------------------------------------------------------------------------
| Expectations
|--------------------------------------------------------------------------
*/

/*
|--------------------------------------------------------------------------
| Functions
|--------------------------------------------------------------------------
*/

/**
 * Recursive rm -rf for tmp test directories. Suppresses errors per-entry
 * so a partially-populated tmp dir still gets fully removed in `finally`
 * blocks even if a sub-entry vanished mid-cleanup. Older test files in
 * the suite define their own `rmTree*` helpers with varying signatures;
 * this is the canonical one for new tests — collapse the pre-existing
 * copies into this when next touching them.
 */
function cleanupTestDir(string $path): void
{
    if (! is_dir($path)) {
        return;
    }

    $items = scandir($path);
    if ($items === false) {
        return;
    }

    foreach ($items as $item) {
        if ($item === '.') {
            continue;
        }

        if ($item === '..') {
            continue;
        }

        $full = $path . '/' . $item;
        if (is_dir($full) && ! is_link($full)) {
            cleanupTestDir($full);
        } else {
            @unlink($full);
        }
    }

    @rmdir($path);
}

/**
 * A GitHub-shaped tarball: one wrapper directory containing the given
 * `repo-relative path => SKILL.md frontmatter` entries.
 *
 * Shared because both the discoverer's unit tests and `boost remote`'s
 * command tests need to hand a canned repo to FakeRemoteFetcher.
 *
 * @param  array<string, string>  $skills  path (`.` for the repo root) => frontmatter body
 * @param  list<string>  $extraFiles  repo-relative paths written as filler content
 */
function discoveryTarballBytes(string $wrapper, array $skills, array $extraFiles = []): string
{
    $base = sys_get_temp_dir() . '/boost-discovery-tar-' . bin2hex(random_bytes(6));
    @unlink($base . '.tar');
    @unlink($base . '.tar.gz');

    $phar = new PharData($base . '.tar');
    foreach ($skills as $path => $frontmatter) {
        $prefix = $path === '.' ? '' : rtrim($path, '/') . '/';
        $phar->addFromString($wrapper . '/' . $prefix . 'SKILL.md', "---\n{$frontmatter}\n---\nBody.");
    }

    foreach ($extraFiles as $file) {
        $phar->addFromString($wrapper . '/' . $file, 'filler');
    }

    $phar->compress(Phar::GZ);
    unset($phar);
    @unlink($base . '.tar');

    $bytes = (string) file_get_contents($base . '.tar.gz');
    @unlink($base . '.tar.gz');

    return $bytes;
}
