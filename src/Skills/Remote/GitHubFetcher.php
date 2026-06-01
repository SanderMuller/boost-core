<?php declare(strict_types=1);

namespace SanderMuller\BoostCore\Skills\Remote;

/**
 * GitHub-backed {@see RemoteFetcher} implementation.
 *
 * Talks to three host families, all hardcoded in the allow-list:
 *  - `api.github.com` — Releases + Commits API (JSON ref resolution).
 *  - `codeload.github.com` — repo tarball downloads (path mode).
 *  - `*.githubusercontent.com` — release-asset downloads (bundle mode);
 *    cURL transparently follows the redirect from the API's
 *    `browser_download_url` to `objects.githubusercontent.com` or
 *    `release-assets.githubusercontent.com`. Default cURL behavior since
 *    7.58 strips the `Authorization` header on cross-host hops, so the
 *    bearer token never leaks to the asset host.
 *
 * `BOOST_GITHUB_TOKEN` raises the anonymous 60 req/h limit to 5000 — set
 * only when hitting `api.github.com` / `codeload.github.com`, never the
 * asset hosts.
 *
 * The HTTP I/O lives in an injected {@see HttpTransport} so the URL-building
 * + response-classification logic here is fully unit-testable without a
 * real HTTP server.
 */
final readonly class GitHubFetcher implements RemoteFetcher
{
    private const API_HOSTS = ['api.github.com', 'codeload.github.com'];

    private const ASSET_HOST_SUFFIX = '.githubusercontent.com';

    private const ACCEPT_JSON = 'application/vnd.github+json';

    private const GH_API_VERSION = '2022-11-28';

    private ?string $token;

    public function __construct(
        private HttpTransport $transport,
        ?string $token = null,
    ) {
        $this->token = $token ?? $this->tokenFromEnv();
    }

    public function resolveRef(string $source, string $version, string $mode): ResolvedRef
    {
        return $mode === RemoteSkillSource::MODE_BUNDLE
            ? $this->resolveBundleRef($source, $version)
            : $this->resolvePathRef($source, $version);
    }

    public function fetchAsset(string $source, ResolvedRef $ref, string $assetName, string $destinationPath): void
    {
        $releaseUrl = sprintf('https://api.github.com/repos/%s/releases/tags/%s', $source, $ref->resolved);
        $release = $this->fetchJson($releaseUrl);

        $assetUrl = $this->findAssetUrl($release, $assetName);
        if ($assetUrl === null) {
            throw new RemoteFetchException(
                sprintf('Asset `%s` not found in release `%s@%s`.', $assetName, $source, $ref->resolved),
                RemoteFetchException::NOT_FOUND,
            );
        }

        $this->download($assetUrl, $destinationPath);
    }

    public function fetchTarball(string $source, ResolvedRef $ref, string $destinationPath): void
    {
        $url = sprintf('https://codeload.github.com/%s/tar.gz/%s', $source, $ref->resolved);
        $this->download($url, $destinationPath);
    }

    private function resolveBundleRef(string $source, string $version): ResolvedRef
    {
        $url = $version === 'latest'
            ? sprintf('https://api.github.com/repos/%s/releases/latest', $source)
            : sprintf('https://api.github.com/repos/%s/releases/tags/%s', $source, $version);

        $release = $this->fetchJson($url);
        $tag = $release['tag_name'] ?? null;
        if (! is_string($tag) || $tag === '') {
            throw new RemoteFetchException(
                sprintf('Release for `%s@%s` is missing `tag_name`.', $source, $version),
                RemoteFetchException::MALFORMED_RESPONSE,
            );
        }

        return new ResolvedRef(requested: $version, resolved: $tag);
    }

    private function resolvePathRef(string $source, string $version): ResolvedRef
    {
        // `'latest'` in path mode resolves to the default-branch tip via
        // `HEAD`. GitHub's commits endpoint accepts a branch name, tag, or
        // SHA — `HEAD` is shorthand for the default branch.
        $ref = $version === 'latest' ? 'HEAD' : $version;
        $url = sprintf('https://api.github.com/repos/%s/commits/%s', $source, $ref);

        $commit = $this->fetchJson($url);
        $sha = $commit['sha'] ?? null;
        if (! is_string($sha) || $sha === '') {
            throw new RemoteFetchException(
                sprintf('Commit for `%s@%s` is missing `sha`.', $source, $version),
                RemoteFetchException::MALFORMED_RESPONSE,
            );
        }

        return new ResolvedRef(requested: $version, resolved: $sha);
    }

    /**
     * @param  array<mixed,mixed>  $release
     */
    private function findAssetUrl(array $release, string $assetName): ?string
    {
        $assets = $release['assets'] ?? null;
        if (! is_array($assets)) {
            return null;
        }

        foreach ($assets as $asset) {
            if (! is_array($asset)) {
                continue;
            }

            $name = $asset['name'] ?? null;
            $downloadUrl = $asset['browser_download_url'] ?? null;
            if ($name === $assetName && is_string($downloadUrl)) {
                return $downloadUrl;
            }
        }

        return null;
    }

    /**
     * @return array<mixed,mixed>
     */
    private function fetchJson(string $url): array
    {
        $response = $this->request($url);
        $decoded = json_decode($response->body, true);
        if (! is_array($decoded)) {
            throw new RemoteFetchException(
                sprintf('Malformed JSON response from `%s`.', $url),
                RemoteFetchException::MALFORMED_RESPONSE,
            );
        }

        return $decoded;
    }

    private function download(string $url, string $destinationPath): void
    {
        $this->request($url, $destinationPath);
    }

    private function request(string $url, ?string $destinationPath = null): HttpResponse
    {
        $initialHost = $this->host($url);
        $this->assertHostAllowed($initialHost, RemoteFetchException::BAD_REDIRECT);

        $response = $this->transport->get($url, $this->buildHeaders($initialHost), $destinationPath);

        $finalHost = $this->host($response->effectiveUrl);
        $this->assertHostAllowed($finalHost, RemoteFetchException::BAD_REDIRECT);

        if ($response->status !== 200) {
            $this->cleanupOnFailure($destinationPath);
            throw $this->classifyError($url, $response);
        }

        return $response;
    }

    private function assertHostAllowed(string $host, string $reason): void
    {
        if ($host === '') {
            throw new RemoteFetchException('Cannot parse host from URL.', $reason);
        }

        if (in_array($host, self::API_HOSTS, true)) {
            return;
        }

        if (str_ends_with($host, self::ASSET_HOST_SUFFIX)) {
            return;
        }

        throw new RemoteFetchException(
            sprintf('Host `%s` is not in the GitHub allow-list (api.github.com, codeload.github.com, *.githubusercontent.com).', $host),
            $reason,
        );
    }

    /**
     * @return list<string>
     */
    private function buildHeaders(string $host): array
    {
        $headers = [
            'Accept: ' . self::ACCEPT_JSON,
            'X-GitHub-Api-Version: ' . self::GH_API_VERSION,
        ];

        if ($this->token !== null && in_array($host, self::API_HOSTS, true)) {
            $headers[] = 'Authorization: Bearer ' . $this->token;
        }

        return $headers;
    }

    private function cleanupOnFailure(?string $destinationPath): void
    {
        if ($destinationPath !== null && file_exists($destinationPath)) {
            @unlink($destinationPath);
        }
    }

    private function classifyError(string $url, HttpResponse $response): RemoteFetchException
    {
        return match (true) {
            $response->status === 401 => new RemoteFetchException(
                'Unauthorized — `BOOST_GITHUB_TOKEN` is invalid or lacks scope.',
                RemoteFetchException::UNAUTHORIZED,
            ),
            $response->status === 404 => new RemoteFetchException(
                sprintf('Not found: `%s`.', $url),
                RemoteFetchException::NOT_FOUND,
            ),
            $response->status === 429 || $this->isRateLimitedForbidden($response) => new RemoteFetchException(
                'Rate-limited by GitHub. Set `BOOST_GITHUB_TOKEN` to raise the limit from 60 to 5000 req/h.',
                RemoteFetchException::RATE_LIMITED,
            ),
            $response->status >= 500 => new RemoteFetchException(
                sprintf('GitHub server error (%d) at `%s`.', $response->status, $url),
                RemoteFetchException::SERVER_ERROR,
            ),
            default => new RemoteFetchException(
                sprintf('Unexpected HTTP %d from `%s`.', $response->status, $url),
                RemoteFetchException::SERVER_ERROR,
            ),
        };
    }

    private function isRateLimitedForbidden(HttpResponse $response): bool
    {
        return $response->status === 403 && $response->header('x-ratelimit-remaining') === '0';
    }

    private function host(string $url): string
    {
        $host = parse_url($url, PHP_URL_HOST);

        return is_string($host) ? $host : '';
    }

    private function tokenFromEnv(): ?string
    {
        $value = getenv('BOOST_GITHUB_TOKEN');

        return is_string($value) && $value !== '' ? $value : null;
    }
}
