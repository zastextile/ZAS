<?php
declare(strict_types=1);

/* TABLE CHECKS RUN ONCE — includes/schema_once.php.
 * Each module's "create the table / add the column" check used to run on
 * every page load; it now runs once per version of its file, again when a
 * table is added, and a column is changed only when it really differs —
 * without waiting on anyone reading the table. Each step runs in its own
 * PHP process, as one page load does. Run through tests/run_att.sh. */

$P = 0; $F = 0;
function ok($c, $m) { global $P, $F; if ($c) { $P++; } else { $F++; echo "  FAIL: $m\n"; } }
$dsn = getenv('ATT_TEST_DSN') ?: 'mysql:host=127.0.0.1;port=3306;dbname=zas_att_test;charset=utf8mb4';
$dbu = getenv('ATT_TEST_USER') ?: 'zas';
$dbp = getenv('ATT_TEST_PASS'); if ($dbp === false) { $dbp = 'zaspw'; }
$O = [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC];
try { $pdo = new PDO($dsn, $dbu, $dbp, $O); } catch (Throwable $e) { echo "SKIP: no MySQL available\n"; exit(0); }

$lib = dirname(__DIR__) . '/public_html/includes/schema_once.php';
$work = sys_get_temp_dir() . '/zschema_' . getmypid();
@mkdir($work);
$checkFile = "$work/module.php";
file_put_contents($checkFile, "<?php // version 1\n");
foreach (['zas_schema_marks', 'zs_t_items', 'zs_t_new'] as $t) { $pdo->exec("DROP TABLE IF EXISTS $t"); }
$pdo->exec("CREATE TABLE zs_t_items (id INT PRIMARY KEY, note VARCHAR(10) NULL)");

/* one "page load": run the check through the gate, report what happened */
function load(string $body): array {
    global $lib, $dsn, $dbu, $dbp, $checkFile, $work;
    $code = '<?php
        $pdo = new PDO(' . var_export($dsn, true) . ', ' . var_export($dbu, true) . ', ' . var_export($dbp, true) . ', [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC]);
        $pdo->exec("SET SESSION lock_wait_timeout = 3");
        function db(): PDO { global $pdo; return $pdo; }
        require ' . var_export($lib, true) . ';
        $F = ' . var_export($checkFile, true) . ';
        $ran = false;
        ' . $body . '
        echo json_encode(["ran" => $ran]);';
    file_put_contents("$work/load.php", $code);
    $out = (string) shell_exec('php ' . escapeshellarg("$work/load.php") . ' 2>&1');
    return json_decode($out, true) ?: ['ran' => null, 'raw' => $out];
}
$gate = 'if (!zas_schema_fresh("mod", [$F])) { $ran = true; try { db()->exec("ALTER TABLE zs_t_items ADD COLUMN extra INT NULL"); } catch (Throwable $e) {} zas_schema_mark("mod", [$F]); }';

echo "1. Once, not on every page\n";
ok(load($gate)['ran'] === true, 'the first page load runs the check (nothing remembered yet)');
ok((int) $pdo->query("SELECT COUNT(*) FROM zas_schema_marks WHERE name = 'mod'")->fetchColumn() === 1, '  and remembers it in zas_schema_marks (made by itself)');
ok(load($gate)['ran'] === false && load($gate)['ran'] === false, 'the next page loads skip it');

echo "2. Again when it has a reason to\n";
file_put_contents($checkFile, "<?php // version 2 — a new column added\n");
ok(load($gate)['ran'] === true, 'a new version of the file (uploaded) runs it once more');
ok(load($gate)['ran'] === false, '  then it is skipped again');
$pdo->exec("CREATE TABLE zs_t_new (id INT PRIMARY KEY)");
ok(load($gate)['ran'] === true, 'a table added by another module runs it once more (it may need a column there)');
ok(load($gate)['ran'] === false, '  then it is skipped again');
$pdo->exec("DELETE FROM zas_schema_marks");
ok(load($gate)['ran'] === true, 'emptying zas_schema_marks makes every check run again');
$pdo->exec("DROP TABLE zas_schema_marks");
ok(load($gate)['ran'] === true && load($gate)['ran'] === false, 'with the marks table gone it runs, makes the table again, and remembers');

