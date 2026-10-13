<?php
/* THE WEIGHT MODULE — profiles, and the weight of each shipment.
 *
 * afnan: "weight separately … profile name and number, linked with each
 * line of every shipment, summed up automatically from what is already
 * packed … one line of total weight per invoice line, all series summed
 * … gross from the empty package × total packages … 10% tolerance."
 *
 * Runs the REAL includes/weight.php, includes/packing.php and weight.php
 * on a real SQL database (SQLite, the live columns), then drives the page
 * in Chromium through a real server.
 *
 *   1  A PROFILE is checked on the server exactly as on the screen: types
 *      from the list, whole grams, a target the types must meet exactly,
 *      a sample reading that sets the target and records the empty package.
 *   2  A LINK: one profile per invoice line; never one made per piece on a
 *      line counted in sets; never a profile taken out of use. The next
 *      shipment of the same product is offered the profile used last.
 *   3  THE CALCULATION takes the quantity from packing — the phone's ranges
 *      and the desktop's rows — never from the browser. Gross is net plus
 *      the empty package × packages, a sample filling in an untyped kind.
 *   4  APPLY: within ±10% or refused before anything is written; lines,
 *      shipment totals and packing rows all add up exactly; a changed
 *      profile or packing is flagged, never applied by itself.
 *   5  FINISH PACKING after Apply keeps the weight on the rewritten rows.
 *   6  A PROFILE IN USE is never deleted, only taken out of use.
 *   7  THE PAGE: CSRF, permission, a locked shipment read-only.
 *   8  IN CHROMIUM: entry, sample, link, suggestion, typing, apply, preview.
 */

$B    = __DIR__ . '/app_src/public_html/';
$work = __DIR__ . '/.zweight';
$P = 0; $F = 0;
function ok(bool $c, string $m, $got = null): void {
    global $P, $F;
    if ($c) { $P++; }
    else { $F++; echo "  FAIL  $m" . ($got !== null ? "\n        got: " . var_export($got, true) : '') . "\n"; }
}
function head(string $n): void { echo "\n$n\n"; }

@mkdir($work . '/includes', 0777, true);
@unlink($work . '/db.sqlite');
foreach (['weight.php', 'm_pack.php', 'includes/weight.php', 'includes/packing.php', 'includes/mobile.php'] as $f) copy($B . $f, $work . '/' . $f);

$schema = <<<'SQL'
CREATE TABLE exp_meta (k TEXT PRIMARY KEY, v TEXT, updated_at TEXT);
INSERT INTO exp_meta VALUES ('pack_schema_version', '7', NULL), ('wt_schema_version', '1', NULL);
CREATE TABLE shipments (id INTEGER PRIMARY KEY, invoice_no TEXT, buyer_name TEXT, status TEXT DEFAULT 'draft',
  packing_status TEXT NOT NULL DEFAULT 'open', packing_completed_by INT, packing_completed_at TEXT,
  total_qty REAL DEFAULT 0, total_packages REAL DEFAULT 0, total_net_weight REAL DEFAULT 0,
  total_gross_weight REAL DEFAULT 0, updated_by INT, updated_at TEXT);
CREATE TABLE shipment_items (id INTEGER PRIMARY KEY, shipment_id INT, line_no INT, product_name TEXT, des_col TEXT,
  optional_value TEXT, department TEXT, qty REAL DEFAULT 0, unit TEXT DEFAULT 'Pcs', rate REAL DEFAULT 0,
  amount REAL DEFAULT 0, product_id INT);
CREATE TABLE packing_groups (id INTEGER PRIMARY KEY AUTOINCREMENT, shipment_id INT NOT NULL, line_no INT NOT NULL DEFAULT 0,
  invoice_item_id INT, product_name TEXT NOT NULL DEFAULT '', des_col TEXT NOT NULL DEFAULT '',
  optional_value TEXT NOT NULL DEFAULT '', unit_title TEXT NOT NULL DEFAULT 'Carton', serial_from INT NOT NULL DEFAULT 0,
  serial_to INT NOT NULL DEFAULT 0, packages INT NOT NULL DEFAULT 0, qty_mode TEXT NOT NULL DEFAULT 'per',
  qty_per_pkg REAL NOT NULL DEFAULT 0, total_qty REAL NOT NULL DEFAULT 0, assorted INT NOT NULL DEFAULT 0,
  size_label TEXT NOT NULL DEFAULT '', pkg_gross REAL NOT NULL DEFAULT 0, pkg_tare REAL NOT NULL DEFAULT 0,
  created_by INT, created_at TEXT, updated_by INT, updated_at TEXT, product_key TEXT NOT NULL DEFAULT '',
  weight_by_colour INT NOT NULL DEFAULT 0, colour_mix TEXT, serial_prefix TEXT NOT NULL DEFAULT '',
  serial_pad INT NOT NULL DEFAULT 0, pkg_spec TEXT NOT NULL DEFAULT '', edited INT NOT NULL DEFAULT 0);
CREATE TABLE packing_group_sizes (id INTEGER PRIMARY KEY AUTOINCREMENT, group_id INT NOT NULL,
  size_label TEXT NOT NULL DEFAULT '', qty_per_pkg REAL NOT NULL DEFAULT 0, total_qty REAL NOT NULL DEFAULT 0,
  colour_label TEXT NOT NULL DEFAULT '', style_label TEXT NOT NULL DEFAULT '');
CREATE TABLE packing_weight_lines (id INTEGER PRIMARY KEY AUTOINCREMENT, group_id INT NOT NULL,
  size_label TEXT NOT NULL DEFAULT '', line_no INT NOT NULL DEFAULT 0, w_type TEXT NOT NULL DEFAULT 'Fabric',
  w_name TEXT NOT NULL DEFAULT '', grams REAL NOT NULL DEFAULT 0, colour_label TEXT NOT NULL DEFAULT '');
