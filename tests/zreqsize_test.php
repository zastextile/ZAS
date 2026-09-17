<?php
/* THE SIZE IS COMPULSORY — WHERE THERE IS ONE TO PICK.
 *
 * A proforma line whose size does not resolve is not a cosmetic problem.
 * Production works pieces-per-set out from it, so zp_pieces_needed() returns
 * ZP_NO_SIZE and THE LINE CANNOT BE BOOKED AT ALL.
 *
 * The rule is deliberately NOT "every line":
 *     linked product WITH sizes   -> one of them is required
 *     linked product with none    -> nothing to pick, so not required
 *     no product link at all      -> no list exists, so not required
 * Demanding it everywhere would make a proforma unsaveable for a product that
 * genuinely has no sizes, and several do.
 *
 * This RUNS the shipped server check and drives the shipped browser guard. */

$B  = __DIR__ . '/app_src/public_html/';
$pf = file_get_contents($B . 'proforma.php');

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

/* ---------- the server check, lifted and RUN against a stub product list ---------- */
$SIZES = [
    5 => ['Single', 'Double', 'King'],      // a product WITH sizes
    6 => [],                                 // a product with none
];
eval('function pf_sizes_of(int $p): array { return $GLOBALS["SIZES"][$p] ?? []; }
      function pf_size_id(int $p, $posted, string $label): ?int {
          $posted = (int)$posted; if ($posted > 0) return $posted;
          $label = mb_strtolower(trim($label));
          if ($p <= 0 || $label === "") return null;
          foreach ($GLOBALS["SIZES"][$p] ?? [] as $i => $l)
              if (mb_strtolower($l) === $label) return 100 + $i;
          return null;
      }');
eval(grab($pf, 'function pf_missing_sizes(') . grab($pf, 'function pf_size_error('));

echo "1. A product WITH sizes must say which one\n";
$bad = pf_missing_sizes(['Bed in Bag'], [5], [0], ['']);
ok(count($bad) === 1, 'an empty size is refused, got ' . count($bad));
ok($bad[0]['row'] === 1, '  and the LINE NUMBER is reported, got ' . ($bad[0]['row'] ?? '?'));
ok($bad[0]['sizes'] === ['Single','Double','King'], '  with the sizes it may use');

$ok1 = pf_missing_sizes(['Bed in Bag'], [5], [0], ['King']);
ok($ok1 === [], 'typing a real size passes');
$ok2 = pf_missing_sizes(['Bed in Bag'], [5], [102], ['King size, 230x260']);
ok($ok2 === [], 'A REWORDED LINE STILL PASSES on its stored link — that is the whole point of the link');

$bad2 = pf_missing_sizes(['Bed in Bag'], [5], [0], ['Emperor']);
ok(count($bad2) === 1, 'a size that is not one of this product\'s is refused');
ok($bad2[0]['typed'] === 'Emperor', '  and what was typed is quoted back, got ' . json_encode($bad2[0]['typed'] ?? null));

echo "2. NOTHING ELSE IS MADE HARDER — this is the part that must not bite\n";
ok(pf_missing_sizes(['Loose Fabric'], [6], [0], ['']) === [],
   'a product with NO sizes saves with none — there is nothing to pick');
ok(pf_missing_sizes(['One-off carton'], [0], [0], ['']) === [],
   'a typed one-off line with no product link is left alone');
ok(pf_missing_sizes([''], [5], [0], ['']) === [],
   'a blank row is skipped, exactly as the writer skips it');
/* the writer keeps a row that has a size but no name, so the check must too */
ok(count(pf_missing_sizes([''], [5], [0], ['Emperor'])) === 1,
   'but a row with a size and no name IS checked, because the writer stores it');

echo "3. Several bad lines are all reported, not just the first\n";
$many = pf_missing_sizes(['A','B','C'], [5,5,5], [0,0,0], ['', 'Nope', '']);
ok(count($many) === 3, 'all three, got ' . count($many));
ok(array_column($many, 'row') === [1,2,3], 'each with its own line number');

echo "4. The refusal says what to do, not just that it refused\n";
$msg = pf_size_error($many);
ok(str_contains($msg, 'Nothing was saved'), 'it says nothing was written');
ok(str_contains($msg, 'cannot be booked'), '  and why the size matters');
ok(str_contains($msg, 'Line 1') && str_contains($msg, 'Line 3'), '  naming the lines');
ok(str_contains($msg, 'Single, Double, King'), '  and listing what they may say');
ok(str_contains($msg, '"Nope"'), '  quoting back the value that was wrong');
$lots = pf_missing_sizes(array_fill(0,9,'X'), array_fill(0,9,5),
                         array_fill(0,9,0), array_fill(0,9,''));
$m2 = pf_size_error($lots);
ok(str_contains($m2, 'And 3 more'), 'a long list is cut and SAYS it is cut, got: '
   . substr($m2, -60));

echo "5. It runs BEFORE anything is written\n";
$flat = preg_replace('/\s+/', ' ', $pf);
ok(str_contains($flat, 'THE SIZE CHECK RUNS BEFORE A SINGLE ROW IS WRITTEN'),
   'the ordering is stated where the save happens');
$savePos = strpos($pf, '$missing = pf_missing_sizes($names, $pids, $szids, $szs);');
$writePos = strpos($pf, '$upd=db()->prepare("UPDATE proforma_items SET product_name=?,product_id=?,');
ok($savePos !== false && $writePos !== false && $savePos < $writePos,
   'and the check really is above the writer');
ok(str_contains($pf, "if (\$missing) { \$_SESSION['error'] = pf_size_error(\$missing); redirect('proforma.php?id='.\$pfid); }"),
   'a refusal redirects without touching a row');

echo "6. THE CSV IMPORT CANNOT WALK ROUND THE RULE\n";
ok(str_contains($flat, 'THE WHOLE FILE IS READ BEFORE ANYTHING IS WRITTEN'),
   'the import collects before it writes');
ok(str_contains($pf, '$missing = pf_missing_sizes(array_column($csvRows,\'name\'), $effPid,'),
   'and runs the same check');
/* the import used to write row by row: a refusal halfway would leave half the
   file in, and re-importing the fixed file would double those rows */
$collect = strpos($pf, '$csvRows[]=[');
$impWrite = strpos($pf, 'foreach ($csvRows as $r) {');
ok($collect !== false && $impWrite !== false && $collect < $impWrite,
   'it collects first and writes after, so an import lands completely or not at all');
ok(str_contains($pf, "'Nothing was imported. '"), 'and says nothing went in');
ok(str_contains($pf, 'Line numbers are rows of the CSV'),
   '  and that the line numbers mean CSV rows, not screen rows');

echo "6b. A CSV row that KEEPS its existing link is checked on that link\n";
/* the UPDATE uses COALESCE, so a CSV name that does not resolve keeps the
   row's stored product. Checking the CSV's own 0 would let a reworded line
   walk straight past the rule. */
ok(str_contains($pf, '$existingLink[(int)$er[\'id\']] = (int)($er[\'product_id\'] ?? 0);'),
   'the existing link is read');
ok(str_contains($pf, "\$effPid[] = \$r['pid'] ?: ((\$r['id'] > 0 && isset(\$existingLink[\$r['id']])) ? \$existingLink[\$r['id']] : 0);"),
   'and the check uses the link the row will ACTUALLY end up with');
ok(str_contains($flat, 'not at the CSV\'s failure to resolve one'), '  with the reason recorded');
ok(str_contains($pf, 'product_id=COALESCE(?,product_id)'), 'the COALESCE contract is untouched');

echo "\n";
/* ---------- now DRIVE the browser guard ---------- */
$js = grab($pf, 'window.pfCheckSizes = function()') . grab($pf, 'window.pfSizeGuard = function(e)');
$MASTER = [
    ['id'=>5,'name'=>'7 pcs Bed in Bag','sizes'=>[['id'=>101,'label'=>'Single'],['id'=>102,'label'=>'Double'],['id'=>103,'label'=>'King']]],
    ['id'=>6,'name'=>'Loose Fabric','sizes'=>[]],
];
$rows = [
    ['pid'=>5,'name'=>'7 pcs Bed in Bag','size'=>'King','sid'=>103],   // fine
    ['pid'=>5,'name'=>'7 pcs Bed in Bag','size'=>'','sid'=>0],          // MISSING
    ['pid'=>6,'name'=>'Loose Fabric','size'=>'','sid'=>0],              // no sizes: fine
    ['pid'=>0,'name'=>'One-off carton','size'=>'','sid'=>0],            // free text: fine
    ['pid'=>0,'name'=>'','size'=>'','sid'=>0],                          // blank: skipped
    ['pid'=>5,'name'=>'Bed in Bag (custom wording)','size'=>'230x260','sid'=>102], // reworded, still linked
];
$tr = '';
foreach ($rows as $r) {
    $tr .= '<tr><td><input data-c="name" value="' . htmlspecialchars($r['name'], ENT_QUOTES) . '">'
         . '<input type="hidden" class="pid" value="' . $r['pid'] . '"></td>'
         . '<td><input data-c="size" value="' . htmlspecialchars($r['size'], ENT_QUOTES) . '">'
         . '<input type="hidden" class="sid" value="' . $r['sid'] . '"></td></tr>';
}
$page = '<!doctype html><meta charset="utf-8">'
      . '<style>#pfItems tr.needsz > td{background:#fdeef1}</style>'
      . '<form id="pfSaveForm" onsubmit="return pfSizeGuard(event)">'
      . '<table id="pfItems"><tbody>' . $tr . '</tbody></table>'
      . '<button id="go">Save Proforma</button></form>'
      . '<script>'
      . 'var MASTER = ' . json_encode($MASTER) . ';'
      . 'var LOV = { esc:function(s){return String(s).replace(/[&<>"]/g,function(c){'
      . 'return {"&":"&amp;","<":"&lt;",">":"&gt;","\\"":"&quot;"}[c];});} };'
      . 'function byId(id){ id=+id; if(!id) return null;'
      . ' for(var i=0;i<MASTER.length;i++) if(MASTER[i].id===id) return MASTER[i]; return null; }'
      /* preventDefault() stops the SUBMISSION, not other listeners — so a probe
         that merely records "a submit event fired" proves nothing. What matters
         is whether the default was prevented. */
      . 'var submitted = false, blocked = null;'
      . $js
      . 'document.getElementById("pfSaveForm").addEventListener("submit", function(e){'
      . ' blocked = e.defaultPrevented; submitted = !e.defaultPrevented; e.preventDefault(); });'
      . '</script>';
file_put_contents(__DIR__ . '/.rs.html', $page);

$harness = <<<'JS'
const {chromium} = require('playwright');
(async () => {
  const b = await chromium.launch({executablePath:'/opt/pw-browsers/chromium'});
  const p = await b.newPage({viewport:{width:1000,height:700}});
  const errs = []; p.on('pageerror', e => errs.push(String(e)));
  await p.goto('file://' + process.argv[2]);
  await p.waitForTimeout(200);
  const out = {errors: errs};

  out.bad = await p.evaluate(() => pfCheckSizes().map(x => ({n:x.n, name:x.name})));
  out.marked = await p.evaluate(() => [...document.querySelectorAll('#pfItems tr')]
    .map((t,i) => t.classList.contains('needsz') ? i+1 : 0).filter(Boolean));

  /* the guard must STOP the submit */
  await p.click('#go');
  await p.waitForTimeout(200);
  out.submitted = await p.evaluate(() => submitted);
  out.blocked   = await p.evaluate(() => blocked);
  out.warn = await p.evaluate(() => (document.getElementById('pfSzWarn')||{}).textContent || '');
  out.focused = await p.evaluate(() => {
    const a = document.activeElement;
    return a && a.dataset ? (a.dataset.c || '') + ':' + [...document.querySelectorAll('#pfItems tr')]
      .findIndex(t => t.contains(a)) : '';
  });

  /* fix the offending line and it must go through */
  await p.evaluate(() => {
    const tr = document.querySelectorAll('#pfItems tr')[1];
    tr.querySelector('[data-c="size"]').value = 'Double';
    tr.querySelector('.sid').value = '102';
  });
  await p.click('#go');
  await p.waitForTimeout(200);
  out.submittedAfterFix = await p.evaluate(() => submitted);
  out.blockedAfterFix   = await p.evaluate(() => blocked);
  out.markedAfterFix = await p.evaluate(() =>
    document.querySelectorAll('#pfItems tr.needsz').length);
  console.log(JSON.stringify(out));
  await b.close();
})();
JS;
file_put_contents(__DIR__ . '/.rs.js', $harness);
$raw = shell_exec('node ' . escapeshellarg(__DIR__ . '/.rs.js') . ' '
                . escapeshellarg(__DIR__ . '/.rs.html') . ' 2>&1');
$r = json_decode(trim((string)$raw), true);

echo "7. The BROWSER catches it first, so nothing typed is ever lost\n";
if (!is_array($r)) { echo "  FAIL: it did not run —\n$raw\n"; $F++; }
else {
    ok($r['errors'] === [], 'no JavaScript errors, got ' . json_encode($r['errors']));
    ok(count($r['bad']) === 1, 'exactly ONE line is flagged out of six, got ' . count($r['bad'])
       . ' — ' . json_encode($r['bad']));
    ok(($r['bad'][0]['n'] ?? 0) === 2, '  and it is line 2, got ' . json_encode($r['bad'][0]['n'] ?? null));
    ok($r['marked'] === [2], 'only that row is marked red, got ' . json_encode($r['marked']));
    ok($r['blocked'] === true && $r['submitted'] === false,
       'THE SAVE IS BLOCKED, so the typing survives — defaultPrevented was '
       . json_encode($r['blocked']));
    ok(str_contains($r['warn'], '1 line still needs a size'), 'the banner counts them, got: '
       . substr($r['warn'], 0, 60));
    ok(str_contains($r['warn'], 'Single, Double, King'), '  and lists what that product allows');
    ok(str_contains($r['warn'], 'cannot be booked'), '  and says why it matters');
    ok(str_contains($r['focused'], 'size'), 'the cursor is put IN the offending cell, got '
       . json_encode($r['focused']));

    echo "8. Fix it and it goes straight through\n";
    ok($r['blockedAfterFix'] === false && $r['submittedAfterFix'] === true,
       'the save proceeds once the size is set, defaultPrevented was '
       . json_encode($r['blockedAfterFix']));
    ok($r['markedAfterFix'] === 0, '  and no row is left marked');
}
@unlink(__DIR__ . '/.rs.html'); @unlink(__DIR__ . '/.rs.js');

echo "9. The guard only blocks — it never edits what is posted\n";
$g = grab($pf, 'window.pfSizeGuard = function(e)');
ok(!preg_match('/\.value\s*=/', $g), 'pfSizeGuard writes no value');
ok(str_contains($pf, 'onsubmit="return pfSizeGuard(event)"'), 'it is bound to the save form');
ok(substr_count($pf, 'onsubmit="return pfSizeGuard(event)"') === 1,
   '  once, on the one form that saves lines');
ok(str_contains($flat, 'It only ever blocks; it never changes what is posted'),
   'and that is written down beside the form');

echo "10. The mark clears as you type, not only on the next failed save\n";
ok(str_contains($pf, "trOwn.classList.toggle('needsz'"), 'pfSize refreshes its own row');
ok(str_contains($flat, 'without waiting for another failed save'), '  and why');
ok(str_contains($pf, '#pfItems tr.needsz > td'), 'the whole ROW is marked, not just the cell');
ok(str_contains($flat, 'a red border on one narrow box scrolled off to the right is easy to miss'),
   '  and why the row rather than the cell');

echo "\n$P passed, $F failed\n";
exit($F ? 1 : 0);
