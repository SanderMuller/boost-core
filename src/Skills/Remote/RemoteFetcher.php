<?php declare(strict_types=1);

namespace SanderMuller\BoostCore\Skills\Remote;

/**
 * Production fetcher contract — what `RemoteSkillCache` and `SyncEngine`
 * depend on for ingesting remote skill sources. Implementations:
 *  - {@see GitHubFetcher}: hits the GitHub API + codeload + asset URLs over cURL.
 *  - `tests/Doubles/Remote/FakeRemoteFetcher` (test double): serves canned
 *    responses from an in-memory map for tests that don't want to wire up
 *    `HttpTransport` + GitHubFetcher together.
 *
 * All methods throw {@see RemoteFetchException} on failure with a typed
 * `$reason`. Callers branch on the reason to decide cache-fallback,
 * warn-and-skip, or escalation under `BOOST_REMOTE_STRICT`.
 */
interface RemoteFetcher
{
    /**
     * Resolve a `version` string (tag / branch / SHA / `'latest'`) for the given
     * source and mode. Bundle mode resolves to a release tag; path mode
     * resolves to a 40-char SHA. The result is the cache slot identifier.
     */
    public function resolveRef(string $source, string $version, string $mode): ResolvedRef;

    /**
     * Download a `.skill` release asset to a local temp file path. The caller
     * owns the file (extractor + cache promote it to the final cache slot).
     */
    public function fetchAsset(string $source, ResolvedRef $ref, string $assetName, string $destinationPath): void;

    /**
     * Download a repo tarball at a ref to a local temp file path.
     */
    public function fetchTarball(string $source, ResolvedRef $ref, string $destinationPath): void;
}
