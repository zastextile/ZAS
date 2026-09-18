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

echo "1. The key is built the way the stock list builds it\n";
$code = preg_replace('!/\*.*?\*/!s', '', $gate);
ok(str_contains($code, "var key = l.material_id ? ('m' + l.material_id)"),
   'the contract pick builds an item key');
ok(str_contains($code, "(l.product_id ? ('p' + l.product_id) : '')"),
   '  and a contract line for a PRODUCT builds one too');
ok(!preg_match('/sel\.value = String\(l\.material_id\)/', $code),
   'the bare material id is gone');
/* A select silently refusing a value is the whole class of bug here, so the
   assignment is checked rather than trusted. */
ok(str_contains($code, 'if(sel.value === key){'),
   'and the assignment is CHECKED, because a select drops a value it has no option for');
ok(str_contains($gate, 'names an item that is not on the stock list'),
   '  with something said when it does');

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
echo "3. Driven: pick a contract line, see the item fill\n";

$a = strpos($gate, '<div style="overflow-x:auto"><table class="ig-tbl"');
if ($a === false) $a = strpos($gate, '<table class="ig-tbl"');
$b = strpos($gate, '</table>', $a);
ok($a !== false && $b !== false, 'the items grid is where it was');
$frag = substr($gate, $a, $b - $a + strlen('</table>'));

$tpl = '<?php
function e($s){ return htmlspecialchars((string)$s, ENT_QUOTES); }
function inv_num($v){ return (float)$v; }
$dir = "out"; $IS_OUT = true;
$gst = false;
$stockItems = [
  ["key"=>"m12","kind"=>"mat","id"=>12,"code"=>"FAB-012","name"=>"Cotton greige 60s","grp"=>"Fabric",
   "stage"=>"grey","uom"=>"MTR","rate"=>210.5,"bal"=>[0=>900,1=>900],"val"=>[],"sizes"=>[]],
  ["key"=>"p7","kind"=>"prod","id"=>7,"code"=>"PRD-7","name"=>"Comforter Set 7 Pc","grp"=>"Finished goods",
   "stage"=>"product","uom"=>"SET","rate"=>0,"bal"=>[0=>300,1=>300],"val"=>[],"sizes"=>["King","Queen"]],
];
$materials = [];
$lines = [["material_id"=>0,"product_id"=>0,"lot_no"=>"","qty"=>"","uom"=>"","rate"=>"",
           "description"=>"","contract_id"=>0,"contract_item_id"=>0,"size_label"=>""]];
$D = ["location_id"=>1,"gst_applies"=>0,"gst_pct"=>18];
?>' . $frag;
file_put_contents($work . '/frag.php', $tpl);
$html = (string)shell_exec('php ' . escapeshellarg($work . '/frag.php') . ' 2>&1');
ok(!str_contains($html, 'Fatal'), 'the grid renders: ' . substr(trim($html), 0, 200));
ok(str_contains($html, 'class="ig-inp cline'), '  with a contract box on the line');
ok(str_contains($html, 'matsel'), '  and an item select');

/* The two functions under test, lifted whole. */
$pa = strpos($gate, "    pick: function(f, r){\n      var tr = f.closest('tr');\n      if(r.clear)");
$pb = strpos($gate, "\n    }\n  });", $pa);
/* The closing brace of pick() is part of the anchor below it, so the slice
   has to put it back — without it the object literal in the harness is
   unbalanced and nothing on the page parses at all. */
$pick = substr($gate, $pa, $pb - $pa) . "\n    }";
ok($pick !== '' && str_contains($pick, "var key = l.material_id"), 'the contract pick lifted');
ok(substr_count($pick, '{') === substr_count($pick, '}'),
   '  with balanced braces, got ' . substr_count($pick, '{') . ' open, ' . substr_count($pick, '}') . ' close');

