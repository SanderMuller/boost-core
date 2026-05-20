<?php declare(strict_types=1);

use SanderMuller\BoostCore\Config\BoostConfigBuilder;
use SanderMuller\BoostCore\Enums\Agent;
use SanderMuller\BoostCore\Enums\Tag;

it('builds a config with all explicit values', function (): void {
    $config = (new BoostConfigBuilder())
        ->withAgents([Agent::CLAUDE_CODE, Agent::CURSOR])
        ->withAllowedVendors(['doctrine/orm'])
        ->withSkillsPath('/host/.ai/skills')
        ->withGuidelinesPath('/host/.ai/guidelines')
        ->withDisabledEmitters(['Foo\\Emitter'])
        ->build('/host');

    expect($config->agents)->toEqual([Agent::CLAUDE_CODE, Agent::CURSOR])
        ->and($config->allowedVendors)
        ->toEqual(['doctrine/orm'])
        ->and($config->skillsPath)
        ->toBe('/host/.ai/skills')
        ->and($config->guidelinesPath)
        ->toBe('/host/.ai/guidelines')
        ->and($config->disabledEmitters)
        ->toEqual(['Foo\\Emitter']);
});

it('falls back to convention paths when not explicitly set', function (): void {
    $config = (new BoostConfigBuilder())->build('/some/project');

    expect($config->skillsPath)->toBe('/some/project/.ai/skills')
        ->and($config->guidelinesPath)
        ->toBe('/some/project/.ai/guidelines');
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

it('starts with empty tags and excluded skills', function (): void {
    $config = (new BoostConfigBuilder())->build('/x');

    expect($config->tags)->toBeEmpty()
        ->and($config->excludedSkills)->toBeEmpty();
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
