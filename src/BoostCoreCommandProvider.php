<?php

declare(strict_types=1);

namespace SanderMuller\BoostCore;

use Composer\Command\BaseCommand;
use Composer\Plugin\Capability\CommandProvider;

final class BoostCoreCommandProvider implements CommandProvider
{
    /**
     * @return array<BaseCommand>
     */
    public function getCommands(): array
    {
        return [
            // boost:init, boost:install, boost:sync, boost:new, boost:doctor, boost:scan
            // To be implemented as src/Commands/* classes land.
        ];
    }
}
