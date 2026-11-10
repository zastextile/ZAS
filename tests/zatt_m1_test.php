<?php
declare(strict_types=1);

/* ATTENDANCE M1 — migrations, masters, roles, shifts.
 *
 * RUN AGAINST A REAL MySQL 8, NOT A STAND-IN. Three of the rules this module
 * has to keep are kept by the database engine and not by PHP:
 *
 *   - one open entry cycle per employee  (stored generated column + UNIQUE)
 *   - one shift instance per employee per day   (UNIQUE)
 *   - "HOD of no department, twice" is impossible (NULL folded by a generated
 *     column, because MySQL lets NULLs repeat inside a unique key)
 *
 * Testing those against SQLite would test a different database and prove
 * nothing about the one in production. The harness therefore needs a live
 * server; see tests/run_att.sh, which starts one and sets ATT_TEST_DSN.
 *
 * It also checks the thing that worried me most about adding a module to a
 * 242-file production app: that migrating up, down and up again leaves the
 * EXISTING tables byte-for-byte as they were.
 */

$B = __DIR__ . '/app_src/public_html/';
$P = 0; $F = 0;
function ok($c, $m) { global $P, $F; if ($c) { $P++; } else { $F++; echo "  FAIL: $m\n"; } }

$dsn  = getenv('ATT_TEST_DSN') ?: 'mysql:host=127.0.0.1;port=3306;dbname=zas_att_test;charset=utf8mb4';
$user = getenv('ATT_TEST_USER') ?: 'zas';
$pass = getenv('ATT_TEST_PASS');
if ($pass === false) { $pass = 'zaspw'; }

require_once $B . 'includes/att/Db.php';
require_once $B . 'includes/att/Time.php';
require_once $B . 'includes/att/Migrations.php';
require_once $B . 'includes/att/Policy.php';
require_once $B . 'includes/att/Audit.php';
require_once $B . 'includes/att/Roles.php';
require_once $B . 'includes/att/Employees.php';
require_once $B . 'includes/att/Shifts.php';

use Att\Db;
use Att\Employees;
use Att\Migrations;
use Att\Policy;
use Att\Audit;
use Att\Roles;
use Att\Shifts;
use Att\Time;

try {
    $pdo = new PDO($dsn, $user, $pass, [
        PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        PDO::ATTR_EMULATE_PREPARES   => false,
    ]);
} catch (Throwable $e) {
    echo "SKIP: no MySQL available ({$e->getMessage()})\n";
    echo "Start one with tests/run_att.sh\n";
    exit(0);
}
Db::bind($pdo);

/* A clean schema, plus stand-ins for the two production tables migration 0005
   has to cope with. They are shaped like the real ones so 0005 runs its real
   path here rather than its skip path. */
$pdo->exec('SET FOREIGN_KEY_CHECKS=0');
foreach ($pdo->query("SELECT table_name AS tn FROM information_schema.tables WHERE table_schema = DATABASE()")->fetchAll() as $t) {
    $pdo->exec('DROP TABLE IF EXISTS `' . $t['tn'] . '`');
}
$pdo->exec('SET FOREIGN_KEY_CHECKS=1');
$pdo->exec("CREATE TABLE users (id INT AUTO_INCREMENT PRIMARY KEY, name VARCHAR(150) NOT NULL,
            email VARCHAR(190) NOT NULL UNIQUE, password_hash VARCHAR(255) NOT NULL,
            role VARCHAR(40) NOT NULL DEFAULT 'colleague', department VARCHAR(60) NULL,
            is_active TINYINT(1) NOT NULL DEFAULT 1) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
$pdo->exec("CREATE TABLE zp_workers (id INT AUTO_INCREMENT PRIMARY KEY, worker_code VARCHAR(20) NOT NULL UNIQUE,
            worker_name VARCHAR(120) NOT NULL, department VARCHAR(60) NULL,
            is_active TINYINT(1) NOT NULL DEFAULT 1) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
$pdo->exec("INSERT INTO users (name,email,password_hash) VALUES ('Test HR','hr@example.test','x')");
$pdo->exec("INSERT INTO zp_workers (worker_code,worker_name,department) VALUES ('W001','Machine Person','Stitching')");

$usersBefore   = $pdo->query('SHOW CREATE TABLE users')->fetch()['Create Table'];
$workersBefore = $pdo->query('SHOW CREATE TABLE zp_workers')->fetch()['Create Table'];

$m = new Migrations($B . 'sql/att');

echo "1. Migrations apply in order\n";
$expected = count($m->available());
$log = $m->up();
/* Counted from what is on disk, not hard-coded. An earlier version asserted
   "six", which was true until M2 added three more and then failed for a
   reason that had nothing to do with what it was testing. */
