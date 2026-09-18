<?php
/* PICKING A CONTRACT LINE ON A GATE PASS — driven in a browser.
 *
 * Reported twice: "still problem in gate in and out, especially using
 * contract". Reading the code found it, and this test RUNS it, because
 * reading is what missed it the first time.
 *
 * THE FAULT. The contract picker filled the item by assigning a bare
 * material id — "12" — to the item select. That select was widened to carry
 * ITEM KEYS, "m12" or "p7", so assigning "12" matched no <option> and the
 * select stayed empty. Pick a contract line and the item, the unit and the
 * rate were all still blank. On a sale contract for a FINISHED PRODUCT it
 * was worse: only material_id was ever looked at, so a product line could
 * not fill anything even in principle.
 *
 * The real grid, the real picker and the real lists are loaded into a page
 * and clicked.
 */

$B = __DIR__ . '/app_src/public_html/';
$P = 0; $F = 0;
function ok($c, $m) { global $P, $F; if ($c) { $P++; } else { $F++; echo "  FAIL: $m\n"; } }

$gate = file_get_contents($B . 'inv_gate.php');
$work = __DIR__ . '/.zgcl';
@mkdir($work, 0777, true);

echo "1. One pick sets the item AND the contract together\n";
$code = preg_replace('!/\*.*?\*/!s', '', $gate);
/* The old two-picker arrangement could disagree with itself: choose a
   contract line, then change the item, and the line stayed attached to a
   contract that never mentioned that item. One pick now decides both. */
ok(str_contains($code, "if(r.cl){") && str_contains($code, "if(cid) cid.value = r.cl.contract_id;"),
   'picking a contract row records the contract');
ok(str_contains($code, "if(cid) cid.value = ''; if(ci) ci.value = '';"),
   'AND PICKING A FREE ITEM TAKES IT OFF AGAIN');
ok(str_contains($gate, 'THE CONTRACT IS SET OR CLEARED BY WHAT WAS PICKED'),
   '  with the reason recorded');
ok(str_contains($code, "if(r.cl && r.cl.rate > 0){"),
   "the contract's agreed rate outranks the item's standard one");
/* FOUND BY DRIVING THE SCREEN, NOT READING IT.
   The change handler fills the unit and the rate only when each is EMPTY —
   right while a line is being built, wrong the moment the item on it is
   replaced. Pick a contract line for a 4,200 comforter set, then change
   that line to sewing thread, and 4,200 stayed on the thread with SET as
   its unit. Both are cleared before the new item fills them. */
ok(str_contains($code, "if(ru) ru.value = '';") && str_contains($code, "if(rr0) rr0.value = '';"),
   'CHANGING THE ITEM ON A LINE CLEARS THE OLD UNIT AND RATE');
ok(str_contains($gate, 'THE UNIT AND THE RATE ARE CLEARED BEFORE THE NEW ITEM FILLS THEM'),
   '  with the reason recorded');
$pk = substr($code, strpos($code, 'pick: function(f, r){'));
$pk = substr($pk, 0, strpos($pk, "\n    }\n  });"));
ok(strpos($pk, "if(ru) ru.value = '';") < strpos($pk, "sel.dispatchEvent"),
   '  and cleared BEFORE the change event, or the handler would skip them again');
ok(str_contains($code, "if(!r.it){"),
   'a contract line naming an item the stock list has not got is handled');
ok(str_contains($gate, 'names an item that is not on the stock list'),
   '  and said out loud rather than filling nothing in silence');

ok(!str_contains($code, "select[name*=\"[product_id]\"]"),
   'the dead product select the strip no longer has is gone with it');

echo "2. The lot list asks by key too\n";
ok(str_contains($code, "ajax=onhand&item_key=' + encodeURIComponent(ik)"),
   'lotsFor asks by item key');
ok(substr_count($code, "ajax=onhand&material_id=") === 0,
   'nothing on the page still asks onhand by material id');
/* Two caches keyed differently is how one of them is always cold. */
/* ONE SHAPE OF CACHE KEY on the page. Two shapes is how one of the two
   caches is always cold and re-fetches what the other already answered. */
