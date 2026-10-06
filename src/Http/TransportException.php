<?php

declare(strict_types=1);

namespace Kaaljyoti\Http;

use RuntimeException;
use Throwable;

/**
 * The request never got an answer.
 *
 * This is the one thing an {@see HttpClient} may throw, and the one thing it
 * *must* throw when the socket died: a client that invented a `HttpResponse`
 * with a status of `0` or `500` would make "the network is down" look like
 * "the gateway refused you", and the transport would then retry it on the
 * wrong budget and report the wrong code to the caller.
 *
 * It never leaves this package. {@see \Kaaljyoti\Transport} catches it and
 * rethrows a {@see \Kaaljyoti\KaaljyotiException} with `timeout` or
 * `network_error`, so an application has one exception type to catch.
 */
class TransportException extends RuntimeException
{
    /**
     * @param string $message Why the request failed. Must not contain the URL: for a publishable key the URL
     *     holds the key, and an exception message is the thing that ends up in a log.
     * @param bool $timedOut Whether the deadline is what killed it, rather than a refused or dropped
     *     connection. This is the only signal the transport has for telling `timeout` from `network_error`.
     * @param Throwable|null $previous The client library's own exception, kept for a stack trace.
     */
    public function __construct(
        string $message,
        public readonly bool $timedOut = false,
        ?Throwable $previous = null,
    ) {
        parent::__construct($message, 0, $previous);
    }
}
