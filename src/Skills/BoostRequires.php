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
     * The `subagent:` demand prefix. The only prefix the syntax defines — any
     * other is malformed, because a bare skill name never contains a colon.
     */
    public const string SUBAGENT_PREFIX = 'subagent:';

    /**
     * @param  array<string, mixed>  $frontmatter
     * @return array{0: list<string>, 1: bool}  [required skill names, valid]
     *
     * @api
     */
    public static function parse(array $frontmatter): array
    {
        [$skills, , $valid] = self::partition($frontmatter);

        return [$skills, $valid];
    }

    /**
     * The SUBAGENT names a skill demands — the `subagent:`-prefixed tokens,
     * with the prefix stripped.
     *
     * Split from {@see parse()} rather than folded into it so the existing
     * return shape stays byte-identical for callers that predate subagents.
     *
     * @param  array<string, mixed>  $frontmatter
     * @return array{0: list<string>, 1: bool}  [required subagent names, valid]
     *
     * @api
     */
    public static function parseSubagents(array $frontmatter): array
    {
        [, $subagents, $valid] = self::partition($frontmatter);

        return [$subagents, $valid];
    }

    /**
     * Tokenize once and split by demand kind.
     *
     * A token carrying an UNKNOWN prefix (any colon that is not
     * `subagent:`) makes the whole value invalid, matching the
     * malformed-value contract: sync warns, `boost validate --strict` errors,
     * and the skill still ships — requires gate completeness, not scoping.
     * Failing closed instead would let a typo silently unship a skill.
     *
     * @param  array<string, mixed>  $frontmatter
     * @return array{0: list<string>, 1: list<string>, 2: bool}  [skill names, subagent names, valid]
     */
    private static function partition(array $frontmatter): array
    {
        $metadata = $frontmatter['metadata'] ?? null;
        if (! is_array($metadata) || ! array_key_exists('boost-requires', $metadata)) {
            return [[], [], true];
        }

        $raw = $metadata['boost-requires'];
        if (! is_string($raw)) {
            return [[], [], false];
        }

        $tokens = preg_split('/\s+/', trim($raw), -1, PREG_SPLIT_NO_EMPTY);
        if ($tokens === false) {
            return [[], [], true];
        }

        $skills = [];
        $subagents = [];
        $valid = true;

        foreach (array_unique($tokens) as $token) {
            if (str_starts_with($token, self::SUBAGENT_PREFIX)) {
                $name = substr($token, strlen(self::SUBAGENT_PREFIX));
                if ($name === '') {
                    $valid = false;

                    continue;
                }

                $subagents[] = $name;

                continue;
            }

            if (str_contains($token, ':')) {
                $valid = false;

                continue;
            }

            $skills[] = $token;
        }

        return [$skills, $subagents, $valid];
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
