<?php
/*
  Proforma Invoice semantic search — feeds search.php's "Proforma Invoice"
  mode. Mirrors includes/ai_costing.php's costing_embeddings pattern
  exactly (natural-sentence text, content-hash skip, same table shape),
  since Proforma is auto-embedded on every save just like Costing —
  see the call to proforma_embed_one() at the end of proforma.php's
  action=save handler.
*/

function proforma_embed_schema(): void {
    try { db()->exec("CREATE TABLE IF NOT EXISTS proforma_embeddings (
        id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
        proforma_id INT NOT NULL,
        embedding_model VARCHAR(80) NOT NULL,
        embedding_vector LONGTEXT NOT NULL,
        embedded_text MEDIUMTEXT NOT NULL,
        content_hash CHAR(64) NOT NULL,
        token_count INT NULL,
        estimated_cost DECIMAL(14,8) NULL,
        embedded_at DATETIME NULL,
        UNIQUE KEY uniq_proforma (proforma_id, embedding_model),
        KEY idx_hash (content_hash)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4"); } catch (Throwable $e) {}
}

/* Natural-sentence description of one proforma — same phrasing style as
   aic_build_embed_text() in ai_costing.php, for the same reason: plain
   sentences let the actual customer/product wording carry the meaning,
   instead of repeated field-label boilerplate drowning it out. */
function proforma_embed_build_text(int $proformaId): ?string {
    $pf = db()->prepare("SELECT * FROM proforma_invoices WHERE id=?");
    $pf->execute([$proformaId]);
    $pf = $pf->fetch();
    if (!$pf) return null;

    $cur = $pf['currency'] ?: 'USD';
    $it = db()->prepare("SELECT * FROM proforma_items WHERE proforma_id=? ORDER BY sort_order,id");
    $it->execute([$proformaId]);
    $items = $it->fetchAll();
    $total = 0; foreach ($items as $r) $total += (float)$r['amount'];

    $intro = 'Proforma Invoice ' . $pf['pi_no'] . ' for ' . ($pf['customer_name'] ?: 'an unnamed customer')
        . ', currently ' . ($pf['status'] ?: 'draft') . '.';
    $intro .= ' Total value ' . $cur . ' ' . number_format($total, 2) . '.';
    if (trim((string)$pf['validity']) !== '') $intro .= ' Validity ' . $pf['validity'] . '.';
    if (trim((string)$pf['payment_terms']) !== '') $intro .= ' Payment terms: ' . $pf['payment_terms'] . '.';
    if (trim((string)$pf['delivery_terms']) !== '') $intro .= ' Delivery terms: ' . $pf['delivery_terms'] . '.';
    if (trim((string)$pf['shipment_terms']) !== '') $intro .= ' Shipment terms: ' . $pf['shipment_terms'] . '.';
    if (trim((string)($pf['delivery_date'] ?? '')) !== '') $intro .= ' Delivery date: ' . $pf['delivery_date'] . '.';
    if (trim((string)$pf['remarks']) !== '') $intro .= ' Terms & conditions note: ' . $pf['remarks'];

    $lineSentences = [];
    foreach ($items as $r) {
        $name = $r['product_name'] ?: 'Item';
        $s = $name;
        if (trim((string)$r['description']) !== '') $s .= ' (' . $r['description'] . ')';
        if (trim((string)$r['size']) !== '') $s .= ', size ' . $r['size'];
        $s .= ', quantity ' . rtrim(rtrim(number_format((float)$r['qty'], 3), '0'), '.') . ' ' . ($r['unit'] ?: 'pcs')
            . ', unit price ' . $cur . ' ' . number_format((float)$r['unit_price'], 2)
            . ', amounting to ' . $cur . ' ' . number_format((float)$r['amount'], 2) . '.';
        $lineSentences[] = $s;
    }

    $text = $intro;
    if ($lineSentences) $text .= "\n\nProducts: " . implode(' ', $lineSentences);
    return $text;
}

function proforma_embed_hash(string $text, string $model): string { return hash('sha256', $model . '|' . $text); }

/* create/update one proforma's embedding. Content-hash skip means saving a
   proforma whose text didn't actually change never re-bills OpenAI — same
   habit as costing/shipment/Product Master embeddings. */
function proforma_embed_one(int $proformaId): array {
    global $config;
    proforma_embed_schema();
    $model = $config['embedding_model'] ?? 'text-embedding-3-small';
    $text = proforma_embed_build_text($proformaId);
    if ($text === null) return ['status' => 'failed', 'error' => 'proforma not found'];
    $hash = proforma_embed_hash($text, $model);

    $ex = db()->prepare("SELECT content_hash FROM proforma_embeddings WHERE proforma_id=? AND embedding_model=?");
    $ex->execute([$proformaId, $model]);
    if ($ex->fetchColumn() === $hash) return ['status' => 'current', 'skipped' => true];

    try {
        $r = create_embedding_ex($text, $model, 0);
        $vec = $r['vector']; $tok = $r['tokens'];
        if (!$vec) throw new Exception('empty vector');
        $cost = round($tok / 1e6 * (float)($config['embed_price_per_m'] ?? 0.02), 8);
        db()->prepare("REPLACE INTO proforma_embeddings (proforma_id,embedding_model,embedding_vector,embedded_text,content_hash,token_count,estimated_cost,embedded_at) VALUES (?,?,?,?,?,?,?,NOW())")
            ->execute([$proformaId, $model, json_encode($vec), $text, $hash, $tok, $cost]);
        return ['status' => 'current', 'tokens' => $tok, 'cost' => $cost, 'skipped' => false];
    } catch (Throwable $e) {
        return ['status' => 'failed', 'error' => $e->getMessage()];
    }
}

/* which proformas still need embedding for the CURRENT model — same
   pending-check pattern as aic_pending_ids(), used by the "Embed All"
   button on search.php to know what to queue without billing anything
   just to check. */
function proforma_pending_ids(): array {
    global $config;
    proforma_embed_schema();
    $model = $config['embedding_model'] ?? 'text-embedding-3-small';
    $out = [];
    $ids = db()->query("SELECT id FROM proforma_invoices")->fetchAll(PDO::FETCH_COLUMN);
    $hashes = db()->prepare("SELECT content_hash FROM proforma_embeddings WHERE proforma_id=? AND embedding_model=?");
    foreach ($ids as $pid) {
        $text = proforma_embed_build_text((int)$pid);
        if ($text === null) continue;
        $hashes->execute([$pid, $model]);
        $stored = $hashes->fetchColumn();
        if ($stored !== proforma_embed_hash($text, $model)) $out[] = (int)$pid;
    }
    return $out;
}
