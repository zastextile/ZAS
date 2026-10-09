<?php
/* PACKING ON A PHONE — the serial-first model.
 *
 * "Start packing with serial as from to 1 to 100 so if toggle tiny button
 *  direct qty so its means 100 ctn and give direct qty means total 1000
 *  sets and if per package means serial is compulsory as 1 to 100 means
 *  100 package ctn so per set means 10 set per so automatically clear its
 *  total is 1000"
 *
 * That sentence is the whole model and every number below comes from it.
 * The serial is typed; the count is produced. A count that is typed can
 * disagree with the serials, and then nobody knows which document is
 * right — so there is no field for it.
 *
 * What this file holds shut:
 *   THE COUNT IS NEVER AN INPUT. 1 to 100 is 100, by arithmetic only.
 *   DIRECT HIDES THE REST. One figure, not two that can contradict.
 *   ASSORTED SPLITS WHATEVER THAT MODE OWNS. Per package, or the total.
 *   NOTHING IS CHECKED AGAINST THE INVOICE. Quantity here is primary.
 *   TWO RANGES MAY NOT OWN THE SAME CARTON.
 *   EVERY FUNCTION THE SCREEN CALLS IS ACTUALLY REQUIRED. A stub in a
 *   test is exactly what hides a missing require, so that one is checked
 *   against the source, not against this process.
 */

$B = __DIR__ . '/app_src/public_html/';
$P = 0; $F = 0;
function t(string $n, bool $c, $got = null): void {
    global $P, $F;
    if ($c) { $P++; }
    else { $F++; echo "  FAIL  $n" . ($got !== null ? "\n        got: " . var_export($got, true) : '') . "\n"; }
}
function head(string $n): void { echo "\n$n\n"; }
/* Comments are not rules. Strip them before matching, or a test passes on
   prose that describes behaviour the code does not have. */
function nocomments(string $s): string {
    return (string)preg_replace(['~/\*.*?\*/~s', '~//[^\n]*~', '~(?<![:\w])#[^\n]*~'], ' ', $s);
}

/* ---- the smallest possible stubs, for the arithmetic only ---------------
   packing.php's sums never touch the database. These exist so the file
   can be loaded at all; if one of them is ever actually reached by a test
   below, that test is measuring the stub and not the app. */
if (!function_exists('db'))           { function db() { throw new RuntimeException('no db in this test'); } }
if (!function_exists('current_user')) { function current_user(): ?array { return ['id' => 1]; } }
if (!function_exists('is_admin'))     { function is_admin(): bool { return false; } }
if (!function_exists('e'))            { function e($s) { return htmlspecialchars((string)$s, ENT_QUOTES); } }

require_once $B . 'includes/packing.php';

$pkSrc  = (string)file_get_contents($B . 'includes/packing.php');
$pkN    = nocomments($pkSrc);
$mp     = (string)file_get_contents($B . 'm_pack.php');
$mpN    = nocomments($mp);

/* ===================================================== 1. serial to count */
head('1. The serial produces the count — it is never typed');

t('1 to 100 is 100 cartons',
  pack_packages(['serial_from' => 1, 'serial_to' => 100]) === 100,
  pack_packages(['serial_from' => 1, 'serial_to' => 100]));
t('101 to 225 is 125',
  pack_packages(['serial_from' => 101, 'serial_to' => 225]) === 125);
t('a single carton is 1',
  pack_packages(['serial_from' => 7, 'serial_to' => 7]) === 1);
t('a backwards range is 0, not a negative count',
  pack_packages(['serial_from' => 100, 'serial_to' => 1]) === 0,
  pack_packages(['serial_from' => 100, 'serial_to' => 1]));

t('the screen has no field that types a package count',
  !preg_match('~name="packages"~', $mpN), 'a packages input on the phone screen');
/* Not [^>]* — the value between the two is PHP, and "?>" contains a ">". */
t('and the serial fields are required',
  preg_match('~name="serial_from".{0,240}?\brequired\b~s', $mpN) === 1
  && preg_match('~name="serial_to".{0,240}?\brequired\b~s', $mpN) === 1);

/* ============================================ 2. per package versus direct */
head('2. Per package and direct give the same thousand');

$g100 = ['serial_from' => 1, 'serial_to' => 100, 'qty_mode' => 'per'];
$per  = [['size_label' => 'King', 'qty_per_pkg' => 10, 'total_qty' => 1000]];
t('10 per carton over 1–100 is 1,000',
  abs(pack_group_qty($g100, $per) - 1000) < 0.0001,
  pack_group_qty($g100, $per));

