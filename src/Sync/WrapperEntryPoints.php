<?php declare(strict_types=1);

namespace SanderMuller\BoostCore\Sync;

use Composer\InstalledVersions;
use JsonException;

/**
 * Reads wrapper entry-point declarations out of installed packages'
 * `composer.json` — `extra.boost.entry-point`, a map of bare boost-core
 * command name => the exact invocation to run instead:
 *
 *     "extra": {
 *         "boost": {
 *             "entry-point": {
 *                 "sync": "php artisan project-boost:sync",
 *                 "where": "php artisan project-boost:where",
 *                 "install": "php artisan project-boost:install"
 *             }
 *         }
 *     }
 *
 * **Why composer.json and not a PHP interface.** The entry-point banner has to
 * work exactly when the wrapper does NOT — a wrapper whose framework layer
 * cannot boot is the case an operator most needs to be told about. Probing a
 * wrapper CLASS autoloads third-party code on that very path, so a broken
 * wrapper could break the message explaining that it is broken. Reading an
 * inert JSON file cannot. It also keeps fast commands (`paths`, `slots`) free
 * of any third-party autoload, and adds no `@api` interface to freeze.
 *
 * Full invocation strings rather than a command prefix, so the wrapper owns
 * the exact copy and boost-core never composes a command name the wrapper did
 * not write. The two CLIs' flags are NOT interchangeable — boost-core's
 * read-only flag is `--check` while `project-boost:sync`'s is `--dry-run` —
 * so a composed redirect would eventually name a flag that does not exist.
 *
 * A package's map describes projects that INSTALL it — it does not describe
 * the package's own repository, so a claim by the ROOT package is ignored.
 * `InstalledVersions::getInstalledPackages()` does include the root, and
 * honouring its claim looked like useful self-protection until a wrapper
 * package pointed out what it does there: at the root of a package that SHIPS
 * a wrapper there is no application, so `php artisan <wrapper>:sync` cannot
 * run at all, while bare `boost sync` is the correct command. The banner told
 * a maintainer to stop using the command that works and run one that does not
 * exist — worse than the silence it replaced, and aimed at the person least in
 * need of the warning.
 *
 * The ignore is REPORTED by `boost doctor`, never silent: a declaration that
 * does nothing and says nothing is the same unread-channel shape this engine
 * has been fixing all release.
 *
 * Third-party data, so every malformed shape is skipped rather than fatal.
 *
 * @internal
 */
final readonly class WrapperEntryPoints
{
    /**
     * Commands a wrapper may never claim. `doctor` diagnoses a broken wrapper
     * and tells a misrouted operator they are misrouted; a wrapper whose own
     * CLI will not boot must not be able to take that path down with it.
     *
     * The general test for reserving a command: running it bare can neither
     * mutate state nor be mistaken for a complete answer. `doctor` writes
     * nothing, and since it began consulting `SyncResult::hasErrors()` it
     * refuses to give a drift verdict rather than giving a wrong one.
     */
    public const array RESERVED_COMMANDS = ['doctor'];

    public function __construct(
        private InstalledPackages $packages,
        /**
         * The root package's Composer name, or null to read it from the
         * runtime. A claim by this package is ignored — see the class
         * docblock.
         */
        private ?string $rootPackage = null,
    ) {}

    /**
     * @internal Engine-internal — returns the `@internal` map type.
     */
    public function discover(): WrapperEntryPointMap
    {
        /** @var array<string, array{package: string, invocation: string}> $claims */
        $claims = [];
        /** @var array<string, list<string>> $reserved */
        $reserved = [];
        /** @var array<string, list<string>> $conflicts */
        $conflicts = [];
        /** @var array<string, list<string>> $selfClaims */
        $selfClaims = [];

        $root = $this->rootPackage ?? $this->rootPackageName();

        foreach ($this->packages->all() as $package) {
            foreach ($this->readDeclaration($package->installPath) as $command => $invocation) {
                if ($root !== null && $package->name === $root) {
                    $selfClaims[$package->name][] = $command;

                    continue;
                }

                if (in_array($command, self::RESERVED_COMMANDS, strict: true)) {
                    $reserved[$package->name][] = $command;

                    continue;
                }

                // First declaration wins — preferring the later package would
                // be equally arbitrary. But RECORD the loser: resolving the
                // clash silently is what made a misconfigured project look
                // correctly configured while the advisory named one of two
                // wrappers at random.
                if (isset($claims[$command])) {
                    $conflicts[$command][] = $package->name;

                    continue;
                }

                $claims[$command] = ['package' => $package->name, 'invocation' => $invocation];
            }
        }

        return new WrapperEntryPointMap($claims, $reserved, $conflicts, $selfClaims);
    }

    /**
     * The root package's Composer name, or null when the runtime cannot name
     * it (an exotic checkout with no registered root).
     */
    private function rootPackageName(): ?string
    {
        $name = InstalledVersions::getRootPackage()['name'];

        return $name === '' ? null : $name;
    }

    /**
     * The package's validated `extra.boost.entry-point` map. Anything that is
     * not a non-empty string keyed by a non-empty string is dropped.
     *
     * @return array<string, string>
     */
    private function readDeclaration(string $installPath): array
    {
        $composerJson = $installPath . '/composer.json';
        if (! is_file($composerJson)) {
            return [];
        }

        $raw = @file_get_contents($composerJson);
        if ($raw === false) {
            return [];
        }

        try {
            /** @var array<mixed, mixed> $decoded */
            $decoded = json_decode($raw, true, 512, JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            return [];
        }

        $extra = $decoded['extra'] ?? null;
        $boost = is_array($extra) ? ($extra['boost'] ?? null) : null;
        $entryPoint = is_array($boost) ? ($boost['entry-point'] ?? null) : null;
        if (! is_array($entryPoint)) {
            return [];
        }

        $map = [];
        foreach ($entryPoint as $command => $invocation) {
            if (! is_string($command) || $command === '') {
                continue;
            }

            if (! is_string($invocation) || trim($invocation) === '') {
                continue;
            }

            $map[$command] = trim($invocation);
        }

        return $map;
    }
}
