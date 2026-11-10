<?php
declare(strict_types=1);

namespace Att;

use DateTimeImmutable;
use DateTimeZone;

/**
 * Time, stored one way and shown another.
 *
 * The contract says "All timestamps UTC in DB; return ISO 8601 with offset for
 * display". The surrounding app calls date_default_timezone_set('Asia/Karachi')
 * in bootstrap.php, so any bare date() or new DateTime() in this module would
 * quietly produce Karachi local time and write it into a UTC column. Every
 * attendance timestamp goes through here instead, so there is one place that
 * can be wrong rather than fifty.
 *
 * This matters more than usual here: shifts cross midnight, devices replay
 * events hours late, and a six-hour error turns a night shift's entry into the
 * previous day's.
 */
final class Time
{
    public const UTC = 'UTC';

    public static function nowUtc(): DateTimeImmutable
    {
        return new DateTimeImmutable('now', new DateTimeZone(self::UTC));
    }

    /** MySQL DATETIME(6) literal, always UTC. */
    public static function sql(?DateTimeImmutable $t = null): string
    {
        return ($t ?? self::nowUtc())->setTimezone(new DateTimeZone(self::UTC))->format('Y-m-d H:i:s.u');
    }

    /**
     * Parse an incoming client timestamp into UTC.
     *
     * Returns null rather than guessing when the value is unusable — a gate
     * event with an unparseable time must become NEEDS_REVIEW, not silently
     * land on "now", which would look like a perfectly ordinary entry.
     */
    public static function parse(?string $value, ?string $timezone = null): ?DateTimeImmutable
    {
        $value = trim((string) $value);
        if ($value === '') {
            return null;
        }
        try {
            $tz = new DateTimeZone($timezone !== null && $timezone !== '' ? $timezone : self::UTC);
        } catch (\Throwable) {
            $tz = new DateTimeZone(self::UTC);
        }
        try {
            // A value carrying its own offset ("…Z", "+05:00") keeps it; a bare
            // local stamp is read in the device's declared zone.
            $t = new DateTimeImmutable($value, $tz);
        } catch (\Throwable) {
            return null;
        }
        return $t->setTimezone(new DateTimeZone(self::UTC));
    }

    /** ISO 8601 with offset, for display. */
    public static function iso(?DateTimeImmutable $t, string $timezone = 'Asia/Karachi'): ?string
    {
        if ($t === null) {
            return null;
        }
        try {
            $tz = new DateTimeZone($timezone);
        } catch (\Throwable) {
            $tz = new DateTimeZone(self::UTC);
        }
        return $t->setTimezone($tz)->format('c');
    }

    /** Read a UTC column back out as a DateTimeImmutable. */
    public static function fromDb(?string $value): ?DateTimeImmutable
    {
        if ($value === null || trim($value) === '') {
            return null;
        }
        try {
            return new DateTimeImmutable($value, new DateTimeZone(self::UTC));
        } catch (\Throwable) {
            return null;
        }
    }

    /**
     * How far a device's clock is from the server's, in milliseconds.
     * Recorded, never corrected — blueprint §3 wants skew tracked, and a
     * device whose clock is wrong should be visible, not silently adjusted.
     */
    public static function skewMs(DateTimeImmutable $deviceTime, ?DateTimeImmutable $serverTime = null): int
    {
        $server = $serverTime ?? self::nowUtc();
        return (int) round(((float) $deviceTime->format('U.u') - (float) $server->format('U.u')) * 1000);
    }
}
