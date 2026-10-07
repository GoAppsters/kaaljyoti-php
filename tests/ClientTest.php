<?php

declare(strict_types=1);

namespace Kaaljyoti\Tests;

use Kaaljyoti\Api\CalendarApi;
use Kaaljyoti\Api\JaiminiApi;
use Kaaljyoti\Api\KpApi;
use Kaaljyoti\Api\KundliApi;
use Kaaljyoti\Api\MatchApi;
use Kaaljyoti\Api\PanchangApi;
use Kaaljyoti\Api\PdfApi;
use Kaaljyoti\Api\ReportsApi;
use Kaaljyoti\Api\TransitApi;
use Kaaljyoti\Api\VarshphalApi;
use Kaaljyoti\Client;
use Kaaljyoti\Http\HttpResponse;
use Kaaljyoti\KaaljyotiException;
use Kaaljyoti\PdfFile;
use Kaaljyoti\Models\Birth;
use Kaaljyoti\Models\CalculationOptions;
use Kaaljyoti\Models\AreaSummary;
use Kaaljyoti\Models\Disclaimer;
use Kaaljyoti\Models\GrahaReading;
use Kaaljyoti\Models\HoroscopeRequest;
use Kaaljyoti\Models\HoroscopeTransit;
use Kaaljyoti\Models\KundliChartRequest;
use Kaaljyoti\Models\KundliRequest;
use Kaaljyoti\Models\LifeArea;
use Kaaljyoti\Models\MahadashaReading;
use Kaaljyoti\Models\MatchBatchRequest;
use Kaaljyoti\Models\MatchBatchRequestPairsItem;
use Kaaljyoti\Models\PdfKundliRequest;
use Kaaljyoti\Models\PdfMatchRequest;
use Kaaljyoti\Models\PdfPanchangMonthRequest;
use Kaaljyoti\Models\PdfVarshphalRequest;
use Kaaljyoti\Models\ReportLagnaRequest;
use Kaaljyoti\Models\ReadingSummary;
use Kaaljyoti\Models\ReportKundliRequest;
use Kaaljyoti\Models\ReportNakshatraRequest;
use Kaaljyoti\Models\VarshphalPeriod;
use Kaaljyoti\Models\VarshphalRequest;
use Kaaljyoti\Models\YogaReading;
use Kaaljyoti\Tests\Support\RecordingHttpClient;
use Kaaljyoti\Transport;
use PHPUnit\Framework\TestCase;

/**
 * The client's own job: naming, typing, and the three endpoints that are not
 * "POST a body, read a document".
 *
 * The transport is already proven by `TransportTest`, so what is left here is
 * that each method reads the answer into the class the caller was promised and
 * that the odd ones out — an SVG, a reference table, a batch with a failed pair
 * — behave as documented.
 */
final class ClientTest extends TestCase
{
    private const KEY = 'kj_test_bbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbb';

    /** The first bytes of every PDF, and enough of one to prove nothing re-encodes it. */
    private const PDF_BYTES = "%PDF-1.7\n\xe2\xe3\xcf\xd3";

    private static function client(RecordingHttpClient $http): Client
    {
        return Client::withTransport(new Transport(
            apiKey: self::KEY,
            httpClient: $http,
            maxRetries: 0,
            sleep: $http->sleeper(),
        ));
    }

    private static function fixture(string $name): string
    {
        $raw = file_get_contents(__DIR__ . "/fixtures/{$name}.json");
        self::assertIsString($raw);

        return $raw;
    }

    private static function birth(): Birth
    {
        return new Birth(
            datetime: '1990-05-14T10:30:00',
            timezone: 'Asia/Kolkata',
            latitude: 28.6139,
            longitude: 77.209,
            place: 'New Delhi',
        );
    }

    public function testKundliGetAnswersTheDocumentAndTheMetaBothTyped(): void
    {
        $http = new RecordingHttpClient(RecordingHttpClient::json(
            200,
            self::fixture('kundli'),
            'X-KJ-Request-Id: req-1',
        ));

        $answer = self::client($http)->kundli->get(new KundliRequest(birth: self::birth()));

        self::assertSame('cancer', $answer->data->lagnaSign->id);
        self::assertSame('Cancer', $answer->data->lagnaSign->name);
        self::assertSame('कर्क', $answer->data->lagnaSign->names['hi'] ?? null);
        self::assertArrayHasKey('sun', $answer->data->positions);
        self::assertSame('+05:30', $answer->meta->timezone->utcOffset);
        self::assertSame('given', $answer->meta->timezone->source);
        self::assertSame('req-1', $answer->requestId);

        // The path came from the generated table, and the body from the
        // request's own `toArray()`.
        $sent = $http->last();
        self::assertSame('POST', $sent->method);
        self::assertSame('https://api.kaaljyoti.com/v1/kundli', $sent->url);
        self::assertSame(
            ['birth' => [
                'datetime' => '1990-05-14T10:30:00',
                'latitude' => 28.6139,
                'longitude' => 77.209,
                'timezone' => 'Asia/Kolkata',
                'place' => 'New Delhi',
            ]],
            json_decode((string) $sent->body, true, 512, JSON_THROW_ON_ERROR),
        );
    }

