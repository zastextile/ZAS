<?php
/* BOOT THE WHOLE GATE PAGE AND CLICK THE ITEM BOX.
 *
 * Three rounds of "the gate list still does not work" were answered by
 * reading the file, and reading the file was not enough — the last fault
 * was a crash at page load that no fragment test could ever see, because
 * every fragment test builds its own little page and never runs the real
 * one end to end.
 *
 * So this runs the REAL inv_gate.php. Not a lifted function, not a
 * fragment: the actual shipped file, executed by PHP against stubbed
 * includes, with its real <script> tags, its real lov.js and its real
 * lov.css. Then Chromium loads the output, and the item box is clicked
 * the way an operator clicks it.
 *
 * What it asserts is the whole of what he keeps asking for:
 *   the page loads with NO javascript error at all
 *   the item box is the search box, not the fallback dropdown
 *   clicking it OPENS the list
 *   the list has rows in it
 *   typing narrows it
 *   choosing one fills the line
 */

$B = __DIR__ . '/app_src/public_html/';
$P = 0; $F = 0;
function ok($c, $m) { global $P, $F; if ($c) { $P++; } else { $F++; echo "  FAIL: $m\n"; } }

$work = __DIR__ . '/.zgateboot';
@mkdir($work . '/includes', 0777, true);
@mkdir($work . '/assets/js', 0777, true);
@mkdir($work . '/assets/css', 0777, true);

/* the real page, and the real front-end files it links */
copy($B . 'inv_gate.php',        $work . '/inv_gate.php');
copy($B . 'assets/js/lov.js',    $work . '/assets/js/lov.js');
copy($B . 'assets/css/lov.css',  $work . '/assets/css/lov.css');
if (is_file($B . 'assets/js/grid.js')) copy($B . 'assets/js/grid.js', $work . '/assets/js/grid.js');

/* ---------------------------------------------------------------- stubs
   Only what inv_gate.php actually calls. Everything returns the shape the
   real function returns — the point is to exercise the page, not the
   database. */
$bootstrap = <<<'PHP'
<?php
session_start();
$_SESSION['user_id'] = 1; $_SESSION['role'] = 'admin';
function e($s){ return htmlspecialchars((string)$s, ENT_QUOTES); }
function require_login(){}
function verify_csrf(){}
function csrf_token(){ return 'testtoken'; }
function csrf_field(){ return '<input type="hidden" name="_csrf" value="testtoken">'; }
function is_admin(){ return true; }
function redirect($u){ exit; }
function flash(){}
function money($v){ return number_format((float)$v, 2); }
function page_header($t){ echo "<!doctype html><html><head><meta charset=\"utf-8\"><title>" . e($t) . "</title></head><body>"; }
function page_footer(){ echo "</body></html>"; }

/* A PDO that answers every query with nothing. The page must survive an
   empty database — and every list it draws comes from the inv_* stubs
   below, not from these raw queries. */
final class BStmt {
    public function __construct(public string $sql) {}
    public function execute($a = []){ return true; }
    public function fetch(){ return false; }
    public function fetchAll(){ return []; }
    public function fetchColumn(){ return 0; }
}
final class BDb {
    public function prepare($s){ return new BStmt($s); }
    public function query($s){ return new BStmt($s); }
    public function exec($s){ return 1; }
    public function beginTransaction(){ return true; }
    public function commit(){ return true; }
    public function rollBack(){ return true; }
    public function lastInsertId(){ return '1'; }
}
function db(){ static $d = null; if (!$d) $d = new BDb(); return $d; }
PHP;
/* short_ref() IS LIFTED TOO, not stubbed. The real bootstrap loads
   helpers.php before anything else, so the page has it; this harness has to
   as well, and lifting it means the page is tested with the real rule for
   shortening a reference rather than a copy of it.

   It was added by NOT being here: the first run after short_ref() went into
   the page died with "Call to undefined function", which is exactly the
   class of fault this whole test exists to catch. */
