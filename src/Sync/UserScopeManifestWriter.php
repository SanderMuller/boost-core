<?php declare(strict_types=1);

namespace SanderMuller\BoostCore\Sync;

/**
 * Persists the per-package user-scope ownership manifest
 * (`$home/.boost/manifests/<slug>.json`) — the user/global-scope counterpart of
 * {@see SyncManifestWriter}, extracted from {@see SyncEngine} so the engine stays
 * focused on resolution + fan-out. Stateless; behavior is identical to the
 * engine's prior inline `writeUserScopeManifest()`.
 */
final readonly class UserScopeManifestWriter
{
    /**
     * Build + persist the manifest for one package after a clean user-scope sync.
     * Records every path emitted this run (path → on-disk sha) plus the package
     * install path (the reconcile source-of-truth).
     *
     * Carries forward any prior-recorded path this run no longer emits but that
     * STILL exists on disk — a retain-on-fail leftover (the reaper's unlink
     * failed) must stay tracked so the next clean run retries the reap; dropping
     * it from the manifest would orphan the file permanently (codex 0.19.0 P1).
     * The carried entry keeps its PRIOR sha, so an operator-edited file stays
     * un-owned (never reaped); only a byte-identical boost file is re-claimed.
     *
     * Returns an error string when the manifest could not be persisted — the
     * cleanup feature depends on it, so a silent write failure would claim
     * support it did not record (codex 0.19.0 P2). Null on success.
     *
     * @param  array<string, true>  $emittedPaths
     */
    public function write(string $home, string $packageName, string $packageRoot, array $emittedPaths, UserScopeManifest $prior): ?string
    {
        $manifest = UserScopeManifest::empty()->withInstallPath($packageRoot);

        foreach ($prior->paths() as $relative) {
            if (isset($emittedPaths[$relative])) {
                continue;
            }

            // Never claim a symlink: FileWriter returns SKIPPED_SYMLINK precisely
            // so boost takes no ownership of an operator's own link, and reaping it
            // would unlink the operator's file (codex 0.19.0).
            if (is_link($home . '/' . $relative)) {
                continue;
            }

            // Gone on disk → successfully reaped (or never written): drop it.
            // Still present → retain-on-fail leftover: keep its prior sha.
            if (ManagedFileOps::fileSha($home, $relative) === null) {
                continue;
            }

            $priorSha = $prior->recordedSha($relative);
            if ($priorSha !== null) {
                $manifest = $manifest->withEntry($relative, $priorSha);
            }
        }

        foreach (array_keys($emittedPaths) as $relative) {
            // A planned path that is a live symlink was SKIPPED_SYMLINK by the
            // writer — boost does not own it, so it must never enter the manifest
            // (else a later reap would delete the operator's link). `fileSha`
            // follows the link, so an explicit is_link guard is required.
            if (is_link($home . '/' . $relative)) {
                continue;
            }

            $sha = ManagedFileOps::fileSha($home, $relative);
            if ($sha !== null) {
                $manifest = $manifest->withEntry($relative, $sha);
            }
        }

        $path = UserScopeManifest::pathFor($home, $packageName);
        $dir = dirname($path);
        if (! is_dir($dir) && ! @mkdir($dir, 0o755, true) && ! is_dir($dir)) {
            return sprintf('Could not create user-scope manifest directory %s — dropped-skill cleanup and reconcile-on-remove are disabled for %s until it is writable.', $dir, $packageName);
        }

        if (@file_put_contents($path, $manifest->toJson($packageName, 'boost-core')) === false) {
            return sprintf('Could not write user-scope manifest %s — dropped-skill cleanup and reconcile-on-remove are disabled for %s until it is writable.', $path, $packageName);
        }

        return null;
    }
}
