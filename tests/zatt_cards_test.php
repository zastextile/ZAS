<?php
declare(strict_types=1);
/*
 * EMPLOYEE CARDS (owner, 23 Nov 2026) — People Finance › Cards.
 *   - a card is linked by TAPPING it; typing digits slowly does nothing
 *   - the card number is never sent, kept or shown: only its SHA-256
 *   - one card per person, one person per card; replace / unlink need a reason
 *   - every change is logged; the canteen asks, with a key, "may this card buy?"
 *   - nothing on att_employees changes (the phones' sync is untouched)
 */
$B = __DIR__ . '/app_src/public_html/';
$P = 0; $F = 0;
function ok($c, $m) { global $P, $F; if ($c) { $P++; } else { $F++; echo "  FAIL: $m\n"; } }

$dsn = getenv('ATT_TEST_DSN') ?: 'mysql:host=127.0.0.1;port=3306;dbname=zas_att_test;charset=utf8mb4';
$dbu = getenv('ATT_TEST_USER') ?: 'zas';
$dbp = getenv('ATT_TEST_PASS'); if ($dbp === false) { $dbp = 'zaspw'; }
if (!is_file($B . 'config/config.php')) { echo "SKIP: no test config — run tests/run_att.sh\n"; exit(0); }
foreach (['Db','Time','Migrations','Policy','Audit','Roles','Employees','Shifts','AdminService','CardService'] as $c) {
    require_once $B . "includes/att/$c.php";
}
use Att\CardService as C;
use Att\Db;
use Att\Employees;
use Att\Migrations;
use Att\Roles;

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
$mig->up(22);
$n = fn(string $sql, array $p = []) => (int) Db::value($sql, $p);
$pw = password_hash('CorrectHorse9', PASSWORD_DEFAULT);
$mk = function (string $name, string $email, string $role = 'colleague') use ($pdo, $pw): int {
    $pdo->prepare("INSERT INTO users (name,email,password_hash,role) VALUES (?,?,?,?)")->execute([$name, $email, $pw, $role]);
    return (int) $pdo->lastInsertId();
};
$admin = $mk('Admin Person', 'admin@zas.test', 'admin');
$gate  = $mk('Gul Gatekeeper', 'gate@zas.test');
$dS = Employees::createDepartment('Stitching');
$dP = Employees::createDepartment('Packing');
$emp = [];
foreach (range(1, 4) as $i) { $c = sprintf('E%03d', $i); $emp[$c] = Employees::create(['code' => $c, 'full_name' => "Worker $i", 'department_id' => $i < 4 ? $dS : $dP]); }
Roles::grant($gate, Roles::GATEKEEPER);
$h = fn(string $digits) => hash('sha256', $digits);

echo "1. Before the database step, nothing else is touched\n";
ok(!C::ready() && (C::people(null)['reason_code'] ?? '') === 'NOT_READY', 'without the tables every call says NOT_READY');
$cols0 = $n("SELECT COUNT(*) FROM information_schema.columns WHERE table_schema = DATABASE() AND table_name = 'att_employees'");
$upd0 = (string) Db::value('SELECT updated_at FROM att_employees WHERE id = ?', [$emp['E001']]);
$mig->up();
ok($n("SELECT COUNT(*) FROM information_schema.tables WHERE table_schema = DATABASE() AND table_name IN ('att_emp_cards','att_card_log')") === 2, 'the step makes the two card tables');
ok($n("SELECT COUNT(*) FROM information_schema.columns WHERE table_schema = DATABASE() AND table_name = 'att_employees'") === $cols0, '  and adds no column to att_employees');


