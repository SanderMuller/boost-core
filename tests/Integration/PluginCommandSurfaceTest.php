<?php

declare(strict_types=1);

use Symfony\Component\Process\Process;

/**
 * Regression guard: the `composer boost:*` command surface must register
 * cleanly through the plugin's CommandProvider capability. Composer's
 * PluginManager runtime-validates that every returned command is a
 * `Composer\Command\BaseCommand` — 0.1.2 shipped them as plain Symfony
 * commands and `composer boost:init` (and every other boost:* command)
 * failed with "Plugin capability ... returned an invalid value".
 *
 * Covering this requires a real composer subprocess against a fixture
 * project where boost-core is installed as a plugin (not via the
 * standalone bin).
 */
it('composer boost:* commands are registered through the plugin capability', function (): void {
    $packageRoot = dirname(__DIR__, 2);
    $fixture = sys_get_temp_dir() . '/boost-plugin-surface-' . bin2hex(random_bytes(6));
    mkdir($fixture, 0o755, recursive: true);

    try {
        file_put_contents($fixture . '/composer.json', json_encode([
            'name' => 'boost-core-test/plugin-surface-fixture',
            'require' => [
                'sandermuller/boost-core' => '*',
            ],
            'repositories' => [
                [
                    'type' => 'path',
                    'url' => $packageRoot,
                    'options' => ['symlink' => false],
                ],
            ],
            'minimum-stability' => 'dev',
            'config' => [
                'allow-plugins' => [
                    'sandermuller/boost-core' => true,
                ],
            ],
        ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));

        $install = Process::fromShellCommandline(
            'cd ' . escapeshellarg($fixture) . ' && composer install --no-dev --no-interaction --quiet 2>&1',
        );
        $install->run();
        if (! $install->isSuccessful()) {
            throw new RuntimeException('composer install failed: ' . $install->getOutput() . $install->getErrorOutput());
        }

        // `composer list` from inside the fixture must enumerate every boost:*
        // command. If the plugin capability returned invalid commands, composer
        // would either error out or silently drop them — either way, this
        // assertion fails loudly.
        $list = Process::fromShellCommandline(
            'cd ' . escapeshellarg($fixture) . ' && composer list --no-interaction 2>&1',
        );
        $list->run();
        $listOutput = $list->getOutput() . $list->getErrorOutput();

        expect($list->isSuccessful())->toBeTrue("composer list failed:\n" . $listOutput);
        expect($listOutput)
            ->not->toContain('returned an invalid value')
            ->toContain('boost:sync')
            ->toContain('boost:init')
            ->toContain('boost:install')
            ->toContain('boost:scan')
            ->toContain('boost:doctor')
            ->toContain('boost:new');

        // Drive one specific command end-to-end so we know dispatch through
        // the BaseCommandAdapter actually works (not just registration).
        $help = Process::fromShellCommandline(
            'cd ' . escapeshellarg($fixture) . ' && composer boost:init --help --no-interaction 2>&1',
        );
        $help->run();
        $helpOutput = $help->getOutput() . $help->getErrorOutput();

        expect($help->isSuccessful())
            ->toBeTrue("composer boost:init --help failed (exit {$help->getExitCode()}):\n" . $helpOutput);
        expect($helpOutput)->toContain('Generate a starter boost.php');
    } finally {
        cleanupTestDir($fixture);
    }
});
