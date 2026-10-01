<?php
require_once __DIR__ . '/includes/bootstrap.php';
require_login();

$id = (int)($_GET['id'] ?? 0);
if (!can_view_shipment($id)) { http_response_code(403); exit('Not allowed.'); }

$stmt = db()->prepare("SELECT * FROM audit_logs WHERE shipment_id=? ORDER BY id DESC");
$stmt->execute([$id]);
$logs = $stmt->fetchAll();

page_header('Audit Log');
?>
<div class="topbar"><div><h1>Audit Log</h1><p class="lead">Every important shipment change is recorded here.</p></div><a class="btn secondary" href="shipment_view.php?id=<?= e($id) ?>">Back</a></div>
<div class="card">
<?php foreach($logs as $log): ?>
  <div class="audit-entry">
    <div class="audit-time"><?= e($log['created_at']) ?><br><?= e($log['user_name']) ?><br><?= e($log['ip_address']) ?></div>
    <div class="audit-text">
      <strong><?= e($log['section_changed']) ?> / <?= e($log['field_changed']) ?></strong><br>
      <b>Reason:</b> <?= e($log['change_reason']) ?><br>
      <details><summary>Old / New value</summary>
        <pre><?= e($log['old_value']) ?></pre>
        <pre><?= e($log['new_value']) ?></pre>
      </details>
    </div>
  </div>
<?php endforeach; ?>
</div>
<?php page_footer(); ?>
