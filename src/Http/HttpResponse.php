<?php

declare(strict_types=1);

namespace Kaaljyoti\Http;

/**
 * One HTTP answer, as far as this package needs to understand it.
 *
 * The body is kept as the bytes that arrived. The API is UTF-8 throughout and
 * PHP strings are bytes, so there is nothing to re-encode (design decision 6);
 * a client that helpfully transcoded a body would turn every Devanagari name
 * into mojibake.
 */
final readonly class HttpResponse
{
    /**
     * @param int $status The HTTP status. A client that never got one throws {@see TransportException} instead.
     * @param array<string, string> $headers Header names lower-cased, because HTTP header names are
     *     case-insensitive and every reader here would otherwise have to try three spellings. A header sent
     *     twice keeps the last value; none of the headers this SDK reads is ever repeated.
     * @param string $body The response body, undecoded.
     */
    public function __construct(
        public int $status,
        public array $headers,
        public string $body,
    ) {
    }

    /**
     * The value of one header, by any spelling, or null when it did not arrive.
     *
     * A proxy in front of the gateway may strip any of them, so every caller
     * here treats a missing header as "not known" rather than as a zero.
     */
    public function header(string $name): ?string
    {
        return $this->headers[strtolower($name)] ?? null;
    }

    /** Whether the status is one of the 2xx the gateway uses for success. */
    public function isOk(): bool
    {
        return $this->status >= 200 && $this->status < 300;
    }
}
