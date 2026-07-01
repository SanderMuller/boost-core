<?php declare(strict_types=1);

use SanderMuller\BoostCore\Contracts\SkillRenderer;
use SanderMuller\BoostCore\Skills\FrontmatterParser;
use SanderMuller\BoostCore\Skills\Rendering\PassthroughRenderer;
use SanderMuller\BoostCore\Skills\Rendering\RenderContext;
use SanderMuller\BoostCore\Skills\Rendering\SkillRendererDispatcher;
use SanderMuller\BoostCore\Skills\Rendering\SkillRenderException;
use SanderMuller\BoostCore\Skills\Skill;
use SanderMuller\BoostCore\Skills\SkillAsset;
use SanderMuller\BoostCore\Skills\SkillLoader;

function loader(): SkillLoader
{
    return new SkillLoader(new FrontmatterParser());
}

function rmTreeSkillLoader(string $path): void
{
    if (! is_dir($path)) {
        return;
    }

    $entries = scandir($path);
    if ($entries === false) {
        return;
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
            rmTreeSkillLoader($full);
        } else {
            @unlink($full);
        }
    }

    @rmdir($path);
}

/**
 * @return list<Skill>
 */
function skillsFromFixture(string $subdir, ?string $vendor = null): array
{
    /** @var list<Skill> $skills */
    $skills = [];
    foreach (loader()->load(__DIR__ . '/../../Fixtures/skills/' . $subdir, $vendor) as $skill) {
        $skills[] = $skill;
    }

    return $skills;
}

/**
 * @param  list<Skill>  $skills
 */
function findLoadedSkill(array $skills, string $name): Skill
{
    foreach ($skills as $skill) {
        if ($skill->name === $name) {
            return $skill;
        }
    }

    throw new RuntimeException(sprintf('Skill "%s" not loaded.', $name));
}

it('loads skills with frontmatter from a directory', function (): void {
    $skills = skillsFromFixture('host');

    $names = array_map(fn (Skill $s): string => $s->name, $skills);
    expect($names)->toContain('host-skill')
        ->toContain('shared-name');
});

it('marks loaded skills with the source vendor', function (): void {
    $hostSkills = skillsFromFixture('host');
    $vendorSkills = skillsFromFixture('vendor-a', 'test/vendor-a');

    expect($hostSkills[0]->sourceVendor)->toBeNull()
        ->and($hostSkills[0]->isHostAuthored())
        ->toBeTrue()
        ->and($vendorSkills[0]->sourceVendor)
        ->toBe('test/vendor-a')
        ->and($vendorSkills[0]->isHostAuthored())
        ->toBeFalse();
});

it('derives name from filename when frontmatter omits it', function (): void {
    $skills = skillsFromFixture('no-frontmatter');

    expect($skills)->toHaveCount(1)
        ->and($skills[0]->name)
        ->toBe('raw')
        ->and($skills[0]->frontmatter)
        ->toBeEmpty()
        ->and($skills[0]->body)
        ->toContain('Just a body');
});

it('returns empty iterable for missing directories', function (): void {
    $skills = iterator_to_array(loader()->load('/nonexistent/path/' . bin2hex(random_bytes(4))));

    expect($skills)->toBeEmpty();
});

it('exposes description from frontmatter', function (): void {
    $skills = skillsFromFixture('host');
    $hostSkill = findLoadedSkill($skills, 'host-skill');

    expect($hostSkill->description)->toBe('A skill authored in the host project.');
});

it('silently skips Blade-template skills (e.g. SKILL.blade.php) while loading sibling .md', function (): void {
    $skills = skillsFromFixture('blade-template');

    expect($skills)->toHaveCount(1)
        ->and($skills[0]->name)
        ->toBe('regular');
});

