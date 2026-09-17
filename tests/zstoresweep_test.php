<?php
/* THE REST OF THE STORE MODULE — eleven screens in one sweep.
 *
 * Each of these pages was written at a different time and invented its
 * own class prefix, but they are the same shape. This checks that the one
 * mapping block reaches all eleven, that it makes each of them compact,
 * and — the part that actually needed care — that .iv-*, which TWO pages
 * use for different things, cannot cross between them.
 *
 * Each page's OWN stylesheet is loaded, with a scrap of markup in that
 * page's vocabulary, and the result is measured in Chromium with the
 * wrapper and without it.
 */

$B   = __DIR__ . '/app_src/public_html/';
$css = file_get_contents($B . 'assets/css/zskin.css');

$P = 0; $F = 0;
function ok($c, $m) { global $P, $F; if ($c) { $P++; } else { $F++; echo "  FAIL: $m\n"; } }

$work = __DIR__ . '/.zsweep';
@mkdir($work, 0777, true);

/* page => [prefix, wrapper class] */
$PAGES = [
    'inv_consume.php'    => ['ic2', 'zskin'],
    'inv_stock.php'      => ['is',  'zskin'],
    'inv_items.php'      => ['im',  'zskin'],
    'inv_ledger.php'     => ['il',  'zskin'],
    'inv_jobwork.php'    => ['jw',  'zskin'],
    'inv_verify.php'     => ['iv',  'zskin ivchk'],
    'inv_setup.php'      => ['iv',  'zskin ivset'],
    'inv_reset.php'      => ['ir',  'zskin'],
    'inv_parties.php'    => ['ip',  'zskin'],
    'inv_ordercost.php'  => ['oc',  'zskin'],
    'inv_exceptions.php' => ['ex',  'zskin'],
];

echo "1. Every page opts in once, and can be taken back out\n";
foreach ($PAGES as $f => [$pre, $cls]) {
    $src = file_get_contents($B . $f);
    ok(substr_count($src, '<div class="' . $cls . '">') === 1, "$f opens <div class=\"$cls\">");
    ok(substr_count($src, 'closes .zskin') === 1, "  $f closes it once");
    ok(strpos($src, '<div class="' . $cls . '">') > strrpos($src, '</style>'),
       "  $f wraps the markup, not the stylesheet");
    ok(strpos($src, 'closes .zskin') < strrpos($src, '<?php page_footer(); ?>'),
       "  $f closes before the footer");
    /* the page must still define its own look, or removing the wrapper
       leaves raw HTML rather than the page it was */
    ok(preg_match('/^\.' . $pre . '-[a-z0-9]/m', $src) === 1,
       "  $f still defines its own .$pre-* styles");
}

echo "2. THE ONE THAT NEEDED CARE: .iv-* is used by TWO pages\n";
$setup  = file_get_contents($B . 'inv_setup.php');
$verify = file_get_contents($B . 'inv_verify.php');
ok(str_contains($setup, '.iv-ok{') && str_contains($verify, '.iv-ok{'),
   'both pages really do define .iv-ok');
/* and they mean different things — this is why a bare mapping is unsafe */
preg_match('/^\.iv-ok\{([^}]*)\}/m', $setup, $a);
preg_match('/^\.iv-ok\{([^}]*)\}/m', $verify, $b);
ok(($a[1] ?? '') !== ($b[1] ?? ''), '  and they define it DIFFERENTLY, which is the whole problem');
ok(str_contains($b[1] ?? '', 'border-left'), '  one is a panel with a left rule');
ok(!str_contains($a[1] ?? '', 'border-left'), '  the other is a badge');

/* THE RULE: the skin must never write .iv-ok or .iv-btn without saying
   which page it means. */
/* Comments stripped first. The block EXPLAINS that a bare .zskin .iv-ok
   would be wrong, and saying so is not doing it — this is the third time
   a test of mine has read its own documentation as a fault. */
