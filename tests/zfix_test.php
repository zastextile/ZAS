<?php
declare(strict_types=1);

/* THE TEN-POINT REVIEW — what was confirmed and fixed, held shut.
 *
 *   1 Permissions  Create / Edit / Delete each need their own tick on the stock screens
 *   2 Stock        two simultaneous posts of one document: only one writes
 *   3 Security     diagnose.php and the cache-clear script answer admins only
 *   4 Profit       an empty costing is not a costing; draft costings are named
 *   5 Currency     a missing exchange rate is flagged, not silently 1
 *   6 Database     the users.role list is widened, never replaced
 *   7 Mobile       the menu folds away on a phone and the page fits the screen
 *   8 Login        changing a password ends that user's other sessions
 *
 * Needs MySQL and the temporary test config: run through tests/run_att.sh.
 */

$B = __DIR__ . '/app_src/public_html/';
$P = 0; $F = 0;
function ok($c, $m) { global $P, $F; if ($c) { $P++; } else { $F++; echo "  FAIL: $m\n"; } }
function nocomments(string $s): string {
    $out = '';
    foreach (token_get_all($s) as $t) { $out .= is_array($t) ? (($t[0] === T_COMMENT || $t[0] === T_DOC_COMMENT) ? ' ' : $t[1]) : $t; }
    return $out;
}
/* The body of one function, from its signature to its closing brace. */
function body(string $src, string $sig): string {
    $a = strpos($src, $sig); if ($a === false) { return ''; }
    $i = strpos($src, '{', $a); $depth = 0;
    for ($n = strlen($src); $i < $n; $i++) { if ($src[$i] === '{') { $depth++; } elseif ($src[$i] === '}') { if (--$depth === 0) { return substr($src, $a, $i - $a + 1); } } }
    return '';
}
$src = fn(string $f) => nocomments((string) file_get_contents($B . $f));

$dsn = getenv('ATT_TEST_DSN') ?: 'mysql:host=127.0.0.1;port=3306;dbname=zas_att_test;charset=utf8mb4';
$dbu = getenv('ATT_TEST_USER') ?: 'zas';
$dbp = getenv('ATT_TEST_PASS'); if ($dbp === false) { $dbp = 'zaspw'; }
if (!is_file($B . 'config/config.php')) { echo "SKIP: no test config — run tests/run_att.sh\n"; exit(0); }
$newPdo = fn() => new PDO($dsn, $dbu, $dbp, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC, PDO::ATTR_EMULATE_PREPARES => false]);
try { $pdo = $newPdo(); } catch (Throwable $e) { echo "SKIP: no MySQL available ({$e->getMessage()})\n"; exit(0); }
function db(): PDO { global $pdo; return $pdo; }

$pdo->exec('SET FOREIGN_KEY_CHECKS=0');
foreach ($pdo->query("SELECT table_name AS tn FROM information_schema.tables WHERE table_schema = DATABASE()")->fetchAll() as $t) { $pdo->exec('DROP TABLE IF EXISTS `' . $t['tn'] . '`'); }
$pdo->exec('SET FOREIGN_KEY_CHECKS=1');
$legacy = ['cost_view','cost_create','cost_edit','cost_delete','cost_import','cost_print','cost_proforma','inv_view','inv_gate','inv_store','inv_consume','inv_master','inv_post','inv_adjust'];
$pdo->exec("CREATE TABLE users (id INT AUTO_INCREMENT PRIMARY KEY, name VARCHAR(150) NOT NULL, email VARCHAR(190) NOT NULL UNIQUE, password_hash VARCHAR(255) NOT NULL,
            role ENUM('admin','colleague','staff','production_staff','customer') NOT NULL DEFAULT 'colleague', department VARCHAR(60) NULL, company_name VARCHAR(190) NULL,
            can_see_rates TINYINT(1) NOT NULL DEFAULT 0, is_active TINYINT(1) NOT NULL DEFAULT 1, "
            . implode(', ', array_map(fn($c) => "$c TINYINT(1) NOT NULL DEFAULT 0", $legacy)) . ")");
