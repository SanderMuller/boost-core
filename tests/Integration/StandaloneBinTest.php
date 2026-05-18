<?php

declare(strict_types=1);
use Symfony\Component\Process\Process;

/**
 * @return array{exit: int, output: string}
 */
function runBin(string $args): array
{
    $process = Process::fromShellCommandline(
        'php ' . escapeshellarg(dirname(__DIR__, 2) . '/bin/boost') . ' ' . $args,
    );
    $process->run();

    return ['exit' => $process->getExitCode() ?? 0, 'output' => $process->getOutput() . $process->getErrorOutput()];
}

it('registers all BoostCoreCommandProvider commands in standalone bin', function (): void {
    $result = runBin('list');

    expect($result['exit'])->toBe(0);
    foreach (['sync', 'install', 'scan', 'doctor', 'new'] as $name) {
        expect($result['output'])->toContain($name);
    }
});

it('strips boost: prefix from command names in standalone bin', function (): void {
    $result = runBin('sync --help');

    expect($result['exit'])->toBe(0)
        ->and($result['output'])
        ->toContain('Usage:')
        ->toContain('sync [options]');
});

it('exposes --scope option on sync command', function (): void {
    $result = runBin('sync --help');

    expect($result['output'])->toContain('--scope');
});
