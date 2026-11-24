<?php
declare(strict_types=1);
/*
 * ID CARD PRINTING (owner, 24 Nov 2026): design C, with each person's job
 * title ("designation") kept on the card table, never on att_employees.
 */
$B = __DIR__ . '/app_src/public_html/';
$P = 0; $F = 0;
function ok($c, $m) { global $P, $F; if ($c) { $P++; } else { $F++; echo "  FAIL: $m\n"; } }
$dsn = getenv('ATT_TEST_DSN') ?: 'mysql:host=127.0.0.1;port=3306;dbname=zas_att_test;charset=utf8mb4';
$dbu = getenv('ATT_TEST_USER') ?: 'zas';
$dbp = getenv('ATT_TEST_PASS'); if ($dbp === false) { $dbp = 'zaspw'; }
if (!is_file($B . 'config/config.php')) { echo "SKIP: no test config — run tests/run_att.sh\n"; exit(0); }
foreach (['Db','Time','Migrations','Policy','Audit','Roles','Employees','CardService'] as $c) { require_once $B . "includes/att/$c.php"; }
use Att\CardService as C; use Att\Db; use Att\Employees; use Att\Migrations;
try {
    $pdo = new PDO($dsn, $dbu, $dbp, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC, PDO::ATTR_EMULATE_PREPARES => false]);
} catch (Throwable $e) { echo "SKIP: no MySQL available\n"; exit(0); }
Db::bind($pdo); Db::useUtc();
$pdo->exec('SET FOREIGN_KEY_CHECKS=0');
foreach ($pdo->query("SELECT table_name AS tn FROM information_schema.tables WHERE table_schema = DATABASE()")->fetchAll() as $t) { $pdo->exec('DROP TABLE IF EXISTS `' . $t['tn'] . '`'); }
$pdo->exec('SET FOREIGN_KEY_CHECKS=1');
$pdo->exec("CREATE TABLE users (id INT AUTO_INCREMENT PRIMARY KEY, name VARCHAR(150) NOT NULL, email VARCHAR(190) NOT NULL UNIQUE, password_hash VARCHAR(255) NOT NULL, role VARCHAR(40) NOT NULL DEFAULT 'colleague', department VARCHAR(60) NULL, can_see_rates TINYINT(1) NOT NULL DEFAULT 0, is_active TINYINT(1) NOT NULL DEFAULT 1)");
$mig = new Migrations($B . 'sql/att');
$mig->up();
$n = fn(string $sql, array $p = []) => (int) Db::value($sql, $p);
$dS = Employees::createDepartment('Stitching'); $dP = Employees::createDepartment('Packing');
$a = Employees::create(['code' => '112', 'full_name' => 'Adnan Butt', 'department_id' => $dS]);
$b = Employees::create(['code' => '113', 'full_name' => 'Bilal Toor', 'department_id' => $dS]);
$c = Employees::create(['code' => '201', 'full_name' => 'Packing Person', 'department_id' => $dP]);
$upd0 = (string) Db::value('SELECT updated_at FROM att_employees WHERE id = ?', [$a]);

echo "1. The database step\n";
ok(Db::columnExists('att_emp_cards', 'designation') && !Db::columnExists('att_employees', 'designation'), 'designation sits on the card table, not att_employees');

echo "2. Setting it\n";
ok((C::setDesignation([$a], str_repeat('x', 81), null, 1)['reason_code'] ?? '') === 'VALIDATION', 'more than 80 letters is refused');
ok((C::setDesignation([], 'Helper', null, 1)['reason_code'] ?? '') === 'VALIDATION', 'nobody chosen is refused');
ok((C::setDesignation([$a, $b], '  Machine   Operator ', null, 1)['changed'] ?? 0) === 2, 'one title for two people at once');
ok(Db::value('SELECT designation FROM att_emp_cards WHERE employee_id = ?', [$a]) === 'Machine Operator', '  spaces tidied');
ok((C::setDesignation([$a], 'Machine Operator', null, 1)['changed'] ?? -1) === 0, 'the same title again changes nothing');
ok((C::setDesignation([$c], 'Packer', [$dS], 1)['changed'] ?? -1) === 0, 'someone outside the user\'s departments is left alone');
ok((C::setDesignation([$b], '', null, 1)['changed'] ?? 0) === 1 && Db::value('SELECT designation FROM att_emp_cards WHERE employee_id = ?', [$b]) === null, 'empty clears it');
ok($n("SELECT COUNT(*) FROM att_card_log WHERE action = 'designation'") === 3, 'every change is logged with old → new');
ok((string) Db::value('SELECT updated_at FROM att_employees WHERE id = ?', [$a]) === $upd0, 'att_employees.updated_at untouched: the phones do not re-sync');

echo "3. What the Cards page gets\n";
$pp = []; foreach (C::people(null)['people'] as $p) { $pp[$p['code']] = $p; }
ok($pp['112']['designation'] === 'Machine Operator' && $pp['113']['designation'] === null && $pp['201']['designation'] === null, 'each person\'s designation, empty where none');
ok($pp['112']['has_photo'] === 0, '  and whether a photo is on file');
ok(C::visible($a, null) && C::visible($a, [$dS]) && !C::visible($c, [$dS]) && !C::visible(999999, null), 'a card photo only for people the user may see');

echo "4. Undo the step\n";
$mig->down(24);
ok(!Db::columnExists('att_emp_cards', 'designation') && Db::columnExists('att_emp_cards', 'weekly_limit_paisa'), 'down --to=24 removes only the designation column');
$mig->up();

echo "\nID CARDS: $P passed, $F failed\n";
exit($F ? 1 : 0);
