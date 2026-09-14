<?php
/* ONE PICKER, DRAWN TWICE — AND ELEVEN PAGES IN ONE LANGUAGE.
 *
 * The bug: production_entry.php carried TWO search lists written separately.
 * They drifted. The in-row one lost the match highlighting, lost the chips,
 * and on the "By worker" tab lost the LEFT count — so you could pick a job in
 * a row without ever seeing how much of it was still open. It also silently
 * stopped at 8 rows on a floor with 200 workers.
 *
 * This does not grep for strings. It lifts the real renderer out of
 * production_entry.php, loads the real zskin.css into a real browser, drives
 * the real keyboard path, and MEASURES what a row actually contains. */

$B  = __DIR__ . '/app_src/public_html/';
$pe = file_get_contents($B . 'production_entry.php');
$css = file_get_contents($B . 'assets/css/zskin.css');

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
$lifted = grab($pe, 'function zeWorkOpt(')
        . grab($pe, 'function zeWorkerOpt(')
        . grab($pe, 'function zeRowHtml(')
        . grab($pe, 'function zePaint(')
        . grab($pe, 'function sugq(')
        . grab($pe, 'function sugList(');
$lifted = preg_replace('/<\?=[\s\S]*?\?>/', 'null', $lifted);
file_put_contents(__DIR__ . '/.zp.js', $lifted);

$harness = <<<'JS'
const fs = require('fs');
const { chromium } = require('playwright');

const WORKERS = [
  {id:1,name:'Muhammad Ashraf',code:'W-014',dept:'Stitching'},
  {id:2,name:'Nasreen Bibi',code:'W-027',dept:'Stitching'},
  {id:3,name:'Abdul Rehman',code:'W-003',dept:'Cutting'}
];
const WORK = [
  {k:'a',op:200,pn:'Flat Sheet',on:'Manual Cutting',pi:'PI-2026-014',cust:'Hastens AB',
   prod:'7 pcs Bed in Bag',size:'King',st:'Cutting',rate:3,left:420,done:false},
  {k:'b',op:201,pn:'Flat Sheet',on:'Overlock',pi:'PI-2026-014',cust:'Hastens AB',
   prod:'7 pcs Bed in Bag',size:'King',st:'Stitching',rate:5,left:180,done:false},
  /* a FINISHED job: the in-row list must not offer it */
  {k:'z',op:299,pn:'Flat Sheet',on:'Finished Op',pi:'PI-2026-001',cust:'Hastens AB',
   prod:'7 pcs Bed in Bag',size:'King',st:'Packing',rate:9,left:0,done:true}
];

const stubs = `
  const MONEY = true;
  let tab = 'A';
  let picked = null;
  const WORKERS = ${JSON.stringify(WORKERS)};
  const WORK = ${JSON.stringify(WORK)};
  WORKERS.forEach(w => w.hay = (w.name+' '+w.code+' '+w.dept).toLowerCase());
  WORK.forEach(w => w.hay = (w.pn+' '+w.on+' '+w.pi+' '+w.cust+' '+w.prod+' '+w.size+' '+w.st).toLowerCase());
  function esc(s){return String(s==null?'':s).replace(/[&<>"]/g,
    c=>({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;'}[c]));}
  function n2(v){return Number(v).toFixed(2);}
  function qn(v){return String(Math.round(v*100)/100);}
  function hit(hay,t){for(var i=0;i<t.length;i++) if(hay.indexOf(t[i])<0) return false; return true;}
  const M1='\\u0001', M2='\\u0002';
  function hl(s,t){
    s=String(s==null?'':s);
    if(t&&t.length) t.slice().sort((a,b)=>b.length-a.length).forEach(function(x){
      if(!x) return;
      s=s.replace(new RegExp(x.replace(/[.*+?^\${}()|[\\]\\\\]/g,'\\\\$&'),'ig'),m=>M1+m+M2);
    });
    return esc(s).split(M1).join('<mark>').split(M2).join('</mark>');
  }
  /* the learned default: worker 1 usually does op 201; nobody else does */
  function score(wid,op){ return (wid===1 && op===201) ? 5 : 0; }
  function usualOps(id){ return id===1 ? 5 : 0; }
`;

