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
foreach (['Db', 'Migrations', 'Policy', 'Audit', 'Roles', 'Employees', 'Time', 'Shifts', 'ReportService'] as $c) { require_once $B . "includes/att/$c.php"; }
use Att\Db; use Att\Employees; use Att\Migrations; use Att\ReportService; use Att\Shifts; use Att\Time;
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

echo "\nNOT YET STARTED: $P passed, $F failed\n";
exit($F ? 1 : 0);
