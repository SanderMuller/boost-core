<?php declare(strict_types=1);

use SanderMuller\BoostCore\Sync\EmitterAction;
use SanderMuller\BoostCore\Sync\EmitterResult;
use SanderMuller\BoostCore\Sync\SyncReporter;
use SanderMuller\BoostCore\Sync\SyncResult;
use SanderMuller\BoostCore\Sync\SyncSummary;
use SanderMuller\BoostCore\Sync\WriteAction;
use SanderMuller\BoostCore\Sync\WrittenFile;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Output\BufferedOutput;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * @param  list<WrittenFile>  $writes
 * @param  list<EmitterResult>  $emitters
 */
function summaryResult(array $writes, array $emitters = []): SyncResult
{
    return new SyncResult(writes: $writes, emitters: $emitters, errors: [], check: false);
}

it('renders the summary line BoostAutoSync parses', function (): void {
    // `BoostAutoSync::summaryReportsChange()` regex-matches this line to decide
    // whether a post-install stays silent. It was produced by a private method
    // and consumed by a regex — a contract in everything but name. Naming it
    // makes both sides point at one place.
    $summary = SyncSummary::from(summaryResult([
        new WrittenFile('a.md', '/tmp/a.md', WriteAction::WROTE),
        new WrittenFile('b.md', '/tmp/b.md', WriteAction::UNCHANGED),
        new WrittenFile('c.md', '/tmp/c.md', WriteAction::UNCHANGED),
        new WrittenFile('d.md', '/tmp/d.md', WriteAction::DELETED),
    ]));

    expect($summary->wrote)->toBe(1)
        ->and($summary->unchanged)->toBe(2)
        ->and($summary->deleted)->toBe(1)
        ->and($summary->line(checkOnly: false))->toBe('Sync done. wrote=1, unchanged=2, deleted=1.')
        ->and($summary->line(checkOnly: false))->toMatch('/wrote=\d+, unchanged=\d+, deleted=\d+/');
});

it('renders the check-mode line without the write counts', function (): void {
    $summary = SyncSummary::from(summaryResult([new WrittenFile('a.md', '/tmp/a.md', WriteAction::UNCHANGED)]));

    expect($summary->line(checkOnly: true))->toBe('No drift. 1 file(s) unchanged.');
});

it('appends the symlink and emitter fragments only when they apply', function (): void {
    $summary = SyncSummary::from(summaryResult(
        [
            new WrittenFile('a.md', '/tmp/a.md', WriteAction::WROTE),
            new WrittenFile('b.md', '/tmp/b.md', WriteAction::SKIPPED_SYMLINK),
        ],
        [new EmitterResult('Acme\Emitter', 'acme/pkg', EmitterAction::WROTE, '.mcp.json', null)],
    ));

    expect($summary->line(checkOnly: false))
        ->toBe('Sync done. wrote=1, unchanged=0, deleted=0. skipped-symlink=1 emitters(wrote=1, skipped=0)');
});

it('reports findings separately from the exit code it would use', function (): void {
    // A wrapper that has already documented exit 0 for its dry-run cannot adopt
    // a stricter code without breaking its own SemVer promise. It should not
    // have to fork the rendering to keep its word, so render() hands back what
    // it FOUND alongside the code boost-core would return.
    $io = new SymfonyStyle(new ArrayInput([]), new BufferedOutput());

    $drifted = new SyncResult(
        writes: [new WrittenFile('a.md', '/tmp/a.md', WriteAction::WOULD_WRITE)],
        emitters: [],
        errors: [],
        check: true,
    );

    $outcome = (new SyncReporter())->render($io, $drifted, checkOnly: true, projectRoot: sys_get_temp_dir());

    expect($outcome->hasDrift)->toBeTrue()
        ->and($outcome->hasErrors)->toBeFalse()
        ->and($outcome->exitCode)->toBe(1);
});

it('reports a clean run as success with no findings', function (): void {
    $io = new SymfonyStyle(new ArrayInput([]), new BufferedOutput());

    $clean = new SyncResult(
        writes: [new WrittenFile('a.md', '/tmp/a.md', WriteAction::UNCHANGED)],
        emitters: [],
        errors: [],
        check: true,
    );

    $outcome = (new SyncReporter())->render($io, $clean, checkOnly: true, projectRoot: sys_get_temp_dir());

    expect($outcome->hasDrift)->toBeFalse()
        ->and($outcome->exitCode)->toBe(0);
});

