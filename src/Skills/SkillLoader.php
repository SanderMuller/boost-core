<?php declare(strict_types=1);

namespace SanderMuller\BoostCore\Skills;

use SanderMuller\BoostCore\Enums\Tag;
use Symfony\Component\Finder\Finder;

/**
 * Loads Skills from a directory of markdown files.
 *
 * Skill name derives from the filename (without `.md` extension). Frontmatter
 * may override via `name:` key — if present and non-empty, that wins.
 *
 * Skip rules:
 *  - Hidden files (`.`-prefixed) are skipped.
 *  - Non-`.md` extensions are skipped.
 *  - **Blade-template skills** (`*.blade.php`, e.g. `SKILL.blade.php`) are
 *    silently skipped — they need Laravel app context to render, which
 *    boost-core (framework-agnostic) cannot provide. `laravel/mcp`'s
 *    `mcp-development` skill is the canonical example.
 *
 * Important: a vendor that publishes **only** Blade-template skills will
 * contribute zero loaded skills from boost-core's perspective, with no
 * diagnostic. That's intentional (boost-core does not warn about its own
 * inability to render Blade), but vendors wanting their skills to
 * propagate through boost-core's per-agent fan-out must pre-render to
 * `SKILL.md` before shipping. Whether such skills reach the host app via
 * a different integration path (e.g. the vendor's own Laravel ServiceProvider
 * rendering on boot) is the vendor's responsibility, not boost-core's.
 */
final readonly class SkillLoader
{
    public function __construct(
        private FrontmatterParser $parser,
    ) {}

    /**
     * @return iterable<Skill>
     */
    public function load(string $directory, ?string $sourceVendor = null): iterable
    {
        if (! is_dir($directory)) {
            return;
        }

        $finder = (new Finder())
            ->files()
            ->in($directory)
            ->name('*.md')
            ->ignoreDotFiles(true)
            ->sortByName();

        foreach ($finder as $file) {
            $content = $file->getContents();
            $parsed = $this->parser->parse($content);

            $name = $this->resolveName($parsed->frontmatter, $file->getFilenameWithoutExtension());

            $description = is_string($parsed->frontmatter['description'] ?? null)
                ? $parsed->frontmatter['description']
                : null;

            [$tags, $tagsValid] = $this->parseTags($parsed->frontmatter);

            yield new Skill(
                name: $name,
                description: $description,
                frontmatter: $parsed->frontmatter,
                body: $parsed->body,
                sourcePath: $file->getRealPath() !== false ? $file->getRealPath() : $file->getPathname(),
                sourceVendor: $sourceVendor,
                tags: $tags,
                tagsValid: $tagsValid,
            );
        }
    }

    /**
     * Extract conditional-filtering tags from a skill's frontmatter.
     *
     * Tags live under the Agent Skills standard's sanctioned extension
     * point — the optional `metadata` string→string map — as a single
     * space-delimited `boost-tags` value (the namespaced-key recommendation;
     * mirrors the standard's own space-separated `allowed-tools`):
     *
     *     metadata:
     *       boost-tags: "php jira"
     *
     * Fails closed: when `boost-tags` is present but not a string, the skill
     * is marked tag-invalid (`valid` = false) and ships nowhere — a typo
     * must not silently leave the skill untagged (= ships everywhere) and
     * leak a scoped skill. A missing `metadata`, a `metadata` that is not a
     * map, or an absent `boost-tags` key is untagged-valid.
     *
     * @param  array<string, mixed>  $frontmatter
     * @return array{0: list<string>, 1: bool}  [normalized tags, valid]
     */
    private function parseTags(array $frontmatter): array
    {
        $metadata = $frontmatter['metadata'] ?? null;
        if (! is_array($metadata) || ! array_key_exists('boost-tags', $metadata)) {
            return [[], true];
        }

        $raw = $metadata['boost-tags'];
        if (! is_string($raw)) {
            return [[], false];
        }

        $tokens = preg_split('/\s+/', trim($raw), -1, PREG_SPLIT_NO_EMPTY);

        $tags = [];
        foreach ($tokens === false ? [] : $tokens as $token) {
            $normalized = Tag::normalize($token);
            if ($normalized !== '') {
                $tags[] = $normalized;
            }
        }

        return [array_values(array_unique($tags)), true];
    }

    /**
     * @param  array<string, mixed>  $frontmatter
     */
    private function resolveName(array $frontmatter, string $filename): string
    {
        $fromFrontmatter = $frontmatter['name'] ?? null;
        if (is_string($fromFrontmatter) && $fromFrontmatter !== '') {
            return $fromFrontmatter;
        }

        return $filename;
    }
}
