<?php declare(strict_types=1);

namespace SanderMuller\BoostCore\Commands;

use JsonException;
use SanderMuller\BoostCore\Config\BoostConfig;
use SanderMuller\BoostCore\Skills\Remote\RemoteSkillCache;
use SanderMuller\BoostCore\Skills\Remote\RemoteSkillSource;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * The `boost doctor` remote-skill-sources reporter — extracted from
 * {@see DoctorCommand} so the offline cache-inspection subsystem
 * (`source@version`, moving-ref warnings, per-skill cache presence) lives in one
 * collaborator. Strictly offline: checks the filesystem only, never the network.
 * Behavior is identical to the prior inline `reportRemoteSkills()`.
 */
final readonly class RemoteSkillsReporter
{
    public function report(SymfonyStyle $io, BoostConfig $config): void
    {
        if ($config->remoteSkills === []) {
            return;
        }

        $io->section('Remote skill sources');
        $cacheRoot = RemoteSkillCache::resolveCacheRoot();
        $io->writeln(sprintf('Cache root: <comment>%s</comment>', $cacheRoot));
        $io->newLine();

        $movingRefSeen = false;
        foreach ($config->remoteSkills as $source) {
            $pinned = RemoteSkillCache::isPinnedVersion($source->version, $source->mode());
            $movingRefSeen = $movingRefSeen || ! $pinned;
            $marker = $pinned ? '' : '  <comment>⚠ moving ref — may drift between syncs</comment>';
            $io->writeln(sprintf(
                '<info>%s@%s</info> (%s)%s',
                $source->source,
                $source->version,
                $source->mode(),
                $marker,
            ));

            $slugDir = $cacheRoot . '/' . RemoteSkillCache::slug($source->source);
            $rows = [];
            foreach ($source->skills as $ref) {
                $cached = $this->remoteSkillCachedForDeclaredRef($slugDir, $source, $ref->name);
                $rows[] = [
                    '  ' . $ref->name,
                    $cached ? '<info>cached</info>' : '<comment>not cached (will fetch on next sync)</comment>',
                ];
            }

            if ($rows !== []) {
                $io->table(['Skill', 'Cache'], $rows);
            }
        }

        if ($movingRefSeen) {
            $io->warning('Moving refs re-resolve every 24h and can drift silently. Pin to a tag (`v1.2.0`) or full SHA for reproducible builds.');
        }

        $token = getenv('BOOST_GITHUB_TOKEN');
        if (count($config->remoteSkills) > 3 && (! is_string($token) || $token === '')) {
            $io->note('Anonymous GitHub access caps at 60 requests/hour. Set BOOST_GITHUB_TOKEN to lift this to 5000/hour.');
        }
    }

    /**
     * True when the cache holds `SKILL.md` for THIS source's currently-declared
     * ref — not just any earlier ref. The check is offline:
     *
     *  - Pinned versions (`v1.2.3`, full SHAs) resolve to themselves; the
     *    slot path is fully knowable without I/O. The skill is cached iff
     *    `<slugDir>/<version>/<skillName>/SKILL.md` exists.
     *  - Moving refs (`'main'`, `'latest'`, branches) need the
     *    resolution-cache file to map the declared ref to its last-resolved
     *    SHA. No cache file or no entry → "not cached" (offline contract:
     *    never call out to GitHub). With an entry, check the SHA slot.
     *
     * Reporting any-ref-matches would lie after a version bump — the
     * previous slot would mark the new ref "cached" even though the next
     * sync must fetch.
     */
    private function remoteSkillCachedForDeclaredRef(string $slugDir, RemoteSkillSource $source, string $skillName): bool
    {
        if (! is_dir($slugDir)) {
            return false;
        }

        if (RemoteSkillCache::isPinnedVersion($source->version, $source->mode())) {
            return is_file($slugDir . '/' . $source->version . '/' . $skillName . '/SKILL.md');
        }

        $resolved = $this->readResolutionCacheEntry($slugDir, $source);
        if ($resolved === null) {
            return false;
        }

        return is_file($slugDir . '/' . $resolved . '/' . $skillName . '/SKILL.md');
    }

    /**
     * Read the resolution-cache file for the source's slug and return the
     * SHA last resolved for `<version>:<mode>`, or null if not present. Pure
     * filesystem read — never hits the network.
     */
    private function readResolutionCacheEntry(string $slugDir, RemoteSkillSource $source): ?string
    {
        $path = $slugDir . '/.resolution-cache.json';
        if (! is_file($path)) {
            return null;
        }

        $raw = @file_get_contents($path);
        if ($raw === false) {
            return null;
        }

        try {
            $decoded = json_decode($raw, true, flags: JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            return null;
        }

        $key = $source->version . ':' . $source->mode();
        $entry = is_array($decoded) ? ($decoded[$key] ?? null) : null;
        if (! is_array($entry)) {
            return null;
        }

        $resolved = $entry['resolved'] ?? null;

        return is_string($resolved) && $resolved !== '' ? $resolved : null;
    }
}
