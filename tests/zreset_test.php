<?php
/* START AGAIN FOR EXPORT AND IMPORTS — trial data cleared, nothing orphaned.
 *
 * "add reset option ... related to export and import because sometimes we
 *  started trial data ... so can reset with reason and passwords"
 *
 * Driven in a browser against MariaDB with trial shipments, packing, files,
 * an EFS allocation, a proforma booked by production, an import with a
 * posted Gate Inward and EFS opening balances: the proforma and imports
 * resets refused with counts; wrong password / phrase / reason refused with
 * nothing deleted; the export reset cleared shipments, files and allocations,
 * kept the gate pass (unlinked) and the proforma, and restarted invoice
 * numbering; the imports reset (gate reversed) cleared imports, files and
 * opening balances and unlocked them; proformas cleared once production was
 * gone; five wrong passwords locked the form. This file holds the rules shut.
 */

$B = __DIR__ . '/app_src/public_html/';
$P = 0; $F = 0;
function t(string $n, bool $c): void { global $P, $F; if ($c) $P++; else { $F++; echo "  FAIL  $n\n"; } }
function nocomments(string $s): string {
    $out = '';
    foreach (token_get_all($s) as $t) $out .= is_array($t) ? (($t[0] === T_COMMENT || $t[0] === T_DOC_COMMENT) ? ' ' : $t[1]) : $t;
    return $out;
}
$r  = nocomments((string)file_get_contents($B . 'includes/reset.php'));
$pg = nocomments((string)file_get_contents($B . 'inv_reset.php'));
$v  = nocomments((string)file_get_contents($B . 'includes/reset_view.php'));

echo "\n1. The four locks\n";
t('admin only', str_contains($r, "if (!is_admin()) return ['ok' => false"));
t('the exact phrase', str_contains($r, "'export' => 'RESET EXPORT', 'imports' => 'RESET IMPORTS'") && str_contains($r, 'if (trim($phrase) !== $want)'));
t('a reason of 5 or more', str_contains($r, 'if (mb_strlen($reason) < 5)'));
t('the login password, checked against the stored hash', str_contains($r, "password_verify(\$pw, (string)(\$me['password_hash'] ?? ''))"));
t('  and a wrong one stops the reset, before anything is deleted',
  str_contains($r, 'if (!$pwOk) return [\'ok\' => false, \'error\' => $pwErr];')
  && strpos($r, 'if (!$pwOk) return') < strpos($r, 'db()->beginTransaction();'));
t('  five wrong tries lock it for 15 minutes', str_contains($r, "if (\$lock['n'] >= 5) { \$lock = ['n' => 0, 'until' => time() + 900]; }"));
t('the inventory reset asks for the password too', str_contains($pg, "elseif (!(\$pw = zr_password_ok((string)(\$_POST['password'] ?? '')))[0])"));
t('the reason is logged BEFORE anything is deleted',
  strpos($r, "_reset_start'") !== false && strpos($r, "_reset_start'") < strpos($r, 'db()->beginTransaction();'));
t('all or nothing', str_contains($r, 'db()->rollBack();') && str_contains($r, "'Nothing was deleted — the database refused: '"));
t('ids restart only AFTER the commit', strpos($r, 'AUTO_INCREMENT = 1') > strpos($r, 'db()->commit();'));
t('CSRF on the post', str_contains($pg, "if (\$_SERVER['REQUEST_METHOD'] === 'POST' && \$mod !== 'inventory') {\n    verify_csrf();"));

echo "\n2. Refused, never orphaned\n";
foreach (['zp_entries', 'zp_order_rates', 'zp_assembly', 'inv_stock_ledger', 'inv_gate', 'inv_consumption_items', 'inv_contracts'] as $t)
    t("proformas wait while $t points at them", str_contains($r, "'$t' => '"));
t('imports wait while a POSTED Gate Inward put their goods into stock', str_contains($r, "\"import_id IS NOT NULL AND status='posted'\""));
t('imports wait while a live export allocation uses their lines',
  str_contains($r, "\"target_kind='line' AND alloc_id IN (SELECT id FROM imp_efs_alloc WHERE status <> 'reversed')\""));
t('opening balances wait the same way', str_contains($r, "\"target_kind='opening' AND alloc_id IN (SELECT id FROM imp_efs_alloc WHERE status <> 'reversed')\""));
t('service providers wait while import logistics name them', str_contains($r, "['imp_logistics', '(forwarder_id IS NOT NULL"));
t('a blocked group cannot even be ticked', str_contains($v, "<?= \$blocked ? 'disabled' : '' ?>"));

echo "\n3. What goes, what is kept\n";
foreach (['shipments', 'shipment_items', 'packing_items', 'packing_groups', 'shipment_documents', 'shipment_files', 'shipment_logistics',
          'shipment_payments', 'shipment_costs', 'exp_doc_lines', 'weight_applied', 'imp_efs_alloc'] as $t)
    t("export clears $t", str_contains($r, "['$t', null]"));
foreach (['imp_imports', 'imp_lines', 'imp_documents', 'imp_gd_lines', 'imp_receipts', 'imp_payments', 'imp_costs'] as $t)
    t("imports clear $t", str_contains($r, "['$t', null]"));
t('gate passes are kept, only unlinked', str_contains($r, "['inv_gate', 'shipment_id']") && str_contains($r, "['inv_gate', 'import_id']"));
t('a proforma kept loses only its pointer to the shipment', str_contains($r, "['proforma_invoices', 'converted_shipment_id']"));
t('invoice numbering restarts; proforma numbering only with proformas',
  str_contains($r, "'numbering' => ['INV']") && str_contains($r, "'numbering' => ['PI']"));
t('uploaded files go too, from local disk or R2', str_contains($r, "'files' => ['shipment_documents', 'shipment_files']") && str_contains($r, 'exp_r2_delete('));
t('opening balances are unlocked when cleared', str_contains($r, "imp_set_setting('opening_locked_at', '');"));
t('the audit tables are never in a delete', !preg_match("/\\['(audit_logs|imp_audit)', /", $r));
t('nor users, costings or production', !preg_match("/\\['(users|costing_versions|costing_lines|zp_entries|products)', null\\]/", $r));

echo "\n4. The page\n";
t('three tabs: Inventory, Export, Imports', str_contains($pg, "['inventory' => 'Inventory (stock)', 'export' => 'Export (shipments)', 'imports' => 'Imports']"));
t('live counts and the reason it cannot run yet are shown before anyone types', str_contains($v, 'Cannot be cleared yet:') && str_contains($v, "number_format(\$g['total'])"));
t('the menu says what the page is now for', str_contains((string)file_get_contents($B . 'includes/menu.php'), "'Start Again (clear trial data)'"));

echo "\n" . str_repeat('-', 58) . "\n" . ($F === 0 ? 'ALL PASS' : 'FAILURES') . "   $P passed, $F failed\n";
exit($F === 0 ? 0 : 1);
