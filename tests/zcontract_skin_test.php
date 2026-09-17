<?php
/* CONTRACTS IN THE SAME GRID LANGUAGE AS EVERYTHING ELSE.
 *
 * inv_contracts.php carries purchase, sales and job work — both directions —
 * and was still on the old look: 16px radius, a 30px drop shadow round every
 * card, pill tabs on a grey tray, a cyan-to-violet gradient button, and
 * .ic-tbl td { vertical-align:top } with no row height, so rows drifted with
 * whatever was in them.
 *
 * This loads the REAL zskin.css and the REAL page markup into a browser and
 * MEASURES the result, rather than reading the files for strings. */

$B   = __DIR__ . '/app_src/public_html/';
$css = file_get_contents($B . 'assets/css/zskin.css');
$ic  = file_get_contents($B . 'inv_contracts.php');

$P = 0; $F = 0;
function ok($c, $m) { global $P, $F; if ($c) { $P++; } else { $F++; echo "  FAIL: $m\n"; } }

echo "1. The page opts in once, and can be taken back out\n";
ok(substr_count($ic, '<div class="zskin">') === 1, 'one wrapper, got '
   . substr_count($ic, '<div class="zskin">'));
ok(substr_count($ic, 'closes .zskin') === 1, '  closed once');
ok(strpos($ic, '<div class="zskin">') > strrpos($ic, '</style>'),
   'it wraps the markup, not the stylesheet');
ok(strpos($ic, 'closes .zskin') < strpos($ic, 'page_footer()'),
   '  and closes before the footer');

echo "2. NOTHING IS REWRITTEN — the page keeps its own names\n";
/* the whole point of the skin: a page is converted by one attribute, not by
   being edited. If this file had to change its classes, ten more pages would
   each need their own rewrite too. */
foreach (['ic-card','ic-tbl','ic-btn','ic-inp','ic-pill','ic-tabs','ic-grid','ic-note','ic-bar2'] as $c)
    ok(str_contains($ic, $c), "the page still uses .$c");
ok(str_contains($ic, '.ic-card{background:#fff'),
   'and still defines its own styles, so removing the skin restores them');

echo "3. THE NAMES ARE THIS PAGE'S ALONE — no collision is possible\n";
/* .zgrid was claimed by two pages meaning two different things, and .empty
   still is. A name the skin maps must belong to one page, or the mapping
   reaches a page nobody reviewed. */
$pages = glob($B . 'inv_*.php');
/* the .ic-* names belong to this page alone. The STATUS names do not —
   inv_contract_print.php and inv_gate_print.php define them too — so those are
   checked below as scoped rules instead, which is the honest fix. */
foreach (['ic-card','ic-tbl','ic-btn','ic-inp','ic-pill','ic-tabs','ic-grid','ic-note','ic-bar','ic-bar2'] as $c) {
    $owners = [];
    foreach ($pages as $f)
        if (preg_match('/^\.' . preg_quote($c, '/') . '[\s{,.:]/m', file_get_contents($f)))
            $owners[] = basename($f);
    ok(count($owners) <= 1, ".$c is defined by at most one page, got " . json_encode($owners));
}

/* A NAME TWO PAGES OWN IS NEVER MAPPED BARE. */
foreach (['c-purchase','c-sales','c-jobwork_out','c-jobwork_in',
          's-draft','s-active','s-closed','s-cancelled'] as $c) {
    ok(str_contains($css, '.zskin .ic-pill.' . $c . '{'),
       ".$c is mapped only as a .ic-pill, never bare");
    ok(!preg_match('/\.zskin \.' . preg_quote($c, '/') . '\{/', $css),
       "  and there is no bare .zskin .$c rule to leak onto a print page");
}
ok(str_contains(preg_replace('/\s+/', ' ', $css), 'that is luck, not design'),
   'and the reason those four are scoped is written down');

echo "4. The mapping says what was loose and what it cost\n";
$flat = preg_replace('/\s+/', ' ', $css);
ok(str_contains($css, 'CONTRACTS — .ic-* (inv_contracts.php)'), 'the block is labelled');
ok(str_contains($flat, 'WHAT WAS LOOSE, AND WHAT IT COST'), 'and each change is justified');
ok(str_contains($flat, 'vertical-align:top is what let these rows drift'),
   '  including the one that made rows uneven');
ok(str_contains($flat, 'SAVE AND CANCEL MUST NOT LOOK THE SAME'),
   'and the destructive-button trap is named');
