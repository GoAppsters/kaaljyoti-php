# kaaljyoti/sdk

The typed PHP client for the [Kaal Jyoti API](https://kaaljyoti.com/api) —
kundli, panchang, dasha, vargas, KP, Jaimini, varshphal, transits, matching,
written readings, horoscopes and printable PDFs.
One client, one method per endpoint, every request and response generated from
the API's own OpenAPI document. No calculation happens here: the package adds
auth, retries, error mapping and the types, and gets out of the way.

PHP 8.2+. **No runtime dependencies at all** — HTTP goes through a two-method
interface with an `ext-curl` implementation built in, so bundling this inside a
WordPress plugin cannot collide with whatever Guzzle the plugin next door
bundled.

## 1. Install

```sh
composer require kaaljyoti/sdk
```

`examples/quickstart.php` is the quick start below, runnable, and
`CHANGELOG.md` records what each version changed.

## 2. Quick start

```php
<?php

use Kaaljyoti\Client;
use Kaaljyoti\Models\{Birth, CalculationOptions, KundliRequest};

require __DIR__ . '/vendor/autoload.php';

$kj = new Client(apiKey: getenv('KAALJYOTI_API_KEY'));

$answer = $kj->kundli->get(new KundliRequest(
    birth: new Birth(
        datetime: '1990-05-14T10:30:00', // wall clock at the birth place
        latitude: 28.6139,
        longitude: 77.209,
        timezone: 'Asia/Kolkata',
        place: 'New Delhi',
    ),
    options: new CalculationOptions(ayanamsa: 'lahiri', language: ['en']),
));

echo $answer->data->ascendantDms;          //  99°02'48.2"
echo $answer->data->lagnaSign->name;       //  Cancer
echo $answer->meta->timezone->source;      //  given
```

Two details in that body are the whole contract, and they are the two people
get wrong:

- **`datetime` is the clock on the wall where the birth happened**, not UTC and
  not your server's zone. If you are starting from a `DateTimeImmutable`, see
  [wall clocks](#7-dates-and-wall-clocks).
- **Give `timezone` or `utcOffset`, or neither — never both.** With neither, the
  API derives the zone from the coordinates and `meta.timezone.source` says
  `derived`. Giving both is a `400`.

Everything else has a default, and `options` can be left out entirely. The
[quick start](https://kaaljyoti.com/api/docs/quick-start) covers the same ground
in curl and Python.

The SDK does not read the environment for you; pass `apiKey` explicitly, so a
process with two keys in it cannot pick the wrong one by accident.

## 3. Keys

Create one in the [dashboard](https://kaaljyoti.com/api/dashboard/keys). You see
the whole key once — only its SHA-256 is stored.

| Prefix      | What it is                          | Where the SDK puts it   | Safe in a browser |
| ----------- | ----------------------------------- | ----------------------- | ----------------- |
| `kj_live_…` | Your production secret key          | `Authorization: Bearer` | **No**            |
| `kj_test_…` | A second secret key, for staging    | `Authorization: Bearer` | **No**            |
| `kj_pub_…`  | Publishable, locked to your origins | `?key=…` in the query   | Yes, with care    |

The prefix decides the placement; you never choose it. A browser cannot set
`Authorization` on a cross-origin request without a preflight the gateway does
not grant, so a publishable key travels in the query — and the gateway refuses a
secret key in a query string outright, which is the backstop for the mistake
this table exists to prevent.

PHP is a server language, so the key you want is almost always a secret one. A
publishable key is what you would hand to the JavaScript on the page this SDK
renders; it is only accepted from the origins you listed when you created it, the
match is exact and `*` is not a wildcard, and it cannot reach the heavy endpoints
(`panchang->month`, `transit->scan`, `match->batch`) or ask for `embedFont` on a
chart. Outside a browser there is no `Origin` header, so a publishable key used
from a PHP process is checked against the key-wide limits only — and the gateway
refuses it outright unless you send an `Origin` of your own (see
[options](#11-options)).

**Keep secret keys out of the document root and out of version control**: an
environment variable, or a config file above the web root. Nothing in this
package ever logs a key or puts one in an exception message. See
[authentication](https://kaaljyoti.com/api/docs/authentication).

## 4. Every method

Grouped the way the paths are, so a path in the docs is a method here without
looking anything up. Every method takes one generated request class and answers
a `Result`. What each one costs is in
[Credits per API](https://kaaljyoti.com/api/docs/credits): **a calculation costs
1 credit, a written reading 5, a scan across time 10 or 20, a PDF 500 or
1,000**; a refusal costs nothing, and a cache hit costs the same as any other
answer. Every plan can call every method — the credits do the limiting — except
that PDFs are not on the Free plan.

### Service and reference — free

| Method                                 | Path                       | Answers                                       | Cost              |
| -------------------------------------- | -------------------------- | --------------------------------------------- | ----------------- |
| `$kj->health()`                        | `GET /v1/health`           | `HealthDocument`, no meta                     | Free, no key      |
| `$kj->reference($list, $language)`     | `GET /v1/reference/{list}` | `list<array<string, mixed>>`, `ReferenceMeta` | Free, wants a key |
| `$kj->timezone($lat, $lon, $datetime)` | `GET /v1/timezone`         | `TimezoneDocument`, `TimezoneMeta`            | Free, wants a key |

```php
$signs = $kj->reference('signs', ['en', 'hi']);
// the list is joined into the one comma-separated parameter the API takes
echo $signs->data[0]['name'];        //  Aries
print_r($signs->meta->language);     //  ['en', 'hi'] — the labels you actually got

$zone = $kj->timezone(28.6139, 77.209);
echo "{$zone->data->name} {$zone->data->utcOffset}"; //  Asia/Kolkata +05:30
```

`$list` is one of `ayanamsas`, `planets`, `signs`, `nakshatras`, `tithis`,
`yogas`, `karanas`, `vargas`, `dasha-systems`, `house-systems`,
`chalit-systems`, `transit-events`, `languages`, `credits`. `$language` is a
`string` or a `list<string>`. `credits` is the price list in force: one
`{route, credits}` row per metered route, with `per` (`pair` or `part`) on the
two priced per unit.
Its rows come back as plain arrays rather than a class: each table publishes its
own columns — `{id, name}` for most, `{index, name}` for the ones the engine
numbers — and the snapshot declares them open, so a class here would be a guess
that goes stale. The `meta` beside them is the generated `ReferenceMeta`
(`engine`, `language`), whose fields the snapshot does name.

### `$kj->kundli` — 1 credit each

| Method                  | Path                             | Request              |
| ----------------------- | -------------------------------- | -------------------- |
| `kundli->get`           | `POST /v1/kundli`                | `KundliRequest`      |
| `kundli->chart`         | `POST /v1/kundli/chart`          | `KundliChartRequest` |
| `kundli->chartSvg`      | `POST /v1/kundli/chart`          | `KundliChartRequest` |
| `kundli->dasha`         | `POST /v1/kundli/dasha`          | `DashaRequest`       |
| `kundli->vargas`        | `POST /v1/kundli/vargas`         | `VargasRequest`      |
| `kundli->chalit`        | `POST /v1/kundli/chalit`         | `ChalitRequest`      |
| `kundli->yogas`         | `POST /v1/kundli/yogas`          | `KundliRequest`      |
| `kundli->shadbala`      | `POST /v1/kundli/shadbala`       | `KundliRequest`      |
| `kundli->bhavaBala`     | `POST /v1/kundli/bhava-bala`     | `KundliRequest`      |
| `kundli->ashtakavarga`  | `POST /v1/kundli/ashtakavarga`   | `KundliRequest`      |
| `kundli->grahaDrishti`  | `POST /v1/kundli/graha-drishti`  | `KundliRequest`      |
| `kundli->maitri`        | `POST /v1/kundli/maitri`         | `KundliRequest`      |
| `kundli->pace`          | `POST /v1/kundli/pace`           | `KundliRequest`      |
| `kundli->specialLagnas` | `POST /v1/kundli/special-lagnas` | `KundliRequest`      |
| `kundli->tripataki`     | `POST /v1/kundli/tripataki`      | `KundliRequest`      |
| `kundli->sarvatobhadra` | `POST /v1/kundli/sarvatobhadra`  | `KundliRequest`      |
| `kundli->nakshatra28`   | `POST /v1/kundli/nakshatra28`    | `KundliRequest`      |
| `kundli->sadeSati`      | `POST /v1/kundli/sade-sati`      | `SadeSatiRequest`    |
| `kundli->events`        | `POST /v1/kundli/events`         | `SadeSatiRequest`    |
| `kundli->kotaChakra`    | `POST /v1/kundli/kota-chakra`    | `KundliRequest`      |

`chart` and `chartSvg` are the same endpoint and the same call, asked for with a
different `Accept`; see [charts as SVG](#9-charts-as-svg). `dasha` and
`kotaChakra` are never cached.

`kundli->dasha` answers one `system` — `vimshottari`, `yogini`, or the Jaimini
`chara`, `sthira` and `mandook` — as a tree `levels` deep: 1 to 5, default 2.
Levels 4 and 5 need `from`/`to`, UTC instants at most 366 days apart, which cut
the tree to that window; the running chain is always included, as deep as
`levels`.

### `$kj->panchang`, `$kj->ephemeris`, `$kj->calendar`

| Method                   | Path                              | Cost                |
| ------------------------ | --------------------------------- | ------------------- |
| `panchang->daily`        | `POST /v1/panchang`               | 1 credit            |
| `panchang->muhurta`      | `POST /v1/panchang/muhurta`       | 1 credit            |
| `panchang->month`        | `POST /v1/panchang/month`         | 20 credits · 10/min |
| `ephemeris->month`       | `POST /v1/ephemeris/month`        | 20 credits · 10/min |
| `calendar->vikramSamvat` | `POST /v1/calendar/vikram-samvat` | 1 credit            |

`panchang->daily`, `panchang->month` and
`ephemeris->month` are about a **place and a day**, not a person, so their
requests take the place fields flat, with no `Birth`.
`panchang->month` answers one daily panchang per date — every tithi, nakshatra,
yoga and karana that touches the day with the time it ends, the vara, sunrise
and sunset, and the masa in both reckonings; `ephemeris->month` is the month of
graha positions, sidereal and tropical unless `system` narrows it.
`panchang->muhurta` takes a `MuhurtaRequest` with **either** a `birth` (the
answer then adds `taraBala` and `chandraBala`) **or** a place and an optional `date`.

The panchang's names — `tithi_name`, `yoga_name`, `karana_name`, `paksha`,
`vara`, `masa.month_name` and each of `tithis[]` — are `LabelledId`s like a
nakshatra: `id` is a lower-case slug (`shashthi`, `somavara`), `name` is in the
first language asked for, and `names` has both when two were asked for. A leap
month is `is_adhik` on the masa, not part of its name.

### `$kj->jaimini`, `$kj->kp` — 1 credit each

| Method                 | Path                            | Request         |
| ---------------------- | ------------------------------- | --------------- |
| `jaimini->karakas`     | `POST /v1/jaimini/karakas`      | `KundliRequest` |
| `jaimini->arudhaPadas` | `POST /v1/jaimini/arudha-padas` | `KundliRequest` |
| `jaimini->aspects`     | `POST /v1/jaimini/aspects`      | `KundliRequest` |
| `jaimini->karakamsha`  | `POST /v1/jaimini/karakamsha`   | `KundliRequest` |
| `kp->chart`            | `POST /v1/kp/chart`             | `KundliRequest` |

The four Jaimini sections are one document split four ways: asking for the second
section of the same chart is a cache hit, and still costs its own credit. The five
`varshphal` sections work the same way.

### `$kj->varshphal` — 1 credit each, all `VarshphalRequest`

| Method              | Path                        |
| ------------------- | --------------------------- |
| `varshphal->get`    | `POST /v1/varshphal`        |
| `varshphal->bala`   | `POST /v1/varshphal/bala`   |
| `varshphal->sahams` | `POST /v1/varshphal/sahams` |
| `varshphal->yogas`  | `POST /v1/varshphal/yogas`  |
| `varshphal->dasha`  | `POST /v1/varshphal/dasha`  |

### `$kj->transit`, `$kj->match`

| Method             | Path                       | Request                 | Cost                                                |
| ------------------ | -------------------------- | ----------------------- | --------------------------------------------------- |
| `transit->now`     | `POST /v1/transit/now`     | `TransitNowRequest`     | 1 credit · never cached without an explicit `at`    |
| `transit->scan`    | `POST /v1/transit/scan`    | `TransitScanRequest`    | 20 credits · 10/min, no publishable keys            |
| `transit->events`  | `POST /v1/transit/events`  | `TransitEventsRequest`  | 20 credits · 10/min                                 |
| `match->ashtakoot` | `POST /v1/match/ashtakoot` | `MatchAshtakootRequest` | 1 credit                                            |
| `match->compare`   | `POST /v1/match/compare`   | `MatchCompareRequest`   | 1 credit · never cached                             |
| `match->batch`     | `POST /v1/match/batch`     | `MatchBatchRequest`     | **1 credit per pair** · 10/min, no publishable keys |

`transit->events()` is the calendar of a `year` (or a `from`…`to` window of at
most 366 days), the same for everyone: every sign ingress and every retrograde
and direct station, as `TransitEvent`s sorted by time. It takes no birth — only
a `timezone` for the local times — and `moon`, `nakshatras` and `combustion`
add more kinds.

```php
$sky = $kj->transit->events(new TransitEventsRequest(year: 2026, timezone: 'Asia/Kolkata'));
foreach ($sky->data->events as $event) {
    echo $event->local, ' ', $event->planet->id, ' ', $event->kind, "\n";
}
```

### Readings and the horoscope — 5 credits each; place search — 1

| Method                 | Path                           | Request                                 | Answers                        |
| ---------------------- | ------------------------------ | --------------------------------------- | ------------------------------ |
| `reports->lagna`       | `POST /v1/reports/lagna`       | `ReportLagnaRequest`                    | `ReadingLagnaDocument`         |
| `reports->nakshatra`   | `POST /v1/reports/nakshatra`   | `ReportNakshatraRequest`                | `ReadingNakshatraDocument`     |
| `reports->houseLords`  | `POST /v1/reports/house-lords` | `KundliRequest`                         | `ReadingHouseLordsDocument`    |
| `reports->grahas`      | `POST /v1/reports/grahas`      | `KundliRequest`                         | `ReadingGrahasDocument`        |
| `reports->yogas`       | `POST /v1/reports/yogas`       | `KundliRequest`                         | `ReadingYogasDocument`         |
| `reports->vimshottari` | `POST /v1/reports/vimshottari` | `KundliRequest`                         | `VimshottariReadingDocument`   |
| `reports->varshphal`   | `POST /v1/reports/varshphal`   | `VarshphalRequest`                      | `VarshphalReadingDocument`     |
| `reports->lifeAreas`   | `POST /v1/reports/life-areas`  | `KundliRequest`                         | `LifeAreasDocument`            |
| `reports->kundli`      | `POST /v1/reports/kundli`      | `ReportKundliRequest`                   | `KundliReportDocument`         |
| `$kj->horoscope()`     | `POST /v1/horoscope`           | `HoroscopeRequest`                      | `HoroscopeDocument`            |
| `$kj->places($q, …)`   | `GET /v1/places`               | `$q`, `$country`, `$limit`, `$language` | `PlacesDocument`, `PlacesMeta` |

All of them are open to publishable keys. A reading takes a `birth` — the
lagna, or the Moon's nakshatra, is computed from it — or the answer itself
(`sign: 'leo'`, `nakshatra: 'purva_phalguni'`) for a page that lets the visitor
pick. The personal reports — the house lords, the grahas (nine
`GrahaReading`s), the yogas (a `YogaReading` per yoga, by `code`), the
Vimshottari dashas (a `MahadashaReading` per mahadasha, `current` marking the
running one), the varshphal (a summary, seven `AreaSummary`s and the year's
`VarshphalPeriod`s) and the life areas (eleven `LifeArea`s) — take a `birth`
only. All of them are on every plan. `reports->kundli()` asks for several of
them at once: `parts` names them
(default all eight), each comes back as its own route answers it, and the
request is priced 5 credits per part (`$answer->meta->credits`); without a `year`
its varshphal is the one running now. Every `YogaReading` carries its `name`.
Every text comes back as a `LocalizedText` with one entry per language in
`options.language`:

```php
use Kaaljyoti\Models\{CalculationOptions, Disclaimer, HoroscopeRequest, KundliRequest, ReportLagnaRequest, VarshphalRequest};

$reading = $kj->reports->lagna(new ReportLagnaRequest(
    sign: 'leo',
    options: new CalculationOptions(language: ['en', 'hi']),
));
echo $reading->data->lagna?->entry->text->hi;
echo $reading->data->disclaimer?->en;
//  These predictions are indicative. For a reading of your own chart, consult an astrologer.

$lords = $kj->reports->houseLords(new KundliRequest(birth: $birth));
foreach ($lords->data->houseLords ?? [] as $lord) {
    // Twelve, in house order: the sign on the house, its lord, and the house the lord sits in.
    echo "{$lord->house}: {$lord->sign->name}, lord {$lord->lord->name} in {$lord->inHouse}\n"; // 1: Gemini, lord Mercury in 9
}

$week = $kj->horoscope(new HoroscopeRequest(
    sign: 'aries',
    period: 'weekly',          // daily (the default), weekly, monthly, yearly
    date: '2026-09-28',        // default today, in `timezone` (default Asia/Kolkata)
    options: new CalculationOptions(
        disclaimer: new Disclaimer(name: 'Acharya Amit Verma', url: 'https://kaaljyoti.com'),
    ),
));
echo "{$week->data->summary->level}: {$week->data->summary->text->en}\n";
foreach ($week->data->areas as $area) {
    // work, money, relationships, health, education — each favourable, mixed or care.
    echo "{$area->area} ({$area->level}): {$area->text->en}\n";
}

$dashas = $kj->reports->vimshottari(new KundliRequest(birth: $birth));
foreach ($dashas->data->periods as $period) {
    echo "{$period->lord->name} {$period->from} – {$period->to} ({$period->level})", $period->current ? ' ← now' : '', "\n";
}

$year = $kj->reports->varshphal(new VarshphalRequest(birth: $birth, year: 2026));
echo $year->data->summary->text->en, "\n";
```

A horoscope is one `summary` and five areas, each with a `level` —
`favourable`, `mixed` or `care` — and a text; there are no scores. `from` and
`to` are UTC instants. `basis` (on the horoscope, one `HoroscopeTransit` per
graha and sign, with `entered` and `leaves` for a sign change inside the
period; on the Vimshottari, varshphal and life-areas readings, the reasoning)
is for you, not for the reader.

`options.disclaimer` is on every request, and only the readings and the
horoscope use it: `'default'` closes with the line above, a `Disclaimer` names
the astrologer to consult instead ("…consult Acharya Amit Verma
(https://kaaljyoti.com)."), and `'off'` leaves `disclaimer` out of the answer.

`$kj->places()` is what a birth form's place field calls: names that begin with
`$q`, in Latin or Devanagari, each with the `latitude`, `longitude` and IANA
`timezone` a `Birth` needs. `$language` works as for `reference()`.

### `$kj->pdf` — 500 or 1,000 credits each · every paid plan

| Method               | Path                          | Request                   | Body of                    |
| -------------------- | ----------------------------- | ------------------------- | -------------------------- |
| `pdf->kundli`        | `POST /v1/pdf/kundli`         | `PdfKundliRequest`        | `POST /v1/kundli`          |
| `pdf->match`         | `POST /v1/pdf/match`          | `PdfMatchRequest`         | `POST /v1/match/ashtakoot` |
| `pdf->varshphal`     | `POST /v1/pdf/varshphal`      | `PdfVarshphalRequest`     | `POST /v1/varshphal`       |
| `pdf->panchangMonth` | `POST /v1/pdf/panchang/month` | `PdfPanchangMonthRequest` | `POST /v1/panchang/month`  |

A finished, printable PDF instead of JSON. Each takes the JSON route's body
plus `template` (`classic`, `modern`, `minimal`, `traditional`) and `branding`
(a `PdfBranding`); the kundli, match and varshphal also take `chartStyle` and
`name` (the match adds `partnerName`), and the kundli takes `edition` (`basic`,
the default, or `professional`), `sections` and `vargas`. The kundli PDF costs
1,000 credits and the others 500, and each uses one PDF from the month's
allowance (Starter 50, Growth 200, Scale 500, Enterprise 2,500); past it the
error is `pdf_quota_exceeded`. PDFs are on every paid plan and not on Free,
which throws `plan_required`. A publishable key cannot make one, and `branding`
in the body is Enterprise only. See
[PDFs](https://kaaljyoti.com/api/docs/pdf).

The answer is the file, not an envelope: `data` is a `PdfFile` and `meta` is
`null`. `bytes` is a plain PHP string — PHP strings are bytes — exactly as the
gateway sent it.

```php
use Kaaljyoti\Models\{HouseSystem, PdfKundliOptions, PdfKundliRequest};

$answer = $kj->pdf->kundli(new PdfKundliRequest(
    birth: $birth,
    name: 'Ravi Kumar',
    edition: 'professional',
    template: 'traditional',
    options: new PdfKundliOptions(language: ['en', 'hi'], houseSystem: HouseSystem::KP),
));
$file = $answer->data;

$file->bytes;      //  string — the PDF itself
$file->filename;   //  'kundli-ravi-kumar.pdf', from Content-Disposition
$file->credits;    //  1000, from X-KJ-Credits
$answer->cached;   //  true when the 24-hour cache answered: no PDF used, still 1,000 credits
$answer->creditsRemaining; //  what is left this month, packs included

file_put_contents($file->filename ?? 'kundli.pdf', $file->bytes);

// Or straight to the browser:
header('Content-Type: ' . $file->contentType);
header('Content-Disposition: attachment; filename="' . ($file->filename ?? 'kundli.pdf') . '"');
echo $file->bytes;
```

The kundli PDF takes `PdfKundliOptions` rather than `CalculationOptions`: the
same fields plus `houseSystem`, the bhava chalit it prints (default
`placidus`). No other route takes a house system — the API answers `400` to
`options.house_system` anywhere else — and `POST /v1/kundli/chalit` has its own
`system`.

A failure is the usual JSON error and throws a `KaaljyotiException`, never a PDF.

Namespaces are plain readonly properties, so pulling one out works:

```php
$kundli = $kj->kundli;
$chart = $kundli->get(new KundliRequest(birth: $birth));
```

No method here retypes its path: each one looks the path up in the generated
operation table by `operationId`, and the contract test walks the same table
against `openapi/openapi.json`, so a typo cannot ship.

## 5. What comes back

Every method answers a `Result<Document, Meta>` — the envelope, flattened by one
level, because `meta` is how you answer a user who asks why a number is what it
is:

```php
$answer = $kj->kundli->get(new KundliRequest(birth: $birth));

$answer->data;       //  the calculation, typed per endpoint
$answer->meta;       //  ayanamsa, timezone, engine, computeMs, languageFallback, credits
$answer->requestId;  //  'X-KJ-Request-Id' — quote it to support
$answer->plan;       //  the plan this answer was served under
$answer->cached;     //  true when the 24-hour cache answered. Costs the same credits.
$answer->credits;    //  'X-KJ-Credits' — what this request cost, as meta->credits says
$answer->creditsRemaining; //  'X-KJ-Credits-Remaining' — secret keys only
$answer->rateLimit;  //  RateLimit(limit, remaining, reset) — the bucket as it stands
```

The two type parameters are what PHPStan and your IDE read: the second is the
`meta` — `Meta` for almost everything, `BatchMeta` for `match->batch`,
`ReferenceMeta` for the reference tables, `TimezoneMeta` for `$kj->timezone()`,
`PlacesMeta` for `$kj->places()`,
and `null` for the answers that carry none: `$kj->health()`,
`kundli->chartSvg` and the `pdf` methods. `requestId`, `plan` and the `rateLimit` numbers are `null`
when the gateway did not send the header — a proxy in front of it, usually.

`credits` is what the request cost, on every metered answer — the same number
as `meta->credits`, and the only place an SVG or a PDF says it. It is `null` on
the free answers (health, time zone, the reference tables). `creditsRemaining`
is what is left of the month and of your credit packs together; the API sends
it to **secret keys only**, so it is always `null` with a `kj_pub_…` key — a
page's visitors do not get to read the account's balance.

Every document is a `final readonly class` with a promoted constructor,
`fromArray()` and `toArray()`, so an answer is `json_encode`-able and comparable
by value without a library. Inside `data`, every id comes back as a `LabelledId`
— see [labelled ids](#12-labelled-ids).

## 6. Errors

Every failure — from the gateway or from the socket — is a thrown
`KaaljyotiException`:

```php
use Kaaljyoti\KaaljyotiException;

try {
    $answer = $kj->kundli->get(new KundliRequest(birth: $birth));
} catch (KaaljyotiException $error) {
    match ($error->code()) {
        'validation_error' => $form->reject($error->field),  // 'birth.utc_offset'
        'quota_exceeded' => $this->askToUpgrade($error->docs),
        default => $log->warning("{$error->code()} {$error->status} {$error->requestId}"),
    };
    if ($error->isRetryable()) {
        $queue->later($error->retryAfter ?? 60, $job);
    }
}
```

| Member          | What it is                                                    |
| --------------- | ------------------------------------------------------------- |
| `code()`        | The contract. **Branch on this, never on `getMessage()`.**    |
| `status`        | HTTP status, or `0` when the request never got an answer      |
| `getMessage()`  | Human-readable, and free to get clearer between versions      |
| `field`         | Dotted path of the offending request field, when there is one |
| `docs`          | Link to the errors page for this code                         |
| `requestId`     | `X-KJ-Request-Id` — the one thing support asks for            |
| `retryAfter`    | Seconds from `Retry-After`, on a `429`                        |
| `isRetryable()` | Whether sending the identical request again is worth anything |

**The code is `code()`, not `getCode()`.** `Exception::getCode()` is an `int`
that PHP owns and the API's codes are strings, so ours is stored as
`$errorCode` and read through the accessor; `getCode()` stays at its inherited
`0`.

The codes are the API's own — `validation_error`, `invalid_key`, `key_revoked`,
`quota_exceeded`, `pdf_quota_exceeded`, `forbidden_origin`, `plan_required`, `not_found`,
`not_computable`, `rate_limited`, `engine_error`, `service_disabled` — plus
three this package adds for failures that never reached the API:
`KaaljyotiException::NETWORK_ERROR`, `::TIMEOUT` and `::BAD_RESPONSE`, alongside
`::INVALID_KEY`. One `match` handles both kinds. A malformed answer that the
generated models cannot read is `bad_response` too — never a raw `TypeError` for
the caller to interpret.

### Retries

`maxRetries` is `2` by default, and the budget is spent per reason:

- **`429 rate_limited`** — waits what `Retry-After` said (capped at 30 seconds,
  defaulting to 2 when the header is missing or unparseable) and tries again, up
  to `maxRetries` times.
- **`500 engine_error`** — one more try. Our fault, and often transient.
- **A dead socket or a timeout** — one more try.
- **`400`, `401`, `402`, `403`, `422`** — never. The identical body will be
  refused identically, and retrying only spends your time.

Every endpoint is a pure calculation, which is what makes retrying a POST safe at
all. To do the waiting yourself — a queue worker with its own backoff, a request
that must answer inside a page load — turn it off and read `retryAfter` off the
exception:

```php
$kj = new Client(apiKey: $apiKey, maxRetries: 0);
```

A refused request costs no credits, so a retry that gets refused again costs nothing
but latency. **The SDK sleeps in-process while it waits**, so a `maxRetries: 2`
on a 429 with a 30-second `Retry-After` can hold a web request for a minute;
inside a page load, `maxRetries: 0` and a job queue is the better shape.

## 7. Dates and wall clocks

The single most common integration bug is sending an instant where a wall clock
belongs. A birth time is a wall clock — `1990-05-14T10:30:00` means half past ten
_where the birth happened_ — and an instant knows nothing about where it was.

> **`(new DateTimeImmutable('@' . $ts))->format('c')` on a birth is wrong.** It
> sends the UTC time of that moment, which is the birth time only in London and
> five and a half hours out in India. That is not a rounding error: it moves the
> ascendant by most of the zodiac.

PHP ships the IANA zone database, so — unlike the Dart SDK — this one can convert
an instant for you. Four pure static methods on `Kaaljyoti\WallClock`:

```php
use Kaaljyoti\WallClock;

// A DateTime whose fields already are the clock at the place — a date and a
// time typed into a form. Written out as they stand:
WallClock::format(new DateTimeImmutable('1990-05-14 10:30:00'));
// '1990-05-14T10:30:00'

// An instant from anywhere else — a UTC database column, another API — turned
// into the clock that was on the wall at the place:
WallClock::fromDateTime(new DateTimeImmutable('1990-05-14T05:00:00Z'), 'Asia/Kolkata');
// '1990-05-14T10:30:00'

// The reverse, given meta.timezone.utcOffset from an answer:
WallClock::toDateTime('1990-05-14T10:30:00', '+05:30');
// DateTimeImmutable 1990-05-14T05:00:00+00:00

// The offset a zone was on at an instant, for birth.utcOffset:
WallClock::offsetAt(new DateTimeImmutable('1944-03-15T10:00:00Z'), 'Asia/Kolkata');
// '+06:30' — India's war time, not today's +05:30
```

`format()` reads `year`…`second` and does not convert, which is correct precisely
when those fields already are the clock at the place and wrong for a `DateTime`
that came from a clock somewhere else — use `fromDateTime()` for that one.
`toDateTime()` throws an `InvalidArgumentException` on anything that is not
`YYYY-MM-DDTHH:MM:SS` and `+HH:MM`, because a silently wrong chart is worse than
a throw.

For a zone **name** from a pair of coordinates, ask the API — it costs no credits:

```php
$zone = $kj->timezone(28.6139, 77.209, '1944-03-15T10:00:00');
$zone->data->utcOffset; // '+06:30'
```

Or send no zone at all and let the API derive it from the coordinates;
`meta.timezone.source` then says `derived`. More on this on the
[time zones](https://kaaljyoti.com/api/docs/timezones) page.

## 8. Charts as SVG

The same endpoint answers either way:

```php
use Kaaljyoti\Models\{ChartStyle, KundliChartRequest};

// The document: metadata plus the markup in `data->svg`.
$document = $kj->kundli->chart(new KundliChartRequest(
    birth: $birth,
    style: ChartStyle::NORTH,
    size: 360,
));
$document->data->style;  //  'north'
$document->data->svg;    //  '<svg …'

// The markup itself. `data` is a string, and `meta` is null.
$svg = $kj->kundli->chartSvg(new KundliChartRequest(birth: $birth, size: 360));
echo $svg->data;
```

Echo it straight into the page — the markup colours itself from the same sixteen
custom properties (`--kj-bg`, `--kj-lagna`, `--kj-planet-sun`, …) that
[`@kaaljyoti/widgets`](https://www.npmjs.com/package/@kaaljyoti/widgets) uses, so
an inlined chart takes the page's theme. A failure is an envelope whatever
`Accept` asked for, so `chartSvg` throws the same `KaaljyotiException` as
everything else.

`firstHouse` rotates the chart: `lagna` (the default), a graha — `moon` draws
the Chandra kundli, `sun` the Surya kundli — or `house_2` … `house_12` for
bhavat bhavam. It works in every varga, and the grahas never move; the document
echoes `firstHouse`, names the sign drawn as house 1 in `firstHouseSign` and
says what the chart is in `title`:

```php
$moon = $kj->kundli->chart(new KundliChartRequest(birth: $birth, firstHouse: 'moon'));
$moon->data->firstHouseSign->id;  //  the Moon's sign, now house 1
$moon->data->title;               //  'Moon chart'
```

## 9. Matching in bulk

`match->batch` takes up to 100 pairs and charges 1 credit per pair. **A pair that
could not be computed comes back as a value, not as a thrown exception** — the
other pairs were calculated and charged, and throwing would discard answers you
have already paid for:

```php
use Kaaljyoti\Models\{MatchBatchRequest, MatchBatchRequestPairsItem};

$answer = $kj->match->batch(new MatchBatchRequest(pairs: [
    new MatchBatchRequestPairsItem(bride: $brideBirth, groom: $groomBirth),
    new MatchBatchRequestPairsItem(bride: $brideBirth, groom: $otherBirth),
]));

foreach ($answer->data->results as $result) {
    if ($result->error !== null) {
        echo "pair {$result->index}: {$result->error->code} {$result->error->field}\n";
        continue;
    }
    echo "{$result->index}: {$result->data?->total} / 36\n";
}

$answer->meta->credits; //  what the request cost: one per pair
```

Only the whole request failing — a bad key, a body over the 8 KB limit, the rate
limit — throws. In practice that 8 KB allows about thirty pairs per request, not
a hundred. Batch is closed to publishable keys.

## 10. HTTP clients

The package has **no runtime dependencies**, because it is bundled inside a
WordPress plugin and a `vendor/` with Guzzle in it collides with every other
plugin that bundled a different one. HTTP is a one-method interface,
`Kaaljyoti\Http\HttpClient`, with three ways to satisfy it.

**cURL, the default.** Nothing to configure; `ext-curl` is in every PHP
distribution worth shipping on. Pass extra `CURLOPT_*` if you need a proxy or a
CA bundle:

```php
use Kaaljyoti\Http\CurlClient;

$kj = new Client(
    apiKey: $apiKey,
    httpClient: new CurlClient([CURLOPT_PROXY => 'http://proxy.internal:3128']),
);
```

**A PSR-18 client you already have.** Guzzle, Symfony's HTTP client, anything
with middleware you want the SDK's traffic to go through. `psr/http-client` and
`psr/http-factory` are dev dependencies here, so this class is only loaded when
you name it:

```php
use Kaaljyoti\Http\Psr18Client;

$kj = new Client(
    apiKey: $apiKey,
    httpClient: new Psr18Client($psr18Client, $requestFactory, $streamFactory),
);
```

PSR-18 has no per-request deadline, so `timeoutSeconds` is ignored for this one —
set the timeout on your own client, at or below the SDK's.

**Your own.** Two classes and a method; the WordPress plugin implements it over
`wp_remote_request` so the site's own HTTP filters, proxy settings and
certificate bundle apply:

```php
use Kaaljyoti\Http\{HttpClient, HttpRequest, HttpResponse, TransportException};

final class WpHttpClient implements HttpClient
{
    public function send(HttpRequest $request): HttpResponse
    {
        $answer = wp_remote_request($request->url, [
            'method' => $request->method,
            'headers' => $request->headers,
            'body' => $request->body,
            'timeout' => $request->timeoutSeconds,
        ]);

        // A dead socket must throw, never come back as an invented status:
        // the SDK retries a transport failure on a different budget than a 500.
        if (is_wp_error($answer)) {
            throw new TransportException($answer->get_error_message());
        }

        return new HttpResponse(
            wp_remote_retrieve_response_code($answer),
            wp_remote_retrieve_headers($answer)->getAll(),
            wp_remote_retrieve_body($answer),
        );
    }
}
```

An implementation does not retry, does not touch the key or the headers and does
not interpret the body: the transport owns all of that, so every client behaves
identically.

## 11. Options

```php
$kj = new Client(
    apiKey: $apiKey,
    timeoutSeconds: 10.0,
    maxRetries: 1,
);
```

| Option           | Type                    | Default                     | What it does                                                                          |
| ---------------- | ----------------------- | --------------------------- | ------------------------------------------------------------------------------------- |
| `apiKey`         | `string`                | — (required)                | Placed by its prefix; see [keys](#3-keys). An empty key is refused before the network |
| `baseUrl`        | `?string`               | `https://api.kaaljyoti.com` | Origin only; a trailing `/` or `/v1` is trimmed for you                               |
| `httpClient`     | `?HttpClient`           | a new `CurlClient`          | Your own transport; see [HTTP clients](#10-http-clients)                              |
| `timeoutSeconds` | `float`                 | `30.0`                      | Deadline **per attempt**, not per call                                                |
| `maxRetries`     | `int`                   | `2`                         | `0` turns retrying off entirely                                                       |
| `clientTag`      | `string`                | `sdk-php/<version>`         | The `X-KJ-Client` tag, so a shell built on this SDK attributes usage to itself        |
| `headers`        | `array<string, string>` | `[]`                        | Extra headers on every request. Cannot override the key, the `Accept` or the tag      |

There is nothing to close: the SDK owns no connection pool of its own, and the
one an `HttpClient` owns belongs to whoever built it.

`headers` is also how a server-side process uses a publishable key at all — the
gateway wants an `Origin`, and PHP is not a browser:

```php
$kj = new Client(apiKey: $publishableKey, headers: ['Origin' => 'https://example.com']);
```

### Staging

One option, no separate build:

```php
$kj = new Client(
    apiKey: getenv('KAALJYOTI_TEST_KEY'), // a kj_test_… key
    baseUrl: 'https://api-staging.kaaljyoti.com',
);
```

The package's own smoke test runs against staging and is opt-in:

```sh
KJ_SMOKE=1 KJ_API_KEY=kj_pub_… vendor/bin/phpunit --group smoke
```

It reads `KJ_BASE_URL` (default staging) and, for a publishable key, `KJ_ORIGIN`
(default `http://localhost:3000`), which it sends as `Origin` because a PHP
process does not send one of its own.

## 12. Labelled ids

Every id the gateway recognises arrives as a `LabelledId` — `id`, `name` and an
optional `names` — and is typed that way: `lagnaSign`, `moonSign`, every
`*Nakshatra` and `*Lord`, `planet` / `sign` / `nakshatra` inside `positions`, and
so on.

```php
$sun = $answer->data->positions['sun'];
$sun->sign->id;            //  'aries'  ← the stable half: switch on this
$sun->sign->name;          //  'Aries'  ← for people, in the first language you asked for
$sun->sign->names['hi'];   //  'मेष'    ← present when you asked for more than one
```

`LabelledId` is a `final readonly class`, so two ids with the same `id`, `name`
and `names` compare equal with `==` and are safe to use as array values in a
cache. The constants the API's closed enums accept are generated beside the
models — `Ayanamsa::LAHIRI`, `Varga::D9`, `ChartStyle::NORTH`,
`HouseSystem::WHOLE_SIGN` (the kundli PDF's `houseSystem`), and `::VALUES` on
each for a `<select>`. They are plain strings and the properties stay `string`,
so a slug from a form or a database still goes in as it is.

---

Generated from OpenAPI document version **0.16.0**
(`Kaaljyoti\Generated\Version::OPENAPI`, beside `::SDK`).
`pnpm --filter sdk-php run gen` regenerates `src/Models/` and `src/Generated/`, and CI fails when the generated code and the snapshot
disagree.

MIT licensed. Issues and pull requests:
[kaaljyoti-integrations](https://github.com/goappsters/kaaljyoti-integrations).
