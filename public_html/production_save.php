<?php
require_once __DIR__ . '/includes/bootstrap.php';
require_login();
if (!is_admin() && !is_production_staff()) { http_response_code(403); header('Content-Type: application/json'); echo json_encode(['ok' => false, 'errors' => ['Not authorized.']]); exit; }
require_once __DIR__ . '/includes/production.php';
production_ensure_schema();
header('Content-Type: application/json');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') { echo json_encode(['ok' => false, 'errors' => ['POST required.']]); exit; }
verify_csrf();

$raw = $_POST['entries'] ?? '';
$date = trim($_POST['production_date'] ?? '');
if ($date === '' || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $date)) $date = date('Y-m-d');
if ($date > date('Y-m-d')) { echo json_encode(['ok' => false, 'errors' => ['Production date cannot be in the future.']]); exit; }

$entries = json_decode($raw, true);
if (!is_array($entries) || !$entries) { echo json_encode(['ok' => false, 'errors' => ['No rows to save.']]); exit; }
if (count($entries) > 100) { echo json_encode(['ok' => false, 'errors' => ['Too many rows in one submit (max 100).']]); exit; }

$allowedProformaIds = is_admin() ? null : production_assigned_proforma_ids((int)current_user()['id']);
$result = production_validate_and_save($entries, $date, (int)current_user()['id'], $allowedProformaIds);
echo json_encode($result);
