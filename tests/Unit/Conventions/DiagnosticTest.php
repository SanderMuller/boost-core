<?php declare(strict_types=1);

use SanderMuller\BoostCore\Conventions\Diagnostic;

it('constructs an error diagnostic via factory', function (): void {
    $diagnostic = Diagnostic::error('jira.project_key', 'missing', 'sandermuller/boost-skills');

    expect($diagnostic->level)->toBe('error')
        ->and($diagnostic->slot)
        ->toBe('jira.project_key')
        ->and($diagnostic->message)
        ->toBe('missing')
        ->and($diagnostic->vendor)
        ->toBe('sandermuller/boost-skills')
        ->and($diagnostic->isError())
        ->toBeTrue();
});

it('constructs warning and info via factories', function (): void {
    $warning = Diagnostic::warning('foo', 'unknown slot');
    $info = Diagnostic::info(null, 'schema-version: 1 matches all vendors');

    expect($warning->level)->toBe('warning')
        ->and($warning->vendor)
        ->toBeNull()
        ->and($info->level)
        ->toBe('info')
        ->and($info->slot)
        ->toBeNull();
});

it('serializes to a stable array shape for JSON output', function (): void {
    $diagnostic = Diagnostic::warning('pr.title_format', 'unknown slot');

    expect($diagnostic->toArray())->toBe([
        'level' => 'warning',
        'slot' => 'pr.title_format',
        'message' => 'unknown slot',
        'vendor' => null,
    ]);
});

it('rejects an invalid level', function (): void {
    expect(fn () => new Diagnostic('fatal', null, 'oops'))
        ->toThrow(InvalidArgumentException::class, 'Diagnostic level must be one of error/warning/info; got "fatal".');
});

it('reports non-error levels as not isError', function (): void {
    expect(Diagnostic::warning('a', 'b')->isError())->toBeFalse()
        ->and(Diagnostic::info('a', 'b')
            ->isError())
        ->toBeFalse();
});
