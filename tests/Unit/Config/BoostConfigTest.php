<?php declare(strict_types=1);

use SanderMuller\BoostCore\Config\BoostConfig;
use SanderMuller\BoostCore\Config\BoostConfigBuilder;
use SanderMuller\BoostCore\Enums\Agent;

it('exposes a static `configure` returning a BoostConfigBuilder', function (): void {
    expect(BoostConfig::configure())->toBeInstanceOf(BoostConfigBuilder::class);
});

it('reports membership via hasAgent', function (): void {
    $config = new BoostConfig(
        agents: [Agent::CLAUDE_CODE, Agent::CURSOR],
        allowedVendors: [],
        skillsPath: '/tmp/skills',
        guidelinesPath: '/tmp/guidelines',
        disabledEmitters: [],
    );

    expect($config->hasAgent(Agent::CLAUDE_CODE))->toBeTrue()
        ->and($config->hasAgent(Agent::CURSOR))
        ->toBeTrue()
        ->and($config->hasAgent(Agent::COPILOT))
        ->toBeFalse();
});

it('reports allowlist membership via isVendorAllowed', function (): void {
    $config = new BoostConfig(
        agents: [],
        allowedVendors: ['doctrine/orm', 'symfony/symfony'],
        skillsPath: '/tmp/skills',
        guidelinesPath: '/tmp/guidelines',
        disabledEmitters: [],
    );

    expect($config->isVendorAllowed('doctrine/orm'))->toBeTrue()
        ->and($config->isVendorAllowed('not/allowed'))
        ->toBeFalse();
});

it('reports disabled emitters via isEmitterDisabled', function (): void {
    $config = new BoostConfig(
        agents: [],
        allowedVendors: [],
        skillsPath: '/tmp/skills',
        guidelinesPath: '/tmp/guidelines',
        disabledEmitters: ['Foo\\BarEmitter'],
    );

    expect($config->isEmitterDisabled('Foo\\BarEmitter'))->toBeTrue()
        ->and($config->isEmitterDisabled('Baz\\QuxEmitter'))
        ->toBeFalse();
});
