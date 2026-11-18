<?php
declare(strict_types=1);

/* REMEMBER THIS PHONE, AND A DEPARTMENT'S REGULAR SHIFT.
 *
 *  A. A gate or HOD phone signed in once gets a key and signs itself back in
 *     when the server's sign-in has ended — over real HTTP, through the real
 *     router and session. The key is stored only as a hash, and it is refused
 *     on a password change, a withdrawn phone, deactivation, loss of the job,
 *     expiry, sign-out, or another phone's id.
 *  B. A department's regular shift attaches to a scan on a day nobody gave a
 *     shift — including a 19:00–08:30 night — and never replaces a shift that
 *     was given. Run through tests/run_att.sh. */

$B = __DIR__ . '/app_src/public_html/';
$P = 0; $F = 0;
function ok($c, $m) { global $P, $F; if ($c) { $P++; } else { $F++; echo "  FAIL: $m\n"; } }
$dsn  = getenv('ATT_TEST_DSN') ?: 'mysql:host=127.0.0.1;port=3306;dbname=zas_att_test;charset=utf8mb4';
$user = getenv('ATT_TEST_USER') ?: 'zas';
$pass = getenv('ATT_TEST_PASS'); if ($pass === false) { $pass = 'zaspw'; }
if (!is_file($B . 'config/config.php')) { echo "SKIP: no test config — run tests/run_att.sh\n"; exit(0); }
foreach (['Db', 'Migrations', 'Policy', 'Audit', 'Roles', 'Employees', 'Time', 'Shifts', 'PhoneKeys', 'EntryAdmin'] as $c) { require_once $B . "includes/att/$c.php"; }
use Att\Db; use Att\Employees; use Att\Migrations; use Att\Roles; use Att\Shifts; use Att\Time;
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

$hash = password_hash('CorrectHorse9', PASSWORD_DEFAULT);
$ins = $pdo->prepare("INSERT INTO users (name,email,password_hash,role) VALUES (?,?,?,?)");
$ins->execute(['Gate Guard', 'gate@zas.test', $hash, 'staff']); $gateId = (int) $pdo->lastInsertId();
$ins->execute(['Office Clerk', 'clerk@zas.test', $hash, 'staff']); $clerkId = (int) $pdo->lastInsertId();
$pdo->prepare("INSERT INTO zu_perm (user_id, module, actions) VALUES (?,?,?)")->execute([$gateId, 'attgate', 'vc']);

$port = 8723; $docroot = rtrim($B, '/');
$srv = proc_open(sprintf('exec php -S 127.0.0.1:%d -t %s', $port, escapeshellarg($docroot)), [1 => ['file', '/tmp/att_pk_srv.log', 'a'], 2 => ['file', '/tmp/att_pk_srv.log', 'a']], $pipes);
register_shutdown_function(static function () use ($srv) { if (is_resource($srv)) { proc_terminate($srv, 9); proc_close($srv); } });
for ($i = 0; $i < 60; $i++) { $c = @fsockopen('127.0.0.1', $port, $e1, $e2, 0.2); if ($c) { fclose($c); break; } usleep(200000); }

$jar = tempnam(sys_get_temp_dir(), 'pkjar'); $csrf = '';
function call(string $method, string $path, array $data = []): array {
    global $port, $jar, $csrf;
    $ch = curl_init("http://127.0.0.1:$port/api/v1/index.php?route=" . rawurlencode($path));
    $h = ['Accept: application/json', 'X-CSRF-Token: ' . $csrf];
    $o = [CURLOPT_RETURNTRANSFER => true, CURLOPT_COOKIEJAR => $jar, CURLOPT_COOKIEFILE => $jar, CURLOPT_TIMEOUT => 20, CURLOPT_CUSTOMREQUEST => $method];
    if ($method !== 'GET') { $o[CURLOPT_POSTFIELDS] = json_encode($data); $h[] = 'Content-Type: application/json'; }
    $o[CURLOPT_HTTPHEADER] = $h; curl_setopt_array($ch, $o);
    $raw = (string) curl_exec($ch); $st = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE); curl_close($ch);
    $b = json_decode($raw, true); if (is_array($b) && !empty($b['csrf_token'])) { $csrf = $b['csrf_token']; }
    return ['status' => $st, 'body' => is_array($b) ? $b : [], 'raw' => $raw];
}
function newPhone(): void { global $jar, $csrf; @unlink($jar); $jar = tempnam(sys_get_temp_dir(), 'pkjar'); $csrf = ''; }   // the app closed: the server's sign-in is gone
$dev = '11111111-2222-4333-8444-555555555555';

