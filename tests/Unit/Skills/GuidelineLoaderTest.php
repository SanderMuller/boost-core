<?php declare(strict_types=1);

use SanderMuller\BoostCore\Contracts\SkillRenderer;
use SanderMuller\BoostCore\Skills\FrontmatterParser;
use SanderMuller\BoostCore\Skills\Guideline;
use SanderMuller\BoostCore\Skills\GuidelineLoader;
use SanderMuller\BoostCore\Skills\Rendering\PassthroughRenderer;
use SanderMuller\BoostCore\Skills\Rendering\RenderContext;
use SanderMuller\BoostCore\Skills\Rendering\SkillRendererDispatcher;

function guidelineLoaderTempDir(): string
{
    $dir = sys_get_temp_dir() . '/boost-gl-' . bin2hex(random_bytes(8));
    mkdir($dir, 0o755, recursive: true);

    return $dir;
}

function guidelineLoaderCleanup(string $dir): void
{
    if (! is_dir($dir)) {
        return;
    }

    $iter = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($dir, FilesystemIterator::SKIP_DOTS),
        RecursiveIteratorIterator::CHILD_FIRST,
    );
    /** @var SplFileInfo $f */
    foreach ($iter as $f) {
        $f->isDir() ? @rmdir($f->getPathname()) : @unlink($f->getPathname());
    }

    @rmdir($dir);
}

it('warns (does not silently drop) a host guideline whose extension has no registered renderer (#85)', function (): void {
    $dir = guidelineLoaderTempDir();
    file_put_contents($dir . '/styling.blade.php', "---\nname: styling\n---\nBlade body.");
    file_put_contents($dir . '/plain.md', "---\nname: plain\n---\nMarkdown body.");

    try {
        $errors = [];
        $warnings = [];
        // Default dispatcher — passthrough only, NO BladeRenderer registered.
        $loader = new GuidelineLoader(new FrontmatterParser());
        $guidelines = iterator_to_array($loader->load($dir, null, null, $errors, $warnings), false);

        $names = array_map(static fn (Guideline $g): string => $g->name, $guidelines);

        // .md still loads; the .blade.php is still skipped (no renderer)...
        expect($names)->toContain('plain')
            ->and($names)->not->toContain('styling')
            // ...but the skip is no longer silent — it's a render-class WARNING,
            // not an error (it must not fail --check or block other writes).
            ->and($errors)->toBeEmpty()
            ->and($warnings)->toHaveCount(1)
            ->and($warnings[0])->toContain('styling.blade.php')
            ->and($warnings[0])->toContain('blade.php');
    } finally {
        guidelineLoaderCleanup($dir);
    }
});

it('emits no skip warning when every file has a registered renderer (.md only)', function (): void {
    $dir = guidelineLoaderTempDir();
    file_put_contents($dir . '/a.md', "---\nname: a\n---\nBody.");
    file_put_contents($dir . '/b.md', "---\nname: b\n---\nBody.");

    try {
        $errors = [];
        $warnings = [];
        $loader = new GuidelineLoader(new FrontmatterParser());
        $guidelines = iterator_to_array($loader->load($dir, null, null, $errors, $warnings), false);

        expect($guidelines)->toHaveCount(2)
            ->and($errors)->toBeEmpty()
            ->and($warnings)->toBeEmpty();
    } finally {
        guidelineLoaderCleanup($dir);
    }
});

it('does NOT warn when a registered renderer claims the extension — the guard respects renderers (#85)', function (): void {
    $dir = guidelineLoaderTempDir();
    file_put_contents($dir . '/styling.blade.php', "---\nname: styling\n---\nBlade body.");

    // A renderer IS registered for blade.php — so the file renders, loads, and
    // must NOT be reported as an unrenderable skip.
    $blade = new class implements SkillRenderer {
        public function extensions(): array
        {
            return ['blade.php'];
        }

        public function render(string $raw, RenderContext $ctx): string
        {
            return $raw;
        }
    };
    $dispatcher = new SkillRendererDispatcher([$blade, new PassthroughRenderer()]);

    try {
        $errors = [];
        $warnings = [];
        $loader = new GuidelineLoader(new FrontmatterParser());
        $guidelines = iterator_to_array($loader->load($dir, null, $dispatcher, $errors, $warnings), false);

        expect(array_map(static fn (Guideline $g): string => $g->name, $guidelines))->toContain('styling')
            ->and($warnings)->toBeEmpty()
            ->and($errors)->toBeEmpty();
    } finally {
        guidelineLoaderCleanup($dir);
    }
});

it('does NOT warn on single-extension asset files in the guidelines dir — only template sources (#85 codex P2)', function (): void {
    $dir = guidelineLoaderTempDir();
    file_put_contents($dir . '/team.md', "---\nname: team\n---\nBody.");
    // Assets an operator/vendor might legitimately keep here — must NOT be
    // flagged as unrenderable guidelines (no misleading "rename to .md").
    file_put_contents($dir . '/notes.txt', 'scratch notes');
    file_put_contents($dir . '/diagram.png', 'fake png bytes');
    file_put_contents($dir . '/data.json', '{"k":"v"}');

    try {
        $errors = [];
        $warnings = [];
        $loader = new GuidelineLoader(new FrontmatterParser());
        $guidelines = iterator_to_array($loader->load($dir, null, null, $errors, $warnings), false);

        expect(array_map(static fn (Guideline $g): string => $g->name, $guidelines))->toBe(['team'])
            ->and($warnings)->toBeEmpty()
            ->and($errors)->toBeEmpty();
    } finally {
        guidelineLoaderCleanup($dir);
    }
});

it('warns on a single-extension renderer source (.mdx), not only compound extensions (#87 codex P2)', function (): void {
    $dir = guidelineLoaderTempDir();
    // .mdx has no registered renderer here → dropped. It is a template SOURCE
    // (single-segment ext), so it must still be flagged — unlike a binary asset.
    file_put_contents($dir . '/notes.mdx', "---\nname: notes\n---\nMDX body.");
    file_put_contents($dir . '/logo.png', 'pretend image');

    try {
        $errors = [];
        $warnings = [];
        $guidelines = iterator_to_array((new GuidelineLoader(new FrontmatterParser()))->load($dir, null, null, $errors, $warnings), false);

        expect($guidelines)->toBeEmpty()
            ->and($warnings)->toHaveCount(1)
            ->and($warnings[0])->toContain('notes.mdx')
            // The .png asset is NOT a renderable source → not flagged.
            ->and($warnings[0])->not->toContain('logo.png')
            ->and($errors)->toBeEmpty();
    } finally {
        guidelineLoaderCleanup($dir);
    }
});
