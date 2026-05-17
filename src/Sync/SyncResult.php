<?php

declare(strict_types=1);

namespace SanderMuller\BoostCore\Sync;

final readonly class SyncResult
{
    /**
     * @param  list<WrittenFile>  $writes
     * @param  list<string>  $errors
     */
    public function __construct(
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
