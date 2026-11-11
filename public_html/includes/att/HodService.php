<?php
declare(strict_types=1);

namespace Att;

use DateTimeImmutable;
use Throwable;

/**
 * HOD arrival confirmation — one per entry cycle, including every return.
 *
 * The rules, from the approved baseline:
 *
 *   Invariant 1  Every cycle requires its own HOD confirmation.
 *   Invariant 5  HOD events arriving before gate events remain pending.
 *                Never auto-match ambiguous events.
 *   §5           Link each confirmation to exactly one cycle. Never treat HOD
 *                arrival as a second payroll IN.
 *   AT06         Bulk verifies each cycle once, restricted to the HOD's
 *                assigned department.
 *
 * HOW A CONFIRMATION FINDS ITS CYCLE, in the order the blueprint gives (§3):
 *
 *   1. the HOD named the cycle           → explicit, nothing to decide
 *   2. the HOD's device knew the gate    → look the cycle up by that event;
 *      event's UUID                        if the gate event has not arrived
 *                                          yet, WAIT (pending_match)
 *   3. neither                           → look for unconfirmed cycles for
 *                                          that employee near that time:
 *                                            exactly one → confirm it
 *                                            none        → WAIT
 *                                            two or more → AMBIGUOUS, to HR
 *
 * Step 3 never picks "the most likely one". Two candidates means a person
 * decides (AT05). The system matching the wrong cycle would attach a HOD's
 * word to an entry the HOD never saw.
 *
 * WHO MAY CONFIRM WHOM is decided here, on the server, from att_user_roles —
 * not by which rows the screen happened to show. A HOD confirming a cycle
 * outside their department gets the same answer as for a cycle that does not
 * exist, so cycle ids cannot be used to probe other departments.
 */
final class HodService
{
    public const KIND = 'HOD_CONFIRM';
    public const BULK_MAX = 200;

