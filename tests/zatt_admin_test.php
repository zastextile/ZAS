<?php
declare(strict_types=1);

/* ATTENDANCE — SETUP SCREEN (departments, employees, shifts, people, settings)
 *
 * Setup is where a typing mistake becomes a payroll dispute three weeks
 * later, so what is tested here is mostly what the screen must REFUSE:
 *
 *   - changing the code of an employee who already has scans
 *   - making somebody inactive while they are inside the factory
 *   - switching off a department that still has people, or the default shift
 *   - re-pointing a day that already has entries at another shift
 *   - an import that overwrites people already on the roll
 *   - a setup clerk handing out attendance access, or changing the settings
 *
 * Three layers, in this order: the service on its own, the same thing over
 * real HTTP with the ERP's real session/permission code, and the screen
 * itself in Chromium.
 */

$B = __DIR__ . '/app_src/public_html/';
$P = 0; $F = 0;
function ok($c, $m) { global $P, $F; if ($c) { $P++; } else { $F++; echo "  FAIL: $m\n"; } }

$dsn = getenv('ATT_TEST_DSN') ?: 'mysql:host=127.0.0.1;port=3306;dbname=zas_att_test;charset=utf8mb4';
$dbu = getenv('ATT_TEST_USER') ?: 'zas';
$dbp = getenv('ATT_TEST_PASS'); if ($dbp === false) { $dbp = 'zaspw'; }

if (!is_file($B . 'config/config.php')) {
    echo "SKIP: no test config at public_html/config/config.php — run tests/run_att.sh\n";
    exit(0);
}
foreach (['Db','Time','Migrations','Policy','Audit','Roles','Employees','Shifts','Receipts','AttendanceService','HodService','Reconciler','SyncService','HrService','AdminService'] as $c) {
    require_once $B . "includes/att/$c.php";
}

use Att\AdminService as A;
use Att\Db;
use Att\Employees;
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
/* The stand-in for the ERP's users table carries the legacy permission
   columns, because the People tab saves through the ERP's own
   zu_save_perms(), which writes them. */
$legacy = ['cost_view','cost_create','cost_edit','cost_delete','cost_import','cost_print','cost_proforma',
           'inv_view','inv_gate','inv_store','inv_consume','inv_master','inv_post','inv_adjust'];
$pdo->exec("CREATE TABLE users (id INT AUTO_INCREMENT PRIMARY KEY, name VARCHAR(150) NOT NULL,
            email VARCHAR(190) NOT NULL UNIQUE, password_hash VARCHAR(255) NOT NULL,
            role VARCHAR(40) NOT NULL DEFAULT 'colleague', department VARCHAR(60) NULL,
            can_see_rates TINYINT(1) NOT NULL DEFAULT 0, is_active TINYINT(1) NOT NULL DEFAULT 1, "
            . implode(', ', array_map(fn($c) => "$c TINYINT(1) NOT NULL DEFAULT 0", $legacy)) . ")");
$pdo->exec("CREATE TABLE zu_perm (id INT AUTO_INCREMENT PRIMARY KEY, user_id INT NOT NULL, module VARCHAR(24) NOT NULL,
            actions VARCHAR(12) NOT NULL DEFAULT '', updated_by INT NULL, updated_at DATETIME DEFAULT CURRENT_TIMESTAMP,
            UNIQUE KEY uniq_user_module (user_id, module), INDEX(user_id))");
/* Deliberately a different collation from the attendance tables, as the live
   ERP table may well be: the worker comparison must not depend on it. */
$pdo->exec("CREATE TABLE zp_workers (id INT AUTO_INCREMENT PRIMARY KEY, worker_code VARCHAR(40) NOT NULL UNIQUE,
            worker_name VARCHAR(120) NOT NULL, department VARCHAR(60) NULL,
            is_active TINYINT(1) NOT NULL DEFAULT 1) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci");
$mig = new Migrations($B . 'sql/att');
$mig->up();

$n   = fn(string $sql, array $p = []) => (int) Db::value($sql, $p);
$col = fn(string $c) => (int) Db::value(
    "SELECT CHARACTER_MAXIMUM_LENGTH FROM information_schema.columns WHERE table_schema = DATABASE() AND table_name = 'att_employees' AND column_name = ?", [$c]);

$hash = password_hash('CorrectHorse9', PASSWORD_DEFAULT);
$mk = function (string $name, string $email, string $role = 'staff') use ($pdo, $hash): int {
    $pdo->prepare("INSERT INTO users (name,email,password_hash,role) VALUES (?,?,?,?)")->execute([$name, $email, $hash, $role]);
    return (int) $pdo->lastInsertId();
};
$admin = $mk('Admin Person', 'admin@zas.test', 'admin');
$clerk = $mk('Clara Clerk', 'clerk@zas.test', 'colleague');
$gk    = $mk('Gul Gatekeeper', 'gate@zas.test', 'colleague');
$hodU  = $mk('Hina Hod', 'hod@zas.test', 'colleague');

/* ===================================================================== */
echo "1. A long employee number fits, and rolling back never cuts one\n";
ok($col('code') === 40 && $col('worker_code') === 40, 'code and worker_code are 40 characters wide after 0012');
$dTmp = Employees::createDepartment('Temp');
$long = 'HR-2026-KHI-000000000000000000000-0042';          // 38 characters
Employees::create(['code' => $long, 'full_name' => 'Long Code', 'department_id' => $dTmp]);
$mig->down(11);
ok($col('code') === 40, 'with a 38-character code on the roll, the rollback leaves the column wide');
ok(Db::value('SELECT code FROM att_employees WHERE full_name = ?', ['Long Code']) === $long, '  and the code is intact, not cut');
$mig->up();
$pdo->exec("DELETE FROM att_employees"); $pdo->exec("DELETE FROM att_departments");
$mig->down(11);
ok($col('code') === 32 && $col('worker_code') === 20, 'with nothing long stored, the rollback restores the old widths');
$mig->up();
ok($col('code') === 40, 'and up widens them again');

/* ===================================================================== */
echo "2. Departments\n";
$r = A::saveDepartment(null, '  Stitching  ', null, $admin);
$dS = (int) ($r['department_id'] ?? 0);
ok($r['ok'] && Db::value('SELECT name FROM att_departments WHERE id = ?', [$dS]) === 'Stitching', 'added, with the stray spaces removed');
$dP = (int) A::saveDepartment(null, 'Packing', null, $admin)['department_id'];
ok((A::saveDepartment(null, 'stitching', null, $admin)['reason_code'] ?? '') === 'NAME_TAKEN', 'the same name in other letters is refused');
ok((A::saveDepartment(null, '   ', null, $admin)['reason_code'] ?? '') === 'VALIDATION', 'a blank name is refused');
ok((A::saveDepartment($dP, 'Stitching', null, $admin)['reason_code'] ?? '') === 'NAME_TAKEN', 'renaming onto another department\'s name is refused');
ok(A::saveDepartment($dP, 'Packing & Dispatch', null, $admin)['ok'], 'renamed');
ok($n("SELECT COUNT(*) FROM att_audit_logs WHERE action = 'department.update' AND subject_id = ? AND JSON_EXTRACT(old_data, '$.name') = 'Packing'", [$dP]) === 1,
   'the rename is in the audit log with the old name');

