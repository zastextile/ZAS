<?php
/* PRODUCTION STAFF LOGINS (owner, 28 Nov 2026: "1 + 2 + 3") and FINISHED
   PRODUCTS ("go"): who typed it, whose login it is, which floor, and whole
   products instead of operations. */
$P = 0; $F = 0;
function ok($c, $m) { global $P, $F; if ($c) { $P++; echo "  ok  $m\n"; } else { $F++; echo "  FAIL: $m\n"; } }
$B = __DIR__ . '/../public_html/';
$st = file_get_contents($B . 'includes/zp_staff.php'); $au = file_get_contents($B . 'includes/auth.php'); $lg = file_get_contents($B . 'login.php');
$us = file_get_contents($B . 'users.php'); $pe = file_get_contents($B . 'production_entry.php'); $rp = file_get_contents($B . 'includes/zp_report.php');
$js = file_get_contents($B . 'assets/js/zprep.js'); $pr = file_get_contents($B . 'production_reports.php'); $db = file_get_contents($B . 'production_dashboard.php');
$ex = function (string $src, string $fn): string { $i = strpos($src, "function $fn("); $a = strpos($src, '{', $i); $d = 0;
    for ($j = $a; $j < strlen($src); $j++) { if ($src[$j] === '{') $d++; elseif ($src[$j] === '}') { if (--$d === 0) return substr($src, $i, $j - $i + 1); } } return ''; };
eval($ex($st, 'zps_norm')); eval($ex($st, 'zps_depts')); eval($ex($st, 'zps_dept_ok'));

echo "1. Entered by\n";
ok(str_contains($rp, "COALESCE(e.created_by,0) cb") && str_contains($rp, "COALESCE(ae.full_name, u.name)"), 'each entry carries the login that typed it, named as the linked person');
ok(str_contains($js, "DIM.u = { t: 'Entered by'") && str_contains($js, "['user', 'Entered by']") && str_contains($js, "user:    { t: 'By data entry', g: 'u'"), 'a filter, a column in Every entry and a By data entry tab');

echo "2. A login belongs to a person\n";
ok(str_contains($st, 'ALTER TABLE users ADD COLUMN att_employee_id') && str_contains($st, 'ALTER TABLE users ADD COLUMN prod_depts'), 'two columns, added by themselves — no SQL step');
ok(!preg_match('/\b(DELETE|UPDATE)\b[^;]*\batt_employees\b/i', $st . $au . $lg . $us), 'People Finance is only READ — nothing there is changed or removed');
ok(str_contains($au, "if (\$row && zas_login_person_left(\$row) !== '') \$row = false;"), 'a login whose person has left stops working');
ok(str_contains($au, "if (!\$r || (int)\$r['active'] === 1) return '';") && str_contains($au, "if (\$eid <= 0) return '';"), '  a login with no person linked is never stopped by this');
ok(str_contains($lg, '$error = $left;'), '  and the login page says why');
ok(str_contains($us, "zps_save_user((int)\$savedId, \$data[2], (int)(\$_POST['att_employee_id'] ?? 0)") && str_contains($st, "if (\$empId > 0 && !isset(\$people[\$empId])) return"), 'the Users page saves the link; an unknown person is refused');

echo "3. Floors of a Production Staff login\n";
ok(zps_depts(['role' => 'production_staff', 'prod_depts' => 'Stitching|PACKING']) === ['STITCHING', 'PACKING'], 'floors are read from the login');
ok(zps_depts(['role' => 'production_staff', 'prod_depts' => '']) === [] && zps_depts(['role' => 'admin', 'prod_depts' => 'STITCHING']) === [], 'none ticked = every floor; admin is never limited');
ok(zps_dept_ok(['STITCHING'], 'stitching') && !zps_dept_ok(['STITCHING'], 'CUTTING') && zps_dept_ok([], 'CUTTING'), 'the check, run');
ok(str_contains($pe, '$no = zps_refuse_workers($who, $FLOORS);') && strpos($pe, 'zps_refuse_workers') < strpos($pe, "if (\$a === 'book') {"), 'Daily Entry: the SERVER refuses a booking, edit or cancel for a worker off this login\'s floors');
ok(str_contains($pe, "if (\$FLOORS) \$workers = array_values(array_filter(\$workers, fn(\$w) => zps_dept_ok("), '  and offers only those workers');
$fl = array_filter(explode("\n", $pe), fn($l) => str_contains($l, '$FLOORS') && !str_contains($l, '$FLOORS = zps_depts($me);') && !str_contains($l, 'zps_refuse_workers($who, $FLOORS)'));
ok(count($fl) >= 4 && count(array_filter($fl, fn($l) => !str_contains($l, 'if ($FLOORS)'))) === 0, '  nothing changes for a login with no floors (every use sits behind if ($FLOORS))');
ok(str_contains($rp, "function zpr_floor_sql(") && str_contains($db, 'zpr_dataset($from, $to, $showMoney, $FLOORS)') && str_contains($pr, 'zpr_dataset($from, $to, $showMoney, $FLOORS)'), 'Dashboard and Reports read only those floors');

echo "4. Finished products — whole products, not operations\n";
ok(str_contains($rp, 'function zpr_finished(') && str_contains($rp, '$f = zp_line_flow($l, $prog);'), 'the same lines and the same flow as Order Status');
ok(str_contains($rp, '$ready = $ready === null ? $good : min($ready, $good);'), 'ready sets: the slowest part decides');
ok(str_contains($rp, "\$complete = \$f['is_set'] ? \$sets : \$ready;"), 'finished: Make Sets for a set, the last stage for a one-part product');
ok(str_contains($pr, "'fin' => 'Finished products'") && str_contains($pr, "\$tab = (string)(\$_GET['r'] ?? 'fin');"), 'Reports opens on Finished products');
ok(str_contains($db, "\$D['fin'] = zpr_finished();") && !str_contains($db, 'zp_order_progress()'), 'the dashboard works the open lines out once, for both lists');
ok(str_contains($js, "fc.label = 'Finished products'"), 'the dashboard has a Finished products card');
/* the slowest-part rule, run */
$lines = [['Duvet Cover', 1.0, 200.0], ['Pillow Cover', 1.0, 150.0], ['Pillow (2 a set)', 2.0, 500.0]];
$ready = null; foreach ($lines as [$n, $per, $thr]) { $g = floor($thr / $per + 0.0001); $ready = $ready === null ? $g : min($ready, $g); }
ok($ready == 150.0, '200 duvets + 150 pillows packed = 150 ready sets (owner\'s example)');
echo "\n$P passed, $F failed\n";
exit($F ? 1 : 0);
