<?php declare(strict_types=1);

namespace SanderMuller\BoostCore\Skills;

/**
 * @internal
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
