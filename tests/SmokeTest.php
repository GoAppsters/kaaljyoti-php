<?php

declare(strict_types=1);

namespace Kaaljyoti\Tests;

use Kaaljyoti\Client;
use Kaaljyoti\KaaljyotiException;
use Kaaljyoti\Models\Birth;
use Kaaljyoti\Models\CalculationOptions;
use Kaaljyoti\Models\HoroscopeRequest;
use Kaaljyoti\Models\KundliChartRequest;
use Kaaljyoti\Models\KundliRequest;
use Kaaljyoti\Models\PanchangRequest;
use Kaaljyoti\Models\ReportKundliRequest;
use Kaaljyoti\Models\ReportLagnaRequest;
use Kaaljyoti\Models\VarshphalRequest;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

/**
 * The SDK against the real gateway. Opt-in, and skipped by default.
 *
 * Every other test in this package replaces the socket, which proves that the
 * client sends what it means to send and not that the API answers it. This one
 * is the other half: it runs against staging through the real
 * {@see \Kaaljyoti\Http\CurlClient}, costs about fifty credits, and is the
 * thing to run after a snapshot regeneration or a gateway deploy.
 *
 * ```sh
 * KJ_SMOKE=1 KJ_API_KEY=kj_pub_… vendor/bin/phpunit --group smoke
 * ```
 *
 * - `KJ_BASE_URL` — default `https://api-staging.kaaljyoti.com`.
 * - `KJ_ORIGIN` — default `http://localhost:3000`. Sent as `Origin` when the
 *   key is publishable, because a publishable key is only accepted from an
 *   origin it lists and a PHP process sends no `Origin` of its own.
 */
#[Group('smoke')]
final class SmokeTest extends TestCase
{
    private const STAGING = 'https://api-staging.kaaljyoti.com';
    private const DEFAULT_ORIGIN = 'http://localhost:3000';

    private Client $kj;

