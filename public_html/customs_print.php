<?php
/*
  CUSTOMS / CHAMBER PRINT — one file, four documents.

      ?view=customs|chamber  &  ?doc=invoice|packing

  The layout follows invoice_print.php so the three documents look like a set
  rather than three different companies. What changes is which lines are
  printed, never which shipment they belong to.

  THE GATE: a document whose total units differ from the commercial invoice
  describes goods that were not shipped, so it does not print. The check runs
  here as well as on the editing screen, because a print URL can be typed.
*/
require_once __DIR__ . '/includes/bootstrap.php';
require_once __DIR__ . '/includes/export.php';

require_login();
exp_ensure_schema();

$id = (int)($_GET['id'] ?? 0);
$st = db()->prepare("SELECT * FROM shipments WHERE id=?");
$st->execute([$id]);
$shipment = $st->fetch();

if (!$shipment || !can_view_shipment($id)) { http_response_code(404); exit('Shipment not found or not assigned.'); }
if (!expdoc_can_view()) { http_response_code(403); exit('Not permitted.'); }

$view = (string)($_GET['view'] ?? 'customs');
if (!expdoc_view_ok($view)) $view = 'customs';
$doc = ($_GET['doc'] ?? 'invoice') === 'packing' ? 'packing' : 'invoice';

$lines = expdoc_lines($id, $view);
$check = expdoc_check($id, $view, $lines);

if (!$check['can_print']) {
    http_response_code(409);
    page_header(EXPDOC_VIEWS[$view] . ' Invoice');
    echo exp_page_css();
    echo '<div class="xcard"><h2>This document is not ready to print</h2><div class="xwarn">'
       . ($check['empty']
            ? 'The ' . e(strtolower(EXPDOC_VIEWS[$view])) . ' document has no lines yet.'
            : 'Total units are ' . e(trim_num($check['qty'], 3)) . ' but the commercial invoice shipped '
              . e(trim_num($check['src_qty'], 3)) . '. A document that declares a different quantity '
              . 'from the goods that went cannot be printed.')
       . '</div><div style="margin-top:12px"><a class="xbtn" href="shipment_customs.php?id=' . $id
       . '&view=' . e($view) . '">Back to the document</a></div></div>';
    page_footer();
    exit;
}

$packs = [];
if ($doc === 'packing') {
    $ps = db()->prepare("SELECT * FROM packing_items WHERE shipment_id=? ORDER BY line_no,id");
    $ps->execute([$id]);
    $packs = $ps->fetchAll();
}

/* Printing is a read, but of a document whose value may differ from the
   commercial invoice — so who printed it, and when, is worth keeping. */
try {
    audit_log($id, EXPDOC_VIEWS[$view] . ' Document', 'print', '',
              ucfirst($doc) . ' printed, value ' . number_format((float)$check['value'], 2),
              'Commercial ' . number_format((float)$check['src_value'], 2)
              . ', difference ' . (($check['value_diff'] >= 0 ? '+' : '') . number_format((float)$check['value_diff'], 2)));
} catch (Throwable $e) {}

$cur  = (string)($shipment['currency'] ?? 'USD');
$nm   = EXPDOC_VIEWS[$view];
$totQ = 0; $totV = 0;
foreach ($lines as $l) { $totQ += (float)$l['qty']; $totV += (float)$l['amount']; }

$pQ = 0; $pN = 0; $pG = 0;
foreach ($packs as $p) { $pQ += (float)$p['total_qty']; $pN += (float)$p['net_weight']; $pG += (float)$p['gross_weight']; }

