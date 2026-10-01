<?php
require_once __DIR__ . '/includes/bootstrap.php';
require_admin();
verify_csrf();

$symbols = fx_symbols($config);
$input = [];
foreach ($symbols as $cur) {
    $key = 'rate_' . strtolower($cur);
    if (isset($_POST[$key]) && $_POST[$key] !== '') {
        $input[$cur] = $_POST[$key];
    }
}

$result = fx_save_rates($input);
if ($result['ok']) {
    $_SESSION['flash'] = 'FX rates updated — ' . $result['message'];
} else {
    $_SESSION['error'] = 'FX rates not saved: ' . $result['message'];
}

$back = $_POST['return_to'] ?? 'settings.php';
if (!preg_match('/^[a-zA-Z0-9_\-\.]+\.php$/', $back)) $back = 'settings.php';
redirect($back);
