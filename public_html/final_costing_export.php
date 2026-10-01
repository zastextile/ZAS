<?php
require_once __DIR__ . '/includes/bootstrap.php';
require_once __DIR__ . '/includes/final_costing.php';
require_login();
require_admin();
fc_ensure_schema();

$shipmentId = (int)($_GET['shipment_id'] ?? 0);
$st = db()->prepare("SELECT * FROM shipments WHERE id=?");
$st->execute([$shipmentId]);
$shipment = $st->fetch();
if (!$shipment) { http_response_code(404); exit('Shipment not found.'); }

$itemsSt = db()->prepare("SELECT * FROM shipment_items WHERE shipment_id=? ORDER BY line_no,id");
$itemsSt->execute([$shipmentId]);
$items = $itemsSt->fetchAll();
$finals = fc_get_for_shipment($shipmentId);

$fname = 'final_costing_' . preg_replace('/[^A-Za-z0-9_\-]/', '_', $shipment['invoice_no'] ?: ('shipment_' . $shipmentId)) . '.csv';
header('Content-Type: text/csv; charset=utf-8');
header('Content-Disposition: attachment; filename="' . $fname . '"');

$out = fopen('php://output', 'w');
fwrite($out, "\xEF\xBB\xBF");
fputcsv($out, ['Item ID (do not change)', 'Line No', 'Product', 'Group', 'Item', 'Description', 'Qty', 'Unit', 'Weight (kg)', 'Rate', 'Shared (Y/N)', 'Amount (reference only, recalculated on import)']);
foreach ($items as $it) {
    $fc = $finals[(int)$it['id']] ?? null;
    $lines = $fc['lines'] ?? [];
    if (!$lines) {
        fputcsv($out, [(int)$it['id'], $it['line_no'], $it['product_name'], '', '', '', '', '', '', '', '', '']);
        continue;
    }
    foreach ($lines as $l) {
        fputcsv($out, [
            (int)$it['id'], $it['line_no'], $it['product_name'],
            $l['line_group'], $l['item_name'], $l['description'],
            rtrim(rtrim(number_format((float)$l['quantity'], 3, '.', ''), '0'), '.'),
            $l['unit'],
            rtrim(rtrim(number_format((float)$l['weight_kg'], 3, '.', ''), '0'), '.'),
            number_format((float)$l['rate'], 2, '.', ''),
            $l['shared'] ? 'Y' : 'N',
            number_format((float)$l['amount'], 2, '.', ''),
        ]);
    }
}
fclose($out);
