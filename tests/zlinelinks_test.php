<?php
declare(strict_types=1);

/* LINE LINKS — parts in the product picker, the last price to this buyer,
 * and the "link later" list (includes/linelinks.php, assets/js/linelinks.js,
 * line_links.php). Needs MySQL and the temporary test config: run through
 * tests/run_att.sh. */

$B = __DIR__ . '/app_src/public_html/';
$P = 0; $F = 0;
function ok($c, $m) { global $P, $F; if ($c) { $P++; } else { $F++; echo "  FAIL: $m\n"; } }
$dsn = getenv('ATT_TEST_DSN') ?: 'mysql:host=127.0.0.1;port=3306;dbname=zas_att_test;charset=utf8mb4';
$dbu = getenv('ATT_TEST_USER') ?: 'zas';
$dbp = getenv('ATT_TEST_PASS'); if ($dbp === false) { $dbp = 'zaspw'; }
if (!is_file($B . 'config/config.php')) { echo "SKIP: no test config — run tests/run_att.sh\n"; exit(0); }
try { $pdo = new PDO($dsn, $dbu, $dbp, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC, PDO::ATTR_EMULATE_PREPARES => false]); }
catch (Throwable $e) { echo "SKIP: no MySQL available\n"; exit(0); }
function db(): PDO { global $pdo; return $pdo; }

$pdo->exec('SET FOREIGN_KEY_CHECKS=0');
foreach ($pdo->query("SELECT table_name AS tn FROM information_schema.tables WHERE table_schema = DATABASE()")->fetchAll() as $t) { $pdo->exec('DROP TABLE IF EXISTS `' . $t['tn'] . '`'); }
$pdo->exec('SET FOREIGN_KEY_CHECKS=1');
foreach (array_filter(array_map('trim', explode(';', (string) file_get_contents($B . 'sql/schema.sql')))) as $sql) { $pdo->exec($sql); }
$legacy = ['cost_view','cost_create','cost_edit','cost_delete','cost_import','cost_print','cost_proforma','inv_view','inv_gate','inv_store','inv_consume','inv_master','inv_post','inv_adjust'];
foreach ($legacy as $c) { try { $pdo->exec("ALTER TABLE users ADD COLUMN $c TINYINT(1) NOT NULL DEFAULT 0"); } catch (Throwable $e) {} }
try { $pdo->exec("ALTER TABLE users ADD COLUMN can_see_rates TINYINT(1) NOT NULL DEFAULT 1"); } catch (Throwable $e) {}
$pdo->exec("CREATE TABLE IF NOT EXISTS zu_perm (id INT AUTO_INCREMENT PRIMARY KEY, user_id INT NOT NULL, module VARCHAR(24) NOT NULL, actions VARCHAR(12) NOT NULL DEFAULT '',
            updated_by INT NULL, updated_at DATETIME DEFAULT CURRENT_TIMESTAMP, UNIQUE KEY uniq_user_module (user_id, module), INDEX(user_id))");
foreach (['shipment_items', 'proforma_items'] as $t) {}
$pdo->exec("CREATE TABLE products (id INT AUTO_INCREMENT PRIMARY KEY, name VARCHAR(200) NOT NULL, description TEXT NULL, category VARCHAR(80) NULL, default_unit VARCHAR(20) NOT NULL DEFAULT 'Pcs',
            fcl_40hc_qty DECIMAL(12,2) NULL, is_active TINYINT(1) NOT NULL DEFAULT 1)");