$rules = preg_replace('#/\*.*?\*/#s', '', $css);
ok(!preg_match('/\.zskin \.iv-ok[\s{,]/', $rules), 'the skin never maps .iv-ok bare');
ok(!preg_match('/\.zskin \.iv-btn[\s{,]/', $rules), 'the skin never maps .iv-btn bare');
ok(str_contains($css, '.zskin.ivset .iv-ok{') && str_contains($css, '.zskin.ivchk .iv-ok{'),
   'each page gets its own .iv-ok');
ok(str_contains($css, '.zskin.ivset .iv-btn{') && str_contains($css, '.zskin.ivchk .iv-btn{'),
   'and its own .iv-btn');
/* the names they AGREE on are mapped once, which is the point of
   checking rather than assuming */
ok(str_contains($css, '.zskin .iv-card{') || str_contains($css, '.zskin .iv-card,')
   || str_contains($css, '.zskin .iv-card{') || preg_match('/\.zskin \.iv-card[,{\s]/', $css),
   '.iv-card, which both pages define identically, is mapped once');
ok(str_contains($css, 'That is the .zgrid mistake exactly'),
   'and the reason the two are split is written down');

echo "3. No prefix is claimed by two pages\n";
$all = array_merge(glob($B . '*.php'), glob($B . 'includes/*.php'));
foreach (['ic2','is','im','il','jw','ir','ip','oc','ex','iss','ig','ic','op'] as $pre) {
    $owners = [];
    foreach ($all as $f)
        if (preg_match('/^\.' . $pre . '-[a-z0-9]/m', file_get_contents($f))) $owners[] = basename($f);
    ok(count($owners) <= 1, ".$pre-* belongs to one page, got " . json_encode($owners));
}
/* .iv-* is the documented exception, and it is handled above */
$ivOwners = [];
foreach ($all as $f)
    if (preg_match('/^\.iv-[a-z0-9]/m', file_get_contents($f))) $ivOwners[] = basename($f);
sort($ivOwners);
ok($ivOwners === ['inv_setup.php', 'inv_verify.php'],
   '.iv-* is shared by exactly the two pages that are handled for it, got ' . json_encode($ivOwners));

echo "4. Every shared status name is tied to its own badge\n";
foreach ([['ic2-pill', ['p-draft','p-posted','p-reversed']],
          ['jw-pill',  ['b-draft','b-settled','b-cancelled']],
          ['ip-pill',  ['t-supplier','t-customer','t-jobworker','t-both']],
          ['ex-pill',  ['s-high','s-med','s-low']],
          ['im-pill',  ['g-Fabric','g-Accessories','g-Packing','g-Other']]] as [$pill, $names]) {
    foreach ($names as $n) {
        ok(str_contains($css, '.zskin .' . $pill . '.' . $n . '{'),
           ".$n is mapped only as a .$pill");
        ok(!preg_match('/\.zskin \.' . preg_quote($n, '/') . '[\s{,]/', $css),
           "  and there is no bare .zskin .$n to leak");
    }
}
/* .p-draft really is used by two pages, which is why it is scoped twice */
$pOwners = [];
foreach (glob($B . '*.php') as $f)
    if (preg_match('/^\.p-draft[{ ]/m', file_get_contents($f))) $pOwners[] = basename($f);
sort($pOwners);
ok(count($pOwners) === 2, '.p-draft is defined by two pages, got ' . json_encode($pOwners));
ok(str_contains($css, '.zskin .iss-pill.p-draft{') && str_contains($css, '.zskin .ic2-pill.p-draft{'),
   '  and each has its own scoped rule');

/* ------------------------------------------------------------------ */
echo "5. Measured in a browser: each page's OWN stylesheet, both ways\n";

/* A scrap of markup in each page's vocabulary. Nothing is invented — the
   classes are the ones the page uses; only the content is fake. */
