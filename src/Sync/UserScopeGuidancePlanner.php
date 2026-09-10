<?php declare(strict_types=1);

namespace SanderMuller\BoostCore\Sync;

use SanderMuller\BoostCore\Agents\AgentTarget;
use SanderMuller\BoostCore\Conventions\ConventionsInliner;
use SanderMuller\BoostCore\Skills\Guideline;
use SanderMuller\BoostCore\Skills\GuidelineLoader;
use SanderMuller\BoostCore\Skills\Rendering\PassthroughRenderer;
use SanderMuller\BoostCore\Skills\Rendering\SkillRendererDispatcher;
use SanderMuller\BoostCore\Skills\UnrenderableSourceScanner;
use SanderMuller\BoostCore\Skills\UserScopeGuidelineManifest;
use Throwable;

/**
 * Plans a package's user-scope guidance writes — the author-eligible guidelines,
 * rendered once per active agent that has a verified user-level mechanism.
 *
 * Three things separate this from the project-scope guidance write
 * ({@see GuidanceWriter}):
 *
 *  - Only guidelines the AUTHOR flagged user-scope eligible take part. A
 *    guideline is always-on, so publishing them wholesale — the rule for
 *    user-scope SKILLS — would put project-specific instructions into every
 *    session in every repository on the machine.
 *  - Tags are not consulted. A tag scopes a guideline to PROJECTS and needs a
 *    `boost.php` to answer; user scope has neither. A guideline can be both
 *    tagged and user-scope eligible — the two sidecars answer different
 *    questions and must not interact.
 *  - A conventions token cannot resolve here: with no `boost.php` and no
 *    conventions section, the slot has no source. Such a guideline is REFUSED
 *    loudly rather than published with a raw token in its body.
 *
 * @internal
 */
