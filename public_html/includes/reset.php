<?php
/* START AGAIN for the Export and Import modules — clear trial data.

   "add reset option ... related to export and import because sometimes we
    started trial data ... so can reset with reason and passwords"

   The same rules as the inventory reset on inv_reset.php, plus a password:
     1. Admin only.
     2. The exact phrase typed (RESET EXPORT / RESET IMPORTS).
     3. A reason, written to the audit log BEFORE a single row goes.
     4. The admin's own login password, typed again. Five wrong tries lock
        the form for 15 minutes.
   Everything in one transaction: all of it goes, or none of it.

   WHAT IS NEVER TOUCHED, whatever is ticked: production entries and wages,
   costings, users and permissions, inventory stock (it has its own reset),
   the audit logs, and settings such as banks, document types and
   numbering patterns.

   REFUSED, NEVER ORPHANED. Where another module still points at what would
   be deleted, the reset refuses and says what to clear first, instead of
   leaving production or stock records pointing at nothing:
     - proformas, while production work, order rates, assembly or inventory
       documents are booked against them;
     - imports, while a POSTED Gate Inward put their goods into stock, or a
       live export allocation uses their lines;
     - service providers, while import logistics name them.
   Links that are only references (a gate pass naming a shipment, a proforma
   naming the shipment it became) are cleared, not deleted. */

const ZR_PHRASES = ['export' => 'RESET EXPORT', 'imports' => 'RESET IMPORTS'];

function zr_has_table(string $t): bool {
    static $have = null;
    if ($have === null) {
        $have = [];
        try { foreach (db()->query("SHOW TABLES")->fetchAll(PDO::FETCH_NUM) as $r) $have[(string)$r[0]] = true; }
        catch (Throwable $e) {}
    }
    return isset($have[$t]);
}
function zr_has_col(string $t, string $c): bool {
    static $cols = [];
    if (!isset($cols[$t])) {
        $cols[$t] = [];
        try { foreach (db()->query("SHOW COLUMNS FROM `$t`")->fetchAll() as $r) $cols[$t][(string)$r['Field']] = true; }
        catch (Throwable $e) {}
    }
    return isset($cols[$t][$c]);
}
function zr_count(string $t, ?string $where = null): int {
    if (!zr_has_table($t)) return 0;
    try { return (int)db()->query("SELECT COUNT(*) FROM `$t`" . ($where ? " WHERE $where" : ''))->fetchColumn(); }
    catch (Throwable $e) { return 0; }
}

/* What each module's reset is made of. A step is [table, WHERE or null];
   they run in this order, children before parents. */
