<?php
declare(strict_types=1);

/* ATTENDANCE M8 — HARDENING, BACKUP AND RESTORE, READINESS, SPEED
 *
 *   1. Every API route checks who is asking; every write needs the CSRF token.
 *      Read off the router's own source, so a route added later without them
 *      fails this test instead of reaching production.
 *   2. No page or phone script writes HTML from data; no secret in any file a
 *      browser downloads; the command-line tools refuse to run from the web.
 *   3. The attendance backup restores — proved by loading it, comparing every
 *      row, and putting the live data back from it after damage.
 *   4. The readiness check finds what is missing, and says how to fix it.
 *   5. Blueprint §7: an ordinary gate action within one second, under load;
 *      and AT02 across two phones at the same instant.
 */

$B = __DIR__ . '/app_src/public_html/';
$P = 0; $F = 0;
function ok($c, $m) { global $P, $F; if ($c) { $P++; } else { $F++; echo "  FAIL: $m\n"; } }

$dsn = getenv('ATT_TEST_DSN') ?: 'mysql:host=127.0.0.1;port=3306;dbname=zas_att_test;charset=utf8mb4';
$dbu = getenv('ATT_TEST_USER') ?: 'zas';
$dbp = getenv('ATT_TEST_PASS'); if ($dbp === false) { $dbp = 'zaspw'; }
if (!is_file($B . 'config/config.php')) { echo "SKIP: no test config — run tests/run_att.sh\n"; exit(0); }
$config = require $B . 'config/config.php';
require_once $B . 'includes/storage.php';
foreach (['Db','Time','Migrations','Policy','Audit','Roles','Employees','Shifts','Receipts','AttendanceService','HodService','Reconciler',
          'SyncService','AdminService','Storage','AttBackup','SystemCheck'] as $c) {
    require_once $B . "includes/att/$c.php";
}
use Att\AttBackup;
use Att\Db;
use Att\Employees;
use Att\Migrations;
use Att\Policy;
use Att\Roles;
use Att\Shifts;
use Att\SyncService as S;
use Att\SystemCheck;

/* ===================================================================== */
echo "1. Every route is guarded — read from the router itself\n";
$api = (string) file_get_contents($B . 'api/v1/index.php');
preg_match_all('/^        case \$method === \'(GET|POST)\' && (.+?):\n(.*?)(?=^        case |^        default:)/ms', $api, $mm, PREG_SET_ORDER);
$routes = [];
foreach ($mm as $x) {
    preg_match("#'(/[^']*)'|\\#\\^(/[^\\#]*)\\$\\##", $x[2], $nm);
    $routes[] = ['method' => $x[1], 'name' => $nm[1] ?? ($nm[2] ?? $x[2]), 'body' => $x[3]];
}
ok(count($routes) >= 60, 'the router has ' . count($routes) . ' routes, all read');
$guards = '/Http::require(User|Permission)\(|require_erp_admin\(|require_gate_or_hod\(|ot_view_scope\(/';
$open = array_values(array_filter($routes, fn($r) => !preg_match($guards, $r['body']) && !in_array($r['name'], ['/auth/login', '/auth/logout'], true)));
ok($open === [], 'EVERY ROUTE BUT LOGIN AND LOGOUT CHECKS WHO IS ASKING' . ($open ? ' — unguarded: ' . implode(', ', array_column($open, 'name')) : ''));
$noCsrf = array_values(array_filter($routes, fn($r) => $r['method'] === 'POST' && !str_contains($r['body'], 'Http::requireCsrf()') && $r['name'] !== '/auth/login'));
ok($noCsrf === [], 'EVERY WRITE BUT LOGIN NEEDS THE CSRF TOKEN' . ($noCsrf ? ' — missing: ' . implode(', ', array_column($noCsrf, 'name')) : ''));
$order = array_values(array_filter($routes, fn($r) => $r['method'] === 'POST' && str_contains($r['body'], 'Http::requireCsrf()')
    && strpos($r['body'], 'Http::requireCsrf()') > (int) (preg_match($guards, $r['body'], $g, PREG_OFFSET_CAPTURE) ? $g[0][1] : PHP_INT_MAX)));
