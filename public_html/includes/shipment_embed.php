<?php
/*
  Shared shipment-embedding helpers — single source of truth for both
  embed.php (one shipment at a time, from that shipment's own page) and
  shipment_embed_batch.php (bulk "Embed All" from the shipments list).
  OpenAI Usage Control V2.2 still applies: embedding is only ever allowed
  for a shipment already approved_locked (see shipment_embed_one() below).
*/

function shipment_embed_ensure_table(): void {
    try {
        db()->exec("CREATE TABLE IF NOT EXISTS shipment_embeddings (
            id INT AUTO_INCREMENT PRIMARY KEY,
            shipment_id INT NOT NULL,
            chunk_type VARCHAR(60) NULL,
            chunk_text MEDIUMTEXT NULL,
            vector_json LONGTEXT NULL,
            model VARCHAR(80) NULL,
            is_active TINYINT(1) NOT NULL DEFAULT 1,
            created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
            INDEX(shipment_id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    } catch (Throwable $e) {}
    foreach ([
        "ADD COLUMN chunk_type VARCHAR(60) NULL",
        "ADD COLUMN chunk_text MEDIUMTEXT NULL",
        "ADD COLUMN vector_json LONGTEXT NULL",
        "ADD COLUMN model VARCHAR(80) NULL",
        "ADD COLUMN is_active TINYINT(1) NOT NULL DEFAULT 1",
        "ADD COLUMN content_hash CHAR(64) NULL",
    ] as $alter) {
        try { db()->exec("ALTER TABLE shipment_embeddings $alter"); } catch (Throwable $e) {}
    }
}

function shipment_embed_build_text(array $s, array $items, array $packs): string {
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

function shipment_embed_hash(string $text, string $model): string { return hash('sha256', $model . '|' . $text); }

/* Embed one shipment. Blocked unless already approved_locked (OpenAI Usage
   Control V2.2). Content-hash skip added here — re-running this on a
   shipment whose text hasn't changed since its last embed never re-bills
   OpenAI, same cost-control habit as Product Master / costing embeddings. */
function shipment_embed_one(int $id, int $userId): array {
    global $config;
    shipment_embed_ensure_table();

    $st = db()->prepare("SELECT * FROM shipments WHERE id=?");
    $st->execute([$id]);
    $s = $st->fetch();
    if (!$s) return ['ok' => false, 'message' => 'Shipment not found.'];
    if (($s['status'] ?? '') !== 'approved_locked') return ['ok' => false, 'message' => 'Not approved/locked.', 'skipped' => true];

    $items = db()->prepare("SELECT * FROM shipment_items WHERE shipment_id=? ORDER BY line_no,id");
    $items->execute([$id]);
    $items = $items->fetchAll();
    $packs = db()->prepare("SELECT * FROM packing_items WHERE shipment_id=? ORDER BY line_no,id");
    $packs->execute([$id]);
    $packs = $packs->fetchAll();

    $model = $config['embedding_model'] ?? 'text-embedding-3-small';
    $text = shipment_embed_build_text($s, $items, $packs);
    $hash = shipment_embed_hash($text, $model);

    $ex = db()->prepare("SELECT content_hash FROM shipment_embeddings WHERE shipment_id=? AND model=? AND is_active=1 ORDER BY id DESC LIMIT 1");
    $ex->execute([$id, $model]);
    $curHash = $ex->fetchColumn();
    if ($curHash !== false && $curHash === $hash) return ['ok' => true, 'status' => 'current', 'skipped' => true];

    try {
        $vector = create_embedding($text);
        if (!$vector) throw new Exception('empty vector');

        db()->beginTransaction();
        db()->prepare("UPDATE shipment_embeddings SET is_active=0 WHERE shipment_id=?")->execute([$id]);
        db()->prepare("INSERT INTO shipment_embeddings (shipment_id,chunk_type,chunk_text,vector_json,model,is_active,content_hash) VALUES (?,?,?,?,?,1,?)")
            ->execute([$id, 'shipment_full_locked', $text, json_encode($vector), $model, $hash]);
        audit_log($id, 'AI Embedding', 'create', '', 'Embedding generated after approved lock', 'Admin created/regenerated embedding after lock');
        db()->commit();

        return ['ok' => true, 'status' => 'current', 'skipped' => false];
    } catch (Throwable $e) {
        if (db()->inTransaction()) db()->rollBack();
        return ['ok' => false, 'message' => $e->getMessage()];
    }
}
