<?php
/* ASSEMBLY — turning finished PARTS into finished SETS.
 *
 * "we have to combine pack the goods as well based on parts produced on
 *  floor as to convert stock of parts"
 * "allow parts to be used with any other PO ... we should not waste that
 *  leftover but would use it for any po whatever"
 *
 * The pool, the plan and the writer are lifted out of includes/zprod.php
 * and run against a fake floor: two orders for the same product, one of
 * which has leftovers.
 */
$B = __DIR__ . '/app_src/public_html/';
$P = 0; $F = 0;
function ok($c, $m) { global $P, $F; if ($c) { $P++; } else { $F++; echo "  FAIL: $m\n"; } }
$zp = file_get_contents($B . 'includes/zprod.php');
$pa = file_get_contents($B . 'production_assembly.php');

function lift(string $src, string $from): string {
    $a = strpos($src, $from);
    if ($a === false) return '';
    $b = strpos($src, "\n}\n", $a);
    return substr($src, $a, $b - $a + 3);
}

/* ---------------- the floor ----------------
   PI-2291 made 300 of every part. PI-2304 made 120 comforters, 260 fitted,
   nothing else. A 7pc set: 1 comforter, 1 fitted, 1 neck roll, 2 pillow
   cases, 2 cushions. */
$LINES = [
  ['item_id'=>881,'proforma_id'=>11,'product_id'=>7,'product_name'=>'7pc','ordered_qty'=>500,
   'size'=>'King','product_size_id'=>51,'pi_no'=>'PI-260901-2291','customer_name'=>'Al Noor'],
  ['item_id'=>884,'proforma_id'=>12,'product_id'=>7,'product_name'=>'7pc','ordered_qty'=>300,
   'size'=>'King','product_size_id'=>51,'pi_no'=>'PI-260908-2304','customer_name'=>'Bright Home'],
];
$PARTS = [ ['id'=>21,'part_name'=>'Comforter'], ['id'=>22,'part_name'=>'Fitted sheet'],
           ['id'=>23,'part_name'=>'Neck roll'], ['id'=>24,'part_name'=>'Pillow case'],
           ['id'=>25,'part_name'=>'Cushion cover'] ];
/* one operation per part keeps the fixture readable; the MIN rule is
   tested separately below with two */
$OPS = [21=>[[ 'id'=>301 ]], 22=>[['id'=>302]], 23=>[['id'=>303]], 24=>[['id'=>304]], 25=>[['id'=>305]]];
$PROG = ['op' => [
  881 => [21=>[301=>300], 22=>[302=>300], 23=>[303=>300], 24=>[304=>700], 25=>[305=>700]],
  884 => [21=>[301=>120], 22=>[302=>260]],
]];
$QTY = [21=>[51=>1], 22=>[51=>1], 23=>[51=>1], 24=>[51=>2], 25=>[51=>2]];
$TAKEN = [];   // rows already in zp_assembly_parts

function zp_ensure_schema(): void {}
function zp_open_lines(): array { global $LINES; return $LINES; }
function zp_progress_map(): array { global $PROG; return $PROG; }
function zp_line_size_id(array $l): int { return (int)($l['product_size_id'] ?? 0); }
function zp_sizes(int $p): array { return [['id'=>51,'size_label'=>'King'], ['id'=>52,'size_label'=>'Queen']]; }
function zp_product_parts(int $p): array { global $PARTS; return $PARTS; }
function zp_part_ops(int $id, bool $a = true): array { global $OPS; return $OPS[$id] ?? []; }
function zp_qty_map(int $p): array { global $QTY; return $QTY; }
function zp_qty_for(array $m, int $pt, int $sz): float { return isset($m[$pt][$sz]) ? (float)$m[$pt][$sz] : 1.0; }
function short_ref(?string $r): string {
    $r = trim((string)$r); $sep = strpos($r,'-') !== false ? '-' : '';
    if ($sep === '') return $r; $b = explode($sep,$r);
    return count($b) < 3 ? $r : $b[0].$sep.$b[count($b)-1];
}
$INSERTED = []; $LOG = [];
final class AStmt {
    public function __construct(private string $sql) {}
    public function execute($a = []) { global $LOG, $INSERTED, $TAKEN;
        $LOG[] = [$this->sql, $a];
        if (str_contains($this->sql, 'INSERT INTO zp_assembly_parts')) {
            $INSERTED[] = $a;
            $TAKEN[] = ['part_id'=>$a[1], 'size_label'=>$a[2], 'from_item_id'=>$a[3], 'q'=>$a[4]];
        }
        return true; }
    public function fetchAll() { global $TAKEN;
        if (str_contains($this->sql, 'FROM zp_assembly_parts')) {
            $g = [];
            foreach ($TAKEN as $t) { $k = $t['part_id'].'|'.$t['size_label'].'|'.$t['from_item_id'];
                $g[$k] = ($g[$k] ?? 0) + $t['q']; }
            $o = []; foreach ($g as $k => $q) { [$p,$s,$i] = explode('|', $k);
                $o[] = ['part_id'=>$p,'size_label'=>$s,'from_item_id'=>$i,'q'=>$q]; }
            return $o;
        }
        return []; }
    public function fetchColumn() { return 'active'; }
}
final class ADb {
    public function prepare($s) { return new AStmt($s); }
    public function query($s) { return new AStmt($s); }
    public function exec($s) { return 1; }
    public function beginTransaction() { global $LOG; $LOG[] = ['BEGIN', []]; return true; }
    public function commit() { global $LOG; $LOG[] = ['COMMIT', []]; return true; }
    public function rollBack() { global $LOG; $LOG[] = ['ROLLBACK', []]; return true; }
    public function lastInsertId() { return '900'; }
}
$DB = new ADb(); function db() { global $DB; return $DB; }