/* ===================================================================== */
echo "3. Employees: what a code may be\n";
$e1 = (int) (A::saveEmployee(null, ['code' => ' E001 ', 'full_name' => "Imran\tAli", 'department_id' => $dS], $admin)['employee_id'] ?? 0);
ok($e1 > 0 && Db::value('SELECT full_name FROM att_employees WHERE id = ?', [$e1]) === 'Imran Ali', 'added; a tab inside the name becomes one space');
ok((A::saveEmployee(null, ['code' => 'E 002', 'full_name' => 'X', 'department_id' => $dS], $admin)['reason_code'] ?? '') === 'VALIDATION', 'a code with a space in it is refused');
ok((A::saveEmployee(null, ['code' => 'e001', 'full_name' => 'Other', 'department_id' => $dS], $admin)['reason_code'] ?? '') === 'CODE_TAKEN', 'a code already in use is refused, whatever the letter case');
ok((A::saveEmployee(null, ['code' => 'E002', 'full_name' => '', 'department_id' => $dS], $admin)['reason_code'] ?? '') === 'VALIDATION', 'no name is refused');
ok((A::saveEmployee(null, ['code' => 'E002', 'full_name' => 'Sara', 'department_id' => 9999], $admin)['reason_code'] ?? '') === 'VALIDATION', 'a department that does not exist is refused');
ok((A::saveEmployee(null, ['code' => ['E9'], 'full_name' => 'Array', 'department_id' => $dS], $admin)['reason_code'] ?? '') === 'VALIDATION', 'a code that is not text is refused, not stored as "Array"');
$e2 = (int) A::saveEmployee(null, ['code' => 'E002', 'full_name' => 'Sara Khan', 'department_id' => $dP, 'qr_payload' => 'CARD-7781'], $admin)['employee_id'];
ok($e2 > 0, 'a second employee, with card text');
/* The gate looks a scan up as "code OR card text" — these two must never overlap. */
ok((A::saveEmployee(null, ['code' => 'CARD-7781', 'full_name' => 'Clash', 'department_id' => $dS], $admin)['reason_code'] ?? '') === 'CODE_TAKEN',
   'A NEW CODE THAT IS SOMEBODY ELSE\'S CARD TEXT IS REFUSED');
ok((A::saveEmployee(null, ['code' => 'E003', 'full_name' => 'Clash', 'department_id' => $dS, 'qr_payload' => 'E001'], $admin)['reason_code'] ?? '') === 'QR_TAKEN',
   'CARD TEXT THAT IS SOMEBODY ELSE\'S CODE IS REFUSED');
ok($n("SELECT COUNT(*) FROM att_employees WHERE full_name = 'Clash'") === 0, '  and neither was written');

$pdo->exec("INSERT INTO zp_workers (worker_code, worker_name, department) VALUES ('W100','Bilal Ahmed','Stitching'),('W101','Nadia Noor','Finishing'),('W102','Old Hand','Stitching')");
$pdo->exec("UPDATE zp_workers SET is_active = 0 WHERE worker_code = 'W102'");
$e3 = (int) A::saveEmployee(null, ['code' => 'E003', 'full_name' => 'Bilal Ahmed', 'department_id' => $dS, 'worker_code' => 'W100'], $admin)['employee_id'];
ok((int) Db::value("SELECT att_employee_id FROM zp_workers WHERE worker_code = 'W100'") === $e3, 'a production worker code links the two records');
ok((A::saveEmployee(null, ['code' => 'E004', 'full_name' => 'Twin', 'department_id' => $dS, 'worker_code' => 'w100'], $admin)['reason_code'] ?? '') === 'WORKER_ALREADY_LINKED',
   'one production worker cannot be two employees');
A::saveEmployee($e3, ['code' => 'E003', 'full_name' => 'Bilal Ahmed', 'department_id' => $dS, 'worker_code' => ''], $admin);
ok(Db::value("SELECT att_employee_id FROM zp_workers WHERE worker_code = 'W100'") === null, 'clearing the link clears it on the production side too');

/* ===================================================================== */
echo "4. Employees: what may not change once attendance exists\n";
$day = Shifts::create(['name' => 'Day', 'start_time' => '09:00', 'end_time' => '17:00']);
Policy::set('default_shift_id', (string) $day, null, 'string'); Policy::flush();
Roles::grant($gk, Roles::GATEKEEPER);
const GATE = 'aaaaaaaa-aaaa-4aaa-8aaa-aaaaaaaaaaaa';
$dev = (int) S::registerDevice(GATE, 'Gate A', 'gate', $gk)['device_id'];
$pk  = new DateTimeZone('Asia/Karachi');
$today     = (new DateTimeImmutable('now', $pk))->format('Y-m-d');
$yesterday = (new DateTimeImmutable('now', $pk))->modify('-1 day')->format('Y-m-d');
$tomorrow  = (new DateTimeImmutable('now', $pk))->modify('+1 day')->format('Y-m-d');
$seq = 0; $un = 0;
/* A scan made yesterday at the given UTC time (05:00Z is 10:00 at the factory). */
$scan = function (string $type, string $code, string $hmUtc) use (&$seq, &$un, $gk, $yesterday): array {
    return S::process(['event_uuid' => sprintf('%08x-0000-4000-8000-%012x', random_int(0, 0xffffffff), ++$un),
        'device_id' => GATE, 'device_sequence' => ++$seq, 'employee_code' => $code, 'event_type' => $type,
        'occurred_at' => "{$yesterday}T{$hmUtc}:00Z", 'timezone' => 'UTC', 'payload' => []], $gk, true);
};
ok(A::saveEmployee($e2, ['code' => 'E002B', 'full_name' => 'Sara Khan', 'department_id' => $dP, 'qr_payload' => 'CARD-7781'], $admin)['ok'],
   'with no attendance yet, a mistyped code can still be corrected');
A::saveEmployee($e2, ['code' => 'E002', 'full_name' => 'Sara Khan', 'department_id' => $dP, 'qr_payload' => 'CARD-7781'], $admin);

$in = $scan('GATE_IN', 'E001', '05:00');
ok($in['receipt_status'] === 'ACCEPTED', '(seed) E001 came in yesterday at 10:00 factory time');
$locked = A::saveEmployee($e1, ['code' => 'E001X', 'full_name' => 'Imran Ali', 'department_id' => $dS], $admin);
ok(($locked['reason_code'] ?? '') === 'CODE_LOCKED', 'ONCE THERE IS A SCAN, THE CODE CANNOT BE CHANGED');
ok(Db::value('SELECT code FROM att_employees WHERE id = ?', [$e1]) === 'E001', '  and it was not');
ok(A::saveEmployee($e1, ['code' => 'e001', 'full_name' => 'Imran Ali Khan', 'department_id' => $dP], $admin)['ok'], 'the name and department can still be edited');
$au = Db::one("SELECT old_data, new_data FROM att_audit_logs WHERE action = 'employee.update' AND subject_id = ? ORDER BY id DESC LIMIT 1", [$e1]);
ok(json_decode($au['old_data'], true)['full_name'] === 'Imran Ali' && (int) json_decode($au['new_data'], true)['department_id'] === $dP,
   'the edit is in the audit log, before and after');
A::saveEmployee($e1, ['code' => 'E001', 'full_name' => 'Imran Ali', 'department_id' => $dS], $admin);

