<?php
declare(strict_types=1);

/* PEOPLE FINANCE IS THE ONLY LIST OF PEOPLE (owner, 18 Nov 2026).
 * zp_people_sync() copies code, name, department and on-the-roll from
 * att_employees into production's own worker rows; Daily Entry offers only
 * those. Wages already booked to an old worker are never touched.
 * The functions are lifted from includes/zprod.php and run against MySQL.
 * Run through tests/run_att.sh. */

$P = 0; $F = 0;
function ok($c, $m) { global $P, $F; if ($c) { $P++; } else { $F++; echo "  FAIL: $m\n"; } }
$dsn = getenv('ATT_TEST_DSN') ?: 'mysql:host=127.0.0.1;port=3306;dbname=zas_att_test;charset=utf8mb4';
$dbu = getenv('ATT_TEST_USER') ?: 'zas';
$dbp = getenv('ATT_TEST_PASS'); if ($dbp === false) { $dbp = 'zaspw'; }
try { $pdo = new PDO($dsn, $dbu, $dbp, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC]); }
catch (Throwable $e) { echo "SKIP: no MySQL available\n"; exit(0); }
function db(): PDO { global $pdo; return $pdo; }
function zp_ensure_schema(): void {}
$META = [];
function zp_meta_get(string $k): string { global $META; return $META[$k] ?? ''; }
function zp_meta_set(string $k, string $v): void { global $META; $META[$k] = $v; }

$src = file_get_contents(dirname(__DIR__) . '/public_html/includes/zprod.php');
preg_match('/const ZP_CODE_MAX = (\d+);/', $src, $m); define('ZP_CODE_MAX', (int) $m[1]);
function zp_stage_all(bool $activeOnly = false): array { return [['id' => 1, 'name' => 'Cutting'], ['id' => 2, 'name' => 'Stitching'], ['id' => 3, 'name' => 'Packing']]; }
foreach (['function zp_workers(bool', 'function zp_people_in_use(', 'function zp_people_sync(', 'function zp_workers_not_in_people(', 'function zp_stage_bulk(', 'function zp_worker_stage_map('] as $sig) {
    $a = strpos($src, $sig); $b = strpos($src, "\n}\n", $a);
    eval(substr($src, $a, $b - $a + 3));
}

$pdo->exec('SET FOREIGN_KEY_CHECKS=0');
foreach (['att_employees', 'att_departments', 'zp_workers', 'zp_entries', 'zp_worker_stage'] as $t) $pdo->exec("DROP TABLE IF EXISTS $t");
$pdo->exec('SET FOREIGN_KEY_CHECKS=1');
$pdo->exec("CREATE TABLE att_departments (id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY, name VARCHAR(120) NOT NULL) COLLATE utf8mb4_unicode_ci");
$pdo->exec("CREATE TABLE att_employees (id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY, code VARCHAR(40) NOT NULL, full_name VARCHAR(160) NOT NULL,
            department_id BIGINT UNSIGNED NOT NULL, worker_code VARCHAR(40) NULL, active TINYINT(1) NOT NULL DEFAULT 1) COLLATE utf8mb4_unicode_ci");
$pdo->exec("CREATE TABLE zp_workers (id INT AUTO_INCREMENT PRIMARY KEY, worker_code VARCHAR(40) NOT NULL, worker_name VARCHAR(120) NOT NULL, department VARCHAR(60) NULL,
            is_active TINYINT(1) NOT NULL DEFAULT 1, created_by INT NULL, att_employee_id BIGINT UNSIGNED NULL, UNIQUE KEY uniq_worker_code (worker_code)) COLLATE utf8mb4_0900_ai_ci");
$pdo->exec("CREATE TABLE zp_worker_stage (id INT AUTO_INCREMENT PRIMARY KEY, worker_id INT NOT NULL, stage_id INT NOT NULL, UNIQUE KEY uniq_ws (worker_id, stage_id))");
$pdo->exec("CREATE TABLE zp_entries (id INT AUTO_INCREMENT PRIMARY KEY, worker_id INT NOT NULL, amount DECIMAL(14,2) NOT NULL, status VARCHAR(12) NOT NULL DEFAULT 'active')");

$pdo->exec("INSERT INTO att_departments (id, name) VALUES (1, 'STITCHING'), (2, 'PACKING')");
$pdo->exec("INSERT INTO att_employees (code, full_name, department_id, worker_code) VALUES ('611', 'Abdul Rehman', 1, NULL), ('406', 'Sara Khan', 2, NULL), ('145', 'Abdullah', 1, 'w145')");
/* production as it was: one worker typed with the same code as People Finance (wages booked), one with a production code, one nobody matches */
$pdo->exec("INSERT INTO zp_workers (worker_code, worker_name, department, is_active) VALUES ('611', 'A. Rehman (typed)', 'Floor 1', 1), ('W145', 'Abdulla', NULL, 1), ('W050', 'Old Hand', 'Floor 2', 1)");
$pdo->exec("INSERT INTO zp_entries (worker_id, amount) VALUES (1, 500), (3, 250)");

