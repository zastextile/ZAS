<?php
declare(strict_types=1);

/* PEOPLE FINANCE — OVER THE WEB, AND THE SCREEN
 *
 * zatt_fin_test.php proves the pay rules. This proves the door in front of
 * them: who the API lets in, that a browser cannot send its own totals, and
 * that the screen really does prepare → amend one line → approve → pay.
 *
 * It runs on TODAY's real date (the server's own clock decides what may be
 * paid), so the periods below are worked out from today.
 */

$B = __DIR__ . '/app_src/public_html/';
$P = 0; $F = 0;
function ok($c, $m) { global $P, $F; if ($c) { $P++; } else { $F++; echo "  FAIL: $m\n"; } }

$dsn = getenv('ATT_TEST_DSN') ?: 'mysql:host=127.0.0.1;port=3306;dbname=zas_att_test;charset=utf8mb4';
$dbu = getenv('ATT_TEST_USER') ?: 'zas';
$dbp = getenv('ATT_TEST_PASS'); if ($dbp === false) { $dbp = 'zaspw'; }
if (!is_file($B . 'config/config.php')) { echo "SKIP: no test config — run tests/run_att.sh\n"; exit(0); }
foreach (['Db','Time','Migrations','Policy','Audit','Roles','Employees','Shifts','Receipts','AttendanceService','HodService','Reconciler',
          'SyncService','OvertimeService','ReportService','HrService','AdminService','EntryAdmin','FinanceService'] as $c) {
    require_once $B . "includes/att/$c.php";
}
use Att\AdminService;
use Att\Db;
use Att\Employees;
use Att\FinanceService as Fin;
use Att\Migrations;
use Att\Policy;
use Att\Roles;
use Att\Shifts;
use Att\SyncService as S;

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
            can_see_rates TINYINT(1) NOT NULL DEFAULT 0, is_active TINYINT(1) NOT NULL DEFAULT 1, "
            // the ERP's legacy permission columns: the People tab saves through zu_save_perms(), which writes them
            . implode(', ', array_map(fn($c) => "$c TINYINT(1) NOT NULL DEFAULT 0", ['cost_view','cost_create','cost_edit','cost_delete','cost_import','cost_print','cost_proforma',
              'inv_view','inv_gate','inv_store','inv_consume','inv_master','inv_post','inv_adjust'])) . ")");
$pdo->exec("CREATE TABLE zu_perm (id INT AUTO_INCREMENT PRIMARY KEY, user_id INT NOT NULL, module VARCHAR(24) NOT NULL,
            actions VARCHAR(12) NOT NULL DEFAULT '', updated_by INT NULL, updated_at DATETIME DEFAULT CURRENT_TIMESTAMP,
            UNIQUE KEY uniq_user_module (user_id, module), INDEX(user_id))");
$pdo->exec("CREATE TABLE zp_workers (id INT AUTO_INCREMENT PRIMARY KEY, worker_code VARCHAR(40) NOT NULL, worker_name VARCHAR(120) NOT NULL,
            department VARCHAR(60) NULL, is_active TINYINT(1) NOT NULL DEFAULT 1, UNIQUE KEY uniq_worker_code (worker_code)) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci");
$pdo->exec("CREATE TABLE zp_entries (id INT AUTO_INCREMENT PRIMARY KEY, entry_date DATE NOT NULL, worker_id INT NOT NULL, product_id INT NOT NULL DEFAULT 1,
            op_id INT NOT NULL DEFAULT 1, qty DECIMAL(12,2) NOT NULL, rate_applied DECIMAL(12,2) NOT NULL, amount DECIMAL(14,2) NOT NULL,
            status VARCHAR(12) NOT NULL DEFAULT 'active') ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci");
$pdo->exec("CREATE TABLE zp_pay (id INT AUTO_INCREMENT PRIMARY KEY, pay_date DATE NOT NULL, worker_id INT NOT NULL, amount DECIMAL(14,2) NOT NULL,
            note VARCHAR(255) NULL, status VARCHAR(12) NOT NULL DEFAULT 'active', created_by INT NULL, created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
            cancelled_by INT NULL, cancelled_at DATETIME NULL, cancel_reason VARCHAR(255) NULL) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci");
(new Migrations($B . 'sql/att'))->up();
$n = fn(string $sql, array $p = []) => (int) Db::value($sql, $p);
$hash = password_hash('CorrectHorse9', PASSWORD_DEFAULT);
$mk = function (string $name, string $email, string $role = 'colleague') use ($pdo, $hash): int {
    $pdo->prepare("INSERT INTO users (name,email,password_hash,role) VALUES (?,?,?,?)")->execute([$name, $email, $hash, $role]);
    return (int) $pdo->lastInsertId();
};
$admin = $mk('Admin Person', 'admin@zas.test', 'admin');
$gk  = $mk('Gul Gatekeeper', 'gate@zas.test');
$hod = $mk('Hina Hod', 'hod@zas.test');
$hr  = $mk('Rana HR', 'hr@zas.test');
$acc = $mk('Asif Accounts', 'acc@zas.test');
$aud = $mk('Aisha Audit', 'audit@zas.test');
$tick = $mk('Tariq Ticked', 'tick@zas.test');
$dS = Employees::createDepartment('Stitching');
$dP = Employees::createDepartment('Packing');
$emp = [];
foreach ([['S001', 'Sara Salaried', $dS], ['S002', 'Sami Salaried', $dP], ['D001', 'Dawood Daily', $dS], ['D002', 'Dina Daily', $dS],
          ['D003', 'Danish Daily', $dP], ['C001', 'Kamran Contractor', $dS], ['N001', 'Nida New', $dS]] as [$c, $nm, $d]) {
    $emp[$c] = Employees::create(['code' => $c, 'full_name' => $nm, 'department_id' => $d]);
}
Db::run("UPDATE att_employees SET worker_code = 'W001' WHERE id = ?", [$emp['C001']]);
Roles::grant($gk, Roles::GATEKEEPER);
Roles::grant($hod, Roles::HOD, $dS);
Roles::grant($hr, Roles::HR);
Roles::grant($acc, Roles::ACCOUNTS);
$day = Shifts::create(['name' => 'Day', 'start_time' => '08:00', 'end_time' => '18:00', 'grace_in_minutes' => 10, 'grace_out_minutes' => 10]);
Policy::set('default_shift_id', (string) $day, null, 'string');
Policy::set('auto_out_enabled', '0', null, 'bool'); Policy::flush();
const GATE = 'aaaaaaaa-aaaa-4aaa-8aaa-aaaaaaaaaaaa';
S::registerDevice(GATE, 'Main gate', 'gate', $gk);
$seq = 0; $un = 0;
$uuid = function () use (&$un): string { return sprintf('%08x-0000-4000-8000-%012x', random_int(0, 0xffffffff), ++$un); };
$came = function (string $code, string $date, string $out = '12:00') use (&$seq, $uuid, $gk): void {
    foreach ([['GATE_IN', '03:00'], ['GATE_OUT', $out]] as [$type, $hm]) {
        S::process(['event_uuid' => $uuid(), 'device_id' => GATE, 'device_sequence' => ++$seq, 'employee_code' => $code, 'event_type' => $type,
            'occurred_at' => "{$date}T{$hm}:00Z", 'timezone' => 'UTC', 'payload' => []], $gk, true);
    }
};

// Dates, from today (factory time).
$tz = new DateTimeZone('Asia/Karachi');
$now = new DateTimeImmutable('now', $tz);
$today = $now->format('Y-m-d');
$thu = $now; while ((int) $thu->format('N') !== 4) { $thu = $thu->modify('-1 day'); }
$fri = $thu->modify('-6 days');
$wFrom = $fri->format('Y-m-d'); $wTo = $thu->format('Y-m-d');
$pm = $now->modify('first day of last month');
$mFrom = $pm->format('Y-m-01'); $mTo = $pm->format('Y-m-t'); $dim = (int) $pm->format('t');
$isPayDay = (int) $now->format('N') === 4;

// Pay setup, attendance, production — through the service (its rules are proved elsewhere).
Fin::saveProfile($emp['S001'], ['pay_type' => 'salary', 'basic_paisa' => 6000000, 'allowance_paisa' => 500000, 'pay_method' => 'bank'], '', $admin);
Fin::saveProfile($emp['S002'], ['pay_type' => 'salary', 'basic_paisa' => 4200000], '', $admin);
foreach (['D001', 'D002', 'D003'] as $c) { Fin::saveProfile($emp[$c], ['pay_type' => 'daily', 'daily_rate_paisa' => 120000], '', $admin); }
Fin::saveProfile($emp['C001'], ['pay_type' => 'contract'], '', $admin);
foreach ([0, 1, 2] as $i) { $came('D001', $fri->modify("+$i day")->format('Y-m-d')); }
$came('D002', $wFrom); $came('D003', $wFrom);
$pdo->exec("INSERT INTO zp_workers (worker_code, worker_name) VALUES ('W001', 'Kamran Contractor')");
/* names for the production lines, as the real tables hold them */
$pdo->exec("CREATE TABLE products (id INT AUTO_INCREMENT PRIMARY KEY, name VARCHAR(200) NOT NULL, is_active TINYINT(1) NOT NULL DEFAULT 1)");
$pdo->exec("INSERT INTO products (name) VALUES ('Thermal Blanket')");
$pdo->exec("CREATE TABLE zp_part_ops (id INT AUTO_INCREMENT PRIMARY KEY, part_id INT NOT NULL DEFAULT 1, operation_name VARCHAR(120) NOT NULL)");
$pdo->exec("INSERT INTO zp_part_ops (operation_name) VALUES ('Stitching')");
$pdo->prepare("INSERT INTO zp_entries (entry_date, worker_id, qty, rate_applied, amount) VALUES (?, 1, 100, 50, 5000.00)")->execute([$wFrom]);
$adv = Fin::giveAdvance(['employee_id' => $emp['S001'], 'amount_paisa' => 1000000, 'per_pay_paisa' => 250000, 'given_on' => $mFrom], $admin);

