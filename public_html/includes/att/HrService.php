<?php
declare(strict_types=1);

namespace Att;

use DateTimeImmutable;
use Throwable;

/**
 * The HR reconciliation queue: everything the system declined to decide.
 *
 * Five kinds of item land here, and each is something the rules say a person
 * must settle:
 *
 *   ambiguous      a HOD confirmation that fits more than one entry (AT05)
 *   disputed       two confirmations for one entry
 *   waiting        a confirmation whose gate entry never arrived
 *   held events    gate scans that could not be filed (no shift, unreadable
 *                  device time, reused sequence number)
 *   open too long  an entry with no exit scan — until it is closed the
 *                  employee's next IN is refused as ALREADY_INSIDE
 *
 * EVERY DECISION TAKES A REASON AND IS APPENDED, NEVER OVERWRITTEN.
 * Blueprint §5: "Corrections append audit entries, not destructive edits."
 * Each action writes att_corrections (the decision, with old and new state)
 * and the audit log. Gate events themselves are never edited: closing a
 * forgotten exit marks the CYCLE close_source='hr_correction' and creates no
 * fake GATE_OUT, so the movement history still shows only what the gate saw.
 */
final class HrService
{
    /** @return array<string,mixed> */
    public static function queue(): array
    {
        $windowMin  = max(1, Policy::int('hod_match_window_minutes', 240));
        $staleHours = max(1, Policy::int('stale_open_cycle_hours', 16));
        $skewMs     = max(1000, Policy::int('clock_skew_warn_ms', 120000));

        $arrivalSql = "SELECT h.id, h.event_uuid, h.status, h.occurred_at, h.received_at, h.ambiguity_reason,
                              h.gate_event_uuid, h.hod_user_id, e.id AS employee_id, e.code AS employee_code,
                              e.full_name, d.name AS department_name
                         FROM att_hod_arrivals h
                         JOIN att_employees e   ON e.id = h.employee_id
                         JOIN att_departments d ON d.id = h.department_id";

        $ambiguous = Db::all($arrivalSql . " WHERE h.status = 'ambiguous' ORDER BY h.occurred_at LIMIT 500");
        foreach ($ambiguous as &$a) {
            // Offer every unconfirmed entry of that employee from the same
            // day or so — wider than the match window on purpose: HR may know
            // something the window does not.
            $a['candidates'] = Db::all(
                "SELECT c.id AS entry_cycle_id, c.entry_sequence, c.opened_at, c.closed_at
                   FROM att_entry_cycles c
                   LEFT JOIN att_hod_arrivals x ON x.entry_cycle_id = c.id
                  WHERE c.employee_id = ? AND x.id IS NULL
                    AND c.opened_at BETWEEN (? - INTERVAL 36 HOUR) AND (? + INTERVAL 12 HOUR)
                  ORDER BY c.opened_at",
                [(int) $a['employee_id'], $a['occurred_at'], $a['occurred_at']]
            );
        }
        unset($a);

        return [
            'ambiguous' => $ambiguous,
            'disputed'  => Db::all($arrivalSql . " WHERE h.status = 'disputed' ORDER BY h.occurred_at LIMIT 500"),
            'waiting'   => Db::all(
                $arrivalSql . " WHERE h.status = 'pending_match'
                                  AND h.received_at < (UTC_TIMESTAMP(6) - INTERVAL $windowMin MINUTE)
                                ORDER BY h.occurred_at LIMIT 500"
            ),
            'held_events' => Db::all(
                "SELECT r.event_uuid, r.event_kind, r.reason_code, r.received_at, r.operator_id,
                        JSON_UNQUOTE(JSON_EXTRACT(r.request_json, '$.employee_code')) AS employee_code,
                        JSON_UNQUOTE(JSON_EXTRACT(r.request_json, '$.occurred_at'))   AS occurred_at,
                        JSON_UNQUOTE(JSON_EXTRACT(r.response_json, '$.message'))      AS message,
                        (r.request_json IS NOT NULL) AS can_retry
                   FROM att_sync_receipts r
                  WHERE r.receipt_status = 'NEEDS_REVIEW' AND r.review_state IS NULL
                    AND r.event_kind IN ('GATE_IN','GATE_OUT')
                  ORDER BY r.received_at LIMIT 500"
            ),
            'open_too_long' => Db::all(
                "SELECT c.id AS entry_cycle_id, c.opened_at, c.entry_sequence, si.work_date, si.ends_at AS shift_ends_at,
                        e.id AS employee_id, e.code AS employee_code, e.full_name, d.name AS department_name
                   FROM att_entry_cycles c
                   JOIN att_employees e        ON e.id = c.employee_id
                   JOIN att_departments d      ON d.id = e.department_id
                   JOIN att_shift_instances si ON si.id = c.shift_instance_id
                  WHERE c.closed_at IS NULL
                    AND c.opened_at < (UTC_TIMESTAMP(6) - INTERVAL $staleHours HOUR)
                  ORDER BY c.opened_at LIMIT 500"
            ),
            /* Closed by the system because no OUT was scanned (the auto-OUT
               rule). Listed for a week so HR can put in the real time. */
            'auto_closed' => Db::all(
                "SELECT c.id AS entry_cycle_id, c.opened_at, c.closed_at, c.auto_closed_at, c.present_minutes, si.work_date, si.ends_at AS shift_ends_at,
                        e.id AS employee_id, e.code AS employee_code, e.full_name, d.name AS department_name
                   FROM att_entry_cycles c
                   JOIN att_employees e        ON e.id = c.employee_id
                   JOIN att_departments d      ON d.id = e.department_id
                   JOIN att_shift_instances si ON si.id = c.shift_instance_id
                  WHERE c.close_source = 'auto_shift_end'
                    AND c.auto_closed_at > (UTC_TIMESTAMP(6) - INTERVAL 7 DAY)
                  ORDER BY c.auto_closed_at DESC, e.full_name LIMIT 500"
            ),
            'unconfirmed_entries' => (int) Db::value(
                'SELECT COUNT(*) FROM att_entry_cycles c LEFT JOIN att_hod_arrivals h ON h.entry_cycle_id = c.id WHERE h.id IS NULL'
            ),
            /* Blueprint §7: monitor clock drift. A device listed here is not
               corrected by the system — somebody sets its clock. */
            'devices_with_clock_drift' => Db::all(
                "SELECT dv.id AS device_id, dv.label, COUNT(*) AS events,
                        MAX(ABS(ev.clock_skew_ms)) AS worst_skew_ms, MAX(ev.received_at) AS last_seen
                   FROM att_events ev JOIN att_devices dv ON dv.id = ev.device_id
                  WHERE ABS(ev.clock_skew_ms) > ?
                    AND ev.received_at > (UTC_TIMESTAMP(6) - INTERVAL 7 DAY)
                  GROUP BY dv.id, dv.label ORDER BY worst_skew_ms DESC",
                [$skewMs]
            ),
            'generated_at' => Time::iso(Time::nowUtc()),
        ];
    }