echo "A. Remember this phone\n";
call('POST', '/auth/login', ['email' => 'gate@zas.test', 'password' => 'CorrectHorse9']);
$me = call('GET', '/auth/me');
ok($me['status'] === 200 && ($me['body']['policies']['app_lock_minutes'] ?? 0) === 30, 'signed in; the phone is told to lock after 30 minutes away (Settings)');
$k = call('POST', '/auth/phone-key', ['device_uuid' => $dev]);
$token = (string) ($k['body']['token'] ?? '');
ok($k['status'] === 200 && strlen($token) === 64 && ($k['body']['days'] ?? 0) === 30, 'a signed-in gate phone gets a key (30 days) — ' . $k['status'] . ' ' . substr($k['raw'], 0, 300));
$row = $pdo->query("SELECT * FROM att_device_tokens ORDER BY id DESC LIMIT 1")->fetch();
ok($row && $row['token_hash'] === hash('sha256', $token) && !str_contains(json_encode($row), $token), '  only a SHA-256 of the key is stored — never the key itself');
newPhone();
ok(call('GET', '/auth/me')['status'] === 401, 'the app closed: the server has forgotten the sign-in (401)');
$r = call('POST', '/auth/phone-resume', ['device_uuid' => $dev, 'token' => $token]);
$me = call('GET', '/auth/me');
ok($r['status'] === 200 && $me['status'] === 200 && (int) $me['body']['user']['id'] === $gateId, 'the phone signs itself back in with its key — no email, no password');
$exp1 = $pdo->query("SELECT expires_at FROM att_device_tokens WHERE id = " . (int) $row['id'])->fetchColumn();
ok($exp1 > $row['expires_at'] || $exp1 === $row['expires_at'], '  using the key moves its expiry on (30 days from last use)');

newPhone();
ok(call('POST', '/auth/phone-resume', ['device_uuid' => '99999999-2222-4333-8444-555555555555', 'token' => $token])['status'] === 401, 'the key on another phone\'s id is refused');
ok(call('POST', '/auth/phone-resume', ['device_uuid' => $dev, 'token' => str_repeat('a', 64)])['status'] === 401, 'a made-up key is refused');
ok(call('POST', '/auth/phone-resume', ['device_uuid' => $dev, 'token' => $token])['status'] === 200, '  (and the real key still works afterwards)');

echo "   …and when it stops working\n";
$pdo->prepare("UPDATE users SET password_hash = ? WHERE id = ?")->execute([password_hash('NewPassword7', PASSWORD_DEFAULT), $gateId]);
newPhone();
ok(call('POST', '/auth/phone-resume', ['device_uuid' => $dev, 'token' => $token])['status'] === 401, 'the password changed: the key is refused');
ok($pdo->query("SELECT revoked_at FROM att_device_tokens WHERE id = " . (int) $row['id'])->fetchColumn() !== null, '  and cancelled for good');

call('POST', '/auth/login', ['email' => 'gate@zas.test', 'password' => 'NewPassword7']);
$t2 = (string) (call('POST', '/auth/phone-key', ['device_uuid' => $dev])['body']['token'] ?? '');
$pdo->prepare("INSERT INTO att_devices (device_uuid, label, revoked_at) VALUES (?, 'Gate phone', NOW(6))")->execute([$dev]);
newPhone();
ok(call('POST', '/auth/phone-resume', ['device_uuid' => $dev, 'token' => $t2])['status'] === 401, 'the phone was withdrawn in Setup: refused');
$pdo->exec("DELETE FROM att_devices");

