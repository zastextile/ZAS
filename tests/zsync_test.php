<?php
/* THE PHONE, THE DESKTOP AND THE INVOICE — one set of figures.
 *
 * afnan: "cross check packing list desktop and mobile are matching each
 * other … invoice says 360, packed 2,000 … remove prefix first and make
 * desktop version same pattern … allow packing but once increase so ask
 * invoice to update with list accurate qty and show red highlight … our
 * invoice will be made later and sometimes we make the invoice before."
 *
 * Runs the REAL includes/packing.php, m_pack.php and shipment_save.php on a
 * real SQL database (SQLite):
 *
 *   1  ONE PACKED COUNT: the phone's ranges (finished or not) plus the
 *      desktop's own rows — a row the phone's Finish wrote is not counted
 *      twice; an old desktop row with no line id is matched by its names.
 *   2  THE INVOICE IS CHECKED, NOT ENFORCED: packing past it saves, from the
 *      phone, and is listed with the figure the invoice should say; a line
 *      with no invoice qty yet is listed too.
 *   3  NO PREFIX: a number is a plain number for the phone and the desktop;
 *      an old prefixed range clashes like any other, and numbers already
 *      used twice are listed.
 *   4  SAVING THE INVOICE KEEPS ITS LINES: the same ids after a save, so the
 *      packing stays on its line; a packed line taken off the form is kept;
 *      a rate is not lost by someone who cannot see rates.
 *   5  PACKING CUT OFF BY AN OLD SAVE goes back on its line — only on an
 *      exact, single match.
 */

$B    = __DIR__ . '/app_src/public_html/';
$work = __DIR__ . '/.zsync';
$P = 0; $F = 0;
function ok(bool $c, string $m, $got = null): void {
    global $P, $F;
    if ($c) { $P++; }
    else { $F++; echo "  FAIL  $m" . ($got !== null ? "\n        got: " . var_export($got, true) : '') . "\n"; }
}
function head(string $n): void { echo "\n$n\n"; }

@mkdir($work . '/includes', 0777, true);
@unlink($work . '/db.sqlite');
foreach (['m_pack.php', 'shipment_save.php', 'includes/packing.php', 'includes/mobile.php', 'includes/weight.php'] as $f) copy($B . $f, $work . '/' . $f);

$schema = <<<'SQL'
CREATE TABLE exp_meta (k TEXT PRIMARY KEY, v TEXT, updated_at TEXT);
INSERT INTO exp_meta VALUES ('pack_schema_version', '7', NULL), ('wt_schema_version', '1', NULL);
CREATE TABLE shipments (id INTEGER PRIMARY KEY, invoice_no TEXT, invoice_date TEXT, buyer_name TEXT, buyer_address TEXT, buyer_country TEXT,
  destination_port TEXT, po_no TEXT, bl_container_no TEXT, currency TEXT, payment_terms TEXT, incoterm TEXT,
  optional_column_enabled INT DEFAULT 0, optional_column_title TEXT, status TEXT DEFAULT 'draft', reopen_status TEXT, reopen_reason TEXT,
  revision_no INT DEFAULT 0, packing_status TEXT NOT NULL DEFAULT 'open', packing_completed_by INT, packing_completed_at TEXT,
  total_qty REAL DEFAULT 0, total_amount REAL DEFAULT 0, total_packages REAL DEFAULT 0, total_net_weight REAL DEFAULT 0,
  total_gross_weight REAL DEFAULT 0, updated_by INT, updated_at TEXT);
CREATE TABLE shipment_items (id INTEGER PRIMARY KEY AUTOINCREMENT, shipment_id INT, line_no INT, product_name TEXT, des_col TEXT,
  optional_value TEXT, department TEXT, qty REAL DEFAULT 0, unit TEXT DEFAULT 'Pcs', rate REAL DEFAULT 0,
  amount REAL DEFAULT 0, product_id INT);
