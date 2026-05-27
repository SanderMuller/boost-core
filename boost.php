<?php declare(strict_types=1);

use SanderMuller\BoostCore\Config\BoostConfig;
use SanderMuller\BoostCore\Enums\Agent;
use SanderMuller\BoostCore\Enums\Tag;

return BoostConfig::configure()
    ->withAgents([
        Agent::CLAUDE_CODE,
        Agent::COPILOT,
        Agent::CODEX,
    ])
    ->withAllowedVendors(['sandermuller/boost-skills'])
    ->withTags(Tag::Php, Tag::Github, 'release-automation')
    ->withExcludedGuidelines([
        // boost-core is a framework-free Composer plugin — no database, no
        // migrations. These ship from boost-skills untagged, so the deny-list
        // is the only lever; `verification-before-completion` is universal.
        'sandermuller/boost-skills:database-safety',
        'sandermuller/boost-skills:migrations',
    ])
    ->withDisabledEmitters([]);
