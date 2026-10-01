<?php
require_once __DIR__ . '/includes/bootstrap.php';
require_once __DIR__ . '/includes/pm_search.php';
require_once __DIR__ . '/includes/ai_check_core.php';
require_once __DIR__ . '/includes/final_costing.php';

$isAjax = ($_POST['ajax'] ?? '') === '1';
$shipmentId = (int)($_POST['shipment_id'] ?? 0);

// Same AJAX-safe pattern as final_costing_save.php — require_admin()/verify_csrf()
// normally redirect or exit with plain text on failure, which breaks the AJAX
// response parser and shows as a generic "Network error" with no real reason.
function fclock_fail(bool $isAjax, string $message, int $shipmentId = 0, int $httpCode = 400): never
{
    if ($isAjax) {
        http_response_code($httpCode);
        header('Content-Type: application/json');
        echo json_encode(['success' => false, 'message' => $message]);
        exit;
    }
    http_response_code($httpCode);
    $_SESSION['error'] = $message;
    redirect($shipmentId ? ('final_costing.php?shipment_id=' . $shipmentId) : 'final_costing.php');
}

if (!current_user()) fclock_fail($isAjax, 'Your session has expired — please refresh the page and sign in again.', $shipmentId, 401);
if (!is_admin()) fclock_fail($isAjax, 'Admin access required.', $shipmentId, 403);
if (!hash_equals($_SESSION['_csrf'] ?? '', $_POST['_csrf'] ?? '')) fclock_fail($isAjax, 'Security token expired — please refresh the page and try again.', $shipmentId, 419);

fc_ensure_schema();

$fcId = (int)($_POST['final_costing_id'] ?? 0);
$action = $_POST['fc_action'] ?? 'lock';

try {
    if ($action === 'reopen') {
        $result = fc_reopen($fcId, current_user()['id'], trim($_POST['reopen_reason'] ?? ''));
        if ($result['ok']) {
            audit_log($shipmentId, 'Final Costing', 'reopen', 'locked', 'draft', trim($_POST['reopen_reason'] ?? ''));
        }
    } else {
        $result = fc_lock($fcId, current_user()['id']);
        if ($result['ok']) {
            audit_log($shipmentId, 'Final Costing', 'lock', 'draft', 'locked', 'Final costing locked by admin.');
        }
    }

    if ($isAjax) {
        header('Content-Type: application/json');
        if (!$result['ok']) { echo json_encode(['success' => false, 'message' => $result['message']]); exit; }
        $st = db()->prepare("SELECT shipment_item_id FROM final_costings WHERE id=?"); $st->execute([$fcId]);
        $itemId = (int)$st->fetchColumn();
        $st = db()->prepare("SELECT currency FROM shipments WHERE id=?"); $st->execute([$shipmentId]);
        $cur = $st->fetchColumn() ?: 'PKR';
        $finals = fc_get_for_shipment($shipmentId);
        // Locking changes a line's status, not what product it is — the cached
        // estimate stays correct. This is why Lock has always been the quick
        // button on this page; Save Draft now works the same way.
        $estimate = fc_estimate_cached($shipmentId, fc_locked_item_ids($finals));
        [$lc, $itemRow] = fc_find_line($estimate, $itemId);
        $html = ($lc && $itemRow) ? fc_render_line_card($lc, $itemRow, $finals[$itemId] ?? null, $cur, $shipmentId) : '';
        echo json_encode(['success' => true, 'message' => $result['message'], 'html' => $html]);
        exit;
    }

    if ($result['ok']) { $_SESSION['flash'] = $result['message']; } else { $_SESSION['error'] = $result['message']; }
    redirect('final_costing.php?shipment_id=' . $shipmentId);
} catch (Throwable $e) {
    error_log('final_costing_lock.php: ' . $e->getMessage());
    fclock_fail($isAjax, 'Something went wrong: ' . $e->getMessage(), $shipmentId, 500);
}
