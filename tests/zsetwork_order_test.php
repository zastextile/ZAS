<?php
/* SET / PACK WORK ON THE ORDER RATES PAGE (owner, 26 Nov 2026): per set, per
   line — change a rate, skip it on a line, add a job to the ticked lines.
   Runs the shipped count on plain numbers, then checks the wiring. */
$P = 0; $F = 0;
function ok($c, $m) { global $P, $F; if ($c) { $P++; echo "  ok  $m\n"; } else { $F++; echo "  FAIL: $m\n"; } }
function lift(string $src, string $fn): string {
    $i = strpos($src, "function $fn("); if ($i === false) { echo "missing $fn\n"; exit(1); }
    $a = strpos($src, '{', $i); $d = 0;
    for ($j = $a; $j < strlen($src); $j++) { if ($src[$j] === '{') $d++; elseif ($src[$j] === '}') { $d--; if ($d === 0) return substr($src, $i, $j - $i + 1); } }
    exit(1);
}
$src = file_get_contents(__DIR__ . '/../public_html/includes/zprod.php');
eval(lift($src, 'zp_set_done'));

echo "1. Sets already made are never forgotten\n";
$prog = ['set' => [8502 => ['set' => [2 => 100.0], 'line' => [12 => 50.0]]]];
ok(zp_set_done($prog, 8502, ['kind' => 'set', 'id' => 2]) == 100, 'a line on the product list: 100 cartons made');
ok(zp_set_done($prog, 8502, ['kind' => 'line', 'id' => 12, 'set_op_id' => 2]) == 150,
   'AFTER THE LINE TAKES ITS OWN LIST, the 100 made before still count (100 + 50 = 150) — the same sets cannot be paid twice');
ok(zp_set_done($prog, 8502, ['kind' => 'line', 'id' => 13, 'set_op_id' => 0]) == 0, 'a job added for this order starts at 0');
ok(zp_set_done($prog, 8503, ['kind' => 'set', 'id' => 2]) == 0, 'another line counts on its own');

echo "2. Booking and the work list use that count\n";
ok(str_contains($src, '$done  = zp_set_done($prog, $itemId, $found);'), 'Production Entry: the ceiling counts both');
ok(str_contains($src, '$done = zp_set_done($prog, (int)$l[\'item_id\'], $w);'), 'the work list: "left" counts both');

echo "3. The save\n";
$i = strpos($src, 'function zp_order_sheet_save('); $save = substr($src, $i, strpos($src, 'function zp_ops_for_order(') - $i);
ok(str_contains($save, "if (!isset(\$setRow[\$k])) { \$bad[] = 'A set job that is not on that line was sent.'"), 'only a set job that line really has can be changed');
ok(str_contains($save, "array_diff(\$items, array_keys(\$lineById))"), 'new set work only for lines of THIS order');
ok(str_contains($save, "zp_line_set_own(\$it, (int)\$ln['product_id'], zp_line_size_id(\$ln))"), 'a line takes its own copy before it is changed — the product and other orders stay as they are');
ok(str_contains($save, "SET is_active=?") && !preg_match('/DELETE FROM zp_line_set_ops/', $save), 'a skip switches the job off, never deletes it (it can be switched back on)');
ok(substr_count($save, "zp_rate_log(null, \$pf, 'Set work'") === 4, 'every set change (add, rate, skip, quantity) goes into the history with its reason');
$own = lift($src, 'zp_line_set_own');
ok(str_contains($own, 'zp_line_take_set_copy(') && str_contains($own, "':set:' . (int)\$r['set_op_id']"), 'a change typed on the product\'s job finds the same job on the line\'s new copy');

echo "4. The page\n";
$pg = file_get_contents(__DIR__ . '/../public_html/production_order_rates.php');
ok(str_contains($pg, 'Set / pack work<small>per set · '), 'each PI line has its Set / pack work box');
ok(str_contains($pg, 'data-sn="') && str_contains($pg, 'Typing here fills every line that has this job'), 'By operation: one rate fills every line that has the job');
ok(str_contains($pg, 'data-tick="all"') && str_contains($pg, 'data-tick="same"'), 'adding: tick lines, All lines, Same product');
ok(str_contains($pg, "sets: setsOut, sadd: P.sadd.map("), 'sent with the same one Save');
ok(str_contains($pg, "per set"), 'always shown as per set');

echo "\n$P passed, $F failed\n";
exit($F ? 1 : 0);
