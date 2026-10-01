<?php
/*
  Customer Portal — a lightweight buyer-facing account type, separate from
  admin/colleague/staff/production_staff. A customer sees only their own
  Approved & Locked invoices (matched by company_name === buyer_name), on a
  small dedicated dashboard/search — never the internal tool or its nav.
*/

function customer_ensure_schema(): void {
    // Same technique already used in includes/production.php to add
    // 'production_staff' to this same ENUM — additive, no data loss.
    try { db()->exec("ALTER TABLE users MODIFY COLUMN role ENUM('admin','colleague','staff','production_staff','customer') NOT NULL DEFAULT 'colleague'"); } catch (Throwable $e) {}
    // Matched case-insensitively against shipments.buyer_name to decide which
    // invoices this account can see — set once by Admin on Users, must match
    // the Buyer Name exactly as typed on their invoices.
    try { db()->exec("ALTER TABLE users ADD COLUMN company_name VARCHAR(190) NULL"); } catch (Throwable $e) {}

    // "Stay logged in until logout" — a standard selector/validator remember
    // token (never the raw session id, never guessable from the DB alone:
    // only a hash of the validator is stored). One row per device/browser.
    try { db()->exec("CREATE TABLE IF NOT EXISTS remember_tokens (
        id INT AUTO_INCREMENT PRIMARY KEY,
        user_id INT NOT NULL,
        selector VARCHAR(24) NOT NULL,
        validator_hash VARCHAR(255) NOT NULL,
        expires_at DATETIME NOT NULL,
        created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
        UNIQUE KEY uniq_selector (selector),
        INDEX(user_id)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4"); } catch (Throwable $e) {}
}

function is_customer(): bool {
    $u = current_user();
    return $u && $u['role'] === 'customer';
}

/* Every Approved & Locked shipment this customer's company_name matches —
   the whole of their visibility. Empty company_name or no match = nothing
   shown, never every shipment (fail closed, not open). */
function customer_shipment_ids(): array {
    $u = current_user();
    if (!$u || $u['role'] !== 'customer') return [];
    $company = trim((string)($u['company_name'] ?? ''));
    if ($company === '') return [];
    $st = db()->prepare("SELECT id FROM shipments WHERE status='approved_locked' AND LOWER(buyer_name) = LOWER(?)");
    $st->execute([$company]);
    return array_map('intval', array_column($st->fetchAll(), 'id'));
}

const CUSTOMER_REMEMBER_COOKIE = 'zas_customer_remember';
const CUSTOMER_REMEMBER_DAYS = 400; // Chrome's own cap on cookie lifetime — matching it avoids a silent early expiry

/* Issues a fresh remember-me token for this user, replacing any token this
   selector previously had. Called on every login AND every successful
   auto-login, so a stolen old cookie value stops working the moment the
   real owner's browser rotates it (standard remember-me hardening). */
function customer_issue_remember_cookie(int $userId): void {
    $selector = bin2hex(random_bytes(9));
    $validator = bin2hex(random_bytes(32));
    $expires = date('Y-m-d H:i:s', time() + CUSTOMER_REMEMBER_DAYS * 86400);
    db()->prepare("INSERT INTO remember_tokens (user_id, selector, validator_hash, expires_at) VALUES (?,?,?,?)")
        ->execute([$userId, $selector, hash('sha256', $validator), $expires]);
    setcookie(CUSTOMER_REMEMBER_COOKIE, $selector . ':' . $validator, [
        'expires' => time() + CUSTOMER_REMEMBER_DAYS * 86400,
        'path' => '/', 'secure' => true, 'httponly' => true, 'samesite' => 'Lax',
    ]);
}

/* If there's no active session but a valid remember-me cookie, restore the
   login silently and rotate the token. Safe to call on every customer page
   load — does nothing when a session already exists or no cookie is set. */
function customer_try_remember_login(): void {
    if (!empty($_SESSION['user_id'])) return;
    $raw = $_COOKIE[CUSTOMER_REMEMBER_COOKIE] ?? '';
    if ($raw === '' || strpos($raw, ':') === false) return;
    [$selector, $validator] = explode(':', $raw, 2);

    $st = db()->prepare("SELECT * FROM remember_tokens WHERE selector=?");
    $st->execute([$selector]);
    $token = $st->fetch();
    if (!$token || strtotime($token['expires_at']) < time() || !hash_equals($token['validator_hash'], hash('sha256', $validator))) {
        customer_clear_remember_cookie();
        return;
    }
    db()->prepare("DELETE FROM remember_tokens WHERE id=?")->execute([$token['id']]);
    $ust = db()->prepare("SELECT id, role FROM users WHERE id=? AND is_active=1 AND role='customer'");
    $ust->execute([(int)$token['user_id']]);
    $user = $ust->fetch();
    if (!$user) { customer_clear_remember_cookie(); return; }

    session_regenerate_id(true);
    $_SESSION['user_id'] = (int)$user['id'];
    customer_issue_remember_cookie((int)$user['id']);
}

/* Logout-time cleanup — removes every remembered device for this user's
   session token specifically (not all their devices), and clears the
   cookie on this browser. */
function customer_clear_remember_cookie(): void {
    $raw = $_COOKIE[CUSTOMER_REMEMBER_COOKIE] ?? '';
    if ($raw !== '' && strpos($raw, ':') !== false) {
        [$selector] = explode(':', $raw, 2);
        try { db()->prepare("DELETE FROM remember_tokens WHERE selector=?")->execute([$selector]); } catch (Throwable $e) {}
    }
    setcookie(CUSTOMER_REMEMBER_COOKIE, '', ['expires' => time() - 3600, 'path' => '/', 'secure' => true, 'httponly' => true, 'samesite' => 'Lax']);
}

/* Gate for every customer_*.php page — separate from require_login() on
   purpose: a customer session must never satisfy an internal page's login
   check, and an internal (admin/colleague/staff) session must never see
   the customer portal either. */
function require_customer_login(): void {
    customer_try_remember_login();
    if (!is_customer()) redirect('customer_login.php');
}

/* Same PKR-base conversion as cvt() in includes/ai_check_core.php — kept as
   its own tiny copy here rather than pulling that whole file (Product
   Master fuzzy matching, AI Check computation, etc.) into the customer
   portal just for one 6-line pure function. */
function customer_cvt(float $amt, string $from, string $to, array $fx): float {
    $from = strtoupper($from ?: 'PKR'); $to = strtoupper($to ?: 'PKR');
    $f = $fx[$from] ?? 0; $t = $fx[$to] ?? 0;
    if ($f <= 0 || $t <= 0 || $from === $to) return $amt;
    return ($amt / $f) * $t;
}