it('silently contributes zero skills when a directory has ONLY Blade-template skills (no diagnostic)', function (): void {
    // Documents the silent-disappearance risk: a vendor publishing only
    // SKILL.blade.php yields zero skills from boost-core's perspective.
    // boost-core does not warn (rendering Blade is out of scope). Vendors
    // wanting propagation through boost-core must pre-render to SKILL.md.
    $skills = skillsFromFixture('blade-only');

    expect($skills)
        ->toBeEmpty();
});

/**
 * @return list<Skill>
 */
function skillsFromFixtureWithDispatcher(string $subdir, SkillRendererDispatcher $dispatcher, ?string $vendor = null): array
{
    /** @var list<Skill> $skills */
    $skills = [];
    foreach (loader()->load(__DIR__ . '/../../Fixtures/skills/' . $subdir, $vendor, $dispatcher) as $skill) {
        $skills[] = $skill;
    }

    return $skills;
}

it('loads SKILL.blade.php once a renderer claims `blade.php`', function (): void {
    $bladeRenderer = new class implements SkillRenderer {
        /** @return list<string> */
        public function extensions(): array
        {
            return ['blade.php'];
        }

        public function render(string $raw, RenderContext $ctx): string
        {
            // Substitute the Blade directive the fixture already carries
            // (`{{ config('app.name') }}`). The real BladeRenderer
            // delegates to Laravel's compiler; this stub proves the render
            // seam fires.
            return str_replace("{{ config('app.name') }}", 'TestApp', $raw);
        }
    };

    $dispatcher = new SkillRendererDispatcher([
        $bladeRenderer,
        new PassthroughRenderer(),
    ]);

    $skills = skillsFromFixtureWithDispatcher('blade-only', $dispatcher);

    expect($skills)->toHaveCount(1)
        ->and($skills[0]->body)->toContain('TestApp');
});

it('does NOT ship nested reference files as phantom skills; flat + nested SKILL.* both load (0.22.0 #A)', function (): void {
    // Regression: laravel/mcp ships mcp-development/SKILL.blade.php +
    // mcp-development/references/app.md. The depth-unbounded Finder shipped a
    // phantom top-level skill named `app` from the reference file. Correct
    // scope = top-level `*.<ext>` OR depth-1 `*/SKILL.*` — never deeper.
    $dir = sys_get_temp_dir() . '/boost-skill-depth-' . bin2hex(random_bytes(8));
    mkdir($dir . '/mcp-development/references', 0o755, true);
    file_put_contents($dir . '/mcp-development/SKILL.md', "---\nname: mcp-development\n---\nNested skill body.");
    file_put_contents($dir . '/mcp-development/references/app.md', "# app reference\nNot a skill.");
    file_put_contents($dir . '/flat-skill.md', "---\nname: flat-skill\n---\nFlat body.");
    // A non-SKILL .md sitting directly in a skill subdir is also NOT a skill.
    file_put_contents($dir . '/mcp-development/notes.md', "# notes\nNot a skill.");

    try {
        /** @var list<Skill> $skills */
        $skills = iterator_to_array(loader()->load($dir), false);
        $names = array_map(fn (Skill $s): string => $s->name, $skills);

        expect($names)->toContain('mcp-development') // nested SKILL.md entry
            ->toContain('flat-skill')                // top-level flat skill
            ->not->toContain('app')                  // nested reference — NOT shipped
            ->and($skills)->toHaveCount(2);
    } finally {
        rmTreeSkillLoader($dir);
    }
});

