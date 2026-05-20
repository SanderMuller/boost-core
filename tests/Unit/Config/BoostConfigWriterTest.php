<?php declare(strict_types=1);

use SanderMuller\BoostCore\Config\BoostConfigBuilder;
use SanderMuller\BoostCore\Config\BoostConfigLoader;
use SanderMuller\BoostCore\Config\BoostConfigWriteException;
use SanderMuller\BoostCore\Config\BoostConfigWriter;
use SanderMuller\BoostCore\Enums\Agent;

function tempConfigPath(string $contents): string
{
    $path = sys_get_temp_dir() . '/boost-cfg-writer-' . bin2hex(random_bytes(6)) . '.php';
    file_put_contents($path, $contents);

    return $path;
}

it('updates withAgents in an existing config', function (): void {
    $path = tempConfigPath(<<<'PHP'
<?php
declare(strict_types=1);
use SanderMuller\BoostCore\Config\BoostConfig;
use SanderMuller\BoostCore\Enums\Agent;
return BoostConfig::configure()
    ->withAgents([])
    ->withAllowedVendors([]);
PHP);

    try {
        (new BoostConfigWriter())->update($path, [Agent::CLAUDE_CODE, Agent::CURSOR], [], []);

        $config = (new BoostConfigLoader())->load(dirname($path), $path);
        expect($config->agents)->toEqual([Agent::CLAUDE_CODE, Agent::CURSOR]);
    } finally {
        @unlink($path);
    }
});

it('updates withAllowedVendors in an existing config', function (): void {
    $path = tempConfigPath(<<<'PHP'
<?php
declare(strict_types=1);
use SanderMuller\BoostCore\Config\BoostConfig;
return BoostConfig::configure()
    ->withAllowedVendors(['old/vendor']);
PHP);

    try {
        (new BoostConfigWriter())->update($path, [], ['new/vendor-a', 'new/vendor-b'], []);

        $config = (new BoostConfigLoader())->load(dirname($path), $path);
        expect($config->allowedVendors)->toEqual(['new/vendor-a', 'new/vendor-b']);
    } finally {
        @unlink($path);
    }
});

it('inserts a method that was not previously in the chain', function (): void {
    $path = tempConfigPath(<<<'PHP'
<?php
declare(strict_types=1);
use SanderMuller\BoostCore\Config\BoostConfig;
return BoostConfig::configure();
PHP);

    try {
        (new BoostConfigWriter())->update($path, [Agent::COPILOT], ['inserted/vendor'], ['Disabled\\Emitter']);

        $config = (new BoostConfigLoader())->load(dirname($path), $path);
        expect($config->agents)->toEqual([Agent::COPILOT])
            ->and($config->allowedVendors)
            ->toEqual(['inserted/vendor'])
            ->and($config->disabledEmitters)
            ->toEqual(['Disabled\\Emitter']);
    } finally {
        @unlink($path);
    }
});

it('refuses to edit a config that does not start with BoostConfig::configure()', function (): void {
    $path = tempConfigPath(<<<'PHP'
<?php
declare(strict_types=1);
$foo = "something else";
return $foo;
PHP);

    try {
        (new BoostConfigWriter())->update($path, [Agent::CLAUDE_CODE], [], []);
    } finally {
        @unlink($path);
    }
})->throws(BoostConfigWriteException::class, 'BoostConfig::configure');

it('refuses to edit a config with a parse error', function (): void {
    $path = tempConfigPath("<?php\nreturn BoostConfig::configure()->[unclosed");

    try {
        (new BoostConfigWriter())->update($path, [Agent::CLAUDE_CODE], [], []);
    } finally {
        @unlink($path);
    }
})->throws(BoostConfigWriteException::class, 'parse error');

it('refuses to edit a non-existent file', function (): void {
    (new BoostConfigWriter())->update('/nonexistent/' . bin2hex(random_bytes(4)) . '/boost.php', [], [], []);
})->throws(BoostConfigWriteException::class, 'does not exist');

it('round-trips: write then load gives back the same data', function (): void {
    $path = tempConfigPath(<<<'PHP'
<?php
declare(strict_types=1);
use SanderMuller\BoostCore\Config\BoostConfig;
use SanderMuller\BoostCore\Enums\Agent;
return BoostConfig::configure();
PHP);

    try {
        (new BoostConfigWriter())->update(
            $path,
            [Agent::CLAUDE_CODE, Agent::CURSOR, Agent::COPILOT],
            ['acme/foo', 'acme/bar'],
            ['Acme\\SomeEmitter'],
        );

        $config = (new BoostConfigLoader())->load(dirname($path), $path);

        expect($config->agents)->toEqual([Agent::CLAUDE_CODE, Agent::CURSOR, Agent::COPILOT])
            ->and($config->allowedVendors)
            ->toEqual(['acme/foo', 'acme/bar'])
            ->and($config->disabledEmitters)
            ->toEqual(['Acme\\SomeEmitter']);

        // Loadable as a BoostConfigBuilder when required directly.
        $builder = require $path;
        expect($builder)->toBeInstanceOf(BoostConfigBuilder::class);
    } finally {
        @unlink($path);
    }
});

it('preserves the header docblock, inline comments, and use imports', function (): void {
    $path = tempConfigPath(<<<'PHP'
<?php declare(strict_types=1);

use SanderMuller\BoostCore\Config\BoostConfig;
use SanderMuller\BoostCore\Enums\Agent;

/**
 * boost-core configuration.
 *
 * Docs: https://github.com/sandermuller/boost-core
 */
return BoostConfig::configure()
    // Which AI agents to publish to.
    ->withAgents([])

    // Vendors allowed to publish.
    ->withAllowedVendors([])

    ->withDisabledEmitters([])
;
PHP);

    try {
        (new BoostConfigWriter())->update($path, [Agent::CLAUDE_CODE], ['acme/foo'], []);

        $written = (string) file_get_contents($path);

        expect($written)->toContain('* boost-core configuration.')
            ->toContain('* Docs: https://github.com/sandermuller/boost-core')
            ->toContain('// Which AI agents to publish to.')
            ->toContain('// Vendors allowed to publish.')
            ->toContain('use SanderMuller\BoostCore\Config\BoostConfig;')
            ->toContain('use SanderMuller\BoostCore\Enums\Agent;');
    } finally {
        @unlink($path);
    }
});

it('expands a non-empty array one item per line with a trailing comma', function (): void {
    $path = tempConfigPath(<<<'PHP'
<?php
declare(strict_types=1);
use SanderMuller\BoostCore\Config\BoostConfig;
return BoostConfig::configure()
    ->withAgents([])
    ->withAllowedVendors([])
    ->withDisabledEmitters([]);
PHP);

    try {
        (new BoostConfigWriter())->update(
            $path,
            [Agent::CLAUDE_CODE, Agent::COPILOT],
            ['acme/foo', 'acme/bar'],
            [],
        );

        $written = (string) file_get_contents($path);

        // Each item on its own line, trailing comma after the last.
        // The writer emits the Agent enum fully-qualified (leading `\`).
        expect($written)
            ->toContain("->withAgents([\n")
            ->toContain('\\' . Agent::class . "::CLAUDE_CODE,\n")
            ->toContain('\\' . Agent::class . "::COPILOT,\n")
            ->toContain("'acme/foo',\n")
            ->toContain("'acme/bar',\n")
            // Empty array stays inline.
            ->toContain('->withDisabledEmitters([])');
    } finally {
        @unlink($path);
    }
});
