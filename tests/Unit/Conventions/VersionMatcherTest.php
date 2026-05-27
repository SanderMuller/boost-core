<?php declare(strict_types=1);

use SanderMuller\BoostCore\Conventions\VersionMatcher;

beforeEach(function (): void {
    $this->matcher = new VersionMatcher();
});

it('satisfies wildcard for null/empty/star', function (): void {
    expect($this->matcher->satisfies(1, null))->toBeTrue()
        ->and($this->matcher->satisfies(1, ''))
        ->toBeTrue()
        ->and($this->matcher->satisfies(1, '*'))
        ->toBeTrue()
        ->and($this->matcher->satisfies(99, '*'))
        ->toBeTrue();
});

it('satisfies a caret range that includes the host', function (): void {
    expect($this->matcher->satisfies(1, '^1'))->toBeTrue()
        ->and($this->matcher->satisfies(2, '^2'))
        ->toBeTrue();
});

it('rejects a caret range that excludes the host', function (): void {
    expect($this->matcher->satisfies(1, '^2'))->toBeFalse()
        ->and($this->matcher->satisfies(2, '^1'))
        ->toBeFalse();
});

it('satisfies a transitional OR range', function (): void {
    expect($this->matcher->satisfies(1, '^1||^2'))->toBeTrue()
        ->and($this->matcher->satisfies(2, '^1||^2'))
        ->toBeTrue()
        ->and($this->matcher->satisfies(3, '^1||^2'))
        ->toBeFalse();
});

it('satisfies a greater-or-equal range', function (): void {
    expect($this->matcher->satisfies(3, '>=3'))->toBeTrue()
        ->and($this->matcher->satisfies(4, '>=3'))
        ->toBeTrue()
        ->and($this->matcher->satisfies(2, '>=3'))
        ->toBeFalse();
});

it('minRequired extracts the lower major from a caret range', function (): void {
    expect($this->matcher->minRequired('^1'))->toBe(1)
        ->and($this->matcher->minRequired('^2'))
        ->toBe(2);
});

it('minRequired returns the lower major from an OR range', function (): void {
    expect($this->matcher->minRequired('^1||^2'))->toBe(1)
        ->and($this->matcher->minRequired('^2||^3'))
        ->toBe(2);
});

it('minRequired returns the major from a greater-or-equal range', function (): void {
    expect($this->matcher->minRequired('>=3'))->toBe(3);
});

it('minRequired returns null for wildcard or null/empty', function (): void {
    expect($this->matcher->minRequired(null))->toBeNull()
        ->and($this->matcher->minRequired(''))
        ->toBeNull()
        ->and($this->matcher->minRequired('*'))
        ->toBeNull();
});

it('minRequired returns null for an unparseable constraint', function (): void {
    expect($this->matcher->minRequired('not-a-real-constraint!!!'))->toBeNull();
});