function cp_v($v, $d = '—') { $v = trim((string)$v); return $v === '' ? $d : $v; }
function cp_n($v, $dec = 2) { return number_format((float)$v, $dec); }
function cp_q($v) { return rtrim(rtrim(number_format((float)$v, 3, '.', ','), '0'), '.'); }
?><!doctype html>
<html><head><meta charset="utf-8">
<title><?= e($nm) ?> <?= $doc === 'packing' ? 'Packing List' : 'Invoice' ?> — <?= e($shipment['invoice_no']) ?></title>
<style>
*{box-sizing:border-box}
body{font-family:'Segoe UI',Arial,sans-serif;color:#111;background:#fff;margin:0;padding:22px;font-size:12px;line-height:1.45}
.sheet{max-width:860px;margin:0 auto}
.hd{display:flex;justify-content:space-between;align-items:flex-start;gap:16px;border-bottom:2px solid #111;padding-bottom:10px}
.co{font-size:19px;font-weight:800;letter-spacing:.01em}
.co small{display:block;font-size:11px;font-weight:400;color:#555;margin-top:2px}
.tt{text-align:right}
.tt .k{font-size:15px;font-weight:800;text-transform:uppercase;letter-spacing:.06em}
.tt .s{font-size:11px;color:#555;margin-top:2px}
.meta{display:grid;grid-template-columns:1fr 1fr;gap:14px;margin:14px 0}
.box{border:1px solid #bbb;padding:9px 11px}
.box h4{margin:0 0 5px;font-size:9.5px;text-transform:uppercase;letter-spacing:.07em;color:#555;font-weight:700}
.kv{display:flex;gap:6px;font-size:11.5px;padding:1px 0}
.kv .k{color:#555;min-width:92px}
.kv .v{font-weight:600}
table{width:100%;border-collapse:collapse;margin-top:6px;font-size:11.5px}
th{background:#f0f0f0;border:1px solid #bbb;padding:6px 7px;text-align:left;font-size:9.5px;
   text-transform:uppercase;letter-spacing:.05em}
td{border:1px solid #ccc;padding:6px 7px;vertical-align:top}
td.n,th.n{text-align:right;white-space:nowrap;font-variant-numeric:tabular-nums}
tfoot td{background:#f6f6f6;font-weight:800}
.foot{margin-top:20px;display:flex;justify-content:space-between;gap:20px;font-size:11px;color:#555}
.sign{margin-top:34px;text-align:right}
.sign .ln{display:inline-block;border-top:1px solid #111;padding-top:4px;min-width:210px;text-align:center;font-size:11px}
.note{margin-top:12px;border:1px solid #bbb;padding:8px 10px;font-size:10.5px;color:#444}
@media print{body{padding:0}.noprint{display:none}}
.noprint{margin-bottom:14px;display:flex;gap:8px}
.noprint button,.noprint a{padding:8px 15px;border-radius:8px;border:1px solid #bbb;background:#f4f4f4;
  font:inherit;font-size:12px;font-weight:700;cursor:pointer;text-decoration:none;color:#111}
</style></head><body>
<div class="sheet">

  <div class="noprint">
    <button onclick="window.print()">Print</button>
    <a href="shipment_customs.php?id=<?= $id ?>&view=<?= e($view) ?>">Back</a>
  </div>

  <div class="hd">
    <div class="co">ZAS TEXTILE<small>Faisalabad, Pakistan</small></div>
    <div class="tt">
      <div class="k"><?= e($nm) ?> <?= $doc === 'packing' ? 'Packing List' : 'Invoice' ?></div>
      <div class="s">No. <?= e($shipment['invoice_no']) ?></div>
      <div class="s"><?= e($shipment['invoice_date'] ? date('d M Y', strtotime((string)$shipment['invoice_date'])) : '') ?></div>
    </div>
  </div>

  <div class="meta">
    <div class="box">
      <h4>Consignee</h4>
      <div style="font-weight:700;font-size:12.5px"><?= e($shipment['buyer_name']) ?></div>
      <?php if (!empty($shipment['buyer_address'])): ?>
        <div style="color:#444;margin-top:2px;white-space:pre-line"><?= e($shipment['buyer_address']) ?></div>
      <?php endif; ?>
      <?php if (!empty($shipment['buyer_country'])): ?>
        <div style="margin-top:3px"><?= e($shipment['buyer_country']) ?></div>
      <?php endif; ?>
    </div>
    <div class="box">
      <h4>Shipment</h4>
      <div class="kv"><span class="k">Destination</span><span class="v"><?= e(cp_v($shipment['destination_port'])) ?></span></div>
      <div class="kv"><span class="k">Incoterm</span><span class="v"><?= e(cp_v($shipment['incoterm'])) ?></span></div>
      <div class="kv"><span class="k">PO No.</span><span class="v"><?= e(cp_v($shipment['po_no'])) ?></span></div>
      <div class="kv"><span class="k">Currency</span><span class="v"><?= e($cur) ?></span></div>
      <?php
      $log = exp_logistics($id);
      if (!empty($log['bl_no'])): ?>
        <div class="kv"><span class="k">BL No.</span><span class="v"><?= e($log['bl_no']) ?></span></div>
      <?php endif; ?>
      <?php if (!empty($log['vessel_name'])): ?>
        <div class="kv"><span class="k">Vessel</span><span class="v"><?= e($log['vessel_name']) ?><?= $log['voyage_no'] ? ' / ' . e($log['voyage_no']) : '' ?></span></div>
      <?php endif; ?>
    </div>
  </div>

<?php if ($doc === 'invoice'): ?>

  <table>
    <thead><tr>
      <th style="width:34px" class="n">#</th>
      <th>Description of Goods</th>
      <th style="width:90px">HS Code</th>
      <th style="width:60px">Unit</th>
      <th style="width:84px" class="n">Quantity</th>
      <th style="width:84px" class="n">Rate</th>
      <th style="width:104px" class="n">Amount</th>
    </tr></thead>
    <tbody>
    <?php foreach ($lines as $i => $l): ?>
      <tr>
        <td class="n"><?= $i + 1 ?></td>
        <td><?= e($l['description']) ?></td>
        <td><?= e(cp_v($l['hs_code'], '')) ?></td>
        <td><?= e(cp_v($l['unit'], '')) ?></td>
        <td class="n"><?= e(cp_q($l['qty'])) ?></td>
        <td class="n"><?= e(cp_n($l['rate'], 2)) ?></td>
        <td class="n"><?= e(cp_n($l['amount'], 2)) ?></td>
      </tr>
    <?php endforeach; ?>
    </tbody>
    <tfoot><tr>
      <td colspan="4">Total</td>
      <td class="n"><?= e(cp_q($totQ)) ?></td>
      <td></td>
      <td class="n"><?= e($cur . ' ' . cp_n($totV, 2)) ?></td>
    </tr></tfoot>
  </table>

  <?php if (!empty($shipment['payment_terms'])): ?>
    <div class="note"><b>Payment Terms:</b> <?= e($shipment['payment_terms']) ?></div>
  <?php endif; ?>

<?php else: /* packing */ ?>

  <table>
    <thead><tr>
      <th style="width:34px" class="n">#</th>
      <th>Description of Goods</th>
      <th style="width:90px">HS Code</th>
      <th style="width:84px" class="n">Quantity</th>
    </tr></thead>
    <tbody>
    <?php foreach ($lines as $i => $l): ?>
      <tr>
        <td class="n"><?= $i + 1 ?></td>
        <td><?= e($l['description']) ?></td>
        <td><?= e(cp_v($l['hs_code'], '')) ?></td>
        <td class="n"><?= e(cp_q($l['qty'])) ?></td>
      </tr>
    <?php endforeach; ?>
    </tbody>
    <tfoot><tr><td colspan="3">Total</td><td class="n"><?= e(cp_q($totQ)) ?></td></tr></tfoot>
  </table>

  <?php if ($packs): ?>
  <h4 style="margin:16px 0 0;font-size:10px;text-transform:uppercase;letter-spacing:.07em;color:#555">Cartons and Weights</h4>
  <table>
    <thead><tr>
      <th>Pack Unit</th><th class="n">From</th><th class="n">To</th>
      <th class="n">Units</th><th class="n">Net kg</th><th class="n">Gross kg</th>
    </tr></thead>
    <tbody>
    <?php foreach ($packs as $p): ?>
      <tr>
        <td><?= e(cp_v($p['pack_unit_title'] ?? 'Carton', 'Carton')) ?></td>
        <td class="n"><?= (int)$p['carton_from'] ?></td>
        <td class="n"><?= (int)$p['carton_to'] ?></td>
        <td class="n"><?= e(cp_q($p['packages'])) ?></td>
        <td class="n"><?= e(cp_n($p['net_weight'], 3)) ?></td>
        <td class="n"><?= e(cp_n($p['gross_weight'], 3)) ?></td>
      </tr>
    <?php endforeach; ?>
    </tbody>
    <tfoot><tr>
      <td colspan="3">Total</td>
      <td class="n"><?= e(cp_q($shipment['total_packages'])) ?></td>
      <td class="n"><?= e(cp_n($pN, 3)) ?></td>
      <td class="n"><?= e(cp_n($pG, 3)) ?></td>
    </tr></tfoot>
  </table>
  <?php else: ?>
    <div class="note">No packing rows have been entered on the Packing List screen yet.</div>
  <?php endif; ?>

<?php endif; ?>

  <div class="sign"><span class="ln">For ZAS TEXTILE</span></div>

  <div class="foot">
    <span>Generated by ZAS Export Documentation System</span>
    <span><?= e($nm) ?> <?= $doc === 'packing' ? 'Packing List' : 'Invoice' ?> &middot; <?= e($shipment['invoice_no']) ?></span>
  </div>
</div>
</body></html>
