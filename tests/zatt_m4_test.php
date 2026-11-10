<?php
declare(strict_types=1);

/* ATTENDANCE M4 (server) — sync, reconciliation, the HR queue.
 *
 * AT03  gate offline then HOD online reconciles correctly
 * AT04  HOD offline then gate online reconciles correctly
 * AT05  ambiguous offline match goes to HR, never silently guessed
 *
 * The case this file exists for is step 4. A gate phone holding two entries
 * for one person uploads them in two requests. If reconciliation ran after
 * the first request, a waiting HOD confirmation would see ONE candidate and
 * be linked to the wrong entry — a silent wrong guess caused by nothing but
 * arrival order. The test proves the server waits for the upload to finish.
 */

$B = __DIR__ . '/app_src/public_html/';
$P = 0; $F = 0;
function ok($c, $m) { global $P, $F; if ($c) { $P++; } else { $F++; echo "  FAIL: $m\n"; } }

$dsn = getenv('ATT_TEST_DSN') ?: 'mysql:host=127.0.0.1;port=3306;dbname=zas_att_test;charset=utf8mb4';
$dbu = getenv('ATT_TEST_USER') ?: 'zas';
$dbp = getenv('ATT_TEST_PASS'); if ($dbp === false) { $dbp = 'zaspw'; }

foreach (['Db','Time','Migrations','Policy','Audit','Roles','Employees','Shifts','Receipts','AttendanceService','HodService','Reconciler','SyncService','HrService'] as $c) {
    require_once $B . "includes/att/$c.php";
}

use Att\Db;
use Att\Employees;
use Att\HodService;
use Att\HrService as HR;
use Att\Migrations;
use Att\Policy;
use Att\Reconciler;
use Att\Roles;
use Att\Shifts;
use Att\SyncService as S;

try {
    $pdo = new PDO($dsn, $dbu, $dbp, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC, PDO::ATTR_EMULATE_PREPARES => false]);
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

$dS = Employees::createDepartment('Stitching');
$dP = Employees::createDepartment('Packing');
$day = Shifts::create(['name' => 'Day', 'start_time' => '09:00', 'end_time' => '17:00']);
Policy::set('default_shift_id', (string) $day, null, 'string'); Policy::flush();
$emp = [];
for ($i = 1; $i <= 14; $i++) { $emp["S$i"] = Employees::create(['code' => "S$i", 'full_name' => "Worker S$i", 'department_id' => $dS]); }
$emp['P1'] = Employees::create(['code' => 'P1', 'full_name' => 'Worker P1', 'department_id' => $dP]);

$uid = [];
foreach (['gk', 'gk2', 'hodS', 'admin', 'hr'] as $n) {
    $pdo->prepare("INSERT INTO users (name,email,password_hash) VALUES (?,?,'x')")->execute([$n, "$n@zas.test"]);
    $uid[$n] = (int) $pdo->lastInsertId();
}
Roles::grant($uid['hodS'], Roles::HOD, $dS);
Roles::grant($uid['admin'], Roles::ADMIN);
Roles::grant($uid['hr'], Roles::HR);

const GATE = 'aaaaaaaa-aaaa-4aaa-8aaa-aaaaaaaaaaaa';
const HODP = 'bbbbbbbb-bbbb-4bbb-8bbb-bbbbbbbbbbbb';

echo "1. Devices register themselves, once, with a scope the server chooses\n";
$g = S::registerDevice(GATE, 'Gate A', 'gate', $uid['gk']);
ok(($g['ok'] ?? false) && ($g['created'] ?? false) === true, 'a new phone is registered');
$g2 = S::registerDevice(GATE, 'Gate A', 'gate', $uid['gk']);
ok(($g2['device_id'] ?? 0) === $g['device_id'] && ($g2['created'] ?? true) === false, 'registering again returns the SAME device, not a second one');
$h = S::registerDevice(HODP, 'HOD phone', 'hod', $uid['hodS']);
$wide = S::registerDevice(HODP, 'HOD phone', 'gate', $uid['hodS']);
ok(($wide['role_scope'] ?? '') === 'both', 'a phone used for both jobs widens to "both"; it never flips');
Db::run("UPDATE att_devices SET role_scope='hod' WHERE id=?", [$h['device_id']]);
ok((S::registerDevice('not-a-uuid', 'x', 'gate', $uid['gk'])['ok'] ?? true) === false, 'a malformed device id is refused');
Policy::set('device_self_register', '0', null, 'bool'); Policy::flush();
ok((S::registerDevice('cccccccc-cccc-4ccc-8ccc-cccccccccccc', 'New', 'gate', $uid['gk'])['reason_code'] ?? '') === 'DEVICE_NOT_REGISTERED',
   'with self-registration switched off, an unknown phone is refused');
