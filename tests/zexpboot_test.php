<?php
/* BOOT EVERY NEW EXPORT PAGE AND FAIL ON ANY JAVASCRIPT ERROR.
 *
 * Reading a page is not enough. Twice in this project a page was read,
 * approved and shipped, and then crashed at load — once because a <select>
 * had become a hidden input while eighty lines still drove it as a select,
 * and once because two functions were called seven times and never defined.
 * Neither fault was visible in the source to a careful reader. Both were
 * obvious the instant the page actually ran.
 *
 * So this executes the REAL files — not fragments, not copies — under PHP
 * with stubbed includes, loads each one in Chromium, and fails if the page
 * throws anything at all. It also exercises the two calculators that run in
 * the browser, because a wrong number shown while typing is worse than no
 * number at all.
 */

$B = __DIR__ . '/app_src/public_html/';
$P = 0; $F = 0;
function ok($c, $m) { global $P, $F; if ($c) { $P++; } else { $F++; echo "  FAIL: $m\n"; } }
function head($n) { echo "\n$n\n"; }

$work = __DIR__ . '/.zexpboot';
exec('rm -rf ' . escapeshellarg($work));
@mkdir($work . '/includes', 0777, true);
@mkdir($work . '/assets/js', 0777, true);
@mkdir($work . '/assets/css', 0777, true);

$PAGES = [
    'shipment_logistics.php' => 'logistics',
    'shipment_payments.php'  => 'payments',
    'shipment_costs.php'     => 'costs',
    'shipment_documents.php' => 'documents',
    'shipment_board.php'     => 'board',
    'exp_settings.php'       => 'masters',
    'exp_providers.php'      => 'providers',
];
foreach (array_keys($PAGES) as $p) copy($B . $p, $work . '/' . $p);

/* The real libraries, so the page is wired to what it is actually wired to. */
copy($B . 'includes/export.php',  $work . '/includes/export.php');
copy($B . 'includes/storage.php', $work . '/includes/storage.php');
foreach (['lov.js', 'grid.js'] as $j) if (is_file($B . 'assets/js/' . $j)) copy($B . 'assets/js/' . $j, $work . '/assets/js/' . $j);
foreach (['lov.css', 'zskin.css'] as $c) if (is_file($B . 'assets/css/' . $c)) copy($B . 'assets/css/' . $c, $work . '/assets/css/' . $c);

/* ------------------------------------------------------------------ stubs
   Only what these pages call. The database answers with realistic rows so
   the page draws real dropdowns and a real ledger rather than an empty
   skeleton — an empty page proves much less. */
$bootstrap = <<<'PHP'
<?php
session_start();
$_SESSION['user_id'] = 1;
$config = ['max_upload_mb' => 20, 'upload_dir' => sys_get_temp_dir()];

function e($s){ return htmlspecialchars((string)$s, ENT_QUOTES); }
function require_login(){}
function require_admin(){}
function verify_csrf(){}
function csrf_token(){ return 'testtoken'; }
function csrf_field(){ return '<input type="hidden" name="_csrf" value="testtoken">'; }
function is_admin(){ return true; }
function is_colleague(){ return false; }
function is_staff(){ return false; }
function is_production_staff(){ return false; }
function can_see_rates(){ return true; }
function can_view_shipment($id){ return true; }
function assigned_shipment_ids(){ return ['ALL']; }
function current_user(){ return ['id' => 1, 'role' => 'admin', 'password_hash' => '']; }
function zu_can($m, $a, $u = null){ return true; }
function zu_actions(){ return ['v'=>['n'=>'View'],'c'=>['n'=>'Create'],'u'=>['n'=>'Update'],'d'=>['n'=>'Delete'],'p'=>['n'=>'Post'],'r'=>['n'=>'Reverse']]; }
function redirect($u){ echo "\n__REDIRECT__ " . $u . " || " . ($_SESSION['error'] ?? '') . "\n"; exit; }
function flash(){}
function audit_log($a, $b, $c, $d, $f, $g = ''){}
function num_fmt($n, $d = 3){ return number_format((float)$n, $d); }
function money_fmt($n, $c = 'USD'){ return $c . ' ' . number_format((float)$n, 2); }
function trim_num($n, $d = 3){ return rtrim(rtrim(number_format((float)$n, $d, '.', ''), '0'), '.'); }
function short_ref($r){ return (string)$r; }
function status_badge($s){ return '<span class="xpill b">' . e($s) . '</span>'; }
function post_array($k){ return isset($_POST[$k]) && is_array($_POST[$k]) ? $_POST[$k] : []; }
function fx_get_rates(){ return ['PKR' => 1.0, 'USD' => 0.00359, 'EUR' => 0.0033, 'GBP' => 0.0028]; }
function fx_last_synced_at(){ return null; }
function inv_parties($t = '', $a = true){ return [['id'=>5,'code'=>'SUP-0001','name'=>'Al-Madina Transport','party_type'=>'supplier']]; }

