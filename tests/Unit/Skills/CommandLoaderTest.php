<?php declare(strict_types=1);

use SanderMuller\BoostCore\Skills\Command;
use SanderMuller\BoostCore\Skills\CommandLoader;
use SanderMuller\BoostCore\Skills\FrontmatterParser;

/**
 * Write command files into a throwaway directory, load them, return the list.
 *
 * @param  array<string, string>  $files  filename => contents
 * @return list<Command>
 */
function loadCommandFiles(array $files): array
{
    $dir = sys_get_temp_dir() . '/boost-cmd-loader-' . bin2hex(random_bytes(8));
    mkdir($dir, 0o755, recursive: true);

    foreach ($files as $name => $contents) {
        file_put_contents($dir . '/' . $name, $contents);
    }

    try {
        return iterator_to_array((new CommandLoader(new FrontmatterParser()))->load($dir), false);
    } finally {
        cleanupTestDir($dir);
    }
}

it('returns nothing for a missing directory', function (): void {
    $loaded = iterator_to_array(
        (new CommandLoader(new FrontmatterParser()))->load('/no-such-boost-commands-dir'),
        false,
    );

    expect($loaded)->toBeEmpty();
});

it('loads a command, parsing frontmatter, description, and body', function (): void {
    $commands = loadCommandFiles([
        'deploy.md' => "---\ndescription: Ship it.\n---\n\nRun the deploy.\n",
    ]);

    expect($commands)->toHaveCount(1)
        ->and($commands[0])->toBeInstanceOf(Command::class)
        ->and($commands[0]->name)->toBe('deploy')
        ->and($commands[0]->description)->toBe('Ship it.')
        ->and($commands[0]->body)->toContain('Run the deploy.')
        ->and($commands[0]->sourceVendor)->toBeNull()
        ->and($commands[0]->isHostAuthored())->toBeTrue();
});

it('takes the name from frontmatter when present, else the filename', function (): void {
    $commands = loadCommandFiles([
        'a-file.md' => "---\nname: renamed\n---\nBody.\n",
        'b-file.md' => "No frontmatter here.\n",
    ]);

    expect(array_map(static fn (Command $c): string => $c->name, $commands))
        ->toBe(['renamed', 'b-file']);
});

it('loads only .md files, sorted by name', function (): void {
    $commands = loadCommandFiles([
        'zeta.md' => "Z.\n",
        'alpha.md' => "A.\n",
        'notes.txt' => "ignored — not markdown\n",
    ]);

    expect(array_map(static fn (Command $c): string => $c->name, $commands))
        ->toBe(['alpha', 'zeta']);
});

it('parses metadata.boost-tags into tags (inert until vendor commands ship)', function (): void {
    $commands = loadCommandFiles([
        'tagged.md' => "---\nmetadata:\n  boost-tags: \"php\"\n---\nBody.\n",
    ]);

    expect($commands[0]->tags)->toBe(['php'])
        ->and($commands[0]->tagsValid)->toBeTrue();
});
