<?php

declare(strict_types=1);

namespace Kaaljyoti\Tests;

use Kaaljyoti\Http\CurlClient;
use PHPUnit\Framework\TestCase;

/**
 * The part of the default client that can be tested without a socket.
 *
 * Sending is proved by the smoke test, which is the only honest way to prove
 * it: a mocked cURL would be a test of the mock. What is worth pinning down
 * here is the header parser, because every other reader in the SDK trusts it
 * to have lower-cased the names — `X-KJ-Request-Id`, `x-kj-request-id` and
 * `X-Kj-Request-Id` are the same header, and Cloudflare, the gateway and a
 * corporate proxy each spell it their own way.
 */
final class CurlClientTest extends TestCase
{
    /** A real answer from staging, headers only, as cURL hands them over. */
    private const RAW = "HTTP/2 200 \r\n"
        . "date: Tue, 22 Sep 2026 14:49:41 GMT\r\n"
        . "Content-Type: application/json\r\n"
        . "X-KJ-Request-Id: 351f8b2b-fe42-4dca-be90-71ffd9a3638d\r\n"
        . "x-kj-cache: miss\r\n"
        . "X-RateLimit-Remaining: 119\r\n"
        . "Retry-After:   7   \r\n"
        . "\r\n";

    public function testTheNamesComeBackLowerCasedAndTheValuesTrimmed(): void
    {
        $headers = CurlClient::parseHeaders(self::RAW);

        self::assertSame('application/json', $headers['content-type']);
        self::assertSame('351f8b2b-fe42-4dca-be90-71ffd9a3638d', $headers['x-kj-request-id']);
        self::assertSame('miss', $headers['x-kj-cache']);
        self::assertSame('119', $headers['x-ratelimit-remaining']);
        self::assertSame('7', $headers['retry-after']);
    }

    public function testTheStatusLineIsNotAHeader(): void
    {
        $headers = CurlClient::parseHeaders(self::RAW);

        self::assertArrayNotHasKey('http/2 200', $headers);
        self::assertCount(6, $headers);
    }

    public function testAValueMayContainAColonOfItsOwn(): void
    {
        $headers = CurlClient::parseHeaders("HTTP/1.1 200 OK\r\nDate: Tue, 22 Sep 2026 14:49:41 GMT\r\n\r\n");

        self::assertSame('Tue, 22 Sep 2026 14:49:41 GMT', $headers['date']);
    }

    public function testOnlyTheLastBlockDescribesTheAnswer(): void
    {
        // A `100 Continue`, a redirect or a proxy `CONNECT` each leave a block
        // in front of the real one, and only the last one is about the answer.
        $raw = "HTTP/1.1 100 Continue\r\n\r\n"
            . "HTTP/2 429 \r\n"
            . "Retry-After: 3\r\n"
            . "X-KJ-Plan: free\r\n"
            . "\r\n";

        $headers = CurlClient::parseHeaders($raw);

        self::assertSame(['retry-after' => '3', 'x-kj-plan' => 'free'], $headers);
    }

    public function testALineFeedOnlyBlockIsReadTheSameWay(): void
    {
        self::assertSame(
            ['x-kj-plan' => 'growth'],
            CurlClient::parseHeaders("HTTP/2 200 \nX-KJ-Plan: growth\n\n"),
        );
    }

    public function testNonsenseIsSkippedRatherThanGuessedAt(): void
    {
        self::assertSame([], CurlClient::parseHeaders(''));
        self::assertSame([], CurlClient::parseHeaders("HTTP/2 200 \r\ngarbage\r\n: novalue\r\n\r\n"));
    }
}
