<?php

declare(strict_types=1);

function runBin(string $args): array
{
    $bin = escapeshellarg(dirname(__DIR__, 2) . '/bin/boost');
    $output = [];
    $exit = 0;
    exec("php $bin $args 2>&1", $output, $exit);

    return ['exit' => $exit, 'output' => implode("\n", $output)];
}

it('registers all BoostCoreCommandProvider commands in standalone bin', function () {
    $result = runBin('list');

    expect($result['exit'])->toBe(0);
    foreach (['sync', 'init', 'install', 'scan', 'doctor', 'new'] as $name) {
        expect($result['output'])->toContain($name);
    }
});

it('strips boost: prefix from command names in standalone bin', function () {
    $result = runBin('sync --help');

    expect($result['exit'])->toBe(0);
    expect($result['output'])->toContain('Usage:')->toContain('sync [options]');
});

it('exposes --scope option on sync command', function () {
    $result = runBin('sync --help');

    expect($result['output'])->toContain('--scope');
});
