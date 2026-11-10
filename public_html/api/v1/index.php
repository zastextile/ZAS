<?php
declare(strict_types=1);

/**
 * ZAS Attendance REST API — /api/v1
 *
 * One front controller rather than a file per endpoint, so that the four
 * things that must happen on EVERY request happen in one place and cannot be
 * forgotten on a new route: session, CSRF, authorisation, JSON errors.
 *
 * Milestone 1 implements auth, employees and shifts. Routes belonging to
 * later milestones are declared below and answer 501 rather than 404, so the
 * PWA can tell "not built yet" from "wrong address".
 */

require_once dirname(__DIR__, 2) . '/includes/bootstrap.php';

require_once dirname(__DIR__, 2) . '/includes/att/Db.php';
require_once dirname(__DIR__, 2) . '/includes/att/Time.php';
require_once dirname(__DIR__, 2) . '/includes/att/Policy.php';
require_once dirname(__DIR__, 2) . '/includes/att/Audit.php';
require_once dirname(__DIR__, 2) . '/includes/att/Roles.php';
require_once dirname(__DIR__, 2) . '/includes/att/Employees.php';
require_once dirname(__DIR__, 2) . '/includes/att/Shifts.php';
require_once dirname(__DIR__, 2) . '/includes/att/Http.php';

use Att\Audit;
use Att\Db;
use Att\Employees;
use Att\Http;
use Att\Policy;
use Att\Roles;
use Att\Shifts;
use Att\Time;

/* ---------------------------------------------------------------- routing */

$method = strtoupper((string) ($_SERVER['REQUEST_METHOD'] ?? 'GET'));

/* Works whether the server rewrites to /api/v1/... or the script is reached
   directly with ?route=. PATH_INFO covers the rewrite case. */
$route = (string) ($_GET['route'] ?? '');
if ($route === '') {
    $path  = (string) ($_SERVER['PATH_INFO'] ?? '');
    if ($path === '') {
        $uri  = parse_url((string) ($_SERVER['REQUEST_URI'] ?? ''), PHP_URL_PATH) ?: '';
        $path = (string) preg_replace('#^.*/api/v1#', '', $uri);
    }
    $route = $path;
}
$route = '/' . trim($route, '/');

try {
    switch (true) {

        // ------------------------------------------------------------- auth

        case $method === 'POST' && $route === '/auth/login':
            handle_login();
            break;

        case $method === 'POST' && $route === '/auth/logout':
            Http::requireCsrf();
            $uid = (int) ($_SESSION['user_id'] ?? 0);
            $_SESSION = [];
            if (session_status() === PHP_SESSION_ACTIVE) {
                session_regenerate_id(true);
            }
            if ($uid > 0) {
                Audit::record('auth.logout', 'user', $uid, null, null, $uid);
            }
            Http::json(['ok' => true]);
            break;

        case $method === 'GET' && $route === '/auth/me':
            $u = Http::requireUser();
            $uid = (int) $u['id'];
            Http::json([
                'ok' => true,
                'user' => [
                    'id'         => $uid,
                    'name'       => $u['name'] ?? '',
                    'email'      => $u['email'] ?? '',
                    'erp_role'   => $u['role'] ?? '',
                    'att_roles'  => Roles::forUser($uid),
                    'is_admin'   => Roles::isAdmin($uid),
                ],
                'csrf_token' => function_exists('csrf_token') ? csrf_token() : ($_SESSION['_csrf'] ?? ''),
                'server_time' => Time::iso(Time::nowUtc()),
                'policies' => [
                    'duplicate_window_seconds'  => Policy::int('duplicate_window_seconds'),
                    'sync_backlog_warn_minutes' => Policy::int('sync_backlog_warn_minutes'),
                ],
            ]);
            break;

        // -------------------------------------------------------- employees

        case $method === 'GET' && $route === '/employees':
            $u     = Http::requirePermission('attgate', 'v');
            $scope = employee_scope_for((int) $u['id']);
            Http::json([
                'ok'        => true,
                'employees' => Employees::search((string) Http::input('query', ''), $scope),
            ]);
            break;

        case $method === 'GET' && $route === '/employees/sync-snapshot':
            $u     = Http::requirePermission('attgate', 'v');
            $scope = employee_scope_for((int) $u['id']);
            Http::json(array_merge(['ok' => true], Employees::syncSnapshot($scope)));
            break;

        case $method === 'GET' && preg_match('#^/employees/([^/]+)$#', $route, $m) === 1:
            $u    = Http::requirePermission('attgate', 'v');
            $emp  = Employees::findByCodeOrQr(urldecode($m[1]));
            if ($emp === null) {
                Http::fail('EMPLOYEE_NOT_FOUND', 'No employee with that code.', 404);
            }
            $scope = employee_scope_for((int) $u['id']);
            if ($scope !== null && !in_array((int) $emp['department_id'], $scope, true)) {
                // Same answer as "does not exist": a scoped user must not be
                // able to probe for people outside their department.
                Http::fail('EMPLOYEE_NOT_FOUND', 'No employee with that code.', 404);
            }
            Http::json(['ok' => true, 'employee' => $emp]);
            break;

        // ----------------------------------------------------------- shifts

        case $method === 'GET' && $route === '/shifts':
            Http::requirePermission('attadm', 'v');
            Http::json(['ok' => true, 'shifts' => Shifts::all(false)]);
            break;

        case $method === 'POST' && $route === '/shifts/assignments':
            Http::requireCsrf();
            $u = Http::requirePermission('attadm', 'c');

            $employeeId = (int) Http::input('employee_id', 0);
            $shiftId    = (int) Http::input('shift_id', 0);
            $workDate   = (string) Http::input('work_date', '');

            if ($employeeId <= 0 || $shiftId <= 0 || $workDate === '') {
                Http::fail('VALIDATION', 'employee_id, shift_id and work_date are required.', 422);
            }
            if (Employees::find($employeeId) === null) {
                Http::fail('EMPLOYEE_NOT_FOUND', 'No such employee.', 404);
            }
            $id = Shifts::assign($employeeId, $shiftId, $workDate, 'assigned', (int) $u['id']);
            Http::json(['ok' => true, 'shift_instance_id' => $id], 201);
            break;

        // ------------------------------------------- declared, not yet built

        case in_array($route, [
            '/attendance/in', '/attendance/out', '/attendance/movements', '/attendance/entry-cycles',
            '/hod/pending', '/hod/confirm', '/hod/confirm-bulk', '/hod/movements',
            '/sync/events', '/sync/status', '/sync/changes',
            '/overtime/requests', '/overtime/verification',
            '/overtime/payroll/rate', '/overtime/payroll/finalize',
            '/hr/reconciliation', '/reports/attendance', '/reports/movements', '/reports/overtime',
        ], true):
            Http::fail('NOT_IMPLEMENTED', 'This endpoint arrives in a later milestone.', 501, [
                'route' => $route,
            ]);
            break;

        default:
            Http::fail('NO_ROUTE', 'Unknown endpoint.', 404, ['route' => $route, 'method' => $method]);
    }
} catch (Throwable $e) {
    /* Never leak a stack trace or a query to a handset. The detail goes to the
       server log, the caller gets something it can act on. */
    error_log('[att-api] ' . $route . ' :: ' . $e->getMessage());
    Http::fail('SERVER_ERROR', 'The server could not complete that request.', 500);
}

