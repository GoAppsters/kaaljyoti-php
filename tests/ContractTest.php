<?php

declare(strict_types=1);

namespace Kaaljyoti\Tests;

use Closure;
use Kaaljyoti\Client;
use Kaaljyoti\Generated\Operation;
use Kaaljyoti\Generated\Operations;
use Kaaljyoti\Http\HttpRequest;
use Kaaljyoti\KaaljyotiException;
use Kaaljyoti\Models\Birth;
use Kaaljyoti\Models\ChalitRequest;
use Kaaljyoti\Models\DashaRequest;
use Kaaljyoti\Models\EphemerisMonthRequest;
use Kaaljyoti\Models\HoroscopeRequest;
use Kaaljyoti\Models\HouseSystem;
use Kaaljyoti\Models\KundliChartRequest;
use Kaaljyoti\Models\KundliRequest;
use Kaaljyoti\Models\MatchAshtakootRequest;
use Kaaljyoti\Models\MatchBatchRequest;
use Kaaljyoti\Models\MatchBatchRequestPairsItem;
use Kaaljyoti\Models\MatchCompareRequest;
use Kaaljyoti\Models\MuhurtaRequest;
use Kaaljyoti\Models\PanchangMonthRequest;
use Kaaljyoti\Models\PanchangRequest;
use Kaaljyoti\Models\PdfKundliOptions;
use Kaaljyoti\Models\PdfKundliRequest;
use Kaaljyoti\Models\PdfMatchRequest;
use Kaaljyoti\Models\PdfPanchangMonthRequest;
use Kaaljyoti\Models\PdfVarshphalRequest;
use Kaaljyoti\Models\ReportKundliRequest;
use Kaaljyoti\Models\ReportLagnaRequest;
use Kaaljyoti\Models\ReportNakshatraRequest;
use Kaaljyoti\Models\SadeSatiRequest;
use Kaaljyoti\Models\TransitNowRequest;
use Kaaljyoti\Models\TransitEventsRequest;
use Kaaljyoti\Models\TransitScanRequest;
use Kaaljyoti\Models\VargasRequest;
use Kaaljyoti\Models\VarshphalRequest;
use Kaaljyoti\Models\VikramSamvatRequest;
use Kaaljyoti\Tests\Support\RecordingHttpClient;
use Kaaljyoti\Transport;
use PHPUnit\Framework\TestCase;

/**
 * The client against the snapshot it was generated from.
 *
 * This is the test that makes `Operations` worth having. It reads
 * `openapi/openapi.json`, calls every operation through the public client with
 * a minimal request, and asserts that what went out matches what the document
 * says: the same verb, the same path, no query parameter the document did not
 * declare — and, for a secret key, no `key` in the URL at all, which is the
 * mistake the gateway refuses outright.
 *
 * The table below is written by hand on purpose. An operation added to the API
 * and forgotten here fails the "every operation is exercised" assertion, which
 * is the only way a missing method gets noticed before a user notices.
 */
final class ContractTest extends TestCase
{
    private const PUB_KEY = 'kj_pub_ccccccccccccccccccccccccccccccccccccccccccc';
    private const SECRET_KEY = 'kj_test_bbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbb';

    private static function birth(): Birth
    {
        return new Birth(
            datetime: '1990-05-14T10:30:00',
            timezone: 'Asia/Kolkata',
            latitude: 28.6139,
            longitude: 77.209,
        );
    }

