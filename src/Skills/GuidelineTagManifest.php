<?php declare(strict_types=1);

namespace SanderMuller\BoostCore\Skills;

use Symfony\Component\Yaml\Exception\ParseException;
use Symfony\Component\Yaml\Yaml;

/**
 * The `.boost-tags.yaml` sidecar manifest — the tag source for
 * frontmatter-free vendor guidelines.
 *
 * boost-core's guideline tag-filter reads `metadata.boost-tags` frontmatter,
 * but a guideline that must stay frontmatter-free — a `laravel/boost`-safe
 * package's guidelines, since laravel/boost has no frontmatter parser —
 * cannot carry it. Such a package instead drops a `.boost-tags.yaml` beside
 * its guidelines: a YAML map of guideline filename to a space-delimited tag
 * string, the same grammar as `metadata.boost-tags`:
 *
 *     database-safety.md: "database"
 *     migrations.md: "database"
 *     # files omitted = untagged = always ship
 *
 * The `.yaml` extension keeps the manifest invisible to both laravel/boost's
 * and boost-core's `*.md`-only guideline Finders — it is never mistaken for
 * guideline content.
 *
 * Fail closed: an unparseable manifest (bad YAML, or a non-map document)
 * marks EVERY frontmatter-tag-silent guideline in the directory tag-invalid
 * — they ship nowhere. A loud failure beats silently shipping guidelines the
 * author meant to scope. A single non-string entry value fails only that
 * one guideline closed.
 *
 * @internal
 */
final readonly class GuidelineTagManifest
{
    public const string FILENAME = '.boost-tags.yaml';

    /**
     * @param  array<string, array{0: list<string>, 1: bool}>  $entries  guideline filename → [tags, valid]
     * @param  bool  $unparseable  the manifest exists but could not be read as a map → fail closed
     */
    private function __construct(
        private array $entries,
        private bool $unparseable,
    ) {}

    /**
     * Read `<directory>/.boost-tags.yaml`. An absent manifest is the common
     * case — it simply tags nothing, and every guideline stays untagged.
     */
    public static function load(string $directory): self
    {
        $path = $directory . '/' . self::FILENAME;
        if (! is_file($path)) {
            return new self([], unparseable: false);
        }

        $raw = @file_get_contents($path);
        if ($raw === false) {
            return new self([], unparseable: true);
        }

        try {
            $parsed = Yaml::parse($raw);
        } catch (ParseException) {
            return new self([], unparseable: true);
        }

        // An empty or comment-only manifest parses to null — a usable
        // manifest that happens to tag nothing, not a malformed one.
        if ($parsed === null) {
            return new self([], unparseable: false);
        }

        if (! is_array($parsed)) {
            return new self([], unparseable: true);
        }

        // A YAML sequence (`- a`) parses to a PHP list — a valid array, but
        // not a filename→tags map. Fail closed, same as a scalar document.
        // An empty `[]`/`{}` is allowed through as an empty (usable) manifest.
        if ($parsed !== [] && array_is_list($parsed)) {
            return new self([], unparseable: true);
        }

        /** @var array<string, array{0: list<string>, 1: bool}> $entries */
        $entries = [];
        foreach ($parsed as $filename => $value) {
            if (! is_string($filename)) {
                continue;
            }

            // A non-string value (e.g. a YAML list) fails only this entry
            // closed — mirrors a malformed `metadata.boost-tags`.
            $entries[$filename] = is_string($value)
                ? [BoostTags::parseString($value), true]
                : [[], false];
        }

        return new self($entries, unparseable: false);
    }

    /**
     * The `[tags, valid]` a guideline filename draws from the manifest:
     *  - manifest unparseable        → `[[], false]` — aggressive fail-closed;
     *  - filename present, valid     → `[tags, true]`;
     *  - filename present, bad value → `[[], false]`;
     *  - filename absent             → `[[], true]` — untagged, ships everywhere.
     *
     * @return array{0: list<string>, 1: bool}
     */
    public function tagsFor(string $filename): array
    {
        if ($this->unparseable) {
            return [[], false];
        }

        return $this->entries[$filename] ?? [[], true];
    }
}
