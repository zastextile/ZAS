<?php
declare(strict_types=1);

namespace Att;

use DateTimeImmutable;
use DateTimeZone;

/**
 * Overtime: request → decision → next-day verification → rate → payroll.
 *
 * Blueprint §4, and CLAUDE.md rule 7, in the order they happen:
 *
 *   1. A HOD asks for overtime for named employees of their own department:
 *      date, reason, estimated minutes, optionally planned start and end.
 *   2. ONE officer (HR, Accounts or Admin) approves or rejects each employee.
 *      The first committed decision wins (AT09); a later change is an
 *      audited amendment, never a second decision.
 *   3. From the next day, an officer — or a gatekeeper, only if Settings
 *      allows it — enters the actual minutes per employee, after looking at
 *      the gate history. More than was approved is refused until an
 *      amendment raises the approval (AT10).
 *   4. Accounts or Admin types the rate — hourly or fixed — for each line.
 *      Nothing works a rate out from a wage; there is no wage here (AT11).
 *   5. Payroll is finalized for a period. After that a line is locked; the
 *      only change is an audited correction that leaves the finalized
 *      figures where they were and records the new ones beside them.
 *
 * WHAT NEVER HAPPENS (AT08). Staying late is not overtime. No row in this
 * module is created or filled from att_events or att_entry_cycles. The gate
 * history is SHOWN to the person verifying — as "time inside after the shift
 * ended" — and the actual minutes field is left empty for them to type.
 *
 * Every answer is ['ok' => true, ...] or ['ok' => false, 'reason_code',
 * 'error'], as everywhere else in the API.
 */
final class OvertimeService
{
    public const MAX_EMPLOYEES = 200;

    private static ?string $today = null;

    /** For the tests only: pretend today is $date (factory date). */
    public static function setToday(?string $date): void
    {
        self::$today = $date;
    }

    public static function today(): string
    {
        return self::$today ?? (new DateTimeImmutable('now', new DateTimeZone('Asia/Karachi')))->format('Y-m-d');
    }

    // ================================================================ request

    /**
     * @param array<string,mixed> $in
     * @param list<int>|null $scope departments the requester may ask for (null = any)
     */
    public static function createRequest(array $in, ?array $scope, int $actorId): array
    {
        $uuid = strtolower(trim((string) ($in['request_uuid'] ?? '')));
        if (!Receipts::validUuid($uuid)) {
            return self::no('VALIDATION', 'The request has no valid id.');
        }
        $existing = Db::one('SELECT id, requested_by FROM att_ot_requests WHERE request_uuid = ?', [$uuid]);
        if ($existing !== null) {
            if ((int) $existing['requested_by'] !== $actorId) {
                return self::no('VALIDATION', 'That request id is already in use.');
            }
            return ['ok' => true, 'already' => true] + self::request((int) $existing['id'], null);
        }

        $deptId = (int) ($in['department_id'] ?? 0);
        $dept = Db::one('SELECT id, name, active FROM att_departments WHERE id = ?', [$deptId]);
        if ($dept === null || (int) $dept['active'] !== 1 || ($scope !== null && !in_array($deptId, $scope, true))) {
            return self::no('VALIDATION', 'Choose one of your own departments.');
        }

        $date = self::date((string) ($in['work_date'] ?? ''));
        if ($date === null) {
            return self::no('VALIDATION', 'Give the date the overtime is for.');
        }
        $today = new DateTimeImmutable(self::today(), new DateTimeZone('UTC'));
        $back  = max(0, Policy::int('ot_request_days_back', 1));
        $ahead = max(0, Policy::int('ot_request_days_ahead', 14));
        if ($date < $today->modify("-$back days")) {
            return self::no('OUTSIDE_WINDOW', $back === 0
                ? 'Overtime can only be requested for today or a later day.'
                : "Overtime can be requested at most $back day" . ($back === 1 ? '' : 's') . ' back.');
        }
        if ($date > $today->modify("+$ahead days")) {
            return self::no('OUTSIDE_WINDOW', "Overtime can be requested at most $ahead days ahead.");
        }

        $reason = self::text($in['reason'] ?? '');
        if ($reason === '' || mb_strlen($reason) > 1000) {
            return self::no('VALIDATION', $reason === '' ? 'Give the reason for the overtime.' : 'The reason can be at most 1000 characters.');
        }

        $start = self::hhmm($in['planned_start'] ?? '');
        $end   = self::hhmm($in['planned_end'] ?? '');
        if (($start === false) || ($end === false)) {
            return self::no('VALIDATION', 'Planned start and end are times, for example 17:00 and 20:00.');
        }
        if (($start === null) !== ($end === null)) {
            return self::no('VALIDATION', 'Give both a planned start and a planned end, or neither.');
        }

        $rows = is_array($in['employees'] ?? null) ? array_values($in['employees']) : [];
        if ($rows === []) {
            return self::no('VALIDATION', 'Choose at least one employee.');
        }
        if (count($rows) > self::MAX_EMPLOYEES) {
            return self::no('VALIDATION', 'At most ' . self::MAX_EMPLOYEES . ' employees in one request.');
        }
        $max = max(1, Policy::int('ot_max_minutes', 720));
        $lines = [];
        foreach ($rows as $i => $r) {
            if (!is_array($r)) {
                return self::no('VALIDATION', 'Line ' . ($i + 1) . ' is not an employee.');
            }
            $emp = null;
            if ((int) ($r['employee_id'] ?? 0) > 0) {
                $emp = Db::one('SELECT id, code, full_name, department_id, active FROM att_employees WHERE id = ?', [(int) $r['employee_id']]);
            } elseif (trim((string) ($r['employee_code'] ?? '')) !== '') {
                $emp = Employees::findByCodeOrQr((string) $r['employee_code']);
            }
            if ($emp === null || (int) $emp['department_id'] !== $deptId) {
                return self::no('EMPLOYEE_NOT_FOUND', 'Line ' . ($i + 1) . ': not an employee of ' . $dept['name'] . '.');
            }
            if ((int) $emp['active'] !== 1) {
                return self::no('EMPLOYEE_INACTIVE', $emp['full_name'] . ' is not active.');
            }
            if (isset($lines[(int) $emp['id']])) {
                return self::no('VALIDATION', $emp['full_name'] . ' is in the list twice.');
            }
            $m = $r['estimated_minutes'] ?? null;
            if (!self::wholeNumber($m) || (int) $m < 1 || (int) $m > $max) {
                return self::no('VALIDATION', $emp['full_name'] . ': the estimated time must be more than 0 and at most ' . self::h($max) . '.');
            }
            $lines[(int) $emp['id']] = ['employee' => $emp, 'minutes' => (int) $m];
        }

        // One live line per employee per day — said by name here, kept by the
        // database's unique key below if two requests race.
        $ids = array_keys($lines);
        $clash = Db::all(
            'SELECT e.full_name FROM att_ot_request_employees l JOIN att_employees e ON e.id = l.employee_id
              WHERE l.work_date = ? AND l.approval_status <> \'rejected\' AND l.employee_id IN (' . implode(',', array_fill(0, count($ids), '?')) . ')',
            array_merge([$date->format('Y-m-d')], $ids)
        );
        if ($clash !== []) {
            return self::no('ALREADY_REQUESTED', implode(', ', array_column($clash, 'full_name'))
                . (count($clash) === 1 ? ' already has' : ' already have') . ' overtime requested for that day. To change the minutes, ask an officer to amend it.');
        }

        try {
            $id = Db::tx(static function () use ($uuid, $deptId, $actorId, $date, $reason, $start, $end, $lines): int {
                Db::run(
                    'INSERT INTO att_ot_requests (request_uuid, department_id, requested_by, work_date, reason, planned_start, planned_end)
                     VALUES (?, ?, ?, ?, ?, ?, ?)',
                    [$uuid, $deptId, $actorId, $date->format('Y-m-d'), $reason, $start, $end]
                );
                $rid = Db::lastId();
                foreach ($lines as $empId => $l) {
                    Db::run('INSERT INTO att_ot_request_employees (request_id, employee_id, work_date, estimated_minutes) VALUES (?, ?, ?, ?)',
                        [$rid, $empId, $date->format('Y-m-d'), $l['minutes']]);
                    self::history(Db::lastId(), 'request', 'estimated_minutes', null, $l['minutes'], $reason, $actorId);
                }
                Audit::record('ot.request', 'att_ot_request', $rid, null,
                    ['department_id' => $deptId, 'work_date' => $date->format('Y-m-d'), 'employees' => count($lines)], $actorId, $reason);
                return $rid;
            });
        } catch (\Throwable $e) {
            if (Db::isDuplicate($e)) {
                $again = Db::one('SELECT id FROM att_ot_requests WHERE request_uuid = ?', [$uuid]);
                if ($again !== null) {
                    return ['ok' => true, 'already' => true] + self::request((int) $again['id'], null);
                }
                return self::no('ALREADY_REQUESTED', 'One of these employees was given overtime for that day by another request a moment ago.');
            }
            throw $e;
        }
        return ['ok' => true, 'already' => false] + self::request($id, null);
    }

