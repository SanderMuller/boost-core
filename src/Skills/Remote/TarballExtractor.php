<?php declare(strict_types=1);

namespace SanderMuller\BoostCore\Skills\Remote;

use PharData;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use Throwable;
use UnexpectedValueException;

/**
 * Extracts a `.tar.gz` (path-mode repo tarball) into a destination directory.
 *
 * Same iterate-then-whitelist pattern as {@see BundleExtractor}: walk every
 * archive entry via `PharData`'s recursive iterator, reject any unsafe one
 * (`..`, absolute path, symlink, oversize), and only then call
 * `extractTo()` once over the whole tarball.
 *
 * `PharData` requires a `.tar.gz` (or `.tgz`) extension on the source file.
 * Callers should download the tarball to a path with that extension, or
 * rename / symlink it before calling.
 *
 * Caps mirror {@see BundleExtractor}:
 *  - 10,000 entries per tarball
 *  - 50MB per file
 *  - 200MB total uncompressed
 */
final readonly class TarballExtractor
{
    public function __construct(
        private int $maxEntries = BundleExtractor::MAX_ENTRIES,
        private int $maxFileBytes = BundleExtractor::MAX_FILE_BYTES,
        private int $maxTotalBytes = BundleExtractor::MAX_TOTAL_BYTES,
    ) {}

    public function extract(string $archivePath, string $destinationPath): void
    {
        // Extract-then-validate order. The previous pre-validation pass
        // walked PharData with RecursiveIteratorIterator, which on some
        // Linux/PHP/libphar combinations left extractTo producing
        // zero-byte files (state contamination or cache identity — both
        // empirically reproducible only on CI). Order-flipping sidesteps
        // PharData iteration entirely: stage the extract to a temp dir,
        // walk the resulting files on disk (cheap, predictable), reject
        // if any entry violates the safety contract, atomic-move staging
        // to destination if all-safe.
        $phar = $this->openPhar($archivePath);

        $stagingDir = sys_get_temp_dir() . '/boost-tar-staging-' . bin2hex(random_bytes(8));
        if (! mkdir($stagingDir, 0o755, recursive: true)) {
            throw new RemoteExtractException(
                sprintf('Cannot create staging directory `%s`.', $stagingDir),
                RemoteExtractException::DISK_FULL,
            );
        }

        try {
            try {
                $phar->extractTo($stagingDir, null, overwrite: true);
            } catch (Throwable $e) {
                throw new RemoteExtractException(
                    sprintf('Tarball extraction failed at `%s`: %s', $archivePath, $e->getMessage()),
                    RemoteExtractException::DISK_FULL,
                    $e,
                );
            }

            $this->assertAllEntriesSafe($stagingDir, $archivePath);

            if (! is_dir($destinationPath) && ! mkdir($destinationPath, 0o755, recursive: true) && ! is_dir($destinationPath)) {
                throw new RemoteExtractException(
                    sprintf('Cannot create destination directory `%s`.', $destinationPath),
                    RemoteExtractException::DISK_FULL,
                );
            }

            $this->mergeStagingInto($stagingDir, $destinationPath);
        } finally {
            BundleExtractor::recursivelyRemove($stagingDir);
        }
    }

    /**
     * Move every file from $stagingDir into $destinationPath, preserving
     * the directory structure. Uses `rename` for atomicity within a
     * filesystem; falls back to copy+unlink across filesystems.
     */
    private function mergeStagingInto(string $stagingDir, string $destinationPath): void
    {
        $iter = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($stagingDir, RecursiveDirectoryIterator::SKIP_DOTS),
            RecursiveIteratorIterator::SELF_FIRST,
        );

        foreach ($iter as $entry) {
            /** @var \SplFileInfo $entry */
            $relative = ltrim(substr($entry->getPathname(), strlen($stagingDir)), '/');
            $target = $destinationPath . '/' . $relative;

            if ($entry->isDir()) {
                if (! is_dir($target) && ! @mkdir($target, 0o755, recursive: true) && ! is_dir($target)) {
                    throw new RemoteExtractException(
                        sprintf('Cannot create destination subdir `%s`.', $target),
                        RemoteExtractException::DISK_FULL,
                    );
                }

                continue;
            }

            $parent = dirname($target);
            if (! is_dir($parent) && ! @mkdir($parent, 0o755, recursive: true) && ! is_dir($parent)) {
                throw new RemoteExtractException(
                    sprintf('Cannot create destination subdir `%s`.', $parent),
                    RemoteExtractException::DISK_FULL,
                );
            }

            if (! @rename($entry->getPathname(), $target)) {
                if (! @copy($entry->getPathname(), $target)) {
                    throw new RemoteExtractException(
                        sprintf('Cannot move `%s` → `%s`.', $entry->getPathname(), $target),
                        RemoteExtractException::DISK_FULL,
                    );
                }
                @unlink($entry->getPathname());
            }
        }
    }

    private function openPhar(string $archivePath): PharData
    {
        try {
            return new PharData($archivePath);
        } catch (UnexpectedValueException $unexpectedValueException) {
            throw new RemoteExtractException(
                sprintf('Malformed tarball at `%s`: %s', $archivePath, $unexpectedValueException->getMessage()),
                RemoteExtractException::MALFORMED,
                $unexpectedValueException,
            );
        }
    }

    private function assertAllEntriesSafe(string $stagingDir, string $archivePath): void
    {
        $count = 0;
        $totalBytes = 0;

        $iter = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($stagingDir, RecursiveDirectoryIterator::SKIP_DOTS),
            RecursiveIteratorIterator::SELF_FIRST,
        );

        foreach ($iter as $entry) {
            /** @var \SplFileInfo $entry */
            ++$count;
            if ($count > $this->maxEntries) {
                throw new RemoteExtractException(
                    sprintf('Tarball `%s` has > %d entries (zip-bomb defense).', $archivePath, $this->maxEntries),
                    RemoteExtractException::ENTRY_COUNT,
                );
            }

            $relName = ltrim(substr($entry->getPathname(), strlen($stagingDir)), '/');
            $this->assertEntryNameSafe($relName);

            if ($entry->isLink()) {
                throw new RemoteExtractException(
                    sprintf('Tarball entry `%s` is a symbolic link; strict rejection per §9.', $relName),
                    RemoteExtractException::SYMLINK,
                );
            }

            if ($entry->isDir()) {
                continue;
            }

            $size = (int) $entry->getSize();
            if ($size > $this->maxFileBytes) {
                throw new RemoteExtractException(
                    sprintf('Tarball entry `%s` exceeds the %d-byte per-file cap.', $relName, $this->maxFileBytes),
                    RemoteExtractException::SIZE_LIMIT,
                );
            }

            $totalBytes += $size;
            if ($totalBytes > $this->maxTotalBytes) {
                throw new RemoteExtractException(
                    sprintf('Tarball `%s` uncompressed total exceeds the %d-byte cap.', $archivePath, $this->maxTotalBytes),
                    RemoteExtractException::SIZE_LIMIT,
                );
            }
        }
    }

    private function assertEntryNameSafe(string $name): void
    {
        $normalized = str_replace('\\', '/', $name);

        if (str_starts_with($normalized, '/')) {
            throw new RemoteExtractException(
                sprintf('Tarball entry `%s` uses an absolute path.', $name),
                RemoteExtractException::ABSOLUTE_PATH,
            );
        }

        foreach (explode('/', $normalized) as $segment) {
            if ($segment === '..') {
                throw new RemoteExtractException(
                    sprintf('Tarball entry `%s` contains a `..` path-traversal segment.', $name),
                    RemoteExtractException::PATH_TRAVERSAL,
                );
            }
        }
    }
}
