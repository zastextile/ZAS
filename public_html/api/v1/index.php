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
require_once dirname(__DIR__, 2) . '/includes/att/Reconciler.php';
require_once dirname(__DIR__, 2) . '/includes/att/SyncService.php';
require_once dirname(__DIR__, 2) . '/includes/att/HrService.php';
require_once dirname(__DIR__, 2) . '/includes/att/AdminService.php';
/* The ERP's own R2 client. Functions only; loading it changes nothing. */
require_once dirname(__DIR__, 2) . '/includes/storage.php';
require_once dirname(__DIR__, 2) . '/includes/att/Storage.php';
require_once dirname(__DIR__, 2) . '/includes/att/Images.php';
require_once dirname(__DIR__, 2) . '/includes/att/PhotoService.php';
require_once dirname(__DIR__, 2) . '/includes/att/OvertimeService.php';
require_once dirname(__DIR__, 2) . '/includes/att/Http.php';

use Att\AdminService;
use Att\AttendanceService;
use Att\Audit;
use Att\Db;
use Att\Employees;
use Att\HodService;
use Att\HrService;
use Att\Http;
use Att\Images;
use Att\OvertimeService as OT;
use Att\PhotoService;
use Att\Policy;
use Att\Roles;
use Att\Shifts;
use Att\SyncService;
use Att\Time;

