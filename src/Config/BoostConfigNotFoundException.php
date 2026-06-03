<?php declare(strict_types=1);

namespace SanderMuller\BoostCore\Config;

use RuntimeException;

/**
 * Thrown by {@see BoostConfig::load()} when no `boost.php` is found at the
 * expected path. Catchable by name so a wrapper can give a friendly
 * "no boost config" message instead of letting it bubble.
 *
 * @api
 */
final class BoostConfigNotFoundException extends RuntimeException
{
    public function __construct(public readonly string $expectedPath)
    {
        parent::__construct(sprintf(
            "boost.php not found at %s.\n\nRun `vendor/bin/boost install` to generate a starter config and pick agents/vendors.",
            $expectedPath,
        ));
    }
}
