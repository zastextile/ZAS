<?php
/* THE PICKER CLOSED A PANEL THAT WAS NOT ITS OWN.
 *
 * assets/js/lov.js is the type-to-find list behind every item, lot and
 * contract box in the app — gate passes, consumption, store issues,
 * proforma, final costing. Its focusout handler waits 140ms and then
 * closes the panel, so that clicking away does not leave a list hanging
 * over the page.
 *
 * The timer checked that the panel was open and that focus had left the
 * field it was armed for. It did NOT check that the open panel still
 * belonged to that field. Moving from one picker straight to another —
 * "+ Add line", or Enter on a quantity jumping to the next row's item
 * box — opens a new panel within those 140ms, and the old field's timer
 * then closed it. The list appeared and vanished, and the next Enter
 * chose nothing.
 *
 * This was found by driving a NEW screen, but it was never that screen's
 * bug. It is reproduced here against the real file with two plain inputs
 * and a three-line provider, so it stays fixed for every screen.
 */

$B = __DIR__ . '/app_src/public_html/';
$P = 0; $F = 0;
function ok($c, $m) { global $P, $F; if ($c) { $P++; } else { $F++; echo "  FAIL: $m\n"; } }

$work = __DIR__ . '/.zlovfocus';
@mkdir($work, 0777, true);

echo "1. The guard is in the file, and says why\n";
$lov = file_get_contents($B . 'assets/js/lov.js');
ok(str_contains($lov, 'S.open && S.f === f && document.activeElement !== f'),
   'the delayed close only acts on the panel it was armed for');
ok(str_contains($lov, 'S.f === f IS LOAD-BEARING'),
   'and the reason is written where someone tidying the condition will read it');

echo "2. Reproduced in a browser, against the real file\n";

/* Two plain inputs. Nothing from any page — if this needed a page's
   markup to fail, it would be that page's bug, and it is not. */
$page = '<!doctype html><html><head><meta charset="utf-8"></head><body><div id="root">'
      . '<input id="a" data-lov="x"><input id="b" data-lov="x"><input id="plain">'
      . '</div><scr' . 'ipt>' . file_get_contents($B . 'assets/js/lov.js') . '</scr' . 'ipt><scr' . 'ipt>'
      . 'window.REVERTED = [];'
      . 'LOV.register("x", {'
      . '  cols:[{label:"Name", w:"1fr", get:function(r){ return r.n; }}],'
      . '  rows:function(f,q,s,cb){ cb([{n:"alpha"},{n:"beta"}],0); },'
      . '  revert:function(f){ window.REVERTED.push(f.id); },'
      . '  pick:function(f,r){ f.value = r.n; }'
      . '});'
      . 'LOV.attach(document.getElementById("root"));'
      . '</scr' . 'ipt></body></html>';
file_put_contents($work . '/t.html', $page);

$probe = <<<'JS'
const { chromium } = require('playwright');
(async () => {
  const br = await chromium.launch({ executablePath: '/opt/pw-browsers/chromium' });
  const pg = await br.newPage();
  const errs = []; pg.on('pageerror', e => errs.push(String(e)));
  await pg.goto('file://' + process.argv[2] + '/t.html');
  const open = () => pg.evaluate(() => !!document.querySelector('.lov.open'));
  const R = { errs };

  // THE BUG: picker to picker, inside the 140ms window
  await pg.focus('#a'); await pg.waitForTimeout(60);
  R.openOnA = await open();
  await pg.focus('#b'); await pg.waitForTimeout(40);
  R.openOnB_early = await open();
  await pg.waitForTimeout(220);                 // past the old field's timer
  R.openOnB_late = await open();
  R.revertedAfterHop = await pg.evaluate(() => window.REVERTED.slice());

  // THE BEHAVIOUR THAT MUST SURVIVE: leaving for something that is not a
  // picker still closes, and still puts a half-typed search back
  await pg.fill('#b', 'half typed');
  await pg.focus('#plain');
  await pg.waitForTimeout(260);
  R.openAfterLeaving = await open();
  R.revertedAfterLeaving = await pg.evaluate(() => window.REVERTED.slice());

  // and clicking a row still chooses
  await pg.focus('#a'); await pg.waitForTimeout(80);
  await pg.click('.lov-r:nth-child(2)');
  await pg.waitForTimeout(80);
  R.picked = await pg.inputValue('#a');
  R.openAfterPick = await open();

  await br.close();
  console.log(JSON.stringify(R));
})();
JS;
file_put_contents($work . '/probe.js', $probe);
$raw = shell_exec('cd ' . escapeshellarg(__DIR__) . ' && node ' . escapeshellarg($work . '/probe.js')
                  . ' ' . escapeshellarg($work) . ' 2>&1');
