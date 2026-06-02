<?php declare(strict_types=1);

namespace SanderMuller\BoostCore\Sync;

use SanderMuller\BoostCore\Conventions\Diagnostic;

/**
 * Persists the post-sync ownership manifest and reconciles a stale manifest left
 * by a root↔`.config/` config-layout move — the manifest-writing half of the
 * sync engine, extracted from {@see SyncEngine} so the engine stays focused on
 * resolution + fan-out. Stateless; behavior is identical to the engine's prior
 * inline `writeSyncManifest()` / stale-layout helpers.
 *
 * The set of on-disk managed files is passed IN (the engine owns the gitignore /
 * cleanup machinery that enumerates them), so this collaborator never calls back
 * into the engine — it's a pure "given these inputs, build + persist" unit.
 *
 * @internal
 */
final readonly class SyncManifestWriter
{
    /**
     * Write the ownership manifest. Records every emission target boost owns after
     * this sync: GUIDANCE files written non-empty (sha-gated), the SKILL / COMMAND
     * managed files currently on disk (engine- or wrapper-provenance), and LIVE
     * FileEmitter outputs (so a later sync can reap a dormant emitter's file).
     * Carries forward the prior ownership of any orphan whose reap delete failed.
     * Skips materializing the manifest dir for a project boost emits nothing into,
     * and prunes a stale OTHER-layout manifest either way.
     *
     * @param  list<string>  $ownedGuidancePaths  relative paths from the guidance writer
     * @param  array<string, string>  $wrapperPaths  wrapper-claimed emit path => owning package
     * @param  list<EmitterResult>  $emitterResults  FileEmitter outcomes this sync
     * @param  list<string>  $retainedOrphans  reap targets whose delete FAILED — carry their PRIOR ownership forward so a later sync retries
     * @param  array<string, true>  $ownableEmitterPaths  emitter outputs boost may own
     * @param  list<string>  $managedFilesOnDisk  current on-disk managed files (skill/command emission targets), enumerated by the engine
     */
    public function write(string $projectRoot, array $ownedGuidancePaths, array $wrapperPaths, array $emitterResults, SyncManifest $priorManifest, array $retainedOrphans, array $ownableEmitterPaths, bool $inConfigDir, array $managedFilesOnDisk): void
    {
        $manifest = SyncManifest::empty();

        foreach ($ownedGuidancePaths as $relativePath) {
            $sha = ManagedFileOps::fileSha($projectRoot, $relativePath);
            if ($sha !== null) {
                $manifest = $manifest->withEntry($relativePath, $sha, SyncManifest::CATEGORY_GUIDANCE, SyncManifest::PROVENANCE_ENGINE);
            }
        }

        $manifest = $this->recordEmitterOutputs($manifest, $projectRoot, $emitterResults, $ownableEmitterPaths);

        // Skill / command emission targets currently on disk (enumerated by the
        // engine from the just-written managed gitignore block; `.boost/` skipped).
        foreach ($managedFilesOnDisk as $relativePath) {
            $category = match (true) {
                str_contains($relativePath, '/skills/') => 'skill',
                str_contains($relativePath, '/commands/') => 'command',
                default => null,
            };
            if ($category === null) {
                continue;   // not a skill/command emission target (e.g. a manifest file) — skip
            }

            $sha = ManagedFileOps::fileSha($projectRoot, $relativePath);
            if ($sha === null) {
                continue;
            }

            // Wrapper-claimed paths carry `wrapper:<vendor/package>` provenance
            // (the owning package from WrapperEmitDiscovery) so callers can tell
            // wrappers apart; engine-native paths are `engine`.
            $provenance = isset($wrapperPaths[$relativePath])
                ? 'wrapper:' . $wrapperPaths[$relativePath]
                : SyncManifest::PROVENANCE_ENGINE;
            $manifest = $manifest->withEntry($relativePath, $sha, $category, $provenance);
        }

        // Carry forward the PRIOR ownership of any orphan whose reap delete
        // failed this run. Without this the entry would simply
        // be absent from the new manifest, dropping ownership of a file that is
        // still on disk — the next sync wouldn't know to retry. Re-add verbatim
        // from the prior manifest so the retry path stays alive.
        foreach ($retainedOrphans as $relativePath) {
            $entry = $priorManifest->entries[$relativePath] ?? null;
            if ($entry === null) {
                continue;
            }

            $manifest = $manifest->withEntry($relativePath, $entry['sha256'], $entry['category'], $entry['provenance'], $entry['scope']);
        }

        // Don't materialize the manifest dir for a project boost emits nothing
        // into — but DO update an existing manifest down to empty if everything was
        // removed (keeps it honest rather than leaving stale ownership). In
        // `.config/` layout the manifest lives at `.config/boost/`.
        $manifestPath = $projectRoot . '/' . SyncManifest::relativePathFor($inConfigDir);
        if ($manifest->isEmpty() && ! is_file($manifestPath)) {
            // Nothing to write — but still shed a manifest left at the OTHER
            // layout's location (a prior root↔.config migration): it's now
            // unignored (the managed block lists the active dir) and would
            // otherwise be re-read as ownership on the next sync. Its entries
            // reflect a state boost no longer emits, so dropping them converges.
            $this->removeStaleLayoutManifest($projectRoot, $inConfigDir);

            return;
        }

        $dir = $projectRoot . '/' . SyncManifest::dirFor($inConfigDir);
        if (! is_dir($dir)) {
            @mkdir($dir, 0o755, true);
        }

        // The manifest dir is ignored via the managed .gitignore block (added in
        // updateGitignore when $willWriteManifest), so the regenerable manifest
        // never dirties the working tree. No self-contained .gitignore here —
        // that would itself be an untracked file.
        @file_put_contents($manifestPath, $manifest->toJson('boost-core'));

        // Migration cleanup: after a root↔.config migration the OTHER layout's
        // manifest is now unignored (the managed block lists the active dir only)
        // and would surface as untracked AND be re-read as ownership next sync. Its
        // ownership was already carried forward via fromProjectRoot's fallback, so
        // remove the stale boost-owned manifest (+ its dir if empty); never touch
        // operator content.
        $this->removeStaleLayoutManifest($projectRoot, $inConfigDir);
    }

    /**
     * Advisory for a stale OTHER-layout manifest a sync would prune after a
     * root↔.config migration — surfaced so `boost sync --check` reports the same
     * one-time cleanup a real sync performs (check==real). Empty when gitignore
     * isn't managed (the manifest machinery is off) or none is present. Warning-
     * level: regenerable engine state, never fails the build.
     *
     * @return list<Diagnostic>
     */
    public function staleManifestDiagnostics(string $projectRoot, bool $inConfigDir, bool $gitignoreManaged): array
    {
        if (! $gitignoreManaged) {
            return [];
        }

        $relative = $this->staleLayoutManifestPath($projectRoot, $inConfigDir);
        if ($relative === null) {
            return [];
        }

        return [
            Diagnostic::warning(null, sprintf(
                'Stale boost-owned sync manifest at `%s` from a previous config layout — boost-core prunes it on sync (the manifest now lives under `%s/`). Regenerable engine state; safe.',
                $relative,
                SyncManifest::dirFor($inConfigDir),
            )),
        ];
    }

    /**
     * @param  list<EmitterResult>  $emitterResults
     * @param  array<string, true>  $ownableEmitterPaths
     */
    private function recordEmitterOutputs(SyncManifest $manifest, string $projectRoot, array $emitterResults, array $ownableEmitterPaths): SyncManifest
    {
        foreach ($emitterResults as $emitterResult) {
            if ($emitterResult->relativePath === null) {
                continue;
            }

            if (! in_array($emitterResult->action, [EmitterAction::WROTE, EmitterAction::UNCHANGED], true)) {
                continue;
            }

            if (! isset($ownableEmitterPaths[$emitterResult->relativePath])) {
                continue;
            }

            $sha = ManagedFileOps::fileSha($projectRoot, $emitterResult->relativePath);
            if ($sha === null) {
                continue;
            }

            $manifest = $manifest->withEntry(
                $emitterResult->relativePath,
                $sha,
                SyncManifest::CATEGORY_FILE,
                SyncManifest::PROVENANCE_EMITTER_PREFIX . $emitterResult->fqcn,
            );
        }

        return $manifest;
    }

    /**
     * Delete a manifest left at the layout NOT in use this sync — root `.boost/`
     * when the active layout is `.config/`, or `.config/boost/` when the active
     * layout is root — handling both migration directions. Boost-owned regenerable
     * state only: removes the manifest file + the dir if it is now empty (an
     * operator's own files under `.config/boost/` keep the dir, untouched).
     */
    private function removeStaleLayoutManifest(string $projectRoot, bool $inConfigDir): void
    {
        $relative = $this->staleLayoutManifestPath($projectRoot, $inConfigDir);
        if ($relative === null) {
            return;
        }

        @unlink($projectRoot . '/' . $relative);
        $staleDirAbs = $projectRoot . '/' . SyncManifest::dirFor(! $inConfigDir);
        if (is_dir($staleDirAbs) && $this->isEmptyDir($staleDirAbs)) {
            @rmdir($staleDirAbs);
        }
    }

    /**
     * The project-relative path of a manifest left at the layout NOT in use this
     * sync (root `.boost/` when active is `.config/`, or vice versa), or null when
     * none exists. Read-only — the single detector shared by the prune
     * ({@see removeStaleLayoutManifest}) and the `--check` advisory.
     */
    private function staleLayoutManifestPath(string $projectRoot, bool $inConfigDir): ?string
    {
        $relative = SyncManifest::relativePathFor(! $inConfigDir);

        return is_file($projectRoot . '/' . $relative) ? $relative : null;
    }

    private function isEmptyDir(string $dir): bool
    {
        $entries = @scandir($dir);
        if ($entries === false) {
            return false;
        }

        return array_values(array_diff($entries, ['.', '..'])) === [];
    }
}
