<?php declare(strict_types=1);

use SanderMuller\BoostCore\Commands\BoostApplication;
use SanderMuller\BoostCore\Commands\TouchesResolutionPipeline;
use SanderMuller\BoostCore\Sync\WrapperEntryPointMap;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Tester\ApplicationTester;

class GateProbeCommand extends Command
{
    public bool $ran = false;

    public function __construct(private readonly string $commandName)
    {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->setName($this->commandName);
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $this->ran = true;
        $output->writeln('command body ran');

        return self::SUCCESS;
    }
}

final class GatePipelineCommand extends GateProbeCommand implements TouchesResolutionPipeline {}

/**
 * @param  array<string, array{package: string, invocation: string}>  $claims
 * @param  array<string, list<string>>  $reserved
 */
function gateApp(Command $command, array $claims = [], array $reserved = []): ApplicationTester
{
    $app = new BoostApplication('boost', 'test', new WrapperEntryPointMap($claims, $reserved));
    $app->setAutoExit(false);
    $app->setCatchExceptions(false);
    $app->addCommands([$command]);

    return new ApplicationTester($app);
}

afterEach(function (): void {
    putenv('BOOST_STRICT_ENTRY_POINT');
});

it('runs a covered command but names the wrapper invocation instead', function (): void {
    // 1.x contract: exit codes are part of the frozen CLI surface, and human
    // output is explicitly not. So the banner ships now and the refusal does
    // not — a covered command still runs and still exits 0.
    $command = new GateProbeCommand('sync');
    $tester = gateApp($command, ['sync' => ['package' => 'acme/wrapper', 'invocation' => 'php artisan acme:sync']]);

    $exit = $tester->run(['command' => 'sync']);

    expect($exit)->toBe(0)
        ->and($command->ran)->toBeTrue()
        ->and($tester->getDisplay())->toContain('php artisan acme:sync')
        ->and($tester->getDisplay())->toContain('acme/wrapper');
});

it('refuses a covered command under strict entry-point mode', function (): void {
    putenv('BOOST_STRICT_ENTRY_POINT=1');

    $command = new GateProbeCommand('sync');
    $tester = gateApp($command, ['sync' => ['package' => 'acme/wrapper', 'invocation' => 'php artisan acme:sync']]);

    $exit = $tester->run(['command' => 'sync']);

    expect($exit)->toBe(1)
        ->and($command->ran)->toBeFalse()
        ->and($tester->getDisplay())->toContain('php artisan acme:sync');
});

it('warns that an uncovered pipeline command returns a short result', function (): void {
    // `scan` and `tags` have no wrapper counterpart, so redirecting would name
    // a command that does not exist. They run, and the operator is told the
    // result omits whatever the wrapper injects.
    $command = new GatePipelineCommand('tags');
    $tester = gateApp($command, ['sync' => ['package' => 'acme/wrapper', 'invocation' => 'php artisan acme:sync']]);

    $exit = $tester->run(['command' => 'tags']);

    expect($exit)->toBe(0)
        ->and($command->ran)->toBeTrue()
        ->and($tester->getDisplay())->toContain('incomplete');
});

it('stays silent for a command that neither touches the pipeline nor is covered', function (): void {
    $command = new GateProbeCommand('paths');
    $tester = gateApp($command, ['sync' => ['package' => 'acme/wrapper', 'invocation' => 'php artisan acme:sync']]);

    $tester->run(['command' => 'paths']);

    expect($tester->getDisplay())->not->toContain('acme/wrapper')
        ->and($tester->getDisplay())->toContain('command body ran');
});

it('stays silent when no wrapper is installed', function (): void {
    $command = new GatePipelineCommand('sync');
    $tester = gateApp($command);

    $tester->run(['command' => 'sync']);

    expect($tester->getDisplay())->not->toContain('incomplete')
        ->and($tester->getDisplay())->toContain('command body ran');
});

it('does not gate a help invocation', function (): void {
    putenv('BOOST_STRICT_ENTRY_POINT=1');

    $command = new GateProbeCommand('sync');
    $tester = gateApp($command, ['sync' => ['package' => 'acme/wrapper', 'invocation' => 'php artisan acme:sync']]);

    $exit = $tester->run(['command' => 'sync', '--help' => true]);

    expect($exit)->toBe(0)
        ->and($tester->getDisplay())->not->toContain('php artisan acme:sync');
});

it('never refuses doctor, even under strict mode', function (): void {
    // Reserved. `WrapperEntryPoints` drops a `doctor` claim during discovery,
    // and the gate refuses to honour one that reaches it by any other route.
    putenv('BOOST_STRICT_ENTRY_POINT=1');

    $command = new GateProbeCommand('doctor');
    $tester = gateApp($command, ['doctor' => ['package' => 'acme/wrapper', 'invocation' => 'php artisan acme:doctor']]);

    $exit = $tester->run(['command' => 'doctor']);

    expect($exit)->toBe(0)
        ->and($command->ran)->toBeTrue();
});
