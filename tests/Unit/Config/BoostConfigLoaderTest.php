<?php declare(strict_types=1);

use SanderMuller\BoostCore\Config\AmbiguousBoostConfigException;
use SanderMuller\BoostCore\Config\BoostConfig;
use SanderMuller\BoostCore\Config\BoostConfigLoader;
use SanderMuller\BoostCore\Config\BoostConfigNotFoundException;
use SanderMuller\BoostCore\Config\InvalidBoostConfigException;
use SanderMuller\BoostCore\Enums\Agent;

function configFixture(string $name): string
{
    return __DIR__ . '/../../Fixtures/config/' . $name;
}

it('loads a fully-configured boost.php fixture', function (): void {
    $config = (new BoostConfigLoader())->load(
        '/fake/project',
        configFixture('valid-boost.php'),
    );

    expect($config->agents)->toEqual([Agent::CLAUDE_CODE, Agent::CURSOR])
        ->and($config->allowedVendors)
        ->toEqual(['doctrine/orm', 'symfony/symfony'])
        ->and($config->skillsPath)
        ->toEndWith('/custom-skills')
        ->and($config->guidelinesPath)
        ->toEndWith('/custom-guidelines')
        ->and($config->disabledEmitters)
        ->toEqual(['Acme\\SomeEmitter']);
});

it('loads a minimal boost.php with all defaults applied', function (): void {
    $config = (new BoostConfigLoader())->load(
        '/fake/project',
        configFixture('minimal-boost.php'),
    );

    expect($config->agents)
        ->toBeEmpty()
        ->and($config->allowedVendors)
        ->toBeEmpty()
        ->and($config->skillsPath)
        ->toBe('/fake/project/.ai/skills')
        ->and($config->guidelinesPath)
        ->toBe('/fake/project/.ai/guidelines')
        ->and($config->disabledEmitters)
        ->toBeEmpty();
});

it('throws when boost.php does not exist', function (): void {
    (new BoostConfigLoader())->load(
        '/fake/project',
        '/nonexistent/' . bin2hex(random_bytes(4)) . '/boost.php',
    );
})->throws(BoostConfigNotFoundException::class);

it('reports the expected path in the not-found exception', function (): void {
    $missing = '/nonexistent/' . bin2hex(random_bytes(4)) . '/boost.php';

    try {
        (new BoostConfigLoader())->load('/fake/project', $missing);
        throw new RuntimeException('Expected BoostConfigNotFoundException');
    } catch (BoostConfigNotFoundException $boostConfigNotFoundException) {
        expect($boostConfigNotFoundException->expectedPath)->toBe($missing);
    }
});

it('throws when boost.php returns the wrong type', function (): void {
    (new BoostConfigLoader())->load(
        '/fake/project',
        configFixture('wrong-return.php'),
    );
})->throws(InvalidBoostConfigException::class, 'BoostConfigBuilder');

it('defaults `load($root)` to looking at $root/boost.php', function (): void {
    // No fixture exists at /fake/project/boost.php — should hit the not-found path.
    (new BoostConfigLoader())->load('/fake/project');
})->throws(BoostConfigNotFoundException::class, '/fake/project/boost.php');

it('round-trips withTags and withExcludedSkills through a boost.php', function (): void {
    $path = sys_get_temp_dir() . '/boost-tags-roundtrip-' . bin2hex(random_bytes(6)) . '.php';
    file_put_contents($path, <<<'PHP'
        <?php declare(strict_types=1);

        use SanderMuller\BoostCore\Config\BoostConfig;
        use SanderMuller\BoostCore\Enums\Tag;

        return BoostConfig::configure()
            ->withTags([Tag::Php, 'jira'])
            ->withExcludedSkills(['acme/pack:unwanted']);
        PHP);

    try {
        $config = (new BoostConfigLoader())->load(dirname($path), $path);

        expect($config->tags)->toBe(['php', 'jira'])
            ->and($config->excludedSkills)->toBe(['acme/pack:unwanted']);
    } finally {
        @unlink($path);
    }
});

it('loads from .config/boost.php when only it exists (no explicit path)', function (): void {
    $dir = sys_get_temp_dir() . '/boost-loader-cfg-' . bin2hex(random_bytes(8));
    mkdir($dir . '/.config', 0o755, recursive: true);
    file_put_contents($dir . '/.config/boost.php', <<<'PHP'
        <?php declare(strict_types=1);

        use SanderMuller\BoostCore\Config\BoostConfig;
        use SanderMuller\BoostCore\Enums\Agent;

        return BoostConfig::configure()->withAgents([Agent::CODEX]);
        PHP);

    try {
        $config = (new BoostConfigLoader())->load($dir);

        // Defaults are project-root-relative — a .config/ config still resolves
        // sources to <root>/.ai/*, NOT <root>/.config/.ai/*.
        expect($config->agents)->toBe([Agent::CODEX])
            ->and($config->skillsPath)->toBe($dir . '/.ai/skills')
            ->and($config->guidelinesPath)->toBe($dir . '/.ai/guidelines');
    } finally {
        @unlink($dir . '/.config/boost.php');
        @rmdir($dir . '/.config');
        @rmdir($dir);
    }
});

it('throws Ambiguous on load when BOTH root and .config/ configs exist', function (): void {
    $dir = sys_get_temp_dir() . '/boost-loader-ambig-' . bin2hex(random_bytes(8));
    mkdir($dir . '/.config', 0o755, recursive: true);
    $body = '<?php return ' . BoostConfig::class . '::configure();';
    file_put_contents($dir . '/boost.php', $body);
    file_put_contents($dir . '/.config/boost.php', $body);

    try {
        expect(fn () => (new BoostConfigLoader())->load($dir))
            ->toThrow(AmbiguousBoostConfigException::class);
    } finally {
        @unlink($dir . '/boost.php');
        @unlink($dir . '/.config/boost.php');
        @rmdir($dir . '/.config');
        @rmdir($dir);
    }
});
