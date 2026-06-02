<?php declare(strict_types=1);

namespace SanderMuller\BoostCore\Config;

/**
 * The single authority for WHERE a project's `boost.php` lives, so every reader
 * AND writer agrees on one path.
 *
 * Search order:
 *   1. an explicit path (CLI `--config` / programmatic override) — always wins;
 *   2. `.config/boost.php`;
 *   3. `boost.php` at the project root.
 *
 * Exactly one of (2)/(3) present → use it (root-only is the historical behavior,
 * so every existing repo is unaffected). Both present → fail loud
 * ({@see AmbiguousBoostConfigException}). Neither → default to root with
 * `exists = false` (the not-found / scaffold-target case).
 *
 * @internal
 */
final readonly class BoostConfigPath
{
    public const ROOT = 'boost.php';

    public const CONFIG_DIR = '.config/boost.php';

    private function __construct(
        /** Absolute path of the config file to load / write. */
        public string $path,
        /** Whether that file exists on disk. */
        public bool $exists,
        /** Whether the resolved file lives under `.config/`. */
        public bool $inConfigDir,
    ) {}

    /**
     * @throws AmbiguousBoostConfigException when both root and `.config/` configs exist
     */
    public static function resolve(string $projectRoot, ?string $explicit = null): self
    {
        $projectRoot = rtrim($projectRoot, '/');

        if ($explicit !== null && $explicit !== '') {
            // A RELATIVE explicit path resolves against the project root, never the
            // process CWD — so `--config .config/boost.php` is location-stable
            // regardless of where boost was invoked from.
            $path = self::isAbsolute($explicit)
                ? $explicit
                : $projectRoot . '/' . ltrim($explicit, '/\\');

            return new self($path, is_file($path), basename(dirname($path)) === '.config');
        }

        $root = $projectRoot . '/' . self::ROOT;
        $configDir = $projectRoot . '/' . self::CONFIG_DIR;
        $rootExists = is_file($root);
        $configExists = is_file($configDir);

        if ($rootExists && $configExists) {
            throw new AmbiguousBoostConfigException($root, $configDir);
        }

        if ($configExists) {
            return new self($configDir, true, true);
        }

        // Root present, or neither (default target = root, exists reflects reality).
        return new self($root, $rootExists, false);
    }

    private static function isAbsolute(string $path): bool
    {
        return str_starts_with($path, '/')
            || preg_match('#^[A-Za-z]:[\\\\/]#', $path) === 1;
    }
}