echo "1. People Finance is copied into production\n";
$r = zp_people_sync(true);
$w = fn(string $code) => $pdo->query("SELECT * FROM zp_workers WHERE worker_code = " . $pdo->quote($code))->fetch();
ok($r['ok'] && $r['added'] === 1 && $r['updated'] === 2, 'one added (Sara), two existing workers joined up (' . json_encode($r) . ')');
ok((int) $w('611')['id'] === 1 && $w('611')['worker_name'] === 'Abdul Rehman' && $w('611')['department'] === 'STITCHING', '611 keeps its worker row (and its wages) and now reads as People Finance: Abdul Rehman, STITCHING');
ok($w('W145')['worker_name'] === 'Abdullah' && (int) $w('W145')['id'] === 2, 'the "Production worker code" w145 joins W145 (any letter case)');
ok($w('406') && $w('406')['worker_name'] === 'Sara Khan' && $w('406')['department'] === 'PACKING', 'Sara (406), new in People Finance, gets a production worker by herself');
$offered = array_column(zp_workers(true), 'worker_name');
sort($offered);
ok($offered === ['Abdul Rehman', 'Abdullah', 'Sara Khan'], 'Daily Entry offers exactly the People Finance people (' . implode(', ', $offered) . ')');
ok(array_column(zp_workers_not_in_people(), 'worker_code') === ['W050'], 'Old Hand (W050) is listed as not in People Finance');
ok((int) $pdo->query("SELECT COUNT(*) FROM zp_entries")->fetchColumn() === 2 && (int) $w('W050')['is_active'] === 1, '  and nothing of theirs is deleted or changed');

echo "2. A change in People Finance shows in production\n";
$pdo->exec("UPDATE att_employees SET full_name = 'Sara Ahmed', department_id = 1 WHERE code = '406'");
$pdo->exec("UPDATE att_employees SET active = 0 WHERE code = '611'");
zp_people_sync();
ok($w('406')['worker_name'] === 'Sara Ahmed' && $w('406')['department'] === 'STITCHING', 'renamed and moved in People Finance — renamed and moved here');
ok((int) $w('611')['is_active'] === 0 && !in_array('Abdul Rehman', array_column(zp_workers(true), 'worker_name'), true), 'left in People Finance — inactive here, not offered');
$before = $META['people_fp'] ?? '';
$r = zp_people_sync();
ok(($r['skipped'] ?? false) === true && $META['people_fp'] === $before, 'nothing changed — nothing is done (one fingerprint compared)');

echo "3. A code that is somebody else's is never taken\n";
$pdo->exec("INSERT INTO att_employees (code, full_name, department_id, worker_code) VALUES ('900', 'Second Person', 1, 'W145')");
$r = zp_people_sync(true);
ok(in_array('W145', $r['conflicts'], true) && $w('W145')['worker_name'] === 'Abdullah', 'W145 belongs to Abdullah: the second person is refused and named, Abdullah is untouched');

echo "4. Many people, one stage (Production Workers)\n";
$ids = array_map('intval', $pdo->query("SELECT id FROM zp_workers ORDER BY id")->fetchAll(PDO::FETCH_COLUMN));
$pdo->exec("INSERT INTO zp_worker_stage (worker_id, stage_id) VALUES ({$ids[0]}, 3)");
$r = zp_stage_bulk([$ids[0], $ids[1], 99999], [2], []);
$first = $r['stages'][$ids[0]] ?? []; sort($first);
ok($r['ok'] && $r['n'] === 2 && $first === [2, 3] && ($r['stages'][$ids[1]] ?? null) === [2], 'Stitching given to two people at once; an unknown id is ignored; the first keeps Packing (' . json_encode($r['stages'] ?? null) . ')');
$r = zp_stage_bulk([$ids[0], $ids[1]], [], [2]);
ok($r['ok'] && $r['stages'][$ids[0]] === [3] && $r['stages'][$ids[1]] === [], 'Stitching taken off both; Packing stays where it was');
ok(zp_stage_bulk([], [2], [])['ok'] === false && zp_stage_bulk([$ids[0]], [999], [])['ok'] === false, 'nobody ticked, or a stage that does not exist, is refused');

echo "\nPEOPLE FINANCE → PRODUCTION: $P passed, $F failed\n";
exit($F ? 1 : 0);
