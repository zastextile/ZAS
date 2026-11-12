<?php
declare(strict_types=1);

/* ATTENDANCE — AUTOMATIC OUT FOR A FORGOTTEN EXIT SCAN
 *
 * The owner's rule: someone who leaves without scanning OUT is closed by the
 * system at the END OF THEIR SHIFT — or shift end plus APPROVED overtime —
 * once a waiting time after that has passed with still no OUT. It is marked
 * "not scanned out" for ever, on reports, for HR and at the gate.
 *
 * One day, 2 March, Day shift 08:00–18:00 at the factory (03:00–13:00 UTC),
 * waiting time 4 hours: nothing happens before 22:00; then everyone still
 * inside is closed at 18:00.
 */

$B = __DIR__ . '/app_src/public_html/';
$P = 0; $F = 0;
function ok($c, $m) { global $P, $F; if ($c) { $P++; } else { $F++; echo "  FAIL: $m\n"; } }

$dsn = getenv('ATT_TEST_DSN') ?: 'mysql:host=127.0.0.1;port=3306;dbname=zas_att_test;charset=utf8mb4';
$dbu = getenv('ATT_TEST_USER') ?: 'zas';
$dbp = getenv('ATT_TEST_PASS'); if ($dbp === false) { $dbp = 'zaspw'; }
if (!is_file($B . 'config/config.php')) { echo "SKIP: no test config — run tests/run_att.sh\n"; exit(0); }
foreach (['Db','Time','Migrations','Policy','Audit','Roles','Employees','Shifts','Receipts','AttendanceService','HodService','Reconciler',
          'SyncService','OvertimeService','ReportService','HrService','AdminService'] as $c) {
    require_once $B . "includes/att/$c.php";
}
use Att\AdminService;
use Att\AttendanceService as A;
use Att\Db;
use Att\Employees;
use Att\HrService;
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
$pdo->exec("CREATE TABLE users (id INT AUTO_INCREMENT PRIMARY KEY, name VARCHAR(150) NOT NULL,
            email VARCHAR(190) NOT NULL UNIQUE, password_hash VARCHAR(255) NOT NULL,
            role VARCHAR(40) NOT NULL DEFAULT 'colleague', department VARCHAR(60) NULL,
            can_see_rates TINYINT(1) NOT NULL DEFAULT 0, is_active TINYINT(1) NOT NULL DEFAULT 1)");
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
$gk  = $mk('Gul Gatekeeper', 'gate@zas.test');
$hod = $mk('Hina Hod', 'hod@zas.test');
$hr  = $mk('Rana HR', 'hr@zas.test');
$dS = Employees::createDepartment('Stitching');
$emp = [];
foreach (range(1, 10) as $i) {
    $c = sprintf('E%03d', $i);
    $emp[$c] = Employees::create(['code' => $c, 'full_name' => "Worker $i", 'department_id' => $dS]);
}
Roles::grant($gk, Roles::GATEKEEPER);
Roles::grant($hod, Roles::HOD, $dS);
Roles::grant($hr, Roles::HR);
$day = Shifts::create(['name' => 'Day', 'start_time' => '08:00', 'end_time' => '18:00', 'grace_in_minutes' => 10, 'grace_out_minutes' => 10]);
Policy::set('default_shift_id', (string) $day, null, 'string'); Policy::flush();
const GATE = 'aaaaaaaa-aaaa-4aaa-8aaa-aaaaaaaaaaaa';
S::registerDevice(GATE, 'Main gate', 'gate', $gk);
$seq = 0; $un = 0;
$uuid = function () use (&$un): string { return sprintf('%08x-0000-4000-8000-%012x', random_int(0, 0xffffffff), ++$un); };
/* A scan at $hm UTC. 03:00Z is 08:00 at the factory. */
$scan = function (string $type, string $code, string $hm, string $date = '2026-03-02') use (&$seq, $uuid, $gk): array {
    return S::process(['event_uuid' => $uuid(), 'device_id' => GATE, 'device_sequence' => ++$seq, 'employee_code' => $code, 'event_type' => $type,
        'occurred_at' => "{$date}T{$hm}:00Z", 'timezone' => 'UTC', 'payload' => []], $gk, true);
};
$at = fn(string $iso) => new DateTimeImmutable($iso);
$cyc = fn(string $code) => Db::one('SELECT * FROM att_entry_cycles WHERE employee_id = ? ORDER BY id DESC LIMIT 1', [$emp[$code]]);

