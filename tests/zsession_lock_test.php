<?php
declare(strict_types=1);

/* ONE SLOW PHONE CALL MUST NOT HOLD UP THE NEXT (18 Nov 2026).
 * The server's slow log: 51 of 75 stuck requests were waiting in
 * session_start — queued behind another request of the same login, each
 * holding a PHP worker, until none were left (502). Over real HTTP, with a
 * server that answers several requests at once: one call is held up by a
 * table lock, and a second call from the same phone must still answer at
 * once. Run through tests/run_att.sh. */

$B = __DIR__ . '/app_src/public_html/';
$P = 0; $F = 0;
function ok($c, $m) { global $P, $F; if ($c) { $P++; } else { $F++; echo "  FAIL: $m\n"; } }
$dsn  = getenv('ATT_TEST_DSN') ?: 'mysql:host=127.0.0.1;port=3306;dbname=zas_att_test;charset=utf8mb4';
$user = getenv('ATT_TEST_USER') ?: 'zas';
$pass = getenv('ATT_TEST_PASS'); if ($pass === false) { $pass = 'zaspw'; }
if (!is_file($B . 'config/config.php')) { echo "SKIP: no test config — run tests/run_att.sh\n"; exit(0); }
foreach (['Db', 'Migrations', 'Policy', 'Audit', 'Roles', 'Employees', 'Time', 'Shifts'] as $c) { require_once $B . "includes/att/$c.php"; }
use Att\Db; use Att\Migrations;
try { $pdo = new PDO($dsn, $user, $pass, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC, PDO::ATTR_EMULATE_PREPARES => false]); }
catch (Throwable $e) { echo "SKIP: no MySQL\n"; exit(0); }
Db::bind($pdo); Db::useUtc();

$pdo->exec('SET FOREIGN_KEY_CHECKS=0');
foreach ($pdo->query("SELECT table_name AS tn FROM information_schema.tables WHERE table_schema = DATABASE()")->fetchAll() as $t) { $pdo->exec('DROP TABLE IF EXISTS `' . $t['tn'] . '`'); }
$pdo->exec('SET FOREIGN_KEY_CHECKS=1');
$pdo->exec("CREATE TABLE users (id INT AUTO_INCREMENT PRIMARY KEY, name VARCHAR(150) NOT NULL, email VARCHAR(190) NOT NULL UNIQUE, password_hash VARCHAR(255) NOT NULL,
            role VARCHAR(40) NOT NULL DEFAULT 'colleague', department VARCHAR(60) NULL, can_see_rates TINYINT(1) NOT NULL DEFAULT 0, is_active TINYINT(1) NOT NULL DEFAULT 1) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
$pdo->exec("CREATE TABLE zp_workers (id INT AUTO_INCREMENT PRIMARY KEY, worker_code VARCHAR(20) NOT NULL UNIQUE, worker_name VARCHAR(120) NOT NULL, department VARCHAR(60) NULL, is_active TINYINT(1) NOT NULL DEFAULT 1) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
$pdo->exec("CREATE TABLE IF NOT EXISTS zu_perm (id INT AUTO_INCREMENT PRIMARY KEY, user_id INT NOT NULL, module VARCHAR(24) NOT NULL, actions VARCHAR(12) NOT NULL DEFAULT '',
            updated_by INT NULL, updated_at DATETIME DEFAULT CURRENT_TIMESTAMP, UNIQUE KEY uniq_user_module (user_id, module), INDEX(user_id)) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
(new Migrations($B . 'sql/att'))->up();
$pdo->prepare("INSERT INTO users (name,email,password_hash,role) VALUES (?,?,?,?)")->execute(['Gate Guard', 'gate@zas.test', password_hash('CorrectHorse9', PASSWORD_DEFAULT), 'staff']);
$pdo->prepare("INSERT INTO zu_perm (user_id, module, actions) VALUES (?,?,?)")->execute([(int) $pdo->lastInsertId(), 'attgate', 'vc']);
$pdo->exec("UPDATE att_policies SET value = '" . gmdate('Y-m-d H:i:s') . "' WHERE policy_key = 'auto_out_last_run'");   // keep auto-OUT out of the timing

$port = 8741; $docroot = rtrim($B, '/');
$srv = proc_open(sprintf('exec env PHP_CLI_SERVER_WORKERS=4 php -S 127.0.0.1:%d -t %s', $port, escapeshellarg($docroot)), [1 => ['file', '/dev/null', 'a'], 2 => ['file', '/dev/null', 'a']], $pipes);
register_shutdown_function(static function () use ($srv) { if (is_resource($srv)) { proc_terminate($srv, 9); proc_close($srv); } });
for ($i = 0; $i < 60; $i++) { $c = @fsockopen('127.0.0.1', $port, $e1, $e2, 0.2); if ($c) { fclose($c); break; } usleep(200000); }

$jar = tempnam(sys_get_temp_dir(), 'sljar'); $csrf = '';
function handle(string $method, string $path, array $data = []) {
    global $port, $jar, $csrf;
    $ch = curl_init("http://127.0.0.1:$port/api/v1/index.php?route=" . rawurlencode($path));
    $h = ['Accept: application/json', 'X-CSRF-Token: ' . $csrf];
    $o = [CURLOPT_RETURNTRANSFER => true, CURLOPT_COOKIEJAR => $jar, CURLOPT_COOKIEFILE => $jar, CURLOPT_TIMEOUT => 30, CURLOPT_CUSTOMREQUEST => $method];
    if ($method !== 'GET') { $o[CURLOPT_POSTFIELDS] = json_encode($data); $h[] = 'Content-Type: application/json'; }
    $o[CURLOPT_HTTPHEADER] = $h; curl_setopt_array($ch, $o);
    return $ch;
}
function call(string $method, string $path, array $data = []): array {
    global $csrf;
    $ch = handle($method, $path, $data);
    $raw = (string) curl_exec($ch); $st = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE); curl_close($ch);
    $b = json_decode($raw, true); if (is_array($b) && !empty($b['csrf_token'])) { $csrf = $b['csrf_token']; }
    return ['status' => $st, 'body' => is_array($b) ? $b : []];
}

