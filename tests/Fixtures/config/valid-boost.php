<?php

declare(strict_types=1);

use SanderMuller\BoostCore\Config\BoostConfig;
use SanderMuller\BoostCore\Enums\Agent;

return BoostConfig::configure()
    ->withAgents([Agent::CLAUDE_CODE, Agent::CURSOR])
    ->withAllowedVendors([
        'doctrine/orm',
        'symfony/symfony',
    ])
    ->withSkillsPath(__DIR__ . '/custom-skills')
    ->withGuidelinesPath(__DIR__ . '/custom-guidelines')
    ->withDisabledEmitters([
        'Acme\\SomeEmitter',
    ]);
