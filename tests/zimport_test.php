<?php
/* IMPORTS — PHASE 1.
 *
 * afnan approved the demo ("approved proceed phase 1"). Runs the REAL
 * includes/imports.php, includes/storage.php and every imp_*.php page on a
 * real SQL database (SQLite), each page as its own PHP process as the
 * server would, then drives the invoice and overview in Chromium.
 *
 *   1  THE FIGURES: units never added together; paid and payable with credit
 *      notes, refunds and voids; the due date from BL / arrival / invoice /
 *      fixed / by hand; the stage from the dates; alerts; landed cost shares
 *      that add up exactly; the EFS rule (100 kg × 3.50 → 95 kg, 350) and its
 *      clock; opening balances "still owed".
 *   2  THE PAGES: create (a new supplier goes into the one Parties list);
 *      overview (up to 3 invoices/GDs, hidden options not checked, a due
 *      date by hand needs a reason); invoice lines keep their ids; packing
 *      numbers once per kind; logistics date order; a bank debit that does
 *      not add up is refused; costs need a rate; documents version, archive
 *      and delete only with an admin password; opening balances paste, lock
 *      and adjust with a reason.
 *   3  WHO MAY: CSRF, staff and production staff kept out, a colleague only
 *      with the ticked permission, voids need the Reverse right.
 *   4  IN CHROMIUM: live totals per unit, toggles that hide without losing.
 */

$B    = __DIR__ . '/app_src/public_html/';
$work = __DIR__ . '/.zimport';
$P = 0; $F = 0;
function ok(bool $c, string $m, $got = null): void {
    global $P, $F;
    if ($c) { $P++; } else { $F++; echo "  FAIL  $m" . ($got !== null ? "\n        got: " . var_export($got, true) : '') . "\n"; }
}
function head(string $n): void { echo "\n$n\n"; }

/* a fresh copy of the pages under test */
exec('rm -rf ' . escapeshellarg($work));
@mkdir($work . '/includes', 0777, true); @mkdir($work . '/uploads', 0777, true); @mkdir($work . '/sess', 0777, true);
foreach (glob($B . 'imp_*.php') as $f) copy($f, $work . '/' . basename($f));
copy($B . 'includes/imports.php', $work . '/includes/imports.php');
copy($B . 'includes/imports_efs.php', $work . '/includes/imports_efs.php');
copy($B . 'includes/storage.php', $work . '/includes/storage.php');

$schema = <<<'SQL'
CREATE TABLE exp_meta (k TEXT PRIMARY KEY, v TEXT, updated_at TEXT);
INSERT INTO exp_meta VALUES ('imp_schema_version', '3', NULL);
CREATE TABLE exp_masters (id INTEGER PRIMARY KEY AUTOINCREMENT, kind TEXT, label TEXT, code TEXT, sort_order INT DEFAULT 0, is_active INT DEFAULT 1, flags TEXT, UNIQUE(kind, label));
CREATE TABLE exp_providers (id INTEGER PRIMARY KEY AUTOINCREMENT, name TEXT, is_active INT DEFAULT 1);
CREATE TABLE exp_provider_roles (id INTEGER PRIMARY KEY AUTOINCREMENT, provider_id INT, role TEXT);
CREATE TABLE exp_banks (id INTEGER PRIMARY KEY AUTOINCREMENT, bank_name TEXT, currency TEXT, sort_order INT DEFAULT 0, is_active INT DEFAULT 1);
CREATE TABLE users (id INTEGER PRIMARY KEY, name TEXT, role TEXT, is_active INT DEFAULT 1, password_hash TEXT);
CREATE TABLE inv_parties (id INTEGER PRIMARY KEY AUTOINCREMENT, code TEXT, name TEXT, party_type TEXT, is_active INT DEFAULT 1);
CREATE TABLE inv_materials (id INTEGER PRIMARY KEY AUTOINCREMENT, code TEXT, name TEXT, item_group TEXT DEFAULT 'Other', material_type TEXT, uom TEXT DEFAULT 'KG', is_active INT DEFAULT 1);
CREATE TABLE products (id INTEGER PRIMARY KEY AUTOINCREMENT, name TEXT, is_active INT DEFAULT 1);
CREATE TABLE costing_lines (id INTEGER PRIMARY KEY AUTOINCREMENT, material_id INT);
CREATE TABLE inv_gate (id INTEGER PRIMARY KEY AUTOINCREMENT, gate_no TEXT, direction TEXT DEFAULT 'in', gate_date TEXT, status TEXT DEFAULT 'draft', vehicle_no TEXT, remarks TEXT, import_id INT);
CREATE TABLE inv_gate_items (id INTEGER PRIMARY KEY AUTOINCREMENT, gate_id INT, material_id INT, product_id INT, qty REAL, uom TEXT, rate REAL, lot_no TEXT, sort_order INT DEFAULT 0, imp_line_id INT);
CREATE TABLE imp_gd_lines (id INTEGER PRIMARY KEY AUTOINCREMENT, import_id INT NOT NULL, invoice_id INT, line_no INT NOT NULL DEFAULT 0, hs_code TEXT, description TEXT NOT NULL DEFAULT '',
  declared_qty REAL, declared_unit TEXT, declared_value REAL, assessed_value REAL, exemption_ref TEXT, diff_reason TEXT, updated_by INT, updated_at TEXT, assessed_kg REAL);
CREATE TABLE shipments (id INTEGER PRIMARY KEY, invoice_no TEXT, invoice_date TEXT, buyer_name TEXT, currency TEXT DEFAULT 'USD', total_amount REAL DEFAULT 0);
CREATE TABLE shipment_items (id INTEGER PRIMARY KEY AUTOINCREMENT, shipment_id INT, line_no INT, product_name TEXT, qty REAL, unit TEXT, rate REAL, amount REAL);
CREATE TABLE exp_doc_lines (id INTEGER PRIMARY KEY AUTOINCREMENT, shipment_id INT, view TEXT, line_no INT, description TEXT, hs_code TEXT, unit TEXT, qty REAL, rate REAL, amount REAL);
CREATE TABLE imp_efs_alloc (id INTEGER PRIMARY KEY AUTOINCREMENT, shipment_id INT NOT NULL, source TEXT NOT NULL DEFAULT 'customs', source_hash TEXT, currency TEXT NOT NULL DEFAULT 'USD', rates TEXT,
  export_gd_no TEXT, export_gd_date TEXT, gd_net_kg REAL, gd_fob REAL, below_reason TEXT, warn_reason TEXT, status TEXT NOT NULL DEFAULT 'open', filed_on TEXT, filed_ref TEXT, filed_by INT,
  reversed_by INT, reversed_at TEXT, reverse_reason TEXT, created_by INT, created_at TEXT, updated_by INT, updated_at TEXT);
CREATE TABLE imp_efs_alloc_lines (id INTEGER PRIMARY KEY AUTOINCREMENT, alloc_id INT NOT NULL, src_kind TEXT NOT NULL, src_id INT NOT NULL, description TEXT, hs_code TEXT, line_value REAL NOT NULL DEFAULT 0,
  efs_value REAL NOT NULL DEFAULT 0, manual INT NOT NULL DEFAULT 0, sort_order INT NOT NULL DEFAULT 0);
CREATE TABLE imp_efs_alloc_uses (id INTEGER PRIMARY KEY AUTOINCREMENT, alloc_id INT NOT NULL, alloc_line_id INT NOT NULL, target_kind TEXT NOT NULL, target_id INT NOT NULL, kg REAL NOT NULL DEFAULT 0,
  value_export REAL NOT NULL DEFAULT 0, value_target REAL NOT NULL DEFAULT 0, floor_target REAL NOT NULL DEFAULT 0);
CREATE TABLE imp_efs_reductions (id INTEGER PRIMARY KEY AUTOINCREMENT, target_kind TEXT NOT NULL, target_id INT NOT NULL, kg REAL NOT NULL DEFAULT 0, ref TEXT NOT NULL, reason TEXT NOT NULL,
  is_void INT NOT NULL DEFAULT 0, void_reason TEXT, voided_by INT, voided_at TEXT, created_by INT, created_at TEXT);
CREATE TABLE imp_receipts (gate_id INTEGER PRIMARY KEY, import_id INT NOT NULL, container_id INT, packages INT, gross_kg REAL, tare_kg REAL, updated_by INT, updated_at TEXT);
CREATE TABLE imp_receipt_lines (id INTEGER PRIMARY KEY AUTOINCREMENT, gate_id INT NOT NULL, import_id INT NOT NULL, imp_line_id INT NOT NULL, net_kg REAL NOT NULL DEFAULT 0,
  damaged REAL NOT NULL DEFAULT 0, rejected REAL NOT NULL DEFAULT 0, short_qty REAL NOT NULL DEFAULT 0, remark TEXT, UNIQUE(gate_id, imp_line_id));
CREATE TABLE imp_imports (id INTEGER PRIMARY KEY AUTOINCREMENT, ref TEXT NOT NULL UNIQUE, employee TEXT, supplier_party_id INT, supplier_name TEXT NOT NULL DEFAULT '',
  origin_country TEXT, shipped_from TEXT, contract_no TEXT, contract_date TEXT, incoterm TEXT, incoterm_place TEXT, currency TEXT NOT NULL DEFAULT 'USD',
  import_type TEXT NOT NULL DEFAULT 'ordinary', pay_method TEXT, advance_pct REAL NOT NULL DEFAULT 0, credit INT NOT NULL DEFAULT 0, credit_days INT NOT NULL DEFAULT 0,
  maturity_basis TEXT NOT NULL DEFAULT 'bl', fixed_due_date TEXT, due_override TEXT, due_override_reason TEXT,
  lc INT NOT NULL DEFAULT 0, lc_no TEXT, lc_bank TEXT, lc_expiry TEXT, lc_last_ship TEXT,
  fta INT NOT NULL DEFAULT 0, fta_agreement TEXT, fta_form TEXT, fta_cert_no TEXT, fta_cert_date TEXT,
  commission INT NOT NULL DEFAULT 0, com_beneficiary TEXT, com_rate_pct REAL NOT NULL DEFAULT 0,
  is_opening INT NOT NULL DEFAULT 0, already_in_stock INT NOT NULL DEFAULT 0, efs_months INT NOT NULL DEFAULT 9, alloc_basis TEXT NOT NULL DEFAULT 'kg',
  manual_status TEXT, notes TEXT, created_by INT, created_at TEXT, updated_by INT, updated_at TEXT);
CREATE TABLE imp_invoices (id INTEGER PRIMARY KEY AUTOINCREMENT, import_id INT NOT NULL, sort_order INT NOT NULL DEFAULT 0, invoice_no TEXT, invoice_date TEXT, gd_no TEXT, gd_date TEXT,
  gd_station TEXT, gd_agent_id INT, gd_status TEXT, gd_assessed TEXT, gd_released TEXT, gd_notes TEXT);
CREATE TABLE imp_lines (id INTEGER PRIMARY KEY AUTOINCREMENT, import_id INT NOT NULL, invoice_id INT, sort_order INT NOT NULL DEFAULT 0, category TEXT NOT NULL DEFAULT 'Other',
  material_id INT, product_id INT, description TEXT NOT NULL DEFAULT '', hs_code TEXT, supplier_ref TEXT, bill_unit TEXT NOT NULL DEFAULT 'kg',
  bill_qty REAL NOT NULL DEFAULT 0, rate REAL NOT NULL DEFAULT 0, net_kg REAL NOT NULL DEFAULT 0, gross_kg REAL NOT NULL DEFAULT 0, packages INT NOT NULL DEFAULT 0,
  specs TEXT, efs_valuation REAL, efs_waste_pct REAL, efs_va_pct REAL, stock_qty REAL, gd_line_id INT, size_label TEXT, efs_eligible INT NOT NULL DEFAULT 1, efs_ext_date TEXT, efs_ext_ref TEXT);
CREATE TABLE imp_logistics (import_id INTEGER PRIMARY KEY, booking_no TEXT, booking_date TEXT, forwarder_id INT, shipping_line_id INT, vessel TEXT, voyage TEXT,
  pol TEXT, transship TEXT, pod TEXT, etd TEXT, eta TEXT, departed TEXT, arrival TEXT, bl_no TEXT, bl_date TEXT, bl_type TEXT, bl_released INT NOT NULL DEFAULT 0,
  gd_filed TEXT, customs_release TEXT, do_date TEXT, port_gate_out TEXT, factory_gate_in TEXT, empty_return TEXT,
  free_days INT NOT NULL DEFAULT 0, terminal_free_days INT NOT NULL DEFAULT 0, freight_agreed REAL, freight_currency TEXT,
  last_event TEXT, last_location TEXT, tracking_at TEXT, notes TEXT, updated_by INT, updated_at TEXT);
CREATE TABLE imp_containers (id INTEGER PRIMARY KEY AUTOINCREMENT, import_id INT NOT NULL, container_no TEXT, size_type TEXT, seal_no TEXT, notes TEXT);
CREATE TABLE imp_packing (id INTEGER PRIMARY KEY AUTOINCREMENT, import_id INT NOT NULL, line_id INT NOT NULL, pkg_type TEXT NOT NULL DEFAULT 'Bale',
  serial_from INT NOT NULL DEFAULT 0, serial_to INT NOT NULL DEFAULT 0, packages INT NOT NULL DEFAULT 0, net_kg REAL NOT NULL DEFAULT 0, gross_kg REAL NOT NULL DEFAULT 0, roll_len REAL, container_id INT);
