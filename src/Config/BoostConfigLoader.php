<?php declare(strict_types=1);

namespace SanderMuller\BoostCore\Config;

use Throwable;

/**
 * Loads `boost.php` from a project root and resolves it into a BoostConfig.
 *
 * `boost.php` must:
 *
 * 1. Exist at `$projectRoot/boost.php` or `$projectRoot/.config/boost.php` (or the
 *    explicit `$configFile` path). Location is resolved by {@see BoostConfigPath}.
 * 2. Return a {@see BoostConfigBuilder} instance — typically via
 *    `BoostConfig::configure()->with*()` chained calls.
 *
 * Anything else is an error.
 *
 * @internal
 */
final class BoostConfigLoader
{
    /**
     * @throws BoostConfigNotFoundException
     * @throws InvalidBoostConfigException
     * @throws AmbiguousBoostConfigException
     */
    public function load(string $projectRoot, ?string $configFile = null): BoostConfig
    {
        $projectRoot = rtrim($projectRoot, '/');
        $resolved = BoostConfigPath::resolve($projectRoot, $configFile);
        $path = $resolved->path;

        if (! $resolved->exists) {
            throw new BoostConfigNotFoundException($path);
        }

        try {
            $result = require $path;
        } catch (Throwable $throwable) {
            // A throw while EVALUATING boost.php (a stale pre-0.20 variadic
            // `withTags(...)` TypeError, a syntax error, an undefined symbol) would
            // otherwise escape as a raw fatal — breaking `composer update` mid-run
            // for any caller that doesn't catch \Throwable. Convert it to the typed,
            // catchable InvalidBoostConfigException so wrappers + the CLI can surface
            // an actionable message instead of a 500.
            throw new InvalidBoostConfigException($path, $this->describeEvaluationError($throwable), $throwable);
        }

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

    /**
     * Turn a raw evaluation failure into an actionable reason. The common one is
     * the 0.20 array-only break: a pre-0.20 `withTags(Tag::A, Tag::B)` /
     * `withAgents(...)` variadic call fatals at the call site INSIDE boost.php
     * during `require`, before any migration can run — so point the operator
     * straight at the array form.
     */
    private function describeEvaluationError(Throwable $throwable): string
    {
        $message = $throwable->getMessage();

        if (preg_match('/(withTags|withAgents)\(\):\s*Argument #1 .*must be of type array/', $message) === 1) {
            return sprintf(
                'a pre-0.20 variadic builder call. Wrap the arguments in an array — '
                . '`->withTags([Tag::A, Tag::B])` / `->withAgents([Agent::A, Agent::B])` '
                . '(0.20 changed these to take an array). Original error: %s',
                $message,
            );
        }

        return sprintf('evaluating the file threw %s: %s', $throwable::class, $message);
    }
}
