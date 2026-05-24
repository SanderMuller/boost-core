<?php declare(strict_types=1);

namespace SanderMuller\BoostCore\Tests\Doubles\Remote;

use RuntimeException;
use SanderMuller\BoostCore\Skills\Remote\HttpResponse;
use SanderMuller\BoostCore\Skills\Remote\HttpTransport;

/**
 * Test double for {@see HttpTransport}.
 *
 * Tests pre-register expected responses per URL via {@see expect()}; calls
 * to {@see get()} pop the queued response. Streaming-to-file is honored:
 * when a destination path is provided and the response is 200, the body
 * lands at that path (the real `CurlHttpTransport` streams via cURL's
 * `CURLOPT_FILE`).
 *
 * `$requestedUrls` and `$requestedHeaders` are captured for tests that
 * assert on which URLs were hit and which headers were sent (e.g. token
 * attachment, host-allow-list enforcement).
 */
final class FakeHttpTransport implements HttpTransport
{
    /** @var array<string, list<HttpResponse>> */
    private array $responses = [];

    /** @var list<string> */
    public array $requestedUrls = [];

    /** @var list<list<string>> */
    public array $requestedHeaders = [];

    public function expect(string $url, HttpResponse $response): self
    {
        $this->responses[$url][] = $response;

        return $this;
    }

    public function get(string $url, array $headers, ?string $destinationPath = null): HttpResponse
    {
        $this->requestedUrls[] = $url;
        $this->requestedHeaders[] = $headers;

        $queue = $this->responses[$url] ?? [];
        if ($queue === []) {
            throw new RuntimeException(sprintf('FakeHttpTransport: no canned response for `%s`.', $url));
        }

        $response = array_shift($queue);
        $this->responses[$url] = $queue;

        if ($destinationPath !== null && $response->status === 200) {
            file_put_contents($destinationPath, $response->body);
        }

        return $response;
    }
}
