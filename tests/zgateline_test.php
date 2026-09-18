<?php
/* A CONTRACT ON EVERY GATE LINE.
 *
 * The contract used to live on the gate HEADER only, so one pass could
 * belong to exactly one contract. Real inward and outward passes do not
 * work that way — one truck arrives against three contracts.
 *
 * This test does not read inv_gate.php for strings. It RENDERS the real
 * item grid through PHP, loads it into Chromium with the page's real
 * stylesheet, and measures it; then it LIFTS the real picker JavaScript
 * out of the file, runs it against the real assets/js/lov.js, and drives
 * it the way an operator would.
 *
 * What it is protecting:
 *   - the new column must not make the row taller (that is the mistake
 *     this grid was rebuilt to remove, and I have now made it twice)
 *   - the column count and every colspan must still agree
 *   - the list must be the PARTY'S contracts, never everyone's
 *   - a quantity over the balance is MARKED, never refused
 *   - an empty box means "follow the header", and must not be able to
 *     silently become a stored link
 */

$B  = __DIR__ . '/app_src/public_html/';
$ig = file_get_contents($B . 'inv_gate.php');
$inv = file_get_contents($B . 'includes/inventory.php');

$P = 0; $F = 0;
function ok($c, $m) { global $P, $F; if ($c) { $P++; } else { $F++; echo "  FAIL: $m\n"; } }

/* The probe scripts live BESIDE this test, not in /tmp: node resolves
   require('playwright') from the script's own directory, and playwright is
   installed here. Run them out of /tmp and the harness fails for a reason
   that has nothing to do with the code under test. */
$work = __DIR__ . '/.zgateline';
@mkdir($work, 0777, true);

/* ------------------------------------------------------------------ */
echo "1. The reading side still falls back to the header\n";
/* Every gate line saved before today has contract_id NULL. If the
   fallback were ever dropped, every one of those lines would vanish off
   its contract — silently, with no error anywhere. */
$fallback = 'gi.contract_id = ? OR (gi.contract_id IS NULL AND g.contract_id = ?)';
ok(substr_count($inv, $fallback) >= 3,
   'all three balance queries keep the line-then-header fallback, got '
   . substr_count($inv, $fallback));
ok(str_contains($inv, 'ADD COLUMN contract_id'), 'the column is created on load');
ok(str_contains($inv, 'idx_gi_contract'), 'and indexed — it is joined on every balance read');

/* inv_contract_movements must test in the JOIN, not the WHERE, or an
   empty draft pass disappears off the contract it was raised for. */
$mv = substr($ig, 0, 0) . $inv;
if (preg_match('/function inv_contract_movements.*?\n}/s', $mv, $m)) {
    $body = $m[0];
    ok(strpos($body, $fallback) < strpos($body, 'WHERE g.contract_id'),
       'the line test sits in the JOIN, above the WHERE');
    ok(str_contains($body, 'WHERE g.contract_id = ? OR gi.id IS NOT NULL'),
       '  and a header-only pass with no matching line still shows');
} else { ok(false, 'inv_contract_movements() is still there'); }

echo "2. A line's contract and its contract line are kept together\n";
ok(str_contains($ig, 'if ($cLine && !$cHead) $cHead = inv_contract_of_line($cLine);'),
   'a line pulled in by the old button gets its contract filled in');
ok(str_contains($ig, 'if (!$cHead) $cLine = null;'),
   'and a pointer to a deleted contract line is dropped, not stored');
ok(str_contains($inv, 'function inv_contract_of_line'), 'the resolver exists');
/* It must read the CONTRACT LINE, not the gate header — the header may
   since have been changed to a different contract. */
if (preg_match('/function inv_contract_of_line.*?\n}/s', $inv, $m))
    ok(str_contains($m[0], 'FROM inv_contract_items WHERE id=?'),
       '  and it resolves from the contract line itself');

/* ------------------------------------------------------------------ */
echo "3. The grid renders, and the columns add up\n";

/* Pull the REAL fragment out of the page and render it. Nothing is
   retyped here — if the shipped markup changes, this changes with it. */
$a = strpos($ig, '<div style="overflow-x:auto"><table class="ig-tbl" id="glines">');
$b = strpos($ig, '</table></div>', $a);
ok($a !== false && $b !== false, 'the item grid is where it was');
$frag = substr($ig, $a, $b - $a + strlen('</table></div>'));