function zr_plan(string $module): array {
    if ($module === 'export') {
        /* everything that points at a proforma from OUTSIDE the export module */
        $proRefs = [];
        foreach (['zp_entries' => 'proforma_id', 'zp_order_rates' => 'proforma_id', 'zp_rate_log' => 'proforma_id',
                  'zp_assembly' => 'proforma_item_id', 'zp_line_set_ops' => 'proforma_item_id',
                  'production_transactions' => 'proforma_id', 'production_assignments' => 'proforma_id', 'production_order_rates' => 'proforma_id',
                  'inv_stock_ledger' => 'proforma_id', 'inv_gate' => 'proforma_id', 'inv_store_move' => 'proforma_id',
                  'inv_consumption' => 'proforma_id', 'inv_consumption_items' => 'proforma_item_id', 'inv_contracts' => 'proforma_id',
                  'inv_allocations' => 'proforma_id', 'inv_order_charges' => 'proforma_id'] as $t => $c)
            if (zr_has_table($t) && zr_has_col($t, $c)) $proRefs[] = [$t, "$c IS NOT NULL AND $c > 0"];
        return [
            'shipments' => [
                'label' => 'Shipments, invoices and everything on them', 'always' => true,
                'blurb' => 'Every shipment with its invoice lines and charges; packing lists and Smart Packing; weight; logistics, containers, payments, costs and documents (with their files); customs and chamber documents; the EFS allocations made on shipments; and the AI search data for shipments. Commercial invoice numbering starts again from 1.',
                'steps' => [
                    ['ai_check_cache', null], ['shipment_embeddings', null], ['exp_text_index', 'shipment_id IS NOT NULL AND shipment_id > 0'],
                    ['exp_doc_lines', null],
                    ['imp_efs_alloc_uses', null], ['imp_efs_alloc_lines', null], ['imp_efs_alloc', null],
                    ['weight_applied', null], ['weight_line_links', null], ['weight_tare', null], ['weight_shipment', null],
                    ['packing_weight_lines', null], ['packing_group_sizes', null], ['packing_palette', null], ['packing_groups', null], ['packing_items', null],
                    ['shipment_container_cartons', null], ['shipment_containers', null], ['shipment_logistics', null],
                    ['shipment_payments', null], ['shipment_costs', null], ['shipment_documents', null], ['shipment_files', null],
                    ['shipment_charges', null], ['shipment_assignments', null], ['shipment_items', null], ['shipments', null],
                ],
                /* references only: kept rows, cleared pointer */
                'unlink' => [['inv_gate', 'shipment_id'], ['proforma_invoices', 'converted_shipment_id']],
                'numbering' => ['INV'],
                'files' => ['shipment_documents', 'shipment_files'],
                'blockers' => [],
            ],
            'proformas' => [
                'label' => 'Proforma invoices',
                'blurb' => 'Proforma invoices (orders) with their lines and AI search data. Proforma numbering starts again from 1. Refused while production work, order rates, assembly or inventory documents are booked against them — those would be left pointing at orders that no longer exist.',
                'steps' => [['proforma_embeddings', null], ['proforma_items', null], ['proforma_invoices', null]],
                'unlink' => [], 'numbering' => ['PI'], 'files' => [],
                'blockers' => $proRefs,
            ],
            'weight' => [
                'label' => 'Weight profiles',
                'blurb' => 'The weight profiles themselves (grams per finished unit) and the old standard weights. Leave this off to keep your profiles for the next real shipment.',
                'steps' => [['weight_profile_materials', null], ['weight_profiles', null], ['packing_weight_std', null]],
                'unlink' => [], 'numbering' => [], 'files' => [], 'blockers' => [],
            ],
            'providers' => [
                'label' => 'Service providers',
                'blurb' => 'Freight forwarders, shipping lines, transporters, clearing agents and the rest. Banks, document types and cost types are settings, not trial data, and are kept.',
                'steps' => [['exp_provider_roles', null], ['exp_providers', null]],
                'unlink' => [], 'numbering' => [], 'files' => [],
                'blockers' => zr_has_table('imp_logistics')
                    ? [['imp_logistics', '(forwarder_id IS NOT NULL AND forwarder_id > 0) OR (shipping_line_id IS NOT NULL AND shipping_line_id > 0)']] : [],
            ],
        ];
    }
    if ($module === 'imports') {
        return [
            'imports' => [
                'label' => 'Imports and everything on them', 'always' => true,
                'blurb' => 'Every import with its lines, invoices, packing, containers, logistics, payments, costs, documents (with their files), customs lines, receipts and EFS records. Gate passes made from imports are kept but no longer linked. Import references start again from 0001. The import history log is kept.',
                'steps' => [
                    ['imp_efs_alloc_uses', "target_kind='line'"], ['imp_efs_reductions', "target_kind='line'"],
                    ['imp_receipt_lines', null], ['imp_receipts', null], ['imp_gd_lines', null], ['imp_documents', null],
                    ['imp_lines', null], ['imp_invoices', null], ['imp_packing', null], ['imp_containers', null],
                    ['imp_logistics', null], ['imp_payments', null], ['imp_costs', null], ['imp_imports', null],
                ],
                'unlink' => [['inv_gate_items', 'imp_line_id'], ['inv_gate', 'import_id']],
                'numbering' => [], 'files' => ['imp_documents'],
                'blockers' => [
                    ['inv_gate', "import_id IS NOT NULL AND status='posted'", 'posted Gate Inward pass(es) put import goods into stock — reverse them on Gate Inward, or run the Inventory reset first'],
                    ['imp_efs_alloc_uses', "target_kind='line' AND alloc_id IN (SELECT id FROM imp_efs_alloc WHERE status <> 'reversed')", 'export allocation line(s) still use import lines — run the Export reset first, or reverse those allocations'],
                ],
            ],
            'opening' => [
                'label' => 'EFS opening balances',
                'blurb' => 'The EFS balances carried in from before this system. They are unlocked again so they can be re-entered.',
                'steps' => [['imp_efs_alloc_uses', "target_kind='opening'"], ['imp_efs_reductions', "target_kind='opening'"], ['imp_opening_efs', null]],
                'unlink' => [], 'numbering' => [], 'files' => [],
                'blockers' => [
                    ['imp_efs_alloc_uses', "target_kind='opening' AND alloc_id IN (SELECT id FROM imp_efs_alloc WHERE status <> 'reversed')", 'export allocation line(s) still use opening balances — run the Export reset first, or reverse those allocations'],
                ],
            ],
        ];
    }
    return [];
}

