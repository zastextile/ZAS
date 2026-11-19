<?php
require_once __DIR__ . '/includes/bootstrap.php';
require_login();
if (is_production_staff()) { http_response_code(403); exit('Production Staff cannot access shipments.'); }

/* THE SHIPMENTS LIST, FIORI STYLE (owner, 19 Nov 2026).
   ONE read of the shipments this user may see, plus one grouped read of the
   payments; everything else — KPI cards, status tabs, buyer / country /
   currency, dates, sorting, totals and the Excel file — runs in the browser
   (assets/js/zflist.js). Typing in the search asks the server nothing. */
require_once __DIR__ . '/includes/export.php';
$where = []; $params = [];
if (!is_admin()) {
    $ids = assigned_shipment_ids();
    if ($ids === ['ALL']) {
        // colleague — see all shipments, no filter
    } elseif (!$ids) { $where[] = "1=0"; }
    else {
        $where[] = "s.id IN (" . implode(',', array_fill(0, count($ids), '?')) . ")";
        $params = array_merge($params, $ids);
    }
}
$money = can_see_rates();
$payOk = $money && function_exists('exp_can') && exp_can('payments');
$stmt = db()->prepare("SELECT s.id, s.invoice_no, s.invoice_date, s.buyer_name, s.buyer_country, s.destination_port, s.currency,
                              s.total_amount, s.total_qty, s.status, s.reopen_status
                         FROM shipments s" . ($where ? " WHERE " . implode(' AND ', $where) : '') . " ORDER BY s.id DESC LIMIT 3000");
$stmt->execute($params);
$rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
$paid = [];
if ($payOk) {
    try { foreach (db()->query("SELECT shipment_id, SUM(amount) a FROM shipment_payments WHERE is_void=0 GROUP BY shipment_id")->fetchAll() as $r) $paid[(int)$r['shipment_id']] = (float)$r['a']; }
    catch (Throwable $e) { $paid = []; }
}
$L = array_map(function ($r) use ($money, $payOk, $paid) {
    $o = ['id' => (int)$r['id'], 'no' => (string)$r['invoice_no'], 'date' => (string)($r['invoice_date'] ?? ''), 'buyer' => (string)$r['buyer_name'],
          'country' => trim((string)$r['buyer_country']), 'port' => (string)$r['destination_port'], 'qty' => (float)$r['total_qty'],
          'status' => (string)$r['status'], 'reopen' => ($r['reopen_status'] ?? '') === 'reopened_for_correction' ? 1 : 0];
    if ($money) { $o['cur'] = (string)$r['currency']; $o['amt'] = (float)$r['total_amount']; }
    if ($payOk) { $o['rec'] = $paid[(int)$r['id']] ?? 0.0; $o['bal'] = round($o['amt'] - $o['rec'], 2); }
    return $o;
}, $rows);

page_header('Shipments');
flash();
$J = fn($v) => json_encode($v, JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT);
?>
<link rel="stylesheet" href="assets/css/zf.css?v=4">
<script src="assets/js/zflist.js?v=3"></script>
<div class="zf">
  <div class="zf-hd">
    <div><h1>Shipments</h1><div class="zf-sub">Commercial invoices and packing — click a card or a tab to narrow the list, a row to open it.</div></div>
    <?php if (!is_staff()): ?><a class="zf-btn pri" href="shipment_form.php">+ New Shipment</a><?php endif; ?>
  </div>
  <div id="shList"></div>
</div>
<script>
(function(){
  var R = <?= $J($L) ?>, MONEY = <?= $money ? 'true' : 'false' ?>, PAY = <?= $payOk ? 'true' : 'false' ?>, ADMIN = <?= is_admin() ? 'true' : 'false' ?>;
  var Z = window.ZF, esc = Z.esc, month = new Date().toISOString().slice(0, 7);
  var ST = { draft: ['Draft', 'p-draft'], submitted: ['Submitted', 'p-sub'], approved_locked: ['Approved & locked', 'p-ok'] };
  /* money is owed once the invoice is approved (or something was already received) — a draft is not "unpaid" */
  function due(r){ return r.amt > 0 && (r.status === 'approved_locked' || r.rec > 0.004); }
  function pay(r){ if (!PAY || !due(r)) return ''; return r.rec <= 0.004 ? '<span class="zf-pill p-red">Unpaid</span>' : (r.bal > 0.004 ? '<span class="zf-pill p-sub">Part paid</span>' : '<span class="zf-pill p-ok">Paid</span>'); }
  var kpis = [];
  if (MONEY) kpis.push({ id: 'month', label: 'This month', cls: 'k-blue', test: function(r){ return r.date.slice(0, 7) === month; },
    value: function(rows){ var m = rows.filter(function(r){ return r.date.slice(0, 7) === month; }); return [Z.curText(Z.byCur(m, 'cur', 'amt'), true), m.length + ' invoice' + (m.length === 1 ? '' : 's') + ' dated this month']; } });
  kpis.push({ id: 'draft', label: 'Drafts', cls: 'k-grey', test: function(r){ return r.status === 'draft'; }, value: function(rows){ var n = rows.filter(function(r){ return r.status === 'draft'; }).length; return [String(n), 'not submitted yet']; } });
  kpis.push({ id: 'sub', label: 'Waiting for approval', cls: 'k-amber', test: function(r){ return r.status === 'submitted'; }, value: function(rows){ var n = rows.filter(function(r){ return r.status === 'submitted'; }).length; return [String(n), n ? 'Admin to approve & lock' : 'nothing waiting']; } });
  kpis.push({ id: 'reopen', label: 'Reopened for correction', cls: 'k-amber', test: function(r){ return r.reopen; }, value: function(rows){ var n = rows.filter(function(r){ return r.reopen; }).length; return [String(n), n ? 'colleague to correct' : 'none']; } });
  if (PAY) kpis.push({ id: 'open', label: 'Still to receive', cls: 'k-red', test: function(r){ return due(r) && r.bal > 0.004; },
    value: function(rows){ var o = rows.filter(function(r){ return due(r) && r.bal > 0.004; }); return [Z.curText(Z.byCur(o, 'cur', 'bal'), true), o.length + ' invoice' + (o.length === 1 ? '' : 's') + ' not fully paid']; } });
  var cols = [
    { k: 'no', label: 'Invoice', sort: function(r){ return r.id; }, html: function(r){ return '<a class="lk" href="shipment_view.php?id=' + r.id + '">' + esc(r.no) + '</a>' + (r.reopen ? '<div class="s2" style="color:#9a5710">reopened</div>' : ''); } },
    { k: 'date', label: 'Date', sort: function(r){ return r.date; }, html: function(r){ return '<span style="white-space:nowrap">' + esc(Z.nice(r.date)) + '</span>'; } },
    { k: 'buyer', label: 'Buyer', sort: function(r){ return r.buyer.toLowerCase(); }, html: function(r){ return '<span class="nw" title="' + esc(r.buyer) + '">' + esc(r.buyer) + '</span>' + (r.country ? '<div class="s2">' + esc(r.country) + '</div>' : ''); } },
    { k: 'port', label: 'Destination', sort: function(r){ return r.port.toLowerCase(); }, html: function(r){ return esc(r.port || ''); } },
    { k: 'qty', label: 'Qty', num: 1, sort: function(r){ return r.qty; }, html: function(r){ return r.qty ? Z.n0(r.qty) : '<span style="color:#9aa8b6">—</span>'; } }
  ];
  if (MONEY) cols.push({ k: 'amt', label: 'Amount', num: 1, sort: function(r){ return r.amt; }, html: function(r){ return '<b style="font-weight:600">' + esc(r.cur) + ' ' + Z.n2(r.amt) + '</b>'; } });
  if (PAY) cols.push({ k: 'bal', label: 'Balance', num: 1, sort: function(r){ return r.bal; }, html: function(r){ return due(r) ? (r.bal > 0.004 ? '<span style="color:#b42318">' + esc(r.cur) + ' ' + Z.n2(r.bal) + '</span>' : '<span style="color:#256f3a">0.00</span>') : ''; } });
  cols.push({ k: 'status', label: 'Status', sort: function(r){ return r.status; }, html: function(r){ var s = ST[r.status] || [r.status, 'p-grey']; return '<span class="zf-pill ' + s[1] + '">' + esc(s[0]) + '</span> ' + pay(r); } });
  if (ADMIN) cols.push({ k: 'x', label: '', html: function(r){ return r.status === 'draft' ? '<button type="button" class="zf-btn sm danger" data-del="' + r.id + '" data-no="' + esc(r.no) + '">Delete</button>' : ''; } });
  var csv = [['Invoice', function(r){ return r.no; }], ['Date', function(r){ return r.date; }], ['Buyer', function(r){ return r.buyer; }], ['Country', function(r){ return r.country; }], ['Destination', function(r){ return r.port; }], ['Qty', function(r){ return r.qty; }]];
  if (MONEY) csv.push(['Currency', function(r){ return r.cur; }], ['Amount', function(r){ return r.amt.toFixed(2); }]);
  if (PAY) csv.push(['Received', function(r){ return r.rec.toFixed(2); }], ['Balance', function(r){ return r.bal.toFixed(2); }]);
  csv.push(['Status', function(r){ return (ST[r.status] || [r.status])[0]; }]);
  ZFList({
    root: 'shList', rows: R, cols: cols, kpis: kpis, dateKey: 'date', sort: 'no', dir: -1, csv: csv, csvName: 'shipments', empty: 'No shipments yet.',
    searchHint: 'Search invoice, buyer, country, port…',
    hay: function(r){ return r.no + ' ' + r.buyer + ' ' + r.country + ' ' + r.port + ' ' + (r.cur || ''); },
    href: function(r){ return 'shipment_view.php?id=' + r.id; },
    segs: [['', 'All'], ['draft', 'Draft', function(r){ return r.status === 'draft'; }], ['submitted', 'Submitted', function(r){ return r.status === 'submitted'; }], ['approved_locked', 'Approved & locked', function(r){ return r.status === 'approved_locked'; }]],
    selects: [{ k: 'buyer', label: 'Buyer' }, { k: 'country', label: 'Country' }].concat(MONEY ? [{ k: 'cur', label: 'Currency' }] : []),
    foot: function(rows){
      return cols.map(function(c){
        if (c.k === 'no') return '<td>Total · ' + rows.length + '</td>';
        if (c.k === 'qty') return '<td class="n">' + Z.n0(rows.reduce(function(t, r){ return t + r.qty; }, 0)) + '</td>';
        if (c.k === 'amt') return '<td class="n">' + Z.curLines(Z.byCur(rows, 'cur', 'amt')) + '</td>';
        if (c.k === 'bal') return '<td class="n" style="color:#b42318">' + Z.curLines(Z.byCur(rows.filter(due), 'cur', 'bal')) + '</td>';
        return '<td></td>';
      }).join('');
    }
  });
  document.addEventListener('click', function(e){ var d = e.target.closest('[data-del]'); if (d && window.showDeleteModal) showDeleteModal(+d.getAttribute('data-del'), d.getAttribute('data-no')); });
})();
</script>

<?php if (is_admin()): ?>
<div id="deleteModal" style="display:none;position:fixed;inset:0;background:rgba(10,15,30,.5);z-index:999;align-items:center;justify-content:center;padding:16px">
  <div style="background:#ffffff;border-radius:16px;padding:24px;max-width:400px;width:100%;border:1px solid #e3e9f2">
    <h2 style="font-size:16px;margin:0 0 8px;color:#b8283f">Delete Shipment <span id="delInvoiceNo"></span>?</h2>
    <p style="font-size:13px;color:#5a6b82;line-height:1.5;margin:0 0 16px">This permanently deletes the invoice, all line items, packing entries, charges, files and any AI embedding for this shipment. This cannot be undone. Enter your admin password to confirm.</p>
    <form method="post" action="shipment_delete.php">
      <?= csrf_field() ?>
      <input type="hidden" name="shipment_id" id="delShipmentId" value="">
      <label style="font-size:12px;color:#5a6b82;display:block">Your Password
        <input type="password" id="deletePassword" name="confirm_password" autocomplete="current-password" required style="display:block;width:100%;margin-top:6px;padding:10px 12px;border-radius:10px;border:1px solid #cbd5e3;background:#ffffff;color:#152033;font-size:13.5px;outline:none;font-family:inherit;box-sizing:border-box">
      </label>
      <div style="display:flex;gap:10px;margin-top:18px">
        <button type="button" style="flex:1;padding:11px 18px;border-radius:11px;cursor:pointer;font-weight:700;font-size:13px;background:#f6f8fc;color:#152033;border:1px solid #cbd5e3" onclick="hideDeleteModal()">Cancel</button>
        <button type="submit" style="flex:1;padding:11px 18px;border:none;border-radius:11px;cursor:pointer;font-weight:700;font-size:13px;color:#fff;background:linear-gradient(100deg,#e0435d,#b8283f)" onclick="return confirm('Really delete this shipment permanently?')">Delete Permanently</button>
      </div>
    </form>
  </div>
</div>
<script>
function showDeleteModal(id, invoiceNo){
  document.getElementById('delShipmentId').value = id;
  document.getElementById('delInvoiceNo').textContent = invoiceNo;
  document.getElementById('deletePassword').value = '';
  document.getElementById('deleteModal').style.display = 'flex';
  document.getElementById('deletePassword').focus();
}
function hideDeleteModal(){ document.getElementById('deleteModal').style.display = 'none'; }
</script>
<?php endif; ?>

<?php page_footer(); ?>
