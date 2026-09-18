<?php
/* SET WORK — OPTION C. The product gives the list, an order may take its copy.
 *
 * Folding, matching, bagging, cartoning is work on the SET. Until this existed
 * an operation could only belong to a PART, so that pay was either never
 * booked or stuck onto a part it was never done to — and Costing priced a set
 * on part work alone, understating every quote by its whole packing labour.
 *
 * The rules that matter and are easy to get wrong:
 *   no copy on a line  = follow the product  (so nothing needs migrating)
 *   a removed row      = switched off, never deleted (wages keep their name)
 *   op_kind            = which of three tables op_id points at
 *   LEFT on a set job  = the ORDER quantity, not what the parts have reached
 */

$B = __DIR__ . '/app_src/public_html/';
$P = 0; $F = 0;
function ok($c, $m) { global $P, $F; if ($c) { $P++; } else { $F++; echo "  FAIL: $m\n"; } }

$zp = file_get_contents($B . 'includes/zprod.php');
$pm = file_get_contents($B . 'product_master.php');
$pe = file_get_contents($B . 'production_entry.php');
$code = preg_replace('!/\*.*?\*/!s', '', $zp);

echo "1. Three tables, and what an empty one means\n";
foreach (['zp_set_ops', 'zp_line_set_ops', 'zp_set_op_rate'] as $t)
    ok(str_contains($zp, "CREATE TABLE IF NOT EXISTS $t"), "$t is created");
ok(str_contains($zp, "ALTER TABLE zp_entries ADD COLUMN op_kind VARCHAR(8) NOT NULL DEFAULT 'part'"),
   "op_kind is added with 'part' as the default");
ok(str_contains($zp, 'so EVERY row already in the ledger'),
   '  and it says why that default is what makes it safe');
/* The reason zp_set_op_rate is its own table rather than a `kind` column on
   zp_op_rate — two id spaces cannot share one unique key. */
ok(str_contains($zp, 'two independent id spaces'),
   'and why the set rates need a table of their own');

echo "2. The choice between the two lists is made in ONE place\n";
ok(str_contains($zp, 'function zp_line_set_work('), 'zp_line_set_work exists');
ok(substr_count($code, 'zp_line_has_own_set(') >= 2, 'and it is what decides');
/* Every screen must ask that function rather than testing for itself. */
ok(!preg_match('/zp_line_set_ops.*WHERE proforma_item_id/s', $pm),
   'product_master never queries the line table directly');
ok(!str_contains($pe, 'zp_line_set_ops'),
   'the entry screen never queries it either — it reads the work index');

/* ---- run the rules ---- */
final class FStmt {
    public array $rows = [];
    public function __construct(public string $sql, private object $db) {}
    public function execute(array $a = []): bool { $this->rows = ($this->db->answer)($this->sql, $a); $this->db->ran[] = [$this->sql, $a]; return true; }
    public function fetchAll(): array { return $this->rows; }
    public function fetch() { return $this->rows[0] ?? false; }
    public function fetchColumn() { $r = $this->rows[0] ?? null; return $r === null ? false : reset($r); }
    public function rowCount(): int { return count($this->rows); }
}
final class FDb {
    public $answer; public array $ran = []; private int $next = 500;
    public function prepare(string $s) { return new FStmt($s, $this); }
    public function query(string $s) { $st = new FStmt($s, $this); $st->execute([]); return $st; }
    public function exec(string $s) { return 1; }
    public function beginTransaction() { return true; }
    public function commit() { return true; }
    public function rollBack() { return true; }
    public function lastInsertId() { return (string)(++$this->next); }
}
$DB = new FDb();
function db() { global $DB; return $DB; }
function zp_ensure_schema(): void {}
function zp_stage_name(int $id): string { return [1=>'Cutting',2=>'Stitching',3=>'Set Assembly',4=>'Packing'][$id] ?? ''; }

function lift(string $src, string $name): string {
    $a = strpos($src, 'function ' . $name . '(');
    $b = strpos($src, "\n}\n", $a);
    return substr($src, $a, $b - $a + 3);
}
foreach (['zp_set_ops','zp_set_rate_map','zp_set_rate_for','zp_line_has_own_set',
          'zp_line_set_work','zp_set_work_cost','zp_line_take_set_copy',
          'zp_save_set_ops','zp_line_set_drift'] as $fn) eval(lift($zp, $fn));

$MASTER = [
    ['id'=>1,'product_id'=>5,'seq'=>1,'stage_id'=>3,'operation_name'=>'Fold & match set','rate'=>12.00,'is_active'=>1],
    ['id'=>2,'product_id'=>5,'seq'=>2,'stage_id'=>3,'operation_name'=>'Poly bag & hangtag','rate'=>7.50,'is_active'=>1],
    ['id'=>3,'product_id'=>5,'seq'=>3,'stage_id'=>3,'operation_name'=>'Corner tuck','rate'=>3.00,'is_active'=>1],
    ['id'=>4,'product_id'=>5,'seq'=>4,'stage_id'=>4,'operation_name'=>'Carton & tape','rate'=>4.00,'is_active'=>1],
];
$SIZERATES = [['set_op_id'=>2,'size_id'=>90,'rate'=>8.50]];   // King bagging costs more
$LINEROWS  = [];
$LINECOUNT = 0;

