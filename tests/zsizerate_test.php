<?php
/* A KING SEAM IS LONGER THAN A SINGLE ONE, SO IT PAYS MORE.
 *
 * Two different things vary by size and they are not the same thing:
 *   HOW MANY  zp_part_qty  — a Double set has 2 pillow cases      (already worked)
 *   HOW MUCH  zp_op_rate   — a King seam is longer to overlock    (this change)
 *
 * The trap this replaces: production_operations.product_size_id has existed all
 * along and costing genuinely reads it, but zp_bridge_ops() wrote NULL on every
 * INSERT *and* every UPDATE — so a size rate set by hand there was wiped on the
 * next save. The column looked usable and was not.
 *
 * This RUNS the shipped resolver and the shipped grid JavaScript rather than
 * reading the files for strings. */

$B   = __DIR__ . '/app_src/public_html/';
$zp  = file_get_contents($B . 'includes/zprod.php');
$pm  = file_get_contents($B . 'product_master.php');

$P = 0; $F = 0;
function ok($c, $m) { global $P, $F; if ($c) { $P++; } else { $F++; echo "  FAIL: $m\n"; } }

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

/* ---------- the resolver, lifted and RUN ---------- */
eval(grab($zp, 'function zp_rate_for(') . grab($zp, 'function zp_rate_source('));

echo "1. Most specific wins, and nothing else changes\n";
$STD = 5.00;
$order = [201 => 9.00];                 // this PO negotiated 9.00
$size  = [201 => [13 => 7.00]];         // King overlock is 7.00

ok(zp_rate_for(201, $STD, [], [], 0) === 5.00, 'no overrides at all gives the standard');
ok(zp_rate_for(201, $STD, [], $size, 13) === 7.00, 'a King pays the size rate, got ' . zp_rate_for(201, $STD, [], $size, 13));
ok(zp_rate_for(201, $STD, [], $size, 11) === 5.00, 'a Single is untouched by the King rate');
ok(zp_rate_for(201, $STD, $order, $size, 13) === 9.00,
   'AN ORDER AMENDMENT BEATS THE SIZE RATE, got ' . zp_rate_for(201, $STD, $order, $size, 13));
ok(zp_rate_for(202, $STD, $order, $size, 13) === 5.00, 'another operation is not affected');
/* the size id has to actually be known — a line whose size did not resolve
   must not silently collect some other size's rate */
ok(zp_rate_for(201, $STD, [], $size, 0) === 5.00, 'no size id means the standard, never a guess');

echo "2. It can say WHERE a number came from, or a screen cannot explain itself\n";
ok(zp_rate_source(201, [], [], 0) === 'standard', 'standard');
ok(zp_rate_source(201, [], $size, 13) === 'size', 'size');
ok(zp_rate_source(201, $order, $size, 13) === 'order', 'order');
/* the two must never disagree — a screen that says "from the size" while the
   wage pays the order rate is worse than no explanation at all */
foreach ([[[], [], 0], [[], $size, 13], [$order, $size, 13], [$order, [], 0]] as $c) {
    $r = zp_rate_for(201, $STD, $c[0], $c[1], $c[2]);
    $s = zp_rate_source(201, $c[0], $c[1], $c[2]);
    $want = $s === 'order' ? $c[0][201] : ($s === 'size' ? $c[1][201][$c[2]] : $STD);
    ok($r === (float)$want, "the source agrees with the rate for '$s'");
}

echo "3. An empty table behaves EXACTLY as the app did before this existed\n";
/* the old signature took three arguments; every existing call still compiles
   and still returns what it always did */
ok(zp_rate_for(201, $STD, []) === 5.00, 'the three-argument call still works');
ok(zp_rate_for(201, $STD, $order) === 9.00, '  and still prefers an order amendment');

