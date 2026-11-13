<?php
declare(strict_types=1);

namespace Att;

use DateTimeImmutable;
use DateTimeZone;

/**
 * People Finance — what each employee is owed, and paying it.
 *
 * THREE KINDS OF PAY, ONE PIPELINE.
 *   salary    fixed monthly pay, prepared at month end (custom dates give
 *             salary / days in the month x days)
 *   daily     days present (from attendance) x daily rate, prepared weekly
 *   contract  whatever production has booked and not yet paid
 *
 * Every payment is a PAY SHEET that is prepared, then approved, then paid:
 *
 *   prepare   the system works out each person's LINES; the preparer may amend
 *             any line (except basic salary) and add bonus / deduction lines.
 *             A changed line needs a reason and keeps the worked-out figure.
 *   approve   Admin or Audit. They may amend again. Nobody but an admin
 *             approves a sheet they prepared. Approval posts the earnings and
 *             deductions to the ledger and recovers advances.
 *   pay       Accounts or Admin record the payment. A day that is not the pay
 *             day needs a reason.
 *
 * THE SERVER WORKS OUT EVERY FIGURE ITSELF. A browser sends only which people,
 * and which lines it wants changed to what and why. It never sends a
 * "worked out" figure, a total, a role or a user id.
 *
 * THE LEDGER IS THE BALANCE: opening + earnings - deductions - payments, over
 * lines that are not cancelled. For contractors the earnings and the old
 * payments are copied in from production (zp_entries, zp_pay) each time,
 * idempotently, so production keeps working exactly as it does.
 *
 * Money is whole paisa (int). Amounts cross the API as paisa too.
 */
final class FinanceService
{
    public const KINDS = ['salary', 'daily', 'contract'];
    private const TZ = 'Asia/Karachi';
    /** Lines a person may add to someone's pay. */
    private const ADDABLE = ['bonus' => 'earn', 'other_earning' => 'earn', 'deduction' => 'deduct'];
    /** Worked-out lines that cannot be changed on a sheet: change salary setup instead. */
    private const FIXED = ['basic'];

    private static ?string $today = null;

    /** Tests only. */
    public static function setToday(?string $ymd): void
    {
        self::$today = $ymd;
    }

    public static function today(): string
    {
        return self::$today ?? (new DateTimeImmutable('now', new DateTimeZone(self::TZ)))->format('Y-m-d');
    }

    // ------------------------------------------------------------ who may

    /**
     * What this user may do. A job given on Setup › People is enough by
     * itself; the User Access ticks (finprep, finwage, finapp, finpay) work
     * too, for somebody who should have one power without the whole job.
     *
     *   departments       whose people this user may SEE (null = everyone)
     *   wage_departments  whose daily wages / contractor pay they may PREPARE
     *
     * @return array{salary:bool,wage:bool,approve:bool,pay:bool,setup:bool,any:bool,admin:bool,see_salary:bool,departments:?list<int>,wage_departments:?list<int>}
     */
    public static function caps(int $userId): array
    {
        $admin = Roles::isAdmin($userId);
        $job = static fn(string ...$roles) => (bool) array_filter($roles, static fn($r) => Roles::has($userId, $r));
        // A tick in User Access counts only for the person who is signed in:
        // zu_can() answers for the session, never for an id a caller passes.
        $tick = static function (string $module, string $action) use ($userId): bool {
            if (!function_exists('zu_can') || !function_exists('current_user')) {
                return false;
            }
            $u = \current_user();
            return is_array($u) && (int) ($u['id'] ?? 0) === $userId && \zu_can($module, $action);
        };
        $salary  = $admin || $tick('finprep', 'c') || $job(Roles::AUDIT, Roles::ACCOUNTS, Roles::HR);
        $office  = $admin || $tick('finwage', 'c') || $job(Roles::ACCOUNTS);
        $hodOf   = $admin ? [] : (Roles::departmentScope($userId, Roles::HOD) ?? []);
        $approve = $admin || $tick('finapp', 'u') || $job(Roles::AUDIT);
        $pay     = $admin || $tick('finpay', 'u') || $job(Roles::ACCOUNTS);
        $setup   = $admin || $tick('finprep', 'u') || $job(Roles::ACCOUNTS, Roles::HR);
        // Someone ticked "View" only may look at everything and change nothing.
        $view    = $tick('finprep', 'v') || $tick('finwage', 'v') || $tick('finapp', 'v') || $tick('finpay', 'v');
        $wide    = $salary || $office || $approve || $pay || $setup || $view;
        $caps = [
            'salary' => $salary, 'wage' => $office || $hodOf !== [], 'approve' => $approve, 'pay' => $pay, 'setup' => $setup,
            'any' => $wide || $hodOf !== [], 'admin' => $admin,
            // A HOD prepares wages for their own people and never sees a salary.
            'see_salary' => $wide,
            'departments' => $wide ? null : $hodOf,
            'wage_departments' => $office ? null : $hodOf,
        ];
        // The ERP's access hours apply here as everywhere: outside them a
        // person may look (if their setting says so) but change nothing.
        $window = self::windowState($userId);
        if (!$admin && $window !== 'yes') {
            foreach (['salary', 'wage', 'approve', 'pay', 'setup'] as $k) { $caps[$k] = false; }
            if ($window !== 'ro') { $caps['any'] = false; }
        }
        return $caps;
    }

    private static function windowState(int $userId): string
    {
        if (!function_exists('zu_window_state') || !function_exists('current_user')) {
            return 'yes';
        }
        $u = \current_user();
        return is_array($u) && (int) ($u['id'] ?? 0) === $userId ? (string) \zu_window_state() : 'yes';
    }

    // ------------------------------------------------------- salary setup