CREATE TABLE imp_payments (id INTEGER PRIMARY KEY AUTOINCREMENT, import_id INT NOT NULL, kind TEXT NOT NULL DEFAULT 'payment', paid_on TEXT, amount REAL NOT NULL DEFAULT 0, bank_id INT,
  pkr_debit REAL, rate REAL, bank_charges_pkr REAL NOT NULL DEFAULT 0, other_pkr REAL NOT NULL DEFAULT 0, reference TEXT, notes TEXT,
  is_void INT NOT NULL DEFAULT 0, void_reason TEXT, voided_by INT, voided_at TEXT, created_by INT, created_at TEXT);
CREATE TABLE imp_costs (id INTEGER PRIMARY KEY AUTOINCREMENT, import_id INT NOT NULL, cost_type_id INT, kind TEXT NOT NULL DEFAULT 'service', provider_id INT, bill_no TEXT, bill_date TEXT,
  currency TEXT NOT NULL DEFAULT 'PKR', est_amount REAL NOT NULL DEFAULT 0, amount REAL, fx_rate REAL, is_paid INT NOT NULL DEFAULT 0, paid_on TEXT, pay_ref TEXT, notes TEXT,
  is_void INT NOT NULL DEFAULT 0, void_reason TEXT, voided_by INT, voided_at TEXT, created_by INT, created_at TEXT);
CREATE TABLE imp_documents (id INTEGER PRIMARY KEY AUTOINCREMENT, import_id INT NOT NULL, doc_type_id INT, line_id INT, doc_no TEXT, version INT NOT NULL DEFAULT 1, original_name TEXT NOT NULL,
  storage_driver TEXT NOT NULL DEFAULT 'local', storage_key TEXT NOT NULL, mime_type TEXT, file_size INT, superseded_by INT, is_archived INT NOT NULL DEFAULT 0, archive_reason TEXT,
  archived_by INT, archived_at TEXT, notes TEXT, uploaded_by INT, uploaded_at TEXT);
CREATE TABLE imp_audit (id INTEGER PRIMARY KEY AUTOINCREMENT, import_id INT NOT NULL DEFAULT 0, user_id INT, user_name TEXT, action TEXT NOT NULL, detail TEXT, reason TEXT, created_at TEXT);
CREATE TABLE imp_opening_efs (id INTEGER PRIMARY KEY AUTOINCREMENT, gd_no TEXT NOT NULL DEFAULT '', gd_date TEXT, hs_code TEXT, product TEXT NOT NULL DEFAULT '', imported_kg REAL NOT NULL DEFAULT 0,
  valuation REAL NOT NULL DEFAULT 0, waste_pct REAL NOT NULL DEFAULT 5, va_pct REAL NOT NULL DEFAULT 0, exported_kg REAL NOT NULL DEFAULT 0, exported_value REAL NOT NULL DEFAULT 0,
  months INT NOT NULL DEFAULT 9, notes TEXT, created_by INT, created_at TEXT, updated_by INT, updated_at TEXT, currency TEXT NOT NULL DEFAULT 'USD', ext_date TEXT, ext_ref TEXT);
CREATE TABLE imp_settings (k TEXT PRIMARY KEY, v TEXT, updated_at TEXT);
INSERT INTO exp_masters (kind, label, sort_order, flags) VALUES ('imp_doc_type', 'Supplier Commercial Invoice', 10, NULL), ('imp_doc_type', 'BL', 20, NULL),
  ('imp_cost_type', 'Ocean Freight', 10, NULL), ('imp_cost_type', 'Clearing Agent Service', 20, NULL), ('imp_cost_type', 'Customs Duty & Taxes (via agent)', 30, '{"is_duty":1}'),
  ('shipping_line', 'COSCO', 10, NULL), ('container_type', '40''HC', 10, NULL), ('port_loading', 'KICT', 10, NULL);
INSERT INTO exp_providers (name) VALUES ('Sea Hawk Logistics'); INSERT INTO exp_provider_roles (provider_id, role) VALUES (1, 'freight_forwarder');
INSERT INTO exp_banks (bank_name, currency) VALUES ('Meezan Bank', 'USD');
INSERT INTO inv_parties (code, name, party_type) VALUES ('SUP-0001', 'Shaoxing Huajie Textiles', 'supplier');
INSERT INTO inv_materials (code, name, uom) VALUES ('MAT-0001', 'Polyester fiber 1.4D', 'KG'), ('FAB-0118', 'Poly-cotton 65/35 bleached 250 cm', 'MTR'), ('FAB-0121', 'Poly-cotton dyed navy', 'YDS');
INSERT INTO products (name) VALUES ('Bed Sheet Set 200 TC King');
INSERT INTO costing_lines (material_id) VALUES (1), (1), (2);
SQL;

/* the app's own functions, as small stand-ins on this database */
$bootstrap = <<<'PHP'
<?php
session_save_path(__DIR__ . '/../sess'); session_id('imptest'); if (session_status() === PHP_SESSION_NONE) @session_start();
$GLOBALS['config'] = ['upload_dir' => __DIR__ . '/../uploads', 'max_upload_mb' => 5];
$config = $GLOBALS['config'];
$GLOBALS['ROLE'] = getenv('ROLE') ?: 'admin';
$GLOBALS['PERMS'] = json_decode((string)getenv('PERMS'), true) ?: [];
function e($s){ return htmlspecialchars((string)$s, ENT_QUOTES); }
function require_login(){}
function csrf_token(){ return 'testtoken'; }
function csrf_field(){ return '<input type="hidden" name="_csrf" value="testtoken">'; }
function verify_csrf(){ if (($_POST['_csrf'] ?? '') !== 'testtoken') { http_response_code(419); exit('CSRF token mismatch.'); } }
function current_user(): ?array { return ['id' => 1, 'name' => 'Afnan', 'role' => $GLOBALS['ROLE']]; }
function is_admin(){ return $GLOBALS['ROLE'] === 'admin'; }
function is_colleague(){ return $GLOBALS['ROLE'] === 'colleague'; }
function is_staff(){ return $GLOBALS['ROLE'] === 'staff'; }
function is_production_staff(){ return $GLOBALS['ROLE'] === 'production_staff'; }
function zu_can($m, $a){ if (is_admin()) return true; return strpos($GLOBALS['PERMS'][$m] ?? '', $a) !== false; }
function money_fmt($a, string $c = 'USD'): string { return $c . ' ' . number_format((float)$a, 2); }
function num_fmt($n, int $d = 3): string { return number_format((float)$n, $d); }
function redirect($u){ echo "__REDIRECT__ $u\n__FLASH__" . ($_SESSION['flash'] ?? '') . "__ERROR__" . ($_SESSION['error'] ?? '') . "__END__"; unset($_SESSION['flash'], $_SESSION['error']); exit; }
function page_header($t){ echo "<html><title>" . e($t) . "</title>"; }
function page_footer(){ echo "</html>"; }
function flash(){ foreach (['flash', 'error'] as $k) if (!empty($_SESSION[$k])) { echo '<div class="alert ' . $k . '">' . e($_SESSION[$k]) . '</div>'; unset($_SESSION[$k]); } }
function fx_get_rates(): array { return ['PKR' => 1.0, 'USD' => 1 / 280]; }
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
$export = <<<'PHP'
<?php
function exp_ensure_schema(): void {}
function exp_masters(string $kind, bool $active = true): array { $s = db()->prepare("SELECT * FROM exp_masters WHERE kind=?" . ($active ? " AND is_active=1" : "") . " ORDER BY sort_order, label"); $s->execute([$kind]); return $s->fetchAll(); }
function exp_master_map(string $kind): array { $o = []; foreach (exp_masters($kind, false) as $r) $o[(int)$r['id']] = $r['label']; return $o; }
function exp_master_flag(?array $row, string $flag): bool { if (!$row || empty($row['flags'])) return false; $f = json_decode((string)$row['flags'], true); return is_array($f) && !empty($f[$flag]); }
function exp_providers(string $role = '', bool $active = true): array { $sql = "SELECT p.* FROM exp_providers p" . ($role !== '' ? " JOIN exp_provider_roles r ON r.provider_id=p.id AND r.role=?" : ""); $s = db()->prepare($sql); $s->execute($role !== '' ? [$role] : []); return $s->fetchAll(); }
function exp_provider_map(): array { $o = []; foreach (db()->query("SELECT id, name FROM exp_providers")->fetchAll() as $r) $o[(int)$r['id']] = $r['name']; return $o; }
function exp_banks(bool $active = true): array { return db()->query("SELECT * FROM exp_banks")->fetchAll(); }
function exp_page_css(): string { return '<style>.xcard{}</style>'; }
PHP;
$inventory = <<<'PHP'
<?php
function inv_parties(string $type = '', bool $active = true): array { return db()->query("SELECT * FROM inv_parties WHERE is_active=1 ORDER BY name")->fetchAll(); }
function inv_materials(bool $active = true): array { return db()->query("SELECT * FROM inv_materials")->fetchAll(); }
function inv_perm(string $what): bool { return in_array($GLOBALS['ROLE'], ['admin'], true) || strpos((string)getenv('INVPERM'), $what) !== false; }
function inv_party_create(string $name, string $type): array {
    $s = db()->prepare("SELECT id, name FROM inv_parties WHERE LOWER(name)=LOWER(?)"); $s->execute([$name]);
    if ($e = $s->fetch()) return ['ok' => true, 'id' => (int)$e['id'], 'name' => $e['name'], 'error' => ''];
    db()->prepare("INSERT INTO inv_parties (code, name, party_type) VALUES (?,?,?)")->execute(['SUP-' . rand(1000, 9999), $name, $type]);
    return ['ok' => true, 'id' => (int)db()->lastInsertId(), 'name' => $name, 'error' => ''];
}
PHP;
file_put_contents($work . '/includes/bootstrap.php', $bootstrap);
file_put_contents($work . '/includes/export.php', $export);
file_put_contents($work . '/includes/inventory.php', $inventory);
$pdo = new PDO('sqlite:' . $work . '/db.sqlite'); $pdo->exec($schema);
$pdo->prepare("INSERT INTO users (id, name, role, password_hash) VALUES (1,'Afnan','admin',?), (2,'Sara','admin',?), (3,'Ali','colleague',?)")
    ->execute([password_hash('admin-one', PASSWORD_DEFAULT), password_hash('admin-two', PASSWORD_DEFAULT), password_hash('colleague-pw', PASSWORD_DEFAULT)]);
$pdo = null;

require $work . '/includes/bootstrap.php';
require $work . '/includes/imports.php';
session_write_close();   /* the pages run as their own processes and share this session file */
$q = function (string $sql, array $a = []) { $s = db()->prepare($sql); $s->execute($a); return $s->fetchAll(); };

/* a page as its own PHP process */
function page(string $file, array $get = [], ?array $post = null, string $env = '', ?array $files = null): string {
    global $work;
    $cmd = 'cd ' . escapeshellarg($work) . ' && ' . $env . ' QS=' . escapeshellarg(http_build_query($get))
         . ' FORM=' . escapeshellarg($post === null ? '' : http_build_query($post)) . ' FILES=' . escapeshellarg($files ? json_encode($files) : '')
         . ' ' . escapeshellarg(PHP_BINARY) . ' -d display_errors=1 -d error_reporting=E_ALL -r ' . escapeshellarg(
             '$_GET=[];parse_str((string)getenv("QS"),$_GET);$_POST=[];if(getenv("FORM")!==""){parse_str((string)getenv("FORM"),$_POST);}'
           . '$_FILES=getenv("FILES")!==""?json_decode((string)getenv("FILES"),true):[];'
           . '$_SERVER["REQUEST_METHOD"]=getenv("FORM")!==""?"POST":"GET";require ' . var_export($file, true) . ';') . ' 2>&1';
    return (string)shell_exec($cmd);
}
function said(string $out, string $what = 'flash'): string {
    $re = $what === 'flash' ? '~__FLASH__(.*?)__ERROR__~s' : '~__ERROR__(.*?)__END__~s';
    return preg_match($re, $out, $m) ? $m[1] : '';
}
function noWarn(string $html): bool { return !preg_match('~(Warning|Notice|Deprecated|Fatal error|Parse error|Uncaught)~', $html); }
$T = ['_csrf' => 'testtoken'];

/* =================================================================== 1 */
head('1. The figures');
$L = [['bill_qty' => 20000, 'rate' => 1.75, 'net_kg' => 20000, 'gross_kg' => 20260, 'packages' => 80, 'bill_unit' => 'kg', 'description' => 'Fiber'],
      ['bill_qty' => 2000, 'rate' => 1.10, 'net_kg' => 640, 'gross_kg' => 660, 'packages' => 20, 'bill_unit' => 'm', 'description' => 'PVC fabric']];
$t = imp_totals($L);
ok($t['value'] == 37200 && $t['kg'] == 20640 && $t['by_unit'] === ['kg' => 20000.0, 'm' => 2000.0], 'one total per unit — 20,000 kg and 2,000 m are never added; net kg kept for both', $t);
ok(imp_by_unit_text($t['by_unit']) === '20,000 kg · 2,000 m', 'and said as "20,000 kg · 2,000 m"', imp_by_unit_text($t['by_unit']));
$impA = ['id' => 999, 'currency' => 'USD', 'credit' => 1, 'credit_days' => 30, 'maturity_basis' => 'bl', 'fixed_due_date' => null, 'due_override' => null, 'due_override_reason' => null,
         'manual_status' => null, 'lc' => 0, 'lc_expiry' => null, 'efs_months' => 9, 'alloc_basis' => 'kg'];
