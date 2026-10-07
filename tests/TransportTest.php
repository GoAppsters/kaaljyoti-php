<?php

declare(strict_types=1);

namespace Kaaljyoti\Tests;

use Closure;
use Kaaljyoti\Generated\Json;
use Kaaljyoti\Generated\Version;
use Kaaljyoti\Http\HttpResponse;
use Kaaljyoti\Http\TransportException;
use Kaaljyoti\KaaljyotiException;
use Kaaljyoti\PdfFile;
use Kaaljyoti\Tests\Support\RecordingHttpClient;
use Kaaljyoti\Transport;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

/**
 * The transport against a socket that does what the test says.
 *
 * Everything the SDK promises about a request — where the key goes, which
 * headers are sent, what a `Retry-After` means, what a dead socket becomes —
 * is decided here and nowhere else, so this is the file that has to be
 * exhaustive. The client's own tests can then be about naming.
 */
#[Group('transport')]
final class TransportTest extends TestCase
{
    private const SECRET_KEY = 'kj_test_bbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbb';
    private const LIVE_KEY = 'kj_live_aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa';
    private const PUB_KEY = 'kj_pub_ccccccccccccccccccccccccccccccccccccccccccc';

    /** A decoder that hands back whatever the envelope held. */
    private static function raw(): Closure
    {
        return static fn (mixed $json): mixed => $json;
    }

    /**
     * @param array<string, string> $headers
     */
    private static function transport(
        RecordingHttpClient $http,
        string $apiKey = self::SECRET_KEY,
        ?string $baseUrl = null,
        int $maxRetries = 2,
        ?string $clientTag = null,
        array $headers = [],
    ): Transport {
        return new Transport(
            apiKey: $apiKey,
            baseUrl: $baseUrl,
            httpClient: $http,
            timeoutSeconds: 5.0,
            maxRetries: $maxRetries,
            clientTag: $clientTag ?? 'sdk-php/' . Version::SDK,
            headers: $headers,
            sleep: $http->sleeper(),
        );
    }

    /** A successful, minimal envelope. */
    private static function ok(string ...$headers): HttpResponse
    {
        return RecordingHttpClient::envelope(['ok' => true], ['cached' => false], 200, ...$headers);
    }

    // -----------------------------------------------------------------------
    // The key
    // -----------------------------------------------------------------------

    public function testASecretKeyTravelsAsABearerHeaderAndNeverInTheUrl(): void
    {
        $http = new RecordingHttpClient(self::ok());
        self::transport($http)->post('/v1/kundli', ['birth' => []], self::raw(), self::raw());

        $sent = $http->last();
        self::assertSame('Bearer ' . self::SECRET_KEY, $sent->headers['Authorization']);
        self::assertStringNotContainsString(self::SECRET_KEY, $sent->url);
        self::assertStringNotContainsString('key=', $sent->url);
        self::assertSame('https://api.kaaljyoti.com/v1/kundli', $sent->url);
    }

    public function testALiveKeyIsPlacedTheSameWayAsATestKey(): void
    {
        $http = new RecordingHttpClient(self::ok());
        self::transport($http, apiKey: self::LIVE_KEY)->post('/v1/kundli', [], self::raw(), self::raw());

        self::assertSame('Bearer ' . self::LIVE_KEY, $http->last()->headers['Authorization']);
        self::assertStringNotContainsString(self::LIVE_KEY, $http->last()->url);
    }

    public function testAPublishableKeyTravelsInTheQueryAndNeverAsAHeader(): void
    {
        // A browser cannot set `Authorization` cross-origin without a preflight
        // the gateway does not grant, so the publishable key goes in the URL —
        // and only the publishable key, which is why the prefix decides.
        $http = new RecordingHttpClient(self::ok());
        self::transport($http, apiKey: self::PUB_KEY)->post('/v1/kundli', [], self::raw(), self::raw());

        $sent = $http->last();
        self::assertArrayNotHasKey('Authorization', $sent->headers);
        self::assertSame('https://api.kaaljyoti.com/v1/kundli?key=' . self::PUB_KEY, $sent->url);
    }

