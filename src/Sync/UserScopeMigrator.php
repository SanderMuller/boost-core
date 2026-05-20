<?php declare(strict_types=1);

namespace SanderMuller\BoostCore\Sync;

use FilesystemIterator;
use Generator;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use SanderMuller\BoostCore\Agents\AgentTarget;
use SanderMuller\BoostCore\Skills\Skill;
use SplFileInfo;

/**
 * One-time migration from pre-0.4 basename-only user-scope skill paths to
 * the 0.4+ vendor-namespaced slugs.
 *
 * Pre-0.4: `$home/.{agent}/skills/<basename>/...` (e.g.
 * `~/.claude/skills/repo-init/...`).
 * Post-0.4: `$home/.{agent}/skills/<vendor>__<basename>/...` (e.g.
 * `~/.claude/skills/sandermuller__repo-init/...`).
 *
 * For each enabled agent, if the old basename dir exists, the new slug
 * dir does NOT, and the legacy dir's content can be proven to originate
 * from this package, rename. Idempotent — subsequent runs find the old
 * dir gone and no-op.
 *
 * The ownership check defends against pre-0.2 basename-collision states
 * (where two packages with the same basename both wrote to
 * `~/.{agent}/skills/<basename>/` because the collision-detection guard
 * didn't yet exist): if the legacy dir holds files this package's source
 * tree cannot account for, the rename is skipped and the dir is left for
 * manual cleanup rather than mis-attributing it.
 */
final readonly class UserScopeMigrator
{
    /**
     * @param  list<AgentTarget>  $agentTargets
     * @param  list<Skill>  $skills
     */
    public function run(string $home, string $packageName, array $skills, array $agentTargets): void
    {
        $basename = SyncEngine::packageBasename($packageName);
        $slug = SyncEngine::packageSuffix($packageName);

        if ($basename === $slug) {
            return;
        }

        foreach ($agentTargets as $target) {
            $this->migrateForTarget($home, $target, $basename, $slug, $skills);
        }
    }

    /**
     * @param  list<Skill>  $skills
     */
    private function migrateForTarget(string $home, AgentTarget $target, string $basename, string $slug, array $skills): void
    {
        $skillsDir = $home . '/' . $target->skillsDirectoryRelative();
        $oldPath = $skillsDir . '/' . $basename;
        $newPath = $skillsDir . '/' . $slug;

        if (! is_dir($oldPath) || is_link($oldPath)) {
            return;
        }

        if (is_dir($newPath) || is_link($newPath)) {
            return;
        }

        if (! $this->legacyDirOwnedByPackage($oldPath, $skills, $target, $basename)) {
            return;
        }

        @rename($oldPath, $newPath);
    }

    /**
     * Returns true only when every file under `$legacyDir` corresponds to
     * a write this package would have produced under that path pre-0.4.
     *
     * Pre-0.4 fan-out wrote each planned `<agent>/skills/<filename>` to
     * `<agent>/skills/<basename>/<filename>` (after dedupe collapsing a
     * leading `<basename>/` segment when the source skill dir was named
     * after the package). The relative-to-legacy-dir path for each
     * planned write is therefore the post-`/skills/` portion of `plan()`'s
     * relativePath, with any leading `<basename>/` stripped.
     *
     * @param  list<Skill>  $skills
     */
    private function legacyDirOwnedByPackage(string $legacyDir, array $skills, AgentTarget $target, string $basename): bool
    {
        $expected = $this->expectedLegacyFiles($skills, $target, $basename);
        if ($expected === []) {
            return false;
        }

        foreach ($this->walkFiles($legacyDir) as $relPath) {
            if (! isset($expected[$relPath])) {
                return false;
            }
        }

        return true;
    }

    /**
     * @param  list<Skill>  $skills
     * @return array<string, true>
     */
    private function expectedLegacyFiles(array $skills, AgentTarget $target, string $basename): array
    {
        $expected = [];
        foreach ($target->plan($skills, []) as $pending) {
            $pos = strpos($pending->relativePath, '/skills/');
            if ($pos === false) {
                continue;
            }

            $afterSkills = substr($pending->relativePath, $pos + strlen('/skills/'));
            if (str_starts_with($afterSkills, $basename . '/')) {
                $afterSkills = substr($afterSkills, strlen($basename) + 1);
            }

            $expected[$afterSkills] = true;
        }

        return $expected;
    }

    /**
     * Yields every regular-file path under `$dir`, relative to `$dir`.
     * Symlinks are treated as foreign content and counted as "other"
     * (yielded with `<link>` marker prefix so they won't match expected).
     *
     * @return Generator<int, string>
     */
    private function walkFiles(string $dir): Generator
    {
        $iterator = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($dir, FilesystemIterator::SKIP_DOTS),
        );

        foreach ($iterator as $info) {
            if (! $info instanceof SplFileInfo) {
                continue;
            }

            if ($info->isLink()) {
                yield '<link>:' . substr($info->getPathname(), strlen($dir) + 1);

                continue;
            }

            if (! $info->isFile()) {
                continue;
            }

            yield substr($info->getPathname(), strlen($dir) + 1);
        }
    }
}