ok($order === [], '  and checks the token BEFORE looking at permissions' . ($order ? ' — after: ' . implode(', ', array_column($order, 'name')) : ''));
ok(str_contains($api, "'hod_user_id'     => (int) \$u['id'],") && !preg_match('/Http::input\(\'(operator_id|hod_user_id|actor_id)\'/', $api),
   'the acting person always comes from the session — the router never reads an operator, HOD or actor id from the request');

echo "2. Nothing writes HTML from data; no secret reaches a browser; tools refuse the web\n";
$pages = array_merge(glob($B . 'att_*.php') ?: [], glob($B . 'att/*.js') ?: []);
$html = [];
foreach ($pages as $f) {
    $src = preg_replace('!/\*.*?\*/!s', '', (string) file_get_contents($f));
    if (preg_match('/\.innerHTML\s*=|insertAdjacentHTML|document\.write|outerHTML\s*=|eval\(/', (string) $src)) { $html[] = basename($f); }
}
ok(count($pages) >= 10 && $html === [], 'no attendance page or phone script builds HTML from text (' . count($pages) . ' files)' . ($html ? ' — found in ' . implode(', ', $html) : ''));
$browser = '';
foreach (array_merge(glob($B . 'att/*') ?: [], glob($B . 'att_*.php') ?: []) as $f) {
    if (!in_array(basename($f), ['att_migrate.php', 'att_cron.php', 'att_backup.php', 'att_check.php'], true)) { $browser .= (string) file_get_contents($f); }
}
ok(!preg_match('/r2_secret\s*[\'"]?\s*=>|r2_access_key\s*[\'"]?\s*=>|att_backup_key\s*[\'"]?\s*=>|password_hash\(|db_pass/', $browser),
   'no storage key, backup key or database password is written into any page or phone file');
$direct = [];
foreach (glob($B . 'includes/att/*.php') ?: [] as $f) {
    if (basename($f) !== 'Http.php' && preg_match('/\$_(GET|POST|REQUEST|COOKIE)\b/', (string) file_get_contents($f))) { $direct[] = basename($f); }
}
ok($direct === [], 'the services never read the request directly — only through Http' . ($direct ? ': ' . implode(', ', $direct) : ''));
$cliOnly = ['att_migrate.php', 'att_cron.php', 'att_backup.php', 'att_check.php'];
ok(array_filter($cliOnly, fn($f) => !str_contains((string) file_get_contents($B . $f), "if (PHP_SAPI !== 'cli') {\n    http_response_code(404);\n    exit;")) === [],
   'the four command-line tools all stop at once if opened from a browser');
ok(is_file($B . 'sql/att/.htaccess') && trim((string) file_get_contents($B . 'sql/att/.htaccess')) === 'Deny from all', 'the database step files carry a deny rule for Apache');
$idx = (string) file_get_contents($B . 'att/index.html');
ok(str_contains($idx, "Content-Security-Policy") && str_contains($idx, "script-src 'self'") && str_contains($idx, "connect-src 'self'"),
   'the phone app forbids scripts and connections from anywhere but this server');

/* ===================================================================== */
try {
    $pdo = new PDO($dsn, $dbu, $dbp, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC, PDO::ATTR_EMULATE_PREPARES => false]);
} catch (Throwable $e) { echo "SKIP: no MySQL available ({$e->getMessage()})\n"; exit(0); }
Db::bind($pdo);
Db::useUtc();
$pdo->exec('SET FOREIGN_KEY_CHECKS=0');
foreach ($pdo->query("SELECT table_name AS tn FROM information_schema.tables WHERE table_schema = DATABASE()")->fetchAll() as $t) {
    $pdo->exec('DROP TABLE IF EXISTS `' . $t['tn'] . '`');
}
$pdo->exec('SET FOREIGN_KEY_CHECKS=1');
$pdo->exec("CREATE TABLE users (id INT AUTO_INCREMENT PRIMARY KEY, name VARCHAR(150) NOT NULL,
            email VARCHAR(190) NOT NULL UNIQUE, password_hash VARCHAR(255) NOT NULL,
            role VARCHAR(40) NOT NULL DEFAULT 'colleague', department VARCHAR(60) NULL,
            can_see_rates TINYINT(1) NOT NULL DEFAULT 0, is_active TINYINT(1) NOT NULL DEFAULT 1)");
