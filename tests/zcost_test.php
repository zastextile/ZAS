<?php
/* THE COSTING GRID IS ONE LINE PER ROW, AND THE SKIN IS STILL REMOVABLE.
 *
 * The bug: product_costing.php's cost grid is table.ct — a THIRD table class
 * the skin never mapped. It got the new input styling and none of the row
 * work, so it stood at 53-58px a row while every other grid in the app is 30px.
 * Half of that was the item code sitting on its own line INSIDE the Item cell.
 *
 * This does not read the files for strings. It lifts the real functions out
 * of assets/js/costing.js, loads the real assets/css/zskin.css into a real
 * browser, and MEASURES what comes out. If the CSS specificity fight between
 * .zbtn.red and .rowx goes the wrong way, this fails. */

$B   = __DIR__ . '/app_src/public_html/';
$js  = file_get_contents($B . 'assets/js/costing.js');
$css = file_get_contents($B . 'assets/css/zskin.css');
$php = file_get_contents($B . 'product_costing.php');

$P = 0; $F = 0;
function ok($c, $m) { global $P, $F; if ($c) { $P++; } else { $F++; echo "  FAIL: $m\n"; } }

/* ---------- lift the four functions that build a row ---------- */
function grab(string $src, string $needle): string {
    $i = strpos($src, $needle);
    if ($i === false) { fwrite(STDERR, "missing: $needle\n"); exit(1); }
    $a = strpos($src, '{', $i); $d = 0;
    for ($j = $a; $j < strlen($src); $j++) {
        if ($src[$j] === '{') $d++;
        elseif ($src[$j] === '}') { $d--; if ($d === 0) return substr($src, $i, $j - $i + 1) . "\n"; }
    }
    exit(1);
}
$lifted = grab($js, 'function itemChipHtml(')
        . grab($js, 'function itemCellHtml(')
        . grab($js, 'function rowHtml(')
        . grab($js, 'function workmanshipRowHtml(')
        . grab($js, 'function lineAmt(');
file_put_contents(__DIR__ . '/.zc.js', $lifted);

/* the header row is markup, not a function — pull it out verbatim so the
   test compares the REAL header against the REAL rows */
if (!preg_match('/<table class="ct ct-edit"><thead>(.*?)<\/thead>/', $js, $m)) {
    echo "  FAIL: could not find the cost-grid header\n"; $F++; $m = [1 => '']; }
file_put_contents(__DIR__ . '/.zc_head.txt', $m[1]);

$harness = <<<'JS'
const fs = require('fs');
const { chromium } = require('playwright');

/* the globals the lifted functions read */
const stubs = `
  const CAN_EDIT = true, CAN_CREATE_ITEM = true, PRODUCT_ID = 13;
  const INV_ITEMS = [{id:1},{id:2}];
  const INV_BY_ID = {
    7:  {code:'FB-0001', group:'Fabric', uom:'Mtr'},
    9:  {code:'AC-0022', group:'Accessories', uom:'Pc'},
    11: {code:'PK-0003', group:'Packing', uom:'Ctn'}
  };
  const WORKMANSHIP_COMPONENT_RATES = {};
  function computeWorkmanship(){ return 318; }
  function esc(s){ return String(s==null?'':s).replace(/[&<>"]/g,
    c=>({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;'}[c])); }
  function fmtNum(v,d){ return Number(v).toFixed(d); }
  function n(v){ return Number(v)||0; }
`;

const LINES = [
  {item:'Cotton Percale 200TC', desc:'Flat sheet', qty:2.85, unit:'Mtr', weight:.185, rate:412,  shared:false, material_id:7},
  {item:'Woven Label - THQ',    desc:'Neck label', qty:4,    unit:'Pc',  weight:.001, rate:3.75, shared:false, material_id:9},
  /* typed, never linked — this is the row that must show "+ add" */
  {item:'Printed PVC Zip Bag',  desc:'Retail bag', qty:1,    unit:'Pc',  weight:.048, rate:62,   shared:false, material_id:0},
  /* a shared line: the ÷ toggle must survive untouched */
  {item:'Export Carton 5-ply',  desc:'Per 12 sets',qty:12,   unit:'Ctn', weight:1.9,  rate:340,  shared:true,  material_id:11},
  /* an empty line — no chip at all */
  {item:'',                     desc:'',           qty:0,    unit:'',    weight:0,    rate:0,    shared:false, material_id:0}
];

