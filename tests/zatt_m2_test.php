<?php
declare(strict_types=1);

/* ATTENDANCE M2 — gate IN/OUT, entry cycles, the 60-second rule.
 *
 * Covers AT01 and AT02 from the approved acceptance list, plus the two races
 * that only appear when two handsets act at once. The races are run as REAL
 * CONCURRENT PROCESSES against MySQL, not simulated by calling a function
 * twice: the whole point is that both connections pass the application's own
 * "is there an open cycle?" check before either commits, and only the unique
 * key saves the data. Calling the function twice in one process would never
 * reproduce it and the test would pass while the factory got an employee who
 * is inside twice.
 */

$B = __DIR__ . '/app_src/public_html/';
$P = 0; $F = 0;
function ok($c, $m) { global $P, $F; if ($c) { $P++; } else { $F++; echo "  FAIL: $m\n"; } }

$dsn  = getenv('ATT_TEST_DSN') ?: 'mysql:host=127.0.0.1;port=3306;dbname=zas_att_test;charset=utf8mb4';
$dbu  = getenv('ATT_TEST_USER') ?: 'zas';
$dbp  = getenv('ATT_TEST_PASS'); if ($dbp === false) { $dbp = 'zaspw'; }

require_once $B . 'includes/att/Db.php';
require_once $B . 'includes/att/Time.php';
require_once $B . 'includes/att/Migrations.php';
require_once $B . 'includes/att/Policy.php';
require_once $B . 'includes/att/Audit.php';
require_once $B . 'includes/att/Roles.php';
require_once $B . 'includes/att/Employees.php';
require_once $B . 'includes/att/Shifts.php';
require_once $B . 'includes/att/AttendanceService.php';

use Att\AttendanceService as A;
use Att\Db;
use Att\Employees;
use Att\Migrations;
use Att\Policy;
use Att\Shifts;
use Att\Time;

try {
    $pdo = new PDO($dsn, $dbu, $dbp, [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        PDO::ATTR_EMULATE_PREPARES => false,
    ]);
} catch (Throwable $e) {
    echo "SKIP: no MySQL available ({$e->getMessage()})\n"; exit(0);
}
Db::bind($pdo);

$pdo->exec('SET FOREIGN_KEY_CHECKS=0');
foreach ($pdo->query("SELECT table_name AS tn FROM information_schema.tables WHERE table_schema = DATABASE()")->fetchAll() as $t) {
    $pdo->exec('DROP TABLE IF EXISTS `' . $t['tn'] . '`');
}
$pdo->exec('SET FOREIGN_KEY_CHECKS=1');
$pdo->exec("CREATE TABLE users (id INT AUTO_INCREMENT PRIMARY KEY, name VARCHAR(150) NOT NULL,
            email VARCHAR(190) NOT NULL UNIQUE, password_hash VARCHAR(255) NOT NULL,
            role VARCHAR(40) NOT NULL DEFAULT 'colleague', is_active TINYINT(1) NOT NULL DEFAULT 1)");
(new Migrations($B . 'sql/att'))->up();

/* ---- a factory: one department, one day shift, three employees, 2 gates -- */
$dept  = Employees::createDepartment('Stitching');
$shift = Shifts::create(['name' => 'Day', 'start_time' => '09:00', 'end_time' => '17:00']);
Policy::set('default_shift_id', (string) $shift, null, 'string');
Policy::flush();

$e1 = Employees::create(['code' => 'E001', 'full_name' => 'Imran Ali', 'department_id' => $dept]);
$e2 = Employees::create(['code' => 'E002', 'full_name' => 'Sara Khan', 'department_id' => $dept]);
$e3 = Employees::create(['code' => 'E003', 'full_name' => 'Bilal Toor', 'department_id' => $dept]);