call('POST', '/auth/login', ['email' => 'gate@zas.test', 'password' => 'NewPassword7']);
$t3 = (string) (call('POST', '/auth/phone-key', ['device_uuid' => $dev])['body']['token'] ?? '');
$pdo->prepare("UPDATE zu_perm SET actions = '' WHERE user_id = ? AND module = 'attgate'")->execute([$gateId]);
newPhone();
ok(call('POST', '/auth/phone-resume', ['device_uuid' => $dev, 'token' => $t3])['status'] === 401, 'the person lost the gate job: refused');
$pdo->prepare("UPDATE zu_perm SET actions = 'vc' WHERE user_id = ? AND module = 'attgate'")->execute([$gateId]);

call('POST', '/auth/login', ['email' => 'gate@zas.test', 'password' => 'NewPassword7']);
$t4 = (string) (call('POST', '/auth/phone-key', ['device_uuid' => $dev])['body']['token'] ?? '');
$pdo->prepare("UPDATE users SET is_active = 0 WHERE id = ?")->execute([$gateId]);
newPhone();
ok(call('POST', '/auth/phone-resume', ['device_uuid' => $dev, 'token' => $t4])['status'] === 401, 'the person was deactivated: refused');
$pdo->prepare("UPDATE users SET is_active = 1 WHERE id = ?")->execute([$gateId]);

call('POST', '/auth/login', ['email' => 'gate@zas.test', 'password' => 'NewPassword7']);
$t5 = (string) (call('POST', '/auth/phone-key', ['device_uuid' => $dev])['body']['token'] ?? '');
$pdo->exec("UPDATE att_device_tokens SET expires_at = DATE_SUB(UTC_TIMESTAMP(6), INTERVAL 1 MINUTE) WHERE token_hash = '" . hash('sha256', $t5) . "'");
newPhone();
ok(call('POST', '/auth/phone-resume', ['device_uuid' => $dev, 'token' => $t5])['status'] === 401, 'not used for longer than the days set: refused');

call('POST', '/auth/login', ['email' => 'gate@zas.test', 'password' => 'NewPassword7']);
$t6 = (string) (call('POST', '/auth/phone-key', ['device_uuid' => $dev])['body']['token'] ?? '');
call('POST', '/auth/logout', ['device_uuid' => $dev]);
newPhone();
ok(call('POST', '/auth/phone-resume', ['device_uuid' => $dev, 'token' => $t6])['status'] === 401, 'the phone signed out: its key is cancelled');

call('POST', '/auth/login', ['email' => 'clerk@zas.test', 'password' => 'CorrectHorse9']);
ok(call('POST', '/auth/phone-key', ['device_uuid' => $dev])['status'] === 403, 'a login with no gate or HOD job gets no key');
newPhone();
ok(call('POST', '/auth/phone-key', ['device_uuid' => $dev])['status'] === 401 || call('POST', '/auth/phone-key', ['device_uuid' => $dev])['status'] === 419, 'and nobody signed out can ask for one');

echo "B. A department's regular shift (night 19:00–08:30)\n";
$dNight = Employees::createDepartment('NIGHT SHIFT');
$dDay   = Employees::createDepartment('Stitching');
$guard = Employees::create(['code' => '234', 'full_name' => 'Rauf Guard', 'department_id' => $dNight]);
$worker = Employees::create(['code' => '501', 'full_name' => 'Day Worker', 'department_id' => $dDay]);
$night = Shifts::create(['name' => 'NIGHT SHIFT', 'start_time' => '19:00', 'end_time' => '08:30', 'timezone' => 'Asia/Karachi', 'break_minutes' => 60, 'grace_in_minutes' => 30, 'grace_out_minutes' => 30]);
$day   = Shifts::create(['name' => 'Day', 'start_time' => '08:00', 'end_time' => '17:00', 'timezone' => 'Asia/Karachi', 'break_minutes' => 60, 'grace_in_minutes' => 30, 'grace_out_minutes' => 30]);
$pdo->exec("INSERT INTO att_policies (policy_key, value, value_type, description) VALUES ('default_shift_id', '$day', 'string', '') ON DUPLICATE KEY UPDATE value = VALUES(value)");
\Att\Policy::flush();
$at = fn(string $local) => (new DateTimeImmutable($local, new DateTimeZone('Asia/Karachi')))->setTimezone(new DateTimeZone('UTC'));

