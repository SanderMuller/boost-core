<?php declare(strict_types=1);

namespace SanderMuller\BoostCore\Config;

use RuntimeException;
use Throwable;

/**
 * Thrown by {@see BoostConfig::load()} when a `boost.php` exists but does not
 * return a {@see BoostConfig} — or throws while being evaluated (e.g. a stale
 * pre-0.20 variadic `withTags(...)` call). Catchable by name so a wrapper can
 * surface the reason instead of letting a raw fatal bubble out of `composer`.
 *
 * @api
 */
final class InvalidBoostConfigException extends RuntimeException
{
    public function __construct(public readonly string $configPath, string $reason, ?Throwable $previous = null)
    {
        parent::__construct(sprintf(
            "Invalid boost.php at %s: %s\n\nExpected:\n  return BoostConfig::configure()\n      ->withAgents([...])\n      ->withAllowedVendors([...]);\n",
            $configPath,
            $reason,
        ), 0, $previous);
    }
}