/* the page's own stylesheet, verbatim */
preg_match('/<style>(.*?)<\/style>/s', $ig, $sm);
$pageCss = $sm[1] ?? '';
ok(strlen($pageCss) > 2000, 'the page stylesheet came out whole');

function renderGrid(string $frag, string $dir, array $lines, array $doc): string {
    $tpl = '<?php
function e($s){ return htmlspecialchars((string)$s, ENT_QUOTES); }
$dir = ' . var_export($dir, true) . ';
$lines = ' . var_export($lines, true) . ';
$doc = ' . var_export($doc, true) . ';
$stockItems = [
  ["key"=>"m1","kind"=>"mat","id"=>1,"code"=>"FAB-001","name"=>"Cotton greige 60s","grp"=>"Fabric","stage"=>"grey","uom"=>"MTR","rate"=>210.5,"bal"=>[0=>900,2=>900],"val"=>[0=>189450,2=>189450],"sizes"=>[]],
  ["key"=>"m2","kind"=>"mat","id"=>2,"code"=>"BTN-014","name"=>"Button 4-hole","grp"=>"Accessories","stage"=>"na","uom"=>"PCS","rate"=>1.25,"bal"=>[0=>14000,3=>14000],"val"=>[0=>17500,3=>17500],"sizes"=>[]],
  ["key"=>"p9","kind"=>"prod","id"=>9,"code"=>"PRD-9","name"=>"Duvet cover king","grp"=>"Finished goods","stage"=>"product","uom"=>"PCS","rate"=>0,"bal"=>[0=>60,3=>60],"val"=>[],"sizes"=>["King","Queen"]],
];
$materials = [["id"=>1,"code"=>"FAB-001","name"=>"Cotton greige 60s","uom"=>"MTR","std_rate"=>210.5,"item_group"=>"Fabric"],
              ["id"=>2,"code"=>"BTN-014","name"=>"Button 4-hole","uom"=>"PCS","std_rate"=>1.25,"item_group"=>"Trim"]];
$products = [["id"=>7,"name"=>"Duvet cover king"]];
?>' . $frag;
    $f = __DIR__ . '/.zgateline/frag.php';
    file_put_contents($f, $tpl);
    $out = shell_exec('php ' . escapeshellarg($f) . ' 2>&1');
    return (string)$out;
}

$savedLine = [
    'material_id' => 1, 'product_id' => 0, 'lot_no' => 'LOT-77', 'uom' => 'MTR',
    'qty' => 400, 'rate' => 210.5, 'amount' => 84200, 'description' => '', 'packing' => '',
    'lcid' => 31, 'lcno' => 'PC-2026-0031', 'lctype' => 'purchase', 'cqty' => 1000,
    'contract_item_id' => 502,
];
$plainLine = ['material_id' => 2, 'qty' => 50, 'rate' => 1.25, 'uom' => 'PCS',
              'lcid' => 0, 'lcno' => null, 'contract_item_id' => null];
/* A genuinely empty line — the one the operator is about to type into.
   It is here because "the contract brings its unit and rate with it" can
   only be tested on a line that has neither yet; picking on a line that
   is already filled in must leave it alone, and that is the OTHER rule. */
$blankLine = ['material_id' => 0, 'qty' => '', 'rate' => '', 'uom' => '',
              'lcid' => 0, 'lcno' => null, 'contract_item_id' => null];

$html = [];
foreach (['in', 'out'] as $d) {
    $html[$d] = renderGrid($frag, $d, [$savedLine, $plainLine, $blankLine],
                           ['contract_no' => 'PC-2026-0031', 'id' => 5]);
    ok(!str_contains($html[$d], 'Fatal error') && !str_contains($html[$d], 'Warning:'),
       "the $d grid renders clean: " . substr(trim($html[$d]), 0, 120));
}

/* ------------------------------------------------------------------ */
echo "4. Measured in a browser, not guessed at\n";

$page = function (string $dir) use ($html, $pageCss, $B) {
    /* app.css carries  * { box-sizing:border-box }.  A harness without it
       measures a different box than the real page does, and I have been
       caught by exactly that before — so it is loaded here too. */
    return '<!doctype html><html><head><meta charset="utf-8">'
        . '<style>' . file_get_contents($B . 'assets/css/app.css') . '</style>'
        . '<link rel="stylesheet" href="file://' . $B . 'assets/css/lov.css">'
        . '<style>' . $pageCss . '</style></head><body style="width:1280px;margin:0">'
        . $html[$dir] . '</body></html>';
};
file_put_contents($work . '/grid_in.html', $page('in'));
file_put_contents($work . '/grid_out.html', $page('out'));