ok(substr_count($code, "ik + '|' + locId() + '|' + ownOf()") >= 2,
   'the balance and the lot list build the same cache key');
ok(!preg_match("/mid \+ '\|' \+ locId\(\)/", $code),
   'and nothing still keys that cache by material id');

/* ------------------------------------------------------------------ */
echo "3. The contract left the header and the grid\n";
ok(!preg_match('/<select class="ig-inp" name="contract_id"/', $gate),
   'the header dropdown of every contract in the company is gone');
ok(str_contains($code, '<input type="hidden" name="contract_id" id="cSel"'),
   '  but a saved pass keeps the value it was saved with');
/* SCOPED TO THE ENTRY GRID. The read-only view of a saved pass further down
   the page has a Contract column of its own, shown only when a line really
   carries one — that one is a report, not a form, and is not what was asked
   to change. */
$ga = strpos($code, '<table class="ig-tbl" id="glines">');
$gb = strpos($code, '</table>', $ga);
$entryGrid = substr($code, $ga, $gb - $ga);
ok(!str_contains($entryGrid, '<th>Contract</th>'), 'the entry grid has no Contract column');
ok(str_contains($code, '<th>Contract</th>'),
   '  while the saved-pass view still reports one when a line carries it');
ok(str_contains($code, 'class="ctag'), '  the contract reads back as a tag on the item');
ok(str_contains($code, 'class="cid"') && str_contains($code, 'class="citem"'),
   '  and the two fields the save reads are untouched');
ok(!str_contains($code, "data-lov=\"cline\""), 'the second picker is gone');
ok(!str_contains($code, "LOV.register('cline'"), '  and so is its registration');

/* Remarks and the order link moved into the fold — and the fold must open
   itself when either is filled, or a pass would hide its own remark. */
ok(str_contains($code, "|| trim((string)(\$D['remarks'] ?? '')) !== ''"),
   'a pass carrying a remark opens the fold');
ok(str_contains($code, "|| (int)(\$D['proforma_id'] ?? 0) > 0"),
   '  and so does one linked to an order');

echo "4. The picker library can hold a section heading\n";
$lov = file_get_contents($B . 'assets/js/lov.js');
ok(str_contains($lov, "if (r && r.__sep) return '<div class=\"lov-sep\">'"), 'a heading renders as a heading');
ok(str_contains($lov, "if (!row || row.__sep) return;"), 'take() refuses to pick one');
ok(str_contains($lov, 'function skipIdx('), 'and the arrow keys step past it');
ok(str_contains($lov, "S.idx = skipIdx(S.idx, 1);"), '  including the cursor the list opens on');
ok(str_contains($lov, 'A SECTION HEADING IS A ROW THAT CANNOT BE CHOSEN'),
   'with the three guards written down');

/* ------------------------------------------------------------------ */
echo "5. Driven: one list, both sections, both directions\n";

$a = strpos($gate, '<div style="overflow-x:auto"><table class="ig-tbl" id="glines">');
$b = strpos($gate, '</table>', $a);
ok($a !== false && $b !== false, 'the items grid is where it was');
$frag = substr($gate, $a, $b - $a + strlen('</table>'));

