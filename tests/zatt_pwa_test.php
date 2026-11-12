<?php
declare(strict_types=1);

/* THE PHONE APP — driven in a real browser, with the network really cut.
 *
 * Reading app.js proves nothing about the three things that matter here:
 *
 *   - with no connection, a scan is still saved, the screen says it is on
 *     THIS PHONE, and the waiting count goes up
 *   - the app can be closed and reopened with no connection (the service
 *     worker has to serve it) and the unsent scan is still there
 *   - when the connection returns, the scan reaches the server once, and
 *     only then does the screen say "Recorded by the server"
 *
 * So Chromium loads the real /att/ from a real PHP server on the real stack,
 * the test switches the browser offline, reloads, and switches it back.
 *
 * AT12 is checked here too: the app must say plainly that unsent scans are
 * lost with the phone, and must nowhere claim delivery is guaranteed.
 */

$B = __DIR__ . '/app_src/public_html/';
$P = 0; $F = 0;
function ok($c, $m) { global $P, $F; if ($c) { $P++; } else { $F++; echo "  FAIL: $m\n"; } }

$dsn = getenv('ATT_TEST_DSN') ?: 'mysql:host=127.0.0.1;port=3306;dbname=zas_att_test;charset=utf8mb4';
$dbu = getenv('ATT_TEST_USER') ?: 'zas';
$dbp = getenv('ATT_TEST_PASS'); if ($dbp === false) { $dbp = 'zaspw'; }

if (!is_file($B . 'config/config.php')) { echo "SKIP: no test config — run tests/run_att.sh\n"; exit(0); }
$nodeOk = trim((string) shell_exec('node -e "try{require(\'playwright\');console.log(\'ok\')}catch(e){console.log(\'no\')}" 2>/dev/null'));
if ($nodeOk !== 'ok') { echo "SKIP: Playwright is not available here\n"; exit(0); }

foreach (['Db','Time','Migrations','Policy','Audit','Roles','Employees','Shifts','Receipts','AttendanceService','HodService','Reconciler','SyncService','HrService'] as $c) {
    require_once $B . "includes/att/$c.php";
}
use Att\Db; use Att\Employees; use Att\Migrations; use Att\Policy; use Att\Roles; use Att\Shifts;

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
            role VARCHAR(40) NOT NULL DEFAULT 'colleague', department VARCHAR(60) NULL,
            can_see_rates TINYINT(1) NOT NULL DEFAULT 0, is_active TINYINT(1) NOT NULL DEFAULT 1)");
$pdo->exec("CREATE TABLE zu_perm (id INT AUTO_INCREMENT PRIMARY KEY, user_id INT NOT NULL, module VARCHAR(24) NOT NULL,
            actions VARCHAR(12) NOT NULL DEFAULT '', updated_by INT NULL, updated_at DATETIME DEFAULT CURRENT_TIMESTAMP,
            UNIQUE KEY uniq_user_module (user_id, module), INDEX(user_id))");
(new Migrations($B . 'sql/att'))->up();

$hash = password_hash('CorrectHorse9', PASSWORD_DEFAULT);
$mk = function (string $name, string $email) use ($pdo, $hash): int {
    $pdo->prepare("INSERT INTO users (name,email,password_hash,role) VALUES (?,?,?,'staff')")->execute([$name, $email, $hash]);
    return (int) $pdo->lastInsertId();
};
$gk = $mk('Gul Gatekeeper', 'gate@zas.test');
$hod = $mk('Hina Hod', 'hod@zas.test');
$perm = $pdo->prepare("INSERT INTO zu_perm (user_id, module, actions) VALUES (?,?,?)");
$perm->execute([$gk, 'attgate', 'vc']);
$perm->execute([$hod, 'atthod', 'vc']);
$perm->execute([$hod, 'attgate', 'v']);
/* Somebody with two jobs: gatekeeper for the whole gate, HOD of Stitching
   only. Their phone knows everybody, for the gate; the HOD tab must not. */
$combo = $mk('Kamran Both', 'both@zas.test');
$perm->execute([$combo, 'attgate', 'vc']);
$perm->execute([$combo, 'atthod', 'vc']);

$dS = Employees::createDepartment('Stitching');
$dP = Employees::createDepartment('Packing');
Roles::grant($gk, Roles::GATEKEEPER);
Roles::grant($hod, Roles::HOD, $dS);
Roles::grant($combo, Roles::GATEKEEPER);
Roles::grant($combo, Roles::HOD, $dS);
Employees::create(['code' => 'E001', 'full_name' => 'Imran Ali',  'department_id' => $dS]);
Employees::create(['code' => 'E002', 'full_name' => 'Sara Khan',  'department_id' => $dS]);
Employees::create(['code' => 'E003', 'full_name' => 'Bilal Toor', 'department_id' => $dS]);
Employees::create(['code' => 'P001', 'full_name' => 'Packing Person', 'department_id' => $dP]);
$all = Shifts::create(['name' => 'All day', 'start_time' => '00:00', 'end_time' => '23:59', 'grace_out_minutes' => 2]);
Policy::set('default_shift_id', (string) $all, null, 'string');