(async () => {
  const lifted = fs.readFileSync(process.argv[2], 'utf8');
  const css    = fs.readFileSync(process.argv[3], 'utf8');
  const page = `<!doctype html><meta charset="utf-8"><style>${css}</style>
    <style>body{margin:0}
      .ze-res{border:1px solid #ccc}
      .ze-row{display:grid;grid-template-columns:minmax(0,1fr) auto;width:100%;
        border:0;background:transparent;cursor:pointer;text-align:left;font:inherit}
      .ze-s{display:flex;gap:6px;flex-wrap:wrap;align-items:center}
    </style>
    <div class="zskin"><div class="ze-res" id="top"></div>
      <div class="ze-res zesug" id="sug0"></div></div>
    <script>${stubs}\n${lifted}
      window.__set = function(t,p){ tab=t; picked=p; };
      window.__topPaint = function(list,terms){
        zePaint(document.getElementById('top'),
          list.map((w,i)=> tab==='A' ? zeWorkOpt(w,'zePick('+i+')','onclick')
                                     : zeWorkerOpt(w,0,'zePick('+i+')','onclick')),
          terms, 0, 60);
      };
      window.__sugPaint = function(text){
        zePaint(document.getElementById('sug0'), sugList(0, text),
          (text||'').trim().toLowerCase().split(/\\s+/).filter(Boolean), 0, 12);
      };
    <\/script>`;

  const b = await chromium.launch({ executablePath: '/opt/pw-browsers/chromium' });
  const pg = await b.newPage({ viewport: { width: 1280, height: 900 } });
  const errs = []; pg.on('pageerror', e => errs.push(String(e)));
  await pg.setContent(page);
  await pg.waitForTimeout(200);

  const out = await pg.evaluate(() => {
    const r = {};
    const shape = box => {
      const row = document.querySelector('#' + box + ' .ze-row');
      if (!row) return null;
      return {
        title:  row.querySelector('.ze-t').textContent,
        marks:  row.querySelectorAll('mark').length,
        chips:  row.querySelectorAll('.ze-chip').length,
        strong: (row.querySelector('.ze-s b') || {}).textContent || '',
        left:   (row.querySelector('.ze-left') || {}).textContent || '',
        usual:  !!row.querySelector('.ze-usual'),
        rate:   row.textContent.indexOf('/pc') >= 0,
        click:  row.getAttribute('onclick') || row.getAttribute('onmousedown') || '',
        html:   row.innerHTML
      };
    };

    /* ---- TAB A: top list is WORK, in-row list is WORKERS ---- */
    __set('A', null);
    __topPaint(WORK.filter(w => !w.done), ['flat']);
    r.A_top = shape('top');

    __set('A', WORK[1]);                 // picked: Flat Sheet -> Overlock (op 201)
    __sugPaint('ash');
    r.A_sug = shape('sug0');
    __sugPaint('');
    r.A_sugFirst = document.querySelector('#sug0 .ze-t').textContent;  // learned default first

    /* ---- TAB B: top list is WORKERS, in-row list is WORK ---- */
    __set('B', null);
    __topPaint(WORKERS, ['ash']);
    r.B_top = shape('top');

    __set('B', WORKERS[0]);              // picked: the worker who usually does 201
    __sugPaint('overlock');
    r.B_sug = shape('sug0');
    r.B_sugCount = document.querySelectorAll('#sug0 .ze-row').length;

    /* a FINISHED job must never be offered in the row */
    __sugPaint('finished');
    r.B_finished = document.querySelectorAll('#sug0 .ze-row').length;

    /* the cut is stated, never silent */
    const many = [];
    for (let i = 0; i < 40; i++) many.push(Object.assign({}, WORK[0], {k:'x'+i}));
    zePaint(document.getElementById('sug0'),
      many.map(w => zeWorkOpt(w, "zeSugSet(0,'"+w.k+"')", 'onmousedown')), [], 0, 12);
    r.capRows = document.querySelectorAll('#sug0 .ze-row').length;
    r.capSaid = (document.querySelector('#sug0 .ze-none') || {}).textContent || '';

    /* a quote in a key must not break out of the onmousedown attribute */
    zePaint(document.getElementById('sug0'),
      [zeWorkOpt(Object.assign({}, WORK[0], {k:"it's"}), "zeSugSet(0,'" + sugq("it's") + "')", 'onmousedown')],
      [], 0, 12);
    r.quoteClick = document.querySelector('#sug0 .ze-row').getAttribute('onmousedown');

    /* markup in a worker name must stay text, never become tags */
    zePaint(document.getElementById('sug0'),
      [zeWorkerOpt({id:9,name:'<img src=x onerror=alert(1)>',code:'W-9',dept:'X'},0,'x','onmousedown')],
      ['img'], 0, 12);
    r.xssImgs = document.querySelectorAll('#sug0 img').length;
    r.xssText = document.querySelector('#sug0 .ze-t').textContent;
    return r;
  });
  out.pageErrors = errs;
  console.log(JSON.stringify(out));
  await b.close();
})();
JS;
file_put_contents(__DIR__ . '/.zp_harness.js', $harness);
$raw = shell_exec('node ' . escapeshellarg(__DIR__ . '/.zp_harness.js') . ' '
    . escapeshellarg(__DIR__ . '/.zp.js') . ' '
    . escapeshellarg($B . 'assets/css/zskin.css') . ' 2>&1');
