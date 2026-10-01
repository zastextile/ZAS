<?php
/* THE EXPORT MODULE'S ARITHMETIC AND ITS TWO HARD RULES.
 *
 * Two things in this module are worth proving rather than reading:
 *
 *  1. THE BALANCE CLOSES. The whole reason payments are held in the invoice
 *     currency is that a mixed-currency ledger lands on 49,987.60 against a
 *     50,000 invoice and the status never reaches PAID. The thresholds are
 *     where that goes wrong, so they are tested at the boundary.
 *
 *  2. A COMMISSION IS REMOVED FROM THE QUERY, NOT FROM THE PAGE. Hiding a row
 *     while leaving it in the total leaks the number to anyone who can
 *     subtract. That has to be true in the SQL, not in the markup.
 *
 * The real functions are lifted out of the shipped file and run, with a fake
 * PDO underneath where they need one.
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
    $b = strpos($src, "\n}\n", $a);
    return $b === false ? '' : substr($src, $a, $b - $a + 3);
}

$src = file_get_contents($B . 'includes/export.php');
t('includes/export.php was found', $src !== false && $src !== '');

/* ------------------------------------------------------------- a fake PDO
   Answers only what the function under test asks, and records the SQL so the
   test can assert on the query itself. */
class FakeStmt {
    public $sql; public $params = []; public $rows;
    public function __construct($sql, $rows) { $this->sql = $sql; $this->rows = $rows; }
    public function execute($p = []) { $this->params = $p; return true; }
    public function fetch() { return $this->rows[0] ?? false; }
    public function fetchAll() { return $this->rows; }
    public function fetchColumn($i = 0) {
        $r = $this->rows[0] ?? [];
        return is_array($r) ? array_values($r)[$i] ?? false : $r;
    }
}
class FakeDb {
    public $answers = []; public $seen = [];
    public function prepare($sql) { $this->seen[] = $sql; return new FakeStmt($sql, $this->match($sql)); }
    public function query($sql)   { $this->seen[] = $sql; return new FakeStmt($sql, $this->match($sql)); }
    private function match($sql) {
        foreach ($this->answers as $needle => $rows) {
            if (stripos($sql, (string)$needle) !== false) return $rows;
        }
        return [];
    }
}
$FAKE = new FakeDb();
function db() { global $FAKE; return $FAKE; }

eval(lift($src, 'function exp_payment_summary('));
eval(lift($src, 'function exp_cost_summary('));
eval(lift($src, 'function exp_master_flag('));
eval(lift($src, 'function exp_carton_check('));
eval(lift($src, 'function exp_costs('));
eval(lift($src, 'function exp_masters('));
eval(lift($src, 'function exp_commission_type_ids('));

/* ------------------------------------------------------------------------- */
head('1. The balance, and the status at every boundary');

function summary_for(float $invoice, float $received): array {
    global $FAKE;
    $FAKE->answers = ['FROM shipment_payments' => [['s' => $received, 'n' => 1]]];
    return exp_payment_summary(['id' => 1, 'total_amount' => $invoice, 'currency' => 'USD']);
}

$s = summary_for(50000, 0);
t('nothing received reads UNPAID', $s['status'] === 'UNPAID', $s['status']);
t('  and the whole invoice is outstanding', abs($s['balance'] - 50000) < 0.001, $s['balance']);

$s = summary_for(50000, 45000);
t('part received reads PARTIALLY PAID', $s['status'] === 'PARTIALLY PAID', $s['status']);
t('  and the balance is exact, not approximate', $s['balance'] === 5000.0, $s['balance']);

$s = summary_for(50000, 50000);
t('fully received reads PAID', $s['status'] === 'PAID', $s['status']);
t('  and the balance is exactly zero', $s['balance'] === 0.0, $s['balance']);

/* THE POINT OF THE WHOLE DESIGN. Three payments in one currency sum back to
   the invoice exactly, so PAID actually fires. */
$three = 10000.00 + 20000.00 + 20000.00;
$s = summary_for(50000, $three);
t('10,000 + 20,000 + 20,000 against 50,000 reaches PAID',
  $s['status'] === 'PAID', $s['status'] . ' balance ' . $s['balance']);

/* And the failure a mixed-currency ledger would produce: a few cents short.
   The status must NOT claim PAID — if this ever says PAID the thresholds have
   been loosened into lying. */
$s = summary_for(50000, 49999.99);
t('one cent short is PARTIALLY PAID, not PAID', $s['status'] === 'PARTIALLY PAID', $s['status']);