eval(lift($zp, 'function zp_part_pool('));
eval(lift($zp, 'function zp_assembly_plan('));
eval(lift($zp, 'function zp_assembly_save('));

echo "1. The pool — parts belong to nobody\n";
$pool = zp_part_pool();
ok(($pool[21]['King']['made'] ?? null) === 420.0,
   'comforters from BOTH orders are one pile: 300 + 120 = 420, got ' . ($pool[21]['King']['made'] ?? 'null'));
ok(($pool[22]['King']['made'] ?? null) === 560.0, 'fitted sheets too: 300 + 260 = 560');
ok(($pool[23]['King']['made'] ?? null) === 300.0, 'neck rolls only exist on the first order');
ok(count($pool[21]['King']['by']) === 2, 'and it remembers both orders it came from');
ok($pool[21]['King']['by'][0]['item_id'] === 881,
   'OLDEST ORDER FIRST — leftovers are used before new pieces, got ' . $pool[21]['King']['by'][0]['item_id']);
ok($pool[21]['King']['by'][0]['pi'] === 'PI-2291', '  named short, got ' . $pool[21]['King']['by'][0]['pi']);

echo "2. Finished means past EVERY operation\n";
$OPS[23] = [['id'=>303], ['id'=>306]];          // neck roll now has two
$PROG['op'][881][23] = [303 => 300, 306 => 180];  // 300 cut, 180 stitched
$p2 = zp_part_pool();
ok(($p2[23]['King']['made'] ?? null) === 180.0,
   '300 cut and 180 stitched is 180 FINISHED, not 300 and not 480, got ' . ($p2[23]['King']['made'] ?? 'null'));
$PROG['op'][881][23] = [303 => 300, 306 => 300];
$OPS[23] = [['id'=>303]];

echo "3. What the pool can make\n";
$plan = zp_assembly_plan(7, 'King', 0);
/* comforter 420/1=420, fitted 560/1=560, neck roll 300/1=300,
   pillow 700/2=350, cushion 700/2=350 -> 300, limited by the neck roll */
ok($plan['can'] === 300.0, 'the ceiling is 300, got ' . $plan['can']);
ok($plan['limit_by'] === 'Neck roll', 'and it names what runs out first, got ' . $plan['limit_by']);
$per = []; foreach ($plan['parts'] as $pp) $per[$pp['name']] = $pp['per'];
ok($per['Pillow case'] === 2.0, 'A PART THAT GOES IN TWICE is read as 2 per set');

$plan150 = zp_assembly_plan(7, 'King', 150);
$need = []; foreach ($plan150['parts'] as $pp) $need[$pp['name']] = $pp['need'];
ok($need['Pillow case'] === 300.0, '  so 150 sets need 300 pillow cases, got ' . $need['Pillow case']);
ok($plan150['short'] === 0, 'nothing is short at 150');
$plan400 = zp_assembly_plan(7, 'King', 400);
ok($plan400['short'] > 0, 'and something IS short at 400');

echo "4. Making them\n";
$INSERTED = []; $LOG = [];
$r = zp_assembly_save('2026-09-18', 7, 'King', 150, null, '', 1);
ok($r['ok'] === true, 'saved: ' . $r['error']);
/* comforters: 150 wanted, 300 sitting on PI-2291 — all from the oldest */
$comf = array_values(array_filter($INSERTED, fn($x) => (int)$x[1] === 21));
ok(count($comf) === 1 && (int)$comf[0][3] === 881 && (float)$comf[0][4] === 150.0,
   'comforters all come off the OLDEST order: ' . json_encode($comf));
