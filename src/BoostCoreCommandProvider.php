<?php

declare(strict_types=1);

namespace SanderMuller\BoostCore;

use Composer\Command\BaseCommand;
use Composer\Plugin\Capability\CommandProvider;
use SanderMuller\BoostCore\Commands\InitCommand;
use SanderMuller\BoostCore\Commands\SyncCommand;

final class BoostCoreCommandProvider implements CommandProvider
{
    /**
     * @return array<BaseCommand>
     */
    public function getCommands(): array
    {
        return [
            new InitCommand,
            new SyncCommand,
            // boost:install, boost:scan, boost:doctor, boost:new land in later commits.
        ];
    }
}
