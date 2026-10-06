<?php

declare(strict_types=1);

namespace Kaaljyoti;

/**
 * A successful call: the envelope, flattened by one level.
 *
 * `meta` comes back with the data rather than a call away because it is how an
 * application answers "why is this number what it is" — which ayanamsa, which
 * zone, which engine version (design decision 4).
 *
 * ```php
 * $answer = $kj->kundli->get($request);
 * $answer->data->lagnaSign->name;      // Cancer
 * $answer->meta->timezone->utcOffset;  // +05:30
 * $answer->cached;                     // true when the 24-hour cache answered
 * ```
 *
 * The two type parameters are what an IDE and PHPStan read: `T` is the
 * document — `KundliDocument`, `DashaDocument`, a `string` of SVG markup, a
 * {@see PdfFile} — and `M` is the `meta` beside it: `Meta` for almost
 * everything, `BatchMeta` for `match.batch`, `ReferenceMeta` for a reference
 * table, `TimezoneMeta` for `timezone()`, and `null` for the answers that
 * carry no meta at all: `health()`, `chartSvg()` and the `pdf` methods.
 *
 * @template T
 * @template M
 */
final readonly class Result
{
    /**
     * @param T $data `data` from the envelope — or the SVG or the PDF itself, when one was asked for.
     * @param M $meta `meta` from the envelope, or null on the answers that carry none.
     * @param string|null $requestId `X-KJ-Request-Id`. Quote it to support and they can find the call.
     * @param string|null $plan `X-KJ-Plan`: the plan this answer was served under.
     * @param bool $cached Whether the gateway served this from its 24-hour cache. Costs the same credits —
     *     and on a PDF, `X-KJ-Cache: hit`: no PDF from the month's allowance, still its credits.
     * @param int|null $credits `X-KJ-Credits`: what this request cost — the same number as `meta.credits`,
     *     and the only one on an SVG or a PDF. `null` on the answers that are free (health, time zone,
     *     reference tables).
     * @param int|null $creditsRemaining `X-KJ-Credits-Remaining`: credits left this month, credit packs
     *     included. Sent to secret keys only, so always `null` on a publishable key.
     * @param RateLimit $rateLimit The bucket as this answer left it; unknown when the headers were stripped.
     */
    public function __construct(
        public mixed $data,
        public mixed $meta,
        public ?string $requestId = null,
        public ?string $plan = null,
        public bool $cached = false,
        public ?int $credits = null,
        public ?int $creditsRemaining = null,
        public RateLimit $rateLimit = new RateLimit(),
    ) {
    }
}
