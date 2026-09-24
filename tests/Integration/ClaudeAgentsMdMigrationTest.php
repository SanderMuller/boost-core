<?php declare(strict_types=1);

use SanderMuller\BoostCore\Conventions\Diagnostic;
use SanderMuller\BoostCore\Sync\InstalledPackages;
use SanderMuller\BoostCore\Sync\PackageInfo;
use SanderMuller\BoostCore\Sync\SyncEngine;
use SanderMuller\BoostCore\Sync\SyncManifest;
use SanderMuller\BoostCore\Sync\SyncResult;
use SanderMuller\BoostCore\Sync\WriteAction;

function makeAgentsMdProject(): string
{
    $root = sys_get_temp_dir() . '/boost-agentsmd-' . bin2hex(random_bytes(8));
    mkdir($root . '/.ai/guidelines', 0o755, recursive: true);
    file_put_contents($root . '/boost.php', "<?php\n\ndeclare(strict_types=1);\n\nuse SanderMuller\\BoostCore\\Config\\BoostConfig;\nuse SanderMuller\\BoostCore\\Enums\\Agent;\n\nreturn BoostConfig::configure()->withAgents([Agent::CLAUDE_CODE]);\n");
    file_put_contents($root . '/.ai/guidelines/rules.md', "# Rules\n\nUse strict types.");

    return $root;
}

function removeAgentsMdProject(string $root): void
{
    if (! is_dir($root)) {
        return;
    }

    $iterator = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS),
        RecursiveIteratorIterator::CHILD_FIRST,
    );
    /** @var SplFileInfo $entry */
    foreach ($iterator as $entry) {
        $entry->isDir() && ! $entry->isLink() ? rmdir($entry->getPathname()) : unlink($entry->getPathname());
    }

    rmdir($root);
}

/**
 * Simulate a pre-1.12 sync: a boost-owned `CLAUDE.md` recorded in the
 * manifest with the sha of its current content.
 */
function seedOwnedClaudeMd(string $root, string $content): void
{
    file_put_contents($root . '/CLAUDE.md', $content);
    mkdir($root . '/' . SyncManifest::DIR, 0o755, recursive: true);
    file_put_contents($root . '/' . SyncManifest::DIR . '/manifest.json', json_encode([
        'version' => 1,
        'emitted' => [
            'CLAUDE.md' => ['sha256' => hash('sha256', $content), 'category' => SyncManifest::CATEGORY_GUIDANCE, 'provenance' => SyncManifest::PROVENANCE_ENGINE],
        ],
    ], JSON_PRETTY_PRINT));
}

/**
 * @return list<Diagnostic>
 */
function shadowWarnings(SyncResult $result): array
{
    return array_values(array_filter(
        $result->diagnostics,
        static fn (Diagnostic $diagnostic): bool => str_contains($diagnostic->message, 'Claude Code then reads only the CLAUDE.md files'),
    ));
}

it('writes Claude Code guidance to AGENTS.md and creates no CLAUDE.md', function (): void {
    $root = makeAgentsMdProject();
    try {
        $result = SyncEngine::default(new InstalledPackages([]))->sync($root);

        expect($result->hasErrors())->toBeFalse()
            ->and(file_get_contents($root . '/AGENTS.md'))->toContain('Use strict types.')
            ->and($root . '/CLAUDE.md')->not->toBeFile()
            ->and(shadowWarnings($result))
            ->toBeEmpty();
    } finally {
        removeAgentsMdProject($root);
    }
});

it('reaps a boost-owned CLAUDE.md from a pre-1.12 sync and writes AGENTS.md in the same sync', function (): void {
    $root = makeAgentsMdProject();
    try {
        seedOwnedClaudeMd($root, "# Rules\n\nUse strict types.\n");

        $result = SyncEngine::default(new InstalledPackages([]))->sync($root);

        expect($result->hasErrors())->toBeFalse()
            ->and($root . '/CLAUDE.md')->not->toBeFile()
            ->and(file_get_contents($root . '/AGENTS.md'))->toContain('Use strict types.')
            ->and($result->countByAction(WriteAction::DELETED))->toBe(1)
            ->and(shadowWarnings($result))
            ->toBeEmpty();
    } finally {
        removeAgentsMdProject($root);
    }
});