final readonly class UserScopeGuidancePlanner
{
    public function __construct(
        private GuidelineLoader $guidelineLoader,
    ) {}

    /**
     * `keep` spans the FULL agent catalog while `writes` covers only the ACTIVE
     * targets. The two differ whenever the engine is narrowed
     * (`new SyncEngine([new CursorTarget()])`): such a run plans no Claude Code
     * guidance, but the package is still eligible, so the Claude file on disk is
     * live and must not be reaped. Keying the keep set on ELIGIBILITY rather
     * than on this run's emissions is the same rule
     * {@see UserScopeReaper::keepAcrossAgents()} applies to skills. A package
     * with nothing eligible keeps nothing, so withdrawal still reaps.
     *
     * A planning error — a refused token, a failed render — returns NO writes,
     * even when other guidelines planned cleanly. The guidance file is written
     * wholesale, so a partial body would silently drop content; and the run's
     * error stops the manifest update, which would leave the recorded sha
     * describing the previous file. The reaper would then read the partial file
     * as operator-edited and preserve it forever. Holding the last-known-good
     * file is the same trade {@see GuidanceWriter} makes at project scope when a
     * guideline render fails.
     *
     * @param  list<AgentTarget>  $agentTargets  the engine's ACTIVE targets
     * @param  list<string>  $errors  out-parameter: render failures and refused guidelines
     * @return array{writes: array<string, array{target: AgentTarget, content: string}>, keep: array<string, true>}
     */
    public function plan(string $packageRoot, string $packageName, array $agentTargets, array &$errors): array
    {
        /** @var list<string> $planErrors */
        $planErrors = [];
        $eligible = $this->eligibleGuidelines($packageRoot, $packageName, $planErrors);
        $errors = [...$errors, ...$planErrors];

        $slug = SyncEngine::packageSuffix($packageName);

        if ($planErrors !== []) {
            // Nothing is written, but the package still claims its guidance
            // paths: the file on disk is the last-known-good copy, not an
            // orphan, and must survive the degraded run.
            return ['writes' => [], 'keep' => array_fill_keys(self::guidancePathsForSlug($slug), true)];
        }

        if ($eligible === []) {
            return ['writes' => [], 'keep' => []];
        }

        /** @var array<string, array{target: AgentTarget, content: string}> $writes */
        $writes = [];
        foreach ($agentTargets as $target) {
            $relative = $target->userScopeGuidanceFileRelative($slug);
            if ($relative === null) {
                continue;
            }

            $writes[$relative] = [
                'target' => $target,
                'content' => $target->formatGuidelinesContent($eligible),
            ];
        }

        return ['writes' => $writes, 'keep' => array_fill_keys(self::guidancePathsForSlug($slug), true)];
    }

    /**
     * Every user-scope guidance path package slug `$slug` can occupy, across the
     * FULL agent catalog — the reaper's delete-candidate roots. Computed over the
     * full catalog, not the active targets, so a narrowed engine still reaps a
     * guidance file written for an agent it does not drive.
     *
     * @return list<string>
     */
    public static function guidancePathsForSlug(string $slug): array
    {
        $paths = [];
        foreach (SyncEngine::allAgentTargets() as $target) {
            $path = $target->userScopeGuidanceFileRelative($slug);
            if ($path !== null) {
                $paths[] = $path;
            }
        }

        return $paths;
    }

    /**
     * Write the planned guidance files and report the paths emitted.
     *
     * Owned here rather than by the engine's shared skill-write path: a guidance
     * file has no legacy flat sibling to prune, and keeping the loop out of
     * {@see SyncEngine} holds that class inside its complexity budget.
     *
     * @param  array<string, array{target: AgentTarget, content: string}>  $planned
     * @param  list<WrittenFile>  $writes  out-parameter
     * @param  list<string>  $errors  out-parameter
     * @return array<string, true>  relative paths emitted this run
     */
    public function emit(FileWriter $writer, string $home, array $planned, bool $checkOnly, array &$writes, array &$errors): array
    {
        $emitted = [];
        foreach ($planned as $relative => $write) {
            try {
                $writes[] = $writer->write($home, new PendingWrite($relative, $write['content']), $checkOnly);
                $emitted[$relative] = true;
            } catch (Throwable $throwable) {
                $errors[] = sprintf(
                    'Failed to write %s for %s: %s',
                    $relative,
                    $write['target']->agent()->value,
                    $throwable->getMessage(),
                );
            }
        }

        return $emitted;
    }

    /**
     * An eligible guideline no registered renderer can read never reaches the
     * loader — it is dropped with a WARNING, not an error. At project scope that
     * costs one guideline; here it would silently publish a guidance file
     * missing the very content the author selected, or reap the file when
     * nothing else remains. So a skipped source the sidecar names is promoted to
     * a planning error, which holds the last-known-good file.
     *
     * Only the sidecar can be consulted: a source that cannot be rendered cannot
     * have its frontmatter read either.
     *
     * @param  list<string>  $errors  out-parameter
     */
    private function reportUnrenderableEligible(string $directory, array &$errors): void
    {
        $manifest = UserScopeGuidelineManifest::load($directory);
        $dispatcher = new SkillRendererDispatcher([new PassthroughRenderer()]);

        foreach ((new UnrenderableSourceScanner())->guidelineSkips($directory, $dispatcher) as $source) {
            if (! $manifest->isEligible($source->relativePath)) {
                continue;
            }

            $errors[] = sprintf(
                'Guideline `%s` is listed in %s but no registered renderer can read it, so it cannot be published at user scope: %s',
                $source->relativePath,
                UserScopeGuidelineManifest::FILENAME,
                $source->message,
            );
        }
    }

    /**
     * Promote a render failure to a user-scope planning error only when it
     * concerns a guideline the sidecar selected.
     *
     * A planning error stops the package's whole user-scope publication, so a
     * project-only guideline that fails to render must not block a machine-wide
     * sync it has no part in. The failure still surfaces at project scope, where
     * it belongs — the loader reports it on every project sync.
     *
     * A failed render leaves no frontmatter to read, so the sidecar is the only
     * eligibility source here, exactly as in
     * {@see reportUnrenderableEligible()}.
     *
     * @param  list<string>  $renderErrors  loader messages, each naming its source path in backticks
     * @param  list<string>  $errors  out-parameter
     */
    private function reportEligibleRenderFailures(string $directory, array $renderErrors, array &$errors): void
    {
        $listed = UserScopeGuidelineManifest::load($directory)->listedPaths();

        foreach ($renderErrors as $renderError) {
            foreach ($listed as $path) {
                if (str_contains($renderError, '`' . $path . '`')) {
                    $errors[] = $renderError;

                    break;
                }
            }
        }
    }

    /**
     * @param  list<string>  $errors
     * @return list<Guideline>
     */
    private function eligibleGuidelines(string $packageRoot, string $packageName, array &$errors): array
    {
        $directory = $packageRoot . '/resources/boost/guidelines';
        if (! is_dir($directory)) {
            return [];
        }

        /** @var list<string> $renderErrors */
        $renderErrors = [];
        /** @var list<Guideline> $eligible */
        $eligible = [];

        $this->reportUnrenderableEligible($directory, $errors);

        foreach ($this->guidelineLoader->load($directory, $packageName, errors: $renderErrors, projectRoot: $packageRoot) as $guideline) {
            if (! $guideline->userScopeEligible) {
                continue;
            }

            if (ConventionsInliner::containsToken($guideline->body)) {
                $errors[] = sprintf(
                    'Guideline `%s` (%s) is user-scope eligible but holds a conventions token. A token cannot resolve at user scope — there is no boost.php and no conventions section — so the guideline is not published. Remove the token, or drop the guideline from %s.',
                    $guideline->name,
                    $packageName,
                    UserScopeGuidelineManifest::FILENAME,
                );

                continue;
            }

            $eligible[] = $guideline;
        }

        $this->reportEligibleRenderFailures($directory, $renderErrors, $errors);

        return $eligible;
    }
}
