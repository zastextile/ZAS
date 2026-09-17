<?php
/* OPENING STOCK — where a balance comes from when no document made it.
 *
 * Before this, stock could only arrive through a posted gate pass. That
 * is right for everything arriving from now on and useless for the day
 * the system is switched on. The thing people reach for instead is
 * typing a number onto a balance, which is how a stock figure becomes
 * something nobody can explain.
 *
 * This test LIFTS the posting and the on-hand reader out of
 * includes/inventory.php and RUNS them against a fake PDO, so the SQL
 * and the rules are exercised rather than read; then it renders the real
 * grid out of inv_opening.php and drives it in Chromium.
 */

$B = __DIR__ . '/app_src/public_html/';
$inv = file_get_contents($B . 'includes/inventory.php');
$op  = file_get_contents($B . 'inv_opening.php');

$P = 0; $F = 0;
function ok($c, $m) { global $P, $F; if ($c) { $P++; } else { $F++; echo "  FAIL: $m\n"; } }

$work = __DIR__ . '/.zopening';
@mkdir($work, 0777, true);

/* ------------------------------------------------------------------ */
echo "1. It is a document, with the same shape as every other one\n";
foreach (['inv_opening (', 'inv_opening_items ('] as $t)
    ok(str_contains($inv, 'CREATE TABLE IF NOT EXISTS ' . $t), "$t is created on load");
ok(str_contains($inv, "status ENUM('draft','posted','reversed')"), 'draft / posted / reversed, like the rest');
ok(str_contains($inv, "'prefix_opening' => 'OPN'"), 'it has its own numbering prefix');
ok(str_contains($inv, 'function inv_opening_post'), 'it posts');
ok(str_contains($inv, 'function inv_opening_reverse'), 'and it reverses');
ok(str_contains($inv, "source_type' => 'opening'"), 'through the ordinary ledger, not a side channel');
ok(str_contains($inv, "'opening_rev'"), 'and its reversal is marked as one');
/* location on the LINE is the thing that lets one count sheet cover
   several stores. */
ok(preg_match('/inv_opening_items \(.*?location_id INT NULL/s', $inv) === 1,
   'the store is on the line, so one document can cover several');

echo "2. The size of a finished product does not land in the lot column\n";
/* This is the trap. products are stocked with size_label; materials with
   lot_no. One box asks both questions, so it has to change its NAME. */
ok(str_contains($op, "[size_label]' : '") || str_contains($op, "'size_label' : 'lot_no'")
   || str_contains($op, "? 'size_label' : 'lot_no'"),
   'the rendered box is named for the kind of item on the line');
ok(str_contains($op, "lot.name.replace('[lot_no]', '[size_label]')"),
   'and it is renamed when the item changes to a product');
ok(str_contains($op, "lot.name.replace('[size_label]', '[lot_no]')"),
   '  and back again when it changes to a material');
ok(str_contains($inv, "'size_label'  => \$it['size_label'] ?: null"),
   'and the post writes it to size_label in the ledger');

echo "3. inv_product_sizes reads product_sizes, NOT the dead table\n";
/* I wrote this against zp_sizes first. zp_sizes LOOKS like the right
   table and is a dead copy kept only for its history — zprod.php says so
   at length. Reading it here would have re-created the exact split that
   file was written to undo, on a screen that sets opening balances. */
if (preg_match('/function inv_product_sizes.*?\n}/s', $inv, $m)) {
    ok(str_contains($m[0], 'FROM product_sizes'), 'it reads product_sizes');
    ok(!str_contains($m[0], 'zp_sizes'), '  and never zp_sizes');
    /* The explanation sits ABOVE the function, so it is never inside a
       match that starts at "function". The test was looking in the wrong
       half of its own evidence. */
    ok(str_contains($inv, 'dead copy left on disk'), '  and says why, so nobody "fixes" it back');
} else { ok(false, 'inv_product_sizes() exists'); }

/* ------------------------------------------------------------------ */
echo "4. The rules, RUN — not read\n";

/* A fake PDO. Every statement is recorded, and SELECTs answer from a
   small fixture, so inv_opening_post() can be executed for real. */
