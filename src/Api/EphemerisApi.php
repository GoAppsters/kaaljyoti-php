<?php

declare(strict_types=1);

namespace Kaaljyoti\Api;

use Kaaljyoti\Models\EphemerisMonthDocument;
use Kaaljyoti\Models\EphemerisMonthRequest;
use Kaaljyoti\Models\Meta;
use Kaaljyoti\Result;

/**
 * `$kj->ephemeris` — a month of graha positions at a place.
 */
final readonly class EphemerisApi extends ApiSection
{
    /**
     * `POST /v1/ephemeris/month` — a month of positions, sidereal and tropical unless `system`
     * narrows it.
     *
     * 20 credits, 10 a minute.
     *
     * @return Result<EphemerisMonthDocument, Meta>
     */
    public function month(EphemerisMonthRequest $request): Result
    {
        return $this->document('postEphemerisMonth', $request->toArray(), EphemerisMonthDocument::fromArray(...));
    }
}
