<?php declare(strict_types=1);

namespace SanderMuller\BoostCore\Skills\Remote;

use InvalidArgumentException;

/**
 * A remote skill source — a GitHub repo + version + cherry-picked skill list.
 *
 * Two source modes, picked via the factories:
 *  - {@see githubBundle()} — each skill is a `.skill` release asset
 *    (Anthropic Claude Code Skills standard format).
 *  - {@see githubPath()} — each skill is a subdirectory of the repo at the
 *    given ref. `.` covers the whole-repo single-skill case.
 *
 * Raw construction is available for the rare non-default asset-name case;
 * the factories cover the 99% path with less boilerplate.
 */
final readonly class RemoteSkillSource
{
    public const MODE_BUNDLE = 'bundle';

    public const MODE_PATH = 'path';

    private const SOURCE_PATTERN = '/^[A-Za-z0-9_.-]+\/[A-Za-z0-9_.-]+$/';

    /**
     * @param  list<RemoteSkillRef>  $skills
     */
    public function __construct(
        public string $source,
        public string $version,
        public array $skills,
    ) {
        if (preg_match(self::SOURCE_PATTERN, $source) !== 1) {
            throw new InvalidArgumentException(sprintf(
                'RemoteSkillSource source `%s` must match `<owner>/<repo>` (alphanumeric, `.`, `-`, `_`).',
                $source,
            ));
        }

        if ($version === '') {
            throw new InvalidArgumentException(sprintf(
                'RemoteSkillSource `%s`: version is required (tag, branch, SHA, or `latest`).',
                $source,
            ));
        }

        if ($skills === []) {
            throw new InvalidArgumentException(sprintf(
                'RemoteSkillSource `%s@%s`: skills list is empty.',
                $source,
                $version,
            ));
        }

        $this->assertUniformMode();
        $this->assertNoDuplicateSkillNames();

        if ($this->mode() === self::MODE_PATH) {
            $this->assertNoDuplicatePaths();
        }
    }

    /**
     * Bundle source — each skill is a `<name>.skill` GitHub release asset.
     *
     * Version restricted to release-addressable refs (release tag or
     * `'latest'`). Branch names and bare SHAs are rejected at construction
     * since release assets are tag-anchored on GitHub. For non-default asset
     * names, use the value-object form directly.
     *
     * @param  list<string>  $skills  skill names; each maps to `<name>.skill`
     */
    public static function githubBundle(string $source, string $version, array $skills): self
    {
        self::assertBundleVersion($source, $version);

        $refs = array_map(
            static fn (string $name): RemoteSkillRef => new RemoteSkillRef(name: $name, asset: $name . '.skill'),
            array_values($skills),
        );

        return new self($source, $version, $refs);
    }

    /**
     * Path source — each skill is a directory within the source repo.
     *
     * Version accepts any git ref — tags, branches, SHAs, or `'latest'`
     * (resolved as the default branch tip at fetch time).
     *
     * @param  array<string,string>  $skills  map `name => 'path/in/repo'`. Use `.` for repo root.
     */
    public static function githubPath(string $source, string $version, array $skills): self
    {
        $refs = [];
        foreach ($skills as $name => $path) {
            $refs[] = new RemoteSkillRef(name: $name, path: $path);
        }

        return new self($source, $version, $refs);
    }

    public function mode(): string
    {
        // Safe — `assertUniformMode()` runs in the constructor; an empty
        // list is rejected before this is ever called.
        return $this->skills[0]->asset !== null ? self::MODE_BUNDLE : self::MODE_PATH;
    }

    /**
     * Stable identifier for de-duplication across `withRemoteSkills()`:
     * `<source>@<version>:<mode>`. Same source at the same version in two
     * different modes (one bundle, one path) is allowed and produces two
     * distinct keys — that's a real, supported config shape.
     */
    public function uniqueKey(): string
    {
        return $this->source . '@' . $this->version . ':' . $this->mode();
    }

    private static function assertBundleVersion(string $source, string $version): void
    {
        if ($version === 'latest') {
            return;
        }

        // Branch heuristic — reject the common default-branch names. A
        // creatively-named branch ("staging") slips through and 404s at
        // fetch time with a clear error.
        $knownBranches = ['main', 'master', 'develop', 'dev', 'trunk'];
        if (in_array($version, $knownBranches, true)) {
            throw new InvalidArgumentException(sprintf(
                'githubBundle `%s`: version `%s` looks like a branch name; release assets are tag-anchored. Use a release tag (e.g. `v1.2.0`) or `latest`.',
                $source,
                $version,
            ));
        }

        // SHA heuristic — bare hex 7+ chars.
        if (preg_match('/^[0-9a-f]{7,40}$/i', $version) === 1) {
            throw new InvalidArgumentException(sprintf(
                'githubBundle `%s`: version `%s` looks like a Git SHA; release assets are addressed by tag only. Use a release tag (e.g. `v1.2.0`) or `latest`.',
                $source,
                $version,
            ));
        }
    }

    private function assertUniformMode(): void
    {
        $firstHasAsset = $this->skills[0]->asset !== null;
        foreach ($this->skills as $skill) {
            if (($skill->asset !== null) !== $firstHasAsset) {
                throw new InvalidArgumentException(sprintf(
                    'RemoteSkillSource `%s@%s`: cannot mix `asset` (bundle) and `path` skills in one source. Use the matching factory (`githubBundle` or `githubPath`) per source.',
                    $this->source,
                    $this->version,
                ));
            }
        }
    }

    private function assertNoDuplicateSkillNames(): void
    {
        $seen = [];
        foreach ($this->skills as $skill) {
            if (isset($seen[$skill->name])) {
                throw new InvalidArgumentException(sprintf(
                    'RemoteSkillSource `%s@%s`: duplicate skill name `%s`.',
                    $this->source,
                    $this->version,
                    $skill->name,
                ));
            }

            $seen[$skill->name] = true;
        }
    }

    private function assertNoDuplicatePaths(): void
    {
        $seen = [];
        foreach ($this->skills as $skill) {
            $path = (string) $skill->path;
            if (isset($seen[$path])) {
                throw new InvalidArgumentException(sprintf(
                    'RemoteSkillSource `%s@%s`: duplicate path `%s` requested by multiple skills.',
                    $this->source,
                    $this->version,
                    $path,
                ));
            }

            $seen[$path] = true;
        }
    }
}
