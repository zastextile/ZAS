<?php
/* PROFORMA CONTRACTS on the dashboard.
 *
 * "how much contract i have made with customer and product and value in pak
 *  rupees as converted all any currency in PKR ... product value wise as well
 *  qty wise"
 *
 * Four things decide whether this panel tells the truth:
 *
 *   ONLY REAL CONTRACTS ARE COUNTED. A draft is not a commitment. If drafts
 *   leaked in, the headline figure would overstate the book.
 *
 *   A MISSING RATE IS NOT ZERO. exp_pkr_rate() returns 0.0 for a currency
 *   with no rate configured. Multiplying by it silently removes that
 *   contract from the total while still showing a total — the worst kind of
 *   wrong, because nothing looks wrong.
 *
 *   QUANTITIES ARE NEVER ADDED ACROSS UNITS. 4,000 Pc + 900 Set + 1,200 Kg
 *   is not 6,100 of anything.
 *
 *   IT CANNOT BE READ BY SOMEONE WHO CANNOT SEE RATES. A panel of contract
 *   values is a rate screen under another name.
 */

$B = __DIR__ . '/app_src/public_html/';
$P = 0; $F = 0;
function t(string $n, bool $c, $got = null): void {
    global $P, $F;
    if ($c) { $P++; }
    else { $F++; echo "  FAIL  $n" . ($got !== null ? "\n        got: " . var_export($got, true) : '') . "\n"; }
}
function head(string $n): void { echo "\n$n\n"; }
function nocomments(string $s): string {
    return (string)preg_replace(['~/\*.*?\*/~s', '~//[^\n]*~'], ' ', $s);
}
function lift(string $src, string $from): string {
    $a = strpos($src, $from); if ($a === false) return '';
    $o = strpos($src, '{', $a); if ($o === false) return '';
    $d = 0;
    for ($i = $o, $n = strlen($src); $i < $n; $i++) {
        if ($src[$i] === '{') $d++;
        elseif ($src[$i] === '}') { $d--; if ($d === 0) return substr($src, $a, $i - $a + 1); }
    }
    return '';
}

/* ===================================================== the pure helpers */
head('1. Folding the hand-typed product names');

/* exp_pkr_rate() lives in export.php; the helpers below do not call it, so
   a stub is enough to load the file. */
if (!function_exists('exp_pkr_rate')) { function exp_pkr_rate(string $c): float { return 1.0; } }
if (!function_exists('db')) { function db() { throw new RuntimeException('no database in this test'); } }
require_once $B . 'includes/pfcontracts.php';

t('case is ignored',        pfc_norm_product('Bath Towel') === pfc_norm_product('bath towel'));
t('double spaces are ignored', pfc_norm_product('Bath  Towel') === pfc_norm_product('Bath Towel'));
t('a trailing plural is ignored', pfc_norm_product('Bath Towels') === pfc_norm_product('Bath Towel'));
t('punctuation is ignored',  pfc_norm_product('Bath-Towel') === pfc_norm_product('Bath Towel'));

/* The merge must be loose enough to help and tight enough not to lie.
   "Bath" and "Beach" are 84% alike and both are real products here. */
t('Bath Towel and Beach Towel stay separate',
  pfc_norm_product('Bath Towel') !== pfc_norm_product('Beach Towel'));
t('a size in the name is kept',
  pfc_norm_product('Hotel Flat Sheet 300TC') !== pfc_norm_product('Hotel Flat Sheet 200TC'));
/* A three-letter word ending in s must not lose it — "Gas" is not "Ga". */
t('a short word keeps its s', pfc_norm_product('Gas Pipe') === 'gas pipe', pfc_norm_product('Gas Pipe'));

head('2. Units');
t('pcs, PIECE and Pc are one unit',
  pfc_norm_unit('pcs') === 'Pc' && pfc_norm_unit('PIECE') === 'Pc' && pfc_norm_unit('Pc') === 'Pc');
t('sets folds to Set',   pfc_norm_unit('sets') === 'Set');
t('meter folds to Mtr',  pfc_norm_unit('meter') === 'Mtr');
t('a blank unit becomes Pc rather than an empty heading', pfc_norm_unit('') === 'Pc');
t('an unknown unit is kept as typed, not discarded',      pfc_norm_unit('Dozen') === 'Dozen');

/* =================================================== the summary itself
   Run against a stand-in database, because the arithmetic is the product. */
head('3. The figures');

$probe = <<<'PHP'
<?php
/* rates: PKR per 1 unit. EUR deliberately has no rate, to prove a missing
   rate is reported rather than silently converted to zero. */
