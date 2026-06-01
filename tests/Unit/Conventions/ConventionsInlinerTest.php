<?php declare(strict_types=1);

use SanderMuller\BoostCore\Conventions\ConventionsInliner;
use SanderMuller\BoostCore\Conventions\SlotResolver;

/**
 * @param  array<string, mixed>  $conventions
 */
function inliner(array $conventions): ConventionsInliner
{
    /** @var array<string, mixed> $schema */
    $schema = json_decode((string) file_get_contents(__DIR__ . '/../../Fixtures/conventions/conventions-schema.json'), true, 512, JSON_THROW_ON_ERROR);

    return new ConventionsInliner(
        new SlotResolver($conventions, $schema),
        ['github', 'testing', 'codex', 'mcp', 'branches'],
    );
}

it('resolves a token in prose', function (): void {
    $body = 'Base branch is <!--boost:conv path="github.default_base_branch" mode="inline"--> by default.';
    $r = inliner(['github' => ['default_base_branch' => 'develop']])->inline($body);

    expect($r->body)->toBe('Base branch is develop by default.')
        ->and($r->requiresRuntimeConventions)->toBeFalse()
        ->and($r->errors)
        ->toBeEmpty();
});

it('B2: leaves a token inside a PLAIN fenced code block literal', function (): void {
    $body = "Example:\n```\n<!--boost:conv path=\"github.default_base_branch\" mode=\"inline\"-->\n```\n";
    $r = inliner(['github' => ['default_base_branch' => 'develop']])->inline($body);

    expect($r->body)->toContain('<!--boost:conv path="github.default_base_branch" mode="inline"-->')
        // an unresolved token left in a plain fence keeps the block (fail-safe).
        ->and($r->requiresRuntimeConventions)->toBeTrue();
});

it('B5: resolves a token inside an OPT-IN fence and cleans the info-string', function (): void {
    $body = "```json boost:conv\n{ \"base\": \"<!--boost:conv path=\"github.default_base_branch\" mode=\"inline\"-->\" }\n```";
    $r = inliner(['github' => ['default_base_branch' => 'develop']])->inline($body);

    expect($r->body)->toContain('"base": "develop"')
        ->and($r->body)->toContain('```json')
        ->and($r->body)->not->toContain('boost:conv')
        ->and($r->requiresRuntimeConventions)->toBeFalse();
});

it('leaves a token inside an inline-code span literal', function (): void {
    $body = 'Write `<!--boost:conv path="github.default_base_branch" mode="inline"-->` to inline the branch.';
    $r = inliner(['github' => ['default_base_branch' => 'develop']])->inline($body);

    expect($r->body)->toBe($body); // unchanged — inline code is literal
});

it('P2: leaves a token inside a DOUBLE-backtick code span literal (balanced run)', function (): void {
    $body = 'Write ``<!--boost:conv path="github.default_base_branch" mode="inline"-->`` literally.';
    $r = inliner(['github' => ['default_base_branch' => 'develop']])->inline($body);

    expect($r->body)->toBe($body)           // unchanged — double-backtick span is literal
        ->and($r->errors)
        ->toBeEmpty();
});

it('P1.2: dependsOnConventions catches heading-relative prose pointers', function (): void {
    $inl = inliner([]);

    expect($inl->dependsOnConventions('follow the branches.patterns block above'))->toBeTrue()
        ->and($inl->dependsOnConventions('see the Project Conventions section'))->toBeTrue()
        ->and($inl->dependsOnConventions('use the conventions above for branch naming'))->toBeTrue()
        ->and($inl->dependsOnConventions('Resolve `$.github.default_base_branch` from the block'))->toBeTrue()
        // an UNRESOLVED token in preserved/existing content is also a dependency (codex round-2)
        ->and($inl->dependsOnConventions('Operator note: <!--boost:conv path="jira.project_key" mode="inline"-->'))->toBeTrue()
        ->and($inl->dependsOnConventions('a guideline with no conventions dependency at all'))->toBeFalse();
});

it('B3: an escaped token renders the literal token text', function (): void {
    $body = 'Use <!--\\boost:conv path="x.y" mode="inline"--> to reference a slot.';
    $r = inliner([])->inline($body);

    expect($r->body)->toBe('Use <!--boost:conv path="x.y" mode="inline"--> to reference a slot.')
        ->and($r->errors)
        ->toBeEmpty();
});

it('records an error + keeps the block when a token errors', function (): void {
    $body = 'Runner: <!--boost:conv path="testing.backend_framework" mode="inline"-->.'; // unset, no default, no fallback
    $r = inliner([])->inline($body);

    expect($r->errors)->not->toBeEmpty()
        ->and($r->requiresRuntimeConventions)->toBeTrue()
        ->and($r->body)->toContain('<!--boost:conv'); // errored token left in place
});

it('flags requiresRuntimeConventions for a legacy $.slot prose reference', function (): void {
    $body = 'Resolve `$.codex.invocation_mode` from Project Conventions.';
    // (the $. ref is inside inline code, but the runtime-scan looks at final text)
    $r = inliner([])->inline($body);

    expect($r->requiresRuntimeConventions)->toBeTrue();
});

it('a fully-inlined body with no legacy ref does NOT require the block', function (): void {
    $body = 'Mode: <!--boost:conv path="codex.invocation_mode" mode="inline"-->.';
    $r = inliner([])->inline($body); // unset → schema default "plugin"

    expect($r->body)->toBe('Mode: plugin.')
        ->and($r->requiresRuntimeConventions)->toBeFalse()
        ->and($r->errors)
        ->toBeEmpty();
});

it('legacyRefsIn: captures full dotted PROSE refs for known roots; ignores code examples + unknown roots (#87)', function (): void {
    $body = 'Runner is $.testing.runner; again $.testing.runner; bare $.codex. '
        . 'A documented example like `$.github.default_base_branch` in inline-code is NOT a live ref. '
        . 'And $.jira.project_key is an unknown root.';

    $refs = inliner([])->legacyRefsIn($body);

    expect($refs)->toBe(['$.testing.runner', '$.codex'])
        // Inline-code is a doc example, not a dangling ref (the shipped
        // conventions-migration skills show `$.slot` syntax this way).
        ->and($refs)->not->toContain('$.github.default_base_branch')
        // Unknown root → not a false positive.
        ->and($refs)->not->toContain('$.jira.project_key');
});

it('legacyRefsIn: ignores refs inside a fenced code block (#87)', function (): void {
    $body = "Live ref \$.testing.runner.\n\n```\n\$.codex example in a fence\n```\n";

    expect(inliner([])->legacyRefsIn($body))->toBe(['$.testing.runner']);
});

it('legacyRefsIn: returns nothing when no slot roots are composed (no schema)', function (): void {
    $inliner = new ConventionsInliner(new SlotResolver([], []), []);

    expect($inliner->legacyRefsIn('mentions $.github.x and $.anything.y'))
        ->toBeEmpty();
});
