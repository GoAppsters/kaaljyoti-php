<?php

declare(strict_types=1);

namespace Kaaljyoti\Http;

/**
 * The one seam between this SDK and the network.
 *
 * The package has no runtime dependencies on purpose (design decision 1): the
 * WordPress plugin bundles it, and a `vendor/` with Guzzle inside a plugin
 * collides with every other plugin that bundled a different Guzzle. So HTTP is
 * a one-method interface with three implementations — {@see CurlClient} by
 * default, {@see Psr18Client} for a framework that already has a client, and
 * whatever the host gives you (`wp_remote_request`, a Laravel HTTP client, a
 * queue of canned answers in a test).
 *
 * Implementing it is the whole contract:
 *
 * ```php
 * final class WpHttpClient implements HttpClient
 * {
 *     public function send(HttpRequest $request): HttpResponse
 *     {
 *         $answer = wp_remote_request($request->url, [
 *             'method' => $request->method,
 *             'headers' => $request->headers,
 *             'body' => $request->body,
 *             'timeout' => $request->timeoutSeconds,
 *         ]);
 *         if (is_wp_error($answer)) {
 *             throw new TransportException($answer->get_error_message());
 *         }
 *         return new HttpResponse(
 *             wp_remote_retrieve_response_code($answer),
 *             wp_remote_retrieve_headers($answer)->getAll(),
 *             wp_remote_retrieve_body($answer),
 *         );
 *     }
 * }
 * ```
 */
interface HttpClient
{
    /**
     * Sends one request and returns what came back.
     *
     * An implementation does not retry, does not follow the SDK's own rules
     * about keys or headers, and does not interpret the body: the transport
     * owns all of that, so that every client behaves identically.
     *
     * @throws TransportException when there is no answer at all — a refused
     *     connection, DNS, a dropped socket, or `timeoutSeconds` elapsing. It
     *     must never be reported as a response with an invented status: the
     *     transport retries a dead socket on a different budget than a `500`,
     *     and a caller is told `network_error` rather than a made-up refusal.
     */
    public function send(HttpRequest $request): HttpResponse;
}
