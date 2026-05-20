<?php declare(strict_types=1);

namespace SanderMuller\BoostCore\Config;

/**
 * Loads `boost.php` from a project root and resolves it into a BoostConfig.
 *
 * `boost.php` must:
 *
 * 1. Exist at `$projectRoot/boost.php` (or the explicit `$configFile` path).
 * 2. Return a {@see BoostConfigBuilder} instance — typically via
 *    `BoostConfig::configure()->with*()` chained calls.
 *
 * Anything else is an error.
 */
final class BoostConfigLoader
{
    /**
     * @throws BoostConfigNotFoundException
     * @throws InvalidBoostConfigException
     */
    public function load(string $projectRoot, ?string $configFile = null): BoostConfig
    {
        $projectRoot = rtrim($projectRoot, '/');
        $path = $configFile ?? $projectRoot . '/boost.php';

        if (! is_file($path)) {
            throw new BoostConfigNotFoundException($path);
        }

        $result = require $path;

        if (! $result instanceof BoostConfigBuilder) {
            throw new InvalidBoostConfigException(
                $path,
                sprintf(
                    'expected return value of type %s, got %s.',
                    BoostConfigBuilder::class,
                    get_debug_type($result),
                ),
            );
        }

        return $result->build($projectRoot);
    }
}
