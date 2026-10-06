<?php

declare(strict_types=1);

namespace Kaaljyoti\Http;

use CurlHandle;

/**
 * The default client, on `ext-curl`.
 *
 * `ext-curl` is in every PHP distribution worth shipping on and needs nothing
 * from Composer, which is what lets this package have no runtime dependencies
 * at all (design decision 1). A host that has disabled the extension — a few
 * shared hosts do — passes a {@see Psr18Client} or its own {@see HttpClient}
 * instead, and nothing else about the SDK changes.
 *
 * One handle per request, deliberately: a persistent handle would have to be
 * guarded against being used from two requests at once, and the SDK's own
 * latency is dominated by the calculation at the far end rather than by a TLS
 * handshake.
 */
final class CurlClient implements HttpClient
{
    /**
     * @param array<int, mixed> $options Extra `CURLOPT_*` to set on every handle — a proxy, a CA bundle, an
     *     interface to bind to. Applied first, so nothing here can replace the URL, the headers, the body or
     *     the deadline that the transport decided.
     */
    public function __construct(private readonly array $options = [])
    {
    }

    /**
     * Sends the request with cURL.
     *
     * @throws TransportException on any cURL failure, with `timedOut` set when
     *     the deadline is what ended it. cURL reports both as the same kind of
     *     error, so the code is what tells them apart.
     */
    public function send(HttpRequest $request): HttpResponse
    {
        $handle = curl_init();
        if (!$handle instanceof CurlHandle) {
            throw new TransportException('curl_init() failed');
        }

        try {
            $this->configure($handle, $request);

            $raw = curl_exec($handle);
            if (!is_string($raw)) {
                $errno = curl_errno($handle);
                $reason = curl_error($handle);

                throw new TransportException(
                    $reason !== '' ? $reason : 'the request failed',
                    // 28 is `CURLE_OPERATION_TIMEDOUT`. The constant is only
                    // defined when the extension is loaded, and this branch is
                    // reached with it loaded, so the constant is safe here.
                    timedOut: $errno === CURLE_OPERATION_TIMEDOUT,
                );
            }

            // The two blocks came back in one string; `CURLINFO_HEADER_SIZE` is
            // where the body starts.
            $headerSize = curl_getinfo($handle, CURLINFO_HEADER_SIZE);

            return new HttpResponse(
                status: curl_getinfo($handle, CURLINFO_RESPONSE_CODE),
                headers: self::parseHeaders(substr($raw, 0, $headerSize)),
                body: substr($raw, $headerSize),
            );
        } finally {
            curl_close($handle);
        }
    }

    /**
     * Header lines to the map the rest of the SDK reads.
     *
     * Names are lower-cased because HTTP header names are case-insensitive and
     * the gateway, Cloudflare and a corporate proxy each have their own
     * preferred spelling of `X-KJ-Request-Id`.
     *
     * A response can carry several header blocks — a `100 Continue`, a
     * redirect, a `CONNECT` through a proxy — and only the last one describes
     * the answer, so a fresh status line starts the map over.
     *
     * @param string $raw The raw header block(s), CRLF-separated, as cURL wrote them.
     * @return array<string, string>
     */
    public static function parseHeaders(string $raw): array
    {
        $headers = [];
        foreach (preg_split('/\r\n|\n/', $raw) ?: [] as $line) {
            if (str_starts_with($line, 'HTTP/')) {
                $headers = [];
                continue;
            }
            $colon = strpos($line, ':');
            if ($colon === false) {
                continue;
            }
            $name = strtolower(trim(substr($line, 0, $colon)));
            if ($name === '') {
                continue;
            }
            $headers[$name] = trim(substr($line, $colon + 1));
        }

        return $headers;
    }

    /** Puts the request on the handle, the caller's own options first. */
    private function configure(CurlHandle $handle, HttpRequest $request): void
    {
        // A client is handed a request the transport built, so neither can be
        // empty in practice; cURL would turn an empty URL into a confusing
        // "unsupported protocol" three frames later, and an empty verb into a
        // GET that quietly dropped the body.
        if ($request->url === '' || $request->method === '') {
            throw new TransportException('a request needs a method and a URL');
        }

        foreach ($this->options as $option => $value) {
            curl_setopt($handle, $option, $value);
        }

        $headers = [];
        foreach ($request->headers as $name => $value) {
            $headers[] = $name . ': ' . $value;
        }
        // cURL adds `Expect: 100-continue` to a body over a kilobyte and then
        // waits a second for a gateway that will never send it. A batch body
        // is over a kilobyte.
        $headers[] = 'Expect:';

        curl_setopt_array($handle, [
            CURLOPT_URL => $request->url,
            CURLOPT_CUSTOMREQUEST => $request->method,
            CURLOPT_HTTPHEADER => $headers,
            CURLOPT_RETURNTRANSFER => true,
            // The headers come back in the same string as the body and are cut
            // off it by `CURLINFO_HEADER_SIZE`; a `CURLOPT_HEADERFUNCTION`
            // would be a closure holding the handle, and one of those leaks the
            // handle for the life of the client.
            CURLOPT_HEADER => true,
            CURLOPT_FOLLOWLOCATION => false,
            // Milliseconds, so a sub-second deadline is expressible. Without
            // `CURLOPT_NOSIGNAL` a libcurl built against a resolver that uses
            // `alarm()` rounds the deadline up to whole seconds.
            CURLOPT_TIMEOUT_MS => (int) round($request->timeoutSeconds * 1000),
            CURLOPT_NOSIGNAL => true,
            // The answer is JSON or SVG; asking for compression is free.
            CURLOPT_ENCODING => '',
        ]);

        if ($request->body !== null) {
            curl_setopt($handle, CURLOPT_POSTFIELDS, $request->body);
        }
    }
}
