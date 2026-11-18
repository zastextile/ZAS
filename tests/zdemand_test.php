<?php
declare(strict_types=1);

/* DEMAND SHEET — includes/demand.php (owner, 18 Nov 2026).
 *  1. the formula: per piece, "1 per N pcs" rounded up, extra %, a kg line bought by its kg
 *  2. made from each size's costing (never workmanship); an order line with no costing is kept, empty
 *  3. the summary adds the same purchase across sizes, keeps a size label per size apart
 *  4. amend and save; a partial purchase order; Gate Inward received on it; still to order
 * Run through tests/run_att.sh. */

$P = 0; $F = 0;
function ok($c, $m) { global $P, $F; if ($c) { $P++; } else { $F++; echo "  FAIL: $m\n"; } }
$dsn = getenv('ATT_TEST_DSN') ?: 'mysql:host=127.0.0.1;port=3306;dbname=zas_att_test;charset=utf8mb4';
$dbu = getenv('ATT_TEST_USER') ?: 'zas';
$dbp = getenv('ATT_TEST_PASS'); if ($dbp === false) { $dbp = 'zaspw'; }
try { $pdo = new PDO($dsn, $dbu, $dbp, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC]); }
catch (Throwable $e) { echo "SKIP: no MySQL available\n"; exit(0); }
function db(): PDO { global $pdo; return $pdo; }

$pdo->exec('SET FOREIGN_KEY_CHECKS=0');
foreach ($pdo->query("SELECT table_name AS tn FROM information_schema.tables WHERE table_schema = DATABASE()")->fetchAll() as $t) { $pdo->exec('DROP TABLE IF EXISTS `' . $t['tn'] . '`'); }
$pdo->exec('SET FOREIGN_KEY_CHECKS=1');
$pdo->exec("CREATE TABLE products (id INT AUTO_INCREMENT PRIMARY KEY, name VARCHAR(200) NOT NULL, is_active TINYINT(1) NOT NULL DEFAULT 1)");
$pdo->exec("CREATE TABLE product_sizes (id INT AUTO_INCREMENT PRIMARY KEY, product_id INT NOT NULL, size_label VARCHAR(80) NOT NULL)");
$pdo->exec("CREATE TABLE costing_versions (id INT AUTO_INCREMENT PRIMARY KEY, product_id INT NOT NULL, version_name VARCHAR(160) NOT NULL)");
$pdo->exec("CREATE TABLE costing_version_sizes (costing_version_id INT NOT NULL, product_size_id INT NOT NULL, PRIMARY KEY (costing_version_id, product_size_id))");
$pdo->exec("CREATE TABLE costing_lines (id INT AUTO_INCREMENT PRIMARY KEY, costing_version_id INT NOT NULL, line_group VARCHAR(40) NOT NULL, item_name VARCHAR(160) NULL, description VARCHAR(500) NULL,
            quantity DECIMAL(14,3) NOT NULL DEFAULT 0, unit VARCHAR(40) NULL, weight_kg DECIMAL(14,3) NOT NULL DEFAULT 0, rate DECIMAL(16,4) NOT NULL DEFAULT 0, amount DECIMAL(16,2) NOT NULL DEFAULT 0,
            sort_order INT NOT NULL DEFAULT 0, shared TINYINT(1) NOT NULL DEFAULT 0, material_id INT NULL)");
$pdo->exec("CREATE TABLE proforma_invoices (id INT AUTO_INCREMENT PRIMARY KEY, pi_no VARCHAR(40) NOT NULL, customer_name VARCHAR(200) NULL)");
$pdo->exec("CREATE TABLE proforma_items (id INT AUTO_INCREMENT PRIMARY KEY, proforma_id INT NOT NULL, product_name VARCHAR(200) NULL, size VARCHAR(80) NULL, qty DECIMAL(14,3) NOT NULL DEFAULT 0,
            sort_order INT NOT NULL DEFAULT 0, product_id INT NULL, product_size_id INT NULL)");
require dirname(__DIR__) . '/public_html/includes/demand.php';
inv_ensure_schema();
dm_ensure_schema();

