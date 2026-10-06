<?php

declare(strict_types=1);

namespace Kaaljyoti\Api;

use Kaaljyoti\Generated\Json;
use Kaaljyoti\Generated\Operations;
use Kaaljyoti\Models\AshtakavargaDocument;
use Kaaljyoti\Models\BhavaBalaDocument;
use Kaaljyoti\Models\ChalitDocument;
use Kaaljyoti\Models\ChalitRequest;
use Kaaljyoti\Models\ChartDocument;
use Kaaljyoti\Models\DashaDocument;
use Kaaljyoti\Models\DashaRequest;
use Kaaljyoti\Models\EventsDocument;
use Kaaljyoti\Models\GrahaDrishtiDocument;
use Kaaljyoti\Models\KotaDocument;
use Kaaljyoti\Models\KundliChartRequest;
use Kaaljyoti\Models\KundliDocument;
use Kaaljyoti\Models\KundliRequest;
use Kaaljyoti\Models\MaitriDocument;
use Kaaljyoti\Models\Meta;
use Kaaljyoti\Models\Nakshatra28Document;
use Kaaljyoti\Models\PaceDocument;
use Kaaljyoti\Models\SadeSatiDocument;
use Kaaljyoti\Models\SadeSatiRequest;
use Kaaljyoti\Models\SarvatobhadraDocument;
use Kaaljyoti\Models\ShadbalaDocument;
use Kaaljyoti\Models\SpecialLagnasDocument;
use Kaaljyoti\Models\TripatakiDocument;
use Kaaljyoti\Models\VargasDocument;
use Kaaljyoti\Models\VargasRequest;
use Kaaljyoti\Models\YogasDocument;
use Kaaljyoti\Result;
use Kaaljyoti\Transport;

/**
 * `$kj->kundli` — the birth chart and the nineteen documents read from one.
 *
 * Every method here costs 1 credit, takes one generated request class and
 * answers the envelope whole. `dasha` and `kotaChakra` are never cached.
 */