$CLINES = [
  ['id'=>501,'contract_id'=>3,'contract_no'=>'SC-2609-0001','ctype'=>'sale',
   'material_id'=>0,'product_id'=>7,'item'=>'PRD-7 · Comforter Set 7 Pc','item_name'=>'Comforter Set 7 Pc',
   'description'=>'7 pc comforter set, King','qty'=>500,'done'=>0,'balance'=>500,'over'=>false,
   'uom'=>'SET','rate'=>4200.00,'complete'=>false],
  ['id'=>502,'contract_id'=>3,'contract_no'=>'SC-2609-0001','ctype'=>'sale',
   'material_id'=>12,'product_id'=>0,'item'=>'FAB-012 · Cotton greige 60s','item_name'=>'Cotton greige 60s',
   'description'=>'','qty'=>900,'done'=>0,'balance'=>900,'over'=>false,
   'uom'=>'MTR','rate'=>210.50,'complete'=>false],
  ['id'=>503,'contract_id'=>3,'contract_no'=>'SC-2609-0001','ctype'=>'sale',
   'material_id'=>99,'product_id'=>0,'item'=>'OLD-99 · Retired item','item_name'=>'Retired item',
   'description'=>'','qty'=>50,'done'=>0,'balance'=>50,'over'=>false,
   'uom'=>'PCS','rate'=>10.00,'complete'=>false],
];

$harness = '<!doctype html><html><head><meta charset="utf-8"></head><body>'
  . '<select id="pSel"><option value="1" selected>ABRAR AHMED</option></select>'
  . '<select id="locSel"><option value="1" selected>Main Store</option></select>'
  /* The fragment is already a whole <table>. Wrapping it in another one
     made the HTML parser hoist the inner table out of the outer tbody, and
     the row selector then matched nothing at all. */
  . $html
  . '<scr' . 'ipt>'
  . 'var CLMSG = "", CLINES = ' . json_encode($CLINES) . ';'
  . 'var ONHAND = {}, TOL = 10, ISADMIN = false, IS_OUT = true;'
  . 'function num(v){ v = parseFloat(String(v==null?"":v).replace(/,/g,"")); return isNaN(v)?0:v; }'
  . 'function tot(){} function clWarn(){} function refreshRow(){} function stockCheck(){}'
  . 'function detailOf(tr){ var n = tr.nextElementSibling; return (n && n.classList.contains("detail")) ? n : null; }'
  . 'function syncAll(){ [].forEach.call(document.querySelectorAll(".matbox"), function(box){'
  . '  var sel = box.querySelector(".matsel"), q = box.querySelector(".matq");'
  . '  if(!sel || !q) return;'
  . '  q.value = (sel.value && sel.options[sel.selectedIndex]) ? sel.options[sel.selectedIndex].text : "";'
  . '}); }'
  . 'var LOVPICK = { ' . substr($pick, strpos($pick, 'pick:')) . ' };'
  . 'window.doPick = function(lineId){'
  . '  var tr = document.querySelector(".ig-tbl tbody tr:not(.detail)");'
  . '  var f  = tr.querySelector(".cline");'
  . '  var l  = null; for (var i=0;i<CLINES.length;i++) if(CLINES[i].id === lineId) l = CLINES[i];'
  . '  LOVPICK.pick(f, { l: l });'
  . '  var sel = tr.querySelector(".matsel");'
  . '  return { sel: sel.value, shown: (tr.querySelector(".matq")||{}).value || "",'
  . '           uom: (tr.querySelector(".uom")||{}).value || "",'
  . '           rate: (tr.querySelector(".rate")||{}).value || "",'
  . '           cid: (tr.querySelector(".cid")||{}).value || "",'
  . '           citem: (tr.querySelector(".citem")||{}).value || "",'
  . '           cline: f.value, set: f.classList.contains("set"), msg: CLMSG };'
  . '};'
  . 'window.reset = function(){'
  . '  var tr = document.querySelector(".ig-tbl tbody tr:not(.detail)");'
  . '  tr.querySelector(".matsel").value = "";'
  . '  [".uom",".rate",".cid",".citem"].forEach(function(s){ var e = tr.querySelector(s); if(e) e.value = ""; });'
  . '  var q = tr.querySelector(".matq"); if(q) q.value = "";'
  . '  CLMSG = "";'
  . '};'
  . '</scr' . 'ipt></body></html>';
