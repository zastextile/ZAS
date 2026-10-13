<?php
/* SMART PACKING — afnan's demo, on the phone, on real tables.
 *
 * "That is not as per my instructions … have a look at the demo, see the
 *  feature and function, and copy and apply it on your existing mobile
 *  packing file."
 *
 * The demo kept everything in the browser. Here every save goes to the
 * server, so the rules the demo kept in javascript have to hold on the
 * server too, where a second phone or a lost signal cannot get round them.
 *
 * This runs the REAL includes/packing.php and the REAL m_pack.php against
 * a real SQL database — SQLite, built here with the live tables' columns —
 * so every query actually runs. A fake that answers by matching words in
 * the SQL has passed a broken query before.
 *
 * What it holds shut:
 *   1  WHAT IS IN A PACKAGE IS CHECKED ON THE SERVER — every combination is
 *      one the line is packed in, a positive whole number, no duplicates,
 *      adding up to the pieces per package.
 *   2  A NUMBER ONCE PER KIND, AND NO PREFIX. Carton 1 twice is refused;
 *      Roll 1 is another package; a prefix sent is ignored ("remove
 *      prefix"), so it cannot make a used number look new.
 *   3  EVERY PACKAGE IS ITS OWN RECORD. Editing package 5 of 1–10 changes
 *      5 alone; deleting it leaves the other nine; a refused edit changes
 *      nothing at all.
 *   4  FINISH writes the packing list rows and marks the shipment, REOPEN
 *      opens it again.
 *   5  THE PAGE: no PHP warning, the state is embedded safely, the API
 *      wants the CSRF token and the permission, and a finished list
 *      refuses changes until reopened.
 *   6  IN CHROMIUM, through a real server: tap to count, Save & Next to
 *      the next free number, a duplicate refused with the reason, edit,
 *      copy and delete one package, summary, finish and reopen, typing
 *      kept key by key, no sideways scroll, no javascript error, and no
 *      page load at any point.
 */

$B    = __DIR__ . '/app_src/public_html/';
$work = __DIR__ . '/.zsmartpack';
$P = 0; $F = 0;
function ok(bool $c, string $m, $got = null): void {
    global $P, $F;
    if ($c) { $P++; }
    else { $F++; echo "  FAIL  $m" . ($got !== null ? "\n        got: " . var_export($got, true) : '') . "\n"; }
}
function head(string $n): void { echo "\n$n\n"; }

@mkdir($work . '/includes', 0777, true);
@unlink($work . '/db.sqlite');
copy($B . 'm_pack.php',           $work . '/m_pack.php');
copy($B . 'includes/packing.php', $work . '/includes/packing.php');
copy($B . 'includes/mobile.php',  $work . '/includes/mobile.php');

/* ------------------------------------------------- the database, for real
   The live tables' columns (from the running MariaDB), in SQLite. */
$schema = <<<'SQL'
CREATE TABLE exp_meta (k TEXT PRIMARY KEY, v TEXT, updated_at TEXT);
INSERT INTO exp_meta VALUES ('pack_schema_version', '7', NULL);
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
  label TEXT, sort_no INT DEFAULT 0, created_by INT, created_at TEXT,
  UNIQUE (shipment_id, invoice_item_id, kind, label));
CREATE TABLE packing_items (id INTEGER PRIMARY KEY AUTOINCREMENT, shipment_id INT, invoice_item_id INT, line_no INT,
  product_name TEXT, des_col TEXT, optional_value TEXT, pack_unit_title TEXT DEFAULT 'Carton', carton_from INT DEFAULT 0,
  carton_to INT DEFAULT 0, packages REAL DEFAULT 0, qty_per_carton REAL DEFAULT 0, qty_mode TEXT DEFAULT 'auto',
  total_qty REAL DEFAULT 0, net_weight REAL DEFAULT 0, gross_weight REAL DEFAULT 0, size_text TEXT, packing_group_id INT);
CREATE TABLE product_sizes (id INTEGER PRIMARY KEY, product_id INT, size_label TEXT);
CREATE TABLE products (id INTEGER PRIMARY KEY, name TEXT);

INSERT INTO shipments (id, invoice_no, buyer_name) VALUES (7, 'ZAS/5300', 'Gulf Textiles LLC');
INSERT INTO shipment_items (id, shipment_id, line_no, product_name, des_col, optional_value, qty, unit) VALUES
  (71, 7, 1, 'T-Shirt', 'Cotton jersey', '', 600, 'Pcs'),
  (72, 7, 2, 'Bath Towel 500GSM', 'Combed', '', 400, 'Pcs'),
  (73, 7, 3, 'Pillow </script><b>case', 'Name that tries to close the script', '', 50, 'Pcs'),
  (74, 7, 4, 'Bed Sheet', 'Percale', '', 200, 'Pcs');
INSERT INTO packing_palette (shipment_id, invoice_item_id, kind, label, sort_no) VALUES
  (7, 71, 'size', 'S', 1), (7, 71, 'size', 'M', 2), (7, 71, 'size', 'L', 3),
  (7, 71, 'colour', 'Black', 1), (7, 71, 'colour', 'Green', 2), (7, 71, 'colour', 'White', 3),
  (7, 71, 'style', 'Crew', 1), (7, 71, 'style', 'V-neck', 2),
  (7, 74, 'size', 'Single', 1), (7, 74, 'size', 'Double', 2);
SQL;

/* The app's chrome, as on the live server: the same login, role, CSRF. */
$bootstrap = <<<'PHP'
<?php
if (session_status() === PHP_SESSION_NONE) @session_start();
$_SESSION['user_id'] = 1;
function e($s){ return htmlspecialchars((string)$s, ENT_QUOTES); }
function require_login(){}
function csrf_token(){ return 'testtoken'; }
function csrf_field(){ return '<input type="hidden" name="_csrf" value="testtoken">'; }
/* THE REAL CHECK, not a stub — the API is tested for refusing without it */
function verify_csrf(){ if (($_POST['_csrf'] ?? '') !== 'testtoken') { http_response_code(419); exit('CSRF token mismatch.'); } }
function current_user(): ?array { return ['id'=>1,'name'=>'Afnan','email'=>'a@z','role'=>$GLOBALS['ROLE'],'can_see_rates'=>1]; }
$GLOBALS['ROLE'] = getenv('ROLE') ?: ($_GET['as'] ?? 'admin');
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
$pdo = new PDO('sqlite:' . $work . '/db.sqlite');
$pdo->exec($schema);
$pdo = null;

/* ================================================================ backend */
$GLOBALS['ROLE'] = 'admin';
require $work . '/includes/bootstrap.php';
require $work . '/includes/packing.php';

$items = db()->query("SELECT * FROM shipment_items WHERE shipment_id=7 ORDER BY line_no")->fetchAll();
$tee = $items[0];
$cnt = fn(string $sql, array $a = []) => (function () use ($sql, $a) { $s = db()->prepare($sql); $s->execute($a); return $s->fetchAll(); })();
$ranges = fn() => array_map(fn($g) => $g['serial_prefix'] . $g['serial_from'] . '-' . $g['serial_to'] . ($g['edited'] ? '*' : ''),
                            $cnt("SELECT * FROM packing_groups WHERE shipment_id=7 AND unit_title='Carton' AND serial_prefix='' ORDER BY serial_from"));
