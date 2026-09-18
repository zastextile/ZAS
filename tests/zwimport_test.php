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
ok(str_contains($pw, 'if (window.LOV) LOV.attach(tb);'), 'the worker grid attaches the picker');
ok(strpos($pw, 'LOV.attach(tb)') < strpos($pw, 'GRID.attach(tb'),
   '  before the grid, so the picker sees the key first');

echo "2. The two lists are real pickers, not datalists\n";
ok(str_contains($pw, 'data-lov="wdept"'), 'department is a picker');
ok(str_contains($pw, 'data-lov="wstage"'), 'stages is a picker');
ok(!str_contains($pw, '<datalist id="impDepts"'), 'the old datalists are gone');
ok(!str_contains($pw, '<datalist id="impStages"'), '  both of them');
ok(str_contains($pw, "LOV.register('wstage'"), 'the stage picker is registered');
ok(str_contains($pw, 'ADDS, IT DOES NOT REPLACE'),
   'and taking a stage ADDS it, because a person can work several');

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
echo "7. Driven in a browser, WITHOUT THE MOUSE\n";

$ga = strpos($pw, '<table class="zp-t" id="impTbl">');
$gb = strpos($pw, '</table>', $ga);
$frag = substr($pw, $ga, $gb - $ga + strlen('</table>'));

/* ZP_CODE_MAX GOES INTO THE TEMPLATE TOO. The fragment is rendered by a
   separate php process, so a constant defined in this one does not reach
   it — the grid rendered "Undefined constant" straight into the HTML and
   the browser step then had nothing to drive. Lifted, not invented. */
$tpl = '<?php
const ZP_CODE_MAX = ' . ZP_CODE_MAX . ';
function e($s){ return htmlspecialchars((string)$s, ENT_QUOTES); }
$stages   = [["id"=>1,"name"=>"Cutting"],["id"=>2,"name"=>"Stitching"],["id"=>3,"name"=>"Packing"]];
$impRows  = array_fill(0, 3, ["code"=>"","name"=>"","dept"=>"","stages"=>""]);
?>' . $frag;
file_put_contents($work . '/frag.php', $tpl);
$html = (string)shell_exec('php ' . escapeshellarg($work . '/frag.php') . ' 2>&1');
ok(!str_contains($html, 'Fatal') && !str_contains($html, 'Warning'),
   'the grid renders: ' . substr(trim($html), 0, 200));

/* the two registrations, lifted from the page */
/* Anchored on the block's own boundaries rather than on the line that
   happens to follow it — helper functions were added inside it and the old
   anchor stopped matching, which silently lifted nothing. */
$ra = strpos($pw, 'if (window.LOV) {');
$rb = strpos($pw, "\n}\n</script>", $ra);
$regs = substr($pw, $ra, $rb - $ra + 3);
ok($regs !== '' && str_contains($regs, "LOV.register('wstage'"), 'both registrations lifted');

$page = '<!doctype html><html><head><meta charset="utf-8">'
  . '<style>' . file_get_contents($B . 'assets/css/lov.css') . '</style>'
  . '<style>.zin{width:100%;padding:6px;font:13px system-ui}table{border-collapse:collapse;width:900px}'
  . 'td,th{border:1px solid #ddd;padding:2px}</style></head><body>'
  . $html
  . '<scr' . 'ipt>' . file_get_contents($B . 'assets/js/lov.js') . '</scr' . 'ipt>'
  . '<scr' . 'ipt>' . file_get_contents($B . 'assets/js/grid.js') . '</scr' . 'ipt>'
  . '<scr' . 'ipt>'
  . 'var WDEPTS = ["Packing Hall","Stitching Floor 1","Stitching Floor 2"];'
  . 'var WSTAGES = ["Cutting","Stitching","Packing"];'
  . $regs
  . 'var tb = document.querySelector("#impTbl tbody");'
  . 'function blankRow(){ var t = tb.rows[tb.rows.length-1].cloneNode(true);'
  . '  [].forEach.call(t.querySelectorAll("input"), function(el){ el.value=""; });'
  . '  tb.appendChild(t); return t; }'
  . 'LOV.attach(tb);'
  . 'GRID.attach(tb, { cols:["code","name","dept","stages"], addRow: blankRow });'
  . 'window.read = function(){ return [].map.call(tb.rows, function(tr){'
  . '  return ["code","name","dept","stages"].map(function(c){'
  . '    return tr.querySelector(\'[data-c="\'+c+\'"]\').value; }); }); };'
  . '</scr' . 'ipt></body></html>';
file_put_contents($work . '/live.html', $page);