$pdo->exec("CREATE TABLE zu_perm (id INT AUTO_INCREMENT PRIMARY KEY, user_id INT NOT NULL, module VARCHAR(24) NOT NULL, actions VARCHAR(12) NOT NULL DEFAULT '',
            updated_by INT NULL, updated_at DATETIME DEFAULT CURRENT_TIMESTAMP, UNIQUE KEY uniq_user_module (user_id, module), INDEX(user_id))");
$hash = password_hash('CorrectHorse9', PASSWORD_DEFAULT);
$mk = function (string $name, string $email, string $role) use ($pdo, $hash): int {
    $pdo->prepare("INSERT INTO users (name,email,password_hash,role,company_name) VALUES (?,?,?,?,?)")->execute([$name, $email, $hash, $role, $role === 'customer' ? 'Acme GmbH' : null]);
    return (int) $pdo->lastInsertId();
};
$admin = $mk('Admin Person', 'admin@zas.test', 'admin');
$col   = $mk('Kiran Colleague', 'col@zas.test', 'colleague');
$cust  = $mk('Carl Customer', 'cust@zas.test', 'customer');

/* ===================================================================== */
echo "1. Permissions: create, edit and delete are separate\n";
$U = ['id' => $col, 'role' => 'colleague', 'inv_gate' => 1]; $ZU = [];
function current_user() { global $U; return $U; }
function is_admin() { global $U; return ($U['role'] ?? '') === 'admin'; }
function zu_can($m, $a, $uid = null) { global $ZU; if (is_admin()) { return true; } return strpos($ZU[$m] ?? '', $a) !== false; }
require_once $B . 'includes/inventory.php';
$ZU = ['gate' => 'vu'];
ok(inv_perm('gate') === true, '(the old question, inv_perm("gate"), says yes to somebody with only Edit — that was the hole)');
ok(inv_may('gate', 'u') && !inv_may('gate', 'c') && !inv_may('gate', 'd'), 'EDIT ONLY: may edit, may not create, MAY NOT DELETE');
$ZU = ['gate' => 'vc'];
ok(inv_may('gate', 'c') && !inv_may('gate', 'u') && !inv_may('gate', 'd'), 'create only: may create, may not edit or delete');
$ZU = ['gate' => 'vd', 'opening' => 'vcu'];
ok(inv_may('gate', 'd') && !inv_may('gate', 'c') && inv_may('opening', 'u') && !inv_may('opening', 'd'), 'the Delete tick now decides delete, per screen');
$U['role'] = 'admin'; ok(inv_may('gate', 'd') && inv_may('consume', 'c'), 'admin always may'); $U['role'] = 'colleague';
$gate = $src('inv_gate.php');
ok(str_contains($gate, "\$canDelete = inv_may('gate', 'd');") && str_contains(body($gate, "if (\$act === 'delete')"), 'if (!$canDelete)'), 'inv_gate.php: the delete action asks for the Delete tick');
ok(str_contains(body($gate, "if (\$act === 'save')"), '$id > 0 ? !$canUpdate : !$canCreate'), '  saving an existing pass asks for Edit, a new one for Create');
ok(!str_contains(body($gate, "if (\$act === 'delete')"), '!$canEdit'), '  and no longer accepts the old "may write at all" flag');
foreach (['inv_opening.php' => 'opening', 'inv_store.php' => 'store', 'inv_consume.php' => 'consume'] as $f => $mod) {
    $s = $src($f);
    ok(str_contains($s, "inv_may('$mod', 'c')") && str_contains($s, "inv_may('$mod', 'u')") && str_contains(body($s, "if (\$act === 'save')"), '$id > 0 ? !$canUpdate : !$canCreate'), "$f: create and edit checked separately");
}
ok(str_contains(body($src('inv_opening.php'), "if (\$act === 'delete')"), 'if (!$canDelete)'), 'inv_opening.php: delete asks for the Delete tick');