    /**
     * @param array<string,mixed> $f status (open|decided|all), from, to, department_id
     * @param list<int>|null $scope
     */
    public static function requests(array $f, ?array $scope): array
    {
        $where = [];
        $p = [];
        if ($scope !== null) {
            if ($scope === []) {
                return ['ok' => true, 'requests' => []];
            }
            $where[] = 'r.department_id IN (' . implode(',', array_map('intval', $scope)) . ')';
        }
        if ((int) ($f['department_id'] ?? 0) > 0) { $where[] = 'r.department_id = ?'; $p[] = (int) $f['department_id']; }
        if (($d = self::date((string) ($f['from'] ?? ''))) !== null) { $where[] = 'r.work_date >= ?'; $p[] = $d->format('Y-m-d'); }
        if (($d = self::date((string) ($f['to'] ?? ''))) !== null) { $where[] = 'r.work_date <= ?'; $p[] = $d->format('Y-m-d'); }
        $status = (string) ($f['status'] ?? 'all');
        if ($status === 'open') {
            $where[] = "EXISTS (SELECT 1 FROM att_ot_request_employees x WHERE x.request_id = r.id AND x.approval_status = 'pending')";
        } elseif ($status === 'decided') {
            $where[] = "NOT EXISTS (SELECT 1 FROM att_ot_request_employees x WHERE x.request_id = r.id AND x.approval_status = 'pending')";
        }
        $ids = array_column(Db::all(
            'SELECT r.id FROM att_ot_requests r' . ($where ? ' WHERE ' . implode(' AND ', $where) : '')
            . ' ORDER BY r.work_date DESC, r.id DESC LIMIT 200', $p), 'id');
        $out = [];
        foreach ($ids as $id) {
            $out[] = self::request((int) $id, null)['request'];
        }
        return ['ok' => true, 'requests' => $out];
    }

