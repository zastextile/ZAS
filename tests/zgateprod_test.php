<?php
/* "STOCK NOT SHOWING ON THE GATE LINE" — reported from a live gate-out pass.
 *
 * The picker was widened to carry finished products as well as materials.
 * The Available column on the LINE was not. It asked matIdOf(), which returns
 * 0 for a "p<id>" key, so it gave up before it asked the server — the picker
 * would show a product with 300 in stock and the cell beside it stayed on a
 * dash for ever. The over-issue warning reads the same number, so that never
 * fired on a product either: you could book out more than you had, and
 * nothing on the screen would say so.
 *
 * Underneath, inv_lot_balances() takes a material id, so nothing built on it
 * could answer for a product at all.
 */

$B = __DIR__ . '/app_src/public_html/';
$P = 0; $F = 0;
function ok($c, $m) { global $P, $F; if ($c) { $P++; } else { $F++; echo "  FAIL: $m\n"; } }

$inv  = file_get_contents($B . 'includes/inventory.php');
$gate = file_get_contents($B . 'inv_gate.php');

echo "1. A balance can be asked for by item key\n";
ok(str_contains($inv, 'function inv_lot_balances_key('), 'inv_lot_balances_key exists');
ok(str_contains($inv, 'function inv_available_key('),   'inv_available_key exists');
ok(str_contains($inv, 'WHERE product_id = ?'), 'it really queries the ledger by product');
ok(str_contains($inv, "COALESCE(size_label,'') lot_no"),
   "a product is split by SIZE, and the size is returned where the screens look for a lot");
ok(str_contains($inv, "A PRODUCT'S SUB-KEY IS ITS SIZE, NOT A LOT"),
   '  and it says so, because that is the thing somebody will trip over');
/* The old function must still exist untouched — a dozen screens call it. */
ok(str_contains($inv, 'function inv_lot_balances(int $materialId'), 'the material version is left exactly as it was');

/* --- RUN IT. A fake ledger, answering the two shapes of query. --- */
final class FStmt {
    public array $rows = [];
    public function __construct(private string $sql, private object $db) {}
    public function execute(array $a = []): bool { $this->rows = ($this->db->answer)($this->sql, $a); return true; }
    public function fetchAll(): array { return $this->rows; }
}
final class FDb {
    public $answer;
    public function prepare(string $s) { return new FStmt($s, $this); }
    public function query(string $s) { $st = new FStmt($s, $this); $st->execute([]); return $st; }
}
$DB = new FDb();
function db() { global $DB; return $DB; }
function inv_location_name(?int $id): string { return [1=>'Main Store', 2=>'Finished Goods'][$id] ?? '—'; }

function lift(string $src, string $name): string {
    $a = strpos($src, 'function ' . $name . '(');
    $b = strpos($src, "\n}\n", $a);
    return substr($src, $a, $b - $a + 3);
}
eval(lift($inv, 'inv_split_key'));
eval(lift($inv, 'inv_lot_balances'));
eval(lift($inv, 'inv_lot_balances_key'));
eval(lift($inv, 'inv_available_key'));

$PRODROWS = [
    ['lot_no'=>'King',  'location_id'=>2, 'ownership'=>'own', 'bal'=>300, 'val'=>150000, 'first_in'=>'2026-09-01', 'last_move'=>'2026-09-10'],
    ['lot_no'=>'Queen', 'location_id'=>2, 'ownership'=>'own', 'bal'=>120, 'val'=>54000,  'first_in'=>'2026-09-02', 'last_move'=>'2026-09-11'],
    ['lot_no'=>'King',  'location_id'=>1, 'ownership'=>'own', 'bal'=>40,  'val'=>20000,  'first_in'=>'2026-09-03', 'last_move'=>'2026-09-12'],
    ['lot_no'=>'King',  'location_id'=>2, 'ownership'=>'customer', 'bal'=>500, 'val'=>0,  'first_in'=>'2026-09-04', 'last_move'=>'2026-09-13'],
    ['lot_no'=>'Single','location_id'=>2, 'ownership'=>'own', 'bal'=>0,   'val'=>0,      'first_in'=>'2026-09-05', 'last_move'=>'2026-09-14'],
];
$MATROWS = [
    ['lot_no'=>'LOT-7', 'location_id'=>1, 'ownership'=>'own', 'bal'=>900, 'val'=>189000, 'first_in'=>'2026-09-01', 'last_move'=>'2026-09-09'],
];
$DB->answer = function (string $sql) use (&$PRODROWS, &$MATROWS) {
    if (str_contains($sql, 'WHERE product_id = ?')) return $PRODROWS;
    if (str_contains($sql, 'WHERE material_id = ?')) return $MATROWS;
    return [];
};

