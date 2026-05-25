<?php declare(strict_types=1);

namespace SanderMuller\BoostCore\Skills\Remote;

use PharData;
use PharFileInfo;
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
        // Use SEPARATE PharData instances for the safety walk and the
        // extraction. RecursiveIteratorIterator over PharData advances the
        // archive's internal iterator state; on some Linux/PHP/libphar
        // combinations subsequent `extractTo` then writes zero-byte files
        // (the iterator is at EOF; the extractor's gzip stream handle
        // depends on the shared internal cursor). macOS happens not to hit
        // this — empirically reproducible only in CI.
        $safetyPhar = $this->openPhar($archivePath);
        $this->assertAllEntriesSafe($safetyPhar, $archivePath);
        unset($safetyPhar);

        if (! is_dir($destinationPath) && ! mkdir($destinationPath, 0o755, recursive: true) && ! is_dir($destinationPath)) {
            throw new RemoteExtractException(
                sprintf('Cannot create destination directory `%s`.', $destinationPath),
                RemoteExtractException::DISK_FULL,
            );
        }

        $extractPhar = $this->openPhar($archivePath);

        try {
            $extractPhar->extractTo($destinationPath, null, overwrite: true);
        } catch (Throwable $e) {
            throw new RemoteExtractException(
                sprintf('Tarball extraction failed at `%s`: %s', $destinationPath, $e->getMessage()),
                RemoteExtractException::DISK_FULL,
                $e,
            );
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

    private function assertAllEntriesSafe(PharData $phar, string $archivePath): void
    {
        $count = 0;
        $totalBytes = 0;
        // `PharData::getPath()` returns the host filesystem path of the
        // archive (e.g. `/tmp/foo.tar`), NOT the `phar://...` URL that
        // prefixes iterated-entry pathnames. The pathname looks like
        // `phar:///tmp/foo.tar/<entry>` — the prefix to strip is
        // `phar://` (7 chars) + the host path + `/` (1 char).
        $archiveRootLen = strlen('phar://') + strlen($phar->getPath()) + 1;
        /** @var PharFileInfo $fileInfo */
        foreach (new RecursiveIteratorIterator($phar) as $fileInfo) {
            ++$count;
            if ($count > $this->maxEntries) {
                throw new RemoteExtractException(
                    sprintf('Tarball `%s` has > %d entries (zip-bomb defense).', $archivePath, $this->maxEntries),
                    RemoteExtractException::ENTRY_COUNT,
                );
            }

            $pathName = $fileInfo->getPathname();
            // `phar://<archive-path>/<entry-name>` — strip the prefix to get the in-archive entry name.
            $relName = $this->stripPharPrefix($pathName, $archiveRootLen);

            $this->assertEntryNameSafe($relName);

            if ($fileInfo->isLink()) {
                throw new RemoteExtractException(
                    sprintf('Tarball entry `%s` is a symbolic link; strict rejection per §9.', $relName),
                    RemoteExtractException::SYMLINK,
                );
            }

            $size = (int) $fileInfo->getSize();
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

    private function stripPharPrefix(string $pharPath, int $skip): string
    {
        // Defensive — if for any reason the path is shorter than expected,
        // fall back to the basename so we still have something checkable.
        return strlen($pharPath) > $skip ? substr($pharPath, $skip) : basename($pharPath);
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