it('previews the CLAUDE.md reap under --check without deleting it or warning about it', function (): void {
    $root = makeAgentsMdProject();
    try {
        seedOwnedClaudeMd($root, "# Rules\n\nUse strict types.\n");

        $result = SyncEngine::default(new InstalledPackages([]))->sync($root, checkOnly: true);

        expect($root . '/CLAUDE.md')->toBeFile()
            ->and($result->countByAction(WriteAction::WOULD_DELETE))->toBe(1)
            ->and(shadowWarnings($result))
            ->toBeEmpty();
    } finally {
        removeAgentsMdProject($root);
    }
});

it('keeps an operator-edited CLAUDE.md and warns that it shadows AGENTS.md', function (): void {
    $root = makeAgentsMdProject();
    try {
        seedOwnedClaudeMd($root, "# Rules\n\nUse strict types.\n");
        file_put_contents($root . '/CLAUDE.md', "# Rules\n\nUse strict types.\n\nOperator addition.\n");

        $result = SyncEngine::default(new InstalledPackages([]))->sync($root);

        expect(file_get_contents($root . '/CLAUDE.md'))->toContain('Operator addition.')
            ->and(file_get_contents($root . '/AGENTS.md'))->toContain('Use strict types.')
            ->and(shadowWarnings($result))->toHaveCount(1)
            ->and(shadowWarnings($result)[0]->message)->toContain('`CLAUDE.md`')
            ->and(shadowWarnings($result)[0]->level)->toBe('warning');
    } finally {
        removeAgentsMdProject($root);
    }
});

it('warns for a CLAUDE.local.md or .claude/CLAUDE.md', function (string $file): void {
    $root = makeAgentsMdProject();
    try {
        @mkdir(dirname($root . '/' . $file), 0o755, recursive: true);
        file_put_contents($root . '/' . $file, "Personal notes.\n");

        $result = SyncEngine::default(new InstalledPackages([]))->sync($root);

        expect(shadowWarnings($result))->toHaveCount(1)
            ->and(shadowWarnings($result)[0]->message)->toContain('`' . $file . '`');
    } finally {
        removeAgentsMdProject($root);
    }
})->with(['CLAUDE.local.md', '.claude/CLAUDE.md']);

it('does not warn when the CLAUDE.md imports AGENTS.md', function (string $import): void {
    $root = makeAgentsMdProject();
    try {
        file_put_contents($root . '/CLAUDE.md', "# Project\n\n{$import}\n");

        $result = SyncEngine::default(new InstalledPackages([]))->sync($root);

        expect(shadowWarnings($result))
            ->toBeEmpty()
            ->and(file_get_contents($root . '/CLAUDE.md'))->toContain($import);
    } finally {
        removeAgentsMdProject($root);
    }
})->with(['@AGENTS.md', '@./AGENTS.md', 'See @AGENTS.md for the rules.']);

it('does not warn when Claude Code is not an active agent', function (): void {
    $root = makeAgentsMdProject();
    try {
        file_put_contents($root . '/boost.php', "<?php\n\ndeclare(strict_types=1);\n\nuse SanderMuller\\BoostCore\\Config\\BoostConfig;\nuse SanderMuller\\BoostCore\\Enums\\Agent;\n\nreturn BoostConfig::configure()->withAgents([Agent::CODEX]);\n");
        file_put_contents($root . '/CLAUDE.md', "# Hand-written\n");

        $result = SyncEngine::default(new InstalledPackages([]))->sync($root);

        expect(shadowWarnings($result))
            ->toBeEmpty();
    } finally {
        removeAgentsMdProject($root);
    }
});

