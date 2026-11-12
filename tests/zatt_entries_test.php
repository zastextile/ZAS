<?php
declare(strict_types=1);

/* ATTENDANCE — THE ADMINISTRATOR ADDS, EDITS AND DELETES ENTRIES
 *
 * Owner's decision: the administrator may change attendance. What must hold:
 *   - every change needs a reason and is kept with the administrator's name
 *   - the gate's own scans are never altered; an edited time sits beside them
 *   - a deleted entry leaves attendance and every report, a copy stays
 *   - never two entries for one person at once; nothing in the future
 *   - administrator only, over the real web API
 */

$B = __DIR__ . '/app_src/public_html/';
$P = 0; $F = 0;
function ok($c, $m) { global $P, $F; if ($c) { $P++; } else { $F++; echo "  FAIL: $m\n"; } }

$dsn = getenv('ATT_TEST_DSN') ?: 'mysql:host=127.0.0.1;port=3306;dbname=zas_att_test;charset=utf8mb4';
$dbu = getenv('ATT_TEST_USER') ?: 'zas';
$dbp = getenv('ATT_TEST_PASS'); if ($dbp === false) { $dbp = 'zaspw'; }
if (!is_file($B . 'config/config.php')) { echo "SKIP: no test config — run tests/run_att.sh\n"; exit(0); }
foreach (['Db','Time','Migrations','Policy','Audit','Roles','Employees','Shifts','Receipts','AttendanceService','HodService','Reconciler',
          'SyncService','OvertimeService','ReportService','HrService','AdminService','EntryAdmin'] as $c) {
    require_once $B . "includes/att/$c.php";
}
use Att\Db;
use Att\EntryAdmin as E;
use Att\Employees;
use Att\HodService;
use Att\Migrations;
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
$pdo->prepare("INSERT INTO zu_perm (user_id, module, actions) VALUES (?, 'atthr', 'vud'), (?, 'attadm', 'vcud')")->execute([$hr, $hr]);
$dS = Employees::createDepartment('Stitching');
$emp = [];
foreach (range(1, 6) as $i) { $c = sprintf('E%03d', $i); $emp[$c] = Employees::create(['code' => $c, 'full_name' => "Worker $i", 'department_id' => $dS]); }
Roles::grant($gk, Roles::GATEKEEPER);
Roles::grant($hod, Roles::HOD, $dS);
Roles::grant($hr, Roles::HR);
$day = Shifts::create(['name' => 'Day', 'start_time' => '08:00', 'end_time' => '18:00', 'grace_in_minutes' => 10, 'grace_out_minutes' => 10]);
Policy::set('default_shift_id', (string) $day, null, 'string'); Policy::flush();
const GATE = 'aaaaaaaa-aaaa-4aaa-8aaa-aaaaaaaaaaaa';
S::registerDevice(GATE, 'Main gate', 'gate', $gk);
$seq = 0; $un = 0;
$uuid = function () use (&$un): string { return sprintf('%08x-0000-4000-8000-%012x', random_int(0, 0xffffffff), ++$un); };
$scan = function (string $type, string $code, string $hm, string $date = '2026-03-02') use (&$seq, $uuid, $gk): array {
    return S::process(['event_uuid' => $uuid(), 'device_id' => GATE, 'device_sequence' => ++$seq, 'employee_code' => $code, 'event_type' => $type,
        'occurred_at' => "{$date}T{$hm}:00Z", 'timezone' => 'UTC', 'payload' => []], $gk, true);
};
$k = fn(string $local) => new DateTimeImmutable($local, new DateTimeZone('Asia/Karachi'));   // factory time
$cyc = fn(string $code) => Db::one('SELECT * FROM att_entry_cycles WHERE employee_id = ? ORDER BY id DESC LIMIT 1', [$emp[$code]]);
Policy::set('auto_out_enabled', '0', null, 'bool'); Policy::flush();     // this test's day is in the past