CREATE TABLE packing_weight_std (id INTEGER PRIMARY KEY AUTOINCREMENT, product_key TEXT, size_label TEXT NOT NULL DEFAULT '',
  line_no INT NOT NULL DEFAULT 0, w_type TEXT NOT NULL DEFAULT 'Fabric', w_name TEXT NOT NULL DEFAULT '',
  grams REAL NOT NULL DEFAULT 0, updated_by INT, updated_at TEXT, colour_label TEXT NOT NULL DEFAULT '');
CREATE TABLE packing_palette (id INTEGER PRIMARY KEY AUTOINCREMENT, shipment_id INT, invoice_item_id INT, kind TEXT,
  label TEXT, sort_no INT DEFAULT 0, created_by INT, created_at TEXT);
CREATE TABLE packing_items (id INTEGER PRIMARY KEY AUTOINCREMENT, shipment_id INT, invoice_item_id INT, line_no INT,
  product_name TEXT, des_col TEXT, optional_value TEXT, pack_unit_title TEXT DEFAULT 'Carton', carton_from INT DEFAULT 0,
  carton_to INT DEFAULT 0, packages REAL DEFAULT 0, qty_per_carton REAL DEFAULT 0, qty_mode TEXT DEFAULT 'auto',
  total_qty REAL DEFAULT 0, net_weight REAL DEFAULT 0, gross_weight REAL DEFAULT 0, size_text TEXT, packing_group_id INT);
CREATE TABLE product_sizes (id INTEGER PRIMARY KEY, product_id INT, size_label TEXT);
CREATE TABLE products (id INTEGER PRIMARY KEY, name TEXT);
CREATE TABLE weight_profiles (id INTEGER PRIMARY KEY AUTOINCREMENT, reference TEXT NOT NULL UNIQUE, name TEXT NOT NULL,
  unit TEXT NOT NULL DEFAULT 'pcs', notes TEXT NOT NULL DEFAULT '', version INT NOT NULL DEFAULT 1, unit_net_grams INT NOT NULL DEFAULT 0,
  sample_qty INT, sample_gross_kg REAL, sample_net_kg REAL, sample_pkg_type TEXT NOT NULL DEFAULT '', active INT NOT NULL DEFAULT 1,
  created_by INT, created_at TEXT, updated_by INT, updated_at TEXT);
CREATE TABLE weight_profile_materials (id INTEGER PRIMARY KEY AUTOINCREMENT, profile_id INT NOT NULL, line_no INT NOT NULL DEFAULT 0,
  category TEXT NOT NULL, description TEXT NOT NULL DEFAULT '', grams INT NOT NULL DEFAULT 0);
CREATE TABLE weight_line_links (id INTEGER PRIMARY KEY AUTOINCREMENT, shipment_id INT NOT NULL, invoice_item_id INT NOT NULL,
  profile_id INT NOT NULL, product_key TEXT NOT NULL DEFAULT '', linked_by INT, linked_at TEXT, UNIQUE (shipment_id, invoice_item_id));
CREATE TABLE weight_tare (id INTEGER PRIMARY KEY AUTOINCREMENT, shipment_id INT NOT NULL, pkg_type TEXT NOT NULL, kg REAL NOT NULL DEFAULT 0,
  updated_by INT, updated_at TEXT, UNIQUE (shipment_id, pkg_type));
CREATE TABLE weight_applied (id INTEGER PRIMARY KEY AUTOINCREMENT, shipment_id INT NOT NULL, invoice_item_id INT NOT NULL, profile_id INT NOT NULL,
  profile_version INT NOT NULL DEFAULT 1, unit_grams INT NOT NULL DEFAULT 0, qty REAL NOT NULL DEFAULT 0, packages INT NOT NULL DEFAULT 0,
  pkg_key TEXT NOT NULL DEFAULT '', net_kg REAL NOT NULL DEFAULT 0, gross_kg REAL NOT NULL DEFAULT 0, UNIQUE (shipment_id, invoice_item_id));
CREATE TABLE weight_shipment (shipment_id INTEGER PRIMARY KEY, calc_net REAL NOT NULL DEFAULT 0, calc_gross REAL NOT NULL DEFAULT 0,
  final_net REAL NOT NULL DEFAULT 0, final_gross REAL NOT NULL DEFAULT 0, tare_key TEXT NOT NULL DEFAULT '', applied_by INT, applied_at TEXT);

INSERT INTO shipments (id, invoice_no, buyer_name) VALUES (7, 'ZAS/5300', 'Gulf Textiles LLC'), (6, 'ZAS/5200', 'Gulf Textiles LLC');
INSERT INTO shipment_items (id, shipment_id, line_no, product_name, des_col, optional_value, qty, unit) VALUES
  (71, 7, 1, 'T-Shirt', 'Cotton jersey', '', 600, 'Pcs'),
  (72, 7, 2, 'Bath Towel 500GSM', 'Combed', '', 400, 'PCS'),
  (73, 7, 3, 'Duvet Cover Set', 'King', '', 200, 'Sets'),
  (61, 6, 1, 'Bath-Towel 500 gsm', 'Old order', '', 300, 'Pcs');
INSERT INTO packing_items (shipment_id, invoice_item_id, line_no, product_name, des_col, optional_value, pack_unit_title, carton_from, carton_to, packages, qty_per_carton, total_qty)
  VALUES (7, 72, 1, 'Bath Towel 500GSM', 'Combed', '', 'Carton', 101, 110, 10, 20, 200);
SQL;