    public function testAPublishableKeyIsAppendedAfterTheDeclaredParameters(): void
    {
        $http = new RecordingHttpClient(self::ok());
        self::transport($http, apiKey: self::PUB_KEY)->get(
            '/v1/timezone',
            ['lat' => '28.6139', 'lon' => '77.209', 'datetime' => null],
            self::raw(),
            self::raw(),
        );

        // `datetime` was null, so it is not a parameter at all: an empty one
        // would mean "now" to a reader and "the epoch" to a parser.
        self::assertSame(
            'https://api.kaaljyoti.com/v1/timezone?lat=28.6139&lon=77.209&key=' . self::PUB_KEY,
            $http->last()->url,
        );
    }

    public function testAnEmptyKeyIsRefusedBeforeAnythingIsSent(): void
    {
        $http = new RecordingHttpClient(self::ok());

        try {
            self::transport($http, apiKey: '')->post('/v1/kundli', [], self::raw(), self::raw());
            self::fail('an empty key should not reach the network');
        } catch (KaaljyotiException $error) {
            self::assertSame(KaaljyotiException::INVALID_KEY, $error->code());
            self::assertSame(0, $error->status);
            self::assertFalse($error->isRetryable());
            self::assertSame('https://kaaljyoti.com/api/docs/errors#invalid_key', $error->docs);
        }

        self::assertSame([], $http->requests, 'nothing should have been sent');
    }

    // -----------------------------------------------------------------------
    // The headers
    // -----------------------------------------------------------------------

    public function testTheHeadersNameTheClientAndTheContentType(): void
    {
        $http = new RecordingHttpClient(self::ok());
        self::transport($http)->post('/v1/kundli', [], self::raw(), self::raw());

        $sent = $http->last();
        self::assertSame('application/json', $sent->headers['Content-Type']);
        self::assertSame('application/json', $sent->headers['Accept']);
        self::assertSame('sdk-php/' . Version::SDK, $sent->headers['X-KJ-Client']);
        self::assertSame(5.0, $sent->timeoutSeconds);
    }

    public function testAGetCarriesNoContentTypeAndNoBody(): void
    {
        // A `Content-Type` on a GET buys a CORS preflight in a browser and says
        // nothing true about the request.
        $http = new RecordingHttpClient(self::ok());
        self::transport($http)->get('/v1/health', [], self::raw(), null);

        self::assertArrayNotHasKey('Content-Type', $http->last()->headers);
        self::assertNull($http->last()->body);
        self::assertSame('GET', $http->last()->method);
    }

    public function testAShellCanClaimTheUsageWithItsOwnTag(): void
    {
        $http = new RecordingHttpClient(self::ok());
        self::transport($http, clientTag: 'wordpress/1.2.0')->post('/v1/kundli', [], self::raw(), self::raw());

        self::assertSame('wordpress/1.2.0', $http->last()->headers['X-KJ-Client']);
    }

    public function testExtraHeadersAreMergedButCannotReplaceTheKeyOrTheTag(): void
    {
        $http = new RecordingHttpClient(self::ok());
        self::transport($http, headers: [
            'Origin' => 'https://example.com',
            'X-KJ-Client' => 'impostor/9',
            'Authorization' => 'Bearer not-the-key',
            'Accept' => 'text/csv',
        ])->post('/v1/kundli', [], self::raw(), self::raw());

        $sent = $http->last();
        self::assertSame('https://example.com', $sent->headers['Origin']);
        self::assertSame('sdk-php/' . Version::SDK, $sent->headers['X-KJ-Client']);
        self::assertSame('Bearer ' . self::SECRET_KEY, $sent->headers['Authorization']);
        self::assertSame('application/json', $sent->headers['Accept']);
    }

    // -----------------------------------------------------------------------
    // The base URL
    // -----------------------------------------------------------------------

