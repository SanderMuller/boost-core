<?php declare(strict_types=1);

namespace SanderMuller\BoostCore\Sync;

/**
 * Stateless filesystem/path helpers shared across the sync passes (extracted
 * from SyncEngine, maintenance cycle 2026-05). Used by the stale-cleanup,
 * wrapper-claim, manifest, and orphan-reap logic, so they live in one place
 * rather than being duplicated when those passes are decomposed.
 */
final class ManagedFileOps
{
    /**
     * Walk up from a just-deleted file removing now-empty parent directories,
     * stopping at the project root (never above it, never a non-empty dir, and
     * bailing on any scandir/rmdir failure).
     */
    public static function removeEmptyParentDirs(string $projectRoot, string $absolute): void
    {
        $projectRoot = rtrim($projectRoot, '/');
        $parent = dirname($absolute);
        while ($parent !== $projectRoot && str_starts_with($parent, $projectRoot . '/')) {
            $entries = @scandir($parent);
            if ($entries === false) {
                return;
            }

            $remaining = array_values(array_diff($entries, ['.', '..']));
            if ($remaining !== []) {
                return;
            }

            if (! @rmdir($parent)) {
                return;
            }

            $parent = dirname($parent);
        }
    }

    /**
     * Normalise a wrapper-claimed path: `\` → `/`, drop empty + `.` segments.
     */
    public static function canonicalizeWrapperPath(string $raw): string
    {
        $normalized = str_replace('\\', '/', $raw);

        $out = [];
        foreach (explode('/', $normalized) as $segment) {
            if ($segment === '') {
                continue;
            }
            if ($segment === '.') {
                continue;
            }
            $out[] = $segment;
        }

        return implode('/', $out);
    }

    /**
     * Is `$canonicalRelative` exactly a wrapper claim OR under a wrapper
     * DIRECTORY claim (prefix + `/` boundary, so `.agents/skills/foobar` does
     * NOT match a claim of `.agents/skills/foo`)?
     *
     * @param  array<string, string>  $wrapperExcludedPaths
     */
    public static function isUnderWrapperClaim(string $canonicalRelative, array $wrapperExcludedPaths): bool
    {
        if (isset($wrapperExcludedPaths[$canonicalRelative])) {
            return true;
        }

        foreach (array_keys($wrapperExcludedPaths) as $claim) {
            if (str_starts_with($canonicalRelative, $claim . '/')) {
                return true;
            }
        }

        return false;
    }

    /**
     * sha256 of a file's current content, or null when it is absent/unreadable.
     */
    public static function fileSha(string $projectRoot, string $relativePath): ?string
    {
        $absolute = $projectRoot . '/' . $relativePath;
        $content = is_file($absolute) ? @file_get_contents($absolute) : false;

        return $content === false ? null : hash('sha256', $content);
    }
}