$bootstrap = <<<'PHP'
<?php
if (session_status() === PHP_SESSION_NONE) @session_start();
$_SESSION['user_id'] = 1;
function e($s){ return htmlspecialchars((string)$s, ENT_QUOTES); }
function require_login(){}
function csrf_token(){ return 'testtoken'; }
function csrf_field(){ return '<input type="hidden" name="_csrf" value="testtoken">'; }
function verify_csrf(){ if (($_POST['_csrf'] ?? '') !== 'testtoken') { http_response_code(419); exit('CSRF token mismatch.'); } }
$GLOBALS['ROLE'] = getenv('ROLE') ?: 'admin';
function current_user(): ?array { return ['id'=>1,'name'=>'Afnan','email'=>'a@z','role'=>$GLOBALS['ROLE'],'can_see_rates'=>1]; }
function is_admin(){ return $GLOBALS['ROLE'] === 'admin'; }
function is_colleague(){ return $GLOBALS['ROLE'] === 'colleague'; }
function is_staff(){ return $GLOBALS['ROLE'] === 'staff'; }
function is_production_staff(){ return $GLOBALS['ROLE'] === 'production_staff'; }
function assigned_shipment_ids(){ return ['ALL']; }
function redirect($u){ header('Location: ' . $u); echo "__REDIRECT__ $u"; exit; }
function audit_log(){}
function db() {
    static $p = null;
    if ($p === null) {
        $p = new PDO('sqlite:' . __DIR__ . '/../db.sqlite');
        $p->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $p->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
        $p->sqliteCreateFunction('NOW', fn() => date('Y-m-d H:i:s'), 0);
    }
    return $p;
}
PHP;
file_put_contents($work . '/includes/bootstrap.php', $bootstrap);
file_put_contents($work . '/includes/export.php', "<?php\nfunction exp_ensure_schema(){}\n");
$pdo = new PDO('sqlite:' . $work . '/db.sqlite'); $pdo->exec($schema); $pdo = null;

$GLOBALS['ROLE'] = 'admin';
require $work . '/includes/bootstrap.php';
require $work . '/includes/packing.php';
require $work . '/includes/weight.php';
$q = function (string $sql, array $a = []) { $s = db()->prepare($sql); $s->execute($a); return $s->fetchAll(); };
$items = $q("SELECT * FROM shipment_items WHERE shipment_id=7 ORDER BY line_no");
$items6 = $q("SELECT * FROM shipment_items WHERE shipment_id=6 ORDER BY line_no");
$one = fn($qty) => [['size' => '', 'colour' => '', 'style' => '', 'qty' => $qty]];
/* the phone packs T-Shirt: Carton 1–10 of 12, Carton 11–15 of 10, Bale 1–2 direct 100 */
pack_package_save(7, $items, ['item' => 71, 'type' => 'Carton', 'from' => 1, 'to' => 10, 'target' => 12, 'combos' => $one(12)]);
pack_package_save(7, $items, ['item' => 71, 'type' => 'Carton', 'from' => 11, 'to' => 15, 'target' => 10, 'combos' => $one(10)]);
pack_package_save(7, $items, ['item' => 71, 'type' => 'Bale', 'from' => 1, 'to' => 2, 'target' => 100, 'mode' => 'direct', 'combos' => $one(100)]);
$save = fn(array $over) => wt_profile_save(array_merge(['name' => 'T-shirt jersey', 'unit' => 'pcs', 'target' => '',
    'materials' => [['category' => 'Fabric', 'grams' => '200'], ['category' => 'PVC / Polybag', 'grams' => '10'], ['category' => 'Accessories', 'grams' => '20']]], $over));

head('1. A profile is checked on the server, as on the screen');
[$ok, $m, $pT] = $save(['target' => '230']);
ok($ok && $pT['reference'] === 'WP-0001' && $pT['unitNetGrams'] === 230 && $pT['version'] === 1, 'a balanced profile saves as WP-0001, 230 g', [$m, $pT]);
[$ok, $m] = $save(['name' => 'Other', 'target' => '250']);
ok(!$ok && $m === '20 g remaining. Complete the material total.', 'a target the types do not reach is refused, saying how much is left', $m);
[$ok, $m] = $save(['name' => 'Other', 'target' => '200']);
ok(!$ok && $m === '30 g above target. Correct the materials.', 'and a target they pass is refused, saying by how much', $m);
[$ok, $m] = $save(['name' => '  t-SHIRT jersey ']);
ok(!$ok && str_contains($m, 'already exists'), 'the same name in other capitals is refused', $m);
[$ok, $m] = $save(['name' => 'Bad grams', 'materials' => [['category' => 'Fabric', 'grams' => '12.5']]]);
ok(!$ok && str_contains($m, 'whole grams'), 'half a gram is refused', $m);
[$ok, $m] = $save(['name' => 'Bad type', 'materials' => [['category' => 'Cardboard', 'grams' => '10']]]);
ok(!$ok && str_contains($m, 'valid material type'), 'a type not on the list is refused', $m);
[$ok, $m] = $save(['name' => 'Nothing', 'materials' => [['category' => 'Fabric', 'grams' => '0'], ['category' => 'PVC / Polybag', 'grams' => '']]]);
ok(!$ok && str_contains($m, 'at least one material'), 'a profile with only skipped lines is refused', $m);
[$ok, $m, $pTowel] = $save(['name' => 'Bath towel 500 GSM', 'sampleQty' => '10', 'sampleGross' => '6.3', 'sampleNet' => '5.5', 'samplePkgType' => 'Carton',
    'materials' => [['category' => 'Fabric', 'grams' => '530'], ['category' => 'PVC / Polybag', 'grams' => '20'], ['category' => 'Polyester', 'grams' => '0']]]);
ok($ok && $pTowel['unitNetGrams'] === 550 && $pTowel['calibration']['packageTareGrams'] === 800 && $pTowel['calibration']['packageType'] === 'Carton'
   && count($pTowel['materials']) === 2,
   'a sample of 10 at 5.5 kg net sets 550 g, records an 800 g empty carton, and drops the 0 g line', $pTowel);
