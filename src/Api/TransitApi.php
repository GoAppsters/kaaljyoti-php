<?php

declare(strict_types=1);

namespace Kaaljyoti\Api;

use Kaaljyoti\Models\Meta;
use Kaaljyoti\Models\TransitEventsDocument;
use Kaaljyoti\Models\TransitEventsRequest;
use Kaaljyoti\Models\TransitNowDocument;
use Kaaljyoti\Models\TransitNowRequest;
use Kaaljyoti\Models\TransitScanDocument;
use Kaaljyoti\Models\TransitScanRequest;
use Kaaljyoti\Result;

/**
 * `$kj->transit` — the sky now, gochar over a window, and the events of a year.
 */
final readonly class TransitApi extends ApiSection
{
    /**
     * `POST /v1/transit/now` — the sky at an instant.
     *
     * Never cached without an explicit `at`.
     *
     * @return Result<TransitNowDocument, Meta>
     */
    public function now(TransitNowRequest $request): Result
    {
        return $this->document('postTransitNow', $request->toArray(), TransitNowDocument::fromArray(...));
    }

    /**
     * `POST /v1/transit/scan` — gochar events in a window.
     *
     * 20 credits, 10 a minute, closed to publishable keys.
     *
     * @return Result<TransitScanDocument, Meta>
     */
    public function scan(TransitScanRequest $request): Result
    {
        return $this->document('postTransitScan', $request->toArray(), TransitScanDocument::fromArray(...));
    }

    /**
     * `POST /v1/transit/events` — the sign ingresses and stations of a `year`.
     *
     * The same for everyone, so there is no birth: a `year` (or a `from`…`to`
     * window of at most 366 days) and a `timezone` for the local times.
     * `moon`, `nakshatras` and `combustion` add more kinds.
     *
     * 20 credits, 10 a minute.
     *
     * @return Result<TransitEventsDocument, Meta>
     */
    public function events(TransitEventsRequest $request): Result
    {
        return $this->document('postTransitEvents', $request->toArray(), TransitEventsDocument::fromArray(...));
    }
}
