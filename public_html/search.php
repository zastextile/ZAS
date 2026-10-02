<?php
require_once __DIR__ . '/includes/bootstrap.php';
require_once __DIR__ . '/includes/openai.php';
require_once __DIR__ . '/includes/costing.php';
require_once __DIR__ . '/includes/ai_costing.php';
require_once __DIR__ . '/includes/proforma_embed.php';
require_once __DIR__ . '/includes/export.php';
require_once __DIR__ . '/includes/qrouter.php';
require_login();

if (is_staff() || is_production_staff()) {
    http_response_code(403);
    exit('Staff cannot use AI search.');
}

$mode = $_GET['mode'] ?? $_POST['mode'] ?? 'costing';
if (!in_array($mode, ['costing', 'proforma', 'shipment'], true)) $mode = 'costing';
$q = trim($_GET['q'] ?? $_POST['q'] ?? '');
$answer = '';
$matches = [];
$costingMatches = [];
$proformaMatches = [];

function ai_sym($cur){ $m=['USD'=>'$','EUR'=>'€','GBP'=>'£','PKR'=>'₨']; return $m[strtoupper((string)$cur)] ?? (($cur?$cur.' ':'')); }
function ai_money($cur,$v){ return ai_sym($cur).number_format((float)$v,2); }

/* =======================================================================
   THE ROUTER REPLACES WHAT STOOD HERE.
   =======================================================================

   What this used to do, on every search in every mode: a LIKE query, then
   create_embedding() on the question, then every stored vector loaded into
   PHP and ranked, then gpt_answer(). Two OpenAI calls, unconditionally, with
   no cap, no cache and no counter — even for "ZAS/5191", which is a lookup.

   Now the question is parsed into filters and answered from the tables. The
   paid routes still exist, but only behind a button, and only while the
   monthly cap has room. The render below is unchanged: it is fed the same
   $costingMatches / $proformaMatches / $matches it always was.
   ===================================================================== */

$t0       = microtime(true);
$parsed   = ['mode' => $mode, 'filters' => [], 'used' => [], 'leftover' => ''];
$chips    = [];
$describing = false;
$route    = 'none';
$spent    = 0;
$budget   = qr_budget();
$wantExplain  = isset($_GET['explain']) || isset($_POST['explain']);
$wantSemantic = isset($_GET['semantic']) || isset($_POST['semantic']);
$paidNote = '';

