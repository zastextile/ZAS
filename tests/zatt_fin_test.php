<?php
declare(strict_types=1);

/* PEOPLE FINANCE — THE PAY ENGINE
 *
 * Three kinds of pay through one pipeline (prepare → approve → pay):
 *   - fixed salary: whole month, or custom dates = salary ÷ days in month × days
 *   - daily wages: days present from Attendance × rate, under 4 h is half a day
 *   - contractor: what Production booked and has not been paid
 * What must hold:
 *   - the server works out every figure; a browser only says which line to change
 *   - EACH LINE is amended by itself, with a reason, and keeps the worked-out figure
 *   - basic salary is never changed on a sheet
 *   - nobody is paid for the same day twice
 *   - who may prepare, approve and pay; nobody but an admin approves their own sheet
 *   - the ledger is the balance; a cancelled sheet is struck through, never deleted
 */

$B = __DIR__ . '/app_src/public_html/';
$P = 0; $F = 0;
function ok($c, $m) { global $P, $F; if ($c) { $P++; } else { $F++; echo "  FAIL: $m\n"; } }

$dsn = getenv('ATT_TEST_DSN') ?: 'mysql:host=127.0.0.1;port=3306;dbname=zas_att_test;charset=utf8mb4';
$dbu = getenv('ATT_TEST_USER') ?: 'zas';
$dbp = getenv('ATT_TEST_PASS'); if ($dbp === false) { $dbp = 'zaspw'; }
if (!is_file($B . 'config/config.php')) { echo "SKIP: no test config — run tests/run_att.sh\n"; exit(0); }
foreach (['Db','Time','Migrations','Policy','Audit','Roles','Employees','Shifts','Receipts','AttendanceService','HodService','Reconciler',
          'SyncService','OvertimeService','ReportService','HrService','AdminService','EntryAdmin','FinanceService'] as $c) {
    require_once $B . "includes/att/$c.php";
}
use Att\Db;
use Att\AdminService;
use Att\Employees;
use Att\FinanceService as Fin;
use Att\Migrations;
use Att\Policy;
use Att\Roles;
use Att\Shifts;
use Att\SyncService as S;

try {
    $pdo = new PDO($dsn, $dbu, $dbp, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC, PDO::ATTR_EMULATE_PREPARES => false]);
} catch (Throwable $e) { echo "SKIP: no MySQL available ({$e->getMessage()})\n"; exit(0); }
Db::bind($pdo);
Db::useUtc();
$pdo->exec('SET FOREIGN_KEY_CHECKS=0');
foreach ($pdo->query("SELECT table_name AS tn FROM information_schema.tables WHERE table_schema = DATABASE()")->fetchAll() as $t) {
    $pdo->exec('DROP TABLE IF EXISTS `' . $t['tn'] . '`');
}
$pdo->exec('SET FOREIGN_KEY_CHECKS=1');
$pdo->exec("CREATE TABLE users (id INT AUTO_INCREMENT PRIMARY KEY, name VARCHAR(150) NOT NULL,
            email VARCHAR(190) NOT NULL UNIQUE, password_hash VARCHAR(255) NOT NULL,
            role VARCHAR(40) NOT NULL DEFAULT 'colleague', department VARCHAR(60) NULL,
            can_see_rates TINYINT(1) NOT NULL DEFAULT 0, is_active TINYINT(1) NOT NULL DEFAULT 1)");
$pdo->exec("CREATE TABLE zu_perm (id INT AUTO_INCREMENT PRIMARY KEY, user_id INT NOT NULL, module VARCHAR(24) NOT NULL,
            actions VARCHAR(12) NOT NULL DEFAULT '', updated_by INT NULL, updated_at DATETIME DEFAULT CURRENT_TIMESTAMP,
            UNIQUE KEY uniq_user_module (user_id, module), INDEX(user_id))");
// Production, as the ERP makes it (includes/zprod.php).
$pdo->exec("CREATE TABLE zp_workers (id INT AUTO_INCREMENT PRIMARY KEY, worker_code VARCHAR(40) NOT NULL, worker_name VARCHAR(120) NOT NULL,
            department VARCHAR(60) NULL, is_active TINYINT(1) NOT NULL DEFAULT 1, UNIQUE KEY uniq_worker_code (worker_code)) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci");
$pdo->exec("CREATE TABLE zp_entries (id INT AUTO_INCREMENT PRIMARY KEY, entry_date DATE NOT NULL, worker_id INT NOT NULL, product_id INT NOT NULL DEFAULT 1,
            op_id INT NOT NULL DEFAULT 1, qty DECIMAL(12,2) NOT NULL, rate_applied DECIMAL(12,2) NOT NULL, amount DECIMAL(14,2) NOT NULL,
            status VARCHAR(12) NOT NULL DEFAULT 'active') ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci");
$pdo->exec("CREATE TABLE zp_pay (id INT AUTO_INCREMENT PRIMARY KEY, pay_date DATE NOT NULL, worker_id INT NOT NULL, amount DECIMAL(14,2) NOT NULL,
            note VARCHAR(255) NULL, status VARCHAR(12) NOT NULL DEFAULT 'active') ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci");
(new Migrations($B . 'sql/att'))->up();
$n = fn(string $sql, array $p = []) => (int) Db::value($sql, $p);
$hash = password_hash('CorrectHorse9', PASSWORD_DEFAULT);
$mk = function (string $name, string $email, string $role = 'colleague') use ($pdo, $hash): int {
    $pdo->prepare("INSERT INTO users (name,email,password_hash,role) VALUES (?,?,?,?)")->execute([$name, $email, $hash, $role]);
    return (int) $pdo->lastInsertId();
};
$admin = $mk('Admin Person', 'admin@zas.test', 'admin');
$gk  = $mk('Gul Gatekeeper', 'gate@zas.test');
$hod = $mk('Hina Hod', 'hod@zas.test');
$hr  = $mk('Rana HR', 'hr@zas.test');
$acc = $mk('Asif Accounts', 'acc@zas.test');
$aud = $mk('Aisha Audit', 'audit@zas.test');
$dS = Employees::createDepartment('Stitching');
$dP = Employees::createDepartment('Packing');
$emp = [];
foreach ([['S001', 'Sara Salaried', $dS], ['S002', 'Sami Salaried', $dP], ['D001', 'Dawood Daily', $dS], ['D002', 'Dina Daily', $dS],
          ['D003', 'Danish Daily', $dP], ['C001', 'Kamran Contractor', $dS]] as [$c, $nm, $d]) {
    $emp[$c] = Employees::create(['code' => $c, 'full_name' => $nm, 'department_id' => $d]);
}
Db::run("UPDATE att_employees SET worker_code = 'W001' WHERE id = ?", [$emp['C001']]);
Roles::grant($admin, Roles::ADMIN);
Roles::grant($gk, Roles::GATEKEEPER);
Roles::grant($hod, Roles::HOD, $dS);
Roles::grant($hr, Roles::HR);
Roles::grant($acc, Roles::ACCOUNTS);
Roles::grant($aud, Roles::AUDIT);
$day = Shifts::create(['name' => 'Day', 'start_time' => '08:00', 'end_time' => '18:00', 'grace_in_minutes' => 10, 'grace_out_minutes' => 10]);
Policy::set('default_shift_id', (string) $day, null, 'string');
Policy::set('auto_out_enabled', '0', null, 'bool'); Policy::flush();
const GATE = 'aaaaaaaa-aaaa-4aaa-8aaa-aaaaaaaaaaaa';
S::registerDevice(GATE, 'Main gate', 'gate', $gk);
$seq = 0; $un = 0;
$uuid = function () use (&$un): string { return sprintf('%08x-0000-4000-8000-%012x', random_int(0, 0xffffffff), ++$un); };
$scan = function (string $type, string $code, string $hm, string $date) use (&$seq, $uuid, $gk): array {
    return S::process(['event_uuid' => $uuid(), 'device_id' => GATE, 'device_sequence' => ++$seq, 'employee_code' => $code, 'event_type' => $type,
        'occurred_at' => "{$date}T{$hm}:00Z", 'timezone' => 'UTC', 'payload' => []], $gk, true);
};
$came = function (string $code, string $date, string $out = '12:00') use ($scan): void { $scan('GATE_IN', $code, '03:00', $date); $scan('GATE_OUT', $code, $out, $date); };
$line = function (array $lines, string $key): ?array { foreach ($lines as $l) { if ($l['key'] === $key) { return $l; } } return null; };
$row = function (array $preview, int $employeeId): ?array { foreach ($preview['rows'] ?? [] as $r) { if ($r['employee_id'] === $employeeId) { return $r; } } return null; };
$code = fn(array $r) => $r['reason_code'] ?? ($r['ok'] ? 'OK' : '?');

// Thursday 5 March 2026 — pay day. February had 28 days.
Fin::setToday('2026-03-05');
$A = Fin::caps($admin); $H = Fin::caps($hod); $R = Fin::caps($hr); $C = Fin::caps($acc); $U = Fin::caps($aud); $G = Fin::caps($gk);

echo "1. Who may do what\n";
ok($A['salary'] && $A['wage'] && $A['approve'] && $A['pay'], 'ADMIN: every step');
ok($U['salary'] && $U['approve'] && !$U['wage'] && !$U['pay'], 'AUDIT: prepares fixed salaries and approves; does not pay');
ok($C['salary'] && $C['wage'] && $C['pay'] && !$C['approve'], 'ACCOUNTS: prepares everything and records payment; does not approve');
ok($R['salary'] && !$R['wage'] && !$R['approve'] && !$R['pay'], 'HR: prepares fixed salaries only');
ok(!$H['salary'] && $H['wage'] && !$H['approve'] && !$H['pay'] && $H['wage_departments'] === [$dS] && !$H['see_salary'], 'HOD: prepares wages for their own department only, never sees a salary');
ok(!$G['any'], 'a gatekeeper has no part in it');

echo "2. Salary setup\n";
ok($code(Fin::saveProfile($emp['S001'], ['pay_type' => 'salary', 'basic_paisa' => 0], '', $admin)) === 'VALIDATION', 'a fixed salary needs the monthly amount');
ok($code(Fin::saveProfile($emp['S001'], ['pay_type' => 'weekly'], '', $admin)) === 'VALIDATION', 'the pay type must be one of the three');
ok(Fin::saveProfile($emp['S001'], ['pay_type' => 'salary', 'basic_paisa' => 6000000, 'allowance_paisa' => 500000, 'ot_rate_paisa' => 20000, 'pay_method' => 'bank', 'bank_account' => 'HBL 0042'], '', $admin)['ok'], 'S001: 60,000 a month + 5,000 allowance, by bank');
Fin::saveProfile($emp['S002'], ['pay_type' => 'salary', 'basic_paisa' => 4200000], '', $admin);
foreach (['D001', 'D002', 'D003'] as $c) { Fin::saveProfile($emp[$c], ['pay_type' => 'daily', 'daily_rate_paisa' => 120000, 'ot_rate_paisa' => 15000], '', $admin); }
Fin::saveProfile($emp['C001'], ['pay_type' => 'contract'], '', $admin);
ok($code(Fin::saveProfile($emp['S002'], ['pay_type' => 'salary', 'basic_paisa' => 4500000], '', $admin)) === 'REASON_REQUIRED', 'a raise needs a reason');
ok($n('SELECT basic_paisa FROM att_fin_profiles WHERE employee_id = ?', [$emp['S002']]) === 4200000, '  and without one nothing changed');
ok($n('SELECT COUNT(*) FROM att_fin_profile_history WHERE employee_id = ?', [$emp['S001']]) === 1, 'every change of pay is kept in the history');