$harness = '<?php
class FakeStmt {
    public $sql; public $rows = []; public $bound = [];
    public function __construct($sql, $rows){ $this->sql = $sql; $this->rows = $rows; }
    public function execute($p = []){ $this->bound = $p; $GLOBALS["CALLS"][] = [$this->sql, $p]; return true; }
    public function fetch(){ return $this->rows[0] ?? false; }
    public function fetchAll(){ return $this->rows; }
    public function fetchColumn(){ $r = $this->rows[0] ?? []; return is_array($r) ? reset($r) : $r; }
}
class FakeDb {
    public $tx = false;
    public function prepare($sql){ return new FakeStmt($sql, $GLOBALS["FIX"]($sql)); }
    public function query($sql){ return new FakeStmt($sql, $GLOBALS["FIX"]($sql)); }
    public function exec($sql){ return 1; }
    public function beginTransaction(){ $this->tx = true; return true; }
    public function commit(){ $this->tx = false; return true; }
    public function rollBack(){ $this->tx = false; return true; }
    public function inTransaction(){ return $this->tx; }
    public function lastInsertId(){ return 99; }
}
$GLOBALS["DB"] = new FakeDb();
function db(){ return $GLOBALS["DB"]; }
function current_user(){ return ["id" => 7, "role" => "admin", "name" => "Tester"]; }
function is_admin(){ return true; }
function inv_setting($k, $d = ""){ return $GLOBALS["SETTINGS"][$k] ?? $d; }
function inv_audit(...$a){ $GLOBALS["AUDIT"][] = $a; }
function inv_num($v){ $n = (float)preg_replace("/[^0-9.\\-]/", "", (string)$v); return $n; }
$GLOBALS["CALLS"] = []; $GLOBALS["AUDIT"] = []; $GLOBALS["LEDGER"] = [];
$GLOBALS["SETTINGS"] = ["default_location" => "1"];

/* the real functions, lifted out of the shipped file */
%%FUNCS%%

/* inv_post_ledger is replaced so the rows written can be inspected. The
   posting logic under test is what DECIDES those rows; writing them is
   another function with its own job. */
function inv_post_ledger(array $r){ $GLOBALS["LEDGER"][] = $r; }
function inv_already_posted($t, $i){ return !empty($GLOBALS["ALREADY"]); }

$GLOBALS["FIX"] = function($sql){
    if (strpos($sql, "FROM inv_opening WHERE id") !== false) return [$GLOBALS["DOC"]];
    if (strpos($sql, "FROM inv_opening_items WHERE opening_id") !== false) return $GLOBALS["ITEMS"];
    if (strpos($sql, "FROM inv_stock_ledger WHERE source_type=\'opening\'") !== false) return $GLOBALS["POSTED"];
    if (strpos($sql, "COALESCE(SUM(qty_in),0)") !== false) return [["x" => $GLOBALS["ONHAND"] ?? 0]];
    return [];
};

$out = [];
/* --- a normal post --- */
$GLOBALS["DOC"] = ["id"=>5,"opening_no"=>"OPN-2609-0001","opening_date"=>"2026-06-30","location_id"=>2,"status"=>"draft"];
$GLOBALS["ITEMS"] = [
  ["id"=>11,"material_id"=>3,"product_id"=>null,"size_label"=>null,"location_id"=>2,"lot_no"=>"LOT-7","qty"=>"400.000","uom"=>"MTR","rate"=>"210.5000","ownership"=>"own","owner_party_id"=>null],
  ["id"=>12,"material_id"=>null,"product_id"=>9,"size_label"=>"King","location_id"=>0,"lot_no"=>null,"qty"=>"60.000","uom"=>"PCS","rate"=>"2850.0000","ownership"=>"own","owner_party_id"=>null],
  ["id"=>13,"material_id"=>4,"product_id"=>null,"size_label"=>null,"location_id"=>2,"lot_no"=>null,"qty"=>"0","uom"=>"KGS","rate"=>"0","ownership"=>"own","owner_party_id"=>null],
];
$out["normal"] = inv_opening_post(5);
$out["ledger"] = $GLOBALS["LEDGER"];

