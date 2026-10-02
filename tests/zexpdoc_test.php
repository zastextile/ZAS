<?php
/* THE CUSTOMS AND CHAMBER DOCUMENTS.
 *
 * Two rules decide whether this module is safe, and they are not the same
 * rule:
 *
 *   UNITS ARE LOCKED. A customs document declaring a different quantity from
 *   the goods that shipped describes a shipment that did not happen. It may
 *   be saved half-finished; it must never PRINT.
 *
 *   VALUE IS FREE. A CFR commercial invoice against an FOB declaration
 *   legitimately differs, so the rate is typed by hand — and the difference
 *   has to be visible and logged rather than silent.
 *
 * And one matching rule: "Sheet set", "SHEET SET" and "Sheet-Set" are the
 * same memory and fill themselves, while anything less than identical is
 * OFFERED. That is not fussiness — "Bath Towel" and "Beach Towel" are two
 * letters apart and both are real products.
 */

$B = __DIR__ . '/app_src/public_html/';
$P = 0; $F = 0;
function t(string $n, bool $c, $got = null): void {
    global $P, $F;
    if ($c) { $P++; }
    else { $F++; echo "  FAIL  $n" . ($got !== null ? "\n        got: " . var_export($got, true) : '') . "\n"; }
}
function head(string $n): void { echo "\n$n\n"; }
/* Counts braces instead of hunting for "\n}\n".
 *
 * The simpler version used elsewhere assumes every function body ends on its
 * own line. expdoc_view_ok() is a one-liner, so that version ran straight
 * past it and swallowed the next function too — which PHP then refused to
 * declare twice. Counting braces lifts exactly one function, whatever shape
 * it is written in. Strings and comments inside a body could in principle
 * confuse a counter; none of the functions lifted here contain an unbalanced
 * brace in either, and the redeclare error would say so immediately if that
 * ever changed. */
function lift(string $src, string $from): string {
    $a = strpos($src, $from);
    if ($a === false) return '';
    $open = strpos($src, '{', $a);
    if ($open === false) return '';
    $depth = 0;
    for ($i = $open, $n = strlen($src); $i < $n; $i++) {
        if ($src[$i] === '{') $depth++;
        elseif ($src[$i] === '}') {
            $depth--;
            if ($depth === 0) return substr($src, $a, $i - $a + 1);
        }
    }
    return '';
}

$src = file_get_contents($B . 'includes/exportdocs.php');
t('includes/exportdocs.php was found', $src !== false && $src !== '');

/* ---------------------------------------------------------- the real pieces */
const EXPDOC_VIEWS   = ['customs' => 'Customs', 'chamber' => 'Chamber'];
const EXPDOC_MAX_LEV = 2;
const EXPDOC_MIN_SIM = 80.0;

/* Prove the test's copies of the thresholds are the shipped ones, so this
   file can never quietly test numbers the application does not use. */
t('the thresholds here are the ones in the shipped file',
  str_contains($src, 'EXPDOC_MAX_LEV = 2') && str_contains($src, 'EXPDOC_MIN_SIM = 80.0'));

class FStmt {
    public $p = [];
    public function __construct(public string $sql, public array $rows) {}
    public function execute($a = []) { $this->p = (array)$a; return true; }
    public function fetch() { return $this->rows[0] ?? false; }
    public function fetchAll() { return $this->rows; }
    public function fetchColumn($i = 0) { $r = $this->rows[0] ?? null; return $r === null ? 0 : (is_array($r) ? array_values($r)[$i] ?? 0 : $r); }
}
class FDb {
    public array $A = []; public array $seen = []; public array $wrote = [];
    public function prepare($s) { $this->seen[] = $s; return new FStmt($s, $this->m($s)); }
    public function query($s)   { $this->seen[] = $s; return new FStmt($s, $this->m($s)); }
    public function exec($s)    { $this->seen[] = $s; return 1; }
    public function beginTransaction() { return true; }
    public function commit() { return true; }
    public function rollBack() { return true; }
    public function inTransaction() { return false; }
    public function lastInsertId() { return 1; }
    private function m($s) { foreach ($this->A as $k => $v) if (stripos($s, (string)$k) !== false) return $v; return []; }
}
$DB = new FDb();
function db() { global $DB; return $DB; }
function current_user() { return ['id' => 1, 'role' => 'admin']; }
function is_staff() { return false; }
function is_production_staff() { return false; }
function is_admin() { return true; }
function can_see_rates() { return true; }
function trim_num($n, $d = 3) { return rtrim(rtrim(number_format((float)$n, $d, '.', ''), '0'), '.'); }
$AUDIT = [];
function audit_log($sid, $sec, $fld, $old, $new, $why = '') { global $AUDIT; $AUDIT[] = compact('sid','sec','fld','old','new','why'); }

