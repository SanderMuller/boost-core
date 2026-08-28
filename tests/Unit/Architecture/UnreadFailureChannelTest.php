<?php declare(strict_types=1);

use SanderMuller\BoostCore\Sync\EmitterAction;

/**
 * Guards the one defect shape this codebase has produced repeatedly: **a
 * failure channel that exists, is populated, and is never read.**
 *
 * Four instances shipped before anyone noticed — `DoctorCommand::reportDrift()`
 * never consulting `SyncResult::hasErrors()` (a clean verdict over a failed
 * run); the same error list already gating the engine's stale-cleanup
 * suppression, so one array meant "degraded" to the engine and nothing to
 * doctor; `SyncCommand::report()` rendering `$result->errors` but not the
 * ERRORED-emitter half of `hasErrors()` (exit 1, empty screen); and the picker
 * guard reading Symfony's `--no-interaction` flag while laravel/prompts read
 * the TTY.
 *
 * None was caught by the ordinary suite, and the reason generalises: a test
 * asserts what IS printed, never what is populated-but-unread. There is no
 * natural place for that assertion to live — so it lives here.
 *
 * MAINTENANCE NOTE. `VERDICT_RENDERERS` and `ERROR_LISTERS` are hand-kept
 * lists, which is the weak part: a verdict-maker added later is uncovered
 * until someone remembers to list it. The enum check avoids that by
 * enumerating cases, but "renders a verdict" is not mechanically detectable
 * and a heuristic ("takes a SyncResult, returns an exit code") over-matches.
 * So: adding a class to these lists is PART OF writing a new verdict-maker,
 * not an optional follow-up.
 *
 * SCOPE, honestly stated. These checks catch "nobody reads this at all". They
 * do NOT check that the reader is CORRECT, so they would not have caught
 * doctor's wrong verdict on their own — only its total absence. Reviewing the
 * reader stays a human job. What is mechanised is the class of defect that
 * accumulated four times in a suite of over a thousand tests.
 */
const VERDICT_RENDERERS = [
    // Every class that turns a SyncResult into a statement about the run.
    'src/Sync/SyncReporter.php',
    'src/Commands/DoctorCommand.php',
    'src/Commands/WhereCommand.php',
];

/**
 * Paths where a result channel may legitimately be READ BY AN OPERATOR-FACING
 * path. `SyncResult` itself is deliberately absent: a predicate that merely
 * consults a case (`hasDrift()` reading WOULD_WRITE) satisfies no one's need
 * to SEE it, and including it let a case count as rendered when nothing
 * printed it. Verified by reverting the errored-emitter fix and watching this
 * test go from MISSED to CAUGHT.
 */
const RENDERING_PATHS = [
    'src/Sync/SyncReporter.php',
    'src/Sync/SyncSummary.php',
    'src/Sync/SkillShipmentIndex.php',
    'src/Commands',
];

/**
 * Cases with no reader in any rendering path, each with the reason it is
 * deliberate. An entry here is a claim that operators do not need to be told,
 * NOT a parking space for an unrendered failure.
 */
const ERROR_LISTERS = [
    // Files that tell an operator a run failed. Each must cover BOTH channels
    // of `hasErrors()` — the errors list AND ERRORED emitters — or delegate to
    // the one renderer that does.
    'src/Sync/SyncReporter.php',
    'src/Commands/DoctorCommand.php',
];

const UNREAD_EMITTER_ACTIONS = [
    // Nothing happened to the file. A per-emitter "unchanged" line would bury
    // the cases that did change something.
    'UNCHANGED' => 'no-op outcome; the summary counts what moved',
    // Read by SyncResult::hasDrift() itself, which IS in a rendering path, so
    // check mode already fails on an emitter that would write.
    'WOULD_WRITE' => 'consumed by SyncResult::hasDrift()',
    // An emitter the config switched off. Absence is the configured intent, so
    // reporting it every run would be noise; `boost doctor` is where a
    // consumer inspects emitter configuration.
    'DISABLED' => 'configured absence, not a failure',
];

function sourceOf(string $path): string
{
    $full = dirname(__DIR__, 3) . '/' . $path;

    if (is_dir($full)) {
        $out = '';
        /** @var SplFileInfo $file */
        foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator($full, FilesystemIterator::SKIP_DOTS)) as $file) {
            if ($file->isFile() && $file->getExtension() === 'php') {
                $out .= (string) file_get_contents($file->getPathname());
            }
        }

        return $out;
    }

    return (string) file_get_contents($full);
}

it('makes every verdict renderer consult the failure channel', function (): void {
    // The invariant behind three of the four: do not state an outcome for a run
    // without first asking whether it failed. A new class that reports on a
    // SyncResult belongs in VERDICT_RENDERERS, which forces this question.
    $missing = [];
    foreach (VERDICT_RENDERERS as $path) {
        if (! str_contains(sourceOf($path), 'hasErrors()')) {
            $missing[] = $path;
        }
    }

    expect($missing)->toBe([], sprintf(
        "These classes render a verdict from a SyncResult without consulting hasErrors():\n- %s\n"
        . 'A verdict computed over a failed run is not a verdict — see DoctorCommand::reportDrift().',
        implode("\n- ", $missing),
    ));
});

it('makes every emitter outcome reachable by an operator', function (): void {
    // `hasErrors()` is true for an ERRORED emitter, but the errors LIST does not
    // contain it — so rendering the list alone exited 1 with an empty screen.
    // Enum-case granularity is what catches that; accessor granularity does not,
    // because `$emitters` was referenced all along.
    $rendering = implode('', array_map(sourceOf(...), RENDERING_PATHS));

    $unread = [];
    foreach (EmitterAction::cases() as $case) {
        if (array_key_exists($case->name, UNREAD_EMITTER_ACTIONS)) {
            continue;
        }

        if (! str_contains($rendering, 'EmitterAction::' . $case->name)) {
            $unread[] = $case->name;
        }
    }

    expect($unread)->toBe([], sprintf(
        "No rendering path reads these emitter outcomes, so an operator can never see them:\n- %s\n"
        . 'Render them, or add them to UNREAD_EMITTER_ACTIONS with the reason they are silent.',
        implode("\n- ", $unread),
    ));
});

it('makes every error list cover both channels of hasErrors()', function (): void {
    // Codex caught the instance this check exists for, in code written to fix
    // the very same shape: `reportDriftUnassessable()` warned "fix the errors
    // below", then listed `$result->errors` only — so an ERRORED emitter with an
    // empty errors list produced a heading above nothing at all.
    //
    // Check 1 could not see it (doctor references hasErrors()), and check 2
    // could not either (some path referenced ERRORED). Listing errors WITHOUT
    // covering emitters is the condition that was actually wrong.
    $offenders = [];
    foreach (ERROR_LISTERS as $path) {
        $source = sourceOf($path);

        $listsErrors = str_contains($source, '$result->errors');
        $coversEmitters = str_contains($source, 'EmitterAction::ERRORED')
            || str_contains($source, 'renderErrors(');

        if ($listsErrors && ! $coversEmitters) {
            $offenders[] = $path;
        }
    }

    expect($offenders)->toBe([], sprintf(
        "These render an error list without the ERRORED-emitter half of hasErrors():\n- %s\n"
        . 'Call SyncReporter::renderErrors() rather than keeping a second copy that can drift.',
        implode("\n- ", $offenders),
    ));
});
