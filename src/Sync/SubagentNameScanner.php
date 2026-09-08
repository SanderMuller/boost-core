<?php declare(strict_types=1);

namespace SanderMuller\BoostCore\Sync;

use SanderMuller\BoostCore\Agents\AgentTarget;
use SanderMuller\BoostCore\Skills\FrontmatterParser;
use Symfony\Component\Finder\Finder;

/**
 * Finds Claude Code subagent definitions that declare the SAME `name` under one
 * subagent root, which Claude Code resolves nondeterministically.
 *
 * From the subagent docs: "if two files under the same `.claude/agents/`
 * directory, including its subfolders, declare the same name, Claude Code loads
 * only one of them, chosen by filesystem read order rather than a documented
 * precedence." Identity comes ONLY from the `name` frontmatter field — the
 * subdirectory path never namespaces it — so two files in different subfolders
 * still collide.
 *
 * Read-only and advisory. boost NEVER arbitrates: a collision typically involves
 * at least one file boost does not own, and picking a winner would mean deleting
 * an operator's work. The operator resolves it by renaming or removing one side.
 *
 * Useful before boost emits any subagent of its own — a consumer's hand-written
 * definitions can already collide with each other.
 *
 * @internal
 */
final readonly class SubagentNameScanner
{
    public function __construct(private FrontmatterParser $frontmatterParser = new FrontmatterParser()) {}

    /**
     * The directories a subagent-capable agent target scans, derived from the
     * targets themselves so the scan root and the emit root cannot drift apart.
     *
     * @return list<string>
     */
    public static function roots(): array
    {
        $roots = [];
        foreach (SyncEngine::allAgentTargets() as $target) {
            $directory = $target->subagentsDirectoryRelative();
            if ($directory !== null) {
                $roots[$directory] = true;
            }
        }

        return array_keys($roots);
    }

    /**
     * Every `name` held by more than one file under the subagent root.
     *
     * @return array<string, list<string>>  name => sorted project-relative paths, keyed in name order
     */
    public function scan(string $projectRoot): array
    {
        /** @var array<string, list<string>> $byName */
        $byName = [];
        foreach ($this->namesByPath($projectRoot) as $relativePath => $name) {
            $byName[$name][] = $relativePath;
        }

        $overlaps = array_filter($byName, static fn (array $paths): bool => count($paths) > 1);
        ksort($overlaps);

        return $overlaps;
    }

    /**
     * Every `*.md` under the subagent root that declares a `name`, as
     * project-relative path => declared name, in path order.
     *
     * Symlinked directories are not followed: Finder does not follow links
     * unless asked, and it is never asked here — same invariant as
     * {@see AgentDirSymlinkScanner}, which is that boost never walks out of the
     * managed tree through a link it cannot prove it owns.
     *
     * @return array<string, string>
     */
    private function namesByPath(string $projectRoot): array
    {
        $found = [];
        foreach (self::roots() as $root) {
            $absolute = $projectRoot . '/' . $root;
            if (! is_dir($absolute)) {
                continue;
            }

            $finder = (new Finder())
                ->files()
                ->in($absolute)
                ->name('*.md')
                ->sortByName();

            foreach ($finder as $file) {
                $contents = @file_get_contents($file->getPathname());
                if ($contents === false) {
                    continue;
                }

                $name = $this->declaredName($contents);
                if ($name === null) {
                    continue;
                }

                $found[$root . '/' . str_replace('\\', '/', $file->getRelativePathname())] = $name;
            }
        }

        return $found;
    }

    /**
     * Files OUTSIDE the boost subtree that declare one of `$names`.
     *
     * The emission-time half of the check: boost knows the names it is about to
     * write before it writes them, so it can name the conflict at the moment it
     * is introduced rather than leaving the operator to find it later. Files
     * under the boost subtree are excluded — those are boost's own emissions,
     * and an emitted file colliding with itself is not a finding.
     *
     * @param  list<string>  $names
     * @return array<string, list<string>>  name => sorted project-relative paths of the unowned holders
     */
    public function unownedHolders(string $projectRoot, array $names): array
    {
        if ($names === []) {
            return [];
        }

        $wanted = array_flip($names);
        $ownedPrefixes = array_map(
            static fn (string $root): string => $root . '/' . AgentTarget::SUBAGENT_BOOST_SEGMENT . '/',
            self::roots(),
        );

        $holders = [];
        foreach ($this->namesByPath($projectRoot) as $relativePath => $name) {
            if (! isset($wanted[$name])) {
                continue;
            }

            if ($this->isBoostOwned($relativePath, $ownedPrefixes)) {
                continue;
            }

            $holders[$name][] = $relativePath;
        }

        ksort($holders);

        return $holders;
    }

    /**
     * @param  list<string>  $ownedPrefixes
     */
    private function isBoostOwned(string $relativePath, array $ownedPrefixes): bool
    {
        foreach ($ownedPrefixes as $prefix) {
            if (str_starts_with($relativePath, $prefix)) {
                return true;
            }
        }

        return false;
    }

    /**
     * A subagent's identity: the frontmatter `name`, or null when the file
     * declares none.
     *
     * There is NO filename-stem fallback, because Claude Code does not load
     * such a file at all: "No `name`: Claude Code treats the file as
     * documentation kept beside your agents." A file it never loads cannot
     * collide with anything, so counting it would report a conflict that does
     * not exist.
     *
     * The same applies to an unparseable YAML head — {@see FrontmatterParser}
     * returns EMPTY frontmatter rather than throwing, which lands here as "no
     * declared name" and is skipped, matching Claude Code's own handling.
     */
    private function declaredName(string $contents): ?string
    {
        $declared = $this->frontmatterParser->parse($contents)->frontmatter['name'] ?? null;

        if (! is_string($declared) || trim($declared) === '') {
            return null;
        }

        return trim($declared);
    }
}