echo "2. Linking (as the service sees it)\n";
/* the static cache in ready() is per request; run the rest through a fresh PHP process for the service checks */
$code = <<<'PHPCODE'
<?php
declare(strict_types=1);
$B = $argv[1];
foreach (['Db','Time','Policy','Audit','Roles','Employees','CardService'] as $c) require_once $B . "includes/att/$c.php";
use Att\CardService as C; use Att\Db;
$pdo = new PDO($argv[2], $argv[3], $argv[4], [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC, PDO::ATTR_EMULATE_PREPARES => false]);
Db::bind($pdo); Db::useUtc();
$e = json_decode($argv[5], true); $admin = (int) $argv[6]; $h = fn($d) => hash('sha256', $d);
$o = [];
$o['bad'] = C::link($e['E001'], '0004728391', false, '', null, $admin)['reason_code'] ?? '';
$o['link1'] = C::link($e['E001'], $h('0004728391'), false, '', null, $admin)['ok'] ?? false;
$o['taken'] = C::link($e['E002'], $h('0004728391'), false, '', null, $admin);
$o['same'] = C::link($e['E001'], $h('0004728391'), false, '', null, $admin)['reason_code'] ?? '';
$o['has'] = C::link($e['E001'], $h('0009999999'), false, '', null, $admin)['reason_code'] ?? '';
$o['noreason'] = C::link($e['E001'], $h('0009999999'), true, '', null, $admin)['reason_code'] ?? '';
$o['replace'] = C::link($e['E001'], $h('0009999999'), true, 'lost the old one', null, $admin)['replaced'] ?? null;
$o['oldfree'] = C::check($h('0004728391'))['reason'] ?? '';
$o['scope'] = C::link($e['E004'], $h('0001111111'), false, '', [999], $admin)['reason_code'] ?? '';
$o['check_off'] = C::check($h('0009999999'));
$o['can'] = C::canteen([$e['E001'], $e['E002']], true, null, $admin)['changed'] ?? 0;
$o['can_again'] = C::canteen([$e['E001']], true, null, $admin)['changed'] ?? -1;
$o['check_on'] = C::check($h('0009999999'));
$o['who'] = C::who($h('0009999999'), null);
$o['who_none'] = C::who($h('0000000001'), null)['found'] ?? null;
$o['who_hidden'] = C::who($h('0009999999'), [999])['hidden'] ?? null;
$o['unlink_noreason'] = C::unlink($e['E001'], '', null, $admin)['reason_code'] ?? '';
$o['link3'] = C::link($e['E003'], $h('0003333333'), false, '', null, $admin)['ok'] ?? false;
Db::run('UPDATE att_employees SET active = 0 WHERE id = ?', [$e['E003']]);
$o['check_left'] = C::check($h('0003333333'))['reason'] ?? '';
$o['link_left'] = C::link($e['E003'], $h('0004444444'), true, 'x', null, $admin)['reason_code'] ?? '';
$o['unlink'] = C::unlink($e['E003'], 'left the company', null, $admin)['ok'] ?? false;
$o['check_gone'] = C::check($h('0003333333'))['reason'] ?? '';
$o['people'] = C::people(null)['people'];
$o['people_scoped'] = count(C::people([(int) $argv[7]])['people']);
echo json_encode($o);
PHPCODE;
file_put_contents(__DIR__ . '/.zcards.php', $code);
$raw = shell_exec('php ' . escapeshellarg(__DIR__ . '/.zcards.php') . ' ' . implode(' ', array_map('escapeshellarg', [$B, $dsn, $dbu, $dbp, json_encode($emp), (string) $admin, (string) $dP])) . ' 2>&1');
@unlink(__DIR__ . '/.zcards.php');
$r = json_decode((string) $raw, true);
if (!is_array($r)) { echo "  FAIL: the service run did not finish:\n$raw\n"; $F++; $r = []; }
ok(($r['bad'] ?? '') === 'VALIDATION', 'a card NUMBER is refused: only a fingerprint is accepted');
ok(($r['link1'] ?? false) === true, 'a tapped card links to a person');
ok(($r['taken']['reason_code'] ?? '') === 'CARD_TAKEN' && str_contains((string) ($r['taken']['error'] ?? ''), 'Worker 1 (E001)'), '  the same card for someone else is refused, naming who has it');
ok(($r['same'] ?? '') === 'SAME_CARD', '  tapping it again for the same person says it is already theirs');
ok(($r['has'] ?? '') === 'HAS_CARD', 'a second card for someone who has one needs Replace');
ok(($r['noreason'] ?? '') === 'REASON_REQUIRED', '  and Replace needs a reason');
ok(($r['replace'] ?? null) === true, '  with a reason the card is replaced');
ok(($r['oldfree'] ?? '') === 'unknown_card', '  and the old card stops working at once');
ok(($r['scope'] ?? '') === 'NOT_FOUND', 'someone outside the user\'s departments cannot be given a card');
ok(($r['check_off']['allowed'] ?? true) === false && ($r['check_off']['reason'] ?? '') === 'canteen_off', 'THE CANTEEN CHECK: a linked card with Canteen off is refused');
ok(($r['can'] ?? 0) === 2 && ($r['can_again'] ?? -1) === 0, 'Canteen switched on for two people; switching on again changes nothing');
ok(($r['check_on']['allowed'] ?? false) === true && ($r['check_on']['name'] ?? '') === 'Worker 1' && ($r['check_on']['code'] ?? '') === 'E001',
   '  then the check says yes, with the name and code');