if ($q !== '') {
    $parsed = qr_parse($mode, $q);
    $chips  = qr_chips($parsed);
    $describing = qr_is_description($parsed);
    [$where, $wparams] = qr_where($mode, $parsed['filters']);

    /* A question with no filter and words left over is a DESCRIPTION, not a
       request for the whole table. Showing everything would look like an
       answer and would not be one. */
    if ($describing) {
        $route = 'description';
    } else {
        $route = 'filters';

        if ($mode === 'costing' && costing_perm('view')) {
            try {
                $sql = "SELECT cv.id, cv.costing_no, cv.version_name, cv.status, cv.currency,
                               cv.total_cost, cv.suggested_price, cv.created_at,
                               p.id AS product_id, p.name AS product_name
                        FROM costing_versions cv JOIN products p ON p.id = cv.product_id";
                if ($where !== '') $sql .= " WHERE $where";
                $sql .= " ORDER BY cv.id DESC LIMIT 60";
                $st = db()->prepare($sql); $st->execute($wparams);
                $costingMatches = $st->fetchAll();

                if ($costingMatches) {
                    $ids = array_column($costingMatches, 'id');
                    $in  = implode(',', array_fill(0, count($ids), '?'));
                    $sz  = db()->prepare("SELECT cvs.costing_version_id, ps.size_label
                                          FROM costing_version_sizes cvs
                                          JOIN product_sizes ps ON ps.id = cvs.product_size_id
                                          WHERE cvs.costing_version_id IN ($in)");
                    $sz->execute($ids);
                    $bySize = [];
                    foreach ($sz->fetchAll() as $r) $bySize[$r['costing_version_id']][] = $r['size_label'];
                    foreach ($costingMatches as &$cmRow) $cmRow['sizes'] = implode(', ', $bySize[$cmRow['id']] ?? []);
                    unset($cmRow);
                }
            } catch (Throwable $e) { $costingMatches = []; }
        }

        if ($mode === 'proforma' && costing_perm('proforma')) {
            try {
                $sql = "SELECT pf.id, pf.pi_no, pf.customer_name, pf.currency, pf.status, pf.created_at
                        FROM proforma_invoices pf";
                if ($where !== '') $sql .= " WHERE $where";
                $sql .= " ORDER BY pf.id DESC LIMIT 60";
                $st = db()->prepare($sql); $st->execute($wparams);
                $proformaMatches = $st->fetchAll();
                foreach ($proformaMatches as &$pfRow) {
                    $tot = db()->prepare("SELECT COALESCE(SUM(amount),0) FROM proforma_items WHERE proforma_id=?");
                    $tot->execute([$pfRow['id']]);
                    $pfRow['total'] = (float)$tot->fetchColumn();
                }
                unset($pfRow);
            } catch (Throwable $e) { $proformaMatches = []; }
        }

        if ($mode === 'shipment') {
            try {
                /* DRAFTS ARE INCLUDED NOW. The old query had
                   s.status='approved_locked' hard-coded, so a search for a
                   draft returned "no records matched" — which reads as "it
                   does not exist" rather than "I am not looking there".
                   Visibility is still can_view_shipment(): a colleague sees
                   all, staff never reach this page. */
                $scope = ''; $sparams = [];
                if (!is_admin()) {
                    $ids = assigned_shipment_ids();
                    if ($ids === ['ALL']) { /* colleague: everything */ }
                    elseif (!$ids) { $scope = ' AND 1=0'; }
                    else {
                        $scope = ' AND s.id IN (' . implode(',', array_fill(0, count($ids), '?')) . ')';
                        $sparams = $ids;
                    }
                }

                $sql = "SELECT s.id, s.invoice_no, s.buyer_name, s.buyer_country, s.status, s.currency,
                               s.invoice_date, s.destination_port, s.total_amount, s.logistics_status,
                               i.product_name AS ip, i.des_col AS idc, i.qty AS iqty,
                               i.rate AS irate, i.amount AS iamount
                        FROM shipments s
                        LEFT JOIN shipment_items i ON i.shipment_id = s.id
                        WHERE " . ($where !== '' ? "($where)" : '1=1') . $scope . "
                        ORDER BY s.id DESC LIMIT 200";
                $st = db()->prepare($sql);
                $st->execute(array_merge($wparams, $sparams));
                $matches = $st->fetchAll();
            } catch (Throwable $e) { $matches = []; }
        }
    }

    /* ----------------------------------------------- the two paid routes

       Neither runs unless a button was pressed AND the monthly cap has room.
       Semantic reaches locked records only — that is the existing embedding
       cost control, unchanged. */
    $rowCount = $mode === 'costing' ? count($costingMatches)
              : ($mode === 'proforma' ? count($proformaMatches) : count($matches));

    if (($wantExplain || $wantSemantic) && !$budget['ok']) {
        $paidNote = 'The monthly AI allowance of ' . (int)$budget['cap'] . ' calls is used up. '
                  . 'Filter searches are unaffected and still free. An admin can raise the cap in Settings.';
    } elseif ($wantSemantic) {
        try {
            $vec = create_embedding($q);
            qr_spend(1); $spent++;
            $rank = [];
            if ($mode === 'shipment') {
                /* PRE-FILTERED, which is the whole point. If the filters found
                   twelve shipments we rank twelve vectors, not five hundred.
                   The old code loaded every row it could and still went blind
                   past the newest 500 without saying so. */
                $sqlv = "SELECT e.id, e.shipment_id, e.chunk_text, e.vector_json
                         FROM shipment_embeddings e WHERE e.is_active=1";
                $vp = [];
                if (!empty($matches)) {
                    $sids = array_values(array_unique(array_map(fn($m) => (int)$m['id'], $matches)));
                    $sqlv .= " AND e.shipment_id IN (" . implode(',', array_fill(0, count($sids), '?')) . ")";
                    $vp = $sids;
                } else {
                    $sqlv .= " ORDER BY e.id DESC LIMIT 300";
                }
                $vs = db()->prepare($sqlv); $vs->execute($vp);
                foreach ($vs->fetchAll() as $r) {
                    $v = json_decode((string)$r['vector_json'], true);
                    if (is_array($v)) $rank[$r['id']] = ['text' => $r['chunk_text'], 'vector' => $v];
                }
            } elseif ($mode === 'costing') {
                $vs = db()->query("SELECT costing_version_id, embedded_text, embedding_vector
                                   FROM costing_embeddings ORDER BY costing_version_id DESC LIMIT 300");
                foreach ($vs->fetchAll() as $r) {
                    $v = json_decode((string)$r['embedding_vector'], true);
                    if (is_array($v)) $rank[$r['costing_version_id']] = ['text' => $r['embedded_text'], 'vector' => $v];
                }
            } else {
                $vs = db()->query("SELECT proforma_id, embedded_text, embedding_vector
                                   FROM proforma_embeddings ORDER BY proforma_id DESC LIMIT 300");
                foreach ($vs->fetchAll() as $r) {
                    $v = json_decode((string)$r['embedding_vector'], true);
                    if (is_array($v)) $rank[$r['proforma_id']] = ['text' => $r['embedded_text'], 'vector' => $v];
                }
            }
            $top = $vec ? aic_rank_by_similarity($rank, $vec, 6) : [];
            $chunks = [];
            foreach ($top as $tRow) $chunks[] = $tRow['text'];
            if ($chunks) {
                $answer = gpt_answer($q, array_slice($chunks, 0, 8));
                qr_spend(1); $spent++;
            } else {
                $answer = 'Nothing close enough was found by meaning. Remember that only approved and '
                        . 'locked records are embedded — a draft can be found by filters but not this way.';
            }
            $route = 'semantic';
        } catch (Throwable $e) {
            $answer = 'The meaning search could not run just now. Your filter results are unaffected.';
        }
    } elseif ($wantExplain) {
        try {
            $lines = [];
            if ($mode === 'shipment') {
                foreach (array_slice($matches, 0, 40) as $m) {
                    $lines[] = $m['invoice_no'] . ' | ' . $m['buyer_name'] . ' | ' . $m['buyer_country']
                             . ' | ' . $m['status'] . ' | ' . $m['currency'] . ' ' . $m['total_amount']
                             . ' | ' . $m['ip'] . ' ' . $m['iqty'];
                }
            } elseif ($mode === 'costing') {
                foreach ($costingMatches as $cmX) {
                    $lines[] = $cmX['costing_no'] . ' | ' . $cmX['product_name'] . ' | ' . $cmX['status']
                             . ' | cost ' . $cmX['total_cost'] . ' | price ' . $cmX['suggested_price'];
                }
            } else {
                foreach ($proformaMatches as $pmX) {
                    $lines[] = $pmX['pi_no'] . ' | ' . $pmX['customer_name'] . ' | ' . $pmX['status']
                             . ' | ' . $pmX['currency'] . ' ' . ($pmX['total'] ?? 0);
                }
            }
            if ($lines) {
                $answer = gpt_answer($q, $lines);
                qr_spend(1); $spent++;
                $route = 'explain';
            }
        } catch (Throwable $e) {
            $answer = 'The explanation could not be written just now. Your results are unaffected.';
        }
    }

    if ($answer === '') {
        if ($describing) {
            $answer = 'No filter was recognised in that, so nothing was looked up — and nothing was spent. '
                    . 'If "' . $parsed['leftover'] . '" is a description rather than a code, '
                    . 'Search by meaning is the button for it.';
        } else {
            $answer = $rowCount
                ? 'Found ' . $rowCount . ' record' . ($rowCount === 1 ? '' : 's') . ' from the database. No AI was used.'
                : 'Nothing matched those filters.';
        }
    }

    qr_log($mode, $q, $parsed, $rowCount, $route, $spent, (int)round((microtime(true) - $t0) * 1000));
    $budget = qr_budget();
}

$examples = [
    'costing' => ['draft costings', 'costings over 1200', 'costings this month'],
    'proforma' => ['draft proformas', 'GBP proformas over 10000', 'proformas last month'],
    'shipment' => ['payment outstanding', 'partially paid invoices', 'shipments in transit',
                   'draft shipments', 'freight above 3000'],
];
$placeholders = [
    'costing' => 'Ask about a costing… e.g. fitted sheet costing',
    'proforma' => 'Ask about a proforma… e.g. socks order for Aruf Group',
    'shipment' => 'Ask about a shipment or packing… e.g. blankets packing to UAE',
];
$modeLabels = ['costing' => 'Costing', 'proforma' => 'Proforma Invoice', 'shipment' => 'Shipment & Packing'];
$modeNotes = [
    'costing' => 'Searching saved Costing Versions — filters first, no AI unless you ask',
    'proforma' => 'Searching Proforma Invoices — filters first, no AI unless you ask',
    'shipment' => 'Searching every shipment you may see — draft, submitted and locked',
];

/* Line-item matches (shipment mode only) + per-currency totals + source grouping */
$lineMatches = array_values(array_filter($matches, fn($m) => trim((string)($m['ip'] ?? '')) !== ''));
$totalsByCur = [];
foreach ($lineMatches as $m) {
    $c = strtoupper((string)($m['currency'] ?: ''));
    if (!isset($totalsByCur[$c])) $totalsByCur[$c] = ['qty'=>0,'val'=>0];
    $totalsByCur[$c]['qty'] += (float)$m['iqty'];
    $totalsByCur[$c]['val'] += (float)$m['iamount'];
}
$sources = [];
foreach ($matches as $m) {
    $sid = (int)$m['id'];
    if (!isset($sources[$sid])) $sources[$sid] = ['invoice_no'=>$m['invoice_no'],'buyer'=>$m['buyer_name'],'country'=>$m['buyer_country'],'status'=>$m['status'],'currency'=>$m['currency'],'val'=>0,'n'=>0];
    if (trim((string)($m['ip'] ?? '')) !== '') { $sources[$sid]['val'] += (float)$m['iamount']; $sources[$sid]['n']++; }
}

page_header('AI Search');
flash();
?>
<style>
@keyframes zfloat { 0%,100%{transform:translateY(0)} 50%{transform:translateY(-12px)} }
@keyframes zspin { to { transform: rotate(360deg); } }
@keyframes zpulse { 0%,100%{transform:scale(1)} 50%{transform:scale(1.05)} }
@keyframes zwave { 0%,100%{transform:scaleY(.4)} 50%{transform:scaleY(1)} }
@keyframes zshim { to { background-position: 200% 0; } }
@keyframes zrise { from{opacity:0;transform:translateY(14px)} to{opacity:1;transform:none} }
.ai-wrap{display:flex;flex-direction:column;align-items:center;text-align:center;padding:20px 0 10px}
.ai-orb{position:relative;width:158px;height:158px;cursor:pointer;animation:zfloat 5.5s ease-in-out infinite;border:none;background:transparent;padding:0}
.ai-orb .ring{position:absolute;inset:-8px;border-radius:50%;background:conic-gradient(from 0deg,#0ea8c9,#6d5bd0,#e0435d,#16a34a,#0ea8c9);filter:blur(11px);animation:zspin 6s linear infinite}
.ai-orb .core{position:absolute;inset:9px;border-radius:50%;background:radial-gradient(circle at 35% 30%,#1a2150,#080b20);display:flex;align-items:center;justify-content:center;animation:zpulse 2.6s ease-in-out infinite}
.ai-tabs{display:flex;flex-wrap:wrap;gap:8px;justify-content:center;margin-top:18px}
.ai-tab{padding:9px 16px;border-radius:14px;border:1.5px solid #cbd5e3;background:#ffffff;color:#5a6b82;cursor:pointer;font-size:12.8px;font-weight:700;font-family:inherit;text-decoration:none;display:inline-flex;align-items:center}
.ai-tab.active{border-color:transparent;color:#fff;background:linear-gradient(100deg,#0ea8c9,#6d5bd0)}
.ai-tab:not(.active):hover{border-color:rgba(14,168,201,.4)}
.ai-tabnote{color:#8a97ab;font-size:12px;margin-top:8px}
.ai-searchbar{width:100%;max-width:620px;margin-top:16px;display:flex;gap:10px;padding:8px;border-radius:16px;background:#ffffff;border:1px solid #cbd5e3}
.ai-searchbar input{flex:1;border:none;background:transparent;color:#152033;font-size:14px;outline:none;padding:8px 12px}
.ai-ask{padding:10px 20px;border:none;border-radius:11px;cursor:pointer;font-weight:700;color:#fff;background:linear-gradient(100deg,#0ea8c9,#6d5bd0);background-size:200% 100%;animation:zshim 3.5s linear infinite}
.ai-pills{display:flex;flex-wrap:wrap;gap:8px;justify-content:center;margin-top:14px}
.ai-pill{padding:7px 13px;border-radius:20px;border:1px solid #cbd5e3;background:#f6f8fc;color:#33415c;cursor:pointer;font-size:12.5px;font-family:inherit;transition:.15s;text-decoration:none}
.ai-pill:hover{border-color:rgba(14,168,201,.4);background:rgba(14,168,201,.1)}
.ai-answer{max-width:820px;margin:26px auto 0;animation:zrise .5s ease both}
.ai-card{padding:22px;border-radius:18px;background:#ffffff;border:1px solid #cbd5e3;backdrop-filter:blur(12px)}
.ai-badge{width:26px;height:26px;border-radius:8px;background:conic-gradient(from 210deg,#0ea8c9,#6d5bd0,#e0435d,#0ea8c9)}
.ai-hint{margin-top:22px;font-size:15px;color:#33415c;font-weight:600;min-height:22px}
.ai-waves{display:flex;align-items:center;gap:5px;height:44px;margin-top:16px}
.ai-waves span{width:5px;height:34px;border-radius:4px;background:linear-gradient(180deg,#0ea8c9,#6d5bd0);transform-origin:center;animation:zwave 900ms ease-in-out infinite}
.ai-health{max-width:820px;margin:34px auto 0;padding:16px 18px;border-radius:14px;background:#ffffff;border:1px solid #e3e9f2;font-size:12.5px}
.ai-health h3{margin:0 0 10px;font-size:11px;text-transform:uppercase;letter-spacing:.05em;color:#8a97ab}
.ai-health-row{display:flex;align-items:center;gap:10px;justify-content:space-between;padding:7px 0;border-top:1px solid #f6f8fc}
.ai-health-row:first-of-type{border-top:none}
.ai-health-stat{color:#5a6b82}
.ai-health-btn{padding:6px 12px;border-radius:9px;border:1px solid #cbd5e3;background:#f6f8fc;color:#152033;font-size:11.5px;font-weight:700;cursor:pointer;font-family:inherit}
.ai-health-btn:disabled{opacity:.6;cursor:default}
</style>

<div class="topbar"><div><h1>ZAS Textile AI Search</h1><p class="lead">Choose what to search, then ask a question</p></div></div>

<div class="ai-wrap">
  <form method="get" id="aiForm" style="display:contents">
    <input type="hidden" name="mode" id="modeField" value="<?= e($mode) ?>">
    <button type="submit" class="ai-orb" title="Tap to search">
      <span class="ring"></span>
      <span class="core">
        <svg width="46" height="46" viewBox="0 0 24 24" fill="none" stroke="#0ea8c9" stroke-width="1.5"><path d="M12 2.5l1.9 5.6L19.5 10l-5.6 1.9L12 17.5l-1.9-5.6L4.5 10l5.6-1.9z"/><circle cx="18.6" cy="5.4" r="1.15" fill="#0ea8c9"/></svg>
      </span>
    </button>
    <div class="ai-hint"><?= $q ? 'Results for "'.e($q).'"' : 'Tap the orb or type a question' ?></div>
    <?php if($q !== ''): ?>
    <div class="ai-waves">
      <?php for($w=0;$w<9;$w++): ?><span style="animation-delay:<?= $w*90 ?>ms"></span><?php endfor; ?>
    </div>
    <?php endif; ?>

    <div class="ai-tabs">
      <?php foreach ($modeLabels as $mk => $ml): ?>
        <a href="search.php?mode=<?= e($mk) ?><?= $q!=='' ? '&q='.urlencode($q) : '' ?>" class="ai-tab<?= $mode===$mk?' active':'' ?>"><?= e($ml) ?></a>
      <?php endforeach; ?>
    </div>
    <div class="ai-tabnote"><?= $modeNotes[$mode] ?></div>

    <div class="ai-searchbar">
      <input name="q" value="<?= e($q) ?>" placeholder="<?= e($placeholders[$mode]) ?>" autocomplete="off">
      <button type="submit" class="ai-ask">Ask</button>
    </div>
    <div class="ai-pills">
      <?php foreach($examples[$mode] as $ex): ?>
        <a href="search.php?mode=<?= e($mode) ?>&q=<?= urlencode($ex) ?>" class="ai-pill"><?= e($ex) ?></a>
      <?php endforeach; ?>
    </div>
  </form>

  <?php if($chips || ($q !== '' && $parsed['leftover'] !== '')): ?>
  <div style="max-width:820px;margin:16px auto 0;display:flex;flex-wrap:wrap;gap:7px;justify-content:center">
    <?php foreach($chips as $c): ?>
      <span style="padding:5px 12px;border-radius:16px;font-size:11.5px;font-weight:600;background:rgba(47,127,224,.13);color:#2f7fe0"><?= e($c[0]) ?> <b><?= e($c[1]) ?></b></span>
    <?php endforeach; ?>
    <?php if($parsed['leftover'] !== ''): ?>
      <span style="padding:5px 12px;border-radius:16px;font-size:11.5px;background:#f6f8fc;color:#8a97ab;border:1px solid #e3e9f2">not understood: <?= e($parsed['leftover']) ?></span>
    <?php endif; ?>
  </div>
  <?php endif; ?>

  <?php if($answer): ?>
  <div class="ai-answer">
    <div class="ai-card">
      <div style="display:flex;align-items:center;gap:10px;margin-bottom:12px"><div class="ai-badge"></div><span style="font-weight:700;font-size:13.5px">ZAS Textile AI</span></div>
      <p style="margin:0;font-size:14.5px;line-height:1.7;color:#152033"><span id="aiTyped"></span><span style="color:#0ea8c9">&#9613;</span></p>

      <?php
      /* THE ONLY TWO THINGS ON THIS PAGE THAT COST MONEY, and both need a
         press. Before this, every search spent two calls whether you wanted
         them or not. */
      $rowsNow = $mode === 'costing' ? count($costingMatches)
               : ($mode === 'proforma' ? count($proformaMatches) : count($matches));
      $qs = 'search.php?mode=' . urlencode($mode) . '&q=' . urlencode($q);
      ?>
      <div style="display:flex;gap:9px;flex-wrap:wrap;align-items:center;margin-top:14px;padding-top:13px;border-top:1px solid #e3e9f2">
        <?php if($spent === 0): ?>
          <span style="font-size:11.5px;font-weight:700;color:#16a34a;background:rgba(22,163,74,.12);padding:5px 11px;border-radius:14px">Answered from your database &middot; 0 AI calls</span>
        <?php else: ?>
          <span style="font-size:11.5px;font-weight:700;color:#d97706;background:rgba(217,119,6,.12);padding:5px 11px;border-radius:14px"><?= (int)$spent ?> AI call<?= $spent === 1 ? '' : 's' ?> used &middot; you pressed for it</span>
        <?php endif; ?>

        <?php if($q !== '' && $route !== 'explain' && $rowsNow > 0 && $budget['ok']): ?>
          <a class="ai-pill" href="<?= e($qs) ?>&explain=1">Explain these results</a>
        <?php endif; ?>
        <?php if($q !== '' && $route !== 'semantic' && ($rowsNow === 0 || $describing) && $budget['ok']): ?>
          <a class="ai-pill" href="<?= e($qs) ?>&semantic=1">Search by meaning instead</a>
        <?php endif; ?>

        <span style="font-size:11px;color:#8a97ab"><?= (int)$budget['left'] ?> of <?= (int)$budget['cap'] ?> AI calls left this month</span>
      </div>

      <?php if($paidNote !== ''): ?>
        <div style="margin-top:11px;padding:9px 12px;border-radius:10px;background:rgba(217,119,6,.1);border:1px solid rgba(217,119,6,.3);font-size:12px;color:#9a5a06"><?= e($paidNote) ?></div>
      <?php endif; ?>
    </div>

    <?php if($mode==='costing' && $costingMatches): ?>
    <div style="color:#8a97ab;font-size:12px;margin:20px 4px 10px;text-transform:uppercase;letter-spacing:.06em">Costing Matches · <?= count($costingMatches) ?> found</div>
    <div style="border-radius:16px;background:#ffffff;border:1px solid #e3e9f2;backdrop-filter:blur(12px);overflow-x:auto">
      <table style="width:100%;border-collapse:collapse;font-size:13px;min-width:640px">
        <thead><tr style="text-align:left;color:#8a97ab;font-size:11px;text-transform:uppercase;letter-spacing:.05em">
          <th style="padding:11px 14px">Costing Ref</th><th style="padding:11px 14px">Product</th><th style="padding:11px 14px">Size(s)</th><th style="padding:11px 14px">Date</th><th style="padding:11px 14px;text-align:right">Total Cost</th><th style="padding:11px 14px;text-align:right">Suggested Price</th><th style="padding:11px 14px">Status</th><th></th></tr></thead>
        <tbody>
        <?php foreach($costingMatches as $cm): ?>
          <tr onclick="location.href='product_costing.php?product_id=<?= (int)$cm['product_id'] ?>'" style="cursor:pointer;border-top:1px solid #f6f8fc" onmouseover="this.style.background='#f6f8fc'" onmouseout="this.style.background='transparent'">
            <td style="padding:12px 14px;font-weight:600;color:#0ea8c9"><?= e($cm['costing_no']) ?></td>
            <td style="padding:12px 14px"><div style="font-weight:600"><?= e($cm['product_name']) ?></div><div style="font-size:11.5px;color:#8a97ab"><?= e($cm['version_name']) ?></div></td>
            <td style="padding:12px 14px;color:#5a6b82"><?= e($cm['sizes'] ? implode(', ', $cm['sizes']) : '—') ?></td>
            <td style="padding:12px 14px;color:#5a6b82"><?= e($cm['created_at'] ? date('d M Y', strtotime($cm['created_at'])) : '—') ?></td>
            <td style="padding:12px 14px;text-align:right;font-weight:600"><?= e(ai_money($cm['currency'],$cm['total_cost'])) ?></td>
            <td style="padding:12px 14px;text-align:right"><?= (float)$cm['suggested_price'] > 0 ? e(ai_money($cm['currency'],$cm['suggested_price'])) : '—' ?></td>
            <td style="padding:12px 14px"><?= e(ucfirst($cm['status'] ?: 'draft')) ?></td>
            <td style="padding:12px 14px"><a class="ai-pill" style="text-decoration:none" href="product_costing.php?product_id=<?= (int)$cm['product_id'] ?>" onclick="event.stopPropagation()">Open</a></td>
          </tr>
        <?php endforeach; ?>
        </tbody>
      </table>
    </div>
    <?php endif; ?>

    <?php if($mode==='proforma' && $proformaMatches): ?>
    <div style="color:#8a97ab;font-size:12px;margin:20px 4px 10px;text-transform:uppercase;letter-spacing:.06em">Proforma Matches · <?= count($proformaMatches) ?> found</div>
    <div style="border-radius:16px;background:#ffffff;border:1px solid #e3e9f2;backdrop-filter:blur(12px);overflow-x:auto">
      <table style="width:100%;border-collapse:collapse;font-size:13px;min-width:560px">
        <thead><tr style="text-align:left;color:#8a97ab;font-size:11px;text-transform:uppercase;letter-spacing:.05em">
          <th style="padding:11px 14px">PI No.</th><th style="padding:11px 14px">Customer</th><th style="padding:11px 14px">Date</th><th style="padding:11px 14px;text-align:right">Total</th><th style="padding:11px 14px">Status</th><th></th></tr></thead>
        <tbody>
        <?php foreach($proformaMatches as $pm): ?>
          <tr onclick="location.href='proforma.php?id=<?= (int)$pm['id'] ?>'" style="cursor:pointer;border-top:1px solid #f6f8fc" onmouseover="this.style.background='#f6f8fc'" onmouseout="this.style.background='transparent'">
            <td style="padding:12px 14px;font-weight:600;color:#0ea8c9"><?= e($pm['pi_no']) ?></td>
            <td style="padding:12px 14px;color:#5a6b82"><?= e($pm['customer_name'] ?: '—') ?></td>
            <td style="padding:12px 14px;color:#5a6b82"><?= e($pm['created_at'] ? date('d M Y', strtotime($pm['created_at'])) : '—') ?></td>
            <td style="padding:12px 14px;text-align:right;font-weight:600;color:#0ea8c9"><?= e(ai_money($pm['currency'],$pm['total'])) ?></td>
            <td style="padding:12px 14px"><?= e(ucfirst($pm['status'] ?: 'draft')) ?></td>
            <td style="padding:12px 14px"><a class="ai-pill" style="text-decoration:none" href="proforma.php?id=<?= (int)$pm['id'] ?>" onclick="event.stopPropagation()">Open</a></td>
          </tr>
        <?php endforeach; ?>
        </tbody>
      </table>
    </div>
    <?php endif; ?>

    <?php if($mode==='shipment' && $lineMatches): ?>
    <div style="color:#8a97ab;font-size:12px;margin:20px 4px 10px;text-transform:uppercase;letter-spacing:.06em">Matched Line Items · <?= count($lineMatches) ?> found</div>
    <div style="border-radius:16px;background:#ffffff;border:1px solid #e3e9f2;backdrop-filter:blur(12px);overflow-x:auto">
      <table style="width:100%;border-collapse:collapse;font-size:13px;min-width:600px">
        <thead><tr style="text-align:left;color:#8a97ab;font-size:11px;text-transform:uppercase;letter-spacing:.05em">
          <th style="padding:11px 14px">Product / Article</th><th style="padding:11px 14px">Invoice</th><th style="padding:11px 14px">Buyer</th><th style="padding:11px 14px;text-align:right">Qty</th><th style="padding:11px 14px;text-align:right">Rate</th><th style="padding:11px 14px;text-align:right">Value</th></tr></thead>
        <tbody>
        <?php foreach($lineMatches as $m): ?>
          <tr onclick="location.href='shipment_view.php?id=<?= (int)$m['id'] ?>'" style="cursor:pointer;border-top:1px solid #f6f8fc" onmouseover="this.style.background='#f6f8fc'" onmouseout="this.style.background='transparent'">
            <td style="padding:12px 14px"><div style="font-weight:600"><?= e($m['ip']) ?></div><?php if(trim((string)$m['idc'])!==''): ?><div style="font-size:11.5px;color:#8a97ab"><?= e($m['idc']) ?></div><?php endif; ?></td>
            <td style="padding:12px 14px;font-weight:600;color:#0ea8c9"><?= e($m['invoice_no']) ?></td>
            <td style="padding:12px 14px;color:#5a6b82"><?= e($m['buyer_name']) ?></td>
            <td style="padding:12px 14px;text-align:right"><?= e(number_format((float)$m['iqty'])) ?></td>
            <td style="padding:12px 14px;text-align:right"><?= e(ai_money($m['currency'],$m['irate'])) ?></td>
            <td style="padding:12px 14px;text-align:right;font-weight:600;color:#0ea8c9"><?= e(ai_money($m['currency'],$m['iamount'])) ?></td>
          </tr>
        <?php endforeach; ?>
        </tbody>
        <tfoot>
        <?php foreach($totalsByCur as $cur=>$t): ?>
          <tr style="border-top:2px solid #cbd5e3"><td colspan="3" style="padding:12px 14px;font-weight:700"><?= count($totalsByCur)>1 ? 'Subtotal · '.e($cur?:'—') : 'Combined' ?></td><td style="padding:12px 14px;text-align:right;font-weight:700"><?= e(number_format((float)$t['qty'])) ?></td><td></td><td style="padding:12px 14px;text-align:right;font-weight:700;color:#0ea8c9"><?= e(ai_money($cur,$t['val'])) ?></td></tr>
        <?php endforeach; ?>
        </tfoot>
      </table>
    </div>
    <?php endif; ?>

    <?php if($mode==='shipment' && $sources): ?>
    <div style="color:#8a97ab;font-size:12px;margin:20px 4px 10px;text-transform:uppercase;letter-spacing:.06em">Source Records</div>
    <div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(260px,1fr));gap:14px">
      <?php foreach($sources as $sid=>$s):
        $badge = $s['status']==='approved_locked'
          ? 'padding:4px 10px;border-radius:20px;font-size:11px;font-weight:600;background:rgba(22,163,74,.16);color:#16a34a;border:1px solid rgba(22,163,74,.3)'
          : 'padding:4px 10px;border-radius:20px;font-size:11px;font-weight:600;background:#e3e9f2;color:#33415c;border:1px solid #cbd5e3';
      ?>
      <div onclick="location.href='shipment_view.php?id=<?= (int)$sid ?>'" style="padding:18px;border-radius:16px;background:#ffffff;border:1px solid #e3e9f2;cursor:pointer" onmouseover="this.style.borderColor='rgba(14,168,201,.4)'" onmouseout="this.style.borderColor='#e3e9f2'">
        <div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:8px"><span style="font-weight:700;color:#0ea8c9"><?= e($s['invoice_no']) ?></span><span style="<?= $badge ?>">Approved &amp; Locked</span></div>
        <div style="font-size:13.5px;font-weight:600"><?= e($s['buyer']) ?></div>
        <div style="font-size:12.5px;color:#5a6b82;margin-top:3px"><?= e($s['country'] ?: '—') ?> · <?= e(ai_money($s['currency'],$s['val'])) ?></div>
        <div style="font-size:12px;color:#8a97ab;margin-top:8px"><?= (int)$s['n'] ?> matching line(s) in this record</div>
      </div>
      <?php endforeach; ?>
    </div>
    <?php endif; ?>
  </div>
  <?php endif; ?>

  <?php if (is_admin()): ?>
  <div class="ai-health">
    <h3>Embedding Health (admin only)</h3>
    <div class="ai-health-row"><span>Costing</span><span class="ai-health-stat" id="healthCosting">checking…</span><button class="ai-health-btn" id="btnCosting" onclick="embedAllDomain('costing')">Embed All Pending</button></div>
    <div class="ai-health-row"><span>Proforma Invoice</span><span class="ai-health-stat" id="healthProforma">checking…</span><button class="ai-health-btn" id="btnProforma" onclick="embedAllDomain('proforma')">Embed All Pending</button></div>
    <div class="ai-health-row"><span>Shipment &amp; Packing</span><span class="ai-health-stat" id="healthShipment">checking…</span><button class="ai-health-btn" id="btnShipment" onclick="embedAllDomain('shipment')">Embed All Pending</button></div>
  </div>
  <?php endif; ?>
</div>

<?php if($answer): ?>
<script>
(function(){
  var txt = <?= json_encode($answer) ?>;
  var el = document.getElementById('aiTyped');
  if(!el) return;
  if (window.matchMedia && window.matchMedia('(prefers-reduced-motion: reduce)').matches){ el.textContent = txt; return; }
  var i = 0;
  (function tick(){
    el.textContent = txt.slice(0, i);
    if (i++ < txt.length) setTimeout(tick, 14);
  })();
})();
</script>
<?php endif; ?>

<?php if (is_admin()): ?>
<script>
var EMB_ENDPOINT = {costing:'costing_embed_batch.php', proforma:'proforma_embed_batch.php', shipment:'shipment_embed_batch.php'};
var EMB_CSRF = <?= json_encode(csrf_token()) ?>;
function loadHealth(domain){
  fetch(EMB_ENDPOINT[domain] + '?action=pending').then(function(r){return r.json()}).then(function(d){
    var el = document.getElementById('health' + domain.charAt(0).toUpperCase() + domain.slice(1));
    if (!el) return;
    if (!d.ok) { el.textContent = 'unavailable'; return; }
    el.textContent = d.current + ' / ' + d.total + ' embedded' + (d.pending_count ? ' · ' + d.pending_count + ' pending' : '');
  }).catch(function(){});
}
function embedAllDomain(domain){
  var btn = document.getElementById('btn' + domain.charAt(0).toUpperCase() + domain.slice(1));
  btn.disabled = true; var orig = btn.textContent;
  fetch(EMB_ENDPOINT[domain] + '?action=pending').then(function(r){return r.json()}).then(function(d){
    var ids = d.pending || [];
    if (!ids.length) { btn.textContent = 'All current'; setTimeout(function(){btn.textContent = orig; btn.disabled = false;}, 1500); return; }
    var total = ids.length, done = 0;
    function batch(){
      var chunk = ids.splice(0, 25);
      if (!chunk.length) { btn.textContent = orig; btn.disabled = false; loadHealth(domain); return; }
      var fd = new FormData(); fd.append('action','embed_batch'); fd.append('ids', JSON.stringify(chunk)); fd.append('_csrf', EMB_CSRF);
      fetch(EMB_ENDPOINT[domain], {method:'POST', body:fd}).then(function(r){return r.json()}).then(function(){
        done += chunk.length; btn.textContent = 'Embedding ' + done + '/' + total + '…'; batch();
      }).catch(function(){ btn.textContent = 'Retry'; btn.disabled = false; });
    }
    batch();
  }).catch(function(){ btn.disabled = false; });
}
loadHealth('costing'); loadHealth('proforma'); loadHealth('shipment');
</script>
<?php endif; ?>

<?php page_footer(); ?>