    /**
     * One call on the client, per `operationId`.
     *
     * `kundli->chartSvg` is deliberately absent: it is the same operation as
     * `kundli->chart` with a different `Accept`, and `ClientTest` covers the
     * difference. Every other entry is one operation, called exactly once.
     *
     * @return array<string, Closure(Client): void>
     */
    private static function calls(): array
    {
        $birth = self::birth();
        $kundli = new KundliRequest(birth: $birth);
        $window = new SadeSatiRequest(birth: $birth);
        $varshphal = new VarshphalRequest(birth: $birth, year: 2026);
        $place = ['latitude' => 28.6139, 'longitude' => 77.209, 'timezone' => 'Asia/Kolkata'];

        return [
            'getHealth' => static function (Client $kj): void {
                $kj->health();
            },
            'getReferenceList' => static function (Client $kj): void {
                $kj->reference('signs', ['en', 'hi']);
            },
            'getTimezone' => static function (Client $kj): void {
                $kj->timezone(28.6139, 77.209);
            },
            'getPlaces' => static function (Client $kj): void {
                $kj->places('delhi', country: 'IN', limit: 5, language: ['en', 'hi']);
            },
            'postKundli' => static function (Client $kj) use ($kundli): void {
                $kj->kundli->get($kundli);
            },
            'postKundliChart' => static function (Client $kj) use ($birth): void {
                $kj->kundli->chart(new KundliChartRequest(birth: $birth));
            },
            'postKundliDasha' => static function (Client $kj) use ($birth): void {
                $kj->kundli->dasha(new DashaRequest(birth: $birth, system: 'vimshottari'));
            },
            'postKundliVargas' => static function (Client $kj) use ($birth): void {
                $kj->kundli->vargas(new VargasRequest(birth: $birth));
            },
            'postKundliChalit' => static function (Client $kj) use ($birth): void {
                $kj->kundli->chalit(new ChalitRequest(birth: $birth));
            },
            'postKundliYogas' => static function (Client $kj) use ($kundli): void {
                $kj->kundli->yogas($kundli);
            },
            'postKundliShadbala' => static function (Client $kj) use ($kundli): void {
                $kj->kundli->shadbala($kundli);
            },
            'postKundliBhavaBala' => static function (Client $kj) use ($kundli): void {
                $kj->kundli->bhavaBala($kundli);
            },
            'postKundliAshtakavarga' => static function (Client $kj) use ($kundli): void {
                $kj->kundli->ashtakavarga($kundli);
            },
            'postKundliGrahaDrishti' => static function (Client $kj) use ($kundli): void {
                $kj->kundli->grahaDrishti($kundli);
            },
            'postKundliMaitri' => static function (Client $kj) use ($kundli): void {
                $kj->kundli->maitri($kundli);
            },
            'postKundliPace' => static function (Client $kj) use ($kundli): void {
                $kj->kundli->pace($kundli);
            },
            'postKundliSpecialLagnas' => static function (Client $kj) use ($kundli): void {
                $kj->kundli->specialLagnas($kundli);
            },
            'postKundliTripataki' => static function (Client $kj) use ($kundli): void {
                $kj->kundli->tripataki($kundli);
            },
            'postKundliSarvatobhadra' => static function (Client $kj) use ($kundli): void {
                $kj->kundli->sarvatobhadra($kundli);
            },
            'postKundliNakshatra28' => static function (Client $kj) use ($kundli): void {
                $kj->kundli->nakshatra28($kundli);
            },
            'postKundliSadeSati' => static function (Client $kj) use ($window): void {
                $kj->kundli->sadeSati($window);
            },
            'postKundliEvents' => static function (Client $kj) use ($window): void {
                $kj->kundli->events($window);
            },
            'postKundliKotaChakra' => static function (Client $kj) use ($kundli): void {
                $kj->kundli->kotaChakra($kundli);
            },
            'postPanchang' => static function (Client $kj) use ($place): void {
                $kj->panchang->daily(new PanchangRequest(
                    latitude: $place['latitude'],
                    longitude: $place['longitude'],
                    timezone: $place['timezone'],
                ));
            },
            'postPanchangMuhurta' => static function (Client $kj) use ($birth): void {
                $kj->panchang->muhurta(new MuhurtaRequest(birth: $birth));
            },
            'postPanchangMonth' => static function (Client $kj) use ($place): void {
                $kj->panchang->month(new PanchangMonthRequest(
                    latitude: $place['latitude'],
                    longitude: $place['longitude'],
                    month: '2026-09',
                    timezone: $place['timezone'],
                ));
            },
            'postEphemerisMonth' => static function (Client $kj) use ($place): void {
                $kj->ephemeris->month(new EphemerisMonthRequest(
                    latitude: $place['latitude'],
                    longitude: $place['longitude'],
                    month: '2026-09',
                    timezone: $place['timezone'],
                ));
            },
            'postCalendarVikramSamvat' => static function (Client $kj) use ($place): void {
                $kj->calendar->vikramSamvat(new VikramSamvatRequest(
                    datetime: '2026-09-22T12:00:00',
                    latitude: $place['latitude'],
                    longitude: $place['longitude'],
                    timezone: $place['timezone'],
                ));
            },
            'postJaiminiKarakas' => static function (Client $kj) use ($kundli): void {
                $kj->jaimini->karakas($kundli);
            },
            'postJaiminiArudhaPadas' => static function (Client $kj) use ($kundli): void {
                $kj->jaimini->arudhaPadas($kundli);
            },
            'postJaiminiAspects' => static function (Client $kj) use ($kundli): void {
                $kj->jaimini->aspects($kundli);
            },
            'postJaiminiKarakamsha' => static function (Client $kj) use ($kundli): void {
                $kj->jaimini->karakamsha($kundli);
            },
            'postKpChart' => static function (Client $kj) use ($kundli): void {
                $kj->kp->chart($kundli);
            },
            'postVarshphal' => static function (Client $kj) use ($varshphal): void {
                $kj->varshphal->get($varshphal);
            },
            'postVarshphalBala' => static function (Client $kj) use ($varshphal): void {
                $kj->varshphal->bala($varshphal);
            },
            'postVarshphalSahams' => static function (Client $kj) use ($varshphal): void {
                $kj->varshphal->sahams($varshphal);
            },
            'postVarshphalYogas' => static function (Client $kj) use ($varshphal): void {
                $kj->varshphal->yogas($varshphal);
            },
            'postVarshphalDasha' => static function (Client $kj) use ($varshphal): void {
                $kj->varshphal->dasha($varshphal);
            },
            'postTransitNow' => static function (Client $kj) use ($place): void {
                $kj->transit->now(new TransitNowRequest(
                    latitude: $place['latitude'],
                    longitude: $place['longitude'],
                    timezone: $place['timezone'],
                ));
            },
            'postTransitScan' => static function (Client $kj) use ($birth): void {
                $kj->transit->scan(new TransitScanRequest(
                    birth: $birth,
                    from: '2026-01-01T00:00:00Z',
                    to: '2026-02-01T00:00:00Z',
                ));
            },
            'postTransitEvents' => static function (Client $kj): void {
                $kj->transit->events(new TransitEventsRequest(year: 2026));
            },
            'postMatchAshtakoot' => static function (Client $kj) use ($birth): void {
                $kj->match->ashtakoot(new MatchAshtakootRequest(bride: $birth, groom: $birth));
            },
            'postMatchCompare' => static function (Client $kj) use ($birth): void {
                $kj->match->compare(new MatchCompareRequest(birth: $birth, partner: $birth));
            },
            'postMatchBatch' => static function (Client $kj) use ($birth): void {
                $kj->match->batch(new MatchBatchRequest(
                    pairs: [new MatchBatchRequestPairsItem(bride: $birth, groom: $birth)],
                ));
            },
            'postReportsLagna' => static function (Client $kj) use ($birth): void {
                $kj->reports->lagna(new ReportLagnaRequest(birth: $birth));
            },
            'postReportsNakshatra' => static function (Client $kj): void {
                $kj->reports->nakshatra(new ReportNakshatraRequest(nakshatra: 'ashwini'));
            },
            'postReportsHouseLords' => static function (Client $kj) use ($birth): void {
                $kj->reports->houseLords(new KundliRequest(birth: $birth));
            },
            'postReportsGrahas' => static function (Client $kj) use ($birth): void {
                $kj->reports->grahas(new KundliRequest(birth: $birth));
            },
            'postReportsYogas' => static function (Client $kj) use ($birth): void {
                $kj->reports->yogas(new KundliRequest(birth: $birth));
            },
            'postReportsVimshottari' => static function (Client $kj) use ($birth): void {
                $kj->reports->vimshottari(new KundliRequest(birth: $birth));
            },
            'postReportsVarshphal' => static function (Client $kj) use ($birth): void {
                $kj->reports->varshphal(new VarshphalRequest(birth: $birth, year: 2026));
            },
            'postReportsLifeAreas' => static function (Client $kj) use ($birth): void {
                $kj->reports->lifeAreas(new KundliRequest(birth: $birth));
            },
            'postReportsKundli' => static function (Client $kj) use ($birth): void {
                $kj->reports->kundli(new ReportKundliRequest(birth: $birth, parts: ['lagna', 'yogas']));
            },
            'postHoroscope' => static function (Client $kj): void {
                $kj->horoscope(new HoroscopeRequest(sign: 'aries'));
            },
            // PDFs — the bytes, not an envelope. The stub's JSON body comes
            // back as the file's bytes, which is all this test needs.
            'postPdfKundli' => static function (Client $kj) use ($birth): void {
                $kj->pdf->kundli(new PdfKundliRequest(
                    birth: $birth,
                    options: new PdfKundliOptions(language: ['en', 'hi'], houseSystem: HouseSystem::KP),
                    template: 'modern',
                    name: 'Ravi Kumar',
                    edition: 'professional',
                    vargas: ['d1', 'd9'],
                ));
            },
            'postPdfMatch' => static function (Client $kj) use ($birth): void {
                $kj->pdf->match(new PdfMatchRequest(
                    bride: $birth,
                    groom: $birth,
                    name: 'Sita',
                    partnerName: 'Ram',
                ));
            },
            'postPdfVarshphal' => static function (Client $kj) use ($birth): void {
                $kj->pdf->varshphal(new PdfVarshphalRequest(
                    birth: $birth,
                    year: 2026,
                    chartStyle: 'south',
                    name: 'Ravi Kumar',
                ));
            },
            'postPdfPanchangMonth' => static function (Client $kj) use ($place): void {
                $kj->pdf->panchangMonth(new PdfPanchangMonthRequest(
                    latitude: $place['latitude'],
                    longitude: $place['longitude'],
                    month: '2026-09',
                    timezone: $place['timezone'],
                    template: 'minimal',
                ));
            },
        ];
    }

