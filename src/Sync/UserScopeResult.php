<?php

declare(strict_types=1);

namespace SanderMuller\BoostCore\Sync;

/**
 * Outcome of a `boost:sync --scope=user` run.
 *
 * User-scope syncs install a single package's `resources/boost/skills/`
 * into `~/.{agent}/skills/<package-suffix>/` so the skills activate in
 * any AI session on the machine. Used primarily by globally-installed
 * Composer tools that ship their own skills (e.g. `sandermuller/repo-init`).
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
            if ($write->action === WriteAction::WOULD_WRITE) {
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
                $count++;
            }
        }

        return $count;
    }
}