    public function testARefusalReachesTheCallerAsAnException(): void
    {
        $http = new RecordingHttpClient(RecordingHttpClient::json(400, self::fixture('error')));

        try {
            self::client($http)->kundli->get(new KundliRequest(birth: self::birth()));
            self::fail('a refusal must not be swallowed into a Result');
        } catch (KaaljyotiException $error) {
            self::assertSame('validation_error', $error->code());
            self::assertSame('options.language', $error->field);
        }
    }

    public function testTheChartIsADocumentWithTheMarkupInsideIt(): void
    {
        $http = new RecordingHttpClient(RecordingHttpClient::json(200, self::fixture('chart')));

        $answer = self::client($http)->kundli->chart(new KundliChartRequest(birth: self::birth(), size: 360));

        self::assertSame('north', $answer->data->style);
        self::assertSame(360, $answer->data->size);
        self::assertStringStartsWith('<svg', $answer->data->svg);
        self::assertNotNull($answer->meta);
        self::assertSame('application/json', $http->last()->headers['Accept']);
    }

    public function testTheChartSendsFirstHouseAndSaysWhatItWasDrawnAs(): void
    {
        $http = new RecordingHttpClient(RecordingHttpClient::json(200, self::fixture('chart')));

        $answer = self::client($http)->kundli->chart(
            new KundliChartRequest(birth: self::birth(), firstHouse: 'lagna'),
        );

        $sent = json_decode((string) $http->last()->body, true, 512, JSON_THROW_ON_ERROR);
        self::assertIsArray($sent);
        self::assertSame('lagna', $sent['first_house']);
        self::assertSame('lagna', $answer->data->firstHouse);
        self::assertSame('cancer', $answer->data->firstHouseSign->id);
        self::assertSame('Lagna chart', $answer->data->title);
    }

    public function testChartSvgIsTheMarkupItselfWithNoMeta(): void
    {
        $http = new RecordingHttpClient(new HttpResponse(
            200,
            ['content-type' => 'image/svg+xml'],
            '<svg viewBox="0 0 360 360"><g/></svg>',
        ));

        $answer = self::client($http)->kundli->chartSvg(new KundliChartRequest(birth: self::birth(), size: 360));

        self::assertSame('<svg viewBox="0 0 360 360"><g/></svg>', $answer->data);
        self::assertNull($answer->meta);
        // The same endpoint and the same call cost as `chart()`; only the
        // `Accept` differs.
        self::assertSame('https://api.kaaljyoti.com/v1/kundli/chart', $http->last()->url);
        self::assertSame('image/svg+xml', $http->last()->headers['Accept']);
    }

    public function testReferenceJoinsTheLanguagesIntoTheOneParameterTheApiTakes(): void
    {
        $http = new RecordingHttpClient(RecordingHttpClient::envelope(
            [['id' => 'aries', 'name' => 'Aries', 'names' => ['en' => 'Aries', 'hi' => 'मेष']]],
            ['engine' => '0.2.0', 'language' => ['en', 'hi']],
        ));

        $answer = self::client($http)->reference('signs', ['en', 'hi']);

        self::assertSame('https://api.kaaljyoti.com/v1/reference/signs?language=en%2Chi', $http->last()->url);
        self::assertSame('GET', $http->last()->method);
        self::assertSame('aries', $answer->data[0]['id'] ?? null);
        self::assertSame(['en', 'hi'], $answer->meta->language);
        self::assertSame('0.2.0', $answer->meta->engine);
    }

    public function testReferenceTakesASingleLanguageAsAString(): void
    {
        $http = new RecordingHttpClient(RecordingHttpClient::envelope([], ['language' => ['hi']]));

        self::client($http)->reference('signs', 'hi');

        self::assertSame('https://api.kaaljyoti.com/v1/reference/signs?language=hi', $http->last()->url);
    }

