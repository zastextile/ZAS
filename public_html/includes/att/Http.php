<?php
declare(strict_types=1);

namespace Att;

/**
 * The thin layer between an HTTP request and the services.
 *
 * It owns four things the blueprint insists on for EVERY endpoint: the actor
 * comes from the session and never from the request body, CSRF is verified on
 * every write, authorisation is checked server-side before any work happens,
 * and failures come back as a predictable JSON shape rather than an HTML
 * error page a PWA cannot read.
 */
final class Http
{
    /** Decoded JSON body, memoised. @var array<string,mixed>|null */
    private static ?array $body = null;

    /** @return array<string,mixed> */
    public static function body(): array
    {
        if (self::$body !== null) {
            return self::$body;
        }
        $raw = file_get_contents('php://input');
        $decoded = json_decode((string) $raw, true);
        self::$body = is_array($decoded) ? $decoded : [];
        return self::$body;
    }

    public static function input(string $key, mixed $default = null): mixed
    {
        $body = self::body();
        return $body[$key] ?? $_GET[$key] ?? $default;
    }

    public static function json(array $payload, int $status = 200): never
    {
        http_response_code($status);
        header('Content-Type: application/json; charset=utf-8');
        header('Cache-Control: no-store');
        header('X-Content-Type-Options: nosniff');
        echo json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        exit;
    }

    /**
     * Every failure the API can produce looks like this. reason_code is the
     * machine-readable half — the PWA decides whether to retry, queue, or give
     * up on it, and it is what the sync receipt stores.
     */
    public static function fail(string $reasonCode, string $message, int $status = 400, array $extra = []): never
    {
        self::json(array_merge([
            'ok'          => false,
            'reason_code' => $reasonCode,
            'error'       => $message,
        ], $extra), $status);
    }

    // ------------------------------------------------------------------ auth

    /** The signed-in user, or null. Never trusts anything in the request. */
    public static function user(): ?array
    {
        if (function_exists('current_user')) {
            $u = \current_user();
            return is_array($u) ? $u : null;
        }
        if (!empty($_SESSION['user_id'])) {
            return Db::one('SELECT * FROM users WHERE id = ? AND is_active = 1', [(int) $_SESSION['user_id']]);
        }
        return null;
    }

    public static function requireUser(): array
    {
        $u = self::user();
        if ($u === null) {
            self::fail('NOT_AUTHENTICATED', 'Sign in to continue.', 401);
        }
        return $u;
    }

    /**
     * Authorise through zu_perm. Admin bypasses, as everywhere else in the app.
     * Returns the user so callers can use it without a second lookup.
     */
    public static function requirePermission(string $module, string $action): array
    {
        $u = self::requireUser();
        // No user id on purpose — see Att\Roles::can(). Passing one turns off
        // the admin bypass and the access-window check inside zu_can().
        if (!Roles::can($module, $action)) {
            self::fail('FORBIDDEN', 'You do not have permission for this action.', 403);
        }
        return $u;
    }

    /**
     * CSRF for a JSON API.
     *
     * The app's verify_csrf() reads $_POST['_csrf'], which a JSON request does
     * not have. The token is the same session token — this only changes where
     * it is read from, so a browser that cannot set a custom header cannot
     * forge one either.
     */
    public static function requireCsrf(): void
    {
        if (in_array($_SERVER['REQUEST_METHOD'] ?? 'GET', ['GET', 'HEAD', 'OPTIONS'], true)) {
            return;
        }
        $sent = $_SERVER['HTTP_X_CSRF_TOKEN'] ?? (self::body()['_csrf'] ?? $_POST['_csrf'] ?? '');
        $held = $_SESSION['_csrf'] ?? '';
        if (!is_string($sent) || $sent === '' || $held === '' || !hash_equals((string) $held, $sent)) {
            self::fail('CSRF_MISMATCH', 'Your session has moved on. Reload and try again.', 419);
        }
    }

    // ------------------------------------------------------------ rate limit

    /**
     * Fixed-window rate limit.
     *
     * Windowed rather than rolling on purpose: a handset that has been offline
     * all morning replays its whole queue at once, and a rolling per-request
     * log would throttle exactly the catch-up the offline design depends on,
     * as well as growing without bound.
     */
    public static function rateLimit(string $bucket, int $max, int $windowSeconds): void
    {
        try {
            $now = time();
            $windowStart = date('Y-m-d H:i:s', $now - ($now % max(1, $windowSeconds)));

            Db::run(
                'INSERT INTO att_rate_limits (bucket, window_start, hits) VALUES (?, ?, 1)
                 ON DUPLICATE KEY UPDATE hits = hits + 1',
                [$bucket, $windowStart]
            );
            $hits = (int) Db::value(
                'SELECT hits FROM att_rate_limits WHERE bucket = ? AND window_start = ?',
                [$bucket, $windowStart]
            );
            if ($hits > $max) {
                // fail() exits; no catch below can swallow it.
                self::fail('RATE_LIMITED', 'Too many attempts. Wait a moment and try again.', 429);
            }
        } catch (\Throwable $e) {
            // A failure to COUNT must not become a failure to serve. If the
            // limiter's own table is unreachable the request still proceeds —
            // the alternative is a database hiccup closing the factory gate.
            error_log('[att-ratelimit] ' . $e->getMessage());
        }
    }

    public static function clientIp(): string
    {
        return substr((string) ($_SERVER['REMOTE_ADDR'] ?? 'unknown'), 0, 45);
    }

    /** ISO 8601 with offset, the shape the contract specifies for display. */
    public static function iso(?string $dbValue): ?string
    {
        return Time::iso(Time::fromDb($dbValue));
    }
}