echo "1. The formula\n";
ok(dm_need(1200, 0.95, false, 1, 0) === [1140.0, 1140.0], 'per piece: 1,200 × 0.95 kg = 1,140 kg');
ok(dm_need(1200, 12, true, 0.95, 0) === [100.0, 95.0], 'one carton per 12 pieces: 100 cartons, 95 kg');
ok(dm_need(1210, 12, true, 0.95, 0)[0] === 101.0, '  1,210 pieces need 101 cartons — whole cartons, rounded up');
ok(dm_need(1000, 1, false, 0, 3)[0] === 1030.0, '3% extra: 1,030');
ok(dm_buy('kg', 380, 22.8, 0.06) === [22.8, 22.8] && dm_buy('KG', 50, 0, 0) === [50.0, 50.0] && dm_buy('pc', 100, 95, 0.95) === [100.0, 95.0],
   'a kg line is bought by its kg (380 bags of 0.06 kg = 22.8 kg); a pc line by its count');

echo "2. Made from each size's costing\n";
$pdo->exec("INSERT INTO inv_materials (code, name, item_group, colour, uom) VALUES ('FAB-1','Cotton 200 TC','Fabric','White','KG'),('ACC-1','Size label','Accessories',NULL,'PC'),('PCK-1','Carton','Packing',NULL,'PC')");
$pdo->exec("INSERT INTO products (id, name) VALUES (1, 'Bed Sheet'), (2, 'Duvet')");
$pdo->exec("INSERT INTO product_sizes (id, product_id, size_label) VALUES (1, 1, 'Single'), (2, 1, 'King')");
foreach ([1 => ['Single', 0.95, 12], 2 => ['King', 1.65, 10]] as $sid => [$lbl, $kg, $ctn]) {
    $pdo->prepare("INSERT INTO costing_versions (product_id, version_name) VALUES (1, ?)")->execute([$lbl]); $cv = (int) $pdo->lastInsertId();
    $pdo->prepare("INSERT INTO costing_version_sizes VALUES (?, ?)")->execute([$cv, $sid]);
    $ins = $pdo->prepare("INSERT INTO costing_lines (costing_version_id, line_group, item_name, description, quantity, unit, weight_kg, shared, material_id, sort_order) VALUES (?,?,?,?,?,?,?,?,?,?)");
    $ins->execute([$cv, 'Fabric', 'Cotton 200 TC', 'White', $kg, 'kg', 1, 0, 1, 1]);
    $ins->execute([$cv, 'Accessories', 'Size label', 'Size ' . $lbl, 1, 'pc', 0, 0, 2, 2]);
    $ins->execute([$cv, 'Packing', 'Carton', '60x40x40', $ctn, 'pc', 0.95, 1, 3, 3]);
    $ins->execute([$cv, 'Workmanship', 'Stitching', '', 1, 'pc', 0, 0, null, 4]);
}
$pdo->exec("INSERT INTO proforma_invoices (id, pi_no) VALUES (1, 'PI-T-1')");
$pdo->exec("INSERT INTO proforma_items (proforma_id, product_name, size, qty, product_id, sort_order) VALUES (1,'Bed Sheet','Single',1200,1,0),(1,'Bed Sheet','King',800,1,1),(1,'Duvet','Queen',300,2,2)");
$r = dm_build(1, 7);
$d = dm_get(1); $L = dm_lines((int) $d['id']);
ok($r['ok'] && $r['lines'] === 7 && $r['missing'] === ['Duvet Queen'], '3 lines a size from the costing (workmanship left out) + 1 empty line for the Duvet, which has no costing (' . json_encode($r) . ')');
$king = array_values(array_filter($L, fn($l) => $l['size'] === 'King' && $l['line_group'] === 'Fabric'))[0];
ok((float) $king['per_pc'] === 1.65 && $king['need'] === 1320.0, '  King takes the King costing: 800 × 1.65 = 1,320 kg');

echo "3. The summary\n";
$S = dm_summary(1, $L);
$by = []; foreach ($S as $x) $by[$x['item_name'] . ' | ' . $x['spec']] = $x;
ok(count($S) === 4, 'four things to buy: fabric, size label Single, size label King, carton (' . implode('; ', array_keys($by)) . ')');
ok($by['Cotton 200 TC | White']['need'] === 2460.0 && count($by['Cotton 200 TC | White']['sizes']) === 2, '  fabric added up across both sizes: 1,140 + 1,320 = 2,460 kg');
ok($by['Size label | Size Single']['need'] === 1200.0 && $by['Size label | Size King']['need'] === 800.0, '  the size label stays one line per size');
ok($by['Carton | 60x40x40']['need'] === 180.0, '  cartons: 100 + 80 = 180');