    public function testReferenceLeavesTheParameterOutAndAcceptsAnAbsentMeta(): void
    {
        // Both of `ReferenceMeta`'s fields are optional, so an answer with no
        // `meta` at all is an empty one rather than a `bad_response`.
        $http = new RecordingHttpClient(RecordingHttpClient::json(200, '{"status":"ok","data":[]}'));

        $answer = self::client($http)->reference('tithis');

        self::assertSame('https://api.kaaljyoti.com/v1/reference/tithis', $http->last()->url);
        self::assertSame([], $answer->data);
        self::assertNull($answer->meta->engine);
        self::assertNull($answer->meta->language);
    }

    public function testReferenceKeepsAStraySlashInsideTheSegment(): void
    {
        // A caller's string must not invent a path segment.
        $http = new RecordingHttpClient(RecordingHttpClient::envelope([], []));

        self::client($http)->reference('signs/../health');

        self::assertSame(
            'https://api.kaaljyoti.com/v1/reference/signs%2F..%2Fhealth',
            $http->last()->url,
        );
    }

    public function testTimezoneSendsThePointAndTheInstantAndReadsTheOffsetBack(): void
    {
        $http = new RecordingHttpClient(RecordingHttpClient::envelope(
            ['name' => 'Asia/Kolkata', 'utc_offset' => '+06:30', 'source' => 'derived'],
            ['datetime' => '1944-03-15T10:00:00'],
        ));

        $answer = self::client($http)->timezone(28.6139, 77.209, '1944-03-15T10:00:00');

        self::assertSame(
            'https://api.kaaljyoti.com/v1/timezone?lat=28.6139&lon=77.209&datetime=1944-03-15T10%3A00%3A00',
            $http->last()->url,
        );
        self::assertSame('Asia/Kolkata', $answer->data->name);
        self::assertSame('+06:30', $answer->data->utcOffset, 'the war-time offset, not today\'s');
        self::assertSame('1944-03-15T10:00:00', $answer->meta->datetime);
    }

    public function testTimezoneLeavesDatetimeOutForTheOffsetInForceNow(): void
    {
        $http = new RecordingHttpClient(RecordingHttpClient::envelope(
            ['name' => 'Asia/Kolkata', 'utc_offset' => '+05:30', 'source' => 'derived'],
            [],
        ));

        self::client($http)->timezone(28.6139, 77.209);

        self::assertSame(
            'https://api.kaaljyoti.com/v1/timezone?lat=28.6139&lon=77.209',
            $http->last()->url,
        );
    }

    public function testReportsLagnaSendsThePickedSignAndReadsTheText(): void
    {
        $http = new RecordingHttpClient(RecordingHttpClient::json(200, self::fixture('reading-lagna')));

        $answer = self::client($http)->reports->lagna(new ReportLagnaRequest(
            sign: 'leo',
            options: new CalculationOptions(language: ['en']),
        ));

        $sent = $http->last();
        self::assertSame('POST', $sent->method);
        self::assertSame('https://api.kaaljyoti.com/v1/reports/lagna', $sent->url);
        self::assertSame(
            ['sign' => 'leo', 'options' => ['language' => 'en']],
            json_decode((string) $sent->body, true, 512, JSON_THROW_ON_ERROR),
        );
        self::assertSame('leo', $answer->data->lagna?->sign->id);
        self::assertStringStartsWith('With Leo rising', (string) $answer->data->lagna?->entry->text->en);
        self::assertSame(
            'These predictions are indicative. For a reading of your own chart, consult an astrologer.',
            $answer->data->disclaimer?->en,
        );
        self::assertSame('lahiri', $answer->meta->ayanamsa->id);
    }

    public function testReportsNakshatraSendsABirthOrAPickAndTheNamedDisclaimer(): void
    {
        $http = new RecordingHttpClient(
            RecordingHttpClient::json(200, self::fixture('reading-nakshatra')),
            RecordingHttpClient::json(200, self::fixture('reading-nakshatra-named')),
        );
        $kj = self::client($http);

        $byBirth = $kj->reports->nakshatra(new ReportNakshatraRequest(
            birth: self::birth(),
            options: new CalculationOptions(language: ['en', 'hi']),
        ));
        self::assertSame('https://api.kaaljyoti.com/v1/reports/nakshatra', $http->last()->url);
        self::assertSame(
            ['birth', 'options'],
            array_keys((array) json_decode((string) $http->last()->body, true, 512, JSON_THROW_ON_ERROR)),
        );
        self::assertSame('purva_ashadha', $byBirth->data->nakshatra?->nakshatra->id);
        self::assertNotEmpty($byBirth->data->nakshatra?->entry->text->hi);

        $picked = $kj->reports->nakshatra(new ReportNakshatraRequest(
            nakshatra: 'purva_phalguni',
            options: new CalculationOptions(
                language: ['en'],
                disclaimer: new Disclaimer(name: 'Acharya Amit Verma', url: 'https://kaaljyoti.com'),
            ),
        ));
        self::assertSame(
            [
                'nakshatra' => 'purva_phalguni',
                'options' => [
                    'language' => 'en',
                    'disclaimer' => ['name' => 'Acharya Amit Verma', 'url' => 'https://kaaljyoti.com'],
                ],
            ],
            json_decode((string) $http->last()->body, true, 512, JSON_THROW_ON_ERROR),
        );
        self::assertSame('Purva Phalguni', $picked->data->nakshatra?->nakshatra->name);
        self::assertStringEndsWith(
            'consult Acharya Amit Verma (https://kaaljyoti.com).',
            (string) $picked->data->disclaimer?->en,
        );
    }