    /**
     * Every operation the snapshot documents: verb, path and the query
     * parameters it declares.
     *
     * @return array<string, array{method: string, path: string, parameters: list<string>}>
     */
    private static function snapshot(): array
    {
        // PHPUnit runs from the package root; the snapshot is the workspace's,
        // shared with the TypeScript and Dart SDKs and with the generator.
        $raw = file_get_contents(__DIR__ . '/../../../openapi/openapi.json');
        self::assertIsString($raw, 'openapi/openapi.json is missing');
        $document = json_decode($raw, true, 512, JSON_THROW_ON_ERROR);
        self::assertIsArray($document);
        $paths = $document['paths'] ?? null;
        self::assertIsArray($paths);

        $found = [];
        /** @var mixed $byMethod */
        foreach ($paths as $path => $byMethod) {
            self::assertIsArray($byMethod);
            /** @var mixed $operation */
            foreach ($byMethod as $verb => $operation) {
                self::assertIsArray($operation);
                $id = $operation['operationId'] ?? null;
                self::assertIsString($id);
                $parameters = [];
                /** @var mixed $parameter */
                foreach ($operation['parameters'] ?? [] as $parameter) {
                    if (is_array($parameter) && ($parameter['in'] ?? null) === 'query') {
                        $name = $parameter['name'] ?? null;
                        self::assertIsString($name);
                        $parameters[] = $name;
                    }
                }
                $found[$id] = [
                    'method' => strtoupper((string) $verb),
                    'path' => (string) $path,
                    'parameters' => $parameters,
                ];
            }
        }

        return $found;
    }