function page_header($t){
  echo "<!doctype html><html><head><meta charset=\"utf-8\"><title>" . e($t) . "</title>";
  echo "<style>.topbar{display:flex;justify-content:space-between}.btn{padding:8px}.lead{color:#5a6b82}.zbtn{padding:8px}</style>";
  echo "</head><body><div class=\"content\">";
}
function page_footer(){ echo "</div></body></html>"; }

/* ------------------------------------------------------------------ fake DB
   Matched on a fragment of the SQL. Anything unmatched returns nothing, which
   is itself a test: the page must survive a table with no rows in it. */
final class BStmt {
    public array $p = [];
    public function __construct(public string $sql, public array $rows) {}
    public function execute($a = []){ $this->p = (array)$a; return true; }
    public function fetch(){ return $this->rows[0] ?? false; }
    public function fetchAll(){ return $this->rows; }
    public function fetchColumn($i = 0){
        $r = $this->rows[0] ?? null;
        if ($r === null) return 0;
        return is_array($r) ? (array_values($r)[$i] ?? 0) : $r;
    }
}
final class BDb {
    public array $A = [];
    public function exec($s){ return 1; }
    public function prepare($s){ return new BStmt($s, $this->m($s)); }
    public function query($s){ return new BStmt($s, $this->m($s)); }
    public function beginTransaction(){ return true; }
    public function commit(){ return true; }
    public function rollBack(){ return true; }
    public function inTransaction(){ return false; }
    public function lastInsertId(){ return 99; }
    private function m($s){
        foreach ($this->A as $needle => $rows) if (stripos($s, (string)$needle) !== false) return $rows;
        return [];
    }
}
$GLOBALS['BDB'] = new BDb();
function db(){ return $GLOBALS['BDB']; }

