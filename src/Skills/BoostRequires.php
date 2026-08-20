<?php declare(strict_types=1);

namespace SanderMuller\BoostCore\Skills;

/**
 * Extracts hard skill dependencies from a skill's frontmatter — the
 * counterpart of {@see BoostTags} for the `boost-requires` key.
 *
 * Dependencies live under the Agent Skills standard's sanctioned extension
 * point — the optional `metadata` string→string map — as a single
 * space-delimited `boost-requires` value of bare skill names (never
 * vendor-qualified: after collision resolution a name maps to exactly one
 * shipped skill, and a host-authored skill satisfies a vendor skill's
 * requirement by design):
 *
 *     metadata:
 *       boost-requires: "write-spec code-review"
 *
 * Unlike `boost-tags`, names are NOT case-folded — they must compare exactly
 * as skill names resolve (frontmatter `name:` or filename). And unlike the
 * tag parser's fail-closed contract, a malformed value (`boost-requires`
 * present but not a string) does not stop the skill from shipping — requires
 * gate completeness, not scoping, so shipping without deps is the
 * pre-feature status quo. `valid` = false is surfaced as a sync warning and
 * a `boost validate` error instead.
 *
 * The `@api` requires-parse seam: a wrapper that injects skills computes the
 * SAME `[requires, valid]` the engine does instead of reinventing the
 * tokenize + validate.
 *
 * @api
 */
final class BoostRequires
{
    /**
     * @param  array<string, mixed>  $frontmatter
     * @return array{0: list<string>, 1: bool}  [required skill names, valid]
     *
     * @api
     */
    public static function parse(array $frontmatter): array
    {
        $metadata = $frontmatter['metadata'] ?? null;
        if (! is_array($metadata) || ! array_key_exists('boost-requires', $metadata)) {
            return [[], true];
        }

        $raw = $metadata['boost-requires'];
        if (! is_string($raw)) {
            return [[], false];
        }

        $tokens = preg_split('/\s+/', trim($raw), -1, PREG_SPLIT_NO_EMPTY);

        return [array_values(array_unique($tokens === false ? [] : $tokens)), true];
    }

    /**
     * Whether `$frontmatter` declares a `metadata.boost-requires` key at all —
     * regardless of whether its value is valid. Mirrors
     * {@see BoostTags::declaresTags()}.
     *
     * @param  array<string, mixed>  $frontmatter
     *
     * @api
     */
    public static function declaresRequires(array $frontmatter): bool
    {
        $metadata = $frontmatter['metadata'] ?? null;

        return is_array($metadata) && array_key_exists('boost-requires', $metadata);
    }
}
