<?php

declare(strict_types=1);

namespace Kaaljyoti\Api;

use Kaaljyoti\Models\Meta;
use Kaaljyoti\Models\VikramSamvatDocument;
use Kaaljyoti\Models\VikramSamvatRequest;
use Kaaljyoti\Result;

/**
 * `$kj->calendar` — the Vikram Samvat calendar.
 */
final readonly class CalendarApi extends ApiSection
{
    /**
     * `POST /v1/calendar/vikram-samvat` — samvat year, maasa, paksha, tithi.
     *
     * @return Result<VikramSamvatDocument, Meta>
     */
    public function vikramSamvat(VikramSamvatRequest $request): Result
    {
        return $this->document(
            'postCalendarVikramSamvat',
            $request->toArray(),
            VikramSamvatDocument::fromArray(...),
        );
    }
}
