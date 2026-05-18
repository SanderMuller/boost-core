<?php

declare(strict_types=1);

use SanderMuller\BoostCore\Agents\ClaudeCodeTarget;
use SanderMuller\BoostCore\Skills\FrontmatterParser;
use SanderMuller\BoostCore\Skills\Guideline;
use SanderMuller\BoostCore\Skills\Skill;
use SanderMuller\BoostCore\Skills\SkillLoader;
use SanderMuller\BoostCore\Sync\FileWriter;
use SanderMuller\BoostCore\Sync\PendingWrite;
use SanderMuller\BoostCore\Sync\WriteAction;

function makeTempProject(): string
{
    $root = sys_get_temp_dir() . '/boost-core-int-' . bin2hex(random_bytes(8));
    mkdir($root, 0o755, recursive: true);

    return $root;
}

/**
 * @return list<string>
 */
function rmTreeIntegration(string $path): array
{
    $deleted = [];
    if (! is_dir($path)) {
        return $deleted;
    }

    $entries = scandir($path);
    if ($entries === false) {
        return $deleted;
    }

    foreach ($entries as $entry) {
        if ($entry === '.') {
            continue;
        }
        if ($entry === '..') {
            continue;
        }

        $full = $path . '/' . $entry;
        if (is_dir($full) && ! is_link($full)) {
            $deleted = [...$deleted, ...rmTreeIntegration($full)];
        } else {
            unlink($full);
            $deleted[] = $full;
        }
    }

    rmdir($path);

    return $deleted;
}

it('end-to-end: fixture skills → planner → writer → files on disk', function (): void {
    $root = makeTempProject();
    try {
        // Load skills from the host fixture
        $loader = new SkillLoader(new FrontmatterParser());
        /** @var list<Skill> $skills */
        $skills = [];
        foreach ($loader->load(__DIR__ . '/../Fixtures/skills/host') as $skill) {
            $skills[] = $skill;
        }

        $guidelines = [
            new Guideline(
                name: 'conventions',
                description: null,
                frontmatter: [],
                body: "# Conventions\n\nUse strict types.",
                sourcePath: '/fake/conventions.md',
                sourceVendor: null,
            ),
        ];

        $target = new ClaudeCodeTarget();
        $writer = new FileWriter();

        $planned = $target->plan($skills, $guidelines);
        $results = array_map(fn (PendingWrite $p) => $writer->write($root, $p), $planned);

        // Verify all writes happened
        foreach ($results as $result) {
            expect($result->action)->toBe(WriteAction::WROTE)
                ->and(file_exists($result->absolutePath))
                ->toBeTrue();
        }

        // Verify expected files exist
        expect(file_exists($root . '/.claude/skills/host-skill/SKILL.md'))->toBeTrue();
        expect(file_exists($root . '/.claude/skills/shared-name/SKILL.md'))->toBeTrue()
            ->and(file_exists($root . '/CLAUDE.md'))
            ->toBeTrue();

        // Skill content has frontmatter + body
        $hostSkillContent = file_get_contents($root . '/.claude/skills/host-skill/SKILL.md');
        expect($hostSkillContent)->toContain('name: host-skill')
            ->toContain('# Host skill');

        // Guidelines file has the body
        $claudeMd = file_get_contents($root . '/CLAUDE.md');
        expect($claudeMd)->toContain('# Conventions')
            ->toContain('strict types');
    } finally {
        rmTreeIntegration($root);
    }
});

it('second run with same content reports `unchanged`', function (): void {
    $root = makeTempProject();
    try {
        $loader = new SkillLoader(new FrontmatterParser());
        /** @var list<Skill> $skills */
        $skills = [];
        foreach ($loader->load(__DIR__ . '/../Fixtures/skills/host') as $skill) {
            $skills[] = $skill;
        }

        $target = new ClaudeCodeTarget();
        $writer = new FileWriter();

        $planned = $target->plan($skills, []);

        // First pass: WROTE
        foreach ($planned as $write) {
            expect($writer->write($root, $write)->action)->toBe(WriteAction::WROTE);
        }

        // Second pass: UNCHANGED
        foreach ($planned as $write) {
            expect($writer->write($root, $write)->action)->toBe(WriteAction::UNCHANGED);
        }
    } finally {
        rmTreeIntegration($root);
    }
});