ok(count($log) === $expected, "every available migration applied ($expected)");
ok(Db::tableExists('att_employees'), 'att_employees exists');
ok(Db::tableExists('att_policies'), 'att_policies exists');
ok(Db::tableExists('att_audit_logs'), 'att_audit_logs exists');
ok(!Db::tableExists('employees'), 'did NOT create a bare `employees` table');
ok(!Db::tableExists('departments'), 'did NOT create a bare `departments` table');

echo "2. The two collisions in schema.sql are left alone\n";
/* This is the whole reason for the att_ prefix: running the handoff schema as
   written would have replaced the live users table and the shipment audit
   trail. */
$usersCols = array_column($pdo->query('SHOW COLUMNS FROM users')->fetchAll(), 'Field');
ok(in_array('email', $usersCols, true), 'users still has the ERP email login column');
ok(!in_array('username', $usersCols, true), 'users was NOT replaced by the spec shape');
ok(in_array('att_enabled', $usersCols, true), 'users gained only the additive att_enabled column');
ok(Db::columnExists('zp_workers', 'att_employee_id'), 'zp_workers gained the additive bridge column');

echo "3. Re-running is a no-op, and editing an applied migration is refused\n";
$again = $m->up();
ok($again === ['nothing to apply — database is up to date'], 'second up() changes nothing');
/* Numbered 9999 so it can never collide with a real migration. It was 0007
   once; M2 then added a real 0007 and the two silently fought over the same
   slot, which is why the runner now refuses duplicate version numbers. */
$tmp = $B . 'sql/att/9999_scratch.sql';
file_put_contents($tmp, "CREATE TABLE att_scratch (id INT PRIMARY KEY);\n");
try {
    $m->up();
    ok(Db::tableExists('att_scratch'), 'a new migration is picked up');
    file_put_contents($tmp, "CREATE TABLE att_scratch (id INT PRIMARY KEY, extra INT);\n");
    $refused = false;
    try { $m->up(); } catch (Throwable $e) { $refused = str_contains($e->getMessage(), 'edited after it was applied'); }
    ok($refused, 'EDITING AN APPLIED MIGRATION IS REFUSED, not silently ignored');

    // Two files, one number: the runner must say so rather than guess.
    $dupe = $B . 'sql/att/9999_also_scratch.sql';
    file_put_contents($dupe, "SELECT 1;\n");
    $clash = false;
    try { $m->available(); } catch (Throwable $e) { $clash = str_contains($e->getMessage(), 'share version'); }
    ok($clash, 'TWO MIGRATIONS WITH THE SAME NUMBER ARE REFUSED, not silently merged');
    @unlink($dupe);
} finally {
    /* Always clean up, even if an assertion above threw: a stray scratch
       migration left in sql/att would break every later run of this suite. */
    @unlink($tmp);
    $pdo->exec('DROP TABLE IF EXISTS att_scratch');
    $pdo->exec("DELETE FROM att_migrations WHERE version = 9999");
}

echo "4. Rollback leaves the existing tables exactly as they were\n";
$m->down(0);
ok(!Db::tableExists('att_employees'), 'att_ tables removed');
ok(!Db::columnExists('users', 'att_enabled'), 'the added users column is removed');
ok(!Db::columnExists('zp_workers', 'att_employee_id'), 'the added zp_workers column is removed');
$usersAfter   = $pdo->query('SHOW CREATE TABLE users')->fetch()['Create Table'];
$workersAfter = $pdo->query('SHOW CREATE TABLE zp_workers')->fetch()['Create Table'];
ok($usersBefore === $usersAfter, 'users DDL is byte-for-byte what it was before the module');
ok($workersBefore === $workersAfter, 'zp_workers DDL is byte-for-byte what it was before the module');
ok((int) $pdo->query('SELECT COUNT(*) c FROM users')->fetch()['c'] === 1, 'no ERP rows were harmed');
$m->up();
ok(Db::tableExists('att_employees'), 'up → down → up is repeatable');

echo "5. Policy: defaults exist, and a missing row still answers\n";
Policy::flush();
ok(Policy::int('duplicate_window_seconds') === 60, '60-second duplicate window is the default (blueprint §2)');
ok(Policy::bool('auto_shift_on_gate_in') === true, 'D4 auto-shift is on by default');
ok(Policy::bool('gatekeeper_may_verify_ot') === false, 'gatekeeper OT verification is OFF by default (§4)');
$pdo->exec("DELETE FROM att_policies WHERE policy_key='duplicate_window_seconds'");
Policy::flush();
ok(Policy::int('duplicate_window_seconds') === 60, 'a DELETED policy row falls back to 60, never to 0');
Policy::set('duplicate_window_seconds', '60', null, 'int');

