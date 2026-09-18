<?php
/* THE GATE ITEM LIST — the one the owner kept reporting as broken.
 *
 *   "Gate in/out still issue with list as please copy as store issue and or
 *    use production where list show of operation something like as show
 *    contract and if no so use without as well"
 *
 * He was right, and the cause was not in the list at all.
 *
 * When the contract moved out of the header into the item list, the
 * <select id="cSel"> became <input type="hidden" id="cSel">. The code that
 * drove it as a dropdown stayed behind, and its first line ran at page
 * load:  [].slice.call(cSel.options).  An <input> has no .options, so that
 * is Array.prototype.slice.call(undefined) — a TypeError at the top level
 * of the script, which stops the script dead. Everything after it never
 * ran, including syncAll(), which is what hides the fallback <select> on
 * each line and shows the box the picker attaches to.
 *
 * So: the list was fine and had never been switched on. Section 1 below
 * pins that mechanism down so it cannot come back quietly. Sections 2-4
 * run the REAL itemRowsFor() out of the shipped file, against a fixture,
 * for the rules he stated: the contract if there is one, and without a
 * contract if there is not.
 */

$B = __DIR__ . '/app_src/public_html/';
$P = 0; $F = 0;
function ok($c, $m) { global $P, $F; if ($c) { $P++; } else { $F++; echo "  FAIL: $m\n"; } }

$gate = file_get_contents($B . 'inv_gate.php');
/* A COMMENT IS NOT CODE. This file explains the crash by quoting the
   expression that caused it, and a plain grep reads its own documentation
   as the fault it warns about. The "is it gone" checks below run against a
   stripped copy; everything else keeps the real text. */
$gateC = preg_replace('!/\*.*?\*/!s', '', $gate);
$inv  = file_get_contents($B . 'includes/inventory.php');
$work = __DIR__ . '/.zgatelist';
@mkdir($work, 0777, true);

/* Lift a run of real source out of a shipped file, so the test runs the
   code that ships rather than a copy of it. */
function lift(string $src, string $from, string $to): string {
    $a = strpos($src, $from);
    if ($a === false) return '';
    $b = strpos($src, $to, $a);
    if ($b === false) return '';
    return substr($src, $a, $b - $a + strlen($to));
}

echo "1. The crash that killed the whole form\n";
/* The element really is a hidden input now — that part was deliberate. */
ok(str_contains($gate, '<input type="hidden" name="contract_id" id="cSel"'),
   'the header contract is a hidden input, not a dropdown');
/* And nothing may drive it as a dropdown any more. These four are the
   exact expressions that were left behind. */
ok(!str_contains($gateC, 'cSel.options'),   'nothing reads .options off it');
ok(!str_contains($gateC, 'cSel.innerHTML'), 'nothing writes <option> into it');
ok(!str_contains($gateC, 'cSel.disabled'),  'nothing disables it like a control');
ok(!str_contains($gateC, 'function fillContracts'), 'the dropdown filler is gone');
ok(!preg_match('/\bfillContracts\(\)/', $gateC), '  and every call to it with it');
ok(!str_contains($gateC, 'var CONTRACTS ='), 'the list it was built from is gone');
ok(!str_contains($gate, "getElementById('cHint')"),
   'and the hint line it wrote to, which no longer exists in the markup');
ok(!str_contains($gate, 'id="cHint"'), '  the markup really has no cHint');

/* THE ONE THING BELOW IT THAT MATTERED. syncAll() is what swaps the plain
   <select> on each line for the search box. It has to run at load. */
$tail = substr($gate, strpos($gate, 'var pSel  = document.querySelector'));
ok(str_contains($tail, 'syncAll();'), 'syncAll() still runs after all of this');

