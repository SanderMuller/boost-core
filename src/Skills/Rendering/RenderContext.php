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
 * New fields append as TRAILING constructor params with a default, so an
 * existing renderer (and any test that constructs this) keeps compiling.
 */
final readonly class RenderContext
{
    /**
     * @param  array<string, mixed>  $frontmatter  Pre-render frontmatter (may be partial if template emits its own).
     * @param  string|null  $projectRoot  The project root being synced — lets a
     *   renderer resolve project-relative paths / bootstrap its own services
     *   without reaching for a global container. Null when the loader has no
     *   project-root context (e.g. a standalone load outside a sync).
     */
    public function __construct(
        public string $sourcePath,
        public ?string $sourceVendor,
        public array $frontmatter,
        public ?string $projectRoot = null,
    ) {}
}
