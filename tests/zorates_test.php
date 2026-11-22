<?php
/* Order rates, rebuilt (owner, 22 Nov 2026): an edit table that saves many
   rates under one reason. These are the rules that keep it safe. */
$P = 0; $F = 0;
function ok($c, $m) { global $P, $F; if ($c) { $P++; echo "  ok  $m\n"; } else { $F++; echo "  FAIL: $m\n"; } }
$s = file_get_contents(__DIR__ . '/../public_html/production_order_rates.php');
$code = preg_replace('!/\*.*?\*/!s', ' ', $s);

echo "1. Who may change a rate\n";
ok(str_contains($s, 'verify_csrf();'), 'every save carries the form token');
ok(str_contains($s, '$canEdit = is_admin();') && str_contains($s, "if (!\$canEdit) {"), 'only an admin may save; a colleague reads');
ok(str_contains($s, "if (!is_admin() && !is_colleague())"), 'nobody else opens the page');

echo "2. The server decides what may be saved\n";
ok(str_contains($s, 'foreach (zp_ops_for_order($pfId) as $o) $okIds[(int)$o[\'id\']]'), 'the allowed operations come from THIS order, on the server');
ok(str_contains($s, "if (!isset(\$okIds[\$oid])) { \$bad[] = 'An operation that is not on this order was sent.'"), '  and anything else the browser sends is refused');
ok(str_contains($s, "(float)\$v < 0 || (float)\$v > 100000"), 'a negative or absurd rate is refused');
ok(str_contains($s, "elseif (\$reason === '')"), 'a reason is required');
ok(str_contains($s, "if (!\$isOrder)"), 'the order must be switched on for production');

echo "3. All or nothing\n";
ok(str_contains($s, 'db()->beginTransaction();') && str_contains($s, 'db()->rollBack();') && str_contains($s, 'db()->commit();'), 'the changes are saved in one transaction');
ok(str_contains($s, "if (!\$r['ok']) throw new RuntimeException"), '  and one refused line undoes the rest');
ok(substr_count($code, 'zp_order_rate_save(') === 1 && substr_count($code, 'zp_order_rate_clear(') === 1, 'every change goes through the module\'s own writers, which log it');

echo "4. The page does the work in the browser\n";
ok(str_contains($s, 'JSON_HEX_TAG | JSON_HEX_AMP'), 'the data handed to the browser cannot close the script tag');
ok(!preg_match('/fetch\(|XMLHttpRequest/', $code), 'no request to the server while filtering, searching or typing');
ok(str_contains($s, 'window.onbeforeunload'), 'leaving with unsaved changes asks first');
ok(str_contains($s, "<div class=\"zskin\">") && substr_count($s, 'closes .zskin') === 1, 'it stays inside the module skin, like every production page');

echo "\n$P passed, $F failed\n";
exit($F ? 1 : 0);
