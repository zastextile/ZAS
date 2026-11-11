<?php
declare(strict_types=1);

/* ATTENDANCE M7 — DASHBOARD AND REPORTS
 *
 * One known day at the factory, built from real gate scans, HOD
 * confirmations and an overtime line, and then read back through every
 * report. What must hold:
 *
 *   - present minutes are closed IN–OUT entries only, and an HOD
 *     confirmation never counts as an IN (blueprint §5)
 *   - late and left-early are measured against the shift with its grace
 *     minutes, and left-early is only "potential"
 *   - a night shift is reported under the day it began, across midnight
 *   - overtime in a report is only what people approved and checked; time
 *     inside after the shift is not overtime (AT08 again, on the report side)
 *   - a HOD sees their own departments only; the reports change nothing
 */

$B = __DIR__ . '/app_src/public_html/';
$P = 0; $F = 0;
function ok($c, $m) { global $P, $F; if ($c) { $P++; } else { $F++; echo "  FAIL: $m\n"; } }

$dsn = getenv('ATT_TEST_DSN') ?: 'mysql:host=127.0.0.1;port=3306;dbname=zas_att_test;charset=utf8mb4';
$dbu = getenv('ATT_TEST_USER') ?: 'zas';
$dbp = getenv('ATT_TEST_PASS'); if ($dbp === false) { $dbp = 'zaspw'; }
if (!is_file($B . 'config/config.php')) { echo "SKIP: no test config — run tests/run_att.sh\n"; exit(0); }
foreach (['Db','Time','Migrations','Policy','Audit','Roles','Employees','Shifts','Receipts','AttendanceService','HodService','Reconciler',
          'SyncService','OvertimeService','ReportService'] as $c) {
    require_once $B . "includes/att/$c.php";
}
use Att\Db;
use Att\Employees;
use Att\HodService;
use Att\Migrations;
use Att\OvertimeService as OT;
use Att\Policy;
use Att\ReportService as R;
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
$mig = new Migrations($B . 'sql/att');
$mig->up();
$n = fn(string $sql, array $p = []) => (int) Db::value($sql, $p);

$hash = password_hash('CorrectHorse9', PASSWORD_DEFAULT);
$mk = function (string $name, string $email, string $role = 'colleague') use ($pdo, $hash): int {
    $pdo->prepare("INSERT INTO users (name,email,password_hash,role) VALUES (?,?,?,?)")->execute([$name, $email, $hash, $role]);
    return (int) $pdo->lastInsertId();
};
$admin = $mk('Admin Person', 'admin@zas.test', 'admin');
$gk    = $mk('Gul Gatekeeper', 'gate@zas.test');
$hod   = $mk('Hina Hod', 'hod@zas.test');
$hr    = $mk('Rana HR', 'hr@zas.test');

$dS = Employees::createDepartment('Stitching');
$dP = Employees::createDepartment('Packing');
$emp = [];
foreach ([['E001','Imran Ali',$dS],['E002','Bilal Ahmed',$dS],['E003','Nadia Noor',$dS],['E004','Asad Mir',$dS],['E005','Sara Khan',$dP],['E006','Day Off',$dS],['E007','Night Worker',$dS]] as [$c, $nm, $d]) {
    $emp[$c] = Employees::create(['code' => $c, 'full_name' => $nm, 'department_id' => $d]);
}
Roles::grant($gk, Roles::GATEKEEPER);
Roles::grant($hod, Roles::HOD, $dS);
Roles::grant($hr, Roles::HR);
$day   = Shifts::create(['name' => 'Day', 'start_time' => '09:00', 'end_time' => '17:00', 'grace_in_minutes' => 10, 'grace_out_minutes' => 10]);
$night = Shifts::create(['name' => 'Night', 'start_time' => '22:00', 'end_time' => '06:00', 'grace_in_minutes' => 10, 'grace_out_minutes' => 10]);
Policy::set('default_shift_id', (string) $day, null, 'string'); Policy::flush();
Shifts::assign($emp['E004'], $day, '2026-03-02');        // given a shift, never came
Shifts::assign($emp['E007'], $night, '2026-03-02');      // 22:00 on the 2nd to 06:00 on the 3rd
const GATE = 'aaaaaaaa-aaaa-4aaa-8aaa-aaaaaaaaaaaa';
S::registerDevice(GATE, 'Main gate', 'gate', $gk);
$seq = 0; $un = 0;
$uuid = function () use (&$un): string { return sprintf('%08x-0000-4000-8000-%012x', random_int(0, 0xffffffff), ++$un); };
/* A scan at $hm UTC on $date. 03:55Z is 08:55 at the factory. */
$scan = function (string $type, string $code, string $hm, string $date = '2026-03-02') use (&$seq, $uuid, $gk): array {
    return S::process(['event_uuid' => $uuid(), 'device_id' => GATE, 'device_sequence' => ++$seq, 'employee_code' => $code, 'event_type' => $type,
        'occurred_at' => "{$date}T{$hm}:00Z", 'timezone' => 'UTC', 'payload' => []], $gk, true);
};