$pdo->exec("CREATE TABLE costing_versions (id INT AUTO_INCREMENT PRIMARY KEY, product_id INT NOT NULL, total_cost DECIMAL(16,4) NULL, currency VARCHAR(8) NULL)");
$pdo->exec("CREATE TABLE zp_parts (id INT AUTO_INCREMENT PRIMARY KEY, part_name VARCHAR(160) NOT NULL, uom VARCHAR(20) NULL, is_active TINYINT(1) NOT NULL DEFAULT 1)");
$pdo->exec("CREATE TABLE proforma_invoices (id INT AUTO_INCREMENT PRIMARY KEY, pi_no VARCHAR(40), pi_date DATE NULL, customer_name VARCHAR(200), currency VARCHAR(8) DEFAULT 'USD', status VARCHAR(20) DEFAULT 'draft',
            created_at DATETIME DEFAULT CURRENT_TIMESTAMP)");
$pdo->exec("CREATE TABLE proforma_items (id INT AUTO_INCREMENT PRIMARY KEY, proforma_id INT, product_name VARCHAR(200), product_id INT NULL, description VARCHAR(500) NULL, size VARCHAR(80) NULL,
            qty DECIMAL(14,3) DEFAULT 0, unit VARCHAR(40) NULL, unit_price DECIMAL(14,4) DEFAULT 0, amount DECIMAL(14,2) DEFAULT 0, sort_order INT DEFAULT 0)");
