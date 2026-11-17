<?php
declare(strict_types=1);

/* PRODUCT MATCHING, FAST — includes/pm_search.php.
 * Final costing (and Re-sync, Link product) asked OpenAI for every invoice
 * line's vector on every open, and loaded and decoded every product's
 * vector once per line. Now a line text's vector is kept in
 * pm_query_vectors (the same text goes to OpenAI once, ever) and the
 * products' vectors are read once per page. The match itself must be
 * exactly what it was: this test compares it with the version before the
 * change (tests/fixtures/pm_search_before_speedfix.php), on 300 products
 * and 25 invoice lines. Run through tests/run_att.sh. */

$P = 0; $F = 0;
function ok($c, $m) { global $P, $F; if ($c) { $P++; } else { $F++; echo "  FAIL: $m\n"; } }
$dsn = getenv('ATT_TEST_DSN') ?: 'mysql:host=127.0.0.1;port=3306;dbname=zas_att_test;charset=utf8mb4';
$dbu = getenv('ATT_TEST_USER') ?: 'zas';
$dbp = getenv('ATT_TEST_PASS'); if ($dbp === false) { $dbp = 'zaspw'; }
try { $pdo = new PDO($dsn, $dbu, $dbp, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC, PDO::ATTR_EMULATE_PREPARES => false]); }
catch (Throwable $e) { echo "SKIP: no MySQL available\n"; exit(0); }
function db(): PDO { global $pdo; return $pdo; }
$config = ['openai_enabled' => true, 'openai_api_key' => '', 'pm_embedding_model' => 'text-embedding-3-large', 'pm_embedding_dimensions' => 1024];

foreach (['products', 'product_embeddings', 'customer_product_mappings', 'pm_query_vectors', 'zas_schema_marks'] as $t) { $pdo->exec("DROP TABLE IF EXISTS $t"); }
$pdo->exec("CREATE TABLE products (id INT AUTO_INCREMENT PRIMARY KEY, name VARCHAR(200) NOT NULL, category VARCHAR(80) NULL, is_active TINYINT(1) NOT NULL DEFAULT 1, size VARCHAR(40) NULL)");
require dirname(__DIR__) . '/public_html/includes/pm_search.php';
pm_schema();

