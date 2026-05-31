<?php declare(strict_types=1);

use SanderMuller\BoostCore\Config\BoostConfig;
use SanderMuller\BoostCore\Conventions\EmittedAgentFiles;
use SanderMuller\BoostCore\Enums\Agent;

/**
 * @param  list<Agent>  $agents
 */
function emittedFilesConfig(array $agents): BoostConfig
{
    return new BoostConfig(
        agents: $agents,
        allowedVendors: [],
        skillsPath: '.ai/skills',
        guidelinesPath: '.ai/guidelines',
        commandsPath: '.ai/commands',
        disabledEmitters: [],
    );
}

function emittedFilesTempProject(): string
{
    $dir = sys_get_temp_dir() . '/boost-emitted-' . bin2hex(random_bytes(8));
    mkdir($dir . '/.claude/skills/foo', 0o755, recursive: true);
    mkdir($dir . '/.cursor/rules/bar', 0o755, recursive: true);
    mkdir($dir . '/.ai/skills/baz', 0o755, recursive: true);

    file_put_contents($dir . '/CLAUDE.md', "# Claude\n");
    file_put_contents($dir . '/.claude/skills/foo/SKILL.md', "# Foo skill\n");
    file_put_contents($dir . '/.cursor/rules/bar/SKILL.md', "# Bar skill\n");
    // A source file with a token — must NEVER be scanned.
    file_put_contents($dir . '/.ai/skills/baz/SKILL.md', '<!--boost:conv path="x.y" mode="inline"-->');

    return $dir;
}

function emittedFilesCleanup(string $dir): void
{
    if (! is_dir($dir)) {
        return;
    }

    $iter = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($dir, FilesystemIterator::SKIP_DOTS),
        RecursiveIteratorIterator::CHILD_FIRST,
    );
    /** @var SplFileInfo $f */
    foreach ($iter as $f) {
        $f->isDir() ? @rmdir($f->getPathname()) : @unlink($f->getPathname());
    }

    @rmdir($dir);
}

it('enumerates guidance + per-agent skills for active agents, excluding sources', function (): void {
    $dir = emittedFilesTempProject();

    try {
        $files = EmittedAgentFiles::default()->forConfig($dir, emittedFilesConfig([Agent::CLAUDE_CODE]));
        $relatives = array_map(static fn (array $f): string => $f['relative'], $files);

        expect($relatives)->toContain('CLAUDE.md')
            ->toContain('.claude/skills/foo/SKILL.md');
        // .ai/ sources are never scanned (they legitimately carry tokens).
        expect($relatives)->not->toContain('.ai/skills/baz/SKILL.md');
        expect($relatives)->each->not->toStartWith('.cursor/');
    } finally {
        emittedFilesCleanup($dir);
    }
});

it('returns nothing when no agents are active', function (): void {
    $dir = emittedFilesTempProject();

    try {
        expect(EmittedAgentFiles::default()->forConfig($dir, emittedFilesConfig([])))
            ->toBeEmpty();
    } finally {
        emittedFilesCleanup($dir);
    }
});

it('returns absolute + relative paths that resolve to existing files', function (): void {
    $dir = emittedFilesTempProject();

    try {
        $files = EmittedAgentFiles::default()->forConfig($dir, emittedFilesConfig([Agent::CLAUDE_CODE]));

        expect($files)->not->toBeEmpty();
        foreach ($files as $file) {
            expect($file['absolute'])
                ->toBeFile();
        }
    } finally {
        emittedFilesCleanup($dir);
    }
});