$GLOBALS['BDB']->A = [
  "FROM shipments WHERE id=" => [[
    'id' => 41, 'invoice_no' => 'ZAS/5191', 'invoice_date' => '2026-09-20',
    'buyer_name' => 'ABC Trading', 'buyer_address' => 'Antwerp', 'buyer_country' => 'Belgium',
    'destination_port' => 'ANTWERP', 'po_no' => 'PO-7781', 'bl_container_no' => 'OLD/COMBINED/VALUE',
    'currency' => 'USD', 'payment_terms' => 'LC 60 Days', 'incoterm' => 'FOB',
    'status' => 'approved_locked', 'logistics_status' => 'In Transit',
    'optional_column_enabled' => 0, 'optional_column_title' => null,
    'revision_no' => 2, 'total_amount' => 50000.00, 'total_qty' => 12000,
    'total_packages' => 1230, 'total_net_weight' => 18400, 'total_gross_weight' => 19200,
    'reopen_status' => null, 'reopen_reason' => null, 'bank_id' => 1,
  ]],
  "FROM exp_masters WHERE kind=?" => [
    ['id'=>1,'kind'=>'x','label'=>'Port Qasim','code'=>null,'sort_order'=>10,'is_active'=>1,'flags'=>null],
    ['id'=>2,'kind'=>'x','label'=>'Ocean Freight','code'=>null,'sort_order'=>20,'is_active'=>1,'flags'=>null],
    ['id'=>3,'kind'=>'x','label'=>'Local Commission','code'=>null,'sort_order'=>30,'is_active'=>1,'flags'=>'{"is_commission":1}'],
    ['id'=>4,'kind'=>'x','label'=>'BL','code'=>null,'sort_order'=>40,'is_active'=>1,'flags'=>'{"supports_draft_final":1}'],
  ],
  "FROM exp_banks" => [[
    'id'=>1,'bank_name'=>'Meezan Bank Limited','branch'=>'Faisalabad','account_title'=>'ZAS Textile',
    'account_no'=>'','iban'=>'PK00 MEZN','swift'=>'MEZNPKKA','currency'=>'USD','sort_order'=>10,'is_active'=>1,
  ]],
  "FROM exp_providers" => [[
    'id'=>7,'name'=>'ABC Logistics','city'=>'Karachi','phone'=>'021','email'=>'','ntn'=>'',
    'address'=>'','inv_party_id'=>null,'notes'=>'','is_active'=>1,
  ]],
  "FROM exp_provider_roles" => [['provider_id'=>7,'role'=>'freight_forwarder']],
  "FROM shipment_logistics" => [[
    'shipment_id'=>41,'loading_port_id'=>1,'shipping_line_id'=>null,'vessel_name'=>'MAERSK KOWLOON',
    'voyage_no'=>'241W','expected_load_date'=>'2026-10-15','actual_load_date'=>'2026-10-16',
    'etd_pakistan'=>'2026-10-18','eta_destination'=>'2026-11-09','actual_arrival_date'=>null,
    'transit_days'=>22,'bl_no'=>'MAEU240817221','bl_date'=>'2026-10-20','bl_stage'=>'final',
    'freight_provider_id'=>7,'freight_agreed_amount'=>2800.00,'freight_agreed_currency'=>'USD',
    'freight_quote_ref'=>'Q-8871','freight_quote_date'=>'2026-09-30',
    'last_event'=>'Loaded on vessel','last_location'=>'Jebel Ali','tracking_source'=>null,
    'tracking_updated_at'=>null,'notes'=>'','updated_by'=>1,'updated_at'=>null,
  ]],
  "FROM shipment_containers WHERE shipment_id=? ORDER BY" => [
    ['id'=>11,'shipment_id'=>41,'container_no'=>'SEKU6489931','container_type_id'=>1,'seal_no'=>'PK887412',
     'vgm_kg'=>21400,'tare_kg'=>3800,'cartons'=>620,'notes'=>'','created_by'=>1,'created_at'=>null],
    ['id'=>12,'shipment_id'=>41,'container_no'=>'MSCU1142876','container_type_id'=>1,'seal_no'=>'PK887413',
     'vgm_kg'=>21100,'tare_kg'=>3800,'cartons'=>610,'notes'=>'','created_by'=>1,'created_at'=>null],
  ],
  "FROM shipment_container_cartons" => [
    ['id'=>1,'container_id'=>11,'shipment_id'=>41,'carton_from'=>1,'carton_to'=>620],
    ['id'=>2,'container_id'=>12,'shipment_id'=>41,'carton_from'=>621,'carton_to'=>1230],
  ],
  "COALESCE(SUM(cartons),0)" => [['c'=>1230]],
  "COALESCE(total_packages,0)" => [['p'=>1230]],
  "FROM shipment_payments WHERE shipment_id=? ORDER BY" => [
    ['id'=>1,'shipment_id'=>41,'paid_on'=>'2026-09-10','amount'=>10000.00,'currency'=>'USD','method_id'=>1,
     'bank_id'=>1,'reference'=>'TT-889211','pkr_credited'=>2785000,'pkr_rate'=>278.5,'notes'=>'',
     'proof_doc_id'=>null,'is_void'=>0,'void_reason'=>null,'voided_by'=>null,'voided_at'=>null,
     'created_by'=>1,'created_at'=>null,'updated_by'=>null,'updated_at'=>null],
    ['id'=>2,'shipment_id'=>41,'paid_on'=>'2026-09-28','amount'=>20000.00,'currency'=>'USD','method_id'=>1,
     'bank_id'=>1,'reference'=>'SWIFT-4471','pkr_credited'=>5610000,'pkr_rate'=>280.5,'notes'=>'',
     'proof_doc_id'=>null,'is_void'=>0,'void_reason'=>null,'voided_by'=>null,'voided_at'=>null,
     'created_by'=>1,'created_at'=>null,'updated_by'=>null,'updated_at'=>null],
    ['id'=>3,'shipment_id'=>41,'paid_on'=>'2026-09-29','amount'=>5000.00,'currency'=>'USD','method_id'=>1,
     'bank_id'=>1,'reference'=>'DUPLICATE','pkr_credited'=>null,'pkr_rate'=>null,'notes'=>'',
     'proof_doc_id'=>null,'is_void'=>1,'void_reason'=>'entered twice','voided_by'=>1,'voided_at'=>null,
     'created_by'=>1,'created_at'=>null,'updated_by'=>null,'updated_at'=>null],
  ],
  "COALESCE(SUM(amount),0) s, COUNT(*) n" => [['s'=>30000.00,'n'=>2]],
  "FROM shipment_costs WHERE shipment_id=?" => [
    ['id'=>1,'shipment_id'=>41,'cost_type_id'=>2,'provider_id'=>7,'bill_no'=>'FR-8871','bill_date'=>'2026-10-22',
     'currency'=>'USD','amount'=>3150.00,'fx_rate'=>281.2,'pkr_amount'=>885780.00,'is_paid'=>1,
     'paid_on'=>'2026-10-25','notes'=>'','doc_id'=>null,'is_void'=>0,'void_reason'=>null,'voided_by'=>null,
     'voided_at'=>null,'created_by'=>1,'created_at'=>null,'updated_by'=>null,'updated_at'=>null],
    ['id'=>2,'shipment_id'=>41,'cost_type_id'=>2,'provider_id'=>7,'bill_no'=>'CL-2201','bill_date'=>'2026-10-23',
     'currency'=>'PKR','amount'=>85000.00,'fx_rate'=>1,'pkr_amount'=>85000.00,'is_paid'=>0,
     'paid_on'=>null,'notes'=>'','doc_id'=>null,'is_void'=>0,'void_reason'=>null,'voided_by'=>null,
     'voided_at'=>null,'created_by'=>1,'created_at'=>null,'updated_by'=>null,'updated_at'=>null],
  ],
  "SELECT currency, SUM(amount) a" => [['currency'=>'USD','a'=>3150.00,'p'=>885780.00,'r'=>281.2,'b'=>'FR-8871','n'=>1]],
  "FROM shipment_documents WHERE shipment_id=?" => [
    ['id'=>1,'shipment_id'=>41,'doc_type_id'=>4,'doc_no'=>'MAEU240817221','stage'=>'final','version'=>1,
     'original_name'=>'BL final signed.pdf','storage_driver'=>'local','storage_key'=>'x.pdf',
     'mime_type'=>'application/pdf','file_size'=>284000,'superseded_by'=>null,'is_archived'=>0,
     'archive_reason'=>null,'archived_by'=>null,'archived_at'=>null,'notes'=>'','uploaded_by'=>1,
     'uploaded_at'=>'2026-10-20 11:04:00'],
    ['id'=>2,'shipment_id'=>41,'doc_type_id'=>4,'doc_no'=>null,'stage'=>'draft','version'=>2,
     'original_name'=>'BL draft v2.pdf','storage_driver'=>'r2','storage_key'=>'y.pdf',
     'mime_type'=>'application/pdf','file_size'=>190000,'superseded_by'=>null,'is_archived'=>0,
     'archive_reason'=>null,'archived_by'=>null,'archived_at'=>null,'notes'=>'','uploaded_by'=>1,
     'uploaded_at'=>'2026-10-18 09:30:00'],
    ['id'=>3,'shipment_id'=>41,'doc_type_id'=>4,'doc_no'=>null,'stage'=>'draft','version'=>1,
     'original_name'=>'BL draft v1.pdf','storage_driver'=>'local','storage_key'=>'z.pdf',
     'mime_type'=>'application/pdf','file_size'=>188000,'superseded_by'=>2,'is_archived'=>0,
     'archive_reason'=>null,'archived_by'=>null,'archived_at'=>null,'notes'=>'','uploaded_by'=>1,
     'uploaded_at'=>'2026-10-17 15:00:00'],
  ],
  /* Two old attachments: one already brought in, one still waiting for a
     type. Both must render, and only the waiting one may offer the form. */
  "FROM shipment_files f" => [
    ['id'=>31,'original_name'=>'old buyer PO scan.pdf','stored_name'=>'41_aaa.pdf',
     'mime_type'=>'application/pdf','file_size'=>120000,'uploaded_by'=>1,
     'created_at'=>'2026-08-02 10:00:00','imported_as'=>null],
    ['id'=>32,'original_name'=>'old insurance cert.pdf','stored_name'=>'41_bbb.pdf',
     'mime_type'=>'application/pdf','file_size'=>96000,'uploaded_by'=>1,
     'created_at'=>'2026-08-05 12:00:00','imported_as'=>8],
  ],
  "SELECT COUNT(*) FROM shipments s" => [['n'=>3]],
  "SELECT s.id, s.invoice_no, s.invoice_date, s.buyer_name" => [[
    'id'=>41,'invoice_no'=>'ZAS/5191','invoice_date'=>'2026-09-20','buyer_name'=>'ABC Trading',
    'buyer_country'=>'Belgium','destination_port'=>'ANTWERP','currency'=>'USD','total_amount'=>50000.00,
    'logistics_status'=>'In Transit','vessel_name'=>'MAERSK KOWLOON','expected_load_date'=>'2026-10-15',
    'etd_pakistan'=>'2026-10-18','eta_destination'=>'2026-11-09','actual_load_date'=>'2026-10-16',
    'actual_arrival_date'=>null,'bl_no'=>'MAEU240817221',
  ]],
  "shipment_id, COALESCE(SUM(amount),0) s" => [['shipment_id'=>41,'s'=>30000.00]],
  "FROM exp_meta WHERE k='schema_version'" => [['v'=>'1']],
];
PHP;
file_put_contents($work . '/includes/bootstrap.php', $bootstrap);

