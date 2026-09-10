<?php declare(strict_types=1);

namespace SanderMuller\BoostCore\Skills;

use Symfony\Component\Yaml\Exception\ParseException;
use Symfony\Component\Yaml\Yaml;

/**
 * The `.boost-user-scope.yaml` sidecar — the user-scope eligibility source for
 * frontmatter-free vendor guidelines.
 *
 * A guideline is always-on: it renders into CLAUDE.md and sits in context for
 * every session. Publishing a package's guidelines wholesale at user scope
 * would put project-specific instructions (`migrations.md`, `javascript.md`)
 * into every repository on the machine. So user scope publishes only the
 * guidelines whose AUTHOR marked them machine-wide safe:
 *
 *     # resources/boost/guidelines/.boost-user-scope.yaml
 *     - voice.md
 *     - verification-before-completion.md
 *
 * This answers a different question from `.boost-tags.yaml`, which scopes a
 * guideline to PROJECTS by tag. The two never interact: a guideline can be both
 * tagged (`voice.md: "voice"`) and user-scope eligible. Tags are a project-scope
 * control and have no meaning at user scope, where there is no `boost.php`.
 *
 * A package that can carry frontmatter declares the same thing inline with
 * `metadata.boost-user-scope: true` — one rule, two carriers. A separate file
 * exists because a laravel/boost-safe package's guidelines must stay
 * frontmatter-free, and because the tag sidecar's every key is a guideline
 * filename: an eligibility key does not belong in that namespace.
 *
 * The `.yaml` extension keeps the sidecar invisible to both laravel/boost's and
 * boost-core's `*.md`-only guideline Finders.
 *
 * Fail closed: an unparseable sidecar (bad YAML, or a document that is not a
 * list of filenames) marks NOTHING eligible. A guideline that does not reach
 * user scope is a missing convenience; one that reaches it wrongly is
 * project-specific noise in every session on the machine.
 *
 * @internal
 */
final readonly class UserScopeGuidelineManifest
{
    public const string FILENAME = '.boost-user-scope.yaml';

    /**
     * @param  array<string, true>  $eligible  guideline filename → eligible
     */
    private function __construct(
        private array $eligible,
    ) {}

    /**
     * Read `<directory>/.boost-user-scope.yaml`. An absent sidecar is the common
     * case — it marks nothing eligible, which is the safe default.
     */
    public static function load(string $directory): self
    {
        $path = $directory . '/' . self::FILENAME;
        if (! is_file($path)) {
            return new self([]);
        }

        $raw = @file_get_contents($path);
        if ($raw === false) {
            return new self([]);
        }

        try {
            $parsed = Yaml::parse($raw);
        } catch (ParseException) {
            return new self([]);
        }

        // An empty or comment-only sidecar parses to null — a usable sidecar
        // that happens to list nothing.
        if (! is_array($parsed)) {
            return new self([]);
        }

        // A YAML map (`voice.md: true`) is not the documented shape. Fail closed
        // rather than guess which half of the pair is the filename.
        if ($parsed !== [] && ! array_is_list($parsed)) {
            return new self([]);
        }

        $eligible = [];
        foreach ($parsed as $filename) {
            $normalized = is_string($filename) ? self::normalize($filename) : null;
            if ($normalized !== null) {
                $eligible[$normalized] = true;
            }
        }

        return new self($eligible);
    }

    /**
     * Normalize one entry to the form {@see isEligible()} compares against, or
     * null when the entry can never name a guideline inside the directory.
     *
     * Eligibility is compared against the Finder's relative pathname, so
     * `./voice.md` would resolve on disk yet never match — the guideline would
     * simply never publish, with no error anywhere. A leading `./` is therefore
     * stripped rather than refused: it unambiguously means the same file.
     *
     * An absolute path or one containing a `..` segment is dropped. Eligibility
     * decides what goes into every session on the machine, so it must not be
     * addressable outside the package's own guidelines directory.
     */
    private static function normalize(string $entry): ?string
    {
        $trimmed = ltrim($entry, '/') === $entry ? $entry : '';
        while (str_starts_with($trimmed, './')) {
            $trimmed = substr($trimmed, 2);
        }

        if ($trimmed === '' || in_array('..', explode('/', $trimmed), true)) {
            return null;
        }

        return $trimmed;
    }

    /**
     * @param  string  $relativePath  path relative to the guidelines directory —
     *   the Finder recurses, so a bare basename would make one entry publish
     *   every same-named guideline in every subdirectory.
     */
    public function isEligible(string $relativePath): bool
    {
        return isset($this->eligible[$relativePath]);
    }

    /**
     * Every path the sidecar lists, whether or not a guideline exists there.
     *
     * @return list<string>
     */
    public function listedPaths(): array
    {
        return array_keys($this->eligible);
    }
}
