<?php declare(strict_types=1);

use SanderMuller\BoostCore\Sync\SyncManifest;

function manifestTempRoot(): string
{
    $dir = sys_get_temp_dir() . '/boost-manifest-' . bin2hex(random_bytes(8));
    mkdir($dir, 0o755, recursive: true);

    return $dir;
}

it('absent manifest decodes to empty (backward-safe — no ownership asserted)', function (): void {
    $root = manifestTempRoot();
    try {
        $manifest = SyncManifest::fromProjectRoot($root);
        expect($manifest->isEmpty())->toBeTrue()
            ->and($manifest->has('CLAUDE.md'))->toBeFalse();
    } finally {
        rmdir($root);
    }
});

it('corrupt manifest decodes to empty (backward-safe)', function (): void {
    $root = manifestTempRoot();
    mkdir($root . '/.boost', 0o755, recursive: true);
    file_put_contents($root . '/.boost/manifest.json', '{ not valid json');
    try {
        expect(SyncManifest::fromProjectRoot($root)->isEmpty())->toBeTrue();
    } finally {
        unlink($root . '/.boost/manifest.json');
        rmdir($root . '/.boost');
        rmdir($root);
    }
});

it('guidance ownership requires listed AND sha-match (sha-diverge → not owned, never-lossy)', function (): void {
    $manifest = SyncManifest::empty()
        ->withEntry('CLAUDE.md', 'abc123', 'guidance', SyncManifest::PROVENANCE_ENGINE);

    expect($manifest->ownsGuidance('CLAUDE.md', 'abc123'))->toBeTrue()      // unchanged → owned
        ->and($manifest->ownsGuidance('CLAUDE.md', 'DIFFERENT'))->toBeFalse() // operator edited → not owned
        ->and($manifest->ownsGuidance('AGENTS.md', 'abc123'))->toBeFalse();   // not listed → not owned
});

it('round-trips through JSON preserving entries + provenance + scope', function (): void {
    $root = manifestTempRoot();
    mkdir($root . '/.boost', 0o755, recursive: true);
    try {
        $manifest = SyncManifest::empty()
            ->withEntry('CLAUDE.md', 'sha-c', 'guidance', SyncManifest::PROVENANCE_ENGINE)
            ->withEntry('.cursor/skills/x/SKILL.md', 'sha-x', 'skill', 'wrapper:acme/wrap');

        file_put_contents($root . '/.boost/manifest.json', $manifest->toJson('boost-core/0.13.0'));

        $reloaded = SyncManifest::fromProjectRoot($root);
        expect($reloaded->has('CLAUDE.md'))->toBeTrue()
            ->and($reloaded->ownsGuidance('CLAUDE.md', 'sha-c'))->toBeTrue()
            ->and($reloaded->isEngineProvenance('CLAUDE.md'))->toBeTrue()
            ->and($reloaded->provenanceOf('.cursor/skills/x/SKILL.md'))->toBe('wrapper:acme/wrap')
            ->and($reloaded->isEngineProvenance('.cursor/skills/x/SKILL.md'))->toBeFalse();
    } finally {
        unlink($root . '/.boost/manifest.json');
        rmdir($root . '/.boost');
        rmdir($root);
    }
});

it('REJECTS source-dir paths (.ai/ and resources/boost/) — the dual-role-repo invariant', function (): void {
    $manifest = SyncManifest::empty()
        ->withEntry('.ai/skills/x/SKILL.md', 'sha', 'skill', SyncManifest::PROVENANCE_ENGINE)
        ->withEntry('resources/boost/skills/y/SKILL.md', 'sha', 'skill', SyncManifest::PROVENANCE_ENGINE)
        ->withEntry('.claude/skills/z/SKILL.md', 'sha', 'skill', SyncManifest::PROVENANCE_ENGINE);

    // Only the emission-target path is listed; both source paths are rejected.
    expect($manifest->has('.ai/skills/x/SKILL.md'))->toBeFalse()
        ->and($manifest->has('resources/boost/skills/y/SKILL.md'))->toBeFalse()
        ->and($manifest->has('.claude/skills/z/SKILL.md'))->toBeTrue();
});

it('0.14.0: round-trips a `file`-category entry with emitter provenance', function (): void {
    $root = manifestTempRoot();
    mkdir($root . '/.boost', 0o755, recursive: true);
    try {
        $manifest = SyncManifest::empty()->withEntry(
            '.mcp.json',
            'abc123',
            SyncManifest::CATEGORY_FILE,
            SyncManifest::PROVENANCE_EMITTER_PREFIX . 'Acme\\McpJsonEmitter',
        );
        file_put_contents($root . '/.boost/manifest.json', $manifest->toJson('boost-core'));

        $loaded = SyncManifest::fromProjectRoot($root);

        expect($loaded->has('.mcp.json'))->toBeTrue()
            ->and($loaded->entries['.mcp.json']['category'])->toBe('file')
            ->and($loaded->provenanceOf('.mcp.json'))->toBe('emitter:Acme\\McpJsonEmitter')
            ->and(SyncManifest::CATEGORY_FILE)->toBe('file')
            ->and(SyncManifest::PROVENANCE_EMITTER_PREFIX)->toBe('emitter:');
    } finally {
        @unlink($root . '/.boost/manifest.json');
        @rmdir($root . '/.boost');
        @rmdir($root);
    }
});