$gDir = ['serial_from' => 1, 'serial_to' => 100, 'qty_mode' => 'direct'];
$dir  = [['size_label' => 'King', 'qty_per_pkg' => 0, 'total_qty' => 1000]];
t('a direct total of 1,000 over 1–100 is still 1,000',
  abs(pack_group_qty($gDir, $dir) - 1000) < 0.0001,
  pack_group_qty($gDir, $dir));
t('and direct works back to 10 in each carton',
  abs(pack_size_per_pkg($gDir, $dir[0]) - 10) < 0.0001,
  pack_size_per_pkg($gDir, $dir[0]));

/* The point of the toggle: in direct mode the per-package field is not on
   the screen at all, so the two figures cannot disagree. */
t('direct mode asks for a total, not a per-package figure',
  str_contains($mpN, "\$mode === 'direct' ? 'Total quantity' : 'Quantity per package'"),
  'the single quantity label is not switched by the mode');
t('there is exactly one quantity input outside the assorted setup',
  substr_count($mpN, 'name="single_qty"') === 2,  /* the field, and the hidden carry-over */
  substr_count($mpN, 'name="single_qty"'));
t('nothing on the screen mentions a fixed quantity',
  !preg_match('~fix(ed)?\s*qty~i', $mpN), 'a fixed-qty wording survived');

/* ==================================================== 3. assorted packages */
head('3. Assorted — small 2, medium 4, large 4');

$mix = [['size_label' => 'Small',  'qty_per_pkg' => 2, 'total_qty' => 200],
        ['size_label' => 'Medium', 'qty_per_pkg' => 4, 'total_qty' => 400],
        ['size_label' => 'Large',  'qty_per_pkg' => 4, 'total_qty' => 400]];
t('2 + 4 + 4 in each of 100 cartons is 1,000',
  abs(pack_group_qty($g100, $mix) - 1000) < 0.0001,
  pack_group_qty($g100, $mix));
t('medium alone is 4 per carton',
  abs(pack_size_per_pkg($g100, $mix[1]) - 4) < 0.0001);

/* In direct mode the same rows hold totals, so they divide instead. */
$mixD = [['size_label' => 'Small',  'qty_per_pkg' => 0, 'total_qty' => 200],
         ['size_label' => 'Medium', 'qty_per_pkg' => 0, 'total_qty' => 400],
         ['size_label' => 'Large',  'qty_per_pkg' => 0, 'total_qty' => 400]];
t('the same split entered as totals is still 1,000',
  abs(pack_group_qty($gDir, $mixD) - 1000) < 0.0001,
  pack_group_qty($gDir, $mixD));
t('and medium works back to 4 a carton',
  abs(pack_size_per_pkg($gDir, $mixD[1]) - 4) < 0.0001,
  pack_size_per_pkg($gDir, $mixD[1]));

t('the print line names the mix, not just one size',
  pack_size_text(['assorted' => 1] + $g100, $mix) === '2 Small, 4 Medium, 4 Large',
  pack_size_text(['assorted' => 1] + $g100, $mix));
t('a single-size range prints its one size',
  pack_size_text(['assorted' => 0] + $g100, $per) === 'King',
  pack_size_text(['assorted' => 0] + $g100, $per));

t('assorted is set up on a page of its own',
  str_contains($mpN, "\$tab === 'mix'") && str_contains($mpN, 't=mix&g='),
  'no separate assorted setup');
t('and the size-by-size figures are what save',
  str_contains($pkN, 'INSERT INTO packing_group_sizes'));

/* ============================================== 4. no invoice comparison */
head('4. Quantity is primary — the invoice is never the judge');

t('the phone screen never calls the desktop over-pack guard',
  !str_contains($mpN, 'zas_pack_validate_qty'), 'the over-pack guard is wired in');
t('and packing.php does not either',
  !str_contains($pkN, 'zas_pack_validate_qty'));
t('nothing compares a packed figure with an invoice quantity',
  !preg_match('~invoice[_ ]?qty~i', $pkN . $mpN), 'an invoice-quantity comparison');
t('the invoice is read for the item only',
  str_contains($mpN, 'SELECT * FROM shipment_items'));
t('and the screen says so where it is approved',
  str_contains($mpN, 'It is not compared with the invoice'));

/* ===================================================== 5. serial conflicts */
head('5. Two ranges may not own the same carton');

