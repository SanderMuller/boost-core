<?php declare(strict_types=1);

namespace SanderMuller\BoostCore\Skills;

use Symfony\Component\Finder\Finder;

/**
 * Loads flat `<name>.md` subagent definitions into {@see Subagent} value
 * objects. Mirrors {@see CommandLoader}, with one deliberate difference.
 *
 * **A file that declares no `name` is SKIPPED, not renamed after its file.**
 * Commands and skills fall back to the filename stem; subagents must not,
 * because Claude Code itself will not load such a file: "No `name`: Claude Code
 * treats the file as documentation kept beside your agents." Emitting one would
 * ship a file the target ignores, and counting one would report collisions that
 * cannot happen. The skipped paths come back as warnings so the operator learns
 * why their file never appeared.
 *
 * One file, one subagent: no directory form, no asset siblings. Subdirectories
 * ARE walked, because identity is the frontmatter `name` and not the path, so a
 * source tree may be organised however its author likes.
 *
 * @internal
 */
final readonly class SubagentLoader
{
    public function __construct(
        private FrontmatterParser $parser,
    ) {}

    /**
     * @return array{subagents: list<Subagent>, warnings: list<string>}
     */
    public function load(string $directory, ?string $sourceVendor = null): array
    {
        if (! is_dir($directory)) {
            return ['subagents' => [], 'warnings' => []];
        }

        /** @var list<Subagent> $subagents */
        $subagents = [];
        /** @var list<string> $warnings */
        $warnings = [];

        $finder = (new Finder())
            ->files()
            ->in($directory)
            ->name('*.md')
            ->ignoreDotFiles(true)
            ->sortByName();

        foreach ($finder as $file) {
            $parsed = $this->parser->parse($file->getContents());

            $name = $parsed->frontmatter['name'] ?? null;
            if (! is_string($name) || trim($name) === '') {
                $warnings[] = sprintf(
                    'subagent source `%s` declares no `name` in its frontmatter and was skipped — Claude Code ignores such a file too.',
                    $file->getRelativePathname(),
                );

                continue;
            }

            $description = is_string($parsed->frontmatter['description'] ?? null)
                ? $parsed->frontmatter['description']
                : null;

            [$tags, $tagsValid] = BoostTags::parse($parsed->frontmatter);

            $subagents[] = new Subagent(
                name: trim($name),
                description: $description,
                frontmatter: $parsed->frontmatter,
                body: $parsed->body,
                sourcePath: $file->getRealPath() !== false ? $file->getRealPath() : $file->getPathname(),
                sourceVendor: $sourceVendor,
                tags: $tags,
                tagsValid: $tagsValid,
            );
        }

        return ['subagents' => $subagents, 'warnings' => $warnings];
    }
}
