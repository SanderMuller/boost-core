<?php

declare(strict_types=1);

use SanderMuller\BoostCore\Config\BoostConfigBuilder;
use SanderMuller\BoostCore\Enums\Agent;

it('builds a config with all explicit values', function (): void {
    $config = (new BoostConfigBuilder)
        ->withAgents([Agent::CLAUDE_CODE, Agent::CURSOR])
        ->withAllowedVendors(['doctrine/orm'])
        ->withSkillsPath('/host/.ai/skills')
        ->withGuidelinesPath('/host/.ai/guidelines')
        ->withDisabledEmitters(['Foo\\Emitter'])
        ->build('/host');

    expect($config->agents)->toEqual([Agent::CLAUDE_CODE, Agent::CURSOR]);
    expect($config->allowedVendors)->toEqual(['doctrine/orm']);
    expect($config->skillsPath)->toBe('/host/.ai/skills');
    expect($config->guidelinesPath)->toBe('/host/.ai/guidelines');
    expect($config->disabledEmitters)->toEqual(['Foo\\Emitter']);
});

it('falls back to convention paths when not explicitly set', function (): void {
    $config = (new BoostConfigBuilder)->build('/some/project');

    expect($config->skillsPath)->toBe('/some/project/.ai/skills');
    expect($config->guidelinesPath)->toBe('/some/project/.ai/guidelines');
});

it('trims trailing slash from project root when applying defaults', function (): void {
    $config = (new BoostConfigBuilder)->build('/some/project/');

    expect($config->skillsPath)->toBe('/some/project/.ai/skills');
});

it('starts with empty agents, vendors, and disabled emitters', function (): void {
    $config = (new BoostConfigBuilder)->build('/x');

    expect($config->agents)->toBe([]);
    expect($config->allowedVendors)->toBe([]);
    expect($config->disabledEmitters)->toBe([]);
});
