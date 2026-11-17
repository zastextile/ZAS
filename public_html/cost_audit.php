<?php
/* Cost Audit — admin-only diagnostic. Lists every shipment line for one
   product side-by-side with its matched Final Costing (if any), so an
   unusually high or low markup can be traced back to the exact shipment
   and Final Costing record behind it, instead of just a dashboard %.
   Safe to leave in place — read-only, no writes. */
require_once __DIR__ . '/includes/bootstrap.php';
require_login();
if (!is_admin()) { http_response_code(403); exit('Admin access required.'); }

$product = trim($_GET['product'] ?? '');
$from = $_GET['from'] ?? date('Y-m-d', strtotime('-90 days'));
$to = $_GET['to'] ?? date('Y-m-d');

/* Quick-pick list — every product with sales in this date range, ranked by
   markup % (same break-even house rule as the dashboard: an uncosted item
   counts its own sale price as its cost) so the highest/oddest markups sort
   to the top without having to already know which product name to type. */
/* A FINAL COSTING IS IN ITS OWN CURRENCY, WHICH IS NOT ALWAYS THE INVOICE'S.
   This page used to treat every cost as if it were in the invoice currency:
   a GBP 1.40 costing on a PKR invoice was read as Rs 1.40, and showed a
   72,458% markup that was not real. Each cost is now converted with the
   rates in Settings, the same way the dashboard does (a currency with no
   rate counts as 1, as there), and the product ranking is worked out in
   PKR so lines in different currencies add up. A costing with no cost in
   it (total 0) is not counted as costed — also as on the dashboard. */
$fx = fx_get_rates();
$pkrPer = function (?string $cur) use ($fx): float {            // PKR for one unit of the currency
    $cur = strtoupper(trim((string)$cur)); if ($cur === '') $cur = 'PKR';
    if ($cur === 'PKR' || empty($fx[$cur]) || (float)$fx[$cur] <= 0) return 1.0;
    return 1 / (float)$fx[$cur];
};
$costToInv = function (float $cost, ?string $costCur, ?string $invCur) use ($pkrPer): float {
    $c = trim((string)$costCur) !== '' ? $costCur : $invCur;
    return $cost * $pkrPer($c) / $pkrPer($invCur);
};

