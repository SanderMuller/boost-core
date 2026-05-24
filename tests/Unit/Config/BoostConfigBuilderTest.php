<?php declare(strict_types=1);

use SanderMuller\BoostCore\Config\BoostConfigBuilder;
use SanderMuller\BoostCore\Contracts\SkillRenderer;
use SanderMuller\BoostCore\Enums\Agent;
use SanderMuller\BoostCore\Enums\Tag;
use SanderMuller\BoostCore\Skills\GuidelineTagFilter;
use SanderMuller\BoostCore\Skills\Remote\RemoteSkillSource;
use SanderMuller\BoostCore\Skills\Rendering\InvalidSkillRendererException;
use SanderMuller\BoostCore\Skills\Rendering\PassthroughRenderer;
use SanderMuller\BoostCore\Skills\Rendering\RenderContext;
use SanderMuller\BoostCore\Skills\SkillTagFilter;
use SanderMuller\BoostCore\Sync\InjectedVendorMerger;

it('builds a config with all explicit values', function (): void {
    $config = (new BoostConfigBuilder())
        ->withAgents([Agent::CLAUDE_CODE, Agent::CURSOR])
        ->withAllowedVendors(['doctrine/orm'])
        ->withSkillsPath('/host/.ai/skills')
        ->withGuidelinesPath('/host/.ai/guidelines')
        ->withCommandsPath('/host/.ai/commands')
        ->withDisabledEmitters(['Foo\\Emitter'])
        ->build('/host');

    expect($config->agents)->toEqual([Agent::CLAUDE_CODE, Agent::CURSOR])
        ->and($config->allowedVendors)
        ->toEqual(['doctrine/orm'])
        ->and($config->skillsPath)
        ->toBe('/host/.ai/skills')
        ->and($config->guidelinesPath)
        ->toBe('/host/.ai/guidelines')
        ->and($config->commandsPath)
        ->toBe('/host/.ai/commands')
        ->and($config->disabledEmitters)
        ->toEqual(['Foo\\Emitter']);
});

it('falls back to convention paths when not explicitly set', function (): void {
    $config = (new BoostConfigBuilder())->build('/some/project');

    expect($config->skillsPath)->toBe('/some/project/.ai/skills')
        ->and($config->guidelinesPath)
        ->toBe('/some/project/.ai/guidelines')
        ->and($config->commandsPath)
        ->toBe('/some/project/.ai/commands');
});

it('trims trailing slash from project root when applying defaults', function (): void {
    $config = (new BoostConfigBuilder())->build('/some/project/');

    expect($config->skillsPath)->toBe('/some/project/.ai/skills');
});

it('starts with empty agents, vendors, and disabled emitters', function (): void {
    $config = (new BoostConfigBuilder())->build('/x');

    expect($config->agents)
        ->toBeEmpty()
        ->and($config->allowedVendors)
        ->toBeEmpty()
        ->and($config->disabledEmitters)
        ->toBeEmpty();
});

it('starts with empty tags and exclude lists', function (): void {
    $config = (new BoostConfigBuilder())->build('/x');

    expect($config->tags)->toBeEmpty()
        ->and($config->excludedSkills)->toBeEmpty()
        ->and($config->excludedGuidelines)->toBeEmpty();
});

it('withTags accepts Tag enum cases and raw strings, normalized and deduped', function (): void {
    $config = (new BoostConfigBuilder())
        ->withTags(Tag::Php, 'JIRA', '  laravel  ', 'php')
        ->build('/x');

    expect($config->tags)->toBe(['php', 'jira', 'laravel'])
        ->and($config->hasTag('jira'))->toBeTrue()
        ->and($config->hasTag('frontend'))->toBeFalse();
});

it('withTags drops values that normalize to empty', function (): void {
    $config = (new BoostConfigBuilder())
        ->withTags('php', '   ', '')
        ->build('/x');

    expect($config->tags)->toBe(['php']);
});

it('withExcludedSkills carries vendor:skill deny-list entries', function (): void {
    $config = (new BoostConfigBuilder())
        ->withExcludedSkills(['acme/repo-init:deploy', 'acme/lint-pack:phpcs'])
        ->build('/x');

    expect($config->excludedSkills)->toBe(['acme/repo-init:deploy', 'acme/lint-pack:phpcs']);
});

it('withExcludedGuidelines carries vendor:guideline deny-list entries', function (): void {
    $config = (new BoostConfigBuilder())
        ->withExcludedGuidelines(['acme/skills:database-safety', 'acme/skills:migrations'])
        ->build('/x');

    expect($config->excludedGuidelines)->toBe(['acme/skills:database-safety', 'acme/skills:migrations']);
});

