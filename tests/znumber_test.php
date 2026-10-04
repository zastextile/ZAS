<?php
/* YOUR OWN DOCUMENT NUMBERS.
 *
 * What was there before: next_doc_no() returned PREFIX-YYMMDD-NNN where NNN
 * came from random_int(100,999). Two faults in one line.
 *
 *   NOT YOURS. The prefix was fixed in code, so there was no way to run your
 *   own series.
 *
 *   NOT A SEQUENCE. A random tail out of 900, redrawn each day, is a lottery.
 *   Ten documents in one day is a 4.9% chance of a repeat and nothing checked
 *   for it afterwards. That arithmetic is asserted below rather than asserted
 *   in a comment, because it is the whole reason this code exists.
 *
 * So the three things that matter here:
 *
 *   OFF CHANGES NOTHING. With no row, or the switch off, next_doc_no() must
 *   produce exactly the string it always produced.
 *
 *   THE COUNTER CANNOT REPEAT ITSELF. One statement hands out the number, and
 *   a number already on a document is stepped over.
 *
 *   PEEKING IS FREE. Showing the next number on a form must not use it up.
 */

$B = __DIR__ . '/app_src/public_html/';
$P = 0; $F = 0;
function t(string $n, bool $c, $got = null): void {
    global $P, $F;
    if ($c) { $P++; }
    else { $F++; echo "  FAIL  $n" . ($got !== null ? "\n        got: " . var_export($got, true) : '') . "\n"; }
}
function head(string $n): void { echo "\n$n\n"; }
function lift(string $src, string $from): string {
    $a = strpos($src, $from);
    if ($a === false) return '';
    $o = strpos($src, '{', $a);
    if ($o === false) return '';
    $d = 0;
    for ($i = $o, $n = strlen($src); $i < $n; $i++) {
        if ($src[$i] === '{') $d++;
        elseif ($src[$i] === '}') { $d--; if ($d === 0) return substr($src, $a, $i - $a + 1); }
    }
    return '';
}
/* Comments explain; they do not implement. Several assertions in this suite
   have been fooled by prose before, so source checks read stripped code. */
function nocomments(string $s): string {
    return (string)preg_replace(['~/\*.*?\*/~s', '~//[^\n]*~'], ' ', $s);
}

/* ============================================================ the arithmetic
   that justifies the change */
head('Why the old scheme had to go');

$collide = function (int $n): float {
    $p = 1.0;
    for ($i = 0; $i < $n; $i++) $p *= (900 - $i) / 900;
    return (1 - $p) * 100;
};
t('900 possible tails, so 10 documents in a day already risk a repeat',
  $collide(10) > 4.5 && $collide(10) < 5.5, round($collide(10), 2));
t('40 documents in a day is more likely to collide than not',
  $collide(40) > 50, round($collide(40), 2));
t('901 documents in one day is a certainty, not a risk',
  round($collide(901), 6) === 100.0);

/* ===================================================== the shipped functions */
head('The renderer');

/* db() is never called by the renderer, so a throwing stub is enough to load
   the file and is itself the proof. */
if (!function_exists('db')) {
    function db() { throw new RuntimeException('the renderer must not touch the database'); }
}
require_once $B . 'includes/export.php';

$ts = mktime(12, 0, 0, 10, 2, 2026);   /* 2 October 2026 */

t('{PREFIX} is your code, exactly as typed',
  exp_render_pattern('{PREFIX}/{SEQ}', 'ZAS', 5191, $ts) === 'ZAS/5191');
t('{YY} {MM} {DD} are the two-digit date parts',
  exp_render_pattern('{YY}{MM}{DD}', '', 1, $ts) === '261002');
t('{YYYY} is the full year',
  exp_render_pattern('{YYYY}', '', 1, $ts) === '2026');
t('{SEQ} is the bare counter',
  exp_render_pattern('{SEQ}', '', 7, $ts) === '7');
t('{SEQ:4} pads the counter to four',
  exp_render_pattern('{SEQ:4}', '', 7, $ts) === '0007');
