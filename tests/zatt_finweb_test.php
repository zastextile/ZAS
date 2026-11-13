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
    await m.click('#fin-tabs button:has-text("People")');
    await m.waitForSelector('table.t');
    await m.locator('tr.click', { hasText: 'Sami Salaried' }).click();
    await m.waitForFunction(() => /Wages paid/.test(document.getElementById('fin-shade').textContent));
    t = await m.textContent('#fin-shade');
    check('People › Sami: pay setup, and a statement ending at zero', /How they are paid/.test(t) && /Eid bonus/.test(t) && /Wages paid/.test(t));
    await shot(m, '6-phone-person');
    check('no script error (phone)', e3.length === 0, e3.join(' | '));
    await c3.close();

    // ---- HOD
    const c4 = await browser.newContext({ viewport: { width: 1366, height: 900 } });
    const h = await signIn(c4, 'hod@zas.test');
    await h.goto(BASE + '/att_finance.php#prepare');
    await h.waitForSelector('.seg');
    const kinds = (await h.locator('.cardx .seg').first().locator('button').allTextContents()).join('|');
    check('HOD: Prepare offers daily wages and contractors, never fixed salary', kinds === 'Daily wages|Contractors', kinds);
    await h.click('.seg button:has-text("Contractors")');
    await h.waitForSelector('.prow');
    check('  the contractor\'s booked production (5,000) is offered', /Kamran Contractor/.test(await body(h)) && /5,000/.test(await body(h)));
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
ok($n("SELECT COUNT(*) FROM att_fin_sheets WHERE status = 'paid'") === 2 && Fin::balance($emp['S002']) === 0, 'after the screen: two sheets paid, S002 settled at 43,000');
ok($n("SELECT COUNT(*) FROM att_fin_item_lines WHERE kind = 'deduction' AND label = 'Canteen' AND amount_paisa = 20000") === 3, 'the bulk line is on all three daily workers\' sheet');

echo "\nPEOPLE FINANCE (web + screen): $P passed, $F failed\n";
exit($F ? 1 : 0);
