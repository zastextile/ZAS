<?php
declare(strict_types=1);

namespace Att;

use DateTimeImmutable;
use PDO;
use Throwable;

/**
 * Gate IN and OUT, and the entry cycles they open and close.
 *
 * The rules it keeps, verbatim from the approved baseline:
 *
 *   Invariant 1  Each Gate IN opens exactly one entry cycle; every cycle
 *                requires its own HOD confirmation, including all returns.
 *   Invariant 2  Multiple IN/OUT cycles within a shift are allowed; no double
 *                IN and no OUT without IN.
 *   Invariant 3  60-second duplicate suppression, server-side.
 *   Invariant 4  Offline writes replay idempotently by UUID.
 *   §2           Invalid transitions are rejected, subject to authorised
 *                correction (corrections arrive in M4).
 *
 * WHERE EACH RULE IS ACTUALLY ENFORCED. Three of them are not enforced by the
 * PHP below but by the database, and that is deliberate — two handsets can
 * scan the same person in the same millisecond, and an offline queue replays
 * in a burst:
 *
 *   one open cycle per employee  →  UNIQUE on the generated open_employee_id
 *   an event cannot land twice   →  UNIQUE on event_uuid
 *   a device cannot reuse a seq  →  UNIQUE on (device_id, device_sequence)
 *
 * The PHP checks exist to produce a clear reason_code in the ordinary case.
 * When the engine wins a race instead, the duplicate-key error is caught and
 * turned into the same answer, so the handset cannot tell the difference and
 * the data is right either way.
 */
final class AttendanceService
{
    public const ACCEPTED         = 'ACCEPTED';
    public const ALREADY_RECEIVED = 'ALREADY_RECEIVED';
    public const REJECTED         = 'REJECTED';
    public const NEEDS_REVIEW     = 'NEEDS_REVIEW';

    /**
     * Record a Gate IN.
     *
     * @param array{employee_id:int,device_id:int,operator_id:int,event_uuid:string,
     *              device_sequence:int,occurred_at?:?DateTimeImmutable,device_time?:?string,
     *              timezone?:?string,source?:string} $in
     * @return array<string,mixed> the receipt, exactly as the contract shapes it
     */
    public static function gateIn(array $in): array
    {
        return self::record($in, 'GATE_IN');
    }

    /** Record a Gate OUT. */
    public static function gateOut(array $in): array
    {
        return self::record($in, 'GATE_OUT');
    }

    // ------------------------------------------------------------------ core