ok((A::setEmployeeActive($e1, false, '', $admin)['reason_code'] ?? '') === 'REASON_REQUIRED', 'making somebody inactive needs a reason');
ok((A::setEmployeeActive($e1, false, 'left the company', $admin)['reason_code'] ?? '') === 'EMPLOYEE_INSIDE',
   'SOMEBODY WHO IS INSIDE THE FACTORY CANNOT BE MADE INACTIVE');
ok((int) Db::value('SELECT active FROM att_employees WHERE id = ?', [$e1]) === 1, '  they are still active');
$list = A::employees(['query' => 'imran']);
ok(count($list['employees']) === 1 && (int) $list['employees'][0]['inside'] === 1, 'the list shows them as inside now');
$scan('GATE_OUT', 'E001', '06:00');
ok(A::setEmployeeActive($e1, false, 'left the company', $admin)['ok'], 'after the exit scan it is allowed');
ok(Db::value("SELECT reason FROM att_audit_logs WHERE action = 'employee.deactivate' AND subject_id = ?", [$e1]) === 'left the company', '  and the reason is kept');
ok(($scan('GATE_IN', 'E001', '07:00')['reason_code'] ?? '') === 'EMPLOYEE_INACTIVE', 'an inactive employee is refused at the gate');
ok(count(A::employees(['state' => 'active'])['employees']) === 2 && count(A::employees(['state' => 'inactive'])['employees']) === 1
   && A::employees(['state' => 'all'])['total'] === 3, 'the Active / Inactive / Everyone filters count correctly');
ok(count(A::employees(['query' => '%'])['employees']) === 0, 'a % typed in the search box is a character, not a wildcard');
ok(A::setEmployeeActive($e1, true, '', $admin)['ok'], 'made active again');

ok((A::saveDepartment($dS, 'Stitching', false, $admin)['reason_code'] ?? '') === 'DEPARTMENT_IN_USE', 'A DEPARTMENT WITH ACTIVE EMPLOYEES CANNOT BE SWITCHED OFF');
$dEmpty = (int) A::saveDepartment(null, 'Closed Unit', null, $admin)['department_id'];
ok(A::saveDepartment($dEmpty, 'Closed Unit', false, $admin)['ok'], 'an empty one can');
ok((A::saveEmployee(null, ['code' => 'E050', 'full_name' => 'Nobody', 'department_id' => $dEmpty], $admin)['reason_code'] ?? '') === 'VALIDATION',
   'nobody new can be put in a switched-off department');

/* ===================================================================== */
echo "5. Adding many at once\n";
$rows = [
    ['code' => 'E010', 'full_name' => 'Asad Mir',    'department' => 'stitching'],
    ['code' => 'E011', 'full_name' => 'Rabia Aslam', 'department' => 'Finishing'],
    ['code' => 'E001', 'full_name' => 'Somebody Else', 'department' => 'Stitching'],
    ['code' => 'E010', 'full_name' => 'Asad Again',  'department' => 'Stitching'],
    ['code' => '',     'full_name' => 'No Code',     'department' => 'Stitching'],
    ['code' => 'E012', 'full_name' => 'Off Dept',    'department' => 'Closed Unit'],
];
$before = $n('SELECT COUNT(*) FROM att_employees');
$dry = A::importEmployees($rows, false, false, $admin);
ok($dry['ok'] && $dry['committed'] === false && $n('SELECT COUNT(*) FROM att_employees') === $before, 'CHECKING THE LIST WRITES NOTHING');
ok($dry['counts'] === ['add' => 1, 'exists' => 1, 'error' => 4], 'one to add, one already on the roll, four refused (got ' . json_encode($dry['counts']) . ')');
ok($dry['lines'][0]['status'] === 'add', '  a department is matched whatever the letter case');
ok($dry['lines'][1]['status'] === 'error' && str_contains($dry['lines'][1]['message'], 'no department'), '  an unknown department is refused when not asked to create it');
ok($dry['lines'][2]['status'] === 'exists' && str_contains($dry['lines'][2]['message'], 'Imran Ali'), '  a code already on the roll is left alone, and says whose it is');
ok($dry['lines'][3]['status'] === 'error' && str_contains($dry['lines'][3]['message'], 'line 1'), '  the same code twice in the list is refused, and says where');
ok($dry['lines'][5]['status'] === 'error' && str_contains($dry['lines'][5]['message'], 'switched off'), '  a switched-off department is refused');
$dry2 = A::importEmployees($rows, false, true, $admin);
ok($dry2['counts']['add'] === 2 && $dry2['new_departments'] === ['Finishing'], 'asked to create departments, the check says which it would create');
ok($n("SELECT COUNT(*) FROM att_departments WHERE name = 'Finishing'") === 0, '  and has not created it yet');
$done = A::importEmployees($rows, true, true, $admin);
ok($done['committed'] === true && $n('SELECT COUNT(*) FROM att_employees') === $before + 2, 'the import adds exactly the two good lines');
ok($n("SELECT COUNT(*) FROM att_departments WHERE name = 'Finishing' AND active = 1") === 1, '  and the one new department');
ok(Db::value("SELECT full_name FROM att_employees WHERE code = 'E001'") === 'Imran Ali', 'THE IMPORT DID NOT RENAME THE EMPLOYEE ALREADY ON THE ROLL');
$again = A::importEmployees($rows, true, true, $admin);
ok($again['counts']['add'] === 0 && $again['committed'] === false && $n('SELECT COUNT(*) FROM att_employees') === $before + 2, 'importing the same list twice adds nobody twice');
ok((A::importEmployees(array_fill(0, A::IMPORT_MAX + 1, ['code' => 'X', 'full_name' => 'Y', 'department' => 'Z']), false, false, $admin)['reason_code'] ?? '') === 'VALIDATION',
   'more than ' . A::IMPORT_MAX . ' lines at once is refused');

$w = A::unlinkedWorkers();
ok($w['available'] && array_column($w['workers'], 'code') === ['W100', 'W101'], 'production workers not on the roll are offered (inactive ones are not)');
A::saveEmployee($e3, ['code' => 'E003', 'full_name' => 'Bilal Ahmed', 'department_id' => $dS, 'worker_code' => 'W100'], $admin);
$w = A::unlinkedWorkers();
ok(array_column($w['workers'], 'code') === ['W101'], 'a linked one drops off the list — across tables of different collation');
$imp = A::importEmployees($w['workers'], true, true, $admin);
ok($imp['committed'] && (int) Db::value("SELECT att_employee_id FROM zp_workers WHERE worker_code = 'W101'") > 0, 'bringing them in links each to its production record');