it('uses a renderer body VERBATIM — directive/token-like content survives (0.22.0 @api passthrough)', function (): void {
    // Frozen SkillRenderer guarantee a wrapper's BladeRenderer relies on
    // (e.g. @verbatim-wrapped content): boost-core strips the LEADING
    // frontmatter and uses the rest of render()'s output byte-for-byte — it
    // never re-processes directive-like, token-like, or fence-like body text.
    $body = "Keep @verbatim {{ \$notCompiled }} @endverbatim intact.\n"
        . "A token-looking literal: <!--boost:conv path=\"x\" mode=\"inline\"-->\n"
        . "A legacy ref literal: \$.testing.runner\n"
        . "A fenced block:\n```php\n\$x = 1;\n```\n";

    $renderer = new class ($body) implements SkillRenderer {
        public function __construct(private readonly string $body) {}

        /** @return list<string> */
        public function extensions(): array
        {
            return ['blade.php'];
        }

        public function render(string $raw, RenderContext $ctx): string
        {
            // A renderer that regenerates frontmatter + emits the body unchanged.
            return "---\nname: verbatim-skill\n---\n" . $this->body;
        }
    };

    $fixtureDir = sys_get_temp_dir() . '/boost-skill-loader-verbatim-' . bin2hex(random_bytes(8));
    mkdir($fixtureDir . '/verbatim-skill', 0o755, true);
    file_put_contents($fixtureDir . '/verbatim-skill/SKILL.blade.php', "ignored source\n");

    try {
        $dispatcher = new SkillRendererDispatcher([$renderer, new PassthroughRenderer()]);
        /** @var list<Skill> $skills */
        $skills = iterator_to_array(loader()->load($fixtureDir, null, $dispatcher), false);

        expect($skills)->toHaveCount(1)
            ->and($skills[0]->name)->toBe('verbatim-skill')
            // Body is byte-identical to what the renderer emitted (frontmatter stripped).
            ->and($skills[0]->body)->toBe($body);
    } finally {
        rmTreeSkillLoader($fixtureDir);
    }
});

it('multi-extension filename fallback strips full matched extension', function (): void {
    // Fixture: SKILL.blade.php with NO `name:` frontmatter. Without the
    // fallback fix, getFilenameWithoutExtension would yield `SKILL.blade`.
    $bladeRenderer = new class implements SkillRenderer {
        /** @return list<string> */
        public function extensions(): array
        {
            return ['blade.php'];
        }

        public function render(string $raw, RenderContext $ctx): string
        {
            return $raw;
        }
    };

    // Create a temp fixture inline since the existing `blade-only` fixture
    // contains a `name:` frontmatter that would override the filename fallback.
    $fixtureDir = sys_get_temp_dir() . '/boost-skill-loader-fallback-' . bin2hex(random_bytes(8));
    mkdir($fixtureDir, 0o755, true);
    mkdir($fixtureDir . '/anonymous-skill', 0o755, true);
    file_put_contents($fixtureDir . '/anonymous-skill/SKILL.blade.php', "# No frontmatter\nBody only.\n");

    try {
        $dispatcher = new SkillRendererDispatcher([
            $bladeRenderer,
            new PassthroughRenderer(),
        ]);

        /** @var list<Skill> $skills */
        $skills = iterator_to_array(loader()->load($fixtureDir, null, $dispatcher), false);

        expect($skills)->toHaveCount(1)
            ->and($skills[0]->name)->toBe('SKILL');  // not 'SKILL.blade'
    } finally {
        rmTreeSkillLoader($fixtureDir);
    }
});

it('passes RenderContext to the renderer with pre-parsed frontmatter', function (): void {
    $box = new class {
        public ?RenderContext $ctx = null;
    };

    $renderer = new class ($box) implements SkillRenderer {
        public function __construct(private readonly object $box) {}

        /** @return list<string> */
        public function extensions(): array
        {
            return ['md'];
        }

        public function render(string $raw, RenderContext $ctx): string
        {
            $this->box->ctx = $ctx;

            return $raw;
        }
    };

    $dispatcher = new SkillRendererDispatcher([$renderer]);
    skillsFromFixtureWithDispatcher('host', $dispatcher);

    expect($box->ctx)->toBeInstanceOf(RenderContext::class);
    assert($box->ctx instanceof RenderContext);
    expect($box->ctx->frontmatter)->toBeArray()
        ->and($box->ctx->sourcePath)->toContain('host')
        // projectRoot defaults null when the loader is called without one.
        ->and($box->ctx->projectRoot)->toBeNull();
});