    public function testReportsHouseLordsSendsTheBirthAndReadsTwelveHousesInOrder(): void
    {
        $http = new RecordingHttpClient(RecordingHttpClient::json(200, self::fixture('reading-house-lords')));

        $answer = self::client($http)->reports->houseLords(new KundliRequest(
            birth: new Birth(
                datetime: '1987-03-18T12:06:00',
                timezone: 'Asia/Kolkata',
                latitude: 28.6139,
                longitude: 77.209,
            ),
            options: new CalculationOptions(language: ['en', 'hi']),
        ));

        $sent = $http->last();
        self::assertSame('POST', $sent->method);
        self::assertSame('https://api.kaaljyoti.com/v1/reports/house-lords', $sent->url);
        self::assertSame(
            [
                'birth' => [
                    'datetime' => '1987-03-18T12:06:00',
                    'latitude' => 28.6139,
                    'longitude' => 77.209,
                    'timezone' => 'Asia/Kolkata',
                ],
                'options' => ['language' => ['en', 'hi']],
            ],
            json_decode((string) $sent->body, true, 512, JSON_THROW_ON_ERROR),
        );

        $lords = $answer->data->houseLords ?? [];
        self::assertSame(range(1, 12), array_map(static fn ($lord): int => $lord->house, $lords));
        // Gemini rising: Mercury rules the 1st and sits in the 9th.
        self::assertSame('gemini', $lords[0]->sign->id);
        self::assertSame('mercury', $lords[0]->lord->id);
        self::assertSame('बुध', $lords[0]->lord->names['hi'] ?? null);
        self::assertSame(9, $lords[0]->inHouse);
        self::assertNotEmpty($lords[0]->entry->text->en);
        self::assertNotEmpty($lords[0]->entry->text->hi);
        self::assertStringContainsString('सांकेतिक', (string) $answer->data->disclaimer?->hi);
        self::assertSame('0.5.0', $answer->meta->engine);
    }

    public function testHoroscopeSendsTheSignAndThePeriodAndReadsTheSummaries(): void
    {
        $http = new RecordingHttpClient(RecordingHttpClient::json(200, self::fixture('horoscope')));

        $answer = self::client($http)->horoscope(new HoroscopeRequest(
            sign: 'aries',
            period: 'daily',
            date: '2026-09-28',
            timezone: 'Asia/Kolkata',
        ));

        $sent = $http->last();
        self::assertSame('POST', $sent->method);
        self::assertSame('https://api.kaaljyoti.com/v1/horoscope', $sent->url);
        self::assertSame(
            ['sign' => 'aries', 'period' => 'daily', 'date' => '2026-09-28', 'timezone' => 'Asia/Kolkata'],
            json_decode((string) $sent->body, true, 512, JSON_THROW_ON_ERROR),
        );

        self::assertSame('aries', $answer->data->sign->id);
        self::assertSame('2026-09-27T18:30:00.000Z', $answer->data->from);
        self::assertInstanceOf(ReadingSummary::class, $answer->data->summary);
        self::assertSame('care', $answer->data->summary->level);
        self::assertSame(
            [['work', 'mixed'], ['money', 'care'], ['relationships', 'care'], ['health', 'mixed'], ['education', 'care']],
            array_map(static fn (AreaSummary $area): array => [$area->area, $area->level], $answer->data->areas),
        );
        $moon = array_values(array_filter(
            $answer->data->basis,
            static fn (HoroscopeTransit $transit): bool => $transit->graha->id === 'moon',
        ));
        self::assertSame(['pisces', 'aries'], [$moon[0]->sign->id, $moon[1]->sign->id]);
        self::assertSame([12, 1], [$moon[0]->house, $moon[1]->house]);
        self::assertSame($moon[0]->leaves, $moon[1]->entered);
        self::assertSame('Asia/Kolkata', $answer->meta->timezone->name);
    }