$pays = [['kind' => 'payment', 'amount' => 10000, 'is_void' => 0, 'bank_charges_pkr' => 2500, 'other_pkr' => 0, 'rate' => 280],
         ['kind' => 'credit_note', 'amount' => 200, 'is_void' => 0, 'bank_charges_pkr' => 0, 'other_pkr' => 0, 'rate' => null],
         ['kind' => 'refund', 'amount' => 50, 'is_void' => 0, 'bank_charges_pkr' => 0, 'other_pkr' => 0, 'rate' => null],
         ['kind' => 'payment', 'amount' => 999, 'is_void' => 1, 'bank_charges_pkr' => 0, 'other_pkr' => 0, 'rate' => 280]];
$s = imp_paid_summary($impA, $L, $pays);
ok($s['settled'] == 10150 && $s['balance'] == 27050 && $s['status'] === 'PART PAID' && $s['bank_charges_pkr'] == 2500,
   'paid 10,000 + credit note 200 − refund 50 = 10,150 settled; the voided 999 is ignored; payable 27,050', $s);
$m = imp_maturity($impA, ['bl_date' => '2026-10-02']);
ok($m['date'] === '2026-11-01' && str_contains($m['from'], 'BL date'), 'credit 30 days from the BL date 02 Oct → 01 Nov', $m);
$m = imp_maturity(['maturity_basis' => 'arrival'] + $impA, ['eta' => '2026-10-24']);
ok($m['date'] === '2026-11-23' && $m['estimated'] === true, 'from arrival: until arrival is typed it counts from the ETA, and says it is an estimate', $m);
$m = imp_maturity(['maturity_basis' => 'fixed', 'fixed_due_date' => '2026-12-15'] + $impA, []);
ok($m['date'] === '2026-12-15', 'a fixed agreed date is used as it is', $m);
$m = imp_maturity(['due_override' => '2026-11-10', 'due_override_reason' => 'supplier agreed'] + $impA, ['bl_date' => '2026-10-02']);
ok($m['date'] === '2026-11-10' && str_contains($m['from'], 'supplier agreed'), 'a date set by hand wins, with its reason', $m);
ok(imp_maturity(['credit' => 0] + $impA, ['bl_date' => '2026-10-02'])['date'] === null, 'no credit period, no due date');
$st = fn(array $lg) => imp_stage($impA, $lg)['key'];
ok($st([]) === 'contract' && $st(['booking_no' => 'X']) === 'booked' && $st(['booking_no' => 'X', 'bl_date' => '2026-10-02']) === 'sailing'
   && $st(['arrival' => '2026-10-20']) === 'arrived' && $st(['arrival' => '1', 'gd_filed' => '1']) === 'customs' && $st(['customs_release' => '1']) === 'released'
   && $st(['port_gate_out' => '1']) === 'left_port' && $st(['factory_gate_in' => '1']) === 'at_factory', 'the stage is read from the dates typed — nobody keeps a status by hand');
ok(imp_stage(['manual_status' => 'closed'] + $impA, [])['label'] === 'Closed', 'closed is the one kept by hand');
$today = imp_today();
$al = imp_alerts($impA, ['arrival' => date('Y-m-d', strtotime('-12 days')), 'free_days' => 14]);
ok(str_contains($al[0][1] ?? '', 'Last free day') && str_contains($al[0][1], '2 days left'), 'free time with 2 days left is an alert', $al);
$al = imp_alerts($impA, ['arrival' => date('Y-m-d', strtotime('-20 days')), 'free_days' => 14]);
ok(str_contains($al[0][1] ?? '', 'demurrage running'), 'free time over: demurrage running', $al);
ok(imp_alerts($impA, ['arrival' => date('Y-m-d', strtotime('-20 days')), 'free_days' => 14, 'empty_return' => $today]) === [], 'no free-time alert once the empty is back');
$al = imp_alerts($impA, ['bl_date' => date('Y-m-d', strtotime('-35 days'))], ['balance' => 100]);
ok(str_contains($al[0][1] ?? '', 'overdue'), 'a balance past its due date is overdue', $al);
/* landed cost */
$costs = [['currency' => 'USD', 'est_amount' => 1800, 'amount' => 1950, 'fx_rate' => 278, 'is_void' => 0],
          ['currency' => 'PKR', 'est_amount' => 45000, 'amount' => null, 'fx_rate' => 1, 'is_void' => 0],
          ['currency' => 'PKR', 'est_amount' => 0, 'amount' => 99999, 'fx_rate' => 1, 'is_void' => 1]];
$ld = imp_landed($impA, $L, $costs, $pays);
$sumShares = round(array_sum(array_column($ld['rows'], 'share')), 2);
ok($ld['costs_pkr'] == round(1950 * 278 + 45000 + 2500, 2) && $sumShares == $ld['costs_pkr'] && $ld['provisional'] === true,
   'costs = freight bill + agent estimate + bank charges (the void one out); the shares add up exactly; provisional while a bill is an estimate', [$ld['costs_pkr'], $sumShares]);
ok(abs($ld['rate']['rate'] - 280) < 0.0001 && $ld['rows'][0]['goods_pkr'] == 35000 * 280, 'goods in PKR at the rate the bank paid', $ld['rate']);
$byV = imp_landed($impA, $L, $costs, $pays, 'value');
ok(abs($byV['rows'][0]['share'] / $byV['costs_pkr'] - 35000 / 37200) < 0.001 && abs($ld['rows'][0]['share'] / $ld['costs_pkr'] - 20000 / 20640) < 0.001,
   'shared by value, or by net kg', [$byV['rows'][0]['share'], $ld['rows'][0]['share']]);
$odd = imp_landed($impA, array_fill(0, 3, $L[0]), [['currency' => 'PKR', 'est_amount' => 100, 'amount' => 100, 'fx_rate' => 1, 'is_void' => 0]], []);
ok(array_column($odd['rows'], 'share') === [33.33, 33.33, 33.34], '100 over three lines: 33.33 + 33.33 + 33.34 — the last line takes the rounding', array_column($odd['rows'], 'share'));
/* EFS */
$e = imp_efs_line(['bill_qty' => 100, 'rate' => 3.5, 'net_kg' => 100, 'efs_valuation' => null, 'efs_waste_pct' => null, 'efs_va_pct' => null]);
ok($e['need_kg'] == 95 && $e['need_value'] == 350 && $e['from_invoice'] === true, "afnan's rule: 100 kg × USD 3.50 → export 95 kg and USD 350 (valuation from the invoice until typed)", $e);
$e = imp_efs_line(['bill_qty' => 100, 'rate' => 3.0, 'net_kg' => 100, 'efs_valuation' => 3.5, 'efs_waste_pct' => 5, 'efs_va_pct' => 10]);
ok($e['need_value'] == 385 && $e['imported'] == 350, 'the customs valuation when typed; 10% value addition → USD 385; the value is not reduced by the wastage', $e);
$d = imp_efs_deadline($impA, ['customs_release' => '2026-10-27'], [['gd_date' => '2026-10-20']]);
ok($d['date'] === '2027-07-27' && str_contains($d['from'], 'customs release'), 'the EFS clock: 9 months from customs release (it wins over the GD date)', $d);
ok(imp_efs_deadline($impA, [], [['gd_date' => '2026-10-20']])['date'] === '2027-07-20', 'or from the GD date until release is typed');
$oc = imp_opening_calc(['imported_kg' => 24000, 'valuation' => 1.62, 'waste_pct' => 5, 'va_pct' => 0, 'exported_kg' => 15200, 'exported_value' => 26100, 'gd_date' => '2026-03-14', 'months' => 9]);
ok($oc['left_kg'] == 7600 && $oc['left_value'] == 12780 && $oc['deadline'] === '2026-12-14', 'opening: 24,000 kg at 1.62, 15,200 kg / 26,100 exported → 7,600 kg and 12,780 still owed, by 14 Dec', $oc);

/* =================================================================== 2 */
head('2. Who may');
// even with the Imports box ticked by mistake, staff stay out
$out = page('imp_register.php', [], null, 'ROLE=staff PERMS=' . escapeshellarg(json_encode(['imp' => 'vaed'])));
ok(str_contains($out, 'do not have permission'), 'staff are kept out of imports', substr($out, 0, 120));
ok(str_contains(page('imp_register.php', [], null, 'ROLE=production_staff PERMS=' . escapeshellarg(json_encode(['imp' => 'vaed']))), 'do not have permission'), 'and production staff');
ok(str_contains(page('imp_register.php', [], null, 'ROLE=colleague'), 'do not have permission'), 'a colleague without the Imports permission too');
ok(str_contains(page('imp_register.php', [], null, 'ROLE=colleague PERMS=\'{"imp":"v"}\''), 'Import Register'), 'a colleague with it ticked sees the register');
ok(str_contains(page('imp_new.php', [], ['supplier_party_id' => '1']), 'CSRF'), 'a save without the CSRF token is refused');

/* =================================================================== 3 */
head('3. Create and the overview');
$out = page('imp_new.php', [], $T + ['supplier_party_id' => '-1', 'supplier_new' => 'Zhejiang Hengyi Polymer Co.', 'contract_no' => 'HY-ZAS-2609', 'currency' => 'USD', 'import_type' => 'efs']);
$imp = $q("SELECT * FROM imp_imports ORDER BY id DESC LIMIT 1")[0] ?? [];
ok(($imp['ref'] ?? '') === 'IMP-' . date('y') . '-0001' && $imp['import_type'] === 'efs' && str_contains(said($out), 'created'), 'created as IMP-' . date('y') . '-0001, EFS', [said($out), said($out, 'error')]);
ok((int)$q("SELECT COUNT(*) c FROM inv_parties WHERE name='Zhejiang Hengyi Polymer Co.'")[0]['c'] === 1 && (int)$imp['supplier_party_id'] > 0,
   'the new supplier went into the one Parties list (the one Gate Inward uses)');
$id = (int)$imp['id'];
$out = page('imp_new.php', [], $T + ['supplier_party_id' => '1', 'currency' => 'USD', 'import_type' => 'ordinary', 'is_opening' => '1', 'already_in_stock' => '1']);
$imp2 = $q("SELECT * FROM imp_imports ORDER BY id DESC LIMIT 1")[0];
ok($imp2['ref'] === 'IMP-' . date('y') . '-0002' && (int)$imp2['is_opening'] === 1 && (int)$imp2['already_in_stock'] === 1, 'the next is -0002; an opening import "already in stock" is kept as such', $imp2);
$iv = (int)$q("SELECT id FROM imp_invoices WHERE import_id=?", [$id])[0]['id'];
$ov = $T + ['action' => 'save', 'import_id' => $id, 'supplier_party_id' => $imp['supplier_party_id'], 'currency' => 'USD', 'import_type' => 'efs', 'pay_method' => 'TT',
            'advance_pct' => '30', 'credit' => '1', 'credit_days' => '30', 'maturity_basis' => 'bl', 'incoterm' => 'CFR', 'contract_date' => '2026-09-05',
            'lc_expiry' => 'not a date', 'iv_id' => [$iv], 'iv_no' => ['HY26-0915'], 'iv_date' => ['2026-09-28'], 'iv_gd' => [''], 'iv_gd_date' => ['']];
$out = page('imp_view.php', ['id' => $id], $ov);
ok(said($out) === 'Saved.', 'saved — a garbage LC date is not checked while LC is switched off', said($out, 'error'));
$four = $ov; $four['iv_id'] = [$iv, 0, 0, 0]; $four['iv_no'] = ['A', 'B', 'C', 'D']; $four['iv_date'] = ['', '', '', '']; $four['iv_gd'] = ['', '', '', '']; $four['iv_gd_date'] = ['', '', '', ''];
ok(str_contains(said(page('imp_view.php', ['id' => $id], $four), 'error'), 'Up to 3'), 'a fourth supplier invoice / GD is refused');
$three = $four; foreach (['iv_id', 'iv_no', 'iv_date', 'iv_gd', 'iv_gd_date'] as $k) array_pop($three[$k]);
page('imp_view.php', ['id' => $id], $three);
ok(count(imp_invoices($id)) === 3 && imp_invoices($id)[0]['id'] == $iv, 'three are kept, the first keeps its id');
page('imp_view.php', ['id' => $id], $ov);
ok(count(imp_invoices($id)) === 1, 'and taking two off leaves one');
ok(str_contains(said(page('imp_view.php', ['id' => $id], ['maturity_basis' => 'fixed', 'fixed_due_date' => ''] + $ov), 'error'), 'fixed due date'), 'a fixed due date must be given when chosen');
ok(str_contains(said(page('imp_view.php', ['id' => $id], ['due_override' => '2026-11-10', 'due_override_reason' => ''] + $ov), 'error'), 'needs a reason'), 'a due date set by hand needs a reason');
ok(str_contains(said(page('imp_view.php', ['id' => $id], ['advance_pct' => '130'] + $ov), 'error'), 'between 0 and 100'), 'advance over 100% is refused');

/* =================================================================== 4 */
head('4. Invoice lines, packing, logistics');
$ln = $T + ['import_id' => $id, 'line_id' => [0, 0, 0], 'category' => ['Fiber', 'Fiber', 'PVC'], 'description' => ['Fiber hollow', 'Fiber solid', 'PVC coated fabric'],
            'hs_code' => ['5503.2000', '5503.2000', ''], 'supplier_ref' => ['', '', ''], 'bill_unit' => ['kg', 'kg', 'm'], 'bill_qty' => ['20000', '5000', '2000'],
            'rate' => ['1.75', '1.725', '1.10'], 'net_kg' => ['20000', '', '640'], 'gross_kg' => ['20260', '5065', '660'], 'packages' => ['80', '20', '20'],
            'specs_json' => ['{"denier":"7D","cut":"64 mm"}', '', '{"thickness":"0.35 mm"}'], 'invoice_id' => [$iv, $iv, $iv], 'item_key' => ['m1', '', '']];
