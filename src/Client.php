<?php

declare(strict_types=1);

namespace Kaaljyoti;

use Kaaljyoti\Api\CalendarApi;
use Kaaljyoti\Api\EphemerisApi;
use Kaaljyoti\Api\JaiminiApi;
use Kaaljyoti\Api\KpApi;
use Kaaljyoti\Api\KundliApi;
use Kaaljyoti\Api\MatchApi;
use Kaaljyoti\Api\PanchangApi;
use Kaaljyoti\Api\PdfApi;
use Kaaljyoti\Api\ReportsApi;
use Kaaljyoti\Api\TransitApi;
use Kaaljyoti\Api\VarshphalApi;
use Kaaljyoti\Generated\Json;
use Kaaljyoti\Generated\Operations;
use Kaaljyoti\Generated\Version;
use Kaaljyoti\Http\HttpClient;
use Kaaljyoti\Models\HealthDocument;
use Kaaljyoti\Models\HoroscopeDocument;
use Kaaljyoti\Models\HoroscopeRequest;
use Kaaljyoti\Models\Meta;
use Kaaljyoti\Models\PlacesDocument;
use Kaaljyoti\Models\PlacesMeta;
use Kaaljyoti\Models\ReferenceMeta;
use Kaaljyoti\Models\TimezoneDocument;
use Kaaljyoti\Models\TimezoneMeta;

/**
 * The Kaal Jyoti API, typed.
 *
 * ```php
 * use Kaaljyoti\Client;
 * use Kaaljyoti\Models\{Birth, KundliRequest};
 *
 * $kj = new Client(apiKey: getenv('KAALJYOTI_API_KEY'));
 *
 * $answer = $kj->kundli->get(new KundliRequest(
 *     birth: new Birth(
 *         datetime: '1990-05-14T10:30:00', // the clock on the wall at the place
 *         timezone: 'Asia/Kolkata',
 *         latitude: 28.6139,
 *         longitude: 77.209,
 *     ),
 * ));
 * echo $answer->data->lagnaSign->name, ' · ', $answer->meta->timezone->source;
 * ```
 *
 * One method per endpoint, grouped the way the paths are: a caller who has
 * seen `/v1/kundli/sade-sati` should be able to guess `$kj->kundli->sadeSati`
 * and be right. The names match the TypeScript and Dart clients, method for
 * method.
 *
 * Every method returns the envelope — `data` and `meta` together — because
 * `meta` is how an application answers "why is this number what it is". Every
 * failure is a thrown {@see KaaljyotiException} with a `code`; branch on
 * `$error->code()`, never on the message.
 *
 * There is nothing to close: the SDK owns no connection pool of its own, and
 * the one an {@see HttpClient} owns belongs to whoever built it.
 */
final class Client
{
    /** The birth chart and the nineteen documents computed from one. */
    public readonly KundliApi $kundli;

    /** Daily panchang, the day's windows, a month of panchang. */
    public readonly PanchangApi $panchang;

    /** A month of graha positions. */
    public readonly EphemerisApi $ephemeris;

    /** Vikram Samvat. */
    public readonly CalendarApi $calendar;

    /** Chara karakas, arudha padas, rashi drishti, karakamsha. */
    public readonly JaiminiApi $jaimini;

    /** Krishnamurti Paddhati. */
    public readonly KpApi $kp;

    /** The annual chart and what is read from it. */
    public readonly VarshphalApi $varshphal;

    /** The sky now, gochar over a window, and the events of a year. */
    public readonly TransitApi $transit;

    /** Ashtakoot, a full comparison, and both in bulk. */
    public readonly MatchApi $match;

    /** Written readings: the lagna, the Moon's nakshatra. */
    public readonly ReportsApi $reports;

    /**
     * Printable kundli, match, varshphal and monthly panchang PDFs, answered as
     * bytes. Every paid plan, not Free; a publishable key cannot make one.
     */
    public readonly PdfApi $pdf;

    private readonly Transport $transport;