/* ===================================================================== */
echo "2. Stock: one document, one writer\n";
$inv = $src('includes/inventory.php');
foreach (['inv_gate_post(int $gateId)', 'inv_gate_reverse(int $gateId', 'inv_gate_delete(int $gateId', 'inv_opening_post(int $id)', 'inv_opening_reverse(int $id',
          'inv_store_post(int $moveId)', 'inv_consumption_post(int $conId)', 'inv_doc_reverse(string $table'] as $fn) {
    $b = body($inv, 'function ' . $fn);
    $lockAt = strpos($b, 'new InvDocLock('); $readAt = strpos($b, 'SELECT');
    ok($lockAt !== false && str_contains($b, 'if (!$zlock->held) return inv_doc_busy();') && ($readAt === false || $lockAt < $readAt), "$fn takes the document lock before it reads anything");
}
$other = $newPdo();
$other->query("SELECT GET_LOCK('zas_doc_inv_gate_7', 0)")->fetchColumn();
$t0 = microtime(true); $l = new InvDocLock('inv_gate', 7, 1);
ok($l->held === false && microtime(true) - $t0 >= 0.9, 'while another request holds a document, a second one is refused after waiting');
ok(inv_doc_busy()['ok'] === false && str_contains(inv_doc_busy()['error'], 'Reload'), '  with a message that says to reload and check');
$l2 = new InvDocLock('inv_gate', 8, 1);
ok($l2->held === true, 'a different document is not held up');
$other->query("SELECT RELEASE_LOCK('zas_doc_inv_gate_7')")->fetchColumn();
$l3 = new InvDocLock('inv_gate', 7, 1); ok($l3->held === true, 'once the first has finished, the next gets the lock');
unset($l3);
ok((string) $other->query("SELECT GET_LOCK('zas_doc_inv_gate_7', 0)")->fetchColumn() === '1', 'the lock is let go when the function returns');
$other->query("SELECT RELEASE_LOCK('zas_doc_inv_gate_7')")->fetchColumn();
unset($l, $l2);

/* Really at the same time: eight processes all try to post document 1. Each
   does exactly what the posting functions do — read the status, and if it is
   still a draft, write the stock row and mark it posted. */
$pdo->exec("CREATE TABLE zt_doc (id INT PRIMARY KEY, status VARCHAR(12) NOT NULL)");
$pdo->exec("CREATE TABLE zt_ledger (id INT AUTO_INCREMENT PRIMARY KEY, doc_id INT NOT NULL, pid INT NOT NULL)");
$work = __DIR__ . '/.zfix'; @mkdir($work, 0777, true);
file_put_contents($work . '/poster.php', '<?php
[$_, $inventory, $dsn, $u, $p, $doc, $useLock] = $argv;
$pdo = new PDO($dsn, $u, $p, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC]);
function db(): PDO { global $pdo; return $pdo; }
function current_user() { return ["id" => 1, "role" => "admin"]; }
require $inventory;
function post_doc(int $id, bool $useLock): bool {
    if ($useLock) { $zlock = new InvDocLock("zt_doc", $id); if (!$zlock->held) return false; }
    $s = db()->prepare("SELECT status FROM zt_doc WHERE id=?"); $s->execute([$id]);
    if ($s->fetchColumn() !== "draft") return false;
    usleep(120000);                                   // the gap in which a second request used to slip through
    db()->beginTransaction();
    db()->prepare("INSERT INTO zt_ledger (doc_id, pid) VALUES (?, ?)")->execute([$id, getmypid()]);
    db()->prepare("UPDATE zt_doc SET status=\'posted\' WHERE id=?")->execute([$id]);
    db()->commit();
    return true;
}
echo post_doc((int)$doc, $useLock === "1") ? "POSTED" : "REFUSED";
');
$race = function (int $doc, bool $useLock) use ($work, $B, $dsn, $dbu, $dbp, $pdo): array {
    $pdo->prepare("INSERT INTO zt_doc (id, status) VALUES (?, 'draft')")->execute([$doc]);
    $procs = [];
    for ($i = 0; $i < 8; $i++) {
        $procs[] = proc_open(['php', $work . '/poster.php', $B . 'includes/inventory.php', $dsn, $dbu, $dbp, (string) $doc, $useLock ? '1' : '0'], [1 => ['pipe', 'w'], 2 => ['file', '/dev/null', 'a']], $pipes[$i]);
    }
    $posted = 0;
    foreach ($procs as $i => $pr) { $out = stream_get_contents($pipes[$i][1]); fclose($pipes[$i][1]); proc_close($pr); if (trim((string) $out) === 'POSTED') { $posted++; } }
    return [$posted, (int) $pdo->query("SELECT COUNT(*) FROM zt_ledger WHERE doc_id = $doc")->fetchColumn()];
};
[$p0, $rows0] = $race(1, false);
ok($rows0 > 1, "(without the lock, 8 simultaneous posts of one document wrote $rows0 stock rows — the fault, reproduced)");
[$p1, $rows1] = $race(2, true);
ok($p1 === 1 && $rows1 === 1, "WITH THE LOCK, 8 simultaneous posts wrote exactly 1 stock row and 7 were refused (posted $p1, rows $rows1)");