it('threads projectRoot into RenderContext when provided (0.22.0 — container-free renderers)', function (): void {
    $box = new class {
        public ?RenderContext $ctx = null;
    };

    $renderer = new class ($box) implements SkillRenderer {
        public function __construct(private readonly object $box) {}

        /** @return list<string> */
        public function extensions(): array
        {
            return ['md'];
        }

        public function render(string $raw, RenderContext $ctx): string
        {
            $this->box->ctx = $ctx;

            return $raw;
        }
    };

    $dir = sys_get_temp_dir() . '/boost-skill-projectroot-' . bin2hex(random_bytes(8));
    mkdir($dir, 0o755, true);
    file_put_contents($dir . '/foo.md', "---\nname: foo\n---\nBody.");

    try {
        $dispatcher = new SkillRendererDispatcher([$renderer]);
        iterator_to_array(loader()->load($dir, null, $dispatcher, projectRoot: '/abs/project'), false);

        assert($box->ctx instanceof RenderContext);
        expect($box->ctx->projectRoot)->toBe('/abs/project');
    } finally {
        rmTreeSkillLoader($dir);
    }
});

it('post-render frontmatter override beats the filename fallback', function (): void {
    $renderer = new class implements SkillRenderer {
        /** @return list<string> */
        public function extensions(): array
        {
            return ['md'];
        }

        public function render(string $raw, RenderContext $ctx): string
        {
            // Inject a `name:` frontmatter in render output even if source has none.
            return "---\nname: renderer-renamed\n---\n" . $raw;
        }
    };

    $fixtureDir = sys_get_temp_dir() . '/boost-skill-loader-rename-' . bin2hex(random_bytes(8));
    mkdir($fixtureDir, 0o755, true);
    file_put_contents($fixtureDir . '/skill.md', 'Body.');

    try {
        $dispatcher = new SkillRendererDispatcher([$renderer]);
        /** @var list<Skill> $skills */
        $skills = iterator_to_array(loader()->load($fixtureDir, null, $dispatcher), false);

        expect($skills)->toHaveCount(1)
            ->and($skills[0]->name)->toBe('renderer-renamed');
    } finally {
        rmTreeSkillLoader($fixtureDir);
    }
});

it('lenient mode: renderer exception adds to errors-out and continues with siblings', function (): void {
    $renderer = new class implements SkillRenderer {
        /** @return list<string> */
        public function extensions(): array
        {
            return ['md'];
        }

        public function render(string $raw, RenderContext $ctx): string
        {
            throw new RuntimeException('boom');
        }
    };

    $fixtureDir = sys_get_temp_dir() . '/boost-skill-render-throw-' . bin2hex(random_bytes(8));
    mkdir($fixtureDir, 0o755, true);
    file_put_contents($fixtureDir . '/a.md', 'A.');
    file_put_contents($fixtureDir . '/b.md', 'B.');

    try {
        $dispatcher = new SkillRendererDispatcher([$renderer]);
        $errors = [];
        $skills = iterator_to_array(loader()->load($fixtureDir, null, $dispatcher, $errors), false);

        expect($skills)->toBeEmpty()
            ->and($errors)->toHaveCount(2)
            ->and($errors[0])->toContain('boom')
            ->and($errors[0])->toContain('render failed');
    } finally {
        rmTreeSkillLoader($fixtureDir);
    }
});

it('strict mode: renderer exception throws SkillRenderException', function (): void {
    $renderer = new class implements SkillRenderer {
        /** @return list<string> */
        public function extensions(): array
        {
            return ['md'];
        }

        public function render(string $raw, RenderContext $ctx): string
        {
            throw new RuntimeException('strict-boom');
        }
    };

    $fixtureDir = sys_get_temp_dir() . '/boost-skill-render-strict-' . bin2hex(random_bytes(8));
    mkdir($fixtureDir, 0o755, true);
    file_put_contents($fixtureDir . '/a.md', 'A.');

    putenv('BOOST_RENDER_STRICT=1');
    try {
        $dispatcher = new SkillRendererDispatcher([$renderer]);
        $errors = [];
        expect(fn () => iterator_to_array(loader()->load($fixtureDir, null, $dispatcher, $errors), false))
            ->toThrow(SkillRenderException::class, 'strict-boom');
    } finally {
        putenv('BOOST_RENDER_STRICT');
        rmTreeSkillLoader($fixtureDir);
    }
});