t('{SEQ:3} does not truncate a counter wider than its padding',
  exp_render_pattern('{SEQ:3}', '', 12345, $ts) === '12345');
t('a format with no separator works — this is the case the old shape rule could not make',
  exp_render_pattern('{PREFIX}{YYYY}{SEQ:4}', 'ZAS', 1, $ts) === 'ZAS20260001');
t('punctuation and spaces survive as typed',
  exp_render_pattern('{PREFIX} {YY}-{SEQ:3}', 'ZAS LINEN', 9, $ts) === 'ZAS LINEN 26-009');
t('the built-in format still renders as it always did',
  exp_render_pattern('{PREFIX}-{YY}{MM}{DD}-{SEQ:3}', 'PI', 7, $ts) === 'PI-261002-007');

/* An unknown token must stay visible. A silently dropped token produces a
   number that is wrong in a way nobody can see. */
t('an unknown token is left in the output, not deleted',
  str_contains(exp_render_pattern('{PREFIX}-{FOO}-{SEQ:3}', 'PI', 7, $ts), '{FOO}'));

head('Restart cycles');
t('never has no cycle key, so the counter never resets',    exp_cycle_key('never', $ts) === '');
t('yearly keys on the year',                                 exp_cycle_key('yearly', $ts) === '2026');
t('monthly keys on the year and month',                      exp_cycle_key('monthly', $ts) === '2026-10');
t('an unknown cycle behaves as never rather than erroring',  exp_cycle_key('weekly', $ts) === '');
t('every cycle offered on screen is one exp_cycle_key handles',
  array_keys(EXP_RESET_CYCLES) === ['never', 'yearly', 'monthly']);

head('The kinds, and where each number is written');
t('both document kinds are declared', array_keys(EXP_DOC_KINDS) === ['PI', 'INV']);
t('PI writes to proforma_invoices.pi_no',
  EXP_DOC_KINDS['PI'][1] === 'proforma_invoices' && EXP_DOC_KINDS['PI'][2] === 'pi_no');
t('INV writes to shipments.invoice_no',
  EXP_DOC_KINDS['INV'][1] === 'shipments' && EXP_DOC_KINDS['INV'][2] === 'invoice_no');

/* The table and column names are interpolated into SQL, which is only safe
   because they are constants in this file and never come from a request. */
$exp = file_get_contents($B . 'includes/export.php');
$taken = nocomments(lift($exp, 'function exp_number_taken('));
t('the taken-check reads its table and column from EXP_DOC_KINDS, not from input',
  str_contains($taken, 'EXP_DOC_KINDS[$kind]') && !str_contains($taken, '$_'));
t('the value being checked is still bound, not interpolated',
  str_contains($taken, '`$col` = ?') && str_contains($taken, 'execute([$no])'));

/* ================================================= the counter, end to end
   There is no MySQL in this environment, so the one UPDATE is stood in for
   by code that does exactly what it does. That is enough to test the parts
   this file owns: the loop, the reset, and the step over a used number. */
head('The counter');

final class NumSt {
    public array $rows = [];
    public function __construct(private NumDb $db, private string $sql) {}
    public function execute(array $p = []): bool {
        if (str_contains($this->sql, 'UPDATE exp_numbering')) {
            /* next_no = LAST_INSERT_ID(IF(cycle_key=?, GREATEST(next_no,1), 1)) + 1 */
            [$key, , $kind] = $p;
            $r = &$this->db->num[$kind];
            $seq = ($r['cycle_key'] === $key) ? max((int)$r['next_no'], 1) : 1;
            $this->db->lid = $seq;
            $r['next_no'] = $seq + 1;
            $r['cycle_key'] = $key;
            $this->db->updates++;
        } elseif (str_contains($this->sql, 'FROM exp_numbering')) {
            $this->rows = isset($this->db->num[$p[0]]) ? [$this->db->num[$p[0]]] : [];
        } else {
            $this->rows = in_array($p[0], $this->db->used, true) ? [[1]] : [];
        }
        return true;
    }
    public function fetch() { return $this->rows ? $this->rows[0] : false; }
    public function fetchColumn() { return $this->rows ? array_values((array)$this->rows[0])[0] : false; }
}
final class NumDb {
    public array $num = []; public array $used = []; public int $lid = 0; public int $updates = 0;
    public function prepare(string $s): NumSt { return new NumSt($this, $s); }
    public function query(string $s): NumSt { return new NumSt($this, $s); }
    public function lastInsertId() { return $this->lid; }
}
$DB = new NumDb();
/* db() was declared above as a thrower; the renderer tests needed that. The
   counter tests need a real one, so they run in a child process with the
   stub swapped. Keeping both in one file would mean one of them lies. */

