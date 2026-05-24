<?php declare(strict_types=1);

use SanderMuller\BoostCore\Env;

afterEach(function (): void {
    putenv('BOOST_TEST_FLAG');
});

it('treats unset env vars as disabled', function (): void {
    putenv('BOOST_TEST_FLAG');
    expect(Env::flagEnabled('BOOST_TEST_FLAG'))->toBeFalse();
});

it('treats empty string as disabled', function (): void {
    putenv('BOOST_TEST_FLAG=');
    expect(Env::flagEnabled('BOOST_TEST_FLAG'))->toBeFalse();
});

it('treats canonical "off" values as disabled', function (string $value): void {
    putenv("BOOST_TEST_FLAG={$value}");
    expect(Env::flagEnabled('BOOST_TEST_FLAG'))->toBeFalse(
        "expected {$value} to be disabled — a presence-only check would wrongly enable",
    );
})->with(['0', 'false', 'False', 'FALSE', 'no', 'off']);

it('treats canonical "on" values as enabled', function (string $value): void {
    putenv("BOOST_TEST_FLAG={$value}");
    expect(Env::flagEnabled('BOOST_TEST_FLAG'))->toBeTrue();
})->with(['1', 'true', 'True', 'TRUE', 'yes', 'on', 'ON']);

it('treats unknown values as disabled (conservative — no surprise enables)', function (): void {
    putenv('BOOST_TEST_FLAG=maybe');
    expect(Env::flagEnabled('BOOST_TEST_FLAG'))->toBeFalse();
});
