<?php declare(strict_types=1);

namespace SanderMuller\BoostCore\Sync;

/**
 * One file written (or would-be-written, in check mode) during sync.
 *
 * @api Stable as of 1.0 — an item of {@see SyncResult::$writes}. `relativePath`
 * + `action` are the frozen surface; `absolutePath` is convenience.
 */
final readonly class WrittenFile
{
    public function __construct(
        public string $relativePath,
        public string $absolutePath,
        public WriteAction $action,
    ) {}
}