$probe = <<<'JS'
const { chromium } = require('playwright');
(async () => {
  const out = {};
  const br = await chromium.launch({ executablePath: '/opt/pw-browsers/chromium' });
  for (const dir of ['in', 'out']) {
    const pg = await br.newPage({ viewport: { width: 1280, height: 900 } });
    await pg.goto('file://' + process.argv[2] + '/grid_' + dir + '.html');
    out[dir] = await pg.evaluate(() => {
      const t = document.querySelector('#glines');
      const ths = t.querySelectorAll('thead th');
      const rows = [].slice.call(t.querySelectorAll('tbody tr'))
                     .filter(r => !r.classList.contains('detail'));
      const det = t.querySelector('tbody tr.detail');
      const foot = [].slice.call(t.querySelectorAll('tfoot tr'));
      const cline = rows[0].querySelector('.cline');
      const qty = rows[0].querySelector('.qty');
      return {
        cols: ths.length,
        headers: [].slice.call(ths).map(h => h.textContent.trim()),
        detColspan: det ? +det.querySelector('td').getAttribute('colspan') : 0,
        footWidths: foot.map(r => [].slice.call(r.children)
                      .reduce((n, td) => n + (+td.getAttribute('colspan') || 1), 0)),
        rowH: Math.round(rows[0].getBoundingClientRect().height),
        row2H: Math.round(rows[1].getBoundingClientRect().height),
        clineH: cline ? Math.round(cline.getBoundingClientRect().height) : 0,
        qtyH: qty ? Math.round(qty.getBoundingClientRect().height) : 0,
        clineTop: cline ? Math.round(cline.getBoundingClientRect().top) : 0,
        qtyTop: qty ? Math.round(qty.getBoundingClientRect().top) : 0,
        clineW: cline ? Math.round(cline.getBoundingClientRect().width) : 0,
        clineVal: cline ? cline.value : null,
        clineSet: cline ? cline.classList.contains('set') : false,
        row2Val: rows[1].querySelector('.cline').value,
        row2Ph: rows[1].querySelector('.cline').getAttribute('placeholder'),
        row2Set: rows[1].querySelector('.cline').classList.contains('set'),
        cid: rows[0].querySelector('.cid').value,
        citem: rows[0].querySelector('.citem').value,
        cidName: rows[0].querySelector('.cid').getAttribute('name'),
        citemName: rows[0].querySelector('.citem').getAttribute('name'),
        citemInDetail: !!(det && det.querySelector('.citem')),
        tableW: Math.round(t.getBoundingClientRect().width),
        overflow: t.scrollWidth > t.clientWidth + 1
      };
    });
    await pg.close();
  }
  await br.close();
  console.log(JSON.stringify(out));
})();
JS;
file_put_contents($work . '/probe.js', $probe);
$raw = shell_exec('cd ' . escapeshellarg(__DIR__) . ' && node ' . escapeshellarg($work . '/probe.js')
                  . ' ' . escapeshellarg($work) . ' 2>&1');
