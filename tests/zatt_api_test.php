<?php
declare(strict_types=1);

/* ATTENDANCE API — driven over real HTTP, against the real stack.
 *
 * Not a unit test of the handlers. A PHP server is started on the actual
 * public_html, so bootstrap.php, the session, zu_perm and the router all run
 * exactly as they do in production. The things worth proving here cannot be
 * proved any other way:
 *
 *   - a write without a CSRF token is refused
 *   - a signed-out caller gets 401, and a signed-in one without permission
 *     gets 403 — from the server, not from a hidden button
 *   - a HOD asking for an employee outside their department is told "no such
 *     employee", the same answer as for a code that does not exist, so the
 *     endpoint cannot be used to find out who works where
 *   - login does not say whether it was the email or the password that was
 *     wrong
 *   - endpoints from later milestones answer 501, not 404, so the PWA can
 *     tell "not built yet" from "wrong address"
 */

$B = __DIR__ . '/app_src/public_html/';
$P = 0; $F = 0;
function ok($c, $m) { global $P, $F; if ($c) { $P++; } else { $F++; echo "  FAIL: $m\n"; } }

$dsn  = getenv('ATT_TEST_DSN') ?: 'mysql:host=127.0.0.1;port=3306;dbname=zas_att_test;charset=utf8mb4';
$user = getenv('ATT_TEST_USER') ?: 'zas';
$pass = getenv('ATT_TEST_PASS'); if ($pass === false) { $pass = 'zaspw'; }

if (!is_file($B . 'config/config.php')) {
    echo "SKIP: no test config at public_html/config/config.php — run tests/run_att.sh\n";
    exit(0);
}

require_once $B . 'includes/att/Db.php';
require_once $B . 'includes/att/Migrations.php';
require_once $B . 'includes/att/Policy.php';
require_once $B . 'includes/att/Audit.php';
require_once $B . 'includes/att/Roles.php';
require_once $B . 'includes/att/Employees.php';
require_once $B . 'includes/att/Shifts.php';

use Att\Db;
use Att\Employees;
use Att\Migrations;
use Att\Roles;

try {
    $pdo = new PDO($dsn, $user, $pass, [
        PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        PDO::ATTR_EMULATE_PREPARES   => false,
    ]);
} catch (Throwable $e) {
    echo "SKIP: no MySQL available ({$e->getMessage()})\n";
    exit(0);
}
Db::bind($pdo);

/* ---- a clean database with the ERP stand-ins the module integrates with -- */
$pdo->exec('SET FOREIGN_KEY_CHECKS=0');
foreach ($pdo->query("SELECT table_name AS tn FROM information_schema.tables WHERE table_schema = DATABASE()")->fetchAll() as $t) {
    $pdo->exec('DROP TABLE IF EXISTS `' . $t['tn'] . '`');
}
$pdo->exec('SET FOREIGN_KEY_CHECKS=1');
$pdo->exec("CREATE TABLE users (id INT AUTO_INCREMENT PRIMARY KEY, name VARCHAR(150) NOT NULL,
            email VARCHAR(190) NOT NULL UNIQUE, password_hash VARCHAR(255) NOT NULL,
            role VARCHAR(40) NOT NULL DEFAULT 'colleague', department VARCHAR(60) NULL,
            can_see_rates TINYINT(1) NOT NULL DEFAULT 0,
            is_active TINYINT(1) NOT NULL DEFAULT 1) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
$pdo->exec("CREATE TABLE zp_workers (id INT AUTO_INCREMENT PRIMARY KEY, worker_code VARCHAR(20) NOT NULL UNIQUE,
            worker_name VARCHAR(120) NOT NULL, department VARCHAR(60) NULL,
            is_active TINYINT(1) NOT NULL DEFAULT 1) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

(new Migrations($B . 'sql/att'))->up();

$hash = password_hash('CorrectHorse9', PASSWORD_DEFAULT);
$pdo->prepare("INSERT INTO users (name,email,password_hash,role) VALUES (?,?,?,?)")
    ->execute(['Admin Person', 'admin@zas.test', $hash, 'admin']);
$adminId = (int) $pdo->lastInsertId();
$pdo->prepare("INSERT INTO users (name,email,password_hash,role) VALUES (?,?,?,?)")
    ->execute(['Hod Person', 'hod@zas.test', $hash, 'staff']);
$hodId = (int) $pdo->lastInsertId();

$dStitch = Employees::createDepartment('Stitching');
$dPack   = Employees::createDepartment('Packing');
Employees::create(['code' => 'E001', 'full_name' => 'Imran Ali', 'department_id' => $dStitch]);
Employees::create(['code' => 'E002', 'full_name' => 'Sara Khan', 'department_id' => $dPack]);
Roles::grant($hodId, Roles::HOD, $dStitch);
Roles::grant($adminId, Roles::ADMIN);

