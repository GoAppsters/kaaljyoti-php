<?php

declare(strict_types=1);

namespace Kaaljyoti;

use RuntimeException;
use Throwable;

/**
 * One exception type for every failure, API-side or not.
 *
 * The docs' rule for callers is "branch on `code`, never on `message`", and
 * that rule is only keepable if every failure has a code — including the ones
 * that never reached the API, where cURL reports an integer and PSR-18 clients
 * each throw something of their own. So a dead socket is `network_error`, an
 * expired deadline is `timeout`, and a proxy's HTML error page is
 * `bad_response`; all of them arrive as this class, with the same fields, as a
 * `validation_error` from the gateway.
 *
 * Nothing here ever carries the API key: a `KaaljyotiException` is the object
 * an application logs, and a logged key is a leaked key.
 *
 * ```php
 * try {
 *     $answer = $kj->kundli->get($request);
 * } catch (KaaljyotiException $error) {
 *     match ($error->code()) {
 *         'validation_error' => $form->reject($error->field),
 *         'quota_exceeded' => $this->askToUpgrade($error->docs),
 *         default => $log->warning($error->code() . ' ' . $error->requestId),
 *     };
 * }
 * ```
 */
final class KaaljyotiException extends RuntimeException
{
    /** The socket never answered — offline, DNS, a refused connection. */
    public const NETWORK_ERROR = 'network_error';

    /** The client's `timeoutSeconds` elapsed before the answer did. */
    public const TIMEOUT = 'timeout';

    /** An answer arrived, but it was not an envelope this SDK can read. */
    public const BAD_RESPONSE = 'bad_response';

    /**
     * No key was configured; nothing was sent.
     *
     * Deliberately the API's own code rather than a new one: an empty key is
     * refused here instead of a round trip away, and a caller should not have
     * to handle two codes for the same mistake.
     */
    public const INVALID_KEY = 'invalid_key';

    /** The gateway's code for "you are going too fast"; obeyed on `Retry-After`. */
    public const RATE_LIMITED = 'rate_limited';

    /** The gateway's code for a calculation that failed inside the engine. */
    public const ENGINE_ERROR = 'engine_error';

    /**
     * Codes worth trying again, and the only ones.
     *
     * `rate_limited` and `engine_error` say "later"; `network_error` and
     * `timeout` say "the question never got an answer". Everything else —
     * `validation_error`, `invalid_key`, `quota_exceeded`, `plan_required`,
     * `not_computable` — will refuse the identical request identically, so a
     * retry only spends the caller's time.
     *
     * @var list<string>
     */
    private const RETRYABLE = [
        self::RATE_LIMITED,
        self::ENGINE_ERROR,
        self::NETWORK_ERROR,
        self::TIMEOUT,
    ];

    /**
     * @param string $errorCode The contract — read it through {@see code()}.
     *
     *     It is not called `$code`, and this is the one wart in the class:
     *     `Exception` already has a `$code`, it is an `int`, and `getCode()`
     *     returns it. Naming ours `$code` would either shadow a property PHP
     *     itself writes or force an `int` on a value the API defines as a
     *     string. So the field is `$errorCode`, `code()` is the accessor every
     *     example uses, and `getCode()` is left at its inherited `0`.
     * @param string $message Human-readable, and free to get clearer between versions.
     * @param int $status The HTTP status, or `0` when the request never got an answer.
     * @param string|null $field Dotted path of the offending request field, e.g. `birth.utc_offset`.
     * @param string|null $docs Link to the errors page for this code.
     * @param string|null $requestId `X-KJ-Request-Id` — the one thing support asks for.
     * @param int|null $retryAfter Seconds the gateway asked us to wait, on a `429`. Exposed rather than only
     *     obeyed, because a caller who set `maxRetries: 0` is doing the waiting themselves.
     * @param Throwable|null $previous The failure underneath, when there was one.
     */
    public function __construct(
        public readonly string $errorCode,
        string $message,
        public readonly int $status = 0,
        public readonly ?string $field = null,
        public readonly ?string $docs = null,
        public readonly ?string $requestId = null,
        public readonly ?int $retryAfter = null,
        ?Throwable $previous = null,
    ) {
        parent::__construct($message, 0, $previous);
    }

    /**
     * The contract. Branch on this, never on the message.
     *
     * One of the API's own codes — `validation_error`, `invalid_key`,
     * `key_revoked`, `quota_exceeded`, `forbidden_origin`, `plan_required`,
     * `not_found`, `not_computable`, `rate_limited`, `engine_error`,
     * `service_disabled` — or one of the three this package adds for failures
     * that never reached it. One `match` handles both kinds.
     */
    public function code(): string
    {
        return $this->errorCode;
    }

    /**
     * Whether sending the identical request again is worth anything.
     *
     * The SDK already retried these within its own budget, so `true` here
     * means the budget ran out, not that nothing was tried. Every endpoint is
     * a pure calculation, which is what makes retrying a POST safe at all.
     */
    public function isRetryable(): bool
    {
        return in_array($this->errorCode, self::RETRYABLE, true);
    }

    /** `code`, the status, the field and the request id — never the key. */
    public function __toString(): string
    {
        $parts = [$this->errorCode];
        if ($this->status !== 0) {
            $parts[] = 'HTTP ' . $this->status;
        }
        if ($this->field !== null) {
            $parts[] = 'field ' . $this->field;
        }
        if ($this->requestId !== null) {
            $parts[] = 'request ' . $this->requestId;
        }

        return 'KaaljyotiException(' . implode(', ', $parts) . '): ' . $this->getMessage();
    }
}
