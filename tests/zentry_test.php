<?php
/* THE FAST DAILY ENTRY.
 *
 * 200-300 lines a day, so the screen is built for typing. What is tested here
 * is not the typing — it is the rules underneath it, because a fast screen that
 * books a wage twice is worse than a slow one:
 *
 *   THE CEILING IS SHARED     three workers x 200 against 420 left is 600
 *   STAGED LINES STILL COUNT  300 now + 300 after staging must not slip past
 *   THE LIST CANNOT OVER-OFFER it is built from the same zp_remaining() the save uses
 *   LEARNED, NOT FIXED        habit reorders the list, it never restricts it
 *   THE BROWSER DECIDES NOTHING the server re-checks and re-prices every line
 */
$B  = __DIR__ . '/app_src/public_html/';
$zp = file_get_contents($B . 'includes/zprod.php');
$pe = file_get_contents($B . 'production_entry.php');

$P = 0; $F = 0;
function ok($c, $m) { global $P, $F; if ($c) { $P++; } else { $F++; echo "  FAIL: $m\n"; } }
function flat(string $s): string { return preg_replace('/\s+/', ' ', $s); }
function lift(string $src, string $fn): string {
    $i = strpos($src, "function $fn(");
    if ($i === false) { fwrite(STDERR, "missing $fn\n"); exit(1); }
    $a = strpos($src, '{', $i); $d = 0;
    for ($j = $a; $j < strlen($src); $j++) {
        if ($src[$j] === '{') $d++;
        elseif ($src[$j] === '}') { $d--; if ($d === 0) return substr($src, $i, $j - $i + 1); }
    }
    exit(1);
}
/* the picker's own JavaScript, lifted out of the shipped page and RUN in node */
function js(string $body): string {
    preg_match('/<script>\n([\s\S]*?)<\/script>/', $GLOBALS['pe'], $m);
    $src = preg_replace('/<\?=[\s\S]*?\?>/', 'null', $m[1]);
    return $src;
}

echo "1. The work index is built from the same numbers the save enforces\n";
$wi = lift($zp, 'zp_work_index');
ok(str_contains($wi, 'zp_remaining($l, $partId, $opId'), 'it asks zp_remaining for what is left');
/* finished work is now MARKED rather than dropped, so the screen can tell
   "that is already done" apart from "that does not exist" — but it is still
   never offered as something to book against. */
ok(str_contains($wi, '$done = $left <= 0.0001;'), 'finished work is marked');
ok(str_contains($wi, "'done' => \$done ? 1 : 0,"), '  and the flag rides on the row');
ok(str_contains($pe, 'return !w.done && hit(w.hay, t);'), '  and it is never offered');
ok(substr_count($pe, '!w.done &&') === 2, '  on both tabs, got ' . substr_count($pe, '!w.done &&'));
ok(str_contains($wi, 'zp_rate_for($opId, (float)$o[\'rate\'], $rates, $szRates, $szId)'),
   'the rate shown is the one this ORDER pays, amendments included');
/* THE LIST MUST QUOTE WHAT THE SAVE WILL PAY. Showing the standard while
   zp_book() freezes a size rate would only ever surface in a wage dispute. */
ok(str_contains($wi, '$szRates = zp_op_rate_map($pid);') && str_contains($wi, '$szId    = zp_line_size_id($l);'),
   '  and the size rate too, so the list cannot quote one number and pay another');
ok(str_contains($wi, "if (zp_size_problem(\$l) !== '') continue;"),
   'a line whose size cannot be resolved is never offered');
ok(str_contains($wi, "'hay'"), 'every row carries one folded search string');
ok(str_contains($wi, 'mb_strtolower'), '  lower-cased on the server, so the browser need not');
foreach (['pi_no', 'customer_name', 'product_name', 'part_name', 'operation_name'] as $f)
    ok(str_contains($wi, $f), "  the search text includes $f");

echo "2. Learned defaults are learned, weighted and honest\n";
$hb = lift($zp, 'zp_worker_habit');
ok(str_contains($hb, "status='active'"), 'cancelled entries never teach the screen');
ok(str_contains(flat($zp), 'A mistake that was corrected must not teach'), '  and the reason is written down');
ok(str_contains($hb, 'GROUP BY worker_id, op_id'), 'one grouped query, not one per worker');
ok(str_contains($hb, '2 * (int)$r[\'recent\']'), 'recent work is weighted heavier');
ok(str_contains(flat($zp), 'should rank as a stitcher now'), '  and why is stated');
ok(str_contains($hb, "'byWorker'") && str_contains($hb, "'byOp'"), 'both directions are indexed');
ok(str_contains(flat($zp), 'Nothing is fixed to anybody'), 'it reorders, it never restricts');

echo "3. The rules live on the server, and the page still posts what it always did\n";
ok(str_contains($pe, "\$r = zp_book(\$date, \$rows, \$userId);"), 'the save handler is unchanged');
ok(str_contains($pe, "'item_id'   => \$_POST['r_item'][\$i]"), 'and reads the same fields');
/* count CALLS, not mentions — the picker's header comment names zp_book() to
   say the server re-checks every line, and that is documentation, not a route. */