    /**
     * Builds a client. `apiKey` is the only option that has no default.
     *
     * @param string $apiKey Placed by its prefix: `kj_pub_…` travels as `?key=`, everything else as
     *     `Authorization: Bearer`. An empty key is refused before the network, as `invalid_key`.
     * @param string|null $baseUrl Default `https://api.kaaljyoti.com`; a trailing `/` or `/v1` is trimmed, so
     *     staging is one option and not a separate build.
     * @param HttpClient|null $httpClient Your own client — a PSR-18 adapter, WordPress's HTTP API, a fake in a
     *     test. Default a new {@see \Kaaljyoti\Http\CurlClient}.
     * @param float $timeoutSeconds The deadline **per attempt**, not per call.
     * @param int $maxRetries The retry budget; `0` turns retrying off entirely.
     * @param string $clientTag `X-KJ-Client`, so a shell built on this SDK — the WordPress plugin — can
     *     attribute usage to itself rather than to the SDK.
     * @param array<string, string> $headers Extra headers on every request. Cannot override the key, the
     *     `Accept` or the tag.
     * @param Transport|null $transport A transport built by hand. When one is given every option above is
     *     already baked into it and is ignored; {@see withTransport()} is the way in, and a test that needs
     *     to replace the retry sleep is the reason it exists.
     */
    public function __construct(
        string $apiKey,
        ?string $baseUrl = null,
        ?HttpClient $httpClient = null,
        float $timeoutSeconds = 30.0,
        int $maxRetries = 2,
        string $clientTag = 'sdk-php/' . Version::SDK,
        array $headers = [],
        ?Transport $transport = null,
    ) {
        $this->transport = $transport ?? new Transport(
            apiKey: $apiKey,
            baseUrl: $baseUrl,
            httpClient: $httpClient,
            timeoutSeconds: $timeoutSeconds,
            maxRetries: $maxRetries,
            clientTag: $clientTag,
            headers: $headers,
        );

        $this->kundli = new KundliApi($this->transport);
        $this->panchang = new PanchangApi($this->transport);
        $this->ephemeris = new EphemerisApi($this->transport);
        $this->calendar = new CalendarApi($this->transport);
        $this->jaimini = new JaiminiApi($this->transport);
        $this->kp = new KpApi($this->transport);
        $this->varshphal = new VarshphalApi($this->transport);
        $this->transit = new TransitApi($this->transport);
        $this->match = new MatchApi($this->transport);
        $this->reports = new ReportsApi($this->transport);
        $this->pdf = new PdfApi($this->transport);
    }

    /**
     * Builds a client on a {@see Transport} you configured yourself.
     *
     * The only reason to reach for this is a test that needs to replace the
     * retry sleep or watch what went out; everything else is a named argument
     * on the constructor.
     */
    public static function withTransport(Transport $transport): self
    {
        // The key lives on the transport that was passed in, so the one here
        // is never read; it is not optional on the constructor because for
        // every other caller forgetting it is the mistake worth catching.
        return new self(apiKey: '', transport: $transport);
    }

    /**
     * `GET /v1/health` — liveness. Costs no credits and needs no key.
     *
     * The one answer with no envelope around it, so `meta` is always `null`.
     * It keeps answering while the service is disabled, which is what makes it
     * the right thing to poll after a `service_disabled`.
     *
     * @return Result<HealthDocument, null>
     * @throws KaaljyotiException
     */
    public function health(): Result
    {
        return $this->transport->get(
            Operations::byId('getHealth')->path,
            [],
            static fn (mixed $json): HealthDocument => HealthDocument::fromArray(Json::object($json)),
            static fn (mixed $json): null => null,
        );
    }

    /**
     * `GET /v1/reference/{list}` — a static table. Costs no credits.
     *
     * ```php
     * $signs = $kj->reference('signs', ['en', 'hi']);
     * echo $signs->data[0]['name'];   // Aries
     * echo implode(',', $signs->meta->language ?? []); // en,hi
     * ```
     *
     * The rows are deliberately untyped: each table publishes its own columns
     * — `{id, name}` for most, `{index, name}` for the ones the engine numbers
     * — and the snapshot declares them open, so a class here would be a guess
     * that goes stale.
     *
     * @param string $list One of `ayanamsas`, `planets`, `signs`, `nakshatras`, `tithis`, `yogas`, `karanas`,
     *     `vargas`, `dasha-systems`, `house-systems`, `chalit-systems`, `transit-events`, `languages`,
     *     `credits`. `credits` is the price list in force: a `{route, credits}` row per metered route, with
     *     `per` (`pair` or `part`) on the two priced per unit.
     * @param list<string>|string|null $language Joined with commas, because the parameter is one
     *     comma-separated field.
     * @return Result<list<array<string, mixed>>, ReferenceMeta>
     * @throws KaaljyotiException
     */
    public function reference(string $list, array|string|null $language = null): Result
    {
        $languages = is_string($language) ? [$language] : ($language ?? []);

        return $this->transport->get(
            // The snapshot's `{list}` placeholder, filled in. Encoded because a
            // stray `/` in a caller's string must not invent a path segment.
            str_replace('{list}', rawurlencode($list), Operations::byId('getReferenceList')->path),
            ['language' => $languages === [] ? null : implode(',', $languages)],
            static fn (mixed $json): array => array_map(
                static fn (mixed $row): array => Json::object($row),
                Json::list($json),
            ),
            // Both of `ReferenceMeta`'s fields are optional, so an absent
            // `meta` is an empty one rather than a `bad_response`.
            static fn (mixed $json): ReferenceMeta => ReferenceMeta::fromArray(
                $json === null ? [] : Json::object($json),
            ),
        );
    }

