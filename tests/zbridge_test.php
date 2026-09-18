<?php
/* THE COSTING BRIDGE — lifts the zp_bridge_* functions out of the SHIPPED
   includes/zprod.php and RUNS them against a stub database that records every
   statement. The rules under test are money rules:

     WORKMANSHIP MUST ARRIVE       zero wages = an under-priced quote
     NOTHING OLD IS DELETED        a saved costing points at those size ids
     NO DOUBLE COUNTING            SUM() over a leftover legacy row = 2x cost
     THE ENUM MUST BE RESPECTED    'Manual Cutting' is not a value it accepts */

$SRC = __DIR__ . '/app_src/public_html/includes/zprod.php';
$src = file_get_contents($SRC);

$P = 0; $F = 0;
function ok($c, $m) { global $P, $F; if ($c) { $P++; } else { $F++; echo "  FAIL: $m\n"; } }
function lift(string $src, string $name): string {
    $i = strpos($src, "function $name(");
    if ($i === false) { fwrite(STDERR, "missing $name\n"); exit(1); }
    $b = strpos($src, '{', $i); $d = 0;
    for ($j = $b; $j < strlen($src); $j++) {
        if ($src[$j] === '{') $d++;
        elseif ($src[$j] === '}') { $d--; if ($d === 0) return substr($src, $i, $j - $i + 1); }
    }
    exit(1);
}

/* ---------- the stub database: records everything, answers from fixtures ---------- */
$LOG = [];
$ZP_OP_RATES = [];
class Stmt {
    public $sql; private $rows; public $n = 0;
    public function __construct($sql, $rows) { $this->sql = $sql; $this->rows = $rows; }
    public function execute($a = []) {
        $GLOBALS['LOG'][] = ['sql' => preg_replace('/\s+/', ' ', trim($this->sql)), 'args' => $a];
        $this->n = $GLOBALS['ROWCOUNT'] ?? 0;
        return true;
    }
    public function fetchAll() { return $this->rows; }
    public function fetchColumn() { return $this->rows ? array_values($this->rows[0])[0] : false; }
    public function rowCount() { return $this->n; }
}
class Db {
    private function answer($sql) {
        if (stripos($sql, 'FROM product_sizes') !== false)               return $GLOBALS['OLD_SIZES'];
        if (stripos($sql, 'FROM production_operations') !== false)       return $GLOBALS['OLD_MIRROR'];
        if (stripos($sql, 'FROM zp_product_parts') !== false)            return $GLOBALS['PART_USERS'];
        return [];
    }
    public function prepare($sql) { return new Stmt($sql, $this->answer($sql)); }
    public function query($sql)   { $s = new Stmt($sql, $this->answer($sql)); $s->execute(); return $s; }
    public function exec($sql)    { $GLOBALS['LOG'][] = ['sql' => preg_replace('/\s+/', ' ', trim($sql)), 'args' => []]; return 1; }
    public function lastInsertId() { return (string)(++$GLOBALS['NEXTID']); }
}
function db() { return new Db(); }

/* ---------- stubs for the new-module readers ---------- */
function zp_ensure_schema() {}
function zp_is_cutting(string $s): bool { return $s === 'Cutting' || $s === 'Manual Cutting'; }
function zp_sizes(int $p): array { return $GLOBALS['ZP_SIZES']; }
function zp_product_parts(int $p): array { return $GLOBALS['ZP_PARTS']; }
function zp_part_ops(int $id, bool $a = false): array { return $GLOBALS['ZP_OPS'][$id] ?? []; }
/* Rates that differ by size. Empty by default, so every test below describes
   the ordinary case — one rate for every size — exactly as before. Section 13
   fills it to prove the extra rows are written. */
function zp_op_rate_map(int $p, bool $fresh = false): array { return $GLOBALS['ZP_OP_RATES'] ?? []; }
/* SET WORK NOW REACHES COSTING TOO, so the bridge asks for it. Stubbed empty
   by default — every assertion below is about PART work, and a set job
   appearing in those counts would be measuring the wrong thing. The set-work
   mirror has its own test. */
