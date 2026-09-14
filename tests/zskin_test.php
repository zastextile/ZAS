<?php
/* THE SKIN.
 *
 * A stylesheet cannot break a calculation, but it can absolutely break a
 * screen, and in two ways that matter here:
 *
 *   IT LEAKS        one loose selector and all 57 pages change at once,
 *                   including the ones nobody has looked at yet
 *   IT OVERRIDES    !important on a background turns every Delete button the
 *                   same colour as Save — the two you most need to tell apart
 *
 * Both are tested. So is the fallback: if this file fails to load, the page
 * must still render the way it did yesterday, not as unstyled boxes.
 */
$B  = __DIR__ . '/app_src/public_html/';
$css = file_get_contents($B . 'assets/css/zskin.css');
$pf  = file_get_contents($B . 'proforma.php');
$lay = file_get_contents($B . 'includes/layout.php');

$P = 0; $F = 0;
function ok($c, $m) { global $P, $F; if ($c) { $P++; } else { $F++; echo "  FAIL: $m\n"; } }
function flat(string $s): string { return preg_replace('/\s+/', ' ', $s); }

echo "1. NOTHING LEAKS — every rule is scoped, so an un-opted page is untouched\n";
/* strip comments, then read every selector that carries a declaration block */
$code = preg_replace('!/\*.*?\*/!s', ' ', $css);
preg_match_all('/([^{}]+)\{[^{}]*\}/', $code, $m);
$loose = [];
foreach ($m[1] as $sel) {
    $sel = trim($sel);
    if ($sel === '' || $sel[0] === '@') continue;
    foreach (explode(',', $sel) as $one) {
        $one = trim($one);
        if ($one === '') continue;
        /* allowed: anything mentioning .zskin, and the ground painted on a body
           that CONTAINS a .zskin — which is still conditional on opting in */
        if (str_contains($one, '.zskin')) continue;
        $loose[] = $one;
    }
}
ok($loose === [], 'no selector escapes .zskin: ' . json_encode(array_slice($loose, 0, 4)));
ok(str_contains($css, 'body:has(.zskin)'), 'even the page background is conditional on opting in');
ok(!preg_match('/^\s*(body|html|table|input|button|a)\s*\{/m', $code),
   'no bare element selector anywhere');

echo "2. The page opts in with ONE class, and can opt out again\n";
ok(substr_count($pf, '<div class="zskin">') === 2, 'both views are wrapped, got '
   . substr_count($pf, '<div class="zskin">'));
ok(substr_count($pf, 'closes .zskin') === 2, 'and both are closed');
ok(str_contains($pf, 'removing this attribute puts the'), 'and reversing it is written down');

echo "2b. THE GRID CELL IS FLAT UNTIL YOU ARE IN IT\n";
ok(str_contains($css, 'border:1px solid transparent !important'), 'a resting cell has no border');
ok(str_contains($css, '.zskin .zgwrap td>input:hover{border-color:var(--bd)'), 'hover hints at it');
ok(str_contains($css, 'box-shadow:inset 0 0 0 1px var(--acc)'), 'focus draws it properly');
ok(str_contains(flat($css), 'a grid of sixty lines is scannable and a grid of sixty boxes is not'),
   'and why is written down');
ok(str_contains($css, '--rowh:30px'), 'rows are 30px');
ok(str_contains($css, '.zskin .zpin-l') && str_contains($css, '.zskin .zpin-r'), 'both edges pin');
/* three each: the header cell, the saved row and the new-row template */
ok(substr_count($pf, 'class="zpin-l"') === 3 && substr_count($pf, 'class="zpin-r"') === 3,
   'header, saved row and new row all pin the same columns, got '
   . substr_count($pf, 'class="zpin-l"') . '/' . substr_count($pf, 'class="zpin-r"'));

echo "2c. A NEW ROW IS THE SAME MARKUP AS A SAVED ROW\n";
/* a line you add must look and behave exactly like one that loaded, or a
   column silently stops lining up and nobody sees it until print */
foreach (['zpin-l','zdim','class="num" data-c="qty"','zro amt','rowx','lovf','data-c="size"'] as $bit)
    ok(substr_count($pf, $bit) >= 2, "both rows carry $bit, got " . substr_count($pf, $bit));
ok(!preg_match('/class="[^"]*"\s+class="/', $pf),
   'NO DUPLICATE class ATTRIBUTE — the second is dropped by every browser, '
   . 'which is how the product picker would have silently stopped working');

echo "3. The old inline styles are KEPT as the fallback\n";
/* a stale cache on the stylesheet has already cost this project a round:
   the markup arrived, the rules did not, and the page rendered as raw boxes */
