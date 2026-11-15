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
/** Thrown inside a transaction to undo all of it and say why (never a database error). */
final class FinStop extends \Exception
{
}

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
            'mark' => $admin || $job(Roles::HR),          // the week attendance grid: Admin and HR
            // A deduction entry (penalty, unpaid leave, a recovery taken early) is an
            // adjustment, not a payment: the office posts it at once. A HOD's still waits.
            'direct_deduct' => ($admin || $job(Roles::ACCOUNTS, Roles::HR, Roles::AUDIT)) && Policy::get('fin_direct_deductions', '1') !== '0',
            // Adjustment entries (Add earning / Add deduction) for anybody, wage and
            // contract people included: the office — Admin, Accounts, HR, Audit.
            'adjust' => $admin || $job(Roles::ACCOUNTS, Roles::HR, Roles::AUDIT),
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
            foreach (['salary', 'wage', 'approve', 'pay', 'setup', 'mark', 'direct_deduct', 'adjust'] as $k) { $caps[$k] = false; }
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
            "SELECT e.id AS employee_id, e.code, e.full_name, e.active, d.name AS department_name, e.department_id, e.worker_code, e.qr_payload,
                    (e.reference_photo_key IS NOT NULL) AS has_photo, (e.notice_ack_at IS NOT NULL) AS notice_given,
                    f.pay_type, f.basic_paisa, f.allowance_paisa, f.daily_rate_paisa, f.ot_rate_paisa, f.pay_method, f.bank_account
               FROM att_employees e
               JOIN att_departments d ON d.id = e.department_id
               LEFT JOIN att_fin_profiles f ON f.employee_id = e.id
              WHERE 1 = 1 $w
              ORDER BY e.full_name LIMIT 5000", $p);
        $opening = [];
        foreach (Db::all("SELECT employee_id, amount_paisa FROM att_fin_ledger WHERE kind = 'opening' AND cancelled_at IS NULL") as $o) { $opening[(int) $o['employee_id']] = (int) $o['amount_paisa']; }
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
            $r['opening_paisa'] = $opening[$id] ?? null;
            if (!$caps['setup'] && !$caps['pay'] && !$caps['approve']) {
                $r['bank_account'] = null;                 // an account number is for the people who pay
            }
            if (!$caps['see_salary'] && $r['pay_type'] === 'salary') {
                // Not for a HOD's eyes: the person is listed, the pay is not.
                $r['basic_paisa'] = $r['allowance_paisa'] = null;
                $r['earned'] = $r['deducted'] = $r['paid'] = $r['balance'] = $r['opening_paisa'] = null;
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

    /**
     * Pay setup for many people in one go — the first-time job, instead of
     * opening each person. Every row goes through saveProfile(), so every
     * rule and the history are exactly the same; a row that is refused is
     * reported by name and the others are still saved.
     *
     * @param list<array<string,mixed>> $items each: employee_id + the fields of saveProfile()
     */
    public static function saveProfiles(array $items, string $reason, int $actorId): array
    {
        if ($items === [] || count($items) > 1000) {
            return self::no('VALIDATION', 'Tick at least one person.');
        }
        $saved = 0; $errors = [];
        foreach ($items as $it) {
            $id = (int) ($it['employee_id'] ?? 0);
            $r = self::saveProfile($id, $it, $reason, $actorId);
            if ($r['ok']) {
                $saved++;
            } else {
                $name = (string) (Db::value('SELECT full_name FROM att_employees WHERE id = ?', [$id]) ?: 'Someone');
                $errors[] = $name . ': ' . $r['error'];
            }
        }
        return ['ok' => $saved > 0 || $errors === [], 'saved' => $saved, 'errors' => $errors,
                'error' => $saved === 0 && $errors !== [] ? implode(' ', array_slice($errors, 0, 3)) : null];
    }

    /* ------------------------------------------------- one employee form

       The employee (code, name, department, production code), how they are
       paid, and what they were owed on day one used to be entered on two
       different screens. saveStaff() takes all of it for many people in one
       go — typed, or pasted from Excel — and sends each part through the rule
       that already governs it: AdminService::saveEmployee() for the person,
       saveProfile() for the pay, setOpening() for the balance. Nothing here
       is a second copy of those rules.

       A row that is refused is reported by name and the other rows are still
       saved. Within one row the parts are done in that order and stop at the
       first refusal, so a person is never given pay under a code that was
       turned down. */

    /** May this user add and change employees themselves (not only their pay)? */
    public static function mayEditStaff(int $userId): bool
    {
        if (Roles::isAdmin($userId) || Roles::has($userId, Roles::HR)) {
            return self::windowState($userId) === 'yes' || Roles::isAdmin($userId);
        }
        if (!function_exists('zu_can') || !function_exists('current_user')) {
            return false;
        }
        $u = \current_user();
        return is_array($u) && (int) ($u['id'] ?? 0) === $userId && \zu_can('attadm', 'u');
    }

    /** The next free employee code, continuing the numbering already in use (ZAS-007 → ZAS-008). */
    public static function nextCode(array $alsoTaken = []): string
    {
        $taken = []; $families = [];
        foreach (array_merge(array_column(Db::all('SELECT code FROM att_employees'), 'code'), $alsoTaken) as $c) {
            $taken[mb_strtolower((string) $c)] = true;
            if (preg_match('/^(.*?)(\d+)$/', (string) $c, $m)) {
                $f = &$families[$m[1]];
                $f = ['n' => max($f['n'] ?? 0, (int) $m[2]), 'w' => max($f['w'] ?? 0, strlen($m[2])), 'count' => ($f['count'] ?? 0) + 1];
                unset($f);
            }
        }
        $prefix = 'E'; $n = 0; $w = 3; $best = 0;
        foreach ($families as $pre => $f) {
            if ($f['count'] > $best) { $best = $f['count']; $prefix = (string) $pre; $n = $f['n']; $w = $f['w']; }
        }
        do { $code = $prefix . str_pad((string) (++$n), $w, '0', STR_PAD_LEFT); } while (isset($taken[mb_strtolower($code)]));
        return $code;
    }

    /**
     * @param list<array<string,mixed>> $rows each: employee_id?, code, full_name, department (name) or department_id, worker_code,
     *        pay_type?, basic_paisa?, allowance_paisa?, daily_rate_paisa?, ot_rate_paisa?, pay_method?, bank_account?, opening_paisa?
     */
    public static function saveStaff(array $rows, string $reason, array $caps, bool $mayMaster, int $actorId): array
    {
        if ($rows === [] || count($rows) > 500) {
            return self::no('VALIDATION', $rows === [] ? 'There is nothing to save.' : 'At most 500 people at a time.');
        }
        $depts = [];
        foreach (Db::all('SELECT id, name FROM att_departments') as $d) { $depts[mb_strtolower(trim((string) $d['name']))] = (int) $d['id']; }
        $created = 0; $updated = 0; $paySaved = 0; $opened = 0; $errors = []; $given = [];
        foreach ($rows as $row) {
            $id = (int) ($row['employee_id'] ?? 0);
            $name = trim((string) ($row['full_name'] ?? ''));
            $who = $name !== '' ? $name : ($id > 0 ? 'Employee #' . $id : 'A new line');
            $fail = static function (string $msg) use (&$errors, $who): void { $errors[] = $who . ': ' . $msg; };

            // ---- the person
            $before = $id > 0 ? Db::one('SELECT * FROM att_employees WHERE id = ?', [$id]) : null;
            if ($id > 0 && $before === null) { $fail('no longer exists.'); continue; }
            if ($before !== null && !self::inScope($caps['departments'], (int) $before['department_id'])) { $fail('is not yours to change.'); continue; }
            $deptId = (int) ($row['department_id'] ?? 0);
            $deptName = trim((string) ($row['department'] ?? ''));
            if ($deptId <= 0 && $deptName !== '') {
                $deptId = $depts[mb_strtolower($deptName)] ?? 0;
                if ($deptId <= 0) {
                    if (!$mayMaster) { $fail('there is no department called "' . $deptName . '".'); continue; }
                    if (mb_strlen($deptName) > 120) { $fail('the department name is too long.'); continue; }
                    $deptId = Employees::createDepartment($deptName, $actorId);
                    $depts[mb_strtolower($deptName)] = $deptId;
                }
            }
            if ($deptId <= 0 && $before !== null) { $deptId = (int) $before['department_id']; }
            $code = trim((string) ($row['code'] ?? ''));
            if ($code === '') { $code = $before !== null ? (string) $before['code'] : self::nextCode($given); }
            $worker = array_key_exists('worker_code', $row) ? trim((string) $row['worker_code']) : (string) ($before['worker_code'] ?? '');
            $qr = array_key_exists('qr_payload', $row) ? trim((string) $row['qr_payload']) : (string) ($before['qr_payload'] ?? '');
            if ($name === '' && $before !== null) { $name = (string) $before['full_name']; }

            $masterChanged = $before === null
                || $code !== (string) $before['code'] || $name !== (string) $before['full_name']
                || $deptId !== (int) $before['department_id'] || $worker !== (string) ($before['worker_code'] ?? '') || $qr !== (string) ($before['qr_payload'] ?? '');
            if ($masterChanged) {
                if (!$mayMaster) { $fail($before === null ? 'you cannot add employees. Ask HR or an admin.' : 'you can set pay, but not change the name, code or department.'); continue; }
                $r = AdminService::saveEmployee($before === null ? null : $id,
                    ['code' => $code, 'full_name' => $name, 'department_id' => $deptId, 'worker_code' => $worker, 'qr_payload' => $qr], $actorId);
                if (!$r['ok']) { $fail((string) $r['error']); continue; }
                if ($before === null) { $created++; $given[] = $code; } else { $updated++; }
                $id = (int) $r['employee_id'];
            }

            // ---- how they are paid
            $type = (string) ($row['pay_type'] ?? '');
            if ($type !== '') {
                if (!$caps['setup']) { $fail('saved, but you cannot set pay.'); continue; }
                $new = ['pay_type' => $type];
                foreach (['basic_paisa', 'allowance_paisa', 'daily_rate_paisa', 'ot_rate_paisa'] as $k) { $new[$k] = $row[$k] ?? 0; }
                $new['pay_method'] = ($row['pay_method'] ?? 'cash') === 'bank' ? 'bank' : 'cash';
                $new['bank_account'] = mb_substr(trim((string) ($row['bank_account'] ?? '')), 0, 60);
                $old = Db::one('SELECT * FROM att_fin_profiles WHERE employee_id = ?', [$id]);
                $same = $old !== null && $old['pay_type'] === $type && $old['pay_method'] === $new['pay_method'] && (string) ($old['bank_account'] ?? '') === $new['bank_account'];
                foreach (['basic_paisa', 'allowance_paisa', 'daily_rate_paisa', 'ot_rate_paisa'] as $k) {
                    $same = $same && self::whole($new[$k]) && (int) $old[$k] === (int) $new[$k];
                }
                if (!$same) {
                    $r = self::saveProfile($id, $new, $reason, $actorId);
                    if (!$r['ok']) { $fail('pay not saved — ' . $r['error']); continue; }
                    $paySaved++;
                }
            }

            // ---- what they were owed on day one
            $open = $row['opening_paisa'] ?? null;
            if ($open !== null && $open !== '') {
                if (!$caps['pay']) { $fail('saved, but only Accounts or an admin can enter an opening balance.'); continue; }
                if (!self::whole($open)) { $fail('the opening balance must be a number.'); continue; }
                $has = Db::value("SELECT amount_paisa FROM att_fin_ledger WHERE source_key = ?", ['open:' . $id]);
                if ($has === null || $has === false ? (int) $open > 0 : (int) $has !== (int) $open) {
                    $r = self::setOpening($id, (int) $open, trim($reason) !== '' ? $reason : 'Entered with the employee list', $actorId);
                    if (!$r['ok']) { $fail('opening balance not saved — ' . $r['error']); continue; }
                    $opened++;
                }
            }
        }
        $done = $created + $updated + $paySaved + $opened;
        return ['ok' => $done > 0 || $errors === [], 'created' => $created, 'updated' => $updated, 'pay_saved' => $paySaved, 'opening_saved' => $opened, 'errors' => $errors,
                'error' => $done === 0 && $errors !== [] ? implode(' ', array_slice($errors, 0, 3)) : null];
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

    /**
     * THE LEDGER IN ONE PLACE: every person this user may see, with what they
     * were owed before the first day, what they earned, had deducted and were
     * paid between the two days, and what they are owed at the end.
     */
    public static function ledgerSummary(array $caps, string $from, string $to): array
    {
        if (!self::isDate($from) || !self::isDate($to) || $to < $from) {
            return self::no('VALIDATION', 'Give the first and last day.');
        }
        self::syncProduction();
        [$w, $p] = self::deptWhere($caps['departments']);
        $rows = Db::all(
            "SELECT e.id AS employee_id, e.code, e.full_name, d.name AS department_name, f.pay_type,
                    COALESCE(SUM(CASE WHEN l.entry_date <  ? THEN IF(l.kind IN ('opening','earning'), l.amount_paisa, -l.amount_paisa) END), 0) AS before_paisa,
                    COALESCE(SUM(CASE WHEN l.entry_date BETWEEN ? AND ? AND l.kind IN ('opening','earning') THEN l.amount_paisa END), 0) AS earned,
                    COALESCE(SUM(CASE WHEN l.entry_date BETWEEN ? AND ? AND l.kind = 'deduction' THEN l.amount_paisa END), 0) AS deducted,
                    COALESCE(SUM(CASE WHEN l.entry_date BETWEEN ? AND ? AND l.kind = 'payment' THEN l.amount_paisa END), 0) AS paid,
                    COUNT(l.id) AS line_count
               FROM att_employees e
               JOIN att_departments d ON d.id = e.department_id
               LEFT JOIN att_fin_profiles f ON f.employee_id = e.id
               LEFT JOIN att_fin_ledger l ON l.employee_id = e.id AND l.cancelled_at IS NULL AND l.entry_date <= ?
              WHERE 1 = 1 $w" . ($caps['see_salary'] ? '' : " AND COALESCE(f.pay_type, '') <> 'salary'") . '
              GROUP BY e.id, e.code, e.full_name, d.name, f.pay_type
             HAVING COUNT(l.id) > 0
              ORDER BY e.full_name LIMIT 5000',
            array_merge([$from, $from, $to, $from, $to, $from, $to, $to], $p));
        $t = ['before_paisa' => 0, 'earned' => 0, 'deducted' => 0, 'paid' => 0, 'closing_paisa' => 0];
        foreach ($rows as &$r) {
            foreach (['before_paisa', 'earned', 'deducted', 'paid'] as $k) { $r[$k] = (int) $r[$k]; }
            $r['closing_paisa'] = $r['before_paisa'] + $r['earned'] - $r['deducted'] - $r['paid'];
            unset($r['line_count']);
            foreach ($t as $k => $_) { $t[$k] += $r[$k]; }
        }
        unset($r);
        return ['ok' => true, 'from' => $from, 'to' => $to, 'rows' => $rows, 'totals' => $t];
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
            'SELECT l.id, l.entry_date, l.kind, l.line_kind, l.label, l.amount_paisa, l.method, l.source, l.cancelled_at, l.cancel_reason, l.sheet_id, s.ref AS sheet_ref
               FROM att_fin_ledger l LEFT JOIN att_fin_sheets s ON s.id = l.sheet_id
              WHERE l.employee_id = ? ORDER BY l.entry_date, l.id LIMIT 3000', [$employeeId]);
        $bal = 0;
        foreach ($lines as &$l) {
            $l['amount_paisa'] = (int) $l['amount_paisa'];
            if ($l['cancelled_at'] === null) {
                $bal += in_array($l['kind'], ['opening', 'earning'], true) ? $l['amount_paisa'] : -$l['amount_paisa'];
            }
            $l['balance_paisa'] = $bal;
            $l['ref'] = self::lineRef($l);
        }
        unset($l);
        return ['ok' => true, 'employee' => $e, 'lines' => $lines, 'balance_paisa' => $bal,
                'advances' => self::advancesFor($employeeId)];
    }

    /**
     * A reference for one statement line: what it is, the month, its number.
     * SAL-2611-000042 is a basic-salary line of November 2026, ledger line 42.
     * The number is the line's own id, so it never changes and never repeats.
     */
    public static function lineRef(array $l): string
    {
        $map = ['basic' => 'SAL', 'allowance' => 'ALW', 'daily' => 'WAG', 'overtime' => 'OT', 'production' => 'PRD', 'bonus' => 'BON', 'other_earning' => 'ERN',
                'leave' => 'LVE', 'deduction' => 'DED', 'advance' => 'REC', 'loan' => 'LREC'];
        $kind = (string) ($l['kind'] ?? '');
        $pre = $kind === 'payment' ? 'PAY' : ($kind === 'opening' ? 'OPN' : ($map[(string) ($l['line_kind'] ?? '')] ?? ($kind === 'deduction' ? 'DED' : 'ERN')));
        return $pre . '-' . substr(str_replace('-', '', (string) ($l['entry_date'] ?? '')), 2, 4) . '-' . str_pad((string) (int) ($l['id'] ?? 0), 6, '0', STR_PAD_LEFT);
    }

    /**
     * One person's statement between two days, the way an accountant lays it
     * out: the balance brought forward, each line with what it added, took
     * away or paid and the balance after it, then the totals of the period.
     * Cancelled lines are left out. Amounts in paisa.
     */
    public static function statement(int $employeeId, string $from, string $to, array $caps): array
    {
        if (!self::isDate($from) || !self::isDate($to) || $to < $from) {
            return self::no('VALIDATION', 'Give the first and last day.');
        }
        $lg = self::ledger($employeeId, $caps);
        if (!$lg['ok']) {
            return $lg;
        }
        $bal = 0; $rows = []; $t = ['earned' => 0, 'deducted' => 0, 'paid' => 0];
        foreach ($lg['lines'] as $l) {
            if ($l['cancelled_at'] !== null || $l['entry_date'] > $to) { continue; }
            $plus = in_array($l['kind'], ['opening', 'earning'], true);
            if ($l['entry_date'] < $from) { $bal += $plus ? $l['amount_paisa'] : -$l['amount_paisa']; continue; }
            if (!isset($opening)) { $opening = $bal; }
            $bal += $plus ? $l['amount_paisa'] : -$l['amount_paisa'];
            $e = $plus ? $l['amount_paisa'] : 0; $d = $l['kind'] === 'deduction' ? $l['amount_paisa'] : 0; $p = $l['kind'] === 'payment' ? $l['amount_paisa'] : 0;
            $t['earned'] += $e; $t['deducted'] += $d; $t['paid'] += $p;
            $rows[] = ['date' => $l['entry_date'], 'ref' => $l['ref'], 'label' => $l['label'] . ($l['sheet_ref'] && !str_contains((string) $l['label'], (string) $l['sheet_ref']) ? ' · ' . $l['sheet_ref'] : ''),
                       'earned' => $e, 'deducted' => $d, 'paid' => $p, 'balance' => $bal, 'sheet_id' => $l['sheet_id'] === null ? null : (int) $l['sheet_id'],
                       'method' => $l['method'], 'kind' => $l['kind'], 'line_kind' => $l['line_kind']];
        }
        return ['ok' => true, 'employee' => $lg['employee'], 'from' => $from, 'to' => $to, 'opening_paisa' => $opening ?? $bal, 'rows' => $rows,
                'totals' => $t, 'closing_paisa' => $bal];
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
        $rows = Db::all("SELECT id, employee_id, kind, amount_paisa, per_pay_paisa, recovered_paisa, given_on, method, note, status FROM att_fin_advances
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

    /**
     * Change how much of an advance or loan is recovered from each pay — or
     * pause it (0). What was given and what was recovered never change here:
     * recovering more at once is an "Advance/Loan recovery" deduction entry,
     * giving more is a new advance. The reason is kept in the audit trail.
     */
    public static function changeAdvance(int $id, mixed $perPay, string $reason, int $actorId): array
    {
        if (trim($reason) === '') {
            return self::no('REASON_REQUIRED', 'Say why the plan changes.');
        }
        return Db::tx(static function () use ($id, $perPay, $reason, $actorId) {
            $a = Db::one('SELECT * FROM att_fin_advances WHERE id = ? FOR UPDATE', [$id]);
            if ($a === null) {
                return self::no('NOT_FOUND', 'No such advance or loan.');
            }
            if ($a['status'] !== 'active') {
                return self::no('ALREADY_DECIDED', 'Only an advance or loan still being recovered can be changed.');
            }
            $left = (int) $a['amount_paisa'] - (int) $a['recovered_paisa'];
            if (!self::whole($perPay) || (int) $perPay > $left) {
                return self::no('VALIDATION', 'From each pay: between 0 (to pause) and the Rs ' . self::rupees($left) . ' still owed.');
            }
            Db::run('UPDATE att_fin_advances SET per_pay_paisa = ? WHERE id = ?', [(int) $perPay, $id]);
            Audit::record('fin.advance_change', 'att_fin_advance', $id, ['per_pay_paisa' => (int) $a['per_pay_paisa']], ['per_pay_paisa' => (int) $perPay], $actorId, mb_substr(trim($reason), 0, 255));
            return ['ok' => true, 'advance_id' => $id, 'per_pay_paisa' => (int) $perPay];
        });
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
        $carry = self::owedFree($id);
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
        if ($status !== null && in_array($status, ['waiting', 'approved', 'paid', 'returned', 'cancelled', 'posted'], true)) { $w .= ' AND s.status = ?'; $p[] = $status; }
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
            if ($s['kind'] === 'entry') {
                return self::no('VALIDATION', 'An entry is not amended. Send it back and enter it again.');
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

    public static function approve(int $id, array $caps, int $actorId, bool $direct = false): array
    {
        if (!$direct && !$caps['approve']) {
            return self::no('FORBIDDEN', 'Only Admin or Audit can approve a pay sheet.');
        }
        return Db::tx(static function () use ($id, $caps, $actorId, $direct) {
            $s = Db::one('SELECT * FROM att_fin_sheets WHERE id = ? FOR UPDATE', [$id]);
            if ($s === null) {
                return self::no('NOT_FOUND', 'No such pay sheet.');
            }
            if ($s['status'] !== 'waiting') {
                return self::no('ALREADY_DECIDED', 'This sheet is already ' . $s['status'] . '.');
            }
            if (!$direct && (int) $s['prepared_by'] === $actorId && !$caps['admin']) {
                return self::no('FORBIDDEN', 'You prepared this sheet, so someone else must approve it.');
            }
            $date = min((string) $s['period_to'], self::today());
            $lines = Db::all('SELECT l.*, i.employee_id, e.full_name FROM att_fin_item_lines l JOIN att_fin_sheet_items i ON i.id = l.item_id JOIN att_employees e ON e.id = i.employee_id
                               WHERE i.sheet_id = ? ORDER BY l.item_id, l.sort', [$id]);
            // Things may have moved since it was prepared. Check all of it before posting any of it.
            $taking = [];
            foreach ($lines as $l) {
                $amt = (int) $l['amount_paisa'];
                if ($l['side'] === 'carry' && $amt > self::owedFree((int) $l['employee_id'], $id, false)) {
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
            // An entry that takes money off must not leave anybody owing the company.
            if ($s['kind'] === 'entry') {
                $effect = [];
                foreach ($lines as $l) {
                    if ($l['side'] !== 'carry') {
                        $effect[(int) $l['employee_id']] = ($effect[(int) $l['employee_id']] ?? 0) + ($l['side'] === 'deduct' ? -1 : 1) * (int) $l['amount_paisa'];
                    }
                }
                foreach ($effect as $emp => $net) {
                    if ($net < 0 && -$net > self::owedFree($emp, $id, false)) {
                        return self::no('STALE', self::nameOf($emp) . ': less is owed now than this deduction. Send the entry back.');
                    }
                }
            }
            $hasPay = false;
            foreach ($lines as $l) {
                $amt = (int) $l['amount_paisa'];
                $hasPay = $hasPay || ($l['side'] === 'carry' && $amt > 0);
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
            // An entry of earnings or deductions has nothing to hand over: it is posted and done.
            $status = $s['kind'] === 'entry' && !$hasPay ? 'posted' : 'approved';
            Db::run('UPDATE att_fin_sheets SET status = ?, approved_by = ?, approved_at = ? WHERE id = ?', [$status, $actorId, Time::sql(), $id]);
            Audit::record('fin.sheet_approve', 'att_fin_sheet', $id, ['status' => 'waiting'], ['status' => $status], $actorId);
            return ['ok' => true, 'sheet_id' => $id, 'status' => $status];
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
            if (!in_array($s['status'], ['approved', 'paid', 'posted'], true)) {
                return self::no('ALREADY_DECIDED', $s['status'] === 'waiting' ? 'A sheet still waiting is sent back, not cancelled.' : 'This sheet is already ' . $s['status'] . '.');
            }
            if ($s['status'] === 'paid' ? !$caps['admin'] : !$caps['approve']) {
                return self::no('FORBIDDEN', $s['status'] === 'paid' ? 'Only an administrator can cancel a sheet that has been paid.' : 'Only Admin or Audit can cancel an approved sheet or a posted entry.');
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

    // ------------------------------------------------------------- entries

    /**
     * ADD EARNING, ADD DEDUCTION, RECORD PAYMENT — from the Employee Ledger,
     * for one person or many at once. Each is an ENTRY SHEET (kind 'entry',
     * reference ENT-…) and goes the same road as a pay sheet: it waits, and a
     * second person approves it. Nothing reaches the ledger before that.
     *
     *   earning / deduction  posted to the ledger on approval; the sheet is
     *                        then 'posted' — what is owed is paid on the next
     *                        pay sheet ("Balance owed from before") or by a
     *                        payment entry.
     *   payment              pays part or all of what is owed: approved, then
     *                        Accounts records it, exactly like a pay sheet.
     *
     * Overtime and unpaid leave that Attendance knows about are already worked
     * out on the pay sheet. An entry is for what Attendance does not know.
     */
    public const ENTRY_TYPES = [
        'overtime' => ['earn', 'Overtime'], 'production' => ['earn', 'Production incentive'], 'bonus' => ['earn', 'Bonus'],
        'allowance' => ['earn', 'Allowance'], 'other_earning' => ['earn', 'Other earning'],
        'leave' => ['deduct', 'Unpaid leave'], 'advance' => ['deduct', 'Advance recovery'], 'loan' => ['deduct', 'Loan recovery'], 'deduction' => ['deduct', 'Other deduction'],
    ];

    /**
     * What a person is owed and has not already been promised by a live sheet:
     * the ledger balance, less what approved sheets will pay them, less what
     * waiting sheets mean to pay out of that balance. Without this, two sheets
     * could both pay the same money.
     */
    public static function owedFree(int $employeeId, ?int $exceptSheet = null, bool $countWaiting = true): int
    {
        $x = $exceptSheet ?? 0;
        $approved = (int) Db::value(
            "SELECT COALESCE(SUM(i.amount_paisa), 0) FROM att_fin_sheet_items i JOIN att_fin_sheets s ON s.id = i.sheet_id
              WHERE i.employee_id = ? AND s.status = 'approved' AND i.amount_paisa > 0 AND s.id <> ?", [$employeeId, $x]);
        $waiting = $countWaiting ? (int) Db::value(
            "SELECT COALESCE(SUM(l.amount_paisa), 0) FROM att_fin_item_lines l JOIN att_fin_sheet_items i ON i.id = l.item_id JOIN att_fin_sheets s ON s.id = i.sheet_id
              WHERE i.employee_id = ? AND s.status = 'waiting' AND l.side = 'carry' AND s.id <> ?", [$employeeId, $x]) : 0;
        return self::balance($employeeId) - $approved - $waiting;
    }

    /** For the entry form: how a person is paid, what is owed and free to pay, and their open advances. */
    public static function entryInfo(int $employeeId, array $caps): array
    {
        $e = Db::one('SELECT e.id, e.code, e.full_name, e.department_id, d.name AS department_name, f.pay_type, f.pay_method
                        FROM att_employees e JOIN att_departments d ON d.id = e.department_id LEFT JOIN att_fin_profiles f ON f.employee_id = e.id WHERE e.id = ?', [$employeeId]);
        if ($e === null || !self::inScope($caps['departments'], (int) $e['department_id']) || (!$caps['see_salary'] && $e['pay_type'] === 'salary')) {
            return self::no('NOT_FOUND', 'No such employee.');
        }
        self::syncProduction();
        return ['ok' => true, 'employee_id' => (int) $e['id'], 'pay_type' => $e['pay_type'], 'method' => $e['pay_method'] ?: 'cash',
                'balance_paisa' => self::balance($employeeId), 'free_paisa' => self::owedFree($employeeId), 'may_enter' => self::mayEnter($caps, $e),
                'advances' => array_values(array_filter(self::advancesFor($employeeId), static fn($a) => $a['status'] === 'active' && $a['left_paisa'] > 0))];
    }

    private static function mayEnter(array $caps, array $e): bool
    {
        $type = (string) ($e['pay_type'] ?? '');
        if ($type === 'salary') {
            return (bool) $caps['salary'];
        }
        if (!in_array($type, ['daily', 'contract'], true)) {
            return false;
        }
        return !empty($caps['adjust']) || ($caps['wage'] && self::inScope($caps['wage_departments'], (int) $e['department_id']));
    }

    private static function nameOf(int $employeeId): string
    {
        return (string) (Db::value('SELECT full_name FROM att_employees WHERE id = ?', [$employeeId]) ?: 'Someone');
    }

    /**
     * @param list<array<string,mixed>> $rows employee_id, amount_paisa, and for an earning or a
     *        deduction: type, reason, label?, advance_id?; for a payment: method?
     */
    public static function createEntry(string $mode, string $date, array $rows, string $reason, array $caps, int $actorId, bool $approveNow = false): array
    {
        try {
            return self::createEntryTx($mode, $date, $rows, $reason, $caps, $actorId, $approveNow);
        } catch (FinStop $e) {
            return self::no('VALIDATION', $e->getMessage());        // everything was rolled back
        }
    }

    private static function createEntryTx(string $mode, string $date, array $rows, string $reason, array $caps, int $actorId, bool $approveNow): array
    {
        if (!in_array($mode, ['earning', 'deduction', 'payment'], true)) {
            return self::no('VALIDATION', 'Choose an earning, a deduction or a payment.');
        }
        if (!$caps['salary'] && !$caps['wage']) {
            return self::no('FORBIDDEN', 'You cannot make entries for anybody.');
        }
        if (!self::isDate($date) || $date > self::today()) {
            return self::no('VALIDATION', 'Give the date of the entry. It cannot be in the future.');
        }
        if ($rows === [] || count($rows) > 500) {
            return self::no('VALIDATION', 'Add at least one person.');
        }
        $reason = mb_substr(trim($reason), 0, 255);
        self::syncProduction();
        return Db::tx(static function () use ($mode, $date, $rows, $reason, $caps, $actorId, $approveNow) {
            $by = []; $taking = [];
            foreach (array_values($rows) as $i => $r) {
                $emp = (int) ($r['employee_id'] ?? 0);
                $e = Db::one('SELECT e.id, e.full_name, e.department_id, f.pay_type, f.pay_method FROM att_employees e
                                LEFT JOIN att_fin_profiles f ON f.employee_id = e.id WHERE e.id = ? FOR UPDATE', [$emp]);
                if ($e === null) {
                    return self::no('EMPLOYEE_NOT_FOUND', 'Line ' . ($i + 1) . ': choose the employee.');
                }
                $name = (string) $e['full_name'];
                if (!self::mayEnter($caps, $e)) {
                    return self::no('FORBIDDEN', $e['pay_type'] === null ? "$name has no pay set up yet. Set it up in Employees first." : "$name: you cannot make entries for this person.");
                }
                $amt = $r['amount_paisa'] ?? null;
                if (!self::whole($amt) || (int) $amt <= 0) {
                    return self::no('VALIDATION', "$name: give the amount.");
                }
                $amt = (int) $amt;
                $b = $by[$emp] ?? ['name' => $name, 'lines' => [], 'method' => $e['pay_method'] ?: 'cash'];
                $why = mb_substr(trim((string) ($r['reason'] ?? '')), 0, 255) ?: $reason;
                if ($mode === 'payment') {
                    if ($b['lines'] !== []) {
                        return self::no('VALIDATION', "$name is on the list twice.");
                    }
                    $free = self::owedFree($emp);
                    if ($amt > $free) {
                        return self::no('VALIDATION', "$name: only Rs " . self::rupees(max(0, $free)) . ' is owed and not already on a pay sheet.');
                    }
                    $b['lines'][] = ['key' => 'carry', 'kind' => 'carry', 'side' => 'carry', 'label' => $amt === $free ? 'Payment of the balance owed' : 'Part payment of the balance owed',
                                     'detail' => 'Rs ' . self::rupees($free) . ' owed when entered', 'calc_paisa' => $free, 'amount_paisa' => $amt, 'reason' => $why ?: null,
                                     'advance_id' => null, 'amended_by' => $actorId, 'sort' => 0];
                    if (in_array($r['method'] ?? null, ['bank', 'cash'], true)) {
                        $b['method'] = $r['method'];
                    }
                } else {
                    $t = (string) ($r['type'] ?? '');
                    if (!isset(self::ENTRY_TYPES[$t]) || (self::ENTRY_TYPES[$t][0] === 'earn') !== ($mode === 'earning')) {
                        return self::no('VALIDATION', "$name: choose what kind of " . ($mode === 'earning' ? 'earning' : 'deduction') . ' it is.');
                    }
                    if ($why === '') {
                        return self::no('REASON_REQUIRED', "$name: say what it is for.");
                    }
                    $adv = null;
                    if ($t === 'advance' || $t === 'loan') {
                        $a = Db::one('SELECT id, kind, amount_paisa - recovered_paisa AS left_paisa, status FROM att_fin_advances WHERE id = ? AND employee_id = ? FOR UPDATE',
                            [(int) ($r['advance_id'] ?? 0), $emp]);
                        if ($a === null || $a['status'] !== 'active' || $a['kind'] !== $t) {
                            return self::no('VALIDATION', "$name: choose which " . ($t === 'loan' ? 'loan' : 'advance') . ' is being recovered.');
                        }
                        $adv = (int) $a['id'];
                        $taking[$adv] = ($taking[$adv] ?? 0) + $amt;
                        if ($taking[$adv] > (int) $a['left_paisa']) {
                            return self::no('VALIDATION', "$name: only Rs " . self::rupees((int) $a['left_paisa']) . ' of that ' . $t . ' is still owed.');
                        }
                    }
                    $n = count($b['lines']);
                    $b['lines'][] = ['key' => 'e:' . ($n + 1), 'kind' => $t, 'side' => self::ENTRY_TYPES[$t][0],
                                     'label' => mb_substr(trim((string) ($r['label'] ?? '')), 0, 100) ?: self::ENTRY_TYPES[$t][1], 'detail' => 'entered by hand',
                                     'calc_paisa' => $amt, 'amount_paisa' => $amt, 'reason' => $why, 'advance_id' => $adv, 'amended_by' => $actorId, 'sort' => $n];
                }
                $by[$emp] = $b;
            }
            if ($mode === 'deduction') {
                foreach ($by as $emp => $b) {
                    $free = self::owedFree($emp); $take = -self::net($b['lines']);
                    if ($take > $free) {
                        return self::no('VALIDATION', $b['name'] . ': only Rs ' . self::rupees(max(0, $free)) . ' is owed to them now, so Rs ' . self::rupees($take)
                            . ' cannot be taken off. Deduct it on their next pay sheet instead.');
                    }
                }
            }
            $ref = self::nextRef('ENT');
            Db::run('INSERT INTO att_fin_sheets (ref, kind, period_from, period_to, prepared_by) VALUES (?,?,?,?,?)', [$ref, 'entry', $date, $date, $actorId]);
            $sheetId = Db::lastId(); $total = 0;
            foreach ($by as $emp => $b) {
                $net = self::net($b['lines']);
                Db::run('INSERT INTO att_fin_sheet_items (sheet_id, employee_id, calc_paisa, amount_paisa, method) VALUES (?,?,?,?,?)', [$sheetId, $emp, $net, $net, $b['method']]);
                self::storeLines(Db::lastId(), $b['lines']);
                $total += $net;
            }
            Audit::record('fin.entry_prepare', 'att_fin_sheet', $sheetId, null, ['ref' => $ref, 'mode' => $mode, 'date' => $date, 'people' => count($by), 'total_paisa' => $total], $actorId);
            /* WITHOUT A SECOND PERSON, in two cases only:
                 · a DEDUCTION entered by the office (Admin, Accounts, HR, Audit): an
                   adjustment, never money going out, and never more than is owed;
                 · anything the ADMINISTRATOR asks to approve now — and a payment is
                   then recorded as paid in the same step.
               Both are decided here, on the server, from who is signed in. */
            $status = 'waiting';
            $direct = $mode === 'deduction' && !empty($caps['direct_deduct']);
            if ($direct || ($approveNow && $caps['admin'])) {
                $r = self::approve($sheetId, $caps, $actorId, true);
                if (!$r['ok']) { throw new FinStop((string) $r['error']); }
                $status = (string) $r['status'];
                if ($mode === 'payment') {
                    $payDay = (int) (new DateTimeImmutable($date))->format('N') === Policy::int('fin_pay_weekday', 4);
                    $r = self::pay($sheetId, $date, $payDay ? '' : ($reason !== '' ? $reason : 'Paid at once by the administrator'), $caps, $actorId);
                    if (!$r['ok']) { throw new FinStop((string) $r['error']); }
                    $status = 'paid';
                }
                Audit::record('fin.entry_direct', 'att_fin_sheet', $sheetId, null, ['ref' => $ref, 'mode' => $mode, 'status' => $status, 'by' => $direct ? 'office deduction' : 'administrator'], $actorId);
            }
            return ['ok' => true, 'sheet_id' => $sheetId, 'ref' => $ref, 'mode' => $mode, 'people' => count($by), 'total_paisa' => $total, 'status' => $status];
        });
    }

    /**
     * TAKE ONE PERSON OFF A SHEET — a wrong line among many, without sending
     * the whole sheet back and entering everybody again.
     *
     *   waiting            the person simply leaves the sheet (nothing was in
     *                      the ledger yet). The approver, an admin, or whoever
     *                      prepared it.
     *   approved / posted  their lines are struck through in the ledger with
     *                      the reason, and what was recovered from their
     *                      advances is put back. Admin or Audit.
     *   paid               not here: money has been handed over. An admin
     *                      cancels the sheet.
     *
     * When the last person goes, the sheet is closed. A copy of what was
     * removed is kept in the audit trail.
     */
    public static function removeFromSheet(int $id, int $employeeId, string $reason, array $caps, int $actorId): array
    {
        $why = mb_substr(trim($reason), 0, 255);
        if ($why === '') {
            return self::no('REASON_REQUIRED', 'Say why this person is taken off — the reason is kept.');
        }
        return Db::tx(static function () use ($id, $employeeId, $why, $caps, $actorId) {
            $s = Db::one('SELECT * FROM att_fin_sheets WHERE id = ? FOR UPDATE', [$id]);
            if ($s === null) {
                return self::no('NOT_FOUND', 'No such pay sheet.');
            }
            $live = in_array($s['status'], ['approved', 'posted'], true);
            if ($s['status'] !== 'waiting' && !$live) {
                return self::no('ALREADY_DECIDED', $s['status'] === 'paid' ? 'This sheet has been paid. An administrator can cancel the whole sheet.' : 'This sheet is already ' . $s['status'] . '.');
            }
            if ($live ? !$caps['approve'] : (!$caps['approve'] && (int) $s['prepared_by'] !== $actorId)) {
                return self::no('FORBIDDEN', $live ? 'Only Admin or Audit can take someone off an approved sheet.' : 'Only an approver, or the person who prepared it, can take someone off a waiting sheet.');
            }
            $item = Db::one('SELECT i.*, e.full_name FROM att_fin_sheet_items i JOIN att_employees e ON e.id = i.employee_id WHERE i.sheet_id = ? AND i.employee_id = ? FOR UPDATE', [$id, $employeeId]);
            if ($item === null) {
                return self::no('NOT_FOUND', 'That person is not on this sheet.');
            }
            $lines = Db::all('SELECT line_key, kind, side, label, amount_paisa, reason, advance_id FROM att_fin_item_lines WHERE item_id = ? ORDER BY sort, id', [(int) $item['id']]);
            if ($live) {
                foreach (Db::all('SELECT advance_id, SUM(amount_paisa) AS paisa FROM att_fin_ledger WHERE sheet_id = ? AND employee_id = ? AND advance_id IS NOT NULL AND cancelled_at IS NULL GROUP BY advance_id', [$id, $employeeId]) as $r) {
                    Db::run("UPDATE att_fin_advances SET recovered_paisa = GREATEST(0, recovered_paisa - ?), status = IF(status = 'closed', 'active', status) WHERE id = ?", [(int) $r['paisa'], (int) $r['advance_id']]);
                }
                Db::run('UPDATE att_fin_ledger SET cancelled_at = ?, cancelled_by = ?, cancel_reason = ? WHERE sheet_id = ? AND employee_id = ? AND cancelled_at IS NULL', [Time::sql(), $actorId, $why, $id, $employeeId]);
            }
            Db::run('DELETE FROM att_fin_item_lines WHERE item_id = ?', [(int) $item['id']]);
            Db::run('DELETE FROM att_fin_sheet_items WHERE id = ?', [(int) $item['id']]);
            $left = (int) Db::value('SELECT COUNT(*) FROM att_fin_sheet_items WHERE sheet_id = ?', [$id]);
            if ($left === 0) {
                Db::run('UPDATE att_fin_sheets SET status = ?, returned_by = ?, returned_at = ?, return_reason = ? WHERE id = ?',
                    [$live ? 'cancelled' : 'returned', $actorId, Time::sql(), mb_substr('Everyone was taken off: ' . $why, 0, 255), $id]);
            }
            Audit::record('fin.sheet_remove_person', 'att_fin_sheet', $id, ['employee_id' => $employeeId, 'name' => $item['full_name'], 'amount_paisa' => (int) $item['amount_paisa'], 'was' => $s['status'], 'lines' => $lines],
                ['people_left' => $left], $actorId, $why);
            return ['ok' => true, 'sheet_id' => $id, 'people_left' => $left, 'closed' => $left === 0];
        });
    }

    // ------------------------------------------------- production adjustment

    /**
     * WHAT PRODUCTION BOOKED for each contractor between two days — pieces,
     * amount and every line (date, product, operation, pieces, rate) — for the
     * adjustment grid. For Admin, Accounts, HR and Audit, and a HOD for their
     * own department.
     */
    public static function productionBill(string $from, string $to, int $deptId, array $caps): array
    {
        if (empty($caps['adjust']) && empty($caps['wage'])) {
            return self::no('FORBIDDEN', 'Production adjustments are for Admin, Accounts, HR and Audit.');
        }
        if (!self::isDate($from) || !self::isDate($to) || $to < $from) {
            return self::no('VALIDATION', 'Give the first and last day.');
        }
        if (!Db::tableExists('zp_workers') || !Db::tableExists('zp_entries')) {
            return ['ok' => true, 'from' => $from, 'to' => $to, 'contractors' => []];
        }
        self::syncProduction();
        $scope = !empty($caps['adjust']) ? null : $caps['wage_departments'];
        $workers = self::contractWorkers();
        $out = [];
        foreach (Db::all("SELECT e.id AS employee_id, e.code, e.full_name, e.department_id, d.name AS department_name FROM att_fin_profiles f
                            JOIN att_employees e ON e.id = f.employee_id JOIN att_departments d ON d.id = e.department_id
                           WHERE f.pay_type = 'contract' AND e.active = 1 ORDER BY d.name, e.full_name") as $e) {
            $id = (int) $e['employee_id'];
            if (!isset($workers[$id]) || !self::inScope($scope, (int) $e['department_id']) || ($deptId > 0 && (int) $e['department_id'] !== $deptId)) {
                continue;
            }
            $lines = self::productionLines($workers[$id], $from, $to);
            $qty = 0.0; $paisa = 0;
            foreach ($lines as $l) { $qty += $l['qty']; $paisa += $l['amount_paisa']; }
            $out[] = ['employee_id' => $id, 'code' => $e['code'], 'full_name' => $e['full_name'], 'department_name' => $e['department_name'],
                      'booked_qty' => round($qty, 2), 'booked_paisa' => $paisa, 'owed_paisa' => self::owedFree($id), 'lines' => $lines];
        }
        return ['ok' => true, 'from' => $from, 'to' => $to, 'contractors' => $out];
    }

    /** @return list<array{id:int,date:string,what:string,qty:float,rate:float,amount_paisa:int}> */
    private static function productionLines(int $workerId, string $from, string $to, ?int $onlyId = null): array
    {
        $prod = Db::tableExists('products'); $ops = Db::tableExists('zp_part_ops');
        $rows = Db::all(
            'SELECT z.id, z.entry_date, z.qty, z.rate_applied, z.amount, ' . ($prod ? 'p.name' : 'NULL') . ' AS product_name, ' . ($ops ? 'o.operation_name' : 'NULL') . ' AS op_name
               FROM zp_entries z' . ($prod ? ' LEFT JOIN products p ON p.id = z.product_id' : '') . ($ops ? ' LEFT JOIN zp_part_ops o ON o.id = z.op_id' : '') . "
              WHERE z.worker_id = ? AND z.status = 'active' AND z.entry_date BETWEEN ? AND ?" . ($onlyId !== null ? ' AND z.id = ?' : '') . '
              ORDER BY z.entry_date, z.id LIMIT 2000', $onlyId !== null ? [$workerId, $from, $to, $onlyId] : [$workerId, $from, $to]);
        return array_map(static fn($r) => ['id' => (int) $r['id'], 'date' => (string) $r['entry_date'],
            'what' => trim(implode(' · ', array_filter([(string) ($r['product_name'] ?? ''), (string) ($r['op_name'] ?? '')]))) ?: 'Production',
            'qty' => (float) $r['qty'], 'rate' => (float) $r['rate_applied'], 'amount_paisa' => (int) round((float) $r['amount'] * 100)], $rows);
    }

    /**
     * Save a grid of production adjustments. Every figure is worked out HERE,
     * from what Production holds — the browser sends only what was typed:
     *
     *   line     a booked line with its corrected pieces and/or rate; the
     *            adjustment is corrected pieces x corrected rate, less what
     *            was booked
     *   pieces   pieces (+ or -) x a rate
     *   fixed    an amount (+ or -)
     *   percent  a percentage (+ or -) of what was booked between the dates
     *
     * What Production booked is never changed. What is taken off becomes one
     * deduction entry, what is added one earning entry (the same entries as
     * Add deduction / Add earning, with the same rules: a deduction never more
     * than is owed, posted at once for the office; an earning waits, unless
     * the administrator approves it in the same step). All of it or none.
     *
     * @param list<array<string,mixed>> $rows employee_id, reason?, parts: list of {kind, ...}
     */
    public static function adjustProduction(string $from, string $to, array $rows, string $reason, array $caps, int $actorId, bool $approveNow = false): array
    {
        if (empty($caps['adjust']) && empty($caps['wage'])) {
            return self::no('FORBIDDEN', 'Production adjustments are for Admin, Accounts, HR and Audit.');
        }
        if (!self::isDate($from) || !self::isDate($to) || $to < $from) {
            return self::no('VALIDATION', 'Give the first and last day.');
        }
        if ($rows === [] || count($rows) > 500) {
            return self::no('VALIDATION', 'Adjust at least one contractor.');
        }
        $reason = mb_substr(trim($reason), 0, 255);
        $workers = Db::tableExists('zp_workers') ? self::contractWorkers() : [];
        $money = static fn(int $p): string => ($p < 0 ? '−' : '') . self::rupees(abs($p));
        $num = static fn($v): ?float => is_numeric($v) ? (float) $v : null;
        $take = []; $give = [];
        foreach (array_values($rows) as $row) {
            $emp = (int) ($row['employee_id'] ?? 0);
            $name = self::nameOf($emp);
            if (!isset($workers[$emp])) {
                return self::no('VALIDATION', "$name is not a contractor linked to Production.");
            }
            $why = mb_substr(trim((string) ($row['reason'] ?? '')), 0, 255) ?: $reason;
            foreach ((array) ($row['parts'] ?? []) as $part) {
                $kind = (string) ($part['kind'] ?? ''); $paisa = 0; $label = 'Production adjustment';
                if ($kind === 'line') {
                    $l = self::productionLines($workers[$emp], '1900-01-01', '2999-12-31', (int) ($part['entry_id'] ?? 0))[0] ?? null;
                    $q = $num($part['qty'] ?? null); $r = $num($part['rate'] ?? null);
                    if ($l === null) { return self::no('VALIDATION', "$name: one of the production lines is no longer there. Open the grid again."); }
                    if ($q === null || $r === null || $q < 0 || $r < 0) { return self::no('VALIDATION', "$name: give the correct pieces and rate as numbers."); }
                    $paisa = (int) round($q * $r * 100) - $l['amount_paisa'];
                    $label .= ' · ' . (new DateTimeImmutable($l['date']))->format('j M') . ' ' . $l['what'] . ': ' . self::num($l['qty']) . ' × ' . self::num($l['rate']) . ' → ' . self::num($q) . ' × ' . self::num($r);
                } elseif ($kind === 'pieces') {
                    $q = $num($part['qty'] ?? null); $r = $num($part['rate'] ?? null);
                    if ($q === null || $r === null || $r < 0) { return self::no('VALIDATION', "$name: give the pieces and the rate as numbers."); }
                    $paisa = (int) round($q * $r * 100);
                    $label .= ' · ' . ($q < 0 ? '−' : '') . self::num(abs($q)) . ' × ' . self::num($r);
                } elseif ($kind === 'fixed') {
                    $a = $part['amount_paisa'] ?? null;
                    if (!is_int($a) && !(is_string($a) && preg_match('/^-?\d{1,13}$/', $a) === 1)) { return self::no('VALIDATION', "$name: give the amount as a number."); }
                    $paisa = (int) $a;
                } elseif ($kind === 'percent') {
                    $pc = $num($part['pct'] ?? null);
                    if ($pc === null || abs($pc) > 100) { return self::no('VALIDATION', "$name: a percentage is between −100 and 100."); }
                    $booked = array_sum(array_column(self::productionLines($workers[$emp], $from, $to), 'amount_paisa'));
                    $paisa = (int) round($booked * $pc / 100);
                    $label .= ' · ' . self::num(abs($pc)) . '% of ' . self::rupees($booked);      // taken off or added: the entry says which
                } else {
                    return self::no('VALIDATION', "$name: that adjustment was not understood.");
                }
                if ($paisa === 0) { continue; }
                if ($why === '') { return self::no('REASON_REQUIRED', "$name: say why (" . $money($paisa) . ').'); }
                $line = ['employee_id' => $emp, 'label' => mb_substr($label, 0, 100), 'amount_paisa' => abs($paisa), 'reason' => $why];
                if ($paisa < 0) { $take[] = $line + ['type' => 'deduction']; } else { $give[] = $line + ['type' => 'other_earning']; }
            }
        }
        if ($take === [] && $give === []) {
            return self::no('VALIDATION', 'Nothing was changed: every adjustment comes to zero.');
        }
        $date = min($to, self::today());
        try {
            return Db::tx(static function () use ($take, $give, $date, $reason, $caps, $actorId, $approveNow) {
                $out = ['ok' => true, 'taken_off' => null, 'added' => null];
                foreach ([['taken_off', 'deduction', $take], ['added', 'earning', $give]] as [$key, $mode, $lines]) {
                    if ($lines === []) { continue; }
                    $r = self::createEntry($mode, $date, $lines, $reason, $caps, $actorId, $approveNow);
                    if (!$r['ok']) { throw new FinStop((string) $r['error']); }
                    $out[$key] = ['ref' => $r['ref'], 'status' => $r['status'], 'people' => $r['people'], 'total_paisa' => abs((int) $r['total_paisa']), 'sheet_id' => $r['sheet_id']];
                }
                return $out;
            });
        } catch (FinStop $e) {
            return self::no('VALIDATION', $e->getMessage());
        }
    }

    // ------------------------------------------------------- mark attendance

    /**
     * MARK ATTENDANCE — a week of daily-wage workers on one screen, Full, Half
     * or Absent for each day, for Admin and HR. Each mark becomes an ordinary
     * attendance entry (Setup › Entries, source "admin") with the reason and
     * the name of who marked it, so daily wages count it exactly like a scan.
     *
     * Never touched here: a day the gate scanner recorded (that is fixed in
     * Setup › Entries, one entry at a time), a day already on a daily-wages
     * pay sheet (so no day is paid twice), and a day that has not come yet.
     */
    public static function weekGrid(string $from, int $deptId, array $caps): array
    {
        if (empty($caps['mark'])) {
            return self::no('FORBIDDEN', 'Marking attendance is for Admin and HR.');
        }
        if (!self::isDate($from)) {
            return self::no('VALIDATION', 'Choose the first day of the week.');
        }
        $to = (new DateTimeImmutable($from))->modify('+6 days')->format('Y-m-d');
        $days = [];
        for ($d = new DateTimeImmutable($from), $i = 0; $i < 7; $i++, $d = $d->modify('+1 day')) { $days[] = $d->format('Y-m-d'); }
        $half = max(1, Policy::int('fin_half_day_minutes', 240));
        $emps = Db::all(
            "SELECT e.id AS employee_id, e.code, e.full_name, d.name AS department_name, f.daily_rate_paisa
               FROM att_fin_profiles f JOIN att_employees e ON e.id = f.employee_id JOIN att_departments d ON d.id = e.department_id
              WHERE f.pay_type = 'daily' AND e.active = 1" . ($deptId > 0 ? ' AND e.department_id = ?' : '') . '
              ORDER BY d.name, e.full_name LIMIT 1000', $deptId > 0 ? [$deptId] : []);
        $state = self::dayStates(array_map(static fn($e) => (int) $e['employee_id'], $emps), $from, $to);
        $today = self::today();
        foreach ($emps as &$e) {
            $id = (int) $e['employee_id'];
            $e['employee_id'] = $id; $e['daily_rate_paisa'] = (int) $e['daily_rate_paisa'];
            $e['days'] = [];
            foreach ($days as $day) {
                $st = $state[$id][$day] ?? ['minutes' => 0, 'open' => 0, 'scanned' => 0, 'sheet' => null];
                $mark = ($st['minutes'] >= $half || $st['open'] > 0) ? 'F' : ($st['minutes'] > 0 ? 'H' : null);
                $lock = $day > $today ? 'future' : ($st['sheet'] !== null ? 'sheet' : ($st['scanned'] > 0 ? 'scan' : null));
                $e['days'][$day] = ['mark' => $mark, 'minutes' => $st['minutes'], 'lock' => $lock, 'sheet' => $st['sheet']];
            }
        }
        unset($e);
        [$fi, $fo] = self::defaultShiftTimes();
        $hi = $fi; $ho = (new DateTimeImmutable('2000-01-01 ' . $fi))->modify('+' . max(30, $half - 30) . ' minutes')->format('H:i');
        return ['ok' => true, 'from' => $from, 'to' => $to, 'days' => $days, 'today' => $today, 'half_minutes' => $half,
                'times' => ['full_in' => $fi, 'full_out' => $fo, 'half_in' => $hi, 'half_out' => $ho], 'workers' => $emps];
    }

    /** @return array<int,array<string,array{minutes:int,open:int,scanned:int,sheet:?string}>> */
    private static function dayStates(array $ids, string $from, string $to): array
    {
        $out = [];
        if ($ids === []) {
            return $out;
        }
        $in = implode(',', array_fill(0, count($ids), '?'));
        foreach (Db::all(
            "SELECT c.employee_id, si.work_date, COALESCE(SUM(c.present_minutes), 0) AS minutes, SUM(c.closed_at IS NULL) AS open_now,
                    SUM(c.gate_in_event_id IS NOT NULL OR COALESCE(c.in_source, 'gate') <> 'admin') AS scanned
               FROM att_entry_cycles c JOIN att_shift_instances si ON si.id = c.shift_instance_id
              WHERE c.employee_id IN ($in) AND si.work_date BETWEEN ? AND ? GROUP BY c.employee_id, si.work_date", array_merge($ids, [$from, $to])) as $r) {
            $out[(int) $r['employee_id']][(string) $r['work_date']] = ['minutes' => (int) $r['minutes'], 'open' => (int) $r['open_now'], 'scanned' => (int) $r['scanned'], 'sheet' => null];
        }
        foreach (Db::all(
            "SELECT i.employee_id, s.ref, s.period_from, s.period_to FROM att_fin_sheet_items i JOIN att_fin_sheets s ON s.id = i.sheet_id
              WHERE s.kind = 'daily' AND s.status IN ('waiting','approved','paid') AND s.period_from <= ? AND s.period_to >= ? AND i.employee_id IN ($in)",
            array_merge([$to, $from], $ids)) as $r) {
            for ($d = new DateTimeImmutable(max($from, (string) $r['period_from'])); $d->format('Y-m-d') <= min($to, (string) $r['period_to']); $d = $d->modify('+1 day')) {
                $k = $d->format('Y-m-d');
                $out[(int) $r['employee_id']][$k] = ($out[(int) $r['employee_id']][$k] ?? ['minutes' => 0, 'open' => 0, 'scanned' => 0, 'sheet' => null]);
                $out[(int) $r['employee_id']][$k]['sheet'] = (string) $r['ref'];
            }
        }
        return $out;
    }

    /** The default shift's start and end, or 08:00–18:00. @return array{0:string,1:string} */
    private static function defaultShiftTimes(): array
    {
        $id = (int) Policy::get('default_shift_id', '');
        $sh = $id > 0 ? Db::one('SELECT start_time, end_time FROM att_shifts WHERE id = ?', [$id]) : null;
        if ($sh === null) {
            return ['08:00', '18:00'];
        }
        return [substr((string) $sh['start_time'], 0, 5), substr((string) $sh['end_time'], 0, 5)];
    }

    /**
     * @param list<array<string,mixed>> $marks employee_id, day (Y-m-d), mark F|H|A — only the days that change
     * @param array<string,string> $times full_in, full_out, half_in, half_out (HH:MM, factory time)
     */
    public static function saveWeek(array $marks, array $times, string $reason, array $caps, int $actorId): array
    {
        if (empty($caps['mark'])) {
            return self::no('FORBIDDEN', 'Marking attendance is for Admin and HR.');
        }
        $reason = mb_substr(trim($reason), 0, 200);
        if ($reason === '') {
            return self::no('REASON_REQUIRED', 'Say why the days are marked by hand (for example: from the paper register).');
        }
        if ($marks === [] || count($marks) > 3000) {
            return self::no('VALIDATION', 'Mark at least one day.');
        }
        $t = [];
        foreach (['full_in', 'full_out', 'half_in', 'half_out'] as $k) {
            $v = (string) ($times[$k] ?? '');
            if (preg_match('/^([01]\d|2[0-3]):[0-5]\d$/', $v) !== 1) {
                return self::no('VALIDATION', 'Give the times of a full day and of a half day, like 08:00.');
            }
            $t[$k] = $v;
        }
        $half = max(1, Policy::int('fin_half_day_minutes', 240));
        $mins = static fn(string $a, string $b): int => (int) ((strtotime("2000-01-01 $b") - strtotime("2000-01-01 $a")) / 60);
        if ($mins($t['full_in'], $t['full_out']) < $half) {
            return self::no('VALIDATION', 'A full day must be at least ' . self::num($half / 60) . ' hours, or it counts as a half day.');
        }
        if ($mins($t['half_in'], $t['half_out']) <= 0 || $mins($t['half_in'], $t['half_out']) >= $half) {
            return self::no('VALIDATION', 'A half day must be shorter than ' . self::num($half / 60) . ' hours, or it counts as a full day.');
        }
        $why = 'Marked in the week grid: ' . $reason;
        $today = self::today();
        $saved = 0; $errors = [];
        foreach ($marks as $m) {
            $id = (int) ($m['employee_id'] ?? 0); $day = (string) ($m['day'] ?? ''); $mark = (string) ($m['mark'] ?? '');
            $e = Db::one("SELECT e.id, e.code, e.full_name FROM att_employees e JOIN att_fin_profiles f ON f.employee_id = e.id WHERE e.id = ? AND f.pay_type = 'daily' AND e.active = 1", [$id]);
            $who = $e !== null ? $e['full_name'] : 'Employee #' . $id;
            if ($e === null) { $errors[] = "$who is not a daily-wage worker on the roll."; continue; }
            if (!self::isDate($day) || !in_array($mark, ['F', 'H', 'A'], true)) { $errors[] = "$who: a day was not understood."; continue; }
            $label = $who . ', ' . (new DateTimeImmutable($day))->format('D j M');
            if ($day > $today) { $errors[] = "$label: that day has not come yet."; continue; }
            $st = self::dayStates([$id], $day, $day)[$id][$day] ?? ['minutes' => 0, 'open' => 0, 'scanned' => 0, 'sheet' => null];
            if ($st['sheet'] !== null) { $errors[] = "$label: already on pay sheet {$st['sheet']}. Use Add Earning for a missed day."; continue; }
            if ($st['scanned'] > 0) { $errors[] = "$label: scanned at the gate. Correct it in Setup › Entries."; continue; }
            $own = array_map(static fn($r) => (int) $r['id'], Db::all(
                "SELECT c.id FROM att_entry_cycles c JOIN att_shift_instances si ON si.id = c.shift_instance_id WHERE c.employee_id = ? AND si.work_date = ?", [$id, $day]));
            if ($mark === 'A' && $own === []) { continue; }
            try {
                Db::tx(static function () use ($own, $mark, $e, $day, $t, $why, $actorId) {
                    foreach ($own as $cid) {
                        $r = EntryAdmin::delete($cid, $why, $actorId);
                        if (!$r['ok']) { throw new \RuntimeException((string) $r['error']); }
                    }
                    if ($mark !== 'A') {
                        [$a, $b] = $mark === 'F' ? [$t['full_in'], $t['full_out']] : [$t['half_in'], $t['half_out']];
                        $r = EntryAdmin::add((string) $e['code'], Time::parse($day . 'T' . $a, 'Asia/Karachi'), Time::parse($day . 'T' . $b, 'Asia/Karachi'), $why, $actorId);
                        if (!$r['ok']) { throw new \RuntimeException((string) $r['error']); }
                    }
                });
                $saved++;
            } catch (\RuntimeException $x) {
                $errors[] = "$label: " . $x->getMessage();
            }
        }
        return ['ok' => true, 'saved' => $saved, 'errors' => $errors];
    }

    /** Home: what is owed, and how much is waiting at each step. */
    public static function overview(array $caps, int $userId): array
    {
        $people = self::people($caps)['people'];
        $t = ['owed' => 0, 'earned' => 0, 'paid' => 0];
        foreach ($people as $r) {
            if ($r['balance'] !== null) { $t['owed'] += max(0, (int) $r['balance']); $t['earned'] += (int) $r['earned']; $t['paid'] += (int) $r['paid']; }
        }
        $count = ['waiting' => 0, 'approved' => 0, 'paid' => 0, 'returned' => 0, 'cancelled' => 0, 'posted' => 0];
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

    private static function nextRef(string $prefix = 'PAY'): string
    {
        $day = $prefix . '-' . (new DateTimeImmutable(self::today()))->format('ymd') . '-';
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
