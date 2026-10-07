<?php

declare(strict_types=1);

namespace Kaaljyoti\Tests;

use Kaaljyoti\Generated\LabelledId;
use Kaaljyoti\Generated\Operations;
use Kaaljyoti\Generated\Version;
use Kaaljyoti\Models\Ayanamsa;
use Kaaljyoti\Models\Birth;
use Kaaljyoti\Models\CalculationOptions;
use Kaaljyoti\Models\ChartDocument;
use Kaaljyoti\Models\ChartStyle;
use Kaaljyoti\Models\DailyPanchangDocument;
use Kaaljyoti\Models\DashaRequest;
use Kaaljyoti\Models\Disclaimer;
use Kaaljyoti\Models\ErrorBody;
use Kaaljyoti\Models\HoroscopeDocument;
use Kaaljyoti\Models\HouseSystem;
use Kaaljyoti\Models\KundliDocument;
use Kaaljyoti\Models\KundliReportDocument;
use Kaaljyoti\Models\KundliRequest;
use Kaaljyoti\Models\LifeAreasDocument;
use Kaaljyoti\Models\Meta;
use Kaaljyoti\Models\MuhurtaDocument;
use Kaaljyoti\Models\MuhurtaRequest;
use Kaaljyoti\Models\PdfKundliOptions;
use Kaaljyoti\Models\PdfKundliRequest;
use Kaaljyoti\Models\ReadingGrahasDocument;
use Kaaljyoti\Models\ReadingHouseLordsDocument;
use Kaaljyoti\Models\ReadingLagnaDocument;
use Kaaljyoti\Models\ReadingNakshatraDocument;
use Kaaljyoti\Models\ReadingYogasDocument;
use Kaaljyoti\Models\TimeWindow;
use Kaaljyoti\Models\Varga;
use Kaaljyoti\Models\VargasRequest;
use Kaaljyoti\Models\VarshphalReadingDocument;
use Kaaljyoti\Models\VimshottariReadingDocument;
use PHPUnit\Framework\TestCase;
use UnexpectedValueException;

/**
 * The generated models against real staging envelopes.
 *
 * `tests/fixtures/*.json` are recorded answers, not hand-written samples, so
 * this is the test that catches a schema the engine has quietly outgrown: a
 * field the snapshot calls required and the engine now omits fails
 * `fromArray()`, and a field the snapshot does not mention at all disappears
 * in the round trip and fails the comparison.
 */
final class ModelsTest extends TestCase
{
    /**
     * @return array<string, mixed>
     */
    private static function fixture(string $name): array
    {
        $path = __DIR__ . "/fixtures/{$name}.json";
        $raw = file_get_contents($path);
        self::assertIsString($raw, "cannot read {$path}");
        $decoded = json_decode($raw, true, 512, JSON_THROW_ON_ERROR);
        self::assertIsArray($decoded);

        /** @var array<string, mixed> $decoded */
        return $decoded;
    }

    /**
     * The `data` member of a recorded envelope.
     *
     * @return array<string, mixed>
     */
    private static function dataOf(string $name): array
    {
        $data = self::fixture($name)['data'] ?? null;
        self::assertIsArray($data);

        /** @var array<string, mixed> $data */
        return $data;
    }

    /**
     * `toArray()` omits nulls; the recorded envelopes carry them explicitly.
     *
     * Both are the same document — the gateway's `additionalProperties: false`
     * means an absent key and a null key say the same thing — so the original
     * is stripped before the two are compared, rather than teaching
     * `toArray()` to write nulls that the API's "either timezone or
     * utc_offset" rules would then trip over.
     */
    private static function stripNulls(mixed $value): mixed
    {
        if (!is_array($value)) {
            return $value;
        }
        $out = [];
        foreach ($value as $key => $item) {
            if ($item !== null) {
                $out[$key] = self::stripNulls($item);
            }
        }

        return $out;
    }

