<?php
declare(strict_types=1);

/* A SHIFT THAT HAS NOT STARTED YET IS NOT "DID NOT COME" (owner, 19 Nov 2026:
 * 234 Rauf Guard, night shift 19:00–08:30, showed "A" in the morning for the
 * shift starting that evening). ReportService leaves a shift with no entry out
 * of every figure until its start time; from the start time on, no IN = absent.
 * Run through tests/run_att.sh. */

$B = __DIR__ . '/app_src/public_html/';
$P = 0; $F = 0;
function ok($c, $m) { global $P, $F; if ($c) { $P++; } else { $F++; echo "  FAIL: $m\n"; } }
$dsn  = getenv('ATT_TEST_DSN') ?: 'mysql:host=127.0.0.1;port=3306;dbname=zas_att_test;charset=utf8mb4';
$user = getenv('ATT_TEST_USER') ?: 'zas';
$pass = getenv('ATT_TEST_PASS'); if ($pass === false) { $pass = 'zaspw'; }
foreach (['Db', 'Migrations', 'Policy', 'Audit', 'Roles', 'Employees', 'Time', 'Shifts', 'ReportService', 'AdminService'] as $c) { require_once $B . "includes/att/$c.php"; }
use Att\AdminService; use Att\Db; use Att\Employees; use Att\Migrations; use Att\ReportService; use Att\Shifts; use Att\Time;
try { $pdo = new PDO($dsn, $user, $pass, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC, PDO::ATTR_EMULATE_PREPARES => false]); }
catch (Throwable $e) { echo "SKIP: no MySQL\n"; exit(0); }
Db::bind($pdo); Db::useUtc();
$pdo->exec('SET FOREIGN_KEY_CHECKS=0');
foreach ($pdo->query("SELECT table_name AS tn FROM information_schema.tables WHERE table_schema = DATABASE()")->fetchAll() as $t) { $pdo->exec('DROP TABLE IF EXISTS `' . $t['tn'] . '`'); }
$pdo->exec('SET FOREIGN_KEY_CHECKS=1');
$pdo->exec("CREATE TABLE users (id INT AUTO_INCREMENT PRIMARY KEY, name VARCHAR(150) NOT NULL, email VARCHAR(190) NOT NULL UNIQUE, password_hash VARCHAR(255) NOT NULL, role VARCHAR(40) NOT NULL DEFAULT 'colleague', is_active TINYINT(1) NOT NULL DEFAULT 1)");
$pdo->exec("CREATE TABLE zp_workers (id INT AUTO_INCREMENT PRIMARY KEY, worker_code VARCHAR(20) NOT NULL UNIQUE, worker_name VARCHAR(120) NOT NULL, department VARCHAR(60) NULL, is_active TINYINT(1) NOT NULL DEFAULT 1)");
$pdo->exec("CREATE TABLE IF NOT EXISTS zu_perm (id INT AUTO_INCREMENT PRIMARY KEY, user_id INT NOT NULL, module VARCHAR(24) NOT NULL, actions VARCHAR(12) NOT NULL DEFAULT '', UNIQUE KEY uniq_user_module (user_id, module))");
(new Migrations($B . 'sql/att'))->up();

$dept = Employees::createDepartment('NIGHT SHIFT');
$now = new DateTimeImmutable('now', new DateTimeZone('Asia/Karachi'));
$today = $now->format('Y-m-d');
/* one shift starting two hours from now, one that started two hours ago — whatever the hour */
$hm = fn(DateTimeImmutable $t) => $t->format('H:i');
$later  = Shifts::create(['name' => 'LATER',  'start_time' => $hm($now->modify('+2 hours')), 'end_time' => $hm($now->modify('+10 hours')), 'grace_in_minutes' => 15, 'grace_out_minutes' => 15]);
$begun  = Shifts::create(['name' => 'BEGUN',  'start_time' => $hm($now->modify('-2 hours')), 'end_time' => $hm($now->modify('+6 hours')), 'grace_in_minutes' => 15, 'grace_out_minutes' => 15]);
$a = Employees::create(['code' => '234', 'full_name' => 'Rauf Guard', 'department_id' => $dept]);
$b = Employees::create(['code' => '379', 'full_name' => 'Bilal Guard', 'department_id' => $dept]);
/* the work date is the day the shift starts: two hours ahead may already be tomorrow */
Shifts::assign($a, $later, $now->modify('+2 hours')->format('Y-m-d'), 'assigned', null);
Shifts::assign($b, $begun, $now->modify('-2 hours')->format('Y-m-d'), 'assigned', null);

