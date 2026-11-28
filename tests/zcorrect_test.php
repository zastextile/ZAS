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


echo "5. The Excel-style grid (28 Nov 2026)\n";
ok(str_contains($pg, "if (\$_SERVER['REQUEST_METHOD'] === 'POST' && (\$_POST['action'] ?? '') === 'save_grid') {") && substr_count($pg, 'verify_csrf();') >= 2, 'one save for the whole grid, with the form token');
ok(str_contains($pg, "ze_edit_row(\$t['ids'], \$t['d'], \$t['qty'], \$t['wid'], \$why, \$userId, \$t['nd'], \$t['exact'], \$t['rate'])"), '  every changed row through the same ze_edit_row');
ok(str_contains($pg, "if (\$why === '') { echo json_encode(['ok' => false"), '  under one reason, required');
ok(str_contains($pg, "usort(\$todo, fn(\$a, \$b) => (\$b['down'] <=> \$a['down'])"), '  lower quantities first (later stages first), then the rest (earlier stages first)');
ok(str_contains($pg, '$canRate = $showMoney && is_admin();') && str_contains($pg, "if (\$t['rate'] !== null && !\$canRate)"), 'only an admin may correct a rate');
ok(str_contains($zc, '$rateNow = $newRate !== null ? round($newRate, 2) : $wasRate;') && str_contains($zc, "UPDATE zp_entries SET rate_applied=?, amount=ROUND(qty*?, 2) WHERE note=? AND status='active'"), 'the paid rate stays unless the rate itself is corrected');
ok(str_contains($zc, ", rate ' . number_format(\$wasRate, 2) . ' → '"), '  and a rate change is written into the reason on the old line');
ok(str_contains($pg, "if (k === 'ArrowDown' || k === 'ArrowUp')") && str_contains($pg, "else if (k === 'Tab')") && str_contains($pg, "e.key === 'Enter') { e.preventDefault(); commit(); move(1, 0); }"), 'arrows, Tab and Enter move between cells');
ok(str_contains($pg, "document.addEventListener('paste'") && str_contains($pg, "(k === 'd' || k === 'D')"), 'paste from Excel and Ctrl+D copy-from-above');
$js = substr($pg, strpos($pg, '/* ============ THE GRID'));
ok(substr_count($js, 'fetch(') === 1, 'the grid asks the server nothing until Save');
ok(str_contains($pg, "window.addEventListener('beforeunload', guard);"), 'leaving with unsaved changes asks first');

ok(str_contains($pg, "return ['max' => round(\$q + \$LEFT[\$k], 2)") && str_contains($pg, "return ['max' => round(\$q + \$GRP[\$k]['left'], 2)"), 'each row shows its max: its own qty + what is left (one size, or the whole merged job)');
ok(str_contains($pg, "if (\$OPEN[\$k]) return ['max' => null") && str_contains($pg, "if (\$GRP[\$k]['open']) return ['max' => null"), '  cutting is open — no max');
ok(str_contains($pg, "if (!isset(\$LEFT[\$k])) return ['max' => \$q"), '  a job no longer on the list cannot go up');
ok(str_contains($pg, 'function maxFor(r){') && str_contains($pg, "if (!o || o.jk !== r.jk) return;"), '  rows of the same job share what is left');
ok(str_contains($pg, "'Too many — the most this row can be is '"), '  above it the cell is red and Save waits');

echo "\n$P passed, $F failed\n";
exit($F ? 1 : 0);
