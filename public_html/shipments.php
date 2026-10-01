<?php
require_once __DIR__ . '/includes/bootstrap.php';
require_login();
if (is_production_staff()) { http_response_code(403); exit('Production Staff cannot access shipments.'); }

$q  = trim($_GET['q'] ?? '');
$st = trim($_GET['status'] ?? '');
$params = [];
$where = [];

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
if ($q !== '') {
    $where[] = "(s.invoice_no LIKE ? OR s.buyer_name LIKE ? OR s.buyer_country LIKE ? OR s.destination_port LIKE ?)";
    $like = "%{$q}%"; array_push($params, $like, $like, $like, $like);
}
if (in_array($st, ['draft','submitted','approved_locked'], true)) {
    $where[] = "s.status = ?"; $params[] = $st;
}

$sql = "SELECT s.* FROM shipments s";
if ($where) $sql .= " WHERE " . implode(' AND ', $where);
$sql .= " ORDER BY s.id DESC LIMIT 200";
$stmt = db()->prepare($sql);
$stmt->execute($params);
$rows = $stmt->fetchAll();

function ship_badge($status) {
    $map = [
        'draft'           => ['Draft','rgba(47,127,224,.16)','#2f7fe0','rgba(47,127,224,.3)'],
        'submitted'       => ['Submitted','rgba(217,119,6,.16)','#d97706','rgba(217,119,6,.3)'],
        'approved_locked' => ['Approved & Locked','rgba(22,163,74,.16)','#16a34a','rgba(22,163,74,.3)'],
    ];
    $x = $map[$status] ?? [ucfirst((string)$status),'#e3e9f2','#33415c','#cbd5e3'];
    return 'padding:5px 11px;border-radius:20px;font-size:12px;font-weight:600;white-space:nowrap;background:'.$x[1].';color:'.$x[2].';border:1px solid '.$x[3];
}
$cardCss = 'padding:22px;border-radius:18px;background:#ffffff;border:1px solid #e3e9f2;backdrop-filter:blur(12px)';
$tabs = [''=>'All','draft'=>'Draft','submitted'=>'Submitted','approved_locked'=>'Approved & Locked'];

page_header('Shipments');
flash();
?>
<style>
.sh-tab{padding:7px 15px;border-radius:20px;border:1px solid #cbd5e3;background:#f6f8fc;color:#33415c;cursor:pointer;font-size:12.5px;text-decoration:none;white-space:nowrap;transition:.15s}
.sh-tab:hover{border-color:rgba(14,168,201,.4);background:rgba(14,168,201,.1)}
.sh-tab.on{background:linear-gradient(100deg,#0ea8c9,#6d5bd0);color:#fff;font-weight:700;border-color:transparent}
.sh-bar input{flex:1;border:none;background:transparent;color:#152033;font-size:14px;outline:none;padding:8px 12px}
</style>

<div class="topbar">
  <div><h1>Shipments</h1><p class="lead">Search &amp; manage commercial invoice and packing records.</p></div>
  <?php if (!is_staff()): ?><a class="btn green" href="shipment_form.php">+ New Shipment</a><?php endif; ?>
</div>

<div style="<?= $cardCss ?>;margin-bottom:18px">
  <form method="get" style="display:flex;gap:10px;padding:8px;border-radius:14px;background:#ffffff;border:1px solid #e3e9f2;margin-bottom:16px" class="sh-bar">
    <?php if($st!==''): ?><input type="hidden" name="status" value="<?= e($st) ?>"><?php endif; ?>
    <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="#8a97ab" stroke-width="2" style="align-self:center;margin-left:8px"><circle cx="11" cy="11" r="7"/><path d="M21 21l-4-4"/></svg>
    <input name="q" value="<?= e($q) ?>" placeholder="Search invoice, buyer, country, destination…" autocomplete="off">
    <button class="btn green" style="padding:10px 20px">Search</button>
  </form>
  <div style="display:flex;flex-wrap:wrap;gap:8px">
    <?php foreach($tabs as $k=>$label):
      $href = 'shipments.php?status='.urlencode($k).($q!==''?'&q='.urlencode($q):''); ?>
      <a class="sh-tab <?= $st===$k?'on':'' ?>" href="<?= e($href) ?>"><?= e($label) ?></a>
    <?php endforeach; ?>
  </div>
</div>

<div style="<?= $cardCss ?>">
  <div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:16px">
    <h2 style="font-size:15px;margin:0">Records</h2>
    <span style="color:#8a97ab;font-size:12px"><?= count($rows) ?> shown · click a row to open</span>
  </div>
  <div style="overflow-x:auto">
    <table style="width:100%;border-collapse:collapse;font-size:13px;min-width:720px">
      <thead><tr style="text-align:left;color:#8a97ab;font-size:11.5px;text-transform:uppercase;letter-spacing:.05em">
        <th style="padding:8px 10px">Invoice</th><th style="padding:8px 10px">Date</th><th style="padding:8px 10px">Buyer</th><th style="padding:8px 10px">Country</th><th style="padding:8px 10px">Destination</th>
        <?php if(can_see_rates()): ?><th style="padding:8px 10px;text-align:right">Amount</th><?php endif; ?>
        <th style="padding:8px 10px">Status</th>
        <?php if(is_admin()): ?><th style="padding:8px 10px"></th><?php endif; ?>
        </tr></thead>
      <tbody>
      <?php if($rows): foreach ($rows as $r): ?>
        <tr onclick="location.href='shipment_view.php?id=<?= (int)$r['id'] ?>'" style="cursor:pointer;border-top:1px solid #f6f8fc" onmouseover="this.style.background='#f6f8fc'" onmouseout="this.style.background='transparent'">
          <td style="padding:12px 10px;font-weight:600;color:#0ea8c9"><?= e($r['invoice_no']) ?></td>
          <td style="padding:12px 10px;color:#5a6b82"><?= e($r['invoice_date']) ?></td>
          <td style="padding:12px 10px"><?= e($r['buyer_name']) ?></td>
          <td style="padding:12px 10px;color:#5a6b82"><?= e($r['buyer_country']) ?></td>
          <td style="padding:12px 10px;color:#5a6b82"><?= e($r['destination_port']) ?></td>
          <?php if(can_see_rates()): ?><td style="padding:12px 10px;text-align:right;font-family:'Space Grotesk',system-ui,sans-serif;font-weight:600"><?= e(money_fmt($r['total_amount'], $r['currency'])) ?></td><?php endif; ?>
          <td style="padding:12px 10px"><span style="<?= ship_badge($r['status']) ?>"><?= e(ucwords(str_replace('_',' ',$r['status']))) ?></span></td>
          <?php if(is_admin()): ?>
          <td style="padding:12px 10px" onclick="event.stopPropagation()">
            <?php if($r['status']==='draft'): ?>
            <button type="button" onclick="showDeleteModal(<?= (int)$r['id'] ?>,'<?= e(addslashes($r['invoice_no'])) ?>')" style="padding:6px 11px;border-radius:8px;border:1px solid rgba(224,67,93,.3);background:rgba(224,67,93,.1);color:#b8283f;font-size:11.5px;font-weight:600;cursor:pointer">Delete</button>
            <?php endif; ?>
          </td>
          <?php endif; ?>
        </tr>
      <?php endforeach; else: ?>
        <tr><td colspan="8" style="padding:30px;text-align:center;color:#8a97ab">No shipments match your filters.</td></tr>
      <?php endif; ?>
      </tbody>
    </table>
  </div>
</div>

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
