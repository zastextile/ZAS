<?php
/* A LIMITED QUANTITY (owner, 27 Nov 2026): an operation or set job added for
   a few pieces only — "washing the stained ones" — is offered and paid for
   that many, and never holds back the next stage. */
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
function zp_item_order(int $it): int { return 100; }
function zp_pieces_needed(array $l, int $pt): float { return (float)$l['ordered_qty']; }
foreach (['zp_op_skipped', 'zp_op_limit', 'zp_line_ops', 'zp_part_stage_ops', 'zp_stage_pos', 'zp_stage_feeder', 'zp_stage_through', 'zp_remaining'] as $fn) eval(lift($src, $fn));

$op = fn($id, $sid, $st, $lim = null, $extra = false) => ['id' => $id, 'stage_id' => $sid, 'stage' => $st, 'seq' => $id, 'rate' => 1, 'operation_name' => "op$id",
    'only_proforma_id' => $extra ? 100 : null, 'only_item_id' => $extra ? 1 : 0, 'limit_qty' => $lim];
$GLOBALS['OPS'][3] = [$op(9, 1, 'Cutting'), $op(10, 2, 'Stitching'), $op(12, 3, 'Packing')];
$GLOBALS['CTX'] = ['skip' => [], 'extra' => [3 => [$op(50, 2, 'Stitching', 50.0, true)]], 'lrate' => [], 'pf' => [], 'tier' => [], 'lim' => [50 => 50.0]];
$line = ['item_id' => 1, 'proforma_id' => 100, 'ordered_qty' => 600];
$prog = ['op' => [1 => [3 => [9 => 600, 10 => 600]]], 'stage' => [1 => [3 => ['cut' => 600]]]];

echo "1. A stain wash for 50 pieces\n";
ok(zp_remaining($line, 3, 50, 'Stitching', $prog) == 50, 'it is offered for 50, not 600');
$prog['op'][1][3][50] = 20;
ok(zp_remaining($line, 3, 50, 'Stitching', $prog) == 30, '  20 washed: 30 left');
ok(zp_remaining($line, 3, 12, 'Packing', $prog) == 600, 'PACKING DOES NOT WAIT FOR IT: all 600 stitched can be packed while only 20 are washed');
$st = zp_part_stage_ops(3, 1);
ok(!in_array(50, $st[1]['ops'], true) && in_array(10, $st[1]['ops'], true), 'it is outside the stage flow; the normal stitching is not');
ok(zp_op_limit(10) === null && zp_op_limit(50) === 50.0, 'only the added job carries a quantity');

echo "2. The same job for all pieces behaves as before\n";
$GLOBALS['CTX']['extra'][3] = [$op(51, 2, 'Stitching', null, true)]; $GLOBALS['CTX']['lim'] = [];
$line2 = ['item_id' => 2, 'proforma_id' => 100, 'ordered_qty' => 600];
$GLOBALS['CTX']['extra'][3][0]['only_item_id'] = 0;
$prog2 = ['op' => [2 => [3 => [9 => 600, 10 => 600]]], 'stage' => [2 => [3 => ['cut' => 600]]]];
ok(zp_remaining($line2, 3, 51, 'Stitching', $prog2) == 600, 'empty quantity: all 600');
ok(zp_remaining($line2, 3, 12, 'Packing', $prog2) == 0, '  and packing waits for it, as a normal operation');

echo "3. Wiring\n";
ok(str_contains($src, 'ALTER TABLE zp_part_ops ADD COLUMN limit_qty') && str_contains($src, 'ALTER TABLE zp_line_set_ops ADD COLUMN limit_qty'), 'two columns, added on their own — no SQL step');
ok(str_contains($src, "if ((!zp_is_cutting((string)\$op['stage']) || \$lim !== null) && \$qty > \$left + 0.0001)"), 'Production Entry refuses more than the quantity, even at cutting');
ok(str_contains($src, "it was added for ' . rtrim(rtrim(number_format(\$lim, 2)"), '  and says why');
ok(str_contains($src, "\$cap   = (\$found['lim'] ?? null) !== null ? min((float)\$found['lim']"), 'set work: the same for sets');
ok(str_contains($src, "pieces are already booked — the quantity cannot go below that.") && str_contains($src, "sets are already booked — the quantity cannot go below that."), 'the quantity can be changed later, never below what is booked');
ok(str_contains($src, "A quantity can be given only to an operation added for one line."), 'a part operation for the whole order has no quantity (it would be unclear per line)');
$pg = file_get_contents(__DIR__ . '/../public_html/production_order_rates.php');
ok(str_contains($pg, 'class="qt" placeholder="Qty (all)"') && str_contains($pg, 'class="qt" placeholder="Sets (all)"'), 'the page: a Qty box when adding, empty = all');
ok(str_contains($pg, 'data-pl="') && str_contains($pg, 'data-sl="'), '  and the quantity can be edited on the row');
echo "\n$P passed, $F failed\n";
exit($F ? 1 : 0);
