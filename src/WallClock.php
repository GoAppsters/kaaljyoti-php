<?php

declare(strict_types=1);

namespace Kaaljyoti;

use DateTimeImmutable;
use DateTimeInterface;
use DateTimeZone;
use Exception;
use InvalidArgumentException;

/**
 * Wall clocks and instants, and the conversion between them.
 *
 * The docs' time-zone page opens by saying that more wrong answers come from
 * this than from everything else combined, and that almost all of them are one
 * mistake: sending an instant where a wall clock belongs. A birth time is a
 * wall clock — `1990-05-14T10:30:00` means half past ten *where the birth
 * happened* — and the UTC time of that moment is the birth time only in London
 * and five and a half hours out in India. That is not a rounding error: it
 * moves the ascendant by most of the zodiac.
 *
 * ```php
 * $birth = new Birth(
 *     datetime: WallClock::format(new DateTimeImmutable('1990-05-14 10:30:00')),
 *     timezone: 'Asia/Kolkata',
 *     latitude: 28.6139,
 *     longitude: 77.209,
 * );
 * ```
 *
 * Unlike Dart, PHP ships the IANA database, so {@see fromDateTime()} can take
 * an instant and the zone it happened in and produce the clock that was on the
 * wall. Zone *names* are still worth asking the API for
 * (`$kj->timezone(lat: …, lon: …)`) when all you have is a pair of
 * coordinates.
 *
 * Every method is pure and static.
 */
final class WallClock
{
    /** `YYYY-MM-DDTHH:MM:SS`, no zone attached, as `birth.datetime` wants it. */
    private const WALL_CLOCK = '/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}(\.\d{1,3})?$/';

    /** `+05:30`, `-04:00`, as `birth.utc_offset` and `meta.timezone` write it. */
    private const UTC_OFFSET = '/^[+-]\d{2}:\d{2}$/';

    /** Nothing to construct: these are four functions with a namespace. */
    private function __construct()
    {
    }

    /**
     * The wall clock a date-time's own fields spell.
     *
     * ```php
     * WallClock::format(new DateTimeImmutable('1990-05-14 10:30:00')); // 1990-05-14T10:30:00
     * ```
     *
     * **The fields are read as they stand and no zone conversion happens** —
     * which is right when they already are the clock at the place, as a date
     * and a time typed into a form are. A `DateTimeImmutable` built from an
     * instant in some other zone is not, so convert it with
     * {@see fromDateTime()} first.
     *
     * Sub-second precision is dropped: the API accepts milliseconds and no
     * birth record has them.
     */
    public static function format(DateTimeInterface $dt): string
    {
        return $dt->format('Y-m-d\TH:i:s');
    }

    /**
     * The wall clock an instant showed in a given zone.
     *
     * ```php
     * WallClock::fromDateTime(new DateTimeImmutable('1990-05-14T05:00:00Z'), 'Asia/Kolkata');
     * // 1990-05-14T10:30:00
     * ```
     *
     * This is the conversion to reach for when the time came from anywhere but
     * a human typing it: a database column in UTC, an API elsewhere, a device
     * clock in another country. The zone database answers for the date given,
     * so a 1944 Indian birth gets the war-time `+06:30` rather than today's
     * `+05:30`.
     *
     * @param string $zone An IANA identifier — `Asia/Kolkata`, `America/New_York`.
     * @throws InvalidArgumentException when the zone is not one PHP knows.
     */
    public static function fromDateTime(DateTimeInterface $instant, string $zone): string
    {
        return self::format(
            DateTimeImmutable::createFromInterface($instant)->setTimezone(self::zone($zone)),
        );
    }

    /**
     * The instant a wall clock was, given the offset it was on, in UTC.
     *
     * The offset is the one thing a wall clock does not carry, so it has to
     * come from somewhere: `meta.timezone.utcOffset` on an answer, or
     * `$kj->timezone(...)` before the call.
     *
     * ```php
     * WallClock::toDateTime('1990-05-14T10:30:00', '+05:30');
     * // 1990-05-14T05:00:00+00:00
     * ```
     *
     * @throws InvalidArgumentException when either argument is malformed. A `-0500`, an `Asia/Kolkata` or a
     *     `+5:30` here would otherwise become a plausible but wrong instant, and a silently wrong chart is
     *     worse than a throw.
     */
    public static function toDateTime(string $wall, string $utcOffset): DateTimeImmutable
    {
        if (preg_match(self::WALL_CLOCK, $wall) !== 1) {
            throw new InvalidArgumentException(sprintf('"%s" is not a wall clock (YYYY-MM-DDTHH:MM:SS)', $wall));
        }
        if (preg_match(self::UTC_OFFSET, $utcOffset) !== 1) {
            throw new InvalidArgumentException(sprintf('"%s" is not a UTC offset (+HH:MM)', $utcOffset));
        }

        // The shape is already proven, so the parse cannot fail; `setTimezone`
        // only makes the answer's own zone explicit, since the caller is going
        // to compare it with other instants.
        return (new DateTimeImmutable($wall . $utcOffset))->setTimezone(new DateTimeZone('UTC'));
    }

    /**
     * The offset a zone was on at an instant, as the API writes one.
     *
     * ```php
     * WallClock::offsetAt(new DateTimeImmutable('1944-03-15T10:00:00Z'), 'Asia/Kolkata'); // +06:30
     * ```
     *
     * Fills `birth.utcOffset` when you would rather pin the offset than name
     * the zone. Seconds are truncated, exactly as the API's own database does
     * with the pre-1906 local mean times.
     *
     * @throws InvalidArgumentException when the zone is not one PHP knows.
     */
    public static function offsetAt(DateTimeInterface $instant, string $zone): string
    {
        return DateTimeImmutable::createFromInterface($instant)->setTimezone(self::zone($zone))->format('P');
    }

    /**
     * A zone by name, with PHP's own complaint turned into an argument error.
     *
     * `new DateTimeZone('Asia/Delhi')` throws a bare `Exception` on PHP 8.2 and
     * a `DateInvalidTimeZoneException` from 8.3; callers should not have to
     * know which PHP they are on to catch it.
     *
     * @throws InvalidArgumentException
     */
    private static function zone(string $zone): DateTimeZone
    {
        try {
            return new DateTimeZone($zone);
        } catch (Exception $error) {
            throw new InvalidArgumentException(sprintf('"%s" is not an IANA time zone', $zone), 0, $error);
        }
    }
}