/* ===================================================================== */
echo "6. Database: the role list is widened, never replaced\n";
require_once $B . 'includes/helpers.php';
$roleType = fn() => (string) $pdo->query("SHOW COLUMNS FROM users LIKE 'role'")->fetch()['Type'];
users_role_ensure(['admin', 'colleague', 'staff', 'production_staff']);          // what production asks for
ok(str_contains($roleType(), "'customer'") && $pdo->query("SELECT role FROM users WHERE id = $cust")->fetchColumn() === 'customer', "production's shorter list no longer removes 'customer': the customer account keeps its role");
$pdo->exec("ALTER TABLE users MODIFY COLUMN role ENUM('admin','colleague','staff','customer') NOT NULL DEFAULT 'colleague'");
users_role_ensure(['admin', 'colleague', 'staff', 'production_staff']);
ok(str_contains($roleType(), "'production_staff'") && str_contains($roleType(), "'customer'"), 'a role that is missing is added, and nothing is taken away');
$before = $roleType(); users_role_ensure(['admin', 'customer']);
ok($roleType() === $before, 'when nothing is missing the table is not touched');
ok(!preg_match('/MODIFY COLUMN role ENUM/', $src('includes/production.php') . $src('includes/customer.php')), 'neither module writes its own list any more');

/* ===================================================================== */
echo "4-5. Profit and currency (the dashboard's own queries)\n";
$dash = $src('dashboard.php');
preg_match_all('/FROM final_costings fc[^)]*\)/', $dash, $fcq);
ok(count($fcq[0]) >= 5 && array_filter($fcq[0], fn($q) => !str_contains($q, 'fc.total_cost > 0')) === [], 'every query that reads a Final Costing ignores one whose cost is zero — an empty costing cannot show a 100% margin');
ok(str_contains($dash, "fc.status = 'draft' AND fc.total_cost > 0") && str_contains($dash, 'still draft (not locked)'), 'draft costings are counted and named beside Total Cost');
$toPkr = body($dash, 'function to_pkr(');
ok(str_contains($toPkr, '$fxMissing[$cur] = true') && str_contains($dash, 'No exchange rate for'), 'a currency with no rate is collected and the page says so in red');
eval('$fxMissing = []; ' . $toPkr);
to_pkr(100.0, 'USD', ['PKR' => 1.0, 'USD' => 0.0036]); to_pkr(50.0, 'EUR', ['PKR' => 1.0]); to_pkr(0.0, 'GBP', ['PKR' => 1.0]); to_pkr(10.0, 'PKR', ['PKR' => 1.0]);
ok(array_keys($fxMissing) === ['EUR'], 'to_pkr(): USD with a rate is fine, EUR without one is flagged, a zero amount and PKR are not');
ok(str_contains($src('costing_print.php'), 'cstFxMissing'), 'the costing print sheet names a missing rate too');

/* A costing keeps the currency it was made in. An invoice corrected from USD
   to PKR afterwards still has dollar costings, and the dashboard must convert
   them — the real case: 12,151,784 PKR of sales, 39,995 USD of cost. */