/* ---- the day ---------------------------------------------------------- */
$in1 = $scan('GATE_IN', 'E001', '03:55');  $scan('GATE_OUT', 'E001', '06:00');      // 08:55 – 11:00
$scan('GATE_IN', 'E001', '06:30');         $scan('GATE_OUT', 'E001', '12:00');      // 11:30 – 17:00
$scan('GATE_IN', 'E002', '04:30');         $scan('GATE_OUT', 'E002', '10:00');      // 09:30 – 15:00: late 30, early 120
$scan('GATE_IN', 'E003', '04:00');                                                  // still inside
$scan('GATE_IN', 'E005', '04:10');         $scan('GATE_OUT', 'E005', '11:50');      // 09:10 – 16:50: exactly on both graces
$scan('GATE_IN', 'E007', '17:00');         $scan('GATE_OUT', 'E007', '01:00', '2026-03-03');   // 22:00 – 06:00 next day
$c1 = (int) Db::value('SELECT id FROM att_entry_cycles WHERE employee_id = ? ORDER BY id LIMIT 1', [$emp['E001']]);
HodService::confirm(['event_uuid' => $uuid(), 'hod_user_id' => $hod, 'entry_cycle_id' => $c1, 'employee_id' => $emp['E001'], 'device_time' => '2026-03-02T04:20:00Z', 'timezone' => 'UTC']);
/* Overtime for E001: approved 60, checked 45. E002 stayed nowhere late; E003 is inside. */
OT::setToday('2026-03-02');
$rq = OT::createRequest(['request_uuid' => $uuid(), 'department_id' => $dS, 'work_date' => '2026-03-02', 'reason' => 'urgent order',
    'employees' => [['employee_code' => 'E001', 'estimated_minutes' => 60]]], null, $hod);
$L = (int) $rq['request']['lines'][0]['request_employee_id'];
OT::decide((int) $rq['request']['id'], $uuid(), [['request_employee_id' => $L, 'decision' => 'approve']], $hr);
OT::setToday('2026-03-03');
OT::verify((int) $rq['request']['id'], $L, 45, '', null, $hr);
OT::setToday(null);
R::setNow('2026-03-02T13:00:00Z');                  // 18:00 at the factory

$tables = ['att_events', 'att_entry_cycles', 'att_hod_arrivals', 'att_shift_instances', 'att_ot_request_employees', 'att_ot_payroll', 'att_employees'];
$snapshot = fn() => array_map(fn($t) => $n("SELECT COUNT(*) FROM $t") . ':' . (string) Db::value("SELECT MAX(id) FROM $t"), $tables);
$before = $snapshot();

/* ===================================================================== */
echo "1. The attendance report, line by line\n";
$a = R::attendance(['from' => '2026-03-02', 'to' => '2026-03-02'], null);
$row = [];
foreach ($a['rows'] as $x) { $row[$x['employee_code']] = $x; }
ok(count($a['rows']) === 6, 'six shift days: E001, E002, E003, E004, E005, E007 (E006 had no shift and no scan) — got ' . implode(',', array_keys($row)));
ok($row['E001']['entries'] === 2 && $row['E001']['present_minutes'] === 455, 'E001: two entries, 455 present minutes (125 + 330) — the half-hour outside is not counted');
ok($row['E001']['unconfirmed'] === 1, '  one of the two entries still owes its HOD confirmation');
ok($row['E001']['late_minutes'] === 0 && $row['E001']['early_minutes'] === 0 && $row['E001']['status'] === 'present', '  in at 08:55, out at 17:00: not late, not early');
ok(strtotime((string) $row['E001']['first_in']) === strtotime('2026-03-02T03:55:00Z') && $row['E001']['ot_approved_minutes'] === 60 && $row['E001']['ot_actual_minutes'] === 45,
   '  with the overtime people approved (60) and checked (45)');