echo "3. A column is changed only when it differs — and never waits on a reader when it does not\n";
$modify = '$ran = true; zas_modify_if_needed("zs_t_items", "note", "varchar(40)", true, "ALTER TABLE zs_t_items MODIFY note VARCHAR(40) NULL");';
load($modify);
$col = $pdo->query("SHOW COLUMNS FROM zs_t_items LIKE 'note'")->fetch();
ok(strtolower($col['Type']) === 'varchar(40)', 'a column that differs is changed (varchar(10) → varchar(40))');
/* someone is reading the table, in an open transaction */
$reader = new PDO($dsn, $dbu, $dbp, $O);
$reader->beginTransaction();
$reader->query("SELECT * FROM zs_t_items")->fetchAll();
$t0 = microtime(true); $r = load($modify); $dt = microtime(true) - $t0;
ok($r['ran'] === true && $dt < 2.0, sprintf('the same check while someone reads the table: done in %.2fs, no lock taken (before: waited for the reader)', $dt));
$t0 = microtime(true); load('$ran = true; try { db()->exec("ALTER TABLE zs_t_items MODIFY note VARCHAR(40) NULL"); } catch (Throwable $e) {}'); $dt2 = microtime(true) - $t0;
ok($dt2 >= 2.5, sprintf('  (the old way, a plain MODIFY, waits for the reader: %.1fs here, stopped by a 3s limit)', $dt2));
$reader->rollBack();
ok((int) $pdo->query("SELECT COUNT(*) FROM zs_t_items")->fetchColumn() === 0 && strtolower($pdo->query("SHOW COLUMNS FROM zs_t_items LIKE 'note'")->fetch()['Type']) === 'varchar(40)', 'the table is as it should be');

echo "4. In the app\n";
$boot = (string) file_get_contents(dirname(__DIR__) . '/public_html/includes/bootstrap.php');
ok(str_contains($boot, "zas_schema_fresh('core'") && !preg_match('/^\s*db\(\)->exec\("ALTER TABLE shipments MODIFY/m', $boot), 'the startup file checks its columns once, and changes the shipments column only when it differs');
$plain = 0;
foreach (['includes/bootstrap.php', 'includes/inventory.php', 'includes/zprod.php', 'includes/costing.php', 'includes/production.php'] as $f) {
    $plain += preg_match_all('/db\(\)->exec\("ALTER TABLE \w+ MODIFY/', (string) file_get_contents(dirname(__DIR__) . '/public_html/' . $f));
}
ok($plain === 0, 'no plain "ALTER TABLE … MODIFY" left in the startup file, Inventory, Production or Costing (' . $plain . ')');
foreach (['inventory.php' => 'inv', 'zprod.php' => 'zp', 'costing.php' => 'costing', 'production.php' => 'production', 'ai_costing.php' => 'aic', 'final_costing.php' => 'fc', 'access.php' => 'zu'] as $f => $n) {
    $s = (string) file_get_contents(dirname(__DIR__) . '/public_html/includes/' . $f);
    ok(str_contains($s, "zas_schema_fresh('$n', [__FILE__])") && str_contains($s, "zas_schema_mark('$n', [__FILE__])"), "$f: its table check runs once per version");
}

foreach (['zas_schema_marks', 'zs_t_items', 'zs_t_new'] as $t) { $pdo->exec("DROP TABLE IF EXISTS $t"); }
array_map('unlink', glob("$work/*")); @rmdir($work);
echo "\nTABLE CHECKS ONCE: $P passed, $F failed\n";
exit($F ? 1 : 0);
