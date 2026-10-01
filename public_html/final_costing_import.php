<?php
require_once __DIR__ . '/includes/bootstrap.php';
require_once __DIR__ . '/includes/final_costing.php';
require_login();
require_admin();
fc_ensure_schema();

$shipmentId = (int)($_POST['shipment_id'] ?? 0);
$st = db()->prepare("SELECT * FROM shipments WHERE id=?");
$st->execute([$shipmentId]);
$shipment = $st->fetch();
if (!$shipment) { http_response_code(404); exit('Shipment not found.'); }

function fc_num($v): float { $s = preg_replace('/[^0-9.\-]/', '', (string)$v); return is_numeric($s) ? (float)$s : 0.0; }

/* ===== PHASE 2: confirm & save (from the preview page) ===== */
if (($_POST['action'] ?? '') === 'confirm') {
    verify_csrf();
    $token = $_POST['import_token'] ?? '';
    $pending = $_SESSION['fc_import_' . $token] ?? null;
    if (!$pending || (int)$pending['shipment_id'] !== $shipmentId) {
        $_SESSION['error'] = 'Import session expired — please upload the CSV again.';
        redirect('final_costing.php?shipment_id=' . $shipmentId);
    }
    $uid = current_user()['id'];
    $saved = 0; $skippedLocked = 0;
    $finals = fc_get_for_shipment($shipmentId);
    foreach ($pending['groups'] as $itemId => $rows) {
        $fc = $finals[$itemId] ?? null;
        if (!$fc) continue;
        if ($fc['status'] === 'locked') { $skippedLocked++; continue; }
        fc_save_lines((int)$fc['id'], $rows, $uid);
        $saved++;
    }
    unset($_SESSION['fc_import_' . $token]);
    $_SESSION['flash'] = "Import saved for {$saved} line(s)." . ($skippedLocked ? " {$skippedLocked} line(s) skipped — already locked." : '');
    redirect('final_costing.php?shipment_id=' . $shipmentId);
}

/* ===== PHASE 1: upload, parse, validate, show preview ===== */
verify_csrf();
if (empty($_FILES['csv_file']['tmp_name']) || !is_uploaded_file($_FILES['csv_file']['tmp_name'])) {
    $_SESSION['error'] = 'No file uploaded.';
    redirect('final_costing.php?shipment_id=' . $shipmentId);
}

$validItemIds = [];
$itemsSt = db()->prepare("SELECT id, line_no, product_name FROM shipment_items WHERE shipment_id=?");
$itemsSt->execute([$shipmentId]);
$itemNames = [];
foreach ($itemsSt->fetchAll() as $r) { $validItemIds[(int)$r['id']] = true; $itemNames[(int)$r['id']] = 'Line ' . $r['line_no'] . ' · ' . $r['product_name']; }

$finals = fc_get_for_shipment($shipmentId);

$fh = fopen($_FILES['csv_file']['tmp_name'], 'r');
$header = fgetcsv($fh);
$groups = []; // itemId => [lines]
$warnings = [];
$rowNum = 1;
while (($row = fgetcsv($fh)) !== false) {
    $rowNum++;
    if (count(array_filter($row, fn($v) => trim((string)$v) !== '')) === 0) continue; // fully blank row
    $itemId = (int)($row[0] ?? 0);
    $group = trim($row[3] ?? ''); $item = trim($row[4] ?? ''); $desc = trim($row[5] ?? '');
    $qty = fc_num($row[6] ?? 0); $unit = trim($row[7] ?? ''); $weight = fc_num($row[8] ?? 0);
    $rate = fc_num($row[9] ?? 0); $sharedFlag = strtoupper(trim($row[10] ?? '')) === 'Y';

    if (!isset($validItemIds[$itemId])) { $warnings[] = "Row {$rowNum}: Item ID {$itemId} doesn't belong to this shipment — skipped."; continue; }
    if ($item === '') { if ($qty || $rate) $warnings[] = "Row {$rowNum}: material name is blank — skipped."; continue; }
    if ($qty < 0 || $rate < 0) $warnings[] = "Row {$rowNum} ({$item}): negative qty or rate — kept as entered, please double-check.";

    $groups[$itemId][] = ['line_group' => $group ?: 'Other', 'item_name' => $item, 'description' => $desc, 'quantity' => $qty, 'unit' => $unit, 'weight_kg' => $weight, 'rate' => $rate, 'shared' => $sharedFlag];
}
fclose($fh);