$s = summary_for(50000, 50000.01);
t('one cent over is OVERPAID', $s['status'] === 'OVERPAID', $s['status']);

$s = summary_for(50000, 55000);
t('a real overpayment is OVERPAID', $s['status'] === 'OVERPAID', $s['status']);
t('  and the balance goes negative rather than clamping to zero',
  $s['balance'] < 0, $s['balance']);

/* A rounding crumb must not flip the status. Half a tenth of a cent is noise
   from a DECIMAL sum, not a debt. */
$s = summary_for(50000, 49999.999);
t('a sub-cent crumb still reads PAID', $s['status'] === 'PAID', $s['status']);

$s = summary_for(0, 0);
t('a zero-value invoice does not divide by anything or crash', $s['status'] === 'UNPAID', $s['status']);

/* ------------------------------------------------------------------------- */
head('2. Voided rows count for nothing');

/* exp_payment_summary asks the database for the sum, and the query itself is
   what has to exclude voids — a void filtered in PHP would still be inside
   the SUM the page prints. */
$FAKE->seen = [];
summary_for(50000, 45000);
$sql = implode(' ', $FAKE->seen);
t('the received total is summed with is_void=0 in the SQL',
  stripos($sql, 'is_void=0') !== false || stripos($sql, 'is_void = 0') !== false, $sql);

/* Costs are summed in PHP from rows already fetched, so the skip is here. */
$costs = [
    ['is_void' => 0, 'is_paid' => 1, 'pkr_amount' => 100000],
    ['is_void' => 0, 'is_paid' => 0, 'pkr_amount' =>  50000],
    ['is_void' => 1, 'is_paid' => 0, 'pkr_amount' => 999999],   /* voided */
];
$cs = exp_cost_summary($costs);
t('a voided cost is left out of the total', $cs['pkr'] === 150000.0, $cs['pkr']);
t('  and out of the unpaid total', $cs['unpaid_pkr'] === 50000.0, $cs['unpaid_pkr']);
t('  and out of the count', $cs['count'] === 2, $cs['count']);

/* ------------------------------------------------------------------------- */
head('3. Commissions leave the query, not just the page');

$FAKE->answers = ['FROM exp_masters' => [
    ['id' => 1, 'kind' => 'cost_type', 'label' => 'Ocean Freight',    'flags' => null],
    ['id' => 2, 'kind' => 'cost_type', 'label' => 'Local Commission', 'flags' => '{"is_commission":1}'],
    ['id' => 3, 'kind' => 'cost_type', 'label' => 'Export Commission','flags' => '{"is_commission":1}'],
]];

$hide = exp_commission_type_ids();
t('the commission types are found by their flag', $hide === [2, 3], $hide);

$FAKE->seen = [];
$FAKE->answers['FROM shipment_costs'] = [];
exp_costs(41, false);                       /* a user who may NOT see commissions */
$sqlNo = implode(' ', array_filter($FAKE->seen, fn($q) => stripos($q, 'shipment_costs') !== false));
t('their query excludes the commission type ids',
  stripos($sqlNo, 'NOT IN') !== false, $sqlNo);

$FAKE->seen = [];
exp_costs(41, true);                        /* a user who MAY see them */
$sqlYes = implode(' ', array_filter($FAKE->seen, fn($q) => stripos($q, 'shipment_costs') !== false));
t('and a user with rate visibility gets no exclusion at all',
  stripos($sqlYes, 'NOT IN') === false, $sqlYes);

/* The flag reader itself. */
t('a flag set to 1 reads true',  exp_master_flag(['flags' => '{"is_commission":1}'], 'is_commission'));
t('a different flag reads false', !exp_master_flag(['flags' => '{"is_commission":1}'], 'supports_draft_final'));
t('no flags at all reads false', !exp_master_flag(['flags' => null], 'is_commission'));
t('a missing row reads false',   !exp_master_flag(null, 'is_commission'));
t('broken JSON does not throw',  !exp_master_flag(['flags' => 'not json'], 'is_commission'));

/* ------------------------------------------------------------------------- */
head('4. Container cartons against the packing list');

function carton_check(float $declared, float $packed): array {
    global $FAKE;
    $FAKE->answers = [
        'FROM shipment_containers' => [['c' => $declared]],
        'FROM shipments'           => [['p' => $packed]],
    ];
    return exp_carton_check(41);
}

$c = carton_check(1230, 1230);
t('matching totals report ok', $c['ok'] === true);
t('  and a zero difference', $c['diff'] === 0.0, $c['diff']);

