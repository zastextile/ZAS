<?php
/*
  SEARCH EVERY OPERATION AND RATE IN THE PART LIBRARY.
  ====================================================

  One box on Part Library: type "hem", "overlock pillow", "cutting rate
  duvet" or "packing bag cost" and get a short table — part, stage,
  operation, rate (and the range when some products pay differently),
  and which products use the part. A part's own line gives its whole cost
  per piece.

  TWO WAYS OF MATCHING, used together:
    words    — every word typed (or the start of it) found in the part,
               stage, operation, style or the products using it. Instant,
               free, always on.
    meaning  — embeddings (the same OpenAI model Product Master search
               uses), so "hem" also finds "fold & stitch edge". Each line's
               vector is made once and kept in zp_op_embeddings; it is made
               again only when its text changes. A question asked before
               reuses its stored vector (pm_query_vectors), so repeating a
               search costs nothing. With AI switched off, or OpenAI not
               answering, the words still work and the page says so.

  Read only: nothing here changes a part, an operation or a rate.
*/
require_once __DIR__ . '/openai.php';
require_once __DIR__ . '/pm_search.php';

/* THE VECTOR SIZE FOR OPERATION SEARCH: 256 numbers, not the 1,024 Product
   Master uses. Same OpenAI model, asked for its shorter form (OpenAI's own
   "dimensions" option). Every search unpacks one vector per operation, and on
   a one-core server unpacking 1,024 numbers per line on each search was the
   cost; 256 is four times lighter, and for names as short as "Overlock sides"
   the matches are the same. Changing it re-makes the vectors once. */
function ops_model(): array
{
    [$model, ] = pm_active_model();
    return [$model, 256];
}

