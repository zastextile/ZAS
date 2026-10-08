<?php
/* GATE ON A PHONE, and the rate rule that moved.
 *
 * "keep there field which is compulsory to data also its draft base purpose"
 *
 * The gatekeeper records what came through: type, vehicle, item, quantity.
 * Not the rate — seeing rates is a permission of its own here, and someone
 * standing at the gate at night will either hold the truck or type a
 * plausible number. A guessed rate is worse than a blank one: blank is
 * visibly unfinished, a guess is silently wrong.
 *
 * So the rate moved. It is no longer demanded when a pass is saved, and it
 * IS demanded before stock is written. That second half is a tightening:
 * until now nothing checked rates at posting at all.
 *
 * What this file holds shut:
 *   THE PHONE CANNOT POST. No button, and posting is a separate permission.
 *   IT CANNOT TOUCH A FINISHED PASS. Draft only.
 *   IT WRITES THE SAME TABLES. Not a parallel store that drifts.
 *   A LINE WITH AN ITEM AND NO QUANTITY IS STILL REFUSED. That is how a
 *   delivery leaves the gate and is never written down.
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

$mg   = (string)file_get_contents($B . 'm_gate.php');
$mgN  = nocomments($mg);
$inv  = (string)file_get_contents($B . 'includes/inventory.php');
$invN = nocomments($inv);
$gate = (string)file_get_contents($B . 'inv_gate.php');
$gateN= nocomments($gate);

/* ============================================== it is the same database */
head('1. The same tables, not a parallel one');

t('m_gate.php exists', $mg !== '');
t('it writes inv_gate',        str_contains($mgN, 'INSERT INTO inv_gate'));
t('and inv_gate_items',        str_contains($mgN, 'INSERT INTO inv_gate_items'));
t('it invents no table of its own',
  !preg_match('~CREATE TABLE~i', $mgN), 'a CREATE TABLE in the phone screen');
t('it uses the same gate number series as the desktop',
  str_contains($mgN, "inv_next_no(\$dir === 'in' ? 'prefix_gate_in' : 'prefix_gate_out', 'inv_gate', 'gate_no')"));
t('it uses the same item key format, so a line is readable by the desktop',
  str_contains($mgN, 'inv_split_key('));
t('lines are rewritten whole, so a line removed on the phone is really gone',
  str_contains($mgN, 'DELETE FROM inv_gate_items WHERE gate_id=?'));
t('the write is in a transaction',
  str_contains($mgN, 'beginTransaction()') && str_contains($mgN, 'rollBack()'));

head('2. It can only ever write a draft');

t('new passes are inserted as draft',   str_contains($mgN, "'draft',?,?)"));
t('the phone has no post action',       !preg_match("~'post'~", $mgN));
t('and no reverse action',              !preg_match("~'reverse'~", $mgN));
t('and no delete action',               !preg_match("~'delete'~", $mgN));
t('a pass that is not a draft cannot be saved from the phone',
  str_contains($mgN, "\$st !== 'draft'"));
t('and cannot even be opened for editing',
  str_contains($mgN, "\$pass['status'] !== 'draft'"));
t('the list only offers drafts',        str_contains($mgN, "g.status='draft'"));

head('3. Permission and CSRF, the same as everywhere else');

t('it needs the gate permission',       str_contains($mgN, "inv_perm('gate')"));
t('and refuses without it',             str_contains($mgN, 'http_response_code(403)'));
t('it does not invent a permission of its own',
  !preg_match("~inv_perm\('(?!gate)~", $mgN));
t('the POST is behind verify_csrf()',   str_contains($mgN, 'verify_csrf()'));
t('the form carries the token',         str_contains($mg, 'csrf_field()'));
t('the menu entry is behind the same permission',
  str_contains(nocomments((string)file_get_contents($B . 'includes/menu.php')), "'m_gate.php?dir=in',        'show' => \$inv('gate')"));

head('4. Only the compulsory fields');