$RATES = ['PKR' => 1.0, 'USD' => 281.50, 'GBP' => 356.80, 'EUR' => 0.0];
function exp_pkr_rate(string $c): float { global $RATES; return (float)($RATES[strtoupper($c)] ?? 0.0); }

$HEADS = [
  ['id'=>1,'customer_name'=>'Gulf Textiles LLC','currency'=>'USD','status'=>'converted','d'=>'2026-07-02','own_total'=>37725.00],
  ['id'=>2,'customer_name'=>'Gulf Textiles LLC','currency'=>'USD','status'=>'sent','d'=>'2026-07-19','own_total'=>10000.00],
  ['id'=>3,'customer_name'=>'Home Comfort','currency'=>'GBP','status'=>'confirmed','d'=>'2026-05-08','own_total'=>25900.00],
  ['id'=>4,'customer_name'=>'Decent Textile','currency'=>'PKR','status'=>'confirmed','d'=>'2026-09-28','own_total'=>1776000.00],
  ['id'=>5,'customer_name'=>'Nordic Linen AB','currency'=>'EUR','status'=>'confirmed','d'=>'2026-08-18','own_total'=>34250.00],
];
$LINES = [
  ['proforma_id'=>1,'product_name'=>'Bath Towel','unit'=>'Pc','qty'=>7500,'amount'=>29625.00],
  ['proforma_id'=>1,'product_name'=>'Bath Mat','unit'=>'pcs','qty'=>3000,'amount'=>8100.00],
  ['proforma_id'=>2,'product_name'=>'bath towels','unit'=>'Pc','qty'=>2500,'amount'=>10000.00],
  ['proforma_id'=>3,'product_name'=>'Duvet Cover Set','unit'=>'Set','qty'=>1400,'amount'=>25900.00],
  ['proforma_id'=>4,'product_name'=>'Cotton Yarn 30s','unit'=>'Kg','qty'=>1200,'amount'=>1776000.00],
  ['proforma_id'=>5,'product_name'=>'Mattress Topper','unit'=>'Pc','qty'=>1250,'amount'=>34250.00],
];

class St {
  public $rows = [];
  public function __construct(private string $sql) {}
  public function execute(array $p = []): bool {
    global $HEADS, $LINES;
    if (str_contains($this->sql, 'FROM proforma_invoices')) {
      /* the last two params are the dates; the rest are the statuses */
      $to = array_pop($p); $from = array_pop($p);
      $this->rows = array_values(array_filter($HEADS,
        fn($h) => in_array($h['status'], $p, true) && $h['d'] >= $from && $h['d'] <= $to));
    } else {
      $this->rows = array_values(array_filter($LINES, fn($l) => in_array($l['proforma_id'], $p, true)));
    }
    return true;
  }
  public function fetchAll(): array { return $this->rows; }
}
class Db { public function prepare(string $s): St { return new St($s); } }
function db(): Db { static $d; return $d ??= new Db(); }

require $argv[1] . 'includes/pfcontracts.php';
echo json_encode(pfc_summary('2026-01-01', '2026-12-31'));
PHP;
$pf = sys_get_temp_dir() . '/zpfc' . getmypid() . '.php';
file_put_contents($pf, $probe);
$s = json_decode((string)shell_exec(escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg($pf) . ' ' . escapeshellarg($B) . ' 2>&1'), true);
@unlink($pf);

t('the summary ran', is_array($s) && !empty($s['ok']), $s);