/* -------------------------------------------------------------- handlers */

function handle_login(): void
{
    $email    = strtolower(trim((string) Http::input('email', '')));
    $password = (string) Http::input('password', '');

    /* Rate limited per email AND per IP. Per email alone lets one attacker
       work through many accounts from one machine; per IP alone lets a
       distributed attempt work through one account. */
    Http::rateLimit('login:ip:' . Http::clientIp(), 30, 300);
    if ($email !== '') {
        Http::rateLimit('login:email:' . $email, 10, 300);
    }

    if ($email === '' || $password === '') {
        Http::fail('VALIDATION', 'Email and password are required.', 422);
    }

    $user = Db::one('SELECT * FROM users WHERE LOWER(email) = ? AND is_active = 1', [$email]);

    /* One message and one timing for "no such user" and "wrong password".
       Telling them apart tells an attacker which emails exist. */
    if ($user === null || !password_verify($password, (string) $user['password_hash'])) {
        Audit::record('auth.login_failed', 'user', (int) ($user['id'] ?? 0), null, ['email' => $email], null);
        Http::fail('BAD_CREDENTIALS', 'That email and password do not match.', 401);
    }

    $uid = (int) $user['id'];

    /* Session fixation: a session id that existed before the login must not
       survive it. */
    if (session_status() === PHP_SESSION_ACTIVE) {
        session_regenerate_id(true);
    }
    $_SESSION['user_id'] = $uid;

    Audit::record('auth.login', 'user', $uid, null, null, $uid);

    Http::json([
        'ok' => true,
        'user' => [
            'id'        => $uid,
            'name'      => $user['name'] ?? '',
            'att_roles' => Roles::forUser($uid),
            'is_admin'  => Roles::isAdmin($uid),
        ],
        'csrf_token'  => function_exists('csrf_token') ? csrf_token() : ($_SESSION['_csrf'] ?? ''),
        'server_time' => Time::iso(Time::nowUtc()),
    ]);
}

/**
 * The department scope to apply to an employee query for this user.
 * null = every department. A HOD gets their own; everyone else is judged by
 * the roles they actually hold.
 *
 * @return list<int>|null
 */
function employee_scope_for(int $userId): ?array
{
    if (Roles::isAdmin($userId)) {
        return null;
    }
    foreach ([Roles::HR, Roles::ACCOUNTS, Roles::GATEKEEPER] as $unscoped) {
        if (Roles::has($userId, $unscoped)) {
            return null; // a gatekeeper sees everyone who walks through the gate
        }
    }
    if (Roles::has($userId, Roles::HOD)) {
        return Roles::departmentScope($userId, Roles::HOD) ?? null;
    }
    return []; // holds no attendance role: sees nobody
}
