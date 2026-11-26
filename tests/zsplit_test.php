<?php
/* A SPLIT RATE (owner, 26 Nov 2026): "order is 1500, we pay 4 for the first
   1000 pcs and 4.25 for the other 500". Runs the shipped functions on plain
   numbers, then the booking arithmetic the way zp_book() uses it. */
$P = 0; $F = 0;
function ok($c, $m) { global $P, $F; if ($c) { $P++; echo "  ok  $m\n"; } else { $F++; echo "  FAIL: $m\n"; } }
function lift(string $src, string $fn): string {
    $i = strpos($src, "function $fn("); if ($i === false) { echo "missing $fn\n"; exit(1); }
    $a = strpos($src, '{', $i); $d = 0;
    for ($j = $a; $j < strlen($src); $j++) { if ($src[$j] === '{') $d++; elseif ($src[$j] === '}') { $d--; if ($d === 0) return substr($src, $i, $j - $i + 1); } }
    exit(1);
}
$src = file_get_contents(__DIR__ . '/../public_html/includes/zprod.php');
function zp_line_ctx(bool $fresh = false): array { return $GLOBALS['CTX']; }
function zp_open_lines(): array { return $GLOBALS['LINES']; }
foreach (['zp_tiers_for', 'zp_scope_done', 'zp_tier_split', 'zp_tier_rate_now'] as $fn) eval(lift($src, $fn));

$T = [[1000.0, 4.0], [null, 4.25]];
$sum = fn(array $p) => array_sum(array_map(fn($x) => $x[0] * $x[1], $p));

echo "1. The owner's example: 1,500 pieces, first 1,000 at 4.00, the rest at 4.25\n";
ok(zp_tier_split($T, 0, 1500) == [[1000, 4.0], [500, 4.25]], 'all 1,500 in one go: 1,000 @ 4.00 and 500 @ 4.25');
ok(abs($sum(zp_tier_split($T, 0, 1500)) - 6125) < 0.001, '  = 4,000 + 2,125 = 6,125');
ok(zp_tier_split($T, 0, 300) == [[300, 4.0]], 'the first 300: all at 4.00');
ok(zp_tier_split($T, 900, 300) == [[100, 4.0], [200, 4.25]], 'A BOOKING THAT CROSSES THE STEP is split: 900 made, 300 more = 100 @ 4.00 + 200 @ 4.25');
ok(zp_tier_split($T, 1000, 200) == [[200, 4.25]], 'after 1,000 everything pays 4.25');
ok(zp_tier_split($T, 1400, 300) == [[300, 4.25]], 'the last step has no end — extra pieces still pay 4.25');
ok(zp_tier_rate_now($T, 0) == 4.0 && zp_tier_rate_now($T, 999) == 4.0 && zp_tier_rate_now($T, 1000) == 4.25, 'the work list shows the rate the NEXT piece pays (4.00 until 1,000 are made, then 4.25)');

echo "2. Three steps\n";
$T3 = [[500.0, 3.0], [500.0, 3.5], [null, 4.0]];
ok(zp_tier_split($T3, 400, 700) == [[100, 3.0], [500, 3.5], [100, 4.0]], 'one booking can cross two steps: 100 @ 3.00, 500 @ 3.50, 100 @ 4.00');
ok(zp_tier_rate_now($T3, 1000) == 4.0, 'after 1,000 the third rate');

echo "3. Which split applies: line, else order — and a line's own flat rate beats the order's split\n";
$GLOBALS['CTX'] = ['skip' => [], 'extra' => [], 'lrate' => [], 'pf' => [], 'tier' => [100 => [0 => [10 => $T], 2 => [10 => [[200.0, 5.0], [null, 5.5]]]]]];
ok(zp_tiers_for(100, 1, 10) === ['order', $T], 'line 1 follows the order split, counted on the whole order');
ok(zp_tiers_for(100, 2, 10)[0] === 'line', 'line 2 has its own split');
ok(zp_tiers_for(100, 1, 11) === null && zp_tiers_for(200, 7, 10) === null, 'another operation or order: no split');
$GLOBALS['CTX']['lrate'][3][10] = 4.5;
ok(zp_tiers_for(100, 3, 10) === null, 'a line with its own flat 4.50 is not pulled into the order split');

