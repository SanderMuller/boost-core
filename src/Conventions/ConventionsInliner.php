<?php declare(strict_types=1);

namespace SanderMuller\BoostCore\Conventions;

/**
 * Resolves `<!--boost:conv …-->` tokens in a skill/guidance BODY into inlined
 * convention values at sync time.
 *
 * Token: `<!--boost:conv path="jira.project_key" mode="inline" fallback="ask the user"-->`
 *  - `path` (required) — dot-notation slot path;
 *  - `mode` (required) — inline | bullets | yaml | json;
 *  - `fallback` (optional) — prose emitted when the slot is unset AND has no
 *    schema default (required only for no-default slots — D9).
 *
 * Scope: tokens resolve in PROSE and in OPT-IN fenced code
 * blocks (info-string contains `boost:conv`). They are LEFT LITERAL
 * inside plain fenced code blocks and inline-code spans (so authoring docs can
 * show the token verbatim). An ESCAPE — `<!--\boost:conv …-->` — renders the
 * literal token text in prose. (Callers keep tokens out of FRONTMATTER; this
 * operates on the body only.)
 *
 * Errors are render-class (D7): an errored token is left in place + reported, so
 * `boost sync --check` fails and the conventions block is kept.
 *
 * The line scanner is shared between {@see inline()} (which resolves) and
 * {@see scanLeaks()} (which detects leaks in EMITTED output) via
 * {@see walkLines()}, so the two never drift in how they classify prose vs.
 * fence vs. inline-code.
 *
 * @internal
 */
