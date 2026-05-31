<?php declare(strict_types=1);

namespace SanderMuller\BoostCore\Sync;

use SanderMuller\BoostCore\Agents\AgentTarget;
use SanderMuller\BoostCore\Config\BoostConfig;
use SanderMuller\BoostCore\Conventions\ConventionsBlockEmitter;
use SanderMuller\BoostCore\Conventions\ConventionsPass;
use SanderMuller\BoostCore\Conventions\Diagnostic;
use SanderMuller\BoostCore\Conventions\GuidanceComposer;
use SanderMuller\BoostCore\Skills\Guideline;

/**
 * Writes the per-agent guidance files (CLAUDE.md / AGENTS.md / GEMINI.md)
 * wholesale + markerless, extracted from SyncEngine (maintenance cycle 2026-05).
 *
 * 0.12.0 consolidated the former per-target guideline write + the marker-based
 * conventions write into ONE wholesale path per file. Conventions render
 * markerless into CLAUDE.md (from `boost.php`'s `->withConventions([...])`, via the
 * passed {@see ConventionsPass}); guidelines render markerless into each guidance
 * file. {@see GuidanceComposer} migrates legacy marker-bounded files, and the
 * never-lossy empty-assembly guard (0.12.0 / manifest-aware 0.13.0) ensures a
 * non-empty file is never blanked unless boost provably owns it.
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
     * @return array{writes: list<WrittenFile>, diagnostics: list<Diagnostic>, ownedGuidancePaths: list<string>, conventionsErrors: list<string>}
     *   `ownedGuidancePaths` = relative paths boost wrote with NON-empty content
     *   this sync (boost-owned guidance), for recording in the new manifest so a
     *   later empty sync can prove ownership + converge. Excludes preserved
     *   operator files and empty/cleared files. `conventionsErrors` = guidance-side
     *   render-class token errors (fail --check), from the ConventionsPass gate.
     */
    public function write(string $projectRoot, BoostConfig $config, array $resolvedGuidelines, bool $checkOnly, bool $skipGuidelineWrites, SyncManifest $priorManifest, ConventionsPass $conventionsPass, bool $skillRequiresRuntime, bool $skillInlinedAny): array
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
            return ['writes' => $writes, 'diagnostics' => $diagnostics, 'ownedGuidancePaths' => [], 'conventionsErrors' => $conventionsErrors];
        }

        $guidanceFiles = $this->collectGuidanceFiles($config, $resolvedGuidelines, $conventionsPass->section());

        // 0.15.0: inline slot tokens into each guidance body + decide the drop
        // gate — owned by ConventionsPass.
        ['files' => $guidanceFiles, 'section' => $effectiveSection, 'errors' => $conventionsErrors, 'selfCheck' => $guidanceSelfCheck] = $conventionsPass->inlineGuidanceAndGate(
            $projectRoot,
            $guidanceFiles,
            $skillRequiresRuntime,
            $skillInlinedAny,
            $priorManifest,
        );
        $diagnostics = [...$diagnostics, ...$guidanceSelfCheck];

        foreach ($guidanceFiles as $file => $info) {
            $assembled = $composer->assemble($info['isClaude'] ? $effectiveSection : null, $info['body']);
            $absolute = $projectRoot . '/' . $file;
            $existing = is_file($absolute) ? @file_get_contents($absolute) : null;
            $existing = $existing === false ? null : $existing;

            // Empty-assembly guard (0.12.0): boost produced NO guidance content
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
            // resolved guidance set is empty (codex-review: stale instructions
            // must not linger in a boost-owned file).
            //
            // 0.13.0 — the manifest resolves the 0.12 trade-off. A marker file
            // is exempt (markers prove authorship → falls through to migrate()).
            // For a MARKERLESS non-empty file under empty assembly, consult the
            // PRIOR manifest: if it proves boost owns this exact file (listed +
            // sha-match — i.e. unchanged since boost wrote it), CLEAR it
            // (converge — the "synced then removed all guidance" case now
            // converges correctly). Otherwise PRESERVE: operator-authored, or
            // sha-diverged (operator hand-edited), or no manifest yet (cold
            // start / pre-0.13) — the never-lossy default. The clear is gated
            // on the prior manifest only; the new manifest is written after a
            // successful sync, so the first 0.13 sync can never promote a
            // pre-existing file to owned mid-run and wipe it (codex P1.1).
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
            // mere presence (codex-review): a file boost once owned but the
            // operator later hand-edited has a DIVERGED prior sha; if a later
            // assembly happens to coincide with the operator's edit byte-for-
            // byte, `has()` alone would wrongly re-claim it → a subsequent empty
            // sync could blank an operator-edited file. Empty content is never
            // owned. (A NOT-listed UNCHANGED file is the first-sync coincidence
            // case — also not claimed.)
            $boostProvesOwnership = $written->action !== WriteAction::UNCHANGED
                || $priorManifest->ownsGuidance($file, hash('sha256', $migration['content']));
            if (trim($migration['content']) !== '' && $boostProvesOwnership) {
                $ownedGuidancePaths[] = $file;
            }
        }

        return ['writes' => $writes, 'diagnostics' => $diagnostics, 'ownedGuidancePaths' => $ownedGuidancePaths, 'conventionsErrors' => $conventionsErrors];
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
     * WARNING fired on the 0.12.0 marker→markerless transition when genuine
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