if (is_array($s) && !empty($s['ok'])) {
    /* 37725*281.50 + 10000*281.50 + 25900*356.80 + 1776000*1 = 24,319,207.50 */
    $want = 37725 * 281.50 + 10000 * 281.50 + 25900 * 356.80 + 1776000;
    t('the total converts every currency into PKR',
      abs($s['total_pkr'] - $want) < 0.01, [$s['total_pkr'], $want]);

    /* The EUR proforma has no rate. It must be reported, not counted as 0. */
    t('a proforma whose currency has no rate is NOT counted', (int)$s['count'] === 4, $s['count']);
    t('and it is named rather than silently dropped',
      ($s['missing_rate']['EUR'] ?? 0) === 1, $s['missing_rate']);

    $cust = [];
    foreach ($s['customers'] as $c) $cust[$c['name']] = $c;
    /* Gulf: (37,725 + 10,000) x 281.50 = 13,434,587.50
       Decent: 1,776,000 x 1            =  1,776,000.00
       so Gulf is first. The first version of this assertion named Decent,
       which was my arithmetic being wrong, not the code. */
    t('customers are ordered by PKR value, biggest first',
      $s['customers'][0]['name'] === 'Gulf Textiles LLC'
      && $s['customers'][0]['pkr'] >= ($s['customers'][1]['pkr'] ?? 0),
      array_map(fn($c) => $c['name'] . ' ' . round($c['pkr']), $s['customers']));
    t('two proformas for one customer are added together',
      (int)$cust['Gulf Textiles LLC']['n'] === 2
      && abs($cust['Gulf Textiles LLC']['pkr'] - (37725 + 10000) * 281.50) < 0.01, $cust['Gulf Textiles LLC'] ?? null);
    t('a customer with no rate does not appear at all', !isset($cust['Nordic Linen AB']));

    /* "Bath Towel" on one proforma and "bath towels" on another are the same
       product typed twice. */
    $prod = [];
    foreach ($s['products'] as $p) $prod[$p['label']] = $p;
    t('the two spellings of Bath Towel became one row', count($s['products']) === 4, array_keys($prod));
    $bt = null;
    foreach ($s['products'] as $p) if (stripos($p['label'], 'bath towel') !== false) $bt = $p;
    t('and its value is the sum of both',
      $bt && abs($bt['pkr'] - (29625 + 10000) * 281.50) < 0.01, $bt);
    t('the screen is told which spellings were merged',
      $bt && count($bt['merged']) === 2, $bt['merged'] ?? null);
    t('a product typed only one way reports no merge',
      ($prod['Bath Mat']['merged'] ?? null) === [], $prod['Bath Mat']['merged'] ?? null);

    /* The unit question. */
    t('quantity is grouped by unit', is_array($s['qty']) && count($s['qty']) === 3, array_keys($s['qty']));
    t('Pc, Set and Kg each have their own group',
      isset($s['qty']['Pc'], $s['qty']['Set'], $s['qty']['Kg']), array_keys($s['qty']));
    t('"pcs" was folded into Pc rather than making a fourth group',
      !isset($s['qty']['pcs']) && !isset($s['qty']['Pcs']));
    $pcq = [];
    foreach ($s['qty']['Pc'] as $r) $pcq[$r['label']] = $r['qty'];
    t('the two Bath Towel lines are added within Pc',
      abs(($pcq['Bath Towel'] ?? 0) - 10000) < 0.001, $pcq);
    t('there is no grand total of quantity anywhere in the result',
      !array_key_exists('qty_total', $s) && !array_key_exists('total_qty', $s));

    /* currency mix */
    t('the currency split only holds currencies that converted',
      array_keys($s['currencies']) === ['PKR', 'USD', 'GBP']
      || !array_key_exists('EUR', $s['currencies']), array_keys($s['currencies']));
    t('the split adds up to the total',
      abs(array_sum($s['currencies']) - $s['total_pkr']) < 0.01);
}

/* ======================================================= what is counted */
head('4. Only real contracts');

$src = (string)file_get_contents($B . 'includes/pfcontracts.php');
$code = nocomments($src);
t('the statuses are one named list, not scattered through the SQL',
  preg_match("~const PFC_STATUSES = \['sent', 'confirmed', 'converted'\]~", $code) === 1);
t('draft is not among them',    !in_array('draft', PFC_STATUSES, true));
t('archived is not among them', !in_array('archived', PFC_STATUSES, true));
t('the status list is bound, never pasted into the SQL',
  str_contains($code, 'array_fill(0, count(PFC_STATUSES)') && !str_contains($code, "IN ('sent'"));
t('a proforma with no date still counts, via created_at',
  str_contains($code, 'COALESCE(pf.pi_date, DATE(pf.created_at))'));
t('the lines are fetched only for proformas already counted',
  str_contains($code, 'WHERE pi.proforma_id IN ($qin)'));
t('a zero rate is caught instead of multiplying the value away',
  str_contains($code, '$rate <= 0') && str_contains($code, "missing_rate"));
t('every list is capped so one big month cannot flood the card',
  str_contains($code, 'PFC_TOP_CUSTOMERS') && str_contains($code, 'PFC_TOP_PRODUCTS') && str_contains($code, 'PFC_TOP_PER_UNIT'));
t('a database failure returns an empty summary rather than a fatal',
  substr_count($code, 'catch (Throwable') >= 2);

head('5. Who may see it');

$dash = (string)file_get_contents($B . 'dashboard.php');
$dashN = nocomments($dash);
t('the panel needs proforma permission',  str_contains($dashN, "costing_perm('proforma')"));
t('and rate visibility — it is a money screen',
  preg_match("~\\\$pfcShow\s*=\s*costing_perm\('proforma'\)\s*&&\s*can_see_rates\(\)~", $dashN) === 1);