    public function testReportsGrahasReadsNineGrahas(): void
    {
        $http = new RecordingHttpClient(RecordingHttpClient::json(200, self::fixture('reading-grahas')));

        $answer = self::client($http)->reports->grahas(new KundliRequest(birth: self::birth()));

        self::assertSame('https://api.kaaljyoti.com/v1/reports/grahas', $http->last()->url);
        $grahas = $answer->data->grahas ?? [];
        self::assertCount(9, $grahas);
        self::assertInstanceOf(GrahaReading::class, $grahas[0]);
        self::assertSame(['sun', 'aries', 10], [$grahas[0]->graha->id, $grahas[0]->sign->id, $grahas[0]->house]);
        self::assertStringStartsWith('Your Sun is in Aries', (string) $grahas[0]->inSign->text->en);
        self::assertNotEmpty($grahas[0]->inHouse->text->hi);
        self::assertSame('0.10.1', $answer->meta->engine);
    }

    public function testReportsYogasNamesEachByCode(): void
    {
        $http = new RecordingHttpClient(RecordingHttpClient::json(200, self::fixture('reading-yogas')));

        $answer = self::client($http)->reports->yogas(new KundliRequest(birth: self::birth()));

        self::assertSame('https://api.kaaljyoti.com/v1/reports/yogas', $http->last()->url);
        $first = ($answer->data->yogas ?? [])[0];
        self::assertInstanceOf(YogaReading::class, $first);
        self::assertSame(['gaja_kesari', 'Chandra'], [$first->code, $first->category]);
        self::assertSame(['jupiter', 'moon'], array_map(static fn ($p): string => $p->id, $first->participants));
        self::assertSame(['Gaja-Kesari Yoga', 'गजकेसरी योग'], [$first->name?->en, $first->name?->hi]);
    }

    public function testReportsKundliAnswersThePartsAskedFor(): void
    {
        $http = new RecordingHttpClient(RecordingHttpClient::json(200, self::fixture('reading-kundli')));

        $answer = self::client($http)->reports->kundli(new ReportKundliRequest(
            birth: self::birth(),
            parts: ['lagna', 'yogas', 'vimshottari', 'varshphal'],
        ));

        self::assertSame('https://api.kaaljyoti.com/v1/reports/kundli', $http->last()->url);
        $body = json_decode((string) $http->last()->body, true, 512, JSON_THROW_ON_ERROR);
        self::assertSame(['lagna', 'yogas', 'vimshottari', 'varshphal'], $body['parts']);
        self::assertArrayNotHasKey('year', $body);
        self::assertSame(['lagna', 'yogas', 'vimshottari', 'varshphal'], $answer->data->parts);
        self::assertSame('cancer', $answer->data->lagna?->sign->id);
        self::assertSame('gaja_kesari', ($answer->data->yogas ?? [])[0]->code);
        self::assertCount(1, array_filter($answer->data->vimshottari->periods ?? [], static fn ($p): bool => $p->current));
        // No year was sent: the API reads the one running now.
        self::assertSame(2026, $answer->data->varshphal?->year);
        // Four parts at 5 credits each.
        self::assertSame(20, $answer->meta->credits);
    }

    public function testReportsVimshottariMarksTheCurrentMahadasha(): void
    {
        $http = new RecordingHttpClient(RecordingHttpClient::json(200, self::fixture('reading-vimshottari')));

        $answer = self::client($http)->reports->vimshottari(new KundliRequest(birth: self::birth()));

        self::assertSame('https://api.kaaljyoti.com/v1/reports/vimshottari', $http->last()->url);
        $periods = $answer->data->periods;
        self::assertContainsOnlyInstancesOf(MahadashaReading::class, $periods);
        self::assertSame(
            ['venus', 'sun', 'moon', 'mars', 'rahu', 'jupiter', 'saturn'],
            array_map(static fn (MahadashaReading $p): string => $p->lord->id, $periods),
        );
        $current = array_values(array_filter($periods, static fn (MahadashaReading $p): bool => $p->current));
        self::assertCount(1, $current);
        self::assertSame(['mars', 'mixed'], [$current[0]->lord->id, $current[0]->level]);
        self::assertNotEmpty($periods[0]->antardashas);
    }