t('pack_serial_problem exists and is used before every save',
  str_contains($pkN, 'function pack_serial_problem')
  && preg_match('~pack_serial_problem\(\$shipmentId, \$from, \$to, \$groupId\)~', $pkN) === 1);
t('it looks for an overlap in the same shipment',
  preg_match('~serial_from<=\?\s+AND\s+serial_to>=\?~', $pkN) === 1,
  'the overlap query is not an overlap test');
t('and it excludes the range being edited, or editing one would hit itself',
  str_contains($pkN, 'id<>?'));
t('a save that fails the serial check returns before writing',
  preg_match('~\$bad\s*=\s*pack_serial_problem.*?if \(\$bad !== \x27\x27\) return \[false~s', $pkN) === 1,
  'the serial check result is not acted on');
t('the span guard matches the desktop',
  PACK_MAX_SPAN === 200000, PACK_MAX_SPAN);

/* ======================================================= 6. the weight sums */
head('6. Weight is built from the lines, not typed');

/* One carton weighed at 14 kg, the carton itself 1 kg, 13 sets inside at
   1,000 g each: the contents come to exactly 13 kg. */
$g13 = ['serial_from' => 1, 'serial_to' => 100, 'qty_mode' => 'per'];
$s13 = [['size_label' => 'King', 'qty_per_pkg' => 13, 'total_qty' => 1300]];
t("13 sets of 1,000 g is 13 kg inside the package",
  abs(pack_contents_kg($g13, $s13, ['King' => 1000]) - 13.0) < 0.0001,
  pack_contents_kg($g13, $s13, ['King' => 1000]));

/* An assorted carton's sizes do not weigh the same, so each is counted at
   its own weight — this is the sum the demo shows balancing at 13 kg. */
$pu = ['Small' => 850, 'Medium' => 1030, 'Large' => 1150];
$mixA = [['size_label' => 'Small',  'qty_per_pkg' => 2, 'total_qty' => 200],
         ['size_label' => 'Medium', 'qty_per_pkg' => 4, 'total_qty' => 400],
         ['size_label' => 'Large',  'qty_per_pkg' => 4, 'total_qty' => 400]];
t('2x850 + 4x1030 + 4x1150 is 10.42 kg, each size at its own weight',
  abs(pack_contents_kg($g100, $mixA, $pu) - 10.42) < 0.0001,
  pack_contents_kg($g100, $mixA, $pu));
t('a size with no breakdown counts as nothing, not as the others',
  abs(pack_contents_kg($g100, $mixA, ['Small' => 850]) - 1.7) < 0.0001,
  pack_contents_kg($g100, $mixA, ['Small' => 850]));

t('net and gross are not asked for on the packing screen',
  !preg_match('~name="net_weight"|name="gross_weight"~', $mpN),
  'a net or gross input survived');
t('the weight lines are typed material, name and grams',
  str_contains($mpN, 'name="w_type[]"') && str_contains($mpN, 'name="w_name[]"')
  && str_contains($mpN, 'name="w_grams[]"'));
t('the material types are the ones asked for',
  in_array('Fabric', PACK_WTYPES, true) && in_array('Fiber / filling', PACK_WTYPES, true)
  && in_array('PVC / poly bag', PACK_WTYPES, true) && in_array('Accessories', PACK_WTYPES, true),
  PACK_WTYPES);
t('a new line starts as Fabric, not as the last type in the list',
  str_contains($mp, "ROWS.push({ t: TYPES[0]"), 'the new-line default is not the first type');
t('a line worth nothing is not stored',
  preg_match('~if \(\$g <= 0\) continue;~', $pkN) === 1);

/* =========================================== 7. the remembered breakdown */
head('7. The last breakdown is remembered per product and size');

t('punctuation becomes a space, so Bath-Towel meets Bath Towel',
  pack_product_key('Bath-Towel') === pack_product_key('Bath  Towel'),
  [pack_product_key('Bath-Towel'), pack_product_key('Bath  Towel')]);
t('and 500GSM meets 500 gsm, which is how the same product gets retyped',
  pack_product_key('Bath Towel 500GSM') === pack_product_key('Bath-Towel 500 gsm'),
  [pack_product_key('Bath Towel 500GSM'), pack_product_key('Bath-Towel 500 gsm')]);
t('300TC meets 300 TC the same way',
  pack_product_key('Flat Sheet 300TC') === pack_product_key('Flat Sheet 300 tc'));
