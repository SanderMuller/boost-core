<?php declare(strict_types=1);

namespace SanderMuller\BoostCore\Skills\Rendering;

use SanderMuller\BoostCore\Contracts\SkillRenderer;

/**
 * Read-only context handed to a {@see SkillRenderer}.
 *
 * Pre-parsed `$frontmatter` is the file's YAML head (may be empty when the
 * file has no `---` block). The renderer's output is parsed for frontmatter
 * again in `SkillLoader`, so a template that regenerates its frontmatter is
 * free to ignore `$frontmatter` here — but most renderers should leave it
 * intact since templates typically wrap the body, not the head.
 *
 * @api Stable as of 1.0 — the read-only context passed to {@see SkillRenderer}.
 */
final readonly class RenderContext
{
    /**
     * @param  array<string, mixed>  $frontmatter  Pre-render frontmatter (may be partial if template emits its own).
     */
    public function __construct(
        public string $sourcePath,
        public ?string $sourceVendor,
        public array $frontmatter,
    ) {}
}
