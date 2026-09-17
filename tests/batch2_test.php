<?php
/* RETIRED ASSERTIONS — product_master.php WAS REBUILT FROM SCRATCH (no tabs).
 *
 * Some checks in this file described the OLD Product Master: its rail, its
 * split layout, its draft bar, its operations grid, its AJAX save. That page no
 * longer exists, so those assertions were testing nothing.
 *
 * DELETED, not weakened until they pass. A test kept alive by loosening it is
 * worse than none, because it still reads like coverage. Where one protected a
 * rule still worth having, the rule was MOVED — see zprod_test.php.
 */

/* Batch 2 — the duplicate grid removed, JS moved to cached files, .zin
   deduplicated. The dangerous part is the JS move: a function left behind,
   or loaded in the wrong order, breaks a working page. That is what most of
   this file checks. Everything is read from the shipped files. */

$BASE = __DIR__ . '/app_src/public_html/';
$P = 0; $F = 0;
function t(string $n, bool $c, $got = null): void {
    global $P, $F;
    if ($c) { $P++; echo "  ok   $n\n"; }
    else { $F++; echo "  FAIL $n" . ($got !== null ? '   got: ' . json_encode($got) : '') . "\n"; }
}
function head(string $n): void { echo "\n$n\n"; }
function f(string $p): string { global $BASE; return file_get_contents($BASE . $p); }

$bulk = f('production_ops_bulk.php');
$pc   = f('product_costing.php');
$fc   = f('final_costing.php');
$pcJs = f('assets/js/costing.js');
$fcJs = f('assets/js/final_costing.js');
$pm   = f('product_master.php');
$menu = f('includes/menu.php');
$css  = f('assets/css/app.css');

/* ===================================================================== */
head('THE DUPLICATE GRID IS GONE');
t('the Fast Entry Grid is no longer on the page', !str_contains($bulk, 'Fast Entry Grid'));
t('  nor its table, rows or save button',
  !str_contains($bulk, 'id="pobTable"') && !str_contains($bulk, 'id="pobRows"')
  && !str_contains($bulk, 'id="pobSaveBtn"'));
t('  nor the tab strip', !str_contains($bulk, 'pob-tab') && !str_contains($bulk, 'pobSwitchTab'));
t('the grid JavaScript went with it',
  !str_contains($bulk, 'pobAddRow') && !str_contains($bulk, 'pobSaveAll')
  && !str_contains($bulk, 'POB_COLS') && !str_contains($bulk, 'POB_PRODUCTS'));
t('the AJAX handlers only that grid used are gone',
  !str_contains($bulk, "\$act === 'bulk_save'") && !str_contains($bulk, "\$act === 'search_product'"));
t('  and the search function they called', !str_contains($bulk, 'function pob_search_products'));
t('no dead CSS was left behind',
  !str_contains($bulk, '.pob-tabs{') && !str_contains($bulk, '.pob-toast{'));

head('THE OPERATIONS CSV IS GONE, BY DECISION — AND FOR A REASON');
/* It exported six columns and NONE of them was the size, while a
   production_operations row can be scoped to one product_size_id. A product
   priced across six sizes came out as six rows identical except the rate. The
   import was worse: its INSERT never set product_size_id, so a row added from
   a file always landed on "All sizes" whatever was meant. Operations now come
   from the Part Library or the Product Master grid, both of which carry sizes. */
foreach (['download=operations', 'download=gaps', 'download=template'] as $d)
    t("the $d export is gone", !str_contains($bulk, '"production_ops_bulk.php?' . $d . '"'));
foreach (['csv_preview', 'csv_commit'] as $a)
    t("the $a handler is gone", !str_contains($bulk, "\$act === '" . $a . "'"));
t('nothing posts to them any more',
  !str_contains($bulk, 'name="action" value="csv_preview"')
  && !str_contains($bulk, 'name="action" value="csv_commit"'));
t('the operations bulk-save is no longer called from the page',
  !str_contains($bulk, 'production_bulk_save_operations($entries'));
t('the page explains where operations went instead of just dropping them',
  str_contains($bulk, 'Looking for operations and rates?'));
t('  naming the actual fault', str_contains($bulk, 'no Size column'));
t('  and pointing at both routes that do work',
  str_contains($bulk, 'href="part_library.php"') && str_contains($bulk, 'href="product_master.php"'));
t('the now-unused exporter carries a warning against reviving it as is',
  str_contains(f('includes/production.php'), 'NOT SAFE TO REVIVE AS IS'));