/* --- a document that names no store at all: NOW the app default applies --- */
$GLOBALS["LEDGER"] = [];
$saveLoc = $GLOBALS["DOC"]["location_id"]; $GLOBALS["DOC"]["location_id"] = 0;
$GLOBALS["ITEMS"] = [["id"=>14,"material_id"=>3,"product_id"=>null,"size_label"=>null,"location_id"=>0,"lot_no"=>null,"qty"=>"5","uom"=>"MTR","rate"=>"1","ownership"=>"own","owner_party_id"=>null]];
$out["noDocLoc"] = (function(){ inv_opening_post(5); return $GLOBALS["LEDGER"]; })();
$GLOBALS["DOC"]["location_id"] = $saveLoc;

/* --- negative quantity --- */
$GLOBALS["LEDGER"] = [];
$GLOBALS["ITEMS"] = [["id"=>21,"material_id"=>3,"product_id"=>null,"size_label"=>null,"location_id"=>2,"lot_no"=>null,"qty"=>"-5","uom"=>"MTR","rate"=>"10","ownership"=>"own","owner_party_id"=>null]];
$out["negative"] = inv_opening_post(5);
$out["negLedger"] = count($GLOBALS["LEDGER"]);

/* --- nothing worth posting --- */
$GLOBALS["LEDGER"] = [];
$GLOBALS["ITEMS"] = [["id"=>31,"material_id"=>null,"product_id"=>null,"size_label"=>null,"location_id"=>2,"lot_no"=>null,"qty"=>"10","uom"=>"","rate"=>"0","ownership"=>"own","owner_party_id"=>null]];
$out["noItem"] = inv_opening_post(5);

/* --- already posted --- */
$GLOBALS["ALREADY"] = true; $GLOBALS["LEDGER"] = [];
$GLOBALS["ITEMS"] = [["id"=>41,"material_id"=>3,"product_id"=>null,"size_label"=>null,"location_id"=>2,"lot_no"=>null,"qty"=>"5","uom"=>"MTR","rate"=>"1","ownership"=>"own","owner_party_id"=>null]];
$out["twice"] = inv_opening_post(5);
$out["twiceLedger"] = count($GLOBALS["LEDGER"]);
$GLOBALS["ALREADY"] = false;

/* --- a posted document cannot post again --- */
$GLOBALS["DOC"]["status"] = "posted";
$out["postedAgain"] = inv_opening_post(5);
$GLOBALS["DOC"]["status"] = "draft";

/* --- reversal --- */
$GLOBALS["DOC"]["status"] = "posted";
$GLOBALS["POSTED"] = [["material_id"=>3,"product_id"=>null,"size_label"=>null,"location_id"=>2,
  "ownership"=>"own","owner_party_id"=>null,"lot_no"=>"LOT-7","qty_in"=>"400.000","qty_out"=>"0.000",
  "rate"=>"210.5000","source_item_id"=>11]];
$GLOBALS["LEDGER"] = [];
$out["revNoReason"] = inv_opening_reverse(5, "   ");
$out["rev"] = inv_opening_reverse(5, "counted twice");
$out["revLedger"] = $GLOBALS["LEDGER"];

/* --- the on-hand reader: what SQL does it build? --- */
$GLOBALS["CALLS"] = []; $GLOBALS["ONHAND"] = 1840.5;
$out["onhandLot"]   = inv_opening_onhand(3, 0, 2, "LOT-7", "");
$out["sqlLot"]      = $GLOBALS["CALLS"][0][0] ?? "";
$out["bindLot"]     = $GLOBALS["CALLS"][0][1] ?? [];
$GLOBALS["CALLS"] = [];
$out["onhandBlank"] = inv_opening_onhand(3, 0, 2, "", "");
$out["sqlBlank"]    = $GLOBALS["CALLS"][0][0] ?? "";
$GLOBALS["CALLS"] = [];
$out["onhandSize"]  = inv_opening_onhand(0, 9, 3, "", "King");
$out["sqlSize"]     = $GLOBALS["CALLS"][0][0] ?? "";
$out["bindSize"]    = $GLOBALS["CALLS"][0][1] ?? [];
$GLOBALS["CALLS"] = [];
$out["onhandNone"]  = inv_opening_onhand(0, 0, 2, "", "");
$out["sqlNone"]     = $GLOBALS["CALLS"][0][0] ?? "";

echo json_encode($out);
';

