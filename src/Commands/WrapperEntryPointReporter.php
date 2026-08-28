<?php declare(strict_types=1);

namespace SanderMuller\BoostCore\Commands;

use SanderMuller\BoostCore\Sync\InstalledPackages;
use SanderMuller\BoostCore\Sync\WrapperEntryPointMap;
use SanderMuller\BoostCore\Sync\WrapperEntryPoints;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * Doctor's view of the wrapper entry-point declarations in this project.
 *
 * This is the ONLY place a rejected claim is reported. A wrapper claiming a
 * reserved command is the wrapper author's mistake, and printing it on every
 * bare run would turn one package's bug into permanent noise for operators
 * who cannot fix it. The author sees it the first time they run `boost doctor`
 * against their own fixture, which is where it belongs.
 *
 * @internal
 */
final readonly class WrapperEntryPointReporter
{
    public function report(SymfonyStyle $io, ?InstalledPackages $packages = null, ?WrapperEntryPointMap $map = null): void
    {
        $map ??= (new WrapperEntryPoints($packages ?? InstalledPackages::fromComposer()))->discover();

        if (! $map->hasWrapper()) {
            return;
        }

        $io->section('Wrapper entry points');

        $rows = [];
        foreach (['sync', 'scan', 'install', 'where', 'tags', 'validate', 'remote', 'new', 'slots', 'paths'] as $command) {
            if ($map->covers($command)) {
                $rows[] = [$command, (string) $map->packageFor($command), (string) $map->invocationFor($command)];
            }
        }

        if ($rows !== []) {
            $io->writeln('These bare commands are covered by an installed wrapper. Run the invocation on the right instead:');
            $io->table(['Bare command', 'Declared by', 'Run instead'], $rows);
        }

        foreach ($map->reservedClaims() as $package => $commands) {
            $io->warning(sprintf(
                'Package `%s` claims the reserved command(s) `%s` in its `extra.boost.entry-point`. boost-core '
                . 'ignores those entries. A reserved command must stay runnable bare — `doctor` is what diagnoses '
                . 'a wrapper whose own CLI will not boot, so a wrapper cannot stand in front of it. To resolve: '
                . 'remove the entry, and surface the richer diagnostic from the wrapper\'s own command instead.',
                $package,
                implode('`, `', $commands),
            ));
        }
    }
}
