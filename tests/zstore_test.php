<?php
/* STORE ISSUE & RETURN — the screen the store team uses most.
 *
 * Material off the shelf to a floor in the morning, what is left back to
 * the shelf at night. Two real defects were found while converting it,
 * and neither was a styling problem:
 *
 *   The totals row spanned SEVEN columns. An issue has six. Every figure
 *   on the total row of every issue sat under the wrong heading.
 *
 *   "Stock now" was computed in PHP at page load, from whatever location
 *   was on the document when it opened, and never changed again. Pick an
 *   item, change the From location, add a line — the number stayed put.
 *   On a new line it read "—" for as long as the form was open, which is
 *   exactly when the operator needs it.
 *
 * The grid is rendered for BOTH types and measured in a browser, and the
 * live balance is driven the way an operator drives it.
 */

$B  = __DIR__ . '/app_src/public_html/';
$st = file_get_contents($B . 'inv_store.php');
$css = file_get_contents($B . 'assets/css/zskin.css');

$P = 0; $F = 0;
function ok($c, $m) { global $P, $F; if ($c) { $P++; } else { $F++; echo "  FAIL: $m\n"; } }

$work = __DIR__ . '/.zstore';
@mkdir($work, 0777, true);

echo "1. The page opts in once, and can be taken back out\n";
ok(substr_count($st, '<div class="zskin">') === 1, 'one wrapper');
ok(substr_count($st, 'closes .zskin') === 1, '  closed once');
ok(strpos($st, '<div class="zskin">') > strrpos($st, '</style>'), 'it wraps the markup, not the stylesheet');
ok(strpos($st, 'closes .zskin') < strrpos($st, '<?php page_footer(); ?>'), '  and closes before the footer');
foreach (['iss-card','iss-tbl','iss-btn','iss-inp','iss-grid','iss-note','iss-lbl','iss-pill','iss-tabs'] as $c)
    ok(str_contains($st, $c), "the page still uses .$c");

echo "2. The names are this page's alone\n";
$pages = array_merge(glob($B . '*.php'), glob($B . 'includes/*.php'));
foreach (['iss-card','iss-tbl','iss-btn','iss-inp','iss-grid','iss-note','iss-lbl','iss-pill','iss-tabs'] as $c) {
    $owners = [];
    foreach ($pages as $f)
        if (preg_match('/(^|[\s,>])\.' . preg_quote($c, '/') . '[\s{,.:]/m', file_get_contents($f)))
            $owners[] = basename($f);
    ok(count($owners) <= 1, ".$c is defined by at most one page, got " . json_encode($owners));
}
foreach (['p-draft','p-posted','p-reversed'] as $c) {
    ok(str_contains($css, '.zskin .iss-pill.' . $c . '{'), ".$c is mapped only as a .iss-pill");
    ok(!preg_match('/\.zskin \.' . preg_quote($c, '/') . '\{/', $css), "  and never bare");
}

echo "3. .matbox is stated where it is used\n";
/* The page's markup uses .matbox and only inv_gate.php ever defined it —
   a different page, a different <style>, so it has never applied here. */
ok(str_contains($st, '.matbox{position:relative}'), 'inv_store.php defines the class its own markup uses');
ok(str_contains($st, 'borrowed from a file that cannot reach it'), '  and says why it had to');

/* ------------------------------------------------------------------ */
echo "4. Rendered for BOTH types, and counted\n";

$a = strpos($st, '<div style="overflow-x:auto"><table class="iss-tbl" id="slines">');
$b = strpos($st, '</table></div>', $a);
ok($a !== false && $b !== false, 'the grid is where it was');
$frag = substr($st, $a, $b - $a + strlen('</table></div>'));
preg_match('/<style>(.*?)<\/style>/s', $st, $sm);
$pageCss = $sm[1] ?? '';