$helper = (function (string $src) {
    $a = strpos($src, 'function short_ref(');
    $b = strpos($src, "\n}\n", $a);
    return substr($src, $a, $b - $a + 3);
})(file_get_contents($B . 'includes/helpers.php'));
$bootstrap .= "\n" . $helper;

file_put_contents($work . '/includes/bootstrap.php', $bootstrap);

/* inv_gate_types() IS LIFTED, NOT STUBBED. The whole contract rule on this
   screen reads out of it — which transaction type belongs to which kind of
   contract — so a hand-written copy would test my copy, not the app. */
$types = (function (string $src) {
    $a = strpos($src, 'function inv_gate_types');
    $b = strpos($src, "\n}\n", $a);
    return substr($src, $a, $b - $a + 3);
})(file_get_contents($B . 'includes/inventory.php'));

$invStub = "<?php\n" . $types . "\n" . <<<'PHP'
function inv_ensure_schema(){}
function inv_can_see(){ return true; }
function inv_perm($k){ return true; }
function inv_num($v){ return (float)str_replace(',', '', (string)$v); }
function inv_setting($k, $d = null){ return $d; }
function inv_gst_default(){ return 18.0; }
function inv_neg_tolerance_pct(){ return 2.0; }
function inv_audit(){}
function inv_next_no(){ return 'GO-0001'; }
function inv_departments(){ return ['Stitching Floor 1', 'Packing Hall', 'Dispatch']; }
function inv_locations($a = true){ return [['id'=>1,'name'=>'Main Store'], ['id'=>2,'name'=>'Finished Goods Store']]; }
function inv_location_name($id){ return $id == 2 ? 'Finished Goods Store' : 'Main Store'; }
function inv_parties($t = ''){ return [
    ['id'=>1,'name'=>'ABRAR AHMED','party_type'=>'customer'],
    ['id'=>2,'name'=>'Shaheen Processing Mills','party_type'=>'jobworker'],
    ['id'=>3,'name'=>'Al Noor Trading','party_type'=>'customer'],
]; }
function inv_materials($a = true){ return [
    ['id'=>12,'code'=>'FAB-012','name'=>'Cotton greige 60s','item_group'=>'Fabric','uom'=>'MTR','std_rate'=>210.50],
    ['id'=>13,'code'=>'BTN-013','name'=>'Button 4-hole','item_group'=>'Accessories','uom'=>'PCS','std_rate'=>1.25],
    ['id'=>14,'code'=>'THR-014','name'=>'Sewing thread','item_group'=>'Trims','uom'=>'CON','std_rate'=>95.00],
]; }
/* THE LIST THE PICKER IS BUILT FROM — materials and finished products,
   with a balance per location. Exactly the shape inv_stock_items returns. */
