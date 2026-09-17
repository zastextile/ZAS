<?php
/* THE LINK STICKS, AND THE CROSS IS HOW YOU DROP IT.
 *
 * The bug: the product cell and the size cell disagreed. Rewording a product
 * kept its link; rewording a size silently dropped it — and the size link is
 * the one thing production needs to work out pieces-per-set. So a buyer's own
 * wording typed over "Double" quietly unlinked the line, and nothing said so.
 *
 * Both cells now behave identically, and this RUNS their real JavaScript to
 * prove it rather than reading the file for strings. */

$B  = __DIR__ . '/app_src/public_html/';
$pf = file_get_contents($B . 'proforma.php');

$P = 0; $F = 0;
function ok($c, $m) { global $P, $F; if ($c) { $P++; } else { $F++; echo "  FAIL: $m\n"; } }
function flat(string $s): string { return preg_replace('/\s+/', ' ', $s); }

/* ---------- lift the three functions that decide a link ---------- */
function grab(string $src, string $needle): string {
    $i = strpos($src, $needle);
    if ($i === false) { fwrite(STDERR, "missing: $needle\n"); exit(1); }
    $a = strpos($src, '{', $i); $d = 0;
    for ($j = $a; $j < strlen($src); $j++) {
        if ($src[$j] === '{') $d++;
        elseif ($src[$j] === '}') { $d--; if ($d === 0) return substr($src, $i, $j - $i + 1) . ';'; }
    }
    exit(1);
}
$js = grab($pf, 'function cutBtn(')
    . grab($pf, 'function paintLink(')
    . grab($pf, 'function relink(')
    . grab($pf, 'window.pfSize = function(box)')
    . grab($pf, 'window.pfUnlink = function(btn)')
    . grab($pf, 'window.pfUnlinkSize = function(btn)');
$js = preg_replace('/<\?=[\s\S]*?\?>/', 'null', $js);
file_put_contents(__DIR__ . '/.zl.js', $js);

$harness = <<<'JS'
const fs = require('fs');
/* a row with the three things that matter: the typed text and the two links */
function mkRow(){
  const cells = {
    '.pid':   {value:'0'},
    '.sid':   {value:'0'},
    '[data-c="name"]': {value:''},
    '[data-c="size"]': {value:''},
    '.linkchip:not(.szchip)': {innerHTML:''},
    '.szchip': {innerHTML:''}
  };
  /* EVERY REAL ELEMENT HAS classList. pfSize() marks its own row when the
     size is required and missing, so a stub without one made the shipped code
     look broken when only the stub was thin. Recorded rather than guarded in
     the page — shipping `if (el.classList)` to satisfy a test would be the
     tail wagging the dog. */
  const marks = new Set();
  const tr = {
    querySelector(sel){ return cells[sel] || null; },
    classList: {
      toggle(c, on){ if (on) marks.add(c); else marks.delete(c); },
      add(c){ marks.add(c); }, remove(c){ marks.delete(c); },
      contains(c){ return marks.has(c); }
    },
    _marks: marks
  };
  for (const k in cells) cells[k].closest = () => tr;
  tr.cells = cells;
  return tr;
}
const norm = s => String(s == null ? '' : s).trim().toLowerCase().replace(/\s+/g,' ');
global.LOV = { norm, esc: s => String(s) };
const MASTER = [
  {id:5, name:'Quilted Waterproof Mattress Protector', unit:'Pc',
   sizes:[{id:91,label:'90'},{id:92,label:'135'},{id:93,label:'150'}]},
  {id:6, name:'Pillow', unit:'Pair', sizes:[{id:95,label:'45x70'}]}
];
global.byId   = id => MASTER.find(p => p.id === +id) || null;
global.byName = v  => MASTER.find(p => norm(p.name) === norm(v)) || null;
global.window = global;
global.document = {};
/* relink() also rebuilds the size list, because changing the product changes
   which sizes exist. Stubbed here and counted, so the call cannot be dropped. */
let sizeRowCalls = 0;
global.pfSizeRow = () => { sizeRowCalls++; };
eval(fs.readFileSync(process.argv[2],'utf8'));

