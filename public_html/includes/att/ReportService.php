<?php
declare(strict_types=1);

namespace Att;

use DateTimeImmutable;
use DateTimeZone;

/**
 * Today's dashboard and the three reports API_CONTRACT.md names:
 * /reports/attendance, /reports/movements, /reports/overtime.
 *
 * READ-ONLY. Nothing here writes, corrects or decides; every figure is read
 * from the records the gate, the HODs and the officers made.
 *
 * WHAT THE NUMBERS MEAN, said once so the screens can say it the same way:
 *   Present minutes  the sum of CLOSED entries (IN to OUT) for the shift day.
 *                    An entry still open counts as "inside", not as minutes.
 *                    HOD confirmation times are never part of it (blueprint
 *                    §5: "never treat HOD arrival as second payroll IN").
 *   Late             first IN after the shift start plus its grace minutes.
 *   Left early       the last OUT before the shift end minus its grace, with
 *                    nothing still open. Blueprint §2 calls this a POTENTIAL
 *                    early exit: an approved break or permission may explain
 *                    it, and the screens say "potential".
 *   Overtime         only approved overtime, as entered by people (M6). Time
 *                    inside after the shift is never shown as overtime.
 *
 * Department scope comes from the caller: null = every department (HR,
 * Accounts, Admin); a list = a HOD's own departments.
 */
final class ReportService
{
    public const MAX_DAYS = 62;
    public const MAX_ROWS = 20000;
    private const TZ = 'Asia/Karachi';

    private static ?string $now = null;

    /** For the tests only: pretend it is $iso now (UTC). */
    public static function setNow(?string $iso): void
    {
        self::$now = $iso;
    }

    private static function nowUtc(): DateTimeImmutable
    {
        return self::$now !== null ? new DateTimeImmutable(self::$now, new DateTimeZone('UTC')) : Time::nowUtc();
    }

    public static function today(): string
    {
        return self::nowUtc()->setTimezone(new DateTimeZone(self::TZ))->format('Y-m-d');
    }

    // ============================================================== dashboard