    /**
     * Calls every operation once and returns what went out, by `operationId`.
     *
     * @return array<string, HttpRequest>
     */
    private static function runEveryOperation(string $apiKey): array
    {
        $sent = [];
        foreach (self::calls() as $id => $call) {
            // An empty envelope: enough for the transport to accept, never
            // enough for a generated document to read. The request is what this
            // test is about, so a `bad_response` on the way back is expected.
            $http = new RecordingHttpClient(RecordingHttpClient::json(
                200,
                '{"status":"ok","data":{},"meta":{}}',
            ));
            $kj = Client::withTransport(new Transport(
                apiKey: $apiKey,
                httpClient: $http,
                maxRetries: 0,
                sleep: $http->sleeper(),
            ));

            try {
                $call($kj);
            } catch (KaaljyotiException $error) {
                self::assertSame(
                    KaaljyotiException::BAD_RESPONSE,
                    $error->code(),
                    "{$id} failed for a reason other than the empty body: {$error->getMessage()}",
                );
            }

            $sent[$id] = $http->last();
        }

        return $sent;
    }

    /** `/v1/reference/{list}` as a pattern a concrete path must match. */
    private static function pathPattern(string $path): string
    {
        $escaped = implode(
            '[^/]+',
            array_map(
                static fn (string $part): string => preg_quote($part, '#'),
                preg_split('/\{[^}]+\}/', $path) ?: [$path],
            ),
        );

        return '#^' . $escaped . '$#';
    }

