<?php
/*
  AI Check — Stage 1 shared core (deterministic, no OpenAI).
  Used by BOTH ai_check.php (the on-screen AI Check panel) and
  ai_check_export.php (the downloadable CSV costing report), so the two
  always show exactly the same numbers — no risk of the export drifting
  from what the panel calculated.
*/

function pmk($n){ return strtolower(trim(preg_replace('/[^a-z0-9]+/i',' ', (string)$n))); }

/* currency conversion via PKR base (fx = units of currency per 1 PKR) */
function cvt(float $amt, string $from, string $to, array $fx): float {
    $from=strtoupper($from?:'PKR'); $to=strtoupper($to?:'PKR');
    $f=$fx[$from]??0; $t=$fx[$to]??0;
    if($f<=0||$t<=0||$from===$to) return $amt;
    return ($amt/$f)*$t;   // amt -> PKR base -> target currency
}

/* fuzzy Product Master lookup: returns [key, score 0-1] best candidate for a name */
function pm_fuzzy(string $name, array $pm): array {
    $q = pmk($name); if ($q==='' || !$pm) return ['',0];
    $best=''; $bestScore=0;
    foreach ($pm as $key=>$row){
        if ($key===$q){ return [$key,1]; }
        if (strpos($key,$q)!==false || strpos($q,$key)!==false){ $score=0.9; }
        else { similar_text($q,$key,$pct); $score=$pct/100; }
        if ($score>$bestScore){ $bestScore=$score; $best=$key; }
    }
    return [$best,$bestScore];
}

/* choose the costing version for a product that best matches an invoice size/desc text (numeric-aware) */
function pick_costing(array $versions, string $sizeText) {
    if (!$versions) return null;
    $norm = fn($s)=>strtolower(trim(preg_replace('/[^a-z0-9]+/i',' ', (string)$s)));
    $nums = function($s){ preg_match_all('/\d+(?:\.\d+)?/', (string)$s, $m); return array_map('floatval',$m[0]); };
    $q = $norm($sizeText); $qn = $nums($sizeText);
    $best=null; $bestScore=-1; $bestExact=false;
    foreach ($versions as $cv) {
        foreach (($cv['size_labels'] ?? []) as $lbl) {
            $l = $norm($lbl); if ($l==='') continue;
            $ln = $nums($lbl); $score=0; $exact=false;
            if ($l===$q || strpos($q,$l)!==false || strpos($l,$q)!==false) { $score=100; $exact=true; }
            elseif ($qn && $ln) {
                $bestDiff = PHP_FLOAT_MAX;
                foreach ($qn as $a) foreach ($ln as $b) { $d=abs($a-$b); if ($d<$bestDiff) $bestDiff=$d; }
                $score = 50 - min(49,$bestDiff);      // nearest size number wins
                if ($bestDiff <= 15) $exact = true;   // within 15 units = accept
            } else { similar_text($q,$l,$pct); $score=$pct/4; }
            if ($score > $bestScore) { $bestScore=$score; $best=$cv; $bestExact=$exact; }
        }
    }
    if ($best === null) return ['cv'=>$versions[0],'size_matched'=>false];
    return ['cv'=>$best,'size_matched'=>$bestExact];
}

/* Runs every deterministic Stage-1 check + the costing/material/financial
   calculation for one shipment. Returns null if the shipment doesn't
   exist / isn't viewable by the current user. */
/* $skipItemIds: shipment_item ids to skip the expensive per-line work for
   (matching, price history, profitability) — used ONLY by final_costing.php
   to avoid re-checking lines that are already Locked (their cost is final
   and can't change without an explicit Reopen, so re-verifying the product
   match on every page view is wasted work). Every other caller passes
   nothing, so this is a no-op / zero behavior change for them. */