function renderStore(string $frag, string $type, array $lines): string {
    $tpl = '<?php
function e($s){ return htmlspecialchars((string)$s, ENT_QUOTES); }
function inv_balance(...$a){ return 0; }
$type = ' . var_export($type, true) . ';
$lines = ' . var_export($lines, true) . ';
$D = ["from_location_id" => 2];
$materials = [["id"=>1,"code"=>"FAB-001","name"=>"Cotton greige 60s","uom"=>"MTR","item_group"=>"Fabric"],
              ["id"=>2,"code"=>"BTN-014","name"=>"Button 4-hole","uom"=>"PCS","item_group"=>"Accessories"]];
?>' . $frag;
    $f = __DIR__ . '/.zstore/frag.php';
    file_put_contents($f, $tpl);
    return (string)shell_exec('php ' . escapeshellarg($f) . ' 2>&1');
}

$fix = [
  ['material_id'=>1,'lot_no'=>'LOT-7','qty'=>400,'uom'=>'MTR','condition_note'=>''],
  ['material_id'=>2,'lot_no'=>'','qty'=>50,'uom'=>'PCS','condition_note'=>'Usable'],
];
$html = [];
foreach (['issue', 'return'] as $t) {
    $html[$t] = renderStore($frag, $t, $fix);
    ok(!str_contains($html[$t], 'Fatal') && !str_contains($html[$t], 'Warning'),
       "the $t grid renders clean: " . substr(trim($html[$t]), 0, 120));
}

$head = '<!doctype html><html><head><meta charset="utf-8">'
      . '<style>' . file_get_contents($B . 'assets/css/app.css') . '</style>'
      . '<style>' . $pageCss . '</style>'
      . '<style>' . $css . '</style></head><body style="width:1280px;margin:0">';
foreach (['issue', 'return'] as $t) {
    file_put_contents($work . "/plain_$t.html", $head . $html[$t] . '</body></html>');
    file_put_contents($work . "/skin_$t.html", $head . '<div class="zskin">' . $html[$t] . '</div></body></html>');
}

