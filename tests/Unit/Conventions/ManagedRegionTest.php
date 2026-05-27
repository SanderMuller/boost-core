<?php declare(strict_types=1);

use SanderMuller\BoostCore\Conventions\ManagedRegion;

function makeRegion(?string $note = null): ManagedRegion
{
    return new ManagedRegion(
        start: '<!-- boost-core:test:start -->',
        end: '<!-- boost-core:test:end -->',
        note: $note,
    );
}

it('no-ops when region missing and body empty', function (): void {
    $result = makeRegion()->render('# existing content', '');

    expect($result)->toBeNull();
});

it('no-ops when both existing and body are empty', function (): void {
    $result = makeRegion()->render(null, '');

    expect($result)->toBeNull();
});

it('appends a new region to the end of a non-terminated existing file', function (): void {
    $result = makeRegion()->render('# header', 'body line');

    expect($result)->toBe("# header\n<!-- boost-core:test:start -->\nbody line\n<!-- boost-core:test:end -->\n");
});

it('appends a new region to a newline-terminated existing file without extra separator', function (): void {
    $result = makeRegion()->render("# header\n", 'body line');

    expect($result)->toBe("# header\n<!-- boost-core:test:start -->\nbody line\n<!-- boost-core:test:end -->\n");
});

it('creates a fresh-file region when existing is null', function (): void {
    $result = makeRegion()->render(null, 'body line');

    expect($result)->toBe("<!-- boost-core:test:start -->\nbody line\n<!-- boost-core:test:end -->\n");
});

it('emits the optional note line between start marker and body', function (): void {
    $result = makeRegion('# explainer')->render(null, 'body line');

    expect($result)->toBe("<!-- boost-core:test:start -->\n# explainer\nbody line\n<!-- boost-core:test:end -->\n");
});

it('strips the region when body is empty and region exists', function (): void {
    $existing = "# before\n<!-- boost-core:test:start -->\nold body\n<!-- boost-core:test:end -->\n# after\n";

    $result = makeRegion()->render($existing, '');

    expect($result)->toBe("# before\n# after\n");
});

it('rebuilds the region in place when body changes', function (): void {
    $existing = "# before\n<!-- boost-core:test:start -->\nold body\n<!-- boost-core:test:end -->\n# after\n";

    $result = makeRegion()->render($existing, 'new body');

    expect($result)->toBe("# before\n<!-- boost-core:test:start -->\nnew body\n<!-- boost-core:test:end -->\n# after\n");
});

it('returns null when rebuild would produce identical output', function (): void {
    $existing = "<!-- boost-core:test:start -->\nbody\n<!-- boost-core:test:end -->\n";

    $result = makeRegion()->render($existing, 'body');

    expect($result)->toBeNull();
});

it('strips region via dedicated strip() and returns null on missing', function (): void {
    expect(makeRegion()->strip(null))->toBeNull()
        ->and(makeRegion()
            ->strip('# no marker here'))
        ->toBeNull();

    $existing = "# before\n<!-- boost-core:test:start -->\nbody\n<!-- boost-core:test:end -->\n# after\n";
    expect(makeRegion()->strip($existing))->toBe("# before\n# after\n");
});

it('extract returns null when region missing', function (): void {
    expect(makeRegion()->extract(null))->toBeNull()
        ->and(makeRegion()
            ->extract(''))
        ->toBeNull()
        ->and(makeRegion()
            ->extract('# no markers'))
        ->toBeNull();
});

it('extract returns body between markers, excluding marker lines', function (): void {
    $existing = "# before\n<!-- boost-core:test:start -->\nbody line one\nbody line two\n<!-- boost-core:test:end -->\n# after\n";

    expect(makeRegion()->extract($existing))->toBe("body line one\nbody line two");
});

it('extract excludes the note line when present', function (): void {
    $existing = "<!-- boost-core:test:start -->\n# explainer\nactual body\n<!-- boost-core:test:end -->\n";

    expect(makeRegion('# explainer')->extract($existing))->toBe('actual body');
});

it('extract leaves non-matching note prefix intact (note line is positional)', function (): void {
    $existing = "<!-- boost-core:test:start -->\n# wrong explainer\nactual body\n<!-- boost-core:test:end -->\n";

    expect(makeRegion('# right explainer')->extract($existing))->toBe("# wrong explainer\nactual body");
});

it('extract handles empty body between immediately-adjacent markers', function (): void {
    $existing = "<!-- boost-core:test:start -->\n<!-- boost-core:test:end -->\n";

    expect(makeRegion()->extract($existing))
        ->toBeEmpty();
});

it('preserves content before and after the region during rebuild', function (): void {
    $existing = "# header line\n\nintro paragraph\n<!-- boost-core:test:start -->\nold body\n<!-- boost-core:test:end -->\n\n## another section\nmore content\n";

    $result = makeRegion()->render($existing, 'replaced body');

    expect($result)->toBe("# header line\n\nintro paragraph\n<!-- boost-core:test:start -->\nreplaced body\n<!-- boost-core:test:end -->\n\n## another section\nmore content\n");
});
