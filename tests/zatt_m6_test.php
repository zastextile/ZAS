<?php
declare(strict_types=1);

/* ATTENDANCE M6 — OVERTIME
 *
 *   AT08  An unapproved late checkout never creates overtime.
 *   AT09  One officer's approval is authoritative; a concurrent approval race
 *         is safe.
 *   AT10  Next-day actual minutes per employee; more than approved needs an
 *         amendment.
 *   AT11  Manual overtime rate, payroll lock, and audit.
 *
 * The service on its own first, then a real race between two processes, then
 * the same rules over HTTP with the ERP's own session and permission code.
 */

$B = __DIR__ . '/app_src/public_html/';
$P = 0; $F = 0;
function ok($c, $m) { global $P, $F; if ($c) { $P++; } else { $F++; echo "  FAIL: $m\n"; } }

$dsn = getenv('ATT_TEST_DSN') ?: 'mysql:host=127.0.0.1;port=3306;dbname=zas_att_test;charset=utf8mb4';
$dbu = getenv('ATT_TEST_USER') ?: 'zas';
$dbp = getenv('ATT_TEST_PASS'); if ($dbp === false) { $dbp = 'zaspw'; }
if (!is_file($B . 'config/config.php')) { echo "SKIP: no test config — run tests/run_att.sh\n"; exit(0); }

foreach (['Db','Time','Migrations','Policy','Audit','Roles','Employees','Shifts','Receipts','AttendanceService','HodService','Reconciler',
          'SyncService','OvertimeService'] as $c) {
    require_once $B . "includes/att/$c.php";
}
use Att\Db;
use Att\Employees;
use Att\Migrations;
use Att\OvertimeService as OT;
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
$hod   = $mk('Hina Hod', 'hod@zas.test');
$hodP  = $mk('Pervez Packing HOD', 'hodp@zas.test');
$hr    = $mk('Rana HR', 'hr@zas.test');
$acc   = $mk('Asma Accounts', 'acc@zas.test');
$gk    = $mk('Gul Gatekeeper', 'gate@zas.test');

$dS = Employees::createDepartment('Stitching');
$dP = Employees::createDepartment('Packing');
$emp = [];
foreach ([['E001','Imran Ali',$dS],['E002','Bilal Ahmed',$dS],['E003','Nadia Noor',$dS],['E004','Asad Mir',$dS],['E005','Sara Khan',$dP],['E006','Old Hand',$dS]] as [$c, $nm, $d]) {
    $emp[$c] = Employees::create(['code' => $c, 'full_name' => $nm, 'department_id' => $d]);
}
Employees::deactivate($emp['E006'], $admin, 'left');
Roles::grant($hod, Roles::HOD, $dS);
Roles::grant($hodP, Roles::HOD, $dP);
Roles::grant($hr, Roles::HR);
Roles::grant($acc, Roles::ACCOUNTS);
Roles::grant($gk, Roles::GATEKEEPER);
$day = Shifts::create(['name' => 'Day', 'start_time' => '09:00', 'end_time' => '17:00', 'grace_in_minutes' => 30, 'grace_out_minutes' => 30]);
Policy::set('default_shift_id', (string) $day, null, 'string'); Policy::flush();
const GATE = 'aaaaaaaa-aaaa-4aaa-8aaa-aaaaaaaaaaaa';
S::registerDevice(GATE, 'Gate A', 'gate', $gk);

$un = 0;
$uuid = function () use (&$un): string { return sprintf('%08x-0000-4000-8000-%012x', random_int(0, 0xffffffff), ++$un); };
$seq = 0;
/** A gate scan on $date at $hm UTC (04:00Z is 09:00 at the factory). */
$scan = function (string $type, string $code, string $date, string $hm) use (&$seq, $uuid, $gk): array {
    return S::process(['event_uuid' => $uuid(), 'device_id' => GATE, 'device_sequence' => ++$seq, 'employee_code' => $code,
        'event_type' => $type, 'occurred_at' => "{$date}T{$hm}:00Z", 'timezone' => 'UTC', 'payload' => []], $gk, true);
};
$req = function (array $over = []) use ($uuid, $dS, $emp): array {
    return array_merge(['request_uuid' => $uuid(), 'department_id' => $dS, 'work_date' => '2026-03-02', 'reason' => 'Export order 4471 must ship Friday',
        'planned_start' => '17:00', 'planned_end' => '20:00',
        'employees' => [['employee_id' => $emp['E001'], 'estimated_minutes' => 120], ['employee_code' => 'E002', 'estimated_minutes' => 90]]], $over);
};
$line = fn(int $rid, string $code) => (int) Db::value('SELECT l.id FROM att_ot_request_employees l JOIN att_employees e ON e.id = l.employee_id WHERE l.request_id = ? AND e.code = ?', [$rid, $code]);
$scopeS = [$dS];

OT::setToday('2026-03-02');

/* ===================================================================== */
echo "1. A HOD asks for overtime\n";
ok((OT::createRequest($req(['request_uuid' => 'nope']), $scopeS, $hod)['reason_code'] ?? '') === 'VALIDATION', 'a request with no valid id is refused');
ok((OT::createRequest($req(['department_id' => $dP]), $scopeS, $hod)['reason_code'] ?? '') === 'VALIDATION', 'A HOD CANNOT ASK FOR ANOTHER DEPARTMENT');
ok((OT::createRequest($req(['employees' => [['employee_code' => 'E005', 'estimated_minutes' => 60]]]), $scopeS, $hod)['reason_code'] ?? '') === 'EMPLOYEE_NOT_FOUND',
   'nor for an employee of another department inside their own request');
ok((OT::createRequest($req(['employees' => [['employee_code' => 'E006', 'estimated_minutes' => 60]]]), $scopeS, $hod)['reason_code'] ?? '') === 'EMPLOYEE_INACTIVE', 'an inactive employee is refused');
ok((OT::createRequest($req(['reason' => '  ']), $scopeS, $hod)['reason_code'] ?? '') === 'VALIDATION', 'a reason is required');
ok((OT::createRequest($req(['work_date' => '2026-02-27']), $scopeS, $hod)['reason_code'] ?? '') === 'OUTSIDE_WINDOW', 'three days back is outside the window (1 day by default)');
ok((OT::createRequest($req(['work_date' => '2026-04-30']), $scopeS, $hod)['reason_code'] ?? '') === 'OUTSIDE_WINDOW', 'two months ahead is outside the window (14 days)');
ok((OT::createRequest($req(['work_date' => '2026-02-30']), $scopeS, $hod)['reason_code'] ?? '') === 'VALIDATION', '30 February is not a date');
ok((OT::createRequest($req(['planned_start' => '17:00', 'planned_end' => '']), $scopeS, $hod)['reason_code'] ?? '') === 'VALIDATION', 'a planned start needs a planned end');
ok((OT::createRequest($req(['employees' => [['employee_id' => $emp['E001'], 'estimated_minutes' => 0]]]), $scopeS, $hod)['reason_code'] ?? '') === 'VALIDATION', '0 estimated minutes is refused');
ok((OT::createRequest($req(['employees' => [['employee_id' => $emp['E001'], 'estimated_minutes' => 800]]]), $scopeS, $hod)['reason_code'] ?? '') === 'VALIDATION', 'more than 720 minutes is refused');
ok((OT::createRequest($req(['employees' => [['employee_id' => $emp['E001'], 'estimated_minutes' => 60], ['employee_code' => 'E001', 'estimated_minutes' => 30]]]), $scopeS, $hod)['reason_code'] ?? '') === 'VALIDATION',
   'the same employee twice in one request is refused');