ok(str_contains($css, '.zskin .ic-btn.sec{') && str_contains($css, '.zskin .ic-btn.red{'),
   '  with each intent given its own rule');
ok(str_contains($flat, 'a gradient on a 3px bar is just noise'),
   'the progress bar is explained too');

echo "\n";
/* ---------- render the REAL markup under the REAL stylesheet ---------- */
$rows = '';
$types = [['purchase','Purchase'],['sales','Sales'],['jobwork_out','Job work out'],['jobwork_in','Job work in']];
$states = ['draft','active','closed','cancelled'];
foreach ($types as $i => $t) {
    $rows .= '<tr><td><a href="#">JW-2026-00' . (41 + $i) . '</a></td>'
          .  '<td><span class="ic-pill c-' . $t[0] . '">' . $t[1] . '</span></td>'
          .  '<td>Al-Karam Textile Mills</td><td>PI-260908-786</td>'
          .  '<td class="r">22,400</td><td class="r">12,650</td>'
          .  '<td class="r">9,750<div class="ic-bar2"><i style="width:56%"></i></div></td>'
          .  '<td class="r">4,760,000.00</td>'
          .  '<td><span class="ic-pill s-' . $states[$i] . '">' . $states[$i] . '</span></td>'
          .  '<td><a href="#">open</a></td></tr>';
}
$items = '';
for ($i = 0; $i < 3; $i++) {
    $items .= '<tr><td class="r">' . ($i+1) . '</td>'
           .  '<td><input class="ic-inp" value="Cotton Grey Fabric 60x60"></td>'
           .  '<td><input class="ic-inp" value="Bleached Percale 200TC"></td>'
           .  '<td><input class="ic-inp" value="Bleach + calender"></td>'
           .  '<td class="r"><input class="ic-inp" value="12000"></td>'
           .  '<td><input class="ic-inp" value="Mtr"></td>'
           .  '<td class="r"><input class="ic-inp" value="212.50"></td>'
           .  '<td class="r">2,550,000.00</td><td></td></tr>';
}
$page = '<!doctype html><meta charset="utf-8">'
      . '<link rel="stylesheet" href="https://fonts.googleapis.com/css2?family=IBM+Plex+Sans:wght@400;500;600;700&family=IBM+Plex+Mono&display=swap">'
      /* app.css first, exactly as layout.php loads it — it carries the global
         box-sizing, and a height that is only right while another file happens
         to load is not a height worth asserting */
      . '<style>' . file_get_contents($B . 'assets/css/app.css') . '</style>'
      . '<style>' . $css . '</style>'
      /* the page's OWN stylesheet, verbatim — testing the skin without it
         tests half the cascade, because a page's <style> loads after ours */
      . '<style>' . (preg_match('/<style>([\s\S]*?)<\/style>/', $ic, $m) ? $m[1] : '') . '</style>'
      . '<style>body{margin:0;padding:16px;background:#f6f7f8;font:13px system-ui}</style>'
      . '<div class="zskin">'
      . '<div class="ic-card"><div class="ic-tabs">'
      . '<a class="ic-tab on" href="#">All 14</a><a class="ic-tab" href="#">Purchase 5</a>'
      . '<a class="ic-tab" href="#">Job work out 4</a><a class="ic-tab" href="#">Job work in 2</a>'
      . '</div>'
      . '<table class="ic-tbl" id="list"><thead><tr><th>Contract</th><th>Type</th><th>Party</th>'
      . '<th>Order</th><th class="r">Qty</th><th class="r">Done</th><th class="r">Balance</th>'
      . '<th class="r">Value</th><th>Status</th><th></th></tr></thead>'
      . '<tbody>' . $rows . '</tbody></table></div>'
      . '<div class="ic-card">'
      . '<div class="ic-grid"><div><label class="ic-lbl">Contract no</label>'
      . '<input class="ic-inp" value="JW-2026-0041"></div>'
      . '<div><label class="ic-lbl">Party</label><input class="ic-inp" value="Al-Karam"></div>'
      . '<div><label class="ic-lbl">Date</label><input class="ic-inp" value="2026-08-02"></div>'
      . '<div><label class="ic-lbl">Status</label><input class="ic-inp" value="Active"></div></div>'
      . '<table class="ic-tbl" id="items"><thead><tr><th>#</th><th>Material</th>'
      . '<th>Expected back as</th><th>Description</th><th class="r">Quantity</th><th>UOM</th>'
      . '<th class="r">Rate</th><th class="r">Amount</th><th></th></tr></thead>'
      . '<tbody>' . $items . '</tbody>'
      . '<tfoot><tr><td colspan="7">Contract value</td><td class="r">5,265,200.00</td><td></td></tr></tfoot>'
      . '</table>'
      . '<div style="margin-top:9px"><button class="ic-btn">Save contract</button> '
      . '<button class="ic-btn sec">Cancel</button> <button class="ic-btn red">Delete</button></div>'
      . '<div class="ic-note info" style="margin-top:9px">A gate pass never requires a contract.</div>'
      . '</div></div>';
