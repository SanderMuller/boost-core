<?php

declare(strict_types=1);

use Composer\Plugin\Capability\CommandProvider;
use SanderMuller\BoostCore\BoostCorePlugin;

it('loads the composer plugin entry class', function (): void {
    expect(class_exists(BoostCorePlugin::class))->toBeTrue();
});

it('plugin advertises a command provider capability', function (): void {
    $plugin = new BoostCorePlugin;
    $capabilities = $plugin->getCapabilities();

    expect($capabilities)->toHaveKey(CommandProvider::class);
});
