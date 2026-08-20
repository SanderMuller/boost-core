<?php declare(strict_types=1);

namespace SanderMuller\BoostCore\Commands;

use JsonException;
use SanderMuller\BoostCore\Enums\Agent;

/**
 * Reads laravel/boost's own state file, `boost.json`.
 *
 * That file is laravel/boost's install state — its selected agents, the packages
 * and skills it installed, its feature flags — written by `boost:install` and read
 * back by `boost:install` / `boost:update`. boost-core never writes it and never
 * depends on it; this reader exists so the ADVISORY layer (the install picker and
 * `boost doctor`) can explain a coexisting setup instead of ignoring it.
 *
 * Kept alongside {@see CoexistenceReporter} so everything that knows laravel/boost
 * by name stays in the command layer — the sync engine remains tool-agnostic.
 *
 * @internal
 */
final class LaravelBoostState
{
    public const FILE = 'boost.json';

    /**
     * The agents laravel/boost is configured for, translated to boost-core's enum.
     *
     * Its `boost.json` stores `Agent::name()` values (snake_case), ours are
     * kebab-case enum values; the only spelling that differs is
     * `claude_code` → `claude-code`, so a `_` → `-` swap covers the whole set.
     * Agents boost-core has no case for (`factory`, `grok_build`, `pi`, `zed`)
     * are unmappable and returned separately rather than dropped, so
     * callers can say why an agent didn't carry over.
     *
     * @return array{agents: list<Agent>, unmappable: list<string>}
     */
    public function agents(string $projectRoot): array
    {
        $names = $this->stringList($this->decode($projectRoot)['agents'] ?? null);

        $agents = [];
        $unmappable = [];
        foreach ($names as $name) {
            $agent = Agent::tryFrom(str_replace('_', '-', $name));
            if ($agent instanceof Agent) {
                $agents[$agent->value] = $agent;

                continue;
            }

            $unmappable[$name] = true;
        }

        return ['agents' => array_values($agents), 'unmappable' => array_keys($unmappable)];
    }

    /**
     * Skill names laravel/boost tracks as installed.
     *
     * @return list<string>
     */
    public function skillNames(string $projectRoot): array
    {
        return $this->stringList($this->decode($projectRoot)['skills'] ?? null);
    }

    public function exists(string $projectRoot): bool
    {
        return is_file($this->path($projectRoot));
    }

    /**
     * The decoded state file — an empty array when absent, unreadable, malformed,
     * or not a JSON object. No claim is ever made from a file we can't read.
     *
     * @return array<string, mixed>
     */
    private function decode(string $projectRoot): array
    {
        $path = $this->path($projectRoot);
        if (! is_file($path)) {
            return [];
        }

        $raw = @file_get_contents($path);
        if ($raw === false) {
            return [];
        }

        try {
            $decoded = json_decode($raw, true, 512, JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            return [];
        }

        if (! is_array($decoded)) {
            return [];
        }

        /** @var array<string, mixed> $decoded */
        return $decoded;
    }

    /**
     * @return list<string>
     */
    private function stringList(mixed $value): array
    {
        if (! is_array($value)) {
            return [];
        }

        $strings = [];
        foreach ($value as $entry) {
            if (is_string($entry) && $entry !== '') {
                $strings[] = $entry;
            }
        }

        return $strings;
    }

    private function path(string $projectRoot): string
    {
        return rtrim($projectRoot, '/') . '/' . self::FILE;
    }
}