$port = 8741;
$srv = proc_open(sprintf('exec php -S 127.0.0.1:%d -t %s', $port, escapeshellarg(rtrim($B, '/'))), [1 => ['file', '/dev/null', 'a'], 2 => ['file', '/dev/null', 'a']], $pipes);
register_shutdown_function(static function () use ($srv) { if (is_resource($srv)) { proc_terminate($srv, 9); proc_close($srv); } });
for ($i = 0; $i < 60; $i++) { $cc = @fsockopen('127.0.0.1', $port, $e1, $e2, 0.2); if ($cc) { fclose($cc); break; } usleep(200000); }
function sess(string $email): array
{
    global $port;
    $jar = tempnam(sys_get_temp_dir(), 'attfin');
    $ch = curl_init("http://127.0.0.1:$port/api/v1/index.php?route=%2Fauth%2Flogin");
    curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_COOKIEJAR => $jar, CURLOPT_COOKIEFILE => $jar, CURLOPT_POST => true,
        CURLOPT_POSTFIELDS => json_encode(['email' => $email, 'password' => 'CorrectHorse9']), CURLOPT_HTTPHEADER => ['Content-Type: application/json']]);
    $b = json_decode((string) curl_exec($ch), true); curl_close($ch);
    return [$jar, (string) ($b['csrf_token'] ?? '')];
}
function req(array $s, string $method, string $route, ?array $body = null, bool $csrf = true): array
{
    global $port;
    [$path, $qs] = array_pad(explode('?', $route, 2), 2, '');
    $ch = curl_init("http://127.0.0.1:$port/api/v1/index.php?route=" . rawurlencode($path) . ($qs !== '' ? "&$qs" : ''));
    $h = ['Content-Type: application/json'];
    if ($csrf) { $h[] = 'X-CSRF-Token: ' . $s[1]; }
    curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_COOKIEFILE => $s[0], CURLOPT_CUSTOMREQUEST => $method, CURLOPT_HTTPHEADER => $h]);
    if ($body !== null) { curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($body)); }
    $raw = (string) curl_exec($ch); $code = curl_getinfo($ch, CURLINFO_HTTP_CODE); curl_close($ch);
    return ['status' => $code, 'body' => json_decode($raw, true) ?? [], 'raw' => $raw];
}
function page(array $s, string $path): array
{
    global $port;
    $ch = curl_init("http://127.0.0.1:$port/$path");
    curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_COOKIEFILE => $s[0]]);
    $raw = (string) curl_exec($ch); $code = curl_getinfo($ch, CURLINFO_HTTP_CODE); curl_close($ch);
    return ['status' => $code, 'raw' => $raw];
}

echo "1. The Audit job, and who gets in\n";
$as = sess('admin@zas.test');
$gj = req($as, 'POST', '/admin/jobs/grant', ['user_id' => $aud, 'role' => 'audit', 'department_id' => 0]);
ok($gj['status'] === 200 && Roles::has($aud, Roles::AUDIT), 'the administrator gives the Audit job on Setup › People (' . $gj['status'] . ')');
ok(str_contains((string) Db::value("SELECT actions FROM zu_perm WHERE user_id = ? AND module = 'finapp'", [$aud]), 'u'), '  and "Approve Pay Sheets" is ticked for them in User Access');
ok(in_array('Audit', array_column(AdminService::users()['jobs'], 'label'), true), '  Audit is one of the jobs on the People tab');
$audRow = array_values(array_filter(AdminService::users()['users'], fn($u) => (int) $u['id'] === (int) $aud))[0] ?? ['jobs' => []];
$audJob = array_values(array_filter($audRow['jobs'], fn($j) => $j['role'] === 'audit'))[0] ?? null;
ok($audJob !== null && $audJob['working'] === true, '  and the People tab shows it as working (green), not "(not working)" — its permission is a People Finance one');
$pdo->prepare("INSERT INTO zu_perm (user_id, module, actions) VALUES (?, 'finpay', 'vu')")->execute([$tick]);
$sx = ['acc' => sess('acc@zas.test'), 'hr' => sess('hr@zas.test'), 'aud' => sess('audit@zas.test'), 'hod' => sess('hod@zas.test'), 'gk' => sess('gate@zas.test'), 'tick' => sess('tick@zas.test')];
ok(req($sx['gk'], 'GET', '/finance/overview')['status'] === 403 && page($sx['gk'], 'att_finance.php')['status'] === 403, 'a gatekeeper is refused by the API and by the page');
foreach (['acc', 'hr', 'aud', 'hod', 'tick'] as $w) {
    ok(req($sx[$w], 'GET', '/finance/overview')['status'] === 200 && page($sx[$w], 'att_finance.php')['status'] === 200, "$w: the page and the API open");
}
$menu = page($sx['hod'], 'att_finance.php')['raw'];
ok(str_contains($menu, 'att_finance.php') && !str_contains(page($sx['gk'], 'att_reports.php')['raw'] . page($sx['gk'], 'index.php')['raw'], 'att_finance.php'), 'the menu shows People Finance to those it opens for, and not to the gatekeeper');
$tc = req($sx['tick'], 'GET', '/finance/overview')['body']['caps'] ?? [];
ok(($tc['pay'] ?? false) === true && ($tc['approve'] ?? true) === false && ($tc['salary'] ?? true) === false, 'a single tick in User Access ("Record Payments") gives that one power and no other');
ok(req($as, 'GET', '/finance/overview')['body']['no_setup'] === 1, 'the overview says one active employee has no pay set up');

echo "2. Each power is checked on the server\n";
ok(req($sx['hod'], 'POST', '/finance/people/' . $emp['N001'] . '/profile', ['pay_type' => 'daily', 'daily_rate_paisa' => 100000])['status'] === 403, 'a HOD cannot set anyone\'s pay');
ok(req($sx['aud'], 'POST', '/finance/people/' . $emp['N001'] . '/profile', ['pay_type' => 'daily', 'daily_rate_paisa' => 100000])['status'] === 403, 'nor can Audit');
ok(req($sx['hr'], 'POST', '/finance/people/' . $emp['N001'] . '/profile', ['pay_type' => 'daily', 'daily_rate_paisa' => 100000], false)['status'] !== 200 && $n('SELECT COUNT(*) FROM att_fin_profiles WHERE employee_id = ?', [$emp['N001']]) === 0, 'without the CSRF token nothing is saved');
ok(req($sx['hr'], 'POST', '/finance/people/' . $emp['N001'] . '/profile', ['pay_type' => 'daily', 'daily_rate_paisa' => 100000])['status'] === 200, 'HR sets up a new person\'s pay');
ok(req($sx['hr'], 'POST', '/finance/people/' . $emp['N001'] . '/profile', ['pay_type' => 'daily', 'daily_rate_paisa' => 1000.5])['status'] === 422, 'an amount that is not whole paisa is refused');
ok(req($sx['hr'], 'POST', '/finance/advances', ['employee_id' => $emp['D001'], 'amount_paisa' => 100000, 'per_pay_paisa' => 50000])['status'] === 403, 'HR cannot give an advance');
ok(req($sx['hr'], 'POST', '/finance/people/' . $emp['D003'] . '/opening', ['amount_paisa' => 100000, 'reason' => 'x'])['status'] === 403, 'nor enter an opening balance');
ok(req($sx['acc'], 'POST', '/finance/people/' . $emp['D003'] . '/opening', ['amount_paisa' => '12x', 'reason' => 'x'])['status'] === 422, 'an opening balance must be a number');
ok(req($sx['hod'], 'GET', "/finance/preview?kind=salary&from=$mFrom&to=$mTo")['status'] === 403, 'a HOD cannot open salaries');
ok(req($sx['hr'], 'GET', "/finance/preview?kind=daily&from=$wFrom&to=$wTo")['status'] === 403, 'HR cannot open daily wages');
$hp = req($sx['hod'], 'GET', '/finance/people')['body']['people'] ?? [];
$hs = array_values(array_filter($hp, fn($r) => $r['code'] === 'S001'))[0] ?? [];
ok(count($hp) === 5 && array_key_exists('basic_paisa', $hs) && $hs['basic_paisa'] === null && $hs['balance'] === null, 'the HOD\'s people: own department, with the salaried person\'s pay blank');
ok(req($sx['hod'], 'GET', '/finance/people/' . $emp['S001'] . '/ledger')['status'] === 404 && req($sx['hod'], 'GET', '/finance/people/' . $emp['D003'] . '/ledger')['status'] === 404, '  and no statement for a salaried person or another department');
ok(count(req($sx['hod'], 'GET', '/finance/advances')['body']['advances'] ?? [1]) === 0, '  and the salaried person\'s advance is not in their list');

echo "3. A browser cannot send its own figures\n";
$pv = req($sx['hr'], 'GET', "/finance/preview?kind=salary&from=$mFrom&to=$mTo")['body'];
$r1 = array_values(array_filter($pv['rows'] ?? [], fn($r) => $r['employee_id'] === $emp['S001']))[0] ?? ['calc_paisa' => -1, 'lines' => []];
ok($r1['calc_paisa'] === 6000000 + 500000 - 250000, 'HR\'s preview of last month: 60,000 + 5,000 − 2,500 advance (' . $r1['calc_paisa'] . ')');
$mk1 = req($sx['hr'], 'POST', '/finance/sheets', ['kind' => 'salary', 'from' => $mFrom, 'to' => $mTo, 'prepared_by' => $admin, 'status' => 'paid', 'items' => [
    ['employee_id' => $emp['S001'], 'amount_paisa' => 99900000, 'calc_paisa' => 99900000, 'lines' => [['key' => 'basic', 'amount_paisa' => 99900000]],
     'changes' => [['key' => 'allowance', 'amount_paisa' => 300000, 'reason' => 'Half month on site']]]]]);