$out = page('imp_invoice.php', ['id' => $id], $ln);
$lines = imp_lines($id);
ok(str_contains(said($out), '3 lines') && count($lines) === 3, 'three lines saved', [said($out), said($out, 'error')]);
ok((float)$lines[1]['net_kg'] === 5000.0, 'a line billed in kg with no net kg takes it from the quantity');
ok(json_decode($lines[0]['specs'], true) === ['denier' => '7D', 'cut' => '64 mm'] && (int)$lines[0]['material_id'] === 1, 'the specification and the stock-item link are kept');
ok(imp_totals($lines)['value'] == 45825.0, 'invoice USD 45,825.00');
$ids = array_map(fn($l) => (int)$l['id'], $lines);
$ln2 = $ln; $ln2['line_id'] = $ids; $ln2['rate'][0] = '1.80';
page('imp_invoice.php', ['id' => $id], $ln2);
ok(array_map(fn($l) => (int)$l['id'], imp_lines($id)) === $ids && (float)imp_lines($id)[0]['rate'] === 1.8, 'saving again keeps every line\'s id — packing, customs and EFS point at them');
$bad = $ln2; $bad['net_kg'][2] = '700';
ok(str_contains(said(page('imp_invoice.php', ['id' => $id], $bad), 'error'), 'net kg cannot be more than gross'), 'net kg over gross kg is refused');
$bad = $ln2; $bad['bill_unit'][0] = 'litre';
ok(str_contains(said(page('imp_invoice.php', ['id' => $id], $bad), 'error'), 'billing unit'), 'a unit not on the list is refused');
/* packing */
$pk = fn(array $o) => page('imp_packing.php', ['id' => $id], array_merge($T + ['action' => 'add', 'import_id' => $id, 'line_id' => $ids[0], 'pkg_type' => 'Bale', 'serial_from' => '1', 'serial_to' => '40', 'net_kg' => '10000', 'gross_kg' => '10130', 'container_id' => '0'], $o));
ok(str_contains(said($pk([])), 'Bale 1–40'), 'Bale 1–40 packed');
ok(str_contains(said($pk(['serial_from' => '30', 'serial_to' => '50']), 'error'), 'once per kind of package'), 'Bale 30–50 refused: those numbers are taken');
ok(str_contains(said($pk(['pkg_type' => 'Roll', 'serial_from' => '1', 'serial_to' => '5', 'line_id' => $ids[2], 'net_kg' => '160', 'gross_kg' => '165'])), 'Roll 1–5'), 'but Roll 1–5 is fine — a different kind');
$noLine = $ln2; foreach (['line_id', 'category', 'description', 'hs_code', 'supplier_ref', 'bill_unit', 'bill_qty', 'rate', 'net_kg', 'gross_kg', 'packages', 'specs_json', 'invoice_id', 'item_key'] as $k) array_shift($noLine[$k]);
ok(str_contains(said(page('imp_invoice.php', ['id' => $id], $noLine), 'error'), 'has packing rows'), 'a line with packing rows cannot be removed from the invoice');
$html = page('imp_packing.php', ['id' => $id]);
ok(noWarn($html) && str_contains($html, '40 pkgs short') && str_contains($html, 'not packed yet'), 'the comparison shows a short line and an unpacked one', substr(strip_tags($html), 0, 0));
/* logistics */
$lg = $T + ['action' => 'save', 'import_id' => $id, 'booking_no' => 'NGB2609-7713', 'bl_no' => 'COSU6402291180', 'bl_date' => '2026-10-02', 'etd' => '2026-10-02', 'eta' => '2026-10-24', 'free_days' => '14', 'forwarder_id' => '1'];
ok(said(page('imp_logistics.php', ['id' => $id], $lg)) === 'Logistics saved.', 'logistics saved');
ok(imp_stage($q("SELECT * FROM imp_imports WHERE id=?", [$id])[0])['key'] === 'sailing' && imp_maturity($q("SELECT * FROM imp_imports WHERE id=?", [$id])[0])['date'] === '2026-11-01',
   'the BL makes it "Sailing", and the balance falls due 01 Nov (BL + 30)');
ok(str_contains(said(page('imp_logistics.php', ['id' => $id], ['port_gate_out' => '2026-11-05', 'factory_gate_in' => '2026-11-03'] + $lg), 'error'), 'Factory gate in cannot be before port gate out'), 'dates in the wrong order are refused');
page('imp_logistics.php', ['id' => $id], $T + ['action' => 'add_container', 'import_id' => $id, 'container_no' => 'csnu 718220-4']);
ok(($q("SELECT container_no FROM imp_containers WHERE import_id=?", [$id])[0]['container_no'] ?? '') === 'CSNU 718220-4', 'a container, in capitals');
ok(str_contains(said(page('imp_logistics.php', ['id' => $id], $T + ['action' => 'add_container', 'import_id' => $id, 'container_no' => 'CSNU 718220-4']), 'error'), 'already on this import'), 'and not twice');

/* =================================================================== 5 */
head('5. Payments and costs');
$py = $T + ['action' => 'add', 'import_id' => $id, 'kind' => 'payment', 'paid_on' => '2026-09-24', 'amount' => '13087.50', 'pkr_debit' => '3632000', 'rate' => '277.55', 'bank_charges_pkr' => '2500'];
$err = said(page('imp_payments.php', ['id' => $id], $py), 'error');
ok(str_contains($err, 'does not add up') && str_contains($err, '2,935.63'), 'a bank debit that does not add up is refused — PKR 2,935.63 allocated too much', $err);
ok(str_contains(said(page('imp_payments.php', ['id' => $id], ['pkr_debit' => '3634936'] + $py)), 'saved'), 'split right (13,087.50 × 277.55 + 2,500 = 3,634,936): saved');
page('imp_payments.php', ['id' => $id], $T + ['action' => 'add', 'import_id' => $id, 'kind' => 'credit_note', 'paid_on' => '2026-10-10', 'amount' => '100']);
$sumP = imp_paid_summary($q("SELECT * FROM imp_imports WHERE id=?", [$id])[0], imp_lines($id));
ok($sumP['settled'] == 13187.5 && abs($sumP['balance'] - (imp_totals(imp_lines($id))['value'] - 13187.5)) < 0.005, 'a credit note lowers the balance like a payment', $sumP);
$pid = (int)$q("SELECT id FROM imp_payments WHERE kind='credit_note'")[0]['id'];
ok(str_contains(page('imp_payments.php', ['id' => $id], $T + ['action' => 'void', 'import_id' => $id, 'payment_id' => $pid, 'void_reason' => 'x'], 'ROLE=colleague PERMS=\'{"imp":"v","imppay":"vc"}\''), 'do not have permission'),
   'a void needs the Reverse right');
ok(str_contains(said(page('imp_payments.php', ['id' => $id], $T + ['action' => 'void', 'import_id' => $id, 'payment_id' => $pid, 'void_reason' => '']), 'error'), 'reason'), 'and a reason');
page('imp_payments.php', ['id' => $id], $T + ['action' => 'void', 'import_id' => $id, 'payment_id' => $pid, 'void_reason' => 'raised in error']);
ok((int)$q("SELECT is_void FROM imp_payments WHERE id=?", [$pid])[0]['is_void'] === 1 && (int)$q("SELECT COUNT(*) c FROM imp_payments")[0]['c'] === 2, 'voided, never deleted');
$types = []; foreach ($q("SELECT id, label FROM exp_masters WHERE kind='imp_cost_type'") as $r) $types[$r['label']] = $r['id'];
$co = $T + ['action' => 'add', 'import_id' => $id, 'cost_type_id' => $types['Ocean Freight'], 'currency' => 'USD', 'est_amount' => '1800', 'amount' => '1950', 'fx_rate' => ''];
ok(str_contains(said(page('imp_costs.php', ['id' => $id], $co), 'error'), 'exchange rate'), 'a foreign-currency cost needs its rate');
page('imp_costs.php', ['id' => $id], ['fx_rate' => '278.1'] + $co);
page('imp_costs.php', ['id' => $id], $T + ['action' => 'add', 'import_id' => $id, 'cost_type_id' => $types['Customs Duty & Taxes (via agent)'], 'currency' => 'PKR', 'est_amount' => '0', 'amount' => '120000']);
page('imp_costs.php', ['id' => $id], $T + ['action' => 'add', 'import_id' => $id, 'cost_type_id' => $types['Clearing Agent Service'], 'currency' => 'PKR', 'est_amount' => '45000', 'amount' => '']);
$kinds = array_column($q("SELECT kind FROM imp_costs WHERE import_id=? ORDER BY id", [$id]), 'kind');
ok($kinds === ['service', 'duty', 'service'], 'duty paid through the agent is kept apart from service charges', $kinds);
$html = page('imp_costs.php', ['id' => $id]);
ok(noWarn($html) && str_contains($html, 'provisional') && str_contains($html, '709,795.00'), 'landed cost: provisional, costs PKR 709,795.00 (freight 542,295 + duty 120,000 + agent 45,000 + bank 2,500)');
$cid = (int)$q("SELECT id FROM imp_costs WHERE cost_type_id=?", [$types['Clearing Agent Service']])[0]['id'];
page('imp_costs.php', ['id' => $id], $T + ['action' => 'bill', 'import_id' => $id, 'cost_id' => $cid, 'amount' => '52000', 'is_paid' => '1']);
ok(!imp_landed($q("SELECT * FROM imp_imports WHERE id=?", [$id])[0], imp_lines($id))['provisional'], 'the late agent bill in: landed cost is no longer provisional, the quantities untouched');

/* =================================================================== 6 */
head('6. Documents');
$docType = (int)$q("SELECT id FROM exp_masters WHERE label='Supplier Commercial Invoice'")[0]['id'];
$up = function (string $content) use ($work, $id, $docType, $T) {
    $tmp = $work . '/up_' . md5($content . microtime()) . '.txt'; file_put_contents($tmp, $content);
    return page('imp_documents.php', ['id' => $id], $T + ['action' => 'upload', 'import_id' => $id, 'doc_type_id' => $docType, 'line_id' => '0'], '',
                ['file' => ['name' => 'invoice.pdf', 'type' => 'application/pdf', 'tmp_name' => $tmp, 'error' => 0, 'size' => strlen($content)]]);
};
ok(str_contains(said($up('version one')), 'version 1'), 'uploaded as version 1');
ok(str_contains(said($up('version two')), 'Version 1 is kept'), 'the same type again is version 2, and version 1 is kept');
$docs = $q("SELECT * FROM imp_documents ORDER BY id");
ok(count($docs) === 2 && (int)$docs[0]['superseded_by'] === (int)$docs[1]['id'] && is_file($work . '/uploads/' . $docs[1]['storage_key']), 'V1 marked superseded; the file is on disk under its own random name', $docs[1]['storage_key'] ?? null);
$dl = page('imp_doc_file.php', ['doc' => $docs[1]['id']]);
ok(str_contains($dl, 'version two'), 'it downloads through the page (no public link)');
ok(str_contains(page('imp_doc_file.php', ['doc' => $docs[1]['id']], null, 'ROLE=colleague PERMS=\'{"imp":"v"}\''), 'do not have permission'), 'not without the Documents permission');
$del = $T + ['action' => 'delete', 'import_id' => $id, 'doc_id' => $docs[1]['id'], 'reason' => 'wrong file'];
ok(str_contains(said(page('imp_documents.php', ['id' => $id], ['admin_password' => 'colleague-pw'] + $del), 'error'), 'not an admin password'), "a colleague's password does not delete");
$out = page('imp_documents.php', ['id' => $id], ['admin_password' => 'admin-two'] + $del);
ok(str_contains(said($out), 'Deleted') && !is_file($work . '/uploads/' . $docs[1]['storage_key']) && $q("SELECT superseded_by FROM imp_documents WHERE id=?", [$docs[0]['id']])[0]['superseded_by'] === null,
   "another admin's password deletes it, the file goes, and V1 is current again", said($out, 'error'));
for ($i = 0; $i < 5; $i++) page('imp_documents.php', ['id' => $id], ['admin_password' => 'nope', 'doc_id' => $docs[0]['id']] + $del);
ok(str_contains(said(page('imp_documents.php', ['id' => $id], ['admin_password' => 'admin-one', 'doc_id' => $docs[0]['id']] + $del), 'error'), 'Too many wrong passwords'), 'five wrong passwords lock it, even for the right one');

