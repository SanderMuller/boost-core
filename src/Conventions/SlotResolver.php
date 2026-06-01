<?php declare(strict_types=1);

namespace SanderMuller\BoostCore\Conventions;

use Symfony\Component\Yaml\Yaml;

/**
 * Resolves a convention slot reference to inlined text at sync/render time.
 *
 * Given a slot `path` (dot-notation, e.g. `jira.project_key`), a render `mode`,
 * and an optional inline `fallback`, produces the text to splice in place of a
 * `<!--boost:conv …-->` token. Three-state resolution by PATH-EXISTENCE (D2),
 * NOT truthiness — a declared `false` / `[]` / `''` is DECLARED, never treated
 * as missing:
 *
 *   declared (path exists in boost.php conventions) → render the declared value
 *   else schema default present                     → render the default
 *   else inline fallback present                    → emit the fallback prose
 *   else                                            → ERROR (nothing to render)
 *
 * Type × mode matrix (3ncrxzev-B + k5m15b0p list-inline; a mismatch is a
 * render-class error):
 *   scalar → inline | yaml | json            (NOT bullets)
 *   list   → inline | bullets | yaml | json  (inline = comma-joined; for
 *                                             prose-flow slots like testing.forbid)
 *   map    → yaml | json                     (NOT inline, NOT bullets)
 *
 * A schema may pin a per-slot `render` (a mode string or list of allowed modes)
 * — the token's mode is validated against it (drift guard, 3ncrxzev-B).
 */
