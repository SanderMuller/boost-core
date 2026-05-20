<?php declare(strict_types=1);

use SanderMuller\BoostCore\Config\BoostConfig;
use SanderMuller\BoostCore\Config\BoostConfigLoader;
use SanderMuller\BoostCore\Config\BoostConfigWriter;
use SanderMuller\BoostCore\Enums\Agent;

/**
 * Round-trip stability tests for BoostConfigWriter.
 *
 * The writer is documented as best-effort on formatting (header docblocks
 * stripped, blank lines may collapse). These tests don't pin the FORMAT —
 * they pin SEMANTIC EQUIVALENCE across a parse → write-unchanged → parse
 * cycle, so a future switch to PHP-Parser's `printFormatPreserving` can be
 * verified against the same contract without rewriting assertions.
 *
 * What's pinned:
 *  - Same agents (order + values)
 *  - Same allowed vendors (order + values)
 *  - Same disabled emitters (order + values)
 *
 * What's NOT pinned (intentionally — these are known-lossy):
 *  - Header docblocks above the `return` statement
 *  - Exact whitespace / blank-line layout
 *  - Inline comments inside the chained method calls
 */
/**
 * @param  list<Agent>  $agents
 * @param  list<string>  $allowedVendors
 * @param  list<string>  $disabledEmitters
 */
function writeAndReload(
    string $initialContents,
    array $agents,
    array $allowedVendors,
    array $disabledEmitters,
): BoostConfig {
    $path = sys_get_temp_dir() . '/boost-cfg-rt-' . bin2hex(random_bytes(6)) . '.php';
    file_put_contents($path, $initialContents);

    try {
        (new BoostConfigWriter())->update($path, $agents, $allowedVendors, $disabledEmitters);

        return (new BoostConfigLoader())->load(dirname($path), $path);
    } finally {
        @unlink($path);
    }
}

it('round-trip preserves agents identity across write+reload', function (): void {
    $initial = <<<'PHP'
<?php
declare(strict_types=1);
use SanderMuller\BoostCore\Config\BoostConfig;
use SanderMuller\BoostCore\Enums\Agent;
return BoostConfig::configure()
    ->withAgents([Agent::CLAUDE_CODE, Agent::CURSOR, Agent::COPILOT])
    ->withAllowedVendors([])
    ->withDisabledEmitters([]);
PHP;

    $config = writeAndReload(
        $initial,
        agents: [Agent::CLAUDE_CODE, Agent::CURSOR, Agent::COPILOT],
        allowedVendors: [],
        disabledEmitters: [],
    );

    expect($config->agents)->toEqual([Agent::CLAUDE_CODE, Agent::CURSOR, Agent::COPILOT]);
});

it('round-trip preserves allowed-vendor order across write+reload', function (): void {
    $initial = <<<'PHP'
<?php
declare(strict_types=1);
use SanderMuller\BoostCore\Config\BoostConfig;
use SanderMuller\BoostCore\Enums\Agent;
return BoostConfig::configure()
    ->withAgents([])
    ->withAllowedVendors(['vendor-a/pkg', 'vendor-b/pkg', 'vendor-c/pkg'])
    ->withDisabledEmitters([]);
PHP;

    $config = writeAndReload(
        $initial,
        agents: [],
        allowedVendors: ['vendor-a/pkg', 'vendor-b/pkg', 'vendor-c/pkg'],
        disabledEmitters: [],
    );

    expect($config->allowedVendors)->toEqual(['vendor-a/pkg', 'vendor-b/pkg', 'vendor-c/pkg']);
});

it('round-trip preserves disabled-emitter FQCNs across write+reload', function (): void {
    $initial = <<<'PHP'
<?php
declare(strict_types=1);
use SanderMuller\BoostCore\Config\BoostConfig;
use SanderMuller\BoostCore\Enums\Agent;
return BoostConfig::configure()
    ->withAgents([])
    ->withAllowedVendors([])
    ->withDisabledEmitters(['Acme\\McpJsonEmitter', 'Acme\\GitignoreEmitter']);
PHP;

    $config = writeAndReload(
        $initial,
        agents: [],
        allowedVendors: [],
        disabledEmitters: ['Acme\\McpJsonEmitter', 'Acme\\GitignoreEmitter'],
    );

    expect($config->disabledEmitters)->toEqual(['Acme\\McpJsonEmitter', 'Acme\\GitignoreEmitter']);
});

