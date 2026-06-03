<?php declare(strict_types=1);

namespace SanderMuller\BoostCore\Sync;

/**
 * Outcome of running a single FileEmitter during sync.
 *
 * Distinct from WriteAction (the standard skill/guideline fan-out) because
 * emitters have additional outcomes (`skipped` when emit() yields nothing,
 * `disabled` when in `withDisabledEmitters`, `errored` when emit() throws).
 *
 * @api Stable as of 1.0 — the per-emitter action on an {@see EmitterResult} in
 * an `@api` {@see SyncResult}. New cases may be added (additive); existing
 * backing values won't change.
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