echo "4. Both places that pay a wage now know about the size\n";
$flat = preg_replace('/\s+/', ' ', $zp);
ok(substr_count($zp, 'zp_rate_for($opId') === 2, 'there are still exactly two, got '
   . substr_count($zp, 'zp_rate_for($opId'));
ok(str_contains($flat, '$rate = zp_rate_for($opId, (float)$op[\'rate\'], $rateCache[$pfid], $sizeRateCache[$prodId], zp_line_size_id($line));'),
   'zp_book() — the rate actually frozen onto the entry — passes the size');
ok(str_contains($zp, "zp_rate_for(\$opId, (float)\$o['rate'], \$rates, \$szRates, \$szId)"),
   'zp_work_index() — the rate the picker SHOWS — passes it too');
ok(str_contains($flat, 'THE LIST MUST QUOTE WHAT THE SAVE WILL PAY'),
   '  and why they must agree is written down');
ok(str_contains($zp, '$sizeRateCache = [];'), 'the booking cache is initialised, not an undefined index');
ok(str_contains($zp, 'static $cache = [];') && str_contains($zp, 'if ($fresh) $cache = [];'),
   'the map is cached per request AND can be dropped — a no-op "reset" would be a lie');
ok(str_contains($zp, 'zp_op_rate_map(0, true);'), '  and the save drops it');

echo "5. Typing the standard is NOT an override\n";
ok(str_contains($flat, 'TYPING THE STANDARD IS NOT AN OVERRIDE'), 'the rule is stated');
ok(str_contains($zp, 'if (abs($val - $std[$op]) < 0.0001)'), 'and enforced on the server');
ok(str_contains($zp, '$blank = ($raw === null'), 'a blank clears the override');
ok(str_contains($zp, "if (\$op <= 0 || !isset(\$std[\$op]) || !isset(\$sizes[\$sz])) continue;"),
   'a rate for an operation or size this product does not have is refused, not stored');

echo "6. The set cost counts the size rate, or the page lies about the money\n";
ok(str_contains($zp, 'function zp_set_cost(int $productId, int $sizeId): float'), 'zp_set_cost still exists');
ok(str_contains($flat, 'zp_rate_for((int)$o[\'id\'], (float)$o[\'rate\'], [], $rates, $sizeId)'),
   'it resolves each operation through the size map');
ok(!str_contains($flat, '$t += zp_part_cost((int)$p[\'id\']) * zp_qty_for('),
   'and no longer sums a part cost that cannot see a size');
ok(str_contains($flat, 'TWO THINGS VARY BY SIZE, AND THEY ARE DIFFERENT THINGS'),
   'the two kinds of size variation are told apart in writing');

echo "7. THE BRIDGE WRITES THE SIZE RATE — it used to wipe it\n";
ok(!str_contains($zp, 'VALUES (?,?,?,?,NULL,?,1,?)'), 'the INSERT no longer hard-codes NULL');
ok(!str_contains($zp, 'SET operation_name=?, component_name=?, stage=?, product_size_id=NULL'),
   'and neither does the UPDATE — that was the part that wiped a hand-set rate');
ok(str_contains($zp, '$sizeRates = zp_op_rate_map($productId);'), 'it reads the size rates');
ok(str_contains($zp, "\$want2[\$zid . '|0']"), 'the All Sizes row is still written');
ok(str_contains($zp, "\$want2[\$zid . '|' . (int)\$sid]"), '  with one extra row per size rate');
/* keyed on the op alone, several rows for one operation collapse onto one key
   and the bridge rewrites the same row while deleting the rest as strays */
ok(str_contains($zp, "\$have[(int)\$r['zp_op_id'] . '|' . (int)(\$r['product_size_id'] ?? 0)]"),
   'the existing mirror is keyed on operation AND size');
ok(str_contains(preg_replace('/\s+/', ' ', $zp),
   'WHERE product_id=? AND zp_op_id IS NOT NULL AND id IN ($in)'),
   'a stray row is deleted BY ROW ID — by zp_op_id would take the All Sizes row with it');