Policy::set('device_self_register', '1', null, 'bool'); Policy::flush();

$gs = 0; $hs = 0; $n = 0;
function uuid(): string { global $n; return sprintf('%08x-0000-4000-8000-%012x', random_int(0, 0xffffffff), ++$n); }
/** A gate envelope as a phone would send it. */
function gate(string $type, string $code, string $hm, array $over = []): array {
    global $gs;
    return array_merge(['event_uuid' => uuid(), 'device_id' => GATE, 'device_sequence' => ++$gs, 'employee_code' => $code,
                        'event_type' => $type, 'occurred_at' => "2026-03-02T$hm:00Z", 'timezone' => 'UTC', 'payload' => []], $over);
}
function hod(string $code, string $hm, array $payload = [], array $over = []): array {
    global $hs;
    return array_merge(['event_uuid' => uuid(), 'device_id' => HODP, 'device_sequence' => ++$hs, 'employee_code' => $code,
                        'event_type' => 'HOD_CONFIRM', 'occurred_at' => "2026-03-02T$hm:00Z", 'timezone' => 'UTC', 'payload' => $payload], $over);
}
function arrival(string $uuid): array {
    return Db::one('SELECT status, entry_cycle_id, match_method, ambiguity_reason FROM att_hod_arrivals WHERE event_uuid = ?', [$uuid]) ?? [];
}

echo "2. AT03 — gate offline, HOD online\n";
/* The HOD sees the worker at 05:05 and confirms. The gate phone that let them
   in at 05:00 has no signal, so the server has never heard of the entry. */
$h1 = hod('S1', '05:05');
$r = S::process($h1, $uid['hodS']);
ok($r['receipt_status'] === 'PENDING_MATCH', 'the confirmation waits: PENDING_MATCH, not rejected');
$up = S::processBatch([gate('GATE_IN', 'S1', '05:00')], $uid['gk'], false);
ok($up['receipts'][0]['receipt_status'] === 'ACCEPTED', 'the gate phone comes back and its entry is accepted');
ok(($up['reconciled']['linked'] ?? 0) === 1, 'and the waiting confirmation is matched to it');
$a = arrival($h1['event_uuid']);
ok($a['status'] === 'confirmed' && (int) $a['entry_cycle_id'] === (int) $up['receipts'][0]['entry_cycle_id'], '  linked to exactly that entry');
ok($a['match_method'] === 'sequence', '  recorded as matched by sequence');
ok((int) Db::value('SELECT provisional FROM att_events WHERE event_uuid = ?', [$up['receipts'][0]['event_uuid']]) === 1,
   'the late gate event is marked provisional (it sat in an outbox)');

echo "3. AT04 — HOD offline, gate online\n";
$gin = S::process(gate('GATE_IN', 'S2', '06:00'), $uid['gk']);
ok($gin['receipt_status'] === 'ACCEPTED', 'the gate records the entry live');
$hb = S::processBatch([hod('S2', '06:04')], $uid['hodS'], false);
ok($hb['receipts'][0]['receipt_status'] === 'ACCEPTED' && (int) $hb['receipts'][0]['entry_cycle_id'] === (int) $gin['entry_cycle_id'],
   'the HOD phone syncs later and its confirmation lands on that entry');
