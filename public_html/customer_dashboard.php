<?php
require_once __DIR__ . '/includes/bootstrap.php';
require_once __DIR__ . '/includes/customer.php';
require_once __DIR__ . '/includes/fx.php';
customer_ensure_schema();
require_customer_login();

$ids = customer_shipment_ids();
$invoices = [];
if ($ids) {
    $in = implode(',', array_fill(0, count($ids), '?'));
    $st = db()->prepare("SELECT s.*, (SELECT COUNT(*) FROM shipment_items si WHERE si.shipment_id=s.id) item_count
        FROM shipments s WHERE s.id IN ($in) ORDER BY s.invoice_date DESC, s.id DESC");
    $st->execute($ids);
    $invoices = $st->fetchAll();
}

// Total value across every visible invoice — shown in one currency. When
// every invoice already shares the same currency (the common case) that
// figure is exact and native; a mix of currencies is converted to USD and
// labelled as such, rather than adding unlike amounts together.
$currencies = array_values(array_unique(array_column($invoices, 'currency')));
if (count($currencies) <= 1) {
    $totalValue = array_sum(array_column($invoices, 'total_amount'));
    $totalLabel = $currencies[0] ?? 'USD';
} else {
    $fx = fx_get_rates();
    $totalValue = 0.0;
    foreach ($invoices as $inv) $totalValue += customer_cvt((float)$inv['total_amount'], $inv['currency'], 'USD', $fx);
    $totalLabel = 'USD (equiv.)';
}

require_once __DIR__ . '/includes/customer_layout.php';
customer_page_header('Welcome back', 'home');
?>
<div class="kpi-row" style="display:grid;grid-template-columns:1fr 1fr;gap:8px;margin-bottom:14px">
  <div class="zcard" style="margin-bottom:0;padding:12px 10px"><div style="font-size:9px;text-transform:uppercase;letter-spacing:.04em;color:#8a97ab;font-weight:700">Invoices</div><div style="font-size:17px;font-weight:800;margin-top:4px"><?= count($invoices) ?></div></div>
  <div class="zcard" style="margin-bottom:0;padding:12px 10px"><div style="font-size:9px;text-transform:uppercase;letter-spacing:.04em;color:#8a97ab;font-weight:700">Total Value</div><div style="font-size:17px;font-weight:800;margin-top:4px;font-variant-numeric:tabular-nums"><?= e(num_fmt($totalValue,2)) ?> <span style="font-size:11px;font-weight:600;color:#8a97ab"><?= e($totalLabel) ?></span></div></div>
</div>

<div style="font-size:11px;text-transform:uppercase;letter-spacing:.05em;color:#8a97ab;font-weight:700;margin:16px 0 8px">Your Invoices</div>

<?php if (!$invoices): ?>
<div class="zcard" style="text-align:center;color:#8a97ab;padding:26px 16px">
  No invoices to show yet.
</div>
<?php else: foreach ($invoices as $inv): ?>
<div class="zcard" style="display:flex;justify-content:space-between;align-items:center;gap:10px">
  <div><div style="font-size:13px;font-weight:700"><?= e($inv['invoice_no']) ?></div><div style="font-size:10.5px;color:#8a97ab;margin-top:1px"><?= $inv['invoice_date'] ? e(date('d M Y', strtotime($inv['invoice_date']))) : '—' ?> · <?= (int)$inv['item_count'] ?> item<?= (int)$inv['item_count']===1?'':'s' ?></div></div>
  <div style="font-size:13px;font-weight:700;font-variant-numeric:tabular-nums;white-space:nowrap"><?= e(money_fmt($inv['total_amount'], $inv['currency'])) ?></div>
</div>
<?php endforeach; endif; ?>
<?php customer_page_footer('home'); ?>