$peCode = preg_replace('!/\*.*?\*/!s', ' ', $pe);
ok(substr_count($peCode, 'zp_book(') === 1,
   'there is exactly one way in, got ' . substr_count($peCode, 'zp_book('));
$book = lift($zp, 'zp_book');
ok(str_contains($book, 'zp_remaining('), 'the server re-checks what is left');
ok(!str_contains($book, "\$r['rate']"), 'and never takes a rate from the browser');
ok(str_contains($pe, "name=\"r_item\"") || str_contains($pe, "'r_item'"), 'the posted names are intact');

echo "4. The picker's own JavaScript — actually executed\n";
$src = js($pe);
file_put_contents(__DIR__ . '/.ze.js', $src);
$harness = <<<'JS'
const fs=require('fs');
let calls=[], el={};
function mkEl(){ return {innerHTML:'',textContent:'',hidden:false,value:'',dataset:{},
  style:{}, classList:{toggle(){},add(){},remove(){}},
  addEventListener(){}, setAttribute(){}, focus(){}, select(){}, scrollIntoView(){},
  querySelector(){return null}, querySelectorAll(){return []},
  appendChild(){}, insertBefore(){}, remove(){}, closest(){return null},
  parentNode:{insertBefore(){}}, dispatchEvent(){}, requestSubmit(){calls.push('submit')} }; }
global.document={ getElementById(id){ return el[id] || (el[id]=mkEl()); },
  querySelectorAll(){return []}, querySelector(){return null},
  createElement(){return mkEl()}, addEventListener(){} };
global.window=global; global.alert=function(){}; global.prompt=function(){return null};
/* A REAL WINDOW ALWAYS HAS THESE. The picker registers scroll/resize handlers
   so its list can follow the input it is anchored to; this stub had no
   addEventListener, so the page looked broken when only the stub was.
   Recorded here rather than guarded in the page — shipping `if
   (window.addEventListener)` to satisfy a test would be the tail wagging. */
global.addEventListener=function(){}; global.removeEventListener=function(){};
global.innerWidth=1280; global.innerHeight=900;
let src=fs.readFileSync(process.argv[2],'utf8');
/* feed it real data in place of the PHP holes */
src=src.replace('var WORK    = null;', `var WORK = ${JSON.stringify([
 {k:'1:700',item:1,op:700,part:10,pi:'PI-1042',cust:'Ideal Home',prod:'Bed in Bag',size:'Double',
  pn:'Pillow Case',on:'Overlock',st:'Stitching',rate:5,left:420,hay:'pi-1042 ideal home bed in bag double pillow case overlock stitching'},
 {k:'2:701',item:2,op:701,part:11,pi:'PI-1051',cust:'Nordic',prod:'Bed sheet',size:'Single',
  pn:'Flat Sheet',on:'Singer',st:'Stitching',rate:4,left:900,hay:'pi-1051 nordic bed sheet single flat sheet singer stitching'}
])};`);
src=src.replace('var WORKERS = null;', `var WORKERS = ${JSON.stringify([
 {id:1,code:'W001',name:'Asif',dept:'Stitching',hay:'w001 asif stitching'},
 {id:2,code:'W002',name:'Rubina',dept:'Stitching',hay:'w002 rubina stitching'},
 {id:3,code:'W003',name:'Kashif',dept:'Cutting',hay:'w003 kashif cutting'}
])};`);
src=src.replace('var HABIT   = null;', 'var HABIT = {byWorker:{1:{700:90},3:{999:40}}, byOp:{700:{1:90}}};');
src=src.replace('var MONEY   = null;', 'var MONEY = true;');
eval(src);
/* --- drive it --- */
const W=global.window;
let out={};
W.zeTab('A');
el.zeQ.value='';
W.zePick(0);                       // the Overlock line, 420 left
W.zeSugSet(0, 1);                  // Asif
W.zeQty(0,'200'); W.zeAddRow();
W.zeSugSet(1, 2); W.zeQty(1,'200');
out.twoAt200 = el.zeSave.disabled;                 // 400 of 420 -> fine
W.zeAddRow(); W.zeSugSet(2, 3); W.zeQty(2,'200');
out.threeAt200 = el.zeSave.disabled;               // 600 of 420 -> refused
out.warnShown  = /only 420/.test(el.zeWarn ? el.zeWarn.innerHTML : '');
W.zeQty(2,'20');
out.backUnder  = el.zeSave.disabled;               // 420 of 420 -> exactly fine
out.count      = String(el.rowCount.textContent);
out.total      = String(el.sheetTotal.textContent);
/* stage it, then try to book more against the same operation */
W.zeStash();
out.staged     = String(el.rowCount.textContent);
W.zePick(0);
W.zeSugSet(0, 1); W.zeQty(0,'10');
out.afterStage = el.zeSave.disabled;               // 420 already staged -> refused
console.log(JSON.stringify(out));
JS;
file_put_contents(__DIR__ . '/.ze_harness.js', $harness);
$raw = shell_exec('node ' . escapeshellarg(__DIR__ . '/.ze_harness.js') . ' ' . escapeshellarg(__DIR__ . '/.ze.js') . ' 2>&1');
$r = json_decode(trim((string)$raw), true);
if (!is_array($r)) { echo "  FAIL: the picker did not run — $raw\n"; $F++; }
else {
    ok($r['twoAt200']  === false, '400 of 420 is allowed');
    ok($r['threeAt200'] === true, 'THE CEILING IS SHARED: 600 of 420 is refused, not passed per line');
    ok($r['warnShown'] === true,  '  and it says how many are really left');
    ok($r['backUnder'] === false, 'exactly 420 of 420 is allowed');
    ok($r['count'] === '3',       'the sheet counts three lines, got ' . $r['count']);
    ok($r['total'] === '2,100.00','and 420 x 5.00 = 2,100.00, got ' . $r['total']);
    ok($r['staged'] === '3',      'staging keeps them on the sheet, got ' . $r['staged']);
    ok($r['afterStage'] === true, 'STAGED LINES STILL COUNT: 10 more against a full 420 is refused');
}
@unlink(__DIR__ . '/.ze.js'); @unlink(__DIR__ . '/.ze_harness.js');