echo "1. Edit a scanned entry\n";
$scan('GATE_IN', 'E001', '03:00'); $scan('GATE_OUT', 'E001', '12:00');      // 08:00 – 17:00
$c1 = $cyc('E001');
ok((E::edit((int) $c1['id'], null, $k('2026-03-02 17:30'), '', $admin)['reason_code'] ?? '') === 'REASON_REQUIRED', 'no change without a reason');
ok((E::edit((int) $c1['id'], null, $k('2026-03-02 07:00'), 'x', $admin)['reason_code'] ?? '') === 'VALIDATION', 'OUT before IN is refused');
ok((E::edit((int) $c1['id'], null, new DateTimeImmutable('+2 days'), 'x', $admin)['reason_code'] ?? '') === 'VALIDATION', 'a time in the future is refused');
$ed = E::edit((int) $c1['id'], null, $k('2026-03-02 17:30'), 'Left at 17:30, guard confirmed', $admin);
$c1b = $cyc('E001');
ok($ed['ok'] && (int) $c1b['present_minutes'] === 570 && $c1b['close_source'] === 'admin' && (int) $c1b['edited_by'] === $admin, 'OUT MOVED TO 17:30: 570 minutes, marked as changed by the administrator');
ok(str_starts_with((string) Db::value("SELECT occurred_at FROM att_events WHERE event_type = 'GATE_OUT' AND employee_id = ?", [$emp['E001']]), '2026-03-02 12:00'),
   '  THE GATE SCAN STILL SAYS 17:00 — the record of what the gate saw is not altered');
$corr = Db::one("SELECT * FROM att_corrections WHERE action = 'admin.entry_edit' AND subject_id = ?", [(int) $c1['id']]);
ok($corr !== null && str_contains((string) $corr['old_data'], '12:00:00') && str_contains((string) $corr['new_data'], '12:30:00') && (int) $corr['actor_id'] === $admin && $corr['reason'] === 'Left at 17:30, guard confirmed',
   '  the change is kept: before, after, reason, administrator');
$mv = R::movements(['from' => '2026-03-02', 'to' => '2026-03-02', 'employee' => 'E001'], null);
$out = $mv['rows'][1] ?? [];
ok(str_contains((string) $out['occurred_at'], 'T17:30') && $out['edited'] === true && str_contains((string) $out['scanned_at'], 'T17:00'), 'movements show 17:30, "corrected", with the scanned 17:00 beside it');

echo "2. Add an entry for someone who was not scanned\n";
$add = E::add('E002', $k('2026-03-02 08:15'), $k('2026-03-02 18:00'), 'Came in by the side gate, no phone there', $admin);
$c2 = $cyc('E002');
ok($add['ok'] && $c2['in_source'] === 'admin' && $c2['gate_in_event_id'] === null && (int) $c2['present_minutes'] === 585, 'ADDED: 08:15 – 18:00, 585 minutes, marked as added, no gate scan invented');
ok($n('SELECT COUNT(*) FROM att_events WHERE employee_id = ?', [$emp['E002']]) === 0, '  no gate event was written for it');
$att = array_column(R::attendance(['from' => '2026-03-02', 'to' => '2026-03-02'], null)['rows'], null, 'employee_code');
ok(($att['E002']['present_minutes'] ?? 0) === 585 && ($att['E001']['present_minutes'] ?? 0) === 570, 'the attendance report uses the added and the edited times');
$mv2 = array_column(R::movements(['from' => '2026-03-02', 'to' => '2026-03-02', 'employee' => 'E002'], null)['rows'], 'event_type');
ok($mv2 === ['ADMIN_IN', 'FIXED_OUT'], 'movements list it as "IN · added" and "OUT · corrected" (' . implode(',', $mv2) . ')');
ok((E::add('E001', $k('2026-03-02 09:00'), $k('2026-03-02 10:00'), 'x', $admin)['reason_code'] ?? '') === 'OVERLAP', 'an entry overlapping another of the same person is refused');
ok((E::add('E999', $k('2026-03-02 09:00'), null, 'x', $admin)['reason_code'] ?? '') === 'EMPLOYEE_NOT_FOUND', 'an unknown code is refused');
$open = E::add('E003', $k('2026-03-02 08:00'), null, 'Phone was dead at the gate', $admin);
ok($open['ok'] && $cyc('E003')['closed_at'] === null, 'an entry with no OUT is "still inside"');
ok((E::add('E003', $k('2026-03-02 09:00'), null, 'x', $admin)['reason_code'] ?? '') === 'OVERLAP', '  and a second open one for the same person is refused');
$o3 = $scan('GATE_OUT', 'E003', '12:00');
ok(($o3['receipt_status'] ?? '') === 'ACCEPTED' && $cyc('E003')['close_source'] === 'gate', '  the gate\'s OUT scan then closes it as normal');

