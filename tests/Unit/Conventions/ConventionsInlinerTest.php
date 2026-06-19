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

it('dependencyCauses: names each prose legacy ref + a prose pointer; quiet when nothing depends (#87)', function (): void {
    $causes = inliner([])->dependencyCauses('Runner is $.testing.runner. See the Project Conventions section above.');

    expect($causes)->toContain('legacy slot reference `$.testing.runner`')
        ->and($causes)->toContain('a prose pointer to the Project Conventions section')
        ->and(inliner([])
            ->dependencyCauses('A guideline with no conventions dependency at all.'))
        ->toBeEmpty();
});

it('dependencyCauses: flags an unresolved/raw conventions token', function (): void {
    $causes = inliner([])->dependencyCauses('Operator note: <!--boost:conv path="testing.runner" mode="inline"-->');

    expect($causes)->toContain('an unresolved conventions token');
});

it('dependencyCauses: an inline-code $.slot still yields a GENERIC fallback, since the gate keeps the block on the flat signal', function (): void {
    // legacyRefsIn() is prose-scoped (masks inline code), so it names no specific
    // ref — but the drop gate's hasLegacyRef() is flat and DOES keep the block for
    // this text. A kept block must always be explained, so dependencyCauses falls
    // back to the generic cause rather than going silent (which would leave the
    // kept block unexplained).
    $causes = inliner([])->dependencyCauses('Write `$.testing.runner` to reference the slot.');

    expect($causes)->toBe(['a legacy `$.<root>` slot reference']);
});

/*
 * Paired visible-default spans —
 * `<!--boost:conv path="x" mode="inline"-->VISIBLE<!--boost:conv:end-->`.
 * boost-core replaces the whole span with the resolved value; the visible
 * default doubles as the fallback. A no-resolver engine (laravel/boost) leaves
 * the comments inert, so the visible default shows as ordinary prose.
 */

it('paired: a declared value replaces the whole span', function (): void {
    $body = 'Write tests in <!--boost:conv path="testing.backend_framework" mode="inline"-->Pest<!--boost:conv:end-->.';
    $r = inliner(['testing' => ['backend_framework' => 'phpunit']])->inline($body);

    expect($r->body)->toBe('Write tests in phpunit.')
        ->and($r->body)->not->toContain('boost:conv')
        ->and($r->requiresRuntimeConventions)->toBeFalse()
        ->and($r->errors)->toBeEmpty();
});

it('paired: the visible default doubles as the fallback when the slot is unset and has no schema default', function (): void {
    $body = 'Write tests in <!--boost:conv path="testing.backend_framework" mode="inline"-->Pest (or PHPUnit)<!--boost:conv:end-->.';
    $r = inliner([])->inline($body);

    expect($r->body)->toBe('Write tests in Pest (or PHPUnit).')
        ->and($r->body)->not->toContain('boost:conv')
        ->and($r->errors)->toBeEmpty();
});

it('paired: a schema default still wins over the visible default', function (): void {
    $body = 'Base is <!--boost:conv path="github.default_base_branch" mode="inline"-->the trunk<!--boost:conv:end-->.';
    $r = inliner([])->inline($body);

    expect($r->body)->toBe('Base is main.'); // schema default 'main', not the visible 'the trunk'
});

it('paired: an explicit fallback attribute wins over the visible default', function (): void {
    $body = 'Runner: <!--boost:conv path="testing.backend_framework" mode="inline" fallback="auto-detected">Pest<!--boost:conv:end-->.';
    $r = inliner([])->inline($body);

    expect($r->body)->toBe('Runner: auto-detected.');
});

it('paired: under a no-resolver engine the inert comments leave the visible default as prose', function (): void {
    // Simulates laravel/boost: it preserves HTML comments verbatim and never
    // resolves boost:conv, so the rendered skill is the source with the comments
    // treated as inert. Stripping them must yield clean prose carrying the default.
    $source = 'Write tests in <!--boost:conv path="testing.backend_framework" mode="inline"-->Pest<!--boost:conv:end-->.';
    $inert = (string) preg_replace('/<!--\/?boost:conv(:end)?.*?-->/s', '', $source);

    expect($inert)->toBe('Write tests in Pest.');
});

it('paired: a multi-line yaml block inside an opt-in fence resolves and strips the info-string', function (): void {
    $body = "```yaml boost:conv\n<!--boost:conv path=\"branches.patterns\" mode=\"yaml\"-->\n- pattern: placeholder\n  base: main\n<!--boost:conv:end-->\n```";
    $r = inliner(['branches' => ['patterns' => [['pattern' => 'feature/*', 'base' => 'develop']]]])->inline($body);

    expect($r->body)->toContain('feature/*')
        ->and($r->body)->toContain('```yaml')
        ->and($r->body)->not->toContain('boost:conv')
        ->and($r->body)->not->toContain('placeholder')
        ->and($r->requiresRuntimeConventions)->toBeFalse()
        ->and($r->errors)->toBeEmpty();
});

it('paired: a span shown inside an inline-code span stays literal', function (): void {
    $body = 'Author as `<!--boost:conv path="testing.backend_framework" mode="inline"-->Pest<!--boost:conv:end-->`.';
    $r = inliner(['testing' => ['backend_framework' => 'phpunit']])->inline($body);

    expect($r->body)->toBe($body); // unchanged — inline code is literal
});

it('paired: a malformed span (missing path) is left in place and keeps the block', function (): void {
    $body = 'Runner: <!--boost:conv mode="inline"-->Pest<!--boost:conv:end-->.';
    $r = inliner([])->inline($body);

    expect($r->body)->toContain('<!--boost:conv:end-->')
        ->and($r->errors)->not->toBeEmpty()
        ->and($r->requiresRuntimeConventions)->toBeTrue();
});

it('paired: an orphan end marker keeps the conventions block (dependsOnConventions)', function (): void {
    expect(inliner([])->dependsOnConventions('stray <!--boost:conv:end--> marker'))->toBeTrue();
});