/* exp_providers.php requires includes/inventory.php for the one party list it
   offers as a link target. inv_parties() is stubbed in bootstrap above, so
   this only has to exist — and the fact that it can be EMPTY is itself worth
   knowing: the export module reads one function from the inventory module and
   nothing else. */
file_put_contents($work . '/includes/inventory.php', "<?php\n/* stub: the export module needs inv_parties() only, stubbed in bootstrap */\n");

/* ------------------------------------------------- render each page with PHP */
head('1. Every page renders under PHP without a fatal error');

$html = [];
foreach ($PAGES as $page => $slug) {
    $cmd = 'cd ' . escapeshellarg($work) . ' && php -d error_reporting=E_ALL -d display_errors=1 '
         . '-r ' . escapeshellarg('$_GET=["id"=>41,"view"=>"transit"]; $_SERVER["REQUEST_METHOD"]="GET"; include "' . $page . '";')
         . ' 2>&1';
    $out = (string)shell_exec($cmd);
    $html[$slug] = $out;

    $fatal = stripos($out, 'Fatal error') !== false || stripos($out, 'Parse error') !== false;
    ok(!$fatal, "$page had a PHP fatal/parse error:\n" . substr($out, 0, 700));

    $warn = stripos($out, 'Warning:') !== false || stripos($out, 'Deprecated:') !== false;
    ok(!$warn, "$page emitted a PHP warning/deprecation:\n" . substr($out, 0, 700));

    ok(stripos($out, '__REDIRECT__') === false, "$page redirected away instead of rendering: " . substr($out, 0, 300));
    ok(strlen($out) > 500, "$page produced almost no output (" . strlen($out) . " bytes)");
    ok(str_contains($out, '</html>'), "$page did not finish rendering");

    file_put_contents($work . '/' . $slug . '.html', $out);
}