ok(str_contains($zp, 'zp_op_id IS NOT NULL AND id IN'),
   '  while still saying, in the DELETE itself, that it only touches bridge rows');
ok(str_contains($zp, '$gone = array_diff_key($have, $want2);'), '  and "stray" is computed on the same key');
ok(str_contains($flat, 'looked usable and was not'), 'the old trap is recorded so it is not reintroduced');

echo "8. DELETING A SIZE NEVER REFUSES — your call — but it says what it took\n";
ok(!str_contains($zp, "if (\$refs) { \$kept[] ="), 'the refusal is gone');
ok(str_contains($zp, 'if ($refs) $dropped[] ='), 'a size that carried something is still NAMED');
ok(str_contains($zp, 'DELETE FROM zp_op_rate  WHERE size_id=?'), 'its size rates go with it');
ok(str_contains($zp, 'DELETE FROM zp_part_qty WHERE size_id=?'), '  and its quantities');
ok(str_contains($zp, 'DELETE FROM product_sizes WHERE id=?'), '  and the size itself');
/* the delete and the guard must be written together or one outlives the other */
ok(str_contains($zp, "'size rates'  => \"SELECT COUNT(*) FROM zp_op_rate WHERE size_id=?\""),
   'zp_size_refs() knows about the new table, so the report is complete');
ok(str_contains($flat, 'NO "<>" HERE, UNLIKE QUANTITIES'),
   '  and why it has no <>1 clause, unlike quantities, is explained');
ok(str_contains($flat, 'CANNOT BE BOOKED until a size is linked again')
   || str_contains($flat, 'CANNOT BE BOOKED'),
   'the real consequence — an unbookable order line — is written down');
ok(str_contains($pm, "\$sz['dropped']"), 'and product_master reports it to you');
ok(!str_contains($pm, "\$sz['kept']"), '  with nothing left reading the old shape');
ok(str_contains($pm, 'cannot be booked until a size is linked to it again'),
   '  in words that say what to do about it');
ok(str_contains($pm, 'Wages already booked are unchanged'), '  and what is safe');

echo "9. The grid posts every cell, including the blank ones\n";
ok(str_contains($pm, 'name="oprate[<?= $oid ?>][<?= $sid ?>]"'), 'each cell posts by operation and size');
ok(str_contains($pm, "foreach ((\$_POST['oprate'] ?? []) as \$opId => \$bySize)"), 'the handler reads them');
ok(str_contains($pm, 'if (isset($live[(int)$sizeId]))'),
   'a size deleted in this same submit cannot collect a rate against a dead id');
ok(str_contains(preg_replace('/\s+/', ' ', $pm), 'Sent for EVERY cell, blank ones included'),
   'a blank must reach the server, or clearing a cell would do nothing');

echo "\n";
/* ---------- now RUN the grid's own JavaScript in a browser ----------
   Product Master's Parts & rates grid (owner, 22 Nov 2026: one grid — a part
   row carries its quantity per size, ▸ opens its operations with a rate per
   size). The script is lifted out of the page as shipped and run against the
   markup the PHP prints. */
$js = '';
if (preg_match('/PARTS & RATES — one grid.*?\n  pmTotals\(\);\n\}\)\(\);/s', $pm, $m)) {
    $js = substr($m[0], strpos($m[0], '(function(){'));
}
ok($js !== '', 'the grid script could be lifted');

/* the markup exactly as the PHP prints it: 3 parts, 2 ops each, 3 sizes */
$rows = '';
$ops = [[101,'Cutting','Manual Cutting','3.00'],[102,'Stitching','Overlock','5.00'],
        [103,'Cutting','Manual Cutting','2.00'],[104,'Stitching','Singer','4.00'],
        [105,'Cutting','Manual Cutting','4.00'],[106,'Stitching','Overlock','6.00']];