$port = 8721;
$srv = proc_open(sprintf('exec php -S 127.0.0.1:%d -t %s', $port, escapeshellarg(rtrim($B, '/'))),
    [1 => ['file', '/tmp/att_pwa_srv.log', 'a'], 2 => ['file', '/tmp/att_pwa_srv.log', 'a']], $pp);
register_shutdown_function(static function () use ($srv) { if (is_resource($srv)) { proc_terminate($srv, 9); proc_close($srv); } });
for ($i = 0; $i < 60; $i++) { $c = @fsockopen('127.0.0.1', $port, $e1, $e2, 0.2); if ($c) { fclose($c); break; } usleep(200000); }

$work = __DIR__ . '/.zattpwa';
@mkdir($work, 0777, true);
file_put_contents($work . '/run.js', <<<'JS'
const { chromium } = require('playwright');
const BASE = process.argv[2];
const SHOT = process.env.ATT_SHOT || '';
const out = [];
const check = (name, pass, extra) => out.push({ name, pass: !!pass, extra: extra === undefined ? '' : String(extra) });

async function signIn(page, email) {
  await page.goto(BASE + '/att/');
  await page.waitForSelector('#email', { timeout: 20000 });
  await page.fill('#email', email);
  await page.fill('#password', 'CorrectHorse9');
  await page.click('#signin');
  await page.waitForSelector('#pin', { timeout: 20000 });
  await page.fill('#pin', '1234');
  await page.click('#pingo');
  await page.waitForSelector('#status', { timeout: 20000 });
}
const waiting = (page) => page.getAttribute('#waiting', 'data-count');
const text = (page, sel) => page.textContent(sel).catch(() => '');
async function gate(page, code, btn) {
  await page.fill('#code', code);
  await page.click(btn);
}
/* The button the screen did NOT offer: through "Not right?". */
async function gateOther(page, code, btn) {
  await page.fill('#code', code);
  await page.click('#btn-other');
  await page.click(btn);
}
const shown = (page, sel) => page.isVisible(sel);

(async () => {
  const browser = await chromium.launch();
  try {
    // ------------------------------------------------------------- gate phone
    const ctx = await browser.newContext({ viewport: { width: 390, height: 800 } });
    const page = await ctx.newPage();
    page.on('dialog', d => d.accept());
    await signIn(page, 'gate@zas.test');

    check('after sign-in and PIN the Gate tab is shown, waiting for a code', await page.isVisible('#gate-idle'));
    check('BY DEFAULT NO BUTTON AND NO MESSAGE: nothing to press until a code is typed', !(await shown(page, '#btn-in')) && !(await shown(page, '#btn-out')) && (await page.$('#msg')) === null);
    check('a gatekeeper is NOT shown the HOD tab', !(await page.isVisible('#tab-hod')));
    check('a login with one job gets no switch', !(await page.isVisible('#mode-gate')) && !(await page.isVisible('#mode-hod')));
    check('the header says "Security gate"', /Security gate/.test(await text(page, '#who')));
    const kb = await page.evaluate(() => { const c = document.getElementById('code'); return [c.getAttribute('inputmode'), c.getAttribute('enterkeyhint'), c.getAttribute('pattern')].join('|'); });
    check('THE CODE BOX ASKS THE PHONE FOR ITS NUMBER PAD, with a "Next" key', kb === 'numeric|next|[0-9]*', kb);
    await page.click('#code-kbd');
    check('"ABC" switches the box to letters for a code that has them', (await page.getAttribute('#code', 'inputmode')) === 'text' && (await page.textContent('#code-kbd')) === '123');
    await page.click('#code-kbd');
    const honest = await text(page, '#honest');
    check('AT12: the app says unsent scans are lost with the phone', /unsent scans are lost/i.test(honest), honest);
    const whole = (await text(page, 'body')).toLowerCase();
    check('AT12: nowhere does it claim delivery is guaranteed', !/guarantee|never lost|cannot be lost|always delivered/.test(whole));

    await page.waitForFunction(() => AttDB.getAll('employees').then(r => r.length > 0), null, { timeout: 20000 });
    await page.fill('#code', 'E001');
    await page.waitForFunction(() => document.getElementById('empname').textContent.includes('Imran'), null, { timeout: 10000 });
    check('typing a code shows the name from the phone\'s own list', true);
    await page.waitForSelector('#btn-in', { state: 'visible' });
    check('E001 IS OUTSIDE: ONLY THE GREEN IN BUTTON IS SHOWN', !(await shown(page, '#btn-out')) && !(await shown(page, '#gate-idle')));
    await page.waitForFunction(() => /server/.test(document.getElementById('emphint').textContent), null, { timeout: 10000 });
    check('  marked Outside, checked with the server', /Outside/.test(await text(page, '#empstate')), await text(page, '#emphint'));
    check('  the button names the person', /Imran is coming in/.test(await text(page, '#btn-in')), await text(page, '#btn-in'));

    await page.evaluate(() => { window.__vib = []; navigator.vibrate = (p) => { window.__vib.push(p); return true; }; });
    await page.click('#btn-in');
    await page.waitForSelector('.burst', { state: 'attached', timeout: 3000 }).catch(() => {});
    await page.waitForSelector('#msg');
    check('A SAVED SCAN BUZZES THE PHONE and bursts on the button', (await page.evaluate(() => window.__vib.length)) >= 1);
    check('the first message says saved ON THIS PHONE', /saved on this phone/i.test(await text(page, '#msg')), await text(page, '#msg'));
    check('after saving, the code box is empty and the screen is back to waiting', (await page.inputValue('#code')) === '' && await page.isVisible('#gate-idle'));
    await page.waitForSelector('#msg', { state: 'detached', timeout: 8000 }).catch(() => {});
    check('THE SUCCESS MESSAGE HIDES BY ITSELF after a few seconds', (await page.$('#msg')) === null);
    await page.fill('#code', 'E001');
    await page.waitForSelector('#btn-out', { state: 'visible', timeout: 10000 });
    check('TYPING E001 AGAIN: HE IS INSIDE, ONLY THE RED OUT BUTTON IS SHOWN', !(await shown(page, '#btn-in')) && /Inside since/.test(await text(page, '#empstate')), await text(page, '#empstate'));
    check('  "Not right?" offers the other one', /Record IN instead/.test(await text(page, '#btn-other')));
    await page.press('#code', 'Enter');
    check('the "Next" key closes the keypad, points at OUT, and records nothing', (await page.evaluate(() => document.activeElement && document.activeElement.id)) === 'btn-out'
      && (await page.evaluate(() => AttDB.allOutbox().then(r => r.length))) === 1);
    await page.fill('#code', '');
    check('clearing the code goes back to waiting, no buttons', await page.isVisible('#gate-idle') && !(await shown(page, '#btn-out')));
    await page.waitForFunction(() => [...document.querySelectorAll('#recent .item')].some(i => /Recorded by the server/.test(i.textContent)), null, { timeout: 20000 });
    check('once the receipt arrives the row says Recorded by the server', true);
    check('and nothing is left waiting', (await waiting(page)) === '0');

    await page.fill('#code', 'ZZZ999');
    await page.waitForFunction(() => /not in this phone/i.test(document.getElementById('empname').textContent));
    check('an unknown code says so and offers no button at all', !(await shown(page, '#btn-in')) && !(await shown(page, '#btn-out')) && !(await shown(page, '#btn-other')));
    check('  and nothing is queued', (await waiting(page)) === '0');

    // Signed in, but the phone's list is empty: typing a code fetches it.
    await page.evaluate(() => AttDB.replaceAll('employees', []));
    await page.fill('#code', '');
    await page.fill('#code', 'E002');
    await page.waitForFunction(() => /Sara Khan/.test(document.getElementById('empname').textContent), null, { timeout: 15000 }).catch(() => {});
    check('AN EMPTY LIST IS DOWNLOADED BY ITSELF when a code is typed, and the name appears', /Sara Khan/.test(await text(page, '#empname')));
    await page.fill('#code', '');

    // A second gate phone: what it offers comes from the one central database.
    const ctx2 = await browser.newContext({ viewport: { width: 390, height: 800 }, deviceScaleFactor: SHOT ? 2 : 1 });
    const p2 = await ctx2.newPage();
    await signIn(p2, 'gate@zas.test');
    await p2.waitForFunction(() => AttDB.getAll('employees').then(r => r.length > 0), null, { timeout: 20000 });
    await p2.fill('#code', 'E001');
    await p2.waitForFunction(() => /server/.test(document.getElementById('emphint').textContent), null, { timeout: 10000 });
    check('ANOTHER GATE\'S PHONE, never used for E001, offers OUT: he is inside, says the server', await p2.isVisible('#btn-out') && !(await p2.isVisible('#btn-in')));
    if (SHOT) {
      await p2.waitForTimeout(600); await p2.screenshot({ path: SHOT + '/gate-out.png' });
      await p2.fill('#code', 'E002'); await p2.waitForFunction(() => /server/.test(document.getElementById('emphint').textContent));
      await p2.waitForTimeout(600); await p2.screenshot({ path: SHOT + '/gate-in.png' });
      await p2.fill('#code', ''); await p2.waitForTimeout(600); await p2.screenshot({ path: SHOT + '/gate-idle.png' });
    }
    // Signed out on the server (as after a sign-in setting changes) with no employee list on the phone.
    await p2.fill('#code', '');
    await ctx2.clearCookies();
    await p2.evaluate(() => AttDB.replaceAll('employees', []));
    await p2.fill('#code', 'E002');
    await p2.waitForFunction(() => /SIGNED OUT on the server/.test(document.getElementById('emphint').textContent), null, { timeout: 15000 }).catch(() => {});
    const so = await p2.textContent('#emphint');
    check('NO LIST AND SIGNED OUT ON THE SERVER: the gate is told why, and what to tap', /SIGNED OUT on the server/.test(so) && /Sign in/.test(so), so);
    check('  and the red "signed out" banner with its Sign in button is shown', await p2.isVisible('#relogin'));
    await ctx2.close();

    // ---------------------------------------------------- the network is cut
    await page.evaluate(() => navigator.serviceWorker.ready);
    await page.waitForFunction(() => !!navigator.serviceWorker.controller, null, { timeout: 20000 }).catch(() => {});
    await ctx.setOffline(true);

    await gate(page, 'E002', '#btn-in');
    await page.waitForFunction(() => /Sara Khan/.test(((document.getElementById('msg') || {}).textContent || '')), null, { timeout: 10000 });
    check('OFFLINE: the scan is saved and the message says on this phone', /saved on this phone/i.test(await text(page, '#msg')));
    await page.waitForFunction(() => document.getElementById('waiting').getAttribute('data-count') === '1' && !/Sending/.test(document.getElementById('waiting').textContent), null, { timeout: 20000 });
    check('OFFLINE: the waiting count is 1', true);
    check('OFFLINE: the status says Offline', /Offline/.test(await text(page, '#net')));
    const row = await text(page, '#recent .item');
    check('OFFLINE: the row says NOT SENT YET — it does not say recorded', /not sent yet/i.test(row) && !/Recorded by the server/.test(row), row);

    await gateOther(page, 'E002', '#btn-in');
    await page.waitForFunction(() => /Already scanned/i.test(((document.getElementById('msg') || {}).textContent || '')), null, { timeout: 10000 });
    check('OFFLINE: a double-tap inside 60 seconds is stopped on the phone', (await waiting(page)) === '1');

    // ------------------------------- closed and reopened with no connection
    let reopened = true;
    try { await page.reload({ timeout: 20000 }); await page.waitForSelector('#pin', { timeout: 20000 }); } catch (e) { reopened = false; }
    check('OFFLINE: THE APP REOPENS WITH NO CONNECTION (served by its service worker)', reopened);
    if (reopened) {
      check('reopening asks for the PIN, not a fresh sign-in', await page.isVisible('#pin'));
      await page.fill('#pin', '0000'); await page.click('#pingo');
      await page.waitForFunction(() => /Wrong PIN/.test(document.getElementById('pinerr').textContent), null, { timeout: 10000 });
      check('a wrong PIN is refused', true);
      await page.fill('#pin', '1234'); await page.click('#pingo');
      await page.waitForSelector('#status', { timeout: 20000 });
      await page.waitForFunction(() => document.getElementById('waiting') && document.getElementById('waiting').getAttribute('data-count') === '1', null, { timeout: 20000 });
      check('OFFLINE: THE UNSENT SCAN SURVIVED THE APP BEING CLOSED', true);
    }

    // -------------------------------------------------- the network returns
    await ctx.setOffline(false);
    await page.evaluate(() => window.dispatchEvent(new Event('online')));
    await page.waitForFunction(() => document.getElementById('waiting').getAttribute('data-count') === '0', null, { timeout: 30000 });
    check('BACK ONLINE: the queue empties by itself', true);
    await page.waitForFunction(() => [...document.querySelectorAll('#recent .item')].filter(i => /Recorded by the server/.test(i.textContent)).length >= 2, null, { timeout: 20000 });
    check('BACK ONLINE: the offline scan now says Recorded by the server', true);

    // ------------------------------------------- the server's refusal shows
    await gateOther(page, 'E003', '#btn-out');
    await page.waitForFunction(() => [...document.querySelectorAll('#recent .item')].some(i => /Refused by the server/.test(i.textContent)), null, { timeout: 20000 });
    check('an OUT with nobody inside comes back Refused by the server, with the reason', /not currently inside/i.test(await text(page, '#recent')));
    check('a refused scan is settled — it is not left waiting to be resent', (await waiting(page)) === '0');

    // also let one Packing employee in, so the HOD test can prove the scope
    await page.fill('#code', 'P001');
    await page.click('#btn-in');
    await page.waitForFunction(() => [...document.querySelectorAll('#recent .item')].some(i => /Packing Person/.test(i.textContent) && /Recorded by the server/.test(i.textContent)), null, { timeout: 20000 });
    await ctx.close();

    // -------------------------------------------------------------- HOD phone
    const hctx = await browser.newContext({ viewport: { width: 390, height: 800 } });
    const hp = await hctx.newPage();
    hp.on('dialog', d => d.accept());
    await signIn(hp, 'hod@zas.test');
    check('a HOD lands on the HOD tab and has no Gate tab', (await hp.isVisible('#btn-confirm')) && !(await hp.isVisible('#tab-gate')));
    await hp.waitForFunction(() => document.querySelectorAll('#pendinglist .pick').length >= 2, null, { timeout: 20000 });
    const list = await text(hp, '#pendinglist');
    check('the HOD sees the two Stitching entries', /Imran Ali/.test(list) && /Sara Khan/.test(list), list);
    check('AND NOT the Packing entry', !/Packing Person/.test(list));

    await hctx.setOffline(true);                       // confirm while offline…
    await hp.click('#btn-all');
    await hp.click('#btn-confirm');
    await hp.waitForFunction(() => /confirmation\(s\) saved on this phone/i.test((document.getElementById('msg') || {}).textContent || ''), null, { timeout: 10000 });
    await hp.waitForFunction(() => document.getElementById('waiting').getAttribute('data-count') === '2' && !/Sending/.test(document.getElementById('waiting').textContent), null, { timeout: 20000 });
    check('HOD OFFLINE: two confirmations are saved on the phone, one per entry', true);
    check('and the confirmed entries leave the list at once', (await hp.locator('#pendinglist .pick').count()) === 0);
    await hctx.setOffline(false);                      // …and they go when the signal returns
    await hp.evaluate(() => window.dispatchEvent(new Event('online')));
    await hp.waitForFunction(() => document.getElementById('waiting').getAttribute('data-count') === '0', null, { timeout: 30000 });
    check('HOD BACK ONLINE: both confirmations are sent', true);
    await hctx.close();

    // ------------------------------------------- gatekeeper AND HOD of Stitching
    const bctx = await browser.newContext({ viewport: { width: 390, height: 800 } });
    const bp = await bctx.newPage();
    bp.on('dialog', d => d.accept());
    await signIn(bp, 'both@zas.test');
    await bp.waitForFunction(() => AttDB.getAll('employees').then(r => r.length), null, { timeout: 20000 });
    for (let i = 0; i < 50 && (await bp.evaluate(() => AttDB.getAll('employees').then(r => r.length))) < 4; i++) await bp.waitForTimeout(200);
    check('a login with BOTH jobs gets a switch: Security gate / HOD', (await bp.isVisible('#mode-gate')) && (await bp.isVisible('#mode-hod')));
    check('  and in gate mode sees only the gate tabs — no HOD, no Overtime', (await bp.isVisible('#tab-gate')) && !(await bp.isVisible('#tab-hod')) && !(await bp.isVisible('#tab-ot')));
    await bp.click('#tab-gate');
    await bp.fill('#code', 'P001');
    await bp.waitForFunction(() => /Packing Person/.test(document.getElementById('empname').textContent), null, { timeout: 10000 });
    check('with two jobs, the GATE tab still knows the Packing employee — the gate serves everyone', true);
    await bp.click('#mode-hod');
    await bp.waitForSelector('#hod-scope');
    check('switched to HOD: the HOD tab shows and the gate buttons are gone', (await bp.isVisible('#tab-hod')) && !(await bp.isVisible('#btn-in')));
    check('  and the header says which job the phone is doing', /HOD · Stitching/.test(await text(bp, '#who')), await text(bp, '#who'));
    check('the HOD tab says whose arrivals this person confirms: Stitching', /for: Stitching\./.test(await text(bp, '#hod-scope')), await text(bp, '#hod-scope'));
    await bp.waitForTimeout(1500);
    check('THE HOD LIST HOLDS NO PACKING ENTRY, even for a login that also works the gate', !/Packing Person/.test(await text(bp, '#pendinglist')), await text(bp, '#pendinglist'));
    await bp.evaluate(() => { window.__vib = []; navigator.vibrate = (p) => { window.__vib.push(p); return true; }; });
    await bp.fill('#hodcode', 'P001');
    await bp.waitForFunction(() => /not in your department/.test(document.getElementById('hodname').textContent), null, { timeout: 10000 });
    check('TYPING A PACKING CODE ON THE HOD TAB SAYS "not in your department"', true);
    check('  and shows no photo for them', await bp.evaluate(() => document.getElementById('hodphoto').hidden));
    const before = await bp.getAttribute('#waiting', 'data-count');
    await bp.click('#btn-hodcode');
    await bp.waitForFunction(() => /Only their own HOD confirms them\. Nothing was saved/.test((document.getElementById('msg') || {}).textContent || ''), null, { timeout: 10000 });
    check('  the refusal buzzes the long double buzz', (await bp.evaluate(() => JSON.stringify(window.__vib))) === '[[120,70,120]]', await bp.evaluate(() => JSON.stringify(window.__vib)));
    check('  and pressing Confirm saves nothing', (await bp.getAttribute('#waiting', 'data-count')) === before
      && (await bp.evaluate(() => AttDB.allOutbox().then(r => r.filter(x => x.event_type === 'HOD_CONFIRM').length))) === 0);
    await bctx.close();
  } catch (e) {
    check('the browser run completed', false, e && e.message);
  } finally {
    await browser.close();
  }
  console.log('RESULT' + JSON.stringify(out));
})();
JS);

