<?php
require_once __DIR__ . '/includes/bootstrap.php';
require_login();
verify_csrf();


function zas_ensure_reopen_columns_v26(): void {
    try { db()->exec("ALTER TABLE shipments ADD COLUMN reopen_status VARCHAR(40) NULL AFTER status"); } catch (Throwable $e) {}
    try { db()->exec("ALTER TABLE shipments ADD COLUMN reopen_reason TEXT NULL AFTER reopen_status"); } catch (Throwable $e) {}
    try { db()->exec("ALTER TABLE shipments ADD COLUMN reopened_by INT NULL AFTER reopen_reason"); } catch (Throwable $e) {}
    try { db()->exec("ALTER TABLE shipments ADD COLUMN reopened_at DATETIME NULL AFTER reopened_by"); } catch (Throwable $e) {}
}

zas_ensure_reopen_columns_v26();

$id = (int)($_POST['shipment_id'] ?? 0);
$stmt = db()->prepare("SELECT * FROM shipments WHERE id=?");
$stmt->execute([$id]);
$shipment = $stmt->fetch();

if (!$shipment || !can_view_shipment($id)) {
    http_response_code(404);
    exit('Shipment not found.');
}

$locked = $shipment['status'] === 'approved_locked';
$reason = trim($_POST['amendment_reason'] ?? '');

if ($locked && !is_admin()) {
    http_response_code(403);
    exit('Locked record. Only Admin can amend.');
}
if ($locked && is_admin() && $reason === '') {
    $_SESSION['error'] = 'Admin amendment reason is required for locked record.';
    redirect('shipment_view.php?id=' . $id);
}

$editInvoice = can_edit_invoice($shipment);
$editPacking = can_edit_packing($shipment);
$saveAndSubmit = isset($_POST['save_and_submit']) && !is_staff() && $editInvoice && ($shipment['status'] !== 'approved_locked');
$showRates = can_see_rates();

