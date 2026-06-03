<?php declare(strict_types=1);

namespace SanderMuller\BoostCore\Skills\Remote;

use InvalidArgumentException;

/**
 * Declared reference to a single skill inside a {@see RemoteSkillSource}.
 *
 * Two mutually-exclusive locator modes:
 *  - `asset` — a `.skill` ZIP release asset (bundle source).
 *  - `path` — a directory path within the source repo, `.` for the repo root.
 *
 * Exactly one MUST be set. The {@see RemoteSkillSource::githubBundle()} and
 * {@see RemoteSkillSource::githubPath()} factories construct correctly-shaped
 * refs for each mode; raw construction is available for the rare case where
 * a bundle source needs a non-default asset name.
 *
 * `name` is a flat identifier (kebab/snake-case) that MUST equal the source
 * `SKILL.md`'s frontmatter `name`. Mismatch is a config error caught at
 * sync time.
 *
 * @api
 */
final readonly class RemoteSkillRef
{
    private const NAME_PATTERN = '/^[a-z0-9](?:[a-z0-9_-]*[a-z0-9])?$/';

    public function __construct(
        public string $name,
        public ?string $asset = null,
        public ?string $path = null,
    ) {
        if (preg_match(self::NAME_PATTERN, $name) !== 1) {
            throw new InvalidArgumentException(sprintf(
                'RemoteSkillRef name `%s` must match %s (kebab/snake-case identifier).',
                $name,
                self::NAME_PATTERN,
            ));
        }

        if ($asset === null && $path === null) {
            throw new InvalidArgumentException(sprintf(
                'RemoteSkillRef `%s`: one of `asset` or `path` must be set.',
                $name,
            ));
        }

        if ($asset !== null && $path !== null) {
            throw new InvalidArgumentException(sprintf(
                'RemoteSkillRef `%s`: `asset` and `path` are mutually exclusive.',
                $name,
            ));
        }

        if ($asset !== null && (str_contains($asset, '/') || str_contains($asset, '\\'))) {
            throw new InvalidArgumentException(sprintf(
                'RemoteSkillRef `%s`: asset name `%s` must not contain directory separators.',
                $name,
                $asset,
            ));
        }

        if ($path !== null && $this->pathHasTraversal($path)) {
            throw new InvalidArgumentException(sprintf(
                'RemoteSkillRef `%s`: path `%s` must not contain `..` segments.',
                $name,
                $path,
            ));
        }
    }

    private function pathHasTraversal(string $path): bool
    {
        return in_array('..', explode('/', str_replace('\\', '/', $path)), true);
    }
}