const EXPDOC_CSV_COLS = ['description', 'hs_code', 'unit', 'qty', 'rate'];
foreach (['expdoc_view_ok','expdoc_norm','expdoc_near','expdoc_memory','expdoc_recall',
          'expdoc_lines','expdoc_source_lines','expdoc_totals','expdoc_check',
          'expdoc_save_lines','expdoc_audit_change','expdoc_from_invoice','expdoc_annotate',
          'expdoc_csv_header_match','expdoc_csv_num','expdoc_parse_csv','expdoc_csv_template'] as $fn) {
    $code = lift($src, 'function ' . $fn . '(');
    if ($code === '') { t("could not lift $fn", false); continue; }
    eval($code);
}

/* ------------------------------------------------------------------------- */
head('1. Caps, spaces and punctuation are the same word');

$same = ['Sheet set', 'SHEET SET', 'sheetset', 'Sheet-Set', 'Sheetset.', '  sheet  set  ', 'Sheet_Set'];
foreach ($same as $s) {
    t('"' . $s . '" normalises to sheetset', expdoc_norm($s) === 'sheetset', expdoc_norm($s));
}
t('an empty string stays empty', expdoc_norm('') === '');
t('digits survive, because 300TC matters', expdoc_norm('300 TC Sateen') === '300tcsateen', expdoc_norm('300 TC Sateen'));

/* ------------------------------------------------------------------------- */
head('2. A typo is close; a different product is not');

t('shetseTs is a near match to sheetset',
  expdoc_near('shetsets', 'sheetset') !== null, expdoc_near('shetsets', 'sheetset'));
t('shet set is a near match',
  expdoc_near(expdoc_norm('shet set'), 'sheetset') !== null);

t('Flat Sheet is NOT a match for Fitted Sheet',
  expdoc_near(expdoc_norm('Flat Sheet'), expdoc_norm('Fitted Sheet')) === null);
t('Bath Towel is NOT a match for Hand Towel',
  expdoc_near(expdoc_norm('Bath Towel'), expdoc_norm('Hand Towel')) === null);
t('Sheet Set is NOT a match for Sheet',
  expdoc_near(expdoc_norm('Sheet Set'), expdoc_norm('Sheet')) === null);
t('Mattress Protector is NOT a match for Mattress Pad',
  expdoc_near(expdoc_norm('Mattress Protector'), expdoc_norm('Mattress Pad')) === null);

/* THE CASE THAT JUSTIFIES THE WHOLE "ASK, DO NOT APPLY" DESIGN. */
$bb = expdoc_near(expdoc_norm('Bath Towel'), expdoc_norm('Beach Towel'));
t('Bath Towel and Beach Towel DO fall inside the near window', $bb !== null, $bb);
t('  so they are not identical, and therefore can never fill silently',
  expdoc_norm('Bath Towel') !== expdoc_norm('Beach Towel'));

t('singular and plural are near, as wanted',
  expdoc_near(expdoc_norm('Pillow Case'), expdoc_norm('Pillow Cases')) !== null);

/* A description longer than levenshtein() accepts must not blow up. */
$long = str_repeat('a', 300);
t('an over-long description returns no match rather than an error',
  expdoc_near($long, 'sheetset') === null);