it('keeps a pre-0.12 marker-bounded CLAUDE.md and warns that it shadows AGENTS.md', function (): void {
    $root = makeAgentsMdProject();
    try {
        $legacy = "<!-- boost-core:guidelines:start -->\n# Old rules\n<!-- boost-core:guidelines:end -->\n";
        file_put_contents($root . '/CLAUDE.md', $legacy);

        $result = SyncEngine::default(new InstalledPackages([]))->sync($root);

        expect(file_get_contents($root . '/CLAUDE.md'))->toBe($legacy)
            ->and(file_get_contents($root . '/AGENTS.md'))->toContain('Use strict types.')
            ->and(shadowWarnings($result))->toHaveCount(1);
    } finally {
        removeAgentsMdProject($root);
    }
});

it('adds the conventions section to the Codex AGENTS.md when Claude Code is not active, and keeps the guideline body', function (): void {
    $root = makeAgentsMdProject();
    $vendor = sys_get_temp_dir() . '/boost-agentsmd-conv-' . bin2hex(random_bytes(8));
    mkdir($vendor . '/resources/boost', 0o755, recursive: true);
    try {
        file_put_contents($vendor . '/composer.json', json_encode(['name' => 'acme/conv'], JSON_THROW_ON_ERROR));
        file_put_contents($vendor . '/resources/boost/conventions-schema.json', json_encode([
            'type' => 'object',
            'properties' => ['jira' => ['type' => 'object', 'properties' => ['project_key' => ['type' => 'string']]]],
        ], JSON_THROW_ON_ERROR));
        file_put_contents($root . '/boost.php', "<?php\n\ndeclare(strict_types=1);\n\nuse SanderMuller\\BoostCore\\Config\\BoostConfig;\nuse SanderMuller\\BoostCore\\Enums\\Agent;\n\nreturn BoostConfig::configure()->withAgents([Agent::CODEX])->withAllowedVendors(['acme/conv'])->withConventions(['jira' => ['project_key' => 'BOOST-7']]);\n");
        file_put_contents($root . '/.ai/guidelines/rules.md', "# Rules\n\nFollow the Project Conventions section above for the key.");

        $packages = new InstalledPackages(['acme/conv' => new PackageInfo('acme/conv', '1.0.0', $vendor)]);
        $result = SyncEngine::default($packages)->sync($root);
        $agentsMd = (string) file_get_contents($root . '/AGENTS.md');

        expect($result->hasErrors())->toBeFalse()
            ->and($root . '/CLAUDE.md')->not->toBeFile()
            ->and(substr_count($agentsMd, '## Project Conventions'))->toBe(1)
            ->and($agentsMd)->toContain('project_key: BOOST-7')
            ->toContain('Follow the Project Conventions section above');
    } finally {
        removeAgentsMdProject($root);
        removeAgentsMdProject($vendor);
    }
});

it('resolves the AGENTS.md import relative to .claude/CLAUDE.md', function (string $import, int $warnings): void {
    $root = makeAgentsMdProject();
    try {
        mkdir($root . '/.claude', 0o755, recursive: true);
        file_put_contents($root . '/.claude/CLAUDE.md', "{$import}\n");

        $result = SyncEngine::default(new InstalledPackages([]))->sync($root);

        expect(shadowWarnings($result))->toHaveCount($warnings);
    } finally {
        removeAgentsMdProject($root);
    }
})->with([
    'root-relative import loads .claude/AGENTS.md, not the root file' => ['@AGENTS.md', 1],
    'parent import loads the root file' => ['@../AGENTS.md', 0],
]);

it('does not warn when boost emits no guidance and both files are hand-written', function (): void {
    $root = makeAgentsMdProject();
    try {
        unlink($root . '/.ai/guidelines/rules.md');
        file_put_contents($root . '/AGENTS.md', "# Hand-written agents\n");
        file_put_contents($root . '/CLAUDE.md', "# Hand-written claude\n");

        $result = SyncEngine::default(new InstalledPackages([]))->sync($root);

        expect(shadowWarnings($result))->toBeEmpty()
            ->and(file_get_contents($root . '/AGENTS.md'))->toBe("# Hand-written agents\n");
    } finally {
        removeAgentsMdProject($root);
    }
});
