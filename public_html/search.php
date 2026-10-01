<?php
require_once __DIR__ . '/includes/bootstrap.php';
require_once __DIR__ . '/includes/openai.php';
require_once __DIR__ . '/includes/costing.php';
require_once __DIR__ . '/includes/ai_costing.php';
require_once __DIR__ . '/includes/proforma_embed.php';
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

if ($q !== '') {
    $like = "%{$q}%";

    /* ============ COSTING MODE ============ */
    if ($mode === 'costing' && costing_perm('view')) {
        try {
            $csql = "SELECT cv.id, cv.costing_no, cv.version_name, cv.status, cv.currency, cv.total_cost, cv.suggested_price, cv.created_at,
                            p.id AS product_id, p.name AS product_name
                     FROM costing_versions cv
                     JOIN products p ON p.id = cv.product_id
                     WHERE p.name LIKE ? OR cv.costing_no LIKE ? OR cv.version_name LIKE ?
                        OR EXISTS (SELECT 1 FROM costing_lines cl WHERE cl.costing_version_id = cv.id AND cl.item_name LIKE ?)
                     ORDER BY cv.id DESC LIMIT 40";
            $cst = db()->prepare($csql);
            $cst->execute([$like, $like, $like, $like]);
            $costingMatches = $cst->fetchAll();
            if ($costingMatches) {
                $cids = array_column($costingMatches, 'id');
                $in = implode(',', array_fill(0, count($cids), '?'));
                $szst = db()->prepare("SELECT cvs.costing_version_id, ps.size_label FROM costing_version_sizes cvs JOIN product_sizes ps ON ps.id = cvs.product_size_id WHERE cvs.costing_version_id IN ($in)");
                $szst->execute($cids);
                $sizesByV = [];
                foreach ($szst->fetchAll() as $r) { $sizesByV[(int)$r['costing_version_id']][] = $r['size_label']; }
                foreach ($costingMatches as &$cm) { $cm['sizes'] = $sizesByV[(int)$cm['id']] ?? []; }
                unset($cm);
            }

            $queryVec = create_embedding($q);
            $costForRank = [];
            $costRows = db()->query("SELECT costing_version_id, embedded_text, embedding_vector FROM costing_embeddings")->fetchAll();
            foreach ($costRows as $r) {
                $vec = json_decode((string)$r['embedding_vector'], true);
                if (is_array($vec)) $costForRank[$r['costing_version_id']] = ['text' => $r['embedded_text'], 'vector' => $vec];
            }
            $topCost = $queryVec ? aic_rank_by_similarity($costForRank, $queryVec, 6) : [];

            $strongIds = array_column(array_filter($topCost, fn($c) => $c['score'] >= 0.55), 'key');
            $existingIds2 = array_column($costingMatches, 'id');
            $newIds = array_diff($strongIds, $existingIds2);
            if ($newIds) {
                $in = implode(',', array_fill(0, count($newIds), '?'));
                $extra = db()->prepare("SELECT cv.id, cv.costing_no, cv.version_name, cv.status, cv.currency, cv.total_cost, cv.suggested_price, cv.created_at,
                                                p.id AS product_id, p.name AS product_name
                                         FROM costing_versions cv JOIN products p ON p.id = cv.product_id
                                         WHERE cv.id IN ($in)");
                $extra->execute(array_values($newIds));
                $extraRows = $extra->fetchAll();
                if ($extraRows) {
                    $cids = array_column($extraRows, 'id');
                    $in2 = implode(',', array_fill(0, count($cids), '?'));
                    $szst = db()->prepare("SELECT cvs.costing_version_id, ps.size_label FROM costing_version_sizes cvs JOIN product_sizes ps ON ps.id = cvs.product_size_id WHERE cvs.costing_version_id IN ($in2)");
                    $szst->execute($cids);
                    $sizesByV = [];
                    foreach ($szst->fetchAll() as $r) { $sizesByV[(int)$r['costing_version_id']][] = $r['size_label']; }
                    foreach ($extraRows as &$er) { $er['sizes'] = $sizesByV[(int)$er['id']] ?? []; }
                    unset($er);
                    $costingMatches = array_merge($costingMatches, $extraRows);
                }
            }

            $chunks = [];
            foreach ($topCost as $c) $chunks[] = $c['text'];
            $topIds = array_column($topCost, 'key');
            foreach ($costingMatches as $cm) {
                if (in_array((int)$cm['id'], $topIds, true)) continue;
                $chunks[] = 'Costing ' . $cm['costing_no'] . ' for ' . $cm['product_name'] . ($cm['sizes'] ? ' (' . implode('/', $cm['sizes']) . ')' : '')
                    . ': status ' . $cm['status'] . ', total cost ' . $cm['currency'] . ' ' . number_format((float)$cm['total_cost'], 2)
                    . ($cm['suggested_price'] > 0 ? ', suggested price ' . $cm['currency'] . ' ' . number_format((float)$cm['suggested_price'], 2) : '') . '.';
            }
            if ($chunks) $answer = gpt_answer($q, array_slice($chunks, 0, 8));
        } catch (Throwable $e) { $answer = ''; }
        if ($answer === '') {
            $answer = $costingMatches ? 'Found ' . count($costingMatches) . ' costing record(s) for "' . $q . '". See the matched records below.' : 'No costing records matched "' . $q . '".';
        }
    }

    /* ============ PROFORMA MODE ============ */
    if ($mode === 'proforma' && costing_perm('proforma')) {
        try {
            $psql = "SELECT DISTINCT pf.id, pf.pi_no, pf.customer_name, pf.currency, pf.status, pf.created_at
                     FROM proforma_invoices pf
                     LEFT JOIN proforma_items pi ON pi.proforma_id = pf.id
                     WHERE pf.pi_no LIKE ? OR pf.customer_name LIKE ? OR pi.product_name LIKE ? OR pi.description LIKE ?
                     ORDER BY pf.id DESC LIMIT 40";
            $pst = db()->prepare($psql);
            $pst->execute([$like, $like, $like, $like]);
            $proformaMatches = $pst->fetchAll();
            if ($proformaMatches) {
                $totSt = db()->prepare("SELECT COALESCE(SUM(amount),0) FROM proforma_items WHERE proforma_id=?");
                foreach ($proformaMatches as &$pm) { $totSt->execute([$pm['id']]); $pm['total'] = (float)$totSt->fetchColumn(); }
                unset($pm);
            }

            $queryVec = create_embedding($q);
            $pfForRank = [];
            $pfRows = db()->query("SELECT proforma_id, embedded_text, embedding_vector FROM proforma_embeddings")->fetchAll();
            foreach ($pfRows as $r) {
                $vec = json_decode((string)$r['embedding_vector'], true);
                if (is_array($vec)) $pfForRank[$r['proforma_id']] = ['text' => $r['embedded_text'], 'vector' => $vec];
            }
            $topPf = $queryVec ? aic_rank_by_similarity($pfForRank, $queryVec, 6) : [];

            $strongIds = array_column(array_filter($topPf, fn($c) => $c['score'] >= 0.55), 'key');
            $existingIds2 = array_column($proformaMatches, 'id');
            $newIds = array_diff($strongIds, $existingIds2);
            if ($newIds) {
                $in = implode(',', array_fill(0, count($newIds), '?'));
                $extra = db()->prepare("SELECT id, pi_no, customer_name, currency, status, created_at FROM proforma_invoices WHERE id IN ($in)");
                $extra->execute(array_values($newIds));
                $extraRows = $extra->fetchAll();
                if ($extraRows) {
                    $totSt = db()->prepare("SELECT COALESCE(SUM(amount),0) FROM proforma_items WHERE proforma_id=?");
                    foreach ($extraRows as &$er) { $totSt->execute([$er['id']]); $er['total'] = (float)$totSt->fetchColumn(); }
                    unset($er);
                    $proformaMatches = array_merge($proformaMatches, $extraRows);
                }
            }

            $chunks = [];
            foreach ($topPf as $c) $chunks[] = $c['text'];
            $topIds = array_column($topPf, 'key');
            foreach ($proformaMatches as $pm) {
                if (in_array((int)$pm['id'], $topIds, true)) continue;
                $chunks[] = 'Proforma Invoice ' . $pm['pi_no'] . ' for ' . ($pm['customer_name'] ?: 'an unnamed customer')
                    . ': status ' . $pm['status'] . ', total ' . $pm['currency'] . ' ' . number_format((float)$pm['total'], 2) . '.';
            }
            if ($chunks) $answer = gpt_answer($q, array_slice($chunks, 0, 8));
        } catch (Throwable $e) { $answer = ''; }
        if ($answer === '') {
            $answer = $proformaMatches ? 'Found ' . count($proformaMatches) . ' proforma invoice(s) for "' . $q . '". See the matched records below.' : 'No proforma invoices matched "' . $q . '".';
        }
    }

    /* ============ SHIPMENT & PACKING MODE ============ */
    if ($mode === 'shipment') {
        try {
            $sql = "SELECT s.id, s.invoice_no, s.buyer_name, s.buyer_country, s.status, s.currency,
                           i.product_name AS ip, i.des_col AS idc, i.qty AS iqty, i.rate AS irate, i.amount AS iamount
                    FROM shipments s
                    LEFT JOIN shipment_items i ON i.shipment_id = s.id
                    WHERE s.status='approved_locked'
                      AND (s.invoice_no LIKE ? OR s.buyer_name LIKE ? OR s.buyer_country LIKE ? OR s.destination_port LIKE ? OR i.product_name LIKE ? OR i.des_col LIKE ?
                           OR EXISTS (SELECT 1 FROM packing_items pk WHERE pk.shipment_id = s.id AND (pk.product_name LIKE ? OR pk.des_col LIKE ?)))
                    ORDER BY s.id DESC LIMIT 60";
            $stmt = db()->prepare($sql);
            $stmt->execute([$like, $like, $like, $like, $like, $like, $like, $like]);
            $matches = $stmt->fetchAll();

            $queryVec = create_embedding($q);
            $shipForRank = [];
            $shipRows = db()->query("SELECT id, chunk_text, vector_json FROM shipment_embeddings WHERE is_active=1 ORDER BY id DESC LIMIT 500")->fetchAll();
            foreach ($shipRows as $r) {
                $vec = json_decode((string)$r['vector_json'], true);
                if (is_array($vec)) $shipForRank[$r['id']] = ['text' => $r['chunk_text'], 'vector' => $vec];
            }
            $topShip = $queryVec ? aic_rank_by_similarity($shipForRank, $queryVec, 6) : [];
            $chunks = [];
            foreach ($topShip as $s) $chunks[] = $s['text'];
            if ($chunks) $answer = gpt_answer($q, array_slice($chunks, 0, 8));
        } catch (Throwable $e) { $answer = ''; }
        if ($answer === '') {
            $answer = $matches ? 'Found ' . count($matches) . ' shipment line item(s) for "' . $q . '". See the matched records below.' : 'No records matched "' . $q . '".';
        }
    }
}

$examples = [
    'costing' => ['fitted sheet costing', 'draft costings this month', 'highest cost per unit'],
    'proforma' => ['socks order for Aruf Group', 'unsent proformas', 'GBP proformas over 10000'],
    'shipment' => ['UAE shipments', 'blankets packing', 'recent invoices'],
];
$placeholders = [
    'costing' => 'Ask about a costing… e.g. fitted sheet costing',
    'proforma' => 'Ask about a proforma… e.g. socks order for Aruf Group',
    'shipment' => 'Ask about a shipment or packing… e.g. blankets packing to UAE',
];
$modeLabels = ['costing' => 'Costing', 'proforma' => 'Proforma Invoice', 'shipment' => 'Shipment & Packing'];
$modeNotes = [
    'costing' => 'Searching saved Costing Versions — draft, approved and locked',
    'proforma' => 'Searching Proforma Invoices — draft, sent, confirmed and archived',
    'shipment' => 'Searching approved &amp; locked Shipments — invoice items and packing list together',
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

  <?php if($answer): ?>
  <div class="ai-answer">
    <div class="ai-card">
      <div style="display:flex;align-items:center;gap:10px;margin-bottom:12px"><div class="ai-badge"></div><span style="font-weight:700;font-size:13.5px">ZAS Textile AI</span></div>
      <p style="margin:0;font-size:14.5px;line-height:1.7;color:#152033"><span id="aiTyped"></span><span style="color:#0ea8c9">&#9613;</span></p>
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