/* ===================================================================== */
echo "6. Shifts\n";
ok((A::saveShift(null, ['name' => 'Day', 'start_time' => '08:00', 'end_time' => '16:00'], $admin)['reason_code'] ?? '') === 'NAME_TAKEN', 'a second shift with the same name is refused');
ok((A::saveShift(null, ['name' => 'Zero', 'start_time' => '08:00', 'end_time' => '08:00'], $admin)['reason_code'] ?? '') === 'VALIDATION', 'a shift that starts and ends at the same time is refused');
ok((A::saveShift(null, ['name' => 'Bad', 'start_time' => '25:00', 'end_time' => '08:00'], $admin)['reason_code'] ?? '') === 'VALIDATION', '25:00 is not a time');
ok((A::saveShift(null, ['name' => 'Bad', 'start_time' => '08:00', 'end_time' => '16:00', 'break_minutes' => '-5'], $admin)['reason_code'] ?? '') === 'VALIDATION', 'a negative break is refused');
$night = (int) A::saveShift(null, ['name' => 'Night', 'start_time' => '19:00', 'end_time' => '3:00', 'break_minutes' => 30, 'grace_in_minutes' => 15, 'grace_out_minutes' => 15], $admin)['shift_id'];
ok((int) Db::value('SELECT crosses_midnight FROM att_shifts WHERE id = ?', [$night]) === 1, 'a shift ending before it starts is a night shift — worked out, not ticked');
$sh = A::shifts();
ok(count($sh['shifts']) === 2 && array_values(array_filter($sh['shifts'], fn($s) => $s['is_default']))[0]['name'] === 'Day', 'the list marks which shift is the default');
ok((A::saveShift($day, ['name' => 'Day', 'start_time' => '09:00', 'end_time' => '17:00', 'active' => false], $admin)['reason_code'] ?? '') === 'DEFAULT_SHIFT',
   'THE DEFAULT SHIFT CANNOT BE SWITCHED OFF');

/* Changing the hours: tomorrow moves, today does not. */
Shifts::assign($e2, $day, $today);
Shifts::assign($e2, $day, $tomorrow);
$startOf = fn(string $d) => (string) Db::value('SELECT starts_at FROM att_shift_instances WHERE employee_id = ? AND work_date = ?', [$e2, $d]);
$todayBefore = $startOf($today);
$upd = A::saveShift($day, ['name' => 'Day', 'start_time' => '08:00', 'end_time' => '16:30', 'break_minutes' => 45, 'grace_in_minutes' => 30, 'grace_out_minutes' => 30], $admin);
ok($upd['ok'] && $upd['days_recalculated'] === 1, 'changing the hours moves the one day that has not started');
ok(str_starts_with($startOf($tomorrow), "$tomorrow 03:00:00"), '  tomorrow now starts at 08:00 factory time (03:00 UTC)');
ok($startOf($today) === $todayBefore, '  TODAY KEEPS THE HOURS IT HAD');
ok(str_starts_with((string) Db::value('SELECT starts_at FROM att_shift_instances WHERE employee_id = ? AND work_date = ?', [$e1, $yesterday]), "$yesterday 04:00:00"),
   '  and so does yesterday, which has an entry on it');
ok((A::saveShift($day, ['name' => 'Day', 'start_time' => '08:00', 'end_time' => '16:30', 'break_minutes' => 45, 'grace_in_minutes' => 30, 'grace_out_minutes' => 30], $admin)['days_recalculated'] ?? -1) === 0,
   'saving again with the same hours moves nothing');

/* ===================================================================== */
echo "7. Giving a shift to a department\n";
$stitchers = $n('SELECT COUNT(*) FROM att_employees WHERE department_id = ? AND active = 1', [$dS]);
$res = A::assignRange($night, $yesterday, $tomorrow, [], $dS, [], $admin);
ok($res['ok'] && $res['employees'] === $stitchers && $res['days'] === 3, "three days for the $stitchers people in Stitching");
ok($res['kept_with_entries'] === 1 && $res['shift_days_set'] === $stitchers * 3 - 1, 'ONE DAY ALREADY HAD ENTRIES AND KEPT ITS SHIFT (set ' . $res['shift_days_set'] . ', kept ' . $res['kept_with_entries'] . ')');
ok((int) Db::value('SELECT shift_id FROM att_shift_instances WHERE employee_id = ? AND work_date = ?', [$e1, $yesterday]) === $day, '  E001\'s yesterday is still on the Day shift it was worked under');
ok((int) Db::value('SELECT shift_id FROM att_shift_instances WHERE employee_id = ? AND work_date = ?', [$e1, $tomorrow]) === $night, '  E001\'s tomorrow is on Night');
$cnt = $n('SELECT COUNT(*) FROM att_shift_instances');
A::assignRange($night, $yesterday, $tomorrow, [], $dS, [], $admin);
ok($n('SELECT COUNT(*) FROM att_shift_instances') === $cnt, 'doing it twice does not make a second shift day for anybody');
ok($n("SELECT COUNT(*) FROM att_audit_logs WHERE action = 'shift.assign_range'") === 2, 'one audit line per instruction, not one per person per day');
$mon = (new DateTimeImmutable($today))->modify('next monday')->format('Y-m-d');
$wk = A::assignRange($day, $mon, (new DateTimeImmutable($mon))->modify('+13 days')->format('Y-m-d'), [1, 2, 3, 4, 5, 6], $dP, [], $admin);
ok($wk['ok'] && $wk['days'] === 12, 'Monday to Saturday over two weeks is twelve days — Sundays are left out');
ok((A::assignRange($day, $today, (new DateTimeImmutable($today))->modify('+120 days')->format('Y-m-d'), [], $dS, [], $admin)['reason_code'] ?? '') === 'VALIDATION', 'more than 92 days at once is refused');
ok((A::assignRange($day, (new DateTimeImmutable($today))->modify('-60 days')->format('Y-m-d'), $today, [], $dS, [], $admin)['reason_code'] ?? '') === 'VALIDATION', 'starting two months back is refused');
ok((A::assignRange($day, $tomorrow, $today, [], $dS, [], $admin)['reason_code'] ?? '') === 'VALIDATION', 'a last day before the first day is refused');
ok((A::assignRange($day, '2026-02-30', '2026-03-02', [], $dS, [], $admin)['reason_code'] ?? '') === 'VALIDATION', '30 February is not a day');
ok((A::assignRange($day, $today, $tomorrow, [], $dEmpty, [], $admin)['reason_code'] ?? '') === 'VALIDATION', 'a department with nobody in it is refused, not silently "done"');

/* ===================================================================== */
echo "8. Settings\n";
$set = A::settings();
ok(!in_array('duplicate_window_seconds', array_column($set['settings'], 'key'), true) && str_contains($set['fixed'][0]['value'], '60'),
   'THE 60-SECOND RULE IS SHOWN, NOT OFFERED FOR EDITING');
ok((A::saveSettings(['duplicate_window_seconds' => 5], $admin)['reason_code'] ?? '') === 'VALIDATION' && Policy::int('duplicate_window_seconds') === 60, '  and cannot be changed through the back door either');
ok((A::saveSettings(['hod_match_window_minutes' => 5], $admin)['reason_code'] ?? '') === 'VALIDATION', 'a matching window of 5 minutes is below the limit');
ok((A::saveSettings(['hod_match_window_minutes' => '12abc'], $admin)['reason_code'] ?? '') === 'VALIDATION', '"12abc" is not a number');
ok((A::saveSettings(['default_shift_id' => 99999], $admin)['reason_code'] ?? '') === 'VALIDATION', 'a default shift that does not exist is refused');
$sv = A::saveSettings(['hod_match_window_minutes' => 180, 'clock_skew_warn_ms' => 90, 'device_self_register' => false, 'default_shift_id' => $night], $admin);
Policy::flush();
ok($sv['ok'] && $sv['changed'] === 4 && Policy::int('hod_match_window_minutes') === 180 && Policy::bool('device_self_register') === false
   && (int) Policy::get('default_shift_id') === $night, 'four settings saved');