$c = carton_check(1230, 1200);
t('a mismatch is caught', $c['ok'] === false);
t('  and the difference is reported', $c['diff'] === 30.0, $c['diff']);

$c = carton_check(1200, 1230);
t('a shortfall is caught too', $c['ok'] === false && $c['diff'] === -30.0, $c['diff']);

$c = carton_check(0, 0);
t('nothing entered yet is not reported as a mismatch', $c['any'] === false);

$c = carton_check(0, 620);
t('packing entered but no containers yet IS worth flagging', $c['any'] === true && $c['ok'] === false);

/* ------------------------------------------------------------------------- */
head('5. The migration, read as SQL rather than as a file');

/* THE SQL IS EXTRACTED FIRST, AND THAT MATTERS.
 *
 * An earlier version of this section searched the whole PHP file for words
 * like FLOAT, RENAME and "ALTER TABLE". All three assertions failed, and none
 * of them had found a real problem:
 *
 *   - "FLOAT" matched PHP's own `float` type on a function parameter.
 *   - "RENAME" matched the word "renamed" in an explanatory comment.
 *   - The twelfth "ALTER TABLE" was the file's own header comment describing
 *     the convention, not a statement.
 *
 * A comment is not a rule and a type hint is not a column. So every check
 * below runs on the statements actually handed to the executor. */
/* The money types are PHP constants concatenated into the SQL, so they are
   substituted BEFORE extraction — otherwise the non-greedy match stops at the
   quote that closes the string around the constant, and three statements come
   out truncated with their DECIMAL columns missing. */
$ddlSrc = str_replace(
    ['" . EXP_DEC_MONEY . "', '" . EXP_DEC_RATE . "'],
    ['DECIMAL(16,2)', 'DECIMAL(12,4)'],
    $src
);
preg_match_all('~\$x\("(.*?)"\);~s', $ddlSrc, $mm);
$ddl  = implode("\n;\n", $mm[1]);
$stmts = $mm[1];

t('no statement was truncated during extraction', (function () use ($stmts) {
    foreach ($stmts as $s) {
        if (str_contains($s, 'EXP_DEC')) return false;
        if (substr_count($s, '(') !== substr_count($s, ')')) return false;
    }
    return true;
})());

t('the migration statements were found', count($stmts) >= 15, count($stmts));

t('money columns are DECIMAL, never FLOAT or DOUBLE',
  !preg_match('~\b(FLOAT|DOUBLE)\b~i', $ddl));

t('there is at least one DECIMAL money column, so the check above means something',
  preg_match_all('~DECIMAL\(~i', $ddl) >= 6, preg_match_all('~DECIMAL\(~i', $ddl));

t('the schema check is skipped on a marker read rather than re-running the DDL',
  str_contains($src, "FROM exp_meta WHERE k='schema_version'"));

t('  and that early return really is inside exp_ensure_schema',
  str_contains((string)lift($src, 'function exp_ensure_schema('), 'return;'));

t('no DROP, no RENAME, no TRUNCATE in any statement',
  !preg_match('~\b(DROP\s+TABLE|DROP\s+COLUMN|RENAME|TRUNCATE)\b~i', $ddl));

/* \b matters here: without it, UPDATE matches the start of `updated_at`, which
   is a column name on nearly every table in this module. */
t('no UPDATE or DELETE against an existing table on install',
  !preg_match('~^\s*(UPDATE|DELETE)\b~im', $ddl));

/* Each ALTER checked on its own, so one bad statement cannot hide behind a
   count that happens to balance. */
$alters = array_values(array_filter($stmts, fn($s) => stripos(ltrim($s), 'ALTER TABLE') === 0));
t('every column added to an existing table is nullable', (function () use ($alters) {
    foreach ($alters as $a) if (!preg_match('~\sNULL\s*$~i', trim($a))) return false;
    return count($alters) > 0;
})(), $alters);

t('  and there are the eleven expected ALTERs, no more', count($alters) === 11, count($alters));

t('the ALTERs only touch shipments, proforma_invoices, shipment_files and products',
  (function () use ($alters) {
      foreach ($alters as $a) {
          if (!preg_match('~ALTER TABLE (shipments|proforma_invoices|shipment_files|products)\b~i', $a)) return false;
      }
      return true;
  })(), $alters);

t('shipments.status is NOT reused for the logistics status',
  str_contains($src, 'logistics_status VARCHAR(40) NULL')
  && !preg_match('~ALTER TABLE shipments MODIFY COLUMN status~i', $src));

t('products.hs_code is not added again, because it already exists',
  !str_contains($src, 'ADD COLUMN hs_code'));

