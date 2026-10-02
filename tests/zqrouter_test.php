<?php
/* THE QUERY ROUTER.
 *
 * Ten questions were written down when this was specified. Every one is a
 * database question, and the old search sent all ten to OpenAI twice — once
 * to embed the question, once to write a paragraph — then returned prose
 * where a list was wanted. There was no cap, no cache and no counter.
 *
 * So two things are tested here above all:
 *
 *   THE TEN QUESTIONS PARSE. Not approximately — into the exact filters that
 *   answer them.
 *
 *   NOTHING SPENDS WITHOUT A PRESS. create_embedding() and gpt_answer() must
 *   be unreachable unless a button was clicked and the monthly cap has room.
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

$src = file_get_contents($B . 'includes/qrouter.php');
t('includes/qrouter.php was found', $src !== false && $src !== '');

const QR_MODES = ['shipment', 'proforma', 'costing'];
const QR_FILLER = [
    'show','find','list','all','the','me','my','with','where','is','are','was','were',
    'for','of','in','to','on','at','and','or','a','an','any','which','what','did','do',
    'we','us','using','used','use','still','by','from','please','give','get','see',
    'shipment','shipments','invoice','invoices','proforma','proformas','costing','costings',
    'order','orders','payment','payments','document','documents','doc','docs',
    'container','containers','value','amount','total','no','number','code','against',
    'made','been','have','has','that','this','it','there','about','much','many','last',
];
t('the filler list here matches the shipped one',
  substr_count($src, "'shipments','invoice'") === 1);

/* The dictionaries are the only thing the parser reads from the database. */
$DICT = [
    'country'  => ['belgium' => 'Belgium', 'spain' => 'Spain', 'france' => 'France', 'uae' => 'UAE'],
    'port'     => ['antwerp' => 'ANTWERP', 'jebel ali' => 'JEBEL ALI', 'valencia' => 'VALENCIA'],
    'buyer'    => ['abc trading' => 'ABC Trading', 'gulf textiles' => 'Gulf Textiles'],
    'customer' => ['aruf group' => 'Aruf Group', 'abc trading' => 'ABC Trading'],
    'agent'    => ['abc logistics' => 'ABC Logistics', 'star shipping' => 'Star Shipping'],
    'product'  => ['hotel flat sheet 300tc' => 'Hotel Flat Sheet 300TC'],
];
function qr_dict(string $what): array { global $DICT; return $DICT[$what] ?? []; }
function current_user() { return ['id' => 1]; }
function db() { throw new Exception('the parser must not touch the database'); }

eval(lift($src, 'function qr_parse('));
eval(lift($src, 'function qr_has_filters('));
eval(lift($src, 'function qr_is_description('));
eval(lift($src, 'function qr_where('));
eval(lift($src, 'function qr_doc_status('));
eval(lift($src, 'function qr_chips('));

function F(string $q, string $mode = 'shipment'): array { return qr_parse($mode, $q)['filters']; }

/* ------------------------------------------------------------------------- */
head('1. The ten questions this was built for');

$f = F('Show shipments to Belgium where payment is still outstanding');
t('Belgium + outstanding', ($f['country'] ?? '') === 'Belgium' && ($f['pay'] ?? '') === 'unpaid', $f);

$f = F('Find all Spain shipments in September');
t('Spain + September', ($f['country'] ?? '') === 'Spain' && ($f['month'] ?? 0) === 9, $f);

$f = F('Show shipments using ABC Logistics');
t('freight agent', ($f['agent'] ?? '') === 'ABC Logistics', $f);

$f = F('Which shipment used container SEKU6489931?');
t('container number', ($f['container'] ?? '') === 'SEKU6489931', $f);

$f = F('Show invoices with HS code 630231');
t('HS code', ($f['hs'] ?? '') === '630231', $f);

$f = F('Show freight above USD 3,000');
t('freight over 3000', ($f['min_amount'] ?? 0) == 3000 && ($f['amount_on'] ?? '') === 'freight', $f);

/* The USD in "above USD 3,000" names the UNIT OF THE THRESHOLD, not a filter
   on the record. Treating it as a currency filter would quietly drop every
   EUR shipment whose freight exceeds 3,000 — a wrong answer that looks
   right. This assertion originally demanded the opposite and the parser was
   correct, not the test. */
t('  and the currency in a money phrase is NOT taken as a filter',
  !isset($f['currency']), $f);

$f = F('EUR shipments');
t('  while a currency on its own still is', ($f['currency'] ?? '') === 'EUR', $f);

