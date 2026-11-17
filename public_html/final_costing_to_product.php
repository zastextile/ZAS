<?php
/*
  "Save as product costing…" on a Final Costing card.

  Saves the card first (so what is on screen is what gets copied), then
  adds its lines to Product Costing as a NEW costing: a variation under
  the product chosen, or a new product. Nothing existing is changed.
  The line is then remembered as that product for this buyer, so the
  next shipment line with the same name finds this costing by itself.
  Answers in JSON with the redrawn card — no page reload.
*/
require_once __DIR__ . '/includes/bootstrap.php';
require_once __DIR__ . '/includes/pm_search.php';
require_once __DIR__ . '/includes/ai_check_core.php';
require_once __DIR__ . '/includes/final_costing.php';

header('Content-Type: application/json');
function fcsa_fail(string $m, int $code = 400): never { http_response_code($code); echo json_encode(['success' => false, 'message' => $m]); exit; }

if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') fcsa_fail('Not allowed.', 405);
if (!current_user()) fcsa_fail('Your session has expired — please refresh the page and sign in again.', 401);
if (!is_admin()) fcsa_fail('Admin access required.', 403);
if (!hash_equals($_SESSION['_csrf'] ?? '', $_POST['_csrf'] ?? '')) fcsa_fail('Security token expired — please refresh the page and try again.', 419);
fc_ensure_schema();

$shipmentId = (int)($_POST['shipment_id'] ?? 0);
$fcId = (int)($_POST['final_costing_id'] ?? 0);
$st = db()->prepare("SELECT fc.shipment_item_id, fc.status, si.product_name, s.buyer_name, s.currency
    FROM final_costings fc JOIN shipment_items si ON si.id = fc.shipment_item_id JOIN shipments s ON s.id = fc.shipment_id
    WHERE fc.id = ? AND fc.shipment_id = ?");
$st->execute([$fcId, $shipmentId]);
$row = $st->fetch();
if (!$row) fcsa_fail('This line was not found — refresh the page.', 404);
$locked = $row['status'] === 'locked';     // a locked card is copied as it stands, and not changed
$itemId = (int)$row['shipment_item_id'];
$uid = (int)current_user()['id'];

$mode = ($_POST['sa_mode'] ?? 'variation') === 'new' ? 'new' : 'variation';
$productId = $mode === 'variation' ? (int)($_POST['sa_product_id'] ?? 0) : 0;
if ($mode === 'variation' && $productId <= 0) fcsa_fail('Pick the product to add this costing under.');

try {
    // 1. what is on screen is what gets copied
    if (!$locked) {
        $lines = fc_lines_from_post();
        if (!$lines) fcsa_fail('This card has no lines to save — add its lines first.');
        $saved = fc_save_lines($fcId, $lines, $uid);
        if (!$saved['ok']) fcsa_fail($saved['message']);
    }

    // the line as matched before this change (normally kept — instant)
    $finals = fc_get_for_shipment($shipmentId);
    $estimate = fc_estimate_cached($shipmentId, fc_locked_item_ids($finals));
    [$lc0] = $estimate ? fc_find_line($estimate, $itemId) : [null];

    // a costing the page itself flags as wrong (usually rupees typed on a foreign-currency costing)
    // is not copied into Product Costing without a second, deliberate yes
    if ($lc0 && empty($_POST['sa_force'])) {
        $facts = fc_line_facts($lc0, $finals[$itemId] ?? null, $row['currency'] ?: 'PKR', fx_get_rates());
        foreach ($facts['flags'] as $f) {
            if ($f[0] === 'bad') {
                echo json_encode(['success' => false, 'confirm' => true, 'message' => $f[1] . '. Copying it would put the same mistake into Product Costing — fix the rates first, or save it anyway.']);
                exit;
            }
        }
    }

    // 2. the new costing
    $r = fc_save_as_costing($fcId, $productId, (string)($_POST['sa_new_name'] ?? ''), (string)($_POST['sa_version'] ?? ''), (string)($_POST['sa_size'] ?? ''), $uid, !$locked);
    if (!$r['ok']) fcsa_fail($r['message']);

    // 3. remember this line's name as that product, for this buyer
    $wasMatched = $lc0 && !empty($lc0['master_found']) && (int)($lc0['matched_product_id'] ?? 0) === $r['product_id'] && (int)($lc0['match_pct'] ?? 0) >= 100;
    if (!$wasMatched) {
        pm_save_alias((string)$row['product_name'], $r['product_id'], (string)($row['buyer_name'] ?? ''), $uid);
        $estimate = fc_estimate_fresh($shipmentId, fc_locked_item_ids(fc_get_for_shipment($shipmentId)));
    }

    $finals = fc_get_for_shipment($shipmentId);
    [$lc, $itemRow] = $estimate ? fc_find_line($estimate, $itemId) : [null, null];
    $html = ($lc && $itemRow) ? fc_render_line_card($lc, $itemRow, $finals[$itemId] ?? null, $row['currency'] ?: 'PKR', $shipmentId) : '';
    $msg = ($r['new_product'] ? 'New product "' . $r['product_name'] . '" made, with' : 'Added to "' . $r['product_name'] . '":')
         . ' costing "' . $r['version_name'] . '" (' . $r['costing_no'] . '), ' . $r['currency'] . ' ' . number_format($r['total'], 4) . ' per piece.';
    echo json_encode(['success' => true, 'message' => $msg, 'html' => $html, 'costing_no' => $r['costing_no'],
                      'product_id' => $r['product_id'], 'version_id' => $r['version_id']]);
    // the AI costing search learns the new costing — after the answer has gone, so nobody waits for it
    if (!empty($config['openai_enabled'])) {
        if (function_exists('fastcgi_finish_request')) { session_write_close(); fastcgi_finish_request(); }
        try { require_once __DIR__ . '/includes/ai_costing.php'; if (function_exists('aic_embed_version')) aic_embed_version((int)$r['version_id']); } catch (Throwable $e) {}
    }
} catch (Throwable $e) {
    error_log('final_costing_to_product.php: ' . $e->getMessage());
    fcsa_fail('Something went wrong: ' . $e->getMessage(), 500);
}