$from = $now->modify('-1 day')->format('Y-m-d'); $to = $now->modify('+1 day')->format('Y-m-d');
$r = ReportService::attendance(['from' => $from, 'to' => $to], null);
$codes = array_column($r['rows'] ?? [], 'status', 'employee_code');
ok($r['ok'] && !isset($codes['234']), '234, whose shift starts in two hours, is not on the list at all — not "did not come" (' . json_encode($codes) . ')');
ok(($codes['379'] ?? '') === 'absent', '379, whose shift began two hours ago with no IN, did not come');
$d = ReportService::dashboard(null);
$abs = array_column($d['absent_with_shift'] ?? [], 'employee_code');
ok(!in_array('234', $abs, true), 'Today › Did not come leaves out the shift that has not started');


echo "2. Day off\n";
$day = Shifts::create(['name' => 'DAY', 'start_time' => '09:00', 'end_time' => '18:00', 'grace_in_minutes' => 15, 'grace_out_minutes' => 15]);
$dd = []; for ($i = 5; $i >= 3; $i--) $dd[] = $now->modify("-$i days")->format('Y-m-d');
foreach ($dd as $wd) { Shifts::assign($a, $day, $wd, 'assigned', null); Shifts::assign($b, $day, $wd, 'assigned', null); }
/* 379 came on the middle day: an entry an administrator added */
$si = (int) $pdo->query("SELECT id FROM att_shift_instances WHERE employee_id = $b AND work_date = '{$dd[1]}'")->fetchColumn();
$pdo->prepare("INSERT INTO att_entry_cycles (employee_id, shift_instance_id, entry_sequence, opened_at, closed_at, present_minutes, in_source, close_source) VALUES (?,?,1,?,?,480,'admin','admin')")
    ->execute([$b, $si, $dd[1] . ' 04:00:00', $dd[1] . ' 12:00:00']);
$abs0 = array_filter(ReportService::attendance(['from' => $dd[0], 'to' => $dd[2]], null)['rows'], fn($x) => $x['status'] === 'absent');
ok(count($abs0) === 5, '(before: five "did not come" days — 234 three, 379 two)');
ok(AdminService::dayOff($dd[0], $dd[2], [], $dept, [], '', 1)['ok'] === false, 'no reason, no day off');
/* the screen sends an empty code box as one empty code: the department must still be used (owner's screenshot, 19 Nov) */
$far = $now->modify('+20 days')->format('Y-m-d');
$e1 = AdminService::dayOff($far, $far, [], $dept, [''], 'check', 1);
ok($e1['ok'] === true && $e1['employees'] === 2, 'a department with an empty code box is taken as the whole department (' . json_encode($e1) . ')');
$r = AdminService::dayOff($dd[0], $dd[2], [], 0, ['234', '379', '999'], 'weekly off', 1);
ok($r['ok'] && $r['removed'] === 5 && count($r['kept']) === 1 && str_starts_with($r['kept'][0], '379') && $r['unknown_codes'] === ['999'],
   'five shift days become day off; 379\'s day with an entry is kept and named; 999 is reported as unknown (' . json_encode($r) . ')');
$rows = ReportService::attendance(['from' => $dd[0], 'to' => $dd[2]], null)['rows'];
ok(count($rows) === 1 && $rows[0]['employee_code'] === '379' && $rows[0]['status'] === 'present', 'after: no "did not come" at all; only 379\'s worked day is left');
ok((int) $pdo->query("SELECT COUNT(*) FROM att_entry_cycles")->fetchColumn() === 1, 'the entry itself is untouched');
ok((int) $pdo->query("SELECT COUNT(*) FROM att_audit_logs WHERE action = 'shift.day_off'")->fetchColumn() === 2, 'one audit line for each day off given, with the reason');

echo "\nNOT YET STARTED / DAY OFF: $P passed, $F failed\n";
exit($F ? 1 : 0);
