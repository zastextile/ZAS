<?php
require_once __DIR__ . '/includes/bootstrap.php';
require_login();

$u = current_user();
$msg = ''; $err = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verify_csrf();
    // trimmed on all three so a mobile keyboard's trailing-space quirk
    // can't cause "Current password is incorrect" on a password that's
    // actually correct, or save a new password with a stray edge-space
    // baked in (see the matching fix in login.php)
    $cur = trim($_POST['current_password'] ?? '');
    $new = trim($_POST['new_password'] ?? '');
    $cnf = trim($_POST['confirm_password'] ?? '');
    $row = db()->prepare("SELECT password_hash FROM users WHERE id=?");
    $row->execute([$u['id']]);
    $hash = $row->fetchColumn();
    if (!$hash || !password_verify($cur, $hash)) {
        $err = 'Current password is incorrect.';
    } elseif (strlen($new) < 8) {
        $err = 'New password must be at least 8 characters.';
    } elseif ($new !== $cnf) {
        $err = 'New password and confirmation do not match.';
    } else {
        db()->prepare("UPDATE users SET password_hash=? WHERE id=?")->execute([password_hash($new, PASSWORD_DEFAULT), $u['id']]);
        try { audit_log(0, 'Profile', 'password_change', $u['name'], 'Own password changed', ''); } catch (Throwable $e) {}
        $msg = 'Your password has been updated.';
    }
}

page_header('My Profile');
flash();
?>
<style>
.zcard{padding:15px;border-radius:18px;background:#ffffff;border:1px solid #e3e9f2;backdrop-filter:blur(12px);margin-bottom:11px}
.zcard h2{font-size:15px;margin:0 0 16px}
.zgrid{display:grid;grid-template-columns:repeat(auto-fit,minmax(200px,1fr));gap:14px}
.zlabel{font-size:12px;color:#5a6b82;display:block}
.zin{display:block;width:100%;margin-top:6px;padding:10px 12px;border-radius:10px;border:1px solid #cbd5e3;background:#ffffff;color:#152033;font-size:13.5px;outline:none;font-family:inherit}
.zin:focus{border-color:#0ea8c9}
.zbtn{padding:11px 18px;border:none;border-radius:11px;cursor:pointer;font-weight:700;font-size:13px;color:#fff;background:linear-gradient(100deg,#0ea8c9,#6d5bd0)}
.zalert{padding:14px 18px;border-radius:14px;margin-bottom:18px;font-size:13px}
.zalert.ok{background:rgba(22,163,74,.1);border:1px solid rgba(22,163,74,.28);color:#127a3f}
.zalert.err{background:rgba(224,67,93,.1);border:1px solid rgba(224,67,93,.28);color:#b8283f}
.info div{margin-bottom:12px}
.info .lbl{font-size:11px;text-transform:uppercase;letter-spacing:.04em;color:#5a6b82}
.info .val{font-size:14px;color:#152033;margin-top:3px;font-weight:600}
</style>

<div class="topbar"><div><h1>My Profile</h1><p class="lead">Your account details &amp; password.</p></div>
  <a href="logout.php" style="padding:11px 18px;border-radius:11px;font-weight:700;font-size:13px;background:rgba(224,67,93,.14);color:#b8283f;border:1px solid rgba(224,67,93,.3);text-decoration:none">Logout</a>
</div>

<?php if($msg): ?><div class="zalert ok"><?= e($msg) ?></div><?php endif; ?>
<?php if($err): ?><div class="zalert err"><?= e($err) ?></div><?php endif; ?>

<div class="zcard">
  <h2>Account</h2>
  <div class="zgrid info">
    <div><div class="lbl">Name</div><div class="val"><?= e($u['name']) ?></div></div>
    <div><div class="lbl">Login ID / Email</div><div class="val"><?= e($u['email']) ?></div></div>
    <div><div class="lbl">Role</div><div class="val"><?= e(ucwords(str_replace('_',' ',$u['role']))) ?></div></div>
    <div><div class="lbl">Department</div><div class="val"><?= e($u['department'] ?: '—') ?></div></div>
    <div><div class="lbl">Rate Visibility</div><div class="val"><?= can_see_rates() ? 'Visible' : 'Hidden' ?></div></div>
  </div>
</div>

<div class="zcard">
  <h2>Change Password</h2>
  <form method="post">
    <?= csrf_field() ?>
    <div class="zgrid">
      <label class="zlabel">Current Password<input class="zin" type="password" name="current_password" required></label>
      <label class="zlabel">New Password <span style="color:#8a97ab">(min 8 chars)</span><input class="zin" type="password" name="new_password" minlength="8" required></label>
      <label class="zlabel">Confirm New Password<input class="zin" type="password" name="confirm_password" minlength="8" required></label>
    </div>
    <button class="zbtn" style="margin-top:16px">Update Password</button>
  </form>
</div>
<?php page_footer(); ?>