(async () => {
  const lifted = fs.readFileSync(process.argv[2], 'utf8');
  const head   = fs.readFileSync(process.argv[3], 'utf8');
  const css    = fs.readFileSync(process.argv[4], 'utf8');

  const page = `<!doctype html><meta charset="utf-8"><style>${css}</style>
    <style>body{margin:0;font:13px system-ui}
      /* the two rules the page itself owns that affect row height */
      .tablewrap{overflow:auto;border:1px solid #e3e9f2;border-radius:12px}
      table.ct{width:100%;border-collapse:collapse;min-width:920px;font-size:12.5px}
      table.ct th{background:#fff;color:#8a97ab;font-size:10.5px;text-transform:uppercase;
        letter-spacing:.04em;text-align:left;padding:5px 8px}
      table.ct td{padding:3px 8px;border-top:1px solid #f6f8fc}
      table.ct .zin{padding:4px 7px}
      .zin{width:100%;padding:10px 12px;border:1px solid #d3dce8;border-radius:10px;font-size:13.5px}
      .itagrow{margin-top:4px}
      .itag{display:inline-block;font-size:10px;font-weight:800;padding:2px 7px;border-radius:20px;
        border:1px solid #e3e9f2;background:#f6f8fc;color:#8a97ab;white-space:nowrap}
      .itag.ok{background:rgba(22,163,74,.1);border-color:transparent;color:#15803d}
      .mini{max-width:92px}.num{text-align:right}
      .qtywrap{display:flex;gap:6px;align-items:center}
      .shbtn{width:22px;height:22px;flex-shrink:0;border-radius:6px;border:1px solid #cbd5e3;
        background:#fff;color:#8a97ab;font-size:12px;line-height:1;display:inline-flex;
        align-items:center;justify-content:center;padding:0}
      .zbtn{padding:10px 16px;border:none;border-radius:11px;font-weight:700;font-size:13px;
        color:#fff;background:linear-gradient(100deg,#0ea8c9,#6d5bd0)}
      .zbtn.sm{padding:6px 10px;font-size:12px}
      .zbtn.red{background:rgba(224,67,93,.15);color:#b8283f;border:1px solid rgba(224,67,93,.3)}
      tr.wmrow{background:#f2f7fb} tr.wmrow td{padding-top:10px;padding-bottom:10px}
      .wmlabel{font-weight:700;color:#0c7a94;font-size:12.5px}
      .wmsrc{font-size:11px;color:#8a97ab;margin-top:2px}
    </style>
    <div class="zskin" id="skin">
      <div class="tablewrap"><table class="ct ct-edit" id="g">
        <thead>${head}</thead><tbody></tbody></table></div>
      <!-- THE OLD MARKUP, under the SAME stylesheet, so the "58px became
           30px" claim is measured rather than asserted. This is the exact
           cell that shipped before: the code chip stacked in a div under
           the item input. -->
      <div class="tablewrap"><table class="ct" id="old"><tbody><tr>
        <td><input class="zin" value="Cotton Percale 200TC">
            <div class="itagrow"><span class="itag ok">&#10003; FB-0001</span></div></td>
        <td><input class="zin" style="min-width:150px" value="Flat sheet"></td>
        <td><div class="qtywrap"><input class="zin mini" type="number" value="2.85">
            <button class="shbtn" type="button">&divide;</button></div></td>
        <td><input class="zin mini" value="Mtr"></td>
        <td><input class="zin mini" type="number" value="0.185"></td>
        <td><input class="zin mini" type="number" value="412"></td>
        <td class="num amt">1174.20</td>
        <td><button class="zbtn red sm" type="button">&times;</button></td>
      </tr></tbody></table></div>
    </div>
    <script>${stubs}\n${lifted}
      const LINES = ${JSON.stringify(LINES)};
      const p = { id: 1, lines: LINES, sizeIds: [91] };
      document.querySelector('#g tbody').innerHTML =
        LINES.map((l,i)=>rowHtml(p,l,i)).join('') + workmanshipRowHtml(p);
    <\/script>`;

  const b = await chromium.launch({ executablePath: '/opt/pw-browsers/chromium' });
  const pg = await b.newPage({ viewport: { width: 1280, height: 900 } });
  const errs = [];
  pg.on('pageerror', e => errs.push(String(e)));
  await pg.setContent(page);
  await pg.waitForTimeout(250);

  const out = await pg.evaluate(() => {
    const rows  = [...document.querySelectorAll('#g tbody tr')];
    const data  = rows.filter(r => !r.classList.contains('wmrow'));
    const wm    = document.querySelector('#g tbody tr.wmrow');
    const heads = [...document.querySelectorAll('#g thead th')];
    const h = el => Math.round(el.getBoundingClientRect().height);
    const cs = el => getComputedStyle(el);

    /* the delete button of row 1, before and while its row is hovered */
    const x = data[0].querySelector('.rowx');
    const qtyIn = data[0].querySelector('.qtywrap > .zin');
    const itemIn = data[0].querySelector('td:first-child > .zin');

    return {
      headCount:  heads.length,
      headText:   heads.map(t => t.textContent.trim()),
      cellCounts: data.map(r => r.children.length),
      rowHeights: data.map(h),
      wmSpan:     wm ? [...wm.children].reduce((a,c)=>a+(c.colSpan||1),0) : -1,
      wmHeight:   wm ? h(wm) : -1,
      /* the code lives in its OWN cell now, never stacked in the item cell */
      itemCellHasChip: !!data[0].querySelector('td:first-child .itag'),
      codeCellChip:    (data[0].querySelector('.codecell .itag')||{}).textContent || '',
      addLabel:        (data[2].querySelector('.codecell .itag.add')||{}).textContent || '',
      addTitle:        (data[2].querySelector('.codecell .itag.add')||{}).title || '',
      emptyCodeCell:   data[4].querySelector('.codecell').innerHTML.trim(),
      /* the ÷ toggle survived */
      sharedOn:  !!data[3].querySelector('.shbtn.on'),
      sharedAmt: data[3].querySelector('.amt').textContent.trim(),
      normalAmt: data[0].querySelector('.amt').textContent.trim(),
      /* the delete button must be a quiet glyph, NOT the red .zbtn block */
      xBg:      cs(x).backgroundColor,
      xColor:   cs(x).color,
      xWidth:   Math.round(x.getBoundingClientRect().width),
      /* .mini's 92px cap must not survive inside the grid */
      qtyMaxW:  cs(qtyIn).maxWidth,
      qtyAlign: cs(qtyIn).textAlign,
      /* flat cell: transparent border until touched */
      itemBorder: cs(itemIn).borderTopColor,
      itemRadius: cs(itemIn).borderTopLeftRadius,
      /* the shipped-before row, measured under this same stylesheet */
      oldHeight: h(document.querySelector('#old tbody tr'))
    };
  });

  /* PROVE REVERSIBILITY: drop the one .zskin attribute and the page must go
     back to the old tall grid, exactly as the skin's own comment promises. */
  await pg.evaluate(() => document.getElementById('skin').className = '');
  await pg.waitForTimeout(120);
  out.heightWithoutSkin = await pg.evaluate(() =>
    Math.round(document.querySelector('#g tbody tr').getBoundingClientRect().height));
  out.xBgWithoutSkin = await pg.evaluate(() =>
    getComputedStyle(document.querySelector('#g tbody tr .rowx')).backgroundColor);

  out.pageErrors = errs;
  console.log(JSON.stringify(out));
  await b.close();
})();
JS;
file_put_contents(__DIR__ . '/.zc_harness.js', $harness);

