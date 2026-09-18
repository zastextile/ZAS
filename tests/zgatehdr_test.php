<?php
/* THE GATE HEADER — measured, not guessed.
 *
 * The owner sent a screenshot of a gate-out pass where the header pushed the
 * first item line most of the way down the screen. The fields themselves were
 * already compact; what was tall was the GREY SENTENCE under six of them.
 * A grid row is as tall as its tallest cell, so six one-and-two-line hints
 * were adding their height to every row of the header, on every pass.
 *
 * They are not deleted — they move out of the layout and appear over it when
 * the field they belong to has focus, which is the only moment they are worth
 * reading. And "+ Add new party" gave up its whole grid cell for a button used
 * once a week; it sits on the party field's own label line now.
 *
 * Rendered with the real CSS and measured in a browser at three widths.
 */

$B = __DIR__ . '/app_src/public_html/';
$P = 0; $F = 0;
function ok($c, $m) { global $P, $F; if ($c) { $P++; } else { $F++; echo "  FAIL: $m\n"; } }

$gate = file_get_contents($B . 'inv_gate.php');
$css  = file_get_contents($B . 'assets/css/zskin.css');
$work = __DIR__ . '/.zghdr';
@mkdir($work, 0777, true);

echo "1. The button gave up its cell\n";
$code = preg_replace('!<\?php /\*.*?\*/ \?>!s', '', $gate);
$code = preg_replace('!/\*.*?\*/!s', '', $code);
ok(substr_count($code, 'id="pNewWrap"') === 1, 'there is exactly one pNewWrap, still');
ok(str_contains($code, 'id="plabel"') && strpos($code, 'id="pNewWrap"') > strpos($code, 'id="plabel"')
   && strpos($code, 'id="pNewWrap"') < strpos($code, 'name="party_id"'),
   'it now sits inside the party field\'s label, not in a cell of its own');
/* The script only ever sets style.display to '' or 'none'. A span's default
   display is inline, so '' restores it correctly — which is the whole reason
   the element could be moved without touching a line of that script. */
ok(str_contains($code, "<span id=\"pNewWrap\""), 'it is a span, so style.display=\'\' restores it inline');
ok(str_contains($code, "pNewWrap.style.display  = (trade && CANMAKE) ? '' : 'none'"),
   '  and the script that shows and hides it is untouched');
ok(str_contains($gate, 'title="Adds it to the master'), 'the sentence under it became the button\'s tooltip');
ok(!str_contains($code, 'Not on the list?'), '  and the label it needed is gone with the cell');

echo "2. The hints are off the layout, not deleted\n";
ok(str_contains($css, '.zskin .ig-grid>div>p{'), 'the hints are positioned out of flow');
ok(str_contains($css, 'position:absolute !important'), '  absolutely, so they take no row height');
ok(str_contains($css, '.zskin .ig-grid>div:focus-within>p{opacity:1;visibility:visible}'),
   'and they come back when the field is focused');
ok(str_contains($css, '.zskin .ig-grid>div{position:relative}'),
   '  anchored to their own field, not to the page');
/* Hidden with visibility, not display:none — a screen reader still reaches
   it, and it can be animated. */
ok(str_contains($css, 'visibility:hidden'), 'hidden by visibility, so it is still in the document');

/* ------------------------------------------------------------------ */
echo "3. Rendered and measured\n";

$a = strpos($gate, '<div class="ig-grid">');
$b = strpos($gate, "\n    </div>", $a);
ok($a !== false && $b !== false, 'the header block is where it was');
$frag = substr($gate, $a, $b - $a) . "\n</div>";

$tpl = '<?php
function e($s){ return htmlspecialchars((string)$s, ENT_QUOTES); }
$dir = "out";
$curType = "sale";
$TYPES = ["sale" => ["label"=>"Sale / Export","note"=>"Finished goods going to a customer","own"=>"own"],
          "sample" => ["label"=>"Sample out","note"=>"A sample leaving the mill","own"=>"own"]];
