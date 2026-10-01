<?php
require_once __DIR__ . '/includes/bootstrap.php';
require_admin();
verify_csrf();

function zas_ensure_reopen_columns_v26(): void {
    try { db()->exec("ALTER TABLE shipments ADD COLUMN reopen_status VARCHAR(40) NULL AFTER status"); } catch (Throwable $e) {}
    try { db()->exec("ALTER TABLE shipments ADD COLUMN reopen_reason TEXT NULL AFTER reopen_status"); } catch (Throwable $e) {}
    try { db()->exec("ALTER TABLE shipments ADD COLUMN reopened_by INT NULL AFTER reopen_reason"); } catch (Throwable $e) {}
    try { db()->exec("ALTER TABLE shipments ADD COLUMN reopened_at DATETIME NULL AFTER reopened_by"); } catch (Throwable $e) {}
}
zas_ensure_reopen_columns_v26();

$id = (int)($_POST['shipment_id'] ?? $_GET['id'] ?? 0);
$stmt = db()->prepare("SELECT * FROM shipments WHERE id=?");
$stmt->execute([$id]);
$shipment = $stmt->fetch();

if (!$shipment) {
    http_response_code(404);
    exit('Shipment not found.');
}

db()->prepare("
    UPDATE shipments
    SET status='approved_locked',
        reopen_status=NULL,
        reopen_reason=NULL,
        reopened_by=NULL,
        reopened_at=NULL,
        approved_by=?,
        approved_at=NOW(),
        locked_at=NOW(),
        updated_at=NOW()
    WHERE id=?
")->execute([current_user()['id'], $id]);

audit_log($id, 'Approval', 'status', ($shipment['status'] ?? '') . ' / ' . ($shipment['reopen_status'] ?? ''), 'approved_locked', 'Admin approved and locked record');

$_SESSION['flash'] = 'Shipment approved and locked. You should now create/regenerate embedding.';
redirect('shipment_view.php?id=' . $id);
