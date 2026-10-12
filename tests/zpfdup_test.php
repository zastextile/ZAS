<?php
/* DUPLICATE A PROFORMA.
 *
 * "In proforma.php I want to add keep duplicate."
 *
 * A copy that starts as this proforma — customer, terms, banks, packing
 * details, every line — but is a NEW document: its own number, today's
 * date, a draft, not converted, not in production.
 *
 * What this file holds shut, against the source:
 *   THE COPY GETS ITS OWN IDENTITY. Number, date, status, author reset.
 *   IT IS NOT A SHIPMENT AND NOT IN PRODUCTION. The converted link and
 *   every production_* column are left off, and no assignments or rates
 *   are copied — the floor never sees a copy it was not given.
 *   ALL OR NOTHING. Header and lines in one transaction; a failure
 *   creates nothing.
 *   THE SAME PERMISSION AS CREATING ONE, AND CSRF.
 *   THE BUTTON IS NOT HELD BY SAVE'S SIZE CHECK. It copies what is
 *   saved, so it submits a form of its own.
 *
 * The behaviour was also run end to end against a real MariaDB: a
 * confirmed, converted, in-production proforma with two lines became a
 * draft with a new number, no shipment, production off, and the same
 * two lines; the original was unchanged.
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
    foreach (token_get_all($s) as $tk) {
        if (is_array($tk)) {
            if ($tk[0] === T_COMMENT || $tk[0] === T_DOC_COMMENT) { $out .= ' '; continue; }
            $out .= $tk[1];
        } else { $out .= $tk; }
    }
    return (string)preg_replace_callback('~<(script|style)\b[^>]*>.*?</\1>~is', function ($m) {
        return (string)preg_replace(['~/\*.*?\*/~s', '~//[^\n]*~'], ' ', $m[0]);
    }, $out);
}

$src = nocomments((string)file_get_contents($B . 'proforma.php'));

/* The handler, cut out of the raw file between its own heading and the
   delete heading, then stripped of comments. A match across the whole
   file has found the right words in the wrong place before. */
$raw = (string)file_get_contents($B . 'proforma.php');
$a = strpos($raw, '/* ---------- duplicate ----------');
$b = $a === false ? false : strpos($raw, '/* ---------- delete', $a);
$h = ($a !== false && $b !== false) ? nocomments('<?php ' . substr($raw, $a, $b - $a)) : '';

head('1. The handler exists and is guarded');
t('there is a duplicate handler, and it was found', $h !== '');
t('it checks the same permission as creating a proforma',
  str_contains($h, "if (!costing_perm('proforma'))"));
t('and the CSRF token', str_contains($h, 'verify_csrf();'));
t('a proforma that is not there is refused, not copied as nothing',
  str_contains($h, "if (!\$src)"));

head('2. The copy is a new document');
t('its own number from the PI sequence', str_contains($h, "\$newNo = next_doc_no('PI');")
  && str_contains($h, "\$head['pi_no']      = \$newNo;"));
t('today\'s date', str_contains($h, "\$head['pi_date']    = date('Y-m-d');"));
t('a draft', str_contains($h, "\$head['status']     = 'draft';"));
t('made by whoever pressed the button', str_contains($h, "\$head['created_by'] = current_user()['id'];"));
t('not converted to the original\'s shipment, and with fresh timestamps',
  preg_match("~in_array\(\\\$k, \['id', 'created_at', 'updated_at', 'converted_shipment_id'\], true\)~", $h) === 1);
t('not in production — every production_* column is left to its default',
  str_contains($h, "str_starts_with(\$k, 'production_')"));
t('and no production assignments or rates are copied',
  !preg_match('~production_(assignments|order_rates|set_assignments)~', $h));

head('3. Everything else comes with it, all or nothing');
t('every other header column is copied by name, so a field added later comes too',
  str_contains($h, "\"INSERT INTO proforma_invoices (`\" . implode('`,`', \$cols)"));
t('every line, in order, onto the new proforma',
  str_contains($h, "SELECT * FROM proforma_items WHERE proforma_id=? ORDER BY sort_order, id")
  && str_contains($h, "\$row['proforma_id'] = \$newId;") && str_contains($h, "unset(\$row['id']);"));
t('in one transaction', str_contains($h, 'db()->beginTransaction();') && str_contains($h, 'db()->commit();'));
t('and a failure rolls back, so nothing half-made is left',
  str_contains($h, 'if (db()->inTransaction()) db()->rollBack();'));
t('search and AI search learn about the copy as after any save',
  str_contains($h, 'txt_index_proforma($newId)') && str_contains($h, 'proforma_embed_one($newId)'));
t('it opens the copy', str_contains($h, "redirect('proforma.php?id=' . \$newId);"));

head('4. The button');
t('a Duplicate button, shown to whoever may create a proforma',
  /* an empty <?php ?> is left where the comment above the button was */
  preg_match('~<\?php if \(costing_perm\(\x27proforma\x27\)\): \?>\s*(?:<\?php\s*\?>\s*)?<button type="submit" form="pfDupForm"~', $src) === 1);
t('it says the copy is taken from what is saved',
  str_contains($src, 'The copy is taken from what is saved'));
t('it submits its own form, so Save\'s size check cannot hold it',
  preg_match('~<form method="post" id="pfDupForm"[^>]*>\s*<\?= csrf_field\(\) \?><input type="hidden" name="action" value="duplicate">~', $src) === 1);
/* the little form must sit OUTSIDE the save form — a form inside a form
   is not allowed and the browser would drop it */
$save = strpos($src, '<form method="post" id="pfSaveForm"');
$saveEnd = $save === false ? false : strpos($src, '</form>', $save);
$dup = strpos($src, '<form method="post" id="pfDupForm"');
t('and that form is outside the save form, not inside it',
  $save !== false && $saveEnd !== false && $dup !== false && $dup > $saveEnd);

head('5. Delete is unchanged');
t('delete still needs the delete permission and your password',
  str_contains($src, "if (!costing_perm('delete'))")
  && str_contains($src, "password_verify((string)(\$_POST['delete_password'] ?? ''), current_user()['password_hash'])"));

echo "\n" . ($F === 0 ? "ALL PASS   ($P checks)\n" : "FAILED  $F   (" . ($P + $F) . " checks)\n");
exit($F === 0 ? 0 : 1);