/* ----------------------------------------- what the rendered HTML must say */
head('2. The pages drew what they were supposed to draw');

ok(str_contains($html['logistics'], 'MAERSK KOWLOON'), 'logistics did not show the vessel');
ok(str_contains($html['logistics'], 'SEKU6489931') && str_contains($html['logistics'], 'MSCU1142876'),
   'logistics did not list both containers');
ok(str_contains($html['logistics'], '1 – 620') || str_contains($html['logistics'], '1 &ndash; 620'),
   'logistics did not show the carton range');
ok(str_contains($html['logistics'], 'match the packing list'),
   'logistics did not report the carton cross-check');
ok(str_contains($html['logistics'], 'OLD/COMBINED/VALUE'),
   'the old combined BL/container value was not shown read-only');
ok(!preg_match('~name="destination_port"~', $html['logistics']),
   'logistics offered a second destination_port field, which would duplicate the invoice');

/* 50,000 invoice, 30,000 received (the third payment is void) → 20,000 left. */
ok(str_contains($html['payments'], 'USD 20,000.00'), 'payments did not show the 20,000 balance');
ok(str_contains($html['payments'], 'PARTIALLY PAID'), 'payments did not show PARTIALLY PAID');
ok(str_contains($html['payments'], 'entered twice'), 'the voided payment lost its reason');
ok(substr_count($html['payments'], 'Voided') >= 1, 'the voided payment was not marked');
ok(str_contains($html['payments'], 'DUPLICATE'), 'the voided payment row disappeared instead of staying struck through');

