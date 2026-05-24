<?php declare(strict_types=1);

namespace SanderMuller\BoostCore\Skills\Rendering;

use Override;
use SanderMuller\BoostCore\Contracts\SkillRenderer;

/**
 * No-op renderer claiming the `md` extension. Always present in the
 * dispatcher — the builder re-appends it after applying
 * `withDisabledRenderers(...)` so `.md` skills always render.
 *
 * A consumer wanting to override `.md` rendering (CommonMark normalization,
 * include-expansion, …) registers their own renderer claiming `md`; the
 * dispatcher honors registration order so the user-registered renderer
 * wins over this implicit default.
 */
final class PassthroughRenderer implements SkillRenderer
{
    /**
     * @return list<string>
     */
    #[Override]
    public function extensions(): array
    {
        return ['md'];
    }

    #[Override]
    public function render(string $raw, RenderContext $ctx): string
    {
        return $raw;
    }
}