$R = json_decode((string)$raw, true);

if (!is_array($R)) { echo "  FAIL: the probe did not run:\n" . substr((string)$raw, 0, 800) . "\n"; $F++; }
else {
    ok(empty($R['errs']), 'the file runs clean: ' . json_encode($R['errs']));
    ok($R['openOnA'] === true, 'focusing a picker opens its list');
    ok($R['openOnB_early'] === true, 'moving to the next picker opens the next list');
    /* The whole bug, in one assertion. */
    ok($R['openOnB_late'] === true,
       'and it is STILL open once the first field\'s timer has fired — it was not');
    ok($R['revertedAfterHop'] === [],
       '  and hopping between pickers reverts nothing: ' . json_encode($R['revertedAfterHop']));

    ok($R['openAfterLeaving'] === false,
       'leaving for something that is not a picker still closes the list');
    ok(in_array('b', $R['revertedAfterLeaving'], true),
       '  and a half-typed search is still put back, not taken as a choice');

    ok($R['picked'] === 'beta', 'clicking a row still chooses it, got ' . json_encode($R['picked']));
    ok($R['openAfterPick'] === false, '  and closes the list');
}

echo "3. Every screen that uses the picker gets the fix\n";
$users = [];
foreach (array_merge(glob($B . '*.php'), glob($B . 'includes/*.php')) as $f)
    if (preg_match('#<script src="assets/js/lov\.js#', file_get_contents($f))) $users[] = basename($f);
sort($users);
ok(count($users) >= 4, count($users) . ' screens load lov.js: ' . implode(', ', $users));
/* A cache-busted file is the difference between shipping a fix and
   shipping a file nobody's browser will fetch. */
$stale = [];
foreach ($users as $u) {
    $src = file_get_contents(is_file($B . $u) ? $B . $u : $B . 'includes/' . $u);
    if (preg_match('#assets/js/lov\.js(\?v=\d+)?#', $src, $m) && empty($m[1])) $stale[] = $u;
}
ok($stale === [], 'each one cache-busts it, or the fix never reaches a browser: ' . json_encode($stale));

/* THE SAME TRAP, EVERYWHERE ELSE.
   Chasing lov.js turned up grid.js loaded bare on four pages and app.js
   loaded bare in layout.php — on EVERY screen in the app. A fix to either
   would have shipped to the server and never reached a single browser
   that had already cached it. That is not a theoretical risk: it is
   exactly the bug layout.php already carries a comment about, from the
   time app.css did it. So the rule is checked for every local asset, not
   just the one I happened to be holding. */
$bare = [];
foreach (array_merge(glob($B . '*.php'), glob($B . 'includes/*.php')) as $f)
    if (preg_match_all('~(?:src|href)="(assets/(?:js|css)/[a-z_]+\.(?:js|css))"~', file_get_contents($f), $mm))
        foreach ($mm[1] as $hit) $bare[] = basename($f) . ' -> ' . $hit;
ok($bare === [], 'no local asset is linked without a ?v= anywhere: ' . json_encode($bare));

echo "\n$P passed, $F failed\n";
exit($F ? 1 : 0);
