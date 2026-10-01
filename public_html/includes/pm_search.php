<?php
/*
  Product Master semantic search service (reusable).
  - Builds structured embedding text per product variant.
  - Content-hash skip so unchanged records are never re-embedded (cost control).
  - Stores vectors in product_embeddings; tracks tokens + estimated cost.
  - match(): exact code → confirmed alias → normalized → embedding retrieval,
             returns candidates with a hybrid score. NEVER selects cost by
             similarity alone — caller confirms group/size, then loads live cost.
  Uses the existing OpenAI config + includes/openai.php.
*/
require_once __DIR__ . '/openai.php';

function pm_schema(): void {
    try { db()->exec("CREATE TABLE IF NOT EXISTS product_embeddings (
        id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
        entity_type VARCHAR(40) NOT NULL DEFAULT 'variant',
        entity_id INT NOT NULL,
        embedding_model VARCHAR(80) NOT NULL,
        embedding_dimensions INT NOT NULL,
        embedding_vector LONGTEXT NOT NULL,
        embedded_text MEDIUMTEXT NOT NULL,
        content_hash CHAR(64) NOT NULL,
        status VARCHAR(20) NOT NULL DEFAULT 'current',
        token_count INT NULL,
        estimated_cost DECIMAL(14,8) NULL,
        error_message TEXT NULL,
        embedded_at DATETIME NULL,
        updated_at DATETIME NULL,
        UNIQUE KEY uniq_entity (entity_type, entity_id, embedding_model, embedding_dimensions),
        KEY idx_hash (content_hash), KEY idx_status (status)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4"); } catch (Throwable $e) {}
    try { db()->exec("CREATE TABLE IF NOT EXISTS customer_product_mappings (
        id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
        buyer_name VARCHAR(200) NULL,
        customer_product_name VARCHAR(255) NOT NULL,
        normalized_text VARCHAR(500) NOT NULL,
        product_id INT NOT NULL,
        confirmed_by INT NULL, confirmed_at DATETIME NULL, last_used_at DATETIME NULL,
        created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
        KEY idx_norm (normalized_text), KEY idx_buyer (buyer_name)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4"); } catch (Throwable $e) {}
}

function pm_norm(string $s): string { return strtolower(trim(preg_replace('/[^a-z0-9]+/i',' ', $s))); }

/* structured semantic text for one product row (array from products table) */
function pm_build_text(array $p): string {
    $L = [];
    $L[] = "RECORD TYPE: Product Master Variant";
    $L[] = "PRODUCT NAME: ".($p['name'] ?? '');
    if (!empty($p['description'])) $L[] = "DESCRIPTION: ".$p['description'];
    if (!empty($p['category']))   $L[] = "CATEGORY: ".$p['category'];
    if (!empty($p['default_unit']))$L[] = "UNIT: ".$p['default_unit'];
    $L[] = "STATUS: ".(!empty($p['is_active']) ? 'Active' : 'Inactive');
    return implode("\n", $L);
}

function pm_hash(string $text, string $model, int $dims): string { return hash('sha256', $model.'|'.$dims.'|'.$text); }

/* the model+dims actually stored (most current rows); falls back to config */
function pm_active_model(): array {
    global $config;
    try {
        $r = db()->query("SELECT embedding_model, embedding_dimensions, COUNT(*) n FROM product_embeddings WHERE status='current' GROUP BY embedding_model, embedding_dimensions ORDER BY n DESC LIMIT 1")->fetch();
        if ($r) return [$r['embedding_model'], (int)$r['embedding_dimensions']];
    } catch (Throwable $e) {}
    return [$config['pm_embedding_model'] ?? 'text-embedding-3-large', (int)($config['pm_embedding_dimensions'] ?? 1024)];
}

/* create/update one product's embedding. Returns [status, tokens, cost, skipped]. */
function pm_embed_product(int $pid): array {
    global $config;
    pm_schema();
    $model = $config['pm_embedding_model'] ?? 'text-embedding-3-large';
    $dims  = (int)($config['pm_embedding_dimensions'] ?? 1024);
    $st = db()->prepare("SELECT * FROM products WHERE id=?"); $st->execute([$pid]); $p = $st->fetch();
    if (!$p) return ['status'=>'failed','error'=>'product not found'];
    $text = pm_build_text($p);
    $hash = pm_hash($text, $model, $dims);

    $ex = db()->prepare("SELECT content_hash FROM product_embeddings WHERE entity_type='variant' AND entity_id=? AND embedding_model=? AND embedding_dimensions=?");
    $ex->execute([$pid,$model,$dims]); $curHash = $ex->fetchColumn();
    if ($curHash === $hash) return ['status'=>'current','tokens'=>0,'cost'=>0,'skipped'=>true];

    try {
        $r = create_embedding_ex($text, $model, $dims);
        $vec = $r['vector']; $tok = $r['tokens'];
        if (!$vec) throw new Exception('empty vector');
        $cost = round($tok/1e6 * (float)($config['pm_embed_price_per_m'] ?? 0.13), 8);
        db()->prepare("REPLACE INTO product_embeddings (entity_type,entity_id,embedding_model,embedding_dimensions,embedding_vector,embedded_text,content_hash,status,token_count,estimated_cost,embedded_at,updated_at) VALUES ('variant',?,?,?,?,?,?, 'current',?,?,NOW(),NOW())")
          ->execute([$pid,$model,$dims,json_encode($vec),$text,$hash,$tok,$cost]);
        return ['status'=>'current','tokens'=>$tok,'cost'=>$cost,'skipped'=>false,'model'=>$model];
    } catch (Throwable $e) {
        // fallback: retry with the basic embedding model (no custom dimensions) that search already uses
        $fbModel = $config['embedding_model'] ?? 'text-embedding-3-small';
        if ($fbModel !== $model) {
            try {
                $r = create_embedding_ex($text, $fbModel, 0);
                $vec = $r['vector']; $tok = $r['tokens'];
                if ($vec) {
                    $fbDims = count($vec);
                    $fbHash = pm_hash($text, $fbModel, $fbDims);
                    $cost = round($tok/1e6 * (float)($config['pm_embed_price_per_m'] ?? 0.13), 8);
                    db()->prepare("REPLACE INTO product_embeddings (entity_type,entity_id,embedding_model,embedding_dimensions,embedding_vector,embedded_text,content_hash,status,token_count,estimated_cost,embedded_at,updated_at) VALUES ('variant',?,?,?,?,?,?, 'current',?,?,NOW(),NOW())")
                      ->execute([$pid,$fbModel,$fbDims,json_encode($vec),$text,$fbHash,$tok,$cost]);
                    return ['status'=>'current','tokens'=>$tok,'cost'=>$cost,'skipped'=>false,'model'=>$fbModel,'fallback'=>true];
                }
            } catch (Throwable $e2) { $e = $e2; }
        }
        try { db()->prepare("REPLACE INTO product_embeddings (entity_type,entity_id,embedding_model,embedding_dimensions,embedding_vector,embedded_text,content_hash,status,error_message,updated_at) VALUES ('variant',?,?,?,?,?,?, 'failed',?,NOW())")
          ->execute([$pid,$model,$dims,'[]',$text,$hash,substr($e->getMessage(),0,240)]); } catch (Throwable $e3) {}
        return ['status'=>'failed','error'=>$e->getMessage()];
    }
}

/* which products still need embedding (missing or outdated by hash) */
function pm_pending_ids(): array {
    global $config; pm_schema();
    $out = [];
    $rows = db()->query("SELECT * FROM products")->fetchAll();
    $emb = db()->prepare("SELECT embedding_model, embedding_dimensions, content_hash, status FROM product_embeddings WHERE entity_type='variant' AND entity_id=? ORDER BY (status='current') DESC LIMIT 1");
    foreach ($rows as $p) {
        $emb->execute([$p['id']]); $row = $emb->fetch();
        if (!$row || $row['status']!=='current') { $out[] = (int)$p['id']; continue; }
        $hash = pm_hash(pm_build_text($p), $row['embedding_model'], (int)$row['embedding_dimensions']);
        if ($row['content_hash'] !== $hash) $out[] = (int)$p['id'];
    }
    return $out;
}

function pm_status(int $pid): array {
    global $config; pm_schema();
    $st = db()->prepare("SELECT * FROM products WHERE id=?"); $st->execute([$pid]); $p = $st->fetch();
    if (!$p) return ['status'=>'none'];
    // find any embedding row for this product (whatever model landed after fallback)
    $e = db()->prepare("SELECT * FROM product_embeddings WHERE entity_type='variant' AND entity_id=? ORDER BY (status='current') DESC, updated_at DESC LIMIT 1");
    $e->execute([$pid]); $row = $e->fetch();
    if (!$row) return ['status'=>'none'];
    if ($row['status']==='failed') return ['status'=>'failed','model'=>$row['embedding_model'],'dims'=>(int)$row['embedding_dimensions'],'error'=>$row['error_message']];
    $hash = pm_hash(pm_build_text($p), $row['embedding_model'], (int)$row['embedding_dimensions']);
    return ['status'=>($row['content_hash']===$hash?'current':'outdated'),'model'=>$row['embedding_model'],'dims'=>(int)$row['embedding_dimensions'],'updated'=>$row['embedded_at']];
}

/*
  match a free-text invoice product against Product Master.
  $name, $desc, $buyerName. Returns:
  ['method','product_id','product_name','score','candidates'=>[...], 'needs_confirm'=>bool]
*/
function pm_match(string $name, string $desc = '', string $buyerName = ''): array {
    global $config; pm_schema();
    $norm = pm_norm($name.' '.$desc);
    // pm_save_alias() stores normalized_text from the product name ALONE
    // (never the description — Description/Colour never decides which
    // product a line costs against, same rule as everywhere else in this
    // app). The alias lookup below must match on that same name-only text,
    // not $norm (name+description combined) — otherwise a just-confirmed
    // alias silently never matches again on any line that has a
    // Description/Colour value, and the auto-guess keeps overriding it.
    $nameOnly = pm_norm($name);

    // 1. confirmed customer alias (highest priority)
    try {
        $q = db()->prepare("SELECT * FROM customer_product_mappings WHERE normalized_text=? ".($buyerName!==''?"AND (buyer_name=? OR buyer_name IS NULL)":"")." ORDER BY (buyer_name IS NOT NULL) DESC, last_used_at DESC LIMIT 1");
        $q->execute($buyerName!=='' ? [$nameOnly,$buyerName] : [$nameOnly]);
        if ($m = $q->fetch()) {
            db()->prepare("UPDATE customer_product_mappings SET last_used_at=NOW() WHERE id=?")->execute([$m['id']]);
            $pn = db()->prepare("SELECT name FROM products WHERE id=?"); $pn->execute([$m['product_id']]);
            return ['method'=>'confirmed_alias','product_id'=>(int)$m['product_id'],'product_name'=>$pn->fetchColumn(),'score'=>1.0,'candidates'=>[],'needs_confirm'=>false];
        }
    } catch (Throwable $e) {}

    // 2. exact normalized name
    $rows = db()->query("SELECT id,name,category,is_active,size FROM products")->fetchAll();
    foreach ($rows as $p) if (pm_norm($p['name'])===pm_norm($name))
        return ['method'=>'exact_name','product_id'=>(int)$p['id'],'product_name'=>$p['name'],'score'=>1.0,'candidates'=>[],'needs_confirm'=>false];

    // 3. embedding retrieval (only if embeddings exist)
    list($model, $dims) = pm_active_model();
    $emb = db()->prepare("SELECT entity_id, embedding_vector FROM product_embeddings WHERE entity_type='variant' AND embedding_model=? AND embedding_dimensions=? AND status='current'");
    $emb->execute([$model,$dims]); $vecs = $emb->fetchAll();
    if ($vecs) {
        try {
            $qtext = "RECORD TYPE: Invoice Product\nPRODUCT: {$name}\nDESCRIPTION: {$desc}";
            $qr = create_embedding_ex($qtext, $model, $dims); $qv = $qr['vector'];
            if ($qv) {
                $byId = []; foreach ($rows as $p) $byId[(int)$p['id']] = $p;
                $scored = [];
                foreach ($vecs as $v) {
                    $vec = json_decode($v['embedding_vector'], true); if (!is_array($vec)) continue;
                    $sim = cosine_similarity($qv, $vec);
                    $pid = (int)$v['entity_id']; $p = $byId[$pid] ?? null; if (!$p) continue;
                    // small deterministic boost when tokens overlap
                    $tokBoost = 0; foreach (explode(' ',pm_norm($name)) as $w) if ($w!=='' && strpos(pm_norm($p['name']),$w)!==false) $tokBoost += 0.05;
                    $final = min(1, $sim*0.85 + min(0.15,$tokBoost));
                    $scored[] = ['product_id'=>$pid,'product_name'=>$p['name'],'category'=>$p['category'],'is_active'=>(int)$p['is_active'],'size'=>$p['size'],'similarity'=>round($sim,4),'score'=>round($final,4)];
                }
                usort($scored, fn($a,$b)=>$b['score']<=>$a['score']);
                $top = array_slice($scored, 0, 3);
                if ($top) {
                    $best = $top[0];
                    $needsConfirm = $best['score'] < (float)($config['pm_match_good'] ?? 0.82);
                    return ['method'=>'embedding','product_id'=>$needsConfirm?0:$best['product_id'],'product_name'=>$needsConfirm?'':$best['product_name'],'score'=>$best['score'],'candidates'=>$top,'needs_confirm'=>$needsConfirm || $best['score'] < (float)($config['pm_match_high'] ?? 0.90)];
                }
            }
        } catch (Throwable $e) {}
    }

    // 4. fallback: local similar_text
    $best=''; $bestPid=0; $bestScore=0;
    foreach ($rows as $p) {
        $k = pm_norm($p['name']);
        if (strpos($k,$norm)!==false || strpos($norm,$k)!==false) $sc=0.85; else { similar_text($norm,$k,$pct); $sc=$pct/100; }
        if ($sc>$bestScore){ $bestScore=$sc; $best=$p['name']; $bestPid=(int)$p['id']; }
    }
    if ($bestScore >= 0.55) return ['method'=>'fuzzy_local','product_id'=>0,'product_name'=>'','score'=>round($bestScore,3),'candidates'=>[['product_id'=>$bestPid,'product_name'=>$best,'score'=>round($bestScore,3),'similarity'=>round($bestScore,3)]],'needs_confirm'=>true];
    return ['method'=>'none','product_id'=>0,'product_name'=>'','score'=>0,'candidates'=>[],'needs_confirm'=>false];
}

/* save a confirmed customer→product mapping (called after user confirms a candidate) */
function pm_save_alias(string $custName, int $pid, string $buyerName = '', int $userId = 0): void {
    pm_schema();
    db()->prepare("INSERT INTO customer_product_mappings (buyer_name,customer_product_name,normalized_text,product_id,confirmed_by,confirmed_at,last_used_at) VALUES (?,?,?,?,?,NOW(),NOW())")
      ->execute([$buyerName ?: null, $custName, pm_norm($custName), $pid, $userId ?: null]);
}