ok($row['E002']['late_minutes'] === 30 && $row['E002']['early_minutes'] === 120, 'E002: in 09:30 = 30 min late; out 15:00 = 120 min early (potential)');
ok($row['E003']['status'] === 'inside' && $row['E003']['present_minutes'] === 0 && $row['E003']['early_minutes'] === 0,
   'E003, still inside: "inside", no present minutes yet, and not "left early"');
ok($row['E004']['status'] === 'absent' && $row['E004']['entries'] === 0, 'E004 was given a shift and did not come: "absent"');
ok($row['E005']['late_minutes'] === 0 && $row['E005']['early_minutes'] === 0, 'E005 in at 09:10 and out at 16:50 — EXACTLY ON BOTH GRACE LIMITS: neither late nor early');
ok($row['E007']['work_date'] === '2026-03-02' && $row['E007']['present_minutes'] === 480 && $row['E007']['late_minutes'] === 0 && $row['E007']['early_minutes'] === 0,
   'E007, NIGHT SHIFT 22:00–06:00: one day, filed under the 2nd, 480 minutes, on time');
ok(R::attendance(['from' => '2026-03-03', 'to' => '2026-03-03'], null)['rows'] === [], '  and nothing on the 3rd, although the OUT happened then');
ok($row['E003']['ot_actual_minutes'] === null && $row['E002']['ot_actual_minutes'] === null, 'NOBODY ELSE HAS OVERTIME, however long they stayed (AT08)');
$t = $a['totals'];
ok($t['present'] === 5 && $t['absent'] === 1 && $t['present_minutes'] === 455 + 330 + 0 + 460 + 480 && $t['late'] === 1 && $t['left_early'] === 1 && $t['ot_actual_minutes'] === 45,
   'totals: 5 worked, 1 absent, 1725 present minutes, 1 late, 1 early, 45 OT (got ' . json_encode($t) . ')');
$hodTimes = array_map(fn($x) => $x['first_in'], $a['rows']);
ok(!in_array(strtotime('2026-03-02T04:20:00Z'), array_map('strtotime', array_filter($hodTimes)), true), 'THE HOD CONFIRMATION AT 04:20 IS NOWHERE IN THE ATTENDANCE REPORT — it is not an IN');

echo "2. Department scope\n";
$hs = R::attendance(['from' => '2026-03-02', 'to' => '2026-03-02'], [$dS]);
ok(count($hs['rows']) === 5 && !in_array('E005', array_column($hs['rows'], 'employee_code'), true), 'the Stitching HOD sees Stitching only — no Packing');
ok(R::attendance(['from' => '2026-03-02', 'to' => '2026-03-02'], [])['rows'] === [], 'someone scoped to no department sees nobody');
ok(count(R::attendance(['from' => '2026-03-02', 'to' => '2026-03-02', 'department_id' => $dP], null)['rows']) === 1, 'filtering by Packing gives one line');
ok(count(R::attendance(['from' => '2026-03-02', 'to' => '2026-03-02', 'employee' => 'nadia'], null)['rows']) === 1, 'searching "nadia" finds Nadia Noor');
ok(R::attendance(['from' => '2026-03-02', 'to' => '2026-03-02', 'employee' => '%'], null)['rows'] === [], 'a % typed in the search is a character, not "everything"');