$pdo->exec("CREATE TABLE zu_perm (id INT AUTO_INCREMENT PRIMARY KEY, user_id INT NOT NULL, module VARCHAR(24) NOT NULL,
            actions VARCHAR(12) NOT NULL DEFAULT '', updated_by INT NULL, updated_at DATETIME DEFAULT CURRENT_TIMESTAMP,
            UNIQUE KEY uniq_user_module (user_id, module), INDEX(user_id))");
(new Migrations($B . 'sql/att'))->up();
$n = fn(string $sql, array $p = []) => (int) Db::value($sql, $p);
$hash = password_hash('CorrectHorse9', PASSWORD_DEFAULT);
$mk = function (string $name, string $email, string $role = 'colleague') use ($pdo, $hash): int {
    $pdo->prepare("INSERT INTO users (name,email,password_hash,role) VALUES (?,?,?,?)")->execute([$name, $email, $hash, $role]);
    return (int) $pdo->lastInsertId();
};
$admin = $mk('Admin Person', 'admin@zas.test', 'admin');
$gk = $mk('Gul Gatekeeper', 'gate@zas.test');
$gk2 = $mk('Gate Two', 'gate2@zas.test');
foreach ([$gk, $gk2] as $u) {
    $pdo->prepare("INSERT INTO zu_perm (user_id, module, actions) VALUES (?, 'attgate', 'vc')")->execute([$u]);
    Roles::grant($u, Roles::GATEKEEPER);
}

echo "3. The readiness check\n";
$byId = fn(array $r) => array_column($r['checks'], null, 'id');
$c = $byId(SystemCheck::run(false));
ok(isset($c['php'], $c['migrations'], $c['utc'], $c['default_shift'], $c['config'], $c['backup']), 'one list covers server, database, files, security, setup and day-to-day');
ok($c['migrations']['state'] === 'ok' && $c['migration_files']['state'] === 'ok' && $c['utc']['state'] === 'ok' && $c['innodb']['state'] === 'ok', 'database steps all applied and unchanged; UTC; InnoDB');
ok($c['config']['state'] === 'fail', 'IT RECOGNISES THE TEST CONFIG and fails it — the one that must never be on the live server');
ok($c['default_shift']['state'] === 'fail' && str_contains($c['default_shift']['detail'], 'does not reach the HOD'), 'no default shift: FAIL, saying the scans will not reach the HOD');
ok($c['employees']['state'] === 'fail' && $c['gatekeeper']['state'] === 'ok', 'no employees yet: FAIL; a working gatekeeper: OK');
ok(!str_contains(json_encode(SystemCheck::run(false)), 'zaspw') && !str_contains(json_encode(SystemCheck::run(false)), 'test-secret'), 'no password or key appears anywhere in the check');
$dS = Employees::createDepartment('Stitching');
$day = Shifts::create(['name' => 'Day', 'start_time' => '00:00', 'end_time' => '23:59', 'grace_in_minutes' => 30, 'grace_out_minutes' => 30]);
Policy::set('default_shift_id', (string) $day, null, 'string'); Policy::flush();
for ($i = 1; $i <= 320; $i++) { Employees::create(['code' => sprintf('%04d', $i), 'full_name' => "Worker $i", 'department_id' => $dS]); }
$c = $byId(SystemCheck::run(false));
ok($c['default_shift']['state'] === 'ok' && $c['employees']['state'] === 'ok', 'once set up, those lines turn green');
$migFile = $B . 'sql/att/0006_rate_limits.sql';
$orig = (string) file_get_contents($migFile);
file_put_contents($migFile, $orig . "\n-- edited\n");
$edited = $byId(SystemCheck::run(false))['migration_files'];
file_put_contents($migFile, $orig);
ok($edited['state'] === 'fail' && str_contains($edited['detail'], '0006'), 'a database step file edited after it ran is caught, by number');
$cli = (string) shell_exec('cd ' . escapeshellarg(rtrim($B, '/')) . ' && php att_check.php 2>&1; echo "EXIT=$?"');
ok(str_contains($cli, 'FAIL  config.php is the real one') && str_contains($cli, 'NOT READY') && str_contains($cli, 'EXIT=1'), 'php att_check.php prints the same list and exits 1 when something fails');

