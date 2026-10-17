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
copy($B . 'includes/storage.php', $work . '/includes/storage.php');

$schema = <<<'SQL'
CREATE TABLE exp_meta (k TEXT PRIMARY KEY, v TEXT, updated_at TEXT);
INSERT INTO exp_meta VALUES ('imp_schema_version', '1', NULL);
CREATE TABLE exp_masters (id INTEGER PRIMARY KEY AUTOINCREMENT, kind TEXT, label TEXT, code TEXT, sort_order INT DEFAULT 0, is_active INT DEFAULT 1, flags TEXT, UNIQUE(kind, label));
CREATE TABLE exp_providers (id INTEGER PRIMARY KEY AUTOINCREMENT, name TEXT, is_active INT DEFAULT 1);
CREATE TABLE exp_provider_roles (id INTEGER PRIMARY KEY AUTOINCREMENT, provider_id INT, role TEXT);
CREATE TABLE exp_banks (id INTEGER PRIMARY KEY AUTOINCREMENT, bank_name TEXT, currency TEXT, sort_order INT DEFAULT 0, is_active INT DEFAULT 1);
CREATE TABLE users (id INTEGER PRIMARY KEY, name TEXT, role TEXT, is_active INT DEFAULT 1, password_hash TEXT);
CREATE TABLE inv_parties (id INTEGER PRIMARY KEY AUTOINCREMENT, code TEXT, name TEXT, party_type TEXT, is_active INT DEFAULT 1);
CREATE TABLE inv_materials (id INTEGER PRIMARY KEY AUTOINCREMENT, code TEXT, name TEXT, is_active INT DEFAULT 1);
CREATE TABLE imp_imports (id INTEGER PRIMARY KEY AUTOINCREMENT, ref TEXT NOT NULL UNIQUE, employee TEXT, supplier_party_id INT, supplier_name TEXT NOT NULL DEFAULT '',
  origin_country TEXT, shipped_from TEXT, contract_no TEXT, contract_date TEXT, incoterm TEXT, incoterm_place TEXT, currency TEXT NOT NULL DEFAULT 'USD',
  import_type TEXT NOT NULL DEFAULT 'ordinary', pay_method TEXT, advance_pct REAL NOT NULL DEFAULT 0, credit INT NOT NULL DEFAULT 0, credit_days INT NOT NULL DEFAULT 0,
  maturity_basis TEXT NOT NULL DEFAULT 'bl', fixed_due_date TEXT, due_override TEXT, due_override_reason TEXT,
  lc INT NOT NULL DEFAULT 0, lc_no TEXT, lc_bank TEXT, lc_expiry TEXT, lc_last_ship TEXT,
  fta INT NOT NULL DEFAULT 0, fta_agreement TEXT, fta_form TEXT, fta_cert_no TEXT, fta_cert_date TEXT,
  commission INT NOT NULL DEFAULT 0, com_beneficiary TEXT, com_rate_pct REAL NOT NULL DEFAULT 0,
  is_opening INT NOT NULL DEFAULT 0, already_in_stock INT NOT NULL DEFAULT 0, efs_months INT NOT NULL DEFAULT 9, alloc_basis TEXT NOT NULL DEFAULT 'kg',
  manual_status TEXT, notes TEXT, created_by INT, created_at TEXT, updated_by INT, updated_at TEXT);
CREATE TABLE imp_invoices (id INTEGER PRIMARY KEY AUTOINCREMENT, import_id INT NOT NULL, sort_order INT NOT NULL DEFAULT 0, invoice_no TEXT, invoice_date TEXT, gd_no TEXT, gd_date TEXT);
CREATE TABLE imp_lines (id INTEGER PRIMARY KEY AUTOINCREMENT, import_id INT NOT NULL, invoice_id INT, sort_order INT NOT NULL DEFAULT 0, category TEXT NOT NULL DEFAULT 'Other',
  material_id INT, product_id INT, description TEXT NOT NULL DEFAULT '', hs_code TEXT, supplier_ref TEXT, bill_unit TEXT NOT NULL DEFAULT 'kg',
  bill_qty REAL NOT NULL DEFAULT 0, rate REAL NOT NULL DEFAULT 0, net_kg REAL NOT NULL DEFAULT 0, gross_kg REAL NOT NULL DEFAULT 0, packages INT NOT NULL DEFAULT 0,
  specs TEXT, efs_valuation REAL, efs_waste_pct REAL, efs_va_pct REAL);
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
  months INT NOT NULL DEFAULT 9, notes TEXT, created_by INT, created_at TEXT, updated_by INT, updated_at TEXT);
CREATE TABLE imp_settings (k TEXT PRIMARY KEY, v TEXT, updated_at TEXT);
INSERT INTO exp_masters (kind, label, sort_order, flags) VALUES ('imp_doc_type', 'Supplier Commercial Invoice', 10, NULL), ('imp_doc_type', 'BL', 20, NULL),
  ('imp_cost_type', 'Ocean Freight', 10, NULL), ('imp_cost_type', 'Clearing Agent Service', 20, NULL), ('imp_cost_type', 'Customs Duty & Taxes (via agent)', 30, '{"is_duty":1}'),
  ('shipping_line', 'COSCO', 10, NULL), ('container_type', '40''HC', 10, NULL), ('port_loading', 'KICT', 10, NULL);
INSERT INTO exp_providers (name) VALUES ('Sea Hawk Logistics'); INSERT INTO exp_provider_roles (provider_id, role) VALUES (1, 'freight_forwarder');
INSERT INTO exp_banks (bank_name, currency) VALUES ('Meezan Bank', 'USD');
INSERT INTO inv_parties (code, name, party_type) VALUES ('SUP-0001', 'Shaoxing Huajie Textiles', 'supplier');
INSERT INTO inv_materials (code, name) VALUES ('MAT-0001', 'Polyester fiber 1.4D');
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
            'specs_json' => ['{"denier":"7D","cut":"64 mm"}', '', '{"thickness":"0.35 mm"}'], 'invoice_id' => [$iv, $iv, $iv], 'material_id' => ['1', '0', '0']];
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
$noLine = $ln2; foreach (['line_id', 'category', 'description', 'hs_code', 'supplier_ref', 'bill_unit', 'bill_qty', 'rate', 'net_kg', 'gross_kg', 'packages', 'specs_json', 'invoice_id', 'material_id'] as $k) array_shift($noLine[$k]);
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
ok(noWarn(page('imp_phase.php', ['id' => $id, 'tab' => 'gatein'])) && str_contains(page('imp_phase.php', ['id' => $imp2['id'], 'tab' => 'gatein']), 'already in stock'), 'Gate In (Phase 2) says when an opening import is already in stock');
/* delete: never with money on it */
ok(str_contains(said(page('imp_view.php', ['id' => $id], $T + ['action' => 'delete', 'import_id' => $id, 'confirm_ref' => $imp['ref']]), 'error'), 'payments'), 'an import with payments on it cannot be deleted');
$out = page('imp_view.php', ['id' => $imp2['id']], $T + ['action' => 'delete', 'import_id' => $imp2['id'], 'confirm_ref' => $imp2['ref']]);
ok(str_contains(said($out), 'deleted') && !$q("SELECT id FROM imp_imports WHERE id=?", [$imp2['id']]), 'an empty one can be, typing its reference');

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
