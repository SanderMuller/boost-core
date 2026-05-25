<?php declare(strict_types=1);

use SanderMuller\BoostCore\Contracts\SkillRenderer;
use SanderMuller\BoostCore\Skills\Rendering\InvalidSkillRendererException;
use SanderMuller\BoostCore\Skills\Rendering\MatchedRenderer;
use SanderMuller\BoostCore\Skills\Rendering\PassthroughRenderer;
use SanderMuller\BoostCore\Skills\Rendering\RenderContext;
use SanderMuller\BoostCore\Skills\Rendering\SkillRendererDispatcher;

/**
 * @param  list<string>  $extensions
 */
function makeRenderer(array $extensions, string $bodyTransform = ''): SkillRenderer
{
    return new class ($extensions, $bodyTransform) implements SkillRenderer {
        /**
         * @param  list<string>  $extensions
         */
        public function __construct(
            private readonly array $extensions,
            private readonly string $bodyTransform,
        ) {}

        /** @return list<string> */
        public function extensions(): array
        {
            return $this->extensions;
        }

        public function render(string $raw, RenderContext $ctx): string
        {
            return $this->bodyTransform === 'upper' ? strtoupper($raw) : $raw;
        }
    };
}

test('passthrough-only registry resolves SKILL.md', function (): void {
    $d = new SkillRendererDispatcher([new PassthroughRenderer()]);
    $matched = $d->resolve('SKILL.md');

    expect($matched)->not->toBeNull();
    assert($matched instanceof MatchedRenderer);
    expect($matched->extension)->toBe('md')
        ->and($matched->renderer)->toBeInstanceOf(PassthroughRenderer::class);
});

test('returns null for an extension no renderer claims', function (): void {
    $d = new SkillRendererDispatcher([new PassthroughRenderer()]);
    expect($d->resolve('SKILL.blade.php'))->toBeNull();
});

test('longest-extension-first beats single-segment claim', function (): void {
    $blade = makeRenderer(['blade.php']);
    $php = makeRenderer(['php']);
    $d = new SkillRendererDispatcher([$php, $blade, new PassthroughRenderer()]);

    $matched = $d->resolve('SKILL.blade.php');
    expect($matched)->not->toBeNull();
    assert($matched instanceof MatchedRenderer);
    expect($matched->extension)->toBe('blade.php')
        ->and($matched->renderer)->toBe($blade);
});

test('first-registered wins on same extension (user override of md)', function (): void {
    $custom = makeRenderer(['md']);
    $d = new SkillRendererDispatcher([$custom, new PassthroughRenderer()]);

    $matched = $d->resolve('SKILL.md');
    expect($matched?->renderer)->toBe($custom);
});

test('filename match is case-insensitive', function (): void {
    $d = new SkillRendererDispatcher([new PassthroughRenderer()]);
    expect($d->resolve('SKILL.MD'))->not->toBeNull();
});

test('fileGlobPatterns returns globs per registered extension', function (): void {
    $blade = makeRenderer(['blade.php']);
    $d = new SkillRendererDispatcher([new PassthroughRenderer(), $blade]);

    $patterns = $d->fileGlobPatterns();
    sort($patterns);
    expect($patterns)->toBe(['*.blade.php', '*.md']);
});

test('rejects an empty extension string', function (): void {
    expect(fn () => new SkillRendererDispatcher([makeRenderer([''])]))
        ->toThrow(InvalidSkillRendererException::class, 'invalid entry');
});

test('rejects a leading-dot extension', function (): void {
    expect(fn () => new SkillRendererDispatcher([makeRenderer(['.md'])]))
        ->toThrow(InvalidSkillRendererException::class, 'invalid entry');
});

test('rejects uppercase characters in extension', function (): void {
    expect(fn () => new SkillRendererDispatcher([makeRenderer(['MD'])]))
        ->toThrow(InvalidSkillRendererException::class, 'invalid entry');
});

test('rejects trailing-dot extension', function (): void {
    expect(fn () => new SkillRendererDispatcher([makeRenderer(['md.'])]))
        ->toThrow(InvalidSkillRendererException::class, 'invalid entry');
});