/* ===================================================================== */
echo "4. Backup, restore test, restore\n";
const G1 = 'aaaaaaaa-aaaa-4aaa-8aaa-aaaaaaaaaaaa';
const G2 = 'bbbbbbbb-bbbb-4bbb-8bbb-bbbbbbbbbbbb';
S::registerDevice(G1, 'Main gate', 'gate', $gk);
S::registerDevice(G2, 'Back gate', 'gate', $gk2);
$un = 0; $seq = 0;
$uuid = function () use (&$un): string { return sprintf('%08x-0000-4000-8000-%012x', random_int(0, 0xffffffff), ++$un); };
for ($i = 1; $i <= 40; $i++) {
    S::process(['event_uuid' => $uuid(), 'device_id' => G1, 'device_sequence' => ++$seq, 'employee_code' => sprintf('%04d', $i), 'event_type' => 'GATE_IN',
        'occurred_at' => gmdate('Y-m-d\TH:i:s\Z', time() - 7200), 'timezone' => 'UTC', 'payload' => []], $gk, true);
}
$bdir = sys_get_temp_dir() . '/zas_att_bk_' . getmypid();
register_shutdown_function(static function () use ($bdir) { exec('rm -rf ' . escapeshellarg($bdir)); });
$liveBefore = [$n('SELECT COUNT(*) FROM att_events'), $n('SELECT COUNT(*) FROM att_entry_cycles'), $n('SELECT COUNT(*) FROM att_employees')];
$b = AttBackup::create($bdir, null);
ok($b['ok'] && is_file($b['file']) && $b['encrypted'] === false && $b['tables'] >= 20, 'a backup of ' . ($b['tables'] ?? 0) . ' attendance tables, ' . ($b['rows'] ?? 0) . ' rows');
ok(str_starts_with(basename($b['file']), 'att-') && (fileperms($b['file']) & 0077) === 0, '  readable by the owner only');
ok(is_file($bdir . '/.htaccess'), '  in a folder that also carries a deny rule');
$v = AttBackup::verify($b['file'], null);
ok($v['ok'] && count($v['tables']) === $b['tables'] && $v['tables']['att_events']['rows'] === 40 && $v['tables']['att_employees']['rows'] === 320,
   'THE RESTORE TEST PASSES: every table loaded into scratch copies and matches, row for row');
ok($n("SELECT COUNT(*) FROM information_schema.tables WHERE table_schema = DATABASE() AND TABLE_NAME LIKE 'attbk\\_%'") === 0, '  and the scratch copies are gone afterwards');
ok([$n('SELECT COUNT(*) FROM att_events'), $n('SELECT COUNT(*) FROM att_entry_cycles'), $n('SELECT COUNT(*) FROM att_employees')] === $liveBefore, '  the live tables were not touched');
$cutRaw = gzdecode((string) file_get_contents($b['file']));
$cut = $bdir . '/att-cut.sql.gz';
file_put_contents($cut, gzencode(preg_replace('/\),\(/', '),(', preg_replace("/^INSERT INTO `att_events`.*$\n/m", '', (string) $cutRaw, 1), 1)));
$vc = AttBackup::verify($cut, null);
ok(!$vc['ok'] && $vc['tables']['att_events']['match'] === false, 'A BACKUP WITH ROWS MISSING FAILS THE TEST, naming the table');
file_put_contents($bdir . '/att-trunc.sql.gz', substr((string) file_get_contents($b['file']), 0, 300));
ok((AttBackup::verify($bdir . '/att-trunc.sql.gz', null)['ok'] ?? true) === false, 'a file cut short fails too');

$eb = AttBackup::create($bdir . '/enc', 'a long and private backup phrase for the test');
$raw = (string) file_get_contents($eb['file']);
ok($eb['encrypted'] && str_ends_with($eb['file'], '.enc') && @gzdecode($raw) === false && !str_contains($raw, 'Worker 1'), 'with a key the backup is encrypted: not readable as text');
ok(AttBackup::verify($eb['file'], 'a long and private backup phrase for the test')['ok'], '  and restores with the key');
ok(str_contains((string) (AttBackup::verify($eb['file'], 'the wrong phrase')['error'] ?? ''), 'cannot be decrypted') && str_contains((string) (AttBackup::verify($eb['file'], null)['error'] ?? ''), 'no att_backup_key'),
   '  but not with the wrong key, or none');

