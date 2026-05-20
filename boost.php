<?php declare(strict_types=1);

use SanderMuller\BoostCore\Config\BoostConfig;
use SanderMuller\BoostCore\Enums\Agent;

return BoostConfig::configure()
    ->withAgents([
        Agent::CLAUDE_CODE,
        Agent::CURSOR,
        Agent::COPILOT,
        Agent::CODEX,
        Agent::GEMINI,
        Agent::JUNIE,
        Agent::KIRO,
        Agent::OPENCODE,
        Agent::AMP,
    ])
    ->withAllowedVendors([])
    ->withDisabledEmitters([]);
