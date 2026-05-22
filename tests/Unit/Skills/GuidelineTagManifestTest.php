<?php declare(strict_types=1);

use SanderMuller\BoostCore\Skills\GuidelineTagManifest;

/**
 * Write `.boost-tags.yaml` (when $yaml is non-null) into a throwaway dir,
 * load the manifest from it, return it.
 */
function loadManifest(?string $yaml): GuidelineTagManifest
{
    $dir = sys_get_temp_dir() . '/boost-gtm-' . bin2hex(random_bytes(8));
    mkdir($dir, 0o755, recursive: true);

    if ($yaml !== null) {
        file_put_contents($dir . '/.boost-tags.yaml', $yaml);
    }

    try {
        return GuidelineTagManifest::load($dir);
    } finally {
        cleanupTestDir($dir);
    }
}

it('treats an absent manifest as tagging nothing', function (): void {
    expect(loadManifest(null)->tagsFor('database-safety.md'))->toBe([[], true]);
});

it('tags a guideline named in the manifest', function (): void {
    $manifest = loadManifest("database-safety.md: \"database\"\nmigrations.md: \"database\"\n");

    expect($manifest->tagsFor('database-safety.md'))->toBe([['database'], true])
        ->and($manifest->tagsFor('migrations.md'))->toBe([['database'], true]);
});

it('leaves a guideline the manifest does not name untagged', function (): void {
    $manifest = loadManifest("database-safety.md: \"database\"\n");

    expect($manifest->tagsFor('verification-before-completion.md'))->toBe([[], true]);
});

it('parses a multi-tag manifest value with the skill-tag grammar', function (): void {
    $manifest = loadManifest("x.md: \"php  GITHUB php\"\n");

    expect($manifest->tagsFor('x.md'))->toBe([['php', 'github'], true]);
});

it('treats a comment-only manifest as usable and tagging nothing', function (): void {
    expect(loadManifest("# only a comment\n")->tagsFor('x.md'))->toBe([[], true]);
});

it('fails every guideline closed when the manifest is not valid YAML', function (): void {
    $manifest = loadManifest("x.md: [unterminated\n");

    expect($manifest->tagsFor('x.md'))->toBe([[], false])
        ->and($manifest->tagsFor('anything-else.md'))->toBe([[], false]);
});

it('fails every guideline closed when the manifest is a YAML sequence, not a map', function (): void {
    expect(loadManifest("- just\n- a\n- list\n")->tagsFor('x.md'))->toBe([[], false]);
});

it('fails every guideline closed when the manifest is a bare scalar', function (): void {
    expect(loadManifest("42\n")->tagsFor('x.md'))->toBe([[], false]);
});

it('fails only the offending entry closed when its value is not a string', function (): void {
    $manifest = loadManifest("good.md: \"php\"\nbad.md:\n  - php\n");

    expect($manifest->tagsFor('good.md'))->toBe([['php'], true])
        ->and($manifest->tagsFor('bad.md'))->toBe([[], false]);
});
