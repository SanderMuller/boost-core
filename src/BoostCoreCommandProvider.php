<?php

declare(strict_types=1);

namespace SanderMuller\BoostCore;

use Composer\Command\BaseCommand;
use Composer\Plugin\Capability\CommandProvider;
use Override;
use SanderMuller\BoostCore\Commands\BaseCommandAdapter;
use SanderMuller\BoostCore\Commands\CommandRegistry;
use Symfony\Component\Console\Command\Command;

final class BoostCoreCommandProvider implements CommandProvider
{
    /**
     * Composer's CommandProvider capability runtime-validates that each
     * returned command is a `Composer\Command\BaseCommand` — plain Symfony
     * commands are rejected with "Plugin capability ... returned an invalid
     * value". We keep CommandRegistry returning plain Symfony commands so
     * the standalone `bin/boost` path stays Composer-free, then wrap each
     * one in BaseCommandAdapter here.
     *
     * This file (and the adapter it references) is only autoloaded inside
     * an active Composer process, where `Composer\Command\BaseCommand` is
     * always available.
     *
     * @return array<BaseCommand>
     */
    #[Override]
    public function getCommands(): array
    {
        return array_map(
            static fn (Command $cmd): BaseCommand => new BaseCommandAdapter($cmd),
            CommandRegistry::commands(),
        );
    }
}