$DB->answer = function (string $sql, array $a) use (&$MASTER, &$SIZERATES, &$LINEROWS, &$LINECOUNT) {
    if (str_contains($sql, 'FROM zp_set_ops'))            return $MASTER;
    if (str_contains($sql, 'FROM zp_set_op_rate'))        return $SIZERATES;
    if (str_contains($sql, 'COUNT(*) FROM zp_line_set_ops')) return [['c' => $LINECOUNT]];
    if (str_contains($sql, 'FROM zp_line_set_ops WHERE proforma_item_id=? AND is_active=1')) return $LINEROWS;
    if (str_contains($sql, 'SELECT set_op_id FROM zp_line_set_ops')) return array_map(
        fn($r) => ['set_op_id' => $r['set_op_id']], array_filter($LINEROWS, fn($r) => $r['set_op_id'] !== null));
    return [];
};

echo "3. A line with no copy follows the product\n";
$w = zp_line_set_work(77, 5, 90);
ok(count($w) === 4, 'all four jobs, got ' . count($w));
ok($w[0]['kind'] === 'set', "they are the PRODUCT's rows, got " . $w[0]['kind']);
ok($w[0]['from'] === 'product', '  and they say so');
/* THE SIZE OVERRIDE IS APPLIED, and this is the one that would be missed:
   the King bag is 8.50, not the 7.50 on the row. */
ok($w[1]['rate'] === 8.50, 'the King bagging rate wins over the standard, got ' . $w[1]['rate']);
ok($w[3]['rate'] === 4.00, '  and a job with no override keeps its own rate');
ok(zp_set_work_cost(5, 90) === 27.50, 'set work on a King is 27.50, got ' . zp_set_work_cost(5, 90));
ok(zp_set_work_cost(5, 0) === 26.50, 'and 26.50 where no size is known, got ' . zp_set_work_cost(5, 0));

echo "4. Taking a copy\n";
$DB->ran = [];
$r = zp_line_take_set_copy(77, 5, 90);
ok($r['ok'] === true && $r['copied'] === 4, 'four rows copied onto the line, got ' . json_encode($r));
$ins = array_values(array_filter($DB->ran, fn($x) => str_contains($x[0], 'INSERT INTO zp_line_set_ops')));
ok(count($ins) === 4, '  four inserts, got ' . count($ins));
/* THE COPY TAKES THE RATE THIS LINE WOULD REALLY HAVE PAID. Copying the
   standard 7.50 instead would silently re-price every size that had an
   override the moment somebody pressed the button. */
ok((float)$ins[1][1][5] === 8.50,
   'THE COPY CARRIES THE SIZE RATE, not the standard, got ' . json_encode($ins[1][1][5]));
ok($ins[0][1][1] === 1, '  and each row remembers which product job it came from');

$LINECOUNT = 1;
$r2 = zp_line_take_set_copy(77, 5, 90);
ok($r2['ok'] === false, 'pressing the button twice is refused');
ok(str_contains($r2['error'], 'already has its own'),
   '  rather than throwing away what was typed the first time');

echo "5. A line with its own copy stops following the product\n";
$LINEROWS = [
    ['id'=>601,'set_op_id'=>1,'stage_id'=>3,'operation_name'=>'Fold & match set','rate'=>12.00],
    ['id'=>602,'set_op_id'=>2,'stage_id'=>3,'operation_name'=>'Poly bag & hangtag','rate'=>9.00],
    ['id'=>603,'set_op_id'=>null,'stage_id'=>4,'operation_name'=>'Gift box + ribbon','rate'=>22.00],
    ['id'=>604,'set_op_id'=>4,'stage_id'=>4,'operation_name'=>'Carton & tape','rate'=>4.00],
];
$w2 = zp_line_set_work(77, 5, 90);
ok(count($w2) === 4, 'the copy is what is read, got ' . count($w2));
ok($w2[0]['kind'] === 'line', "and every row is a 'line' row, got " . $w2[0]['kind']);
ok($w2[1]['rate'] === 9.00, 'the re-rated job pays 9.00, not the product 8.50');
ok($w2[2]['from'] === 'added', 'the gift box says it was added here');
ok($w2[0]['from'] === 'product', '  while a kept row says where it came from');
/* Corner tuck was dropped from the copy and must NOT come back from the
   product's list. A copy that merged the two would quietly re-add work the
   buyer refused. */
$names = array_column($w2, 'name');
ok(!in_array('Corner tuck', $names, true),
   'A JOB LEFT OUT OF THE COPY DOES NOT COME BACK FROM THE PRODUCT');

echo "6. But drift is never silent\n";
$MASTER[] = ['id'=>9,'product_id'=>5,'seq'=>5,'stage_id'=>4,'operation_name'=>'Anti-slip strip','rate'=>5.00,'is_active'=>1];
$d = zp_line_set_drift(77, 5);
ok(count($d) === 2, 'the product has jobs this copy never heard of, got ' . count($d));
$dn = array_column($d, 'operation_name');
ok(in_array('Anti-slip strip', $dn, true), '  the new one is named');
ok(in_array('Corner tuck', $dn, true), '  and so is the one this order dropped');