it('names the caller\'s own command in follow-up advice', function (): void {
    // The report contains advice ("run X to see the filtered skills"). Hardcoding
    // `vendor/bin/boost` would make a WRAPPER's command output point operators at
    // the bare binary — the exact wrong entry point the entry-point banner exists
    // to steer them away from.
    $filtered = new SyncResult(
        writes: [],
        emitters: [],
        errors: [],
        tagFilteredSkillsCount: 3,
        check: false,
    );

    $bare = new BufferedOutput();
    (new SyncReporter())->render(new SymfonyStyle(new ArrayInput([]), $bare), $filtered, false, sys_get_temp_dir());

    $wrapped = new BufferedOutput();
    (new SyncReporter(['tags' => 'php artisan acme:tags']))
        ->render(new SymfonyStyle(new ArrayInput([]), $wrapped), $filtered, false, sys_get_temp_dir());

    // fetch() CLEARS the buffer, so capture once — calling it twice would make
    // the negative assertion pass against an empty string.
    $wrappedOutput = $wrapped->fetch();

    expect($bare->fetch())->toContain('vendor/bin/boost tags')
        ->and($wrappedOutput)->toContain('php artisan acme:tags')
        ->and($wrappedOutput)->not->toContain('vendor/bin/boost');
});

it('explains an errored emitter instead of exiting silently', function (): void {
    // `hasErrors()` is true for a non-empty errors list OR any ERRORED emitter,
    // but only the errors list was rendered. An emitter failure with an empty
    // errors list therefore produced a red exit with nothing on screen — a
    // silent failure, the mirror of doctor's silent success.
    $result = new SyncResult(
        writes: [],
        emitters: [new EmitterResult('Acme\Emitter', 'acme/pkg', EmitterAction::ERRORED, '.mcp.json', 'disk full')],
        errors: [],
        check: false,
    );

    $output = new BufferedOutput();
    $outcome = (new SyncReporter())->render(new SymfonyStyle(new ArrayInput([]), $output), $result, false, sys_get_temp_dir());
    $display = $output->fetch();

    expect($outcome->hasErrors)->toBeTrue()
        ->and($outcome->exitCode)->toBe(1)
        ->and($display)->toContain('Acme\Emitter')
        ->and($display)->toContain('disk full');
});

it('describes drift neutrally for a caller that does not treat it as a failure', function (): void {
    // The exit-code split let a caller keep its own code but not its own REPORT:
    // the drift branch returned early with a "Drift detected" warning and no
    // summary, so a lenient caller printed something that reads like a failure
    // and then exited 0. Worse for an operator than either consistent story.
    $drifted = new SyncResult(
        writes: [
            new WrittenFile('a.md', '/tmp/a.md', WriteAction::WOULD_WRITE),
            new WrittenFile('b.md', '/tmp/b.md', WriteAction::WOULD_DELETE),
        ],
        emitters: [],
        errors: [],
        check: true,
    );

    $lenient = new BufferedOutput();
    $outcome = (new SyncReporter(driftIsFailure: false))
        ->render(new SymfonyStyle(new ArrayInput([]), $lenient), $drifted, true, sys_get_temp_dir());
    $display = $lenient->fetch();

    expect($outcome->hasDrift)->toBeTrue()
        ->and($outcome->exitCode)->toBe(0)
        ->and($display)->toContain('would-write=1')
        ->and($display)->toContain('would-delete=1')
        ->and($display)->toContain('a.md')
        ->and($display)->not->toContain('Drift detected');
});

it('still frames drift as a failure by default', function (): void {
    $drifted = new SyncResult(
        writes: [new WrittenFile('a.md', '/tmp/a.md', WriteAction::WOULD_WRITE)],
        emitters: [],
        errors: [],
        check: true,
    );

    $output = new BufferedOutput();
    $outcome = (new SyncReporter())->render(new SymfonyStyle(new ArrayInput([]), $output), $drifted, true, sys_get_temp_dir());

    expect($outcome->exitCode)->toBe(1)
        ->and($output->fetch())->toContain('Drift detected');
});
