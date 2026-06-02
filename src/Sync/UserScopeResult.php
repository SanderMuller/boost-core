<?php declare(strict_types=1);

namespace SanderMuller\BoostCore\Sync;

/**
 * Outcome of a `boost:sync --scope=user` run.
 *
 * User-scope syncs install a single package's `resources/boost/skills/`
 * into `~/.{agent}/skills/<package-suffix>/` so the skills activate in
 * any AI session on the machine. Used primarily by globally-installed
 * Composer tools that ship their own skills (e.g. `sandermuller/repo-init`).
 *
 * @internal
 */
final readonly class UserScopeResult
{
    /**
     * @param  list<WrittenFile>  $writes
     * @param  list<string>  $errors
     */
    public function __construct(
        public string $packageName,
        public string $homeRoot,
        public array $writes,
        public array $errors,
        public bool $check,
    ) {}

    public function hasDrift(): bool
    {
        foreach ($this->writes as $write) {
            // WOULD_DELETE counts too: a `--check` run whose only pending change
            // is a clean-slate / reconcile reap (a dropped or removed skill's
            // user-scope copy) is still drift — otherwise the CLI prints "No
            // drift" and CI/operators miss the pending cleanup (codex 0.19.0).
            if ($write->action === WriteAction::WOULD_WRITE || $write->action === WriteAction::WOULD_DELETE) {
                return true;
            }
        }

        return false;
    }

    public function hasErrors(): bool
    {
        return $this->errors !== [];
    }

    public function countByAction(WriteAction $action): int
    {
        $count = 0;
        foreach ($this->writes as $write) {
            if ($write->action === $action) {
                ++$count;
            }
        }

        return $count;
    }
}
