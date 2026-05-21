<?php declare(strict_types=1);

namespace SanderMuller\BoostCore\Enums;

/**
 * Convenience tag vocabulary for conditional skill filtering.
 *
 * NON-AUTHORITATIVE. The canonical tag type is `string` and the vocabulary
 * is open — this enum exists only for consumer-side ergonomics. `boost.php`
 * authors get autocomplete and a discoverable common set via
 * `BoostConfigBuilder::withTags()`, which accepts `Tag|string`. boost-core
 * never validates a skill's declared tag against this enum; any string is a
 * legal tag. New common tags can be added here freely — doing so closes
 * nothing.
 */
enum Tag: string
{
    case Php = 'php';
    case Laravel = 'laravel';
    case Frontend = 'frontend';
    case Jira = 'jira';
    case Github = 'github';
    case GithubIssues = 'github-issues';

    /**
     * Canonical tag normalization, applied identically to a skill's declared
     * tags (`metadata.boost-tags`) and a consumer's `withTags()` declaration
     * so the two always compare like-for-like.
     */
    public static function normalize(string $tag): string
    {
        return strtolower(trim($tag));
    }
}
