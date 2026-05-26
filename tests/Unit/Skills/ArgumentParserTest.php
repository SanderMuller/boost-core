<?php declare(strict_types=1);

use SanderMuller\BoostCore\Skills\ArgumentParser;
use SanderMuller\BoostCore\Skills\ArgumentToken;

it('parses an empty body as zero tokens', function (): void {
    expect((new ArgumentParser())->parse(''))
        ->toBeEmpty();
});

it('parses plain text as a single literal token', function (): void {
    $tokens = (new ArgumentParser())->parse('hello world');
    expect($tokens)->toHaveCount(1)
        ->and($tokens[0]->kind)->toBe(ArgumentToken::KIND_LITERAL)
        ->and($tokens[0]->value)->toBe('hello world');
});

it('parses `$ARGUMENTS` as the unsplit-args token', function (): void {
    $tokens = (new ArgumentParser())->parse('Triage $ARGUMENTS now.');
    expect($tokens)->toHaveCount(3)
        ->and($tokens[0]->kind)->toBe(ArgumentToken::KIND_LITERAL)
        ->and($tokens[1]->kind)->toBe(ArgumentToken::KIND_ARGUMENTS)
        ->and($tokens[2]->kind)->toBe(ArgumentToken::KIND_LITERAL);
});

it('parses `$N` as one-indexed positional', function (): void {
    $tokens = (new ArgumentParser())->parse('$1 and $42 here');
    expect($tokens)->toHaveCount(4)
        ->and($tokens[0]->kind)->toBe(ArgumentToken::KIND_POSITIONAL)
        ->and($tokens[0]->position)->toBe(1)
        ->and($tokens[2]->kind)->toBe(ArgumentToken::KIND_POSITIONAL)
        ->and($tokens[2]->position)->toBe(42);
});

it('parses `$word` as a named token', function (): void {
    $tokens = (new ArgumentParser())->parse('Triage $issue with priority $level.');
    expect($tokens)->toHaveCount(5)
        ->and($tokens[1]->kind)->toBe(ArgumentToken::KIND_NAMED)
        ->and($tokens[1]->value)->toBe('issue')
        ->and($tokens[3]->kind)->toBe(ArgumentToken::KIND_NAMED)
        ->and($tokens[3]->value)->toBe('level');
});

it('respects word boundaries for positional placeholders', function (): void {
    // `$1deploy` is not `$1` + `deploy` — `1deploy` is not a valid
    // numeric literal followed by word characters (the regex demands a
    // word-boundary terminator after the digits). The token shape
    // depends on the parser's recovery rule.
    $tokens = (new ArgumentParser())->parse('$1deploy');
    expect($tokens[0]->kind)->toBe(ArgumentToken::KIND_LITERAL);
});

it('treats `\\$ARGUMENTS` as a literal `$ARGUMENTS` (no placeholder)', function (): void {
    $tokens = (new ArgumentParser())->parse('Echo \\$ARGUMENTS literally.');
    expect($tokens)->toHaveCount(1)
        ->and($tokens[0]->kind)->toBe(ArgumentToken::KIND_LITERAL)
        ->and($tokens[0]->value)->toBe('Echo $ARGUMENTS literally.');
});

it('treats `\\$1` as a literal `$1`', function (): void {
    $tokens = (new ArgumentParser())->parse('Pass \\$1 unchanged.');
    expect($tokens)->toHaveCount(1)
        ->and($tokens[0]->value)->toBe('Pass $1 unchanged.');
});

it('treats `\\$name` as a literal `$name`', function (): void {
    $tokens = (new ArgumentParser())->parse('Pass \\$foo unchanged.');
    expect($tokens)->toHaveCount(1)
        ->and($tokens[0]->value)->toBe('Pass $foo unchanged.');
});

it('treats a bare `$` followed by non-placeholder text as a literal', function (): void {
    // `$@` and `$!` aren't valid placeholders — neither digits nor a
    // word-starting char follow. The `$` is preserved literally.
    $tokens = (new ArgumentParser())->parse('Special: $@ and $! here.');
    expect($tokens)->toHaveCount(1)
        ->and($tokens[0]->value)->toBe('Special: $@ and $! here.');
});

it('parses `$N` for ANY positive integer N (no upper bound on N)', function (): void {
    // A body like `Cost: $100.` parses `$100` as positional position
    // 100 — that's a legitimate (if odd) command shape. Use `\$100` if
    // you really mean the literal dollar amount.
    $tokens = (new ArgumentParser())->parse('Cost: $100.');
    $positional = array_values(array_filter($tokens, static fn (ArgumentToken $t) => $t->kind === ArgumentToken::KIND_POSITIONAL));
    expect($positional)->toHaveCount(1)
        ->and($positional[0]->position)->toBe(100);
});

it('handles a body with all four placeholder kinds + escapes intermixed', function (): void {
    $tokens = (new ArgumentParser())->parse('All: $ARGUMENTS / first: $1 / named: $issue / literal: \\$2');
    $kinds = array_map(static fn (ArgumentToken $t) => $t->kind, $tokens);
    expect($kinds)->toContain(ArgumentToken::KIND_ARGUMENTS)
        ->and($kinds)->toContain(ArgumentToken::KIND_POSITIONAL)
        ->and($kinds)->toContain(ArgumentToken::KIND_NAMED);
    $reconstructed = '';
    foreach ($tokens as $t) {
        $reconstructed .= $t->kind === ArgumentToken::KIND_LITERAL ? $t->value : '<PH>';
    }

    expect($reconstructed)->toContain('literal: $2');
});