    public function testTheBaseUrlMeansTheSameThingHoweverItWasWritten(): void
    {
        self::assertSame('https://api.kaaljyoti.com', Transport::normaliseBaseUrl(null));
        self::assertSame('https://api.kaaljyoti.com', Transport::normaliseBaseUrl(''));
        self::assertSame('https://api.kaaljyoti.com', Transport::normaliseBaseUrl('  '));
        self::assertSame('https://example.test', Transport::normaliseBaseUrl('https://example.test/'));
        self::assertSame('https://example.test', Transport::normaliseBaseUrl('https://example.test///'));
        self::assertSame('https://example.test', Transport::normaliseBaseUrl('https://example.test/v1'));
        self::assertSame('https://example.test', Transport::normaliseBaseUrl('https://example.test/v1/'));
        self::assertSame('https://example.test', Transport::normaliseBaseUrl(' https://example.test/v1/ '));
    }

    public function testTheNormalisedBaseUrlIsTheOriginTheRequestGoesTo(): void
    {
        $http = new RecordingHttpClient(self::ok());
        self::transport($http, baseUrl: 'https://api-staging.kaaljyoti.com/v1/')
            ->post('/v1/kundli', [], self::raw(), self::raw());

        self::assertSame('https://api-staging.kaaljyoti.com/v1/kundli', $http->last()->url);
    }

    public function testAnEmptyBodyIsSentAsAnObject(): void
    {
        // `json_encode([])` is `[]`, and the gateway wants `{}`.
        $http = new RecordingHttpClient(self::ok());
        self::transport($http)->post('/v1/kundli', [], self::raw(), self::raw());

        self::assertSame('{}', $http->last()->body);
    }

    public function testTheBodyKeepsUtf8AsItStands(): void
    {
        $http = new RecordingHttpClient(self::ok());
        self::transport($http)->post('/v1/kundli', ['place' => 'नई दिल्ली'], self::raw(), self::raw());

        self::assertSame('{"place":"नई दिल्ली"}', $http->last()->body);
    }

    // -----------------------------------------------------------------------
    // A successful answer
    // -----------------------------------------------------------------------

    public function testTheEnvelopeAndTheHeadersComeBackTogether(): void
    {
        $http = new RecordingHttpClient(RecordingHttpClient::envelope(
            ['ascendant' => 99.04],
            ['cached' => false, 'engine' => '0.2.0'],
            200,
            'X-KJ-Request-Id: req-1234',
            'X-KJ-Plan: growth',
            'X-KJ-Credits: 1',
            'X-KJ-Credits-Remaining: 199412',
            'X-RateLimit-Limit: 120',
            'X-RateLimit-Remaining: 119',
            'X-RateLimit-Reset: 1790000000',
        ));

        $answer = self::transport($http)->post('/v1/kundli', [], self::raw(), self::raw());

        self::assertSame(['ascendant' => 99.04], $answer->data);
        self::assertSame(['cached' => false, 'engine' => '0.2.0'], $answer->meta);
        self::assertSame('req-1234', $answer->requestId);
        self::assertSame('growth', $answer->plan);
        self::assertFalse($answer->cached);
        self::assertSame(1, $answer->credits);
        self::assertSame(199412, $answer->creditsRemaining);
        self::assertSame(120, $answer->rateLimit->limit);
        self::assertSame(119, $answer->rateLimit->remaining);
        self::assertSame(1790000000, $answer->rateLimit->reset);
    }

    public function testACacheHitIsReadFromEitherTheMetaOrTheHeader(): void
    {
        $fromMeta = new RecordingHttpClient(RecordingHttpClient::envelope([], ['cached' => true]));
        self::assertTrue(self::transport($fromMeta)->post('/v1/kundli', [], self::raw(), self::raw())->cached);

        $fromHeader = new RecordingHttpClient(
            RecordingHttpClient::envelope([], ['cached' => false], 200, 'X-KJ-Cache: hit'),
        );
        self::assertTrue(self::transport($fromHeader)->post('/v1/kundli', [], self::raw(), self::raw())->cached);

        $neither = new RecordingHttpClient(
            RecordingHttpClient::envelope([], ['cached' => false], 200, 'X-KJ-Cache: miss'),
        );
        self::assertFalse(self::transport($neither)->post('/v1/kundli', [], self::raw(), self::raw())->cached);
    }

