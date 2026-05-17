<?php

declare(strict_types=1);

namespace SanderMuller\BoostCore\Sync;

use RuntimeException;

/**
 * Atomic file writer with path-traversal guard.
 *
 * Contract:
 * - Refuses absolute paths or paths containing `..` segments (PathTraversalException).
 * - Creates parent directories as needed.
 * - Atomic write via tempfile + rename — readers never see a half-written file.
 * - Detects byte-identical content and reports `unchanged` without rewriting.
 * - Check mode reports `would-write` for differing content without touching disk.
 */
final class FileWriter
{
    public function write(string $projectRoot, PendingWrite $pending, bool $checkOnly = false): WrittenFile
    {
        $absolute = $this->resolveInside($projectRoot, $pending->relativePath);

        if ($this->existingContentMatches($absolute, $pending->content)) {
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
        $this->atomicWrite($absolute, $pending->content);

        return new WrittenFile(
            relativePath: $pending->relativePath,
            absolutePath: $absolute,
            action: WriteAction::WROTE,
        );
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

    private function existingContentMatches(string $absolutePath, string $expected): bool
    {
        if (! is_file($absolutePath)) {
            return false;
        }

        $existing = @file_get_contents($absolutePath);

        return $existing === $expected;
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