function zp_set_ops(int $p, bool $a = true): array { return $GLOBALS['ZP_SET_OPS'] ?? []; }
function zp_set_rate_map(int $p): array { return $GLOBALS['ZP_SET_RATES'] ?? []; }
function zp_stage_name(int $id): string { return $GLOBALS['ZP_STAGE_NAMES'][$id] ?? ''; }
function zp_qty_map(int $p): array { return $GLOBALS['ZP_QTY']; }
function zp_qty_for(array $m, int $part, int $size): float { return (float)($m[$part][$size] ?? 1.0); }

foreach (['zp_bridge_stage', 'zp_bridge_schema', 'zp_bridge_sizes', 'zp_bridge_ops',
          'zp_bridge_qty', 'zp_bridge_sync_product', 'zp_bridge_sync_part', 'zp_bridge_sync_all'] as $fn)
    eval(lift($src, $fn));

function sqls(string $needle): array {
    return array_values(array_filter($GLOBALS['LOG'], fn($l) => stripos($l['sql'], $needle) !== false));
}
function reset_log() { $GLOBALS['LOG'] = []; $GLOBALS['ROWCOUNT'] = 0; }

function fixture() {
    $GLOBALS['NEXTID'] = 500;
    /* ONE SIZE TABLE NOW. zp_sizes() reads product_sizes, so these ARE the rows
       Costing sees — there is no second list and nothing to copy between them. */
    $GLOBALS['ZP_SIZES'] = [
        ['id' => 91, 'size_label' => 'Single'],
        ['id' => 92, 'size_label' => 'double'],
        ['id' => 93, 'size_label' => 'King'],
    ];
    $GLOBALS['OLD_SIZES'] = $GLOBALS['ZP_SIZES'];
    $GLOBALS['ZP_PARTS'] = [['id' => 10, 'part_name' => 'Flat Sheet'], ['id' => 11, 'part_name' => 'Pillow Case']];
    $GLOBALS['ZP_OPS'] = [
        10 => [['id' => 700, 'operation_name' => 'Cutting',  'stage' => 'Cutting',        'rate' => 3.0],
               ['id' => 701, 'operation_name' => 'Overlock', 'stage' => 'Stitching',      'rate' => 5.0]],
        11 => [['id' => 702, 'operation_name' => 'Hand Cut', 'stage' => 'Manual Cutting', 'rate' => 2.5],
               ['id' => 703, 'operation_name' => 'Singer',   'stage' => 'Stitching',      'rate' => 4.0]],
    ];
    $GLOBALS['ZP_QTY'] = [11 => [92 => 2.0]];          // Double = 2 pillow cases
    $GLOBALS['OLD_MIRROR'] = [];
    $GLOBALS['PART_USERS'] = [];
    reset_log();
}

echo "1. The rate actually reaches production_operations (the zero-workmanship bug)\n";
fixture();
zp_bridge_sync_product(5);
$ins = sqls('INSERT INTO production_operations');
ok(count($ins) === 4, 'all four operations written, got ' . count($ins));
/* ARG POSITIONS MOVED when product_size_id stopped being the literal NULL it
   had always been written as. The columns are now
   (product, operation, component, stage, SIZE, rate, zp_op_id) with is_active
   still the one literal — so rate is arg 5 and the zp id is arg 6. */
$rates = array_map(fn($l) => $l['args'][5], $ins);
sort($rates);
ok($rates === [2.5, 3.0, 4.0, 5.0], 'every rate carried: ' . json_encode($rates));
$FIRSTLOG = $LOG;   // kept for test 11: the ALTER only happens on the first sync
$comps = array_values(array_unique(array_map(fn($l) => $l['args'][2], $ins)));
sort($comps);
ok($comps === ['Flat Sheet', 'Pillow Case'], 'part becomes the component: ' . json_encode($comps));
ok(array_map(fn($l) => $l['args'][6], $ins) === [700, 701, 702, 703], 'each row remembers its zp op id');
/* with no size rates set, every row is still the "All Sizes" one — which is
   the whole app's behaviour before this feature and must stay the default */
ok(array_map(fn($l) => $l['args'][4], $ins) === [null, null, null, null],
   'and with no size rates every row is still All Sizes');