$contents = function (int $gid): array {
    $s = db()->prepare("SELECT size_label, colour_label, style_label, qty_per_pkg FROM packing_group_sizes WHERE group_id=? ORDER BY id");
    $s->execute([$gid]);
    return array_map(fn($r) => $r['size_label'] . '/' . $r['colour_label'] . '/' . $r['style_label'] . ' ' . (0 + $r['qty_per_pkg']), $s->fetchAll());
};
$gidAt = function (int $num, string $prefix = '', string $kind = 'Carton'): int {
    $s = db()->prepare("SELECT id FROM packing_groups WHERE shipment_id=7 AND serial_prefix=? AND unit_title=? AND serial_from<=? AND serial_to>=?");
    $s->execute([$prefix, $kind, $num, $num]);
    return (int)$s->fetchColumn();
};
$mix = [['size' => 'S', 'colour' => 'Black', 'style' => 'Crew', 'qty' => 4],
        ['size' => 'S', 'colour' => 'Green', 'style' => 'Crew', 'qty' => 3],
        ['size' => 'M', 'colour' => 'White', 'style' => 'V-neck', 'qty' => 3]];
$save = fn(array $over) => pack_package_save(7, $items, array_merge(
    ['item' => 71, 'type' => 'Carton', 'spec' => 'Standard carton', 'prefix' => 'C', 'pad' => 3,
     'from' => 1, 'to' => 10, 'target' => 10, 'combos' => $mix], $over));

head('1. What is in a package is checked on the server');
$ax = pack_line_axes(7, $tee);
ok($ax['size'] === ['S', 'M', 'L'] && $ax['colour'] === ['Black', 'Green', 'White'] && $ax['style'] === ['Crew', 'V-neck'],
   'the line offers exactly the sizes, colours and styles the office set', $ax);
ok(pack_combos_problem($ax, $mix, 10) === '', 'a full, valid package passes', pack_combos_problem($ax, $mix, 10));
ok(pack_combos_problem($ax, $mix, 12) !== '', 'contents that do not add up to the pieces per package are refused');
ok(pack_combos_problem($ax, [['size' => 'XL', 'colour' => 'Black', 'style' => 'Crew', 'qty' => 10]], 10) !== '',
   'a size the line is not packed in is refused');
ok(pack_combos_problem($ax, [['size' => 'S', 'colour' => 'Pink', 'style' => 'Crew', 'qty' => 10]], 10) !== '',
   'and a colour it is not packed in');
ok(pack_combos_problem($ax, [['size' => 'S', 'colour' => 'Black', 'style' => 'Crew', 'qty' => 5],
                             ['size' => 'S', 'colour' => 'Black', 'style' => 'Crew', 'qty' => 5]], 10) !== '',
   'the same combination twice is refused');
ok(pack_combos_problem($ax, [['size' => 'S', 'colour' => 'Black', 'style' => 'Crew', 'qty' => 2.5],
                             ['size' => 'M', 'colour' => 'Black', 'style' => 'Crew', 'qty' => 7.5]], 10) !== '',
   'half a piece is refused');
ok(pack_combos_problem($ax, [['size' => 'S', 'colour' => 'Black', 'style' => 'Crew', 'qty' => 0]], 0) !== '',
   'and an empty package');
ok(pack_combos_problem($ax, [['size' => 'S', 'colour' => '', 'style' => 'Crew', 'qty' => 10]], 10) !== '',
   'a blank colour is refused on a line that has colours');
$towelAx = pack_line_axes(7, $items[1]);
ok(pack_combos_problem($towelAx, [['size' => '', 'colour' => '', 'style' => '', 'qty' => 10]], 10) === '',
   'but a line with no lists counts finished units', pack_combos_problem($towelAx, [['size' => '', 'colour' => '', 'style' => '', 'qty' => 10]], 10));
[$okBad, $msgBad] = $save(['target' => 11]);
ok(!$okBad && !$cnt("SELECT id FROM packing_groups"), 'and a bad package is not saved at all', $msgBad);

head('2. A number once per kind of package, and no prefix');
/* every save below SENDS prefix C; it must be ignored */
[$ok1, $m1] = $save([]);
ok($ok1, 'Carton 001–010 saves', $m1);
$g1 = $gidAt(1);
$row = $cnt("SELECT * FROM packing_groups WHERE id=?", [$g1])[0];
ok($row['serial_prefix'] === '', 'with no prefix, even though one was sent', $row['serial_prefix']);
ok((int)$row['serial_pad'] === 3 && $row['pkg_spec'] === 'Standard carton'
   && (int)$row['packages'] === 10 && (float)$row['total_qty'] === 100.0,
   'its padding, spec, 10 packages and 100 pieces', $row);
ok($contents($g1) === ['S/Black/Crew 4', 'S/Green/Crew 3', 'M/White/V-neck 3'],
   'and the contents by size, colour AND style', $contents($g1));
[$okD, $mD] = $save(['from' => 10, 'to' => 12]);
ok(!$okD && str_contains($mD, 'already used by Carton 001–010'), '010–012 is refused, naming who has 010', $mD);
ok(str_contains($mD, 'a different kind, like a Roll, may reuse it') && !str_contains($mD, 'prefix'),
   'and the message says a different kind may reuse it, with no word of a prefix', $mD);
[$okR, $mR] = $save(['prefix' => 'R', 'from' => 1, 'to' => 2]);
ok(!$okR, 'a prefix cannot make a used number new — "R1" is still 1', $mR);
[$okK, $mK] = $save(['type' => 'Roll', 'from' => 1, 'to' => 2]);
ok($okK, 'Roll 1 is another package — a different kind may reuse the number', $mK);
[$okT, $mT] = $save(['type' => ' carton ', 'from' => 3, 'to' => 3]);
ok(!$okT, 'and " carton " is still a Carton', $mT);
[$ok5k, $m5k] = $save(['from' => 100, 'to' => 5200]);
ok(!$ok5k && str_contains($m5k, '5,000'), 'more than 5,000 packages in one save is refused', $m5k);

head('3. Every package is its own record');
db()->prepare("INSERT INTO packing_weight_lines (group_id, size_label, colour_label, line_no, w_type, w_name, grams) VALUES (?,?,?,?,?,?,?)")
    ->execute([$g1, 'S', '', 1, 'Fabric', 'Jersey', 180]);
$one = [['size' => 'L', 'colour' => 'White', 'style' => 'Crew', 'qty' => 10]];
[$okE, $mE] = $save(['from' => 5, 'to' => 5, 'combos' => $one, 'edit_group' => $g1, 'edit_num' => 5]);
ok($okE, 'package C005 of C001–C010 is edited', $mE);
ok($ranges() === ['1-4', '5-5*', '6-10'], 'the range is split around it, and only C005 is marked edited', $ranges());
ok($contents($gidAt(5)) === ['L/White/Crew 10'], 'C005 holds what it was changed to', $contents($gidAt(5)));
ok($contents($gidAt(1)) === ['S/Black/Crew 4', 'S/Green/Crew 3', 'M/White/V-neck 3']
   && $contents($gidAt(6)) === $contents($gidAt(1)), 'and both sides keep the original contents',
   [$contents($gidAt(1)), $contents($gidAt(6))]);
