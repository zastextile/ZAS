<?php
/* Order Status (owner, 22 Nov 2026): each stage counts the pieces that have
   been through EVERY operation of it; overall is the last stage. Lifts the
   measuring functions out of the module and runs them on plain numbers. */
$P = 0; $F = 0;
function ok($c, $m) { global $P, $F; if ($c) { $P++; echo "  ok  $m\n"; } else { $F++; echo "  FAIL: $m\n"; } }
function lift(string $src, string $fn): string {
    $i = strpos($src, "function $fn("); if ($i === false) { echo "missing $fn\n"; exit(1); }
    $a = strpos($src, '{', $i); $d = 0;
    for ($j = $a; $j < strlen($src); $j++) { if ($src[$j] === '{') $d++; elseif ($src[$j] === '}') { $d--; if ($d === 0) return substr($src, $i, $j - $i + 1); } }
    exit(1);
}
$src = file_get_contents(__DIR__ . '/../public_html/includes/zprod.php');
define('ZP_NO_SIZE', -1.0);
function zp_stage_all(bool $a = false): array { return [['id' => 1, 'name' => 'Cutting'], ['id' => 2, 'name' => 'Stitching'], ['id' => 3, 'name' => 'Packing']]; }
function zp_part_ops(int $pt, bool $a = false): array { return $GLOBALS['OPS'][$pt] ?? []; }
function zp_product_parts(int $pid): array { return $GLOBALS['PARTS'][$pid] ?? []; }
function zp_pieces_needed(array $l, int $pt): float { return (float)$l['ordered_qty'] * ($GLOBALS['PER'][$pt] ?? 1); }
foreach (['zp_part_stage_ops', 'zp_stage_through', 'zp_line_flow'] as $fn) eval(lift($src, $fn));

/* a pillow cover: cut; overlock + hem; pack */
$GLOBALS['PARTS'][6] = [['id' => 3, 'part_name' => 'Pillow Cover']];
$GLOBALS['OPS'][3] = [['id' => 9, 'stage_id' => 1, 'stage' => 'Cutting'], ['id' => 10, 'stage_id' => 2, 'stage' => 'Stitching'],
                      ['id' => 11, 'stage_id' => 2, 'stage' => 'Stitching'], ['id' => 12, 'stage_id' => 3, 'stage' => 'Packing']];
$line = ['item_id' => 1, 'product_id' => 6, 'ordered_qty' => 600];
$prog = ['op' => [1 => [3 => [9 => 600, 10 => 600, 11 => 300, 12 => 200]]]];
$f = zp_line_flow($line, $prog);

echo "1. A stage counts pieces through EVERY operation\n";
ok($f['stages'][0]['done'] == 600 && $f['stages'][0]['need'] == 600, 'cutting 600 of 600');
ok($f['stages'][1]['done'] == 300, 'stitching is 300 — Overlock 600 and Hem 300 is 300 pieces sewn, not 900');
ok($f['stages'][2]['done'] == 200, 'packing 200');
ok($f['pct'] == 33.3, 'overall is the LAST stage: 200 / 600 = 33.3%, got ' . $f['pct']);
ok($f['behind'] === 'Pillow Cover (Stitching)', 'waiting on the earliest stage still open: ' . $f['behind']);

echo "2. Never more than the order needs\n";
$prog2 = ['op' => [1 => [3 => [9 => 700, 10 => 700, 11 => 700, 12 => 650]]]];
$f2 = zp_line_flow($line, $prog2);
ok($f2['stages'][0]['done'] == 600 && $f2['pct'] == 100.0, 'over-booking is capped at what is needed: 100%');
ok($f2['behind'] === '', 'a finished line waits on nothing');

echo "3. A set: every part counts\n";
$GLOBALS['PARTS'][9] = [['id' => 3, 'part_name' => 'Pillow Cover'], ['id' => 5, 'part_name' => 'Sheet']];
$GLOBALS['OPS'][5] = [['id' => 20, 'stage_id' => 1, 'stage' => 'Cutting'], ['id' => 21, 'stage_id' => 3, 'stage' => 'Packing']];
$GLOBALS['PER'] = [3 => 2, 5 => 1];          // two pillow covers and one sheet per set
$set = ['item_id' => 2, 'product_id' => 9, 'ordered_qty' => 100];
$f3 = zp_line_flow($set, ['op' => [2 => [3 => [9 => 200], 5 => [20 => 50]]]]);
ok($f3['stages'][0]['need'] == 300 && $f3['stages'][0]['done'] == 250, 'cutting needs 200 covers + 100 sheets; 250 done');
ok(!isset($f3['stages'][1]['need']) || $f3['stages'][1]['need'] == 200, 'stitching counts only the part that is stitched');
ok($f3['behind'] === 'Sheet (Cutting)', 'the slowest part at the earliest open stage: ' . $f3['behind']);

echo "4. The pages\n";
$pg = file_get_contents(__DIR__ . '/../public_html/production_orders.php');
ok(!preg_match('/INSERT|UPDATE|DELETE/i', preg_replace('!/\*.*?\*/!s', '', $pg)), 'Order Status writes nothing');
ok(str_contains($pg, 'JSON_HEX_TAG | JSON_HEX_AMP'), 'its data cannot close the script tag');
ok(str_contains($pg, 'if (!is_admin() && !is_colleague() && !is_production_staff())'), 'the same people as the dashboard may open it');
ok(str_contains($pg, '$showMoney ? ') || str_contains($pg, 'if ($showMoney)'), 'wages only for people allowed to see rates');
$db = file_get_contents(__DIR__ . '/../public_html/production_dashboard.php');
ok(str_contains($db, '.zd-wrap table.zp-t{min-width:0}'), 'the dashboard tables fit their cards');
ok(str_contains($src, "\$f = zp_line_flow(\$l, \$prog);\n        \$first"), 'the dashboard measures orders the same way as the report');

echo "\n$P passed, $F failed\n";
exit($F ? 1 : 0);
