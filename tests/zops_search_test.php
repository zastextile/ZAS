<?php
declare(strict_types=1);

/* SEARCH EVERY OPERATION AND RATE — includes/op_search.php (Part Library box).
 * Words always work; meaning (embeddings) finds what the words miss. No
 * OpenAI here: vectors are stored beforehand, exactly as they are kept on
 * the server after the first search, so no call may be made at all.
 * Run through tests/run_att.sh. */

$P = 0; $F = 0;
function ok($c, $m) { global $P, $F; if ($c) { $P++; } else { $F++; echo "  FAIL: $m\n"; } }
$dsn = getenv('ATT_TEST_DSN') ?: 'mysql:host=127.0.0.1;port=3306;dbname=zas_att_test;charset=utf8mb4';
$dbu = getenv('ATT_TEST_USER') ?: 'zas';
$dbp = getenv('ATT_TEST_PASS'); if ($dbp === false) { $dbp = 'zaspw'; }
try { $pdo = new PDO($dsn, $dbu, $dbp, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC, PDO::ATTR_EMULATE_PREPARES => false]); }
catch (Throwable $e) { echo "SKIP: no MySQL available\n"; exit(0); }
function db(): PDO { global $pdo; return $pdo; }
$config = ['openai_enabled' => false, 'openai_api_key' => '', 'pm_embedding_model' => 'text-embedding-3-large', 'pm_embedding_dimensions' => 1024];

foreach (['zp_parts', 'zp_part_ops', 'zp_stage', 'zp_op_rate', 'zp_product_parts', 'products', 'product_embeddings', 'pm_query_vectors', 'zp_op_embeddings', 'customer_product_mappings', 'zas_schema_marks'] as $t) { $pdo->exec("DROP TABLE IF EXISTS $t"); }
$pdo->exec("CREATE TABLE products (id INT AUTO_INCREMENT PRIMARY KEY, name VARCHAR(200) NOT NULL, is_active TINYINT(1) NOT NULL DEFAULT 1)");
$pdo->exec("CREATE TABLE zp_parts (id INT AUTO_INCREMENT PRIMARY KEY, part_name VARCHAR(120) NOT NULL, uom VARCHAR(20) NOT NULL DEFAULT 'Pc', style VARCHAR(80) NULL, is_active TINYINT(1) NOT NULL DEFAULT 1)");
$pdo->exec("CREATE TABLE zp_stage (id INT AUTO_INCREMENT PRIMARY KEY, seq INT NOT NULL DEFAULT 0, name VARCHAR(60) NOT NULL)");
$pdo->exec("CREATE TABLE zp_part_ops (id INT AUTO_INCREMENT PRIMARY KEY, part_id INT NOT NULL, stage_id INT NULL, seq INT NOT NULL DEFAULT 0, stage VARCHAR(60) NULL, operation_name VARCHAR(120) NOT NULL, rate DECIMAL(12,2) NOT NULL DEFAULT 0, is_active TINYINT(1) NOT NULL DEFAULT 1)");
$pdo->exec("CREATE TABLE zp_op_rate (id INT AUTO_INCREMENT PRIMARY KEY, product_id INT NOT NULL, part_op_id INT NOT NULL, size_id INT NOT NULL, rate DECIMAL(12,2) NOT NULL)");
$pdo->exec("CREATE TABLE zp_product_parts (id INT AUTO_INCREMENT PRIMARY KEY, product_id INT NOT NULL, part_id INT NOT NULL)");
require dirname(__DIR__) . '/public_html/includes/op_search.php';
pm_schema();

$pdo->exec("INSERT INTO zp_stage (id, seq, name) VALUES (1,1,'Cutting'),(2,2,'Stitching'),(3,3,'Packing')");
$pdo->exec("INSERT INTO products (id, name) VALUES (1,'Face Towel 30x30'),(2,'Pillow Cover 50x75')");
$parts = ['Face Towel' => [[1, 'Towel roll cutting', 1.2], [2, 'Hemming 2 ends', 2.2], [3, 'Fold & bundle', 0.6]],
          'Pillow Cover' => [[1, 'Fabric cutting', 2.5], [2, 'Overlock sides', 5], [2, 'Fold & stitch flap edge', 4], [3, 'Fold & pack', 1.5]],
          'Packing Bag' => [[2, 'Bag side sealing', 1.5], [3, 'Insert & seal', 0.9]]];
foreach ($parts as $name => $ops) {
    $pdo->prepare("INSERT INTO zp_parts (part_name) VALUES (?)")->execute([$name]); $pid = (int) $pdo->lastInsertId();
    foreach ($ops as $i => [$sid, $op, $rate]) $pdo->prepare("INSERT INTO zp_part_ops (part_id, stage_id, seq, operation_name, rate) VALUES (?,?,?,?,?)")->execute([$pid, $sid, $i + 1, $op, $rate]);
}
$pdo->exec("INSERT INTO zp_product_parts (product_id, part_id) VALUES (1,1),(2,2)");
$hemId = (int) $pdo->query("SELECT id FROM zp_part_ops WHERE operation_name = 'Hemming 2 ends'")->fetchColumn();
$pdo->exec("INSERT INTO zp_op_rate (product_id, part_op_id, size_id, rate) VALUES (1, $hemId, 0, 2.6)");
$ops = fn(array $r) => array_map(fn($x) => $x['part'] . ' / ' . $x['op'], $r['rows']);