it('warns on a SKILL.* file with no renderer, and does NOT false-trigger on asset files (#85)', function (): void {
    $dir = sys_get_temp_dir() . '/boost-sl-skip-' . bin2hex(random_bytes(8));
    mkdir($dir . '/templated', 0o755, recursive: true);
    mkdir($dir . '/plain', 0o755, recursive: true);
    // A SKILL.* file with no registered renderer → must warn (silent-loss guard).
    file_put_contents($dir . '/templated/SKILL.blade.php', "---\nname: templated\n---\nBlade skill.");
    // A normal skill PLUS a non-SKILL asset alongside it → the asset must NOT warn
    // (detection is scoped to the SKILL.* convention).
    file_put_contents($dir . '/plain/SKILL.md', "---\nname: plain\n---\nBody.");
    file_put_contents($dir . '/plain/snippet.blade.php', 'asset partial, not a skill file');

    try {
        $errors = [];
        $warnings = [];
        $skills = iterator_to_array(loader()->load($dir, null, null, $errors, $warnings), false);

        $names = array_map(static fn (Skill $s): string => $s->name, $skills);
        expect($names)->toContain('plain')
            ->and($names)->not->toContain('templated')
            // Exactly one warning — the SKILL.blade.php. snippet.blade.php is not
            // a SKILL.* file, so it must not appear.
            ->and($warnings)->toHaveCount(1)
            ->and($warnings[0])->toContain('SKILL.blade.php')
            ->and($warnings[0])->not->toContain('snippet')
            ->and($errors)->toBeEmpty();
    } finally {
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
});

it('collects asset siblings for a nested skill; flat skills have none (1.3.0)', function (): void {
    $dir = sys_get_temp_dir() . '/boost-skill-assets-' . bin2hex(random_bytes(8));
    mkdir($dir . '/codex-review/scripts', 0o755, true);
    mkdir($dir . '/codex-review/references', 0o755, true);
    file_put_contents($dir . '/codex-review/SKILL.md', "---\nname: codex-review\n---\nNested skill body.");
    file_put_contents($dir . '/codex-review/scripts/run-codex-review.mjs', "#!/usr/bin/env node\nconsole.log('review');\n");
    file_put_contents($dir . '/codex-review/references/api.md', "# api reference\n");
    // Never assets: entry candidates, backups, hidden files.
    file_put_contents($dir . '/codex-review/SKILL.md.bak', 'backup');
    file_put_contents($dir . '/codex-review/scripts/run-codex-review.mjs~', 'editor temp');
    file_put_contents($dir . '/codex-review/.hidden', 'hidden');
    file_put_contents($dir . '/flat-skill.md', "---\nname: flat-skill\n---\nFlat body.");

    try {
        /** @var list<Skill> $skills */
        $skills = iterator_to_array(loader()->load($dir), false);
        $byName = [];
        foreach ($skills as $skill) {
            $byName[$skill->name] = $skill;
        }

        expect($byName)->toHaveKeys(['codex-review', 'flat-skill']);

        $assetPaths = array_map(
            fn (SkillAsset $a): string => $a->relativePath,
            $byName['codex-review']->assets,
        );
        expect($assetPaths)->toBe(['references/api.md', 'scripts/run-codex-review.mjs'])
            ->and($byName['codex-review']->assets[1]->contents)->toContain("console.log('review');")
            ->and($byName['flat-skill']->assets)->toBe([]);
    } finally {
        rmTreeSkillLoader($dir);
    }
});
