<?php declare(strict_types=1);

namespace SanderMuller\BoostCore\Contracts;

use SanderMuller\BoostCore\Sync\EmittedFile;
use SanderMuller\BoostCore\Sync\SyncContext;

/**
 * Plugin seam for vendor packages to emit custom files during sync.
 *
 * @api Stable plugin seam (locked at 1.0). Parameterless constructors only;
 * changing `emit()`'s signature, or requiring a constructor argument, is a
 * breaking change that needs a major bump.
 *
 * Reference consumer: `sandermuller/package-boost-laravel` emits `.mcp.json`
 * via this seam when Laravel Boost is detected.
 *
 * Contract:
 * - Called exactly once per sync, after vendor allowlist filtering.
 * - Returns the files to emit — zero (an empty iterable to skip), one, or many.
 *   No separate guard method (eliminates TOCTOU). Each {@see EmittedFile} from a
 *   SUCCESSFUL emit is validated + written independently (one outcome per file).
 * - Throwing is recorded as `errored` and sync continues with the remaining
 *   emitters. Emit is ALL-OR-NOTHING on failure: if a generator `emit()` throws
 *   after yielding some files, NONE of its files are written — a crashed emitter
 *   never half-applies a partial set. Return an array (not a throwing generator)
 *   if you want already-computed files to land regardless of a later problem.
 * - Parameterless constructors only. Anything more is deferred to a
 *   factory pattern when emitter #2 demands it.
 * - Identity is the fully-qualified class name (FQCN). Used in JSON
 *   output, `withDisabledEmitters`, and conflict detection.
 */
interface FileEmitter
{
    /**
     * @return iterable<EmittedFile>  zero files (skip), one, or many
     */
    public function emit(SyncContext $ctx): iterable;
}
