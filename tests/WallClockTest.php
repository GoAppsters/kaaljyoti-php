<?php

declare(strict_types=1);

namespace Kaaljyoti\Tests;

use DateTime;
use DateTimeImmutable;
use DateTimeZone;
use InvalidArgumentException;
use Kaaljyoti\WallClock;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * The wall-clock helpers, which are pure and therefore cheap to pin down.
 *
 * The case that matters is the round trip: a birth time goes out as a wall
 * clock, the answer says which offset it was on, and the same instant has to
 * come back. Everything else here guards the throw — a silently wrong chart is
 * worse than an exception.
 */
final class WallClockTest extends TestCase
{
    public function testFormatWritesTheDateTimesOwnFieldsZeroPadded(): void
    {
        self::assertSame(
            '1990-05-14T10:30:00',
            WallClock::format(new DateTimeImmutable('1990-05-14 10:30:00')),
        );
        self::assertSame(
            '2026-01-02T03:04:05',
            WallClock::format(new DateTimeImmutable('2026-01-02 03:04:05')),
        );
    }

    public function testFormatDropsSubSecondPrecisionWhichNoBirthRecordHas(): void
    {
        self::assertSame(
            '1990-05-14T10:30:15',
            WallClock::format(new DateTimeImmutable('1990-05-14 10:30:15.250')),
        );
    }

    public function testFormatReadsAMutableDateTimeToo(): void
    {
        self::assertSame('2026-09-22T12:00:00', WallClock::format(new DateTime('2026-09-22 12:00:00')));
    }

    public function testFormatReadsTheFieldsAsTheyStandRatherThanAsAnInstant(): void
    {
        // The documented contract: the caller hands over a date-time whose
        // fields already are the clock at the place. A UTC one is only right
        // when the birth happened on UTC — which is exactly the mistake the
        // docs warn about, and why this function does not convert.
        $utc = new DateTimeImmutable('1990-05-14 05:00:00', new DateTimeZone('UTC'));
        self::assertSame('1990-05-14T05:00:00', WallClock::format($utc));
    }

    public function testFromDateTimeConvertsAnInstantIntoThePlacesClock(): void
    {
        $instant = new DateTimeImmutable('1990-05-14T05:00:00+00:00');

        self::assertSame('1990-05-14T10:30:00', WallClock::fromDateTime($instant, 'Asia/Kolkata'));
        self::assertSame('1990-05-14T01:00:00', WallClock::fromDateTime($instant, 'America/New_York'));
    }

    public function testFromDateTimeAsksTheZoneDatabaseForTheDateGiven(): void
    {
        // New York in July is on daylight time and in January is not; the same
        // instant an hour apart proves the offset came from the database and
        // not from a constant.
        $summer = new DateTimeImmutable('2026-07-01T16:00:00+00:00');
        $winter = new DateTimeImmutable('2026-01-01T16:00:00+00:00');

        self::assertSame('2026-07-01T12:00:00', WallClock::fromDateTime($summer, 'America/New_York'));
        self::assertSame('2026-01-01T11:00:00', WallClock::fromDateTime($winter, 'America/New_York'));
    }

    public function testFromDateTimeKnowsIndiasWarTimeOffset(): void
    {
        // India ran on +06:30 between 1942 and 1945, which is the offset a 1944
        // birth needs and the one arithmetic on today's +05:30 would miss.
        $instant = new DateTimeImmutable('1944-03-15T04:30:00+00:00');

        self::assertSame('1944-03-15T11:00:00', WallClock::fromDateTime($instant, 'Asia/Kolkata'));
    }

    public function testFromDateTimeRefusesAZoneThatIsNotAZone(): void
    {
        $this->expectException(InvalidArgumentException::class);
        WallClock::fromDateTime(new DateTimeImmutable(), 'Asia/Delhi');
    }

    public function testToDateTimeTurnsAWallClockAndItsOffsetIntoAnInstant(): void
    {
        $at = WallClock::toDateTime('1990-05-14T10:30:00', '+05:30');

        self::assertSame('UTC', $at->getTimezone()->getName());
        self::assertSame('1990-05-14T05:00:00+00:00', $at->format('c'));
    }