/* lift the real functions */
$funcs = '';
foreach (['inv_opening_post', 'inv_opening_reverse', 'inv_opening_onhand'] as $fn) {
    if (preg_match('/\nfunction ' . $fn . '\(.*?\n}/s', $inv, $m)) $funcs .= $m[0] . "\n";
    else { ok(false, "could not lift $fn()"); }
}
ok(substr_count($funcs, 'function ') >= 3, 'all three functions lifted out of the shipped file');

file_put_contents($work . '/run.php', str_replace('%%FUNCS%%', $funcs, $harness));
$raw = shell_exec('php ' . escapeshellarg($work . '/run.php') . ' 2>&1');
$R = json_decode((string)$raw, true);

if (!is_array($R)) { echo "  FAIL: the lifted code did not run:\n" . substr((string)$raw, 0, 900) . "\n"; $F++; }
else {
    echo "   posting\n";
    ok($R['normal']['ok'] === true, 'a normal document posts: ' . $R['normal']['error']);
    ok(count($R['ledger']) === 2,
       'two lines with a quantity become two ledger rows, the zero line does not, got ' . count($R['ledger']));
    $a = $R['ledger'][0]; $b = $R['ledger'][1];
    ok((float)$a['qty_in'] === 400.0 && !isset($a['qty_out']),
       'opening stock is quantity IN, never out');
    ok($a['txn_date'] === '2026-06-30',
       'the ledger row carries the DOCUMENT\'s date, not today — an opening balance is as at a date');
    ok($a['source_type'] === 'opening' && (int)$a['source_id'] === 5 && (int)$a['source_item_id'] === 11,
       'and points back at the document and the line');
    ok($a['source_no'] === 'OPN-2609-0001', 'so the Stock Ledger shows the number');
    ok($a['lot_no'] === 'LOT-7', 'a material keeps its lot');
    ok($b['size_label'] === 'King' && empty($b['lot_no']),
       'a product keeps its SIZE, and no lot — this is the column that matters');
    /* I asserted the APP default here and the code uses the DOCUMENT's
       store. The code is right: a count sheet headed "Fabric Store" with
       one line left blank means Fabric Store, not whatever the app was
       configured with months ago. The app default is only reached when
       the document itself names no store, which is the case below. */
    ok((int)$b['location_id'] === 2,
       'a line with no store falls back to the DOCUMENT\'s store, got ' . $b['location_id']);
    ok((int)$a['location_id'] === 2, '  and a line with one keeps it');
    ok((int)$R['noDocLoc'][0]['location_id'] === 1,
       '  and only when the document names none does the app default apply, got '
       . $R['noDocLoc'][0]['location_id']);

    echo "   what it refuses\n";
    ok($R['negative']['ok'] === false && str_contains($R['negative']['error'], 'cannot be negative'),
       'a negative opening quantity is refused: ' . $R['negative']['error']);
    ok($R['negLedger'] === 0, '  and nothing at all is written');
    ok($R['noItem']['ok'] === false, 'a quantity with no item is refused');
    ok($R['twice']['ok'] === false && str_contains($R['twice']['error'], 'already been written'),
       'a double submit cannot post twice: ' . $R['twice']['error']);
    ok($R['twiceLedger'] === 0, '  and writes nothing');
    ok($R['postedAgain']['ok'] === false, 'a posted document cannot be posted again');

    echo "   reversing\n";
    ok($R['revNoReason']['ok'] === false, 'a reversal without a reason is refused');
    ok($R['rev']['ok'] === true, 'with a reason it reverses: ' . $R['rev']['error']);
    ok(count($R['revLedger']) === 1, 'one opposite row per posted row');
    $rv = $R['revLedger'][0];
    ok((float)$rv['qty_out'] === 400.0 && (float)$rv['qty_in'] === 0.0,
       'the quantity comes back OUT');
    ok($rv['source_type'] === 'opening_rev',
       '  marked as a reversal, so it is never counted as an opening balance');
    ok(str_contains($rv['remarks'], 'counted twice'), '  carrying the reason');

    echo "   in stock now\n";
    ok($R['onhandLot'] === 1840.5, 'it reads a balance');
    ok(str_contains($R['sqlLot'], 'lot_no = ?'), 'a named lot is matched exactly');
    ok(in_array('LOT-7', $R['bindLot'], true), '  and bound, not interpolated');
    /* THE ONE THAT WOULD HAVE MADE EVERY LINE LOOK LIKE A DOUBLE COUNT. */
    ok(str_contains($R['sqlBlank'], "lot_no IS NULL OR lot_no = ''"),
       'a BLANK lot is its own bucket, not a wildcard over the whole item');
    ok(str_contains($R['sqlSize'], 'product_id = ?') && str_contains($R['sqlSize'], 'size_label = ?'),
       'a product is matched on its size');
    ok(in_array('King', $R['bindSize'], true), '  bound too');
    ok(str_contains($R['sqlLot'], "source_type <> 'opening_rev'"),
       'and a reversed opening is not counted as stock on hand');
    /* json_encode turns 0.0 into 0, which decodes as int — a fact about
       the transport, not about the function. Compared by value. */
    ok((float)$R['onhandNone'] === 0.0 && $R['sqlNone'] === '',
       'naming nothing reads nothing — and asks the database nothing at all');
}

