<?php declare(strict_types=1);

namespace SanderMuller\BoostCore\Sync;

/**
 * Refuses a user-scope write onto a path boost does not own.
 *
 * Flat user-scope skill dirs (`~/.{agent}/skills/<name>-user/`) share the
 * agent's skills directory with the operator's own skills and with every
 * other package, so a planned path may already hold a file boost never wrote.
 * The check runs over the whole plan BEFORE the first write: a refusal makes
 * the run unclean, and an unclean run must not leave half its files written
 * but unrecorded in the manifest.
 *
 * A path another package's manifest records is always a collision, even when
 * the file is gone: two packages that both claim one path would overwrite each
 * other on every sync. Otherwise a planned path is safe when it does not exist,
 * when this package's prior manifest records it, or when its content already
 * equals the planned content (adopted — for example after a lost manifest).
 * Anything else, including a symlink, is a collision.
 *
 * @internal
 */
final readonly class UserScopeCollisionGuard
{
    /**
     * @param  list<PendingWrite>  $planned
     * @param  array<string, string>  $foreignPaths  path => the other package that records it
     * @param  array<string, true>  $absent  paths to treat as deleted (a `--check` run's would-be reaps)
     * @return list<string>  one error per colliding path
     */
    public function check(string $home, string $packageName, array $planned, UserScopeManifest $prior, array $foreignPaths, array $absent = []): array
    {
        $home = rtrim($home, '/');
        $errors = [];
        $seen = [];

        foreach ($planned as $pending) {
            $relative = $pending->relativePath;
            if (isset($seen[$relative])) {
                continue;
            }

            $seen[$relative] = true;

            if (isset($foreignPaths[$relative])) {
                $errors[] = sprintf(
                    '%s/%s is owned by %s, which publishes a skill of the same name. Run `boost sync --scope=user --all` to reap a removed package; if both are installed, drop the skill from one of them in ~/.boost/user-scope.php.',
                    $home,
                    $relative,
                    $foreignPaths[$relative],
                );

                continue;
            }

            if ($this->isSafe($home, $relative, $pending->content, $prior, isset($absent[$relative]))) {
                continue;
            }

            $errors[] = sprintf(
                '%s/%s exists and is not owned by %s — remove or rename it, then sync again.',
                $home,
                $relative,
                $packageName,
            );
        }

        return $errors;
    }

    private function isSafe(string $home, string $relative, string $content, UserScopeManifest $prior, bool $treatAsAbsent): bool
    {
        $absolute = $home . '/' . $relative;

        if ($treatAsAbsent || ! file_exists($absolute) && ! is_link($absolute)) {
            return ! $this->hasSymlinkedSkillDir($home, $relative);
        }

        if (is_link($absolute) || $this->hasSymlinkedSkillDir($home, $relative)) {
            return false;
        }

        if ($prior->recordedSha($relative) !== null) {
            return true;
        }

        return ManagedFileOps::fileSha($home, $relative) === hash('sha256', $content);
    }

    /**
     * An operator symlink at the skill dir itself (`<skillsDir>/<name>-user`
     * pointing elsewhere) would route the write into a tree boost does not own.
     */
    private function hasSymlinkedSkillDir(string $home, string $relative): bool
    {
        $dir = dirname($relative);
        while (! in_array($dir, ['.', '', '/'], true)) {
            if (str_ends_with($dir, UserScopeSkillRenamer::SUFFIX) && is_link($home . '/' . $dir)) {
                return true;
            }

            $dir = dirname($dir);
        }

        return false;
    }
}