/* 300 products, each with a 1024-number vector, as the live system has */
mt_srand(7);
$kinds = ['Bath Towel', 'Hand Towel', 'Bed Sheet', 'Pillow Cover', 'Duvet Cover', 'Bath Mat', 'Kitchen Towel', 'Bathrobe', 'Fitted Sheet', 'Face Towel'];
$vec = function () { $v = []; for ($i = 0; $i < 1024; $i++) $v[] = round(mt_rand(-1000, 1000) / 1000, 4); return $v; };
$ins = $pdo->prepare("INSERT INTO products (name, category, size) VALUES (?, 'Home', ?)");
$emb = $pdo->prepare("INSERT INTO product_embeddings (entity_type, entity_id, embedding_model, embedding_dimensions, embedding_vector, embedded_text, content_hash, status)
                      VALUES ('variant', ?, 'text-embedding-3-large', 1024, ?, '', '', 'current')");
$V = [];
for ($i = 1; $i <= 300; $i++) {
    $ins->execute([$kinds[$i % 10] . ' Style ' . $i, (string) (50 + $i % 7 * 10) . 'x' . (90 + $i % 5 * 10)]);
    $V[$i] = $vec(); $emb->execute([$i, json_encode($V[$i])]);
}
/* 25 invoice lines a buyer typed their own way; each line's vector sits near one product's */
$lines = [];
for ($j = 1; $j <= 25; $j++) {
    $target = $j * 11 % 300 + 1;
    $q = array_map(fn($x) => round($x + mt_rand(-200, 200) / 1000, 4), $V[$target]);
    $text = 'Buyer item ' . $j . ' ' . strtolower($kinds[$target % 10]);
    $lines[] = ['name' => $text, 'desc' => 'white', 'vec' => $q, 'target' => $target];
}

echo "1. The same text never goes to OpenAI twice\n";
$GLOBALS['pm_openai_calls'] = 0;
$m0 = pm_match($lines[0]['name'], $lines[0]['desc']);
ok($GLOBALS['pm_openai_calls'] === 1 && $m0['method'] !== 'embedding', 'a text seen for the first time is sent to OpenAI (here no key: it falls back to the name match, as before)');
/* what OpenAI would answer, kept as if asked once */
$keep = $pdo->prepare("INSERT INTO pm_query_vectors (k, embedding_model, embedding_dimensions, embedding_vector) VALUES (?, 'text-embedding-3-large', 1024, ?)");
foreach ($lines as $l) {
    $qtext = "RECORD TYPE: Invoice Product\nPRODUCT: {$l['name']}\nDESCRIPTION: {$l['desc']}";
    $keep->execute([hash('sha256', 'text-embedding-3-large|1024|' . $qtext), json_encode($l['vec'])]);
}
$GLOBALS['pm_openai_calls'] = 0;
$t0 = microtime(true); $new = []; foreach ($lines as $l) { $new[] = pm_match($l['name'], $l['desc']); } $tNew = microtime(true) - $t0;
ok($GLOBALS['pm_openai_calls'] === 0, '25 lines whose text was asked before: no call to OpenAI at all (' . $GLOBALS['pm_openai_calls'] . ')');
ok(count(array_filter($new, fn($m) => $m['method'] === 'embedding' && ($m['candidates'][0]['product_id'] ?? 0) > 0)) === 25, 'every line still matched through its vector');
ok(count(array_filter($new, fn($m, $i) => (int) $m['candidates'][0]['product_id'] === $lines[$i]['target'], ARRAY_FILTER_USE_BOTH)) === 25, '  each to the product it is closest to');

echo "2. The match is exactly what it was before the change\n";
/* the version before the change, run on the same data in its own process (it defines the same function names) */
$work = sys_get_temp_dir() . '/zpm_' . getmypid(); @mkdir($work);
file_put_contents("$work/old.php", '<?php
    $pdo = new PDO(' . var_export($dsn, true) . ', ' . var_export($dbu, true) . ', ' . var_export($dbp, true) . ', [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC]);
    function db(): PDO { global $pdo; return $pdo; }
    $config = ' . var_export($config, true) . ';
    $lines = json_decode(file_get_contents(' . var_export("$work/lines.json", true) . '), true);
    require ' . var_export(__DIR__ . '/fixtures/pm_search_before_speedfix.php', true) . ';
    /* the old code asked OpenAI here; it gets the same answer the new one kept */
    $out = []; $t0 = microtime(true);
    foreach ($lines as $l) { $out[] = pm_match_old_with_vector($l); }
    echo json_encode(["t" => microtime(true) - $t0, "m" => $out]);
    function pm_match_old_with_vector(array $l): array {
        $GLOBALS["__qv"] = $l["vec"];
        return pm_match($l["name"], $l["desc"]);
    }');
/* the old file calls create_embedding_ex() from openai.php: give it a stand-in that answers with the kept vector */
$old = (string) file_get_contents(__DIR__ . '/fixtures/pm_search_before_speedfix.php');
$old = str_replace("require_once __DIR__ . '/openai.php';", "function create_embedding_ex(\$t, \$m, \$d = 0) { return ['vector' => \$GLOBALS['__qv'], 'tokens' => 0]; }\nfunction cosine_similarity(array \$a, array \$b): float { \$dot = 0; \$na = 0; \$nb = 0; \$len = min(count(\$a), count(\$b)); for (\$i=0; \$i<\$len; \$i++) { \$dot += \$a[\$i] * \$b[\$i]; \$na += \$a[\$i] * \$a[\$i]; \$nb += \$b[\$i] * \$b[\$i]; } if (\$na == 0 || \$nb == 0) return 0; return \$dot / (sqrt(\$na) * sqrt(\$nb)); }", $old);
file_put_contents("$work/pm_old_runnable.php", $old);
file_put_contents("$work/old.php", str_replace(var_export(__DIR__ . '/fixtures/pm_search_before_speedfix.php', true), var_export("$work/pm_old_runnable.php", true), (string) file_get_contents("$work/old.php")));
file_put_contents("$work/lines.json", json_encode($lines));
$res = json_decode((string) shell_exec('php ' . escapeshellarg("$work/old.php") . ' 2>&1'), true);
ok(is_array($res) && count($res['m'] ?? []) === 25, 'the version before the change ran on the same 25 lines');
$same = 0;
foreach (($res['m'] ?? []) as $i => $o) {
    $n = $new[$i];
    if ($o['method'] === $n['method'] && $o['product_id'] === $n['product_id'] && $o['score'] === $n['score'] && $o['needs_confirm'] === $n['needs_confirm']
        && array_column($o['candidates'], 'product_id') === array_column($n['candidates'], 'product_id') && array_column($o['candidates'], 'score') === array_column($n['candidates'], 'score')) $same++;
}
ok($same === 25, "all 25 matches identical: same products, same three suggestions, same scores, same \"needs confirming\" ($same of 25)");
foreach (['Bath Towel Style 10' => 'exact_name', 'zzz unknown thing' => null] as $txt => $want) {
    $a = pm_match($txt); 
    ok($want === null || $a['method'] === $want, "\"$txt\": " . $a['method']);
}

echo "3. How much faster\n";
$tOld = (float) ($res['t'] ?? 0);
printf("   matching 25 lines against 300 products: before %.2fs + 25 calls to OpenAI over the internet, now %.2fs and none\n", $tOld, $tNew);
ok($tNew < $tOld, sprintf('the work on the server itself is faster too (%.2fs → %.2fs)', $tOld, $tNew));
ok((int) $pdo->query("SELECT COUNT(*) FROM pm_query_vectors")->fetchColumn() === 25, 'pm_query_vectors holds one row per line text asked (25)');

foreach (['products', 'product_embeddings', 'customer_product_mappings', 'pm_query_vectors', 'zas_schema_marks'] as $t) { $pdo->exec("DROP TABLE IF EXISTS $t"); }
array_map('unlink', glob("$work/*")); @rmdir($work);
echo "\nPRODUCT MATCHING FAST: $P passed, $F failed\n";
exit($F ? 1 : 0);
