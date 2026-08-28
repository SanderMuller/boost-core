<?php declare(strict_types=1);

namespace SanderMuller\BoostCore\Sync;

use SanderMuller\BoostCore\Config\BoostConfig;
use SanderMuller\BoostCore\Config\BoostConfigLoader;
use SanderMuller\BoostCore\Conventions\ConventionTokenLeakScanner;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Style\SymfonyStyle;
use Throwable;

/**
 * Renders a {@see SyncResult} to the console exactly as `bin/boost sync`
 * does, and returns the exit code that run would use.
 *
 * **Why this is `@api`.** A wrapper package drives the sync through
 * {@see BoostSync} and then has to report it. Before this class existed the
 * only implementation lived in private methods on boost-core's `SyncCommand`,
 * so every wrapper reimplemented drift lists, diagnostics, shadow notes, the
 * tag-filter nudge and the summary line — and any of them could describe the
 * same result differently from the bare binary. Two entry points that describe
 * one result in two ways is the quiet-divergence problem in miniature.
 *
 * The exit-code decisions are part of the contract, since they are what a CI
 * step reads:
 *
 * - top-level errors on the result → FAILURE, whatever the mode;
 * - `--check` with an error-level conventions diagnostic → FAILURE;
 * - `--check` with a leaked conventions token in emitted output → FAILURE;
 * - `--check` with drift → FAILURE, plus the changed-path list;
 * - otherwise SUCCESS.
 *
 * The wording of the human output is NOT contractual, with one exception:
 * the parseable fragment of {@see SyncSummary::line()}.
 *
 * @api Stable as of 1.4. Frozen surface: {@see report()} and its exit-code
 * semantics.
 */
