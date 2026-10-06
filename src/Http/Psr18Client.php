<?php

declare(strict_types=1);

namespace Kaaljyoti\Http;

use Psr\Http\Client\ClientExceptionInterface;
use Psr\Http\Client\ClientInterface;
use Psr\Http\Message\RequestFactoryInterface;
use Psr\Http\Message\StreamFactoryInterface;
use Throwable;

/**
 * An adapter onto any PSR-18 client and PSR-17 factories.
 *
 * An application that already has Guzzle, Symfony's HTTP client or a
 * decorating stack of middleware should use it rather than opening a second
 * connection pool through cURL:
 *
 * ```php
 * $kj = new Client(
 *     apiKey: getenv('KAALJYOTI_API_KEY'),
 *     httpClient: new Psr18Client($psr18, $requestFactory, $streamFactory),
 * );
 * ```
 *
 * **The PSR packages are `require-dev` here, not `require`.** This class is
 * the only file that names them, and PHP autoloads a class the first time it
 * is used, so a project that never mentions `Psr18Client` never loads this
 * file and never needs `psr/http-client` installed. Code that must decide at
 * runtime can ask before constructing one:
 *
 * ```php
 * $http = interface_exists(ClientInterface::class)
 *     ? new Psr18Client($psr18, $requests, $streams)
 *     : new CurlClient();
 * ```
 *
 * The type hints below are the real interfaces rather than a guard inside the
 * constructor, because a guard would only move the failure one line later and
 * would cost every caller the honest type.
 *
 * ## The timeout
 *
 * PSR-18 has no notion of a deadline: it is a property of the client you
 * built, not of a request. So `timeoutSeconds` cannot be honoured here and is
 * ignored — set the timeout on your own client, at or below the SDK's, so that
 * the SDK's retry budget still means something.
 */
final class Psr18Client implements HttpClient
{
    public function __construct(
        private readonly ClientInterface $client,
        private readonly RequestFactoryInterface $requests,
        private readonly StreamFactoryInterface $streams,
    ) {
    }

    /**
     * Sends the request through the PSR-18 client.
     *
     * @throws TransportException for a `ClientExceptionInterface` — which is
     *     what PSR-18 requires a client to throw when there is no answer — and
     *     for anything else the client throws on its way there, because the
     *     transport's contract is that a dead socket is never a response.
     */
    public function send(HttpRequest $request): HttpResponse
    {
        try {
            $psrRequest = $this->requests->createRequest($request->method, $request->url);
            foreach ($request->headers as $name => $value) {
                $psrRequest = $psrRequest->withHeader($name, $value);
            }
            if ($request->body !== null) {
                $psrRequest = $psrRequest->withBody($this->streams->createStream($request->body));
            }

            $response = $this->client->sendRequest($psrRequest);
        } catch (ClientExceptionInterface $error) {
            throw new TransportException(
                $error->getMessage(),
                // PSR-18 does not distinguish a timeout from a refused
                // connection, and every client words it differently. Reading
                // the message would be guessing, so this is reported as a
                // network failure: both are retried on the same budget, and
                // only the code the caller finally sees differs.
                timedOut: false,
                previous: $error,
            );
        } catch (Throwable $error) {
            // A factory that rejects the URL, a stream that cannot be created,
            // a middleware that threw something of its own. None of them is an
            // answer from the API, so none of them may reach the caller as one.
            throw new TransportException($error->getMessage(), previous: $error);
        }

        $headers = [];
        foreach ($response->getHeaders() as $name => $values) {
            $headers[strtolower($name)] = implode(', ', $values);
        }

        return new HttpResponse(
            status: $response->getStatusCode(),
            headers: $headers,
            body: (string) $response->getBody(),
        );
    }
}