    /**
     * Confirm one arrival.
     *
     * @param array{event_uuid:string,hod_user_id:int,entry_cycle_id?:int,employee_id?:int,
     *              gate_event_uuid?:string,device_id?:int,device_sequence?:int,
     *              occurred_at?:?DateTimeImmutable,device_time?:?string,timezone?:?string,
     *              source?:string} $in
     * @return array<string,mixed> receipt
     */
    public static function confirm(array $in): array
    {
        $uuid = trim((string) ($in['event_uuid'] ?? ''));
        if (!Receipts::validUuid($uuid)) {
            return Receipts::reject($uuid, self::KIND, 'BAD_EVENT_UUID',
                'Every confirmation needs a stable UUID so a replay can be recognised.');
        }

        // Replays get their original answer, before anything is re-judged.
        $existing = Receipts::find($uuid);
        if ($existing !== null) {
            return $existing;
        }

        $hodUserId = (int) ($in['hod_user_id'] ?? 0);
        if ($hodUserId <= 0) {
            return Receipts::reject($uuid, self::KIND, 'VALIDATION', 'A signed-in HOD is required.');
        }

        /* A device is optional here (an admin may confirm from a desktop), but
           when one is named it must be registered, live, and registered for
           HOD use — a gate handset cannot post confirmations. */
        $deviceId = ((int) ($in['device_id'] ?? 0)) ?: null;
        $deviceProblem = Receipts::checkDevice($deviceId, 'hod', $uuid, self::KIND, false);
        if ($deviceProblem !== null) {
            return $deviceProblem;
        }

        $deviceTime = Time::parse((string) ($in['device_time'] ?? ''), $in['timezone'] ?? null);
        if (($in['device_time'] ?? '') !== '' && $deviceTime === null) {
            return Receipts::review($uuid, self::KIND, 'UNREADABLE_DEVICE_TIME',
                'The device sent a time that could not be read.', $deviceId);
        }
        $occurred = $in['occurred_at'] ?? $deviceTime ?? Time::nowUtc();
        /* Skew is what the caller measured when it could measure it properly
           (SyncService, from the phone's clock at upload time). Only a direct
           caller with nothing better falls back to device time minus now —
           which for an event replayed hours later is queue delay, not clock
           error, so SyncService never lets that through. */
        $skew = array_key_exists('clock_skew_ms', $in)
            ? Time::clampSkew($in['clock_skew_ms'] === null ? null : (int) $in['clock_skew_ms'])
            : ($deviceTime !== null ? Time::skewMs($deviceTime) : null);

        $scope = Roles::departmentScope($hodUserId, Roles::HOD);   // null = all (admin)

        $ctx = [
            'uuid' => $uuid, 'hod' => $hodUserId, 'device' => $deviceId,
            'sequence' => isset($in['device_sequence']) && $deviceId ? (int) $in['device_sequence'] : null,
            'occurred' => $occurred, 'device_time' => $deviceTime, 'skew' => $skew,
            'source' => ($in['source'] ?? 'online') === 'offline_replay' ? 'offline_replay' : 'online',
            'gate_uuid' => self::cleanUuid($in['gate_event_uuid'] ?? null),
        ];

        // ---- 1. the HOD named the cycle
        $cycleId = (int) ($in['entry_cycle_id'] ?? 0);
        if ($cycleId > 0) {
            $cycle = self::cycleInScope($cycleId, $scope);
            if ($cycle === null) {
                return Receipts::reject($uuid, self::KIND, 'CYCLE_NOT_FOUND', 'No such entry cycle.', $deviceId);
            }
            return self::link($ctx, $cycle, 'explicit_cycle');
        }

        // ---- 2. the device knew the gate event
        if ($ctx['gate_uuid'] !== null) {
            $cycle = Db::one(
                'SELECT c.id FROM att_entry_cycles c
                   JOIN att_events ev ON ev.id = c.gate_in_event_id
                  WHERE ev.event_uuid = ?',
                [$ctx['gate_uuid']]
            );
            if ($cycle !== null) {
                $cycle = self::cycleInScope((int) $cycle['id'], $scope);
                if ($cycle === null) {
                    return Receipts::reject($uuid, self::KIND, 'CYCLE_NOT_FOUND', 'No such entry cycle.', $deviceId);
                }
                return self::link($ctx, $cycle, 'gate_event_uuid');
            }
            // The gate's phone has not synced yet. Fall through to wait —
            // with the employee, if we were told who it is.
        }

        // ---- 3. only the employee is known
        $employeeId = (int) ($in['employee_id'] ?? 0);
        $employee = $employeeId > 0
            ? Db::one('SELECT id, department_id, active FROM att_employees WHERE id = ?', [$employeeId])
            : null;
        if ($employee === null || ($scope !== null && !in_array((int) $employee['department_id'], $scope, true))) {
            // Outside the HOD's department reads exactly like "does not exist".
            return Receipts::reject($uuid, self::KIND, 'EMPLOYEE_NOT_FOUND', 'No such employee.', $deviceId);
        }

        if ($ctx['gate_uuid'] !== null) {
            // We know precisely which gate event this belongs to; do not guess
            // at another cycle while waiting for it.
            return self::park($ctx, $employee, 'pending_match', 'AWAITING_GATE_EVENT',
                'Saved. It will be matched when the gate\'s record of this entry arrives.');
        }

        $candidates = self::candidateCycles((int) $employee['id'], $occurred);

        if (count($candidates) === 1) {
            $cycle = self::cycleInScope((int) $candidates[0]['id'], $scope);
            if ($cycle !== null) {
                return self::link($ctx, $cycle, 'sequence');
            }
        }
        if (count($candidates) > 1) {
            /* AT05. Two or more plausible entries: a person decides. */
            return self::park($ctx, $employee, 'ambiguous', 'AMBIGUOUS_MATCH',
                'More than one entry could match this confirmation. It has been sent to HR to decide.',
                'candidate cycles: ' . implode(',', array_column($candidates, 'id')));
        }

        return self::park($ctx, $employee, 'pending_match', 'AWAITING_GATE_EVENT',
            'Saved. No gate entry for this employee has reached the server yet; it will be matched when one does.');
    }