ok(str_contains($html['costs'], '885,780') || str_contains($html['costs'], '885780'),
   'costs did not show the PKR freight figure');
ok(str_contains($html['costs'], 'Agreed') && str_contains($html['costs'], 'Final Bill'),
   'the freight comparison block is missing');
ok(str_contains($html['costs'], '+USD 350.00') || str_contains($html['costs'], '350.00'),
   'the freight variance was not computed');

ok(str_contains($html['documents'], 'BL draft v2.pdf'), 'documents did not list version 2');
ok(str_contains($html['documents'], 'superseded'), 'the superseded version was not marked');
ok(str_contains($html['documents'], 'Current: V'), 'the current version was not identified');
ok(str_contains($html['documents'], 'shipment_doc_file.php?doc='),
   'documents did not link downloads through the permission-checked endpoint');
ok(!preg_match('~r2\.cloudflarestorage\.com~', $html['documents']),
   'a raw R2 URL leaked into the page');
ok(!preg_match('~storage_key~', $html['documents']) || !str_contains($html['documents'], 'y.pdf'),
   'the object storage key leaked into the page');

/* The old attachments, and the one-way door into the document system. */
ok(str_contains($html['documents'], 'old buyer PO scan.pdf'), 'the legacy file was not listed');
ok(str_contains($html['documents'], 'Files attached before the document system'),
   'the legacy group heading is missing');
ok(str_contains($html['documents'], 'download_file.php?id=31'),
   'the legacy file does not download through the original endpoint');
ok(str_contains($html['documents'], 'import_legacy'),
   'there is no way to bring a legacy file in');
