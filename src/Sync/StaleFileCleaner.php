<?php declare(strict_types=1);

namespace SanderMuller\BoostCore\Sync;

use FilesystemIterator;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use SanderMuller\BoostCore\Config\BoostConfig;
use SanderMuller\BoostCore\Conventions\Diagnostic;
use SanderMuller\BoostCore\Enums\Agent;
use SplFileInfo;

/**
 * The stale-file cleanup machinery, extracted from {@see SyncEngine}: the
 * retired-paths registry sweep, the clean-slate post-sync prune of managed files
 * no longer emitted, the managed-file enumerator they share, and the recursive
 * delete. Stateless — every input is a parameter; behavior is identical to the
 * engine's prior inline methods.
 */
final readonly class StaleFileCleaner
{
    /**
     * Remove paths boost-core retired entirely (the retired-paths registry),
     * gated on Copilot being active (the registry is Copilot-scoped). Reports
     * drift via `WrittenFile` DELETED / WOULD_DELETE entries so `boost sync
     * --check` and CI surface the upcoming cleanup. A delete that leaves residual
     * paths is NOT marked DELETED (would poison drift/summary) — only an
     * actionable warning fires.
     *
     * @param  list<string>  $retiredPaths  the retired-paths registry (SyncEngine::RETIRED_COPILOT_PATHS)
     * @return array{writes: list<WrittenFile>, diagnostics: list<Diagnostic>}
     */
    public function cleanupStalePaths(string $projectRoot, BoostConfig $config, bool $checkOnly, array $retiredPaths): array
    {
        if (! $config->hasAgent(Agent::COPILOT)) {
            return ['writes' => [], 'diagnostics' => []];
        }

        $writes = [];
        $diagnostics = [];

        foreach ($retiredPaths as $relativePath) {
            $absolute = $projectRoot . '/' . $relativePath;
            if (! file_exists($absolute) && ! is_link($absolute)) {
                continue;
            }

            $failures = [];
            $write = $this->cleanupPath($absolute, $relativePath, $checkOnly, $failures);

            // When @-suppressed fs operations leave residual paths (permission
            // denied, open file descriptor, race with re-emission), surface it.
            //
            // On failure, do NOT mark this path as DELETED in $writes (would
            // poison SyncResult::hasDrift() + the "deleted=" summary count
            // for wrapper-side consumers reading the write log — both would
            // report cleanup success while the path persists on disk). Do
            // NOT emit the "removed" INFO either (contradicts the warning we
            // surface next). Only the actionable warning fires.
            if ($failures === []) {
                $writes[] = $write;
                $diagnostics[] = Diagnostic::info(null, $this->cleanupMessage($relativePath, $checkOnly));

                continue;
            }

            $diagnostics[] = Diagnostic::warning(
                null,
                sprintf(
                    'Cleanup of `%s` left %d residual path(s) on disk — drift will persist until removed manually. Likely cause: permission denied, open file descriptor, or concurrent re-emission. Residual: %s',
                    $relativePath,
                    count($failures),
                    implode(', ', array_slice($failures, 0, 5)) . (count($failures) > 5 ? sprintf(' (+%d more)', count($failures) - 5) : ''),
                ),
            );
        }

        return ['writes' => $writes, 'diagnostics' => $diagnostics];
    }

    /**
     * Delete files that were inside boost-managed patterns BEFORE this sync
     * but weren't rewritten this run. The clean-slate model: anything
     * boost-core no longer publishes is stale. Removes per-file then walks
     * up to clean empty parent directories so a directory that lost every
     * file disappears entirely.
     *
     * Skips files already deleted by other prune passes (FilteredSkillPruner,
     * RemoteOrphanPruner) — `file_exists()` returns false on already-gone
     * files, no double DELETED records.
     *
     * @param  list<string>  $priorManagedFiles
     * @param  list<WrittenFile>  $writes
     * @param  array<string, string>  $wrapperExcludedPaths  paths
     *   declared by `BoostWrapper` classes from installed wrapper packages —
     *   excluded from "stale-to-delete" classification so bare-CLI doesn't
     *   false-positive-flag wrapper-injected files for deletion.
     * @return list<WrittenFile>
     */
    public function cleanupStaleManagedFiles(string $projectRoot, array $priorManagedFiles, array $writes, bool $checkOnly, array $wrapperExcludedPaths = []): array
    {
        $writtenPaths = [];
        foreach ($writes as $w) {
            $writtenPaths[$w->relativePath] = true;
        }

        foreach ($priorManagedFiles as $relativePath) {
            if (isset($writtenPaths[$relativePath])) {
                continue;
            }

            // Wrapper-claimed paths get preserved. Wrapper-driven sync rewrites
            // them on next invocation; bare-CLI must NOT delete. Both exact-file
            // and directory-prefix match: a wrapper claim of `.agents/skills/foo`
            // (directory) should preserve every file under it. Without
            // prefix-match, wrapper dir claims would only preserve the dir entry
            // itself (which wouldn't be in priorManagedFiles anyway) while
            // children get false-positive-deleted.
            $canonicalRelative = ManagedFileOps::canonicalizeWrapperPath($relativePath);
            if (ManagedFileOps::isUnderWrapperClaim($canonicalRelative, $wrapperExcludedPaths)) {
                continue;
            }

            $absolute = $projectRoot . '/' . $relativePath;
            if (! file_exists($absolute) && ! is_link($absolute)) {
                continue;
            }

            if (! $checkOnly) {
                @unlink($absolute);
                ManagedFileOps::removeEmptyParentDirs($projectRoot, $absolute);
            }

            $writes[] = new WrittenFile(
                relativePath: $relativePath,
                absolutePath: $absolute,
                action: $checkOnly ? WriteAction::WOULD_DELETE : WriteAction::DELETED,
            );
        }

        return $writes;
    }

    /**
     * Enumerate every file currently on disk under any of the given
     * boost-managed gitignore patterns. Used by the clean-slate post-sync
     * pass: anything in this list NOT rewritten by the current sync is
     * stale and gets deleted.
     *
     * Patterns ending with `/` are treated as directories — recursed.
     * Other patterns are treated as single file paths. Wildcard / glob
     * patterns are skipped (boost-managed gitignore only uses directory +
     * file patterns).
     *
     * Symlinks at the pattern root are skipped — never followed, never
     * deleted. Matches `FileWriter::anySegmentIsSymlink()` safety contract.
     *
     * @param  list<string>  $patterns
     * @return list<string>  relative file paths inside any pattern
     */
    public function enumerateManagedFiles(string $projectRoot, array $patterns): array
    {
        $files = [];
        foreach ($patterns as $pattern) {
            if ($pattern === '') {
                continue;
            }

            // The sync manifest dir is engine-internal state owned by
            // SyncManifest itself — never a stale-cleanup target. Skip it so the
            // cleanup pass doesn't delete the manifest it's about to rely on.
            // Both the root `.boost` and the `.config/boost` (config-dir layout)
            // variants are skipped regardless of mode — neither is ever a real
            // managed output.
            if (rtrim($pattern, '/') === SyncManifest::DIR) {
                continue;
            }

            if (rtrim($pattern, '/') === SyncManifest::CONFIG_DIR) {
                continue;
            }

            if (str_contains($pattern, '*')) {
                continue;
            }

            if (str_contains($pattern, '?')) {
                continue;
            }

            $relative = rtrim($pattern, '/');
            $absolute = $projectRoot . '/' . $relative;

            if (is_link($absolute)) {
                continue;
            }

            if (is_file($absolute)) {
                $files[] = $relative;

                continue;
            }

            if (! is_dir($absolute)) {
                continue;
            }

            $iter = new RecursiveIteratorIterator(
                new RecursiveDirectoryIterator($absolute, FilesystemIterator::SKIP_DOTS),
                RecursiveIteratorIterator::LEAVES_ONLY,
            );
            /** @var SplFileInfo $file */
            foreach ($iter as $file) {
                if (! $file->isFile()) {
                    continue;
                }

                if ($file->isLink()) {
                    continue;
                }

                $files[] = $relative . '/' . str_replace('\\', '/', substr($file->getPathname(), strlen($absolute) + 1));
            }
        }

        return $files;
    }

    /**
     * Guideline files (CLAUDE.md / AGENTS.md / GEMINI.md / similar) use
     * ManagedRegion + are operator-tracked, never wholesale-replaced. Adding
     * them to the gitignore-managed manifest would route them through
     * cleanupStaleManagedFiles which would delete the WHOLE file when stale,
     * destroying operator content outside boost-core's markers. Filter at
     * the gitignore-pattern emit point so wrapper-returned guideline-file
     * paths are silently dropped from the managed manifest.
     */
    public function isGuidelineFilePath(string $relativePath): bool
    {
        return in_array($relativePath, ['CLAUDE.md', 'AGENTS.md', 'GEMINI.md'], true);
    }

    /**
     * @param  list<string>  $failures  Paths that could not be removed (by ref)
     */
    private function cleanupPath(string $absolute, string $relative, bool $checkOnly, array &$failures): WrittenFile
    {
        if (! $checkOnly) {
            if (is_link($absolute)) {
                if (! @unlink($absolute)) {
                    $failures[] = $absolute;
                }
            } elseif (is_dir($absolute)) {
                $this->deleteRecursive($absolute, $failures);
            } elseif (! @unlink($absolute)) {
                $failures[] = $absolute;
            }
        }

        return new WrittenFile(
            relativePath: $relative,
            absolutePath: $absolute,
            action: $checkOnly ? WriteAction::WOULD_DELETE : WriteAction::DELETED,
        );
    }

    private function cleanupMessage(string $relativePath, bool $checkOnly): string
    {
        $verb = $checkOnly ? 'would remove' : 'removed';

        return sprintf(
            'Cleanup: %s retired boost-core path `%s`. boost-core generates this file, so once no emitter still produces it, sync removes it. Do not edit these files by hand; boost rewrites them on every sync. To change what gets emitted, edit your `.ai/` sources, allowlisted vendors (`withAllowedVendors`), remote skills (`withRemoteSkills`), or `boost.php`. If you wrote content here yourself, recover it from git before the next sync.',
            $verb,
            $relativePath,
        );
    }

    /**
     * @param  list<string>  $failures  Paths that could not be removed (by ref)
     */
    private function deleteRecursive(string $path, array &$failures = []): void
    {
        // is_link() must be checked BEFORE is_dir() — PHP's is_dir() follows
        // symlinks and reports a symlink-to-directory as a directory, which
        // would route us into @rmdir($path). rmdir requires a real directory
        // and fails on a symlink, leaving residual drift. The engine can
        // encounter such symlinks (e.g. a skills dir symlinked into vendor
        // content) when cleaning a retired registry path.
        if (is_link($path)) {
            if (! @unlink($path)) {
                $failures[] = $path;
            }

            return;
        }

        if (! is_dir($path)) {
            if (! @unlink($path)) {
                $failures[] = $path;
            }

            return;
        }

        // RecursiveDirectoryIterator::hasChildren() defaults to
        // $allowLinks=false, so the iterator never descends INTO yielded
        // symlinks (which would otherwise walk vendor content through the
        // symlink target). Symlinks ARE yielded as top-level entries inside
        // their parent dir so the loop body can unlink them — see the
        // isLink() branch below.
        $iter = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($path, FilesystemIterator::SKIP_DOTS),
            RecursiveIteratorIterator::CHILD_FIRST,
        );
        /** @var SplFileInfo $file */
        foreach ($iter as $file) {
            $pathname = $file->getPathname();
            // Same is_link-before-is_dir ordering as the top of this method.
            // SplFileInfo::isDir() follows symlinks; a symlink-to-dir yielded
            // by the iterator would route into @rmdir without this guard.
            if ($file->isLink()) {
                $ok = @unlink($pathname);
            } elseif ($file->isDir()) {
                $ok = @rmdir($pathname);
            } else {
                $ok = @unlink($pathname);
            }

            if (! $ok) {
                $failures[] = $pathname;
            }
        }

        if (! @rmdir($path)) {
            $failures[] = $path;
        }
    }
}