ok(!array_key_exists('card_hash', $r['check_on'] ?? []) && !isset($r['check_on']['pay_type']), '  and says nothing more: no fingerprint, no pay');
ok(($r['who']['full_name'] ?? '') === 'Worker 1' && ($r['who_none'] ?? null) === false, 'Who has this card? finds the owner, or nobody');
ok(($r['who_hidden'] ?? null) === true, '  and does not name someone outside the user\'s departments');
ok(($r['unlink_noreason'] ?? '') === 'REASON_REQUIRED', 'unlinking needs a reason');
ok(($r['check_left'] ?? '') === 'left', 'a card of someone who has LEFT is refused at the canteen');
ok(($r['link_left'] ?? '') === 'VALIDATION', '  and nobody who has left is given a card');
ok(($r['unlink'] ?? false) === true && ($r['check_gone'] ?? '') === 'unknown_card', 'unlinked: the card stops working');
$pp = array_column($r['people'] ?? [], null, 'code');
ok(($pp['E001']['has_card'] ?? 0) === 1 && ($pp['E001']['canteen_ok'] ?? 0) === 1 && ($pp['E004']['has_card'] ?? 1) === 0, 'the list shows who has a card and who may buy');
ok(!str_contains((string) $raw, '"card_hash"') && !str_contains(json_encode($r['people'] ?? []), hash('sha256', '0009999999')), '  and never a fingerprint');
ok(($r['people_scoped'] ?? 0) === 1, 'a user who sees one department gets only that department');
$log = array_column(Db::all('SELECT action FROM att_card_log ORDER BY id'), 'action');
ok($log === ['link', 'replace', 'canteen_on', 'canteen_on', 'link', 'unlink'], 'every change is logged in order: ' . implode(',', $log));
ok($n("SELECT COUNT(*) FROM att_card_log WHERE action = 'replace' AND reason = 'lost the old one' AND actor_id = ?", [$admin]) === 1, '  with the reason and who did it');
ok(!str_contains(json_encode(Db::all('SELECT * FROM att_card_log')), '0009999999'), '  never a card number');
ok((string) Db::value('SELECT updated_at FROM att_employees WHERE id = ?', [$emp['E001']]) === $upd0, 'att_employees.updated_at is untouched, so no phone re-syncs');