/* UTC for everything the attendance code stores — see Att\Db::useUtc(). */
try { Db::useUtc(); } catch (Throwable $e) { error_log('[att-api] could not set UTC: ' . $e->getMessage()); }

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
                /* What the phone app should SHOW. Showing a tab is not
                   permission to use it: every request is checked again. */
                'can' => [
                    'gate' => Roles::can('attgate', 'c'),
                    'hod'  => Roles::can('atthod', 'c'),
                    'flag' => Roles::can('attflag', 'c'),
                    'ot'   => Roles::can('attot', 'c'),
                ],
                'flag_reasons' => PhotoService::REASONS,
                'csrf_token' => function_exists('csrf_token') ? csrf_token() : ($_SESSION['_csrf'] ?? ''),
                'server_time' => Time::iso(Time::nowUtc()),
                'server_epoch_ms' => (int) round(microtime(true) * 1000),
                'policies' => [
                    'duplicate_window_seconds'  => Policy::int('duplicate_window_seconds'),
                    'sync_backlog_warn_minutes' => Policy::int('sync_backlog_warn_minutes'),
                ],
            ]);
            break;

        // -------------------------------------------------------- employees

        case $method === 'GET' && $route === '/employees':
            $u     = require_gate_or_hod();
            $scope = employee_scope_for((int) $u['id']);
            Http::json([
                'ok'        => true,
                'employees' => Employees::search((string) Http::input('query', ''), $scope),
            ]);
            break;

        case $method === 'GET' && $route === '/employees/sync-snapshot':
            $u     = require_gate_or_hod();
            $scope = employee_scope_for((int) $u['id']);
            Http::json(array_merge(['ok' => true], Employees::syncSnapshot($scope)));
            break;

        /* The employee's reference photo, for the gatekeeper or HOD who has
           just typed or scanned the code. The picture itself comes back —
           not a link to it — after the same department check as the lookup
           below, and each view is one line in the audit log. */
        case $method === 'GET' && preg_match('#^/employees/([^/]+)/photo$#', $route, $m) === 1:
            $u = require_gate_or_hod();
            // Enough for a busy gate; not enough to walk off with the album.
            Http::rateLimit('photo:user:' . (int) $u['id'], 900, 600);
            $emp   = Employees::findByCodeOrQr(urldecode($m[1]));
            $scope = employee_scope_for((int) $u['id']);
            if ($emp === null || ($scope !== null && !in_array((int) $emp['department_id'], $scope, true))) {
                Http::fail('EMPLOYEE_NOT_FOUND', 'No employee with that code.', 404);
            }
            send_image(PhotoService::reference((int) $emp['id'], (int) $u['id'], 'code entered on a phone'));
            break;

        case $method === 'GET' && preg_match('#^/employees/([^/]+)$#', $route, $m) === 1:
            $u    = require_gate_or_hod();
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
            // Where the picture is kept is the server's business only.
            $emp['has_photo'] = $emp['reference_photo_key'] !== null;
            unset($emp['reference_photo_key'], $emp['reference_photo_etag'], $emp['reference_photo_at']);
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

        // ---------------------------------------------------- devices & sync

        case $method === 'POST' && $route === '/devices/register':
            Http::requireCsrf();
            $u = Http::requireUser();
            $gate = Roles::can('attgate', 'c');
            $hod  = Roles::can('atthod', 'c');
            if (!$gate && !$hod) {
                Http::fail('FORBIDDEN', 'You do not have permission for this action.', 403);
            }
            // The scope follows what this person may do. The phone does not
            // get to say what kind of device it is.
            $out = SyncService::registerDevice(
                (string) Http::input('device_uuid', ''),
                (string) Http::input('label', ''),
                $gate && $hod ? 'both' : ($gate ? 'gate' : 'hod'),
                (int) $u['id'],
                Http::input('app_version') ? (string) Http::input('app_version') : null
            );
            Http::json($out, ($out['ok'] ?? false) ? 200 : (($out['reason_code'] ?? '') === 'VALIDATION' ? 422 : 403));
            break;

        case $method === 'GET' && $route === '/devices':
            Http::requirePermission('attadm', 'v');
            Http::json(['ok' => true, 'devices' => HrService::devices()]);
            break;

        case $method === 'POST' && preg_match('#^/devices/(\d+)/revoke$#', $route, $m) === 1:
            Http::requireCsrf();
            $u = Http::requirePermission('attadm', 'u');
            $reason = trim((string) Http::input('reason', ''));
            if ($reason === '') {
                Http::fail('REASON_REQUIRED', 'Say why the device is being withdrawn.', 422);
            }
            Http::json(['ok' => true, 'revoked' => SyncService::revokeDevice((int) $m[1], (int) $u['id'], $reason)]);
            break;

        case $method === 'POST' && $route === '/sync/events':
            Http::requireCsrf();
            $u = Http::requireUser();
            $allowed = [];
            if (Roles::can('attgate', 'c')) { $allowed[] = 'GATE_IN'; $allowed[] = 'GATE_OUT'; }
            if (Roles::can('atthod', 'c'))  { $allowed[] = 'HOD_CONFIRM'; }
            if ($allowed === []) {
                Http::fail('FORBIDDEN', 'You do not have permission for this action.', 403);
            }
            /* Generous, and per user: a phone that was offline all morning
               sends its whole queue in a few requests, and throttling that is
               throttling the catch-up the design depends on. */
            Http::rateLimit('sync:user:' . (int) $u['id'], 240, 60);
            $events = Http::input('events', []);
            if (!is_array($events)) {
                Http::fail('VALIDATION', 'events must be a list.', 422);
            }
            $out = SyncService::processBatch($events, (int) $u['id'], (bool) Http::input('more', false), $allowed,
                Http::input('device_now') ? (string) Http::input('device_now') : null);
            Http::json($out, ($out['ok'] ?? false) ? 200 : 413);
            break;

        case $method === 'GET' && $route === '/sync/status':
            $u = Http::requireUser();
            Http::json(SyncService::status(SyncService::resolveDevice(Http::input('device_id')), (int) $u['id']));
            break;

        case $method === 'GET' && $route === '/sync/changes':
            $u = Http::requirePermission('atthod', 'v');
            Http::json(SyncService::changes(Http::input('cursor') ? (string) Http::input('cursor') : null, (int) $u['id']));
            break;

        // --------------------------------------------------------------- HR

        case $method === 'GET' && $route === '/hr/reconciliation':
            Http::requirePermission('atthr', 'v');
            Http::json(array_merge(['ok' => true], HrService::queue()));
            break;

        case $method === 'POST' && $route === '/hr/reconciliation/resolve':
            Http::requireCsrf();
            $u = Http::requirePermission('atthr', 'u');
            $kind   = (string) Http::input('kind', '');
            $reason = (string) Http::input('reason', '');
            $out = match ($kind) {
                'arrival' => HrService::resolveArrival(
                    (int) Http::input('hod_arrival_id', 0), (string) Http::input('action', ''),
                    ((int) Http::input('entry_cycle_id', 0)) ?: null, $reason, (int) $u['id']),
                'cycle'   => HrService::closeCycle(
                    (int) Http::input('entry_cycle_id', 0),
                    Time::parse((string) Http::input('closed_at', ''), (string) Http::input('timezone', 'Asia/Karachi')),
                    $reason, (int) $u['id']),
                'event'   => HrService::reviewEvent(
                    (string) Http::input('event_uuid', ''), (string) Http::input('action', ''), $reason, (int) $u['id']),
                default   => ['ok' => false, 'reason_code' => 'VALIDATION', 'error' => 'kind must be arrival, cycle or event.'],
            };
            Http::json($out, ($out['ok'] ?? false) ? 200 : 422);
            break;

        // -------------------------------------------------------- overtime
        /* Blueprint §4. Who does what, by permission letter:
             attot   c   HOD: request, for their own departments only
             attotap u   HR / Accounts / Admin: approve, reject, amend
             attotvf u   HR / Accounts / Admin (a gatekeeper only if Settings
                         allows it): enter next-day actual minutes
             attpay  u   Accounts / Admin: rate, finalize, correct
           The department a HOD may ask for comes from att_user_roles on the
           server; a department_id sent by a phone is checked against it. */

        case $method === 'POST' && $route === '/overtime/requests':
            Http::requireCsrf();
            $u = Http::requirePermission('attot', 'c');
            admin_answer(OT::createRequest(Http::body(), ot_request_scope((int) $u['id']), (int) $u['id']));
            break;

        /* Who a requester may ask for overtime for: their departments and
           the active people in them. */
        case $method === 'GET' && $route === '/overtime/employees':
            $u = Http::requirePermission('attot', 'v');
            $scope = ot_request_scope((int) $u['id']);
            $depts = array_values(array_filter(Employees::departments(true), fn($d) => $scope === null || in_array((int) $d['id'], $scope, true)));
            Http::json(['ok' => true, 'departments' => $depts,
                        'employees' => $depts === [] ? [] : Employees::syncSnapshot(array_map(fn($d) => (int) $d['id'], $depts))['employees']]);
            break;

        case $method === 'GET' && $route === '/overtime/requests':
            $u = Http::requireUser();
            Http::json(OT::requests([
                'status' => Http::input('status', 'all'), 'from' => Http::input('from', ''), 'to' => Http::input('to', ''),
                'department_id' => Http::input('department_id', 0),
            ], ot_view_scope((int) $u['id'])));
            break;

        case $method === 'GET' && preg_match('#^/overtime/requests/(\d+)$#', $route, $m) === 1:
            $u = Http::requireUser();
            admin_answer(OT::request((int) $m[1], ot_view_scope((int) $u['id'])));
            break;

        case $method === 'POST' && preg_match('#^/overtime/requests/(\d+)/decisions$#', $route, $m) === 1:
            Http::requireCsrf();
            $u  = Http::requirePermission('attotap', 'u');
            $ed = Http::input('employee_decisions', []);
            admin_answer(OT::decide((int) $m[1], (string) Http::input('request_uuid', ''), is_array($ed) ? array_values($ed) : [], (int) $u['id']));
            break;

        case $method === 'GET' && $route === '/overtime/verification':
            Http::requirePermission('attotvf', 'v');
            Http::json(OT::verification((string) Http::input('date', ''), null));
            break;

        case $method === 'POST' && preg_match('#^/overtime/requests/(\d+)/verify-hours$#', $route, $m) === 1:
            Http::requireCsrf();
            $u = Http::requirePermission('attotvf', 'u');
            admin_answer(OT::verify((int) $m[1], (int) Http::input('request_employee_id', 0), Http::input('actual_minutes', null),
                (string) Http::input('verification_note', ''), Http::input('evidence_refs', null), (int) $u['id'], ot_may_verify((int) $u['id'])));
            break;

        case $method === 'POST' && preg_match('#^/overtime/requests/(\d+)/dispute$#', $route, $m) === 1:
            Http::requireCsrf();
            $u = Http::requirePermission('attotvf', 'u');
            admin_answer(OT::dispute((int) $m[1], (int) Http::input('request_employee_id', 0), (string) Http::input('verification_note', ''),
                (int) $u['id'], ot_may_verify((int) $u['id'])));
            break;

        case $method === 'POST' && preg_match('#^/overtime/requests/(\d+)/amend$#', $route, $m) === 1:
            Http::requireCsrf();
            $change = (string) Http::input('change', '');
            if ($change === 'actual_minutes') {
                $u = Http::requirePermission('attotvf', 'u');
                if (!ot_is_officer((int) $u['id'])) {
                    Http::fail('FORBIDDEN', 'Only HR, Accounts or Admin can amend verified minutes.', 403);
                }
            } else {
                $u = Http::requirePermission('attotap', 'u');
            }
            admin_answer(OT::amend((int) $m[1], (int) Http::input('request_employee_id', 0), $change, Http::input('value', null),
                (string) Http::input('reason', ''), (int) $u['id']));
            break;

        case $method === 'GET' && preg_match('#^/overtime/lines/(\d+)/history$#', $route, $m) === 1:
            $u = Http::requireUser();
            if (!Roles::can('attotap', 'v') && !Roles::can('attotvf', 'v') && !Roles::can('attpay', 'v')) {
                Http::fail('FORBIDDEN', 'You do not have permission for this action.', 403);
            }
            Http::json(OT::history((int) $m[1]));
            break;

        case $method === 'GET' && $route === '/overtime/payroll':
            Http::requirePermission('attpay', 'v');
            admin_answer(OT::payroll((string) Http::input('from', ''), (string) Http::input('to', '')));
            break;

        case $method === 'POST' && $route === '/overtime/payroll/rate':
            Http::requireCsrf();
            $u = Http::requirePermission('attpay', 'u');
            admin_answer(OT::setRate((int) Http::input('request_employee_id', 0), (string) Http::input('rate_type', ''),
                (string) Http::input('manual_rate', ''), (int) $u['id']));
            break;

        case $method === 'POST' && $route === '/overtime/payroll/finalize':
            Http::requireCsrf();
            $u = Http::requirePermission('attpay', 'u');
            admin_answer(OT::finalize((string) Http::input('run_uuid', ''), (string) Http::input('from', ''), (string) Http::input('to', ''),
                (string) Http::input('expected_total', ''), (string) Http::input('note', ''), (int) $u['id']));
            break;

        case $method === 'POST' && $route === '/overtime/payroll/correct':
            Http::requireCsrf();
            $u = Http::requirePermission('attpay', 'u');
            admin_answer(OT::correct((int) Http::input('request_employee_id', 0), Http::input('minutes', null), (string) Http::input('rate_type', ''),
                (string) Http::input('manual_rate', ''), (string) Http::input('reason', ''), (int) $u['id']));
            break;

        case $method === 'GET' && $route === '/overtime/payroll/runs':
            Http::requirePermission('attpay', 'v');
            Http::json(OT::runs());
            break;

        case $method === 'GET' && preg_match('#^/overtime/payroll/runs/(\d+)/export$#', $route, $m) === 1:
            $u = Http::requirePermission('attpay', 'v');
            $r = OT::runLines((int) $m[1]);
            if (!$r['ok']) {
                Http::fail('NOT_FOUND', $r['error'], 404);
            }
            Audit::record('ot.payroll_export', 'att_ot_payroll_run', (int) $m[1], null, ['lines' => count($r['lines'])], (int) $u['id']);
            send_csv('overtime-' . $r['run']['period_from'] . '-to-' . $r['run']['period_to'] . '.csv',
                ['Work date', 'Department', 'Code', 'Name', 'Approved min', 'Actual min', 'Rate type', 'Rate', 'Payable min', 'Amount (finalized)', 'Amount now (after corrections)'],
                array_map(fn($l) => [$l['work_date'], $l['department_name'], $l['employee_code'], $l['full_name'], $l['approved_minutes'], $l['actual_minutes'],
                                     $l['rate_type'], $l['manual_rate'], $l['payable_minutes'], $l['payable_amount'], $l['effective_amount'] ?? $l['payable_amount']], $r['lines']));
            break;

        // ----------------------------------------------------- capture & flag
        /* A flag is the ONLY thing a still can be attached to, and nothing in
           /attendance/in, /attendance/out or /sync/events can make one.
           Phones name the scan a flag is about by that scan's own id
           (POST /flags), because offline they cannot know the entry number.
           The three /attendance/{cycle_id}/... routes are the ones
           API_CONTRACT.md lists, for a caller that does know it. */

        case $method === 'POST' && $route === '/flags':
            Http::requireCsrf();
            $u = Http::requirePermission('attflag', 'c');
            flag_answer(PhotoService::createFlag(Http::body(), (int) $u['id']));
            break;

        case $method === 'POST' && preg_match('#^/flags/([0-9a-fA-F-]{36})/photo$#', $route, $m) === 1:
            Http::requireCsrf();
            $u = Http::requirePermission('attflag', 'c');
            Http::rateLimit('flagphoto:user:' . (int) $u['id'], 120, 600);
            flag_answer(PhotoService::storeFlagPhoto($m[1], uploaded_image(max(65536, Policy::int('photo_max_bytes', 5242880))), (int) $u['id']));
            break;

        case $method === 'POST' && preg_match('#^/attendance/(\d+)/flag$#', $route, $m) === 1:
            Http::requireCsrf();
            $u  = Http::requirePermission('attflag', 'c');
            $in = Http::body();
            $in['entry_cycle_id'] = (int) $m[1];
            unset($in['gate_event_uuid']);
            if (trim((string) ($in['flag_uuid'] ?? '')) === '') {
                $b = random_bytes(16);
                $b[6] = chr((ord($b[6]) & 0x0f) | 0x40);
                $b[8] = chr((ord($b[8]) & 0x3f) | 0x80);
                $in['flag_uuid'] = vsprintf('%s%s-%s-%s-%s-%s%s%s', str_split(bin2hex($b), 4));
            }
            flag_answer(PhotoService::createFlag($in, (int) $u['id']));
            break;

        /* Where to send the still. The contract's name says "URL" and the
           answer is one — on this server. The bytes are checked and re-made
           here before anything is stored, so no phone is ever handed a way
           to write into the bucket directly. */
        case $method === 'POST' && preg_match('#^/attendance/(\d+)/photo-upload-url$#', $route, $m) === 1:
            Http::requireCsrf();
            Http::requirePermission('attflag', 'c');
            $flag = Db::one('SELECT flag_uuid, photo_key, photo_purged_at FROM att_flags WHERE flag_uuid = ? AND entry_cycle_id = ?',
                [strtolower(trim((string) Http::input('flag_uuid', ''))), (int) $m[1]]);
            if ($flag === null) {
                Http::fail('FLAG_NOT_FOUND', 'Flag the entry first; a photo belongs to a flag.', 404);
            }
            if ($flag['photo_key'] !== null || $flag['photo_purged_at'] !== null) {
                Http::fail('PHOTO_EXISTS', 'This flag already has a photo.', 409);
            }
            Http::json(['ok' => true, 'upload' => [
                'method'    => 'POST',
                'url'       => 'api/v1/index.php?route=' . rawurlencode('/flags/' . $flag['flag_uuid'] . '/photo'),
                'field'     => 'photo',
                'max_bytes' => max(65536, Policy::int('photo_max_bytes', 5242880)),
                'accepts'   => Images::ALLOWED,
            ]]);
            break;

        case $method === 'POST' && preg_match('#^/attendance/(\d+)/photo-confirm$#', $route, $m) === 1:
            Http::requireCsrf();
            Http::requirePermission('attflag', 'c');
            $flag = Db::one('SELECT id, photo_key, photo_sha256, photo_bytes, photo_stored_at FROM att_flags WHERE flag_uuid = ? AND entry_cycle_id = ?',
                [strtolower(trim((string) Http::input('flag_uuid', ''))), (int) $m[1]]);
            if ($flag === null) {
                Http::fail('FLAG_NOT_FOUND', 'That flag does not exist.', 404);
            }
            if ($flag['photo_key'] === null) {
                Http::fail('PHOTO_NOT_STORED', 'No photo has been stored for this flag.', 409);
            }
            $sha = strtolower(trim((string) Http::input('sha256', '')));
            if ($sha !== '' && !hash_equals((string) $flag['photo_sha256'], $sha)) {
                Http::fail('PHOTO_MISMATCH', 'The stored photo is not the one this phone sent.', 409);
            }
            Http::json(['ok' => true, 'flag_id' => (int) $flag['id'], 'stored' => true,
                        'bytes' => (int) $flag['photo_bytes'], 'stored_at' => Http::iso((string) $flag['photo_stored_at'])]);
            break;

        case $method === 'GET' && $route === '/hr/flags':
            Http::requirePermission('atthr', 'v');
            try { PhotoService::purgeIfDue(); } catch (Throwable $e) { error_log('[att-api] retention purge: ' . $e->getMessage()); }
            Http::json(PhotoService::flags());
            break;

        case $method === 'GET' && preg_match('#^/hr/flags/(\d+)/photo$#', $route, $m) === 1:
            $u = Http::requirePermission('atthr', 'v');
            Http::rateLimit('photo:user:' . (int) $u['id'], 900, 600);
            send_image(PhotoService::flagPhoto((int) $m[1], Http::input('which', 'flag') === 'reference' ? 'reference' : 'flag', (int) $u['id']));
            break;

        case $method === 'POST' && preg_match('#^/hr/flags/(\d+)/review$#', $route, $m) === 1:
            Http::requireCsrf();
            $u = Http::requirePermission('atthr', 'u');
            admin_answer(PhotoService::reviewFlag((int) $m[1], (string) Http::input('reason', ''), (int) $u['id']));
            break;

        // ------------------------------------------------- reference photos

        case $method === 'GET' && $route === '/admin/photos/status':
            Http::requirePermission('attadm', 'v');
            Http::json(PhotoService::status());
            break;

        case $method === 'POST' && $route === '/admin/photos/test':
            Http::requireCsrf();
            $u = Http::requirePermission('attadm', 'u');
            admin_answer(PhotoService::selfTest((int) $u['id']));
            break;

        case $method === 'POST' && $route === '/admin/photos/import':
            Http::requireCsrf();
            $u    = Http::requirePermission('attadm', 'u');
            $skip = Http::input('skip', []);
            @set_time_limit(120);
            admin_answer(PhotoService::importBatch((int) Http::input('limit', 10),
                is_array($skip) ? array_values(array_filter($skip, 'is_string')) : [],
                (bool) Http::input('notice_given', false), (int) $u['id']));
            break;

        case $method === 'GET' && $route === '/admin/employees/photo':
            $u = Http::requirePermission('attadm', 'v');
            Http::rateLimit('photo:user:' . (int) $u['id'], 900, 600);
            send_image(PhotoService::reference((int) Http::input('id', 0), (int) $u['id'], 'setup screen'));
            break;

        case $method === 'POST' && $route === '/admin/employees/photo':
            Http::requireCsrf();
            $u = Http::requirePermission('attadm', 'u');
            admin_answer(PhotoService::setReference((int) ($_POST['id'] ?? 0), uploaded_image(Images::MAX_SOURCE),
                in_array((string) ($_POST['notice_given'] ?? ''), ['1', 'true', 'on'], true), (int) $u['id']));
            break;

        case $method === 'POST' && $route === '/admin/employees/photo/remove':
            Http::requireCsrf();
            $u = Http::requirePermission('attadm', 'u');
            admin_answer(PhotoService::removeReference((int) Http::input('id', 0), (string) Http::input('reason', ''), (int) $u['id']));
            break;

        // ------------------------------------------------------------ setup
        /* The Attendance Setup screen. Not in API_CONTRACT.md — the contract
           lists what the phones and reports need and leaves the masters to
           the implementation — so these live under /admin and nothing a
           phone uses depends on them.

           Departments, employees and shifts are open to whoever holds the
           "Employees, Shifts, Devices" permission. Who holds which attendance
           job, and the settings, are for an ERP admin only: handing out
           access is not something a setup clerk should be able to do for
           themselves. */

        case $method === 'GET' && $route === '/admin/overview':
            Http::requirePermission('attadm', 'v');
            Http::json(AdminService::overview());
            break;

        case $method === 'GET' && $route === '/admin/departments':
            Http::requirePermission('attadm', 'v');
            Http::json(AdminService::departments());
            break;

        case $method === 'POST' && $route === '/admin/departments':
            Http::requireCsrf();
            $id = ((int) Http::input('id', 0)) ?: null;
            $u  = Http::requirePermission('attadm', $id === null ? 'c' : 'u');
            $active = Http::input('active', null);
            admin_answer(AdminService::saveDepartment($id, (string) Http::input('name', ''),
                $active === null ? null : (bool) $active, (int) $u['id']));
            break;

        case $method === 'POST' && $route === '/admin/departments/seed':
            Http::requireCsrf();
            $u = Http::requirePermission('attadm', 'c');
            Http::json(['ok' => true, 'added' => Employees::seedDepartmentsFromInventory((int) $u['id'])]);
            break;

        case $method === 'GET' && $route === '/admin/employees':
            Http::requirePermission('attadm', 'v');
            Http::json(AdminService::employees([
                'query' => Http::input('query', ''), 'department_id' => Http::input('department_id', 0),
                'state' => Http::input('state', 'active'), 'page' => Http::input('page', 1),
            ]));
            break;

        case $method === 'POST' && $route === '/admin/employees':
            Http::requireCsrf();
            $id = ((int) Http::input('id', 0)) ?: null;
            $u  = Http::requirePermission('attadm', $id === null ? 'c' : 'u');
            admin_answer(AdminService::saveEmployee($id, Http::body(), (int) $u['id']));
            break;

        case $method === 'POST' && $route === '/admin/employees/active':
            Http::requireCsrf();
            $active = (bool) Http::input('active', false);
            // Taking somebody off the roll is the 'delete' letter; nothing is
            // ever actually deleted.
            $u = Http::requirePermission('attadm', $active ? 'u' : 'd');
            admin_answer(AdminService::setEmployeeActive((int) Http::input('id', 0), $active,
                (string) Http::input('reason', ''), (int) $u['id']));
            break;

        case $method === 'POST' && $route === '/admin/employees/import':
            Http::requireCsrf();
            $u    = Http::requirePermission('attadm', 'c');
            $rows = Http::input('rows', []);
            admin_answer(AdminService::importEmployees(
                is_array($rows) ? array_values(array_filter($rows, 'is_array')) : [],
                (bool) Http::input('commit', false), (bool) Http::input('create_departments', false), (int) $u['id']));
            break;

        case $method === 'GET' && $route === '/admin/workers':
            Http::requirePermission('attadm', 'v');
            Http::json(AdminService::unlinkedWorkers());
            break;

        case $method === 'GET' && $route === '/admin/shifts':
            Http::requirePermission('attadm', 'v');
            Http::json(AdminService::shifts());
            break;

        case $method === 'POST' && $route === '/admin/shifts':
            Http::requireCsrf();
            $id = ((int) Http::input('id', 0)) ?: null;
            $u  = Http::requirePermission('attadm', $id === null ? 'c' : 'u');
            admin_answer(AdminService::saveShift($id, Http::body(), (int) $u['id']));
            break;

        case $method === 'POST' && $route === '/admin/shifts/assign':
            Http::requireCsrf();
            $u   = Http::requirePermission('attadm', 'c');
            $wd  = Http::input('weekdays', []);
            $ids = Http::input('employee_ids', []);
            admin_answer(AdminService::assignRange(
                (int) Http::input('shift_id', 0), (string) Http::input('from', ''), (string) Http::input('to', ''),
                is_array($wd) ? array_values($wd) : [], (int) Http::input('department_id', 0),
                is_array($ids) ? array_values($ids) : [], (int) $u['id']));
            break;

        case $method === 'GET' && $route === '/admin/users':
            require_erp_admin();
            Http::json(AdminService::users());
            break;

        case $method === 'POST' && ($route === '/admin/jobs/grant' || $route === '/admin/jobs/revoke'):
            Http::requireCsrf();
            $u = require_erp_admin();
            $args = [(int) Http::input('user_id', 0), (string) Http::input('role', ''),
                     (int) Http::input('department_id', 0), (int) $u['id']];
            admin_answer($route === '/admin/jobs/grant' ? AdminService::grantJob(...$args) : AdminService::revokeJob(...$args));
            break;

        case $method === 'GET' && $route === '/admin/settings':
            Http::requirePermission('attadm', 'v');
            Http::json(AdminService::settings());
            break;

        case $method === 'POST' && $route === '/admin/settings':
            Http::requireCsrf();
            $u = require_erp_admin();
            $values = Http::input('values', []);
            admin_answer(AdminService::saveSettings(is_array($values) ? $values : [], (int) $u['id']));
            break;

        // ------------------------------------------- declared, not yet built

        case in_array($route, [
            '/reports/attendance', '/reports/movements', '/reports/overtime',
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
 * The bytes of the one uploaded picture, or a refusal.
 * What the file IS gets decided later, by reading it (Att\Images) — its name
 * and the type the browser claimed are not looked at here or anywhere.
 */
function uploaded_image(int $maxBytes): string
{
    $f   = $_FILES['photo'] ?? null;
    $err = is_array($f) ? (int) ($f['error'] ?? UPLOAD_ERR_NO_FILE) : UPLOAD_ERR_NO_FILE;
    if ($err === UPLOAD_ERR_INI_SIZE || $err === UPLOAD_ERR_FORM_SIZE) {
        Http::fail('IMAGE_TOO_LARGE', 'That picture is too large to upload.', 413);
    }
    if ($err !== UPLOAD_ERR_OK || !is_uploaded_file((string) $f['tmp_name'])) {
        Http::fail('VALIDATION', 'No picture was received.', 422);
    }
    if ((int) filesize((string) $f['tmp_name']) > $maxBytes) {
        Http::fail('IMAGE_TOO_LARGE', 'That picture is larger than ' . round($maxBytes / 1048576, 1) . ' MB.', 413);
    }
    $bytes = file_get_contents((string) $f['tmp_name']);
    if ($bytes === false || $bytes === '') {
        Http::fail('VALIDATION', 'The picture could not be read.', 422);
    }
    return $bytes;
}

/**
 * Send a picture to a caller who has already been authorised.
 * no-store, because a private picture must not be left in a phone's cache
 * (blueprint §6: "minimal local cache"); nosniff, so a browser shows it as the
 * image the server says it is and nothing else.
 */
function send_image(array $r): never
{
    if (!($r['ok'] ?? false)) {
        $code = (string) ($r['reason_code'] ?? '');
        Http::fail($code, (string) ($r['error'] ?? ''), in_array($code, ['NO_PHOTO', 'NOT_FOUND'], true) ? 404 : 503);
    }
    http_response_code(200);
    header('Content-Type: ' . $r['mime']);
    header('Content-Length: ' . strlen($r['bytes']));
    header('Cache-Control: private, no-store');
    header('X-Content-Type-Options: nosniff');
    header('Content-Disposition: inline');
    echo $r['bytes'];
    exit;
}

/**
 * An answer about a flag or its photo. 409/503 mean "keep it and try again";
 * 4xx otherwise means the server has decided and the phone should stop.
 */
function flag_answer(array $out): never
{
    if ($out['ok'] ?? false) {
        Http::json($out, 200);
    }
    $code = (string) ($out['reason_code'] ?? '');
    $status = match (true) {
        $code === 'GATE_EVENT_NOT_RECEIVED'                              => 409,
        (bool) ($out['retry'] ?? false)                                  => 503,
        in_array($code, ['FLAG_NOT_FOUND', 'CYCLE_NOT_FOUND'], true)     => 404,
        $code === 'IMAGE_TOO_LARGE'                                      => 413,
        $code === 'PHOTO_EXISTS'                                         => 409,
        default                                                          => 422,
    };
    Http::json($out, $status);
}

/** Departments this user may request overtime for. null = any (an admin). */
function ot_request_scope(int $userId): ?array
{
    return Roles::isAdmin($userId) ? null : (Roles::departmentScope($userId, Roles::HOD) ?? []);
}

/** Which requests this user may see: officers see all, a HOD their own departments. */
function ot_view_scope(int $userId): ?array
{
    if (Roles::can('attotap', 'v') || Roles::can('attotvf', 'v') || Roles::can('attpay', 'v')) {
        return null;
    }
    if (Roles::can('attot', 'v')) {
        return ot_request_scope($userId);
    }
    Http::fail('FORBIDDEN', 'You do not have permission for this action.', 403);
}

/** HR, Accounts or Admin — the officers blueprint §4 names. */
function ot_is_officer(int $userId): bool
{
    return Roles::isAdmin($userId) || Roles::has($userId, Roles::HR) || Roles::has($userId, Roles::ACCOUNTS);
}

/**
 * Blueprint §4: next-day minutes are entered by HR/Admin/Accounts "or
 * explicitly permitted gatekeeper". Explicitly = the verify permission in
 * User Access AND the setting switched on; a gatekeeper with only one of the
 * two cannot.
 */
function ot_may_verify(int $userId): bool
{
    if (ot_is_officer($userId) || !Roles::has($userId, Roles::GATEKEEPER)) {
        return true;
    }
    return Policy::bool('gatekeeper_may_verify_ot', false);
}

/**
 * A spreadsheet download. A cell that begins with = + - @ is read by Excel as
 * a formula, which is how a name typed as "=HYPERLINK(...)" becomes a link
 * on the accountant's machine; such text cells get a leading apostrophe.
 * Numbers are left alone.
 *
 * @param list<string> $head
 * @param list<list<mixed>> $rows
 */
function send_csv(string $name, array $head, array $rows): never
{
    $safe = static function ($v): string {
        if ($v === null) {
            return '';
        }
        $v = (string) $v;
        if (!is_numeric($v) && preg_match('/^[=+\-@\t\r]/', $v)) {
            $v = "'" . $v;
        }
        return '"' . str_replace('"', '""', $v) . '"';
    };
    $out = "\xEF\xBB\xBF" . implode(',', array_map($safe, $head)) . "\r\n";
    foreach ($rows as $r) {
        $out .= implode(',', array_map($safe, $r)) . "\r\n";
    }
    http_response_code(200);
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="' . preg_replace('/[^A-Za-z0-9._-]/', '', $name) . '"');
    header('Cache-Control: private, no-store');
    header('X-Content-Type-Options: nosniff');
    echo $out;
    exit;
}

/** A service answer, with a status code a caller can branch on. */
function admin_answer(array $out): never
{
    if ($out['ok'] ?? false) {
        Http::json($out, 200);
    }
    $code = (string) ($out['reason_code'] ?? '');
    Http::json($out, match ($code) { 'NOT_FOUND' => 404, 'FORBIDDEN' => 403, 'PAYROLL_LOCKED', 'ALREADY_DECIDED', 'TOTAL_CHANGED' => 409, default => 422 });
}

/**
 * The employee list, lookup and photo are for the two people who face the
 * employee: the gatekeeper and the HOD. Either permission opens them; WHO
 * each may see is then decided by employee_scope_for() — a HOD gets their own
 * department and nobody else.
 *
 * These routes used to ask for the gate permission alone. A HOD given the job
 * from the People tab holds only the HOD permission, so their phone could not
 * load its employee list at all: codes typed on it showed no name. Found when
 * the photo was added, because there was then no face either.
 */
function require_gate_or_hod(): array
{
    $u = Http::requireUser();
    if (!Roles::can('attgate', 'v') && !Roles::can('atthod', 'v')) {
        Http::fail('FORBIDDEN', 'You do not have permission for this action.', 403);
    }
    return $u;
}

/**
 * Only an ERP admin account. Deliberately not a zu_perm letter: the people
 * who may hand out attendance access are the same people who may hand out
 * every other access in the ERP, and nobody else.
 */
function require_erp_admin(): array
{
    $u = Http::requireUser();
    if (($u['role'] ?? '') !== 'admin') {
        Http::fail('FORBIDDEN', 'Only an administrator can change this.', 403);
    }
    return $u;
}

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

    /* Through SyncService like every other event, so a scan held for review
       keeps its envelope and HR can re-run it once the cause is fixed. */
    $receipt = SyncService::process([
        'event_uuid'      => (string) Http::input('event_uuid', ''),
        'event_type'      => $direction === 'in' ? 'GATE_IN' : 'GATE_OUT',
        'employee_id'     => $employeeId,
        'employee_code'   => $employeeCode,
        'device_id'       => Http::input('device_id', 0),
        'device_sequence' => (int) Http::input('device_sequence', 0),
        'occurred_at'     => (string) Http::input('occurred_at', ''),
        'timezone'        => (string) Http::input('timezone', ''),
    ], $operatorId, true);

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