echo "2. Why it was fatal, in a browser\n";
$probe = <<<'HTML'
<!doctype html><meta charset="utf-8"><input type="hidden" id="cSel" value="7">
<script>
window.R = (function(){
  var el = document.getElementById('cSel');
  var r = { optionsIsUndefined: el.options === undefined, threw: false, msg: '' };
  try { [].slice.call(el.options); } catch (e) { r.threw = true; r.msg = String(e.name); }
  return r;
})();
</script>
HTML;
file_put_contents($work . '/crash.html', $probe);
$drv = <<<'JS'
const { chromium } = require('playwright');
(async () => {
  const br = await chromium.launch({ executablePath: '/opt/pw-browsers/chromium' });
  const pg = await br.newPage();
  await pg.goto('file://' + process.argv[2] + '/crash.html');
  console.log(JSON.stringify(await pg.evaluate(() => window.R)));
  await br.close();
})();
JS;
file_put_contents($work . '/crash.js', $drv);
$c = json_decode((string)shell_exec('node ' . escapeshellarg($work . '/crash.js') . ' '
                                    . escapeshellarg($work) . ' 2>&1'), true);
if (!is_array($c)) { echo "  FAIL: the crash probe did not run\n"; $F++; }
else {
    ok($c['optionsIsUndefined'] === true, 'a hidden input has no .options');
    ok($c['threw'] === true && $c['msg'] === 'TypeError',
       'AND SLICING IT THROWS A TypeError — which is what stopped the script: '
       . json_encode($c));
}

echo "3. The engine gives the browser what it needs to judge a contract\n";
ok(str_contains($inv, "'status'      => (string)\$l['status']"),
   'inv_party_contract_lines() returns the contract status');
ok(str_contains($inv, "c.status IN ('draft','active')"),
   '  and still fetches drafts, so the screen can SAY how many are held back');

echo "4. The list itself, run for real\n";
$rowsFn = lift($gate, '  function itemRowsFor(q, showAll){', "    return [out, hidden];\n  }");
ok($rowsFn !== '', 'itemRowsFor lifted out of the shipped file');

$lov = file_get_contents($B . 'assets/js/lov.js');

/* Two materials and one product. Fabric is in stock at location 1;
   thread is not; the product is. */
$ITEMS = [
  ['key'=>'m1','kind'=>'mat','id'=>1,'code'=>'FAB-01','name'=>'Cotton Fabric 200TC',
   'grp'=>'Fabric','uom'=>'MTR','rate'=>420.0,'bal'=>['0'=>1840,'1'=>1840],'val'=>['0'=>772800,'1'=>772800]],
  ['key'=>'m2','kind'=>'mat','id'=>2,'code'=>'THR-09','name'=>'Sewing Thread',
   'grp'=>'Trims','uom'=>'CON','rate'=>95.0,'bal'=>[],'val'=>[]],
  ['key'=>'p5','kind'=>'prod','id'=>5,'code'=>'PRD-5','name'=>'7pc Comforter Set',
   'grp'=>'Finished goods','uom'=>'PCS','rate'=>0,'bal'=>['0'=>60,'1'=>60],'val'=>['0'=>252000,'1'=>252000]],
];
/* Four contract lines for ONE party: an active sales line, an active
   purchase line, a draft sales line, and a sales line for an item that is
   not on the stock list at all. */
$CLINES = [
  ['id'=>11,'contract_id'=>3,'contract_no'=>'SC-0007','ctype'=>'sales','status'=>'active',
   'material_id'=>0,'product_id'=>5,'item'=>'7pc Comforter Set','code'=>'',
   'description'=>'','uom'=>'PCS','rate'=>4200,'qty'=>100,'done'=>40,'balance'=>60,'complete'=>false],
  ['id'=>12,'contract_id'=>4,'contract_no'=>'PC-0031','ctype'=>'purchase','status'=>'active',
   'material_id'=>1,'product_id'=>0,'item'=>'Cotton Fabric 200TC','code'=>'FAB-01',
   'description'=>'','uom'=>'MTR','rate'=>415,'qty'=>2000,'done'=>0,'balance'=>2000,'complete'=>false],
  ['id'=>13,'contract_id'=>5,'contract_no'=>'SC-0009','ctype'=>'sales','status'=>'draft',
   'material_id'=>0,'product_id'=>5,'item'=>'7pc Comforter Set','code'=>'',
   'description'=>'','uom'=>'PCS','rate'=>4300,'qty'=>50,'done'=>0,'balance'=>50,'complete'=>false],
  ['id'=>14,'contract_id'=>3,'contract_no'=>'SC-0007','ctype'=>'sales','status'=>'active',
   'material_id'=>0,'product_id'=>0,'item'=>'','code'=>'',
   'description'=>'Gift box printing','uom'=>'PCS','rate'=>35,'qty'=>100,'done'=>0,'balance'=>100,'complete'=>false],
];

