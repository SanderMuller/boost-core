<?php declare(strict_types=1);

namespace SanderMuller\BoostCore\Sync;

/**
 * Single emitter's outcome from a sync run, suitable for both
 * human-readable reporting and JSON serialization.
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