ok(str_contains($pf, "\$card='padding:22px"), 'the card still carries its own style');
ok(str_contains($pf, "\$inp='width:100%;padding:6px 8px"), 'so does the field');
ok(str_contains($pf, "\$btn='padding:7px 13px"), 'and the button');
ok(preg_match('/class="<\?= \$cardC \?>" style="<\?= \$card \?>"/', $pf) === 1,
   'the class sits BESIDE the inline style, not instead of it');
ok(str_contains(flat($pf), 'the page still renders as it did yesterday'), 'and why is recorded');

echo "4. SAVE AND DELETE CANNOT LOOK THE SAME\n";
/* .zbt forces its background with !important; without a destructive variant
   every Delete would be painted the same blue as Save */
ok(str_contains($css, '.zskin .zbt.del'), 'there is a destructive variant');
ok(str_contains($css, '.zskin .zbt.del:hover{background:var(--bad-soft)'), '  and it wins the override');
/* the per-ROW remove is no longer a .zbt at all — a red button repeated sixty
   times down a grid is the loudest thing on the screen, so it became .rowx:
   a quiet glyph that only turns red on the row you are on */
ok(substr_count($pf, '$btnC ?> del') === 1, 'the page-level destructive button uses it, got '
   . substr_count($pf, '$btnC ?> del'));
ok(str_contains($css, '.zskin .rowx'), 'and the per-row remove is a quieter control');
ok(str_contains($css, '.zskin .rowx:hover{background:var(--bad-soft)'), '  that still goes red on hover');
ok(str_contains(flat($css), 'repeated sixty times down a grid is the loudest thing'),
   '  and why it is not a button');
ok(!preg_match('/class="<\?= \$btnC \?>" style="<\?= \$btn \?>;background:rgba\(224,67,93/', $pf),
   'and none is left relying on an inline colour the skin would overrule');
ok(str_contains(flat($css), 'the two buttons you most need to tell apart'),
   'the trap is written down so it is not walked into again');

echo "5. It is dense, and flat, because the reference is AG Grid\n";
ok(str_contains($css, 'padding:0 !important'), 'the cell has no padding — the input fills it');
ok(str_contains($css, 'padding:12px 14px !important'), 'panels are tight');
ok(str_contains($css, 'box-shadow:none !important'), 'and carry no shadow');
ok(!str_contains($css, 'border-radius:99px !important'), 'no pill buttons');
ok(str_contains($css, 'border-radius:5px !important'), 'buttons are 5px');
ok(str_contains($css, '--t:170ms'), 'transitions are 170ms, inside the 150-200 asked for');
ok(str_contains($css, 'flex-wrap:nowrap'), 'the chip cannot wrap to a second line');
ok(str_contains(flat($css), 'makes the whole grid jump as you type'),
   '  because a row that changes height while typing is worse than a short word');
ok(str_contains($pf, '&#9998;'), 'the reword sentence became a mark');
ok(str_contains($pf, 'title="Linked. What prints is your wording'), '  with the words kept in the tooltip');

echo "5b. THE FORM SECTIONS ARE AS TIGHT AS THE GRID\n";
/* tightening the grid and leaving the form alone looked worse than touching
   neither: a 30px grid row under 44px form rows reads as two applications */
ok(str_contains($css, '.zskin [style*="grid-template-columns"]{gap:8px !important}'),
   'inline grid gaps are overridden, because no stylesheet can reach them otherwise');
ok(str_contains(flat($css), 'The attribute selector is deliberate and narrow'),
   '  and the bluntness of that selector is acknowledged');
ok(str_contains(flat($css), 'push the Bank 2 block below the fold'),
   '  with what the space actually cost');
ok(!str_contains($pf, 'minmax(190px,1fr));gap:14px'), 'no 14px gap survives on the proforma');
ok(!str_contains($pf, 'minmax(200px,1fr));gap:14px'), '  nor the other one');
ok(str_contains($pf, "margin-bottom:3px';"), 'labels sit 3px from their field, not 5');
ok(str_contains($pf, 'font-size:13.5px;font-weight:600;margin:0 0 9px'),
   'card headings are 13.5px with 9px under them');
ok(str_contains($pf, 'padding:9px 11px;border-radius:6px'), 'the bank sub-panels are tightened');
ok(str_contains($css, 'min-height:54px'), 'a textarea keeps a usable height rather than collapsing');

echo "6. Both themes are defined at token level\n";
ok(substr_count($css, '--acc:') >= 3, 'the palette is redefined for dark, not patched per component');
ok(str_contains($css, ':root:not([data-theme="light"]) .zskin'), 'system dark is handled');
ok(str_contains($css, ':root[data-theme="dark"] .zskin'), 'and an explicit choice beats it');
ok(str_contains(flat($css), 'classic unreadable-page bug'), 'and the reason is stated');

