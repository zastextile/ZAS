<?php
/* The summarised production list (owner, 21 Nov 2026): "sum qty where the
   proforma is the same and part, stage and rate / pc match — forget sizes".
   Lifts the two merge functions out of the page and runs them on plain rows:
   no database, no server. */
$P = 0; $F = 0;
function ok($c, $m) { global $P, $F; if ($c) { $P++; echo "  ok  $m\n"; } else { $F++; echo "  FAIL: $m\n"; } }
function grab(string $src, string $needle): string {
    $i = strpos($src, $needle); if ($i === false) { echo "missing $needle\n"; exit(1); }
    $a = strpos($src, '{', $i); $d = 0;
    for ($j = $a; $j < strlen($src); $j++) {
        if ($src[$j] === '{') $d++;
        elseif ($src[$j] === '}') { $d--; if ($d === 0) return substr($src, $i, $j - $i + 1) . "\n"; }
    }
    exit(1);
}
$pe = file_get_contents(__DIR__ . '/../public_html/production_entry.php');
function db() { throw new RuntimeException('no database in this test'); }
eval(grab($pe, 'function ze_merge_work(') . grab($pe, 'function ze_group_today('));

function w($item, $pi, $part, $op, $sid, $rate, $left, $size, $kind = 'part') {
    return ['k' => "$item:$op", 'item' => $item, 'op' => $op, 'kind' => $kind, 'part' => $part, 'pi' => $pi, 'pifull' => $pi,
            'cust' => 'C', 'prod' => 'Pillow', 'size' => $size, 'pn' => 'Pillow Cover', 'on' => 'Overlock', 'st' => 'Stitching',
            'sid' => $sid, 'rate' => $rate, 'left' => $left, 'done' => $left <= 0 ? 1 : 0, 'hay' => "pi-1 pillow " . strtolower($size)];
}

echo "1. Sizes of one job become one row\n";
$m = ze_merge_work([w(1,'PI-1',3,10,2,5.0,600,'Standard'), w(2,'PI-1',3,10,2,5.0,400,'King'), w(3,'PI-1',3,10,2,5.0,0,'Euro')]);
ok(count($m) === 1, 'three sizes, one row');
ok($m[0]['left'] == 1000, 'left is every size added up (1000)');
ok($m[0]['nsz'] === 3 && $m[0]['size'] === '', 'it says 3 sizes');
ok($m[0]['mem'] === [[1,10,600.0],[2,10,400.0],[3,10,0.0]], 'the size lines are kept, oldest first, for first-size-first');
ok($m[0]['item'] === 1 && !$m[0]['done'], 'it points at the first line with work left, and is open');
ok(str_contains($m[0]['hay'], 'king'), 'a search by size still finds it');

echo "2. What must stay apart\n";
$m = ze_merge_work([w(1,'PI-1',3,10,2,5.0,600,'S'), w(2,'PI-1',3,10,2,5.5,400,'K')]);
ok(count($m) === 2, 'a different rate stays its own row');
$m = ze_merge_work([w(1,'PI-1',3,10,2,5.0,600,'S'), w(2,'PI-2',3,10,2,5.0,400,'S')]);
ok(count($m) === 2, 'a different proforma stays its own row');
$m = ze_merge_work([w(1,'PI-1',3,10,2,5.0,600,'S'), w(1,'PI-1',3,12,3,5.0,600,'S')]);
ok(count($m) === 2, 'a different stage at the same rate stays its own row');
$m = ze_merge_work([w(1,'PI-1',3,10,2,5.0,600,'S'), w(1,'PI-1',3,10,2,5.0,600,'S','set')]);
ok(count($m) === 2, 'part work and set work never merge');
$m = ze_merge_work([w(1,'PI-1',3,10,2,5.0,0,'S'), w(2,'PI-1',3,10,2,5.0,0,'K')]);
ok($m[0]['done'] === 1, 'all sizes finished: the row is finished');

echo "2b. Operations of one stage at one rate become one row (owner, 21 Nov 2026)\n";
$a = w(1,'PI-1',3,10,2,5.0,600,'S'); $b = w(2,'PI-1',3,10,2,5.0,300,'K');
$c = w(1,'PI-1',3,11,2,5.0,600,'S'); $c['on'] = 'Hem'; $d = w(2,'PI-1',3,11,2,5.0,300,'K'); $d['on'] = 'Hem';
$m = ze_merge_work([$a, $c, $b, $d]);
ok(count($m) === 1, 'Overlock and Hem at 5.00 in Stitching: one row');
ok($m[0]['on'] === 'Overlock, Hem' && $m[0]['nop'] === 2, 'the operations are listed with commas');
ok($m[0]['left'] == 1800 && $m[0]['nsz'] === 2, 'left is every size and operation added up; still 2 sizes');
ok($m[0]['mem'] === [[1,10,600.0],[2,10,300.0],[1,11,600.0],[2,11,300.0]], 'first operation first, then oldest line first');

echo "3. The booked table, merged the same way\n";
$e = fn($id, $wk, $q, $st = 'active', $rate = 5.0) => ['id' => $id, 'worker_id' => $wk, 'pi_no' => 'PI-1', 'product_name' => 'Pillow',
     'part_id' => 3, 'op_id' => 10, 'stage' => 'Stitching', 'rate_applied' => $rate, 'status' => $st, 'qty' => $q, 'amount' => $q * $rate, 'created_at' => '2026-11-21 10:' . $id];
$g = ze_group_today([$e(11, 4, 100), $e(12, 4, 200), $e(13, 3, 500), $e(14, 4, 50, 'cancelled')]);
ok(count($g) === 3, 'one row per worker + job + state');
$h = ze_group_today([$e(21, 4, 50) + ['operation_name' => 'Hem'], ['op_id' => 9] + $e(20, 4, 50) + ['operation_name' => 'Overlock']]);
ok(count($h) === 1 && $h[0]['ops'] === ['Overlock', 'Hem'], 'two operations of one stage: one row, listed in the order booked');
ok($g[0]['qty'] == 300 && $g[0]['amount'] == 1500 && $g[0]['ids'] === [11, 12], 'the first line is counted too: 100 + 200 = 300');
ok($g[1]['qty'] == 500 && $g[1]['n'] === 1, 'a single line stays as it is');
ok($g[2]['status'] === 'cancelled', 'a cancelled line is never added into a live one');

echo "4. The page wiring\n";
ok(str_contains($pe, '$WORK = ze_merge_work(zp_work_index());'), 'the list is merged once, when the page opens');
ok(str_contains($pe, "mem[m][2] - stagedItem(mem[m][0], mem[m][1], kind)"), 'a booking is shared first size first, minus what is already staged');

echo "5. Edit and cancel are all or nothing\n";
ok(str_contains($pe, "if (!\$r['ok']) { ze_restore(\$done);"), 'a cancel that one line refuses puts the others back');
ok(substr_count($pe, 'ze_restore($ids); return') >= 3, 'an edit the new booking refuses puts the old lines back');
ok(str_contains($pe, "\$o['entry_date'] !== \$date"), 'an edit only touches lines of the day on screen');
ok(str_contains($pe, "if (/^[=+\\-@]/.test(v) && isNaN(+v))"), 'the CSV cannot carry a spreadsheet formula');
ok(str_contains($pe, "explode(',', (string)(\$_POST['entry_id'] ?? ''))"), 'Cancel cancels every size line of the row');
ok(str_contains($pe, "['r_item',x.item]"), 'each size line is still posted, so the server checks and prices every one');

echo "\n$P passed, $F failed\n";
exit($F ? 1 : 0);
