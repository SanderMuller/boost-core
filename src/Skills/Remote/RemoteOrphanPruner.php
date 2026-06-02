<?php declare(strict_types=1);

namespace SanderMuller\BoostCore\Skills\Remote;

use JsonException;
use SanderMuller\BoostCore\Agents\AgentTarget;
use SanderMuller\BoostCore\Sync\FilteredSkillPruner;
use SanderMuller\BoostCore\Sync\SyncManifest;
use SanderMuller\BoostCore\Sync\WriteAction;
use SanderMuller\BoostCore\Sync\WrittenFile;

/**
 * Removes the agent-directory output of a {@see RemoteSkillSource} skill that
 * the user dropped from `withRemoteSkills(...)`.
 *
 * Pruning needs a record of what was previously remote-managed — the on-disk
 * `.{agent}/skills/<name>/` layout alone cannot tell remote-sourced skills
 * apart from host- or vendor-sourced ones. The ledger
 * `<.boost|.config/boost>/remote-manifest.json` is that record: each successful
 * sync writes the currently-resolved remote skill names; the next sync diffs
 * prev-vs-current and prunes the gap. The ledger lives inside the active sync-
 * manifest dir (0.19.0+) so it follows the root↔`.config/` layout and stays out
 * of the repo root; a pre-0.19 root-level `.boost-remote-manifest.json` is
 * migrated into that dir on the next real sync.
 *
 * Delete-safety contract — provenance alone is not enough, so each delete
 * holds only when ALL of:
 *  - the skill name is a single safe path segment (no separators, not `.`/`..`);
 *  - the target is a real directory, not a symlink;
 *  - it holds a `SKILL.md`;
 *  - it resolves strictly inside the managed `.{agent}/skills/` directory;
 *  - the prior manifest claimed it (so boost-core wrote it);
 *  - no other (host / Composer-vendor / still-declared remote) skill of the
 *    same name took its place in this sync.
 *
 * Unlike {@see FilteredSkillPruner}, the
 * directory can contain bundle siblings (`references/`, `scripts/`, …) —
 * remote skills legitimately ship them, and the manifest is the ownership
 * signal that lets us remove a non-empty dir safely.
 *
 * In check mode nothing is deleted — a `WOULD_DELETE` result is returned.
 */
final class RemoteOrphanPruner
{
    /**
     * Pre-0.19 root-level ledger location. Retained only as the migration source
     * (read as a fallback, then moved into the manifest dir on the next real
     * sync) — never written to any more.
     */
    public const MANIFEST_FILE = '.boost-remote-manifest.json';

    /**
     * The ledger basename inside the active sync-manifest dir
     * (`.boost/` or `.config/boost/`).
     */
    public const MANIFEST_BASENAME = 'remote-manifest.json';

    /**
     * Absolute path of the orphan ledger for the active layout — inside the
     * sync-manifest dir so it follows root↔`.config/` and never litters the repo
     * root.
     */
    public static function manifestPathFor(string $projectRoot, bool $inConfigDir): string
    {
        return rtrim($projectRoot, '/') . '/' . SyncManifest::dirFor($inConfigDir) . '/' . self::MANIFEST_BASENAME;
    }

    /**
     * Diff prev manifest against the names that should be protected this
     * sync (any resolved skill of any source, OR any name still declared in
     * `withRemoteSkills`); prune what remains.
     *
     * Including still-declared-but-failed-to-fetch names preserves a
     * previously cached agent-dir copy across a transient network outage —
     * the user's declared intent stands, so the stale copy survives until
     * the user explicitly removes the declaration.
     *
     * @param  list<AgentTarget>  $agentTargets
     * @param  array<string, true>  $protectedNames  union of (resolved skill names) + (declared remote skill names), as a lookup set
     * @return list<WrittenFile>
     */
    public function pruneOrphans(
        string $projectRoot,
        array $agentTargets,
        array $protectedNames,
        bool $checkOnly,
        bool $inConfigDir = false,
    ): array {
        $prevNames = $this->readManifest($projectRoot, $inConfigDir);
        if ($prevNames === []) {
            return [];
        }

        $writes = [];
        foreach ($prevNames as $name) {
            if (isset($protectedNames[$name])) {
                continue;
            }

            foreach ($agentTargets as $target) {
                $written = $this->pruneOne($projectRoot, $target, $name, $checkOnly);
                if ($written instanceof WrittenFile) {
                    $writes[] = $written;
                }
            }
        }

        return $writes;
    }

    /**
     * Persist the names this sync wanted under `withRemoteSkills(...)` so
     * the next sync can diff for orphans. Use the DECLARED set, not just the
     * resolved set — a fetch failure must not silently drop a skill from the
     * orphan-detection map, or its previously-cached agent dir would persist
     * indefinitely once the user removes it.
     *
     * Removes the manifest file entirely when nothing's declared — a stale
     * manifest pointing at vanished names would otherwise re-trigger pruning
     * forever.
     *
     * @param  list<string>  $remoteSkillNames  every name declared in `withRemoteSkills(...)` this sync
     */
    public function writeManifest(string $projectRoot, array $remoteSkillNames, bool $inConfigDir = false): void
    {
        $path = self::manifestPathFor($projectRoot, $inConfigDir);

        if ($remoteSkillNames === []) {
            if (is_file($path)) {
                @unlink($path);
            }

            // Nothing declared → no ledger state to preserve, so the stale copies
            // (pre-0.19 root + the other 0.19 layout) are safe to drop alongside
            // the cleared active location.
            $this->removeStaleLedgers($projectRoot, $inConfigDir);

            return;
        }

        $unique = array_values(array_unique($remoteSkillNames));
        sort($unique);

        try {
            $json = json_encode(
                ['skills' => $unique],
                JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR,
            );
        } catch (JsonException) {
            return;   // legacy ledger preserved — nothing was migrated
        }

        $dir = dirname($path);
        if (! is_dir($dir) && ! @mkdir($dir, 0o755, true) && ! is_dir($dir)) {
            return;   // can't create the dir → keep the legacy ledger for retry
        }

        if (@file_put_contents($path, $json . "\n") === false) {
            return;   // write failed → keep the legacy ledger for retry
        }

        // Only now that the new ledger is persisted is it safe to drop the
        // migrated stale copies — never delete them before the replacement exists,
        // or a read-only/full manifest dir would leave NO ledger and silently
        // disable orphan pruning (codex 0.19.0 P1).
        $this->removeStaleLedgers($projectRoot, $inConfigDir);
    }