$gin3 = S::process(gate('GATE_IN', 'S3', '06:00'), $uid['gk']);
$named = S::processBatch([hod('S3', '06:03', ['entry_cycle_id' => $gin3['entry_cycle_id']])], $uid['hodS'], false);
ok(($named['receipts'][0]['match_method'] ?? '') === 'explicit_cycle', 'an offline HOD who had the entry on screen names it: explicit');
$gin4 = S::process(gate('GATE_IN', 'S4', '06:00'), $uid['gk']);
$hinted = S::processBatch([hod('S4', '06:03', ['gate_event_uuid' => $gin4['event_uuid']])], $uid['hodS'], false);
ok(($hinted['receipts'][0]['match_method'] ?? '') === 'gate_event_uuid', 'or names the gate event it saw');

echo "4. AT05 — two entries, one waiting confirmation: HR decides, the system does not\n";
$h5 = hod('S5', '06:05');
S::process($h5, $uid['hodS']);
/* THE DANGEROUS ORDER. The gate phone uploads in two requests. After the first
   there is exactly one candidate entry on the server. */
$first = S::processBatch([gate('GATE_IN', 'S5', '05:00')], $uid['gk'], true);     // more = true
ok(!isset($first['reconciled']), 'while more of the upload is coming, reconciliation does not run');
ok(arrival($h5['event_uuid'])['status'] === 'pending_match',
   'THE CONFIRMATION IS NOT LINKED TO THE FIRST ENTRY JUST BECAUSE IT ARRIVED FIRST');
$rest = S::processBatch([gate('GATE_OUT', 'S5', '05:30'), gate('GATE_IN', 'S5', '06:00')], $uid['gk'], false);
ok(($rest['reconciled']['ambiguous'] ?? 0) === 1, 'once the upload is complete there are two candidates → ambiguous');
$a5 = arrival($h5['event_uuid']);
ok($a5['status'] === 'ambiguous' && $a5['entry_cycle_id'] === null, 'AND NEITHER ENTRY WAS LINKED');
$q = HR::queue();
$mine = array_values(array_filter($q['ambiguous'], fn($x) => $x['event_uuid'] === $h5['event_uuid']));
ok(count($mine) === 1 && count($mine[0]['candidates']) === 2, 'it is in the HR queue with both candidate entries listed');

echo "5. Order inside an upload does not matter; a replayed upload changes nothing\n";
$in = gate('GATE_IN', 'S6', '05:00'); $out = gate('GATE_OUT', 'S6', '05:40');
$b = S::processBatch([$out, $in], $uid['gk'], false);                              // OUT listed before IN
ok(array_column($b['receipts'], 'receipt_status') === ['ACCEPTED', 'ACCEPTED'],
   'an OUT listed before its IN is still applied in device-sequence order');
$events = (int) Db::value('SELECT COUNT(*) FROM att_events');
$b2 = S::processBatch([$out, $in], $uid['gk'], false);
ok(array_unique(array_column($b2['receipts'], 'receipt_status')) === ['ALREADY_RECEIVED'], 'the same upload again → ALREADY_RECEIVED');
ok((int) Db::value('SELECT COUNT(*) FROM att_events') === $events, '  and no event is stored twice');

echo "6. A confirmation that named its gate event waits for exactly that event\n";
$gateEv = gate('GATE_IN', 'S7', '05:00');
$h7 = hod('S7', '05:02', ['gate_event_uuid' => $gateEv['event_uuid']]);
ok(S::process($h7, $uid['hodS'])['receipt_status'] === 'PENDING_MATCH', 'the named gate event has not arrived: waits');
$late = S::processBatch([$gateEv], $uid['gk'], false);
ok(arrival($h7['event_uuid'])['match_method'] === 'gate_event_uuid', 'when it arrives, it is linked by that UUID');

echo "7. Two waiting confirmations for one entry: neither is chosen\n";
$x1 = hod('S8', '05:05'); $x2 = hod('S8', '05:06', [], ['device_id' => '', 'device_sequence' => null]);
S::process($x1, $uid['hodS']); S::process($x2, $uid['admin']);
S::processBatch([gate('GATE_IN', 'S8', '05:00')], $uid['gk'], false);
ok(arrival($x1['event_uuid'])['status'] === 'ambiguous' && arrival($x2['event_uuid'])['status'] === 'ambiguous',
   'both go to HR; the entry stays unconfirmed');

