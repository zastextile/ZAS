<?php
/* ONE GATE PASS, BOTH KINDS OF THING.
 *
 * "can u check as gate in and out we can do any thing like material and or
 *  finish product and also same gate in or our can use both things"
 *
 * The answer is yes, and it always was — but checking it turned up a hole
 * that had been open the whole time: the outward stock check skipped every
 * finished-goods line. A Sale of 500 sets posted cleanly when 180 existed,
 * the ledger went negative, and nothing on any screen said a word.
 */
$B = __DIR__ . '/app_src/public_html/';
$P = 0; $F = 0;
function ok($c, $m) { global $P, $F; if ($c) { $P++; } else { $F++; echo "  FAIL: $m\n"; } }
$inv = file_get_contents($B . 'includes/inventory.php');

function lift(string $src, string $from): string {
    $a = strpos($src, $from); if ($a === false) return '';
    $b = strpos($src, "\n}\n", $a); return substr($src, $a, $b - $a + 3);
}

echo "1. The old skip is gone\n";
$invC = preg_replace('!/\*.*?\*/!s', '', $inv);       // comments quote the old line on purpose
ok(!str_contains($invC, "if (\$qty <= 0 || !\$it['material_id']) continue;   // finished goods"),
   'the "finished goods skip" line is gone');
ok(str_contains($invC, 'function inv_outward_check_key('),
   'and there is a check that takes an item key, not only a material id');
ok(substr_count($invC, 'inv_outward_check_key(') >= 2, '  and the post actually calls it');
ok(str_contains($invC, "function inv_outward_check(int \$materialId"),
   'the old material-only one is kept for the screens that call it');

echo "2. The key-based check, run for real\n";
/* A material with 1,840 metres, and a product with 180 King sets and 60
   Queen — the shape a finished-goods store really has. */
$LOTS = [
  'm12' => [
    ['lot_no'=>'L-1','location_id'=>1,'ownership'=>'own','bal'=>1000.0],
    ['lot_no'=>'L-2','location_id'=>1,'ownership'=>'own','bal'=>840.0],
  ],
  'p7' => [
    ['lot_no'=>'King', 'location_id'=>2,'ownership'=>'own','bal'=>180.0],
    ['lot_no'=>'Queen','location_id'=>2,'ownership'=>'own','bal'=>60.0],
  ],
];
function inv_lot_balances_key(string $k, bool $pos = true): array { global $LOTS; return $LOTS[$k] ?? []; }
function inv_neg_tolerance_pct(): float { return 2.0; }
eval(lift($inv, 'function inv_available_key('));
eval(lift($inv, 'function inv_outward_check_key('));

$c = inv_outward_check_key('m12', '', 1, 'own', 1500);
ok($c['level'] === 'ok', 'a material within its balance passes');
$c = inv_outward_check_key('m12', 'L-1', 1, 'own', 1500);
ok($c['level'] !== 'ok' && $c['bal'] === 1000.0,
   'AND A LOT IS STILL A LOT — 1,500 out of a 1,000 lot is short: ' . json_encode($c));

/* THE ONE THAT WAS NEVER CHECKED BEFORE. */
$c = inv_outward_check_key('p7', 'King', 2, 'own', 180);
ok($c['level'] === 'ok', 'exactly what is packed goes out fine');
$c = inv_outward_check_key('p7', 'King', 2, 'own', 500);
ok($c['level'] === 'admin_only' && $c['bal'] === 180.0 && $c['short'] === 320.0,
   'A FINISHED PRODUCT IS NOW CHECKED: 500 against 180 is 320 short: ' . json_encode($c));
$c = inv_outward_check_key('p7', 'King', 2, 'own', 183);
ok($c['level'] === 'tolerance',
   '  and a little over is the tolerance band, not a refusal: ' . json_encode($c));

/* THE SIZE IS THE SUB-KEY ON A PRODUCT. Sending Queen sets must not be
   measured against the King pile. */
$c = inv_outward_check_key('p7', 'Queen', 2, 'own', 100);
ok($c['bal'] === 60.0, 'SIZES DO NOT SHARE A BALANCE — Queen is measured on its own 60: ' . json_encode($c));
$c = inv_outward_check_key('p7', '', 2, 'own', 100);
ok($c['bal'] === 240.0, 'with no size given, the whole product counts, got ' . $c['bal']);
$c = inv_outward_check_key('p7', 'King', 1, 'own', 10);
ok($c['bal'] === 0.0, 'and a store the sets are not in has none of them');

echo "3. A pass may carry both, and the post treats each line on its merits\n";
ok(str_contains($invC, "\$key = !empty(\$it['material_id']) ? 'm' . (int)\$it['material_id']"),
   'the key is built per LINE, so one pass can mix the two');
ok(str_contains($invC, "\$sub = !empty(\$it['material_id']) ? (string)(\$it['lot_no'] ?? '')"),
   '  each with the right sub-key: a lot on a material, a size on a product');
/* The ledger side always handled both — that is why the hole was invisible. */
ok(str_contains($invC, "'material_id' => \$it['material_id'] ?: null,")
   && str_contains($invC, "'product_id'  => \$it['product_id'] ?: null,"),
   'the ledger row carries whichever the line is');
ok(str_contains($invC, "'size_label'  => \$it['size_label'] ?? null,"),
   '  and a product keeps its size all the way to the ledger');

echo "4. Names in the message, for both kinds\n";
ok(str_contains($invC, '$PRODNAME = [];'), 'products are named too');
ok(str_contains($invC, '$lineName = function (array $it)'), 'one namer for either kind');
$invF = preg_replace('/\s+/', ' ', $inv);
ok(str_contains($invF, 'a King set and a Queen set are different stock'),
   '  and a product is named with its size');

echo "5. The return check stays materials-only, on purpose\n";
ok(str_contains($invF, 'MATERIALS ONLY, AND THAT IS DELIBERATE'),
   'the remaining skip says why it is there');
ok(str_contains($invF, 'nobody sends a finished product out for processing and gets the same product back'),
   '  so nobody later "fixes" it into a bug');

echo "\n$P passed, $F failed\n";
exit($F ? 1 : 0);