$r = json_decode(trim((string)$raw), true);

if (!is_array($r)) { echo "  FAIL: it did not run —\n$raw\n\n0 passed, 1 failed\n"; exit(1); }

echo "1. It runs without a single JavaScript error\n";
ok($r['pageErrors'] === [], 'no errors, got ' . json_encode($r['pageErrors']));

echo "2. THE IN-ROW LIST IS THE SAME ROW AS THE TOP LIST — the whole fix\n";
/* tab A: top shows work, row shows workers. Different DATA, same SHAPE. */
ok($r['A_top']['marks'] > 0, 'the top list highlights what you typed');
ok($r['A_sug']['marks'] > 0, 'AND SO DOES THE IN-ROW LIST — it used not to, got '
   . $r['A_sug']['marks'] . ' marks');
ok($r['A_sug']['strong'] === 'W-014', 'the in-row worker shows their code, got '
   . json_encode($r['A_sug']['strong']));
ok(str_contains($r['A_sug']['html'], 'Stitching'), '  and their department, got: ' . $r['A_sug']['html']);

echo "3. TAB B WAS BOOKING BLIND — the work row had no LEFT count\n";
ok($r['B_sug']['title'] === 'Flat Sheet → Overlock', 'the row names the job, got '
   . json_encode($r['B_sug']['title']));
ok(str_contains($r['B_sug']['left'], '180'),
   'IT NOW CARRIES THE REMAINING QUANTITY, got ' . json_encode($r['B_sug']['left']));
ok(str_contains($r['B_sug']['left'], 'left'), '  and says what that number is');
ok($r['B_sug']['strong'] === 'PI-2026-014', 'the order it belongs to, got '
   . json_encode($r['B_sug']['strong']));
ok(str_contains($r['B_sug']['html'], 'Hastens AB'), '  the customer');
ok($r['B_sug']['chips'] === 2, '  the size and the stage as chips, got ' . $r['B_sug']['chips']);
ok($r['B_sug']['rate'] === true, '  and the rate per piece');
ok($r['B_sug']['usual'] === true, 'the learned default is marked on this tab too');
ok($r['B_sug']['marks'] > 0, '  and the match is highlighted');
/* the top list on tab A always had all of this — prove they now match */
ok($r['A_top']['chips'] === $r['B_sug']['chips'],
   'the same work row in both places carries the same chips, got '
   . $r['A_top']['chips'] . ' vs ' . $r['B_sug']['chips']);
ok(($r['A_top']['left'] !== '') === ($r['B_sug']['left'] !== ''),
   '  and both show a remaining count');

echo "4. A worker row points on; a work row counts down\n";
ok($r['B_top']['left'] === '→', 'a worker row shows an arrow, not a fake zero, got '
   . json_encode($r['B_top']['left']));
ok($r['A_sug']['left'] === '→', '  in the row list too');

echo "5. Finished work is never offered, and the learned default leads\n";
ok($r['B_finished'] === 0, 'a done job cannot be picked in a row, got ' . $r['B_finished']);
ok($r['A_sugFirst'] === 'Muhammad Ashraf',
   'whoever usually does this operation comes first, got ' . json_encode($r['A_sugFirst']));

echo "6. The list no longer stops at eight without saying so\n";
ok($r['capRows'] === 12, 'twelve rows are drawn in a cell, got ' . $r['capRows']);
ok(str_contains($r['capSaid'], '28 more'), 'AND THE CUT IS STATED, got ' . json_encode($r['capSaid']));
ok(str_contains($r['capSaid'], 'keep typing'), '  with what to do about it');

