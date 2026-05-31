<?php declare(strict_types=1);

use SanderMuller\BoostCore\Conventions\ConventionsInliner;
use SanderMuller\BoostCore\Conventions\LeakHit;
use SanderMuller\BoostCore\Conventions\SlotResolver;

/**
 * scanLeaks() — 0.16.0 conventions-token observability. The detection half of
 * the same line scanner inline() uses, so the two never diverge.
 *
 * @param  array<string, mixed>  $conventions
 */
function leakInliner(array $conventions = []): ConventionsInliner
{
    /** @var array<string, mixed> $schema */
    $schema = json_decode((string) file_get_contents(__DIR__ . '/../../Fixtures/conventions/conventions-schema.json'), true, 512, JSON_THROW_ON_ERROR);

    return new ConventionsInliner(
        new SlotResolver($conventions, $schema),
        ['github', 'testing', 'codex', 'mcp', 'branches'],
    );
}

it('flags a raw token in prose with parsed path/mode and 1-based line', function (): void {
    $body = "Intro line.\nBase branch is <!--boost:conv path=\"github.default_base_branch\" mode=\"inline\"--> here.";
    $hits = leakInliner()->scanLeaks($body);

    expect($hits)->toHaveCount(1)
        ->and($hits[0]->kind)
        ->toBe(LeakHit::KIND_PROSE_TOKEN)
        ->and($hits[0]->line)
        ->toBe(2)
        ->and($hits[0]->path)
        ->toBe('github.default_base_branch')
        ->and($hits[0]->mode)
        ->toBe('inline');
});

it('does NOT flag a token inside an inline-code span (intentional literal)', function (): void {
    $body = 'Write `<!--boost:conv path="github.default_base_branch" mode="inline"-->` to inline the base branch.';

    expect(leakInliner()->scanLeaks($body))
        ->toBeEmpty();
});

it('does NOT flag a token inside a PLAIN code fence (intentional literal)', function (): void {
    $body = "Example:\n```\n<!--boost:conv path=\"github.default_base_branch\" mode=\"inline\"-->\n```\n";

    expect(leakInliner()->scanLeaks($body))
        ->toBeEmpty();
});

it('flags a surviving ```boost:conv fence opener (mode A/C signal)', function (): void {
    $body = "Before.\n```yaml boost:conv\npatterns:\n  - main\n```\nAfter.";
    $hits = leakInliner()->scanLeaks($body);

    expect($hits)->toHaveCount(1)
        ->and($hits[0]->kind)
        ->toBe(LeakHit::KIND_FENCE_OPENER)
        ->and($hits[0]->line)
        ->toBe(2)
        ->and($hits[0]->path)
        ->toBeNull();
});

it('does NOT flag a PROCESSED opt-in fence (info-string already stripped)', function (): void {
    // What a 0.15+ engine leaves after processing: plain ```yaml, value inlined.
    $body = "```yaml\npatterns:\n  - main\n```\n";

    expect(leakInliner()->scanLeaks($body))
        ->toBeEmpty();
});

it('does NOT flag a ```boost:conv line nested inside ANOTHER fence (codex P2b)', function (): void {
    // The outer ~~~~ fence is active; the inner ```boost:conv is fence CONTENT,
    // not a real opener. Only the stateful walker (not a flat grep) gets this right.
    $body = "~~~~\nUse a ```boost:conv fence like this:\n```boost:conv\nfoo\n```\n~~~~\n";

    expect(leakInliner()->scanLeaks($body))
        ->toBeEmpty();
});

it('flags multiple prose tokens with distinct line numbers', function (): void {
    $body = "<!--boost:conv path=\"a.b\" mode=\"inline\"-->\nplain\n<!--boost:conv path=\"c.d\" mode=\"bullets\"-->";
    $hits = leakInliner()->scanLeaks($body);

    expect($hits)->toHaveCount(2)
        ->and($hits[0]->line)
        ->toBe(1)
        ->and($hits[1]->line)
        ->toBe(3)
        ->and($hits[1]->path)
        ->toBe('c.d');
});

it('flags a prose token missing the mode attribute (path parsed, mode null)', function (): void {
    $hits = leakInliner()->scanLeaks('<!--boost:conv path="github.default_base_branch"-->');

    expect($hits)->toHaveCount(1)
        ->and($hits[0]->path)
        ->toBe('github.default_base_branch')
        ->and($hits[0]->mode)
        ->toBeNull();
});

it('reports nothing for a clean body with no tokens', function (): void {
    expect(leakInliner()->scanLeaks("# Heading\n\nProse with no tokens.\n"))
        ->toBeEmpty();
});

it('P1: KEEPS the boost:conv info-string when a fenced token errors, so scanLeaks catches it on disk', function (): void {
    // An opt-in yaml fence whose token references an unknown slot → inline()
    // errors and KEEPS the info-string (vs stripping on a clean fence), so the
    // fenced mode-B leak stays detectable by the surviving-opener signal.
    $body = "```yaml boost:conv\nbase: <!--boost:conv path=\"bogus.slot\" mode=\"inline\"-->\n```";
    $inliner = leakInliner();

    $result = $inliner->inline($body);

    expect($result->body)->toContain('```yaml boost:conv')
        ->and($result->errors)->not->toBeEmpty();

    $hits = $inliner->scanLeaks($result->body);
    expect($hits)->toHaveCount(1)
        ->and($hits[0]->kind)->toBe(LeakHit::KIND_FENCE_OPENER);
});

it('P1: STRIPS the info-string when a fenced token resolves cleanly (no leak)', function (): void {
    $body = "```yaml boost:conv\nbranch: <!--boost:conv path=\"github.default_base_branch\" mode=\"inline\"-->\n```";
    $inliner = leakInliner(['github' => ['default_base_branch' => 'develop']]);

    $result = $inliner->inline($body);

    expect($result->body)->toContain('```yaml')
        ->and($result->body)->not->toContain('boost:conv')
        ->and($result->body)->toContain('branch: develop')
        ->and($inliner->scanLeaks($result->body))
        ->toBeEmpty();
});

it('cross-checks with inline(): a plain-fence token is neither inlined nor leak-flagged', function (): void {
    $body = "```\n<!--boost:conv path=\"github.default_base_branch\" mode=\"inline\"-->\n```";
    $inliner = leakInliner(['github' => ['default_base_branch' => 'develop']]);

    // inline() leaves it literal (plain fence)...
    expect($inliner->inline($body)->body)->toContain('<!--boost:conv path="github.default_base_branch" mode="inline"-->');
    // ...and scanLeaks() agrees it is an intentional literal, not a leak.
    expect($inliner->scanLeaks($body))
        ->toBeEmpty();
});