$harness = <<<'PHP'
<?php
require __DIR__ . '/__numdb.php';
$DB = new NumDb();
function db() { global $DB; return $DB; }
require $argv[1] . 'includes/export.php';
$out = [];

$DB->num['INV'] = ['doc_kind'=>'INV','prefix'=>'ZAS','pattern'=>'{PREFIX}/{SEQ}','next_no'=>5191,
                   'reset_cycle'=>'never','cycle_key'=>'','is_active'=>1];

$out['run'] = [];
for ($i = 0; $i < 4; $i++) { $n = exp_numbering_next('INV'); $DB->used[] = $n; $out['run'][] = $n; }

$out['peek1'] = exp_numbering_peek('INV');
$out['peek2'] = exp_numbering_peek('INV');
$out['after_peek_counter'] = $DB->num['INV']['next_no'];

/* wind the counter back onto numbers that are already on documents */
$DB->num['INV']['next_no'] = 5191;
$out['after_rewind'] = exp_numbering_next('INV');

$DB->num['INV']['is_active'] = 0;
$out['when_off'] = exp_numbering_next('INV');
$out['peek_when_off'] = exp_numbering_peek('INV');

$out['when_missing'] = exp_numbering_next('PI');

$DB->num['PI'] = ['doc_kind'=>'PI','prefix'=>'PI','pattern'=>'{PREFIX}-{YYYY}-{SEQ:4}','next_no'=>88,
                  'reset_cycle'=>'yearly','cycle_key'=>'2025','is_active'=>1];
$out['new_year_first']  = exp_numbering_next('PI');
$out['new_year_second'] = exp_numbering_next('PI');

$DB->num['PI']['is_active'] = 1;
$DB->updates = 0;
exp_numbering_peek('PI');
$out['peek_updates'] = $DB->updates;
$DB->updates = 0;
exp_numbering_next('PI');
$out['next_updates'] = $DB->updates;

$out['prefixes'] = exp_numbering_prefixes();

echo json_encode($out);
PHP;

$tmp = sys_get_temp_dir() . '/zasnum' . getmypid();
@mkdir($tmp);
file_put_contents($tmp . '/__numdb.php', "<?php\n" . lift(file_get_contents(__FILE__), 'final class NumSt')
    . "\n" . lift(file_get_contents(__FILE__), 'final class NumDb') . "\n");
file_put_contents($tmp . '/run.php', $harness);
$json = shell_exec(escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg($tmp . '/run.php') . ' ' . escapeshellarg($B) . ' 2>&1');
$r = json_decode((string)$json, true);
@unlink($tmp . '/__numdb.php'); @unlink($tmp . '/run.php'); @rmdir($tmp);

t('the counter harness ran', is_array($r), $json);
if (is_array($r)) {
    t('consecutive numbers come out in sequence, not at random',
      $r['run'] === ['ZAS/5191', 'ZAS/5192', 'ZAS/5193', 'ZAS/5194'], $r['run']);
    t('peeking twice gives the same answer',          $r['peek1'] === $r['peek2']);
    t('peeking shows the number that is next',        $r['peek1'] === 'ZAS/5195');
    t('peeking does not move the counter',            (int)$r['after_peek_counter'] === 5195);
    t('peeking issues no UPDATE at all',              (int)$r['peek_updates'] === 0, $r['peek_updates']);
    t('taking a number issues exactly one UPDATE',    (int)$r['next_updates'] === 1, $r['next_updates']);
    t('a counter wound back onto used numbers steps over every one',
      $r['after_rewind'] === 'ZAS/5195', $r['after_rewind']);
    t('switched off returns null so the caller keeps its old behaviour',
      $r['when_off'] === null);
    t('peek is null when switched off, so no form is pre-filled',
      $r['peek_when_off'] === null);
    t('a kind with no row at all returns null',       $r['when_missing'] === null);
    t('a yearly counter restarts at 1 in a new year', $r['new_year_first'] === 'PI-' . date('Y') . '-0001');
    t('and then carries on from there',               $r['new_year_second'] === 'PI-' . date('Y') . '-0002');
    t('configured prefixes are offered to the search box',
      in_array('PI', $r['prefixes'], true) && in_array('INV', $r['prefixes'], true), $r['prefixes']);
}