$M = json_decode((string)$raw, true);
if (!is_array($M)) { echo "  FAIL: the browser probe did not run:\n" . substr((string)$raw, 0, 900) . "\n"; $F++; }
else {
    /* Material, Contract, Lot, UOM, Quantity, Rate, Amount, ×  = 8
       plus Available on an outward pass                        = 9 */
    ok($M['in']['cols'] === 8, 'inward grid has 8 columns, got ' . $M['in']['cols']);
    ok($M['out']['cols'] === 9, 'outward grid has 9 columns, got ' . $M['out']['cols']);
    ok($M['in']['headers'][1] === 'Contract', 'Contract sits beside Material, got '
       . json_encode($M['in']['headers']));

    foreach (['in', 'out'] as $d) {
        ok($M[$d]['detColspan'] === $M[$d]['cols'],
           "$d: the detail strip spans the whole table ({$M[$d]['detColspan']} vs {$M[$d]['cols']})");
        foreach ($M[$d]['footWidths'] as $i => $w)
            ok($w === $M[$d]['cols'],
               "$d: total row $i adds up to {$M[$d]['cols']}, got $w");
    }

    /* THE POINT OF THE WHOLE COLUMN.
       A contract shown as a chip UNDER the item makes every line two lines
       tall whether it is used or not. It is a column precisely so the row
       does not grow — so the row height is measured, and the contract box
       is checked to be sitting on the SAME baseline as the quantity box,
       not below it. */
    foreach (['in', 'out'] as $d) {
        /* MEASURED, NOT ASSUMED. This grid's row is 47px and always has
           been: a 37px input, 3px of cell padding top and bottom, a 1px
           rule, and 3px of margin on the (empty) lot hint. I first wrote
           "<= 34px" here from memory of a different page's grid and it
           failed — so the number now comes from the page.
           What is actually being protected is that the contract does not
           add a SECOND LINE. A second line would put the row past 80px,
           so the test is against the input height, not a magic number. */
        ok($M[$d]['rowH'] - $M[$d]['qtyH'] <= 14,
           "$d: the row is one input tall plus chrome — no second line (row "
           . $M[$d]['rowH'] . 'px, input ' . $M[$d]['qtyH'] . 'px)');
        ok(abs($M[$d]['rowH'] - $M[$d]['row2H']) <= 1,
           "$d: the line WITH a contract is the same height as the one without ("
           . $M[$d]['rowH'] . ' vs ' . $M[$d]['row2H'] . ')');
        ok($M[$d]['clineTop'] === $M[$d]['qtyTop'],
           "$d: the contract box is beside the quantity box, not under the item");
        ok($M[$d]['clineH'] === $M[$d]['qtyH'],
           "$d: and it is the same height as every other cell input ("
           . $M[$d]['clineH'] . ' vs ' . $M[$d]['qtyH'] . ')');
    }
    ok($M['in']['clineW'] >= 120 && $M['in']['clineW'] <= 165,
       'the column is wide enough for a contract number and no wider, got '
       . $M['in']['clineW'] . 'px');

    echo "5. Three states, and they read differently\n";
    ok($M['in']['clineVal'] === 'PC-2026-0031', 'a line with its own contract shows it');
    ok($M['in']['clineSet'] === true, '  and is marked as chosen');
    ok($M['in']['row2Val'] === '', 'a line without one shows nothing in the box');
    ok($M['in']['row2Set'] === false, '  and is not marked as chosen');
    ok($M['in']['row2Ph'] === 'PC-2026-0031',
       '  but its placeholder is the HEADER contract, so "following" is visible');
    ok($M['in']['cid'] === '31' && $M['in']['citem'] === '502',
       'both halves of the link are stored on the line');
    ok($M['in']['cidName'] === 'line[0][contract_id]'
       && $M['in']['citemName'] === 'line[0][contract_item_id]',
       'and they post under the line, got ' . $M['in']['cidName']);
    ok($M['in']['citemInDetail'] === false,
       'the old hidden field is gone from the detail strip — one home, not two');
}

/* ------------------------------------------------------------------ */
echo "6. The picker itself, driven the way an operator drives it\n";

/* Lift the REAL provider out of the page. */
$ja = strpos($ig, '/* ---- contract on the line ---');
$jb = strpos($ig, 'LOV.attach(tb);', $ja);
ok($ja !== false && $jb !== false, 'the picker block is where it was');
$js = substr($ig, $ja, $jb - $ja);
/* one PHP tag inside it — the contract-type labels */
$js = preg_replace('/<\?=.*?\?>/s',
    '{"purchase":"Purchase","sales":"Sales","jobwork_out":"Job work — we send","jobwork_in":"Job work — we do"}',
    $js);
ok(!str_contains($js, '<?'), 'and it lifted with no PHP left in it');

/* The over-balance strip is repainted by the page's own input listener.
   Stubbing that listener in the harness would test the stub, so the REAL
   one is lifted too — if someone deletes the clWarn() call from it, this
   test goes red. */
$la = strpos($ig, "tb.addEventListener('input',function(e){");
$lb = strpos($ig, "\n  });", $la);
ok($la !== false && $lb !== false, 'the grid input listener is where it was');
$inputJs = substr($ig, $la, $lb - $la + 6);
ok(str_contains($inputJs, 'clWarn()'),
   '  and it is the one that repaints the over-balance strip');