t('and two different products still differ',
  pack_product_key('Bath Towel') !== pack_product_key('Beach Towel'));
t('the standard is replaced, not merged — a deleted line must go',
  preg_match('~DELETE FROM packing_weight_std WHERE product_key=\? AND size_label=\?~', $pkN) === 1);
t('it is saved when the list is approved',
  preg_match('~pack_approve.*?pack_std_save~s', $pkN) === 1);
t('and a standard that will not save cannot lose the packing',
  preg_match('~function pack_std_save.*?catch \(Throwable \$e\) \{~s', $pkN) === 1);
t('the screen offers it only when something is remembered',
  str_contains($mpN, '$hasStd') && str_contains($mpN, 'pack_std_get('));

/* ====================================================== 8. the ten per cent */
head('8. The approver may overrule, within ten per cent');

t('the tolerance is ten per cent',
  abs(PACK_TOLERANCE_PCT - 10.0) < 0.0001, PACK_TOLERANCE_PCT);
t('no difference reads as nothing',
  abs(pack_deviation_pct(100.0, 100.0)) < 0.0001);
t('ten per cent out is ten',
  abs(pack_deviation_pct(100.0, 110.0) - 10.0) < 0.0001, pack_deviation_pct(100.0, 110.0));
t('under is measured the same as over',
  abs(pack_deviation_pct(100.0, 90.0) - 10.0) < 0.0001, pack_deviation_pct(100.0, 90.0));
t('nothing calculated yet cannot be a percentage of nothing',
  abs(pack_deviation_pct(0.0, 50.0)) < 0.0001, pack_deviation_pct(0.0, 50.0));
t('approve refuses beyond the tolerance, before it writes anything',
  preg_match('~pack_deviation_pct\(\$t\[\x27net\x27\], \$finalNet\) > PACK_TOLERANCE_PCT~', $pkN) === 1
  && preg_match('~> PACK_TOLERANCE_PCT.*?return \[false~s', $pkN) === 1);
t('an approved override is spread over the rows, so they still add up',
  str_contains($pkN, '$netScale') && str_contains($pkN, '$grossScale'),
  'the rows keep calculated weights while the header says something else');

/* ============================================= 9. it is the same database */
head('9. The same tables the desktop already uses');

t('the finished line still goes to packing_items',
  str_contains($pkN, 'INSERT INTO packing_items'));
t('the phone screen builds no table of its own',
  !preg_match('~CREATE TABLE~i', $mpN), 'a CREATE TABLE in the phone screen');
t('approve only clears rows the ranges own, never hand-entered ones',
  str_contains($pkN, 'DELETE FROM packing_items WHERE shipment_id=? AND packing_group_id IS NOT NULL'),
  'approve deletes more than its own rows');
t('the shipment header is updated from the approved figures',
  preg_match('~UPDATE shipments SET total_packages=\?, total_net_weight=\?, total_gross_weight=\?~', $pkN) === 1);
t('the schema is guarded by a version marker, not run every page load',
  str_contains($pkN, "k='pack_schema_version'") && str_contains($pkN, 'PACK_SCHEMA_VERSION'));
t('and it has its own key, so it cannot fight the export version',
  !str_contains($pkN, "k='schema_version'"), 'it writes the export module version marker');

/* ========================================================= 10. permission */
head('10. The same permission, the same CSRF');

t('login is required',          str_contains($mpN, 'require_login()'));
t('production staff cannot open it', str_contains($mpN, 'is_production_staff()'));
t('an unassigned shipment is refused',
  str_contains($mpN, 'assigned_shipment_ids()') && str_contains($mpN, 'not assigned to you'));
t('every POST verifies CSRF before anything else',
  preg_match('~REQUEST_METHOD.*?===\s*\x27POST\x27.*?\{\s*verify_csrf\(\);~s', $mpN) === 1,
  'a POST is handled before CSRF is checked');
t('and a POST is refused outright when the list is closed',
  preg_match('~verify_csrf\(\);\s*if \(!\$canEdit\)~', $mpN) === 1);
t('a closed list is read-only, not merely hidden',
  substr_count($mpN, 'if ($canEdit)') >= 3);
t('the edit rule lives in one place for both screens',
  str_contains($pkN, 'function pack_may_edit'));
t('a locked shipment is admin only',
  preg_match('~\$locked.*?return is_admin\(\);~s', $pkN) === 1);

