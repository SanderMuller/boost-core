<?php declare(strict_types=1);

namespace SanderMuller\BoostCore\Sync;

use RuntimeException;
use SanderMuller\BoostCore\Conventions\ManagedRegion;

/**
 * Atomic file writer with path-traversal guard.
 *
 * Contract:
 * - Refuses absolute paths or paths containing `..` segments (PathTraversalException).
 * - Creates parent directories as needed.
 * - Atomic write via tempfile + rename — readers never see a half-written file.
 * - Detects byte-identical content and reports `unchanged` without rewriting.
 * - Check mode reports `would-write` for differing content without touching disk.
 * - **Refuses to follow user-placed symlinks.** If any path segment under
 *   `$projectRoot` (other than the project-root itself) is a symbolic link,
 *   the write is SKIPPED with `WriteAction::SKIPPED_SYMLINK`. Honors the
 *   "live symlinks owned by consumer" contract documented in
 *   `SyncEngine::pruneDeadSymlinks()` — a sync write that followed
 *   `.claude/skills/<name>` (symlink → `../../.ai/skills/<name>/`) would
 *   overwrite the user's source file in `.ai/skills/`, silently.
 *
 * @internal
 */
final class FileWriter
{
    public function write(string $projectRoot, PendingWrite $pending, bool $checkOnly = false): WrittenFile
    {
        $absolute = $this->resolveInside($projectRoot, $pending->relativePath);

        if ($this->anySegmentIsSymlink($projectRoot, $pending->relativePath)) {
            return new WrittenFile(
                relativePath: $pending->relativePath,
                absolutePath: $absolute,
                action: WriteAction::SKIPPED_SYMLINK,
            );
        }

        $finalContent = $this->resolveFinalContent($absolute, $pending);

        if ($finalContent === null) {
            return new WrittenFile(
                relativePath: $pending->relativePath,
                absolutePath: $absolute,
                action: WriteAction::UNCHANGED,
            );
        }

        if ($checkOnly) {
            return new WrittenFile(
                relativePath: $pending->relativePath,
                absolutePath: $absolute,
                action: WriteAction::WOULD_WRITE,
            );
        }

        $this->ensureParentDirectoryExists($absolute);
        $this->atomicWrite($absolute, $finalContent);

        return new WrittenFile(
            relativePath: $pending->relativePath,
            absolutePath: $absolute,
            action: WriteAction::WROTE,
        );
    }

    /**
     * Returns the final bytes to land on disk, or null when the resolved
     * content is byte-identical to what's already there.
     *
     * When `$pending->managedRegion` is non-null, the existing file content
     * (if any) is preserved OUTSIDE the markers — `ManagedRegion::render()`
     * replaces only the body between start/end markers. Otherwise the write
     * is wholesale (`$pending->content` IS the file's final content).
     */
    private function resolveFinalContent(string $absolute, PendingWrite $pending): ?string
    {
        $existing = is_file($absolute) ? @file_get_contents($absolute) : null;
        $existing = $existing === false ? null : $existing;

        if (! $pending->managedRegion instanceof ManagedRegion) {
            if ($existing === $pending->content) {
                return null;
            }

            return $pending->content;
        }

        return $pending->managedRegion->render($existing, $pending->content);
    }

    /**
     * Walk each path segment under `$projectRoot` (exclusive) and return
     * true if any is a symlink. Catches both the common dir-symlink case
     * (e.g. `.claude/skills/<name>` → `../../.ai/skills/<name>`) and the
     * less-common nested case (e.g. `.claude/skills/` itself a symlink).
     * The project root is never checked — the user owns it and a write
     * that resolves to inside it via a project-root symlink is still
     * "inside the project" by user intent.
     */
    private function anySegmentIsSymlink(string $projectRoot, string $relative): bool
    {
        $projectRoot = rtrim($projectRoot, '/');
        $segments = preg_split('#[/\\\\]+#', ltrim($relative, '/\\'));
        if ($segments === false) {
            return false;
        }

        $accumulator = $projectRoot;
        foreach ($segments as $segment) {
            if ($segment === '') {
                continue;
            }

            $accumulator .= '/' . $segment;
            if (is_link($accumulator)) {
                return true;
            }
        }

        return false;
    }

    private function resolveInside(string $projectRoot, string $relative): string
    {
        $projectRoot = rtrim($projectRoot, '/');

        if ($relative === '' || str_starts_with($relative, '/') || str_starts_with($relative, '\\')) {
            throw new PathTraversalException($relative, $projectRoot);
        }

        // Reject `..` segments anywhere — strict but predictable.
        $segments = preg_split('#[/\\\\]+#', $relative);
        if ($segments === false) {
            throw new PathTraversalException($relative, $projectRoot);
        }

        foreach ($segments as $segment) {
            if ($segment === '..') {
                throw new PathTraversalException($relative, $projectRoot);
            }
        }

        return $projectRoot . '/' . ltrim($relative, '/');
    }

    private function ensureParentDirectoryExists(string $absolutePath): void
    {
        $dir = dirname($absolutePath);
        if (is_dir($dir)) {
            return;
        }

        if (! @mkdir($dir, 0o755, recursive: true) && ! is_dir($dir)) {
            throw new RuntimeException(sprintf('Failed to create directory "%s".', $dir));
        }
    }

    private function atomicWrite(string $absolutePath, string $content): void
    {
        $temp = $absolutePath . '.boost.' . bin2hex(random_bytes(4)) . '.tmp';

        if (file_put_contents($temp, $content) === false) {
            throw new RuntimeException(sprintf('Failed to write temp file "%s".', $temp));
        }

        if (! rename($temp, $absolutePath)) {
            @unlink($temp);
            throw new RuntimeException(sprintf('Failed to rename "%s" to "%s".', $temp, $absolutePath));
        }
    }
}