final readonly class SlotResolver
{
    private const MODES = ['inline', 'bullets', 'yaml', 'json'];

    /**
     * @param  array<string, mixed>  $conventions     declared values from boost.php (nested)
     * @param  array<string, mixed>  $composedSchema  ConventionsSchema::compose() output
     */
    public function __construct(
        private array $conventions,
        private array $composedSchema,
    ) {}

    public function resolve(string $path, string $mode, ?string $fallback): SlotResolution
    {
        if (! in_array($mode, self::MODES, true)) {
            return SlotResolution::error(sprintf('unknown render mode "%s" for slot "%s" (expected one of: %s)', $mode, $path, implode(', ', self::MODES)));
        }

        $leaf = $this->schemaLeaf($path);
        if ($leaf === null) {
            return SlotResolution::error(sprintf('unknown convention slot "%s" — not defined in the composed conventions schema', $path));
        }

        // Schema-pinned render mode (drift guard) — validate BEFORE resolving a value.
        $pin = $leaf['render'] ?? null;
        if ($pin !== null && ! $this->modeMatchesPin($mode, $pin)) {
            return SlotResolution::error(sprintf('render mode "%s" not allowed for slot "%s" (schema pins: %s)', $mode, $path, $this->pinToString($pin)));
        }

        // 1. declared (path-existence, even if falsy).
        $declared = $this->declaredValue($path);
        if ($declared->found) {
            return $this->render($declared->value, $mode, $path, SlotResolution::PROVENANCE_DECLARED);
        }

        // 2. schema default — the leaf's own `default`, OR an ancestor open-vocab
        // map's `default` indexed by the sub-key (e.g. `mcp.jira`).
        $default = $this->schemaDefault($path);
        if ($default->found) {
            return $this->render($default->value, $mode, $path, SlotResolution::PROVENANCE_SCHEMA_DEFAULT);
        }

        // 3. inline fallback prose (emitted verbatim — it's author-written text).
        if ($fallback !== null) {
            return SlotResolution::ok($fallback, SlotResolution::PROVENANCE_FALLBACK);
        }

        // 4. nothing to render → render-class error (never a silent vanish — D7).
        return SlotResolution::error(sprintf('slot "%s" is unset, has no schema default, and the token supplies no fallback', $path));
    }

    private function render(mixed $value, string $mode, string $path, string $provenance): SlotResolution
    {
        $type = $this->classify($value);

        if (! $this->matrixAllows($type, $mode)) {
            return SlotResolution::error(sprintf('a %s value cannot render in mode "%s" for slot "%s"', $type, $mode, $path));
        }

        if ($mode === 'inline' && is_string($value) && str_contains($value, "\n")) {
            return SlotResolution::error(sprintf('slot "%s" holds a multi-line value and cannot render inline — use mode="yaml" or mode="json"', $path));
        }

        if ($mode === 'inline' && $type === 'list' && ! $this->isScalarList($value)) {
            return SlotResolution::error(sprintf('slot "%s" is a list of structured items and cannot render inline (comma-join) — use mode="yaml" or mode="bullets"', $path));
        }

        return SlotResolution::ok($this->format($value, $mode), $provenance);
    }

    private function format(mixed $value, string $mode): string
    {
        return match ($mode) {
            'inline' => is_array($value) ? $this->formatInlineList($value) : $this->formatScalarInline($value),
            'bullets' => $this->formatBullets($value),
            'yaml' => rtrim(Yaml::dump($value, 4, 2, Yaml::DUMP_EMPTY_ARRAY_AS_SEQUENCE)),
            'json' => $this->formatJson($value),
            default => '',
        };
    }

    private function formatJson(mixed $value): string
    {
        $json = json_encode($value, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);

        return $json === false ? 'null' : $json;
    }

    private function formatScalarInline(mixed $value): string
    {
        if (is_bool($value)) {
            return $value ? 'true' : 'false';
        }

        if ($value === null) {
            return 'null';
        }

        return is_scalar($value) ? (string) $value : '';
    }

    /**
     * A list renders INLINE as a comma-joined clause (k5m15b0p — prose-flow
     * slots like `testing.forbid` / `spec.research_docs`); a DECLARED-EMPTY list
     * renders the literal "none" (never a dangling clause — 3ncrxzev-C nit).
     */
    private function formatInlineList(mixed $value): string
    {
        if (! is_array($value) || $value === []) {
            return 'none';
        }

        return implode(', ', array_map(static fn (mixed $item): string => is_scalar($item) ? (string) $item : '', $value));
    }

    /**
     * A list renders as bullets; a DECLARED-EMPTY list renders the literal
     * "none" (never a stray `- ` — 3ncrxzev-C).
     */
    private function formatBullets(mixed $value): string
    {
        if (! is_array($value) || $value === []) {
            return 'none';
        }

        $lines = [];
        foreach ($value as $item) {
            $lines[] = '- ' . (is_scalar($item) ? (string) $item : trim(Yaml::dump($item, 1, 2)));
        }

        return implode("\n", $lines);
    }

    private function isScalarList(mixed $value): bool
    {
        if (! is_array($value)) {
            return false;
        }

        foreach ($value as $item) {
            if (is_array($item)) {
                return false;
            }
        }

        return true;
    }

    private function classify(mixed $value): string
    {
        if (! is_array($value)) {
            return 'scalar';
        }

        return array_is_list($value) ? 'list' : 'map';
    }

    private function matrixAllows(string $type, string $mode): bool
    {
        return match ($type) {
            'scalar' => in_array($mode, ['inline', 'yaml', 'json'], true),
            'list' => in_array($mode, ['inline', 'bullets', 'yaml', 'json'], true),
            'map' => in_array($mode, ['yaml', 'json'], true),
            default => false,
        };
    }

    /**
     * Look up a slot's leaf schema by dot-path. Navigates nested `properties`;
     * for a segment with no matching property, descends into an open-vocab map's
     * `additionalProperties` schema — so a dynamic map sub-key like
     * `mcp.jira` is an addressable, type-classifiable leaf, not an "unknown slot".
     * Returns null when the path is defined by neither.
     *
     * @return array<mixed, mixed>|null
     */
    private function schemaLeaf(string $path): ?array
    {
        $node = $this->composedSchema;
        foreach (explode('.', $path) as $segment) {
            $props = $node['properties'] ?? null;
            if (is_array($props) && isset($props[$segment]) && is_array($props[$segment])) {
                $node = $props[$segment];

                continue;
            }

            $additional = $node['additionalProperties'] ?? null;
            if (is_array($additional)) {
                $node = $additional;

                continue;
            }

            return null;
        }

        return $node;
    }

    /**
     * The effective schema default for a slot path, by PATH-EXISTENCE.
     * Walks the schema along the path; the deepest node carrying a `default` wins,
     * with the segments BELOW that node indexed into the default value. This
     * resolves both a normal leaf default (`github.default_base_branch` → the
     * leaf's `default`) AND an open-vocab map sub-key (`mcp.jira` → the `mcp`
     * node's `default` map indexed by `jira`), which has no leaf default of its
     * own. `found` is false when no ancestor default covers the path.
     */
    private function schemaDefault(string $path): DeclaredLookup
    {
        $segments = explode('.', $path);
        $node = $this->composedSchema;
        $best = new DeclaredLookup(false, null);

        for ($i = 0, $n = count($segments); $i <= $n; ++$i) {
            if (array_key_exists('default', $node)) {
                $candidate = $this->indexInto($node['default'], array_slice($segments, $i));
                if ($candidate->found) {
                    $best = $candidate; // a deeper default-bearing node wins
                }
            }

            if ($i === $n) {
                break;
            }

            $segment = $segments[$i];
            $props = $node['properties'] ?? null;
            if (is_array($props) && isset($props[$segment]) && is_array($props[$segment])) {
                $node = $props[$segment];

                continue;
            }

            $additional = $node['additionalProperties'] ?? null;
            if (is_array($additional)) {
                $node = $additional;

                continue;
            }

            break;
        }

        return $best;
    }

    /**
     * Index `$segments` into `$value` (a schema `default`). Empty segments → the
     * value itself (a normal leaf default). A non-array encountered mid-path, or a
     * missing key, → not found.
     *
     * @param  list<string>  $segments
     */
    private function indexInto(mixed $value, array $segments): DeclaredLookup
    {
        foreach ($segments as $segment) {
            if (! is_array($value) || ! array_key_exists($segment, $value)) {
                return new DeclaredLookup(false, null);
            }

            $value = $value[$segment];
        }

        return new DeclaredLookup(true, $value);
    }

    /**
     * Declared-value lookup by PATH-EXISTENCE (D2): `found` is true when every
     * segment exists as an array key, even if the leaf value is falsy.
     */
    private function declaredValue(string $path): DeclaredLookup
    {
        $node = $this->conventions;
        $segments = explode('.', $path);
        foreach ($segments as $i => $segment) {
            if (! is_array($node) || ! array_key_exists($segment, $node)) {
                return new DeclaredLookup(false, null);
            }

            if ($i === count($segments) - 1) {
                return new DeclaredLookup(true, $node[$segment]);
            }

            $node = $node[$segment];
        }

        return new DeclaredLookup(false, null);
    }

    private function modeMatchesPin(string $mode, mixed $pin): bool
    {
        if (is_string($pin)) {
            return $mode === $pin;
        }

        return is_array($pin) && in_array($mode, $pin, true);
    }

    private function pinToString(mixed $pin): string
    {
        if (is_string($pin)) {
            return $pin;
        }

        return is_array($pin) ? implode(', ', array_filter($pin, is_string(...))) : '(invalid)';
    }
}
