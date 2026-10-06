<?php

/**
 * The quick start, runnable.
 *
 * ```sh
 * composer install
 * KAALJYOTI_API_KEY=kj_test_… php examples/quickstart.php
 * ```
 *
 * Against staging instead of production:
 *
 * ```sh
 * KAALJYOTI_API_KEY=kj_test_… KAALJYOTI_BASE_URL=https://api-staging.kaaljyoti.com \
 *   php examples/quickstart.php
 * ```
 *
 * It costs three credits — a kundli, a panchang and a chart — plus two free
 * ones: `health()` and `timezone()` are never charged.
 */

declare(strict_types=1);

use Kaaljyoti\Client;
use Kaaljyoti\KaaljyotiException;
use Kaaljyoti\Models\Birth;
use Kaaljyoti\Models\CalculationOptions;
use Kaaljyoti\Models\ChartStyle;
use Kaaljyoti\Models\KundliChartRequest;
use Kaaljyoti\Models\KundliRequest;
use Kaaljyoti\Models\PanchangRequest;
use Kaaljyoti\WallClock;

require __DIR__ . '/../vendor/autoload.php';

$apiKey = getenv('KAALJYOTI_API_KEY');
if (!is_string($apiKey) || $apiKey === '') {
    fwrite(STDERR, "Set KAALJYOTI_API_KEY first — https://kaaljyoti.com/api/dashboard/keys\n");
    exit(1);
}

$baseUrl = getenv('KAALJYOTI_BASE_URL');

// The SDK does not read the environment for you: a process with two keys in it
// must not be able to pick the wrong one by accident.
$kj = new Client(
    apiKey: $apiKey,
    baseUrl: is_string($baseUrl) && $baseUrl !== '' ? $baseUrl : null,
);

try {
    $health = $kj->health();
    echo "engine {$health->data->engine}, {$health->data->ops} operations\n\n";

    // `datetime` is the clock on the wall where the birth happened — not UTC,
    // not your server's zone. `WallClock::format()` writes a DateTime's own
    // fields out; `WallClock::fromDateTime()` converts an instant first.
    $birth = new Birth(
        datetime: WallClock::format(new DateTimeImmutable('1990-05-14 10:30:00')),
        latitude: 28.6139,
        longitude: 77.209,
        // Give `timezone` or `utcOffset`, or neither — never both.
        timezone: 'Asia/Kolkata',
        place: 'New Delhi',
    );

    $kundli = $kj->kundli->get(new KundliRequest(
        birth: $birth,
        options: new CalculationOptions(language: ['en', 'hi']),
    ));

    // `names` is filled only when more than one language was asked for; every
    // id also carries a stable `id` to switch on and a `name` to show.
    $lagnaInHindi = $kundli->data->lagnaSign->names['hi'] ?? $kundli->data->lagnaSign->name;

    echo "ascendant        {$kundli->data->ascendantDms}\n";
    echo "lagna            {$kundli->data->lagnaSign->name} ({$lagnaInHindi})\n";
    echo "moon             {$kundli->data->moonSign->name}, {$kundli->data->moonNakshatra->name}\n";
    echo "zone             {$kundli->meta->timezone->name} {$kundli->meta->timezone->utcOffset}"
        . " ({$kundli->meta->timezone->source})\n";
    echo "cached           " . ($kundli->cached ? 'yes' : 'no') . "\n";
    echo "request          {$kundli->requestId}\n";
    echo 'rate limit       ' . ($kundli->rateLimit->remaining ?? '?')
        . '/' . ($kundli->rateLimit->limit ?? '?') . " left this minute\n\n";

    foreach ($kundli->data->positions as $planet => $position) {
        printf(
            "%-10s %-12s %-18s %s\n",
            $planet,
            $position->sign->name,
            $position->nakshatra->name,
            $position->isRetrograde ? 'retrograde' : '',
        );
    }
    echo "\n";

    // A place and a day, not a person: no `Birth` here.
    $panchang = $kj->panchang->daily(new PanchangRequest(
        latitude: 28.6139,
        longitude: 77.209,
        timezone: 'Asia/Kolkata',
        date: date('Y-m-d'),
    ));

    echo "today            {$panchang->data->panchang->vara->name}, "
        . "{$panchang->data->panchang->tithiName->name}, "
        . "{$panchang->data->panchang->nakshatra->name}\n";

    // The same endpoint answers the document or the markup; this is the
    // markup, and it costs the same one credit.
    $svg = $kj->kundli->chartSvg(new KundliChartRequest(
        birth: $birth,
        style: ChartStyle::NORTH,
        size: 360,
    ));

    // Written to the temp directory rather than beside this file: an example
    // should not leave anything behind in the checkout.
    $chart = sys_get_temp_dir() . '/kaaljyoti-chart.svg';
    file_put_contents($chart, $svg->data);
    echo 'chart            ' . strlen($svg->data) . " bytes written to {$chart}\n";
} catch (KaaljyotiException $error) {
    // Branch on the code, never on the message: the codes are the contract and
    // the messages are free to get clearer between versions.
    fwrite(STDERR, sprintf(
        "%s (HTTP %d)%s: %s\n",
        $error->code(),
        $error->status,
        $error->field === null ? '' : " at {$error->field}",
        $error->getMessage(),
    ));
    if ($error->requestId !== null) {
        fwrite(STDERR, "request {$error->requestId} — quote it to support\n");
    }
    if ($error->isRetryable()) {
        fwrite(STDERR, 'worth trying again' . ($error->retryAfter === null ? '' : " in {$error->retryAfter} s") . "\n");
    }
    exit(1);
}