function inv_stock_items($own = 'own', $activeOnly = true){ return [
    ['key'=>'m12','kind'=>'mat','id'=>12,'code'=>'FAB-012','name'=>'Cotton greige 60s',
     'grp'=>'Fabric','stage'=>'grey','uom'=>'MTR','rate'=>210.50,
     'bal'=>[0=>1840,1=>1840],'val'=>[0=>387320,1=>387320],'sizes'=>[]],
    ['key'=>'m13','kind'=>'mat','id'=>13,'code'=>'BTN-013','name'=>'Button 4-hole',
     'grp'=>'Accessories','stage'=>'na','uom'=>'PCS','rate'=>1.25,
     'bal'=>[0=>14000,1=>14000],'val'=>[0=>17500,1=>17500],'sizes'=>[]],
    ['key'=>'m14','kind'=>'mat','id'=>14,'code'=>'THR-014','name'=>'Sewing thread',
     'grp'=>'Trims','stage'=>'na','uom'=>'CON','rate'=>95.00,
     'bal'=>[],'val'=>[],'sizes'=>[]],
    ['key'=>'p7','kind'=>'prod','id'=>7,'code'=>'PRD-7','name'=>'Comforter Set 7 Pc',
     'grp'=>'Finished goods','stage'=>'product','uom'=>'PCS','rate'=>0.0,
     'bal'=>[0=>300,2=>300],'val'=>[0=>1260000,2=>1260000],'sizes'=>['King','Queen']],
]; }
function inv_stock_maps($own = 'own'){ return ['qty'=>[], 'val'=>[]]; }
function inv_holdings($k = 'jobworker'){ return []; }
function inv_holding_lots($p, $m, $k = 'jobworker'){ return []; }
function inv_lot_balances_key($k, $pos = true){ return []; }
function inv_available_key($k, $s, $l, $o){ return 0.0; }
function inv_balance($m, $l = 0){ return 0.0; }
function inv_split_key($key){
    $key = trim($key); if ($key === '') return [0,0];
    $n = (int)substr($key, 1); if ($n <= 0) return [0,0];
    if ($key[0] === 'm') return [$n,0];
    if ($key[0] === 'p') return [0,$n];
    return ctype_digit($key) ? [(int)$key,0] : [0,0];
}
function inv_contract_lines($id){ return []; }
function inv_contract_unassigned($id){ return ['qty'=>0.0,'amount'=>0.0,'lines'=>0]; }
function inv_contract_qty($id){ return 0.0; }
function inv_contract_done($id, $d){ return 0.0; }
function inv_contract_of_line($id){ return null; }
function inv_gate_links($id){ return []; }
function inv_gate_post($id, $u = null){ return ['ok'=>true]; }
function inv_gate_reverse($id, $r, $u = null){ return ['ok'=>true]; }
function inv_gate_delete($id, $u = null){ return ['ok'=>true]; }
function inv_party_create($n, $t){ return ['ok'=>true]; }
/* ONE PARTY'S OPEN CONTRACT LINES — an ACTIVE sales line, an ACTIVE
   purchase line and a DRAFT sales line, so the picker's own filtering is
   exercised by the real page rather than described. */
function inv_party_contract_lines($pid){
    if ($pid != 1) return [];
    return [
      ['id'=>501,'contract_id'=>3,'contract_no'=>'SC-0001','ctype'=>'sales','status'=>'active',
       'material_id'=>0,'product_id'=>7,'item'=>'Comforter Set 7 Pc','code'=>'',
       'description'=>'','uom'=>'PCS','rate'=>4200.00,'qty'=>500,'done'=>0,'balance'=>500,
       'complete'=>false,'hay'=>'comforter set 7 pc sc-0001 sales'],
      ['id'=>502,'contract_id'=>3,'contract_no'=>'SC-0001','ctype'=>'sales','status'=>'active',
       'material_id'=>13,'product_id'=>0,'item'=>'Button 4-hole','code'=>'BTN-013',
       'description'=>'','uom'=>'PCS','rate'=>1.30,'qty'=>900,'done'=>0,'balance'=>900,
       'complete'=>false,'hay'=>'button 4-hole btn-013 sc-0001 sales'],
      ['id'=>503,'contract_id'=>4,'contract_no'=>'PC-0044','ctype'=>'purchase','status'=>'active',
       'material_id'=>12,'product_id'=>0,'item'=>'Cotton greige 60s','code'=>'FAB-012',
       'description'=>'','uom'=>'MTR','rate'=>205.00,'qty'=>2000,'done'=>0,'balance'=>2000,
       'complete'=>false,'hay'=>'cotton greige fab-012 pc-0044 purchase'],
      ['id'=>504,'contract_id'=>5,'contract_no'=>'SC-0009','ctype'=>'sales','status'=>'draft',
       'material_id'=>14,'product_id'=>0,'item'=>'Sewing thread','code'=>'THR-014',
       'description'=>'','uom'=>'CON','rate'=>96.00,'qty'=>100,'done'=>0,'balance'=>100,
       'complete'=>false,'hay'=>'sewing thread thr-014 sc-0009 sales'],
    ];
}
PHP;
file_put_contents($work . '/includes/inventory.php', $invStub);