    /**
     * Asserts that `toArray()` of a parsed fixture is the fixture again.
     *
     * `assertEquals`, not `assertSame`: JSON has one number type and PHP has
     * two, so a `number` field whose recorded value happens to be whole comes
     * back as an `int` and goes out as a `float`. That is not a round-trip
     * failure, and it is the only difference this comparison forgives.
     *
     * @param array<string, mixed> $original
     * @param array<string, mixed> $roundTripped
     */
    private static function assertRoundTrip(array $original, array $roundTripped): void
    {
        self::assertEquals(self::stripNulls($original), $roundTripped);
    }

    public function testKundliNamesIdsInsteadOfAnsweringBareStrings(): void
    {
        $document = KundliDocument::fromArray(self::dataOf('kundli'));

        self::assertInstanceOf(LabelledId::class, $document->lagnaSign);
        self::assertSame('cancer', $document->lagnaSign->id);
        self::assertSame('Cancer', $document->lagnaSign->name);
        self::assertSame('कर्क', $document->lagnaSign->names['hi'] ?? null);
        self::assertSame('purva_ashadha', $document->moonNakshatra->id);
    }

    public function testKundliTypesTheNumbersAndTheMaps(): void
    {
        $document = KundliDocument::fromArray(self::dataOf('kundli'));

        self::assertIsFloat($document->ascendant);
        self::assertSame("99°02'48.2\"", $document->ascendantDms);
        self::assertCount(12, $document->houseCusps);
        self::assertIsInt($document->houses['sun'] ?? null);

        $sun = $document->positions['sun'] ?? null;
        self::assertNotNull($sun);
        self::assertSame('sun', $sun->planet->id);
        self::assertSame('aries', $sun->sign->id);
        self::assertFalse($sun->isRetrograde);
        self::assertSame(1, $sun->pada);
        self::assertEqualsWithDelta(29.4246, $sun->longitude, 0.0001);
    }

    public function testKundliCamelCasesTheWireKeys(): void
    {
        $document = KundliDocument::fromArray(self::dataOf('kundli'));

        self::assertSame('New Delhi', $document->birth->placeName);
        self::assertSame(330, $document->birth->utcOffsetMinutes);
    }

    public function testKundliRoundTrips(): void
    {
        $data = self::dataOf('kundli');
        self::assertRoundTrip($data, KundliDocument::fromArray($data)->toArray());
    }

    public function testPanchangCarriesBothTithisOfATwoTithiDay(): void
    {
        $document = DailyPanchangDocument::fromArray(self::dataOf('panchang'));

        self::assertCount(2, $document->tithis);
        self::assertSame('ekadashi', $document->tithis[0]->name->id);
        self::assertNull($document->tithis[0]->starts);
        self::assertSame('dwadashi', $document->tithis[1]->name->id);
        self::assertSame('shukla', $document->tithis[1]->paksha->id);
        self::assertNotNull($document->tithis[1]->starts);
    }

    public function testPanchangLabelsItsNamesFromTheirIndex(): void
    {
        $document = DailyPanchangDocument::fromArray(self::dataOf('panchang'));
        $day = $document->panchang;

        self::assertSame('ekadashi', $day->tithiName->id);
        self::assertSame(['en' => 'Ekadashi', 'hi' => 'एकादशी'], $day->tithiName->names);
        self::assertSame('mangalavara', $day->vara->id);
        self::assertSame('shukla', $day->paksha->id);
        self::assertSame('atiganda', $day->yogaName->id);
        self::assertSame('vishti', $day->karanaName->id);
        self::assertSame('भाद्रपद', $document->masa->monthName->names['hi'] ?? null);
    }

    public function testMuhurtaTakesABirthOrAPlace(): void
    {
        $byPlace = new MuhurtaRequest(latitude: 28.6139, longitude: 77.209, date: '2026-09-22');
        self::assertSame(
            ['latitude' => 28.6139, 'longitude' => 77.209, 'date' => '2026-09-22'],
            $byPlace->toArray(),
        );

        $byBirth = new MuhurtaRequest(
            birth: new Birth(datetime: '1990-05-14T10:30:00', latitude: 28.6, longitude: 77.2),
        );
        self::assertSame(['birth'], array_keys($byBirth->toArray()));
    }

