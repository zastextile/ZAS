<?php
declare(strict_types=1);

namespace Att;

use DateTimeImmutable;
use DateTimeZone;

/**
 * Shifts, and the shift instance a gate event belongs to.
 *
 * The hard part here is not the table, it is D4 and midnight.
 *
 * MIDNIGHT. A shift of 19:00–03:00 belongs to one working day but spans two
 * calendar dates. Every instance is therefore stored as two absolute UTC
 * instants already resolved, plus the work_date it is filed under, so no
 * reporting query ever has to work out whether 02:00 belonged to yesterday.
 * Getting this wrong puts a night worker's entry on the wrong day and makes
 * their overtime unprovable.
 *
 * D4 (approved 2026-11-10). An employee who reaches the gate with no shift
 * assigned is ADMITTED and a configurable default shift is attached, marked
 * source='default_auto' so HR can see which days the system filled in. The
 * alternative — refusing the scan — stops a worker starting their shift over
 * missing paperwork, which is a worse failure than a data correction.
 */
final class Shifts
{
    /** @param array<string,mixed> $data */
    public static function create(array $data, ?int $actorId = null): int
    {
        $name = trim((string) ($data['name'] ?? ''));
        if ($name === '') {
            throw new \InvalidArgumentException('Shift name is required.');
        }
        foreach (['start_time', 'end_time'] as $k) {
            if (!preg_match('/^\d{2}:\d{2}(:\d{2})?$/', (string) ($data[$k] ?? ''))) {
                throw new \InvalidArgumentException("$k must be HH:MM or HH:MM:SS.");
            }
        }

        Db::run(
            'INSERT INTO att_shifts
               (name, start_time, end_time, timezone, break_minutes, grace_in_minutes, grace_out_minutes, active)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?)',
            [
                $name,
                (string) $data['start_time'],
                (string) $data['end_time'],
                (string) ($data['timezone'] ?? 'Asia/Karachi'),
                (int) ($data['break_minutes'] ?? 0),
                (int) ($data['grace_in_minutes'] ?? 0),
                (int) ($data['grace_out_minutes'] ?? 0),
                isset($data['active']) ? (int) (bool) $data['active'] : 1,
            ]
        );
        $id = Db::lastId();
        Audit::record('shift.create', 'att_shift', $id, null, ['name' => $name], $actorId);
        return $id;
    }

    public static function find(int $id): ?array
    {
        return Db::one('SELECT * FROM att_shifts WHERE id = ?', [$id]);
    }

    /** @return list<array<string,mixed>> */
    public static function all(bool $activeOnly = true): array
    {
        return Db::all(
            'SELECT id, name, start_time, end_time, timezone, break_minutes,
                    grace_in_minutes, grace_out_minutes, crosses_midnight, active
               FROM att_shifts' . ($activeOnly ? ' WHERE active = 1' : '') . ' ORDER BY start_time, name'
        );
    }

    /**
     * Turn a shift plus a working date into two absolute UTC instants.
     *
     * $workDate is the day the shift is FILED under — for a night shift that
     * is the date it begins, even though it ends on the next calendar day.
     *
     * @return array{starts_at:DateTimeImmutable,ends_at:DateTimeImmutable}
     */
    public static function resolveWindow(array $shift, string $workDate): array
    {
        $tzName = (string) ($shift['timezone'] ?? 'Asia/Karachi');
        try {
            $tz = new DateTimeZone($tzName);
        } catch (\Throwable) {
            $tz = new DateTimeZone('Asia/Karachi');
        }

        $start = new DateTimeImmutable($workDate . ' ' . $shift['start_time'], $tz);
        $end   = new DateTimeImmutable($workDate . ' ' . $shift['end_time'], $tz);

        // end <= start means the shift runs into the next calendar day.
        if ($end <= $start) {
            $end = $end->modify('+1 day');
        }

        return [
            'starts_at' => $start->setTimezone(new DateTimeZone(Time::UTC)),
            'ends_at'   => $end->setTimezone(new DateTimeZone(Time::UTC)),
        ];
    }

    /**
     * Assign a shift to an employee for a working date.
     * Re-assigning the same day updates it rather than creating a second
     * instance — the unique key on (employee_id, work_date) makes two
     * parallel streams of entry cycles impossible.
     */
    public static function assign(
        int $employeeId,
        int $shiftId,
        string $workDate,
        string $source = 'assigned',
        ?int $actorId = null
    ): int {
        $shift = self::find($shiftId);
        if ($shift === null) {
            throw new \RuntimeException('Shift not found.', 404);
        }
        if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $workDate)) {
            throw new \InvalidArgumentException('work_date must be YYYY-MM-DD.');
        }

        $w = self::resolveWindow($shift, $workDate);

        Db::run(
            'INSERT INTO att_shift_instances
               (employee_id, shift_id, work_date, starts_at, ends_at, source, created_by)
             VALUES (?, ?, ?, ?, ?, ?, ?)
             ON DUPLICATE KEY UPDATE
               shift_id  = VALUES(shift_id),
               starts_at = VALUES(starts_at),
               ends_at   = VALUES(ends_at),
               source    = VALUES(source)',
            [
                $employeeId, $shiftId, $workDate,
                Time::sql($w['starts_at']), Time::sql($w['ends_at']),
                $source, $actorId,
            ]
        );

        $row = Db::one(
            'SELECT id FROM att_shift_instances WHERE employee_id = ? AND work_date = ?',
            [$employeeId, $workDate]
        );
        $id = (int) ($row['id'] ?? 0);

        Audit::record('shift.assign', 'att_shift_instance', $id, null, [
            'employee_id' => $employeeId,
            'shift_id'    => $shiftId,
            'work_date'   => $workDate,
            'source'      => $source,
        ], $actorId);

        return $id;
    }

    /**
     * The instance a gate event at $at belongs to.
     *
     * Looks for an instance whose window contains the moment, allowing the
     * shift's own grace either side — somebody who clocks in five minutes
     * early is starting their shift, not standing outside it. Falls back to
     * the instance filed under the local working date, which is what an
     * ordinary mid-shift event matches.
     */
    public static function instanceFor(int $employeeId, DateTimeImmutable $at): ?array
    {
        $atSql = Time::sql($at);

        $row = Db::one(
            'SELECT si.*, s.grace_in_minutes, s.grace_out_minutes, s.timezone, s.crosses_midnight
               FROM att_shift_instances si
               JOIN att_shifts s ON s.id = si.shift_id
              WHERE si.employee_id = ?
                AND ? >= DATE_SUB(si.starts_at, INTERVAL s.grace_in_minutes MINUTE)
                AND ? <= DATE_ADD(si.ends_at,   INTERVAL s.grace_out_minutes MINUTE)
              ORDER BY si.starts_at DESC
              LIMIT 1',
            [$employeeId, $atSql, $atSql]
        );

        return $row ?: null;
    }

    /**
     * The instance a gate event belongs to, creating one from the default
     * shift when the employee has none — D4.
     *
     * Returns [instance, created] so the caller can tell the difference
     * between a planned day and one the system filled in, and record it.
     *
     * @return array{0:?array,1:bool}
     */
    public static function instanceForOrDefault(int $employeeId, DateTimeImmutable $at, ?int $actorId = null): array
    {
        $existing = self::instanceFor($employeeId, $at);
        if ($existing !== null) {
            return [$existing, false];
        }

        if (!Policy::bool('auto_shift_on_gate_in')) {
            return [null, false];
        }

        $defaultId = (int) Policy::get('default_shift_id', '');
        if ($defaultId <= 0) {
            // Configured to auto-assign but nobody has chosen the default
            // shift yet. Say so plainly rather than inventing one.
            return [null, false];
        }
        $shift = self::find($defaultId);
        if ($shift === null || (int) $shift['active'] !== 1) {
            return [null, false];
        }

        // File it under the local working date of the moment, in the shift's
        // own timezone — not the server's, and not UTC.
        try {
            $tz = new DateTimeZone((string) $shift['timezone']);
        } catch (\Throwable) {
            $tz = new DateTimeZone('Asia/Karachi');
        }
        $workDate = $at->setTimezone($tz)->format('Y-m-d');

        // A night shift beginning at 19:00 and scanned at 01:00 belongs to the
        // PREVIOUS working date, not the one the clock shows.
        if ((int) $shift['crosses_midnight'] === 1) {
            $localTime  = $at->setTimezone($tz)->format('H:i:s');
            if ($localTime < (string) $shift['start_time']) {
                $workDate = $at->setTimezone($tz)->modify('-1 day')->format('Y-m-d');
            }
        }

        /* Check the default shift actually COVERS this moment before writing
           anything. A scan at 19:00 against a 09:00–17:00 default is somebody
           working out of hours: the right answer is to hold the event for HR,
           not to file it under a shift that had already ended. An earlier
           draft assigned first and re-checked afterwards, which left an
           orphan "default_auto" instance on the employee's record for a day
           they were never scheduled — invisible, and wrong in any report that
           counts scheduled days. */
        $window = self::resolveWindow($shift, $workDate);
        $graceIn  = (int) $shift['grace_in_minutes'];
        $graceOut = (int) $shift['grace_out_minutes'];
        $from = $window['starts_at']->modify("-{$graceIn} minutes");
        $to   = $window['ends_at']->modify("+{$graceOut} minutes");

        if ($at < $from || $at > $to) {
            return [null, false];
        }

        self::assign($employeeId, $defaultId, $workDate, 'default_auto', $actorId);
        $created = self::instanceFor($employeeId, $at);

        return [$created, $created !== null];
    }
}