/* ------------------------------------------------------------------------- */
head('3. Only an exact memory fills itself');

$DB->A = ['FROM exp_doc_memory WHERE view=? AND norm_key=?' => []];
$DB->A['FROM exp_doc_memory WHERE view=? ORDER BY'] = [
    ['id'=>1,'view'=>'customs','norm_key'=>'bathtowel','description'=>'Cotton Terry Towels','hs_code'=>'630260','unit'=>'Pcs','times_used'=>9],
];
$r = expdoc_recall('customs', 'Beach Towel');
t('Beach Towel against a Bath Towel memory comes back as NEAR',
  is_array($r) && $r['kind'] === 'near', $r['kind'] ?? null);

$DB->A['FROM exp_doc_memory WHERE view=? AND norm_key=?'] = [
    ['id'=>1,'view'=>'customs','norm_key'=>'bathtowel','description'=>'Cotton Terry Towels','hs_code'=>'630260','unit'=>'Pcs','times_used'=>9],
];
$r = expdoc_recall('customs', 'BATH  TOWEL');
t('BATH  TOWEL against the same memory is EXACT',
  is_array($r) && $r['kind'] === 'exact', $r['kind'] ?? null);
t('  and brings its HS code with it', ($r['row']['hs_code'] ?? '') === '630260');

/* from_invoice must fill ONLY on exact, leaving a near match alone for the
   screen to query. */
$DB->A = [
  'FROM shipment_items' => [
    ['line_no'=>1,'product_name'=>'Bath Towel','des_col'=>'','optional_value'=>'','qty'=>250,'unit'=>'Pcs','rate'=>17.0,'amount'=>4250],
    ['line_no'=>2,'product_name'=>'Beach Towel','des_col'=>'','optional_value'=>'','qty'=>150,'unit'=>'Pcs','rate'=>17.5,'amount'=>2625],
  ],
  'FROM exp_doc_memory WHERE view=? AND norm_key=?' => [],
  'FROM exp_doc_memory WHERE view=? ORDER BY' => [
    ['id'=>1,'view'=>'customs','norm_key'=>'bathtowel','description'=>'Cotton Terry Towels','hs_code'=>'630260','unit'=>'Pcs','times_used'=>9],
  ],
];
/* Exact lookup answers only for the bath towel. */
$DB->A['FROM exp_doc_memory WHERE view=? AND norm_key=?'] = [];
$copy = expdoc_from_invoice(1, 'customs');
t('a line with no exact memory keeps its own wording',
  $copy[1]['description'] === 'Beach Towel', $copy[1]['description']);
t('  and is not marked as filled from memory', $copy[1]['from_memory'] === false);
t('quantities and rates come across unchanged',
  (float)$copy[0]['qty'] === 250.0 && (float)$copy[0]['rate'] === 17.0);

/* ------------------------------------------------------------------------- */
head('4. Units are locked, value is free');

function chk(array $mine, array $srcLines) {
    global $DB;
    $DB->A = ['FROM shipment_items' => $srcLines];
    return expdoc_check(1, 'customs', $mine);
}
$SRC = [
  ['qty'=>300,'rate'=>19.00,'amount'=>5700],  ['qty'=>300,'rate'=>20.50,'amount'=>6150],
  ['qty'=>600,'rate'=>6.00,'amount'=>3600],   ['qty'=>200,'rate'=>19.00,'amount'=>3800],
  ['qty'=>200,'rate'=>20.50,'amount'=>4100],  ['qty'=>400,'rate'=>6.00,'amount'=>2400],
  ['qty'=>250,'rate'=>17.00,'amount'=>4250],  ['qty'=>250,'rate'=>8.00,'amount'=>2000],
  ['qty'=>150,'rate'=>17.50,'amount'=>2625],  ['qty'=>100,'rate'=>13.75,'amount'=>1375],
];
/* 2,750 pcs and 36,000.00 — the ten commercial lines. */
$st = expdoc_totals($SRC);
t('the commercial invoice totals 2,750 pcs', $st['qty'] === 2750.0, $st['qty']);
t('  and USD 36,000.00', $st['value'] === 36000.0, $st['value']);