echo "8. Confirmed by someone else while it waited: disputed, not dropped\n";
$g9 = gate('GATE_IN', 'S9', '05:00');
$h9 = hod('S9', '05:02', ['gate_event_uuid' => $g9['event_uuid']]);
S::process($h9, $uid['hodS']);
$c9 = S::processBatch([$g9], $uid['gk'], true)['receipts'][0]['entry_cycle_id'];
HodService::confirm(['event_uuid' => uuid(), 'hod_user_id' => $uid['admin'], 'entry_cycle_id' => $c9]);
$rec = Reconciler::run(null);
ok(($rec['disputed'] ?? 0) === 1 && arrival($h9['event_uuid'])['status'] === 'disputed', 'the late one is marked disputed and kept for HR');

echo "9. HR settles a confirmation — with a reason, appended, never overwritten\n";
$arrId = (int) $mine[0]['id'];
$cands = array_column($mine[0]['candidates'], 'entry_cycle_id');
ok((HR::resolveArrival($arrId, 'link', (int) $cands[1], '', $uid['hr'])['reason_code'] ?? '') === 'REASON_REQUIRED', 'no reason → refused');
$p1c = S::process(gate('GATE_IN', 'P1', '05:00'), $uid['gk'])['entry_cycle_id'];
ok((HR::resolveArrival($arrId, 'link', (int) $p1c, 'testing', $uid['hr'])['reason_code'] ?? '') === 'WRONG_EMPLOYEE',
   'EVEN HR CANNOT ATTACH IT TO ANOTHER EMPLOYEE\'S ENTRY');
$res = HR::resolveArrival($arrId, 'link', (int) $cands[1], 'HOD confirmed by phone it was the second entry', $uid['hr']);
ok(($res['ok'] ?? false) === true, 'HR links it to the second entry');
$a5 = arrival($h5['event_uuid']);
ok($a5['status'] === 'confirmed' && $a5['match_method'] === 'hr_manual', '  recorded as an HR decision, not a system match');
ok((HR::resolveArrival($arrId, 'dismiss', null, 'again', $uid['hr'])['reason_code'] ?? '') === 'ALREADY_SETTLED', 'a settled item cannot be settled twice');
$corr = Db::one("SELECT reason, actor_id FROM att_corrections WHERE subject_type='att_hod_arrival' AND subject_id=? AND action='hod.link'", [$arrId]);
ok($corr !== null && (int) $corr['actor_id'] === $uid['hr'] && str_contains($corr['reason'], 'second entry'), 'the decision is in att_corrections with who and why');
$dis = (int) Db::value("SELECT id FROM att_hod_arrivals WHERE event_uuid = ?", [$x2['event_uuid']]);
ok((HR::resolveArrival($dis, 'dismiss', null, 'duplicate of the HOD\'s own confirmation', $uid['hr'])['status'] ?? '') === 'dismissed', 'HR can set one aside');
ok((int) Db::value('SELECT COUNT(*) FROM att_hod_arrivals WHERE id = ?', [$dis]) === 1, '  dismissed, NOT deleted');

echo "10. A gate scan held for review can be re-run once the cause is fixed\n";
/* 14:00 UTC is 19:00 Karachi: no shift covers it, so the scan is held. */
$held = gate('GATE_IN', 'S10', '14:00');
$hr1 = S::process($held, $uid['gk']);
ok($hr1['receipt_status'] === 'NEEDS_REVIEW' && $hr1['reason_code'] === 'NO_SHIFT_INSTANCE', 'an out-of-hours scan is held');
$hq = array_values(array_filter(HR::queue()['held_events'], fn($x) => $x['event_uuid'] === $held['event_uuid']));
ok(count($hq) === 1 && (int) $hq[0]['can_retry'] === 1 && $hq[0]['employee_code'] === 'S10', 'it is in the HR queue, with who and when, and can be re-run');
$early = HR::reviewEvent($held['event_uuid'], 'retry', 'trying before fixing', $uid['hr']);
ok(($early['ok'] ?? true) === false, 'retrying before the cause is fixed fails');
ok(count(array_filter(HR::queue()['held_events'], fn($x) => $x['event_uuid'] === $held['event_uuid'])) === 1, '  and the event is STILL in the queue, not lost');
$eve = Shifts::create(['name' => 'Evening', 'start_time' => '17:00', 'end_time' => '23:00']);
Shifts::assign($emp['S10'], $eve, '2026-03-02', 'assigned', $uid['hr']);
$retry = HR::reviewEvent($held['event_uuid'], 'retry', 'evening shift was missing from the roster; assigned', $uid['hr']);
ok(($retry['ok'] ?? false) === true && ($retry['receipt']['receipt_status'] ?? '') === 'ACCEPTED', 'after HR assigns the shift, the retry is ACCEPTED');
ok((int) Db::value('SELECT operator_id FROM att_events WHERE event_uuid = ?', [$held['event_uuid']]) === $uid['gk'],
   'THE EVENT IS FILED UNDER THE ORIGINAL GATEKEEPER — HR fixed paperwork, HR was not at the gate');