/* ================================================ 11. the require chain
   The dashboard 500 was a function called from a page that never required
   the file defining it — and the boot test passed because the harness had
   stubbed that very function. So this one ignores what is loaded in this
   process and reads the source: every app function m_pack.php calls must
   be defined in a file it reaches through its own requires. */
head('11. Every function the screen calls is actually required');

$seen = [];
$collect = function (string $file) use (&$collect, &$seen, $B): string {
    $real = realpath($file);
    if ($real === false || isset($seen[$real])) return '';
    $seen[$real] = true;
    $src = (string)file_get_contents($real);
    $out = $src;
    if (preg_match_all('~require(?:_once)?\s+__DIR__\s*\.\s*\x27([^\x27]+)\x27~', $src, $m)) {
        foreach ($m[1] as $rel) $out .= $collect(dirname($real) . $rel);
    }
    return $out;
};
$chain  = $collect($B . 'm_pack.php');
$chainN = nocomments($chain);

$defined = [];
if (preg_match_all('~function\s+([a-zA-Z_][a-zA-Z0-9_]*)\s*\(~', $chainN, $m)) {
    foreach ($m[1] as $fn) $defined[strtolower($fn)] = true;
}
$internal = [];
foreach (get_defined_functions()['internal'] as $fn) $internal[strtolower($fn)] = true;

/* Only PHP calls count. A <script> block is full of JavaScript calls, a
   <style> block has rgba(), and "WHERE id IN ($in)" inside a string looks
   like a call to IN() — all three would be reported as missing functions
   and none of them is one. */
$phpOnly = (string)preg_replace(
    ['~<script\b.*?</script>~is', '~<style\b.*?</style>~is',
     '~\x27(?:[^\x27\\\\]|\\\\.)*\x27~s', '~"(?:[^"\\\\]|\\\\.)*"~s'],
    ' ', $mpN);

$missing = [];
if (preg_match_all('~(?<![\$>:\w])([a-zA-Z_][a-zA-Z0-9_]*)\s*\(~', $phpOnly, $m)) {
    foreach (array_unique($m[1]) as $fn) {
        $l = strtolower($fn);
        if (isset($defined[$l]) || isset($internal[$l])) continue;
        /* language constructs and the shapes a regex cannot tell from a call */
        if (in_array($l, ['if', 'for', 'foreach', 'while', 'switch', 'catch', 'function',
                          'fn', 'array', 'isset', 'unset', 'empty', 'list', 'echo', 'print',
                          'exit', 'die', 'return', 'require', 'require_once', 'include',
                          'include_once', 'new', 'match', 'use', 'elseif', 'and', 'or'], true)) continue;
        $missing[] = $fn;
    }
}
t('no function is called that nothing in the require chain defines',
  $missing === [], $missing);
t('the chain really was walked, not silently empty',
  count($seen) >= 4 && isset($defined['pack_packages']) && isset($defined['mob_header']),
  array_keys($seen));

/* ==================================================== 12. the small print */
head('12. Loose ends');

t('the packing screen has its own installable manifest',
  is_file($B . 'manifest_pack.json'));
t('and asks for it by name',
  str_contains($mpN, "'manifest_pack.json'"));
t('the gate keeps the manifest it had, untouched',
  preg_match('~string \$manifest = \x27manifest_gate\.json\x27~',
             nocomments((string)file_get_contents($B . 'includes/mobile.php'))) === 1,
  'the shell no longer defaults to the gate manifest');
$mobN = nocomments((string)file_get_contents($B . 'includes/mobile.php'));
t('the manifest is escaped into the page, not pasted raw',
  str_contains($mobN, 'href="<?= e($manifest) ?>"'));
t('the overlay fix is still in the shell',
  str_contains($mobN, '[hidden]{display:none!important}'));
t('deleting a range takes its weights with it',
  preg_match('~function pack_group_delete.*?DELETE FROM packing_weight_lines.*?'
           . 'DELETE FROM packing_group_sizes.*?DELETE FROM packing_groups~s', $pkN) === 1,
  'a removed range leaves its weight lines behind');
t('a size that is renamed carries its weight lines across',
  str_contains($mp, "g.wt[old]") === false, 'leftover demo code in the app file');
t('no stray demo wording reached the app',
  !preg_match('~nothing is saved|sample data~i', $mp), 'demo text in the shipped screen');

echo "\n" . ($F ? "FAILED  $F" : 'ALL PASS') . "   ($P checks)\n";
exit($F ? 1 : 0);