file_put_contents(__DIR__ . '/.ics.html', $page);

$harness = <<<'JS'
const {chromium} = require('playwright');
(async () => {
  const b = await chromium.launch({executablePath:'/opt/pw-browsers/chromium'});
  const p = await b.newPage({viewport:{width:1240,height:900}});
  const errs = []; p.on('pageerror', e => errs.push(String(e)));
  await p.goto('file://' + process.argv[2]);
  await p.waitForTimeout(650);
  const out = await p.evaluate(() => {
    const cs = el => getComputedStyle(el);
    const h  = el => Math.round(el.getBoundingClientRect().height);
    const root = cs(document.querySelector('.zskin'));
    const tok = n => root.getPropertyValue(n).trim();
    const card = document.querySelector('.ic-card');
    const listRows = [...document.querySelectorAll('#list tbody tr')];
    const itemRows = [...document.querySelectorAll('#items tbody tr')];
    const inp = document.querySelector('#items td .ic-inp');
    const tab = document.querySelector('.ic-tab.on');
    const pill = document.querySelector('.ic-pill');
    return {
      rowh: tok('--rowh'), acc: tok('--acc'),
      listRow: h(listRows[0]),
      /* every row the same height is the property that "drifting" broke */
      listAllSame: new Set(listRows.map(h)).size,
      itemRow: h(itemRows[0]),
      itemAllSame: new Set(itemRows.map(h)).size,
      header: h(document.querySelector('#list th')),
      cardShadow: cs(card).boxShadow,
      cardRadius: cs(card).borderTopLeftRadius,
      cellAlign: cs(document.querySelector('#list td')).verticalAlign,
      /* a cell input must go FLAT, like every other grid in the app */
      inpBorder: cs(inp).borderTopColor,
      inpRadius: cs(inp).borderTopLeftRadius,
      inpH: Math.round(inp.getBoundingClientRect().height),
      tabAccent: cs(tab).boxShadow,
      tabRadius: cs(tab).borderTopLeftRadius,
      pillRadius: cs(pill).borderTopLeftRadius,
      btnBg:  cs(document.querySelector('.ic-btn')).backgroundImage,
      btnH:   Math.round(document.querySelector('.ic-btn').getBoundingClientRect().height),
      secBg:  cs(document.querySelector('.ic-btn.sec')).backgroundColor,
      redCol: cs(document.querySelector('.ic-btn.red')).color,
      bar:    h(document.querySelector('.ic-bar2')),
      barBg:  cs(document.querySelector('.ic-bar2 i')).backgroundImage,
      font:   cs(document.querySelector('#list td')).fontFamily.split(',')[0],
      gridGap: cs(document.querySelector('.ic-grid')).gap
    };
  });
  out.errors = errs;

  /* REMOVE THE SKIN — the page must go straight back to what it was */
  await p.evaluate(() => document.querySelector('.zskin').className = '');
  await p.waitForTimeout(150);
  out.off = await p.evaluate(() => {
    const cs = el => getComputedStyle(el);
    return {
      shadow: cs(document.querySelector('.ic-card')).boxShadow,
      radius: cs(document.querySelector('.ic-card')).borderTopLeftRadius,
      align:  cs(document.querySelector('#list td')).verticalAlign,
      btn:    cs(document.querySelector('.ic-btn')).backgroundImage
    };
  });
  console.log(JSON.stringify(out));
  await b.close();
})();
JS;
file_put_contents(__DIR__ . '/.ics.js', $harness);
$raw = shell_exec('node ' . escapeshellarg(__DIR__ . '/.ics.js') . ' '
                . escapeshellarg(__DIR__ . '/.ics.html') . ' 2>&1');
$r = json_decode(trim((string)$raw), true);

