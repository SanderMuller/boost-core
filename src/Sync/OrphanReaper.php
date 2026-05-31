<?php declare(strict_types=1);

namespace SanderMuller\BoostCore\Sync;

/**
 * 0.14.0 reconcile-on-sync orphan reap, extracted from SyncEngine (maintenance
 * cycle 2026-05). Deletes boost-owned files recorded in the PRIOR ownership
 * manifest that this sync no longer intends to emit — a dormant FileEmitter's
 * output, or a de-selected agent's guidance file — manifest-gated and never-lossy
 * (a hand-edited file whose sha diverged is preserved).
 *
 * Stateless: all inputs are passed in. Only called by SyncEngine on a successful,
 * real (non-check) sync, after all non-destructive writes + cleanup succeeded and
 * before the new manifest is written.
 */
final class OrphanReaper
{
    /**
     * Partition this sync's emitter results into the sets the reap predicate
     * needs: `intended` (paths emitted/kept this sync — never reaped),
     * `preserved` (FQCNs DISABLED/errored this sync — their files preserved), and
     * `hasLiveOutput` (any emitter actually wrote/kept output).
     *
     * @param  list<EmitterResult>  $emitterResults
     * @return array{intended: array<string, true>, preserved: array<string, true>, hasLiveOutput: bool}
     */
    public static function emitterReapSets(array $emitterResults): array
    {
        $intended = [];
        $preserved = [];
        $hasLiveOutput = false;

        foreach ($emitterResults as $emitterResult) {
            if (
                $emitterResult->relativePath !== null
                && in_array($emitterResult->action, [EmitterAction::WROTE, EmitterAction::UNCHANGED, EmitterAction::WOULD_WRITE], true)
            ) {
                $intended[$emitterResult->relativePath] = true;
            }

            if (
                $emitterResult->relativePath !== null
                && in_array($emitterResult->action, [EmitterAction::WROTE, EmitterAction::UNCHANGED], true)
            ) {
                $hasLiveOutput = true;
            }

            if (in_array($emitterResult->action, [EmitterAction::DISABLED, EmitterAction::ERRORED], true)) {
                $preserved[$emitterResult->fqcn] = true;
            }
        }

        return ['intended' => $intended, 'preserved' => $preserved, 'hasLiveOutput' => $hasLiveOutput];
    }

    /**
     * Delete boost-owned prior-manifest entries this sync no longer emits. The
     * delete predicate ({@see isReapableOrphan}) consults the prior manifest's
     * ownership (not raw gitignore membership), and only a regular, non-aliased
     * file is unlinked; a delete failure RETAINS ownership so the next sync
     * retries.
     *
     * @param  array<string, true>  $intendedEmitterPaths  emitter paths emitted this sync (kept)
     * @param  array<string, true>  $preservedEmitterFqcns  FQCNs DISABLED/errored this sync (files preserved)
     * @param  list<string>  $ownedGuidancePaths  guidance files boost owns this sync (kept)
     * @param  array<string, string>  $wrapperPaths  wrapper-claimed paths (never reaped here)
     * @return array{writes: list<WrittenFile>, retained: list<string>}  `retained` = orphans whose delete FAILED — ownership kept so the next sync retries
     */
    public static function reapManifestOrphans(
        string $projectRoot,
        SyncManifest $priorManifest,
        array $intendedEmitterPaths,
        array $preservedEmitterFqcns,
        array $ownedGuidancePaths,
        array $wrapperPaths,
    ): array {
        $intendedGuidance = array_fill_keys($ownedGuidancePaths, true);

        /** @var list<string> $reaps */
        $reaps = [];
        foreach ($priorManifest->entries as $relativePath => $entry) {
            if (self::isReapableOrphan($projectRoot, $priorManifest, $relativePath, $entry, $intendedEmitterPaths, $preservedEmitterFqcns, $intendedGuidance, $wrapperPaths)) {
                $reaps[] = $relativePath;
            }
        }

        // 0.14.0 (codex high): identify files boost wrote/kept LIVE this sync by
        // INODE. On a case-insensitive filesystem an emitter that renames its
        // output by CASE only (`.Dummy/output.txt` → `.dummy/output.txt`) leaves
        // a prior manifest entry under the old spelling that string-matches as an
        // orphan — but it is the SAME on-disk file (inode) as the just-written
        // live output. Inode is the reliable alias test (macOS `realpath()`
        // preserves the queried casing, so it does NOT collapse case): same
        // dev:ino ⇒ same file ⇒ never unlink it; distinct inodes (case-sensitive
        // FS) ⇒ a genuine orphan ⇒ reap (no leak). Where PHP can't read inodes
        // (`ino === 0`, some Windows volumes) fall back to a case-folded path
        // match — preserve rather than risk deleting an aliased live file.
        $liveInodes = [];
        $liveLowerPaths = [];
        foreach ([...array_keys($intendedEmitterPaths), ...$ownedGuidancePaths] as $livePath) {
            $liveAbsolute = $projectRoot . '/' . $livePath;
            $stat = @stat($liveAbsolute);
            if ($stat !== false && $stat['ino'] > 0) {
                $liveInodes[$stat['dev'] . ':' . $stat['ino']] = true;
            } else {
                $liveLowerPaths[strtolower($liveAbsolute)] = true;
            }
        }

        $writes = [];
        /** @var list<string> $retained */
        $retained = [];
        foreach ($reaps as $relativePath) {
            $absolute = $projectRoot . '/' . $relativePath;
            // Reap ONLY a regular file (codex high): boost's guidance/emitter
            // outputs are always plain files. If the operator has since replaced
            // the path with a directory tree or a symlink, that is THEIR content —
            // never recurse into it or unlink it, and drop ownership (don't
            // retain). (is_link first — is_file() follows symlinks.)
            if (is_link($absolute)) {
                continue;
            }

            if (! is_file($absolute)) {
                continue;
            }

            // Never unlink a path that aliases a live output (codex high).
            $stat = @stat($absolute);
            if ($stat !== false && $stat['ino'] > 0) {
                if (isset($liveInodes[$stat['dev'] . ':' . $stat['ino']])) {
                    continue;
                }
            } elseif (isset($liveLowerPaths[strtolower($absolute)])) {
                continue;
            }

            if (@unlink($absolute)) {
                ManagedFileOps::removeEmptyParentDirs($projectRoot, $absolute);
                $writes[] = new WrittenFile($relativePath, $absolute, WriteAction::DELETED);

                continue;
            }

            // Delete FAILED (codex medium): a transient permission/filesystem
            // error must NOT silently drop the ownership record — that would leak
            // the stale file forever (the next sync wouldn't know to retry).
            // Retain it so writeSyncManifest carries the entry forward.
            $retained[] = $relativePath;
        }

        return ['writes' => $writes, 'retained' => $retained];
    }