$fixture = json_encode([
    ['id' => 502, 'contract_id' => 31, 'contract_no' => 'PC-2026-0031', 'ctype' => 'purchase',
     'material_id' => 1, 'product_id' => 0, 'item' => 'Cotton greige 60s', 'code' => 'FAB-001',
     'description' => 'Greige 60s 100% cotton', 'uom' => 'MTR', 'rate' => 210.5,
     'qty' => 1000, 'done' => 600, 'balance' => 400, 'complete' => false,
     'hay' => 'cotton greige 60s fab-001 pc-2026-0031 purchase'],
    ['id' => 503, 'contract_id' => 31, 'contract_no' => 'PC-2026-0031', 'ctype' => 'purchase',
     'material_id' => 2, 'product_id' => 0, 'item' => 'Button 4-hole', 'code' => 'BTN-014',
     'description' => '', 'uom' => 'PCS', 'rate' => 1.25,
     'qty' => 5000, 'done' => 5000, 'balance' => 0, 'complete' => true,
     'hay' => 'button 4-hole btn-014 pc-2026-0031 purchase'],
    ['id' => 610, 'contract_id' => 44, 'contract_no' => 'JW-2026-0044', 'ctype' => 'jobwork_out',
     'material_id' => 1, 'product_id' => 0, 'item' => 'Cotton greige 60s', 'code' => 'FAB-001',
     'description' => 'Send for dyeing', 'uom' => 'MTR', 'rate' => 18,
     'qty' => 2000, 'done' => 0, 'balance' => 2000, 'complete' => false,
     'hay' => 'cotton greige 60s fab-001 jw-2026-0044 jobwork_out'],
]);

/* The harness. Everything the lifted block reaches for that lives
   elsewhere in the page is stubbed HERE — never patched in the shipped
   file. A stub that is too thin is the harness's bug, not the app's. */
$harness = '<!doctype html><html><head><meta charset="utf-8">'
    . '<style>' . file_get_contents($B . 'assets/css/app.css') . '</style>'
    . '<link rel="stylesheet" href="file://' . $B . 'assets/css/lov.css">'
    . '<style>' . $pageCss . '</style></head><body style="width:1280px;margin:0">'
    . '<select id="pSel"><option value="0">— not listed —</option>'
    . '<option value="9" selected>Fine Weaving Mills</option>'
    . '<option value="12">Other Supplier</option></select>'
    . $html['in']
    . '<div id="cWarn" class="ig-note warn" style="display:none"></div>'
    . '<script src="file://' . $B . 'assets/js/lov.js"></script><script>'
    . 'window.FETCHED = [];
window.fetch = function(u){
  window.FETCHED.push(u);
  var pid = (String(u).match(/party_id=(\d+)/) || [])[1];
  /* party 12 genuinely has nothing — that is what proves the scoping */
  var rows = pid === "9" ? ' . $fixture . ' : [];
  return Promise.resolve({ json: function(){ return Promise.resolve({ ok:true, lines: rows }); } });
};
(function(){
  var tb = document.querySelector("#glines tbody");
  var pSel = document.getElementById("pSel");
  var IS_OUT = false;
  function num(v){ var n = parseFloat(String(v).replace(/[^0-9.\-]/g,"")); return isNaN(n)?0:n; }
  function lines(){ return [].slice.call(tb.querySelectorAll("tr"))
    .filter(function(tr){ return !tr.classList.contains("detail"); }); }
  function detailOf(tr){ var n = tr.nextElementSibling;
    return (n && n.classList.contains("detail")) ? n : null; }
  function partyName(){ var o = pSel.options[pSel.selectedIndex]; return o ? o.text : ""; }
  window.TOTS = 0;
  function tot(){ window.TOTS++; }
  function syncAll(){}
  function refreshRow(){}
  function stockCheck(){}
  ' . $js . '
  ' . $inputJs . '
  LOV.attach(tb);
  /* what the page does on load */
  lines().forEach(function(tr){
    var cid = tr.querySelector(".cid"), f = tr.querySelector(".cline");
    if(f && cid && +cid.value) f.classList.add("set");
  });
  window.T = { lines:lines, clLoad:clLoad, clPaint:clPaint, clWarn:clWarn,
               clRecheck:null, clOf:clOf, get CLMSG(){ return CLMSG; } };
  window.T.recheck = function(){ ' . '
    CLINES = null; CLPARTY = -1;
    var held = [];
    lines().forEach(function(tr, i){
      var cid = tr.querySelector(".cid"), f = tr.querySelector(".cline");
      if(cid && +cid.value){ held.push(i + 1); clClear(tr); }
    });
    CLMSG = held.length ? "<b>Contract cleared on " + held.length + " line(s).</b>" : "";
    clLoad(function(){ lines().forEach(clPaint); clWarn(); });
    clWarn();
  };
  clLoad(function(){ lines().forEach(clPaint); clWarn(); });
})();
</' . 'script></body></html>';
file_put_contents($work . '/pick.html', $harness);