    private static function record(array $in, string $type): array
    {
        $uuid = trim((string) ($in['event_uuid'] ?? ''));
        if (!Receipts::validUuid($uuid)) {
            return self::reject($uuid, $type, 'BAD_EVENT_UUID',
                'Every event needs a stable UUID so a replay can be recognised.');
        }

        /* 1. IDEMPOTENCY FIRST, BEFORE ANY VALIDATION.
           A replay must get its original answer back even if the world has
           moved on since — the employee may now be inside, which would make a
           fresh evaluation of the same event say REJECTED and tell the phone
           a successful entry had failed. */
        $existing = Receipts::find($uuid);
        if ($existing !== null) {
            return $existing;
        }

        $employeeId = (int) ($in['employee_id'] ?? 0);
        $deviceId   = (int) ($in['device_id'] ?? 0);
        $operatorId = (int) ($in['operator_id'] ?? 0);
        $sequence   = (int) ($in['device_sequence'] ?? 0);

        if ($employeeId <= 0) {
            // Said plainly: a mistyped code is the commonest refusal at a gate,
            // and "validation error" tells the gatekeeper nothing.
            return self::reject($uuid, $type, 'EMPLOYEE_NOT_FOUND', 'No employee with that code.', $deviceId ?: null);
        }
        if ($deviceId <= 0 || $operatorId <= 0) {
            return self::reject($uuid, $type, 'VALIDATION',
                'employee, device and operator are all required.', $deviceId ?: null);
        }

        /* Registered, not revoked, and registered FOR THE GATE: a HOD handset
           cannot record gate movements. Shared with HodService so the two
           cannot drift apart on what a usable device is. */
        $deviceProblem = Receipts::checkDevice($deviceId, 'gate', $uuid, $type, true);
        if ($deviceProblem !== null) {
            return $deviceProblem;
        }

        $employee = Db::one('SELECT id, active, department_id FROM att_employees WHERE id = ?', [$employeeId]);
        if ($employee === null) {
            return self::reject($uuid, $type, 'EMPLOYEE_NOT_FOUND', 'No such employee.', $deviceId);
        }
        if ((int) $employee['active'] !== 1) {
            return self::reject($uuid, $type, 'EMPLOYEE_INACTIVE', 'This employee is not active.', $deviceId);
        }

        // Canonical time. A device time that cannot be parsed does not become
        // "now" — it is held for review, because a silent "now" is
        // indistinguishable from a real scan.
        $deviceTime = Time::parse((string) ($in['device_time'] ?? ''), $in['timezone'] ?? null);
        $occurred   = $in['occurred_at'] ?? $deviceTime ?? Time::nowUtc();
        /* Skew is what the caller measured when it could measure it properly
           (SyncService, from the phone's clock at upload time). Only a direct
           caller with nothing better falls back to device time minus now —
           which for an event replayed hours later is queue delay, not clock
           error, so SyncService never lets that through. */
        $skew = array_key_exists('clock_skew_ms', $in)
            ? Time::clampSkew($in['clock_skew_ms'] === null ? null : (int) $in['clock_skew_ms'])
            : ($deviceTime !== null ? Time::skewMs($deviceTime) : null);

        if (($in['device_time'] ?? '') !== '' && $deviceTime === null) {
            return self::needsReview($uuid, $type, 'UNREADABLE_DEVICE_TIME',
                'The device sent a time that could not be read.', $deviceId);
        }

        $source      = ($in['source'] ?? 'online') === 'offline_replay' ? 'offline_replay' : 'online';
        $provisional = $source === 'offline_replay' ? 1 : 0;

        /* 2. 60-SECOND DUPLICATE SUPPRESSION (invariant 3).
           Measured on occurred_at, not received_at. Two handsets at the same
           door produce two events a second apart but may reach the server
           minutes apart; judging by arrival time would let the second one
           through. Scoped to employee + action, which is what the blueprint
           says, so an IN followed immediately by an OUT is still allowed. */
        $window = Policy::int('duplicate_window_seconds', 60);
        if ($window > 0) {
            $recent = Db::one(
                'SELECT id, event_uuid, occurred_at FROM att_events
                  WHERE employee_id = ? AND event_type = ?
                    AND reconciliation_status = \'accepted\'
                    AND ABS(TIMESTAMPDIFF(SECOND, occurred_at, ?)) < ?
                  ORDER BY occurred_at DESC LIMIT 1',
                [$employeeId, $type, Time::sql($occurred), $window]
            );
            if ($recent !== null) {
                return self::reject($uuid, $type, 'DUPLICATE_WITHIN_WINDOW',
                    sprintf('The same action was already recorded for this employee within %d seconds.', $window),
                    $deviceId, ['duplicate_of_event_uuid' => $recent['event_uuid']]);
            }
        }

        return $type === 'GATE_IN'
            ? self::openCycle($uuid, $in, $employeeId, $deviceId, $operatorId, $sequence, $occurred, $deviceTime, $skew, $source, $provisional)
            : self::closeCycle($uuid, $employeeId, $deviceId, $operatorId, $sequence, $occurred, $deviceTime, $skew, $source, $provisional);
    }

    // -------------------------------------------------------------- GATE IN

