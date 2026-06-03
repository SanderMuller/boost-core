<?php declare(strict_types=1);

use SanderMuller\BoostCore\Commands\ConvertConventionsCommand;
use SanderMuller\BoostCore\Commands\InstallCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Tester\CommandTester;

/**
 * 1.0 CLI-contract guards (task #117).
 */
it('hides the legacy convert-conventions command from the 1.0 command list', function (): void {
    expect((new ConvertConventionsCommand())->isHidden())->toBeTrue();
});

it('boost install fails fast (with guidance) under --no-interaction instead of hanging on the picker', function (): void {
    $dir = sys_get_temp_dir() . '/boost-cli-noninteractive-' . bin2hex(random_bytes(8));
    mkdir($dir, 0o755, recursive: true);

    try {
        $tester = new CommandTester(new InstallCommand());
        $tester->execute(['--working-dir' => $dir], ['interactive' => false]);

        expect($tester->getStatusCode())->toBe(Command::FAILURE)
            // Console wraps the error block across lines, so assert on an intact
            // fragment + the flag, whitespace-insensitively for the phrase.
            ->and(preg_replace('/\s+/', ' ', $tester->getDisplay()))->toContain('interactive terminal')
            ->and($tester->getDisplay())->toContain('--no-interaction')
            // The scaffold runs before the guard, so a starter still lands.
            ->and($dir . '/boost.php')->toBeFile();
    } finally {
        @unlink($dir . '/boost.php');
        @rmdir($dir);
    }
});
