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

echo "13. Rollback\n";
$m = new Migrations($B . 'sql/att');
$m->down(16);
ok(!Db::tableExists('att_fin_ledger') && !Db::tableExists('att_fin_profiles') && $n("SELECT COUNT(*) FROM att_user_roles WHERE role = 'hr'") === 1, 'migration 0017 rolls back cleanly and leaves the other roles');
$m->up();
ok(Db::tableExists('att_fin_ledger'), 'and goes up again');

echo "\nPEOPLE FINANCE (engine): $P passed, $F failed\n";
exit($F ? 1 : 0);