/* What a table is called on the screens, for the refusal message. */
function zr_name(string $t): string {
    return [
        'zp_entries' => 'production work entries', 'zp_order_rates' => 'order rate amendments', 'zp_rate_log' => 'rate changes',
        'zp_assembly' => 'assembly (make sets) entries', 'zp_line_set_ops' => 'set operation lines',
        'production_transactions' => 'old production entries', 'production_assignments' => 'old production assignments',
        'production_order_rates' => 'old order rates', 'inv_stock_ledger' => 'stock ledger rows', 'inv_gate' => 'gate passes',
        'inv_store_move' => 'store issues / returns', 'inv_consumption' => 'consumptions', 'inv_consumption_items' => 'consumption lines',
        'inv_contracts' => 'contracts', 'inv_allocations' => 'stock reservations', 'inv_order_charges' => 'order charges',
        'imp_logistics' => 'import logistics rows name these providers — run the Imports reset first',
    ][$t] ?? $t;
}

/* Live counts and any blockers, for the page to show before anyone types. */
function zr_preview(string $module): array {
    $out = [];
    foreach (zr_plan($module) as $k => $g) {
        $rows = [];
        foreach ($g['steps'] as [$t, $w]) if (zr_has_table($t)) $rows[] = ['table' => $t, 'n' => zr_count($t, $w)];
        $blocks = [];
        foreach ($g['blockers'] as $b) {
            [$t, $w] = $b;
            $n = zr_count($t, $w);
            if ($n > 0) $blocks[] = $n . ' ' . ($b[2] ?? (zr_name($t) . (str_contains(zr_name($t), '—') ? '' : ' booked against them')));
        }
        $out[$k] = $g + ['rows' => $rows, 'total' => array_sum(array_column($rows, 'n')), 'blocked' => $blocks];
    }
    return $out;
}

/* The password check, with a lock after five wrong tries. */
function zr_password_ok(string $pw): array {
    $lock = $_SESSION['zr_pw_lock'] ?? ['n' => 0, 'until' => 0];
    if ((int)$lock['until'] > time()) return [false, 'Too many wrong passwords. Try again after ' . date('H:i', (int)$lock['until']) . '.'];
    $me = current_user();
    if ($pw !== '' && password_verify($pw, (string)($me['password_hash'] ?? ''))) { unset($_SESSION['zr_pw_lock']); return [true, '']; }
    $lock['n'] = (int)$lock['n'] + 1;
    if ($lock['n'] >= 5) { $lock = ['n' => 0, 'until' => time() + 900]; }
    $_SESSION['zr_pw_lock'] = $lock;
    return [false, 'That is not your login password. Nothing was deleted.' . ($lock['until'] ? ' The form is locked for 15 minutes.' : '')];
}