it('round-trip preserves all three lists in a single write', function (): void {
    $initial = <<<'PHP'
<?php
declare(strict_types=1);
use SanderMuller\BoostCore\Config\BoostConfig;
use SanderMuller\BoostCore\Enums\Agent;
return BoostConfig::configure()
    ->withAgents([])
    ->withAllowedVendors([])
    ->withDisabledEmitters([]);
PHP;

    $config = writeAndReload(
        $initial,
        agents: [Agent::CLAUDE_CODE, Agent::GEMINI],
        allowedVendors: ['some/vendor'],
        disabledEmitters: ['Some\\Emitter'],
    );

    expect($config->agents)->toEqual([Agent::CLAUDE_CODE, Agent::GEMINI])
        ->and($config->allowedVendors)->toEqual(['some/vendor'])
        ->and($config->disabledEmitters)->toEqual(['Some\\Emitter']);
});

it('double round-trip is idempotent (parse → write → parse → write → parse)', function (): void {
    // Catches drift introduced by the writer itself across consecutive runs —
    // e.g. if the writer ever started normalising something asymmetrically.
    $initial = <<<'PHP'
<?php
declare(strict_types=1);
use SanderMuller\BoostCore\Config\BoostConfig;
use SanderMuller\BoostCore\Enums\Agent;
return BoostConfig::configure()
    ->withAgents([Agent::CLAUDE_CODE])
    ->withAllowedVendors(['acme/pkg'])
    ->withDisabledEmitters(['Acme\\Emitter']);
PHP;

    $path = sys_get_temp_dir() . '/boost-cfg-rt-' . bin2hex(random_bytes(6)) . '.php';
    file_put_contents($path, $initial);

    try {
        $writer = new BoostConfigWriter();
        $loader = new BoostConfigLoader();

        $writer->update($path, [Agent::CLAUDE_CODE], ['acme/pkg'], ['Acme\\Emitter']);
        $first = $loader->load(dirname($path), $path);

        $writer->update($path, $first->agents, $first->allowedVendors, $first->disabledEmitters);
        $second = $loader->load(dirname($path), $path);

        expect($second->agents)->toEqual($first->agents)
            ->and($second->allowedVendors)->toEqual($first->allowedVendors)
            ->and($second->disabledEmitters)->toEqual($first->disabledEmitters);
    } finally {
        @unlink($path);
    }
});

it('round-trip survives starter-template shape (auto-init format)', function (): void {
    // The InstallCommand starter template is what fresh installs land on;
    // BoostConfigWriter is expected to round-trip cleanly through that exact
    // shape including the header docblock + commented-out source-path lines.
    // Header docblock is known-lossy (TODO #10 docs that) — pin behaviour
    // only, not formatting.
    $initial = <<<'PHP'
<?php declare(strict_types=1);

use SanderMuller\BoostCore\Config\BoostConfig;
use SanderMuller\BoostCore\Enums\Agent;

/**
 * boost-core configuration.
 *
 * Generated by `composer boost:install`.
 */
return BoostConfig::configure()
    ->withAgents([])
    ->withAllowedVendors([])
    ->withDisabledEmitters([])
    // ->withSkillsPath(__DIR__ . '/.ai/skills')
    // ->withGuidelinesPath(__DIR__ . '/.ai/guidelines')
;
PHP;

    $config = writeAndReload(
        $initial,
        agents: [Agent::CLAUDE_CODE, Agent::CURSOR],
        allowedVendors: ['acme/skills-pkg'],
        disabledEmitters: [],
    );

    expect($config->agents)->toEqual([Agent::CLAUDE_CODE, Agent::CURSOR])
        ->and($config->allowedVendors)->toEqual(['acme/skills-pkg']);
});