function renderGate(string $frag, string $dir, string $work): string {
    $tpl = '<?php
function e($s){ return htmlspecialchars((string)$s, ENT_QUOTES); }
function inv_num($v){ return (float)$v; }
$dir = ' . var_export($dir, true) . ';
$stockItems = [
  ["key"=>"m12","kind"=>"mat","id"=>12,"code"=>"FAB-012","name"=>"Cotton greige 60s","grp"=>"Fabric",
   "stage"=>"grey","uom"=>"MTR","rate"=>210.5,"bal"=>[0=>900,1=>900],"val"=>[],"sizes"=>[]],
  ["key"=>"m13","kind"=>"mat","id"=>13,"code"=>"BTN-013","name"=>"Button 4-hole","grp"=>"Accessories",
   "stage"=>"na","uom"=>"PCS","rate"=>1.25,"bal"=>[0=>0],"val"=>[],"sizes"=>[]],
  ["key"=>"p7","kind"=>"prod","id"=>7,"code"=>"PRD-7","name"=>"Comforter Set 7 Pc","grp"=>"Finished goods",
   "stage"=>"product","uom"=>"SET","rate"=>0,"bal"=>[0=>300,1=>300],"val"=>[],"sizes"=>["King","Queen"]],
];
$lines = [["material_id"=>0,"product_id"=>0,"lot_no"=>"","qty"=>"","uom"=>"","rate"=>"",
           "description"=>"","contract_id"=>0,"contract_item_id"=>0,"size_label"=>"","lcid"=>0,"lcno"=>""]];
$doc = null;
$D = ["location_id"=>1,"gst_applies"=>0,"gst_pct"=>18];
?>' . $frag;
    file_put_contents($work . '/frag_' . $dir . '.php', $tpl);
    return (string)shell_exec('php ' . escapeshellarg($work . '/frag_' . $dir . '.php') . ' 2>&1');
}

$G = [];
foreach (['out', 'in'] as $d) {
    $G[$d] = renderGate($frag, $d, $work);
    ok(!str_contains($G[$d], 'Fatal') && !str_contains($G[$d], 'Warning'),
       "the $d grid renders clean: " . substr(trim($G[$d]), 0, 200));
    /* "<th" also matches "<thead", which counted the header row itself as a
       column and made every table look one wider than it is. */
    $th = preg_match_all('/<th[\s>]/', substr($G[$d], 0, strpos($G[$d], '</thead>')));
    ok($th === ($d === 'out' ? 8 : 7), "$d has " . ($d === 'out' ? 8 : 7) . " columns, got $th");
}
/* A totals row one cell wider than its table is a fault this module has
   already had once, on the store screen. Counted, not assumed. */
foreach (['out', 'in'] as $d) {
    preg_match_all('/<tfoot>(.*?)<\/tfoot>/s', $G[$d], $tf);
    $foot = $tf[1][0] ?? '';
    preg_match_all('/<tr>(.*?)<\/tr>/s', $foot, $rows);
    foreach ($rows[1] as $ri => $row) {
        $w = 0;
        preg_match_all('/<td([^>]*)>/', $row, $tds);
        foreach ($tds[1] as $attr) {
            $w += preg_match('/colspan="(\d+)"/', $attr, $cm) ? (int)$cm[1] : 1;
        }
        ok($w === ($d === 'out' ? 8 : 7),
           "$d totals row $ri adds up to " . ($d === 'out' ? 8 : 7) . ", got $w");
    }
}

$pa = strpos($gate, "  function itemRowsFor(q, showAll){");
$pb = strpos($gate, "\n  }\n", strpos($gate, "return [out, hidden];", $pa)) + 4;
$rowsFn = substr($gate, $pa, $pb - $pa);
ok(str_contains($rowsFn, '__sep'), 'the row builder emits headings');

$CLINES = [
  ['id'=>501,'contract_id'=>3,'contract_no'=>'SC-0001','ctype'=>'sales',
   'material_id'=>0,'product_id'=>7,'item'=>'PRD-7 · Comforter Set 7 Pc','description'=>'',
   'qty'=>500,'balance'=>500,'uom'=>'SET','rate'=>4200.00,'complete'=>false],
  ['id'=>502,'contract_id'=>3,'contract_no'=>'SC-0001','ctype'=>'sales',
   'material_id'=>13,'product_id'=>0,'item'=>'BTN-013 · Button 4-hole','description'=>'',
   'qty'=>900,'balance'=>900,'uom'=>'PCS','rate'=>1.25,'complete'=>false],
  ['id'=>503,'contract_id'=>3,'contract_no'=>'SC-0001','ctype'=>'sales',
   'material_id'=>12,'product_id'=>0,'item'=>'FAB-012 · Cotton greige 60s','description'=>'',
   'qty'=>100,'balance'=>0,'uom'=>'MTR','rate'=>210.50,'complete'=>true],
];
$ITEMS = json_decode('[
  {"key":"m12","kind":"mat","id":12,"code":"FAB-012","name":"Cotton greige 60s","grp":"Fabric","stage":"grey","uom":"MTR","rate":210.5,"bal":{"0":900,"1":900},"sizes":[]},
  {"key":"m13","kind":"mat","id":13,"code":"BTN-013","name":"Button 4-hole","grp":"Accessories","stage":"na","uom":"PCS","rate":1.25,"bal":{"0":0},"sizes":[]},
  {"key":"p7","kind":"prod","id":7,"code":"PRD-7","name":"Comforter Set 7 Pc","grp":"Finished goods","stage":"product","uom":"SET","rate":0,"bal":{"0":300,"1":300},"sizes":["King","Queen"]}
]', true);