$fx = ['PKR' => 1.0, 'USD' => 1 / 274, 'EUR' => 1 / 315];
preg_match('/\$dashPkrCase = function.*?\n};\n\$costToInvCur = [^\n]+\n\$costValSub = [^\n]+\n/s', (string) file_get_contents($B . 'dashboard.php'), $mm);
ok(isset($mm[0]), 'the cost conversion is where the test expects it in dashboard.php');
eval($mm[0] ?? '');
$pdo->exec("CREATE TABLE shipments (id INT PRIMARY KEY, currency VARCHAR(10))");
$pdo->exec("CREATE TABLE shipment_items (id INT PRIMARY KEY, shipment_id INT, qty DECIMAL(14,3), amount DECIMAL(14,2))");
$pdo->exec("CREATE TABLE final_costings (id INT AUTO_INCREMENT PRIMARY KEY, shipment_item_id INT, shipment_id INT, currency VARCHAR(8), total_cost DECIMAL(16,2))");
$pdo->exec("INSERT INTO shipments VALUES (9, 'PKR'), (10, 'USD'), (11, 'USD')");
$pdo->exec("INSERT INTO shipment_items VALUES (1, 9, 10, 12151784.00), (2, 10, 100, 5000.00), (3, 11, 10, 900.00)");
$pdo->exec("INSERT INTO final_costings (shipment_item_id, shipment_id, currency, total_cost) VALUES (1, 9, 'USD', 3999.50), (2, 10, 'USD', 40.00), (3, 11, 'PKR', 13700.00)");
$costOf = fn(int $sid) => (float) $pdo->query("SELECT $costValSub FROM shipments s WHERE s.id = $sid")->fetchColumn();
$c9 = $costOf(9);
ok(abs($c9 - 39995 * 274) < 5, 'A PKR INVOICE WITH DOLLAR COSTINGS: 39,995 USD of cost is counted as 10,958,630 PKR, not 39,995 (' . number_format($c9, 0) . ')');
ok(abs((12151784 - $c9) / 12151784 * 100 - 9.8) < 0.2, '  so the margin reads 9.8%, not 99.7%');
ok(abs($costOf(10) - 4000) < 0.01, 'a costing in the invoice\'s own currency is left exactly as it was (4,000 USD)');
ok(abs($costOf(11) - 500) < 0.01, 'a rupee costing on a dollar invoice comes out in dollars: 137,000 PKR = 500 USD');
foreach (['final_costings', 'shipment_items', 'shipments'] as $t) { $pdo->exec("DROP TABLE $t"); }

