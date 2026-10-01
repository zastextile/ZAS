<?php
require_once __DIR__ . '/includes/bootstrap.php';
require_login();

if (!is_admin()) {
    http_response_code(403);
    exit('Admin only.');
}

$action = $_GET['action'] ?? 'list';
$userId = (int)($_GET['user_id'] ?? 0);
$err = '';

if ($action === 'reset_user' && $userId && $_SERVER['REQUEST_METHOD'] === 'POST') {
    verify_csrf();
    // trimmed so a password set here (often from a PC by an admin) can't
    // end up with an invisible edge-space mismatch against what the
    // account holder actually types back on their own phone later
    $new = trim($_POST['new_password'] ?? '');
    $confirm = trim($_POST['confirm_password'] ?? '');

    if (!$new || !$confirm) {
        $err = 'Both fields required.';
    } elseif ($new !== $confirm) {
        $err = 'Passwords do not match.';
    } elseif (strlen($new) < 8) {
        $err = 'Min 8 characters.';
    } else {
        $stmt = db()->prepare("SELECT id, name FROM users WHERE id=?");
        $stmt->execute([$userId]);
        $target = $stmt->fetch();

        if ($target) {
            $hash = password_hash($new, PASSWORD_BCRYPT);
            db()->prepare("UPDATE users SET password_hash=? WHERE id=?")->execute([$hash, $userId]);
            $_SESSION['flash'] = 'Password reset for ' . $target['name'];
            header('Location: password_manage.php');
            exit;
        }
    }
}

$users = db()->query("SELECT id, name, email, role FROM users ORDER BY name")->fetchAll();
$targetUser = null;
if ($action === 'reset_user' && $userId) {
    $stmt = db()->prepare("SELECT id, name, email FROM users WHERE id=?");
    $stmt->execute([$userId]);
    $targetUser = $stmt->fetch();
}

?>
<!DOCTYPE html>
<html>
<head><title>Password Management</title></head>
<body style="background:#fff;color:#152033;font-family:Arial,sans-serif;padding:20px">
<div style="max-width:900px;margin:0 auto">

<?php if ($targetUser && $action === 'reset_user'): ?>
<h1>Reset Password for <?= htmlspecialchars($targetUser['name']) ?></h1>
<div style="background:#ffffff;padding:20px;border-radius:10px;border:1px solid #e3e9f2;max-width:500px">
<?php if ($err): ?><div style="background:rgba(220,38,38,.1);color:#b8283f;padding:10px;border-radius:8px;margin-bottom:15px"><?= htmlspecialchars($err) ?></div><?php endif; ?>
<form method="POST">
<?= csrf_field() ?>
<div style="margin-bottom:15px">
<label style="display:block;margin-bottom:5px;font-weight:bold">New Password</label>
<input type="password" name="new_password" required style="width:100%;padding:10px;border-radius:8px;border:1px solid #cbd5e3;background:#f6f8fc;color:#152033">
</div>
<div style="margin-bottom:15px">
<label style="display:block;margin-bottom:5px;font-weight:bold">Confirm Password</label>
<input type="password" name="confirm_password" required style="width:100%;padding:10px;border-radius:8px;border:1px solid #cbd5e3;background:#f6f8fc;color:#152033">
</div>
<button type="submit" style="background:linear-gradient(100deg,#0ea8c9,#6d5bd0);color:#fff;border:none;padding:10px 16px;border-radius:8px;font-weight:bold;cursor:pointer">Reset Password</button>
<a href="password_manage.php" style="margin-left:10px;color:#0ea8c9">← Back</a>
</form>
</div>

<?php else: ?>
<h1>Password Management</h1>
<div style="background:#ffffff;padding:20px;border-radius:10px;border:1px solid #e3e9f2">
<h2>Users</h2>
<?php foreach($users as $u): ?>
<div style="padding:10px;margin:10px 0;background:#f6f8fc;border-radius:8px;display:flex;justify-content:space-between;align-items:center">
<div>
<strong><?= htmlspecialchars($u['name']) ?></strong><br>
Email: <?= htmlspecialchars($u['email']) ?><br>
Role: <?= htmlspecialchars($u['role']) ?>
</div>
<a href="password_manage.php?action=reset_user&user_id=<?= (int)$u['id'] ?>" style="background:#0ea8c9;color:#fff;padding:8px 12px;border-radius:6px;text-decoration:none;font-weight:bold">Reset</a>
</div>
<?php endforeach; ?>
</div>
<?php endif; ?>

</div>
</body>
</html>
