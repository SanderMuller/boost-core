<?php declare(strict_types=1);

use SanderMuller\BoostCore\Sync\SyncResult;
use SanderMuller\BoostCore\Sync\WriteAction;
use SanderMuller\BoostCore\Sync\WrittenFile;

it('renders delete attribution naming all three possible causes plus the deleted paths', function (): void {
    $result = new SyncResult(
        writes: [
            new WrittenFile(relativePath: '.claude/skills/mcp-development', absolutePath: '/tmp/.claude/skills/mcp-development', action: WriteAction::DELETED),
            new WrittenFile(relativePath: '.cursor/skills/mcp-development', absolutePath: '/tmp/.cursor/skills/mcp-development', action: WriteAction::DELETED),
            new WrittenFile(relativePath: '.claude/skills/livewire-development', absolutePath: '/tmp/.claude/skills/livewire-development', action: WriteAction::UNCHANGED),
        ],
        emitters: [],
        errors: [],
        check: false,
    );

    $attribution = $result->renderDeleteAttribution();

    expect($attribution)->not->toBeNull()
        ->and($attribution)->toContain('Deleted 2 file(s)')
        ->and($attribution)->toContain('tag-filter')
        ->and($attribution)->toContain('withRemoteSkills')
        ->and($attribution)->toContain('stale prune')
        ->and($attribution)->toContain('  - .claude/skills/mcp-development')
        ->and($attribution)->toContain('  - .cursor/skills/mcp-development')
        ->and($attribution)->not->toContain('livewire-development');
});

it('returns null when no files were deleted', function (): void {
    $result = new SyncResult(
        writes: [
            new WrittenFile(relativePath: '.claude/skills/foo', absolutePath: '/tmp/.claude/skills/foo', action: WriteAction::WROTE),
            new WrittenFile(relativePath: '.claude/skills/bar', absolutePath: '/tmp/.claude/skills/bar', action: WriteAction::UNCHANGED),
        ],
        emitters: [],
        errors: [],
        check: false,
    );

    expect($result->renderDeleteAttribution())->toBeNull();
});

it('returns null in check mode even when WOULD_DELETE entries are present', function (): void {
    // Check mode already lists `would-delete` paths inline as part of
    // the drift report; the attribution surface is for destructive deletes
    // only, which by definition do not occur in --check.
    $result = new SyncResult(
        writes: [
            new WrittenFile(relativePath: '.claude/skills/foo', absolutePath: '/tmp/.claude/skills/foo', action: WriteAction::WOULD_DELETE),
        ],
        emitters: [],
        errors: [],
        check: true,
    );

    expect($result->renderDeleteAttribution())->toBeNull();
});