echo "2. 'Manual Cutting' is never written — the old column is an ENUM\n";
$stages = array_map(fn($l) => $l['args'][3], $ins);
ok(!in_array('Manual Cutting', $stages, true), 'invalid enum value never sent: ' . json_encode($stages));
ok(count(array_diff($stages, ['Cutting', 'Stitching'])) === 0, 'only legal values');
ok(zp_bridge_stage('Manual Cutting') === 'Cutting', 'mapped to Cutting');
ok(zp_bridge_stage('Cutting') === 'Cutting' && zp_bridge_stage('Stitching') === 'Stitching', 'others unchanged');

echo "3. A leftover legacy row is switched OFF, never left to double the cost\n";
$off = sqls('UPDATE production_operations SET is_active=0');
ok(count($off) === 1, 'legacy rows deactivated once');
ok(stripos($off[0]['sql'], 'zp_op_id IS NULL') !== false, 'only rows the bridge does not own');
ok(stripos($off[0]['sql'], 'is_active=1') !== false, 'and only ones currently on');
ok(count(sqls('DELETE FROM production_operations')) === 0, 'nothing legacy is deleted');

echo "4. A product with NO operations does not switch off the old ones\n";
fixture(); $GLOBALS['ZP_OPS'] = [];
zp_bridge_sync_product(5);
ok(count(sqls('UPDATE production_operations SET is_active=0')) === 0,
   'no new rates means the old ones are left exactly as they are');

echo "5. The size mirror is GONE — there is one table, so nothing is copied\n";
fixture();
zp_bridge_sync_product(5);
ok(count(sqls('INSERT INTO product_sizes')) === 0, 'the bridge inserts no size at all');
ok(count(sqls('UPDATE product_sizes')) === 0, 'and updates none');
ok(count(sqls('DELETE FROM product_sizes')) === 0, 'and deletes none');

echo "6. It maps every size to itself, because it IS itself\n";
$m = zp_bridge_sizes(5);
ok($m === [91=>91, 92=>92, 93=>93], 'identity map: ' . json_encode($m));
ok(count(sqls('product_sizes')) === 0, 'reading the list wrote nothing');

echo "7. Quantity per set reaches costing in the OLD size ids\n";
fixture(); zp_bridge_sync_product(5);
$q = sqls('INSERT INTO product_component_qty');
ok(count($q) === 6, '2 parts x 3 sizes, got ' . count($q));
$ids = array_values(array_unique(array_map(fn($l) => $l['args'][1], $q)));
sort($ids);
ok($ids === [91, 92, 93], 'the SAME size ids costing already uses, no remapping: ' . json_encode($ids));
$double = array_values(array_filter($q, fn($l) => $l['args'][1] === 92 && $l['args'][2] === 'Pillow Case'));
ok(count($double) === 1 && $double[0]['args'][3] === 2.0, 'Double pillow = 2, not 1');
$single = array_values(array_filter($q, fn($l) => $l['args'][1] === 91 && $l['args'][2] === 'Pillow Case'));
ok(count($single) === 1 && $single[0]['args'][3] === 1.0, 'a missing quantity is 1, never 0');
ok(count(sqls('DELETE FROM product_component_qty')) === 0, 'quantities are upserted, not wiped');
ok(count(sqls('ON DUPLICATE KEY UPDATE qty_per_set')) === 6, 'written as an upsert');

echo "8. Running it twice rewrites, it does not double\n";
fixture();
$GLOBALS['OLD_MIRROR'] = [['id' => 800, 'zp_op_id' => 700], ['id' => 801, 'zp_op_id' => 701],
                          ['id' => 802, 'zp_op_id' => 702], ['id' => 803, 'zp_op_id' => 703]];
zp_bridge_sync_product(5);
ok(count(sqls('INSERT INTO production_operations')) === 0, 'nothing inserted the second time');
ok(count(sqls('UPDATE production_operations SET operation_name')) === 4, 'all four updated in place');

echo "9. An operation removed in the new module is removed from the mirror — and only that\n";
fixture();
$GLOBALS['OLD_MIRROR'] = [['id' => 800, 'zp_op_id' => 700], ['id' => 809, 'zp_op_id' => 999]];
zp_bridge_sync_product(5);
$del = sqls('DELETE FROM production_operations');
ok(count($del) === 1, 'one delete');
/* BY ROW ID NOW, NOT BY zp_op_id. One operation can own several rows — the
   All Sizes one plus a row per size rate — so deleting by zp_op_id would take
   the whole family when only one size rate had been cleared. */