ok(Policy::int('clock_skew_warn_ms') === 90000, 'the clock limit is typed in seconds and stored in the unit the system uses');
ok(array_values(array_filter(A::settings()['settings'], fn($s) => $s['key'] === 'clock_skew_warn_ms'))[0]['value'] === 90, '  and read back in seconds');
ok($n("SELECT COUNT(*) FROM att_audit_logs WHERE action = 'policy.update'") >= 4, 'each change is in the audit log');
ok((A::saveSettings(['hod_match_window_minutes' => 180], $admin)['changed'] ?? -1) === 0, 'saving the same values again changes nothing');
A::saveSettings(['default_shift_id' => $day, 'device_self_register' => true], $admin); Policy::flush();

/* ===================================================================== */
echo "9. People: jobs (service level)\n";
ok((A::grantJob($hodU, 'hod', 0, $admin)['reason_code'] ?? '') === 'VALIDATION', 'a HOD without a department is refused');
ok((A::grantJob($hodU, 'admin', 0, $admin)['reason_code'] ?? '') === 'VALIDATION', 'the bypass "admin" role cannot be handed out from this screen');
ok((A::grantJob(99999, 'hr', 0, $admin)['reason_code'] ?? '') === 'NOT_FOUND', 'a login that does not exist is refused');
ok((A::grantJob($hodU, 'hod', $dEmpty, $admin)['reason_code'] ?? '') === 'VALIDATION', 'a HOD of a switched-off department is refused');
$g = A::grantJob($hodU, 'hod', $dS, $admin);
ok($g['ok'] && $g['permission_synced'] === false && str_contains((string) $g['note'], 'User Access'),
   'when the permission cannot be ticked (no ERP loaded here), THE SCREEN IS TOLD SO rather than left to assume');
$row = array_values(array_filter(A::users()['users'], fn($u) => $u['id'] === $hodU))[0];
ok($row['jobs'][0]['label'] === 'HOD' && $row['jobs'][0]['department_name'] === 'Stitching' && $row['jobs'][0]['working'] === false,
   'the list shows the job as held but NOT WORKING until its permission exists');
$ov = A::overview();
ok($ov['gatekeepers_ready'] === 0 && in_array('Stitching', $ov['departments_without_hod'], true),
   'the checklist does not count a job that is not working');
A::revokeJob($hodU, 'hod', $dS, $admin);
Roles::revoke($gk, Roles::GATEKEEPER);

/* ===================================================================== */
/* The same things over real HTTP, with the ERP's own session and
   permission code in the path.                                           */
$port = 8731;
$srv = proc_open(sprintf('exec php -S 127.0.0.1:%d -t %s', $port, escapeshellarg(rtrim($B, '/'))),
    [1 => ['file', '/tmp/att_admin_srv.log', 'a'], 2 => ['file', '/tmp/att_admin_srv.log', 'a']], $pipes);
register_shutdown_function(static function () use ($srv) { if (is_resource($srv)) { proc_terminate($srv, 9); proc_close($srv); } });
for ($i = 0; $i < 60; $i++) { $c = @fsockopen('127.0.0.1', $port, $e1, $e2, 0.2); if ($c) { fclose($c); break; } usleep(200000); }

$jar = tempnam(sys_get_temp_dir(), 'attadm');
function call(string $method, string $path, array $data = [], ?string $csrf = null): array
{
    global $port, $jar;
    $url = "http://127.0.0.1:$port/api/v1/index.php?route=" . rawurlencode($path);
    $h = ['Accept: application/json'];
    if ($csrf !== null) { $h[] = "X-CSRF-Token: $csrf"; }
    $ch = curl_init();
    $opts = [CURLOPT_RETURNTRANSFER => true, CURLOPT_COOKIEJAR => $jar, CURLOPT_COOKIEFILE => $jar, CURLOPT_TIMEOUT => 20, CURLOPT_CUSTOMREQUEST => $method];
    if ($method !== 'GET') { $opts[CURLOPT_POSTFIELDS] = json_encode($data); $h[] = 'Content-Type: application/json'; }
    elseif ($data !== []) { $url .= '&' . http_build_query($data); }
    $opts[CURLOPT_URL] = $url; $opts[CURLOPT_HTTPHEADER] = $h;
    curl_setopt_array($ch, $opts);
    $raw = (string) curl_exec($ch);
    $status = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    $body = json_decode($raw, true);
    return ['status' => $status, 'body' => is_array($body) ? $body : [], 'raw' => $raw];
}
function signIn(string $email): string
{
    global $jar;
    file_put_contents($jar, '');
    return (string) (call('POST', '/auth/login', ['email' => $email, 'password' => 'CorrectHorse9'])['body']['csrf_token'] ?? '');
}
$perm = fn(int $uid, string $mod) => Db::value('SELECT actions FROM zu_perm WHERE user_id = ? AND module = ?', [$uid, $mod]);

echo "9b. Installing attendance took nothing away from User Access\n";
/* The first attendance commit added its ten rows to zu_modules() and, in the
   same edit, lost the last existing one — 'Users & Setup'. A permission whose
   row is missing from that list cannot be ticked, and is silently deleted the
   next time that user's grid is saved. Every row the ERP had before must
   still be there, with the same letters. */
$src = (string) file_get_contents($B . 'includes/access.php');
$mods = [];
if (preg_match_all("/\['g' => '([^']+)',\s*'k' => '([^']+)',\s*'n' => '([^']+)',\s*'a' => '([^']+)'\]/", $src, $mm, PREG_SET_ORDER)) {
    foreach ($mm as $x) { $mods[$x[2]] = [$x[1], $x[3], $x[4]]; }
}
$erp = ['ship','proforma','packing','shiplog','shippay','shipcost','shipdoc','imp','imppay','impcost','impdoc','impefs','costing','fcost',
        'pmaster','prod','prodrate','prodrep','gate','store','consume','opening','contract','jobwork','invmast','stockrep','admin'];
$missing = array_values(array_diff($erp, array_keys($mods)));
ok($missing === [], 'all 27 permission rows the ERP had before are still listed' . ($missing ? ' — missing: ' . implode(', ', $missing) : ''));
ok(($mods['admin'] ?? null) === ['Administration', 'Users & Setup', 'vcud'], 'THE "Users & Setup" ROW IS THERE, with view / create / update / delete');
ok(count(array_filter(array_keys($mods), fn($k) => str_starts_with($k, 'att'))) === 10 && count($mods) === 37, 'plus the ten attendance rows: 37 in all');

