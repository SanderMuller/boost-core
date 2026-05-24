<?php declare(strict_types=1);

namespace SanderMuller\BoostCore\Skills\Remote;

use SanderMuller\BoostCore\Env;
use SanderMuller\BoostCore\Skills\BoostTags;
use SanderMuller\BoostCore\Skills\FrontmatterParser;
use SanderMuller\BoostCore\Skills\Rendering\MatchedRenderer;
use SanderMuller\BoostCore\Skills\Rendering\PassthroughRenderer;
use SanderMuller\BoostCore\Skills\Rendering\RenderContext;
use SanderMuller\BoostCore\Skills\Rendering\SkillRendererDispatcher;
use SanderMuller\BoostCore\Skills\Rendering\SkillRenderException;
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
                $this->absorbOutcome(
                    $this->loadOne($source, $cached, $ref, $dispatcher),
                    $source,
                    $skillsByVendor,
                    $errors,
                );
            }
        }

        return ['skills' => $skillsByVendor, 'errors' => $errors];
    }

    /**
     * Absorb one loadOne outcome into the in-flight vendor map. Skill
     * goes through same-vendor-name collision detection (two
     * `withRemoteSkills` entries for the same repo at different versions
     * silently first-winning is the bug class — surfaced explicitly).
     * Error string is recorded; strict mode escalates.
     *
     * @param  array<string, list<Skill>>  $skillsByVendor  in-place
     * @param  list<string>  $errors  in-place
     */
    private function absorbOutcome(Skill|string $outcome, RemoteSkillSource $source, array &$skillsByVendor, array &$errors): void
    {
        if (! ($outcome instanceof Skill)) {
            $errors[] = $outcome;
            if ($this->strict) {
                throw new RemoteFetchException($outcome, RemoteFetchException::MALFORMED_RESPONSE);
            }

            return;
        }

        foreach ($skillsByVendor[$source->source] as $existing) {
            if ($existing->name === $outcome->name) {
                $message = sprintf(
                    'remote skill `%s:%s`: collides with an earlier remote declaration of the same skill name under the same source — likely two `withRemoteSkills` entries for `%s` at different versions both listing `%s`. Pick one version, or rename one of the skills.',
                    $source->source,
                    $outcome->name,
                    $source->source,
                    $outcome->name,
                );
                if ($this->strict) {
                    throw new RemoteFetchException($message, RemoteFetchException::MALFORMED_RESPONSE);
                }
                $errors[] = $message;

                return;
            }
        }

        $skillsByVendor[$source->source][] = $outcome;
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
            $message = sprintf(
                'remote skill `%s:%s`: render failed: %s',
                $source->source,
                $ref->name,
                $throwable->getMessage(),
            );

            // BOOST_RENDER_STRICT escalates here, mirroring SkillLoader's
            // strict path. BOOST_REMOTE_STRICT is separately checked by
            // the outer ingest() loop and triggers a different exception
            // (RemoteFetchException) — render strictness must escalate
            // FROM the render site so the right env var controls the
            // right failure class.
            if (Env::flagEnabled(Env::RENDER_STRICT)) {
                throw new SkillRenderException($message, previous: $throwable);
            }

            return $message;
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
     * Locate the skill file inside a cache slot.
     *
     * Two-pass scan across ALL globs the dispatcher claims:
     *  1. First pass: any EXACT canonical `SKILL.<ext>` (case-insensitive)
     *     wins regardless of which glob iterated first. This prevents a
     *     non-canonical sibling matched by an earlier glob (e.g.
     *     `README.md` under the `*.md` glob) from beating the canonical
     *     `SKILL.blade.php` under a later glob.
     *  2. Second pass (only if no canonical found): the first
     *     non-canonical match in glob-iteration order. Helper variants
     *     like `skill.backup.md` are tolerated but never preferred.
     *
     * Returns null if no claimed extension matches. Hidden files skipped.
     */
    private function locateSkillFile(string $cacheSlot, SkillRendererDispatcher $dispatcher): ?string
    {
        $globs = $dispatcher->fileGlobPatterns();

        // Pass 1: canonical SKILL.<ext> across all globs.
        foreach ($globs as $glob) {
            $canonicalBasename = $this->canonicalBasenameFromGlob($glob);
            foreach ($this->matchingFiles($cacheSlot, $glob) as $match) {
                if (strcasecmp(basename($match), $canonicalBasename) === 0) {
                    return $match;
                }
            }
        }

        // Pass 2: first non-canonical match in glob-iteration order.
        foreach ($globs as $glob) {
            foreach ($this->matchingFiles($cacheSlot, $glob) as $match) {
                return $match;
            }
        }

        return null;
    }

    /**
     * Globs are `*.<ext>` per SkillRendererDispatcher::fileGlobPatterns.
     * Strip the `*.` prefix to recover the canonical `SKILL.<ext>`.
     */
    private function canonicalBasenameFromGlob(string $glob): string
    {
        return 'SKILL.' . (str_starts_with($glob, '*.') ? substr($glob, 2) : $glob);
    }

    /**
     * @return iterable<string>
     */
    private function matchingFiles(string $cacheSlot, string $glob): iterable
    {
        $matches = glob($cacheSlot . '/' . $glob);
        if ($matches === false) {
            return;
        }
        foreach ($matches as $match) {
            if (! is_file($match)) {
                continue;
            }
            if (str_starts_with(basename($match), '.')) {
                continue;
            }

            yield $match;
        }
    }
}