function zr_run(string $module, array $picked, string $reason, string $phrase, string $password): array {
    if (!is_admin()) return ['ok' => false, 'error' => 'Only an administrator can reset data.'];
    $plan = zr_plan($module);
    if (!$plan) return ['ok' => false, 'error' => 'Unknown module.'];
    $want = ZR_PHRASES[$module];
    if (trim($phrase) !== $want) return ['ok' => false, 'error' => 'The phrase did not match. Type ' . $want . ' exactly — capitals and the space included.'];
    $reason = trim($reason);
    if (mb_strlen($reason) < 5) return ['ok' => false, 'error' => 'Write a reason of at least 5 characters. It is written to the audit log before anything is deleted.'];
    [$pwOk, $pwErr] = zr_password_ok($password);
    if (!$pwOk) return ['ok' => false, 'error' => $pwErr];

    $pick = [];
    foreach ($plan as $k => $g) if (!empty($g['always']) || in_array($k, $picked, true)) $pick[$k] = $g;
    $prev = zr_preview($module);
    $blocked = [];
    foreach ($pick as $k => $_) foreach ($prev[$k]['blocked'] as $b) $blocked[] = $plan[$k]['label'] . ': ' . $b;
    if ($blocked) return ['ok' => false, 'error' => 'Nothing was deleted. ' . implode(' · ', $blocked) . '.'];

    $planned = [];
    foreach ($pick as $k => $_) foreach ($prev[$k]['rows'] as $r) $planned[$r['table']] = ($planned[$r['table']] ?? 0) + $r['n'];
    /* the record goes in FIRST, so it survives whatever follows */
    audit_log(0, 'Reset', $module . '_reset_start', json_encode(array_keys($pick)), json_encode($planned), $reason);

    /* files are read before their rows go, and removed after the commit */
    $files = [];
    foreach ($pick as $g) foreach ($g['files'] as $ft) {
        if (!zr_has_table($ft)) continue;
        try { foreach (db()->query("SELECT * FROM `$ft`")->fetchAll() as $f) $files[] = $f; } catch (Throwable $e) {}
    }

    $deleted = []; $emptied = [];
    try {
        db()->beginTransaction();
        foreach ($pick as $g) {
            foreach ($g['unlink'] as [$t, $c]) if (zr_has_table($t) && zr_has_col($t, $c)) db()->exec("UPDATE `$t` SET `$c` = NULL WHERE `$c` IS NOT NULL");
            foreach ($g['steps'] as [$t, $w]) {
                if (!zr_has_table($t)) continue;
                $n = db()->exec("DELETE FROM `$t`" . ($w ? " WHERE $w" : ''));
                $deleted[$t] = ($deleted[$t] ?? 0) + (int)$n;
                if ($w === null) $emptied[$t] = true;
            }
            foreach ($g['numbering'] as $kind)
                if (zr_has_table('exp_numbering')) db()->prepare("UPDATE exp_numbering SET next_no = 1, cycle_key = '' WHERE doc_kind = ?")->execute([$kind]);
        }
        db()->commit();
    } catch (Throwable $e) {
        if (db()->inTransaction()) db()->rollBack();
        return ['ok' => false, 'error' => 'Nothing was deleted — the database refused: ' . $e->getMessage()];
    }
    /* AFTER the commit: ALTER TABLE would commit an open transaction by itself */
    foreach (array_keys($emptied) as $t) { try { db()->exec("ALTER TABLE `$t` AUTO_INCREMENT = 1"); } catch (Throwable $e) {} }
    if (!empty($pick['opening']) && function_exists('imp_set_setting')) {
        try { imp_set_setting('opening_locked_at', ''); imp_set_setting('opening_locked_by', ''); } catch (Throwable $e) {}
    }

    global $config;
    $dir = rtrim((string)($config['upload_dir'] ?? (__DIR__ . '/../storage/uploads')), '/');
    $left = 0;
    foreach ($files as $f) {
        if (isset($f['storage_driver'])) {
            if ((string)$f['storage_driver'] === 'r2') {
                if (function_exists('exp_r2_delete')) { [$ok] = exp_r2_delete((string)$f['storage_key']); if (!$ok) $left++; } else $left++;
                continue;
            }
            $path = $dir . '/' . basename((string)$f['storage_key']);
        } else {
            $path = $dir . '/' . basename((string)($f['stored_name'] ?? ''));
        }
        if (basename($path) !== '' && is_file($path) && !@unlink($path)) $left++;
    }
    audit_log(0, 'Reset', $module . '_reset_done', json_encode(array_keys($pick)), json_encode($deleted), $reason);
    return ['ok' => true, 'error' => '', 'deleted' => $deleted, 'picked' => array_keys($pick), 'files' => count($files), 'files_left' => $left];
}