/* Two different authorisation paths are exercised deliberately:
   the admin passes by zu_can()'s admin bypass, and the HOD passes only
   because of a real zu_perm row. The HOD is given attgate 'v' and NOT
   attadm, so step 8 proves a well-formed request with a valid CSRF token is
   still refused when the letter is missing. */
$pdo->exec("CREATE TABLE IF NOT EXISTS zu_perm (
    id INT AUTO_INCREMENT PRIMARY KEY, user_id INT NOT NULL, module VARCHAR(24) NOT NULL,
    actions VARCHAR(12) NOT NULL DEFAULT '', updated_by INT NULL,
    updated_at DATETIME DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY uniq_user_module (user_id, module), INDEX(user_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
$pdo->prepare("INSERT INTO zu_perm (user_id, module, actions) VALUES (?,?,?)")
    ->execute([$hodId, 'attgate', 'v']);

/* ---------------------------------------------------------- the server --- */
$port = 8719;
$docroot = rtrim($B, '/');
$srv = proc_open(
    sprintf('exec php -S 127.0.0.1:%d -t %s', $port, escapeshellarg($docroot)),
    [1 => ['file', '/tmp/att_api_srv.log', 'a'], 2 => ['file', '/tmp/att_api_srv.log', 'a']],
    $pipes
);
register_shutdown_function(static function () use ($srv) {
    if (is_resource($srv)) { proc_terminate($srv, 9); proc_close($srv); }
});
for ($i = 0; $i < 60; $i++) {
    $c = @fsockopen('127.0.0.1', $port, $e1, $e2, 0.2);
    if ($c) { fclose($c); break; }
    usleep(200000);
}

$jar = tempnam(sys_get_temp_dir(), 'attjar');

/** @return array{status:int,body:array,raw:string} */
function call(string $method, string $path, array $data = [], array $headers = []): array
{
    global $port, $jar;
    $ch = curl_init("http://127.0.0.1:$port/api/v1/index.php?route=" . rawurlencode($path));
    $h = ['Accept: application/json'];
    foreach ($headers as $k => $v) { $h[] = "$k: $v"; }
    $opts = [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_COOKIEJAR      => $jar,
        CURLOPT_COOKIEFILE     => $jar,
        CURLOPT_TIMEOUT        => 20,
        CURLOPT_CUSTOMREQUEST  => $method,
    ];
    if ($method !== 'GET') {
        $opts[CURLOPT_POSTFIELDS] = json_encode($data);
        $h[] = 'Content-Type: application/json';
    } elseif ($data !== []) {
        curl_setopt($ch, CURLOPT_URL, "http://127.0.0.1:$port/api/v1/index.php?route=" . rawurlencode($path) . '&' . http_build_query($data));
    }
    $opts[CURLOPT_HTTPHEADER] = $h;
    curl_setopt_array($ch, $opts);
    $raw = (string) curl_exec($ch);
    $status = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    $body = json_decode($raw, true);
    return ['status' => $status, 'body' => is_array($body) ? $body : [], 'raw' => $raw];
}

echo "1. A signed-out caller gets 401, not data\n";
$r = call('GET', '/auth/me');
ok($r['status'] === 401, '/auth/me is 401 when signed out');
ok(($r['body']['reason_code'] ?? '') === 'NOT_AUTHENTICATED', '  with a machine-readable reason_code');
$r = call('GET', '/employees');
ok($r['status'] === 401, '/employees is 401 when signed out');

echo "2. Login does not reveal which half was wrong\n";
$badPass  = call('POST', '/auth/login', ['email' => 'admin@zas.test', 'password' => 'wrong']);
$noSuch   = call('POST', '/auth/login', ['email' => 'ghost@zas.test', 'password' => 'wrong']);
ok($badPass['status'] === 401 && $noSuch['status'] === 401, 'both are 401');
ok(($badPass['body']['error'] ?? 'a') === ($noSuch['body']['error'] ?? 'b'),
   'A WRONG PASSWORD AND AN UNKNOWN EMAIL GIVE THE SAME MESSAGE');

echo "3. A real login works and returns a CSRF token\n";
$login = call('POST', '/auth/login', ['email' => 'admin@zas.test', 'password' => 'CorrectHorse9']);
ok($login['status'] === 200, 'login succeeds');
$csrf = (string) ($login['body']['csrf_token'] ?? '');
ok($csrf !== '', 'a CSRF token comes back for the PWA to use');
ok(!str_contains(strtolower($login['raw']), 'password_hash'), 'the password hash is NOT in the response');

$me = call('GET', '/auth/me');
ok($me['status'] === 200 && ($me['body']['user']['id'] ?? 0) > 0, '/auth/me now answers');
ok(($me['body']['user']['is_admin'] ?? false) === true, '  and knows this one is an admin');
ok(!isset($me['body']['user']['password_hash']), '  without leaking the hash');

echo "4. A write without a CSRF token is refused\n";
$noCsrf = call('POST', '/shifts/assignments', ['employee_id' => 1, 'shift_id' => 1, 'work_date' => '2026-03-02']);
ok($noCsrf['status'] === 419, 'no token → 419');
ok(($noCsrf['body']['reason_code'] ?? '') === 'CSRF_MISMATCH', '  reason_code says why');
$wrongCsrf = call('POST', '/shifts/assignments', ['employee_id' => 1], ['X-CSRF-Token' => 'not-the-token']);
ok($wrongCsrf['status'] === 419, 'a wrong token → 419');

echo "5. Validation happens after CSRF, and says what is missing\n";
$bad = call('POST', '/shifts/assignments', ['employee_id' => 0], ['X-CSRF-Token' => $csrf]);
ok($bad['status'] === 422, 'an incomplete assignment is 422');
ok(($bad['body']['reason_code'] ?? '') === 'VALIDATION', '  reason_code VALIDATION');

echo "6. Employees, and the department scope that AT06 depends on\n";
$all = call('GET', '/employees');
ok($all['status'] === 200 && count($all['body']['employees'] ?? []) === 2, 'an admin sees both employees');
$one = call('GET', '/employees/E001');
ok($one['status'] === 200 && ($one['body']['employee']['full_name'] ?? '') === 'Imran Ali', 'lookup by code');
$missing = call('GET', '/employees/NOPE');
ok($missing['status'] === 404, 'an unknown code is 404');

echo "7. A HOD is confined to their own department, by the server\n";
call('POST', '/auth/logout', [], ['X-CSRF-Token' => $csrf]);
$hodLogin = call('POST', '/auth/login', ['email' => 'hod@zas.test', 'password' => 'CorrectHorse9']);
ok($hodLogin['status'] === 200, 'the HOD signs in');
$hodCsrf = (string) ($hodLogin['body']['csrf_token'] ?? '');

$hodList = call('GET', '/employees');
$codes = array_column($hodList['body']['employees'] ?? [], 'code');
ok($hodList['status'] === 200, 'the HOD may list employees');
ok($codes === ['E001'], 'THE HOD SEES ONLY THEIR OWN DEPARTMENT (got: ' . implode(',', $codes) . ')');

$probe = call('GET', '/employees/E002');
ok($probe['status'] === 404, 'asking for another department\'s employee is 404');
ok(($probe['body']['reason_code'] ?? '') === 'EMPLOYEE_NOT_FOUND',
   '  AND THE SAME reason_code AS A CODE THAT DOES NOT EXIST — the endpoint cannot be used to discover who works where');

echo "8. Permission is checked server-side, not in the UI\n";
/* The HOD holds no attadm permission, so shift administration must refuse
   even though the request is perfectly well formed and carries a valid token. */
$forbidden = call('GET', '/shifts');
ok($forbidden['status'] === 403, 'a HOD gets 403 on an admin endpoint');
ok(($forbidden['body']['reason_code'] ?? '') === 'FORBIDDEN', '  reason_code FORBIDDEN');

echo "9. Later milestones answer 501, unknown routes 404\n";
$later = call('GET', '/sync/status');
ok($later['status'] === 501, '/sync/status is 501 (declared, not built)');
ok(($later['body']['reason_code'] ?? '') === 'NOT_IMPLEMENTED', '  reason_code NOT_IMPLEMENTED');
$nowhere = call('GET', '/does/not/exist');
ok($nowhere['status'] === 404 && ($nowhere['body']['reason_code'] ?? '') === 'NO_ROUTE', 'an unknown route is 404 NO_ROUTE');

echo "10. Errors are JSON, never an HTML page or a stack trace\n";
foreach ([$forbidden, $later, $nowhere, $noCsrf] as $resp) {
    ok(is_array($resp['body']) && isset($resp['body']['reason_code']), 'every failure is a JSON envelope');
}
ok(!str_contains($nowhere['raw'], 'Stack trace') && !str_contains($nowhere['raw'], '<br'),
   'no stack trace and no HTML leaks to a handset');

echo "11. Logging out really ends the session\n";
$out = call('POST', '/auth/logout', [], ['X-CSRF-Token' => $hodCsrf]);
ok($out['status'] === 200, 'logout succeeds');
ok(call('GET', '/auth/me')['status'] === 401, '  and /auth/me is 401 again afterwards');

@unlink($jar);
echo "\n$P passed, $F failed\n";
exit($F === 0 ? 0 : 1);