$raw = shell_exec('node ' . escapeshellarg(__DIR__ . '/.zc_harness.js') . ' '
    . escapeshellarg(__DIR__ . '/.zc.js') . ' '
    . escapeshellarg(__DIR__ . '/.zc_head.txt') . ' '
    . escapeshellarg($B . 'assets/css/zskin.css') . ' 2>&1');
$r = json_decode(trim((string)$raw), true);

if (!is_array($r)) {
    echo "  FAIL: the grid did not render at all —\n$raw\n";
    echo "\n0 passed, 1 failed\n"; exit(1);
}

echo "1. The grid renders without a single JavaScript error\n";
ok($r['pageErrors'] === [], 'no errors, got ' . json_encode($r['pageErrors']));

echo "2. Every row has exactly as many cells as the header has columns\n";
ok($r['headCount'] === 9, 'the header has 9 columns, got ' . $r['headCount']);
ok(in_array('Code', $r['headText'], true), 'one of them is Code: ' . json_encode($r['headText']));
ok($r['headText'][1] === 'Code', '  and it sits beside Item, got ' . json_encode($r['headText'][1]));
ok(count(array_unique($r['cellCounts'])) === 1 && $r['cellCounts'][0] === 9,
   'EVERY data row has 9 cells, got ' . json_encode($r['cellCounts']));