    public function testTheRateLimitIsUnknownRatherThanZeroWhenNoHeadersArrived(): void
    {
        // A proxy that strips the headers must not read as "no requests left".
        $http = new RecordingHttpClient(RecordingHttpClient::envelope([], []));
        $answer = self::transport($http)->post('/v1/kundli', [], self::raw(), self::raw());

        self::assertNull($answer->rateLimit->limit);
        self::assertNull($answer->rateLimit->remaining);
        self::assertNull($answer->rateLimit->reset);
        self::assertNull($answer->requestId);
        self::assertNull($answer->plan);
        self::assertNull($answer->credits);
        self::assertNull($answer->creditsRemaining);
    }

    public function testAPublishableKeyIsToldTheCostButNotWhatIsLeft(): void
    {
        // The gateway never sends `X-KJ-Credits-Remaining` to a `kj_pub_…` key.
        $http = new RecordingHttpClient(RecordingHttpClient::envelope([], [], 200, 'X-KJ-Credits: 1'));
        $answer = self::transport($http, apiKey: self::PUB_KEY)->post('/v1/kundli', [], self::raw(), self::raw());

        self::assertSame(1, $answer->credits);
        self::assertNull($answer->creditsRemaining);
    }

    public function testAnUnreadableRateLimitHeaderIsUnknownToo(): void
    {
        $http = new RecordingHttpClient(
            RecordingHttpClient::envelope([], [], 200, 'X-RateLimit-Limit: many'),
        );

        self::assertNull(self::transport($http)->post('/v1/kundli', [], self::raw(), self::raw())->rateLimit->limit);
    }

    // -----------------------------------------------------------------------
    // Failures
    // -----------------------------------------------------------------------

    public function testAnErrorEnvelopeBecomesAnExceptionWithEveryFieldTheGatewayNamed(): void
    {
        $fixture = file_get_contents(__DIR__ . '/fixtures/error.json');
        self::assertIsString($fixture);

        $http = new RecordingHttpClient(RecordingHttpClient::json(
            400,
            $fixture,
            'X-KJ-Request-Id: req-9',
            'X-KJ-Plan: free',
        ));

        try {
            self::transport($http)->post('/v1/kundli', [], self::raw(), self::raw());
            self::fail('a 400 should throw');
        } catch (KaaljyotiException $error) {
            self::assertSame('validation_error', $error->code());
            self::assertSame(400, $error->status);
            self::assertSame('Invalid input', $error->getMessage());
            self::assertSame('options.language', $error->field);
            self::assertSame('https://kaaljyoti.com/api/docs/errors#validation_error', $error->docs);
            self::assertSame('req-9', $error->requestId);
            self::assertNull($error->retryAfter);
            self::assertFalse($error->isRetryable());
            // `Exception::getCode()` is an int and is not ours; `code()` is.
            self::assertSame(0, $error->getCode());
        }

        self::assertCount(1, $http->requests, 'the caller\'s own mistake is never retried');
    }

    public function testAStatusWithoutAnEnvelopeIsStillAnException(): void
    {
        // A 502 from a proxy that did not bother with our envelope.
        $http = new RecordingHttpClient(RecordingHttpClient::json(502, '{"message":"bad gateway"}'));

        $this->expectException(KaaljyotiException::class);
        $this->expectExceptionMessage('Request failed');
        self::transport($http, maxRetries: 0)->post('/v1/kundli', [], self::raw(), self::raw());
    }

    public function testAnAnswerThatIsNotJsonIsABadResponse(): void
    {
        $http = new RecordingHttpClient(
            new HttpResponse(200, ['content-type' => 'text/html'], '<html>maintenance</html>'),
        );

        try {
            self::transport($http)->post('/v1/kundli', [], self::raw(), self::raw());
            self::fail('an HTML page is not an answer');
        } catch (KaaljyotiException $error) {
            self::assertSame(KaaljyotiException::BAD_RESPONSE, $error->code());
            self::assertSame(200, $error->status);
        }
    }

    public function testJsonThatIsNotAnEnvelopeIsABadResponse(): void
    {
        $http = new RecordingHttpClient(RecordingHttpClient::json(200, '{"ascendant": 99.04}'));

        $this->expectException(KaaljyotiException::class);
        $this->expectExceptionMessage('Response was not a Kaal Jyoti envelope');
        self::transport($http)->post('/v1/kundli', [], self::raw(), self::raw());
    }

