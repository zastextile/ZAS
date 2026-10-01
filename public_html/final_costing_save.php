<?php
require_once __DIR__ . '/includes/bootstrap.php';
require_once __DIR__ . '/includes/pm_search.php';
require_once __DIR__ . '/includes/ai_check_core.php';
require_once __DIR__ . '/includes/final_costing.php';

$isAjax = ($_POST['ajax'] ?? '') === '1';
$shipmentId = (int)($_POST['shipment_id'] ?? 0);

// AJAX-safe failure for every early-exit below — require_login()/require_admin()/
// verify_csrf() normally redirect or exit with plain text on failure (session
// expired, wrong role, stale CSRF token), any of which breaks the AJAX
// response parser and shows as a generic "Network error" with no way to tell
// what actually happened. Checking those conditions here first, before any
// output, means a real reason always comes back instead.
function fcsave_fail(bool $isAjax, string $message, int $shipmentId = 0, int $httpCode = 400): never
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

if (!current_user()) fcsave_fail($isAjax, 'Your session has expired — please refresh the page and sign in again.', $shipmentId, 401);
if (!is_admin()) fcsave_fail($isAjax, 'Admin access required.', $shipmentId, 403);
if (!hash_equals($_SESSION['_csrf'] ?? '', $_POST['_csrf'] ?? '')) fcsave_fail($isAjax, 'Security token expired — please refresh the page and try again.', $shipmentId, 419);

fc_ensure_schema();

$fcId = (int)($_POST['final_costing_id'] ?? 0);
$groups = post_array('line_group');
$items = post_array('item_name');
$descriptions = post_array('description');
$qtys = post_array('quantity');
$units = post_array('unit');
$weights = post_array('weight_kg');
$rates = post_array('rate');
$shared = post_array('shared'); // hidden field per row, value '1' or '0' (toggled by the ÷ button)
$nativeCurrencies = post_array('native_currency'); // hidden field per row — which currency this line's rate is in

$lines = [];
foreach ($items as $i => $name) {
    if (trim((string)$name) === '') continue; // skip blank rows (e.g. unused add-row slots)
    $lines[] = [
        'line_group' => $groups[$i] ?? 'Other',
        'item_name' => $name,
        'description' => $descriptions[$i] ?? '',
        'quantity' => $qtys[$i] ?? 0,
        'unit' => $units[$i] ?? '',
        'weight_kg' => $weights[$i] ?? 0,
        'rate' => $rates[$i] ?? 0,
        'shared' => ($shared[$i] ?? '0') === '1',
        'native_currency' => $nativeCurrencies[$i] ?? '',
    ];
}

try {
    $result = fc_save_lines($fcId, $lines, current_user()['id']);

    if ($isAjax) {
        header('Content-Type: application/json');
        if (!$result['ok']) { echo json_encode(['success' => false, 'message' => $result['message']]); exit; }
        $st = db()->prepare("SELECT shipment_item_id FROM final_costings WHERE id=?"); $st->execute([$fcId]);
        $itemId = (int)$st->fetchColumn();
        $st = db()->prepare("SELECT currency FROM shipments WHERE id=?"); $st->execute([$shipmentId]);
        $cur = $st->fetchColumn() ?: 'PKR';
        $finals = fc_get_for_shipment($shipmentId);
        /* Save Draft reuses the cached estimate. See the rule at the top of
           includes/final_costing.php.

           This USED to force a full recompute — every product, every costing
           version, and a live AI call per unconfirmed match — to redraw one
           card. It was doing that to stop the match banner and "Estimated
           cost/unit" reading up to 10 minutes stale, which was a real problem
           but not one that Save caused: nothing you can type into this form
           changes which product a line is matched to.

           The staleness is now fixed where it actually originates —
           shipment_save.php bumps this cache when the invoice lines change —
           so Save Draft no longer has to pay for someone else's problem. It
           is now as fast as Lock, which never did this. */
        $estimate = fc_estimate_cached($shipmentId, fc_locked_item_ids($finals));
        [$lc, $itemRow] = fc_find_line($estimate, $itemId);
        $html = ($lc && $itemRow) ? fc_render_line_card($lc, $itemRow, $finals[$itemId] ?? null, $cur, $shipmentId) : '';
        echo json_encode(['success' => true, 'message' => 'Final costing saved — total ' . number_format($result['total'], 2) . '.', 'html' => $html]);
        exit;
    }

    if ($result['ok']) {
        $_SESSION['flash'] = 'Final costing saved — total ' . number_format($result['total'], 2) . '.';
    } else {
        $_SESSION['error'] = $result['message'];
    }
    redirect('final_costing.php?shipment_id=' . $shipmentId);
} catch (Throwable $e) {
    error_log('final_costing_save.php: ' . $e->getMessage());
    fcsave_fail($isAjax, 'Something went wrong saving the draft: ' . $e->getMessage(), $shipmentId, 500);
}