    /**
     * Confirm many cycles at once.
     *
     * API_CONTRACT: {entry_cycle_ids:[...], confirmed_at, event_uuids:[...]} —
     * one event UUID per cycle. Each cycle is its own transaction and gets its
     * own receipt: one that is already confirmed, or outside the department,
     * does not void the other forty.
     *
     * @param list<int> $cycleIds
     * @param list<string> $uuids
     * @return array<string,mixed>
     */
    public static function confirmBulk(array $cycleIds, array $uuids, int $hodUserId, array $common = []): array
    {
        $cycleIds = array_values($cycleIds);
        $uuids    = array_values($uuids);

        if ($cycleIds === []) {
            return ['ok' => false, 'reason_code' => 'VALIDATION', 'error' => 'No entry cycles were selected.'];
        }
        if (count($cycleIds) !== count($uuids)) {
            return ['ok' => false, 'reason_code' => 'VALIDATION',
                    'error' => 'Each entry cycle needs its own event UUID (one per cycle).'];
        }
        if (count($cycleIds) > self::BULK_MAX) {
            return ['ok' => false, 'reason_code' => 'VALIDATION',
                    'error' => 'At most ' . self::BULK_MAX . ' cycles can be confirmed in one request.'];
        }
        /* The same cycle listed twice, or one UUID reused for two cycles, is a
           client bug. Refused whole rather than half-processed: with a reused
           UUID the second cycle would be answered with the first one's stored
           receipt and look confirmed when it was not. */
        if (count(array_unique(array_map('intval', $cycleIds))) !== count($cycleIds)
            || count(array_unique(array_map('strtolower', array_map('strval', $uuids)))) !== count($uuids)) {
            return ['ok' => false, 'reason_code' => 'VALIDATION',
                    'error' => 'Entry cycles and event UUIDs must each be listed once.'];
        }

        $results = [];
        $summary = [];
        foreach ($cycleIds as $i => $cycleId) {
            $receipt = self::confirm(array_merge($common, [
                'event_uuid'     => (string) $uuids[$i],
                'entry_cycle_id' => (int) $cycleId,
                'hod_user_id'    => $hodUserId,
                // A device sequence belongs to one event; a bulk request
                // carries one number at most, so it is not applied per item.
                'device_sequence' => null,
            ]));
            $receipt['entry_cycle_id'] = $receipt['entry_cycle_id'] ?? (int) $cycleId;
            $results[] = $receipt;
            $summary[$receipt['receipt_status']] = ($summary[$receipt['receipt_status']] ?? 0) + 1;
        }

        return ['ok' => true, 'results' => $results, 'summary' => $summary];
    }

    // --------------------------------------------------------------- queries

    /**
     * Entry cycles still owing a confirmation.
     *
     * Includes cycles that have already CLOSED: an employee who came and went
     * before the HOD looked still owes one (invariant 1 has no exception for
     * short visits).
     *
     * @param list<int>|null $departmentIds null = all
     * @return list<array<string,mixed>>
     */
    public static function pending(?array $departmentIds, ?string $workDate = null, int $limit = 500): array
    {
        if ($departmentIds === []) {
            return [];
        }
        $sql = 'SELECT c.id AS entry_cycle_id, c.entry_sequence, c.opened_at, c.closed_at,
                       e.id AS employee_id, e.code AS employee_code, e.full_name,
                       (e.reference_photo_key IS NOT NULL) AS has_photo,
                       e.department_id, d.name AS department_name, si.work_date,
                       ev.event_uuid AS gate_event_uuid
                  FROM att_entry_cycles c
                  JOIN att_employees e        ON e.id = c.employee_id
                  JOIN att_departments d      ON d.id = e.department_id
                  JOIN att_shift_instances si ON si.id = c.shift_instance_id
                  JOIN att_events ev          ON ev.id = c.gate_in_event_id
                  LEFT JOIN att_hod_arrivals h ON h.entry_cycle_id = c.id
                 WHERE h.id IS NULL';
        $params = [];
        if ($departmentIds !== null) {
            $sql .= ' AND e.department_id IN (' . implode(',', array_fill(0, count($departmentIds), '?')) . ')';
            foreach ($departmentIds as $d) { $params[] = (int) $d; }
        }
        if ($workDate !== null && preg_match('/^\d{4}-\d{2}-\d{2}$/', $workDate)) {
            $sql .= ' AND si.work_date = ?';
            $params[] = $workDate;
        }
        $sql .= ' ORDER BY c.opened_at, c.id LIMIT ' . max(1, min(2000, $limit));
        return Db::all($sql, $params);
    }