echo "1. The setting\n";
ok(Policy::bool('auto_out_enabled') === true && Policy::int('auto_out_after_minutes') === 240, 'on by default, waiting 4 hours after shift end');
$keys = array_column(AdminService::settings()['settings'], null, 'key');
ok(isset($keys['auto_out_enabled'], $keys['auto_out_after_minutes']), 'both are on Setup › Settings');
ok((AdminService::saveSettings(['auto_out_after_minutes' => 10], $admin)['ok'] ?? true) === false, 'a waiting time under 30 minutes is refused');

echo "2. The day\n";
$scan('GATE_IN', 'E001', '03:05');                                   // 08:05, forgets to scan out
$scan('GATE_IN', 'E002', '03:00'); $scan('GATE_OUT', 'E002', '12:00'); // scans out at 17:00 — untouched
$scan('GATE_IN', 'E003', '03:00');                                   // approved overtime 120, forgets to scan out
$scan('GATE_IN', 'E004', '03:00');                                   // overtime asked for, not decided
$scan('GATE_IN', 'E005', '12:30');                                   // 17:30 — came late in the day, forgets
$scan('GATE_IN', 'E006', '03:00');                                   // forgets; comes back next morning
$scan('GATE_IN', 'E007', '03:00');                                   // left 19:30 on a phone with no signal
$scan('GATE_IN', 'E008', '03:00');                                   // scans OUT at 23:00, after the system closed it
OT::setToday('2026-03-02');
$rq = OT::createRequest(['request_uuid' => $uuid(), 'department_id' => $dS, 'work_date' => '2026-03-02', 'reason' => 'urgent order',
    'employees' => [['employee_code' => 'E003', 'estimated_minutes' => 120], ['employee_code' => 'E004', 'estimated_minutes' => 60]]], null, $hod);
$lines = array_column($rq['request']['lines'], 'request_employee_id', 'employee_code');
OT::decide((int) $rq['request']['id'], $uuid(), [['request_employee_id' => (int) $lines['E003'], 'decision' => 'approve']], $hr);
OT::setToday(null);
ok($n('SELECT COUNT(*) FROM att_entry_cycles WHERE closed_at IS NULL') === 7, 'seven people inside at the end of the day');

echo "3. Before the waiting time is over: nothing\n";
$r = A::autoCloseDue($at('2026-03-02T16:59:00Z'));                     // 21:59
ok($r['closed'] === 0 && $n('SELECT COUNT(*) FROM att_entry_cycles WHERE closed_at IS NULL') === 7, 'at 21:59 nobody is closed');

echo "4. At 22:01\n";
$r = A::autoCloseDue($at('2026-03-02T17:01:00Z'));
ok($r['closed'] === 6, "six closed ({$r['closed']}): everyone without approved overtime");
$c1 = $cyc('E001');
ok(str_starts_with((string) $c1['closed_at'], '2026-03-02 13:00:00') && (int) $c1['present_minutes'] === 595, 'E001 CLOSED AT 18:00 (THE SHIFT END), 595 minutes — not at 22:00');
ok($c1['close_source'] === 'auto_shift_end' && str_starts_with((string) $c1['auto_closed_at'], '2026-03-02 17:01'), '  marked as closed by the system, with when it did it');
ok($c1['gate_out_event_id'] === null && $n("SELECT COUNT(*) FROM att_events WHERE event_type = 'GATE_OUT' AND employee_id = ?", [$emp['E001']]) === 0, '  no gate scan was invented');
ok($n("SELECT COUNT(*) FROM att_audit_logs WHERE action = 'gate.auto_out' AND subject_id = ?", [(int) $c1['id']]) === 1, '  and the audit log says so');
ok($cyc('E002')['close_source'] === 'gate' && str_starts_with((string) $cyc('E002')['closed_at'], '2026-03-02 12:00'), 'E002, who scanned out at 17:00, is untouched');
ok($cyc('E003')['closed_at'] === null, 'E003 WITH APPROVED OVERTIME (2 h) is still open: the wait runs from 20:00');
ok(str_starts_with((string) $cyc('E004')['closed_at'], '2026-03-02 13:00') && $cyc('E004')['close_source'] === 'auto_shift_end', 'E004, overtime asked for but NOT approved, closed at 18:00');
ok(str_starts_with((string) $cyc('E005')['closed_at'], '2026-03-02 13:00') && (int) $cyc('E005')['present_minutes'] === 30, 'E005 came at 17:30: closed at 18:00, 30 minutes');
ok(A::autoOutTimes(['ends_at' => '2026-03-02 13:00:00', 'opened_at' => '2026-03-02 14:00:00', 'employee_id' => $emp['E010'], 'work_date' => '2026-03-02'])[0]->format('H:i') === '14:00',
   'someone in only after the shift ended would be closed at the moment they came in, with no minutes — overtime is never made up');