    public function testToDateTimeHandlesANegativeOffset(): void
    {
        self::assertSame(
            '2026-09-22T13:15:00+00:00',
            WallClock::toDateTime('2026-09-22T09:15:00', '-04:00')->format('c'),
        );
    }

    public function testToDateTimeAcceptsTheMillisecondsTheApiTolerates(): void
    {
        $at = WallClock::toDateTime('2026-09-22T09:15:00.250', '+00:00');

        self::assertSame('2026-09-22T09:15:00+00:00', $at->format('c'));
        self::assertSame('250000', $at->format('u'));
    }

    /**
     * @return list<array{string, string}>
     */
    public static function malformedInputs(): array
    {
        return [
            ['1990-05-14T10:30:00Z', '+05:30'],
            ['1990-05-14 10:30:00', '+05:30'],
            ['1990-05-14', '+05:30'],
            ['1990-05-14T10:30', '+05:30'],
            ['1990-05-14T10:30:00', 'Asia/Kolkata'],
            ['1990-05-14T10:30:00', '+5:30'],
            ['1990-05-14T10:30:00', '0530'],
            ['1990-05-14T10:30:00', '+0530'],
        ];
    }

    #[DataProvider('malformedInputs')]
    public function testToDateTimeRefusesAnInstantAZoneNameOrASloppyOffset(string $wall, string $offset): void
    {
        $this->expectException(InvalidArgumentException::class);
        WallClock::toDateTime($wall, $offset);
    }

    public function testOffsetAtWritesTheShapeTheApiWrites(): void
    {
        self::assertSame(
            '+05:30',
            WallClock::offsetAt(new DateTimeImmutable('2026-09-22T12:00:00+00:00'), 'Asia/Kolkata'),
        );
        self::assertSame(
            '-04:00',
            WallClock::offsetAt(new DateTimeImmutable('2026-07-01T12:00:00+00:00'), 'America/New_York'),
        );
        self::assertSame(
            '-05:00',
            WallClock::offsetAt(new DateTimeImmutable('2026-01-01T12:00:00+00:00'), 'America/New_York'),
        );
        self::assertSame(
            '+00:00',
            WallClock::offsetAt(new DateTimeImmutable('2026-01-01T12:00:00+00:00'), 'UTC'),
        );
        self::assertSame(
            '+06:30',
            WallClock::offsetAt(new DateTimeImmutable('1944-03-15T10:00:00+00:00'), 'Asia/Kolkata'),
        );
    }

    public function testOffsetAtTruncatesSecondsAsTheZoneDatabaseItselfDoes(): void
    {
        // Calcutta kept a local mean time of +05:53:20 until 1870 and Madras
        // time of +05:21:10 after it; the API writes such offsets to the minute,
        // and so does this.
        self::assertSame(
            '+05:53',
            WallClock::offsetAt(new DateTimeImmutable('1860-01-01T12:00:00+00:00'), 'Asia/Kolkata'),
        );
        self::assertSame(
            '+05:21',
            WallClock::offsetAt(new DateTimeImmutable('1900-01-01T12:00:00+00:00'), 'Asia/Kolkata'),
        );
    }

    public function testTheRoundTripGetsBackTheWallClockItStartedFrom(): void
    {
        $wall = '1990-05-14T10:30:00';

        $instant = WallClock::toDateTime($wall, '+05:30');
        self::assertSame($wall, WallClock::fromDateTime($instant, 'Asia/Kolkata'));
        self::assertSame('+05:30', WallClock::offsetAt($instant, 'Asia/Kolkata'));
    }

    public function testTheRoundTripSurvivesAnOffsetTheOtherSideOfUtc(): void
    {
        $wall = '2026-02-14T23:59:59';

        $instant = WallClock::toDateTime($wall, '-05:00');
        self::assertSame($wall, WallClock::fromDateTime($instant, 'America/New_York'));
    }
}