    /**
     * Settle a confirmation the system would not match.
     *
     * @param 'link'|'dismiss' $action
     */
    public static function resolveArrival(int $arrivalId, string $action, ?int $cycleId, string $reason, int $actorId): array
    {
        $reason = trim($reason);
        if ($reason === '') {
            return self::no('REASON_REQUIRED', 'Say why — the reason is kept with the decision.');
        }
        $a = Db::one('SELECT * FROM att_hod_arrivals WHERE id = ?', [$arrivalId]);
        if ($a === null) {
            return self::no('NOT_FOUND', 'No such confirmation.');
        }
        if (!in_array($a['status'], ['ambiguous', 'disputed', 'pending_match'], true)) {
            return self::no('ALREADY_SETTLED', 'That confirmation has already been settled.');
        }

        if ($action === 'dismiss') {
            Db::run("UPDATE att_hod_arrivals SET status = 'dismissed', resolved_by = ?, resolved_at = ?, resolution_note = ? WHERE id = ?",
                [$actorId, Time::sql(), $reason, $arrivalId]);
            self::correction('att_hod_arrival', $arrivalId, 'hod.dismiss', ['status' => $a['status']], ['status' => 'dismissed'], $reason, $actorId);
            return ['ok' => true, 'status' => 'dismissed'];
        }

        if ($action !== 'link' || !$cycleId) {
            return self::no('VALIDATION', 'Choose the entry this confirmation belongs to, or dismiss it.');
        }
        $cycle = Db::one('SELECT id, employee_id FROM att_entry_cycles WHERE id = ?', [$cycleId]);
        if ($cycle === null) {
            return self::no('CYCLE_NOT_FOUND', 'No such entry.');
        }
        /* Even HR cannot attach a confirmation for one person to another
           person's entry: that is not a correction, it is a different fact. */
        if ((int) $cycle['employee_id'] !== (int) $a['employee_id']) {
            return self::no('WRONG_EMPLOYEE', 'That entry belongs to a different employee.');
        }
        try {
            Db::run(
                "UPDATE att_hod_arrivals
                    SET entry_cycle_id = ?, status = 'confirmed', match_method = 'hr_manual', matched_at = ?,
                        resolved_by = ?, resolved_at = ?, resolution_note = ?
                  WHERE id = ?",
                [$cycleId, Time::sql(), $actorId, Time::sql(), $reason, $arrivalId]
            );
        } catch (Throwable $e) {
            if (Db::isDuplicate($e)) {
                return self::no('ALREADY_CONFIRMED', 'That entry already has a confirmation.');
            }
            throw $e;
        }
        self::correction('att_hod_arrival', $arrivalId, 'hod.link',
            ['status' => $a['status'], 'entry_cycle_id' => null],
            ['status' => 'confirmed', 'entry_cycle_id' => $cycleId, 'match_method' => 'hr_manual'], $reason, $actorId);
        return ['ok' => true, 'status' => 'confirmed', 'entry_cycle_id' => $cycleId];
    }

