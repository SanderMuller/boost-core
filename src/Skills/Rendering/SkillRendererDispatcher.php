<?php declare(strict_types=1);

namespace SanderMuller\BoostCore\Skills\Rendering;

use SanderMuller\BoostCore\Contracts\SkillRenderer;

/**
 * Registry + resolver for SkillRenderer plugins.
 *
 * Spec: internal/specs/skill-renderer-plugin.md §4.
 *
 * Resolution rules:
 *  - Walks a filename right-to-left, peeling extensions from longest to
 *    shortest, returning the first match. `SKILL.blade.php` tries
 *    `blade.php` first; only if no renderer claims it does it fall back to
 *    `php` — so a single-segment `.php` renderer never hijacks a
 *    multi-segment claim.
 *  - Within one extension, **first-registered wins**. The implicit
 *    `PassthroughRenderer` is appended LAST (by `BoostConfigBuilder` after
 *    applying the deny-list), so a user-registered `md`-claiming renderer
 *    overrides the default.
 *  - Filename comparison is lowercase: `SKILL.MD` resolves the same as
 *    `SKILL.md`. Extensions are normalized to lowercase at registration.
 *
 * Validation runs at construction:
 *  - Extension strings must match `/^[a-z0-9.]+$/` (lowercase letters,
 *    digits, dots — for multi-segment extensions). Empty or leading-dot
 *    entries reject the whole dispatcher.
 *  - Conflict detection is the BUILDER's job (`BoostConfigBuilder::build`),
 *    not the dispatcher's — the dispatcher accepts duplicate extension
 *    registrations and the first-registered-wins rule applies. This lets
 *    `withDisabledRenderers([...])` resolve conflicts by dropping one side
 *    before the dispatcher sees the list.
 */
final readonly class SkillRendererDispatcher
{
    /** @var list<array{ext: string, renderer: SkillRenderer}> */
    private array $entries;

    /**
     * @param  list<SkillRenderer>  $renderers
     */
    public function __construct(array $renderers)
    {
        $entries = [];
        foreach ($renderers as $renderer) {
            foreach ($renderer->extensions() as $ext) {
                if ($ext === '' || preg_match('/^[a-z0-9.]+$/', $ext) !== 1 || str_starts_with($ext, '.') || str_ends_with($ext, '.')) {
                    throw new InvalidSkillRendererException(sprintf(
                        '%s::extensions() returned an invalid entry `%s` — must match /^[a-z0-9.]+$/, no leading/trailing dot.',
                        $renderer::class,
                        $ext,
                    ));
                }

                $entries[] = ['ext' => $ext, 'renderer' => $renderer];
            }
        }

        $this->entries = $entries;
    }

    public function resolve(string $filename): ?MatchedRenderer
    {
        $lower = strtolower($filename);

        // Peel multi-segment extensions longest-first. For `SKILL.blade.php`,
        // candidates are `blade.php`, then `php`. Two passes find the
        // longest first since the entries themselves carry multi-segment
        // extension strings already.
        $candidates = [];
        for ($i = 0; $i < strlen($lower); ++$i) {
            if ($lower[$i] === '.' && $i + 1 < strlen($lower)) {
                $candidates[] = substr($lower, $i + 1);
            }
        }

        usort($candidates, static fn (string $a, string $b): int => strlen($b) <=> strlen($a));

        foreach ($candidates as $candidate) {
            foreach ($this->entries as $entry) {
                if ($entry['ext'] === $candidate) {
                    return new MatchedRenderer($entry['renderer'], $entry['ext']);
                }
            }
        }

        return null;
    }

    /**
     * Globs suitable for Symfony Finder's `->name([...])`, derived from the
     * registered extension set. `['md', 'blade.php']` → `['*.md', '*.blade.php']`.
     *
     * @return list<string>
     */
    public function fileGlobPatterns(): array
    {
        $globs = [];
        foreach ($this->entries as $entry) {
            $globs['*.' . $entry['ext']] = true;
        }

        return array_keys($globs);
    }
}
