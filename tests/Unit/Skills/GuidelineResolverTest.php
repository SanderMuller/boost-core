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

it('#86: vendor/injected emission order is deterministic + host order preserved', function (): void {
    $resolver = new GuidelineResolver();
    $shadows = [];

    // Host arrives loader-sorted (deterministic in production) — fed identically
    // both runs. The real non-determinism is the vendor-scan map order +
    // within-vendor (unsorted injected) order, SCRAMBLED between the two runs.
    $host = [grGuideline('a-host', null), grGuideline('z-host', null)];

    $a = $resolver->resolve($host, [
        'acme/beta' => [grGuideline('migrations', 'acme/beta'), grGuideline('database', 'acme/beta')],
        'acme/alpha' => [grGuideline('pint', 'acme/alpha')],
    ], false, $shadows);
    $b = $resolver->resolve($host, [
        'acme/alpha' => [grGuideline('pint', 'acme/alpha')],
        'acme/beta' => [grGuideline('database', 'acme/beta'), grGuideline('migrations', 'acme/beta')],
    ], false, $shadows);

    $namesA = [];
    foreach ($a as $g) {
        $namesA[] = ($g->sourceVendor ?? 'host') . ':' . $g->name;
    }

    $namesB = [];
    foreach ($b as $g) {
        $namesB[] = ($g->sourceVendor ?? 'host') . ':' . $g->name;
    }

    // Byte-identical resolved order despite scrambled vendor input.
    expect($namesA)->toBe($namesB);
    // Host first (loader order preserved), then vendors in (vendor, sourcePath) order.
    expect($namesA)->toBe([
        'host:a-host',
        'host:z-host',
        'acme/alpha:pint',
        'acme/beta:database',
        'acme/beta:migrations',
    ]);
});

it('#86: host guideline order is PRESERVED (not re-sorted by path) when vendors are present', function (): void {
    $resolver = new GuidelineResolver();
    $shadows = [];

    // Host fed in a non-alphabetical order (the loader's filename order can differ
    // from frontmatter `name` order). The fix must keep this exact order.
    $resolved = $resolver->resolve(
        [grGuideline('zeta', null), grGuideline('alpha', null)],
        ['acme/pack' => [grGuideline('vendored', 'acme/pack')]],
        false,
        $shadows,
    );

    expect($resolved[0]->name)->toBe('zeta')
        ->and($resolved[1]->name)->toBe('alpha')
        ->and($resolved[2]->sourceVendor)->toBe('acme/pack');
});
