<?php
require_once __DIR__ . '/includes/bootstrap.php';
require_once __DIR__ . '/includes/openai.php';
require_once __DIR__ . '/includes/shipment_embed.php';
require_admin();
verify_csrf();

/*
  OpenAI Usage Control V2.2
  Embedding is allowed ONLY after Admin has approved and locked the shipment.
  This prevents token usage on draft/working records.
  Core logic lives in includes/shipment_embed.php, shared with
  shipment_embed_batch.php's bulk "Embed All" — single source of truth.
*/

$id = (int)($_POST['shipment_id'] ?? $_GET['id'] ?? 0);

$stmt = db()->prepare("SELECT status FROM shipments WHERE id=?");
$stmt->execute([$id]);
$status = $stmt->fetchColumn();

if ($status === false) {
    http_response_code(404);
    exit('Shipment not found.');
}

if ($status !== 'approved_locked') {
    $_SESSION['error'] = 'Embedding blocked. First approve and lock this shipment, then create/regenerate embedding.';
    redirect('shipment_view.php?id=' . $id);
}

$result = shipment_embed_one($id, current_user()['id']);
if ($result['ok']) {
    $_SESSION['flash'] = !empty($result['skipped'])
        ? 'Already up to date — nothing changed since the last embedding, no OpenAI call made.'
        : 'Embedding generated successfully for approved/locked shipment.';
} else {
    $_SESSION['error'] = 'Embedding failed: ' . $result['message'];
}

redirect('shipment_view.php?id=' . $id);
