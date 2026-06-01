<?php declare(strict_types=1);

namespace SanderMuller\BoostCore\Conventions;

/**
 * A raw, unresolved `boost:conv` token detected in EMITTED output by
 * {@see ConventionsInliner::scanLeaks()}.
 *
 * Two kinds, both classified by the inliner's own line scanner so detection and
 * inlining can never drift:
 *
 *  - {@see KIND_PROSE_TOKEN} — a `<!--boost:conv …-->` token sitting in PROSE
 *    (outside any code fence / inline-code span). The inliner resolves prose, so a
 *    raw token here means it never ran or errored.
 *    `path` / `mode` carry the parsed token attributes (null if malformed).
 *  - {@see KIND_FENCE_OPENER} — a surviving ` ```boost:conv ` opt-in fence OPENER.
 *    The engine strips the `boost:conv` info-string when it processes the
 *    fence; a surviving one means the fence was never processed.
 *    `path` / `mode` are null — the signal is the unprocessed fence itself,
 *    not a single token.
 *
 * `line` is 1-based, for `<file>:<line>` reporting.
 */
final readonly class LeakHit
{
    public const string KIND_PROSE_TOKEN = 'prose_token';

    public const string KIND_FENCE_OPENER = 'fence_opener';

    public function __construct(
        public string $kind,
        public int $line,
        public string $raw,
        public ?string $path = null,
        public ?string $mode = null,
    ) {}

    public static function proseToken(int $line, string $raw, ?string $path, ?string $mode): self
    {
        return new self(self::KIND_PROSE_TOKEN, $line, $raw, $path, $mode);
    }

    public static function fenceOpener(int $line, string $raw): self
    {
        return new self(self::KIND_FENCE_OPENER, $line, $raw);
    }
}