$tot = $cnt("SELECT SUM(packages) p, SUM(total_qty) q FROM packing_groups WHERE serial_prefix='' AND unit_title='Carton'")[0];
ok((int)$tot['p'] === 10 && (float)$tot['q'] === 100.0, 'still 10 packages and 100 pieces between them', $tot);
ok((int)$cnt("SELECT COUNT(*) c FROM packing_weight_lines WHERE group_id=?", [$gidAt(6)])[0]['c'] === 1,
   'a weight already entered goes with each piece of the range', $cnt("SELECT * FROM packing_weight_lines"));
$before = $ranges();
[$okEB, $mEB] = $save(['from' => 7, 'to' => 7, 'target' => 10, 'combos' => [['size' => 'L', 'colour' => 'White', 'style' => 'Crew', 'qty' => 9]],
                       'edit_group' => $gidAt(7), 'edit_num' => 7]);
ok(!$okEB && $ranges() === $before, 'an edit that is refused changes nothing — the range is not split', [$mEB, $ranges()]);
[$okE2, $mE2] = $save(['from' => 7, 'to' => 8, 'edit_group' => $gidAt(7), 'edit_num' => 7]);
ok(!$okE2, 'an edit is one package, never a range', $mE2);
[$okDel, $mDel] = pack_package_delete(7, $gidAt(8), 8);
ok($okDel && $ranges() === ['1-4', '5-5*', '6-7', '9-10'], 'deleting C008 leaves the other nine', [$mDel, $ranges()]);
[$okDel2] = pack_package_delete(7, $gidAt(5), 5);
ok($okDel2 && $ranges() === ['1-4', '6-7', '9-10'], 'and a package on its own is simply removed', $ranges());
[$okDel3, $mDel3] = pack_package_delete(7, $gidAt(1), 99);
ok(!$okDel3 && $ranges() === ['1-4', '6-7', '9-10'], 'a number outside the range is refused, not guessed at', $mDel3);
[$okRe] = $save(['from' => 8, 'to' => 8]);
ok($okRe, 'a deleted number is free again');
/* a package saved WITH a prefix before it was removed keeps it while it
   is edited, so the edit cannot land on another package's number */
pack_group_save(7, ['invoice_item_id' => 71, 'product_name' => 'T-Shirt', 'unit_title' => 'Carton', 'serial_from' => 40,
    'serial_to' => 41, 'qty_mode' => 'per', 'auto_assorted' => true, 'serial_prefix' => 'C', 'serial_pad' => 3],
    [['size_label' => 'S', 'colour_label' => 'Black', 'style_label' => 'Crew', 'qty' => 10]]);
[$okOld, $mOld] = $save(['from' => 40, 'to' => 40, 'combos' => $one, 'edit_group' => $gidAt(40, 'C'), 'edit_num' => 40]);
ok($okOld && $gidAt(40, 'C') > 0 && $gidAt(41, 'C') > 0 && $gidAt(40, '') === 0,
   'an old prefixed package keeps its prefix when edited', [$mOld, $gidAt(40, 'C'), $gidAt(40, '')]);

head('3b. Direct quantity — the total for the whole range');
/* "give the qty even by size or colour or style or a mix — but we are not
   skipping anything, even the serial number". Bale 101–110 of T-Shirt:
   a total of 250, not a per-bale figure. */
$dmix = [['size' => 'S', 'colour' => 'Black', 'style' => 'Crew', 'qty' => 100],
         ['size' => 'M', 'colour' => 'White', 'style' => 'V-neck', 'qty' => 150]];
$dsave = fn(array $over) => pack_package_save(7, $items, array_merge(
    ['item' => 71, 'type' => 'Bale', 'spec' => 'Standard bale', 'from' => 101, 'to' => 110, 'target' => 250,
     'combos' => $dmix, 'mode' => 'direct'], $over));
[$okDd, $mDd] = $dsave([]);
$dg = $gidAt(101, '', 'Bale');
$dgr = $dg ? $cnt("SELECT * FROM packing_groups WHERE id=?", [$dg])[0] : [];
ok($okDd && ($dgr['qty_mode'] ?? '') === 'direct' && (int)$dgr['serial_from'] === 101 && (int)$dgr['serial_to'] === 110
   && (int)$dgr['packages'] === 10 && (float)$dgr['total_qty'] === 250.0,
   'a direct range saves with its serials, 10 packages and the 250 total', [$mDd, $dgr]);
$dtot = array_map(fn($r) => $r['size_label'] . '/' . $r['colour_label'] . '/' . $r['style_label'] . ' ' . (0 + $r['total_qty']),
    $cnt("SELECT * FROM packing_group_sizes WHERE group_id=? ORDER BY id", [$dg]));
ok($dtot === ['S/Black/Crew 100', 'M/White/V-neck 150'], 'with the totals by size, colour and style as given', $dtot);
[$okDs] = $dsave(['target' => 260]);
ok(!$okDs, 'a direct total that the figures do not add up to is refused');
[$okDn, $mDn] = $dsave(['from' => 201, 'to' => 210, 'target' => 5, 'combos' => [['size' => 'S', 'colour' => 'Black', 'style' => 'Crew', 'qty' => 5]]]);
ok(!$okDn && str_contains($mDn, 'at least one piece in each package'), '5 pieces cannot fill 10 packages', $mDn);
[$okDdup, $mDdup] = $dsave(['from' => 105, 'to' => 120]);
ok(!$okDdup && str_contains($mDdup, 'already used by Bale 101–110'), 'and its serials are guarded like any other', $mDdup);
[$okDe, $mDe] = pack_package_save(7, $items, ['item' => 71, 'type' => 'Bale', 'from' => 103, 'to' => 103, 'target' => 10,
    'combos' => [['size' => 'L', 'colour' => 'White', 'style' => 'Crew', 'qty' => 10]], 'edit_group' => $dg, 'edit_num' => 103]);
ok(!$okDe && str_contains($mDe, 'edit the whole range') && $gidAt(103, '', 'Bale') === $dg,
   'one package of a direct range is not edited on its own — it has no exact contents', $mDe);
[$okDx, $mDx] = pack_package_delete(7, $dg, 103);
ok(!$okDx && $gidAt(103, '', 'Bale') === $dg, 'nor deleted on its own', $mDx);
[$okDr, $mDr] = $dsave(['from' => 101, 'to' => 112, 'target' => 300, 'edit_range' => $dg,
    'combos' => [['size' => 'S', 'colour' => 'Black', 'style' => 'Crew', 'qty' => 120], ['size' => 'L', 'colour' => 'Navy' === '' ? '' : 'Green', 'style' => 'Crew', 'qty' => 180]]]);
$dgr2 = $cnt("SELECT * FROM packing_groups WHERE id=?", [$dg])[0] ?? [];
ok($okDr && (int)$dgr2['serial_to'] === 112 && (float)$dgr2['total_qty'] === 300.0
   && (int)$cnt("SELECT COUNT(*) c FROM packing_groups WHERE unit_title='Bale'")[0]['c'] === 1,
   'the whole range is edited in place — longer, new totals, still one range', [$mDr, $dgr2]);
