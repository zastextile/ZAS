<?php
/*
  AI Check — two-stage invoice/packing validation.
  STAGE 1 (this file, no OpenAI): all deterministic rule checks, costing,
           material utilisation, totals, CBM, health score, Urdu/English summary.
  STAGE 2 (optional OpenAI): a short management summary, only when
           enable_ai_analysis + ai_analysis_mode != 'rules_only', cached by data hash.
  OpenAI failure NEVER blocks — Stage 1 always returns.
*/
require_once __DIR__ . '/includes/bootstrap.php';
require_once __DIR__ . '/includes/openai.php';
require_once __DIR__ . '/includes/pm_search.php';
require_login();
header('Content-Type: application/json; charset=utf-8');

if (!is_admin()) { echo json_encode(['checks'=>[['level'=>'info','code'=>'acl','msg'=>'AI Check is available to Admin only.']]]); exit; }

global $config;
$TH = $config['ai_thresholds'] ?? [];
$th = fn($k,$d)=> (float)($TH[$k] ?? $d);

$id = (int)($_GET['id'] ?? 0);
$st = db()->prepare("SELECT * FROM shipments WHERE id=?"); $st->execute([$id]); $s = $st->fetch();
if (!$s || !can_view_shipment($id)) { echo json_encode(['checks'=>[['level'=>'error','code'=>'notfound','msg'=>'Shipment not found.']]]); exit; }

$st = db()->prepare("SELECT * FROM shipment_items WHERE shipment_id=? ORDER BY line_no,id"); $st->execute([$id]); $items = $st->fetchAll();
$st = db()->prepare("SELECT * FROM packing_items WHERE shipment_id=? ORDER BY line_no,id"); $st->execute([$id]); $packs = $st->fetchAll();

/* Product Master + latest costing per product (batched, no queries in loops) */
$pm = []; $pmById = [];
try { foreach (db()->query("SELECT * FROM products")->fetchAll() as $p) { $pm[strtolower(trim(preg_replace('/[^a-z0-9]+/i',' ',$p['name'])))] = $p; $pmById[(int)$p['id']] = $p; } } catch (Throwable $e) {}
$costByPid = []; $matByPid = []; $costVersionsByPid = []; $matByVid = [];
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
        foreach ($costByPid as $pid=>$cv) $matByPid[$pid] = $matByVid[(int)$cv['id']] ?? [];
    }
} catch (Throwable $e) {}
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
function pmk($n){ return strtolower(trim(preg_replace('/[^a-z0-9]+/i',' ', (string)$n))); }
/* currency conversion via PKR base (fx = units of currency per 1 PKR),
   live-synced weekly from Frankfurter, falls back to config on failure */
