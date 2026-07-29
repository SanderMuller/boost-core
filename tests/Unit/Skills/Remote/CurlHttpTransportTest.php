<?php declare(strict_types=1);

use SanderMuller\BoostCore\Skills\Remote\CurlHttpTransport;
use SanderMuller\BoostCore\Skills\Remote\RemoteFetchException;

it('rejects an empty URL before touching cURL', function (): void {
    expect(fn () => (new CurlHttpTransport())->get('', []))
        ->toThrow(RemoteFetchException::class, 'Cannot fetch an empty URL.');
});

it('reports an empty URL as network-unreachable', function (): void {
    $reason = null;

    try {
        (new CurlHttpTransport())->get('', []);
    } catch (RemoteFetchException $remoteFetchException) {
        $reason = $remoteFetchException->reason;
    }

    expect($reason)->toBe(RemoteFetchException::NETWORK_UNREACHABLE);
});