ok(A::autoCloseDue($at('2026-03-02T17:30:00Z'))['closed'] === 0, 'running again closes nothing twice');

echo "5. Approved overtime moves the time\n";
ok(A::autoCloseDue($at('2026-03-02T18:59:00Z'))['closed'] === 0, 'E003 still open at 23:59');
A::autoCloseDue($at('2026-03-02T19:01:00Z'));
$c3 = $cyc('E003');
ok(str_starts_with((string) $c3['closed_at'], '2026-03-02 15:00') && (int) $c3['present_minutes'] === 720, 'E003 closed at 20:00 = shift end + the 2 approved hours (00:01)');

echo "6. A real OUT that arrives late\n";
$o7 = $scan('GATE_OUT', 'E007', '14:30');                            // 19:30, sent at 22:40 when the signal came back
$c7 = $cyc('E007');
ok(($o7['receipt_status'] ?? '') === 'ACCEPTED' && !empty($o7['replaced_auto_out']), 'an OUT SCANNED AT 19:30 on a phone with no signal is accepted when it arrives');
ok(str_starts_with((string) $c7['closed_at'], '2026-03-02 14:30') && $c7['close_source'] === 'gate' && (int) $c7['present_minutes'] === 690 && $c7['gate_out_event_id'] !== null,
   '  and the real time replaces the system\'s: 19:30, by the gate, 690 minutes');
ok($c7['auto_closed_at'] !== null, '  the record still shows the system had closed it first');
$o8 = $scan('GATE_OUT', 'E008', '18:00');                            // 23:00 — after the system had closed it
ok(($o8['receipt_status'] ?? '') === 'REJECTED' && $o8['reason_code'] === 'NOT_INSIDE' && str_contains((string) $o8['message'], 'system closed'),
   'an OUT scanned at 23:00, AFTER the system closed the entry, is refused and says why');
ok($cyc('E008')['close_source'] === 'auto_shift_end', '  E008 stays closed at 18:00');

echo "6b. Two INs replayed late from two phones\n";
$scan('GATE_IN', 'E010', '03:00', '2026-03-05');                     // 08:00 on phone A
$dbl = $scan('GATE_IN', 'E010', '05:00', '2026-03-05');              // 10:00 on phone B — both sent days later
ok(($dbl['receipt_status'] ?? '') === 'REJECTED' && $dbl['reason_code'] === 'ALREADY_INSIDE' && $cyc('E010')['closed_at'] === null,
   'A SECOND IN SCANNED DURING THE SHIFT IS STILL "ALREADY INSIDE" — the rule never closes an entry early to let a double IN through');
$pdo->exec('DELETE FROM att_entry_cycles WHERE employee_id = ' . (int) $emp['E010']);
$pdo->exec("UPDATE att_events SET reconciliation_status = 'rejected' WHERE employee_id = " . (int) $emp['E010']);