    public function testPanchangSharesOneWindowTypeForEveryMuhurta(): void
    {
        $document = DailyPanchangDocument::fromArray(self::dataOf('panchang'));

        self::assertInstanceOf(TimeWindow::class, $document->abhijitMuhurta);
        self::assertInstanceOf(TimeWindow::class, $document->brahmaMuhurta);
        self::assertNotNull($document->rahuKalam?->start);
        self::assertSame(2083, $document->masa->samvatYear);
        self::assertFalse($document->masa->isAdhik);
        self::assertContains('mars', $document->placements['cancer'] ?? []);
    }

    public function testPanchangRoundTrips(): void
    {
        $data = self::dataOf('panchang');
        self::assertRoundTrip($data, DailyPanchangDocument::fromArray($data)->toArray());
    }

    public function testMuhurtaReadsTheChoghadiyaTable(): void
    {
        $document = MuhurtaDocument::fromArray(self::dataOf('muhurta'));

        $day = $document->choghadiya?->day;
        self::assertNotNull($day);
        self::assertCount(8, $day);
        self::assertSame('amrit', $day[0]->choghadiya);
        self::assertTrue($day[0]->good);
        // `planet` is `{"type": "null"}` in the snapshot: always absent.
        self::assertNull($day[0]->planet);
        self::assertTrue($document->abhijitApplies);
        self::assertStringStartsWith('1990-05-14T', $document->abhijit->start);
    }

    public function testMuhurtaRoundTrips(): void
    {
        $data = self::dataOf('muhurta');
        self::assertRoundTrip($data, MuhurtaDocument::fromArray($data)->toArray());
    }

    public function testChartCarriesTheSvgWhole(): void
    {
        $document = ChartDocument::fromArray(self::dataOf('chart'));

        self::assertStringStartsWith('<svg', $document->svg);
        self::assertSame(ChartStyle::NORTH, $document->style);
        self::assertSame(360, $document->size);
        self::assertTrue($document->showDegrees);
    }

    public function testChartRoundTrips(): void
    {
        $data = self::dataOf('chart');
        self::assertRoundTrip($data, ChartDocument::fromArray($data)->toArray());
    }

    public function testHoroscopeIsASummaryAndFiveAreasWithTheTransitsBehindThem(): void
    {
        $document = HoroscopeDocument::fromArray(self::dataOf('horoscope'));

        self::assertSame('aries', $document->sign->id);
        self::assertSame('day', $document->period);
        self::assertSame('2026-09-27T18:30:00.000Z', $document->from);
        self::assertSame('2026-09-28T18:30:00.000Z', $document->to);
        self::assertSame('care', $document->summary->level);
        self::assertNotEmpty($document->summary->text->hi);
        self::assertCount(5, $document->areas);
        self::assertSame('education', $document->areas[4]->area);

        self::assertCount(10, $document->basis);
        $moon = array_values(array_filter(
            $document->basis,
            static fn ($transit): bool => $transit->graha->id === 'moon',
        ));
        self::assertCount(2, $moon);
        self::assertNull($moon[0]->entered);
        self::assertSame('2026-09-28T04:46:38.438Z', $moon[0]->leaves);
        self::assertSame($moon[0]->leaves, $moon[1]->entered);
        self::assertSame('unfavourable', $moon[0]->nature);
        self::assertSame(
            'These predictions are indicative. For a reading of your own chart, consult an astrologer.',
            $document->disclaimer?->en,
        );
    }

    public function testHoroscopeRoundTrips(): void
    {
        $data = self::dataOf('horoscope');
        self::assertRoundTrip($data, HoroscopeDocument::fromArray($data)->toArray());
    }

    public function testThePersonalReportsRoundTrip(): void
    {
        $cases = [
            'reading-grahas' => ReadingGrahasDocument::fromArray(...),
            'reading-yogas' => ReadingYogasDocument::fromArray(...),
            'reading-vimshottari' => VimshottariReadingDocument::fromArray(...),
            'reading-varshphal' => VarshphalReadingDocument::fromArray(...),
            'reading-life-areas' => LifeAreasDocument::fromArray(...),
            'reading-kundli' => KundliReportDocument::fromArray(...),
        ];
        foreach ($cases as $name => $read) {
            $data = self::dataOf($name);
            self::assertRoundTrip($data, $read($data)->toArray());
        }
    }

