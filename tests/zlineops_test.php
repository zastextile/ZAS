<?php
/* ONE ORDER, ONE LINE (owner, 26 Nov 2026): skip an operation for an order or
   one line, add an extra one for an order or one line, and a rate for one PI
   line. Runs the shipped functions on plain numbers. */
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
function zp_is_cutting(string $s): bool { return strtolower($s) === 'cutting'; }
function zp_part_ops(int $pt, bool $a = false): array { return $GLOBALS['OPS'][$pt] ?? []; }
function zp_line_ctx(bool $fresh = false): array { return $GLOBALS['CTX']; }
function zp_item_order(int $it): int { return $GLOBALS['ITEMPF'][$it] ?? 0; }
function zp_order_rate_map(int $pf): array { return $GLOBALS['ORATE'][$pf] ?? []; }
function zp_pieces_needed(array $l, int $pt): float { return (float)$l['ordered_qty']; }
foreach (['zp_op_skipped', 'zp_line_ops', 'zp_line_rate_map', 'zp_part_stage_ops', 'zp_stage_pos', 'zp_stage_feeder', 'zp_stage_through', 'zp_remaining'] as $fn) eval(lift($src, $fn));

$op = fn($id, $sid, $st, $rate, $extra = null) => ['id' => $id, 'stage_id' => $sid, 'stage' => $st, 'seq' => $id, 'rate' => $rate, 'operation_name' => "op$id", 'only_proforma_id' => $extra[0] ?? null, 'only_item_id' => $extra[1] ?? 0];
$GLOBALS['OPS'][3] = [$op(9, 1, 'Cutting', 2), $op(10, 2, 'Stitching', 5), $op(11, 2, 'Stitching', 4), $op(12, 3, 'Packing', 1)];
$GLOBALS['ITEMPF'] = [1 => 100, 2 => 100, 7 => 200];
$GLOBALS['CTX'] = ['skip' => [], 'extra' => [], 'lrate' => [], 'pf' => []];
$ids = fn(array $ops) => array_map(fn($o) => (int)$o['id'], $ops);

echo "1. Nothing set: exactly the part's own operations\n";
ok($ids(zp_line_ops(1, 3)) === [9, 10, 11, 12], 'a line with no skip and no extra gets the part\'s list unchanged');

echo "2. Skip for one line\n";
$GLOBALS['CTX']['skip'][100][1][11] = true;                     // line 1 does not need the Hem
ok($ids(zp_line_ops(1, 3)) === [9, 10, 12], 'line 1 loses the skipped Hem');
ok($ids(zp_line_ops(2, 3)) === [9, 10, 11, 12], 'line 2 of the same order keeps it');
$line1 = ['item_id' => 1, 'proforma_id' => 100, 'ordered_qty' => 600];
$line2 = ['item_id' => 2, 'proforma_id' => 100, 'ordered_qty' => 600];
$prog = ['op' => [1 => [3 => [9 => 600, 10 => 600]], 2 => [3 => [9 => 600, 10 => 600]]], 'stage' => [1 => [3 => ['cut' => 600]], 2 => [3 => ['cut' => 600]]]];
ok(zp_remaining($line1, 3, 12, 'Packing', $prog) == 600, 'THE SKIPPED HEM NO LONGER HOLDS PACKING BACK on line 1: 600 can be packed');
ok(zp_remaining($line2, 3, 12, 'Packing', $prog) == 0, '  while line 2 still waits for its Hem: 0 can be packed');

echo "3. Skip for the whole order\n";
$GLOBALS['CTX']['skip'] = [100 => [0 => [11 => true]]];
ok($ids(zp_line_ops(1, 3)) === [9, 10, 12] && $ids(zp_line_ops(2, 3)) === [9, 10, 12], 'every line of order 100 loses it');
ok($ids(zp_line_ops(7, 3)) === [9, 10, 11, 12], 'another order keeps it');
ok(zp_op_skipped(100, 2, 11) && !zp_op_skipped(200, 7, 11), 'zp_op_skipped says so for the booking check');

echo "4. An extra operation\n";
$GLOBALS['CTX']['skip'] = [];
$GLOBALS['CTX']['extra'][3] = [$op(50, 3, 'Packing', 2.5, [100, 0]), $op(51, 2, 'Stitching', 3, [100, 2])];
ok($ids(zp_line_ops(1, 3)) === [9, 10, 11, 50, 12] || $ids(zp_line_ops(1, 3)) === [9, 10, 11, 12, 50], 'an extra for the whole order shows on its lines, in its stage');
ok(in_array(51, $ids(zp_line_ops(2, 3)), true) && !in_array(51, $ids(zp_line_ops(1, 3)), true), 'an extra for line 2 only is on line 2, not line 1');
ok(!in_array(50, $ids(zp_line_ops(7, 3)), true), 'and never on another order');
$GLOBALS['ITEMPF'][4] = 100; $GLOBALS['CTX']['extra'][3][] = $op(52, 2, 'Stitching', 3, [100, 4]);
$st = zp_part_stage_ops(3, 4);                                  // a line not looked at before in this run
ok(in_array(52, $st[1]['ops'], true), 'an extra Stitching operation is part of that line\'s Stitching stage (packing waits for it too)');

echo "5. A rate for one PI line\n";
$GLOBALS['ORATE'][100] = [10 => 5.5];
$GLOBALS['CTX']['lrate'][1][10] = 6.0;
ok(zp_line_rate_map(100, 1)[10] == 6.0, 'line 1 pays its own 6.00');
ok(zp_line_rate_map(100, 2)[10] == 5.5, 'line 2 pays the order\'s 5.50');

echo "6. The rest of the module\n";
ok(str_contains($src, 'WHERE part_id=? AND only_proforma_id IS NULL'), 'the part master never shows an order\'s extra operation');
ok(str_contains($src, "is skipped for this order — nothing is booked on it.") && str_contains($src, 'was added for another order and cannot be booked on this one.'), 'Production Entry refuses work on a skipped operation, or on another order\'s extra');
ok(str_contains($src, "foreach (zp_line_ops((int)\$l['item_id'], \$partId, (int)\$l['proforma_id']) as \$o) {"), 'the work list offers each line its own operations');
ok(str_contains($src, "zp_part_stage_ops(\$pt, \$it)"), 'Order Status and corrections follow each line\'s own stages');

echo "\n$P passed, $F failed\n";
exit($F ? 1 : 0);
