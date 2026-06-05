<?php declare(strict_types=1);

namespace SanderMuller\BoostCore\Sync;

use SanderMuller\BoostCore\Agents\AgentTarget;
use SanderMuller\BoostCore\Config\BoostConfig;
use SanderMuller\BoostCore\Conventions\ConventionsBlockEmitter;
use SanderMuller\BoostCore\Conventions\ConventionsPass;
use SanderMuller\BoostCore\Conventions\Diagnostic;
use SanderMuller\BoostCore\Conventions\GuidanceComposer;
use SanderMuller\BoostCore\Conventions\KeepReason;
use SanderMuller\BoostCore\Skills\Guideline;

/**
 * Writes the per-agent guidance files (CLAUDE.md / AGENTS.md / GEMINI.md)
 * wholesale + markerless.
 *
 * Conventions + guidelines assemble into ONE wholesale path per file.
 * Conventions render markerless into CLAUDE.md (from `boost.php`'s
 * `->withConventions([...])`, via the passed {@see ConventionsPass}); guidelines
 * render markerless into each guidance file. {@see GuidanceComposer} migrates
 * legacy marker-bounded files, and the never-lossy empty-assembly guard ensures a
 * non-empty file is never blanked unless boost provably owns it.
 *
 * @internal
 */
final readonly class GuidanceWriter
{
    /**
     * @param  list<AgentTarget>  $agentTargets
     */
    public function __construct(
        private FileWriter $writer,
        private array $agentTargets,
    ) {}

    /**
     * @param  list<Guideline>  $resolvedGuidelines
     * @param  list<KeepReason>  $skillKeepReasons  conventions keep-reasons already collected from skills
     * @return array{writes: list<WrittenFile>, diagnostics: list<Diagnostic>, ownedGuidancePaths: list<string>, emittedGuidancePaths: list<string>, conventionsErrors: list<string>, conventionsKeepReasons: list<KeepReason>, conventionsBlockKept: bool, conventionsEvaluated: bool}
     *   `ownedGuidancePaths` = relative paths boost wrote with NON-empty content
     *   this sync (boost-owned guidance), for recording in the new manifest so a
     *   later empty sync can prove ownership + converge. Excludes preserved
     *   operator files and empty/cleared files. `emittedGuidancePaths` = every
     *   guidance file boost is RESPONSIBLE for this sync (CONFIGURED agents +
     *   conventions-CLAUDE.md), changed or unchanged or left-intact — the set the
     *   stale-managed cleanup must EXEMPT from reaping. Scoped to configured
     *   agents so a DROPPED agent's guidance is NOT exempt and gets reaped.
     *   `conventionsErrors` = guidance-side
     *   render-class token errors (fail --check), from the ConventionsPass gate.
     *   `conventionsKeepReasons` / `conventionsBlockKept` = why the `## Project
     *   Conventions` block was kept (empty + false when dropped).
     */
    public function write(string $projectRoot, BoostConfig $config, array $resolvedGuidelines, bool $checkOnly, bool $skipGuidelineWrites, SyncManifest $priorManifest, ConventionsPass $conventionsPass, bool $skillRequiresRuntime, bool $skillInlinedAny, array $skillKeepReasons = []): array
    {
        /** @var list<WrittenFile> $writes */
        $writes = [];
        /** @var list<Diagnostic> $diagnostics */
        $diagnostics = [];
        /** @var list<string> $ownedGuidancePaths */
        $ownedGuidancePaths = [];
        /** @var list<string> $conventionsErrors */
        $conventionsErrors = [];

        $composer = new GuidanceComposer();

        if ($skipGuidelineWrites) {
            // A guideline render failed. Conventions + guidelines now assemble
            // into ONE wholesale file, so the render-fail safety gate (preserve
            // the prior guidance file byte-for-byte) skips the whole guidance
            // write — including the conventions section. This is a deliberate
            // trade-off of the unified markerless write: while a renderer is
            // broken, the guidance file (conventions + guidelines) holds at its
            // prior state. It is a TRANSIENT degraded window — once the operator
            // fixes the failing renderer, the next sync re-renders conventions +
            // guidelines together. Preserving everything is safer than writing a
            // partial file that drops the failed guideline's content.
            //
            // No ownedGuidancePaths reported on this gate — the render failed,
            // so the manifest write is skipped at the call site anyway (the
            // prior manifest stays last-known-good).
            return ['writes' => $writes, 'diagnostics' => $diagnostics, 'ownedGuidancePaths' => [], 'emittedGuidancePaths' => [], 'conventionsErrors' => $conventionsErrors, 'conventionsKeepReasons' => [], 'conventionsBlockKept' => false, 'conventionsEvaluated' => false];
        }

        $guidanceFiles = $this->collectGuidanceFiles($config, $resolvedGuidelines, $conventionsPass->section());

        // Inline slot tokens into each guidance body + decide the drop
        // gate — owned by ConventionsPass.
        ['files' => $guidanceFiles, 'section' => $effectiveSection, 'errors' => $conventionsErrors, 'selfCheck' => $guidanceSelfCheck, 'keepReasons' => $conventionsKeepReasons, 'blockKept' => $conventionsBlockKept] = $conventionsPass->inlineGuidanceAndGate(
            $projectRoot,
            $guidanceFiles,
            $skillRequiresRuntime,
            $skillInlinedAny,
            $priorManifest,
            $skillKeepReasons,
        );
        $diagnostics = [...$diagnostics, ...$guidanceSelfCheck];

        // Every guidance file boost is responsible for THIS sync (configured
        // agents + conventions-CLAUDE.md), independent of whether it ends up
        // written, unchanged, or left-intact. The stale-managed cleanup exempts
        // exactly this set — a dropped agent's guidance is absent here and so
        // stays reapable.
        $emittedGuidancePaths = array_keys($guidanceFiles);

        foreach ($guidanceFiles as $file => $info) {
            $assembled = $composer->assemble($info['isClaude'] ? $effectiveSection : null, $info['body']);
            $absolute = $projectRoot . '/' . $file;
            $existing = is_file($absolute) ? @file_get_contents($absolute) : null;
            $existing = $existing === false ? null : $existing;

            // Empty-assembly guard: boost produced NO guidance content
            // this sync (no resolved guidelines + no conventions). The
            // markerless model is stateless — for a MARKERLESS file it cannot
            // tell a boost-owned file that should now go empty from a NEW
            // adopter's pre-existing CLAUDE.md (laravel/boost's `boost install`
            // writes one; many repos hand-author one) that boost-core has never
            // synced. Wholesale-writing the empty assembly would WIPE that file
            // — and via BoostAutoSync this can fire on a routine
            // `composer update` without the operator watching. So: never blank a
            // non-empty MARKERLESS guidance file (left untouched + recoverable;
            // an operator who genuinely wants it empty deletes it manually).
            //
            // A file still carrying boost MARKERS is exempt from the guard: the
            // markers prove boost wrote it, so it falls through to migrate(),
            // which strips the markers (converging it to markerless) and
            // preserves any genuine out-of-marker residual — never a wipe. That
            // keeps the legacy-marker upgrade path converging even when the
            // resolved guidance set is empty (stale instructions must not linger
            // in a boost-owned file).
            //
            // For a MARKERLESS non-empty file under empty assembly, consult the
            // PRIOR manifest: if it proves boost owns this exact file (listed +
            // sha-match — i.e. unchanged since boost wrote it), CLEAR it
            // (converge — the "synced then removed all guidance" case). Otherwise
            // PRESERVE: operator-authored, sha-diverged (operator hand-edited),
            // or no manifest yet (cold start) — the never-lossy default. The
            // clear is gated on the PRIOR manifest only; the new manifest is
            // written after a successful sync, so a sync can never promote a
            // pre-existing file to owned mid-run and wipe it.
            if ($assembled === '' && ! ($existing !== null && $composer->hasManagedMarkers($existing))) {
                $ownedByManifest = $existing !== null
                    && trim($existing) !== ''
                    && $priorManifest->ownsGuidance($file, hash('sha256', $existing));

                if (! $ownedByManifest) {
                    $diagnostics = $this->noteGuidanceLeftIntact($diagnostics, $file, $existing);

                    continue;
                }

                // boost-owned markerless file → fall through; migrate(existing,
                // '') returns '' → writes empty → converges to empty.
            }

            // Warn-and-overwrite guard (§5 — the never-lossy guard's non-empty
            // sibling). boost is about to wholesale-replace a NON-empty guidance file
            // it does NOT own (no prior-manifest sha-match) whose content differs from
            // the assembly — i.e. a foreign or operator writer authored it. The
            // overwrite still proceeds (the MINOR-safe default; a preserve mode is a
            // future opt-in), but surface a WARNING so the takeover is visible. Skipped
            // for marker-bearing files (migrate() preserves their residual losslessly)
            // and for boost-owned files (steady-state convergence). The message stays
            // tool-agnostic — the advisory layer (doctor) names a specific tool.
            if ($this->shouldWarnOverwrite($assembled, $existing, $file, $composer, $priorManifest)) {
                $diagnostics[] = Diagnostic::warning(null, $this->guidanceOverwriteMessage($file));
            }

            $migration = $composer->migrate($existing, $assembled);

            // Warn only on the actual marker→markerless transition with genuine
            // content (not on steady-state syncs).
            if ($migration['migrated'] && $migration['residual'] !== null) {
                $diagnostics[] = Diagnostic::warning(null, $this->guidanceReplacedMessage($file, $migration['residual']));
            }

            $written = $this->writer->write($projectRoot, new PendingWrite($file, $migration['content']), $checkOnly);
            if ($written->action !== WriteAction::UNCHANGED) {
                $writes[] = $written;
            }

            // Record boost-owned guidance for the manifest, but ONLY when boost
            // can actually prove ownership: either boost WROTE the file this run
            // (action != UNCHANGED → it's boost's fresh output), OR it was
            // UNCHANGED and the prior manifest still owns this EXACT content
            // (sha-match — genuine steady-state boost output).
            //
            // Crucially, an UNCHANGED file is re-claimed ONLY on a sha-match, not
            // mere presence: a file boost once owned but the
            // operator later hand-edited has a DIVERGED prior sha; if a later
            // assembly happens to coincide with the operator's edit byte-for-
            // byte, `has()` alone would wrongly re-claim it → a subsequent empty
            // sync could blank an operator-edited file. Empty content is never
            // owned. A NOT-listed UNCHANGED file is the first-sync coincidence
            // case — also not claimed.
            $boostProvesOwnership = $written->action !== WriteAction::UNCHANGED
                || $priorManifest->ownsGuidance($file, hash('sha256', $migration['content']));
            if (trim($migration['content']) !== '' && $boostProvesOwnership) {
                $ownedGuidancePaths[] = $file;
            }
        }

        return ['writes' => $writes, 'diagnostics' => $diagnostics, 'ownedGuidancePaths' => $ownedGuidancePaths, 'emittedGuidancePaths' => $emittedGuidancePaths, 'conventionsErrors' => $conventionsErrors, 'conventionsKeepReasons' => $conventionsKeepReasons, 'conventionsBlockKept' => $conventionsBlockKept, 'conventionsEvaluated' => true];
    }

    /**
     * Collect each unique guidance file to write, keyed by relative path. The
     * first active target owning a file defines its guideline body formatting
     * (shared-pool agents use the default formatter, so this is deterministic).
     *
     * Conventions render into CLAUDE.md specifically (its canonical home —
     * convert-conventions / validate all key off it): if there is a conventions
     * section but the Claude agent isn't active, schedule CLAUDE.md anyway with an
     * empty guideline body so the conventions don't silently vanish.
     *
     * @param  list<Guideline>  $resolvedGuidelines
     * @return array<string, array{body: string, isClaude: bool}>
     */
    private function collectGuidanceFiles(BoostConfig $config, array $resolvedGuidelines, ?string $conventionsSection): array
    {
        $guidanceFiles = [];
        foreach ($this->agentTargets as $target) {
            if (! $config->hasAgent($target->agent())) {
                continue;
            }

            $file = $target->guidelinesFileRelative();
            if ($file === null) {
                continue;
            }

            if (isset($guidanceFiles[$file])) {
                continue;
            }

            $guidanceFiles[$file] = [
                'body' => $resolvedGuidelines === [] ? '' : $target->formatGuidelinesContent($resolvedGuidelines),
                'isClaude' => $file === 'CLAUDE.md',
            ];
        }

        if ($conventionsSection !== null && ! isset($guidanceFiles['CLAUDE.md'])) {
            $guidanceFiles['CLAUDE.md'] = ['body' => '', 'isClaude' => true];
        }

        return $guidanceFiles;
    }

    /**
     * Empty-assembly guard bookkeeping: when the existing guidance file is
     * non-empty, append the INFO recording that it was LEFT INTACT (the caller
     * skips the write). Returns the possibly-extended diagnostics list.
     *
     * @param  list<Diagnostic>  $diagnostics
     * @return list<Diagnostic>
     */
    private function noteGuidanceLeftIntact(array $diagnostics, string $file, ?string $existing): array
    {
        if ($existing !== null && trim($existing) !== '') {
            $diagnostics[] = Diagnostic::info(null, $this->guidanceLeftIntactMessage($file));
        }

        return $diagnostics;
    }

    /**
     * INFO fired by the empty-assembly guard: boost resolved no guidance content
     * this sync and the existing guidance file is non-empty, so it was LEFT INTACT
     * rather than blanked. Makes the leave-prior behavior observable.
     */
    private function guidanceLeftIntactMessage(string $file): string
    {
        return sprintf(
            'boost-core resolved no guidelines or conventions this sync, so `%s` was left untouched rather than blanked. Add guidelines under `.ai/guidelines/` (or declare conventions in `boost.php`) to populate it; delete the file manually if you want it empty.',
            $file,
        );
    }

    /**
     * The warn-and-overwrite guard's predicate (§5): a NON-empty assembly is about to
     * wholesale-replace a non-empty guidance file that differs from it, carries no boost
     * markers, and is not boost-owned — i.e. a foreign/operator takeover worth surfacing.
     */
    private function shouldWarnOverwrite(string $assembled, ?string $existing, string $file, GuidanceComposer $composer, SyncManifest $priorManifest): bool
    {
        return $assembled !== ''
            && $existing !== null
            && trim($existing) !== ''
            && $existing !== $assembled
            && ! $composer->hasManagedMarkers($existing)
            && ! $priorManifest->ownsGuidance($file, hash('sha256', $existing));
    }

    /**
     * WARNING fired by the warn-and-overwrite guard (§5): boost is taking over a
     * non-empty guidance file it does not own (a foreign or operator writer authored
     * it). Stays tool-agnostic — accurate whether the file's content reaches boost via
     * `.ai/guidelines/` / a wrapper's injection (preserved) or not (replaced). The
     * laravel/boost-specific reconcile steer lives in `boost doctor`.
     */
    private function guidanceOverwriteMessage(string $file): string
    {
        return sprintf(
            '`%s` was authored outside boost-core (boost does not own it yet) and is being taken over by the '
            . "wholesale guidance assembly. Content that reaches boost via `.ai/guidelines/` (or a wrapper's "
            . 'injected guidelines) is preserved; any OTHER content in the file is replaced. If another tool '
            . 'seeded this file, run that tool\'s reconcile/sync so its content is captured first — '
            . '`boost doctor` reports the specifics.',
            $file,
        );
    }

    /**
     * WARNING fired on the marker→markerless transition when genuine
     * out-of-marker content was preserved below boost's wholesale body. Names the
     * conventions path only when the residual looks like the legacy conventions
     * YAML (convert-conventions no longer applies — markers are gone).
     */
    private function guidanceReplacedMessage(string $file, string $residual): string
    {
        $looksLikeConventions = str_contains($residual, 'schema-version')
            || str_contains($residual, ConventionsBlockEmitter::H2_HEADING);

        $tail = $looksLikeConventions
            ? " If this is the legacy Project Conventions YAML, copy its slot values into `boost.php`'s ->withConventions([...]) chain (the conventions markers are gone, so `convert-conventions` no longer applies); otherwise move it into `.ai/guidelines/`."
            : ' Move it into `.ai/guidelines/` so boost-core assembles it into the guidance file on every sync.';

        return sprintf(
            "0.12.0 markerless migration: `%s` is now wholesale-owned by boost-core (no markers). Content found outside boost-core's generated output has been preserved below it.%s",
            $file,
            $tail,
        );
    }
}
