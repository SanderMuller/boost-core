<?php

declare(strict_types=1);
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
