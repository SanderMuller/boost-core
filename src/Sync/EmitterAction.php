<?php

declare(strict_types=1);

namespace SanderMuller\BoostCore\Sync;

/**
 * Outcome of running a single FileEmitter during sync.
 *
 * Five values per the FileEmitter contract — distinct from WriteAction
 * (which is for the standard skill/guideline fan-out) because emitters
 * have additional outcomes (`skipped` when emit() returns null,
 * `disabled` when in `withDisabledEmitters`, `errored` when emit() throws).
 */
enum EmitterAction: string
{
    case WROTE = 'wrote';
    case UNCHANGED = 'unchanged';
    case WOULD_WRITE = 'would-write';
    case SKIPPED = 'skipped';
    case DISABLED = 'disabled';
    case ERRORED = 'errored';
}