[$i0, ] = Shifts::instanceForOrDefault($guard, $at('2026-12-01 20:05'));
ok($i0 === null, 'before: a guard scanning at 8:05 PM with no shift and a day-shift default — nothing to file it under (held for HR)');
call('POST', '/auth/login', ['email' => 'gate@zas.test', 'password' => 'NewPassword7']);
$pdo->prepare("INSERT INTO zu_perm (user_id, module, actions) VALUES (?,?,?) ON DUPLICATE KEY UPDATE actions = VALUES(actions)")->execute([$gateId, 'attadm', 'vcu']);
$r = call('POST', '/admin/departments/regular-shift', ['department_id' => $dNight, 'shift_id' => $night]);
ok($r['status'] === 200 && ($r['body']['ok'] ?? false), 'Setup: NIGHT SHIFT department gets the regular shift NIGHT SHIFT');
$list = call('GET', '/admin/departments')['body']['departments'] ?? [];
ok((int) (array_values(array_filter($list, fn($d) => (int) $d['id'] === $dNight))[0]['regular_shift_id'] ?? 0) === $night, '  and the departments list shows it');
[$i1, $made] = Shifts::instanceForOrDefault($guard, $at('2026-12-02 20:05'));
ok($i1 !== null && $made && (int) $i1['shift_id'] === $night && $i1['work_date'] === '2026-12-02' && $i1['source'] === 'assigned', 'the guard\'s 8:05 PM scan is filed under the night shift of that day — no renewing, no HR');
[$i2, ] = Shifts::instanceForOrDefault($guard, $at('2026-12-03 08:20'));
ok($i2 !== null && (int) $i2['id'] === (int) $i1['id'], '  his 8:20 AM scan the next morning belongs to the same night');
[$i3, ] = Shifts::instanceForOrDefault($guard, $at('2026-12-04 02:00'));
ok($i3 !== null && $i3['work_date'] === '2026-12-03', '  a scan at 2 AM with no earlier one is filed under the night that began the evening before');
Shifts::assign($guard, $day, '2026-12-05');
[$i4, ] = Shifts::instanceForOrDefault($guard, $at('2026-12-05 20:30'));
ok($i4 === null && (int) $pdo->query("SELECT shift_id FROM att_shift_instances WHERE employee_id = $guard AND work_date = '2026-12-05'")->fetchColumn() === $day, 'a day somebody gave him the day shift keeps it — the regular shift never replaces a given one');
[$i5, ] = Shifts::instanceForOrDefault($worker, $at('2026-12-02 08:10'));
ok($i5 !== null && (int) $i5['shift_id'] === $day && $i5['source'] === 'default_auto', 'other departments are untouched: the day worker still gets the default day shift');
$r = call('POST', '/admin/departments/regular-shift', ['department_id' => $dNight, 'shift_id' => 0]);
[$i6, ] = Shifts::instanceForOrDefault($guard, $at('2026-12-08 20:05'));
ok(($r['body']['ok'] ?? false) && $i6 === null, 'clearing the regular shift puts it back as before');


