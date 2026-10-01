<?php
require_once __DIR__ . '/includes/bootstrap.php';
require_login();

$id = (int)($_GET['id'] ?? 0);

$stmt = db()->prepare("SELECT * FROM shipments WHERE id=?");
$stmt->execute([$id]);
$shipment = $stmt->fetch();

if (!$shipment || !can_view_shipment($id)) {
    http_response_code(404);
    exit('Shipment not found or not assigned.');
}

if (is_staff()) {
    http_response_code(403);
    exit('Staff cannot access print reports.');
}

function rpt_v($arr, $key, $default = '') {
    $v = trim((string)($arr[$key] ?? ''));
    return $v === '' ? $default : $v;
}
function rpt_num($v, int $dec = 2): string {
    return number_format((float)$v, $dec);
}
function rpt_qty($v): string {
    $n = (float)$v;
    return rtrim(rtrim(number_format($n, 3, '.', ','), '0'), '.');
}
function rpt_has_value($v): bool {
    return trim((string)$v) !== '';
}
function rpt_watermark(): void {
    $positions = [
        [70,30],[70,310],[70,590],
        [210,-70],[210,210],[210,490],
        [350,40],[350,320],[350,600],
        [490,-90],[490,190],[490,470],
        [630,30],[630,310],[630,590],
        [770,-80],[770,200],[770,480],
        [910,40],[910,320],[910,600],
    ];
    echo '<div class="watermark-grid">';
    foreach ($positions as $p) {
        echo '<span style="top:' . (int)$p[0] . 'px;left:' . (int)$p[1] . 'px">ZAS TEXTILE</span>';
    }
    echo '</div>';
}
function rpt_company_header(string $title, array $shipment, string $statusText = ''): void {
    $statusText = $statusText ?: ucfirst(str_replace('_', ' ', (string)($shipment['status'] ?? '')));
    ?>
    <div class="header">
      <div class="company">
        <h1>ZAS TEXTILE</h1>
        <div class="addr">
          Faisalabad, Pakistan | Manufacturer & Exporter of Home Textile, Hotel Linen, Towels & Apparel<br>
          WhatsApp: +92 300 8663721 | info@zastextiles.com | www.zastextiles.com
        </div>
      </div>
      <div class="title-box">
        <div class="doc-title"><?= e($title) ?></div>
        <div class="doc-meta">
          <div class="meta-pill"><b>Invoice No</b><?= e(rpt_v($shipment, 'invoice_no', '-')) ?></div>
          <div class="meta-pill"><b>Date</b><?= e(rpt_v($shipment, 'invoice_date', date('Y-m-d'))) ?></div>
          <div class="meta-pill"><b>Currency</b><?= e(rpt_v($shipment, 'currency', '-')) ?></div>
          <div class="meta-pill"><b>Status</b><span class="badge"><?= e($statusText) ?></span></div>
        </div>
      </div>
    </div>
    <?php
}
function rpt_party_boxes(array $shipment, bool $packing = false): void {
    ?>
    <div class="section-grid">
      <div class="box">
        <div class="box-title">Buyer / Consignee</div>
        <div class="box-body">
          <b><?= e(rpt_v($shipment, 'buyer_name', '-')) ?></b><br>
          <?= nl2br(e(rpt_v($shipment, 'buyer_address', '-'))) ?><br>
          <?php if(rpt_has_value($shipment['buyer_country'] ?? '')): ?>Country: <?= e($shipment['buyer_country']) ?><?php endif; ?>
        </div>
      </div>
      <div class="box">
        <div class="box-title"><?= $packing ? 'Shipment Details' : 'Exporter / Shipper' ?></div>
        <div class="box-body">
          <?php if($packing): ?>
            PO No: <?= e(rpt_v($shipment, 'po_no', '-')) ?><br>
            BL / Container: <?= e(rpt_v($shipment, 'bl_container_no', '-')) ?><br>
            Destination: <?= e(rpt_v($shipment, 'destination_port', '-')) ?>
          <?php else: ?>
            <b>ZAS TEXTILE</b><br>
            Faisalabad, Pakistan<br>
            Manufacturer & Exporter<br>
            WhatsApp: +92 300 8663721<br>
            info@zastextiles.com
          <?php endif; ?>
        </div>
      </div>
    </div>
    <?php
}
function rpt_info_grid(array $shipment): void {
    ?>
    <div class="info-grid">
      <div class="info"><b>PO No</b><?= e(rpt_v($shipment, 'po_no', '-')) ?></div>
      <div class="info"><b>Payment Terms</b><?= e(rpt_v($shipment, 'payment_terms', '-')) ?></div>
      <div class="info"><b>Port of Loading</b>Karachi</div>
      <div class="info"><b>Destination</b><?= e(rpt_v($shipment, 'destination_port', '-')) ?></div>
      <div class="info"><b>BL / Container</b><?= e(rpt_v($shipment, 'bl_container_no', '-')) ?></div>
      <div class="info"><b>Country of Origin</b>Pakistan</div>
      <div class="info"><b>Incoterm</b><?= e(rpt_v($shipment, 'incoterm', 'FOB / As per invoice')) ?></div>
      <div class="info"><b>Shipment Type</b>Sea Freight</div>
    </div>
    <?php
}
?>
<?php
if (!can_see_rates()) {
    http_response_code(403);
    exit('You do not have permission to print commercial invoice values.');
}