echo "1. A phone call held up does not hold up the next one\n";
ok(call('POST', '/auth/login', ['email' => 'gate@zas.test', 'password' => 'CorrectHorse9'])['status'] === 200 && call('GET', '/auth/me')['status'] === 200, '(the gate phone is signed in)');

/* hold up the employee list: another connection keeps its table locked for 3 seconds */
$lock = new PDO($dsn, $user, $pass, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
$lock->exec('LOCK TABLES att_employees WRITE');
$mh = curl_multi_init();
$slow = handle('GET', '/employees/sync-snapshot');
curl_multi_add_handle($mh, $slow);
$t0 = microtime(true);
do { curl_multi_exec($mh, $run); curl_multi_select($mh, 0.05); } while (microtime(true) - $t0 < 0.7);   // the slow call is now inside the server, waiting
$t1 = microtime(true);
$quick = call('GET', '/sync/status');
$quickSecs = microtime(true) - $t1;
usleep(max(0, (int) ((3.0 - (microtime(true) - $t0)) * 1e6)));
$lock->exec('UNLOCK TABLES');
do { curl_multi_exec($mh, $run); curl_multi_select($mh, 0.1); } while ($run > 0 && microtime(true) - $t0 < 20);
$slowSt = (int) curl_getinfo($slow, CURLINFO_HTTP_CODE); $slowSecs = microtime(true) - $t0;
curl_multi_remove_handle($mh, $slow); curl_close($slow); curl_multi_close($mh);
ok($slowSt === 200 && $slowSecs >= 2.5, sprintf('the held-up call waited for the lock and then answered (%d, %.1f s)', $slowSt, $slowSecs));
ok($quick['status'] === 200 && $quickSecs < 1.5, sprintf('THE SECOND CALL FROM THE SAME PHONE ANSWERED AT ONCE (%.2f s) — before the fix it queued until the first was done', $quickSecs));

echo "2. The session still works after it is let go\n";
ok(call('GET', '/auth/me')['status'] === 200, 'still signed in after both calls');
$pdo->exec("UPDATE att_policies SET value = '" . gmdate('Y-m-d H:i:s') . "' WHERE policy_key = 'auto_out_last_run'");
$r = call('POST', '/sync/events', ['events' => []]);
ok(in_array($r['status'], [200, 413], true), 'a POST with the CSRF token is still accepted (' . $r['status'] . ')');
ok(call('POST', '/auth/logout', ['device_uuid' => ''])['status'] === 200 && call('GET', '/auth/me')['status'] === 401, 'signing out still ends the session');

@unlink($jar);
echo "\nSESSION LOCK: $P passed, $F failed\n";
exit($F ? 1 : 0);