foreach (['txn_type', 'gate_date', 'party_text', 'vehicle_no', 'remarks'] as $f) {
    t("the form has $f", str_contains($mg, 'name="' . $f . '"'), $f);
}
/* Everything the office owns must NOT be here. */
foreach (['rate', 'article', 'lot_no', 'packing', 'contract_id', 'proforma_id',
          'location_id', 'verified_by', 'security_by', 'override_reason'] as $f) {
    t("the phone never asks for $f", !str_contains($mg, 'name="' . $f . '"'), $f);
}
t('no rate is written on a line — it stays at the column default',
  str_contains($mgN, 'uom, rate, ownership') && str_contains($mgN, 'VALUES (?,?,?,?,?,?,0,?,?)'));

head('5. The rate rule moved, and tightened');

/* Saving: qty still required, rate no longer. */
$save = nocomments(lift($gate, "if (\$act === 'save')"));
t('the desktop save still refuses a line with no quantity',
  str_contains($save, "inv_num(\$ln['qty'] ?? 0)  <= 0") && str_contains($save, "\$miss[] = 'quantity'"));
t('the desktop save no longer refuses a line with no rate',
  !preg_match("~\\\$miss\[\] = 'rate'~", $save));
t('a blank line is still just the spare row',
  str_contains($save, 'continue;'));

/* Posting: rate now required. This is the half that did not exist. */
$post = nocomments(lift($inv, 'function inv_gate_post('));
t('inv_gate_post() now checks every line has a rate',
  str_contains($post, "(float)\$it['rate'] <= 0"));
t('it refuses to post when one does not',
  str_contains($post, 'A rate is needed before this can be posted'));
t('it names the lines rather than just saying no',
  str_contains($post, "\$it['description']"));
t('the check runs before any stock is written',
  strpos($post, "rate'] <= 0") < strpos($post, 'inv_stock_write') || !str_contains($post, 'inv_stock_write'));
t('the existing "add at least one item" guard is still there',
  str_contains($post, 'Add at least one item before posting.'));
t('and the already-posted guards are untouched',
  str_contains($post, 'This pass is already posted.') && str_contains($post, 'inv_already_posted('));

head('6. The things a gate screen gets wrong');

t('a future date is refused',
  str_contains($mgN, "\$date > date('Y-m-d')"));
t('the backdating limit is the same setting the desktop uses',
  str_contains($mgN, "inv_setting('backdate_days', '7')"));
t('an item with no quantity is refused, not silently dropped',
  str_contains($mgN, 'has an item but no quantity'));
t('a pass with no items at all is refused',
  str_contains($mgN, "'Add at least one item.'"));
t('a failed save rolls back rather than leaving half a pass',
  str_contains($mgN, 'if (db()->inTransaction()) db()->rollBack()'));
t('the save is logged',  str_contains($mgN, "audit_log(0, 'Gate (mobile)'"));
t('free text is length-capped before it reaches the column',
  substr_count($mgN, 'mb_substr(trim(') >= 6);
t('the drafts list is capped',  str_contains($mgN, 'LIMIT 15'));

head('7. It behaves like a phone app');

$mob = (string)file_get_contents($B . 'includes/mobile.php');
t('includes/mobile.php exists', $mob !== '');
t('it declares a viewport that reaches under the notch',
  str_contains($mob, 'viewport-fit=cover'));
t('and keeps content clear of the system bars',
  str_contains($mob, 'safe-area-inset-top') && str_contains($mob, 'safe-area-inset-bottom'));
t('inputs are 16px so iOS does not zoom when one is tapped',
  str_contains($mob, 'input,select,textarea,button{font:inherit;font-size:16px}'));
t('buttons are at least 50px tall — a thumb is about that wide',
  str_contains($mob, 'min-height:50px'));
/* Read the stripped source: the file's own header explains WHY it does not
   use layout.php, and matching that sentence is matching prose, not code —
   the recurring fault in this suite. */
$mobN = nocomments($mob);
t('it does not draw the desktop sidebar',
  !str_contains($mobN, 'layout.php') && !str_contains($mobN, 'zm-sub'));
