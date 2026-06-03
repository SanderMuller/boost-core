<?php declare(strict_types=1);

namespace SanderMuller\BoostCore\Sync;

/**
 * Outcome for ONE emitted file from the FileEmitter plugin layer, suitable for
 * both human-readable reporting and JSON serialization. Since 0.21.0 an emitter
 * returns `iterable<EmittedFile>`, so an emitter that emits N files contributes
 * N of these (keyed individually by `relativePath`); a skipped/disabled/errored
 * emitter contributes one with a null `relativePath`.
 *
 * @api Stable as of 1.0 — an item of {@see SyncResult::$emitters}. All five
 * properties (`fqcn`, `vendor`, `action`, `relativePath`, `reason`) are frozen.
 */
final readonly class EmitterResult
{
    public function __construct(
        public string $fqcn,
        public string $vendor,
        public EmitterAction $action,
        public ?string $relativePath,
        public ?string $reason,
    ) {}
}
