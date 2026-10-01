<?php
require_once __DIR__ . '/includes/bootstrap.php';
require_once __DIR__ . '/includes/pm_search.php';
require_once __DIR__ . '/includes/ai_check_core.php';
require_once __DIR__ . '/includes/final_costing.php';
require_admin();
verify_csrf();
fc_ensure_schema();

$shipmentId = (int)($_POST['shipment_id'] ?? 0);
$onlyItemId = (int)($_POST['item_id'] ?? 0); // 0 = resync every line on this shipment
$isAjax = ($_POST['ajax'] ?? '') === '1' && $onlyItemId > 0; // "Resync All" always stays a full page action — it touches every card

$st = db()->prepare("SELECT * FROM shipments WHERE id=?");
$st->execute([$shipmentId]);
$shipment = $st->fetch();
if (!$shipment) { http_response_code(404); exit('Shipment not found.'); }

// Locked lines can't be re-synced anyway (fc_resync_line() refuses them below)
// — skip computing their match/profitability too, same reasoning as final_costing.php.
$lockedItemIds = [];
foreach (fc_get_for_shipment($shipmentId) as $itemId => $fc) {
    if ($fc['status'] === 'locked') $lockedItemIds[] = $itemId;
}
$estimate = ai_check_compute($shipmentId, $lockedItemIds);
$uid = current_user()['id'];

$done = 0; $skippedLocked = 0; $noMatch = 0;
$targetLc = null; $targetItemRow = null; $targetResult = null;
foreach ($estimate['line_costing'] ?? [] as $lc) {
    $itemRow = null;
    foreach ($estimate['items'] as $it) { if ((int)$it['line_no'] === (int)$lc['line']) { $itemRow = $it; break; } }
    if (!$itemRow) continue;
    $itemId = (int)$itemRow['id'];
    if ($onlyItemId && $itemId !== $onlyItemId) continue;

    if (empty($lc['materials'])) {
        $noMatch++;
        if ($onlyItemId) { $targetLc = $lc; $targetItemRow = $itemRow; $targetResult = ['ok' => false, 'message' => 'No matching Product Costing found for this product/size.']; }
        continue;
    }
    $result = fc_resync_line($itemId, $lc['materials'], $uid, $lc['costing_version_id'] ?? null);
    if ($onlyItemId) { $targetLc = $lc; $targetItemRow = $itemRow; $targetResult = $result; }
    if (!empty($result['skipped'])) { $skippedLocked++; continue; }
    if ($result['ok']) $done++;
}

audit_log($shipmentId, 'Final Costing', 'resync', '', "$done line(s) re-synced from Product Costing", $onlyItemId ? 'Single line re-sync' : 'Bulk re-sync all lines');

$msg = "$done line(s) re-synced from current Product Costing.";
if ($skippedLocked) $msg .= " $skippedLocked locked line(s) skipped — reopen first if they need updating.";
if ($noMatch) $msg .= " $noMatch line(s) had no matching Product Costing to sync from.";
$_SESSION['flash'] = $msg;
// Refill the cache with what was just computed, instead of only invalidating
// it — Re-sync always redirects straight back to final_costing.php, so this
// turns "recompute everything again on the very next view" into an instant
// cache hit. $estimate is still accurate to cache: Product Costing (what it's
// matched against) isn't changed by resync, only final_costing_lines is.
fc_estimate_store($shipmentId, $estimate);

if ($isAjax) {
    header('Content-Type: application/json');
    if (!$targetLc || !$targetItemRow) { echo json_encode(['success' => false, 'message' => 'Line not found.']); exit; }
    if ($targetResult && !$targetResult['ok']) { echo json_encode(['success' => false, 'message' => $targetResult['message']]); exit; }
    $cur = $shipment['currency'] ?: 'PKR';
    $finals = fc_get_for_shipment($shipmentId);
    $html = fc_render_line_card($targetLc, $targetItemRow, $finals[$onlyItemId] ?? null, $cur, $shipmentId);
    echo json_encode(['success' => true, 'message' => 'Line re-synced from current Product Costing.', 'html' => $html]);
    exit;
}

redirect('final_costing.php?shipment_id=' . $shipmentId);
