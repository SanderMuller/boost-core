<?php declare(strict_types=1);

namespace SanderMuller\BoostCore\Skills;

use SanderMuller\BoostCore\Env;
use SanderMuller\BoostCore\Skills\Rendering\MatchedRenderer;
use SanderMuller\BoostCore\Skills\Rendering\PassthroughRenderer;
use SanderMuller\BoostCore\Skills\Rendering\RenderContext;
use SanderMuller\BoostCore\Skills\Rendering\SkillRendererDispatcher;
use SanderMuller\BoostCore\Skills\Rendering\SkillRenderException;
use Symfony\Component\Finder\Finder;
use Symfony\Component\Finder\SplFileInfo;
use Throwable;

/**
 * Loads Skills from a directory of skill files.
 *
 * File extension handling is driven by the {@see SkillRendererDispatcher}:
 *  - `.md` files are always loaded (the implicit {@see PassthroughRenderer}
 *    claims it; raw-content passthrough by default).
 *  - Files matching a registered renderer's extension are rendered through
 *    that renderer first, then the rendered output is frontmatter-parsed
 *    and turned into a {@see Skill}. A `BladeRenderer` registered for
 *    `blade.php` lets `SKILL.blade.php` files load with their template
 *    output as the skill body.
 *  - Files whose extension no registered renderer claims are silently
 *    skipped — boost-core's default registry is passthrough-only.
 *
 * Hidden files (`.`-prefixed) are skipped.
 *
 * Name resolution:
 *  1. Post-render frontmatter `name:` wins when present.
 *  2. Filename fallback strips the MATCHED RENDERER EXTENSION (not just the
 *     last `.`-segment) — so `SKILL.blade.php` becomes `SKILL`, not
 *     `SKILL.blade`. The matched extension is carried out of the dispatcher
 *     via {@see MatchedRenderer}
 *     so multi-segment extensions work.
 *
 * Errors raised by a renderer are NOT caught here. Caller (`SyncEngine`)
 * applies the `BOOST_RENDER_STRICT` policy.
 *
 * @internal
 */
final readonly class SkillLoader
{
    public function __construct(
        private FrontmatterParser $parser,
    ) {}

    /**
     * @param  list<string>  $errors  Out-parameter: render failures (lenient mode) accumulate here.
     * @param  list<string>  $warnings  Out-parameter: a SKILL.* file skipped because
     *   no registered renderer claims its extension (silent-capability-loss guard).
     * @return iterable<Skill>
     */
    public function load(string $directory, ?string $sourceVendor = null, ?SkillRendererDispatcher $renderers = null, array &$errors = [], array &$warnings = [], ?string $projectRoot = null): iterable
    {
        if (! is_dir($directory)) {
            return;
        }

        $dispatcher = $renderers ?? new SkillRendererDispatcher([new PassthroughRenderer()]);
        $strict = Env::flagEnabled(Env::RENDER_STRICT);

        $warnings = [...$warnings, ...array_map(
            static fn (UnrenderableSource $source): string => $source->message,
            (new UnrenderableSourceScanner())->skillSkips($directory, $dispatcher),
        )];

        $finder = (new Finder())
            ->files()
            ->in($directory)
            ->name($dispatcher->fileGlobPatterns())
            // Top-level `*.<ext>` OR depth-1 `*/SKILL.*` only — never descend
            // into a skill's `references/`/`examples/` (which would otherwise
            // ship nested asset files as phantom top-level skills).
            ->filter(static fn (SplFileInfo $file): bool => SkillSourceScope::isSkillSource($file))
            ->ignoreDotFiles(true)
            ->sortByName();

        foreach ($finder as $file) {
            $matched = $dispatcher->resolve($file->getFilename());
            if (! $matched instanceof MatchedRenderer) {
                // Defensive: the Finder pattern is derived from the same
                // registry, so this should not happen — but skip rather
                // than crash if it ever does.
                continue;
            }

            $raw = $file->getContents();

            // Pre-render frontmatter so we can hand the renderer enough
            // context. The renderer's output is re-parsed below — most
            // templates leave the YAML head untouched and the second parse
            // sees the same frontmatter, but a renderer that regenerates
            // the head is free to.
            $preParsed = $this->parser->parse($raw);
            $ctx = new RenderContext(
                sourcePath: $this->resolvedPath($file),
                sourceVendor: $sourceVendor,
                frontmatter: $preParsed->frontmatter,
                projectRoot: $projectRoot,
            );

            try {
                $rendered = $matched->renderer->render($raw, $ctx);
            } catch (Throwable $e) {
                $message = sprintf(
                    'skill render failed (`%s`, renderer `%s`): %s',
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

            [$tags, $tagsValid] = BoostTags::parse($parsed->frontmatter);

            yield new Skill(
                name: $name,
                description: $description,
                frontmatter: $parsed->frontmatter,
                body: $parsed->body,
                sourcePath: $this->resolvedPath($file),
                sourceVendor: $sourceVendor,
                tags: $tags,
                tagsValid: $tagsValid,
                assets: SkillAssetCollector::collect($this->resolvedPath($file)),
            );
        }
    }

    /**
     * Resolve to a real-path string, falling back to the unresolved
     * pathname if PHP's `realpath()` returns false (broken symlink,
     * open_basedir restriction, race where the file disappears between
     * Finder enumeration and getRealPath call). Symfony Finder's
     * SplFileInfo narrows the return type to `string` via PHPDoc which
     * lets phpstan see the `!== false` check as always-true; the
     * runtime branch is still meaningful for the genuine false case,
     * so we suppress the static-analyzer hint by computing once.
     */
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
     * `SKILL.blade.php` + matched extension `blade.php` → `SKILL`. Falls
     * through to `getFilenameWithoutExtension`'s last-segment behavior if
     * the suffix does not match (defensive — should not happen since the
     * dispatcher derived the extension from the same filename).
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