echo "1. The phone app, in a browser, with the network really cut\n";
$raw = (string) shell_exec('cd ' . escapeshellarg($work) . ' && timeout 240 node run.js ' . escapeshellarg("http://127.0.0.1:$port") . ' 2>&1');
$results = [];
if (preg_match('/RESULT(\[.*\])\s*$/s', $raw, $m)) { $results = json_decode($m[1], true) ?: []; }
ok(count($results) >= 20, 'the browser run reported its checks (' . count($results) . ')' . (count($results) ? '' : "\n$raw"));
foreach ($results as $r) { ok($r['pass'], $r['name'] . ($r['pass'] ? '' : ' — ' . $r['extra'])); }

echo "2. What the server ended up with\n";
$n = fn(string $sql, array $p = []) => (int) Db::value($sql, $p);
ok($n("SELECT COUNT(*) FROM att_events WHERE event_type='GATE_IN'") === 3, 'three gate INs stored: E001, E002 (sent after the outage), P001');
ok($n("SELECT COUNT(*) FROM att_events ev JOIN att_employees e ON e.id=ev.employee_id WHERE e.code='E002'") === 1,
   'THE OFFLINE SCAN ARRIVED EXACTLY ONCE — not lost, not doubled by the retry');
ok($n("SELECT COUNT(*) FROM att_events WHERE operator_id <> ?", [$gk]) === 0, 'every gate event is under the gatekeeper who was signed in');
ok($n("SELECT COUNT(*) FROM att_events ev JOIN att_employees e ON e.id=ev.employee_id WHERE e.code IN ('E003','ZZZ999')") === 0,
   'the refused OUT and the unknown code stored no event');
