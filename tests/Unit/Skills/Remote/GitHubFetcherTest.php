<?php declare(strict_types=1);

use SanderMuller\BoostCore\Skills\Remote\GitHubFetcher;
use SanderMuller\BoostCore\Skills\Remote\HttpResponse;
use SanderMuller\BoostCore\Skills\Remote\RemoteFetchException;
use SanderMuller\BoostCore\Skills\Remote\RemoteSkillSource;
use SanderMuller\BoostCore\Skills\Remote\ResolvedRef;
use SanderMuller\BoostCore\Tests\Doubles\Remote\FakeHttpTransport;

/**
 * @param  array<string,string>  $headers
 */
function fakeResponse(int $status, string $body, string $effectiveUrl, array $headers = []): HttpResponse
{
    return new HttpResponse(status: $status, body: $body, headers: $headers, effectiveUrl: $effectiveUrl);
}

// ---------- resolveRef — bundle mode ----------

it('resolveRef (bundle) calls /releases/tags/<v> and returns the tag_name', function (): void {
    $url = 'https://api.github.com/repos/peterfox/agent-skills/releases/tags/v1.2.0';
    $transport = (new FakeHttpTransport())
        ->expect($url, fakeResponse(200, json_encode(['tag_name' => 'v1.2.0', 'assets' => []], JSON_THROW_ON_ERROR), $url));

    $ref = (new GitHubFetcher($transport))->resolveRef('peterfox/agent-skills', 'v1.2.0', RemoteSkillSource::MODE_BUNDLE);

    expect($ref)->toBeInstanceOf(ResolvedRef::class)
        ->and($ref->requested)->toBe('v1.2.0')
        ->and($ref->resolved)->toBe('v1.2.0')
        ->and($transport->requestedUrls)->toBe([$url]);
});

it('resolveRef (bundle) with `latest` hits /releases/latest', function (): void {
    $url = 'https://api.github.com/repos/a/b/releases/latest';
    $transport = (new FakeHttpTransport())
        ->expect($url, fakeResponse(200, json_encode(['tag_name' => 'v9.9.9'], JSON_THROW_ON_ERROR), $url));

    $ref = (new GitHubFetcher($transport))->resolveRef('a/b', 'latest', RemoteSkillSource::MODE_BUNDLE);

    expect($ref->resolved)->toBe('v9.9.9');
});

// ---------- resolveRef — path mode ----------

it('resolveRef (path) calls /commits/<ref> and returns the SHA', function (): void {
    $url = 'https://api.github.com/repos/mattpocock/skills/commits/main';
    $sha = 'abc1234def5678abc1234def5678abc1234def56';
    $transport = (new FakeHttpTransport())
        ->expect($url, fakeResponse(200, json_encode(['sha' => $sha], JSON_THROW_ON_ERROR), $url));

    $ref = (new GitHubFetcher($transport))->resolveRef('mattpocock/skills', 'main', RemoteSkillSource::MODE_PATH);

    expect($ref->resolved)->toBe($sha)
        ->and($ref->requested)->toBe('main');
});

it('resolveRef (path) with `latest` resolves /commits/HEAD', function (): void {
    $url = 'https://api.github.com/repos/a/b/commits/HEAD';
    $transport = (new FakeHttpTransport())
        ->expect($url, fakeResponse(200, json_encode(['sha' => 'cafebabe'], JSON_THROW_ON_ERROR), $url));

    $ref = (new GitHubFetcher($transport))->resolveRef('a/b', 'latest', RemoteSkillSource::MODE_PATH);

    expect($ref->resolved)->toBe('cafebabe');
});

// ---------- Error classification ----------

it('throws NOT_FOUND on 404', function (): void {
    $url = 'https://api.github.com/repos/a/b/releases/tags/v9.9.9';
    $transport = (new FakeHttpTransport())
        ->expect($url, fakeResponse(404, '{"message":"Not Found"}', $url));

    try {
        (new GitHubFetcher($transport))->resolveRef('a/b', 'v9.9.9', RemoteSkillSource::MODE_BUNDLE);
        throw new RuntimeException('Expected RemoteFetchException.');
    } catch (RemoteFetchException $remoteFetchException) {
        expect($remoteFetchException->reason)->toBe(RemoteFetchException::NOT_FOUND);
    }
});

it('throws UNAUTHORIZED on 401', function (): void {
    $url = 'https://api.github.com/repos/a/b/releases/tags/v1.0';
    $transport = (new FakeHttpTransport())
        ->expect($url, fakeResponse(401, '{"message":"Bad credentials"}', $url));

    try {
        (new GitHubFetcher($transport))->resolveRef('a/b', 'v1.0', RemoteSkillSource::MODE_BUNDLE);
        throw new RuntimeException('Expected RemoteFetchException.');
    } catch (RemoteFetchException $remoteFetchException) {
        expect($remoteFetchException->reason)->toBe(RemoteFetchException::UNAUTHORIZED);
    }
});