echo "3. Today\n";
$d = R::dashboard(null);
ok($d['today'] === '2026-03-02' && $d['inside_now'] === 1 && $d['inside_by_department'][0]['department_name'] === 'Stitching', 'one person inside now, in Stitching');
ok($d['came_in'] === 5 && $d['active_employees'] === 7 && $d['not_in_count'] === 2, 'came in 5 of 7 active; 2 with no entry today');
ok(array_column($d['not_in'], 'employee_code') === ['E004', 'E006'], '  which are E004 (had a shift) and E006 (day off)');
ok($d['late_count'] === 1 && $d['left_early_count'] === 1 && $d['absent_with_shift_count'] === 1, 'late 1, left early 1, absent with a shift 1');
ok($d['unconfirmed_entries'] === 5, 'five of today\'s six entries still owe a HOD confirmation');
ok(isset($d['monitoring']) && $d['monitoring']['ot_waiting_rate'] === 1 && $d['monitoring']['held_scans'] === 0, 'HR also sees what needs attention: one overtime line waiting for a rate');
ok(str_contains($d['monitoring']['backups'], 'Cloudways'), '  and is told plainly that backups are checked in Cloudways, not here');
$hd = R::dashboard([$dS]);
ok($hd['came_in'] === 4 && $hd['active_employees'] === 6 && !isset($hd['monitoring']), 'the HOD\'s dashboard counts Stitching only, and has no system monitoring');
ok(R::dashboard([])['empty_scope'] ?? false, 'a dashboard for nobody says so');

echo "4. Movements\n";
$m = R::movements(['from' => '2026-03-02', 'to' => '2026-03-03'], null);
ok(count($m['rows']) === 11, 'eleven scans over the two days (got ' . count($m['rows']) . ')');
$f1 = $m['rows'][0];
ok($f1['employee_code'] === 'E001' && $f1['event_type'] === 'GATE_IN' && $f1['recorded_by'] === 'Gul Gatekeeper' && $f1['phone'] === 'Main gate', 'the first is E001 IN, recorded by Gul on the Main gate phone');
ok($f1['hod_confirmed_at'] !== null && $f1['hod_name'] === 'Hina Hod' && (int) $f1['entry_sequence'] === 1, '  with its HOD confirmation and entry number beside it');
$one = R::movements(['from' => '2026-03-02', 'to' => '2026-03-02'], null);
ok(count($one['rows']) === 10 && end($one['rows'])['employee_code'] === 'E007', 'on the 2nd alone: ten — E007\'s 06:00 OUT belongs to the 3rd by the clock');
ok(count(R::movements(['from' => '2026-03-02', 'to' => '2026-03-03'], [$dP])['rows']) === 2, 'the Packing scope sees Sara\'s two scans only');

echo "5. Overtime\n";
$o = R::overtime(['from' => '2026-03-01', 'to' => '2026-03-31'], null);
ok(count($o['rows']) === 1 && $o['rows'][0]['approval_status'] === 'approved' && (int) $o['rows'][0]['actual_minutes'] === 45, 'one line: E001, approved, 45 checked');
$b = $o['by_department'][0];
ok($b['department_name'] === 'Stitching' && $b['requested'] === 1 && $b['approved_minutes'] === 60 && $b['actual_minutes'] === 45 && $b['finalized_amount'] === '0.00',
   'by department: 1 asked, 60 approved, 45 checked, nothing paid yet');
ok(R::overtime(['from' => '2026-03-01', 'to' => '2026-03-31'], [$dP])['rows'] === [], 'Packing scope: no overtime');

echo "6. Periods, and nothing written\n";
ok((R::attendance(['from' => '2026-01-01', 'to' => '2026-03-31'], null)['reason_code'] ?? '') === 'VALIDATION', 'more than 62 days at once is refused');
ok((R::attendance(['from' => '2026-03-05', 'to' => '2026-03-01'], null)['reason_code'] ?? '') === 'VALIDATION', 'a last day before the first is refused');
ok((R::movements(['from' => '2026-02-30', 'to' => '2026-03-01'], null)['reason_code'] ?? '') === 'VALIDATION', '30 February is not a day');
ok((R::overtime(['from' => ['x'], 'to' => '2026-03-01'], null)['reason_code'] ?? '') === 'VALIDATION', 'a date that is not text is refused');
ok($snapshot() === $before, 'RUNNING EVERY REPORT CHANGED NOTHING in the attendance or overtime tables');

/* ===================================================================== */
R::setNow(null);
$port = 8734;
$srv = proc_open(sprintf('exec php -S 127.0.0.1:%d -t %s', $port, escapeshellarg(rtrim($B, '/'))),
    [1 => ['file', '/tmp/att_m7_srv.log', 'a'], 2 => ['file', '/tmp/att_m7_srv.log', 'a']], $pipes);