CREATE TABLE shipment_charges (id INTEGER PRIMARY KEY AUTOINCREMENT, shipment_id INT, charge_name TEXT, amount REAL);
CREATE TABLE final_costings (id INTEGER PRIMARY KEY AUTOINCREMENT, shipment_item_id INT NOT NULL UNIQUE, shipment_id INT NOT NULL, total_cost REAL DEFAULT 0);
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
CREATE TABLE weight_line_links (id INTEGER PRIMARY KEY AUTOINCREMENT, shipment_id INT NOT NULL, invoice_item_id INT NOT NULL,
  profile_id INT NOT NULL, product_key TEXT NOT NULL DEFAULT '', linked_by INT, linked_at TEXT, UNIQUE (shipment_id, invoice_item_id));
CREATE TABLE weight_applied (id INTEGER PRIMARY KEY AUTOINCREMENT, shipment_id INT NOT NULL, invoice_item_id INT NOT NULL, profile_id INT NOT NULL,
  profile_version INT NOT NULL DEFAULT 1, unit_grams INT NOT NULL DEFAULT 0, qty REAL NOT NULL DEFAULT 0, packages INT NOT NULL DEFAULT 0,
  pkg_key TEXT NOT NULL DEFAULT '', net_kg REAL NOT NULL DEFAULT 0, gross_kg REAL NOT NULL DEFAULT 0, UNIQUE (shipment_id, invoice_item_id));
CREATE TABLE weight_profiles (id INTEGER PRIMARY KEY AUTOINCREMENT, reference TEXT NOT NULL UNIQUE, name TEXT NOT NULL,
  unit TEXT NOT NULL DEFAULT 'pcs', notes TEXT NOT NULL DEFAULT '', version INT NOT NULL DEFAULT 1, unit_net_grams INT NOT NULL DEFAULT 0,
  sample_qty INT, sample_gross_kg REAL, sample_net_kg REAL, sample_pkg_type TEXT NOT NULL DEFAULT '', active INT NOT NULL DEFAULT 1,
  created_by INT, created_at TEXT, updated_by INT, updated_at TEXT);
CREATE TABLE weight_profile_materials (id INTEGER PRIMARY KEY AUTOINCREMENT, profile_id INT NOT NULL, line_no INT NOT NULL DEFAULT 0,
  category TEXT NOT NULL, description TEXT NOT NULL DEFAULT '', grams INT NOT NULL DEFAULT 0);
CREATE TABLE weight_tare (id INTEGER PRIMARY KEY AUTOINCREMENT, shipment_id INT NOT NULL, pkg_type TEXT NOT NULL, kg REAL NOT NULL DEFAULT 0,
  updated_by INT, updated_at TEXT, UNIQUE (shipment_id, pkg_type));
CREATE TABLE weight_shipment (shipment_id INTEGER PRIMARY KEY, calc_net REAL NOT NULL DEFAULT 0, calc_gross REAL NOT NULL DEFAULT 0,
  final_net REAL NOT NULL DEFAULT 0, final_gross REAL NOT NULL DEFAULT 0, tare_key TEXT NOT NULL DEFAULT '', applied_by INT, applied_at TEXT);

INSERT INTO shipments (id, invoice_no, buyer_name, currency) VALUES (7, 'ZAS/5400', 'Gulf Textiles LLC', 'USD');
INSERT INTO shipment_items (id, shipment_id, line_no, product_name, des_col, optional_value, qty, unit, rate, amount) VALUES
  (71, 7, 1, 'Bath Towel', 'Royal blue', '', 360, 'Pcs', 2.5, 900),
  (72, 7, 2, 'Hand Towel', 'White', '', 0, 'Pcs', 1.2, 0),
  (73, 7, 3, 'Bath Sheet', 'Grey', '', 100, 'Pcs', 4, 400);
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
function can_view_shipment($id){ return true; }
function can_edit_invoice($s){ return true; }
function can_see_rates(){ return getenv('NORATES') ? false : true; }
function assigned_shipment_ids(){ return ['ALL']; }
function post_array(string $key): array { return isset($_POST[$key]) && is_array($_POST[$key]) ? $_POST[$key] : []; }
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
file_put_contents($work . '/includes/textindex.php', "<?php\nfunction txt_index_shipment(\$id){}\n");
$pdo = new PDO('sqlite:' . $work . '/db.sqlite'); $pdo->exec($schema); $pdo = null;