echo "4. Amend, save, a partial purchase order, the gate\n";
foreach ($L as &$l) { if ($l['size'] === 'Single' && $l['line_group'] === 'Fabric') { $l['per_pc'] = 1; $l['extra_pct'] = 3; } }
unset($l);
ok(dm_save(1, $L, 7)['ok'] === true, 'the whole grid saved at once');
$L = dm_lines((int) $d['id']); $S = dm_summary(1, $L); $fab = array_values(array_filter($S, fn($x) => $x['item_name'] === 'Cotton 200 TC'))[0];
ok($fab['need'] === 2556.0, '  Single amended to 1.00 kg + 3%: 1,236 + 1,320 = 2,556 kg');
$pdo->exec("INSERT INTO inv_parties (code, name, party_type) VALUES ('S1', 'Al-Noor Fabrics', 'supplier')");
$po = dm_make_po(1, 1, [['key' => $fab['key'], 'qty' => 2000, 'rate' => 1450]], '', 7);
$ci = $pdo->query("SELECT c.contract_type, c.proforma_id, c.status, ci.qty, ci.material_id, ci.amount FROM inv_contracts c JOIN inv_contract_items ci ON ci.contract_id = c.id")->fetch();
ok($po['ok'] && $ci['contract_type'] === 'purchase' && (int) $ci['proforma_id'] === 1 && (float) $ci['qty'] === 2000.0 && (float) $ci['amount'] === 2900000.0, 'a purchase contract for 2,000 kg of the 2,556, linked to the order (' . ($po['contract_no'] ?? $po['error'] ?? '') . ')');
$S = dm_summary(1, $L); $fab = array_values(array_filter($S, fn($x) => $x['item_name'] === 'Cotton 200 TC'))[0];
ok($fab['ordered'] === 2000.0 && $fab['to_order'] === 556.0 && $fab['received'] === 0.0, '  On PO 2,000 · still to order 556 · nothing received yet');
$item = (int) $pdo->query("SELECT id FROM inv_contract_items LIMIT 1")->fetchColumn();
$pdo->prepare("INSERT INTO inv_gate (gate_no, direction, txn_type, gate_date, contract_id, status) VALUES ('G1','in','purchase',CURDATE(),?, 'draft')")->execute([$po['contract_id']]);
$g = (int) $pdo->lastInsertId();
$pdo->prepare("INSERT INTO inv_gate_items (gate_id, material_id, qty, contract_id, contract_item_id) VALUES (?, 1, 1500, ?, ?)")->execute([$g, $po['contract_id'], $item]);
$fab = array_values(array_filter(dm_summary(1, $L), fn($x) => $x['item_name'] === 'Cotton 200 TC'))[0];
ok($fab['received'] === 0.0, '  a gate pass not yet posted receives nothing');
$pdo->prepare("UPDATE inv_gate SET status = 'posted' WHERE id = ?")->execute([$g]);
$fab = array_values(array_filter(dm_summary(1, $L), fn($x) => $x['item_name'] === 'Cotton 200 TC'))[0];
ok($fab['received'] === 1500.0, '  posted: 1,500 kg received against the PO');
$pdo->prepare("UPDATE inv_contracts SET status = 'cancelled' WHERE id = ?")->execute([$po['contract_id']]);
$fab = array_values(array_filter(dm_summary(1, $L), fn($x) => $x['item_name'] === 'Cotton 200 TC'))[0];
ok($fab['ordered'] === 0.0 && $fab['to_order'] === 2556.0, 'a cancelled PO no longer counts');
dm_set_status(1, 'final', 7);
ok(dm_save(1, $L, 7)['ok'] === false && dm_build(1, 7)['ok'] === false, 'the final plan cannot be changed or rebuilt until it is reopened');

echo "\nDEMAND SHEET: $P passed, $F failed\n";
exit($F ? 1 : 0);
