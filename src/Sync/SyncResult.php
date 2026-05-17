<?php

declare(strict_types=1);

namespace SanderMuller\BoostCore\Sync;

final readonly class SyncResult
{
    /**
     * @param  list<WrittenFile>  $writes  Files written by agent fan-out (skills + guidelines).
     * @param  list<EmitterResult>  $emitters  Per-emitter outcomes (FileEmitter plugin layer).
     * @param  list<string>  $errors  Top-level errors that aborted parts of the run.
     */
    public function __construct(
        public array $writes,
        public array $emitters,
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

        foreach ($this->emitters as $emitter) {
            if ($emitter->action === EmitterAction::WOULD_WRITE) {
                return true;
            }
        }

        return false;
    }

    public function hasErrors(): bool
    {
        if ($this->errors !== []) {
            return true;
        }

        foreach ($this->emitters as $emitter) {
            if ($emitter->action === EmitterAction::ERRORED) {
                return true;
            }
        }

        return false;
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

    public function countEmittersByAction(EmitterAction $action): int
    {
        $count = 0;
        foreach ($this->emitters as $emitter) {
            if ($emitter->action === $action) {
                $count++;
            }
        }

        return $count;
    }
}