    public function testReportsVarshphalSendsTheYear(): void
    {
        $http = new RecordingHttpClient(RecordingHttpClient::json(200, self::fixture('reading-varshphal')));

        $answer = self::client($http)->reports->varshphal(new VarshphalRequest(birth: self::birth(), year: 2026));

        self::assertSame('https://api.kaaljyoti.com/v1/reports/varshphal', $http->last()->url);
        $body = json_decode((string) $http->last()->body, true, 512, JSON_THROW_ON_ERROR);
        self::assertSame(2026, $body['year']);
        self::assertSame(2026, $answer->data->year);
        self::assertSame('mixed', $answer->data->summary->level);
        self::assertSame(
            ['work', 'money', 'relationships', 'health', 'education', 'home', 'travel'],
            array_map(static fn (AreaSummary $area): string => $area->area, $answer->data->areas),
        );
        self::assertCount(10, $answer->data->months);
        self::assertInstanceOf(VarshphalPeriod::class, $answer->data->months[0]);
        self::assertSame('venus', $answer->data->months[0]->lord->id);
    }

    public function testReportsLifeAreasReadsElevenAreas(): void
    {
        $http = new RecordingHttpClient(RecordingHttpClient::json(200, self::fixture('reading-life-areas')));

        $answer = self::client($http)->reports->lifeAreas(new KundliRequest(birth: self::birth()));

        self::assertSame('https://api.kaaljyoti.com/v1/reports/life-areas', $http->last()->url);
        self::assertSame(['foreign', 'marriage'], $answer->data->summary->strongest);
        self::assertSame(['children', 'fortune'], $answer->data->summary->needsCare);
        self::assertCount(11, $answer->data->areas);
        self::assertInstanceOf(LifeArea::class, $answer->data->areas[0]);
        self::assertSame(['self', 'favourable'], [$answer->data->areas[0]->area, $answer->data->areas[0]->level]);
    }

    public function testAMonthWithNoCreditsLeftThrowsQuotaExceeded(): void
    {
        $http = new RecordingHttpClient(RecordingHttpClient::json(402, json_encode([
            'status' => 'error',
            'error' => [
                'code' => 'quota_exceeded',
                'message' => 'you have used all 1,000 credits for this month (1,000 included in the Free plan)',
                'docs' => 'https://kaaljyoti.com/api/docs/errors#quota_exceeded',
            ],
        ], JSON_THROW_ON_ERROR)));

        try {
            self::client($http)->reports->lifeAreas(new KundliRequest(birth: self::birth()));
            self::fail('a quota refusal must not be swallowed into a Result');
        } catch (KaaljyotiException $error) {
            self::assertSame('quota_exceeded', $error->code());
            self::assertSame(402, $error->status);
        }
    }

    public function testHoroscopeSendsOnlyTheSignWhenThatIsAllItIsGiven(): void
    {
        $http = new RecordingHttpClient(RecordingHttpClient::json(200, self::fixture('horoscope')));

        self::client($http)->horoscope(new HoroscopeRequest(
            sign: 'aries',
            options: new CalculationOptions(disclaimer: 'off'),
        ));

        self::assertSame(
            ['sign' => 'aries', 'options' => ['disclaimer' => 'off']],
            json_decode((string) $http->last()->body, true, 512, JSON_THROW_ON_ERROR),
        );
    }

    public function testPlacesSendsTheSearchAsQueryParametersAndReadsTheRows(): void
    {
        $http = new RecordingHttpClient(RecordingHttpClient::envelope(
            ['places' => [[
                'id' => 1253405,
                'name' => 'Varanasi',
                'names' => ['en' => 'Varanasi', 'hi' => 'वाराणसी'],
                'region' => 'Uttar Pradesh',
                'country' => 'IN',
                'country_name' => 'India',
                'latitude' => 25.31668,
                'longitude' => 83.01041,
                'timezone' => 'Asia/Kolkata',
                'population' => 1164404,
            ]]],
            ['query' => 'varanasi', 'language' => ['en', 'hi'], 'count' => 1, 'source' => 'geonames'],
        ));

        $answer = self::client($http)->places('varanasi', country: 'IN', limit: 5, language: ['en', 'hi']);

        self::assertSame('GET', $http->last()->method);
        self::assertSame(
            'https://api.kaaljyoti.com/v1/places?q=varanasi&country=IN&limit=5&language=en%2Chi',
            $http->last()->url,
        );
        $place = $answer->data->places[0];
        self::assertSame('Varanasi', $place->name);
        self::assertSame('वाराणसी', $place->names['hi'] ?? null);
        self::assertSame('India', $place->countryName);
        self::assertSame('Asia/Kolkata', $place->timezone);
        self::assertSame(1, $answer->meta->count);
    }

    public function testPlacesLeavesOutEveryParameterButTheSearch(): void
    {
        $http = new RecordingHttpClient(RecordingHttpClient::envelope(['places' => []], []));

        $answer = self::client($http)->places('bombay');

        self::assertSame('https://api.kaaljyoti.com/v1/places?q=bombay', $http->last()->url);
        self::assertSame([], $answer->data->places);
    }