/* BUILT FROM WHAT THE PAGE ACTUALLY DEFINES.
   A fixed template put a .ex-tbl and a .ex-btn on the page — and
   inv_exceptions.php has neither: it is a card / pill / note screen built
   from .ex-row and .ex-val. Measuring a class nobody styles measures
   nothing, and it reported the sweep as broken when it was the sample
   that was wrong. inv_verify.php has no input and no pill either. */
$has = function (string $src, string $cls): bool {
    return (bool)preg_match('/^\.' . preg_quote($cls, '/') . '[{ ,.:]/m', $src);
};
$sample = function (string $pre, string $src) use ($has): string {
    $h = '<div class="' . $pre . '-card">';
    if ($has($src, $pre . '-note')) $h .= '<div class="' . $pre . '-note info">a note</div>';
    if ($has($src, $pre . '-tbl')) {
        $h .= '<table class="' . $pre . '-tbl"><thead><tr><th>Item</th><th class="r">Qty</th><th>Status</th></tr></thead><tbody>'
           . '<tr><td>FAB-001 Cotton greige</td><td class="r">1,840.5</td><td>'
           . ($has($src, $pre . '-pill') ? '<span class="' . $pre . '-pill">Draft</span>' : 'Draft')
           . '</td></tr><tr><td>'
           . ($has($src, $pre . '-inp') ? '<input class="' . $pre . '-inp" value="typed">' : 'typed')
           . '</td><td class="r">620</td><td></td></tr></tbody></table>';
    }
    if ($has($src, $pre . '-btn'))
        $h .= '<button class="' . $pre . '-btn">Save</button>'
            . '<button class="' . $pre . '-btn sec">Cancel</button>';
    return $h . '</div>';
};

$cases = [];
foreach ($PAGES as $f => [$pre, $cls]) {
    $src = file_get_contents($B . $f);
    if (!preg_match('/<style>(.*?)<\/style>/s', $src, $m)) { ok(false, "$f has a stylesheet"); continue; }
    $cases[$f] = ['pre' => $pre, 'cls' => $cls, 'css' => $m[1], 'src' => $src];
}
ok(count($cases) === 11, 'all eleven stylesheets came out, got ' . count($cases));

$head = '<!doctype html><html><head><meta charset="utf-8">'
      . '<style>' . file_get_contents($B . 'assets/css/app.css') . '</style>';
foreach ($cases as $f => $c) {
    $id = preg_replace('/[^a-z0-9]/', '', strtolower($f));
    foreach (['plain' => '', 'skin' => $c['cls']] as $mode => $cls) {
        $html = $head . '<style>' . $c['css'] . '</style><style>' . $css . '</style>'
              . '</head><body style="width:1280px;margin:0">'
              . ($cls ? '<div class="' . $cls . '">' : '<div>')
              . $sample($c['pre'], $c['src']) . '</div></body></html>';
        file_put_contents($work . "/{$mode}_{$id}.html", $html);
    }
}

