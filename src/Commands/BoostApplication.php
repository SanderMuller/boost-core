<?php declare(strict_types=1);

namespace SanderMuller\BoostCore\Commands;

use SanderMuller\BoostCore\Env;
use SanderMuller\BoostCore\Sync\InstalledPackages;
use SanderMuller\BoostCore\Sync\WrapperEntryPointMap;
use SanderMuller\BoostCore\Sync\WrapperEntryPoints;
use Symfony\Component\Console\Application;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\ConsoleOutputInterface;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * The console application behind `bin/boost`. Adds one thing to Symfony's:
 * the wrapper entry-point gate.
 *
 * **Why here and not in the commands.** `bin/boost` is the only definitionally
 * BARE entry point. `CommandRegistry` has exactly two consumers — this binary
 * and boost-core's own tests — so a wrapper's artisan commands can never route
 * through the gate and be told off by it. The gate also covers the Composer
 * hook for free: `BoostAutoSync::resolveAndRun()` spawns
 * `<config.bin-dir>/boost sync` as a subprocess, so a `post-install-cmd` in a
 * wrapper project passes through here like any other bare run.
 *
 * **Why a subclass and not an event listener.** `symfony/event-dispatcher` is
 * not a direct dependency of boost-core, and a `ConsoleEvents` listener would
 * make it one for a gate this small. Overriding `doRunCommand()` gets the
 * already-resolved `Command` (so aliases and abbreviations behave) with no new
 * requirement.
 *
 * **Banner in 1.x, refusal behind a flag.** `PUBLIC_API.md` puts command
 * names, documented options and EXIT CODES inside the 1.0 promise, and says in
 * the same breath that human-readable output is not a contract. So the default
 * is advisory: covered commands still run and still exit as they did.
 * `BOOST_STRICT_ENTRY_POINT=1` opts a project into the refusal early; it
 * becomes the default in the next major.
 *
 * Banners go to STDERR when the output supports it, so a `--json` envelope on
 * STDOUT stays machine-readable.
 *
 * @internal
 */
final class BoostApplication extends Application
{
    /**
     * Commands that describe the CLI rather than act on a project. Gating
     * them would put a banner in front of `--help`, which is the one place an
     * operator is already asking what to run.
     */
    private const array UNGATED_COMMANDS = ['help', 'list', 'completion', '_complete'];

    public function __construct(string $name, string $version, private ?WrapperEntryPointMap $entryPoints = null)
    {
        parent::__construct($name, $version);
    }

    protected function doRunCommand(Command $command, InputInterface $input, OutputInterface $output): int
    {
        $name = $command->getName();

        if ($name !== null && ! $this->skipsGate($input, $name)) {
            $refused = $this->applyEntryPointGate($name, $command, $output);
            if ($refused) {
                return Command::FAILURE;
            }
        }

        return parent::doRunCommand($command, $input, $output);
    }

    /**
     * Emit the entry-point advisory. Returns true when the run must stop
     * (strict mode, covered command) and false when it should proceed.
     */
    private function applyEntryPointGate(string $name, Command $command, OutputInterface $output): bool
    {
        $map = $this->entryPoints();
        if (! $map->hasWrapper()) {
            return false;
        }

        $stderr = $output instanceof ConsoleOutputInterface ? $output->getErrorOutput() : $output;

        if ($map->covers($name)) {
            $invocation = (string) $map->invocationFor($name);
            $package = (string) $map->packageFor($name);

            // Phrased as an IDENTIFICATION, not a paste-ready command. The
            // declaration is a static string, so it cannot know that PHP does
            // not run on the host — Sail, Docker Compose, and anything else
            // fronting artisan need their own prefix. "Run X instead" sends
            // those operators to a command-not-found; naming the package and
            // its equivalent survives the difference without any per-project
            // resolution.
            $stderr->writeln(sprintf(
                '<comment>This project uses `%s`, which covers `%s`. Its equivalent is `%s` — adapt the prefix '
                . 'if PHP does not run on the host (Sail, Docker Compose). The bare binary does not run what '
                . 'that package adds.</comment>',
                $package,
                $name,
                $invocation,
            ));

            if (Env::flagEnabled(Env::STRICT_ENTRY_POINT)) {
                $stderr->writeln(sprintf(
                    '<error>Refused: %s is set. Unset it to run the bare command anyway.</error>',
                    Env::STRICT_ENTRY_POINT,
                ));

                return true;
            }

            return false;
        }

        if ($command instanceof TouchesResolutionPipeline) {
            $stderr->writeln(sprintf(
                "<comment>`%s` reads boost-core's own sources only. %s extends the set in this project, "
                . 'so this result is incomplete. There is no %s equivalent to run instead — read the output '
                . 'with that in mind.</comment>',
                $name,
                implode(', ', $map->packages()),
                $name,
            ));
        }

        return false;
    }

    /**
     * `doctor` is reserved at discovery, but the gate refuses to honour a
     * claim on it by any route: it is the command that diagnoses a broken
     * wrapper, so a wrapper must never be able to stand in front of it.
     */
    private function skipsGate(InputInterface $input, string $name): bool
    {
        if (in_array($name, self::UNGATED_COMMANDS, strict: true)) {
            return true;
        }

        if (in_array($name, WrapperEntryPoints::RESERVED_COMMANDS, strict: true)) {
            return true;
        }

        return $input->hasParameterOption(['--help', '-h'], onlyParams: true);
    }

    /**
     * Resolved once per invocation, and only for a gated command — `--help`,
     * `list` and `doctor` never read a `composer.json` at all. Deciding
     * coverage needs the map, so a gated command always pays for it; the cost
     * is a JSON read per installed package and no autoload of third-party
     * code.
     */
    private function entryPoints(): WrapperEntryPointMap
    {
        return $this->entryPoints ??= (new WrapperEntryPoints(InstalledPackages::fromComposer()))->discover();
    }
}
