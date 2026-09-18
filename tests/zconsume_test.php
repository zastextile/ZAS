<?php
/* CONSUMPTION AGAINST WHAT WAS ACTUALLY PACKED.
 *
 * "where i asked only allow consumption against assembled items instead
 *  opened also allow multiple assembled lines as show list of ready packed
 *  assembled product so can use stock and keep ledger ... I need fast way
 *  AG grid enter next move and last enter next line so when CTRL + S will
 *  be save an esc quit form and ask same as window / office files"
 */
$B = __DIR__ . '/app_src/public_html/';
$P = 0; $F = 0;
function ok($c, $m) { global $P, $F; if ($c) { $P++; } else { $F++; echo "  FAIL: $m\n"; } }
$zp = file_get_contents($B . 'includes/zprod.php');
$ic = file_get_contents($B . 'inv_consume.php');
function lift(string $src, string $from): string {
    $a = strpos($src, $from); if ($a === false) return '';
    $b = strpos($src, "\n}\n", $a); return substr($src, $a, $b - $a + 3);
}

echo "1. What is packed and still unused\n";
$MADE = [
  ['product_id'=>7,'size_label'=>'King','q'=>300,'product_name'=>'7pc Bed in Bag'],
  ['product_id'=>7,'size_label'=>'Queen','q'=>80,'product_name'=>'7pc Bed in Bag'],
  ['product_id'=>9,'size_label'=>'King','q'=>50,'product_name'=>'Duvet 4pc'],
];
$USED = [
  ['product_id'=>7,'size_label'=>'King','q'=>120],
  ['product_id'=>9,'size_label'=>'King','q'=>50],     // fully used up
];
function zp_ensure_schema(): void {}
final class CStmt {
    public function __construct(private string $sql) {}
    public function execute($a = []) { return true; }
    public function fetchAll() {
        global $MADE, $USED;
        if (str_contains($this->sql, 'FROM zp_assembly a')) return $MADE;
        if (str_contains($this->sql, 'FROM inv_consumption_items')) return $USED;
        return [];
    }
    public function fetchColumn() { return 0; }
}
final class CDb {
    public array $log = [];
    public function prepare($s) { $this->log[] = $s; return new CStmt($s); }
    public function query($s) { $this->log[] = $s; return new CStmt($s); }
}
$DB = new CDb(); function db() { global $DB; return $DB; }
eval(lift($zp, 'function zp_assembled_stock('));

$st = zp_assembled_stock();
$by = []; foreach ($st as $r) $by[$r['product_id'] . '|' . $r['size']] = $r;
ok(($by['7|King']['left'] ?? null) === 180.0,
   '300 packed less 120 already consumed is 180, got ' . ($by['7|King']['left'] ?? 'null'));
ok(($by['7|Queen']['left'] ?? null) === 80.0, 'a size nothing has been consumed from is all there');
ok(!isset($by['9|King']),
   'A FULLY CONSUMED SET IS NOT OFFERED — a list of things you cannot take is one you distrust');
ok($st[0]['size'] === 'King' && $st[0]['product_id'] === 7, 'biggest balance first, got ' . json_encode($st[0]));
ok(str_contains($DB->log[1] ?? '', "<> 'cancelled'"),
   'a cancelled consumption sheet does not count as used');
ok(str_contains($DB->log[0] ?? '', "a.status='active'"), 'nor does a cancelled assembly count as made');

echo "2. The screen offers only those\n";
ok(str_contains($ic, '$ASSEMBLED = function_exists(\'zp_assembled_stock\') ? zp_assembled_stock() : [];'),
   'the page reads the packed list');
ok(str_contains($ic, 'function_exists(\'zp_assembled_stock\')'),
   '  and does not die if the production module is absent');
ok(str_contains($ic, '— packed sets ready to consume —'), 'the box says what it holds');
ok(str_contains($ic, 'ready</option>') || str_contains($ic, ' ready'), '  with how many are ready on each row');
ok(!str_contains($ic, '— select product —'), 'THE OPEN PRODUCT LIST IS GONE');
ok(str_contains($ic, 'already on this sheet'),
   'but a line saved earlier keeps its own product, so an old sheet never empties itself');
/* MULTIPLE LINES — he asked for it twice. */
ok(str_contains($ic, "id=\"addo\""), 'more than one packed set can go on a sheet');

echo "3. The product and the size travel together\n";
ok(str_contains($ic, 'name="out[<?= $i ?>][asm]"'), 'one box carries both');
ok(str_contains($ic, "array_pad(explode('|', (string)(\$ln['asm'] ?? ''), 2), 2, '')"),
   'and the save splits it back');
ok(str_contains($ic, "function asmPid(v)") && str_contains($ic, "function asmSize(v)"),
   'the browser reads it the same way');
ok(!str_contains($ic, ".osize"), 'and the old separate size box is gone everywhere');

echo "4. You cannot consume sets nobody packed\n";
ok(str_contains($ic, '$asmBad[] = \'line \' . ((int)$li + 1) . \' asks for \''),
   'the check names the line');
ok(str_contains($ic, 'set(s) are packed and unused'), '  and how many really are there');
ok(str_contains($ic, 'Assemble them first on Assembly — Make Sets'), '  and what to do about it');
/* THE SHEET BEING EDITED MUST NOT REFUSE ITSELF. Its own output is already
   inside the "used" figure, so reopening and re-saving would look like an
   over-consumption. */
$icF = preg_replace('/\s+/', ' ', $ic);
ok(str_contains($icF, 'The sheet being EDITED is allowed to keep the quantity it already has'),
   'reopening a saved sheet does not refuse itself');
ok(str_contains($ic, "\$asmLeft[\$k] = (\$asmLeft[\$k] ?? 0) + (float)\$r['q'];"),
   '  because its own lines are added back before the check');
/* REFUSED BEFORE THE TRANSACTION, so nothing is half-written. */
ok(strpos($ic, '$asmBad') < strpos($ic, 'db()->beginTransaction();', strpos($ic, '$asmBad')),
   'and the check runs BEFORE anything is written');

echo "5. The office keys\n";
ok(str_contains($ic, 'assets/js/grid.js'), 'the shared keys file is loaded');
ok(str_contains($ic, "GRID.keys(f, { badge:'#conState'"), 'Ctrl+S and Escape are wired');
ok(str_contains($ic, "escapeTo:'inv_consume.php'"), '  Escape goes back, asking if there is unsaved work');
ok(str_contains($ic, 'id="conState"'), 'and a saved / unsaved word sits by the button');
ok(str_contains($ic, "e.preventDefault();                       // never submit from inside a grid"),
   'ENTER INSIDE A GRID CAN NEVER SUBMIT — the fault that cost a day on the gate');
ok(str_contains($ic, "if(!tr.closest('#inT') && !tr.closest('#outT')) return;"),
   'the walk is confined to the two grids, so the header behaves normally');
ok(str_contains($ic, "tr.closest('#outT') ? document.getElementById('addo') : document.getElementById('addi')"),
   'and off the last line it presses the right Add button');
ok(str_contains($ic, 'if(window.LOV && LOV.isOpen && LOV.isOpen()) return;'),
   'an open picker still owns Enter');

echo "\n$P passed, $F failed\n";
exit($F ? 1 : 0);
