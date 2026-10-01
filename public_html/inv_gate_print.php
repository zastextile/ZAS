<?php
/* Printable gate pass. Standalone page — no app chrome — styled to match
   the navy/gold look of the existing invoice and packing prints. */
require_once __DIR__ . '/includes/bootstrap.php';
require_login();
require_once __DIR__ . '/includes/inventory.php';
inv_ensure_schema();
if (!inv_can_see()) { http_response_code(403); exit('Not permitted.'); }

$id = (int)($_GET['id'] ?? 0);
$doc = null; $lines = [];
try {
    $s = db()->prepare("SELECT g.*, p.name party_name, p.address party_address, p.ntn party_ntn,
            c.contract_no, pf.pi_no, u.name prepared_name
        FROM inv_gate g
        LEFT JOIN inv_parties p ON p.id=g.party_id
        LEFT JOIN inv_contracts c ON c.id=g.contract_id
        LEFT JOIN proforma_invoices pf ON pf.id=g.proforma_id
        LEFT JOIN users u ON u.id=g.prepared_by
        WHERE g.id=?");
    $s->execute([$id]); $doc = $s->fetch() ?: null;
    if ($doc) {
        $s2 = db()->prepare("SELECT gi.*, m.code mcode, m.name mname, m.composition, pr.name pname
            FROM inv_gate_items gi
            LEFT JOIN inv_materials m ON m.id=gi.material_id
            LEFT JOIN products pr ON pr.id=gi.product_id
            WHERE gi.gate_id=? ORDER BY gi.sort_order, gi.id");
        $s2->execute([$id]); $lines = $s2->fetchAll();
    }
} catch (Throwable $e) {}
if (!$doc) { http_response_code(404); exit('Gate pass not found.'); }

$TYPES = inv_gate_types($doc['direction']);
$T = $TYPES[$doc['txn_type']] ?? ['label' => $doc['txn_type'], 'own' => 'own'];
$isIn = $doc['direction'] === 'in';
$title = $isIn ? 'GATE INWARD PASS' : 'GATE OUTWARD PASS';
$company = $config['company_name'] ?? 'ZAS TEXTILE';
?><!doctype html>
<html lang="en"><head><meta charset="utf-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<title><?= e($doc['gate_no']) ?> — <?= e($title) ?></title>
<style>
:root{--navy:#0b2a4a;--line:#dbe4ee;--sub:#5a7590}
*{box-sizing:border-box}
body{margin:0;background:#f0f3f8;color:var(--navy);font-family:"Segoe UI",Arial,sans-serif;padding:26px 16px}
.sheet{background:#fff;max-width:800px;margin:0 auto;padding:30px 32px;border:1px solid var(--line);border-radius:6px}
.ph{display:flex;justify-content:space-between;align-items:flex-start;border-bottom:2.5px solid var(--navy);padding-bottom:13px;margin-bottom:15px}
.co{font-size:20px;font-weight:800;letter-spacing:.02em}
.co small{display:block;font-size:9.5px;font-weight:600;color:var(--sub);letter-spacing:.07em;margin-top:3px}
.dt{text-align:right;font-size:11px;color:#3f5f7d;line-height:1.65}
.dt b{font-size:15px;font-family:"Courier New",monospace}
.ttl{background:var(--navy);color:#fff;text-align:center;font-size:12.5px;font-weight:800;letter-spacing:.16em;padding:8px;border-radius:4px;margin-bottom:15px}
.kv{display:grid;grid-template-columns:repeat(4,1fr);gap:10px 15px;font-size:11px;margin-bottom:15px}
.kv span{display:block;font-size:8.5px;text-transform:uppercase;letter-spacing:.07em;color:#7a93ac;font-weight:800;margin-bottom:2px}
.kv b{font-weight:700}
table{width:100%;border-collapse:collapse;font-size:11px}
th{text-align:left;color:var(--sub);border-bottom:1.5px solid var(--navy);padding:0 9px 6px;font-size:9.5px;text-transform:uppercase;letter-spacing:.05em}
td{border-top:1px solid var(--line);padding:8px 9px}
td.r,th.r{text-align:right;font-variant-numeric:tabular-nums}
tfoot td{border-top:1.5px solid var(--navy);font-weight:800}
.note{margin-top:13px;padding:9px 12px;background:#f2f6fa;border-radius:5px;font-size:10px;color:#3f5f7d;line-height:1.6}
.warn{background:#fdf3e6;color:#7a4d09}
.sigs{display:grid;grid-template-columns:repeat(4,1fr);gap:18px;margin-top:40px;font-size:9px;text-align:center;color:var(--sub)}
.sigs div{border-top:1px solid var(--navy);padding-top:6px;font-weight:700;letter-spacing:.04em}
.sigs em{display:block;font-style:normal;font-weight:400;margin-top:3px;color:#33475c}
.bar{max-width:800px;margin:0 auto 16px;display:flex;gap:9px;justify-content:flex-end}
.bar button,.bar a{padding:9px 17px;border-radius:9px;border:1px solid #cbd5e3;background:#fff;color:var(--navy);
  font-size:12.5px;font-weight:700;cursor:pointer;text-decoration:none;font-family:inherit}
.bar button{background:var(--navy);color:#fff;border-color:var(--navy)}
.stamp{display:inline-block;font-size:9.5px;font-weight:800;padding:3px 9px;border-radius:3px;letter-spacing:.06em}
.s-posted{background:#e6f5ec;color:#16733d;border:1px solid #b9e0c9}
.s-draft{background:#eef1f7;color:#5a6b82;border:1px solid #d8e0ea}
.s-reversed{background:#fdeaee;color:#a8283f;border:1px solid #f2c2cc}
@media print{
  body{background:#fff;padding:0}
  .bar{display:none}
  .sheet{border:none;border-radius:0;max-width:none;padding:0}
  @page{margin:14mm}
}
@media(max-width:640px){.kv,.sigs{grid-template-columns:repeat(2,1fr)}}
</style></head><body>

<div class="bar">
  <a href="inv_gate.php?id=<?= (int)$doc['id'] ?>">← Back</a>
  <button onclick="window.print()">Print</button>
</div>

<div class="sheet">
  <div class="ph">
    <div class="co"><?= e($company) ?><small>TEXTILE EXPORTS · FAISALABAD, PAKISTAN</small></div>
    <div class="dt">
      <b><?= e($doc['gate_no']) ?></b><br>
      Date: <?= e(date('d M Y', strtotime((string)$doc['gate_date']))) ?><br>
      Time <?= $isIn ? 'In' : 'Out' ?>: <?= e(substr((string)$doc['gate_time'], 0, 5)) ?><br>
      <span class="stamp s-<?= e($doc['status']) ?>"><?= e(strtoupper($doc['status'])) ?></span>
    </div>
  </div>

  <div class="ttl"><?= e($title) ?></div>

  <div class="kv">
    <div><span>Transaction type</span><b><?= e($T['label']) ?></b></div>
    <div><span><?= $isIn ? 'Received from' : 'Party / destination' ?></span><b><?= e($doc['party_name'] ?: ($doc['party_text'] ?: '—')) ?></b></div>
    <div><span>Vehicle no.</span><b><?= e($doc['vehicle_no'] ?: '—') ?></b></div>
    <div><span><?= $isIn ? 'Challan / bilty' : 'Challan / reference' ?></span><b><?= e($doc['challan_no'] ?: '—') ?></b></div>
    <div><span>Contract</span><b><?= e($doc['contract_no'] ?: 'Direct / none') ?></b></div>
    <div><span>Order reference</span><b><?= e($doc['pi_no'] ?: '—') ?></b></div>
    <div><span><?= $isIn ? 'Receiving location' : 'Issued from' ?></span><b><?= e(inv_location_name((int)$doc['location_id'])) ?></b></div>
    <div><span>Purpose</span><b><?= e($doc['purpose'] ?: '—') ?></b></div>
  </div>

  <table>
    <?php
      /* Money is only printed when there is money on the document — a
         plain material-movement pass stays the clean two-column list it
         has always been. */
      $hasMoney = false;
      foreach ($lines as $L) if ((float)$L['rate'] > 0 || (float)($L['amount'] ?? 0) > 0) { $hasMoney = true; break; }
      $T2 = inv_totals($lines, !empty($doc['gst_applicable']), (float)($doc['gst_pct'] ?? inv_gst_default()));
      $money = $hasMoney ? 3 : 0;   // extra columns
    ?>
    <thead><tr>
      <th style="width:28px">#</th><th>Item</th><th>Description</th><th>Article / lot</th>
      <th class="r" style="width:84px">Quantity</th><th style="width:52px">UOM</th>
      <?php if ($hasMoney): ?><th class="r" style="width:88px">Rate</th><th class="r" style="width:104px">Amount</th><?php endif; ?>
      <th style="width:96px">Packing</th>
    </tr></thead>
    <tbody>
    <?php $i = 1; $tot = 0; foreach ($lines as $L): $tot += (float)$L['qty'];
      $ourName = $L['mcode'] ? $L['mcode'] . ' · ' . $L['mname'] : ($L['pname'] ?: '—');
      $theirs  = trim((string)($L['description'] ?? ''));
      $amt = (float)($L['amount'] ?? 0) ?: (float)$L['qty'] * (float)$L['rate'];
    ?>
      <tr>
        <td><?= $i++ ?></td>
        <td><b><?= e($ourName) ?></b>
          <?php if ($L['composition']): ?><br><span style="color:var(--sub)"><?= e($L['composition']) ?></span><?php endif; ?></td>
        <td><?= e($theirs !== '' ? $theirs : '—') ?></td>
        <td><?= e(trim(($L['article'] ?: '') . ' ' . ($L['lot_no'] ? '· ' . $L['lot_no'] : '')) ?: '—') ?></td>
        <td class="r"><b><?= number_format((float)$L['qty'], 2) ?></b></td>
        <td><?= e($L['uom'] ?: '') ?></td>
        <?php if ($hasMoney): ?>
          <td class="r"><?= number_format((float)$L['rate'], 2) ?></td>
          <td class="r"><?= number_format($amt, 2) ?></td>
        <?php endif; ?>
        <td><?= e($L['packing'] ?: '—') ?></td>
      </tr>
    <?php endforeach; ?>
    </tbody>
    <tfoot>
      <tr><td colspan="4" style="text-align:right">TOTAL QUANTITY</td><td class="r"><?= number_format($tot, 2) ?></td>
          <td colspan="<?= 1 + $money ?>"></td></tr>
      <?php if ($hasMoney): ?>
        <tr><td colspan="7" style="text-align:right">TOTAL EXCLUDING TAX</td>
            <td class="r"><b><?= number_format($T2['excl'], 2) ?></b></td><td></td></tr>
        <?php if ($T2['applies']): ?>
          <tr><td colspan="7" style="text-align:right">GST @ <?= rtrim(rtrim(number_format($T2['pct'], 2), '0'), '.') ?>%</td>
              <td class="r"><?= number_format($T2['gst'], 2) ?></td><td></td></tr>
        <?php endif; ?>
        <tr><td colspan="7" style="text-align:right">TOTAL INCLUDING TAX</td>
            <td class="r"><b><?= number_format($T2['incl'], 2) ?></b></td><td></td></tr>
      <?php endif; ?>
    </tfoot>
  </table>
  <?php if ($hasMoney && !$T2['applies']): ?>
    <div class="note" style="margin-top:10px">No sales tax is applied to this document, so the two totals are the same.</div>
  <?php endif; ?>

  <?php if ($T['own'] === 'customer'): ?>
    <div class="note warn"><b>Customer-owned material.</b> The goods listed above belong to <?= e($doc['party_name'] ?: 'the customer') ?> and are held by us for processing only. They are not part of our stock or valuation.</div>
  <?php endif; ?>
  <?php if ($doc['remarks']): ?><div class="note"><b>Remarks:</b> <?= e($doc['remarks']) ?></div><?php endif; ?>
  <?php if ($doc['status'] === 'reversed'): ?>
    <div class="note warn"><b>REVERSED.</b> <?= e((string)$doc['reversal_reason']) ?></div>
  <?php endif; ?>

  <div class="sigs">
    <div>PREPARED BY<em><?= e($doc['prepared_name'] ?: '') ?></em></div>
    <div><?= $isIn ? 'QUALITY / QTY VERIFIED' : 'STORE AUTHORISED' ?><em><?= e($doc['verified_by'] ?: '') ?></em></div>
    <div><?= $isIn ? 'STORE RECEIVED' : 'RECEIVER SIGN' ?><em>&nbsp;</em></div>
    <div>SECURITY CHECK<em><?= e($doc['security_by'] ?: '') ?></em></div>
  </div>
</div>
</body></html>