it('toJson emits version + generatedBy + an object (not array) for empty emitted', function (): void {
    $json = SyncManifest::empty()->toJson('boost-core/0.13.0');

    expect($json)
        ->toContain('"version": 1')
        ->toContain('"generatedBy": "boost-core/0.13.0"')
        ->toContain('"emitted": {}')
        ->toEndWith("\n");
});

it('0.18.0 dirFor/relativePathFor: root layout uses .boost, config-dir layout uses .config/boost', function (): void {
    expect(SyncManifest::dirFor(false))->toBe('.boost')
        ->and(SyncManifest::dirFor(true))->toBe('.config/boost')
        ->and(SyncManifest::relativePathFor(false))->toBe('.boost/manifest.json')
        ->and(SyncManifest::relativePathFor(true))->toBe('.config/boost/manifest.json');
});

it('0.18.0 config-dir read: prefers .config/boost/manifest.json over a legacy root .boost copy', function (): void {
    $root = manifestTempRoot();
    mkdir($root . '/.config/boost', 0o755, recursive: true);
    mkdir($root . '/.boost', 0o755, recursive: true);
    file_put_contents($root . '/.config/boost/manifest.json', json_encode([
        'version' => 1, 'generator' => 'boost-core',
        'emitted' => ['CONFIG.md' => ['sha256' => 'aaa', 'category' => 'guidance', 'provenance' => 'engine', 'scope' => 'project']],
    ], JSON_THROW_ON_ERROR));
    file_put_contents($root . '/.boost/manifest.json', json_encode([
        'version' => 1, 'generator' => 'boost-core',
        'emitted' => ['ROOT.md' => ['sha256' => 'bbb', 'category' => 'guidance', 'provenance' => 'engine', 'scope' => 'project']],
    ], JSON_THROW_ON_ERROR));
    try {
        $manifest = SyncManifest::fromProjectRoot($root, inConfigDir: true);
        expect($manifest->has('CONFIG.md'))->toBeTrue('config-dir manifest is authoritative')
            ->and($manifest->has('ROOT.md'))->toBeFalse('legacy root copy is not read when config-dir present');
    } finally {
        rmTreeManifest($root);
    }
});

it('0.18.0 config-dir read: falls back to legacy root .boost/manifest.json when config-dir copy is absent (migration carry-forward)', function (): void {
    $root = manifestTempRoot();
    mkdir($root . '/.boost', 0o755, recursive: true);
    file_put_contents($root . '/.boost/manifest.json', json_encode([
        'version' => 1, 'generator' => 'boost-core',
        'emitted' => ['ROOT.md' => ['sha256' => 'bbb', 'category' => 'guidance', 'provenance' => 'engine', 'scope' => 'project']],
    ], JSON_THROW_ON_ERROR));
    try {
        $manifest = SyncManifest::fromProjectRoot($root, inConfigDir: true);
        expect($manifest->has('ROOT.md'))->toBeTrue('ownership carried forward from the legacy root manifest');
    } finally {
        rmTreeManifest($root);
    }
});

it('0.18.0 root layout: falls back to a .config/boost manifest (reverse migration carry-forward, codex P2)', function (): void {
    // Moving .config/boost.php back to root must not forget prior ownership: with
    // no root .boost/manifest.json yet, fromProjectRoot(false) reads the leftover
    // .config/boost copy so the first post-migration sync carries ownership forward.
    $root = manifestTempRoot();
    mkdir($root . '/.config/boost', 0o755, recursive: true);
    file_put_contents($root . '/.config/boost/manifest.json', json_encode([
        'version' => 1, 'generator' => 'boost-core',
        'emitted' => ['CONFIG.md' => ['sha256' => 'aaa', 'category' => 'guidance', 'provenance' => 'engine', 'scope' => 'project']],
    ], JSON_THROW_ON_ERROR));
    try {
        expect(SyncManifest::fromProjectRoot($root)->has('CONFIG.md'))->toBeTrue('root layout falls back to .config/boost when root manifest absent');
    } finally {
        rmTreeManifest($root);
    }
});

it('0.18.0 root layout: prefers the root .boost manifest over a leftover .config/boost copy', function (): void {
    $root = manifestTempRoot();
    mkdir($root . '/.config/boost', 0o755, recursive: true);
    mkdir($root . '/.boost', 0o755, recursive: true);
    file_put_contents($root . '/.boost/manifest.json', json_encode([
        'version' => 1, 'generator' => 'boost-core',
        'emitted' => ['ROOT.md' => ['sha256' => 'bbb', 'category' => 'guidance', 'provenance' => 'engine', 'scope' => 'project']],
    ], JSON_THROW_ON_ERROR));
    file_put_contents($root . '/.config/boost/manifest.json', json_encode([
        'version' => 1, 'generator' => 'boost-core',
        'emitted' => ['CONFIG.md' => ['sha256' => 'aaa', 'category' => 'guidance', 'provenance' => 'engine', 'scope' => 'project']],
    ], JSON_THROW_ON_ERROR));
    try {
        $manifest = SyncManifest::fromProjectRoot($root);
        expect($manifest->has('ROOT.md'))->toBeTrue('active root manifest is authoritative')
            ->and($manifest->has('CONFIG.md'))->toBeFalse();
    } finally {
        rmTreeManifest($root);
    }
});

function rmTreeManifest(string $path): void
{
    if (! is_dir($path)) {
        return;
    }

    /** @var array<int, string> $entries */
    $entries = array_diff((array) scandir($path), ['.', '..']);
    foreach ($entries as $entry) {
        $full = $path . '/' . $entry;
        is_dir($full) ? rmTreeManifest($full) : unlink($full);
    }

    rmdir($path);
}