$harness = '<!doctype html><meta charset="utf-8"><body><script>' . $lov . '</script><script>'
  . 'var ITEMS = ' . json_encode($ITEMS, JSON_UNESCAPED_UNICODE) . ';'
  . 'var CLINES = ' . json_encode($CLINES, JSON_UNESCAPED_UNICODE) . ';'
  . 'var CLPARTY = 1;'
  /* the knobs each scenario turns */
  . 'var MODE = "all", WANT = "sales";'
  . 'function ctypeOf(){ return WANT; }'
  . 'function pickMode(){ return MODE; }'
  . 'function clParty(){ return 1; }'
  . 'function partyName(){ return "ABRAR AHMED"; }'
  . 'function itemByKey(k){ for(var i=0;i<ITEMS.length;i++) if(ITEMS[i].key===k) return ITEMS[i]; return null; }'
  . 'function balOf(it){ if(!it) return 0; var m=it.bal||{}; return m["1"]!==undefined?m["1"]:(m["0"]||0); }'
  . 'function rateFor(it){ return it ? it.rate : 0; }'
  . $rowsFn
  /* Report a run as plain shapes the test can assert on. */
  . 'window.run = function(mode, want, q, showAll){'
  . '  MODE = mode; WANT = want;'
  . '  var r = itemRowsFor(q || "", !!showAll);'
  . '  return { heads: r[0].filter(function(x){return x.__sep;}).map(function(x){return x.__sep;}),'
  . '           contracts: r[0].filter(function(x){return !x.__sep && x.cl;})'
  . '                          .map(function(x){return x.cl.contract_no + "/" + x.cl.id;}),'
  . '           free: r[0].filter(function(x){return !x.__sep && !x.cl;})'
  . '                     .map(function(x){return x.it.code;}),'
  . '           hidden: r[1] };'
  . '};</script></body>';
file_put_contents($work . '/list.html', $harness);

$drive = <<<'JS'
const { chromium } = require('playwright');
(async () => {
  const br = await chromium.launch({ executablePath: '/opt/pw-browsers/chromium' });
  const pg = await br.newPage();
  const errs = []; pg.on('pageerror', e => errs.push(String(e)));
  await pg.goto('file://' + process.argv[2] + '/list.html');
  const R = { errs };
  R.saleIn    = await pg.evaluate(() => window.run('all',  'sales',    '', false));
  R.saleOut   = await pg.evaluate(() => window.run('here', 'sales',    '', false));
  R.purchIn   = await pg.evaluate(() => window.run('all',  'purchase', '', false));
  R.sample    = await pg.evaluate(() => window.run('all',  '',         '', false));
  R.sampleOut = await pg.evaluate(() => window.run('here', '',         '', false));
  R.outAll    = await pg.evaluate(() => window.run('here', 'sales',    '', true));
  R.typed     = await pg.evaluate(() => window.run('all',  'sales',    'thread', false));
  await br.close();
  console.log(JSON.stringify(R));
})();
JS;
file_put_contents($work . '/list.js', $drive);
$raw = shell_exec('node ' . escapeshellarg($work . '/list.js') . ' ' . escapeshellarg($work) . ' 2>&1');
$R = json_decode((string)$raw, true);

