<?php

declare(strict_types=1);

namespace Kaaljyoti\Http;

/**
 * One HTTP request, already fully decided.
 *
 * The transport has put the key where the key belongs, chosen the headers and
 * encoded the body before this object exists, so an {@see HttpClient} has
 * nothing left to decide: it sends these bytes to this URL and hands back what
 * came. That is what makes the interface small enough for a WordPress plugin
 * to implement over `wp_remote_request` in twenty lines (design decision 1).
 *
 * Nothing here is ever logged by this package: for a publishable key the URL
 * holds the key, and a logged key is a leaked key.
 */
final readonly class HttpRequest
{
    /**
     * @param string $method The HTTP verb, upper case — `GET` or `POST`.
     * @param string $url The absolute URL, query string included.
     * @param array<string, string> $headers Header names as the transport wrote them, e.g. `X-KJ-Client`.
     * @param string|null $body The encoded body, or null on a request that carries none.
     * @param float $timeoutSeconds The deadline for this one attempt. Fractions are meaningful.
     */
    public function __construct(
        public string $method,
        public string $url,
        public array $headers,
        public ?string $body,
        public float $timeoutSeconds,
    ) {
    }
}