ok((HR::reviewEvent($held['event_uuid'], 'retry', 'again', $uid['hr'])['reason_code'] ?? '') === 'NOT_REVIEWABLE', 'it cannot be re-run a second time');
$acc = gate('GATE_IN', 'S11', '05:00'); S::process($acc, $uid['gk']);
ok((S::retryReview($acc['event_uuid'], $uid['hr'], 'x')['reason_code'] ?? '') === 'NOT_REVIEWABLE', 'AN ACCEPTED EVENT CANNOT BE "RETRIED" — settled decisions stay settled');
$held2 = gate('GATE_IN', 'S12', '14:00'); S::process($held2, $uid['gk']);
ok((HR::reviewEvent($held2['event_uuid'], 'dismiss', 'test scan by the gatekeeper', $uid['hr'])['ok'] ?? false) === true, 'or HR dismisses it with a reason');

echo "11. A forgotten exit: HR closes the entry, and no fake gate event is invented\n";
$fc = S::process(gate('GATE_IN', 'S13', '05:00'), $uid['gk'])['entry_cycle_id'];
ok(S::process(gate('GATE_IN', 'S13', '07:00'), $uid['gk'])['reason_code'] === 'ALREADY_INSIDE', 'until it is closed, the next IN is refused');
ok(count(array_filter(HR::queue()['open_too_long'], fn($x) => (int) $x['entry_cycle_id'] === (int) $fc)) === 1, 'the open entry is listed for HR');
$t = fn(string $hm) => new DateTimeImmutable("2026-03-02 $hm:00", new DateTimeZone('UTC'));
ok((HR::closeCycle($fc, $t('06:30'), '', $uid['hr'])['reason_code'] ?? '') === 'REASON_REQUIRED', 'no reason → refused');
ok((HR::closeCycle($fc, $t('04:00'), 'x', $uid['hr'])['reason_code'] ?? '') === 'VALIDATION', 'an exit before the entry → refused');
ok((HR::closeCycle($fc, new DateTimeImmutable('+2 days', new DateTimeZone('UTC')), 'x', $uid['hr'])['reason_code'] ?? '') === 'VALIDATION', 'an exit in the future → refused');
$evBefore = (int) Db::value('SELECT COUNT(*) FROM att_events');
$cl = HR::closeCycle($fc, $t('06:30'), 'left at 12:30 per supervisor; forgot to scan', $uid['hr']);
ok(($cl['ok'] ?? false) && $cl['present_minutes'] === 90, 'closed at the stated time: 90 minutes');
$row = Db::one('SELECT close_source, gate_out_event_id, correction_id FROM att_entry_cycles WHERE id = ?', [$fc]);
ok($row['close_source'] === 'hr_correction' && $row['gate_out_event_id'] === null && (int) $row['correction_id'] > 0,
   'marked hr_correction, linked to the correction, with NO gate-out event');
ok((int) Db::value('SELECT COUNT(*) FROM att_events') === $evBefore, 'NO GATE EVENT WAS CREATED — the movement history shows only what the gate saw');
ok((HR::closeCycle($fc, $t('06:40'), 'again', $uid['hr'])['reason_code'] ?? '') === 'ALREADY_CLOSED', 'it cannot be closed twice');
ok(S::process(gate('GATE_IN', 'S13', '07:00'), $uid['gk'])['receipt_status'] === 'ACCEPTED', 'and the employee can now come in again');