$parts = [[1,'Flat Sheet',[1,1,1],[0,1]], [2,'Pillow Case',[1,2,2],[2,3]], [3,'Duvet Cover',[1,1,1],[4,5]]];
$SZ = [[11,'Single'],[12,'Double'],[13,'King']];
$pre = [102 => [13 => '7.00']];        // the King overlock override
foreach ($parts as $p) {
    $rows .= '<tr class="pr" data-part="' . $p[0] . '"><td><button type="button" class="tgb" aria-expanded="false">&#9654;</button></td>'
           . '<td>' . $p[1] . '</td><td>qty / set</td><td></td>';
    foreach ($SZ as $i => $s)
        $rows .= '<td class="sz q"><input class="cell qz" data-part="' . $p[0] . '" data-size="' . $s[0] . '" name="qty[' . $p[0] . '][' . $s[0] . ']" value="' . $p[2][$i] . '"></td>';
    $rows .= '<td></td></tr>';
    foreach ($p[3] as $oi) {
        $o = $ops[$oi];
        $rows .= '<tr class="op" data-part="' . $p[0] . '" hidden><td></td><td>' . $o[2] . '</td><td>' . $o[1] . '</td><td class="n">' . $o[3] . '</td>';
        foreach ($SZ as $s) {
            $v = $pre[$o[0]][$s[0]] ?? '';
            $rows .= '<td class="sz szcell' . ($v !== '' ? ' ovr' : '') . '">'
                  . '<input class="cell ratein" name="oprate[' . $o[0] . '][' . $s[0] . ']"'
                  . ' data-std="' . $o[3] . '" value="' . $v . '" placeholder="' . $o[3] . '">'
                  . '<button type="button" class="x">&times;</button></td>';
        }
        $rows .= '<td></td></tr>';
    }
}
$foot = '';
foreach (['pw', 'sw', 'tot'] as $k) {
    $foot .= '<tr><td></td><td colspan="3">' . $k . '</td>';
    foreach ($SZ as $s) $foot .= '<td data-' . $k . '="' . $s[0] . '">0.00</td>';
    $foot .= '<td></td></tr>';
}
$page = '<!doctype html><meta charset="utf-8">'
      . '<button type="button" id="pmOpenAll">Open all</button>'
      . '<table id="pmGrid"><tbody>' . $rows . '</tbody><tfoot>' . $foot . '</tfoot></table>'
      . '<span id="ovrCount"></span>'
      . '<script>' . str_replace(['<?= json_encode(array_map(fn($s) => (int)$s[\'id\'], $sizes ?? [])) ?>', '<?= (int)$editId ?>'],
                                 ['[11,12,13]', '0'], $js) . '</script>';
file_put_contents(__DIR__ . '/.zsr.html', $page);

