<?php
/*
  Printable costing report for one shipment — same deterministic Stage-1
  engine as AI Check (includes/ai_check_core.php), independent of whether
  AI Check has ever been run on-screen for this shipment. One block per
  invoice line ("quality") with its own material consumption sub-table,
  plus a combined total across the whole shipment at the end.
*/
require_once __DIR__ . '/includes/bootstrap.php';
require_once __DIR__ . '/includes/pm_search.php';
require_once __DIR__ . '/includes/ai_check_core.php';
require_once __DIR__ . '/includes/final_costing.php';
require_login();

if (!is_admin()) { http_response_code(403); exit('Costing report is available to Admin only.'); }

$id = (int)($_GET['id'] ?? 0);
$result = ai_check_compute($id);
if (!$result) { http_response_code(404); exit('Shipment not found.'); }

$s = $result['shipment'];
$cur = $s['currency'] ?: 'PKR';
$fx = $result['FX'];
$f = $result['financials'];

/* Swap in locked Final (Actual) costing per line where it exists — the
   estimate stays as the fallback for any line that hasn't been finalised
   yet. Also re-derives the financial summary so Sales/Cost/Profit reflect
   whichever mix of actual vs estimated costs is currently locked. */
fc_ensure_schema();
$finalsByItemId = fc_get_for_shipment($id);
$costTotalReal = 0.0;
foreach ($result['line_costing'] as &$lcRef) {
    $itemRow = null;
    foreach ($result['items'] as $it) { if ((int)$it['line_no'] === (int)$lcRef['line']) { $itemRow = $it; break; } }
    $fcRow = $itemRow ? ($finalsByItemId[(int)$itemRow['id']] ?? null) : null;
    $isFinal = $fcRow && $fcRow['status'] === 'locked';
    $lcRef['costing_source'] = $isFinal ? 'final' : 'estimate';
    if ($isFinal) {
        $lcRef['line_cost'] = (float)$fcRow['total_cost'];
        $lcRef['unit_cost'] = $lcRef['qty'] > 0 ? round($lcRef['line_cost'] / $lcRef['qty'], 4) : 0;
        $lcRef['gross_profit'] = $lcRef['rate'] > 0 ? round(($lcRef['rate'] - $lcRef['unit_cost']) * $lcRef['qty'], 2) : null;
        $lcRef['margin_pct'] = $lcRef['rate'] > 0 ? round((($lcRef['rate'] - $lcRef['unit_cost']) / $lcRef['rate']) * 100, 2) : null;
        $lcRef['materials'] = array_map(function ($l) use ($lcRef, $cur, $fx) {
            $mq = (float)$l['quantity']; $mwt = (float)$l['weight_kg']; $isShared = !empty($l['shared']);
            // same shared-vs-normal per-unit math as the estimate engine (includes/ai_check_core.php):
            // shared -> quantity field is the SHARE COUNT (divide); normal -> quantity is already per-piece (multiply).
            $eachQty = $isShared ? ($mq > 0 ? 1 / $mq : 0) : $mq;
            $eachWt = $isShared ? ($mq > 0 ? $mwt / $mq : $mwt) : $mwt;
            // Rate/amount stay in the line's own native currency (Final Costing now shows the same
            // figures Product Costing does) for the per-line table below. Only the grand-total
            // "Material Utilisation" row (via rp_both, labelled with $cur) needs converting so it's
            // not mislabelled as the shipment currency when it's actually still native.
            $nativeCur = strtoupper((string)($l['native_currency'] ?? '')) ?: $cur;
            $costNative = (float)$l['amount'] * $lcRef['qty'];
            $costWorking = ($nativeCur === strtoupper($cur)) ? $costNative : cvt($costNative, $nativeCur, $cur, $fx);
            return ['category' => $l['line_group'], 'material' => $l['item_name'], 'unit' => $l['unit'], 'description' => (string)($l['description'] ?? ''), 'rate' => (float)$l['rate'], 'rate_native' => (float)$l['rate'],
                    'consum_each' => $eachQty, 'wt_each' => $eachWt, 'qty' => $eachQty * $lcRef['qty'], 'wt' => $eachWt * $lcRef['qty'],
                    'cost' => $costWorking, 'shared' => $isShared, 'share_qty' => $isShared ? $mq : 0, 'weight_raw' => $mwt];
        }, $fcRow['lines']);
    }
    $costTotalReal += (float)($lcRef['line_cost'] ?? 0);
}
unset($lcRef);
$f['estimated_cost_total'] = round($costTotalReal, 2);
$f['gross_profit'] = round($f['sales_total'] - $costTotalReal, 2);
$f['gross_margin_percent'] = $f['sales_total'] > 0 ? round($f['gross_profit'] / $f['sales_total'] * 100, 2) : 0;
$costTotalRealPkr = cvt($costTotalReal, $cur, 'PKR', $fx);
$f['estimated_cost_total_pkr'] = round($costTotalRealPkr, 2);
$f['gross_profit_pkr'] = round($f['sales_total_pkr'] - $costTotalRealPkr, 2);

