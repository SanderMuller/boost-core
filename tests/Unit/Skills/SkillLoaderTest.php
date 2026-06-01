<?php declare(strict_types=1);

use SanderMuller\BoostCore\Contracts\SkillRenderer;
use SanderMuller\BoostCore\Skills\FrontmatterParser;
use SanderMuller\BoostCore\Skills\Rendering\PassthroughRenderer;
use SanderMuller\BoostCore\Skills\Rendering\RenderContext;
use SanderMuller\BoostCore\Skills\Rendering\SkillRendererDispatcher;
use SanderMuller\BoostCore\Skills\Rendering\SkillRenderException;
use SanderMuller\BoostCore\Skills\Skill;
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
        ->and($box->ctx->sourcePath)->toContain('host');
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
    mkdir($fixtureDir . '/anonymous', 0o755, true);
    file_put_contents($fixtureDir . '/anonymous/skill.md', 'Body.');

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
    mkdir($fixtureDir . '/one', 0o755, true);
    mkdir($fixtureDir . '/two', 0o755, true);
    file_put_contents($fixtureDir . '/one/a.md', 'A.');
    file_put_contents($fixtureDir . '/two/b.md', 'B.');

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
    mkdir($fixtureDir . '/one', 0o755, true);
    file_put_contents($fixtureDir . '/one/a.md', 'A.');

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
