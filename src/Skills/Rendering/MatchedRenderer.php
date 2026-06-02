<?php declare(strict_types=1);

namespace SanderMuller\BoostCore\Skills\Rendering;

use SanderMuller\BoostCore\Contracts\SkillRenderer;

/**
 * The dispatcher's resolve() result. Returns the matched renderer plus the
 * matched extension (lowercase, no leading dot) so callers — chiefly
 * SkillLoader — can strip the full extension from a filename. Without the
 * extension carried out of the match, `getFilenameWithoutExtension()`
 * strips only the last `.`-segment and `SKILL.blade.php` would resolve to
 * `SKILL.blade` instead of `SKILL`.
 *
 * @internal
 */
final readonly class MatchedRenderer
{
    public function __construct(
        public SkillRenderer $renderer,
        public string $extension,
    ) {}
}