require $work . '/includes/bootstrap.php';
require $work . '/includes/packing.php';
require $work . '/includes/weight.php';
$q = function (string $sql, array $a = []) { $s = db()->prepare($sql); $s->execute($a); return $s->fetchAll(); };
$items = fn() => $q("SELECT * FROM shipment_items WHERE shipment_id=7 ORDER BY line_no, id");
$one = fn($qty) => [['size' => '', 'colour' => '', 'style' => '', 'qty' => $qty]];

/* run a page as a separate PHP process, as the server would */
function page(string $work, string $file, array $get, string $env = '', ?string $json = null, ?array $post = null): string {
    $cmd = 'cd ' . escapeshellarg($work) . ' && ' . $env . ' QS=' . escapeshellarg(http_build_query($get))
         . ' BODY=' . escapeshellarg((string)$json) . ' FORM=' . escapeshellarg($post === null ? '' : http_build_query($post))
         . ' ' . escapeshellarg(PHP_BINARY) . ' -d display_errors=1 -d error_reporting=E_ALL -r ' . escapeshellarg(
             '$_GET=[];parse_str((string)getenv("QS"),$_GET);$_POST=[];'
           . 'if(getenv("FORM")!==""){parse_str((string)getenv("FORM"),$_POST);}'
           . '$_SERVER["REQUEST_METHOD"]=(getenv("BODY")!==""||getenv("FORM")!=="")?"POST":"GET";'
           . 'if(getenv("BODY")!==""){stream_wrapper_unregister("php");stream_wrapper_register("php",FakeIn::class);}'
           . 'class FakeIn{public $context;private $d;private $p=0;function stream_open(){$this->d=(string)getenv("BODY");return true;}'
           . 'function stream_read($n){$r=substr($this->d,$this->p,$n);$this->p+=strlen($r);return $r;}'
           . 'function stream_eof(){return $this->p>=strlen($this->d);}function stream_stat(){return [];}}'
           . 'require ' . var_export($file, true) . ';') . ' 2>&1';
    return (string)shell_exec($cmd);
}
$api = fn(array $b) => json_decode(page($work, 'm_pack.php', ['id' => 7, 'api' => 1], '', json_encode(['_csrf' => 'testtoken'] + $b)), true);

