<?php declare(strict_types=1);

namespace SanderMuller\BoostCore\Sync;

/**
 * Stateless filesystem/path helpers shared across the sync passes. Used by the
 * stale-cleanup, wrapper-claim, manifest, and orphan-reap logic, so they live in
 * one place rather than being duplicated across those passes.
 *
 * @internal
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
     * Narrow the on-disk managed files to the ones boost may CLAIM in the manifest.
     *
     * The enumeration behind `$managedFilesOnDisk` is a raw directory walk of the
     * boost-managed gitignore patterns, so it also picks up files another tool wrote
     * into those directories (laravel/boost installs its bundled skills there). Left
     * unfiltered, the manifest would adopt such a file as boost's own — and the very
     * next sync, seeing it in the prior manifest, would delete it. That turned the
     * stale sweep's preservation into a one-sync reprieve.
     *
     * A path is claimable when this sync touched it (any {@see WrittenFile} action,
     * including UNCHANGED and SKIPPED_SYMLINK), when boost already owned it, or when
     * a wrapper claims it. Everything else belongs to another writer: not recorded,
     * so it is never reaped. The failure direction is a leak, never a delete.
     *
     * @param  list<string>  $managedFilesOnDisk
     * @param  list<WrittenFile>  $writes
     * @param  array<string, string>  $wrapperPaths
     * @return list<string>
     */
    public static function claimableManagedFiles(array $managedFilesOnDisk, array $writes, SyncManifest $priorManifest, array $wrapperPaths): array
    {
        $written = [];
        foreach ($writes as $write) {
            $written[$write->relativePath] = true;
        }

        $claimable = [];
        foreach ($managedFilesOnDisk as $relativePath) {
            if (isset($written[$relativePath]) || $priorManifest->has($relativePath)) {
                $claimable[] = $relativePath;

                continue;
            }

            if (self::isUnderWrapperClaim(self::canonicalizeWrapperPath($relativePath), $wrapperPaths)) {
                $claimable[] = $relativePath;
            }
        }

        return $claimable;
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