    /**
     * Decide whether a single prior-manifest entry is a reapable orphan.
     *  - emitter `file`: orphan unless still emitted, wrapper-claimed, or its
     *    producing emitter was DISABLED/errored this sync (preserved); reaped only
     *    when the on-disk sha still matches the recorded sha (operator hand-edited
     *    → preserve, never-lossy);
     *  - engine `guidance`: orphan unless still scheduled; reap only when the
     *    on-disk sha still matches (operator-edited → preserve, never-lossy).
     *
     * @param  array{sha256: string, category: string, provenance: string, scope: string}  $entry
     * @param  array<string, true>  $intendedEmitterPaths
     * @param  array<string, true>  $preservedEmitterFqcns
     * @param  array<string, true>  $intendedGuidance
     * @param  array<string, string>  $wrapperPaths
     */
    private static function isReapableOrphan(
        string $projectRoot,
        SyncManifest $priorManifest,
        string $relativePath,
        array $entry,
        array $intendedEmitterPaths,
        array $preservedEmitterFqcns,
        array $intendedGuidance,
        array $wrapperPaths,
    ): bool {
        $category = $entry['category'];
        $provenance = $entry['provenance'];

        if ($category === SyncManifest::CATEGORY_FILE && str_starts_with($provenance, SyncManifest::PROVENANCE_EMITTER_PREFIX)) {
            // Wrapper preservation must be PREFIX-aware (codex high): a wrapper
            // DIRECTORY claim (`.agents/skills/foo`) owns its whole subtree, so a
            // path under it is never reaped here (exact-match would leak a delete
            // on descendants).
            if (isset($intendedEmitterPaths[$relativePath]) || ManagedFileOps::isUnderWrapperClaim(ManagedFileOps::canonicalizeWrapperPath($relativePath), $wrapperPaths)) {
                return false;
            }

            $fqcn = substr($provenance, strlen(SyncManifest::PROVENANCE_EMITTER_PREFIX));
            if (isset($preservedEmitterFqcns[$fqcn])) {
                return false;
            }

            // sha-revalidation (codex high — never-lossy): an emitter output the
            // operator hand-edited after boost wrote it (e.g. a tweaked
            // `.mcp.json`) must NOT be deleted on dormancy. Reap ONLY when the
            // on-disk content still matches what boost recorded — a divergence
            // means the operator took it over → preserve.
            $currentSha = ManagedFileOps::fileSha($projectRoot, $relativePath);

            return $currentSha !== null && $currentSha === $entry['sha256'];
        }

        if ($category === SyncManifest::CATEGORY_GUIDANCE && $provenance === SyncManifest::PROVENANCE_ENGINE) {
            if (isset($intendedGuidance[$relativePath])) {
                return false;
            }

            $currentSha = ManagedFileOps::fileSha($projectRoot, $relativePath);

            return $currentSha !== null && $priorManifest->ownsGuidance($relativePath, $currentSha);
        }

        return false;
    }
}