    /**
     * Drop ledgers left at a location NOT active this sync — the pre-0.19 root
     * file and the OTHER 0.19 layout (root `.boost/` when active is `.config/`,
     * or vice versa) — so a layout move never leaves a stale ledger behind. Only
     * the file is removed; the other-layout dir is owned by the sync manifest,
     * which prunes the dir itself.
     */
    private function removeStaleLedgers(string $projectRoot, bool $inConfigDir): void
    {
        foreach ([
            rtrim($projectRoot, '/') . '/' . self::MANIFEST_FILE,
            self::manifestPathFor($projectRoot, ! $inConfigDir),
        ] as $stale) {
            if (is_file($stale)) {
                @unlink($stale);
            }
        }
    }

    private function pruneOne(
        string $projectRoot,
        AgentTarget $target,
        string $skillName,
        bool $checkOnly,
    ): ?WrittenFile {
        if (! $this->isSafeSegment($skillName)) {
            return null;
        }

        $skillsDirRelative = $target->skillsDirectoryRelative();
        $relativePath = $skillsDirRelative . '/' . $skillName;
        $absolute = $projectRoot . '/' . $relativePath;

        if (is_link($absolute) || ! is_dir($absolute)) {
            return null;
        }

        if (! is_file($absolute . '/' . AgentTarget::SKILL_FILE)) {
            return null;
        }

        // Containment — target must sit inside the project root AND inside
        // the managed skills dir, so a symlinked ancestor cannot route a
        // recursive delete outside the repo.
        $projectRootReal = realpath($projectRoot);
        $skillsDirReal = realpath($projectRoot . '/' . $skillsDirRelative);
        $targetReal = realpath($absolute);
        if ($projectRootReal === false || $skillsDirReal === false || $targetReal === false) {
            return null;
        }

        if (! str_starts_with($targetReal, $projectRootReal . DIRECTORY_SEPARATOR)) {
            return null;
        }

        if (! str_starts_with($targetReal, $skillsDirReal . DIRECTORY_SEPARATOR)) {
            return null;
        }

        if ($checkOnly) {
            return new WrittenFile($relativePath, $absolute, WriteAction::WOULD_DELETE);
        }

        return $this->deleteDirectory($targetReal)
            ? new WrittenFile($relativePath, $absolute, WriteAction::DELETED)
            : null;
    }

    /**
     * Recursively delete a directory. Symlinked entries are unlinked, never
     * followed into.
     */
    private function deleteDirectory(string $dir): bool
    {
        $entries = @scandir($dir);
        if ($entries === false) {
            return false;
        }

        foreach ($entries as $entry) {
            if ($entry === '.') {
                continue;
            }

            if ($entry === '..') {
                continue;
            }

            $path = $dir . '/' . $entry;
            if (is_dir($path) && ! is_link($path)) {
                $this->deleteDirectory($path);
            } else {
                @unlink($path);
            }
        }

        return @rmdir($dir);
    }

    private function isSafeSegment(string $name): bool
    {
        if (in_array($name, ['', '.', '..'], true)) {
            return false;
        }

        return ! str_contains($name, '/') && ! str_contains($name, '\\');
    }

    /**
     * @return list<string>
     */
    private function readManifest(string $projectRoot, bool $inConfigDir): array
    {
        $path = self::manifestPathFor($projectRoot, $inConfigDir);
        if (! is_file($path)) {
            // Fallback chain for a not-yet-migrated ledger, so orphan pruning keeps
            // working on the first sync after a layout move (the same sync then
            // migrates it into the active dir):
            //   1. the OTHER 0.19 layout (root `.boost/` ↔ `.config/boost/`);
            //   2. the pre-0.19 root-level `.boost-remote-manifest.json`.
            $otherLayout = self::manifestPathFor($projectRoot, ! $inConfigDir);
            $legacy = rtrim($projectRoot, '/') . '/' . self::MANIFEST_FILE;

            $path = match (true) {
                is_file($otherLayout) => $otherLayout,
                is_file($legacy) => $legacy,
                default => null,
            };

            if ($path === null) {
                return [];
            }
        }

        $raw = @file_get_contents($path);
        if ($raw === false) {
            return [];
        }

        try {
            $decoded = json_decode($raw, true, flags: JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            return [];
        }

        if (! is_array($decoded) || ! isset($decoded['skills']) || ! is_array($decoded['skills'])) {
            return [];
        }

        $names = [];
        foreach ($decoded['skills'] as $entry) {
            if (is_string($entry) && $entry !== '') {
                $names[] = $entry;
            }
        }

        return $names;
    }
}
