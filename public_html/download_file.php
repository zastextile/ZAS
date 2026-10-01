<?php
require_once __DIR__ . '/includes/bootstrap.php';
require_login();

$fileId = (int)($_GET['id'] ?? 0);
$stmt = db()->prepare("SELECT * FROM shipment_files WHERE id=?");
$stmt->execute([$fileId]);
$f = $stmt->fetch();
if (!$f || !can_view_shipment((int)$f['shipment_id'])) { http_response_code(404); exit('File not found.'); }

$path = $config['upload_dir'] . '/' . $f['stored_name'];
if (!is_file($path)) { http_response_code(404); exit('Missing file.'); }

header('Content-Type: application/octet-stream');
header('Content-Disposition: attachment; filename="' . basename($f['original_name']) . '"');
header('Content-Length: ' . filesize($path));
readfile($path);
exit;
