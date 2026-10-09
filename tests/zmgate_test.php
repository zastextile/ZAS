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
    /* THE NAIVE STRIPPER ATE REAL CODE.
       It used one regex for block comments and one for line comments.
       But a phone's file input carries accept="image" followed by a
       slash and a star, and that opens a block comment which never
       closes — so the regex paired it with the next genuine closer
       forty lines later and swallowed everything between. Across this
       app that is twenty-four files, thirty-one thousand bytes in the
       worst of them, and the damage is invisible: a str_contains goes
       false and somebody notices, but a !str_contains goes true and the
       assertion passes for nothing at all.

       PHP's own tokenizer knows a comment from inline HTML, so an
       attribute value cannot fool it. JavaScript comments are stripped
       afterwards, inside script blocks only, because those mislead a
       test exactly the same way. */
    $out = '';
    foreach (token_get_all($s) as $t) {
        if (is_array($t)) {
            if ($t[0] === T_COMMENT || $t[0] === T_DOC_COMMENT) { $out .= ' '; continue; }
            $out .= $t[1];
        } else {
            $out .= $t;
        }
    }
    /* script AND style: a CSS comment misleads a test exactly as a
       JavaScript one does — these very tests were matching the words
       "translateX" and "swipe" inside a comment that says the code
       must never use them. */
    return (string)preg_replace_callback('~<(script|style)\b[^>]*>.*?</\1>~is', function ($m) {
        return (string)preg_replace(['~/\*.*?\*/~s', '~//[^\n]*~'], ' ', $m[0]);
    }, $out);
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
/* contract_id is no longer on this list: the phone now offers the contract
   deliberately, because a line without one counts against nothing. It is a
   hidden field set by tapping a contract, and the server re-checks it. */
foreach (['article', 'lot_no', 'packing', 'proforma_id',
          'location_id', 'verified_by', 'security_by', 'override_reason'] as $f) {
    t("the phone never asks for $f", !str_contains($mg, 'name="' . $f . '"'), $f);
}
t('the contract is carried, but as a hidden field the server re-checks',
  str_contains($mg, 'name="contract_id"') && str_contains($mg, 'type="hidden"'));
t('a rate is still never typed by hand',
  !preg_match('~name="line\[[^]]*\]\[rate\]"~', $mg));
/* The rule is no longer "always zero" but "only ever from a contract".
   A rate the gatekeeper could type is still the thing being prevented. */
t('a rate reaches a line only when a contract supplied it',
  str_contains($mgN, "\$cItem > 0 && \$cRate > 0 ? \$cRate : 0.0"));
t('and the posted rate value is never read straight from the form',
  !preg_match("~inv_num\(\\\$ln\['rate'\]~", $mgN));

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

head('7b. The photo taken at the gate');

$mobN2 = nocomments($mob);
t('the form can carry a file at all',  str_contains($mg, 'enctype="multipart/form-data"'));
t('the camera opens straight to the back lens on a phone',
  str_contains($mg, 'capture="environment"'));
t('and the same control is a file picker on a desktop',
  str_contains($mg, 'type="file"') && str_contains($mg, 'accept="image/*"'));
t('photos have their own table, not exp_documents, which is keyed to a shipment',
  str_contains(nocomments($inv), 'CREATE TABLE IF NOT EXISTS inv_gate_photos'));
t('each row records where its own bytes live',
  str_contains(nocomments($inv), "storage_driver VARCHAR(20) NOT NULL DEFAULT 'local'"));

/* A file arriving from a phone someone else is holding. */
t('the bytes are checked, not the filename',   str_contains($mobN2, 'getimagesize($tmp)'));
t('only real image types are accepted',
  str_contains($mobN2, "['image/jpeg', 'image/png', 'image/webp']"));
t('an upload that is not really an upload is refused',
  str_contains($mobN2, 'is_uploaded_file($tmp)'));
t('a size ceiling is enforced',                str_contains($mobN2, 'MOBPHOTO_MAX'));
t('the count is capped, so one tap cannot post forty',
  str_contains($mobN2, 'MOBPHOTO_MAX_COUNT'));
t('nothing from the phone filename reaches the stored path',
  str_contains($mobN2, 'bin2hex(random_bytes(16))'));
t('the key is unguessable rather than sequential',
  !preg_match('~\$key\s*=\s*.gate/.\s*\.\s*\$gateId\s*\.\s*.\.(jpg|png)~', $mobN2));
t('R2 is used when configured, disk when not',
  str_contains($mobN2, 'exp_r2_configured()') && str_contains($mobN2, 'move_uploaded_file'));
t('and m_gate.php actually loads storage.php, or every photo would land on disk',
  str_contains($mgN, "includes/storage.php"));

t('a photo is stored only AFTER the pass is committed',
  strpos($mgN, 'db()->commit();') < strpos($mgN, 'mob_photo_store('));
t('a photo that fails does not lose the gate pass',
  str_contains($mgN, 'The pass was saved, but the photo was not'));