/* Ten lines merged to five, at FOB — fewer lines, same units, lower value. */
$MINE = [
  ['qty'=>1200,'rate'=>11.98,'amount'=>14376.00],
  ['qty'=>800, 'rate'=>11.98,'amount'=>9584.00],
  ['qty'=>500, 'rate'=>11.63,'amount'=>5815.00],
  ['qty'=>150, 'rate'=>16.29,'amount'=>2443.50],
  ['qty'=>100, 'rate'=>12.80,'amount'=>1280.00],
];
$c = chk($MINE, $SRC);
t('ten lines merged into five is fine', $c['lines'] === 5);
t('units still match exactly', $c['units_match'] === true, $c['qty_diff']);
t('  so the document may print', $c['can_print'] === true);
t('the value is lower and that is allowed', $c['value_match'] === false);
t('  and the difference is reported', abs($c['value_diff'] + 2501.50) < 0.01, $c['value_diff']);
t('  as a percentage too', abs($c['value_pct'] + 6.9) < 0.05, $c['value_pct']);

/* One piece out and printing stops. */
$short = $MINE; $short[0]['qty'] = 1199;
$c = chk($short, $SRC);
t('ONE unit short blocks printing', $c['can_print'] === false, $c['qty_diff']);
t('  and says how far out it is', abs($c['qty_diff'] + 1) < 0.0005, $c['qty_diff']);

$over = $MINE; $over[0]['qty'] = 1201;
t('one unit over also blocks printing', chk($over, $SRC)['can_print'] === false);

$c = chk([], $SRC);
t('an empty document cannot print', $c['can_print'] === false && $c['empty'] === true);

/* Value identical to the commercial invoice is the ordinary case, not an error. */
$same = [['qty'=>2750,'rate'=>13.0909091,'amount'=>36000.00]];
$c = chk($same, $SRC);
t('a document matching on both reads as matching', $c['units_match'] && $c['value_match'] && $c['can_print']);

/* A higher value is allowed too — over-declaration is still the user's call. */
$high = $MINE; $high[0]['amount'] = 20000.00;
$c = chk($high, $SRC);
t('a HIGHER value is permitted and still printable', $c['can_print'] === true && $c['value_diff'] > 0);

/* ------------------------------------------------------------------------- */
head('5. Saving');

$DB->A = ['FROM shipment_items' => $SRC, 'FROM exp_doc_lines' => []];
$DB->seen = []; $AUDIT = [];

[$ok, $msg] = expdoc_save_lines(1, 'customs', [
    ['description' => 'Cotton Bed Linen', 'hs_code' => '630231', 'unit' => 'Pcs', 'qty' => '1200', 'rate' => '11.98'],
    ['description' => '',                 'hs_code' => '',       'unit' => '',    'qty' => '0',    'rate' => '0'],     /* blank — dropped */
    ['description' => 'Cotton Towels',    'hs_code' => '630260', 'unit' => 'Pcs', 'qty' => '500',  'rate' => '11.63'],
], false);
t('a good save succeeds', $ok === true, $msg);
t('  and the blank row is dropped, not stored', str_contains($msg, '2 lines'), $msg);

$sql = implode(' | ', $DB->seen);
t('the old set is replaced in one go', str_contains($sql, 'DELETE FROM exp_doc_lines WHERE shipment_id=? AND view=?'));
t('shipment_items is READ but never written',
  !preg_match('~(INSERT INTO|UPDATE|DELETE FROM)\s+shipment_items~i', $sql));
t('packing_items is never touched either',
  !preg_match('~packing_items~i', $sql));

[$ok, $msg] = expdoc_save_lines(1, 'customs', [['description' => 'X', 'qty' => '-5', 'rate' => '1']], false);
t('a negative quantity is refused', $ok === false, $msg);
[$ok, $msg] = expdoc_save_lines(1, 'customs', [['description' => 'X', 'qty' => '5', 'rate' => '-1']], false);
t('a negative rate is refused', $ok === false, $msg);