/* =================================================================== 7 */
head('7. EFS tab, opening balances, timeline, the register');
$efs = $T + ['import_id' => $id, 'efs_months' => '9', 'val' => [$ids[0] => '1.75'], 'waste' => [$ids[0] => ''], 'va' => [$ids[2] => '10']];
ok(said(page('imp_efs.php', ['id' => $id], $efs)) === 'EFS figures saved.', 'EFS figures saved');
$l0 = imp_lines($id)[0]; $l2 = imp_lines($id)[2];
ok((float)$l0['efs_valuation'] === 1.75 && $l0['efs_waste_pct'] === null && imp_efs_line($l2)['need_value'] == 2420.0, 'valuation typed for one line, wastage left at 5%, 10% value addition on the PVC line → USD 2,420', imp_efs_line($l2));
ok(str_contains(said(page('imp_efs.php', ['id' => $id], ['waste' => [$ids[0] => '120']] + $efs), 'error'), 'wastage 0–100'), 'a wastage over 100% is refused');
$op = $T + ['action' => 'paste', 'paste' => "GD no.\tGD date\nKAPW-HC-88120\t14/03/2026\t5503.2000\tPolyester fiber 1.4D\t24000\t1.62\t5\t0\t15200\t26100\t9\nKAPW-HC-90455\t2026-06-02\t3921.1300\tPU foam\t3200\t2.9\t\t0\t900\t2750\t"];
ok(str_contains(said(page('imp_opening.php', [], $op)), '2 rows added'), 'two opening rows pasted from Excel (the header row skipped)');
$or = $q("SELECT * FROM imp_opening_efs ORDER BY id");
ok($or[0]['gd_date'] === '2026-03-14' && (float)$or[1]['waste_pct'] === 5.0 && (int)$or[1]['months'] === 9, 'an Excel date 14/03/2026 is read; blank wastage is 5%, blank months 9', $or);
$up1 = $T + ['action' => 'save', 'row' => [$or[0]['id'] => array_intersect_key($or[0], array_flip(['gd_no', 'gd_date', 'hs_code', 'product', 'imported_kg', 'valuation', 'waste_pct', 'va_pct', 'exported_kg', 'exported_value', 'months']))]];
$up1['row'][$or[0]['id']]['exported_kg'] = '15500';
page('imp_opening.php', [], $up1);
ok((float)$q("SELECT exported_kg FROM imp_opening_efs WHERE id=?", [$or[0]['id']])[0]['exported_kg'] === 15500.0, 'freely edited before locking');
ok(str_contains(said(page('imp_opening.php', [], $T + ['action' => 'lock'], 'ROLE=colleague PERMS=\'{"impefs":"vcud"}\''), 'error'), 'Only an admin'), 'only an admin can lock');
page('imp_opening.php', [], $T + ['action' => 'lock']);
ok(str_contains(said(page('imp_opening.php', [], $up1), 'error'), 'locked'), 'after locking, a direct edit is refused');
$adj = $T + ['action' => 'adjust', 'row_id' => $or[0]['id'], 'field' => 'exported_kg', 'value' => '16000', 'reason' => ''];
ok(str_contains(said(page('imp_opening.php', [], $adj), 'error'), 'needs a reason'), 'an adjustment needs a reason');
page('imp_opening.php', [], ['reason' => 'March export GD found'] + $adj);
ok((float)$q("SELECT exported_kg FROM imp_opening_efs WHERE id=?", [$or[0]['id']])[0]['exported_kg'] === 16000.0
   && (int)$q("SELECT COUNT(*) c FROM imp_audit WHERE action='Opening balance adjusted' AND reason='March export GD found'")[0]['c'] === 1, 'adjusted, and the reason is in the history');
$tl = page('imp_timeline.php', ['id' => $id]);
ok(noWarn($tl) && str_contains($tl, 'Payment voided') && str_contains($tl, 'raised in error') && str_contains($tl, 'Document deleted'), 'the timeline has the void with its reason and the delete');
$reg = page('imp_register.php');
ok(noWarn($reg) && str_contains($reg, 'IMP-' . date('y') . '-0001') && str_contains($reg, 'IMP-' . date('y') . '-0002'), 'the register lists both imports');
ok(str_contains(page('imp_register.php', ['q' => 'hengyi']), '-0001') && !str_contains(page('imp_register.php', ['q' => 'hengyi']), '-0002'), 'and finds by supplier');
foreach (['imp_view.php', 'imp_invoice.php', 'imp_packing.php', 'imp_logistics.php', 'imp_payments.php', 'imp_costs.php', 'imp_documents.php', 'imp_efs.php', 'imp_timeline.php'] as $pg) {
    $h = page($pg, ['id' => $id]);
    ok(noWarn($h) && str_contains($h, 'Import IMP-'), $pg . ' renders with no PHP warning', preg_match('~.{0,160}(Warning|Notice|Fatal error|Uncaught).{0,200}~s', $h, $mm) ? $mm[0] : substr($h, 0, 200));
}
ok(str_contains(page('imp_phase.php', ['id' => $id, 'tab' => 'gatein']), '__REDIRECT__ imp_gatein.php?id=' . $id) && str_contains(page('imp_phase.php', ['id' => $id]), '__REDIRECT__ imp_customs.php?id=' . $id), 'an old Phase 1 tab link forwards to the real CUSTOMS / GATE IN page');
$gi2 = page('imp_gatein.php', ['id' => $imp2['id']]);
ok(noWarn($gi2) && str_contains($gi2, 'already in stock') && !str_contains($gi2, 'Receive into stock →'), 'Gate In refuses to receive an opening import that is already in stock', substr(strip_tags($gi2), 0, 300));
/* delete: never with money on it */
ok(str_contains(said(page('imp_view.php', ['id' => $id], $T + ['action' => 'delete', 'import_id' => $id, 'confirm_ref' => $imp['ref']]), 'error'), 'payments'), 'an import with payments on it cannot be deleted');
$out = page('imp_view.php', ['id' => $imp2['id']], $T + ['action' => 'delete', 'import_id' => $imp2['id'], 'confirm_ref' => $imp2['ref']]);
ok(str_contains(said($out), 'deleted') && !$q("SELECT id FROM imp_imports WHERE id=?", [$imp2['id']]), 'an empty one can be, typing its reference');


/* =================================================================== 9 */
head('9. Phase 2 — items, the stock unit, customs, receiving');
/* a fresh import: three lines billed in kg, m and yd, paid at a known rate */
db()->exec("INSERT INTO imp_imports (ref, supplier_party_id, supplier_name, currency, import_type, alloc_basis) VALUES ('IMP-P2-0001', 1, 'Shaoxing Huajie Textiles', 'USD', 'efs', 'value')");
$pid = (int)db()->lastInsertId();
db()->exec("INSERT INTO imp_invoices (import_id, sort_order, invoice_no) VALUES ($pid, 1, 'HJ-77')");
$piv = (int)db()->lastInsertId();
db()->exec("INSERT INTO imp_payments (import_id, kind, paid_on, amount, rate) VALUES ($pid, 'payment', '2026-10-01', 54200, 280)");
db()->exec("INSERT INTO imp_costs (import_id, kind, currency, est_amount, amount) VALUES ($pid, 'service', 'PKR', 0, 500000), ($pid, 'duty', 'PKR', 0, 185000)");
db()->exec("INSERT INTO imp_containers (import_id, container_no) VALUES ($pid, 'TGHU 902115-1')");
$pcont = (int)db()->lastInsertId();
$inv = $T + ['import_id' => $pid, 'line_id' => ['0', '0', '0', '0'], 'category' => ['Fiber', 'Fabric', 'Fabric', 'Bedding'], 'description' => ['Polyester fiber hollow', 'PC fabric bleached 250', 'PC fabric dyed navy 280', 'Bed sheet sets King'],
    'hs_code' => ['5503.2000', '5513.2100', '5513.2100', '6302.2100'], 'supplier_ref' => ['', '', '', ''], 'bill_unit' => ['kg', 'm', 'yd', 'set'], 'bill_qty' => ['20000', '12000', '5000', '100'],
    'rate' => ['1.75', '1.10', '1.20', '2'], 'net_kg' => ['', '3600', '1408', '150'], 'gross_kg' => ['', '', '', ''], 'packages' => ['', '', '', ''], 'specs_json' => ['', '', '', ''],
    'invoice_id' => [$piv, $piv, $piv, $piv], 'item_key' => ['m1', 'm2', 'm2', 'p1'], 'stock_qty' => ['', '', '', ''], 'size_label' => ['', '', '', 'King']];
page('imp_invoice.php', ['id' => $pid], $inv);
$pl = $q("SELECT * FROM imp_lines WHERE import_id=? ORDER BY sort_order", [$pid]);
ok(count($pl) === 4 && (int)$pl[0]['material_id'] === 1 && (int)$pl[1]['material_id'] === 2 && (int)$pl[3]['product_id'] === 1 && $pl[3]['material_id'] === null && $pl[3]['size_label'] === 'King',
   'each line links to an Item Master material or a Product Master product (with its size)', array_map(fn($l) => [$l['material_id'], $l['product_id'], $l['size_label']], $pl));
$ITEMS = imp_item_options(false);
ok(($ITEMS['m1']['costing'] ?? 0) === 2 && ($ITEMS['m2']['costing'] ?? 0) === 1 && ($ITEMS['p1']['uom'] ?? '') === 'PCS' && ($ITEMS['p1']['code'] ?? '') === 'PRD-1',
   'the picker lists both masters, with how many costing lines use each material', $ITEMS);

/* the stock unit rule */
$st = fn($i) => imp_stock_qty($pl[$i], $ITEMS[imp_line_key($pl[$i])] ?? null);
ok($st(0)['ok'] && $st(0)['qty'] == 20000 && $st(0)['how'] === 'same as billed', 'billed in kg, kept in KG: the billed quantity', $st(0));
ok($st(1)['ok'] && $st(1)['qty'] == 12000 && $st(1)['uom'] === 'MTR', 'billed in m, kept in MTR: the billed quantity', $st(1));
ok($st(2)['ok'] && abs($st(2)['qty'] - 4572) < 0.001 && $st(2)['how'] === 'yd × 0.9144', 'billed in yd, kept in MTR: converted, 5,000 yd = 4,572 m', $st(2));
ok(!$st(3)['ok'] && $st(3)['need_type'] && str_contains($st(3)['why'], 'PCS'), 'billed in sets, kept in PCS: never guessed — it must be typed', $st(3));
ok(imp_stock_qty(['bill_unit' => 'm', 'bill_qty' => 100, 'net_kg' => 30, 'stock_qty' => null], ['uom' => 'KGS'])['qty'] == 30, 'an item kept in KGS takes the net kg');
ok(imp_stock_qty(['bill_unit' => 'm', 'bill_qty' => 914.4, 'net_kg' => 0, 'stock_qty' => null], ['uom' => 'YDS'])['qty'] == 1000, 'billed in m, kept in YDS: 914.4 m = 1,000 yd');
ok(imp_qty_kg(12000, 'MTR', 3600) === '12,000 MTR (3,600 kg)' && imp_qty_kg(20000, 'KG', 20000) === '20,000 KG', 'shown as real unit first, kg in brackets — once for an item kept in kg');
$inv2 = $inv; $inv2['line_id'] = array_map(fn($l) => (string)$l['id'], $pl); $inv2['stock_qty'] = ['', '', '', '-5'];
ok(str_contains(said(page('imp_invoice.php', ['id' => $pid], $inv2), 'error'), 'cannot be negative'), 'a negative quantity into stock is refused');
$inv2['stock_qty'] = ['', '', '', '100'];
page('imp_invoice.php', ['id' => $pid], $inv2);
$pl = $q("SELECT * FROM imp_lines WHERE import_id=? ORDER BY sort_order", [$pid]);
$st = fn($i) => imp_stock_qty($pl[$i], $ITEMS[imp_line_key($pl[$i])] ?? null);
ok($st(3)['ok'] && $st(3)['qty'] == 100 && $st(3)['how'] === 'typed' && (int)$pl[0]['id'] === (int)$inv2['line_id'][0], 'typed once, the PCS quantity is used — and the lines keep their ids');
$ip = page('imp_invoice.php', ['id' => $pid]);
ok(noWarn($ip) && str_contains($ip, 'name="item_key[]"') && str_contains($ip, 'value="p1"') && str_contains($ip, 'id="ipNew"'), 'the invoice page draws the picker, with New item for an Item Master user', substr(strip_tags($ip), 0, 200));
ok(!str_contains(page('imp_invoice.php', ['id' => $pid], null, 'ROLE=colleague PERMS=\'{"imp":"vu"}\''), 'id="ipNew"') && str_contains(page('imp_invoice.php', ['id' => $pid], null, 'ROLE=colleague INVPERM=master PERMS=\'{"imp":"vu"}\''), 'id="ipNew"'),
   'New item is offered only with the Item Master permission');

/* New item (B): save the invoice, go to the existing New Item page, come back linked */
$nb = $inv2; $nb['new_item_for'] = '1';
$out = page('imp_invoice.php', ['id' => $pid], $nb);
ok(str_contains($out, '__REDIRECT__ inv_items.php?new=1&name=PC+fabric+bleached+250&uom=MTR&group=Fabric') && str_contains($out, 'return=imp_invoice.php%3Fid%3D' . $pid),
   'New item saves the invoice and opens Item Master → New Item, filled from the line, with the way back', substr($out, 0, 260));
db()->exec("INSERT INTO inv_materials (code, name, uom) VALUES ('FB-0009', 'PC fabric bleached 250', 'MTR')");
$newMat = (int)db()->lastInsertId();
$out = page('imp_invoice.php', ['id' => $pid, 'new_item' => $newMat]);
ok(str_contains(said($out), 'FB-0009 created and linked') && (int)$q("SELECT material_id FROM imp_lines WHERE id=?", [$pl[1]['id']])[0]['material_id'] === $newMat,
   'back from Item Master, the new item is linked to the line it was made for', said($out));