echo "1. Words — always on\n";
$r = ops_search('hem');
ok($r['how'] === 'words' && $ops($r) === ['Face Towel / Hemming 2 ends'], '"hem" by words finds Hemming 2 ends (' . implode('; ', $ops($r)) . ')');
ok($r['rows'][0]['stage'] === 'Stitching' && (float) $r['rows'][0]['rate'] === 2.2 && (float) $r['rows'][0]['lo'] === 2.6, '  with its stage, its rate 2.20 and the product rate 2.60');
ok($r['rows'][0]['products'] === ['Face Towel 30x30'], '  and the product that uses the part');
$r = ops_search('overlock pillow');
ok(($r['rows'][0]['op'] ?? '') === 'Overlock sides' && $r['rows'][0]['part'] === 'Pillow Cover', '"overlock pillow": Pillow Cover / Overlock sides first');
$r = ops_search('packing bag cost');
ok(($r['rows'][0]['kind'] ?? '') === 'part' && $r['rows'][0]['part'] === 'Packing Bag' && abs((float) $r['rows'][0]['rate'] - 2.4) < 0.001, 'a cost question puts the part\'s whole cost first: Packing Bag 2.40');
ok(ops_search('x')['rows'] === [], 'one letter searches nothing');

echo "2. Meaning — embeddings, stored, no OpenAI call when stored\n";
$config['openai_enabled'] = true;   // the key is empty: any real call would fail and show as a note
[$model, $dims] = ops_model();
ok($dims === 256, 'operation search uses the light 256-number vectors');
$axis = ['hem' => ['hem', 'hemming', 'fold', 'edge', 'ends'], 'cut' => ['cut', 'cutting', 'roll'], 'pack' => ['pack', 'bundle', 'bag', 'seal', 'insert'], 'lock' => ['overlock', 'sides']];
$fv = function (string $t) use ($axis) { $w = explode(' ', pm_norm($t)); $v = []; foreach ($axis as $syn) { $n = 0; foreach ($w as $x) foreach ($syn as $s) if ($x !== '' && strpos($x, $s) === 0) $n++; $v[] = $n; } $v[] = 0.1;
    $l = sqrt(array_sum(array_map(fn($a) => $a * $a, $v))) ?: 1; return array_map(fn($a) => $a / $l, $v); };
ops_schema();
$put = $pdo->prepare("REPLACE INTO zp_op_embeddings (k, text_hash, embedding_model, embedding_dimensions, embedding_vector) VALUES (?,?,?,?,?)");
foreach (ops_lines() as $l) $put->execute([$l['k'], hash('sha256', $model . '|' . $dims . '|' . $l['text']), $model, $dims, json_encode($fv(preg_replace('/Part: |Stage: |Operation: |Used in:.*$/', ' ', $l['text'])))]);
$pdo->prepare("INSERT INTO pm_query_vectors (k, embedding_model, embedding_dimensions, embedding_vector) VALUES (?,?,?,?)")->execute([hash('sha256', $model . '|' . $dims . '|hem'), $model, $dims, json_encode($fv('hem'))]);
$GLOBALS['ops_openai_calls'] = 0; $GLOBALS['pm_openai_calls'] = 0;
$r = ops_search('hem');
ok($r['how'] === 'meaning', '"hem" now searches by words + meaning');
ok(in_array('Pillow Cover / Fold & stitch flap edge', $ops($r), true) && ($ops($r)[0] ?? '') === 'Face Towel / Hemming 2 ends',
   '  meaning also finds "Fold & stitch flap edge" (no word "hem" in it), after the word match (' . implode('; ', $ops($r)) . ')');
ok(!in_array('Pillow Cover / Fabric cutting', $ops($r), true), '  and leaves out what means something else (Fabric cutting)');
ok($GLOBALS['ops_openai_calls'] === 0 && $GLOBALS['pm_openai_calls'] === 0, 'with every vector stored, no call to OpenAI is made');

echo "3. A changed operation is embedded again; OpenAI down → words, with a note\n";
$pdo->exec("UPDATE zp_part_ops SET operation_name = 'Hemming 2 ends (double fold)' WHERE id = $hemId");
$r = ops_search('hem');
ok($r['how'] === 'words' && isset($r['note']) && ($r['rows'][0]['op'] ?? '') === 'Hemming 2 ends (double fold)',
   'the changed line needs a new vector; OpenAI is not reachable here, so it answers by words and says so (' . ($r['note'] ?? '') . ')');

echo "\nOPERATION SEARCH: $P passed, $F failed\n";
exit($F ? 1 : 0);