$drive = <<<'JS'
const { chromium } = require('playwright');
(async () => {
  const br = await chromium.launch({ executablePath: '/opt/pw-browsers/chromium' });
  const pg = await br.newPage({ viewport: { width: 1280, height: 900 } });
  const errs = [];
  pg.on('pageerror', e => errs.push(String(e)));
  await pg.goto('file://' + process.argv[2] + '/pick.html');
  await pg.waitForTimeout(150);
  const R = { errs };

  const rowCline = n => `#glines tbody tr:not(.detail):nth-of-type(${n}) .cline`;

  // open the picker on the THIRD line — the empty one, the line an
  // operator would actually be typing into
  await pg.click('#glines tbody tr:not(.detail):nth-of-type(5) .cline');
  await pg.waitForTimeout(120);
  R.openRows = await pg.$$eval('.lov-r', rs => rs.map(r => r.textContent));
  R.openTitle = await pg.$eval('.lov .ttl', e => e.textContent).catch(() => null);
  // the completed line is hidden behind "show N already completed"
  R.xtra = await pg.$eval('.lov .xtra', e => e.textContent.trim()).catch(() => '');

  // fuzzy: type part of the OTHER contract's number
  await pg.fill('#glines tbody tr:not(.detail):nth-of-type(5) .cline', 'jw44');
  await pg.waitForTimeout(120);
  R.fuzzyTop = await pg.$eval('.lov-r.on', r => r.textContent).catch(() => null);
  R.fuzzyCount = await pg.$$eval('.lov-r', rs => rs.length);

  // Enter takes it
  await pg.keyboard.press('Enter');
  await pg.waitForTimeout(120);
  R.afterPick = await pg.evaluate(() => {
    const tr = document.querySelectorAll('#glines tbody tr:not(.detail)')[2];
    const det = tr.nextElementSibling;
    return {
      text: tr.querySelector('.cline').value,
      set: tr.querySelector('.cline').classList.contains('set'),
      cid: tr.querySelector('.cid').value,
      citem: tr.querySelector('.citem').value,
      uom: tr.querySelector('.uom').value,
      rate: tr.querySelector('.rate').value,
      desc: det ? det.querySelector('.desc').value : null,
      focused: document.activeElement.className,
      rowH: Math.round(tr.getBoundingClientRect().height)
    };
  });

  // a quantity OVER the balance is marked, not refused
  await pg.fill('#glines tbody tr:not(.detail):nth-of-type(5) .qty', '2500');
  await pg.waitForTimeout(80);
  R.over = await pg.evaluate(() => ({
    qty: document.querySelectorAll('#glines tbody tr:not(.detail)')[2].querySelector('.qty').value,
    marked: document.querySelectorAll('#glines tbody tr:not(.detail)')[2]
              .querySelector('.cline').classList.contains('over'),
    warn: document.getElementById('cWarn').style.display !== 'none',
    text: document.getElementById('cWarn').textContent
  }));

  // back under the balance and the marking goes away again
  await pg.fill('#glines tbody tr:not(.detail):nth-of-type(5) .qty', '100');
  await pg.waitForTimeout(80);
  R.under = await pg.evaluate(() => ({
    marked: document.querySelectorAll('#glines tbody tr:not(.detail)')[2]
              .querySelector('.cline').classList.contains('over'),
    warn: document.getElementById('cWarn').style.display !== 'none'
  }));

  // the clear row: only offered once something is set
  await pg.click('#glines tbody tr:not(.detail):nth-of-type(5) .cline');
  await pg.waitForTimeout(120);
  R.clearOffered = await pg.$$eval('.lov-r', rs => rs.map(r => r.textContent));
  R.clearIsLast = await pg.$$eval('.lov-r', rs =>
    rs.length > 1 && /no contract/.test(rs[rs.length - 1].textContent)
               && !/no contract/.test(rs[0].textContent));
  await pg.keyboard.press('End');
  await pg.keyboard.press('Enter');
  await pg.waitForTimeout(120);
  R.afterClear = await pg.evaluate(() => {
    const tr = document.querySelectorAll('#glines tbody tr:not(.detail)')[2];
    return { text: tr.querySelector('.cline').value, cid: tr.querySelector('.cid').value,
             citem: tr.querySelector('.citem').value,
             set: tr.querySelector('.cline').classList.contains('set') };
  });

  // half-typed and abandoned must not become a choice
  await pg.click('#glines tbody tr:not(.detail):nth-of-type(5) .cline');
  await pg.fill('#glines tbody tr:not(.detail):nth-of-type(5) .cline', 'PC-99');
  await pg.keyboard.press('Escape');
  await pg.waitForTimeout(220);
  R.abandoned = await pg.evaluate(() => {
    const tr = document.querySelectorAll('#glines tbody tr:not(.detail)')[2];
    return { text: tr.querySelector('.cline').value, cid: tr.querySelector('.cid').value };
  });

  // the list is the PARTY'S — switch party and it empties
  R.beforeSwitch = await pg.evaluate(() => {
    const tr = document.querySelectorAll('#glines tbody tr:not(.detail)')[0];
    return { cid: tr.querySelector('.cid').value };
  });
  await pg.selectOption('#pSel', '12');
  await pg.evaluate(() => window.T.recheck());
  await pg.waitForTimeout(200);
  R.afterSwitch = await pg.evaluate(() => {
    const tr = document.querySelectorAll('#glines tbody tr:not(.detail)')[0];
    return { cid: tr.querySelector('.cid').value, citem: tr.querySelector('.citem').value,
             text: tr.querySelector('.cline').value,
             set: tr.querySelector('.cline').classList.contains('set'),
             warn: document.getElementById('cWarn').style.display !== 'none',
             warnText: document.getElementById('cWarn').textContent };
  });
  await pg.click('#glines tbody tr:not(.detail):nth-of-type(1) .cline');
  await pg.waitForTimeout(150);
  R.otherPartyRows = await pg.$$eval('.lov-r', rs => rs.length);
  R.otherPartyEmpty = await pg.$eval('.lov-none', e => e.textContent).catch(() => null);
  R.fetched = await pg.evaluate(() => window.FETCHED);

  await br.close();
  console.log(JSON.stringify(R));
})();
JS;
file_put_contents($work . '/drive.js', $drive);
$raw2 = shell_exec('cd ' . escapeshellarg(__DIR__) . ' && node ' . escapeshellarg($work . '/drive.js')
                   . ' ' . escapeshellarg($work) . ' 2>&1');