ok(stripos($del[0]['sql'], 'id IN') !== false, 'the stray row is named by its own id');
ok(stripos($del[0]['sql'], 'zp_op_id IS NOT NULL') !== false,
   'and the DELETE still says out loud that it only touches rows the bridge wrote');
ok($del[0]['args'] === [5, 809], 'only the dead one, bound not interpolated: ' . json_encode($del[0]['args']));

echo "9b. A SIZE RATE BECOMES ITS OWN ROW, BESIDE THE ALL-SIZES ONE\n";
/* this is the bug the whole change exists for: product_size_id was written as
   a hard-coded NULL on every INSERT *and* UPDATE, so a size rate set by hand
   in the old table was wiped on the next save. */
fixture();
$GLOBALS['ZP_OP_RATES'] = [701 => [13 => 7.00]];      // King overlock pays 7.00
$GLOBALS['OLD_MIRROR'] = [];
zp_bridge_sync_product(5);
$ins2 = sqls('INSERT INTO production_operations');
ok(count($ins2) === 5, 'four All Sizes rows plus one for the size, got ' . count($ins2));
$sized = array_values(array_filter($ins2, fn($l) => $l['args'][4] !== null));
ok(count($sized) === 1, 'exactly one row carries a size, got ' . count($sized));
ok($sized[0]['args'][4] === 13, '  and it is the King size id, got ' . json_encode($sized[0]['args'][4] ?? null));
ok($sized[0]['args'][5] === 7.00, '  paying 7.00, got ' . json_encode($sized[0]['args'][5] ?? null));
ok($sized[0]['args'][6] === 701, '  against the operation it belongs to');
/* the All Sizes row for that same operation must SURVIVE — costing falls back
   to it whenever a version is not tied to exactly one size */
$allsz = array_values(array_filter($ins2, fn($l) => $l['args'][6] === 701 && $l['args'][4] === null));
ok(count($allsz) === 1, 'the All Sizes row for that operation is still written too');
ok($allsz[0]['args'][5] === 5.00, '  still at the standard rate, got ' . json_encode($allsz[0]['args'][5] ?? null));
$GLOBALS['ZP_OP_RATES'] = [];

echo "10. A shared part re-feeds EVERY product that uses it\n";
fixture();
$GLOBALS['PART_USERS'] = [['product_id' => 5], ['product_id' => 6], ['product_id' => 7]];
$r = zp_bridge_sync_part(10);
ok($r['products'] === 3, 'three products re-fed, got ' . $r['products']);
ok(count(sqls('INSERT INTO production_operations')) === 12, '4 ops x 3 products, got '
   . count(sqls('INSERT INTO production_operations')));

echo "11. The marker column is added additively and never breaks the old table\n";
/* zp_bridge_schema() is static-guarded so the ALTER runs once per request, not
   once per product — so this checks the very first sync, recorded in $FIRSTLOG. */
$alt = array_values(array_filter($FIRSTLOG, fn($l) => stripos($l['sql'], 'ALTER TABLE production_operations') !== false));
ok(count($alt) === 1, 'the column is added exactly once per request, got ' . count($alt));
ok(stripos($alt[0]['sql'], 'ADD COLUMN zp_op_id') !== false, 'ADD COLUMN only');
$bad = array_values(array_filter($FIRSTLOG, fn($l) => preg_match('/\\b(DROP|TRUNCATE)\\b/i', $l['sql'])));
ok(count($bad) === 0, 'nothing is dropped or truncated');
fixture(); zp_bridge_sync_product(5);

echo "12. Nothing in the whole bridge ever touches products or the wage ledger\n";
fixture(); zp_bridge_sync_product(5);
ok(count(sqls('DELETE FROM products')) === 0 && count(sqls('UPDATE products')) === 0, 'products untouched');
ok(count(sqls('zp_entries')) === 0, 'the wage ledger is never written');
ok(count(sqls('costing_versions')) === 0 && count(sqls('costing_lines')) === 0,
   'saved costing sheets are never rewritten behind the user');

echo "\n$P passed, $F failed\n";
exit($F ? 1 : 0);