echo "6b. The three missing keys are in the SHARED grid, not one page\n";
$g = file_get_contents($B . 'assets/js/grid.js');
ok(str_contains($g, "e.key === 'ArrowLeft' || e.key === 'ArrowRight'"), 'left and right move cells');
ok(str_contains($g, 'if (e.key === \'ArrowLeft\'  && !atStart) return;'),
   '  but ONLY at the edge of the text, so the caret keeps them');
ok(str_contains(flat($g), 'make correcting a typo impossible without the mouse'),
   '  and why grabbing them outright would be wrong');
ok(str_contains($g, "e.key === 'Home' || e.key === 'End'"), 'Home and End jump to the row ends');
ok(str_contains($g, "if (e.key === 'Delete')"), 'Delete clears a selected cell');
ok(str_contains($g, "el.selectionStart === 0 && el.selectionEnd === el.value.length"),
   '  only when the whole value is selected');
ok(!str_contains($g, "e.key === 'Backspace'"),
   'Backspace is deliberately NOT bound — it means "rub out one character" to everybody');
ok(str_contains(flat($g), 'stealing it would delete a whole cell by surprise'), '  and that is recorded');
ok(str_contains($g, 'e.shiftKey || e.ctrlKey || e.metaKey'), 'Shift+Arrow still selects text');

echo "6c. The filter hides rows, it never removes them\n";
ok(str_contains($pf, 'tr.hidden = !on;'), 'rows are hidden, not detached');
ok(str_contains(flat($pf), 'a detached row would silently stop being saved'),
   '  because a detached row would stop posting and the line would vanish');
ok(str_contains($pf, 'shown + \' of \' + rows.length'), 'and the count says how many are hidden');

echo "7. The stylesheet is cache-busted, like the last one had to be\n";
ok(preg_match('/zskin\.css\?v=\d+/', $lay) === 1, 'it carries a version');
ok(str_contains($lay, 'assets/css/app.css?v=29'), 'and app.css keeps its own');
ok(str_contains(flat($lay), 'until it puts that class on a wrapper'),
   'layout.php says why linking it globally is safe');

echo "8. The font cannot break the page if it never arrives\n";
ok(str_contains($css, 'ui-sans-serif, system-ui'), 'IBM Plex has a real fallback stack');
ok(str_contains($css, 'ui-monospace, Menlo, Consolas'), 'and so does the mono');
ok(str_contains($lay, 'fonts.googleapis.com'), 'it is fetched from the one allowed host');

echo "8b. THE TYPE-TO-FILTER PICKER IS UNTOUCHED\n";
/* the thing asked about directly: start typing a product and the list narrows.
   It binds on data-lov, NOT on the lovf class, and its panel is appended to
   document.body — outside .zskin — so neither the markup change nor the skin
   can reach it. */
$lov = file_get_contents($B . 'assets/js/lov.js');
ok(str_contains($lov, "!t.dataset.lov || !PROV[t.dataset.lov]"),
   'the picker binds on data-lov, not on a CSS class');
ok(substr_count($pf, 'data-lov="prod"') === 2, 'both rows still carry it, got '
   . substr_count($pf, 'data-lov="prod"'));
ok(str_contains($pf, 'LOV.attach(tb)') && str_contains($pf, "LOV.register('prod'"),
   'and the page still registers and attaches it');
ok(str_contains($lov, 'document.body.appendChild(el)'),
   'its panel lives on the body, outside .zskin, so the skin cannot restyle it');
/* EVERY key the grid added must stand down while the list is open, or the
   arrows that choose a row would move a cell instead */
$kg = substr_count($g, 'if (lovOpen');
ok($kg >= 6, "every grid key defers to the open list, got $kg");
ok(str_contains($g, "if (lovOpen || e.shiftKey"), '  including the new left/right');
ok(str_contains($g, "if (lovOpen || e.ctrlKey || e.metaKey) return;"), '  Home and End');
ok(str_contains($g, "if (lovOpen || el.tagName === 'SELECT') return;"), '  and Delete');

echo "8c. THE SKIN MAY NOT STEAL A NAME A PAGE IS ALREADY USING\n";
/* THE BUG THIS CAUGHT, BEFORE IT SHIPPED.
   The grid panel was first called .zgrid — and shipment_view.php already
   defines .zgrid as a grid of FORM FIELDS. Opting that page in would have
   wrapped its form in a border and clipped it, for no reason visible anywhere
   in the page's own markup. A shared stylesheet does not get to take a name a
   page is already using for something else. */