    /**
     * Confirmations already made, for the HOD's own history screen.
     *
     * @param list<int>|null $departmentIds
     * @return list<array<string,mixed>>
     */
    public static function arrivals(?array $departmentIds, ?string $date = null, int $limit = 200): array
    {
        if ($departmentIds === []) {
            return [];
        }
        $sql = 'SELECT h.id, h.event_uuid, h.entry_cycle_id, h.status, h.occurred_at, h.match_method,
                       h.hod_user_id, e.code AS employee_code, e.full_name, h.department_id
                  FROM att_hod_arrivals h
                  JOIN att_employees e ON e.id = h.employee_id
                 WHERE 1 = 1';
        $params = [];
        if ($departmentIds !== null) {
            $sql .= ' AND h.department_id IN (' . implode(',', array_fill(0, count($departmentIds), '?')) . ')';
            foreach ($departmentIds as $d) { $params[] = (int) $d; }
        }
        if ($date !== null && preg_match('/^\d{4}-\d{2}-\d{2}$/', $date)) {
            $sql .= ' AND DATE(h.occurred_at) = ?';
            $params[] = $date;
        }
        $sql .= ' ORDER BY h.occurred_at DESC, h.id DESC LIMIT ' . max(1, min(1000, $limit));
        return Db::all($sql, $params);
    }

    // -------------------------------------------------------------- internals

    /** The cycle, if it exists AND lies inside the caller's department scope. */
    private static function cycleInScope(int $cycleId, ?array $scope): ?array
    {
        if ($scope === []) {
            return null;
        }
        $sql = 'SELECT c.id, c.employee_id, c.opened_at, c.entry_sequence, e.department_id
                  FROM att_entry_cycles c
                  JOIN att_employees e ON e.id = c.employee_id
                 WHERE c.id = ?';
        $params = [$cycleId];
        if ($scope !== null) {
            $sql .= ' AND e.department_id IN (' . implode(',', array_fill(0, count($scope), '?')) . ')';
            foreach ($scope as $d) { $params[] = (int) $d; }
        }
        return Db::one($sql, $params);
    }

    /**
     * Unconfirmed cycles this confirmation could plausibly belong to.
     *
     * "Near that time" is a policy, not a constant: the entry must have opened
     * no more than hod_match_window_minutes before the HOD saw the employee,
     * and not after it by more than the tolerated clock skew. Without the
     * window, a confirmation today would happily attach itself to a cycle the
     * HOD forgot to confirm last week.
     *
     * @return list<array<string,mixed>>
     */
    public static function candidateCycles(int $employeeId, DateTimeImmutable $hodTime): array
    {
        $windowMin = max(1, Policy::int('hod_match_window_minutes', 240));
        $skewSec   = (int) ceil(max(0, Policy::int('clock_skew_warn_ms', 120000)) / 1000);

        return Db::all(
            'SELECT c.id, c.opened_at
               FROM att_entry_cycles c
               LEFT JOIN att_hod_arrivals h ON h.entry_cycle_id = c.id
              WHERE c.employee_id = ?
                AND h.id IS NULL
                AND c.opened_at >= ?
                AND c.opened_at <= ?
              ORDER BY c.opened_at',
            [
                $employeeId,
                Time::sql($hodTime->modify("-{$windowMin} minutes")),
                Time::sql($hodTime->modify("+{$skewSec} seconds")),
            ]
        );
    }

    /** Write the confirmation against a cycle. The unique key is the referee. */
    private static function link(array $ctx, array $cycle, string $method): array
    {
        $cycleId = (int) $cycle['id'];
        try {
            Db::run(
                'INSERT INTO att_hod_arrivals
                   (event_uuid, employee_id, entry_cycle_id, hod_user_id, department_id,
                    device_id, device_sequence, occurred_at, device_time, clock_skew_ms,
                    status, match_method, matched_at, gate_event_uuid, source, provisional)
                 VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, \'confirmed\', ?, ?, ?, ?, ?)',
                [
                    $ctx['uuid'], (int) $cycle['employee_id'], $cycleId, $ctx['hod'], (int) $cycle['department_id'],
                    $ctx['device'], $ctx['sequence'],
                    Time::sql($ctx['occurred']),
                    $ctx['device_time'] ? Time::sql($ctx['device_time']) : null,
                    $ctx['skew'], $method, Time::sql(), $ctx['gate_uuid'],
                    $ctx['source'], $ctx['source'] === 'offline_replay' ? 1 : 0,
                ]
            );
        } catch (Throwable $e) {
            return self::fromRace($e, $ctx, $cycleId);
        }
        $id = Db::lastId();

        $openedAt = Time::fromDb((string) $cycle['opened_at']);
        /* Recorded, not enforced: a HOD time earlier than the gate time is a
           clock problem on one of the two devices. The link itself is not in
           doubt (the cycle was named or uniquely found), so the confirmation
           stands and the oddity is visible in the receipt and the audit. */
        $beforeGate = $openedAt !== null && $ctx['occurred'] < $openedAt;

        Audit::record('hod.confirm', 'att_entry_cycle', $cycleId, null, [
            'hod_arrival_id' => $id,
            'event_uuid'     => $ctx['uuid'],
            'match_method'   => $method,
            'occurred_at'    => Time::sql($ctx['occurred']),
            'before_gate_in' => $beforeGate,
        ], $ctx['hod']);

        return Receipts::accept($ctx['uuid'], self::KIND, $id, $ctx['device'], [
            'entry_cycle_id'  => $cycleId,
            'employee_id'     => (int) $cycle['employee_id'],
            'match_method'    => $method,
            'before_gate_in'  => $beforeGate,
        ]);
    }

