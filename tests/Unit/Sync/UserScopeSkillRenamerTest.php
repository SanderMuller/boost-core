<?php declare(strict_types=1);

use SanderMuller\BoostCore\Skills\Skill;
use SanderMuller\BoostCore\Skills\SkillAsset;
use SanderMuller\BoostCore\Sync\UserScopeSkillRenamer;

/**
 * @param  list<SkillAsset>  $assets
 */
function renamerSkill(string $name, string $body = 'Body.', array $assets = []): Skill
{
    return new Skill(
        name: $name,
        description: 'A skill.',
        frontmatter: ['name' => $name, 'description' => 'A skill.', 'metadata' => ['boost-requires' => 'clarify']],
        body: $body,
        sourcePath: '/src/' . $name . '/SKILL.md',
        sourceVendor: 'acme/tools',
        assets: $assets,
    );
}

it('suffixes the name and the frontmatter name, and keeps everything else', function (): void {
    $result = (new UserScopeSkillRenamer())->rename([renamerSkill('interview')]);

    $skill = $result['skills'][0];

    expect($result['warnings'])->toBe([])
        ->and($skill->name)->toBe('interview-user')
        ->and($skill->frontmatter['name'])->toBe('interview-user')
        ->and($skill->frontmatter['description'])->toBe('A skill.')
        ->and($skill->frontmatter['metadata'])->toBe(['boost-requires' => 'clarify'])
        ->and($skill->description)->toBe('A skill.')
        ->and($skill->sourceVendor)->toBe('acme/tools');
});

it('rewrites the three reference forms for names in the published set', function (string $before, string $after): void {
    expect(UserScopeSkillRenamer::rewriteReferences($before, ['clarify', 'write-spec', 'codex-review', 'autoresearch']))
        ->toBe($after);
})->with([
    'backticked name' => ['hand to `write-spec` at the end', 'hand to `write-spec-user` at the end'],
    'slash command at line start' => ['/clarify <ask>', '/clarify-user <ask>'],
    'slash command in backticks' => ['standalone (`/clarify <ask>`)', 'standalone (`/clarify-user <ask>`)'],
    'slash command after a space' => ['run /codex-review first', 'run /codex-review-user first'],
    'slash command with a sub-mode' => ['run `/autoresearch:plan` first', 'run `/autoresearch-user:plan` first'],
    'slash command at sentence end' => ['Then run /clarify.', 'Then run /clarify-user.'],
    'relative skill link' => ['[Step 7](../codex-review/SKILL.md#step)', '[Step 7](../codex-review-user/SKILL.md#step)'],
]);

it('leaves the non-reference forms alone', function (string $content): void {
    expect(UserScopeSkillRenamer::rewriteReferences($content, ['clarify', 'write-spec', 'frontend-quality', 'autoresearch']))
        ->toBe($content);
})->with([
    'plain-text name' => ['handing off to write-spec.'],
    'backticked longer token' => ['`write-spec-extra` and `clarify.md`'],
    'install path' => ['node .claude/skills/frontend-quality/scripts/run.mjs'],
    'url' => ['https://github.com/karpathy/autoresearch'],
    'slash command prefix of a longer name' => ['/clarify-more and /clarify/sub'],
    'slash command followed by an underscore or capital' => ['/clarify_x and /clarifyX'],
]);

it('rewrites only names in the published set', function (): void {
    $body = 'Use `write-spec`, then `implement-spec`.';

    expect(UserScopeSkillRenamer::rewriteReferences($body, ['write-spec']))
        ->toBe('Use `write-spec-user`, then `implement-spec`.');
});

it('rewrites references across skills in the same run, in the body and in .md assets only', function (): void {
    $result = (new UserScopeSkillRenamer())->rename([
        renamerSkill('interview', 'Read `clarify` first, then `write-spec`.', [
            new SkillAsset('references/flow.md', 'Hand off to `clarify`.'),
            new SkillAsset('scripts/run.sh', 'echo `clarify`'),
        ]),
        renamerSkill('clarify'),
    ]);

    $interview = $result['skills'][0];

    expect($interview->body)->toBe('Read `clarify-user` first, then `write-spec`.')
        ->and($interview->assets[0]->contents)->toBe('Hand off to `clarify-user`.')
        ->and($interview->assets[1]->contents)->toBe('echo `clarify`');
});

it('skips a skill whose suffixed name is over the 64-character limit, with a warning', function (): void {
    $long = str_repeat('a', 60);
    $result = (new UserScopeSkillRenamer())->rename([renamerSkill($long), renamerSkill('ok')]);

    expect(array_map(static fn (Skill $skill): string => $skill->name, $result['skills']))->toBe(['ok-user'])
        ->and($result['warnings'])->toHaveCount(1)
        ->and($result['warnings'][0])->toContain('longer than the 64-character skill name limit');
});

it('keeps a 59-character name, which fits exactly', function (): void {
    $result = (new UserScopeSkillRenamer())->rename([renamerSkill(str_repeat('a', 59))]);

    expect($result['skills'])->toHaveCount(1)
        ->and(strlen($result['skills'][0]->name))->toBe(64);
});
