<?php declare(strict_types=1);

namespace SanderMuller\BoostCore\Skills;

use Symfony\Component\Finder\Finder;

/**
 * Loads `.ai/commands/*.md` into {@see Command} value objects.
 * Mirrors {@see GuidelineLoader}.
 */
final readonly class CommandLoader
{
    public function __construct(
        private FrontmatterParser $parser,
    ) {}

    /**
     * @return iterable<Command>
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

            [$tags, $tagsValid] = BoostTags::parse($parsed->frontmatter);
            $argumentDeclarations = $this->parseArgumentDeclarations($parsed->frontmatter);

            yield new Command(
                name: $name,
                description: $description,
                frontmatter: $parsed->frontmatter,
                body: $parsed->body,
                sourcePath: $file->getRealPath() !== false ? $file->getRealPath() : $file->getPathname(),
                sourceVendor: $sourceVendor,
                tags: $tags,
                tagsValid: $tagsValid,
                argumentDeclarations: $argumentDeclarations,
            );
        }
    }

    /**
     * Parse the optional `arguments:` frontmatter list into an ordered
     * list of declared argument names. Accepts:
     *  - YAML list: `arguments: [issue, branch]` → ['issue', 'branch']
     *  - Block form: `arguments:\n  - issue\n  - branch`
     *
     * Non-string entries and malformed shapes are silently dropped —
     * the transpiler is the bouncer for "declared args match body
     * placeholders," and a malformed declaration is treated identically
     * to no declaration.
     *
     * @param  array<string, mixed>  $frontmatter
     * @return list<string>
     */
    private function parseArgumentDeclarations(array $frontmatter): array
    {
        $declarations = $frontmatter['arguments'] ?? null;
        if (! is_array($declarations)) {
            return [];
        }

        $out = [];
        foreach ($declarations as $entry) {
            if (! is_string($entry)) {
                continue;
            }

            if ($entry === '') {
                continue;
            }

            $out[] = $entry;
        }

        return $out;
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
