<?php declare(strict_types=1);

namespace SanderMuller\BoostCore\Sync;

use Throwable;

/**
 * The operator's user-scope selection file, `~/.boost/user-scope.php`.
 *
 * The file returns `['skills' => ['vendor/pkg' => ['skill-a', …]]]`. A package
 * with no entry publishes every skill it ships; a package with `[]` publishes
 * none. Any load or shape error is reported through {@see errors()} and the
 * caller fails closed: no package publishes, nothing is reaped.
 *
 * @internal
 */
final readonly class UserScopeConfig
{
    public const FILE = '.boost/user-scope.php';

    /**
     * @param  array<string, list<string>>  $skills  package name => selected skill names
     * @param  list<string>  $errors
     */
    private function __construct(
        public string $path,
        private array $skills,
        private array $errors,
    ) {}

    public static function pathFor(string $home): string
    {
        return rtrim($home, '/') . '/' . self::FILE;
    }

    public static function fromHome(string $home): self
    {
        $path = self::pathFor($home);
        if (! is_file($path)) {
            return new self($path, [], []);
        }

        try {
            $raw = self::includeIsolated($path);
        } catch (Throwable $throwable) {
            return self::invalid($path, sprintf('could not be loaded: %s', $throwable->getMessage()));
        }

        if (! is_array($raw)) {
            return self::invalid($path, 'must return an array');
        }

        foreach (array_keys($raw) as $key) {
            if ($key !== 'skills') {
                return self::invalid($path, sprintf('has an unknown key "%s" (expected only "skills")', $key));
            }
        }

        /** @var mixed $entries */
        $entries = array_key_exists('skills', $raw) ? $raw['skills'] : [];
        if (! is_array($entries)) {
            return self::invalid($path, '"skills" must be an array of package name => list of skill names');
        }

        /** @var array<string, list<string>> $skills */
        $skills = [];
        /** @var mixed $names */
        foreach ($entries as $package => $names) {
            if (! is_string($package) || $package === '') {
                return self::invalid($path, '"skills" keys must be Composer package names');
            }

            if (! is_array($names) || ! array_is_list($names)) {
                return self::invalid($path, sprintf('"skills" entry for %s must be a list of skill names', $package));
            }

            $list = [];
            foreach ($names as $name) {
                if (! is_string($name) || $name === '') {
                    return self::invalid($path, sprintf('"skills" entry for %s must contain only non-empty strings', $package));
                }

                $list[] = $name;
            }

            $skills[$package] = array_values(array_unique($list));
        }

        return new self($path, $skills, []);
    }

    /**
     * The selected skill names for a package, or null when the file has no
     * entry for it (publish everything).
     *
     * @return list<string>|null
     */
    public function selectionFor(string $packageName): ?array
    {
        return $this->skills[$packageName] ?? null;
    }

    /**
     * @return list<string>
     */
    public function errors(): array
    {
        return $this->errors;
    }

    public function hasErrors(): bool
    {
        return $this->errors !== [];
    }

    private static function invalid(string $path, string $reason): self
    {
        return new self($path, [], [sprintf('%s %s.', $path, $reason)]);
    }

    private static function includeIsolated(string $path): mixed
    {
        return (static fn (): mixed => include $path)();
    }
}