    public function testReadingsKeyEveryTextByTheLanguagesAskedFor(): void
    {
        $lagna = ReadingLagnaDocument::fromArray(self::dataOf('reading-lagna'));
        self::assertSame('leo', $lagna->lagna?->sign->id);
        self::assertStringStartsWith('With Leo rising', (string) $lagna->lagna?->entry->text->en);
        self::assertNull($lagna->lagna?->entry->text->hi);

        $nakshatra = ReadingNakshatraDocument::fromArray(self::dataOf('reading-nakshatra'));
        self::assertSame('purva_ashadha', $nakshatra->nakshatra?->nakshatra->id);
        self::assertNotEmpty($nakshatra->nakshatra?->entry->text->en);
        self::assertNotEmpty($nakshatra->nakshatra?->entry->text->hi);
        self::assertNotEmpty($nakshatra->disclaimer?->hi);
    }

    public function testReadingsRoundTrip(): void
    {
        foreach (['reading-lagna', 'reading-nakshatra', 'reading-nakshatra-named'] as $name) {
            $data = self::dataOf($name);
            $document = str_starts_with($name, 'reading-lagna')
                ? ReadingLagnaDocument::fromArray($data)
                : ReadingNakshatraDocument::fromArray($data);
            self::assertRoundTrip($data, $document->toArray());
        }
    }

    public function testHouseLordsKeepHouseOrderAndOnlyTheLanguagesAskedFor(): void
    {
        $both = ReadingHouseLordsDocument::fromArray(self::dataOf('reading-house-lords'));
        $lords = $both->houseLords ?? [];
        self::assertCount(12, $lords);
        self::assertSame('sagittarius', $lords[6]->sign->id);
        self::assertSame('jupiter', $lords[6]->lord->id);
        self::assertSame(10, $lords[6]->inHouse);
        self::assertNotNull($both->disclaimer?->en);

        // Hindi alone, and the disclaimer turned off: the names come back in
        // Hindi, there is no English text, and no closing line at all.
        $hindi = ReadingHouseLordsDocument::fromArray(self::dataOf('reading-house-lords-hi'));
        $first = ($hindi->houseLords ?? [])[0] ?? null;
        self::assertNotNull($first);
        self::assertSame('मिथुन', $first->sign->name);
        self::assertNull($first->entry->text->en);
        self::assertNotEmpty($first->entry->text->hi);
        self::assertNull($hindi->disclaimer);
    }

    public function testHouseLordsRoundTrip(): void
    {
        foreach (['reading-house-lords', 'reading-house-lords-hi'] as $name) {
            $data = self::dataOf($name);
            self::assertRoundTrip($data, ReadingHouseLordsDocument::fromArray($data)->toArray());
        }
    }

    public function testTheDisclaimerOptionIsAKeywordOrANamedAstrologer(): void
    {
        self::assertSame(['disclaimer' => 'off'], (new CalculationOptions(disclaimer: 'off'))->toArray());
        self::assertSame(
            ['disclaimer' => ['name' => 'Acharya Amit Verma', 'url' => 'https://kaaljyoti.com']],
            (new CalculationOptions(
                disclaimer: new Disclaimer(name: 'Acharya Amit Verma', url: 'https://kaaljyoti.com'),
            ))->toArray(),
        );
        self::assertSame(
            ['disclaimer' => ['name' => 'Acharya Amit Verma']],
            (new CalculationOptions(disclaimer: new Disclaimer(name: 'Acharya Amit Verma')))->toArray(),
        );

        self::assertSame('default', CalculationOptions::fromArray(['disclaimer' => 'default'])->disclaimer);
        self::assertEquals(
            new Disclaimer(name: 'Acharya Amit Verma'),
            CalculationOptions::fromArray(['disclaimer' => ['name' => 'Acharya Amit Verma']])->disclaimer,
        );
        self::assertNull(CalculationOptions::fromArray([])->disclaimer);
    }