ok(in_array('COMMIT', array_column($LOG, 0), true), 'one transaction, committed');

echo "5. Leftovers really are used — across POs\n";
/* THE FIXTURE CHANGES HERE, on purpose. Up to now the neck roll was the
   scarce part, which is what section 3 needed. To SEE a leftover cross
   from one PO to another, the scarce part has to be one that BOTH orders
   made — so the second order now finishes its neck rolls, pillow cases and
   cushions too, and the comforter becomes the ceiling.

   My first attempt skipped this and asked for 280 sets while the neck roll
   still capped it at 150. The save refused, correctly, and the test read
   as a bug in the code. It was a bug in the fixture. */
$PROG['op'][884][23] = [303 => 400];
$PROG['op'][884][24] = [304 => 900];
$PROG['op'][884][25] = [305 => 900];

/* PI-2291 has 150 comforters left after the 150 sets above, and PI-2304
   has 120. Asking for exactly 270 must empty the older one and then reach
   into the newer. THAT is the whole feature. */
$INSERTED = [];
$r2 = zp_assembly_save('2026-09-18', 7, 'King', 270, null, '', 1);
ok($r2['ok'] === true, 'saved: ' . $r2['error']);
$comf2 = array_values(array_filter($INSERTED, fn($x) => (int)$x[1] === 21));
ok(count($comf2) === 2, 'the comforters came off TWO orders, got ' . count($comf2) . ': ' . json_encode($comf2));
ok((int)$comf2[0][3] === 881 && (float)$comf2[0][4] === 150.0, '  the last 150 off PI-2291 first');
ok((int)$comf2[1][3] === 884 && (float)$comf2[1][4] === 120.0,
   '  AND THE REST OFF PI-2304 — a leftover from one PO packed into another');

echo "6. The one refusal\n";
$INSERTED = [];
$r3 = zp_assembly_save('2026-09-18', 7, 'King', 9999, null, '', 1);
ok($r3['ok'] === false, 'more sets than the pool has parts for is REFUSED');
ok(str_contains($r3['error'], 'Short on:'), '  naming what is short: ' . $r3['error']);
ok(str_contains($r3['error'], 'never produced'), '  and why it is refused rather than warned about');
ok($INSERTED === [], '  AND NOTHING WAS WRITTEN');
$r4 = zp_assembly_save(date('Y-m-d', strtotime('+2 days')), 7, 'King', 1, null, '', 1);
ok($r4['ok'] === false, 'a future date is refused');
$r5 = zp_assembly_save('2026-09-18', 7, 'King', 0, null, '', 1);
ok($r5['ok'] === false && str_contains($r5['error'], 'How many'), 'zero sets is refused');
$r6 = zp_assembly_plan(7, 'Queen', 0);
ok($r6['ok'] === true && $r6['can'] === 0.0, 'a size with no parts finished can make nothing');
$r7 = zp_assembly_plan(7, 'Emperor', 0);
ok($r7['ok'] === false && str_contains($r7['error'], 'not on this product'), 'a size the product does not have is refused');

echo "7. Cancelling puts the parts back\n";
$canc = lift($zp, 'function zp_assembly_cancel(');
ok(str_contains($canc, "UPDATE zp_assembly SET status='cancelled'"), 'cancelling marks it');
ok(!preg_match('/DELETE\s+FROM\s+zp_assembly/i', $zp), '  nothing deletes an assembly');
ok(str_contains($zp, "WHERE a.status='active'"),
   'and the pool only counts ACTIVE ones, so a cancelled assembly returns its parts by itself');

echo "8. The screen\n";
ok(str_contains($pa, 'zp_assembly_plan($pid, $size, $sets)'), 'the page shows the plan before writing');
ok(str_contains($pa, 'name="proforma_item_id"'), 'crediting to an order is on the form');
ok(str_contains($pa, '— no order, to stock —'), '  and OPTIONAL, which is the leftover rule working');
ok(str_contains($pa, 'Where the pieces are'), 'the row says which POs the parts come from');
ok(str_contains($pa, 'pool allows'), 'the size list says how many sets each size can make');
ok(str_contains($pa, 'refused rather than warned about'), 'and the one refusal explains itself');
ok(str_contains($pa, "onsubmit=\"var r=prompt("), 'cancelling asks for a reason');
ok(str_contains(file_get_contents($B . 'includes/menu.php'), "'href' => 'production_assembly.php'"), 'it is on the menu');

echo "\n$P passed, $F failed\n";
exit($F ? 1 : 0);
