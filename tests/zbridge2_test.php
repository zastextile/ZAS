<?php
/* THE DEAD LINK BETWEEN THE FLOOR AND THE STORE.
 *
 * inv_line_production() asked production_transactions — the OLD production
 * module. Daily Production Entry writes zp_entries. Nothing has written the
 * old table since the rebuild, so every field came back zero, and in this one
 * place zero is not a harmless wrong answer:
 *
 *   inv_consumption_post() uses `available` as a HARD BLOCK, so at zero it
 *   refused to post any consumption tied to an order line — the only door
 *   into finished goods stock, shut.
 *
 *   inv_consume.php drops lines where `started` is false, so the picker
 *   listed nothing.
 *
 *   `wage_per_unit` fed the cost check, understating every order's cost by
 *   the whole of its workmanship.
 *
 * The function is LIFTED and RUN against a fake database, so what it does
 * with a real set of bookings is measured rather than asserted about.
 */

$B = __DIR__ . '/app_src/public_html/';
$P = 0; $F = 0;
function ok($c, $m) { global $P, $F; if ($c) { $P++; } else { $F++; echo "  FAIL: $m\n"; } }

$inv = file_get_contents($B . 'includes/inventory.php');
$oc  = file_get_contents($B . 'inv_ordercost.php');

echo "1. The old table is gone from the screens that cost an order\n";

/* Comments name the old table on purpose — they explain the repair. Strip
   them before looking, or the test reads its own documentation as the fault.
   This has bitten me three times now. */
$invCode = preg_replace('!/\*.*?\*/!s', '', $inv);
$ocCode  = preg_replace('!/\*.*?\*/!s', '', $oc);
$ocCode  = preg_replace('/^\s*--.*$/m', '', $ocCode);

ok(!str_contains($invCode, 'production_transactions'),
   'includes/inventory.php no longer reads the old table');
ok(!str_contains($ocCode, 'production_transactions'),
   'inv_ordercost.php no longer reads it either');
ok(substr_count($ocCode, 'FROM zp_entries') === 2,
   '  both of its wage queries moved, got ' . substr_count($ocCode, 'FROM zp_entries'));
/* The columns are NOT the same in the two tables. production_transactions has
   `quantity`; zp_entries has `qty`. A straight table swap would have thrown. */
ok(str_contains($oc, 'COALESCE(SUM(qty),0) q'), '  and the column name moved with it');
ok(str_contains($inv, 'THIS FUNCTION WAS READING A TABLE NOTHING HAS WRITTEN'),
   'the repair says what was wrong, at the place it was wrong');

echo "2. Stages are decided by position, never by spelling\n";
$a = strpos($inv, 'function inv_line_production');
$b = strpos($inv, "\n}\n", $a);
$fn = substr($inv, $a, $b - $a + 3);
$fnCode = preg_replace('!/\*.*?\*/!s', '', $fn);
ok(str_contains($fnCode, 'zp_stage_first_id()'), 'the first stage is asked for, not spelled');
ok(!preg_match("/==\s*'Cutting'|'Cutting'\s*==/", $fnCode), "nothing is compared to the word 'Cutting'");
ok(!preg_match("/==\s*'Stitching'|'Stitching'\s*==/", $fnCode), "  nor to 'Stitching'");

/* ------------------------------------------------------------------ */
echo "3. Run against a floor that has really booked work\n";

/* A fake PDO that answers each of the function's queries by shape. Nothing
   is mocked away that the function decides for itself — the parts, the
   operations and the per-set quantities are all real inputs. */
final class FStmt {
    public array $rows = [];
    public function __construct(private string $sql, private object $db) {}
    public function execute(array $a = []): bool { $this->rows = ($this->db->answer)($this->sql, $a); return true; }
    public function fetchAll(): array { return $this->rows; }
    public function fetch() { return $this->rows[0] ?? false; }
    public function fetchColumn() { $r = $this->rows[0] ?? null; return $r === null ? false : reset($r); }
}
final class FDb {
    public $answer;
    public function prepare(string $s) { return new FStmt($s, $this); }
    public function query(string $s) { $st = new FStmt($s, $this); $st->execute([]); return $st; }
    public function exec(string $s) { return 1; }
}
$DB = new FDb();
function db() { global $DB; return $DB; }

/* --- the world the fake database describes ---
   Product 5 "Comforter Set 7 Pc", size King (size id 90).
   Parts: Comforter x1, Pillow Case x2, Cushion Cover x2.
   Cushion Cover has TWO operations — cut and pipe — and only 300 have been
   piped though 900 were cut. That is the whole point of the minimum. */
$PARTS = [
    ['id'=>11, 'part_name'=>'Comforter'],
    ['id'=>12, 'part_name'=>'Pillow Case'],
    ['id'=>13, 'part_name'=>'Cushion Cover'],
];
$OPS = [11 => [['id'=>101]], 12 => [['id'=>102]], 13 => [['id'=>103], ['id'=>104]]];
$PER = [11 => 1.0, 12 => 2.0, 13 => 2.0];
$ENTRIES = [
    /* part, op, stage_id, stage, qty, amount */
    [11, 101, 2, 'Stitching', 400, 4000],
    [12, 102, 2, 'Stitching', 900, 5400],
    [13, 103, 1, 'Cutting',   900, 3150],
    [13, 104, 2, 'Stitching', 300, 2775],   // only 300 piped
    [0,  105, 3, 'Packing',   150, 1800],   // set-level work, no part
];
$CONVERTED = 0.0;

