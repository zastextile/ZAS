<?php
/*
  Manual fallback for a shipment line whose product text matched nothing in
  Product Costing (even after Re-sync) — e.g. it came from a CSV import or a
  manually typed name that doesn't exactly match Product Master. Admin picks
  the correct product once; this saves a confirmed alias (customer_product_
  mappings, via includes/pm_search.php) so every future line with this exact
  text auto-matches from then on, and immediately pulls this line's costing
  in. No AI/OpenAI calls — plain DB lookups only.
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
$productId = (int)($_POST['product_id'] ?? 0);
$isAjax = ($_POST['ajax'] ?? '') === '1';

$st = db()->prepare("SELECT si.*, s.buyer_name FROM shipment_items si JOIN shipments s ON s.id = si.shipment_id WHERE si.id = ? AND si.shipment_id = ?");
$st->execute([$itemId, $shipmentId]);
$item = $st->fetch();
if (!$item) { http_response_code(404); exit('Shipment line not found.'); }

$ps = db()->prepare("SELECT id, name FROM products WHERE id=? AND is_active=1");
$ps->execute([$productId]);
$prod = $ps->fetch();
if (!$prod) {
    if ($isAjax) { header('Content-Type: application/json'); echo json_encode(['success' => false, 'message' => 'Please pick a valid product from the list.']); exit; }
    $_SESSION['error'] = 'Please pick a valid product from the list.';
    redirect('final_costing.php?shipment_id=' . $shipmentId);
}

$uid = current_user()['id'];
pm_save_alias((string)$item['product_name'], (int)$prod['id'], (string)($item['buyer_name'] ?? ''), $uid);

// Locked lines can't be pulled into anyway — skip their match/profitability,
// same reasoning as final_costing.php and final_costing_resync.php.
$lockedItemIds = [];
foreach (fc_get_for_shipment($shipmentId) as $lid => $lfc) {
    if ($lfc['status'] === 'locked') $lockedItemIds[] = $lid;
}
// Re-runs now that the alias exists, so this line resolves to the newly-linked
// product's costing — this line's own match no longer needs an OpenAI call
// (confirmed alias is checked first), though other still-unconfirmed lines
// on the same shipment may still trigger one.
$estimate = ai_check_compute($shipmentId, $lockedItemIds);
$materials = []; $matchedVersionId = null;
foreach ($estimate['line_costing'] ?? [] as $lc) {
    if ((int)$lc['line'] === (int)$item['line_no']) { $materials = $lc['materials']; $matchedVersionId = $lc['costing_version_id'] ?? null; break; }
}

if ($materials) {
    fc_resync_line($itemId, $materials, $uid, $matchedVersionId);
    $msg = "Linked \"{$item['product_name']}\" to \"{$prod['name']}\" and pulled its costing in. Any other line named exactly \"{$item['product_name']}\" will now match automatically.";
    $ok = true;
} else {
    $msg = "Linked to \"{$prod['name']}\", but it has no costing version yet — add one in Product Costing, then Re-sync this line.";
    $ok = false;
}
audit_log($shipmentId, 'Final Costing', 'link_product', '', "Linked '{$item['product_name']}' -> '{$prod['name']}'", 'Manual product link + alias saved (no costing match found)');
// Refill (not just invalidate) the cache with what was just computed — same
// reasoning as final_costing_resync.php — so the redirect below is an
// instant cache hit instead of recomputing everything again immediately.
fc_estimate_store($shipmentId, $estimate);

if ($isAjax) {
    header('Content-Type: application/json');
    // $estimate already reflects the fresh match — pm_save_alias() ran before
    // ai_check_compute() above, so this item's line_costing already shows the
    // new match (see the comment on that call). No need to recompute again.
    [$lc, $itemRow] = fc_find_line($estimate, $itemId);
    $st = db()->prepare("SELECT currency FROM shipments WHERE id=?"); $st->execute([$shipmentId]);
    $cur = $st->fetchColumn() ?: 'PKR';
    $finals = fc_get_for_shipment($shipmentId);
    $html = ($lc && $itemRow) ? fc_render_line_card($lc, $itemRow, $finals[$itemId] ?? null, $cur, $shipmentId) : '';
    echo json_encode(['success' => $ok, 'message' => $msg, 'html' => $html]);
    exit;
}

if ($ok) { $_SESSION['flash'] = $msg; } else { $_SESSION['error'] = $msg; }
redirect('final_costing.php?shipment_id=' . $shipmentId);