$pages = ['proforma.php','shipment_view.php','product_costing.php','final_costing.php'];
$skinCss = preg_replace('!/\*.*?\*/!s', ' ', $css);
preg_match_all('/\.zskin[^{,]*?\.([a-zA-Z][\w-]*)/', $skinCss, $sm);
$styled = array_unique($sm[1]);
/* these are overridden ON PURPOSE — the skin is meant to restyle them */
$intended = ['zcard','zin','zlab','zlabel','zbt','zbtn','ztable','linkchip','total-row',
             'fc-table','fc-in','num','tablewrap','itag','badge','chip','lead','rowx',
             'zgpanel','zgwrap','zpin-l','zpin-r','ztools','zfind','zsep','zsp','zcnt',
             'zst','zempty','zhint',
             /* product_costing's cost-line grid. .ct is its table, .ct-edit marks
                the one editable grid among the four tables that share .ct, and
                .footbar is its action bar. All three are defined in exactly one
                page and used in exactly one page — checked below, not assumed. */
             'ct','ct-edit','footbar'];
/* A NAME CLAIMED ON PURPOSE STILL HAS TO BE UNIQUE. .zgrid was a collision
   precisely because TWO pages meant different things by it, so an allowlist
   entry is only safe while the name belongs to one page. */
foreach (['ct','ct-edit','footbar'] as $c) {
    $defs = 0;
    foreach ($pages as $pg) {
        $src = file_get_contents($B . $pg);
        if (preg_match_all('/<style>(.*?)<\/style>/s', $src, $bm))
            foreach ($bm[1] as $b)
                if (preg_match('/(?:^|[\s,>])[a-zA-Z]*\.' . preg_quote($c, '/') . '\s*[{,:.\s]/',
                    preg_replace('!/\*.*?\*/!s', ' ', $b))) { $defs++; break; }
    }
    /* AT MOST one. Two readings are both safe and the count tells them apart:
         1  a name a page already owns, mapped on purpose (.ct, .footbar)
         0  a name the skin invented, so nothing can clash with it (.ct-edit)
       TWO or more is the .zgrid bug — one stylesheet, two meanings. */
    ok($defs <= 1, ".$c is defined by at most one page, got $defs");
}
$taken = [];
foreach ($pages as $pg) {
    $src = file_get_contents($B . $pg);
    preg_match_all('/<style>(.*?)<\/style>/s', $src, $bm);
    $own = [];
    foreach ($bm[1] as $b) {
        $b = preg_replace('!/\*.*?\*/!s', ' ', $b);
        /* the optional [a-zA-Z]* matters: product_costing writes `table.ct{`,
           and a regex demanding a bare `.ct{` never saw the page owned that
           name at all — the exact blind spot the .zgrid collision lives in. */
        preg_match_all('/(?:^|[\s,>])[a-zA-Z]*\.([a-zA-Z][\w-]*)\s*[{,:.\s]/', $b, $om);
        $own = array_merge($own, $om[1]);
    }
    foreach (array_intersect($styled, array_unique($own)) as $c)
        if (!in_array($c, $intended, true)) $taken[] = "$pg:.$c";
}
ok($taken === [], 'the skin takes no name a page uses for something else: ' . json_encode($taken));
ok(str_contains($css, '.zskin .zgpanel{'), 'the grid panel is .zgpanel, not .zgrid');
ok(!preg_match('/\.zskin\s+\.zgrid\b/', $css), 'and .zgrid is left to the page that already had it');
ok(str_contains(flat($css), 'does not get to take a name a page is already using'),
   'the rule is written down');

echo "8d. FOUR PAGES ARE IN, AND EACH CAN BE TAKEN BACK OUT\n";
foreach ($pages as $pg) {
    $src = file_get_contents($B . $pg);
    ok(substr_count($src, '<div class="zskin">') >= 1, "$pg opts in");
    ok(substr_count($src, '<div class="zskin">') === substr_count($src, 'closes .zskin'),
       "  $pg opens and closes the same number of times");
}
ok(str_contains(flat($css), 'a page should not have to be rewritten to be restyled'),
   'the pages keep their own class names; the skin maps onto them');
ok(str_contains(flat($css), 'looks more broken than one that was never touched'),
   'and a half-skinned screen is called out as worse than an untouched one');

echo "9. Nothing about behaviour moved\n";
ok(str_contains($pf, 'pf_size_id('), 'the size link still resolves server-side');
ok(str_contains($pf, 'window.pfUnlinkSize'), 'the unlink button still exists');
ok(str_contains($pf, 'name="i_size_id[]"'), 'the posted fields are unchanged');
ok(!str_contains($css, 'display:none'), 'the skin hides nothing');
/* content is allowed for the badge's dot, which is a shape, not words */
preg_match_all('/content:\s*([^;}]+)/', $css, $cm);
$words = array_values(array_filter($cm[1], function ($v) {
    $v = trim($v); return $v !== '""' && $v !== "''" && $v !== 'none';
}));
ok($words === [], 'the skin invents no text of its own: ' . json_encode($words));

echo "\n$P passed, $F failed\n";
exit($F ? 1 : 0);