$harness = <<<'JS'
const {chromium} = require('playwright');
(async () => {
  const b = await chromium.launch({executablePath:'/opt/pw-browsers/chromium'});
  const p = await b.newPage({viewport:{width:1100,height:800}});
  const errs = []; p.on('pageerror', e => errs.push(String(e)));
  await p.goto('file://' + process.argv[2]);
  await p.waitForTimeout(250);
  const g = () => p.evaluate(() => ({
    totals: [...document.querySelectorAll('tfoot td[data-tot]')].map(t => t.textContent),
    ovr:    document.querySelectorAll('td.szcell.ovr').length,
    count:  document.getElementById('ovrCount').textContent
  }));
  const out = {errors: errs};
  out.start = await g();
  /* a part with a size rate opens by itself; the others stay shut */
  out.opened = await p.evaluate(() => [...document.querySelectorAll('tr.pr .tgb')].map(b => b.getAttribute('aria-expanded')));
  await p.click('#pmOpenAll');
  out.allOpen = await p.evaluate(() => [...document.querySelectorAll('tr.op')].every(r => !r.hidden));

  /* typing the standard back in must NOT mark an override */
  await p.fill('input[name="oprate[101][12]"]', '3.00');
  await p.waitForTimeout(120);
  out.typedStandard = await g();

  /* a real change must mark it AND move the total by rate x qty-per-set */
  await p.fill('input[name="oprate[103][12]"]', '2.50');
  await p.waitForTimeout(120);
  out.typedReal = await g();

  /* the x button clears it */
  await p.evaluate(() => document.querySelector('input[name="oprate[103][12]"]')
    .closest('td').querySelector('.x').click());
  await p.waitForTimeout(120);
  out.afterClear = await g();

  /* Esc clears too */
  await p.fill('input[name="oprate[104][11]"]', '9');
  await p.waitForTimeout(80);
  await p.keyboard.press('Escape');
  await p.waitForTimeout(120);
  out.afterEsc = await g();

  /* Enter moves DOWN a row in the same size column, not across */
  await p.click('input[name="oprate[101][12]"]');
  await p.keyboard.press('Enter');
  out.afterEnter = await p.evaluate(() => document.activeElement.name);

  /* a quantity moves the total by that part's whole cost */
  await p.fill('input[name="qty[2][11]"]', '2');
  await p.waitForTimeout(100);
  out.qty = await g();
  await p.fill('input[name="qty[2][11]"]', '1');

  /* closing a part HIDES its rows, it must not unload them */
  await p.click('tr.pr[data-part="1"] .tgb');
  await p.waitForTimeout(120);
  out.off = await g();
  out.hidden = await p.evaluate(() => document.querySelector('input[name="oprate[102][13]"]').closest('tr').hidden);
  out.stillInDom = await p.evaluate(() => document.querySelectorAll('input[name^="oprate"]').length);
  out.valueKept = await p.evaluate(() => document.querySelector('input[name="oprate[102][13]"]').value);
  await p.click('tr.pr[data-part="1"] .tgb');

  /* ---- the fast keys, LAST, because they change every total on purpose ---- */
  await p.fill('input[name="oprate[101][13]"]', '3.75');
  await p.click('input[name="oprate[102][13]"]');
  await p.keyboard.press('Control+d');
  await p.waitForTimeout(120);
  out.ctrlD = await p.evaluate(() => ({
    copied: document.querySelector('input[name="oprate[102][13]"]').value,
    focus:  document.activeElement.name
  }));
  await p.click('input[name="oprate[101][11]"]');
  await p.keyboard.press('Control+d');
  await p.waitForTimeout(80);
  out.ctrlDTop = await p.evaluate(() => document.querySelector('input[name="oprate[101][11]"]').value);

  await p.click('input[name="oprate[103][11]"]');
  await p.evaluate(() => {
    const el = document.querySelector('input[name="oprate[103][11]"]');
    const dt = new DataTransfer(); dt.setData('text', '1.10\t1.20\n1.30\t1.40');
    el.dispatchEvent(new ClipboardEvent('paste', {clipboardData: dt, bubbles: true}));
  });
  await p.waitForTimeout(150);
  out.pasted = await p.evaluate(() => ({
    a: document.querySelector('input[name="oprate[103][11]"]').value,
    b: document.querySelector('input[name="oprate[103][12]"]').value,
    c: document.querySelector('input[name="oprate[104][11]"]').value,
    d: document.querySelector('input[name="oprate[104][12]"]').value,
    king: document.querySelector('input[name="oprate[103][13]"]').value
  }));
  await p.click('input[name="oprate[106][12]"]');
  await p.evaluate(() => {
    const el = document.querySelector('input[name="oprate[106][12]"]');
    const dt = new DataTransfer(); dt.setData('text', '9.10\t9.20\t9.30\n9.40\t9.50\t9.60');
    el.dispatchEvent(new ClipboardEvent('paste', {clipboardData: dt, bubbles: true}));
  });
  await p.waitForTimeout(150);
  out.overflow = await p.evaluate(() => ({
    hit:  document.querySelector('input[name="oprate[106][12]"]').value,
    next: document.querySelector('input[name="oprate[106][13]"]').value,
    first: document.querySelector('input[name="oprate[101][11]"]').value
  }));
  console.log(JSON.stringify(out));
  await b.close();
})();
JS;
file_put_contents(__DIR__ . '/.zsr.js', $harness);
$raw = shell_exec('node ' . escapeshellarg(__DIR__ . '/.zsr.js') . ' '
                . escapeshellarg(__DIR__ . '/.zsr.html') . ' 2>&1');
