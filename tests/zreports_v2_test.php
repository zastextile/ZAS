<?php
/* PRODUCTION DASHBOARD & REPORTS, REBUILT (owner, 28 Nov 2026: "standard and
   professional like SAP Fiori / Oracle Fusion … animated show / hide … cards
   flip, summary on the front, detail on the back … filters that work").
   The period is read once; everything else happens on the page. */
$P = 0; $F = 0;
function ok($c, $m) { global $P, $F; if ($c) { $P++; echo "  ok  $m\n"; } else { $F++; echo "  FAIL: $m\n"; } }
$B  = __DIR__ . '/../public_html/';
$rp = file_get_contents($B . 'includes/zp_report.php');
$js = file_get_contents($B . 'assets/js/zprep.js');
$css = file_get_contents($B . 'assets/css/zprep.css');
$db = file_get_contents($B . 'production_dashboard.php');
$pr = file_get_contents($B . 'production_reports.php');

echo "1. One read of the period, nothing written\n";
$code = preg_replace('!/\*.*?\*/!s', '', $rp);
ok(substr_count($code, 'FROM zp_entries e') === 1, 'one query reads the entries of the period');
ok(!preg_match('/\b(INSERT|UPDATE|DELETE)\b/i', $code), 'the report code writes nothing');
ok(!str_contains($js, 'fetch(') && !str_contains($js, 'XMLHttpRequest'), 'the page never asks the server while filters, tabs, sorting or cards change');
ok(str_contains($rp, "LEFT JOIN zp_line_set_ops lo ON lo.id = e.op_id AND e.op_kind = 'line'"), 'set work copied onto a line is named, like on Order Status');
ok(str_contains($rp, 'LIMIT " . ($cap + 1)') && str_contains($db, 'only the first 120,000 are shown'), 'a period nobody meant is capped, and the page says so');

echo "2. Money only for those allowed\n";
ok(str_contains($rp, "\$money ? round((float)\$r['a'], 2) : 0, \$money ? round((float)\$r['r'], 2) : 0"), 'without can_see_rates the amount and rate are sent as 0');
ok(str_contains($db, '$showMoney = can_see_rates();') && str_contains($pr, '$showMoney = can_see_rates();'), 'both pages ask can_see_rates');
ok(str_contains($db, "if (!\$showMoney) \$D['prev']['amt'] = 0;") && str_contains($pr, "if (!\$showMoney) \$D['prev']['amt'] = 0;"), '  the previous period\'s wage bill too');
ok(str_contains($js, "if (MONEY) out.push({ id: 'pkr'"), 'the Wage bill card only for them');

echo "3. Cancelled entries\n";
ok(str_contains($js, 'if (!opts.all && r[10]) return false;'), 'every total skips cancelled rows');
ok(str_contains($js, "filtered({ all: tab === 'entries' })"), 'only Every entry shows them');
ok(str_contains($rp, "WHERE status='active' AND entry_date BETWEEN ? AND ?"), 'the previous-period comparison counts active rows only');

echo "4. Flip cards, animated sections, filters\n";
ok(str_contains($css, '.zpr .fc.flip .in{transform:rotateY(180deg)}') && str_contains($css, 'backface-visibility:hidden'), 'a card turns over: summary on the front, detail on the back');
ok(str_contains($js, "c.classList.toggle('flip')") && str_contains($js, "e.key === 'Enter' || e.key === ' '"), '  by click, or Enter / Space from the keyboard');
ok(str_contains($css, 'grid-template-rows:0fr') && str_contains($js, "store('zpr_sec_' + s.id"), 'sections fold away with an animation, and the page remembers');
ok(str_contains($js, "var DORD = ['dept', 'stage', 'w', 'p', 'f'];"), 'filters: department, stage, worker, product, order (+ a day and a search)');
ok(str_contains($js, 'filtered({ skip: k })'), '  a value list counts what the OTHER filters leave');
ok(str_contains($js, 'history.replaceState(null'), '  the filters live in the address, so a view can be shared');
ok(str_contains($css, '@media (prefers-reduced-motion:reduce)'), 'people who turn motion off get no animation');

echo "5. The reports\n";
foreach (['worker', 'dept', 'product', 'order', 'op', 'daily', 'entries'] as $t) ok(str_contains($pr, "'$t' =>") && str_contains($js, "$t:"), "tab: $t");
ok(str_contains($js, "order:   { t: 'By order',      g: 'f',    drill: ['l', 'o']"), '▸ an order opens its lines (product · size), then operations');
ok(str_contains($js, "product: { t: 'By product',    g: 'p',    drill: ['o', 'w']"), '▸ a product opens its operations, then who did them');
ok(str_contains($pr, "\$tab = (string)(\$_GET['r'] ?? 'worker');"), 'old ?r= links still open the right tab (Daily Entry\'s "Full report")');
ok(str_contains($js, "if (/^[=+\\-@\\t\\r']/.test(v)) v = \"'\" + v;"), 'the CSV puts a \' before a name Excel would run as a formula');

echo "6. Safe on the page\n";
ok(str_contains($rp, 'JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT'), 'the data cannot close the script tag');
ok(str_contains($db, "if (!is_admin() && !is_colleague() && !is_production_staff())") && str_contains($pr, "if (!is_admin() && !is_colleague() && !is_production_staff())"), 'the same people as before may open them');
ok(str_contains($js, "function esc(s)") && substr_count($js, 'innerHTML') > 5 && !preg_match('/innerHTML\s*=\s*[^;]*\b(n|name)\b\s*\+/', $js), 'names are escaped before they reach the page');

/* the CSV guard, run */
if (trim((string)@shell_exec('command -v node'))) {
    $i = strpos($js, 'function csvCell('); $fn = substr($js, $i, strpos($js, "\n", $i) - $i);
    $f = tempnam(sys_get_temp_dir(), 'cv') . '.js';
    file_put_contents($f, $fn . "\nconsole.log(JSON.stringify(['=SUM(A1)', '-5 pcs', 'Plain', 'a,b', 12.345].map(csvCell)));");
    $got = json_decode((string)shell_exec('node ' . escapeshellarg($f)), true); @unlink($f);
    ok($got === ["'=SUM(A1)", "'-5 pcs", 'Plain', '"a,b"', '12.35'], 'CSV cells, run in node: formulas defused, commas quoted, numbers kept');
}
echo "\n$P passed, $F failed\n";
exit($F ? 1 : 0);
