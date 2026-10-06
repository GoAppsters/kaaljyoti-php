<?php

declare(strict_types=1);

namespace Kaaljyoti\Tests\Support;

use Closure;
use Kaaljyoti\Http\HttpClient;
use Kaaljyoti\Http\HttpRequest;
use Kaaljyoti\Http\HttpResponse;
use Kaaljyoti\Http\TransportException;
use LogicException;

/**
 * The socket, replaced by a list.
 *
 * Every test in this package that is not the smoke test runs against one of
 * these: answers are queued in the order they should arrive, each `send()`
 * takes the next one, and what went out is kept for the assertions. A queued
 * {@see TransportException} is a dead socket — the one thing an
 * {@see HttpClient} may throw — so the retry budgets can be exercised without
 * unplugging anything.
 *
 * The retry sleep is here too, as {@see sleeper()}: the transport takes the
 * waiting as a closure, so a test can assert *what* was waited for in the time
 * it takes to append to an array.
 */
final class RecordingHttpClient implements HttpClient
{
    /**
     * Every request that was sent, in order.
     *
     * @var list<HttpRequest>
     */
    public array $requests = [];

    /**
     * Every wait the transport asked for, in seconds, in order.
     *
     * @var list<float>
     */
    public array $sleeps = [];

    /** @var list<HttpResponse|TransportException> */
    private array $queue;

    /**
     * @param HttpResponse|TransportException ...$answers What the socket will do, in order.
     */
    public function __construct(HttpResponse|TransportException ...$answers)
    {
        $this->queue = array_values($answers);
    }

    /** An `ok` envelope, with `data` and `meta` as given. */
    public static function envelope(
        mixed $data,
        mixed $meta = null,
        int $status = 200,
        string ...$headers,
    ): HttpResponse {
        $body = ['status' => 'ok', 'data' => $data];
        if ($meta !== null) {
            $body['meta'] = $meta;
        }

        return self::json($status, json_encode($body, JSON_THROW_ON_ERROR), ...$headers);
    }

    /**
     * A response with a JSON content type and any extra headers, given as
     * `'name: value'` strings so a test reads like the wire.
     */
    public static function json(int $status, string $body, string ...$headers): HttpResponse
    {
        $map = ['content-type' => 'application/json'];
        foreach ($headers as $header) {
            $colon = strpos($header, ':');
            if ($colon === false) {
                throw new LogicException("header \"{$header}\" is not name: value");
            }
            $map[strtolower(trim(substr($header, 0, $colon)))] = trim(substr($header, $colon + 1));
        }

        return new HttpResponse($status, $map, $body);
    }

    /** The gateway's failure shape, as `tests/fixtures/error.json` records it. */
    public static function failure(
        int $status,
        string $code,
        string $message = 'Request failed',
        string ...$headers,
    ): HttpResponse {
        return self::json(
            $status,
            json_encode(
                ['status' => 'error', 'error' => ['code' => $code, 'message' => $message]],
                JSON_THROW_ON_ERROR,
            ),
            ...$headers,
        );
    }

    /**
     * The transport's `sleep`, recording instead of waiting.
     *
     * @return Closure(float): void
     */
    public function sleeper(): Closure
    {
        return function (float $seconds): void {
            $this->sleeps[] = $seconds;
        };
    }

    /** The last request sent, for a test that only cares about one. */
    public function last(): HttpRequest
    {
        $last = end($this->requests);
        if ($last === false) {
            throw new LogicException('nothing was sent');
        }

        return $last;
    }

    public function send(HttpRequest $request): HttpResponse
    {
        $this->requests[] = $request;
        $next = array_shift($this->queue);
        if ($next === null) {
            // A test that sent more requests than it queued answers for is a
            // test whose retry budget is wrong, and saying so beats a
            // `TypeError` on the next line.
            throw new LogicException(sprintf(
                'the queue ran out: %s %s was request %d',
                $request->method,
                $request->url,
                count($this->requests),
            ));
        }
        if ($next instanceof TransportException) {
            throw $next;
        }

        return $next;
    }
}
