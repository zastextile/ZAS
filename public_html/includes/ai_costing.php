<?php
/*
  AI-Assisted Product Costing — Phase 1 (see brief).
  Service layer only: AI EXTRACTS structured draft data from free text,
  PHP VALIDATES + CALCULATES + SEARCHES, and nothing is ever saved by this
  layer — costing_ai_extract.php returns a preview; the browser applies the
  result into the SAME client-side draft model product_costing.php already
  uses for "+ New Costing Version", so the existing Save Costing button
  (and its existing DB-write code) is the only thing that ever persists
  anything. AI never calculates totals, never invents missing numbers,
  never saves, never approves.
*/

const AIC_MAX_SIZES = 8;
const AIC_GROUPS = ['Fabric', 'Accessories', 'Packing', 'Workmanship', 'Other'];

function aic_ensure_schema(): void {
    try { db()->exec("CREATE TABLE IF NOT EXISTS ai_costing_log (
        id INT AUTO_INCREMENT PRIMARY KEY,
        user_id INT NULL,
        user_name VARCHAR(120) NULL,
        action VARCHAR(30) NOT NULL,
        input_text MEDIUMTEXT NULL,
        model VARCHAR(60) NULL,
        input_tokens INT NOT NULL DEFAULT 0,
        output_tokens INT NOT NULL DEFAULT 0,
        estimated_cost DECIMAL(10,6) NOT NULL DEFAULT 0,
        sizes_count INT NOT NULL DEFAULT 0,
        success TINYINT(1) NOT NULL DEFAULT 0,
        error_message VARCHAR(500) NULL,
        created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
        INDEX(user_id), INDEX(created_at)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4"); } catch (Throwable $e) {}

    // Item standardization (brief section 23) — deliberately a small lookup table,
    // NOT a parallel costing structure. Seeded once with common textile aliases;
    // grows over time as you see more variants. Never auto-applied — only ever
    // shown as a one-click suggestion.
    try {
        db()->exec("CREATE TABLE IF NOT EXISTS costing_item_aliases (
            id INT AUTO_INCREMENT PRIMARY KEY,
            alias_norm VARCHAR(160) NOT NULL,
            standard_item VARCHAR(160) NOT NULL,
            standard_group VARCHAR(40) NULL,
            standard_unit VARCHAR(40) NULL,
            created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
            UNIQUE KEY uniq_alias (alias_norm)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
        $seed = [
            ['poly bag', 'Individual Polybag', 'Packing', 'Piece'], ['pe bag', 'Individual Polybag', 'Packing', 'Piece'],
            ['plastic bag', 'Individual Polybag', 'Packing', 'Piece'], ['individual poly', 'Individual Polybag', 'Packing', 'Piece'],
            ['polybag', 'Individual Polybag', 'Packing', 'Piece'],
            ['stiching', 'Stitching', 'Workmanship', 'Piece'], ['stitiching', 'Stitching', 'Workmanship', 'Piece'], ['stich', 'Stitching', 'Workmanship', 'Piece'],
            ['cut sew', 'Cutting & Stitching', 'Workmanship', 'Piece'], ['cut and sew', 'Cutting & Stitching', 'Workmanship', 'Piece'],
            ['sewing thred', 'Sewing Thread', 'Accessories', 'Set'], ['thread', 'Sewing Thread', 'Accessories', 'Set'],
            ['insert card', 'Insert Card', 'Packing', 'Piece'], ['hang tag', 'Insert Card', 'Packing', 'Piece'],
            ['master ctn', 'Master Carton', 'Packing', 'Carton'], ['ctn', 'Master Carton', 'Packing', 'Carton'], ['carton', 'Master Carton', 'Packing', 'Carton'],
            ['brand lbl', 'Brand Label', 'Accessories', 'Piece'], ['size lbl', 'Size Label', 'Accessories', 'Piece'], ['care lbl', 'Care Label', 'Accessories', 'Piece'],
        ];
        $ins = db()->prepare("INSERT IGNORE INTO costing_item_aliases (alias_norm, standard_item, standard_group, standard_unit) VALUES (?,?,?,?)");
        foreach ($seed as $s) $ins->execute($s);
    } catch (Throwable $e) {}

    // Cached AI comparison summaries — keyed by the two version ids plus both
    // totals at cache time, so if either version changes (total_cost differs)
    // the cache is treated as stale and a fresh summary is generated. This is
    // what makes "Refresh AI Analysis" a manual choice rather than a re-bill
    // on every click of Compare.
    try { db()->exec("CREATE TABLE IF NOT EXISTS ai_costing_compare_cache (
        id INT AUTO_INCREMENT PRIMARY KEY,
        version_a_id INT NOT NULL,
        version_b_id INT NOT NULL,
        total_cost_a DECIMAL(16,2) NOT NULL DEFAULT 0,
        total_cost_b DECIMAL(16,2) NOT NULL DEFAULT 0,
        summary_text MEDIUMTEXT NULL,
        model VARCHAR(60) NULL,
        created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
        UNIQUE KEY uniq_pair (version_a_id, version_b_id)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4"); } catch (Throwable $e) {}

    // Same idea as the pair cache above, for a 2-to-6-version multi compare —
    // one row per distinct SET of versions (keyed by a hash of their ids +
    // their total_costs, so it invalidates itself if either changes).
    try { db()->exec("CREATE TABLE IF NOT EXISTS ai_costing_compare_multi_cache (
        id INT AUTO_INCREMENT PRIMARY KEY,
        cache_key CHAR(32) NOT NULL,
        version_ids VARCHAR(120) NULL,
        summary_text MEDIUMTEXT NULL,
        model VARCHAR(60) NULL,
        created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
        UNIQUE KEY uniq_key (cache_key)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4"); } catch (Throwable $e) {}

    // additive, safe-to-repeat indexes on existing tables (section 7 of the brief) —
    // MySQL has no "ADD INDEX IF NOT EXISTS", so a duplicate-key error is caught & ignored.
    $idx = [
        "ALTER TABLE costing_versions ADD INDEX idx_status (status)",
        "ALTER TABLE costing_versions ADD INDEX idx_costing_no (costing_no)",
        "ALTER TABLE costing_versions ADD INDEX idx_product_status (product_id, status)",
        "ALTER TABLE costing_versions ADD INDEX idx_created_at (created_at)",
        "ALTER TABLE products ADD INDEX idx_product_code (product_code)",
        "ALTER TABLE product_sizes ADD INDEX idx_size_label (size_label)",
        "ALTER TABLE costing_lines ADD INDEX idx_item_name (item_name(100))",
    ];
    foreach ($idx as $sql) { try { db()->exec($sql); } catch (Throwable $e) {} }
}

function aic_log(string $action, string $inputText, string $model, int $inTok, int $outTok, float $cost, bool $success, string $err = '', int $sizesCount = 0): void {
    try {
        $u = current_user();
        db()->prepare("INSERT INTO ai_costing_log (user_id,user_name,action,input_text,model,input_tokens,output_tokens,estimated_cost,sizes_count,success,error_message) VALUES (?,?,?,?,?,?,?,?,?,?,?)")
            ->execute([$u['id'] ?? null, $u['name'] ?? 'System', $action, mb_substr($inputText, 0, 4000), $model, $inTok, $outTok, $cost, $sizesCount, $success ? 1 : 0, $err !== '' ? mb_substr($err, 0, 500) : null]);
    } catch (Throwable $e) {}
}

/* ===== 1. AI EXTRACTION (text -> strict draft JSON; no math, no invention) ===== */
function aic_extract(string $text): array {
    global $config;
    $model = $config['ai_costing_model'] ?? 'gpt-4o-mini';
    $schema = <<<SCHEMA
Return ONLY strict JSON with this exact shape (no markdown, no commentary):
{
 "request_type": "single_size_costing" | "multi_size_costing",
 "product": {"product_name": string|null, "product_code": string|null, "composition": string|null, "construction": string|null, "currency": string|null},
 "sizes": [{"size_name": string}, ...]   (1 to 8 items, never more than 8),
 "common_lines": [
   {"group": "Fabric"|"Accessories"|"Packing"|"Workmanship"|"Other", "item": string, "quantity": number|null, "unit": string|null, "rate": number|null, "pieces_per_carton": number|null, "variable_by_size": boolean}
 ],
 "size_overrides": [
   {"size_name": string, "item": string, "quantity": number|null, "rate": number|null}
 ],
 "search_existing": boolean,
 "approved_costings_only": boolean,
 "missing_fields": [string, ...],
 "warnings": [string, ...]
}
SCHEMA;
    $rules = <<<RULES
You extract structured DRAFT costing data from a textile export manager's plain English description. You are an extraction tool only.

Hard rules — never break these:
- Detect whether this is one size or multiple sizes (maximum 8 sizes; if more than 8 are named, keep only the first 8 and add a warning explaining the rest were dropped).
- A line is "common" if the manager states it applies to every size the same way. A line is size-variable (variable_by_size=true, quantity=null in common_lines) if it differs by size — put its per-size values in size_overrides instead.
- If a "master carton" or "shared carton" is mentioned with a pieces-per-carton count, put that count in pieces_per_carton on the Packing line, and leave quantity as given (normally 1 carton).
- NEVER invent a quantity, rate, weight, product code, or size that was not stated or clearly implied. If something needed is missing, leave it null and add a plain-language entry to missing_fields.
- NEVER calculate any amount, subtotal, or total — that is not your job.
- NEVER decide anything is approved or saved — you only extract a draft.
- Keep warnings short and in plain language (e.g. "Only 8 of 10 sizes were kept — the maximum per request is 8.").
- If the text is not about product costing at all, return sizes:[] and common_lines:[] and explain why in warnings.

$schema
RULES;
    $prompt = $rules . "\n\nMANAGER'S TEXT:\n" . $text;

    $t0 = microtime(true);
    $resp = openai_request('chat/completions', [
        'model' => $model,
        'messages' => [['role' => 'user', 'content' => $prompt]],
        'max_tokens' => (int)($config['ai_costing_max_tokens'] ?? 1200),
        'temperature' => 0.1,
        'response_format' => ['type' => 'json_object'],
    ]);
    $ms = (int)round((microtime(true) - $t0) * 1000);
    $content = $resp['choices'][0]['message']['content'] ?? '';
    $parsed = json_decode($content, true);
    $inTok = (int)($resp['usage']['prompt_tokens'] ?? 0);
    $outTok = (int)($resp['usage']['completion_tokens'] ?? 0);
    $cost = round($inTok / 1e6 * (float)($config['ai_price_in_per_m'] ?? 0.15) + $outTok / 1e6 * (float)($config['ai_price_out_per_m'] ?? 0.60), 6);

    if (!is_array($parsed)) {
        throw new Exception('AI did not return valid JSON.');
    }
    return [
        'data' => $parsed,
        'usage' => ['model' => $model, 'input_tokens' => $inTok, 'output_tokens' => $outTok, 'estimated_cost' => $cost, 'response_time_ms' => $ms],
    ];
}

/* ===== 2. PHP-SIDE VALIDATION (never trust the AI's shape or limits) ===== */
function aic_num_or_null($v) {
    if ($v === null || $v === '') return null;
    return is_numeric($v) ? (float)$v : null;
}
function aic_txt($v, int $max = 200): string { return mb_substr(trim((string)($v ?? '')), 0, $max); }

function aic_validate(array $raw): array {
    $errors = [];
    $warnings = array_values(array_filter(array_map('strval', $raw['warnings'] ?? []), fn($w) => $w !== ''));
    $missing = array_values(array_filter(array_map('strval', $raw['missing_fields'] ?? []), fn($w) => $w !== ''));

    $product = is_array($raw['product'] ?? null) ? $raw['product'] : [];
    $productClean = [
        'product_name' => aic_txt($product['product_name'] ?? '', 200),
        'product_code' => aic_txt($product['product_code'] ?? '', 60),
        'composition' => aic_txt($product['composition'] ?? '', 200),
        'construction' => aic_txt($product['construction'] ?? '', 200),
        'currency' => strtoupper(aic_txt($product['currency'] ?? 'PKR', 8)) ?: 'PKR',
    ];
    if ($productClean['product_name'] === '') $errors[] = 'AI could not identify a product name in the text.';

    $sizesRaw = is_array($raw['sizes'] ?? null) ? $raw['sizes'] : [];
    if (count($sizesRaw) > AIC_MAX_SIZES) {
        $warnings[] = 'Request contained more than ' . AIC_MAX_SIZES . ' sizes — only the first ' . AIC_MAX_SIZES . ' were kept.';
        $sizesRaw = array_slice($sizesRaw, 0, AIC_MAX_SIZES);
    }
    $sizes = [];
    foreach ($sizesRaw as $s) {
        $name = aic_txt(is_array($s) ? ($s['size_name'] ?? '') : $s, 80);
        if ($name !== '') $sizes[] = $name;
    }
    $sizes = array_values(array_unique($sizes));
    if (!$sizes) $errors[] = 'No sizes could be identified.';

    $commonRaw = is_array($raw['common_lines'] ?? null) ? $raw['common_lines'] : [];
    $common = [];
    foreach ($commonRaw as $l) {
        if (!is_array($l)) continue;
        $item = aic_txt($l['item'] ?? '', 160);
        if ($item === '') continue;
        $group = in_array($l['group'] ?? '', AIC_GROUPS, true) ? $l['group'] : 'Other';
        $qty = aic_num_or_null($l['quantity'] ?? null);
        $rate = aic_num_or_null($l['rate'] ?? null);
        $ppc = aic_num_or_null($l['pieces_per_carton'] ?? null);
        $variable = !empty($l['variable_by_size']);
        if ($rate === null) $missing[] = "Rate missing for \"$item\" — enter it manually before applying.";
        if (!$variable && $qty === null) $missing[] = "Quantity missing for \"$item\" — enter it manually before applying.";
        $common[] = ['group' => $group, 'item' => $item, 'desc' => aic_txt($l['description'] ?? '', 300), 'quantity' => $qty, 'unit' => aic_txt($l['unit'] ?? '', 40), 'rate' => $rate, 'pieces_per_carton' => $ppc, 'variable_by_size' => $variable];
    }
    if (!$common) $errors[] = 'No costing lines could be identified.';

    $overridesRaw = is_array($raw['size_overrides'] ?? null) ? $raw['size_overrides'] : [];
    $overrides = [];
    foreach ($overridesRaw as $o) {
        if (!is_array($o)) continue;
        $sn = aic_txt($o['size_name'] ?? '', 80);
        $item = aic_txt($o['item'] ?? '', 160);
        if ($sn === '' || $item === '') continue;
        $overrides[] = ['size_name' => $sn, 'item' => $item, 'quantity' => aic_num_or_null($o['quantity'] ?? null), 'rate' => aic_num_or_null($o['rate'] ?? null)];
    }

    // every size-variable common line must have an override for every size, or that size is flagged
    foreach ($common as $cl) {
        if (!$cl['variable_by_size']) continue;
        foreach ($sizes as $sz) {
            $found = false;
            foreach ($overrides as $o) { if (mb_strtolower($o['size_name']) === mb_strtolower($sz) && mb_strtolower($o['item']) === mb_strtolower($cl['item'])) { $found = true; break; } }
            if (!$found) $missing[] = "\"{$cl['item']}\" has no value given for size \"$sz\".";
        }
    }

    return [
        'ok' => empty($errors),
        'errors' => $errors,
        'data' => ['product' => $productClean, 'sizes' => $sizes, 'common_lines' => $common, 'size_overrides' => $overrides, 'missing_fields' => array_values(array_unique($missing)), 'warnings' => array_values(array_unique($warnings))],
    ];
}

/* ===== 3. SQL-ONLY SEARCH (exact + basic similar; no embeddings in Phase 1) ===== */
function aic_search_existing(array $product, int $limit = 5): array {
    $name = trim($product['product_name'] ?? '');
    $code = trim($product['product_code'] ?? '');
    $out = [];
    try {
        if ($code !== '') {
            $st = db()->prepare("SELECT id,name,category,product_code FROM products WHERE product_code=? AND is_active=1 LIMIT ?");
            $st->bindValue(1, $code); $st->bindValue(2, $limit, PDO::PARAM_INT); $st->execute();
            $out = $st->fetchAll();
        }
        if (!$out && $name !== '') {
            $st = db()->prepare("SELECT id,name,category,product_code FROM products WHERE is_active=1 AND (name LIKE ? OR category LIKE ? OR notes LIKE ?) ORDER BY (name=?) DESC LIMIT ?");
            $like = '%' . $name . '%';
            $st->bindValue(1, $like); $st->bindValue(2, $like); $st->bindValue(3, $like); $st->bindValue(4, $name); $st->bindValue(5, $limit, PDO::PARAM_INT);
            $st->execute();
            $out = $st->fetchAll();
        }
        foreach ($out as &$row) {
            $vs = db()->prepare("SELECT id, costing_no, version_name, status FROM costing_versions WHERE product_id=? AND status='approved' ORDER BY id DESC LIMIT 5");
            $vs->execute([$row['id']]);
            $row['approved_costings'] = $vs->fetchAll();
        }
        unset($row);
    } catch (Throwable $e) {}
    return $out;
}

/* ===== 4. PHP CALCULATION (reuses the exact shared/normal formula used
   everywhere else in this app: shared -> rate/qty, normal -> qty*rate) ===== */
function aic_line_amount(array $l): float {
    $q = (float)($l['quantity'] ?? 0); $r = (float)($l['rate'] ?? 0);
    return !empty($l['shared']) ? ($q > 0 ? round($r / $q, 2) : 0.0) : round($q * $r, 2);
}

function aic_calculate(array $data): array {
    $common = $data['common_lines']; $overrides = $data['size_overrides']; $sizes = $data['sizes'];
    $results = [];
    foreach ($sizes as $sz) {
        $lines = []; $sizeMissing = [];
        foreach ($common as $cl) {
            $qty = $cl['quantity']; $rate = $cl['rate']; $shared = false;
            if ($cl['pieces_per_carton'] !== null && $cl['pieces_per_carton'] > 0) { $shared = true; $qty = $cl['pieces_per_carton']; }
            if ($cl['variable_by_size']) {
                $ov = null;
                foreach ($overrides as $o) { if (mb_strtolower($o['size_name']) === mb_strtolower($sz) && mb_strtolower($o['item']) === mb_strtolower($cl['item'])) { $ov = $o; break; } }
                if ($ov) { if ($ov['quantity'] !== null) $qty = $ov['quantity']; if ($ov['rate'] !== null) $rate = $ov['rate']; }
                else { $sizeMissing[] = "{$cl['item']} not given for this size."; }
            }
            $line = ['group' => $cl['group'], 'item' => $cl['item'], 'desc' => $cl['desc'], 'quantity' => $qty ?? 0, 'unit' => $cl['unit'], 'weight' => 0, 'rate' => $rate ?? 0, 'shared' => $shared];
            $line['amount'] = aic_line_amount($line);
            $lines[] = $line;
        }
        $total = array_sum(array_column($lines, 'amount'));
        $groupTotals = ['Fabric' => 0, 'Accessories' => 0, 'Packing' => 0, 'Workmanship' => 0, 'Other' => 0];
        foreach ($lines as $l) $groupTotals[$l['group']] += $l['amount'];
        $results[] = ['size_name' => $sz, 'lines' => $lines, 'total_cost' => round($total, 2), 'group_totals' => array_map(fn($v) => round($v, 2), $groupTotals), 'missing' => $sizeMissing, 'ok' => empty($sizeMissing)];
    }
    return $results;
}

/* ===== PHASE 2 ===== */

// Common bed-linen size progression for a "nearest size" guess when no exact
// match exists. Unknown size names fall back to alphabetical distance.
const AIC_SIZE_ORDER = ['XS', 'Single', 'Small', 'Double', 'Medium', 'Queen', 'Large', 'King', 'XL', 'Super King', 'XXL', 'Extra King'];

function aic_size_index(string $sizeName): ?int {
    foreach (AIC_SIZE_ORDER as $i => $s) if (mb_strtolower($s) === mb_strtolower($sizeName)) return $i;
    return null;
}

function aic_nearest_size(string $wanted, array $availableSizeLabels): ?string {
    if (!$availableSizeLabels) return null;
    foreach ($availableSizeLabels as $s) if (mb_strtolower($s) === mb_strtolower($wanted)) return $s; // exact, case-insensitive
    $wIdx = aic_size_index($wanted);
    if ($wIdx !== null) {
        $best = null; $bestDist = PHP_INT_MAX;
        foreach ($availableSizeLabels as $s) {
            $sIdx = aic_size_index($s);
            if ($sIdx === null) continue;
            $d = abs($sIdx - $wIdx);
            if ($d < $bestDist) { $bestDist = $d; $best = $s; }
        }
        if ($best !== null) return $best;
    }
    // fallback: closest alphabetically / by string similarity
    $best = null; $bestPct = 0;
    foreach ($availableSizeLabels as $s) { similar_text(mb_strtolower($wanted), mb_strtolower($s), $pct); if ($pct > $bestPct) { $bestPct = $pct; $best = $s; } }
    return $bestPct >= 40 ? $best : null;
}

/* All approved costings for a product, one per size (most recent version if
   several). Used by aic_estimate_new_size() to find bracketing reference
   sizes to interpolate between. */
function aic_get_approved_sizes_map(int $productId): array {
    $out = [];
    try {
        $st = db()->prepare("SELECT cv.id, cv.costing_no, ps.size_label FROM costing_versions cv
            JOIN costing_version_sizes cvs ON cvs.costing_version_id=cv.id
            JOIN product_sizes ps ON ps.id=cvs.product_size_id
            WHERE cv.product_id=? AND cv.status='approved' ORDER BY cv.id DESC");
        $st->execute([$productId]);
        foreach ($st->fetchAll() as $r) {
            if (isset($out[$r['size_label']])) continue; // most recent approved per size only
            $ls = db()->prepare("SELECT * FROM costing_lines WHERE costing_version_id=? ORDER BY sort_order,id");
            $ls->execute([$r['id']]);
            $out[$r['size_label']] = ['id' => (int)$r['id'], 'costing_no' => $r['costing_no'], 'lines' => $ls->fetchAll()];
        }
    } catch (Throwable $e) {}
    return $out;
}

/* Estimate a size with no approved costing of its own by linearly
   interpolating each shared item between the nearest approved smaller and
   larger size (brief section 17: "estimate King between Queen and Super
   King"). Falls back to copying the single nearest approved size if only
   one side of the bracket exists. Returns null if there's nothing to work
   from, or if the target size isn't a recognized size name (can't place it
   on the size order to interpolate against). Never invents a size the
   product has no approved reference for at all. */
function aic_estimate_new_size(int $productId, string $targetSize): ?array {
    $map = aic_get_approved_sizes_map($productId);
    if (!$map) return null;
    $targetIdx = aic_size_index($targetSize);
    if ($targetIdx === null) return null;
    if (isset($map[$targetSize])) return null; // an approved costing already exists — nothing to estimate

    $below = null; $belowIdx = -PHP_INT_MAX;
    $above = null; $aboveIdx = PHP_INT_MAX;
    foreach ($map as $label => $rowData) {
        $idx = aic_size_index($label);
        if ($idx === null) continue;
        if ($idx < $targetIdx && $idx > $belowIdx) { $belowIdx = $idx; $below = ['label' => $label] + $rowData; }
        if ($idx > $targetIdx && $idx < $aboveIdx) { $aboveIdx = $idx; $above = ['label' => $label] + $rowData; }
    }

    if ($below && $above) {
        $frac = ($targetIdx - $belowIdx) / ($aboveIdx - $belowIdx);
        $byItem = [];
        foreach ($below['lines'] as $l) $byItem[$l['item_name']]['below'] = $l;
        foreach ($above['lines'] as $l) $byItem[$l['item_name']]['above'] = $l;
        $lines = []; $total = 0.0;
        foreach ($byItem as $item => $pair) {
            if (!isset($pair['below'], $pair['above'])) continue; // only interpolate items present at both reference sizes
            $b = $pair['below']; $a = $pair['above'];
            $qty = (float)$b['quantity'] + ((float)$a['quantity'] - (float)$b['quantity']) * $frac;
            $rate = (float)$b['rate'] + ((float)$a['rate'] - (float)$b['rate']) * $frac;
            $shared = !empty($b['shared']) && !empty($a['shared']);
            $line = ['group' => $b['line_group'], 'item' => $item, 'quantity' => round($qty, 4), 'unit' => $b['unit'], 'rate' => round($rate, 4), 'shared' => $shared];
            $line['amount'] = aic_line_amount($line);
            $total += $line['amount'];
            $lines[] = $line;
        }
        if (!$lines) return null;
        return ['method' => 'interpolated', 'ref_smaller' => $below['label'] . ' (' . $below['costing_no'] . ')', 'ref_larger' => $above['label'] . ' (' . $above['costing_no'] . ')', 'lines' => $lines, 'total_cost' => round($total, 2)];
    }

    $only = $below ?: $above;
    if ($only) {
        $lines = array_map(fn($l) => ['group' => $l['line_group'], 'item' => $l['item_name'], 'quantity' => (float)$l['quantity'], 'unit' => $l['unit'], 'rate' => (float)$l['rate'], 'shared' => !empty($l['shared']), 'amount' => (float)$l['amount']], $only['lines']);
        $total = array_sum(array_column($lines, 'amount'));
        return ['method' => 'copied_from_nearest', 'reference' => $only['label'] . ' (' . $only['costing_no'] . ')', 'lines' => $lines, 'total_cost' => round($total, 2)];
    }
    return null;
}

/* Full detail (with lines) of the most recent approved costing for a product,
   optionally scoped to one size. Used for duplicate warnings + comparison. */
function aic_get_approved_costing(int $productId, ?string $sizeName = null): ?array {
    try {
        if ($sizeName !== null) {
            $st = db()->prepare("SELECT cv.* FROM costing_versions cv
                JOIN costing_version_sizes cvs ON cvs.costing_version_id=cv.id
                JOIN product_sizes ps ON ps.id=cvs.product_size_id
                WHERE cv.product_id=? AND cv.status='approved' AND ps.size_label=? ORDER BY cv.id DESC LIMIT 1");
            $st->execute([$productId, $sizeName]);
        } else {
            $st = db()->prepare("SELECT * FROM costing_versions WHERE product_id=? AND status='approved' ORDER BY id DESC LIMIT 1");
            $st->execute([$productId]);
        }
        $cv = $st->fetch();
        if (!$cv) return null;
        $ls = db()->prepare("SELECT * FROM costing_lines WHERE costing_version_id=? ORDER BY sort_order,id"); $ls->execute([$cv['id']]);
        $lines = $ls->fetchAll();
        $groupTotals = ['Fabric' => 0, 'Accessories' => 0, 'Packing' => 0, 'Workmanship' => 0, 'Other' => 0];
        foreach ($lines as $l) { $g = in_array($l['line_group'], AIC_GROUPS, true) ? $l['line_group'] : 'Other'; $groupTotals[$g] += (float)$l['amount']; }
        return ['id' => (int)$cv['id'], 'costing_no' => $cv['costing_no'], 'version_name' => $cv['version_name'], 'total_cost' => (float)$cv['total_cost'], 'group_totals' => array_map(fn($v) => round($v, 2), $groupTotals), 'lines' => $lines];
    } catch (Throwable $e) { return null; }
}

/* every size label already saved (any status) for a product, for nearest-size + duplicate checks */
function aic_product_sizes(int $productId): array {
    try {
        $st = db()->prepare("SELECT DISTINCT ps.size_label FROM product_sizes ps WHERE ps.product_id=?");
        $st->execute([$productId]);
        return array_column($st->fetchAll(), 'size_label');
    } catch (Throwable $e) { return []; }
}

/* Duplicate-costing warning: does this product already have ANY costing
   version (draft or approved) for this exact size? Applying never
   overwrites it, but the user should know before creating another. */
function aic_duplicate_check(int $productId, string $sizeName): ?array {
    try {
        $st = db()->prepare("SELECT cv.id, cv.costing_no, cv.status FROM costing_versions cv
            JOIN costing_version_sizes cvs ON cvs.costing_version_id=cv.id
            JOIN product_sizes ps ON ps.id=cvs.product_size_id
            WHERE cv.product_id=? AND ps.size_label=? ORDER BY cv.id DESC LIMIT 1");
        $st->execute([$productId, $sizeName]);
        $r = $st->fetch();
        return $r ?: null;
    } catch (Throwable $e) { return null; }
}

/* ===== PHASE 3 ===== */

/* Embedding-based similar-product search — reuses Product Master's own
   existing embedding infrastructure (includes/pm_search.php) rather than
   building a second one. Only called when the exact/LIKE SQL search (Phase
   1's aic_search_existing) finds nothing. */
function aic_similar_products(string $productName): array {
    if (trim($productName) === '' || !function_exists('pm_match')) return [];
    try {
        $m = pm_match($productName);
        if (!$m['candidates']) return [];
        return array_map(fn($c) => ['id' => $c['product_id'], 'name' => $c['product_name'], 'category' => $c['category'] ?? '', 'similarity' => $c['score']], $m['candidates']);
    } catch (Throwable $e) { return []; }
}

/* Missing-cost suggestion: for an item with no rate given, look at the most
   recent rate used for the SAME item name across approved costings
   (any product) and offer it as a labeled suggestion — never auto-filled. */
function aic_suggest_rate(string $itemName, array &$cache = []): ?array {
    if (array_key_exists($itemName, $cache)) return $cache[$itemName];
    try {
        $st = db()->prepare("SELECT cl.rate, cl.unit, cv.costing_no, p.name pname FROM costing_lines cl
            JOIN costing_versions cv ON cv.id=cl.costing_version_id AND cv.status='approved'
            JOIN products p ON p.id=cv.product_id
            WHERE cl.item_name=? ORDER BY cl.id DESC LIMIT 1");
        $st->execute([$itemName]);
        $r = $st->fetch();
        $out = $r ? ['rate' => (float)$r['rate'], 'unit' => $r['unit'], 'source' => $r['pname'] . ' (' . $r['costing_no'] . ')'] : null;
        return $cache[$itemName] = $out;
    } catch (Throwable $e) { return $cache[$itemName] = null; }
}

/* Anomaly detection: flag a line whose rate deviates from the recent
   average for the same item name by more than the SAME threshold AI Check
   already uses (config ai_thresholds.price_hist_pct) — fully deterministic,
   no AI call, same pattern as includes/ai_check_core.php's PRICE_HIST check. */
function aic_check_anomaly(string $itemName, float $rate, array &$avgCache = []): ?array {
    global $config;
    if ($rate <= 0) return null;
    $pct = (float)($config['ai_thresholds']['price_hist_pct'] ?? 0.30);
    if (!array_key_exists($itemName, $avgCache)) {
        try {
            $st = db()->prepare("SELECT AVG(cl.rate) avg_rate, COUNT(*) n FROM costing_lines cl
                JOIN costing_versions cv ON cv.id=cl.costing_version_id AND cv.status='approved'
                WHERE cl.item_name=?");
            $st->execute([$itemName]);
            $r = $st->fetch();
            $avgCache[$itemName] = ($r && (int)$r['n'] >= 2) ? ['avg' => (float)$r['avg_rate'], 'n' => (int)$r['n']] : null;
        } catch (Throwable $e) { $avgCache[$itemName] = null; }
    }
    $info = $avgCache[$itemName];
    if (!$info || $info['avg'] <= 0) return null;
    $dev = ($rate - $info['avg']) / $info['avg'];
    if (abs($dev) < $pct) return null;
    return ['item' => $itemName, 'rate' => $rate, 'avg_rate' => round($info['avg'], 2), 'deviation_pct' => round($dev * 100, 1), 'sample_size' => $info['n']];
}

/* On-demand explanation (brief section 12: only when the user explicitly asks).
   Takes ALREADY-CALCULATED PHP data — never a raw DB dump — and asks the
   model to explain/compare it in plain language. Separate, optional AI call. */
function aic_explain(array $context): array {
    global $config;
    $model = $config['ai_costing_model'] ?? 'gpt-4o-mini';
    $prompt = "You are a textile export costing analyst. In under 60 words, explain the following costing comparison/anomaly in plain language for a manager. Do not invent numbers not present in the data. Data:\n" . json_encode($context, JSON_UNESCAPED_UNICODE);
    $t0 = microtime(true);
    $resp = openai_request('chat/completions', ['model' => $model, 'messages' => [['role' => 'user', 'content' => $prompt]], 'max_tokens' => 150, 'temperature' => 0.3]);
    $ms = (int)round((microtime(true) - $t0) * 1000);
    $text = trim($resp['choices'][0]['message']['content'] ?? '');
    $inTok = (int)($resp['usage']['prompt_tokens'] ?? 0); $outTok = (int)($resp['usage']['completion_tokens'] ?? 0);
    $cost = round($inTok / 1e6 * (float)($config['ai_price_in_per_m'] ?? 0.15) + $outTok / 1e6 * (float)($config['ai_price_out_per_m'] ?? 0.60), 6);
    return ['text' => $text ?: 'No explanation returned.', 'usage' => ['model' => $model, 'input_tokens' => $inTok, 'output_tokens' => $outTok, 'estimated_cost' => $cost, 'response_time_ms' => $ms]];
}

/* ===== EXPANDED MODULE — item standardization, diffs, missing-cost checker, free-form Q&A ===== */

/* Suggestion only — never auto-applied. Returns null when nothing matches
   rather than guessing, per the brief ("must never invent"). */
function aic_standardize_item(string $rawName, array &$cache = []): ?array {
    $norm = mb_strtolower(trim(preg_replace('/[^a-z0-9]+/i', ' ', $rawName)));
    if ($norm === '') return null;
    if (array_key_exists($norm, $cache)) return $cache[$norm];
    try {
        $st = db()->prepare("SELECT standard_item, standard_group, standard_unit FROM costing_item_aliases WHERE alias_norm=?");
        $st->execute([$norm]);
        $r = $st->fetch();
        // don't suggest "renaming" an item to the exact name it already has
        if ($r && mb_strtolower(trim($rawName)) === mb_strtolower($r['standard_item'])) $r = false;
        $out = $r ? ['standard_item' => $r['standard_item'], 'group' => $r['standard_group'], 'unit' => $r['standard_unit']] : null;
        return $cache[$norm] = $out;
    } catch (Throwable $e) { return $cache[$norm] = null; }
}

/* Diff vs previous size (in the order the sizes were given) + a suggested
   selling price at the given margin. Pure math over already-calculated
   totals — no AI, no new numbers invented. */
function aic_add_diffs_and_pricing(array $calculated, float $marginPct, string $marginType): array {
    $prevCost = null;
    foreach ($calculated as &$sz) {
        if ($prevCost === null) { $sz['diff_prev'] = null; $sz['diff_prev_pct'] = null; }
        else {
            $d = $sz['total_cost'] - $prevCost;
            $sz['diff_prev'] = round($d, 2);
            $sz['diff_prev_pct'] = $prevCost > 0 ? round($d / $prevCost * 100, 1) : null;
        }
        $prevCost = $sz['total_cost'];
        $m = $marginPct / 100;
        $sz['suggested_price'] = $marginType === 'markup'
            ? round($sz['total_cost'] * (1 + $m), 2)
            : ($m < 1 ? round($sz['total_cost'] / (1 - $m), 2) : null);
        // confidence: mirrors the brief's High/Medium/Low bands using signals already computed
        if (!$sz['ok']) $sz['confidence'] = 'low';
        elseif ($sz['comparison'] !== null || ($sz['nearest_size'] === null && $sz['duplicate'] === null)) $sz['confidence'] = 'high';
        else $sz['confidence'] = 'medium';
    }
    unset($sz);
    return $calculated;
}

/* Missing-cost checker (brief section 22) — compares this draft's item set
   against what the SAME product's own approved costings usually include.
   Never auto-adds anything; only surfaces a suggestion. */
function aic_missing_cost_check(int $productId, array $commonLines): array {
    if (!$productId) return [];
    try {
        $st = db()->prepare("SELECT cl.item_name, COUNT(DISTINCT cl.costing_version_id) n FROM costing_lines cl
            JOIN costing_versions cv ON cv.id=cl.costing_version_id AND cv.status='approved'
            WHERE cv.product_id=? GROUP BY cl.item_name");
        $st->execute([$productId]);
        $rows = $st->fetchAll();
        if (!$rows) return [];
        $totalSt = db()->prepare("SELECT COUNT(*) FROM costing_versions WHERE product_id=? AND status='approved'");
        $totalSt->execute([$productId]);
        $totalApproved = (int)$totalSt->fetchColumn();
        if ($totalApproved < 2) return []; // not enough history to call anything "usual"
        $draftItems = array_map(fn($l) => mb_strtolower(trim($l['item'])), $commonLines);
        $missing = [];
        foreach ($rows as $r) {
            $freq = (int)$r['n'];
            if ($freq / $totalApproved < 0.5) continue; // "usual" = in at least half of approved costings
            if (in_array(mb_strtolower(trim($r['item_name'])), $draftItems, true)) continue;
            $missing[] = ['item' => $r['item_name'], 'seen_in' => $freq, 'of' => $totalApproved];
        }
        usort($missing, fn($a, $b) => $b['seen_in'] <=> $a['seen_in']);
        return array_slice($missing, 0, 5);
    } catch (Throwable $e) { return []; }
}

/* "Ask AI About This Costing" — free-form question, same rule as Explain:
   only the already-calculated PHP data goes in, never a raw DB dump, only
   fires when the user actually asks. */
function aic_ask(string $question, array $context): array {
    global $config;
    $model = $config['ai_costing_model'] ?? 'gpt-4o-mini';
    $q = mb_substr(trim($question), 0, 400);
    $prompt = "You are a textile export costing analyst answering a manager's question about a costing draft. Answer ONLY using the numbers in the data below — never invent a number that isn't there; if the data doesn't contain what's needed to answer, say so plainly. Keep it under 80 words.\n\nQUESTION: {$q}\n\nDATA:\n" . json_encode($context, JSON_UNESCAPED_UNICODE);
    $t0 = microtime(true);
    $resp = openai_request('chat/completions', ['model' => $model, 'messages' => [['role' => 'user', 'content' => $prompt]], 'max_tokens' => 200, 'temperature' => 0.3]);
    $ms = (int)round((microtime(true) - $t0) * 1000);
    $text = trim($resp['choices'][0]['message']['content'] ?? '');
    $inTok = (int)($resp['usage']['prompt_tokens'] ?? 0); $outTok = (int)($resp['usage']['completion_tokens'] ?? 0);
    $cost = round($inTok / 1e6 * (float)($config['ai_price_in_per_m'] ?? 0.15) + $outTok / 1e6 * (float)($config['ai_price_out_per_m'] ?? 0.60), 6);
    return ['text' => $text ?: 'No answer returned.', 'usage' => ['model' => $model, 'input_tokens' => $inTok, 'output_tokens' => $outTok, 'estimated_cost' => $cost, 'response_time_ms' => $ms]];
}

/* ===== GAP CLOSE 1: general version-to-version comparison (brief section 21).
   Works on any two costing_versions (draft/approved/converted/archived, same
   product or different, any currency) — not just draft-vs-approved-reference. ===== */
/* Full-sheet version compare — product info, sizes, every line (materials,
   workmanship/labour, packing, other charges), consumption, rates, total
   cost and suggested/final price. Pure SQL + math, no AI call — the AI
   summary is a separate, cached, on-demand layer (aic_get_or_build_ai_summary
   below) built FROM this already-computed diff, never a raw DB dump. */
function aic_compare_versions(int $idA, int $idB): ?array {
    try {
        $stA = db()->prepare("SELECT cv.*, p.name pname FROM costing_versions cv JOIN products p ON p.id=cv.product_id WHERE cv.id=?");
        $stA->execute([$idA]); $a = $stA->fetch();
        $stB = db()->prepare("SELECT cv.*, p.name pname FROM costing_versions cv JOIN products p ON p.id=cv.product_id WHERE cv.id=?");
        $stB->execute([$idB]); $b = $stB->fetch();
        if (!$a || !$b) return null;

        $szSt = db()->prepare("SELECT ps.size_label FROM costing_version_sizes cvs JOIN product_sizes ps ON ps.id=cvs.product_size_id WHERE cvs.costing_version_id=?");
        $szSt->execute([$idA]); $sizesA = array_column($szSt->fetchAll(), 'size_label');
        $szSt->execute([$idB]); $sizesB = array_column($szSt->fetchAll(), 'size_label');

        $laA = db()->prepare("SELECT * FROM costing_lines WHERE costing_version_id=? ORDER BY sort_order,id"); $laA->execute([$idA]); $linesA = $laA->fetchAll();
        $laB = db()->prepare("SELECT * FROM costing_lines WHERE costing_version_id=? ORDER BY sort_order,id"); $laB->execute([$idB]); $linesB = $laB->fetchAll();
        $byItemA = []; foreach ($linesA as $l) $byItemA[$l['item_name']] = $l;
        $byItemB = []; foreach ($linesB as $l) $byItemB[$l['item_name']] = $l;

        $added = []; $removed = []; $changed = []; $unchanged = [];
        foreach ($byItemB as $item => $lb) {
            if (!isset($byItemA[$item])) { $added[] = ['item' => $item, 'group' => $lb['line_group'], 'unit' => $lb['unit'], 'qty' => (float)$lb['quantity'], 'rate' => (float)$lb['rate'], 'amount' => (float)$lb['amount']]; continue; }
            $la = $byItemA[$item];
            $qtyChanged = abs((float)$la['quantity'] - (float)$lb['quantity']) > 0.0001;
            $rateChanged = abs((float)$la['rate'] - (float)$lb['rate']) > 0.0001;
            $unitChanged = trim((string)$la['unit']) !== trim((string)$lb['unit']);
            if ($qtyChanged || $rateChanged || $unitChanged) {
                $flags = [];
                if ($rateChanged && (float)$la['rate'] > 0) {
                    $rpct = ((float)$lb['rate'] - (float)$la['rate']) / (float)$la['rate'] * 100;
                    if (abs($rpct) >= 10) $flags[] = ['type' => 'rate', 'text' => 'Rate ' . ($rpct >= 0 ? '↑' : '↓') . ' ' . abs(round($rpct, 1)) . '%'];
                }
                if ($qtyChanged && (float)$la['quantity'] > 0) {
                    $ratio = (float)$lb['quantity'] / (float)$la['quantity'];
                    if ($ratio >= 8 && $ratio <= 12) $flags[] = ['type' => 'qty_decimal', 'text' => 'Possible decimal slip — check consumption'];
                    elseif ($ratio >= 1.5 || $ratio <= 0.6) $flags[] = ['type' => 'qty', 'text' => 'Consumption ' . ($ratio >= 1 ? '↑' : '↓') . ' ' . abs(round(($ratio - 1) * 100)) . '%'];
                }
                $changed[] = ['item' => $item, 'group' => $lb['line_group'],
                    'old_qty' => (float)$la['quantity'], 'new_qty' => (float)$lb['quantity'], 'old_unit' => $la['unit'], 'new_unit' => $lb['unit'],
                    'old_rate' => (float)$la['rate'], 'new_rate' => (float)$lb['rate'],
                    'old_amount' => (float)$la['amount'], 'new_amount' => (float)$lb['amount'], 'amount_diff' => round((float)$lb['amount'] - (float)$la['amount'], 2),
                    'flags' => $flags];
            } else {
                $unchanged[] = $item;
            }
        }
        foreach ($byItemA as $item => $la) if (!isset($byItemB[$item])) $removed[] = ['item' => $item, 'group' => $la['line_group'], 'unit' => $la['unit'], 'qty' => (float)$la['quantity'], 'rate' => (float)$la['rate'], 'amount' => (float)$la['amount']];
        usort($changed, fn($x, $y) => abs($y['amount_diff']) <=> abs($x['amount_diff']));

        // possible duplicate / renamed line: an added item whose group matches
        // a removed item's group and total amount is close (within 15%) —
        // shown as a suggestion only, never merged automatically.
        foreach ($added as &$ad) {
            foreach ($removed as $rm) {
                if ($rm['group'] !== $ad['group']) continue;
                $base = max($rm['amount'], 0.01);
                if (abs($ad['amount'] - $rm['amount']) / $base <= 0.15) { $ad['possible_rename_of'] = $rm['item']; break; }
            }
        }
        unset($ad);

        $issueCount = count($changed ? array_filter($changed, fn($c) => !empty($c['flags'])) : []) + count($removed) + count(array_filter($added, fn($a) => !empty($a['possible_rename_of'])));

        $totalDiff = (float)$b['total_cost'] - (float)$a['total_cost'];
        $groupTotals = function (array $lines) {
            $g = ['Fabric' => 0, 'Accessories' => 0, 'Packing' => 0, 'Workmanship' => 0, 'Other' => 0];
            foreach ($lines as $l) $g[$l['line_group']] = ($g[$l['line_group']] ?? 0) + (float)$l['amount'];
            return array_map(fn($v) => round($v, 2), $g);
        };

        return [
            'a' => ['id' => (int)$a['id'], 'costing_no' => $a['costing_no'], 'version_name' => $a['version_name'], 'status' => $a['status'], 'product_name' => $a['pname'], 'total_cost' => (float)$a['total_cost'], 'suggested_price' => (float)$a['suggested_price'], 'currency' => $a['currency'], 'sizes' => $sizesA, 'group_totals' => $groupTotals($linesA)],
            'b' => ['id' => (int)$b['id'], 'costing_no' => $b['costing_no'], 'version_name' => $b['version_name'], 'status' => $b['status'], 'product_name' => $b['pname'], 'total_cost' => (float)$b['total_cost'], 'suggested_price' => (float)$b['suggested_price'], 'currency' => $b['currency'], 'sizes' => $sizesB, 'group_totals' => $groupTotals($linesB)],
            'total_diff' => round($totalDiff, 2),
            'total_diff_pct' => (float)$a['total_cost'] > 0 ? round($totalDiff / (float)$a['total_cost'] * 100, 1) : null,
            'added' => $added, 'removed' => $removed, 'changed' => $changed, 'unchanged_count' => count($unchanged),
            'issue_count' => $issueCount,
        ];
    } catch (Throwable $e) { return null; }
}

/* Side-by-side compare for 2 TO 6 costing versions at once — e.g. every size
   of a product in one table, not just two at a time. Same deterministic
   SQL + math as aic_compare_versions() above (kept separate/untouched so the
   existing 2-way path isn't put at risk), just generalised to N columns
   instead of an A/B diff. */
function aic_compare_multi(array $ids): ?array {
    $ids = array_values(array_unique(array_map('intval', $ids)));
    if (count($ids) < 2 || count($ids) > 6) return null;
    try {
        $in = implode(',', array_fill(0, count($ids), '?'));
        $st = db()->prepare("SELECT cv.*, p.name pname FROM costing_versions cv JOIN products p ON p.id=cv.product_id WHERE cv.id IN ($in)");
        $st->execute($ids);
        $versions = [];
        foreach ($st->fetchAll() as $v) { $versions[(int)$v['id']] = $v; }
        if (count($versions) !== count($ids)) return null; // one or more not found

        $szSt = db()->prepare("SELECT ps.size_label FROM costing_version_sizes cvs JOIN product_sizes ps ON ps.id=cvs.product_size_id WHERE cvs.costing_version_id=?");
        $lnSt = db()->prepare("SELECT * FROM costing_lines WHERE costing_version_id=? ORDER BY sort_order,id");
        $byItem = []; // item => [group, values: [vid => line]]
        $cols = [];
        foreach ($ids as $vid) {
            $v = $versions[$vid];
            $szSt->execute([$vid]); $sizes = array_column($szSt->fetchAll(), 'size_label');
            $cols[] = ['id' => $vid, 'costing_no' => $v['costing_no'], 'version_name' => $v['version_name'], 'status' => $v['status'],
                'product_name' => $v['pname'], 'currency' => $v['currency'], 'total_cost' => (float)$v['total_cost'],
                'suggested_price' => (float)$v['suggested_price'], 'sizes' => $sizes];
            $lnSt->execute([$vid]);
            foreach ($lnSt->fetchAll() as $l) {
                $item = $l['item_name'];
                if (!isset($byItem[$item])) $byItem[$item] = ['group' => $l['line_group'], 'values' => []];
                $byItem[$item]['values'][$vid] = ['qty' => (float)$l['quantity'], 'unit' => $l['unit'], 'rate' => (float)$l['rate'], 'amount' => (float)$l['amount']];
            }
        }

        $rows = []; $issueCount = 0;
        foreach ($byItem as $item => $row) {
            $present = array_keys($row['values']);
            $missing = array_diff($ids, $present);
            $flags = [];
            if ($missing) {
                $missNames = array_map(fn($mid) => $versions[$mid]['version_name'], $missing);
                $flags[] = ['type' => 'missing', 'text' => 'Missing in ' . implode(', ', $missNames)];
                $issueCount++;
            }
            if (count($present) >= 2) {
                $qtys = array_map(fn($v) => $v['qty'], $row['values']);
                sort($qtys);
                $mid = $qtys[(int)floor((count($qtys) - 1) / 2)]; // median, robust to one outlier
                if ($mid > 0) {
                    foreach ($row['values'] as $vid => $val) {
                        $ratio = $val['qty'] / $mid;
                        if ($ratio >= 8 && $ratio <= 12) { $flags[] = ['type' => 'qty_decimal', 'text' => $versions[$vid]['version_name'] . ': possible decimal slip']; $issueCount++; }
                        elseif ($ratio >= 1.6 || ($ratio > 0 && $ratio <= 0.6)) { $flags[] = ['type' => 'qty', 'text' => $versions[$vid]['version_name'] . ': consumption ' . ($ratio >= 1 ? '↑' : '↓') . ' unusual vs others']; $issueCount++; }
                    }
                }
                $rates = array_map(fn($v) => $v['rate'], $row['values']);
                $rmed = $rates; sort($rmed); $rmed = $rmed[(int)floor((count($rmed) - 1) / 2)];
                if ($rmed > 0) {
                    foreach ($row['values'] as $vid => $val) {
                        $rpct = ($val['rate'] - $rmed) / $rmed * 100;
                        if (abs($rpct) >= 10) { $flags[] = ['type' => 'rate', 'text' => $versions[$vid]['version_name'] . ': rate ' . ($rpct >= 0 ? '↑' : '↓') . ' ' . abs(round($rpct, 1)) . '% vs others']; $issueCount++; }
                    }
                }
            }
            $rows[] = ['item' => $item, 'group' => $row['group'], 'values' => $row['values'], 'flags' => $flags];
        }
        usort($rows, fn($a, $b) => strcmp($a['group'] . $a['item'], $b['group'] . $b['item']));

        return ['columns' => $cols, 'rows' => $rows, 'issue_count' => $issueCount];
    } catch (Throwable $e) { return null; }
}

/* AI-written short summary of a multi-version compare (aic_compare_multi
   above) — capped to the top 10 flagged rows, never the full line list.
   Cached by a hash of (version ids + their total_costs) so re-opening the
   same set of versions never re-bills unless membership or a total_cost
   actually changed. */
function aic_get_or_build_multi_ai_summary(array $cmp, bool $forceRefresh = false): array {
    global $config;
    $ids = array_column($cmp['columns'], 'id');
    sort($ids);
    $totals = array_map(fn($c) => round($c['total_cost'], 2), $cmp['columns']);
    $key = md5(implode(',', $ids) . '|' . implode(',', $totals));

    if (!$forceRefresh) {
        try {
            $st = db()->prepare("SELECT summary_text FROM ai_costing_compare_multi_cache WHERE cache_key=?");
            $st->execute([$key]);
            $cached = $st->fetchColumn();
            if ($cached !== false && trim((string)$cached) !== '') return ['text' => $cached, 'cached' => true, 'usage' => null];
        } catch (Throwable $e) {}
    }

    $flaggedRows = array_values(array_filter($cmp['rows'], fn($r) => !empty($r['flags'])));
    usort($flaggedRows, fn($a, $b) => count($b['flags']) <=> count($a['flags']));
    $payload = [
        'product' => $cmp['columns'][0]['product_name'] ?? '',
        'versions' => array_map(fn($c) => ['name' => $c['version_name'], 'sizes' => $c['sizes'], 'status' => $c['status'], 'total_cost' => $c['total_cost'], 'currency' => $c['currency']], $cmp['columns']),
        'flagged_lines' => array_slice($flaggedRows, 0, 10),
        'issue_count' => $cmp['issue_count'],
    ];

    $model = $config['ai_costing_model'] ?? 'gpt-4o-mini';
    $prompt = "You are a textile export costing analyst. Summarise this multi-size/multi-version costing comparison for a manager who will approve or reject it. "
        . "Use EXACTLY this structure and nothing else — short, practical, no long explanations:\n\n"
        . "Overall Result: <one sentence on how the sizes/versions compare in total cost.>\n"
        . "Main Reasons:\n1. <reason>\n2. <reason>\n(up to 5 numbered reasons, only ones that actually matter — unusual consumption for a size, rate differences, missing items)\n"
        . "Suggested Action: <one short practical sentence.>\n\n"
        . "Never invent a number that is not in the data below. If nothing looks unusual, say so plainly.\n\nDATA:\n" . json_encode($payload, JSON_UNESCAPED_UNICODE);

    $t0 = microtime(true);
    $resp = openai_request('chat/completions', ['model' => $model, 'messages' => [['role' => 'user', 'content' => $prompt]], 'max_tokens' => 350, 'temperature' => 0.2]);
    $ms = (int)round((microtime(true) - $t0) * 1000);
    $text = trim($resp['choices'][0]['message']['content'] ?? '');
    $inTok = (int)($resp['usage']['prompt_tokens'] ?? 0); $outTok = (int)($resp['usage']['completion_tokens'] ?? 0);
    $cost = round($inTok / 1e6 * (float)($config['ai_price_in_per_m'] ?? 0.15) + $outTok / 1e6 * (float)($config['ai_price_out_per_m'] ?? 0.60), 6);

    if ($text !== '') {
        try {
            db()->prepare("INSERT INTO ai_costing_compare_multi_cache (cache_key, version_ids, summary_text, model) VALUES (?,?,?,?)
                ON DUPLICATE KEY UPDATE version_ids=VALUES(version_ids), summary_text=VALUES(summary_text), model=VALUES(model), created_at=NOW()")
                ->execute([$key, implode(',', $ids), $text, $model]);
        } catch (Throwable $e) {}
    }
    return ['text' => $text ?: 'No summary returned.', 'cached' => false, 'usage' => ['model' => $model, 'input_tokens' => $inTok, 'output_tokens' => $outTok, 'estimated_cost' => $cost, 'response_time_ms' => $ms]];
}

/* AI-written short summary of an ALREADY-COMPUTED comparison (never a raw DB
   dump — only the diff fields above, capped to the biggest changes, go into
   the prompt). Cached per (version_a_id, version_b_id, both totals); a
   version being resaved after the cache was written naturally invalidates it
   since total_cost will differ. $forceRefresh bypasses the cache. */
function aic_get_or_build_ai_summary(array $cmp, bool $forceRefresh = false): array {
    global $config;
    $vidA = $cmp['a']['id']; $vidB = $cmp['b']['id'];
    $totalA = round($cmp['a']['total_cost'], 2); $totalB = round($cmp['b']['total_cost'], 2);

    if (!$forceRefresh) {
        try {
            $st = db()->prepare("SELECT summary_text FROM ai_costing_compare_cache WHERE version_a_id=? AND version_b_id=? AND total_cost_a=? AND total_cost_b=?");
            $st->execute([$vidA, $vidB, $totalA, $totalB]);
            $cached = $st->fetchColumn();
            if ($cached !== false && trim((string)$cached) !== '') {
                return ['text' => $cached, 'cached' => true, 'usage' => null];
            }
        } catch (Throwable $e) {}
    }

    // cap what goes to the model: top 8 changed lines by |amount_diff|, plus
    // added/removed — never the whole line list, never unrelated costings.
    $topChanged = array_slice($cmp['changed'], 0, 8);
    $payload = [
        'product' => $cmp['a']['product_name'],
        'version_a' => ['name' => $cmp['a']['version_name'], 'status' => $cmp['a']['status'], 'total_cost' => $totalA, 'currency' => $cmp['a']['currency']],
        'version_b' => ['name' => $cmp['b']['version_name'], 'status' => $cmp['b']['status'], 'total_cost' => $totalB, 'currency' => $cmp['b']['currency']],
        'total_diff' => $cmp['total_diff'], 'total_diff_pct' => $cmp['total_diff_pct'],
        'changed_lines' => $topChanged, 'added_lines' => $cmp['added'], 'removed_lines' => $cmp['removed'],
    ];

    $model = $config['ai_costing_model'] ?? 'gpt-4o-mini';
    $prompt = "You are a textile export costing analyst. Summarise this costing version comparison for a manager who will approve or reject it. "
        . "Use EXACTLY this structure and nothing else — short, practical, no long explanations:\n\n"
        . "Overall Result: <Cost increased/decreased by X%.>\n"
        . "Main Reasons:\n1. <reason>\n2. <reason>\n(up to 5 numbered reasons, only the ones that actually matter — rate changes, unusual consumption, missing/added items, possible duplicates)\n"
        . "Suggested Action: <one short practical sentence.>\n\n"
        . "Never invent a number that is not in the data below. If nothing changed meaningfully, say so plainly.\n\nDATA:\n" . json_encode($payload, JSON_UNESCAPED_UNICODE);

    $t0 = microtime(true);
    $resp = openai_request('chat/completions', ['model' => $model, 'messages' => [['role' => 'user', 'content' => $prompt]], 'max_tokens' => 350, 'temperature' => 0.2]);
    $ms = (int)round((microtime(true) - $t0) * 1000);
    $text = trim($resp['choices'][0]['message']['content'] ?? '');
    $inTok = (int)($resp['usage']['prompt_tokens'] ?? 0); $outTok = (int)($resp['usage']['completion_tokens'] ?? 0);
    $cost = round($inTok / 1e6 * (float)($config['ai_price_in_per_m'] ?? 0.15) + $outTok / 1e6 * (float)($config['ai_price_out_per_m'] ?? 0.60), 6);

    if ($text !== '') {
        try {
            db()->prepare("INSERT INTO ai_costing_compare_cache (version_a_id,version_b_id,total_cost_a,total_cost_b,summary_text,model) VALUES (?,?,?,?,?,?)
                ON DUPLICATE KEY UPDATE total_cost_a=VALUES(total_cost_a), total_cost_b=VALUES(total_cost_b), summary_text=VALUES(summary_text), model=VALUES(model), created_at=NOW()")
                ->execute([$vidA, $vidB, $totalA, $totalB, $text, $model]);
        } catch (Throwable $e) {}
    }
    return ['text' => $text ?: 'No summary returned.', 'cached' => false, 'usage' => ['model' => $model, 'input_tokens' => $inTok, 'output_tokens' => $outTok, 'estimated_cost' => $cost, 'response_time_ms' => $ms]];
}

/* ===== GAP CLOSE 2: unusual size progression (brief section 24 — "fabric
   consumption lower for a larger size"). Deterministic, no AI: for every
   item that appears at more than one size with a recognized position in
   AIC_SIZE_ORDER, flags any case where a larger size uses LESS of it than
   a smaller size. ===== */
function aic_check_size_progression(array $calculated): array {
    $warnings = [];
    $byItem = [];
    foreach ($calculated as $sz) {
        $idx = aic_size_index($sz['size_name']);
        if ($idx === null) continue;
        foreach ($sz['lines'] as $l) $byItem[$l['item']][] = ['idx' => $idx, 'size' => $sz['size_name'], 'qty' => (float)$l['quantity']];
    }
    foreach ($byItem as $item => $points) {
        if (count($points) < 2) continue;
        usort($points, fn($a, $b) => $a['idx'] <=> $b['idx']);
        for ($i = 1; $i < count($points); $i++) {
            $prev = $points[$i - 1]; $cur = $points[$i];
            if ($cur['qty'] < $prev['qty'] - 0.0001) {
                $warnings[] = "\"$item\": {$cur['size']} (" . rtrim(rtrim(number_format($cur['qty'], 3), '0'), '.') . ') uses less than ' . $prev['size'] . ' (' . rtrim(rtrim(number_format($prev['qty'], 3), '0'), '.') . ') — check consumption for the larger size.';
            }
        }
    }
    return $warnings;
}

/* ============================================================
   Costing semantic search (embeddings) — feeds search.php.
   Mirrors includes/pm_search.php's proven pattern: build structured text
   (header + every costing line), embed it, skip re-embedding when nothing
   changed (content hash). Lets search reason over real line-item detail
   — Group / Item / Description / Qty / Unit / Alloc. Wt (kg) / Rate —
   by meaning, not just literal text match.
   ============================================================ */
function aic_embed_schema(): void {
    try { db()->exec("CREATE TABLE IF NOT EXISTS costing_embeddings (
        id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
        costing_version_id INT NOT NULL,
        embedding_model VARCHAR(80) NOT NULL,
        embedding_vector LONGTEXT NOT NULL,
        embedded_text MEDIUMTEXT NOT NULL,
        content_hash CHAR(64) NOT NULL,
        token_count INT NULL,
        estimated_cost DECIMAL(14,8) NULL,
        embedded_at DATETIME NULL,
        UNIQUE KEY uniq_version (costing_version_id, embedding_model),
        KEY idx_hash (content_hash)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4"); } catch (Throwable $e) {}
}

/* Natural-sentence description of one costing version — deliberately NOT a
   rigid "LABEL: value" template. An earlier version used fixed field labels
   ("RECORD TYPE:", "COST LINES (Group / Item / ...)") repeated identically
   on every single record; because that boilerplate was identical across
   ALL costings, it dominated the embedding and made unrelated products
   (e.g. "Baby Set" vs "Comforter") look artificially similar. Writing it as
   plain sentences instead lets the actual product name and materials carry
   the meaning, which is what search similarity should be based on. Still
   carries the same full detail (every line's group/item/qty/rate/weight),
   just phrased naturally instead of a formatted table. */
function aic_build_embed_text(int $versionId): ?string {
    $v = db()->prepare("SELECT cv.*, p.name AS product_name FROM costing_versions cv JOIN products p ON p.id = cv.product_id WHERE cv.id=?");
    $v->execute([$versionId]); $v = $v->fetch();
    if (!$v) return null;

    $sz = db()->prepare("SELECT ps.size_label FROM costing_version_sizes cvs JOIN product_sizes ps ON ps.id=cvs.product_size_id WHERE cvs.costing_version_id=?");
    $sz->execute([$versionId]); $sizes = array_column($sz->fetchAll(), 'size_label');

    $fmtQty = fn($n) => rtrim(rtrim(number_format((float)$n, 3), '0'), '.');

    $intro = $v['product_name'];
    if ($sizes) $intro .= ' (size ' . implode(', ', $sizes) . ')';
    $intro .= ' — costing "' . $v['version_name'] . '", reference ' . $v['costing_no'] . ', currently ' . ($v['status'] === 'locked' ? 'locked' : 'a draft') . '.';
    $intro .= ' Total cost ' . $v['currency'] . ' ' . number_format((float)$v['total_cost'], 2) . '.';
    if ((float)$v['suggested_price'] > 0) $intro .= ' Suggested selling price ' . $v['currency'] . ' ' . number_format((float)$v['suggested_price'], 2) . '.';
    if (!empty($v['remarks'])) $intro .= ' Note: ' . $v['remarks'];

    $ls = db()->prepare("SELECT * FROM costing_lines WHERE costing_version_id=? ORDER BY sort_order,id");
    $ls->execute([$versionId]);
    $lineSentences = [];
    foreach ($ls->fetchAll() as $l) {
        $item = $l['item_name'] ?: $l['line_group'];
        $s = $item . ', a ' . $l['line_group'] . ' item';
        if ($l['description']) $s .= ' (' . $l['description'] . ')';
        $s .= ', quantity ' . $fmtQty($l['quantity']) . ' ' . ($l['unit'] ?: 'pcs')
            . ', allocated weight ' . $fmtQty($l['weight_kg']) . ' kg'
            . ', rate ' . number_format((float)$l['rate'], 4)
            . ', amounting to ' . number_format((float)$l['amount'], 2)
            . (!empty($l['shared']) ? ' (shared across sizes)' : '') . '.';
        $lineSentences[] = $s;
    }

    $text = $intro;
    if ($lineSentences) $text .= "\n\nMaterials and cost lines: " . implode(' ', $lineSentences);
    return $text;
}

function aic_embed_hash(string $text, string $model): string { return hash('sha256', $model . '|' . $text); }

/* create/update one costing version's embedding. Content-hash skip means
   re-saving a costing whose text didn't actually change never re-bills —
   same cost-control habit as everywhere else in this app. */
function aic_embed_version(int $versionId): array {
    global $config;
    aic_embed_schema();
    $model = $config['embedding_model'] ?? 'text-embedding-3-small';
    $text = aic_build_embed_text($versionId);
    if ($text === null) return ['status' => 'failed', 'error' => 'version not found'];
    $hash = aic_embed_hash($text, $model);

    $ex = db()->prepare("SELECT content_hash FROM costing_embeddings WHERE costing_version_id=? AND embedding_model=?");
    $ex->execute([$versionId, $model]);
    if ($ex->fetchColumn() === $hash) return ['status' => 'current', 'skipped' => true];

    try {
        $r = create_embedding_ex($text, $model, 0);
        $vec = $r['vector']; $tok = $r['tokens'];
        if (!$vec) throw new Exception('empty vector');
        $cost = round($tok / 1e6 * (float)($config['embed_price_per_m'] ?? 0.02), 8);
        db()->prepare("REPLACE INTO costing_embeddings (costing_version_id,embedding_model,embedding_vector,embedded_text,content_hash,token_count,estimated_cost,embedded_at) VALUES (?,?,?,?,?,?,?,NOW())")
            ->execute([$versionId, $model, json_encode($vec), $text, $hash, $tok, $cost]);
        return ['status' => 'current', 'tokens' => $tok, 'cost' => $cost, 'skipped' => false];
    } catch (Throwable $e) {
        return ['status' => 'failed', 'error' => $e->getMessage()];
    }
}

/* which costing versions still need embedding for the CURRENT model — missing
   entirely, or their text changed since the last embed (same content-hash
   check aic_embed_version() itself uses, just run across all versions so the
   "Embed All" button knows what to queue without billing anything). */
function aic_pending_ids(): array {
    global $config;
    aic_embed_schema();
    $model = $config['embedding_model'] ?? 'text-embedding-3-small';
    $out = [];
    $ids = db()->query("SELECT id FROM costing_versions")->fetchAll(PDO::FETCH_COLUMN);
    $hashes = db()->prepare("SELECT content_hash FROM costing_embeddings WHERE costing_version_id=? AND embedding_model=?");
    foreach ($ids as $vid) {
        $text = aic_build_embed_text((int)$vid);
        if ($text === null) continue;
        $hashes->execute([$vid, $model]);
        $stored = $hashes->fetchColumn();
        if ($stored !== aic_embed_hash($text, $model)) $out[] = (int)$vid;
    }
    return $out;
}

/* rank a set of [key => ['text'=>..., 'vector'=>array]] rows against a query
   vector by cosine similarity, descending. Shared by search.php for both
   shipment and costing retrieval so the ranking logic lives in one place. */
function aic_rank_by_similarity(array $rows, array $queryVector, int $limit, float $minScore = 0.32): array {
    $scored = [];
    foreach ($rows as $key => $row) {
        $vec = $row['vector'];
        if (!is_array($vec) || !$vec) continue;
        $sim = cosine_similarity($queryVector, $vec);
        if ($sim < $minScore) continue;
        $scored[] = ['key' => $key, 'score' => $sim, 'text' => $row['text']];
    }
    usort($scored, fn($a, $b) => $b['score'] <=> $a['score']);
    return array_slice($scored, 0, $limit);
}
