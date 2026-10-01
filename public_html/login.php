<?php
require_once __DIR__ . '/includes/bootstrap.php';

if ($u = current_user()) redirect($u['role'] === 'customer' ? 'customer_dashboard.php' : 'dashboard.php');

$error = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verify_csrf();
    $email = strtolower(trim($_POST['email'] ?? ''));
    // mobile keyboards frequently insert a trailing space after the last
    // field typed (autocorrect/predictive-text dismissing the keyboard) —
    // password_verify() is byte-exact, so an invisible trailing space
    // silently breaks login on mobile while the same credentials work
    // fine on desktop. Trimming here is safe: no account's real password
    // was ever created with meaningful leading/trailing whitespace either
    // (see the matching fix in users.php).
    $password = trim($_POST['password'] ?? '');

    $stmt = db()->prepare("SELECT * FROM users WHERE LOWER(email) = ? AND is_active = 1");
    $stmt->execute([$email]);
    $user = $stmt->fetch();

    if ($user && password_verify($password, $user['password_hash'])) {
        session_regenerate_id(true);
        $_SESSION['user_id'] = $user['id'];
        redirect($user['role'] === 'customer' ? 'customer_dashboard.php' : 'dashboard.php');
    }
    $error = 'Invalid login details.';
}

page_header('Login');
?>
<div class="card">
    <h1>Login</h1>
    <p class="lead">ZAS Textile</p>
    <?php if ($error): ?><div class="alert error"><?= e($error) ?></div><?php endif; ?>
    <?php /* NOT A SIGN-OUT. The session store was unreachable for that request,
             so the server could not see who you were. Saying so plainly stops
             people looking for a cause that is not there — and the marker is
             also what the AJAX error branch reads to word its message. */ ?>
    <?php if (($_GET['store'] ?? '') === 'down'): ?>
      <div class="alert error" id="zasStoreDown">
        <b>The session store did not answer.</b> You were not signed out — the server
        could not reach the place your login is kept, so it could not tell who you were.
        Sign in again; if this keeps happening, open <code>session_check.php</code>.
      </div>
    <?php endif; ?>
    <form method="post">
        <?= csrf_field() ?>
        <div class="field"><label>Email</label><input type="email" name="email" autocomplete="username" autocapitalize="off" spellcheck="false" required></div><br>
        <div class="field"><label>Password</label><input type="password" name="password" autocomplete="current-password" required></div><br>
        <button class="btn green" style="width:100%">Login</button>
    </form>
</div>
<?php page_footer(); ?>