$parties = [["id"=>1,"name"=>"ABRAR AHMED","party_type"=>"customer"]];
$contracts = [["id"=>3,"contract_no"=>"SC-2609-0001","party_id"=>1,"party_name"=>"ABRAR AHMED",
               "pname"=>"ABRAR AHMED","contract_type"=>"sale","status"=>"active"]];
$proformas = [["id"=>7,"pi_no"=>"PI-2609-0142","customer_name"=>"Home Linen GmbH"]];
$locations = [["id"=>1,"name"=>"Main Store"],["id"=>2,"name"=>"Finished Goods"]];
$defLoc = 1;
$CTLBL = "Contract — optional";
$D = ["gate_no"=>"","gate_date"=>"2026-09-18","gate_time"=>"13:42","party_id"=>1,
      "contract_id"=>3,"vehicle_no"=>"","challan_no"=>"","remarks"=>"","status"=>"draft",
      "gst_applies"=>0,"gst_pct"=>18,"proforma_id"=>0,"location_id"=>1,"party_text"=>""];
?>' . $frag;
file_put_contents($work . '/frag.php', $tpl);
$html = (string)shell_exec('php ' . escapeshellarg($work . '/frag.php') . ' 2>&1');
ok(!str_contains($html, 'Fatal') && !str_contains($html, 'Warning'),
   'the header renders clean: ' . substr(trim($html), 0, 160));
ok(substr_count($html, '<p ') >= 4, 'it really does carry several hint paragraphs, got ' . substr_count($html, '<p '));

preg_match('/<style>(.*?)<\/style>/s', $gate, $sm);
$pageCss = $sm[1] ?? '';
$head = '<!doctype html><html><head><meta charset="utf-8"><style>*{box-sizing:border-box}'
      . 'body{margin:0;font-family:system-ui,Arial,sans-serif}</style>'
      . '<style>' . $pageCss . '</style><style>' . $css . '</style></head><body>';
file_put_contents($work . '/plain.html', $head . '<div id="w">' . $html . '</div></body></html>');
file_put_contents($work . '/skin.html', $head . '<div class="zskin"><div id="w">' . $html . '</div></div></body></html>');

$probe = <<<'JS'
const { chromium } = require('playwright');
(async () => {
  const br = await chromium.launch({ executablePath: '/opt/pw-browsers/chromium' });
  const out = {};
  for (const which of ['plain', 'skin']) {
    out[which] = {};
    for (const w of [1280, 1000, 560]) {
      const pg = await br.newPage({ viewport: { width: w, height: 900 } });
      await pg.goto('file://' + process.argv[2] + '/' + which + '.html');
      await pg.waitForTimeout(120);
      out[which][w] = await pg.evaluate(() => {
        const g = document.querySelector('.ig-grid');
        const ps = [...g.querySelectorAll(':scope > div > p')];
        const inp = g.querySelector('.ig-inp');
        return {
          h: Math.round(g.getBoundingClientRect().height),
          rows: new Set([...g.children].map(c => Math.round(c.getBoundingClientRect().top))).size,
          inputH: Math.round(inp.getBoundingClientRect().height),
          cells: g.children.length,
          hintsVisible: ps.filter(p => getComputedStyle(p).visibility !== 'hidden').length,
          hintsPresent: ps.length,
          newBtnInLabel: !!document.querySelector('#plabel #pNewWrap'),
          overflow: document.documentElement.scrollWidth > w + 1
        };
      });
      await pg.close();
    }
  }
  // focus a field that has a hint and confirm the hint appears without moving anything
  const pg = await br.newPage({ viewport: { width: 1280, height: 900 } });
  await pg.goto('file://' + process.argv[2] + '/skin.html');
  await pg.waitForTimeout(100);
  out.focus = await pg.evaluate(() => {
    const g = document.querySelector('.ig-grid');
    const before = Math.round(g.getBoundingClientRect().height);
    const sel = document.getElementById('ttype');
    const p = sel.parentElement.querySelector('p');
    p.textContent = 'Finished goods going to a customer';
    sel.focus();
    const vis = getComputedStyle(p).visibility;
    const after = Math.round(g.getBoundingClientRect().height);
    return { before, after, vis };
  });
  await pg.waitForTimeout(400);           // let the fade finish before reading it
  out.focus.op = await pg.evaluate(() => {
    const p = document.getElementById('ttype').parentElement.querySelector('p');
    return getComputedStyle(p).opacity;
  });
  await br.close();
  console.log(JSON.stringify(out));
})();
JS;
file_put_contents($work . '/probe.js', $probe);
$raw = shell_exec('cd ' . escapeshellarg(__DIR__) . ' && node ' . escapeshellarg($work . '/probe.js')
                  . ' ' . escapeshellarg($work) . ' 2>&1');