[$ok, $msg] = expdoc_save_lines(1, 'nonsense', [], false);
t('an unknown view is refused', $ok === false);

/* ------------------------------------------------------------------------- */
head('6. The audit records the difference, every time');

$DB->A = ['FROM shipment_items' => $SRC, 'FROM exp_doc_lines' => []];
$AUDIT = [];
expdoc_save_lines(1, 'customs', [
    ['description' => 'Cotton Bed Linen', 'hs_code' => '630231', 'unit' => 'Pcs', 'qty' => '2750', 'rate' => '12.18'],
], false);

t('a save writes exactly one audit entry', count($AUDIT) === 1, count($AUDIT));
$a = $AUDIT[0] ?? [];
t('  tagged as a Customs Document', ($a['sec'] ?? '') === 'Customs Document', $a['sec'] ?? null);
t('  naming the commercial total', str_contains((string)($a['why'] ?? ''), '36,000.00'), $a['why'] ?? null);
t('  and the difference against it', str_contains((string)($a['why'] ?? ''), '('), $a['why'] ?? null);

/* ------------------------------------------------------------------------- */
head('7. What the module promises about itself');

/* Asserted on the CREATE TABLE, not on the file.
 *
 * The first version of this check searched exportdocs.php for the word
 * "buyer" and failed on the comment explaining WHY there is no buyer column.
 * A comment is not a column. The table definition is the only thing that
 * settles it. */
$expSrc = file_get_contents($B . 'includes/export.php');
preg_match('~CREATE TABLE IF NOT EXISTS exp_doc_memory \((.*?)\) ENGINE~s', $expSrc, $mm);
$memDdl = $mm[1] ?? '';
t('the exp_doc_memory table definition was found', $memDdl !== '');
t('it has no buyer or customer column — it describes goods, not customers',
  $memDdl !== '' && !preg_match('~\b(buyer|customer|shipment_id)\b~i', $memDdl), $memDdl);
t('  and it is unique on view plus the normalised key alone',
  str_contains($memDdl, 'UNIQUE KEY uniq_view_key (view, norm_key)'));

$mem = lift($src, 'function expdoc_remember(');
t('remembering is per view, so chamber and customs keep separate vocabularies',
  str_contains($mem, '(view, norm_key'));
t('  and re-typing the same thing counts rather than duplicating',
  str_contains($mem, 'times_used = times_used + 1'));

$save = lift($src, 'function expdoc_save_lines(');
t('the save runs in a transaction', str_contains($save, 'beginTransaction()') && str_contains($save, 'rollBack()'));
t('line numbers are renumbered, never trusted from the form',
  str_contains($save, "'line_no'     => \$n"));

$chk = lift($src, 'function expdoc_check(');
t('can_print depends on the unit match and nothing else',
  str_contains($chk, "'can_print'    => count(\$lines) > 0 && abs(\$qtyDiff) < 0.0005"));
t('value_match is reported but never gates printing',
  str_contains($chk, "'value_match'") && !preg_match("~can_print.*value~s", $chk));

$prn = file_get_contents($B . 'customs_print.php');
t('the print re-checks before printing, because a URL can be typed',
  str_contains($prn, "if (!\$check['can_print'])"));
t('  and says why it refused', str_contains($prn, 'cannot be printed'));
t('the print is audit logged with the value difference',
  str_contains($prn, "'print'") && str_contains($prn, 'difference'));

$scr = file_get_contents($B . 'shipment_customs.php');
t('the editing screen shows the commercial invoice read-only',
  str_contains($scr, 'Read-only. Nothing you type below changes'));
t('  and refuses to edit without rate visibility',
  str_contains(lift($src, 'function expdoc_can_edit('), 'can_see_rates()'));
t('  and blocks staff outright',
  str_contains(lift($src, 'function expdoc_can_view('), 'is_staff() || is_production_staff()'));