echo "4. The running count\n";
$GLOBALS['LINES'] = [['proforma_id' => 100, 'item_id' => 1], ['proforma_id' => 100, 'item_id' => 2], ['proforma_id' => 200, 'item_id' => 7]];
$prog = ['op' => [1 => [3 => [10 => 600]], 2 => [3 => [10 => 300], 4 => [10 => 0]], 7 => [3 => [10 => 999]]]];
ok(zp_scope_done($prog, 100, 1, 10, 'line') == 600, 'one line: what that line has made');
ok(zp_scope_done($prog, 100, 1, 10, 'order') == 900, 'the order: every line of it (600 + 300), never another order (999)');

echo "5. One production sheet, three rows, like zp_book()\n";
/* the same loop shape as zp_book(): done so far + earlier rows of this sheet */
$book = function (array $rows, float $done) use ($T) {
    $out = []; $batch = 0.0;
    foreach ($rows as $q) { foreach (zp_tier_split($T, $done + $batch, $q) as [$pq, $r]) $out[] = [$pq, $r, round($pq * $r, 2)]; $batch += $q; }
    return $out;
};
$e = $book([500, 400, 300], 0);
ok($e == [[500, 4.0, 2000.0], [400, 4.0, 1600.0], [100, 4.0, 400.0], [200, 4.25, 850.0]], 'rows of the same sheet count on each other: 500 + 400 + (100 @ 4.00 + 200 @ 4.25)');
ok(array_sum(array_column($e, 0)) == 1200, 'no piece is lost or counted twice in the split');

echo "6. Wired in\n";
ok(str_contains($src, 'CREATE TABLE IF NOT EXISTS zp_rate_tiers'), 'the table is made on its own — no SQL step to run');
ok(str_contains($src, "foreach (\$pieces as [\$pq, \$rate]) \$ready[] = ["), 'Production Entry books one entry per step crossed');
ok(str_contains($src, "\$tierBatch[\$sk] = (\$tierBatch[\$sk] ?? 0.0) + \$qty;"), '  counting earlier rows of the same sheet');
ok(substr_count($src, 'zp_tier_rate_now($tier[1], zp_scope_done(') === 1, 'the work list quotes the rate the next piece pays');
ok(str_contains($src, "'SPLIT for '") && str_contains($src, "'Split taken off for '"), 'every split goes into the rate history with its reason');
ok(str_contains($src, "A split needs 2 to 6 steps."), 'a split with one step is refused');
$mw = file_get_contents(__DIR__ . '/../public_html/production_my_work.php');
ok(str_contains($mw, 'zp_tier_rate_now('), 'My Work shows the same rate');
$pg = file_get_contents(__DIR__ . '/../public_html/production_order_rates.php');
ok(str_contains($pg, 'function tsplit(') && str_contains($pg, 'data-sp="'), 'Order rates: the Split toggle on each operation');
ok(str_contains($pg, 'Produced <b>') && str_contains($pg, 'Balance <b>'), '  shows produced and balance');
ok(!preg_match('/fetch\([^)]*\)/', substr($pg, strpos($pg, 'function spPanel'), 4000)), '  opening the panel asks the server nothing');

/* the page's arithmetic and the server's give the same answer */
if (trim((string)@shell_exec('command -v node'))) {
    $js = substr($pg, strpos($pg, 'function tsplit('));
    $js = substr($js, 0, strpos($js, 'function tcost('));
    $cases = [[[[1000, 4], [null, 4.25]], 900, 300], [[[500, 3], [500, 3.5], [null, 4]], 400, 700], [[[1000, 4], [null, 4.25]], 0, 1500]];
    $run = $js . 'console.log(JSON.stringify(' . json_encode($cases) . '.map(function(c){ return tsplit(c[0], c[1], c[2]); })));';
    $f = tempnam(sys_get_temp_dir(), 'sp') . '.js'; file_put_contents($f, $run);
    $got = json_decode((string)shell_exec('node ' . escapeshellarg($f)), true); @unlink($f);
    $want = array_map(fn($c) => zp_tier_split(array_map(fn($x) => [$x[0] === null ? null : (float)$x[0], (float)$x[1]], $c[0]), $c[1], $c[2]), $cases);
    ok($got == $want, 'THE PAGE AND THE SERVER SPLIT THE SAME WAY (3 cases, run in node)');
}

echo "\n$P passed, $F failed\n";
exit($F ? 1 : 0);