register_shutdown_function(static function () use ($srv) { if (is_resource($srv)) { proc_terminate($srv, 9); proc_close($srv); } });
for ($i = 0; $i < 60; $i++) { $c = @fsockopen('127.0.0.1', $port, $e1, $e2, 0.2); if ($c) { fclose($c); break; } usleep(200000); }
$jar = tempnam(sys_get_temp_dir(), 'attm7');
function call(string $method, string $path, array $data = [], ?string $csrf = null): array
{
    global $port, $jar;
    $url = "http://127.0.0.1:$port/api/v1/index.php?route=" . rawurlencode($path);
    $h = ['Accept: application/json'];
    if ($csrf !== null) { $h[] = "X-CSRF-Token: $csrf"; }
    $ch = curl_init();
    $o = [CURLOPT_RETURNTRANSFER => true, CURLOPT_COOKIEJAR => $jar, CURLOPT_COOKIEFILE => $jar, CURLOPT_TIMEOUT => 30, CURLOPT_CUSTOMREQUEST => $method, CURLOPT_HEADER => true];
    if ($method !== 'GET') { $o[CURLOPT_POSTFIELDS] = json_encode($data); $h[] = 'Content-Type: application/json'; }
    elseif ($data !== []) { $url .= '&' . http_build_query($data); }
    $o[CURLOPT_URL] = $url; $o[CURLOPT_HTTPHEADER] = $h;
    curl_setopt_array($ch, $o);
    $raw = (string) curl_exec($ch);
    $st = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $hs = (int) curl_getinfo($ch, CURLINFO_HEADER_SIZE);
    curl_close($ch);
    $b = substr($raw, $hs);
    $j = json_decode($b, true);
    return ['status' => $st, 'headers' => strtolower(substr($raw, 0, $hs)), 'body' => is_array($j) ? $j : [], 'raw' => $b];
}
function signIn(string $email): string
{
    global $jar;
    file_put_contents($jar, '');
    return (string) (call('POST', '/auth/login', ['email' => $email, 'password' => 'CorrectHorse9'])['body']['csrf_token'] ?? '');
}
$perm = fn(int $uid, string $m) => (string) Db::value('SELECT actions FROM zu_perm WHERE user_id = ? AND module = ?', [$uid, $m]);

echo "7. Over HTTP\n";
$as = signIn('admin@zas.test');
foreach ([[$gk, 'gatekeeper', 0], [$hod, 'hod', $dS], [$hr, 'hr', 0]] as [$uid, $role, $dept]) {
    Roles::revoke($uid, $role, $dept ?: null);
    call('POST', '/admin/jobs/grant', ['user_id' => $uid, 'role' => $role, 'department_id' => $dept], $as);
}
ok($perm($hod, 'attrep') === 'v' && $perm($hr, 'attrep') === 'v' && $perm($gk, 'attrep') === '', 'the HOD and HR jobs include Reports; the gatekeeper job does not');
$q = ['from' => '2026-03-02', 'to' => '2026-03-02'];
signIn('gate@zas.test');
ok(call('GET', '/reports/attendance', $q)['status'] === 403 && call('GET', '/reports/dashboard')['status'] === 403, 'a gatekeeper cannot open reports');
signIn('hod@zas.test');
$ha = call('GET', '/reports/attendance', $q);
ok($ha['status'] === 200 && count($ha['body']['rows']) === 5 && !str_contains($ha['raw'], 'Sara Khan'), 'THE HOD GETS STITCHING ONLY over HTTP — Sara of Packing is not in the answer at all');
ok(call('GET', '/reports/overtime', ['from' => '2026-03-01', 'to' => '2026-03-31'])['status'] === 200, 'the HOD may see their department\'s overtime');
$rs = signIn('hr@zas.test');
$ra = call('GET', '/reports/attendance', $q);
ok($ra['status'] === 200 && count($ra['body']['rows']) === 6, 'HR gets every department');
$csv = call('GET', '/reports/attendance', $q + ['format' => 'csv']);
ok($csv['status'] === 200 && str_contains($csv['headers'], 'text/csv') && str_contains($csv['headers'], 'attendance-2026-03-02-to-2026-03-02.csv'), 'the same report downloads as a CSV file');
ok(str_contains($csv['raw'], '"2026-03-02 08:55"') && str_contains($csv['raw'], '"2026-03-03 06:00"'), '  with times in factory time — 08:55, and the night worker\'s 06:00 the next morning');
ok(str_contains($csv['raw'], '"Night Worker"') && str_contains($csv['raw'], '"absent"'), '  every line, absent included');
$mcsv = call('GET', '/reports/movements', $q + ['format' => 'csv']);
ok(str_contains($mcsv['raw'], '"Main gate"') && str_contains($mcsv['raw'], '"Hina Hod"'), 'the movements CSV names the phone and the HOD');
ok($n("SELECT COUNT(*) FROM att_audit_logs WHERE action = 'report.export' AND actor_id = ?", [$hr]) === 2, 'each download is in the audit log, under who took it');
$db = call('GET', '/reports/dashboard');
ok($db['status'] === 200 && isset($db['body']['monitoring']) && isset($db['body']['inside_now']), 'HR\'s dashboard, with monitoring');
ok(call('GET', '/reports/attendance', ['from' => '2026-03-02'])['status'] === 422, 'a report with one date missing: 422');