$DB->answer = function (string $sql, array $args) use (&$ENTRIES, &$CONVERTED) {
    if (str_contains($sql, 'FROM proforma_items pi WHERE pi.id=?'))
        return [['item_id'=>77, 'proforma_id'=>9, 'product_id'=>5, 'product_name'=>'Comforter Set 7 Pc',
                 'ordered_qty'=>500, 'size'=>'King', 'product_size_id'=>90]];
    if (str_contains($sql, 'FROM zp_entries e'))
        return array_map(fn($e) => ['part_id'=>$e[0], 'op_id'=>$e[1], 'stage_id'=>$e[2],
                                    'stage'=>$e[3], 'q'=>$e[4], 'a'=>$e[5]], $ENTRIES);
    if (str_contains($sql, 'inv_consumption_items ci')) return [['c'=>$CONVERTED]];
    return [];
};

/* The production module's own functions, standing in for the schema. */
function zp_part_ops(int $p, bool $a = false): array { global $OPS; return $OPS[$p] ?? []; }
function zp_product_parts(int $p): array { global $PARTS; return $p === 5 ? $PARTS : []; }
function zp_qty_map(int $p): array { global $PER; return $PER; }
function zp_qty_for(array $m, int $partId, int $sizeId): float { return (float)($m[$partId] ?? 1); }
function zp_line_size_id(array $l): int { return (int)($l['product_size_id'] ?? 0); }
function zp_stage_first_id(): int { return 1; }
function zp_stage_name(int $id): string { return [1=>'Cutting', 2=>'Stitching', 3=>'Packing'][$id] ?? ''; }
function zp_resolve_item(array $r): int { return (int)($r['product_id'] ?? 0); }

eval(preg_replace('/^function inv_line_production/m', 'function inv_line_production', $fn));

$p = inv_line_production(77);

ok($p['ordered'] === 500.0, 'the ordered quantity is read, got ' . json_encode($p['ordered']));
ok($p['started'] === true,  'THE LINE READS AS STARTED — it reported false before, and the picker dropped it');
ok(abs($p['wage'] - 17125) < 0.001, 'the wage is the sum of what was booked, got ' . $p['wage']);

/* THE MINIMUM ACROSS OPERATIONS.
   Cushion Cover: 900 cut, 300 piped -> 300 real cushion covers, 2 per set
   -> 150 sets. Comforter 400/1 = 400. Pillow 900/2 = 450. So 150, and the
   cushion cover is what is holding it. */
ok($p['finished'] === 150.0,
   'finished is 150 SETS, held down by the operation least done, got ' . json_encode($p['finished']));
ok($p['limiting'] === 'Cushion Cover', 'and it names what holds it: ' . json_encode($p['limiting']));
ok($p['by_part']['Cushion Cover']['done'] === 300.0,
   '  the cushion cover counts 300, not the 900 that were cut');
ok($p['by_part']['Pillow Case']['sets'] === 450.0, '  900 pillow cases at 2 per set is 450 sets');

/* Stages roll up by NAME but are decided by id. */
ok(abs($p['cut'] - 900) < 0.001, 'the first stage is counted as cut, got ' . $p['cut']);
/* 400 + 900 + 300 + 150 = 1750. Packing counts here too, and that is right:
   'stitched' now means "booked at a stage after the first", not the stage
   that happens to be spelled Stitching. These three fields survive only for
   callers written against the old module — nothing in the app reads them any
   more, which is why they can carry a broader meaning safely. */
ok(abs($p['stitched'] - 1750) < 0.001, 'later stages are counted after it, got ' . $p['stitched']);
ok(array_keys($p['stages']) === ['Stitching', 'Cutting', 'Packing'],
   'every stage that has work appears, got ' . json_encode(array_keys($p['stages'])));
ok(abs($p['stages']['Packing']['qty'] - 150) < 0.001,
   'A STAGE THE OWNER TYPED HIMSELF IS COUNTED — the old code could not see it');
ok(abs($p['stages']['Packing']['wage'] - 1800) < 0.001, '  and so is its wage');

ok(abs($p['wage_per_unit'] - (17125 / 150)) < 0.001,
   'wage per unit divides by the sets really finished, got ' . $p['wage_per_unit']);
ok($p['available'] === 150.0, 'nothing converted yet, so all 150 are available');

/* --- the block that was shutting the door --- */
$CONVERTED = 150.0;
$p2 = inv_line_production(77);
ok($p2['converted'] === 150.0, 'what is already converted is read back');
ok($p2['available'] === 0.0, '  and available falls to zero, so it cannot be converted twice');
$CONVERTED = 40.0;
$p3 = inv_line_production(77);
ok($p3['available'] === 110.0, 'part-converted leaves the rest available, got ' . $p3['available']);

/* --- a line nobody has touched --- */
$ENTRIES = [];
$CONVERTED = 0.0;
$p4 = inv_line_production(77);
ok($p4['started'] === false, 'a line with no work booked is not started');
ok($p4['finished'] === 0.0, '  and has finished nothing');
ok($p4['wage_per_unit'] === 0.0, '  and a divide by zero does not happen');

echo "4. A product with no parts keeps the old meaning\n";
/* Nothing that worked before this repair may stop working. */
$PARTS = [];
$ENTRIES = [[0, 105, 2, 'Stitching', 260, 5200]];
$p5 = inv_line_production(77);
ok($p5['finished'] === 260.0, 'no parts defined falls back to the last stage worked, got ' . json_encode($p5['finished']));
ok($p5['started'] === true,  '  and it still counts as started');

echo "5. Nothing is written from here\n";
ok(!preg_match('/\b(INSERT|UPDATE|DELETE)\b/i', $fnCode),
   'the bridge only ever reads — production is never written from the store');

echo "\n$P passed, $F failed\n";
exit($F ? 1 : 0);
