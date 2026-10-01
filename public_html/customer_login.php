<?php
require_once __DIR__ . '/includes/bootstrap.php';
require_once __DIR__ . '/includes/customer.php';
customer_ensure_schema();

customer_try_remember_login();
if (is_customer()) redirect('customer_dashboard.php');

$error = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verify_csrf();
    $email = strtolower(trim($_POST['email'] ?? ''));
    // same mobile-autocorrect trailing-space fix as the internal login.php
    $password = trim($_POST['password'] ?? '');

    $stmt = db()->prepare("SELECT * FROM users WHERE LOWER(email) = ? AND is_active = 1 AND role = 'customer'");
    $stmt->execute([$email]);
    $user = $stmt->fetch();

    if ($user && password_verify($password, $user['password_hash'])) {
        session_regenerate_id(true);
        $_SESSION['user_id'] = $user['id'];
        // Always remembered — this portal has no "remember me" checkbox by
        // design, so signing in once is enough until they tap Logout.
        customer_issue_remember_cookie((int)$user['id']);
        redirect('customer_dashboard.php');
    }
    $error = 'Invalid login details.';
}
?>
<!doctype html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">
<title>Sign In - ZAS Textile</title>
<link rel="manifest" href="manifest_customer.json">
<meta name="theme-color" content="#0ea8c9">
<link rel="apple-touch-icon" href="assets/icons/apple-touch-icon.png">
<style>
:root{--bg:#eef1f6;--paper:#ffffff;--ink:#152033;--sub:#5a6b82;--accent1:#0ea8c9;--accent2:#6d5bd0}
*{box-sizing:border-box}
body{margin:0;background:var(--bg);color:var(--ink);font:14px/1.5 "Segoe UI",Arial,sans-serif;min-height:100vh;display:flex;align-items:center;justify-content:center;padding:24px}
.card{width:100%;max-width:360px;background:var(--paper);border-radius:20px;padding:32px 26px;text-align:center;box-shadow:0 20px 50px rgba(0,0,0,.08)}
.mark{width:56px;height:56px;border-radius:16px;background:linear-gradient(135deg,var(--accent1),var(--accent2));display:flex;align-items:center;justify-content:center;color:#fff;font-weight:800;font-size:20px;margin:0 auto 16px}
h1{font-size:18px;margin:0 0 2px}
.sub{font-size:12.5px;color:var(--sub);margin:0 0 22px}
.field{text-align:left;margin-bottom:14px}
.field label{font-size:11px;font-weight:700;color:var(--sub);text-transform:uppercase;letter-spacing:.03em}
.field input{width:100%;margin-top:6px;padding:12px 14px;border-radius:11px;border:1px solid #cbd5e3;background:#fff;color:var(--ink);font-size:14px;font-family:inherit}
.btn{width:100%;padding:13px;border:none;border-radius:11px;background:linear-gradient(100deg,var(--accent1),var(--accent2));color:#fff;font-weight:700;font-size:14px;cursor:pointer;margin-top:6px}
.note{font-size:11px;color:#8a97ab;line-height:1.6;margin-top:16px}
.alert{padding:11px 14px;border-radius:11px;margin-bottom:16px;font-size:12.5px;background:rgba(224,67,93,.08);border:1px solid rgba(224,67,93,.24);color:#b8283f;text-align:left}
</style>
</head>
<body>
<div class="card">
  <div class="mark">ZT</div>
  <h1>Sign In</h1>
  <p class="sub">ZAS Textile — Order Portal</p>
  <?php if ($error): ?><div class="alert"><?= e($error) ?></div><?php endif; ?>
  <form method="post">
    <?= csrf_field() ?>
    <div class="field"><label>Email</label><input type="email" name="email" autocomplete="username" autocapitalize="off" spellcheck="false" required></div>
    <div class="field"><label>Password</label><input type="password" name="password" autocomplete="current-password" required></div>
    <button type="submit" class="btn">Sign In</button>
  </form>
  <div class="note">You'll stay signed in on this device until you tap Logout — no need to sign in again each visit.</div>
</div>
</body>
</html>
