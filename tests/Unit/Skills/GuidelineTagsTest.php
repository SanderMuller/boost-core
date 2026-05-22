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

/**
 * Write each file into a throwaway directory, load every guideline via
 * `GuidelineLoader`, return them.
 *
 * @param  array<string, string>  $files  filename → contents (may include `.boost-tags.yaml`)
 * @return list<Guideline>
 */
function loadGuidelinesWithFiles(array $files): array
{
    $dir = sys_get_temp_dir() . '/boost-gl-manifest-' . bin2hex(random_bytes(8));
    mkdir($dir, 0o755, recursive: true);

    foreach ($files as $name => $contents) {
        file_put_contents($dir . '/' . $name, $contents);
    }

    try {
        return iterator_to_array((new GuidelineLoader(new FrontmatterParser()))->load($dir), false);
    } finally {
        cleanupTestDir($dir);
    }
}

it('tags a frontmatter-free guideline from the .boost-tags.yaml manifest', function (): void {
    $guidelines = loadGuidelinesWithFiles([
        'database-safety.md' => "# Database Safety\n\nBody.\n",
        '.boost-tags.yaml' => "database-safety.md: \"database\"\n",
    ]);

    expect($guidelines)->toHaveCount(1)
        ->and($guidelines[0]->name)->toBe('database-safety')
        ->and($guidelines[0]->tags)->toBe(['database'])
        ->and($guidelines[0]->tagsValid)->toBeTrue();
});

it('lets frontmatter metadata.boost-tags win over the manifest', function (): void {
    $guidelines = loadGuidelinesWithFiles([
        'g.md' => "---\nmetadata:\n  boost-tags: \"php\"\n---\n# G\n",
        '.boost-tags.yaml' => "g.md: \"database\"\n",
    ]);

    expect($guidelines[0]->tags)->toBe(['php']);
});

it('leaves a guideline untagged when neither frontmatter nor manifest names it', function (): void {
    $guidelines = loadGuidelinesWithFiles([
        'g.md' => "# G\n\nBody.\n",
        '.boost-tags.yaml' => "other.md: \"database\"\n",
    ]);

    expect($guidelines[0]->tags)
        ->toBeEmpty()
        ->and($guidelines[0]->tagsValid)->toBeTrue();
});

it('fails a frontmatter-silent guideline closed when the manifest is unparseable', function (): void {
    $guidelines = loadGuidelinesWithFiles([
        'g.md' => "# G\n\nBody.\n",
        '.boost-tags.yaml' => "g.md: [unterminated\n",
    ]);

    expect($guidelines[0]->tags)
        ->toBeEmpty()
        ->and($guidelines[0]->tagsValid)->toBeFalse();
});

it('does not load .boost-tags.yaml itself as a guideline', function (): void {
    $guidelines = loadGuidelinesWithFiles([
        'g.md' => "# G\n",
        '.boost-tags.yaml' => "g.md: \"php\"\n",
    ]);

    expect($guidelines)->toHaveCount(1)
        ->and($guidelines[0]->name)->toBe('g');
});
