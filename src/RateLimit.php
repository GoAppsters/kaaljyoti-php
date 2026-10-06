<?php

declare(strict_types=1);

namespace Kaaljyoti;

/**
 * The rate-limit bucket, as the last answer left it.
 *
 * Every field is nullable because a proxy in front of the gateway may strip
 * the headers, and a missing number must not read as "no requests left".
 */
final readonly class RateLimit
{
    /**
     * @param int|null $limit `X-RateLimit-Limit`: requests a minute for this key.
     * @param int|null $remaining `X-RateLimit-Remaining`: tokens left in the bucket.
     * @param int|null $reset `X-RateLimit-Reset`: unix seconds at which it refills.
     */
    public function __construct(
        public ?int $limit = null,
        public ?int $remaining = null,
        public ?int $reset = null,
    ) {
    }

    /** No headers arrived, therefore nothing is known. Not "nothing left". */
    public static function unknown(): self
    {
        return new self();
    }
}