$products = [];
$pst = db()->prepare("SELECT si.product_name grp, si.amount amt, si.qty, UPPER(COALESCE(s.currency,'PKR')) scur,
        fc.total_cost, fc.currency fcur
    FROM shipment_items si JOIN shipments s ON s.id = si.shipment_id
    LEFT JOIN final_costings fc ON fc.shipment_item_id = si.id AND fc.total_cost > 0
    WHERE COALESCE(s.invoice_date, DATE(s.created_at)) BETWEEN ? AND ?");
$pst->execute([$from, $to]);
foreach ($pst->fetchAll() as $r) {
    $raw = trim((string)$r['grp']); if ($raw === '') continue;
    $g = mb_strtolower($raw);
    if (!isset($products[$g])) $products[$g] = ['name' => $raw, 'sales' => 0.0, 'costed_sales' => 0.0, 'real_cost' => 0.0, 'lines' => 0];
    $salePkr = (float)$r['amt'] * $pkrPer($r['scur']);
    $products[$g]['sales'] += $salePkr;
    $products[$g]['lines']++;
    if ($r['total_cost'] !== null) {
        $products[$g]['costed_sales'] += $salePkr;
        $products[$g]['real_cost'] += $costToInv((float)$r['total_cost'], $r['fcur'], $r['scur']) * (float)$r['qty'] * $pkrPer($r['scur']);
    }
}
$productList = [];
foreach ($products as $p) {
    $breakevenAmt = max(0.0, $p['sales'] - $p['costed_sales']);
    $totalCost = $p['real_cost'] + $breakevenAmt;
    $pct = $totalCost > 0 ? round(($p['sales'] - $totalCost) / $totalCost * 100, 1) : 0.0;
    $productList[] = ['name' => $p['name'], 'lines' => $p['lines'], 'pct' => $pct, 'has_uncosted' => $breakevenAmt > 1];
}
usort($productList, fn($a, $b) => $b['pct'] <=> $a['pct']);

$rows = [];
if ($product !== '') {
    $st = db()->prepare("SELECT s.id sid, s.invoice_no, s.buyer_name,
            COALESCE(s.invoice_date, DATE(s.created_at)) dt,
            UPPER(COALESCE(s.currency,'PKR')) cur,
            si.qty, si.rate, si.amount,
            fc.id fc_id, fc.total_cost, UPPER(COALESCE(NULLIF(fc.currency,''), s.currency, 'PKR')) fcur
        FROM shipment_items si
        JOIN shipments s ON s.id = si.shipment_id
        LEFT JOIN final_costings fc ON fc.shipment_item_id = si.id AND fc.total_cost > 0
        WHERE si.product_name LIKE ? AND COALESCE(s.invoice_date, DATE(s.created_at)) BETWEEN ? AND ?
        ORDER BY dt DESC, s.id DESC");
    $st->execute(['%' . $product . '%', $from, $to]);
    foreach ($st->fetchAll() as $r) {
        $sale = (float)$r['amount'];
        $costed = $r['fc_id'] !== null;
        $unitInv = $costed ? $costToInv((float)$r['total_cost'], $r['fcur'], $r['cur']) : null;   // in the invoice's currency
        $costTotal = $costed ? $unitInv * (float)$r['qty'] : null;
        $profit = $costed ? $sale - $costTotal : null;
        $pct = ($costed && $costTotal > 0) ? round($profit / $costTotal * 100, 1) : null;
        $rows[] = [
            'sid' => (int)$r['sid'], 'invoice_no' => $r['invoice_no'], 'buyer_name' => $r['buyer_name'],
            'dt' => $r['dt'], 'cur' => $r['cur'],
            'qty' => (float)$r['qty'], 'rate' => (float)$r['rate'], 'sale' => $sale,
            'costed' => $costed, 'unit_cost' => $unitInv,
            'entered' => ($costed && $r['fcur'] !== $r['cur']) ? $r['fcur'] . ' ' . number_format((float)$r['total_cost'], 2) : '',
            'cost_total' => $costTotal, 'profit' => $profit, 'pct' => $pct,
        ];
    }
}

$costedPcts = array_column(array_filter($rows, fn($r) => $r['pct'] !== null), 'pct');
sort($costedPcts);
$n = count($costedPcts);
$medianPct = $n > 0 ? $costedPcts[(int)floor(($n - 1) / 2)] : null;

page_header('Cost Audit');
?>
<div class="topbar"><div><h1>Cost Audit</h1><p class="lead">Every shipment line for a product, matched against its Final Costing — spot a bad cost entry that's skewing a dashboard %.</p></div></div>

<form method="get" style="display:flex;gap:10px;align-items:flex-end;flex-wrap:wrap;background:#fff;border:1px solid #e3e9f2;border-radius:14px;padding:16px 18px;margin-bottom:18px">
  <div><label style="display:block;font-size:11px;color:#8a97ab;margin-bottom:4px">Product name contains</label>
    <input type="text" name="product" value="<?= e($product) ?>" placeholder="e.g. Bath Towel" style="padding:8px 10px;border-radius:8px;border:1px solid #cbd5e3;font-size:13px;min-width:200px"></div>
  <div><label style="display:block;font-size:11px;color:#8a97ab;margin-bottom:4px">From</label>
    <input type="date" name="from" value="<?= e($from) ?>" style="padding:8px 10px;border-radius:8px;border:1px solid #cbd5e3;font-size:13px"></div>
  <div><label style="display:block;font-size:11px;color:#8a97ab;margin-bottom:4px">To</label>
    <input type="date" name="to" value="<?= e($to) ?>" style="padding:8px 10px;border-radius:8px;border:1px solid #cbd5e3;font-size:13px"></div>
  <button style="padding:9px 16px;border:none;border-radius:8px;background:linear-gradient(100deg,#0ea8c9,#6d5bd0);color:#fff;font-weight:700;font-size:13px;cursor:pointer">Search</button>
</form>

<?php if ($productList): ?>
<div style="background:#fff;border:1px solid #e3e9f2;border-radius:14px;padding:16px 18px;margin-bottom:18px">
  <p style="margin:0 0 10px;font-size:11px;color:#8a97ab;text-transform:uppercase;letter-spacing:.03em">All products in this date range — ranked by markup %, highest first</p>
  <div style="display:flex;flex-wrap:wrap;gap:8px">
    <?php foreach ($productList as $p):
      $isCur = mb_strtolower($product) === mb_strtolower($p['name']);
      $bad = $p['pct'] > 60 || $p['pct'] < 0;
    ?>
    <a href="?product=<?= urlencode($p['name']) ?>&from=<?= e($from) ?>&to=<?= e($to) ?>"
       style="text-decoration:none;font-size:12px;font-weight:600;padding:6px 12px;border-radius:20px;
              background:<?= $isCur ? '#152033' : ($bad ? 'rgba(192,41,63,.08)' : '#f6f8fc') ?>;
              color:<?= $isCur ? '#fff' : ($bad ? '#c0293f' : '#33415c') ?>;
              border:1px solid <?= $isCur ? '#152033' : ($bad ? 'rgba(192,41,63,.25)' : '#e3e9f2') ?>">
      <?= e($p['name']) ?> · <?= e((string)$p['pct']) ?>%<?= $p['has_uncosted'] ? ' ⚠' : '' ?> <span style="opacity:.6">(<?= $p['lines'] ?>)</span>
    </a>
    <?php endforeach; ?>
  </div>
</div>
<?php endif; ?>

<?php if ($product === ''): ?>
<p style="color:#8a97ab;font-size:13px;padding:20px 0;text-align:center">Pick a product above, or type a name, to see its line-by-line audit.</p>
<?php elseif (!$rows): ?>
<p style="color:#8a97ab;font-size:13px;padding:20px 0;text-align:center">No shipment lines match "<?= e($product) ?>" in this date range.</p>
<?php else: ?>
<p style="color:#5a6b82;font-size:12.5px;margin:0 0 12px"><?= count($rows) ?> line(s) found. Median markup among costed lines: <b><?= $medianPct !== null ? e((string)$medianPct).'%' : '—' ?></b> — rows more than double that (or negative) are flagged in red.</p>
<div style="overflow-x:auto"><table style="width:100%;border-collapse:collapse;font-size:12.5px;background:#fff;border:1px solid #e3e9f2;border-radius:14px">
  <thead><tr style="text-align:left;color:#8a97ab;font-size:10.5px;text-transform:uppercase;letter-spacing:.03em">
    <th style="padding:10px">Date</th><th style="padding:10px">Invoice</th><th style="padding:10px">Buyer</th>
    <th style="padding:10px">Qty</th><th style="padding:10px" class="num">Sale Rate</th><th style="padding:10px" class="num">Sale Amount</th>
    <th style="padding:10px" class="num">Cost/Unit</th><th style="padding:10px" class="num">Total Cost</th>
    <th style="padding:10px" class="num">Profit</th><th style="padding:10px" class="num">Markup %</th>
  </tr></thead>
  <tbody>
  <?php foreach ($rows as $r):
    $flag = $r['pct'] !== null && $medianPct !== null && ($r['pct'] < 0 || ($medianPct > 0 && $r['pct'] > $medianPct * 2));
  ?>
  <tr style="border-top:1px solid #e3e9f2;<?= $flag ? 'background:rgba(192,41,63,.06)' : '' ?>">
    <td style="padding:9px 10px"><?= e($r['dt']) ?></td>
    <td style="padding:9px 10px"><a href="final_costing.php?shipment_id=<?= (int)$r['sid'] ?>" style="color:#0ea8c9;font-weight:600;text-decoration:none"><?= e($r['invoice_no']) ?></a></td>
    <td style="padding:9px 10px"><?= e($r['buyer_name']) ?></td>
    <td style="padding:9px 10px"><?= number_format($r['qty'], 2) ?></td>
    <td style="padding:9px 10px" class="num"><?= e($r['cur']) ?> <?= number_format($r['rate'], 2) ?></td>
    <td style="padding:9px 10px" class="num"><?= e($r['cur']) ?> <?= number_format($r['sale'], 2) ?></td>
    <td style="padding:9px 10px" class="num"><?= $r['costed'] ? e($r['cur']).' '.number_format($r['unit_cost'], 2) : '—' ?><?php if (!empty($r['entered'])): ?><br><small style="color:#b45309;font-weight:600"><?= e($r['entered']) ?> entered</small><?php endif; ?></td>
    <td style="padding:9px 10px" class="num"><?= $r['costed'] ? e($r['cur']).' '.number_format($r['cost_total'], 2) : '—' ?></td>
    <td class="num" style="padding:9px 10px;color:<?= $r['profit']!==null && $r['profit']<0 ? '#c0293f' : '#152033' ?>"><?= $r['profit'] !== null ? e($r['cur']).' '.number_format($r['profit'], 2) : '—' ?></td>
    <td class="num" style="padding:9px 10px;font-weight:700;color:<?= $flag ? '#c0293f' : '#152033' ?>"><?= $r['pct'] !== null ? e((string)$r['pct']).'%' : 'not costed' ?><?= $flag ? ' ⚠' : '' ?></td>
  </tr>
  <?php endforeach; ?>
  </tbody>
</table></div>
<?php endif; ?>

<style>.num{text-align:right}</style>
<?php page_footer(); ?>