    public function testADocumentThatDoesNotMatchTheSchemaIsABadResponse(): void
    {
        $http = new RecordingHttpClient(RecordingHttpClient::envelope(['ok' => true], []));

        try {
            self::transport($http)->post(
                '/v1/kundli',
                [],
                // Stands in for a generated `fromArray()`: `Json` throws an
                // `UnexpectedValueException`, and the caller must never see one.
                static fn (mixed $json): string => Json::string($json),
                self::raw(),
            );
            self::fail('a mismatched document should not reach the caller raw');
        } catch (KaaljyotiException $error) {
            self::assertSame(KaaljyotiException::BAD_RESPONSE, $error->code());
            self::assertStringContainsString('Response did not match the schema', $error->getMessage());
        }
    }

    // -----------------------------------------------------------------------
    // Retrying
    // -----------------------------------------------------------------------

    public function testA429WaitsWhatItWasToldToWaitAndStopsAtMaxRetries(): void
    {
        $http = new RecordingHttpClient(
            RecordingHttpClient::failure(429, 'rate_limited', 'Too many requests', 'Retry-After: 3'),
            RecordingHttpClient::failure(429, 'rate_limited', 'Too many requests', 'Retry-After: 5'),
            RecordingHttpClient::failure(429, 'rate_limited', 'Too many requests', 'Retry-After: 7'),
        );

        try {
            self::transport($http, maxRetries: 2)->post('/v1/kundli', [], self::raw(), self::raw());
            self::fail('the budget ran out, so the 429 should surface');
        } catch (KaaljyotiException $error) {
            self::assertSame('rate_limited', $error->code());
            self::assertSame(429, $error->status);
            self::assertSame(7, $error->retryAfter, 'the last header is the one the caller gets');
            self::assertTrue($error->isRetryable());
        }

        self::assertCount(3, $http->requests, 'one attempt plus two retries');
        self::assertSame([3.0, 5.0], $http->sleeps);
    }

    public function testAWaitIsCappedAndFallsBackWhenRetryAfterIsNonsense(): void
    {
        $http = new RecordingHttpClient(
            RecordingHttpClient::failure(429, 'rate_limited', 'slow down', 'Retry-After: 600'),
            RecordingHttpClient::failure(429, 'rate_limited', 'slow down', 'Retry-After: whenever'),
            self::ok(),
        );

        self::transport($http, maxRetries: 2)->post('/v1/kundli', [], self::raw(), self::raw());

        // 30 seconds is as long as the SDK will sit on a retry; 2 is what a
        // missing or unreadable header means.
        self::assertSame([30.0, 2.0], $http->sleeps);
    }

    public function testA429IsNotRetriedAtAllWhenRetryingIsOff(): void
    {
        $http = new RecordingHttpClient(
            RecordingHttpClient::failure(429, 'rate_limited', 'slow down', 'Retry-After: 4'),
        );

        try {
            self::transport($http, maxRetries: 0)->post('/v1/kundli', [], self::raw(), self::raw());
            self::fail('maxRetries: 0 means the first answer is the answer');
        } catch (KaaljyotiException $error) {
            self::assertSame('rate_limited', $error->code());
            self::assertSame(4, $error->retryAfter, 'the caller does the waiting now');
        }

        self::assertCount(1, $http->requests);
        self::assertSame([], $http->sleeps);
    }

    public function testAnEngineErrorGetsExactlyOneMoreTry(): void
    {
        $http = new RecordingHttpClient(
            RecordingHttpClient::failure(500, 'engine_error', 'the engine fell over'),
            self::ok(),
        );

        $answer = self::transport($http)->post('/v1/kundli', [], self::raw(), self::raw());

        self::assertSame(['ok' => true], $answer->data);
        self::assertCount(2, $http->requests);
        self::assertSame([], $http->sleeps, 'our own fault is retried at once, not after a wait');
    }