    public function testErrorNamesTheCodeAndTheOffendingField(): void
    {
        $body = self::fixture('error');
        $error = ErrorBody::fromArray($body);

        self::assertSame('error', $error->status);
        self::assertSame('validation_error', $error->error->code);
        self::assertSame('Invalid input', $error->error->message);
        self::assertSame('options.language', $error->error->field);
        self::assertStringEndsWith('#validation_error', $error->error->docs);
    }

    public function testErrorRoundTrips(): void
    {
        $body = self::fixture('error');
        self::assertRoundTrip($body, ErrorBody::fromArray($body)->toArray());
    }

    public function testMetaReportsTheZoneTheAnswerWasComputedIn(): void
    {
        $raw = self::fixture('kundli')['meta'] ?? null;
        self::assertIsArray($raw);

        /** @var array<string, mixed> $raw */
        $meta = Meta::fromArray($raw);

        self::assertFalse($meta->cached);
        self::assertIsInt($meta->computeMs);
        self::assertSame('lahiri', $meta->ayanamsa->id);
        self::assertSame('Asia/Kolkata', $meta->timezone->name);
        self::assertSame('+05:30', $meta->timezone->utcOffset);
        self::assertSame('given', $meta->timezone->source);
        self::assertSame([], $meta->languageFallback);
    }

    public function testRequestsSendExactlyTheQuickStartBodyWithNoNullKeys(): void
    {
        $request = new KundliRequest(
            birth: new Birth(
                datetime: '1990-05-14T10:30:00',
                timezone: 'Asia/Kolkata',
                latitude: 28.6139,
                longitude: 77.209,
            ),
            options: new CalculationOptions(language: ['en', 'hi']),
        );

        // `assertSame`, so the key order is part of what is asserted: the
        // constructor lists the fields the schema requires first, and
        // `toArray()` writes them in that order.
        self::assertSame([
            'birth' => [
                'datetime' => '1990-05-14T10:30:00',
                'latitude' => 28.6139,
                'longitude' => 77.209,
                'timezone' => 'Asia/Kolkata',
            ],
            // Two languages go out as the array…
            'options' => ['language' => ['en', 'hi']],
        ], $request->toArray());
    }

    public function testRequestsWriteASingleLanguageAsAString(): void
    {
        // …and one goes out as the bare string the API documents.
        self::assertSame(
            ['language' => 'en'],
            (new CalculationOptions(language: ['en']))->toArray(),
        );
    }

    public function testRequestsReadALanguageBackAsAListEitherWay(): void
    {
        self::assertSame(['hi'], CalculationOptions::fromArray(['language' => 'hi'])->language);
        self::assertSame(
            ['en', 'hi'],
            CalculationOptions::fromArray(['language' => ['en', 'hi']])->language,
        );
    }

    public function testRequestsLeaveOutEveryOptionTheCallerDidNotSet(): void
    {
        $request = new KundliRequest(
            birth: new Birth(datetime: '1990-05-14T10:30:00', latitude: 28.6139, longitude: 77.209),
        );

        self::assertSame(['birth'], array_keys($request->toArray()));
        $birth = $request->toArray()['birth'];
        self::assertIsArray($birth);
        self::assertSame(['datetime', 'latitude', 'longitude'], array_keys($birth));
    }

    public function testLabelledIdOmitsAbsentNames(): void
    {
        self::assertSame(
            ['id' => 'sun', 'name' => 'Sun'],
            (new LabelledId(id: 'sun', name: 'Sun'))->toArray(),
        );
        self::assertEquals(
            new LabelledId(id: 'sun', name: 'Sun', names: ['en' => 'Sun']),
            LabelledId::fromArray(['id' => 'sun', 'name' => 'Sun', 'names' => ['en' => 'Sun']]),
        );
    }

