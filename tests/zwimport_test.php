<?php
/* ADDING MANY WORKERS AT ONCE — pasted from Excel, driven by the keyboard.
 *
 * Two things were asked for, a day apart:
 *   "allow me to add worker as multiple import or copy and paste data from
 *    Excel into multiple row as per worker fields"
 *   "I dont want to use mouse so allow here simple enter and list open"
 *
 * So the grid takes a pasted block, and every list on it opens with Enter.
 * The keyboard half is driven in a real browser WITHOUT EVER USING THE
 * MOUSE — if a single step here needed a click, the feature has failed.
 */

$B = __DIR__ . '/app_src/public_html/';
$P = 0; $F = 0;
function ok($c, $m) { global $P, $F; if ($c) { $P++; } else { $F++; echo "  FAIL: $m\n"; } }

$zp  = file_get_contents($B . 'includes/zprod.php');
$pw  = file_get_contents($B . 'production_workers.php');
$lov = file_get_contents($B . 'assets/js/lov.js');

/* A SENTENCE IN A COMMENT IS WRAPPED. Searching a file for a sentence and
   asking it to sit on one line is a test of the line width, not of the
   file. These flattened copies are for the prose checks only; the code
   checks keep the real text. */
function flat(string $s): string { return preg_replace('/\s+/', ' ', $s); }
$zpF = flat($zp); $lovF = flat($lov);

$work = __DIR__ . '/.zwimp';
@mkdir($work, 0777, true);

echo "1. Enter opens the list, everywhere in the app\n";
/* THE RULE SPLIT IN TWO, because Enter had to do two jobs. Down always
   opens. Enter opens an EMPTY box and moves on from a full one — except on
   a field that holds a LIST, where there is always another one to add. */
ok(str_contains($lov, "if (e.key === 'ArrowDown') {"), 'Down opens a picker that is shut');
ok(str_contains($lovF, 'A FIELD THAT HOLDS A LIST IS NEVER "ANSWERED"'),
   'and Enter opens one too, on an empty box or on a list field');
ok(str_contains($lov, 'if (!(pShut && pShut.stayOnEnter)'),
   '  which is what stayOnEnter marks');
ok(str_contains($lov, 'e.stopImmediatePropagation();'),
   'AND THE KEY STOPS THERE — the spreadsheet keys live on the same element');
ok(str_contains($lovF, 'Enter would open the list AND move the cursor down a row'),
   '  with the collision it prevents written down');
ok(str_contains($lovF, 'the answer to "is a list open?" is held true for the rest of THIS key press'),
   '  and the hold that makes the answer independent of listener order');
ok(str_contains($lov, 'return S.open || S.hold;'), 'isOpen() reports the hold too');
/* The grid's own guard is the other half of the pair. Between the two, the
   order the two libraries are attached in cannot matter. */
$grid = file_get_contents($B . 'assets/js/grid.js');
ok(str_contains($grid, 'window.LOV && window.LOV.isOpen && window.LOV.isOpen()'),
   'and the grid still stands aside when a list is open');

echo "2. Since 18 Nov 2026 people are not pasted here — they come from People Finance\n";
ok(!str_contains($pw, 'id="impTbl"') && !str_contains($pw, 'value="import"'), 'the paste sheet is gone from Production Workers');
ok(str_contains($pw, 'att_finance.php#people'), '  and the screen points to People Finance › Employees, where many are pasted at once');

echo "3. The engine: all of them, or none\n";
$imp = (function (string $src) {
    $a = strpos($src, 'function zp_import_workers');
    $b = strpos($src, "\n}\n", $a);
    return substr($src, $a, $b - $a + 3);
})($zp);
ok($imp !== '' && str_contains($imp, 'beginTransaction'), 'the import lifted, and it is one transaction');
ok(str_contains($zp, 'NOTHING IS WRITTEN UNTIL EVERY ROW PASSES'), '  with the rule stated');
ok(str_contains($zpF, 'pasting it again to be sure creates every worker in that half twice'),
   '  and the reason a half-saved sheet is worse than a refused one');

/* --- run it against a fake database --- */
final class FStmt {
    public array $rows = [];
    public function __construct(public string $sql, private object $db) {}
    public function execute(array $a = []): bool { $this->db->ran[] = [$this->sql, $a]; return true; }
    public function fetchAll(): array { return []; }
    public function fetchColumn() { return 0; }
}
final class FDb {
    public array $ran = []; private int $next = 900;
    public function prepare(string $s) { return new FStmt($s, $this); }
    public function query(string $s) { return new FStmt($s, $this); }
    public function exec(string $s) { return 1; }
    public function beginTransaction() { $this->ran[] = ['BEGIN', []]; return true; }
    public function commit() { $this->ran[] = ['COMMIT', []]; return true; }
    public function rollBack() { $this->ran[] = ['ROLLBACK', []]; return true; }
    public function lastInsertId() { return (string)(++$this->next); }
}
$DB = new FDb();
function db() { global $DB; return $DB; }
function zp_ensure_schema(): void {}
function zp_stage_all(bool $a = false): array {
    return [['id' => 1, 'name' => 'Cutting'], ['id' => 2, 'name' => 'Stitching'],
            ['id' => 3, 'name' => 'Packing']];
}
$WORKERS = [
    ['id' => 1, 'worker_code' => 'W001', 'worker_name' => 'Rashid Ali'],
    ['id' => 2, 'worker_code' => 'W002', 'worker_name' => 'Saima Kausar'],
];
function zp_workers(bool $a = false): array { global $WORKERS; return $WORKERS; }
$SAVED = []; $STAGED = [];
function zp_save_worker(int $id, string $c, string $n, ?string $d, int $act): array {
    global $SAVED;
    $SAVED[] = ['code' => $c, 'name' => $n, 'dept' => $d, 'active' => $act];
    return ['ok' => true, 'id' => 500 + count($SAVED), 'error' => ''];
}
function zp_save_worker_stages(int $wid, array $sids): void {
    global $STAGED; $STAGED[$wid] = $sids;
}
/* ZP_CODE_MAX IS LIFTED, NOT GUESSED. The import reads it, so the test has
   to have the real one — inventing a number here would let the test agree
   with itself while the app used a different limit. */
