<?php
require_once __DIR__ . '/includes/bootstrap.php';
require_once __DIR__ . '/includes/openai.php';
require_admin();

/*
  OpenAI Usage Control V2.2
  Embedding is allowed ONLY after Admin has approved and locked the shipment.
  This prevents token usage on draft/working records.
*/

$id = (int)($_GET['id'] ?? 0);

$stmt = db()->prepare("SELECT * FROM shipments WHERE id=?");
$stmt->execute([$id]);
$s = $stmt->fetch();

if (!$s) {
    http_response_code(404);
    exit('Shipment not found.');
}

if (($s['status'] ?? '') !== 'approved_locked') {
    $_SESSION['error'] = 'Embedding blocked. First approve and lock this shipment, then create/regenerate embedding.';
    redirect('shipment_view.php?id=' . $id);
}

$items = db()->prepare("SELECT * FROM shipment_items WHERE shipment_id=? ORDER BY line_no,id");
$items->execute([$id]);
$items = $items->fetchAll();

$packs = db()->prepare("SELECT * FROM packing_items WHERE shipment_id=? ORDER BY line_no,id");
$packs->execute([$id]);
$packs = $packs->fetchAll();

function clean_shipment_text_locked_only($s, $items, $packs): string {
    $lines = [];
    $lines[] = "Document Type: Commercial Invoice and Packing List";
    $lines[] = "Record Status: Approved and Locked";
    $lines[] = "Invoice No: {$s['invoice_no']}";
    $lines[] = "Invoice Date: {$s['invoice_date']}";
    $lines[] = "Buyer: {$s['buyer_name']}";
    $lines[] = "Buyer Address: {$s['buyer_address']}";
    $lines[] = "Buyer Country: {$s['buyer_country']}";
    $lines[] = "Destination Port: {$s['destination_port']}";
    $lines[] = "PO No: {$s['po_no']}";
    $lines[] = "BL / Container No: {$s['bl_container_no']}";
    $lines[] = "Currency: {$s['currency']}";
    $lines[] = "Payment Terms: {$s['payment_terms']}";
    $lines[] = "Optional Column Title: {$s['optional_column_title']}";
    $lines[] = "Total Qty: {$s['total_qty']}";
    $lines[] = "Total Packages / Units: {$s['total_packages']}";
    $lines[] = "Net Weight Kg: {$s['total_net_weight']}";
    $lines[] = "Gross Weight Kg: {$s['total_gross_weight']}";
    $lines[] = "Total Amount: {$s['currency']} {$s['total_amount']}";

    foreach ($items as $it) {
        $lines[] = "Invoice Item {$it['line_no']}: Product {$it['product_name']} | Des/Col {$it['des_col']} | {$s['optional_column_title']} {$it['optional_value']} | Qty {$it['qty']} {$it['unit']} | Rate {$s['currency']} {$it['rate']} | Amount {$s['currency']} {$it['amount']}";
    }

    foreach ($packs as $p) {
        $unitTitle = $p['pack_unit_title'] ?? 'Carton';
        $qtyMode = ($p['qty_mode'] ?? 'auto') === 'direct' ? 'Direct Qty' : 'Auto Qty';
        $lines[] = "Packing Item {$p['line_no']}: Product {$p['product_name']} | Des/Col {$p['des_col']} | {$s['optional_column_title']} {$p['optional_value']} | Unit Title {$unitTitle} | Serial {$p['carton_from']} to {$p['carton_to']} | Units {$p['packages']} | Qty Mode {$qtyMode} | Qty/Unit {$p['qty_per_carton']} | Total Qty {$p['total_qty']} | Net {$p['net_weight']} kg | Gross {$p['gross_weight']} kg";
    }

    return implode("\n", $lines);
}

try {
    $text = clean_shipment_text_locked_only($s, $items, $packs);

    /*
      OpenAI token usage happens here only:
      - model: text-embedding-3-small
      - only after approved_locked
      - only by Admin clicking Create / Regenerate Embedding
    */
    $vector = create_embedding($text);

    db()->beginTransaction();

    db()->prepare("UPDATE shipment_embeddings SET is_active=0 WHERE shipment_id=?")->execute([$id]);
    db()->prepare("INSERT INTO shipment_embeddings (shipment_id,chunk_type,chunk_text,vector_json,model,is_active) VALUES (?,?,?,?,?,1)")
        ->execute([$id, 'shipment_full_locked', $text, json_encode($vector), $config['embedding_model']]);

    audit_log($id, 'AI Embedding', 'create', '', 'Embedding generated after approved lock', 'Admin created/regenerated embedding after lock');

    db()->commit();

    $_SESSION['flash'] = 'Embedding generated successfully for approved/locked shipment.';
} catch (Throwable $e) {
    if (db()->inTransaction()) db()->rollBack();
    $_SESSION['error'] = $e->getMessage();
}

redirect('shipment_view.php?id=' . $id);
