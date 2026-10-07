# Changelog

## Unreleased

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
