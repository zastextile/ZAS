<?php
function current_user(): ?array {
    if (empty($_SESSION['user_id'])) {
        return null;
    }
    static $user = null; // in-process memo -- still just one query even without Redis
    if ($user !== null) {
        return $user;
    }
    $uid = (int)$_SESSION['user_id'];
    $key = 'user:v' . cache_version('users') . ":$uid";
    $user = cache_remember($key, 20, function () use ($uid) {
        $stmt = db()->prepare("SELECT * FROM users WHERE id = ? AND is_active = 1");
        $stmt->execute([$uid]);
        return $stmt->fetch() ?: false;
    });
    if ($user === false) $user = null;
    return $user;
}

function require_login(): void {
    $u = current_user();
    if (!$u) {
        /* SAY WHICH IT WAS. "You have been signed out" and "the session store
           did not answer" look identical from here — an empty $_SESSION — but
           they are completely different problems, and telling somebody they
           were signed out when the server simply could not reach Redis sends
           them hunting for a cause that does not exist. The reason travels on
           the query string so the login page, and the AJAX error branch that
           reads this response, can both use it. */
        $why = function_exists('session_fallback_reason') ? session_fallback_reason() : '';
        redirect('login.php' . ($why !== '' ? '?store=down' : ''));
    }
    // A customer account is a separate portal (customer_dashboard.php etc.)
    // with its own login/session handling — it must never fall through into
    // any internal page just because a session happens to be active.
    if ($u['role'] === 'customer') {
        redirect('customer_dashboard.php');
    }

    /* THE ACCESS WINDOW, AND ONLY THE HARD ONE.
     *
     * A user whose admin set "outside those hours: no access at all" is
     * stopped here, on every page, before anything loads. The softer
     * setting — "can look, cannot change" — is NOT enforced here, because
     * looking is exactly what it permits; that one is caught in
     * verify_csrf(), which every write in the app goes through.
     *
     * Admins are exempt inside zu_window_state(), so this can never lock
     * out the person who would have to unlock it. The guard is wrapped in
     * function_exists so a part-uploaded copy of the app cannot make the
     * whole site unreachable. */
    if (function_exists('zu_window_state') && zu_window_state($u) === 'no') {
        http_response_code(403);
        $words = function_exists('zu_window_words') ? zu_window_words($u) : '';
        exit('<!doctype html><meta charset="utf-8"><title>Outside your hours</title>'
           . '<div style="font:15px/1.7 system-ui,sans-serif;max-width:520px;margin:14vh auto;padding:0 20px;color:#152033">'
           . '<h1 style="font-size:19px;margin:0 0 8px">Outside your working hours</h1>'
           . '<p style="color:#5a6b82;margin:0 0 14px">Your login is set to work ' . e($words) . '</p>'
           . '<p style="color:#5a6b82;margin:0 0 18px">Come back inside those hours, or ask an admin to change it '
           . 'on Administration &rsaquo; User Access.</p>'
           . '<a href="logout.php" style="color:#2563eb;font-weight:600;text-decoration:none">Sign out</a></div>');
    }
}

function is_admin(): bool {
    $u = current_user();
    return $u && $u['role'] === 'admin';
}

function is_colleague(): bool {
    $u = current_user();
    return $u && $u['role'] === 'colleague';
}

function is_staff(): bool {
    $u = current_user();
    return $u && $u['role'] === 'staff';
}

function is_production_staff(): bool {
    $u = current_user();
    return $u && $u['role'] === 'production_staff';
}

function can_see_rates(): bool {
    $u = current_user();
    if (!$u) return false;
    if ($u['role'] === 'admin') return true;
    if ($u['role'] === 'colleague' && (int)$u['can_see_rates'] === 1) return true;
    return false;
}

function assigned_shipment_ids(): array {
    $u = current_user();
    if (!$u) return [];
    if ($u['role'] === 'admin' || $u['role'] === 'colleague') return ['ALL'];

    $stmt = db()->prepare("SELECT shipment_id FROM shipment_assignments WHERE user_id = ?");
    $stmt->execute([$u['id']]);
    return array_map('intval', array_column($stmt->fetchAll(), 'shipment_id'));
}

function is_assigned_shipment(int $shipmentId): bool {
    $u = current_user();
    if (!$u) return false;
    if ($u['role'] === 'admin') return true;
    $stmt = db()->prepare("SELECT COUNT(*) FROM shipment_assignments WHERE user_id = ? AND shipment_id = ?");
    $stmt->execute([$u['id'], $shipmentId]);
    return (int)$stmt->fetchColumn() > 0;
}

function can_pack_department(int $shipmentId, string $dept): bool {
    $u = current_user();
    if (!$u) return false;
    if ($u['role'] === 'admin') return true;
    if (!$u['department']) return false;
    if ($u['department'] !== $dept) return false;
    return can_view_shipment($shipmentId);
}

function can_view_shipment(int $shipmentId): bool {
    $u = current_user();
    if (!$u) return false;
    if ($u['role'] === 'admin') return true;
    if ($u['role'] === 'colleague') return true;

    $stmt = db()->prepare("SELECT COUNT(*) FROM shipment_assignments WHERE user_id = ? AND shipment_id = ?");
    $stmt->execute([$u['id'], $shipmentId]);
    return (int)$stmt->fetchColumn() > 0;
}

function can_edit_invoice(array $shipment): bool {
    $u = current_user();
    if (!$u) return false;
    if ($u['role'] === 'staff') return false;

    if ($u['role'] === 'admin') return true;

    if (!can_view_shipment((int)$shipment['id'])) return false;

    $status = $shipment['status'] ?? 'draft';
    $reopenStatus = $shipment['reopen_status'] ?? '';

    if ($status === 'draft') return true;
    if ($status === 'submitted' && $reopenStatus === 'reopened_for_correction') return true;

    return false;
}

function can_edit_packing(array $shipment): bool {
    $u = current_user();
    if (!$u) return false;

    if ($u['role'] === 'admin') return true;

    if (!can_view_shipment((int)$shipment['id'])) return false;

    $status = $shipment['status'] ?? 'draft';
    $reopenStatus = $shipment['reopen_status'] ?? '';

    if ($status === 'draft') return true;
    if ($status === 'submitted' && $reopenStatus === 'reopened_for_correction') return true;

    return false;
}

function can_approve(): bool {
    return is_admin();
}

function require_admin(): void {
    require_login();
    if (!is_admin()) {
        http_response_code(403);
        exit('Admin access required.');
    }
}