$r = json_decode(trim((string)$raw), true);

echo "10. The grid, RUN: totals, marks and keys\n";
if (!is_array($r)) { echo "  FAIL: it did not run —\n$raw\n"; $F++; }
else {
    ok($r['errors'] === [], 'no JavaScript errors, got ' . json_encode($r['errors']));
    /* Single 3+5+2+4+4+6 = 24; Double the same but 2 pillow cases = 30;
       King the same as Double plus the 7.00 overlock = 32 */
    ok($r['start']['totals'] === ['24.00','30.00','32.00'],
       'the arithmetic is right from the start, got ' . json_encode($r['start']['totals']));
    ok($r['start']['ovr'] === 1, 'one override to begin with');
    ok($r['opened'] === ['true','false','false'],
       'the part carrying a size rate opens by itself, the rest stay shut, got ' . json_encode($r['opened']));
    ok($r['allOpen'] === true, 'Open all opens every part');

    ok($r['typedStandard']['ovr'] === 1,
       'TYPING THE STANDARD MARKS NOTHING, got ' . $r['typedStandard']['ovr'] . ' overrides');
    ok($r['typedStandard']['totals'] === ['24.00','30.00','32.00'], '  and moves no total');

    ok($r['typedReal']['ovr'] === 2, 'a real change is marked, got ' . $r['typedReal']['ovr']);
    ok($r['typedReal']['totals'][1] === '31.00',
       '  and the total moves by rate x qty-per-set (2 pillow cases), got ' . $r['typedReal']['totals'][1]);
    ok($r['typedReal']['totals'][0] === '24.00', '  while the other sizes stay put');
    ok(str_contains($r['typedReal']['count'], '2 size rates set'), '  and the counter agrees');

    ok($r['afterClear']['ovr'] === 1, 'the x button clears an override');
    ok($r['afterClear']['totals'][1] === '30.00', '  and the total goes back');
    ok($r['afterEsc']['ovr'] === 1, 'Esc clears one too');

    ok($r['afterEnter'] === 'oprate[102][12]',
       'Enter moves DOWN the size column to the next operation, got ' . json_encode($r['afterEnter']));
    ok($r['qty']['totals'][0] === '30.00',
       'a second pillow case on Single adds its whole cost (2+4), got ' . json_encode($r['qty']['totals']));

    echo "11. Closing a part HIDES its rows, it does not lose them\n";
    ok($r['hidden'] === true, 'the rows are hidden');
    ok($r['stillInDom'] === 18, 'every input is still in the form, got ' . $r['stillInDom']);
    ok($r['valueKept'] === '7.00', 'AND STILL CARRIES ITS VALUE, got ' . json_encode($r['valueKept']));
    ok($r['off']['totals'][2] === '32.00', 'the set cost still includes the size rate');

    echo "11b. Ctrl+D and an Excel block paste — this is the 'fast' part\n";
    ok($r['ctrlD']['copied'] === '3.75',
       'Ctrl+D copies the cell above, got ' . json_encode($r['ctrlD']['copied']));
    ok($r['ctrlD']['focus'] === 'oprate[103][13]',
       '  and moves on, so holding it fills a column, got ' . json_encode($r['ctrlD']['focus']));
    ok($r['ctrlDTop'] === '',
       '  and on the top row it does nothing rather than throwing, got ' . json_encode($r['ctrlDTop']));
    ok($r['pasted']['a'] === '1.10' && $r['pasted']['b'] === '1.20'
       && $r['pasted']['c'] === '1.30' && $r['pasted']['d'] === '1.40',
       'a 2x2 block from Excel lands across and down, got ' . json_encode($r['pasted']));
    ok($r['pasted']['king'] === '', '  and a column it never reached stays empty');
    ok($r['overflow']['hit'] === '9.10', 'a block bigger than what is left still starts where you are');
    ok($r['overflow']['next'] === '9.20', '  filling what it can');
    ok($r['overflow']['first'] === '',
       '  AND TRIMS THE REST — it never wraps a rate onto another operation, got '
       . json_encode($r['overflow']['first']));
}
@unlink(__DIR__ . '/.zsr.html'); @unlink(__DIR__ . '/.zsr.js');