$f = F('Show partially paid invoices');
t('partially paid', ($f['pay'] ?? '') === 'partial', $f);

$f = F('Find all Chamber documents for ABC Trading');
t('chamber + buyer', ($f['doc'] ?? '') === 'chamber' && ($f['buyer'] ?? '') === 'ABC Trading', $f);

$f = F('What did we pay the last time we shipped to Antwerp');
t('port picked out of a conversational question', ($f['port'] ?? '') === 'ANTWERP', $f);

/* The tenth, "find BL where consignee was amended", is an audit-log question
   and the router does not pretend to answer it. It must therefore come back
   with no filters, so the page offers the meaning search instead of
   inventing a result. */
$p = qr_parse('shipment', 'Find BL where consignee was amended');
t('an audit-log question is NOT answered with a wrong filter',
  !isset($p['filters']['bl']) && $p['leftover'] !== '', $p);

/* ------------------------------------------------------------------------- */
head('2. Nothing is confused for something else');

$f = F('ZAS/5191');
t('an invoice number is a reference, not a quantity', ($f['ref'] ?? '') === 'ZAS/5191', $f);
t('  and it is not read as an HS code', !isset($f['hs']), $f);

$f = F('container MSCU1142876 arrived');
t('a container and a status together',
  ($f['container'] ?? '') === 'MSCU1142876' && ($f['logistics'] ?? '') === 'arrived', $f);

$f = F('invoices over 50000 under 90000');
t('two bounds at once', ($f['min_amount'] ?? 0) == 50000 && ($f['max_amount'] ?? 0) == 90000, $f);

$f = F('shipments over 3000');
t('without the word freight, the bound is on the invoice value',
  ($f['min_amount'] ?? 0) == 3000 && !isset($f['amount_on']), $f);

$f = F('draft shipments');
t('draft is a document status', ($f['status'] ?? '') === 'draft', $f);
t('  and maps to the real column value', qr_doc_status('draft') === 'draft');
t('approved maps to approved_locked', qr_doc_status('locked') === 'approved_locked');

$f = F('PI-260908-786');
t('a proforma number is recognised', ($f['ref'] ?? '') === 'PI-260908-786', $f);

$f = F('bl no MAEU240817221');
t('a BL number is recognised', ($f['bl'] ?? '') === 'MAEU240817221', $f);
t('  and is not mistaken for a container', !isset($f['container']), $f);

$f = F('SEKU6489931 HS 630231');
t('a container and an HS code in one question stay separate',
  ($f['container'] ?? '') === 'SEKU6489931' && ($f['hs'] ?? '') === '630231', $f);

/* ---------------------------------------------------------------------
   SIX LOOSE DIGITS ARE NEVER AN HS CODE.

   next_doc_no() builds every number in this system as PREFIX-YYMMDD-NNN,
   so PI-260908-786, INV-260908-786 and CI-260908-455 all contain a
   six-digit date. A bare-digits rule would answer a search for a proforma
   with a search for an HS code. There is no such rule, and these
   assertions exist to stop one being added back.
   --------------------------------------------------------------------- */
foreach (['PI-260908-786', 'INV-260908-786', 'CI-260908-455', 'CV-260908-991'] as $docNo) {
    $f = F($docNo);
    t($docNo . ' is a document number, not an HS code',
      ($f['ref'] ?? '') === $docNo && !isset($f['hs']), $f);
}

$f = F('630231');
t('six digits alone are NOT taken for an HS code', !isset($f['hs']), $f);
$p2 = qr_parse('shipment', '630231');
t('  and the router says so rather than guessing', qr_is_description($p2) === true, $p2);

foreach (['HS 630231' => '630231', 'hs code 630231' => '630231',
          'hs:630231' => '630231', 'tariff 630231' => '630231'] as $typed => $want) {
    $f = F($typed);
    t('"' . $typed . '" IS an HS code', ($f['hs'] ?? '') === $want, $f);
}

/* short_ref() prints PI-260908-786 as PI-786, which is what people read off
   a screen and type back in. Both must find the same record. */
$f = F('PI-786');
t('a shortened reference is still a reference', ($f['ref'] ?? '') === 'PI-786', $f);
eval(lift($src, 'function qr_ref_match('));
[$rw, $rp] = qr_ref_match('pf.pi_no', 'PI-786');
t('  and it matches the stored long number', $rp === ['PI-786', 'PI%786'], $rp);
t('  with no trailing wildcard, so PI-786 cannot match PI-1786',
  str_ends_with((string)$rp[1], '786') && !str_ends_with((string)$rp[1], '%'), $rp[1]);