echo "3. Delete an entry\n";
$scan('GATE_IN', 'E004', '03:00');
$c4 = $cyc('E004');
HodService::confirm(['event_uuid' => $uuid(), 'hod_user_id' => $hod, 'entry_cycle_id' => (int) $c4['id'], 'employee_id' => $emp['E004'], 'device_time' => '2026-03-02T03:20:00Z', 'timezone' => 'UTC']);
ok($n("SELECT COUNT(*) FROM att_hod_arrivals WHERE entry_cycle_id = ? AND status = 'confirmed'", [(int) $c4['id']]) === 1, '(E004 scanned IN — the wrong person — and confirmed by the HOD)');
ok((E::delete((int) $c4['id'], '', $admin)['reason_code'] ?? '') === 'REASON_REQUIRED', 'no delete without a reason');
$del = E::delete((int) $c4['id'], 'Gatekeeper scanned the wrong card', $admin);
ok($del['ok'] && $n('SELECT COUNT(*) FROM att_entry_cycles WHERE id = ?', [(int) $c4['id']]) === 0, 'DELETED: the entry is gone from attendance');
ok($n("SELECT COUNT(*) FROM att_hod_arrivals WHERE employee_id = ? AND status = 'dismissed' AND entry_cycle_id IS NULL", [$emp['E004']]) === 1, '  its HOD confirmation is set aside, with the reason');
ok($n('SELECT COUNT(*) FROM att_events WHERE employee_id = ?', [$emp['E004']]) === 1, '  the gate scan stays on record');
$copy = Db::one("SELECT old_data FROM att_corrections WHERE action = 'admin.entry_delete' AND subject_id = ?", [(int) $c4['id']]);
$cp = json_decode((string) ($copy['old_data'] ?? ''), true) ?: [];
ok(($cp['employee_code'] ?? '') === 'E004' && (int) ($cp['entry']['id'] ?? 0) === (int) $c4['id'] && count($cp['hod_arrivals'] ?? []) === 1, '  and a full copy is kept — entry, HOD confirmation — so it can be put back by hand');
ok(R::movements(['from' => '2026-03-02', 'to' => '2026-03-02', 'employee' => 'E004'], null)['rows'] === [], '  it is gone from the movements report');
$a4 = array_column(R::attendance(['from' => '2026-03-02', 'to' => '2026-03-02'], null)['rows'], null, 'employee_code');
ok(($a4['E004']['entries'] ?? 1) === 0, '  and from the attendance report');
ok(\Att\AttendanceService::movements(null, $emp['E004']) === [], '  and from the HOD\'s movement list');
ok((E::delete((int) $c4['id'], 'again', $admin)['reason_code'] ?? '') === 'NOT_FOUND', 'deleting it twice says it is already gone');
$re = $scan('GATE_IN', 'E004', '05:00');
ok(($re['receipt_status'] ?? '') === 'ACCEPTED', 'the right person can then be scanned IN as normal');
$list = E::list('2026-03-02', '');
ok($list['ok'] && count($list['entries']) === 4, 'the day\'s list shows the four entries now on record (' . count($list['entries']) . ')');

