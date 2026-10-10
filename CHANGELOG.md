# Changelog

## 0.1.2

- Generated from the API's OpenAPI document for engine 0.17.0.
- Kundli PDF: `edition` takes `life`, the Life Report written for the person it is about, beside
  `basic` and `professional`; `sections` takes `nature`, `sarvashtakavarga`, `in_depth` and
  `now_next`.
- Varshphal PDF: `edition`, `life` (the default) or `professional` (adds the astrologer's tables).
- Kundli report: three more parts, `nature`, `in_depth` and `now_next`. Without `parts` the report
  now holds all eleven (55 credits).
- The written readings carry the engine's new fields: the Varshphal's monthly readings, best and
  hard days and sub-periods, a `note` on its summary, the yogas' periods. The Vimshottari reading
  reads every mahadasha that begins before age 80 to its own end.
- Python, Dart and PHP: the horoscope's summary types are now `ReadingSummary2` (its `summary`) and
  `AreaSummary2` (its `areas`); `ReadingSummary` and `AreaSummary` now name the Varshphal's. Code
  that names the horoscope's types needs the new names. TypeScript is unaffected.

## 0.1.1

- Generated from the API's OpenAPI document 0.16.0. `health()` carries `supported_range`
  (`first_date`, `last_date`, `first_year`, `last_year`): the dates the API answers for, from its
  ephemeris data. Dates now run from 1 April 1550 to 31 December 2400 (whole years 1551–2399); a
  date outside is refused with a message naming the range.

## 0.1.0

First release.

- One method per endpoint of the Kaal Jyoti API — kundli, panchang, dasha, vargas, KP, Jaimini,
  varshphal, transits, matching, horoscopes, written readings and printable PDFs — with every
  request and response typed from the API's OpenAPI document 0.15.2.
- What each request cost, in credits (`meta->credits`, `$result->credits`), and what is left of the
  month for a secret key (`$result->creditsRemaining`).
- Retries a `429` after its `Retry-After`, an engine error and a network failure, and never a
  request the API would refuse again; one error type, `KaaljyotiException`, carrying the API's error
  `code`.
- Wall-clock times kept as the API sends them: never moved into the caller's time zone.
- PHP 8.2+, no runtime dependencies; HTTP through `ext-curl` or any PSR-18 client.