$sid = (int) ($mk1['body']['sheet_id'] ?? 0);
$st = Db::one('SELECT * FROM att_fin_sheets WHERE id = ?', [$sid]) ?? [];
ok($mk1['status'] === 200 && $mk1['body']['total_paisa'] === 6000000 + 300000 - 250000, 'the sheet is 60,000 + 3,000 − 2,500: the server\'s figures with the one amended line, not the 999,000 the browser sent');
ok(($st['status'] ?? '') === 'waiting' && (int) ($st['prepared_by'] ?? 0) === $hr, '  prepared by whoever is signed in, waiting — whatever the browser claimed');
ok(req($sx['hr'], 'POST', '/finance/sheets', ['kind' => 'salary', 'from' => $mFrom, 'to' => $mTo, 'items' => [['employee_id' => $emp['S001']]]])['status'] === 422, 'the same month again is refused');
ok(req($sx['hod'], 'GET', '/finance/sheets/' . $sid)['status'] === 404 && req($sx['hod'], 'POST', '/finance/sheets/' . $sid . '/approve', [])['status'] === 403, 'the HOD cannot see it, or approve it');
ok(req($sx['hr'], 'POST', '/finance/sheets/' . $sid . '/approve', [])['status'] === 403 && req($sx['acc'], 'POST', '/finance/sheets/' . $sid . '/approve', [])['status'] === 403, 'HR and Accounts cannot approve');
ok(req($sx['acc'], 'POST', '/finance/sheets/' . $sid . '/pay', ['pay_date' => $today, 'reason' => 'x'])['status'] === 409, 'it cannot be paid before it is approved');
$am = req($sx['aud'], 'POST', '/finance/sheets/' . $sid . '/amend', ['items' => [['employee_id' => $emp['S001'], 'changes' => [['key' => 'adv:' . $adv['advance_id'], 'amount_paisa' => 100000, 'reason' => 'Asked for a smaller cut']]]]]);
ok($am['status'] === 200 && req($sx['aud'], 'GET', '/finance/sheets/' . $sid)['body']['total_paisa'] === 6000000 + 300000 - 100000, 'Audit amends one line (the advance recovery) → 62,000');
ok(req($sx['aud'], 'POST', '/finance/sheets/' . $sid . '/approve', [], false)['status'] !== 200 && Db::value('SELECT status FROM att_fin_sheets WHERE id = ?', [$sid]) === 'waiting', 'approving without the CSRF token does nothing');
ok(req($sx['aud'], 'POST', '/finance/sheets/' . $sid . '/approve', [])['status'] === 200, 'Audit approves');
ok(req($sx['aud'], 'POST', '/finance/sheets/' . $sid . '/pay', ['pay_date' => $today, 'reason' => 'x'])['status'] === 403, 'Audit cannot pay');
$np = req($sx['tick'], 'POST', '/finance/sheets/' . $sid . '/pay', ['pay_date' => $today]);
ok($isPayDay ? $np['status'] === 200 : ($np['status'] === 422 && ($np['body']['reason_code'] ?? '') === 'REASON_REQUIRED'), $isPayDay ? 'today is pay day: paid with no reason' : 'today is not Thursday: paying needs a reason');
if (!$isPayDay) { ok(req($sx['tick'], 'POST', '/finance/sheets/' . $sid . '/pay', ['pay_date' => $today, 'reason' => 'Bank closed on Thursday'])['status'] === 200, '  with the reason, the ticked user records the payment'); }
ok(Fin::balance($emp['S001']) === 0 && $n("SELECT COUNT(*) FROM att_fin_ledger WHERE sheet_id = ? AND kind = 'payment' AND amount_paisa = 6200000", [$sid]) === 1, '62,000 paid; S001 is owed nothing');
$csv = req($sx['acc'], 'GET', '/finance/sheets/' . $sid . '/export')['raw'];
ok(str_contains($csv, 'Sara Salaried') && str_contains($csv, '62000.00') && str_contains($csv, 'Asked for a smaller cut'), 'the download has the person, the amount and the amendment reason');
ok(req($sx['aud'], 'POST', '/finance/sheets/' . $sid . '/cancel', ['reason' => 'x'])['status'] === 403, 'a paid sheet is not cancelled by Audit');

echo "3a. Many at once, and the ledger\n";
ok(req($sx['hod'], 'POST', '/finance/people/bulk-profile', ['items' => [['employee_id' => $emp['D001'], 'pay_type' => 'daily', 'daily_rate_paisa' => 999900]], 'reason' => 'x'])['status'] === 403
   && $n('SELECT daily_rate_paisa FROM att_fin_profiles WHERE employee_id = ?', [$emp['D001']]) === 120000, 'a HOD cannot set pay in bulk either');
ok(req($sx['hr'], 'POST', '/finance/people/bulk-profile', ['items' => [['employee_id' => $emp['N001'], 'pay_type' => 'daily', 'daily_rate_paisa' => 105000]], 'reason' => 'Corrected rate'], false)['status'] !== 200, '  nor anybody without the CSRF token');
$lgr = req($sx['acc'], 'GET', "/finance/ledger?from=$mFrom&to=$today");
$lgS = array_column($lgr['body']['rows'] ?? [], null, 'code');
ok($lgr['status'] === 200 && ($lgS['S001']['earned'] ?? 0) === 6300000 && ($lgS['S001']['deducted'] ?? 0) === 100000 && ($lgS['S001']['paid'] ?? 0) === 6200000 && ($lgS['S001']['closing_paisa'] ?? 1) === 0, 'the ledger shows S001: earned 63,000, 1,000 recovered, 62,000 paid, owed 0');
$hl = req($sx['hod'], 'GET', "/finance/ledger?from=$mFrom&to=$today")['body']['rows'] ?? [['code' => 'S001']];
ok(!in_array('S001', array_column($hl, 'code'), true), '  the HOD\'s ledger has no salaried person in it');
ok(str_contains(req($sx['acc'], 'GET', "/finance/ledger/export?from=$mFrom&to=$today")['raw'], 'Sara Salaried'), '  and it downloads for Excel');
ok(req($sx['gk'], 'GET', '/finance/ledger')['status'] === 403, '  a gatekeeper gets nothing');

$hs = req($sx['hod'], 'POST', '/finance/staff', ['rows' => [['full_name' => 'Hod Hire', 'department' => 'Stitching']]]);
ok(count($hs['body']['errors'] ?? []) === 1 && $n("SELECT COUNT(*) FROM att_employees WHERE full_name = 'Hod Hire'") === 0, 'ONE FORM over the web: a HOD cannot add an employee through it');
ok(req($sx['hr'], 'POST', '/finance/staff', ['rows' => [['full_name' => 'No Token', 'department' => 'Stitching']]], false)['status'] !== 200 && $n("SELECT COUNT(*) FROM att_employees WHERE full_name = 'No Token'") === 0, '  nor anybody without the CSRF token');
ok(req($sx['acc'], 'POST', '/finance/staff/' . $emp['D003'] . '/active', ['active' => false, 'reason' => 'x'])['status'] === 403, '  taking somebody off the roll is for HR or an admin');
$stx = req($sx['acc'], 'GET', '/finance/people/' . $emp['S001'] . "/statement/export?from=$mFrom&to=$today")['raw'];
ok(str_contains($stx, 'Wages payable') && str_contains($stx, 'Balance brought forward') && str_contains($stx, 'PERIOD TOTALS') && preg_match('/SAL-\d{4}-\d{6}/', $stx) === 1 && preg_match('/PAY-\d{4}-\d{6}/', $stx) === 1,
   'the ledger page downloads for Excel: employee, account, dates, one referenced line each (SAL-…, PAY-…), period totals');
ok(req($sx['hod'], 'GET', '/finance/people/' . $emp['S001'] . "/statement?from=$mFrom&to=$today")['status'] === 404, '  and a HOD cannot open a salaried person\'s');

echo "3b. The old Worker Pay screen\n";
$pdo->exec("INSERT INTO zp_workers (worker_code, worker_name) VALUES ('W900', 'Unlinked Usman')");
$pdo->prepare("INSERT INTO zp_entries (entry_date, worker_id, qty, rate_applied, amount) VALUES (?, 2, 10, 70, 700.00)")->execute([$wFrom]);
$old = page($as, 'production_pay.php');
ok($old['status'] === 200 && str_contains($old['raw'], '1 worker is now paid in People Finance'), 'Worker Pay says one worker is now paid in People Finance (' . $old['status'] . ')');
preg_match('/<form method="post" id="payForm">(.*?)<\/form>/s', $old['raw'], $pf);
ok(isset($pf[1]) && str_contains($pf[1], 'Unlinked Usman') && !str_contains($pf[1], 'Kamran Contractor'), '  "Pay today" lists the worker not yet moved, and not the one who was');
$postOld = function (int $workerId) use ($as, $port, $today): string {
    $ch = curl_init("http://127.0.0.1:$port/production_pay.php");
    curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_COOKIEFILE => $as[0], CURLOPT_POST => true, CURLOPT_FOLLOWLOCATION => true,
        CURLOPT_POSTFIELDS => http_build_query(['_csrf' => $as[1], 'action' => 'pay', 'pay_date' => $today, 'p_worker' => [$workerId], 'p_amount' => ['100'], 'p_note' => ['']])]);
    $raw = (string) curl_exec($ch); curl_close($ch);
    return $raw;
};
ok(str_contains($postOld(1), 'now paid in People Finance') && $n('SELECT COUNT(*) FROM zp_pay') === 0, '  a payment posted there for the moved worker is refused — one person, one place to be paid');
ok($postOld(2) !== '' && $n('SELECT COUNT(*) FROM zp_pay WHERE worker_id = 2 AND amount = 100') === 1, '  the worker still on the old screen is paid there as before');
$uw = req($as, 'GET', '/finance/overview')['body']['unlinked_workers'] ?? [];
ok(array_column($uw, 'code') === ['W900'], 'People Finance lists who is still on the old screen');