/* Damage the live data, then put it back. */
$pdo->exec('SET FOREIGN_KEY_CHECKS=0');
$pdo->exec('DELETE FROM att_entry_cycles');
$pdo->exec("UPDATE att_employees SET full_name = 'DAMAGED' WHERE code = '0001'");
$pdo->exec('SET FOREIGN_KEY_CHECKS=1');
$rs = AttBackup::restore($b['file'], null, $bdir);
ok($rs['ok'] && is_file($rs['safety_backup']), 'restore: done, after first backing up the damaged state');
ok([$n('SELECT COUNT(*) FROM att_events'), $n('SELECT COUNT(*) FROM att_entry_cycles'), $n('SELECT COUNT(*) FROM att_employees')] === $liveBefore
   && Db::value("SELECT full_name FROM att_employees WHERE code = '0001'") === 'Worker 1', 'THE LIVE DATA IS BACK EXACTLY AS IT WAS BACKED UP');
ok($n('SELECT COUNT(*) FROM att_entry_cycles WHERE open_employee_id IS NOT NULL') === 40, '  and the database rebuilt its own "one open entry per person" key on the way');
ok((new Migrations($B . 'sql/att'))->status() === array_map(fn($r) => $r, (new Migrations($B . 'sql/att'))->status())
   && count(array_filter((new Migrations($B . 'sql/att'))->status(), fn($r) => $r['state'] !== 'applied')) === 0, '  the database steps still read as all applied');
$cliB = (string) shell_exec('cd ' . escapeshellarg(rtrim($B, '/')) . ' && php att_backup.php verify ' . escapeshellarg($b['file']) . ' 2>&1; echo "EXIT=$?"');
ok(str_contains($cliB, 'RESTORE TEST PASSED') && str_contains($cliB, 'EXIT=0'), 'php att_backup.php verify FILE says RESTORE TEST PASSED');
$cliR = (string) shell_exec('cd ' . escapeshellarg(rtrim($B, '/')) . ' && php att_backup.php restore ' . escapeshellarg($b['file']) . ' 2>&1; echo "EXIT=$?"');
ok(str_contains($cliR, '--yes-replace-live-attendance-data') && str_contains($cliR, 'EXIT=2'), 'restore from the command line refuses without the long confirmation flag');
$cliC = (string) shell_exec('cd ' . escapeshellarg(rtrim($B, '/')) . ' && php att_backup.php create --dir=' . escapeshellarg($bdir . '/cli') . ' 2>&1');
ok(str_contains($cliC, 'Backed up') && str_contains($cliC, 'NOT ENCRYPTED'), 'php att_backup.php create works, and says plainly when it is not encrypted');
for ($i = 0; $i < 4; $i++) { sleep(1); AttBackup::create($bdir . '/keep', null, 3); }
ok(count(glob($bdir . '/keep/att-*.sql.gz') ?: []) === 3, 'only the newest backups are kept (3 here, 14 by default)');
$note = AttBackup::lastNote();
ok($note !== null && str_starts_with((string) $note['file'], 'att-') && !str_contains(json_encode($note), 'Worker'), 'each backup leaves a short note in the database: name, time, size — never the contents');
/* The live web server runs as another user and cannot look inside the SSH
   user's private backup folder. The check then reads the note. */
$hidden = AttBackup::defaultDir(rtrim($B, '/'));
$moved = is_dir($hidden) ? $hidden . '.moved' : null;
if ($moved) { rename($hidden, $moved); }
AttBackup::verify($b['file'], null);
$bk = $byId(SystemCheck::run(true))['backup'];
if ($moved) { rename($moved, $hidden); }
ok($bk['state'] === 'ok' && str_contains($bk['detail'], 'as recorded by the backup') && str_contains($bk['detail'], 'restore test PASSED'),
   'A PAGE THAT CANNOT SEE THE BACKUP FOLDER STILL SHOWS THE LAST BACKUP AND RESTORE TEST: ' . $bk['detail']);