echo "3. Over the web\n";
$port = 8739;
$srv = proc_open(sprintf('exec php -S 127.0.0.1:%d -t %s', $port, escapeshellarg(rtrim($B, '/'))), [1 => ['file', '/dev/null', 'a'], 2 => ['file', '/dev/null', 'a']], $pipes);
register_shutdown_function(static function () use ($srv) { if (is_resource($srv)) { proc_terminate($srv, 9); proc_close($srv); } });
for ($i = 0; $i < 60; $i++) { $cc = @fsockopen('127.0.0.1', $port, $e1, $e2, 0.2); if ($cc) { fclose($cc); break; } usleep(200000); }
function sess(string $email): array
{
    global $port;
    $jar = tempnam(sys_get_temp_dir(), 'attcard');
    $ch = curl_init("http://127.0.0.1:$port/api/v1/index.php?route=%2Fauth%2Flogin");
    curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_COOKIEJAR => $jar, CURLOPT_COOKIEFILE => $jar, CURLOPT_POST => true,
        CURLOPT_POSTFIELDS => json_encode(['email' => $email, 'password' => 'CorrectHorse9']), CURLOPT_HTTPHEADER => ['Content-Type: application/json']]);
    $b = json_decode((string) curl_exec($ch), true); curl_close($ch);
    return [$jar, (string) ($b['csrf_token'] ?? '')];
}
function req(?array $s, string $method, string $route, ?array $body = null, array $headers = []): array
{
    global $port;
    $ch = curl_init("http://127.0.0.1:$port/api/v1/index.php?route=" . rawurlencode($route));
    $h = array_merge(['Content-Type: application/json'], $headers);
    if ($s) { $h[] = 'X-CSRF-Token: ' . $s[1]; }
    curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_CUSTOMREQUEST => $method, CURLOPT_HTTPHEADER => $h]);
    if ($s) { curl_setopt($ch, CURLOPT_COOKIEFILE, $s[0]); }
    if ($body !== null) { curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($body)); }
    $raw = (string) curl_exec($ch); $code = curl_getinfo($ch, CURLINFO_HTTP_CODE); curl_close($ch);
    return ['status' => $code, 'body' => json_decode($raw, true) ?? [], 'raw' => $raw];
}
$as = sess('admin@zas.test');
$gs = sess('gate@zas.test');
ok(req($gs, 'GET', '/finance/cards')['status'] === 403, 'a gatekeeper cannot open Cards');
ok(req($gs, 'POST', '/finance/cards/link', ['employee_id' => $emp['E004'], 'card' => $h('0005555555')])['status'] === 403, '  nor link a card');
$g = req($as, 'GET', '/finance/cards');
ok($g['status'] === 200 && count($g['body']['people'] ?? []) === 4, 'the administrator gets the list');
ok(req($as, 'POST', '/finance/cards/link', ['employee_id' => $emp['E004'], 'card' => $h('0005555555')], [])['status'] === 200, '  and links a card over the web');
$noCsrf = req([$as[0], ''], 'POST', '/finance/cards/unlink', ['employee_id' => $emp['E004'], 'reason' => 'x']);
ok(in_array($noCsrf['status'], [403, 419], true), '  without the CSRF token nothing changes');

/* the canteen's address: closed until a key is set, then key-only */
$cfgFile = $B . 'config/config.php';
$cfg0 = file_get_contents($cfgFile);
$closed = req(null, 'POST', '/canteen/card-check', ['card' => $h('0005555555')]);
ok($closed['status'] === 503, 'THE CANTEEN ADDRESS IS CLOSED until a key is set in config (got ' . $closed['status'] . ')');
$key = str_repeat('k7', 20);
file_put_contents($cfgFile, preg_replace('/\]\s*;\s*$/', "] + ['canteen_link_key' => '$key'];", rtrim($cfg0)));
sleep(3);   // the test web server may keep the old config compiled for up to 2 seconds (opcache)
$dbg = req(null, 'POST', '/canteen/card-check', ['card' => $h('0005555555')]);
ok($dbg['status'] === 403, '  with a key set, a call without it is refused (got ' . $dbg['status'] . ')');
ok(req(null, 'POST', '/canteen/card-check', ['card' => $h('0005555555')], ['X-Link-Key: wrong-key-wrong-key-wrong-key-wrong'])['status'] === 403, '  and a wrong key is refused');
$ck = req(null, 'POST', '/canteen/card-check', ['card' => $h('0005555555')], ['X-Link-Key: ' . $key]);
ok($ck['status'] === 200 && ($ck['body']['allowed'] ?? true) === false && ($ck['body']['reason'] ?? '') === 'canteen_off' && ($ck['body']['code'] ?? '') === 'E004',
   '  with the key: who it is, and refused because Canteen is off');
$hdr = shell_exec('curl -s -D - -o /dev/null -X POST -H "Content-Type: application/json" -H "X-Link-Key: ' . $key . '" --data ' . escapeshellarg(json_encode(['card' => $h('0005555555')])) . ' "http://127.0.0.1:' . $port . '/api/v1/index.php?route=%2Fcanteen%2Fcard-check"');
ok(stripos((string) $hdr, 'Cache-Control: no-store') !== false, '  and the answer is never cached (Cache-Control: no-store)');
file_put_contents($cfgFile, $cfg0);