echo "C. Bulk add — the one-time opening\n";
use Att\EntryAdmin;
call('POST', '/admin/departments/regular-shift', ['department_id' => $dNight, 'shift_id' => $night]);
$opt = ['from' => '2026-11-02', 'to' => '2026-11-07', 'days' => '123456', 'department_id' => 0, 'codes' => '', 'mode' => 'shift', 'in_time' => '', 'out_time' => '', 'reason' => 'Opening attendance'];
$n = fn(string $sql) => (int) $pdo->query($sql)->fetchColumn();
$before = $n('SELECT COUNT(*) FROM att_entry_cycles');
$chk = EntryAdmin::bulk($opt, false, $gateId);
ok($chk['ok'] && $chk['to_add'] === 12 && $chk['people'] === 2 && $chk['days'] === 6, 'Check: 2 people × 6 days (Mon–Sat) = 12 entries');
ok($n('SELECT COUNT(*) FROM att_entry_cycles') === $before, '  and Check writes nothing');
$no = EntryAdmin::bulk(['reason' => ''] + $opt, true, $gateId);
ok(!$no['ok'] && $no['reason_code'] === 'REASON_REQUIRED', 'Add without a reason is refused');
$r = EntryAdmin::bulk($opt, true, $gateId);
ok($r['ok'] && $r['added'] === 12 && !$r['failed'], 'Add: 12 entries added');
$g = $pdo->query("SELECT CONVERT_TZ(opened_at,'+00:00','+05:00') i, CONVERT_TZ(closed_at,'+00:00','+05:00') o, present_minutes m FROM att_entry_cycles WHERE employee_id = $guard ORDER BY opened_at LIMIT 1")->fetch();
ok(substr($g['i'], 0, 16) === '2026-11-02 19:00' && substr($g['o'], 0, 16) === '2026-11-03 08:30' && (int) $g['m'] === 810, 'the night guard gets his own night shift: 7:00 PM to 8:30 AM next day');
$w = $pdo->query("SELECT CONVERT_TZ(opened_at,'+00:00','+05:00') i, CONVERT_TZ(closed_at,'+00:00','+05:00') o FROM att_entry_cycles WHERE employee_id = $worker ORDER BY opened_at LIMIT 1")->fetch();
ok(substr($w['i'], 11, 5) === '08:00' && substr($w['o'], 11, 5) === '17:00', 'the day worker gets the default day shift');
ok($n("SELECT COUNT(*) FROM att_corrections WHERE action = 'admin.entry_add' AND reason = 'Opening attendance'") === 12, 'every entry keeps the reason, under the administrator');
$again = EntryAdmin::bulk($opt, true, $gateId);
ok($again['ok'] && $again['added'] === 0 && $again['skipped_already'] === 12, 'running it again adds nothing — every day is already there');
$fx = EntryAdmin::bulk(['from' => '2026-11-09', 'to' => '2026-11-09', 'codes' => '501, 999', 'mode' => 'fixed', 'in_time' => '09:15', 'out_time' => '18:00'] + $opt, true, $gateId);
$fw = $pdo->query("SELECT CONVERT_TZ(opened_at,'+00:00','+05:00') i FROM att_entry_cycles WHERE employee_id = $worker ORDER BY opened_at DESC LIMIT 1")->fetchColumn();
ok($fx['added'] === 1 && $fx['unknown_codes'] === ['999'] && substr((string) $fw, 11, 5) === '09:15', 'same times for chosen codes: 501 gets 09:15–18:00, unknown code 999 is listed');
$fut = EntryAdmin::bulk(['from' => '2027-01-04', 'to' => '2027-01-05'] + $opt, false, $gateId);
ok($fut['to_add'] === 0 && $fut['skipped_future'] === 4, 'days in the future are skipped');
$big = EntryAdmin::bulk(['from' => '2026-01-01', 'to' => '2026-11-01'] + $opt, false, $gateId);
ok(!$big['ok'], 'more than 62 days at once is refused');
call('POST', '/auth/login', ['email' => 'gate@zas.test', 'password' => 'NewPassword7']);
$r = call('POST', '/admin/entries/bulk', $opt + ['apply' => false]);
ok($r['status'] === 403, 'a non-administrator cannot use bulk add (' . $r['status'] . ')');

echo "\nPHONE KEY, REGULAR SHIFT AND BULK ADD: $P passed, $F failed\n";
exit($F ? 1 : 0);
