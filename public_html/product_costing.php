<?php
require_once __DIR__ . '/includes/bootstrap.php';
require_once __DIR__ . '/includes/costing.php';
require_once __DIR__ . '/includes/openai.php';
require_once __DIR__ . '/includes/ai_costing.php';
require_once __DIR__ . '/includes/production.php';
// Optional: only present once the Store & Inventory module is installed.
// Without it this page still works, just with no Item Master list to pick from.
if (is_file(__DIR__ . '/includes/inventory.php')) require_once __DIR__ . '/includes/inventory.php';
require_login();
if (is_staff() || is_production_staff()) { http_response_code(403); exit('Staff cannot access product costing.'); }
require_costing('view');

function pc_ensure_schema(): void {
    try { db()->exec("CREATE TABLE IF NOT EXISTS costing_versions (
        id INT AUTO_INCREMENT PRIMARY KEY, product_id INT NOT NULL, version_name VARCHAR(160) NOT NULL,
        currency VARCHAR(8) NULL DEFAULT 'PKR', total_cost DECIMAL(16,2) NOT NULL DEFAULT 0,
        net_weight DECIMAL(12,3) NOT NULL DEFAULT 0, packing_weight DECIMAL(12,3) NOT NULL DEFAULT 0,
        gross_weight DECIMAL(12,3) NOT NULL DEFAULT 0, created_by INT NULL, created_at DATETIME DEFAULT CURRENT_TIMESTAMP, INDEX(product_id)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4"); } catch (Throwable $e) {}
    try { db()->exec("CREATE TABLE IF NOT EXISTS costing_version_sizes (costing_version_id INT NOT NULL, product_size_id INT NOT NULL, PRIMARY KEY (costing_version_id, product_size_id), INDEX(product_size_id)) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4"); } catch (Throwable $e) {}
    try { db()->exec("CREATE TABLE IF NOT EXISTS costing_lines (id INT AUTO_INCREMENT PRIMARY KEY, costing_version_id INT NOT NULL, line_group VARCHAR(40) NOT NULL, item_name VARCHAR(160) NULL, description VARCHAR(500) NULL, quantity DECIMAL(14,3) NOT NULL DEFAULT 0, unit VARCHAR(40) NULL, weight_kg DECIMAL(14,3) NOT NULL DEFAULT 0, rate DECIMAL(16,4) NOT NULL DEFAULT 0, amount DECIMAL(16,2) NOT NULL DEFAULT 0, sort_order INT NOT NULL DEFAULT 0, INDEX(costing_version_id)) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4"); } catch (Throwable $e) {}
    try { db()->exec("CREATE TABLE IF NOT EXISTS product_sizes (id INT AUTO_INCREMENT PRIMARY KEY, product_id INT NOT NULL, size_label VARCHAR(80) NOT NULL, net_weight DECIMAL(12,3) NULL DEFAULT 0, gross_weight DECIMAL(12,3) NULL DEFAULT 0, fabric_consumption DECIMAL(12,3) NULL DEFAULT 0, filling_weight DECIMAL(12,3) NULL DEFAULT 0, pack_qty DECIMAL(12,3) NULL DEFAULT 0, prev_price DECIMAL(14,4) NULL DEFAULT 0, cost_price DECIMAL(14,4) NULL DEFAULT 0, created_at DATETIME DEFAULT CURRENT_TIMESTAMP, INDEX(product_id)) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4"); } catch (Throwable $e) {}
}
pc_ensure_schema();
costing_ensure_schema();
production_ensure_schema();

/* sum of ACTIVE Production Operations rates for a product — this is what
   Workmanship now auto-derives from, instead of a manually typed cost line. */
function pc_workmanship_rate(int $productId): float {
    if ($productId <= 0) return 0.0;
    $st = db()->prepare("SELECT COALESCE(SUM(rate),0) FROM production_operations WHERE product_id=? AND is_active=1");
    $st->execute([$productId]);
    return (float)$st->fetchColumn();
}

/* size-aware version: for a product with no Components and no size-scoped
   operation rates, this is identical to pc_workmanship_rate() above — flat
   sum of active operation rates, zero behaviour change. Two independent
   things can now vary by size, applied together:
     1. Rate itself — an operation row priced specifically for the version's
        size (production_operations.product_size_id) wins over its "All
        Sizes" rate, when the version is tied to exactly one size.
     2. Quantity — for a component with a Components/Set-Quantities entry,
        that resolved rate is then multiplied by the component's
        quantity-per-set for the version's size (e.g. Double = 2x Pillow
        Case), same as before.
   Zero or several sizes on the version falls back to each component's "All
   Sizes" rate only (no size-scoped row can apply — which one would be
   ambiguous), same as the original flat-sum fallback. */
function pc_workmanship_rate_for_version(int $productId, array $sizeIds = []): float {
    if ($productId <= 0) return 0.0;
    $rateMap = production_operation_rate_map($productId);
    if (!$rateMap) return 0.0;

    $singleSizeId = count($sizeIds) === 1 ? (int)$sizeIds[0] : null;
    $sizeLabel = null;
    if ($singleSizeId !== null) {
        $st2 = db()->prepare("SELECT size_label FROM product_sizes WHERE id=? AND product_id=?");
        $st2->execute([$singleSizeId, $productId]);
        $lbl = $st2->fetchColumn();
        if ($lbl !== false) $sizeLabel = $lbl;
    }

    $total = 0.0;
    foreach (array_keys($rateMap) as $comp) {
        $rate = production_operation_rate_for_size($rateMap, $comp, $singleSizeId);
        if ($comp === '' || $sizeLabel === null) { $total += $rate; continue; }
        $total += $rate * production_component_qty_per_set($productId, $sizeLabel, $comp);
    }
    return $total;
}
try { db()->exec("ALTER TABLE costing_lines ADD COLUMN shared TINYINT(1) NOT NULL DEFAULT 0"); } catch (Throwable $e) {}
try { db()->exec("ALTER TABLE costing_versions ADD COLUMN ai_generated TINYINT(1) NOT NULL DEFAULT 0"); } catch (Throwable $e) {}
/* Which Item Master item this cost line actually is. NULL for every line
   saved before this existed — those keep working exactly as they did, on
   their typed name alone. Additive, nullable, no default change. */
try { db()->exec("ALTER TABLE costing_lines ADD COLUMN material_id INT NULL DEFAULT NULL"); } catch (Throwable $e) {}
try { db()->exec("ALTER TABLE costing_lines ADD INDEX idx_material (material_id)"); } catch (Throwable $e) {}

function pc_num($v): float { return is_numeric($v) ? (float)$v : 0.0; }
function pc_txt($v,$m=255): string { return mb_substr(trim((string)$v),0,$m); }

/* The Item Master list this page offers in the Item column. Guarded on
   purpose: if the Store & Inventory module is not installed the query
   fails, the list is empty, and every line falls back to plain typing —
   this page then behaves exactly as it did before. */
function pc_inv_items(): array {
    static $cache = null;
    if ($cache !== null) return $cache;
    $cache = [];
    try {
        foreach (db()->query("SELECT id,code,name,item_group,uom,std_rate FROM inv_materials WHERE is_active=1 ORDER BY item_group,name")->fetchAll() as $r) {
            $cache[] = ['id'=>(int)$r['id'],'code'=>$r['code'],'name'=>$r['name'],'group'=>$r['item_group'],'uom'=>$r['uom'],'rate'=>(float)$r['std_rate']];
        }
    } catch (Throwable $e) { $cache = []; }
    return $cache;
}
function pc_inv_map(): array {
    static $m = null;
    if ($m !== null) return $m;
    $m = []; foreach (pc_inv_items() as $i) $m[$i['id']] = $i;
    return $m;
}
function pc_can_create_item(): bool {
    return is_admin() || (function_exists('inv_perm') && inv_perm('master'));
}

/* Legacy fallback, kept for lines that are still only a typed name:
   anything containing "fabric" is Fabric, everything else Accessories.
   This is why fabrics used to land in Accessories and why Packing could
   never be produced here at all — it is now the LAST resort, not the rule. */
function pc_auto_group(string $itemName): string {
    return stripos(trim($itemName), 'fabric') !== false ? 'Fabric' : 'Accessories';
}

/* A line's Group, in order of how much the source can be trusted:
     1. the Item Master item it is linked to  — the master file decides
     2. the costing_item_aliases dictionary   — already in your app
     3. the old name guess                    — legacy lines only
   The row editor still has no Group picker: the item carries its group,
   so there is nothing to set twice and nothing to disagree about. */
function pc_group_for(string $itemName, int $materialId = 0): string {
    if ($materialId > 0) {
        $m = pc_inv_map();
        if (isset($m[$materialId])) return $m[$materialId]['group'];
    }
    try {
        $norm = mb_strtolower(trim(preg_replace('/[^a-z0-9]+/i', ' ', $itemName)));
        if ($norm !== '') {
            $st = db()->prepare("SELECT standard_group FROM costing_item_aliases WHERE alias_norm=?");
            $st->execute([$norm]);
            $g = $st->fetchColumn();
            // the seeded alias dictionary still says Packing for poly bags
            // and cartons; inv_group_norm() folds that into Accessories
            if ($g && in_array($g, ['Fabric','Accessories','Packing'], true)) {
                return function_exists('inv_group_norm') ? inv_group_norm($g) : ($g === 'Packing' ? 'Accessories' : $g);
            }
        }
    } catch (Throwable $e) {}
    return pc_auto_group($itemName);
}
$STATUSES = ['draft'=>'Draft','approved'=>'Approved','converted'=>'Converted to PI','archived'=>'Archived'];

/* ---------- AJAX save (JSON) ---------- */
if ($_SERVER['REQUEST_METHOD']==='POST' && ($_SERVER['HTTP_X_REQUESTED_WITH'] ?? '')==='costing') {
    header('Content-Type: application/json');
    $data = json_decode(file_get_contents('php://input') ?: '', true);
    if (!is_array($data)) { echo json_encode(['success'=>false,'message'=>'Invalid request.']); exit; }
    $tok = $data['_csrf'] ?? '';
    if (!$tok || !hash_equals($_SESSION['_csrf'] ?? '', $tok)) { echo json_encode(['success'=>false,'message'=>'Session expired, please reload.']); exit; }

    /* instant delete of one saved version (no reload) */
    if (($data['op'] ?? '')==='delete_version') {
        if (!costing_perm('delete')) { echo json_encode(['success'=>false,'message'=>'No permission to delete costings.']); exit; }
        $vid=(int)($data['version_id'] ?? 0);
        try {
            db()->prepare("DELETE FROM costing_lines WHERE costing_version_id=?")->execute([$vid]);
            db()->prepare("DELETE FROM costing_version_sizes WHERE costing_version_id=?")->execute([$vid]);
            db()->prepare("DELETE FROM costing_versions WHERE id=?")->execute([$vid]);
            try { audit_log(0,'Product Costing','delete_version',$vid,'','Costing version deleted'); } catch(Throwable $e){}
            echo json_encode(['success'=>true,'message'=>'Costing version deleted.']);
        } catch(Throwable $e){ echo json_encode(['success'=>false,'message'=>$e->getMessage()]); }
        exit;
    }

    /* Adds one item to the Item Master from inside the costing sheet, so a
       new material never forces you to leave a quote half-finished. It
       writes exactly what Item Master's own form writes — same table, same
       code rule (group prefix + next free number, never reused) — and only
       for someone who is allowed to maintain the Item Master anyway. */
    if (($data['op'] ?? '')==='create_item') {
        if (!pc_can_create_item()) { echo json_encode(['success'=>false,'message'=>'You do not have permission to add items to the Item Master. Ask an admin to add it.']); exit; }
        $name  = pc_txt($data['name'] ?? '',190);
        $groups = function_exists('inv_item_groups') ? inv_item_groups() : ['Fabric','Accessories','Other'];
        $group = in_array($data['group'] ?? '', $groups, true) ? $data['group'] : 'Other';
        $uom   = strtoupper(pc_txt($data['uom'] ?? '',20)); if ($uom==='') $uom='PCS';
        $rate  = pc_num($data['rate'] ?? 0);
        if ($name==='') { echo json_encode(['success'=>false,'message'=>'Type the item name first.']); exit; }
        try {
            $dup = db()->prepare("SELECT id,code,name,item_group,uom,std_rate FROM inv_materials WHERE LOWER(name)=LOWER(?) LIMIT 1");
            $dup->execute([$name]);
            if ($ex = $dup->fetch()) {
                echo json_encode(['success'=>true,'existing'=>true,'message'=>'"'.$ex['name'].'" is already in the Item Master — linked to it.',
                    'item'=>['id'=>(int)$ex['id'],'code'=>$ex['code'],'name'=>$ex['name'],'group'=>$ex['item_group'],'uom'=>$ex['uom'],'rate'=>(float)$ex['std_rate']]]);
                exit;
            }
            $prefixes = ['Fabric'=>'FB','Accessories'=>'AC','Other'=>'OT'];
            $p = $prefixes[$group] ?? 'OT'; $n = 0;
            foreach (db()->query("SELECT code FROM inv_materials WHERE code LIKE '$p-%'")->fetchAll() as $r) {
                $tail = (int)substr((string)$r['code'], strlen($p)+1);
                if ($tail > $n) $n = $tail;
            }
            $code = $p.'-'.str_pad((string)($n+1),4,'0',STR_PAD_LEFT);
            db()->prepare("INSERT INTO inv_materials (code,name,item_group,uom,std_rate,created_by) VALUES (?,?,?,?,?,?)")
                ->execute([$code,$name,$group,$uom,$rate,(int)(current_user()['id'] ?? 0)]);
            $id = (int)db()->lastInsertId();
            try { audit_log(0,'Inventory','item_create',$code,$name.' ('.$group.')','Item added from Product Costing'); } catch(Throwable $e){}
            echo json_encode(['success'=>true,'message'=>'Added to Item Master as '.$code.'.',
                'item'=>['id'=>$id,'code'=>$code,'name'=>$name,'group'=>$group,'uom'=>$uom,'rate'=>$rate]]);
        } catch(Throwable $e){ echo json_encode(['success'=>false,'message'=>'Could not add the item: '.$e->getMessage()]); }
        exit;
    }

    /* creates the Product Master record an AI-generated draft needs to live
       under, when Generate Draft was used before any product was selected
       and found no confident existing match. Minimal fields only — same as
       a blank product_master.php entry; nothing here is AI-decided, it's
       just the name/currency the user already typed and the AI extracted. */
    if (($data['op'] ?? '')==='create_product_from_ai') {
        if (!costing_perm('create') && !costing_perm('edit')) { echo json_encode(['success'=>false,'message'=>'No permission to create products.']); exit; }
        $name = pc_txt($data['name'] ?? '', 200);
        if ($name === '') { echo json_encode(['success'=>false,'message'=>'Product name is required.']); exit; }
        $curc = pc_txt($data['currency'] ?? 'PKR', 8) ?: 'PKR';
        try {
            db()->prepare("INSERT INTO products (name,category,default_unit,currency,is_active,created_by,updated_by) VALUES (?,?,?,?,1,?,?)")
                ->execute([$name, '', 'Pcs', $curc, current_user()['id'], current_user()['id']]);
            $newId = (int)db()->lastInsertId();
            cache_bump('products');
            try { audit_log(0,'Product Master','create',$name,'','Created from AI-Assisted Costing draft'); } catch (Throwable $e) {}
            echo json_encode(['success'=>true,'product_id'=>$newId]);
        } catch (Throwable $e) { echo json_encode(['success'=>false,'message'=>$e->getMessage()]); }
        exit;
    }
    if (!costing_perm('create') && !costing_perm('edit')) { echo json_encode(['success'=>false,'message'=>'No permission to save costings.']); exit; }

    $pid = (int)($data['product_id'] ?? 0);
    $st = db()->prepare("SELECT * FROM products WHERE id=?"); $st->execute([$pid]); $prod = $st->fetch();
    if (!$prod) { echo json_encode(['success'=>false,'message'=>'Select a valid product first.']); exit; }

    $sizes = is_array($data['product_sizes'] ?? null) ? $data['product_sizes'] : [];
    $versions = is_array($data['costing_versions'] ?? null) ? $data['costing_versions'] : [];
    if (!$versions) { echo json_encode(['success'=>false,'message'=>'Add at least one costing version.']); exit; }

    try {
        db()->beginTransaction();

        /* sizes: reuse BY ID first, then by label (non-destructive)
         *
         * temp_id carries the real product_sizes.id for a size that already
         * exists (buildPayload sends s.id). Matching on the label alone meant a
         * size RENAMED elsewhere — in Product Master — was no longer found by
         * an already-open Costing tab, and a SECOND size row was inserted:
         * "King" and "King Size" on one product, each with its own quantities.
         * The id is the identity; the label is only a fallback for a size the
         * browser has never had an id for. A recognised id also picks up the
         * rename, so the two screens converge instead of diverging. */
        $existing = db()->prepare("SELECT id,size_label FROM product_sizes WHERE product_id=?"); $existing->execute([$pid]);
        $byLabel = []; $byId = [];
        foreach ($existing->fetchAll() as $r) {
            $byLabel[mb_strtolower($r['size_label'])] = (int)$r['id'];
            $byId[(int)$r['id']] = $r['size_label'];
        }
        $insSize = db()->prepare("INSERT INTO product_sizes (product_id,size_label) VALUES (?,?)");
        $renSize = db()->prepare("UPDATE product_sizes SET size_label=? WHERE id=? AND product_id=?");
        $tempToDb = [];
        foreach ($sizes as $s) {
            $label = pc_txt($s['size_name'] ?? '', 80); if ($label==='') continue;
            $key  = mb_strtolower($label);
            $sent = (int)($s['temp_id'] ?? 0);          // a real id, or a negative/0 placeholder
            if ($sent > 0 && isset($byId[$sent])) {
                $sid = $sent;                            // this size, whatever it is called now
                if (mb_strtolower($byId[$sent]) !== $key && !isset($byLabel[$key])) {
                    $renSize->execute([$label, $sid, $pid]);   // a genuine rename, not a clash
                    unset($byLabel[mb_strtolower($byId[$sent])]);
                    $byId[$sent] = $label;
                }
                $byLabel[$key] = $sid;
            } elseif (isset($byLabel[$key])) {
                $sid = $byLabel[$key];
            } else {
                $insSize->execute([$pid,$label]); $sid=(int)db()->lastInsertId();
                $byLabel[$key]=$sid; $byId[$sid]=$label;
            }
            $tempToDb[(string)($s['temp_id'] ?? $label)] = $sid;
        }

        /* upsert versions (stable ids). delete versions removed in UI. */
        $submittedIds = array_values(array_filter(array_map(fn($v)=>(int)($v['id'] ?? 0), $versions), fn($x)=>$x>0));
        $cur = db()->prepare("SELECT id FROM costing_versions WHERE product_id=?"); $cur->execute([$pid]);
        $dbIds = array_map('intval', array_column($cur->fetchAll(),'id'));
        $toDelete = array_diff($dbIds, $submittedIds);
        foreach ($toDelete as $did) {
            db()->prepare("DELETE FROM costing_lines WHERE costing_version_id=?")->execute([$did]);
            db()->prepare("DELETE FROM costing_version_sizes WHERE costing_version_id=?")->execute([$did]);
            db()->prepare("DELETE FROM costing_versions WHERE id=?")->execute([$did]);
        }

        $aiGeneratedNames = [];
        $savedVersionIds = [];
        foreach ($versions as $vi=>$v) {
            $vid = (int)($v['id'] ?? 0);
            $vname = pc_txt($v['name'] ?? '', 160) ?: ('Costing Version '.($vi+1));
            $curc = pc_txt($v['currency'] ?? 'PKR', 8) ?: 'PKR';
            $status = in_array(($v['status'] ?? 'draft'), array_keys($STATUSES), true) ? $v['status'] : 'draft';
            $remarks = pc_txt($v['remarks'] ?? '', 1000);
            $sugg = pc_num($v['suggested_price'] ?? 0);
            $aiGen = !empty($v['ai_generated']) ? 1 : 0;
            $lines = is_array($v['cost_lines'] ?? null) ? $v['cost_lines'] : [];
            $tc=0;$net=0;$pack=0;
            // must use the SAME group rule the lines are stored with, a few
            // lines below — otherwise the saved net/packing weights would be
            // split by one rule and the printed lines grouped by another
            foreach ($lines as $l){ $mid0=(int)($l['material_id'] ?? 0); $g=pc_group_for((string)($l['item'] ?? ''), $mid0); $q=pc_num($l['qty'] ?? 0); $r=pc_num($l['rate'] ?? 0); $w=pc_num($l['weight'] ?? 0); $sh=!empty($l['shared']); $aw=$sh ? ($q>0?$w/$q:$w) : ($w*$q); $tc+=($sh ? ($q>0?$r/$q:0) : $q*$r); if($g==='Fabric'||$g==='Accessories')$net+=$aw; if($g==='Packing')$pack+=$aw; }
            $versionSizeIds = [];
            foreach (array_unique($v['applies_to_size_ids'] ?? []) as $t){ $sid=$tempToDb[(string)$t] ?? null; if($sid) $versionSizeIds[] = $sid; }
            $workmanshipRate = pc_workmanship_rate_for_version($pid, $versionSizeIds);
            $tc += $workmanshipRate;

            if ($vid>0) {
                db()->prepare("UPDATE costing_versions SET version_name=?,currency=?,status=?,remarks=?,suggested_price=?,total_cost=?,net_weight=?,packing_weight=?,gross_weight=?,ai_generated=? WHERE id=? AND product_id=?")
                    ->execute([$vname,$curc,$status,$remarks,$sugg,round($tc,2),round($net,3),round($pack,3),round($net+$pack,3),$aiGen,$vid,$pid]);
            } else {
                db()->prepare("INSERT INTO costing_versions (product_id,version_name,currency,status,remarks,suggested_price,total_cost,net_weight,packing_weight,gross_weight,ai_generated,created_by) VALUES (?,?,?,?,?,?,?,?,?,?,?,?)")
                    ->execute([$pid,$vname,$curc,$status,$remarks,$sugg,round($tc,2),round($net,3),round($pack,3),round($net+$pack,3),$aiGen,current_user()['id']]);
                $vid=(int)db()->lastInsertId();
                db()->prepare("UPDATE costing_versions SET costing_no=? WHERE id=?")->execute(['CST-'.$pid.'-'.$vid,$vid]);
            }
            if ($aiGen) $aiGeneratedNames[] = $vname.' (CST-'.$pid.'-'.$vid.')';
            $savedVersionIds[] = $vid;

            db()->prepare("DELETE FROM costing_lines WHERE costing_version_id=?")->execute([$vid]);
            db()->prepare("DELETE FROM costing_version_sizes WHERE costing_version_id=?")->execute([$vid]);
            $insVS = db()->prepare("INSERT INTO costing_version_sizes (costing_version_id,product_size_id) VALUES (?,?)");
            foreach ($versionSizeIds as $sid){ try{$insVS->execute([$vid,$sid]);}catch(Throwable $e){} }
            $insL = db()->prepare("INSERT INTO costing_lines (costing_version_id,line_group,item_name,description,quantity,unit,weight_kg,rate,amount,sort_order,shared,material_id) VALUES (?,?,?,?,?,?,?,?,?,?,?,?)");
            $invMap = pc_inv_map();
            foreach ($lines as $li=>$l){
                // an id the browser sends is only trusted if it is a real,
                // active Item Master item; anything else stores as unlinked
                $mid = (int)($l['material_id'] ?? 0);
                if ($mid > 0 && !isset($invMap[$mid])) $mid = 0;
                $name = pc_txt($l['item'] ?? '',160);
                $g=pc_group_for($name,$mid); $q=pc_num($l['qty'] ?? 0); $r=pc_num($l['rate'] ?? 0); $sh=!empty($l['shared'])?1:0; $amt=$sh ? ($q>0?round($r/$q,2):0) : round($q*$r,2);
                $insL->execute([$vid,$g,$name,pc_txt($l['desc'] ?? '',500),$q,pc_txt($l['unit'] ?? '',40),pc_num($l['weight'] ?? 0),$r,$amt,$li+1,$sh,$mid>0?$mid:null]);
            }
            // Workmanship is no longer a manual line — save it as a real (but
            // auto-generated) cost line each time, so printed costing sheets
            // still itemize it exactly like before. Rate changes only affect
            // versions saved after the change, same rule as Production Operations.
            if ($workmanshipRate > 0) {
                $insL->execute([$vid,'Workmanship','Workmanship (Production Operations)','Auto — sum of active operation rates',1,'Pc',0,$workmanshipRate,round($workmanshipRate,2),count($lines)+1,0,null]);
            }
        }

        try { audit_log(0,'Product Costing','save',$prod['name'],count($versions).' version(s)','Costing saved'); } catch (Throwable $e) {}
        // dedicated audit trail entry whenever an AI-assisted draft is part of what got saved —
        // distinct from the generic "save" line above, so AI-sourced costings are traceable.
        if ($aiGeneratedNames) { try { audit_log(0,'Product Costing','ai_apply',$prod['name'],implode('; ',$aiGeneratedNames),'AI-assisted costing version(s) saved'); } catch (Throwable $e) {} }
        db()->commit();
        // AI search index — never blocks the save; if OpenAI is briefly
        // unavailable the costing is still saved, search just stays a
        // little stale until the next successful save.
        foreach ($savedVersionIds as $svid) { try { aic_embed_version($svid); } catch (Throwable $e) {} }
        echo json_encode(['success'=>true,'message'=>'Costing saved for '.$prod['name'].'.']);
    } catch (Throwable $e) { if (db()->inTransaction()) db()->rollBack(); echo json_encode(['success'=>false,'message'=>$e->getMessage()]); }
    exit;
}

/* ---------- page load ---------- */
$pid = (int)($_GET['product_id'] ?? 0);
$product=null; $sizes=[]; $versions=[]; $workmanshipRate=0.0; $wmComponentRates=[]; $wmComponentQty=[];
$allProducts = cache_remember('products_active_list:v' . cache_version('products'), 30, function () {
    return db()->query("SELECT id,name,category,product_code FROM products WHERE is_active=1 ORDER BY name LIMIT 500")->fetchAll();
});
if ($pid) {
    $st=db()->prepare("SELECT * FROM products WHERE id=?"); $st->execute([$pid]); $product=$st->fetch();
    if ($product) {
        $workmanshipRate = pc_workmanship_rate($pid);
        // per-component, per-size-scope rate + per-size quantity, so the live
        // editor can preview size-scaled Workmanship as sizes are ticked,
        // matching exactly what pc_workmanship_rate_for_version() computes
        // on save. $wmComponentRates[component][sizeScope] — sizeScope is ''
        // for the "All Sizes" rate or a product_size_id for a size-specific one.
        $wmComponentRates = production_operation_rate_map($pid);
        $st=db()->prepare("SELECT product_size_id, component_name, qty_per_set FROM product_component_qty WHERE product_id=?"); $st->execute([$pid]);
        foreach ($st->fetchAll() as $r) $wmComponentQty[(int)$r['product_size_id']][$r['component_name']] = (float)$r['qty_per_set'];
        $st=db()->prepare("SELECT id,size_label FROM product_sizes WHERE product_id=? ORDER BY id"); $st->execute([$pid]);
        foreach ($st->fetchAll() as $r) $sizes[]=['id'=>(int)$r['id'],'name'=>$r['size_label']];
        $st=db()->prepare("SELECT * FROM costing_versions WHERE product_id=? ORDER BY id"); $st->execute([$pid]);
        foreach ($st->fetchAll() as $v) {
            $ls=db()->prepare("SELECT * FROM costing_lines WHERE costing_version_id=? ORDER BY sort_order,id"); $ls->execute([$v['id']]);
            // Workmanship rows are auto-generated fresh on every save (see save
            // handler) — skip loading the saved snapshot back into the live
            // editor so it isn't shown twice or treated as an editable line.
            $lines=[]; foreach ($ls->fetchAll() as $l) { if ($l['line_group']==='Workmanship') continue; $lines[]=['group'=>$l['line_group'],'item'=>$l['item_name'],'desc'=>$l['description'],'qty'=>(float)$l['quantity'],'unit'=>$l['unit'],'weight'=>(float)$l['weight_kg'],'rate'=>(float)$l['rate'],'shared'=>!empty($l['shared']),'material_id'=>(int)($l['material_id'] ?? 0)]; }
            $sz=db()->prepare("SELECT product_size_id FROM costing_version_sizes WHERE costing_version_id=?"); $sz->execute([$v['id']]);
            $versions[]=['id'=>(int)$v['id'],'costing_no'=>$v['costing_no'],'name'=>$v['version_name'],'currency'=>$v['currency'],'status'=>$v['status'] ?? 'draft','remarks'=>$v['remarks'],'suggested_price'=>(float)$v['suggested_price'],'sizeIds'=>array_map('intval',array_column($sz->fetchAll(),'product_size_id')),'lines'=>$lines];
        }
    }
}

$canEdit = costing_perm('create') || costing_perm('edit');
page_header('Product Costing');
flash();
?>
<?php /* THE SKIN, OPTED IN. Every rule in assets/css/zskin.css is scoped under
         .zskin, so this one attribute is the whole of the restyle and removing
         it puts the page back exactly as it was. Nothing below is edited: the
         page keeps its own class names, and the skin maps onto them. */ ?>
<div class="zskin">
<style>
.zcard{padding:15px;border-radius:18px;background:#ffffff;border:1px solid #e3e9f2;backdrop-filter:blur(12px);margin-bottom:11px}
.zcard h2{font-size:15px;margin:0 0 4px}
/* .zin lives in assets/css/app.css now — it was defined here twice, the
   second copy silently overriding the first. */
.zlabel{font-size:11px;font-weight:700;text-transform:uppercase;letter-spacing:.03em;color:#5a6b82;display:block;margin-bottom:5px}
.zbtn{padding:10px 16px;border:none;border-radius:11px;cursor:pointer;font-weight:700;font-size:13px;color:#fff;background:linear-gradient(100deg,#0ea8c9,#6d5bd0);text-decoration:none;display:inline-block}
.zbtn.sec{background:#f6f8fc;color:#152033;border:1px solid #cbd5e3}
.zbtn.sm{padding:6px 10px;font-size:12px}
.zbtn.red{background:rgba(224,67,93,.15);color:#b8283f;border:1px solid rgba(224,67,93,.3)}
.shbtn{width:22px;height:22px;flex-shrink:0;border-radius:6px;border:1px solid #cbd5e3;background:#ffffff;color:#8a97ab;font-size:12px;line-height:1;cursor:pointer;display:inline-flex;align-items:center;justify-content:center;transition:.15s;padding:0}
.shbtn:hover{border-color:rgba(14,168,201,.5);color:#0ea8c9}
.shbtn.on{background:linear-gradient(135deg,#0ea8c9,#6d5bd0);border-color:transparent;color:#fff;box-shadow:0 0 10px rgba(14,168,201,.4)}
.qtywrap{display:flex;gap:6px;align-items:center}
.note{padding:12px 14px;border-radius:12px;background:rgba(14,168,201,.07);border:1px solid rgba(14,168,201,.2);color:#0ea8c9;font-size:12.5px;margin-bottom:12px;line-height:1.5}
.msg{padding:13px 16px;border-radius:12px;margin-bottom:14px;font-size:13px;display:none}
.msg.ok{display:block;background:rgba(22,163,74,.1);border:1px solid rgba(22,163,74,.28);color:#127a3f}
.msg.err{display:block;background:rgba(224,67,93,.1);border:1px solid rgba(224,67,93,.28);color:#b8283f}
.chips{display:flex;gap:8px;flex-wrap:wrap;margin-top:12px}
.chip{display:inline-flex;align-items:center;gap:8px;border:1px solid #cbd5e3;border-radius:20px;padding:7px 12px;background:#ffffff;font-size:13px}
.chip button{border:0;background:transparent;cursor:pointer;padding:0;color:#5a6b82;font-size:14px}.chip .edit{color:#0ea8c9}
.empty{color:#8a97ab;font-size:13px}
.profile{border:1px solid #e3e9f2;border-radius:14px;padding:16px;margin-top:14px;background:#ffffff}
.profile.active{border-color:rgba(14,168,201,.5);box-shadow:0 0 0 2px rgba(14,168,201,.18)}
.profilehead{display:flex;justify-content:space-between;gap:10px;align-items:center;flex-wrap:wrap}
.profilehead h3{margin:0;font-size:14.5px}.profilehead small{color:#8a97ab}
.badge{padding:3px 9px;border-radius:20px;font-size:10.5px;font-weight:700;text-transform:uppercase;letter-spacing:.03em}
.badge.draft{background:rgba(47,127,224,.16);color:#2f7fe0}.badge.approved{background:rgba(22,163,74,.16);color:#16a34a}
.badge.converted{background:rgba(109,91,208,.16);color:#6d5bd0}.badge.archived{background:#e3e9f2;color:#5a6b82}
.sizechecks{display:flex;gap:8px;flex-wrap:wrap;margin:10px 0}
.sizecheck{border:1px solid #cbd5e3;border-radius:16px;padding:6px 10px;font-size:12.5px;color:#33415c;cursor:pointer}
.sizecheck.active{border-color:rgba(14,168,201,.45);background:rgba(14,168,201,.1);color:#0ea8c9;font-weight:700}.sizecheck input{margin:0 5px 0 0;accent-color:#0ea8c9}
.tablewrap{overflow:auto;border:1px solid #e3e9f2;border-radius:12px;margin-top:10px}
table.ct{width:100%;border-collapse:collapse;min-width:920px;font-size:12.5px}
table.ct th{background:#ffffff;color:#8a97ab;font-size:10.5px;text-transform:uppercase;letter-spacing:.04em;text-align:left;padding:5px 8px}
table.ct td{padding:3px 8px;border-top:1px solid #f6f8fc}table.ct .zin{padding:4px 7px}
/* Item-link state, under the Item box. Small on purpose — it reports, it
   does not compete with the figures. */
.itagrow{margin-top:4px}
.itag{display:inline-block;font-size:10px;font-weight:800;letter-spacing:.02em;padding:2px 7px;border-radius:20px;border:1px solid #e3e9f2;background:#f6f8fc;color:#8a97ab;font-family:inherit;white-space:nowrap}
.itag.ok{background:rgba(22,163,74,.1);border-color:transparent;color:#15803d}
.itag.warn{background:rgba(217,119,6,.12);border-color:transparent;color:#b45309}
button.itag.add{background:rgba(14,168,201,.1);border-color:transparent;color:#0b7d96;cursor:pointer}
button.itag.add:hover{background:rgba(14,168,201,.2)}
.mini{max-width:92px}.num{text-align:right;color:#0ea8c9;font-weight:600}
.summary{display:grid;grid-template-columns:repeat(3,1fr);gap:10px;margin-top:12px}
@media(max-width:720px){.summary{grid-template-columns:1fr 1fr}}
.box{border:1px solid #e3e9f2;border-radius:12px;padding:12px;background:#ffffff}
.box span{display:block;font-size:10.5px;color:#5a6b82;text-transform:uppercase;letter-spacing:.04em}.box b{font-size:17px;font-family:'Space Grotesk',system-ui,sans-serif}
tr.wmrow{background:#f2f7fb}
tr.wmrow td{padding-top:10px;padding-bottom:10px}
.wmlabel{font-weight:700;color:#0c7a94;font-size:12.5px}
.wmsrc{font-size:11px;color:#8a97ab;margin-top:2px}
.wmlink{color:#0ea8c9;font-weight:600;text-decoration:none}
.vactions{display:flex;gap:8px;flex-wrap:wrap;margin-top:12px}
.footbar{position:sticky;bottom:0;background:rgba(255,255,255,.94);backdrop-filter:blur(8px);border-top:1px solid #e3e9f2;padding:12px 0;display:flex;justify-content:flex-end;gap:10px;margin-top:8px}
/* The "More" menu. A plain <details>, so it needs no script and the keyboard
   opens it. It rises UPWARDS because the bar is stuck to the bottom of the
   screen — a panel dropping down would fall off the edge. */
.moremenu{position:relative}
.moremenu>summary{list-style:none;cursor:pointer;user-select:none}
.moremenu>summary::-webkit-details-marker{display:none}
.morepanel{position:absolute;bottom:calc(100% + 6px);right:0;z-index:40;min-width:250px;
  display:flex;flex-direction:column;gap:2px;padding:5px;
  background:#fff;border:1px solid #e3e9f2;border-radius:10px;
  box-shadow:0 10px 30px rgba(21,32,51,.14)}
.morepanel>a,.morepanel>button{display:block;width:100%;text-align:left;white-space:nowrap;
  padding:7px 10px;border:0;border-radius:6px;background:transparent;color:#152033;
  font:inherit;font-size:12.5px;text-decoration:none;cursor:pointer}
.morepanel>a:hover,.morepanel>button:hover{background:#f6f8fc}
.morepanel>.dg{color:#b8283f}
.morepanel>.dg:hover{background:rgba(224,67,93,.1)}
input[type=number]::-webkit-outer-spin-button,input[type=number]::-webkit-inner-spin-button{-webkit-appearance:none;margin:0}
input[type=number]{-moz-appearance:textfield}
/* ---- Compare Versions dashboard ---- */
.cmp-tiles{display:grid;grid-template-columns:repeat(5,1fr);gap:10px;margin-bottom:14px}
@media(max-width:760px){.cmp-tiles{grid-template-columns:repeat(2,1fr)}}
.cmp-tile{border:1px solid #e3e9f2;border-radius:12px;padding:12px;background:#ffffff}
.cmp-tile b{display:block;font-size:9.5px;text-transform:uppercase;letter-spacing:.03em;color:#8a97ab;margin-bottom:5px}
.cmp-tile .v{font-size:17px;font-weight:800;color:#152033;font-family:'Space Grotesk',system-ui,sans-serif}
.cmp-tile .v.pos{color:#b8283f}.cmp-tile .v.neg{color:#16a34a}
.cmp-ai-box{border:1.5px solid #cfe4fb;background:#f4f9ff;border-radius:12px;padding:14px;margin-bottom:14px}
.cmp-ai-box .head{display:flex;justify-content:space-between;align-items:center;flex-wrap:wrap;gap:8px;margin-bottom:8px}
.cmp-ai-box .head .t{font-weight:800;color:#0f4c81;font-size:12.5px}
.cmp-ai-box pre{white-space:pre-wrap;font-family:inherit;font-size:12.5px;line-height:1.65;color:#152033;margin:0}
.flagpill{display:inline-block;padding:2px 7px;border-radius:20px;font-size:9.5px;font-weight:700;margin-top:3px;margin-right:3px}
.flagpill.rate{background:rgba(217,119,6,.14);color:#a25c04}
.flagpill.qty,.flagpill.qty_decimal{background:rgba(224,67,93,.14);color:#b8283f}
.flagpill.missing{background:rgba(138,151,171,.16);color:#5a6b82}
.flagpill.dup{background:rgba(109,91,208,.14);color:#6d5bd0}
.rowflagged{background:#fffaf2}
</style>

<div class="topbar">
  <div><h1>Product Costing</h1><p class="lead">Reusable costing versions with shared-carton support, print, import &amp; proforma conversion.</p></div>
  <a class="zbtn sec" href="product_master.php">← Product Master</a>
</div>

<div id="msg" class="msg"></div>

<div class="zcard">
  <h2>Product</h2>
  <div class="note">Costing links to a Product Master product &amp; product code. Pick a product, then build costing versions and assign each to the sizes it covers.</div>
  <form method="get" style="display:flex;gap:10px;flex-wrap:wrap;align-items:end">
    <div style="flex:1;min-width:240px"><label class="zlabel">Select Product</label>
      <select class="zin" name="product_id" onchange="this.form.submit()">
        <option value="">— Choose a product —</option>
        <?php foreach($allProducts as $p): ?><option value="<?= (int)$p['id'] ?>" <?= $pid===(int)$p['id']?'selected':'' ?>><?= $p['product_code']?e($p['product_code']).' · ':'' ?><?= e($p['name']) ?></option><?php endforeach; ?>
      </select>
    </div>
  </form>
  <?php if(!$allProducts): ?><div style="margin-top:10px;font-size:12.5px;color:#d97706">No products yet. Create one in Product Master first.</div><?php endif; ?>
</div>

<?php if($canEdit && !empty($config['openai_enabled'])): ?>
<div class="zcard" id="aiCard">
  <h2>AI-Assisted Costing <span style="font-weight:400;color:#8a97ab;font-size:11.5px;text-transform:none;letter-spacing:0">(beta — always creates a draft, never saves automatically)</span></h2>
  <div class="note">Describe the costing in plain English — one size or up to eight, for an existing OR a brand-new product. AI only drafts the numbers you already stated; it never invents a quantity or rate, never calculates totals, and never saves anything. PHP validates, calculates every size, and you review &amp; apply before Save Costing does its normal job.</div>
  <label class="zlabel" style="margin-top:10px">Describe Product Costing</label>
  <textarea id="aiText" class="zin" rows="7" style="resize:vertical;font-family:inherit" placeholder="Create costing for a 100% cotton fitted sheet in six sizes.&#10;The main fabric rate is PKR 420 per meter.&#10;All costs are the same for every size except main fabric consumption.&#10;&#10;Common costs:&#10;Stitching: PKR 180 per piece&#10;Elastic: PKR 65 per piece&#10;Polybag: PKR 25 per piece&#10;Insert card: PKR 12 per piece&#10;Master carton: PKR 300&#10;Pieces per carton: 6&#10;&#10;Sizes and fabric consumption:&#10;Single: 2.80 meters&#10;Double: 3.40 meters&#10;Queen: 3.90 meters&#10;King: 4.50 meters&#10;Super King: 4.90 meters&#10;Extra King: 5.30 meters"></textarea>
  <div style="display:flex;gap:10px;flex-wrap:wrap;margin-top:12px">
    <button class="zbtn" type="button" id="aiGenBtn" onclick="aiGenerateDraftEntry()">Generate Draft</button>
    <?php if($product): ?>
    <button class="zbtn sec" type="button" onclick="aiSearchExisting()">Search Existing Costing</button>
    <button class="zbtn sec" type="button" id="aiReviewBtn" onclick="aiScrollToPreview()" disabled>Review and Apply</button>
    <?php endif; ?>
    <button class="zbtn sec" type="button" onclick="aiCancel()">Cancel</button>
  </div>
  <div id="aiSearchResults" style="margin-top:12px"></div>
  <div id="aiMsg" style="margin-top:12px;display:none"></div>
  <div id="aiPreview" style="margin-top:16px;display:none"></div>
</div>
<script>
const CSRF=<?= json_encode(csrf_token()) ?>;
const PRODUCT_ID=<?= (int)$pid ?>;
const HAS_PRODUCT=<?= $product ? 'true' : 'false' ?>;
function esc(v){return String(v==null?'':v).replace(/[&<>"']/g,s=>({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#039;'}[s]))}
function aiShowMsg(t,ok){const el=document.getElementById('aiMsg');if(!el)return;el.style.display='block';el.style.padding='12px 14px';el.style.borderRadius='10px';el.style.fontSize='12.5px';
  if(ok){el.style.background='rgba(22,163,74,.1)';el.style.border='1px solid rgba(22,163,74,.28)';el.style.color='#127a3f'}
  else{el.style.background='rgba(224,67,93,.1)';el.style.border='1px solid rgba(224,67,93,.28)';el.style.color='#b8283f'}
  el.textContent=t}
function aiCancel(){
  document.getElementById('aiText').value='';
  const pv=document.getElementById('aiPreview'); if(pv){pv.style.display='none';pv.innerHTML=''}
  const sr=document.getElementById('aiSearchResults'); if(sr) sr.innerHTML='';
  document.getElementById('aiMsg').style.display='none';
  const rb=document.getElementById('aiReviewBtn'); if(rb) rb.disabled=true;
  sessionStorage.removeItem('zas_ai_pending');
  if(typeof aiLastResult!=='undefined') aiLastResult=null;
}
/* No product selected yet: Generate Draft still runs the full AI extraction
   (costing_ai_extract.php understands free text either way) — it just can't
   apply into a page that has nowhere to save it, so it hands off to either
   the matched product's own page, or offers to create a new one, carrying
   the already-generated draft across via sessionStorage (never re-billing
   the AI call just because of the navigation). */
async function aiGenerateDraftEntry(){
  if(HAS_PRODUCT){ aiGenerateDraft(); return; }
  const txt=document.getElementById('aiText').value.trim();const btn=document.getElementById('aiGenBtn');const msg=document.getElementById('aiMsg');
  msg.style.display='none';
  if(!txt){aiShowMsg('Please describe the costing first.',false);return}
  btn.disabled=true;btn.textContent='Generating…';
  try{
    const r=await fetch('costing_ai_extract.php',{method:'POST',headers:{'Content-Type':'application/json'},body:JSON.stringify({_csrf:CSRF,action:'generate',text:txt})});
    const d=await r.json();
    if(!d.success){aiShowMsg(d.message||'Could not generate a draft.',false);return}
    sessionStorage.setItem('zas_ai_pending', JSON.stringify(d));
    const box=document.getElementById('aiPreview');box.style.display='block';
    if(d.matched_product_id){
      const name=(d.existing_matches&&d.existing_matches[0]&&d.existing_matches[0].name)||d.product.product_name||'this product';
      box.innerHTML='<div class="note">Matched to an existing product: <strong>'+esc(name)+'</strong>. Open it to review and apply this draft.</div>'
        +'<button class="zbtn" type="button" onclick="location.href=\'product_costing.php?product_id='+d.matched_product_id+'&ai_resume=1\'">Open '+esc(name)+' &amp; Continue</button>';
    } else {
      const name=d.product.product_name||'New Product';
      box.innerHTML='<div class="note">This looks like a new product — not yet in Product Master.</div>'
        +'<button class="zbtn" type="button" id="aiCreateProductBtn" onclick="aiCreateProductAndContinue()">Create &quot;'+esc(name)+'&quot; &amp; Continue</button>';
    }
  }catch(e){aiShowMsg('AI request failed — you can still use the costing form manually.',false)}
  finally{btn.disabled=false;btn.textContent='Generate Draft'}
}
async function aiCreateProductAndContinue(){
  const pending=JSON.parse(sessionStorage.getItem('zas_ai_pending')||'null');
  if(!pending){aiShowMsg('Draft expired — please generate again.',false);return}
  const btn=document.getElementById('aiCreateProductBtn'); if(btn){btn.disabled=true;btn.textContent='Creating…'}
  try{
    const r=await fetch(location.pathname,{method:'POST',headers:{'Content-Type':'application/json','X-Requested-With':'costing'},body:JSON.stringify({_csrf:CSRF,op:'create_product_from_ai',name:pending.product.product_name||'New Product',currency:pending.product.currency||'PKR'})});
    const d=await r.json();
    if(!d.success){aiShowMsg(d.message||'Could not create product.',false);if(btn){btn.disabled=false;btn.textContent='Create & Continue'}return}
    location.href='product_costing.php?product_id='+d.product_id+'&ai_resume=1';
  }catch(e){aiShowMsg('Could not create product.',false);if(btn)btn.disabled=false}
}
</script>
<?php endif; ?>

<?php if($product): ?>
<div class="zcard">
  <h2>Sizes — <?= e($product['name']) ?><?= $product['product_code']?' <span style="color:#8a97ab;font-size:12px">('.e($product['product_code']).')</span>':'' ?></h2>
  <div class="note">Type any size. New sizes append to this product’s Product Master record on save. Existing sizes are never deleted here.</div>
  <div style="display:grid;grid-template-columns:1fr auto;gap:10px;max-width:520px">
    <input id="newSize" class="zin" placeholder="Type a size and press Enter" onkeydown="if(event.key==='Enter'){event.preventDefault();addSize()}" <?= $canEdit?'':'disabled' ?>>
    <button class="zbtn" type="button" onclick="addSize()" <?= $canEdit?'':'disabled' ?>>+ Add Size</button>
  </div>
  <div id="sizeChips" class="chips"></div>
</div>

<div class="zcard">
  <h2>Costing Versions</h2>
  <div class="note">Duplicate a version when another size reuses most lines. Use a fractional carton qty (e.g. 0.2) for a master carton shared by several units. Detail lines can also be bulk-imported per version.</div>
  <?php if($canEdit): ?><div style="display:flex;gap:10px;flex-wrap:wrap"><button class="zbtn sec" type="button" title="Start a fresh, empty costing version with starter lines" onclick="addProfile()">+ New Costing Version</button></div><?php endif; ?>
  <?php if(costing_perm('proforma')): ?>
  <div id="proformaBulkBar" style="display:none;margin-top:12px;padding:12px 14px;border-radius:12px;background:#eef6ff;border:1px solid #cfe4fb;align-items:center;justify-content:space-between;gap:10px;flex-wrap:wrap">
    <span style="font-size:12.5px;color:#0f4c81;font-weight:700"><span id="proformaBulkCount">0</span> version(s) selected</span>
    <div style="display:flex;gap:8px">
      <button class="zbtn sec sm" type="button" onclick="clearProformaSelection()">Clear</button>
      <button class="zbtn sm" type="button" onclick="convertSelectedToProforma()">Convert Selected to Proforma Invoice</button>
    </div>
  </div>
  <?php endif; ?>
  <div id="profiles"></div>
  <?php /* One shared pick-list for every Item box on the page. Filled by
           renderItemList() so a newly added item appears at once. */ ?>
  <datalist id="invItems"></datalist>
</div>

<?php if($canEdit && !empty($config['openai_enabled'])): ?>
<div class="zcard" id="compareCard">
  <h2>Compare Versions</h2>
  <div class="note">Select 2 to 6 saved versions — e.g. every size of this product — for a full side-by-side sheet: materials, consumption, rates, labour, packing, other charges, total cost &amp; suggested price. The table is instant calculation; the written summary is one cached AI call.</div>
  <div id="cmpPicker" style="display:flex;gap:8px;flex-wrap:wrap;margin:10px 0"></div>
  <div style="display:flex;gap:10px;align-items:center;flex-wrap:wrap">
    <button class="zbtn" type="button" onclick="aiCompareVersions(false)"><span id="cmpSelCount">0</span> selected — Compare</button>
  </div>
  <div id="cmpResult" style="margin-top:14px"></div>
</div>
<?php endif; ?>

<?php if($canEdit || costing_perm('print')): ?>
<?php /* SIX BUTTONS ACROSS THE BOTTOM, of which only one is what you came to
         do. Print and Save stay out; Share, Duplicate, Compare and Reset move
         under "More" — they are still one click away, and nothing was removed.
         The menu is a plain <details>: no JavaScript, works with the keyboard,
         and closing the page closes it. */ ?>
<div class="footbar">
  <?php if(costing_perm('print')): ?><a class="zbtn sec" target="_blank" href="costing_print.php?product_id=<?= (int)$pid ?>">🖨 Print</a><?php endif; ?>
  <details class="moremenu">
    <summary class="zbtn sec" title="Share, duplicate, compare and reset">⋯ More</summary>
    <div class="morepanel">
      <?php if(costing_perm('print')): ?><a target="_blank" title="Fabric &amp; total cost hidden — safe to send to your team" href="costing_print.php?product_id=<?= (int)$pid ?>&share=1">🔒 Share with Team</a><?php endif; ?>
      <?php if($canEdit): ?><button type="button" title="Duplicates the whole selected costing version — every detail line and size — into a new editable draft" onclick="duplicateActive()">⎘ Duplicate Costing</button><?php endif; ?>
      <?php if($canEdit && !empty($config['openai_enabled'])): ?><button type="button" onclick="document.getElementById('compareCard').scrollIntoView({behavior:'smooth',block:'start'})">⇄ Compare Versions</button><?php endif; ?>
      <?php if($canEdit): ?><button type="button" class="dg" title="Reloads the page — anything not saved is lost" onclick="location.reload()">↺ Reset (discards unsaved changes)</button><?php endif; ?>
    </div>
  </details>
  <?php if($canEdit): ?><button class="zbtn" type="button" id="saveBtn" onclick="saveCosting()">Save Costing</button><?php endif; ?>
</div>
<?php endif; ?>

<?php /* The behaviour of this screen — ~430 lines of it — now lives in a file
         the browser caches once, instead of being re-sent inside the page on
         every single load. It DECLARES only; nothing in it runs at load time,
         so it must come BEFORE the inline block below, which supplies the
         PHP-rendered data those functions read and then calls renderAll(). */ ?>
<script src="assets/js/costing.js?v=27"></script>
<script>
/* CSRF, PRODUCT_ID and HAS_PRODUCT are already declared above, in the
   always-present AI-Assisted Costing script — this block only exists when
   a product is selected, so it reuses those instead of redeclaring them. */
const STATUSES=<?= json_encode($STATUSES) ?>;
const PRODUCT_WORKMANSHIP_RATE=<?= json_encode(round($workmanshipRate,2)) ?>;
const WORKMANSHIP_COMPONENT_RATES=<?= json_encode($wmComponentRates) ?>;
const WORKMANSHIP_COMPONENT_QTY=<?= json_encode($wmComponentQty) ?>;
/* mirrors pc_workmanship_rate_for_version() server-side. WORKMANSHIP_COMPONENT_RATES
   is [component][operationName][sizeScope] => rate — kept per-operation so a
   component made of several operations (some global, some size-scoped) adds
   each one's own resolved rate together, instead of one size-scoped operation
   replacing its component's other operations. For each operation: a row priced
   for the version's one size wins over its "All Sizes" row (sizeScope ''); an
   operation with only size-scoped rows contributes 0 for a size it wasn't
   priced for. The component's resulting sum is then scaled by that size's
   quantity-per-set, same as before. Zero or several sizes on the version uses
   every operation's "All Sizes" rate only — no single size to resolve against. */
function computeWorkmanship(sizeIds){
  var singleSize = (sizeIds && sizeIds.length===1) ? sizeIds[0] : null;
  var qtyMap = singleSize!==null ? (WORKMANSHIP_COMPONENT_QTY[singleSize]||{}) : {};
  var total = 0;
  Object.keys(WORKMANSHIP_COMPONENT_RATES).forEach(function(comp){
    var ops = WORKMANSHIP_COMPONENT_RATES[comp] || {};
    var compTotal = 0;
    Object.keys(ops).forEach(function(opName){
      var bySize = ops[opName] || {};
      if (singleSize!==null && (singleSize in bySize)) compTotal += bySize[singleSize];
      else if ('' in bySize) compTotal += bySize[''];
    });
    if (comp === '' || singleSize === null) { total += compTotal; return; }
    var q = (comp in qtyMap) ? qtyMap[comp] : 1;
    total += compTotal * q;
  });
  return total;
}
const CAN_EDIT=<?= $canEdit?'true':'false' ?>;
const CAN_PRINT=<?= costing_perm('print')?'true':'false' ?>;
const CAN_IMPORT=<?= costing_perm('import')?'true':'false' ?>;
const CAN_PROFORMA=<?= costing_perm('proforma')?'true':'false' ?>;
/* The Item Master, for the Item column's pick-list. Empty array = the
   Store & Inventory module is not installed, and every Item cell behaves
   as the plain text box it has always been. */
const INV_ITEMS=<?= json_encode(pc_inv_items()) ?>;
const CAN_CREATE_ITEM=<?= pc_can_create_item()?'true':'false' ?>;
const INV_BY_NAME=(function(){const m={};INV_ITEMS.forEach(function(i){const k=i.name.trim().toLowerCase();if(!(k in m))m[k]=i});return m})();
const INV_BY_ID=(function(){const m={};INV_ITEMS.forEach(function(i){m[i.id]=i});return m})();
let sizes=<?= json_encode($sizes ?: []) ?>;
let profiles=<?= json_encode($versions ?: []) ?>;
let nextTempPid=-1, nextTempSid=-1;
let selectedForProforma=new Set();
const starterLines=[
 {group:'Fabric',item:'Main Fabric',desc:'',qty:3.2,unit:'Meter',weight:1.45,rate:520},
 {group:'Accessories',item:'Sewing Thread',desc:'',qty:1,unit:'Set',weight:0.02,rate:35},
 {group:'Accessories',item:'Polybag',desc:'',qty:1,unit:'Pc',weight:0.03,rate:25},
 {group:'Accessories',item:'Shared Master Carton',desc:'1 carton shared by 5 units',qty:0.2,unit:'Carton',weight:0.08,rate:250}
];
if(!profiles.length) profiles=[{id:-1,costing_no:'(new)',name:'Base Costing',currency:'PKR',status:'draft',remarks:'',suggested_price:0,sizeIds:[],lines:clone(starterLines)}];
let activeProfileId=profiles[0].id;

renderAll();
renderItemList();
document.addEventListener('focusin',function(e){if(e.target&&e.target.tagName==='INPUT'&&e.target.type==='number')e.target.select()});

/* Resume an AI draft generated before a product existed/was selected — the
   draft itself was already produced by the AI call on the previous page,
   this just re-renders it here so nothing gets extracted twice. */
if (new URLSearchParams(location.search).get('ai_resume') === '1') {
  var pendingRaw = sessionStorage.getItem('zas_ai_pending');
  if (pendingRaw) {
    try {
      var pending = JSON.parse(pendingRaw);
      aiLastResult = pending; aiSelectedSizes = new Set(pending.sizes.map(s=>s.size_name)); aiOpenDetails = new Set();
      aiRenderPreview(pending);
      document.getElementById('aiReviewBtn').disabled = false;
      sessionStorage.removeItem('zas_ai_pending');
      var pv = document.getElementById('aiPreview'); if (pv) pv.scrollIntoView({behavior:'smooth', block:'start'});
    } catch (e) {}
  }
}
</script>
<?php endif; ?>
</div><?php /* closes .zskin */ ?>
<?php page_footer(); ?>