ok($n('SELECT COUNT(*) FROM att_ot_requests') === 0, 'none of those wrote anything');

$body = $req();
$r1 = OT::createRequest($body, $scopeS, $hod);
$R1 = (int) ($r1['request']['id'] ?? 0);
ok($r1['ok'] && $r1['already'] === false && count($r1['request']['lines']) === 2 && $r1['request']['pending'] === 2, 'a request for two employees is recorded, both pending');
ok($r1['request']['planned_start'] === '17:00:00' && $r1['request']['requested_by_name'] === 'Hina Hod', '  with the planned window and who asked');
$again = OT::createRequest($body, $scopeS, $hod);
ok($again['ok'] && $again['already'] === true && (int) $again['request']['id'] === $R1 && $n('SELECT COUNT(*) FROM att_ot_requests') === 1, 'THE SAME REQUEST SENT TWICE IS ONE REQUEST');
$dup = OT::createRequest($req(['employees' => [['employee_code' => 'E001', 'estimated_minutes' => 30]]]), $scopeS, $hod);
ok(($dup['reason_code'] ?? '') === 'ALREADY_REQUESTED' && str_contains($dup['error'], 'Imran Ali'), 'A SECOND REQUEST FOR THE SAME EMPLOYEE AND DAY IS REFUSED, by name');
/* The database keeps the rule even if the check above were skipped. */
try {
    $pdo->prepare("INSERT INTO att_ot_request_employees (request_id, employee_id, work_date, estimated_minutes) VALUES (?, ?, '2026-03-02', 10)")->execute([$R1, $emp['E001']]);
    $raced = false;
} catch (PDOException $e) { $raced = true; }
ok($raced, '  and the database refuses it too, should two requests race');
ok(count(OT::requests(['status' => 'open'], $scopeS)['requests']) === 1 && OT::requests([], [$dP])['requests'] === [], 'the HOD sees it; the Packing HOD does not');

/* ===================================================================== */
echo "2. AT08 — staying late is not overtime\n";
/* E003 has no request. E004's request is never approved. Both stay until 22:00. */
$scan('GATE_IN', 'E003', '2026-03-02', '04:00'); $scan('GATE_OUT', 'E003', '2026-03-02', '17:00');
$scan('GATE_IN', 'E001', '2026-03-02', '04:00'); $scan('GATE_OUT', 'E001', '2026-03-02', '15:00');    // 17:00 → 20:00 local = 3 hours after the shift
$r2 = OT::createRequest($req(['employees' => [['employee_code' => 'E004', 'estimated_minutes' => 60]]]), $scopeS, $hod);
$R2 = (int) $r2['request']['id']; $L4 = $line($R2, 'E004');
$scan('GATE_IN', 'E004', '2026-03-02', '04:00'); $scan('GATE_OUT', 'E004', '2026-03-02', '17:00');
OT::setToday('2026-03-03');
$ver = OT::verification(null, null);
$codes = array_column($ver['lines'], 'employee_code');
ok(!in_array('E003', $codes, true), 'E003 STAYED FIVE HOURS LATE WITH NO REQUEST: NOTHING TO VERIFY, NOTHING OWED');
ok(!in_array('E004', $codes, true), 'E004 stayed late with a request nobody approved: not on the verification list either');
ok((OT::verify($R2, $L4, 60, '', null, $hr, true)['reason_code'] ?? '') === 'NOT_APPROVED', 'and minutes cannot be entered for an unapproved line');
ok($n('SELECT COUNT(*) FROM att_ot_payroll') === 0 && OT::payroll('2026-03-02', '2026-03-02')['total_ready'] === '0.00', 'payroll for the day is empty');
ok($n('SELECT COUNT(*) FROM att_ot_request_employees WHERE actual_minutes IS NOT NULL') === 0, 'no actual minutes anywhere — nothing was filled from the gate');
OT::setToday('2026-03-02');

/* ===================================================================== */
echo "3. One officer decides, each employee on their own\n";
$L1 = $line($R1, 'E001'); $L2 = $line($R1, 'E002');
$bad = OT::decide($R1, 'nope', [['request_employee_id' => $L1, 'decision' => 'approve']], $hr);
ok(($bad['reason_code'] ?? '') === 'VALIDATION', 'a decision with no valid id is refused');
$du = $uuid();
$d1 = OT::decide($R1, $du, [['request_employee_id' => $L1, 'decision' => 'approve', 'remarks' => 'urgent shipment'],
                            ['request_employee_id' => $L2, 'decision' => 'reject', 'remarks' => 'not needed for packing'],
                            ['request_employee_id' => $L4, 'decision' => 'approve']], $hr);
$res = array_column($d1['results'], 'result', 'request_employee_id');
ok($d1['ok'] && $res[$L1] === 'APPROVED' && $res[$L2] === 'REJECTED', 'E001 approved and E002 rejected in one go');
ok($res[$L4] === 'NOT_IN_REQUEST' && Db::value('SELECT approval_status FROM att_ot_request_employees WHERE id = ?', [$L4]) === 'pending',
   'a line from a DIFFERENT request named in the decision is refused and left alone');
$l1 = Db::one('SELECT approval_status, approved_minutes, decided_by, decision_note FROM att_ot_request_employees WHERE id = ?', [$L1]);
ok($l1['approved_minutes'] === 120 && (int) $l1['decided_by'] === $hr && $l1['decision_note'] === 'urgent shipment', 'approved for the estimated 120 minutes, under HR\'s name, with the remark');
$rep = OT::decide($R1, $du, [['request_employee_id' => $L1, 'decision' => 'reject']], $hr);
ok(($rep['replayed'] ?? false) === true && array_column($rep['results'], 'result', 'request_employee_id')[$L1] === 'APPROVED'
   && Db::value('SELECT approval_status FROM att_ot_request_employees WHERE id = ?', [$L1]) === 'approved', 'THE SAME DECISION SENT AGAIN RETURNS THE FIRST ANSWER and changes nothing');
$late = OT::decide($R1, $uuid(), [['request_employee_id' => $L1, 'decision' => 'reject']], $acc);
$lr = $late['results'][0];
ok($lr['result'] === 'ALREADY_DECIDED' && str_contains($lr['message'], 'Rana HR') && Db::value('SELECT approval_status FROM att_ot_request_employees WHERE id = ?', [$L1]) === 'approved',
   'A SECOND OFFICER IS TOLD IT WAS ALREADY APPROVED BY RANA HR — the first decision stands');
ok($n("SELECT COUNT(*) FROM att_ot_history WHERE line_id = ? AND kind = 'decision'", [$L1]) === 1 && $n("SELECT COUNT(*) FROM att_audit_logs WHERE action IN ('ot.approve','ot.reject')") === 2,
   'one decision in the line history; two in the audit log (one per employee)');
Policy::set('ot_decision_window_days', '1', null, 'int'); Policy::flush();
OT::setToday('2026-03-05');
ok((OT::decide($R2, $uuid(), [['request_employee_id' => $L4, 'decision' => 'approve']], $hr)['reason_code'] ?? '') === 'OUTSIDE_WINDOW', 'with a 1-day decision window, deciding three days later is refused');
Policy::set('ot_decision_window_days', '0', null, 'int'); Policy::flush();
OT::setToday('2026-03-02');

