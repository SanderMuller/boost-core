<?php declare(strict_types=1);

namespace SanderMuller\BoostCore\Tests\Doubles\Remote;

use SanderMuller\BoostCore\Skills\Remote\RemoteFetcher;
use SanderMuller\BoostCore\Skills\Remote\RemoteFetchException;
use SanderMuller\BoostCore\Skills\Remote\ResolvedRef;

/**
 * Higher-level test double for {@see RemoteFetcher} — pre-register canned
 * ref resolutions, asset bodies, and tarball bodies; the fetcher returns
 * them. Used by phase 5+ tests that drive `SyncEngine` end-to-end without
 * standing up an HTTP server.
 *
 * For tests that need to exercise `GitHubFetcher`'s URL-building and
 * redirect-handling logic, use {@see FakeHttpTransport} instead.
 */
final class FakeRemoteFetcher implements RemoteFetcher
{
    /** @var array<string, ResolvedRef> */
    private array $refs = [];

    /** @var array<string, string> */
    private array $assets = [];

    /** @var array<string, string> */
    private array $tarballs = [];

    public function withResolvedRef(string $source, string $version, string $mode, string $resolved): self
    {
        $this->refs[$this->refKey($source, $version, $mode)] = new ResolvedRef(requested: $version, resolved: $resolved);

        return $this;
    }

    public function withAsset(string $source, string $resolved, string $assetName, string $bodyBytes): self
    {
        $this->assets[$this->assetKey($source, $resolved, $assetName)] = $bodyBytes;

        return $this;
    }

    public function withTarball(string $source, string $resolved, string $bodyBytes): self
    {
        $this->tarballs[$this->tarballKey($source, $resolved)] = $bodyBytes;

        return $this;
    }

    public function resolveRef(string $source, string $version, string $mode): ResolvedRef
    {
        $key = $this->refKey($source, $version, $mode);
        if (! isset($this->refs[$key])) {
            throw new RemoteFetchException(
                sprintf('FakeRemoteFetcher: no canned ref for `%s` (mode=%s).', $key, $mode),
                RemoteFetchException::NOT_FOUND,
            );
        }

        return $this->refs[$key];
    }

    public function fetchAsset(string $source, ResolvedRef $ref, string $assetName, string $destinationPath): void
    {
        $key = $this->assetKey($source, $ref->resolved, $assetName);
        if (! isset($this->assets[$key])) {
            throw new RemoteFetchException(
                sprintf('FakeRemoteFetcher: no canned asset for `%s`.', $key),
                RemoteFetchException::NOT_FOUND,
            );
        }

        file_put_contents($destinationPath, $this->assets[$key]);
    }

    public function fetchTarball(string $source, ResolvedRef $ref, string $destinationPath): void
    {
        $key = $this->tarballKey($source, $ref->resolved);
        if (! isset($this->tarballs[$key])) {
            throw new RemoteFetchException(
                sprintf('FakeRemoteFetcher: no canned tarball for `%s`.', $key),
                RemoteFetchException::NOT_FOUND,
            );
        }

        file_put_contents($destinationPath, $this->tarballs[$key]);
    }

    private function refKey(string $source, string $version, string $mode): string
    {
        return $source . '@' . $version . ':' . $mode;
    }

    private function assetKey(string $source, string $resolved, string $assetName): string
    {
        return $source . '@' . $resolved . '/' . $assetName;
    }

    private function tarballKey(string $source, string $resolved): string
    {
        return $source . '@' . $resolved;
    }
}
