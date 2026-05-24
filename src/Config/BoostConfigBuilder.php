<?php declare(strict_types=1);

namespace SanderMuller\BoostCore\Config;

use InvalidArgumentException;
use SanderMuller\BoostCore\Contracts\SkillRenderer;
use SanderMuller\BoostCore\Enums\Agent;
use SanderMuller\BoostCore\Enums\Tag;
use SanderMuller\BoostCore\Skills\Remote\RemoteSkillSource;
use SanderMuller\BoostCore\Skills\Rendering\InvalidSkillRendererException;
use SanderMuller\BoostCore\Skills\Rendering\PassthroughRenderer;

/**
 * Fluent builder used in `boost.php`. Mutable — call chain accumulates state.
 *
 * BoostConfigLoader receives the builder from `require boost.php` and calls
 * `build($projectRoot)` to resolve defaults and produce an immutable BoostConfig.
 */
final class BoostConfigBuilder
{
    /** @var list<Agent> */
    private array $agents = [];

    /** @var list<string> */
    private array $allowedVendors = [];

    private ?string $skillsPath = null;

    private ?string $guidelinesPath = null;

    private ?string $commandsPath = null;

    /** @var list<string> */
    private array $disabledEmitters = [];

    private bool $manageGitignore = true;

    /** @var list<string> */
    private array $tags = [];

    /** @var list<string> */
    private array $excludedSkills = [];

    /** @var list<string> */
    private array $excludedGuidelines = [];

    /** @var list<RemoteSkillSource> */
    private array $remoteSkills = [];

    /** @var list<SkillRenderer> */
    private array $skillRenderers = [];

    /** @var list<string> */
    private array $disabledRenderers = [];

    /**
     * @param  list<Agent>  $agents
     */
    public function withAgents(array $agents): self
    {
        $this->agents = array_values($agents);

        return $this;
    }

    /**
     * @param  list<string>  $vendors  Composer vendor/package names (e.g. "doctrine/orm")
     */
    public function withAllowedVendors(array $vendors): self
    {
        $this->allowedVendors = array_values($vendors);

        return $this;
    }

    public function withSkillsPath(string $path): self
    {
        $this->skillsPath = $path;

        return $this;
    }

    public function withGuidelinesPath(string $path): self
    {
        $this->guidelinesPath = $path;

        return $this;
    }

    public function withCommandsPath(string $path): self
    {
        $this->commandsPath = $path;

        return $this;
    }

    /**
     * @param  list<string>  $fqcns  Fully-qualified emitter class names to skip during sync
     */
    public function withDisabledEmitters(array $fqcns): self
    {
        $this->disabledEmitters = array_values($fqcns);

        return $this;
    }

    /**
     * Control whether boost maintains a managed block in `.gitignore`. On by
     * default — generated agent files are ignored, edited only via `.ai/`.
     */
    public function withGitignoreManagement(bool $manage = true): self
    {
        $this->manageGitignore = $manage;

        return $this;
    }

    /**
     * Declare the project's skill tags. A vendor skill ships only when every
     * tag in its `metadata.boost-tags` is declared here. Accepts {@see Tag}
     * enum cases (autocomplete) and/or raw strings (the vocabulary is open).
     */
    public function withTags(Tag|string ...$tags): self
    {
        $normalized = array_map(
            static fn (Tag|string $tag): string => Tag::normalize($tag instanceof Tag ? $tag->value : $tag),
            $tags,
        );

        $this->tags = array_values(array_unique(array_filter(
            $normalized,
            static fn (string $tag): bool => $tag !== '',
        )));

        return $this;
    }

    /**
     * Exclude specific vendor skills regardless of tags. Each entry is a
     * `vendor/package:skill-name` string.
     *
     * @param  list<string>  $skills
     */
    public function withExcludedSkills(array $skills): self
    {
        $this->excludedSkills = array_values($skills);

        return $this;
    }

    /**
     * Exclude specific vendor guidelines regardless of tags. Each entry is a
     * `vendor/package:guideline-name` string. The guideline counterpart of
     * {@see withExcludedSkills()} — and the only lever for a vendor guideline
     * that ships without `metadata.boost-tags` (e.g. a frontmatter-free
     * guideline a `laravel/boost`-compatible package publishes), since
     * untagged guidelines pass tag-filtering trivially.
     *
     * @param  list<string>  $guidelines
     */
    public function withExcludedGuidelines(array $guidelines): self
    {
        $this->excludedGuidelines = array_values($guidelines);

        return $this;
    }