const out = {};
const tr = mkRow();
const nameBox = tr.cells['[data-c="name"]'];
const sizeBox = tr.cells['[data-c="size"]'];
const pid = tr.cells['.pid'], sid = tr.cells['.sid'];
const pchip = tr.cells['.linkchip:not(.szchip)'], schip = tr.cells['.szchip'];

/* ---- link the product by typing its exact name ---- */
nameBox.value = 'Quilted Waterproof Mattress Protector';
relink(tr, nameBox.value);
out.pidAfterType = pid.value;

/* ---- link the size by typing an exact size ---- */
sizeBox.value = '135';
pfSize(sizeBox);
out.sidAfterType = sid.value;
out.sizeChipExact = schip.innerHTML;

/* ---- NOW REWORD BOTH, the way you would for printing ---- */
nameBox.value = 'Quilted Waterproof Mattress';
relink(tr, nameBox.value);
out.pidAfterReword = pid.value;
out.prodChipReword = pchip.innerHTML;

sizeBox.value = '135 cm x 190 cm';
pfSize(sizeBox);
out.sidAfterReword = sid.value;
out.sizeChipReword = schip.innerHTML;

/* ---- the red cross drops the link and KEEPS the wording ---- */
pfUnlinkSize({ closest: () => tr });
out.sidAfterCut  = sid.value;
out.textAfterCut = sizeBox.value;
out.sizeChipCut  = schip.innerHTML;

pfUnlink({ closest: () => tr });
out.pidAfterCut  = pid.value;
out.nameAfterCut = nameBox.value;

/* ---- typing a real size again re-links ---- */
pid.value = '5';
sizeBox.value = '150';
pfSize(sizeBox);
out.sidRelink = sid.value;

/* ---- emptying the box drops it ---- */
sizeBox.value = '';
pfSize(sizeBox);
out.sidEmpty = sid.value;
out.sizeChipEmpty = schip.innerHTML;

/* ---- a link to a size that is NOT this product's is dead, not "linked" ---- */
sid.value = '95';                 // a size belonging to Pillow, not to this product
sizeBox.value = 'anything';
pfSize(sizeBox);
out.sidForeign = sid.value;
out.chipForeign = schip.innerHTML;

out.sizeRowCalls = sizeRowCalls;
console.log(JSON.stringify(out));
JS;
file_put_contents(__DIR__ . '/.zl_harness.js', $harness);
$raw = shell_exec('node ' . escapeshellarg(__DIR__ . '/.zl_harness.js') . ' '
                . escapeshellarg(__DIR__ . '/.zl.js') . ' 2>&1');
$r = json_decode(trim((string)$raw), true);

