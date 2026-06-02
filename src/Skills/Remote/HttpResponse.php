<?php declare(strict_types=1);

namespace SanderMuller\BoostCore\Skills\Remote;

/**
 * HTTP response shape returned by {@see HttpTransport::get()}.
 *
 * `effectiveUrl` is the URL the response was actually read from after any
 * redirect chain — used by {@see GitHubFetcher} to verify the final host
 * is still allow-listed (a cross-host redirect to a non-GitHub host
 * triggers a {@see RemoteFetchException::BAD_REDIRECT} abort).
 *
 * @internal
 */
final readonly class HttpResponse
{
    /**
     * @param  array<string,string>  $headers  lower-case header names
     */
    public function __construct(
        public int $status,
        public string $body,
        public array $headers,
        public string $effectiveUrl,
    ) {}

    public function header(string $name): ?string
    {
        return $this->headers[strtolower($name)] ?? null;
    }
}
