<?php declare(strict_types=1);

use SanderMuller\BoostCore\Skills\Remote\RemoteFetchException;
use SanderMuller\BoostCore\Skills\Remote\RemoteSkillSource;
use SanderMuller\BoostCore\Skills\Remote\ResolvedRef;
use SanderMuller\BoostCore\Tests\Doubles\Remote\FakeRemoteFetcher;

it('returns a pre-registered ResolvedRef', function (): void {
    $fetcher = (new FakeRemoteFetcher())
        ->withResolvedRef('peterfox/agent-skills', 'v1.2.0', RemoteSkillSource::MODE_BUNDLE, 'v1.2.0');

    $ref = $fetcher->resolveRef('peterfox/agent-skills', 'v1.2.0', RemoteSkillSource::MODE_BUNDLE);

    expect($ref)->toBeInstanceOf(ResolvedRef::class)
        ->and($ref->resolved)->toBe('v1.2.0');
});

it('throws NOT_FOUND when a ref was not pre-registered', function (): void {
    $fetcher = new FakeRemoteFetcher();

    try {
        $fetcher->resolveRef('a/b', 'v1.0', RemoteSkillSource::MODE_PATH);
        throw new RuntimeException('Expected RemoteFetchException.');
    } catch (RemoteFetchException $remoteFetchException) {
        expect($remoteFetchException->reason)->toBe(RemoteFetchException::NOT_FOUND);
    }
});

it('writes a pre-registered asset body to the destination path', function (): void {
    $fetcher = (new FakeRemoteFetcher())
        ->withAsset('peterfox/agent-skills', 'v1.2.0', 'composer-upgrade.skill', 'BUNDLE_BYTES');

    $dest = sys_get_temp_dir() . '/boost-fake-asset-' . bin2hex(random_bytes(6));
    try {
        $fetcher->fetchAsset('peterfox/agent-skills', new ResolvedRef('v1.2.0', 'v1.2.0'), 'composer-upgrade.skill', $dest);

        expect(file_get_contents($dest))->toBe('BUNDLE_BYTES');
    } finally {
        @unlink($dest);
    }
});

it('throws NOT_FOUND when an asset was not pre-registered', function (): void {
    $fetcher = new FakeRemoteFetcher();

    try {
        $fetcher->fetchAsset('a/b', new ResolvedRef('v1.0', 'v1.0'), 'missing.skill', '/tmp/x');
        throw new RuntimeException('Expected RemoteFetchException.');
    } catch (RemoteFetchException $remoteFetchException) {
        expect($remoteFetchException->reason)->toBe(RemoteFetchException::NOT_FOUND);
    }
});

it('writes a pre-registered tarball body to the destination path', function (): void {
    $fetcher = (new FakeRemoteFetcher())
        ->withTarball('mattpocock/skills', 'abc123', 'TAR_BYTES');

    $dest = sys_get_temp_dir() . '/boost-fake-tar-' . bin2hex(random_bytes(6));
    try {
        $fetcher->fetchTarball('mattpocock/skills', new ResolvedRef('main', 'abc123'), $dest);

        expect(file_get_contents($dest))->toBe('TAR_BYTES');
    } finally {
        @unlink($dest);
    }
});