[$ok, $m] = $save(['name' => 'Frac', 'sampleQty' => '3', 'sampleNet' => '1', 'materials' => [['category' => 'Fabric', 'grams' => '333']]]);
ok(!$ok && str_contains($m, 'fractional gram'), 'a sample giving a fraction of a gram is refused', $m);
[$ok, $m] = $save(['name' => 'Net over gross', 'sampleQty' => '10', 'sampleGross' => '5', 'sampleNet' => '6', 'materials' => [['category' => 'Fabric', 'grams' => '600']]]);
ok(!$ok && str_contains($m, 'cannot exceed gross'), 'net heavier than gross is refused', $m);
[$ok, $m, $pDuvet] = $save(['name' => 'Duvet set King', 'unit' => 'sets', 'materials' => [['category' => 'Fabric', 'grams' => '1350'], ['category' => 'PVC / Polybag', 'grams' => '50']]]);
ok($ok && $pDuvet['reference'] === 'WP-0003' && $pDuvet['unit'] === 'sets', 'the next code follows on: WP-0003, per set', $pDuvet['reference'] ?? $m);

head('2. One profile per invoice line, the right unit, offered next time');
[$ok, $m] = wt_link(7, $items, 73, $pT['id']);
ok(!$ok && str_contains($m, 'counted in sets'), 'a profile per piece cannot go on a line counted in sets', $m);
[$ok, $m] = wt_link(7, $items, 71, $pT['id']);
ok($ok, 'T-Shirt links to WP-0001', $m);
[$ok] = wt_link(7, $items, 73, $pDuvet['id']);
ok($ok, 'and the duvet line to the per-set profile');
wt_profile_set_active($pDuvet['id'], false);
[$ok, $m] = wt_link(7, $items, 73, $pDuvet['id']);
ok(!$ok && str_contains($m, 'Not in use'), 'a profile taken out of use cannot be linked again', $m);
wt_profile_set_active($pDuvet['id'], true);
/* the towel was weighed on an older shipment under a slightly different name */
wt_link(6, $items6, 61, $pTowel['id']);
$sug = wt_suggest(7, $items[1]);
ok(($sug['reference'] ?? '') === 'WP-0002' && ($sug['from'] ?? '') === 'ZAS/5200',
   'Bath Towel 500GSM is offered WP-0002, used for "Bath-Towel 500 gsm" on ZAS/5200', $sug);
ok(wt_suggest(7, $items[2]) === null, 'nothing is offered for a product never weighed before');
ok(wt_suggest(7, array_merge($items[1], ['unit' => 'Sets'])) === null, 'nor a profile per piece for the same product sold in sets');
wt_profile_set_active($pTowel['id'], false);
ok(wt_suggest(7, $items[1]) === null, 'and nothing out of use is offered');
wt_profile_set_active($pTowel['id'], true);

head('3. The quantity comes from packing; gross from the empty packages');
$c = wt_calc(7, $items);
$L = []; foreach ($c['lines'] as $l) $L[$l['id']] = $l;
ok($L[71]['qty'] == 270 && $L[71]['packages'] === 17 && $L[71]['byType'] === ['Carton' => 15, 'Bale' => 2],
   'T-Shirt: 120 + 50 + 100 direct = 270 pcs in 15 cartons and 2 bales, all ranges summed', [$L[71]['qty'], $L[71]['byType']]);
ok($L[72]['qty'] == 200 && $L[72]['packages'] === 10 && ($L[72]['rows'][0]['mode'] ?? '') === 'Desktop',
   'Bath Towel: the 200 typed on the desktop packing list count too', $L[72]);
ok(abs($L[71]['net'] - 62.1) < 0.0005 && abs($L[71]['gross'] - 62.1) < 0.0005, 'net = 230 g × 270 ÷ 1,000 = 62.1 kg; no empty weight yet', [$L[71]['net'], $L[71]['gross']]);
ok($L[73]['qty'] == 0 && $L[73]['net'] == 0, 'a line not packed yet weighs nothing yet');
wt_link(7, $items, 72, $pTowel['id']);
$c = wt_calc(7, $items);
ok(($c['tares']['Carton']['kg'] ?? 0) == 0.8 && ($c['tares']['Carton']['source'] ?? '') === 'WP-0002 sample',
   'the carton weight nobody typed is filled from the towel profile\'s sample: 0.800 kg', $c['tares']['Carton'] ?? null);
ok(($c['tares']['Bale']['kg'] ?? 1) == 0 && ($c['tares']['Bale']['source'] ?? 'x') === '', 'a kind no sample covers stays empty', $c['tares']['Bale'] ?? null);
[$ok] = wt_tare_save(7, 'Carton', '0.750');
[$ok2] = wt_tare_save(7, 'Bale', '1.5');
[$bad, $mb] = wt_tare_save(7, 'Bale', 'abc');
ok($ok && $ok2 && !$bad, 'typed weights are kept, and nonsense is refused', $mb);
$c = wt_calc(7, $items);
$L = []; foreach ($c['lines'] as $l) $L[$l['id']] = $l;
ok(($c['tares']['Carton']['source'] ?? '') === 'typed' && $c['tares']['Carton']['kg'] == 0.75, 'a typed figure beats the sample', $c['tares']['Carton']);
ok(abs($L[71]['gross'] - (62.1 + 15 * 0.75 + 2 * 1.5)) < 0.0005, 'T-Shirt gross = 62.1 + 15 × 0.750 + 2 × 1.500 = 76.35 kg', $L[71]['gross']);
ok(abs($L[72]['net'] - 110) < 0.0005 && abs($L[72]['gross'] - 117.5) < 0.0005, 'Bath Towel: 550 g × 200 = 110 kg net, 117.5 kg gross', [$L[72]['net'], $L[72]['gross']]);