function ops_schema(): void
{
    static $done = false;
    if ($done) return;
    $done = true;
    try { db()->exec("CREATE TABLE IF NOT EXISTS zp_op_embeddings (
        k VARCHAR(20) NOT NULL PRIMARY KEY,
        text_hash CHAR(64) NOT NULL,
        embedding_model VARCHAR(60) NOT NULL,
        embedding_dimensions INT NOT NULL,
        embedding_vector MEDIUMTEXT NOT NULL,
        updated_at DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4"); } catch (Throwable $e) {}
}

/** Every searchable line: one per active operation, and one per part (its whole cost). */
function ops_lines(): array
{
    $ops = db()->query("SELECT o.id, o.part_id, p.part_name, p.uom, p.style, COALESCE(s.name, o.stage, '') AS stage,
                               o.operation_name, o.rate, o.seq
                          FROM zp_part_ops o
                          JOIN zp_parts p ON p.id = o.part_id
                          LEFT JOIN zp_stage s ON s.id = o.stage_id
                         WHERE o.is_active = 1 AND p.is_active = 1
                         ORDER BY p.part_name, o.seq, o.id")->fetchAll();
    $range = [];
    try {
        foreach (db()->query("SELECT part_op_id, MIN(rate) lo, MAX(rate) hi, COUNT(DISTINCT product_id) n FROM zp_op_rate GROUP BY part_op_id")->fetchAll() as $r) {
            $range[(int) $r['part_op_id']] = [(float) $r['lo'], (float) $r['hi'], (int) $r['n']];
        }
    } catch (Throwable $e) {}
    $used = [];
    try {
        foreach (db()->query("SELECT pp.part_id, pr.name FROM zp_product_parts pp JOIN products pr ON pr.id = pp.product_id ORDER BY pr.name")->fetchAll() as $r) {
            $used[(int) $r['part_id']][] = (string) $r['name'];
        }
    } catch (Throwable $e) {}

    $lines = []; $parts = [];
    foreach ($ops as $o) {
        $pid = (int) $o['part_id'];
        $prods = $used[$pid] ?? [];
        $rg = $range[(int) $o['id']] ?? null;
        $lines[] = ['k' => 'o' . (int) $o['id'], 'kind' => 'op', 'part_id' => $pid, 'part' => (string) $o['part_name'], 'uom' => (string) $o['uom'],
                    'stage' => (string) $o['stage'], 'op' => (string) $o['operation_name'], 'rate' => (float) $o['rate'],
                    'lo' => $rg ? $rg[0] : null, 'hi' => $rg ? $rg[1] : null, 'products' => $prods,
                    'text' => 'Part: ' . $o['part_name'] . ' | Stage: ' . $o['stage'] . ' | Operation: ' . $o['operation_name']
                              . ($o['style'] ? ' | Style: ' . $o['style'] : '') . ($prods ? ' | Used in: ' . implode(', ', array_slice($prods, 0, 8)) : '')];
        $parts[$pid] ??= ['part' => (string) $o['part_name'], 'uom' => (string) $o['uom'], 'style' => (string) $o['style'], 'ops' => [], 'total' => 0.0, 'products' => $prods];
        $parts[$pid]['ops'][] = (string) $o['operation_name'];
        $parts[$pid]['total'] += (float) $o['rate'];
    }
    foreach ($parts as $pid => $p) {
        $lines[] = ['k' => 'p' . $pid, 'kind' => 'part', 'part_id' => $pid, 'part' => $p['part'], 'uom' => $p['uom'], 'stage' => '',
                    'op' => 'All operations (' . count($p['ops']) . ')', 'rate' => round($p['total'], 2), 'lo' => null, 'hi' => null, 'products' => $p['products'],
                    'text' => 'Part: ' . $p['part'] . ' | Whole cost per piece of all operations: ' . implode(', ', $p['ops'])
                              . ($p['style'] ? ' | Style: ' . $p['style'] : '') . ($p['products'] ? ' | Used in: ' . implode(', ', array_slice($p['products'], 0, 8)) : '')];
    }
    return $lines;
}

/** Score 0..1 for the words typed: each word (or its start) found counts. */
function ops_word_score(array $terms, string $hay): float
{
    if (!$terms) return 0.0;
    $words = explode(' ', $hay);
    $hit = 0;
    foreach ($terms as $t) {
        foreach ($words as $w) {
            if ($w !== '' && (strpos($w, $t) === 0 || ($t !== '' && strlen($t) >= 4 && strpos($w, $t) !== false))) { $hit++; break; }
        }
    }
    return $hit / count($terms);
}

/** Vectors for every line, made only for lines that are new or changed (at most $budget per call). */
function ops_vectors(array $lines, string $model, int $dims, int $budget = 400): array
{
    ops_schema();
    $have = [];
    try {
        $st = db()->prepare("SELECT k, text_hash, embedding_vector FROM zp_op_embeddings WHERE embedding_model = ? AND embedding_dimensions = ?");
        $st->execute([$model, $dims]);
        foreach ($st->fetchAll() as $r) $have[$r['k']] = [$r['text_hash'], $r['embedding_vector']];
    } catch (Throwable $e) {}
    $vec = []; $todo = [];
    foreach ($lines as $l) {
        $h = hash('sha256', $model . '|' . $dims . '|' . $l['text']);
        if (isset($have[$l['k']]) && $have[$l['k']][0] === $h) {
            $v = json_decode((string) $have[$l['k']][1], true);
            if (is_array($v)) { $vec[$l['k']] = $v; continue; }
        }
        if (count($todo) < $budget) $todo[] = [$l['k'], $l['text'], $h];
    }
    foreach (array_chunk($todo, 96) as $chunk) {
        $payload = ['model' => $model, 'input' => array_column($chunk, 1)];
        if ($dims > 0) $payload['dimensions'] = $dims;
        $data = openai_request('embeddings', $payload);
        $GLOBALS['ops_openai_calls'] = ($GLOBALS['ops_openai_calls'] ?? 0) + 1;
        $ins = db()->prepare("REPLACE INTO zp_op_embeddings (k, text_hash, embedding_model, embedding_dimensions, embedding_vector) VALUES (?,?,?,?,?)");
        foreach ($data['data'] ?? [] as $d) {
            $i = (int) ($d['index'] ?? -1);
            if (!isset($chunk[$i]) || empty($d['embedding'])) continue;
            $vec[$chunk[$i][0]] = $d['embedding'];
            try { $ins->execute([$chunk[$i][0], $chunk[$i][2], $model, $dims, json_encode($d['embedding'])]); } catch (Throwable $e) {}
        }
    }
    return $vec;
}

/**
 * The search. @return array{ok:bool,how:string,rows:list<array>,note?:string}
 */
function ops_search(string $q, int $limit = 12): array
{
    global $config;
    $q = trim(mb_substr($q, 0, 200));
    if (mb_strlen($q) < 2) return ['ok' => true, 'how' => 'words', 'rows' => []];
    $lines = ops_lines();
    if (!$lines) return ['ok' => true, 'how' => 'words', 'rows' => [], 'note' => 'No operations in the Part Library yet.'];

    $terms = array_values(array_filter(explode(' ', pm_norm($q)), fn($t) => $t !== '' && !in_array($t, ['the', 'of', 'for', 'and', 'rate', 'rates', 'cost', 'price', 'per', 'pc', 'pcs'], true)));
    $score = [];
    foreach ($lines as $i => $l) {
        $score[$i] = ops_word_score($terms, pm_norm($l['part'] . ' ' . $l['stage'] . ' ' . $l['op'] . ' ' . implode(' ', $l['products'])));
    }

    $how = 'words'; $note = null;
    if (!empty($config['openai_enabled']) && mb_strlen($q) >= 3) {      // meaning only from three letters; words always
        try {
            [$model, $dims] = ops_model();
            $qv = pm_query_vector($q, $model, $dims);
            $vecs = $qv ? ops_vectors($lines, $model, $dims) : [];
            if ($qv && $vecs) {
                $how = 'meaning';
                $cos = [];
                foreach ($lines as $i => $l) $cos[$i] = isset($vecs[$l['k']]) ? cosine_similarity($qv, $vecs[$l['k']]) : 0.0;
                $best = $cos ? max($cos) : 0.0;
                foreach ($lines as $i => $l) {
                    $c = $cos[$i];
                    $near = $c >= max(0.30, $best - 0.12);              // close to the best match in meaning
                    $score[$i] = $score[$i] > 0 ? 0.5 + 0.5 * max($score[$i], $c) : ($near ? $c * 0.9 : 0.0);
                }
            }
        } catch (Throwable $e) { $note = 'Meaning search is not answering right now — matched by words only.'; }
    }

    /* "packing bag cost", "total for pillow cover": the part's whole cost answers it, so it goes first */
    if (preg_match('/\b(cost|total|price|whole|all)\b/i', $q)) {
        foreach ($lines as $i => $l) if ($l['kind'] === 'part' && $score[$i] > 0) $score[$i] += 0.25;
    }
    arsort($score);
    $rows = [];
    foreach ($score as $i => $s) {
        if ($s <= 0 || count($rows) >= $limit) break;
        $l = $lines[$i];
        $rows[] = ['kind' => $l['kind'], 'part_id' => $l['part_id'], 'part' => $l['part'], 'uom' => $l['uom'], 'stage' => $l['stage'], 'op' => $l['op'],
                   'rate' => $l['rate'], 'lo' => $l['lo'], 'hi' => $l['hi'], 'products' => array_slice($l['products'], 0, 3), 'more' => max(0, count($l['products']) - 3),
                   'score' => round($s, 3)];
    }
    return ['ok' => true, 'how' => $how, 'rows' => $rows] + ($note ? ['note' => $note] : []);
}