/* No public URL — the same rule the shipment documents follow. */
$pv = (string)file_get_contents($B . 'm_gate_photo.php');
t('photos are served by a page, never a public link', $pv !== '');
t('which checks permission first',     str_contains($pv, "inv_perm('view')"));
t('and refuses without it',            str_contains($pv, 'http_response_code(403)'));
t('the id is read as an integer',      str_contains($pv, "(int)(\$_GET['id'] ?? 0)"));
t('the photo must belong to a real gate pass',
  str_contains($pv, 'JOIN inv_gate g ON g.id = p.gate_id'));
t('the browser is told not to sniff the type',
  str_contains(nocomments($mob), 'X-Content-Type-Options: nosniff'));
t('an unexpected mime is not echoed back as a content type',
  str_contains($mobN2, "in_array(\$mime, ['image/jpeg','image/png','image/webp'], true) ? \$mime : 'image/jpeg'"));

t('the office sees them on the desktop pass, where it actually looks',
  str_contains($gateN, 'mob_photos((int)$doc[\'id\'])'));
t('and inv_gate.php really loads the file that defines it',
  str_contains($gateN, "includes/mobile.php"));
t('the drafts list shows how many are attached',
  str_contains($mgN, 'FROM inv_gate_photos ph WHERE ph.gate_id=g.id'));

head('7c. Against a contract');

/* WHY THIS SECTION IS THE MOST IMPORTANT ONE IN THE FILE.
 *
 * inv_contract_lines() measures progress by summing gate lines whose
 * contract_item_id is set:
 *
 *   WHERE (gi.contract_id = ? OR (gi.contract_id IS NULL AND g.contract_id = ?))
 *     AND g.status = 'posted' AND gi.contract_item_id IS NOT NULL
 *
 * A line without a contract_item_id counts against NOTHING. The contract
 * reads as undelivered for ever, and you would go on delivering against it
 * with the screen saying there was balance left. Before this change every
 * line the phone wrote was such a line. */
t('the counting rule is still the one described above',
  str_contains(nocomments($inv), 'gi.contract_item_id IS NOT NULL'));

t('a line taken from a contract carries the contract item id',
  str_contains($mgN, "'citem'       => \$cItem > 0 ? \$cItem : null"));
t('and the contract id, so the OR in that query matches either way',
  str_contains($mgN, "\$l['citem'] ? \$cid : null, \$l['citem']"));
t('the insert really writes both columns',
  str_contains($mgN, 'contract_id, contract_item_id'));
t('the header records the contract too',
  str_contains($mgN, 'contract_id=?') && str_contains($mgN, 'remarks, contract_id, status'));

/* The rate comes from the contract, which is what lets a contract-built
   pass post without the office touching it. */
t('the contract rate is carried onto the line',
  str_contains($mgN, "'rate'        => \$cItem > 0 && \$cRate > 0 ? \$cRate : 0.0"));
t('and the amount is computed from it rather than left at zero',
  str_contains($mgN, "round(\$l['qty'] * \$l['rate'], 2)"));
t('a line NOT from a contract still has no rate — the office fills it',
  str_contains($mgN, '? $cRate : 0.0'));

/* The contract id arrived from a browser, so it is checked. */
t('the contract must exist',           str_contains($mgN, 'SELECT contract_type, status FROM inv_contracts WHERE id=?'));
t('it must match the type being raised',
  str_contains($mgN, "(string)\$crow['contract_type'] !== \$want"));
t('it must still be open',             str_contains($mgN, "['active', 'draft'], true)"));
t('and a contract that fails any of those is dropped, not trusted',
  str_contains($mgN, '$cid = 0;'));

/* Only lines still outstanding are offered. */
t('a fully delivered contract line is not offered at the gate',
  str_contains($mgN, "(float)\$l['balance'] <= 0) continue"));
t('the contract list is limited to open contracts',
  str_contains($mgN, "c.status IN ('active','draft')"));
t('and capped',                        str_contains($mgN, 'LIMIT 120'));

head('7d. Finding an item, and remembering what this party brings');

t('there is a search box, not a 900-row dropdown',
  str_contains($mg, 'id="psearch"'));
t('the picker is its own screen',      str_contains($mg, 'id="pick"'));
t('hidden really hides it — an inline display: would otherwise win',
  str_contains(nocomments($mob), '[hidden]{display:none!important}'));
t('three ways of matching are implemented',
  str_contains($mg, 'function score(') && str_contains($mg, 'function subseq('));
t('search ignores case and punctuation',
  str_contains($mg, "replace(/[^a-z0-9]+/g, ' ')"));
t('the results are capped so a blank search cannot paint 900 rows',
  str_contains($mg, '.slice(0, 80)'));

t('what a party brought before is read from what was recorded',
  str_contains($mgN, 'FROM inv_gate_items gi') && str_contains($mgN, 'g.party_text = ?'));
t('ordered by how often, then how recently',
  str_contains($mgN, 'ORDER BY n DESC, last_id DESC'));