/* ===================================================================== */
echo "5. Over HTTP: speed, and two gates at the same instant\n";
$port = 8735;
$env = getenv() + ['PHP_CLI_SERVER_WORKERS' => '8'];
$srv = proc_open(sprintf('exec php -S 127.0.0.1:%d -t %s', $port, escapeshellarg(rtrim($B, '/'))),
    [1 => ['file', '/tmp/att_m8_srv.log', 'a'], 2 => ['file', '/tmp/att_m8_srv.log', 'a']], $pipes, null, $env);
register_shutdown_function(static function () use ($srv) { if (is_resource($srv)) { proc_terminate($srv, 9); proc_close($srv); } });
for ($i = 0; $i < 60; $i++) { $cc = @fsockopen('127.0.0.1', $port, $e1, $e2, 0.2); if ($cc) { fclose($cc); break; } usleep(200000); }
function login(string $email): array
{
    global $port;
    $jar = tempnam(sys_get_temp_dir(), 'attm8');
    $ch = curl_init("http://127.0.0.1:$port/api/v1/index.php?route=%2Fauth%2Flogin");
    curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_COOKIEJAR => $jar, CURLOPT_COOKIEFILE => $jar, CURLOPT_POST => true,
        CURLOPT_POSTFIELDS => json_encode(['email' => $email, 'password' => 'CorrectHorse9']), CURLOPT_HTTPHEADER => ['Content-Type: application/json']]);
    $b = json_decode((string) curl_exec($ch), true);
    curl_close($ch);
    return [$jar, (string) ($b['csrf_token'] ?? '')];
}
function gateHandle(array $sess, array $body)
{
    global $port;
    $ch = curl_init("http://127.0.0.1:$port/api/v1/index.php?route=%2Fattendance%2Fin");
    curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_COOKIEFILE => $sess[0], CURLOPT_POST => true, CURLOPT_POSTFIELDS => json_encode($body),
        CURLOPT_HTTPHEADER => ['Content-Type: application/json', 'X-CSRF-Token: ' . $sess[1]], CURLOPT_TIMEOUT => 30]);
    return $ch;
}
$s1 = login('gate@zas.test');
$s2 = login('gate2@zas.test');
ok($s1[1] !== '' && $s2[1] !== '', 'two gatekeepers signed in');
/* 240 people scanned IN through two gates, 8 at a time. */
$times = []; $statuses = [];
$todo = range(41, 280);
$k = 0;
while ($todo !== []) {
    $mh = curl_multi_init();
    $hs = [];
    foreach (array_splice($todo, 0, 8) as $i) {
        $two = $i % 2 === 0;
        $h = gateHandle($two ? $s2 : $s1, ['event_uuid' => $uuid(), 'device_id' => $two ? G2 : G1, 'device_sequence' => 1000 + (++$k),
            'employee_code' => sprintf('%04d', $i), 'occurred_at' => gmdate('Y-m-d\TH:i:s\Z'), 'timezone' => 'UTC']);
        curl_multi_add_handle($mh, $h);
        $hs[] = $h;
    }
    do { $st = curl_multi_exec($mh, $run); if ($run) { curl_multi_select($mh, 1.0); } } while ($run && $st === CURLM_OK);
    foreach ($hs as $h) {
        $times[] = curl_getinfo($h, CURLINFO_TOTAL_TIME) * 1000;
        $statuses[] = json_decode((string) curl_multi_getcontent($h), true)['receipt_status'] ?? 'ERR';
        curl_multi_remove_handle($mh, $h);
        curl_close($h);
    }
    curl_multi_close($mh);
}
sort($times);
$p95 = $times[(int) floor(count($times) * 0.95) - 1];
$p50 = $times[(int) floor(count($times) / 2)];
ok(count(array_filter($statuses, fn($s) => $s === 'ACCEPTED')) === 240, 'all 240 scans accepted (' . json_encode(array_count_values($statuses)) . ')');
ok($p95 < 1000, sprintf('BLUEPRINT §7, ONE SECOND: 95%% of gate actions answered within %.0f ms, half within %.0f ms, 8 at a time', $p95, $p50));
ok($n('SELECT COUNT(*) FROM att_entry_cycles WHERE closed_at IS NULL') === 280, '280 people inside, each with exactly one open entry');

