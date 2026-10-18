<?php
/* GATE OUTWARD MAY GO BELOW ZERO — WHEN THE ADMIN SAYS SO.
 *
 * "send gate out as sale or job work out or sample etc even we do not have
 *  stock ... show my inventory minus because I do not want to hold or wait."
 *
 * Before: a short pass needed a reason in Remarks, and beyond the 10%
 * allowance only an admin could post it. At zero stock the allowance is
 * zero, so every short pass waited for an admin.
 *
 * Now, with "Gate Outward below zero" on (Inventory Setup, on by default):
 *   THE PASS POSTS for anyone with the post right, no reason demanded.
 *   THE SHORTAGE IS WRITTEN DOWN on the pass (posted_short) and in the audit.
 *   THE SCREEN, THE PRINT AND THE FLASH say "Posted short".
 *   OFF PUTS THE OLD RULE BACK, untouched.
 *   ONLY GATE OUTWARD. Consumption and store issue keep the allowance.
 *
 * The posting itself was run against MariaDB: switch off refuses a
 * storekeeper's short Sale; switch on posts it to -500 with posted_short
 * filled; reversing puts the stock back. This file holds the code shut.
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
    $out = '';
    foreach (token_get_all($s) as $t) {
        if (is_array($t)) { $out .= ($t[0] === T_COMMENT || $t[0] === T_DOC_COMMENT) ? ' ' : $t[1]; }
        else $out .= $t;
    }
    return $out;
}
function lift(string $src, string $from): string {
    $a = strpos($src, $from); if ($a === false) return '';
    $o = strpos($src, '{', $a); $d = 0;
    for ($i = $o, $n = strlen($src); $i < $n; $i++) {
        if ($src[$i] === '{') $d++;
        elseif ($src[$i] === '}') { $d--; if ($d === 0) return substr($src, $a, $i - $a + 1); }
    }
    return '';
}

$inv  = (string)file_get_contents($B . 'includes/inventory.php');
$invN = nocomments($inv);
$post = nocomments(lift($inv, 'function inv_gate_post('));
$gate = nocomments((string)file_get_contents($B . 'inv_gate.php'));
$prt  = nocomments((string)file_get_contents($B . 'inv_gate_print.php'));
$set  = nocomments((string)file_get_contents($B . 'inv_setup.php'));

/* ===================================================================== */
head('1. The switch');
$SETTINGS = [];
function inv_setting(string $k, string $d = '') { global $SETTINGS; return $SETTINGS[$k] ?? $d; }
$fn = lift($inv, 'function inv_gate_out_negative_ok(');
t('inv_gate_out_negative_ok() exists', $fn !== '');
eval($fn);
t('with nothing saved it is ON', inv_gate_out_negative_ok() === true);
$SETTINGS['gate_out_negative'] = '0';
t('saved 0 turns it off', inv_gate_out_negative_ok() === false);
$SETTINGS['gate_out_negative'] = '1';
t('saved 1 turns it on again', inv_gate_out_negative_ok() === true);
t('the default is seeded ON for every install', str_contains($invN, "'gate_out_negative' => '1'"));
t('the column for the note is added to inv_gate', str_contains($invN, 'ALTER TABLE inv_gate ADD COLUMN posted_short TEXT NULL'));

head('2. Posting: a shortage is noted, not stopped — only when the switch is on');
t('the switch is read only for passes that take stock out',
  str_contains($post, "\$negOk = \$takesOut && inv_gate_out_negative_ok();"));
$pNote = strpos($post, 'if ($negOk) {');
$pStop = strpos($post, "if (\$why === '') {");
t('the note branch comes BEFORE the reason and admin checks', $pNote !== false && $pStop !== false && $pNote < $pStop);
$branch = substr($post, (int)$pNote, (int)$pStop - (int)$pNote);
t('  and it notes the item, the place, what was there, and the balance after',
  str_contains($branch, '$shortNote[] = "$what at $where: $has in stock, sent $over more, balance $left.";'));
t('  and goes on to the next line instead of stopping', str_contains($branch, 'continue;'));
t('the old stops are still there for when the switch is off',
  str_contains($post, 'Write why in Remarks and save the pass, then post it.')
  && str_contains($post, 'so only an admin can post it.'));
