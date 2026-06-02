<?php declare(strict_types=1);

namespace SanderMuller\BoostCore\Skills;

use Symfony\Component\Yaml\Exception\ParseException;
use Symfony\Component\Yaml\Yaml;

/**
 * Parses YAML frontmatter from markdown content.
 *
 * Loose v1 schema — every field is optional, unknown keys pass through.
 * This parser just extracts what's there; no schema validation.
 *
 * Format:
 *
 *     ---
 *     name: foo
 *     description: bar
 *     ---
 *     # Body content
 *
 * Files without a frontmatter block are valid — they return an empty
 * frontmatter array and the full content as body.
 *
 * @internal
 */
final class FrontmatterParser
{
    public function parse(string $content): ParsedDocument
    {
        if (! str_starts_with($content, "---\n") && ! str_starts_with($content, "---\r\n")) {
            return new ParsedDocument([], $content);
        }

        // Find the closing fence. Match `\n---\n` or `\n---\r\n` or `\n---` at end of file.
        $remainder = substr($content, 4);
        $closingFencePosition = $this->findClosingFence($remainder);

        if ($closingFencePosition === null) {
            // No closing fence — not a valid frontmatter block, treat as body.
            return new ParsedDocument([], $content);
        }

        $rawFrontmatter = substr($remainder, 0, $closingFencePosition);
        $body = substr($remainder, $closingFencePosition + $this->fenceLengthAt($remainder, $closingFencePosition));

        try {
            $parsed = Yaml::parse($rawFrontmatter);
        } catch (ParseException) {
            return new ParsedDocument([], $content);
        }

        if (! is_array($parsed)) {
            return new ParsedDocument([], $content);
        }

        /** @var array<string, mixed> $frontmatter */
        $frontmatter = $parsed;

        return new ParsedDocument($frontmatter, ltrim($body, "\r\n"));
    }

    private function findClosingFence(string $remainder): ?int
    {
        $candidates = ["\n---\n", "\n---\r\n", "\n---"];

        foreach ($candidates as $candidate) {
            $pos = strpos($remainder, $candidate);
            if ($pos !== false) {
                return $pos + 1; // +1 to point at the `---` line itself, not the preceding newline
            }
        }

        return null;
    }

    private function fenceLengthAt(string $remainder, int $position): int
    {
        if (str_starts_with(substr($remainder, $position), "---\r\n")) {
            return 5;
        }

        if (str_starts_with(substr($remainder, $position), "---\n")) {
            return 4;
        }

        return 3; // ---<EOF>
    }
}
