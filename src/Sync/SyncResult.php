<?php declare(strict_types=1);

namespace SanderMuller\BoostCore\Sync;

final readonly class SyncResult
{
    /**
     * @param  list<WrittenFile>  $writes  Files written by agent fan-out (skills + guidelines).
     * @param  list<EmitterResult>  $emitters  Per-emitter outcomes (FileEmitter plugin layer).
     * @param  list<string>  $errors  Top-level errors that aborted parts of the run.
     * @param  int  $tagFilteredSkillsCount  Count of vendor skills dropped by the tag filter
     *         WHEN the consumer's `withTags()` is empty. Zero when `withTags()` is declared
     *         (intentional filtering) or when no skills were dropped. Drives the post-sync
     *         "discover hidden skills" nudge in `SyncCommand::report()`.
     */
    public function __construct(
        public array $writes,
        public array $emitters,
        public array $errors,
        public bool $check,
        public int $tagFilteredSkillsCount = 0,
    ) {}

    public function hasDrift(): bool
    {
        foreach ($this->writes as $write) {
            if ($write->action === WriteAction::WOULD_WRITE || $write->action === WriteAction::WOULD_DELETE) {
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
                ++$count;
            }
        }

        return $count;
    }

    /**
     * Files a real (non-check) sync would change — both rewrites and the
     * deletions tag-filtering prunes. The number a `--check` run reports.
     */
    public function countWouldChange(): int
    {
        return $this->countByAction(WriteAction::WOULD_WRITE)
            + $this->countByAction(WriteAction::WOULD_DELETE);
    }

    public function countEmittersByAction(EmitterAction $action): int
    {
        $count = 0;
        foreach ($this->emitters as $emitter) {
            if ($emitter->action === $action) {
                ++$count;
            }
        }

        return $count;
    }
}