it('withRemoteSkills defaults to an empty list and propagates to BoostConfig', function (): void {
    $config = (new BoostConfigBuilder())
        ->withAgents([Agent::CLAUDE_CODE])
        ->build('/x');

    expect($config->remoteSkills)
        ->toBeEmpty();
});

it('withRemoteSkills stores and propagates the declared sources', function (): void {
    $bundle = RemoteSkillSource::githubBundle('peterfox/agent-skills', 'v1.2.0', ['composer-upgrade']);
    $path = RemoteSkillSource::githubPath('mattpocock/skills', 'main', ['grill-with-docs' => 'skills/engineering/grill-with-docs']);

    $config = (new BoostConfigBuilder())
        ->withAgents([Agent::CLAUDE_CODE])
        ->withRemoteSkills([$bundle, $path])
        ->build('/x');

    expect($config->remoteSkills)->toHaveCount(2)
        ->and($config->remoteSkills[0])->toBe($bundle)
        ->and($config->remoteSkills[1])->toBe($path);
});

it('withRemoteSkills overwrites a prior list (overwrite semantics)', function (): void {
    $first = RemoteSkillSource::githubBundle('a/b', 'v1.0.0', ['x']);
    $second = RemoteSkillSource::githubBundle('c/d', 'v2.0.0', ['y']);

    $config = (new BoostConfigBuilder())
        ->withAgents([Agent::CLAUDE_CODE])
        ->withRemoteSkills([$first])
        ->withRemoteSkills([$second])
        ->build('/x');

    expect($config->remoteSkills)->toHaveCount(1)
        ->and($config->remoteSkills[0])->toBe($second);
});

it('withRemoteSkills rejects two sources sharing (source, version, mode)', function (): void {
    $a = RemoteSkillSource::githubBundle('peterfox/agent-skills', 'v1.2.0', ['composer-upgrade']);
    $b = RemoteSkillSource::githubBundle('peterfox/agent-skills', 'v1.2.0', ['phpstan-developer']);

    expect(fn () => (new BoostConfigBuilder())->withRemoteSkills([$a, $b]))
        ->toThrow(InvalidArgumentException::class, 'duplicate RemoteSkillSource');
});

it('withRemoteSkills allows same source+version in different modes (bundle + path coexist)', function (): void {
    $bundle = RemoteSkillSource::githubBundle('a/b', 'v1.0.0', ['foo']);
    $path = RemoteSkillSource::githubPath('a/b', 'v1.0.0', ['bar' => 'bar']);

    $config = (new BoostConfigBuilder())
        ->withAgents([Agent::CLAUDE_CODE])
        ->withRemoteSkills([$bundle, $path])
        ->build('/x');

    expect($config->remoteSkills)->toHaveCount(2);
});

it('withRemoteSkills allows same source at different versions', function (): void {
    $v1 = RemoteSkillSource::githubBundle('a/b', 'v1.0.0', ['foo']);
    $v2 = RemoteSkillSource::githubBundle('a/b', 'v2.0.0', ['foo']);

    $config = (new BoostConfigBuilder())
        ->withAgents([Agent::CLAUDE_CODE])
        ->withRemoteSkills([$v1, $v2])
        ->build('/x');

    expect($config->remoteSkills)->toHaveCount(2);
});

it('withSkillRenderers defaults to passthrough-only when no renderer registered', function (): void {
    $config = (new BoostConfigBuilder())
        ->withAgents([Agent::CLAUDE_CODE])
        ->build('/x');

    expect($config->skillRenderers)->toHaveCount(1)
        ->and($config->skillRenderers[0])->toBeInstanceOf(PassthroughRenderer::class);
});

it('withSkillRenderers appends passthrough last so user-registered md wins by registration order', function (): void {
    $customMd = new class implements SkillRenderer {
        /** @return list<string> */
        public function extensions(): array
        {
            return ['md'];
        }

        public function render(string $raw, RenderContext $ctx): string
        {
            return $raw;
        }
    };

    $config = (new BoostConfigBuilder())
        ->withAgents([Agent::CLAUDE_CODE])
        ->withSkillRenderers([$customMd])
        ->build('/x');

    expect($config->skillRenderers)->toHaveCount(2)
        ->and($config->skillRenderers[0])->toBe($customMd)
        ->and($config->skillRenderers[1])->toBeInstanceOf(PassthroughRenderer::class);
});