echo "7. Next morning\n";
$pdo->exec("UPDATE att_entry_cycles SET closed_at = NULL, present_minutes = NULL, close_source = 'gate', auto_closed_at = NULL WHERE id = " . (int) $cyc('E006')['id']);
ok($cyc('E006')['closed_at'] === null, '(E006 put back to "still inside", as if the rule had not run overnight)');
$in6 = $scan('GATE_IN', 'E006', '03:00', '2026-03-03');
$prev6 = Db::one('SELECT * FROM att_entry_cycles WHERE employee_id = ? ORDER BY id LIMIT 1', [$emp['E006']]);
ok(($in6['receipt_status'] ?? '') === 'ACCEPTED', 'E006\'S IN NEXT MORNING IS ACCEPTED, not refused as "already inside"');
ok($prev6['close_source'] === 'auto_shift_end' && str_starts_with((string) $prev6['closed_at'], '2026-03-02 13:00'), '  yesterday\'s entry was closed at 18:00 by the rule, on the spot');
$s = Employees::search('E001');
ok((int) $s[0]['inside'] === 0 && str_starts_with((string) $s[0]['last_auto_out_at'], '2026-03-02 13:00'), 'the gate lookup tells the phone E001 is outside and his last exit was the system\'s');
ok((int) Employees::search('E006')[0]['inside'] === 1, '  E006 is inside again (the phone shows the note only for someone outside)');

echo "8. Reports\n";
R::setNow('2026-03-03T04:00:00Z');                                   // 09:00 next morning
$d = R::dashboard(null);
$codes = array_column($d['auto_out'], 'employee_code');
sort($codes);
ok($d['auto_out_count'] === 6 && $codes === ['E001', 'E003', 'E004', 'E005', 'E006', 'E008'], 'Reports › Today next morning lists everyone not scanned out (' . implode(',', $codes) . ')');
$hodDash = R::dashboard([$dS]);
ok($hodDash['auto_out_count'] === 6, '  the HOD sees their own people there too');
ok(R::dashboard([999])['auto_out_count'] === 0, '  and a HOD of another department sees none of them');
$att = R::attendance(['from' => '2026-03-02', 'to' => '2026-03-02'], null);
$row = array_column($att['rows'], null, 'employee_code');
ok($row['E001']['auto_out'] === 1 && $row['E002']['auto_out'] === 0 && $att['totals']['auto_out'] === 6, 'the attendance report marks the days not scanned out (' . $att['totals']['auto_out'] . ')');
ok($row['E001']['early_minutes'] === 0 && $row['E001']['present_minutes'] === 595, '  E001: present to 18:00, not "left early"');
$mv = R::movements(['from' => '2026-03-02', 'to' => '2026-03-02', 'employee' => 'E001'], null);
$types = array_column($mv['rows'], 'event_type');
ok($types === ['GATE_IN', 'AUTO_OUT'] && $mv['rows'][1]['recorded_by'] === 'System — no OUT scanned', 'movements show E001\'s OUT as the system\'s, not a scan');
R::setNow(null);

echo "9. HR puts in the real time\n";
// The HR list covers the last 7 days by the real clock; this test day is in March.
$pdo->exec("UPDATE att_entry_cycles SET auto_closed_at = UTC_TIMESTAMP(6) WHERE close_source = 'auto_shift_end'");
$q = HrService::queue();
ok(count($q['auto_closed']) === 6, 'HR Reconciliation lists the six system OUTs');
$fix = HrService::closeCycle((int) $c1['id'], $at('2026-03-02T13:30:00Z'), 'He left at 18:30, guard confirmed', $hr);
$c1b = $cyc('E001');
ok(($fix['ok'] ?? false) && $c1b['close_source'] === 'hr_correction' && (int) $c1b['present_minutes'] === 625, 'HR SETS E001\'S REAL EXIT, 18:30: kept as a correction, 625 minutes');
ok($n("SELECT COUNT(*) FROM att_corrections WHERE action = 'cycle.retime_auto_out' AND subject_id = ?", [(int) $c1['id']]) === 1, '  with the system\'s time kept as the "before"');
ok((HrService::closeCycle((int) $cyc('E002')['id'], $at('2026-03-02T13:30:00Z'), 'x', $hr)['reason_code'] ?? '') === 'ALREADY_CLOSED', 'an entry closed by a real scan cannot be re-timed this way');