    /**
     * Close an entry whose exit was never scanned.
     *
     * $closedAt is the time HR states the employee left. It must not be
     * before the entry began or in the future. No GATE_OUT event is created.
     */
    public static function closeCycle(int $cycleId, ?DateTimeImmutable $closedAt, string $reason, int $actorId): array
    {
        $reason = trim($reason);
        if ($reason === '') {
            return self::no('REASON_REQUIRED', 'Say why — the reason is kept with the decision.');
        }
        if ($closedAt === null) {
            return self::no('VALIDATION', 'Give the time the employee left.');
        }

        return Db::tx(static function () use ($cycleId, $closedAt, $reason, $actorId) {
            $c = Db::one('SELECT id, employee_id, opened_at, closed_at, close_source, present_minutes FROM att_entry_cycles WHERE id = ? FOR UPDATE', [$cycleId]);
            if ($c === null) {
                return self::no('CYCLE_NOT_FOUND', 'No such entry.');
            }
            /* An entry the SYSTEM closed (no OUT scanned) may be given its
               real time; one closed by a gate scan or by HR may not. */
            $wasAuto = $c['close_source'] === AttendanceService::AUTO_OUT;
            if ($c['closed_at'] !== null && !$wasAuto) {
                return self::no('ALREADY_CLOSED', 'That entry is already closed.');
            }
            $opened = Time::fromDb((string) $c['opened_at']);
            if ($opened === null || $closedAt < $opened) {
                return self::no('VALIDATION', 'The exit time cannot be before the entry time.');
            }
            if ($closedAt > Time::nowUtc()->modify('+1 minute')) {
                return self::no('VALIDATION', 'The exit time cannot be in the future.');
            }
            $minutes = intdiv($closedAt->getTimestamp() - $opened->getTimestamp(), 60);

            $corrId = self::correction('att_entry_cycle', $cycleId, $wasAuto ? 'cycle.retime_auto_out' : 'cycle.close',
                $wasAuto ? ['closed_at' => $c['closed_at'], 'present_minutes' => $c['present_minutes'], 'close_source' => AttendanceService::AUTO_OUT] : ['closed_at' => null],
                ['closed_at' => Time::sql($closedAt), 'present_minutes' => $minutes, 'close_source' => 'hr_correction'],
                $reason, $actorId);

            Db::run(
                "UPDATE att_entry_cycles
                    SET closed_at = ?, present_minutes = ?, close_source = 'hr_correction', correction_id = ?
                  WHERE id = ? AND (closed_at IS NULL OR close_source = 'auto_shift_end')",
                [Time::sql($closedAt), $minutes, $corrId, $cycleId]
            );
            return ['ok' => true, 'entry_cycle_id' => $cycleId, 'present_minutes' => $minutes, 'correction_id' => $corrId];
        });
    }

