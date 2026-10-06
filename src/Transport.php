<?php

declare(strict_types=1);

namespace Kaaljyoti;

use Closure;
use JsonException;
use Kaaljyoti\Generated\Version;
use Kaaljyoti\Http\CurlClient;
use Kaaljyoti\Http\HttpClient;
use Kaaljyoti\Http\HttpRequest;
use Kaaljyoti\Http\HttpResponse;
use Kaaljyoti\Http\TransportException;
use TypeError;
use UnexpectedValueException;
use ValueError;

/**
 * The one HTTP call in the package.
 *
 * Every method on {@see Client} is a path, a body and a document type; all the
 * behaviour a caller would otherwise have to reimplement — where the key goes,
 * which failures are worth retrying, what a `Retry-After` means, which headers
 * carry the answer's provenance — happens once, here.
 *
 * Four rules are not negotiable:
 *
 *   1. **The key's prefix decides where it travels.** `kj_pub_…` goes in
 *      `?key=`, because a browser cannot set `Authorization` cross-origin
 *      without a preflight the gateway does not grant; everything else goes in
 *      `Authorization: Bearer`, because the gateway refuses a secret key in a
 *      URL outright and a URL ends up in logs, history and referrers.
 *   2. **The envelope is not hidden.** `meta` is how a user answers "why is
 *      this number what it is", so it comes back with the data.
 *   3. **Retries are bounded and honest.** A 429 waits what it was told to
 *      wait; an `engine_error` and a dead socket each get one more try; a
 *      `400` gets none, because the identical body will be refused
 *      identically.
 *   4. **Nothing escapes as a raw `TypeError` or a cURL number.** A caller
 *      gets a {@see Result} or a {@see KaaljyotiException} with a `code`.
 *
 * Built by {@see Client} from its own options; there is no reason to construct
 * one directly outside a test that needs to replace the retry sleep.
 */
final class Transport
{
    /** Where the API lives when nobody says otherwise. */
    public const DEFAULT_BASE_URL = 'https://api.kaaljyoti.com';

    /** `GET /v1/health` answers bare, not in the envelope. */
    public const HEALTH_PATH = '/v1/health';

    /** `Accept` for every endpoint but the SVG variant of a chart and the PDFs. */
    public const ACCEPT_JSON = 'application/json';

    /** `Accept` that asks `POST /v1/kundli/chart` for the markup itself. */
    public const ACCEPT_SVG = 'image/svg+xml';

    /** `Accept` for the `/v1/pdf/*` routes, whose success is the file itself. */
    public const ACCEPT_PDF = 'application/pdf';

    /** Publishable keys, and only these, may travel in a query string. */
    private const PUBLISHABLE_PREFIX = 'kj_pub_';

    /** A 429 without a usable `Retry-After`; the gateway always sends one. */
    private const DEFAULT_RETRY_SECONDS = 2;

    /** Longest we will sit on a retry. Past this, the caller should decide. */
    private const MAX_RETRY_SECONDS = 30;

    /** The origin every path is appended to, already normalised. */
    public readonly string $baseUrl;

    /** How many extra attempts the retry policy may spend. `0` turns it off. */
    public readonly int $maxRetries;

    /** Read once: a key that is not publishable is a secret key, whatever its prefix. */
    private readonly bool $publishable;

    private readonly HttpClient $httpClient;

    /** @var Closure(float): void */
    private readonly Closure $sleep;

    /**
     * @param string $apiKey `kj_live_…`, `kj_test_…` or `kj_pub_…`. Never logged, never in an exception.
     * @param string|null $baseUrl Default {@see DEFAULT_BASE_URL}; a trailing `/` or `/v1` is trimmed.
     * @param HttpClient|null $httpClient Default a new {@see CurlClient}.
     * @param float $timeoutSeconds The deadline for one attempt, not for the whole call.
     * @param int $maxRetries The retry budget. Negative is read as `0`.
     * @param string $clientTag `X-KJ-Client`. A shell built on this SDK — the WordPress plugin — sets its own
     *     so that usage attributes to it rather than to the SDK.
     * @param array<string, string> $headers Extra headers on every request. Cannot override the key, the
     *     `Accept` or the tag.
     * @param (Closure(float): void)|null $sleep How the transport waits between attempts, in seconds.
     *     Injectable so that a test can assert *what* was waited for without waiting for it.
     */
    public function __construct(
        private readonly string $apiKey,
        ?string $baseUrl = null,
        ?HttpClient $httpClient = null,
        public readonly float $timeoutSeconds = 30.0,
        int $maxRetries = 2,
        public readonly string $clientTag = 'sdk-php/' . Version::SDK,
        private readonly array $headers = [],
        ?Closure $sleep = null,
    ) {
        $this->baseUrl = self::normaliseBaseUrl($baseUrl);
        $this->maxRetries = max(0, $maxRetries);
        $this->publishable = str_starts_with($apiKey, self::PUBLISHABLE_PREFIX);
        $this->httpClient = $httpClient ?? new CurlClient();
        $this->sleep = $sleep ?? static function (float $seconds): void {
            usleep((int) round($seconds * 1_000_000));
        };
    }