db()->exec("UPDATE imp_lines SET material_id=2 WHERE id=" . (int)$pl[1]['id']);
page('imp_invoice.php', ['id' => $pid, 'new_item' => $newMat]);
ok((int)$q("SELECT material_id FROM imp_lines WHERE id=?", [$pl[1]['id']])[0]['material_id'] === 2, 'the same link opened again changes nothing — it works once, for the line it was made for');
page('imp_invoice.php', ['id' => $pid], $nb);
page('imp_invoice.php', ['id' => $pid, 'new_item' => 1]);
ok((int)$q("SELECT material_id FROM imp_lines WHERE id=?", [$pl[1]['id']])[0]['material_id'] === 2, 'an item that existed before New item was pressed is not accepted');

/* customs */
$cus = fn($post) => page('imp_customs.php', ['id' => $pid], $T + ['import_id' => $pid, 'invoice_id' => $piv] + $post);
$cus(['action' => 'copy']);
$gd = $q("SELECT * FROM imp_gd_lines WHERE import_id=? ORDER BY line_no", [$pid]);
ok(count($gd) === 4 && (float)$gd[1]['declared_value'] === 13200.0 && count($q("SELECT id FROM imp_lines WHERE import_id=? AND gd_line_id IS NULL", [$pid])) === 0,
   'Copy from invoice: one GD line per invoice line, declared at the supplier value, each line remembering its GD line', $gd);
ok(str_contains(said($cus(['action' => 'copy']), 'error'), 'already on a GD line'), 'copying twice adds nothing');
$cus(['action' => 'group']);
$gd = $q("SELECT * FROM imp_gd_lines WHERE import_id=? ORDER BY line_no", [$pid]);
$pl = $q("SELECT * FROM imp_lines WHERE import_id=? ORDER BY sort_order", [$pid]);
ok(count($gd) === 3 && (int)$pl[1]['gd_line_id'] === (int)$pl[2]['gd_line_id'] && (float)$gd[1]['declared_value'] === 19200.0 && $gd[1]['declared_qty'] === null,
   'Group by HS: the two 5513.2100 lines share one GD line; values add up; metres and yards are not added together', $gd);
ok(array_map(fn($g) => (int)$g['line_no'], $gd) === [1, 2, 3], 'the GD lines are numbered 1, 2, 3 again after grouping', array_column($gd, 'line_no'));
ok((int)$pl[1]['material_id'] === 2 && (int)$pl[2]['material_id'] === 2 && $pl[1]['id'] !== $pl[2]['id'], 'grouping keeps both invoice lines — the products stay separate');
$sv = ['action' => 'save_lines', 'gd' => [$gd[1]['id'] => ['hs_code' => '5513.2100', 'description' => 'Woven PC fabric', 'declared_qty' => '', 'declared_unit' => '', 'declared_value' => '19200', 'assessed_value' => '20600', 'exemption_ref' => 'EFS-AUTH-12', 'diff_reason' => '']]];
ok(str_contains(said($cus($sv), 'error'), 'give the reason') && $q("SELECT assessed_value FROM imp_gd_lines WHERE id=?", [$gd[1]['id']])[0]['assessed_value'] === null, 'assessed different from declared needs a reason — nothing saved without one');
$sv['gd'][$gd[1]['id']]['diff_reason'] = 'Valuation ruling 1.18/m';
$cus($sv);
ok((float)$q("SELECT assessed_value FROM imp_gd_lines WHERE id=?", [$gd[1]['id']])[0]['assessed_value'] === 20600.0, 'with the reason it is saved');
$gv = imp_gd_valuation($pl);
ok(abs($gv[(int)$pl[1]['id']] * 3600 - 20600 * 13200 / 19200) < 0.01 && abs($gv[(int)$pl[2]['id']] * 1408 - 20600 * 6000 / 19200) < 0.01 && !isset($gv[(int)$pl[0]['id']]),
   'the assessed value is shared back by supplier value, giving each line its own valuation per kg; a line not yet assessed has none', $gv);
$e1 = imp_efs_line($pl[1], $gv[(int)$pl[1]['id']]);
ok($e1['source'] === 'GD assessed' && abs($e1['imported'] - 14162.5) < 0.01 && imp_efs_line($pl[0], null)['source'] === 'invoice', 'EFS values the line from the GD once assessed, from the invoice until then', $e1);
$pl[1]['efs_valuation'] = '9'; ok(imp_efs_line($pl[1], $gv[(int)$pl[1]['id']])['source'] === 'typed', 'a valuation typed on the EFS tab still wins');
$cus(['action' => 'save_gd', 'gd_no' => 'KPAF-1', 'gd_date' => '2026-10-27', 'gd_released' => '2026-10-28', 'gd_status' => '']);
$lg = $q("SELECT gd_filed, customs_release FROM imp_logistics WHERE import_id=?", [$pid]);
ok(($q("SELECT gd_status FROM imp_invoices WHERE id=?", [$piv])[0]['gd_status'] ?? '') === 'released' && ($lg[0]['customs_release'] ?? '') === '2026-10-28' && ($lg[0]['gd_filed'] ?? '') === '2026-10-27',
   'a release date marks the GD released and fills LOGISTICS (which starts the EFS clock)', $lg);
ok(str_contains(said($cus(['action' => 'save_gd', 'gd_date' => '2026-10-27', 'gd_released' => '2026-10-20']), 'error'), 'cannot be before'), 'released before the GD date is refused');
ok(str_contains(said(page('imp_invoice.php', ['id' => $pid], $T + ['import_id' => $pid, 'line_id' => [(string)$pl[0]['id']], 'description' => ['Polyester fiber hollow'], 'category' => ['Fiber'], 'bill_unit' => ['kg'], 'bill_qty' => ['20000'], 'rate' => ['1.75'], 'item_key' => ['m1']]), 'error'), 'on a GD line'),
   'an invoice line on a GD line cannot be removed from the invoice');
$cp = page('imp_customs.php', ['id' => $pid]);
ok(noWarn($cp) && str_contains($cp, 'PKR 185,000 of duty and taxes') && str_contains($cp, 'Valuation ruling'), 'the CUSTOMS page shows the GD lines, the reason, and the duty entered on COSTS', substr(strip_tags($cp), 0, 300));
ok(str_contains(page('imp_customs.php', ['id' => $pid], $T + ['import_id' => $pid, 'invoice_id' => $piv, 'action' => 'copy'], 'ROLE=colleague PERMS=\'{"imp":"v"}\''), 'do not have permission'), 'changing customs needs the Update right');

/* receiving: the pre-fill */
$pre = imp_gate_prefill($q("SELECT * FROM imp_imports WHERE id=?", [$pid])[0]);
$land = imp_landed($q("SELECT * FROM imp_imports WHERE id=?", [$pid])[0], $pl);
ok($pre['ok'] && count($pre['lines']) === 4 && (float)$pre['lines'][1]['qty'] === 12000.0 && $pre['lines'][1]['uom'] === 'MTR' && (float)$pre['lines'][1]['imp_kg'] === 3600.0
   && (float)$pre['lines'][2]['qty'] === 4572.0 && $pre['lines'][0]['lot_no'] === 'IMP-P2-0001-1' && $pre['head']['party_id'] === 1 && $pre['head']['txn_type'] === 'purchase',
   'Receive into stock fills Gate Inward: the supplier, Purchase, each line in the item\'s unit with its kg and lot', $pre);
ok(abs($pre['lines'][1]['rate'] - round($land['rows'][1]['landed'] / 12000, 4)) < 0.0001 && $pre['lines'][3]['product_id'] === 1 && $pre['lines'][3]['size_label'] === 'King',
   'the rate is the landed cost per stock unit; a product line carries its size', [$pre['lines'][1]['rate'], $land['rows'][1]['landed']]);

/* receiving: a posted pass, a draft, a reversed one */
$gate = function (string $status, array $rows, string $remarks = '') use ($pid) {
    db()->prepare("INSERT INTO inv_gate (gate_no, gate_date, status, remarks, import_id) VALUES (?,?,?,?,?)")->execute(['GIP-' . rand(1000, 9999), '2026-10-30', $status, $remarks, $pid]);
    $g = (int)db()->lastInsertId();
    foreach ($rows as $r) db()->prepare("INSERT INTO inv_gate_items (gate_id, material_id, product_id, qty, uom, rate, lot_no, imp_line_id) VALUES (?,?,?,?,?,?,?,?)")->execute([$g, $r[0], $r[1], $r[2], $r[3], 100, $r[4], $r[5]]);
    return $g;
};
$g1 = $gate('posted', [[1, null, 10000, 'KG', 'IMP-P2-0001-1', $pl[0]['id']], [2, null, 12000, 'MTR', 'IMP-P2-0001-2', $pl[1]['id']]]);
imp_receipt_save($g1, $pid, ['line' => [['imp_line_id' => $pl[0]['id'], 'imp_kg' => '999'], ['imp_line_id' => $pl[1]['id'], 'imp_kg' => '3600']],
    'imp_container_id' => $pcont, 'imp_packages' => '50', 'imp_gross' => '13700', 'imp_tare' => '0', 'imp_rcv' => [$pl[1]['id'] => ['damaged' => '5', 'remark' => '1 roll torn']]]);
$rl = $q("SELECT * FROM imp_receipt_lines WHERE gate_id=? ORDER BY imp_line_id", [$g1]);
ok((float)$rl[0]['net_kg'] === 10000.0 && (float)$rl[1]['net_kg'] === 3600.0 && (float)$rl[1]['damaged'] === 5.0 && (int)$q("SELECT container_id FROM imp_receipts WHERE gate_id=?", [$g1])[0]['container_id'] === $pcont,
   'the import side of a pass: kg for an item kept in KG is its quantity (whatever was typed); kg for metres as typed; damaged for the claim; the container', $rl);
imp_receipt_save($g1, $pid, ['line' => [['imp_line_id' => $pl[0]['id']], ['imp_line_id' => $pl[1]['id'], 'imp_kg' => '3600']], 'imp_container_id' => '999']);
ok($q("SELECT container_id FROM imp_receipts WHERE gate_id=?", [$g1])[0]['container_id'] === null && count($q("SELECT id FROM imp_receipt_lines WHERE gate_id=?", [$g1])) === 2,
   'saving again rewrites it whole; a container from another import is not accepted');
$g2 = $gate('draft', [[1, null, 4000, 'KG', 'IMP-P2-0001-1', $pl[0]['id']]]);
imp_receipt_save($g2, $pid, ['line' => [['imp_line_id' => $pl[0]['id']]]]);
$g3 = $gate('reversed', [[1, null, 6000, 'KG', 'IMP-P2-0001-1', $pl[0]['id']]]);
$tot = imp_receipt_totals($pid);
ok($tot[(int)$pl[0]['id']]['posted'] == 10000 && $tot[(int)$pl[0]['id']]['draft'] == 4000 && $tot[(int)$pl[0]['id']]['kg_posted'] == 10000 && $tot[(int)$pl[1]['id']]['kg_posted'] == 3600,
   'received = posted passes only; a draft is shown apart; a reversed pass counts for nothing', $tot);
$pre = imp_gate_prefill($q("SELECT * FROM imp_imports WHERE id=?", [$pid])[0]);
$byL = []; foreach ($pre['lines'] as $x) $byL[$x['imp_line_id']] = $x;
ok((float)$byL[$pl[0]['id']]['qty'] === 6000.0 && !isset($byL[$pl[1]['id']]) && $byL[$pl[0]['id']]['lot_no'] === 'IMP-P2-0001-1',
   'the next truck is filled with only what is still to come (20,000 − 10,000 posted − 4,000 on a draft), under the same lot', $byL);

/* the import's checks before Gate Inward posts */
$g4 = $gate('draft', [[2, null, 4572, 'MTR', 'IMP-P2-0001-3', $pl[2]['id']]]);
imp_receipt_save($g4, $pid, ['line' => [['imp_line_id' => $pl[2]['id'], 'imp_kg' => '']]]);
ok(str_contains(imp_receipt_check($g4), 'Net kg is needed on import line 3'), 'a pass cannot post without the kg of each import line (EFS counts in kg)', imp_receipt_check($g4));
imp_receipt_save($g4, $pid, ['line' => [['imp_line_id' => $pl[2]['id'], 'imp_kg' => '1408']]]);
ok(imp_receipt_check($g4) === '', 'with the kg it may post');
$g5 = $gate('draft', [[1, null, 10500, 'KG', 'IMP-P2-0001-1', $pl[0]['id']]]);
imp_receipt_save($g5, $pid, ['line' => [['imp_line_id' => $pl[0]['id']]]]);
ok(str_contains(imp_receipt_check($g5), 'brings 500 KG more than the import') && str_contains(imp_receipt_check($g5), 'Remarks'), 'more than the import needs a reason in Remarks', imp_receipt_check($g5));
db()->exec("UPDATE inv_gate SET remarks='Supplier scale 10,500' WHERE id=$g5");
ok(imp_receipt_check($g5) === '', 'with the reason it may post');
ok(imp_receipt_check($gate('draft', [[1, null, 1, 'KG', '', null]])) === '' && imp_receipt_check(999999) === '', 'a pass without an import line, or not an import pass, is not touched');
db()->exec("UPDATE imp_imports SET already_in_stock=1 WHERE id=$pid");
ok(str_contains(imp_receipt_check($g4), 'already in stock') && !imp_gate_prefill($q("SELECT * FROM imp_imports WHERE id=?", [$pid])[0])['ok'], 'an opening import "already in stock" can never be received or posted');
db()->exec("UPDATE imp_imports SET already_in_stock=0 WHERE id=$pid");
imp_receipt_forget($g2);
ok(!$q("SELECT id FROM imp_receipt_lines WHERE gate_id=?", [$g2]) && !$q("SELECT gate_id FROM imp_receipts WHERE gate_id=?", [$g2]), 'a deleted pass takes its import rows with it');