$stD = pack_state(7, $items, $cnt("SELECT * FROM shipments WHERE id=7")[0]);
$rd = array_values(array_filter($stD['records'], fn($r) => $r['id'] === $dg))[0] ?? [];
$dgFull = pack_group($dg);
ok(pack_size_text($dgFull, pack_sizes($dg)) === '120 Black S Crew, 180 Green L Crew (in total)',
   'the packing list text for a direct range gives the totals entered, not a per-package average', pack_size_text($dgFull, pack_sizes($dg)));
$g1Full = pack_group($gidAt(1));
ok(pack_size_text($g1Full, pack_sizes($gidAt(1))) === '4 Black S Crew, 3 Green S Crew, 3 White M V-neck',
   'and a per-package range reads exactly as before', pack_size_text($g1Full, pack_sizes($gidAt(1))));
ok(($rd['mode'] ?? '') === 'direct' && ($rd['total'] ?? 0) == 300 && ($rd['combos'][1]['total'] ?? 0) == 180,
   'the phone is told it is direct, with its totals', $rd);
[$okDdel, $mDdel] = pack_range_delete(7, $dg);
ok($okDdel && !$gidAt(101, '', 'Bale'), 'and it is deleted as a whole range', $mDdel);
[$okDin] = pack_package_save(7, $items, ['item' => 71, 'type' => 'Carton', 'from' => 1, 'to' => 1, 'target' => 10, 'combos' => $mix]);
ok(!$okDin, 'per package is untouched: a used number is still refused');

head('4. The state the phone draws from');
$sh = $cnt("SELECT * FROM shipments WHERE id=7")[0];
$st = pack_state(7, $items, $sh);
ok($st['shipment'] === ['id' => 7, 'invoice' => 'ZAS/5300', 'buyer' => 'Gulf Textiles LLC'], 'the shipment', $st['shipment']);
ok($st['products'][0]['id'] === '71' && $st['products'][0]['line'] === '#1' && $st['products'][0]['setUp'] === true
   && $st['products'][0]['styles'] === ['Crew', 'V-neck'] && $st['products'][0]['unit'] === 'pcs',
   'each invoice line with its number, unit and lists', $st['products'][0]);
ok($st['products'][1]['setUp'] === false && $st['products'][1]['colors'] === [], 'a line not set up says so, with no colours invented', $st['products'][1]);
$c1 = array_values(array_filter($st['records'], fn($r) => $r['prefix'] === '' && $r['type'] === 'Carton' && $r['from'] === 1))[0] ?? null;
ok($c1 && $c1['to'] === 4 && $c1['pad'] === 3 && $c1['target'] == 10 && count($c1['combos']) === 3
   && $c1['combos'][0] == ['size' => 'S', 'colour' => 'Black', 'style' => 'Crew', 'qty' => 4, 'total' => 16]
   && $c1['mode'] === 'per',
   'every range with its serials, pieces per package and contents', $c1);

head('5. Finish packing, reopen');
[$okF, $mF] = pack_finish(7);
$sh = $cnt("SELECT * FROM shipments WHERE id=7")[0];
ok($okF && $sh['packing_status'] === 'completed', 'finish marks the shipment complete', [$mF, $sh['packing_status']]);
$pi = $cnt("SELECT SUM(packages) p, SUM(total_qty) q FROM packing_items WHERE shipment_id=7")[0];
$pg = $cnt("SELECT SUM(packages) p, SUM(total_qty) q FROM packing_groups WHERE shipment_id=7")[0];
ok((int)$pi['p'] === (int)$pg['p'] && (float)$pi['q'] === (float)$pg['q'] && (int)$pi['p'] > 0,
   'and the packing list rows add up to what was packed', [$pi, $pg]);
ok((int)$sh['total_packages'] === (int)$pg['p'], 'with the shipment\'s package total written', $sh['total_packages']);
[$okO] = pack_reopen(7);
ok($okO && $cnt("SELECT packing_status FROM shipments WHERE id=7")[0]['packing_status'] === 'open', 'reopen opens it again');

/* ============================================================== the page */
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
           . 'require "m_pack.php";') . ' 2>&1';
    return (string)shell_exec($cmd);
}
head('6. The page, and the API behind it');
$html = page($work, ['id' => 7]);
ok(!preg_match('~(Warning|Notice|Deprecated|Fatal error|Parse error)~', $html), 'it renders with no PHP warning',
   preg_match('~.{0,200}(Warning|Notice|Fatal error).{0,200}~s', $html, $mm) ? $mm[0] : null);
ok(str_contains($html, 'id="sp"') && str_contains($html, 'data-tab="packing"') && str_contains($html, 'data-tab="records"')
   && str_contains($html, 'data-tab="summary"'), 'with the three tabs: Packing, Records, Summary');
preg_match('~var STATE = (\{.*?\});\nvar CAN_EDIT~s', $html, $sm);
$state = json_decode($sm[1] ?? '', true);
ok(is_array($state) && count($state['products']) === 4 && count($state['records']) >= 5, 'the state is embedded, and reads back', $sm[1] ?? null);
ok(!str_contains($sm[1] ?? '', '</'), 'embedded so a product name cannot close the script tag');
ok(!preg_match('~<script[^>]+src=~i', substr($html, strpos($html, 'id="sp"'))), 'no framework or library is loaded — plain javascript');
$app = substr($html, strpos($html, 'id="sp"'));
ok(!str_contains($app, '<form') && !preg_match('~location\.(reload|href\s*=)|\.submit\(\)~', $app),
   'nothing on the packing screen submits a form or reloads the page');
ok(!str_contains($app, 't=weight') && str_contains($html, 'weight.php?id=7#link'),
   'weight is a module of its own: packing links to weight.php, the old weight screen is not used');
ok(!str_contains($app, 'id="spPrefix"') && !str_contains($app, 'Serial prefix'), 'and no prefix box');
$api = fn(array $b, string $env = '') => page($work, ['id' => 7, 'api' => 1], $env, json_encode($b));
$noTok = $api(['op' => 'finish']);
ok(str_contains($noTok, 'CSRF'), 'a change without the CSRF token is refused', $noTok);
$okSave = json_decode($api(['_csrf' => 'testtoken', 'op' => 'save', 'item' => 71, 'type' => 'Carton', 'prefix' => 'C', 'pad' => 3,
                            'from' => 20, 'to' => 21, 'target' => 10, 'combos' => $mix]), true);
ok(($okSave['ok'] ?? false) === true && count($okSave['state']['records'] ?? []) === count($state['records']) + 1,
   'a save answers with the whole new state', $okSave['msg'] ?? $okSave);
$dupe = json_decode($api(['_csrf' => 'testtoken', 'op' => 'save', 'item' => 71, 'type' => 'Carton', 'prefix' => 'C',
                          'from' => 21, 'to' => 21, 'target' => 10, 'combos' => $mix]), true);
ok(($dupe['ok'] ?? true) === false && str_contains($dupe['msg'] ?? '', 'already used'), 'a duplicate answers why, and the state still comes back',
   $dupe);