function ai_check_compute(int $id, array $skipItemIds = []): ?array {
    global $config;
    $TH = $config['ai_thresholds'] ?? [];
    $th = fn($k,$d)=> (float)($TH[$k] ?? $d);

    $st = db()->prepare("SELECT * FROM shipments WHERE id=?"); $st->execute([$id]); $s = $st->fetch();
    if (!$s || !can_view_shipment($id)) return null;

    $st = db()->prepare("SELECT * FROM shipment_items WHERE shipment_id=? ORDER BY line_no,id"); $st->execute([$id]); $items = $st->fetchAll();
    $st = db()->prepare("SELECT * FROM packing_items WHERE shipment_id=? ORDER BY line_no,id"); $st->execute([$id]); $packs = $st->fetchAll();

    /* Product Master + latest costing per product (batched, no queries in loops) */
    $pm = []; $pmById = [];
    try { foreach (db()->query("SELECT * FROM products")->fetchAll() as $p) { $pm[pmk($p['name'])] = $p; $pmById[(int)$p['id']] = $p; } } catch (Throwable $e) {}
    $costByPid = []; $costVersionsByPid = []; $matByVid = [];
    try {
        foreach (db()->query("SELECT * FROM costing_versions ORDER BY id DESC")->fetchAll() as $cv) {
            if (!isset($costByPid[$cv['product_id']])) $costByPid[$cv['product_id']] = $cv;  // latest (fallback)
            $costVersionsByPid[$cv['product_id']][] = $cv;                                    // all versions
        }
        // size labels per costing version
        $vsizes = [];
        try { foreach (db()->query("SELECT cvs.costing_version_id vid, ps.size_label lbl FROM costing_version_sizes cvs JOIN product_sizes ps ON ps.id=cvs.product_size_id")->fetchAll() as $r) { $vsizes[(int)$r['vid']][] = $r['lbl']; } } catch (Throwable $e) {}
        foreach ($costVersionsByPid as $pid=>$list) foreach ($list as $i=>$cv) $costVersionsByPid[$pid][$i]['size_labels'] = $vsizes[(int)$cv['id']] ?? [];
        if ($costByPid) {
            $allVids = [];
            foreach ($costVersionsByPid as $list) foreach ($list as $cv) $allVids[] = (int)$cv['id'];
            if ($allVids) {
                $in = implode(',', array_fill(0,count($allVids),'?'));
                $ls = db()->prepare("SELECT * FROM costing_lines WHERE costing_version_id IN ($in)");
                $ls->execute($allVids);
                foreach ($ls->fetchAll() as $l) $matByVid[(int)$l['costing_version_id']][] = $l;
            }
        }
    } catch (Throwable $e) {}

    $FX = fx_get_rates();

    $checks = [];
    $add = function($level,$code,$msg,$line=null) use (&$checks){ $checks[] = ['level'=>$level,'code'=>$code,'msg'=>$msg,'line_no'=>$line]; };

    /* ===== 1. HEADER ===== */
    if (trim((string)$s['invoice_no'])==='') $add('error','HDR_NO_INVOICE',"Invoice number is missing.");
    if (empty($s['invoice_date'])) $add('warning','HDR_NO_DATE',"Invoice date is not set.");
    if (trim((string)$s['buyer_name'])==='') $add('warning','HDR_NO_BUYER',"Buyer name is missing.");
    if (trim((string)($s['buyer_country'] ?? ''))==='') $add('info','HDR_NO_COUNTRY',"Buyer country is missing.");
    if (trim((string)($s['currency'] ?? ''))==='') $add('error','HDR_NO_CURRENCY',"Currency is not set for this shipment.");
    if (trim((string)($s['destination_port'] ?? ''))==='') $add('info','HDR_NO_PORT',"Destination port is empty.");
    if (trim((string)($s['payment_terms'] ?? ''))==='') $add('info','HDR_NO_TERMS',"Payment terms are empty.");
    if (!$items) $add('error','ITM_NONE',"This shipment has no invoice item lines.");

    /* ===== 2. PACKING PRE-SCAN ===== */
    $packedByItem = []; $serials = []; $grossByItem = [];
    foreach ($packs as $p) {
        $dk = pmk($p['product_name']).'|'.strtolower(trim((string)$p['des_col']));
        $packedByItem[$dk] = ($packedByItem[$dk] ?? 0) + (float)$p['total_qty'];
        $grossByItem[$dk]  = ($grossByItem[$dk] ?? 0) + (float)$p['gross_weight'];
        $net=(float)$p['net_weight']; $gross=(float)$p['gross_weight']; $from=(int)$p['carton_from']; $to=(int)$p['carton_to'];
        if ($gross>0 && $net>$gross+0.0001) $add('error','PK_NET_GT_GROSS',"Packing serial {$from}-{$to} ({$p['product_name']}): net {$net} > gross {$gross}.");
        if ($net<0 || $gross<0) $add('error','PK_NEG_WT',"Packing serial {$from}-{$to} ({$p['product_name']}): negative weight.");
        if ($gross>0 && $net>0 && $net<$gross*0.30) $add('info','PK_LOW_NET',"Packing serial {$from}-{$to} ({$p['product_name']}): net under 30% of gross — check tare.");
        if ($to<$from) $add('error','PK_SERIAL_REV',"Packing serial {$from}-{$to} ({$p['product_name']}) is reversed.");
        if ($from>0 && $to>=$from) for ($c=$from;$c<=$to;$c++){ if(isset($serials[$c])) $add('error','PK_SERIAL_DUP',"Carton serial #{$c} used twice — '{$serials[$c]}' and '{$p['product_name']}'."); else $serials[$c]=$p['product_name']; }
    }

    /* ===== 3-8. INVOICE LINES + MASTER + PRICE + PROFIT + MATERIAL ===== */
    $seen=[]; $invQty=0; $invAmt=0;
    $salesTotal=0; $costTotal=0; $costKnown=true; $costMissing=[];
    $matAgg=[]; $ambiguous=[]; $lineCosting=[]; // key: category|material|unit
    $cur = $s['currency'] ?: '';
    foreach ($items as $it) {
        $line=$it['line_no']; $name=trim((string)$it['product_name']);
        $qty=(float)$it['qty']; $rate=(float)$it['rate']; $amt=(float)$it['amount'];
        $invQty+=$qty; $invAmt+=$amt; $salesTotal+=$amt;

        if (in_array((int)$it['id'], $skipItemIds, true)) {
            // Locked — already final, skip matching/price-history/profitability entirely.
            $lineCosting[] = ['line'=>$line,'product_name'=>$name,'description'=>(string)($it['des_col'] ?? ''),'qty'=>$qty,'unit'=>(string)$it['unit'],'rate'=>$rate,
                'sales_amount'=>$amt,'matched_product'=>'','master_found'=>false,'match_pct'=>null,'size_matched'=>null,'costing_version'=>'','costing_version_id'=>null,'suggestions'=>[],
                'unit_cost'=>null,'cost_currency'=>'','line_cost'=>null,'gross_profit'=>null,'margin_pct'=>null,'note'=>'','materials'=>[]];
            continue;
        }

        if ($name==='') $add('error','ITM_NO_NAME',"Line $line: product/article name is empty.",$line);

        if (abs(($qty*$rate)-$amt)>max(0.02,abs($amt)*0.001)) $add('error','MATH_QTYRATE',"Line $line ($name): Qty × Rate = ".number_format($qty*$rate,2)." but Amount = ".number_format($amt,2).".",$line);
        if (abs($amt-round($amt,2))>0.00001) $add('info','MATH_DECIMALS',"Line $line ($name): amount has more than 2 decimals ($amt).",$line);
        if ($rate<0) $add('error','SIGN_RATE',"Line $line ($name): rate is negative (".number_format($rate,2).").",$line);
        if ($amt<0)  $add('error','SIGN_AMT',"Line $line ($name): amount is negative (".number_format($amt,2).").",$line);
        if ($qty<0)  $add('error','SIGN_QTY',"Line $line ($name): quantity is negative (".number_format($qty).").",$line);
        if ($qty==0) $add('warning','ZERO_QTY',"Line $line ($name): quantity is zero.",$line);
        if ($rate==0 && $amt==0) $add('warning','ZERO_RATEAMT',"Line $line ($name): rate and amount both zero.",$line);
        if (trim((string)$it['unit'])==='') $add('warning','ITM_NO_UNIT',"Line $line ($name): unit is missing.",$line);

        $dk = pmk($name).'|'.strtolower(trim((string)$it['des_col']));
        if ($name!==''){ if(isset($seen[$dk])) $add('warning','DUP_LINE',"Line $line ($name): duplicate product/description (also line {$seen[$dk]}).",$line); else $seen[$dk]=$line; }

        $lc = ['line'=>$line,'product_name'=>$name,'description'=>(string)($it['des_col'] ?? ''),'qty'=>$qty,'unit'=>(string)$it['unit'],'rate'=>$rate,
               'sales_amount'=>$amt,'matched_product'=>'','master_found'=>false,'match_pct'=>null,'size_matched'=>null,'costing_version'=>'','costing_version_id'=>null,'suggestions'=>[],
               'unit_cost'=>null,'cost_currency'=>'','line_cost'=>null,'gross_profit'=>null,'margin_pct'=>null,'note'=>'','materials'=>[]];

        $prod = $pm[pmk($name)] ?? null;
        $approxPct = 0; $approxVia = '';
        if (!$prod && $name!==''){
            // no exact match — use semantic/alias candidate so cost IS considered (flagged for confirmation)
            $mm = pm_match($name, (string)$it['des_col'], (string)($s['buyer_name'] ?? ''));
            // pm_match() already ranks up to 3 candidates internally (embedding
            // path) — surfacing them here costs nothing extra (no new query, no
            // new OpenAI call), it's just exposing data that was being computed
            // and thrown away. Drives the "Likely matches" quick-pick chips in
            // fc_render_line_card() so most mismatches need one click, not typing.
            if (!empty($mm['candidates'])) {
                $lc['suggestions'] = array_map(fn($c) => ['id' => (int)$c['product_id'], 'name' => (string)$c['product_name'], 'pct' => (int)round(($c['score'] ?? 0) * 100)], array_slice($mm['candidates'], 0, 3));
            }
            $candId = 0;
            if (($mm['method'] ?? '')==='confirmed_alias' && !empty($mm['product_id'])) { $candId=(int)$mm['product_id']; $approxVia='saved customer mapping'; $approxPct=100; }
            elseif (!empty($mm['candidates'])) { $candId=(int)$mm['candidates'][0]['product_id']; $approxPct=round(($mm['candidates'][0]['score'] ?? 0)*100); $approxVia=($mm['method']==='embedding'?'semantic match':'similar name'); }
            if ($candId && isset($pmById[$candId])) {
                $prod = $pmById[$candId];
                if ($approxVia==='saved customer mapping') $add('info','MASTER_ALIAS',"Line $line: '$name' → '{$prod['name']}' via saved customer mapping (reused).",$line);
                else { $add('warning','MASTER_CANDIDATE',"Line $line: '$name' matched to closest product '{$prod['name']}' ({$approxPct}% $approxVia). Cost/profit below uses this match — confirm it in Product Master.",$line);
                    $cands = array_map(fn($c)=>['name'=>$c['product_name'],'score'=>$c['score'] ?? null], array_slice($mm['candidates'] ?? [],0,3));
                    $ambiguous[] = ['line'=>$line,'invoice_text'=>trim($name.' '.$it['des_col']),'chosen'=>$prod['name'],'confidence'=>$approxPct,'candidates'=>$cands];
                }
            }
        }
        if ($prod) {
            $pid=(int)$prod['id'];
            $lc['master_found'] = true; $lc['matched_product'] = $prod['name']; $lc['matched_product_id'] = $pid; $lc['match_pct'] = $approxPct>0 ? $approxPct : 100;
            if ((int)$prod['is_active']===0) $add('warning','MASTER_INACTIVE',"Line $line: '$name' is INACTIVE in Product Master.",$line);
            $packed = $packedByItem[$dk] ?? 0;
            if ($packed>0 && abs($packed-$qty)>max(1,$qty*0.001)){ $lvl=$packed>$qty?'error':'warning'; $add($lvl,$packed>$qty?'PACK_OVER':'PACK_SHORT',"Line $line ($name): invoice qty ".number_format($qty)." vs packed ".number_format($packed).($packed>$qty?' — OVER-packed.':' — short/incomplete.'),$line); }
            if ((float)$prod['gross_weight']>0 && $packed>0 && ($grossByItem[$dk]??0)>0){ $perUnit=($grossByItem[$dk])/max(1,$packed); $std=(float)$prod['gross_weight']; if(abs($perUnit-$std)/$std>$th('weight_dev_pct',0.25)) $add('warning','WT_DEV',"Line $line ($name): packed gross ≈ ".number_format($perUnit,3)." kg/unit vs master ".number_format($std,3)." kg.",$line); }

            // price history — FIXED: only compares against past invoices in the
            // SAME currency, so a EUR/USD price never gets blended with PKR
            // history (previously this compared raw rate numbers across every
            // currency ever invoiced for the product, which is meaningless).
            try {
                $h=db()->prepare("SELECT AVG(rate) a,MIN(rate) mn,MAX(rate) mx,COUNT(*) c FROM shipment_items i JOIN shipments sp ON sp.id=i.shipment_id WHERE i.product_name=? AND sp.id<>? AND UPPER(COALESCE(sp.currency,'PKR'))=?");
                $h->execute([$name,$id,strtoupper($cur?:'PKR')]); $hr=$h->fetch();
                if ($hr && (int)$hr['c']>0 && (float)$hr['a']>0 && $rate>0){ $d=($rate-(float)$hr['a'])/(float)$hr['a']; if(abs($d)>$th('price_hist_pct',0.30)) $add('warning','PRICE_HIST',"Line $line ($name): rate ".number_format($rate,2)." is ".($d>0?'+':'').round($d*100)."% vs previous {$cur} avg ".number_format((float)$hr['a'],2)." (range ".number_format((float)$hr['mn'],2)."–".number_format((float)$hr['mx'],2).").",$line); }
            } catch (Throwable $e) {}

            // price vs Product Master — FIXED: prev_price is stored in the
            // product's OWN currency (products.currency), which is often
            // different from the invoice currency. Previously this compared
            // the two raw numbers directly, which is only valid by coincidence.
            $masterCur = $prod['currency'] ?: 'PKR';
            $prevPriceRaw = (float)($prod['prev_price'] ?? 0);
            if ($prevPriceRaw>0 && $rate>0){
                $sameCur = strtoupper($masterCur)===strtoupper($cur?:'PKR');
                $prevPriceConv = $sameCur ? $prevPriceRaw : cvt($prevPriceRaw, $masterCur, ($cur?:'PKR'), $FX);
                $pd = $prevPriceConv>0 ? ($rate-$prevPriceConv)/$prevPriceConv : 0;
                if(abs($pd)>$th('price_master_pct',0.40)){
                    $mnote = $sameCur ? "" : " (master price {$masterCur}".number_format($prevPriceRaw,2)." → {$cur}".number_format($prevPriceConv,2).")";
                    $add('warning','PRICE_MASTER',"Line $line ($name): rate ".number_format($rate,2)." is ".($pd>0?'+':'').round($pd*100)."% vs master price ".number_format($prevPriceConv,2).$mnote.".",$line);
                }
            }

            // profitability — pick costing version by SIZE (not just latest), convert to invoice currency
            $pickedC = pick_costing($costVersionsByPid[$pid] ?? [], $name.' '.$it['des_col']);
            if ($pickedC && (float)$pickedC['cv']['total_cost']>0){
                $cv = $pickedC['cv']; $sizeMatched = $pickedC['size_matched'];
                $costCur = $cv['currency'] ?: 'PKR';
                $canConvert = isset($FX[strtoupper($costCur)]) && isset($FX[strtoupper($cur?:'PKR')]);
                $unitCostRaw=(float)$cv['total_cost'];
                $unitCost = $canConvert ? cvt($unitCostRaw, $costCur, ($cur?:'PKR'), $FX) : $unitCostRaw;
                $lineCost=$unitCost*$qty; $costTotal+=$lineCost;
                $lc['size_matched']=$sizeMatched; $lc['costing_version']=$cv['version_name']; $lc['costing_version_id']=(int)$cv['id']; $lc['cost_currency']=$cur?:'PKR';
                $lc['unit_cost']=round($unitCost,4); $lc['line_cost']=round($lineCost,2);
                if (!$canConvert && strtoupper($costCur)!==strtoupper($cur?:'PKR'))
                    $add('warning','FX_MISSING',"Line $line ($name): cost is in {$costCur} but invoice is in {$cur}; no exchange rate configured.",$line);
                $sizeNote = $sizeMatched ? "" : " [size not matched — using '{$cv['version_name']}'; confirm the correct size costing]";
                if ($rate>0){ $gp=$rate-$unitCost; $margin=$rate>0?($gp/$rate*100):0;
                    $lc['gross_profit']=round($gp*$qty,2); $lc['margin_pct']=round($margin,2);
                    $cnote = (strtoupper($costCur)!==strtoupper($cur?:'PKR') && $canConvert) ? " (cost {$costCur}".number_format($unitCostRaw,2)." → {$cur}".number_format($unitCost,2).")" : "";
                    if ($rate<$unitCost) {
                        if ($sizeMatched) { $add('error','PROFIT_BELOW_COST',"Line $line ($name): selling {$cur}".number_format($rate,2)." is BELOW cost {$cur}".number_format($unitCost,2).$cnote." (loss ".number_format($unitCost-$rate,2)."/unit).",$line); $lc['note']='BELOW COST'; }
                        else { $add('warning','COST_SIZE_UNMATCHED',"Line $line ($name): appears below cost, but the costing size was NOT matched{$sizeNote}. Confirm the size-specific cost before treating as a loss.",$line); $lc['note']='size unmatched, appears below cost'; }
                    }
                    elseif ($margin<$th('min_margin_pct',8)) { $add('warning','PROFIT_LOW',"Line $line ($name): gross margin only ".round($margin,1)."% (cost {$cur}".number_format($unitCost,2).", sell {$cur}".number_format($rate,2).")".$cnote.$sizeNote.".",$line); $lc['note']='low margin'; }
                    elseif ($margin>$th('high_margin_pct',75)) { $add('info','PROFIT_HIGH',"Line $line ($name): unusually high margin ".round($margin,1)."% — verify rate.",$line); $lc['note']='unusually high margin'; }
                    elseif (!$sizeMatched) { $add('info','COST_SIZE_UNMATCHED',"Line $line ($name): costing size not matched{$sizeNote}.",$line); $lc['note']='size not matched'; }
                }
                // material utilisation from the picked costing version.
                // IMPORTANT: a costing line can be "shared" (e.g. one master carton
                // split across several pieces) — for those, product_costing.php's own
                // math is per-unit = rate/qty and weight/qty (qty here is the SHARE
                // COUNT, not a per-piece quantity), the opposite of a normal line
                // (per-unit = qty*rate / qty*weight). Ignoring this for shared lines
                // would multiply instead of divide, wildly overstating consumption.
                $lineMats = [];
                foreach (($matByVid[(int)$cv['id']] ?? []) as $ml){
                    $catg=$ml['line_group'] ?: 'Other'; $mn=trim((string)$ml['item_name'])?:$catg; $unit=trim((string)$ml['unit'])?:'unit';
                    $mq=(float)$ml['quantity']; $mrate=(float)$ml['rate']; $mwt=(float)$ml['weight_kg'];
                    $isShared = !empty($ml['shared']);
                    $eachQty = $isShared ? ($mq>0 ? 1/$mq : 0) : $mq;
                    $eachCost = $isShared ? ($mq>0 ? $mrate/$mq : 0) : ($mq*$mrate);
                    $eachWt = $isShared ? ($mq>0 ? $mwt/$mq : $mwt) : ($mq*$mwt);
                    if ($eachQty<=0 && $eachCost<=0) continue;
                    $rateConv = cvt($mrate, $costCur, ($cur?:'PKR'), $FX);
                    $totalQty = $eachQty*$qty; $totalWt = $eachWt*$qty;
                    $totalCostC = cvt($eachCost*$qty, $costCur, ($cur?:'PKR'), $FX);
                    // Per-line detail keeps rate as its own row (the real given rate
                    // for that specific costing line). The shipment-wide aggregate
                    // ($matAgg — used by AI Check's panel, the CSV, and a report's
                    // grand total) groups by category+material+unit ONLY, so the
                    // same material at a different price still combines into one
                    // summed row instead of splitting — price differences don't
                    // matter for "how much of this material did we use in total".
                    $kLine=$catg.'|'.$mn.'|'.$unit.'|'.round($rateConv,6);
                    $kAgg=$catg.'|'.$mn.'|'.$unit;
                    if(!isset($matAgg[$kAgg])) $matAgg[$kAgg]=['category'=>$catg,'material'=>$mn,'unit'=>$unit,'qty'=>0,'wt'=>0,'cost'=>0];
                    $matAgg[$kAgg]['qty']+=$totalQty; $matAgg[$kAgg]['wt']+=$totalWt; $matAgg[$kAgg]['cost']+=$totalCostC;
                    // 'rate' stays converted into the invoice currency — Final Costing's seed
                    // and every $ total downstream depend on that. 'rate_native' is the raw,
                    // as-entered rate in the material's own cost currency, matching what
                    // costing_print.php's own Rate/Amount columns show (e.g. PKR 535.00, not a
                    // converted USD 1.95) — used only for the per-line display table.
                    if(!isset($lineMats[$kLine])) $lineMats[$kLine]=['category'=>$catg,'material'=>$mn,'unit'=>$unit,'description'=>(string)($ml['description'] ?? ''),'rate'=>$rateConv,'rate_native'=>$mrate,'currency_native'=>$costCur,'consum_each'=>$eachQty,'wt_each'=>$eachWt,'qty'=>0,'wt'=>0,'cost'=>0,'shared'=>$isShared,'share_qty'=>$mq,'weight_raw'=>$mwt];
                    $lineMats[$kLine]['qty']+=$totalQty; $lineMats[$kLine]['wt']+=$totalWt; $lineMats[$kLine]['cost']+=$totalCostC;
                }
                $lc['materials'] = array_values($lineMats);
            } else { $costKnown=false; if($name!=='') $costMissing[$name]=true; $lc['note']='no costing found for this product/size'; }
        } else if ($name!==''){
            $add('info','MASTER_NOT_FOUND',"Line $line: '$name' is not in Product Master — no price/weight/cost reference. Tip: embed products in Product Master for smarter matching.",$line);
            $costKnown=false; $costMissing[$name]=true; $lc['note']='not found in Product Master';
        }
        $lineCosting[] = $lc;
    }

    /* ===== 9. PACKING ORPHANS + no-packing ===== */
    $invKeys=[]; foreach($items as $it) $invKeys[pmk($it['product_name']).'|'.strtolower(trim((string)$it['des_col']))]=true;
    $orph=[]; foreach($packs as $p){ $dk=pmk($p['product_name']).'|'.strtolower(trim((string)$p['des_col'])); if(!isset($invKeys[$dk]) && trim((string)$p['product_name'])!=='' && !isset($orph[$dk])){ $add('error','PACKING_NOT_IN_INVOICE',"Packing item '{$p['product_name']}' has no matching invoice line — it will not be billed."); $orph[$dk]=true; }
        $prod=$pm[pmk($p['product_name'])]??null; if($prod && (float)$prod['std_pack_qty']>0 && (float)$p['qty_per_carton']>0 && abs((float)$p['qty_per_carton']-(float)$prod['std_pack_qty'])>0.0001) $add('info','PACK_QTY_STD',"Packing '{$p['product_name']}' (serial {$p['carton_from']}-{$p['carton_to']}): {$p['qty_per_carton']}/carton vs standard {$prod['std_pack_qty']}."); }
    if ($packs) foreach($items as $it){ $dk=pmk($it['product_name']).'|'.strtolower(trim((string)$it['des_col'])); if(trim((string)$it['product_name'])!=='' && ($packedByItem[$dk]??0)==0) $add('warning','NO_PACKING',"Line {$it['line_no']} ({$it['product_name']}): no packing entered yet.",$it['line_no']); }

    /* ===== 12. TOTALS ===== */
    if ($invAmt<0) $add('error','TOTAL_NEG_AMT',"Invoice total amount is negative (".number_format($invAmt,2).").");
    if ($invQty<0) $add('error','TOTAL_NEG_QTY',"Invoice total quantity is negative (".number_format($invQty).").");
    if ($items && $invAmt==0) $add('warning','TOTAL_ZERO',"Invoice total amount is zero.");
    if ((float)$s['total_amount']<0) $add('error','TOTAL_SAVED_NEG',"Saved shipment total is negative (".number_format((float)$s['total_amount'],2).").");
    if (isset($s['total_amount']) && $items && abs((float)$s['total_amount']-$invAmt)>max(0.5,abs($invAmt)*0.001)) $add('warning','TOTAL_MISMATCH',"Saved header total ".number_format((float)$s['total_amount'],2)." differs from line sum ".number_format($invAmt,2)." — Recalculate & Save.");

    /* ===== 13. CBM ===== */
    $totalCbm=0; $cbmKnown=false;
    foreach ($items as $it){ $prod=$pm[pmk($it['product_name'])]??null; if($prod && (float)($prod['cbm_per_unit']??0)>0){ $totalCbm+=(float)$prod['cbm_per_unit']*(float)$it['qty']; $cbmKnown=true; } }
    $containerType=''; $util=0;
    $cbm40hc = (float)($config['container_40hc_cbm'] ?? 68); // same constant Product Master's FCL-capacity auto-calc uses
    if ($cbmKnown){ $add('info','CBM_TOTAL','Estimated total volume: '.number_format($totalCbm,3).' CBM (master CBM/unit × qty).');
        if($totalCbm>$cbm40hc){ $containerType='Multiple 40HC'; $util=100; $add('info','CBM_MULTI','Volume '.number_format($totalCbm,2).' CBM exceeds one 40ft HC (~'.$cbm40hc.' CBM) — multiple containers.'); }
        elseif($totalCbm>33){ $containerType='40FT'; $util=round($totalCbm/$cbm40hc*100); $add('info','CBM_40',"Volume ".number_format($totalCbm,2)." CBM fits a 40ft (~{$cbm40hc} CBM), ~{$util}% utilised."); }
        elseif($totalCbm>0){ $containerType='20FT'; $util=round($totalCbm/33*100); $add('info','CBM_20',"Volume ".number_format($totalCbm,2)." CBM fits a 20ft (~33 CBM), ~{$util}% utilised."); }
    }

    /* ===== FINANCIALS ===== */
    $grossProfit = $salesTotal - $costTotal;
    $grossMargin = $salesTotal>0 ? round($grossProfit/$salesTotal*100,2) : 0;
    if (!$costKnown && $items) $add('info','COST_INCOMPLETE','Profitability is partial: costing missing for '.count($costMissing).' product(s): '.implode(', ', array_slice(array_keys($costMissing),0,6)).(count($costMissing)>6?'…':'').'.');

    $materials = array_values($matAgg);
    usort($materials, fn($a,$b)=>strcmp($a['category'].$a['material'],$b['category'].$b['material']));

    $financials = ['currency'=>$cur,'sales_total'=>round($salesTotal,2),'estimated_cost_total'=>round($costTotal,2),'gross_profit'=>round($grossProfit,2),'gross_margin_percent'=>$grossMargin,'cost_complete'=>$costKnown];
    $container = ['cbm'=>round($totalCbm,3),'type'=>$containerType,'utilisation'=>$util];

    /* total estimated cost also shown in PKR (local) for reference */
    $costTotalPkr = cvt($costTotal, ($cur?:'PKR'), 'PKR', $FX);
    $salesTotalPkr = cvt($salesTotal, ($cur?:'PKR'), 'PKR', $FX);
    $financials['sales_total_pkr'] = round($salesTotalPkr,2);
    $financials['estimated_cost_total_pkr'] = round($costTotalPkr,2);
    $financials['gross_profit_pkr'] = round($salesTotalPkr - $costTotalPkr,2);

    /* overall shipment profit conclusion (appended at the end of the report) */
    if ($items) {
        $c2 = $cur ?: 'PKR';
        if ($costKnown) {
            $verdict = $grossProfit < 0 ? 'LOSS' : ($grossMargin < $th('min_margin_pct',8) ? 'LOW PROFIT' : 'PROFITABLE');
            $lvl = $grossProfit < 0 ? 'error' : ($grossMargin < $th('min_margin_pct',8) ? 'warning' : 'info');
            $add($lvl,'PROFIT_CONCLUSION',
                "Overall shipment: {$verdict}. Sales {$c2} ".number_format($salesTotal,2).", cost {$c2} ".number_format($costTotal,2).", gross profit {$c2} ".number_format($grossProfit,2)." (".$grossMargin."% margin). In PKR profit ₨".number_format($financials['gross_profit_pkr'],2).".");
        } else {
            $add('info','PROFIT_CONCLUSION',
                "Overall shipment: profit is PARTIAL — costing missing for ".count($costMissing)." product(s). Known-cost profit so far: {$c2} ".number_format($grossProfit,2)." (".$grossMargin."%). Add costing for a complete figure.");
        }
    }

    /* ===== SCORE + COUNTS (after conclusion so a LOSS verdict is counted) ===== */
    $firstErr=''; foreach($checks as $c){ if($c['level']==='error'){ $firstErr=$c['msg']; break; } }
    if (!array_filter($checks, fn($c)=>$c['level']!=='info')) $add('info','OK','No blocking issues found. Invoice and packing look consistent.');
    $nErr=0;$nWarn=0;$nInfo=0; foreach($checks as $c){ if($c['level']==='error')$nErr++; elseif($c['level']==='warning')$nWarn++; else $nInfo++; }
    $score = max(0, 100 - $nErr*(int)$th('err_deduct',20) - $nWarn*(int)$th('warn_deduct',6));
    $lock = $nErr>0 ? 'blocked' : 'allowed';

    /* ===== LOCAL URDU + ENGLISH SUMMARY (no OpenAI) ===== */
    $fin = $cur.' '.number_format($salesTotal,2).' sales, cost '.number_format($costTotal,2).', profit '.number_format($grossProfit,2)." ({$grossMargin}%)";
    if ($nErr>0){ $enSum="High risk: {$nErr} error(s), {$nWarn} warning(s). Most critical: {$firstErr} Score {$score}/100. Fix before locking. {$fin}."; $urSum="خطرہ: {$nErr} بڑی غلطی موجود ہے۔ لاک سے پہلے درست کریں۔ سب سے اہم مسئلہ: {$firstErr} منافع: {$fin} (اسکور: {$score}/100)"; }
    elseif ($nWarn>0){ $enSum="Review recommended: {$nWarn} warning(s), no blocking errors. Score {$score}/100. {$fin}."; $urSum="دھیان دیں: کوئی بڑی غلطی نہیں، مگر {$nWarn} تنبیہات ہیں۔ منافع: {$fin} (اسکور: {$score}/100)"; }
    else { $enSum="Good: no issues found. Score {$score}/100. {$fin}."; $urSum="سب ٹھیک ہے: کوئی مسئلہ نہیں ملا۔ منافع: {$fin} (اسکور: {$score}/100)"; }

    return [
        'shipment'=>$s,'items'=>$items,'checks'=>$checks,
        'score'=>$score,'lock_status'=>$lock,'most_critical_issue'=>$firstErr,
        'counts'=>['errors'=>$nErr,'warnings'=>$nWarn,'info'=>$nInfo],
        'financials'=>$financials,'materials'=>$materials,'container'=>$container,
        'ambiguous'=>$ambiguous,'line_costing'=>$lineCosting,
        'summary'=>['english'=>$enSum,'urdu'=>$urSum],
        'FX'=>$FX,
    ];
}