    /**
     * `https://api.kaaljyoti.com/v1/` and `https://api.kaaljyoti.com` mean the
     * same thing to a person, so they mean the same thing here. The `/v1` is
     * trimmed too: paths carry their own version prefix, and a base URL that
     * already had one would otherwise produce `/v1/v1/kundli`.
     */
    public static function normaliseBaseUrl(?string $raw): string
    {
        $value = trim($raw ?? self::DEFAULT_BASE_URL);
        if ($value === '') {
            return self::DEFAULT_BASE_URL;
        }
        $value = rtrim($value, '/');
        if (str_ends_with($value, '/v1')) {
            $value = substr($value, 0, -3);
        }

        return rtrim($value, '/');
    }

    /**
     * Sends a JSON body and reads the envelope back.
     *
     * `$decodeData` is handed `data` from the envelope — or, when `$accept` is
     * {@see ACCEPT_SVG} and the call succeeded, the body as a string, and when
     * it is {@see ACCEPT_PDF}, a {@see PdfFile} built from the bytes and the
     * headers. `$decodeMeta` is handed `meta`; pass `null` on the answers that
     * carry none, and `meta` on the result is `null`.
     *
     * @template TData
     * @template TMeta
     * @param string $path A path from the generated operation table, never typed by hand.
     * @param array<string, mixed> $body The request body. An empty array is sent as `{}`, not as `[]`.
     * @param callable(mixed): TData $decodeData
     * @param (callable(mixed): TMeta)|null $decodeMeta
     * @param string $accept {@see ACCEPT_JSON}, {@see ACCEPT_SVG} or {@see ACCEPT_PDF}.
     * @return Result<TData, TMeta>
     * @throws KaaljyotiException for every failure, including the ones that never left the process.
     */
    public function post(
        string $path,
        array $body,
        callable $decodeData,
        ?callable $decodeMeta,
        string $accept = self::ACCEPT_JSON,
    ): Result {
        $this->requireKey();

        // `[]` is a JSON array to `json_encode` and an object to every other
        // language; the API wants the object. The flags keep the bytes as they
        // are: PHP strings are bytes, the API is UTF-8 throughout, and escaping
        // Devanagari to `\uXXXX` would only make a body harder to read in a log.
        $encoded = $body === []
            ? '{}'
            : json_encode($body, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

        return $this->send(
            path: $path,
            method: 'POST',
            url: $this->url($path),
            accept: $accept,
            body: $encoded,
            decodeData: $decodeData,
            decodeMeta: $decodeMeta,
        );
    }

    /**
     * Reads an endpoint that takes its arguments in the query string.
     *
     * A `null` value in `$query` leaves the parameter out entirely, which is
     * not the same as sending it empty.
     *
     * @template TData
     * @template TMeta
     * @param string $path A path from the generated operation table, placeholders already filled in.
     * @param array<string, string|null> $query
     * @param callable(mixed): TData $decodeData
     * @param (callable(mixed): TMeta)|null $decodeMeta
     * @return Result<TData, TMeta>
     * @throws KaaljyotiException for every failure, including the ones that never left the process.
     */
    public function get(string $path, array $query, callable $decodeData, ?callable $decodeMeta): Result
    {
        $this->requireKey();

        return $this->send(
            path: $path,
            method: 'GET',
            url: $this->url($path, $query),
            accept: self::ACCEPT_JSON,
            body: null,
            decodeData: $decodeData,
            decodeMeta: $decodeMeta,
        );
    }

    /**
     * Fails before the network, so an unset key is a stack trace in the
     * caller's own code rather than a 401 a round trip away.
     *
     * @throws KaaljyotiException
     */
    private function requireKey(): void
    {
        if ($this->apiKey === '') {
            throw new KaaljyotiException(
                KaaljyotiException::INVALID_KEY,
                'No API key configured',
                docs: 'https://kaaljyoti.com/api/docs/errors#invalid_key',
            );
        }
    }

    /**
     * The attempt loop: send, decide whether the answer is worth another try,
     * and turn the last one into a {@see Result} or a {@see KaaljyotiException}.
     *
     * @template TData
     * @template TMeta
     * @param callable(mixed): TData $decodeData
     * @param (callable(mixed): TMeta)|null $decodeMeta
     * @return Result<TData, TMeta>
     * @throws KaaljyotiException
     */
    private function send(
        string $path,
        string $method,
        string $url,
        string $accept,
        ?string $body,
        callable $decodeData,
        ?callable $decodeMeta,
    ): Result {
        $request = new HttpRequest(
            method: $method,
            url: $url,
            headers: $this->headersFor($accept, $body !== null),
            body: $body,
            timeoutSeconds: $this->timeoutSeconds,
        );

        // Each reason has its own budget: a 429 is the gateway pacing us and is
        // worth obeying repeatedly, while an `engine_error` or a dead socket is
        // worth exactly one more try before the caller hears about it.
        $rateLimitBudget = $this->maxRetries;
        $engineBudget = min(1, $this->maxRetries);
        $networkBudget = min(1, $this->maxRetries);

        while (true) {
            try {
                $response = $this->httpClient->send($request);
            } catch (TransportException $error) {
                if ($networkBudget > 0) {
                    --$networkBudget;
                    continue;
                }

                throw $error->timedOut
                    ? new KaaljyotiException(
                        KaaljyotiException::TIMEOUT,
                        sprintf('No answer within %s s', $this->timeoutSeconds),
                        previous: $error,
                    )
                    : new KaaljyotiException(
                        KaaljyotiException::NETWORK_ERROR,
                        $error->getMessage(),
                        previous: $error,
                    );
            }

            $requestId = $response->header('X-KJ-Request-Id');

            if ($response->status === 429 && $rateLimitBudget > 0) {
                --$rateLimitBudget;
                ($this->sleep)((float) self::retryDelaySeconds($response->header('Retry-After')));
                continue;
            }

            $rateLimit = new RateLimit(
                limit: self::headerInt($response, 'X-RateLimit-Limit'),
                remaining: self::headerInt($response, 'X-RateLimit-Remaining'),
                reset: self::headerInt($response, 'X-RateLimit-Reset'),
            );
            $plan = $response->header('X-KJ-Plan');
            $cachedHeader = $response->header('X-KJ-Cache') === 'hit';

            // A PDF is bytes, and a PHP string already is bytes: the body goes
            // into the file untouched, never through `json_decode` or a
            // charset conversion. Like the SVG below, only a success is a
            // file; a failure is an envelope.
            if ($response->isOk() && $accept === self::ACCEPT_PDF) {
                $file = new PdfFile(
                    bytes: $response->body,
                    contentType: $response->header('Content-Type') ?? self::ACCEPT_PDF,
                    filename: self::filenameOf($response->header('Content-Disposition')),
                    // There is no `meta` on a PDF to say what it cost.
                    credits: self::headerInt($response, 'X-KJ-Credits'),
                );

                return $this->result(
                    $this->decode($decodeData, $file, $response, $requestId),
                    $decodeMeta,
                    null,
                    $response,
                    $requestId,
                    $plan,
                    $cachedHeader,
                    $rateLimit,
                );
            }

            // A chart asked for as SVG comes back as the document itself — but
            // only when it succeeded. A failure is an envelope whatever
            // `Accept` said.
            if ($response->isOk() && $accept !== self::ACCEPT_JSON) {
                return $this->result(
                    $this->decode($decodeData, $response->body, $response, $requestId),
                    $decodeMeta,
                    null,
                    $response,
                    $requestId,
                    $plan,
                    $cachedHeader,
                    $rateLimit,
                );
            }

            try {
                /** @var mixed $parsed */
                $parsed = json_decode($response->body, true, 512, JSON_THROW_ON_ERROR);
            } catch (JsonException $error) {
                // An HTML error page from a proxy, a truncated body, an empty 502.
                throw new KaaljyotiException(
                    KaaljyotiException::BAD_RESPONSE,
                    'Response was not JSON',
                    status: $response->status,
                    requestId: $requestId,
                    previous: $error,
                );
            }

            /** @var array<string, mixed>|null $envelope */
            $envelope = is_array($parsed) ? $parsed : null;

            if (($envelope['status'] ?? null) === 'error' || !$response->isOk()) {
                $error = self::errorFrom($envelope, $response, $requestId);
                // One more try for our own fault, none for the caller's.
                if ($error->code() === KaaljyotiException::ENGINE_ERROR && $engineBudget > 0) {
                    --$engineBudget;
                    continue;
                }

                throw $error;
            }

            // Health is the one answer with no envelope around it: it must keep
            // working while the service is disabled, so it carries no `meta`
            // and no key was needed to ask.
            if ($path === self::HEALTH_PATH) {
                return $this->result(
                    $this->decode($decodeData, $parsed, $response, $requestId),
                    $decodeMeta,
                    null,
                    $response,
                    $requestId,
                    $plan,
                    $cachedHeader,
                    $rateLimit,
                );
            }

            if ($envelope === null || ($envelope['status'] ?? null) !== 'ok' || !array_key_exists('data', $envelope)) {
                throw new KaaljyotiException(
                    KaaljyotiException::BAD_RESPONSE,
                    'Response was not a Kaal Jyoti envelope',
                    status: $response->status,
                    requestId: $requestId,
                );
            }

            /** @var mixed $metaRaw */
            $metaRaw = $envelope['meta'] ?? null;

            return $this->result(
                $this->decode($decodeData, $envelope['data'], $response, $requestId),
                $decodeMeta,
                $metaRaw,
                $response,
                $requestId,
                $plan,
                // `meta.cached` is the gateway's own word for it; the header is
                // the only signal on the answers that carry no meta.
                (is_array($metaRaw) && ($metaRaw['cached'] ?? null) === true) || $cachedHeader,
                $rateLimit,
            );
        }
    }

    /**
     * Assembles the envelope, decoding `meta` on the way.
     *
     * @template TData
     * @template TMeta
     * @param TData $data
     * @param (callable(mixed): TMeta)|null $decodeMeta
     * @return Result<TData, TMeta>
     * @throws KaaljyotiException
     */
    private function result(
        mixed $data,
        ?callable $decodeMeta,
        mixed $metaRaw,
        HttpResponse $response,
        ?string $requestId,
        ?string $plan,
        bool $cached,
        RateLimit $rateLimit,
    ): Result {
        // `null` is the meta of the answers that carry none — health, an SVG
        // and a PDF — which is exactly what `TMeta` is bound to for those calls; the
        // annotation says so because PHP has no way to prove it.
        /** @var TMeta $meta */
        $meta = $decodeMeta === null ? null : $this->decode($decodeMeta, $metaRaw, $response, $requestId);

        return new Result(
            data: $data,
            meta: $meta,
            requestId: $requestId,
            plan: $plan,
            cached: $cached,
            // What the answer cost, and what is left: the second only ever
            // reaches a secret key.
            credits: self::headerInt($response, 'X-KJ-Credits'),
            creditsRemaining: self::headerInt($response, 'X-KJ-Credits-Remaining'),
            rateLimit: $rateLimit,
        );
    }

    /**
     * Runs a generated `fromArray()` and turns its complaint into a `bad_response`.
     *
     * `Kaaljyoti\Generated\Json` throws an `UnexpectedValueException` naming
     * the field it could not read; a payload the schema did not promise is the
     * API's problem or ours, never an exception the caller has to interpret.
     *
     * @template TValue
     * @param callable(mixed): TValue $decoder
     * @return TValue
     * @throws KaaljyotiException
     */
    private function decode(callable $decoder, mixed $json, HttpResponse $response, ?string $requestId): mixed
    {
        try {
            return $decoder($json);
        } catch (UnexpectedValueException | TypeError | ValueError $error) {
            throw new KaaljyotiException(
                KaaljyotiException::BAD_RESPONSE,
                'Response did not match the schema: ' . $error->getMessage(),
                status: $response->status,
                requestId: $requestId,
                previous: $error,
            );
        }
    }

    /**
     * The absolute URL for a path, with the key appended when it is publishable.
     *
     * @param array<string, string|null> $query
     */
    private function url(string $path, array $query = []): string
    {
        $parameters = [];
        foreach ($query as $name => $value) {
            if ($value !== null) {
                $parameters[$name] = $value;
            }
        }
        if ($this->publishable) {
            $parameters['key'] = $this->apiKey;
        }
        if ($parameters === []) {
            return $this->baseUrl . $path;
        }

        return $this->baseUrl . $path . '?' . http_build_query($parameters, '', '&', PHP_QUERY_RFC3986);
    }

    /**
     * @return array<string, string>
     */
    private function headersFor(string $accept, bool $hasBody): array
    {
        // The caller's headers go on first so that nothing they pass can
        // replace the key, the client tag or the `Accept` this call depends on.
        $headers = $this->headers;
        // Only on a body: a `Content-Type` on a GET buys a CORS preflight in a
        // browser and says nothing true about the request.
        if ($hasBody) {
            $headers['Content-Type'] = self::ACCEPT_JSON;
        }
        $headers['Accept'] = $accept;
        $headers['X-KJ-Client'] = $this->clientTag;
        if (!$this->publishable) {
            $headers['Authorization'] = 'Bearer ' . $this->apiKey;
        }

        return $headers;
    }

    /**
     * The file name in a `Content-Disposition`, or null.
     *
     * The RFC 6266 `filename*=UTF-8''…` form wins over the plain one when both
     * are there, because it is the one that can carry a name in Devanagari.
     */
    public static function filenameOf(?string $header): ?string
    {
        if ($header === null) {
            return null;
        }
        if (preg_match("/filename\\*\\s*=\\s*utf-8''([^;]+)/i", $header, $extended) === 1) {
            $decoded = rawurldecode(trim($extended[1]));
            // A malformed escape decodes to bytes that are not UTF-8: fall
            // through to the plain parameter rather than answer mojibake.
            if ($decoded !== '' && preg_match('//u', $decoded) === 1) {
                return $decoded;
            }
        }
        if (preg_match('/filename\\s*=\\s*(?:"([^"]*)"|([^;\\s]+))/', $header, $plain) !== 1) {
            return null;
        }
        // Group 1 is the quoted form, group 2 the bare one; only one matched.
        $name = $plain[1] !== '' ? $plain[1] : ($plain[2] ?? '');

        return $name === '' ? null : $name;
    }

    /** Seconds to wait, from a `Retry-After` header that may be anything. */
    private static function retryDelaySeconds(?string $header): int
    {
        $seconds = $header === null ? false : filter_var(trim($header), FILTER_VALIDATE_INT);
        if (!is_int($seconds) || $seconds <= 0) {
            return self::DEFAULT_RETRY_SECONDS;
        }

        return min($seconds, self::MAX_RETRY_SECONDS);
    }

    /** A header as an integer, or null when it was absent or unreadable. */
    private static function headerInt(HttpResponse $response, string $name): ?int
    {
        $raw = $response->header($name);
        if ($raw === null) {
            return null;
        }
        $value = filter_var(trim($raw), FILTER_VALIDATE_INT);

        return is_int($value) ? $value : null;
    }

    /**
     * The gateway's own error, with what the headers add to it.
     *
     * @param array<string, mixed>|null $envelope
     */
    private static function errorFrom(?array $envelope, HttpResponse $response, ?string $requestId): KaaljyotiException
    {
        /** @var mixed $detail */
        $detail = $envelope['error'] ?? null;
        $body = is_array($detail) ? $detail : [];
        /** @var mixed $code */
        $code = $body['code'] ?? null;
        /** @var mixed $message */
        $message = $body['message'] ?? null;
        /** @var mixed $field */
        $field = $body['field'] ?? null;
        /** @var mixed $docs */
        $docs = $body['docs'] ?? null;
        $retryAfter = $response->header('Retry-After');

        return new KaaljyotiException(
            is_string($code) ? $code : KaaljyotiException::BAD_RESPONSE,
            is_string($message) ? $message : 'Request failed',
            status: $response->status,
            field: is_string($field) ? $field : null,
            docs: is_string($docs) ? $docs : null,
            requestId: $requestId,
            retryAfter: $retryAfter === null ? null : self::retryDelaySeconds($retryAfter),
        );
    }
}