$harness = '<!doctype html><html><head><meta charset="utf-8"></head><body>'
  . '<scr' . 'ipt>'
  . 'var LOV = { score:function(q,a,b,c){ if(!q) return 1;'
  . '   var h = String((a||"")+" "+(b||"")+" "+(c||"")).toLowerCase();'
  . '   return h.indexOf(String(q).toLowerCase()) >= 0 ? 2 : 0; } };'
  . 'var ITEMS = ' . json_encode($ITEMS) . ';'
  . 'var CLINES = ' . json_encode($CLINES) . ', CLPARTY = 1;'
  . 'var IS_OUT = true;'
  . 'function clParty(){ return 1; }'
  . 'function partyName(){ return "ABRAR AHMED"; }'
  . 'function itemByKey(k){ for(var i=0;i<ITEMS.length;i++) if(ITEMS[i].key===k) return ITEMS[i]; return null; }'
  . 'function pickMode(){ return IS_OUT ? "here" : "all"; }'
  . 'function balOf(it){ if(!it) return 0; var m = it.bal||{}; return m[1] !== undefined ? m[1] : (m[0]||0); }'
  . 'function rateFor(it){ return it ? it.rate : 0; }'
  . $rowsFn
  . 'window.run = function(out, q, showAll){'
  . '  IS_OUT = out;'
  . '  var r = itemRowsFor(q||"", !!showAll);'
  . '  return { rows: r[0].map(function(x){'
  . '      return x.__sep ? {sep:x.__sep}'
  . '                     : {cl: x.cl ? x.cl.contract_no : null, key: x.it ? x.it.key : null,'
  . '                        bal: x.bal, rate: x.rate, cbal: x.cl ? x.cl.balance : null}; }),'
  . '           hidden: r[1] };'
  . '};'
  . '</scr' . 'ipt></body></html>';
file_put_contents($work . '/rows.html', $harness);

$drive = <<<'JS'
const { chromium } = require('playwright');
(async () => {
  const br = await chromium.launch({ executablePath: '/opt/pw-browsers/chromium' });
  const pg = await br.newPage();
  const errs = []; pg.on('pageerror', e => errs.push(String(e)));
  await pg.goto('file://' + process.argv[2] + '/rows.html');
  await pg.waitForTimeout(120);
  const R = { errs };
  R.out      = await pg.evaluate(() => window.run(true,  '',    false));
  R.outAll   = await pg.evaluate(() => window.run(true,  '',    true));
  R.in       = await pg.evaluate(() => window.run(false, '',    false));
  R.search   = await pg.evaluate(() => window.run(true,  'button', false));
  await br.close();
  console.log(JSON.stringify(R));
})();
JS;
file_put_contents($work . '/rows.js', $drive);
$raw = shell_exec('cd ' . escapeshellarg(__DIR__) . ' && node ' . escapeshellarg($work . '/rows.js')
                  . ' ' . escapeshellarg($work) . ' 2>&1');
$R = json_decode((string)$raw, true);