$drive = <<<'JS'
const { chromium } = require('playwright');
(async () => {
  const br = await chromium.launch({ executablePath: '/opt/pw-browsers/chromium' });
  const pg = await br.newPage({ viewport: { width: 1100, height: 800 } });
  const errs = []; pg.on('pageerror', e => errs.push(String(e)));
  await pg.goto('file://' + process.argv[2] + '/live.html');
  await pg.waitForTimeout(200);
  const R = { errs };

  /* THE ONLY focus() IN THIS WHOLE SCRIPT. After it, nothing but keys —
     if any step below needed the mouse, the feature has failed. */
  await pg.evaluate(() => document.querySelector('[data-c="code"]').focus());

  // paste a block out of "Excel": three rows, tab separated
  await pg.evaluate(() => {
    const dt = new DataTransfer();
    dt.setData('text/plain',
      'W401\tNasreen Bibi\tPacking Hall\tPacking\n' +
      '\tImran Yousaf\tStitching Floor 1\tStitching\n' +
      '\tAdnan Sharif\tPacking Hall\tPacking');
    document.activeElement.dispatchEvent(
      new ClipboardEvent('paste', { clipboardData: dt, bubbles: true, cancelable: true }));
  });
  await pg.waitForTimeout(200);
  R.afterPaste = await pg.evaluate(() => window.read());

  // Tab to the stages cell of row 1 and open the list with ENTER
  await pg.evaluate(() => {
    document.querySelector('#impTbl tbody tr:nth-child(1) [data-c="stages"]').focus();
  });
  await pg.keyboard.press('Escape');            // shut whatever focus opened
  await pg.waitForTimeout(120);
  R.shutAfterEscape = await pg.evaluate(() => !window.LOV.isOpen());

  await pg.keyboard.press('Enter');             // <-- the whole point
  await pg.waitForTimeout(200);
  R.openedByEnter = await pg.evaluate(() => window.LOV.isOpen());
  R.rowCountAfterEnter = await pg.evaluate(() => window.read().length);
  R.list = await pg.evaluate(() =>
    [...document.querySelectorAll('.lov .lov-r')].map(r => r.children[0].textContent.trim()));

  // choose with the keyboard, then add a SECOND stage the same way
  await pg.keyboard.press('ArrowDown');
  await pg.keyboard.press('Enter');
  await pg.waitForTimeout(150);
  R.afterFirstStage = await pg.evaluate(() =>
    document.querySelector('#impTbl tbody tr:nth-child(1) [data-c="stages"]').value);

  await pg.keyboard.press('Enter');             // open again
  await pg.waitForTimeout(180);
  R.reopened = await pg.evaluate(() => window.LOV.isOpen());
  await pg.keyboard.press('Enter');             // take the top row
  await pg.waitForTimeout(150);
  R.afterSecondStage = await pg.evaluate(() =>
    document.querySelector('#impTbl tbody tr:nth-child(1) [data-c="stages"]').value);

  // and once more — the same stage must not go in twice
  await pg.keyboard.press('Enter');
  await pg.waitForTimeout(180);
  await pg.keyboard.press('Enter');
  await pg.waitForTimeout(150);
  R.afterThird = await pg.evaluate(() =>
    document.querySelector('#impTbl tbody tr:nth-child(1) [data-c="stages"]').value);

  R.rows = await pg.evaluate(() => window.read());
  await br.close();
  console.log(JSON.stringify(R));
})();
JS;
file_put_contents($work . '/drive.js', $drive);
$raw = shell_exec('cd ' . escapeshellarg(__DIR__) . ' && node ' . escapeshellarg($work . '/drive.js')
                  . ' ' . escapeshellarg($work) . ' 2>&1');
$R = json_decode((string)$raw, true);

if (!is_array($R)) { echo "  FAIL: the driver did not run:\n" . substr((string)$raw, 0, 900) . "\n"; $F++; }
else {
    ok(empty($R['errs']), 'it runs clean: ' . json_encode($R['errs']));

    ok(count($R['afterPaste']) >= 3, 'the paste made rows, got ' . count($R['afterPaste']));
    ok($R['afterPaste'][0] === ['W401', 'Nasreen Bibi', 'Packing Hall', 'Packing'],
       'row 1 landed in the right columns, got ' . json_encode($R['afterPaste'][0]));
    ok($R['afterPaste'][1][1] === 'Imran Yousaf' && $R['afterPaste'][1][0] === '',
       'a blank code stays blank, got ' . json_encode($R['afterPaste'][1]));
    ok($R['afterPaste'][2][1] === 'Adnan Sharif',
       'THE THIRD ROW WAS ADDED BY THE PASTE — the sheet started with three and'
       . ' the paste filled them, got ' . json_encode($R['afterPaste'][2]));

    ok($R['shutAfterEscape'] === true, 'Escape shuts the list');
    ok($R['openedByEnter'] === true, 'AND ENTER OPENS IT AGAIN');
    /* THE COLLISION. Without stopImmediatePropagation the same Enter would
       ALSO have moved the cursor down a row — and on the last row, the grid
       would have ADDED one. */
    ok($R['rowCountAfterEnter'] === count($R['afterPaste']),
       '  and does NOT also move down a row or add one, got '
       . $R['rowCountAfterEnter'] . ' rows against ' . count($R['afterPaste']));
    ok($R['list'] === ['Cutting', 'Stitching', 'Packing'],
       'the list holds the stages, got ' . json_encode($R['list']));

    /* THE CELL IS NOT EMPTY HERE. The paste above put "Packing" in it, which
       is the realistic case: a sheet comes in from Excel with one stage per
       worker and the floor in-charge adds the rest by keyboard. So every
       value below is the pasted stage PLUS what the keyboard added. */
    ok($R['afterFirstStage'] === 'Packing, Stitching',
       'arrow down and Enter takes one, got ' . json_encode($R['afterFirstStage']));
    ok($R['reopened'] === true, 'Enter opens it again on a cell that already has one');
    ok($R['afterSecondStage'] === 'Packing, Stitching, Cutting',
       'AND THE SECOND IS ADDED, not swapped, got ' . json_encode($R['afterSecondStage']));
    ok($R['afterThird'] === 'Packing, Stitching, Cutting',
       'taking the same one twice does not put it in twice, got ' . json_encode($R['afterThird']));
    /* AND THE CURSOR NEVER LEFT. Three takes in a row can only land in the
       same cell if Enter stopped moving down — which is the whole fix. */
    ok($R['rows'][1][3] === 'Stitching' && $R['rows'][2][3] === 'Packing',
       '  rows 2 and 3 are untouched, got ' . json_encode([$R['rows'][1][3] ?? null, $R['rows'][2][3] ?? null]));
}

echo "\n$P passed, $F failed\n";
exit($F ? 1 : 0);
