<?php
require_once __DIR__ . '/includes/bootstrap.php';
require_admin();

$id = (int)($_GET['id'] ?? $_POST['shipment_id'] ?? 0);
$stmt = db()->prepare("SELECT * FROM shipments WHERE id=?");
$stmt->execute([$id]);
$shipment = $stmt->fetch();
if (!$shipment) { http_response_code(404); exit('Shipment not found.'); }

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verify_csrf();
    db()->prepare("DELETE FROM shipment_assignments WHERE shipment_id=?")->execute([$id]);
    foreach (post_array('user_ids') as $uid) {
        db()->prepare("INSERT IGNORE INTO shipment_assignments (shipment_id,user_id,assigned_by) VALUES (?,?,?)")
            ->execute([$id, (int)$uid, current_user()['id']]);
    }
    audit_log($id, 'Assignment', 'users', '', json_encode(post_array('user_ids')), 'Admin assigned shipment users');
    $_SESSION['flash'] = 'Assignments saved.';
    redirect('shipment_view.php?id=' . $id);
}

$users = db()->query("SELECT * FROM users WHERE is_active=1 ORDER BY role,name")->fetchAll();
$assigned = db()->prepare("SELECT user_id FROM shipment_assignments WHERE shipment_id=?");
$assigned->execute([$id]);
$assigned = array_map('intval', array_column($assigned->fetchAll(), 'user_id'));

page_header('Assign Users');
?>
<div class="topbar"><div><h1>Assign Users</h1><p class="lead">Assign access for invoice <?= e($shipment['invoice_no']) ?>.</p></div></div>
<div class="card">
<form method="post">
<?= csrf_field() ?>
<input type="hidden" name="shipment_id" value="<?= e($id) ?>">
<?php foreach($users as $u): ?>
  <label style="display:block;margin:10px 0">
    <input type="checkbox" name="user_ids[]" value="<?= e($u['id']) ?>" <?= in_array((int)$u['id'],$assigned)?'checked':'' ?>>
    <?= e($u['name']) ?> - <?= e($u['role']) ?> <?= $u['can_see_rates'] ? '(rates visible)' : '' ?>
  </label>
<?php endforeach; ?>
<br><button class="btn green">Save Assignment</button>
<a class="btn secondary" href="shipment_view.php?id=<?= e($id) ?>">Cancel</a>
</form>
</div>
<?php page_footer(); ?>