preg_match('/const ZP_CODE_MAX = (\d+);/', $zp, $mx);
ok(!empty($mx[1]), 'ZP_CODE_MAX found in the engine');
if (!defined('ZP_CODE_MAX')) define('ZP_CODE_MAX', (int)$mx[1]);
eval($imp);

echo "4. A good sheet goes in\n";
$SAVED = []; $STAGED = [];
$r = zp_import_workers([
    ['code' => '',      'name' => 'Nasreen Bibi',  'dept' => 'Packing Hall',      'stages' => 'Packing'],
    ['code' => 'W150',  'name' => 'Imran Yousaf',  'dept' => 'Stitching Floor 1', 'stages' => 'Stitching, Packing'],
    ['code' => '',      'name' => 'Adnan Sharif',  'dept' => '',                  'stages' => ''],
    ['code' => '',      'name' => '',              'dept' => '',                  'stages' => ''],   // trailing blank
]);
ok($r['ok'] === true, 'the sheet is accepted: ' . json_encode($r['errors']));
ok($r['saved'] === 3, 'THREE saved, the blank row ignored, got ' . $r['saved']);
ok($SAVED[0]['code'] === '' && $SAVED[1]['code'] === 'W150',
   'a blank code is passed through so the numbering rule stays in one place');
ok($STAGED[502] === [2, 3], 'two stages on one row, got ' . json_encode($STAGED[502] ?? null));
ok(!isset($STAGED[503]), 'a worker with no stages is left on "any stage", not written as none');
ok($r['warn'] === [], 'nothing to warn about');

echo "5. A sheet with a fault goes nowhere\n";
foreach ([
    [['code' => '', 'name' => '', 'dept' => 'x', 'stages' => ''], 'there is no name'],
    [['code' => 'W001', 'name' => 'Someone', 'dept' => '', 'stages' => ''], 'already belongs to Rashid Ali'],
    [['code' => '', 'name' => 'Someone', 'dept' => '', 'stages' => 'Stiching'], 'no stage is called'],
    /* Longer than the real limit, whatever it is — so this case keeps
       testing the rule and not a number that has since moved. */
    [['code' => str_repeat('X', ZP_CODE_MAX + 5), 'name' => 'Someone', 'dept' => '', 'stages' => ''],
     'longer than ' . ZP_CODE_MAX],
] as [$row, $expect]) {
    $SAVED = [];
    $r2 = zp_import_workers([['code' => '', 'name' => 'Good One', 'dept' => '', 'stages' => ''], $row]);
    ok($r2['ok'] === false, "refused: $expect");
    ok(str_contains(implode(' ', $r2['errors']), $expect),
       "  and says so: " . json_encode($r2['errors']));
    /* THE POINT. The good row before it is NOT saved either. */
    ok($SAVED === [], '  AND THE GOOD ROW BEFORE IT IS NOT SAVED');
}

$SAVED = [];
$r3 = zp_import_workers([
    ['code' => 'W300', 'name' => 'A', 'dept' => '', 'stages' => ''],
    ['code' => 'w300', 'name' => 'B', 'dept' => '', 'stages' => ''],
]);
ok($r3['ok'] === false && str_contains(implode(' ', $r3['errors']), 'twice in this paste'),
   'the same code twice inside one paste is caught, ignoring case: ' . json_encode($r3['errors']));

echo "6. A name already on the list warns, it does not refuse\n";
$SAVED = [];
$r4 = zp_import_workers([['code' => '', 'name' => 'Rashid Ali', 'dept' => '', 'stages' => '']]);
ok($r4['ok'] === true, 'a repeated NAME is allowed — two Rashid Alis is ordinary');
ok($r4['warn'] === ['Rashid Ali'], '  but it is named, got ' . json_encode($r4['warn']));
ok(count($SAVED) === 1, '  and the worker really is added');

/* ------------------------------------------------------------------ */
/* 7. (the browser drive of the paste sheet) — the sheet left this screen on 18 Nov 2026; People Finance has its own, tested in zatt_finweb_test. */
echo "\n$P passed, $F failed\n";
exit($F ? 1 : 0);