ok($n("SELECT COUNT(*) FROM att_hod_arrivals WHERE status='confirmed' AND hod_user_id=? AND match_method='explicit_cycle'", [$hod]) === 2,
   'two HOD confirmations, each naming its entry, under the signed-in HOD');
ok($n("SELECT COUNT(*) FROM att_hod_arrivals h JOIN att_employees e ON e.id=h.employee_id WHERE e.code='P001'") === 0,
   'the Packing entry is still unconfirmed');
/* And if a phone were changed to send it anyway, the server says no. */
require_once $B . 'includes/att/HodService.php';
$forced = Att\HodService::confirm(['event_uuid' => 'dddddddd-0000-4000-8000-00000000dddd', 'hod_user_id' => $combo,
    'employee_id' => (int) Db::value("SELECT id FROM att_employees WHERE code='P001'"), 'device_time' => gmdate('Y-m-d\TH:i:s\Z'), 'timezone' => 'UTC']);
ok(($forced['receipt_status'] ?? '') === 'REJECTED' && $n("SELECT COUNT(*) FROM att_hod_arrivals h JOIN att_employees e ON e.id=h.employee_id WHERE e.code='P001'") === 0,
   'A CONFIRMATION FOR PACKING SENT STRAIGHT TO THE SERVER BY THE STITCHING HOD IS REFUSED (' . ($forced['reason_code'] ?? '?') . ')');