/* ------------------------------------------------------------------ */
echo "5. The grid, rendered and driven\n";

$a = strpos($op, '<div style="overflow-x:auto"><table class="op-tbl" id="ogrid">');
$b = strpos($op, '</table></div>', $a);
ok($a !== false && $b !== false, 'the grid is where it was');
$frag = substr($op, $a, $b - $a + strlen('</table></div>'));
preg_match('/<style>(.*?)<\/style>/s', $op, $sm);
$pageCss = $sm[1] ?? '';

$lines = [
  ['material_id'=>3,'product_id'=>0,'mcode'=>'FAB-001','mname'=>'Cotton greige 60s','mstage'=>'grey',
   'location_id'=>2,'lot_no'=>'LOT-7','qty'=>400,'uom'=>'MTR','rate'=>210.5,'amount'=>84200],
  ['material_id'=>0,'product_id'=>9,'pname'=>'Duvet cover king','size_label'=>'King',
   'location_id'=>3,'qty'=>60,'uom'=>'PCS','rate'=>2850,'amount'=>171000],
  [],
];
$tpl = '<?php
function e($s){ return htmlspecialchars((string)$s, ENT_QUOTES); }
$lines = ' . var_export($lines, true) . ';
$locations = [["id"=>1,"name"=>"Main Store"],["id"=>2,"name"=>"Fabric Store"],["id"=>3,"name"=>"Finished Goods"]];
$KIND = ["grey"=>"Grey","raw"=>"Raw","finished"=>"Finished","product"=>"Product","na"=>"\u{2014}"];
?>' . $frag;
file_put_contents($work . '/frag.php', $tpl);
$grid = shell_exec('php ' . escapeshellarg($work . '/frag.php') . ' 2>&1');
ok(!str_contains($grid, 'error') && !str_contains($grid, 'Warning'),
   'it renders clean: ' . substr(trim($grid), 0, 140));

$page = '<!doctype html><html><head><meta charset="utf-8">'
      . '<style>' . file_get_contents($B . 'assets/css/app.css') . '</style>'
      . '<style>' . $pageCss . '</style>'
      . '<style>' . file_get_contents($B . 'assets/css/zskin.css') . '</style>'
      . '</head><body style="width:1280px;margin:0"><div class="zskin">' . $grid . '</div></body></html>';
file_put_contents($work . '/grid.html', $page);

