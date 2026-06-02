<?php declare(strict_types=1);

namespace SanderMuller\BoostCore\Sync;

use SanderMuller\BoostCore\Conventions\Diagnostic;
use SanderMuller\BoostCore\Conventions\KeepReason;

/**
 * @internal
 */
final readonly class SyncResult
{
    /**
     * @param  list<WrittenFile>  $writes  Files written by agent fan-out (skills + guidelines).
     * @param  list<EmitterResult>  $emitters  Per-emitter outcomes (FileEmitter plugin layer).
     * @param  list<string>  $errors  Top-level errors that aborted parts of the run.
     * @param  int  $tagFilteredSkillsCount  Count of vendor skills dropped by the tag filter
     *         WHEN the consumer's `withTags()` is empty. Zero when `withTags()` is declared
     *         (intentional filtering) or when no skills were dropped. Drives the post-sync
     *         "discover hidden skills" nudge in `SyncCommand::report()`.
     * @param  list<array{skill: string, shadowedVendor: string}>  $hostShadows  Host
     *         `.ai/skills/<name>/SKILL.md` shadowing an allowlisted-vendor skill of the
     *         same name. Silent override is the documented behavior; the data is surfaced
     *         so `SyncCommand` can log "shadowed: <name> (vendor: <pkg>)" lines —
     *         consumers using `withAllowedVendors` + host overrides can audit which
     *         version actually shipped.
     * @param  list<array{guideline: string, shadowedVendor: string}>  $hostGuidelineShadows
     *         Host `.ai/guidelines/<name>.md` shadowing a TAG-ELIGIBLE allowlisted-vendor
     *         guideline of the same name. Vendor guidelines are tag-filtered
     *         before resolution, so a tag-filtered-out vendor copy is never recorded as
     *         shadowed (no false positives). Surfaced in `boost where` / `boost sync` for
     *         parity with `$hostShadows` (skills).
     * @param  list<Diagnostic>  $diagnostics
     *         Lenient diagnostics from the conventions-schema
     *         layer (schema parse failures, validation diagnostics, scaffold
     *         warnings). Never triggers sync/where exit FAILURE; the
     *         `errors` channel carries fatal-failure semantics.
     * @param  bool  $conventionsBlockKept  Whether this sync KEPT the `## Project
     *         Conventions` block (vs dropped it on proof of full migration). False
     *         when there is no schema / nothing to render.
     * @param  list<KeepReason>  $conventionsKeepReasons  Why the block was kept —
     *         one entry per tripping artifact (a skill / guidance file carrying a
     *         legacy `$.<root>` ref, unresolved token, or prose pointer), or a
     *         single no-migration-yet note. Empty when the block dropped.
     * @param  bool  $conventionsEvaluated  Whether the drop gate actually RAN this
     *         sync. False when the sync aborted before guidance assembly (a skill
     *         collision early-return) or a guideline render failure skipped the
     *         guidance write — in which case `conventionsBlockKept` is the default
     *         false but says nothing, so a reader must treat the block state as
     *         unknown rather than "dropped".
     */
    public function __construct(
        public array $writes,
        public array $emitters,
        public array $errors,
        public bool $check,
        public int $tagFilteredSkillsCount = 0,
        public array $hostShadows = [],
        public array $hostGuidelineShadows = [],
        public array $diagnostics = [],
        public bool $conventionsBlockKept = false,
        public array $conventionsKeepReasons = [],
        public bool $conventionsEvaluated = false,
    ) {}

    public function hasDrift(): bool
    {
        foreach ($this->writes as $write) {
            if ($write->action === WriteAction::WOULD_WRITE || $write->action === WriteAction::WOULD_DELETE) {
                return true;
            }
        }

        foreach ($this->emitters as $emitter) {
            if ($emitter->action === EmitterAction::WOULD_WRITE) {
                return true;
            }
        }

        return false;
    }

    public function hasErrors(): bool
    {
        if ($this->errors !== []) {
            return true;
        }

        foreach ($this->emitters as $emitter) {
            if ($emitter->action === EmitterAction::ERRORED) {
                return true;
            }
        }

        return false;
    }

    public function countByAction(WriteAction $action): int
    {
        $count = 0;
        foreach ($this->writes as $write) {
            if ($write->action === $action) {
                ++$count;
            }
        }

        return $count;
    }

    /**
     * Files a real (non-check) sync would change — both rewrites and the
     * deletions tag-filtering prunes. The number a `--check` run reports.
     */
    public function countWouldChange(): int
    {
        return $this->countByAction(WriteAction::WOULD_WRITE)
            + $this->countByAction(WriteAction::WOULD_DELETE);
    }

    public function countEmittersByAction(EmitterAction $action): int
    {
        $count = 0;
        foreach ($this->emitters as $emitter) {
            if ($emitter->action === $action) {
                ++$count;
            }
        }

        return $count;
    }

    /**
     * Canonical attribution text for a live sync's destructive deletes.
     * Returns `null` when nothing was deleted, otherwise a multi-line
     * string naming the three possible causes (tag-filter, removed
     * `withRemoteSkills` entry, stale-source prune) followed by the
     * deleted paths.
     *
     * The single source of truth for delete-attribution wording —
     * boost-core's own `SyncCommand` renders it as a `[WARNING]` block,
     * wrapper commands (e.g. project-boost-laravel's artisan
     * `project-boost:sync`) take the same string and route it through
     * their own logger so the operator-visible audit signal is
     * identical regardless of invocation surface.
     *
     * Returns `null` in check-mode results too — `--check` already
     * lists `would-delete` paths inline as part of the drift report,
     * so there's nothing to attribute (no destructive action occurred).
     */
    public function renderDeleteAttribution(): ?string
    {
        if ($this->check) {
            return null;
        }

        $deletedPaths = [];
        foreach ($this->writes as $write) {
            if ($write->action === WriteAction::DELETED) {
                $deletedPaths[] = $write->relativePath;
            }
        }

        if ($deletedPaths === []) {
            return null;
        }

        $lines = [
            sprintf(
                'Deleted %d file(s) from agent dirs. The corresponding sources are no longer eligible (tag-filter, removed `withRemoteSkills` entry, or stale prune). Paths:',
                count($deletedPaths),
            ),
        ];

        foreach ($deletedPaths as $path) {
            $lines[] = '  - ' . $path;
        }

        return implode("\n", $lines);
    }
}
