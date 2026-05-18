<?php

declare(strict_types=1);

namespace SanderMuller\BoostCore\Config;

use RuntimeException;

final class BoostConfigNotFoundException extends RuntimeException
{
    public function __construct(public readonly string $expectedPath)
    {
        parent::__construct(sprintf(
            "boost.php not found at %s.\n\nRun `composer boost:install` to generate a starter config and pick agents/vendors.",
            $expectedPath,
        ));
    }
}
