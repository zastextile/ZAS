<?php
/* Correct Entries (rebuilt 22 Nov 2026) and the shared booked-work helpers. */
$P = 0; $F = 0;
function ok($c, $m) { global $P, $F; if ($c) { $P++; echo "  ok  $m\n"; } else { $F++; echo "  FAIL: $m\n"; } }
$pg = file_get_contents(__DIR__ . '/../public_html/production_amend.php');
$zc = file_get_contents(__DIR__ . '/../public_html/includes/zp_correct.php');
$pe = file_get_contents(__DIR__ . '/../public_html/production_entry.php');
$code = fn($s) => preg_replace('!/\*.*?\*/!s', ' ', $s);

echo "1. One set of rules for both pages\n";
ok(str_contains($pg, "require_once __DIR__ . '/includes/zp_correct.php';") && str_contains($pe, "require_once __DIR__ . '/includes/zp_correct.php';"), 'both pages load the same helpers');
ok(!str_contains($code($pe), 'function ze_edit_row(') && !str_contains($code($pg), 'function ze_edit_row('), 'neither page keeps its own copy of Edit');
ok(substr_count($code($zc), 'zp_book(') === 1 && !preg_match('/INSERT\s+INTO\s+zp_entries/i', $code($zc . $pg)), 'every re-booking goes through zp_book');

echo "2. Who and how\n";
ok(str_contains($pg, "if (!is_admin() && !is_colleague())"), 'admin and colleague may use it, nobody else');
ok(str_contains($pg, 'verify_csrf();'), 'every change carries the form token');
ok(str_contains($pg, "ze_cancel_all(\$ids, (string)(\$_POST['reason'] ?? ''), \$userId)"), 'bulk cancel goes through the all-or-nothing helper');
ok(str_contains($zc, "if (!\$r['ok']) { ze_restore(\$done);"), '  which puts every line back when one is refused');
ok(str_contains($pg, 'usort($ids, fn($x, $y) => ($pos[$y] ?? 0) <=> ($pos[$x] ?? 0)'), 'later stages are cancelled before earlier ones');
$zp = file_get_contents(__DIR__ . '/../public_html/includes/zprod.php');
ok(str_contains($zc, "if (\$why === '')") && str_contains($zp, "if (\$reason === '') return ['ok' => false, 'error' => 'Give a reason"), 'a reason is required to edit and to cancel');

echo "3. Edit keeps what was really booked\n";
ok(str_contains($zc, 'foreach ($old as $o) {') && str_contains($zc, '$keep[$k] = ($keep[$k] ?? 0) + $take;'), 'the old lines are re-booked as they were');
ok(str_contains($zc, "[\$add, \$rest] = ze_alloc(\$grp['mem'], \$rem, !empty(\$grp['open']), \$keep);"), 'only a higher quantity looks for room, split by ordered %');
ok(str_contains($zc, "if (\$exact) {"), 'the every-line view edits one line on its own size and operation');
ok(str_contains($zc, "if (\$bookOn > date('Y-m-d'))"), 'a date can move, never into the future');
ok(str_contains($zc, "\$o['entry_date'] !== \$date"), 'an edit only touches lines of the day shown');
ok(str_contains($zc, 'zp_flow_problem($ti, $tp, $prog0) === \'\''), 'the result is checked for stage order, and undone if this edit broke it');

echo "4. The page\n";
ok(str_contains($pg, 'JSON_HEX_TAG | JSON_HEX_AMP'), 'its data cannot close the script tag');
ok(str_contains($pg, "if (/^[=+\\-@]/.test(v) && isNaN(+v))"), 'the Excel file cannot carry a formula');
ok(str_contains($pg, '92 * 86400'), 'at most about three months are loaded at once');
ok(str_contains($pg, '<div class="zskin">') && substr_count($pg, 'closes .zskin') === 1, 'it stays inside the module skin');

echo "\n$P passed, $F failed\n";
exit($F ? 1 : 0);