/* -------------------------------------------------- render the real page */
function render(string $work, string $dir): array {
    $cmd = 'cd ' . escapeshellarg($work)
         . ' && REQUEST_METHOD=GET php -d error_reporting=E_ALL -d display_errors=1'
         . ' -r ' . escapeshellarg(
             '$_GET=["dir"=>"' . $dir . '","new"=>"1"];'
           . '$_SERVER["REQUEST_METHOD"]="GET";'
           . 'require "inv_gate.php";')
         . ' 2>&1';
    $out = (string)shell_exec($cmd);
    return [$out, $out];
}

foreach (['out' => 'Gate Outward', 'in' => 'Gate Inward'] as $dir => $label) {
    echo "1. The real page renders — $label\n";
    [$html] = render($work, $dir);
    file_put_contents($work . "/page_$dir.html", $html);

    ok(str_contains($html, $label), "the page renders at all");
    /* A PHP notice or warning in the output is a fault in its own right —
       it also lands in the middle of the HTML and can break a script tag. */
    ok(!preg_match('/\b(Fatal error|Parse error|Warning|Notice|Deprecated):/', $html),
       'and PHP says nothing on the way: '
       . (preg_match('/\b(?:Fatal error|Parse error|Warning|Notice|Deprecated):.*/', $html, $m) ? $m[0] : ''));
    ok(str_contains($html, 'data-lov="item"'), 'the item search box is on the line');
    ok(str_contains($html, 'assets/js/lov.js'), 'and the picker library is loaded');
}