/* value shown as "CUR amount (₨pkr)" — PKR shown alongside only when the
   invoice currency isn't already PKR, so we never show "(₨X (₨X))" */
function rp_both($amt, string $cur, array $fx): string {
    $s = number_format((float)$amt, 2);
    if (strtoupper($cur) !== 'PKR') {
        $pkr = cvt((float)$amt, $cur, 'PKR', $fx);
        $s .= ' <span class="pkr">(₨' . number_format($pkr, 0) . ')</span>';
    }
    return $s;
}
function rp_qty($n): string {
    $n = (float)$n;
    $s = number_format($n, 3, '.', '');
    if (strpos($s, '.') !== false) $s = rtrim(rtrim($s, '0'), '.');
    return $s;
}
/* small light italic note under a cell — same convention as costing_print.php's
   shared-line notes, so a shared material's real split is always visible,
   never just a bare small number with no context. */
function rp_note(string $t): string {
    return '<div style="font-size:8px;color:#8a97ab;font-weight:400;font-style:italic;line-height:1.25;margin-top:1px">'.$t.'</div>';
}
function rp_mat_table(array $mats, string $cur, array $fx): string {
    if (!$mats) return '<p style="color:#8a97ab;font-size:11px;margin:4px 0 0">No costed materials for this line.</p>';
    usort($mats, fn($a,$b)=>strcmp($a['category'].$a['material'],$b['category'].$b['material']));
    $h = '<div class="tablewrap"><table><thead><tr><th>Group</th><th>Item</th><th>Description</th><th class="num">Qty</th><th class="num">Total Qty</th><th class="num">Wt</th><th class="num">Total Wt</th><th class="num">Rate</th><th class="num">Total Amount</th></tr></thead><tbody>';
    foreach ($mats as $m) {
        $shared = !empty($m['shared']);
        $unit = trim((string)($m['unit'] ?? '')) !== '' ? trim((string)($m['unit'] ?? '')) : 'unit';
        $shareQty = $shared ? rp_qty($m['share_qty'] ?? 0) : '';
        $consumEach = (float)($m['consum_each'] ?? 0);
        $wtEach = (float)($m['wt_each'] ?? 0);
        $rate = (float)($m['rate_native'] ?? $m['rate'] ?? 0);
        $totalQty = (float)($m['qty'] ?? 0);
        $totalAmt = $totalQty * $rate; // Total Qty x Rate = total order cost for this material on this line
        // formatting matches costing_print.php's cst_line_row() exactly: fixed 3dp for qty/wt,
        // no trimming, no dash for zero. No separate Unit column — the unit shows as a
        // small note under Qty / Total Qty / Rate instead, same convention as "Per cone".
        $qtyCell = number_format($consumEach,3) . ($shared ? rp_note('1 '.e($unit).' &divide; '.$shareQty.' pcs') : rp_note(e($unit)));
        $totalQtyCell = rp_qty($totalQty) . rp_note(e($unit));
        $totalWtCell = rp_qty($m['wt']) . rp_note('Kg');
        $wtCell = number_format($wtEach,3) . ($shared ? rp_note(number_format((float)($m['weight_raw'] ?? 0),3).' kg &divide; '.$shareQty.' pcs') : '');
        $rateCell = number_format($rate,2) . rp_note('Per '.e($unit));
        $h .= '<tr><td>'.e($m['category']).'</td><td>'.e($m['material']).'</td><td>'.e($m['description'] ?? '').'</td>'
            .'<td class="num">'.$qtyCell.'</td>'
            .'<td class="num">'.$totalQtyCell.'</td>'
            .'<td class="num">'.$wtCell.'</td>'
            .'<td class="num">'.$totalWtCell.'</td>'
            .'<td class="num">'.$rateCell.'</td>'
            .'<td class="num">'.number_format($totalAmt,2).'</td></tr>';
    }
    $h .= '</tbody></table></div>';
    return $h;
}
?><!doctype html><html><head><meta charset="utf-8"><title>Costing Report — <?= e($s['invoice_no']) ?></title><style>
:root{--navy:#0b2a4a;--gold:#b8891f;--ink:#152033;--muted:#5a6b82;--faint:#8a97ab;--line:#e3e9f0;--nest:#f6f8fb;--gold-soft:rgba(184,137,31,.08);--green:#16a34a;--amber:#a25c04;--red:#b8283f}
@page{size:A4;margin:14mm}
*{box-sizing:border-box}
body{font:12px/1.55 "Segoe UI",Arial,sans-serif;color:var(--ink);margin:0;background:#eef1f6}
.page{max-width:900px;margin:0 auto;padding:0 0 40px}
.doc{background:#fff;padding:8px}
.noprint{max-width:900px;margin:14px auto;text-align:right;padding:0 4px}
.btn{background:var(--navy);color:#fff;border:0;border-radius:7px;padding:9px 18px;font-weight:700;font-size:12.5px;cursor:pointer;text-decoration:none;display:inline-block}
.head{display:flex;justify-content:space-between;align-items:flex-start;border-bottom:3px solid var(--navy);padding-bottom:12px;margin-bottom:16px}
.brand{font-size:22px;font-weight:800;color:var(--navy)}
.brand small{display:block;font-size:9.5px;font-weight:700;color:var(--gold);letter-spacing:2px;text-transform:uppercase;margin-top:2px}
.docttl{text-align:right}.docttl h1{margin:0;font-size:17px;color:var(--navy);text-transform:uppercase;letter-spacing:.8px}
.docttl .meta{font-size:11px;color:var(--muted);margin-top:5px}
.row{display:flex;gap:20px;margin-bottom:14px;flex-wrap:wrap}.row>div{flex:1;min-width:100px}
.lbl{font-size:9px;text-transform:uppercase;letter-spacing:.06em;color:var(--faint);font-weight:700}
.val{font-size:12.5px;color:var(--ink);margin-top:3px;font-weight:600}
.sect{font-size:11px;font-weight:800;color:var(--navy);text-transform:uppercase;letter-spacing:.05em;margin:20px 0 8px;border-left:3px solid var(--gold);padding-left:8px}
.kpis{display:grid;grid-template-columns:repeat(auto-fit,minmax(150px,1fr));gap:10px}
.kpi{border:1px solid var(--line);border-radius:8px;padding:10px 12px}
.kpi .lbl{margin-bottom:4px}.kpi .num{font-size:15px;font-weight:800;color:var(--navy)}
.kpi.profit .num{color:var(--green)}.kpi.loss .num{color:var(--red)}
.pkr{color:var(--faint);font-weight:600;font-size:11px}
table{width:100%;border-collapse:collapse;margin:6px 0}
th{background:var(--navy);color:#fff;font-size:9px;text-transform:uppercase;letter-spacing:.04em;text-align:left;padding:7px 8px;font-weight:700}
td{padding:6px 8px;border-bottom:1px solid var(--line);font-size:11px}
.num{text-align:right}
tr.tot td{border-top:2px solid var(--navy);font-weight:800;font-size:11.5px;background:var(--gold-soft)}
.quality{border:1px solid var(--line);border-radius:9px;margin-bottom:14px;overflow:hidden}
.quality-head{background:var(--nest);padding:11px 14px;border-bottom:1px solid var(--line);display:flex;justify-content:space-between;align-items:center;gap:12px;flex-wrap:wrap}
.quality-head .qname{font-size:13px;font-weight:800;color:var(--navy)}
.quality-head .qdesc{font-size:10.5px;color:var(--muted);margin-top:2px}
.match-pill{font-size:10px;font-weight:700;padding:4px 9px;border-radius:20px;white-space:nowrap}
.match-pill.hi{background:rgba(22,163,74,.12);color:var(--green)}
.match-pill.lo{background:rgba(217,119,6,.14);color:var(--amber)}
.source-pill{font-size:10px;font-weight:700;padding:4px 9px;border-radius:20px;white-space:nowrap}
.source-pill.final{background:rgba(22,163,74,.14);color:var(--green)}
.source-pill.estimate{background:#eef1f6;color:var(--muted)}
.quality-body{padding:12px 14px}
.qrow{display:flex;gap:24px;flex-wrap:wrap;margin-bottom:10px;font-size:11.5px}
.qrow .item{min-width:80px}.qrow .item .lbl{font-size:9px}.qrow .item .val{margin-top:2px}
.subtable-lbl{font-size:10px;font-weight:700;color:var(--faint);text-transform:uppercase;letter-spacing:.05em;margin:10px 0 5px}
.quality-body table th{background:#eef1f6;color:var(--navy);font-size:8.5px}
.quality-body table td{font-size:10.5px}
.tablewrap{overflow-x:auto}
.remarks{margin-top:12px;padding:10px 12px;background:var(--nest);border:1px solid var(--line);border-radius:7px;font-size:10.5px;color:#3a4a60}
.sign{display:flex;justify-content:space-between;margin-top:40px;font-size:11px}
.sign div{width:38%;border-top:1px solid #98a6b8;padding-top:6px;text-align:center;color:var(--muted)}
@media print{.noprint{display:none}body{background:#fff}}
</style></head><body>
<div class="noprint"><a class="btn" href="javascript:window.print()">Print / Save PDF</a></div>
<div class="page"><div class="doc">

  <div class="head">
    <div class="brand">ZAS TEXTILE<small>Textile Exports</small></div>
    <div class="docttl"><h1>Costing Report</h1><div class="meta">Generated <?= e(date('d M Y, H:i')) ?> by <?= e(current_user()['name'] ?? '') ?></div></div>
  </div>

  <div class="row">
    <div><div class="lbl">Invoice No.</div><div class="val"><?= e($s['invoice_no']) ?></div></div>
    <div><div class="lbl">Buyer</div><div class="val"><?= e($s['buyer_name']) ?></div></div>
    <div><div class="lbl">Country</div><div class="val"><?= e($s['buyer_country'] ?: '—') ?></div></div>
    <div><div class="lbl">Currency</div><div class="val"><?= e($cur) ?></div></div>
    <div><div class="lbl">Status</div><div class="val"><?= e(ucwords(str_replace('_',' ',$s['status']))) ?></div></div>
  </div>

  <div class="sect">Financial Summary</div>
  <div class="kpis">
    <div class="kpi"><div class="lbl">Sales Total</div><div class="num"><?= rp_both($f['sales_total'],$cur,$fx) ?></div></div>
    <div class="kpi"><div class="lbl">Estimated Cost</div><div class="num"><?= rp_both($f['estimated_cost_total'],$cur,$fx) ?></div></div>
    <div class="kpi <?= $f['gross_profit']<0?'loss':'profit' ?>"><div class="lbl">Gross Profit</div><div class="num"><?= rp_both($f['gross_profit'],$cur,$fx) ?></div></div>
    <div class="kpi <?= $f['gross_margin_percent']<0?'loss':'profit' ?>"><div class="lbl">Margin</div><div class="num"><?= e($f['gross_margin_percent']) ?>%</div></div>
  </div>
  <?php if (!$f['cost_complete']): ?><div class="remarks">Costing is partial — one or more products on this invoice have no matching Product Costing version, so totals above only reflect the products that ARE costed.</div><?php endif; ?>

  <div class="sect">Line Items — Costing &amp; Material Detail</div>
  <?php $grandMats = []; foreach ($result['line_costing'] as $lc):
      $pct = $lc['match_pct'];
      $pillClass = ($pct !== null && $pct >= 95) ? 'hi' : 'lo';
      $pillText = $pct === null ? 'Not in Master' : ($lc['matched_product'] === $lc['product_name'] || $pct>=100 ? 'Matched: '.$lc['matched_product'] : 'Matched: '.$lc['matched_product'].' ('.$pct.'% match)');
      foreach ($lc['materials'] as $m) {
          // Grand total groups by category+material+unit only — price is NOT
          // part of the key here, so the same material at a different rate on
          // another line still combines into one summed row (qty & cost add up
          // fine regardless of price; only category/material actually changing
          // starts a new row).
          $k = $m['category'].'|'.$m['material'].'|'.$m['unit'];
          if (!isset($grandMats[$k])) $grandMats[$k] = ['category'=>$m['category'],'material'=>$m['material'],'unit'=>$m['unit'],'qty'=>0,'wt'=>0,'cost'=>0];
          $grandMats[$k]['qty'] += $m['qty']; $grandMats[$k]['wt'] += $m['wt']; $grandMats[$k]['cost'] += $m['cost'];
      }
  ?>
  <div class="quality">
    <div class="quality-head">
      <div><div class="qname">Line <?= (int)$lc['line'] ?> · <?= e($lc['product_name']) ?></div><?php if($lc['description']): ?><div class="qdesc"><?= e($lc['description']) ?></div><?php endif; ?></div>
      <div style="display:flex;gap:6px;align-items:center;flex-wrap:wrap">
        <span class="source-pill <?= $lc['costing_source']==='final'?'final':'estimate' ?>"><?= $lc['costing_source']==='final' ? 'FINAL (ACTUAL)' : 'ESTIMATED' ?></span>
        <?php if ($lc['master_found']): ?><span class="match-pill <?= $pillClass ?>"><?= e($pillText) ?></span><?php else: ?><span class="match-pill lo">Not in Product Master</span><?php endif; ?>
      </div>
    </div>
    <div class="quality-body">
      <div class="qrow">
        <div class="item"><div class="lbl">Qty</div><div class="val"><?= rp_qty($lc['qty']) ?> <?= e($lc['unit']) ?></div></div>
        <div class="item"><div class="lbl">Sale Rate</div><div class="val"><?= e($cur) ?> <?= number_format($lc['rate'],2) ?></div></div>
        <div class="item"><div class="lbl">Cost</div><div class="val"><?= $lc['line_cost']!==null ? rp_both($lc['line_cost'],$cur,$fx) : '—' ?></div></div>
        <div class="item"><div class="lbl">Margin</div><div class="val" style="<?= $lc['margin_pct']!==null && $lc['margin_pct']<8 ? 'color:var(--amber)' : ($lc['margin_pct']!==null && $lc['margin_pct']<0 ? 'color:var(--red)' : 'color:var(--green)') ?>"><?= $lc['margin_pct']!==null ? $lc['margin_pct'].'%'.($lc['note']?' — '.e($lc['note']):'') : ($lc['note'] ?: '—') ?></div></div>
      </div>
      <div class="subtable-lbl">Material Consumption — this line</div>
      <?= rp_mat_table($lc['materials'], $cur, $fx) ?>
    </div>
  </div>
  <?php endforeach; ?>

  <div class="sect">Material Utilisation — Total Across Whole Shipment <span style="color:var(--faint);font-weight:600;text-transform:none;letter-spacing:0">— same category + material + unit combined into one row, regardless of price</span></div>
  <?php
    $grandList = array_values($grandMats);
    usort($grandList, fn($a,$b)=>strcmp($a['category'].$a['material'],$b['category'].$b['material']));
    $grandCost = array_sum(array_column($grandList,'cost'));
  ?>
  <div class="tablewrap"><table>
    <thead><tr><th>Category</th><th>Material</th><th>Unit</th><th class="num">Total Qty (approx)</th><th class="num">Wt Total</th><th class="num">Total Value</th></tr></thead>
    <tbody>
      <?php if ($grandList): foreach ($grandList as $m): ?>
      <tr><td><?= e($m['category']) ?></td><td><?= e($m['material']) ?></td><td><?= e($m['unit']) ?></td>
        <td class="num"><?= rp_qty($m['qty']) ?></td>
        <td class="num"><?= $m['wt']>0 ? rp_qty($m['wt']) : '—' ?></td>
        <td class="num"><?= rp_both($m['cost'],$cur,$fx) ?></td></tr>
      <?php endforeach; else: ?><tr><td colspan="6" style="text-align:center;color:#8a97ab">No costed materials on this shipment.</td></tr><?php endif; ?>
      <?php if ($grandList): ?><tr class="tot"><td colspan="5">Total Material Cost</td><td class="num"><?= rp_both($grandCost,$cur,$fx) ?></td></tr><?php endif; ?>
    </tbody>
  </table></div>

  <div class="sign"><div>Prepared by — <?= e(current_user()['name'] ?? '') ?></div><div>Approved by</div></div>

</div></div>
</body></html>
