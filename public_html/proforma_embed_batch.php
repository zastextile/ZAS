<?php
/*
  Bulk "Embed All (Missing/Changed)" for Proforma Invoices — mirrors
  costing_embed_batch.php's action-based JSON API exactly. Needed for
  proformas that already existed before proforma_embed_one() started
  running automatically on save — those never got a first embedding
  until someone opens and re-saves them, or runs this batch tool once.
*/
require_once __DIR__ . '/includes/bootstrap.php';
require_once __DIR__ . '/includes/openai.php';
require_once __DIR__ . '/includes/costing.php';
require_once __DIR__ . '/includes/proforma_embed.php';
require_login();
require_admin();
header('Content-Type: application/json; charset=utf-8');

$action = $_POST['action'] ?? $_GET['action'] ?? '';

try {
    if ($action === 'pending') {
        $ids = proforma_pending_ids();
        $total = (int)db()->query("SELECT COUNT(*) FROM proforma_invoices")->fetchColumn();
        echo json_encode(['ok' => true, 'pending' => $ids, 'pending_count' => count($ids), 'total' => $total, 'current' => $total - count($ids)]);
        exit;
    }

    if ($action === 'embed_batch') {
        verify_csrf();
        global $config;
        $ids = json_decode($_POST['ids'] ?? '[]', true) ?: [];
        $ids = array_slice(array_map('intval', $ids), 0, (int)($config['pm_batch_size'] ?? 25));
        $done = 0; $skip = 0; $fail = 0; $tok = 0; $cost = 0;
        foreach ($ids as $pid) {
            $r = proforma_embed_one($pid);
            if (($r['status'] ?? '') === 'failed') $fail++;
            elseif (!empty($r['skipped'])) $skip++;
            else { $done++; $tok += (int)($r['tokens'] ?? 0); $cost += (float)($r['cost'] ?? 0); }
        }
        echo json_encode(['ok' => true, 'done' => $done, 'skipped' => $skip, 'failed' => $fail, 'tokens' => $tok, 'cost' => round($cost, 6)]);
        exit;
    }

    if ($action === 'usage') {
        proforma_embed_schema();
        $row = db()->query("SELECT COUNT(*) n, COALESCE(SUM(token_count),0) tok, COALESCE(SUM(estimated_cost),0) cost FROM proforma_embeddings")->fetch();
        echo json_encode(['ok' => true] + $row);
        exit;
    }

    echo json_encode(['ok' => false, 'message' => 'Unknown action.']);
} catch (Throwable $e) {
    echo json_encode(['ok' => false, 'message' => $e->getMessage()]);
}