echo "5. MEASURED in a browser, not asserted\n";
if (!is_array($r)) { echo "  FAIL: it did not render —\n$raw\n"; $F++; }
else {
    ok($r['errors'] === [], 'no errors, got ' . json_encode($r['errors']));
    ok($r['listRow'] <= 32, 'the list row is one grid row, got ' . $r['listRow'] . 'px');
    ok($r['itemRow'] <= 32, 'the item row too, got ' . $r['itemRow'] . 'px');
    /* THIS is what vertical-align:top cost: rows of different heights */
    ok($r['listAllSame'] === 1, 'EVERY list row is the same height, got '
       . $r['listAllSame'] . ' distinct heights');
    ok($r['itemAllSame'] === 1, '  and every item row, got ' . $r['itemAllSame']);
    ok($r['cellAlign'] === 'middle', 'cells centre rather than sitting at the top, got '
       . $r['cellAlign']);
    ok($r['header'] <= 32, 'the header is a row too, got ' . $r['header'] . 'px');

    echo "6. The card stops shouting\n";
    ok($r['cardShadow'] === 'none', 'no drop shadow, got ' . $r['cardShadow']);
    ok($r['cardRadius'] === '8px', '8px radius, not 16, got ' . $r['cardRadius']);

    echo "7. A cell input is flat, like every other grid\n";
    ok($r['inpBorder'] === 'rgba(0, 0, 0, 0)', 'transparent until touched, got ' . $r['inpBorder']);
    ok($r['inpRadius'] === '0px', 'square inside a cell, got ' . $r['inpRadius']);
    ok($r['inpH'] === 28, '28px inside a 30px row, got ' . $r['inpH'] . 'px');
    ok(str_contains(preg_replace('/\s+/', ' ', $css), 'is not a height'),
       '  and the box model is stated, not borrowed from app.css');

    echo "8. Tabs, pills and buttons speak the same language as the rest\n";
    ok(str_contains($r['tabAccent'], 'rgb(37, 99, 235)'),
       'the selected tab carries --acc, not its own blue, got ' . $r['tabAccent']);
    ok($r['tabRadius'] === '0px', 'a segment, not a pill, got ' . $r['tabRadius']);
    ok($r['pillRadius'] === '3px', 'a badge is square-ish, not a 20px pill, got ' . $r['pillRadius']);
    ok($r['btnBg'] === 'none', 'THE GRADIENT IS GONE, got ' . $r['btnBg']);
    ok($r['btnH'] === 28, 'the button is 28px, same as a field, got ' . $r['btnH'] . 'px');
    /* Save, Cancel and Delete must be three different things on sight */
    ok($r['secBg'] === 'rgb(255, 255, 255)', 'Cancel is not painted like Save, got ' . $r['secBg']);
    ok($r['redCol'] === 'rgb(201, 45, 75)', 'and Delete reads as destructive, got ' . $r['redCol']);
    ok($r['bar'] === 3, 'the progress bar is 3px, got ' . $r['bar'] . 'px');
    ok($r['barBg'] === 'none', '  and flat, not a gradient, got ' . $r['barBg']);
    ok(str_contains($r['font'], 'IBM Plex Sans'), 'the same typeface, got ' . $r['font']);
    ok($r['gridGap'] === '8px', 'the header form is 8px gaps, not 13, got ' . $r['gridGap']);

    echo "9. REMOVING THE ONE ATTRIBUTE PUTS IT ALL BACK\n";
    /* the property that makes converting a page at a time safe: if this were
       one-way, every page would be a decision nobody could reverse */
    ok($r['off']['shadow'] !== 'none', 'the shadow returns, got ' . $r['off']['shadow']);
    ok($r['off']['radius'] === '16px', 'the 16px radius returns, got ' . $r['off']['radius']);
    ok($r['off']['align'] === 'top', 'and vertical-align:top, got ' . $r['off']['align']);
    ok(str_contains($r['off']['btn'], 'gradient'), 'and the gradient button, got '
       . substr($r['off']['btn'], 0, 30));
}
@unlink(__DIR__ . '/.ics.html'); @unlink(__DIR__ . '/.ics.js');

echo "10. The cache is busted, or nobody sees any of it\n";
ok((bool)preg_match('~zskin\.css\?v=\d+~', file_get_contents($B . 'includes/layout.php')),
   'zskin.css is cache-busted');

echo "\n$P passed, $F failed\n";
exit($F ? 1 : 0);
