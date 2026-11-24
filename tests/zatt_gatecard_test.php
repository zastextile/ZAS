<?php
declare(strict_types=1);
/*
 * CARD TAP AT THE GATE (owner, 24 Nov 2026): "security will tap the card on
 * the reader and attendance is done, then the next person".
 *   - the phone's employee list carries each card's fingerprint (never the number)
 *   - a fast burst of digits + Enter is a card; slow typing goes to the code box as before
 *   - automatic: IN if outside, OUT if inside; 3 seconds with Undo / "It is OUT instead" / Save now
 *   - a second tap of the same card a moment later saves nothing
 *   - a tap asks the server nothing (no extra load); the scan is sent like any other
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
$pdo->prepare("INSERT INTO users (name,email,password_hash,role) VALUES ('Gul Gatekeeper','gate@zas.test',?,'staff')")->execute([password_hash('CorrectHorse9', PASSWORD_DEFAULT)]);
$gk = (int) $pdo->lastInsertId();
$pdo->prepare("INSERT INTO zu_perm (user_id, module, actions) VALUES (?, 'attgate', 'vc')")->execute([$gk]);
$d = Employees::createDepartment('Stitching');
Roles::grant($gk, Roles::GATEKEEPER);
$e = [];
foreach (['1001' => 'Imran Ali', '1002' => 'Sara Khan', '1003' => 'Bilal Toor', '1004' => 'No Card'] as $c => $nm) {
    $e[$c] = Employees::create(['code' => $c, 'full_name' => $nm, 'department_id' => $d]);
}
foreach (['1001' => '0004728391', '1002' => '0004728392', '1003' => '0004728393'] as $c => $card) {
    Db::run('INSERT INTO att_emp_cards (employee_id, card_hash, linked_at) VALUES (?, ?, NOW(6))', [$e[$c], hash('sha256', $card)]);
}
$all = Shifts::create(['name' => 'All day', 'start_time' => '00:00', 'end_time' => '23:59', 'grace_out_minutes' => 2]);
Policy::set('default_shift_id', (string) $all, null, 'string');
Policy::set('duplicate_window_seconds', '8', null, 'int');

echo "1. The phone's list carries fingerprints, never numbers\n";
$snap = Employees::syncSnapshot(null);
$byCode = []; foreach ($snap['employees'] as $r) { $byCode[$r['code']] = $r; }
ok(($byCode['1001']['card_hash'] ?? '') === hash('sha256', '0004728391') && $byCode['1004']['card_hash'] === null, 'each person\'s card fingerprint is in the list; no card = empty');
ok(!str_contains(json_encode($snap), '0004728391'), 'the card number itself is nowhere in it');

$port = 8731;
$srv = proc_open(sprintf('exec php -S 127.0.0.1:%d -t %s', $port, escapeshellarg(rtrim($B, '/'))),
    [1 => ['file', '/tmp/att_gatecard_srv.log', 'a'], 2 => ['file', '/tmp/att_gatecard_srv.log', 'a']], $pp);
register_shutdown_function(static function () use ($srv) { if (is_resource($srv)) { proc_terminate($srv, 9); proc_close($srv); } });
for ($i = 0; $i < 60; $i++) { $c = @fsockopen('127.0.0.1', $port, $e1, $e2, 0.2); if ($c) { fclose($c); break; } usleep(200000); }
$work = __DIR__ . '/.zgatecard'; @mkdir($work, 0777, true);
file_put_contents($work . '/run.js', <<<'JS'
const { chromium } = require('playwright');
const BASE = process.argv[2];
const out = [];
const check = (name, pass, extra) => out.push({ name, pass: !!pass, extra: extra === undefined ? '' : String(extra) });
const text = (p, s) => p.textContent(s).catch(() => '');
(async () => {
  const browser = await chromium.launch();
  try {
    const page = await (await browser.newContext({ viewport: { width: 390, height: 800 } })).newPage();
    page.on('dialog', d => d.accept());
    await page.goto(BASE + '/att/');
    await page.waitForSelector('#email'); await page.fill('#email', 'gate@zas.test'); await page.fill('#password', 'CorrectHorse9');
    await page.click('#signin');
    await page.waitForSelector('#status', { timeout: 20000 });
    await page.waitForFunction(() => AttDB.getAll('employees').then(r => r.length > 0), null, { timeout: 20000 });
    check('the screen says a card can be tapped', /Tap the card/.test(await text(page, 'label[for=code]')));
    await page.evaluate(() => { window.__saved = []; const q = AttDB.enqueue; AttDB.enqueue = function (ev) { window.__saved.push(JSON.parse(JSON.stringify(ev))); return q.apply(this, arguments); }; });
    const saved = () => page.evaluate(() => window.__saved);
    const reqs = []; page.on('request', r => { if (r.url().includes('/api/')) reqs.push(r.url()); });
    const tap = async (digits) => { await page.keyboard.type(digits, { delay: 4 }); await page.keyboard.press('Enter'); };

    // 1. a tap: IN, automatic
    await page.focus('#code');
    await tap('0004728391');
    await page.waitForSelector('#gate-tap:not([hidden])', { timeout: 5000 });
    check('A TAP SHOWS THE PERSON AND "IN" AT ONCE', /IN/.test(await text(page, '.tap-word')) && /Imran Ali/.test(await text(page, '#tapname')), await text(page, '#gate-tap'));
    check('  with Undo, "It is OUT instead" and Save now', await page.isVisible('#tap-undo') && /OUT instead/.test(await text(page, '#tap-switch')) && await page.isVisible('#tap-save'));
    check('  the digits did not go into the code box', (await page.inputValue('#code')) === '');
    check('  and it asked the server nothing to find the person', !reqs.length, reqs.join(' '));
    check('  nothing saved during the 3 seconds', (await saved()).length === 0);
    await page.waitForFunction(() => window.__saved.length === 1, null, { timeout: 6000 });
    const ev = (await saved())[0];
    check('AFTER 3 SECONDS IT IS SAVED BY ITSELF: IN for 1001', ev.event_type === 'GATE_IN' && ev.employee_code === '1001' && ev.payload.source === 'card', JSON.stringify(ev));
    check('  the panel is gone, ready for the next card', !(await page.isVisible('#gate-tap')) && await page.isVisible('#gate-idle'));
    await page.waitForFunction(() => AttDB.allOutbox().then(r => r.every(x => x.state !== 'pending')), null, { timeout: 15000 });
    check('  and sent to the server like any scan', true);

    // 2. the same card again a moment later: nothing new
    await tap('0004728391'); await page.waitForTimeout(400);
    check('THE SAME CARD AGAIN AT ONCE SAVES NOTHING (a double tap is not an exit)', /just recorded IN/.test(await text(page, '#msg')) && !(await page.isVisible('#gate-tap')), await text(page, '#msg'));

    // 3. another person, Undo
    await tap('0004728392'); await page.waitForSelector('#gate-tap:not([hidden])');
    await page.click('#tap-undo'); await page.waitForTimeout(3500);
    check('UNDO: nothing saved for Sara', (await saved()).filter(x => x.employee_code === '1002').length === 0 && /Undone/.test(await text(page, '#msg')));

    // 4. switch to OUT, then a second card during the countdown saves the first at once
    await tap('0004728393'); await page.waitForSelector('#gate-tap:not([hidden])');
    await page.click('#tap-switch');
    check('"IT IS OUT INSTEAD" turns the tap into OUT', /OUT/.test(await text(page, '.tap-word')));
    await tap('0004728392');
    await page.waitForFunction(() => window.__saved.some(x => x.employee_code === '1003'), null, { timeout: 3000 });
    const b = (await saved()).filter(x => x.employee_code === '1003')[0];
    check('A NEW CARD DURING THE 3 SECONDS SAVES THE ONE BEFORE AT ONCE (Bilal, OUT)', b && b.event_type === 'GATE_OUT');
    check('  and shows the new person', /Sara Khan/.test(await text(page, '#tapname')));
    await page.click('#tap-save');
    await page.waitForFunction(() => window.__saved.some(x => x.employee_code === '1002'), null, { timeout: 3000 });
    check('SAVE NOW saves without waiting', true);

    // 5. an unknown card, and slow typing
    await tap('0009999999'); await page.waitForTimeout(400);
    check('AN UNKNOWN CARD SAYS SO and saves nothing', /not linked to anyone/.test(await text(page, '#msg')));
    await page.focus('#code');
    await page.keyboard.type('1004', { delay: 150 }); await page.waitForTimeout(500);
    check('SLOW TYPING IS NOT A CARD: the digits go into the code box as before', (await page.inputValue('#code')) === '1004' && /No Card/.test(await text(page, '#empname')), await page.inputValue('#code'));
    check('the card number is nowhere on the page or in the phone\'s storage',
      !(await page.content()).includes('0004728391') && !(await page.evaluate(() => Promise.all([AttDB.getAll('employees'), AttDB.allOutbox()]).then(v => JSON.stringify(v)))).includes('0004728391'));
  } catch (e) {
    check('the browser run completed', false, e && e.stack);
  } finally { await browser.close(); }
  console.log('RESULT' + JSON.stringify(out));
})();
JS);
echo "2. At the gate, in a browser\n";
$raw = (string) shell_exec('cd ' . escapeshellarg($work) . ' && timeout 180 node run.js ' . escapeshellarg("http://127.0.0.1:$port") . ' 2>&1');
$results = [];
if (preg_match('/RESULT(\[.*\])\s*$/s', $raw, $m)) { $results = json_decode($m[1], true) ?: []; }
ok(count($results) >= 15, 'the browser run reported its checks (' . count($results) . ')' . (count($results) ? '' : "\n$raw"));
foreach ($results as $r) { ok($r['pass'], $r['name'] . ($r['pass'] ? '' : ' — ' . $r['extra'])); }
@unlink($work . '/run.js'); @rmdir($work);

echo "3. What the server has\n";
$n = fn(string $sql, array $p = []) => (int) Db::value($sql, $p);
ok($n("SELECT COUNT(*) FROM att_events ev JOIN att_employees e ON e.id = ev.employee_id WHERE e.code = '1001' AND ev.event_type = 'GATE_IN'") === 1, 'Imran: one IN, from one tap (the double tap added nothing)');
ok($n("SELECT COUNT(*) FROM att_events ev JOIN att_employees e ON e.id = ev.employee_id WHERE e.code = '1002' AND ev.event_type = 'GATE_IN'") === 1, 'Sara: one IN (the undone tap never left the phone)');

echo "\nGATE CARD TAP: $P passed, $F failed\n";
exit($F ? 1 : 0);
