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
 * Skip rules: hidden files (`.`-prefixed), non-`.md` extensions.
 */
final class SkillLoader
{
    public function __construct(
        private readonly FrontmatterParser $parser,
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