t('scoped to this direction, so an outward list is not shown on an inward pass',
  str_contains($mgN, 'g.direction = ?'));
t('and capped',                        str_contains($mgN, 'LIMIT 12'));
t('it is fetched once per party, not on every keystroke',
  str_contains($mg, 'party === recentFor'));
/* Two JSON endpoints now — the read and the standard-list write. What
   matters is that each declares JSON and stops, rather than falling
   through and rendering a phone page into a fetch(). */
$jsonHdrs = substr_count($mgN, "header('Content-Type: application/json')");
t('every ajax path declares JSON', $jsonHdrs >= 2, $jsonHdrs);
t('and each one ends in exit, so none falls through into the page',
  substr_count($mgN, 'exit;') >= $jsonHdrs + 2);
t('the ajax is behind the same login and permission as the page',
  strpos($mgN, "inv_perm('gate')") < strpos($mgN, "\$_GET['ajax']"));

head('7e. A fixed standard list per party');

$invN3 = nocomments($inv);
t('the list has its own table',
  str_contains($invN3, 'CREATE TABLE IF NOT EXISTS inv_party_items'));
t('it is per direction, so an outward list is not offered on an inward pass',
  str_contains($invN3, "direction ENUM('in','out') NOT NULL"));

/* The link must survive a party being renamed, and must never attach to
   the wrong party. */
t('it keys on the party id when the name resolves to exactly one party',
  str_contains(nocomments(lift($inv, 'function inv_party_id_for(')), 'count($rows) === 1'));
t('two parties sharing a name resolve to nothing rather than to a guess',
  str_contains(nocomments(lift($inv, 'function inv_party_id_for(')), 'LIMIT 2'));
t('and the normalised name is always stored as the fallback',
  str_contains($invN3, 'party_key VARCHAR(190) NOT NULL'));
t('the name is normalised for case and spacing',
  str_contains(nocomments(lift($inv, 'function inv_party_key(')), "preg_replace('/\\s+/', ' '"));

$sv = nocomments(lift($inv, 'function inv_party_standard_save('));
t('saving REPLACES the list rather than adding to it',
  str_contains($sv, 'DELETE FROM inv_party_items'));
t('it is one transaction, so a half-written list cannot survive',
  str_contains($sv, 'beginTransaction()') && str_contains($sv, 'rollBack()'));
t('the number of items is capped',   str_contains($sv, 'count($items) > 40'));
t('a blank party name is refused',   str_contains($sv, "Type the party name first"));
t('an unknown direction is refused', str_contains($sv, "in_array(\$dir, ['in', 'out'], true)"));
t('clearing the list is possible and says so',
  str_contains($sv, 'was cleared'));

$rd = nocomments(lift($inv, 'function inv_party_standard('));
t('reading matches on either the id or the name',
  str_contains($rd, 'party_id=? OR party_key=?'));
t('and the same item cannot come back twice from that OR',
  str_contains($rd, 'isset($seen[$k])'));

t('the phone can save the list without submitting the pass',
  str_contains($mgN, "'savestd'") && str_contains($mg, 'id="savestd"'));
t('that action is behind CSRF like every other write',
  strpos($mgN, "=== 'savestd'") < strpos($mgN, 'inv_party_standard_save(')
  && str_contains($mgN, 'verify_csrf()'));
t('it answers JSON and stops, so the page is not re-rendered',
  str_contains($mgN, "echo json_encode(['ok' => \$ok, 'msg' => \$msg]);"));
t('both lists come back in one request — the gate has the worst signal',
  str_contains($mgN, "'std' => inv_party_standard("));
t('the picker shows the standard list',   str_contains($mg, 'Standard list for this party'));
t('and what was actually brought before', str_contains($mg, 'Brought before with this party'));
t('with one tap to add the whole standard list',
  str_contains($mg, "id=\"addall\"") && str_contains($mg, "closest('#addall')"));
t('adding fills the empty first row rather than stranding it',
  substr_count($mg, "return !r.querySelector('.k').value;") >= 2);

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
/* The desktop now loads mobile.php on purpose, for mob_photos() — the
   photographs belong on the screen the office looks at. What must NOT
   happen is the desktop being drawn with the phone shell. */
t('the desktop still draws itself with its own layout, not the phone shell',
  !str_contains($gateN, 'mob_header(') && !str_contains($gateN, 'mob_footer('));
/* Every mob_ in the desktop file must be mob_photos — counted without the
   bracket, because one use is function_exists('mob_photos'). */
t('it uses mobile.php only to read the photos',
  substr_count($gateN, 'mob_') === substr_count($gateN, 'mob_photos')
  && substr_count($gateN, 'mob_photos') > 0,
  substr_count($gateN, 'mob_') . ' mob_ vs ' . substr_count($gateN, 'mob_photos') . ' mob_photos');
t('the phone screen is still a separate file',
  is_file($B . 'm_gate.php') && !str_contains($gateN, 'm_gate.php?dir='));

echo "\n$P passed, $F failed\n";
exit($F > 0 ? 1 : 0);