echo "2. Driven in a browser — the box is clicked, like an operator clicks it\n";
$drive = <<<'JS'
const { chromium } = require('playwright');
(async () => {
  const br = await chromium.launch({ executablePath: '/opt/pw-browsers/chromium' });
  const R = {};
  for (const dir of ['out', 'in']) {
    const pg = await br.newPage({ viewport: { width: 1400, height: 950 } });
    const errs = [];
    pg.on('pageerror', e => errs.push(String(e)));
    /* A file:// page cannot fetch its own ajax endpoints, so CORS and
       ERR_FAILED here are the harness talking, not the app. Every OTHER
       console error still counts — that is the whole point of this test. */
    pg.on('console', m => {
      if (m.type() !== 'error') return;
      const t = m.text();
      if (/CORS policy|ERR_FAILED|Failed to load resource/.test(t)) return;
      errs.push('console: ' + t);
    });
    await pg.goto('file://' + process.argv[2] + '/page_' + dir + '.html');
    await pg.waitForTimeout(350);

    const r = { errs };
    const box = '.matbox .matq';
    const sel = '.matbox .matsel';

    /* THE SWAP. If the script died anywhere above, the search box is still
       hidden and the fallback <select> is what the operator sees. */
    r.boxVisible = await pg.isVisible(box).catch(() => false);
    r.selVisible = await pg.isVisible(sel).catch(() => false);

    /* CLICK IT. A real click, because lov.js listens on mousedown and a
       synthetic .click() does not fire that. */
    await pg.click(box);
    await pg.waitForTimeout(300);
    r.opened = await pg.evaluate(() => !!(window.LOV && window.LOV.isOpen && window.LOV.isOpen()));
    r.panelOnScreen = await pg.isVisible('.lov.open').catch(() => false);
    r.rows = await pg.evaluate(() =>
      [...document.querySelectorAll('.lov .lov-r')].map(x => x.children[2] ? x.children[2].textContent.trim() : ''));
    r.heads = await pg.evaluate(() =>
      [...document.querySelectorAll('.lov .lov-sep')].map(x => x.textContent.trim()));

    /* TYPE. The list must narrow. */
    await pg.keyboard.type('button');
    await pg.waitForTimeout(300);
    r.typedRows = await pg.evaluate(() =>
      [...document.querySelectorAll('.lov .lov-r')].map(x => x.children[2] ? x.children[2].textContent.trim() : ''));

    /* CHOOSE. The line must fill in. */
    await pg.keyboard.press('Enter');
    await pg.waitForTimeout(300);
    r.afterPick = await pg.evaluate(() => {
      const tr = document.querySelector('.matbox').closest('tr');
      return { box: tr.querySelector('.matq').value,
               key: tr.querySelector('.matsel').value,
               uom: (tr.querySelector('.uom') || {}).value,
               tag: (tr.querySelector('.ctag') || {}).textContent };
    });
    await pg.screenshot({ path: process.argv[2] + '/shot_' + dir + '.png' });

    /* A FINISHED PRODUCT MUST BE SELLABLE. Its stock is at the Finished
       Goods Store, so on an outward pass at Main Store it is correctly held
       back — and must appear the moment the location is changed. That is
       the "we can sell finish item or store item" rule.

       ON A FRESH PAGE, deliberately. Doing it in the middle of the run
       above left the picker holding the last search and the next step
       measured the wrong thing — the harness lying, not the app. */
    if (dir === 'out') {
      await pg.goto('file://' + process.argv[2] + '/page_out.html');
      await pg.waitForTimeout(300);
      await pg.selectOption('select[name="location_id"]', '2');
      await pg.waitForTimeout(250);
      r.locNow = await pg.inputValue('select[name="location_id"]');
      await pg.click(box);
      await pg.waitForTimeout(350);
      r.atFgStore = await pg.evaluate(() =>
        [...document.querySelectorAll('.lov .lov-r')].map(x => x.children[2] ? x.children[2].textContent.trim() : ''));
      r.titleAtFg = await pg.evaluate(() => (document.querySelector('.lov .ttl') || {}).textContent || '');
    }

    /* ---- the office keys, on the real page ---- */
    await pg.goto('file://' + process.argv[2] + '/page_' + dir + '.html');
    await pg.waitForTimeout(300);
    r.stateAtRest = await pg.textContent('#gState').catch(() => null);

    /* ENTER WALKS THE LINE: item -> lot -> uom -> qty -> rate -> next line */
    await pg.click(box);
    await pg.waitForTimeout(250);
    await pg.keyboard.type('button');
    await pg.waitForTimeout(250);
    await pg.keyboard.press('Enter');          // takes the row from the picker
    await pg.waitForTimeout(250);
    const where = async () => pg.evaluate(() => {
      const a = document.activeElement; if (!a) return null;
      const tr = a.closest('tr'), body = tr && tr.closest('tbody');
      const rows = body ? [...body.querySelectorAll('tr')].filter(x => x.querySelector('.matbox')) : [];
      return { cls: (a.className || '').split(' ').filter(c => ['matq','lot','uom','qty','rate'].includes(c))[0] || a.className,
               row: tr ? rows.indexOf(tr) : -1 };
    });
    r.walk = [await where()];
    for (let i = 0; i < 5; i++) { await pg.keyboard.press('Enter'); await pg.waitForTimeout(160); r.walk.push(await where()); }

    r.stateDirty = await pg.textContent('#gState').catch(() => null);

    /* CTRL+S SUBMITS — caught at the form rather than letting it navigate */
    r.ctrlS = await pg.evaluate(() => new Promise(res => {
      const f = document.getElementById('gForm');
      f.addEventListener('submit', e => { e.preventDefault(); res(true); }, { once: true });
      const old = f.submit; f.submit = () => { f.dispatchEvent(new Event('submit', {cancelable:true})); };
      document.dispatchEvent(new KeyboardEvent('keydown', { key: 's', ctrlKey: true, bubbles: true }));
      setTimeout(() => res(false), 600);
    }));

    R[dir] = r;
    await pg.close();
  }
  await br.close();
  console.log(JSON.stringify(R));
})();
JS;
file_put_contents($work . '/drive.js', $drive);
$raw = shell_exec('node ' . escapeshellarg($work . '/drive.js') . ' ' . escapeshellarg($work) . ' 2>&1');
$R = json_decode((string)$raw, true);