ok($n("SELECT COUNT(*) FROM att_devices WHERE revoked_at IS NULL") === 4, 'four phones registered themselves: two at the gate, the HOD, and the one with both jobs');
ok(Db::value("SELECT role_scope FROM att_devices WHERE registered_by=? LIMIT 1", [$gk]) === 'gate'
   && Db::value("SELECT role_scope FROM att_devices WHERE registered_by=?", [$hod]) === 'hod',
   'each with the scope the SERVER derived from the user\'s permissions');

echo "3. The app shell: static, self-contained, nothing secret\n";
$js = file_get_contents($B . 'att/app.js') . file_get_contents($B . 'att/db.js') . file_get_contents($B . 'att/sw.js');
ok(!preg_match('/(secret|api[_-]?key|access[_-]?key|password\s*[:=]\s*[\'"][^\'"]+)/i', preg_replace('!/\*.*?\*/!s', '', $js) ?? ''),
   'no key, secret or password literal in any script the phone downloads');
ok(!str_contains($js, 'innerHTML'), 'no innerHTML anywhere — names and codes are always written as text');
$sw = file_get_contents($B . 'att/sw.js');
ok(str_contains($sw, "indexOf('/api/') !== -1) return"), 'the service worker never intercepts the API');
$rootSw = file_get_contents($B . 'sw.js');
ok(str_contains($rootSw, 'zas-production-shell-v1') && !str_contains($rootSw, 'zas-att-shell') && !str_contains($rootSw, '/att/'),
   'the existing root service worker is untouched');