/* AT02 across phones: the same person scanned IN at both gates at once. */
$wins = 0;
foreach (range(281, 300) as $i) {
    $mh = curl_multi_init();
    $a = gateHandle($s1, ['event_uuid' => $uuid(), 'device_id' => G1, 'device_sequence' => 5000 + $i, 'employee_code' => sprintf('%04d', $i), 'occurred_at' => gmdate('Y-m-d\TH:i:s\Z'), 'timezone' => 'UTC']);
    $b2 = gateHandle($s2, ['event_uuid' => $uuid(), 'device_id' => G2, 'device_sequence' => 5000 + $i, 'employee_code' => sprintf('%04d', $i), 'occurred_at' => gmdate('Y-m-d\TH:i:s\Z'), 'timezone' => 'UTC']);
    curl_multi_add_handle($mh, $a); curl_multi_add_handle($mh, $b2);
    do { $st = curl_multi_exec($mh, $run); if ($run) { curl_multi_select($mh, 1.0); } } while ($run && $st === CURLM_OK);
    $r = [json_decode((string) curl_multi_getcontent($a), true)['receipt_status'] ?? 'ERR', json_decode((string) curl_multi_getcontent($b2), true)['receipt_status'] ?? 'ERR'];
    sort($r);
    if ($r === ['ACCEPTED', 'REJECTED']) { $wins++; }
    curl_multi_close($mh);
}
ok($wins === 20, "AT02 ACROSS TWO GATES: 20 people scanned IN at both gates at the same instant — every time one accepted, one refused ($wins of 20)");
ok($n('SELECT COUNT(*) FROM att_events WHERE employee_id IN (SELECT id FROM att_employees WHERE code BETWEEN \'0281\' AND \'0300\')') === 20, '  twenty events stored, not forty');

echo "6. Security headers\n";
$ch = curl_init("http://127.0.0.1:$port/api/v1/index.php?route=%2Fauth%2Fme");
curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_HEADER => true]);
$hd = strtolower((string) curl_exec($ch)); curl_close($ch);
ok(str_contains($hd, 'x-frame-options: deny') && str_contains($hd, 'x-content-type-options: nosniff') && str_contains($hd, 'cache-control: no-store') && str_contains($hd, 'referrer-policy: same-origin'),
   'every API answer: not frameable, not sniffable, not cached, no referrer');
foreach ($cliOnly as $f) {
    $ch = curl_init("http://127.0.0.1:$port/$f"); curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true]);
    $body = (string) curl_exec($ch); $code = curl_getinfo($ch, CURLINFO_HTTP_CODE); curl_close($ch);
    ok($code === 404 && trim($body) === '', "$f opened from the web: 404, nothing done");
}
$ch = curl_init("http://127.0.0.1:$port/includes/att/AttBackup.php"); curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true]);
$body = (string) curl_exec($ch); curl_close($ch);
ok(trim($body) === '', 'opening a service file from the web runs nothing and shows nothing');
[$aj, $acs] = login('admin@zas.test');
$ch = curl_init("http://127.0.0.1:$port/api/v1/index.php?route=%2Fadmin%2Fsystem-check"); curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_COOKIEFILE => $aj]);
$sc = json_decode((string) curl_exec($ch), true); curl_close($ch);
ok(($sc['ok'] ?? false) && isset(array_column($sc['checks'], null, 'id')['https']) && count($sc['web_probes'] ?? []) === 3, 'the admin\'s browser check adds HTTPS and three web probes');
$web = array_column($sc['checks'] ?? [], null, 'id');
ok(($web['migrations']['state'] ?? '') === 'ok' && ($web['migration_files']['state'] ?? '') === 'ok',
   'THE SAME CHECK FROM THE WEB READS THE DATABASE STEPS: all applied and unchanged (' . ($web['migrations']['detail'] ?? '?') . ')');