    /**
     * Deal with a gate event that was held for review.
     *
     * @param 'retry'|'dismiss' $action
     */
    public static function reviewEvent(string $uuid, string $action, string $reason, int $actorId): array
    {
        $reason = trim($reason);
        if ($reason === '') {
            return self::no('REASON_REQUIRED', 'Say why — the reason is kept with the decision.');
        }
        if ($action === 'retry') {
            return SyncService::retryReview($uuid, $actorId, $reason);
        }
        if ($action !== 'dismiss') {
            return self::no('VALIDATION', 'Choose retry or dismiss.');
        }
        $st = Db::run(
            "UPDATE att_sync_receipts SET review_state = 'dismissed', reviewed_by = ?, reviewed_at = ?, review_note = ?
              WHERE event_uuid = ? AND receipt_status = 'NEEDS_REVIEW' AND review_state IS NULL",
            [$actorId, Time::sql(), $reason, $uuid]
        );
        if ($st->rowCount() === 0) {
            return self::no('NOT_REVIEWABLE', 'That event is not waiting for review.');
        }
        self::correction('att_sync_receipt', 0, 'event.dismiss', ['event_uuid' => $uuid], ['review_state' => 'dismissed'], $reason, $actorId);
        return ['ok' => true, 'review_state' => 'dismissed'];
    }

    /** @return list<array<string,mixed>> */
    public static function devices(): array
    {
        $users = Db::tableExists('users');
        return Db::all(
            'SELECT dv.id, dv.device_uuid, dv.label, dv.role_scope, dv.app_version, dv.last_seen_at, dv.revoked_at, dv.revoke_reason, dv.created_at,
                    ' . ($users ? 'u.name' : 'NULL') . ' AS registered_by_name,
                    (SELECT COUNT(*) FROM att_events ev WHERE ev.device_id = dv.id) AS scans
               FROM att_devices dv ' . ($users ? 'LEFT JOIN users u ON u.id = dv.registered_by ' : '') . '
              ORDER BY (dv.revoked_at IS NOT NULL), dv.last_seen_at DESC, dv.id DESC'
        );
    }

    private static function correction(string $type, int $id, string $action, ?array $old, ?array $new, string $reason, int $actorId): int
    {
        Db::run(
            'INSERT INTO att_corrections (subject_type, subject_id, action, old_data, new_data, reason, actor_id) VALUES (?,?,?,?,?,?,?)',
            [$type, $id, $action, $old === null ? null : json_encode($old), $new === null ? null : json_encode($new), $reason, $actorId]
        );
        $corrId = Db::lastId();
        Audit::record($action, $type, $id, $old, $new, $actorId, $reason);
        return $corrId;
    }

    private static function no(string $code, string $message): array
    {
        return ['ok' => false, 'reason_code' => $code, 'error' => $message];
    }
}