head('4. Apply — within ten per cent, adding up, never by itself');
/* linking already counted the weight, by itself (no Apply needed) */
$before = $q("SELECT total_net_weight n FROM shipments WHERE id=7")[0]['n'];
ok(abs($before - 172.1) < 0.0005, 'linking counted the weight by itself: 62.1 + 110 = 172.1 kg net, before any Apply', $before);
[$ok, $m] = wt_apply(7, $items, '150', '');
ok(!$ok && str_contains($m, 'more than 10%') && $q("SELECT total_net_weight n FROM shipments WHERE id=7")[0]['n'] == $before,
   'a final net 13% off is refused, and nothing changes', $m);
[$ok, $m] = wt_apply(7, $items, '180', '179');
ok(!$ok && str_contains($m, 'Gross cannot be less than net'), 'gross below net is refused', $m);
[$ok, $m] = wt_apply(7, $items);
$sh = $q("SELECT * FROM shipments WHERE id=7")[0];
ok($ok && abs($sh['total_net_weight'] - 172.1) < 0.0005 && abs($sh['total_gross_weight'] - 193.85) < 0.0005,
   'Apply writes 172.1 kg net and 193.85 kg gross to the shipment', [$m, $sh['total_net_weight'], $sh['total_gross_weight']]);
ok(str_contains($m, 'still without a profile') === false, 'every packed line had a profile, so nothing is pending', $m);
$rows = $q("SELECT invoice_item_id i, SUM(net_weight) n, SUM(gross_weight) g FROM packing_items WHERE shipment_id=7 GROUP BY invoice_item_id");
$R = []; foreach ($rows as $r) $R[$r['i']] = [round($r['n'], 3), round($r['g'], 3)];
ok(($R[72] ?? null) === [110.0, 117.5], 'the desktop row carries its line\'s weight', $R);
$ap = $q("SELECT invoice_item_id, net_kg, gross_kg FROM weight_applied WHERE shipment_id=7 ORDER BY invoice_item_id");
ok(count($ap) === 2 && abs($ap[0]['net_kg'] - 62.1) < 0.0005, 'one figure per invoice line is kept', $ap);
$c = wt_calc(7, $items);
ok($c['applied']['current'] === true, 'and the shipment reads as applied');
/* scale it within the tolerance: every line scales the same way */
[$ok, $m] = wt_apply(7, $items, '180', '200');
$ap = $q("SELECT SUM(net_kg) n, SUM(gross_kg) g FROM weight_applied WHERE shipment_id=7")[0];
ok($ok && abs($ap['n'] - 180) < 0.002 && abs($ap['g'] - 200) < 0.002, 'an override within 10% is spread over the lines, which still add up', [$m, $ap]);
wt_apply(7, $items);
/* a profile updated later flags the shipment; nothing changes by itself */
$save(['id' => $pT['id'], 'name' => 'T-shirt jersey', 'target' => '240',
       'materials' => [['category' => 'Fabric', 'grams' => '210'], ['category' => 'PVC / Polybag', 'grams' => '10'], ['category' => 'Accessories', 'grams' => '20']]]);
$c = wt_calc(7, $items);
$L = []; foreach ($c['lines'] as $l) $L[$l['id']] = $l;
ok(($L[71]['applied']['state'] ?? '') === 'profile_updated' && abs($L[71]['applied']['net'] - 62.1) < 0.0005
   && $c['applied']['current'] === false && abs($q("SELECT total_net_weight n FROM shipments WHERE id=7")[0]['n'] - 172.1) < 0.0005,
   'an updated profile says "apply again" and the shipment keeps its applied figures', $L[71]['applied']);
wt_apply(7, $items);
/* packing changes after Apply: flagged too */
pack_package_save(7, $items, ['item' => 71, 'type' => 'Carton', 'from' => 16, 'to' => 16, 'target' => 12, 'combos' => $one(12)]);
$c = wt_calc(7, $items);
$L = []; foreach ($c['lines'] as $l) $L[$l['id']] = $l;
ok(($L[71]['applied']['state'] ?? '') === 'changed' && $c['applied']['current'] === false, 'one more carton packed says "apply again" too', $L[71]['applied']);
wt_apply(7, $items);
$c = wt_calc(7, $items);
ok($c['applied']['current'] === true, 'and applying again brings it up to date');
$g0 = (float)$q("SELECT total_gross_weight g FROM shipments WHERE id=7")[0]['g'];
wt_tare_save(7, 'Carton', '0.800');
$g1 = (float)$q("SELECT total_gross_weight g FROM shipments WHERE id=7")[0]['g'];
ok(wt_calc(7, $items)['applied']['current'] === true && $g1 > $g0, 'a changed empty-carton weight moves the gross at once — no Apply', [$g0, $g1]);
wt_apply(7, $items);
/* same quantity, another kind of package: the empty weight differs, so it is flagged */
db()->exec("UPDATE packing_items SET pack_unit_title='Bale' WHERE shipment_id=7 AND packing_group_id IS NULL");
$c = wt_calc(7, $items);
$L = []; foreach ($c['lines'] as $l) $L[$l['id']] = $l;
ok(($L[72]['applied']['state'] ?? '') === 'changed', 'the same 200 towels moved from cartons to bales say "apply again" too', $L[72]['applied']);
db()->exec("UPDATE packing_items SET pack_unit_title='Carton' WHERE shipment_id=7 AND packing_group_id IS NULL");
wt_apply(7, $items);

head('5. Finish packing keeps the applied weight on the rows');
$tot0 = $q("SELECT total_net_weight n, total_gross_weight g FROM shipments WHERE id=7")[0];
[$okF, $mF] = pack_finish(7);
$sum = $q("SELECT SUM(net_weight) n, SUM(gross_weight) g FROM packing_items WHERE shipment_id=7")[0];
$tot1 = $q("SELECT total_net_weight n, total_gross_weight g FROM shipments WHERE id=7")[0];
ok($okF && abs($sum['n'] - $tot0['n']) < 0.0005 && abs($sum['g'] - $tot0['g']) < 0.0005,
   'Finish rewrites the rows and they still add up to the applied weight, exactly', [$mF, $sum, $tot0]);