file_put_contents($work . '/live.html', $harness);

$drive = <<<'JS'
const { chromium } = require('playwright');
(async () => {
  const br = await chromium.launch({ executablePath: '/opt/pw-browsers/chromium' });
  const pg = await br.newPage();
  const errs = []; pg.on('pageerror', e => errs.push(String(e)));
  await pg.goto('file://' + process.argv[2] + '/live.html');
  await pg.waitForTimeout(150);
  const R = { errs };
  R.product  = await pg.evaluate(() => window.doPick(501));
  await pg.evaluate(() => window.reset());
  R.material = await pg.evaluate(() => window.doPick(502));
  await pg.evaluate(() => window.reset());
  R.missing  = await pg.evaluate(() => window.doPick(503));
  await br.close();
  console.log(JSON.stringify(R));
})();
JS;
file_put_contents($work . '/drive.js', $drive);
$raw = shell_exec('cd ' . escapeshellarg(__DIR__) . ' && node ' . escapeshellarg($work . '/drive.js')
                  . ' ' . escapeshellarg($work) . ' 2>&1');
$R = json_decode((string)$raw, true);

if (!is_array($R)) { echo "  FAIL: the driver did not run:\n" . substr((string)$raw, 0, 900) . "\n"; $F++; }
else {
    ok(empty($R['errs']), 'the contract pick runs clean: ' . json_encode($R['errs']));

    /* A SALE CONTRACT FOR A FINISHED PRODUCT — the case that could never
       work, because only material_id was looked at. */
    $p = $R['product'];
    ok($p['sel'] === 'p7', 'A CONTRACT LINE FOR A PRODUCT FILLS THE ITEM, got ' . json_encode($p['sel']));
    ok(str_contains($p['shown'], 'Comforter Set 7 Pc'), '  and the box shows its name, got ' . json_encode($p['shown']));
    ok($p['uom'] === 'SET', '  the unit comes with it, got ' . json_encode($p['uom']));
    ok((float)$p['rate'] === 4200.0, '  and the agreed rate, got ' . json_encode($p['rate']));
    ok($p['cid'] === '3' && $p['citem'] === '501', '  the contract and its line are stored on the row');
    ok($p['cline'] === 'SC-2609-0001' && $p['set'] === true, '  and the box reads as set');
    ok($p['msg'] === '', '  with nothing to complain about');

    /* A MATERIAL LINE — the case that used to work by accident and stopped
       working when the select moved to keys. */
    $m = $R['material'];
    ok($m['sel'] === 'm12', 'a contract line for a MATERIAL fills the item, got ' . json_encode($m['sel']));
    ok(str_contains($m['shown'], 'Cotton greige 60s'), '  and shows its name');
    ok($m['uom'] === 'MTR' && (float)$m['rate'] === 210.5, '  with its unit and rate');

    /* A CONTRACT NAMING SOMETHING THE STOCK LIST DOES NOT CARRY. The select
       silently refuses the value; the screen must not silently carry on. */
    $x = $R['missing'];
    ok($x['sel'] === '', 'an item not on the stock list leaves the select empty, got ' . json_encode($x['sel']));
    ok(str_contains($x['shown'], 'Retired item'),
       '  but the name is still shown so the operator knows what was meant');
    ok(str_contains($x['msg'], 'not on the stock list'),
       '  AND IT IS SAID OUT LOUD, got ' . json_encode($x['msg']));
    ok($x['cid'] === '3', '  the contract is still recorded on the line');
}

echo "\n$P passed, $F failed\n";
exit($F ? 1 : 0);