final readonly class SyncReporter
{
    /**
     * @param  array<string, string>  $commandInvocations  bare boost-core command
     *   name => how to invoke the equivalent in THIS project. A wrapper passes
     *   its own commands here; anything unmapped falls back to
     *   `vendor/bin/boost <name>`.
     *
     *   The equivalent need NOT be a command of the same name, or a
     *   reimplementation of ours — only the right thing to run instead. A
     *   package with no `tags` command of its own but a richer `where` maps
     *   `['tags' => 'php artisan project-boost:where']`. Leaving it unmapped is
     *   the wrong answer: the fallback names a bare command that, in a wrapper
     *   project, reports a materially incomplete set.
     */
    public function __construct(
        private array $commandInvocations = [],
        /**
         * Whether `--check` drift is a failure. True is boost-core's own rule
         * and the right default: a check run that stays green while files
         * would change teaches a CI step nothing.
         *
         * A caller whose CLI has already DOCUMENTED a lenient exit code sets
         * this false, and gets neutral drift wording to match — otherwise the
         * report reads like a failure while the process exits 0, which is
         * worse for an operator than either consistent story.
         */
        private bool $driftIsFailure = true,
    ) {}

    /**
     * How to tell the operator to run a boost-core command.
     *
     * The report contains follow-up advice ("run X to see the filtered
     * skills"), and hardcoding `vendor/bin/boost` would make a wrapper's own
     * command output point at the bare binary — the exact wrong entry point
     * this release exists to steer people away from. The advice has to name
     * the CLI the operator is actually using.
     */
    private function invocation(string $bareCommand): string
    {
        return $this->commandInvocations[$bareCommand] ?? 'vendor/bin/boost ' . $bareCommand;
    }

    private function hasErrorDiagnostic(SyncResult $result): bool
    {
        foreach ($result->diagnostics as $diagnostic) {
            if ($diagnostic->isError()) {
                return true;
            }
        }

        return false;
    }

    /**
     * On-disk conventions-token leak scan for `--check` — the same scan
     * `validate --strict` runs (symlink-following, #88), so both CI gates fail
     * identically on a leaked token. Returns FAILURE when any leak is found,
     * SUCCESS otherwise. A config that fails to (re)load is a no-op here — that
     * failure already surfaced on the sync itself.
     */
    private function conventionTokenLeakError(SymfonyStyle $io, string $projectRoot, ?string $configFile): int
    {
        $config = $this->loadConfigQuietly($projectRoot, $configFile);
        if (! $config instanceof BoostConfig) {
            return Command::SUCCESS;
        }

        $leaks = ConventionTokenLeakScanner::fromConfig(InstalledPackages::fromComposer(), $config)
            ->errorDiagnostics($projectRoot, $config);
        if ($leaks === []) {
            return Command::SUCCESS;
        }

        foreach ($leaks as $leak) {
            $io->error($leak->message);
        }

        $io->error('Leaked conventions token(s) in emitted output. A skill whose output path is a symlink serves its SOURCE raw, so its `boost:conv` tokens never resolve — remove the symlink and re-sync (or fix the token).');

        return Command::FAILURE;
    }

    /**
     * Render a completed sync and return the process exit code
     * `bin/boost sync` would use.
     *
     * Convenience over {@see render()} for a caller that wants boost-core's
     * exit rule as well as its output. A caller with its own documented exit
     * contract should call `render()` and decide from the findings.
     *
     * `$projectRoot` and `$configFile` are used only by the check-mode
     * conventions-token leak scan, which re-reads the config; pass what the
     * sync itself ran with.
     */
    public function report(SymfonyStyle $io, SyncResult $result, bool $checkOnly, string $projectRoot, ?string $configFile = null): int
    {
        return $this->render($io, $result, $checkOnly, $projectRoot, $configFile)->exitCode;
    }

    /**
     * Render a completed sync and report WHAT WAS FOUND, leaving the exit
     * decision to the caller. See {@see SyncReportOutcome} for why those two
     * are separable.
     */
    public function render(SymfonyStyle $io, SyncResult $result, bool $checkOnly, string $projectRoot, ?string $configFile = null): SyncReportOutcome
    {
        // Render diagnostics BEFORE the error short-circuit. Render-fail
        // warnings and other safety-gate diagnostics carry
        // operator-facing reassurance ("prior content preserved") that
        // must reach the operator even when SyncResult also carries
        // top-level errors.
        $this->renderConventionsDiagnostics($io, $result);

        if ($result->hasErrors()) {
            $this->renderErrors($io, $result);

            return new SyncReportOutcome(true, false, false, false, Command::FAILURE);
        }

        // --check gates on an error-level conventions diagnostic (e.g. a
        // schema-version handshake mismatch) in addition to drift. Plain sync
        // stays lenient (the diagnostic is rendered above, but exit stays 0 so a
        // composer install isn't broken) — the gate is the CI surface.
        if ($checkOnly && $this->hasErrorDiagnostic($result)) {
            $io->error('Conventions error: a vendor\'s required schema-version is not satisfied by the host — its conventions were NOT applied (see the diagnostics above). Align the host schema-version or the vendor allowlist.');

            return new SyncReportOutcome(false, true, false, false, Command::FAILURE);
        }

        // --check fails on a leaked conventions token in EMITTED output — parity
        // with `validate --strict`. The scan follows symlinked emitted files, so it
        // catches a token served RAW through a SKIPPED_SYMLINK skill output (a real
        // correctness leak the inline self-check above can't see, since that content
        // was never written). Plain sync stays lenient — this is the CI gate only.
        if ($checkOnly) {
            $leak = $this->conventionTokenLeakError($io, $projectRoot, $configFile);
            if ($leak !== Command::SUCCESS) {
                return new SyncReportOutcome(false, false, true, false, Command::FAILURE);
            }
        }

        if ($checkOnly && $result->hasDrift()) {
            $this->renderDrift($io, $result);

            return new SyncReportOutcome(
                false,
                false,
                false,
                true,
                $this->driftIsFailure ? Command::FAILURE : Command::SUCCESS,
            );
        }

        $summary = SyncSummary::from($result);
        $skippedSymlink = $summary->skippedSymlink;

        if ($skippedSymlink > 0) {
            $skippedPaths = array_values(array_map(
                static fn (WrittenFile $write): string => $write->relativePath,
                array_filter(
                    $result->writes,
                    static fn (WrittenFile $write): bool => $write->action === WriteAction::SKIPPED_SYMLINK,
                ),
            ));

            // A NOTE, not a warning: a path with a live symlink segment is preserved
            // BY DESIGN — boost never follows or overwrites a symlink it can't prove
            // it owns (it may be a legacy symlink-era artifact or an intentional
            // operator link). Nothing is wrong; it just won't converge to a plain copy
            // until the operator removes the link. (Dead/broken symlinks are auto-pruned.)
            $io->note(sprintf(
                "%d file(s) skipped — a path segment is a live (resolving) symlink, preserved by design (boost does not follow or overwrite it). To switch to a plain copy, remove the link and re-sync (e.g. `find %s -type l -delete && %s`):\n  - %s",
                $skippedSymlink,
                AgentDirSymlinkScanner::cleanupRootsFor($skippedPaths),
                $this->invocation('sync'),
                implode("\n  - ", $skippedPaths),
            ));
        }

        $this->noteDeletes($io, $result, $checkOnly, $summary->deleted);
        $this->noteHostShadows($io, $result);

        if ($checkOnly) {
            $io->success($summary->line(checkOnly: true));
            $this->noteTagFilterGap($io, $result);

            return SyncReportOutcome::success();
        }

        $io->success($summary->line(checkOnly: false));
        $this->noteTagFilterGap($io, $result);

        return SyncReportOutcome::success();
    }

    /**
     * Renders the SyncResult::diagnostics list. The list carries multiple
     * kinds — conventions warn/error, clean-slate stale-removal info,
     * copilot-instructions strip info, render-fail safety warnings. The
     * section is named "Diagnostics" to cover all of those without
     * misleading operators who'd otherwise scroll past expecting only
     * conventions content.
     */
    private function renderConventionsDiagnostics(SymfonyStyle $io, SyncResult $result): void
    {
        if ($result->diagnostics === []) {
            return;
        }

        $io->section('Diagnostics');
        foreach ($result->diagnostics as $diagnostic) {
            $glyph = match ($diagnostic->level) {
                'error' => '<fg=red>✗</>',
                'warning' => '<fg=yellow>⚠</>',
                'info' => '<fg=cyan>ℹ</>',
                default => ' ',
            };
            $slot = $diagnostic->slot === null ? '' : "{$diagnostic->slot}: ";
            $vendor = $diagnostic->vendor === null ? '' : " ({$diagnostic->vendor})";
            $io->writeln("{$glyph} {$slot}{$diagnostic->message}{$vendor}");
        }
    }

    /**
     * Nudge a consumer whose `withTags()` is empty AND some vendor skills were
     * tag-filtered out as a result — the silent-filter foot-gun. Three real
     * boost-stack repos hit it (repo-new, package-boost-laravel, boost-skills'
     * own dogfood) before being audited. Pointing the consumer at `boost tags`
     * is the cheapest discoverability fix.
     */
    private function noteTagFilterGap(SymfonyStyle $io, SyncResult $result): void
    {
        if ($result->tagFilteredSkillsCount > 0) {
            $io->note(sprintf(
                '%d tagged skill(s) currently filtered out — your `withTags()` is empty. Run `%s` to see them.',
                $result->tagFilteredSkillsCount,
                $this->invocation('tags'),
            ));
        }
    }

    /**
     * Log every host-vs-vendor shadow event. Silent override is the
     * documented behavior, but operators using `withAllowedVendors` +
     * symlinked host overrides have no way to tell which version
     * actually ships without this log. Each line names the skill and
     * the vendor whose copy was shadowed.
     */
    private function noteHostShadows(SymfonyStyle $io, SyncResult $result): void
    {
        if ($result->hostShadows !== []) {
            $io->note(sprintf(
                '%d host skill(s) shadowed allowlisted-vendor copies:',
                count($result->hostShadows),
            ));
            foreach ($result->hostShadows as $shadow) {
                $io->writeln(sprintf('  • <fg=cyan>%s</> shadows %s', $shadow['skill'], $shadow['shadowedVendor']));
            }
        }

        if ($result->hostGuidelineShadows !== []) {
            // Count UNIQUE host guidelines, not shadow events (one guideline can
            // shadow the same name across multiple vendors).
            $uniqueGuidelines = count(array_unique(array_column($result->hostGuidelineShadows, 'guideline')));
            $io->note(sprintf(
                '%d host guideline(s) shadowed allowlisted-vendor copies:',
                $uniqueGuidelines,
            ));
            foreach ($result->hostGuidelineShadows as $shadow) {
                $io->writeln(sprintf('  • <fg=cyan>%s</> shadows %s', $shadow['guideline'], $shadow['shadowedVendor']));
            }
        }
    }

    private function noteDeletes(SymfonyStyle $io, SyncResult $result, bool $checkOnly, int $deleted): void
    {
        if ($checkOnly || $deleted <= 0) {
            return;
        }

        // Delegate to the canonical attribution renderer so wrapper
        // commands (project-boost-laravel artisan, future custom CLIs)
        // produce identical text via `$result->renderDeleteAttribution()`.
        $attribution = $result->renderDeleteAttribution();
        if ($attribution !== null) {
            $io->warning($attribution);
        }
    }

    /**
     * Both error channels. `hasErrors()` is true for a non-empty errors list OR
     * any ERRORED emitter, and the two live in different places on the result —
     * rendering only the list meant an emitter failure on an otherwise clean run
     * exited 1 having printed nothing at all.
     */
    private function renderErrors(SymfonyStyle $io, SyncResult $result): void
    {
        foreach ($result->errors as $error) {
            $io->error($error);
        }

        foreach ($result->emitters as $emitter) {
            if ($emitter->action === EmitterAction::ERRORED) {
                $io->error(sprintf(
                    'emitter %s (%s): %s',
                    $emitter->fqcn,
                    $emitter->vendor,
                    $emitter->reason ?? 'no reason recorded',
                ));
            }
        }
    }

    /**
     * The changed-path list plus a countable summary. The FRAMING follows
     * `$driftIsFailure`, because whoever owns the exit code owns whether this
     * is bad news — a warning above an exit 0 is worse than either consistent
     * story.
     */
    private function renderDrift(SymfonyStyle $io, SyncResult $result): void
    {
        $count = $result->countWouldChange();

        if ($this->driftIsFailure) {
            $io->warning(sprintf('Drift detected: %d file(s) would change.', $count));
        } else {
            $io->note(sprintf('%d file(s) would change.', $count));
        }

        foreach ($result->writes as $write) {
            if ($write->action === WriteAction::WOULD_WRITE) {
                $io->writeln('  ~ ' . $write->relativePath);
            }

            if ($write->action === WriteAction::WOULD_DELETE) {
                $io->writeln('  - ' . $write->relativePath);
            }
        }

        $io->writeln(SyncSummary::from($result)->line(checkOnly: true));
    }

    /**
     * Re-read the config for the leak scan. A config that fails to load is a
     * no-op here: that failure already surfaced on the sync itself, and a
     * reporter must never turn a reporting step into a second error.
     */
    private function loadConfigQuietly(string $projectRoot, ?string $configFile): ?BoostConfig
    {
        try {
            return (new BoostConfigLoader())->load($projectRoot, $configFile);
        } catch (Throwable) {
            return null;
        }
    }
}
