<?php

declare(strict_types=1);

namespace SanderMuller\BoostCore\Sync;

/**
 * Single emitter's outcome from a sync run, suitable for both
 * human-readable reporting and JSON serialization.
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