/* ======================================================== the atomic UPDATE */
head('How the number is handed out');

$next = nocomments(lift($exp, 'function exp_numbering_next('));
t('the read and the increment are one statement — LAST_INSERT_ID inside the UPDATE',
  str_contains($next, 'LAST_INSERT_ID(') && str_contains($next, 'UPDATE exp_numbering'));
t('there is no SELECT-then-UPDATE in the issuing path, which two users could interleave',
  !preg_match('~SELECT[^;]*next_no~i', $next));
t('a new cycle resets the counter in that same statement',
  str_contains($next, 'IF(cycle_key = ?'));
t('the counter can never be handed out as zero or negative',
  str_contains($next, 'GREATEST(next_no, 1)') && str_contains($next, '$seq < 1'));
t('the result is checked against the real column before being returned',
  str_contains($next, 'exp_number_taken('));
t('the retry loop is bounded, so a misconfiguration cannot spin forever',
  preg_match('~\$i < 50~', $next) === 1);

/* ================================================= nothing changes by itself */
head('Off by default');

$schema = nocomments(lift($exp, 'function exp_build_schema('));
t('the numbering table is created',  str_contains($schema, 'CREATE TABLE IF NOT EXISTS exp_numbering'));
t('is_active defaults to 0, so installing this switches nothing on',
  preg_match('~is_active TINYINT\(1\) NOT NULL DEFAULT 0~', $schema) === 1);
t('the schema version was raised so the new table is actually installed',
  (int)EXP_SCHEMA_VERSION >= 5, EXP_SCHEMA_VERSION);
t('no seed row is inserted, so every kind starts switched off',
  !str_contains(nocomments($exp), 'INSERT INTO exp_numbering (doc_kind,prefix,pattern,next_no,reset_cycle,cycle_key,is_active,created'));

$cost = nocomments(file_get_contents($B . 'includes/costing.php'));
$ndn  = lift($cost, 'function next_doc_no(');
t('next_doc_no still contains the original line, unchanged',
  str_contains($ndn, "\$prefix . '-' . date('ymd') . '-' . substr((string)random_int(100, 999), 0, 3)"));
t('it only diverts when the numbering code is actually loaded',
  str_contains($ndn, "function_exists('exp_numbering_next')"));
t('an empty or null answer falls through to the original line',
  str_contains($ndn, "\$own !== null && \$own !== ''"));
t('includes/costing.php does not require export.php — the guard is the only coupling',
  !str_contains($cost, "require_once __DIR__ . '/export.php'"));

/* ============================================================ the settings UI */
head('The Numbering screen');

$set = file_get_contents($B . 'exp_settings.php');
$setN = nocomments($set);
t('numbering is a tab',                       str_contains($setN, "'numbering'"));
/* Written against the intent rather than the exact literal: pinning the
   whole array means this breaks every time a tab is added, which is a test
   failing for being out of date rather than for finding anything. What
   matters is that the tab is validated against a fixed list at all. */
t('the tab is validated against a fixed list, so the URL cannot be forced elsewhere',
  preg_match('~in_array\(\$tab,\s*\[[^\]]*\'numbering\'[^\]]*\],\s*true\)~', $setN) === 1);
t('the form posts through the CSRF check',    str_contains($set, 'save_numbering'));
t('a format with no counter is rejected, or every document would share a number',
  str_contains($setN, "strpos(\$pattern, '{SEQ}') === false") || str_contains($setN, "strpos(\$pattern, '{SEQ') === false"));
