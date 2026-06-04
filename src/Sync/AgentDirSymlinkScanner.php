<?php declare(strict_types=1);

namespace SanderMuller\BoostCore\Sync;

use SanderMuller\BoostCore\Agents\AgentTarget;

/**
 * Scans every agent's skill + command directory for symlinks and classifies them
 * DEAD (broken — target gone) vs LIVE (resolves). Shared by the sync prune and
 * `boost doctor` so both classify identically.
 *
 * Migration context: boost-core USED to symlink vendor skills into agent dirs and
 * now COPIES them, so a repo synced across that boundary carries leftover symlinks.
 *
 * Safety invariant — boost NEVER unlinks or follows a LIVE symlink. It cannot prove
 * a live symlink is its own legacy artifact vs one the operator placed intentionally
 * (the symlink era predates the ownership manifest), so live links are reported but
 * left untouched; removing them is the operator's call. Only DEAD links — which
 * point at nothing and serve no one — are pruned. Symlinked directories are never
 * recursed into (no link-loop, no following a link out of the managed tree).
 *
 * @internal
 */
final readonly class AgentDirSymlinkScanner
{
    /**
     * @param  list<AgentTarget>  $agentTargets
     * @return array{dead: list<string>, live: list<string>}  project-relative paths, sorted
     */
    public function scan(string $projectRoot, array $agentTargets): array
    {
        /** @var list<string> $dead */
        $dead = [];
        /** @var list<string> $live */
        $live = [];

        foreach ($this->agentDirs($agentTargets) as $relativeDir) {
            $this->scanDir($projectRoot, $projectRoot . '/' . $relativeDir, $dead, $live);
        }

        sort($dead);
        sort($live);

        return ['dead' => $dead, 'live' => $live];
    }

    /**
     * Prune DEAD symlinks across every agent dir (configured or not — a broken
     * link points nowhere, so removal is always safe). Live symlinks are left
     * untouched. Empty parent dirs left behind are cleaned up. Returns the
     * removed project-relative paths.
     *
     * @param  list<AgentTarget>  $agentTargets
     * @return list<string>
     */
    public function pruneDead(string $projectRoot, array $agentTargets): array
    {
        $dead = $this->scan($projectRoot, $agentTargets)['dead'];

        foreach ($dead as $relativePath) {
            $absolute = $projectRoot . '/' . $relativePath;
            @unlink($absolute);
            ManagedFileOps::removeEmptyParentDirs($projectRoot, $absolute);
        }

        return $dead;
    }

    /**
     * The distinct top-level dirs of the given agent-dir paths, space-joined for a
     * `find <roots> -type l -delete` cleanup hint. Derived from ACTUAL reported
     * paths, not a hardcoded `.claude .agents .cursor` — the scan covers every
     * agent, so a fixed triplet would miss links reported under other roots.
     * Shared by `boost sync` + `boost doctor` so both print the same shape.
     *
     * @param  list<string>  $paths
     */
    public static function cleanupRootsFor(array $paths): string
    {
        $roots = [];
        foreach ($paths as $path) {
            $roots[explode('/', $path)[0]] = true;
        }

        return implode(' ', array_keys($roots));
    }

    /**
     * The distinct skill + command directories across all agent targets.
     *
     * @param  list<AgentTarget>  $agentTargets
     * @return list<string>
     */
    private function agentDirs(array $agentTargets): array
    {
        $dirs = [];
        foreach ($agentTargets as $target) {
            $dirs[$target->skillsDirectoryRelative()] = true;

            $commands = $target->commandsDirectoryRelative();
            if ($commands !== null) {
                $dirs[$commands] = true;
            }
        }

        return array_keys($dirs);
    }

    /**
     * True when `$dir`'s canonical path resolves OUTSIDE the project root — the
     * tell that some ancestor segment is a symlink pointing elsewhere. A project
     * legitimately reached through a symlink is fine: both realpaths resolve under
     * the same canonical root, so containment still holds.
     */
    private function escapesProjectRoot(string $projectRoot, string $dir): bool
    {
        $realRoot = realpath($projectRoot);
        $realDir = realpath($dir);

        // Unresolvable paths: leave classification to the is_dir()/is_link() guards
        // above — a non-existent dir is never scanned regardless.
        if ($realRoot === false || $realDir === false) {
            return false;
        }

        return $realDir !== $realRoot
            && ! str_starts_with($realDir, $realRoot . DIRECTORY_SEPARATOR);
    }

    /**
     * Classify a symlink path as DEAD (target gone) or LIVE (resolves) by its
     * OWN project-relative path — never its target. Used for both child entries
     * and a managed dir that is itself a link.
     *
     * @param  list<string>  $dead
     * @param  list<string>  $live
     */
    private function classifyLink(string $projectRoot, string $path, array &$dead, array &$live): void
    {
        $relative = ltrim(substr($path, strlen($projectRoot)), '/');
        if (file_exists($path)) {
            $live[] = $relative;
        } else {
            $dead[] = $relative;
        }
    }

    /**
     * @param  list<string>  $dead
     * @param  list<string>  $live
     */
    private function scanDir(string $projectRoot, string $dir, array &$dead, array &$live): void
    {
        // The managed dir ITSELF is a symlink (e.g. `.cursor/skills` → elsewhere).
        // CLASSIFY it (a dead root link is prunable; a live one is reported) but
        // NEVER follow it — `is_dir()` follows links, so descending would traverse
        // OUTSIDE the project tree. Returning early WITHOUT classifying (the prior
        // behavior) left a broken managed-dir symlink unpruned AND invisible to
        // doctor; the same guard inside the loop covers child dirs.
        if (is_link($dir)) {
            $this->classifyLink($projectRoot, $dir, $dead, $live);

            return;
        }

        if (! is_dir($dir)) {
            return;
        }

        // `is_link($dir)` only catches the managed dir ITSELF being a link. When an
        // ANCESTOR is the link (e.g. `.claude` → /shared, with a REAL `skills` dir
        // inside the target), `.claude/skills` is not itself a link, yet `is_dir()`
        // still follows the parent link out of the tree. Resolve the canonical path
        // and refuse to scan anything that escapes the project root — so the prune
        // can never unlink broken links in an external shared location.
        if ($this->escapesProjectRoot($projectRoot, $dir)) {
            return;
        }

        $entries = @scandir($dir);
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

            $path = $dir . '/' . $entry;

            if (is_link($path)) {
                $this->classifyLink($projectRoot, $path, $dead, $live);

                // Never recurse THROUGH a symlinked dir — don't follow the link.
                continue;
            }

            if (is_dir($path)) {
                $this->scanDir($projectRoot, $path, $dead, $live);
            }
        }
    }
}
