<?php declare(strict_types=1);

namespace SanderMuller\BoostCore\Conventions;

/**
 * Outcome of inlining convention tokens into one skill/guidance body.
 *
 *  - `$body` — the body with resolved tokens spliced in (errored/skipped tokens
 *    left as-is).
 *  - `$requiresRuntimeConventions` — true when the FINAL body still depends on
 *    the rendered `## Project Conventions` block: it carries a legacy runtime
 *    `$.<slot-root>` prose reference, or an unresolved/errored token. The drop
 *    gate (§2) keeps the block whenever ANY live artifact reports true.
 *  - `$errors` — render-class token failures (unknown path, type×mode mismatch,
 *    unset-no-default-no-fallback, multi-line-inline, schema-pin violation).
 *    Per D7/§2 these fail `boost sync --check` and suppress the block drop.
 *
 * @phpstan-type InlineErrorList list<string>
 */
final readonly class InlineResult
{
    /**
     * @param  list<string>  $errors
     * @param  bool  $inlinedAny  true when ≥1 token was successfully resolved + spliced — proof that migration to inlining actually happened (the drop gate only removes the block on positive proof, never merely because nothing referenced it).
     */
    public function __construct(
        public string $body,
        public bool $requiresRuntimeConventions,
        public array $errors,
        public bool $inlinedAny = false,
    ) {}
}