echo "4. Administrator only, over the web\n";
$port = 8737;
$srv = proc_open(sprintf('exec php -S 127.0.0.1:%d -t %s', $port, escapeshellarg(rtrim($B, '/'))), [1 => ['file', '/dev/null', 'a'], 2 => ['file', '/dev/null', 'a']], $pipes);
register_shutdown_function(static function () use ($srv) { if (is_resource($srv)) { proc_terminate($srv, 9); proc_close($srv); } });
for ($i = 0; $i < 60; $i++) { $cc = @fsockopen('127.0.0.1', $port, $e1, $e2, 0.2); if ($cc) { fclose($cc); break; } usleep(200000); }
function sess(string $email): array
{
    global $port;
    $jar = tempnam(sys_get_temp_dir(), 'attent');
    $ch = curl_init("http://127.0.0.1:$port/api/v1/index.php?route=%2Fauth%2Flogin");
    curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_COOKIEJAR => $jar, CURLOPT_COOKIEFILE => $jar, CURLOPT_POST => true,
        CURLOPT_POSTFIELDS => json_encode(['email' => $email, 'password' => 'CorrectHorse9']), CURLOPT_HTTPHEADER => ['Content-Type: application/json']]);
    $b = json_decode((string) curl_exec($ch), true); curl_close($ch);
    return [$jar, (string) ($b['csrf_token'] ?? '')];
}
function req(array $s, string $method, string $route, ?array $body = null, bool $csrf = true): array
{
    global $port;
    [$path, $qs] = array_pad(explode('?', $route, 2), 2, '');
    $ch = curl_init("http://127.0.0.1:$port/api/v1/index.php?route=" . rawurlencode($path) . ($qs !== '' ? "&$qs" : ''));
    $h = ['Content-Type: application/json'];
    if ($csrf) { $h[] = 'X-CSRF-Token: ' . $s[1]; }
    curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_COOKIEFILE => $s[0], CURLOPT_CUSTOMREQUEST => $method, CURLOPT_HTTPHEADER => $h]);
    if ($body !== null) { curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($body)); }
    $raw = (string) curl_exec($ch); $code = curl_getinfo($ch, CURLINFO_HTTP_CODE); curl_close($ch);
    return ['status' => $code, 'body' => json_decode($raw, true) ?? []];
}
$as = sess('admin@zas.test');
$hs = sess('hr@zas.test');
ok(req($hs, 'GET', '/admin/entries?date=2026-03-02')['status'] === 403, 'HR — even with every attendance permission — cannot open the entry editor');
ok(req($hs, 'POST', '/admin/entries/' . (int) $c1['id'] . '/delete', ['reason' => 'x'])['status'] === 403, '  nor delete');
$g = req($as, 'GET', '/admin/entries?date=2026-03-02');
ok($g['status'] === 200 && count($g['body']['entries'] ?? []) === 4, 'the administrator can');
ok(in_array(req($as, 'POST', '/admin/entries', ['employee_code' => 'E005', 'in_at' => '2026-03-02T08:00', 'out_at' => '2026-03-02T17:00', 'reason' => 'x'], false)['status'], [403, 419], true) && $cyc('E005') === null,
   'without the CSRF token, nothing is changed');
$ha = req($as, 'POST', '/admin/entries', ['employee_code' => 'E005', 'in_at' => '2026-03-02T08:00', 'out_at' => '2026-03-02T17:00', 'timezone' => 'Asia/Karachi', 'reason' => 'Forgot to scan']);
ok($ha['status'] === 200 && (int) $cyc('E005')['present_minutes'] === 540 && str_starts_with((string) $cyc('E005')['opened_at'], '2026-03-02 03:00'),
   'adding over the web in factory time: 08:00 – 17:00 is stored as 03:00 – 12:00 UTC, 540 minutes');
$hd = req($as, 'POST', '/admin/entries/' . (int) $cyc('E005')['id'] . '/delete', ['reason' => 'test entry']);
ok($hd['status'] === 200 && $cyc('E005') === null, 'and deleting over the web');

