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
      const cline = rows[0].querySelector('.ctag');
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
        clineVal: cline ? cline.textContent.trim() : null,
        clineSet: cline ? cline.classList.contains('set') : false,
        row2Val: rows[1].querySelector('.ctag').textContent.trim(),
        row2Set: rows[1].querySelector('.ctag').classList.contains('set'),
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
    /* THE CONTRACT COLUMN IS GONE, at the owner's instruction: it held a
       value chosen once and only read afterwards, on every pass, whether or
       not a contract was ever used. The contract is chosen inside the item
       list now and read back as a tag on the item itself.
       Item, Lot/size, UOM, Quantity, Rate, Amount, ×  = 7
       plus Available on an outward pass               = 8 */
    ok($M['in']['cols'] === 7, 'inward grid has 7 columns, got ' . $M['in']['cols']);
    ok($M['out']['cols'] === 8, 'outward grid has 8 columns, got ' . $M['out']['cols']);
    ok($M['in']['headers'][0] === 'Item', 'the item leads, got ' . json_encode($M['in']['headers']));
    ok(!in_array('Contract', $M['in']['headers'], true),
       'and no column is spent on the contract, got ' . json_encode($M['in']['headers']));

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
        /* THE TAG IS OUT OF THE FLOW, which is the only reason it can sit on
           the item without making that line taller. It is vertically centred
           on the same band as the quantity box rather than aligned to its
           top, so "same top" is the wrong test now — "inside the row" is the
           right one. */
        ok($M[$d]['clineTop'] >= $M[$d]['qtyTop'] - 12 && $M[$d]['clineTop'] <= $M[$d]['qtyTop'] + 12,
           "$d: the contract tag sits on the item line, not under it ("
           . $M[$d]['clineTop'] . ' vs ' . $M[$d]['qtyTop'] . ')');
        ok($M[$d]['clineH'] < $M[$d]['qtyH'],
           "$d: and it is smaller than a field, because it is a label and not one ("
           . $M[$d]['clineH'] . ' vs ' . $M[$d]['qtyH'] . ')');
    }
    /* Narrow: it is a tag on the item cell now, not a column of its own. */
    ok($M['in']['clineW'] <= 110,
       'the tag is small enough to sit on the item without hiding it, got '
       . $M['in']['clineW'] . 'px');

    echo "5. Two states now, not three\n";
    ok($M['in']['clineVal'] === 'PC-2026-0031', 'a line with a contract shows it');
    ok($M['in']['clineSet'] === true, '  and is marked as chosen');
    ok($M['in']['row2Val'] === '', 'a line without one shows nothing');
    ok($M['in']['row2Set'] === false, '  and is not marked');
    /* THE THIRD STATE IS GONE WITH THE HEADER FIELD. A line used to be able
       to "follow" a contract named on the pass header, shown as a greyed
       placeholder. There is no header contract any more — one pass routinely
       carries lines from two contracts, so a single header value was never
       the whole truth — and a line either names one or does not. */
    ok(!str_contains($ig, 'follows the contract on the pass header'),
       'nothing still promises a line can follow a header contract');
    ok($M['in']['cid'] === '31' && $M['in']['citem'] === '502',
       'both halves of the link are stored on the line');
    ok($M['in']['cidName'] === 'line[0][contract_id]'
       && $M['in']['citemName'] === 'line[0][contract_item_id]',
       'and they post under the line, got ' . $M['in']['cidName']);
    ok($M['in']['citemInDetail'] === false,
       'the old hidden field is gone from the detail strip — one home, not two');
}

/* ------------------------------------------------------------------ */
echo "6. The separate contract picker is gone, and so is this section\n";
/* THIS SECTION USED TO DRIVE A SECOND PICKER.
 *
 * A line had its own contract box in its own column, with its own list, and
 * this drove it: open it, type, choose, watch the party filter apply. That
 * box no longer exists. The owner asked for the contract to leave both the
 * header and the grid and to be chosen inside the item list instead — one
 * question, "which item, and against what?", answered in one place.
 *
 * The behaviour it protected did not go anywhere; it moved. Party scoping,
 * the two sections, what an outward pass may offer against an inward one,
 * and a contract line naming an item the stock list has not got are all
 * driven in zgatecl_test.php, against the list that replaced this one.
 * Deleting the section outright would have left no trace of where its
 * assertions went, which is how a test suite quietly loses coverage. */
$igc = preg_replace('!/\*.*?\*/!s', '', $ig);
ok(!str_contains($igc, "data-lov=\"cline\""), 'no line carries a second picker any more');
ok(!str_contains($igc, "LOV.register('cline'"), '  and it is not registered');
ok(!str_contains($igc, 'function clRows('), '  nor is its row builder still sitting unused');
/* What replaced it still has to be party-scoped, which is the one rule this
   section existed for. */
ok(str_contains($igc, 'clLoad(function(){ var r = itemRowsFor(q, showAll); cb(r[0], r[1]); });'),
   'the item list loads this PARTY\'s contract lines before it draws');
ok(str_contains($igc, "var cls = (CLPARTY === clParty() && CLINES) ? CLINES : null;"),
   '  and reads them only when they belong to the party now on the pass');


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