echo "10. Switched off\n";
$scan('GATE_IN', 'E009', '03:00');
Policy::set('auto_out_enabled', '0', $admin, 'bool'); Policy::flush();
ok(A::autoCloseDue($at('2026-03-02T23:00:00Z'))['closed'] === 0 && $cyc('E009')['closed_at'] === null, 'with the setting off nobody is closed');
Policy::set('auto_out_enabled', '1', $admin, 'bool'); Policy::flush();

echo "11. Runs by itself\n";
$pdo->exec("DELETE FROM att_policies WHERE policy_key = 'auto_out_last_run'");
$first = A::autoCloseIfDue();
ok($first !== null && $first['closed'] === 2 && $cyc('E009')['close_source'] === 'auto_shift_end' && $cyc('E006')['close_source'] === 'auto_shift_end',
   'the first phone sync of the day runs it (E009, and E006\'s second day, closed)');
ok(A::autoCloseIfDue() === null, '  and it waits five minutes before running again');

echo "12. Undo\n";
$mig->down(14);
ok($n("SELECT COUNT(*) FROM information_schema.columns WHERE table_schema = DATABASE() AND table_name = 'att_entry_cycles' AND column_name = 'auto_closed_at'") === 0
   && $n('SELECT COUNT(*) FROM att_entry_cycles WHERE closed_at IS NULL') === 0, 'the database step undoes cleanly; closed entries stay closed');
$mig->up();
ok($n("SELECT COUNT(*) FROM information_schema.columns WHERE table_schema = DATABASE() AND table_name = 'att_entry_cycles' AND column_name = 'auto_closed_at'") === 1, '  and applies again');

echo "13. The morning's first lookup, before anyone has scanned\n";
$pdo->prepare("INSERT INTO zu_perm (user_id, module, actions) VALUES (?, 'attgate', 'vc')")->execute([$gk]);
$scan('GATE_IN', 'E010', '03:00');                                   // 2 March, never scanned out; nothing has run since
$pdo->exec("UPDATE att_policies SET value = '' WHERE policy_key = 'auto_out_last_run'");
ok($cyc('E010')['closed_at'] === null, '(E010 still open from 2 March, the rule has not run)');
$port = 8736;
$srv = proc_open(sprintf('exec php -S 127.0.0.1:%d -t %s', $port, escapeshellarg(rtrim($B, '/'))), [1 => ['file', '/dev/null', 'a'], 2 => ['file', '/dev/null', 'a']], $pipes);
register_shutdown_function(static function () use ($srv) { if (is_resource($srv)) { proc_terminate($srv, 9); proc_close($srv); } });
for ($i = 0; $i < 60; $i++) { $cc = @fsockopen('127.0.0.1', $port, $e1, $e2, 0.2); if ($cc) { fclose($cc); break; } usleep(200000); }
$jar = tempnam(sys_get_temp_dir(), 'attao');
$ch = curl_init("http://127.0.0.1:$port/api/v1/index.php?route=%2Fauth%2Flogin");
curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_COOKIEJAR => $jar, CURLOPT_COOKIEFILE => $jar, CURLOPT_POST => true,
    CURLOPT_POSTFIELDS => json_encode(['email' => 'gate@zas.test', 'password' => 'CorrectHorse9']), CURLOPT_HTTPHEADER => ['Content-Type: application/json']]);
curl_exec($ch); curl_close($ch);
$ch = curl_init("http://127.0.0.1:$port/api/v1/index.php?route=%2Femployees&query=E010");
curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_COOKIEFILE => $jar]);
$look = json_decode((string) curl_exec($ch), true); curl_close($ch);
$e10 = array_values(array_filter($look['employees'] ?? [], fn($e) => $e['code'] === 'E010'))[0] ?? null;
ok($e10 !== null && (int) $e10['inside'] === 0 && $e10['last_auto_out_at'] !== null,
   'THE GATE TYPES E010 NEXT MORNING: the server answers "outside" — so the phone shows green IN — and that the system closed his last exit');
ok($cyc('E010')['close_source'] === 'auto_shift_end', '  the lookup itself ran the rule');

echo "\n$P passed, $F failed\n";
exit($F === 0 ? 0 : 1);