    public function testASecondEngineErrorIsTheCallersToSeeEvenWithBudgetLeft(): void
    {
        $http = new RecordingHttpClient(
            RecordingHttpClient::failure(500, 'engine_error', 'the engine fell over'),
            RecordingHttpClient::failure(500, 'engine_error', 'the engine fell over'),
        );

        try {
            self::transport($http, maxRetries: 2)->post('/v1/kundli', [], self::raw(), self::raw());
            self::fail('twice in a row is not a transient');
        } catch (KaaljyotiException $error) {
            self::assertSame('engine_error', $error->code());
            self::assertSame(500, $error->status);
            self::assertTrue($error->isRetryable());
        }

        self::assertCount(2, $http->requests);
    }

    public function testADeadSocketIsTriedOnceMoreAndThenIsANetworkError(): void
    {
        $http = new RecordingHttpClient(
            new TransportException('Could not resolve host'),
            new TransportException('Could not resolve host'),
        );

        try {
            self::transport($http)->post('/v1/kundli', [], self::raw(), self::raw());
            self::fail('a dead socket should become a coded exception');
        } catch (KaaljyotiException $error) {
            self::assertSame(KaaljyotiException::NETWORK_ERROR, $error->code());
            self::assertSame(0, $error->status, 'there was no answer to have a status');
            self::assertSame('Could not resolve host', $error->getMessage());
            self::assertTrue($error->isRetryable());
        }

        self::assertCount(2, $http->requests);
    }

    public function testASocketThatComesBackOnTheSecondAttemptIsNotAFailureAtAll(): void
    {
        $http = new RecordingHttpClient(new TransportException('Connection reset by peer'), self::ok());

        $answer = self::transport($http)->post('/v1/kundli', [], self::raw(), self::raw());

        self::assertSame(['ok' => true], $answer->data);
        self::assertCount(2, $http->requests);
    }

    public function testADeadlineIsItsOwnCodeAndNamesTheBudgetItSpent(): void
    {
        $http = new RecordingHttpClient(
            new TransportException('Operation timed out', timedOut: true),
            new TransportException('Operation timed out', timedOut: true),
        );

        try {
            self::transport($http)->post('/v1/kundli', [], self::raw(), self::raw());
            self::fail('a timeout should become a coded exception');
        } catch (KaaljyotiException $error) {
            self::assertSame(KaaljyotiException::TIMEOUT, $error->code());
            self::assertStringContainsString('5', $error->getMessage());
            self::assertTrue($error->isRetryable());
        }
    }

    public function testADeadSocketIsNotRetriedWhenRetryingIsOff(): void
    {
        $http = new RecordingHttpClient(new TransportException('Connection refused'));

        $this->expectException(KaaljyotiException::class);
        self::transport($http, maxRetries: 0)->post('/v1/kundli', [], self::raw(), self::raw());
    }

    // -----------------------------------------------------------------------
    // The answers that are not envelopes
    // -----------------------------------------------------------------------

    public function testHealthComesBackBareWithNoMeta(): void
    {
        // Health must keep answering while the service is disabled, so it is
        // not wrapped in an envelope and carries no meta.
        $http = new RecordingHttpClient(RecordingHttpClient::json(
            200,
            '{"status":"ok","engine":"0.14.2","ephemeris":"kaaljyoti-ephemeris 0.1.1","ops":42,"supported_range":{"first_date":"1550-04-01","last_date":"2400-12-31","first_year":1551,"last_year":2399},"uptime_s":900}',
        ));

        $answer = self::transport($http)->get(Transport::HEALTH_PATH, [], self::raw(), null);

        self::assertSame('ok', is_array($answer->data) ? $answer->data['status'] : null);
        self::assertNull($answer->meta);
    }

    public function testAnSvgComesBackAsItsOwnMarkup(): void
    {
        $http = new RecordingHttpClient(
            new HttpResponse(
                200,
                ['content-type' => 'image/svg+xml', 'x-kj-credits' => '1'],
                '<svg viewBox="0 0 360 360"></svg>',
            ),
        );

        $answer = self::transport($http)->post(
            '/v1/kundli/chart',
            [],
            self::raw(),
            null,
            Transport::ACCEPT_SVG,
        );

        self::assertSame('<svg viewBox="0 0 360 360"></svg>', $answer->data);
        self::assertNull($answer->meta);
        // No `meta` on markup: the header is the only place the cost is.
        self::assertSame(1, $answer->credits);
        self::assertSame('image/svg+xml', $http->last()->headers['Accept']);
    }