$probe = <<<'JS'
const { chromium } = require('playwright');
(async () => {
  const br = await chromium.launch({ executablePath: '/opt/pw-browsers/chromium' });
  const pg = await br.newPage({ viewport: { width: 1280, height: 800 } });
  await pg.goto('file://' + process.argv[2] + '/grid.html');
  await pg.waitForTimeout(150);
  console.log(JSON.stringify(await pg.evaluate(() => {
    const t = document.querySelector('#ogrid');
    const rows = [...t.querySelectorAll('tbody tr')];
    const g = el => el ? Math.round(el.getBoundingClientRect().height) : 0;
    return {
      cols: t.querySelectorAll('thead th').length,
      headers: [...t.querySelectorAll('thead th')].map(h => h.textContent.trim()),
      rowH: rows.map(g),
      footSpan: [...t.querySelectorAll('tfoot tr')].map(r =>
        [...r.children].reduce((n, td) => n + (+td.getAttribute('colspan') || 1), 0)),
      // the box that asks two questions must be NAMED for the question
      lotNames: rows.map(r => r.querySelector('.lot') ? r.querySelector('.lot').getAttribute('name') : null),
      lotValues: rows.map(r => r.querySelector('.lot') ? r.querySelector('.lot').value : null),
      keys: rows.map(r => r.querySelector('.ikey').value),
      kinds: rows.map(r => r.querySelector('.kind').textContent.trim()),
      uomClipped: rows.map(r => { const u = r.querySelector('.uom'); return u.scrollWidth > u.clientWidth + 1; }),
      itemClipped: rows.map(r => { const u = r.querySelector('.it'); return u.scrollWidth > u.clientWidth + 1; }),
      tableOverflow: t.scrollWidth > t.clientWidth + 1
    };
  })));
  await br.close();
})();
JS;
file_put_contents($work . '/probe.js', $probe);
$raw2 = shell_exec('cd ' . escapeshellarg(__DIR__) . ' && node ' . escapeshellarg($work . '/probe.js')
                   . ' ' . escapeshellarg($work) . ' 2>&1');
$M = json_decode((string)$raw2, true);

if (!is_array($M)) { echo "  FAIL: the browser probe did not run:\n" . substr((string)$raw2, 0, 700) . "\n"; $F++; }
else {
    ok($M['cols'] === 11, '11 columns, got ' . $M['cols'] . ': ' . json_encode($M['headers']));
    foreach ($M['footSpan'] as $w) ok($w === 11, 'the total row adds up to 11, got ' . $w);
    ok(count(array_unique($M['rowH'])) === 1 && $M['rowH'][0] <= 32,
       'every row is the same height and compact: ' . json_encode($M['rowH']));

    /* THE SIZE TRAP, PROVEN IN THE RENDERED HTML. */
    ok($M['lotNames'][0] === 'line[0][lot_no]',
       'a material line posts its lot as lot_no, got ' . json_encode($M['lotNames'][0]));
    ok($M['lotNames'][1] === 'line[1][size_label]',
       'a PRODUCT line posts its size as size_label, got ' . json_encode($M['lotNames'][1]));
    ok($M['lotValues'][1] === 'King', '  and shows the size it was saved with');
    ok($M['lotNames'][2] === 'line[2][lot_no]',
       'an empty line starts as a lot box, got ' . json_encode($M['lotNames'][2]));

    ok($M['keys'][0] === 'm3' && $M['keys'][1] === 'p9',
       'the kind travels WITH the id, so the save never has to guess: ' . json_encode($M['keys']));
    ok($M['keys'][2] === '', '  and an empty line names nothing');
    ok($M['kinds'][0] === 'Grey' && $M['kinds'][1] === 'Product',
       'the kind is shown, not left to be worked out: ' . json_encode($M['kinds']));

    ok(!in_array(true, $M['uomClipped'], true), 'no unit is cut off');
    ok(!in_array(true, $M['itemClipped'], true), 'no item name is cut off');
    ok($M['tableOverflow'] === false, 'and the table does not scroll sideways at 1280');
}

/* ------------------------------------------------------------------ */
echo "6. Wired in, and reachable\n";
ok(is_file($B . 'inv_opening.php'), 'the page exists');
ok(str_contains(file_get_contents($B . 'includes/menu.php'), 'inv_opening.php'), 'it is in the menu');
ok(substr_count($op, '<div class="zskin">') === 1, 'it opts into the skin once');
ok(substr_count($op, 'closes .zskin') === 1, '  and closes it once');
ok(preg_match('~assets/js/lov\.js\?v=\d+~', $op) === 1, 'the picker is cache-busted');
ok(str_contains($op, "verify_csrf()"), 'writes are CSRF-checked');
ok(str_contains($op, "inv_perm('post')"), 'posting needs the posting permission');
ok(str_contains($op, "cannot be dated in the future"),
   'and it cannot be dated ahead of movements that already happened');
/* A posted document is reversed, never deleted — both stay. */
ok(str_contains($op, "Only a draft can be deleted"), 'only a draft can be deleted');

echo "\n$P passed, $F failed\n";
exit($F ? 1 : 0);