/* a colspan that does not add up silently shears the whole table */
ok($r['wmSpan'] === 9, 'the locked Workmanship row spans 9 too, got ' . $r['wmSpan']);

echo "3. The row is ONE line — this is the whole point\n";
ok(count(array_unique($r['rowHeights'])) === 1,
   'every row is the same height, got ' . json_encode($r['rowHeights']));
/* 31, not 30: border-collapse adds the shared 1px rule to the measured box.
   The declared row is 30px, the same --rowh every other grid uses. */
ok($r['rowHeights'][0] <= 32,
   'and that height is one grid row, got ' . $r['rowHeights'][0] . 'px');
ok($r['wmHeight'] <= 36 && $r['wmHeight'] > $r['rowHeights'][0],
   'Workmanship is a little taller, not double, got ' . $r['wmHeight'] . 'px');
/* the before/after is measured side by side under one stylesheet, so the
   saving cannot be a number I talked myself into */
/* the exact "before" depends on which .zin wins on the live page, so this
   asserts the SHAPE of the problem — a two-line row in the mid-50s — and
   prints the measured figure rather than baking in a number I like. */
ok($r['oldHeight'] >= 50,
   'the markup that shipped before really was a two-line row, got ' . $r['oldHeight'] . 'px');
printf("     measured: %dpx a row becomes %dpx — %d%% shorter\n",
   $r['oldHeight'], $r['rowHeights'][0],
   round(($r['oldHeight'] - $r['rowHeights'][0]) / $r['oldHeight'] * 100));

echo "4. The item code moved OUT of the item cell and INTO its own column\n";
ok($r['itemCellHasChip'] === false, 'nothing is stacked under the Item box any more');
ok(trim($r['codeCellChip']) === '✓ FB-0001', 'the code is in the Code cell, got ' . json_encode($r['codeCellChip']));
ok(trim($r['addLabel']) === '+ add', 'an unlinked line offers a short "+ add", got ' . json_encode($r['addLabel']));
/* the label had to shrink for the narrow column — the words must not vanish */
ok(str_contains($r['addTitle'], 'Add') && str_contains($r['addTitle'], 'Item Master'),
   '  and the full sentence survives in its tooltip, got ' . json_encode($r['addTitle']));
ok(str_contains($r['addTitle'], 'Printed PVC Zip Bag'),
   '  naming the actual item it would add');
ok($r['emptyCodeCell'] === '', 'an empty line shows no chip at all');

echo "5. Nothing about the MONEY changed\n";
ok($r['normalAmt'] === '1174.20', 'a normal line is qty x rate, got ' . json_encode($r['normalAmt']));
ok($r['sharedOn'] === true, 'the shared line still shows its toggle lit');
ok(str_starts_with($r['sharedAmt'], '28.33'), 'and is still rate / qty, got ' . json_encode($r['sharedAmt']));

echo "6. The delete button is a quiet glyph, not the old red block\n";
/* this is the specificity fight: .zskin .zbtn.red (0,3,0) against
   .zskin table.ct .rowx (0,3,1). If it goes the wrong way the button keeps a
   red background and the row cannot come down to 30px. */
ok($r['xBg'] === 'rgba(0, 0, 0, 0)', 'no background until you are on the row, got ' . $r['xBg']);
ok($r['xColor'] === 'rgba(0, 0, 0, 0)', 'and no visible glyph either, got ' . $r['xColor']);
ok($r['xWidth'] === 20, 'it is 20px wide, not a padded button, got ' . $r['xWidth'] . 'px');

