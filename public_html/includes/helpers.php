<?php
function e($value): string {
    return htmlspecialchars((string)$value, ENT_QUOTES, 'UTF-8');
}

function zas_category_of(array $it): string {
    $c = trim((string)($it['category'] ?? ''));
    if ($c !== '') return $c;
    $s = strtolower(($it['product_name'] ?? '') . ' ' . ($it['des_col'] ?? ''));
    if (strpos($s, 'blanket') !== false) return 'Blankets';
    if (strpos($s, 'towel') !== false) return 'Towels';
    if (strpos($s, 'gown') !== false) return 'Apparel';
    if (strpos($s, 'duvet') !== false) return 'Made-ups';
    return 'Bed Linen';
}

function redirect(string $url): void {
    header("Location: {$url}");
    exit;
}

function csrf_token(): string {
    if (empty($_SESSION['_csrf'])) {
        $_SESSION['_csrf'] = bin2hex(random_bytes(32));
    }
    return $_SESSION['_csrf'];
}

function csrf_field(): string {
    return '<input type="hidden" name="_csrf" value="' . e(csrf_token()) . '">';
}

function verify_csrf(): void {
    $token = $_POST['_csrf'] ?? '';
    if (!$token || !hash_equals($_SESSION['_csrf'] ?? '', $token)) {
        http_response_code(419);
        exit('CSRF token mismatch.');
    }
    /* THE ACCESS WINDOW IS ENFORCED HERE, AND HERE IS WHY.
     *
     * Every write handler in the app already calls this function before
     * it touches anything — 56 of the screens do. That makes it the one
     * honest place to ask "may this person change something right now":
     * one gate instead of fifty, and a screen that forgets to ask is
     * still covered.
     *
     * It only ever blocks a POST. Being read-only means being able to
     * read, so a GET passes through untouched. Admins are exempt inside
     * the guard, and function_exists keeps a part-uploaded copy of the
     * app from breaking every form on the site. */
    if (function_exists('zu_guard_write')) zu_guard_write();
}

function money_fmt($amount, string $currency = 'USD'): string {
    return $currency . ' ' . number_format((float)$amount, 2);
}

function num_fmt($number, int $decimals = 3): string {
    return number_format((float)$number, $decimals);
}

/* Like num_fmt() but drops pointless trailing zeros (0.400 -> 0.4, 6.000 -> 6).
   Use for quantities/weights; keep num_fmt()/money_fmt() for money, where a
   fixed number of decimals is the correct, expected format. */
function trim_num($number, int $maxDecimals = 3): string {
    $s = number_format((float)$number, $maxDecimals, '.', '');
    if (strpos($s, '.') !== false) {
        $s = rtrim(rtrim($s, '0'), '.');
    }
    return $s;
}

function post_array(string $key): array {
    return isset($_POST[$key]) && is_array($_POST[$key]) ? $_POST[$key] : [];
}

function audit_log(int $shipmentId, string $section, string $field, $old, $new, string $reason = ''): void {
    $user = current_user();
    $stmt = db()->prepare("
        INSERT INTO audit_logs
        (shipment_id, user_id, user_name, user_role, section_changed, field_changed, old_value, new_value, change_reason, ip_address, created_at)
        VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, NOW())
    ");
    $stmt->execute([
        $shipmentId,
        $user['id'] ?? null,
        $user['name'] ?? 'System',
        $user['role'] ?? 'system',
        $section,
        $field,
        is_string($old) ? $old : json_encode($old, JSON_UNESCAPED_UNICODE),
        is_string($new) ? $new : json_encode($new, JSON_UNESCAPED_UNICODE),
        $reason,
        $_SERVER['REMOTE_ADDR'] ?? ''
    ]);
}

function status_badge(string $status): string {
    $map = [
        'draft' => 'Draft',
        'submitted' => 'Submitted',
        'approved_locked' => 'Approved & Locked'
    ];
    $class = $status === 'approved_locked' ? 'green' : ($status === 'submitted' ? 'orange' : 'blue');
    return '<span class="badge ' . $class . '">' . e($map[$status] ?? $status) . '</span>';
}

function get_setting_model_text(): string {
    global $config;
    return "Embedding: " . ($config['embedding_model'] ?? 'text-embedding-3-small') . " | Answer: " . ($config['ai_answer_model'] ?? 'gpt-5-mini');
}

/* The same normalisation the LOV uses in the browser (assets/js/lov.js,
   norm()): lowercase, then drop everything that is not a letter or a
   digit. Kept identical on purpose — the product-link chip is drawn once
   by PHP on page load and again by JS as you type, and the two must not
   disagree about whether a line has been reworded.

   Deliberately NOT production_norm(), which collapses punctuation to a
   space instead of removing it: that one drives production's matching,
   this one only compares two names for sameness. */
function lov_norm(string $s): string {
    return preg_replace('/[^a-z0-9]/', '', strtolower($s));
}
