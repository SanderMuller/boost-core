<?php declare(strict_types=1);

use Laravel\Prompts\Key;
use Laravel\Prompts\Prompt;
use SanderMuller\BoostCore\Commands\BoostBaseCommand;
use SanderMuller\BoostCore\Commands\ScanCommand;
use SanderMuller\BoostCore\Sync\InstalledPackages;
use SanderMuller\BoostCore\Sync\PackageInfo;
use Symfony\Component\Console\Tester\CommandTester;

/*
 * The picker is driven with a faked terminal, so declare one to the shared
 * guard as well — see InteractivityGuardTest.
 */
beforeEach(function (): void {
    BoostBaseCommand::probeTtyUsing(fn (): bool => true);
});

afterEach(function (): void {
    BoostBaseCommand::probeTtyUsing(null);
});

/**
 * A project whose `boost.php` allowlists `$allowed`, plus one installed
 * package that genuinely publishes skills so the picker has a row to show.
 *
 * @param  list<string>  $allowed
 * @return array{0: string, 1: InstalledPackages}
 */
function scanTempProject(array $allowed): array
{
    $dir = sys_get_temp_dir() . '/boost-scan-' . bin2hex(random_bytes(8));
    mkdir($dir, 0o755, recursive: true);

    $list = implode(', ', array_map(static fn (string $v): string => "'" . $v . "'", $allowed));
    file_put_contents(
        $dir . '/boost.php',
        "<?php\nuse SanderMuller\\BoostCore\\Config\\BoostConfig;\nuse SanderMuller\\BoostCore\\Enums\\Agent;\n"
        . "return BoostConfig::configure()->withAgents([Agent::CLAUDE_CODE])->withAllowedVendors([{$list}]);\n",
    );

    $packages = new InstalledPackages([
        'test-fixture/with-skills-default' => new PackageInfo(
            'test-fixture/with-skills-default',
            '1.0.0',
            dirname(__DIR__, 2) . '/Fixtures/vendor-packages/with-skills-default-path',
        ),
    ]);

    return [$dir, $packages];
}

it('keeps an allowlisted vendor the scanner can no longer discover', function (): void {
    // `acme/gone` is in withAllowedVendors() but publishes nothing the bare
    // scanner can see — the shape of laravel/boost in a wrapper project, whose
    // skills only exist once the wrapper injects them. The picker built its
    // options from VendorScanner->discover() alone, so the entry was not merely
    // deselected: it was absent from the list and therefore impossible to keep.
    // BoostConfigWriter then replaced withAllowedVendors() with the picked
    // subset, silently narrowing a hand-authored config.
    [$dir, $packages] = scanTempProject(['acme/gone', 'test-fixture/with-skills-default']);

    Prompt::fake([Key::ENTER]);

    try {
        $tester = new CommandTester(new ScanCommand(injectedPackages: $packages));
        $tester->execute(['--working-dir' => $dir], ['interactive' => true]);

        expect(file_get_contents($dir . '/boost.php'))->toContain('acme/gone');
    } finally {
        cleanupTestDir($dir);
    }
});

it('still runs the picker when the only allowlisted vendor is undiscoverable', function (): void {
    // With nothing discoverable at all, scan used to take the "no publishers"
    // early return and never reach the picker — so an allowlisted entry it
    // could not see was invisible AND unreviewable. The union keeps the picker
    // reachable so the operator can decide whether the entry still belongs.
    $dir = sys_get_temp_dir() . '/boost-scan-' . bin2hex(random_bytes(8));
    mkdir($dir, 0o755, recursive: true);
    file_put_contents(
        $dir . '/boost.php',
        "<?php\nuse SanderMuller\\BoostCore\\Config\\BoostConfig;\nuse SanderMuller\\BoostCore\\Enums\\Agent;\n"
        . "return BoostConfig::configure()->withAgents([Agent::CLAUDE_CODE])->withAllowedVendors(['acme/gone']);\n",
    );

    Prompt::fake([Key::ENTER]);

    try {
        $tester = new CommandTester(new ScanCommand(injectedPackages: new InstalledPackages([])));
        $tester->execute(['--working-dir' => $dir], ['interactive' => true]);

        expect($tester->getDisplay())->not->toContain('No installed packages publish')
            ->and(file_get_contents($dir . '/boost.php'))->toContain('acme/gone');
    } finally {
        cleanupTestDir($dir);
    }
});