final readonly class ConventionsInliner
{
    private const TOKEN = '/<!--(\\\\?)boost:conv\s+(.*?)-->/s';

    /**
     * Paired visible-default span: an open token, a VISIBLE DEFAULT, and an end
     * marker — `<!--boost:conv path="x" mode="inline"-->Pest<!--boost:conv:end-->`.
     * boost-core replaces the WHOLE span with the resolved value (the visible
     * default doubles as the inline fallback). An engine with no resolver (e.g.
     * `laravel/boost`, which preserves HTML comments verbatim) leaves both
     * comments inert, so the visible default reads as ordinary prose — no word
     * gap. Resolved BEFORE {@see TOKEN} so the span's own open comment is not
     * consumed as a bare unpaired token. `/s` lets the visible default span lines
     * (a `mode="yaml"` block inside an opt-in fence).
     */
    private const PAIRED_TOKEN = '/<!--boost:conv\s+(.*?)-->(.*?)<!--boost:conv:end-->/s';

    /** The closing marker of a paired span. */
    private const END_MARKER = '<!--boost:conv:end-->';

    /** A line that opens a fenced code block (` ``` ` / ` ~~~ `). */
    private const CTX_FENCE_OPEN = 'fence_open';

    /** A line that closes the active fence. */
    private const CTX_FENCE_CLOSE = 'fence_close';

    /**
     * A fence-marker-looking line INSIDE the active fence that does not close it
     * (a different/longer marker). Emitted verbatim — never resolved, even in an
     * opt-in fence — matching the original scanner exactly.
     */
    private const CTX_FENCE_MARKER = 'fence_marker';

    /** Ordinary content inside the active fence. */
    private const CTX_FENCE_BODY = 'fence_body';

    /** A line outside any fence. */
    private const CTX_PROSE = 'prose';

    /**
     * @param  list<string>  $slotRoots  top-level convention roots (for the legacy runtime-ref scan)
     */
    public function __construct(
        private SlotResolver $resolver,
        private array $slotRoots,
    ) {}

    public function inline(string $body): InlineResult
    {
        /** @var list<string> $errors */
        $errors = [];
        $inlinedCount = 0;
        $out = [];

        // An OPT-IN fence is BUFFERED: its opener is emitted only on close, once
        // we know whether the body resolved cleanly. On a clean fence the
        // `boost:conv` info-string is stripped (` ```yaml boost:conv ` → ` ```yaml `).
        // On a fence whose body ERRORED, the info-string is
        // KEPT, so the unresolved token stays detectable on disk by the same
        // surviving-info-string signal that catches an emit that never processed
        // the fence. Plain fences + prose stream
        // through unchanged.
        //
        // @var array{open: string, body: list<string>}|null $fence
        $fence = null;

        $this->walkLines($body, function (int $_lineNo, string $line, string $context, bool $optIn) use (&$out, &$errors, &$inlinedCount, &$fence): void {
            if ($fence !== null) {
                if ($context === self::CTX_FENCE_CLOSE) {
                    $this->flushOptInFence($out, $fence, $errors, $inlinedCount, $line);
                    $fence = null;

                    return;
                }

                // Body of the buffered opt-in fence: buffered RAW and resolved as
                // one block at flush, so a multi-line paired span (open token,
                // visible default lines, end marker) resolves across line breaks.
                // A fence-marker-looking line that doesn't close stays verbatim —
                // resolveTokens only rewrites token text, so passing it through is
                // harmless.
                $fence['body'][] = $line;

                return;
            }

            if ($context === self::CTX_FENCE_OPEN && $optIn) {
                $fence = ['open' => $line, 'body' => []];

                return;
            }

            $out[] = $context === self::CTX_PROSE
                ? $this->resolveProseLine($line, $errors, $inlinedCount)
                // CTX_FENCE_OPEN (plain) + CTX_FENCE_CLOSE + CTX_FENCE_MARKER + a
                // plain fence's body → verbatim.
                : $line;
        });

        // An unterminated opt-in fence (no closing marker before EOF) still flushes
        // — never drop buffered lines.
        if ($fence !== null) {
            $this->flushOptInFence($out, $fence, $errors, $inlinedCount, null);
        }

        $result = implode("\n", $out);

        return new InlineResult($result, $this->requiresRuntime($result, $errors), $errors, $inlinedCount > 0);
    }

    /**
     * Emit a buffered opt-in fence: resolve the whole body as one block (so a
     * multi-line paired span resolves across line breaks), then emit the opener
     * (info-string stripped only if the body resolved without error), the resolved
     * body, and the close line (null at EOF for an unterminated fence).
     *
     * @param  list<string>  $out
     * @param  array{open: string, body: list<string>}  $fence
     * @param  list<string>  $errors
     */
    private function flushOptInFence(array &$out, array $fence, array &$errors, int &$inlinedCount, ?string $closeLine): void
    {
        $errorsBefore = count($errors);
        $resolvedBody = $fence['body'] === []
            ? []
            : explode("\n", $this->resolveTokens(implode("\n", $fence['body']), $errors, $inlinedCount));
        $erroredInFence = count($errors) > $errorsBefore;

        $out[] = $erroredInFence ? $fence['open'] : rtrim(str_replace('boost:conv', '', $fence['open']));
        foreach ($resolvedBody as $bodyLine) {
            $out[] = $bodyLine;
        }

        if ($closeLine !== null) {
            $out[] = $closeLine;
        }
    }

    /**
     * Detect raw, unresolved `boost:conv` tokens in EMITTED output.
     * Read-only; never
     * resolves. Two leak signals, both decided by the SAME stateful walker
     * `inline()` uses, so classification can't drift:
     *
     *  - a `<!--boost:conv …-->` token in PROSE context (outside any fence /
     *    inline-code span) → {@see LeakHit::KIND_PROSE_TOKEN};
     *  - a surviving ` ```boost:conv ` opt-in fence OPENER → {@see
     *    LeakHit::KIND_FENCE_OPENER}. The engine strips the info-string when
     *    it processes the fence, so a surviving one is a definitive leak. Decided
     *    by the walker's active-fence state (NOT a flat grep): a `boost:conv` line
     *    nested inside ANOTHER fence is fence content, not an opener.
     *
     * Tokens inside plain fences / inline-code spans are intentional literals and
     * are NOT reported. A token left raw inside a PROCESSED opt-in fence (mode B,
     * info-string already stripped) is not re-caught here — that case is reported
     * at emit time by {@see inline()} — see the spec's coverage boundary.
     *
     * @return list<LeakHit>
     */
    public function scanLeaks(string $body): array
    {
        /** @var list<LeakHit> $hits */
        $hits = [];

        $this->walkLines($body, function (int $lineNo, string $line, string $context, bool $optIn) use (&$hits): void {
            if ($context === self::CTX_FENCE_OPEN && $optIn) {
                $hits[] = LeakHit::fenceOpener($lineNo, $line);

                return;
            }

            if ($context === self::CTX_PROSE) {
                foreach ($this->proseTokensIn($line) as $token) {
                    $hits[] = LeakHit::proseToken($lineNo, $token['raw'], $token['path'], $token['mode']);
                }
            }
        });

        return $hits;
    }

    /**
     * The single fence/prose state machine. Classifies each line and invokes
     * `$visit($lineNo, $line, $context, $fenceOptIn)` — `$lineNo` is 1-based,
     * `$context` is one of the CTX_* constants, `$fenceOptIn` is whether the
     * ACTIVE fence opted in (`boost:conv` in its info-string). Shared by
     * `inline()` and `scanLeaks()` so they never diverge.
     *
     * @param  callable(int, string, string, bool): void  $visit
     */
    private function walkLines(string $body, callable $visit): void
    {
        $inFence = false;
        $fenceMarker = '';
        $fenceOptIn = false;
        $lineNo = 0;

        foreach (explode("\n", $body) as $line) {
            ++$lineNo;

            if (preg_match('/^\s*(`{3,}|~{3,})(.*)$/', $line, $m) === 1) {
                if (! $inFence) {
                    $inFence = true;
                    $fenceMarker = $m[1];
                    $fenceOptIn = str_contains($m[2], 'boost:conv');
                    $visit($lineNo, $line, self::CTX_FENCE_OPEN, $fenceOptIn);
                } elseif (str_starts_with(ltrim($line), $fenceMarker)) {
                    $visit($lineNo, $line, self::CTX_FENCE_CLOSE, $fenceOptIn);
                    $inFence = false;
                    $fenceOptIn = false;
                } else {
                    $visit($lineNo, $line, self::CTX_FENCE_MARKER, $fenceOptIn);
                }

                continue;
            }

            if ($inFence) {
                $visit($lineNo, $line, self::CTX_FENCE_BODY, $fenceOptIn);

                continue;
            }

            $visit($lineNo, $line, self::CTX_PROSE, false);
        }
    }

    /**
     * Resolve tokens in a prose line, leaving inline-code spans (`` `…` ``)
     * untouched so a token shown as inline code stays literal.
     *
     * @param  list<string>  $errors
     */
    private function resolveProseLine(string $line, array &$errors, int &$inlinedCount): string
    {
        $spans = [];
        $masked = $this->maskInlineCode($line, $spans);

        $resolved = $this->resolveTokens($masked, $errors, $inlinedCount);

        return strtr($resolved, $spans);
    }

    /**
     * Mask inline-code spans (`` `…` ``) with placeholders so tokens inside
     * backticks are never seen as tokens. Matches a balanced run of N backticks:
     * `…`, ``…``, ```…``` all protect a literal token example. The
     * `$spans` map (placeholder → original) is filled for restoration / lookup.
     *
     * @param  array<string, string>  $spans
     */
    private function maskInlineCode(string $line, array &$spans): string
    {
        $masked = preg_replace_callback('/(`+)(.*?)\1/', function (array $m) use (&$spans): string {
            $key = "\x00CODE" . count($spans) . "\x00";
            $spans[$key] = $m[0];

            return $key;
        }, $line);

        return $masked ?? $line;
    }

    /**
     * Raw `boost:conv` tokens in a prose line, with inline-code spans masked out
     * (a token shown as inline code is an intentional literal, not a leak). Used
     * by {@see scanLeaks()}.
     *
     * @return list<array{raw: string, path: ?string, mode: ?string}>
     */
    private function proseTokensIn(string $line): array
    {
        $spans = [];
        $masked = $this->maskInlineCode($line, $spans);

        if (preg_match_all(self::TOKEN, $masked, $matches, PREG_SET_ORDER) === 0) {
            return [];
        }

        $tokens = [];
        foreach ($matches as $m) {
            $attrs = $this->parseAttributes($m[2]);
            $tokens[] = ['raw' => $m[0], 'path' => $attrs['path'], 'mode' => $attrs['mode']];
        }

        return $tokens;
    }

    /**
     * Resolve both token forms in one pass: paired visible-default spans FIRST
     * (so a span's open comment is not consumed as a bare unpaired token), then
     * legacy unpaired tokens.
     *
     * @param  list<string>  $errors
     */
    private function resolveTokens(string $text, array &$errors, int &$inlinedCount): string
    {
        $text = $this->resolvePairedTokens($text, $errors, $inlinedCount);

        return $this->resolveUnpairedTokens($text, $errors, $inlinedCount);
    }

    /**
     * Resolve paired visible-default spans. The visible default doubles as the
     * inline fallback; an explicit `fallback="…"` attribute, if present, still
     * wins (lets an author emit different prose to boost-core on an unset slot
     * than the visible default a no-resolver engine shows).
     *
     * @param  list<string>  $errors
     */
    private function resolvePairedTokens(string $text, array &$errors, int &$inlinedCount): string
    {
        $replaced = preg_replace_callback(self::PAIRED_TOKEN, function (array $m) use (&$errors, &$inlinedCount): string {
            $attrs = $this->parseAttributes($m[1]);
            $visibleDefault = $m[2];

            if ($attrs['path'] === null) {
                $errors[] = 'boost:conv token missing required attribute "path"';

                return $m[0];
            }

            if ($attrs['mode'] === null) {
                $errors[] = sprintf('boost:conv token for "%s" missing required attribute "mode"', $attrs['path']);

                return $m[0];
            }

            $resolution = $this->resolver->resolve($attrs['path'], $attrs['mode'], $attrs['fallback'] ?? $visibleDefault);
            if (! $resolution->ok) {
                $errors[] = (string) $resolution->error;

                return $m[0];
            }

            ++$inlinedCount;

            return $resolution->output;
        }, $text);

        return $replaced ?? $text;
    }

    /**
     * @param  list<string>  $errors
     */
    private function resolveUnpairedTokens(string $text, array &$errors, int &$inlinedCount): string
    {
        $replaced = preg_replace_callback(self::TOKEN, function (array $m) use (&$errors, &$inlinedCount): string {
            // Escaped token (`<!--\boost:conv …-->`) → render the literal token.
            if ($m[1] === '\\') {
                return '<!--boost:conv ' . $m[2] . '-->';
            }

            $attrs = $this->parseAttributes($m[2]);
            if ($attrs['path'] === null) {
                $errors[] = 'boost:conv token missing required attribute "path"';

                return $m[0];
            }

            if ($attrs['mode'] === null) {
                $errors[] = sprintf('boost:conv token for "%s" missing required attribute "mode"', $attrs['path']);

                return $m[0];
            }

            $resolution = $this->resolver->resolve($attrs['path'], $attrs['mode'], $attrs['fallback']);
            if (! $resolution->ok) {
                $errors[] = (string) $resolution->error;

                return $m[0];
            }

            ++$inlinedCount;

            return $resolution->output;
        }, $text);

        return $replaced ?? $text;
    }

    /**
     * @return array{path: ?string, mode: ?string, fallback: ?string}
     */
    private function parseAttributes(string $raw): array
    {
        return [
            'path' => $this->attr($raw, 'path'),
            'mode' => $this->attr($raw, 'mode'),
            'fallback' => $this->attr($raw, 'fallback'),
        ];
    }

    private function attr(string $raw, string $name): ?string
    {
        if (preg_match('/\b' . preg_quote($name, '/') . '\s*=\s*"([^"]*)"/', $raw, $m) === 1) {
            return $m[1];
        }

        return null;
    }

    /**
     * The body still needs the rendered block if it carries a legacy runtime
     * `$.<root>` reference, an unresolved/errored token, or any token error.
     *
     * @param  list<string>  $errors
     */
    private function requiresRuntime(string $body, array $errors): bool
    {
        if ($errors !== []) {
            return true;
        }

        if (str_contains($body, '<!--boost:conv ') || str_contains($body, '<!--\\boost:conv ') || str_contains($body, self::END_MARKER)) {
            return true;
        }

        return $this->hasLegacyRef($body);
    }

    /**
     * Does this text DEPEND on the rendered conventions block (drop-gate KEEP
     * signal)? Either a legacy `$.<root>` runtime reference, OR heading-relative
     * prose that points at the section. Used by the gate on both the emitted
     * guidance body AND the EXISTING on-disk content that migrate() may preserve
     * as residual, and as a broader replacement for the brittle
     * exact-string "Project Conventions" check (real prose varies).
     */
    public function dependsOnConventions(string $text): bool
    {
        // An unresolved token in preserved/existing content also depends on the
        // block (it never got inlined) — check it alongside legacy refs +
        // pointers so residual a migration carries forward keeps the block.
        if (str_contains($text, '<!--boost:conv ') || str_contains($text, '<!--\\boost:conv ') || str_contains($text, self::END_MARKER)) {
            return true;
        }

        if ($this->hasLegacyRef($text)) {
            return true;
        }

        return $this->mentionsConventionsPointer($text);
    }

    private function hasLegacyRef(string $text): bool
    {
        if ($this->slotRoots === []) {
            return false;
        }

        return preg_match('/\$\.(?:' . $this->slotRootsAlternation() . ')\b/', $text) === 1;
    }

    /**
     * The legacy `$.<root>...` runtime references in a body — refs boost-core
     * never resolves (it only DETECTS them; they emit literally) and which, since
     * the `## Project Conventions` block is CLAUDE.md-only, dangle unresolved for
     * non-Claude agents. Matched only against KNOWN slot roots (from the composed
     * schema), so plain `$.foo` text isn't a false positive.
     *
     * PROSE-scoped, with inline-code spans masked — mirroring {@see scanLeaks()}:
     * a `$.slot` ref shown inside backticks or a fenced block is an intentional
     * documentation example (the shipped conventions-migration skills do exactly
     * this), NOT a live dangling reference, so it must not be flagged. Returns the
     * full dotted refs (e.g. `$.jira.project_key`), de-duplicated, in first-seen order.
     *
     * @return list<string>
     */
    public function legacyRefsIn(string $text): array
    {
        if ($this->slotRoots === []) {
            return [];
        }

        $pattern = '/\$\.(?:' . $this->slotRootsAlternation() . ')(?:\.[A-Za-z0-9_-]+)*/';
        $found = [];

        $this->walkLines($text, function (int $_lineNo, string $line, string $context) use ($pattern, &$found): void {
            if ($context !== self::CTX_PROSE) {
                return;
            }

            $spans = [];
            $masked = $this->maskInlineCode($line, $spans);
            if (preg_match_all($pattern, $masked, $matches) === 0) {
                return;
            }

            foreach ($matches[0] as $ref) {
                $found[$ref] = true;
            }
        });

        return array_keys($found);
    }

    /**
     * Human-readable descriptions of WHY this text depends on the rendered
     * conventions block — the keep-reason provenance behind {@see
     * dependsOnConventions()}. Mirrors that method's KEEP signals one-for-one so a
     * body the gate keeps the block for always yields at least one cause:
     *
     *  - an unresolved/errored `boost:conv` token (never inlined);
     *  - each legacy `$.<root>` runtime reference (named individually when prose-
     *    scoped detection pins them; a generic fallback when only the flat gate
     *    signal trips, e.g. a ref the prose scoper masks);
     *  - a heading-relative prose pointer at the section.
     *
     * Returns an empty list when nothing depends on the block.
     *
     * @return list<string>
     */
    public function dependencyCauses(string $text): array
    {
        $causes = [];

        if (str_contains($text, '<!--boost:conv ') || str_contains($text, '<!--\\boost:conv ')) {
            $causes[] = 'an unresolved conventions token';
        }

        $refs = $this->legacyRefsIn($text);
        foreach ($refs as $ref) {
            $causes[] = sprintf('legacy slot reference `%s`', $ref);
        }

        // Flat gate signal trips but the prose scoper named nothing (e.g. a ref the
        // inline-code mask skipped) — keep a cause so the reason is never blank.
        if ($refs === [] && $this->hasLegacyRef($text)) {
            $causes[] = 'a legacy `$.<root>` slot reference';
        }

        if ($this->mentionsConventionsPointer($text)) {
            $causes[] = 'a prose pointer to the Project Conventions section';
        }

        return $causes;
    }

    private function slotRootsAlternation(): string
    {
        return implode('|', array_map(static fn (string $r): string => preg_quote($r, '/'), $this->slotRoots));
    }

    private function mentionsConventionsPointer(string $text): bool
    {
        // Broad, conservative (a match KEEPS the block — over-keep is safe; a
        // miss would wrongly drop). Covers "Project Conventions", "the
        // conventions section", and heading-relative prose like "the
        // branches.patterns block above" / "see the section above".
        $patterns = [
            '/project conventions/i',
            '/conventions section/i',
            '/\bsection above\b/i',
            '/\bblock above\b/i',
            '/above[\s\S]{0,40}\bconvention/i',
            '/convention[\s\S]{0,40}\babove\b/i',
        ];

        foreach ($patterns as $pattern) {
            if (preg_match($pattern, $text) === 1) {
                return true;
            }
        }

        return false;
    }
}
