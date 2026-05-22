<?php declare(strict_types=1);

use SanderMuller\BoostCore\Commands\CommandRegistry;
use Symfony\Component\Console\Command\Command;

/**
 * Smoke test for boost-core's command surface. Since the Pattern C
 * migration (0.6.0) boost-core is a plain `library`, not a Composer
 * plugin — the command surface is the standalone `bin/boost`, fed by
 * `CommandRegistry`.
 */
it('CommandRegistry exposes the boost command surface', function (): void {
    $commands = CommandRegistry::commands();

    expect($commands)->not->toBeEmpty()
        ->toContainOnlyInstancesOf(Command::class);
});

it('every registered command carries a boost: name', function (): void {
    foreach (CommandRegistry::commands() as $command) {
        expect($command->getName())->toStartWith('boost:');
    }
});
