<?php

declare(strict_types=1);

namespace SanderMuller\BoostCore\Contracts;

use SanderMuller\BoostCore\Sync\EmittedFile;
use SanderMuller\BoostCore\Sync\SyncContext;

/**
 * Plugin seam for vendor packages to emit custom files during sync.
 *
 * @experimental Will change before v1.0 stable. Pin to exact boost-core
 * version if building against this. Lock-in happens after a second
 * non-trivial consumer from a different problem domain validates the shape.
 *
 * Reference consumer: `sandermuller/package-boost-laravel` v1.0 emits
 * `.mcp.json` via this seam when Laravel Boost is detected.
 *
 * Contract:
 * - Called exactly once per sync, after vendor allowlist filtering.
 * - Return null to skip — no separate guard method (eliminates TOCTOU).
 * - Throwing is recorded as `errored` in the SyncResult; sync continues
 *   with remaining emitters and standard fan-out.
 * - Parameterless constructors only. Anything more is deferred to a
 *   factory pattern when emitter #2 demands it.
 * - Identity is the fully-qualified class name (FQCN). Used in JSON
 *   output, `withDisabledEmitters`, and conflict detection.
 *
 * @see /Users/sandermuller/Documents/GitHub/elements/internal/boost-file-emitter-contract.md
 */
interface FileEmitter
{
    public function emit(SyncContext $ctx): ?EmittedFile;
}
