<?php

declare(strict_types=1);

namespace SanderMuller\BoostCore\Config;

use RuntimeException;

final class InvalidBoostConfigException extends RuntimeException
{
    public function __construct(public readonly string $configPath, string $reason)
    {
        parent::__construct(sprintf(
            "Invalid boost.php at %s: %s\n\nExpected:\n  return BoostConfig::configure()\n      ->withAgents([...])\n      ->withAllowedVendors([...]);\n",
            $configPath,
            $reason,
        ));
    }
}
