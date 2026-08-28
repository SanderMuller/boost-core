<?php declare(strict_types=1);

use SanderMuller\BoostCore\Commands\BoostBaseCommand;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Component\Console\Tester\CommandTester;

/**
 * Probe double for the shared picker guard. The guard is `protected` on
 * {@see BoostBaseCommand} and identical for all three real call sites
 * (`ScanCommand:81`, `InstallCommand:94`, `RemoteCommand:122`), so it is
 * tested once here through a minimal subclass.
 */
final class InteractivityGuardProbeCommand extends BoostBaseCommand
{
    protected function configure(): void
    {
        $this->setName('guard:probe');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);

        return $this->isInteractiveOrExplain($input, $io, 'the picker needs an interactive terminal.')
            ? self::SUCCESS
            : self::FAILURE;
    }
}

afterEach(function (): void {
    BoostBaseCommand::probeTtyUsing(null);
});

it('refuses the picker when no TTY is attached and --no-interaction was not passed', function (): void {
    // The exact CI / git-hook / agent-shell shape: Symfony reports the input as
    // interactive (only `--no-interaction` clears that flag — Application.php:1030),
    // but there is no terminal, so laravel/prompts would silently return its
    // precomputed defaults instead of prompting (Prompt.php:111-115).
    BoostBaseCommand::probeTtyUsing(fn (): bool => false);

    $tester = new CommandTester(new InteractivityGuardProbeCommand());
    $tester->execute([], ['interactive' => true]);

    expect($tester->getStatusCode())->toBe(1)
        ->and($tester->getDisplay())->toContain('interactive terminal');
});

it('runs the picker when a TTY is attached', function (): void {
    BoostBaseCommand::probeTtyUsing(fn (): bool => true);

    $tester = new CommandTester(new InteractivityGuardProbeCommand());
    $tester->execute([], ['interactive' => true]);

    expect($tester->getStatusCode())->toBe(0)
        ->and($tester->getDisplay())->not->toContain('interactive terminal');
});

it('still refuses the picker under --no-interaction even with a TTY attached', function (): void {
    BoostBaseCommand::probeTtyUsing(fn (): bool => true);

    $tester = new CommandTester(new InteractivityGuardProbeCommand());
    $tester->execute([], ['interactive' => false]);

    expect($tester->getStatusCode())->toBe(1)
        ->and($tester->getDisplay())->toContain('interactive terminal');
});