$FX = fx_get_rates();
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
$matAgg=[]; $ambiguous=[]; // ambiguous product/size matches for AI suggestion // key: category|material|unit
$cur = $s['currency'] ?: '';
foreach ($items as $it) {
    $line=$it['line_no']; $name=trim((string)$it['product_name']);
    $qty=(float)$it['qty']; $rate=(float)$it['rate']; $amt=(float)$it['amount'];
    $invQty+=$qty; $invAmt+=$amt; $salesTotal+=$amt;
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

    $prod = $pm[pmk($name)] ?? null;
    $approxPct = 0; $approxVia = '';
    if (!$prod && $name!==''){
        // no exact match — use semantic/alias candidate so cost IS considered (flagged for confirmation)
        $mm = pm_match($name, (string)$it['des_col'], (string)($s['buyer_name'] ?? ''));
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
        if ((int)$prod['is_active']===0) $add('warning','MASTER_INACTIVE',"Line $line: '$name' is INACTIVE in Product Master.",$line);
        $packed = $packedByItem[$dk] ?? 0;
        if ($packed>0 && abs($packed-$qty)>max(1,$qty*0.001)){ $lvl=$packed>$qty?'error':'warning'; $add($lvl,$packed>$qty?'PACK_OVER':'PACK_SHORT',"Line $line ($name): invoice qty ".number_format($qty)." vs packed ".number_format($packed).($packed>$qty?' — OVER-packed.':' — short/incomplete.'),$line); }
        if ((float)$prod['gross_weight']>0 && $packed>0 && ($grossByItem[$dk]??0)>0){ $perUnit=($grossByItem[$dk])/max(1,$packed); $std=(float)$prod['gross_weight']; if(abs($perUnit-$std)/$std>$th('weight_dev_pct',0.25)) $add('warning','WT_DEV',"Line $line ($name): packed gross ≈ ".number_format($perUnit,3)." kg/unit vs master ".number_format($std,3)." kg.",$line); }
        // price history (aggregated only)
        try {
            $h=db()->prepare("SELECT AVG(rate) a,MIN(rate) mn,MAX(rate) mx,COUNT(*) c FROM shipment_items i JOIN shipments sp ON sp.id=i.shipment_id WHERE i.product_name=? AND sp.id<>?");
            $h->execute([$name,$id]); $hr=$h->fetch();
            if ($hr && (int)$hr['c']>0 && (float)$hr['a']>0 && $rate>0){ $d=($rate-(float)$hr['a'])/(float)$hr['a']; if(abs($d)>$th('price_hist_pct',0.30)) $add('warning','PRICE_HIST',"Line $line ($name): rate ".number_format($rate,2)." is ".($d>0?'+':'').round($d*100)."% vs previous avg ".number_format((float)$hr['a'],2)." (range ".number_format((float)$hr['mn'],2)."–".number_format((float)$hr['mx'],2).").",$line); }
        } catch (Throwable $e) {}
        if ((float)($prod['prev_price']??0)>0 && $rate>0){ $pd=($rate-(float)$prod['prev_price'])/(float)$prod['prev_price']; if(abs($pd)>$th('price_master_pct',0.40)) $add('warning','PRICE_MASTER',"Line $line ($name): rate ".number_format($rate,2)." is ".($pd>0?'+':'').round($pd*100)."% vs master price ".number_format((float)$prod['prev_price'],2).".",$line); }

        // profitability — pick costing version by SIZE (not just latest), convert to invoice currency
        $pickedC = pick_costing($costVersionsByPid[$pid] ?? [], (string)($it['size'] ?? '').' '.$name.' '.$it['des_col']);
        if ($pickedC && (float)$pickedC['cv']['total_cost']>0){
            $cv = $pickedC['cv']; $sizeMatched = $pickedC['size_matched'];
            $costCur = $cv['currency'] ?: 'PKR';
            $canConvert = isset($FX[strtoupper($costCur)]) && isset($FX[strtoupper($cur?:'PKR')]);
            $unitCostRaw=(float)$cv['total_cost'];
            $unitCost = $canConvert ? cvt($unitCostRaw, $costCur, ($cur?:'PKR'), $FX) : $unitCostRaw;
            $lineCost=$unitCost*$qty; $costTotal+=$lineCost;
            if (!$canConvert && strtoupper($costCur)!==strtoupper($cur?:'PKR'))
                $add('warning','FX_MISSING',"Line $line ($name): cost is in {$costCur} but invoice is in {$cur}; no exchange rate configured.",$line);
            $sizeNote = $sizeMatched ? "" : " [size not matched — using '{$cv['version_name']}'; confirm the correct size costing]";
            if ($rate>0){ $gp=$rate-$unitCost; $margin=$rate>0?($gp/$rate*100):0;
                $cnote = (strtoupper($costCur)!==strtoupper($cur?:'PKR') && $canConvert) ? " (cost {$costCur}".number_format($unitCostRaw,2)." → {$cur}".number_format($unitCost,2).")" : "";
                if ($rate<$unitCost) {
                    if ($sizeMatched) $add('error','PROFIT_BELOW_COST',"Line $line ($name): selling {$cur}".number_format($rate,2)." is BELOW cost {$cur}".number_format($unitCost,2).$cnote." (loss ".number_format($unitCost-$rate,2)."/unit).",$line);
                    else $add('warning','COST_SIZE_UNMATCHED',"Line $line ($name): appears below cost, but the costing size was NOT matched{$sizeNote}. Confirm the size-specific cost before treating as a loss.",$line);
                }
                elseif ($margin<$th('min_margin_pct',8)) $add('warning','PROFIT_LOW',"Line $line ($name): gross margin only ".round($margin,1)."% (cost {$cur}".number_format($unitCost,2).", sell {$cur}".number_format($rate,2).")".$cnote.$sizeNote.".",$line);
                elseif ($margin>$th('high_margin_pct',75)) $add('info','PROFIT_HIGH',"Line $line ($name): unusually high margin ".round($margin,1)."% — verify rate.",$line);
                elseif (!$sizeMatched) $add('info','COST_SIZE_UNMATCHED',"Line $line ($name): costing size not matched{$sizeNote}.",$line);
            }
            // material utilisation from the picked costing version
            foreach (($matByVid[(int)$cv['id']] ?? []) as $ml){
                $catg=$ml['line_group'] ?: 'Other'; $mn=trim((string)$ml['item_name'])?:$catg; $unit=trim((string)$ml['unit'])?:'unit';
                $req=(float)$ml['quantity']*$qty; if($req<=0) continue;
                $lineCostC = cvt((float)$ml['rate']*$req, $costCur, ($cur?:'PKR'), $FX);
                $k=$catg.'|'.$mn.'|'.$unit;
                if(!isset($matAgg[$k])) $matAgg[$k]=['category'=>$catg,'material'=>$mn,'unit'=>$unit,'qty'=>0,'cost'=>0];
                $matAgg[$k]['qty']+=$req; $matAgg[$k]['cost']+=$lineCostC;
            }
        } else { $costKnown=false; if($name!=='') $costMissing[$name]=true; }
    } else if ($name!==''){
        $add('info','MASTER_NOT_FOUND',"Line $line: '$name' is not in Product Master — no price/weight/cost reference. Tip: embed products in Product Master for smarter matching.",$line);
        $costKnown=false; $costMissing[$name]=true;
    }
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
if ($cbmKnown){ $add('info','CBM_TOTAL','Estimated total volume: '.number_format($totalCbm,3).' CBM (master CBM/unit × qty).');
    if($totalCbm>68){ $containerType='Multiple 40HC'; $util=100; $add('info','CBM_MULTI','Volume '.number_format($totalCbm,2).' CBM exceeds one 40ft HC (~68 CBM) — multiple containers.'); }
    elseif($totalCbm>33){ $containerType='40FT'; $util=round($totalCbm/68*100); $add('info','CBM_40',"Volume ".number_format($totalCbm,2)." CBM fits a 40ft (~68 CBM), ~{$util}% utilised."); }
    elseif($totalCbm>0){ $containerType='20FT'; $util=round($totalCbm/33*100); $add('info','CBM_20',"Volume ".number_format($totalCbm,2)." CBM fits a 20ft (~33 CBM), ~{$util}% utilised."); }
}

/* ===== FINANCIALS ===== */
$grossProfit = $salesTotal - $costTotal;
$grossMargin = $salesTotal>0 ? round($grossProfit/$salesTotal*100,2) : 0;
if (!$costKnown && $items) $add('info','COST_INCOMPLETE','Profitability is partial: costing missing for '.count($costMissing).' product(s): '.implode(', ', array_slice(array_keys($costMissing),0,6)).(count($costMissing)>6?'…':'').'.');

/* ===== SEVERITY SORT ===== */
$order=['error'=>0,'warning'=>1,'info'=>2];
usort($checks, fn($a,$b)=>($order[$a['level']]??9)<=>($order[$b['level']]??9));

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
            "Overall shipment: {$verdict}. Sales {$c2} ".number_format($salesTotal,2).", cost {$c2} ".number_format($costTotal,2).", gross profit {$c2} ".number_format($grossProfit,2)." (".$grossMargin."% margin). In PKR profit \u20a8".number_format($financials['gross_profit_pkr'],2).".");
    } else {
        $add('info','PROFIT_CONCLUSION',
            "Overall shipment: profit is PARTIAL \u2014 costing missing for ".count($costMissing)." product(s). Known-cost profit so far: {$c2} ".number_format($grossProfit,2)." (".$grossMargin."%). Add costing for a complete figure.");
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

/* ===== STAGE 2: OpenAI management summary (cached, economical, optional) ===== */
$mode = $config['ai_analysis_mode'] ?? 'economy';
$matchSuggestions = [];
$usage = ['ai_called'=>false,'skipped_reason'=>'','model'=>'','input_tokens'=>0,'output_tokens'=>0,'total_tokens'=>0,'estimated_cost'=>0,'response_time_ms'=>0,'cache_used'=>false];

// self-healing cache table
try { db()->exec("CREATE TABLE IF NOT EXISTS ai_check_cache (shipment_id INT PRIMARY KEY, data_hash VARCHAR(64), payload MEDIUMTEXT, created_at DATETIME DEFAULT CURRENT_TIMESTAMP) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4"); } catch (Throwable $e) {}
$dataHash = md5(json_encode([$items,$packs,$score,$nErr,$nWarn,$financials,$materials,$container,$mode]));

if (empty($config['enable_ai_analysis']) || $mode==='rules_only') {
    $usage['skipped_reason'] = 'AI analysis disabled or rules_only mode — local summary used.';
} else {
    // reuse cache if data unchanged
    $cached=null;
    try { $cq=db()->prepare("SELECT data_hash,payload FROM ai_check_cache WHERE shipment_id=?"); $cq->execute([$id]); $cached=$cq->fetch(); } catch (Throwable $e) {}
    if ($cached && $cached['data_hash']===$dataHash){ $cp=json_decode($cached['payload'],true); if(is_array($cp)){ $enSum=$cp['en']??$enSum; $urSum=$cp['ur']??$urSum; $matchSuggestions=$cp['sug']??[]; $usage=array_merge($usage,$cp['usage']??[]); $usage['cache_used']=true; $usage['ai_called']=false; } }
    else {
        try {
            $model = ($mode==='detailed') ? ($config['ai_check_model_hi'] ?? 'gpt-4o') : ($config['ai_check_model'] ?? 'gpt-4o-mini');
            $compact = ['task'=>'invoice_risk_summary','shipment'=>['invoice_number'=>$s['invoice_no'],'currency'=>$cur,'buyer_country'=>$s['buyer_country']??''],'score'=>$score,'counts'=>['errors'=>$nErr,'warnings'=>$nWarn,'info'=>$nInfo],'critical_findings'=>array_slice(array_map(fn($c)=>['code'=>$c['code'],'message'=>$c['msg']],array_filter($checks,fn($c)=>$c['level']==='error')),0,5),'financial_summary'=>$financials,'container_summary'=>$container,'material_summary'=>array_slice($materials,0,8),'ambiguous_matches'=>array_slice($ambiguous,0,6)];
            $prompt = "You are a textile export analyst. Given this compact JSON, reply ONLY strict JSON {\"english\":\"...\",\"urdu\":\"...\",\"match_suggestions\":[{\"line\":n,\"suggested_product\":\"...\",\"reason\":\"...\"}]}. english/urdu = manager summary max 45 words each (status, most critical issue, profit/margin, next action). For every entry in ambiguous_matches, pick the most sensible Product Master product+size from its candidates using textile knowledge (fabric/size/type sense) and give a short reason; if none fits, say create new product. Do not translate codes, numbers, currency or units in urdu.\n\nDATA:\n".json_encode($compact,JSON_UNESCAPED_UNICODE);
            $t0=microtime(true);
            $resp = openai_request('chat/completions', ['model'=>$model,'messages'=>[['role'=>'user','content'=>$prompt]],'max_tokens'=>(int)($config['ai_check_max_tokens']??400),'temperature'=>0.2,'response_format'=>['type'=>'json_object']]);
            $usage['response_time_ms']=(int)round((microtime(true)-$t0)*1000);
            $content = $resp['choices'][0]['message']['content'] ?? '';
            $parsed = json_decode($content,true);
            if (is_array($parsed)){ if(!empty($parsed['english'])) $enSum=$parsed['english']; if(!empty($parsed['urdu'])) $urSum=$parsed['urdu']; if(!empty($parsed['match_suggestions'])) $matchSuggestions=$parsed['match_suggestions']; }
            $it_tok=(int)($resp['usage']['prompt_tokens']??0); $ot_tok=(int)($resp['usage']['completion_tokens']??0);
            $usage['ai_called']=true; $usage['model']=$model; $usage['input_tokens']=$it_tok; $usage['output_tokens']=$ot_tok; $usage['total_tokens']=$it_tok+$ot_tok;
            $usage['estimated_cost']=round($it_tok/1e6*(float)($config['ai_price_in_per_m']??0.15)+$ot_tok/1e6*(float)($config['ai_price_out_per_m']??0.60),6);
            try { db()->prepare("REPLACE INTO ai_check_cache (shipment_id,data_hash,payload,created_at) VALUES (?,?,?,NOW())")->execute([$id,$dataHash,json_encode(['en'=>$enSum,'ur'=>$urSum,'usage'=>$usage,'sug'=>$matchSuggestions ?? []])]); } catch (Throwable $e) {}
        } catch (Throwable $e) {
            // OpenAI failed — keep local summary, never block
            $usage['skipped_reason']='AI unavailable — showing local analysis ('.substr($e->getMessage(),0,80).').';
        }
    }
}

echo json_encode([
    'status'=>'completed','shipment_id'=>$id,'score'=>$score,'lock_status'=>$lock,
    'counts'=>['errors'=>$nErr,'warnings'=>$nWarn,'info'=>$nInfo],
    'errors'=>$nErr,'warnings'=>$nWarn,'info'=>$nInfo,
    'ai'=>$urSum,'summary'=>['english'=>$enSum,'urdu'=>$urSum],
    'most_critical_issue'=>$firstErr,
    'financials'=>$financials,'materials'=>$materials,'container'=>$container,
    'ambiguous'=>$ambiguous,'match_suggestions'=>$matchSuggestions,
    'checks'=>$checks,'openai_usage'=>$usage,
], JSON_UNESCAPED_UNICODE);
