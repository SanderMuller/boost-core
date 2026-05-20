<?php declare(strict_types=1);

namespace SanderMuller\BoostCore\Sync;

use SanderMuller\BoostCore\Agents\AgentTarget;
use SanderMuller\BoostCore\Skills\Skill;

/**
 * Removes the agent-directory output of a skill that `SkillTagFilter` dropped.
 *
 * Tag filtering only reaches the agent's skill-selection index if a skill
 * that stopped shipping also has its previously-synced `.{agent}/skills/
 * <name>/` directory removed — otherwise its `description` lingers.
 *
 * Delete-safety contract — provenance alone does not justify a recursive
 * delete, so {@see prune()} acts only when ALL hold:
 *  - the skill name is a single safe path segment (no separators, not `.`/`..`);
 *  - the target is a real directory, not a symlink;
 *  - it holds the generated `<name>/SKILL.md` layout;
 *  - it resolves strictly inside the managed `.{agent}/skills/` directory.
 *
 * In check mode nothing is deleted — a `WOULD_DELETE` result is returned.
 */
final class FilteredSkillPruner
{
    /**
     * The subset of dropped skill names whose agent-dir output should be
     * pruned: those NOT re-occupied by a surviving (resolved) skill of the
     * same name. The fan-out writes by skill name, so a still-claimed name's
     * directory holds a shipped skill and must be left alone.
     *
     * @param  list<Skill>  $resolvedSkills
     * @param  list<string>  $droppedNames
     * @return list<string>
     */
    public function candidates(array $resolvedSkills, array $droppedNames): array
    {
        $resolvedNames = [];
        foreach ($resolvedSkills as $skill) {
            $resolvedNames[$skill->name] = true;
        }

        $candidates = [];
        foreach ($droppedNames as $name) {
            if (! isset($resolvedNames[$name])) {
                $candidates[] = $name;
            }
        }

        return $candidates;
    }

    /**
     * Prune (or, under `$checkOnly`, report) one dropped skill's agent-dir
     * directory. Returns the `WrittenFile` describing the deletion, or null
     * when there is nothing safe to prune at the path.
     */
    public function prune(
        string $projectRoot,
        AgentTarget $target,
        string $skillName,
        bool $checkOnly,
    ): ?WrittenFile {
        if (in_array($skillName, ['', '.', '..'], true)) {
            return null;
        }

        if (str_contains($skillName, '/') || str_contains($skillName, '\\')) {
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

        // Boost-core's fan-out emits exactly `<name>/SKILL.md` and nothing
        // else. A skill directory holding any other entry — sidecar notes,
        // a bundled `references/`, a hand-authored skill that merely shares
        // the name — has been touched by a human. Refuse: a recursive delete
        // must never destroy user content. (The fully precise ownership
        // signal would be a sync manifest — deferred; see the spec.)
        if (! $this->holdsOnlySkillFile($absolute)) {
            return null;
        }

        // Containment. Resolve the project root, the managed skills dir, and
        // the target. The target must sit inside BOTH — inside the project
        // root (so a symlinked `.{agent}/skills` or any symlinked ancestor
        // cannot route a recursive delete outside the repo) AND inside the
        // managed skills dir (so it is the right place semantically).
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

        // Only claim DELETED when the directory is actually gone — a failed
        // delete must not clear drift. A still-present dir resurfaces as a
        // WOULD_DELETE on the next `--check` run.
        return $this->deleteDirectory($targetReal)
            ? new WrittenFile($relativePath, $absolute, WriteAction::DELETED)
            : null;
    }

    /**
     * True when `$dir` contains nothing but the generated `SKILL.md` — i.e.
     * it matches exactly what boost-core's fan-out produces, with no
     * human-added entry.
     */
    private function holdsOnlySkillFile(string $dir): bool
    {
        $entries = @scandir($dir);
        if ($entries === false) {
            return false;
        }

        foreach ($entries as $entry) {
            if (! in_array($entry, ['.', '..', AgentTarget::SKILL_FILE], true)) {
                return false;
            }
        }

        return true;
    }

    /**
     * Recursively delete a directory. Symlinked entries are unlinked, never
     * followed into. Returns true only when the directory is gone afterward
     * — the final `rmdir` fails if anything underneath could not be removed.
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
}
