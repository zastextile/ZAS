<?php
/*
  Manually switch a Final Costing line to a specific Product Costing version
  (e.g. a different size) instead of the auto-picked one from pick_costing()
  in ai_check_core.php. Only reachable when the matched product actually has
  more than one costing version — see fc_render_line_card()'s picker.
*/
require_once __DIR__ . '/includes/bootstrap.php';
require_once __DIR__ . '/includes/pm_search.php';
require_once __DIR__ . '/includes/ai_check_core.php';
require_once __DIR__ . '/includes/final_costing.php';
require_login();
require_admin();
verify_csrf();
fc_ensure_schema();

$shipmentId = (int)($_POST['shipment_id'] ?? 0);
$itemId = (int)($_POST['item_id'] ?? 0);
$versionId = (int)($_POST['costing_version_id'] ?? 0);
$isAjax = ($_POST['ajax'] ?? '') === '1';

// AJAX-safe early exit: always return valid JSON when this is an AJAX call,
// instead of a plain-text exit that would break the client's response.json()
// parse and surface as a generic "Network error" toast.
function fcsv_fail(bool $isAjax, string $message, int $httpCode = 400): never
{
    if ($isAjax) {
        http_response_code($httpCode);
        header('Content-Type: application/json');
        echo json_encode(['success' => false, 'message' => $message]);
        exit;
    }
    http_response_code($httpCode);
    $_SESSION['error'] = $message;
    redirect('final_costing.php');
}

try {
    $st = db()->prepare("SELECT si.*, s.currency shipcur FROM shipment_items si JOIN shipments s ON s.id = si.shipment_id WHERE si.id = ? AND si.shipment_id = ?");
    $st->execute([$itemId, $shipmentId]);
    $item = $st->fetch();
    if (!$item) fcsv_fail($isAjax, 'Shipment line not found.', 404);

    $cur = $item['shipcur'] ?: 'PKR';
    $fx = fx_get_rates();
    $materials = fc_materials_from_version($versionId, (float)$item['qty'], $cur, $fx);

    if ($materials === null) {
        $ok = false; $msg = 'Costing version not found.';
    } else {
        $uid = current_user()['id'];
        $result = fc_resync_line($itemId, $materials, $uid, $versionId);
        $ok = $result['ok']; $msg = $ok ? 'Switched to the selected costing variation.' : $result['message'];
        if ($ok) audit_log($shipmentId, 'Final Costing', 'switch_variation', '', "Line switched to costing_version_id={$versionId}", 'Manual costing variation change');
    }

    // Locked lines can't be touched anyway — same reasoning as elsewhere on this page.
    $lockedItemIds = [];
    foreach (fc_get_for_shipment($shipmentId) as $lid => $lfc) if ($lfc['status'] === 'locked') $lockedItemIds[] = $lid;
    $estimate = ai_check_compute($shipmentId, $lockedItemIds);
    // Refill (not just invalidate) the cache with what was just computed — same
    // reasoning as final_costing_resync.php and final_costing_link_product.php.
    fc_estimate_store($shipmentId, $estimate);

    if ($isAjax) {
        header('Content-Type: application/json');
        if (!$ok) { echo json_encode(['success' => false, 'message' => $msg]); exit; }
        [$lc, $itemRow] = fc_find_line($estimate, $itemId);
        $finals = fc_get_for_shipment($shipmentId);
        $html = ($lc && $itemRow) ? fc_render_line_card($lc, $itemRow, $finals[$itemId] ?? null, $cur, $shipmentId) : '';
        echo json_encode(['success' => true, 'message' => $msg, 'html' => $html]);
        exit;
    }

    if ($ok) { $_SESSION['flash'] = $msg; } else { $_SESSION['error'] = $msg; }
    redirect('final_costing.php?shipment_id=' . $shipmentId);
} catch (Throwable $e) {
    error_log('final_costing_switch_variation.php: ' . $e->getMessage());
    fcsv_fail($isAjax, 'Something went wrong switching the variation: ' . $e->getMessage(), 500);
}
