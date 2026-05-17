<?php

declare(strict_types=1);

namespace SanderMuller\BoostCore\Config;

use RuntimeException;

final class BoostConfigWriteException extends RuntimeException
{
    public function __construct(public readonly string $configPath, string $reason)
    {
        parent::__construct(sprintf(
            "Refusing to modify boost.php at %s: %s\n\nThe writer expects a simple `return BoostConfig::configure()->with*()...;` shape. Templated or conditional configs must be edited by hand.",
            $configPath,
            $reason,
        ));
    }
}