    public function testAnSvgRequestThatFailedIsStillAnEnvelope(): void
    {
        $http = new RecordingHttpClient(RecordingHttpClient::failure(403, 'forbidden_origin', 'not your origin'));

        try {
            self::transport($http)->post('/v1/kundli/chart', [], self::raw(), null, Transport::ACCEPT_SVG);
            self::fail('a refusal is an envelope whatever Accept asked for');
        } catch (KaaljyotiException $error) {
            self::assertSame('forbidden_origin', $error->code());
            self::assertSame(403, $error->status);
        }
    }

    public function testAPdfComesBackAsBytesNeverAsText(): void
    {
        // Every byte value, so a UTF-8 decode anywhere on the way would show.
        $bytes = implode('', array_map('chr', range(0, 255)));
        $http = new RecordingHttpClient(new HttpResponse(200, [
            'content-type' => 'application/pdf',
            'content-disposition' => 'attachment; filename="panchang-2026-10.pdf"',
            'x-kj-credits' => '500',
        ], $bytes));

        $answer = self::transport($http)->post(
            '/v1/pdf/panchang/month',
            [],
            self::raw(),
            null,
            Transport::ACCEPT_PDF,
        );

        self::assertInstanceOf(PdfFile::class, $answer->data);
        self::assertSame($bytes, $answer->data->bytes);
        self::assertSame(256, strlen($answer->data->bytes));
        self::assertSame('application/pdf', $answer->data->contentType);
        self::assertSame('panchang-2026-10.pdf', $answer->data->filename);
        self::assertSame(500, $answer->data->credits);
        self::assertSame(500, $answer->credits);
        self::assertNull($answer->meta);
        self::assertSame('application/pdf', $http->last()->headers['Accept']);
        self::assertSame('application/json', $http->last()->headers['Content-Type']);
    }

    public function testAFailedPdfIsStillAnEnvelopeAndIsRetriedLikeAnyOther(): void
    {
        $http = new RecordingHttpClient(
            RecordingHttpClient::failure(429, 'rate_limited', 'slow down', 'Retry-After: 1'),
            RecordingHttpClient::failure(400, 'validation_error', 'birth is required', 'X-KJ-Request-Id: r-1'),
        );

        try {
            self::transport($http)->post('/v1/pdf/kundli', [], self::raw(), null, Transport::ACCEPT_PDF);
            self::fail('a refusal is an envelope whatever Accept asked for');
        } catch (KaaljyotiException $error) {
            self::assertSame('validation_error', $error->code());
            self::assertSame(400, $error->status);
        }

        self::assertCount(2, $http->requests);
        self::assertSame([1.0], $http->sleeps);
    }

    // -----------------------------------------------------------------------
    // The file name of a PDF
    // -----------------------------------------------------------------------

    public function testTheQuotedFileNameTheGatewaySendsIsRead(): void
    {
        self::assertSame(
            'kundli-ravi-kumar.pdf',
            Transport::filenameOf('attachment; filename="kundli-ravi-kumar.pdf"'),
        );
    }

    public function testAnUnquotedFileNameIsReadToo(): void
    {
        self::assertSame('match-2026.pdf', Transport::filenameOf('attachment; filename=match-2026.pdf'));
    }

    public function testTheRfc6266FormWinsBecauseItCanCarryDevanagari(): void
    {
        self::assertSame(
            'kundli-रवि.pdf',
            Transport::filenameOf(
                "attachment; filename=\"kundli.pdf\"; filename*=UTF-8''kundli-%E0%A4%B0%E0%A4%B5%E0%A4%BF.pdf",
            ),
        );
    }

    public function testNoHeaderOrNoFileNameInItIsNull(): void
    {
        self::assertNull(Transport::filenameOf(null));
        self::assertNull(Transport::filenameOf('attachment'));
        self::assertNull(Transport::filenameOf('attachment; filename=""'));
    }
}
