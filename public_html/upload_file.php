<?php
require_once __DIR__ . '/includes/bootstrap.php';
require_login();

$id = (int)($_GET['id'] ?? $_POST['shipment_id'] ?? 0);
if (!can_view_shipment($id)) { http_response_code(403); exit('Not allowed.'); }

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verify_csrf();
    global $config;
    if (!isset($_FILES['file']) || $_FILES['file']['error'] !== UPLOAD_ERR_OK) {
        $_SESSION['error'] = 'Upload failed.';
        redirect('upload_file.php?id=' . $id);
    }
    $max = ($config['max_upload_mb'] ?? 20) * 1024 * 1024;
    if ($_FILES['file']['size'] > $max) {
        $_SESSION['error'] = 'File too large.';
        redirect('upload_file.php?id=' . $id);
    }
    $orig = basename($_FILES['file']['name']);
    $ext = strtolower(pathinfo($orig, PATHINFO_EXTENSION));
    $allowed = ['xlsx','xls','csv','pdf','jpg','jpeg','png'];
    if (!in_array($ext, $allowed, true)) {
        $_SESSION['error'] = 'File type not allowed.';
        redirect('upload_file.php?id=' . $id);
    }
    $stored = $id . '_' . bin2hex(random_bytes(12)) . '.' . $ext;
    $dir = $config['upload_dir'];
    if (!is_dir($dir)) mkdir($dir, 0775, true);
    move_uploaded_file($_FILES['file']['tmp_name'], $dir . '/' . $stored);

    db()->prepare("INSERT INTO shipment_files (shipment_id,original_name,stored_name,mime_type,file_size,uploaded_by) VALUES (?,?,?,?,?,?)")
        ->execute([$id, $orig, $stored, $_FILES['file']['type'] ?? '', $_FILES['file']['size'], current_user()['id']]);
    audit_log($id, 'File', 'upload', '', $orig, 'File uploaded');
    $_SESSION['flash'] = 'File uploaded.';
    redirect('shipment_view.php?id=' . $id);
}

page_header('Upload File');
flash();
?>
<div class="topbar"><div><h1>Upload File</h1><p class="lead">Attach original Excel, PDF, buyer PO, BL, GD or certificate.</p></div></div>
<div class="card">
<form method="post" enctype="multipart/form-data">
<?= csrf_field() ?>
<input type="hidden" name="shipment_id" value="<?= e($id) ?>">
<div class="field"><label>Select File</label><input type="file" name="file" required></div><br>
<button class="btn green">Upload</button>
<a class="btn secondary" href="shipment_view.php?id=<?= e($id) ?>">Back</a>
</form>
</div>
<?php page_footer(); ?>