echo "4. The page: a reader taps, a person types\n";
$work = __DIR__ . '/.zattcards';
@mkdir($work, 0777, true);
file_put_contents($work . '/run.js', <<<'JS'
const { chromium } = require('playwright');
const BASE = process.argv[2];
const out = [];
const check = (name, pass, extra) => out.push({ name, pass: !!pass, extra: extra === undefined ? '' : String(extra) });
(async () => {
  const browser = await chromium.launch();
  try {
    const ctx = await browser.newContext({ viewport: { width: 1280, height: 900 } });
    const p = await ctx.newPage();
    await p.goto(BASE + '/att/manifest.webmanifest');
    await p.evaluate(async () => fetch('../api/v1/index.php?route=%2Fauth%2Flogin', { method: 'POST', credentials: 'same-origin',
      headers: { 'Content-Type': 'application/json' }, body: JSON.stringify({ email: 'admin@zas.test', password: 'CorrectHorse9' }) }));
    const errs = []; p.on('pageerror', e => errs.push(e.message));
    const sent = []; p.on('request', r => { if (r.method() === 'POST') sent.push(r.postData() || ''); });
    await p.goto(BASE + '/att_finance.php#cards');
    await p.waitForSelector('#fin-body .cdpanel', { timeout: 15000 });
    check('People Finance has a Cards tab', await p.locator('#fin-tabs button', { hasText: /^Cards$/ }).count() === 1);
    await p.locator('#fin-body table.en tbody tr', { hasText: 'Worker 2' }).locator('button:has-text("Link (tap)")').click();
    await p.keyboard.type('0006666666', { delay: 4 }); await p.keyboard.press('Enter');
    await p.waitForFunction(() => /Card linked/.test(document.querySelector('.cdmsg').textContent), null, { timeout: 8000 }).catch(() => {});
    check('a reader tap links the card', /Card linked — Worker 2/.test(await p.textContent('.cdmsg')), await p.textContent('.cdmsg'));
    await p.click('button:has-text("Who has this card?")');
    await p.keyboard.type('0006666666', { delay: 180 }); await p.keyboard.press('Enter');
    await p.waitForTimeout(800);
    check('SLOW TYPING IS IGNORED — a card number cannot be typed in', !/belongs to/.test(await p.textContent('.cdmsg')), await p.textContent('.cdmsg'));
    await p.keyboard.type('0006666666', { delay: 4 }); await p.keyboard.press('Enter');
    await p.waitForFunction(() => /belongs to/.test(document.querySelector('.cdmsg').textContent), null, { timeout: 8000 }).catch(() => {});
    check('Who has this card? names the owner', /belongs to Worker 2/.test(await p.textContent('.cdmsg')));
    check('THE NUMBER IS NEVER SENT', !sent.some(s => s.includes('0006666666')), sent.join(' | ').slice(0, 300));
    check('  nor shown', !(await p.content()).includes('0006666666'));
    check('no script error', errs.length === 0, errs.join(' | '));
    await p.keyboard.press('Escape');
    await p.click('#fin-tabs button:has-text("Employee Ledger")');
    await p.waitForTimeout(800);
    check('the Employee Ledger still opens', errs.length === 0 && await p.locator('#fin-body').count() === 1, errs.join(' | '));
  } catch (e) { check('the browser run completed', false, e && e.message); }
  finally { await browser.close(); }
  console.log('RESULT' + JSON.stringify(out));
})();
JS);
$raw2 = (string) shell_exec('cd ' . escapeshellarg($work) . ' && timeout 180 node run.js ' . escapeshellarg("http://127.0.0.1:$port") . ' 2>&1');
$res = [];
if (preg_match('/RESULT(\[.*\])\s*$/s', $raw2, $m)) { $res = json_decode($m[1], true) ?: []; }
ok(count($res) >= 8, 'the browser run reported its checks (' . count($res) . ')' . (count($res) ? '' : "\n$raw2"));
foreach ($res as $x) { ok($x['pass'], $x['name'] . ($x['pass'] ? '' : ' — ' . $x['extra'])); }

echo "5. Undo\n";
$mig->down(22);
ok($n("SELECT COUNT(*) FROM information_schema.tables WHERE table_schema = DATABASE() AND table_name IN ('att_emp_cards','att_card_log')") === 0, 'the step undoes cleanly');
$mig->up();
ok($n("SELECT COUNT(*) FROM information_schema.tables WHERE table_schema = DATABASE() AND table_name = 'att_emp_cards'") === 1, '  and applies again');

echo "\nCARDS: $P passed, $F failed\n";
exit($F === 0 ? 0 : 1);