echo "10. Over HTTP: who may do what\n";
ok(call('GET', '/admin/overview')['status'] === 401, 'signed out: 401');
$pdo->prepare("INSERT INTO zu_perm (user_id, module, actions) VALUES (?,?,?)")->execute([$clerk, 'attadm', 'v']);
$cs = signIn('clerk@zas.test');
ok(call('GET', '/admin/employees')['status'] === 200, 'a clerk with "view" can read the roll');
ok(call('POST', '/admin/employees', ['code' => 'E900', 'full_name' => 'Sneak', 'department_id' => $dS])['status'] === 419, 'a write without the CSRF token is refused');
ok(call('POST', '/admin/employees', ['code' => 'E900', 'full_name' => 'Sneak', 'department_id' => $dS], $cs)['status'] === 403, 'with "view" only, adding is 403');
ok(call('POST', '/admin/departments', ['name' => 'Sneak Dept'], $cs)['status'] === 403 && call('POST', '/admin/shifts', ['name' => 'S', 'start_time' => '01:00', 'end_time' => '02:00'], $cs)['status'] === 403
   && call('POST', '/admin/employees/import', ['rows' => [['code' => 'Z1', 'full_name' => 'Z', 'department' => 'Stitching']], 'commit' => true], $cs)['status'] === 403
   && call('POST', '/admin/shifts/assign', ['shift_id' => $day, 'department_id' => $dS, 'from' => $today, 'to' => $today], $cs)['status'] === 403,
   '  and so are departments, shifts, the import and shift days');
ok($n("SELECT COUNT(*) FROM att_employees WHERE code IN ('E900','Z1')") === 0, '  nothing was written');

$pdo->prepare("UPDATE zu_perm SET actions = 'vcu' WHERE user_id = ? AND module = 'attadm'")->execute([$clerk]);
$cs = signIn('clerk@zas.test');
$add = call('POST', '/admin/employees', ['code' => 'E900', 'full_name' => 'Clerk Added', 'department_id' => $dS], $cs);
ok($add['status'] === 200 && ($add['body']['employee_id'] ?? 0) > 0, 'with create + update, the clerk adds an employee');
$e900 = (int) $add['body']['employee_id'];
ok((int) Db::value("SELECT actor_id FROM att_audit_logs WHERE action = 'employee.create' AND subject_id = ?", [$e900]) === $clerk, '  recorded under the clerk\'s own name');
ok(call('POST', '/admin/employees/active', ['id' => $e900, 'active' => false, 'reason' => 'test'], $cs)['status'] === 403, 'taking somebody off the roll needs the "delete" letter the clerk does not have');
$dup = call('POST', '/admin/employees', ['code' => 'E900', 'full_name' => 'Again', 'department_id' => $dS], $cs);
ok($dup['status'] === 422 && ($dup['body']['reason_code'] ?? '') === 'CODE_TAKEN', 'a refused save is 422 with a reason the screen can show');
ok(call('POST', '/admin/employees', ['id' => 99999, 'code' => 'E901', 'full_name' => 'Ghost', 'department_id' => $dS], $cs)['status'] === 404, 'editing somebody who does not exist is 404');

ok(call('GET', '/admin/users')['status'] === 403, 'THE CLERK CANNOT SEE WHO HOLDS WHICH JOB');
ok(call('POST', '/admin/jobs/grant', ['user_id' => $clerk, 'role' => 'hr'], $cs)['status'] === 403, 'THE CLERK CANNOT MAKE THEMSELVES HR');
ok(call('POST', '/admin/settings', ['values' => ['hod_match_window_minutes' => 30]], $cs)['status'] === 403, 'THE CLERK CANNOT CHANGE THE SETTINGS');
ok(!Roles::has($clerk, Roles::HR) && Policy::int('hod_match_window_minutes') === 180, '  and neither happened');
ok(call('GET', '/admin/settings')['status'] === 200, 'the clerk may read the settings');

echo "11. Over HTTP: a job and its permission move together\n";
$as = signIn('admin@zas.test');
$pdo->prepare("INSERT INTO zu_perm (user_id, module, actions) VALUES (?,?,?)")->execute([$gk, 'gate', 'vc']);
$pdo->prepare("INSERT INTO zu_perm (user_id, module, actions) VALUES (?,?,?)")->execute([$gk, 'admin', 'v']);
$g = call('POST', '/admin/jobs/grant', ['user_id' => $gk, 'role' => 'gatekeeper'], $as);
ok($g['status'] === 200 && ($g['body']['permission_synced'] ?? false) === true, 'the admin makes Gul a gatekeeper' . ($g['status'] === 200 ? '' : ' — ' . $g['raw']));
ok(Roles::has($gk, Roles::GATEKEEPER), '  the job is recorded');
ok(str_contains((string) $perm($gk, 'attgate'), 'v') && str_contains((string) $perm($gk, 'attgate'), 'c'), '  AND "Attendance — Gate IN/OUT" IS TICKED FOR THEM IN USER ACCESS');
ok($perm($gk, 'gate') === 'vc', '  the inventory permission they already had is untouched');
ok($perm($gk, 'admin') === 'v', '  AND SO IS THEIR "Users & Setup" PERMISSION — the row the attendance module once dropped from the list');
$us = call('GET', '/admin/users')['body'];
$gul = array_values(array_filter($us['users'] ?? [], fn($u) => $u['id'] === $gk))[0] ?? ['jobs' => []];
ok(($gul['jobs'][0]['working'] ?? false) === true, 'the People list now shows the job as working');
ok((call('GET', '/admin/overview')['body']['gatekeepers_ready'] ?? 0) === 1, 'and the checklist counts one gatekeeper');

call('POST', '/admin/jobs/grant', ['user_id' => $hodU, 'role' => 'hod', 'department_id' => $dS], $as);
call('POST', '/admin/jobs/grant', ['user_id' => $hodU, 'role' => 'hod', 'department_id' => $dP], $as);
ok(count(Roles::forUser($hodU)) === 2 && str_contains((string) $perm($hodU, 'atthod'), 'c'), 'Hina is HOD of two departments');
call('POST', '/admin/jobs/revoke', ['user_id' => $hodU, 'role' => 'hod', 'department_id' => $dP], $as);
ok(count(Roles::forUser($hodU)) === 1 && str_contains((string) $perm($hodU, 'atthod'), 'c'), 'losing one department leaves her a HOD, permission and all');
call('POST', '/admin/jobs/revoke', ['user_id' => $hodU, 'role' => 'hod', 'department_id' => $dS], $as);
ok(Roles::forUser($hodU) === [] && $perm($hodU, 'atthod') === null, 'LOSING THE LAST ONE UN-TICKS THE PERMISSION TOO');

call('POST', '/admin/jobs/grant', ['user_id' => $hodU, 'role' => 'hr'], $as);
ok(str_contains((string) $perm($hodU, 'atthr'), 'u'), 'made HR: the reconciliation permission is ticked');
$rv = call('POST', '/admin/jobs/revoke', ['user_id' => $hodU, 'role' => 'hr'], $as);
ok(($rv['body']['permission_synced'] ?? false) === true && $perm($hodU, 'atthr') === null, 'HR TAKEN AWAY: THE ACCESS IS REALLY GONE, not just the label');
$ad = call('POST', '/admin/jobs/grant', ['user_id' => $admin, 'role' => 'hr'], $as);
ok($ad['status'] === 200 && ($ad['body']['permission_synced'] ?? false) === true && $n('SELECT COUNT(*) FROM zu_perm WHERE user_id = ?', [$admin]) === 0,
   'giving a job to an administrator records it without writing permissions they do not need');
call('POST', '/admin/jobs/grant', ['user_id' => $hodU, 'role' => 'hod', 'department_id' => $dS], $as);

