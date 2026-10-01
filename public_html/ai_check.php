<?php
/*
  AI Check — two-stage invoice/packing validation.
  STAGE 1 (includes/ai_check_core.php): all deterministic rule checks, costing,
           material utilisation, totals, CBM, health score, Urdu/English summary.
           Shared with ai_check_export.php so the on-screen panel and the
           downloadable CSV report always agree.
  STAGE 2 (optional OpenAI): a short management summary, only when
           enable_ai_analysis + ai_analysis_mode != 'rules_only', cached by data hash.
  OpenAI failure NEVER blocks — Stage 1 always returns.
*/
require_once __DIR__ . '/includes/bootstrap.php';
require_once __DIR__ . '/includes/openai.php';
require_once __DIR__ . '/includes/pm_search.php';
require_once __DIR__ . '/includes/ai_check_core.php';
require_login();
header('Content-Type: application/json; charset=utf-8');

if (!is_admin()) { echo json_encode(['checks'=>[['level'=>'info','code'=>'acl','msg'=>'AI Check is available to Admin only.']]]); exit; }

$id = (int)($_GET['id'] ?? 0);
$result = ai_check_compute($id);
if (!$result) { echo json_encode(['checks'=>[['level'=>'error','code'=>'notfound','msg'=>'Shipment not found.']]]); exit; }

$s = $result['shipment']; $checks = $result['checks']; $score = $result['score']; $lock = $result['lock_status'];
$nErr = $result['counts']['errors']; $nWarn = $result['counts']['warnings']; $nInfo = $result['counts']['info'];
$firstErr = $result['most_critical_issue']; $financials = $result['financials']; $materials = $result['materials'];
$container = $result['container']; $ambiguous = $result['ambiguous']; $cur = $s['currency'] ?: '';
$enSum = $result['summary']['english']; $urSum = $result['summary']['urdu'];

/* ===== STAGE 2: OpenAI management summary (cached, economical, optional) ===== */
global $config;
$mode = $config['ai_analysis_mode'] ?? 'economy';
$matchSuggestions = [];
$usage = ['ai_called'=>false,'skipped_reason'=>'','model'=>'','input_tokens'=>0,'output_tokens'=>0,'total_tokens'=>0,'estimated_cost'=>0,'response_time_ms'=>0,'cache_used'=>false];

// self-healing cache table
try { db()->exec("CREATE TABLE IF NOT EXISTS ai_check_cache (shipment_id INT PRIMARY KEY, data_hash VARCHAR(64), payload MEDIUMTEXT, created_at DATETIME DEFAULT CURRENT_TIMESTAMP) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4"); } catch (Throwable $e) {}
$dataHash = md5(json_encode([$result['items'],$score,$nErr,$nWarn,$financials,$materials,$container,$mode]));

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