    /** Store a confirmation that cannot be linked yet (waiting, or ambiguous). */
    private static function park(array $ctx, array $employee, string $status, string $reason, string $message, ?string $why = null): array
    {
        try {
            Db::run(
                'INSERT INTO att_hod_arrivals
                   (event_uuid, employee_id, entry_cycle_id, hod_user_id, department_id,
                    device_id, device_sequence, occurred_at, device_time, clock_skew_ms,
                    status, gate_event_uuid, ambiguity_reason, source, provisional)
                 VALUES (?, ?, NULL, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)',
                [
                    $ctx['uuid'], (int) $employee['id'], $ctx['hod'], (int) $employee['department_id'],
                    $ctx['device'], $ctx['sequence'],
                    Time::sql($ctx['occurred']),
                    $ctx['device_time'] ? Time::sql($ctx['device_time']) : null,
                    $ctx['skew'], $status, $ctx['gate_uuid'], $why,
                    $ctx['source'], $ctx['source'] === 'offline_replay' ? 1 : 0,
                ]
            );
        } catch (Throwable $e) {
            return self::fromRace($e, $ctx, null);
        }
        $id = Db::lastId();

        Audit::record('hod.' . $status, 'att_hod_arrival', $id, null, [
            'event_uuid' => $ctx['uuid'], 'employee_id' => (int) $employee['id'], 'why' => $why,
        ], $ctx['hod']);

        return $status === 'ambiguous'
            ? Receipts::review($ctx['uuid'], self::KIND, $reason, $message, $ctx['device'], ['hod_arrival_id' => $id])
            : Receipts::pending($ctx['uuid'], self::KIND, $id, $reason, $message, $ctx['device']);
    }

    private static function fromRace(Throwable $e, array $ctx, ?int $cycleId): array
    {
        if (!Db::isDuplicate($e)) {
            throw $e;
        }
        $msg = $e->getMessage();

        if (str_contains($msg, 'uniq_att_hod_uuid')) {
            return Receipts::find($ctx['uuid']) ?? Receipts::alreadyReceived($ctx['uuid'], self::KIND);
        }
        if (str_contains($msg, 'uniq_att_hod_cycle')) {
            /* AT06: each cycle is verified ONCE. Somebody else — or this HOD's
               own double-tap, or a bulk request racing a single one — got
               there first. Say who and when, so nobody wonders. */
            $first = $cycleId ? Db::one(
                'SELECT hod_user_id, occurred_at FROM att_hod_arrivals WHERE entry_cycle_id = ?', [$cycleId]
            ) : null;
            return Receipts::reject($ctx['uuid'], self::KIND, 'ALREADY_CONFIRMED',
                'This entry has already been confirmed.', $ctx['device'], [
                    'entry_cycle_id'  => $cycleId,
                    'confirmed_by'    => $first ? (int) $first['hod_user_id'] : null,
                    'confirmed_at'    => $first ? Time::iso(Time::fromDb((string) $first['occurred_at'])) : null,
                ]);
        }
        if (str_contains($msg, 'uniq_att_hod_device_seq')) {
            return Receipts::review($ctx['uuid'], self::KIND, 'DEVICE_SEQUENCE_REUSED',
                'This device has already used that sequence number.', $ctx['device']);
        }
        throw $e;
    }

    private static function cleanUuid(mixed $v): ?string
    {
        $v = trim((string) ($v ?? ''));
        return Receipts::validUuid($v) ? $v : null;
    }
}