ok(str_contains($html['documents'], 'brought in'),
   'the already-imported file is not marked as such');
/* Exactly one form, for the one file still waiting. */
ok(substr_count($html['documents'], 'name="file_id"') === 1,
   'the Bring in form should appear once, only for the file without a type — got '
   . substr_count($html['documents'], 'name="file_id"'));
ok(str_contains($html['documents'], 'Needs a type'), 'the pending count badge is missing');

ok(str_contains($html['board'], 'Booking Pending') && str_contains($html['board'], 'Final BL Pending'),
   'the board is missing its indicators');
ok(str_contains($html['masters'], 'Port Qasim'), 'the masters screen did not list its rows');
ok(str_contains($html['masters'], 'Commission'), 'the commission flag was not shown on a cost type');
ok(str_contains($html['providers'], 'ABC Logistics'), 'the providers screen did not list the provider');
ok(str_contains($html['providers'], 'Freight Forwarder'), 'the provider role chip is missing');

/* The tab strip must appear, and must not offer a tab to a blocked area. */
foreach (['logistics', 'payments', 'costs', 'documents'] as $slug) {
    ok(str_contains($html[$slug], 'class="xtabs"'), "$slug did not draw the tab strip");
    ok(str_contains($html[$slug], 'shipment_logistics.php?id=41'), "$slug tab strip has no logistics link");
}

/* ------------------------------------------------------- now run it in Chromium */
head('3. Chromium loads every page with no JavaScript error at all');

$drive = <<<'JS'
const { chromium } = require('playwright');
const path = require('path');
const fs = require('fs');

(async () => {
  const work = process.argv[2];
  const pages = process.argv[3].split(',');
  const out = {};
  const br = await chromium.launch({ executablePath: '/opt/pw-browsers/chromium' });

  for (const slug of pages) {
    const pg = await br.newPage();
    const errs = [];
    pg.on('pageerror', e => errs.push('pageerror: ' + e.message));
    pg.on('console', m => { if (m.type() === 'error') {
      const t = m.text();
      /* A missing favicon or a blocked file:// fetch is this harness, not the
         app. Anything else counts. */
      if (!/favicon|ERR_FILE_NOT_FOUND|ERR_FAILED|net::/i.test(t)) errs.push('console: ' + t);
    }});

    await pg.goto('file://' + path.join(work, slug + '.html'), { waitUntil: 'load' });
    await pg.waitForTimeout(120);

    const r = { errors: errs, probes: {} };

    if (slug === 'costs') {
      /* The PKR preview. 3150 USD at 281.2 must read 885,780.00 — if this
         calculator is wrong, the operator sees a number that is not the one
         about to be stored. */
      const has = await pg.$('#amtIn');
      if (has) {
        await pg.fill('#curIn', 'USD').catch(() => {});
        await pg.selectOption('#curIn', 'USD').catch(() => {});
        await pg.fill('#amtIn', '3150');
        await pg.fill('#rateIn', '281.2');
        await pg.dispatchEvent('#rateIn', 'input');
        await pg.waitForTimeout(60);
        r.probes.pkr = await pg.inputValue('#pkrOut');

        /* PKR must force a rate of 1 and mirror the amount. */
        await pg.selectOption('#curIn', 'PKR').catch(() => {});
        await pg.fill('#rateIn', '');
        await pg.fill('#amtIn', '85000');
        await pg.dispatchEvent('#amtIn', 'input');
        await pg.waitForTimeout(60);
        r.probes.pkrNative = await pg.inputValue('#pkrOut');
      }
    }

    if (slug === 'payments') {
      /* Typing more than the balance must warn, and typing within it must not. */
      const has = await pg.$('#amtIn');
      if (has) {
        await pg.fill('#amtIn', '15000');
        await pg.dispatchEvent('#amtIn', 'input');
        await pg.waitForTimeout(60);
        r.probes.warnWithin = await pg.evaluate(() => {
          const w = document.getElementById('payWarn');
          return w ? w.style.display : 'missing';
        });
        await pg.fill('#amtIn', '99999');
        await pg.dispatchEvent('#amtIn', 'input');
        await pg.waitForTimeout(60);
        r.probes.warnOver = await pg.evaluate(() => {
          const w = document.getElementById('payWarn');
          return w ? w.style.display : 'missing';
        });
      }
    }

    if (slug === 'documents') {
      /* The Stage control must appear only for a type that has draft/final,
         and the next version must be announced before upload. */
      const sel = await pg.$('#typeIn');
      if (sel) {
        await pg.selectOption('#typeIn', { label: 'BL' }).catch(() => {});
        await pg.waitForTimeout(60);
        r.probes.stageShown = await pg.evaluate(() => {
          const w = document.getElementById('stageWrap');
          return w ? w.style.display : 'missing';
        });
        r.probes.verNote = await pg.evaluate(() => {
          const n = document.getElementById('verNote');
          return n ? n.textContent.trim() : 'missing';
        });
        await pg.selectOption('#typeIn', { label: 'Ocean Freight' }).catch(() => {});
        await pg.waitForTimeout(60);
        r.probes.stageHidden = await pg.evaluate(() => {
          const w = document.getElementById('stageWrap');
          return w ? w.style.display : 'missing';
        });
      }
    }

    out[slug] = r;
    await pg.close();
  }

  await br.close();
  console.log(JSON.stringify(out));
})().catch(e => { console.log(JSON.stringify({ __harness: String(e) })); });
JS;
file_put_contents($work . '/drive.js', $drive);

