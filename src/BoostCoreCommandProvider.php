<?php

declare(strict_types=1);

namespace SanderMuller\BoostCore;

use Composer\Plugin\Capability\CommandProvider;
use SanderMuller\BoostCore\Commands\CommandRegistry;
use Symfony\Component\Console\Command\Command;

final class BoostCoreCommandProvider implements CommandProvider
{
    /**
     * @return array<Command>
     */
    public function getCommands(): array
    {
        return CommandRegistry::commands();
    }
}
