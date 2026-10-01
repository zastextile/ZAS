<?php
$current = current_user();
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<title>Password Management</title>
<style>
body { background: #fff; color: #152033; font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', sans-serif; margin: 0; padding: 20px; }
.container { max-width: 900px; margin: 0 auto; }
.header { margin-bottom: 30px; }
.header h1 { margin: 0; font-size: 32px; }
.card { background: #ffffff; border: 1px solid #e3e9f2; border-radius: 14px; padding: 24px; margin-bottom: 20px; }
.btn { display: inline-block; padding: 10px 16px; background: linear-gradient(100deg,#0ea8c9,#6d5bd0); color: #fff; border: none; border-radius: 10px; cursor: pointer; font-weight: 600; text-decoration: none; }
.btn.secondary { background: #f6f8fc; color: #152033; }
.user-list { display: grid; gap: 12px; }
.user-item { display: flex; justify-content: space-between; align-items: center; padding: 12px; background: #f6f8fc; border-radius: 10px; border: 1px solid #e3e9f2; }
.user-info { flex: 1; }
.user-name { font-weight: 600; font-size: 14px; }
.user-email { font-size: 12px; color: #5a6b82; margin-top: 4px; }
.user-role { display: inline-block; margin-top: 4px; padding: 3px 8px; background: rgba(22,163,74,.1); color: #127a3f; border-radius: 6px; font-size: 11px; }
.form-group { margin-bottom: 16px; }
.form-group label { display: block; font-size: 13px; font-weight: 600; margin-bottom: 6px; }
.form-group input { width: 100%; padding: 10px 12px; background: #f6f8fc; border: 1px solid #cbd5e3; border-radius: 10px; color: #152033; font-size: 14px; }
.form-group input::placeholder { color: #8a97ab; }
.error { background: rgba(220,38,38,.1); border: 1px solid rgba(220,38,38,.3); color: #b8283f; padding: 12px; border-radius: 10px; margin-bottom: 16px; font-size: 13px; }
.success { background: rgba(22,163,74,.1); border: 1px solid rgba(22,163,74,.3); color: #127a3f; padding: 12px; border-radius: 10px; margin-bottom: 16px; font-size: 13px; }
.form-actions { display: flex; gap: 10px; }
</style>
</head>
<body>
<div class="container">
<div class="header">
  <h1>Password Management</h1>
  <p style="margin: 8px 0 0; color: #5a6b82;">Change your password or reset user passwords</p>
</div>

<div class="card">
  <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 16px;">
    <h2 style="margin: 0; font-size: 18px;">Users</h2>
    <a href="password_manage.php?action=change_own" class="btn">Change Your Password</a>
  </div>
  <div class="user-list">
    <?php foreach ($users as $u): ?>
    <div class="user-item">
      <div class="user-info">
        <div class="user-name"><?= htmlspecialchars($u['name']) ?></div>
        <div class="user-email"><?= htmlspecialchars($u['email']) ?></div>
        <span class="user-role"><?= ucfirst($u['role']) ?></span>
      </div>
      <a href="password_manage.php?action=reset_user&user_id=<?= (int)$u['id'] ?>" class="btn secondary">Reset</a>
    </div>
    <?php endforeach; ?>
  </div>
</div>

<div style="text-align: center; margin-top: 30px;">
  <a href="dashboard.php" class="btn secondary">← Back to Dashboard</a>
</div>
</div>
</body>
</html>
