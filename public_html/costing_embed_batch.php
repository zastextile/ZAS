<?php
/*
  Bulk "Embed All (Missing/Changed)" for Product Costing versions — mirrors
  pm_embed.php's action-based JSON API, same as shipment_embed_batch.php.
  Reuses aic_embed_version() as-is (already has its own content-hash skip),
  just adds a bulk driver so switching embedding_model doesn't mean
  re-saving every costing by hand.
*/
require_once __DIR__ . '/includes/bootstrap.php';
require_once __DIR__ . '/includes/openai.php';
require_once __DIR__ . '/includes/ai_costing.php';
require_login();
require_admin();
header('Content-Type: application/json; charset=utf-8');

$action = $_POST['action'] ?? $_GET['action'] ?? '';

try {
    if ($action === 'pending') {
        $ids = aic_pending_ids();
        $total = (int)db()->query("SELECT COUNT(*) FROM costing_versions")->fetchColumn();
        echo json_encode(['ok' => true, 'pending' => $ids, 'pending_count' => count($ids), 'total' => $total, 'current' => $total - count($ids)]);
        exit;
    }

    if ($action === 'embed_batch') {
        verify_csrf();
        global $config;
        $ids = json_decode($_POST['ids'] ?? '[]', true) ?: [];
        $ids = array_slice(array_map('intval', $ids), 0, (int)($config['pm_batch_size'] ?? 25));
        $done = 0; $skip = 0; $fail = 0; $tok = 0; $cost = 0;
        foreach ($ids as $vid) {
            $r = aic_embed_version($vid);
            if (($r['status'] ?? '') === 'failed') $fail++;
            elseif (!empty($r['skipped'])) $skip++;
            else { $done++; $tok += (int)($r['tokens'] ?? 0); $cost += (float)($r['cost'] ?? 0); }
        }
        echo json_encode(['ok' => true, 'done' => $done, 'skipped' => $skip, 'failed' => $fail, 'tokens' => $tok, 'cost' => round($cost, 6)]);
        exit;
    }

    if ($action === 'usage') {
        aic_embed_schema();
        $row = db()->query("SELECT COUNT(*) n, COALESCE(SUM(token_count),0) tok, COALESCE(SUM(estimated_cost),0) cost FROM costing_embeddings")->fetch();
        echo json_encode(['ok' => true] + $row);
        exit;
    }

    echo json_encode(['ok' => false, 'message' => 'Unknown action.']);
} catch (Throwable $e) {
    echo json_encode(['ok' => false, 'message' => $e->getMessage()]);
}