/* the GATE IN page */
$gp = page('imp_gatein.php', ['id' => $pid]);
ok(noWarn($gp) && str_contains($gp, '12,000 MTR <span class="kgb">(3,600 kg)</span>') && str_contains($gp, 'Partly received') && str_contains($gp, 'inv_gate.php?dir=in&amp;new=1&amp;import=' . $pid),
   'GATE IN shows each line as real unit (kg), what is still to come, and Receive into stock', substr(strip_tags($gp), 0, 400));
ok(!str_contains(page('imp_gatein.php', ['id' => $pid], null, 'ROLE=colleague PERMS=\'{"imp":"v"}\''), 'Receive into stock →'), 'Receive into stock needs the Gate Inward permission');
db()->exec("UPDATE imp_lines SET material_id=NULL WHERE id=" . (int)$pl[0]['id']);
$gp = page('imp_gatein.php', ['id' => $pid]);
ok(str_contains($gp, 'Line 1: link it to an Item Master') && !str_contains($gp, 'Receive into stock →'), 'a line not linked to an item stops receiving, and says which line');
db()->exec("UPDATE imp_lines SET material_id=1 WHERE id=" . (int)$pl[0]['id']);


/* =================================================================== 10 */
head('10. Phase 3 — EFS reconciliation');
$L1 = 'L' . (int)$pl[0]['id']; $L2 = 'L' . (int)$pl[1]['id']; $L4 = 'L' . (int)$pl[3]['id']; $O1 = 'O' . (int)$or[0]['id'];
/* the GD's assessed kg on CUSTOMS */
$gdL1 = $q("SELECT * FROM imp_gd_lines WHERE id=?", [$pl[0]['gd_line_id']])[0];
page('imp_customs.php', ['id' => $pid], $T + ['import_id' => $pid, 'invoice_id' => $piv, 'action' => 'save_lines', 'gd' => [$gdL1['id'] => ['hs_code' => $gdL1['hs_code'], 'description' => $gdL1['description'],
    'declared_qty' => '', 'declared_unit' => '', 'declared_value' => $gdL1['declared_value'], 'assessed_value' => '', 'assessed_kg' => '19800', 'exemption_ref' => '', 'diff_reason' => '']]]);
$ob = imp_efs_obligations();
ok(isset($ob[$L1]) && $ob[$L1]['kg'] == 19800 && $ob[$L1]['kg_source'] === 'GD assessed' && $ob[$L1]['need_kg'] == 18810 && $ob[$L1]['need_value'] == round(19800 * 1.75, 2),
   'EFS counts the GD\'s assessed kg (19,800) — must export 18,810 kg and USD 34,650 (value not reduced by the 5%)', $ob[$L1] ?? null);
ok($ob[$O1]['kind'] === 'opening' && $ob[$O1]['done_kg'] == 16000 && $ob[$O1]['need_kg'] == 22800 && $ob[$O1]['deadline'] === '2026-12-14', 'an opening balance is an obligation too, with what was exported before go-live', $ob[$O1]);
$keys = array_keys($ob);
ok(array_search($O1, $keys, true) < array_search($L1, $keys, true), 'oldest deadline first');
/* not every line of an EFS import is EFS */
$ef = $T + ['import_id' => $pid, 'efs_months' => '9', 'eligible' => [$pl[0]['id'] => '1', $pl[1]['id'] => '1', $pl[2]['id'] => '1']];
ok(said(page('imp_efs.php', ['id' => $pid], $ef)) === 'EFS figures saved.' && !isset(imp_efs_obligations()[$L4]) && isset(imp_efs_obligations()[$L1]), 'a line switched off EFS is not an obligation');
$ep = page('imp_efs.php', ['id' => $pid]);
ok(noWarn($ep) && str_contains($ep, 'GD assessed') && str_contains($ep, 'not EFS'), 'the EFS tab shows the kg source and the line that is not EFS', substr(strip_tags($ep), 0, 200));