echo "5. The screen\n";
$work = __DIR__ . '/.zattentries';
@mkdir($work, 0777, true);
file_put_contents($work . '/run.js', <<<'JS'
const { chromium } = require('playwright');
const BASE = process.argv[2];
const out = [];
const check = (name, pass, extra) => out.push({ name, pass: !!pass, extra: extra === undefined ? '' : String(extra) });
async function signIn(ctx, email) {
  const p = await ctx.newPage();
  await p.goto(BASE + '/att/manifest.webmanifest');
  await p.evaluate(async (email) => fetch('../api/v1/index.php?route=%2Fauth%2Flogin', { method: 'POST', credentials: 'same-origin',
    headers: { 'Content-Type': 'application/json' }, body: JSON.stringify({ email, password: 'CorrectHorse9' }) }), email);
  return p;
}
(async () => {
  const browser = await chromium.launch();
  try {
    const ctx = await browser.newContext({ viewport: { width: 1280, height: 900 }, timezoneId: 'Asia/Karachi' });
    const p = await signIn(ctx, 'admin@zas.test');
    const errs = []; p.on('pageerror', e => errs.push(e.message));
    await p.goto(BASE + '/att_setup.php#entries');
    await p.waitForSelector('#ent-date');
    await p.fill('#ent-date', '2026-03-02');
    await p.click('#atts-body button:has-text("Show")');
    await p.waitForFunction(() => /Worker 2/.test(document.getElementById('atts-body').textContent));
    const t = await p.textContent('#atts-body');
    check('the administrator sees Setup › Entries with the day\'s entries', /Worker 1/.test(t) && /Worker 2/.test(t));
    check('  an added entry says so; an edited one shows the scanned time', /added by administrator/.test(t) && /OUT scanned 17:00/.test(t), t.slice(0, 400));
    await p.locator('#atts-body tr', { hasText: 'Worker 2' }).locator('button:has-text("Edit")').click();
    await p.waitForSelector('#attsdlg[open]');
    check('  Edit shows the entry\'s times in factory time', (await p.inputValue('#ent-ed-in')) === '2026-03-02T08:15' && (await p.inputValue('#ent-ed-out')) === '2026-03-02T18:00');
    await p.fill('#ent-ed-out', '2026-03-02T17:45');
    await p.fill('#ent-ed-why', 'Left at 17:45');
    await p.click('#attsdlg-ok');
    await p.waitForFunction(() => !document.querySelector('#attsdlg[open]'));
    await p.waitForFunction(() => [...document.querySelectorAll('#atts-body tr')].some(r => /Worker 2/.test(r.textContent) && /17:45/.test(r.textContent)), null, { timeout: 10000 }).catch(() => {});
    check('  the change is saved and shown', /17:45/.test(await p.locator('#atts-body tr', { hasText: 'Worker 2' }).textContent()));
    await p.locator('#atts-body tr', { hasText: 'Worker 3' }).locator('button:has-text("Delete")').click();
    await p.waitForSelector('#attsdlg[open]');
    await p.click('#attsdlg-ok');
    await p.waitForFunction(() => /reason/i.test(document.getElementById('attsdlg-err').textContent), null, { timeout: 10000 }).catch(() => {});
    check('  Delete without a reason is refused', /reason/i.test(await p.textContent('#attsdlg-err')), await p.textContent('#attsdlg-err'));
    await p.fill('#ent-del-why', 'Test');
    await p.click('#attsdlg-ok');
    await p.waitForFunction(() => !/Worker 3/.test(document.getElementById('atts-body').textContent), null, { timeout: 10000 }).catch(() => {});
    check('  with a reason, the entry is deleted from the list', !/Worker 3/.test(await p.textContent('#atts-body')));
    check('no script error', errs.length === 0, errs.join(' | '));
    await ctx.close();
    const h = await browser.newContext({ viewport: { width: 1280, height: 900 } });
    const hp = await signIn(h, 'hr@zas.test');
    await hp.goto(BASE + '/att_setup.php');
    await hp.waitForSelector('#atts-tabs button');
    check('HR does not see the Entries tab', (await hp.locator('#atts-tabs button', { hasText: 'Entries' }).count()) === 0);
    await h.close();
  } catch (e) { check('the browser run completed', false, e && e.message); }
  finally { await browser.close(); }
  console.log('RESULT' + JSON.stringify(out));
})();
JS);
$raw = (string) shell_exec('cd ' . escapeshellarg($work) . ' && timeout 180 node run.js ' . escapeshellarg("http://127.0.0.1:$port") . ' 2>&1');
$results = [];
if (preg_match('/RESULT(\[.*\])\s*$/s', $raw, $m)) { $results = json_decode($m[1], true) ?: []; }
ok(count($results) >= 7, 'the browser run reported its checks (' . count($results) . ')' . (count($results) ? '' : "\n$raw"));
foreach ($results as $r) { ok($r['pass'], $r['name'] . ($r['pass'] ? '' : ' — ' . $r['extra'])); }
ok($n("SELECT COUNT(*) FROM att_corrections WHERE action LIKE 'admin.entry_%'") >= 7, 'every change made in this test is on record (' . $n("SELECT COUNT(*) FROM att_corrections WHERE action LIKE 'admin.entry_%'") . ')');

echo "6. Undo\n";
$mig->down(15);
ok($n("SELECT COUNT(*) FROM information_schema.columns WHERE table_schema = DATABASE() AND table_name = 'att_entry_cycles' AND column_name = 'in_source'") === 0
   && $n('SELECT COUNT(*) FROM att_entry_cycles WHERE gate_in_event_id IS NULL') === 0, 'the database step undoes cleanly (added entries go; each is on record)');
$mig->up();
ok($n("SELECT COUNT(*) FROM information_schema.columns WHERE table_schema = DATABASE() AND table_name = 'att_entry_cycles' AND column_name = 'in_source'") === 1, '  and applies again');

echo "\n$P passed, $F failed\n";
exit($F === 0 ? 0 : 1);