    public function testAMalformedPayloadSaysWhatItWantedAndWhatItGot(): void
    {
        $this->expectException(UnexpectedValueException::class);
        $this->expectExceptionMessage('expected a string, got int (42)');

        LabelledId::fromArray(['id' => 42, 'name' => 'Sun']);
    }

    public function testTheGeneratedConstantsPinBothVersions(): void
    {
        self::assertMatchesRegularExpression('/^\d+\.\d+\.\d+/', Version::SDK);
        self::assertSame('0.16.0', Version::OPENAPI);
    }

    public function testTheGeneratedConstantsSpellTheEnumsTheSchemaLists(): void
    {
        self::assertSame('whole_sign', HouseSystem::WHOLE_SIGN);
        self::assertSame(['whole_sign', 'placidus', 'porphyry', 'equal', 'sripati', 'kp'], HouseSystem::VALUES);
        self::assertSame(['north', 'south', 'circular'], ChartStyle::VALUES);
        self::assertCount(47, Ayanamsa::VALUES);
        self::assertSame('lahiri', Ayanamsa::LAHIRI);
        self::assertSame('aryabhata_522', Ayanamsa::ARYABHATA_522);
        self::assertCount(16, Varga::VALUES);
        self::assertSame('d1', Varga::VALUES[0]);
        self::assertSame('d60', Varga::D60);
    }

    public function testTheEnumPropertiesStayPlainStrings(): void
    {
        // A slug the caller already holds goes in as it is.
        self::assertSame(['ayanamsa' => 'raman'], (new CalculationOptions(ayanamsa: 'raman'))->toArray());
        $birth = new Birth(datetime: '1990-05-14T10:30:00', latitude: 28.6, longitude: 77.2);
        self::assertSame(
            ['d9', 'd10'],
            (new VargasRequest(birth: $birth, vargas: [Varga::D9, 'd10']))->toArray()['vargas'],
        );
        self::assertSame(
            3,
            (new DashaRequest(birth: $birth, system: 'vimshottari', levels: 3))->toArray()['levels'],
        );
    }

    public function testOnlyTheKundliPdfTakesAHouseSystem(): void
    {
        // The API answers 400 to `options.house_system` anywhere but the kundli
        // PDF, so the shared options do not offer it.
        self::assertFalse(property_exists(CalculationOptions::class, 'houseSystem'));
        $request = new PdfKundliRequest(
            birth: new Birth(datetime: '1990-05-14T10:30:00', latitude: 28.6, longitude: 77.2),
            options: new PdfKundliOptions(language: ['en'], houseSystem: HouseSystem::WHOLE_SIGN),
        );
        self::assertSame(['language' => 'en', 'house_system' => 'whole_sign'], $request->toArray()['options']);
        self::assertEquals($request, PdfKundliRequest::fromArray($request->toArray()));
    }

    public function testTheOperationTableIsTheSnapshotsOwn(): void
    {
        $raw = file_get_contents(__DIR__ . '/../../../openapi/openapi.json');
        self::assertIsString($raw);
        $snapshot = json_decode($raw, true, 512, JSON_THROW_ON_ERROR);
        self::assertIsArray($snapshot);

        $wanted = [];
        $paths = $snapshot['paths'] ?? null;
        self::assertIsArray($paths);
        foreach ($paths as $item) {
            self::assertIsArray($item);
            foreach ($item as $operation) {
                if (is_array($operation) && isset($operation['operationId'])) {
                    self::assertIsString($operation['operationId']);
                    $wanted[] = $operation['operationId'];
                }
            }
        }

        self::assertCount(58, Operations::all());
        self::assertSame(
            $wanted,
            array_map(static fn ($operation): string => $operation->id, Operations::all()),
        );
        self::assertSame('POST', Operations::byId('postKundli')->method);
        self::assertSame('/v1/kundli', Operations::byId('postKundli')->path);
        self::assertSame('KundliRequest', Operations::byId('postKundli')->requestType);
        self::assertSame('KundliDocument', Operations::byId('postKundli')->documentType);
        // `GET /v1/reference/{list}` rows are open by design: no document class.
        self::assertNull(Operations::byId('getReferenceList')->documentType);
    }
}
