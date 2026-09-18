<?php
/* THE GATE FORM IN THE SAME GRID LANGUAGE AS THE REST OF THE APP.
 *
 * inv_gate.php is the densest form in the system — item, contract, lot,
 * unit, quantity, rate and amount on every line, fifteen lines on a real
 * pass — and it was the last one still on the old look: 47px rows, 9px
 * radius form controls, a cyan-to-violet gradient button, and an 18px
 * folded strip under every single line.
 *
 * This loads the REAL zskin.css over the REAL page markup in a browser
 * and measures BOTH states — without the wrapper and with it — so the
 * saving is a measurement, not a claim. It also proves the page is put
 * back exactly as it was when the wrapper is removed, which is the whole
 * promise of an opt-in skin.
 */

$B   = __DIR__ . '/app_src/public_html/';
$css = file_get_contents($B . 'assets/css/zskin.css');
$ig  = file_get_contents($B . 'inv_gate.php');

$P = 0; $F = 0;
function ok($c, $m) { global $P, $F; if ($c) { $P++; } else { $F++; echo "  FAIL: $m\n"; } }

$work = __DIR__ . '/.zgateskin';
@mkdir($work, 0777, true);

echo "1. The page opts in once, and can be taken back out\n";
ok(substr_count($ig, '<div class="zskin">') === 1,
   'one wrapper, got ' . substr_count($ig, '<div class="zskin">'));
ok(substr_count($ig, 'closes .zskin') === 1, '  closed exactly once');
ok(strpos($ig, '<div class="zskin">') > strrpos($ig, '</style>'),
   'it wraps the markup, not the page stylesheet — the skin must override, not be overridden');
/* strrpos, not strpos: the wrapper's own comment explains that it closes
   before page_footer(), so the FIRST match for that string is the comment,
   which sits above the closing tag. The test was reading its own
   documentation and calling it a failure. */
ok(strpos($ig, 'closes .zskin') < strrpos($ig, '<?php page_footer(); ?>'),
   '  and it closes before the footer');

echo "2. NOTHING WAS RENAMED — the page keeps its own vocabulary\n";
/* If converting a page meant rewriting its classes, every page would be a
   separate rewrite and removing the skin would be a separate rewrite back. */
foreach (['ig-card','ig-tbl','ig-btn','ig-inp','ig-pill','ig-grid','ig-note','ig-lbl','ig-kpi'] as $c)
    ok(str_contains($ig, $c), "the page still uses .$c");
ok(str_contains($ig, '.ig-card{background:#fff'),
   'and still defines its own styles, so deleting the wrapper restores them');

echo "3. THE NAMES ARE THIS PAGE'S ALONE\n";
/* The .zgrid lesson, for the third time: a name the skin maps must belong
   to one page, or the mapping silently reaches a page nobody reviewed. */
$pages = array_merge(glob($B . '*.php'), glob($B . 'includes/*.php'));
$owners = function (string $c) use ($pages): array {
    $o = [];
    foreach ($pages as $f)
        if (preg_match('/(^|[\s,>])\.' . preg_quote($c, '/') . '[\s{,.:]/m', file_get_contents($f)))
            $o[] = basename($f);
    return $o;
};
foreach (['ig-card','ig-tbl','ig-btn','ig-inp','ig-pill','ig-grid','ig-note','ig-lbl','ig-kpi',
          'lothint','dtog','dwrap','ctag'] as $c)
    ok(count($owners($c)) <= 1, ".$c is defined by at most one page, got " . json_encode($owners($c)));

/* .matbox IS defined by two pages, on purpose, and that is not the thing
   this section is guarding against.
 *
 * The rule is "the skin must not map a name that two pages mean
 * differently" — because the mapping would then reach a page nobody
 * reviewed. .matbox is a one-line local utility (position:relative) that
 * inv_store.php's markup was already using while only inv_gate.php
 * defined it, so it had never applied there; stating it on both pages is
 * the fix, not a collision.
 *
 * What has to remain true is that the SKIN never touches it bare. Both
 * mappings sit under their own table — .ig-tbl and .iss-tbl — so neither
 * can reach the other's page. That is what is checked. */
$mb = $owners('matbox');
ok(count($mb) === 2 && in_array('inv_gate.php', $mb, true) && in_array('inv_store.php', $mb, true),
   '.matbox is stated by both pages that use it, got ' . json_encode($mb));
ok(!preg_match('/\.zskin \.matbox[\s{,>]/', $css),
   '  and the skin never maps it bare');
