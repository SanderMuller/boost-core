<?php declare(strict_types=1);

use SanderMuller\BoostCore\Skills\Guideline;
use SanderMuller\BoostCore\Skills\GuidelineResolver;

function grGuideline(string $name, ?string $vendor): Guideline
{
    return new Guideline(
        name: $name,
        description: null,
        frontmatter: [],
        body: 'body',
        sourcePath: ($vendor ?? 'host') . '/' . $name,
        sourceVendor: $vendor,
    );
}

it('host guideline wins and records a shadow over a same-named vendor guideline', function (): void {
    $shadows = [];
    $resolved = (new GuidelineResolver())->resolve(
        [grGuideline('release-automation', null)],
        ['acme/pack' => [grGuideline('release-automation', 'acme/pack')]],
        false,
        $shadows,
    );

    // Host copy is the one that survives.
    expect($resolved)->toHaveCount(1)
        ->and($resolved[0]->isHostAuthored())->toBeTrue()
        // Shadow recorded for surfacing in `boost where` / `boost sync`.
        ->and($shadows)->toBe([['guideline' => 'release-automation', 'shadowedVendor' => 'acme/pack']]);
});

it('records NO shadow when host and vendor guidelines have different names', function (): void {
    $shadows = [];
    (new GuidelineResolver())->resolve(
        [grGuideline('host-only', null)],
        ['acme/pack' => [grGuideline('vendor-only', 'acme/pack')]],
        false,
        $shadows,
    );

    expect($shadows)
        ->toBeEmpty();
});

it('records a shadow event PER vendor when multiple vendors publish the same guideline name (codex P2 — no collapse)', function (): void {
    $shadows = [];
    (new GuidelineResolver())->resolve(
        [grGuideline('release-automation', null)],
        [
            'acme/a' => [grGuideline('release-automation', 'acme/a')],
            'acme/b' => [grGuideline('release-automation', 'acme/b')],
        ],
        false,
        $shadows,
    );

    expect($shadows)->toBe([
        ['guideline' => 'release-automation', 'shadowedVendor' => 'acme/a'],
        ['guideline' => 'release-automation', 'shadowedVendor' => 'acme/b'],
    ]);
});

it('records NO shadow when there is no host guideline of the vendor name (vendor simply ships)', function (): void {
    $shadows = [];
    $resolved = (new GuidelineResolver())->resolve(
        [],
        ['acme/pack' => [grGuideline('release-automation', 'acme/pack')]],
        false,
        $shadows,
    );

    expect($shadows)
        ->toBeEmpty()
        ->and($resolved)->toHaveCount(1)
        ->and($resolved[0]->isHostAuthored())->toBeFalse();
});
