<?php declare(strict_types=1);

namespace SanderMuller\BoostCore\Skills;

/**
 * A companion file shipped alongside a nested skill's `SKILL.*` entry file —
 * a `scripts/run.mjs`, a `references/api.md`, an `examples/` fixture. Emitted
 * verbatim next to the rendered `SKILL.md` in every agent target's skill
 * directory, so a skill body can reference its assets by relative path and
 * find them at runtime.
 *
 * @api Stable as of 1.3 — the value type a wrapper package constructs to
 * attach assets to an injected {@see Skill}. Both constructor properties are
 * frozen; new ones, if ever added, append with a default (non-breaking).
 */
final readonly class SkillAsset
{
    /**
     * @param  string  $relativePath  Path relative to the skill directory, `/`-separated (e.g. `scripts/run.mjs`). Never absolute, never containing `..`.
     * @param  string  $contents  Raw file contents (bytes — binary-safe).
     */
    public function __construct(
        public string $relativePath,
        public string $contents,
    ) {}
}
