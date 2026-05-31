<?php declare(strict_types=1);

namespace SanderMuller\BoostCore\Conventions;

/**
 * Resolves `<!--boost:conv …-->` tokens in a skill/guidance BODY into inlined
 * convention values at sync time (0.15.0 conventions inlining).
 *
 * Token: `<!--boost:conv path="jira.project_key" mode="inline" fallback="ask the user"-->`
 *  - `path` (required) — dot-notation slot path;
 *  - `mode` (required) — inline | bullets | yaml | json;
 *  - `fallback` (optional) — prose emitted when the slot is unset AND has no
 *    schema default (required only for no-default slots — D9).
 *
 * Scope (D1, codex P2.5): tokens resolve in PROSE and in OPT-IN fenced code
 * blocks (info-string contains `boost:conv` — 3ncrxzev-A). They are LEFT LITERAL
 * inside plain fenced code blocks and inline-code spans (so authoring docs can
 * show the token verbatim). An ESCAPE — `<!--\boost:conv …-->` — renders the
 * literal token text in prose. (Callers keep tokens out of FRONTMATTER; this
 * operates on the body only.)
 *
 * Errors are render-class (D7): an errored token is left in place + reported, so
 * `boost sync --check` fails and the conventions block is kept.
 */
final readonly class ConventionsInliner
{
    private const TOKEN = '/<!--(\\\\?)boost:conv\s+(.*?)-->/s';

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
        $lines = explode("\n", $body);

        $out = [];
        $inFence = false;
        $fenceMarker = '';
        $fenceOptIn = false;

        foreach ($lines as $line) {
            if (preg_match('/^\s*(`{3,}|~{3,})(.*)$/', $line, $m) === 1) {
                if (! $inFence) {
                    $inFence = true;
                    $fenceMarker = $m[1];
                    $fenceOptIn = str_contains($m[2], 'boost:conv');
                    // Strip the opt-in flag from the emitted info-string so the
                    // fence renders clean (```json boost:conv → ```json).
                    $out[] = $fenceOptIn ? rtrim(str_replace('boost:conv', '', $line)) : $line;
                } elseif (str_starts_with(ltrim($line), $fenceMarker)) {
                    $inFence = false;
                    $fenceOptIn = false;
                    $out[] = $line;
                } else {
                    $out[] = $line;
                }

                continue;
            }

            if ($inFence) {
                $out[] = $fenceOptIn ? $this->resolveTokens($line, $errors, $inlinedCount) : $line;

                continue;
            }

            $out[] = $this->resolveProseLine($line, $errors, $inlinedCount);
        }

        $result = implode("\n", $out);

        return new InlineResult($result, $this->requiresRuntime($result, $errors), $errors, $inlinedCount > 0);
    }

    /**
     * Resolve tokens in a prose line, leaving inline-code spans (`` `…` ``)
     * untouched so a token shown as inline code stays literal.
     *
     * @param  list<string>  $errors
     */
    private function resolveProseLine(string $line, array &$errors, int &$inlinedCount): string
    {
        // Mask inline-code spans, resolve, then restore — so tokens inside
        // backticks are never resolved. Matches a balanced run of N backticks
        // (codex P2): `…`, ``…``, ```…``` all protect a literal token example.
        $spans = [];
        $masked = preg_replace_callback('/(`+)(.*?)\1/', function (array $m) use (&$spans): string {
            $key = "\x00CODE" . count($spans) . "\x00";
            $spans[$key] = $m[0];

            return $key;
        }, $line);
        $masked ??= $line;

        $resolved = $this->resolveTokens($masked, $errors, $inlinedCount);

        return strtr($resolved, $spans);
    }

    /**
     * @param  list<string>  $errors
     */
    private function resolveTokens(string $text, array &$errors, int &$inlinedCount): string
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

        if (str_contains($body, '<!--boost:conv ') || str_contains($body, '<!--\\boost:conv ')) {
            return true;
        }

        return $this->hasLegacyRef($body);
    }

    /**
     * Does this text DEPEND on the rendered conventions block (drop-gate KEEP
     * signal)? Either a legacy `$.<root>` runtime reference, OR heading-relative
     * prose that points at the section. Used by the gate on both the emitted
     * guidance body AND the EXISTING on-disk content that migrate() may preserve
     * as residual (codex P1.1), and as a broader replacement for the brittle
     * exact-string "Project Conventions" check (codex P1.2 — real prose varies).
     */
    public function dependsOnConventions(string $text): bool
    {
        // An unresolved token in preserved/existing content also depends on the
        // block (it never got inlined) — check it alongside legacy refs +
        // pointers so residual a migration carries forward keeps the block.
        if (str_contains($text, '<!--boost:conv ') || str_contains($text, '<!--\\boost:conv ')) {
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

        $roots = implode('|', array_map(static fn (string $r): string => preg_quote($r, '/'), $this->slotRoots));

        return preg_match('/\$\.(?:' . $roots . ')\b/', $text) === 1;
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