echo "3. Fixed salary — February worked out line by line\n";
// S001: three days planned, came on one → two days of unpaid leave.
foreach (['2026-02-02', '2026-02-03', '2026-02-04'] as $d) { Shifts::assign($emp['S001'], $day, $d, 'assigned', $admin); }
$came('S001', '2026-02-02');
// Overtime: 2 h approved and checked with no typed rate, and one line HR rated at 750.
Db::run("INSERT INTO att_ot_requests (request_uuid, department_id, requested_by, work_date, reason) VALUES (?, ?, ?, '2026-02-10', 'Order rush'), (?, ?, ?, '2026-02-11', 'Order rush')",
    [$uuid(), $dS, $hod, $uuid(), $dS, $hod]);
$rq = (int) Db::value('SELECT MIN(id) FROM att_ot_requests');
Db::run("INSERT INTO att_ot_request_employees (request_id, employee_id, work_date, estimated_minutes, approval_status, approved_minutes, verification_status, actual_minutes)
         VALUES (?, ?, '2026-02-10', 120, 'approved', 120, 'verified', 120), (?, ?, '2026-02-11', 180, 'approved', 180, 'verified', 180)", [$rq, $emp['S001'], $rq + 1, $emp['S001']]);
$l2 = (int) Db::value("SELECT id FROM att_ot_request_employees WHERE work_date = '2026-02-11'");
Db::run("INSERT INTO att_ot_payroll (request_employee_id, rate_type, manual_rate, payable_minutes, payable_amount) VALUES (?, 'fixed', 750, 180, 750.00)", [$l2]);
// A line that was never checked must not be paid.
Db::run("INSERT INTO att_ot_requests (request_uuid, department_id, requested_by, work_date, reason) VALUES (?, ?, ?, '2026-02-12', 'x')", [$uuid(), $dS, $hod]);
Db::run("INSERT INTO att_ot_request_employees (request_id, employee_id, work_date, estimated_minutes, approval_status, approved_minutes) VALUES (?, ?, '2026-02-12', 240, 'approved', 240)", [$rq + 2, $emp['S001']]);
ok($code(Fin::giveAdvance(['employee_id' => $emp['S001'], 'amount_paisa' => 1000000, 'per_pay_paisa' => 0], $acc)) === 'VALIDATION', 'an advance needs a recovery plan');
$adv = Fin::giveAdvance(['employee_id' => $emp['S001'], 'kind' => 'advance', 'amount_paisa' => 1000000, 'per_pay_paisa' => 250000, 'given_on' => '2026-02-15'], $acc);
$loan = Fin::giveAdvance(['employee_id' => $emp['S001'], 'kind' => 'loan', 'amount_paisa' => 5000000, 'per_pay_paisa' => 500000, 'given_on' => '2026-01-10'], $acc);
$ak = 'adv:' . $adv['advance_id']; $lk = 'adv:' . $loan['advance_id'];

$pv = Fin::preview('salary', '2026-02-01', '2026-02-28', $R);
$s1 = $row($pv, $emp['S001']);
ok($pv['ok'] && count($pv['rows']) === 2, 'HR opens February: both salaried people are listed');
ok($line($s1['lines'], 'basic')['calc_paisa'] === 6000000 && $line($s1['lines'], 'basic')['editable'] === false, 'basic salary 60,000 — fixed, from salary setup');
ok($line($s1['lines'], 'allowance')['calc_paisa'] === 500000, 'allowance 5,000');
ok($line($s1['lines'], 'overtime')['calc_paisa'] === 115000, 'overtime 1,150: 2 h × 200 + the 750 HR typed; the unchecked evening is not paid (' . $line($s1['lines'], 'overtime')['calc_paisa'] . ')');
// An overtime rate is only ever what a person typed: with none typed, nothing is worked out from the salary.
Fin::saveProfile($emp['S001'], ['pay_type' => 'salary', 'basic_paisa' => 6000000, 'allowance_paisa' => 500000, 'ot_rate_paisa' => 0, 'pay_method' => 'bank', 'bank_account' => 'HBL 0042'], 'test: no overtime rate', $admin);
ok($line($row(Fin::preview('salary', '2026-02-01', '2026-02-28', $R), $emp['S001'])['lines'], 'overtime')['calc_paisa'] === 75000, '  with no overtime rate typed, only the 750 HR priced is paid — NO RATE IS DERIVED FROM THE SALARY');
Fin::saveProfile($emp['S001'], ['pay_type' => 'salary', 'basic_paisa' => 6000000, 'allowance_paisa' => 500000, 'ot_rate_paisa' => 20000, 'pay_method' => 'bank', 'bank_account' => 'HBL 0042'], 'test: rate back', $admin);
ok($line($s1['lines'], 'leave')['calc_paisa'] === 428571 && $line($s1['lines'], 'leave')['side'] === 'deduct', 'unpaid leave 2 days = 60,000 ÷ 28 × 2 = 4,285.71');
ok($line($s1['lines'], $ak)['calc_paisa'] === 250000 && $line($s1['lines'], $lk)['calc_paisa'] === 500000, 'advance recovery 2,500 and loan recovery 5,000 — each its own line');
ok($s1['calc_paisa'] === 6000000 + 500000 + 115000 - 428571 - 250000 - 500000, 'net 54,364.29');

echo "4. Each line is amended by itself\n";
$mkS = fn(array $it, array $caps, int $who) => Fin::createSheet('salary', '2026-02-01', '2026-02-28', [$it + ['employee_id' => $emp['S001']]], $caps, $who);
ok($code($mkS(['changes' => [['key' => 'basic', 'amount_paisa' => 7000000, 'reason' => 'raise']]], $R, $hr)) === 'VALIDATION', 'basic salary cannot be changed on a sheet');
ok($code($mkS(['changes' => [['key' => 'leave', 'amount_paisa' => 0]]], $R, $hr)) === 'REASON_REQUIRED', 'a changed line needs a reason');
ok($code($mkS(['changes' => [['key' => $ak, 'amount_paisa' => 1000001, 'reason' => 'all of it']]], $R, $hr)) === 'VALIDATION', 'a recovery cannot be more than is still owed');
ok($code($mkS(['added' => [['kind' => 'bonus', 'amount_paisa' => 200000]]], $R, $hr)) === 'REASON_REQUIRED', 'an added line needs a reason');
ok($code($mkS(['added' => [['kind' => 'gift', 'amount_paisa' => 200000, 'reason' => 'x']]], $R, $hr)) === 'VALIDATION', 'only a bonus, another earning or a deduction can be added');
ok($code($mkS(['changes' => [['key' => 'nope', 'amount_paisa' => 1, 'reason' => 'x']]], $R, $hr)) === 'VALIDATION', 'a line that is not there cannot be changed');
ok($code($mkS(['added' => [['kind' => 'deduction', 'amount_paisa' => 90000000, 'reason' => 'x']]], $R, $hr)) === 'VALIDATION', 'deductions cannot be more than the pay');
ok($code($mkS([], $H, $hod)) === 'FORBIDDEN', 'a HOD cannot prepare a salary sheet');
ok($n('SELECT COUNT(*) FROM att_fin_sheets') === 0, '  none of those made a sheet');
$sh = $mkS(['changes' => [['key' => 'leave', 'amount_paisa' => 0, 'reason' => 'Leave approved by the director'], ['key' => $lk, 'amount_paisa' => 0, 'reason' => 'Skip this month, family emergency'],
                          ['key' => 'basic', 'amount_paisa' => 6000000]],
            'added' => [['kind' => 'bonus', 'label' => 'Eid bonus', 'amount_paisa' => 200000, 'reason' => 'Eid']]], $R, $hr);
ok($sh['ok'] && $sh['total_paisa'] === 6565000 && str_starts_with($sh['ref'], 'PAY-260305-'), 'HR PREPARES IT: leave waived, loan skipped, Eid bonus added → 65,650 (' . ($sh['total_paisa'] ?? '') . ')');
$sid = $sh['sheet_id'];
$v = Fin::sheet($sid, $U, $aud);
$it = $v['items'][0];
ok($it['calc_paisa'] === 5436429 && $it['amount_paisa'] === 6565000, 'the sheet keeps both: worked out 54,364.29, to pay 65,650');
$lv = $line($it['lines'], 'leave');
ok($lv['calc_paisa'] === 428571 && $lv['amount_paisa'] === 0 && $lv['reason'] === 'Leave approved by the director', '  the leave line shows 4,285.71 → 0 with the reason');
ok($line($it['lines'], $ak)['amount_paisa'] === 250000 && $line($it['lines'], $ak)['reason'] === null, '  the advance line is untouched — amending one line does not touch another');
ok($line($it['lines'], 'add:1')['label'] === 'Eid bonus' && $line($it['lines'], 'add:1')['side'] === 'earn', '  the bonus is its own line');
ok(Fin::sheets($U, $aud)['sheets'][0]['amended'] === 3, 'the list says 3 lines were changed by hand');

echo "5. No day is paid twice\n";
ok($row(Fin::preview('salary', '2026-02-01', '2026-02-28', $C), $emp['S001']) === null, 'S001 is no longer offered for February');
ok($code($mkS([], $C, $acc)) === 'OVERLAP', 'and a second February sheet for them is refused');
ok($code(Fin::createSheet('salary', '2026-02-10', '2026-02-20', [['employee_id' => $emp['S001']]], $C, $acc)) === 'OVERLAP', '  so is one for part of February');
ok($code(Fin::preview('salary', '2026-02-15', '2026-03-05', $C)) === 'VALIDATION', 'a salary sheet stays inside one month');
ok($code(Fin::preview('salary', '2026-03-01', '2026-03-31', $C)) === 'VALIDATION', 'days that have not happened yet cannot be paid');

echo "6. The approver amends, then approves\n";
ok($code(Fin::approve($sid, $C, $acc)) === 'FORBIDDEN', 'Accounts cannot approve');
ok($code(Fin::approve($sid, $R, $hr)) === 'FORBIDDEN', 'HR cannot approve');
ok($code(Fin::amendSheet($sid, [['employee_id' => $emp['S001'], 'changes' => [['key' => 'overtime', 'amount_paisa' => 100000, 'reason' => 'x']]]], $C, $acc)) === 'FORBIDDEN', 'someone who neither prepared nor approves cannot amend it');
ok($code(Fin::amendSheet($sid, [['employee_id' => $emp['S001'], 'remove_keys' => ['leave']]], $U, $aud)) === 'VALIDATION', 'a worked-out line cannot be removed, only set to zero');
// One good change and one bad one in the same request: nothing may be kept.
$bad = Fin::amendSheet($sid, [['employee_id' => $emp['S001'], 'changes' => [['key' => 'overtime', 'amount_paisa' => 100000, 'reason' => 'ok'], ['key' => 'allowance', 'amount_paisa' => 1]]]], $U, $aud);
ok($code($bad) === 'REASON_REQUIRED' && $line(Fin::sheet($sid, $U, $aud)['items'][0]['lines'], 'overtime')['amount_paisa'] === 115000, 'a refused amendment leaves the whole sheet as it was');
$am = Fin::amendSheet($sid, [['employee_id' => $emp['S001'], 'changes' => [['key' => 'overtime', 'amount_paisa' => 100000, 'reason' => 'Audit: 30 min was a break'], ['key' => $lk, 'amount_paisa' => 500000]],
                              'remove_keys' => ['add:1'], 'added' => [['kind' => 'deduction', 'label' => 'Uniform', 'amount_paisa' => 50000, 'reason' => 'Uniform issued']]]], $U, $aud);
$it = Fin::sheet($sid, $U, $aud)['items'][0];
ok($am['ok'] && $it['amount_paisa'] === 6000000 + 500000 + 100000 - 0 - 250000 - 500000 - 50000, 'AUDIT AMENDS: overtime trimmed, loan put back to plan, bonus removed, uniform deducted → 58,000 (' . $it['amount_paisa'] . ')');
ok($line($it['lines'], $lk)['reason'] === null && $line($it['lines'], 'leave')['amount_paisa'] === 0, '  a line put back to the worked-out figure loses its reason; HR\'s leave change stands');
ok($n('SELECT COUNT(*) FROM att_fin_ledger') === 0, 'nothing is in the ledger before approval');
$own = Fin::createSheet('salary', '2026-02-01', '2026-02-28', [['employee_id' => $emp['S002']]], $U, $aud);
ok($own['ok'] && $code(Fin::approve($own['sheet_id'], $U, $aud)) === 'FORBIDDEN', 'Audit prepared S002\'s sheet, so Audit cannot approve it');
ok(Fin::sendBack($own['sheet_id'], 'Wrong month', $U, $aud)['ok'] && $row(Fin::preview('salary', '2026-02-01', '2026-02-28', $C), $emp['S002']) !== null, '  sent back: S002 can be prepared again');
ok($code(Fin::sendBack($sid, '', $U, $aud)) === 'REASON_REQUIRED', 'sending back needs a reason');
$ap = Fin::approve($sid, $U, $aud);
ok($ap['ok'] && $ap['status'] === 'approved', 'AUDIT APPROVES S001\'s sheet');
$b = Fin::balances($emp['S001'])[$emp['S001']];
ok($b['earned'] === 6600000 && $b['deducted'] === 800000 && $b['paid'] === 0 && $b['balance'] === 5800000, 'ledger: earned 66,000, deducted 8,000, owed 58,000');
ok($n("SELECT COUNT(*) FROM att_fin_ledger WHERE sheet_id = ? AND amount_paisa = 0", [$sid]) === 0, '  a line of zero posts nothing');
ok(str_contains((string) Db::value("SELECT label FROM att_fin_ledger WHERE sheet_id = ? AND line_kind = 'overtime'", [$sid]), 'amended: Audit: 30 min was a break'), '  the ledger line carries the amendment reason');
ok($n('SELECT recovered_paisa FROM att_fin_advances WHERE id = ?', [$adv['advance_id']]) === 250000 && $n('SELECT recovered_paisa FROM att_fin_advances WHERE id = ?', [$loan['advance_id']]) === 500000, 'the advance and the loan are each reduced by their own line');
ok($code(Fin::approve($sid, $A, $admin)) === 'ALREADY_DECIDED' && $code(Fin::amendSheet($sid, [], $U, $aud)) === 'ALREADY_DECIDED', 'an approved sheet cannot be approved or amended again');
ok($code(Fin::cancelAdvance($adv['advance_id'], 'x', $acc)) === 'ALREADY_DECIDED', 'an advance partly recovered cannot be cancelled');

echo "7. Paying\n";
ok($code(Fin::pay($sid, '2026-03-05', '', $U, $aud)) === 'FORBIDDEN' && $code(Fin::pay($sid, '2026-03-05', '', $R, $hr)) === 'FORBIDDEN', 'Audit and HR cannot record a payment');
ok($code(Fin::pay($sid, '2026-03-06', '', $C, $acc)) === 'VALIDATION', 'not on a date in the future');
ok($code(Fin::pay($sid, '2026-03-03', '', $C, $acc)) === 'REASON_REQUIRED', 'Tuesday is not pay day: a reason is needed');
$w = Fin::createSheet('salary', '2026-02-01', '2026-02-28', [['employee_id' => $emp['S002']]], $C, $acc);
ok($code(Fin::pay($w['sheet_id'], '2026-03-05', '', $C, $acc)) === 'ALREADY_DECIDED', 'a sheet not yet approved cannot be paid');
$pd = Fin::pay($sid, '2026-03-05', '', $C, $acc);
ok($pd['ok'] && $pd['total_paisa'] === 5800000, 'ACCOUNTS PAYS on Thursday: 58,000, no reason needed');
ok(Fin::balance($emp['S001']) === 0, 'S001 is owed nothing now');
ok(Db::value("SELECT method FROM att_fin_ledger WHERE sheet_id = ? AND kind = 'payment'", [$sid]) === 'bank', '  paid by bank, as set up');
ok($code(Fin::pay($sid, '2026-03-05', '', $C, $acc)) === 'ALREADY_DECIDED', 'it cannot be paid twice');
ok(Fin::approve($w['sheet_id'], $A, $admin)['ok'], '(admin approves S002)');
$off = Fin::pay($w['sheet_id'], '2026-03-03', 'Leaving for Umrah on Wednesday', $C, $acc);
ok($off['ok'] && Db::value('SELECT off_day_reason FROM att_fin_sheets WHERE id = ?', [$w['sheet_id']]) === 'Leaving for Umrah on Wednesday', 'paid on a Tuesday with the reason kept');
$lg = Fin::ledger($emp['S001'], $C);
ok($lg['ok'] && $lg['balance_paisa'] === 0 && end($lg['lines'])['kind'] === 'payment' && count($lg['advances']) === 2, 'the statement runs to zero and shows the advance and the loan');

echo "8. Custom dates\n";
Fin::setToday('2026-04-02');
$half = Fin::preview('salary', '2026-03-01', '2026-03-10', $C);
$h1 = $row($half, $emp['S001']);
ok($line($h1['lines'], 'basic')['calc_paisa'] === 1935484 && $line($h1['lines'], 'allowance')['calc_paisa'] === 161290, '1–10 March: 60,000 ÷ 31 × 10 = 19,354.84; allowance the same way');
ok($line($h1['lines'], $ak) === null && $line($h1['lines'], 'leave') === null, '  recoveries and leave wait for the sheet with the last day of the month');
$p1 = Fin::createSheet('salary', '2026-03-01', '2026-03-10', [['employee_id' => $emp['S001']]], $C, $acc);
$rest = $row(Fin::preview('salary', '2026-03-11', '2026-03-31', $C), $emp['S001']);
ok($p1['ok'] && $line($rest['lines'], 'basic')['calc_paisa'] === 6000000 - 1935484 && $line($rest['lines'], $ak)['calc_paisa'] === 250000, '11–31 March: the rest of the salary, with the recoveries');
ok($code(Fin::createSheet('salary', '2026-03-10', '2026-03-31', [['employee_id' => $emp['S001']]], $C, $acc)) === 'OVERLAP', '10–31 March would pay the 10th twice: refused');
Fin::sendBack($p1['sheet_id'], 'test only', $C, $acc);

echo "9. Daily wages — Friday to Thursday\n";
Fin::setToday('2026-03-05');
foreach (['2026-02-27', '2026-02-28', '2026-03-02'] as $d) { $came('D001', $d); }
$came('D001', '2026-03-03', '06:00');                                            // three hours: half a day
foreach (['2026-03-02', '2026-03-03'] as $d) { $came('D002', $d); $came('D003', $d); }
$dw = Fin::preview('daily', '2026-02-27', '2026-03-05', $H);
ok($dw['ok'] && count($dw['rows']) === 2 && $row($dw, $emp['D003']) === null, 'THE HOD sees only Stitching\'s daily workers');
ok($line($row($dw, $emp['D001'])['lines'], 'daily')['calc_paisa'] === 420000, 'D001: 3 days + a half = 3.5 × 1,200 = 4,200');
ok(str_contains($line($row($dw, $emp['D001'])['lines'], 'daily')['label'], '3.5 days'), '  and the line says "3.5 days"');
ok($line($row($dw, $emp['D002'])['lines'], 'daily')['calc_paisa'] === 240000, 'D002: 2 days = 2,400');
ok($code(Fin::createSheet('daily', '2026-02-27', '2026-03-05', [['employee_id' => $emp['D003']]], $H, $hod)) === 'EMPLOYEE_NOT_FOUND', 'the HOD cannot put Packing\'s worker on a sheet');
ok($code(Fin::preview('daily', '2026-02-27', '2026-03-05', $R)) === 'FORBIDDEN', 'HR cannot prepare daily wages');
Fin::giveAdvance(['employee_id' => $emp['D002'], 'amount_paisa' => 500000, 'per_pay_paisa' => 300000], $acc);
ok($row(Fin::preview('daily', '2026-02-27', '2026-03-05', $H), $emp['D002'])['calc_paisa'] === 0, 'D002 earned 2,400 with a plan of 3,000: the recovery takes 2,400, never more than the pay');
$ds = Fin::createSheet('daily', '2026-02-27', '2026-03-05', [
    ['employee_id' => $emp['D001'], 'changes' => [['key' => 'daily', 'amount_paisa' => 480000, 'reason' => 'Gate missed his scan-out on Tuesday, full day']]],
    ['employee_id' => $emp['D002'], 'changes' => [['key' => 'adv:' . (int) Db::value('SELECT MAX(id) FROM att_fin_advances'), 'amount_paisa' => 100000, 'reason' => 'Asked to pay back slower']]],
], $H, $hod);
ok($ds['ok'] && $ds['people'] === 2 && $ds['total_paisa'] === 480000 + 140000, 'THE HOD PREPARES both in one sheet, each with its own amendment → 6,200');
ok(count(Fin::sheets($H, $hod)['sheets']) === 1 && $code(Fin::sheet($sid, $H, $hod)) === 'NOT_FOUND', 'the HOD sees their own sheet, and not the salary sheets');
ok($code(Fin::approve($ds['sheet_id'], $H, $hod)) === 'FORBIDDEN' && Fin::approve($ds['sheet_id'], $U, $aud)['ok'], 'the HOD cannot approve it; Audit does');
ok(Fin::pay($ds['sheet_id'], '2026-03-05', '', $C, $acc)['ok'] && Fin::balance($emp['D001']) === 0 && Fin::balance($emp['D002']) === 0, 'Accounts pays: both settled');
ok($row(Fin::preview('daily', '2026-03-05', '2026-03-05', $C), $emp['D001']) === null, 'that week cannot be prepared again for them');
$hp = Fin::people($H)['people'];
ok(count($hp) === 4 && array_values(array_filter($hp, fn($r) => $r['code'] === 'S001'))[0]['basic_paisa'] === null, 'the HOD\'s list: Stitching only, and the salaried person\'s pay is blank');

echo "10. Contractor — production is owed as it is booked\n";
$pdo->exec("INSERT INTO zp_workers (worker_code, worker_name) VALUES ('W001', 'Kamran Contractor')");
$pdo->exec("INSERT INTO zp_entries (entry_date, worker_id, qty, rate_applied, amount) VALUES ('2026-03-02', 1, 100, 50, 5000.00), ('2026-03-02', 1, 10, 50.05, 500.50), ('2026-03-03', 1, 60, 50, 3000.00)");
$pdo->exec("INSERT INTO zp_pay (pay_date, worker_id, amount, note, status) VALUES ('2026-03-03', 1, 2000.00, 'old screen', 'active'), ('2026-03-03', 1, 500.00, NULL, 'cancelled')");
Fin::syncProduction(); Fin::syncProduction();
ok($n("SELECT COUNT(*) FROM att_fin_ledger WHERE employee_id = ?", [$emp['C001']]) === 4, 'copied once however often it runs: two days of production, two old payments');
ok(Fin::balance($emp['C001']) === 650050, 'owed 6,500.50 = 8,500.50 booked − 2,000 paid on the old screen (the cancelled 500 does not count)');
$cp = $row(Fin::preview('contract', '2026-03-05', '2026-03-05', $H), $emp['C001']);
ok($cp !== null && $line($cp['lines'], 'carry')['calc_paisa'] === 650050 && $line($cp['lines'], 'carry')['side'] === 'carry', 'the sheet offers the 6,500.50');
Fin::giveAdvance(['employee_id' => $emp['C001'], 'amount_paisa' => 100000, 'per_pay_paisa' => 50000], $acc);
$ck = 'adv:' . (int) Db::value('SELECT MAX(id) FROM att_fin_advances');
ok($code(Fin::createSheet('contract', '2026-03-05', '2026-03-05', [['employee_id' => $emp['C001'], 'changes' => [['key' => 'carry', 'amount_paisa' => 700000]]]], $H, $hod)) === 'VALIDATION', 'more than is owed cannot be paid — that is an advance');
$cs = Fin::createSheet('contract', '2026-03-05', '2026-03-05', [['employee_id' => $emp['C001'], 'changes' => [['key' => 'carry', 'amount_paisa' => 500000]]]], $H, $hod);
ok($cs['ok'] && $cs['total_paisa'] === 450000, 'PART PAYMENT: 5,000 of it, less 500 advance → 4,500 — no reason needed to pay part');
ok($code(Fin::createSheet('contract', '2026-03-05', '2026-03-05', [['employee_id' => $emp['C001']]], $C, $acc)) === 'OVERLAP', 'one open sheet per contractor at a time');
Fin::approve($cs['sheet_id'], $A, $admin); Fin::pay($cs['sheet_id'], '2026-03-05', '', $A, $admin);
ok(Fin::balance($emp['C001']) === 150050, 'after it: 1,500.50 still owed');
$pdo->exec("UPDATE zp_entries SET status = 'cancelled' WHERE amount = 500.50");
Fin::syncProduction();
ok(Fin::balance($emp['C001']) === 100000, 'a production entry cancelled later lowers what is owed: 1,000');
$pdo->exec("UPDATE zp_pay SET status = 'cancelled' WHERE amount = 2000.00");
ok(Fin::people($C)['ok'] && Fin::balance($emp['C001']) === 300000, 'an old payment cancelled on Worker Pay comes back as owed: 3,000');
$pdo->exec("INSERT INTO zp_workers (worker_code, worker_name) VALUES ('W777', 'Not Linked Yet'), ('w-OLD', 'Left Long Ago')");
$pdo->exec("UPDATE zp_workers SET is_active = 0 WHERE worker_code = 'w-OLD'");
ok(array_column(Fin::unlinkedWorkers(), 'code') === ['W777'] && Fin::linkedWorkerIds() === [1], 'a production worker with no contractor set up is listed as "still on the old screen"; the linked one is not');
Fin::saveProfile($emp['D003'], ['pay_type' => 'contract'], 'Moved to piece rate', $admin);
Db::run("UPDATE att_employees SET worker_code = 'w777' WHERE id = ?", [$emp['D003']]);
ok(Fin::unlinkedWorkers() === [] && count(Fin::linkedWorkerIds()) === 2, '  set up as a contractor under the same code (any letter case), they move over');
Fin::saveProfile($emp['D003'], ['pay_type' => 'daily', 'daily_rate_paisa' => 120000], 'Back to daily', $admin);
Db::run("UPDATE att_employees SET worker_code = NULL WHERE id = ?", [$emp['D003']]);
// Stale sheet: prepared for 3,000, then production is cancelled before approval.
$st = Fin::createSheet('contract', '2026-03-05', '2026-03-05', [['employee_id' => $emp['C001']]], $C, $acc);
$pdo->exec("UPDATE zp_entries SET status = 'cancelled' WHERE entry_date = '2026-03-03'");
Fin::syncProduction();
ok($code(Fin::approve($st['sheet_id'], $A, $admin)) === 'STALE' && $n('SELECT COUNT(*) FROM att_fin_ledger WHERE sheet_id = ?', [$st['sheet_id']]) === 0, 'a sheet for more than is now owed is refused at approval, and posts nothing');
Fin::sendBack($st['sheet_id'], 'production was cancelled', $A, $admin);

echo "11. Cancelling a sheet\n";
ok($code(Fin::cancelSheet($sid, '', $A, $admin)) === 'REASON_REQUIRED', 'needs a reason');
ok($code(Fin::cancelSheet($sid, 'x', $U, $aud)) === 'FORBIDDEN', 'a PAID sheet: administrator only');
$before = $n('SELECT COUNT(*) FROM att_fin_ledger');
$cx = Fin::cancelSheet($sid, 'Paid from the wrong account', $A, $admin);
ok($cx['ok'] && $n('SELECT COUNT(*) FROM att_fin_ledger') === $before && $n('SELECT COUNT(*) FROM att_fin_ledger WHERE sheet_id = ? AND cancelled_at IS NULL', [$sid]) === 0, 'CANCELLED: every line struck through, none deleted');
ok(Fin::balance($emp['S001']) === 0 && $n('SELECT recovered_paisa FROM att_fin_advances WHERE id = ?', [$adv['advance_id']]) === 0, '  the balance is as if it never happened, and the advance is owed in full again');
ok($row(Fin::preview('salary', '2026-02-01', '2026-02-28', $C), $emp['S001']) !== null, '  February can be prepared again');
ok($code(Fin::cancelSheet($sid, 'again', $A, $admin)) === 'ALREADY_DECIDED', 'not twice');

echo "12. Opening balance and overview\n";
ok($code(Fin::setOpening($emp['D003'], 250000, '', $acc)) === 'REASON_REQUIRED', 'an opening balance needs its source');
Fin::setOpening($emp['D003'], 250000, 'Old register, page 14', $acc);
Fin::setOpening($emp['D003'], 300000, 'Old register, page 14 (corrected)', $acc);
ok(Fin::balance($emp['D003']) === 300000 && $n("SELECT COUNT(*) FROM att_fin_ledger WHERE employee_id = ? AND kind = 'opening'", [$emp['D003']]) === 1, 'entered twice it is replaced, not added: 3,000');
$d3 = $row(Fin::preview('daily', '2026-02-27', '2026-03-05', $C), $emp['D003']);
ok($line($d3['lines'], 'carry')['calc_paisa'] === 300000 && $d3['calc_paisa'] === 300000 + 240000, 'D003\'s next sheet pays the old 3,000 with this week\'s 2,400');
$ov = Fin::overview($C, $acc);
ok($ov['ok'] && $ov['owed_paisa'] === 300000 && $ov['sheets']['paid'] === 3 && $ov['sheets']['cancelled'] === 1 && $ov['pay_weekday'] === 4, 'overview: 3,000 owed in all, 3 sheets paid, 1 cancelled, pay day Thursday');

echo "12b. Many people at once, and the ledger in one place\n";
$n1 = Employees::create(['code' => 'B001', 'full_name' => 'Bilal Bulk', 'department_id' => $dS]);
$n2 = Employees::create(['code' => 'B002', 'full_name' => 'Bushra Bulk', 'department_id' => $dP]);
$bk = Fin::saveProfiles([['employee_id' => $n1, 'pay_type' => 'daily', 'daily_rate_paisa' => 110000], ['employee_id' => $n2, 'pay_type' => 'salary', 'basic_paisa' => 0],
                         ['employee_id' => $emp['S002'], 'pay_type' => 'salary', 'basic_paisa' => 4300000]], '', $hr);
ok($bk['ok'] && $bk['saved'] === 1 && count($bk['errors']) === 2 && str_contains($bk['errors'][0], 'Bushra Bulk') && str_contains($bk['errors'][1], 'Sami Salaried'),
   'BULK SETUP: the good row is saved; the salary with no amount and the unexplained raise are refused by name');
ok($n('SELECT daily_rate_paisa FROM att_fin_profiles WHERE employee_id = ?', [$n1]) === 110000 && $n('SELECT basic_paisa FROM att_fin_profiles WHERE employee_id = ?', [$emp['S002']]) === 4200000 && $n('SELECT COUNT(*) FROM att_fin_profile_history WHERE employee_id = ?', [$n1]) === 1,
   '  each row went through the same rules and the same history as one person at a time');
ok(Fin::saveProfiles([['employee_id' => $emp['S002'], 'pay_type' => 'salary', 'basic_paisa' => 4300000]], 'Annual raise', $hr)['saved'] === 1, '  with the reason, the raise is saved');
ok($code(Fin::saveProfiles([], '', $hr)) === 'VALIDATION', '  an empty list is refused');
$ls = Fin::ledgerSummary($C, '2026-03-01', '2026-03-05');
$lr = array_column($ls['rows'], null, 'code');
ok($ls['ok'] && $lr['D001']['before_paisa'] === 0 && $lr['D001']['earned'] === 480000 && $lr['D001']['paid'] === 480000 && $lr['D001']['closing_paisa'] === 0, 'LEDGER, 1–5 March: D001 earned 4,800, was paid 4,800, is owed nothing');
ok($lr['D002']['earned'] === 240000 && $lr['D002']['deducted'] === 100000 && $lr['D002']['paid'] === 140000, '  D002: earned 2,400, 1,000 recovered, 1,400 paid');
ok(!isset($lr['S001']) || $lr['S001']['closing_paisa'] === 0, '  the cancelled salary sheet counts for nothing');
ok($ls['totals']['closing_paisa'] === array_sum(array_column($ls['rows'], 'closing_paisa')) && $ls['totals']['closing_paisa'] === Fin::overview($C, $acc)['owed_paisa'], '  the totals add up, and "owed at the end" is the same figure Home shows');
$later = array_column(Fin::ledgerSummary($C, '2026-03-06', '2026-03-06')['rows'], null, 'code');
ok($later['D003']['before_paisa'] === 300000 && $later['D003']['earned'] === 0 && $later['D003']['closing_paisa'] === 300000, '  a later window carries the balance forward as "owed before"');
ok(array_filter(Fin::ledgerSummary($H, '2026-03-01', '2026-03-05')['rows'], fn($r) => $r['pay_type'] === 'salary' || $r['department_name'] !== 'Stitching') === [], '  the HOD sees only their own department, and no salaried person');
ok($code(Fin::ledgerSummary($C, '2026-03-05', '2026-03-01')) === 'VALIDATION', '  the dates must be in order');

echo "12c. One form for an employee, and the ledger page\n";
$A2 = Fin::caps($admin);
$st1 = Fin::saveStaff([
    ['code' => 'N100', 'full_name' => 'Nasir New', 'department' => 'Stitching', 'pay_type' => 'daily', 'daily_rate_paisa' => 130000, 'opening_paisa' => 800000],
    ['full_name' => 'Auto Coded', 'department' => 'Dyeing', 'pay_type' => 'salary', 'basic_paisa' => 4500000, 'allowance_paisa' => 300000, 'pay_method' => 'bank', 'bank_account' => 'MCB 77'],
    ['full_name' => 'No Department'],
    ['code' => 'S001', 'full_name' => 'Clash Code', 'department' => 'Stitching'],
], '', $A2, true, $admin);
ok($st1['created'] === 2 && $st1['pay_saved'] === 2 && $st1['opening_saved'] === 1 && count($st1['errors']) === 2, 'ONE FORM, FOUR LINES: two people added with their pay, one opening balance; the line with no department and the one with a taken code are refused by name (' . json_encode([$st1['created'], $st1['pay_saved'], $st1['opening_saved'], count($st1['errors'])]) . ')');
$nn = (int) Db::value("SELECT id FROM att_employees WHERE code = 'N100'");
ok($nn > 0 && Fin::balance($nn) === 800000 && $n('SELECT daily_rate_paisa FROM att_fin_profiles WHERE employee_id = ?', [$nn]) === 130000, '  Nasir: on the roll, 1,300 a day, 8,000 owed from before — all from one line');
$ac = Db::one("SELECT e.id, e.code, d.name FROM att_employees e JOIN att_departments d ON d.id = e.department_id WHERE e.full_name = 'Auto Coded'");
ok($ac !== null && $ac['name'] === 'Dyeing' && preg_match('/^[A-Z]\d{3}$/', (string) $ac['code']) === 1, '  a line with no code is given the next number in the series in use (' . ($ac['code'] ?? '') . '), and a new department is created');
ok($n("SELECT COUNT(*) FROM att_employees WHERE full_name IN ('No Department', 'Clash Code')") === 0, '  nothing was written for the refused lines');
$again = Fin::saveStaff([['employee_id' => $nn, 'code' => 'N100', 'full_name' => 'Nasir New', 'department' => 'Stitching', 'pay_type' => 'daily', 'daily_rate_paisa' => 130000, 'opening_paisa' => 800000]], '', $A2, true, $admin);
ok($again['ok'] && $again['created'] + $again['updated'] + $again['pay_saved'] + $again['opening_saved'] === 0 && $n('SELECT COUNT(*) FROM att_fin_profile_history WHERE employee_id = ?', [$nn]) === 1, '  saving the same line again changes nothing and writes no history');
$mv = Fin::saveStaff([['employee_id' => $nn, 'full_name' => 'Nasir Naveed', 'department' => 'Packing', 'pay_type' => 'daily', 'daily_rate_paisa' => 140000]], 'Rate revised', $A2, true, $admin);
ok($mv['updated'] === 1 && $mv['pay_saved'] === 1 && Db::value('SELECT full_name FROM att_employees WHERE id = ?', [$nn]) === 'Nasir Naveed' && Db::value('SELECT code FROM att_employees WHERE id = ?', [$nn]) === 'N100', '  an existing person is edited in the same form: name, department and rate — the code is kept when not given');
ok(count(Fin::saveStaff([['employee_id' => $nn, 'pay_type' => 'daily', 'daily_rate_paisa' => 150000]], '', $A2, true, $admin)['errors']) === 1, '  a pay change with no reason is still refused');
$noM = Fin::saveStaff([['full_name' => 'Sneak In', 'department' => 'Stitching'], ['employee_id' => $nn, 'full_name' => 'Renamed By Accounts', 'pay_type' => 'daily', 'daily_rate_paisa' => 140000]], '', $C, false, $acc);
ok(count($noM['errors']) === 2 && $n("SELECT COUNT(*) FROM att_employees WHERE full_name IN ('Sneak In', 'Renamed By Accounts')") === 0, 'somebody who may set pay but not add employees cannot add or rename anyone through it');
ok(count(Fin::saveStaff([['employee_id' => $emp['D003'], 'pay_type' => 'daily', 'daily_rate_paisa' => 125000]], 'x', $H, false, $hod)['errors']) === 1, 'a HOD cannot change another department\'s employee, or anybody\'s pay');
ok(Fin::mayEditStaff($admin) && Fin::mayEditStaff($hr) && !Fin::mayEditStaff($acc) && !Fin::mayEditStaff($hod), 'adding and changing employees: Admin and HR');
ok(Fin::nextCode() !== Fin::nextCode([Fin::nextCode()]), 'two new lines in one save get two different codes');
$sm = Fin::statement($emp['D002'], '2026-03-01', '2026-03-05', $C);
ok($sm['ok'] && $sm['opening_paisa'] === 0 && count($sm['rows']) === 3 && $sm['totals'] === ['earned' => 240000, 'deducted' => 100000, 'paid' => 140000] && $sm['closing_paisa'] === 0, 'LEDGER PAGE for D002, 1–5 March: brought forward 0, three lines, totals 2,400 / 1,000 / 1,400, balance 0');
$refs = array_column($sm['rows'], 'ref');
ok(preg_match('/^WAG-2603-\d{6}$/', $refs[0]) === 1 && preg_match('/^REC-2603-\d{6}$/', $refs[1]) === 1 && preg_match('/^PAY-2603-\d{6}$/', $refs[2]) === 1 && count(array_unique($refs)) === 3, '  every line has its own reference: WAG (wages), REC (advance recovery), PAY (payment) — ' . implode(', ', $refs));
ok(array_column($sm['rows'], 'balance') === [240000, 140000, 0], '  and the balance after it');
$sm2 = Fin::statement($emp['D003'], '2026-03-06', '2026-03-06', $C);
ok($sm2['opening_paisa'] === 300000 && $sm2['rows'] === [] && $sm2['closing_paisa'] === 300000, '  a later window opens with the balance brought forward');
ok($code(Fin::statement($emp['S001'], '2026-03-01', '2026-03-05', $H)) === 'NOT_FOUND', '  a HOD gets no statement for a salaried person');

echo "12d. Entries from the Employee Ledger: earning, deduction, payment — always approved first\n";
Fin::setToday('2026-03-05');
$b3 = Fin::balance($emp['D003']); $b1 = Fin::balance($emp['D001']);
$e1 = Fin::createEntry('earning', '2026-03-05', [
    ['employee_id' => $emp['D003'], 'type' => 'bonus', 'amount_paisa' => 150000],
    ['employee_id' => $emp['D001'], 'type' => 'bonus', 'amount_paisa' => 150000],
    ['employee_id' => $emp['D001'], 'type' => 'overtime', 'label' => 'Sunday overtime · 4 h', 'amount_paisa' => 60000, 'reason' => 'Order 5236 shipment']], 'Eid bonus', $C, $acc);
ok($e1['ok'] && str_starts_with($e1['ref'], 'ENT-') && $e1['people'] === 2 && $e1['total_paisa'] === 360000, 'ADD EARNING for two people at once: one entry ENT-…, 2 people, 3,600 — ' . ($e1['error'] ?? ''));
ok(Fin::balance($emp['D003']) === $b3 && Fin::balance($emp['D001']) === $b1, '  nothing is in the ledger before it is approved');
ok($n("SELECT COUNT(*) FROM att_fin_item_lines l JOIN att_fin_sheet_items i ON i.id = l.item_id WHERE i.sheet_id = ? AND l.reason = 'Eid bonus'", [$e1['sheet_id']]) === 2
   && $n("SELECT COUNT(*) FROM att_fin_item_lines l JOIN att_fin_sheet_items i ON i.id = l.item_id WHERE i.sheet_id = ? AND l.reason = 'Order 5236 shipment'", [$e1['sheet_id']]) === 1, '  the shared reason goes on every line; a line\'s own reason wins');
ok($code(Fin::createEntry('earning', '2026-03-05', [['employee_id' => $emp['D001'], 'type' => 'bonus', 'amount_paisa' => 1000]], '', $C, $acc)) === 'REASON_REQUIRED', '  an earning needs a reason');
ok($code(Fin::createEntry('earning', '2026-03-05', [['employee_id' => $emp['D001'], 'type' => 'leave', 'amount_paisa' => 1000]], 'x', $C, $acc)) === 'VALIDATION', '  unpaid leave is not an earning');
ok($code(Fin::createEntry('earning', '2026-03-06', [['employee_id' => $emp['D001'], 'type' => 'bonus', 'amount_paisa' => 1000]], 'x', $C, $acc)) === 'VALIDATION', '  no date in the future');
ok($code(Fin::createEntry('earning', '2026-03-05', [['employee_id' => $emp['S001'], 'type' => 'bonus', 'amount_paisa' => 1000]], 'x', $H, $hod)) === 'FORBIDDEN'
   && $code(Fin::createEntry('earning', '2026-03-05', [['employee_id' => $emp['D003'], 'type' => 'bonus', 'amount_paisa' => 1000]], 'x', $H, $hod)) === 'FORBIDDEN', '  a HOD cannot make an entry for a salaried person, nor for another department');
ok($code(Fin::createEntry('earning', '2026-03-05', [['employee_id' => $emp['D001'], 'type' => 'bonus', 'amount_paisa' => 0]], 'x', $C, $acc)) === 'VALIDATION', '  an amount of zero is refused');
ok($code(Fin::amendSheet($e1['sheet_id'], [['employee_id' => $emp['D001'], 'changes' => []]], $U, $aud)) === 'VALIDATION', '  an entry is not amended: it is sent back and entered again');
ok($code(Fin::approve($e1['sheet_id'], $C, $acc)) === 'FORBIDDEN', '  Accounts cannot approve it');
$ap = Fin::approve($e1['sheet_id'], $U, $aud);
ok($ap['ok'] && $ap['status'] === 'posted', 'Audit approves: the entry is POSTED to the ledger (nothing to hand over)');
ok(Fin::balance($emp['D003']) === $b3 + 150000 && Fin::balance($emp['D001']) === $b1 + 210000, '  D003 is owed 1,500 more, D001 2,100 more');
$st = Fin::statement($emp['D001'], '2026-03-05', '2026-03-05', $C);
$refs = implode(' ', array_column($st['rows'], 'ref'));
ok(preg_match('/BON-2603-\d{6}/', $refs) === 1 && preg_match('/OT-2603-\d{6}/', $refs) === 1, '  the ledger shows BON- and OT- lines — ' . $refs);
ok(in_array($e1['sheet_id'], array_column($st['rows'], 'sheet_id'), true), '  each line knows the entry it came from, so a click opens it');
ok(Fin::overview($C, $acc)['sheets']['posted'] === 1, '  Home counts it as posted');

$b1 = Fin::balance($emp['D001']);
ok(str_contains((string) (Fin::createEntry('deduction', '2026-03-05', [['employee_id' => $emp['D001'], 'type' => 'deduction', 'amount_paisa' => $b1 + 100]], 'Damage', $C, $acc)['error'] ?? ''), 'cannot be taken off'),
   'ADD DEDUCTION: never more than is owed — the person would end up owing the company');
$e2 = Fin::createEntry('deduction', '2026-03-05', [['employee_id' => $emp['D001'], 'type' => 'leave', 'label' => 'Unpaid leave · 1 day', 'amount_paisa' => 50000]], 'Absent Saturday, not marked in Attendance', $C, $acc);
Fin::approve($e2['sheet_id'], $U, $aud);
ok(Fin::balance($emp['D001']) === $b1 - 50000 && preg_match('/LVE-2603-\d{6}/', implode(' ', array_column(Fin::statement($emp['D001'], '2026-03-05', '2026-03-05', $C)['rows'], 'ref'))) === 1, '  unpaid leave 500 is taken off, as an LVE- line');
$adv = Fin::giveAdvance(['employee_id' => $emp['D001'], 'kind' => 'loan', 'amount_paisa' => 200000, 'per_pay_paisa' => 50000, 'given_on' => '2026-03-05'], $acc)['advance_id'];
ok($code(Fin::createEntry('deduction', '2026-03-05', [['employee_id' => $emp['D001'], 'type' => 'loan', 'amount_paisa' => 50000]], 'x', $C, $acc)) === 'VALIDATION', '  a loan recovery must say which loan');
ok($code(Fin::createEntry('deduction', '2026-03-05', [['employee_id' => $emp['D001'], 'type' => 'loan', 'advance_id' => $adv, 'amount_paisa' => 250000]], 'x', $C, $acc)) === 'VALIDATION', '  and cannot take more than is still owed on it');
$b1 = Fin::balance($emp['D001']);
$e3 = Fin::createEntry('deduction', '2026-03-05', [['employee_id' => $emp['D001'], 'type' => 'loan', 'advance_id' => $adv, 'amount_paisa' => 100000]], 'Two instalments at once', $C, $acc);
Fin::approve($e3['sheet_id'], $U, $aud);
ok($n('SELECT recovered_paisa FROM att_fin_advances WHERE id = ?', [$adv]) === 100000 && Fin::balance($emp['D001']) === $b1 - 100000, '  LOAN RECOVERY 1,000: the loan is 1,000 lower and so is the balance (LREC- line)');

$b3 = Fin::balance($emp['D003']);
ok($code(Fin::createEntry('payment', '2026-03-05', [['employee_id' => $emp['D003'], 'amount_paisa' => $b3 + 1]], '', $C, $acc)) === 'VALIDATION', 'RECORD PAYMENT: never more than is owed');
$e4 = Fin::createEntry('payment', '2026-03-05', [['employee_id' => $emp['D003'], 'amount_paisa' => 100000, 'method' => 'bank']], 'Part of the old balance', $C, $acc);
ok($e4['ok'] && $e4['total_paisa'] === 100000, '  a part payment of 1,000 waits for approval');
$pv = $row(Fin::preview('daily', '2026-03-05', '2026-03-05', $C), $emp['D003']);
ok($pv === null || ($line($pv['lines'], 'carry')['calc_paisa'] ?? 0) === $b3 - 100000, '  meanwhile a new pay sheet offers only what is not already on its way — the same money is never paid twice');
ok($code(Fin::createEntry('payment', '2026-03-05', [['employee_id' => $emp['D003'], 'amount_paisa' => $b3 - 50000]], '', $C, $acc)) === 'VALIDATION', '  a second payment entry cannot take the same money either');
$ap4 = Fin::approve($e4['sheet_id'], $U, $aud);
ok($ap4['status'] === 'approved' && Fin::balance($emp['D003']) === $b3, '  approved, it is ready to pay — the balance moves only when it is paid');
ok(Fin::pay($e4['sheet_id'], '2026-03-05', '', $C, $acc)['ok'] && Fin::balance($emp['D003']) === $b3 - 100000
   && $n("SELECT COUNT(*) FROM att_fin_ledger WHERE sheet_id = ? AND kind = 'payment' AND method = 'bank' AND amount_paisa = 100000", [$e4['sheet_id']]) === 1, '  Accounts pays it: a PAY- line of 1,000 by bank, and 1,000 less is owed');

$b1 = Fin::balance($emp['D001']);
ok($code(Fin::cancelSheet($e1['sheet_id'], 'Bonus was for next month', $C, $acc)) === 'FORBIDDEN', 'a posted entry is cancelled by Audit or Admin only');
ok(Fin::cancelSheet($e1['sheet_id'], 'Bonus was for next month', $U, $aud)['ok'] && Fin::balance($emp['D001']) === $b1 - 210000, '  cancelled: its lines are struck through and the 2,100 comes off again');
$e5 = Fin::createEntry('earning', '2026-03-05', [['employee_id' => $emp['D001'], 'type' => 'production', 'amount_paisa' => 30000]], 'Line target met', $H, $hod);
ok($e5['ok'], 'a HOD may enter an incentive for their own department\'s daily worker');
ok($code(Fin::approve($e5['sheet_id'], $H, $hod)) === 'FORBIDDEN' && Fin::sendBack($e5['sheet_id'], 'Wrong amount', $H, $hod)['ok'], '  but not approve it; they can take it back');

echo "12e. Changing an advance's plan, and the card text in the one form\n";
ok($code(Fin::changeAdvance($adv, 20000, '', $acc)) === 'REASON_REQUIRED', 'changing a plan needs a reason');
ok($code(Fin::changeAdvance($adv, 999999999, 'x', $acc)) === 'VALIDATION', '  never more from each pay than is still owed');
ok(Fin::changeAdvance($adv, 0, 'Eid month: no recovery', $acc)['ok'] && $n('SELECT per_pay_paisa FROM att_fin_advances WHERE id = ?', [$adv]) === 0, '  0 pauses the recovery');
$pz = $row(Fin::preview('daily', '2026-02-27', '2026-03-05', $C), $emp['D001']);
ok($pz === null || $line($pz['lines'], 'adv:' . $adv) === null, '  and a paused loan puts no recovery line on the next sheet');
ok(Fin::changeAdvance($adv, 25000, 'Back to normal', $acc)['ok'] && $n("SELECT COUNT(*) FROM att_audit_logs WHERE action = 'fin.advance_change'") >= 2, '  the plan can be set again; every change is in the audit trail');
$cx = Fin::cancelAdvance($adv, 'x', $acc);
ok(!$cx['ok'], '  a loan with something already recovered cannot simply be cancelled');
$sv = Fin::saveStaff([['employee_id' => $emp['D002'], 'qr_payload' => 'CARD-D002-X']], '', $A, true, $admin);
ok($sv['ok'] && Db::value('SELECT qr_payload FROM att_employees WHERE id = ?', [$emp['D002']]) === 'CARD-D002-X', 'the card text is kept from the same one form');
ok(Fin::saveStaff([['employee_id' => $emp['D002'], 'full_name' => 'Dina Daily']], '', $A, true, $admin)['ok'] && Db::value('SELECT qr_payload FROM att_employees WHERE id = ?', [$emp['D002']]) === 'CARD-D002-X', '  and a save that does not mention it leaves it alone');

echo "12f. Mark attendance — a week of daily-wage workers on one screen (Admin and HR)\n";
Fin::setToday('2026-03-12');
$A = Fin::caps($admin); $R = Fin::caps($hr); $C = Fin::caps($acc); $H = Fin::caps($hod);
ok($A['mark'] && $R['mark'] && !$C['mark'] && !$H['mark'], 'Admin and HR may mark attendance; Accounts and a HOD may not');
ok($code(Fin::weekGrid('2026-03-06', 0, $C)) === 'FORBIDDEN' && $code(Fin::saveWeek([['employee_id' => $emp['D001'], 'day' => '2026-03-06', 'mark' => 'F']], [], 'x', $H, $hod)) === 'FORBIDDEN', '  and the server refuses the others');
$came('D002', '2026-03-09');
$g = Fin::weekGrid('2026-03-06', 0, $R);
$gw = array_column($g['workers'], null, 'code');
ok($g['ok'] && count($g['days']) === 7 && isset($gw['D001'], $gw['D002'], $gw['D003']) && !isset($gw['S001']) && !isset($gw['C001']), 'THE GRID: seven days, every daily-wage worker, nobody salaried or on contract');
ok($gw['D002']['days']['2026-03-09']['lock'] === 'scan' && $gw['D002']['days']['2026-03-09']['mark'] === 'F', '  a day the gate scanner recorded is shown as scanned and locked');
$T = ['full_in' => '08:00', 'full_out' => '18:00', 'half_in' => '08:00', 'half_out' => '11:30'];
ok($code(Fin::saveWeek([['employee_id' => $emp['D001'], 'day' => '2026-03-06', 'mark' => 'F']], $T, '', $R, $hr)) === 'REASON_REQUIRED', 'marking needs a reason');
ok($code(Fin::saveWeek([['employee_id' => $emp['D001'], 'day' => '2026-03-06', 'mark' => 'H']], ['half_out' => '13:00'] + $T, 'x', $R, $hr)) === 'VALIDATION', '  a "half day" long enough to count as a full day is refused');
$sw = Fin::saveWeek([['employee_id' => $emp['D001'], 'day' => '2026-03-06', 'mark' => 'F'], ['employee_id' => $emp['D001'], 'day' => '2026-03-07', 'mark' => 'H'],
                     ['employee_id' => $emp['D001'], 'day' => '2026-03-08', 'mark' => 'A'], ['employee_id' => $emp['D003'], 'day' => '2026-03-06', 'mark' => 'F']], $T, 'From the paper register', $R, $hr);
ok($sw['ok'] && $sw['saved'] === 3 && $sw['errors'] === [], 'HR marks D001 Full, Half, Absent and D003 Full: three days saved (an absent day with nothing there needs nothing) — ' . json_encode($sw['errors'] ?? $sw));
$dl = fn($code) => $line($row(Fin::preview('daily', '2026-03-06', '2026-03-12', Fin::caps($acc)), $emp[$code])['lines'] ?? [], 'daily');
ok(($dl('D001')['label'] ?? '') === 'Daily wages · 1.5 days' && ($dl('D003')['label'] ?? '') === 'Daily wages · 1 day', '  Prepare pay counts them like scans: D001 1.5 days, D003 1 day');
ok($n("SELECT COUNT(*) FROM att_corrections WHERE action = 'admin.entry_add' AND reason LIKE 'Marked in the week grid: From the paper register'") === 3, '  each marked day is an attendance entry with the reason, on record');
Fin::saveWeek([['employee_id' => $emp['D001'], 'day' => '2026-03-07', 'mark' => 'F'], ['employee_id' => $emp['D001'], 'day' => '2026-03-06', 'mark' => 'A']], $T, 'Register re-checked', $R, $hr);
$gw = array_column(Fin::weekGrid('2026-03-06', 0, $R)['workers'], null, 'code');
ok($gw['D001']['days']['2026-03-07']['mark'] === 'F' && $gw['D001']['days']['2026-03-06']['mark'] === null
   && $n("SELECT COUNT(*) FROM att_entry_cycles c JOIN att_shift_instances si ON si.id = c.shift_instance_id WHERE c.employee_id = ? AND si.work_date = '2026-03-07'", [$emp['D001']]) === 1,
   '  changed again: Half becomes Full (one entry, not two), Full becomes Absent (the entry goes)');
$bad = Fin::saveWeek([['employee_id' => $emp['D002'], 'day' => '2026-03-09', 'mark' => 'A'], ['employee_id' => $emp['D001'], 'day' => '2026-03-02', 'mark' => 'F'],
                      ['employee_id' => $emp['D001'], 'day' => '2026-03-13', 'mark' => 'F'], ['employee_id' => $emp['S001'], 'day' => '2026-03-06', 'mark' => 'F']], $T, 'x', $R, $hr);
ok($bad['saved'] === 0 && count($bad['errors']) === 4 && str_contains($bad['errors'][0], 'scanned at the gate') && str_contains($bad['errors'][1], 'already on pay sheet')
   && str_contains($bad['errors'][2], 'not come yet') && str_contains($bad['errors'][3], 'not a daily-wage worker'),
   '  never changed here: a scanned day, a day already on a pay sheet, a day to come, a salaried person — each says why');
Fin::setToday('2026-03-05');

echo "12g. Deductions without a second person, the administrator's one step, and taking one person off\n";
Fin::setToday('2026-03-12');
$A = Fin::caps($admin); $R = Fin::caps($hr); $C = Fin::caps($acc); $H = Fin::caps($hod); $U = Fin::caps($aud);
ok($A['direct_deduct'] && $C['direct_deduct'] && $R['direct_deduct'] && $U['direct_deduct'] && !$H['direct_deduct'], 'a deduction is posted at once by Admin, Accounts, HR and Audit — not by a HOD');
Fin::createEntry('earning', '2026-03-12', [['employee_id' => $emp['D002'], 'type' => 'bonus', 'amount_paisa' => 500000], ['employee_id' => $emp['D001'], 'type' => 'bonus', 'amount_paisa' => 500000]], 'Seed for the tests', $A, $admin, true);
$b2 = Fin::balance($emp['D002']);
$dd = Fin::createEntry('deduction', '2026-03-12', [['employee_id' => $emp['D002'], 'type' => 'deduction', 'label' => 'Penalty', 'amount_paisa' => 30000]], 'Late three times', $C, $acc);
ok($dd['ok'] && $dd['status'] === 'posted' && Fin::balance($emp['D002']) === $b2 - 30000, 'ACCOUNTS enters a penalty of 300: posted at once, no second person, 300 less is owed');
ok($n('SELECT approved_by FROM att_fin_sheets WHERE id = ?', [$dd['sheet_id']]) === $acc && $n("SELECT COUNT(*) FROM att_audit_logs WHERE action = 'fin.entry_direct' AND subject_id = ?", [$dd['sheet_id']]) === 1, '  it carries their name, and the trail says it was a direct office deduction');
ok(str_contains((string) (Fin::createEntry('deduction', '2026-03-12', [['employee_id' => $emp['D002'], 'type' => 'deduction', 'amount_paisa' => Fin::balance($emp['D002']) + 100]], 'x', $C, $acc)['error'] ?? ''), 'cannot be taken off'), '  still never more than is owed');
$hd = Fin::createEntry('deduction', '2026-03-12', [['employee_id' => $emp['D001'], 'type' => 'deduction', 'amount_paisa' => 10000]], 'Broken needle', $H, $hod);
ok($hd['ok'] && $hd['status'] === 'waiting', '  a HOD\'s deduction still waits for approval');
Fin::sendBack($hd['sheet_id'], 'test', $H, $hod);
Policy::set('fin_direct_deductions', '0', null, 'string'); Policy::flush();
$off = Fin::createEntry('deduction', '2026-03-12', [['employee_id' => $emp['D002'], 'type' => 'deduction', 'amount_paisa' => 10000]], 'x', Fin::caps($acc), $acc);
ok($off['status'] === 'waiting', '  and the whole rule can be switched off (policy fin_direct_deductions = 0): then it waits');
Fin::sendBack($off['sheet_id'], 'test', $C, $acc);
Policy::set('fin_direct_deductions', '1', null, 'string'); Policy::flush();
$hre = Fin::createEntry('earning', '2026-03-12', [['employee_id' => $emp['D002'], 'type' => 'production', 'amount_paisa' => 20000], ['employee_id' => $emp['C001'], 'type' => 'other_earning', 'label' => 'Production adjustment', 'amount_paisa' => 15000]], 'Rate corrected', $R, $hr);
ok($hre['ok'] && $hre['status'] === 'waiting', 'HR may enter an adjustment for a daily worker and a contractor (an earning waits for approval)');
ok(Fin::createEntry('earning', '2026-03-12', [['employee_id' => $emp['D002'], 'type' => 'bonus', 'amount_paisa' => 1000]], 'x', $C, $acc, true)['status'] === 'waiting', '"approve now" from anybody but an administrator is ignored: it waits');
$b2 = Fin::balance($emp['D002']);
$an = Fin::createEntry('earning', '2026-03-12', [['employee_id' => $emp['D002'], 'type' => 'bonus', 'amount_paisa' => 70000]], 'Target met', $A, $admin, true);
ok($an['status'] === 'posted' && Fin::balance($emp['D002']) === $b2 + 70000, 'THE ADMINISTRATOR, one step: an earning is entered and posted');
$b2 = Fin::balance($emp['D002']);
$ap = Fin::createEntry('payment', '2026-03-12', [['employee_id' => $emp['D002'], 'amount_paisa' => 50000, 'method' => 'cash']], '', $A, $admin, true);
ok($ap['status'] === 'paid' && Fin::balance($emp['D002']) === $b2 - 50000 && $n("SELECT COUNT(*) FROM att_fin_ledger WHERE sheet_id = ? AND kind = 'payment' AND amount_paisa = 50000", [$ap['sheet_id']]) === 1, '  a payment is entered, approved and paid: a PAY- line of 500');
ok($code(Fin::createEntry('payment', '2026-03-12', [['employee_id' => $emp['D002'], 'amount_paisa' => 99999999]], '', $A, $admin, true)) === 'VALIDATION', '  the limits are the same for the administrator: never more than is owed');

$two = Fin::createEntry('earning', '2026-03-12', [['employee_id' => $emp['D001'], 'type' => 'bonus', 'amount_paisa' => 40000], ['employee_id' => $emp['D002'], 'type' => 'bonus', 'amount_paisa' => 40000]], 'Eid', $C, $acc);
ok($code(Fin::removeFromSheet($two['sheet_id'], $emp['D001'], '', $C, $acc)) === 'REASON_REQUIRED' && $code(Fin::removeFromSheet($two['sheet_id'], $emp['D001'], 'x', $H, $hod)) === 'FORBIDDEN', 'TAKING ONE PERSON OFF needs a reason, and is not for a stranger to the sheet');
$rm = Fin::removeFromSheet($two['sheet_id'], $emp['D001'], 'Wrong person', $C, $acc);
ok($rm['ok'] && $rm['people_left'] === 1 && !$rm['closed'] && $n('SELECT status = \'waiting\' FROM att_fin_sheets WHERE id = ?', [$two['sheet_id']]) === 1, '  from a waiting entry: only that person leaves, the entry still waits with the other');
$b1 = Fin::balance($emp['D001']); $b2 = Fin::balance($emp['D002']);
Fin::approve($two['sheet_id'], $U, $aud);
ok(Fin::balance($emp['D001']) === $b1 && Fin::balance($emp['D002']) === $b2 + 40000, '  approved afterwards, only the one left on it is posted');
$lo = Fin::giveAdvance(['employee_id' => $emp['D002'], 'kind' => 'advance', 'amount_paisa' => 60000, 'per_pay_paisa' => 20000, 'given_on' => '2026-03-12'], $acc)['advance_id'];
$rc = Fin::createEntry('deduction', '2026-03-12', [['employee_id' => $emp['D002'], 'type' => 'advance', 'advance_id' => $lo, 'amount_paisa' => 20000], ['employee_id' => $emp['D001'], 'type' => 'deduction', 'amount_paisa' => 5000]], 'Early recovery', $C, $acc);
$b2 = Fin::balance($emp['D002']); $b1 = Fin::balance($emp['D001']);
ok($rc['status'] === 'posted' && $n('SELECT recovered_paisa FROM att_fin_advances WHERE id = ?', [$lo]) === 20000 && $code(Fin::removeFromSheet($rc['sheet_id'], $emp['D002'], 'x', $C, $acc)) === 'FORBIDDEN', '  from a POSTED entry it is for Admin or Audit, not Accounts');
$rp = Fin::removeFromSheet($rc['sheet_id'], $emp['D002'], 'Recovered twice by mistake', $U, $aud);
ok($rp['ok'] && Fin::balance($emp['D002']) === $b2 + 20000 && Fin::balance($emp['D001']) === $b1 && $n('SELECT recovered_paisa FROM att_fin_advances WHERE id = ?', [$lo]) === 0
   && $n("SELECT COUNT(*) FROM att_fin_ledger WHERE sheet_id = ? AND employee_id = ? AND cancelled_at IS NOT NULL AND cancel_reason = 'Recovered twice by mistake'", [$rc['sheet_id'], $emp['D002']]) === 1,
   '  Audit takes one person off a posted entry: their line is struck through with the reason, the advance is owed again, the other person is untouched');
$last = Fin::removeFromSheet($rc['sheet_id'], $emp['D001'], 'Not needed either', $U, $aud);
ok($last['closed'] && $n('SELECT status = \'cancelled\' FROM att_fin_sheets WHERE id = ?', [$rc['sheet_id']]) === 1 && Fin::balance($emp['D001']) === $b1 + 5000, '  when the last person goes the entry is closed');
ok($code(Fin::removeFromSheet($ap['sheet_id'], $emp['D002'], 'x', $A, $admin)) === 'ALREADY_DECIDED', '  never from a sheet that has been paid: money has been handed over');
Fin::setToday('2026-03-05');

echo "12h. Production adjustment for many contractors: pieces x rate, a fixed amount, a percentage, or a corrected line\n";
Fin::setToday('2026-03-12');
$A = Fin::caps($admin); $R = Fin::caps($hr); $C = Fin::caps($acc); $H = Fin::caps($hod); $U = Fin::caps($aud); $G = Fin::caps($gk);
$pdo->exec("INSERT INTO zp_workers (worker_code, worker_name) VALUES ('W002', 'Bilal Bulk')");
$w2 = (int) $pdo->lastInsertId();
Db::run("UPDATE att_employees SET worker_code = 'W002' WHERE id = ?", [$n1]);
Fin::saveProfile($n1, ['pay_type' => 'contract', 'pay_method' => 'cash'], 'Now paid by production', $acc);
$pdo->exec("INSERT INTO zp_entries (entry_date, worker_id, qty, rate_applied, amount) VALUES ('2026-03-10', $w2, 1200, 8, 9600.00), ('2026-03-11', $w2, 200, 12.5, 2500.00), ('2026-03-10', 1, 100, 10, 1000.00)");
$pb = Fin::productionBill('2026-03-01', '2026-03-12', 0, $R);
$pc = array_column($pb['contractors'], null, 'code');
ok($pb['ok'] && isset($pc['C001'], $pc['B001']) && $pc['B001']['booked_paisa'] === 1210000 && (float) $pc['B001']['booked_qty'] === 1400.0 && count($pc['B001']['lines']) === 2,
   'THE BILL: every contractor with what Production booked between the dates — pieces, amount and each line (HR may see it)');
ok($code(Fin::productionBill('2026-03-01', '2026-03-12', 0, $G)) === 'FORBIDDEN' && $code(Fin::adjustProduction('2026-03-01', '2026-03-12', [['employee_id' => $n1, 'parts' => [['kind' => 'fixed', 'amount_paisa' => -100]]]], 'x', $G, $gk)) === 'FORBIDDEN', '  not for someone with no finance job');
$l2 = $pc['B001']['lines'][1]['id'];
$b = Fin::balance($n1); $sheets0 = $n('SELECT COUNT(*) FROM att_fin_sheets');
ok($code(Fin::adjustProduction('2026-03-01', '2026-03-12', [['employee_id' => $n1, 'parts' => [['kind' => 'line', 'entry_id' => $l2, 'qty' => 180, 'rate' => 12.5]]]], '', $C, $acc)) === 'REASON_REQUIRED', 'an adjustment needs a reason');
ok($code(Fin::adjustProduction('2026-03-01', '2026-03-12', [['employee_id' => $n1, 'parts' => [['kind' => 'line', 'entry_id' => $l2, 'qty' => 200, 'rate' => 12.5]]]], 'x', $C, $acc)) === 'VALIDATION', '  correcting a line to what it already is changes nothing');
$a1 = Fin::adjustProduction('2026-03-01', '2026-03-12', [['employee_id' => $n1, 'parts' => [['kind' => 'line', 'entry_id' => $l2, 'qty' => 180, 'rate' => 12.5]]]], '20 pieces rejected in checking', $C, $acc);
ok($a1['ok'] && $a1['taken_off']['status'] === 'posted' && $a1['taken_off']['total_paisa'] === 25000 && $a1['added'] === null && Fin::balance($n1) === $b - 25000,
   'A CORRECTED LINE: 200 x 12.50 booked, 180 correct — 250 is taken off at once (Accounts), worked out on the server');
ok((float) Db::value('SELECT qty FROM zp_entries WHERE id = ?', [$l2]) === 200.0, '  what Production booked is not changed');
$lab = (string) Db::value("SELECT label FROM att_fin_ledger WHERE sheet_id = ? AND employee_id = ?", [$a1['taken_off']['sheet_id'], $n1]);
ok(str_contains($lab, 'Production adjustment') && str_contains($lab, '200 × 12.5 → 180 × 12.5') && str_contains($lab, '20 pieces rejected'), '  the ledger line says exactly what was corrected and why — ' . $lab);
$b = Fin::balance($n1); $bk = Fin::balance($emp['C001']);
$a2 = Fin::adjustProduction('2026-03-01', '2026-03-12', [
    ['employee_id' => $n1, 'reason' => 'Rate agreed 8.50, booked at 8.00', 'parts' => [['kind' => 'pieces', 'qty' => 1200, 'rate' => 0.5]]],
    ['employee_id' => $emp['C001'], 'parts' => [['kind' => 'fixed', 'amount_paisa' => -10000]]]], 'Month-end check', $C, $acc);
ok($a2['ok'] && ($a2['added']['status'] ?? '') === 'waiting' && $a2['added']['total_paisa'] === 60000 && $a2['taken_off']['status'] === 'posted' && $a2['taken_off']['total_paisa'] === 10000
   && Fin::balance($n1) === $b && Fin::balance($emp['C001']) === $bk - 10000, 'MANY AT ONCE: +600 for one (1,200 x 0.50 — waits for approval), a fixed −100 for another (taken off at once) — ' . ($a2['error'] ?? ''));
ok($n("SELECT COUNT(*) FROM att_fin_item_lines l JOIN att_fin_sheet_items i ON i.id = l.item_id WHERE i.sheet_id = ? AND l.reason = 'Rate agreed 8.50, booked at 8.00'", [(int) ($a2['added']['sheet_id'] ?? 0)]) === 1
   && $n("SELECT COUNT(*) FROM att_fin_item_lines l JOIN att_fin_sheet_items i ON i.id = l.item_id WHERE i.sheet_id = ? AND l.reason = 'Month-end check'", [(int) ($a2['taken_off']['sheet_id'] ?? 0)]) === 1, '  a line\'s own reason, or the one reason for all');
Fin::approve((int) ($a2['added']['sheet_id'] ?? 0), $U, $aud);
$b = Fin::balance($n1);
$a3 = Fin::adjustProduction('2026-03-01', '2026-03-12', [['employee_id' => $n1, 'parts' => [['kind' => 'percent', 'pct' => -2]]]], 'Quality penalty, 2% of the bill', $R, $hr);
ok($a3['ok'] && $a3['taken_off']['total_paisa'] === 24200 && Fin::balance($n1) === $b - 24200, 'A PERCENTAGE: 2% of the 12,100 booked = 242, taken off (HR)');
ok(str_contains((string) Db::value("SELECT label FROM att_fin_ledger WHERE sheet_id = ?", [$a3['taken_off']['sheet_id']]), '2% of 12,100'), '  and the line says "2% of 12,100"');
$b = Fin::balance($n1);
$a4 = Fin::adjustProduction('2026-03-01', '2026-03-12', [['employee_id' => $n1, 'parts' => [['kind' => 'pieces', 'qty' => -40, 'rate' => 5], ['kind' => 'fixed', 'amount_paisa' => 30000]]]], 'Recount', $A, $admin, true);
ok($a4['taken_off']['status'] === 'posted' && $a4['added']['status'] === 'posted' && Fin::balance($n1) === $b - 20000 + 30000, 'THE ADMINISTRATOR, one step: −200 (−40 x 5.00) and +300, both posted');
$cnt = $n('SELECT COUNT(*) FROM att_fin_sheets'); $b = Fin::balance($n1); $bk = Fin::balance($emp['C001']);
$bad = Fin::adjustProduction('2026-03-01', '2026-03-12', [['employee_id' => $emp['C001'], 'parts' => [['kind' => 'fixed', 'amount_paisa' => -5000]]], ['employee_id' => $n1, 'parts' => [['kind' => 'fixed', 'amount_paisa' => -99999999]]]], 'x', $C, $acc);
ok(!$bad['ok'] && str_contains((string) $bad['error'], 'cannot be taken off') && $n('SELECT COUNT(*) FROM att_fin_sheets') === $cnt && Fin::balance($n1) === $b && Fin::balance($emp['C001']) === $bk,
   'ALL OR NOTHING: one contractor\'s deduction is more than they are owed, so nothing is saved for anybody');
ok($code(Fin::adjustProduction('2026-03-01', '2026-03-12', [['employee_id' => $emp['D001'], 'parts' => [['kind' => 'fixed', 'amount_paisa' => -100]]]], 'x', $C, $acc)) === 'VALIDATION', '  only a contractor linked to Production');
ok($code(Fin::adjustProduction('2026-03-01', '2026-03-12', [['employee_id' => $n1, 'parts' => [['kind' => 'line', 'entry_id' => 1, 'qty' => 1, 'rate' => 1]]]], 'x', $C, $acc)) === 'VALIDATION', '  and only their own lines: another contractor\'s line is refused');
$hb = Fin::productionBill('2026-03-01', '2026-03-12', 0, $H);
ok($hb['ok'] && array_filter($hb['contractors'], fn($c) => $c['department_name'] !== 'Stitching') === [], '  a HOD sees and adjusts only their own department\'s contractors');
Fin::setToday('2026-03-05');

echo "12i. Deleting what was entered by mistake — and never what has history\n";
$z = Employees::create(['code' => 'Z999', 'full_name' => 'Typed Twice', 'department_id' => $dP]);
Fin::saveProfile($z, ['pay_type' => 'daily', 'daily_rate_paisa' => 100000, 'pay_method' => 'cash'], 'x', $acc);
$day0 = Shifts::create(['name' => 'Spare', 'start_time' => '09:00', 'end_time' => '17:00']);
Db::run("INSERT INTO att_shift_instances (employee_id, shift_id, work_date, starts_at, ends_at) VALUES (?, ?, '2026-03-20', '2026-03-20 04:00:00', '2026-03-20 12:00:00')", [$z, $day0]);
ok($code(AdminService::deleteEmployee($z, '', $admin)) === 'REASON_REQUIRED', 'deleting needs a reason');
$dz = AdminService::deleteEmployee($z, 'Entered twice', $admin);
ok($dz['ok'] && $n('SELECT COUNT(*) FROM att_employees WHERE id = ?', [$z]) === 0 && $n('SELECT COUNT(*) FROM att_fin_profiles WHERE employee_id = ?', [$z]) === 0 && $n('SELECT COUNT(*) FROM att_shift_instances WHERE employee_id = ?', [$z]) === 0,
   'SOMEONE ENTERED BY MISTAKE (pay set up, a shift day, nothing else): deleted, with their pay setup and shift days — ' . ($dz['error'] ?? ''));
ok($n("SELECT COUNT(*) FROM att_audit_logs WHERE action = 'employee.delete' AND subject_id = ? AND old_data LIKE '%Typed Twice%'", [$z]) === 1, '  a full copy is kept in the audit trail, with the reason');
$dh = AdminService::deleteEmployee($emp['D001'], 'x', $admin);
ok(!$dh['ok'] && $dh['reason_code'] === 'HAS_HISTORY' && str_contains($dh['error'], 'gate scans') && str_contains($dh['error'], 'Mark as left the company') && $n('SELECT COUNT(*) FROM att_employees WHERE id = ?', [$emp['D001']]) === 1,
   'SOMEONE WITH HISTORY is never deleted: the answer says what they have and points to "left the company" — ' . $dh['error']);
ok($code(AdminService::deleteDepartment($dP, 'x', $admin)) === 'DEPARTMENT_IN_USE', 'a department with people in it is not deleted');
$md = Employees::createDepartment('Made By Mistake');
ok(AdminService::deleteDepartment($md, 'Typo', $admin)['ok'] && $n('SELECT COUNT(*) FROM att_departments WHERE id = ?', [$md]) === 0, '  an empty one made by mistake is');
$hd = Employees::createDepartment('Has A HOD');
Roles::grant($hr, Roles::HOD, $hd);
ok(str_contains((string) (AdminService::deleteDepartment($hd, 'x', $admin)['error'] ?? ''), 'job given to a user'), '  one a HOD is tied to says so, and stays');

echo "13. Rollback\n";
$m = new Migrations($B . 'sql/att');
$m->down(16);
ok(!Db::tableExists('att_fin_ledger') && !Db::tableExists('att_fin_profiles') && $n("SELECT COUNT(*) FROM att_user_roles WHERE role = 'hr'") === 1, 'migration 0017 rolls back cleanly and leaves the other roles');
$m->up();
ok(Db::tableExists('att_fin_ledger'), 'and goes up again');

echo "\nPEOPLE FINANCE (engine): $P passed, $F failed\n";
exit($F ? 1 : 0);
