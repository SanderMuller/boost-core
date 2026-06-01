<?php declare(strict_types=1);

namespace SanderMuller\BoostCore\Skills\Remote;

use FilesystemIterator;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use SplFileInfo;

/**
 * Filesystem helpers used by {@see RemoteSkillCache} during slot population
 * and integrity checks. Every method here is a pure-static utility with no
 * shared state.
 */
final class RemoteSkillCacheFilesystem
{
    /**
     * Compute a stable SHA-256 tree hash for a directory: walk every file
     * recursively, sort by path, hash `<relative-path>:<file-sha256>\n`
     * concatenation. Identical inputs → identical hash; any file added,
     * removed, renamed, or modified → different hash.
     */
    public static function treeHash(string $dir): string
    {
        $files = [];
        if (! is_dir($dir)) {
            return hash('sha256', '');
        }

        $iter = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($dir, FilesystemIterator::SKIP_DOTS),
        );

        /** @var SplFileInfo $fileInfo */
        foreach ($iter as $fileInfo) {
            if (! $fileInfo->isFile()) {
                continue;
            }

            $rel = substr($fileInfo->getPathname(), strlen($dir) + 1);
            $sha = hash_file('sha256', $fileInfo->getPathname());
            $files[$rel] = is_string($sha) ? $sha : '';
        }

        ksort($files);
        $combined = '';
        foreach ($files as $path => $sha) {
            $combined .= $path . ':' . $sha . "\n";
        }

        return hash('sha256', $combined);
    }

    /**
     * If `$dir` contains exactly one subdirectory and no files, return that
     * subdirectory's path; otherwise return `$dir` unchanged. Used to unwrap
     * the single top-level directory inside a GitHub tarball
     * (`<owner>-<repo>-<sha>/...`).
     */
    public static function firstSingleSubdir(string $dir): string
    {
        $entries = @scandir($dir);
        if ($entries === false) {
            return $dir;
        }

        $realEntries = array_values(array_filter(
            $entries,
            static fn (string $e): bool => $e !== '.' && $e !== '..',
        ));
        if (count($realEntries) === 1 && is_dir($dir . '/' . $realEntries[0])) {
            return $dir . '/' . $realEntries[0];
        }

        return $dir;
    }

    /**
     * Mirror a tree from `$sourceDir` to `$destDir`, skipping any entry whose
     * relative path matches the file-inclusion blocklist (VCS dirs, IDE
     * files, lockfiles, project-metadata files at the top level).
     */
    public static function copyTreeFiltered(string $sourceDir, string $destDir): void
    {
        if (! is_dir($destDir) && ! mkdir($destDir, 0o755, recursive: true) && ! is_dir($destDir)) {
            throw new RemoteExtractException(
                sprintf('Cannot create dest dir `%s`.', $destDir),
                RemoteExtractException::DISK_FULL,
            );
        }

        $iter = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($sourceDir, FilesystemIterator::SKIP_DOTS),
            RecursiveIteratorIterator::SELF_FIRST,
        );

        /** @var SplFileInfo $fileInfo */
        foreach ($iter as $fileInfo) {
            $rel = substr($fileInfo->getPathname(), strlen($sourceDir) + 1);
            if (self::isBlockedEntry($rel)) {
                continue;
            }

            $target = $destDir . '/' . $rel;

            if ($fileInfo->isDir()) {
                if (! is_dir($target) && ! @mkdir($target, 0o755, recursive: true)) {
                    throw new RemoteExtractException(
                        sprintf('Cannot create dir `%s`.', $target),
                        RemoteExtractException::DISK_FULL,
                    );
                }

                continue;
            }

            if (! @copy($fileInfo->getPathname(), $target)) {
                throw new RemoteExtractException(
                    sprintf('Cannot copy `%s` to `%s`.', $fileInfo->getPathname(), $target),
                    RemoteExtractException::DISK_FULL,
                );
            }
        }
    }

    /**
     * File-inclusion rule: blocklist project-metadata + VCS/IDE/OS noise.
     */
    public static function isBlockedEntry(string $relPath): bool
    {
        $segments = explode('/', $relPath);
        $top = $segments[0];

        if (count($segments) === 1) {
            foreach (['README', 'LICENSE', 'CHANGELOG', 'CONTRIBUTING', 'CODE_OF_CONDUCT'] as $prefix) {
                if (stripos($top, $prefix) === 0) {
                    return true;
                }
            }

            if (in_array($top, ['package.json', 'package-lock.json', 'yarn.lock', 'pnpm-lock.yaml', 'composer.json', 'composer.lock'], true)) {
                return true;
            }
        }

        foreach ($segments as $segment) {
            if (in_array($segment, ['.git', '.github', '.idea', '.vscode', 'node_modules', 'vendor'], true)) {
                return true;
            }

            if (in_array($segment, ['.gitignore', '.gitattributes', '.DS_Store', 'Thumbs.db', '.editorconfig'], true)) {
                return true;
            }
        }

        return false;
    }
}