    /** @param list<int>|null $scope */
    public static function dashboard(?array $scope): array
    {
        $today = self::today();
        [$from, $to] = self::utcRange($today, $today);
        [$dw, $dp] = self::deptWhere($scope, 'e.department_id');
        if ($dw === false) {
            return ['ok' => true, 'today' => $today, 'empty_scope' => true];
        }

        $inside = Db::all(
            "SELECT d.name AS department_name, COUNT(*) AS inside
               FROM att_entry_cycles c JOIN att_employees e ON e.id = c.employee_id JOIN att_departments d ON d.id = e.department_id
              WHERE c.closed_at IS NULL $dw GROUP BY d.id, d.name ORDER BY d.name", $dp);

        $active = (int) Db::value("SELECT COUNT(*) FROM att_employees e WHERE e.active = 1 $dw", $dp);
        $came = Db::one(
            "SELECT COUNT(DISTINCT c.employee_id) AS people, COUNT(*) AS entries,
                    SUM(h.id IS NULL) AS unconfirmed
               FROM att_entry_cycles c JOIN att_employees e ON e.id = c.employee_id
               LEFT JOIN att_hod_arrivals h ON h.entry_cycle_id = c.id AND h.status = 'confirmed'
              WHERE c.opened_at >= ? AND c.opened_at < ? $dw", array_merge([$from, $to], $dp));

        $days = self::attendanceRows($today, $today, $scope, null, null);
        $late = array_values(array_filter($days, fn($r) => $r['late_minutes'] > 0));
        $early = array_values(array_filter($days, fn($r) => $r['early_minutes'] > 0));
        $absent = array_values(array_filter($days, fn($r) => $r['status'] === 'absent'));

        $notIn = Db::all(
            "SELECT e.code AS employee_code, e.full_name, d.name AS department_name
               FROM att_employees e JOIN att_departments d ON d.id = e.department_id
              WHERE e.active = 1 $dw
                AND NOT EXISTS (SELECT 1 FROM att_entry_cycles c WHERE c.employee_id = e.id AND c.opened_at >= ? AND c.opened_at < ?)
              ORDER BY d.name, e.full_name LIMIT 300", array_merge($dp, [$from, $to]));

        /* Not scanned out: closed by the system in the last day and a half, so
           the morning after shows last night's forgotten exits. */
        $auto = Db::all(
            "SELECT e.code AS employee_code, e.full_name, d.name AS department_name, c.opened_at, c.closed_at, c.auto_closed_at, si.work_date
               FROM att_entry_cycles c JOIN att_employees e ON e.id = c.employee_id JOIN att_departments d ON d.id = e.department_id
               JOIN att_shift_instances si ON si.id = c.shift_instance_id
              WHERE c.close_source = 'auto_shift_end' AND c.auto_closed_at >= ? $dw
              ORDER BY c.auto_closed_at DESC, e.full_name LIMIT 300", array_merge([Time::sql(self::nowUtc()->modify('-36 hours'))], $dp));
        $utcZ = new DateTimeZone('UTC');
        foreach ($auto as &$a) {
            foreach (['opened_at', 'closed_at', 'auto_closed_at'] as $k) {
                $a[$k] = Time::iso(new DateTimeImmutable((string) $a[$k], $utcZ));
            }
        }
        unset($a);

        $out = [
            'ok' => true, 'today' => $today, 'generated_at' => Time::iso(self::nowUtc()),
            'auto_out' => $auto, 'auto_out_count' => count($auto),
            'inside_now' => array_sum(array_map(fn($r) => (int) $r['inside'], $inside)),
            'inside_by_department' => $inside,
            'active_employees' => $active,
            'came_in' => (int) ($came['people'] ?? 0),
            'entries' => (int) ($came['entries'] ?? 0),
            'unconfirmed_entries' => (int) ($came['unconfirmed'] ?? 0),
            'not_in' => $notIn, 'not_in_count' => max(0, $active - (int) ($came['people'] ?? 0)),
            'late' => array_slice($late, 0, 100), 'late_count' => count($late),
            'left_early' => array_slice($early, 0, 100), 'left_early_count' => count($early),
            'absent_with_shift' => array_slice($absent, 0, 100), 'absent_with_shift_count' => count($absent),
        ];
        if ($scope === null) {
            $out['monitoring'] = self::monitoring();
        }
        return $out;
    }

    /**
     * Blueprint §7: "monitoring for sync backlog, unpaired events, clock
     * drift, failed R2 uploads and backup failures". The server cannot see
     * what is still on a phone; what it can see is a phone that has gone
     * quiet, which is how a backlog shows from here.
     */
    private static function monitoring(): array
    {
        $quietMin = max(10, Policy::int('sync_backlog_warn_minutes', 120));
        $now = self::nowUtc();
        $quietSince = Time::sql($now->modify("-$quietMin minutes"));
        $skew = max(30000, Policy::int('clock_skew_warn_ms', 120000));
        $users = Db::tableExists('users');
        $t = fn(string $tbl) => Db::tableExists($tbl);
        return [
            'phones_quiet' => Db::all(
                'SELECT dv.id, dv.label, dv.role_scope, dv.last_seen_at' . ($users ? ', u.name AS registered_by_name' : ', NULL AS registered_by_name') . '
                   FROM att_devices dv' . ($users ? ' LEFT JOIN users u ON u.id = dv.registered_by' : '') . '
                  WHERE dv.revoked_at IS NULL AND (dv.last_seen_at IS NULL OR dv.last_seen_at < ?)
                  ORDER BY dv.last_seen_at LIMIT 50', [$quietSince]),
            'quiet_after_minutes' => $quietMin,
            'held_scans' => (int) Db::value("SELECT COUNT(*) FROM att_sync_receipts WHERE receipt_status = 'NEEDS_REVIEW' AND review_state IS NULL"),
            'hod_unmatched' => (int) Db::value("SELECT COUNT(*) FROM att_hod_arrivals WHERE status IN ('pending_match','ambiguous','disputed')"),
            'entries_left_open' => (int) Db::value('SELECT COUNT(*) FROM att_entry_cycles WHERE closed_at IS NULL AND opened_at < ?',
                [Time::sql($now->modify('-' . max(1, Policy::int('stale_open_cycle_hours', 16)) . ' hours'))]),
            'phones_clock_wrong' => (int) Db::value('SELECT COUNT(DISTINCT device_id) FROM att_events WHERE ABS(clock_skew_ms) > ? AND received_at > ?',
                [$skew, Time::sql($now->modify('-7 days'))]),
            'photo_upload_failures_7d' => (int) Db::value("SELECT COUNT(*) FROM att_audit_logs WHERE action = 'photo.store_failed' AND created_at > ?", [Time::sql($now->modify('-7 days'))]),
            'flags_open' => $t('att_flags') ? (int) Db::value("SELECT COUNT(*) FROM att_flags WHERE status = 'open'") : 0,
            'ot_waiting_decision' => $t('att_ot_request_employees') ? (int) Db::value("SELECT COUNT(*) FROM att_ot_request_employees WHERE approval_status = 'pending'") : 0,
            'ot_waiting_check' => $t('att_ot_request_employees') ? (int) Db::value(
                "SELECT COUNT(*) FROM att_ot_request_employees WHERE approval_status = 'approved' AND verification_status IN ('pending','disputed') AND work_date < ?", [self::today()]) : 0,
            'ot_waiting_rate' => $t('att_ot_request_employees') ? (int) Db::value(
                "SELECT COUNT(*) FROM att_ot_request_employees l LEFT JOIN att_ot_payroll p ON p.request_employee_id = l.id
                  WHERE l.approval_status = 'approved' AND l.verification_status = 'verified' AND l.actual_minutes > 0 AND p.id IS NULL") : 0,
            'backups' => 'Database backups are taken by Cloudways, outside this system: check them in the Cloudways panel.',
        ];
    }

    // ============================================================= attendance

    /** @param array<string,mixed> $f from, to, department_id, employee @param list<int>|null $scope */
    public static function attendance(array $f, ?array $scope): array
    {
        $r = self::period($f);
        if (!$r['ok']) {
            return $r;
        }
        $rows = self::attendanceRows($r['from'], $r['to'], $scope, (int) ($f['department_id'] ?? 0) ?: null, self::str($f['employee'] ?? ''));
        $sum = ['days' => count($rows), 'present' => 0, 'absent' => 0, 'present_minutes' => 0, 'late' => 0, 'left_early' => 0, 'unconfirmed' => 0, 'ot_actual_minutes' => 0, 'auto_out' => 0];
        foreach ($rows as $x) {
            $sum[$x['status'] === 'absent' ? 'absent' : 'present']++;
            $sum['present_minutes'] += $x['present_minutes'];
            $sum['late'] += $x['late_minutes'] > 0 ? 1 : 0;
            $sum['left_early'] += $x['early_minutes'] > 0 ? 1 : 0;
            $sum['unconfirmed'] += $x['unconfirmed'];
            $sum['auto_out'] += $x['auto_out'] > 0 ? 1 : 0;
            $sum['ot_actual_minutes'] += (int) ($x['ot_actual_minutes'] ?? 0);
        }
        return ['ok' => true, 'from' => $r['from'], 'to' => $r['to'], 'rows' => $rows, 'totals' => $sum, 'truncated' => count($rows) >= self::MAX_ROWS];
    }

    /**
     * One row per employee per shift day. A shift day with no entry is
     * "absent" — which can only happen for a day somebody assigned, because a
     * default shift is attached only when the employee arrives (D4).
     *
     * @param list<int>|null $scope
     * @return list<array<string,mixed>>
     */
    private static function attendanceRows(string $from, string $to, ?array $scope, ?int $dept, ?string $employee): array
    {
        [$dw, $dp] = self::deptWhere($scope, 'e.department_id');
        if ($dw === false) {
            return [];
        }
        $where = 'si.work_date BETWEEN ? AND ?' . $dw;
        $p = array_merge([$from, $to], $dp);
        if ($dept !== null) { $where .= ' AND e.department_id = ?'; $p[] = $dept; }
        if ($employee !== null && $employee !== '') {
            $where .= ' AND (e.code = ? OR e.full_name LIKE ?)';
            $p[] = $employee; $p[] = '%' . addcslashes($employee, '\\%_') . '%';
        }
        $ot = Db::tableExists('att_ot_request_employees')
            ? ", (SELECT l.actual_minutes FROM att_ot_request_employees l WHERE l.employee_id = si.employee_id AND l.work_date = si.work_date AND l.approval_status = 'approved' LIMIT 1) AS ot_actual_minutes,
                 (SELECT l.approved_minutes FROM att_ot_request_employees l WHERE l.employee_id = si.employee_id AND l.work_date = si.work_date AND l.approval_status = 'approved' LIMIT 1) AS ot_approved_minutes"
            : ', NULL AS ot_actual_minutes, NULL AS ot_approved_minutes';
        $rows = Db::all(
            "SELECT si.id AS shift_instance_id, si.work_date, si.starts_at, si.ends_at, si.source AS shift_source,
                    s.name AS shift_name, s.grace_in_minutes, s.grace_out_minutes,
                    e.id AS employee_id, e.code AS employee_code, e.full_name, d.name AS department_name,
                    COUNT(c.id) AS entries, MIN(c.opened_at) AS first_in, MAX(c.closed_at) AS last_out,
                    SUM(CASE WHEN c.id IS NOT NULL AND c.closed_at IS NULL THEN 1 ELSE 0 END) AS open_entries,
                    COALESCE(SUM(c.present_minutes), 0) AS present_minutes,
                    SUM(CASE WHEN c.id IS NOT NULL AND h.id IS NULL THEN 1 ELSE 0 END) AS unconfirmed,
                    SUM(CASE WHEN c.close_source = 'hr_correction' THEN 1 ELSE 0 END) AS corrected,
                    SUM(CASE WHEN c.close_source = 'auto_shift_end' THEN 1 ELSE 0 END) AS auto_out
                    $ot
               FROM att_shift_instances si
               JOIN att_shifts s ON s.id = si.shift_id
               JOIN att_employees e ON e.id = si.employee_id
               JOIN att_departments d ON d.id = e.department_id
               LEFT JOIN att_entry_cycles c ON c.shift_instance_id = si.id
               LEFT JOIN att_hod_arrivals h ON h.entry_cycle_id = c.id AND h.status = 'confirmed'
              WHERE $where
              GROUP BY si.id
              ORDER BY si.work_date, d.name, e.full_name
              LIMIT " . self::MAX_ROWS, $p);

        $utc = new DateTimeZone('UTC');
        foreach ($rows as &$r) {
            $start = new DateTimeImmutable((string) $r['starts_at'], $utc);
            $end   = new DateTimeImmutable((string) $r['ends_at'], $utc);
            $r['entries'] = (int) $r['entries'];
            $r['open_entries'] = (int) $r['open_entries'];
            $r['present_minutes'] = (int) $r['present_minutes'];
            $r['unconfirmed'] = (int) $r['unconfirmed'];
            $r['corrected'] = (int) $r['corrected'];
            $r['auto_out'] = (int) $r['auto_out'];       // entries closed by the system: no OUT was scanned
            $r['late_minutes'] = 0;
            $r['early_minutes'] = 0;
            if ($r['entries'] === 0) {
                $r['status'] = 'absent';
            } else {
                $r['status'] = $r['open_entries'] > 0 ? 'inside' : 'present';
                $first = new DateTimeImmutable((string) $r['first_in'], $utc);
                $limit = $start->modify('+' . (int) $r['grace_in_minutes'] . ' minutes');
                if ($first > $limit) {
                    $r['late_minutes'] = intdiv($first->getTimestamp() - $start->getTimestamp(), 60);
                }
                if ($r['open_entries'] === 0 && $r['last_out'] !== null) {
                    $last = new DateTimeImmutable((string) $r['last_out'], $utc);
                    $okFrom = $end->modify('-' . (int) $r['grace_out_minutes'] . ' minutes');
                    if ($last < $okFrom) {
                        $r['early_minutes'] = intdiv($end->getTimestamp() - $last->getTimestamp(), 60);
                    }
                }
            }
            foreach (['starts_at', 'ends_at', 'first_in', 'last_out'] as $k) {
                $r[$k] = $r[$k] !== null ? Time::iso(new DateTimeImmutable((string) $r[$k], $utc)) : null;
            }
            $r['ot_actual_minutes'] = $r['ot_actual_minutes'] !== null ? (int) $r['ot_actual_minutes'] : null;
            $r['ot_approved_minutes'] = $r['ot_approved_minutes'] !== null ? (int) $r['ot_approved_minutes'] : null;
        }
        unset($r);
        return $rows;
    }

    // ============================================================== movements

    /** @param array<string,mixed> $f @param list<int>|null $scope */
    public static function movements(array $f, ?array $scope): array
    {
        $r = self::period($f);
        if (!$r['ok']) {
            return $r;
        }
        [$dw, $dp] = self::deptWhere($scope, 'e.department_id');
        if ($dw === false) {
            return ['ok' => true, 'from' => $r['from'], 'to' => $r['to'], 'rows' => []];
        }
        [$a, $b] = self::utcRange($r['from'], $r['to']);
        $where = 'ev.occurred_at >= ? AND ev.occurred_at < ?' . $dw;
        $p = array_merge([$a, $b], $dp);
        if ((int) ($f['department_id'] ?? 0) > 0) { $where .= ' AND e.department_id = ?'; $p[] = (int) $f['department_id']; }
        $emp = self::str($f['employee'] ?? '');
        if ($emp !== '') { $where .= ' AND (e.code = ? OR e.full_name LIKE ?)'; $p[] = $emp; $p[] = '%' . addcslashes($emp, '\\%_') . '%'; }
        $users = Db::tableExists('users');
        $rows = Db::all(
            "SELECT ev.event_type, ev.occurred_at, ev.received_at, ev.source, ev.reconciliation_status,
                    e.code AS employee_code, e.full_name, d.name AS department_name,
                    dv.label AS phone, " . ($users ? 'u.name' : 'NULL') . " AS recorded_by,
                    COALESCE(ci.entry_sequence, co.entry_sequence) AS entry_sequence,
                    h.occurred_at AS hod_confirmed_at, " . ($users ? 'hu.name' : 'NULL') . " AS hod_name
               FROM att_events ev
               JOIN att_employees e ON e.id = ev.employee_id
               JOIN att_departments d ON d.id = e.department_id
               LEFT JOIN att_devices dv ON dv.id = ev.device_id
               LEFT JOIN att_entry_cycles ci ON ci.gate_in_event_id = ev.id
               LEFT JOIN att_entry_cycles co ON co.gate_out_event_id = ev.id
               LEFT JOIN att_hod_arrivals h ON h.entry_cycle_id = ci.id AND h.status = 'confirmed' "
            . ($users ? 'LEFT JOIN users u ON u.id = ev.operator_id LEFT JOIN users hu ON hu.id = h.hod_user_id ' : '') . "
              WHERE $where
              ORDER BY ev.occurred_at, ev.id LIMIT " . self::MAX_ROWS, $p);
        /* Exits nobody scanned: the system's OUT, as its own row, so the list
           of movements says plainly where the gate saw nothing. */
        $aw = 'c.close_source = \'auto_shift_end\' AND c.closed_at >= ? AND c.closed_at < ?' . $dw;
        $ap = array_merge([$a, $b], $dp);
        if ((int) ($f['department_id'] ?? 0) > 0) { $aw .= ' AND e.department_id = ?'; $ap[] = (int) $f['department_id']; }
        if ($emp !== '') { $aw .= ' AND (e.code = ? OR e.full_name LIKE ?)'; $ap[] = $emp; $ap[] = '%' . addcslashes($emp, '\\%_') . '%'; }
        $autoRows = Db::all(
            "SELECT 'AUTO_OUT' AS event_type, c.closed_at AS occurred_at, c.auto_closed_at AS received_at, 'system' AS source, 'accepted' AS reconciliation_status,
                    e.code AS employee_code, e.full_name, d.name AS department_name,
                    NULL AS phone, 'System — no OUT scanned' AS recorded_by, c.entry_sequence, NULL AS hod_confirmed_at, NULL AS hod_name
               FROM att_entry_cycles c
               JOIN att_employees e ON e.id = c.employee_id
               JOIN att_departments d ON d.id = e.department_id
              WHERE $aw LIMIT " . self::MAX_ROWS, $ap);
        if ($autoRows !== []) {
            $rows = array_merge($rows, $autoRows);
            usort($rows, fn($x, $y) => strcmp((string) $x['occurred_at'], (string) $y['occurred_at']));
        }
        $utc = new DateTimeZone('UTC');
        foreach ($rows as &$x) {
            foreach (['occurred_at', 'received_at', 'hod_confirmed_at'] as $k) {
                $x[$k] = $x[$k] !== null ? Time::iso(new DateTimeImmutable((string) $x[$k], $utc)) : null;
            }
        }
        unset($x);
        return ['ok' => true, 'from' => $r['from'], 'to' => $r['to'], 'rows' => $rows, 'truncated' => count($rows) >= self::MAX_ROWS];
    }

    // =============================================================== overtime

    /** @param array<string,mixed> $f @param list<int>|null $scope */
    public static function overtime(array $f, ?array $scope): array
    {
        $r = self::period($f);
        if (!$r['ok']) {
            return $r;
        }
        if (!Db::tableExists('att_ot_request_employees')) {
            return ['ok' => true, 'from' => $r['from'], 'to' => $r['to'], 'rows' => [], 'by_department' => []];
        }
        [$dw, $dp] = self::deptWhere($scope, 'q.department_id');
        if ($dw === false) {
            return ['ok' => true, 'from' => $r['from'], 'to' => $r['to'], 'rows' => [], 'by_department' => []];
        }
        $where = 'l.work_date BETWEEN ? AND ?' . $dw;
        $p = array_merge([$r['from'], $r['to']], $dp);
        if ((int) ($f['department_id'] ?? 0) > 0) { $where .= ' AND q.department_id = ?'; $p[] = (int) $f['department_id']; }
        $users = Db::tableExists('users');
        $rows = Db::all(
            "SELECT l.work_date, d.name AS department_name, e.code AS employee_code, e.full_name, q.reason,
                    l.estimated_minutes, l.approval_status, l.approved_minutes, l.verification_status, l.actual_minutes,
                    p.rate_type, p.manual_rate, COALESCE(p.effective_amount, p.payable_amount) AS amount, COALESCE(p.status, 'none') AS payroll_status,
                    " . ($users ? 'ru.name' : 'NULL') . " AS requested_by, " . ($users ? 'du.name' : 'NULL') . " AS decided_by, " . ($users ? 'vu.name' : 'NULL') . " AS verified_by
               FROM att_ot_request_employees l
               JOIN att_ot_requests q ON q.id = l.request_id
               JOIN att_departments d ON d.id = q.department_id
               JOIN att_employees e ON e.id = l.employee_id
               LEFT JOIN att_ot_payroll p ON p.request_employee_id = l.id "
            . ($users ? 'LEFT JOIN users ru ON ru.id = q.requested_by LEFT JOIN users du ON du.id = l.decided_by LEFT JOIN users vu ON vu.id = l.verified_by ' : '') . "
              WHERE $where ORDER BY l.work_date, d.name, e.full_name LIMIT " . self::MAX_ROWS, $p);
        $by = [];
        foreach ($rows as $x) {
            $k = (string) $x['department_name'];
            $by[$k] = $by[$k] ?? ['department_name' => $k, 'requested' => 0, 'approved' => 0, 'rejected' => 0, 'approved_minutes' => 0, 'actual_minutes' => 0, 'finalized_amount' => 0, 'draft_amount' => 0];
            $by[$k]['requested']++;
            if ($x['approval_status'] === 'approved') { $by[$k]['approved']++; $by[$k]['approved_minutes'] += (int) $x['approved_minutes']; }
            if ($x['approval_status'] === 'rejected') { $by[$k]['rejected']++; }
            if ($x['verification_status'] === 'verified') { $by[$k]['actual_minutes'] += (int) $x['actual_minutes']; }
            $c = OvertimeService::cents((string) ($x['amount'] ?? '0')) ?? 0;
            if ($x['payroll_status'] === 'finalized') { $by[$k]['finalized_amount'] += $c; }
            if ($x['payroll_status'] === 'draft') { $by[$k]['draft_amount'] += $c; }
        }
        foreach ($by as &$b) {
            $b['finalized_amount'] = OvertimeService::money($b['finalized_amount']);
            $b['draft_amount'] = OvertimeService::money($b['draft_amount']);
        }
        unset($b);
        return ['ok' => true, 'from' => $r['from'], 'to' => $r['to'], 'rows' => $rows, 'by_department' => array_values($by), 'truncated' => count($rows) >= self::MAX_ROWS];
    }

    // ================================================================ helpers

    /** @param array<string,mixed> $f @return array{ok:true,from:string,to:string}|array{ok:false,reason_code:string,error:string} */
    private static function period(array $f): array
    {
        $from = self::date(self::str($f['from'] ?? ''));
        $to   = self::date(self::str($f['to'] ?? ''));
        if ($from === null || $to === null) {
            return ['ok' => false, 'reason_code' => 'VALIDATION', 'error' => 'Give the first and last day.'];
        }
        if ($to < $from) {
            return ['ok' => false, 'reason_code' => 'VALIDATION', 'error' => 'The last day is before the first day.'];
        }
        if ((int) $from->diff($to)->days + 1 > self::MAX_DAYS) {
            return ['ok' => false, 'reason_code' => 'VALIDATION', 'error' => 'At most ' . self::MAX_DAYS . ' days at a time.'];
        }
        return ['ok' => true, 'from' => $from->format('Y-m-d'), 'to' => $to->format('Y-m-d')];
    }

    /** Factory days $from..$to as a UTC half-open range. @return array{0:string,1:string} */
    private static function utcRange(string $from, string $to): array
    {
        $tz = new DateTimeZone(self::TZ);
        $utc = new DateTimeZone('UTC');
        $a = (new DateTimeImmutable($from . ' 00:00:00', $tz))->setTimezone($utc);
        $b = (new DateTimeImmutable($to . ' 00:00:00', $tz))->modify('+1 day')->setTimezone($utc);
        return [Time::sql($a), Time::sql($b)];
    }

    /**
     * @param list<int>|null $scope
     * @return array{0:string|false,1:list<int>} false = scoped to nothing
     */
    private static function deptWhere(?array $scope, string $col): array
    {
        if ($scope === null) {
            return ['', []];
        }
        if ($scope === []) {
            return [false, []];
        }
        $ids = array_values(array_map('intval', $scope));
        return [' AND ' . $col . ' IN (' . implode(',', array_fill(0, count($ids), '?')) . ')', $ids];
    }

    private static function str(mixed $v): string
    {
        return is_scalar($v) ? trim((string) $v) : '';
    }

    private static function date(string $d): ?DateTimeImmutable
    {
        if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $d)) {
            return null;
        }
        $x = DateTimeImmutable::createFromFormat('!Y-m-d', $d, new DateTimeZone('UTC'));
        return ($x !== false && $x->format('Y-m-d') === $d) ? $x : null;
    }
}