head('1. One packed count for the phone and the desktop');
/* the phone: Carton 1–10 of 20 towels, still open */
[$ok, $m] = pack_package_save(7, $items(), ['item' => 71, 'type' => 'Carton', 'from' => 1, 'to' => 10, 'target' => 20, 'combos' => $one(20)]);
ok($ok, 'the phone saves Carton 1–10 of 20 towels', $m);
/* the desktop: a row of its own, and an old row with no line id */
db()->exec("INSERT INTO packing_items (shipment_id, invoice_item_id, line_no, product_name, des_col, optional_value, pack_unit_title, carton_from, carton_to, packages, total_qty)
            VALUES (7, 71, 1, 'Bath Towel', 'Royal blue', '', 'Carton', 101, 105, 5, 100),
                   (7, NULL, 2, 'Bath Towel', 'Royal blue', '', 'Bale', 1, 1, 1, 60)");
$pk = pack_packed_by_item(7, $items());
ok($pk[71]['phone'] == 200 && $pk[71]['desktop'] == 160 && $pk[71]['total'] == 360,
   'Bath Towel: 200 on the phone + 100 + 60 on the desktop = 360, the invoice exactly', $pk[71]);
ok(pack_invoice_mismatch(7, $items()) === [], 'at exactly the invoice nothing is red');
/* Finish writes the phone's range as a desktop row: still 360, not 560 */
[$okF, $mF] = pack_finish(7);
$pk = pack_packed_by_item(7, $items());
ok($okF && $pk[71]['total'] == 360 && (int)$q("SELECT COUNT(*) c FROM packing_items WHERE packing_group_id IS NOT NULL")[0]['c'] === 1,
   'after Finish the phone\'s row is on the desktop list and counted once — 360, not 560', [$mF, $pk[71]]);
pack_reopen(7);

head('2. Past the invoice: allowed, and listed in red with the right figure');
$r = $api(['op' => 'save', 'item' => 71, 'type' => 'Carton', 'from' => 11, 'to' => 11, 'target' => 20, 'combos' => $one(20)]);
ok(($r['ok'] ?? false) === true, 'the phone saves a carton past the invoice — packing is not refused', $r['msg'] ?? $r);
$p71 = array_values(array_filter($r['state']['products'] ?? [], fn($p) => $p['id'] === '71'))[0] ?? [];
ok(($p71['invoiceQty'] ?? null) == 360 && ($p71['desktopQty'] ?? null) == 160,
   'the phone is told the invoice qty and what the desktop packed, to show it in red', $p71);
$mm = pack_invoice_mismatch(7, $items());
ok(count($mm) === 1 && $mm[0]['id'] === 71 && $mm[0]['packed'] == 380 && $mm[0]['invoice'] == 360 && $mm[0]['over'] == 20 && $mm[0]['kind'] === 'over',
   'Bath Towel is listed: invoice 360, packed 380 — update the invoice to 380', $mm);
[$ok] = pack_package_save(7, $items(), ['item' => 72, 'type' => 'Bag', 'from' => 1, 'to' => 2, 'target' => 50, 'combos' => $one(50)]);
$mm = pack_invoice_mismatch(7, $items());
$k72 = array_values(array_filter($mm, fn($x) => $x['id'] === 72))[0] ?? null;
ok($ok && $k72 && $k72['kind'] === 'no_invoice_qty' && $k72['packed'] == 100,
   'a line whose invoice qty is not set yet (invoice made later) packs, and is listed with 100 to put on it', $k72);
ok(!array_filter($mm, fn($x) => $x['id'] === 73), 'a line not packed yet is not listed');
$html = page($work, 'm_pack.php', ['id' => 7]);
ok(!preg_match('~(Warning|Notice|Deprecated|Fatal error)~', $html), 'the phone page renders with no PHP warning',
   preg_match('~.{0,160}(Warning|Notice|Fatal error).{0,160}~s', $html, $w) ? $w[0] : null);
ok(str_contains($html, 'more than the invoice') && str_contains($html, 'Invoice qty not set yet') && str_contains($html, 'sp-bad'),
   'and carries the red notes for both');

head('3. No prefix: plain numbers, one rule for phone and desktop');
[$ok, $m] = pack_package_save(7, $items(), ['item' => 73, 'type' => 'Carton', 'from' => 103, 'to' => 103, 'target' => 10, 'combos' => $one(10), 'prefix' => 'X']);
ok(!$ok && str_contains($m, 'Carton (desktop) 101–105'), 'Carton 103 is refused because the desktop has Carton 101–105 — a prefix sent changes nothing', $m);
[$ok] = pack_package_save(7, $items(), ['item' => 73, 'type' => 'Roll', 'from' => 103, 'to' => 103, 'target' => 10, 'combos' => $one(10)]);
ok($ok, 'Roll 103 is a different kind and saves');
$g = $q("SELECT serial_prefix, serial_pad FROM packing_groups ORDER BY id DESC LIMIT 1")[0];
ok($g['serial_prefix'] === '' && (int)$g['serial_pad'] === 0, 'nothing is stored with a prefix or padding', $g);
[$okL, $mL, $gidL] = pack_group_save(7, ['invoice_item_id' => 73, 'product_name' => 'Bath Sheet', 'unit_title' => 'Bag', 'serial_from' => 70,
    'serial_to' => 70, 'qty_mode' => 'per', 'auto_assorted' => true, 'serial_prefix' => 'Z', 'serial_pad' => 4], [['size_label' => '', 'qty' => 5]]);
$gL = $q("SELECT serial_prefix, serial_pad FROM packing_groups WHERE id=?", [(int)$gidL])[0] ?? [];
ok($okL && ($gL['serial_prefix'] ?? 'x') === '' && (int)($gL['serial_pad'] ?? 1) === 0, 'not even when a prefix is handed straight to the range save', [$mL, $gL]);
db()->exec("DELETE FROM packing_groups WHERE id=" . (int)$gidL);
/* an old range stored with a letter, from before */
db()->exec("INSERT INTO packing_groups (shipment_id, invoice_item_id, product_name, des_col, unit_title, serial_from, serial_to, packages, serial_prefix, serial_pad)
            VALUES (7, 73, 'Bath Sheet', 'Grey', 'Carton', 5, 6, 2, 'C', 3)");
$st = pack_state(7, $items(), ['id' => 7, 'invoice_no' => 'X', 'buyer_name' => '', 'packing_status' => 'open']);
ok(!array_filter($st['records'], fn($r) => $r['prefix'] !== '' || $r['pad'] !== 0), 'the phone is sent every range as plain numbers', array_column($st['records'], 'prefix'));
ok(in_array('Carton 5–6', $st['duplicates'], true), 'old C005–C006 is plain Carton 5–6, the same as phone Carton 1–10: listed as used twice', $st['duplicates']);
[$ok, $m] = pack_package_save(7, $items(), ['item' => 73, 'type' => 'Carton', 'from' => 6, 'to' => 6, 'target' => 10, 'combos' => $one(10)]);
ok(!$ok && str_contains($m, 'already used'), 'and a new Carton 6 is refused', $m);
$dbl = pack_duplicate_serials(7);
db()->exec("DELETE FROM packing_groups WHERE serial_prefix='C'");
ok(!in_array('Carton 5–6', pack_duplicate_serials(7), true) && $dbl !== pack_duplicate_serials(7), 'deleting one of them clears the red note');

head('4. Saving the invoice keeps its lines');
/* a weight link and a final costing on the towel line, as in real use */
db()->exec("INSERT INTO weight_line_links (shipment_id, invoice_item_id, profile_id) VALUES (7, 71, 1)");
db()->exec("INSERT INTO final_costings (shipment_item_id, shipment_id, total_cost) VALUES (71, 7, 1.75)");
$form = function (array $rows, array $extra = []) {
    $f = ['_csrf' => 'testtoken', 'shipment_id' => 7, 'invoice_no' => 'ZAS/5400', 'buyer_name' => 'Gulf Textiles LLC', 'currency' => 'USD', 'save_only' => 1];
    foreach ($rows as $r) {
        foreach (['item_id', 'product_name', 'product_id', 'des_col', 'qty', 'unit', 'rate'] as $k) $f[$k][] = $r[$k] ?? '';
    }
    return $f + $extra;
};
$rows = [
    ['item_id' => 71, 'product_name' => 'Bath Towel', 'des_col' => 'Royal blue', 'qty' => 380, 'unit' => 'Pcs', 'rate' => 2.5],
    ['item_id' => 72, 'product_name' => 'Hand Towel', 'des_col' => 'White', 'qty' => 100, 'unit' => 'Pcs', 'rate' => 1.2],
    ['item_id' => 73, 'product_name' => 'Bath Sheet', 'des_col' => 'Grey', 'qty' => 100, 'unit' => 'Pcs', 'rate' => 4],
    ['item_id' => '', 'product_name' => 'Face Towel', 'des_col' => 'Navy', 'qty' => 50, 'unit' => 'Pcs', 'rate' => 0.5],
];
$out = page($work, 'shipment_save.php', [], '', null, $form($rows));
$now = $items();
ok(str_contains($out, '__REDIRECT__') && array_column($now, 'id') === [71, 72, 73, 74], 'after a save the lines keep their ids 71, 72, 73, and the new line is added',
   [substr($out, 0, 300), array_column($now, 'id')]);
ok($now[0]['qty'] == 380 && $now[1]['qty'] == 100 && $now[3]['product_name'] === 'Face Towel', 'with the new quantities — the invoice updated to what was packed');
ok(pack_invoice_mismatch(7, $now) === [], 'and nothing is red any more');
$pk = pack_packed_by_item(7, $now);
ok($pk[71]['total'] == 380 && $pk[72]['total'] == 100, 'the packing is still on its lines', $pk);
ok((int)$q("SELECT COUNT(*) c FROM weight_line_links WHERE invoice_item_id=71")[0]['c'] === 1
   && (int)$q("SELECT COUNT(*) c FROM final_costings WHERE shipment_item_id=71")[0]['c'] === 1,
   'and so are the weight link and the final costing');
$sh = $q("SELECT total_qty, total_amount FROM shipments WHERE id=7")[0];
ok($sh['total_qty'] == 630 && abs($sh['total_amount'] - (950 + 120 + 400 + 25)) < 0.001, 'the totals follow: 630 pieces, 1,495.00', $sh);
/* a packed line taken off the form is kept, and said */
$rows[3]['item_id'] = 74; /* the form now carries the new line's id too */
$out = page($work, 'shipment_save.php', [], '', null, $form([$rows[1], $rows[2], $rows[3]]));
$now = $items();
ok(in_array(71, array_column($now, 'id'), true) && (int)$q("SELECT COUNT(*) c FROM shipment_items WHERE id=74")[0]['c'] === 1,
   'Bath Towel has packing, so taking it off the form keeps it', array_column($now, 'id'));
$out = page($work, 'shipment_save.php', [], '', null, $form([$rows[0], $rows[1], $rows[2]]));
ok(!in_array(74, array_column($items(), 'id'), true), 'a line with no packing is deleted as before');
/* someone who cannot see rates: no rate box, rates kept */
$noRate = array_map(fn($r) => array_diff_key($r, ['rate' => 1]), [$rows[0], $rows[1], $rows[2]]);
db()->exec("INSERT INTO shipment_charges (shipment_id, charge_name, amount) VALUES (7, 'Freight', 80)");
$f = $form($noRate); unset($f['rate']);
page($work, 'shipment_save.php', [], 'NORATES=1', null, $f);
$r71 = $q("SELECT rate, amount FROM shipment_items WHERE id=71")[0];
ok($r71['rate'] == 2.5 && $r71['amount'] == 950, 'a save by someone who cannot see rates keeps the rate (it used to be saved as 0)', $r71);
ok((int)$q("SELECT COUNT(*) c FROM shipment_charges WHERE shipment_id=7")[0]['c'] === 1, 'and keeps the charges (they used to be deleted)');

head('5. Packing cut off by an old invoice save goes back on its line');
/* what the old save did: every line deleted and inserted again, new ids */
$old = $items();
db()->exec("DELETE FROM shipment_items WHERE shipment_id=7");
$ins = db()->prepare("INSERT INTO shipment_items (shipment_id, line_no, product_name, des_col, optional_value, qty, unit) VALUES (?,?,?,?,?,?,?)");
foreach ($old as $o) $ins->execute([7, $o['line_no'], $o['product_name'], $o['des_col'], $o['optional_value'], $o['qty'], $o['unit']]);
$new = $items();
$pkLost = pack_packed_by_item(7, $new);
ok($pkLost[$new[0]['id']]['phone'] == 0, 'cut off, the towel\'s phone packing shows 0 — this is how it looked packed twice', $pkLost[$new[0]['id']]);
$n = pack_relink_orphans(7, $new);
$pk = pack_packed_by_item(7, $new);
ok($n > 0 && $pk[$new[0]['id']]['total'] == 380 && $pk[$new[1]['id']]['total'] == 100, 'mended: Bath Towel 380 and Hand Towel 100 are back on their lines', [$n, $pk]);
ok((int)$q("SELECT COUNT(*) c FROM weight_line_links WHERE invoice_item_id=?", [$new[0]['id']])[0]['c'] === 1
   && (int)$q("SELECT COUNT(*) c FROM final_costings WHERE shipment_item_id=?", [$new[0]['id']])[0]['c'] === 1,
   'with its weight link and its final costing');
ok(pack_relink_orphans(7, $new) === 0, 'a second run finds nothing to do');
/* two lines with the same names: nothing is guessed */
db()->exec("UPDATE packing_groups SET invoice_item_id=999 WHERE invoice_item_id=" . (int)$new[2]['id']);
db()->exec("INSERT INTO shipment_items (shipment_id, line_no, product_name, des_col, optional_value, qty, unit) VALUES (7, 9, 'Bath Sheet', 'Grey', '', 5, 'Pcs')");
pack_relink_orphans(7, $items());
ok((int)$q("SELECT COUNT(*) c FROM packing_groups WHERE invoice_item_id=999")[0]['c'] > 0, 'two lines that both match: left alone, never guessed');

head('6. afnan\'s case: 600 g a piece, a second range, then all deleted on the phone');
/* "I given 600 gram per pc so if 1000 pcs then its 600 kg and if two lines
    packed so why it shows 300 kg — but also if removed from mobile so why
    show here" */
db()->exec("INSERT INTO shipments (id, invoice_no, buyer_name, currency) VALUES (8, 'ZAS/5223', 'Al-Berjas Trading LLC', 'USD')");
db()->exec("INSERT INTO shipment_items (id, shipment_id, line_no, product_name, des_col, optional_value, qty, unit) VALUES (81, 8, 1, 'Bath Towel', 'Royal blue', '', 2000, 'Pcs')");
$items8 = $q("SELECT * FROM shipment_items WHERE shipment_id=8");
$api8 = fn(array $b) => json_decode(page($work, 'm_pack.php', ['id' => 8, 'api' => 1], '', json_encode(['_csrf' => 'testtoken'] + $b)), true);
[$okP, $mP, $prof] = wt_profile_save(['name' => 'Bath towel royal', 'unit' => 'pcs', 'materials' => [['category' => 'Fabric', 'grams' => '600']]]);
wt_link(8, $items8, 81, $prof['id']);
$r = $api8(['op' => 'save', 'item' => 81, 'type' => 'Carton', 'from' => 1, 'to' => 100, 'target' => 1000, 'mode' => 'direct', 'combos' => $one(1000)]);
[$okA, $mA] = wt_apply(8, $items8);
ok(($r['ok'] ?? false) && $okA && abs($q("SELECT total_net_weight n FROM shipments WHERE id=8")[0]['n'] - 600) < 0.0005,
   'Carton 1–100, 1,000 pcs direct, applied at 600 g: 600 kg', [$r['msg'] ?? $r, $mA]);
[$okF] = pack_finish(8); pack_reopen(8);
$r = $api8(['op' => 'save', 'item' => 81, 'type' => 'Carton', 'from' => 101, 'to' => 101, 'target' => 1000, 'combos' => $one(1000)]);
$rows8 = $q("SELECT carton_from f, total_qty q, net_weight n FROM packing_items WHERE shipment_id=8 ORDER BY carton_from");
$sh8 = $q("SELECT total_net_weight n FROM shipments WHERE id=8")[0];
ok(($r['ok'] ?? false) && count($rows8) === 2 && abs($rows8[0]['n'] - 600) < 0.0005 && abs($rows8[1]['n'] - 600) < 0.0005 && abs($sh8['n'] - 1200) < 0.0005,
   'Carton 101 with 1,000 more, packed after Finish: the desktop rows show it at once, 600 kg each, 1,200 kg in all — not 300 + 300', [$rows8, $sh8]);
$g8 = $q("SELECT id FROM packing_groups WHERE shipment_id=8 ORDER BY serial_from");
foreach ($g8 as $g) $api8(['op' => 'delete_range', 'group' => (int)$g['id']]);
ok((int)$q("SELECT COUNT(*) c FROM packing_items WHERE shipment_id=8")[0]['c'] === 0,
   'both ranges deleted on the phone: no row is left on the desktop (or the print)');
ok(abs($q("SELECT total_net_weight n FROM shipments WHERE id=8")[0]['n']) < 0.0005 && (int)$q("SELECT COUNT(*) c FROM weight_applied WHERE shipment_id=8")[0]['c'] === 0,
   'and the weight goes with them: 0 kg, nothing applied');
/* the state afnan's shipment was left in by the old files: copies whose ranges are gone */
$api8(['op' => 'save', 'item' => 81, 'type' => 'Carton', 'from' => 1, 'to' => 1, 'target' => 10, 'combos' => $one(10)]);
pack_finish(8); pack_reopen(8);
db()->exec("DELETE FROM packing_group_sizes WHERE group_id IN (SELECT id FROM packing_groups WHERE shipment_id=8)");
db()->exec("DELETE FROM packing_groups WHERE shipment_id=8");
ok(pack_sync_if_stale(8) === true && (int)$q("SELECT COUNT(*) c FROM packing_items WHERE shipment_id=8")[0]['c'] === 0,
   'rows left behind by the old files are cleared the next time a page opens');
ok(pack_sync_if_stale(8) === false, 'and nothing is rewritten when it already matches');
/* a changed profile still waits for Apply */
$api8(['op' => 'save', 'item' => 81, 'type' => 'Carton', 'from' => 1, 'to' => 10, 'target' => 100, 'combos' => $one(100)]);
wt_apply(8, $items8);
wt_profile_save(['id' => $prof['id'], 'name' => 'Bath towel royal', 'unit' => 'pcs', 'materials' => [['category' => 'Fabric', 'grams' => '650']]]);
$api8(['op' => 'save', 'item' => 81, 'type' => 'Carton', 'from' => 11, 'to' => 11, 'target' => 100, 'combos' => $one(100)]);
$c8 = wt_calc(8, $items8);
ok(abs($q("SELECT total_net_weight n FROM shipments WHERE id=8")[0]['n'] - 660) < 0.0005 && ($c8['lines'][0]['applied']['state'] ?? '') === 'profile_updated',
   'the profile changed to 650 g: more packing still counts at the applied 600 g (1,100 pcs = 660 kg) and the page says "apply again"',
   [$q("SELECT total_net_weight n FROM shipments WHERE id=8")[0]['n'], $c8['lines'][0]['applied'] ?? null]);
/* applied before this fix: the page catches it up */
db()->exec("UPDATE weight_applied SET qty=100, net_kg=60 WHERE shipment_id=8");
ok(wt_refresh_if_stale(8) === true && abs((float)$q("SELECT net_kg n FROM weight_applied WHERE shipment_id=8")[0]['n'] - 660) < 0.0005,
   'weight applied on an older quantity is worked out again when the page opens');

echo "\n" . ($F === 0 ? "ALL PASS   ($P checks)\n" : "FAILED  $F   (" . ($P + $F) . " checks)\n");
exit($F === 0 ? 0 : 1);
