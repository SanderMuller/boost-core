<?php

declare(strict_types=1);

namespace SanderMuller\BoostCore;

use Composer\Plugin\Capability\CommandProvider;
use SanderMuller\BoostCore\Commands\CommandRegistry;
use Symfony\Component\Console\Command\Command;

final class BoostCoreCommandProvider implements CommandProvider
{
    /**
     * Intentional variance with parent's `array<BaseCommand>` — our commands
     * extend Symfony\Console\Command\Command (not Composer\Command\BaseCommand)
     * so the same registry can power both the Composer plugin AND the
     * standalone bin/boost entry point.
     *
     * @return array<Command>
     */
    #[\Override]
    public function getCommands(): array
    {
        return CommandRegistry::commands();
    }
}