/* ===================================================================== */
echo "4. AT09 — two officers decide the same employee at the same moment\n";
$r3 = OT::createRequest($req(['employees' => [['employee_code' => 'E003', 'estimated_minutes' => 60]], 'work_date' => '2026-03-03']), $scopeS, $hod);
$R3 = (int) $r3['request']['id']; $L3 = $line($R3, 'E003');
$runner = sys_get_temp_dir() . '/att_ot_race_' . getmypid() . '.php';
file_put_contents($runner, <<<'PHP'
<?php
[$_, $B, $req, $lineId, $who, $decision, $dsn, $u, $p, $at] = $argv;
foreach (['Db','Time','Policy','Audit','Roles','Employees','Receipts','OvertimeService'] as $c) { require_once "$B/includes/att/$c.php"; }
Att\Db::bind(new PDO($dsn, $u, $p, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC, PDO::ATTR_EMULATE_PREPARES => false]));
Att\Db::useUtc();
Att\OvertimeService::setToday('2026-03-02');
while (microtime(true) < (float) $at) { usleep(500); }
$id = sprintf('%08x-1111-4111-8111-%012x', random_int(0, 0xffffffff), random_int(0, 0xffffffff));
try {
    $r = Att\OvertimeService::decide((int) $req, $id, [['request_employee_id' => (int) $lineId, 'decision' => $decision]], (int) $who);
    echo json_encode(['result' => $r['results'][0]['result'] ?? ($r['reason_code'] ?? '?')]);
} catch (Throwable $e) { echo json_encode(['result' => 'ERROR', 'error' => $e->getMessage()]); }
PHP);
$start = microtime(true) + 1.5;
$procs = []; $pipes = [];
foreach ([[$hr, 'approve'], [$acc, 'reject']] as $i => [$who, $dec]) {
    $procs[$i] = proc_open(sprintf('exec php %s %s %d %d %d %s %s %s %s %.6f', escapeshellarg($runner), escapeshellarg(rtrim($B, '/')), $R3, $L3, $who, $dec,
        escapeshellarg($dsn), escapeshellarg($dbu), escapeshellarg($dbp), $start), [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes[$i]);
}
$out = [];
foreach ($procs as $i => $proc) {
    $out[$i] = json_decode(trim((string) stream_get_contents($pipes[$i][1])), true) ?: ['result' => 'NO OUTPUT: ' . stream_get_contents($pipes[$i][2])];
    fclose($pipes[$i][1]); fclose($pipes[$i][2]); proc_close($proc);
}
@unlink($runner);
$got = array_column($out, 'result'); sort($got);
ok(count(array_intersect($got, ['APPROVED', 'REJECTED'])) === 1 && in_array('ALREADY_DECIDED', $got, true),
   'AFTER A GENUINE RACE EXACTLY ONE DECISION WON; the other was told ALREADY_DECIDED, not an error (' . implode(' / ', $got) . ')');
$w = Db::one('SELECT approval_status, decided_by FROM att_ot_request_employees WHERE id = ?', [$L3]);
ok(($w['approval_status'] === 'approved' && (int) $w['decided_by'] === $hr) || ($w['approval_status'] === 'rejected' && (int) $w['decided_by'] === $acc),
   'the stored decision is the winner\'s, under the winner\'s name');
ok($n("SELECT COUNT(*) FROM att_ot_history WHERE line_id = ? AND kind = 'decision'", [$L3]) === 1, 'and there is exactly one decision in its history');
if ($w['approval_status'] === 'rejected') {      // make the rest of the test independent of who won
    OT::amend($R3, $L3, 'decision', 'approve', 'race test: continue with an approved line', $admin);
}

/* ===================================================================== */
echo "5. AT10 — next-day actual minutes\n";
$tooEarly = OT::verify($R1, $L1, 100, '', null, $hr, true);
ok(($tooEarly['reason_code'] ?? '') === 'TOO_EARLY', 'ON THE WORK DATE ITSELF THE ACTUAL MINUTES CANNOT BE ENTERED');
OT::setToday('2026-03-03');
$v = OT::verification(null, null);
$row = array_values(array_filter($v['lines'], fn($l) => (int) $l['request_employee_id'] === $L1))[0];
ok($row['can_verify_now'] === true && $row['actual_minutes'] === null, 'the next day E001 is on the list, with the actual minutes still EMPTY');
ok($row['movement']['minutes_inside_after_shift'] === 180 && $row['movement']['minutes_inside_planned'] === 180 && count($row['movement']['entries']) === 1,
   'beside it, the gate history: 180 minutes inside after the shift, 180 inside the planned window');
ok(str_contains($row['movement']['note'], 'not overtime unless a person verifies'), '  marked as for checking only');
ok((OT::verify($R1, $L1, 'ninety', '', null, $hr, true)['reason_code'] ?? '') === 'VALIDATION', '"ninety" is not a number');
ok((OT::verify($R1, $L1, 100, '', null, $hr, false)['reason_code'] ?? '') === 'FORBIDDEN', 'a gatekeeper without the setting switched on cannot enter minutes');
$ex = OT::verify($R1, $L1, 180, 'worked till 8', null, $hr, true);
ok(($ex['reason_code'] ?? '') === 'EXCESS_REQUIRES_AMENDMENT' && str_contains($ex['error'], '120 approved'), '180 MINUTES AGAINST 120 APPROVED IS REFUSED: EXCESS NEEDS AN AMENDMENT');
ok((OT::amend($R1, $L1, 'approved_minutes', 180, '', $hr)['reason_code'] ?? '') === 'REASON_REQUIRED', 'an amendment without a reason is refused');
$am = OT::amend($R1, $L1, 'approved_minutes', 180, 'Supervisor confirms the container came late; 3 hours agreed', $hr);
ok($am['ok'] && $am['old']['approved_minutes'] === 120 && $am['new']['approved_minutes'] === 180, 'amended from 120 to 180 approved, with the reason');
$vv = OT::verify($R1, $L1, 175, 'gate shows 20:00 exit', ['gate log 2026-03-02', 'supervisor sheet 17'], $hr);
ok($vv['ok'] && $vv['actual_minutes'] === 175, 'then 175 actual minutes are accepted');
ok(json_decode((string) Db::value('SELECT evidence_refs FROM att_ot_request_employees WHERE id = ?', [$L1]), true) === ['gate log 2026-03-02', 'supervisor sheet 17'], '  with the evidence references kept');
ok((OT::verify($R1, $L1, 170, '', null, $hr, true)['reason_code'] ?? '') === 'ALREADY_VERIFIED', 'entering it a second time is refused — a change is an amendment');
$h = OT::history($L1)['history'];
ok(array_column($h, 'kind') === ['request', 'decision', 'amendment', 'verification'], 'the line\'s history reads: request, decision, amendment, verification');
ok($h[2]['remarks'] === 'Supervisor confirms the container came late; 3 hours agreed' && $h[2]['actor_name'] === 'Rana HR', '  each with who and why');

// dispute, then settle it by amendment
$r4 = OT::createRequest($req(['employees' => [['employee_code' => 'E002', 'estimated_minutes' => 60]], 'work_date' => '2026-03-03', 'planned_start' => '', 'planned_end' => '']), $scopeS, $hod);
OT::setToday('2026-03-03');
$R4 = (int) $r4['request']['id']; $L5 = $line($R4, 'E002');
OT::decide($R4, $uuid(), [['request_employee_id' => $L5, 'decision' => 'approve']], $acc);
OT::setToday('2026-03-04');
ok((OT::dispute($R4, $L5, '', $hr, true)['reason_code'] ?? '') === 'REASON_REQUIRED', 'a dispute needs a note');
ok(OT::dispute($R4, $L5, 'No gate record for E002 that evening — HOD says he stayed', $hr, true)['ok'], 'the verifier disputes it instead of guessing');
ok((OT::verify($R4, $L5, 60, '', null, $hr, true)['ok'] ?? false) === true, 'a disputed line can still be verified once it is clear');
OT::amend($R4, $L5, 'actual_minutes', 45, 'HOD sheet shows 45 minutes', $hr);
ok((int) Db::value('SELECT actual_minutes FROM att_ot_request_employees WHERE id = ?', [$L5]) === 45, 'and its actual minutes amended to 45 afterwards');
ok((OT::amend($R4, $L5, 'actual_minutes', 90, 'more', $hr)['reason_code'] ?? '') === 'VALIDATION', 'an amended actual above the approved 60 is refused too');

/* ===================================================================== */
echo "6. AT11 — the rate is typed, never worked out\n";
ok((OT::setRate($L4, 'hourly', '200', $acc)['reason_code'] ?? '') === 'NOT_VERIFIED', 'no rate for a line that is not verified');
foreach (['abc', '-5', '1e3', '0', '0.00', '12.345', ''] as $bad) {
    ok((OT::setRate($L1, 'hourly', $bad, $acc)['reason_code'] ?? '') === 'VALIDATION', "\"$bad\" is not a rate");
}
ok((OT::setRate($L1, 'daily', '200', $acc)['reason_code'] ?? '') === 'VALIDATION', 'a rate type other than hourly or fixed is refused');
$rt = OT::setRate($L1, 'hourly', '250.50', $acc);
ok($rt['ok'] && $rt['payable_minutes'] === 175 && $rt['payable_amount'] === '730.63', 'hourly 250.50 × 175 min ÷ 60 = 730.625 → 730.63, worked in whole paisa');
ok(OT::amount('hourly', 25050, 95) === 39663 && OT::amount('hourly', 1, 30) === 1 && OT::amount('hourly', 1, 29) === 0 && OT::amount('fixed', 150000, 30) === 150000,
   'rounding: half a paisa rounds up, less than half rounds down; fixed pays the amount whatever the minutes');
$fx = OT::setRate($L5, 'fixed', '1500', $acc);
ok($fx['ok'] && $fx['payable_amount'] === '1500.00', 'a fixed rate pays the amount typed');
$cols = array_column(Db::all("SELECT COLUMN_NAME AS c FROM information_schema.columns WHERE table_schema = DATABASE() AND table_name LIKE 'att_%'"), 'c');
ok(array_filter($cols, fn($c) => preg_match('/wage|salary|daily_rate|basic_pay/i', $c)) === [], 'THERE IS NO WAGE OR SALARY COLUMN ANYWHERE IN THE MODULE for a rate to be derived from');

/* ===================================================================== */
echo "7. Finalizing payroll\n";
OT::setToday('2026-03-04');
$pv = OT::payroll('2026-03-02', '2026-03-03');
$states = array_column($pv['lines'], 'readiness', 'request_employee_id');
ok($states[$L1] === 'ready' && $states[$L2] === 'rejected' && $states[$L4] === 'undecided' && $states[$L3] === 'unverified' && $states[$L5] === 'ready',
   'the payroll view says what each line still needs: ' . json_encode(array_values($states)));
ok($pv['total_ready'] === '2230.63' && $pv['blocking'] === 2, 'total of the ready lines 2230.63; two lines blocking');
ok((OT::finalize($uuid(), '2026-03-02', '2026-03-04', '0', '', $acc)['reason_code'] ?? '') === 'VALIDATION', 'a period that includes today cannot be finalized');
$nr = OT::finalize($uuid(), '2026-03-02', '2026-03-03', '2230.63', '', $acc);
ok(($nr['reason_code'] ?? '') === 'NOT_READY' && count($nr['blocking']) === 2, 'WITH AN UNDECIDED AND AN UNVERIFIED LINE, THE PERIOD IS NOT FINALIZED — they are listed');
OT::decide($R2, $uuid(), [['request_employee_id' => $L4, 'decision' => 'reject', 'remarks' => 'not approved in time']], $hr);
OT::verify($R3, $L3, 0, 'did not stay', null, $hr);
ok(OT::payroll('2026-03-02', '2026-03-03')['blocking'] === 0, 'once decided and verified (0 minutes), nothing blocks');
$tc = OT::finalize($uuid(), '2026-03-02', '2026-03-03', '2000.00', '', $acc);
ok(($tc['reason_code'] ?? '') === 'TOTAL_CHANGED' && $tc['total'] === '2230.63', 'A TOTAL DIFFERENT FROM THE ONE ON THE SCREEN IS REFUSED');
$runU = $uuid();
$fin = OT::finalize($runU, '2026-03-02', '2026-03-03', '2230.63', 'March week 1', $acc);
ok($fin['ok'] && $fin['already'] === false && (int) $fin['run']['line_count'] === 3 && $fin['run']['total_amount'] === '2230.63', 'finalized: 3 lines, 2230.63');
ok(Db::value("SELECT rate_type FROM att_ot_payroll WHERE request_employee_id = ?", [$L3]) === 'none'
   && Db::value("SELECT status FROM att_ot_payroll WHERE request_employee_id = ?", [$L3]) === 'finalized', 'the 0-minute line is locked in the run too, with nothing to pay');
$rr = OT::finalize($runU, '2026-03-02', '2026-03-03', '2230.63', '', $acc);
ok($rr['already'] === true && $n('SELECT COUNT(*) FROM att_ot_payroll_runs') === 1, 'the same finalize sent twice is one run');
ok((OT::finalize($uuid(), '2026-03-02', '2026-03-03', '0', '', $acc)['reason_code'] ?? '') === 'NOTHING_TO_FINALIZE', 'finalizing the same period again finds nothing left');

echo "8. After finalizing, the line is locked\n";
ok((OT::setRate($L1, 'hourly', '300', $acc)['reason_code'] ?? '') === 'PAYROLL_LOCKED', 'the rate cannot be changed');
ok((OT::amend($R1, $L1, 'approved_minutes', 200, 'try', $hr)['reason_code'] ?? '') === 'PAYROLL_LOCKED', 'the approval cannot be amended');
ok((OT::amend($R1, $L1, 'decision', 'reject', 'try', $hr)['reason_code'] ?? '') === 'PAYROLL_LOCKED', 'nor the decision');
ok((OT::amend($R3, $L3, 'actual_minutes', 30, 'try', $hr)['reason_code'] ?? '') === 'PAYROLL_LOCKED', 'NOR THE 0-MINUTE LINE — that is why it is locked as well');
ok((OT::correct($L1, 175, 'hourly', '300', '', $acc)['reason_code'] ?? '') === 'REASON_REQUIRED', 'a correction needs a reason');
$co = OT::correct($L1, 175, 'hourly', '300', 'Rate agreed for export rush was 300, not 250.50', $acc);
ok($co['ok'] && $co['amount'] === '875.00' && $co['delta'] === '144.37', 'corrected to 300/hour: now owed 875.00, a difference of 144.37');
$pay = Db::one('SELECT payable_amount, manual_rate, effective_amount FROM att_ot_payroll WHERE request_employee_id = ?', [$L1]);
ok($pay['payable_amount'] === '730.63' && $pay['manual_rate'] === '250.50' && $pay['effective_amount'] === '875.00', 'THE FINALIZED FIGURES ARE UNCHANGED; the corrected amount sits beside them');
ok(Db::value('SELECT total_amount FROM att_ot_payroll_runs') === '2230.63', 'and so is the run total');
$run = OT::runs()['runs'][0];
ok($run['corrections'] === 1 && $run['corrections_total'] === '144.37' && $run['finalized_by_name'] === 'Asma Accounts', 'the run list shows one correction worth +144.37');
ok((OT::correct($L1, 175, 'hourly', '300.00', 'again', $acc)['reason_code'] ?? '') === 'NO_CHANGE', 'correcting to what is already owed changes nothing');
$co2 = OT::correct($L1, 160, 'hourly', '300', 'Gate log rechecked: 160 minutes', $acc);
ok($co2['delta'] === '-75.00' && $n('SELECT COUNT(*) FROM att_ot_payroll_corrections') === 2, 'a second correction is a second row, measured from the first (−75.00)');
ok((OT::correct($L4, 10, 'hourly', '100', 'try', $acc)['reason_code'] ?? '') === 'NOT_FINALIZED', 'only finalized payroll is corrected');
ok(array_column(OT::history($L1)['history'], 'kind') === ['request', 'decision', 'amendment', 'verification', 'rate', 'finalize', 'correction', 'correction'],
   'E001\'s history, start to end: request, decision, amendment, verification, rate, finalize, correction, correction');
ok($n("SELECT COUNT(*) FROM att_audit_logs WHERE action LIKE 'ot.%'") >= 15, 'and every step is in the audit log');

echo "9. Amendments to a decision\n";
OT::setToday('2026-03-05');
$r6 = OT::createRequest($req(['employees' => [['employee_code' => 'E004', 'estimated_minutes' => 60]], 'work_date' => '2026-03-05', 'planned_start' => '', 'planned_end' => '']), $scopeS, $hod);
$R6 = (int) $r6['request']['id']; $L6 = $line($R6, 'E004');
OT::decide($R6, $uuid(), [['request_employee_id' => $L6, 'decision' => 'approve']], $hr);
OT::setToday('2026-03-06');
OT::verify($R6, $L6, 60, '', null, $hr);
OT::setRate($L6, 'hourly', '200', $acc);
$rj = OT::amend($R6, $L6, 'decision', 'reject', 'Approved by mistake — E004 was on leave', $hr);
ok($rj['ok'] && ($rj['new']['payroll_draft_removed'] ?? false) === true && $n('SELECT COUNT(*) FROM att_ot_payroll WHERE request_employee_id = ?', [$L6]) === 0,
   'an approval reversed before payroll: the line is rejected and its unpaid rate removed');
$l6 = Db::one('SELECT approval_status, approved_minutes, actual_minutes, verification_status FROM att_ot_request_employees WHERE id = ?', [$L6]);
ok($l6['approved_minutes'] === null && $l6['actual_minutes'] === null && $l6['verification_status'] === 'pending', '  nothing left to verify or pay');
OT::setToday('2026-03-05');
$r7 = OT::createRequest($req(['employees' => [['employee_code' => 'E004', 'estimated_minutes' => 30]], 'work_date' => '2026-03-05', 'planned_start' => '', 'planned_end' => '']), $scopeS, $hod);
ok($r7['ok'], 'once rejected, the day is free for a new request');
ok((OT::amend($R6, $L6, 'decision', 'approve', 'changed mind', $hr)['reason_code'] ?? '') === 'ALREADY_REQUESTED',
   'putting the old line back to approved would make two live lines for one day — refused');
ok((OT::amend((int) $r7['request']['id'], $line((int) $r7['request']['id'], 'E004'), 'decision', 'approve', 'x', $hr)['reason_code'] ?? '') === 'NOT_DECIDED',
   'a line nobody has decided yet is decided, not amended');

/* ===================================================================== */
/* Over real HTTP.                                                         */
OT::setToday(null);
$port = 8733;
$srv = proc_open(sprintf('exec php -S 127.0.0.1:%d -t %s', $port, escapeshellarg(rtrim($B, '/'))),
    [1 => ['file', '/tmp/att_m6_srv.log', 'a'], 2 => ['file', '/tmp/att_m6_srv.log', 'a']], $pipes);
register_shutdown_function(static function () use ($srv) { if (is_resource($srv)) { proc_terminate($srv, 9); proc_close($srv); } });
for ($i = 0; $i < 60; $i++) { $c = @fsockopen('127.0.0.1', $port, $e1, $e2, 0.2); if ($c) { fclose($c); break; } usleep(200000); }
$jar = tempnam(sys_get_temp_dir(), 'attm6');
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

echo "10. Giving the jobs gives the overtime permissions that go with them\n";
$as = signIn('admin@zas.test');
foreach ([[$hod, 'hod', $dS], [$hodP, 'hod', $dP], [$hr, 'hr', 0], [$acc, 'accounts', 0], [$gk, 'gatekeeper', 0]] as [$uid, $role, $dept]) {
    Roles::revoke($uid, $role, $dept ?: null);
    call('POST', '/admin/jobs/grant', ['user_id' => $uid, 'role' => $role, 'department_id' => $dept], $as);
}
ok(str_contains($perm($hod, 'attot'), 'c') && $perm($hod, 'attotap') === '', 'a HOD can request overtime, and cannot approve it');
ok(str_contains($perm($hr, 'attotap'), 'u') && str_contains($perm($hr, 'attotvf'), 'u') && $perm($hr, 'attpay') === '', 'HR can approve and verify, and cannot touch payroll');
ok(str_contains($perm($acc, 'attotap'), 'u') && str_contains($perm($acc, 'attotvf'), 'u') && str_contains($perm($acc, 'attpay'), 'u'), 'Accounts can approve, verify, rate and finalize');
ok($perm($gk, 'attotvf') === '' && $perm($gk, 'attot') === '', 'a gatekeeper gets no overtime permission by default');

echo "11. Over HTTP\n";
$today = (new DateTimeImmutable('now', new DateTimeZone('Asia/Karachi')))->format('Y-m-d');
$yday  = (new DateTimeImmutable('now', new DateTimeZone('Asia/Karachi')))->modify('-1 day')->format('Y-m-d');
$hs = signIn('hod@zas.test');
$body = ['request_uuid' => $uuid(), 'department_id' => $dS, 'work_date' => $yday, 'reason' => '=HYPERLINK("http://x","rush")',
         'employees' => [['employee_code' => 'E003', 'estimated_minutes' => 90]]];
ok(call('POST', '/overtime/requests', $body)['status'] === 419, 'no CSRF token: refused');
ok(call('POST', '/overtime/requests', array_merge($body, ['department_id' => $dP]), $hs)['status'] === 422, 'the HOD asking for Packing: 422');
$cr = call('POST', '/overtime/requests', $body, $hs);
$RH = (int) ($cr['body']['request']['id'] ?? 0);
ok($cr['status'] === 200 && $RH > 0, 'the HOD asks for E003, yesterday');
$LH = (int) $cr['body']['request']['lines'][0]['request_employee_id'];
ok(call('POST', "/overtime/requests/$RH/decisions", ['request_uuid' => $uuid(), 'employee_decisions' => [['request_employee_id' => $LH, 'decision' => 'approve']]], $hs)['status'] === 403,
   'THE HOD CANNOT APPROVE THEIR OWN REQUEST');
$list = call('GET', '/overtime/requests');
ok($list['status'] === 200 && count(array_filter($list['body']['requests'], fn($r) => (int) $r['department_id'] !== $dS)) === 0, 'the HOD\'s list holds only Stitching requests');
signIn('hodp@zas.test');
ok(call('GET', "/overtime/requests/$RH")['status'] === 404, 'the Packing HOD cannot open a Stitching request');
signIn('gate@zas.test');
ok(call('GET', '/overtime/requests')['status'] === 403, 'a gatekeeper cannot read overtime requests');

$rs = signIn('hr@zas.test');
$dec = call('POST', "/overtime/requests/$RH/decisions", ['request_uuid' => $uuid(), 'employee_decisions' => [['request_employee_id' => $LH, 'decision' => 'approve']]], $rs);
ok($dec['status'] === 200 && $dec['body']['results'][0]['result'] === 'APPROVED', 'HR approves');
$vl = call('GET', '/overtime/verification');
$vrow = array_values(array_filter($vl['body']['lines'] ?? [], fn($l) => (int) $l['request_employee_id'] === $LH))[0] ?? null;
ok($vl['status'] === 200 && $vrow !== null && $vrow['can_verify_now'] === true, 'it is on HR\'s verification list, ready today');
$ex = call('POST', "/overtime/requests/$RH/verify-hours", ['request_employee_id' => $LH, 'actual_minutes' => 120], $rs);
ok($ex['status'] === 422 && ($ex['body']['reason_code'] ?? '') === 'EXCESS_REQUIRES_AMENDMENT', '120 against 90 approved: 422 EXCESS_REQUIRES_AMENDMENT');
ok(call('POST', "/overtime/requests/$RH/verify-hours", ['request_employee_id' => $LH, 'actual_minutes' => 80, 'verification_note' => 'gate 18:20'], $rs)['status'] === 200, '80 minutes: verified');
ok(call('POST', '/overtime/payroll/rate', ['request_employee_id' => $LH, 'rate_type' => 'hourly', 'manual_rate' => '210'], $rs)['status'] === 403, 'HR CANNOT ENTER A RATE');
ok(call('GET', '/overtime/payroll', ['from' => $yday, 'to' => $yday])['status'] === 403, 'nor see payroll');

$gs = signIn('gate@zas.test');
$pdo->prepare("INSERT INTO zu_perm (user_id, module, actions) VALUES (?, 'attotvf', 'vu')")->execute([$gk]);
$gs = signIn('gate@zas.test');
$gv = call('POST', "/overtime/requests/$RH/verify-hours", ['request_employee_id' => $LH, 'actual_minutes' => 10], $gs);
ok($gv['status'] === 403, 'A GATEKEEPER WITH THE VERIFY PERMISSION BUT THE SETTING OFF IS STILL REFUSED');
ok(call('POST', "/overtime/requests/$RH/amend", ['request_employee_id' => $LH, 'change' => 'actual_minutes', 'value' => 10, 'reason' => 'x'], $gs)['status'] === 403,
   'and a gatekeeper can never amend verified minutes');

$ac = signIn('acc@zas.test');
ok(call('POST', '/overtime/payroll/rate', ['request_employee_id' => $LH, 'rate_type' => 'hourly', 'manual_rate' => '210'], $ac)['status'] === 200, 'Accounts enters 210/hour');
$pv = call('GET', '/overtime/payroll', ['from' => $yday, 'to' => $yday]);
$tot = (string) ($pv['body']['total_ready'] ?? '');
ok($pv['status'] === 200 && $tot === '280.00', 'payroll for yesterday shows 280.00 ready (210 × 80 ÷ 60)');
$fz = call('POST', '/overtime/payroll/finalize', ['run_uuid' => $uuid(), 'from' => $yday, 'to' => $yday, 'expected_total' => $tot], $ac);
ok($fz['status'] === 200 && ($fz['body']['run']['total_amount'] ?? '') === '280.00', 'finalized over HTTP');
$lk = call('POST', '/overtime/payroll/rate', ['request_employee_id' => $LH, 'rate_type' => 'hourly', 'manual_rate' => '220'], $ac);
ok($lk['status'] === 409 && ($lk['body']['reason_code'] ?? '') === 'PAYROLL_LOCKED', 'then the rate is locked: 409 PAYROLL_LOCKED');
$runId = (int) $fz['body']['run']['id'];
$csv = call('GET', "/overtime/payroll/runs/$runId/export");
ok($csv['status'] === 200 && str_contains($csv['headers'], 'text/csv') && str_contains($csv['headers'], 'attachment'), 'the run downloads as a spreadsheet file');
ok(str_contains($csv['raw'], '"Nadia Noor"') && str_contains($csv['raw'], '"280.00"'), '  with the employee and the amount');
signIn('hr@zas.test');
ok(call('GET', "/overtime/payroll/runs/$runId/export")['status'] === 403, 'HR cannot download payroll');

/* A name typed as a spreadsheet formula must arrive as text. */
$pdo->prepare('UPDATE att_employees SET full_name = ? WHERE id = ?')->execute(['=HYPERLINK("http://evil","pay")', $emp['E003']]);
signIn('acc@zas.test');
$csv2 = call('GET', "/overtime/payroll/runs/$runId/export");
ok(str_contains($csv2['raw'], '"\'=HYPERLINK(""http://evil"",""pay"")"'), 'A NAME BEGINNING WITH "=" IS WRITTEN AS TEXT, so the spreadsheet does not run it');
$pdo->prepare('UPDATE att_employees SET full_name = ? WHERE id = ?')->execute(['Nadia Noor', $emp['E003']]);

echo "12. Settings\n";
$as = signIn('admin@zas.test');
$keys = array_column(call('GET', '/admin/settings')['body']['settings'] ?? [], 'key');
ok(count(array_intersect(['ot_request_days_ahead', 'ot_request_days_back', 'ot_max_minutes', 'ot_decision_window_days', 'ot_verify_from_next_day', 'gatekeeper_may_verify_ot'], $keys)) === 6,
   'the six overtime settings are on the Settings tab');
ok(call('POST', '/admin/settings', ['values' => ['gatekeeper_may_verify_ot' => true]], $as)['status'] === 200, 'the admin switches "a gatekeeper may enter actual minutes" on');
$r8 = OT::createRequest(['request_uuid' => $uuid(), 'department_id' => $dS, 'work_date' => $yday, 'reason' => 'second shift cover',
    'employees' => [['employee_code' => 'E002', 'estimated_minutes' => 60]]], null, $hod);
$R8 = (int) $r8['request']['id']; $L8 = $line($R8, 'E002');
OT::decide($R8, $uuid(), [['request_employee_id' => $L8, 'decision' => 'approve']], $hr);
$gs = signIn('gate@zas.test');
ok(call('POST', "/overtime/requests/$R8/verify-hours", ['request_employee_id' => $L8, 'actual_minutes' => 60], $gs)['status'] === 200,
   'NOW THE GATEKEEPER, WITH BOTH THE PERMISSION AND THE SETTING, CAN ENTER ACTUAL MINUTES');

/* ===================================================================== */
echo "13. In a browser: the HOD's phone, HR's and Accounts' desk\n";
OT::setRate($L8, 'fixed', '500', $acc);       // the gatekeeper-verified line from step 12, so only the browser's line is left to rate
$scan('GATE_IN', 'E001', $yday, '04:00'); $scan('GATE_OUT', 'E001', $yday, '14:00');     // 09:00 → 19:00 at the factory
$scan('GATE_IN', 'E004', $yday, '04:00'); $scan('GATE_OUT', 'E004', $yday, '12:05');     // left on time
$work = __DIR__ . '/.zattm6';
@mkdir($work, 0777, true);
$shot = getenv('ATT_SHOT_DIR') ?: '';
file_put_contents($work . '/run.js', <<<'JS'
const { chromium } = require('playwright');
const BASE = process.argv[2], YDAY = process.argv[3], SHOT = process.argv[4] || '';
const out = [];
const check = (name, pass, extra) => out.push({ name, pass: !!pass, extra: extra === undefined ? '' : String(extra) });
async function desk(page, email) {
  await page.goto(BASE + '/att/manifest.webmanifest');
  return page.evaluate(async (email) => (await fetch('../api/v1/index.php?route=%2Fauth%2Flogin', { method: 'POST', credentials: 'same-origin',
    headers: { 'Content-Type': 'application/json' }, body: JSON.stringify({ email, password: 'CorrectHorse9' }) })).status, email);
}
const loaded = (page) => page.waitForFunction(() => !/^Loading…$/.test(document.getElementById('atto-body').textContent.trim()), null, { timeout: 20000 });
(async () => {
  const browser = await chromium.launch();
  try {
    // ============================================================ HOD phone
    const pc = await browser.newContext({ viewport: { width: 390, height: 800 } });
    const ph = await pc.newPage();
    const perr = [];
    ph.on('pageerror', e => perr.push(e.message));
    ph.on('dialog', d => d.accept());
    await ph.goto(BASE + '/att/');
    await ph.waitForSelector('#email', { timeout: 20000 });
    await ph.fill('#email', 'hod@zas.test'); await ph.fill('#password', 'CorrectHorse9'); await ph.click('#signin');
    await ph.waitForSelector('#pin', { timeout: 20000 }); await ph.fill('#pin', '1234'); await ph.click('#pingo');
    await ph.waitForSelector('#tab-ot', { timeout: 20000 });
    check('the HOD\'s phone has an Overtime tab', true);
    await ph.click('#tab-ot');
    await ph.waitForSelector('#ot-send', { timeout: 20000 });
    await ph.fill('#ot-date', YDAY);
    await ph.fill('#ot-reason', 'Container 4471 loading ran late');
    await ph.fill('#ot-min', '90');
    for (const name of ['Imran Ali', 'Asad Mir']) await ph.locator('#ot-people label', { hasText: name }).locator('input').check();
    check('only the HOD\'s own department is offered', (await ph.locator('#ot-people label', { hasText: 'Sara Khan' }).count()) === 0);
    await ph.click('#ot-send');
    await ph.waitForSelector('#ot-msg.ok', { timeout: 20000 });
    check('the request is sent from the phone', /Sent for 2 employee/.test(await ph.textContent('#ot-msg')));
    // Wait for THIS request: an older one in the list may already show "waiting".
    const mineItem = ph.locator('#ot-mine .item', { hasText: 'Container 4471' });
    await mineItem.waitFor({ timeout: 20000 });
    check('  and appears under "Your recent requests", both employees waiting', (await mineItem.locator('[data-status="pending"]').count()) === 2);
    if (SHOT) await ph.screenshot({ path: SHOT + '/att-m6-hod-phone.png', fullPage: true });
    await pc.setOffline(true);
    await ph.click('#tab-queue'); await ph.click('#tab-ot');
    await ph.waitForSelector('#ot-offline');
    check('offline, the Overtime tab says plainly that it needs a connection', /need a connection/.test(await ph.textContent('#ot-offline')));
    await pc.setOffline(false);
    check('no script error on the HOD phone', perr.length === 0, perr.join(' | '));
    await pc.close();

    // ============================================================= HR desk
    const hc = await browser.newContext({ viewport: { width: 1280, height: 900 } });
    const hp = await hc.newPage();
    const herr = [];
    hp.on('pageerror', e => herr.push(e.message));
    await desk(hp, 'hr@zas.test');
    await hp.goto(BASE + '/att_overtime.php');
    await loaded(hp);
    const tabs = await hp.locator('#atto-tabs button').allTextContents();
    check('HR sees Requests and Verify hours — not New request, not Payroll', tabs.join(',') === 'Requests,Verify hours', tabs.join(','));
    check('the menu shows Attendance › Overtime', /Overtime/.test(await hp.textContent('.nav')));
    const card = hp.locator('.req', { hasText: 'Container 4471' });
    await card.waitFor({ timeout: 20000 });
    await card.locator('tr', { hasText: 'Imran Ali' }).locator('input[type=checkbox]').check();
    await card.locator('button:has-text("Approve selected")').click();
    await hp.waitForSelector('#attodlg[open]');
    await hp.fill('#dec-remarks', 'Loading confirmed by dispatch');
    await hp.click('#attodlg-ok');
    await hp.waitForFunction(() => !document.querySelector('#attodlg[open]'));
    await loaded(hp);
    const card2 = hp.locator('.req', { hasText: 'Container 4471' });
    await card2.locator('tr', { hasText: 'Asad Mir' }).locator('input[type=checkbox]').check();
    await card2.locator('button:has-text("Reject selected")').click();
    await hp.waitForSelector('#attodlg[open]');
    await hp.click('#attodlg-ok');
    await hp.waitForFunction(() => !document.querySelector('#attodlg[open]'));
    await loaded(hp);
    check('once both are decided the request leaves the "waiting" list', (await hp.locator('.req', { hasText: 'Container 4471' }).count()) === 0);
    await hp.selectOption('#req-status', 'decided');
    await loaded(hp);
    const dc = await hp.locator('.req', { hasText: 'Container 4471' }).textContent();
    check('  and shows under Decided: Imran approved, Asad rejected, by Rana HR', /Imran Ali[\s\S]*approved[\s\S]*Rana HR/.test(dc) && /Asad Mir[\s\S]*rejected/.test(dc), dc.slice(0, 300));
    if (SHOT) await hp.screenshot({ path: SHOT + '/att-m6-requests.png', fullPage: true });

    await hp.click('#atto-tabs button:has-text("Verify hours")');
    await loaded(hp);
    const vrow = hp.locator('#atto-body tr', { hasText: 'Imran Ali' }).filter({ hasText: 'Container 4471' });
    await vrow.waitFor();
    const vt = await vrow.textContent();
    check('the gate history is shown beside the line: out at 19:00, 2 h inside after the shift', /Inside after the shift ended: 2 h 0 min/.test(vt), vt);
    const box = vrow.locator('input[type=number]');
    check('THE ACTUAL MINUTES BOX IS EMPTY — the 2 hours at the gate are not copied into it', (await box.inputValue()) === '');
    await vrow.locator('button:has-text("Verify")').click();
    await hp.waitForFunction(() => /Type the actual minutes/.test(document.getElementById('atto-body').textContent));
    check('verifying with the box empty is refused on the screen', true);
    await box.fill('120');
    await vrow.locator('button:has-text("Verify")').click();
    await hp.waitForFunction(() => /approved\. An officer must first amend/.test(document.getElementById('atto-body').textContent));
    check('120 against 90 approved is refused, with the reason', true);
    if (SHOT) await hp.screenshot({ path: SHOT + '/att-m6-verify.png', fullPage: true });
    await box.fill('85');
    await vrow.locator('button:has-text("Verify")').click();
    await hp.waitForFunction(() => !/Container 4471/.test(document.getElementById('atto-body').textContent) || /verified/.test(document.getElementById('atto-body').textContent), null, { timeout: 20000 });
    check('85 minutes is accepted and the line leaves the list', true);
    check('no script error on HR\'s desk', herr.length === 0, herr.join(' | '));
    await hc.close();

    // ======================================================= Accounts desk
    const ac = await browser.newContext({ viewport: { width: 1280, height: 900 }, acceptDownloads: true });
    const ap = await ac.newPage();
    const aerr = [];
    ap.on('pageerror', e => aerr.push(e.message));
    await desk(ap, 'acc@zas.test');
    await ap.goto(BASE + '/att_overtime.php#pay');
    await loaded(ap);
    await ap.fill('#pay-from', YDAY); await ap.fill('#pay-to', YDAY);
    await ap.click('#atto-body button:has-text("Show")');
    await ap.waitForSelector('#atto-body tr:has-text("Imran Ali")');
    const prow = ap.locator('#atto-body tr', { hasText: 'Imran Ali' });
    check('Imran\'s line waits for a rate, and blocks finalizing until it has one', /no rate/.test(await prow.textContent()) && await ap.isDisabled('button:has-text("Finalize these dates")'));
    await prow.locator('input[type=text]').fill('abc');
    await prow.locator('button:has-text("Save")').click();
    await ap.waitForFunction(() => /never worked out/.test(document.getElementById('atto-body').textContent));
    check('a rate that is not an amount is refused', true);
    await ap.locator('#atto-body tr', { hasText: 'Imran Ali' }).locator('input[type=text]').fill('240');
    await ap.locator('#atto-body tr', { hasText: 'Imran Ali' }).locator('button:has-text("Save")').click();
    await ap.waitForFunction(() => /340\.00/.test(document.getElementById('atto-body').textContent), null, { timeout: 20000 });
    check('240 per hour × 85 minutes = 340.00', true);
    const total = await ap.textContent('#pay-total');
    check('the total ready to finalize is shown', /\d+\.\d\d/.test(total), total);
    if (SHOT) await ap.screenshot({ path: SHOT + '/att-m6-payroll.png', fullPage: true });
    await ap.click('button:has-text("Finalize these dates")');
    await ap.waitForSelector('#attodlg[open]');
    check('the confirmation states the total and that the lines will be locked', /locked/.test(await ap.textContent('#attodlg-text')) && (await ap.textContent('#attodlg-ok')).includes(total));
    await ap.click('#attodlg-ok');
    await ap.waitForFunction(() => !document.querySelector('#attodlg[open]'));
    await loaded(ap);
    await ap.waitForSelector('#atto-body a:has-text("Download")');
    check('after finalizing, the line shows as finalized and the run is listed', /finalized/.test(await ap.locator('#atto-body tr', { hasText: 'Imran Ali' }).first().textContent()));
    const [dl] = await Promise.all([ap.waitForEvent('download'), ap.locator('#atto-body a:has-text("Download")').first().click()]);
    const csv = require('fs').readFileSync(await dl.path(), 'utf8');
    check('the run downloads as a spreadsheet with the 340.00 in it', /Imran Ali/.test(csv) && /340\.00/.test(csv), csv.slice(0, 200));
    check('a finalized line offers only "Correct…", no rate box', (await ap.locator('#atto-body tr', { hasText: 'Imran Ali' }).first().locator('input').count()) === 0
      && (await ap.locator('#atto-body tr', { hasText: 'Imran Ali' }).first().locator('button:has-text("Correct")').count()) === 1);
    check('no script error on Accounts\' desk', aerr.length === 0, aerr.join(' | '));

    // phone width
    const mc = await browser.newContext({ viewport: { width: 390, height: 800 }, storageState: await ac.storageState() });
    const mp = await mc.newPage();
    const wide = [];
    for (const t of ['req', 'verify', 'pay']) {
      await mp.goto(BASE + '/att_overtime.php#' + t); await mp.reload(); await loaded(mp); await mp.waitForTimeout(300);
      const over = await mp.evaluate(() => document.documentElement.scrollWidth - innerWidth);
      if (over > 1) wide.push(t + ' +' + over);
    }
    check('at phone width no tab pushes the page sideways', wide.length === 0, wide.join(', '));
    await mc.close(); await ac.close();
  } catch (e) { check('the browser run completed', false, e && e.message); }
  finally { await browser.close(); }
  console.log('RESULT' + JSON.stringify(out));
})();
JS);
$raw = (string) shell_exec('cd ' . escapeshellarg($work) . ' && timeout 400 node run.js ' . escapeshellarg("http://127.0.0.1:$port") . ' ' . escapeshellarg($yday) . ' ' . escapeshellarg($shot) . ' 2>&1');
$results = [];
if (preg_match('/RESULT(\[.*\])\s*$/s', $raw, $m)) { $results = json_decode($m[1], true) ?: []; }
ok(count($results) >= 20, 'the browser run reported its checks (' . count($results) . ')' . (count($results) ? '' : "\n$raw"));
foreach ($results as $r) { ok($r['pass'], $r['name'] . ($r['pass'] ? '' : ' — ' . $r['extra'])); }
$e1 = Db::one("SELECT l.approval_status, l.approved_minutes, l.actual_minutes, l.decided_by, l.verified_by, p.payable_amount, p.status
                 FROM att_ot_request_employees l LEFT JOIN att_ot_payroll p ON p.request_employee_id = l.id
                WHERE l.employee_id = ? AND l.work_date = ?", [$emp['E001'], $yday]);
ok($e1 && $e1['approval_status'] === 'approved' && (int) $e1['approved_minutes'] === 90 && (int) $e1['actual_minutes'] === 85 && (int) $e1['decided_by'] === $hr && (int) $e1['verified_by'] === $hr
   && $e1['payable_amount'] === '340.00' && $e1['status'] === 'finalized', 'on the server: requested 90, approved by HR, 85 verified by HR, 340.00 finalized');
ok(Db::value("SELECT approval_status FROM att_ot_request_employees WHERE employee_id = ? AND work_date = ?", [$emp['E004'], $yday]) === 'rejected', 'and Asad\'s line is rejected');

echo "14. Rolling back\n";
$mig->down(13);
ok(!Db::tableExists('att_ot_requests') && !Db::tableExists('att_ot_payroll') && Db::tableExists('att_flags'), 'down to 13 removes the overtime tables and nothing older');
$mig->up();
ok(Db::tableExists('att_ot_payroll_corrections'), 'and up puts them back');

echo "\n$P passed, $F failed\n";
exit($F === 0 ? 0 : 1);