ok($tot1 == $tot0, 'and Finish leaves the shipment\'s weight totals alone', [$tot0, $tot1]);
$tsRows = $q("SELECT net_weight n, gross_weight g FROM packing_items WHERE shipment_id=7 AND invoice_item_id=71 ORDER BY id");
ok(count($tsRows) === 4 && min(array_column($tsRows, 'n')) > 0, 'each T-Shirt range gets its share', $tsRows);
pack_reopen(7);

head('6. A profile in use is never deleted');
[$ok, $m] = wt_profile_delete($pT['id']);
ok(!$ok && str_contains($m, 'Not in use'), 'a profile on a shipment cannot be deleted', $m);
[$ok, $m, $spare] = $save(['name' => 'Spare', 'materials' => [['category' => 'Fabric', 'grams' => '5']]]);
[$ok2] = wt_profile_delete($spare['id']);
ok($ok && $ok2 && !$q("SELECT id FROM weight_profiles WHERE id=?", [$spare['id']]), 'an unused one can be');

/* ================================================================ page */
function page(string $work, array $get, string $env = '', ?string $body = null): string {
    $cmd = 'cd ' . escapeshellarg($work) . ' && ' . $env . ' QS=' . escapeshellarg(http_build_query($get))
         . ' BODY=' . escapeshellarg((string)$body) . ' ' . escapeshellarg(PHP_BINARY)
         . ' -d display_errors=1 -d error_reporting=E_ALL -r ' . escapeshellarg(
             '$_GET=[];parse_str((string)getenv("QS"),$_GET);'
           . '$_SERVER["REQUEST_METHOD"]=getenv("BODY")!==""?"POST":"GET";'
           . 'if(getenv("BODY")!==""){stream_wrapper_unregister("php");stream_wrapper_register("php",FakeIn::class);}'
           . 'class FakeIn{public $context;private $d;private $p=0;function stream_open(){$this->d=(string)getenv("BODY");return true;}'
           . 'function stream_read($n){$r=substr($this->d,$this->p,$n);$this->p+=strlen($r);return $r;}'
           . 'function stream_eof(){return $this->p>=strlen($this->d);}function stream_stat(){return [];}}'
           . 'require "weight.php";') . ' 2>&1';
    return (string)shell_exec($cmd);
}
head('7. The page: CSRF, permission, a locked shipment');
$html = page($work, ['id' => 7]);
ok(!preg_match('~(Warning|Notice|Deprecated|Fatal error|Parse error)~', $html), 'it renders with no PHP warning',
   preg_match('~.{0,200}(Warning|Notice|Fatal error).{0,200}~s', $html, $mm) ? $mm[0] : null);
preg_match('~var S = (\{.*?\});\nvar CSRF~s', $html, $sm);
$st = json_decode($sm[1] ?? '', true);
ok(is_array($st) && count($st['calc']['lines']) === 3 && $st['shipment']['invoice'] === 'ZAS/5300', 'the state is embedded and reads back', $sm[1] ?? null);
$list = page($work, []);
ok(str_contains($list, 'var S = ') && str_contains($list, 'ZAS\\/5300'), 'without a shipment it opens on profiles, with a shipment list to pick from');
$api = fn(array $b, string $env = '') => json_decode(page($work, ['id' => 7, 'api' => 1], $env, json_encode($b)), true);
$raw = page($work, ['id' => 7, 'api' => 1], '', json_encode(['op' => 'apply']));
ok(str_contains($raw, 'CSRF'), 'a change without the CSRF token is refused', $raw);
$r = $api(['_csrf' => 'testtoken', 'op' => 'tare', 'type' => 'Bale', 'kg' => '1.600']);
ok(($r['ok'] ?? false) && ($r['state']['calc']['tares']['Bale']['kg'] ?? 0) == 1.6, 'a change answers with the whole new state', $r['msg'] ?? $r);
$r = $api(['_csrf' => 'testtoken', 'op' => 'profile_delete', 'profileId' => $pT['id']]);
ok(($r['ok'] ?? true) === false && str_contains($r['msg'] ?? '', 'Not in use'), 'the delete rule holds through the API too', $r['msg'] ?? $r);
$pr = $q("SELECT * FROM shipments WHERE id=7")[0];
db()->exec("UPDATE shipments SET status='approved_locked' WHERE id=7");
$r = $api(['_csrf' => 'testtoken', 'op' => 'apply'], 'ROLE=staff');
ok(($r['ok'] ?? true) === false && str_contains($r['msg'] ?? '', 'locked'), 'a locked shipment cannot be changed by staff', $r['msg'] ?? $r);
$r = $api(['_csrf' => 'testtoken', 'op' => 'profile_save', 'profile' => ['name' => 'Staff profile', 'unit' => 'pcs', 'materials' => [['category' => 'Fabric', 'grams' => '7']]]], 'ROLE=staff');
ok(($r['ok'] ?? false) === true, 'but profiles are still theirs to make', $r['msg'] ?? $r);
db()->exec("UPDATE shipments SET status='draft' WHERE id=7");
$r = json_decode(page($work, ['api' => 1], 'ROLE=production_staff', json_encode(['_csrf' => 'testtoken', 'op' => 'profile_save'])), true);
$rawP = page($work, [], 'ROLE=production_staff');
ok(str_contains($rawP, 'Production Staff cannot open weight'), 'production staff are turned away at the door', substr($rawP, 0, 120));