    /** @param list<int>|null $scope */
    public static function request(int $id, ?array $scope): array
    {
        $users = Db::tableExists('users');
        $r = Db::one(
            'SELECT r.*, d.name AS department_name' . ($users ? ', u.name AS requested_by_name' : ', NULL AS requested_by_name') . '
               FROM att_ot_requests r JOIN att_departments d ON d.id = r.department_id'
            . ($users ? ' LEFT JOIN users u ON u.id = r.requested_by' : '') . ' WHERE r.id = ?', [$id]);
        if ($r === null || ($scope !== null && !in_array((int) $r['department_id'], $scope, true))) {
            return self::no('NOT_FOUND', 'That overtime request does not exist.');
        }
        $lines = Db::all(
            'SELECT l.id AS request_employee_id, l.employee_id, e.code AS employee_code, e.full_name,
                    l.estimated_minutes, l.approval_status, l.approved_minutes, l.decided_at, l.decision_note,
                    l.verification_status, l.actual_minutes, l.verified_at, l.verification_note,
                    COALESCE(p.status, \'none\') AS payroll_status, p.rate_type, p.manual_rate, p.payable_amount, p.effective_amount'
            . ($users ? ', du.name AS decided_by_name, vu.name AS verified_by_name' : ', NULL AS decided_by_name, NULL AS verified_by_name') . '
               FROM att_ot_request_employees l
               JOIN att_employees e ON e.id = l.employee_id
               LEFT JOIN att_ot_payroll p ON p.request_employee_id = l.id'
            . ($users ? ' LEFT JOIN users du ON du.id = l.decided_by LEFT JOIN users vu ON vu.id = l.verified_by' : '') . '
              WHERE l.request_id = ? ORDER BY e.full_name, l.id', [$id]);
        $r['lines'] = $lines;
        $r['pending'] = count(array_filter($lines, fn($l) => $l['approval_status'] === 'pending'));
        return ['ok' => true, 'request' => $r];
    }

    // =============================================================== decision

    /**
     * Approve or reject employees of one request, each on its own.
     *
     * @param list<array<string,mixed>> $decisions [{request_employee_id, decision: approve|reject, remarks}]
     */
    public static function decide(int $requestId, string $decisionUuid, array $decisions, int $actorId): array
    {
        $decisionUuid = strtolower(trim($decisionUuid));
        if (!Receipts::validUuid($decisionUuid)) {
            return self::no('VALIDATION', 'The decision has no valid id.');
        }
        if ($decisions === [] || count($decisions) > self::MAX_EMPLOYEES) {
            return self::no('VALIDATION', 'Choose at least one employee to decide.');
        }
        $req = Db::one('SELECT id, work_date FROM att_ot_requests WHERE id = ?', [$requestId]);
        if ($req === null) {
            return self::no('NOT_FOUND', 'That overtime request does not exist.');
        }
        $stored = self::storedBatch($decisionUuid);
        if ($stored !== null) {
            return $stored + ['replayed' => true];
        }
        $window = max(0, Policy::int('ot_decision_window_days', 0));
        if ($window > 0) {
            $last = (new DateTimeImmutable((string) $req['work_date'], new DateTimeZone('UTC')))->modify("+$window days");
            if (new DateTimeImmutable(self::today(), new DateTimeZone('UTC')) > $last) {
                return self::no('OUTSIDE_WINDOW', "Decisions close $window day" . ($window === 1 ? '' : 's') . ' after the work date. A change now is an amendment.');
            }
        }

        try {
            return Db::tx(static function () use ($requestId, $decisionUuid, $decisions, $actorId): array {
                // Claim the decision id first: a second copy of this request
                // waits here and then finds the first one's answer.
                Db::run('INSERT INTO att_ot_decision_batches (decision_uuid, request_id, actor_id) VALUES (?, ?, ?)', [$decisionUuid, $requestId, $actorId]);
                $results = [];
                $seen = [];
                foreach ($decisions as $d) {
                    $lineId   = is_array($d) ? (int) ($d['request_employee_id'] ?? 0) : 0;
                    $decision = is_array($d) ? strtolower(trim((string) ($d['decision'] ?? ''))) : '';
                    $decision = ['approve' => 'approved', 'approved' => 'approved', 'reject' => 'rejected', 'rejected' => 'rejected'][$decision] ?? '';
                    $remarks  = is_array($d) ? self::text($d['remarks'] ?? '') : '';
                    if ($lineId <= 0 || $decision === '' || isset($seen[$lineId])) {
                        $results[] = ['request_employee_id' => $lineId, 'result' => 'INVALID', 'message' => 'Each employee needs one decision: approve or reject.'];
                        continue;
                    }
                    $seen[$lineId] = true;
                    // The row lock: a second officer deciding the same person
                    // at the same moment waits here, then sees it decided.
                    $line = Db::one('SELECT id, request_id, approval_status, estimated_minutes, decided_by FROM att_ot_request_employees WHERE id = ? FOR UPDATE', [$lineId]);
                    if ($line === null || (int) $line['request_id'] !== $requestId) {
                        $results[] = ['request_employee_id' => $lineId, 'result' => 'NOT_IN_REQUEST', 'message' => 'That employee is not on this request.'];
                        continue;
                    }
                    if ($line['approval_status'] !== 'pending') {
                        $results[] = ['request_employee_id' => $lineId, 'result' => 'ALREADY_DECIDED', 'approval_status' => $line['approval_status'],
                                      'decided_by_name' => self::userName((int) $line['decided_by']),
                                      'message' => 'Already ' . $line['approval_status'] . ' by ' . self::userName((int) $line['decided_by']) . '. A change now is an amendment.'];
                        continue;
                    }
                    if (mb_strlen($remarks) > 1000) {
                        $results[] = ['request_employee_id' => $lineId, 'result' => 'INVALID', 'message' => 'Remarks can be at most 1000 characters.'];
                        continue;
                    }
                    $st = Db::run(
                        "UPDATE att_ot_request_employees
                            SET approval_status = ?, approved_minutes = ?, decided_by = ?, decided_at = ?, decision_note = ?
                          WHERE id = ? AND approval_status = 'pending'",
                        [$decision, $decision === 'approved' ? (int) $line['estimated_minutes'] : null, $actorId, Time::sql(), $remarks !== '' ? $remarks : null, $lineId]
                    );
                    if ($st->rowCount() === 0) {
                        $results[] = ['request_employee_id' => $lineId, 'result' => 'ALREADY_DECIDED', 'message' => 'Already decided by somebody else.'];
                        continue;
                    }
                    self::history($lineId, 'decision', 'approval_status', 'pending', $decision, $remarks, $actorId);
                    Audit::record('ot.' . ($decision === 'approved' ? 'approve' : 'reject'), 'att_ot_request_employee', $lineId,
                        ['approval_status' => 'pending'], ['approval_status' => $decision, 'approved_minutes' => $decision === 'approved' ? (int) $line['estimated_minutes'] : null],
                        $actorId, $remarks !== '' ? $remarks : null);
                    $results[] = ['request_employee_id' => $lineId, 'result' => strtoupper($decision), 'approval_status' => $decision];
                }
                $out = ['ok' => true, 'decision_uuid' => $decisionUuid, 'results' => $results];
                Db::run('UPDATE att_ot_decision_batches SET response_json = ? WHERE decision_uuid = ?', [json_encode($out), $decisionUuid]);
                return $out;
            });
        } catch (\Throwable $e) {
            if (Db::isDuplicate($e)) {
                $stored = self::storedBatch($decisionUuid);
                if ($stored !== null) {
                    return $stored + ['replayed' => true];
                }
            }
            throw $e;
        }
    }

    // ============================================================ verification

    /**
     * Approved lines waiting for their actual minutes, with the gate history
     * beside each one. "Time inside after the shift ended" is shown to help
     * the person checking. It is never written into actual_minutes.
     *
     * @param list<int>|null $scope
     */
    public static function verification(?string $date, ?array $scope): array
    {
        $p = [];
        $where = ["l.approval_status = 'approved'", "COALESCE(pay.status, 'none') <> 'finalized'"];
        if (($d = self::date((string) $date)) !== null) {
            $where[] = 'l.work_date = ?';
            $p[] = $d->format('Y-m-d');
        } else {
            $where[] = "l.verification_status IN ('pending','disputed')";
        }
        if ($scope !== null) {
            if ($scope === []) {
                return ['ok' => true, 'lines' => [], 'today' => self::today()];
            }
            $where[] = 'r.department_id IN (' . implode(',', array_map('intval', $scope)) . ')';
        }
        $rows = Db::all(
            'SELECT l.id AS request_employee_id, l.request_id, l.employee_id, e.code AS employee_code, e.full_name,
                    d.name AS department_name, l.work_date, r.reason, r.planned_start, r.planned_end,
                    l.estimated_minutes, l.approved_minutes, l.verification_status, l.actual_minutes, l.verification_note
               FROM att_ot_request_employees l
               JOIN att_ot_requests r ON r.id = l.request_id
               JOIN att_employees e ON e.id = l.employee_id
               JOIN att_departments d ON d.id = r.department_id
               LEFT JOIN att_ot_payroll pay ON pay.request_employee_id = l.id
              WHERE ' . implode(' AND ', $where) . '
              ORDER BY l.work_date, d.name, e.full_name LIMIT 500', $p);
        $nextDay = Policy::bool('ot_verify_from_next_day', true);
        foreach ($rows as &$r) {
            $r['movement'] = self::movement((int) $r['employee_id'], (string) $r['work_date'], $r['planned_start'], $r['planned_end']);
            $r['can_verify_now'] = !$nextDay || (string) $r['work_date'] < self::today();
        }
        unset($r);
        return ['ok' => true, 'lines' => $rows, 'today' => self::today(), 'from_next_day' => $nextDay];
    }

    /**
     * The employee's gate entries that belong to a work date: those that
     * began between the start of that day and noon the next (factory time),
     * so a night shift's late hours are included.
     */
    public static function movement(int $employeeId, string $workDate, ?string $plannedStart = null, ?string $plannedEnd = null): array
    {
        $tz   = new DateTimeZone('Asia/Karachi');
        $utc  = new DateTimeZone('UTC');
        $from = (new DateTimeImmutable($workDate . ' 00:00:00', $tz))->setTimezone($utc);
        $to   = (new DateTimeImmutable($workDate . ' 12:00:00', $tz))->modify('+1 day')->setTimezone($utc);
        $cycles = Db::all(
            'SELECT c.entry_sequence, c.opened_at, c.closed_at, c.present_minutes, c.close_source, si.starts_at AS shift_starts_at, si.ends_at AS shift_ends_at, s.name AS shift_name
               FROM att_entry_cycles c
               JOIN att_shift_instances si ON si.id = c.shift_instance_id
               JOIN att_shifts s ON s.id = si.shift_id
              WHERE c.employee_id = ? AND c.opened_at >= ? AND c.opened_at < ?
              ORDER BY c.opened_at', [$employeeId, Time::sql($from), Time::sql($to)]);

        $shiftEnd = null;
        $after = 0;
        $insidePlanned = null;
        $pS = null; $pE = null;
        if ($plannedStart !== null && $plannedEnd !== null && $plannedStart !== '' && $plannedEnd !== '') {
            $pS = new DateTimeImmutable($workDate . ' ' . $plannedStart, $tz);
            $pE = new DateTimeImmutable($workDate . ' ' . $plannedEnd, $tz);
            if ($pE <= $pS) { $pE = $pE->modify('+1 day'); }
            $insidePlanned = 0;
        }
        $open = false;
        $list = [];
        foreach ($cycles as $c) {
            $in  = new DateTimeImmutable((string) $c['opened_at'], $utc);
            $out = $c['closed_at'] !== null ? new DateTimeImmutable((string) $c['closed_at'], $utc) : null;
            if ($out === null) { $open = true; }
            $end = new DateTimeImmutable((string) $c['shift_ends_at'], $utc);
            $shiftEnd = $shiftEnd === null || $end > $shiftEnd ? $end : $shiftEnd;
            if ($out !== null) {
                $after += self::overlap($in, $out, $end, null);
                if ($pS !== null) { $insidePlanned += self::overlap($in, $out, $pS, $pE); }
            }
            $list[] = ['entry_sequence' => (int) $c['entry_sequence'], 'shift' => $c['shift_name'],
                       'in' => Time::iso($in), 'out' => $out ? Time::iso($out) : null, 'present_minutes' => $c['present_minutes'] !== null ? (int) $c['present_minutes'] : null,
                       'auto_out' => ($c['close_source'] ?? '') === 'auto_shift_end'];
        }
        return [
            'entries' => $list,
            'shift_ends_at' => $shiftEnd ? Time::iso($shiftEnd) : null,
            'minutes_inside_after_shift' => $list === [] ? null : $after,
            'minutes_inside_planned' => $insidePlanned,
            'still_inside' => $open,
            'note' => 'For checking only. Time inside is not overtime unless a person verifies it.',
        ];
    }

    /**
     * @param list<string>|mixed $evidence
     */
    public static function verify(int $requestId, int $lineId, mixed $actual, string $note, mixed $evidence, int $actorId, bool $allowed = true): array
    {
        if (!$allowed) {
            return self::no('FORBIDDEN', 'Entering actual overtime hours is not switched on for gatekeepers.');
        }
        $note = self::text($note);
        if (mb_strlen($note) > 1000) {
            return self::no('VALIDATION', 'The note can be at most 1000 characters.');
        }
        $refs = [];
        if ($evidence !== null && $evidence !== '' && $evidence !== []) {
            if (!is_array($evidence) || count($evidence) > 10) {
                return self::no('VALIDATION', 'Evidence is a list of at most 10 short references.');
            }
            foreach ($evidence as $e) {
                $e = self::text($e);
                if ($e === '' || mb_strlen($e) > 200) {
                    return self::no('VALIDATION', 'Each evidence reference is 1 to 200 characters.');
                }
                $refs[] = $e;
            }
        }
        if (!self::wholeNumber($actual)) {
            return self::no('VALIDATION', 'Give the actual hours (0 if no overtime was worked).');
        }
        $actual = (int) $actual;

        return Db::tx(static function () use ($requestId, $lineId, $actual, $note, $refs, $actorId): array {
            $l = self::lockLine($requestId, $lineId);
            if (!$l['ok']) {
                return $l;
            }
            $line = $l['line'];
            if ($line['approval_status'] !== 'approved') {
                return self::no('NOT_APPROVED', 'Only approved overtime can be verified. This employee\'s request is ' . $line['approval_status'] . '.');
            }
            if ($line['verification_status'] === 'verified') {
                return self::no('ALREADY_VERIFIED', 'The actual hours were already entered. A change now is an amendment.');
            }
            if (Policy::bool('ot_verify_from_next_day', true) && (string) $line['work_date'] >= self::today()) {
                return self::no('TOO_EARLY', 'Actual hours are entered from the day after the work date, once the gate record for the evening is complete.');
            }
            if ($actual > (int) $line['approved_minutes']) {
                return self::no('EXCESS_REQUIRES_AMENDMENT', self::h($actual) . ' is more than the ' . self::h((int) $line['approved_minutes']) . ' approved. '
                    . 'An officer must first amend the approved hours, with a reason.');
            }
            Db::run(
                "UPDATE att_ot_request_employees SET verification_status = 'verified', actual_minutes = ?, verified_by = ?, verified_at = ?,
                        verification_note = ?, evidence_refs = ? WHERE id = ?",
                [$actual, $actorId, Time::sql(), $note !== '' ? $note : null, $refs !== [] ? json_encode($refs) : null, $lineId]
            );
            self::history($lineId, 'verification', 'actual_minutes', $line['actual_minutes'], $actual, $note, $actorId);
            Audit::record('ot.verify', 'att_ot_request_employee', $lineId, ['verification_status' => $line['verification_status']],
                ['actual_minutes' => $actual, 'evidence_refs' => $refs], $actorId, $note !== '' ? $note : null);
            return ['ok' => true, 'request_employee_id' => $lineId, 'actual_minutes' => $actual, 'verification_status' => 'verified'];
        });
    }

    /** The person checking cannot settle it: say why, and leave it for an amendment. */
    public static function dispute(int $requestId, int $lineId, string $note, int $actorId, bool $allowed): array
    {
        if (!$allowed) {
            return self::no('FORBIDDEN', 'Entering actual overtime hours is not switched on for gatekeepers.');
        }
        $note = self::text($note);
        if ($note === '' || mb_strlen($note) > 1000) {
            return self::no('REASON_REQUIRED', 'Say what does not add up. It is kept with the line.');
        }
        return Db::tx(static function () use ($requestId, $lineId, $note, $actorId): array {
            $l = self::lockLine($requestId, $lineId);
            if (!$l['ok']) {
                return $l;
            }
            $line = $l['line'];
            if ($line['approval_status'] !== 'approved' || $line['verification_status'] !== 'pending') {
                return self::no('VALIDATION', 'Only approved overtime that has not been verified can be disputed.');
            }
            Db::run("UPDATE att_ot_request_employees SET verification_status = 'disputed', verification_note = ?, verified_by = ?, verified_at = ? WHERE id = ?",
                [$note, $actorId, Time::sql(), $lineId]);
            self::history($lineId, 'dispute', 'verification_status', 'pending', 'disputed', $note, $actorId);
            Audit::record('ot.dispute', 'att_ot_request_employee', $lineId, null, ['verification_status' => 'disputed'], $actorId, $note);
            return ['ok' => true, 'request_employee_id' => $lineId, 'verification_status' => 'disputed'];
        });
    }

    // ============================================================== amendment

    /**
     * Change something already decided, with a reason, before payroll is
     * finalized. What can change:
     *   decision          approve ↔ reject
     *   approved_minutes  the approval itself (this is how excess is allowed)
     *   actual_minutes    the verified figure (also how a dispute is settled)
     */
    public static function amend(int $requestId, int $lineId, string $change, mixed $value, string $reason, int $actorId): array
    {
        $reason = self::text($reason);
        if ($reason === '' || mb_strlen($reason) > 1000) {
            return self::no('REASON_REQUIRED', 'An amendment needs a reason. It is kept with the line and in the audit log.');
        }
        if (!in_array($change, ['decision', 'approved_minutes', 'actual_minutes'], true)) {
            return self::no('VALIDATION', 'Choose what is being amended.');
        }
        $max = max(1, Policy::int('ot_max_minutes', 720));

        try {
            return Db::tx(static function () use ($requestId, $lineId, $change, $value, $reason, $actorId, $max): array {
                $l = self::lockLine($requestId, $lineId);
                if (!$l['ok']) {
                    return $l;
                }
                $line = $l['line'];
                $pay = Db::one('SELECT id, status, rate_type, manual_rate FROM att_ot_payroll WHERE request_employee_id = ? FOR UPDATE', [$lineId]);
                if ($pay !== null && $pay['status'] === 'finalized') {
                    return self::no('PAYROLL_LOCKED', 'Payroll for this line is finalized. Only a payroll correction can change it now.');
                }

                if ($change === 'decision') {
                    $to = ['approve' => 'approved', 'approved' => 'approved', 'reject' => 'rejected', 'rejected' => 'rejected'][strtolower((string) $value)] ?? '';
                    if ($to === '') {
                        return self::no('VALIDATION', 'The new decision is approve or reject.');
                    }
                    if ($line['approval_status'] === 'pending') {
                        return self::no('NOT_DECIDED', 'This employee has not been decided yet. Approve or reject it in the ordinary way.');
                    }
                    if ($line['approval_status'] === $to) {
                        return self::no('NO_CHANGE', 'It is already ' . $to . '.');
                    }
                    $old = ['approval_status' => $line['approval_status'], 'approved_minutes' => $line['approved_minutes'],
                            'verification_status' => $line['verification_status'], 'actual_minutes' => $line['actual_minutes']];
                    if ($to === 'rejected') {
                        // Rejected means nothing to verify and nothing to pay.
                        Db::run("UPDATE att_ot_request_employees SET approval_status = 'rejected', approved_minutes = NULL,
                                        verification_status = 'pending', actual_minutes = NULL, verified_by = NULL, verified_at = NULL
                                  WHERE id = ?", [$lineId]);
                        if ($pay !== null) {
                            Db::run('DELETE FROM att_ot_payroll WHERE id = ?', [(int) $pay['id']]);
                        }
                        $new = ['approval_status' => 'rejected', 'payroll_draft_removed' => $pay !== null];
                    } else {
                        Db::run("UPDATE att_ot_request_employees SET approval_status = 'approved', approved_minutes = estimated_minutes WHERE id = ?", [$lineId]);
                        $new = ['approval_status' => 'approved', 'approved_minutes' => (int) $line['estimated_minutes']];
                    }
                } elseif ($change === 'approved_minutes') {
                    if ($line['approval_status'] !== 'approved') {
                        return self::no('NOT_APPROVED', 'Only approved overtime has approved hours to change.');
                    }
                    if (!self::wholeNumber($value) || (int) $value < 1 || (int) $value > $max) {
                        return self::no('VALIDATION', 'The approved time must be more than 0 and at most ' . self::h($max) . '.');
                    }
                    $v = (int) $value;
                    if ($v === (int) $line['approved_minutes']) {
                        return self::no('NO_CHANGE', 'It is already ' . self::h((int) $v) . '.');
                    }
                    if ($line['actual_minutes'] !== null && (int) $line['actual_minutes'] > $v) {
                        return self::no('VALIDATION', 'The verified actual is ' . self::h((int) $line['actual_minutes']) . '. Amend the actual hours down first.');
                    }
                    Db::run('UPDATE att_ot_request_employees SET approved_minutes = ? WHERE id = ?', [$v, $lineId]);
                    $old = ['approved_minutes' => (int) $line['approved_minutes']];
                    $new = ['approved_minutes' => $v];
                } else {
                    if ($line['approval_status'] !== 'approved' || $line['verification_status'] === 'pending') {
                        return self::no('VALIDATION', 'Only verified or disputed overtime has actual hours to amend. Otherwise enter them on the Verify tab.');
                    }
                    if (!self::wholeNumber($value) || (int) $value > (int) $line['approved_minutes']) {
                        return self::no('VALIDATION', 'The actual time must be from 0 to the ' . self::h((int) $line['approved_minutes']) . ' approved. '
                            . 'To allow more, amend the approved hours first.');
                    }
                    $v = (int) $value;
                    if ($line['verification_status'] === 'verified' && $v === (int) $line['actual_minutes']) {
                        return self::no('NO_CHANGE', 'It is already ' . self::h((int) $v) . '.');
                    }
                    Db::run("UPDATE att_ot_request_employees SET actual_minutes = ?, verification_status = 'verified',
                                    verified_by = COALESCE(verified_by, ?), verified_at = COALESCE(verified_at, ?) WHERE id = ?",
                        [$v, $actorId, Time::sql(), $lineId]);
                    $old = ['actual_minutes' => $line['actual_minutes'], 'verification_status' => $line['verification_status']];
                    $new = ['actual_minutes' => $v, 'verification_status' => 'verified'];
                    // A rate already typed stays; the amount follows the new minutes.
                    if ($pay !== null) {
                        if ($v === 0) {
                            Db::run('DELETE FROM att_ot_payroll WHERE id = ?', [(int) $pay['id']]);
                            $new['payroll_draft_removed'] = true;
                        } else {
                            $amt = self::amount((string) $pay['rate_type'], self::cents((string) $pay['manual_rate']) ?? 0, $v);
                            Db::run('UPDATE att_ot_payroll SET payable_minutes = ?, payable_amount = ? WHERE id = ?', [$v, self::money($amt), (int) $pay['id']]);
                            $new['payable_amount'] = self::money($amt);
                        }
                    }
                }
                self::history($lineId, 'amendment', $change, $old, $new, $reason, $actorId);
                Audit::record('ot.amend', 'att_ot_request_employee', $lineId, $old, $new + ['change' => $change], $actorId, $reason);
                return ['ok' => true, 'request_employee_id' => $lineId, 'change' => $change, 'old' => $old, 'new' => $new];
            });
        } catch (\Throwable $e) {
            if (Db::isDuplicate($e)) {
                return self::no('ALREADY_REQUESTED', 'This employee already has another live overtime line for that day, so this one cannot be approved again.');
            }
            throw $e;
        }
    }

    // ================================================================ payroll

    public static function setRate(int $lineId, string $rateType, string $rate, int $actorId): array
    {
        if (!in_array($rateType, ['hourly', 'fixed'], true)) {
            return self::no('VALIDATION', 'Choose hourly or fixed.');
        }
        $c = self::cents($rate);
        if ($c === null || $c <= 0) {
            return self::no('VALIDATION', 'Type the rate as an amount, for example 250 or 250.50. It is never worked out by the system.');
        }
        return Db::tx(static function () use ($lineId, $rateType, $c, $actorId): array {
            $line = Db::one('SELECT id, approval_status, verification_status, actual_minutes FROM att_ot_request_employees WHERE id = ? FOR UPDATE', [$lineId]);
            if ($line === null) {
                return self::no('NOT_FOUND', 'That overtime line does not exist.');
            }
            if ($line['approval_status'] !== 'approved' || $line['verification_status'] !== 'verified') {
                return self::no('NOT_VERIFIED', 'A rate is entered only after the actual hours have been verified.');
            }
            if ((int) $line['actual_minutes'] === 0) {
                return self::no('NOTHING_TO_PAY', 'The verified actual is 0 hours; there is nothing to pay.');
            }
            $pay = Db::one('SELECT id, status, rate_type, manual_rate, payable_amount FROM att_ot_payroll WHERE request_employee_id = ? FOR UPDATE', [$lineId]);
            if ($pay !== null && $pay['status'] === 'finalized') {
                return self::no('PAYROLL_LOCKED', 'Payroll for this line is finalized. Only a payroll correction can change it now.');
            }
            $min = (int) $line['actual_minutes'];
            $amt = self::amount($rateType, $c, $min);
            if ($pay === null) {
                Db::run('INSERT INTO att_ot_payroll (request_employee_id, rate_type, manual_rate, payable_minutes, payable_amount, rated_by, rated_at)
                         VALUES (?, ?, ?, ?, ?, ?, ?)', [$lineId, $rateType, self::money($c), $min, self::money($amt), $actorId, Time::sql()]);
            } else {
                Db::run('UPDATE att_ot_payroll SET rate_type = ?, manual_rate = ?, payable_minutes = ?, payable_amount = ?, rated_by = ?, rated_at = ? WHERE id = ?',
                    [$rateType, self::money($c), $min, self::money($amt), $actorId, Time::sql(), (int) $pay['id']]);
            }
            $old = $pay === null ? null : ['rate_type' => $pay['rate_type'], 'manual_rate' => $pay['manual_rate'], 'payable_amount' => $pay['payable_amount']];
            $new = ['rate_type' => $rateType, 'manual_rate' => self::money($c), 'payable_minutes' => $min, 'payable_amount' => self::money($amt)];
            self::history($lineId, 'rate', 'rate', $old, $new, null, $actorId);
            Audit::record('ot.rate', 'att_ot_request_employee', $lineId, $old, $new, $actorId);
            return ['ok' => true, 'request_employee_id' => $lineId] + $new;
        });
    }

    /** Everything in a period, as payroll sees it, and what still stops it being finalized. */
    public static function payroll(string $from, string $to): array
    {
        $f = self::date($from);
        $t = self::date($to);
        if ($f === null || $t === null || $t < $f) {
            return self::no('VALIDATION', 'Give a period: a first and a last work date.');
        }
        if ((int) $f->diff($t)->days > 92) {
            return self::no('VALIDATION', 'At most 92 days at a time.');
        }
        $rows = self::periodLines($f->format('Y-m-d'), $t->format('Y-m-d'), false);
        $blockers = [];
        $ready = 0;
        $total = 0;
        foreach ($rows as &$r) {
            $r['readiness'] = self::readiness($r);
            if ($r['readiness'] === 'ready') {
                $ready++;
                $total += self::cents((string) ($r['payable_amount'] ?? '0')) ?? 0;
            } elseif (!in_array($r['readiness'], ['finalized', 'rejected'], true)) {
                $blockers[] = $r;
            }
        }
        unset($r);
        return ['ok' => true, 'from' => $f->format('Y-m-d'), 'to' => $t->format('Y-m-d'), 'lines' => $rows,
                'ready' => $ready, 'blocking' => count($blockers), 'total_ready' => self::money($total)];
    }

    /**
     * Lock a period. Refuses while anything in it is undecided, unverified,
     * disputed or unrated, and refuses if the total is not the one the
     * person was looking at when they pressed the button.
     */
    public static function finalize(string $runUuid, string $from, string $to, string $expectedTotal, string $note, int $actorId): array
    {
        $runUuid = strtolower(trim($runUuid));
        if (!Receipts::validUuid($runUuid)) {
            return self::no('VALIDATION', 'The payroll run has no valid id.');
        }
        $done = Db::one('SELECT * FROM att_ot_payroll_runs WHERE run_uuid = ?', [$runUuid]);
        if ($done !== null) {
            return ['ok' => true, 'already' => true, 'run' => $done];
        }
        $f = self::date($from);
        $t = self::date($to);
        if ($f === null || $t === null || $t < $f) {
            return self::no('VALIDATION', 'Give a period: a first and a last work date.');
        }
        if ($t->format('Y-m-d') >= self::today()) {
            return self::no('VALIDATION', 'A period can be finalized only once its last day is over.');
        }
        $expected = self::cents($expectedTotal);
        if ($expected === null) {
            return self::no('VALIDATION', 'The total shown on the screen was not sent.');
        }
        $note = self::text($note);

        try {
            return Db::tx(static function () use ($runUuid, $f, $t, $expected, $note, $actorId): array {
                $rows = self::periodLines($f->format('Y-m-d'), $t->format('Y-m-d'), true);
                $todo = [];
                $blocked = [];
                $total = 0;
                foreach ($rows as $r) {
                    $state = self::readiness($r);
                    if ($state === 'ready') {
                        $todo[] = $r;
                        $total += self::cents((string) ($r['payable_amount'] ?? '0')) ?? 0;
                    } elseif (!in_array($state, ['finalized', 'rejected'], true)) {
                        $blocked[] = ['request_employee_id' => (int) $r['request_employee_id'], 'employee_code' => $r['employee_code'],
                                      'full_name' => $r['full_name'], 'work_date' => $r['work_date'], 'readiness' => $state];
                    }
                }
                if ($blocked !== []) {
                    return self::no('NOT_READY', count($blocked) . ' line' . (count($blocked) === 1 ? ' is' : 's are') . ' not ready: every employee must be decided, verified and, if anything is owed, given a rate.')
                         + ['blocking' => $blocked];
                }
                if ($todo === []) {
                    return self::no('NOTHING_TO_FINALIZE', 'There is nothing left to finalize in that period.');
                }
                if ($total !== $expected) {
                    return self::no('TOTAL_CHANGED', 'The total is now ' . self::money($total) . ', not the ' . self::money($expected)
                        . ' shown when you started. Something changed meanwhile: look again before finalizing.') + ['total' => self::money($total)];
                }
                $now = Time::sql();
                Db::run('INSERT INTO att_ot_payroll_runs (run_uuid, period_from, period_to, line_count, total_amount, note, finalized_by, finalized_at)
                         VALUES (?, ?, ?, ?, ?, ?, ?, ?)',
                    [$runUuid, $f->format('Y-m-d'), $t->format('Y-m-d'), count($todo), self::money($total), $note !== '' ? $note : null, $actorId, $now]);
                $runId = Db::lastId();
                foreach ($todo as $r) {
                    $lid = (int) $r['request_employee_id'];
                    if ($r['payroll_id'] === null) {
                        // Verified at 0 minutes: nothing to pay, locked all the same.
                        Db::run("INSERT INTO att_ot_payroll (request_employee_id, rate_type, manual_rate, payable_minutes, payable_amount, status, run_id, finalized_by, finalized_at)
                                 VALUES (?, 'none', 0, 0, 0, 'finalized', ?, ?, ?)", [$lid, $runId, $actorId, $now]);
                    } else {
                        Db::run("UPDATE att_ot_payroll SET status = 'finalized', run_id = ?, finalized_by = ?, finalized_at = ? WHERE id = ? AND status = 'draft'",
                            [$runId, $actorId, $now, (int) $r['payroll_id']]);
                    }
                    self::history($lid, 'finalize', 'payroll_status', 'draft', 'finalized', 'run ' . $runId, $actorId);
                }
                Audit::record('ot.payroll_finalize', 'att_ot_payroll_run', $runId, null,
                    ['from' => $f->format('Y-m-d'), 'to' => $t->format('Y-m-d'), 'lines' => count($todo), 'total' => self::money($total)], $actorId, $note !== '' ? $note : null);
                return ['ok' => true, 'already' => false, 'run' => Db::one('SELECT * FROM att_ot_payroll_runs WHERE id = ?', [$runId])];
            });
        } catch (\Throwable $e) {
            if (Db::isDuplicate($e)) {
                $done = Db::one('SELECT * FROM att_ot_payroll_runs WHERE run_uuid = ?', [$runUuid]);
                if ($done !== null) {
                    return ['ok' => true, 'already' => true, 'run' => $done];
                }
            }
            throw $e;
        }
    }

    /**
     * After finalization: record what is now owed, and why, beside what was
     * finalized. The finalized figures and the run's total are not touched.
     */
    public static function correct(int $lineId, mixed $minutes, string $rateType, string $rate, string $reason, int $actorId): array
    {
        $reason = self::text($reason);
        if ($reason === '' || mb_strlen($reason) > 1000) {
            return self::no('REASON_REQUIRED', 'A payroll correction needs a reason. It is kept and audited.');
        }
        if (!in_array($rateType, ['hourly', 'fixed', 'none'], true)) {
            return self::no('VALIDATION', 'Choose hourly, fixed, or none (nothing owed).');
        }
        $max = max(1, Policy::int('ot_max_minutes', 720));
        if (!self::wholeNumber($minutes) || (int) $minutes > $max) {
            return self::no('VALIDATION', 'The time must be from 0 to ' . self::h($max) . '.');
        }
        $min = (int) $minutes;
        $c = $rateType === 'none' ? 0 : self::cents($rate);
        if ($c === null || ($rateType !== 'none' && $c <= 0)) {
            return self::no('VALIDATION', 'Type the rate as an amount.');
        }
        $amt = $rateType === 'none' || $min === 0 ? 0 : self::amount($rateType, $c, $min);

        return Db::tx(static function () use ($lineId, $min, $rateType, $c, $amt, $reason, $actorId): array {
            $pay = Db::one('SELECT * FROM att_ot_payroll WHERE request_employee_id = ? FOR UPDATE', [$lineId]);
            if ($pay === null || $pay['status'] !== 'finalized') {
                return self::no('NOT_FINALIZED', 'Only finalized payroll is corrected. Before that, change the rate or amend the line.');
            }
            $last = Db::one('SELECT new_minutes, new_rate_type, new_rate, new_amount FROM att_ot_payroll_corrections WHERE payroll_id = ? ORDER BY id DESC LIMIT 1', [(int) $pay['id']]);
            $oldMin  = (int) ($last['new_minutes'] ?? $pay['payable_minutes']);
            $oldType = (string) ($last['new_rate_type'] ?? $pay['rate_type']);
            $oldRate = (string) ($last['new_rate'] ?? $pay['manual_rate']);
            $oldAmt  = (string) ($last['new_amount'] ?? $pay['payable_amount']);
            if ($oldMin === $min && $oldType === $rateType && self::cents($oldRate) === $c) {
                return self::no('NO_CHANGE', 'That is what is already owed.');
            }
            $delta = $amt - (self::cents($oldAmt) ?? 0);
            Db::run('INSERT INTO att_ot_payroll_corrections (payroll_id, old_minutes, new_minutes, old_rate_type, new_rate_type, old_rate, new_rate,
                                                              old_amount, new_amount, delta_amount, reason, actor_id)
                     VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)',
                [(int) $pay['id'], $oldMin, $min, $oldType, $rateType, $oldRate, self::money($c), $oldAmt, self::money($amt), self::money($delta), $reason, $actorId]);
            Db::run('UPDATE att_ot_payroll SET effective_minutes = ?, effective_amount = ? WHERE id = ?', [$min, self::money($amt), (int) $pay['id']]);
            $old = ['minutes' => $oldMin, 'rate_type' => $oldType, 'rate' => $oldRate, 'amount' => $oldAmt];
            $new = ['minutes' => $min, 'rate_type' => $rateType, 'rate' => self::money($c), 'amount' => self::money($amt), 'delta' => self::money($delta)];
            self::history($lineId, 'correction', 'payroll', $old, $new, $reason, $actorId);
            Audit::record('ot.payroll_correct', 'att_ot_payroll', (int) $pay['id'], $old, $new, $actorId, $reason);
            return ['ok' => true, 'request_employee_id' => $lineId] + $new;
        });
    }

    public static function runs(): array
    {
        $users = Db::tableExists('users');
        $rows = Db::all(
            'SELECT r.*, ' . ($users ? 'u.name' : 'NULL') . ' AS finalized_by_name,
                    (SELECT COALESCE(SUM(c.delta_amount), 0) FROM att_ot_payroll p JOIN att_ot_payroll_corrections c ON c.payroll_id = p.id WHERE p.run_id = r.id) AS corrections_total,
                    (SELECT COUNT(*) FROM att_ot_payroll p JOIN att_ot_payroll_corrections c ON c.payroll_id = p.id WHERE p.run_id = r.id) AS corrections
               FROM att_ot_payroll_runs r ' . ($users ? 'LEFT JOIN users u ON u.id = r.finalized_by ' : '') . '
              ORDER BY r.period_to DESC, r.id DESC LIMIT 100');
        return ['ok' => true, 'runs' => $rows];
    }

    /** One run, line by line, for the spreadsheet Accounts takes away. */
    public static function runLines(int $runId): array
    {
        $run = Db::one('SELECT * FROM att_ot_payroll_runs WHERE id = ?', [$runId]);
        if ($run === null) {
            return self::no('NOT_FOUND', 'That payroll run does not exist.');
        }
        $lines = Db::all(
            'SELECT l.work_date, d.name AS department_name, e.code AS employee_code, e.full_name,
                    l.approved_minutes, l.actual_minutes, p.rate_type, p.manual_rate, p.payable_minutes, p.payable_amount,
                    p.effective_minutes, p.effective_amount
               FROM att_ot_payroll p
               JOIN att_ot_request_employees l ON l.id = p.request_employee_id
               JOIN att_ot_requests r ON r.id = l.request_id
               JOIN att_departments d ON d.id = r.department_id
               JOIN att_employees e ON e.id = l.employee_id
              WHERE p.run_id = ? ORDER BY l.work_date, d.name, e.full_name', [$runId]);
        return ['ok' => true, 'run' => $run, 'lines' => $lines];
    }

    /** Everything that ever happened to one line, oldest first. */
    public static function history(int $lineId, string $kind = '', string $field = '', mixed $old = null, mixed $new = null, ?string $remarks = null, ?int $actorId = null): array
    {
        if ($kind !== '') {
            Db::run('INSERT INTO att_ot_history (line_id, kind, field, old_value, new_value, remarks, actor_id) VALUES (?, ?, ?, ?, ?, ?, ?)',
                [$lineId, $kind, $field !== '' ? $field : null, $old === null ? null : json_encode($old), $new === null ? null : json_encode($new),
                 ($remarks !== null && $remarks !== '') ? $remarks : null, $actorId]);
            return ['ok' => true];
        }
        $users = Db::tableExists('users');
        return ['ok' => true, 'history' => Db::all(
            'SELECT h.kind, h.field, h.old_value, h.new_value, h.remarks, h.created_at, ' . ($users ? 'u.name' : 'NULL') . ' AS actor_name
               FROM att_ot_history h ' . ($users ? 'LEFT JOIN users u ON u.id = h.actor_id ' : '') . 'WHERE h.line_id = ? ORDER BY h.id', [$lineId])];
    }

    // ================================================================ helpers

    /** @return list<array<string,mixed>> */
    private static function periodLines(string $from, string $to, bool $lock): array
    {
        return Db::all(
            'SELECT l.id AS request_employee_id, l.request_id, l.work_date, e.code AS employee_code, e.full_name, d.name AS department_name,
                    l.estimated_minutes, l.approval_status, l.approved_minutes, l.verification_status, l.actual_minutes,
                    p.id AS payroll_id, p.status AS payroll_status, p.rate_type, p.manual_rate, p.payable_minutes, p.payable_amount,
                    p.effective_amount, p.run_id
               FROM att_ot_request_employees l
               JOIN att_ot_requests r ON r.id = l.request_id
               JOIN att_employees e ON e.id = l.employee_id
               JOIN att_departments d ON d.id = r.department_id
               LEFT JOIN att_ot_payroll p ON p.request_employee_id = l.id
              WHERE l.work_date BETWEEN ? AND ?
              ORDER BY l.work_date, d.name, e.full_name' . ($lock ? ' FOR UPDATE' : ''), [$from, $to]);
    }

    /** @param array<string,mixed> $r */
    private static function readiness(array $r): string
    {
        if (($r['payroll_status'] ?? null) === 'finalized') { return 'finalized'; }
        if ($r['approval_status'] === 'rejected') { return 'rejected'; }
        if ($r['approval_status'] === 'pending') { return 'undecided'; }
        if ($r['verification_status'] === 'disputed') { return 'disputed'; }
        if ($r['verification_status'] !== 'verified') { return 'unverified'; }
        if ((int) $r['actual_minutes'] > 0 && $r['payroll_id'] === null) { return 'no_rate'; }
        return 'ready';
    }

    /** @return array{ok:true,line:array<string,mixed>}|array{ok:false,reason_code:string,error:string} */
    private static function lockLine(int $requestId, int $lineId): array
    {
        $line = Db::one('SELECT * FROM att_ot_request_employees WHERE id = ? FOR UPDATE', [$lineId]);
        if ($line === null || (int) $line['request_id'] !== $requestId) {
            return self::no('NOT_FOUND', 'That employee is not on this request.');
        }
        $paid = Db::value("SELECT status FROM att_ot_payroll WHERE request_employee_id = ?", [$lineId]);
        if ($paid === 'finalized') {
            return self::no('PAYROLL_LOCKED', 'Payroll for this line is finalized. Only a payroll correction can change it now.');
        }
        return ['ok' => true, 'line' => $line];
    }

    private static function storedBatch(string $uuid): ?array
    {
        $raw = Db::value('SELECT response_json FROM att_ot_decision_batches WHERE decision_uuid = ?', [$uuid]);
        if ($raw === null || $raw === false) {
            return null;
        }
        $j = json_decode((string) $raw, true);
        return is_array($j) ? $j : null;
    }

    /** Minutes of [a1, a2] that fall inside [b1, b2] (b2 null = open-ended). */
    private static function overlap(DateTimeImmutable $a1, DateTimeImmutable $a2, DateTimeImmutable $b1, ?DateTimeImmutable $b2): int
    {
        $s = max($a1->getTimestamp(), $b1->getTimestamp());
        $e = $b2 === null ? $a2->getTimestamp() : min($a2->getTimestamp(), $b2->getTimestamp());
        return $e > $s ? intdiv($e - $s, 60) : 0;
    }

    /**
     * Money is counted in paisa, as whole numbers, from the moment it is typed:
     * "250.50" × 95 minutes ÷ 60 must not become 396.62499999.
     */
    public static function cents(string $s): ?int
    {
        $s = trim($s);
        if (!preg_match('/^(\d{1,10})(?:\.(\d{1,2}))?$/', $s, $m)) {
            return null;
        }
        return (int) $m[1] * 100 + (int) str_pad($m[2] ?? '0', 2, '0');
    }

    public static function money(int $cents): string
    {
        $neg = $cents < 0;
        $c = abs($cents);
        return ($neg ? '-' : '') . intdiv($c, 100) . '.' . str_pad((string) ($c % 100), 2, '0', STR_PAD_LEFT);
    }

    /** Hourly: rate × minutes ÷ 60, half a paisa rounded up. Fixed: the rate. */
    public static function amount(string $type, int $rateCents, int $minutes): int
    {
        if ($type === 'fixed') {
            return $rateCents;
        }
        if ($type !== 'hourly') {
            return 0;
        }
        return intdiv($rateCents * $minutes * 2 + 60, 120);
    }

    private static function userName(int $id): string
    {
        if ($id <= 0 || !Db::tableExists('users')) {
            return 'another officer';
        }
        return (string) (Db::value('SELECT name FROM users WHERE id = ?', [$id]) ?? 'another officer');
    }

    private static function text(mixed $v): string
    {
        if (!is_scalar($v)) {
            return '';
        }
        return trim(preg_replace('/\s+/u', ' ', preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/u', '', (string) $v) ?? '') ?? '');
    }

    /** Minutes as people read overtime: 90 → "1.5 h", 85 → "1.42 h". */
    public static function h(int $m): string
    {
        return rtrim(rtrim(number_format($m / 60, 2, '.', ''), '0'), '.') . ' h';
    }

    private static function wholeNumber(mixed $v): bool
    {
        return is_int($v) ? $v >= 0 : (is_string($v) && preg_match('/^\d{1,6}$/', trim($v)) === 1);
    }

    private static function date(string $d): ?DateTimeImmutable
    {
        if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $d)) {
            return null;
        }
        $x = DateTimeImmutable::createFromFormat('!Y-m-d', $d, new DateTimeZone('UTC'));
        return ($x !== false && $x->format('Y-m-d') === $d) ? $x : null;
    }

    /** @return string|null|false  "HH:MM:00", null for blank, false for rubbish */
    private static function hhmm(mixed $t): string|null|false
    {
        $t = is_scalar($t) ? trim((string) $t) : '';
        if ($t === '') {
            return null;
        }
        if (!preg_match('/^(\d{1,2}):(\d{2})(?::\d{2})?$/', $t, $m) || (int) $m[1] > 23 || (int) $m[2] > 59) {
            return false;
        }
        return sprintf('%02d:%02d:00', (int) $m[1], (int) $m[2]);
    }

    private static function no(string $code, string $message): array
    {
        return ['ok' => false, 'reason_code' => $code, 'error' => $message];
    }
}
