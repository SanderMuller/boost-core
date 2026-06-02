<?php declare(strict_types=1);

namespace SanderMuller\BoostCore\Skills;

use SanderMuller\BoostCore\Env;
use SanderMuller\BoostCore\Skills\Rendering\MatchedRenderer;
use SanderMuller\BoostCore\Skills\Rendering\PassthroughRenderer;
use SanderMuller\BoostCore\Skills\Rendering\RenderContext;
use SanderMuller\BoostCore\Skills\Rendering\SkillRendererDispatcher;
use SanderMuller\BoostCore\Skills\Rendering\SkillRenderException;
use SplFileInfo;
use Symfony\Component\Finder\Finder;
use Throwable;

/**
 * Loads Guidelines from a directory of guideline files.
 *
 * Mirrors `SkillLoader`'s shape: file extension handling is driven by the
 * SkillRendererDispatcher (renderers are reused across skills and
 * guidelines — same plugin pool). `.md` files always load via the implicit
 * PassthroughRenderer. Files matching a registered renderer's extension
 * (e.g. `.blade.php` with a downstream `BladeRenderer`) render through
 * that renderer first, then the rendered output is frontmatter-parsed.
 * Files whose extension no registered renderer claims are skipped — and the
 * skip is surfaced via the `$warnings` out-param so it is never silent (a host
 * `.blade.php` with no `BladeRenderer` registered, e.g. under bare-CLI, would
 * otherwise vanish from agents with no signal).
 *
 * Tag resolution: frontmatter `metadata.boost-tags` wins; otherwise the
 * sidecar `.boost-tags.yaml` manifest fills in (the only tag source for
 * frontmatter-free, laravel/boost-safe guidelines).
 *
 * Errors raised by a renderer follow `BOOST_RENDER_STRICT`: lenient
 * (default) appends to the errors-out param and skips the file; strict
 * throws `SkillRenderException` to abort.
 *
 * @internal
 */
final readonly class GuidelineLoader
{
    public function __construct(
        private FrontmatterParser $parser,
    ) {}

    /**
     * @param  list<string>  $errors  Out-parameter: render failures (lenient mode) accumulate here.
     * @param  list<string>  $warnings  Out-parameter: a file skipped because no
     *   registered renderer claims its extension (silent-capability-loss guard).
     * @return iterable<Guideline>
     */
    public function load(string $directory, ?string $sourceVendor = null, ?SkillRendererDispatcher $renderers = null, array &$errors = [], array &$warnings = []): iterable
    {
        if (! is_dir($directory)) {
            return;
        }

        $dispatcher = $renderers ?? new SkillRendererDispatcher([new PassthroughRenderer()]);
        $strict = Env::flagEnabled(Env::RENDER_STRICT);
        $manifest = GuidelineTagManifest::load($directory);

        $warnings = [...$warnings, ...(new UnrenderableSourceScanner())->guidelineSkips($directory, $dispatcher)];

        $finder = (new Finder())
            ->files()
            ->in($directory)
            ->name($dispatcher->fileGlobPatterns())
            ->ignoreDotFiles(true)
            ->sortByName();

        foreach ($finder as $file) {
            $matched = $dispatcher->resolve($file->getFilename());
            if (! $matched instanceof MatchedRenderer) {
                continue;
            }

            $raw = $file->getContents();
            $preParsed = $this->parser->parse($raw);
            $ctx = new RenderContext(
                sourcePath: $this->resolvedPath($file),
                sourceVendor: $sourceVendor,
                frontmatter: $preParsed->frontmatter,
            );

            try {
                $rendered = $matched->renderer->render($raw, $ctx);
            } catch (Throwable $e) {
                $message = sprintf(
                    'guideline render failed (`%s`, renderer `%s`): %s',
                    $file->getRelativePathname(),
                    $matched->renderer::class,
                    $e->getMessage(),
                );
                if ($strict) {
                    throw new SkillRenderException($message, previous: $e);
                }

                $errors[] = $message;

                continue;
            }

            $parsed = $this->parser->parse($rendered);

            $name = $this->resolveName(
                $parsed->frontmatter,
                $this->stripMatchedExtension($file->getFilename(), $matched->extension),
            );

            $description = is_string($parsed->frontmatter['description'] ?? null)
                ? $parsed->frontmatter['description']
                : null;

            // Frontmatter `metadata.boost-tags` wins; a guideline that
            // declares no tags inline falls back to the `.boost-tags.yaml`
            // sidecar manifest — the tag source for frontmatter-free
            // (laravel/boost-safe) guidelines.
            [$tags, $tagsValid] = BoostTags::declaresTags($parsed->frontmatter)
                ? BoostTags::parse($parsed->frontmatter)
                : $manifest->tagsFor($file->getFilename());

            yield new Guideline(
                name: $name,
                description: $description,
                frontmatter: $parsed->frontmatter,
                body: $parsed->body,
                sourcePath: $this->resolvedPath($file),
                sourceVendor: $sourceVendor,
                tags: $tags,
                tagsValid: $tagsValid,
            );
        }
    }

    private function resolvedPath(SplFileInfo $file): string
    {
        $real = $file->getRealPath();

        return $real !== false ? $real : $file->getPathname();
    }

    /**
     * @param  array<string, mixed>  $frontmatter
     */
    private function resolveName(array $frontmatter, string $filenameFallback): string
    {
        $fromFrontmatter = $frontmatter['name'] ?? null;
        if (is_string($fromFrontmatter) && $fromFrontmatter !== '') {
            return $fromFrontmatter;
        }

        return $filenameFallback;
    }

    /**
     * `core.blade.php` + matched extension `blade.php` → `core`. Falls
     * through to last-segment behavior if the suffix does not match.
     */
    private function stripMatchedExtension(string $filename, string $matchedExtension): string
    {
        $suffix = '.' . $matchedExtension;
        if (str_ends_with(strtolower($filename), strtolower($suffix))) {
            return substr($filename, 0, -strlen($suffix));
        }

        $lastDot = strrpos($filename, '.');

        return $lastDot === false ? $filename : substr($filename, 0, $lastDot);
    }
}
