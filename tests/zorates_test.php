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

echo "2. The server decides what may be saved (26 Nov 2026: one save for every kind of change)\n";
$zp = file_get_contents(__DIR__ . '/../public_html/includes/zprod.php');
$save = substr($zp, $i = strpos($zp, 'function zp_order_sheet_save('), strpos($zp, 'function zp_ops_for_order(') - $i);
ok(str_contains($s, "zp_order_sheet_save((int)(\$_POST['proforma_id'] ?? 0), \$ch, (string)(\$_POST['reason'] ?? ''), \$userId)"), 'the page hands every change to the module\'s one writer');
ok(str_contains($save, "\$sheet = zp_order_sheet(\$pf);") && str_contains($save, "if (!isset(\$opInfo[\$op])) { \$bad[] = 'An operation that is not on this order was sent.'"), 'the allowed operations come from THIS order, read on the server');
ok(str_contains($save, "if (!isset(\$lineOp[\$k])) { \$bad[] = 'An operation that is not on that line was sent.'"), '  and a line rate only for an operation that line really has');
ok(str_contains($save, "(float)\$v >= 0 && (float)\$v <= 100000"), 'a negative or absurd rate is refused');
ok(str_contains($save, "if (\$reason === '') return"), 'a reason is required');
ok(str_contains($save, "Only an operation added for this order can be removed"), 'only an added operation can be removed; a normal one is skipped instead');
ok(str_contains($save, "A new operation must be on a part this"), 'a new operation must be on a part the order (or that line) makes');

echo "3. All or nothing\n";
ok(str_contains($save, 'db()->beginTransaction();') && str_contains($save, 'db()->rollBack();') && str_contains($save, 'db()->commit();'), 'the changes are saved in one transaction');
ok(str_contains($save, "if (!\$r['ok']) throw new RuntimeException"), '  and one refused change undoes the rest');
ok(substr_count($save, 'zp_rate_log(') >= 5, 'every kind of change is written to the history with its reason');

echo "4. The page does the work in the browser\n";
ok(str_contains($s, 'JSON_HEX_TAG | JSON_HEX_AMP'), 'the data handed to the browser cannot close the script tag');
ok(substr_count($code, 'fetch(') === 1 && str_contains($code, "fd.append('action', 'save_json')"), 'ONE request, and only on Save: filtering, searching, typing and show/hide ask the server nothing');
ok(!str_contains($code, 'location.reload') && !str_contains($code, 'f.submit()'), 'saving does not reload the page');
ok(str_contains($s, "window.addEventListener('beforeunload'"), 'leaving with unsaved changes asks first');
ok(str_contains($s, "<div class=\"zskin\">") && substr_count($s, 'closes .zskin') === 1, 'it stays inside the module skin, like every production page');
ok(str_contains($s, 'data-v="op"') && str_contains($s, 'data-v="line"') && str_contains($s, 'id="orOpen"') && str_contains($s, 'id="orShut"'), 'two views — by operation and by PI line — and Show all / Hide all');

echo "\n$P passed, $F failed\n";
exit($F ? 1 : 0);
