<?php
require_once __DIR__ . '/includes/bootstrap.php';
require_once __DIR__ . '/includes/pm_search.php';
require_login();
require_admin();
header('Content-Type: application/json; charset=utf-8');

$action = $_POST['action'] ?? $_GET['action'] ?? '';

try {
    if ($action === 'embed_one') {
        verify_csrf();
        $pid = (int)($_POST['id'] ?? 0);
        $r = pm_embed_product($pid);
        echo json_encode(['ok'=>true] + $r + ['status_info'=>pm_status($pid)]);
        exit;
    }

    if ($action === 'status') {
        echo json_encode(['ok'=>true,'status_info'=>pm_status((int)($_GET['id'] ?? 0))]);
        exit;
    }

    if ($action === 'pending') {
        $ids = pm_pending_ids();
        $total = (int)db()->query("SELECT COUNT(*) FROM products")->fetchColumn();
        echo json_encode(['ok'=>true,'pending'=>$ids,'pending_count'=>count($ids),'total'=>$total,'current'=>$total-count($ids)]);
        exit;
    }

    if ($action === 'embed_batch') {
        verify_csrf();
        global $config;
        $ids = json_decode($_POST['ids'] ?? '[]', true) ?: [];
        $ids = array_slice(array_map('intval',$ids), 0, (int)($config['pm_batch_size'] ?? 25));
        $done=0; $skip=0; $fail=0; $tok=0; $cost=0;
        foreach ($ids as $pid) {
            $r = pm_embed_product($pid);
            if (($r['status'] ?? '')==='failed') $fail++;
            elseif (!empty($r['skipped'])) $skip++;
            else { $done++; $tok += (int)($r['tokens'] ?? 0); $cost += (float)($r['cost'] ?? 0); }
        }
        echo json_encode(['ok'=>true,'done'=>$done,'skipped'=>$skip,'failed'=>$fail,'tokens'=>$tok,'cost'=>round($cost,6)]);
        exit;
    }

    if ($action === 'usage') {
        pm_schema();
        $row = db()->query("SELECT COUNT(*) n, COALESCE(SUM(token_count),0) tok, COALESCE(SUM(estimated_cost),0) cost, SUM(status='current') cur, SUM(status='failed') fail FROM product_embeddings")->fetch();
        echo json_encode(['ok'=>true] + $row);
        exit;
    }

    if ($action === 'confirm_alias') {
        verify_csrf();
        pm_save_alias(trim($_POST['name'] ?? ''), (int)($_POST['product_id'] ?? 0), trim($_POST['buyer'] ?? ''), (int)current_user()['id']);
        echo json_encode(['ok'=>true,'message'=>'Mapping saved and will be reused on future invoices.']);
        exit;
    }

    echo json_encode(['ok'=>false,'message'=>'Unknown action.']);
} catch (Throwable $e) {
    echo json_encode(['ok'=>false,'message'=>$e->getMessage()]);
}