echo "4. The screen\n";
$work = __DIR__ . '/.zattfin';
@mkdir($work, 0777, true);
file_put_contents($work . '/run.js', <<<'JS'
const { chromium } = require('playwright');
const [BASE, M_FROM, W_TO, SHOTS] = process.argv.slice(2);
const out = [];
const check = (name, pass, extra) => out.push({ name, pass: !!pass, extra: extra === undefined ? '' : String(extra) });
async function signIn(ctx, email) {
  const p = await ctx.newPage();
  await p.goto(BASE + '/att/manifest.webmanifest');
  await p.evaluate(async (email) => fetch('../api/v1/index.php?route=%2Fauth%2Flogin', { method: 'POST', credentials: 'same-origin',
    headers: { 'Content-Type': 'application/json' }, body: JSON.stringify({ email, password: 'CorrectHorse9' }) }), email);
  return p;
}
const shot = async (p, name) => { if (SHOTS) await p.screenshot({ path: SHOTS + '/' + name + '.png', fullPage: true }); };
const body = p => p.textContent('#fin-body');
(async () => {
  const browser = await chromium.launch();
  try {
    // ---- Accounts prepares last month's salary for S002 and this week's wages
    const ctx = await browser.newContext({ viewport: { width: 1366, height: 900 }, timezoneId: 'Asia/Karachi', deviceScaleFactor: SHOTS ? 2 : 1 });
    const p = await signIn(ctx, 'acc@zas.test');
    const errs = []; p.on('pageerror', e => errs.push(e.message));
    await p.goto(BASE + '/att_finance.php');
    await p.waitForSelector('#fin-body .hero');
    check('Home: what is owed, the three steps, who does what', /Owed to people right now/.test(await body(p)) && /Who does what/.test(await body(p)) && (await p.locator('.stepc').count()) === 3);
    check('  Accounts sees Prepare pay; the tabs are there', (await p.locator('#fin-tabs button').allTextContents()).join('|').includes('Prepare pay'));
    await shot(p, '1-home');
    await p.click('#fin-tabs button:has-text("Prepare pay")');
    await p.waitForSelector('.prow');
    let t = await body(p);
    check('Prepare › Fixed salary: last month, S002 listed, S001 (already paid) not', /Sami Salaried/.test(t) && !/Sara Salaried/.test(t), t.slice(0, 300));
    check('  the row says what the pay is made of', /Basic salary 42,000/.test(await p.locator('.prow', { hasText: 'Sami Salaried' }).locator('.sum').textContent()));
    await p.locator('.prow', { hasText: 'Sami Salaried' }).locator('button:has-text("Amend")').click();
    await p.waitForSelector('.prow.open .lines');
    const basic = p.locator('.prow.open .ln', { hasText: 'Basic salary' });
    check('  Amend opens the lines; basic salary is locked', await basic.locator('input.n').isDisabled() && /Fixed/.test(await basic.textContent()));
    await p.click('.prow.open button:has-text("+ Bonus")');
    const add = p.locator('.prow.open .ln.chg').last();
    await add.locator('input.n').fill('1500');
    check('  a bonus with no reason turns red and blocks sending', /Fix the red lines first/.test(await p.textContent('.sumbar')) && await add.evaluate(n => n.classList.contains('bad')));
    await add.locator('input.r').fill('Eid');
    await add.locator('.lb input').fill('Eid bonus');
    check('  with the reason: 43,500, one line changed, the worked-out 42,000 struck through beside it',
      (await p.locator('.prow.open .net b').textContent()) === '43,500' && (await p.locator('.prow.open .net s').textContent()) === '42,000' && /1 line changed/.test(await p.locator('.prow.open .nm').textContent()));
    await shot(p, '2-prepare-amend');
    await p.click('.sumbar button:has-text("Send for approval")');
    await p.waitForSelector('.scard');
    t = await body(p);
    check('  sent: it is on Pay sheets, waiting, 43,500, "1 changed by hand"', /Waiting for approval/.test(t) && /43,500/.test(t) && /1 changed by hand/.test(t), t.slice(0, 300));

    await p.click('#fin-tabs button:has-text("Prepare pay")');
    await p.click('.seg button:has-text("Daily wages")');
    await p.waitForSelector('.prow');
    t = await body(p);
    check('Prepare › Daily wages: the week to Thursday, three workers, 3 days = 3,600', /Dawood Daily/.test(t) && /Daily wages 3,600/.test(t) && (await p.inputValue('input[type=date] >> nth=1')) === W_TO, (await p.inputValue('input[type=date] >> nth=1')));
    await p.selectOption('select[aria-label="Department"]', 'Packing');
    check('  the department filter narrows the list', (await p.locator('.prow').count()) === 1 && /Danish Daily/.test(await body(p)));
    await p.selectOption('select[aria-label="Department"]', '');
    await p.click('button:has-text("Untick all")');
    check('  nothing ticked: nothing to send', await p.locator('.sumbar').isHidden());
    await p.click('button:has-text("Tick all shown")');
    await p.click('button:has-text("Add a line to everyone ticked")');
    await p.waitForSelector('#fin-shade .box');
    await p.selectOption('#fin-shade select', 'deduction');
    await p.locator('#fin-shade input').nth(0).fill('Canteen');
    await p.locator('#fin-shade input').nth(1).fill('200');
    await p.locator('#fin-shade input').nth(2).fill('Canteen bill for the week');
    await p.click('#fin-shade button:has-text("Add to all ticked")');
    await p.waitForFunction(() => document.getElementById('fin-shade').hidden);
    check('  "add a line to everyone ticked": each of the three gets the 200 deduction', (await p.locator('.prow .sum', { hasText: 'Canteen −200' }).count()) === 3 && /Rs 5,400/.test(await p.textContent('.sumbar')), await p.textContent('.sumbar'));
    await shot(p, '3-prepare-daily');
    await p.click('.sumbar button:has-text("Send for approval")');
    await p.waitForSelector('.scard');
    check('no script error (Accounts)', errs.length === 0, errs.join(' | '));
    await ctx.close();

    // ---- Audit amends a line and approves
    const c2 = await browser.newContext({ viewport: { width: 1366, height: 900 }, deviceScaleFactor: SHOTS ? 2 : 1 });
    const a = await signIn(c2, 'audit@zas.test');
    const e2 = []; a.on('pageerror', e => e2.push(e.message));
    await a.goto(BASE + '/att_finance.php#sheets');
    await a.waitForSelector('.scard');
    check('Audit: the tab shows 2 sheets waiting', /2/.test(await a.locator('#fin-tabs button:has-text("Pay sheets") .dot').textContent()));
    await a.locator('.scard', { hasText: 'Fixed salary' }).click();
    await a.waitForSelector('.track3');
    await a.click('button:has-text("Lines")');
    t = await body(a);
    check('  the sheet shows each line, the bonus and its reason', /Eid bonus/.test(t) && /Eid/.test(t) && /Net to pay/.test(t));
    await a.click('button:has-text("Amend")');
    await a.waitForSelector('.prow.open .lines input.n');
    const bonus = a.locator('.prow.open .ln', { has: a.locator('button.x') });
    await bonus.locator('input.n').fill('1000');
    await bonus.locator('input.r').fill('Eid — company rate is 1,000');
    await shot(a, '4-audit-amend');
    await a.click('.sumbar button:has-text("Save amendments")');
    await a.waitForFunction(() => /43,000/.test(document.getElementById('fin-body').textContent) && !document.querySelector('.sumbar:not([hidden])'));
    check('  Audit trims the bonus to 1,000 → 43,000', /Rs 43,000/.test(await body(a)));
    await a.click('button.btn.pri:has-text("Approve")');
    await a.click('#fin-shade button.pri:has-text("Approve")');
    await a.waitForFunction(() => /Approved — ready to pay/.test(document.getElementById('fin-body').textContent));
    check('  and approves it; no Record payment button for Audit', (await a.locator('button:has-text("Record payment")').count()) === 0 && (await a.locator('button:has-text("Amend")').count()) === 0);
    check('no script error (Audit)', e2.length === 0, e2.join(' | '));
    await c2.close();

    // ---- Admin on a phone: pays, opens a statement
    const c3 = await browser.newContext({ viewport: { width: 390, height: 844 }, deviceScaleFactor: SHOTS ? 2 : 1, isMobile: true, hasTouch: true });
    const m = await signIn(c3, 'admin@zas.test');
    const e3 = []; m.on('pageerror', e => e3.push(e.message));
    await m.goto(BASE + '/att_finance.php#sheets');
    await m.waitForSelector('.seg');
    await m.click('.seg button:has-text("To pay")');
    await m.waitForSelector('.scard');
    await m.locator('.scard').first().click();
    await m.waitForSelector('.track3');
    const wide = await m.evaluate(() => document.documentElement.scrollWidth - document.documentElement.clientWidth);
    check('Phone: the sheet fits the screen (no sideways scrolling)', wide <= 1, wide);
    await shot(m, '5-phone-sheet');
    await m.click('button:has-text("Record payment")');
    await m.waitForSelector('#fin-shade .box');
    await m.locator('#fin-shade input').nth(1).fill('Paid early, test');
    await m.click('#fin-shade button:has-text("Paid")');
    await m.waitForFunction(() => document.getElementById('fin-shade').hidden && /3 · Paid/.test(document.getElementById('fin-body').textContent) && document.querySelector('.pill.paid'));
    check('  the administrator records the payment', /Paid/.test(await m.locator('.pill.paid').first().textContent()));
    await m.click('#fin-tabs button:has-text("Employees")');
    await m.waitForSelector('table.t');
    await m.locator('tr.click', { hasText: 'Sami Salaried' }).click();
    await m.waitForFunction(() => /Wages paid/.test(document.getElementById('fin-shade').textContent));
    t = await m.textContent('#fin-shade');
    check('Employees › Sami: one dialog with the person, the pay, and a ledger page with references and period totals', /Employee and pay/.test(t) && /Eid bonus/.test(t) && /Wages paid/.test(t) && /Balance brought forward/.test(t) && /PERIOD TOTALS/.test(t) && /PAY-\d{4}-\d{6}/.test(t), t.slice(0, 300));
    check('  the sheet number is written once on a line, never twice', !/(PAY-\d{6}-\d+) · \1/.test(t), (t.match(/Wages paid[^\n]{0,60}/) || [''])[0]);
    await shot(m, '6-phone-person');
    await m.click('#fin-shade button:has-text("Close")');
    await m.click('#fin-tabs button:has-text("Ledger")');
    await m.waitForSelector('table.t tr.click');
    t = await body(m);
    check('Ledger tab: everybody in one place with owed-before / earned / paid / owed-at-end', /Owed before/.test(t) && /Sami Salaried/.test(t) && /Sara Salaried/.test(t) && /Total —/.test(t));
    await m.locator('tr.click', { hasText: 'Sami Salaried' }).click();
    await m.waitForFunction(() => /Balance brought forward/.test(document.getElementById('fin-body').textContent));
    t = await body(m);
    check('  a row opens that person\'s ledger on the same page, for the same dates', /Wages payable/.test(t) && /Balance brought forward/.test(t) && /PERIOD TOTALS/.test(t) && /Closing balance/.test(t), t.slice(0, 300));
    await shot(m, '7-phone-ledger');
    await m.click('button:has-text("← Everyone")');
    await m.waitForSelector('table.lg tr.click');
    check('no script error (phone)', e3.length === 0, e3.join(' | '));
    await c3.close();

    // ---- HR sets up several people at once
    const c5 = await browser.newContext({ viewport: { width: 1366, height: 900 }, deviceScaleFactor: SHOTS ? 2 : 1 });
    const r = await signIn(c5, 'hr@zas.test');
    const e5 = []; r.on('pageerror', e => e5.push(e.message));
    await r.goto(BASE + '/att_finance.php#people');
    await r.waitForSelector('table.t');
    check('Employees: HR has "+ New employees" and "Edit these in one form"', (await r.locator('button:has-text("+ New employees")').count()) === 1 && (await r.locator('button:has-text("Edit these in one form")').count()) === 1);
    await r.click('button:has-text("Edit these in one form")');
    await r.waitForSelector('#fin-shade table.staff');
    const idx = await r.evaluate(() => [...document.querySelectorAll('#fin-shade table.staff tbody tr')].findIndex(tr => tr.querySelector('[data-k="code"]').value === 'N001'));
    const nrow = r.locator('#fin-shade table.staff tbody tr').nth(idx);
    await nrow.locator('[data-k="type"]').selectOption('salary');
    await nrow.locator('[data-k="amt"]').fill('38000');
    await nrow.locator('[data-k="alw"]').fill('2000');
    const blank = r.locator('#fin-shade table.staff tbody tr').last();
    await blank.locator('[data-k="name"]').fill('Hira Helper');
    check('  the department is a drop-down of every department, with "+ New department…"', (await blank.locator('[data-k="dept"] option').allTextContents()).join('|').includes('Stitching') && (await blank.locator('[data-k="dept"] option').allTextContents()).join('|').includes('+ New department'));
    await blank.locator('[data-k="dept"]').selectOption('Packing');
    await blank.locator('[data-k="type"]').selectOption('daily');
    await blank.locator('[data-k="amt"]').fill('1100');
    await r.evaluate(() => {
      const rows = [...document.querySelectorAll('#fin-shade table.staff tbody tr')];
      const spare = rows.find(tr => !tr.querySelector('[data-k="name"]').value && !tr.querySelector('[data-k="code"]').value);
      const dt = new DataTransfer();
      dt.setData('text', 'X901\tPasted One\tStitching\t\tDaily wages\t1,250\nX902\tPasted Two\tWeaving\t\tFixed salary\t30000\t1500\n');
      spare.querySelector('[data-k="code"]').dispatchEvent(new ClipboardEvent('paste', { clipboardData: dt, bubbles: true, cancelable: true }));
    });
    check('  pasting two lines copied from Excel fills two lines of the form', await r.evaluate(() => [...document.querySelectorAll('#fin-shade table.staff tbody tr')].filter(tr => /^Pasted (One|Two)$/.test(tr.querySelector('[data-k="name"]').value) && tr.querySelector('input[type=checkbox]').checked).length) === 2);
    check('  an opening balance is not HR\'s to enter: that box is locked for them', await blank.locator('[data-k="open"]').isDisabled());
    await shot(r, '8-one-form');
    await r.fill('#fin-shade input[placeholder^="Reason"]', 'Moved from daily to monthly');
    await r.click('#fin-shade button.pri:has-text("Save")');
    await r.waitForFunction(() => document.getElementById('fin-shade').hidden);
    await r.waitForFunction(() => /38,000 a month \+ 2,000/.test(document.getElementById('fin-body').textContent) && /Hira Helper/.test(document.getElementById('fin-body').textContent));
    t = await body(r);
    check('ONE FORM, ONE SAVE: Nida changed to 38,000 a month + 2,000; Hira added with no code typed; both pasted people added, one into a new department', /Hira Helper/.test(t) && /Pasted One/.test(t) && /Pasted Two/.test(t) && /Weaving/.test(t) && /30,000 a month \+ 1,500/.test(t), t.slice(0, 400));
    if (SHOTS) {                                          // pictures only: no checks hang on these steps
      try {
        await r.waitForTimeout(900); await shot(r, '9-employees');
        await r.locator('tr.click', { hasText: 'Sami Salaried' }).click();
        await r.waitForFunction(() => /Balance brought forward/.test(document.getElementById('fin-shade').textContent));
        await r.waitForTimeout(600); await shot(r, '10-person-and-ledger');
        await r.click('#fin-shade button:has-text("Close")');
        await r.click('#fin-tabs button:has-text("Ledger")');
        await r.waitForSelector('table.t tr.click');
        await r.waitForTimeout(900); await shot(r, '11-ledger-tab');
        await r.goto(BASE + '/att_setup.php#emp');
        await r.waitForSelector('#atts-body table, #atts-body .note', { timeout: 8000 });
        await shot(r, '12-setup-pointer');
      } catch (e) { console.error('shots: ' + e.message); }
    }
    check('no script error (HR)', e5.length === 0, e5.join(' | '));
    await c5.close();

    // ---- THE EMPLOYEE LEDGER: Accounts adds a bonus, Audit approves, it shows with its reference
    const c6 = await browser.newContext({ viewport: { width: 1366, height: 900 }, timezoneId: 'Asia/Karachi', deviceScaleFactor: SHOTS ? 2 : 1 });
    const g = await signIn(c6, 'acc@zas.test');
    const e6 = []; g.on('pageerror', e => e6.push(e.message));
    await g.goto(BASE + '/att_finance.php#ledger');
    await g.waitForSelector('table.lg tr.click');
    check('EMPLOYEE LEDGER: the five buttons, the figures and everyone in one table', (await g.locator('.lgacts button').count()) === 5 && (await g.locator('.lgcard').count()) === 5 && /Total —/.test(await body(g)));
    await g.waitForTimeout(500); await shot(g, '13-ledger-everyone');
    await g.click('.lgacts button:has-text("Add Earning")');
    await g.waitForSelector('#fin-shade table.entry');
    const r1 = g.locator('#fin-shade table.entry tbody tr').first();
    await r1.locator('input[aria-label=Employee]').fill('Sami Salaried (S002)');
    await r1.locator('input[aria-label=Employee]').dispatchEvent('change');
    await r1.locator('select[aria-label=Type]').selectOption('bonus');
    await r1.locator('input[aria-label=Amount]').fill('2500');
    const r2 = g.locator('#fin-shade table.entry tbody tr').nth(1);
    await r2.locator('input[aria-label=Employee]').fill('Sara Salaried (S001)');
    await r2.locator('input[aria-label=Employee]').dispatchEvent('change');
    await r2.locator('select[aria-label=Type]').selectOption('overtime');
    await r2.locator('input[aria-label=Description]').fill('Sunday overtime · 6 h');
    await r2.locator('input[aria-label=Amount]').fill('1800');
    await g.fill('#fin-shade input[placeholder^="e.g. Eid"]', 'Eid bonus and Sunday work');
    await g.waitForTimeout(300); await shot(g, '14-add-earning');
    await g.click('#fin-shade button:has-text("Send for approval")');
    await g.waitForFunction(() => document.getElementById('fin-shade').hidden && /sent for approval/.test(document.getElementById('fin-toast').textContent));
    check('  Add Earning for two people: one entry sent for approval', /ENT-\d{6}-\d{2} sent for approval — 2 people, Rs 4,300/.test(await g.textContent('#fin-toast')), await g.textContent('#fin-toast'));
    await g.click('.lgacts button:has-text("Record Payment")');
    await g.waitForSelector('#fin-shade table.entry');
    check('  Record Payment shows what each person is owed and how they are paid', (await g.locator('#fin-shade table.entry th').allTextContents()).join('|').includes('Owed now'));
    await g.click('#fin-shade button:has-text("Close")');
    await g.click('#fin-tabs button:has-text("Advances")');
    await g.waitForSelector('#fin-body button:has-text("Change plan")');
    check('ADVANCES: each open advance or loan has Change plan and Recover now', (await g.locator('#fin-body button:has-text("Recover now")').count()) >= 1);
    await g.locator('#fin-body button:has-text("Recover now")').first().click();
    await g.waitForSelector('#fin-shade table.entry');
    await g.waitForFunction(() => { const s = document.querySelector('#fin-shade select[aria-label="Which advance or loan"]'); return s && !s.hidden && s.value !== ''; });
    check('  Recover now opens Add deduction with the person and that advance already chosen', /Add deduction/.test(await g.textContent('#fin-shade h2')) && (await g.inputValue('#fin-shade input[aria-label=Amount]')) !== '');
    check('  for Accounts a deduction has one button, "Save deduction" — taken off at once, no approval', (await g.locator('#fin-shade button:has-text("Save deduction")').count()) === 1 && (await g.locator('#fin-shade button:has-text("Send for approval")').count()) === 0 && /taken off at once/.test(await g.textContent('#fin-shade')));
    await g.waitForTimeout(300); await shot(g, '16-recover-now');
    await g.click('#fin-shade button:has-text("Close")');
    await g.locator('#fin-body button:has-text("Change plan")').first().click();
    await g.waitForSelector('#fin-shade input');
    check('  Change plan asks for the amount from each pay (0 pauses) and a reason', /0 to pause/.test(await g.textContent('#fin-shade')));
    await g.click('#fin-shade button:has-text("Close")');
    check('no script error (ledger, Accounts)', e6.length === 0, e6.join(' | '));
    await c6.close();
    const c7 = await browser.newContext({ viewport: { width: 1366, height: 900 }, timezoneId: 'Asia/Karachi', deviceScaleFactor: SHOTS ? 2 : 1 });
    const v = await signIn(c7, 'audit@zas.test');
    const e7 = []; v.on('pageerror', e => e7.push(e.message));
    await v.goto(BASE + '/att_finance.php#sheets');
    await v.waitForSelector('.scard');
    await v.locator('.scard', { hasText: 'ENT-' }).first().click();
    await v.waitForSelector('button:has-text("Approve")');
    check('  Audit opens the entry: no Amend, just Approve or Send back', (await v.locator('#fin-body button:has-text("Amend")').count()) === 0 && (await v.locator('#fin-body button:has-text("Send back")').count()) === 1);
    check('  and each person has Remove — one wrong line leaves, the rest stay', (await v.locator('#fin-body .prow button:has-text("Remove")').count()) === 2);
    await v.waitForTimeout(300); await shot(v, '19-entry-remove');
    await v.click('#fin-body button:has-text("Approve")');
    await v.click('#fin-shade button.pri:has-text("Approve")');
    await v.waitForFunction(() => /Posted to ledger/.test(document.getElementById('fin-body').textContent));
    check('  approved, it is POSTED to the ledger', true);
    await v.click('#fin-tabs button:has-text("Employee Ledger")');
    await v.waitForSelector('table.lg tr.click');
    await v.locator('table.lg tr.click', { hasText: 'Sami Salaried' }).click();
    await v.waitForFunction(() => /Balance brought forward/.test(document.getElementById('fin-body').textContent) && /BON-/.test(document.getElementById('fin-body').textContent));
    t = await body(v);
    check('  Sami\'s ledger now has the bonus, with its BON- reference and the reason', /BON-\d{4}-\d{6}/.test(t) && /Eid bonus and Sunday work/.test(t) && /Closing balance/.test(t), t.slice(0, 400));
    await v.waitForTimeout(500); await shot(v, '15-ledger-person');
    await v.locator('table.lg tr.click', { hasText: 'BON-' }).click();
    await v.waitForFunction(() => /ENT-/.test(document.getElementById('fin-body').textContent) && /Posted to ledger/.test(document.getElementById('fin-body').textContent));
    check('  clicking the line opens the entry it came from', true);
    check('no script error (ledger, Audit)', e7.length === 0, e7.join(' | '));
    await c7.close();

    // ---- ADMIN: everything about one person in one card, the gate photo included
    const c8 = await browser.newContext({ viewport: { width: 1366, height: 900 }, deviceScaleFactor: SHOTS ? 2 : 1 });
    const ad = await signIn(c8, 'admin@zas.test');
    const e8 = []; ad.on('pageerror', e => e8.push(e.message));
    await ad.goto(BASE + '/att_finance.php#people');
    await ad.waitForSelector('table.t tr.click');
    // the employee list sorts by any column: Owed now ▼ (largest first), then Employee ▲ (A→Z)
    const colVals = (h) => ad.evaluate((h) => { const t = document.querySelector('#fin-body table.t.sortable'); const i = [...t.tHead.rows[0].cells].findIndex(th => th.textContent.startsWith(h));
      return [...t.tBodies[0].rows].map(r => window.ZSort.value(r.cells[i])); }, h);
    await ad.click('#fin-body table.t.sortable th:has-text("Owed now")'); await ad.click('#fin-body table.t.sortable th:has-text("Owed now")');
    const owed = await colVals('Owed now');
    await ad.click('#fin-body table.t.sortable th:has-text("Employee")');
    const names = await colVals('Employee');
    check('EMPLOYEES: sortable titles — Owed now ▼ largest first, Employee ▲ A to Z', owed.length > 2 && owed.every((v, i) => i === 0 || owed[i - 1] >= v) && names.every((v, i) => i === 0 || names[i - 1].localeCompare(v) <= 0)
      && (await ad.getAttribute('#fin-body table.t.sortable th:has-text("Employee")', 'aria-sort')) === 'ascending', JSON.stringify([owed, names]));
    await shot(ad, '26-employees-sorted');
    // tick many: a bar appears with one action for all; today's chip on every line
    check('EMPLOYEES: every line has today\'s chip (inside / came / did not come / not in today / left)', (await ad.locator('#fin-body tbody tr').count()) === (await ad.locator('#fin-body tbody tr .tchip').count()));
    check('  the bar is hidden until someone is ticked', await ad.locator('#fin-body .selbar').isHidden());
    await ad.locator('#fin-body tbody tr', { hasText: 'Pasted One' }).locator('td.tick input').check();
    await ad.locator('#fin-body tbody tr', { hasText: 'Pasted Two' }).locator('td.tick input').check();
    const bar = await ad.textContent('#fin-body .selbar');
    check('  ticking two shows "2 people ticked" with Mark as left and (admin) Delete — and does not open the card', /2 people ticked/.test(bar) && /Mark as left/.test(bar) && /Delete \(entered by mistake\)/.test(bar) && await ad.locator('#fin-shade').isHidden(), bar);
    await shot(ad, '27-employees-ticked');
    await ad.click('#fin-body .selbar button:has-text("Untick all")');
    check('  Untick all hides the bar again', await ad.locator('#fin-body .selbar').isHidden());
    await ad.click('#fin-body button:has-text("Edit these in one form")');
    await ad.waitForSelector('#fin-shade table.staff');
    const heads = (await ad.locator('#fin-shade table.staff thead th').allTextContents()).join('|');
    check('ONE FORM: Opening advance / loan, Recover each pay and State come after Opening balance', /Opening balance\|Opening advance \/ loan\|Recover each pay\|State/.test(heads), heads);
    check('  "Same for all ticked" can set State', (await ad.locator('#fin-shade select[aria-label="Fill: on the roll or left"]').count()) === 1);
    await ad.locator('#fin-shade .scroll').evaluate(b => { b.scrollLeft = b.scrollWidth; });
    await shot(ad, '28-one-form-state-advance');
    await ad.click('#fin-shade button:has-text("Close")');
    await ad.waitForFunction(() => document.getElementById('fin-shade').hidden);
    await ad.locator('tr.click', { hasText: 'Sami Salaried' }).click();
    await ad.waitForFunction(() => /Photo for the gate/.test(document.getElementById('fin-shade').textContent));
    t = await ad.textContent('#fin-shade');
    check('ONE CARD (admin): code, name, department, card text, pay, the gate photo, the ledger and advances', /Card text/.test(t) && /Photo for the gate/.test(t) && /Employee and pay/.test(t) && /Advances & loans/.test(t));
    await ad.waitForTimeout(400); await shot(ad, '17-one-card-admin');
    const depOpts = await ad.locator('#fin-shade select[aria-label="Department"] option').allTextContents();
    check('ONE CARD: the department is a drop-down with EVERY department (not only the one it has), and Packing chosen', depOpts.includes('Stitching') && depOpts.includes('Packing') && depOpts.includes('Weaving') && (await ad.inputValue('#fin-shade select[aria-label="Department"]')) === 'Packing', depOpts.join('|'));
    check('  the administrator\'s card has "Delete (entered by mistake)" beside "Mark as left the company"', (await ad.locator('#fin-shade button:has-text("Delete (entered by mistake)")').count()) === 1);
    await ad.click('#fin-shade button:has-text("Delete (entered by mistake)")');
    await ad.fill('#fin-shade input[placeholder="e.g. entered twice"]', 'test');
    await ad.click('#fin-shade button.pri:has-text("Delete")');
    await ad.waitForFunction(() => /Mark as left the company/.test((document.querySelector('#fin-shade .err:not([hidden])') || {}).textContent || ''));
    check('  Sami has been paid, so Delete is refused and says why — ' + (await ad.textContent('#fin-shade .err:not([hidden])')), /pay-ledger line/.test(await ad.textContent('#fin-shade .err:not([hidden])')));
    await ad.click('#fin-shade button:has-text("Close")');
    await ad.waitForFunction(() => document.getElementById('fin-shade').hidden);
    check('EMPLOYEES: the list shows those on the roll, with "Left the company" and "Everyone" to choose', (await ad.locator('select[aria-label="Who is listed"] option').allTextContents()).join('|').includes('Left the company'));
    await ad.locator('tr.click', { hasText: 'Hira Helper' }).click();
    await ad.waitForSelector('#fin-shade select[aria-label="Department"]');
    await ad.selectOption('#fin-shade select[aria-label="Department"]', 'Stitching');
    await ad.click('#fin-shade .cardx button.pri:has-text("Save")');
    await ad.waitForFunction(() => document.getElementById('fin-shade').hidden && [...document.querySelectorAll('tr.click')].some(r => /Hira Helper/.test(r.textContent) && /Stitching/.test(r.textContent)));
    check('  changing the department and pressing Save moves Hira Helper to Stitching', true);
    await ad.locator('tr.click', { hasText: 'Sami Salaried' }).click();
    await ad.waitForFunction(() => /Photo for the gate/.test(document.getElementById('fin-shade').textContent));
    await ad.click('#fin-shade button:has-text("Close")');
    await ad.click('#fin-tabs button:has-text("Employee Ledger")');
    await ad.waitForSelector('.lgacts');
    await ad.click('.lgacts button:has-text("Add Earning")');
    await ad.waitForSelector('#fin-shade table.entry');
    check('ADMIN, one step: Add earning has "Save and approve now" beside "Send for approval"', (await ad.locator('#fin-shade button:has-text("Save and approve now")').count()) === 1 && (await ad.locator('#fin-shade button:has-text("Send for approval")').count()) === 1);
    const a1 = ad.locator('#fin-shade table.entry tbody tr').first();
    await a1.locator('input[aria-label=Employee]').fill('Kamran Contractor (C001)');
    await a1.locator('input[aria-label=Employee]').dispatchEvent('change');
    await a1.locator('select[aria-label=Type]').selectOption('production');
    await a1.locator('input[aria-label=Amount]').fill('750');
    await ad.fill('#fin-shade input[placeholder^="e.g. Eid"]', 'Target met on order 5236');
    await ad.waitForTimeout(300); await shot(ad, '20-admin-approve-now');
    await ad.click('#fin-shade button:has-text("Save and approve now")');
    await ad.waitForFunction(() => document.getElementById('fin-shade').hidden && /posted to the ledger/.test(document.getElementById('fin-toast').textContent));
    check('  entered and posted in one step: ' + (await ad.textContent('#fin-toast')), true);
    await ad.click('.lgacts button:has-text("Record Payment")');
    await ad.waitForSelector('#fin-shade table.entry');
    check('  Record payment has "Approve and pay now" for the administrator', (await ad.locator('#fin-shade button:has-text("Approve and pay now")').count()) === 1);
    await ad.click('#fin-shade button:has-text("Close")');
    await ad.click('#fin-tabs button:has-text("Mark attendance")');
    await ad.waitForSelector('table.mw');
    await ad.click('#fin-body button:has-text("‹")');
    await ad.waitForSelector('table.mw');
    await ad.click('#fin-body button:has-text("Fill days")');
    await ad.waitForSelector('#fin-shade .daypick');
    await ad.locator('#fin-shade .daypick input').nth(0).check();
    await ad.locator('#fin-shade .daypick input').nth(1).check();
    await ad.locator('#fin-shade .daypick input').nth(2).check();
    check('MARK ATTENDANCE › Fill days: one day or several at once', (await ad.locator('#fin-shade .daypick').count()) === 7);
    await ad.waitForTimeout(300); await shot(ad, '21-fill-days');
    await ad.click('#fin-shade button.pri:has-text("Fill")');
    await ad.waitForFunction(() => document.getElementById('fin-shade').hidden);
    check('  the three days are filled for every ticked worker', /days changed/.test(await body(ad)));
    // the many-contractor production adjustment grid
    await ad.click('#fin-tabs button:has-text("Employee Ledger")');
    await ad.waitForSelector('.lgacts');
    await ad.click('.lgacts button:has-text("Production adjustment")');
    await ad.waitForSelector('#fin-shade table.padj');
    await ad.locator('#fin-shade input[type=date]').first().fill(M_FROM);
    await ad.locator('#fin-shade input[type=date]').first().dispatchEvent('change');
    await ad.locator('#fin-shade input[type=date]').first().dispatchEvent('change');      // twice quickly: only the newest answer may fill the grid
    await ad.waitForFunction(() => { const r = [...document.querySelectorAll('#fin-shade table.padj tbody tr')].find(tr => /Kamran Contractor/.test(tr.textContent)); return r && /5,000/.test(r.textContent); });
    const kr = ad.locator('#fin-shade table.padj tbody tr', { hasText: 'Kamran Contractor' });
    await ad.waitForTimeout(800);
    check('PRODUCTION ADJUSTMENT GRID: each contractor with what Production booked (Kamran: 100 pieces, 5,000) — once, however fast the dates change', /100/.test(await kr.textContent()) && /5,000/.test(await kr.textContent()) && (await kr.count()) === 1, await kr.count());
    await kr.locator('button.tog').click();
    await ad.waitForSelector('#fin-shade table.padj tr.sub input[aria-label="Correct pieces"]');
    check('  Show lines opens the bill: date, product, operation, pieces, rate', /Thermal Blanket · Stitching/.test(await ad.textContent('#fin-shade table.padj')));
    await ad.fill('#fin-shade table.padj tr.sub input[aria-label="Correct pieces"]', '90');
    check('  correcting 100 pieces to 90 at 50.00 works out −500 at once', /−500/.test(await kr.textContent()), await kr.textContent());
    await ad.fill('#fin-shade input[aria-label="Same for all ticked"]', '2');
    await ad.click('#fin-shade button.pct');
    check('  the % switch turns the amount into a percentage, and says of what', (await ad.getAttribute('#fin-shade button.pct', 'aria-pressed')) === 'true' && /percent of each contractor/.test(await ad.textContent('#fin-shade')));
    await ad.click('#fin-shade .cardx button:has-text("Fill")');
    check('  Fill: 2% of the 5,000 booked = 100 taken off, for every ticked contractor', (await kr.locator('input[aria-label="Fixed amount"]').inputValue()) === '-100' && /−600/.test(await kr.textContent()), await kr.locator('input[aria-label="Fixed amount"]').inputValue());
    await ad.fill('#fin-shade input[placeholder^="e.g. Checked"]', 'Checked against the packing count');
    await ad.waitForTimeout(300); await shot(ad, '24-production-grid');
    await ad.click('#fin-shade button:has-text("Save and approve now")');
    await ad.waitForFunction(() => document.getElementById('fin-shade').hidden && /Taken off/.test(document.getElementById('fin-toast').textContent));
    const tt = await ad.textContent('#fin-toast');
    check('  saved and posted at once: Rs 600 taken off (500 for the corrected line + 2%) — ' + tt, /Taken off Rs 600 \(ENT-\d{6}-\d{2}, posted\)/.test(tt), tt);
    await ad.locator('table.lg tr.click', { hasText: 'Kamran Contractor' }).click();
    await ad.waitForFunction(() => /Balance brought forward/.test(document.getElementById('fin-body').textContent) && /Production adjustment/.test(document.getElementById('fin-body').textContent));
    t = await body(ad);
    check('  Kamran\'s ledger has both lines, each saying what and why', /100 × 50 → 90 × 50/.test(t) && /2% of 5,000/.test(t) && /Checked against the packing count/.test(t), t.slice(0, 500));
    await ad.waitForTimeout(300); await shot(ad, '25-production-ledger');
    check('no script error (admin)', e8.length === 0, e8.join(' | '));
    await c8.close();

    // ---- HR marks a week of attendance in the grid
    const c9 = await browser.newContext({ viewport: { width: 1366, height: 900 }, timezoneId: 'Asia/Karachi', deviceScaleFactor: SHOTS ? 2 : 1 });
    const hrp = await signIn(c9, 'hr@zas.test');
    const e9 = []; hrp.on('pageerror', e => e9.push(e.message));
    await hrp.goto(BASE + '/att_finance.php#mark');
    await hrp.waitForSelector('table.mw');
    check('MARK ATTENDANCE: HR has the tab and the week grid', (await hrp.locator('#fin-tabs button:has-text("Mark attendance")').count()) === 1 && (await hrp.locator('table.mw thead th.c').count()) === 7);
    await hrp.click('#fin-body button:has-text("‹")');
    await hrp.waitForFunction(() => document.querySelectorAll('table.mw .seg3').length > 0);
    await hrp.click('#fin-body button:has-text("All Full for ticked")');
    const hira = hrp.locator('table.mw tbody tr', { hasText: 'Hira Helper' });
    await hira.locator('.seg3').nth(1).locator('button:has-text("H")').click();
    await hira.locator('.seg3').nth(2).locator('button:has-text("A")').click();
    await hrp.fill('#fin-body input[placeholder^="e.g. Gate scanner"]', 'From the paper register');
    t = await hira.textContent();
    check('  All Full, then one Half and one Absent: Hira shows 6 days less 1.5 — 5.5 days, wages worked out live', /5\.5/.test(t) && /6,050/.test(t), t);
    await hrp.waitForTimeout(400); await shot(hrp, '18-mark-attendance');
    await hrp.click('#fin-body button:has-text("Save attendance")');
    await hrp.waitForFunction(() => /days? saved/.test(document.getElementById('fin-toast').textContent));
    check('  saved: ' + (await hrp.textContent('#fin-toast')), true);
    await hrp.waitForSelector('table.mw');
    check('  after saving, the marks are the attendance (nothing left to save)', /Nothing changed yet/.test(await body(hrp)) && /5\.5/.test(await hrp.locator('table.mw tbody tr', { hasText: 'Hira Helper' }).textContent()));
    check('  HR has no "Save and prepare wages" (HR does not prepare wages)', (await hrp.locator('#fin-body button:has-text("prepare wages")').count()) === 0);
    check('no script error (mark attendance)', e9.length === 0, e9.join(' | '));
    await c9.close();
    const c10 = await browser.newContext({ viewport: { width: 1366, height: 900 } });
    const acp = await signIn(c10, 'acc@zas.test');
    await acp.goto(BASE + '/att_finance.php');
    await acp.waitForSelector('#fin-tabs button');
    check('  Accounts does not see Mark attendance', (await acp.locator('#fin-tabs button:has-text("Mark attendance")').count()) === 0);
    await acp.click('#fin-tabs button:has-text("Employees")');
    await acp.waitForSelector('table.t tr.click');
    await acp.locator('tr.click', { hasText: 'Sami Salaried' }).click();
    await acp.waitForSelector('#fin-shade .cardx');
    check('  and has no Delete on an employee (Admin only)', (await acp.locator('#fin-shade button:has-text("Delete")').count()) === 0);
    await c10.close();

    // ---- Employee list: print / download, with columns that can be hidden
    const c11 = await browser.newContext({ viewport: { width: 1366, height: 900 }, timezoneId: 'Asia/Karachi', deviceScaleFactor: SHOTS ? 2 : 1, acceptDownloads: true });
    const el = await signIn(c11, 'acc@zas.test');
    const e11 = []; el.on('pageerror', x => e11.push(x.message));
    await el.goto(BASE + '/att_finance.php#home');
    await el.waitForSelector('button:has-text("Employee list — print / download")');
    check('Employee list: a button on the Home page', true);
    await el.evaluate(() => { try { localStorage.removeItem('fin-elist'); } catch (e) {} });
    await el.click('button:has-text("Employee list — print / download")');
    await el.waitForSelector('#elist-table');
    const elHeads = async () => (await el.locator('#elist-table thead th').allTextContents()).join('|');
    let ehd = await elHeads();
    check('  the short list: code, name, department, how paid, salary, advance, owed, net', /Code\|Name\|Department\|Paid as/.test(ehd) && /Salary \/ day rate/.test(ehd) && /Advance left/.test(ehd) && /Net/.test(ehd), ehd);
    const grp = (await el.locator('#elist-table tr.grp').allTextContents()).map(x => x.split(' ·')[0]).join('|');
    check('  grouped by how people are paid: fixed salary, then daily wages, then contractors', /^Fixed salary\|Daily wages\|Contractor/.test(grp), grp);
    let etx = await el.textContent('#elist-table');
    check('  Sami Salaried is listed with the monthly salary', /Sami Salaried/.test(etx) && /\/ month/.test(etx) && /\/ day/.test(etx), etx.slice(0, 300));
    check('  with totals at the bottom', (await el.locator('#elist-table tfoot td').first().textContent()).startsWith('Total'));
    await shot(el, '40-employee-list-short');
    await el.click('#fin-shade button[data-preset="audit"]');
    ehd = await elHeads();
    check('  "Finance / audit" adds every pay and advance figure: allowance, overtime rate, opening advance, earned, deducted, paid, bank', ['Allowance', 'Overtime / hour', 'Opening advance', 'Recovered each pay', 'Opening balance', 'Earned (all time)', 'Deducted (all time)', 'Paid (all time)', 'Bank account'].every(k => ehd.includes(k)), ehd);
    check('  and that button shows as chosen', await el.locator('#fin-shade button[data-preset="audit"].on').count() === 1);
    await shot(el, '41-employee-list-audit');
    const printShot = async (name) => { if (!SHOTS) return;
      await el.evaluate(() => { window.__realPrint = window.__realPrint || window.print; window.print = () => {}; });
      await el.click('#elist-print'); await el.emulateMedia({ media: 'print' });
      await el.setViewportSize({ width: 1120, height: 790 }); await shot(el, name);
      await el.emulateMedia({ media: 'screen' }); await el.setViewportSize({ width: 1366, height: 900 }); };
    await printShot('43-printed-audit');
    const [dl] = await Promise.all([el.waitForEvent('download'), el.click('#elist-csv')]);
    const csv = require('fs').readFileSync(await dl.path(), 'utf8');
    const csvHead = csv.replace(/^﻿/, '').split('\r\n')[0];
    check('  the download is a spreadsheet file with the same columns, amounts as plain numbers', /^Group,#,Code,Name/.test(csvHead) && /Rate per/.test(csvHead) && /Paid \(all time\)/.test(csvHead) && /,\d+\.\d\d,month,/.test(csv) && !/Rs|−/.test(csv), csvHead + ' / ' + csv.split('\r\n')[1]);
    check('  named with today\'s date', /^employee-list-\d{4}-\d\d-\d\d\.csv$/.test(dl.suggestedFilename()), dl.suggestedFilename());
    await el.click('#fin-shade button[data-preset="codes"]');
    ehd = await elHeads(); etx = await el.textContent('#elist-table');
    check('  "Codes only — no money": no money columns and no amounts anywhere', !/Salary|Advance|owe|Net|Allowance|Paid \(/.test(ehd) && !/\/ month|\/ day|We pay|They owe|\d,\d\d\d/.test(etx) && /Checked \/ signature/.test(ehd), ehd);
    await el.evaluate(() => { window.print = () => { window.__printed = document.getElementById('fin-print').innerText; }; });
    await el.click('#elist-print');
    const pr = await el.evaluate(() => window.__printed || '');
    check('  the print has the title, the date, "no pay figures" and no amounts', /ZAS Textiles — Employee list/.test(pr) && /no pay figures on this list/.test(pr) && /Sami Salaried/.test(pr) && !/\/ month|We pay|They owe/.test(pr), pr.slice(0, 200));
    await shot(el, '42-employee-list-codes-only');
    await printShot('44-printed-codes-only');
    await el.selectOption('#fin-shade select[aria-label="Paid as"]', 'daily');
    etx = await el.textContent('#elist-table');
    check('  "Paid as: Daily wages" lists only the daily workers', !/Sami Salaried/.test(etx) && /Daily wages/.test(etx), etx.slice(0, 200));
    await el.click('#fin-shade button:has-text("Close")');
    await el.click('#fin-tabs button:has-text("Employees")');
    await el.waitForSelector('#fin-body button:has-text("Print / download list")');
    await el.click('#fin-body button:has-text("Print / download list")');
    await el.waitForSelector('#elist-table');
    check('  also from the Employees tab, and it remembers the columns last chosen (codes only)', await el.locator('#fin-shade button[data-preset="codes"].on').count() === 1);
    check('no script error (employee list)', e11.length === 0, e11.join(' | '));
    await c11.close();
    const c12 = await browser.newContext({ viewport: { width: 1366, height: 900 } });
    const hl = await signIn(c12, 'hod@zas.test');
    await hl.goto(BASE + '/att_finance.php#people');
    await hl.waitForSelector('#fin-body button:has-text("Print / download list")');
    await hl.click('#fin-body button:has-text("Print / download list")');
    await hl.waitForSelector('#elist-table');
    await hl.click('#fin-shade button[data-preset="audit"]');
    etx = await hl.textContent('#elist-table');
    check('  a HOD\'s list: own department only, and the salaried person\'s pay shows "hidden"', /hidden/.test(etx) && !/Sami Salaried[^]*?\d{2},\d{3}\.?\d* \/ month/.test(etx), etx.slice(0, 300));
    await c12.close();

    // ---- HOD
    const c4 = await browser.newContext({ viewport: { width: 1366, height: 900 }, deviceScaleFactor: SHOTS ? 2 : 1 });
    const h = await signIn(c4, 'hod@zas.test');
    await h.goto(BASE + '/att_finance.php#prepare');
    await h.waitForSelector('.seg');
    const kinds = (await h.locator('.cardx .seg').first().locator('button').allTextContents()).join('|');
    check('HOD: Prepare offers daily wages and contractors, never fixed salary', kinds === 'Daily wages|Contractors', kinds);
    await h.click('.seg button:has-text("Contractors")');
    await h.waitForSelector('.prow');
    check('  the contractor\'s booked production is offered', /Kamran Contractor/.test(await body(h)) && /5,/.test(await body(h)));
    await h.locator('.prow', { hasText: 'Kamran Contractor' }).locator('button:has-text("Amend")').click();
    await h.waitForSelector('.prow.open .lines');
    await h.click('.prow.open button:has-text("Production adjustment")');
    await h.waitForSelector('#fin-shade input');
    const pin = h.locator('#fin-shade input[type=text]');
    await pin.nth(0).fill('20'); await pin.nth(1).fill('12.5');
    check('  ± Production adjustment: 20 pieces × 12.50 works out 250', (await pin.nth(2).inputValue()) === '250', await pin.nth(2).inputValue());
    await pin.nth(3).fill('20 pieces rejected in checking');
    await h.waitForTimeout(300); await shot(h, '22-production-adjust-form');
    await h.click('#fin-shade button:has-text("Add to sheet")');
    await h.waitForFunction(() => document.getElementById('fin-shade').hidden);
    await h.waitForTimeout(300); await shot(h, '23-production-adjust-on-sheet');
    t = await h.locator('.prow.open').textContent();
    check('  it becomes its own line on the sheet, taken off the pay; the booked figure is untouched', /Production adjustment · 20 × 12\.5/.test(await h.evaluate(() => [...document.querySelectorAll('.prow.open input')].map(i => i.value).join('|'))) && /1 line changed/.test(t), t.slice(0, 200));
    await c4.close();
  } catch (e) { check('the browser run completed', false, e && e.message); }
  finally { await browser.close(); }
  console.log('RESULT' + JSON.stringify(out));
})();
JS);
$shots = getenv('FIN_SHOTS') ?: '';
$outp = (string) shell_exec('cd ' . escapeshellarg($work) . ' && node run.js ' . escapeshellarg("http://127.0.0.1:$port") . ' ' . escapeshellarg($mFrom) . ' ' . escapeshellarg($wTo) . ' ' . escapeshellarg($shots) . ' 2>&1');
if (preg_match('/RESULT(\[.*\])/s', $outp, $mm)) {
    foreach (json_decode($mm[1], true) ?: [] as $r) { ok($r['pass'], $r['name'] . ($r['pass'] || $r['extra'] === '' ? '' : ' — ' . $r['extra'])); }
} else {
    ok(false, 'the browser test produced a result: ' . substr($outp, 0, 400));
}
ok($n("SELECT COUNT(*) FROM att_fin_sheets WHERE status = 'paid'") === 2 && Fin::balance($emp['S002']) === 250000, 'after the screen: two sheets paid, S002 settled at 43,000, then owed only the 2,500 bonus entered from the ledger');
ok($n("SELECT COUNT(*) FROM att_fin_sheets WHERE kind = 'entry' AND status = 'posted'") === 3 && $n("SELECT COUNT(*) FROM att_fin_ledger WHERE line_kind = 'bonus' AND amount_paisa = 250000") === 1
   && $n("SELECT COUNT(*) FROM att_fin_ledger WHERE line_kind = 'production' AND source = 'sheet' AND amount_paisa = 75000") === 1, '  each entry is posted once: the bonus Audit approved, and the incentive the administrator approved in one step');
ok($n("SELECT COUNT(*) FROM att_fin_item_lines WHERE kind = 'deduction' AND label = 'Canteen' AND amount_paisa = 20000") === 3, 'the bulk line is on all three daily workers\' sheet');

echo "\nPEOPLE FINANCE (web + screen): $P passed, $F failed\n";
exit($F ? 1 : 0);
