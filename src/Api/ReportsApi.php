<?php

declare(strict_types=1);

namespace Kaaljyoti\Api;

use Kaaljyoti\Models\KundliReportDocument;
use Kaaljyoti\Models\KundliRequest;
use Kaaljyoti\Models\LifeAreasDocument;
use Kaaljyoti\Models\Meta;
use Kaaljyoti\Models\ReadingGrahasDocument;
use Kaaljyoti\Models\ReadingHouseLordsDocument;
use Kaaljyoti\Models\ReadingLagnaDocument;
use Kaaljyoti\Models\ReadingNakshatraDocument;
use Kaaljyoti\Models\ReadingYogasDocument;
use Kaaljyoti\Models\ReportKundliRequest;
use Kaaljyoti\Models\ReportLagnaRequest;
use Kaaljyoti\Models\ReportNakshatraRequest;
use Kaaljyoti\Models\VarshphalReadingDocument;
use Kaaljyoti\Models\VarshphalRequest;
use Kaaljyoti\Models\VimshottariReadingDocument;
use Kaaljyoti\Result;

/**
 * `$kj->reports` — written readings, 5 credits each.
 *
 * The lagna and nakshatra readings take either a `birth` (the lagna or the
 * Moon's nakshatra is computed from it) or the answer itself, picked by the
 * caller — a `sign`, a `nakshatra` — for a page that lets a visitor choose.
 * The personal reports — the house lords, the grahas, the yogas, the
 * Vimshottari dashas, the varshphal and the life areas — always take a
 * `birth`. All of them are on every plan. Every text
 * comes back keyed by language, one entry per language in `options.language`,
 * and the closing `disclaimer` follows `options.disclaimer` (`'off'` leaves it
 * out).
 */
final readonly class ReportsApi extends ApiSection
{
    /**
     * `POST /v1/reports/lagna` — the reading for a lagna.
     *
     * Send `birth`, or `sign` (`aries`…`pisces`).
     *
     * @return Result<ReadingLagnaDocument, Meta>
     */
    public function lagna(ReportLagnaRequest $request): Result
    {
        return $this->document('postReportsLagna', $request->toArray(), ReadingLagnaDocument::fromArray(...));
    }

    /**
     * `POST /v1/reports/nakshatra` — the reading for the Moon's nakshatra.
     *
     * Send `birth`, or `nakshatra` (`ashwini`, `purva_phalguni`, …).
     *
     * @return Result<ReadingNakshatraDocument, Meta>
     */
    public function nakshatra(ReportNakshatraRequest $request): Result
    {
        return $this->document(
            'postReportsNakshatra',
            $request->toArray(),
            ReadingNakshatraDocument::fromArray(...),
        );
    }

    /**
     * `POST /v1/reports/house-lords` — each house's lord, where it sits, and a reading.
     *
     * Send `birth`; the body is the same `KundliRequest` as `kundli->get()`.
     * Twelve `HouseLord`s come back in house order, each with the sign on the
     * house, its lord and `inHouse`, the house (whole-sign, from the lagna)
     * the lord sits in.
     *
     * @return Result<ReadingHouseLordsDocument, Meta>
     */
    public function houseLords(KundliRequest $request): Result
    {
        return $this->document(
            'postReportsHouseLords',
            $request->toArray(),
            ReadingHouseLordsDocument::fromArray(...),
        );
    }

    /**
     * `POST /v1/reports/grahas` — each graha's sign and house, and what it says in each.
     *
     * Nine `GrahaReading`s, Sun to Ketu, with `inSign` and `inHouse` readings;
     * the house is whole-sign, from the lagna.
     *
     * @return Result<ReadingGrahasDocument, Meta>
     */
    public function grahas(KundliRequest $request): Result
    {
        return $this->document('postReportsGrahas', $request->toArray(), ReadingGrahasDocument::fromArray(...));
    }

    /**
     * `POST /v1/reports/yogas` — the yogas that form in the chart, each with its reading.
     *
     * One `YogaReading` per yoga: its `code` (`gaja_kesari`), `category`, the
     * grahas that make it (`participants`) and `entry->text`.
     *
     * @return Result<ReadingYogasDocument, Meta>
     */
    public function yogas(KundliRequest $request): Result
    {
        return $this->document('postReportsYogas', $request->toArray(), ReadingYogasDocument::fromArray(...));
    }

    /**
     * `POST /v1/reports/vimshottari` — every mahadasha of the life, read.
     *
     * One `MahadashaReading` per mahadasha: its dates, a `level`
     * (`favourable`, `mixed` or `care`), a text, the houses it acts on and its
     * `antardashas`; `current` marks the one running. `basis` is the
     * reasoning, for you rather than the reader.
     *
     * @return Result<VimshottariReadingDocument, Meta>
     */
    public function vimshottari(KundliRequest $request): Result
    {
        return $this->document(
            'postReportsVimshottari',
            $request->toArray(),
            VimshottariReadingDocument::fromArray(...),
        );
    }

    /**
     * `POST /v1/reports/varshphal` — the year from the birthday in `year`, read.
     *
     * A `summary`, seven `AreaSummary`s (work, money, relationships, health,
     * education, home, travel) and the year's periods (`months`, the mudda
     * dasha, as `VarshphalPeriod`s), by the Tajika rules.
     *
     * @return Result<VarshphalReadingDocument, Meta>
     */
    public function varshphal(VarshphalRequest $request): Result
    {
        return $this->document(
            'postReportsVarshphal',
            $request->toArray(),
            VarshphalReadingDocument::fromArray(...),
        );
    }

    /**
     * `POST /v1/reports/life-areas` — eleven areas of life the chart shows, read.
     *
     * A `summary` naming the `strongest` and `needsCare` areas, and one
     * `LifeArea` per area with its `level` and text.
     *
     * @return Result<LifeAreasDocument, Meta>
     */
    public function lifeAreas(KundliRequest $request): Result
    {
        return $this->document('postReportsLifeAreas', $request->toArray(), LifeAreasDocument::fromArray(...));
    }

    /**
     * `POST /v1/reports/kundli` — several of the reports above for one birth, at once.
     *
     * `parts` names any of `lagna`, `nakshatra`, `life_areas`, `house_lords`,
     * `grahas`, `yogas`, `vimshottari` and `varshphal` (default all eight);
     * each comes back as its own route answers it. Without a `year` the
     * varshphal is the one running now. Priced 5 credits per part
     * (`$answer->meta->credits`).
     *
     * @return Result<KundliReportDocument, Meta>
     */
    public function kundli(ReportKundliRequest $request): Result
    {
        return $this->document('postReportsKundli', $request->toArray(), KundliReportDocument::fromArray(...));
    }
}