it('throws RATE_LIMITED on 429', function (): void {
    $url = 'https://api.github.com/repos/a/b/releases/tags/v1.0';
    $transport = (new FakeHttpTransport())
        ->expect($url, fakeResponse(429, '{"message":"Too Many Requests"}', $url));

    try {
        (new GitHubFetcher($transport))->resolveRef('a/b', 'v1.0', RemoteSkillSource::MODE_BUNDLE);
        throw new RuntimeException('Expected RemoteFetchException.');
    } catch (RemoteFetchException $remoteFetchException) {
        expect($remoteFetchException->reason)->toBe(RemoteFetchException::RATE_LIMITED);
    }
});

it('throws RATE_LIMITED on 403 with x-ratelimit-remaining: 0', function (): void {
    $url = 'https://api.github.com/repos/a/b/releases/tags/v1.0';
    $transport = (new FakeHttpTransport())
        ->expect($url, fakeResponse(403, '{"message":"API rate limit exceeded"}', $url, ['x-ratelimit-remaining' => '0']));

    try {
        (new GitHubFetcher($transport))->resolveRef('a/b', 'v1.0', RemoteSkillSource::MODE_BUNDLE);
        throw new RuntimeException('Expected RemoteFetchException.');
    } catch (RemoteFetchException $remoteFetchException) {
        expect($remoteFetchException->reason)->toBe(RemoteFetchException::RATE_LIMITED);
    }
});

it('treats a 403 without rate-limit headers as SERVER_ERROR (not RATE_LIMITED)', function (): void {
    $url = 'https://api.github.com/repos/a/b/releases/tags/v1.0';
    $transport = (new FakeHttpTransport())
        ->expect($url, fakeResponse(403, '{"message":"Forbidden"}', $url));

    try {
        (new GitHubFetcher($transport))->resolveRef('a/b', 'v1.0', RemoteSkillSource::MODE_BUNDLE);
        throw new RuntimeException('Expected RemoteFetchException.');
    } catch (RemoteFetchException $remoteFetchException) {
        expect($remoteFetchException->reason)->toBe(RemoteFetchException::SERVER_ERROR);
    }
});

it('throws SERVER_ERROR on 5xx', function (): void {
    $url = 'https://api.github.com/repos/a/b/releases/tags/v1.0';
    $transport = (new FakeHttpTransport())
        ->expect($url, fakeResponse(503, '{"message":"Service Unavailable"}', $url));

    try {
        (new GitHubFetcher($transport))->resolveRef('a/b', 'v1.0', RemoteSkillSource::MODE_BUNDLE);
        throw new RuntimeException('Expected RemoteFetchException.');
    } catch (RemoteFetchException $remoteFetchException) {
        expect($remoteFetchException->reason)->toBe(RemoteFetchException::SERVER_ERROR);
    }
});

it('throws MALFORMED_RESPONSE on non-JSON body', function (): void {
    $url = 'https://api.github.com/repos/a/b/releases/tags/v1.0';
    $transport = (new FakeHttpTransport())
        ->expect($url, fakeResponse(200, '<html>not json</html>', $url));

    try {
        (new GitHubFetcher($transport))->resolveRef('a/b', 'v1.0', RemoteSkillSource::MODE_BUNDLE);
        throw new RuntimeException('Expected RemoteFetchException.');
    } catch (RemoteFetchException $remoteFetchException) {
        expect($remoteFetchException->reason)->toBe(RemoteFetchException::MALFORMED_RESPONSE);
    }
});

it('throws MALFORMED_RESPONSE when release JSON lacks tag_name', function (): void {
    $url = 'https://api.github.com/repos/a/b/releases/tags/v1.0';
    $transport = (new FakeHttpTransport())
        ->expect($url, fakeResponse(200, '{"unrelated":"thing"}', $url));

    try {
        (new GitHubFetcher($transport))->resolveRef('a/b', 'v1.0', RemoteSkillSource::MODE_BUNDLE);
        throw new RuntimeException('Expected RemoteFetchException.');
    } catch (RemoteFetchException $remoteFetchException) {
        expect($remoteFetchException->reason)->toBe(RemoteFetchException::MALFORMED_RESPONSE);
    }
});

// ---------- Host allow-list ----------

it('rejects a redirect that lands on a non-allowed host (BAD_REDIRECT)', function (): void {
    $url = 'https://api.github.com/repos/a/b/releases/tags/v1.0';
    $transport = (new FakeHttpTransport())
        ->expect($url, fakeResponse(200, '{}', 'https://evil.example.com/landed-here'));

    try {
        (new GitHubFetcher($transport))->resolveRef('a/b', 'v1.0', RemoteSkillSource::MODE_BUNDLE);
        throw new RuntimeException('Expected RemoteFetchException.');
    } catch (RemoteFetchException $remoteFetchException) {
        expect($remoteFetchException->reason)->toBe(RemoteFetchException::BAD_REDIRECT);
    }
});