echo "11c. NOTHING IS DISTURBED — the default path is the old path\n";
ok(str_contains($pm, "openPart(tr, hasOvr || !!opened[tr.dataset.part]);"),
   'a part opens by itself only when it carries a size rate, so no number in the footer is unexplained');
ok(str_contains($pm, '<?php if (!$nS): ?>'),
   'a product with no sizes never renders a size column at all');
ok(str_contains($pm, '$cols = 5 + $nS;'),
   '  and the message rows span the right width, so the table cannot shear');
ok(str_contains($pm, 'if ($rr) {'),
   'a submit carrying no rate cells writes nothing');
/* the Standard column is READ-ONLY: a part is a shared library row, so editing
   its rate here would move every other product using that part */
ok(!str_contains($pm, 'name="opstd['), 'the Standard rate is not editable here');
ok(str_contains($pm, 'Edit in Part Library'), '  and says where it IS edited');
ok(substr_count($pm, 'zp_op_rate_save($pid') === 1, 'one writer, got '
   . substr_count($pm, 'zp_op_rate_save($pid'));
$fa = strpos($pm, '<form method="post" id="pmForm"'); $fb = strpos($pm, '</form>', $fa);
$ra = strpos($pm, 'name="oprate[<?= $oid ?>][<?= $sid ?>]"');
ok($ra > $fa && $ra < $fb,
   'THE RATE BOXES ARE INSIDE THE FORM — below it, as they were, nothing typed there ever reached Save');

echo "11d. It is the SAME look as the other Fiori screens\n";
ok(str_contains($pm, 'assets/css/zf.css'), 'it loads the shared list look');
ok(str_contains($pm, '.zf.pm .pm-g td.szcell.ovr{'), 'an override is green, as the legend says');
ok(str_contains($pm, "(e.ctrlKey || e.metaKey) && (e.key === 'd' || e.key === 'D')"),
   'Ctrl+D is bound, like the other grids');
ok(str_contains($pm, "grid.addEventListener('paste'"), 'and an Excel block paste');
ok(str_contains($pm, 'BACKSPACE IS DELIBERATELY NOT BOUND'),
   'and Backspace is left alone, on purpose, in writing');

echo "12. The schema mirrors the quantity table it sits beside\n";
ok(str_contains($zp, 'CREATE TABLE IF NOT EXISTS zp_op_rate'), 'the table exists');
ok(str_contains($zp, 'UNIQUE KEY uniq_por (product_id, part_op_id, size_id)'),
   'same three-column key as zp_part_qty');
ok(str_contains($zp, 'INDEX(product_id), INDEX(size_id)'), '  and an index the delete can use');
ok(str_contains($flat, 'NO ROW MEANS THE DEFAULT'), 'the inheritance rule is written down');
ok(str_contains($flat, 'zp_parts.part_name is UNIQUE'),
   'and why a shared part is still safe to override per product');

echo "\n$P passed, $F failed\n";
exit($F ? 1 : 0);