    public function testHealthAnswersBareWithNoEnvelopeAndNoMeta(): void
    {
        $http = new RecordingHttpClient(RecordingHttpClient::json(
            200,
            '{"status":"ok","engine":"0.14.2","ephemeris":"kaaljyoti-ephemeris 0.1.1","ops":42,"supported_range":{"first_date":"1550-04-01","last_date":"2400-12-31","first_year":1551,"last_year":2399},"uptime_s":9000}',
        ));

        $answer = self::client($http)->health();

        self::assertSame('https://api.kaaljyoti.com/v1/health', $http->last()->url);
        self::assertSame('ok', $answer->data->status);
        self::assertSame('0.14.2', $answer->data->engine);
        self::assertSame('kaaljyoti-ephemeris 0.1.1', $answer->data->ephemeris);
        self::assertSame(42, $answer->data->ops);
        self::assertNull($answer->meta);
    }

    /**
     * @param array<string, string> $headers Lower-cased, as an {@see HttpResponse} keeps them.
     */
    private static function pdf(array $headers = []): HttpResponse
    {
        return new HttpResponse(200, array_merge([
            'content-type' => 'application/pdf',
            'content-disposition' => 'attachment; filename="kundli-ravi-kumar.pdf"',
            'x-kj-credits' => '1000',
            'x-kj-credits-remaining' => '49000',
            'x-kj-cache' => 'miss',
            'x-kj-request-id' => '97f48252-8b54-4a7e-9e81-eb06d5a3ce02',
        ], $headers), self::PDF_BYTES);
    }

    public function testPdfKundliAnswersTheBytesUntouchedWithTheFileNameAndTheCost(): void
    {
        $http = new RecordingHttpClient(self::pdf());

        $answer = self::client($http)->pdf->kundli(
            new PdfKundliRequest(birth: self::birth(), name: 'Ravi Kumar', edition: 'basic'),
        );

        self::assertInstanceOf(PdfFile::class, $answer->data);
        self::assertSame(self::PDF_BYTES, $answer->data->bytes);
        self::assertSame('application/pdf', $answer->data->contentType);
        self::assertSame('kundli-ravi-kumar.pdf', $answer->data->filename);
        self::assertSame(1000, $answer->data->credits);
        self::assertSame(1000, $answer->credits);
        self::assertSame(49000, $answer->creditsRemaining);
        self::assertNull($answer->meta);
        self::assertFalse($answer->cached);
        self::assertSame('97f48252-8b54-4a7e-9e81-eb06d5a3ce02', $answer->requestId);

        $sent = $http->last();
        self::assertSame('https://api.kaaljyoti.com/v1/pdf/kundli', $sent->url);
        self::assertSame('application/pdf', $sent->headers['Accept']);
        self::assertSame(
            ['birth' => self::birth()->toArray(), 'name' => 'Ravi Kumar', 'edition' => 'basic'],
            json_decode((string) $sent->body, true, 512, JSON_THROW_ON_ERROR),
        );
    }

    public function testAPdfCacheHitIsReadFromTheHeaderTheOnlyPlaceItCanBe(): void
    {
        $http = new RecordingHttpClient(self::pdf(['x-kj-cache' => 'hit']));

        $answer = self::client($http)->pdf->match(
            new PdfMatchRequest(bride: self::birth(), groom: self::birth(), name: 'Sita'),
        );

        self::assertTrue($answer->cached);
        self::assertSame('https://api.kaaljyoti.com/v1/pdf/match', $http->last()->url);
    }

    public function testAPdfWhoseHeadersAProxyStrippedAnswersNullForThem(): void
    {
        $http = new RecordingHttpClient(new HttpResponse(200, ['content-type' => 'application/pdf'], self::PDF_BYTES));

        $answer = self::client($http)->pdf->varshphal(new PdfVarshphalRequest(birth: self::birth(), year: 2026));

        self::assertSame(self::PDF_BYTES, $answer->data->bytes);
        self::assertNull($answer->data->filename);
        self::assertNull($answer->data->credits);
        self::assertNull($answer->credits);
        self::assertNull($answer->creditsRemaining);
        self::assertSame('https://api.kaaljyoti.com/v1/pdf/varshphal', $http->last()->url);
    }

