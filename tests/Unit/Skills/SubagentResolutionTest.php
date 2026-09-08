<?php declare(strict_types=1);

use SanderMuller\BoostCore\Config\BoostConfig;
use SanderMuller\BoostCore\Enums\Agent;
use SanderMuller\BoostCore\Skills\CollidingSubagentsException;
use SanderMuller\BoostCore\Skills\DuplicateSubagentNameException;
use SanderMuller\BoostCore\Skills\Subagent;
use SanderMuller\BoostCore\Skills\SubagentResolver;
use SanderMuller\BoostCore\Skills\SubagentTagFilter;

/**
 * @param  list<string>  $tags
 */
function makeSubagent(string $name, ?string $vendor = null, array $tags = [], bool $tagsValid = true): Subagent
{
    return new Subagent(
        name: $name,
        description: null,
        frontmatter: ['name' => $name],
        body: 'body',
        sourcePath: '/tmp/' . $name . '.md',
        sourceVendor: $vendor,
        tags: $tags,
        tagsValid: $tagsValid,
    );
}

/**
 * @param  list<string>  $tags
 * @param  list<string>  $excluded
 */
function subagentConfig(array $tags = [], array $excluded = []): BoostConfig
{
    return BoostConfig::configure()
        ->withAgents([Agent::CLAUDE_CODE])
        ->withTags($tags)
        ->withExcludedSkills($excluded)
        ->build('/tmp/project');
}

it('lets a host subagent shadow a vendor one and records the shadow', function (): void {
    $shadows = [];

    $resolved = (new SubagentResolver())->resolve(
        [makeSubagent('reviewer')],
        ['acme/pack' => [makeSubagent('reviewer', 'acme/pack')]],
        shadows: $shadows,
    );

    expect($resolved)->toHaveCount(1)
        ->and($resolved[0]->isHostAuthored())->toBeTrue()
        ->and($shadows)->toBe([['subagent' => 'reviewer', 'shadowedVendor' => 'acme/pack']]);
});

it('throws when two vendors publish the same subagent name', function (): void {
    (new SubagentResolver())->resolve(
        [],
        [
            'acme/pack' => [makeSubagent('reviewer', 'acme/pack')],
            'other/pack' => [makeSubagent('reviewer', 'other/pack')],
        ],
    );
})->throws(CollidingSubagentsException::class, 'published by multiple vendors');

it('falls back to declaration order under force instead of throwing', function (): void {
    $resolved = (new SubagentResolver())->resolve(
        [],
        [
            'acme/pack' => [makeSubagent('reviewer', 'acme/pack')],
            'other/pack' => [makeSubagent('reviewer', 'other/pack')],
        ],
        force: true,
    );

    expect($resolved)->toHaveCount(1)
        ->and($resolved[0]->sourceVendor)->toBe('acme/pack');
});

it('keeps distinct names from host and vendors alike', function (): void {
    $resolved = (new SubagentResolver())->resolve(
        [makeSubagent('mine')],
        ['acme/pack' => [makeSubagent('theirs', 'acme/pack')]],
    );

    expect(array_map(static fn (Subagent $s): string => $s->name, $resolved))
        ->toBe(['mine', 'theirs']);
});

it('drops a subagent whose tags are not a subset of the consumer tags', function (): void {
    $result = (new SubagentTagFilter())->filter(
        [makeSubagent('laravel-only', 'acme/pack', ['laravel'])],
        subagentConfig(tags: ['php']),
    );

    expect($result['kept'])->toBe([])
        ->and($result['droppedNames'])->toBe(['laravel-only'])
        ->and($result['droppedByTag'])->toBe(1)
        ->and($result['tagMismatchDrops'])->toHaveCount(1);
});

it('keeps an untagged subagent and one whose tags the consumer declares', function (): void {
    $result = (new SubagentTagFilter())->filter(
        [
            makeSubagent('untagged', 'acme/pack'),
            makeSubagent('php-one', 'acme/pack', ['php']),
        ],
        subagentConfig(tags: ['php']),
    );

    expect($result['kept'])->toHaveCount(2)
        ->and($result['droppedNames'])->toBe([]);
});

it('fails a malformed-tag subagent closed, and does not make it rescue-eligible', function (): void {
    $result = (new SubagentTagFilter())->filter(
        [makeSubagent('broken', 'acme/pack', [], tagsValid: false)],
        subagentConfig(),
    );

    expect($result['kept'])->toBe([])
        ->and($result['droppedNames'])->toBe(['broken'])
        ->and($result['droppedByTag'])->toBe(0)
        ->and($result['tagMismatchDrops'])->toBe([])
        ->and($result['excludedDrops'])->toBe([]);
});

it('drops a subagent named in the exclude list and groups it as excluded', function (): void {
    // Subagents share `withExcludedSkills()` rather than getting a second
    // deny-list — the `vendor/package:name` key shape is identical.
    $result = (new SubagentTagFilter())->filter(
        [makeSubagent('reviewer', 'acme/pack')],
        subagentConfig(excluded: ['acme/pack:reviewer']),
    );

    expect($result['kept'])->toBe([])
        ->and($result['excludedDrops'])->toHaveCount(1)
        ->and($result['droppedByTag'])->toBe(0);
});

it('never excludes a host-authored subagent, which the deny-list cannot name', function (): void {
    $result = (new SubagentTagFilter())->filter(
        [makeSubagent('reviewer')],
        subagentConfig(excluded: ['acme/pack:reviewer']),
    );

    expect($result['kept'])->toHaveCount(1);
});

it('keeps the first of two host files claiming one name, and says which lost', function (): void {
    // Skills cannot hit this — one directory per name — but a subagent's
    // identity is frontmatter, so two flat files can claim it.
    $duplicates = [];
    $first = makeSubagent('auditor');
    $second = new Subagent(
        name: 'auditor',
        description: null,
        frontmatter: [],
        body: 'body',
        sourcePath: '/tmp/legacy/auditor.md',
        sourceVendor: null,
    );

    $resolved = (new SubagentResolver())->resolve([$first, $second], [], duplicateWarnings: $duplicates);

    expect($resolved)->toBe([$first])
        ->and($duplicates)->toHaveCount(1)
        ->and($duplicates[0])->toContain('/tmp/legacy/auditor.md')
        ->and($duplicates[0])->toContain('is ignored');
});

it('throws a package-specific error when one vendor ships two files with one name', function (): void {
    // Not the cross-vendor collision: naming the same package twice would read
    // as an engine bug rather than an authoring mistake.
    $one = new Subagent('auditor', null, [], 'b', '/pkg/a.md', 'acme/pack');
    $two = new Subagent('auditor', null, [], 'b', '/pkg/nested/b.md', 'acme/pack');

    (new SubagentResolver())->resolve([], ['acme/pack' => [$one, $two]]);
})->throws(DuplicateSubagentNameException::class, 'ships two subagents named');

it('names both source files in the one-package duplicate error', function (): void {
    $one = new Subagent('auditor', null, [], 'b', '/pkg/a.md', 'acme/pack');
    $two = new Subagent('auditor', null, [], 'b', '/pkg/nested/b.md', 'acme/pack');

    try {
        (new SubagentResolver())->resolve([], ['acme/pack' => [$one, $two]]);
    } catch (DuplicateSubagentNameException $exception) {
        expect($exception->getMessage())->toContain('/pkg/a.md')
            ->and($exception->getMessage())->toContain('/pkg/nested/b.md')
            // The package is named once, not twice.
            ->and(substr_count($exception->getMessage(), 'acme/pack'))->toBe(1);

        return;
    }

    throw new RuntimeException('expected DuplicateSubagentNameException');
});
