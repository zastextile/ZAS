<?php
require_once __DIR__ . '/includes/bootstrap.php';
require_admin();
verify_csrf();

$id = (int)($_POST['shipment_id'] ?? 0);
$stmt = db()->prepare("SELECT * FROM shipments WHERE id=?");
$stmt->execute([$id]);
$shipment = $stmt->fetch();

if (!$shipment) {
    http_response_code(404);
    exit('Shipment not found.');
}

// Hard guard, independent of what the UI shows: only a DRAFT shipment can
// ever be deleted here, even if someone tampers with the request directly.
if ($shipment['status'] !== 'draft') {
    $_SESSION['error'] = 'Only Draft shipments can be deleted. This one is ' . str_replace('_', ' ', $shipment['status']) . ' — reopen it to Draft first if you really need to remove it.';
    redirect('shipment_view.php?id=' . $id);
}

$password = (string)($_POST['confirm_password'] ?? '');
$me = current_user();
if ($password === '' || !password_verify($password, $me['password_hash'] ?? '')) {
    $_SESSION['error'] = 'Incorrect password — shipment was NOT deleted.';
    redirect('shipment_view.php?id=' . $id);
}

audit_log($id, 'Deletion', 'shipment', [
    'invoice_no'  => $shipment['invoice_no'],
    'buyer_name'  => $shipment['buyer_name'],
    'status'      => $shipment['status'],
], 'DELETED', 'Draft shipment permanently deleted by admin (password-confirmed).');

// Remove any uploaded files from disk — the DB rows cascade-delete with the
// shipment, but the physical files would otherwise be left orphaned.
try {
    global $config;
    $filesStmt = db()->prepare("SELECT stored_name FROM shipment_files WHERE shipment_id=?");
    $filesStmt->execute([$id]);
    foreach ($filesStmt->fetchAll() as $f) {
        $path = $config['upload_dir'] . '/' . $f['stored_name'];
        if (is_file($path)) @unlink($path);
    }
} catch (Throwable $e) {}

// Best-effort cleanup of tables without a foreign key to shipments.
try { db()->prepare("DELETE FROM ai_check_cache WHERE shipment_id=?")->execute([$id]); } catch (Throwable $e) {}

$invoiceNo = $shipment['invoice_no'];
db()->prepare("DELETE FROM shipments WHERE id=?")->execute([$id]);

$_SESSION['flash'] = 'Shipment ' . $invoiceNo . ' was permanently deleted.';
redirect('shipments.php');
