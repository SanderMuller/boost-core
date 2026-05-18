<?php

declare(strict_types=1);

use SanderMuller\BoostCore\Sync\GitignoreManager;

it('returns null when no block exists and no patterns are requested', function (): void {
    $manager = new GitignoreManager();

    expect($manager->render(null, []))->toBeNull();
    expect($manager->render('vendor/', []))->toBeNull();
});

it('appends a block to empty contents', function (): void {
    $manager = new GitignoreManager();
    $result = $manager->render(null, ['.claude/skills/', 'CLAUDE.md']);

    expect($result)->not()->toBeNull();
    expect($result)->toContain(GitignoreManager::START);
    expect($result)->toContain(GitignoreManager::END);
    expect($result)->toContain('.claude/skills/');
    expect($result)->toContain('CLAUDE.md');
});

it('appends a block to existing contents preserving foreign lines', function (): void {
    $manager = new GitignoreManager();
    $existing = "vendor/\n.idea/\n";
    $result = $manager->render($existing, ['.claude/skills/']);

    expect($result)->toStartWith("vendor/\n.idea/\n");
    expect($result)->toContain('.claude/skills/');
});

it('rebuilds the block in place when it already exists', function (): void {
    $manager = new GitignoreManager();
    $existing = "vendor/\n"
        . GitignoreManager::START . "\n"
        . "# old note\n"
        . ".oldagent/\n"
        . GitignoreManager::END . "\n"
        . "node_modules/\n";

    $result = $manager->render($existing, ['.claude/skills/', 'CLAUDE.md']);

    expect($result)->not()->toBeNull();
    expect($result)->toContain('vendor/');
    expect($result)->toContain('node_modules/');
    expect($result)->toContain('.claude/skills/');
    expect($result)->toContain('CLAUDE.md');
    expect($result)->not()->toContain('.oldagent/');
});

it('strips the block when no patterns are requested', function (): void {
    $manager = new GitignoreManager();
    $existing = "vendor/\n"
        . GitignoreManager::START . "\n"
        . ".claude/skills/\n"
        . GitignoreManager::END . "\n"
        . "node_modules/\n";

    $result = $manager->render($existing, []);

    expect($result)->not()->toBeNull();
    expect($result)->toContain('vendor/');
    expect($result)->toContain('node_modules/');
    expect($result)->not()->toContain(GitignoreManager::START);
    expect($result)->not()->toContain('.claude/skills/');
});

it('returns null when block content matches requested patterns', function (): void {
    $manager = new GitignoreManager();
    $existing = $manager->render(null, ['.claude/skills/', 'CLAUDE.md']);

    expect($manager->render($existing, ['.claude/skills/', 'CLAUDE.md']))->toBeNull();
});

it('returns null when block content matches in different input order', function (): void {
    $manager = new GitignoreManager();
    $first = $manager->render(null, ['.claude/skills/', 'CLAUDE.md']);

    expect($manager->render($first, ['CLAUDE.md', '.claude/skills/']))->toBeNull();
});

it('deduplicates patterns', function (): void {
    $manager = new GitignoreManager();
    $result = $manager->render(null, ['CLAUDE.md', 'CLAUDE.md', '.claude/skills/']);

    $matches = substr_count((string) $result, "CLAUDE.md\n");
    expect($matches)->toBe(1);
});

it('sorts patterns alphabetically for diff stability', function (): void {
    $manager = new GitignoreManager();
    $a = $manager->render(null, ['z.md', 'a.md', 'm.md']);
    $b = $manager->render(null, ['a.md', 'm.md', 'z.md']);

    expect($a)->toBe($b);
});
