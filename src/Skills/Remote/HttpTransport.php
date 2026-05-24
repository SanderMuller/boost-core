<?php declare(strict_types=1);

namespace SanderMuller\BoostCore\Skills\Remote;

/**
 * Low-level HTTP seam {@see GitHubFetcher} depends on. Exists so the
 * fetcher's URL/header/redirect/auth logic is fully testable without a
 * real HTTP server: production passes {@see CurlHttpTransport}, tests
 * pass `tests/Doubles/Remote/FakeHttpTransport` returning canned bodies.
 *
 * The transport is responsible for:
 *  - Following redirects (cURL default; with `Authorization` stripping on
 *    cross-host hops, the default since cURL 7.58).
 *  - Surfacing the FINAL effective URL so the fetcher can verify it
 *    landed on an allow-listed host post-redirect.
 *  - Streaming to disk when `$destinationPath` is set (tarballs + assets
 *    may be MB-scale; never load into memory).
 */
interface HttpTransport
{
    /**
     * GET `$url` with `$headers`. If `$destinationPath` is non-null, stream
     * the body to that file path (returned body string is empty). Otherwise
     * return the body inline.
     *
     * @param  list<string>  $headers
     */
    public function get(string $url, array $headers, ?string $destinationPath = null): HttpResponse;
}