$itemsStmt = db()->prepare("SELECT * FROM shipment_items WHERE shipment_id=? ORDER BY line_no,id");
$itemsStmt->execute([$id]);
$items = $itemsStmt->fetchAll();

$chargesStmt = db()->prepare("SELECT * FROM shipment_charges WHERE shipment_id=? ORDER BY id");
$chargesStmt->execute([$id]);
$charges = $chargesStmt->fetchAll();

$showOptional = (int)($shipment['optional_column_enabled'] ?? 0) === 1;
$optionalTitle = $showOptional ? trim((string)($shipment['optional_column_title'] ?? '')) : '';

$totalQty = 0;
$totalAmount = 0;
foreach ($items as $it) {
    $totalQty += (float)($it['qty'] ?? 0);
    $totalAmount += (float)($it['amount'] ?? 0);
}
$chargesTotal = 0;
foreach ($charges as $ch) {
    $chargesTotal += (float)($ch['amount'] ?? 0);
}
$grandTotal = $totalAmount + $chargesTotal;
$currency = rpt_v($shipment, 'currency', '');
$colspan = $showOptional ? 3 : 2;
?>
<!doctype html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>Commercial Invoice - <?= e(rpt_v($shipment, 'invoice_no', $id)) ?></title>

<style>
:root{
  --ink:#172033;
  --muted:#667085;
  --line:#d8e0ea;
  --soft:#f5f7fb;
  --brand:#0f2742;
  --brand2:#0f766e;
  --gold:#b88900;
}
*{box-sizing:border-box}
body{
  margin:0;
  background:#eaf0f7;
  color:var(--ink);
  font-family:Arial,Helvetica,sans-serif;
  padding:18px;
}
.toolbar{
  max-width:210mm;
  margin:0 auto 12px;
  display:flex;
  justify-content:space-between;
  gap:10px;
  align-items:center;
  background:#fff;
  border:1px solid var(--line);
  border-radius:14px;
  padding:10px 12px;
  box-shadow:0 8px 24px rgba(15,39,66,.08);
}
.toolbar .title{font-weight:900;color:var(--brand)}
.toolbar .actions{display:flex;gap:8px;flex-wrap:wrap}
.btn{
  border:0;
  background:var(--brand);
  color:#fff;
  border-radius:10px;
  padding:9px 12px;
  font-weight:900;
  text-decoration:none;
  cursor:pointer;
  font-size:13px;
}
.btn.secondary{background:#e9f2fc;color:var(--brand)}
.report-page{
  width:210mm;
  min-height:297mm;
  background:#fff;
  margin:0 auto 18px;
  padding:12mm 12mm 14mm;
  box-shadow:0 12px 34px rgba(15,39,66,.18);
  position:relative;
  overflow:hidden;
}
.report-page:before{
  content:"";
  position:absolute;
  top:0;left:0;right:0;
  height:8px;
  background:linear-gradient(90deg,var(--brand),var(--brand2),var(--gold));
}
.watermark-grid{
  position:absolute;
  inset:0;
  z-index:0;
  pointer-events:none;
  opacity:.045;
}
.watermark-grid span{
  position:absolute;
  color:#0f2742;
  font-weight:900;
  font-style:italic;
  font-size:26px;
  letter-spacing:3px;
  transform:rotate(-32deg);
  white-space:nowrap;
}
.report-content{position:relative;z-index:1}
.header{
  display:grid;
  grid-template-columns:1.18fr .82fr;
  gap:18px;
  border-bottom:2px solid var(--brand);
  padding-bottom:14px;
  margin-bottom:14px;
}
.company h1{
  margin:0;
  font-family:Georgia,'Times New Roman',serif;
  font-size:30px;
  letter-spacing:1.3px;
  color:var(--brand);
}
.company .addr{
  margin-top:6px;
  color:#4f5f73;
  line-height:1.38;
  font-size:11.7px;
  font-style:italic;
  max-width:470px;
}
.title-box{text-align:right}
.title-box .doc-title{
  display:inline-block;
  background:var(--brand);
  color:#fff;
  padding:12px 16px;
  border-radius:14px;
  font-size:22px;
  font-weight:900;
  letter-spacing:.5px;
}
.title-box .doc-meta{
  margin-top:10px;
  display:grid;
  grid-template-columns:1fr 1fr;
  gap:6px;
  font-size:12px;
}
.meta-pill{
  border:1px solid var(--line);
  border-radius:10px;
  padding:7px 8px;
  background:rgba(245,247,251,.92);
}
.meta-pill b{
  display:block;
  color:var(--muted);
  font-size:10px;
  text-transform:uppercase;
  margin-bottom:3px;
}
.section-grid{
  display:grid;
  grid-template-columns:1fr 1fr;
  gap:12px;
  margin:12px 0;
}
.box{
  border:1px solid var(--line);
  border-radius:14px;
  overflow:hidden;
  background:rgba(255,255,255,.96);
}
.box .box-title{
  background:var(--soft);
  color:var(--brand);
  padding:9px 11px;
  font-weight:900;
  font-size:12px;
  text-transform:uppercase;
  border-bottom:1px solid var(--line);
}
.box .box-body{
  padding:10px 11px;
  font-size:12px;
  line-height:1.55;
}
.info-grid{
  display:grid;
  grid-template-columns:repeat(4,1fr);
  gap:8px;
  margin:12px 0;
}
.info{
  border:1px solid var(--line);
  background:rgba(251,252,255,.95);
  border-radius:12px;
  padding:9px;
  font-size:12px;
  min-height:50px;
}
.info b{
  display:block;
  color:var(--muted);
  font-size:10px;
  text-transform:uppercase;
  margin-bottom:5px;
}
table.report-table{
  width:100%;
  border-collapse:separate;
  border-spacing:0;
  margin-top:10px;
  font-size:10.7px;
  overflow:hidden;
  border:1px solid var(--line);
  border-radius:14px;
  background:rgba(255,255,255,.98);
}
.report-table thead th{
  background:var(--brand);
  color:#fff;
  padding:8px 6px;
  text-align:left;
  font-size:9.8px;
  text-transform:uppercase;
  letter-spacing:.02em;
}
.report-table tbody td{
  padding:8px 6px;
  border-bottom:1px solid #edf1f5;
  vertical-align:top;
}
.report-table tbody tr:nth-child(even) td{background:#fbfcff}
.report-table tbody tr:last-child td{border-bottom:0}
.num{text-align:right;white-space:nowrap}
.total-row td{
  background:#f3f7fb!important;
  font-weight:900;
  border-top:2px solid var(--brand);
}
.summary{
  display:grid;
  grid-template-columns:1.08fr .92fr;
  gap:12px;
  margin-top:12px;
}
.amount-box{
  border:2px solid var(--brand);
  border-radius:16px;
  overflow:hidden;
  background:#fff;
}
.amount-box .amount-title{
  background:var(--brand);
  color:#fff;
  padding:10px 12px;
  font-weight:900;
}
.amount-box .amount-line{
  display:flex;
  justify-content:space-between;
  gap:10px;
  padding:8px 12px;
  border-bottom:1px solid var(--line);
  font-size:12px;
}
.amount-box .amount-line:last-child{
  border-bottom:0;
  font-size:15px;
  font-weight:900;
  color:var(--brand);
}
.sign-row{
  display:grid;
  grid-template-columns:1fr 1fr;
  gap:30px;
  margin-top:34px;
  font-size:12px;
}
.sign{
  border-top:1px solid var(--ink);
  padding-top:8px;
}
.footer{
  position:absolute;
  bottom:7mm;
  left:12mm;
  right:12mm;
  display:flex;
  justify-content:space-between;
  color:var(--muted);
  font-size:10.5px;
  border-top:1px solid var(--line);
  padding-top:7px;
  z-index:2;
}
.badge{
  display:inline-block;
  border-radius:999px;
  padding:4px 8px;
  background:#ecfdf3;
  color:#067647;
  font-weight:900;
}
@media print{
  body{background:#fff;padding:0}
  .toolbar{display:none}
  .report-page{
    box-shadow:none;
    margin:0;
    width:210mm;
    min-height:297mm;
    page-break-after:always;
  }
}
@page{size:A4;margin:0}
</style>

</head>
<body>
<div class="toolbar">
  <div class="title">Commercial Invoice Preview</div>
  <div class="actions">
    <a class="btn secondary" href="shipment_view.php?id=<?= e($id) ?>">Back</a>
    <button class="btn" onclick="window.print()">Print / Save PDF</button>
  </div>
</div>

<section class="report-page">
  <?php rpt_watermark(); ?>
  <div class="report-content">
    <?php rpt_company_header('COMMERCIAL INVOICE', $shipment); ?>
    <?php rpt_party_boxes($shipment, false); ?>
    <?php rpt_info_grid($shipment); ?>

    <table class="report-table">
      <thead>
        <tr>
          <th>Sr</th>
          <th>Description of Goods</th>
          <?php if($showOptional): ?><th><?= e($optionalTitle) ?></th><?php endif; ?>
          <th class="num">Qty</th>
          <th>Unit</th>
          <th class="num">Rate</th>
          <th class="num">Amount</th>
        </tr>
      </thead>
      <tbody>
        <?php foreach($items as $i => $it): ?>
        <tr>
          <td><?= e($it['line_no'] ?? ($i+1)) ?></td>
          <td>
            <b><?= e($it['product_name'] ?? '') ?></b>
            <?php if(rpt_has_value($it['des_col'] ?? '')): ?><br><?= e($it['des_col']) ?><?php endif; ?>
          </td>
          <?php if($showOptional): ?><td><?= e($it['optional_value'] ?? '') ?></td><?php endif; ?>
          <td class="num"><?= e(rpt_qty($it['qty'] ?? 0)) ?></td>
          <td><?= e($it['unit'] ?? '') ?></td>
          <td class="num"><?= e(rpt_num($it['rate'] ?? 0, 4)) ?></td>
          <td class="num"><?= e(rpt_num($it['amount'] ?? 0, 2)) ?></td>
        </tr>
        <?php endforeach; ?>
        <tr class="total-row">
          <td colspan="<?= e($colspan) ?>">Total</td>
          <td class="num"><?= e(rpt_qty($totalQty)) ?></td>
          <td></td>
          <td></td>
          <td class="num"><?= e(rpt_num($totalAmount, 2)) ?></td>
        </tr>
      </tbody>
    </table>

    <div class="summary">
      <div class="box">
        <div class="box-title">Declaration</div>
        <div class="box-body">We hereby certify that the goods are of Pakistan origin and the particulars given above are true and correct to the best of our knowledge.</div>
      </div>
      <div class="amount-box">
        <div class="amount-title">Charges / Adjustments</div>
        <?php if ($charges): ?>
          <?php foreach ($charges as $ch): $chAmt = (float)($ch['amount'] ?? 0); ?>
            <div class="amount-line"><span><?= e(rpt_v($ch, 'charge_name', 'Charge')) ?></span><b style="color:<?= $chAmt < 0 ? '#b8283f' : '#16a34a' ?>"><?= e($currency) ?> <?= e(rpt_num($chAmt, 2)) ?></b></div>
          <?php endforeach; ?>
        <?php else: ?>
          <div class="amount-line"><span>No charges / adjustments</span><b>-</b></div>
        <?php endif; ?>
        <div class="amount-line"><span>Total Amount</span><b><?= e($currency) ?> <?= e(rpt_num($grandTotal, 2)) ?></b></div>
      </div>
    </div>

    <div class="sign-row">
      <div class="sign">Prepared By</div>
      <div class="sign">Authorized Signature & Stamp</div>
    </div>
  </div>
  <div class="footer"><span>Generated by ZAS Export Documentation System</span><span>Commercial Invoice | Page 1 of 1</span></div>
</section>
</body>
</html>
