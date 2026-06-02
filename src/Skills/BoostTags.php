<?php declare(strict_types=1);

namespace SanderMuller\BoostCore\Skills;

use SanderMuller\BoostCore\Enums\Tag;

/**
 * Extracts conditional-filtering tags from a skill's or guideline's
 * frontmatter — the shared parser behind `SkillLoader` and `GuidelineLoader`.
 *
 * Tags live under the Agent Skills standard's sanctioned extension point —
 * the optional `metadata` string→string map — as a single space-delimited
 * `boost-tags` value (the namespaced-key recommendation; mirrors the
 * standard's own space-separated `allowed-tools`):
 *
 *     metadata:
 *       boost-tags: "php jira"
 *
 * Fails closed: when `boost-tags` is present but not a string, the document
 * is marked tag-invalid (`valid` = false) and ships nowhere — a typo must
 * not silently leave it untagged (= ships everywhere) and leak a scoped
 * skill or guideline. A missing `metadata`, a `metadata` that is not a map,
 * or an absent `boost-tags` key is untagged-valid.
 *
 * @internal
 */
final class BoostTags
{
    /**
     * @param  array<string, mixed>  $frontmatter
     * @return array{0: list<string>, 1: bool}  [normalized tags, valid]
     */
    public static function parse(array $frontmatter): array
    {
        $metadata = $frontmatter['metadata'] ?? null;
        if (! is_array($metadata) || ! array_key_exists('boost-tags', $metadata)) {
            return [[], true];
        }

        $raw = $metadata['boost-tags'];
        if (! is_string($raw)) {
            return [[], false];
        }

        return [self::parseString($raw), true];
    }

    /**
     * Whether `$frontmatter` declares a `metadata.boost-tags` key at all —
     * regardless of whether its value is valid. Lets a caller distinguish
     * "the author left tags unspecified" (a guideline may then fall back to
     * the `.boost-tags.yaml` manifest) from "the author declared tags" (that
     * declaration wins, even when malformed).
     *
     * @param  array<string, mixed>  $frontmatter
     */
    public static function declaresTags(array $frontmatter): bool
    {
        $metadata = $frontmatter['metadata'] ?? null;

        return is_array($metadata) && array_key_exists('boost-tags', $metadata);
    }

    /**
     * Tokenize a raw space-delimited `boost-tags` value into normalized,
     * de-duplicated tags — the shared lexer behind {@see parse()} and the
     * guideline `.boost-tags.yaml` manifest. A known string always yields a
     * (possibly empty) list; invalidity — a non-string value — is rejected
     * by the caller before it reaches here.
     *
     * @return list<string>
     */
    public static function parseString(string $raw): array
    {
        $tokens = preg_split('/\s+/', trim($raw), -1, PREG_SPLIT_NO_EMPTY);

        $tags = [];
        foreach ($tokens === false ? [] : $tokens as $token) {
            $normalized = Tag::normalize($token);
            if ($normalized !== '') {
                $tags[] = $normalized;
            }
        }

        return array_values(array_unique($tags));
    }
}