echo "7. The figures line up and fill their cells\n";
ok($r['qtyMaxW'] === 'none', 'the 92px .mini cap is gone inside the grid, got ' . $r['qtyMaxW']);
ok($r['qtyAlign'] === 'right', 'and quantities are right-aligned, got ' . $r['qtyAlign']);
ok($r['itemBorder'] === 'rgba(0, 0, 0, 0)', 'cells are flat until touched, got ' . $r['itemBorder']);
ok($r['itemRadius'] === '0px', 'and square, like a spreadsheet, got ' . $r['itemRadius']);

echo "8. Removing the skin reverts the STYLING — but not the column. Say so.\n";
/* Everywhere else in this app, deleting the one .zskin attribute puts the
   page back exactly as it was. That is only HALF true here, and pretending
   otherwise would be the kind of promise that bites someone at 2am.
   The CSS reverts. The Code column does not: it is markup, and markup does
   not un-write itself. The row therefore lands between the two — no longer
   30px, but nowhere near the old 58px either, because the chip that made it
   two lines tall is now in a cell of its own. */
ok($r['xBgWithoutSkin'] !== 'rgba(0, 0, 0, 0)',
   'the delete button is a plain red button again, got ' . $r['xBgWithoutSkin']);
ok($r['heightWithoutSkin'] > $r['rowHeights'][0],
   'the old padding comes back, got ' . $r['heightWithoutSkin'] . 'px');
ok($r['heightWithoutSkin'] < $r['oldHeight'],
   'but the row does NOT return to ' . $r['oldHeight'] . 'px, because the Code column is '
   . 'markup and stays — got ' . $r['heightWithoutSkin'] . 'px');
ok(str_contains($css, 'the Code column is markup and does not revert'),
   'and the skin file says this out loud instead of promising a clean revert');

@unlink(__DIR__ . '/.zc.js'); @unlink(__DIR__ . '/.zc_head.txt'); @unlink(__DIR__ . '/.zc_harness.js');

echo "9. The other tables on the page were not broken by the new column\n";
/* table.ct is used by Compare Versions and the AI tables too. They must NOT
   get the Code column, and their own colspans must be untouched. */
ok(substr_count($js, 'class="ct ct-edit"') === 1, 'only the editable grid is ct-edit, got '
   . substr_count($js, 'class="ct ct-edit"'));
ok(substr_count($js, '<table class="ct">') === 3, 'the three read-only tables keep plain .ct, got '
   . substr_count($js, '<table class="ct">'));
ok(str_contains($js, "querySelectorAll('table.ct-edit tbody tr')"),
   'the live amount update targets the editable grid by name, not by being first');
ok(!str_contains($js, "querySelectorAll('table.ct tbody tr')"),
   '  and no longer grabs whichever .ct table happens to come first');
ok(str_contains($css, '.zskin table.ct-edit .c-qty'),
   'the column widths are scoped to the editable grid alone');

echo "10. The footer keeps every action, it just stops shouting\n";
ok(substr_count($php, 'class="zbtn') >= 2, 'Print and Save stay as buttons');
ok(str_contains($php, 'duplicateActive()') && str_contains($php, 'compareCard')
   && str_contains($php, 'share=1') && str_contains($php, 'location.reload()'),
   'NOTHING WAS REMOVED — share, duplicate, compare and reset are all still there');
ok(str_contains($php, '<details class="moremenu">'), 'they live under a plain <details>, no script');
ok(str_contains($php, 'bottom:calc(100% + 6px)'), 'which opens upward, off a bar stuck to the bottom');
ok(str_contains($php, 'discards unsaved changes'), 'and Reset now says what it discards');

echo "11. The cache is busted, or nobody sees any of this\n";
ok(str_contains($php, 'costing.js?v=27'), 'costing.js version raised');
/* NOT PINNED TO A NUMBER. Asserting v=6 here meant the next legitimate
   cache-bust failed this test — the same trap batch2_test.php had. */
ok((bool)preg_match('~zskin\.css\?v=\d+~', file_get_contents($B . 'includes/layout.php')),
   'zskin.css is cache-busted');

echo "12. Why is written down, so the next person does not undo it\n";
ok(str_contains($css, 'A THIRD table class'), 'the skin records that it had missed this grid');
ok(str_contains($js, 'A second line in a cell is a second'),
   'and the row builder records why the code moved');

echo "\n$P passed, $F failed\n";
exit($F ? 1 : 0);