$R = json_decode((string)$raw2, true);
if (!is_array($R)) { echo "  FAIL: the picker probe did not run:\n" . substr((string)$raw2, 0, 900) . "\n"; $F++; }
else {
    ok(empty($R['errs']), 'the lifted picker runs with no script errors: ' . json_encode($R['errs']));

    echo "   opening it\n";
    ok(count($R['openRows']) === 2,
       'only the two lines with a balance are shown, got ' . count($R['openRows']));
    ok(str_contains(implode('|', $R['openRows']), 'PC-2026-0031'), '  the purchase line is there');
    ok(str_contains(implode('|', $R['openRows']), 'JW-2026-0044'), '  the job work line is there');
    ok(!str_contains(implode('|', $R['openRows']), 'Button'),
       '  the completed line is not in the way');
    ok(str_contains((string)$R['xtra'], 'already completed'),
       '  but it is one click away, not hidden: ' . $R['xtra']);
    ok(str_contains((string)$R['openTitle'], 'Fine Weaving Mills'),
       '  and the panel says whose contracts these are');

    echo "   typing\n";
    ok(str_contains((string)$R['fuzzyTop'], 'JW-2026-0044'),
       '"jw44" lands on JW-2026-0044, got ' . json_encode($R['fuzzyTop']));
    ok($R['fuzzyCount'] === 1, '  and nothing else survives, got ' . $R['fuzzyCount']);

    echo "   taking a line\n";
    ok($R['afterPick']['text'] === 'JW-2026-0044', 'the box shows the contract');
    ok($R['afterPick']['set'] === true, '  marked as chosen');
    ok($R['afterPick']['cid'] === '44', '  the contract is stored, got ' . $R['afterPick']['cid']);
    ok($R['afterPick']['citem'] === '610', '  and the contract LINE, got ' . $R['afterPick']['citem']);
    ok($R['afterPick']['uom'] === 'MTR', '  the unit came with it');
    ok($R['afterPick']['rate'] === '18.0000', '  and the agreed rate, got ' . $R['afterPick']['rate']);
    ok($R['afterPick']['desc'] === 'Send for dyeing', '  and the contract\'s own wording');
    ok(str_contains((string)$R['afterPick']['focused'], 'qty'),
       '  and the cursor is in Quantity — nothing else left to say');
    ok($R['afterPick']['rowH'] <= 50,
       '  the row did not grow when the contract went in, got ' . $R['afterPick']['rowH'] . 'px');

    echo "   more than the contract says\n";
    ok($R['over']['qty'] === '2500', 'the quantity is kept exactly as typed — never clamped');
    ok($R['over']['marked'] === true, '  the box is marked');
    ok($R['over']['warn'] === true, '  and the strip explains it');
    ok(str_contains($R['over']['text'], 'allowed'),
       '  in words that say it is allowed: ' . substr($R['over']['text'], 0, 90));
    ok(str_contains($R['over']['text'], '2,000'), '  naming the balance it passed');
    ok($R['under']['marked'] === false && $R['under']['warn'] === false,
       'and it all clears again when the quantity comes back down');

    echo "   taking it off again\n";
    ok(str_contains(implode('|', $R['clearOffered']), 'no contract'),
       'a chosen line offers a way to un-choose, got ' . json_encode($R['clearOffered']));
    /* Opening the box selects its text, so row 0 is what Enter takes. Put
       "no contract" there and the reflex of open-Enter wipes the link. */
    ok($R['clearIsLast'] === true,
       '  and it is the LAST row, so Enter can never wipe a contract by reflex');
    ok($R['afterClear']['cid'] === '' && $R['afterClear']['citem'] === '',
       '  and it clears BOTH halves, not one');
    ok($R['afterClear']['text'] === '' && $R['afterClear']['set'] === false,
       '  and the box goes back to empty');

    echo "   half-typed is not a choice\n";
    ok($R['abandoned']['cid'] === '',
       'typing then pressing Escape stores nothing, got ' . json_encode($R['abandoned']));
    ok($R['abandoned']['text'] === '',
       '  and the box is put back, not left holding a search');

    echo "   the list belongs to the party\n";
    ok($R['beforeSwitch']['cid'] === '31', 'line 1 starts on the first party\'s contract');
    ok($R['afterSwitch']['cid'] === '' && $R['afterSwitch']['citem'] === '',
       'changing the party drops it — it belonged to someone else');
    ok($R['afterSwitch']['set'] === false && $R['afterSwitch']['text'] === '',
       '  and the box shows that it is gone');
    ok($R['afterSwitch']['warn'] === true, '  and it is SAID, not done silently');
    ok(str_contains($R['afterSwitch']['warnText'], 'cleared'),
       '  in so many words: ' . substr($R['afterSwitch']['warnText'], 0, 80));
    ok($R['otherPartyRows'] === 0, 'the new party has no contract lines, so none are offered');
    ok(str_contains((string)$R['otherPartyEmpty'], 'no draft or active contract'),
       '  and the panel explains why it is empty: ' . $R['otherPartyEmpty']);
    $f = implode(' ', $R['fetched']);
    ok(str_contains($f, 'party_id=9') && str_contains($f, 'party_id=12'),
       'both parties were asked for by id');
    ok(!preg_match('/plines(?!.*party_id)/', $f),
       'and the picker NEVER asks for contract lines without a party');
}

/* ------------------------------------------------------------------ */
echo "7. The server endpoint is party-scoped too\n";
/* The browser filter is a convenience. The endpoint is the rule. */
ok(str_contains($ig, "=== 'plines'"), 'the endpoint exists');
if (preg_match("/=== 'plines'.*?exit;\n}/s", $ig, $m)) {
    ok(str_contains($m[0], 'inv_party_contract_lines($pid)'),
       '  and it can only answer for one party');
    ok(!str_contains($m[0], 'contract_id'),
       '  there is no way to ask it for a contract directly');
}
if (preg_match('/function inv_party_contract_lines.*?\n}/s', $inv, $m)) {
    ok(str_contains($m[0], 'WHERE c.party_id = ?'), 'the query is scoped to the party');
    ok(str_contains($m[0], "c.status IN ('draft','active')"),
       '  and closed and cancelled contracts stay out');
    ok(str_contains($m[0], "g.status = 'posted'"),
       '  balance counts posted passes only — a draft is not a delivery');
}

echo "\n$P passed, $F failed\n";
exit($F ? 1 : 0);