$pdo->exec("INSERT INTO att_devices (device_uuid,label,role_scope) VALUES
    ('11111111-1111-1111-1111-111111111111','Gate A','gate'),
    ('22222222-2222-2222-2222-222222222222','Gate B','gate'),
    ('33333333-3333-3333-3333-333333333333','Lost handset','gate')");
$gateA = (int) $pdo->query("SELECT id FROM att_devices WHERE label='Gate A'")->fetch()['id'];
$gateB = (int) $pdo->query("SELECT id FROM att_devices WHERE label='Gate B'")->fetch()['id'];
$lost  = (int) $pdo->query("SELECT id FROM att_devices WHERE label='Lost handset'")->fetch()['id'];
$pdo->exec("INSERT INTO users (name,email,password_hash) VALUES ('Gatekeeper','gk@zas.test','x')");
$op = (int) $pdo->lastInsertId();

$seq = 0;
/** One gate scan. $at is a UTC "H:i" on the test day. */
function scan(string $dir, int $emp, int $device, int $op, string $at, array $over = []): array {
    global $seq;
    $t = new DateTimeImmutable('2026-03-02 ' . $at . ':00', new DateTimeZone('UTC'));
    $payload = array_merge([
        'employee_id'     => $emp,
        'device_id'       => $device,
        'operator_id'     => $op,
        'event_uuid'      => sprintf('%08x-0000-4000-8000-%012x', random_int(0, 0xffffffff), ++$seq),
        'device_sequence' => $seq,
        'occurred_at'     => $t,
    ], $over);
    return $dir === 'in' ? A::gateIn($payload) : A::gateOut($payload);
}

echo "1. AT01 — three IN/OUT pairs make three cycles, each owing a HOD confirmation\n";
/* 05:00 UTC is 10:00 Karachi, inside the 09:00-17:00 shift. */
$r = scan('in',  $e1, $gateA, $op, '05:00'); ok($r['receipt_status'] === 'ACCEPTED', 'IN 1 accepted');
ok(($r['hod_confirmation_due'] ?? false) === true, '  invariant 1: the cycle owes a HOD confirmation');
ok(($r['entry_sequence'] ?? 0) === 1, '  first cycle is sequence 1');
ok(scan('out', $e1, $gateA, $op, '06:00')['receipt_status'] === 'ACCEPTED', 'OUT 1 accepted');
ok(scan('in',  $e1, $gateA, $op, '07:00')['receipt_status'] === 'ACCEPTED', 'IN 2 accepted (re-entry)');
ok(scan('out', $e1, $gateA, $op, '08:00')['receipt_status'] === 'ACCEPTED', 'OUT 2 accepted');
$r3 = scan('in', $e1, $gateA, $op, '09:00');
ok($r3['receipt_status'] === 'ACCEPTED', 'IN 3 accepted');
ok(($r3['entry_sequence'] ?? 0) === 3, '  third cycle is sequence 3');
ok(($r3['hod_confirmation_due'] ?? false) === true, '  AND IT OWES ITS OWN HOD CONFIRMATION — returns are not exempt');

$cycles = $pdo->query("SELECT COUNT(*) c FROM att_entry_cycles WHERE employee_id=$e1")->fetch()['c'];
$open   = $pdo->query("SELECT COUNT(*) c FROM att_entry_cycles WHERE employee_id=$e1 AND closed_at IS NULL")->fetch()['c'];
ok((int) $cycles === 3, 'exactly three entry cycles (got ' . $cycles . ')');
ok((int) $open === 1, 'exactly one still open');
scan('out', $e1, $gateA, $op, '10:00');

echo "2. Presence minutes come from CLOSED intervals only\n";
$row = $pdo->query("SELECT present_minutes FROM att_entry_cycles WHERE employee_id=$e1 ORDER BY entry_sequence LIMIT 1")->fetch();
ok((int) $row['present_minutes'] === 60, 'a 05:00→06:00 cycle is 60 minutes');
$sum = $pdo->query("SELECT COALESCE(SUM(present_minutes),0) s FROM att_entry_cycles WHERE employee_id=$e1")->fetch()['s'];
ok((int) $sum === 180, 'three closed hours total 180 minutes, no double counting');

echo "3. Invariant 2 — no double IN, no OUT without IN\n";
scan('in', $e2, $gateA, $op, '05:00');
$dbl = scan('in', $e2, $gateA, $op, '05:30');
ok($dbl['receipt_status'] === 'REJECTED', 'a second IN while inside is rejected');
ok(($dbl['reason_code'] ?? '') === 'ALREADY_INSIDE', '  reason ALREADY_INSIDE');
ok(isset($dbl['open_entry_cycle_id']), '  and it says which cycle is already open');
scan('out', $e2, $gateA, $op, '06:00');
$noIn = scan('out', $e2, $gateA, $op, '07:00');
ok($noIn['receipt_status'] === 'REJECTED' && ($noIn['reason_code'] ?? '') === 'NOT_INSIDE',
   'an OUT with nobody inside is rejected as NOT_INSIDE');

echo "4. AT02 — the 60-second rule, measured on when it HAPPENED\n";
$a = scan('in', $e3, $gateA, $op, '05:00');
ok($a['receipt_status'] === 'ACCEPTED', 'first scan accepted');
$b = scan('in', $e3, $gateB, $op, '05:00', ['device_sequence' => ++$seq]);
ok($b['receipt_status'] === 'REJECTED', 'THE SAME PERSON ON A SECOND GATE SECONDS LATER IS REJECTED');
ok(($b['reason_code'] ?? '') === 'DUPLICATE_WITHIN_WINDOW', '  reason DUPLICATE_WITHIN_WINDOW');
ok(isset($b['duplicate_of_event_uuid']), '  naming the event it duplicates');

/* The window is judged on occurred_at, not arrival. These two events are
   recorded now but happened an hour apart, and the later one must be let in —
   an offline handset syncing at noon must not have its morning scans
   collapsed into one. */
$later = scan('out', $e3, $gateA, $op, '06:30');
ok($later['receipt_status'] === 'ACCEPTED', 'an event an hour apart is accepted even when it arrives late');

/* And the rule is per action: an OUT immediately after an IN is normal. */
scan('in', $e3, $gateA, $op, '07:00');
ok(scan('out', $e3, $gateA, $op, '07:00')['receipt_status'] === 'ACCEPTED',
   'an OUT in the same second as an IN is allowed — the window is per action, not per employee');

echo "5. Invariant 4 — a replay gets the ORIGINAL answer back\n";
$uuid = '44444444-4444-4444-8444-444444444444';
$first = scan('in', $e2, $gateA, $op, '11:00', ['event_uuid' => $uuid, 'device_sequence' => ++$seq]);
ok($first['receipt_status'] === 'ACCEPTED', 'first send accepted');
$replay = scan('in', $e2, $gateA, $op, '11:00', ['event_uuid' => $uuid, 'device_sequence' => $seq]);
ok($replay['receipt_status'] === 'ALREADY_RECEIVED', 'the replay is ALREADY_RECEIVED, not a second cycle');
ok(($replay['entry_cycle_id'] ?? 0) === ($first['entry_cycle_id'] ?? -1),
   '  AND CARRIES THE ORIGINAL entry_cycle_id — the phone gets what it missed, not a bare acknowledgement');
$n = $pdo->query("SELECT COUNT(*) c FROM att_events WHERE event_uuid='$uuid'")->fetch()['c'];
ok((int) $n === 1, 'the event is stored exactly once');

/* A replay of a REJECTED event must still say REJECTED. Re-evaluating it
   fresh could now say ACCEPTED, telling the phone a refused scan had worked. */
$ruuid = '55555555-5555-4555-8555-555555555555';
$rej1 = scan('in', $e2, $gateA, $op, '11:30', ['event_uuid' => $ruuid, 'device_sequence' => ++$seq]);
ok($rej1['receipt_status'] === 'REJECTED', 'a double IN is rejected');
$rej2 = scan('in', $e2, $gateA, $op, '11:30', ['event_uuid' => $ruuid, 'device_sequence' => $seq]);
ok($rej2['receipt_status'] === 'ALREADY_RECEIVED' && ($rej2['reason_code'] ?? '') === 'ALREADY_INSIDE',
   'ITS REPLAY STILL CARRIES THE REJECTION REASON, never a fresh acceptance');

echo "6. Devices: unknown and revoked are refused on every event\n";
$unknown = scan('in', $e3, 99999, $op, '12:00', ['device_sequence' => ++$seq]);
ok(($unknown['reason_code'] ?? '') === 'UNKNOWN_DEVICE', 'an unregistered device is refused');
$pdo->exec("UPDATE att_devices SET revoked_at = NOW(6) WHERE id = $lost");
$revoked = scan('in', $e3, $lost, $op, '12:05', ['device_sequence' => ++$seq]);
ok(($revoked['reason_code'] ?? '') === 'DEVICE_REVOKED',
   'A REVOKED HANDSET IS STOPPED ON THE NEXT EVENT, not when its session expires');

echo "7. Clock skew is recorded, never silently corrected\n";
$su = '66666666-6666-4666-8666-666666666666';
A::gateIn([
    'employee_id' => $e3, 'device_id' => $gateA, 'operator_id' => $op,
    'event_uuid' => $su, 'device_sequence' => ++$seq,
    'device_time' => (Time::nowUtc())->modify('+10 minutes')->format('c'),
    'timezone' => 'UTC',
]);
$ev = $pdo->query("SELECT clock_skew_ms, device_time, occurred_at FROM att_events WHERE event_uuid='$su'")->fetch();
ok($ev !== false && (int) $ev['clock_skew_ms'] > 500000, 'a ten-minute-fast handset has its skew stored');
ok($ev['device_time'] !== null, '  the claimed device time is kept beside the canonical one');

$bad = A::gateIn([
    'employee_id' => $e1, 'device_id' => $gateA, 'operator_id' => $op,
    'event_uuid' => '77777777-7777-4777-8777-777777777777', 'device_sequence' => ++$seq,
    'device_time' => 'definitely not a timestamp', 'timezone' => 'UTC',
]);
ok($bad['receipt_status'] === 'NEEDS_REVIEW' && ($bad['reason_code'] ?? '') === 'UNREADABLE_DEVICE_TIME',
   'an unreadable device time goes to review and does NOT silently become "now"');

echo "8. Offline replays are marked provisional\n";
/* A fresh employee on purpose. E003 is still inside from step 7's skew scan,
   so reusing them here would get a correct ALREADY_INSIDE rejection and this
   step would be testing the previous step's leftovers instead of its own
   subject — which is exactly how it failed the first time it was run. */
$e4 = Employees::create(['code' => 'E004', 'full_name' => 'Offline Case', 'department_id' => $dept]);
$pu = '88888888-8888-4888-8888-888888888888';
/* 10:00 UTC is 15:00 Karachi, inside the 09:00–17:00 day shift. */
$pr = scan('in', $e4, $gateA, $op, '10:00', ['event_uuid' => $pu, 'device_sequence' => ++$seq, 'source' => 'offline_replay']);
ok($pr['receipt_status'] === 'ACCEPTED', 'the replayed event is accepted');
$pv = $pdo->query("SELECT source, provisional FROM att_events WHERE event_uuid='$pu'")->fetch();
ok($pv !== false && (int) $pv['provisional'] === 1 && $pv['source'] === 'offline_replay',
   'a replayed event is distinguishable from a server-verified one (blueprint §3)');

echo "8b. A scan outside the default shift is held, and files nothing\n";
/* 14:00 UTC is 19:00 Karachi — two hours after a 09:00–17:00 day shift ended.
   D4 admits people who have no shift; it does not invent a shift that had
   already finished. The event is held for HR AND no "scheduled day" is
   written, or the employee's record would show a planned shift they were
   never on. */
$e5 = Employees::create(['code' => 'E005', 'full_name' => 'Out Of Hours', 'department_id' => $dept]);
$oh = scan('in', $e5, $gateA, $op, '14:00', ['device_sequence' => ++$seq]);
ok($oh['receipt_status'] === 'NEEDS_REVIEW', 'an out-of-hours scan goes to review, not silently through');
ok(($oh['reason_code'] ?? '') === 'NO_SHIFT_INSTANCE', '  reason NO_SHIFT_INSTANCE');
$orphans = (int) $pdo->query("SELECT COUNT(*) c FROM att_shift_instances WHERE employee_id=$e5")->fetch()['c'];
ok($orphans === 0, '  AND NO ORPHAN SHIFT INSTANCE IS LEFT BEHIND for a day they were never scheduled');

echo "9. THE RACE — two handsets scanning one person at the same instant\n";
/* Two separate PHP processes, started together, both told to open a cycle for
   the same employee. Both will pass the "already inside?" check; only the
   unique key can decide. */
$raceEmp = Employees::create(['code' => 'E900', 'full_name' => 'Race Case', 'department_id' => $dept]);
Shifts::assign($raceEmp, $shift, '2026-03-02');
$runner = sys_get_temp_dir() . '/att_race.php';
file_put_contents($runner, <<<'PHP'
<?php
$B = $argv[1]; $emp = (int)$argv[2]; $dev = (int)$argv[3]; $op = (int)$argv[4]; $uuid = $argv[5]; $seq = (int)$argv[6];
$dsn = $argv[7]; $u = $argv[8]; $p = $argv[9]; $start = (float)$argv[10];
foreach (['Db','Time','Policy','Audit','Roles','Employees','Shifts','AttendanceService'] as $c) require_once "$B/includes/att/$c.php";
$pdo = new PDO($dsn, $u, $p, [PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE=>PDO::FETCH_ASSOC, PDO::ATTR_EMULATE_PREPARES=>false]);
Att\Db::bind($pdo);
// Both processes wake at the same wall-clock instant.
usleep((int)max(0, ($start - microtime(true)) * 1000000));
$r = Att\AttendanceService::gateIn([
  'employee_id'=>$emp,'device_id'=>$dev,'operator_id'=>$op,'event_uuid'=>$uuid,'device_sequence'=>$seq,
  'occurred_at'=>new DateTimeImmutable('2026-03-02 05:00:00', new DateTimeZone('UTC')),
]);
echo json_encode(['status'=>$r['receipt_status'],'reason'=>$r['reason_code'] ?? null]);
PHP);

$start = microtime(true) + 0.6;
$cmds = [];
foreach ([[$gateA, 'aaaaaaaa-0000-4000-8000-000000000001', 9001], [$gateB, 'bbbbbbbb-0000-4000-8000-000000000002', 9002]] as $i => $d) {
    $cmds[$i] = proc_open(
        sprintf('exec php %s %s %d %d %d %s %d %s %s %s %.6f',
            escapeshellarg($runner), escapeshellarg(rtrim($B, '/')), $raceEmp, $d[0], $op,
            escapeshellarg($d[1]), $d[2], escapeshellarg($dsn), escapeshellarg($dbu), escapeshellarg($dbp), $start),
        [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes[$i]
    );
}
$out = [];
foreach ($cmds as $i => $proc) {
    $out[$i] = json_decode(trim((string) stream_get_contents($pipes[$i][1])), true) ?: [];
    fclose($pipes[$i][1]); fclose($pipes[$i][2]); proc_close($proc);
}
@unlink($runner);

$statuses = array_column($out, 'status');
sort($statuses);
$openNow = (int) $pdo->query("SELECT COUNT(*) c FROM att_entry_cycles WHERE employee_id=$raceEmp AND closed_at IS NULL")->fetch()['c'];
$total   = (int) $pdo->query("SELECT COUNT(*) c FROM att_entry_cycles WHERE employee_id=$raceEmp")->fetch()['c'];

ok($openNow === 1, 'AFTER A GENUINE RACE THE EMPLOYEE IS INSIDE EXACTLY ONCE (open cycles: ' . $openNow . ')');
ok($total === 1, '  and only one cycle was created in total');
ok(in_array('ACCEPTED', $statuses, true), '  one handset was told ACCEPTED');
ok(in_array('REJECTED', $statuses, true), '  the other was told REJECTED, not given a 500 (' . implode('/', $statuses) . ')');
$reasons = array_filter(array_column($out, 'reason'));
ok(in_array('ALREADY_INSIDE', $reasons, true) || in_array('DUPLICATE_WITHIN_WINDOW', $reasons, true),
   '  with a reason the gatekeeper can act on: ' . implode(',', $reasons));

echo "10. Movements and cycles respect the caller's department scope\n";
$other = Employees::createDepartment('Packing');
$oEmp  = Employees::create(['code' => 'E500', 'full_name' => 'Other Dept', 'department_id' => $other]);
Shifts::assign($oEmp, $shift, '2026-03-02');
scan('in', $oEmp, $gateA, $op, '05:00', ['device_sequence' => ++$seq]);

$scoped = A::movements([$dept]);
$depts  = array_unique(array_column($scoped, 'department_id'));
ok($depts === [$dept], 'movements scoped to one department return only that department');
ok(count(A::movements([$dept], null, null, 500)) < count(A::movements(null, null, null, 500)),
   '  and an unscoped caller sees more');
ok(A::movements([]) === [], 'scoped to nothing returns nothing, never everything');
ok(A::entryCycles([]) === [], 'the same for entry cycles');

$cyc = A::entryCycles([$other]);
ok(count($cyc) === 1 && (int) $cyc[0]['employee_id'] === $oEmp, 'entry cycles filter by department too');

echo "11. Events are immutable — nothing in the module updates one\n";
$svc = file_get_contents($B . 'includes/att/AttendanceService.php');
$body = preg_replace('!/\*.*?\*/!s', '', $svc);
ok(!preg_match('/UPDATE\s+att_events/i', (string) $body), 'there is no UPDATE of att_events anywhere in the service');
ok(!preg_match('/DELETE\s+FROM\s+att_events/i', (string) $body), 'and no DELETE either (blueprint §5)');

echo "\n$P passed, $F failed\n";
exit($F === 0 ? 0 : 1);
