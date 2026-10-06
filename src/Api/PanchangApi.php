<?php

declare(strict_types=1);

namespace Kaaljyoti\Api;

use Kaaljyoti\Models\DailyPanchangDocument;
use Kaaljyoti\Models\Meta;
use Kaaljyoti\Models\MuhurtaDocument;
use Kaaljyoti\Models\MuhurtaRequest;
use Kaaljyoti\Models\PanchangMonthDocument;
use Kaaljyoti\Models\PanchangMonthRequest;
use Kaaljyoti\Models\PanchangRequest;
use Kaaljyoti\Result;

/**
 * `$kj->panchang` — the five limbs, the day's windows, a month of panchang.
 *
 * `daily` and `month` are about a **place and a day**, not a person, so
 * `PanchangRequest` takes the place fields flat, with no `Birth`. `muhurta`
 * takes either.
 */
final readonly class PanchangApi extends ApiSection
{
    /**
     * `POST /v1/panchang` — the five limbs for a place and a day.
     *
     * @return Result<DailyPanchangDocument, Meta>
     */
    public function daily(PanchangRequest $request): Result
    {
        return $this->document('postPanchang', $request->toArray(), DailyPanchangDocument::fromArray(...));
    }

    /**
     * `POST /v1/panchang/muhurta` — the day's windows, choghadiya and hora.
     *
     * Send either `birth` (the answer adds `taraBala` and `chandraBala`) or a place — `latitude`,
     * `longitude`, optional `timezone`/`utcOffset` — with an optional `date`, as for `daily`.
     *
     * @return Result<MuhurtaDocument, Meta>
     */
    public function muhurta(MuhurtaRequest $request): Result
    {
        return $this->document('postPanchangMuhurta', $request->toArray(), MuhurtaDocument::fromArray(...));
    }

    /**
     * `POST /v1/panchang/month` — a month of daily panchangs.
     *
     * 20 credits, 10 a minute.
     *
     * @return Result<PanchangMonthDocument, Meta>
     */
    public function month(PanchangMonthRequest $request): Result
    {
        return $this->document('postPanchangMonth', $request->toArray(), PanchangMonthDocument::fromArray(...));
    }
}