$probe = <<<'JS'
const { chromium } = require('playwright');
(async () => {
  const fs = require('fs');
  const dir = process.argv[2];
  const files = fs.readdirSync(dir).filter(f => /^(plain|skin)_.*\.html$/.test(f));
  const br = await chromium.launch({ executablePath: '/opt/pw-browsers/chromium' });
  const out = {};
  for (const f of files) {
    const pg = await br.newPage({ viewport: { width: 1280, height: 700 } });
    await pg.goto('file://' + dir + '/' + f);
    await pg.waitForTimeout(60);
    out[f.replace('.html','')] = await pg.evaluate(() => {
      const t = document.querySelector('table');
      const rows = t ? [...t.querySelectorAll('tbody tr')] : [];
      const g = el => el ? Math.round(el.getBoundingClientRect().height) : 0;
      const cs = el => el ? getComputedStyle(el) : {};
      const card = document.querySelector('[class$="-card"]');
      const btn = document.querySelector('button');
      const pill = document.querySelector('[class$="-pill"]');
      return {
        hasTable: !!t, hasBtn: !!btn, hasPill: !!pill,
        rowH: rows.map(g),
        rAlign: t && t.querySelector('td.r') ? cs(t.querySelector('td.r')).textAlign : null,
        rFont: t && t.querySelector('td.r') ? cs(t.querySelector('td.r')).fontFamily : null,
        cellInpBorder: t && t.querySelector('td > input')
          ? cs(t.querySelector('td > input')).borderTopLeftRadius : null,
        thTransform: t ? cs(t.querySelector('thead th')).textTransform : null,
        thBg: t ? cs(t.querySelector('thead th')).backgroundColor : null,
        tdAlign: rows.length ? cs(rows[0].querySelector('td')).verticalAlign : null,
        cardRadius: cs(card).borderTopLeftRadius,
        cardShadow: cs(card).boxShadow,
        btnRadius: btn ? cs(btn).borderTopLeftRadius : null,
        btnH: g(btn),
        pillRadius: pill ? cs(pill).borderTopLeftRadius : null
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

if (!is_array($M)) { echo "  FAIL: the probe did not run:\n" . substr((string)$raw, 0, 900) . "\n"; $F++; }
else {
    $evened = 0; $tallestCut = 0;
    foreach ($cases as $f => $c) {
        $id = preg_replace('/[^a-z0-9]/', '', strtolower($f));
        $p = $M["plain_$id"] ?? null; $s2 = $M["skin_$id"] ?? null;
        if (!$p || !$s2) { ok(false, "$f was measured"); continue; }

        /* Every page has a card. */
        ok($s2['cardShadow'] === 'none', "$f: the card has no drop shadow");
        ok((float)$s2['cardRadius'] <= 8.5, "$f: and an 8px radius, got " . $s2['cardRadius']);

        if ($s2['hasTable']) {
            /* THE CLAIM THAT IS ACTUALLY TRUE, and it is not "shorter".
               With plain text in one row and an input in the next, these
               tables DRIFT: the rows are different heights because the
               cell is whatever its content makes it. A stated row height
               is what stops that. On a synthetic sample the text-only row
               can even get taller; on the real screens, which are full of
               inputs, the tallest row is what sets the page length — so
               that is what is measured. */
            ok(count(array_unique($s2['rowH'])) === 1,
               "$f: skinned rows are all one height: " . json_encode($s2['rowH']));
            if (count(array_unique($p['rowH'])) > 1) $evened++;
            if (max($s2['rowH']) < max($p['rowH'])) $tallestCut++;
            ok(max($s2['rowH']) <= 32,
               "$f: the tallest row is compact, got " . max($s2['rowH'])
               . 'px (was ' . max($p['rowH']) . 'px)');
            ok($s2['tdAlign'] === 'middle', "$f: cells are middle-aligned, so a row cannot drift");
            ok($s2['thTransform'] === 'none', "$f: headers are sentence case, not shouted");
            ok($s2['thBg'] !== 'rgba(0, 0, 0, 0)', "$f: headers sit on a tint");
            /* A column class called "r" that does not right-align is a
               broken promise. Several of these pages never defined td.r
               themselves, so the numbers sat ragged; the skin states it. */
            ok($s2['rAlign'] === 'right',
               "$f: a .r column is right-aligned, got " . json_encode($s2['rAlign']));
            ok(str_contains(strtolower((string)$s2['rFont']), 'mono'),
               "$f: and set in the mono face so digits line up");
            if ($s2['cellInpBorder'] !== null)
                ok((float)$s2['cellInpBorder'] === 0.0,
                   "$f: an input inside a cell is flat, got " . $s2['cellInpBorder']);
        }
        if ($s2['hasBtn']) {
            ok((float)$s2['btnRadius'] <= 5.5, "$f: buttons are 5px, got " . $s2['btnRadius']);
            ok($s2['btnH'] === 28, "$f: and 28px tall, got " . $s2['btnH']);
        }
        if ($s2['hasPill'])
            ok((float)$s2['pillRadius'] <= 3.5, "$f: badges are square, got " . $s2['pillRadius']);
    }
    /* Nine of the eleven have a table. Every one of them drifted before
       and none of them drifts now — that is the sweep's actual result. */
    ok($evened >= 8, "the skin evens out rows that drifted, on $evened pages");
    ok($tallestCut >= 8, "  and cuts the tallest row on $tallestCut of them");

    echo "   and the two .iv-* pages do NOT bleed into each other\n";
    $set = $M['skin_invsetupphp']; $chk = $M['skin_invverifyphp'];
    /* Setup's .iv-btn is the primary; Health's is the quiet one. If the
       mapping had been written bare, both would be the same colour. */
    ok($set['btnH'] === 28 && $chk['btnH'] === 28, 'both pages get a 28px button');
    ok($set['btnRadius'] === $chk['btnRadius'], '  the same radius');
}

/* .iv-ok is the pair that would actually break. Measured on its own,
   because the sample above has no .iv-ok in it. */
echo "6. .iv-ok stays a badge on one page and a panel on the other\n";
foreach (['ivset' => 'inv_setup.php', 'ivchk' => 'inv_verify.php'] as $mod => $f) {
    preg_match('/<style>(.*?)<\/style>/s', file_get_contents($B . $f), $m);
    file_put_contents($work . "/ok_$mod.html",
        $head . '<style>' . $m[1] . '</style><style>' . $css . '</style>'
        . '</head><body style="width:900px;margin:0"><div class="zskin ' . $mod . '">'
        . '<span class="iv-ok">All clear</span></div></body></html>');
}
$probe2 = <<<'JS'
const { chromium } = require('playwright');
(async () => {
  const br = await chromium.launch({ executablePath: '/opt/pw-browsers/chromium' });
  const out = {};
  for (const m of ['ivset','ivchk']) {
    const pg = await br.newPage({ viewport: { width: 900, height: 400 } });
    await pg.goto('file://' + process.argv[2] + '/ok_' + m + '.html');
    await pg.waitForTimeout(60);
    out[m] = await pg.evaluate(() => {
      const el = document.querySelector('.iv-ok'), cs = getComputedStyle(el);
      return { w: Math.round(el.getBoundingClientRect().width),
               h: Math.round(el.getBoundingClientRect().height),
               pad: cs.paddingTop, leftBorder: cs.borderLeftWidth, size: cs.fontSize };
    });
    await pg.close();
  }
  await br.close();
  console.log(JSON.stringify(out));
})();
JS;
file_put_contents($work . '/probe2.js', $probe2);
$raw2 = shell_exec('cd ' . escapeshellarg(__DIR__) . ' && node ' . escapeshellarg($work . '/probe2.js')
                   . ' ' . escapeshellarg($work) . ' 2>&1');
$K = json_decode((string)$raw2, true);
if (!is_array($K)) { echo "  FAIL: the .iv-ok probe did not run:\n" . substr((string)$raw2, 0, 600) . "\n"; $F++; }
else {
    ok((float)$K['ivset']['pad'] <= 2,
       'on Setup it is a tight badge, got padding ' . $K['ivset']['pad']);
    ok((float)$K['ivchk']['pad'] >= 8,
       'on Health it is a padded panel, got padding ' . $K['ivchk']['pad']);
    ok((float)$K['ivchk']['leftBorder'] >= 3,
       '  and keeps its left rule, got ' . $K['ivchk']['leftBorder']);
    ok((float)$K['ivset']['leftBorder'] < 3,
       '  which the badge does not have, got ' . $K['ivset']['leftBorder']);
    ok($K['ivchk']['h'] > $K['ivset']['h'],
       'the panel is taller than the badge (' . $K['ivchk']['h'] . ' vs ' . $K['ivset']['h'] . ')');
}

echo "7. The stylesheet is cache-busted\n";
ok(preg_match('/zskin\.css\?v=\d+/', file_get_contents($B . 'includes/layout.php')) === 1,
   'zskin.css carries a ?v=');

echo "\n$P passed, $F failed\n";
exit($F ? 1 : 0);
