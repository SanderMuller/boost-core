<?php declare(strict_types=1);

namespace SanderMuller\BoostCore\Contracts;

use SanderMuller\BoostCore\Skills\Rendering\RenderContext;

/**
 * Plugin seam for rendering template-flavored skill bodies (Blade, Twig, …).
 *
 * @experimental Will change before v1.0 stable. Pin to exact boost-core
 * version if building against this. Lock-in happens after a second
 * non-trivial consumer from a different problem domain validates the shape
 * (mirrors {@see FileEmitter}'s lock-in criteria).
 *
 * Reference consumer: `sandermuller/project-boost-laravel` ships a
 * `BladeRenderer` that bridges to laravel/boost's `RendersBladeGuidelines`
 * trait so its `.blade.php` skills render with the `$assist` runtime context
 * they expect.
 *
 * Contract:
 * - Parameterless constructor. Anything more is deferred to a factory
 *   pattern when renderer #2 demands it (mirrors {@see FileEmitter}).
 * - Identity is the fully-qualified class name (FQCN). Used in JSON output,
 *   `withDisabledRenderers`, and conflict-detection error messages.
 * - `extensions()` returns lowercase, no-leading-dot extension strings.
 *   Multi-segment extensions (`blade.php`) are matched longest-first by the
 *   dispatcher so a `.php` renderer cannot hijack `.blade.php`.
 * - `render()` receives the raw file content INCLUDING frontmatter. The
 *   dispatcher re-parses frontmatter from the rendered output, so a
 *   renderer that strips frontmatter must restore it. Most renderers
 *   template the whole file and frontmatter survives as inert YAML head.
 * - Throwing is caught by the loader. Behavior is gated by
 *   `BOOST_RENDER_STRICT`: lenient records `SyncResult::errors` and
 *   skips the file, strict re-throws as `SkillRenderException`.
 */
interface SkillRenderer
{
    /**
     * File extensions this renderer handles. Lowercase, no leading dot.
     * Multi-segment extensions allowed (`blade.php`, `liquid.html`).
     *
     * @return list<string>
     */
    public function extensions(): array;

    /**
     * Render the raw file content. Returns the post-render markdown body
     * (with frontmatter intact unless the renderer regenerated it).
     */
    public function render(string $raw, RenderContext $ctx): string;
}