    /** @return array<string,mixed> */
    public static function people(array $caps): array
    {
        self::syncProduction();
        [$w, $p] = self::deptWhere($caps['departments']);
        $rows = Db::all(
            "SELECT e.id AS employee_id, e.code, e.full_name, e.active, d.name AS department_name, e.department_id, e.worker_code,
                    (e.reference_photo_key IS NOT NULL) AS has_photo,
                    f.pay_type, f.basic_paisa, f.allowance_paisa, f.daily_rate_paisa, f.ot_rate_paisa, f.pay_method, f.bank_account
               FROM att_employees e
               JOIN att_departments d ON d.id = e.department_id
               LEFT JOIN att_fin_profiles f ON f.employee_id = e.id
              WHERE (e.active = 1 OR f.employee_id IS NOT NULL) $w
              ORDER BY e.full_name LIMIT 5000", $p);
        $bal = self::balances();
        $onSheet = array_flip(array_map('intval', array_column(Db::all(
            "SELECT DISTINCT i.employee_id FROM att_fin_sheet_items i JOIN att_fin_sheets s ON s.id = i.sheet_id WHERE s.status IN ('waiting','approved')"), 'employee_id')));
        foreach ($rows as &$r) {
            $id = (int) $r['employee_id'];
            foreach (['basic_paisa', 'allowance_paisa', 'daily_rate_paisa', 'ot_rate_paisa'] as $k) {
                $r[$k] = $r[$k] === null ? null : (int) $r[$k];
            }
            $b = $bal[$id] ?? ['earned' => 0, 'deducted' => 0, 'paid' => 0, 'balance' => 0];
            $r += $b;
            $r['on_sheet'] = isset($onSheet[$id]);
            if (!$caps['see_salary'] && $r['pay_type'] === 'salary') {
                // Not for a HOD's eyes: the person is listed, the pay is not.
                $r['basic_paisa'] = $r['allowance_paisa'] = null;
                $r['earned'] = $r['deducted'] = $r['paid'] = $r['balance'] = null;
            }
        }
        unset($r);
        return ['ok' => true, 'people' => $rows];
    }

    /** @param array<string,mixed> $in */
    public static function saveProfile(int $employeeId, array $in, string $reason, int $actorId): array
    {
        $emp = Db::one('SELECT id, full_name FROM att_employees WHERE id = ?', [$employeeId]);
        if ($emp === null) {
            return self::no('NOT_FOUND', 'No such employee.');
        }
        $type = (string) ($in['pay_type'] ?? '');
        if (!in_array($type, self::KINDS, true)) {
            return self::no('VALIDATION', 'Choose how this person is paid: fixed salary, daily wages or contractor.');
        }
        $money = [];
        foreach (['basic_paisa', 'allowance_paisa', 'daily_rate_paisa', 'ot_rate_paisa'] as $k) {
            $v = $in[$k] ?? 0;
            if (!self::whole($v) || (int) $v < 0 || (int) $v > 100000000000) {
                return self::no('VALIDATION', 'Amounts must be whole numbers, zero or more.');
            }
            $money[$k] = (int) $v;
        }
        if ($type === 'salary' && $money['basic_paisa'] <= 0) {
            return self::no('VALIDATION', 'A fixed salary needs the monthly salary.');
        }
        if ($type === 'daily' && $money['daily_rate_paisa'] <= 0) {
            return self::no('VALIDATION', 'Daily wages need the rate per day.');
        }
        $method = ($in['pay_method'] ?? 'cash') === 'bank' ? 'bank' : 'cash';
        $bank = mb_substr(trim((string) ($in['bank_account'] ?? '')), 0, 60) ?: null;
        return Db::tx(static function () use ($employeeId, $type, $money, $method, $bank, $reason, $actorId) {
            $old = Db::one('SELECT * FROM att_fin_profiles WHERE employee_id = ? FOR UPDATE', [$employeeId]);
            $new = ['pay_type' => $type] + $money + ['pay_method' => $method, 'bank_account' => $bank];
            if ($old !== null && trim($reason) === '') {
                foreach ($new as $k => $v) {
                    if ((string) $old[$k] !== (string) $v) {
                        return self::no('REASON_REQUIRED', 'Say why the pay is changing — the reason is kept with the change.');
                    }
                }
            }
            Db::run(
                'INSERT INTO att_fin_profiles (employee_id, pay_type, basic_paisa, allowance_paisa, daily_rate_paisa, ot_rate_paisa, pay_method, bank_account, updated_by)
                 VALUES (?,?,?,?,?,?,?,?,?)
                 ON DUPLICATE KEY UPDATE pay_type = VALUES(pay_type), basic_paisa = VALUES(basic_paisa), allowance_paisa = VALUES(allowance_paisa),
                     daily_rate_paisa = VALUES(daily_rate_paisa), ot_rate_paisa = VALUES(ot_rate_paisa), pay_method = VALUES(pay_method),
                     bank_account = VALUES(bank_account), updated_by = VALUES(updated_by), updated_at = CURRENT_TIMESTAMP(6)',
                [$employeeId, $type, $money['basic_paisa'], $money['allowance_paisa'], $money['daily_rate_paisa'], $money['ot_rate_paisa'], $method, $bank, $actorId]
            );
            Db::run('INSERT INTO att_fin_profile_history (employee_id, old_data, new_data, reason, actor_id) VALUES (?,?,?,?,?)',
                [$employeeId, $old === null ? null : json_encode($old), json_encode($new), trim($reason) ?: null, $actorId]);
            Audit::record('fin.profile_save', 'att_employee', $employeeId, $old, $new, $actorId, trim($reason) ?: null);
            return ['ok' => true, 'employee_id' => $employeeId];
        });
    }

    /** What is owed today when the system starts being used. One per person; replaced, never added twice. */
    public static function setOpening(int $employeeId, int $paisa, string $reason, int $actorId): array
    {
        if (Db::one('SELECT id FROM att_employees WHERE id = ?', [$employeeId]) === null) {
            return self::no('NOT_FOUND', 'No such employee.');
        }
        if (trim($reason) === '') {
            return self::no('REASON_REQUIRED', 'Say where this opening balance comes from.');
        }
        $key = 'open:' . $employeeId;
        if ($paisa < 0) {
            return self::no('VALIDATION', 'An opening balance is what the company owes the person: zero or more. Money the person owes is an advance.');
        }
        Db::run(
            "INSERT INTO att_fin_ledger (employee_id, entry_date, kind, label, amount_paisa, source, source_key, created_by)
             VALUES (?, ?, 'opening', ?, ?, 'manual', ?, ?)
             ON DUPLICATE KEY UPDATE amount_paisa = VALUES(amount_paisa), label = VALUES(label), created_by = VALUES(created_by)",
            [$employeeId, self::today(), 'Opening balance · ' . mb_substr(trim($reason), 0, 150), $paisa, $key, $actorId]
        );
        Audit::record('fin.opening', 'att_employee', $employeeId, null, ['paisa' => $paisa], $actorId, trim($reason));
        return ['ok' => true];
    }

    // ------------------------------------------------------------- ledger

    /** [employee_id => earned, deducted, paid, balance] in paisa, cancelled lines left out. */
    public static function balances(?int $employeeId = null): array
    {
        $rows = Db::all(
            "SELECT employee_id,
                    SUM(CASE WHEN kind IN ('opening','earning') THEN amount_paisa ELSE 0 END) AS earned,
                    SUM(CASE WHEN kind = 'deduction' THEN amount_paisa ELSE 0 END) AS deducted,
                    SUM(CASE WHEN kind = 'payment' THEN amount_paisa ELSE 0 END) AS paid
               FROM att_fin_ledger WHERE cancelled_at IS NULL" . ($employeeId !== null ? ' AND employee_id = ?' : '') . ' GROUP BY employee_id',
            $employeeId !== null ? [$employeeId] : []
        );
        $out = [];
        foreach ($rows as $r) {
            $e = (int) $r['earned']; $d = (int) $r['deducted']; $p = (int) $r['paid'];
            $out[(int) $r['employee_id']] = ['earned' => $e, 'deducted' => $d, 'paid' => $p, 'balance' => $e - $d - $p];
        }
        return $out;
    }

    public static function balance(int $employeeId): int
    {
        return self::balances($employeeId)[$employeeId]['balance'] ?? 0;
    }

    /** One person's statement, oldest first, with the running balance. */
    public static function ledger(int $employeeId, array $caps): array
    {
        self::syncProduction();
        $e = Db::one('SELECT e.id, e.code, e.full_name, e.department_id, d.name AS department_name, f.pay_type
                        FROM att_employees e JOIN att_departments d ON d.id = e.department_id
                        LEFT JOIN att_fin_profiles f ON f.employee_id = e.id WHERE e.id = ?', [$employeeId]);
        if ($e === null || !self::inScope($caps['departments'], (int) $e['department_id']) || (!$caps['see_salary'] && $e['pay_type'] === 'salary')) {
            return self::no('NOT_FOUND', 'No such employee.');
        }
        $lines = Db::all(
            'SELECT l.id, l.entry_date, l.kind, l.line_kind, l.label, l.amount_paisa, l.method, l.source, l.cancelled_at, l.cancel_reason, s.ref AS sheet_ref
               FROM att_fin_ledger l LEFT JOIN att_fin_sheets s ON s.id = l.sheet_id
              WHERE l.employee_id = ? ORDER BY l.entry_date, l.id LIMIT 3000', [$employeeId]);
        $bal = 0;
        foreach ($lines as &$l) {
            $l['amount_paisa'] = (int) $l['amount_paisa'];
            if ($l['cancelled_at'] === null) {
                $bal += in_array($l['kind'], ['opening', 'earning'], true) ? $l['amount_paisa'] : -$l['amount_paisa'];
            }
            $l['balance_paisa'] = $bal;
        }
        unset($l);
        return ['ok' => true, 'employee' => $e, 'lines' => $lines, 'balance_paisa' => $bal,
                'advances' => self::advancesFor($employeeId)];
    }

    /**
     * Contractors: copy what production has booked, and the payments made on
     * the old Worker Pay screen, into the ledger. Safe to run any number of
     * times: each day's production and each old payment has one line, kept in
     * step (a cancelled production entry lowers that day's line; a cancelled
     * old payment cancels its line).
     */
    public static function syncProduction(bool $force = false): void
    {
        if (!Db::tableExists('zp_workers') || !Db::tableExists('zp_entries')) {
            return;
        }
        $map = self::contractWorkers();
        $hasPay = Db::tableExists('zp_pay');
        // Nothing booked, paid, cancelled or linked since last time? Then there is nothing to copy.
        $mark = md5(json_encode($map)
            . json_encode(Db::one("SELECT COUNT(*) AS c, COALESCE(SUM(CRC32(CONCAT_WS(':', id, worker_id, entry_date, amount, status))), 0) AS h FROM zp_entries"))
            . ($hasPay ? json_encode(Db::one("SELECT COUNT(*) AS c, COALESCE(SUM(CRC32(CONCAT_WS(':', id, worker_id, pay_date, amount, status, COALESCE(note, '')))), 0) AS h FROM zp_pay")) : ''));
        if (!$force && $mark === (string) Db::value("SELECT value FROM att_policies WHERE policy_key = 'fin_sync_mark'")) {
            return;
        }
        foreach ($map as $emp => $wid) {
            Db::run(
                "INSERT INTO att_fin_ledger (employee_id, entry_date, kind, line_kind, label, amount_paisa, source, source_key)
                 SELECT ?, z.entry_date, 'earning', 'production', 'Production', ROUND(SUM(z.amount) * 100), 'production', CONCAT('zpe:', ?, ':', z.entry_date)
                   FROM zp_entries z WHERE z.worker_id = ? AND z.status = 'active' GROUP BY z.entry_date
                 ON DUPLICATE KEY UPDATE amount_paisa = VALUES(amount_paisa), cancelled_at = NULL",
                [$emp, $wid, $wid]
            );
            // A day whose every entry was cancelled has no row above: bring its line to zero.
            Db::run(
                "UPDATE att_fin_ledger l SET l.amount_paisa = 0
                  WHERE l.employee_id = ? AND l.source = 'production' AND l.amount_paisa <> 0
                    AND NOT EXISTS (SELECT 1 FROM zp_entries z WHERE z.worker_id = ? AND z.status = 'active' AND z.entry_date = l.entry_date)",
                [$emp, $wid]
            );
            if ($hasPay) {
                foreach (Db::all('SELECT id, pay_date, ROUND(amount * 100) AS paisa, status, note FROM zp_pay WHERE worker_id = ?', [$wid]) as $pay) {
                    $dead = $pay['status'] !== 'active';
                    Db::run(
                        "INSERT INTO att_fin_ledger (employee_id, entry_date, kind, label, amount_paisa, method, source, source_key, cancelled_at, cancel_reason)
                         VALUES (?, ?, 'payment', ?, ?, 'cash', 'legacy_pay', ?, ?, ?)
                         ON DUPLICATE KEY UPDATE amount_paisa = VALUES(amount_paisa),
                             cancelled_at = IF(VALUES(cancelled_at) IS NULL, NULL, COALESCE(cancelled_at, VALUES(cancelled_at))), cancel_reason = VALUES(cancel_reason)",
                        [$emp, $pay['pay_date'], 'Paid on Worker Pay' . ($pay['note'] ? ' · ' . mb_substr((string) $pay['note'], 0, 120) : ''), (int) $pay['paisa'],
                         'zpp:' . (int) $pay['id'], $dead ? Time::sql() : null, $dead ? 'Cancelled on Worker Pay' : null]
                    );
                }
            }
        }
        Db::run("INSERT INTO att_policies (policy_key, value, value_type, description) VALUES ('fin_sync_mark', ?, 'string', 'People Finance: fingerprint of the production data last copied in')
                 ON DUPLICATE KEY UPDATE value = VALUES(value)", [$mark]);
    }

    /**
     * Contractors and their Production worker: [employee_id => zp_workers.id],
     * matched on the code (the employee's worker code, or else their own code).
     *
     * MATCHED IN PHP, NOT IN SQL. The production tables were made by the ERP
     * with whatever collation the server had that day; joining their text to
     * an attendance column can fail with "Illegal mix of collations" on one
     * server and work on another.
     *
     * @return array<int,int>
     */
    private static function contractWorkers(): array
    {
        $byCode = [];
        foreach (Db::all('SELECT id, worker_code FROM zp_workers') as $w) {
            $byCode[mb_strtolower(trim((string) $w['worker_code']))] = (int) $w['id'];
        }
        $out = [];
        foreach (Db::all("SELECT e.id, e.code, e.worker_code FROM att_fin_profiles f JOIN att_employees e ON e.id = f.employee_id WHERE f.pay_type = 'contract'") as $e) {
            $code = mb_strtolower(trim((string) ($e['worker_code'] ?? '') !== '' ? (string) $e['worker_code'] : (string) $e['code']));
            if (isset($byCode[$code])) {
                $out[(int) $e['id']] = $byCode[$code];
            }
        }
        return $out;
    }

    /**
     * Production workers nobody can pay here yet: active on Production ›
     * Workers, but with no contractor set up under the same code. They stay
     * on the old Worker Pay screen until they are linked.
     *
     * @return list<array{id:int,code:string,name:string}>
     */
    public static function unlinkedWorkers(): array
    {
        if (!Db::tableExists('zp_workers')) {
            return [];
        }
        $linked = array_flip(self::contractWorkers());
        $out = [];
        foreach (Db::all('SELECT id, worker_code, worker_name FROM zp_workers WHERE is_active = 1 ORDER BY worker_name') as $w) {
            if (!isset($linked[(int) $w['id']])) {
                $out[] = ['id' => (int) $w['id'], 'code' => (string) $w['worker_code'], 'name' => (string) $w['worker_name']];
            }
        }
        return $out;
    }

    /** Production worker ids that are paid through People Finance now. @return list<int> */
    public static function linkedWorkerIds(): array
    {
        return Db::tableExists('zp_workers') ? array_values(self::contractWorkers()) : [];
    }

    // ----------------------------------------------------------- advances

    public static function advancesFor(int $employeeId): array
    {
        $rows = Db::all("SELECT id, kind, amount_paisa, per_pay_paisa, recovered_paisa, given_on, method, note, status FROM att_fin_advances
                          WHERE employee_id = ? AND status <> 'cancelled' ORDER BY id", [$employeeId]);
        foreach ($rows as &$r) {
            foreach (['amount_paisa', 'per_pay_paisa', 'recovered_paisa'] as $k) { $r[$k] = (int) $r[$k]; }
            $r['left_paisa'] = $r['amount_paisa'] - $r['recovered_paisa'];
        }
        unset($r);
        return $rows;
    }

    /** Every advance and loan this user may see, newest first. */
    public static function advances(array $caps): array
    {
        [$w, $p] = self::deptWhere($caps['departments']);
        $rows = Db::all(
            "SELECT a.id, a.employee_id, a.kind, a.amount_paisa, a.per_pay_paisa, a.recovered_paisa, a.given_on, a.method, a.note, a.status,
                    e.code, e.full_name, d.name AS department_name, f.pay_type
               FROM att_fin_advances a JOIN att_employees e ON e.id = a.employee_id JOIN att_departments d ON d.id = e.department_id
               LEFT JOIN att_fin_profiles f ON f.employee_id = e.id
              WHERE a.status <> 'cancelled' $w" . ($caps['see_salary'] ? '' : " AND COALESCE(f.pay_type, '') <> 'salary'") . '
              ORDER BY (a.status = \'active\') DESC, a.id DESC LIMIT 2000', $p);
        foreach ($rows as &$r) {
            foreach (['amount_paisa', 'per_pay_paisa', 'recovered_paisa'] as $k) { $r[$k] = (int) $r[$k]; }
            $r['left_paisa'] = $r['amount_paisa'] - $r['recovered_paisa'];
        }
        unset($r);
        return ['ok' => true, 'advances' => $rows];
    }

    /** @param array<string,mixed> $in */
    public static function giveAdvance(array $in, int $actorId): array
    {
        $emp = (int) ($in['employee_id'] ?? 0);
        if (Db::one('SELECT id FROM att_employees WHERE id = ?', [$emp]) === null) {
            return self::no('NOT_FOUND', 'No such employee.');
        }
        $amt = $in['amount_paisa'] ?? null; $per = $in['per_pay_paisa'] ?? null;
        if (!self::whole($amt) || (int) $amt <= 0) {
            return self::no('VALIDATION', 'Give the amount.');
        }
        if (!self::whole($per) || (int) $per <= 0 || (int) $per > (int) $amt) {
            return self::no('VALIDATION', 'Give how much to recover from each pay: more than zero, and not more than the amount.');
        }
        $kind = ($in['kind'] ?? 'advance') === 'loan' ? 'loan' : 'advance';
        $on = (string) ($in['given_on'] ?? self::today());
        if (!self::isDate($on) || $on > self::today()) {
            return self::no('VALIDATION', 'The date given cannot be in the future.');
        }
        Db::run('INSERT INTO att_fin_advances (employee_id, kind, amount_paisa, per_pay_paisa, given_on, method, note, created_by) VALUES (?,?,?,?,?,?,?,?)',
            [$emp, $kind, (int) $amt, (int) $per, $on, ($in['method'] ?? 'cash') === 'bank' ? 'bank' : 'cash', mb_substr(trim((string) ($in['note'] ?? '')), 0, 255) ?: null, $actorId]);
        $id = Db::lastId();
        Audit::record('fin.advance_give', 'att_fin_advance', $id, null, ['employee_id' => $emp, 'kind' => $kind, 'amount_paisa' => (int) $amt, 'per_pay_paisa' => (int) $per], $actorId);
        return ['ok' => true, 'advance_id' => $id];
    }

    public static function cancelAdvance(int $id, string $reason, int $actorId): array
    {
        if (trim($reason) === '') {
            return self::no('REASON_REQUIRED', 'Say why — the reason is kept.');
        }
        return Db::tx(static function () use ($id, $reason, $actorId) {
            $a = Db::one('SELECT * FROM att_fin_advances WHERE id = ? FOR UPDATE', [$id]);
            if ($a === null || $a['status'] === 'cancelled') {
                return self::no('NOT_FOUND', 'No such advance.');
            }
            if ((int) $a['recovered_paisa'] > 0) {
                return self::no('ALREADY_DECIDED', 'Part of it has been recovered already, so it cannot be cancelled.');
            }
            Db::run("UPDATE att_fin_advances SET status = 'cancelled', cancelled_by = ?, cancelled_at = ?, cancel_reason = ? WHERE id = ?", [$actorId, Time::sql(), mb_substr(trim($reason), 0, 255), $id]);
            Audit::record('fin.advance_cancel', 'att_fin_advance', $id, $a, null, $actorId, trim($reason));
            return ['ok' => true];
        });
    }

    // ------------------------------------------------- working out the pay

    /**
     * Who can go on a sheet of this kind for these dates, with every line
     * worked out. People already on a sheet for overlapping dates, or with
     * nothing to pay, are left out.
     */
    public static function preview(string $kind, string $from, string $to, array $caps): array
    {
        if (($bad = self::checkPeriod($kind, $from, $to)) !== null) {
            return $bad;
        }
        if (!self::mayPrepare($caps, $kind)) {
            return self::no('FORBIDDEN', $kind === 'salary' ? 'Fixed salaries are prepared by Accounts, HR, Audit or Admin.' : 'Daily wages and contractors are prepared by Accounts, a HOD or Admin.');
        }
        self::syncProduction();
        [$w, $p] = self::deptWhere(self::prepScope($caps, $kind));
        $emps = Db::all(
            "SELECT e.id, e.code, e.full_name, e.department_id, d.name AS department_name, (e.reference_photo_key IS NOT NULL) AS has_photo,
                    f.pay_type, f.basic_paisa, f.allowance_paisa, f.daily_rate_paisa, f.ot_rate_paisa, f.pay_method
               FROM att_fin_profiles f JOIN att_employees e ON e.id = f.employee_id JOIN att_departments d ON d.id = e.department_id
              WHERE f.pay_type = ? $w ORDER BY d.name, e.full_name", array_merge([$kind], $p));
        $rows = [];
        foreach ($emps as $e) {
            if (self::busy((int) $e['id'], $kind, $from, $to) !== null) {
                continue;
            }
            $lines = self::workOut($e, $kind, $from, $to);
            $net = self::net($lines);
            if ($lines === [] || ($net <= 0 && !self::hasEarning($lines))) {
                continue;
            }
            $rows[] = ['employee_id' => (int) $e['id'], 'code' => $e['code'], 'full_name' => $e['full_name'], 'department_name' => $e['department_name'],
                       'has_photo' => (int) $e['has_photo'], 'method' => $e['pay_method'], 'calc_paisa' => $net, 'lines' => $lines];
        }
        return ['ok' => true, 'kind' => $kind, 'from' => $from, 'to' => $to, 'rows' => $rows,
                'pay_weekday' => Policy::int('fin_pay_weekday', 4)];
    }

    /**
     * Every line the system works out for one person. Each has a stable key,
     * so an amendment names the line it changes.
     *
     * @param array<string,mixed> $e employee + profile
     * @return list<array<string,mixed>>
     */
    public static function workOut(array $e, string $kind, string $from, string $to): array
    {
        $id = (int) $e['id'];
        $L = [];
        $add = function (string $key, string $lineKind, string $side, string $label, int $paisa, ?string $detail = null, ?int $advId = null) use (&$L): void {
            $L[] = ['key' => $key, 'kind' => $lineKind, 'side' => $side, 'label' => $label, 'detail' => $detail,
                    'calc_paisa' => $paisa, 'amount_paisa' => $paisa, 'reason' => null, 'advance_id' => $advId,
                    'editable' => !in_array($lineKind, self::FIXED, true)];
        };
        $settle = true;                                   // overtime, leave and recoveries belong to this sheet
        if ($kind === 'salary') {
            $f = new DateTimeImmutable($from); $t = new DateTimeImmutable($to);
            $dim = (int) $f->format('t'); $n = (int) $f->diff($t)->days + 1;
            $whole = $n === $dim;
            $part = $whole ? '' : ' · ' . $f->format('j') . '–' . $t->format('j M');
            $add('basic', 'basic', 'earn', 'Basic salary' . $part, self::share((int) $e['basic_paisa'], $n, $dim), $whole ? 'from salary setup' : "salary ÷ $dim × $n days");
            if ((int) $e['allowance_paisa'] > 0) {
                $add('allowance', 'allowance', 'earn', 'Allowance' . $part, self::share((int) $e['allowance_paisa'], $n, $dim), 'from salary setup');
            }
            // Month-end items are settled on the sheet that includes the last day of the month.
            $settle = $t->format('j') === (string) $dim;
            if ($settle) {
                $mFrom = $f->format('Y-m-01'); $mTo = $t->format('Y-m-d');
                $abs = self::absentDays($id, $mFrom, $mTo);
                if ($abs > 0) {
                    $add('leave', 'leave', 'deduct', 'Unpaid leave · ' . $abs . ($abs === 1 ? ' day' : ' days'), self::share((int) $e['basic_paisa'], $abs, $dim), "salary ÷ $dim per absent day, from Attendance");
                }
                self::addOvertime($add, $id, (int) $e['ot_rate_paisa'], $mFrom, $mTo);
            }
        } elseif ($kind === 'daily') {
            [$full, $half] = self::daysPresent($id, $from, $to);
            if ($full + $half > 0) {
                $days = $full + $half / 2;
                $add('daily', 'daily', 'earn', 'Daily wages · ' . self::num($days) . ($days == 1 ? ' day' : ' days'), (int) round((int) $e['daily_rate_paisa'] * $days),
                    self::num($days) . ' × ' . self::rupees((int) $e['daily_rate_paisa']) . ($half ? " ($half half)" : '') . ', from Attendance');
            }
            self::addOvertime($add, $id, (int) $e['ot_rate_paisa'], $from, $to);
        }
        // Money already owed from before this sheet: a contractor's production, or an unpaid balance.
        $carry = self::balance($id);
        if ($carry > 0) {
            $add('carry', 'carry', 'carry', $kind === 'contract' ? 'Production booked, not yet paid' : 'Balance owed from before', $carry, $kind === 'contract' ? 'from Production' : 'from the ledger');
        }
        if (($settle && self::hasEarning($L)) || ($kind === 'contract' && $carry > 0)) {
            // A recovery follows the plan, but never takes more than the pay there is.
            $room = self::net($L);
            foreach (self::advancesFor($id) as $a) {
                $take = min($a['per_pay_paisa'], $a['left_paisa'], max(0, $room));
                if ($a['status'] === 'active' && $take > 0) {
                    $add('adv:' . (int) $a['id'], $a['kind'], 'deduct', ($a['kind'] === 'loan' ? 'Loan' : 'Advance') . ' recovery', $take,
                        self::rupees($a['left_paisa']) . ' still owed · plan ' . self::rupees($a['per_pay_paisa']) . ' each pay', (int) $a['id']);
                    $room -= $take;
                }
            }
        }
        foreach ($L as $i => &$l) { $l['sort'] = $i; }
        unset($l);
        return $L;
    }

    private static function addOvertime(callable $add, int $employeeId, int $ratePaisa, string $from, string $to): void
    {
        if (!Db::tableExists('att_ot_request_employees')) {
            return;
        }
        $r = Db::one(
            "SELECT COALESCE(SUM(l.actual_minutes), 0) AS minutes,
                    COALESCE(SUM(CASE WHEN p.id IS NOT NULL THEN ROUND(COALESCE(p.effective_amount, p.payable_amount) * 100) END), 0) AS typed,
                    COALESCE(SUM(CASE WHEN p.id IS NULL THEN l.actual_minutes ELSE 0 END), 0) AS unrated
               FROM att_ot_request_employees l LEFT JOIN att_ot_payroll p ON p.request_employee_id = l.id
              WHERE l.employee_id = ? AND l.work_date BETWEEN ? AND ? AND l.approval_status = 'approved' AND l.verification_status = 'verified'",
            [$employeeId, $from, $to]);
        $minutes = (int) ($r['minutes'] ?? 0);
        if ($minutes <= 0) {
            return;
        }
        $paisa = (int) $r['typed'] + (int) round((int) $r['unrated'] * $ratePaisa / 60);
        $add('overtime', 'overtime', 'earn', 'Overtime · ' . self::num($minutes / 60) . ' h', $paisa, 'approved and checked hours, from Attendance');
    }

    /** Days a shift was assigned, already over, and nobody came. */
    private static function absentDays(int $employeeId, string $from, string $to): int
    {
        return (int) Db::value(
            "SELECT COUNT(*) FROM att_shift_instances si
              WHERE si.employee_id = ? AND si.work_date BETWEEN ? AND ? AND si.work_date < ? AND si.source = 'assigned'
                AND NOT EXISTS (SELECT 1 FROM att_entry_cycles c WHERE c.shift_instance_id = si.id)",
            [$employeeId, $from, $to, self::today()]);
    }

    /** @return array{0:int,1:int} full days, half days */
    private static function daysPresent(int $employeeId, string $from, string $to): array
    {
        $halfBelow = max(1, Policy::int('fin_half_day_minutes', 240));
        $full = 0; $half = 0;
        foreach (Db::all(
            'SELECT si.work_date, COALESCE(SUM(c.present_minutes), 0) AS minutes, SUM(c.closed_at IS NULL) AS open_now
               FROM att_shift_instances si JOIN att_entry_cycles c ON c.shift_instance_id = si.id
              WHERE si.employee_id = ? AND si.work_date BETWEEN ? AND ? GROUP BY si.work_date', [$employeeId, $from, $to]) as $d) {
            if ((int) $d['minutes'] >= $halfBelow || (int) $d['open_now'] > 0) { $full++; }
            elseif ((int) $d['minutes'] > 0) { $half++; }
        }
        return [$full, $half];
    }

    // --------------------------------------------------------- pay sheets

    /**
     * Prepare a pay sheet and send it for approval.
     *
     * @param list<array<string,mixed>> $items each: employee_id, method?, changes?: [{key, amount_paisa, reason}], added?: [{kind, label, amount_paisa, reason}]
     */
    public static function createSheet(string $kind, string $from, string $to, array $items, array $caps, int $actorId): array
    {
        if (($bad = self::checkPeriod($kind, $from, $to)) !== null) {
            return $bad;
        }
        if (!self::mayPrepare($caps, $kind)) {
            return self::no('FORBIDDEN', 'You cannot prepare this kind of pay sheet.');
        }
        if ($items === [] || count($items) > 2000) {
            return self::no('VALIDATION', 'Tick at least one person.');
        }
        self::syncProduction();
        return Db::tx(static function () use ($kind, $from, $to, $items, $caps, $actorId) {
            $built = [];
            foreach ($items as $it) {
                $empId = (int) ($it['employee_id'] ?? 0);
                if (isset($built[$empId])) {
                    return self::no('VALIDATION', 'The same person is on the sheet twice.');
                }
                $e = Db::one(
                    'SELECT e.id, e.full_name, e.department_id, f.pay_type, f.basic_paisa, f.allowance_paisa, f.daily_rate_paisa, f.ot_rate_paisa, f.pay_method
                       FROM att_employees e JOIN att_fin_profiles f ON f.employee_id = e.id WHERE e.id = ? FOR UPDATE', [$empId]);
                if ($e === null || $e['pay_type'] !== $kind || !self::inScope(self::prepScope($caps, $kind), (int) $e['department_id'])) {
                    return self::no('EMPLOYEE_NOT_FOUND', 'One of the people is not paid this way, or is not yours to prepare.');
                }
                if (($clash = self::busy($empId, $kind, $from, $to)) !== null) {
                    return self::no('OVERLAP', $e['full_name'] . ' is already on pay sheet ' . $clash . ' for these dates.');
                }
                $lines = self::workOut($e, $kind, $from, $to);
                $res = self::applyChanges($lines, (array) ($it['changes'] ?? []), (array) ($it['added'] ?? []), (string) $e['full_name'], $actorId);
                if (!$res['ok']) {
                    return $res;
                }
                $lines = $res['lines'];
                $net = self::net($lines);
                if ($net < 0) {
                    return self::no('VALIDATION', $e['full_name'] . ': the deductions are more than the pay. Lower a deduction or the recovery.');
                }
                $built[$empId] = ['e' => $e, 'lines' => $lines, 'calc' => self::net($lines, true), 'net' => $net,
                                  'method' => in_array($it['method'] ?? null, ['bank', 'cash'], true) ? $it['method'] : $e['pay_method']];
            }
            $ref = self::nextRef();
            Db::run('INSERT INTO att_fin_sheets (ref, kind, period_from, period_to, prepared_by) VALUES (?,?,?,?,?)', [$ref, $kind, $from, $to, $actorId]);
            $sheetId = Db::lastId();
            foreach ($built as $empId => $b) {
                Db::run('INSERT INTO att_fin_sheet_items (sheet_id, employee_id, calc_paisa, amount_paisa, method) VALUES (?,?,?,?,?)', [$sheetId, $empId, $b['calc'], $b['net'], $b['method']]);
                self::storeLines(Db::lastId(), $b['lines']);
            }
            Audit::record('fin.sheet_prepare', 'att_fin_sheet', $sheetId, null, ['ref' => $ref, 'kind' => $kind, 'from' => $from, 'to' => $to, 'people' => count($built)], $actorId);
            return ['ok' => true, 'sheet_id' => $sheetId, 'ref' => $ref, 'people' => count($built), 'total_paisa' => array_sum(array_column($built, 'net'))];
        });
    }

    /**
     * Put a person's amendments onto the worked-out lines.
     *
     * @param list<array<string,mixed>> $lines
     * @return array{ok:bool,lines?:list<array<string,mixed>>,reason_code?:string,error?:string}
     */
    private static function applyChanges(array $lines, array $changes, array $added, string $who, int $actorId): array
    {
        $byKey = [];
        foreach ($lines as $i => $l) { $byKey[$l['key']] = $i; }
        foreach ($changes as $c) {
            $key = (string) ($c['key'] ?? '');
            if (!isset($byKey[$key])) {
                return self::no('VALIDATION', "$who: that line is no longer there. Open the sheet again.");
            }
            $l = &$lines[$byKey[$key]];
            $amt = $c['amount_paisa'] ?? null;
            if (!self::whole($amt) || (int) $amt < 0) {
                unset($l);
                return self::no('VALIDATION', "$who, {$lines[$byKey[$key]]['label']}: give an amount of zero or more.");
            }
            $amt = (int) $amt;
            if ($amt === $l['calc_paisa']) {
                $l['amount_paisa'] = $amt; $l['reason'] = null; unset($l);
                continue;
            }
            if (!$l['editable']) {
                $label = $l['label']; unset($l);
                return self::no('VALIDATION', "$who: $label cannot be changed on a pay sheet. Change it in salary setup.");
            }
            $reason = mb_substr(trim((string) ($c['reason'] ?? '')), 0, 255);
            // Paying part of what is already owed needs no story; changing an earning or a deduction does.
            if ($reason === '' && $l['side'] !== 'carry') {
                $label = $l['label']; unset($l);
                return self::no('REASON_REQUIRED', "$who, $label: say why it was changed.");
            }
            if ($l['side'] === 'carry' && $amt > $l['calc_paisa']) {
                unset($l);
                return self::no('VALIDATION', "$who: that is more than is owed. Give an advance instead.");
            }
            if ($l['advance_id'] !== null) {
                $left = (int) Db::value('SELECT amount_paisa - recovered_paisa FROM att_fin_advances WHERE id = ?', [$l['advance_id']]);
                if ($amt > $left) {
                    unset($l);
                    return self::no('VALIDATION', "$who: the recovery is more than the " . self::rupees($left) . ' still owed.');
                }
            }
            $l['amount_paisa'] = $amt; $l['reason'] = $reason ?: null; $l['amended_by'] = $actorId;
            unset($l);
        }
        $n = 0;
        foreach ($lines as $l) { if (str_starts_with($l['key'], 'add:')) { $n = max($n, (int) substr($l['key'], 4)); } }
        foreach ($added as $a) {
            $k = (string) ($a['kind'] ?? '');
            if (!isset(self::ADDABLE[$k])) {
                return self::no('VALIDATION', "$who: a line can be added as a bonus, another earning, or a deduction.");
            }
            $amt = $a['amount_paisa'] ?? null; $reason = mb_substr(trim((string) ($a['reason'] ?? '')), 0, 255);
            if (!self::whole($amt) || (int) $amt <= 0) {
                return self::no('VALIDATION', "$who: an added line needs an amount.");
            }
            if ($reason === '') {
                return self::no('REASON_REQUIRED', "$who: say what the added line is for.");
            }
            $label = mb_substr(trim((string) ($a['label'] ?? '')), 0, 100) ?: ['bonus' => 'Bonus', 'other_earning' => 'Other earning', 'deduction' => 'Deduction'][$k];
            $lines[] = ['key' => 'add:' . (++$n), 'kind' => $k, 'side' => self::ADDABLE[$k], 'label' => $label, 'detail' => 'added by hand',
                        'calc_paisa' => 0, 'amount_paisa' => (int) $amt, 'reason' => $reason, 'advance_id' => null, 'editable' => true, 'amended_by' => $actorId, 'sort' => count($lines)];
        }
        return ['ok' => true, 'lines' => $lines];
    }

    private static function storeLines(int $itemId, array $lines): void
    {
        foreach ($lines as $l) {
            Db::run('INSERT INTO att_fin_item_lines (item_id, line_key, kind, side, label, detail, calc_paisa, amount_paisa, reason, advance_id, amended_by, sort) VALUES (?,?,?,?,?,?,?,?,?,?,?,?)',
                [$itemId, $l['key'], $l['kind'], $l['side'], $l['label'], $l['detail'], $l['calc_paisa'], $l['amount_paisa'], $l['reason'], $l['advance_id'], $l['amended_by'] ?? null, $l['sort']]);
        }
    }

    /** @return array<string,mixed> */
    public static function sheets(array $caps, int $userId, ?string $status = null): array
    {
        $w = '1 = 1'; $p = [];
        if ($status !== null && in_array($status, ['waiting', 'approved', 'paid', 'returned', 'cancelled'], true)) { $w .= ' AND s.status = ?'; $p[] = $status; }
        if ($caps['departments'] !== null) { $w .= ' AND s.prepared_by = ?'; $p[] = $userId; }     // a HOD sees the sheets they prepared
        if (!$caps['see_salary']) { $w .= " AND s.kind <> 'salary'"; }
        $users = Db::tableExists('users');
        $name = fn(string $col, string $as) => $users ? "(SELECT u.name FROM users u WHERE u.id = s.$col) AS $as" : "NULL AS $as";
        $rows = Db::all(
            "SELECT s.id, s.ref, s.kind, s.period_from, s.period_to, s.status, s.prepared_by, s.prepared_at, s.approved_at, s.paid_at, s.pay_date, s.off_day_reason, s.return_reason,
                    {$name('prepared_by', 'prepared_by_name')}, {$name('approved_by', 'approved_by_name')}, {$name('paid_by', 'paid_by_name')},
                    (SELECT COUNT(*) FROM att_fin_sheet_items i WHERE i.sheet_id = s.id) AS people,
                    (SELECT COALESCE(SUM(i.amount_paisa), 0) FROM att_fin_sheet_items i WHERE i.sheet_id = s.id) AS total_paisa,
                    (SELECT COUNT(*) FROM att_fin_item_lines l JOIN att_fin_sheet_items i ON i.id = l.item_id WHERE i.sheet_id = s.id AND l.amount_paisa <> l.calc_paisa AND l.side <> 'carry') AS amended
               FROM att_fin_sheets s WHERE $w ORDER BY s.id DESC LIMIT 300", $p);
        foreach ($rows as &$r) { foreach (['people', 'total_paisa', 'amended', 'prepared_by'] as $k) { $r[$k] = (int) $r[$k]; } }
        unset($r);
        return ['ok' => true, 'sheets' => $rows];
    }

    public static function sheet(int $id, array $caps, int $userId): array
    {
        $s = Db::one('SELECT * FROM att_fin_sheets WHERE id = ?', [$id]);
        if ($s === null || ($caps['departments'] !== null && (int) $s['prepared_by'] !== $userId) || (!$caps['see_salary'] && $s['kind'] === 'salary')) {
            return self::no('NOT_FOUND', 'No such pay sheet.');
        }
        $items = Db::all(
            'SELECT i.id AS item_id, i.employee_id, i.calc_paisa, i.amount_paisa, i.method, e.code, e.full_name, d.name AS department_name, (e.reference_photo_key IS NOT NULL) AS has_photo
               FROM att_fin_sheet_items i JOIN att_employees e ON e.id = i.employee_id JOIN att_departments d ON d.id = e.department_id
              WHERE i.sheet_id = ? ORDER BY d.name, e.full_name', [$id]);
        $lines = [];
        foreach (Db::all('SELECT l.* FROM att_fin_item_lines l JOIN att_fin_sheet_items i ON i.id = l.item_id WHERE i.sheet_id = ? ORDER BY l.item_id, l.sort, l.id', [$id]) as $l) {
            $lines[(int) $l['item_id']][] = ['key' => $l['line_key'], 'kind' => $l['kind'], 'side' => $l['side'], 'label' => $l['label'], 'detail' => $l['detail'],
                'calc_paisa' => (int) $l['calc_paisa'], 'amount_paisa' => (int) $l['amount_paisa'], 'reason' => $l['reason'],
                'editable' => !in_array($l['kind'], self::FIXED, true)];
        }
        $bank = 0; $cash = 0;
        foreach ($items as &$i) {
            $i['calc_paisa'] = (int) $i['calc_paisa']; $i['amount_paisa'] = (int) $i['amount_paisa'];
            $i['lines'] = $lines[(int) $i['item_id']] ?? [];
            if ($i['method'] === 'bank') { $bank += $i['amount_paisa']; } else { $cash += $i['amount_paisa']; }
        }
        unset($i);
        return ['ok' => true, 'sheet' => $s, 'items' => $items, 'bank_paisa' => $bank, 'cash_paisa' => $cash, 'total_paisa' => $bank + $cash,
                'own' => (int) $s['prepared_by'] === $userId, 'pay_weekday' => Policy::int('fin_pay_weekday', 4)];
    }

    /**
     * Amend a sheet that is waiting for approval: the approver's changes, or
     * the preparer's second thoughts.
     *
     * @param list<array<string,mixed>> $items employee_id, method?, changes?, added?, remove_keys?
     */
    public static function amendSheet(int $id, array $items, array $caps, int $actorId): array
    {
        return Db::tx(static function () use ($id, $items, $caps, $actorId) {
            $s = Db::one('SELECT * FROM att_fin_sheets WHERE id = ? FOR UPDATE', [$id]);
            if ($s === null) {
                return self::no('NOT_FOUND', 'No such pay sheet.');
            }
            if ($s['status'] !== 'waiting') {
                return self::no('ALREADY_DECIDED', 'This sheet is already ' . $s['status'] . ' and cannot be amended.');
            }
            if (!$caps['approve'] && (int) $s['prepared_by'] !== $actorId) {
                return self::no('FORBIDDEN', 'Only the person who prepared it, or an approver, can amend a waiting sheet.');
            }
            // Check every person first and write afterwards, so a refused line leaves the sheet exactly as it was.
            $todo = [];
            foreach ($items as $it) {
                $item = Db::one('SELECT i.*, e.full_name FROM att_fin_sheet_items i JOIN att_employees e ON e.id = i.employee_id WHERE i.sheet_id = ? AND i.employee_id = ? FOR UPDATE', [$id, (int) ($it['employee_id'] ?? 0)]);
                if ($item === null || isset($todo[(int) $item['id']])) {
                    return self::no('VALIDATION', 'One of the people is not on this sheet.');
                }
                $itemId = (int) $item['id'];
                $lines = array_map(static fn($l) => ['key' => $l['line_key'], 'kind' => $l['kind'], 'side' => $l['side'], 'label' => $l['label'], 'detail' => $l['detail'],
                    'calc_paisa' => (int) $l['calc_paisa'], 'amount_paisa' => (int) $l['amount_paisa'], 'reason' => $l['reason'],
                    'advance_id' => $l['advance_id'] === null ? null : (int) $l['advance_id'], 'amended_by' => $l['amended_by'], 'sort' => (int) $l['sort'],
                    'editable' => !in_array($l['kind'], self::FIXED, true)],
                    Db::all('SELECT * FROM att_fin_item_lines WHERE item_id = ? ORDER BY sort, id', [$itemId]));
                $drop = array_map('strval', (array) ($it['remove_keys'] ?? []));
                foreach ($drop as $k) {
                    if (!str_starts_with($k, 'add:')) {
                        return self::no('VALIDATION', 'Only a line that was added by hand can be removed. Set a worked-out line to zero instead.');
                    }
                }
                $lines = array_values(array_filter($lines, static fn($l) => !in_array($l['key'], $drop, true)));
                $res = self::applyChanges($lines, (array) ($it['changes'] ?? []), (array) ($it['added'] ?? []), (string) $item['full_name'], $actorId);
                if (!$res['ok']) {
                    return $res;
                }
                $net = self::net($res['lines']);
                if ($net < 0) {
                    return self::no('VALIDATION', $item['full_name'] . ': the deductions are more than the pay.');
                }
                $todo[$itemId] = [$res['lines'], $net, in_array($it['method'] ?? null, ['bank', 'cash'], true) ? $it['method'] : $item['method']];
            }
            foreach ($todo as $itemId => [$lines, $net, $method]) {
                Db::run('DELETE FROM att_fin_item_lines WHERE item_id = ?', [$itemId]);
                self::storeLines($itemId, $lines);
                Db::run('UPDATE att_fin_sheet_items SET amount_paisa = ?, method = ? WHERE id = ?', [$net, $method, $itemId]);
            }
            Audit::record('fin.sheet_amend', 'att_fin_sheet', $id, null, ['people' => count($items)], $actorId);
            return ['ok' => true, 'sheet_id' => $id];
        });
    }

    public static function approve(int $id, array $caps, int $actorId): array
    {
        if (!$caps['approve']) {
            return self::no('FORBIDDEN', 'Only Admin or Audit can approve a pay sheet.');
        }
        return Db::tx(static function () use ($id, $caps, $actorId) {
            $s = Db::one('SELECT * FROM att_fin_sheets WHERE id = ? FOR UPDATE', [$id]);
            if ($s === null) {
                return self::no('NOT_FOUND', 'No such pay sheet.');
            }
            if ($s['status'] !== 'waiting') {
                return self::no('ALREADY_DECIDED', 'This sheet is already ' . $s['status'] . '.');
            }
            if ((int) $s['prepared_by'] === $actorId && !$caps['admin']) {
                return self::no('FORBIDDEN', 'You prepared this sheet, so someone else must approve it.');
            }
            $date = min((string) $s['period_to'], self::today());
            $lines = Db::all('SELECT l.*, i.employee_id, e.full_name FROM att_fin_item_lines l JOIN att_fin_sheet_items i ON i.id = l.item_id JOIN att_employees e ON e.id = i.employee_id
                               WHERE i.sheet_id = ? ORDER BY l.item_id, l.sort', [$id]);
            // Things may have moved since it was prepared. Check all of it before posting any of it.
            $taking = [];
            foreach ($lines as $l) {
                $amt = (int) $l['amount_paisa'];
                if ($l['side'] === 'carry' && $amt > self::balance((int) $l['employee_id'])) {
                    return self::no('STALE', $l['full_name'] . ': less is owed now than when this sheet was prepared. Send it back and prepare it again.');
                }
                if ($l['advance_id'] !== null && $amt > 0) {
                    $aid = (int) $l['advance_id'];
                    $a = Db::one('SELECT amount_paisa, recovered_paisa, status FROM att_fin_advances WHERE id = ? FOR UPDATE', [$aid]);
                    $taking[$aid] = ($taking[$aid] ?? 0) + $amt;
                    if ($a === null || $a['status'] === 'cancelled' || $taking[$aid] > (int) $a['amount_paisa'] - (int) $a['recovered_paisa']) {
                        return self::no('STALE', $l['full_name'] . ': the recovery is now more than is still owed. Send the sheet back and prepare it again.');
                    }
                }
            }
            foreach ($lines as $l) {
                $amt = (int) $l['amount_paisa'];
                if ($l['side'] === 'carry' || $amt === 0) {
                    continue;                                   // already in the ledger, or nothing to post
                }
                if ($l['advance_id'] !== null) {
                    Db::run("UPDATE att_fin_advances SET recovered_paisa = recovered_paisa + ?, status = IF(recovered_paisa >= amount_paisa, 'closed', status) WHERE id = ?", [$amt, (int) $l['advance_id']]);
                }
                $changed = $amt !== (int) $l['calc_paisa'] && $l['reason'] !== null && !str_starts_with((string) $l['line_key'], 'add:');
                Db::run('INSERT INTO att_fin_ledger (employee_id, entry_date, kind, line_kind, label, amount_paisa, sheet_id, advance_id, source, created_by) VALUES (?,?,?,?,?,?,?,?,?,?)',
                    [(int) $l['employee_id'], $date, $l['side'] === 'earn' ? 'earning' : 'deduction', $l['kind'],
                     mb_substr($l['label'] . ($l['reason'] !== null ? ' · ' . ($changed ? 'amended: ' : '') . $l['reason'] : ''), 0, 200), $amt, $id,
                     $l['advance_id'] === null ? null : (int) $l['advance_id'], 'sheet', $actorId]);
            }
            Db::run("UPDATE att_fin_sheets SET status = 'approved', approved_by = ?, approved_at = ? WHERE id = ?", [$actorId, Time::sql(), $id]);
            Audit::record('fin.sheet_approve', 'att_fin_sheet', $id, ['status' => 'waiting'], ['status' => 'approved'], $actorId);
            return ['ok' => true, 'sheet_id' => $id, 'status' => 'approved'];
        });
    }

    /** Back to the people who prepare: the sheet is closed as "returned" and its people can be prepared again. */
    public static function sendBack(int $id, string $reason, array $caps, int $actorId): array
    {
        return Db::tx(static function () use ($id, $reason, $caps, $actorId) {
            $s = Db::one('SELECT * FROM att_fin_sheets WHERE id = ? FOR UPDATE', [$id]);
            if ($s === null) {
                return self::no('NOT_FOUND', 'No such pay sheet.');
            }
            if ($s['status'] !== 'waiting') {
                return self::no('ALREADY_DECIDED', 'Only a sheet waiting for approval can be sent back. This one is ' . $s['status'] . '.');
            }
            if (!$caps['approve'] && (int) $s['prepared_by'] !== $actorId) {
                return self::no('FORBIDDEN', 'Only an approver, or the person who prepared it, can send a sheet back.');
            }
            if (trim($reason) === '') {
                return self::no('REASON_REQUIRED', 'Say why it is going back.');
            }
            Db::run("UPDATE att_fin_sheets SET status = 'returned', returned_by = ?, returned_at = ?, return_reason = ? WHERE id = ?", [$actorId, Time::sql(), mb_substr(trim($reason), 0, 255), $id]);
            Audit::record('fin.sheet_return', 'att_fin_sheet', $id, ['status' => 'waiting'], ['status' => 'returned'], $actorId, trim($reason));
            return ['ok' => true, 'sheet_id' => $id, 'status' => 'returned'];
        });
    }

    /**
     * Undo a sheet that was approved, or even paid, by mistake. Nothing is
     * deleted: its ledger lines are struck through with the reason, and what
     * it recovered from advances is put back. The people can then be prepared
     * again. A PAID sheet is undone by an administrator only.
     */
    public static function cancelSheet(int $id, string $reason, array $caps, int $actorId): array
    {
        if (trim($reason) === '') {
            return self::no('REASON_REQUIRED', 'Say why it is being cancelled — the reason is kept on every line.');
        }
        return Db::tx(static function () use ($id, $reason, $caps, $actorId) {
            $s = Db::one('SELECT * FROM att_fin_sheets WHERE id = ? FOR UPDATE', [$id]);
            if ($s === null) {
                return self::no('NOT_FOUND', 'No such pay sheet.');
            }
            if (!in_array($s['status'], ['approved', 'paid'], true)) {
                return self::no('ALREADY_DECIDED', $s['status'] === 'waiting' ? 'A sheet still waiting is sent back, not cancelled.' : 'This sheet is already ' . $s['status'] . '.');
            }
            if ($s['status'] === 'paid' ? !$caps['admin'] : !$caps['approve']) {
                return self::no('FORBIDDEN', $s['status'] === 'paid' ? 'Only an administrator can cancel a sheet that has been paid.' : 'Only Admin or Audit can cancel an approved sheet.');
            }
            $why = mb_substr(trim($reason), 0, 255);
            foreach (Db::all("SELECT advance_id, SUM(amount_paisa) AS paisa FROM att_fin_ledger WHERE sheet_id = ? AND advance_id IS NOT NULL AND cancelled_at IS NULL GROUP BY advance_id", [$id]) as $r) {
                Db::run("UPDATE att_fin_advances SET recovered_paisa = GREATEST(0, recovered_paisa - ?), status = IF(status = 'closed', 'active', status) WHERE id = ?", [(int) $r['paisa'], (int) $r['advance_id']]);
            }
            Db::run('UPDATE att_fin_ledger SET cancelled_at = ?, cancelled_by = ?, cancel_reason = ? WHERE sheet_id = ? AND cancelled_at IS NULL', [Time::sql(), $actorId, $why, $id]);
            Db::run("UPDATE att_fin_sheets SET status = 'cancelled', returned_by = ?, returned_at = ?, return_reason = ? WHERE id = ?", [$actorId, Time::sql(), $why, $id]);
            Audit::record('fin.sheet_cancel', 'att_fin_sheet', $id, ['status' => $s['status']], ['status' => 'cancelled'], $actorId, $why);
            return ['ok' => true, 'sheet_id' => $id, 'status' => 'cancelled', 'was' => $s['status']];
        });
    }

    public static function pay(int $id, string $date, string $reason, array $caps, int $actorId): array
    {
        if (!$caps['pay']) {
            return self::no('FORBIDDEN', 'Only Accounts or Admin can record a payment.');
        }
        if (!self::isDate($date) || $date > self::today()) {
            return self::no('VALIDATION', 'Give the payment date. It cannot be in the future.');
        }
        $payDay = Policy::int('fin_pay_weekday', 4);
        if ((int) (new DateTimeImmutable($date))->format('N') !== $payDay && trim($reason) === '') {
            return self::no('REASON_REQUIRED', 'That is not the usual pay day. Say why it is paid on this day.');
        }
        return Db::tx(static function () use ($id, $date, $reason, $actorId) {
            $s = Db::one('SELECT * FROM att_fin_sheets WHERE id = ? FOR UPDATE', [$id]);
            if ($s === null) {
                return self::no('NOT_FOUND', 'No such pay sheet.');
            }
            if ($s['status'] !== 'approved') {
                return self::no('ALREADY_DECIDED', $s['status'] === 'paid' ? 'This sheet is already paid.' : 'This sheet has not been approved yet.');
            }
            $total = 0;
            foreach (Db::all('SELECT employee_id, amount_paisa, method FROM att_fin_sheet_items WHERE sheet_id = ?', [$id]) as $i) {
                if ((int) $i['amount_paisa'] <= 0) {
                    continue;
                }
                Db::run("INSERT INTO att_fin_ledger (employee_id, entry_date, kind, label, amount_paisa, method, sheet_id, source, created_by) VALUES (?, ?, 'payment', ?, ?, ?, ?, 'sheet', ?)",
                    [(int) $i['employee_id'], $date, 'Wages paid · ' . $s['ref'], (int) $i['amount_paisa'], $i['method'], $id, $actorId]);
                $total += (int) $i['amount_paisa'];
            }
            Db::run("UPDATE att_fin_sheets SET status = 'paid', paid_by = ?, paid_at = ?, pay_date = ?, off_day_reason = ? WHERE id = ?", [$actorId, Time::sql(), $date, mb_substr(trim($reason), 0, 255) ?: null, $id]);
            Audit::record('fin.sheet_pay', 'att_fin_sheet', $id, ['status' => 'approved'], ['status' => 'paid', 'pay_date' => $date, 'total_paisa' => $total], $actorId, trim($reason) ?: null);
            return ['ok' => true, 'sheet_id' => $id, 'status' => 'paid', 'total_paisa' => $total];
        });
    }

    /** Home: what is owed, and how much is waiting at each step. */
    public static function overview(array $caps, int $userId): array
    {
        $people = self::people($caps)['people'];
        $t = ['owed' => 0, 'earned' => 0, 'paid' => 0];
        foreach ($people as $r) {
            if ($r['balance'] !== null) { $t['owed'] += max(0, (int) $r['balance']); $t['earned'] += (int) $r['earned']; $t['paid'] += (int) $r['paid']; }
        }
        $count = ['waiting' => 0, 'approved' => 0, 'paid' => 0, 'returned' => 0, 'cancelled' => 0];
        foreach (self::sheets($caps, $userId)['sheets'] as $s) { $count[$s['status']]++; }
        return ['ok' => true, 'today' => self::today(), 'owed_paisa' => $t['owed'], 'earned_paisa' => $t['earned'], 'paid_paisa' => $t['paid'],
                'sheets' => $count, 'no_setup' => count(array_filter($people, static fn($r) => $r['pay_type'] === null && (int) $r['active'] === 1)),
                'unlinked_workers' => $caps['wage_departments'] === null && $caps['wage'] ? array_slice(self::unlinkedWorkers(), 0, 200) : [],
                'advances_out_paisa' => (int) Db::value("SELECT COALESCE(SUM(amount_paisa - recovered_paisa), 0) FROM att_fin_advances WHERE status = 'active'"),
                'pay_weekday' => Policy::int('fin_pay_weekday', 4)];
    }

    // ------------------------------------------------------------ helpers

    private static function mayPrepare(array $caps, string $kind): bool
    {
        return $kind === 'salary' ? $caps['salary'] : $caps['wage'];
    }

    /** @param ?list<int> $departments null = every department */
    private static function inScope(?array $departments, int $departmentId): bool
    {
        return $departments === null || in_array($departmentId, $departments, true);
    }

    /** Whose pay of this kind may this user prepare? null = everyone's. */
    private static function prepScope(array $caps, string $kind): ?array
    {
        return $kind === 'salary' ? null : $caps['wage_departments'];
    }

    /**
     * @param ?list<int> $departments
     * @return array{0:string,1:list<int>}
     */
    private static function deptWhere(?array $departments): array
    {
        if ($departments === null) {
            return ['', []];
        }
        if ($departments === []) {
            return [' AND 1 = 0', []];
        }
        return [' AND e.department_id IN (' . implode(',', array_fill(0, count($departments), '?')) . ')', array_map('intval', $departments)];
    }

    private static function checkPeriod(string $kind, string $from, string $to): ?array
    {
        if (!in_array($kind, self::KINDS, true)) {
            return self::no('VALIDATION', 'Choose fixed salary, daily wages or contractors.');
        }
        if (!self::isDate($from) || !self::isDate($to) || $to < $from) {
            return self::no('VALIDATION', 'Give the first and last day.');
        }
        if ($to > self::today()) {
            return self::no('VALIDATION', 'The last day cannot be in the future: those days have not been worked yet.');
        }
        if ($kind === 'salary' && substr($from, 0, 7) !== substr($to, 0, 7)) {
            return self::no('VALIDATION', 'A salary sheet covers days of one month. Prepare each month separately.');
        }
        if ($kind === 'daily' && (new DateTimeImmutable($from))->diff(new DateTimeImmutable($to))->days > 30) {
            return self::no('VALIDATION', 'A daily-wages sheet covers at most 31 days.');
        }
        return null;
    }

    /** Is this person already on a live sheet that covers any of these days? Returns that sheet's ref. */
    private static function busy(int $employeeId, string $kind, string $from, string $to): ?string
    {
        $sql = "SELECT s.ref FROM att_fin_sheet_items i JOIN att_fin_sheets s ON s.id = i.sheet_id WHERE i.employee_id = ? AND s.kind = ?";
        // A contractor has no period: one open sheet at a time. Salary and wages: no day twice, ever.
        $sql .= $kind === 'contract' ? " AND s.status IN ('waiting','approved')" : " AND s.status IN ('waiting','approved','paid') AND s.period_from <= ? AND s.period_to >= ?";
        $ref = Db::value($sql . ' LIMIT 1', $kind === 'contract' ? [$employeeId, $kind] : [$employeeId, $kind, $to, $from]);
        return $ref === null || $ref === false ? null : (string) $ref;
    }

    /** Net of a set of lines; $calc = the worked-out figures instead of the amended ones. */
    private static function net(array $lines, bool $calc = false): int
    {
        $n = 0;
        foreach ($lines as $l) {
            $v = $calc ? $l['calc_paisa'] : $l['amount_paisa'];
            $n += $l['side'] === 'deduct' ? -$v : $v;
        }
        return $n;
    }

    private static function hasEarning(array $lines): bool
    {
        foreach ($lines as $l) {
            if ($l['side'] === 'earn' && $l['amount_paisa'] > 0) {
                return true;
            }
        }
        return false;
    }

    private static function nextRef(): string
    {
        $day = 'PAY-' . (new DateTimeImmutable(self::today()))->format('ymd') . '-';
        $n = (int) Db::value('SELECT COUNT(*) FROM att_fin_sheets WHERE ref LIKE ?', [$day . '%']);
        do {
            $ref = $day . str_pad((string) (++$n), 2, '0', STR_PAD_LEFT);
        } while (Db::value('SELECT 1 FROM att_fin_sheets WHERE ref = ?', [$ref]));
        return $ref;
    }

    /** amount x part / whole, to the nearest paisa, without floats. */
    private static function share(int $paisa, int $part, int $whole): int
    {
        return intdiv($paisa * $part * 2 + $whole, $whole * 2);
    }

    private static function whole(mixed $v): bool
    {
        return is_int($v) || (is_string($v) && preg_match('/^\d{1,13}$/', $v) === 1) || (is_float($v) && floor($v) === $v && $v >= 0);
    }

    private static function isDate(string $d): bool
    {
        return preg_match('/^\d{4}-\d{2}-\d{2}$/', $d) === 1 && checkdate((int) substr($d, 5, 2), (int) substr($d, 8, 2), (int) substr($d, 0, 4));
    }

    private static function num(float|int $n): string
    {
        return rtrim(rtrim(number_format((float) $n, 2, '.', ''), '0'), '.');
    }

    private static function rupees(int $paisa): string
    {
        return number_format(intdiv($paisa, 100)) . ($paisa % 100 ? '.' . str_pad((string) ($paisa % 100), 2, '0', STR_PAD_LEFT) : '');
    }

    private static function no(string $code, string $message): array
    {
        return ['ok' => false, 'reason_code' => $code, 'error' => $message];
    }
}