t('posted_short is saved in its own statement, so it can never stop a post',
  (bool)preg_match('~if \(\$shortNote\) \{\s*try \{ db\(\)->prepare\("UPDATE inv_gate SET posted_short=\? WHERE id=\?"\)~', $post));
t('  after the pass is marked posted, before the commit',
  strpos($post, "SET status='posted'") < strpos($post, 'SET posted_short=?') && strpos($post, 'SET posted_short=?') < strpos($post, 'db()->commit();'));
t('the audit says POSTED SHORT with the details', str_contains($post, "' — POSTED SHORT: ' . implode(' ', \$shortNote)"));
t('the result hands the shortage back to the page', str_contains($post, "return ['ok' => true, 'error' => '', 'short' => \$shortNote];"));
t('the inward check (coming back from a mill) is not touched by the switch',
  !str_contains(substr($post, (int)strpos($post, '$comesBack')), '$negOk'));

head('3. Only Gate Outward');
$cons = nocomments(lift($inv, 'function inv_consumption_post('));
t('consumption posting does not read the switch', $cons !== '' && !str_contains($cons, 'gate_out_negative'));
$store = nocomments(lift($inv, 'function inv_store_post('));
t('store issue posting does not read the switch', !str_contains($store, 'gate_out_negative'));

head('4. What people see');
t('the post message says "Posted short" and where it shows',
  str_contains($gate, "'Posted short — stock is now below zero: ' . implode(' ', \$r['short'])"));
t('the form script knows the switch', str_contains($gate, "var NEGOK = <?= inv_gate_out_negative_ok() ? 'true' : 'false' ?>;"));
t('  and warns "Stock will go below zero" with the balance it will become',
  str_contains($gate, "'<b>Stock will go below zero.</b><br>'") && str_contains($gate, "balance becomes <b>' + q3(av - want) + '</b>'"));
t('  with the switch off it keeps the old words', str_contains($gate, "'<b>This pass takes more than is here.</b><br>'"));
t('a posted-short pass carries the stamp and the note',
  str_contains($gate, '<span class="ig-short">Posted short</span>') && str_contains($gate, "nl2br(e((string)\$doc['posted_short']))"));
t('  only on a posted pass with a note', str_contains($gate, "trim((string)(\$doc['posted_short'] ?? '')) !== ''"));
/* "still not allow gate outward sale ... not show any list": with the switch
   on, the item list opened on "Nothing is in stock" and hid every item
   behind a small "show all" link. */
t('with the switch on, the item list does not hide items with nothing here',
  str_contains($gate, "if(mode !== 'all' && !(b > 0.0005) && !(NEGOK && mode === 'here')){ if(!showAll){ hidden++; return; } }"));
t('  in-stock items are listed first', str_contains($gate, "var ia = a.bal > 0.0005 ? 0 : 1, ib = b.bal > 0.0005 ? 0 : 1;"));
t('  and the heading says every item is offered',
  str_contains($gate, "if(NEGOK) return 'Issuing — ' + c + 'every item, stock at ' + locName() + ' shown';"));
t('the print carries POSTED SHORT',
  str_contains($prt, "\$doc['status'] === 'posted' && trim((string)(\$doc['posted_short'] ?? '')) !== ''") && str_contains($prt, 'POSTED SHORT'));

head('5. Inventory Setup: admin turns it off and on');
t('the tick box is on the settings form', str_contains($set, 'name="gate_out_negative" value="1"'));
t('  ticked when the switch is on', str_contains($set, "<?= inv_gate_out_negative_ok() ? 'checked' : '' ?>"));
t('an unticked box is saved as 0, not ignored',
  str_contains($set, "if (isset(\$_POST['gate_out_negative_shown']))") && str_contains($set, "inv_set_setting('gate_out_negative', \$now ? '1' : '0');"));
t('a change is written to the audit', str_contains($set, "'Gate Outward below zero: ' . (\$now ? 'ALLOWED' : 'STOPPED')"));
$guard = nocomments(substr((string)file_get_contents($B . 'inv_setup.php'), 0, 600));
t('Inventory Setup is still admin only', str_contains($guard, "if (!is_admin()) { http_response_code(403);"));

echo "\n" . str_repeat('-', 58) . "\n";
echo ($F === 0 ? "ALL PASS" : "FAILURES") . "   $P passed, $F failed\n";
exit($F === 0 ? 0 : 1);