echo "6. Departments and employees\n";
$dStitch = Employees::createDepartment('Stitching');
$dPack   = Employees::createDepartment('Packing');
$e1 = Employees::create(['code' => 'E001', 'full_name' => 'Imran Ali',  'department_id' => $dStitch, 'worker_code' => 'W001']);
$e2 = Employees::create(['code' => 'E002', 'full_name' => 'Sara Khan',  'department_id' => $dPack]);
ok($e1 > 0 && $e2 > 0, 'employees created');
ok(Employees::findByCode('E001')['full_name'] === 'Imran Ali', 'lookup by code');

$dup = false;
try { Employees::create(['code' => 'E001', 'full_name' => 'Someone Else', 'department_id' => $dPack]); }
catch (Throwable $e) { $dup = str_contains($e->getMessage(), 'already in use'); }
ok($dup, 'a duplicate employee code is refused with a readable message');

$bridged = $pdo->query("SELECT att_employee_id FROM zp_workers WHERE worker_code='W001'")->fetch();
ok((int) $bridged['att_employee_id'] === $e1, 'D2 bridge written back onto zp_workers');

echo "7. Department scope is applied in SQL, not in the UI (AT06 groundwork)\n";
Roles::grant(10, Roles::HOD, $dStitch);
Roles::grant(11, Roles::HR);
ok(Roles::departmentScope(10, Roles::HOD) === [$dStitch], 'HOD is scoped to one department');
ok(Roles::departmentScope(11, Roles::HR) === null, 'HR is unscoped');
ok(Roles::mayActOnDepartment(10, Roles::HOD, $dStitch) === true, 'HOD may act on own department');
ok(Roles::mayActOnDepartment(10, Roles::HOD, $dPack) === false, 'HOD may NOT act on another department');
$scoped = Employees::search('', [$dStitch]);
ok(count($scoped) === 1 && $scoped[0]['code'] === 'E001', 'a scoped search returns only that department');
ok(Employees::search('', []) === [], 'scoped to nothing returns nothing, never everything');

/* A HOD row with no department is a configuration mistake. It must grant
   nothing — reading it as "all departments" would hand one HOD the whole
   factory by accident. */
Roles::grant(12, Roles::HOD);
ok(Roles::departmentScope(12, Roles::HOD) === [], 'a HOD with no department is scoped to NOTHING, not to everything');

echo "8. Granting twice is not an error; revoking works\n";
Roles::grant(10, Roles::HOD, $dStitch);
ok(count(Roles::forUser(10)) === 1, 'granting the same role twice leaves one row');
Roles::revoke(10, Roles::HOD, $dStitch);
ok(Roles::forUser(10) === [], 'revoke removes it');
Roles::grant(10, Roles::HOD, $dStitch);

echo "9. Shifts, including the ones that cross midnight\n";
$day   = Shifts::create(['name' => 'Day',   'start_time' => '09:00', 'end_time' => '17:00']);
$night = Shifts::create(['name' => 'Night', 'start_time' => '19:00', 'end_time' => '03:00']);
$dayRow   = Shifts::find($day);
$nightRow = Shifts::find($night);
ok((int) $dayRow['crosses_midnight'] === 0, 'a 09:00–17:00 shift does not cross midnight');
ok((int) $nightRow['crosses_midnight'] === 1, 'a 19:00–03:00 shift DOES cross midnight (derived, not typed)');

$w = Shifts::resolveWindow($nightRow, '2026-03-02');
ok($w['ends_at'] > $w['starts_at'], 'a night shift ends AFTER it starts');
/* The end is on the next LOCAL day — and deliberately not on the next UTC
   day, because 03:00 in Karachi is 22:00 UTC the evening before. An earlier
   draft of this test asserted the UTC date and failed; the code was right and
   the test was wrong, which is exactly the confusion resolveWindow() exists
   to take out of every downstream query. */
$endLocal = $w['ends_at']->setTimezone(new DateTimeZone('Asia/Karachi'));
ok($endLocal->format('Y-m-d') === '2026-03-03', '  and ends on the following LOCAL calendar day');
ok($w['ends_at']->format('Y-m-d H:i') === '2026-03-02 22:00', '  which is still the 2nd in UTC');
/* 19:00 Karachi is 14:00 UTC. Stored UTC, shown local — the contract's rule. */
ok($w['starts_at']->format('H:i') === '14:00', 'stored in UTC, not in the server timezone');