if (!is_array($R)) { echo "  FAIL: the list driver did not run:\n" . substr((string)$raw, 0, 900) . "\n"; $F++; }
else {
    ok(empty($R['errs']), 'it runs clean: ' . json_encode($R['errs']));

    /* --- A SALE, COMING IN. Only the party's SALES lines. --- */
    ok($R['saleIn']['contracts'] === ['SC-0007/11', 'SC-0007/14'],
       'a Sale offers only the sales contract lines, got ' . json_encode($R['saleIn']['contracts']));
    ok(!in_array('PC-0031/12', $R['saleIn']['contracts'], true),
       '  AND NEVER THE PURCHASE CONTRACT — that was the wrong booking waiting to happen');
    ok(!in_array('SC-0009/13', $R['saleIn']['contracts'], true),
       '  nor the DRAFT sales contract');
    ok(str_contains($R['saleIn']['heads'][0] ?? '', '1 more still in draft'),
       '  but it SAYS one is held back in draft, got ' . json_encode($R['saleIn']['heads']));
    ok(str_contains($R['saleIn']['heads'][0] ?? '', 'ABRAR AHMED'),
       '  under the party it belongs to');

    /* --- AND WITHOUT A CONTRACT, EVERY ITEM IS STILL THERE. --- */
    ok($R['saleIn']['free'] === ['FAB-01', 'PRD-5', 'THR-09'],
       'every item is offered underneath, contract or no contract, got '
       . json_encode($R['saleIn']['free']));
    ok(count($R['saleIn']['heads']) === 2
       && str_contains($R['saleIn']['heads'][1], 'Any other item'),
       '  under its own heading, got ' . json_encode($R['saleIn']['heads']));

    /* --- A PURCHASE. The other way round, same rule. --- */
    ok($R['purchIn']['contracts'] === ['PC-0031/12'],
       'a Purchase offers only the purchase line, got ' . json_encode($R['purchIn']['contracts']));

    /* --- A SAMPLE. No contract type at all, so no contract section —
           and the free list is untouched, which is the "use without" half
           of what was asked for. --- */
    ok($R['sample']['contracts'] === [],
       'a sample is under no contract, so none is offered, got ' . json_encode($R['sample']['contracts']));
    ok($R['sample']['heads'] === [],
       '  and no heading is drawn for an empty section, got ' . json_encode($R['sample']['heads']));
    ok($R['sample']['free'] === ['FAB-01', 'PRD-5', 'THR-09'],
       '  BUT EVERY ITEM IS STILL PICKABLE, got ' . json_encode($R['sample']['free']));
    ok($R['sampleOut']['free'] === ['FAB-01', 'PRD-5'],
       '  and going out, only what is in stock, got ' . json_encode($R['sampleOut']['free']));

    /* --- GOING OUT. Stock rules the free list; a contract line is shown
           whether or not there is stock behind it. --- */
    ok($R['saleOut']['free'] === ['FAB-01', 'PRD-5'],
       'out: thread has no stock so it is not offered, got ' . json_encode($R['saleOut']['free']));
    ok($R['saleOut']['hidden'] === 1,
       '  and it is counted as held back, not lost, got ' . json_encode($R['saleOut']['hidden']));
    ok($R['outAll']['free'] === ['FAB-01', 'PRD-5', 'THR-09'],
       '  "show all" brings it back, got ' . json_encode($R['outAll']['free']));
    ok($R['saleOut']['contracts'] === ['SC-0007/11', 'SC-0007/14'],
       'out: the contract lines are still shown, stock or no stock, got '
       . json_encode($R['saleOut']['contracts']));

    /* --- TYPING NARROWS BOTH SECTIONS. --- */
    ok($R['typed']['free'] === ['THR-09'],
       'typing "thread" finds the thread, got ' . json_encode($R['typed']['free']));
    ok($R['typed']['contracts'] === [],
       '  and no contract line pretends to match it, got ' . json_encode($R['typed']['contracts']));
}

echo "5. Changing the transaction type does not leave a stale contract behind\n";
ok(str_contains($gate, 'if(nowCT !== lastCT){ lastCT = nowCT; clRecheck(); }'),
   'a change of contract type rechecks the lines');
ok(str_contains($gate, 'var lastCT = ctypeOf();'), '  against what it was before');
ok(str_contains($gate, 'function ctypeOf(){ return CTYPE[ts.value]'),
   'and the type still comes from inv_gate_types(), not from a second list');

echo "\n$P passed, $F failed\n";
exit($F ? 1 : 0);