$M = json_decode((string)$raw, true);

if (!is_array($M)) { echo "  FAIL: the probe did not run:\n" . substr((string)$raw, 0, 900) . "\n"; $F++; }
else {
    $p1280 = $M['plain'][1280]; $s1280 = $M['skin'][1280];

    ok($p1280['hintsPresent'] >= 4, 'the hints are in the markup, got ' . $p1280['hintsPresent']);
    ok($p1280['hintsVisible'] === $p1280['hintsPresent'], 'unskinned, every hint is visible and taking space');
    ok($s1280['hintsVisible'] === 0, 'skinned, none of them is taking space until asked for');

    /* THE NUMBER. Reported rather than asserted against a figure I made up. */
    echo "   header height at 1280: plain " . $p1280['h'] . "px -> skinned " . $s1280['h'] . "px\n";
    ok($s1280['h'] < $p1280['h'],
       'the skinned header is shorter than the plain one, got ' . $s1280['h'] . ' vs ' . $p1280['h']);
    /* TWO ROWS OF FIELDS. A row is a 13px label, a 28px field and the 8px
       gap — about 49px, so two rows is a little over 100. Asserted as the
       ROW COUNT as well as the height, because the height alone would pass
       for the wrong reason if a field ever shrank. */
    ok($s1280['rows'] === 2, 'the fields sit in two rows, got ' . $s1280['rows']);
    ok($s1280['h'] <= 120,
       'and that is under 120px, got ' . $s1280['h'] . 'px');
    ok($s1280['inputH'] <= 30, 'the fields are 30px or less, got ' . $s1280['inputH'] . 'px');
    ok($s1280['newBtnInLabel'] === true, 'the + new button sits inside the party label');

    /* One fewer cell than before, because the button gave one back. */
    echo "   header cells: " . $s1280['cells'] . "\n";

    foreach ([1280, 1000, 560] as $w) {
        ok($M['skin'][$w]['overflow'] === false, "no sideways scroll at {$w}px");
        ok($M['skin'][$w]['h'] < $M['plain'][$w]['h'],
           "shorter at {$w}px too: " . $M['skin'][$w]['h'] . ' vs ' . $M['plain'][$w]['h']);
    }
    ok($M['skin'][560]['rows'] > $M['skin'][1280]['rows'],
       'a phone still stacks them, got ' . $M['skin'][560]['rows'] . ' rows');

    /* THE POINT OF THE WHOLE CHANGE: the hint comes back, and NOTHING MOVES
       when it does. A hint that pushed the grid open on focus would be worse
       than one that was always there. */
    ok($M['focus']['vis'] === 'visible', 'focusing a field brings its hint back, got ' . $M['focus']['vis']);
    /* Read AFTER the fade, not during it — the first run of this test caught
       the transition half-way and reported 0.38. */
    ok($M['focus']['op'] === '1', '  fully, not faded out, got ' . $M['focus']['op']);
    ok($M['focus']['before'] === $M['focus']['after'],
       '  AND THE HEADER DOES NOT MOVE WHEN IT APPEARS: '
       . $M['focus']['before'] . ' -> ' . $M['focus']['after']);
}

echo "\n$P passed, $F failed\n";
exit($F ? 1 : 0);