echo "10. One shift instance per employee per day\n";
$si1 = Shifts::assign($e1, $day, '2026-03-02');
$si2 = Shifts::assign($e1, $night, '2026-03-02');
ok($si1 === $si2, 'assigning twice UPDATES the day rather than creating a second instance');
$count = (int) $pdo->query("SELECT COUNT(*) c FROM att_shift_instances WHERE employee_id=$e1")->fetch()['c'];
ok($count === 1, '  so the employee has exactly one instance for that date');

echo "11. D4 — an employee with no shift is admitted, not turned away\n";
$at = new DateTimeImmutable('2026-03-10 05:00:00', new DateTimeZone('UTC')); // 10:00 Karachi
[$inst, $created] = Shifts::instanceForOrDefault($e2, $at);
ok($inst === null, 'with no default shift configured, nothing is invented');

Policy::set('default_shift_id', (string) $day, null, 'string');
Policy::flush();
[$inst, $created] = Shifts::instanceForOrDefault($e2, $at);
ok($inst !== null, 'with a default configured, the employee IS admitted');
ok($created === true, '  and the instance was created on the spot');
ok($inst['source'] === 'default_auto', '  marked default_auto so HR can see the system filled it in');
ok($inst['work_date'] === '2026-03-10', '  filed under the correct working date');

[$inst2, $created2] = Shifts::instanceForOrDefault($e2, $at);
ok($created2 === false && (int) $inst2['id'] === (int) $inst['id'], 'a second gate event reuses the instance, not a new one');

/* The switch must be honoured: turned off, nobody gets an automatic shift. */
Policy::set('auto_shift_on_gate_in', '0', null, 'bool');
Policy::flush();
[$inst3, ] = Shifts::instanceForOrDefault($e1, new DateTimeImmutable('2026-04-01 05:00:00', new DateTimeZone('UTC')));
ok($inst3 === null, 'auto_shift_on_gate_in=0 is obeyed (the policy is real, not decorative)');
Policy::set('auto_shift_on_gate_in', '1', null, 'bool');
Policy::flush();

echo "12. A night-shift scan after midnight belongs to the PREVIOUS working day\n";
Policy::set('default_shift_id', (string) $night, null, 'string');
Policy::flush();
$e3 = Employees::create(['code' => 'E003', 'full_name' => 'Night Worker', 'department_id' => $dStitch]);
/* 01:00 Karachi on the 12th = 20:00 UTC on the 11th. The worker started at
   19:00 on the 11th, so the shift is filed under the 11th. Get this wrong and
   every night worker's hours land on the wrong date. */
$after = new DateTimeImmutable('2026-03-11 20:00:00', new DateTimeZone('UTC'));
[$ni, ] = Shifts::instanceForOrDefault($e3, $after);
ok($ni !== null, 'the night worker is admitted');
ok($ni !== null && $ni['work_date'] === '2026-03-11', '  filed under the day the shift STARTED, not the calendar date of the scan');

echo "13. Time is stored UTC and shown with an offset\n";
$t = Time::parse('2026-03-02T19:00:00', 'Asia/Karachi');
ok($t !== null && $t->format('H:i') === '14:00', 'a Karachi device time is converted to UTC for storage');
ok(str_contains((string) Time::iso($t, 'Asia/Karachi'), '+05:00'), 'display carries the offset (ISO 8601)');
ok(Time::parse('not a date') === null, 'an unparseable time returns null rather than silently becoming "now"');
$skew = Time::skewMs((Time::nowUtc())->modify('+90 seconds'));
ok($skew > 85000 && $skew < 95000, 'clock skew is measured, not corrected');

echo "14. The audit trail is written and is append-only\n";
Audit::record('test.action', 'att_employee', $e1, ['a' => 1], ['a' => 2], 11, 'because');
$rows = Audit::forSubject('att_employee', $e1);
ok(count($rows) >= 1, 'an audit row is written');
$hasCreate = false;
foreach (Audit::forSubject('att_employee', $e1, 50) as $r) { if ($r['action'] === 'employee.create') { $hasCreate = true; } }
ok($hasCreate, 'creating an employee was audited without anyone asking');
$methods = get_class_methods(Audit::class);
ok(!in_array('update', $methods, true) && !in_array('delete', $methods, true),
   'Audit exposes no update() or delete() — the trail cannot be edited');

echo "15. The SQL splitter does not break on semicolons inside strings\n";
$stmts = Migrations::splitStatements(
    "INSERT INTO t (a) VALUES ('semi; colon');\n-- a comment with ; in it\nSELECT 1;"
);
ok(count($stmts) === 2, 'a semicolon inside a quoted string is not a statement end');
ok(str_contains($stmts[0], 'semi; colon'), '  and the string survives intact');

echo "\n$P passed, $F failed\n";
exit($F === 0 ? 0 : 1);