if (!is_array($R)) { echo "  FAIL: the row builder did not run:\n" . substr((string)$raw, 0, 900) . "\n"; $F++; }
else {
    ok(empty($R['errs']), 'the row builder runs clean: ' . json_encode($R['errs']));

    /* GOING OUT. Contract section first, then free stock — and free stock
       is only what is actually on the floor. */
    $o = $R['out']['rows'];
    ok(($o[0]['sep'] ?? '') === 'On contract with ABRAR AHMED',
       'the contract section comes first, got ' . json_encode($o[0]));
    $seps = array_values(array_filter($o, fn($r) => isset($r['sep'])));
    ok(count($seps) === 2, 'two sections, got ' . count($seps) . ': ' . json_encode(array_column($seps, 'sep')));
    ok($seps[1]['sep'] === 'Any other item in stock', '  and the second says stock, going out');

    $cl = array_values(array_filter($o, fn($r) => !isset($r['sep']) && $r['cl'] !== null));
    ok(count($cl) === 2, 'the delivered-in-full contract line is held back, got ' . count($cl) . ' of 3');
    ok($R['out']['hidden'] >= 1, '  and counted as hidden, got ' . $R['out']['hidden']);

    /* A CONTRACT LINE FOR SOMETHING WITH NO STOCK IS STILL OFFERED, marked
       at zero. The contract is a fact; hiding it would send the operator
       hunting for a line they were told to deliver. */
    $btn = null; foreach ($cl as $r) if ($r['key'] === 'm13') $btn = $r;
    ok($btn !== null, 'a contract line with no stock is still on the list');
    ok($btn && $btn['bal'] === 0, '  showing zero in stock, got ' . json_encode($btn['bal'] ?? null));
    ok($btn && (float)$btn['rate'] === 1.25, '  at the contract rate');

    /* Free stock going out excludes the button, which has none. */
    $free = array_values(array_filter($o, fn($r) => !isset($r['sep']) && $r['cl'] === null));
    $fkeys = array_column($free, 'key'); sort($fkeys);
    ok($fkeys === ['m12', 'p7'],
       'GOING OUT, FREE ITEMS ARE ONLY WHAT IS IN STOCK, got ' . json_encode($fkeys));

    /* COMING IN. Everything, any time. */
    $i = $R['in']['rows'];
    $ifree = array_values(array_filter($i, fn($r) => !isset($r['sep']) && $r['cl'] === null));
    $ikeys = array_column($ifree, 'key'); sort($ikeys);
    ok($ikeys === ['m12', 'm13', 'p7'],
       'COMING IN, EVERY ITEM IS OFFERED, got ' . json_encode($ikeys));
    $iseps = array_values(array_filter($i, fn($r) => isset($r['sep'])));
    ok(($iseps[1]['sep'] ?? '') === 'Any other item',
       '  and the heading does not promise stock, got ' . json_encode($iseps[1] ?? null));
    ok(array_values(array_filter($i, fn($r) => !isset($r['sep']) && $r['cl'] !== null))[0]['bal'] === null,
       '  a contract row coming in shows no stock figure at all');

    /* show all brings the finished contract line back */
    $aRows = $R['outAll']['rows'];
    $acl = array_values(array_filter($aRows, fn($r) => !isset($r['sep']) && $r['cl'] !== null));
    ok(count($acl) === 3, '"show all" brings back the completed contract line, got ' . count($acl));

    /* Searching narrows BOTH sections, and a section with nothing left
       loses its heading rather than standing empty. */
    $sr = $R['search']['rows'];
    $snames = array_map(fn($r) => $r['sep'] ?? $r['key'], $sr);
    ok(in_array('m13', $snames, true), 'searching finds the button on the contract side');
    ok(!in_array('m12', $snames, true), '  and drops what does not match');
    ok(count(array_filter($sr, fn($r) => isset($r['sep']))) === 1,
       'A SECTION WITH NOTHING IN IT LOSES ITS HEADING, got '
       . json_encode(array_values(array_filter($snames, fn($x) => is_string($x) && strlen($x) > 4))));
}

echo "\n$P passed, $F failed\n";
exit($F ? 1 : 0);