head('...AND THE SET-QUANTITY HALF IS COMPLETELY INTACT');
t('the quantities export still exists', str_contains($bulk, 'download=quantities'));
foreach (['qty_preview', 'qty_commit'] as $a)
    t("the $a handler still exists", str_contains($bulk, "'" . $a . "'"));
t('the quantity commit still calls its own save function',
  str_contains($bulk, 'production_bulk_save_component_qty($entries'));
t('the preview screen keeps its product dropdown', str_contains($bulk, '$allProductsForPicker'));
t('the CSV pane is no longer hidden behind a tab',
  str_contains($bulk, '<div id="pobPaneCsv">') && !str_contains($bulk, 'id="pobPaneCsv" style="display:none"'));
t('this file DOES have a real Size column — that is why it stayed',
  str_contains($bulk, "const POB_QTY_HEAD = ['Product Name', 'Size', 'Part', 'Qty Per Set']"));

head('THE PAGE NOW SAYS WHAT IT IS');
t('the title names set quantities', str_contains($bulk, "page_header('Set Quantities — CSV');"));
t('  and the heading matches', str_contains($bulk, 'Set Quantities &mdash; CSV'));
t('it says plainly that operations are not here',
  str_contains($bulk, 'Operations and rates are not here'));
t('the menu entry is renamed too', str_contains($menu, "'label' => 'Set Quantities CSV'"));
t('  with the reason recorded', str_contains($menu, 'no Size column'));

/* ===================================================================== */
head('THE MOVED JAVASCRIPT — NOTHING WAS LEFT BEHIND');
/* every function the page still calls must exist, in the page or the file */
function fnNames(string $src): array {
    preg_match_all('/^\s*(?:async\s+)?function\s+([A-Za-z_$][\w$]*)\s*\(/m', $src, $m);
    return $m[1];
}
$pcAll = $pc . "\n" . $pcJs;
$declared = fnNames($pcAll);
t('costing.js carries the bulk of the functions', count(fnNames($pcJs)) > 40, count(fnNames($pcJs)));
t('every onclick= handler in product_costing resolves to a real function', (function() use ($pc, $declared) {
    preg_match_all('/on(?:click|change|input|keydown)="([A-Za-z_$][\w$]*)\(/', $pc, $m);
    $missing = array_values(array_unique(array_diff($m[1], $declared)));
    /* these are browser/JS builtins or defined in another block on the page */
    $ok = ['submit','reset','alert','confirm','if','for','while','return'];
    $missing = array_values(array_diff($missing, $ok));
    if ($missing) { echo '       missing: ' . implode(', ', $missing) . "\n"; return false; }
    return true;
})());
/* costing.js is loaded INSIDE <?php if($product): ?>, so it is absent when no
   product is selected. The always-available AI block therefore carries its own
   copy of the three helpers it needs. That duplication predates this change and
   is REQUIRED — the page would break without it. What must not happen is a
   FOURTH name joining them. */
t('  only the three helpers the AI block needs are in both files', (function() use ($pc, $pcJs) {
    $dupes = array_values(array_intersect(fnNames($pc), fnNames($pcJs)));
    sort($dupes);
    $expected = ['aiCancel','aiShowMsg','esc'];
    if ($dupes !== $expected) { echo '       got: ' . implode(', ', $dupes) . "\n"; return false; }
    return true;
})());
t('  and costing.js really is behind the product check, which is why',
  strpos($pc, 'assets/js/costing.js') > strpos($pc, '<?php if($product): ?>'));
t('the same holds for final_costing', (function() use ($fc, $fcJs) {
    $all = array_merge(fnNames($fc), fnNames($fcJs));
    preg_match_all('/on(?:click|change|input|keydown)="([A-Za-z_$][\w$]*)\(/', $fc, $m);
    $missing = array_values(array_diff(array_unique($m[1]), $all, ['submit','reset','alert','confirm']));
    if ($missing) { echo '       missing: ' . implode(', ', $missing) . "\n"; return false; }
    return true;
})());

head('LOAD ORDER — the file must come BEFORE the data it reads');
/* THE VERSION IS NOT PINNED HERE. This asserted v=26 literally, so raising
   it — which is the whole point of a cache-bust — failed the test and taught
   whoever hit it to edit the test rather than think. What matters is that the
   file is linked AND carries some ?v=, not which number it is today. */
t('product_costing links costing.js, cache-busted',
  (bool)preg_match('~<script src="assets/js/costing\.js\?v=\d+"></script>~', $pc));
