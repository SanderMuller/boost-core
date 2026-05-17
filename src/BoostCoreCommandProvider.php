<?php

declare(strict_types=1);

namespace SanderMuller\BoostCore;

use Composer\Command\BaseCommand;
use Composer\Plugin\Capability\CommandProvider;
use SanderMuller\BoostCore\Commands\SyncCommand;

final class BoostCoreCommandProvider implements CommandProvider
{
    /**
     * @return array<BaseCommand>
     */
    public function getCommands(): array
    {
        return [
            new SyncCommand,
            // boost:init, boost:install, boost:new, boost:doctor, boost:scan land in later commits.
        ];
    }
}
