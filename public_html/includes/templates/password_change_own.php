<?php
$current = current_user();
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<title>Change Password</title>
<style>
body { background: #fff; color: #152033; font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', sans-serif; margin: 0; padding: 20px; }
.container { max-width: 500px; margin: 0 auto; }
.header { margin-bottom: 30px; }
.header h1 { margin: 0; font-size: 28px; }
.card { background: #ffffff; border: 1px solid #e3e9f2; border-radius: 14px; padding: 24px; }
.form-group { margin-bottom: 16px; }
.form-group label { display: block; font-size: 13px; font-weight: 600; margin-bottom: 6px; }
.form-group input { width: 100%; padding: 10px 12px; background: #f6f8fc; border: 1px solid #cbd5e3; border-radius: 10px; color: #152033; font-size: 14px; box-sizing: border-box; }
.form-group input::placeholder { color: #8a97ab; }
.error { background: rgba(220,38,38,.1); border: 1px solid rgba(220,38,38,.3); color: #b8283f; padding: 12px; border-radius: 10px; margin-bottom: 16px; font-size: 13px; }
.btn { display: inline-block; padding: 10px 16px; background: linear-gradient(100deg,#0ea8c9,#6d5bd0); color: #fff; border: none; border-radius: 10px; cursor: pointer; font-weight: 600; margin-right: 8px; }
.btn.secondary { background: #f6f8fc; color: #152033; }
.form-actions { margin-top: 20px; }
</style>
</head>
<body>
<div class="container">
<div class="header">
  <h1>Change Your Password</h1>
</div>

<div class="card">
  <?php if ($err): ?>
  <div class="error"><?= htmlspecialchars($err) ?></div>
  <?php endif; ?>

  <form method="POST">
    <div class="form-group">
      <label for="current">Current Password</label>
      <input type="password" id="current" name="current_password" required placeholder="Enter your current password">
    </div>
    <div class="form-group">
      <label for="new">New Password</label>
      <input type="password" id="new" name="new_password" required placeholder="At least 8 characters">
    </div>
    <div class="form-group">
      <label for="confirm">Confirm New Password</label>
      <input type="password" id="confirm" name="confirm_password" required placeholder="Confirm new password">
    </div>
    <div class="form-actions">
      <button type="submit" class="btn">Change Password</button>
      <a href="password_manage.php" class="btn secondary">Cancel</a>
    </div>
  </form>
</div>
</div>
</body>
</html>
