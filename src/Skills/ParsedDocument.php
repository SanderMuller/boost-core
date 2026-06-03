<?php declare(strict_types=1);

namespace SanderMuller\BoostCore\Skills;

/**
 * The result of {@see FrontmatterParser::parse()} — the parsed YAML head and the
 * remaining body.
 *
 * @api Stable as of 1.0. `$frontmatter` (the parsed head, `array<string, mixed>`)
 * and `$body` are the frozen read surface.
 */
final readonly class ParsedDocument
{
    /**
     * @param  array<string, mixed>  $frontmatter
     */
    public function __construct(
        public array $frontmatter,
        public string $body,
    ) {}
}