echo "12. Who did it: the uploader is recorded, and a mismatch is said out loud\n";
$mm = gate('GATE_IN', 'S14', '05:00', ['actor_user_id' => $uid['gk']]);
$mr = S::process($mm, $uid['gk2']);          // captured under gk, uploaded under gk2
ok($mr['receipt_status'] === 'ACCEPTED' && ($mr['actor_mismatch'] ?? false) === true, 'accepted, and flagged actor_mismatch');
ok((int) Db::value('SELECT operator_id FROM att_events WHERE event_uuid = ?', [$mm['event_uuid']]) === $uid['gk2'],
   'the operator on record is the signed-in uploader, never the id the phone claimed');
ok((int) Db::value("SELECT COUNT(*) FROM att_audit_logs WHERE action='sync.actor_mismatch'") === 1, 'the difference is in the audit log');

echo "13. Permissions inside an upload; limits; unknown devices\n";
$fb = gate('GATE_OUT', 'S14', '06:00');
$nf = S::processBatch([$fb], $uid['hodS'], false, ['HOD_CONFIRM']);
ok($nf['receipts'][0]['receipt_status'] === 'RETRY' && $nf['receipts'][0]['reason_code'] === 'FORBIDDEN', 'a HOD-only login sending a gate event → RETRY/FORBIDDEN');
ok(Db::one('SELECT 1 FROM att_sync_receipts WHERE event_uuid = ?', [$fb['event_uuid']]) === null,
   'AND IT IS NOT STORED AS A FINAL REJECTION — the scan is not lost for good');
ok(S::processBatch([$fb], $uid['gk'], false, ['GATE_IN', 'GATE_OUT'])['receipts'][0]['receipt_status'] === 'ACCEPTED', 'sent later under a permitted login, it is accepted');
Policy::set('sync_batch_max', '2', null, 'int'); Policy::flush();
ok((S::processBatch([gate('GATE_IN','S1','09:00'), gate('GATE_IN','S2','09:00'), gate('GATE_IN','S3','09:00')], $uid['gk'])['reason_code'] ?? '') === 'BATCH_TOO_LARGE', 'an oversized upload is refused whole');
Policy::set('sync_batch_max', '200', null, 'int'); Policy::flush();
ok(S::process(gate('GATE_IN', 'S1', '10:00', ['device_id' => 'dddddddd-dddd-4ddd-8ddd-dddddddddddd']), $uid['gk'])['reason_code'] === 'UNKNOWN_DEVICE', 'an unregistered phone is refused');
ok(S::process(gate('SOMETHING', 'S1', '10:00'), $uid['gk'])['reason_code'] === 'BAD_EVENT_TYPE', 'an unknown event type is refused');

echo "14. A withdrawn phone: stopped at once, and cannot re-register\n";
ok(S::revokeDevice((int) $g['device_id'], $uid['admin'], 'reported lost') === true, 'admin withdraws the gate phone');
ok(S::process(gate('GATE_OUT', 'S13', '08:00'), $uid['gk'])['reason_code'] === 'DEVICE_REVOKED', 'its very next event is refused');
ok((S::registerDevice(GATE, 'Gate A', 'gate', $uid['gk'])['reason_code'] ?? '') === 'DEVICE_REVOKED', 'RE-REGISTERING IS NOT A WAY BACK IN');

echo "15. What a phone is told: status and changes\n";
$st = S::status(S::resolveDevice(HODP), $uid['hodS']);
ok(($st['device']['role_scope'] ?? '') === 'hod' && $st['device']['last_sequence'] >= 1 && isset($st['server_epoch_ms']), 'status reports the device, its last sequence and the server clock');
$ch = S::changes(null, $uid['hodS']);
$st5 = array_values(array_filter($ch['confirmations'], fn($c) => $c['event_uuid'] === $h5['event_uuid']));
ok(count($st5) === 1 && $st5[0]['status'] === 'confirmed', 'changes tell the HOD phone what became of a confirmation that was waiting');
ok(isset($ch['cursor']) && is_array($ch['pending']), 'with a cursor and the current pending list');
ok(S::changes($ch['cursor'], $uid['hodS'])['confirmations'] === [], 'nothing new since the cursor → nothing returned');

echo "\n$P passed, $F failed\n";
exit($F === 0 ? 0 : 1);