echo "5. Nothing is lost on the way to Save\n";
ok(str_contains($pe, 'if (t.n) zeStash();'), 'rows still on screen are staged before posting');
ok(str_contains(flat($pe), 'Forgetting the rows still on screen is the obvious way to lose'),
   '  and the reason is recorded');
ok(str_contains($pe, "querySelectorAll('.zePost')"), 'a second submit cannot double the hidden fields');

echo "6. Typed text can never become markup\n";
ok(str_contains($pe, "var M1 = '\\u0001', M2 = '\\u0002';"), 'the highlight markers are non-printing');
ok(str_contains($pe, 'var out = esc(text);'), 'and the text is escaped BEFORE it is marked');
ok(str_contains(flat($pe), 'is shown as text and'), '  the reason is written down');

echo "7. A long list stays fast\n";
/* the cap moved into zePaint() when the two lists were merged into one
   renderer — the property is the same, and now BOTH lists get it. */
ok(str_contains($pe, 'var show = list.slice(0, cap);'), 'a long list is drawn short');
ok(str_contains($pe, 'zePaint(box, list.map(function(w, i){'), '  the top list uses the shared painter');
ok(str_contains($pe, '}), t, cur, 60);'), '  and asks for 60 rows');
ok(str_contains($pe, 'zePaint(pop, list,'), '  and so does the in-row list');
ok(str_contains($pe, "\$('zeCnt').textContent = list.length"), '  but the real count is always shown');
ok(str_contains(flat($pe), 'Painting 1,200 rows on every keystroke'), '  and why');

echo "8. The two tabs, and the keyboard\n";
ok(str_contains($pe, 'zeTab(\'A\')') && str_contains($pe, 'zeTab(\'B\')'), 'both tabs exist');
ok(str_contains($pe, 'By operation') && str_contains($pe, 'By worker'), 'and are named for what they do');
ok(str_contains($pe, "ev.key === 's' || ev.key === 'S'") && str_contains($pe, 'ev.ctrlKey || ev.metaKey'),
   'Ctrl+S saves the whole sheet');
ok(str_contains($pe, "if (e.key === 'Enter') { e.preventDefault(); if (list[cur]) zePick(cur); }"),
   'Enter chooses from the list');
ok(str_contains($pe, 'if (i === rows.length - 1) zeAddRow();'), 'Enter on a quantity starts the next line');

echo "9. No stage name decides anything on this page\n";
ok(!preg_match("/\\\$t\\['stage'\\] === '(Cutting|Manual Cutting|Stitching)'/", $pe),
   'the stage pill colour is not chosen by a hardcoded word');
ok(str_contains($pe, "zp_is_cutting((string)\$t['stage'])"), '  it follows position instead');

echo "10. Money is still a separate permission from booking\n";
ok(str_contains($pe, '$showMoney = can_see_rates();'), 'rates are behind their own permission');
ok(str_contains($pe, 'MONEY ?'), 'and the grid drops the money columns entirely when it is off');
/* THE LEAK THIS CAUGHT. The "Booked on ..." table printed Rate and Amount to
   anyone who could open the page, so an operator not allowed to see rates saw
   every rate on the floor. Both the header and the cells are now guarded. */
$booked = substr($pe, strpos($pe, 'Booked on <?='));
ok(substr_count($booked, '<?php if ($showMoney): ?>') >= 3,
   'the booked table guards its header, its cells and its total, got '
   . substr_count($booked, '<?php if ($showMoney): ?>'));
ok(!preg_match('/<th class="num" style="width:80px">Rate<\/th>\s*<th class="num" style="width:100px">Amount<\/th>\s*\n\s*<th style="width:120px">/', $pe),
   'the rate header is no longer printed unconditionally');
ok(str_contains(flat($pe), 'an operator not allowed to see rates saw every rate on the floor'),
   '  and the leak is written down so it is not reintroduced');

echo "\n$P passed, $F failed\n";
exit($F ? 1 : 0);
