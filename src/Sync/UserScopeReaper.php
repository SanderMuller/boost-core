<?php declare(strict_types=1);

namespace SanderMuller\BoostCore\Sync;

/**
 * Reaps stale user-scope files a {@see UserScopeManifest} records — the
 * user/global-scope counterpart of the project {@see OrphanReaper}. Used for
 * both the per-package clean-slate (a still-installed package that dropped a
 * skill) and the `--scope=user --all` reconcile-on-remove (a package
 * `composer global remove`d).
 *
 * The delete predicate is a hard conjunction, every clause required:
 *  - the path is NOT in the keep set (not re-emitted this sync);
 *  - the path is NOT recorded by another package's manifest (`$foreignPaths`);
 *  - the path has a user-scope shape, validated here and NOT trusted from the
 *    manifest: either under one of the package's legacy `<agent skillsDir>/<slug>/`
 *    roots (or an exact guidance-file root), or a flat skill path
 *    `<agent skillsDir>/<name>-user/<file>` — so a corrupt/hand-edited manifest
 *    can't authorize deleting an arbitrary home-dir file whose sha happens to match;
 *  - the on-disk sha matches the recorded sha (boost-owned + unchanged;
 *    operator-edited → preserved).
 *
 * Fence change (flat `-user` layout): before it, every skill path sat under the
 * package's own `<vendor>__<pkg>/` slug, and that slug alone fenced one
 * package's reap from another's files. Flat `<name>-user/` dirs share one
 * namespace, so the fence is now weaker — the `-user` shape, the sha gate, and
 * the foreign-path check together. The legacy slug roots stay so the first
 * flat sync can reap the old nested copies.
 *
 * Reaping itself is gated by the caller on a clean run (no write errors) — a
 * transient write failure must not make a still-needed path look reapable.
 *
 * @internal
 */