ok(substr_count($css, '.zskin .ig-tbl td .matbox') > 0 && substr_count($css, '.zskin .iss-tbl td .matbox') > 0,
   '  every .matbox rule is scoped to one page\'s own table');
/* The status names are scoped anyway, for the same reason the contract
   page's are: "only one page uses it today" is a fact about today. */
foreach (['st-draft','st-verified','st-posted','st-reversed'] as $c) {
    ok(str_contains($css, '.zskin .ig-pill.' . $c . '{'),
       ".$c is mapped only as a .ig-pill, never bare");
    ok(!preg_match('/\.zskin \.' . preg_quote($c, '/') . '\{/', $css),
       "  and there is no bare .zskin .$c rule that could leak");
}
/* Same for the bare names the grid owns. */
foreach (['matbox','lothint','dtog','dwrap','ctag','amt','detail'] as $c)
    ok(!preg_match('/\.zskin \.' . preg_quote($c, '/') . '[\s{,]/', $css),
       ".$c is never mapped bare — it is scoped under .ig-tbl");

echo "4. The block says what was loose and what it cost\n";
$flat = preg_replace('/\s+/', ' ', $css);
ok(str_contains($css, 'GATE INWARD & OUTWARD — .ig-* (inv_gate.php)'), 'the block is labelled');
ok(str_contains($flat, 'WHAT WAS LOOSE, AND WHAT IT COST'), 'and each change is justified');
ok(str_contains($flat, 'ONE cell setting EVERY row\'s height'),
   '  including the lot hint, which is the one that mattered');
ok(str_contains($flat, 'THE HEIGHT IS PAID ONLY WHERE THERE ARE WORDS'),
   'and the lot hint rule says why it is :empty and not a flat hide');
ok(str_contains($flat, 'the place I got it wrong first'),
   '  including that the flat hide was written and rejected');
ok(str_contains($ig, 'lotBox.title = msg'),
   'the page really does put those words on the lot box');

/* ---------------------------------------------------------------- */
echo "5. Measured in a browser, both ways\n";

$a = strpos($ig, '<div style="overflow-x:auto"><table class="ig-tbl" id="glines">');
$b = strpos($ig, '</table></div>', $a);
$frag = substr($ig, $a, $b - $a + strlen('</table></div>'));
preg_match('/<style>(.*?)<\/style>/s', $ig, $sm);
$pageCss = $sm[1];

$lines = [
  ['material_id'=>1,'lot_no'=>'LOT-77','uom'=>'MTR','qty'=>400,'rate'=>210.5,'amount'=>84200,
   'description'=>'','packing'=>'','lcid'=>31,'lcno'=>'PC-2026-0031','contract_item_id'=>502],
  ['material_id'=>2,'lot_no'=>'','uom'=>'PCS','qty'=>50,'rate'=>1.25,'amount'=>62.5,
   'description'=>'','packing'=>'','lcid'=>0,'lcno'=>null,'contract_item_id'=>null],
  /* this one is IN USE, so its strip is open from the server */
  ['material_id'=>1,'lot_no'=>'','uom'=>'MTR','qty'=>10,'rate'=>5,'amount'=>50,
   'description'=>'Their wording','packing'=>'Bale','lcid'=>0,'lcno'=>null,'contract_item_id'=>null],
];
$tpl = '<?php
function e($s){ return htmlspecialchars((string)$s, ENT_QUOTES); }
/* short_ref() IS LIFTED FROM includes/helpers.php, not copied. The gate
   line prints the SHORT form of a contract number, so a fragment harness
   that lacks the real helper dies with "undefined function" — which is how
   this was found. */
function short_ref($r){
    $r = trim((string)$r);
    if ($r === "") return "";
    $sep = strpos($r, "-") !== false ? "-" : (strpos($r, "/") !== false ? "/" : "");
    if ($sep === "") return $r;
    $b = explode($sep, $r);
    if (count($b) < 3) return $r;
    $f = trim($b[0]); $l = trim($b[count($b)-1]);
    return ($f === "" || $l === "") ? $r : $f . $sep . $l;
}

$dir = "in";
$lines = ' . var_export($lines, true) . ';
$doc = ' . var_export(['contract_no' => 'PC-2026-0031', 'id' => 5], true) . ';
$materials = [["id"=>1,"code"=>"FAB-001","name"=>"Cotton greige 60s","uom"=>"MTR","std_rate"=>210.5,"item_group"=>"Fabric"],
              ["id"=>2,"code"=>"BTN-014","name"=>"Button 4-hole","uom"=>"PCS","std_rate"=>1.25,"item_group"=>"Trim"]];
