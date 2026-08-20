<?php declare(strict_types=1);

namespace SanderMuller\BoostCore\Skills\Remote;

use PharData;
use PharFileInfo;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use SplFileInfo;
use Symfony\Component\Process\Process;
use UnexpectedValueException;

/**
 * Extracts a `.tar.gz` (path-mode repo tarball) into a destination directory.
 *
 * Validate-before-write: the archive is enumerated WITHOUT extracting — entry
 * names via `tar -tzf` (rejecting `..` and absolute paths through
 * {@see assertEntryNameSafe}) and entry types via `tar -tvzf` (rejecting symlink
 * members), plus size / entry-count caps read from `PharData` metadata. Only
 * once every entry is proven safe does the extraction run. `PharData` is NOT
 * used for name/type safety: it reports tar symlink members as regular files
 * and enumerates nothing for archives containing an absolute-path or `..`
 * member, so it cannot detect those threats — `tar -t` is the enumerator,
 * `PharData` only sizes the (already name- and type-checked) entries.
 *
 * Extraction shells out to the system `tar` command: `PharData::extractTo()`
 * produced zero-byte files on some Linux/PHP/libphar combinations (empirically
 * reproducible only on CI). After extraction the staged tree is re-scanned
 * ({@see assertAllEntriesSafe}) as defense-in-depth before being merged into
 * the destination.
 *
 * `PharData` requires a `.tar.gz` (or `.tgz`) extension on the source file.
 * Callers should download the tarball to a path with that extension, or
 * rename / symlink it before calling.
 *
 * Caps mirror {@see BundleExtractor}:
 *  - 10,000 entries per tarball
 *  - 50MB per file
 *  - 200MB total uncompressed
 *
 * @internal
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
        // Reject any unsafe entry — malformed, `..`, absolute path, symlink, or
        // oversize — BEFORE extracting a single byte (see assertArchiveSafe).
        // Name/type safety is checked via `tar -t` first, because PharData
        // itself throws (MALFORMED) on an archive that carries an absolute-path,
        // `..`, or symlink member — which would otherwise mask the precise
        // reason code. Extraction is handed to the system `tar` command below.
        $this->assertArchiveSafe($archivePath);

        $stagingDir = sys_get_temp_dir() . '/boost-tar-staging-' . bin2hex(random_bytes(8));
        if (! mkdir($stagingDir, 0o755, recursive: true)) {
            throw new RemoteExtractException(
                sprintf('Cannot create staging directory `%s`.', $stagingDir),
                RemoteExtractException::DISK_FULL,
            );
        }

        try {
            // PharData::extractTo for `.tar.gz` produces zero-byte files
            // on some Linux/PHP/libphar combinations (empirically
            // reproducible only on CI). Shell out to system `tar` instead
            // — POSIX-standard, available on every supported platform,
            // and predictable about content extraction.
            //
            // `-xzf` for gzipped tarballs; `-C` sets the destination.
            // No `-p` (preserve permissions) — we don't ship executables
            // and don't want odd mode bits surviving extraction.
            $process = new Process(['tar', '-xzf', $archivePath, '-C', $stagingDir]);
            $process->run();
            if (! $process->isSuccessful()) {
                throw new RemoteExtractException(
                    sprintf(
                        'Tarball extraction failed at `%s` (tar exit %d): %s',
                        $archivePath,
                        $process->getExitCode() ?? -1,
                        trim($process->getErrorOutput() . "\n" . $process->getOutput()),
                    ),
                    RemoteExtractException::DISK_FULL,
                );
            }

            // Defense-in-depth: names, types, and sizes were already validated
            // pre-extraction by assertArchiveSafe(); re-scan the staged tree so
            // a `tar` that somehow materialized something unexpected still can't
            // reach the destination.
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
            /** @var SplFileInfo $entry */
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

    /**
     * Reject an unsafe archive BEFORE extracting a single byte. Names come from
     * `tar -tzf` (so `..` / absolute-path members are caught by
     * {@see assertEntryNameSafe}); symlink members are caught from the
     * `tar -tvzf` verbose listing (leading `l` mode bit or a ` -> target`
     * suffix); size / entry-count caps are read from `PharData` metadata after
     * names and types are proven safe. Throws {@see RemoteExtractException} on
     * the first violation.
     */
    private function assertArchiveSafe(string $archivePath): void
    {
        // `-P` keeps RAW member names in the listing. GNU tar strips a leading
        // `/` and `..` components from displayed names by default (so does
        // bsdtar), which would hide an absolute-path or traversal member from
        // the name check below and let it through on Ubuntu/GNU-tar CI. Listing
        // only — the extraction `tar -xzf` deliberately runs WITHOUT `-P`, so
        // even a missed member still cannot be written outside the staging dir.
        $names = new Process(['tar', '-P', '-tzf', $archivePath]);
        $names->run();
        if (! $names->isSuccessful()) {
            throw new RemoteExtractException(
                sprintf('Cannot list tarball `%s`: %s', $archivePath, trim($names->getErrorOutput())),
                RemoteExtractException::MALFORMED,
            );
        }

        foreach ($this->splitLines($names->getOutput()) as $name) {
            $this->assertEntryNameSafe($name);
        }

        $verbose = new Process(['tar', '-P', '-tvzf', $archivePath]);
        $verbose->run();
        if (! $verbose->isSuccessful()) {
            throw new RemoteExtractException(
                sprintf('Cannot list tarball `%s`: %s', $archivePath, trim($verbose->getErrorOutput())),
                RemoteExtractException::MALFORMED,
            );
        }

        foreach ($this->splitLines($verbose->getOutput()) as $line) {
            // A symlink member: `l` in the leading mode column, or an explicit
            // ` -> target` in the verbose line. Either marks a link across GNU
            // tar and bsdtar; reject fail-closed (a filename literally
            // containing ` -> ` rejects the whole archive — acceptable).
            if ($line !== '' && ($line[0] === 'l' || str_contains($line, ' -> '))) {
                throw new RemoteExtractException(
                    sprintf('Tarball at `%s` contains a symbolic-link member; strict rejection per §9.', $archivePath),
                    RemoteExtractException::SYMLINK,
                );
            }
        }

        // Size / entry-count caps: read metadata via PharData (no extraction).
        // Reliable here because names + types are already validated above —
        // PharData's getSize() is accurate for well-formed regular-file entries.
        // openPhar() runs only now (post name/type checks), so it also surfaces
        // a genuinely MALFORMED archive that `tar` happened to read.
        $count = 0;
        $totalBytes = 0;
        $iterator = new RecursiveIteratorIterator($this->openPhar($archivePath), RecursiveIteratorIterator::SELF_FIRST);
        foreach ($iterator as $entry) {
            /** @var PharFileInfo $entry */
            ++$count;
            if ($count > $this->maxEntries) {
                throw new RemoteExtractException(
                    sprintf('Tarball `%s` has > %d entries (zip-bomb defense).', $archivePath, $this->maxEntries),
                    RemoteExtractException::ENTRY_COUNT,
                );
            }

            if ($entry->isDir()) {
                continue;
            }

            $size = (int) $entry->getSize();
            if ($size > $this->maxFileBytes) {
                throw new RemoteExtractException(
                    sprintf('Tarball entry `%s` exceeds the %d-byte per-file cap.', $entry->getFilename(), $this->maxFileBytes),
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

    /**
     * Split process output into non-empty lines.
     *
     * @return list<string>
     */
    private function splitLines(string $output): array
    {
        $lines = preg_split('/\r?\n/', $output);
        if ($lines === false) {
            return [];
        }

        return array_values(array_filter($lines, static fn (string $line): bool => $line !== ''));
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
            /** @var SplFileInfo $entry */
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