try {
    db()->beginTransaction();

    $oldSnapshot = [
        'shipment' => $shipment,
        'items' => db()->query("SELECT * FROM shipment_items WHERE shipment_id=".(int)$id." ORDER BY line_no,id")->fetchAll(),
        'charges' => db()->query("SELECT * FROM shipment_charges WHERE shipment_id=".(int)$id." ORDER BY id")->fetchAll(),
        'packing' => db()->query("SELECT * FROM packing_items WHERE shipment_id=".(int)$id." ORDER BY line_no,id")->fetchAll(),
    ];

    if ($editInvoice && !is_staff()) {
        $optEnabled = isset($_POST['optional_column_enabled']) ? 1 : 0;
        $optTitle = $optEnabled ? trim($_POST['optional_column_title'] ?? '') : null;
        $stmt = db()->prepare("UPDATE shipments SET invoice_no=?, invoice_date=?, buyer_name=?, buyer_address=?, buyer_country=?, destination_port=?, po_no=?, bl_container_no=?, currency=?, payment_terms=?, incoterm=?, optional_column_enabled=?, optional_column_title=?, updated_by=?, updated_at=NOW() WHERE id=?");
        $stmt->execute([
            trim($_POST['invoice_no'] ?? $shipment['invoice_no']),
            $_POST['invoice_date'] ?: null,
            trim($_POST['buyer_name'] ?? $shipment['buyer_name']),
            trim($_POST['buyer_address'] ?? $shipment['buyer_address']),
            trim($_POST['buyer_country'] ?? $shipment['buyer_country']),
            trim($_POST['destination_port'] ?? $shipment['destination_port']),
            trim($_POST['po_no'] ?? $shipment['po_no']),
            trim($_POST['bl_container_no'] ?? $shipment['bl_container_no']),
            trim($_POST['currency'] ?? $shipment['currency']),
            trim($_POST['payment_terms'] ?? $shipment['payment_terms']),
            trim($_POST['incoterm'] ?? $shipment['incoterm'] ?? ''),
            $optEnabled,
            $optTitle,
            current_user()['id'],
            $id
        ]);

        db()->prepare("DELETE FROM shipment_items WHERE shipment_id=?")->execute([$id]);
        $products = post_array('product_name');
        $qtys = post_array('qty');
        $rates = post_array('rate');
        /* product_id is the line's link to the Product Master. 0 or
           missing is stored as NULL, which is exactly how every line
           saved before this existed behaves — production falls back to
           matching the name, as it always did. */
        $pids = post_array('product_id');
        $stmtItem = db()->prepare("INSERT INTO shipment_items (shipment_id,line_no,product_name,product_id,des_col,optional_value,qty,unit,rate,amount) VALUES (?,?,?,?,?,?,?,?,?,?)");
        $totalQty = 0; $subtotal = 0;

        foreach ($products as $i => $product) {
            $product = trim($product);
            if ($product === '') continue;

            $qty = (float)($qtys[$i] ?? 0);
            $rate = (float)($rates[$i] ?? 0);
            $amount = $qty * $rate;
            $totalQty += $qty;
            $subtotal += $amount;

            $stmtItem->execute([
                $id,
                $i + 1,
                $product,
                ((int)($pids[$i] ?? 0)) ?: null,
                trim(post_array('des_col')[$i] ?? ''),
                trim(post_array('optional_value')[$i] ?? ''),
                $qty,
                trim(post_array('unit')[$i] ?? 'Pcs'),
                $rate,
                $amount
            ]);
            
            /* The per-line Department was removed from the invoice screen.
               It wrote to shipment_items.department, which nothing in the
               application ever read.

               The column and its existing values are left alone — this
               simply stops writing to it. The guard below was already
               "only if posted", so an old invoice re-saved through the new
               screen keeps whatever department it had rather than having
               it cleared. */
        }

        db()->prepare("DELETE FROM shipment_charges WHERE shipment_id=?")->execute([$id]);
        $chargeNames = post_array('charge_name');
        $chargeAmts = post_array('charge_amount');
        $stmtCh = db()->prepare("INSERT INTO shipment_charges (shipment_id,charge_name,amount) VALUES (?,?,?)");
        $charges = 0;

        foreach ($chargeNames as $i => $cn) {
            $cn = trim($cn);
            $amt = (float)($chargeAmts[$i] ?? 0);
            if ($cn === '' && $amt == 0) continue;
            $charges += $amt;
            $stmtCh->execute([$id, $cn ?: 'Charge', $amt]);
        }

        db()->prepare("UPDATE shipments SET total_qty=?, total_amount=? WHERE id=?")
            ->execute([$totalQty, $subtotal + $charges, $id]);

        /* Final Costing caches a computed view of this shipment — which
           product each line matched to, its estimated cost, its margin — for
           ten minutes. The invoice lines it is computed FROM have just
           changed, so that cache is now wrong. This is the only place that
           can know it: a product name edited here changes the match, and a
           qty or rate changes the margin.

           Without this, editing an invoice and opening Final Costing inside
           ten minutes shows the pre-edit numbers with nothing to say so.
           Final Costing's Save Draft used to paper over it by recomputing
           everything on every save, which made the button you press most
           the slowest one on the page. Bumping here fixes the cause. */
        if (function_exists('cache_bump')) {
            try { cache_bump('fc_estimate_' . $id); } catch (Throwable $e) {}
        }
    }

    /*
      Packing save rule:
      Product name / Des Col cannot be typed manually.
      Any packing rows posted here must use invoice_item_id[].
      Product/description are copied from shipment_items.
    */
    if ($editPacking && isset($_POST['invoice_item_id'])) {
        $itemRows = db()->prepare("SELECT * FROM shipment_items WHERE shipment_id=? ORDER BY line_no,id");
        $itemRows->execute([$id]);
        $itemMap = [];
        foreach ($itemRows->fetchAll() as $it) $itemMap[(int)$it['id']] = $it;

        db()->prepare("DELETE FROM packing_items WHERE shipment_id=?")->execute([$id]);

        $stmtPack = db()->prepare("INSERT INTO packing_items (shipment_id,line_no,product_name,des_col,optional_value,carton_from,carton_to,packages,qty_per_carton,total_qty,net_weight,gross_weight) VALUES (?,?,?,?,?,?,?,?,?,?,?,?)");

        $totalPackages = 0; $totalPackQty = 0; $net = 0; $gross = 0; $line = 0;

        foreach (post_array('invoice_item_id') as $i => $itemId) {
            $itemId = (int)$itemId;
            if (!$itemId || empty($itemMap[$itemId])) continue;

            $from = (int)(post_array('carton_from')[$i] ?? 0);
            $to = (int)(post_array('carton_to')[$i] ?? 0);
            if ($from <= 0 || $to < $from) continue;

            $packages = max(0, $to - $from + 1);
            $qpc = (float)(post_array('qty_per_carton')[$i] ?? 0);
            $totalQty = $packages * $qpc;
            $nw = (float)(post_array('net_weight')[$i] ?? 0);
            $gw = (float)(post_array('gross_weight')[$i] ?? 0);

            $it = $itemMap[$itemId];
            $line++;

            $stmtPack->execute([
                $id,
                $line,
                $it['product_name'],
                $it['des_col'],
                $it['optional_value'],
                $from,
                $to,
                $packages,
                $qpc,
                $totalQty,
                $nw,
                $gw
            ]);

            $totalPackages += $packages;
            $totalPackQty += $totalQty;
            $net += $nw;
            $gross += $gw;
        }

        db()->prepare("UPDATE shipments SET total_packages=?, total_net_weight=?, total_gross_weight=?, updated_by=?, updated_at=NOW() WHERE id=?")
            ->execute([$totalPackages, $net, $gross, current_user()['id'], $id]);
    }

    if ($locked && is_admin()) {
        db()->prepare("UPDATE shipments SET revision_no=revision_no+1 WHERE id=?")->execute([$id]);
        audit_log($id, 'Locked Record Amendment', 'bulk_update', $oldSnapshot, 'Record amended by Admin', $reason);
        db()->prepare("UPDATE shipment_embeddings SET is_active=0 WHERE shipment_id=?")->execute([$id]);
    } elseif ($saveAndSubmit) {
        db()->prepare("UPDATE shipments SET status='submitted', reopen_status=NULL, reopen_reason=NULL, reopened_by=NULL, reopened_at=NULL, updated_by=?, updated_at=NOW() WHERE id=?")
            ->execute([current_user()['id'], $id]);
        audit_log($id, 'Approval Workflow', 'status', $shipment['status'] . ' / ' . ($shipment['reopen_status'] ?? ''), 'submitted', 'Saved and submitted for Admin approval');
    } else {
        audit_log($id, 'Shipment', 'save', 'Before save', 'Shipment saved', (($shipment['reopen_status'] ?? '') === 'reopened_for_correction') ? 'Correction update after Admin reopen' : 'Draft update');
    }

    db()->commit();
    $_SESSION['flash'] = $saveAndSubmit ? 'Shipment saved and submitted for Admin approval.' : 'Shipment saved successfully.';
} catch (Throwable $e) {
    if (db()->inTransaction()) db()->rollBack();
    $_SESSION['error'] = $e->getMessage();
}

redirect('shipment_view.php?id=' . $id);