    public function testARefusedPdfThrowsTheJsonErrorItAnsweredWith(): void
    {
        $http = new RecordingHttpClient(RecordingHttpClient::failure(
            402,
            'pdf_quota_exceeded',
            "This month's PDFs are used up.",
        ));

        try {
            self::client($http)->pdf->panchangMonth(
                new PdfPanchangMonthRequest(latitude: 25.3176, longitude: 82.9739, month: '2026-10'),
            );
            self::fail('a refused PDF must not come back as a file');
        } catch (KaaljyotiException $error) {
            self::assertSame('pdf_quota_exceeded', $error->code());
            self::assertSame(402, $error->status);
        }

        self::assertSame('https://api.kaaljyoti.com/v1/pdf/panchang/month', $http->last()->url);
    }

    public function testBatchKeepsAPairThatFailedAsAValueBesideTheOnesThatDidNot(): void
    {
        // The other pairs were computed and charged; throwing would discard
        // answers already paid for.
        $envelope = [
            'status' => 'ok',
            'data' => ['results' => [
                [
                    'index' => 0,
                    'data' => [
                        'bride_mangal_dosha' => false,
                        'groom_mangal_dosha' => false,
                        'kootas' => [],
                        'mangal_dosha_mismatch' => false,
                        'total' => 28.5,
                        'verdict' => 'good',
                    ],
                ],
                [
                    'index' => 1,
                    'error' => [
                        'code' => 'not_computable',
                        'message' => 'no sunrise at this latitude',
                        'field' => 'pairs.1.groom',
                    ],
                ],
            ]],
            'meta' => [
                'cached' => false,
                'ayanamsa' => ['id' => 'lahiri', 'value' => 23.72],
                'engine' => '0.2.0',
                'compute_ms' => 41,
                'language_fallback' => [],
                'credits' => 2,
            ],
        ];

        $http = new RecordingHttpClient(
            RecordingHttpClient::json(200, json_encode($envelope, JSON_THROW_ON_ERROR)),
        );

        $answer = self::client($http)->match->batch(new MatchBatchRequest(pairs: [
            new MatchBatchRequestPairsItem(bride: self::birth(), groom: self::birth()),
            new MatchBatchRequestPairsItem(bride: self::birth(), groom: self::birth()),
        ]));

        self::assertCount(2, $answer->data->results);
        self::assertSame(28.5, $answer->data->results[0]->data?->total);
        self::assertNull($answer->data->results[0]->error);
        self::assertNull($answer->data->results[1]->data);
        self::assertSame('not_computable', $answer->data->results[1]->error?->code);
        self::assertSame('pairs.1.groom', $answer->data->results[1]->error?->field);
        // `BatchMeta` rather than `Meta`: one timezone would be a lie for a
        // hundred pairs, and `credits` is what the request cost.
        self::assertSame(2, $answer->meta->credits);
    }

    public function testEveryNamespaceIsBoundToTheSameTransport(): void
    {
        $http = new RecordingHttpClient(RecordingHttpClient::envelope([], []));
        $kj = self::client($http);

        // Pulling a namespace out is the obvious thing to write, so it has to
        // keep working.
        $kundli = $kj->kundli;
        self::assertInstanceOf(KundliApi::class, $kundli);
        self::assertInstanceOf(PanchangApi::class, $kj->panchang);
        self::assertInstanceOf(CalendarApi::class, $kj->calendar);
        self::assertInstanceOf(JaiminiApi::class, $kj->jaimini);
        self::assertInstanceOf(KpApi::class, $kj->kp);
        self::assertInstanceOf(VarshphalApi::class, $kj->varshphal);
        self::assertInstanceOf(TransitApi::class, $kj->transit);
        self::assertInstanceOf(MatchApi::class, $kj->match);
        self::assertInstanceOf(ReportsApi::class, $kj->reports);
        self::assertInstanceOf(PdfApi::class, $kj->pdf);
    }

    public function testAClientBuiltFromOptionsCarriesThemToTheTransport(): void
    {
        $http = new RecordingHttpClient(RecordingHttpClient::envelope([], []));
        $kj = new Client(
            apiKey: self::KEY,
            baseUrl: 'https://api-staging.kaaljyoti.com/v1/',
            httpClient: $http,
            timeoutSeconds: 2.5,
            maxRetries: 0,
            clientTag: 'wordpress/1.0.0',
            headers: ['Origin' => 'https://example.com'],
        );

        $kj->reference('signs');

        $sent = $http->last();
        self::assertSame('https://api-staging.kaaljyoti.com/v1/reference/signs', $sent->url);
        self::assertSame(2.5, $sent->timeoutSeconds);
        self::assertSame('wordpress/1.0.0', $sent->headers['X-KJ-Client']);
        self::assertSame('https://example.com', $sent->headers['Origin']);
    }
}