it('throws when two user renderers claim the same extension', function (): void {
    $first = new class implements SkillRenderer {
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
    $second = new class implements SkillRenderer {
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

    expect(fn () => (new BoostConfigBuilder())
        ->withAgents([Agent::CLAUDE_CODE])
        ->withSkillRenderers([$first, $second])
        ->build('/x')
    )->toThrow(InvalidSkillRendererException::class, 'Multiple renderers');
});

it('withDisabledRenderers resolves a conflict by dropping one side', function (): void {
    $first = new class implements SkillRenderer {
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
    $secondClass = new class implements SkillRenderer {
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

    $config = (new BoostConfigBuilder())
        ->withAgents([Agent::CLAUDE_CODE])
        ->withSkillRenderers([$first, $secondClass])
        ->withDisabledRenderers([$secondClass::class])
        ->build('/x');

    expect($config->skillRenderers)->toHaveCount(2)
        ->and($config->skillRenderers[0])->toBe($first);
});

it('disabling PassthroughRenderer is a silent no-op (builder re-appends it)', function (): void {
    $config = (new BoostConfigBuilder())
        ->withAgents([Agent::CLAUDE_CODE])
        ->withDisabledRenderers([PassthroughRenderer::class])
        ->build('/x');

    expect($config->skillRenderers)->toHaveCount(1)
        ->and($config->skillRenderers[0])->toBeInstanceOf(PassthroughRenderer::class);
});

it('passthrough yields to a user-registered md renderer without conflict-throwing', function (): void {
    $customMd = new class implements SkillRenderer {
        /** @return list<string> */
        public function extensions(): array
        {
            return ['md'];
        }

        public function render(string $raw, RenderContext $ctx): string
        {
            return strtoupper($raw);
        }
    };

    // Both claim `md` but build() must NOT throw — passthrough yields silently.
    $config = (new BoostConfigBuilder())
        ->withAgents([Agent::CLAUDE_CODE])
        ->withSkillRenderers([$customMd])
        ->build('/x');

    expect($config->skillRenderers[0])->toBe($customMd);
});

it('throws when user passes their own PassthroughRenderer alongside another md-claiming renderer', function (): void {
    $other = new class implements SkillRenderer {
        /** @return list<string> */
        public function extensions(): array
        {
            return ['md'];
        }

        public function render(string $raw, RenderContext $ctx): string
        {
            return strtoupper($raw);
        }
    };

    // Explicit user-supplied PassthroughRenderer is NOT the same instance
    // as the builder's implicit one — it should be treated as a regular
    // renderer and collide with `$other` over the `md` extension.
    expect(fn () => (new BoostConfigBuilder())
        ->withAgents([Agent::CLAUDE_CODE])
        ->withSkillRenderers([new PassthroughRenderer(), $other])
        ->build('/x')
    )->toThrow(InvalidSkillRendererException::class, 'Multiple renderers');
});

it('extraSkillRenderers inserts between user renderers and the implicit passthrough', function (): void {
    // Construct: user renderer claims `md`. Extras provides another `md`-claimer.
    // Expected order after mergeExtraRenderers: [UserMd, ExtrasMd, Passthrough].
    // Dispatcher first-match-wins → UserMd ALWAYS beats ExtrasMd
    // (user's boost.php is authoritative). Both beat Passthrough.
    $userMd = new class implements SkillRenderer {
        /** @return list<string> */
        public function extensions(): array
        {
            return ['md'];
        }

        public function render(string $raw, RenderContext $ctx): string
        {
            return 'USER:' . $raw;
        }
    };
    $extrasMd = new class implements SkillRenderer {
        /** @return list<string> */
        public function extensions(): array
        {
            return ['md'];
        }

        public function render(string $raw, RenderContext $ctx): string
        {
            return 'EXTRAS:' . $raw;
        }
    };

    $config = (new BoostConfigBuilder())
        ->withAgents([Agent::CLAUDE_CODE])
        ->withSkillRenderers([$userMd])
        ->build('/x');

    $merger = new InjectedVendorMerger(
        new SkillTagFilter(),
        new GuidelineTagFilter(),
    );
    $merged = $merger->mergeExtraRenderers($config, [$extrasMd]);

    expect($merged->skillRenderers)->toHaveCount(3)
        ->and($merged->skillRenderers[0])->toBe($userMd)
        ->and($merged->skillRenderers[1])->toBe($extrasMd)
        ->and($merged->skillRenderers[2])->toBeInstanceOf(PassthroughRenderer::class);
});