$raw = (string)shell_exec('node ' . escapeshellarg($work . '/drive.js') . ' '
       . escapeshellarg($work) . ' ' . escapeshellarg(implode(',', $PAGES)) . ' 2>&1');
$res = json_decode(trim((string)preg_replace('~^.*?(\{.*\})\s*$~s', '$1', $raw)), true);

if (!is_array($res) || isset($res['__harness'])) {
    ok(false, 'the browser harness itself did not run: ' . substr($raw, 0, 500));
} else {
    foreach ($PAGES as $page => $slug) {
        $errs = $res[$slug]['errors'] ?? ['no result'];
        ok(count($errs) === 0, "$page threw in the browser: " . implode(' | ', $errs));
    }

    head('4. The two in-browser calculators give the right numbers');

    $p = $res['costs']['probes'] ?? [];
    ok(($p['pkr'] ?? '') === '885780.00',
       'USD 3,150 at 281.2 should preview 885780.00, got ' . var_export($p['pkr'] ?? null, true));
    ok(($p['pkrNative'] ?? '') === '85000.00',
       'a PKR bill should preview itself at rate 1, got ' . var_export($p['pkrNative'] ?? null, true));

    $p = $res['payments']['probes'] ?? [];
    ok(($p['warnWithin'] ?? '') === 'none',
       'a payment inside the balance should not warn, got ' . var_export($p['warnWithin'] ?? null, true));
    ok(($p['warnOver'] ?? '') === 'block',
       'a payment over the balance should warn, got ' . var_export($p['warnOver'] ?? null, true));

    head('5. The document form reacts to the type chosen');

    $p = $res['documents']['probes'] ?? [];
    ok(($p['stageShown'] ?? '') === 'block',
       'Stage should appear for a draft/final type, got ' . var_export($p['stageShown'] ?? null, true));
    ok(($p['stageHidden'] ?? '') === 'none',
       'Stage should hide for a type without draft/final, got ' . var_export($p['stageHidden'] ?? null, true));
    ok(str_contains((string)($p['verNote'] ?? ''), 'version 3'),
       'uploading another BL draft should announce version 3, got ' . var_export($p['verNote'] ?? null, true));
}

exec('rm -rf ' . escapeshellarg($work));
echo "\n$P passed, $F failed\n";
exit($F > 0 ? 1 : 0);