    public function testTheGeneratedTableIsTheSnapshotOperationForOperation(): void
    {
        $fromSnapshot = [];
        foreach (self::snapshot() as $id => $operation) {
            $fromSnapshot[] = "{$operation['method']} {$operation['path']} ({$id})";
        }
        $fromTable = array_map(
            static fn (Operation $operation): string => (string) $operation,
            Operations::all(),
        );

        sort($fromSnapshot);
        sort($fromTable);
        self::assertSame($fromSnapshot, $fromTable);
    }

    public function testTheOneHardCodedPathInTheTransportIsStillTheHealthPath(): void
    {
        // The transport recognises the bare, envelope-less answer by its path,
        // which is the only path in the package that does not come from the
        // table. A snapshot that moved it would otherwise make `health()` fail
        // as "not a Kaal Jyoti envelope" and nothing would have said so.
        self::assertSame(Operations::byId('getHealth')->path, Transport::HEALTH_PATH);
    }

    public function testEveryOperationTheApiDocumentsHasAMethodOnTheClient(): void
    {
        $documented = array_keys(self::snapshot());
        $called = array_keys(self::calls());
        sort($documented);
        sort($called);

        self::assertSame($documented, $called);
    }

    public function testEveryOperationGoesToTheVerbAndThePathTheDocumentNames(): void
    {
        $sent = self::runEveryOperation(self::PUB_KEY);
        $documented = self::snapshot();

        self::assertCount(count($documented), $sent, 'every operation is called exactly once');

        foreach ($documented as $id => $operation) {
            $request = $sent[$id] ?? null;
            self::assertNotNull($request, "{$id} was never called");
            self::assertSame($operation['method'], $request->method, $id);

            $path = parse_url($request->url, PHP_URL_PATH);
            self::assertIsString($path);
            self::assertMatchesRegularExpression(
                self::pathPattern($operation['path']),
                $path,
                "{$id} went to {$path}, not {$operation['path']}",
            );
        }
    }

    public function testNoQueryParameterTheDocumentDidNotDeclareBeyondTheKey(): void
    {
        $sent = self::runEveryOperation(self::PUB_KEY);

        foreach (self::snapshot() as $id => $operation) {
            $query = [];
            parse_str((string) parse_url($sent[$id]->url, PHP_URL_QUERY), $query);

            foreach (array_keys($query) as $name) {
                self::assertTrue(
                    $name === 'key' || in_array($name, $operation['parameters'], true),
                    "{$id} sent an undeclared \"{$name}\"",
                );
            }
            // A publishable key travels in the query, because a browser cannot
            // send the header.
            self::assertSame(self::PUB_KEY, $query['key'] ?? null, $id);
        }
    }

    public function testASecretKeyIsNeverInAUrlOnAnyOperation(): void
    {
        $sent = self::runEveryOperation(self::SECRET_KEY);

        foreach ($sent as $id => $request) {
            $query = [];
            parse_str((string) parse_url($request->url, PHP_URL_QUERY), $query);

            self::assertArrayNotHasKey('key', $query, $id);
            self::assertStringNotContainsString(self::SECRET_KEY, $request->url, $id);
            self::assertSame('Bearer ' . self::SECRET_KEY, $request->headers['Authorization'], $id);
        }
    }

    public function testAPostCarriesAJsonBodyAndAGetCarriesNone(): void
    {
        $sent = self::runEveryOperation(self::SECRET_KEY);

        foreach (self::snapshot() as $id => $operation) {
            $request = $sent[$id];
            if ($operation['method'] === 'POST') {
                self::assertIsString($request->body, $id);
                self::assertIsArray(json_decode($request->body, true, 512, JSON_THROW_ON_ERROR), $id);
            } else {
                self::assertNull($request->body, $id);
            }
        }
    }

    public function testTheReferenceListIsAPathSegmentNotAParameter(): void
    {
        $sent = self::runEveryOperation(self::SECRET_KEY);

        self::assertSame(
            '/v1/reference/signs',
            parse_url($sent['getReferenceList']->url, PHP_URL_PATH),
        );

        $query = [];
        parse_str((string) parse_url($sent['getReferenceList']->url, PHP_URL_QUERY), $query);
        self::assertSame(['language' => 'en,hi'], $query);
    }
}