t('nothing is queried at all for anyone else',
  preg_match('~\$pfc\s*=\s*\$pfcShow\s*\?\s*pfc_summary~', $dashN) === 1);
t('the markup is behind the same gate',   str_contains($dashN, 'if ($pfcShow && $pfc[\'ok\']'));

head('5b. Every function the page calls is actually loadable');

/* THIS SECTION EXISTS BECAUSE THE SITE WENT DOWN.
 *
 * costing_perm() lives in includes/costing.php. bootstrap.php does not load
 * it — each page requires it for itself. dashboard.php did not, so the whole
 * page fatalled with "Call to undefined function costing_perm()" and the
 * site answered 500.
 *
 * The Chromium boot test passed throughout, because that harness DEFINES a
 * stub costing_perm(). A stub cannot tell you a require is missing; it is
 * precisely what hides one. So this check ignores stubs and follows the
 * real require chain on disk. */
$resolve = function (string $page) use ($B): array {
    $seen = [];
    $walk = function (string $file) use (&$walk, &$seen, $B) {
        $real = realpath($file);
        if ($real === false || isset($seen[$real])) return;
        $seen[$real] = true;
        $src = (string)@file_get_contents($real);
        if (preg_match_all("~require(?:_once)?\s+__DIR__\s*\.\s*'(/[^']+\.php)'~", $src, $m)) {
            foreach ($m[1] as $rel) $walk(dirname($real) . $rel);
        }
    };
    $walk($B . $page);
    $fns = [];
    foreach (array_keys($seen) as $f) {
        if (preg_match_all('~^\s*function\s+([a-zA-Z_][a-zA-Z0-9_]*)\s*\(~m', (string)@file_get_contents($f), $m)) {
            foreach ($m[1] as $fn) $fns[strtolower($fn)] = basename($f);
        }
    }
    return $fns;
};
$dashFns = $resolve('dashboard.php');

t('the require chain was followed', count($dashFns) > 50, count($dashFns));
foreach (['costing_perm', 'can_see_rates', 'pfc_summary', 'exp_pkr_rate', 'fx_get_rates', 'e'] as $fn) {
    t("dashboard.php can actually reach $fn()", isset($dashFns[$fn]),
      isset($dashFns[$fn]) ? null : 'NOT reachable — this is a 500 on the live site');
}
t('costing_perm comes from costing.php, so that file really is required',
  ($dashFns['costing_perm'] ?? '') === 'costing.php', $dashFns['costing_perm'] ?? null);

head('6. The panel');

t('it uses the dashboard period, not a period of its own',
  str_contains($dashN, 'pfc_summary($curFrom, $curTo)'));
t('the rate used is shown on screen',     str_contains($dash, "converted at today's rate"));
t('value by customer is drawn',           str_contains($dash, 'Contract value by customer'));
t('value by product is drawn',            str_contains($dash, 'Contract value by product'));
t('quantity by product is drawn',         str_contains($dash, 'Quantity by product'));
t('each unit is headed separately',       str_contains($dash, 'pfc-unit'));
t('the double-counting warning is on the page',
  str_contains($dash, 'counted twice'));
t('an unconvertible currency is reported on screen too',
  str_contains($dash, 'Left out of the total'));
t('an empty period says drafts are not counted rather than showing nothing',
  str_contains($dash, 'a draft is not a commitment'));
t('no chart library was added',
  !preg_match('~<script[^>]+src=~i', $dash));
t('every customer and product name is escaped',
  !preg_match('~<\?=\s*\$c\[.name.\]\s*\?>~', $dash) && !preg_match('~<\?=\s*\$p\[.label.\]\s*\?>~', $dash));

head('7. Nothing that already worked was changed');

t('the existing Top Customers card is still there', str_contains($dash, 'TOP CUSTOMERS'));
t('Sales Trend is still there',                     str_contains($dash, 'Sales Trend'));
t('Profitability Matrix is still there',            str_contains($dash, 'Profitability Matrix'));
t('the proforma panel sits before Top Customers, not inside it',
  strpos($dash, 'PROFORMA CONTRACTS') < strpos($dash, 'TOP CUSTOMERS'));
t('shipment figures and contract figures are never summed',
  !preg_match('~\$curTotals\[[^\]]*\]\s*\+\s*\$pfc~', $dashN)
  && !preg_match('~\$pfc\[.total_pkr.\]\s*\+\s*\$curTotals~', $dashN));

echo "\n$P passed, $F failed\n";
exit($F > 0 ? 1 : 0);