t('the kind being saved is checked against EXP_DOC_KINDS',
  str_contains($setN, 'isset(EXP_DOC_KINDS[$k])'));
t('the counter cannot be saved below 1',      str_contains($setN, "max(1, (int)(\$_POST['next_no']"));
t('the prefix is stripped of characters that would break a filename later',
  str_contains($setN, "preg_replace('~[^A-Za-z0-9 ./_-]~'"));
t('a format longer than the column is refused before it is saved, not after',
  str_contains($setN, 'mb_strlen($sample) > 40'));
t('the preview is worked out from the same render the server uses on save',
  str_contains($setN, 'exp_render_pattern($pattern, $prefix, $next)'));
t('the change is written to the audit log',   str_contains($setN, "audit_log(0, 'Numbering'"));
/* No framework for the preview. Spelled as "nothing is fetched from
   outside", because matching the words themselves finds this app's own
   includes/bootstrap.php and the word "reactivated" in an audit message —
   a test that fails on prose is a test that will be switched off. */
t('the preview loads no script from anywhere — it is inline, like the rest of the app',
  !preg_match('~<script[^>]+src=~i', $set));
t('and no stylesheet is pulled in either',
  !preg_match('~<link[^>]+stylesheet~i', $set));

/* ========================================================== the shipment form */
head('The new-invoice form');

$form  = file_get_contents($B . 'shipment_form.php');
$formN = nocomments($form);
t('the box is pre-filled from a peek, not from a number taken',
  str_contains($formN, "exp_numbering_peek('INV')"));
t('what was suggested travels with the form',
  str_contains($formN, 'invoice_no_suggested'));
t('the counter is only spent when the suggestion was left alone',
  str_contains($formN, '$invoiceNo === $suggested') && str_contains($formN, "exp_numbering_next('INV')"));
t('the number you type is still what gets saved',
  str_contains($formN, "\$invoiceNo  = trim(\$_POST['invoice_no'] ?? '')"));
t('the field is still editable — no readonly, no disabled',
  preg_match('~name="invoice_no"[^>]*(readonly|disabled)~', $form) !== 1);
t('the insert writes the resolved number rather than the raw post',
  str_contains($formN, '$invoiceNo,') && !preg_match('~execute\(\[\s*trim\(\$_POST\[.invoice_no.~', $formN));

/* ================================================================ the router */
head('Your series stays searchable');

$qr  = file_get_contents($B . 'includes/qrouter.php');
$qrN = nocomments($qr);
t('the router learns your prefixes',       str_contains($qrN, 'function qr_doc_prefixes('));
$dp = nocomments(lift($qr, 'function qr_doc_prefixes('));
t('the built-in prefixes are always included, so old numbers stay findable',
  str_contains($dp, "['PI', 'INV', 'CI']"));
t('a prefix is only accepted if it is letters and digits — nothing that could reach the regex',
  str_contains($dp, "preg_match('~^[A-Z][A-Z0-9]{0,9}$~'"));
t('longest prefix first, so ZASPI is not read as ZAS',
  str_contains($dp, 'strlen($b) <=> strlen($a)'));
t('the lookup is cached, so one search is one read',  str_contains($dp, 'static $cache'));
t('numbering may not be loaded on a page that searches, so the call is guarded',
  str_contains($dp, "function_exists('exp_numbering_prefixes')"));

$parse = nocomments(lift($qr, 'function qr_parse('));
t('the prefix rule runs before the generic shape rule',
  strpos($parse, 'qr_doc_prefixes()') < strpos($parse, '[a-z]{2,5}\s*[-/]'));
t('every prefix is quoted before it goes into the pattern',
  str_contains($parse, 'preg_quote(mb_strtolower($p)'));

$rm = nocomments(lift($qr, 'function qr_ref_match('));
t('a reference with no separator is matched too',
  str_contains($rm, "'~^([A-Z]{2,10})(\\d{1,12})$~'"));