echo "7. Removing is switching off, never deleting\n";
$so = lift($zp, 'zp_save_set_ops');
ok(str_contains($so, 'SET is_active=0'), 'a row that disappears is switched off');
ok(!preg_match('/DELETE FROM zp_set_ops/', $zp), 'nothing ever deletes a set operation');
ok(str_contains($zp, 'A ROW THAT DISAPPEARS IS SWITCHED OFF, NOT DELETED'), '  and it says why');
$dr = lift($zp, 'zp_line_drop_set_copy');
ok(str_contains($dr, "op_kind='line'") && str_contains($dr, 'set_op_id IS NULL'),
   'dropping a copy first checks for wages booked on a job that exists nowhere else');
ok(str_contains($dr, 'would leave those wages pointing at nothing'), '  and refuses in words');

echo "8. Booking it: the ceiling is the order, not the parts\n";
$bk = preg_replace('!/\*.*?\*/!s', '', lift($zp, 'zp_book'));
ok(str_contains($bk, "if (\$kind === 'set' || \$kind === 'line')"), 'zp_book knows a set row when it sees one');
ok(str_contains($bk, "\$left  = (float)\$line['ordered_qty'] - \$done - \$taken"),
   'LEFT on a set job is the ORDER quantity');
/* The SET BRANCH ALONE, cut out at its own `continue` — the part branch
   below it does call zp_remaining, and a lazy .*? across the whole function
   found that one and reported a fault that was not there. */
$sa = strpos($bk, "if (\$kind === 'set' || \$kind === 'line')");
$sb = strpos($bk, "            continue;\n        }", $sa);
$setBranch = substr($bk, $sa, $sb - $sa);
ok($setBranch !== '' && !str_contains($setBranch, 'zp_remaining'),
   '  it never asks zp_remaining, which is about parts');
ok(str_contains($setBranch, "zp_line_set_work("), '  it asks the one place that knows the list');
ok(str_contains($bk, "'part_id' => null,"), 'a set booking carries no part');
ok(str_contains($bk, "'op_kind' => \$kind,"), '  and says which table its op id belongs to');
/* Matched without caring where the line wraps — the sentence is split
   across two source lines and an exact string missed it. */
ok(preg_match('/making the floor\s+wait for the slowest part would be wrong/', $zp) === 1,
   'and the reason the ceiling is the order is written down');

echo "9. Progress counts sets apart from pieces\n";
$pmap = preg_replace('!/\*.*?\*/!s', '', lift($zp, 'zp_progress_map'));
ok(str_contains($pmap, "\$out['set'][\$it][\$kind][\$op]"), 'set work has a bucket of its own');
ok(str_contains($zp, 'would let 200 folds look like 200 cushion covers'),
   '  and the reason it must not share one with the parts');
ok(substr_count($pmap, "status='active'") === 1, 'cancelled rows are still excluded in ONE place');

echo "10. The screens\n";
ok(str_contains($pm, '3b &nbsp;Set work'), 'Product Master has the block');
ok(str_contains($pm, 'name="setop['), '  and it posts the jobs');
ok(str_contains($pm, 'name="setrate['), '  and the per-size rates');
/* INSIDE THE FORM, tested against the form's own boundaries. Comparing it
   with the first `name="do" value="save"` was wrong: the remove-part button
   higher up the page carries that too. */
$fa = strpos($pm, '<form method="post" id="pmForm"');
if ($fa === false) $fa = strpos($pm, '<form method="post"');
$fb = strpos($pm, '</form>', $fa);
$sp = strpos($pm, 'name="setop[');
ok($fa !== false && $fb !== false && $sp > $fa && $sp < $fb,
   '  inside the form, so ONE Save writes it with everything else');
ok(strpos($pm, 'name="setop[') < strrpos($pm, 'name="do" value="save"'),
   '  and above the Save button');
ok(str_contains($pm, 'zp_save_set_ops($pid, $so'), 'the save handler is wired');
ok(strpos($pm, 'zp_save_set_ops') < strpos($pm, 'zp_bridge_sync_product'),
   '  and runs BEFORE the costing bridge, or the sheet would miss it by one save');
ok(str_contains($pe, "'op_kind'   => \$_POST['r_kind'][\$i]   ?? 'part'"),
   'the entry screen posts which table it booked against');
ok(str_contains($pe, "kind:work.kind || 'part'"), '  and the staged row carries it');

echo "11. Costing is fed\n";
ok(str_contains($zp, 'const ZP_SET_OP_BASE'), 'the mirror has a marker space of its own');
ok(str_contains($zp, "'component' => '(whole set)'"), 'set lines are named on the costing sheet');
ok(str_contains($zp, 'ZP_SET_OP_BASE + (int)$o[\'id\']'), '  and marked at the offset');
ok(str_contains($zp, 'set op 4 and part op 4 would fight over one marker'),
   '  with the collision it avoids written down');

echo "\n$P passed, $F failed\n";
exit($F ? 1 : 0);