/* ============================================================= Chromium */
head('8. In Chromium, through a real server');
$node = trim((string)shell_exec('command -v node 2>/dev/null'));
$root = trim((string)shell_exec('cd ' . escapeshellarg(__DIR__) . ' && npm root -g 2>/dev/null'));
if ($node === '' || !is_dir($root . '/playwright')) {
    echo "  (skipped — playwright not available)\n";
} else {
    /* a clean shipment for the browser: unlink, unapply, reset the tares */
    db()->exec("DELETE FROM weight_line_links WHERE shipment_id=7; DELETE FROM weight_applied WHERE shipment_id=7; DELETE FROM weight_shipment WHERE shipment_id=7; DELETE FROM weight_tare WHERE shipment_id=7;");
    $port = 19000 + random_int(0, 999);
    $srv = proc_open([PHP_BINARY, '-S', '127.0.0.1:' . $port, '-t', $work], [0 => ['pipe', 'r'], 1 => ['file', '/dev/null', 'w'], 2 => ['file', '/dev/null', 'w']], $pipes, $work);
    usleep(400000);
    $js = <<<'JS'
const { chromium } = require('playwright');
let R = {};
(async () => {
  const base = process.argv[2];
  const b = await chromium.launch({ executablePath: '/opt/pw-browsers/chromium' });
  const p = await b.newPage({ viewport: { width: 390, height: 844 } });
  const errs = []; let loads = 0;
  p.on('pageerror', e => errs.push(String(e)));
  p.on('console', m => { if (m.type() === 'error' && !/\b404\b/.test(m.text())) errs.push('console: ' + m.text()); });
  p.on('dialog', d => d.accept());
  p.on('framenavigated', f => { if (f === p.mainFrame()) loads++; });
  const W = (ms = 250) => p.waitForTimeout(ms);
  await p.goto(base + '/weight.php'); await p.evaluate(() => localStorage.clear()); await p.reload(); await W(400);
  loads = 0;
  R.width = await p.evaluate(() => document.documentElement.scrollWidth);
  R.tab0 = await p.evaluate(() => ZASWeight.tab);
  /* a profile with a fixed target: Save waits until the types meet it */
  await p.fill('#pName', 'Hotel sheet percale'); await p.fill('#pTarget', '650'); await p.fill('#pGrams', '600'); await W(80);
  R.lock = { rem: await p.locator('#pRem').textContent(), disabled: await p.locator('#wSave').isDisabled() };
  await p.locator('[data-a=next-material]').click(); await W(120);
  R.secondType = (await p.locator('.typepick').textContent()).trim();
  await p.fill('#pGrams', '50'); await W(80);
  R.balanced = await p.locator('#pState').textContent();
  await p.evaluate(() => { window.__t = document.querySelector('#wSave .t'); });
  await p.click('#pName'); await p.keyboard.press('End'); await p.keyboard.type('x'); await p.keyboard.press('Backspace'); await W(60);
  R.sameButton = await p.evaluate(() => document.querySelector('#wSave .t') === window.__t);
  await p.locator('#wSave').click(); await W(700);
  R.saved = { tab: await p.evaluate(() => ZASWeight.tab), toast: await p.locator('#wToast').textContent() };
  /* a sample reading sets the target and names the empty bale */
  await p.locator('#wDock [data-a=new]').click(); await W(150);
  await p.fill('#pName', 'Sample bale towel'); await p.locator('#pReading summary').click(); await W(80);
  await p.fill('#pSQty', '20'); await p.selectOption('#pSPkg', 'Bale'); await p.fill('#pSGross', '11.5'); await p.fill('#pSNet', '10'); await W(120);
  R.sample = { target: await p.inputValue('#pTarget'), formula: (await p.locator('#pFormula').innerText()).replace(/\s+/g, ' ') };
  /* the shipment */
  await p.goto(base + '/weight.php?id=7'); await W(500); loads = 0;
  R.tab1 = await p.evaluate(() => ZASWeight.tab);
  R.lineNames = await p.locator('#wMain details.pcard > summary .pname').allTextContents();
  /* the towel is offered the profile used last time */
  await p.locator('details[data-line="72"] > summary').click(); await W(120);
  R.sug = await p.locator('details[data-line="72"] .sug').textContent().catch(() => '');
  await p.locator('details[data-line="72"] .sug [data-a=choose]').click(); await W(700);
  R.towel = await p.locator('details[data-line="72"] .tw').textContent().catch(() => '');
  /* the T-shirt through the picker; the per-set duvet profile is not offered */
  await p.locator('details[data-line="71"] > summary').click(); await W(120);
  await p.locator('details[data-line="71"] [data-a=pick]').click(); await W(200);
  R.pickDisabled = await p.locator('#wpList button[disabled]').allTextContents();
  await p.fill('#wpSearch', 'jersey'); await W(120);
  await p.locator('#wpList [data-pid]:not([disabled])').first().click(); await W(700);
  R.tshirt = await p.locator('details[data-line="71"] .tw').textContent().catch(() => '');
  /* the carton weight, key by key, the gross following */
  R.cartonFrom = await p.locator('#tc-Carton').textContent();
  await p.click('#tare-Carton'); await W(150);
  await p.keyboard.type('0.75'); await W(120);
  R.typing = { val: await p.inputValue('#tare-Carton'), focus: await p.evaluate(() => document.activeElement.id), gross: await p.locator('#dGross').textContent() };
  await p.keyboard.press('Tab'); await W(700);
  /* a final weight too far off is refused, and what was typed stays */
  await p.locator('#finalBox summary').click(); await W(100);
  await p.fill('#fNet', '1'); await p.locator('#wApply').click(); await W(700);
  R.tooFar = { toast: await p.locator('#wToast').textContent(), open: await p.locator('#finalBox').evaluate(d => d.open), val: await p.inputValue('#fNet') };
  await p.fill('#fNet', ''); await p.locator('#wApply').click(); await W(800);
  R.applied = { btn: (await p.locator('#wApply .t').textContent()).trim(), note: await p.locator('#wMain .notice').first().textContent() };
  await p.locator('[data-a=preview]').click(); await W(200);
  R.preview = await p.locator('.wmodal table').evaluateAll(ts => ts.map(t => Array.from(t.querySelectorAll('tr')).slice(1).map(r => r.innerText.replace(/\s+/g, ' '))));
  R.loads = loads; R.errors = errs;
  R.widthEnd = await p.evaluate(() => document.documentElement.scrollWidth);
  console.log(JSON.stringify(R));
  await b.close();
})().catch(e => { R.crashed = String(e).slice(0, 400); console.log(JSON.stringify(R)); process.exit(0); });
JS;
    file_put_contents($work . '/drive.js', $js);
    $out = (string)shell_exec('NODE_PATH=' . escapeshellarg($root) . ' ' . escapeshellarg($node) . ' ' . escapeshellarg($work . '/drive.js') . ' ' . escapeshellarg("http://127.0.0.1:$port") . ' 2>&1');
    proc_terminate($srv); proc_close($srv);
    $R = json_decode(trim((string)substr($out, (int)strrpos($out, "\n{") ?: 0)), true) ?: json_decode(trim($out), true);
    ok(is_array($R) && !isset($R['crashed']), 'chromium drove the page', $out);
    $R = is_array($R) ? $R : [];
    ok(($R['width'] ?? 999) <= 390 && ($R['widthEnd'] ?? 999) <= 390, 'nothing scrolls sideways on a phone', [$R['width'] ?? null, $R['widthEnd'] ?? null]);
    ok(($R['tab0'] ?? '') === 'entry' && ($R['tab1'] ?? '') === 'link', 'it opens on Profile entry, or on the shipment when one is given');
    ok(($R['lock']['rem'] ?? '') === '50 g' && ($R['lock']['disabled'] ?? false) === true, 'with a 650 g target and 600 g entered, Save waits: 50 g remaining', $R['lock'] ?? null);
    ok(($R['secondType'] ?? '') === 'PVC / Polybag ⌄', 'Next material moves on to the next type', $R['secondType'] ?? null);
    ok(($R['balanced'] ?? '') === '✓ Balanced', 'and 600 + 50 balances', $R['balanced'] ?? null);
    ok(($R['sameButton'] ?? false) === true, 'typing does not rebuild the Save button under the finger, so the first tap always lands');
    ok(($R['saved']['tab'] ?? '') === 'saved' && str_contains($R['saved']['toast'] ?? '', 'saved · Hotel sheet percale'), 'one tap saves it', $R['saved'] ?? null);
    ok(($R['sample']['target'] ?? '') === '500' && str_contains($R['sample']['formula'] ?? '', 'Empty bale: 1,500 g'),
       'a sample of 20 at 10 kg net sets 500 g each, and the empty bale is 1,500 g', $R['sample'] ?? null);
    ok(($R['lineNames'] ?? []) === ['T-Shirt #1', 'Bath Towel 500GSM #2', 'Duvet Cover Set #3'], 'one card per invoice line', $R['lineNames'] ?? null);
    ok(str_contains($R['sug'] ?? '', 'WP-0002') && str_contains($R['sug'] ?? '', 'ZAS/5200'), 'the towel is offered the profile used on ZAS/5200', $R['sug'] ?? null);
    ok(str_contains($R['towel'] ?? '', '110.000 kg net'), 'one tap links it: 550 g × 200 = 110 kg', $R['towel'] ?? null);
    ok(count(array_filter($R['pickDisabled'] ?? [], fn($t) => str_contains($t, 'Duvet set King'))) === 1, 'the per-set profile cannot be picked for a line in pieces', $R['pickDisabled'] ?? null);
    ok(str_contains($R['tshirt'] ?? '', '67.680 kg net'), 'T-Shirt, with the carton packed in part 4: 240 g × 282 pcs = 67.68 kg', $R['tshirt'] ?? null);
    ok(str_contains($R['cartonFrom'] ?? '', 'from WP-0002 sample'), 'the carton weight comes from the towel\'s sample until typed', $R['cartonFrom'] ?? null);
    ok(($R['typing']['val'] ?? '') === '0.75' && ($R['typing']['focus'] ?? '') === 'tare-Carton', 'typing keeps every key and the cursor', $R['typing'] ?? null);
    ok(str_contains($R['typing']['gross'] ?? '', '197.180'), 'and the gross follows while typing: 67.68 + 110 net + 26 cartons × 0.75 = 197.180 kg', $R['typing'] ?? null);
    ok(str_contains($R['tooFar']['toast'] ?? '', 'more than 10%') && ($R['tooFar']['open'] ?? false) && ($R['tooFar']['val'] ?? '') === '1',
       'a final net too far off is refused, and the figure typed stays', $R['tooFar'] ?? null);
    ok(($R['applied']['btn'] ?? '') === '✓ WEIGHT UP TO DATE' && str_contains($R['applied']['note'] ?? '', '✓ Up to date'), 'the page says the weight is up to date', $R['applied'] ?? null);
    $pv = $R['preview'] ?? [[], []];
    ok(count(array_filter($pv[0] ?? [], fn($r) => str_starts_with($r, 'T-Shirt #1 Carton'))) === 1 && count(array_filter($pv[0] ?? [], fn($r) => str_starts_with($r, 'T-Shirt #1 Bale'))) === 1,
       'packing rows: one per line and package type — the T-Shirt\'s three carton ranges are one row', $pv[0] ?? null);
    ok(count($pv[1] ?? []) === 3 && str_starts_with(end($pv[1]) ?: '', 'Total'), 'the weight summary has one row per packed line, then the total', $pv[1] ?? null);
    ok(($R['loads'] ?? 1) === 0, 'and not one page load', $R['loads'] ?? null);
    ok(($R['errors'] ?? ['?']) === [], 'no javascript error', $R['errors'] ?? null);
}

echo "\n" . ($F === 0 ? "ALL PASS   ($P checks)\n" : "FAILED  $F   (" . ($P + $F) . " checks)\n");
exit($F === 0 ? 0 : 1);