$probe = <<<'JS'
const { chromium } = require('playwright');
(async () => {
  const br = await chromium.launch({ executablePath: '/opt/pw-browsers/chromium' });
  const out = {};
  for (const which of ['plain_issue','skin_issue','plain_return','skin_return']) {
    const pg = await br.newPage({ viewport: { width: 1280, height: 800 } });
    await pg.goto('file://' + process.argv[2] + '/' + which + '.html');
    await pg.waitForTimeout(120);
    out[which] = await pg.evaluate(() => {
      const t = document.querySelector('#slines');
      const g = el => el ? Math.round(el.getBoundingClientRect().height) : 0;
      const rows = [...t.querySelectorAll('tbody tr')];
      return {
        cols: t.querySelectorAll('thead th').length,
        headers: [...t.querySelectorAll('thead th')].map(h => h.textContent.trim()),
        footSpan: [...t.querySelectorAll('tfoot tr')].map(r =>
          [...r.children].reduce((n, td) => n + (+td.getAttribute('colspan') || 1), 0)),
        rowH: rows.map(g),
        hasBalCell: rows.every(r => !!r.querySelector('.bal')),
        balText: rows.map(r => r.querySelector('.bal').textContent.trim()),
        qtyH: g(rows[0].querySelector('.qty')),
        tdAlign: getComputedStyle(rows[0].querySelector('td')).verticalAlign,
        inputRadius: getComputedStyle(rows[0].querySelector('.qty')).borderRadius,
        thTransform: getComputedStyle(t.querySelector('thead th')).textTransform,
        // the two padding fudges the old markup used to hold cells level
        paddingHacks: [...t.querySelectorAll('tbody td')].filter(td =>
          (td.getAttribute('style') || '').includes('padding-top')).length
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

if (!is_array($M)) { echo "  FAIL: the probe did not run:\n" . substr((string)$raw, 0, 800) . "\n"; $F++; }
else {
    echo "   the totals row adds up now\n";
    /* THE BUG. Material, Lot, Quantity, UOM, Stock now, ×  = 6 on an
       issue; Condition makes 7 on a return. The footer was a flat
       colspan of 4 after the quantity, which is 7 either way. */
    ok($M['plain_issue']['cols'] === 6,
       'an issue has 6 columns, got ' . $M['plain_issue']['cols'] . ': ' . json_encode($M['plain_issue']['headers']));
    ok($M['plain_return']['cols'] === 7,
       'a return has 7, got ' . $M['plain_return']['cols']);
    foreach (['plain_issue','plain_return','skin_issue','skin_return'] as $k)
        foreach ($M[$k]['footSpan'] as $w)
            ok($w === $M[$k]['cols'],
               "$k: the total row adds up to {$M[$k]['cols']}, got $w");

    echo "   the balance cell is there to be filled in\n";
    ok($M['plain_issue']['hasBalCell'], 'every line has a balance cell');
    /* It starts as a dash and is filled in by JavaScript. Rendered by PHP
       it was a number frozen at page load. */
    ok($M['plain_issue']['balText'] === ['—', '—'],
       'it starts empty and is filled in live, got ' . json_encode($M['plain_issue']['balText']));
    ok(!preg_match('/class="r bal"[^>]*>\s*<\?=/', $st),
       'and PHP does not print a frozen number into it');

    echo "   and the fudges are gone\n";
    ok($M['plain_issue']['paddingHacks'] === 0,
       'no padding-top is holding a cell level, got ' . $M['plain_issue']['paddingHacks']);

    echo "   measured, both ways\n";
    foreach (['issue', 'return'] as $t) {
        $p = $M['plain_' . $t]; $s = $M['skin_' . $t];
        ok(count(array_unique($s['rowH'])) === 1, "$t: every row is the same height: " . json_encode($s['rowH']));
        ok($s['rowH'][0] <= 32, "$t: the row is compact, got " . $s['rowH'][0] . 'px (was ' . $p['rowH'][0] . 'px)');
        ok($s['rowH'][0] < $p['rowH'][0], "$t: and shorter than it was");
        ok($s['tdAlign'] === 'middle', "$t: cells are middle-aligned, so a row cannot drift");
        ok($s['inputRadius'] === '0px', "$t: cell inputs are flat");
        ok($s['thTransform'] === 'none', "$t: headers are sentence case");
    }
}

/* ------------------------------------------------------------------ */
echo "5. The live balance, driven\n";

/* The page's own JS, lifted, with the stock map replaced by a fixture.
   Everything it reaches for that lives elsewhere is stubbed HERE. */
$ja = strpos($st, "var tb=document.querySelector('#slines tbody');");
$jb = strpos($st, "  /* ---- the List of Values", $ja);
$js = substr($st, $ja, $jb - $ja);
$js = preg_replace('/<\?=.*?\?>/s', 'true', $js);   // IS_ISSUE
ok(!str_contains($js, '<?'), 'the paint routine lifted with no PHP left in it');
ok(str_contains($js, 'cell.classList.toggle'), '  and it is the one that paints the balance');

$harness = '<!doctype html><html><head><meta charset="utf-8">'
  . '<style>' . file_get_contents($B . 'assets/css/app.css') . '</style>'
  . '<style>' . $pageCss . '</style>'
  . '<style>' . $css . '</style></head><body style="width:1280px;margin:0">'
  . '<select name="from_location_id"><option value="2" selected>Fabric Store</option>'
  . '<option value="3">Finished Goods</option></select>'
  . '<div class="zskin">' . $html['issue']
  . '<div id="sWarn" class="iss-note warn" style="display:none"></div></div>'
  . '<scr' . 'ipt>'
  . 'var STOCK = {"1":{"0":900,"2":900,"3":0},"2":{"0":14000,"2":0,"3":14000}};'
  . 'function fromLoc(){ var s=document.querySelector(\'select[name="from_location_id"]\'); return s?+s.value:0; }'
  . 'function fromName(){ var s=document.querySelector(\'select[name="from_location_id"]\');'
  . '  return s&&s.selectedIndex>=0 ? s.options[s.selectedIndex].text : "that location"; }'
  . 'function balOf(id){ var m=STOCK[id]; if(!m) return 0; var l=fromLoc(), v=l>0?m[l]:m[0]; return v===undefined?0:v; }'
  . '(function(){' . $js . 'window.TOT = tot; tot();})();'
  . '</scr' . 'ipt></body></html>';
file_put_contents($work . '/live.html', $harness);

$drive = <<<'JS'
const { chromium } = require('playwright');
(async () => {
  const br = await chromium.launch({ executablePath: '/opt/pw-browsers/chromium' });
  const pg = await br.newPage({ viewport: { width: 1280, height: 700 } });
  const errs = []; pg.on('pageerror', e => errs.push(String(e)));
  await pg.goto('file://' + process.argv[2] + '/live.html');
  await pg.waitForTimeout(150);
  const read = () => pg.evaluate(() => {
    const rows = [...document.querySelectorAll('#slines tbody tr')];
    return {
      bal: rows.map(r => r.querySelector('.bal').textContent.trim()),
      short: rows.map(r => r.querySelector('.bal').classList.contains('short')),
      total: document.getElementById('sqty').textContent.trim(),
      warn: document.getElementById('sWarn').style.display !== 'none',
      warnText: document.getElementById('sWarn').textContent.slice(0, 140)
    };
  });
  const R = { errs };
  R.start = await read();
  // take more than is on that floor
  await pg.fill('#slines tbody tr:nth-child(1) .qty', '2000');
  await pg.waitForTimeout(80);
  R.over = await read();
  // back under
  await pg.fill('#slines tbody tr:nth-child(1) .qty', '100');
  await pg.waitForTimeout(80);
  R.under = await read();
  // THE ONE THAT WAS FROZEN: change the From location
  await pg.selectOption('select[name="from_location_id"]', '3');
  await pg.waitForTimeout(120);
  R.moved = await read();
  await br.close();
  console.log(JSON.stringify(R));
})();
JS;
file_put_contents($work . '/drive.js', $drive);
$raw2 = shell_exec('cd ' . escapeshellarg(__DIR__) . ' && node ' . escapeshellarg($work . '/drive.js')
                   . ' ' . escapeshellarg($work) . ' 2>&1');
$R = json_decode((string)$raw2, true);

if (!is_array($R)) { echo "  FAIL: the driver did not run:\n" . substr((string)$raw2, 0, 800) . "\n"; $F++; }
else {
    ok(empty($R['errs']), 'the lifted paint routine runs clean: ' . json_encode($R['errs']));
    ok($R['start']['bal'] === ['900', '0'],
       'the balance is filled in from the stock map, got ' . json_encode($R['start']['bal']));
    ok($R['start']['total'] === '450', 'the total adds up, got ' . json_encode($R['start']['total']));
    /* line 2 asks for 50 buttons and the Fabric Store has none */
    ok($R['start']['short'] === [false, true], 'a line taking more than is there is marked at once');
    ok($R['start']['warn'] === true, '  and named under the table');

    ok($R['over']['short'][0] === true, 'raising a quantity past the balance marks that line too');
    ok(str_contains($R['over']['warnText'], 'Fabric Store'), '  saying WHICH floor is short');
    ok($R['under']['short'][0] === false, 'and lowering it clears that line again');
    ok($R['under']['warn'] === true, '  while the other short line keeps the strip up');

    /* THE FROZEN COLUMN. Same items, different floor: both figures must
       change. Before this they could not. */
    /* '14,000', not '14000' — the cell is formatted for reading, which is
       the point of a quantity column. I wrote the raw number here and the
       code was right. */
    ok($R['moved']['bal'] === ['0', '14,000'],
       'changing the From location repaints EVERY line, got ' . json_encode($R['moved']['bal']));
    ok(str_contains($R['moved']['bal'][1], ','),
       '  and the figure is grouped, so 14,000 is not read as 1,400');
    ok($R['moved']['short'] === [true, false],
       '  and the warnings follow the new floor, got ' . json_encode($R['moved']['short']));
    ok(str_contains($R['moved']['warnText'], 'Finished Goods'), '  and the strip names it');
}

echo "\n$P passed, $F failed\n";
exit($F ? 1 : 0);