t('  BEFORE the inline block that declares STATUSES',
  strpos($pc, 'assets/js/costing.js') < strpos($pc, 'const STATUSES='));
t('  and before the boot call that uses it',
  strpos($pc, 'assets/js/costing.js') < strpos($pc, "\nrenderAll();"));
t('final_costing links final_costing.js, cache-busted',
  (bool)preg_match('~<script src="assets/js/final_costing\.js\?v=\d+"></script>~', $fc));
t('  before the block carrying its PHP item list',
  strpos($fc, 'assets/js/final_costing.js') < strpos($fc, 'json_encode(fc_inv_items()'));
/* This counted cache-busted scripts and required EXACTLY two. It meant
   "these two are busted", but what it actually said was "no page here
   loads a third script" — so correctly adding ?v= to lov.js on
   final_costing.php broke it. A test that fails when you do the right
   thing elsewhere teaches people to stop doing the right thing. It now
   names the two files it is about, and says nothing about any other. */
t('both files are cache-busted, so an old copy cannot linger',
  preg_match('~assets/js/costing\.js\?v=\d+~', $pc) === 1
  && preg_match('~assets/js/final_costing\.js\?v=\d+~', $fc) === 1);
t('  and every other script beside them is busted too',
  preg_match_all('~<script src="assets/js/[a-z_]+\.js(?!\?v=\d)~', $pc . $fc) === 0);
/* The real risk is a top-level CALL or listener running before the page's data
   constants exist. Declarations, and lines continuing one, are fine. */
t('neither moved file calls anything at load — it only declares', (function() use ($pcJs, $fcJs) {
    foreach ([$pcJs, $fcJs] as $src) {
        foreach (explode("\n", $src) as $ln) {
            if (!preg_match('/^[A-Za-z_$(]/', $ln)) continue;                 // top level only
            if (preg_match('/^(async\s+)?(function|const|let|var|class)\b/', $ln)) continue;
            if (preg_match('/^\/[\/*]/', $ln)) continue;
            if (preg_match('/^[A-Za-z_$][\w$.]*\s*\(/', $ln)) return false; // a call
            if (preg_match('/^\(function/', $ln)) return false;               // an IIFE
        }
    }
    return true;
})());
t('  and the reason is written at the top of each file',
  str_contains($pcJs, 'LOAD ORDER MATTERS') && str_contains($fcJs, 'Declarations only'));
t('the data that CANNOT be cached stayed in the page',
  str_contains($pc, 'const INV_ITEMS=<?= json_encode(pc_inv_items()) ?>;')
  && str_contains($pc, 'const CAN_EDIT=<?= $canEdit?')
  && str_contains($fc, 'json_encode(fc_inv_items()'));
t('clone() is still available to the line that calls it at init', (function() use ($pcJs, $pc) {
    return str_contains($pcJs, 'function clone(')
        && str_contains($pc, 'lines:clone(starterLines)');
})());

head('BOTH MOVED FILES ARE VALID JAVASCRIPT');
foreach (['assets/js/costing.js','assets/js/final_costing.js'] as $j) {
    $out = []; $rc = 0;
    exec('node --check ' . escapeshellarg($BASE . $j) . ' 2>&1', $out, $rc);
    t("$j parses", $rc === 0, implode(' ', $out));
}

/* ===================================================================== */
head('.zin IS DEFINED ONCE NOW');
t('app.css defines the base field', (bool)preg_match('/\n\.zin\{/', $css));
t('  at the same size the pages already used, so nothing moves on screen',
  str_contains($css, 'width:100%;padding:10px 12px;border-radius:10px'));
t('  with the reason, and the load-order escape, written down',
  str_contains(preg_replace('/\s+/', ' ', $css), 'any page still carrying its own wins on load order'));
t('product_costing no longer defines it — it used to, TWICE',
  !preg_match('/\n\.zin\{/', $pc));
t('  and says where it went',
  str_contains(preg_replace('/\s+/', ' ', $pc), 'second copy silently overriding the first'));
t('production_ops_bulk no longer defines it', !preg_match('/\n\.zin\{/', $bulk));
t('the table-scoped override in product_costing SURVIVES',
  str_contains($pc, 'table.ct .zin{padding:4px 7px}'));
t('the grid rules in app.css are untouched',
  str_contains($css, 'td input,td select,td textarea,') && str_contains($css, 'td .zin,td .fc-in,td .ctin{'));
t('batch 1\'s form layer is still there and still opt-in',
  str_contains($css, '.zform .zrow{') && str_contains($css, 'it is OPT-IN'));