t('it says so when the connection is gone',
  str_contains($mob, 'No internet') && str_contains($mob, "'offline'"));
t('and disables saving while offline, since the page must reach the server',
  str_contains($mob, 'body.is-offline .btn.go'));
t('it registers the service worker so it can be installed',
  str_contains($mob, "serviceWorker.register('sw.js')"));
t('no framework and no CDN',
  !preg_match('~<script[^>]+src=~i', $mob) && !preg_match('~<link[^>]+href="https?://~i', $mob));
t('reduced motion is respected',  str_contains($mob, 'prefers-reduced-motion'));

$man = (string)@file_get_contents($B . 'manifest_gate.json');
$mj  = json_decode($man, true);
t('manifest_gate.json is valid JSON', is_array($mj), $man === '' ? 'missing' : substr($man, 0, 60));
if (is_array($mj)) {
    t('it opens on the gate screen',  ($mj['start_url'] ?? '') === 'm_gate.php?dir=in', $mj['start_url'] ?? null);
    t('it installs standalone',       ($mj['display'] ?? '') === 'standalone');
    t('it has its own short name, so it is not confused with Production',
      ($mj['short_name'] ?? '') === 'ZAS Gate' && ($mj['short_name'] ?? '') !== 'ZAS Production');
    t('it offers both directions as shortcuts', count($mj['shortcuts'] ?? []) === 2);
    /* json_encode escapes the slashes, so compare the values themselves. */
    t('it reuses the icons that already exist',
      in_array('assets/icons/icon-192.png', array_column($mj['icons'] ?? [], 'src'), true),
      array_column($mj['icons'] ?? [], 'src'));
}

head('8. Everything it calls is reachable — no stub can hide a missing require');

/* Written after dashboard.php took the site down with a missing require
   that the boot harness hid by stubbing the function. This follows the
   real chain on disk and ignores stubs entirely. */
$seen = [];
$walk = function (string $f) use (&$walk, &$seen) {
    $r = realpath($f);
    if ($r === false || isset($seen[$r])) return;
    $seen[$r] = true;
    $src = (string)@file_get_contents($r);
    if (preg_match_all("~require(?:_once)?\s+__DIR__\s*\.\s*'(/[^']+\.php)'~", $src, $m)) {
        foreach ($m[1] as $rel) $walk(dirname($r) . $rel);
    }
};
$walk($B . 'm_gate.php');
$fns = [];
foreach (array_keys($seen) as $f) {
    if (preg_match_all('~^\s*function\s+([a-zA-Z_][a-zA-Z0-9_]*)\s*\(~m', (string)@file_get_contents($f), $m)) {
        foreach ($m[1] as $fn) $fns[strtolower($fn)] = basename($f);
    }
}
t('the require chain was followed', count($seen) >= 5, count($seen));
foreach (['inv_perm', 'inv_gate_types', 'inv_split_key', 'inv_num', 'inv_next_no',
          'inv_setting', 'inv_opening_items', 'inv_parties',
          'mob_header', 'mob_flash', 'mob_footer',
          'csrf_field', 'verify_csrf', 'redirect', 'require_login', 'is_admin', 'audit_log', 'e'] as $fn) {
    t("m_gate.php can reach $fn()", isset($fns[$fn]),
      isset($fns[$fn]) ? null : 'NOT reachable — this would be a 500 on the live site');
}

head('9. The desktop is unharmed');

t('the desktop gate screen still exists',      $gate !== '');
t('it still has its post action',              str_contains($gateN, "\$act === 'post'"));
t('it still has its reverse action',           str_contains($gateN, "\$act === 'reverse'"));
t('posting still needs its own permission',    str_contains($gateN, '$canPost'));
t('the mobile screen is a separate file, so the desktop form is untouched',
  !str_contains($gateN, 'mobile.php'));

echo "\n$P passed, $F failed\n";
exit($F > 0 ? 1 : 0);