it('fetchAsset downloads from an asset URL on *.githubusercontent.com (allow-list wildcard)', function (): void {
    // The Releases API returns `browser_download_url` pointing at
    // `objects.githubusercontent.com` (after cURL follows the redirect
    // chain). The host-allow-list's `*.githubusercontent.com` suffix
    // covers it.
    $releaseUrl = 'https://api.github.com/repos/a/b/releases/tags/v1.0';
    $assetUrl = 'https://objects.githubusercontent.com/foo.skill';

    $release = ['tag_name' => 'v1.0', 'assets' => [['name' => 'foo.skill', 'browser_download_url' => $assetUrl]]];

    $transport = (new FakeHttpTransport())
        ->expect($releaseUrl, fakeResponse(200, json_encode($release, JSON_THROW_ON_ERROR), $releaseUrl))
        ->expect($assetUrl, fakeResponse(200, 'ZIP_BYTES', $assetUrl));

    $dest = sys_get_temp_dir() . '/boost-fetcher-test-' . bin2hex(random_bytes(6));
    try {
        (new GitHubFetcher($transport))->fetchAsset('a/b', new ResolvedRef('v1.0', 'v1.0'), 'foo.skill', $dest);
        expect(file_get_contents($dest))->toBe('ZIP_BYTES');
    } finally {
        @unlink($dest);
    }
});

// ---------- fetchAsset behavior ----------

it('fetchAsset surfaces NOT_FOUND when the asset name is absent from the release', function (): void {
    $releaseUrl = 'https://api.github.com/repos/a/b/releases/tags/v1.0';
    $release = ['tag_name' => 'v1.0', 'assets' => [['name' => 'other.skill', 'browser_download_url' => 'https://objects.githubusercontent.com/x']]];
    $transport = (new FakeHttpTransport())
        ->expect($releaseUrl, fakeResponse(200, json_encode($release, JSON_THROW_ON_ERROR), $releaseUrl));

    try {
        (new GitHubFetcher($transport))->fetchAsset('a/b', new ResolvedRef('v1.0', 'v1.0'), 'missing.skill', '/tmp/x');
        throw new RuntimeException('Expected RemoteFetchException.');
    } catch (RemoteFetchException $remoteFetchException) {
        expect($remoteFetchException->reason)->toBe(RemoteFetchException::NOT_FOUND);
    }
});

it('fetchTarball downloads from codeload.github.com to the destination path', function (): void {
    $tarballUrl = 'https://codeload.github.com/mattpocock/skills/tar.gz/abc123';
    $transport = (new FakeHttpTransport())
        ->expect($tarballUrl, fakeResponse(200, 'TARBALL_BYTES', $tarballUrl));

    $dest = sys_get_temp_dir() . '/boost-fetcher-tar-' . bin2hex(random_bytes(6));
    try {
        (new GitHubFetcher($transport))->fetchTarball('mattpocock/skills', new ResolvedRef('main', 'abc123'), $dest);

        expect(file_get_contents($dest))->toBe('TARBALL_BYTES')
            ->and($transport->requestedUrls)->toContain($tarballUrl);
    } finally {
        @unlink($dest);
    }
});

// ---------- Authorization header attachment ----------

it('does NOT attach Authorization when no token is set', function (): void {
    $url = 'https://api.github.com/repos/a/b/releases/tags/v1.0';
    $transport = (new FakeHttpTransport())
        ->expect($url, fakeResponse(200, '{"tag_name":"v1.0"}', $url));

    (new GitHubFetcher($transport))->resolveRef('a/b', 'v1.0', RemoteSkillSource::MODE_BUNDLE);

    expect($transport->requestedHeaders[0])->each->not->toStartWith('Authorization:');
});

it('attaches `Authorization: Bearer <token>` on api.github.com requests when token is set', function (): void {
    $url = 'https://api.github.com/repos/a/b/releases/tags/v1.0';
    $transport = (new FakeHttpTransport())
        ->expect($url, fakeResponse(200, '{"tag_name":"v1.0"}', $url));

    (new GitHubFetcher($transport, token: 'ghp_test'))->resolveRef('a/b', 'v1.0', RemoteSkillSource::MODE_BUNDLE);

    $sawAuth = false;
    foreach ($transport->requestedHeaders[0] as $header) {
        if ($header === 'Authorization: Bearer ghp_test') {
            $sawAuth = true;
        }
    }

    expect($sawAuth)->toBeTrue();
});
