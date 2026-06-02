<?php declare(strict_types=1);

namespace SanderMuller\BoostCore\Skills\Remote;

use Throwable;
use ZipArchive;

/**
 * Extracts a `.skill` ZIP bundle into a destination directory with
 * mandatory sanitization.
 *
 * Iterate-then-whitelist pattern: open the archive, walk every entry
 * checking name (no `..`, no absolute paths), type (no symlinks), and
 * size (per-file + total caps). Any unsafe entry rejects the SOURCE —
 * extraction never starts. On a clean pass, `ZipArchive::extractTo()`
 * runs once over the whole archive.
 *
 * Caps:
 *  - 10,000 entries per archive (zip-bomb defense)
 *  - 50MB per file
 *  - 200MB total uncompressed size
 *
 * @internal
 */
final readonly class BundleExtractor
{
    public const MAX_ENTRIES = 10_000;

    public const MAX_FILE_BYTES = 50 * 1024 * 1024;

    public const MAX_TOTAL_BYTES = 200 * 1024 * 1024;

    public function __construct(
        private int $maxEntries = self::MAX_ENTRIES,
        private int $maxFileBytes = self::MAX_FILE_BYTES,
        private int $maxTotalBytes = self::MAX_TOTAL_BYTES,
    ) {}

    public function extract(string $archivePath, string $destinationPath): void
    {
        $zip = new ZipArchive();
        $openResult = $zip->open($archivePath);
        if ($openResult !== true) {
            throw new RemoteExtractException(
                sprintf('Malformed ZIP at `%s` (open error %s).', $archivePath, var_export($openResult, true)),
                RemoteExtractException::MALFORMED,
            );
        }

        try {
            $this->assertAllEntriesSafe($zip, $archivePath);

            if (! is_dir($destinationPath) && ! mkdir($destinationPath, 0o755, recursive: true) && ! is_dir($destinationPath)) {
                throw new RemoteExtractException(
                    sprintf('Cannot create destination directory `%s`.', $destinationPath),
                    RemoteExtractException::DISK_FULL,
                );
            }

            if (! $zip->extractTo($destinationPath)) {
                throw new RemoteExtractException(
                    sprintf('ZIP extraction failed (likely disk full or permissions) at `%s`.', $destinationPath),
                    RemoteExtractException::DISK_FULL,
                );
            }
        } finally {
            $zip->close();
        }
    }

    private function assertAllEntriesSafe(ZipArchive $zip, string $archivePath): void
    {
        if ($zip->numFiles > $this->maxEntries) {
            throw new RemoteExtractException(
                sprintf('ZIP `%s` has %d entries; cap is %d (zip-bomb defense).', $archivePath, $zip->numFiles, $this->maxEntries),
                RemoteExtractException::ENTRY_COUNT,
            );
        }

        $totalBytes = 0;
        for ($i = 0; $i < $zip->numFiles; ++$i) {
            $stat = $zip->statIndex($i);
            if ($stat === false || ! isset($stat['name'], $stat['size'])) {
                throw new RemoteExtractException(
                    sprintf('ZIP `%s` entry %d is unreadable.', $archivePath, $i),
                    RemoteExtractException::MALFORMED,
                );
            }

            $name = $stat['name'];
            $size = $stat['size'];

            $this->assertEntryNameSafe($name);
            $this->assertNotSymlink($zip, $i, $name);

            if ($size > $this->maxFileBytes) {
                throw new RemoteExtractException(
                    sprintf('ZIP entry `%s` exceeds the %d-byte per-file cap (uncompressed %d).', $name, $this->maxFileBytes, $size),
                    RemoteExtractException::SIZE_LIMIT,
                );
            }

            $totalBytes += $size;
            if ($totalBytes > $this->maxTotalBytes) {
                throw new RemoteExtractException(
                    sprintf('ZIP `%s` uncompressed total exceeds the %d-byte cap.', $archivePath, $this->maxTotalBytes),
                    RemoteExtractException::SIZE_LIMIT,
                );
            }
        }
    }

    private function assertEntryNameSafe(string $name): void
    {
        // Normalize backslashes (some ZIPs use Windows separators).
        $normalized = str_replace('\\', '/', $name);

        if (str_starts_with($normalized, '/')) {
            throw new RemoteExtractException(
                sprintf('ZIP entry `%s` uses an absolute path.', $name),
                RemoteExtractException::ABSOLUTE_PATH,
            );
        }

        // Windows drive-letter prefix (`C:`).
        if (preg_match('/^[A-Za-z]:/', $normalized) === 1) {
            throw new RemoteExtractException(
                sprintf('ZIP entry `%s` uses an absolute (drive-letter) path.', $name),
                RemoteExtractException::ABSOLUTE_PATH,
            );
        }

        foreach (explode('/', $normalized) as $segment) {
            if ($segment === '..') {
                throw new RemoteExtractException(
                    sprintf('ZIP entry `%s` contains a `..` path-traversal segment.', $name),
                    RemoteExtractException::PATH_TRAVERSAL,
                );
            }
        }
    }

    private function assertNotSymlink(ZipArchive $zip, int $index, string $name): void
    {
        $opsys = 0;
        $attr = 0;
        if (! $zip->getExternalAttributesIndex($index, $opsys, $attr)) {
            // Fail closed: unreadable external attrs mean we cannot prove
            // the entry is NOT a symlink, so reject the whole archive. A
            // crafted ZIP that suppresses attr-readability would otherwise
            // bypass the symlink check entirely.
            throw new RemoteExtractException(
                sprintf('ZIP entry `%s`: external attributes unreadable, cannot verify it is not a symlink.', $name),
                RemoteExtractException::SYMLINK,
            );
        }

        // Unix file-type bits live in the upper 16 bits of the external
        // attribute, bits 12-15. Symlinks are mode 0xA000.
        $attrInt = is_int($attr) ? $attr : (is_numeric($attr) ? (int) $attr : 0);
        if ($opsys === ZipArchive::OPSYS_UNIX && (($attrInt >> 16) & 0xF000) === 0xA000) {
            throw new RemoteExtractException(
                sprintf('ZIP entry `%s` is a symbolic link; strict rejection per §9.', $name),
                RemoteExtractException::SYMLINK,
            );
        }
    }

    /**
     * Best-effort recursive cleanup used by callers on extraction failure.
     */
    public static function recursivelyRemove(string $path): void
    {
        if (! is_dir($path)) {
            if (is_file($path) || is_link($path)) {
                @unlink($path);
            }

            return;
        }

        $entries = @scandir($path);
        if ($entries === false) {
            return;
        }

        foreach ($entries as $entry) {
            if ($entry === '.') {
                continue;
            }

            if ($entry === '..') {
                continue;
            }

            try {
                self::recursivelyRemove($path . '/' . $entry);
            } catch (Throwable) {
                // best-effort
            }
        }

        @rmdir($path);
    }
}