[$rw, $rp] = qr_ref_match('s.invoice_no', 'ZAS/5191');
t('  and a hand-typed invoice number works the same way',
  $rp === ['ZAS/5191', 'ZAS%5191'], $rp);

/* ------------------------------------------------------------------------- */
head('3. Dates');

$f = F('shipments in September 2026');
t('month and year together', ($f['month'] ?? 0) === 9 && ($f['year'] ?? 0) === 2026, $f);
$f = F('shipments this month');
t('this month resolves to now', ($f['month'] ?? 0) === (int)date('n') && ($f['year'] ?? 0) === (int)date('Y'), $f);
$f = F('shipments last month');
t('last month resolves to the month before', ($f['month'] ?? 0) === (int)date('n', strtotime('first day of last month')), $f);
$f = F('shipments in the last 30 days');
t('a rolling window', ($f['days'] ?? 0) === 30, $f);
$f = F('shipments this week');
t('this week is seven days', ($f['days'] ?? 0) === 7, $f);

/* ------------------------------------------------------------------------- */
head('4. A description is not a filter');

$p = qr_parse('shipment', 'blue striped sateen sheet sets');
t('a pure description finds no filters', qr_has_filters($p) === false, $p['filters']);
t('  and is recognised as a description', qr_is_description($p) === true);
t('  with the words kept for the meaning search', $p['leftover'] !== '', $p['leftover']);

$p = qr_parse('shipment', 'Show shipments to Belgium where payment is still outstanding');
t('a question that WAS understood is not called a description', qr_is_description($p) === false);
t('  and reports nothing as unrecognised', $p['leftover'] === '', $p['leftover']);

$p = qr_parse('shipment', 'Which shipment used container SEKU6489931?');
t('filler words do not show up as unrecognised', $p['leftover'] === '', $p['leftover']);

$p = qr_parse('shipment', '');
t('an empty question is not a description', qr_is_description($p) === false);

/* ------------------------------------------------------------------------- */
head('5. The SQL it builds');

[$w, $pp] = qr_where('shipment', F('Show shipments to Belgium where payment is still outstanding'));
t('the country becomes a bound parameter, never inlined',
  str_contains($w, 's.buyer_country = ?') && in_array('Belgium', $pp, true), [$w, $pp]);
t('unpaid is computed from the payments table, not a stored flag',
  str_contains($w, 'FROM shipment_payments') && str_contains($w, 'is_void=0'), $w);

[$w, $pp] = qr_where('shipment', F('Show freight above USD 3,000'));
t('a freight bound sums the freight cost rows', str_contains($w, "LIKE '%Freight%'"), $w);
t('  and the number is a parameter', in_array(3000.0, $pp, true), $pp);

[$w, $pp] = qr_where('shipment', F('Show invoices with HS code 630231'));
t('HS looks at both the customs lines and the product',
  str_contains($w, 'exp_doc_lines') && str_contains($w, 'products'), $w);

[$w, $pp] = qr_where('shipment', F('Which shipment used container SEKU6489931?'));
t('a container checks the new table AND the old combined field',
  str_contains($w, 'shipment_containers') && str_contains($w, 'bl_container_no'), $w);

/* Every value must travel as a parameter. One inlined string is an injection. */
foreach ([
    "buyer ABC Trading", "ZAS/5191", "container SEKU6489931", "over 5000",
    "Belgium in September", "bl no MAEU240817221", "chamber for Gulf Textiles",
] as $qq) {
    [$w, $pp] = qr_where('shipment', F($qq));
    $n = substr_count($w, '?');
    t('"' . $qq . '" binds every value (' . $n . ' placeholders)',
      $n >= 1 && !preg_match("~=\s*'~", $w), $w);
}

/* A hostile question must not reach the SQL as text. */
$evil = "buyer ABC Trading'; DROP TABLE shipments;--";
[$w, $pp] = qr_where('shipment', F($evil));
t('an injection attempt never lands in the SQL string',
  !str_contains($w, 'DROP') && !str_contains($w, '--'), $w);

[$w, $pp] = qr_where('shipment', []);
t('no filters produces no WHERE at all', $w === '' && $pp === []);

[$w, $pp] = qr_where('proforma', F('GBP proformas over 10000', 'proforma'));
t('proforma mode sums its own items for the amount',
  str_contains($w, 'proforma_items') && str_contains($w, 'pf.currency = ?'), $w);

[$w, $pp] = qr_where('costing', F('costings over 1200', 'costing'));
t('costing mode compares total_cost by default', str_contains($w, 'cv.total_cost > ?'), $w);
[$w, $pp] = qr_where('costing', F('costings with price over 1500', 'costing'));
t('  and suggested_price when the question says price', str_contains($w, 'cv.suggested_price > ?'), $w);