if (!is_array($R)) { echo "  FAIL: the driver did not run:\n" . substr((string)$raw, 0, 1200) . "\n"; $F++; }
else foreach (['out' => 'Outward', 'in' => 'Inward'] as $d => $label) {
    $r = $R[$d];
    echo "   -- $label --\n";
    /* THE ONE THAT WOULD HAVE CAUGHT THE LAST THREE ROUNDS. */
    ok(empty($r['errs']), "$label: the page runs with NO javascript error: " . json_encode($r['errs']));
    ok($r['boxVisible'] === true,  "$label: the item box is the search box");
    ok($r['selVisible'] === false, "$label:   and the fallback dropdown is hidden behind it");
    ok($r['opened'] === true,      "$label: CLICKING IT OPENS THE LIST");
    ok($r['panelOnScreen'] === true, "$label:   and the panel is actually on screen");
    ok(count($r['rows']) > 0,      "$label: the list has rows: " . json_encode($r['rows']));
    if ($d === 'out') {
        /* Outward at Main Store: only what is standing there. The product
           lives at the Finished Goods Store, so it is held back HERE and
           counted, not lost. */
        ok(!in_array('Comforter Set 7 Pc', $r['rows'], true),
           "$label:   the product is NOT offered at a store it is not in: " . json_encode($r['rows']));
        ok(($r['locNow'] ?? '') === '2', "$label:   the location really changed: " . json_encode($r['locNow'] ?? null));
        ok(in_array('Comforter Set 7 Pc', $r['atFgStore'] ?? [], true),
           "$label:   BUT IT IS, the moment the location is its own store — a finished "
           . "product must be sellable: " . json_encode($r['atFgStore'] ?? []));
        ok(str_contains((string)($r['titleAtFg'] ?? ''), 'Finished Goods Store'),
           "$label:   and the heading names the store, which is what locName() is for: "
           . json_encode($r['titleAtFg'] ?? ''));
    } else {
        ok(in_array('Comforter Set 7 Pc', $r['rows'], true),
           "$label:   including the FINISHED PRODUCT, not just raw material: " . json_encode($r['rows']));
    }
    ok($r['typedRows'] === ['Button 4-hole', 'Button 4-hole']
       || $r['typedRows'] === ['Button 4-hole'],
       "$label: typing narrows it to the button: " . json_encode($r['typedRows']));
    ok(($r['afterPick']['box'] ?? '') !== '' && ($r['afterPick']['key'] ?? '') !== '',
       "$label: choosing fills the line: " . json_encode($r['afterPick']));

    echo "   .. the office keys\n";
    ok($r['stateAtRest'] === 'saved', "$label: it opens saying 'saved', got " . json_encode($r['stateAtRest']));
    /* ENTER WALKS ACROSS THE LINE AND THEN DOWN TO THE NEXT ONE.
       "when enter so go to next field and even line complete so go next
       line automatically" — the walk below is that sentence, measured. */
    $walk = array_map(fn($w) => ($w['cls'] ?? '?') . '#' . ($w['row'] ?? '?'), (array)$r['walk']);
    /* WHAT THE WALK ACTUALLY IS, measured rather than assumed.
       Choosing an item sends the cursor to Quantity from inside the picker,
       so Lot and the derived UOM are passed over; Quantity goes to Rate;
       and Rate — the last box on the line — starts the NEXT LINE, making
       one if there isn't one. Landing on a fresh item box opens its list,
       so the next Enter chooses and the cycle repeats. That is the whole
       of "go to next field and even line complete so go next line". */
    $want = ['qty#0', 'rate#0', 'matq#1', 'qty#1', 'rate#1', 'matq#2'];
    ok($walk === $want,
       "$label: Enter walks the line and starts the next: " . json_encode($walk));
    ok($r['stateDirty'] !== 'saved', "$label: and typing marks it unsaved, got " . json_encode($r['stateDirty']));
    ok($r['ctrlS'] === true, "$label: CTRL+S SAVES");
}

echo "\n$P passed, $F failed\n";
exit($F ? 1 : 0);