ok(str_contains(file_get_contents($B . 'att/manifest.webmanifest'), '"scope": "./"'), 'the app\'s manifest is scoped to its own folder');


echo "4. The HR screen, inside the real ERP layout\n";
/* Seeded only now, so the counts in step 2 stay about the phone run alone. */
$hr = $mk('Huma HR', 'hr@zas.test');
/* An office account, as HR is in real life. The ERP's own menu looks up
   shipment assignments for 'staff' logins, in a table this bare test database
   does not have — the first run of this step failed on exactly that, in the
   ERP's code, before the attendance page had drawn anything. */
$pdo->prepare("UPDATE users SET role='colleague' WHERE id=?")->execute([$hr]);
$perm->execute([$hr, 'atthr', 'vu']);
$perm->execute([$hr, 'attadm', 'vu']);
Roles::grant($hr, Roles::HR);
$q1 = Employees::create(['code' => 'Q1', 'full_name' => 'Worker Qone', 'department_id' => $dS]);
$q2 = Employees::create(['code' => 'Q2', 'full_name' => 'Worker Qtwo', 'department_id' => $dS]);
$dev = (int) Db::value('SELECT id FROM att_devices WHERE registered_by = ?', [$gk]);
$at = fn(string $hm) => new DateTimeImmutable("2026-03-02 $hm:00", new DateTimeZone('UTC'));
$sq = 5000;
$scan = function (string $dir, int $e, string $hm) use ($dev, $gk, $at, &$sq) {
    $p = ['employee_id' => $e, 'device_id' => $dev, 'operator_id' => $gk, 'occurred_at' => $at($hm),
          'event_uuid' => sprintf('%08x-0000-4000-8000-%012x', random_int(0, 0xffffffff), ++$sq), 'device_sequence' => $sq];
    return $dir === 'in' ? Att\AttendanceService::gateIn($p) : Att\AttendanceService::gateOut($p);
};
$scan('in', $q1, '05:00'); $scan('out', $q1, '05:30');
$second = (int) $scan('in', $q1, '06:00')['entry_cycle_id'];
$amb = Att\HodService::confirm(['event_uuid' => 'a1a1a1a1-0000-4000-8000-00000000a1a1', 'hod_user_id' => $hod, 'employee_id' => $q1, 'occurred_at' => $at('06:05')]);
$openCycle = (int) $scan('in', $q2, '05:00')['entry_cycle_id'];
$heldUuid = 'b2b2b2b2-0000-4000-8000-00000000b2b2';
Att\SyncService::process(['event_uuid' => $heldUuid, 'event_type' => 'GATE_IN', 'employee_code' => 'E003', 'device_id' => $dev,
    'device_sequence' => 6001, 'occurred_at' => 'half past nine', 'timezone' => 'UTC'], $gk);