$products = [["id"=>7,"name"=>"Duvet cover king"]];
?>' . $frag;
file_put_contents($work . '/frag.php', $tpl);
$grid = shell_exec('php ' . escapeshellarg($work . '/frag.php') . ' 2>&1');
ok(!str_contains($grid, 'error'), 'the grid renders clean');

/* app.css carries * { box-sizing:border-box } — without it the harness
   measures a different box than the real page does. */
$head = '<!doctype html><html><head><meta charset="utf-8">'
      . '<style>' . file_get_contents($B . 'assets/css/app.css') . '</style>'
      . '<style>' . $pageCss . '</style>'
      . '<style>' . $css . '</style></head><body style="width:1280px;margin:0">';
file_put_contents($work . '/plain.html',  $head . $grid . '</body></html>');
file_put_contents($work . '/skin.html',   $head . '<div class="zskin">' . $grid . '</div></body></html>');

$probe = <<<'JS'
const { chromium } = require('playwright');
(async () => {
  const out = {};
  const br = await chromium.launch({ executablePath: '/opt/pw-browsers/chromium' });
  for (const which of ['plain', 'skin']) {
    const pg = await br.newPage({ viewport: { width: 1280, height: 900 } });
    await pg.goto('file://' + process.argv[2] + '/' + which + '.html');
    out[which] = await pg.evaluate(() => {
      const t = document.querySelector('#glines');
      const all = [...t.querySelectorAll('tbody tr')];
      const rows = all.filter(r => !r.classList.contains('detail'));
      const dets = all.filter(r =>  r.classList.contains('detail'));
      const g = el => el ? Math.round(el.getBoundingClientRect().height) : 0;
      const cs = el => el ? getComputedStyle(el) : {};
      const q = rows[0].querySelector('.qty');
      const cl = rows[0].querySelector('.ctag');
      const th = t.querySelector('thead th');
      const del = rows[0].querySelector('.del');
      return {
        rowH: rows.map(g),
        detH: dets.map(g),
        lineTotal: g(rows[0]) + g(dets[0]),
        qtyH: g(q),
        inputRadius: cs(q).borderRadius,
        thTransform: cs(th).textTransform,
        thBg: cs(th).backgroundColor,
        hintShown: getComputedStyle(rows[0].querySelector('.lothint')).display,
        hintEmpty: rows[0].querySelector('.lothint').textContent === '',
        /* THE CASE THAT MATTERS: put a real warning in the hint and it has
           to come back. A skin that could swallow "no lot of this item is
           at Main Store" would be hiding information, not styling. */
        hintWithText: (() => {
          const h = rows[1].querySelector('.lothint');
          const was = Math.round(rows[1].getBoundingClientRect().height);
          h.textContent = 'no lot of this item is at Main Store';
          const shown = getComputedStyle(h).display;
          const now = Math.round(rows[1].getBoundingClientRect().height);
          h.textContent = '';
          return { shown: shown, grew: now > was, was: was, now: now };
        })(),
        clineBg: cs(cl).backgroundColor,
        clineSet: cl.classList.contains('set'),
        clinePos: cs(cl).position,
        togFont: cs(dets[0].querySelector('.dtog')).fontSize,
        openTogFont: cs(dets[2].querySelector('.dtog')).fontSize,
        openIsOpen: dets[2].classList.contains('open'),
        tdAlign: cs(rows[0].querySelector('td')).verticalAlign,
        tableH: g(t)
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
    $p = $M['plain']; $s = $M['skin'];

    echo "   what it was\n";
    /* THE TWO NUMBERS COME FROM THE SAME HARNESS.
       I first pinned the strip at 18px, which is what it measures in the
       demo page — and the demo also loads lov.css, which moves it. A
       before-and-after is only a measurement when both halves are measured
       under identical conditions, so plain.html and skin.html load exactly
       the same three stylesheets and differ by the wrapper alone. */
    ok($p['rowH'][0] === 47, 'the row was 47px, got ' . $p['rowH'][0]);
    ok($p['detH'][0] === 21, 'the folded strip was 21px, got ' . $p['detH'][0]);
    ok($p['lineTotal'] === 68, 'so one line cost 68px, got ' . $p['lineTotal']);
    ok($p['tdAlign'] === 'top', 'and cells were top-aligned, so rows drifted with content');
    ok($p['hintShown'] !== 'none', 'the lot hint was a block in the cell');

    echo "   what it is\n";
    ok($s['rowH'][0] === 30, 'the row is 30px, got ' . $s['rowH'][0]);
    ok($s['detH'][0] === 15, 'the folded strip is 15px, got ' . $s['detH'][0]);
    ok($s['lineTotal'] === 45, 'one line costs 45px, got ' . $s['lineTotal']);
    ok($s['tdAlign'] === 'middle', 'cells are middle-aligned, so a row cannot drift');
    ok($s['hintShown'] === 'none' && $s['hintEmpty'],
       'an EMPTY lot hint no longer sets the row height');
    ok($s['hintWithText']['shown'] !== 'none',
       'but a hint with a warning in it is shown, got ' . $s['hintWithText']['shown']);
    ok($s['hintWithText']['grew'] === true,
       '  and the row grows to fit it (' . $s['hintWithText']['was'] . 'px -> '
       . $s['hintWithText']['now'] . 'px) — the height is paid only where there is something to say');

    /* The number the whole change is for. 15 lines is an ordinary pass. */
    $saved = ($p['lineTotal'] - $s['lineTotal']) * 15;
    ok($saved >= 300, "a fifteen-line pass is {$saved}px shorter (" . $p['lineTotal']
       . 'px -> ' . $s['lineTotal'] . 'px per line)');
    /* It could be 42px, by collapsing the fold's label to a caret. That
       version was written, measured, and thrown away: the skin's own rule
       says a stylesheet does not replace a control's name with a symbol.
       The 3px is the price of keeping that rule true, and the price is
       recorded here so nobody has to rediscover the argument. */

    echo "   every row the same, with or without a contract\n";
    ok(count(array_unique($s['rowH'])) === 1,
       'all three rows are identical heights, got ' . json_encode($s['rowH']));
    ok(count(array_unique($p['rowH'])) === 1,
       '  and they were before too — this change did not introduce that');

    echo "   it looks like the other grids\n";
    ok($s['inputRadius'] === '0px', 'cell inputs are flat, got ' . $s['inputRadius']);
    ok($p['inputRadius'] !== '0px', '  and were not, got ' . $p['inputRadius']);
    ok($s['thTransform'] === 'none', 'headers are sentence case, not shouted');
    ok($p['thTransform'] === 'uppercase', '  and were uppercase before');
    ok($s['thBg'] !== 'rgba(0, 0, 0, 0)', 'headers sit on a tint, like every other grid');

    echo "   the contract state survives being flattened\n";
    /* The flat-cell rules set background with !important. If the chosen and
       over states were not written with more specificity, the column would
       go blank the moment the skin was switched on. */
    ok($s['clineSet'] === true, 'the first line is still marked as linked');
    ok($s['clineBg'] !== 'rgba(0, 0, 0, 0)',
       '  and still paints, got ' . $s['clineBg']);
    /* OUT OF THE FLOW, which is what lets it sit on the item cell without
       adding a second line to the row — measured at 61px against 47px when
       it was inline, and that is the exact fault this grid exists to avoid. */
    ok($s['clinePos'] === 'absolute',
       '  and it is taken out of the flow, got ' . $s['clinePos']);

    echo "   the fold keeps its name\n";
    /* The rule the skin's own test enforces, and the reason this is 15px
       rather than 12: a stylesheet does not replace a control's name with
       a symbol, and it does not hide anything that has words in it. */
    ok($s['togFont'] !== '0px',
       'a closed strip still says what it is, got ' . $s['togFont']);
    ok((float)$s['togFont'] < (float)$p['togFont'],
       '  quieter than it was (' . $p['togFont'] . ' -> ' . $s['togFont'] . ')');
    ok($s['openIsOpen'] === true, 'a line already using the strip renders open');
    ok($s['hintShown'] !== 'none' || $s['hintEmpty'],
       'and the lot hint is only hidden while it is empty');
}

echo "6. The stylesheet is still cache-busted\n";
$lay = file_get_contents($B . 'includes/layout.php');
/* Asserting a VERSION NUMBER here would fail every time someone bumps it,
   which teaches people to edit the test instead of thinking. */
ok(preg_match('/zskin\.css\?v=\d+/', $lay) === 1, 'zskin.css carries a ?v=');

echo "\n$P passed, $F failed\n";
exit($F ? 1 : 0);
