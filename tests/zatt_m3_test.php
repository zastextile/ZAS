<?php
declare(strict_types=1);

/* ATTENDANCE M3 — HOD confirmation, one per entry cycle, single and bulk.
 *
 * AT06: "bulk HOD verifies each entry cycle once, restricted to assigned
 * department." Also lays the ground for AT05: when the server has to work out
 * WHICH entry a confirmation belongs to and there is more than one candidate,
 * it must not choose.
 *
 * Runs against real MySQL for the same reason as M2: "each cycle verified
 * once" is a unique key, and the race at the end is two real processes.
 */

$B = __DIR__ . '/app_src/public_html/';
$P = 0; $F = 0;
function ok($c, $m) { global $P, $F; if ($c) { $P++; } else { $F++; echo "  FAIL: $m\n"; } }

$dsn = getenv('ATT_TEST_DSN') ?: 'mysql:host=127.0.0.1;port=3306;dbname=zas_att_test;charset=utf8mb4';
$dbu = getenv('ATT_TEST_USER') ?: 'zas';
$dbp = getenv('ATT_TEST_PASS'); if ($dbp === false) { $dbp = 'zaspw'; }

foreach (['Db','Time','Migrations','Policy','Audit','Roles','Employees','Shifts','Receipts','AttendanceService','HodService'] as $c) {
    require_once $B . "includes/att/$c.php";
}

use Att\AttendanceService as A;
use Att\Db;
use Att\Employees;
use Att\HodService as H;
use Att\Migrations;
use Att\Policy;
use Att\Roles;
use Att\Shifts;

try {
    $pdo = new PDO($dsn, $dbu, $dbp, [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        PDO::ATTR_EMULATE_PREPARES => false,
    ]);
} catch (Throwable $e) { echo "SKIP: no MySQL available ({$e->getMessage()})\n"; exit(0); }
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

/* ---- the factory ---------------------------------------------------------- */
$dS = Employees::createDepartment('Stitching');
$dP = Employees::createDepartment('Packing');
$shift = Shifts::create(['name' => 'Day', 'start_time' => '09:00', 'end_time' => '17:00']);
Policy::set('default_shift_id', (string) $shift, null, 'string'); Policy::flush();

$emp = [];
foreach ([['S1',$dS],['S2',$dS],['S3',$dS],['S4',$dS],['S5',$dS],['S6',$dS],['S7',$dS],['S8',$dS],['P1',$dP],['P2',$dP]] as [$code,$d]) {
    $emp[$code] = Employees::create(['code' => $code, 'full_name' => "Worker $code", 'department_id' => $d]);
}