ok($amb['receipt_status'] === 'NEEDS_REVIEW', '(seed) one ambiguous confirmation, one open entry, one held scan');

file_put_contents($work . '/hr.js', <<<'JS'
const { chromium } = require('playwright');
const BASE = process.argv[2], SECOND = process.argv[3];
const out = [];
const check = (name, pass, extra) => out.push({ name, pass: !!pass, extra: extra === undefined ? '' : String(extra) });
async function login(page, email) {
  // Signed in from a page with no script of its own. From /att/ the app's
  // start-up request races this one, and whichever answer arrives last sets
  // the session cookie — the gatekeeper check failed that way once.
  await page.goto(BASE + '/att/manifest.webmanifest');
  return page.evaluate(async (email) => {
    const r = await fetch('../api/v1/index.php?route=%2Fauth%2Flogin', { method: 'POST', credentials: 'same-origin',
      headers: { 'Content-Type': 'application/json' }, body: JSON.stringify({ email, password: 'CorrectHorse9' }) });
    return r.status;
  }, email);
}
async function decide(page, rowText, button, fill) {
  const row = page.locator('#attq tr', { hasText: rowText }).first();
  await row.locator('button', { hasText: button }).click();
  await page.waitForSelector('#attdlg[open]');
  if (fill) await fill();
}
(async () => {
  const browser = await chromium.launch();
  try {
    const ctx = await browser.newContext({ viewport: { width: 1280, height: 900 } });
    const page = await ctx.newPage();
    const errors = [];
    page.on('pageerror', e => errors.push(e.message));
    check('HR signs in', (await login(page, 'hr@zas.test')) === 200);
    await page.goto(BASE + '/att_hr_reconciliation.php');
    await page.waitForSelector('#attq h2', { timeout: 20000 });
    const body = await page.textContent('body');
    check('the page renders inside the ERP layout with no PHP warning', !/Warning:|Notice:|Fatal error|Deprecated:/.test(body), body.slice(0, 300));
    check('the menu shows Attendance › Reconciliation (HR)', /Reconciliation \(HR\)/.test(await page.textContent('.nav')));
    check('the ambiguous confirmation is listed with the employee', /Worker Qone/.test(await page.textContent('#attq')));
    check('the open entry is listed', /Worker Qtwo/.test(await page.textContent('#attq')));
    check('the held scan is listed with its problem', /could not be read/i.test(await page.textContent('#attq')));

    // 1. choose which entry — a reason is demanded first
    await decide(page, 'Worker Qone', 'Choose entry');
    check('both candidate entries are offered', (await page.locator('#dlg-cycle option').count()) === 2);
    await page.selectOption('#dlg-cycle', SECOND);
    await page.click('#dlg-ok');
    check('saving without a reason is refused in the dialog', await page.isVisible('#attdlg[open]'));
    await page.fill('#dlg-reason', 'HOD says it was the second entry of the morning');
    await page.click('#dlg-ok');
    await page.waitForFunction(() => !document.querySelector('#attdlg[open]') && !/more than one entry[\s\S]*Worker Qone[\s\S]*Two confirmations/.test(document.getElementById('attq').textContent), null, { timeout: 20000 });
    check('after the decision the item leaves the queue', true);

    // 2. close the forgotten exit
    await page.waitForSelector('#attq h2');
    await decide(page, 'Worker Qtwo', 'Close entry', async () => {
      await page.fill('#dlg-time', '2026-03-02T11:30');           // factory time = 06:30 UTC
      await page.fill('#dlg-reason', 'left at 11:30, forgot to scan out');
    });
    await page.click('#dlg-ok');
    await page.waitForFunction(() => !document.querySelector('#attdlg[open]'), null, { timeout: 20000 });
    check('the forgotten exit is closed from the screen', true);

    // 3. set the unreadable scan aside
    await page.waitForFunction(() => /could not be read/i.test(document.getElementById('attq').textContent), null, { timeout: 20000 });
    await decide(page, 'could not be read', 'Set aside', async () => { await page.fill('#dlg-reason', 'phone clock was reset; scan repeated by hand'); });
    await page.click('#dlg-ok');
    await page.waitForFunction(() => !document.querySelector('#attdlg[open]') && !/could not be read/i.test(document.getElementById('attq').textContent), null, { timeout: 20000 });
    check('the held scan is set aside', true);

    // 4. withdraw a phone
    await page.waitForFunction(() => /Registered phones/.test(document.getElementById('attq').textContent), null, { timeout: 20000 });
    await decide(page, 'Gul Gatekeeper', 'Withdraw', async () => { await page.fill('#dlg-reason', 'handset lost on the night shift'); });
    await page.click('#dlg-ok');
    await page.waitForFunction(() => /Withdrawn/.test(document.getElementById('attq').textContent), null, { timeout: 20000 });
    check('a phone is withdrawn from the same screen', true);
    check('no script error on the page', errors.length === 0, errors.join(' | '));
    await ctx.close();

    // a gatekeeper has no business here
    const g = await browser.newContext();
    const gp = await g.newPage();
    await login(gp, 'gate@zas.test');
    const resp = await gp.goto(BASE + '/att_hr_reconciliation.php');
    check('A GATEKEEPER OPENING THE HR SCREEN GETS 403', resp.status() === 403, resp.status());
    await g.close();
  } catch (e) { check('the HR browser run completed', false, e && e.message); }
  finally { await browser.close(); }
  console.log('RESULT' + JSON.stringify(out));
})();
JS);
$raw = (string) shell_exec('cd ' . escapeshellarg($work) . ' && timeout 240 node hr.js ' . escapeshellarg("http://127.0.0.1:$port") . ' ' . $second . ' 2>&1');
$results = [];
if (preg_match('/RESULT(\[.*\])\s*$/s', $raw, $m)) { $results = json_decode($m[1], true) ?: []; }
ok(count($results) >= 10, 'the HR browser run reported its checks (' . count($results) . ')' . (count($results) ? '' : "\n$raw"));
foreach ($results as $r) { ok($r['pass'], $r['name'] . ($r['pass'] ? '' : ' — ' . $r['extra'])); }