echo "1. Typing an exact name links it\n";
if (!is_array($r)) { echo "  FAIL: it did not run — $raw\n"; $F++; }
else {
    ok($r['pidAfterType'] === 5 || $r['pidAfterType'] === '5', 'the product links, got ' . json_encode($r['pidAfterType']));
    ok((int)$r['sidAfterType'] === 92, 'the size links, got ' . json_encode($r['sidAfterType']));
    ok(str_contains($r['sizeChipExact'], '&#10003;'), 'and the chip says so');
    ok(!str_contains($r['sizeChipExact'], 'printing your wording'), '  without claiming it was reworded');

    echo "2. REWORDING KEEPS BOTH LINKS — this is the bug you found\n";
    ok((int)$r['pidAfterReword'] === 5, 'the product link survives a reword');
    ok((int)$r['sidAfterReword'] === 92,
       'THE SIZE LINK SURVIVES TOO, got ' . json_encode($r['sidAfterReword']));
    /* the sentence became a mark when the grid was tightened — the words moved
       into the tooltip, and the STATE is what matters, not its length */
    ok(str_contains($r['prodChipReword'], 'class="rw"'), 'the product chip shows the reword state');
    ok(str_contains($r['sizeChipReword'], 'class="rw"'), '  and so does the size chip');
    ok(str_contains($r['sizeChipReword'], 'not the master size'),
       '  and the size tooltip says SIZE, not name: ' . $r['sizeChipReword']);
    ok(str_contains($r['prodChipReword'], 'not the master name'), '  while the product one says name');
    ok(str_contains($r['sizeChipReword'], '135'), '  while still naming the real size it is linked to');

    echo "3. The red cross drops the link and keeps the text\n";
    ok((int)$r['sidAfterCut'] === 0, 'the size link is gone');
    ok($r['textAfterCut'] === '135 cm x 190 cm', 'THE WORDING IS UNTOUCHED, got ' . json_encode($r['textAfterCut']));
    ok((int)$r['pidAfterCut'] === 0, 'the product link is gone');
    ok($r['nameAfterCut'] === 'Quilted Waterproof Mattress', '  and its wording is untouched too');
    ok(str_contains($r['sizeChipCut'], 'not a size of this product'), 'the chip stops claiming a link');

    echo "4. Typing a real one again re-links, and emptying drops it\n";
    ok((int)$r['sidRelink'] === 93, 'typing 150 links to 150, got ' . json_encode($r['sidRelink']));
    ok((int)$r['sidEmpty'] === 0, 'an empty box means no link');
    ok(str_contains($r['sizeChipEmpty'], 'no size'), '  and says "no size", not "not a size"');

    echo "5. Changing the product rebuilds the size list\n";
    ok($r['sizeRowCalls'] >= 3, 'the size cell is refreshed whenever the product link moves, got '
       . json_encode($r['sizeRowCalls']));

    echo "6. A link to another product's size is dead, and is not called linked\n";
    ok((int)$r['sidForeign'] === 0, 'the stale link is dropped, got ' . json_encode($r['sidForeign']));
    ok(!str_contains($r['chipForeign'], '&#10003;'), '  and never shows a tick for it');
}
@unlink(__DIR__ . '/.zl.js'); @unlink(__DIR__ . '/.zl_harness.js');

echo "7. Both cells offer the cross, and it is the same button\n";
ok(substr_count($pf, "cutBtn('pfUnlink')") === 3, 'every product state offers it, got '
   . substr_count($pf, "cutBtn('pfUnlink')"));
ok(substr_count($pf, "cutBtn('pfUnlinkSize')") === 2, 'and both linked size states, got '
   . substr_count($pf, "cutBtn('pfUnlinkSize')"));
ok(str_contains($pf, 'ONE BUTTON, TWO CELLS'), 'it is deliberately shared');
ok(str_contains($pf, 'Drop the link') && str_contains($pf, 'your typed wording is kept'),
   'and the tooltip says exactly what it does');
/* the reword mark is a symbol at this density; every one of them must still
   explain itself, or it is just a glyph nobody can interpret */
ok(substr_count($pf, '&#9998;') === 3, 'every reword state is a mark, got ' . substr_count($pf, '&#9998;'));
ok(substr_count($pf, 'title="Linked. What prints is your wording') === 3,
   '  and every mark carries the words in its tooltip');
ok(!str_contains($pf, 'printing your wording</span>'), '  no long sentence widens a row any more');
ok(str_contains(flat($pf), 'the two cells disagreed, and the size one was wrong'),
   'the bug is written down so it is not reintroduced');

echo "8. Density: the grid is sized like a spreadsheet, not a form\n";
ok(str_contains($pf, 'padding:6px 8px') && !str_contains($pf, 'padding:10px 12px'),
   'the inputs are tightened');
ok(!str_contains($pf, 'style="padding:5px"'), 'and so are the cells');
ok(str_contains($pf, 'padding:7px 13px'), 'the buttons too');
ok(str_contains(flat($pf), 'sized like a spreadsheet'), 'and why is recorded');

echo "9. Nothing about SAVING changed\n";
ok(str_contains($pf, 'pf_size_id((int)($pids[$i]??0),$szids[$i]??0,'),
   'the server still resolves the id the same way');
ok(str_contains($pf, "\$szids=\$_POST['i_size_id'] ?? [];"), 'and still reads the posted link');
ok(str_contains($pf, 'name="i_size[]"'), 'the typed size is still posted and still prints');

echo "\n$P passed, $F failed\n";
exit($F ? 1 : 0);