    /**
     * Declare non-Composer skill sources — GitHub repos shipping `.skill` ZIP
     * release bundles (`RemoteSkillSource::githubBundle`) or cherry-picked
     * subdirectories (`RemoteSkillSource::githubPath`). Overwrites any prior
     * list; rejects `(source, version, mode)` duplicates across the list.
     *
     * @param  list<RemoteSkillSource>  $sources
     */
    public function withRemoteSkills(array $sources): self
    {
        $seen = [];
        foreach ($sources as $source) {
            $key = $source->uniqueKey();
            if (isset($seen[$key])) {
                throw new InvalidArgumentException(sprintf(
                    'withRemoteSkills: duplicate RemoteSkillSource `%s` — list one entry per (source, version, mode).',
                    $key,
                ));
            }

            $seen[$key] = true;
        }

        $this->remoteSkills = array_values($sources);

        return $this;
    }

    /**
     * Register template renderers for non-`.md` skill files (Blade, Twig, …).
     * Multiple calls accumulate. The implicit {@see PassthroughRenderer} is
     * always appended last so `.md` is always handled — a user-registered
     * renderer claiming `md` still wins (dispatcher uses first-match-wins).
     *
     * @param  list<SkillRenderer>  $renderers
     */
    public function withSkillRenderers(array $renderers): self
    {
        foreach ($renderers as $renderer) {
            $this->skillRenderers[] = $renderer;
        }

        return $this;
    }

    /**
     * Drop specific renderers by FQCN. Applied AFTER `withSkillRenderers`
     * resolution but BEFORE conflict detection, so a deny entry can resolve
     * a duplicate-extension conflict by removing one side. Listing
     * `PassthroughRenderer::class` is silently no-op'd — the builder
     * re-appends it so `.md` always renders.
     *
     * @param  list<string>  $fqcns
     */
    public function withDisabledRenderers(array $fqcns): self
    {
        $this->disabledRenderers = array_values($fqcns);

        return $this;
    }

    public function build(string $projectRoot): BoostConfig
    {
        $projectRoot = rtrim($projectRoot, '/');

        return new BoostConfig(
            agents: $this->agents,
            allowedVendors: $this->allowedVendors,
            skillsPath: $this->skillsPath ?? $projectRoot . '/.ai/skills',
            guidelinesPath: $this->guidelinesPath ?? $projectRoot . '/.ai/guidelines',
            commandsPath: $this->commandsPath ?? $projectRoot . '/.ai/commands',
            disabledEmitters: $this->disabledEmitters,
            manageGitignore: $this->manageGitignore,
            tags: $this->tags,
            excludedSkills: $this->excludedSkills,
            excludedGuidelines: $this->excludedGuidelines,
            remoteSkills: $this->remoteSkills,
            skillRenderers: $this->buildSkillRenderers(),
        );
    }

    /**
     * Apply the deny-list, re-append the implicit passthrough, run
     * conflict detection. Conflict detection runs AFTER the deny-list so
     * users can resolve a duplicate-extension conflict by disabling one side.
     *
     * @return list<SkillRenderer>
     */
    private function buildSkillRenderers(): array
    {
        $denied = array_flip($this->disabledRenderers);

        $kept = [];
        foreach ($this->skillRenderers as $renderer) {
            if (isset($denied[$renderer::class])) {
                continue;
            }

            $kept[] = $renderer;
        }

        // Re-append the implicit passthrough LAST so user-registered
        // renderers claiming `md` win by first-registered-wins. Disabling
        // PassthroughRenderer via the deny-list is silently no-op'd here.
        $hasPassthrough = false;
        foreach ($kept as $renderer) {
            if ($renderer::class === PassthroughRenderer::class) {
                $hasPassthrough = true;
                break;
            }
        }

        if (! $hasPassthrough) {
            $kept[] = new PassthroughRenderer();
        }

        $this->assertNoExtensionConflicts($kept);

        return $kept;
    }

    /**
     * Build-time conflict detection: two renderers claiming the same
     * extension is fatal unless the deny-list resolved it earlier. The
     * implicit {@see PassthroughRenderer}'s `md` claim is *not* considered
     * a conflict — user-registered `md`-claiming renderers override it via
     * first-registered-wins (the passthrough sits last).
     *
     * @param  list<SkillRenderer>  $renderers
     */
    private function assertNoExtensionConflicts(array $renderers): void
    {
        /** @var array<string, list<class-string>> $byExtension */
        $byExtension = [];
        foreach ($renderers as $renderer) {
            $isImplicitPassthrough = $renderer::class === PassthroughRenderer::class;
            foreach ($renderer->extensions() as $ext) {
                if ($isImplicitPassthrough && isset($byExtension[$ext])) {
                    // User-registered renderer already claims this ext.
                    // Passthrough silently yields — first-wins applies.
                    continue;
                }

                $byExtension[$ext][] = $renderer::class;
            }
        }

        foreach ($byExtension as $ext => $fqcns) {
            if (count($fqcns) > 1) {
                throw new InvalidSkillRendererException(sprintf(
                    'Multiple renderers registered for extension `%s`: %s. Pick one or call withDisabledRenderers([...]) to drop a conflict.',
                    $ext,
                    implode(', ', $fqcns),
                ));
            }
        }
    }
}