echo "8. Phones on the Setup screen\n";
$as = signIn('admin@zas.test');
$dv = call('GET', '/devices')['body']['devices'][0] ?? [];
ok(($dv['label'] ?? '') === 'Main gate' && (int) ($dv['scans'] ?? 0) === 11 && ($dv['registered_by_name'] ?? '') === 'Gul Gatekeeper', 'the phone list shows the name, 11 scans, and who signed in first');
ok(call('POST', '/admin/devices/' . $dv['id'] . '/label', ['label' => ''], $as)['status'] === 422, 'a blank name is refused');
ok(call('POST', '/admin/devices/' . $dv['id'] . '/label', ['label' => 'Unit 1 – Main gate'], $as)['status'] === 200
   && Db::value('SELECT label FROM att_devices WHERE id = ?', [$dv['id']]) === 'Unit 1 – Main gate', 'renamed to "Unit 1 – Main gate"');
ok(str_contains(json_encode(R::movements(['from' => '2026-03-02', 'to' => '2026-03-02'], null)['rows'][0], JSON_UNESCAPED_UNICODE), 'Unit 1 – Main gate'), '  and the report now shows the new name');
$rs = signIn('hr@zas.test');
ok(call('POST', '/admin/devices/' . $dv['id'] . '/label', ['label' => 'x'], $rs)['status'] === 403, 'HR, without the setup permission, cannot rename a phone');