    /**
     * `GET /v1/timezone` — the zone covering a point, and its offset at an
     * instant. Costs no credits.
     *
     * This is the cheapest way to fill in a `timezone` you would otherwise
     * have to guess, and the only way to get a *historical* offset: a March
     * 1944 date in Delhi answers `+06:30`, the war-time offset, which PHP's
     * own zone database would give you only if you asked it for that date.
     *
     * @param float $lat Degrees, `-89.9`…`89.9`.
     * @param float $lon Degrees, `-180`…`180`.
     * @param string|null $datetime A wall clock, `YYYY-MM-DDTHH:MM:SS`; leave it out for the offset in force
     *     now. {@see WallClock} writes one.
     * @return Result<TimezoneDocument, TimezoneMeta>
     * @throws KaaljyotiException
     */
    public function timezone(float $lat, float $lon, ?string $datetime = null): Result
    {
        return $this->transport->get(
            Operations::byId('getTimezone')->path,
            [
                // `(string)` rather than `number_format`: the API parses a
                // float, and PHP writes `28.6139` for one.
                'lat' => (string) $lat,
                'lon' => (string) $lon,
                'datetime' => $datetime,
            ],
            static fn (mixed $json): TimezoneDocument => TimezoneDocument::fromArray(Json::object($json)),
            static fn (mixed $json): TimezoneMeta => TimezoneMeta::fromArray(Json::object($json)),
        );
    }

    /**
     * `GET /v1/places` — place search: a name to the coordinates and the zone
     * a `Birth` needs. Costs 1 credit per search.
     *
     * ```php
     * $found = $kj->places('varanasi', country: 'IN', limit: 5);
     * $place = $found->data->places[0];
     * echo $place->name, ' ', $place->timezone; // Varanasi Asia/Kolkata
     * ```
     *
     * @param string $q What the visitor typed, up to 100 characters.
     * @param string|null $country ISO 3166-1 alpha-2, to search one country only.
     * @param int|null $limit `1`…`25`; the API's default is 10.
     * @param list<string>|string|null $language `en`, `hi` or both, joined with commas as for
     *     {@see reference()}.
     * @return Result<PlacesDocument, PlacesMeta>
     * @throws KaaljyotiException
     */
    public function places(
        string $q,
        ?string $country = null,
        ?int $limit = null,
        array|string|null $language = null,
    ): Result {
        $languages = is_string($language) ? [$language] : ($language ?? []);

        return $this->transport->get(
            Operations::byId('getPlaces')->path,
            [
                'q' => $q,
                'country' => $country,
                'limit' => $limit === null ? null : (string) $limit,
                'language' => $languages === [] ? null : implode(',', $languages),
            ],
            static fn (mixed $json): PlacesDocument => PlacesDocument::fromArray(Json::object($json)),
            // Every field of `PlacesMeta` is optional, as for `reference()`.
            static fn (mixed $json): PlacesMeta => PlacesMeta::fromArray(
                $json === null ? [] : Json::object($json),
            ),
        );
    }

    /**
     * `POST /v1/horoscope` — a sign's day, week, month or year, as summaries.
     * Costs 5 credits.
     *
     * ```php
     * use Kaaljyoti\Models\HoroscopeRequest;
     *
     * $answer = $kj->horoscope(new HoroscopeRequest(sign: 'aries', period: 'weekly'));
     * echo $answer->data->summary->level, ': ', $answer->data->summary->text->en, "\n";
     * foreach ($answer->data->areas as $area) {
     *     echo $area->area, ' (', $area->level, '): ', $area->text->en, "\n";
     * }
     * ```
     *
     * `period` is `daily` (the default), `weekly` (seven days from `date`),
     * `monthly` (the calendar month) or `yearly` (the calendar year). Days
     * begin at local midnight in `timezone` — `Asia/Kolkata` unless you say
     * otherwise — and `date` defaults to today there.
     *
     * One `summary` and five `areas` — work, money, relationships, health,
     * education — each with a `level` (`favourable`, `mixed` or `care`) and a
     * text. There are no scores. `basis` lists the transits behind it (one
     * `HoroscopeTransit` per graha and sign, `house` counted from the chosen
     * sign), for you rather than the reader.
     *
     * @return Result<HoroscopeDocument, Meta>
     * @throws KaaljyotiException
     */
    public function horoscope(HoroscopeRequest $request): Result
    {
        return $this->transport->post(
            Operations::byId('postHoroscope')->path,
            $request->toArray(),
            static fn (mixed $json): HoroscopeDocument => HoroscopeDocument::fromArray(
                Json::object($json),
            ),
            static fn (mixed $json): Meta => Meta::fromArray(Json::object($json)),
        );
    }
}