$a = Db::one("SELECT status, entry_cycle_id, match_method, resolved_by FROM att_hod_arrivals WHERE event_uuid='a1a1a1a1-0000-4000-8000-00000000a1a1'");
ok($a['status'] === 'confirmed' && (int) $a['entry_cycle_id'] === $second && $a['match_method'] === 'hr_manual' && (int) $a['resolved_by'] === $hr,
   'the confirmation is linked to the entry HR chose, as an HR decision, under HR\'s name');
$c = Db::one('SELECT closed_at, present_minutes, close_source FROM att_entry_cycles WHERE id = ?', [$openCycle]);
ok($c['close_source'] === 'hr_correction' && (int) $c['present_minutes'] === 90,
   'the entry is closed at 11:30 FACTORY time (06:30 UTC → 90 minutes), marked as a correction');
ok(Db::value('SELECT review_state FROM att_sync_receipts WHERE event_uuid = ?', [$heldUuid]) === 'dismissed', 'the held scan is dismissed, not deleted');
ok($n('SELECT COUNT(*) FROM att_devices WHERE revoked_at IS NOT NULL AND revoked_by = ?', [$hr]) === 1, 'one phone withdrawn, by HR');
ok($n('SELECT COUNT(*) FROM att_corrections WHERE actor_id = ? AND CHAR_LENGTH(reason) > 10', [$hr]) === 3,
   'three decisions, three rows in att_corrections, each with its reason');

echo "\n$P passed, $F failed\n";
exit($F === 0 ? 0 : 1);