t('both halves of the LIKE are letters and digits only, so no wildcard can be smuggled in',
  substr_count($rm, '$m[1] . \'%\' . $m[2]') === 2);
t('the exact value is still tried first and still bound',
  str_contains($rm, '"($col = ? OR $col LIKE ?)", [$ref, $like]'));

/* The parse is behaviour, not text, so it is run. */
$probe = <<<'PHP'
<?php
function exp_numbering_prefixes() { return ['PI', 'INV', 'ZAS']; }
function db() { throw new RuntimeException('qr_parse must not touch the database'); }
require $argv[1] . 'includes/qrouter.php';
$o = [];
foreach ([
  'ZAS20260001', 'ZAS/5191', 'ZAS 5191', 'PI-260908-786', 'PI-786', 'PI786',
  'CI/26/0099', 'INV-2026/0023', 'HS 630231', '630231',
  'ZAS20260001 freight above USD 3,000',
] as $q) {
  $r = qr_parse('shipment', $q);
  $o[$q] = ['ref' => $r['filters']['ref'] ?? null, 'hs' => $r['filters']['hs'] ?? null,
            'left' => $r['leftover']];
}
$o['_sql'] = [];
foreach (['ZAS20260001', 'PI786', 'PI-786', 'ZAS/5191'] as $ref) $o['_sql'][$ref] = qr_ref_match('s.invoice_no', $ref);
echo json_encode($o);
PHP;
$pf = sys_get_temp_dir() . '/zasqr' . getmypid() . '.php';
file_put_contents($pf, $probe);
$g = json_decode((string)shell_exec(escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg($pf) . ' ' . escapeshellarg($B) . ' 2>&1'), true);
@unlink($pf);

t('the parse probe ran', is_array($g));
if (is_array($g)) {
    t('a format with no separator is recognised',   ($g['ZAS20260001']['ref'] ?? null) === 'ZAS20260001');
    t('the hand-typed style still works',           ($g['ZAS/5191']['ref'] ?? null) === 'ZAS/5191');
    t('a space where the separator should be is tolerated',
      ($g['ZAS 5191']['ref'] ?? null) === 'ZAS5191');
    t('the generated format still works',           ($g['PI-260908-786']['ref'] ?? null) === 'PI-260908-786');
    t('the short form still works',                 ($g['PI-786']['ref'] ?? null) === 'PI-786');
    t('and the short form without its dash',        ($g['PI786']['ref'] ?? null) === 'PI786');
    t('CI/26/0099 still works',                     ($g['CI/26/0099']['ref'] ?? null) === 'CI/26/0099');
    t('INV-2026/0023 still works',                  ($g['INV-2026/0023']['ref'] ?? null) === 'INV-2026/0023');

    /* The rule that was removed at your request, still removed. A document
       number contains a six-digit date; six loose digits must never be read
       as an HS code. */
    t('a document number is never read as an HS code',
      ($g['PI-260908-786']['hs'] ?? null) === null && ($g['ZAS20260001']['hs'] ?? null) === null);
    t('an HS code is still recognised when it says so', ($g['HS 630231']['hs'] ?? null) === '630231');
    t('six bare digits are still not understood, and say so rather than guessing',
      ($g['630231']['hs'] ?? null) === null && ($g['630231']['ref'] ?? null) === null
      && trim((string)($g['630231']['left'] ?? '')) === '630231');

    t('your number and a money filter can be read from one question',
      ($g['ZAS20260001 freight above USD 3,000']['ref'] ?? null) === 'ZAS20260001');

    t('a part-typed number searches on both the exact value and the shape',
      ($g['_sql']['PI786'][1] ?? null) === ['PI786', 'PI%786']);
    t('a full number searches on itself as well as the shape',
      ($g['_sql']['ZAS20260001'][1] ?? null) === ['ZAS20260001', 'ZAS%20260001']);
    t('a slash in the typed reference does not become a wildcard',
      ($g['_sql']['ZAS/5191'][1][1] ?? null) === 'ZAS%5191');
}

echo "\n$P passed, $F failed\n";
exit($F > 0 ? 1 : 0);