/* ===================================================================== */
echo "12. The screen itself, in a browser\n";
$work = __DIR__ . '/.zattadmin';
@mkdir($work, 0777, true);
$shot = getenv('ATT_SHOT_DIR') ?: '';
file_put_contents($work . '/run.js', <<<'JS'
const { chromium } = require('playwright');
const BASE = process.argv[2], SHOT = process.argv[3] || '';
const out = [];
const check = (name, pass, extra) => out.push({ name, pass: !!pass, extra: extra === undefined ? '' : String(extra) });
async function login(page, email) {
  await page.goto(BASE + '/att/manifest.webmanifest');
  return page.evaluate(async (email) => {
    const r = await fetch('../api/v1/index.php?route=%2Fauth%2Flogin', { method: 'POST', credentials: 'same-origin',
      headers: { 'Content-Type': 'application/json' }, body: JSON.stringify({ email, password: 'CorrectHorse9' }) });
    return r.status;
  }, email);
}
const tab = async (page, name) => { await page.click(`#atts-tabs button:has-text("${name}")`); await page.waitForFunction(() => !/^Loading…$/.test(document.getElementById('atts-body').textContent.trim())); };
const closed = (page) => page.waitForFunction(() => !document.querySelector('#attsdlg[open]'), null, { timeout: 15000 });
(async () => {
  const browser = await chromium.launch();
  try {
    const ctx = await browser.newContext({ viewport: { width: 1280, height: 900 } });
    const page = await ctx.newPage();
    const errors = [];
    page.on('pageerror', e => errors.push(e.message));
    page.on('dialog', d => d.accept());
    check('the admin signs in', (await login(page, 'admin@zas.test')) === 200);
    await page.goto(BASE + '/att_setup.php');
    await page.waitForSelector('#atts-body .check li', { timeout: 20000 });
    const html = await page.textContent('body');
    check('the page renders inside the ERP layout with no PHP warning', !/Warning:|Notice:|Fatal error|Deprecated:/.test(html), html.slice(0, 300));
    check('the menu shows Attendance › Setup', /Setup/.test(await page.textContent('.nav')));
    check('it opens on a six-line checklist', (await page.locator('#atts-body .check li').count()) === 6);
    check('the checklist names the department that has no HOD', /No HOD yet for:[^.]*Finishing/.test(await page.textContent('#atts-body')), await page.textContent('#atts-body'));

    // Departments
    await tab(page, 'Departments');
    await page.fill('input[aria-label="New department name"]', 'Quality');
    await page.click('button:has-text("Add department")');
    await page.waitForSelector('#atts-body td strong:text-is("Quality")');
    check('a department added from the screen appears in the list', true);
    await page.fill('input[aria-label="New department name"]', 'quality');
    await page.click('button:has-text("Add department")');
    await page.waitForSelector('#atts-body .err:has-text("already")');
    check('adding it again shows the refusal on the screen', true);

    // Employees: one by one
    await tab(page, 'Employees');
    await page.click('button:has-text("Add employee")');
    await page.waitForSelector('#attsdlg[open]');
    await page.click('#attsdlg-ok');
    await page.waitForSelector('#attsdlg-err:not(:empty)');
    check('saving an empty form is refused in the dialog, which stays open', await page.isVisible('#attsdlg[open]'));
    const inputs = page.locator('#attsdlg-fields input[type=text]');
    await inputs.nth(0).fill('Q001');
    await inputs.nth(1).fill('Zara <b>Qureshi</b>');
    await page.selectOption('#attsdlg-fields select', { label: 'Quality' });
    await page.click('#attsdlg-ok');
    await closed(page);
    await page.waitForSelector('#atts-body td strong:text-is("Q001")');
    check('an employee added from the screen appears in the list', true);
    check('a name with angle brackets is shown as text, not as markup', (await page.locator('#atts-body b').count()) === 0 && /Zara <b>Qureshi<\/b>/.test(await page.textContent('#atts-body')));

    // Employees: paste from Excel
    await page.click('button:has-text("Add many")');
    await page.waitForSelector('#attsdlg[open]');
    await page.fill('#attsdlg-fields textarea', 'Code\tName\tDepartment\nQ002\tOmar Sheikh\tQuality\nQ003\tHuma Raza\tLaundry\nQ001\tZara Again\tQuality\n');
    await page.click('#attsdlg-ok');
    await page.waitForSelector('#attsdlg-fields table');
    const preview = await page.textContent('#attsdlg-fields');
    check('the check skips the header row and says what will happen', /Will add 2\. Already on the roll: 1\. Cannot be added: 0\./.test(preview), preview);
    check('  and that a new department will be created', /New department "Laundry" will be created/.test(preview));
    check('  the button now offers to add exactly two', /Add 2 employees/.test(await page.textContent('#attsdlg-ok')));
    await page.click('#attsdlg-ok');
    await page.waitForSelector('#attsdlg-fields .okmsg');
    check('after adding, it says how many were added', /2 employees added/.test(await page.textContent('#attsdlg-fields .okmsg')));
    await page.click('#attsdlg-cancel');
    await closed(page);
    await page.fill('input[aria-label="Search employees"]', 'Q00');
    await page.waitForFunction(() => document.querySelectorAll('#atts-body tbody tr').length === 3);
    check('searching "Q00" finds the three new people', true);

    // Make inactive needs a reason
    await page.locator('#atts-body tr', { hasText: 'Q002' }).locator('button:has-text("Make inactive")').click();
    await page.waitForSelector('#attsdlg[open]');
    await page.click('#attsdlg-ok');
    await page.waitForSelector('#attsdlg-err:not(:empty)');
    check('making somebody inactive without a reason is refused', /why/i.test(await page.textContent('#attsdlg-err')));
    await page.fill('#attsdlg-fields textarea', 'entered by mistake');
    await page.click('#attsdlg-ok');
    await closed(page);
    await page.waitForFunction(() => document.querySelectorAll('#atts-body tbody tr').length === 2);
    check('with a reason they leave the active list', true);

    // Shifts
    await tab(page, 'Shifts');
    await page.click('button:has-text("Add shift")');
    await page.waitForSelector('#attsdlg[open]');
    await page.locator('#attsdlg-fields input[type=text]').fill('Evening');
    await page.locator('#attsdlg-fields input[type=time]').nth(0).fill('17:00');
    await page.locator('#attsdlg-fields input[type=time]').nth(1).fill('01:00');
    await page.click('#attsdlg-ok');
    await closed(page);
    await page.waitForSelector('#atts-body tr:has-text("Evening")');
    check('a shift ending after midnight is listed as ending next day', /17:00 – 01:00 \(ends next day\)/.test(await page.textContent('#atts-body tr:has-text("Evening")')));
    await page.click('button:has-text("Give a shift to a department")');
    await page.waitForSelector('#attsdlg[open]');
    await page.locator('#attsdlg-fields select').nth(0).selectOption({ label: 'Evening' });
    await page.locator('#attsdlg-fields select').nth(1).selectOption({ label: 'Quality' });
    await page.locator('#attsdlg-fields input[type=checkbox]').nth(6).uncheck();   // Sunday
    await page.click('#attsdlg-ok');
    await page.waitForSelector('#attsdlg-fields .okmsg:not(:empty)');
    check('giving a shift to a department reports what was done', /Done: 1 employee × 2[56] days/.test(await page.textContent('#attsdlg-fields .okmsg')), await page.textContent('#attsdlg-fields .okmsg'));
    await page.click('#attsdlg-cancel');
    await closed(page);

    // People
    await tab(page, 'People');
    const clerkRow = page.locator('#atts-body tr', { hasText: 'Clara Clerk' });
    await clerkRow.locator('select').nth(0).selectOption({ label: 'HOD' });
    check('choosing HOD asks which department', await clerkRow.locator('select').nth(1).isVisible());
    await clerkRow.locator('button:has-text("Give")').click();
    await page.waitForSelector('#atts-body .err:has-text("department")');
    check('HOD with no department chosen is refused on the screen', true);
    const row2 = page.locator('#atts-body tr', { hasText: 'Clara Clerk' });
    await row2.locator('select').nth(0).selectOption({ label: 'HOD' });
    await row2.locator('select').nth(1).selectOption({ label: 'Quality' });
    await row2.locator('button:has-text("Give")').click();
    await page.waitForSelector('#atts-body tr:has-text("Clara Clerk") .pill.good:has-text("HOD — Quality")');
    check('given a department, the job shows as working straight away', true);
    await page.locator('#atts-body tr', { hasText: 'Clara Clerk' }).locator('.pill button').click();
    await page.waitForFunction(() => !/HOD — Quality/.test(document.getElementById('atts-body').textContent));
    check('the × takes the job away again', true);

    // Settings
    await tab(page, 'Settings');
    const body = await page.textContent('#atts-body');
    check('settings show the 60-second rule as fixed', /refused within\s*60 seconds/.test(body) && /Not changed from this screen/.test(body));
    await page.locator('#atts-body .setting', { hasText: 'HOD matching window' }).locator('input').fill('5');
    await page.click('button:has-text("Save settings")');
    await page.waitForSelector('#atts-body .err:not(:empty)');
    check('an out-of-range setting is refused with the allowed range', /15 to 1440/.test(await page.textContent('#atts-body .bar .err')));
    await page.locator('#atts-body .setting', { hasText: 'HOD matching window' }).locator('input').fill('200');
    await page.getByLabel('Default shift', { exact: true }).selectOption({ label: 'Evening' });
    await page.click('button:has-text("Save settings")');
    await page.waitForSelector('#atts-body .okmsg:has-text("Saved")');
    check('valid settings save', true);

    await tab(page, 'Start here');
    if (SHOT) await page.screenshot({ path: SHOT + '/att-setup-start.png', fullPage: true });
    await tab(page, 'Employees');
    if (SHOT) await page.screenshot({ path: SHOT + '/att-setup-employees.png', fullPage: true });

    // A phone-width look: nothing may push the page sideways. The ERP's own
    // stylesheet gives every table min-width:980px, which did exactly that.
    const m = await browser.newContext({ viewport: { width: 390, height: 800 }, storageState: await ctx.storageState() });
    const mp = await m.newPage();
    const sideways = [];
    for (const t of ['start', 'emp', 'dept', 'shift', 'people', 'set']) {
      await mp.goto(BASE + '/att_setup.php#' + t);
      await mp.reload();
      await mp.waitForFunction(() => !/^Loading…$/.test(document.getElementById('atts-body').textContent.trim()));
      await mp.waitForTimeout(300);
      const over = await mp.evaluate(() => document.documentElement.scrollWidth - window.innerWidth);
      if (over > 1) sideways.push(t + ' +' + over + 'px');
    }
    check('AT PHONE WIDTH NO TAB PUSHES THE PAGE SIDEWAYS', sideways.length === 0, sideways.join(', '));
    await mp.goto(BASE + '/att_hr_reconciliation.php');
    await mp.waitForSelector('#attq h2');
    await mp.waitForTimeout(500);
    const hrOver = await mp.evaluate(() => document.documentElement.scrollWidth - window.innerWidth);
    check('  nor does the HR Reconciliation screen', hrOver <= 1, hrOver);
    const wideOver = await page.evaluate(() => document.documentElement.scrollWidth - window.innerWidth);
    check('  and at desktop width the page is exactly as wide as the window', wideOver <= 1, wideOver);
    await m.close();

    // The clerk: People and Settings are not theirs.
    const c2 = await browser.newContext({ viewport: { width: 1280, height: 900 } });
    const cp = await c2.newPage();
    await login(cp, 'clerk@zas.test');
    await cp.goto(BASE + '/att_setup.php#people');
    await cp.waitForFunction(() => /administrator/i.test(document.getElementById('atts-body').textContent));
    check('a clerk opening People is told it is for an administrator', true);
    await cp.goto(BASE + '/att_setup.php#set');
    await cp.reload();
    await cp.waitForSelector('#atts-body .setting');
    check('a clerk sees the settings read-only, with no Save button', (await cp.locator('button:has-text("Save settings")').count()) === 0 && await cp.locator('#atts-body .setting input').first().isDisabled());
    await c2.close();

    check('no script error on any tab', errors.length === 0, errors.join(' | '));
  } catch (e) { check('the browser run completed', false, e && e.message); }
  finally { await browser.close(); }
  console.log('RESULT' + JSON.stringify(out));
})();
JS);
$raw = (string) shell_exec('cd ' . escapeshellarg($work) . ' && timeout 300 node run.js ' . escapeshellarg("http://127.0.0.1:$port") . ' ' . escapeshellarg($shot) . ' 2>&1');
$results = [];
if (preg_match('/RESULT(\[.*\])\s*$/s', $raw, $m)) { $results = json_decode($m[1], true) ?: []; }
ok(count($results) >= 25, 'the browser run reported its checks (' . count($results) . ')' . (count($results) ? '' : "\n$raw"));
foreach ($results as $r) { ok($r['pass'], $r['name'] . ($r['pass'] ? '' : ' — ' . $r['extra'])); }