$webFails = array_values(array_diff(array_keys(array_filter($web, fn($c) => $c['state'] === 'fail')), ['https']));   // HTTPS is checked from the web only; the test server is plain http
$cliFails = array_keys(array_filter(array_column(SystemCheck::run(false)['checks'], null, 'id'), fn($c) => $c['state'] === 'fail'));
sort($webFails); sort($cliFails);
ok($webFails === $cliFails, '  and fails exactly what the command-line check fails (' . implode(',', $webFails) . ')');
$ch = curl_init("http://127.0.0.1:$port/api/v1/index.php?route=%2Fadmin%2Fsystem-check"); curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_COOKIEFILE => $s1[0]]);
curl_exec($ch); $code = curl_getinfo($ch, CURLINFO_HTTP_CODE); curl_close($ch);
ok($code === 403, 'a gatekeeper cannot run the system check');

echo "7. Setup › System, in a browser\n";
$work = __DIR__ . '/.zattm8';
@mkdir($work, 0777, true);
file_put_contents($work . '/run.js', <<<'JS'
const { chromium } = require('playwright');
const BASE = process.argv[2];
const out = [];
const check = (name, pass, extra) => out.push({ name, pass: !!pass, extra: extra === undefined ? '' : String(extra) });
(async () => {
  const browser = await chromium.launch();
  try {
    const ctx = await browser.newContext({ viewport: { width: 390, height: 800 } });
    const page = await ctx.newPage();
    const errors = [];
    page.on('pageerror', e => errors.push(e.message));
    await page.goto(BASE + '/att/manifest.webmanifest');
    await page.evaluate(async () => fetch('../api/v1/index.php?route=%2Fauth%2Flogin', { method: 'POST', credentials: 'same-origin',
      headers: { 'Content-Type': 'application/json' }, body: JSON.stringify({ email: 'admin@zas.test', password: 'CorrectHorse9' }) }));
    await page.goto(BASE + '/att_setup.php#sys');
    await page.waitForSelector('#sys-summary');
    check('the System tab opens with its summary', true);
    check('  it says Not ready, because this is the test config', /Not ready/.test(await page.textContent('#sys-summary')));
    check('  the config line is red and tells you what to do', (await page.getAttribute('li[data-check="config"]', 'data-state')) === 'fail'
      && /→/.test(await page.textContent('li[data-check="config"]')));
    await page.waitForSelector('#sys-probes li[data-probe]');
    const probes = await page.$$eval('#sys-probes li[data-probe]', ls => ls.map(l => [l.getAttribute('data-probe'), l.getAttribute('data-state')]));
    check('  the three web probes ran', probes.length === 3, JSON.stringify(probes));
    check('  a service file opened from the web shows nothing: green', probes.some(p => /SystemCheck\.php$/.test(p[0]) && p[1] === 'ok'), JSON.stringify(probes));
    const over = await page.evaluate(() => document.documentElement.scrollWidth - window.innerWidth);
    const wide = await page.evaluate(() => [...document.querySelectorAll('#atts-body *')].filter(e => e.getBoundingClientRect().right > window.innerWidth + 1).slice(-3).map(e => e.tagName + '.' + e.className + ':' + e.textContent.slice(0, 50)));
    check('  at phone width it does not push the page sideways', over <= 1, over + ' ' + JSON.stringify(wide));
    check('no script error', errors.length === 0, errors.join(' | '));
  } catch (e) { check('the browser run completed', false, e && e.message); }
  finally { await browser.close(); }
  console.log('RESULT' + JSON.stringify(out));
})();
JS);
$raw = (string) shell_exec('cd ' . escapeshellarg($work) . ' && timeout 120 node run.js ' . escapeshellarg("http://127.0.0.1:$port") . ' 2>&1');
$results = [];
if (preg_match('/RESULT(\[.*\])\s*$/s', $raw, $m)) { $results = json_decode($m[1], true) ?: []; }
ok(count($results) >= 7, 'the browser run reported its checks (' . count($results) . ')' . (count($results) ? '' : "\n$raw"));
foreach ($results as $r) { ok($r['pass'], $r['name'] . ($r['pass'] ? '' : ' — ' . $r['extra'])); }

echo "\n$P passed, $F failed\n";
exit($F === 0 ? 0 : 1);
