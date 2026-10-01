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
$reason = trim($_POST['reopen_reason'] ?? '');

if ($reason === '') {
    $_SESSION['error'] = 'Reopen reason is required.';
    redirect('shipment_view.php?id=' . $id);
}

$stmt = db()->prepare("SELECT * FROM shipments WHERE id=?");
$stmt->execute([$id]);
$shipment = $stmt->fetch();

if (!$shipment) {
    http_response_code(404);
    exit('Shipment not found.');
}

try {
    db()->beginTransaction();

    /*
      Important:
      Admin viewing/opening the shipment does NOT allow colleague editing.
      Only this controlled reopen action allows assigned colleague to edit.
    */
    db()->prepare("
        UPDATE shipments
        SET status='submitted',
            reopen_status='reopened_for_correction',
            reopen_reason=?,
            reopened_by=?,
            reopened_at=NOW(),
            locked_at=NULL,
            updated_by=?,
            updated_at=NOW(),
            revision_no=revision_no+1
        WHERE id=?
    ")->execute([
        $reason,
        current_user()['id'],
        current_user()['id'],
        $id
    ]);

    db()->prepare("UPDATE shipment_embeddings SET is_active=0 WHERE shipment_id=?")->execute([$id]);

    audit_log(
        $id,
        'Reopen',
        'status',
        ($shipment['status'] ?? '') . ' / ' . ($shipment['reopen_status'] ?? ''),
        'submitted / reopened_for_correction',
        $reason
    );

    db()->commit();

    $_SESSION['flash'] = 'Shipment reopened for correction. Assigned colleague can edit now.';
} catch (Throwable $e) {
    if (db()->inTransaction()) db()->rollBack();
    $_SESSION['error'] = $e->getMessage();
}

redirect('shipment_view.php?id=' . $id);