final readonly class KundliApi extends ApiSection
{
    /**
     * `POST /v1/kundli` — positions, houses and the panchang at birth.
     *
     * @return Result<KundliDocument, Meta>
     */
    public function get(KundliRequest $request): Result
    {
        return $this->document('postKundli', $request->toArray(), KundliDocument::fromArray(...));
    }

    /**
     * `POST /v1/kundli/chart` — the chart document, with the markup in `svg`.
     *
     * Use {@see chartSvg()} to get the markup on its own.
     *
     * @return Result<ChartDocument, Meta>
     */
    public function chart(KundliChartRequest $request): Result
    {
        return $this->document('postKundliChart', $request->toArray(), ChartDocument::fromArray(...));
    }

    /**
     * `POST /v1/kundli/chart` asked for as `image/svg+xml` — the markup itself.
     *
     * The same endpoint and the same call cost as {@see chart()}; only the
     * `Accept` differs, and markup carries no envelope, so `meta` is `null`. A
     * failure is an envelope whatever `Accept` asked for, so this throws the
     * same {@see \Kaaljyoti\KaaljyotiException} as everything else.
     *
     * @return Result<string, null>
     */
    public function chartSvg(KundliChartRequest $request): Result
    {
        return $this->transport->post(
            Operations::byId('postKundliChart')->path,
            $request->toArray(),
            static fn (mixed $json): string => Json::string($json),
            static fn (mixed $json): null => null,
            Transport::ACCEPT_SVG,
        );
    }

    /**
     * `POST /v1/kundli/dasha` — the periods of one system. Never cached.
     *
     * @return Result<DashaDocument, Meta>
     */
    public function dasha(DashaRequest $request): Result
    {
        return $this->document('postKundliDasha', $request->toArray(), DashaDocument::fromArray(...));
    }

    /**
     * `POST /v1/kundli/vargas` — the divisional charts.
     *
     * @return Result<VargasDocument, Meta>
     */
    public function vargas(VargasRequest $request): Result
    {
        return $this->document('postKundliVargas', $request->toArray(), VargasDocument::fromArray(...));
    }

    /**
     * `POST /v1/kundli/chalit` — the bhava chalit cusps.
     *
     * @return Result<ChalitDocument, Meta>
     */
    public function chalit(ChalitRequest $request): Result
    {
        return $this->document('postKundliChalit', $request->toArray(), ChalitDocument::fromArray(...));
    }

    /**
     * `POST /v1/kundli/yogas` — the yogas present, with the parts that make each one.
     *
     * @return Result<YogasDocument, Meta>
     */
    public function yogas(KundliRequest $request): Result
    {
        return $this->document('postKundliYogas', $request->toArray(), YogasDocument::fromArray(...));
    }

    /**
     * `POST /v1/kundli/shadbala` — the six strengths, per planet.
     *
     * @return Result<ShadbalaDocument, Meta>
     */
    public function shadbala(KundliRequest $request): Result
    {
        return $this->document('postKundliShadbala', $request->toArray(), ShadbalaDocument::fromArray(...));
    }

    /**
     * `POST /v1/kundli/bhava-bala` — the house strengths.
     *
     * @return Result<BhavaBalaDocument, Meta>
     */
    public function bhavaBala(KundliRequest $request): Result
    {
        return $this->document('postKundliBhavaBala', $request->toArray(), BhavaBalaDocument::fromArray(...));
    }

    /**
     * `POST /v1/kundli/ashtakavarga` — bhinna and sarva.
     *
     * @return Result<AshtakavargaDocument, Meta>
     */
    public function ashtakavarga(KundliRequest $request): Result
    {
        return $this->document('postKundliAshtakavarga', $request->toArray(), AshtakavargaDocument::fromArray(...));
    }

    /**
     * `POST /v1/kundli/graha-drishti` — the planetary aspects.
     *
     * @return Result<GrahaDrishtiDocument, Meta>
     */
    public function grahaDrishti(KundliRequest $request): Result
    {
        return $this->document('postKundliGrahaDrishti', $request->toArray(), GrahaDrishtiDocument::fromArray(...));
    }

    /**
     * `POST /v1/kundli/maitri` — natural, temporal and compound friendship.
     *
     * @return Result<MaitriDocument, Meta>
     */
    public function maitri(KundliRequest $request): Result
    {
        return $this->document('postKundliMaitri', $request->toArray(), MaitriDocument::fromArray(...));
    }

    /**
     * `POST /v1/kundli/pace` — placement and condition, per planet.
     *
     * @return Result<PaceDocument, Meta>
     */
    public function pace(KundliRequest $request): Result
    {
        return $this->document('postKundliPace', $request->toArray(), PaceDocument::fromArray(...));
    }

    /**
     * `POST /v1/kundli/special-lagnas` — bhava, hora, ghatika and the rest.
     *
     * @return Result<SpecialLagnasDocument, Meta>
     */
    public function specialLagnas(KundliRequest $request): Result
    {
        return $this->document('postKundliSpecialLagnas', $request->toArray(), SpecialLagnasDocument::fromArray(...));
    }

    /**
     * `POST /v1/kundli/tripataki` — the tripataki chakra.
     *
     * @return Result<TripatakiDocument, Meta>
     */
    public function tripataki(KundliRequest $request): Result
    {
        return $this->document('postKundliTripataki', $request->toArray(), TripatakiDocument::fromArray(...));
    }

    /**
     * `POST /v1/kundli/sarvatobhadra` — the sarvatobhadra chakra.
     *
     * @return Result<SarvatobhadraDocument, Meta>
     */
    public function sarvatobhadra(KundliRequest $request): Result
    {
        return $this->document('postKundliSarvatobhadra', $request->toArray(), SarvatobhadraDocument::fromArray(...));
    }

    /**
     * `POST /v1/kundli/nakshatra28` — positions in the 28-nakshatra scheme.
     *
     * @return Result<Nakshatra28Document, Meta>
     */
    public function nakshatra28(KundliRequest $request): Result
    {
        return $this->document('postKundliNakshatra28', $request->toArray(), Nakshatra28Document::fromArray(...));
    }

    /**
     * `POST /v1/kundli/sade-sati` — Saturn over the moon sign, in a window.
     *
     * @return Result<SadeSatiDocument, Meta>
     */
    public function sadeSati(SadeSatiRequest $request): Result
    {
        return $this->document('postKundliSadeSati', $request->toArray(), SadeSatiDocument::fromArray(...));
    }

    /**
     * `POST /v1/kundli/events` — what is coming, in a window.
     *
     * @return Result<EventsDocument, Meta>
     */
    public function events(SadeSatiRequest $request): Result
    {
        return $this->document('postKundliEvents', $request->toArray(), EventsDocument::fromArray(...));
    }

    /**
     * `POST /v1/kundli/kota-chakra` — the kota chakra. Never cached.
     *
     * @return Result<KotaDocument, Meta>
     */
    public function kotaChakra(KundliRequest $request): Result
    {
        return $this->document('postKundliKotaChakra', $request->toArray(), KotaDocument::fromArray(...));
    }
}
