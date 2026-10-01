<?php
require_once __DIR__ . '/includes/bootstrap.php';
require_once __DIR__ . '/includes/openai.php';
require_login();

if (is_staff()) {
    http_response_code(403);
    exit('Staff cannot use AI search.');
}

$q = trim($_POST['q'] ?? $_GET['q'] ?? '');
$answer = '';
$matches = [];

if ($q !== '') {
    $like = "%{$q}%";
    $sql = "SELECT s.*, i.product_name AS ip, i.qty AS iqty, i.rate AS irate, i.amount AS iamount
            FROM shipments s
            LEFT JOIN shipment_items i ON i.shipment_id = s.id
            WHERE s.status='approved_locked'
              AND (s.invoice_no LIKE ? OR s.buyer_name LIKE ? OR s.buyer_country LIKE ? OR s.destination_port LIKE ? OR i.product_name LIKE ? OR i.des_col LIKE ?)
            ORDER BY s.id DESC LIMIT 30";
    $stmt = db()->prepare($sql);
    $stmt->execute([$like, $like, $like, $like, $like, $like]);
    $matches = $stmt->fetchAll();

    try {
        $embSql = "SELECT chunk_text FROM shipment_embeddings WHERE is_active=1 ORDER BY id DESC LIMIT 500";
        $stmt = db()->prepare($embSql);
        $stmt->execute();
        $chunks = array_column($stmt->fetchAll(), 'chunk_text');
        if ($chunks) {
            $answer = gpt_answer($q, array_slice($chunks, 0, 6));
        }
    } catch (Throwable $e) {
        $answer = '';
    }
    if ($answer === '') {
        $answer = $matches
            ? 'Found ' . count($matches) . ' matching line item(s) across approved & locked shipments for "' . $q . '". See the matched records below.'
            : 'No approved & locked records matched "' . $q . '".';
    }
}

$examples = ['patient gown rate', 'UAE shipments', 'blankets packing', 'recent invoices'];

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
.ai-searchbar{width:100%;max-width:620px;margin-top:22px;display:flex;gap:10px;padding:8px;border-radius:16px;background:#ffffff;border:1px solid #cbd5e3}
.ai-searchbar input{flex:1;border:none;background:transparent;color:#152033;font-size:14px;outline:none;padding:8px 12px}
.ai-ask{padding:10px 20px;border:none;border-radius:11px;cursor:pointer;font-weight:700;color:#fff;background:linear-gradient(100deg,#0ea8c9,#6d5bd0);background-size:200% 100%;animation:zshim 3.5s linear infinite}
.ai-pills{display:flex;flex-wrap:wrap;gap:8px;justify-content:center;margin-top:14px}
.ai-pill{padding:7px 13px;border-radius:20px;border:1px solid #cbd5e3;background:#f6f8fc;color:#33415c;cursor:pointer;font-size:12.5px;font-family:inherit;transition:.15s}
.ai-pill:hover{border-color:rgba(14,168,201,.4);background:rgba(14,168,201,.1)}
.ai-answer{max-width:820px;margin:26px auto 0;animation:zrise .5s ease both}
.ai-card{padding:22px;border-radius:18px;background:#ffffff;border:1px solid #cbd5e3;backdrop-filter:blur(12px)}
.ai-badge{width:26px;height:26px;border-radius:8px;background:conic-gradient(from 210deg,#0ea8c9,#6d5bd0,#e0435d,#0ea8c9)}
.ai-hint{margin-top:22px;font-size:15px;color:#33415c;font-weight:600;min-height:22px}
</style>

<div class="topbar"><div><h1>ZAS Textile AI Search</h1><p class="lead">Ask about any approved &amp; locked shipment</p></div></div>

<div class="ai-wrap">
  <form method="post" id="aiForm" style="display:contents">
    <button type="submit" class="ai-orb" title="Tap to search">
      <span class="ring"></span>
      <span class="core">
        <svg width="46" height="46" viewBox="0 0 24 24" fill="none" stroke="#0ea8c9" stroke-width="1.5"><path d="M12 2.5l1.9 5.6L19.5 10l-5.6 1.9L12 17.5l-1.9-5.6L4.5 10l5.6-1.9z"/><circle cx="18.6" cy="5.4" r="1.15" fill="#0ea8c9"/></svg>
      </span>
    </button>
    <div class="ai-hint"><?= $q ? 'Results for "'.e($q).'"' : 'Tap the orb or type a question' ?></div>

    <div class="ai-searchbar">
      <input name="q" value="<?= e($q) ?>" placeholder="Ask about any locked shipment… e.g. patient gown rate" autocomplete="off">
      <button type="submit" class="ai-ask">Ask</button>
    </div>
    <div class="ai-pills">
      <?php foreach($examples as $ex): ?>
        <button type="submit" name="q" value="<?= e($ex) ?>" class="ai-pill"><?= e($ex) ?></button>
      <?php endforeach; ?>
    </div>
  </form>

  <?php if($answer): ?>
  <div class="ai-answer">
    <div class="ai-card">
      <div style="display:flex;align-items:center;gap:10px;margin-bottom:12px"><div class="ai-badge"></div><span style="font-weight:700;font-size:13.5px">ZAS Textile AI</span></div>
      <p style="margin:0;font-size:14.5px;line-height:1.7;color:#152033"><span id="aiTyped"></span><span style="color:#0ea8c9">&#9613;</span></p>
    </div>

    <?php if($matches): ?>
    <div style="color:#8a97ab;font-size:12px;margin:20px 4px 10px;text-transform:uppercase;letter-spacing:.06em">Matched Line Items · <?= count($matches) ?> found</div>
    <div style="border-radius:16px;background:#ffffff;border:1px solid #e3e9f2;backdrop-filter:blur(12px);overflow-x:auto">
      <table style="width:100%;border-collapse:collapse;font-size:13px;min-width:600px">
        <thead><tr style="text-align:left;color:#8a97ab;font-size:11px;text-transform:uppercase;letter-spacing:.05em">
          <th style="padding:11px 14px">Product / Article</th><th style="padding:11px 14px">Invoice</th><th style="padding:11px 14px">Buyer</th><th style="padding:11px 14px;text-align:right">Qty</th><th style="padding:11px 14px;text-align:right">Rate</th><th style="padding:11px 14px;text-align:right">Value</th></tr></thead>
        <tbody>
        <?php foreach($matches as $m): ?>
          <tr style="border-top:1px solid #f6f8fc">
            <td style="padding:11px 14px"><a style="color:#0ea8c9;text-decoration:none" href="shipment_view.php?id=<?= (int)$m['id'] ?>"><?= e($m['ip'] ?? '—') ?></a></td>
            <td style="padding:11px 14px"><?= e($m['invoice_no']) ?></td>
            <td style="padding:11px 14px"><?= e($m['buyer_name']) ?></td>
            <td style="padding:11px 14px;text-align:right"><?= $m['iqty'] !== null ? e(number_format((float)$m['iqty'])) : '—' ?></td>
            <td style="padding:11px 14px;text-align:right"><?= $m['irate'] !== null ? e(number_format((float)$m['irate'], 2)) : '—' ?></td>
            <td style="padding:11px 14px;text-align:right;color:#0ea8c9"><?= $m['iamount'] !== null ? e(number_format((float)$m['iamount'], 2)) : '—' ?></td>
          </tr>
        <?php endforeach; ?>
        </tbody>
      </table>
    </div>
    <?php endif; ?>
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

<?php page_footer(); ?>