foreach ($groups as $itemId => $rows) {
    $newTotal = 0; foreach ($rows as $r) $newTotal += fc_line_amount($r);
    $fc = $finals[$itemId] ?? null;
    $oldTotal = $fc ? (float)$fc['total_cost'] : 0;
    if ($oldTotal > 0 && abs($newTotal - $oldTotal) / $oldTotal > 0.5) {
        $warnings[] = ($itemNames[$itemId] ?? "Item {$itemId}") . ": new total " . number_format($newTotal, 2) . " is " . round((($newTotal - $oldTotal) / $oldTotal) * 100) . "% different from the current " . number_format($oldTotal, 2) . " — please review before confirming.";
    }
    if ($fc && $fc['status'] === 'locked') {
        $warnings[] = ($itemNames[$itemId] ?? "Item {$itemId}") . " is already LOCKED — these rows will be skipped unless you reopen it first.";
    }
}

if (!$groups) {
    $_SESSION['error'] = 'No usable rows found in that CSV.';
    redirect('final_costing.php?shipment_id=' . $shipmentId);
}

$token = bin2hex(random_bytes(8));
$_SESSION['fc_import_' . $token] = ['shipment_id' => $shipmentId, 'groups' => $groups];

page_header('Import Preview — ' . $shipment['invoice_no']);
?>
<style>
.zcard{padding:15px;border-radius:18px;background:#ffffff;border:1px solid #e3e9f2;margin-bottom:11px}
.zbtn{padding:11px 18px;border:none;border-radius:11px;cursor:pointer;font-weight:700;font-size:13px;color:#fff;background:linear-gradient(100deg,#0ea8c9,#6d5bd0);text-decoration:none;display:inline-block}
.zbtn.sec{background:#f6f8fc;color:#152033;border:1px solid #cbd5e3}
.ztable{width:100%;border-collapse:collapse;font-size:12.5px}
.ztable th{background:#f6f8fc;color:#5a6b82;font-size:10px;text-transform:uppercase;padding:5px 8px;text-align:left}
.ztable td{padding:3px 8px;border-top:1px solid #f6f8fc}
.warn{padding:12px 14px;border-radius:10px;background:rgba(217,119,6,.1);border:1px solid rgba(217,119,6,.28);color:#a25c04;font-size:12.5px;margin-bottom:10px}
</style>
<div class="topbar"><div><h1>Import Preview</h1><p class="lead"><?= e($shipment['invoice_no']) ?> — nothing has been saved yet. Review below, then confirm.</p></div></div>

<?php if ($warnings): ?>
<div class="zcard">
  <h2 style="font-size:14px;margin:0 0 10px">⚠ Warnings (<?= count($warnings) ?>)</h2>
  <?php foreach ($warnings as $w): ?><div class="warn"><?= e($w) ?></div><?php endforeach; ?>
</div>
<?php endif; ?>

<?php foreach ($groups as $itemId => $rows): ?>
<div class="zcard">
  <h2 style="font-size:14px;margin:0 0 10px"><?= e($itemNames[$itemId] ?? "Item {$itemId}") ?> — <?= count($rows) ?> line(s)</h2>
  <div style="overflow-x:auto"><table class="ztable">
    <thead><tr><th>Group</th><th>Item</th><th>Qty</th><th>Unit</th><th>Weight</th><th>Rate</th><th>Shared</th><th>Amount</th></tr></thead>
    <tbody>
    <?php $tot = 0; foreach ($rows as $r): $amt = fc_line_amount($r); $tot += $amt; ?>
      <tr><td><?= e($r['line_group']) ?></td><td><?= e($r['item_name']) ?></td><td><?= e($r['quantity']) ?></td><td><?= e($r['unit']) ?></td><td><?= e($r['weight_kg']) ?></td><td><?= number_format($r['rate'],4) ?></td><td><?= $r['shared']?'Yes':'No' ?></td><td><?= number_format($amt,2) ?></td></tr>
    <?php endforeach; ?>
    <tr style="font-weight:700"><td colspan="7">Total</td><td><?= number_format($tot,2) ?></td></tr>
    </tbody>
  </table></div>
</div>
<?php endforeach; ?>

<form method="post" action="final_costing_import.php" style="display:flex;gap:10px">
  <?= csrf_field() ?>
  <input type="hidden" name="action" value="confirm">
  <input type="hidden" name="shipment_id" value="<?= (int)$shipmentId ?>">
  <input type="hidden" name="import_token" value="<?= e($token) ?>">
  <button class="zbtn">Confirm &amp; Save</button>
  <a class="zbtn sec" href="final_costing.php?shipment_id=<?= (int)$shipmentId ?>">Cancel</a>
</form>

<?php page_footer(); ?>
