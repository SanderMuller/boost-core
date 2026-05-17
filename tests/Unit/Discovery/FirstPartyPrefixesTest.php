<?php

declare(strict_types=1);

use SanderMuller\BoostCore\Discovery\FirstPartyPrefixes;

beforeEach(function (): void {
    $this->prefixes = new FirstPartyPrefixes;
});

it('matches `boost-*` packages', function (): void {
    expect($this->prefixes->matches('sandermuller/boost-core'))->toBeTrue();
    expect($this->prefixes->matches('sandermuller/boost-anything'))->toBeTrue();
});

it('matches `package-boost-*` variants but not the retired stub', function (): void {
    expect($this->prefixes->matches('sandermuller/package-boost-php'))->toBeTrue();
    expect($this->prefixes->matches('sandermuller/package-boost-laravel'))->toBeTrue();
    expect($this->prefixes->matches('sandermuller/package-boost'))->toBeFalse();
});

it('matches `project-boost` exactly and as a prefix', function (): void {
    expect($this->prefixes->matches('sandermuller/project-boost'))->toBeTrue();
    expect($this->prefixes->matches('sandermuller/project-boost-doctrine'))->toBeTrue();
    expect($this->prefixes->matches('sandermuller/project-boost-symfony'))->toBeTrue();
});

it('does not match unrelated packages', function (): void {
    expect($this->prefixes->matches('random/package'))->toBeFalse();
    expect($this->prefixes->matches('sandermuller/other-thing'))->toBeFalse();
    expect($this->prefixes->matches('hihaho/laravel-js-store'))->toBeFalse();
});

it('exposes the prefix and exact lists for diagnostics', function (): void {
    expect($this->prefixes->prefixes())->toBeArray()->not->toBeEmpty();
    expect($this->prefixes->exact())->toContain('sandermuller/project-boost');
});
