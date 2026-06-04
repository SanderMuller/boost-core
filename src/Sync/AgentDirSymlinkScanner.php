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
     * @param  list<string>  $dead
     * @param  list<string>  $live
     */
    private function scanDir(string $projectRoot, string $dir, array &$dead, array &$live): void
    {
        // NEVER descend through a symlinked directory — `is_dir()` follows links,
        // so without this an agent ROOT that is itself a symlink (e.g. `.claude` →
        // a shared location) would be traversed and its links pruned OUTSIDE the
        // project tree. The same guard inside the loop (is_link before recursion)
        // covers child dirs; this covers the top dir handed to scan().
        if (is_link($dir)) {
            return;
        }

        if (! is_dir($dir)) {
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
                $relative = ltrim(substr($path, strlen($projectRoot)), '/');
                if (file_exists($path)) {
                    $live[] = $relative;
                } else {
                    $dead[] = $relative;
                }

                // Never recurse THROUGH a symlinked dir — don't follow the link.
                continue;
            }

            if (is_dir($path)) {
                $this->scanDir($projectRoot, $path, $dead, $live);
            }
        }
    }
}
