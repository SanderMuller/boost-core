<?php declare(strict_types=1);

namespace SanderMuller\BoostCore\Discovery;

use JsonException;
use SanderMuller\BoostCore\Skills\Remote\CurlHttpTransport;
use SanderMuller\BoostCore\Skills\Remote\HttpTransport;
use Throwable;

/**
 * Looks up the latest stable version of a Composer package on Packagist.
 *
 * Used by `boost doctor --check-versions` to flag stale path-repo
 * shadows. Pure read-only: one HTTP GET per package, no caching beyond
 * the process. A failed lookup returns `null` and the caller treats it
 * as "couldn't verify" rather than fataling.
 *
 * @internal
 */
final readonly class PackagistVersionLookup
{
    public function __construct(
        private HttpTransport $transport = new CurlHttpTransport(),
    ) {}

    /**
     * Return the latest stable version string Packagist publishes for
     * `<vendor>/<package>`, or `null` when the lookup failed or there
     * are no stable versions. A "stable" version is one whose name
     * matches the routine semver shape `X.Y.Z` — no `-rc`, `-beta`,
     * `-alpha`, `-dev` suffix, no leading `dev-`.
     */
    public function latestStable(string $packageName): ?string
    {
        // URL-encode each `<vendor>/<package>` segment defensively — even
        // though the caller passes a Composer-validated name, a stray
        // space / encoded traversal in the package name must not be able
        // to alter the URL path. The slash separator stays literal.
        $encoded = implode('/', array_map(rawurlencode(...), explode('/', $packageName)));
        $url = sprintf('https://repo.packagist.org/p2/%s.json', $encoded);

        try {
            $response = $this->transport->get($url, ['Accept: application/json']);
        } catch (Throwable) {
            return null;
        }

        if ($response->status !== 200) {
            return null;
        }

        try {
            $decoded = json_decode($response->body, true, flags: JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            return null;
        }

        if (! is_array($decoded)) {
            return null;
        }

        $packagesRoot = $decoded['packages'] ?? null;
        if (! is_array($packagesRoot)) {
            return null;
        }

        $packages = $packagesRoot[$packageName] ?? null;
        if (! is_array($packages)) {
            return null;
        }

        // Packagist's v2 metadata format lists versions newest-first;
        // pick the first whose version is a stable semver triple.
        foreach ($packages as $entry) {
            if (! is_array($entry)) {
                continue;
            }

            $version = $entry['version_normalized'] ?? $entry['version'] ?? null;
            if (! is_string($version)) {
                continue;
            }

            if ($version === '') {
                continue;
            }

            // Strip a leading `v` so a `version` field of `v0.7.1` (common
            // git-tag convention) matches the regex when `version_normalized`
            // is missing. `version_normalized` is always digits.
            $candidate = ltrim($version, 'v');

            // Match `X.Y.Z` (optionally `X.Y.Z.W` from version_normalized,
            // which appends a `.0` patch — strip to the routine triple).
            if (preg_match('/^\d+\.\d+\.\d+(?:\.0)?$/', $candidate) !== 1) {
                continue;
            }

            // Prefer the human-readable form when present (no `.0` tail).
            $display = $entry['version'] ?? $version;

            return is_string($display) ? ltrim($display, 'v') : null;
        }

        return null;
    }
}