$pdo->exec("INSERT INTO att_devices (device_uuid,label,role_scope) VALUES
    ('11111111-1111-4111-8111-111111111111','Gate A','gate'),
    ('22222222-2222-4222-8222-222222222222','HOD phone','hod'),
    ('33333333-3333-4333-8333-333333333333','Lost HOD phone','hod')");
$dev = [];
foreach ($pdo->query("SELECT id,label FROM att_devices")->fetchAll() as $r) { $dev[$r['label']] = (int) $r['id']; }
$pdo->exec("UPDATE att_devices SET revoked_at = NOW(6) WHERE label = 'Lost HOD phone'");

$uid = [];
foreach (['gk','hodS','hodP','admin','nobody','hodNoDept'] as $n) {
    $pdo->prepare("INSERT INTO users (name,email,password_hash) VALUES (?,?,'x')")->execute([$n, "$n@zas.test"]);
    $uid[$n] = (int) $pdo->lastInsertId();
}
Roles::grant($uid['hodS'], Roles::HOD, $dS);
Roles::grant($uid['hodP'], Roles::HOD, $dP);
Roles::grant($uid['admin'], Roles::ADMIN);
Roles::grant($uid['hodNoDept'], Roles::HOD);            // a misconfiguration: HOD of nothing

$seq = 0; $n = 0;
function uuid(): string { global $n; return sprintf('%08x-0000-4000-8000-%012x', random_int(0, 0xffffffff), ++$n); }
function at(string $hm): DateTimeImmutable { return new DateTimeImmutable("2026-03-02 $hm:00", new DateTimeZone('UTC')); }
/** A gate scan; returns the receipt. 04:00–12:00 UTC is inside the day shift. */
function scan(string $dir, int $employee, string $hm): array {
    global $seq, $dev, $uid;
    $p = ['employee_id' => $employee, 'device_id' => $dev['Gate A'], 'operator_id' => $uid['gk'],
          'event_uuid' => uuid(), 'device_sequence' => ++$seq, 'occurred_at' => at($hm)];
    return $dir === 'in' ? A::gateIn($p) : A::gateOut($p);
}
function confirm(string $who, array $over): array {
    global $uid;
    return H::confirm(array_merge(['event_uuid' => uuid(), 'hod_user_id' => $uid[$who]], $over));
}

echo "1. Every cycle owes its own confirmation — including the returns\n";
$c1 = scan('in', $emp['S1'], '05:00')['entry_cycle_id']; scan('out', $emp['S1'], '05:30');
$c2 = scan('in', $emp['S1'], '06:00')['entry_cycle_id']; scan('out', $emp['S1'], '06:30');
$c3 = scan('in', $emp['S1'], '07:00')['entry_cycle_id'];
$pend = H::pending([$dS]);
ok(count($pend) === 3, 'three entries → three confirmations owed (got ' . count($pend) . ')');
ok(count(array_filter($pend, fn($r) => $r['closed_at'] !== null)) === 2,
   'INCLUDING THE TWO THAT ALREADY CLOSED — a short visit still owes one');

$r = confirm('hodS', ['entry_cycle_id' => $c1, 'occurred_at' => at('05:05')]);
ok($r['receipt_status'] === 'ACCEPTED', 'the HOD confirms the first entry');
ok(($r['match_method'] ?? '') === 'explicit_cycle', '  linked because the HOD named it');
ok(count(H::pending([$dS])) === 2, 'the other two are STILL owed — one confirmation does not cover the day');

echo "2. Each cycle is verified once\n";
$again = confirm('hodS', ['entry_cycle_id' => $c1, 'occurred_at' => at('05:06')]);
ok($again['receipt_status'] === 'REJECTED' && ($again['reason_code'] ?? '') === 'ALREADY_CONFIRMED',
   'a second confirmation of the same cycle is ALREADY_CONFIRMED');
ok(($again['confirmed_by'] ?? 0) === $uid['hodS'], '  and it says who got there first');
$u = uuid();
$first  = confirm('hodS', ['event_uuid' => $u, 'entry_cycle_id' => $c2, 'occurred_at' => at('06:05')]);
$replay = confirm('hodS', ['event_uuid' => $u, 'entry_cycle_id' => $c2, 'occurred_at' => at('06:05')]);
ok($replay['receipt_status'] === 'ALREADY_RECEIVED', 'a replay of the same UUID is ALREADY_RECEIVED');
ok(($replay['server_event_id'] ?? 0) === ($first['server_event_id'] ?? -1), '  carrying the original record id');
ok((int) $pdo->query("SELECT COUNT(*) c FROM att_hod_arrivals WHERE entry_cycle_id=$c2")->fetch()['c'] === 1,
   '  and exactly one row exists for that cycle');

echo "3. A HOD confirmation is never a second payroll IN (blueprint §5)\n";
$evBefore = (int) $pdo->query("SELECT COUNT(*) c FROM att_events")->fetch()['c'];
$minBefore = $pdo->query("SELECT present_minutes FROM att_entry_cycles WHERE id=$c1")->fetch()['present_minutes'];
confirm('hodS', ['entry_cycle_id' => $c3, 'occurred_at' => at('07:20')]);
ok((int) $pdo->query("SELECT COUNT(*) c FROM att_events")->fetch()['c'] === $evBefore,
   'confirming writes NO gate event');
ok($pdo->query("SELECT present_minutes FROM att_entry_cycles WHERE id=$c1")->fetch()['present_minutes'] === $minBefore,
   'presence minutes are untouched — they come from the gate alone');
$times = $pdo->query("SELECT c.opened_at, h.occurred_at FROM att_entry_cycles c JOIN att_hod_arrivals h ON h.entry_cycle_id=c.id WHERE c.id=$c3")->fetch();
ok($times['opened_at'] !== $times['occurred_at'], 'gate IN time and HOD time are stored separately and differ');

echo "4. AT06 — a HOD is confined to their own department, by the server\n";
$pc = scan('in', $emp['P1'], '05:00')['entry_cycle_id'];
$x = confirm('hodS', ['entry_cycle_id' => $pc]);
ok($x['receipt_status'] === 'REJECTED', 'the Stitching HOD cannot confirm a Packing entry');
$ghost = confirm('hodS', ['entry_cycle_id' => 99999999]);
ok(($x['reason_code'] ?? 'a') === ($ghost['reason_code'] ?? 'b') && $x['reason_code'] === 'CYCLE_NOT_FOUND',
   '  AND GETS THE SAME ANSWER AS FOR A CYCLE THAT DOES NOT EXIST — ids cannot be used to probe');
ok((int) $pdo->query("SELECT COUNT(*) c FROM att_hod_arrivals WHERE entry_cycle_id=$pc")->fetch()['c'] === 0,
   '  and nothing was written');
ok(array_unique(array_column(H::pending([$dS]), 'department_id')) === [] || array_unique(array_column(H::pending([$dS]), 'department_id')) === [$dS],
   'the pending list holds only the HOD\'s department');
ok(H::pending([]) === [], 'scoped to nothing returns nothing, never everything');

$nb = confirm('nobody', ['entry_cycle_id' => $pc]);
ok($nb['receipt_status'] === 'REJECTED', 'a user with no HOD role confirms nothing');
$nd = confirm('hodNoDept', ['entry_cycle_id' => $pc]);
ok($nd['receipt_status'] === 'REJECTED', 'a "HOD of no department" confirms nothing — not everything');
ok(confirm('hodP', ['entry_cycle_id' => $pc])['receipt_status'] === 'ACCEPTED', 'the Packing HOD can');
$ac = scan('in', $emp['P2'], '05:00')['entry_cycle_id'];
ok(confirm('admin', ['entry_cycle_id' => $ac])['receipt_status'] === 'ACCEPTED', 'an admin can confirm any department');

echo "5. AT06 — bulk: each cycle once, each with its own answer\n";
$b1 = scan('in', $emp['S2'], '05:00')['entry_cycle_id'];
$b2 = scan('in', $emp['S3'], '05:00')['entry_cycle_id'];
$bp = scan('in', $emp['P1'], '08:00');   // P1 is still inside → rejected; open a clean one instead
scan('out', $emp['P2'], '06:00'); $bp = scan('in', $emp['P2'], '08:00')['entry_cycle_id'];
$ids   = [$b1, $b2, $c1, $bp, 88888888];           // 2 good, 1 already confirmed, 1 other dept, 1 nonexistent
$uuids = [uuid(), uuid(), uuid(), uuid(), uuid()];
$bulk = H::confirmBulk($ids, $uuids, $uid['hodS'], ['occurred_at' => at('05:10')]);
ok(($bulk['ok'] ?? false) === true, 'a mixed bulk request is processed, not thrown out whole');
$st = array_column($bulk['results'], 'receipt_status');
$rc = array_column($bulk['results'], 'reason_code');
ok($st[0] === 'ACCEPTED' && $st[1] === 'ACCEPTED', 'the two valid cycles are confirmed');
ok($rc[2] === 'ALREADY_CONFIRMED', 'the already-confirmed one says so');
ok($rc[3] === 'CYCLE_NOT_FOUND', 'THE OTHER DEPARTMENT\'S CYCLE IS REFUSED INSIDE A BULK REQUEST TOO');
ok($rc[4] === 'CYCLE_NOT_FOUND', 'and the nonexistent one');
ok(($bulk['summary']['ACCEPTED'] ?? 0) === 2 && ($bulk['summary']['REJECTED'] ?? 0) === 3, 'the summary adds up: 2 accepted, 3 rejected');
ok((int) $pdo->query("SELECT COUNT(*) c FROM att_hod_arrivals WHERE entry_cycle_id=$bp")->fetch()['c'] === 0,
   'nothing was written for the other department');

$rowsBefore = (int) $pdo->query("SELECT COUNT(*) c FROM att_hod_arrivals")->fetch()['c'];
$bulk2 = H::confirmBulk($ids, $uuids, $uid['hodS'], ['occurred_at' => at('05:10')]);
ok(array_unique(array_column($bulk2['results'], 'receipt_status')) === ['ALREADY_RECEIVED'],
   'replaying the whole bulk request returns ALREADY_RECEIVED for every item');
ok((int) $pdo->query("SELECT COUNT(*) c FROM att_hod_arrivals")->fetch()['c'] === $rowsBefore, '  and writes nothing new');
ok(($bulk2['results'][3]['reason_code'] ?? '') === 'CYCLE_NOT_FOUND', '  a replayed refusal is still a refusal');

echo "6. Bulk requests that are malformed are refused whole\n";
ok((H::confirmBulk([$b1, $b2], [uuid()], $uid['hodS'])['ok'] ?? true) === false, 'two cycles, one UUID → refused');
ok((H::confirmBulk([$b1, $b1], [uuid(), uuid()], $uid['hodS'])['ok'] ?? true) === false, 'the same cycle listed twice → refused');
$same = uuid();
ok((H::confirmBulk([$b1, $b2], [$same, $same], $uid['hodS'])['ok'] ?? true) === false,
   'ONE UUID REUSED FOR TWO CYCLES → refused (the second would have been answered with the first one\'s receipt)');
ok((H::confirmBulk([], [], $uid['hodS'])['ok'] ?? true) === false, 'an empty selection → refused');
ok((H::confirmBulk(range(1, H::BULK_MAX + 1), array_map(fn() => uuid(), range(1, H::BULK_MAX + 1)), $uid['hodS'])['ok'] ?? true) === false,
   'more than ' . H::BULK_MAX . ' at once → refused');

echo "7. Only the employee is known: one candidate → matched; none → waits\n";
$one = scan('in', $emp['S4'], '05:00')['entry_cycle_id'];
$m = confirm('hodS', ['employee_id' => $emp['S4'], 'occurred_at' => at('05:08')]);
ok($m['receipt_status'] === 'ACCEPTED' && ($m['entry_cycle_id'] ?? 0) === $one, 'exactly one unconfirmed entry nearby → confirmed');
ok(($m['match_method'] ?? '') === 'sequence', '  recorded as matched by sequence, not as if the HOD had named it');

$w = confirm('hodS', ['employee_id' => $emp['S5'], 'occurred_at' => at('05:00')]);
ok($w['receipt_status'] === 'PENDING_MATCH', 'NO GATE ENTRY YET → PENDING_MATCH (invariant 5), not rejected');
$row = $pdo->query("SELECT status, entry_cycle_id FROM att_hod_arrivals WHERE event_uuid='{$w['event_uuid']}'")->fetch();
ok($row['status'] === 'pending_match' && $row['entry_cycle_id'] === null, '  stored, unmatched, waiting');

/* An entry the HOD forgot hours ago is NOT a candidate for a confirmation now.
   Without the window a late tap would attach itself to a stale cycle. */
scan('in', $emp['S6'], '04:10'); scan('out', $emp['S6'], '04:30');
$stale = confirm('hodS', ['employee_id' => $emp['S6'], 'occurred_at' => at('11:00')]);
ok($stale['receipt_status'] === 'PENDING_MATCH',
   'an entry outside the match window (' . Policy::int('hod_match_window_minutes') . ' min) is not matched');

$oo = confirm('hodS', ['employee_id' => $emp['P1'], 'occurred_at' => at('05:00')]);
ok(($oo['reason_code'] ?? '') === 'EMPLOYEE_NOT_FOUND', 'another department\'s employee reads as "no such employee"');

echo "8. AT05 groundwork — two candidates are never guessed between\n";
$a1 = scan('in', $emp['S7'], '05:00')['entry_cycle_id']; scan('out', $emp['S7'], '05:30');
$a2 = scan('in', $emp['S7'], '06:00')['entry_cycle_id'];
$amb = confirm('hodS', ['employee_id' => $emp['S7'], 'occurred_at' => at('06:05')]);
ok($amb['receipt_status'] === 'NEEDS_REVIEW' && ($amb['reason_code'] ?? '') === 'AMBIGUOUS_MATCH',
   'TWO UNCONFIRMED ENTRIES → NEEDS_REVIEW / AMBIGUOUS_MATCH');
$linked = (int) $pdo->query("SELECT COUNT(*) c FROM att_hod_arrivals WHERE entry_cycle_id IN ($a1,$a2)")->fetch()['c'];
ok($linked === 0, '  AND NEITHER CYCLE WAS LINKED — the system did not pick one');
$arow = $pdo->query("SELECT status, ambiguity_reason FROM att_hod_arrivals WHERE event_uuid='{$amb['event_uuid']}'")->fetch();
ok($arow['status'] === 'ambiguous' && str_contains((string) $arow['ambiguity_reason'], (string) $a1) && str_contains((string) $arow['ambiguity_reason'], (string) $a2),
   '  the candidates are recorded for HR');
/* Naming the cycle removes the doubt, so the HOD can still resolve it. */
ok(confirm('hodS', ['entry_cycle_id' => $a2, 'occurred_at' => at('06:06')])['receipt_status'] === 'ACCEPTED',
   'naming the cycle explicitly still works');

echo "9. The device knew the gate event's UUID\n";
$gin = scan('in', $emp['S8'], '05:00');
$g = confirm('hodS', ['gate_event_uuid' => $gin['event_uuid'], 'occurred_at' => at('05:04')]);
ok($g['receipt_status'] === 'ACCEPTED' && ($g['match_method'] ?? '') === 'gate_event_uuid', 'a known gate UUID links directly');
$gp = confirm('hodP', ['gate_event_uuid' => $gin['event_uuid']]);
ok(($gp['reason_code'] ?? '') === 'CYCLE_NOT_FOUND', '  but not across departments');

/* S5 now gets a real entry, plus a confirmation that names a DIFFERENT gate
   event the server has never seen. There is exactly one candidate cycle — and
   it must NOT be taken, because the HOD's device said precisely which entry
   it meant and that one has not arrived. */
scan('in', $emp['S5'], '09:00');
$unknownGate = 'dddddddd-dddd-4ddd-8ddd-dddddddddddd';
$hint = confirm('hodS', ['employee_id' => $emp['S5'], 'gate_event_uuid' => $unknownGate, 'occurred_at' => at('09:05')]);
ok($hint['receipt_status'] === 'PENDING_MATCH' && ($hint['reason_code'] ?? '') === 'AWAITING_GATE_EVENT',
   'an UNSEEN gate UUID waits for that event — it does not settle for another entry that happens to be there');

echo "10. Devices: role is enforced in both directions\n";
$s2open = scan('in', $emp['S6'], '07:00')['entry_cycle_id'];
$wrong = confirm('hodS', ['entry_cycle_id' => $s2open, 'device_id' => $dev['Gate A']]);
ok(($wrong['reason_code'] ?? '') === 'DEVICE_ROLE_MISMATCH', 'a GATE handset cannot post HOD confirmations');
$gateOnHod = A::gateIn(['employee_id' => $emp['P1'], 'device_id' => $dev['HOD phone'], 'operator_id' => $uid['gk'],
                        'event_uuid' => uuid(), 'device_sequence' => 1, 'occurred_at' => at('10:00')]);
ok(($gateOnHod['reason_code'] ?? '') === 'DEVICE_ROLE_MISMATCH', 'A HOD HANDSET CANNOT CLOCK PEOPLE IN');
$lost = confirm('hodS', ['entry_cycle_id' => $s2open, 'device_id' => $dev['Lost HOD phone']]);
ok(($lost['reason_code'] ?? '') === 'DEVICE_REVOKED', 'a revoked HOD phone is refused');
$good = confirm('hodS', ['entry_cycle_id' => $s2open, 'device_id' => $dev['HOD phone'], 'device_sequence' => 1, 'occurred_at' => at('07:03')]);
ok($good['receipt_status'] === 'ACCEPTED', 'the registered HOD phone works');

echo "11. A HOD time earlier than the gate time is recorded, not hidden\n";
scan('out', $emp['S2'], '05:40'); $early = scan('in', $emp['S2'], '08:00')['entry_cycle_id'];
$e = confirm('hodS', ['entry_cycle_id' => $early, 'occurred_at' => at('07:55')]);
ok($e['receipt_status'] === 'ACCEPTED' && ($e['before_gate_in'] ?? false) === true,
   'accepted (the cycle was named) and flagged before_gate_in for whoever reads it');

echo "12. THE RACE — two HODs confirm the same entry at the same instant\n";
scan('out', $emp['S3'], '05:40'); $raceCycle = scan('in', $emp['S3'], '08:00')['entry_cycle_id'];
$runner = sys_get_temp_dir() . '/att_hod_race.php';
file_put_contents($runner, <<<'PHP'
<?php
[$_, $B, $cycle, $hod, $uuid, $dsn, $u, $p, $start] = $argv;
foreach (['Db','Time','Policy','Audit','Roles','Employees','Shifts','Receipts','AttendanceService','HodService'] as $c) require_once "$B/includes/att/$c.php";
Att\Db::bind(new PDO($dsn, $u, $p, [PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE=>PDO::FETCH_ASSOC, PDO::ATTR_EMULATE_PREPARES=>false]));
usleep((int) max(0, ((float) $start - microtime(true)) * 1000000));
$r = Att\HodService::confirm(['event_uuid'=>$uuid,'hod_user_id'=>(int)$hod,'entry_cycle_id'=>(int)$cycle]);
echo json_encode(['status'=>$r['receipt_status'],'reason'=>$r['reason_code'] ?? null]);
PHP);
$start = microtime(true) + 0.6;
$procs = []; $pipes = [];
foreach ([[$uid['hodS'], 'aaaaaaaa-1111-4111-8111-000000000001'], [$uid['admin'], 'bbbbbbbb-2222-4222-8222-000000000002']] as $i => [$who, $ru]) {
    $procs[$i] = proc_open(sprintf('exec php %s %s %d %d %s %s %s %s %.6f', escapeshellarg($runner), escapeshellarg(rtrim($B, '/')),
        $raceCycle, $who, escapeshellarg($ru), escapeshellarg($dsn), escapeshellarg($dbu), escapeshellarg($dbp), $start),
        [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes[$i]);
}
$out = [];
foreach ($procs as $i => $proc) {
    $out[$i] = json_decode(trim((string) stream_get_contents($pipes[$i][1])), true) ?: [];
    fclose($pipes[$i][1]); fclose($pipes[$i][2]); proc_close($proc);
}
@unlink($runner);
$statuses = array_column($out, 'status'); sort($statuses);
ok((int) $pdo->query("SELECT COUNT(*) c FROM att_hod_arrivals WHERE entry_cycle_id=$raceCycle")->fetch()['c'] === 1,
   'AFTER A GENUINE RACE THE CYCLE HAS EXACTLY ONE CONFIRMATION');
ok($statuses === ['ACCEPTED', 'REJECTED'], '  one was ACCEPTED and the other REJECTED, not a 500 (' . implode('/', $statuses) . ')');
ok(in_array('ALREADY_CONFIRMED', array_column($out, 'reason'), true), '  the loser was told ALREADY_CONFIRMED');

echo "13. Confirmations are audited; entry cycles report what they still owe\n";
$aud = (int) $pdo->query("SELECT COUNT(*) c FROM att_audit_logs WHERE action='hod.confirm'")->fetch()['c'];
$conf = (int) $pdo->query("SELECT COUNT(*) c FROM att_hod_arrivals WHERE status='confirmed'")->fetch()['c'];
ok($aud === $conf && $conf > 0, "every confirmation has an audit row ($aud of $conf)");
ok((int) $pdo->query("SELECT COUNT(*) c FROM att_audit_logs WHERE action='hod.ambiguous'")->fetch()['c'] === 1, 'the ambiguous one is audited too');
$cyc = A::entryCycles([$dS], $emp['S1']);
ok(count($cyc) === 3 && array_sum(array_column($cyc, 'hod_confirmation_due')) === 0,
   'S1\'s three cycles all show as confirmed');
ok(count(H::arrivals([$dP])) >= 1 && array_unique(array_column(H::arrivals([$dP]), 'department_id')) === [$dP],
   'the HOD history is department-scoped as well');

echo "\n$P passed, $F failed\n";
exit($F === 0 ? 0 : 1);