echo "2. Run against a ledger that holds both\n";

$pl = inv_lot_balances_key('p9', true);
ok(count($pl) === 4, 'a product returns its piles, zero ones dropped, got ' . count($pl));
ok($pl[0]['size_label'] === 'King', '  and each carries the size by its right name');
ok($pl[0]['lot_no'] === 'King', '  in the lot field too, so no screen needs a second code path');
ok(abs($pl[0]['rate'] - 500) < 0.001, '  with a rate worked out from the value, got ' . $pl[0]['rate']);

$ml = inv_lot_balances_key('m12', true);
ok(count($ml) === 1 && $ml[0]['lot_no'] === 'LOT-7', 'a material still answers exactly as before');
ok(inv_lot_balances_key('', true) === [], 'an empty key answers nothing rather than guessing');
ok(inv_lot_balances_key('x5', true) === [], 'an unknown kind answers nothing too');

/* THE NUMBER THE GATE LINE SHOWS. */
ok(inv_available_key('p9', '', 2, 'own') === 420.0,
   'the whole pile at a location, got ' . inv_available_key('p9', '', 2, 'own'));
ok(inv_available_key('p9', 'King', 2, 'own') === 300.0,
   'narrowed to one size, got ' . inv_available_key('p9', 'King', 2, 'own'));
ok(inv_available_key('p9', '', 1, 'own') === 40.0, 'a different floor is a different number');
ok(inv_available_key('p9', '', 0, 'own') === 460.0, 'no location means everywhere');
/* CUSTOMER-OWNED GOODS ARE NOT YOURS. 500 of them sit at the same place. */
ok(inv_available_key('p9', '', 2, 'own') === 420.0,
   'customer-owned stock is NOT counted as yours');
ok(inv_available_key('p9', '', 2, 'customer') === 500.0, '  it is counted separately, when asked for');

echo "3. The gate asks by key, and narrows by the right field\n";
$gcode = preg_replace('!/\*.*?\*/!s', '', $gate);
ok(str_contains($gcode, "ajax=onhand&item_key="), 'the line asks the server by item key');
ok(str_contains($gcode, "inv_lot_balances_key(\$key, true)"), '  and the endpoint answers by key');
ok(str_contains($gcode, "inv_available_key(\$key, '', \$loc, \$own)"), '  including the total');
/* An older page left open in a tab still asks the old way. */
ok(str_contains($gcode, "\$mid = (int)(\$_GET['material_id'] ?? 0)"),
   'material_id is still accepted, so a page left open in a tab keeps working');

ok(str_contains($gcode, 'function subOf(tr)'), 'the row knows what narrows it');
ok(preg_match('/function subOf\(tr\)\{.*?\.szl.*?\}/s', $gcode) === 1,
   '  a product by its size box');
ok(preg_match('/function subOf\(tr\)\{.*?\.lot.*?\}/s', $gcode) === 1,
   '  a material by its lot box');
ok(!preg_match('/function availFor\(tr\)\{[^}]*matIdOf/s', $gcode),
   'availFor no longer asks for a material id, which is what returned 0 on a product');
ok(str_contains($gcode, "var word = d.kind === 'prod' ? 'size' : 'lot';"),
   'and the sentence under the box says size for a product, lot for a material');

echo "4. The size box drives the cell it belongs to\n";
/* The size lives on the DETAIL strip — a different <tr>. closest('tr') from
   it returns the strip, which has no Available cell, so typing a size used to
   repaint nothing at all. */
ok(str_contains($gcode, 'function mainRow(el)'), 'there is a way back from the strip to its line');
ok(str_contains($gcode, 'if(IS_OUT){ var tr=mainRow(e.target); if(tr) refreshRow(tr); stockCheck(); }'),
   '  and the keystroke handler uses it');
ok(str_contains($gate, 'THE SIZE BOX LIVES ON THE DETAIL ROW'), '  with the reason recorded');

echo "\n$P passed, $F failed\n";
exit($F ? 1 : 0);