echo "13. What the browser run left in the database\n";
ok(Db::value("SELECT full_name FROM att_employees WHERE code = 'Q001'") === 'Zara <b>Qureshi</b>', 'the name is stored exactly as typed');
ok($n("SELECT COUNT(*) FROM att_employees WHERE code IN ('Q002','Q003')") === 2 && $n("SELECT COUNT(*) FROM att_departments WHERE name = 'Laundry'") === 1, 'the pasted list added two people and one department');
ok((int) Db::value("SELECT active FROM att_employees WHERE code = 'Q002'") === 0, 'Q002 is inactive');
$ev = (int) Db::value("SELECT id FROM att_shifts WHERE name = 'Evening'");
ok($n('SELECT COUNT(*) FROM att_shift_instances WHERE shift_id = ? AND DAYOFWEEK(work_date) = 1', [$ev]) === 0 && $n('SELECT COUNT(*) FROM att_shift_instances WHERE shift_id = ?', [$ev]) >= 25,
   'Evening was given for the month with no Sunday in it');
Policy::flush();
ok(Policy::int('hod_match_window_minutes') === 200 && (int) Policy::get('default_shift_id') === $ev, 'the settings saved from the screen are the ones in force');
ok(!Roles::has($clerk, Roles::HOD) && !str_contains((string) $perm($clerk, 'atthod'), 'c'), 'the clerk ended up with neither the HOD job nor its permission');
ok($perm($clerk, 'attadm') === 'vcu', '  and still has exactly the setup permission they started with');

echo "\n$P passed, $F failed\n";
exit($F === 0 ? 0 : 1);