/* ------------------------------------------------------------------------- */
head('6. The chips say what was understood');

$c = qr_chips(qr_parse('shipment', 'Spain shipments in September over 10000'));
$flat = array_map(fn($x) => $x[0] . '=' . $x[1], $c);
t('country, month and bound all appear',
  in_array('Country=Spain', $flat, true) && in_array('Month=September', $flat, true)
  && (bool)preg_grep('~More than~', $flat), $flat);

t('an empty question produces no chips', qr_chips(qr_parse('shipment', '')) === []);

/* ------------------------------------------------------------------------- */
head('7. Nothing spends without a press');

$sRaw = file_get_contents($B . 'search.php');

/* COMMENTS STRIPPED BEFORE COUNTING.
 *
 * The first version of this section counted occurrences of create_embedding
 * and gpt_answer in the whole file and failed, because the comment at the
 * top of the rewrite explains what the old code used to call. A comment is
 * not a call. Three separate checks in this project have now been fooled by
 * their own documentation, so the counting here runs on code only. */
$s = preg_replace('~/\*.*?\*/~s', '', $sRaw);
$s = preg_replace('~^\s*//.*$~m', '', $s);

t('search.php loads the router', str_contains($s, "require_once __DIR__ . '/includes/qrouter.php'"));

/* The two paid calls must sit inside a branch that requires a button. */
$semBlock = '';
if (preg_match('~\} elseif \(\$wantSemantic\) \{(.*?)\n    \} elseif \(\$wantExplain\)~s', $s, $m)) $semBlock = $m[1];
$expBlock = '';
if (preg_match('~\} elseif \(\$wantExplain\) \{(.*?)\n    \}\n~s', $s, $m2)) $expBlock = $m2[1];

t('the semantic branch was found', $semBlock !== '');
t('the explain branch was found', $expBlock !== '');
t('create_embedding is only inside the semantic branch',
  substr_count($s, 'create_embedding(') === 1 && str_contains($semBlock, 'create_embedding('),
  substr_count($s, 'create_embedding('));
t('gpt_answer appears only inside the two paid branches',
  substr_count($s, 'gpt_answer(') === 2
  && str_contains($semBlock, 'gpt_answer(') && str_contains($expBlock, 'gpt_answer('),
  substr_count($s, 'gpt_answer('));

t('both buttons are refused when the cap is used up',
  (bool)preg_match('~if \(\(\$wantExplain \|\| \$wantSemantic\) && !\$budget\[.ok.\]\)~', $s));
t('  and the refusal says filter searches still work',
  str_contains($s, 'Filter searches are unaffected and still free'));

t('every paid call is counted', substr_count($s, 'qr_spend(1)') === 3, substr_count($s, 'qr_spend(1)'));
t('every search is logged', str_contains($s, 'qr_log($mode, $q, $parsed,'));

/* The old behaviour must be gone, not merely bypassed. The render still
   mentions approved_locked to draw a status badge, which is correct — what
   must not survive is the hard-coded WHERE that hid drafts. */
t('the hard-coded approved_locked filter is gone from the shipment query',
  !preg_match("~WHERE[^;]*s\.status\s*=\s*'approved_locked'~s", $s));
t('  while the status badge still reads it for display',
  str_contains($sRaw, "\$s['status']==='approved_locked'"));
t('the unbounded vector load is gone',
  !preg_match('~FROM costing_embeddings\"\)~', $s) || str_contains($s, 'LIMIT 300'));
t('the semantic step is pre-filtered by the rows the filters found',
  str_contains($s, 'e.shipment_id IN ('));

/* ------------------------------------------------------------------------- */
head('8. The budget');

$bud = lift($src, 'function qr_budget(');
t('a default cap exists so an unconfigured install is still capped',
  str_contains($bud, '$cap = 500'));
t('the cap is read from settings when set', str_contains($bud, "k='ai_cap_calls'"));
t('ok is false once the cap is reached', str_contains($bud, "'ok' => \$used < \$cap"));

$sp = lift($src, 'function qr_spend(');
t('spending is additive and cannot be lost by a race',
  str_contains($sp, 'ON DUPLICATE KEY UPDATE calls_used = calls_used + VALUES(calls_used)'));

t('the parser itself never touches the database',
  !str_contains(lift($src, 'function qr_parse('), 'db()'));

echo "\n$P passed, $F failed\n";
exit($F > 0 ? 1 : 0);
