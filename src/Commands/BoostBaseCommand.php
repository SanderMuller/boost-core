<?php declare(strict_types=1);

namespace SanderMuller\BoostCore\Commands;

use SanderMuller\BoostCore\Config\BoostConfig;
use SanderMuller\BoostCore\Config\BoostConfigLoader;
use SanderMuller\BoostCore\Config\BoostConfigNotFoundException;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Style\SymfonyStyle;
use Throwable;

/**
 * Shared base for boost-family CLI commands — the documented extension point
 * for wrapper/tooling packages that ship their own `bin/<tool>` commands.
 *
 * Extends Symfony's Command directly so commands load in the standalone
 * `bin/boost` — including end-user installs where composer/composer is not
 * in vendor/.
 *
 * @api The FROZEN surface is exactly two protected helpers — {@see addWorkingDirOption()}
 * and {@see resolveProjectRoot()} — the `--working-dir` / project-root plumbing a
 * family command needs. Subclass via Symfony's normal `configure()` / `execute()`.
 * The remaining protected members (the `--config` option + config-LOADING helpers)
 * are `@internal`: a config-loading extension point is a separate, heavier contract
 * deliberately NOT locked at 1.0.
 */
abstract class BoostBaseCommand extends Command
{
    /**
     * Register the `--working-dir` (`-d`) option. Part of the frozen `@api`
     * family-CLI surface; call from a subclass `configure()`.
     */
    protected function addWorkingDirOption(): static
    {
        $this->addOption(
            'working-dir',
            'd',
            InputOption::VALUE_REQUIRED,
            'Project root. Defaults to current working directory.',
        );

        return $this;
    }

    /**
     * @internal Not part of the frozen family-CLI surface — `--config` resolution
     * is engine-internal; a config-loading extension point is a separate contract.
     */
    protected function addConfigOption(): static
    {
        $this->addOption(
            'config',
            null,
            InputOption::VALUE_REQUIRED,
            'Path to a boost.php config (a relative path resolves against the project root). Overrides auto-discovery of root vs .config/boost.php.',
        );

        return $this;
    }

    /**
     * The `--config` override value, or null when unset/empty. A relative path is
     * resolved against the project root by {@see BoostConfigPath}.
     *
     * @internal Not part of the frozen family-CLI surface.
     */
    protected function configFileOption(InputInterface $input): ?string
    {
        if (! $input->hasOption('config')) {
            return null;
        }

        $value = $input->getOption('config');

        return is_string($value) && $value !== '' ? $value : null;
    }

    /**
     * Resolve the project root from `--working-dir`, falling back to the current
     * working directory. Part of the frozen `@api` family-CLI surface.
     */
    protected function resolveProjectRoot(InputInterface $input): string
    {
        $workingDir = $input->getOption('working-dir');
        if (is_string($workingDir)) {
            return rtrim($workingDir, '/');
        }

        $cwd = getcwd();

        return rtrim($cwd === false ? '.' : $cwd, '/');
    }

    /**
     * Test seam for the TTY probe. `null` (the default) probes the real
     * `STDIN`. Not part of the frozen family-CLI surface.
     *
     * @var (callable(): bool)|null
     */
    private static $ttyProbe = null;

    /**
     * Override the TTY probe used by {@see isInteractiveOrExplain()}. Pass
     * `null` to restore the real `STDIN` probe.
     *
     * Testing seam ONLY — deliberately outside the frozen `@api` surface
     * named in the class docblock, and free to change without a major bump.
     *
     * @param  (callable(): bool)|null  $probe
     */
    public static function probeTtyUsing(?callable $probe): void
    {
        self::$ttyProbe = $probe;
    }

    /**
     * Guard for commands whose flow needs an interactive terminal (the
     * `multiselect` pickers). Returns true when interactive; otherwise prints
     * `$guidance` and returns false so the caller can fail fast with a clear
     * message instead of prompting.
     *
     * TWO conditions, because Symfony and laravel/prompts mean different
     * things by "interactive" and the gap between them was a silent write.
     * Symfony only clears `$input->isInteractive()` for the `--no-interaction`
     * / `-n` FLAG (`Application::configureIO()`) — it never probes the
     * terminal. laravel/prompts probes the terminal
     * (`Prompt::prompt()`: `static::$interactive ??= stream_isatty(STDIN)`)
     * and, when there is no TTY, silently RETURNS THE PRECOMPUTED DEFAULT
     * instead of prompting — no output, no exception, no non-zero exit.
     *
     * So a flag-only guard let `boost scan` fall through to its picker in any
     * non-TTY context that did not pass `-n` (CI, git hooks, Composer
     * scripts, agent shells), where `multiselect()` handed back the defaults
     * and the run wrote `boost.php` with nobody having chosen anything.
     * Requiring an attached TTY as well closes that gap for all three picker
     * call sites at once.
     *
     * @internal Not part of the frozen family-CLI surface.
     */
    protected function isInteractiveOrExplain(InputInterface $input, SymfonyStyle $io, string $guidance): bool
    {
        if ($input->isInteractive() && $this->hasAttachedTty()) {
            return true;
        }

        $io->error($guidance);

        return false;
    }

    /**
     * Ask the same question laravel/prompts will ask before it decides to
     * prompt or to fall back to defaults. Mirrors its probe exactly so the
     * guard and the prompt cannot disagree.
     *
     * `STDIN` is undefined under some SAPIs — treat that as "no terminal",
     * the conservative answer.
     */
    private function hasAttachedTty(): bool
    {
        $probe = self::$ttyProbe;
        if ($probe !== null) {
            return $probe();
        }

        if (! defined('STDIN')) {
            return false;
        }

        return stream_isatty(STDIN);
    }

    /**
     * Load and build the project's `boost.php`. Renders the failure to `$io`
     * and returns null on a missing or broken config — callers return
     * `self::FAILURE` on null.
     *
     * @internal Not part of the frozen family-CLI surface — returns the
     * `@api` BoostConfig but the loading flow itself is engine-internal.
     */
    protected function loadConfig(SymfonyStyle $io, string $projectRoot, ?string $configFile = null): ?BoostConfig
    {
        try {
            return (new BoostConfigLoader())->load($projectRoot, $configFile);
        } catch (BoostConfigNotFoundException $e) {
            $io->error($e->getMessage());

            return null;
        } catch (Throwable $e) {
            $io->error('boost.php failed to load: ' . $e->getMessage());

            return null;
        }
    }
}
