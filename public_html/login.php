<?php
require_once __DIR__ . '/includes/bootstrap.php';

/* WHERE LOGIN IS ALLOWED TO SEND SOMEBODY.
 *
 * The mobile door needs this: a phone user taps one bookmarked link,
 * signs in, and must land on that screen rather than on the dashboard.
 * require_login() puts where they were going on the query string.
 *
 * A redirect target that arrives from outside is how open redirects are
 * made, so nothing is trusted here. The value must be one of this app's
 * own phone screens — a bare m*.php filename with an ordinary query
 * string and nothing else. No slash, no scheme, no host, no backslash,
 * no dot-dot can survive the pattern, so the worst a crafted link can do
 * is send a user to a page of ours they could have typed themselves.
 * Anything that does not match is dropped, not corrected. */
function login_next(string $raw): string {
    if ($raw === '' || strlen($raw) > 200) return '';
    return preg_match('~^m(?:_[a-z0-9_]+)?\.php(?:\?[A-Za-z0-9_\-=&%.]*)?$~', $raw) ? $raw : '';
}
$next = login_next((string)($_GET['next'] ?? $_POST['next'] ?? ''));

/* A customer account has its own portal and never follows a next. */
if ($u = current_user()) {
    if ($u['role'] === 'customer') redirect('customer_dashboard.php');
    redirect($next !== '' ? $next : 'dashboard.php');
}

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
        if ($user['role'] === 'customer') redirect('customer_dashboard.php');
        redirect($next !== '' ? $next : 'dashboard.php');
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
    <?php if ($next !== ''): ?>
      <p class="lead" style="font-size:13px">Sign in to continue to the mobile screen you opened.</p>
    <?php endif; ?>
    <form method="post">
        <?= csrf_field() ?>
        <?php /* Carried through the POST, or the target would be lost the
                 moment the form is submitted. It is re-checked above. */ ?>
        <?php if ($next !== ''): ?><input type="hidden" name="next" value="<?= e($next) ?>"><?php endif; ?>
        <div class="field"><label>Email</label><input type="email" name="email" autocomplete="username" autocapitalize="off" spellcheck="false" required></div><br>
        <div class="field"><label>Password</label><input type="password" name="password" autocomplete="current-password" required></div><br>
        <button class="btn green" style="width:100%">Login</button>
    </form>
    <?php /* The separate door. One link for the people who only ever use a
             phone — same account, same password, and nothing on screen but
             the handful of screens their permissions open. */ ?>
    <p class="lead" style="margin-top:16px;font-size:13px;text-align:center">
      On a phone? <a href="m.php" style="font-weight:700;text-decoration:none">Open the mobile version</a>
    </p>
</div>
<?php page_footer(); ?>