ok(isset($dupe['state']['records']), 'so the screen redraws from what is really saved');
$api(['_csrf' => 'testtoken', 'op' => 'finish']);
$closed = json_decode($api(['_csrf' => 'testtoken', 'op' => 'save', 'item' => 71, 'type' => 'Carton', 'prefix' => 'C',
                            'from' => 30, 'to' => 30, 'target' => 10, 'combos' => $mix]), true);
ok(($closed['ok'] ?? true) === false && str_contains($closed['msg'] ?? '', 'Reopen'), 'a finished list refuses a save until reopened', $closed);
$staff = json_decode($api(['_csrf' => 'testtoken', 'op' => 'reopen'], 'ROLE=staff'), true);
ok(($staff['ok'] ?? true) === false, 'staff cannot reopen a finished list — the same rule as the desktop', $staff);
$re = json_decode($api(['_csrf' => 'testtoken', 'op' => 'reopen']), true);
ok(($re['ok'] ?? false) === true && $re['state']['completed'] === false, 'an admin can', $re);
$weightPage = page($work, ['id' => 7, 't' => 'weight']);
ok(str_contains($weightPage, '__REDIRECT__ weight.php?id=7#link'), 'an old link to the weight screen goes to the Weight module', substr($weightPage, 0, 300));

/* =========================================================== in Chromium */
head('7. In Chromium, through a real server');
$node = trim((string)shell_exec('command -v node 2>/dev/null'));
$root = trim((string)shell_exec('cd ' . escapeshellarg(__DIR__) . ' && npm root -g 2>/dev/null'));
if ($node === '' || !is_dir($root . '/playwright')) {
    echo "  (skipped — playwright not available)\n";
} else {
    /* the browser packs plain numbers from 1; section 2 left a plain 1 */
    db()->exec("DELETE FROM packing_group_sizes WHERE group_id IN (SELECT id FROM packing_groups WHERE serial_prefix='')");
    db()->exec("DELETE FROM packing_groups WHERE serial_prefix=''");
    $port = 18000 + random_int(0, 999);
    $srv = proc_open([PHP_BINARY, '-S', '127.0.0.1:' . $port, '-t', $work], [0 => ['pipe', 'r'], 1 => ['file', '/dev/null', 'w'], 2 => ['file', '/dev/null', 'w']], $pipes, $work);
    usleep(400000);
    $js = <<<'JS'
const { chromium } = require('playwright');
let R = {};
(async () => {
  const url = process.argv[2]; R = {};
  const b = await chromium.launch({ executablePath: '/opt/pw-browsers/chromium' });
  const p = await b.newPage({ viewport: { width: 390, height: 844 } });
  const errs = []; let loads = 0;
  p.on('pageerror', e => errs.push(String(e)));
  /* the phone shell's service worker and icons are not in this test copy;
     their 404s are about the copy, not the screen */
  p.on('console', m => { if (m.type() === 'error' && !/\b404\b/.test(m.text())) errs.push('console: ' + m.text()); });
  p.on('dialog', d => d.accept());
  p.on('framenavigated', f => { if (f === p.mainFrame()) loads++; });
  const W = (ms = 250) => p.waitForTimeout(ms);
  const D = () => p.evaluate(() => JSON.parse(JSON.stringify(ZASPacking.draft)));
  await p.goto(url); await p.evaluate(() => localStorage.clear()); await p.reload(); await W(400);
  loads = 0;
  R.width = await p.evaluate(() => document.documentElement.scrollWidth);
  /* product search forgives a typo */
  await p.fill('#spSearch', 'tshrt'); await W();
  R.search = await p.locator('#spResults button b').allTextContents();
  await p.locator('#spResults button').first().click(); await W();
  let d = await D(); R.picked = d.productId; R.firstSerial = d.start;
  await p.locator('[data-a=clear]').click().catch(() => {}); await W(100);
  R.sizes = await p.locator('#spSizes .t').allTextContents();
  R.colours = await p.locator('#spColors .t').allTextContents();
  R.styles = await p.locator('#spStyles .t').allTextContents();
  /* size S, style Crew: 4 Black, 3 Green; size M, V-neck: 3 White */
  await p.locator('#spSizes button', { hasText: /^S/ }).click(); await W(80);
  await p.locator('#spStyles button', { hasText: 'Crew' }).click(); await W(80);
  for (let i = 0; i < 4; i++) { await p.locator('#spColors button', { hasText: 'Black' }).click(); await W(40); }
  for (let i = 0; i < 3; i++) { await p.locator('#spColors button', { hasText: 'Green' }).click(); await W(40); }
  await p.locator('[data-a=undo]').click(); await W(80);
  R.afterUndo = (await D()).combos.map(c => c.size + '/' + c.color + '/' + c.style + ' ' + c.qty).join(', ');
  await p.locator('#spColors button', { hasText: 'Green' }).click(); await W(40);
  await p.locator('#spSizes button', { hasText: /^M/ }).click(); await W(80);
  await p.locator('#spStyles button', { hasText: 'V-neck' }).click(); await W(80);
  await p.locator('#spColors button', { hasText: 'White' }).click(); await W(80);
  await p.locator('[data-a=fill]').click(); await W(120);
  d = await D(); R.combos = d.combos.map(c => c.size + '/' + c.color + '/' + c.style + ' ' + c.qty).join(', ');
  R.dock = (await p.locator('.sp-dock .cval').textContent()).replace(/\s+/g, ' ');
  /* an eleventh tap is refused, not squeezed in */
  await p.locator('#spColors button', { hasText: 'Black' }).click(); await W(120);
  R.overfull = (await D()).combos.reduce((s, c) => s + c.qty, 0);
  R.fullToast = await p.locator('#spToast').textContent();
  /* the line was last packed padded to 3 digits; these are plain numbers */
  await p.locator('#spPkgDetails summary').click(); await W(80);
  R.prefixBox = await p.locator('#spPrefix').count();
  await p.selectOption('#spPad', '0'); await W(120);
  R.plainStart = (await D()).start;
  /* letters are not a package number any more */
  await p.fill('#spStart', 'C5'); await W(80);
  R.lettersRefused = await p.locator('#spPreview').textContent();
  /* Save & Next */
  await p.fill('#spStart', '1'); await W(80);
  await p.locator('#spSave').click(); await W(900);
  d = await D(); R.nextSerial = d.start; R.keptCombos = d.combos.length;
  R.toast1 = await p.locator('#spToast').textContent();
  R.saved = await p.evaluate(() => ZASPacking.packages().filter(x => x.item === '71' && x.prefix === '' && x.num === 1).length);
  /* another phone saves 50 meanwhile; this one does not know yet */
  R.other = await p.evaluate(async () => {
    const r = await fetch(location.pathname + location.search + '&api=1', { method: 'POST', credentials: 'same-origin',
      headers: { 'Content-Type': 'application/json' }, body: JSON.stringify({ _csrf: 'testtoken', op: 'save', item: 72,
      type: 'Bag', prefix: '', pad: 0, from: 50, to: 50, target: 5, combos: [{ size: '', colour: '', style: '', qty: 5 }] }) });
    return (await r.json()).ok; });
  R.seesOtherBefore = await p.evaluate(() => ZASPacking.packages().some(x => x.num === 50 && x.type === 'Bag'));
  /* a number already used is refused with the reason — and the answer
     brings the other phone's package too */
  await p.fill('#spStart', '1'); await p.locator('#spSave').click(); await W(800);
  R.dupToast = await p.locator('#spToast').textContent();
  R.seesOtherAfter = await p.evaluate(() => ZASPacking.packages().some(x => x.num === 50 && x.type === 'Bag'));
  /* the next number is per kind: no plain Roll yet, so a Roll starts at 1 */
  await p.locator('#spPkgDetails summary').click().catch(() => {}); await W(80);
  if (!(await p.locator('#spType').isVisible())) { await p.locator('#spPkgDetails summary').click(); await W(80); }
  await p.selectOption('#spType', 'Roll'); await W(120);
  R.rollStart = (await D()).start;
  await p.selectOption('#spType', 'Carton'); await W(120);
  R.cartonAgain = (await D()).start;
  /* a range of 3, next number after it */
  await p.locator('[data-a=entry-mode][data-mode=range]').click(); await W(100);
  await p.fill('#spStart', '2'); await p.fill('#spEnd', '4'); await W(100);
  R.preview = await p.locator('#spPreview').textContent();
  await p.locator('#spSave').click(); await W(900);
  R.afterRange = (await D()).start;
  /* typing in the pieces box keeps every key */
  await p.click('#spTarget'); await p.keyboard.type('12'); await W(60);
  R.typed = await p.inputValue('#spTarget'); R.focusKept = await p.evaluate(() => document.activeElement.id);
  await p.fill('#spTarget', '10'); await p.keyboard.press('Tab'); await W(100);
  /* DIRECT: a small switch in the quantity cell, on a serial range */
  await p.locator('[data-a=entry-mode][data-mode=single]').click(); await W(100);
  R.qmodeSingle = await p.locator('.qmode').count();
  await p.locator('[data-a=entry-mode][data-mode=range]').click(); await W(100);
  await p.fill('#spStart', '30'); await p.fill('#spEnd', '39'); await W(80);
  await p.locator('#spEnd').dispatchEvent('input'); await W(80);
  R.qmodeRange = await p.locator('.qmode button').allTextContents();
  await p.locator('[data-a=qty-mode][data-mode=direct]').click(); await W(150);
  d = await D(); R.direct = { mode: d.qtyMode, target: d.target, combos: d.combos.map(c => c.qty).join(',') };
  R.directLabel = await p.locator('label[for=spTarget]').textContent();
  R.directNote = await p.locator('#spDirect').textContent();
  await p.locator('[data-a=clear]').click(); await W(80);
  await p.fill('#spTarget', '250'); await p.locator('#spTarget').dispatchEvent('change'); await W(120);
  /* L / Green / V-neck: one tap, then type the total of 100 */
  await p.locator('#spSizes button', { hasText: /^L/ }).click(); await p.locator('#spStyles button', { hasText: 'V-neck' }).click(); await W(60);
  await p.locator('#spColors button', { hasText: 'Green' }).click(); await W(60);
  await p.locator('[data-a=edit-current]').click(); await W(100);
  await p.fill('#spQty', '100'); await p.locator('[data-a=qty-apply]').click(); await W(100);
  /* M / Green / Crew: the remaining 150 */
  await p.locator('#spSizes button', { hasText: /^M/ }).click(); await p.locator('#spStyles button', { hasText: 'Crew' }).click(); await W(60);
  await p.locator('#spColors button', { hasText: 'Green' }).click(); await W(60);
  await p.locator('[data-a=fill]').click(); await W(100);
  R.directSaveBtn = await p.locator('#spSave .sc').textContent();
  await p.locator('#spSave').click(); await W(900);
  R.directSaved = await p.evaluate(() => ZASPacking.state.records.filter(r => r.mode === 'direct').map(r => r.from + '-' + r.to + ' ' + r.total + ' ' + r.combos.map(c => c.size + c.colour + c.total).join('+')));
  R.directNext = (await D()).start;
  /* back to per package for the rest */
  await p.locator('[data-a=qty-mode][data-mode=per]').click(); await W(100);
  await p.locator('[data-a=entry-mode][data-mode=single]').click(); await W(100);
  if (await p.locator('[data-a=clear]').isEnabled()) await p.locator('[data-a=clear]').click();
  await p.fill('#spTarget', '10'); await p.locator('#spTarget').dispatchEvent('change'); await W(100);
  /* the draft survives a reload */
  await p.locator('#spSizes button', { hasText: /^L/ }).click(); await W(80);
  await p.reload(); await W(400);
  R.draftKept = (await D()).selectedSize; loads = 0;
  /* records: one by one, and as ranges */
  await p.locator('[data-a=tab][data-tab=records]').click(); await W();
  await p.locator('[data-a=record-mode][data-mode=ranges]').click(); await W();
  R.rangeRows = await p.locator('#spRecList .serial').allTextContents();
  await p.locator('[data-a=record-mode][data-mode=individual]').click(); await W();
  /* the units shown on Records, against the ranges added up here: a direct
     range counts its total once */
  R.units = { shown: (await p.locator('.stat .v').nth(1).textContent()).replace(/,/g, ''),
    expected: String(await p.evaluate(() => ZASPacking.state.records.reduce((s, r) => s + (r.mode === 'direct' ? r.total : r.target * (r.to - r.from + 1)), 0))) };
  const drec = p.locator('article').filter({ has: p.locator('.pill.direct') });
  R.directRec = { count: await drec.count(), serial: await drec.locator('.serial').first().textContent().catch(() => ''),
    acts: await drec.locator('.acts button').allTextContents(), brk: await drec.locator('.brk').first().textContent().catch(() => '') };
  await drec.locator('[data-a=edit-range]').click(); await W(150);
  d = await D(); R.editRange = { on: !!d.editRange, start: d.start, end: d.end, target: d.target, mode: d.qtyMode, title: await p.locator('#spMain h2').first().textContent() };
  await p.fill('#spEnd', '41'); await W(60);
  await p.locator('#spSave').click(); await W(900);
  R.afterEditRange = await p.evaluate(() => ZASPacking.state.records.filter(r => r.mode === 'direct').map(r => r.from + '-' + r.to + ' ' + r.total));
  await p.locator('.sp-tabs [data-tab=summary]').click(); await W();
  R.summaryDirect = await p.locator('.sline').filter({ hasText: 'L / Green / V-neck' }).locator('.small').textContent().catch(() => '');
  await p.locator('.sp-tabs [data-tab=records]').click(); await W();
  await p.locator('article').filter({ has: p.locator('.pill.direct') }).locator('[data-a=delete-range]').click(); await W(900);
  R.afterDeleteRange = await p.evaluate(() => ZASPacking.state.records.filter(r => r.mode === 'direct').length);
  await p.locator('[data-a=record-mode][data-mode=ranges]').click(); await W();
  await p.locator('[data-a=record-mode][data-mode=individual]').click(); await W();
  await p.fill('#spRecSearch', 't-shirt'); await W();
  const row = n => p.locator('article').filter({ has: p.locator('.serial', { hasText: new RegExp('^' + n + '$') }) });
  /* edit package 3 only */
  await row('3').locator('[data-a=edit]').click(); await W();
  d = await D(); R.editing = d.edit ? d.edit.num : null; R.editSerial = d.start; R.rangeLocked = await p.locator('[data-a=entry-mode][data-mode=range]').isDisabled();
  await p.locator('[data-a=clear]').click(); await W(80);
  await p.locator('#spSizes button', { hasText: /^L/ }).click(); await W(60);
  await p.locator('#spColors button', { hasText: 'White' }).click(); await W(60);
  await p.locator('[data-a=fill]').click(); await W(80);
  await p.locator('#spSave').click(); await W(900);
  R.afterEdit = await p.evaluate(() => ZASPacking.state.records.filter(r => r.item === '71' && r.prefix === '' && r.type === 'Carton')
    .sort((a, b) => a.from - b.from).map(r => r.from + '-' + r.to + (r.edited ? '*' : '') + ' ' + r.combos.map(c => c.size + c.colour + c.qty).join('+')));
  /* delete package 4 */
  await p.locator('[data-a=tab][data-tab=records]').click(); await W();
  await p.fill('#spRecSearch', 't-shirt'); await W();
  await row('4').locator('[data-a=delete]').click(); await W(900);
  R.afterDelete = await p.evaluate(() => ZASPacking.packages().filter(x => x.item === '71' && x.prefix === '' && x.type === 'Carton').map(x => x.num));
  /* copy package 2 onto the next free number */
  await row('2').locator('[data-a=copy]').click(); await W();
  d = await D(); R.copyTo = d.start; R.copyEdit = d.edit;
  /* summary */
  await p.locator('[data-a=tab][data-tab=summary]').click(); await W();
  R.summary = await p.locator('.sline b').allTextContents();
  R.weightLink = await p.locator('#spMain a[href*="weight.php"]').count();
  /* a line with no sizes, colours or styles shows none of them */
  await p.locator('.sp-tabs [data-tab=packing]').click(); await W();
  const pick = async q => { await p.fill('#spSearch', q); await W(); await p.locator('#spResults button').first().click(); await W(); };
  await pick('towel');
  R.towel = { sizes: await p.locator('#spSizes').count(), colours: await p.locator('#spColors').count(), styles: await p.locator('#spStyles').count(),
    addOne: await p.locator('[data-a=count][data-type=unit]').count(), notice: await p.locator('#spMain .sp-notice').count(),
    words: /select size|colour|style|not set up/i.test(await p.locator('#spMain').innerText()) };
  await p.locator('[data-a=count][data-type=unit]').click(); await W(80);
  R.towelCombo = await p.locator('.combo .text').allTextContents();
  /* a line with sizes only: no colour or style buttons */
  await pick('bed sheet');
  R.sheet = { sizes: await p.locator('#spSizes .t').allTextContents(), colours: await p.locator('#spColors').count(), styles: await p.locator('#spStyles').count(),
    countBtn: await p.locator('[data-a=count][data-type=unit]').count() };
  await p.locator('.sp-tabs [data-tab=summary]').click(); await W();
  await p.locator('[data-a=finish]').click(); await W(1200);
  R.finished = await p.evaluate(() => ZASPacking.state.completed);
  R.lockedDock = await p.evaluate(() => { const b = document.querySelector('[data-a=tab][data-tab=packing]'); b.click(); return !!document.querySelector('#spSave'); });
  await p.locator('[data-a=tab][data-tab=summary]').click(); await W();
  await p.locator('[data-a=reopen]').click(); await W(900);
  R.reopened = await p.evaluate(() => ZASPacking.state.completed === false && !!document.querySelector('#spSave'));
  R.loads = loads; R.errors = errs;
  R.widthEnd = await p.evaluate(() => document.documentElement.scrollWidth);
  console.log(JSON.stringify(R));
  await b.close();
})().catch(e => { R.crashed = String(e).slice(0, 400); console.log(JSON.stringify(R)); process.exit(0); });
JS;
    file_put_contents($work . '/drive.js', $js);
    $out = (string)shell_exec('NODE_PATH=' . escapeshellarg($root) . ' ' . escapeshellarg($node) . ' '
        . escapeshellarg($work . '/drive.js') . ' ' . escapeshellarg("http://127.0.0.1:$port/m_pack.php?id=7") . ' 2>&1');
    proc_terminate($srv); proc_close($srv);
    $R = json_decode(trim((string)substr($out, (int)strrpos($out, "\n{") ?: 0)), true) ?: json_decode(trim($out), true);
    ok(is_array($R) && !isset($R['crashed']), 'chromium drove the page', $out);
    $R = is_array($R) ? $R : [];
    ok(($R['width'] ?? 999) <= 390 && ($R['widthEnd'] ?? 999) <= 390, 'nothing scrolls sideways on a phone', [$R['width'] ?? null, $R['widthEnd'] ?? null]);
    ok(($R['search'] ?? [])[0] ?? '' === 'T-Shirt', 'a misspelt search still finds the product', $R['search'] ?? null);
    ok(($R['sizes'] ?? []) === ['S', 'M', 'L'], 'its line\'s sizes are the buttons', $R['sizes'] ?? null);
    ok(($R['colours'] ?? []) === ['Black', 'Green', 'White'], 'its colours', $R['colours'] ?? null);
    ok(($R['styles'] ?? []) === ['Crew', 'V-neck'], 'and its styles', $R['styles'] ?? null);
    ok(($R['afterUndo'] ?? '') === 'S/Black/Crew 4, S/Green/Crew 2', 'Undo takes back the last tap', $R['afterUndo'] ?? null);
    ok(($R['combos'] ?? '') === 'S/Black/Crew 4, S/Green/Crew 3, M/White/V-neck 3', 'size, then a tap per piece, then Fill remaining', $R['combos'] ?? null);
    ok(str_starts_with($R['dock'] ?? '', '10 / 10'), 'the counter says 10 of 10', $R['dock'] ?? null);
    ok(($R['overfull'] ?? 0) === 10, 'a tap past full is refused, not squeezed in', $R['overfull'] ?? null);
    ok(str_contains($R['fullToast'] ?? '', 'Package complete'), 'and it says the package is complete', $R['fullToast'] ?? null);
    ok(($R['prefixBox'] ?? 1) === 0, 'there is no prefix box', $R['prefixBox'] ?? null);
    ok(str_contains($R['lettersRefused'] ?? '', 'whole package number'), 'and "C5" is not taken as a package number', $R['lettersRefused'] ?? null);
    ok(($R['plainStart'] ?? '') === '1', 'padding off: the first free number is plain 1', $R['plainStart'] ?? null);
    ok(($R['saved'] ?? 0) === 1 && ($R['nextSerial'] ?? '') === '2', 'Save & Next saves package 1 and moves to 2 by itself', [$R['saved'] ?? null, $R['nextSerial'] ?? null]);
    ok(($R['keptCombos'] ?? 0) === 3, 'with the contents kept ready for the next one', $R['keptCombos'] ?? null);
    ok(str_contains($R['dupToast'] ?? '', 'already used'), 'a number already used is refused with the reason', $R['dupToast'] ?? null);
    ok(($R['other'] ?? false) === true && ($R['seesOtherBefore'] ?? true) === false && ($R['seesOtherAfter'] ?? false) === true,
       'and even a refused save brings back what another phone saved meanwhile',
       [$R['other'] ?? null, $R['seesOtherBefore'] ?? null, $R['seesOtherAfter'] ?? null]);
    ok(($R['rollStart'] ?? '') === '1' && ($R['cartonAgain'] ?? '') === '2', 'numbers are per kind: a Roll starts at 1, back to Carton is 2 again',
       [$R['rollStart'] ?? null, $R['cartonAgain'] ?? null]);
    ok(str_contains($R['preview'] ?? '', '3 packages') && str_contains($R['preview'] ?? '', '30 pcs'), 'a range previews its packages and pieces', $R['preview'] ?? null);
    ok(($R['afterRange'] ?? '') === '5', 'and after it the next number is 5', $R['afterRange'] ?? null);
    ok(($R['qmodeSingle'] ?? 1) === 0, 'one package has no Per/Direct switch — it is always per package', $R['qmodeSingle'] ?? null);
    ok(($R['qmodeRange'] ?? []) === ['PER PKG', 'DIRECT'], 'a serial range has the small switch in the quantity cell', $R['qmodeRange'] ?? null);
    ok(($R['direct'] ?? null) === ['mode' => 'direct', 'target' => 100, 'combos' => '40,30,30'],
       'Direct turns 10 a carton into the 100 total for the ten cartons, counted figures too', $R['direct'] ?? null);
    ok(trim($R['directLabel'] ?? '') === 'Total pcs' && str_contains($R['directNote'] ?? '', 'total for all 10 packages')
       && str_contains($R['directNote'] ?? '', 'Every serial number is still saved'),
       'the box says Total, and one line explains direct — serials still saved', [$R['directLabel'] ?? null, $R['directNote'] ?? null]);
    ok(str_contains($R['directSaveBtn'] ?? '', '30 – 39') && str_contains($R['directSaveBtn'] ?? '', '250 pcs direct'),
       'Save says the serials and the direct total', $R['directSaveBtn'] ?? null);
    ok(($R['directSaved'] ?? []) === ['30-39 250 LGreen100+MGreen150'], 'and saves the range with its totals by size and colour', $R['directSaved'] ?? null);
    ok(($R['directNext'] ?? '') === '40', 'then moves to the next free number, 40', $R['directNext'] ?? null);
    ok(($R['directRec']['count'] ?? 0) === 1 && ($R['directRec']['serial'] ?? '') === '30 – 39'
       && ($R['directRec']['acts'] ?? []) === ['Edit range', 'Delete range'] && str_contains($R['directRec']['brk'] ?? '', 'L / Green / V-neck: 100'),
       'Records shows the direct range as one record, with its totals, Edit range and Delete range', $R['directRec'] ?? null);
    ok(($R['editRange'] ?? null) === ['on' => true, 'start' => '30', 'end' => '39', 'target' => 250, 'mode' => 'direct', 'title' => 'Edit range'],
       'Edit range opens the whole range with its total', $R['editRange'] ?? null);
    ok(($R['afterEditRange'] ?? []) === ['30-41 250'], 'and saving it changes that range in place', $R['afterEditRange'] ?? null);
    ok(str_contains($R['summaryDirect'] ?? '', '100 pcs · in 12 packages'), 'Summary counts the direct total once, not per package', $R['summaryDirect'] ?? null);
    ok(($R['units']['shown'] ?? 'x') === ($R['units']['expected'] ?? 'y'), 'Records counts the direct total once in the packed units', $R['units'] ?? null);
    ok(($R['afterDeleteRange'] ?? 1) === 0, 'Delete range removes it', $R['afterDeleteRange'] ?? null);
    ok(($R['typed'] ?? '') === '1012' || ($R['typed'] ?? '') === '12', 'typing keeps every key', $R['typed'] ?? null);
    ok(($R['focusKept'] ?? '') === 'spTarget', 'and the cursor stays in the box', $R['focusKept'] ?? null);
    ok(($R['draftKept'] ?? '') === 'L', 'an unsaved draft survives a reload', $R['draftKept'] ?? null);
    ok(in_array('1 – 4', $R['rangeRows'] ?? [], true), 'Records shows packages 1–4 as one range', $R['rangeRows'] ?? null);
    ok(($R['editing'] ?? null) === 3 && ($R['editSerial'] ?? '') === '3' && ($R['rangeLocked'] ?? false),
       'Edit opens package 3 alone, with Serial range switched off', [$R['editing'] ?? null, $R['editSerial'] ?? null]);
    ok(($R['afterEdit'] ?? []) === ['1-1 SBlack4+SGreen3+MWhite3', '2-2 SBlack4+SGreen3+MWhite3', '3-3* LWhite10', '4-4 SBlack4+SGreen3+MWhite3'],
       'saving it changes 3 alone, marked edited', $R['afterEdit'] ?? null);
    ok(($R['afterDelete'] ?? []) === [1, 2, 3], 'Delete removes package 4 and nothing else', $R['afterDelete'] ?? null);
    ok(($R['copyTo'] ?? '') === '4' && array_key_exists('copyEdit', $R) && $R['copyEdit'] === null, 'Copy puts the contents on the next free number, as a new package', [$R['copyTo'] ?? null]);
    ok(in_array('S / Black / Crew', $R['summary'] ?? [], true) && in_array('L / White / Crew', $R['summary'] ?? [], true),
       'Summary adds up by size, colour and style', $R['summary'] ?? null);
    ok(($R['weightLink'] ?? 0) === 1, 'with one Weight button, to the Weight module', $R['weightLink'] ?? null);
    ok(($R['towel'] ?? null) === ['sizes' => 0, 'colours' => 0, 'styles' => 0, 'addOne' => 1, 'notice' => 0, 'words' => false],
       'a line with no sizes, colours or styles hides them all — just "+ Add one"', $R['towel'] ?? null);
    ok(($R['towelCombo'] ?? []) === ['Pieces'], 'and its contents read as plain pieces', $R['towelCombo'] ?? null);
    ok(($R['sheet'] ?? null) === ['sizes' => ['Single', 'Double'], 'colours' => 0, 'styles' => 0, 'countBtn' => 1],
       'a line with sizes only shows sizes and no colour or style', $R['sheet'] ?? null);
    ok(($R['finished'] ?? false) === true && ($R['lockedDock'] ?? true) === false, 'Finish packing closes it, and the Save button goes',
       [$R['finished'] ?? null, $R['lockedDock'] ?? null]);
    ok(($R['reopened'] ?? false) === true, 'Reopen brings it back', $R['reopened'] ?? null);
    ok(($R['loads'] ?? 1) === 0, 'and not one page load in all of that', $R['loads'] ?? null);
    ok(($R['errors'] ?? ['?']) === [], 'no javascript error', $R['errors'] ?? null);
}

echo "\n" . ($F === 0 ? "ALL PASS   ($P checks)\n" : "FAILED  $F   (" . ($P + $F) . " checks)\n");
exit($F === 0 ? 0 : 1);