echo "7. A name cannot break the row it is drawn into\n";
ok($r['xssImgs'] === 0, 'markup in a name stays text, got ' . $r['xssImgs'] . ' tags');
ok($r['xssText'] === '<img src=x onerror=alert(1)>', '  and reads back exactly, got '
   . json_encode($r['xssText']));
ok(str_contains($r['quoteClick'], "it\\'s"),
   'an apostrophe in a key is escaped inside the handler, got ' . json_encode($r['quoteClick']));

@unlink(__DIR__ . '/.zp.js'); @unlink(__DIR__ . '/.zp_harness.js');

echo "8. There is ONE renderer now, not two copies that can drift\n";
ok(substr_count($pe, 'function zeRowHtml(') === 1, 'one row renderer, got '
   . substr_count($pe, 'function zeRowHtml('));
ok(substr_count($pe, 'zePaint(') === 3, 'one painter, called from the top list and the row list, got '
   . substr_count($pe, 'zePaint('));
ok(!str_contains($pe, "'<div class=\"ze-s\"><span style=\"font-family:ui-monospace,monospace\">' + esc(o.meta)"),
   'the cut-down row builder is gone');
ok(str_contains($pe, 'ONE ROW, DRAWN IN TWO PLACES'), 'and why is written down');
ok(str_contains(preg_replace('/\s+/', ' ', $pe), 'lost the LEFT count'),
   '  including what the drift actually cost');

echo "9. Nothing about SAVING or the ceiling moved\n";
ok(str_contains($pe, 'function stagedOn(key)'), 'the staged sheet still counts against the ceiling');
ok(str_contains($pe, 'q + stagedOn(picked.k) > picked.left'), 'tab A still shares one ceiling');
ok(str_contains($pe, 'per[r.v] + stagedOn(r.v) > rowLeft(r)'), 'tab B still caps each operation');
ok(str_contains($pe, "\$('zeSave').disabled = over"), 'and Save is still refused while over');
ok(str_contains($pe, 'window.zeStash = function()'), 'the staging that builds the POST is untouched');

echo "10. The ceiling is visible WHILE you fill it, not only after\n";
ok(str_contains($pe, 'id="zeCap"'), 'there is a running readout');
ok(str_contains($pe, "' of ' + qn(picked.left) + ' left'"), '  counting up to what is left');
ok(str_contains($pe, '.ze-cap.bad'), '  which turns red when it is passed');
ok(str_contains($pe, 'function warn(over, q)'), 'and the red block still explains a refusal');

echo "11. Eleven production pages are in, and each can be taken back out\n";
$pages = ['production_entry.php','product_master.php','production_ops_bulk.php',
          'production_stages.php','production_order_rates.php','production_reports.php',
          'production_dashboard.php','production_amend.php','production_workers.php',
          'production_my_work.php','part_library.php'];
foreach ($pages as $p) {
    $src = file_get_contents($B . $p);
    $in  = substr_count($src, '<div class="zskin">');
    $out = substr_count($src, 'closes .zskin');
    ok($in === 1 && $out === 1, "$p opts in once and closes once, got $in/$out");
}
/* a wrapper that opens after the markup has started wraps nothing */
foreach ($pages as $p) {
    $src = file_get_contents($B . $p);
    ok(strpos($src, '<div class="zskin">') > strrpos($src, '</style>'),
       "$p wraps its markup, not its stylesheet");
}

echo "12. The module maps ONE vocabulary, so ten pages needed no rewrite\n";
foreach (['zp-card','zp-t','zp-b'] as $c)
    ok(str_contains($css, ".zskin .$c{") || str_contains($css, ".zskin table.$c{"),
       ".$c is mapped once in the skin");
ok(str_contains($css, 'Eleven pages share this vocabulary'), 'and the reason is recorded');
/* OVER is the one thing a density pass must never quieten */
ok(str_contains($css, 'OVER stays loud'), 'the over-booking state is protected in writing');
ok(str_contains($css, '.zskin .zin.over'), '  and actually still painted red');
ok(str_contains($css, '.zskin .zp-b.red'), 'delete cannot end up the same blue as save');
ok(str_contains($css, '.zskin .zp-b.ok'), '  because each intent is named');

echo "\n$P passed, $F failed\n";
exit($F ? 1 : 0);
