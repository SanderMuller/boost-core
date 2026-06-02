<?php declare(strict_types=1);

namespace SanderMuller\BoostCore\Skills\Remote;

use CurlHandle;

/**
 * Production cURL implementation of {@see HttpTransport}.
 *
 * Auto-follows redirects with cURL's default `Authorization`-on-cross-host
 * stripping (CVE mitigation, default since 7.58). The fetcher post-checks
 * the effective-URL host against the allow-list, so a redirect chain
 * that lands outside the GitHub host family is rejected after the fact
 * rather than during cURL's redirect dance. Streams to disk when a
 * destination path is provided — tarballs and `.skill` assets can be
 * MB-scale; never collected in memory.
 *
 * @internal
 */
final class CurlHttpTransport implements HttpTransport
{
    private const TIMEOUT_SECONDS = 30;

    private const MAX_REDIRECTS = 5;

    public function get(string $url, array $headers, ?string $destinationPath = null): HttpResponse
    {
        $ch = curl_init();
        $responseHeaders = [];

        $options = [
            CURLOPT_URL => $url,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_MAXREDIRS => self::MAX_REDIRECTS,
            CURLOPT_TIMEOUT => self::TIMEOUT_SECONDS,
            CURLOPT_CONNECTTIMEOUT => self::TIMEOUT_SECONDS,
            CURLOPT_HTTPHEADER => $headers,
            CURLOPT_USERAGENT => 'boost-core',
            CURLOPT_HEADERFUNCTION => static function (CurlHandle $_ch, string $rawHeader) use (&$responseHeaders): int {
                $len = strlen($rawHeader);
                $sep = strpos($rawHeader, ':');
                if ($sep !== false) {
                    $name = strtolower(trim(substr($rawHeader, 0, $sep)));
                    $value = trim(substr($rawHeader, $sep + 1));
                    if ($name !== '') {
                        $responseHeaders[$name] = $value;
                    }
                }

                return $len;
            },
        ];

        $fileHandle = null;
        if ($destinationPath !== null) {
            $fileHandle = fopen($destinationPath, 'wb');
            if ($fileHandle === false) {
                throw new RemoteFetchException(
                    sprintf('Cannot open `%s` for writing.', $destinationPath),
                    RemoteFetchException::NETWORK_UNREACHABLE,
                );
            }

            $options[CURLOPT_FILE] = $fileHandle;
        } else {
            $options[CURLOPT_RETURNTRANSFER] = true;
        }

        curl_setopt_array($ch, $options);
        $body = curl_exec($ch);
        $errno = curl_errno($ch);
        $errMessage = curl_error($ch);
        $status = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $effectiveUrl = curl_getinfo($ch, CURLINFO_EFFECTIVE_URL);
        // PHP 8+ — CurlHandle objects auto-close on GC; explicit curl_close() is deprecated.
        unset($ch);

        if (is_resource($fileHandle)) {
            fclose($fileHandle);
        }

        if ($errno !== 0) {
            throw new RemoteFetchException(
                sprintf('cURL error (%d): %s', $errno, $errMessage),
                RemoteFetchException::NETWORK_UNREACHABLE,
            );
        }

        return new HttpResponse(
            status: $status,
            body: is_string($body) ? $body : '',
            headers: $responseHeaders,
            effectiveUrl: $effectiveUrl,
        );
    }
}
