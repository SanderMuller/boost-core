<?php

declare(strict_types=1);

namespace SanderMuller\BoostCore\Skills;

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

            yield new Skill(
                name: $name,
                description: $description,
                frontmatter: $parsed->frontmatter,
                body: $parsed->body,
                sourcePath: $file->getRealPath() !== false ? $file->getRealPath() : $file->getPathname(),
                sourceVendor: $sourceVendor,
            );
        }
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
