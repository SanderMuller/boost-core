<?php declare(strict_types=1);

use SanderMuller\BoostCore\Agents\AmpTarget;
use SanderMuller\BoostCore\Agents\ClaudeCodeTarget;
use SanderMuller\BoostCore\Agents\CopilotTarget;
use SanderMuller\BoostCore\Agents\CursorTarget;
use SanderMuller\BoostCore\Agents\JunieTarget;
use SanderMuller\BoostCore\Agents\KiroTarget;
use SanderMuller\BoostCore\Agents\OpenCodeTarget;
use SanderMuller\BoostCore\Skills\Command;

function makeCommandWithBody(string $body): Command
{
    return new Command(
        name: 'deploy',
        description: 'Test.',
        frontmatter: ['description' => 'Test.'],
        body: $body,
        sourcePath: '/src/deploy.md',
        sourceVendor: null,
    );
}

// Claude: $N zero-index conversion, $ARGUMENTS + $name passthrough.

it('Claude transpiles canonical `$1` to zero-indexed `$0`', function (): void {
    $result = (new ClaudeCodeTarget())->transpileCommandBody(makeCommandWithBody('First: $1, second: $2.'));
    expect($result->content)->toBe('First: $0, second: $1.')
        ->and($result->warnings)->toBeEmpty();
});

it('Claude passes `$ARGUMENTS` and named `$name` through unchanged', function (): void {
    $result = (new ClaudeCodeTarget())->transpileCommandBody(makeCommandWithBody('Triage $issue: $ARGUMENTS'));
    expect($result->content)->toBe('Triage $issue: $ARGUMENTS')
        ->and($result->warnings)->toBeEmpty();
});

// OpenCode: native passthrough, named uppercased.

it('OpenCode passes `$ARGUMENTS` and `$N` through natively', function (): void {
    $result = (new OpenCodeTarget())->transpileCommandBody(makeCommandWithBody('Args: $ARGUMENTS, first: $1.'));
    expect($result->content)->toBe('Args: $ARGUMENTS, first: $1.')
        ->and($result->warnings)->toBeEmpty();
});

it('OpenCode uppercases named placeholders', function (): void {
    $result = (new OpenCodeTarget())->transpileCommandBody(makeCommandWithBody('Triage $issue with $priority.'));
    expect($result->content)->toBe('Triage $ISSUE with $PRIORITY.');
});

// Copilot: VS Code ${input:...} shape.

it('Copilot wraps every placeholder in `${input:...}`', function (): void {
    $result = (new CopilotTarget())->transpileCommandBody(makeCommandWithBody('All: $ARGUMENTS, first: $1, named: $issue.'));
    expect($result->content)->toBe('All: ${input:args}, first: ${input:arg1}, named: ${input:issue}.')
        ->and($result->warnings)->toBeEmpty();
});

// Kiro: brace form positional, named warns.

it('Kiro converts `$N` positional to `${N}` brace form', function (): void {
    $result = (new KiroTarget())->transpileCommandBody(makeCommandWithBody('First: $1, args: $ARGUMENTS.'));
    expect($result->content)->toBe('First: ${1}, args: $ARGUMENTS.')
        ->and($result->warnings)->toBeEmpty();
});

it('Kiro emits named placeholders verbatim with a warning', function (): void {
    $result = (new KiroTarget())->transpileCommandBody(makeCommandWithBody('Triage $issue.'));
    expect($result->content)->toBe('Triage $issue.')
        ->and($result->warnings)->toHaveCount(1)
        ->and($result->warnings[0])->toContain('Kiro does not document named placeholders');
});

// Junie: positional auto-named with warn, $ARGUMENTS → $args.

it('Junie maps `$ARGUMENTS` to `$args` (single named arg)', function (): void {
    $result = (new JunieTarget())->transpileCommandBody(makeCommandWithBody('Run $ARGUMENTS.'));
    expect($result->content)->toBe('Run $args.')
        ->and($result->warnings)->toBeEmpty();
});

it('Junie auto-names positional placeholders to `$argN` and warns', function (): void {
    $result = (new JunieTarget())->transpileCommandBody(makeCommandWithBody('First: $1, second: $2.'));
    expect($result->content)->toBe('First: $arg1, second: $arg2.')
        ->and($result->warnings)->toHaveCount(1)
        ->and($result->warnings[0])->toContain('Junie requires named+required args')
        ->and($result->warnings[0])->toContain('$arg1')
        ->and($result->warnings[0])->toContain('$arg2');
});

// Cursor + Amp: warn + verbatim (default base behavior).

it('Cursor emits placeholders verbatim with a no-placeholder warning', function (): void {
    $result = (new CursorTarget())->transpileCommandBody(makeCommandWithBody('Args: $ARGUMENTS, first: $1.'));
    expect($result->content)->toBe('Args: $ARGUMENTS, first: $1.')
        ->and($result->warnings)->toHaveCount(1)
        ->and($result->warnings[0])->toContain('cursor has no placeholder syntax');
});

it('Amp emits placeholders verbatim with a no-placeholder warning', function (): void {
    $result = (new AmpTarget())->transpileCommandBody(makeCommandWithBody('Args: $ARGUMENTS.'));
    expect($result->content)->toBe('Args: $ARGUMENTS.')
        ->and($result->warnings)->toHaveCount(1)
        ->and($result->warnings[0])->toContain('amp has no placeholder syntax');
});

it('Cursor and Amp emit NO warning when the body has no placeholders', function (): void {
    expect((new CursorTarget())->transpileCommandBody(makeCommandWithBody('Plain body.'))->warnings)->toBeEmpty()
        ->and((new AmpTarget())->transpileCommandBody(makeCommandWithBody('Plain body.'))->warnings)->toBeEmpty();
});

// Escape handling at the transpile boundary.

it('All agents preserve `\\$ARGUMENTS` as a literal `$ARGUMENTS`', function (): void {
    // Single-quoted PHP string — `$ARGUMENTS` does NOT interpolate;
    // the literal backslash-dollar-ARGUMENTS reaches the parser.
    $body = 'Echo: \\$ARGUMENTS' . "\n";
    foreach ([new ClaudeCodeTarget(), new OpenCodeTarget(), new CopilotTarget(), new KiroTarget(), new JunieTarget()] as $target) {
        $content = $target->transpileCommandBody(makeCommandWithBody($body))->content;
        expect($content)->toContain('$ARGUMENTS')
            ->and($content)->not->toContain('\\$ARGUMENTS');
    }
});

// SyncResult warnings plumb.

it('planCommands returns the new `{writes, warnings}` shape', function (): void {
    $planned = (new CursorTarget())->planCommands([makeCommandWithBody('Args: $ARGUMENTS.')]);
    expect($planned)->toHaveKeys(['writes', 'warnings'])
        ->and($planned['writes'])->toHaveCount(1)
        ->and($planned['warnings'])->toHaveCount(1)
        ->and($planned['warnings'][0])->toStartWith('[cursor] deploy: ');
});
