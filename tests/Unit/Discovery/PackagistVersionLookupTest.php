<?php declare(strict_types=1);

use SanderMuller\BoostCore\Discovery\PackagistVersionLookup;
use SanderMuller\BoostCore\Skills\Remote\HttpResponse;
use SanderMuller\BoostCore\Tests\Doubles\Remote\FakeHttpTransport;

it('returns the latest stable version from a Packagist v2 metadata response', function (): void {
    $url = 'https://repo.packagist.org/p2/sandermuller/boost-core.json';
    $body = (string) json_encode([
        'packages' => [
            'sandermuller/boost-core' => [
                ['version' => '0.7.1', 'version_normalized' => '0.7.1.0'],
                ['version' => '0.7.0', 'version_normalized' => '0.7.0.0'],
                ['version' => '0.6.2', 'version_normalized' => '0.6.2.0'],
            ],
        ],
    ]);
    $transport = (new FakeHttpTransport())->expect($url, new HttpResponse(200, $body, [], $url));

    $lookup = new PackagistVersionLookup($transport);

    expect($lookup->latestStable('sandermuller/boost-core'))->toBe('0.7.1');
});

it('skips prerelease versions when picking the latest stable', function (): void {
    $url = 'https://repo.packagist.org/p2/sandermuller/boost-core.json';
    $body = (string) json_encode([
        'packages' => [
            'sandermuller/boost-core' => [
                ['version' => '0.8.0-rc1', 'version_normalized' => '0.8.0.0-RC1'],
                ['version' => 'dev-main', 'version_normalized' => '9999999-dev'],
                ['version' => '0.7.1', 'version_normalized' => '0.7.1.0'],
            ],
        ],
    ]);
    $transport = (new FakeHttpTransport())->expect($url, new HttpResponse(200, $body, [], $url));

    expect((new PackagistVersionLookup($transport))->latestStable('sandermuller/boost-core'))->toBe('0.7.1');
});

it('returns null on non-200 responses', function (): void {
    $url = 'https://repo.packagist.org/p2/sandermuller/never-published.json';
    $transport = (new FakeHttpTransport())->expect($url, new HttpResponse(404, '{}', [], $url));

    expect((new PackagistVersionLookup($transport))->latestStable('sandermuller/never-published'))->toBeNull();
});

it('returns null when the response body is malformed JSON', function (): void {
    $url = 'https://repo.packagist.org/p2/sandermuller/boost-core.json';
    $transport = (new FakeHttpTransport())->expect($url, new HttpResponse(200, 'not json', [], $url));

    expect((new PackagistVersionLookup($transport))->latestStable('sandermuller/boost-core'))->toBeNull();
});

it('returns null when the package key is missing from the packages map', function (): void {
    $url = 'https://repo.packagist.org/p2/sandermuller/boost-core.json';
    $body = (string) json_encode(['packages' => []]);
    $transport = (new FakeHttpTransport())->expect($url, new HttpResponse(200, $body, [], $url));

    expect((new PackagistVersionLookup($transport))->latestStable('sandermuller/boost-core'))->toBeNull();
});

it('returns null when only prerelease versions are published', function (): void {
    $url = 'https://repo.packagist.org/p2/sandermuller/boost-core.json';
    $body = (string) json_encode([
        'packages' => [
            'sandermuller/boost-core' => [
                ['version' => '0.8.0-rc1', 'version_normalized' => '0.8.0.0-RC1'],
                ['version' => '0.8.0-beta', 'version_normalized' => '0.8.0.0-beta'],
            ],
        ],
    ]);
    $transport = (new FakeHttpTransport())->expect($url, new HttpResponse(200, $body, [], $url));

    expect((new PackagistVersionLookup($transport))->latestStable('sandermuller/boost-core'))->toBeNull();
});
