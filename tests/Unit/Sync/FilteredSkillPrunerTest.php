<?php declare(strict_types=1);

use SanderMuller\BoostCore\Agents\ClaudeCodeTarget;
use SanderMuller\BoostCore\Sync\FilteredSkillPruner;
use SanderMuller\BoostCore\Sync\WriteAction;

/**
 * Build `<root>/.claude/skills/<name>/SKILL.md` and return the project root.
 */
function prunerProject(string $skillName): string
{
    $root = sys_get_temp_dir() . '/boost-prune-' . bin2hex(random_bytes(8));
    $dir = $root . '/.claude/skills/' . $skillName;
    mkdir($dir, 0o755, recursive: true);
    file_put_contents($dir . '/SKILL.md', "---\nname: {$skillName}\n---\nBody.\n");

    return $root;
}

it('deletes a dropped skill directory and reports DELETED', function (): void {
    $root = prunerProject('gone');
    try {
        $written = (new FilteredSkillPruner())->prune($root, new ClaudeCodeTarget(), 'gone', checkOnly: false);

        expect($written?->action)->toBe(WriteAction::DELETED)
            ->and(is_dir($root . '/.claude/skills/gone'))->toBeFalse();
    } finally {
        cleanupTestDir($root);
    }
});

it('reports WOULD_DELETE and deletes nothing in check mode', function (): void {
    $root = prunerProject('gone');
    try {
        $written = (new FilteredSkillPruner())->prune($root, new ClaudeCodeTarget(), 'gone', checkOnly: true);

        expect($written?->action)->toBe(WriteAction::WOULD_DELETE)
            ->and(is_dir($root . '/.claude/skills/gone'))->toBeTrue();
    } finally {
        cleanupTestDir($root);
    }
});

it('refuses to prune a symlinked target', function (): void {
    $root = sys_get_temp_dir() . '/boost-prune-link-' . bin2hex(random_bytes(8));
    $real = $root . '/real-skill';
    mkdir($real, 0o755, recursive: true);
    file_put_contents($real . '/SKILL.md', "---\nname: linked\n---\nBody.\n");
    mkdir($root . '/.claude/skills', 0o755, recursive: true);
    symlink($real, $root . '/.claude/skills/linked');

    try {
        $written = (new FilteredSkillPruner())->prune($root, new ClaudeCodeTarget(), 'linked', checkOnly: false);

        expect($written)->toBeNull()
            ->and(is_link($root . '/.claude/skills/linked'))->toBeTrue()
            ->and(is_file($real . '/SKILL.md'))->toBeTrue();
    } finally {
        cleanupTestDir($root);
    }
});

it('refuses an unsafe skill name containing a path separator', function (): void {
    $root = prunerProject('gone');
    try {
        $pruner = new FilteredSkillPruner();

        expect($pruner->prune($root, new ClaudeCodeTarget(), '../gone', checkOnly: false))->toBeNull()
            ->and($pruner->prune($root, new ClaudeCodeTarget(), '..', checkOnly: false))->toBeNull()
            ->and(is_dir($root . '/.claude/skills/gone'))->toBeTrue();
    } finally {
        cleanupTestDir($root);
    }
});

it('refuses to prune a skill directory that holds human-added content', function (): void {
    $root = prunerProject('gone');
    // A consumer dropped a sidecar note inside the synced skill directory.
    file_put_contents($root . '/.claude/skills/gone/notes.md', 'my notes');

    try {
        $written = (new FilteredSkillPruner())->prune($root, new ClaudeCodeTarget(), 'gone', checkOnly: false);

        expect($written)->toBeNull()
            ->and(is_file($root . '/.claude/skills/gone/notes.md'))->toBeTrue()
            ->and(is_file($root . '/.claude/skills/gone/SKILL.md'))->toBeTrue();
    } finally {
        cleanupTestDir($root);
    }
});

it('refuses a directory that lacks the generated SKILL.md layout', function (): void {
    $root = sys_get_temp_dir() . '/boost-prune-bare-' . bin2hex(random_bytes(8));
    mkdir($root . '/.claude/skills/notaskill', 0o755, recursive: true);
    file_put_contents($root . '/.claude/skills/notaskill/random.txt', 'hand-placed');

    try {
        $written = (new FilteredSkillPruner())->prune($root, new ClaudeCodeTarget(), 'notaskill', checkOnly: false);

        expect($written)->toBeNull()
            ->and(is_dir($root . '/.claude/skills/notaskill'))->toBeTrue();
    } finally {
        cleanupTestDir($root);
    }
});

it('refuses to prune when the managed skills dir is a symlink escaping the project', function (): void {
    $root = sys_get_temp_dir() . '/boost-prune-escape-' . bin2hex(random_bytes(8));
    $outside = sys_get_temp_dir() . '/boost-prune-outside-' . bin2hex(random_bytes(8));
    mkdir($outside . '/gone', 0o755, recursive: true);
    file_put_contents($outside . '/gone/SKILL.md', "---\nname: gone\n---\nBody.\n");
    mkdir($root . '/.claude', 0o755, recursive: true);
    // The managed skills dir itself is a symlink pointing outside the repo.
    symlink($outside, $root . '/.claude/skills');

    try {
        $written = (new FilteredSkillPruner())->prune($root, new ClaudeCodeTarget(), 'gone', checkOnly: false);

        expect($written)->toBeNull()
            ->and(is_file($outside . '/gone/SKILL.md'))->toBeTrue();
    } finally {
        cleanupTestDir($root);
        cleanupTestDir($outside);
    }
});

it('returns null when there is nothing at the path', function (): void {
    $root = sys_get_temp_dir() . '/boost-prune-empty-' . bin2hex(random_bytes(8));
    mkdir($root, 0o755, recursive: true);

    try {
        expect((new FilteredSkillPruner())->prune($root, new ClaudeCodeTarget(), 'absent', checkOnly: false))
            ->toBeNull();
    } finally {
        cleanupTestDir($root);
    }
});