/* ===================================================================== */
echo "3, 7, 8. Over the web\n";
/* The sign-in API reads the attendance roles, so its tables have to exist. */
foreach (['Db', 'Time', 'Migrations'] as $c) { require_once $B . "includes/att/$c.php"; }
\Att\Db::bind($pdo);
(new \Att\Migrations($B . 'sql/att'))->up();
$port = 8744;
$srv = proc_open(sprintf('exec php -S 127.0.0.1:%d -t %s', $port, escapeshellarg(rtrim($B, '/'))), [1 => ['file', '/dev/null', 'a'], 2 => ['file', '/dev/null', 'a']], $pp);
register_shutdown_function(static function () use ($srv) { if (is_resource($srv)) { proc_terminate($srv, 9); proc_close($srv); } });
for ($i = 0; $i < 60; $i++) { $cc = @fsockopen('127.0.0.1', $port, $e1, $e2, 0.2); if ($cc) { fclose($cc); break; } usleep(200000); }
$http = function (string $path, ?string $jar = null, ?array $post = null) use ($port): array {
    $ch = curl_init("http://127.0.0.1:$port/$path");
    $o = [CURLOPT_RETURNTRANSFER => true, CURLOPT_HEADER => false];
    if ($jar !== null) { $o[CURLOPT_COOKIEJAR] = $jar; $o[CURLOPT_COOKIEFILE] = $jar; }
    if ($post !== null) { $o[CURLOPT_POST] = true; $o[CURLOPT_POSTFIELDS] = json_encode($post); $o[CURLOPT_HTTPHEADER] = ['Content-Type: application/json']; }
    curl_setopt_array($ch, $o);
    $raw = (string) curl_exec($ch); $code = curl_getinfo($ch, CURLINFO_HTTP_CODE); curl_close($ch);
    return [$code, $raw];
};
$login = function (string $email, string $pw = 'CorrectHorse9') use ($http): string {
    $jar = tempnam(sys_get_temp_dir(), 'zfix');
    $http('api/v1/index.php?route=%2Fauth%2Flogin', $jar, ['email' => $email, 'password' => $pw]);
    return $jar;
};
[$c, $raw] = $http('diagnose.php');
ok($c === 404 && !str_contains($raw, 'Configuration') && !str_contains($raw, 'Database'), "SECURITY: diagnose.php answers a stranger with 404 and nothing else ($c)");
$cj = $login('col@zas.test');
[$c, $raw] = $http('diagnose.php', $cj);
ok($c === 404 && !str_contains($raw, 'Database'), '  and a signed-in colleague the same');
$aj = $login('admin@zas.test');
[$c, $raw] = $http('diagnose.php', $aj);
ok($c === 200 && str_contains($raw, 'DIAGNOSTIC COMPLETED'), '  an admin still gets the report');
ok(str_contains((string) shell_exec('cd ' . escapeshellarg($B) . ' && php diagnose.php 2>&1'), 'Database connection: OK'), '  and it still runs from SSH with no login');
[$c, $raw] = $http('oc_clear_tmp.php');
ok(in_array($c, [302, 303], true) && !str_contains($raw, 'opcache'), "the old open cache-clear address now sends a stranger to the login page ($c)");
ok(!str_contains($src('oc_clear_tmp.php'), 'opcache_reset') && str_contains($src('oc_clear_tmp.php'), 'require_admin()'), '  it clears nothing itself and asks for an admin');

$j1 = $login('col@zas.test'); $j2 = $login('col@zas.test');
$me = fn(string $jar) => $http('api/v1/index.php?route=%2Fauth%2Fme', $jar)[0];
ok($me($j1) === 200 && $me($j2) === 200, '(the colleague is signed in on two devices)');
$pdo->prepare('UPDATE users SET password_hash = ? WHERE id = ?')->execute([password_hash('NewSecret77', PASSWORD_DEFAULT), $col]);
sleep(1);
ok($me($j1) === 401 && $me($j2) === 401, 'LOGIN: after the password is changed, both old sessions are signed out');
$j3 = $login('col@zas.test', 'NewSecret77');
ok($me($j3) === 200 && $me($aj) === 200, '  the new password signs in, and nobody else was touched');
ok($me($login('col@zas.test')) === 401, '  the old password no longer works');

