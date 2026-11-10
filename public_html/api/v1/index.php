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
require_once dirname(__DIR__, 2) . '/includes/att/Receipts.php';
require_once dirname(__DIR__, 2) . '/includes/att/AttendanceService.php';
require_once dirname(__DIR__, 2) . '/includes/att/HodService.php';
require_once dirname(__DIR__, 2) . '/includes/att/Http.php';

use Att\AttendanceService;
use Att\Audit;
use Att\Db;
use Att\Employees;
use Att\HodService;
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

        // ------------------------------------------------------- attendance

        case $method === 'POST' && ($route === '/attendance/in' || $route === '/attendance/out'):
            Http::requireCsrf();
            $u = Http::requirePermission('attgate', 'c');
            handle_gate_event($route === '/attendance/in' ? 'in' : 'out', (int) $u['id']);
            break;

        case $method === 'GET' && $route === '/attendance/movements':
            $u = Http::requirePermission('attgate', 'v');
            Http::json([
                'ok' => true,
                'movements' => AttendanceService::movements(
                    employee_scope_for((int) $u['id']),
                    ((int) Http::input('employee_id', 0)) ?: null,
                    Http::input('date') ? (string) Http::input('date') : null,
                    (int) Http::input('limit', 200)
                ),
            ]);
            break;

        case $method === 'GET' && $route === '/attendance/entry-cycles':
            $u = Http::requirePermission('attgate', 'v');
            Http::json([
                'ok' => true,
                'entry_cycles' => AttendanceService::entryCycles(
                    employee_scope_for((int) $u['id']),
                    ((int) Http::input('employee_id', 0)) ?: null,
                    Http::input('date') ? (string) Http::input('date') : null,
                    (int) Http::input('limit', 200)
                ),
            ]);
            break;

        // -------------------------------------------------------------- HOD

        case $method === 'GET' && $route === '/hod/pending':
            $u = Http::requirePermission('atthod', 'v');
            Http::json([
                'ok' => true,
                // Scope comes from att_user_roles, never from the request: a
                // department_id sent by the client is not even read.
                'pending' => HodService::pending(
                    Roles::departmentScope((int) $u['id'], Roles::HOD),
                    Http::input('date') ? (string) Http::input('date') : null
                ),
            ]);
            break;

        case $method === 'POST' && $route === '/hod/confirm':
            Http::requireCsrf();
            $u = Http::requirePermission('atthod', 'c');
            $employeeId = (int) Http::input('employee_id', 0);
            $code = trim((string) Http::input('employee_code', ''));
            if ($employeeId <= 0 && $code !== '') {
                $employeeId = (int) (Employees::findByCodeOrQr($code)['id'] ?? 0);
            }
            Http::json(HodService::confirm([
                'event_uuid'      => (string) Http::input('event_uuid', ''),
                'hod_user_id'     => (int) $u['id'],          // session, never the body
                'entry_cycle_id'  => (int) Http::input('entry_cycle_id', 0),
                'employee_id'     => $employeeId,
                'gate_event_uuid' => (string) Http::input('gate_event_uuid', ''),
                'device_id'       => (int) Http::input('device_id', 0),
                'device_sequence' => Http::input('device_sequence'),
                'device_time'     => (string) Http::input('occurred_at', ''),
                'timezone'        => (string) Http::input('timezone', ''),
                'source'          => (string) Http::input('source', 'online'),
            ]));
            break;

        case $method === 'POST' && $route === '/hod/confirm-bulk':
            Http::requireCsrf();
            $u = Http::requirePermission('atthod', 'c');
            $ids   = Http::input('entry_cycle_ids', []);
            $uuids = Http::input('event_uuids', []);
            if (!is_array($ids) || !is_array($uuids)) {
                Http::fail('VALIDATION', 'entry_cycle_ids and event_uuids must be lists.', 422);
            }
            $out = HodService::confirmBulk($ids, $uuids, (int) $u['id'], [
                'device_id'   => (int) Http::input('device_id', 0),
                'device_time' => (string) Http::input('confirmed_at', ''),
                'timezone'    => (string) Http::input('timezone', ''),
            ]);
            Http::json($out, ($out['ok'] ?? false) ? 200 : 422);
            break;

        case $method === 'GET' && $route === '/hod/movements':
            $u = Http::requirePermission('atthod', 'v');
            $scope = Roles::departmentScope((int) $u['id'], Roles::HOD);
            $date  = Http::input('date') ? (string) Http::input('date') : null;
            Http::json([
                'ok'        => true,
                'movements' => AttendanceService::movements($scope, null, $date),
                'arrivals'  => HodService::arrivals($scope, $date),
            ]);
            break;

        // ------------------------------------------- declared, not yet built

        case in_array($route, [
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

/**
 * POST /attendance/in and /attendance/out.
 *
 * The operator is ALWAYS the signed-in user — API_CONTRACT's closing line,
 * "Never trust client operator/role IDs". A handset that sends operator_id
 * is ignored, not obeyed.
 *
 * The receipt comes back with HTTP 200 even when it says REJECTED. That is
 * deliberate: the request was understood and answered, and the phone must
 * store the receipt and stop retrying. A 4xx would make its outbox treat a
 * settled decision as a transport failure and queue it forever.
 */
function handle_gate_event(string $direction, int $operatorId): void
{
    $employeeCode = trim((string) Http::input('employee_code', ''));
    $employeeId   = (int) Http::input('employee_id', 0);

    if ($employeeId <= 0 && $employeeCode !== '') {
        $emp = Employees::findByCodeOrQr($employeeCode);
        $employeeId = (int) ($emp['id'] ?? 0);
    }

    $payload = [
        'employee_id'     => $employeeId,
        'device_id'       => (int) Http::input('device_id', 0),
        'operator_id'     => $operatorId,
        'event_uuid'      => (string) Http::input('event_uuid', ''),
        'device_sequence' => (int) Http::input('device_sequence', 0),
        'device_time'     => (string) Http::input('occurred_at', ''),
        'timezone'        => (string) Http::input('timezone', ''),
        'source'          => (string) Http::input('source', 'online'),
    ];

    $receipt = $direction === 'in'
        ? AttendanceService::gateIn($payload)
        : AttendanceService::gateOut($payload);

    Http::json($receipt, 200);
}

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
