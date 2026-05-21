<?php declare(strict_types=1);

use SanderMuller\BoostCore\Skills\FrontmatterParser;
use SanderMuller\BoostCore\Skills\Guideline;
use SanderMuller\BoostCore\Skills\GuidelineLoader;

/**
 * Write one guideline file to a throwaway directory, load it, return the
 * single resulting Guideline.
 */
function loadOneGuideline(string $content): Guideline
{
    $dir = sys_get_temp_dir() . '/boost-gl-tags-' . bin2hex(random_bytes(8));
    mkdir($dir, 0o755, recursive: true);
    file_put_contents($dir . '/sample.md', $content);

    try {
        $loaded = iterator_to_array((new GuidelineLoader(new FrontmatterParser()))->load($dir), false);
    } finally {
        @unlink($dir . '/sample.md');
        @rmdir($dir);
    }

    expect($loaded)->toHaveCount(1);

    return $loaded[0];
}

it('loads a guideline with no frontmatter as untagged and valid', function (): void {
    $guideline = loadOneGuideline("# Sample\n\nBody.\n");

    expect($guideline->tags)
        ->toBeEmpty()
        ->and($guideline->tagsValid)->toBeTrue();
});

it('parses metadata.boost-tags from guideline frontmatter', function (): void {
    $guideline = loadOneGuideline("---\nmetadata:\n  boost-tags: \"php jira\"\n---\n\n# Sample\n\nBody.\n");

    expect($guideline->tags)->toBe(['php', 'jira'])
        ->and($guideline->tagsValid)->toBeTrue();
});

it('marks a guideline tag-invalid when boost-tags is malformed', function (): void {
    $guideline = loadOneGuideline("---\nmetadata:\n  boost-tags:\n    - php\n---\n\n# Sample\n\nBody.\n");

    expect($guideline->tags)
        ->toBeEmpty()
        ->and($guideline->tagsValid)->toBeFalse();
});