$exp = file_get_contents($B . 'includes/export.php');
t('the CUSTOMS tab is in the strip', str_contains($exp, "'label' => 'CUSTOMS'"));
t('no new permission module was added for it',
  !str_contains($exp, "'k' => 'shipcust'") && !str_contains($exp, "'k' => 'shipchamber'"));

/* ------------------------------------------------------------------------- */
head('8. Bringing rows in from Excel or a CSV');

/* No memory, so nothing is auto-filled and the parse is judged on its own. */
$DB->A = ['FROM exp_doc_memory WHERE view=? AND norm_key=?' => [],
          'FROM exp_doc_memory WHERE view=? ORDER BY' => []];

/* THE CASE THAT MATTERS MOST: cells copied out of Excel arrive TAB separated,
   and a description legitimately contains commas. Splitting on the comma
   would turn one column into five. */
[$r, $n] = expdoc_parse_csv("Cotton Bed Linen, White	630231	Pcs	1200	11.98", 'customs');
t('an Excel paste is detected as tab separated', str_contains($n[0], 'tab separated'), $n[0]);
t('  and a comma inside the description survives',
  ($r[0]['description'] ?? '') === 'Cotton Bed Linen, White', $r[0]['description'] ?? null);
t('  with the other four columns in place',
  ($r[0]['hs_code'] ?? '') === '630231' && ($r[0]['qty'] ?? 0) == 1200 && ($r[0]['rate'] ?? 0) == 11.98);

/* A header in any order. */
[$r, $n] = expdoc_parse_csv("Qty,Description,Rate,HS Code\n1200,Cotton Bed Linen,11.98,630231", 'customs');
t('a header row is recognised', str_contains($n[1] ?? '', 'Header row recognised'), $n[1] ?? null);
t('  and columns are matched by name, not position',
  ($r[0]['description'] ?? '') === 'Cotton Bed Linen' && ($r[0]['qty'] ?? 0) == 1200, $r[0] ?? null);

/* Header spellings people actually use. */
foreach (['Description' => 'description', 'DESC' => 'description', 'Product Name' => 'description',
          'Description of Goods' => 'description', 'HS Code' => 'hs_code', 'hs-code' => 'hs_code',
          'Tariff Code' => 'hs_code', 'UOM' => 'unit', 'Quantity' => 'qty', 'PCS' => 'qty',
          'Unit Price' => 'rate', 'Price' => 'rate'] as $given => $want) {
    t('"' . $given . '" is understood as ' . $want,
      expdoc_csv_header_match($given) === $want, expdoc_csv_header_match($given));
}
t('an unknown column name is ignored rather than guessed',
  expdoc_csv_header_match('Remarks') === null);

/* No header: read in template order. */
[$r, $n] = expdoc_parse_csv("Cotton Bed Linen,630231,Pcs,1200,11.98", 'customs');
t('with no header the order is description, hs, unit, qty, rate',
  ($r[0]['description'] ?? '') === 'Cotton Bed Linen' && ($r[0]['rate'] ?? 0) == 11.98, $r[0] ?? null);
t('  and it says so, so nobody is surprised', str_contains($n[1] ?? '', 'No header row found'));

/* Numbers as people type them. */
t('a thousands separator is ignored', expdoc_csv_num('2,750') === 2750.0);
t('a currency symbol is ignored', expdoc_csv_num('$ 11.98') === 11.98);
t('spaces are ignored', expdoc_csv_num('  1 200 ') === 1200.0, expdoc_csv_num('  1 200 '));
t('text that is not a number reads as zero', expdoc_csv_num('n/a') === 0.0);
t('a negative is still negative, so it can be refused', expdoc_csv_num('-5') === -5.0);