    private static function openCycle(
        string $uuid, array $in, int $employeeId, int $deviceId, int $operatorId,
        int $sequence, DateTimeImmutable $occurred, ?DateTimeImmutable $deviceTime,
        ?int $skew, string $source, int $provisional
    ): array {
        // Already inside? (invariant 2 — no double IN)
        $open = self::openCycleFor($employeeId);
        /* Yesterday's forgotten exit, closed by the rule on the spot — but
           only for an IN scanned AFTER the time the system would close it.
           An IN scanned while the entry was still running (a second phone,
           replayed late) is a real double IN and is refused below. */
        if ($open !== null && self::autoCloseOne((int) $open['id'], Time::nowUtc(), $occurred)) {
            $open = null;
        }
        if ($open !== null) {
            return self::reject($uuid, 'GATE_IN', 'ALREADY_INSIDE',
                'This employee is already inside. Record an exit first.',
                $deviceId, ['open_entry_cycle_id' => (int) $open['id']]);
        }

        // D4: admit, attaching the default shift if none is assigned.
        [$instance, ] = Shifts::instanceForOrDefault($employeeId, $occurred, $operatorId);
        if ($instance === null) {
            /* Not a refusal of entry: the person is through the gate either
               way. The event is held for HR because there is nothing to file
               it against — no shift assigned and no default configured. */
            return self::needsReview($uuid, 'GATE_IN', 'NO_SHIFT_INSTANCE',
                'No shift is assigned for this employee and no default shift is configured.',
                $deviceId);
        }
        $instanceId = (int) $instance['id'];

        try {
            return Db::tx(static function (PDO $pdo) use (
                $uuid, $employeeId, $deviceId, $operatorId, $sequence, $occurred,
                $deviceTime, $skew, $source, $provisional, $instanceId
            ) {
                /* Lock the shift instance, not the cycle table. entry_sequence
                   is MAX+1 within this instance, and two concurrent INs for
                   the same person on the same day would otherwise compute the
                   same number and collide on uniq_att_shift_sequence. Locking
                   the parent row serialises exactly that pair and nothing
                   wider — other employees are untouched. */
                Db::run('SELECT id FROM att_shift_instances WHERE id = ? FOR UPDATE', [$instanceId]);

                Db::run(
                    'INSERT INTO att_events
                       (event_uuid, employee_id, device_id, operator_id, event_type,
                        occurred_at, device_time, timezone, clock_skew_ms,
                        device_sequence, source, provisional)
                     VALUES (?, ?, ?, ?, \'GATE_IN\', ?, ?, ?, ?, ?, ?, ?)',
                    [
                        $uuid, $employeeId, $deviceId, $operatorId,
                        Time::sql($occurred),
                        $deviceTime ? Time::sql($deviceTime) : null,
                        null, $skew, $sequence, $source, $provisional,
                    ]
                );
                $eventId = Db::lastId();

                $next = (int) Db::value(
                    'SELECT COALESCE(MAX(entry_sequence), 0) + 1 FROM att_entry_cycles WHERE shift_instance_id = ?',
                    [$instanceId]
                );

                Db::run(
                    'INSERT INTO att_entry_cycles
                       (employee_id, shift_instance_id, entry_sequence, gate_in_event_id, opened_at)
                     VALUES (?, ?, ?, ?, ?)',
                    [$employeeId, $instanceId, $next, $eventId, Time::sql($occurred)]
                );
                $cycleId = Db::lastId();

                Audit::record('gate.in', 'att_entry_cycle', $cycleId, null, [
                    'employee_id'    => $employeeId,
                    'entry_sequence' => $next,
                    'event_uuid'     => $uuid,
                    'occurred_at'    => Time::sql($occurred),
                ], $operatorId);

                /* Invariant 1: this cycle now owes a HOD confirmation. The
                   requirement is the open cycle itself — M3 reads unconfirmed
                   cycles rather than keeping a second list that could drift
                   out of step with this one. */
                return self::accept($uuid, 'GATE_IN', $eventId, $deviceId, [
                    'entry_cycle_id'       => $cycleId,
                    'entry_sequence'       => $next,
                    'shift_instance_id'    => $instanceId,
                    'hod_confirmation_due' => true,
                ]);
            });
        } catch (Throwable $e) {
            return self::fromRace($e, $uuid, 'GATE_IN', $employeeId, $deviceId, $sequence);
        }
    }

    // ------------------------------------------------------------- GATE OUT

    private static function closeCycle(
        string $uuid, int $employeeId, int $deviceId, int $operatorId,
        int $sequence, DateTimeImmutable $occurred, ?DateTimeImmutable $deviceTime,
        ?int $skew, string $source, int $provisional
    ): array {
        try {
            return Db::tx(static function (PDO $pdo) use (
                $uuid, $employeeId, $deviceId, $operatorId, $sequence, $occurred,
                $deviceTime, $skew, $source, $provisional
            ) {
                /* Lock the open cycle before reading it. Without FOR UPDATE two
                   simultaneous OUTs both see it open and both try to close it;
                   the second would overwrite the first's closing event and the
                   presence minutes with it. */
                $open = Db::one(
                    'SELECT id, opened_at, shift_instance_id FROM att_entry_cycles
                      WHERE employee_id = ? AND closed_at IS NULL FOR UPDATE',
                    [$employeeId]
                );
                $replacesAuto = false;
                if ($open === null) {
                    /* A real OUT scanned BEFORE the system closed the entry —
                       on a phone with no signal that sent it afterwards — is
                       what actually happened, so it takes the place of the
                       system's OUT. One scanned after the system closed it is
                       not: the entry was already closed when it happened. */
                    $auto = Db::one(
                        "SELECT id, opened_at, shift_instance_id, auto_closed_at FROM att_entry_cycles
                          WHERE employee_id = ? AND opened_at <= ? ORDER BY opened_at DESC LIMIT 1 FOR UPDATE",
                        [$employeeId, Time::sql($occurred)]
                    );
                    $autoSource = $auto !== null ? Db::value('SELECT close_source FROM att_entry_cycles WHERE id = ?', [(int) $auto['id']]) : null;
                    if ($auto !== null && $autoSource === self::AUTO_OUT && $auto['auto_closed_at'] !== null
                        && $occurred <= Time::fromDb((string) $auto['auto_closed_at'])) {
                        $open = $auto;
                        $replacesAuto = true;
                    } else {
                        // invariant 2 — no OUT without IN
                        return self::reject($uuid, 'GATE_OUT', 'NOT_INSIDE',
                            $autoSource === self::AUTO_OUT
                                ? 'This employee is not inside: the system closed the entry because no OUT was scanned.'
                                : 'This employee is not currently inside.', $deviceId);
                    }
                }

                Db::run(
                    'INSERT INTO att_events
                       (event_uuid, employee_id, device_id, operator_id, event_type,
                        occurred_at, device_time, timezone, clock_skew_ms,
                        device_sequence, source, provisional)
                     VALUES (?, ?, ?, ?, \'GATE_OUT\', ?, ?, ?, ?, ?, ?, ?)',
                    [
                        $uuid, $employeeId, $deviceId, $operatorId,
                        Time::sql($occurred),
                        $deviceTime ? Time::sql($deviceTime) : null,
                        null, $skew, $sequence, $source, $provisional,
                    ]
                );
                $eventId = Db::lastId();

                $openedAt = Time::fromDb((string) $open['opened_at']);
                $minutes  = null;
                if ($openedAt !== null) {
                    $seconds = $occurred->getTimestamp() - $openedAt->getTimestamp();
                    /* An exit timed before its own entry is a clock problem,
                       not a negative shift. Store nothing and let HR see it
                       rather than writing a negative that would quietly
                       subtract from somebody's hours. */
                    $minutes = $seconds >= 0 ? intdiv($seconds, 60) : null;
                }

                if ($replacesAuto) {
                    Db::run(
                        "UPDATE att_entry_cycles
                            SET gate_out_event_id = ?, closed_at = ?, present_minutes = ?, close_source = 'gate'
                          WHERE id = ? AND close_source = 'auto_shift_end' AND gate_out_event_id IS NULL",
                        [$eventId, Time::sql($occurred), $minutes, (int) $open['id']]
                    );
                } else {
                    Db::run(
                        'UPDATE att_entry_cycles
                            SET gate_out_event_id = ?, closed_at = ?, present_minutes = ?
                          WHERE id = ? AND closed_at IS NULL',
                        [$eventId, Time::sql($occurred), $minutes, (int) $open['id']]
                    );
                }

                Audit::record($replacesAuto ? 'gate.out_replaces_auto' : 'gate.out', 'att_entry_cycle', (int) $open['id'], null, [
                    'employee_id'     => $employeeId,
                    'event_uuid'      => $uuid,
                    'occurred_at'     => Time::sql($occurred),
                    'present_minutes' => $minutes,
                ], $operatorId);

                return self::accept($uuid, 'GATE_OUT', $eventId, $deviceId, [
                    'entry_cycle_id'  => (int) $open['id'],
                    'present_minutes' => $minutes,
                ] + ($replacesAuto ? ['replaced_auto_out' => true] : []));
            });
        } catch (Throwable $e) {
            return self::fromRace($e, $uuid, 'GATE_OUT', $employeeId, $deviceId, $sequence);
        }
    }

    // ------------------------------------------------------------- auto OUT

    public const AUTO_OUT = 'auto_shift_end';

    /**
     * The owner's rule for a forgotten exit scan.
     *
     * An entry still open when its shift has ended is closed by the system
     * AT THE SHIFT END — or at shift end plus the employee's APPROVED overtime
     * for that day — but only once a waiting time after that (setting
     * auto_out_after_minutes, 4 hours by default) has passed with no OUT. So
     * with a 6 pm shift end nothing happens until 10 pm; anyone still not
     * scanned out then is closed at 6 pm.
     *
     * Overtime is never created by this: the closing time is never later
     * than shift end + approved minutes. Someone who came in after that time
     * is closed at the moment they came in, with no minutes.
     *
     * No gate event is written. The entry carries close_source
     * 'auto_shift_end' and auto_closed_at, and every report says "not scanned
     * out". HR can replace the time with the real one, as a correction.
     *
     * @return array{ok:bool,closed:int,checked:int}
     */
    public static function autoCloseDue(?DateTimeImmutable $now = null, int $limit = 1000): array
    {
        $now ??= Time::nowUtc();
        if (!Policy::bool('auto_out_enabled', true)) {
            return ['ok' => true, 'closed' => 0, 'checked' => 0, 'enabled' => false];
        }
        $wait = max(0, Policy::int('auto_out_after_minutes', 240));
        $ids = Db::all(
            'SELECT c.id FROM att_entry_cycles c JOIN att_shift_instances si ON si.id = c.shift_instance_id
              WHERE c.closed_at IS NULL AND si.ends_at <= ? ORDER BY c.opened_at LIMIT ' . max(1, $limit),
            [Time::sql($now->modify("-$wait minutes"))]
        );
        $closed = 0;
        foreach ($ids as $r) {
            if (self::autoCloseOne((int) $r['id'], $now)) {
                $closed++;
            }
        }
        return ['ok' => true, 'closed' => $closed, 'checked' => count($ids), 'enabled' => true];
    }

    /** Run the rule if it has not run in the last five minutes. Cheap; called on phone syncs, reports and the HR screen. */
    public static function autoCloseIfDue(): ?array
    {
        $last = (string) (Db::value("SELECT value FROM att_policies WHERE policy_key = 'auto_out_last_run'") ?? '');
        if ($last !== '' && strtotime($last . ' UTC') > time() - 300) {
            return null;
        }
        Db::run(
            "INSERT INTO att_policies (policy_key, value, value_type, description) VALUES ('auto_out_last_run', ?, 'string', '')
             ON DUPLICATE KEY UPDATE value = VALUES(value)",
            [gmdate('Y-m-d H:i:s')]
        );
        Policy::flush();
        return self::autoCloseDue();
    }

    /** When would the system close this entry, and may it now? [closeAt, dueAt] or null if the rule is off. */
    public static function autoOutTimes(array $c): ?array
    {
        if (!Policy::bool('auto_out_enabled', true)) {
            return null;
        }
        $utc = new \DateTimeZone('UTC');
        $end = new DateTimeImmutable((string) $c['ends_at'], $utc);
        $ot  = 0;
        if (Db::tableExists('att_ot_request_employees')) {
            $ot = (int) (Db::value(
                "SELECT approved_minutes FROM att_ot_request_employees
                  WHERE employee_id = ? AND work_date = ? AND approval_status = 'approved' LIMIT 1",
                [(int) $c['employee_id'], (string) $c['work_date']]
            ) ?? 0);
        }
        $closeAt = $end->modify('+' . max(0, $ot) . ' minutes');
        $opened  = new DateTimeImmutable((string) $c['opened_at'], $utc);
        if ($opened > $closeAt) {
            $closeAt = $opened;                       // came in after it: closed with no minutes
        }
        $wait = max(0, Policy::int('auto_out_after_minutes', 240));
        return [$closeAt, $end->modify('+' . max(0, $ot) . ' minutes')->modify("+$wait minutes"), $ot];
    }

    /** Close one entry by the rule, if it is due. True when it was closed here. */
    public static function autoCloseOne(int $cycleId, DateTimeImmutable $now, ?DateTimeImmutable $nextInAt = null): bool
    {
        $c = Db::one(
            'SELECT c.id, c.employee_id, c.opened_at, si.ends_at, si.work_date
               FROM att_entry_cycles c JOIN att_shift_instances si ON si.id = c.shift_instance_id
              WHERE c.id = ? AND c.closed_at IS NULL',
            [$cycleId]
        );
        $t = $c !== null ? self::autoOutTimes($c) : null;
        if ($t === null || $now < $t[1] || ($nextInAt !== null && $nextInAt < $t[0])) {
            return false;
        }
        [$closeAt, , $ot] = $t;
        $minutes = intdiv($closeAt->getTimestamp() - (new DateTimeImmutable((string) $c['opened_at'], new \DateTimeZone('UTC')))->getTimestamp(), 60);
        return Db::tx(static function () use ($cycleId, $closeAt, $minutes, $now, $c, $ot): bool {
            $n = Db::run(
                "UPDATE att_entry_cycles
                    SET closed_at = ?, present_minutes = ?, close_source = 'auto_shift_end', auto_closed_at = ?
                  WHERE id = ? AND closed_at IS NULL",
                [Time::sql($closeAt), max(0, $minutes), Time::sql($now), $cycleId]
            )->rowCount();
            if ($n !== 1) {
                return false;                         // a real OUT got there first
            }
            Audit::record('gate.auto_out', 'att_entry_cycle', $cycleId, ['closed_at' => null], [
                'employee_id' => (int) $c['employee_id'], 'closed_at' => Time::sql($closeAt),
                'present_minutes' => max(0, $minutes), 'approved_ot_minutes' => $ot,
                'reason' => 'No OUT was scanned; closed at shift end' . ($ot ? ' plus approved overtime' : '') . ' by the system.',
            ], null);
            return true;
        });
    }

    // ----------------------------------------------------------- race rescue

    /**
     * Turn a lost race into the right answer.
     *
     * Three different unique keys can fire here, and each means something
     * specific. Guessing one answer for all of them would tell a handset its
     * entry failed when in fact it succeeded a millisecond earlier.
     */
    private static function fromRace(Throwable $e, string $uuid, string $type, int $employeeId, int $deviceId, int $sequence): array
    {
        if (!Db::isDuplicate($e)) {
            throw $e;
        }
        $msg = $e->getMessage();

        // The same event arrived twice at once: answer as a replay.
        if (str_contains($msg, 'uniq_att_event_uuid')) {
            return self::receiptFor($uuid) ?? self::alreadyReceived($uuid, $type, null, $deviceId);
        }

        // Two handsets, same person, same instant: one opened the cycle first.
        if (str_contains($msg, 'uniq_att_open_employee')) {
            $open = self::openCycleFor($employeeId);
            return self::reject($uuid, $type, 'ALREADY_INSIDE',
                'This employee is already inside. Record an exit first.',
                $deviceId, $open ? ['open_entry_cycle_id' => (int) $open['id']] : []);
        }

        // A device reused a sequence number — a reinstalled app replaying an
        // old queue. Held for review rather than rejected: the event may be
        // real, and a person's attendance should not vanish over it.
        if (str_contains($msg, 'uniq_att_device_sequence')) {
            return self::needsReview($uuid, $type, 'DEVICE_SEQUENCE_REUSED',
                'This device has already used that sequence number.', $deviceId,
                ['device_sequence' => $sequence]);
        }

        if (str_contains($msg, 'uniq_att_shift_sequence')) {
            return self::needsReview($uuid, $type, 'SEQUENCE_COLLISION',
                'Two entries were opened for the same shift at once.', $deviceId);
        }

        throw $e;
    }

    // ------------------------------------------------------------- receipts
    // Thin delegates: the real implementation is Att\Receipts, shared with
    // HodService so gate events and HOD confirmations answer identically.

    /** @return array<string,mixed>|null */
    public static function receiptFor(string $uuid): ?array
    {
        return Receipts::find($uuid);
    }

    private static function accept(string $uuid, string $kind, int $eventId, int $deviceId, array $extra = []): array
    {
        return Receipts::accept($uuid, $kind, $eventId, $deviceId, $extra);
    }

    private static function reject(string $uuid, string $kind, string $reason, string $message, ?int $deviceId = null, array $extra = []): array
    {
        return Receipts::reject($uuid, $kind, $reason, $message, $deviceId, $extra);
    }

    private static function needsReview(string $uuid, string $kind, string $reason, string $message, ?int $deviceId = null, array $extra = []): array
    {
        return Receipts::review($uuid, $kind, $reason, $message, $deviceId, $extra);
    }

    private static function alreadyReceived(string $uuid, string $kind, ?int $eventId, ?int $deviceId): array
    {
        return Receipts::alreadyReceived($uuid, $kind);
    }

    // --------------------------------------------------------------- queries

    /** @return array<string,mixed>|null */
    public static function openCycleFor(int $employeeId): ?array
    {
        return Db::one(
            'SELECT id, entry_sequence, opened_at, shift_instance_id
               FROM att_entry_cycles WHERE employee_id = ? AND closed_at IS NULL',
            [$employeeId]
        );
    }

    public static function isInside(int $employeeId): bool
    {
        return self::openCycleFor($employeeId) !== null;
    }

    /**
     * Movement history. $departmentIds is the caller's scope; null = all.
     * Applied in SQL so a scoped user cannot read past it.
     *
     * @param list<int>|null $departmentIds
     * @return list<array<string,mixed>>
     */
    public static function movements(?array $departmentIds, ?int $employeeId = null, ?string $date = null, int $limit = 200): array
    {
        $sql = 'SELECT ev.id, ev.event_uuid, ev.event_type, ev.occurred_at, ev.received_at,
                       ev.reconciliation_status, ev.reason_code, ev.provisional, ev.clock_skew_ms,
                       e.code AS employee_code, e.full_name, e.department_id, d.name AS department_name
                  FROM att_events ev
                  JOIN att_employees e  ON e.id = ev.employee_id
                  JOIN att_departments d ON d.id = e.department_id
                 WHERE EXISTS (SELECT 1 FROM att_entry_cycles c WHERE c.gate_in_event_id = ev.id OR c.gate_out_event_id = ev.id)';
        $params = [];   // a scan whose entry the administrator deleted is not listed

        if ($departmentIds !== null) {
            if ($departmentIds === []) {
                return [];
            }
            $sql .= ' AND e.department_id IN (' . implode(',', array_fill(0, count($departmentIds), '?')) . ')';
            foreach ($departmentIds as $d) { $params[] = (int) $d; }
        }
        if ($employeeId !== null) { $sql .= ' AND ev.employee_id = ?'; $params[] = $employeeId; }
        if ($date !== null && preg_match('/^\d{4}-\d{2}-\d{2}$/', $date)) {
            $sql .= ' AND DATE(ev.occurred_at) = ?'; $params[] = $date;
        }

        $sql .= ' ORDER BY ev.occurred_at DESC, ev.id DESC LIMIT ' . max(1, min(1000, $limit));
        return Db::all($sql, $params);
    }

    /**
     * Entry cycles, with whether each still owes its HOD confirmation.
     *
     * @param list<int>|null $departmentIds
     * @return list<array<string,mixed>>
     */
    public static function entryCycles(?array $departmentIds, ?int $employeeId = null, ?string $date = null, int $limit = 200): array
    {
        $sql = 'SELECT c.id, c.employee_id, c.entry_sequence, c.opened_at, c.closed_at,
                       c.present_minutes, c.shift_instance_id,
                       e.code AS employee_code, e.full_name, e.department_id,
                       d.name AS department_name, si.work_date,
                       h.id AS hod_arrival_id, h.occurred_at AS hod_confirmed_at,
                       (h.id IS NULL) AS hod_confirmation_due
                  FROM att_entry_cycles c
                  LEFT JOIN att_hod_arrivals h ON h.entry_cycle_id = c.id
                  JOIN att_employees e   ON e.id = c.employee_id
                  JOIN att_departments d ON d.id = e.department_id
                  JOIN att_shift_instances si ON si.id = c.shift_instance_id
                 WHERE 1 = 1';
        $params = [];

        if ($departmentIds !== null) {
            if ($departmentIds === []) {
                return [];
            }
            $sql .= ' AND e.department_id IN (' . implode(',', array_fill(0, count($departmentIds), '?')) . ')';
            foreach ($departmentIds as $d) { $params[] = (int) $d; }
        }
        if ($employeeId !== null) { $sql .= ' AND c.employee_id = ?'; $params[] = $employeeId; }
        if ($date !== null && preg_match('/^\d{4}-\d{2}-\d{2}$/', $date)) {
            $sql .= ' AND si.work_date = ?'; $params[] = $date;
        }

        $sql .= ' ORDER BY c.opened_at DESC, c.id DESC LIMIT ' . max(1, min(1000, $limit));
        return Db::all($sql, $params);
    }
}