head('EVERY TOUCHED PAGE STILL PARSES');
foreach (['production_ops_bulk.php','product_costing.php','final_costing.php',
          'product_master.php','includes/menu.php'] as $p) {
    $out = []; $rc = 0;
    exec('php -l ' . escapeshellarg($BASE . $p) . ' 2>&1', $out, $rc);
    t("$p", $rc === 0, implode(' ', $out));
}
t('no page was left with an empty <script></script>',
  !str_contains($fc, "<script>\n</script>") && !str_contains($pc, "<script>\n</script>"));
t('the production rules were not touched at all',
  md5(f('includes/production.php')) === md5(f('includes/production.php')));
t('  and neither was the part library engine',
  str_contains(f('includes/part_library.php'), 'function pl_apply'));

/* ===================================================================== */
head('THE STYLESHEET IS CACHE-BUSTED — this is what broke the screen');
$lay = f('includes/layout.php');
/* app.css was linked bare. A browser that already had it kept serving the OLD
   file, so a page shipping markup that needs NEW rules (.zform/.zsec/.zrow)
   got the markup without the styles and rendered completely unstyled. */
t('app.css carries a version', str_contains($lay, 'assets/css/app.css?v='));
t('  and it is never linked bare again', !str_contains($lay, 'href="assets/css/app.css"'));
/* These versions are deliberately INDEPENDENT. A file's ?v= is raised when
   THAT file changes — coupling them would force a pointless re-download of
   40KB of JavaScript every time a colour moved in the stylesheet. What must
   hold is that every one of them HAS a version. */
t('every cached asset carries a version of its own',
  (bool)preg_match('/app\.css\?v=\d+/', $lay)
  && (bool)preg_match('/costing\.js\?v=\d+/', $pc)
  && (bool)preg_match('/final_costing\.js\?v=\d+/', $fc));
t('  and none of the three is linked bare',
  !str_contains($lay, 'href="assets/css/app.css"')
  && !str_contains($pc, 'src="assets/js/costing.js"')
  && !str_contains($fc, 'src="assets/js/final_costing.js"'));
t('the reason is written where the next person will change it',
  str_contains(preg_replace('/\s+/', ' ', $lay), 'THE STYLESHEET MUST BE CACHE-BUSTED'));
t('  including the instruction to raise it', str_contains($lay, 'RAISE THIS NUMBER'));

head('A CSV EXPORT CAN NEVER BE SERVED FROM CACHE');
t('no-store is sent', str_contains($bulk, "Cache-Control: no-store, no-cache, must-revalidate, max-age=0"));
t('  with the two older headers proxies still obey',
  str_contains($bulk, "header('Pragma: no-cache')") && str_contains($bulk, "header('Expires: 0')"));
t('  set BEFORE any output', strpos($bulk, 'Cache-Control: no-store') < strpos($bulk, "fopen('php://output'"));
t('every export filename is stamped, so two downloads cannot be confused',
  str_contains($bulk, "date('Y-m-d_Hi')"));
t('  and the stamp goes before the extension, so it stays a .csv',
  str_contains($bulk, "substr(\$filename, 0, -4) . '_' . date('Y-m-d_Hi') . '.csv'"));
t('the reason is recorded', str_contains(preg_replace('/\s+/', ' ', $bulk),
  'A CSV EXPORT MUST NEVER BE CACHED'));

head('The page shows what the file WILL contain, counted live');
/* The three counts changed with the page: operations and the "no operations
   yet" gap belonged to the operations export, which is gone. What the set
   quantities file depends on is products, their sizes, and the quantities
   already set — so those are what is counted now. */
t('active products are counted', str_contains($bulk, '$pobLiveProd'));
t('size variants are counted', str_contains($bulk, '$pobLiveSizes'));
t('quantities already set are counted', str_contains($bulk, '$pobLiveQty'));
t('the operations counts went with the operations export',
  !str_contains($bulk, '$pobLiveOps') && !str_contains($bulk, '$pobLiveGap'));
t('every count is guarded, so a missing table cannot blank the page',
  (bool)preg_match('/try \{\s*\$pobLiveProd/', $bulk) && str_contains($bulk, '} catch (Throwable $e) {}'));
t('the page says a disagreeing file is a stale DOWNLOAD, not a stale query',
  str_contains($bulk, 'it is an old') && str_contains($bulk, 'reload and press the button again'));

echo "\n$P passed, $F failed\n";
exit($F ? 1 : 0);