/* the export side: the customs invoice, else the sale invoice */
db()->exec("INSERT INTO shipments (id, invoice_no, invoice_date, buyer_name, currency, total_amount) VALUES (7001, 'ZAS/7001', '2026-11-20', 'Nordic Home AB', 'USD', 16650)");
db()->exec("INSERT INTO shipment_items (shipment_id, line_no, product_name, qty, unit, rate, amount) VALUES (7001, 1, 'Comforter King', 400, 'Pcs', 30, 12000), (7001, 2, 'Pillow', 1200, 'Pcs', 3.875, 4650)");
$s1 = imp_efs_source(7001);
ok($s1['kind'] === 'invoice' && count($s1['lines']) === 2, 'without a customs invoice, the sale invoice lines are offered');
db()->exec("INSERT INTO exp_doc_lines (shipment_id, view, line_no, description, hs_code, unit, qty, rate, amount) VALUES (7001, 'customs', 1, 'Comforter, hollow-fibre', '9404.9000', 'PCS', 400, 30, 12000),
    (7001, 'customs', 2, 'Pillow, hollow-fibre (local cover)', '9404.9000', 'PCS', 1200, 3, 3600), (7001, 'customs', 3, 'Bath towel, cotton', '6302.6000', 'PCS', 300, 3.5, 1050), (7001, 'chamber', 1, 'Chamber line', '', 'PCS', 1, 1, 1)");
$src = imp_efs_source(7001);
ok($src['kind'] === 'customs' && count($src['lines']) === 3 && $src['hash'] !== $s1['hash'], 'with a customs invoice, its lines are used (not the chamber one, not the sale invoice)');
[$c1, $c2, $c3] = array_column($src['lines'], 'id');

/* the maths */
$base = ['shipment_id' => 7001, 'rates' => [], 'lines' => [['src_id' => $c1, 'efs_value' => 12000, 'manual' => false, 'uses' => [['key' => $L1, 'kg' => 3000]]],
                                                            ['src_id' => $c2, 'efs_value' => 2400, 'manual' => false, 'uses' => [['key' => $L1, 'kg' => 1500]]]]];
$r = imp_efs_compute($base);
ok(abs($r['per'][$L1]['kg'] - 4500) < 0.001 && $r['counted'] == 14400.0 && $r['lines'][1]['uses'][0]['floor_target'] == 2625.0 && $r['lines'][1]['uses'][0]['diff'] == -225.0,
   'the summary is the total of the detail: 4,500 kg, USD 14,400; the pillow\'s 1,500 kg is assessed at 2,625 and counted at 2,400', $r['per'][$L1] ?? $r['errors']);
ok($r['below'] == 225.0 && str_contains(implode(' ', $r['errors']), 'below the assessed value'), 'one line above never hides another below: 225 below needs a reason', $r['errors']);
$r = imp_efs_compute($base + ['below_reason' => 'Pillow cover is local']);
ok(!$r['errors'], 'with the reason it may be saved', $r['errors']);
$two = $base; $two['lines'][0]['uses'] = [['key' => $L1, 'kg' => 3000], ['key' => $O1, 'kg' => 1000]];
$r = imp_efs_compute($two + ['below_reason' => 'x']);
$u = $r['lines'][0]['uses'];
ok(abs($u[0]['value_export'] - round(12000 * 3000 * 1.75 / (3000 * 1.75 + 1000 * 1.62), 2)) < 0.01 && abs($u[0]['value_export'] + $u[1]['value_export'] - 12000) < 0.001,
   'the line\'s value is split by material value (kg × valuation), and the split adds up exactly', $u);
$thr = $base; $thr['lines'] = [['src_id' => $c1, 'efs_value' => 100, 'manual' => false, 'uses' => [['key' => $L1, 'kg' => 10], ['key' => $L1, 'kg' => 10], ['key' => $L1, 'kg' => 10]]]];
$tu = imp_efs_compute($thr + ['below_reason' => 'x'])['lines'][0]['uses'];
ok(array_column($tu, 'value_export') === [33.33, 33.33, 33.34], 'USD 100 over three equal uses is 33.33 + 33.33 + 33.34 — the last takes the rounding, so it adds up exactly', array_column($tu, 'value_export'));
$m = $two; $m['lines'][0]['manual'] = true; $m['lines'][0]['uses'] = [['key' => $L1, 'kg' => 3000, 'value' => 9000], ['key' => $O1, 'kg' => 1000, 'value' => 2000]];
ok(str_contains(implode(' ', imp_efs_compute($m + ['below_reason' => 'x'])['errors']), 'add up to 11,000.00, not the line\'s 12,000.00'), 'values typed by hand must add up to the line\'s EFS value');
$big = $base; $big['lines'][0]['efs_value'] = 12500;
ok(str_contains(implode(' ', imp_efs_compute($big + ['below_reason' => 'x'])['errors']), 'more than the line value'), 'a line can never count more than its own value');
$over = $base; $over['lines'][0]['uses'] = [['key' => $L1, 'kg' => 19000]];
ok(str_contains(implode(' ', imp_efs_compute($over + ['below_reason' => 'x', 'warn_reason' => 'x'])['errors']), 'more than the 19,800 kg it has left'), 'never more kg than an import line has left');
$rc = $base; $rc['lines'][0]['uses'] = [['key' => $L1, 'kg' => 9000]];
$r = imp_efs_compute($rc + ['below_reason' => 'x']);
ok($r['warn'] && str_contains(implode(' ', $r['errors']), 'received at Gate In'), 'more kg than received at Gate In (10,000) is highlighted and needs a reason', $r['warn']);
ok(!imp_efs_compute($rc + ['below_reason' => 'x', 'warn_reason' => 'received before go-live'])['errors'], 'with the reason it may be saved');
ok(str_contains(implode(' ', imp_efs_compute($base + ['below_reason' => 'x'])['notes']), 'Older pending for HS 5503.2000'), 'an older GD line of the same HS with kg left is pointed out first');
$r = imp_efs_compute($base + ['below_reason' => 'x', 'gd_net_kg' => 4600, 'gd_fob' => 14000]);
ok(str_contains(implode(' ', $r['notes']), 'export GD net weight is 4,600') && str_contains(implode(' ', $r['notes']), 'more than the export GD FOB') && !$r['errors'], 'the export GD net weight and FOB differences are shown, never blocked', $r['notes']);
db()->exec("UPDATE imp_opening_efs SET currency='EUR' WHERE id=" . (int)$or[0]['id']);
$eu = $base; $eu['lines'][0]['uses'] = [['key' => $O1, 'kg' => 1000]];
ok(str_contains(implode(' ', imp_efs_compute($eu + ['below_reason' => 'x'])['errors']), '1 USD = how many EUR'), 'an import in another currency needs the export GD\'s exchange rate');
$eu['rates'] = ['EUR' => 0.92];
$r = imp_efs_compute($eu + ['below_reason' => 'x']);
ok($r['lines'][0]['uses'][0]['value_target'] == round(12000 * 0.92, 2) && !$r['errors'], 'with the rate, the value counted against it is in its own currency (12,000 USD × 0.92)', $r['lines'][0]['uses'][0] ?? $r['errors']);
db()->exec("UPDATE imp_opening_efs SET currency='USD' WHERE id=" . (int)$or[0]['id']);

/* saving through the page */
$save = fn(array $form, int $aid = 0, string $env = '') => page('imp_efs_alloc.php', $aid ? ['id' => $aid] : ['shipment' => 7001],
    $T + ['action' => 'save', 'alloc_id' => $aid, 'shipment_id' => 7001, 'payload' => json_encode($form)], $env);
$out = $save($base);
ok(str_contains(said($out, 'error'), 'below the assessed value') && !$q("SELECT id FROM imp_efs_alloc"), 'the server checks again: nothing saved without the reason', said($out, 'error'));
$out = $save($base + ['below_reason' => 'Pillow cover is local', 'export_gd_no' => 'KEXP-1', 'export_gd_date' => '2026-11-21', 'gd_net_kg' => 4600, 'gd_fob' => 16650]);
$A = $q("SELECT * FROM imp_efs_alloc")[0] ?? null;
ok($A && str_contains($out, '__REDIRECT__ imp_efs_alloc.php?id=' . $A['id']) && $A['source'] === 'customs' && $A['below_reason'] === 'Pillow cover is local' && (float)$A['gd_net_kg'] === 4600.0,
   'saved: the customs invoice, the export GD and the reason are kept', $A);
$U = $q("SELECT * FROM imp_efs_alloc_uses WHERE alloc_id=? ORDER BY id", [$A['id']]);
ok(count($U) === 2 && (float)$U[1]['value_target'] === 2400.0 && (float)$U[1]['floor_target'] === 2625.0, 'each import use keeps its kg, value and assessed value', $U);
$ob = imp_efs_obligations();
ok($ob[$L1]['done_kg'] == 4500 && $ob[$L1]['done_value'] == 14400 && $ob[$L1]['max_kg'] == 15300, 'the import line now shows 4,500 kg / USD 14,400 discharged, 15,300 kg left to allocate', $ob[$L1]);
ok(str_contains(said($save($base + ['below_reason' => 'x']), 'error'), 'already has an allocation'), 'one allocation per shipment');
ok(str_contains(page('imp_efs_alloc.php', ['shipment' => 7001]), '__REDIRECT__ imp_efs_alloc.php?id=' . $A['id']), 'opening the shipment again opens its allocation');
/* an open allocation can be changed */
$ch = $base; $ch['lines'][1]['efs_value'] = 2700;
$save($ch, (int)$A['id']);
ok((float)$q("SELECT efs_value FROM imp_efs_alloc_lines WHERE alloc_id=? AND src_id=?", [$A['id'], $c2])[0]['efs_value'] === 2700.0 && $q("SELECT below_reason FROM imp_efs_alloc WHERE id=?", [$A['id']])[0]['below_reason'] === null,
   'an open allocation is changed and saved again (2,700 is above the assessed 2,625, so no reason is kept)');
/* the customs invoice changes afterwards */
db()->exec("UPDATE exp_doc_lines SET amount=11500 WHERE id=$c1");
ok(str_contains(imp_efs_recheck(imp_efs_alloc((int)$A['id'])), 'customs invoice changed'), 'a customs invoice changed afterwards → recheck');
$rp = page('imp_efs_recon.php');
ok(str_contains($rp, 'recheck'), 'and the reconciliation shows it');
ok(str_contains(said(page('imp_efs_alloc.php', ['id' => $A['id']], $T + ['action' => 'file', 'alloc_id' => $A['id'], 'filed_on' => '2026-11-25', 'filed_ref' => 'S-118']), 'error'), 'Recheck first'), 'it cannot be filed until rechecked');
db()->exec("UPDATE exp_doc_lines SET amount=12000 WHERE id=$c1");
ok(imp_efs_recheck(imp_efs_alloc((int)$A['id'])) === '', 'put back, nothing to recheck');
/* filed with customs locks it */
ok(str_contains(said(page('imp_efs_alloc.php', ['id' => $A['id']], $T + ['action' => 'file', 'alloc_id' => $A['id'], 'filed_on' => '2026-11-25', 'filed_ref' => '']), 'error'), 'reference'), 'filing needs the date and the reference');
page('imp_efs_alloc.php', ['id' => $A['id']], $T + ['action' => 'file', 'alloc_id' => $A['id'], 'filed_on' => '2026-11-25', 'filed_ref' => 'EFS-STMT-118']);
ok($q("SELECT status FROM imp_efs_alloc WHERE id=?", [$A['id']])[0]['status'] === 'filed', 'filed with customs');
ok(str_contains(said($save($ch, (int)$A['id']), 'error'), 'can only be reversed'), 'a filed allocation cannot be changed');
ok(!str_contains(page('imp_efs_alloc.php', ['id' => $A['id']]), 'id="aForm"'), 'and the page shows it read-only');
/* reversing */
ok(str_contains(page('imp_efs_alloc.php', ['id' => $A['id']], $T + ['action' => 'reverse', 'alloc_id' => $A['id'], 'reason' => 'Wrong GD line typed'], 'ROLE=colleague PERMS=\'{"impefs":"vcud"}\''), 'do not have permission'), 'reversing needs the Reverse right');
ok(str_contains(said(page('imp_efs_alloc.php', ['id' => $A['id']], $T + ['action' => 'reverse', 'alloc_id' => $A['id'], 'reason' => 'x']), 'error'), 'at least 5'), 'and a reason');
page('imp_efs_alloc.php', ['id' => $A['id']], $T + ['action' => 'reverse', 'alloc_id' => $A['id'], 'reason' => 'Wrong GD line typed']);
$ob = imp_efs_obligations();
ok($q("SELECT status FROM imp_efs_alloc WHERE id=?", [$A['id']])[0]['status'] === 'reversed' && $ob[$L1]['done_kg'] == 0 && count($q("SELECT id FROM imp_efs_alloc_uses WHERE alloc_id=?", [$A['id']])) === 2,
   'reversed: the kg go back to the import line, and the allocation stays on the record');
$out = $save($base + ['below_reason' => 'Pillow cover is local']);
ok(count($q("SELECT id FROM imp_efs_alloc WHERE shipment_id=7001")) === 2, 'after a reversal the shipment can be allocated again');
/* approved reductions and extensions */
$lp = fn(array $post, string $env = '') => page('imp_efs_line.php', ['kind' => 'line', 'id' => $pl[0]['id']], $T + ['kind' => 'line', 'id' => $pl[0]['id']] + $post, $env);
ok(str_contains(said($lp(['action' => 'extend', 'ext_date' => '2027-12-31', 'ext_ref' => '']), 'error'), 'reference'), 'an extended deadline needs the customs reference');
$lp(['action' => 'extend', 'ext_date' => '2027-12-31', 'ext_ref' => 'MOC/EFS/EXT/552']);
ok(imp_efs_obligations()[$L1]['deadline'] === '2027-12-31' && str_contains(imp_efs_obligations()[$L1]['deadline_from'], 'MOC/EFS/EXT/552'), 'the extended deadline counts, with its reference');
ok(str_contains(said($lp(['action' => 'reduce', 'kg' => '400', 'ref' => '', 'reason' => 'wet']), 'error'), 'reference'), 'an approved reduction needs the reference and the reason');
ok(str_contains(said($lp(['action' => 'reduce', 'kg' => '99999', 'ref' => 'D-1', 'reason' => 'wet']), 'error'), 'not yet exported'), 'never more than is still to export');
$lp(['action' => 'reduce', 'kg' => '400', 'ref' => 'DC-DEST-77', 'reason' => '40 bales wet fibre destroyed']);
$ob = imp_efs_obligations();
ok($ob[$L1]['reduced_kg'] == 400 && $ob[$L1]['need_kg'] == round((19800 - 400) * 0.95, 3) && $ob[$L1]['need_value'] == round((19800 - 400) * 1.75, 2), 'an approved reduction lowers the kg the obligation is based on', $ob[$L1]);
$rid = (int)$q("SELECT id FROM imp_efs_reductions")[0]['id'];
$lp(['action' => 'void_reduction', 'rid' => $rid, 'reason' => 'approval withdrawn']);
ok(imp_efs_obligations()[$L1]['reduced_kg'] == 0 && count($q("SELECT id FROM imp_efs_reductions")) === 1, 'voided with a reason, never deleted');
/* a line already used by an export stays EFS */
$ef2 = $ef; unset($ef2['eligible'][$pl[0]['id']]);
ok(str_contains(said(page('imp_efs.php', ['id' => $pid], $ef2), 'error'), 'stays EFS'), 'a line already used by an export cannot be switched off EFS');
/* the pages */
$rp = page('imp_efs_recon.php');
ok(noWarn($rp) && str_contains($rp, 'ZAS/7001') && str_contains($rp, 'KAPW-HC-88120') && str_contains($rp, 'Not filed'), 'EFS Reconciliation lists the obligations and the allocations', substr(strip_tags($rp), 0, 300));
$csv = page('imp_efs_recon.php', ['csv' => '1', 'show' => 'all']);
ok(str_starts_with($csv, '"Import GD","GD date",Reference') && str_contains($csv, 'KAPW-HC-88120') && noWarn($csv), 'and exports to Excel (CSV) with nothing else in the file', substr($csv, 0, 200));
$ap = page('imp_efs_alloc.php', ['id' => $q("SELECT MAX(id) m FROM imp_efs_alloc")[0]['m']]);
ok(noWarn($ap) && str_contains($ap, 'built from the <b>customs invoice</b>') && str_contains($ap, 'id="aForm"'), 'the allocation page opens with its form', substr(strip_tags($ap), 0, 200));
ok(noWarn(page('imp_efs_alloc.php')) && noWarn(page('imp_efs_line.php', ['kind' => 'opening', 'id' => $or[0]['id']])), 'the shipment picker and an opening balance\'s page open cleanly');
ok(str_contains(page('imp_efs_recon.php', [], null, 'ROLE=colleague PERMS=\'{"imp":"v"}\''), 'do not have permission'), 'EFS Reconciliation needs the EFS permission');

/* =================================================================== 8 */
head('8. In Chromium');
$node = trim((string)shell_exec('command -v node 2>/dev/null'));
$root = trim((string)shell_exec('npm root -g 2>/dev/null'));
if ($node === '' || !is_dir($root . '/playwright')) {
    echo "  (skipped — playwright not available)\n";
} else {
    $router = $work . '/router.php';
    file_put_contents($router, '<?php $_SERVER["REQUEST_METHOD"]=$_SERVER["REQUEST_METHOD"]??"GET"; $f=__DIR__.parse_url($_SERVER["REQUEST_URI"],PHP_URL_PATH); if(is_file($f)&&substr($f,-4)===".php"){chdir(__DIR__);require $f;return true;} return false;');
    $port = 19000 + random_int(0, 900);
    $srv = proc_open([PHP_BINARY, '-S', '127.0.0.1:' . $port, '-t', $work, $router], [0 => ['pipe', 'r'], 1 => ['file', '/dev/null', 'w'], 2 => ['file', '/dev/null', 'w']], $pp, $work);
    usleep(500000);
    $js = <<<'JS'
const { chromium } = require('playwright');
(async () => {
  const base = process.argv[2], id = process.argv[3], R = { errors: [] };
  const b = await chromium.launch({ executablePath: '/opt/pw-browsers/chromium' });
  const p = await b.newPage({ viewport: { width: 1280, height: 900 } });
  p.setDefaultTimeout(8000); // a broken page fails fast instead of waiting 30 s per step
  p.on('pageerror', e => R.errors.push(String(e))); p.on('dialog', d => d.accept());
  await p.goto(base + '/imp_invoice.php?id=' + id);
  R.t0 = [await p.locator('#tUnit').innerText(), await p.locator('#tVal').innerText()];
  const r0 = p.locator('#lnTable tbody tr.ln').first();
  await r0.locator('.q').fill('10000'); R.typed = [await p.locator('#tUnit').innerText(), await r0.locator('.amt').innerText(), await p.evaluate(() => document.activeElement.name)];
  await r0.locator('.unit').selectOption('yd'); R.yd = await p.locator('#tUnit').innerText();
  await r0.locator('.cat').selectOption('Foam'); R.foamSpec = await p.locator('#lnTable tbody tr.sp').first().locator('[data-sk]').evaluateAll(es => es.map(e => e.dataset.sk));
  await r0.locator('.cat').selectOption('Fiber'); R.fiberBack = await p.locator('#lnTable tbody tr.sp').first().locator('[data-sk=denier]').inputValue();
  await p.goto(base + '/imp_view.php?id=' + id);
  R.lcHidden0 = await p.locator('[data-show=lc]').isHidden();
  await p.check('input[name=lc]'); await p.fill('input[name=lc_no]', 'LC-7788'); await p.uncheck('input[name=lc]');
  R.lcHidden1 = await p.locator('[data-show=lc]').isHidden(); R.lcKept = await p.inputValue('input[name=lc_no]');
  await p.selectOption('#basisSel', 'invoice'); R.dueInv = await p.locator('#dueOut').innerText();
  await p.locator('#ivAdd').click(); await p.locator('#ivAdd').click(); R.ivMax = [await p.locator('#ivTable tbody tr').count(), await p.locator('#ivAdd').isDisabled()];
  R.width = await p.evaluate(() => document.documentElement.scrollWidth);
  console.log(JSON.stringify(R)); await b.close();
})().catch(e => { console.log(JSON.stringify({ crashed: String(e).slice(0, 300) })); process.exit(0); });
JS;
    file_put_contents($work . '/drive.js', $js);
    $out = (string)shell_exec('NODE_PATH=' . escapeshellarg($root) . ' ' . escapeshellarg($node) . ' ' . escapeshellarg($work . '/drive.js') . ' ' . escapeshellarg("http://127.0.0.1:$port") . ' ' . $id . ' 2>&1');
    proc_terminate($srv); proc_close($srv);
    $R = json_decode(trim((string)substr($out, (int)strrpos($out, "\n{") ?: 0)), true) ?: json_decode(trim($out), true) ?: [];
    ok($R && !isset($R['crashed']), 'chromium drove the pages', $out);
    ok(($R['t0'][0] ?? '') === '25,000 kg · 2,000 m', 'the invoice opens with one total per unit', $R['t0'] ?? null);
    ok(($R['typed'][0] ?? '') === '15,000 kg · 2,000 m' && ($R['typed'][1] ?? '') === '18,000.00' && ($R['typed'][2] ?? '') === 'bill_qty[]', 'typing 10,000 updates the line and the totals, the cursor stays', $R['typed'] ?? null);
    ok(($R['yd'] ?? '') === '10,000 yd · 5,000 kg · 2,000 m', 'switching a line to yards gives a third total, never added to the others', $R['yd'] ?? null);
    ok(($R['foamSpec'] ?? []) === ['type', 'grade', 'density', 'thickness', 'dims', 'form', 'condition'] && ($R['fiberBack'] ?? '') === '7D', 'Foam shows the foam specification; back to Fiber, the fiber figures are still there', [$R['foamSpec'] ?? null, $R['fiberBack'] ?? null]);
    ok(($R['lcHidden0'] ?? false) && ($R['lcHidden1'] ?? false) && ($R['lcKept'] ?? '') === 'LC-7788', 'LC off hides its fields without losing what was typed', $R);
    ok(($R['dueInv'] ?? '') === '28 Oct 2026', 'counting from the invoice date (28 Sep + 30) moves the due date at once', $R['dueInv'] ?? null);
    ok(($R['ivMax'] ?? []) === [3, true], 'a third invoice / GD can be added, not a fourth', $R['ivMax'] ?? null);
    ok(($R['errors'] ?? ['?']) === [], 'no javascript error', $R['errors'] ?? null);
}

echo "\n" . ($F === 0 ? "ALL PASS   ($P checks)\n" : "FAILED  $F   (" . ($P + $F) . " checks)\n");
exit($F === 0 ? 0 : 1);