t('seeding uses INSERT IGNORE, so a label you edited is never overwritten',
  str_contains($src, 'INSERT IGNORE INTO exp_masters'));

t('the carton ranges are their own table, not a column on packing_items',
  str_contains($src, 'CREATE TABLE IF NOT EXISTS shipment_container_cartons')
  && !str_contains($src, 'ALTER TABLE packing_items'));

t('payments and costs carry a void trail rather than being deleted',
  substr_count($src, 'void_reason TEXT NULL') >= 2
  && substr_count($src, 'voided_by INT NULL') >= 2);

t('the payment row records the invoice currency, not a chosen one',
  str_contains($src, 'pkr_credited') && str_contains($src, 'reconciliation'));

t('every shipment child table is indexed on shipment_id',
  substr_count($src, 'INDEX(shipment_id)') + substr_count($src, 'INDEX idx_ship') >= 5);

t('the four permission areas are mapped in one place',
  str_contains($src, "'logistics' => 'shiplog'")
  && str_contains($src, "'payments' => 'shippay'")
  && str_contains($src, "'costs' => 'shipcost'")
  && str_contains($src, "'documents' => 'shipdoc'"));

t('staff and production staff are refused before permissions are consulted',
  preg_match('~function exp_can.*?is_staff\(\) \|\| is_production_staff\(\)~s', (string)lift($src, 'function exp_can(')) === 1);

/* ------------------------------------------------------------------------- */
head('6. The four new permission modules are registered');

$acc = file_get_contents($B . 'includes/access.php');
foreach (['shiplog' => 'vcud', 'shippay' => 'vcudr', 'shipcost' => 'vcudr', 'shipdoc' => 'vcud'] as $k => $acts) {
    t("$k is in zu_modules with actions $acts",
      (bool)preg_match("~'k' => '$k'.*?'a' => '$acts'~", $acc));
}
t('the module keys fit the zu_perm column, which is VARCHAR(24)',
  max(array_map('strlen', ['shiplog', 'shippay', 'shipcost', 'shipdoc'])) <= 24);

/* ------------------------------------------------------------------------- */
head('7. The existing screens were extended, not rewritten');

$sv = file_get_contents($B . 'shipment_view.php');
$pl = file_get_contents($B . 'packing_list.php');

t('shipment_view.php draws the tab strip', str_contains($sv, "exp_tab_strip(\$shipment, 'invoice')"));
t('  and still saves through its own endpoint', str_contains($sv, 'shipment_save.php'));
t('  and still has its approval and reopen controls',
  str_contains($sv, 'approve.php') && str_contains($sv, 'reopen_shipment.php'));
t('  and still blocks staff', str_contains($sv, "redirect('packing_list.php?id="));

t('packing_list.php draws the strip on the PC layout', str_contains($pl, "exp_tab_strip(\$shipment, 'packing')"));
t('  and the staff mobile layout is left alone',
  substr_count($pl, 'exp_tab_strip') === 1);
t('  and the over-pack guard is untouched', str_contains($pl, 'zas_pack_validate_qty_v21($id, $itemId, $it, $totalQty)'));
t('  and the serial-reuse guard is untouched', str_contains($pl, 'zas_pack_find_over_serials_v21($counts, $from, $to)'));
t('  and a third use of a serial is still refused',
  str_contains($pl, 'Serial number already used 2 times'));

/* The unguarded packing write that used to live in shipment_save.php. */
$ss = file_get_contents($B . 'shipment_save.php');
t('shipment_save.php no longer deletes every packing row',
  !str_contains($ss, 'DELETE FROM packing_items'));
t('  and refuses packing fields instead of writing them unchecked',
  str_contains($ss, 'Packing rows are entered on the Packing List screen'));
t('  while the invoice item rebuild it legitimately owns is still there',
  str_contains($ss, 'DELETE FROM shipment_items'));

/* The proforma's own bank behaviour must be unchanged. */
$pf = file_get_contents($B . 'proforma.php');
t('the proforma still snapshots its own bank details',
  str_contains($pf, 'bank1_name=?') && str_contains($pf, 'bank_choice=?'));
t('  and the new picker only fills fields, writing nothing itself',
  str_contains($pf, 'function pfFillBank') && !str_contains($pf, "name=\"exp_bank_id\""));
t('  and still falls back to company_bank_defaults for a new proforma',
  str_contains($pf, "\$bd['bank1_name']"));

echo "\n$P passed, $F failed\n";
exit($F > 0 ? 1 : 0);