try { $pdo->exec("ALTER TABLE shipment_items ADD COLUMN product_id INT NULL"); } catch (Throwable $e) {}
$hash = password_hash('CorrectHorse9', PASSWORD_DEFAULT);
$pdo->prepare("INSERT INTO users (name,email,password_hash,role) VALUES ('Admin Person','admin@zas.test',?,'admin')")->execute([$hash]);
$pdo->exec("INSERT INTO products (name) VALUES ('Thermal Blanket'), ('Mattress Protector'), ('Old Retired Thing')");
$pdo->exec("UPDATE products SET is_active = 0 WHERE name = 'Old Retired Thing'");
$pdo->exec("INSERT INTO zp_parts (part_name, uom) VALUES ('Pillow Cover', 'Pc'), ('Fitted Sheet Panel', 'Pc')");
$pdo->exec("INSERT INTO shipments (id, invoice_no, invoice_date, buyer_name, currency, status) VALUES (1, 'ZAS 5001', '2026-10-01', 'AB Trader', 'USD', 'draft'), (2, 'ZAS 5002', '2026-11-01', 'AB Trader', 'USD', 'draft')");
$pdo->exec("INSERT INTO shipment_items (shipment_id, line_no, product_name, product_id, qty, unit, rate, amount) VALUES
            (1, 1, 'Thermal Blanket', 1, 100, 'Pcs', 7.25, 725), (1, 2, 'thermal blanket 150x200 grey', NULL, 50, 'Pcs', 7.10, 355), (1, 3, 'Pilow cover', NULL, 10, 'Pcs', 1.5, 15),
            (2, 1, 'Something Else Entirely', NULL, 5, 'Pcs', 3, 15)");
$pdo->exec("INSERT INTO proforma_invoices (pi_no, pi_date, customer_name, currency) VALUES ('PI-1', '2026-11-05', 'AB Trader', 'EUR')");
$pdo->exec("INSERT INTO proforma_items (proforma_id, product_name, product_id, qty, unit_price) VALUES (1, 'Mattres protector', NULL, 20, 9.5), (1, 'Thermal Blanket', 1, 10, 6.80)");

require_once $B . 'includes/helpers.php';
require_once $B . 'includes/linelinks.php';

echo "1. The part link, beside the product link\n";
ok(ll_ready() && (int) $pdo->query("SELECT COUNT(*) FROM information_schema.columns WHERE table_schema = DATABASE() AND column_name = 'part_id' AND table_name IN ('shipment_items','proforma_items')")->fetchColumn() === 2,
   'll_ready adds part_id to invoice and proforma lines, once');
ok(count(ll_parts()) === 2 && ll_parts()[0]['name'] === 'Fitted Sheet Panel', 'the active parts are offered, by name');
ll_set_part('shipment_items', 3, 1, 0);
ok(ll_row_parts('shipment_items', 'shipment_id', 1) === [3 => 1], 'a line linked to a part keeps it');
ll_set_part('shipment_items', 3, 1, 2);
ok(ll_row_parts('shipment_items', 'shipment_id', 1) === [], '  a line with a product has no part: never both');
ll_set_part('shipment_items', 3, 1, 0);

echo "2. The last price to this buyer\n";
$lp = ll_last_prices('ab trader', 'USD');
ok(($lp['p1'] ?? null) === 7.25 && ($lp['k1'] ?? null) === 1.5, 'per product and per part, in this currency, any spelling of the buyer — ' . json_encode($lp));
ok(!isset(ll_last_prices('AB Trader', 'EUR')['k1']) && ll_last_prices('AB Trader', 'EUR')['p1'] === 6.8, '  another currency has its own prices (the proforma in EUR)');
ok(ll_last_prices('', 'USD') === [] && ll_last_prices('Nobody', 'USD') === [], '  no buyer, or a new buyer: nothing is offered');

echo "3. Link later\n";
$un = ll_unlinked();
$names = array_column($un, 'name');
ok(in_array('thermal blanket 150x200 grey', $names, true) && in_array('Mattres protector', $names, true) && !in_array('Pilow cover', $names, true) && !in_array('Thermal Blanket', $names, true),
   'the list holds every line with neither link — from invoices and proformas — and nothing already linked');
$prods = $pdo->query("SELECT id, name FROM products WHERE is_active = 1")->fetchAll();
$parts = ll_parts();
ok(ll_suggest('thermal blanket 150x200 grey', $prods, $parts) === ['p', 1, 'Thermal Blanket'], 'a longer wording is matched to its product');
ok(ll_suggest('Mattres protector', $prods, $parts) === ['p', 2, 'Mattress Protector'], '  a misspelling too');
ok(ll_suggest('Something Else Entirely', $prods, $parts) === null, '  and a line like nothing is offered nothing — no guessing');
$sid = (int) $pdo->query("SELECT id FROM shipment_items WHERE product_name = 'thermal blanket 150x200 grey'")->fetchColumn();
$r = ll_link('inv', $sid, 'p1');
ok($r['ok'] && $r['changed'] && (int) $pdo->query("SELECT product_id FROM shipment_items WHERE id = $sid")->fetchColumn() === 1
   && $pdo->query("SELECT product_name FROM shipment_items WHERE id = $sid")->fetchColumn() === 'thermal blanket 150x200 grey', 'LINK: the line now points at the product; the printed wording is untouched');
ok(ll_link('inv', $sid, 'p2')['changed'] === false, '  a line already linked is not changed again (two people at once)');
ok(ll_link('inv', $sid, 'p3')['ok'] === false && ll_link('pf', 1, 'k99')['ok'] === false, '  a retired product or an unknown part is refused');

echo "4. On the screens\n";
$sv = (string) file_get_contents($B . 'shipment_view.php'); $pf = (string) file_get_contents($B . 'proforma.php');
foreach (['shipment_view.php' => $sv, 'proforma.php' => $pf] as $f => $s) {
    $a = strpos($s, 'echo ll_script('); $b = strpos($s, "LOV.register('prod'"); $c = strpos($s, 'assets/js/lov.js');
    ok($a !== false && $c !== false && $b !== false && $c < $a && $a < $b, "$f loads the helper after the picker library and before the picker is registered");
}
ok(str_contains((string) file_get_contents($B . 'shipment_save.php'), "ll_set_part('shipment_items'") && str_contains($pf, "ll_set_part('proforma_items'"), 'both saves keep the part link');
$save = (string) file_get_contents($B . 'shipment_save.php');
ok(strpos($save, 'll_ready()') < strpos($save, 'db()->beginTransaction();'), '  the column is made sure of before the save transaction (adding a column would end it)');

echo "5. Over the web\n";
foreach (['Db', 'Time', 'Migrations'] as $c) { require_once $B . "includes/att/$c.php"; }
\Att\Db::bind($pdo);
(new \Att\Migrations($B . 'sql/att'))->up();
$port = 8746;
$srv = proc_open(sprintf('exec php -S 127.0.0.1:%d -t %s', $port, escapeshellarg(rtrim($B, '/'))), [1 => ['file', '/dev/null', 'a'], 2 => ['file', '/tmp/zll_srv.log', 'a']], $pp);
register_shutdown_function(static function () use ($srv) { if (is_resource($srv)) { proc_terminate($srv, 9); proc_close($srv); } });
for ($i = 0; $i < 60; $i++) { $cc = @fsockopen('127.0.0.1', $port, $e1, $e2, 0.2); if ($cc) { fclose($cc); break; } usleep(200000); }
$work = sys_get_temp_dir() . '/zll_' . getmypid(); @mkdir($work);
@symlink(dirname(__DIR__) . '/node_modules', $work . '/node_modules');
file_put_contents($work . '/run.js', <<<'JS'
const { chromium } = require('playwright');
const BASE = process.argv[2]; const SHOTS = process.argv[3] || '';
(async () => {
  const out = []; const check = (name, pass, extra) => out.push({ name, pass: !!pass, extra: extra === undefined ? '' : String(extra) });
  const b = await chromium.launch();
  try {
    const c = await b.newContext({ viewport: { width: 1366, height: 900 }, deviceScaleFactor: SHOTS ? 2 : 1 });
    const p = await c.newPage();
    const errs = []; p.on('pageerror', e => errs.push(e.message));
    await p.goto(BASE + '/att/manifest.webmanifest');
    await p.evaluate(async () => fetch('../api/v1/index.php?route=%2Fauth%2Flogin', { method: 'POST', credentials: 'same-origin', headers: { 'Content-Type': 'application/json' }, body: JSON.stringify({ email: 'admin@zas.test', password: 'CorrectHorse9' }) }));
    const j = await p.evaluate(async () => (await fetch('../line_links.php?last=1&ship=0&buyer=AB%20Trader&cur=USD', { credentials: 'same-origin' })).json());
    check('LAST PRICE over the web: AB Trader in USD, product (the newest line, linked above: 7.10) and part', j.ok && j.prices.p1 === 7.1 && j.prices.k1 === 1.5, JSON.stringify(j));
    await p.goto(BASE + '/line_links.php');
    await p.waitForSelector('#llForm');
    const t = await p.textContent('body');
    check('LINK LATER: the unlinked lines, with suggestions', /Mattres protector/.test(t) && /Something Else Entirely/.test(t) && /with a suggestion/.test(t), t.slice(0, 300));
    const sug = await p.inputValue('#llForm tr:has-text("Mattres protector") .to');
    check('  the misspelt proforma line is offered Mattress Protector', sug === 'Product — Mattress Protector', sug);
    if (SHOTS) await p.screenshot({ path: SHOTS + '/link-later.png', fullPage: true });
    await p.click('button:has-text("Tick all with a suggestion")');
    await p.click('button:has-text("Link ticked lines")');
    await p.waitForSelector('#llForm, .empty');
    const t2 = await p.textContent('body');
    check('  Link ticked lines: they are linked and leave the list; the one with no suggestion stays', /line(s)? linked/.test(t2) && !/Mattres protector/.test(t2) && /Something Else Entirely/.test(t2), t2.slice(0, 400));
    await p.goto(BASE + '/shipment_view.php?id=2');
    await p.waitForSelector('#invoiceRows tr.item-row', { timeout: 15000 });
    check('INVOICE: the helper is on the page', await p.evaluate(() => window.ZLL_ready === true));
    const f = p.locator('#invoiceRows tr.item-row').first().locator('[data-c="name"]');
    await f.fill('');
    await f.type('Pillow');
    await p.waitForSelector('.lov-pop, .lovpop, [class*="lov"] .zll-tag', { timeout: 8000 }).catch(() => {});
    const pop = await p.evaluate(() => document.body.innerHTML.includes('zll-tag'));
    check('  typing "Pillow" offers the part, tagged Part', pop);
    if (SHOTS) await p.screenshot({ path: SHOTS + '/picker-part.png' });
    await p.keyboard.press('Enter');
    await p.waitForTimeout(800);
    const row = await p.evaluate(() => { const tr = document.querySelector('#invoiceRows tr.item-row'); return { pid: tr.querySelector('.pid').value, pt: tr.querySelector('.ptid') && tr.querySelector('.ptid').value, name: tr.querySelector('[data-c="name"]').value,
      rate: tr.querySelector('[data-c="rate"]') ? tr.querySelector('[data-c="rate"]').value : '', chip: tr.querySelector('.linkchip').textContent, note: (tr.querySelector('.zll-last') || {}).textContent || '' }; });
    check('  picking it links the line to the part (no product), and the chip says Part', row.pid === '0' && row.pt === '1' && /Part: Pillow Cover/.test(row.chip), JSON.stringify(row));
    check('  the price box keeps its own figure (it was not empty)', row.rate === '3', JSON.stringify(row));
    await p.evaluate(() => { const r = document.querySelector('#invoiceRows tr.item-row [data-c="rate"]'); r.value = ''; });
    const f2 = p.locator('#invoiceRows tr.item-row').first().locator('[data-c="name"]');
    await f2.click(); await f2.fill(''); await f2.type('Thermal');
    await p.waitForTimeout(400);
    if (!(await p.evaluate(() => !!document.querySelector('.lov.open')))) { await p.keyboard.press('ArrowDown'); }
    await p.waitForSelector('.lov.open .lov-r:has-text("Thermal Blanket")', { timeout: 5000 });
    await p.locator('.lov.open .lov-r', { hasText: 'Thermal Blanket' }).first().click(); await p.waitForTimeout(1200);
    const row2 = await p.evaluate(() => { const tr = document.querySelector('#invoiceRows tr.item-row'); return { pid: tr.querySelector('.pid').value, pt: tr.querySelector('.ptid').value, rate: tr.querySelector('[data-c="rate"]').value, note: (tr.querySelector('.zll-last') || {}).textContent || '' }; });
    check('  picking a product with an empty price fills the last price to AB Trader (7.10) and says so', row2.pid === '1' && row2.pt === '0' && row2.rate === '7.1' && /last price to AB Trader/.test(row2.note), JSON.stringify(row2));
    if (SHOTS) await p.screenshot({ path: SHOTS + '/picker-last-price.png' });
    check('no script error', errs.length === 0, errs.join(' | '));
  } catch (e) { check('the browser run completed', false, e && e.message); }
  finally { await b.close(); }
  console.log('RESULT' + JSON.stringify(out));
})();
JS);
$outp = (string) shell_exec('cd ' . escapeshellarg($work) . ' && timeout 240 node run.js ' . escapeshellarg("http://127.0.0.1:$port") . ' ' . escapeshellarg((string) (getenv('LL_SHOTS') ?: '')) . ' 2>&1');
if (preg_match('/RESULT(\[.*\])/s', $outp, $mm)) { foreach (json_decode($mm[1], true) ?: [] as $r) { ok($r['pass'], $r['name'] . ($r['pass'] || $r['extra'] === '' ? '' : ' — ' . $r['extra'])); } }
else { ok(false, 'the browser test produced a result: ' . substr($outp, 0, 600)); }
ok((int) $pdo->query("SELECT product_id FROM proforma_items WHERE product_name = 'Mattres protector'")->fetchColumn() === 2, 'after the screen: the proforma line is linked in the database');

echo "\nLINE LINKS: $P passed, $F failed\n";
exit($F ? 1 : 0);