file_put_contents($work . '/phone.js', <<<'JS'
const { chromium } = require('playwright');
const BASE = process.argv[2];
(async () => {
  const out = []; const check = (name, pass, extra) => out.push({ name, pass: !!pass, extra: extra === undefined ? '' : String(extra) });
  const b = await chromium.launch();
  try {
    const c = await b.newContext({ viewport: { width: 390, height: 844 }, isMobile: true, hasTouch: true });
    const p = await c.newPage();
    await p.goto(BASE + '/att/manifest.webmanifest');
    await p.evaluate(async () => fetch('../api/v1/index.php?route=%2Fauth%2Flogin', { method: 'POST', credentials: 'same-origin', headers: { 'Content-Type': 'application/json' }, body: JSON.stringify({ email: 'admin@zas.test', password: 'CorrectHorse9' }) }));
    for (const url of ['/dashboard.php', '/users.php']) {
      await p.goto(BASE + url); await p.waitForSelector('.side-toggle');
      const m = await p.evaluate(() => { const s = document.querySelector('.sidebar'); return { h: Math.round(s.getBoundingClientRect().height), nav: !!s.querySelector('.nav').offsetParent, over: document.documentElement.scrollWidth - document.documentElement.clientWidth }; });
      check('MOBILE ' + url + ': the menu is a slim bar (' + m.h + 'px), its links folded away', m.h < 110 && m.nav === false, JSON.stringify(m));
      check('  and the page is exactly as wide as the phone — nothing cut off at the side', m.over <= 1, m.over);
    }
    await p.click('.side-toggle');
    check('  tapping Menu opens the links', await p.evaluate(() => !!document.querySelector('.sidebar .nav').offsetParent) && /Close/.test(await p.textContent('.side-toggle')));
    await p.click('.side-toggle');
    check('  tapping again closes them', await p.evaluate(() => !document.querySelector('.sidebar .nav').offsetParent));
    /* DESKTOP: the slim rail and the board on "/" (assets/js/zmenu.js) */
    const d = await b.newContext({ viewport: { width: 1366, height: 800 } });
    const q = await d.newPage();
    const errs = []; q.on('pageerror', e => errs.push(e.message));
    await q.goto(BASE + '/att/manifest.webmanifest');
    await q.evaluate(async () => fetch('../api/v1/index.php?route=%2Fauth%2Flogin', { method: 'POST', credentials: 'same-origin', headers: { 'Content-Type': 'application/json' }, body: JSON.stringify({ email: 'admin@zas.test', password: 'CorrectHorse9' }) }));
    await q.goto(BASE + '/users.php'); await q.waitForSelector('.zr .zr-b');
    let m = await q.evaluate(() => ({ w: Math.round(document.querySelector('.sidebar').getBoundingClientRect().width), nav: !!document.querySelector('#zmNav').offsetParent,
      toggle: getComputedStyle(document.querySelector('.side-toggle')).display, content: Math.round(document.querySelector('.content').getBoundingClientRect().width), over: document.documentElement.scrollWidth - document.documentElement.clientWidth }));
    check('DESKTOP MENU: the sidebar is a 62px rail of icons, the long list is folded away', m.w <= 64 && m.nav === false && m.toggle === 'none', JSON.stringify(m));
    check('  the page gains the width (' + m.content + 'px of 1366) and does not scroll sideways', m.content >= 1290 && m.over <= 1, JSON.stringify(m));
    check('  the section holding this page is lit on the rail', (await q.locator('.zr-b.on').getAttribute('title')) === 'Administration', await q.locator('.zr-b.on').getAttribute('title'));
    await q.hover('.zr-b[title="Attendance"]'); await q.waitForSelector('.zr-fly');
    let t = await q.textContent('.zr-fly');
    check('  pointing at Attendance slides its pages out', /People Finance/.test(t) && /Overtime/.test(t) && !/User Access/.test(t), t.slice(0, 120));
    check('  each is a real link to its page', (await q.locator('.zr-fly a.zx-item', { hasText: 'Overtime' }).getAttribute('href')) === 'att_overtime.php');
    await q.mouse.move(700, 400); await q.waitForFunction(() => !document.querySelector('.zr-fly'), null, { timeout: 4000 }).catch(() => {});
    check('  moving away puts them back', (await q.locator('.zr-fly').count()) === 0);
    await q.keyboard.press('/'); await q.waitForSelector('.zb');
    t = await q.textContent('.zb');
    check('BOARD: "/" opens the whole menu on one sheet with a search box', /Sales/.test(t) && /Attendance/.test(t) && /People Finance/.test(t) && await q.evaluate(() => document.activeElement.classList.contains('zb-in')));
    await q.locator('.zb a.zx-item', { hasText: 'People Finance' }).locator('.zx-star').click();
    check('  starring a page puts it at the top', (await q.locator('.zb-chip.f', { hasText: 'People Finance' }).count()) === 1 && /board|zb/.test(await q.evaluate(() => document.querySelector('.zb') ? 'zb' : '')));
    await q.fill('.zb-in', 'overt');
    check('  typing narrows it to the matching pages', (await q.locator('.zb-body a.zx-item').count()) === 1 && /Overtime/.test(await q.textContent('.zb-body')));
    await Promise.all([q.waitForURL(/att_overtime\.php/), q.keyboard.press('Enter')]);
    check('  Enter opens the first match', /att_overtime\.php/.test(q.url()));
    await q.waitForSelector('.zr .zr-b');
    await q.keyboard.press('Control+k'); await q.waitForSelector('.zb');
    t = await q.textContent('.zb-quick');
    check('  on the next page the star is still there and the last page is under "recent"', /People Finance/.test(t) && /Users/.test(t), t);
    await q.keyboard.press('Escape');
    check('  Esc closes the board', (await q.locator('.zb').count()) === 0);
    await q.focus('body'); await q.evaluate(() => { const i = document.createElement('input'); i.id = 'zt-typing'; document.querySelector('.content').prepend(i); i.focus(); });
    await q.keyboard.type('a/b');
    check('  "/" typed into a field is just a character — the board stays shut', (await q.inputValue('#zt-typing')) === 'a/b' && (await q.locator('.zb').count()) === 0);
    await q.click('.zr-b[title="Show the full menu"]');
    m = await q.evaluate(() => ({ w: Math.round(document.querySelector('.sidebar').getBoundingClientRect().width), nav: !!document.querySelector('#zmNav').offsetParent, fold: !!document.querySelector('.zr-fold').offsetParent }));
    check('PIN: the full menu comes back exactly as before, with a fold button', m.w >= 250 && m.nav && m.fold, JSON.stringify(m));
    await q.goto(BASE + '/users.php'); await q.waitForSelector('#zmNav');
    check('  and that choice is remembered on the next page', await q.evaluate(() => !!document.querySelector('#zmNav').offsetParent && !document.documentElement.classList.contains('zm-rail')));
    await q.click('.zr-fold');
    check('  the fold button returns to the rail', await q.evaluate(() => document.documentElement.classList.contains('zm-rail') && !document.querySelector('#zmNav').offsetParent));
    check('no script error on the desktop', errs.length === 0, errs.join(' | '));
    /* a colleague sees only their own pages on the board */
    const cc = await b.newContext({ viewport: { width: 1366, height: 800 } });
    const cp = await cc.newPage();
    await cp.goto(BASE + '/att/manifest.webmanifest');
    await cp.evaluate(async () => fetch('../api/v1/index.php?route=%2Fauth%2Flogin', { method: 'POST', credentials: 'same-origin', headers: { 'Content-Type': 'application/json' }, body: JSON.stringify({ email: 'col@zas.test', password: 'NewSecret77' }) }));
    await cp.goto(BASE + '/dashboard.php'); await cp.waitForSelector('.zr .zr-b');
    await cp.keyboard.press('/'); await cp.waitForSelector('.zb');
    t = await cp.textContent('.zb');
    check('PERMISSIONS: the board shows a colleague only what their own menu has — no Administration, no User Access', !/User Access/.test(t) && !/Administration/.test(t) && /Dashboard/.test(t), t.slice(0, 200));
    /* the phone is untouched by all of this */
    await p.goto(BASE + '/dashboard.php'); await p.waitForSelector('.side-toggle');
    check('PHONE: no rail and no board — the Menu button as before', await p.evaluate(() => !document.documentElement.classList.contains('zm-rail') && !document.querySelector('.zr').offsetParent && getComputedStyle(document.querySelector('.side-toggle')).display !== 'none'));
  } catch (e) { check('the browser run completed', false, e && e.message); }
  finally { await b.close(); }
  console.log('RESULT' + JSON.stringify(out));
})();
JS);
$outp = (string) shell_exec('cd ' . escapeshellarg($work) . ' && node phone.js ' . escapeshellarg("http://127.0.0.1:$port") . ' 2>&1');
if (preg_match('/RESULT(\[.*\])/s', $outp, $mm)) {
    foreach (json_decode($mm[1], true) ?: [] as $r) { ok($r['pass'], $r['name'] . ($r['pass'] || $r['extra'] === '' ? '' : ' — ' . $r['extra'])); }
} else { ok(false, 'the phone test produced a result: ' . substr($outp, 0, 300)); }

echo "\nTEN-POINT REVIEW: $P passed, $F failed\n";
exit($F ? 1 : 0);