/* Rows that should not become lines. */
[$r, $n] = expdoc_parse_csv("Description,Qty,Rate\nBath Towel,250,17\n,,\n   ,  ,\nBeach Towel,150,16.29", 'customs');
t('blank rows are dropped', count($r) === 2, count($r));
t('  and counted in the notes', (bool)preg_grep('~empty row~', $n), $n);
t('line numbers are renumbered after the drop',
  ($r[1]['line_no'] ?? 0) === 2, $r[1]['line_no'] ?? null);

[$r, $n] = expdoc_parse_csv("Description,Qty,Rate\nGood,10,5\nBad,-10,5", 'customs');
t('a negative quantity row is refused, not imported', count($r) === 1, count($r));
t('  and the row number is named', (bool)preg_grep('~Row 3~', $n), $n);

/* Nothing in, a clear answer out. */
[$r, $n] = expdoc_parse_csv('', 'customs');
t('an empty paste returns no rows and says so', count($r) === 0 && (bool)preg_grep('~Nothing~', $n));

/* Semicolon CSV, which is what a European Excel writes. */
[$r, $n] = expdoc_parse_csv("Description;Qty;Rate\nCotton Bed Linen;1200;11.98", 'customs');
t('a semicolon separated CSV is handled', ($r[0]['qty'] ?? 0) == 1200, $r[0] ?? null);

/* The amount is computed here, never read from the file. */
[$r, $n] = expdoc_parse_csv("Cotton Bed Linen,630231,Pcs,1200,11.98", 'customs');
t('the amount is calculated from qty times rate',
  abs(($r[0]['amount'] ?? 0) - 14376.00) < 0.005, $r[0]['amount'] ?? null);

/* An exact memory still fills a blank HS code on an imported row. */
$DB->A['FROM exp_doc_memory WHERE view=? AND norm_key=?'] = [
    ['id'=>1,'view'=>'customs','norm_key'=>'cottonbedlinen','description'=>'Cotton Bed Linen',
     'hs_code'=>'630231','unit'=>'Pcs','times_used'=>14],
];
[$r, $n] = expdoc_parse_csv("Description,Qty,Rate\nCotton Bed Linen,1200,11.98", 'customs');
t('an imported row with no HS code takes it from the memory',
  ($r[0]['hs_code'] ?? '') === '630231', $r[0]['hs_code'] ?? null);

[$r, $n] = expdoc_parse_csv("Description,HS Code,Qty,Rate\nCotton Bed Linen,999999,1200,11.98", 'customs');
t('  but an HS code in the file is never overwritten by the memory',
  ($r[0]['hs_code'] ?? '') === '999999', $r[0]['hs_code'] ?? null);

/* The template is what the no-header order documents. */
$tpl = expdoc_csv_template();
t('the template header matches the no-header reading order',
  str_starts_with($tpl, 'Description,HS Code,Unit,Qty,Rate'), substr($tpl, 0, 40));

/* An import must reach the editor, not the database. */
$scr2 = file_get_contents($B . 'shipment_customs.php');
/* Written out properly. The first attempt at this check ended in `|| x`,
   which made the whole expression true whatever the rest said — a test that
   cannot fail. What has to hold is specific: the import branch puts rows in
   the session draft and does NOT call the save. */
$impBlock = '';
if (preg_match("~if \(\\\$action === 'import'\) \{(.*?)\n        \}~s", $scr2, $ib)) $impBlock = $ib[1];
t('the import branch was found in the screen', $impBlock !== '');
t('it puts the rows in the draft', str_contains($impBlock, "\$_SESSION['expdoc_draft_'"));
t('  and never writes them to the database itself',
  !str_contains($impBlock, 'expdoc_save_lines'), $impBlock);
t('  and the screen says nothing is saved yet',
  str_contains($scr2, 'Not saved yet'));
t('an xlsx upload is refused with an instruction, not a silent failure',
  str_contains($scr2, 'Save As CSV'));
t('the upload size is capped', str_contains($scr2, '2 * 1024 * 1024'));
t('only text file types are accepted',
  str_contains($scr2, "['csv', 'txt', 'tsv']"));


echo "\n$P passed, $F failed\n";
exit($F > 0 ? 1 : 0);