/* ===================================================================== */
echo "9. In a browser\n";
$work = __DIR__ . '/.zattm7';
@mkdir($work, 0777, true);
$shot = getenv('ATT_SHOT_DIR') ?: '';
file_put_contents($work . '/run.js', <<<'JS'
const { chromium } = require('playwright');
const BASE = process.argv[2], SHOT = process.argv[3] || '';
const out = [];
const check = (name, pass, extra) => out.push({ name, pass: !!pass, extra: extra === undefined ? '' : String(extra) });
async function desk(page, email) {
  await page.goto(BASE + '/att/manifest.webmanifest');
  return page.evaluate(async (email) => (await fetch('../api/v1/index.php?route=%2Fauth%2Flogin', { method: 'POST', credentials: 'same-origin',
    headers: { 'Content-Type': 'application/json' }, body: JSON.stringify({ email, password: 'CorrectHorse9' }) })).status, email);
}
const loaded = (p) => p.waitForFunction(() => !/^Loading…$/.test(document.getElementById('attr-body').textContent.trim()), null, { timeout: 20000 });
(async () => {
  const browser = await chromium.launch();
  try {
    const c = await browser.newContext({ viewport: { width: 1280, height: 900 } });
    const p = await c.newPage();
    const errs = [];
    p.on('pageerror', e => errs.push(e.message));
    await desk(p, 'hr@zas.test');
    await p.goto(BASE + '/att_reports.php');
    await loaded(p);
    check('the menu shows Attendance › Reports', /Reports/.test(await p.textContent('.nav')));
    check('Today opens with the tiles and "Needs attention"', (await p.locator('#t-inside').count()) === 1 && (await p.locator('#watch li').count()) === 10);
    await p.click('#attr-tabs button:has-text("Attendance")');
    await loaded(p);
    await p.fill('#f-from', '2026-03-02'); await p.fill('#f-to', '2026-03-02');
    await p.click('#f-show');
    await p.waitForSelector('#attr-body table');
    const body = await p.textContent('#attr-body');
    check('the attendance report shows the day: Night Worker, "did not come", late 30m', /Night Worker/.test(body) && /did not come/.test(body) && /30m/.test(body), body.slice(0, 200));
    check('the CSV link asks the same report as a file', /format=csv/.test(await p.getAttribute('#f-csv', 'href')));
    if (SHOT) await p.screenshot({ path: SHOT + '/att-m7-attendance.png', fullPage: true });
    await p.click('#attr-tabs button:has-text("Movements")');
    await loaded(p);
    await p.waitForSelector('#attr-body table');
    check('movements: one row per scan, with the HOD who confirmed', (await p.locator('#attr-body tbody tr').count()) === 10 && /Hina Hod/.test(await p.textContent('#attr-body')));
    await p.click('#attr-tabs button:has-text("Overtime")');
    await loaded(p);
    await p.fill('#f-from', '2026-03-01'); await p.fill('#f-to', '2026-03-31'); await p.click('#f-show');
    await p.waitForSelector('#attr-body table');
    check('overtime: by department, 1h 0m approved and 45m checked', /1h 0m/.test(await p.textContent('#attr-body')) && /45m/.test(await p.textContent('#attr-body')));
    await p.click('#attr-tabs button:has-text("Today")');
    await loaded(p);
    if (SHOT) await p.screenshot({ path: SHOT + '/att-m7-today.png', fullPage: true });
    const m = await browser.newContext({ viewport: { width: 390, height: 800 }, storageState: await c.storageState() });
    const mp = await m.newPage();
    const wide = [];
    for (const t of ['today', 'att', 'mov', 'ot']) {
      await mp.goto(BASE + '/att_reports.php#' + t); await mp.reload(); await loaded(mp); await mp.waitForTimeout(300);
      const o = await mp.evaluate(() => document.documentElement.scrollWidth - innerWidth);
      if (o > 1) wide.push(t + ' +' + o);
    }
    check('at phone width no report pushes the page sideways', wide.length === 0, wide.join(', '));
    await m.close();
    check('no script error on the reports page', errs.length === 0, errs.join(' | '));
    await c.close();

    const a = await browser.newContext({ viewport: { width: 1280, height: 900 } });
    const ap = await a.newPage();
    ap.on('dialog', d => d.accept());
    await desk(ap, 'admin@zas.test');
    await ap.goto(BASE + '/att_setup.php#phones');
    await ap.reload();
    await ap.waitForSelector('#atts-body table');
    check('Setup › Phones lists the gate phone with its scans', /Unit 1 – Main gate/.test(await ap.textContent('#atts-body')) && /11/.test(await ap.textContent('#atts-body tbody')));
    await ap.click('#atts-body button:has-text("Rename")');
    await ap.fill('#ph-label', 'Back gate');
    await ap.click('#attsdlg-ok');
    await ap.waitForFunction(() => /Back gate/.test(document.getElementById('atts-body').textContent));
    check('renaming a phone from the screen works', true);
    if (SHOT) await ap.screenshot({ path: SHOT + '/att-m7-phones.png' });
    await a.close();
  } catch (e) { check('the browser run completed', false, e && e.message); }
  finally { await browser.close(); }
  console.log('RESULT' + JSON.stringify(out));
})();
JS);
$raw = (string) shell_exec('cd ' . escapeshellarg($work) . ' && timeout 300 node run.js ' . escapeshellarg("http://127.0.0.1:$port") . ' ' . escapeshellarg($shot) . ' 2>&1');
$results = [];
if (preg_match('/RESULT(\[.*\])\s*$/s', $raw, $mm)) { $results = json_decode($mm[1], true) ?: []; }
ok(count($results) >= 10, 'the browser run reported its checks (' . count($results) . ')' . (count($results) ? '' : "\n$raw"));
foreach ($results as $r) { ok($r['pass'], $r['name'] . ($r['pass'] ? '' : ' — ' . $r['extra'])); }

echo "\n$P passed, $F failed\n";
exit($F === 0 ? 0 : 1);
