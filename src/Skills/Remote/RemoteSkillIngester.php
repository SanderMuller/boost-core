<?php declare(strict_types=1);

namespace SanderMuller\BoostCore\Skills\Remote;

use SanderMuller\BoostCore\Skills\BoostTags;
use SanderMuller\BoostCore\Skills\FrontmatterParser;
use SanderMuller\BoostCore\Skills\Rendering\MatchedRenderer;
use SanderMuller\BoostCore\Skills\Rendering\PassthroughRenderer;
use SanderMuller\BoostCore\Skills\Rendering\RenderContext;
use SanderMuller\BoostCore\Skills\Rendering\SkillRendererDispatcher;
use SanderMuller\BoostCore\Skills\Skill;
use Throwable;

/**
 * Resolves `withRemoteSkills(...)` declarations into loaded {@see Skill}s
 * ready for the rest of the sync pipeline.
 *
 * Per-source try/catch isolates failures — one bad source does not abort
 * the others. When `BOOST_REMOTE_STRICT=1` (or the `$strict` constructor
 * flag) is set, the first failure re-throws and aborts the whole sync.
 *
 * After each skill loads, its `SKILL.md` frontmatter `name` is checked
 * against the declared `RemoteSkillRef::name`. Mismatch → recorded as an
 * error; the mismatched skill does not enter the pipeline; sibling skills
 * proceed.
 *
 * Spec: `internal/specs/remote-skill-sources.md` §3 (name-match rule),
 * §5 (resolve pipeline), §11 (failure isolation), Phase 5.
 */
final readonly class RemoteSkillIngester
{
    public function __construct(
        private RemoteSkillCache $cache,
        private FrontmatterParser $frontmatterParser = new FrontmatterParser(),
        private bool $strict = false,
    ) {}

    /**
     * @param  list<RemoteSkillSource>  $sources
     * @param  SkillRendererDispatcher|null  $renderers  Per-sync dispatcher built from `BoostConfig::skillRenderers`. Caller passes null in user-scope sync (no project config). Default = passthrough-only, matching pre-renderer boost-core behavior. See spec §5.1 (lifecycle constraint).
     * @return array{skills: array<string, list<Skill>>, errors: list<string>}
     */
    public function ingest(array $sources, ?SkillRendererDispatcher $renderers = null): array
    {
        $dispatcher = $renderers ?? new SkillRendererDispatcher([new PassthroughRenderer()]);

        /** @var array<string, list<Skill>> $skillsByVendor */
        $skillsByVendor = [];
        /** @var list<string> $errors */
        $errors = [];

        foreach ($sources as $source) {
            try {
                $cached = $this->cache->ensureCached($source);
            } catch (Throwable $e) {
                $message = sprintf('remote source `%s@%s`: %s', $source->source, $source->version, $e->getMessage());
                if ($this->strict) {
                    throw $e;
                }

                $errors[] = $message;

                continue;
            }

            $skillsByVendor[$source->source] ??= [];

            foreach ($source->skills as $ref) {
                $outcome = $this->loadOne($source, $cached, $ref, $dispatcher);
                if ($outcome instanceof Skill) {
                    $skillsByVendor[$source->source][] = $outcome;
                } else {
                    $errors[] = $outcome;
                    if ($this->strict) {
                        throw new RemoteFetchException($outcome, RemoteFetchException::MALFORMED_RESPONSE);
                    }
                }
            }
        }

        return ['skills' => $skillsByVendor, 'errors' => $errors];
    }

    /**
     * Load one remote skill from its cache slot. Returns a {@see Skill} on
     * success, or an error message string on failure (missing SKILL.* file,
     * name mismatch, render failure).
     *
     * File discovery uses the dispatcher's registered extensions, falling
     * back to `SKILL.md` for backward compat. Renderer exceptions are
     * caught and converted to error strings here so the outer `ingest()`
     * loop honors `BOOST_RENDER_STRICT` analogously to `BOOST_REMOTE_STRICT`
     * — see spec §7.2.
     */
    private function loadOne(RemoteSkillSource $source, CachedSource $cached, RemoteSkillRef $ref, SkillRendererDispatcher $dispatcher): Skill|string
    {
        $skillPath = $this->locateSkillFile($cached->skillPath($ref), $dispatcher);
        if ($skillPath === null) {
            return sprintf(
                'remote skill `%s:%s`: no SKILL.* file in cached slot (`%s`).',
                $source->source,
                $ref->name,
                $cached->skillPath($ref),
            );
        }

        $matched = $dispatcher->resolve(basename($skillPath));
        if (! $matched instanceof MatchedRenderer) {
            // Defensive — locateSkillFile only returns paths the dispatcher claims.
            return sprintf(
                'remote skill `%s:%s`: no renderer claims `%s`.',
                $source->source,
                $ref->name,
                basename($skillPath),
            );
        }

        $raw = (string) file_get_contents($skillPath);
        $preParsed = $this->frontmatterParser->parse($raw);

        try {
            $content = $matched->renderer->render($raw, new RenderContext(
                sourcePath: $skillPath,
                sourceVendor: $source->source,
                frontmatter: $preParsed->frontmatter,
            ));
        } catch (Throwable $throwable) {
            return sprintf(
                'remote skill `%s:%s`: render failed: %s',
                $source->source,
                $ref->name,
                $throwable->getMessage(),
            );
        }

        $parsed = $this->frontmatterParser->parse($content);

        $frontmatterName = $parsed->frontmatter['name'] ?? null;
        $effectiveName = is_string($frontmatterName) && $frontmatterName !== ''
            ? $frontmatterName
            : $ref->name;

        if ($effectiveName !== $ref->name) {
            return sprintf(
                'remote skill `%s:%s`: SKILL.md frontmatter `name: %s` does not match declared `RemoteSkillRef` name.',
                $source->source,
                $ref->name,
                $effectiveName,
            );
        }

        $description = is_string($parsed->frontmatter['description'] ?? null)
            ? $parsed->frontmatter['description']
            : null;

        [$tags, $tagsValid] = BoostTags::parse($parsed->frontmatter);

        return new Skill(
            name: $ref->name,
            description: $description,
            frontmatter: $parsed->frontmatter,
            body: $parsed->body,
            sourcePath: $skillPath,
            sourceVendor: $source->source,
            tags: $tags,
            tagsValid: $tagsValid,
        );
    }

    /**
     * Locate the skill file inside a cache slot. Tries each glob the
     * dispatcher claims; first match wins. Returns null if no claimed
     * extension matches a file in the slot. Excludes hidden files.
     */
    private function locateSkillFile(string $cacheSlot, SkillRendererDispatcher $dispatcher): ?string
    {
        foreach ($dispatcher->fileGlobPatterns() as $glob) {
            $matches = glob($cacheSlot . '/' . $glob);
            if ($matches === false) {
                continue;
            }
            foreach ($matches as $match) {
                if (! is_file($match)) {
                    continue;
                }

                if (str_starts_with(basename($match), '.')) {
                    continue;
                }

                return $match;
            }
        }

        return null;
    }
}