    protected function setUp(): void
    {
        $key = getenv('KJ_API_KEY');
        if (getenv('KJ_SMOKE') !== '1' || !is_string($key) || $key === '') {
            self::markTestSkipped('set KJ_SMOKE=1 and KJ_API_KEY=kj_pub_… (or kj_test_…) to run this');
        }

        $baseUrl = getenv('KJ_BASE_URL');
        $origin = getenv('KJ_ORIGIN');

        $this->kj = new Client(
            apiKey: $key,
            baseUrl: is_string($baseUrl) && $baseUrl !== '' ? $baseUrl : self::STAGING,
            // The header the SDK cannot set for a caller: a browser sends it
            // without being asked, and the gateway checks a publishable key
            // against the origins it was created with.
            headers: str_starts_with($key, 'kj_pub_')
                ? ['Origin' => is_string($origin) && $origin !== '' ? $origin : self::DEFAULT_ORIGIN]
                : [],
        );
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

    public function testHealthAnswersWithoutAnEnvelope(): void
    {
        $answer = $this->kj->health();

        self::assertSame('ok', $answer->data->status);
        self::assertNotSame('', $answer->data->engine);
        self::assertNotSame('', $answer->data->ephemeris);
        self::assertGreaterThan(0, $answer->data->ops);
        self::assertNull($answer->meta);
    }

    public function testAReferenceTableAnswersInBothLanguagesAskedFor(): void
    {
        $answer = $this->kj->reference('signs', ['en', 'hi']);

        self::assertCount(12, $answer->data);
        $aries = $answer->data[0];
        self::assertSame('aries', $aries['id'] ?? null);
        $names = $aries['names'] ?? null;
        self::assertIsArray($names);
        self::assertArrayHasKey('en', $names);
        self::assertArrayHasKey('hi', $names);
        self::assertSame(['en', 'hi'], $answer->meta->language);
    }

    public function testTimezoneKnowsTheWarTimeOffset(): void
    {
        $answer = $this->kj->timezone(28.6139, 77.209, '1944-03-15T10:00:00');

        self::assertSame('Asia/Kolkata', $answer->data->name);
        self::assertSame('+06:30', $answer->data->utcOffset, 'India ran on +06:30 during the war');
        self::assertSame('derived', $answer->data->source);
    }

    public function testTheDailyPanchangAnswersForAPlaceAndADay(): void
    {
        $answer = $this->kj->panchang->daily(new PanchangRequest(
            latitude: 28.6139,
            longitude: 77.209,
            timezone: 'Asia/Kolkata',
            date: '2026-09-22',
        ));

        self::assertNotEmpty($answer->data->tithis);
        self::assertNotSame('', $answer->data->panchang->vara);
        self::assertNotSame('', $answer->data->panchang->nakshatra->id);
        self::assertSame('Asia/Kolkata', $answer->meta->timezone->name);
        self::assertNotNull($answer->requestId);
        // The cost twice over: `meta.credits` and `X-KJ-Credits`.
        self::assertGreaterThan(0, $answer->meta->credits);
        self::assertSame($answer->meta->credits, $answer->credits);
        // The balance goes to secret keys only, never to a page's key.
        if (str_starts_with((string) getenv('KJ_API_KEY'), 'kj_pub_')) {
            self::assertNull($answer->creditsRemaining);
        } else {
            self::assertGreaterThanOrEqual(0, $answer->creditsRemaining);
        }
    }

    public function testThePriceListAnswersForFree(): void
    {
        $answer = $this->kj->reference('credits');

        $prices = array_column($answer->data, null, 'route');
        self::assertGreaterThan(0, $prices['/v1/kundli']['credits'] ?? 0);
        self::assertSame('pair', $prices['/v1/match/batch']['per'] ?? null);
        self::assertSame('part', $prices['/v1/reports/kundli']['per'] ?? null);
        // A free route is not metered, so there is no cost header on it.
        self::assertNull($answer->credits);
    }

    public function testAKundliAnswersWithTheAscendantTheDocsQuote(): void
    {
        $answer = $this->kj->kundli->get(new KundliRequest(
            birth: self::birth(),
            options: new CalculationOptions(language: ['en', 'hi']),
        ));

        self::assertSame('cancer', $answer->data->lagnaSign->id);
        self::assertArrayHasKey('hi', $answer->data->lagnaSign->names ?? []);
        self::assertArrayHasKey('sun', $answer->data->positions);
        self::assertSame('+05:30', $answer->meta->timezone->utcOffset);
        self::assertSame('given', $answer->meta->timezone->source);
    }

    public function testAChartAnswersAsMarkupWhenAskedForAsSvg(): void
    {
        $answer = $this->kj->kundli->chartSvg(new KundliChartRequest(birth: self::birth(), size: 360));

        self::assertStringStartsWith('<svg', $answer->data);
        self::assertStringContainsString('</svg>', $answer->data);
        self::assertNull($answer->meta);
    }

    public function testAHoroscopeIsASummaryAndFiveAreas(): void
    {
        $answer = $this->kj->horoscope(new HoroscopeRequest(sign: 'aries', date: '2026-09-28'));

        self::assertSame('aries', $answer->data->sign->id);
        self::assertSame('2026-09-27T18:30:00.000Z', $answer->data->from);
        self::assertContains($answer->data->summary->level, ['favourable', 'mixed', 'care']);
        self::assertCount(5, $answer->data->areas);
        self::assertNotNull($answer->data->disclaimer?->en);
    }

    public function testAKundliReportOfTwoPartsIsPricedAsTwo(): void
    {
        $answer = $this->kj->reports->kundli(new ReportKundliRequest(birth: self::birth(), parts: ['lagna', 'yogas']));

        self::assertSame(['lagna', 'yogas'], $answer->data->parts);
        // Two parts at 5 credits each, unless the price list has been changed.
        self::assertSame(10, $answer->meta->credits);
    }

    public function testThePersonalReportsAnswerOnEveryPlan(): void
    {
        $dashas = $this->kj->reports->vimshottari(new KundliRequest(birth: self::birth()));
        self::assertCount(1, array_filter($dashas->data->periods, static fn ($p): bool => $p->current));

        $year = $this->kj->reports->varshphal(new VarshphalRequest(birth: self::birth(), year: 2026));
        self::assertSame(2026, $year->data->year);
        self::assertCount(7, $year->data->areas);
    }

    public function testALagnaReadingAnswersForAPickedSign(): void
    {
        $answer = $this->kj->reports->lagna(new ReportLagnaRequest(
            sign: 'leo',
            options: new CalculationOptions(disclaimer: 'off'),
        ));

        self::assertSame('leo', $answer->data->lagna?->sign->id);
        self::assertNotEmpty($answer->data->lagna?->entry->text->en);
        self::assertNull($answer->data->disclaimer, '"off" leaves the disclaimer out');
    }

    public function testTheHouseLordsAnswerTwelveHousesInOrder(): void
    {
        $answer = $this->kj->reports->houseLords(new KundliRequest(
            birth: self::birth(),
            options: new CalculationOptions(language: ['en', 'hi']),
        ));

        $lords = $answer->data->houseLords ?? [];
        self::assertSame(range(1, 12), array_map(static fn ($lord): int => $lord->house, $lords));
        self::assertSame('cancer', $lords[0]->sign->id);
        self::assertNotEmpty($lords[0]->entry->text->hi);
    }

    public function testAPlaceSearchAnswersWithTheZoneABirthNeeds(): void
    {
        $answer = $this->kj->places('varanasi', country: 'IN', limit: 3);

        self::assertNotEmpty($answer->data->places);
        self::assertSame('Asia/Kolkata', $answer->data->places[0]->timezone);
    }

    public function testAContradictionIsAValidationErrorWithTheFieldNamed(): void
    {
        try {
            $this->kj->kundli->get(new KundliRequest(birth: new Birth(
                datetime: '1990-05-14T10:30:00',
                latitude: 28.6139,
                longitude: 77.209,
                // Both a zone and an offset: they can disagree, so the API
                // refuses rather than guessing which one was meant.
                timezone: 'Asia/Kolkata',
                utcOffset: '+05:30',
            )));
            self::fail('the gateway should refuse a birth with two zones');
        } catch (KaaljyotiException $error) {
            self::assertSame('validation_error', $error->code());
            self::assertSame(400, $error->status);
            self::assertFalse($error->isRetryable());
            self::assertStringContainsString('#validation_error', (string) $error->docs);
        }
    }
}