final readonly class UserScopeReaper
{
    /**
     * @param  list<string>  $slugRoots  e.g. `['.claude/skills/acme__foo', '.agents/skills/acme__foo']`
     * @param  array<string, true>  $keep  relative paths to retain (emitted this sync)
     * @param  array<string, string>  $foreignPaths  paths other packages' manifests record (=> owner) — never reaped
     * @return array{writes: list<WrittenFile>, retained: bool}  `writes` = deletions
     *   (WOULD_DELETE under $checkOnly); `retained` = an owned file's real delete
     *   FAILED (still on disk), so the caller should keep the manifest for retry.
     */
    public function reap(string $home, array $slugRoots, UserScopeManifest $manifest, array $keep, bool $checkOnly, array $foreignPaths = []): array
    {
        $home = rtrim($home, '/');
        $writes = [];
        $retained = false;

        foreach ($manifest->paths() as $relative) {
            if (isset($keep[$relative]) || isset($foreignPaths[$relative]) || ! $this->isNormalized($relative)) {
                continue;
            }

            // The path shape is part of the predicate, not an assumption about
            // how the manifest was written — never delete outside a user-scope
            // skill or guidance path even if the manifest names something else.
            if (! $this->underSlugRoot($relative, $slugRoots) && ! self::isFlatSkillPath($relative)) {
                continue;
            }

            // Never reap through a symlinked dir: an operator who swapped a
            // `<name>-user/` dir for a link points it at files boost never wrote.
            if ($this->hasSymlinkedParent($home, $relative)) {
                continue;
            }

            $absolute = $home . '/' . $relative;
            if (! is_file($absolute) && ! is_link($absolute)) {
                continue;   // already gone (another prune pass, manual removal)
            }

            $sha = ManagedFileOps::fileSha($home, $relative);
            if ($sha === null || ! $manifest->ownsPath($relative, $sha)) {
                continue;   // operator-edited (sha diverged) → preserve
            }

            if ($checkOnly) {
                $writes[] = new WrittenFile($relative, $absolute, WriteAction::WOULD_DELETE);

                continue;
            }

            @unlink($absolute);
            if (is_file($absolute) || is_link($absolute)) {
                // Delete failed (permission, lock) — do NOT record a DELETED that
                // didn't happen; signal retain so the manifest stays for retry.
                $retained = true;

                continue;
            }

            ManagedFileOps::removeEmptyParentDirs($home, $absolute);
            $writes[] = new WrittenFile($relative, $absolute, WriteAction::DELETED);
        }

        return ['writes' => $writes, 'retained' => $retained];
    }

    /**
     * Whether any dir on the path is a symlink — the same rule `FileWriter`
     * applies before a write, so boost never deletes where it would not write.
     */
    private function hasSymlinkedParent(string $home, string $relative): bool
    {
        for ($dir = dirname($relative); $dir !== '.' && $dir !== ''; $dir = dirname($dir)) {
            if (is_link($home . '/' . $dir)) {
                return true;
            }
        }

        return false;
    }

    /**
     * A manifest path is only trusted when it is relative and free of `.` / `..`
     * segments — otherwise a root-prefix check like `x-user/../../CLAUDE.md`
     * would pass while the path resolves outside the skill dir.
     */
    private function isNormalized(string $relative): bool
    {
        if ($relative === '' || str_starts_with($relative, '/')) {
            return false;
        }

        foreach (explode('/', $relative) as $segment) {
            if (in_array($segment, ['', '.', '..'], true)) {
                return false;
            }
        }

        return true;
    }

    /**
     * Whether `$relative` is a flat user-scope skill path:
     * `<agent skillsDir>/<name>-user/<file…>` for any agent in the catalog.
     */
    public static function isFlatSkillPath(string $relative): bool
    {
        foreach (SyncEngine::userScopeSkillsDirs() as $skillsDir) {
            if (! str_starts_with($relative, $skillsDir . '/')) {
                continue;
            }

            $rest = substr($relative, strlen($skillsDir) + 1);
            $slash = strpos($rest, '/');
            if ($slash === false || $slash === strlen($rest) - 1) {
                continue;
            }

            $dir = substr($rest, 0, $slash);
            if ($dir !== UserScopeSkillRenamer::SUFFIX && str_ends_with($dir, UserScopeSkillRenamer::SUFFIX)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Build the clean-slate keep set, keyed by SKILL rather than exact path.
     *
     * Each active-target emission is reduced to its per-skill suffix (the part
     * after `<agent skillsDir>/`, e.g. `alpha-user/SKILL.md` — identical across
     * agents) using `$activeRoots`, then re-expanded across `$allRoots`
     * (the full agent catalog). A still-shipped skill is thus kept on EVERY agent
     * (a narrowed engine never over-deletes an inactive agent's live copy) while
     * a dropped skill is kept on NONE (its stale copies are reaped under every
     * agent) — resolving both codex 0.19.0 narrowed-target findings.
     *
     * @param  array<string, true>  $emittedPaths  active-target emissions this sync
     * @param  list<string>  $activeRoots  skills dirs of the engine's ACTIVE targets
     * @param  list<string>  $allRoots  skills dirs across the FULL agent catalog
     * @return array<string, true>  full-path keep set spanning every agent root
     */
    public static function keepAcrossAgents(array $emittedPaths, array $activeRoots, array $allRoots): array
    {
        $suffixes = [];
        foreach (array_keys($emittedPaths) as $path) {
            foreach ($activeRoots as $root) {
                if (str_starts_with($path, $root . '/')) {
                    $suffixes[substr($path, strlen($root) + 1)] = true;

                    break;
                }
            }
        }

        $keep = [];
        foreach ($allRoots as $root) {
            foreach (array_keys($suffixes) as $suffix) {
                $keep[$root . '/' . $suffix] = true;
            }
        }

        return $keep;
    }

    /**
     * @param  list<string>  $slugRoots
     */
    private function underSlugRoot(string $relative, array $slugRoots): bool
    {
        foreach ($slugRoots as $root) {
            if ($root !== '' && ($relative === $root || str_starts_with($relative, $root . '/'))) {
                return true;
            }
        }

        return false;
    }
}
