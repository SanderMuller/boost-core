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
 *  - the path lives under one of the package's `<agent skillsDir>/<slug>/` roots
 *    (validated here, NOT trusted from the manifest — a corrupt/hand-edited
 *    manifest can't authorize deleting another package's slug or an arbitrary
 *    home-dir file whose sha happens to match);
 *  - the on-disk sha matches the recorded sha (boost-owned + unchanged;
 *    operator-edited → preserved).
 *
 * Reaping itself is gated by the caller on a clean run (no write errors) — a
 * transient write failure must not make a still-needed path look reapable.
 */
final readonly class UserScopeReaper
{
    /**
     * @param  list<string>  $slugRoots  e.g. `['.claude/skills/acme__foo', '.agents/skills/acme__foo']`
     * @param  array<string, true>  $keep  relative paths to retain (emitted this sync)
     * @return array{writes: list<WrittenFile>, retained: bool}  `writes` = deletions
     *   (WOULD_DELETE under $checkOnly); `retained` = an owned file's real delete
     *   FAILED (still on disk), so the caller should keep the manifest for retry.
     */
    public function reap(string $home, array $slugRoots, UserScopeManifest $manifest, array $keep, bool $checkOnly): array
    {
        $home = rtrim($home, '/');
        $writes = [];
        $retained = false;

        foreach ($manifest->paths() as $relative) {
            if (isset($keep[$relative])) {
                continue;
            }

            // Slug-prefix is part of the predicate, not an assumption about how
            // the manifest was written — never delete outside this package's
            // own user-scope dirs even if the manifest names something else.
            if (! $this->underSlugRoot($relative, $slugRoots)) {
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
     * Build the clean-slate keep set, keyed by SKILL rather than exact path.
     *
     * Each active-target emission is reduced to its per-skill suffix (the part
     * after `<agent skillsDir>/<vendor__pkg>/`, e.g. `alpha/SKILL.md` — identical
     * across agents) using `$activeRoots`, then re-expanded across `$allRoots`
     * (the full agent catalog). A still-shipped skill is thus kept on EVERY agent
     * (a narrowed engine never over-deletes an inactive agent's live copy) while
     * a dropped skill is kept on NONE (its stale copies are reaped under every
     * agent) — resolving both codex 0.19.0 narrowed-target findings.
     *
     * @param  array<string, true>  $emittedPaths  active-target emissions this sync
     * @param  list<string>  $activeRoots  slug roots of the engine's ACTIVE targets
     * @param  list<string>  $allRoots  slug roots across the FULL agent catalog
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
