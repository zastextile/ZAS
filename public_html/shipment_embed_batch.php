<?php
/*
  Bulk "Embed All (Missing/Changed)" for shipments — mirrors pm_embed.php's
  action-based JSON API exactly, so the shipments.php button behaves the
  same way Product Master's already does. Only ever touches shipments that
  are approved_locked (OpenAI Usage Control V2.2, enforced in
  includes/shipment_embed.php's shipment_embed_one()), and skips any
  shipment whose content hasn't changed since its last embedding.
*/
require_once __DIR__ . '/includes/bootstrap.php';
require_once __DIR__ . '/includes/openai.php';
require_once __DIR__ . '/includes/shipment_embed.php';
require_login();
require_admin();
header('Content-Type: application/json; charset=utf-8');

$action = $_POST['action'] ?? $_GET['action'] ?? '';

try {
    if ($action === 'pending') {
        shipment_embed_ensure_table();
        global $config;
        $model = $config['embedding_model'] ?? 'text-embedding-3-small';
        $locked = db()->query("SELECT id FROM shipments WHERE status='approved_locked' ORDER BY id")->fetchAll(PDO::FETCH_COLUMN);
        $current = db()->prepare("SELECT shipment_id FROM shipment_embeddings WHERE model=? AND is_active=1");
        $current->execute([$model]);
        $embedded = array_flip($current->fetchAll(PDO::FETCH_COLUMN));
        // "pending" here means "not embedded at all yet" — content-hash-changed
        // shipments still get skipped cheaply one-by-one inside embed_batch
        // rather than requiring a full text rebuild here just to list them.
        $pending = array_values(array_filter($locked, fn($id) => !isset($embedded[$id])));
        echo json_encode(['ok' => true, 'pending' => array_map('intval', $pending), 'pending_count' => count($pending), 'total' => count($locked), 'current' => count($locked) - count($pending)]);
        exit;
    }

    if ($action === 'embed_batch') {
        verify_csrf();
        global $config;
        $ids = json_decode($_POST['ids'] ?? '[]', true) ?: [];
        $ids = array_slice(array_map('intval', $ids), 0, (int)($config['pm_batch_size'] ?? 25));
        $uid = current_user()['id'];
        $done = 0; $skip = 0; $fail = 0;
        foreach ($ids as $id) {
            $r = shipment_embed_one($id, $uid);
            if (!$r['ok']) $fail++;
            elseif (!empty($r['skipped'])) $skip++;
            else $done++;
        }
        echo json_encode(['ok' => true, 'done' => $done, 'skipped' => $skip, 'failed' => $fail]);
        exit;
    }

    if ($action === 'usage') {
        shipment_embed_ensure_table();
        $row = db()->query("SELECT COUNT(*) n FROM shipment_embeddings WHERE is_active=1")->fetch();
        echo json_encode(['ok' => true, 'cur' => (int)($row['n'] ?? 0)]);
        exit;
    }

    echo json_encode(['ok' => false, 'message' => 'Unknown action.']);
} catch (Throwable $e) {
    echo json_encode(['ok' => false, 'message' => $e->getMessage()]);
}
