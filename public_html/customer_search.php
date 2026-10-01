<?php
require_once __DIR__ . '/includes/bootstrap.php';
require_once __DIR__ . '/includes/customer.php';
customer_ensure_schema();
require_customer_login();

$q = trim($_GET['q'] ?? '');
$ids = customer_shipment_ids();
$isCarton = $q !== '' && ctype_digit($q);
$cartonResults = [];
$textResults = [];

if ($q !== '' && $ids) {
    $in = implode(',', array_fill(0, count($ids), '?'));

    if ($isCarton) {
        $cartonNo = (int)$q;
        $st = db()->prepare("SELECT p.*, s.invoice_no, s.invoice_date
            FROM packing_items p JOIN shipments s ON s.id = p.shipment_id
            WHERE p.shipment_id IN ($in) AND p.carton_from <= ? AND p.carton_to >= ?
            ORDER BY s.invoice_date DESC, s.id DESC");
        $st->execute(array_merge($ids, [$cartonNo, $cartonNo]));
        $cartonResults = $st->fetchAll();
    } else {
        // Escape LIKE wildcards in the typed text itself (MySQL's default LIKE
        // escape char is backslash) so a customer searching for e.g. "50%"
        // can't accidentally turn part of their own query into a wildcard.
        $like = '%' . str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], $q) . '%';
        $st = db()->prepare("SELECT si.*, s.invoice_no, s.currency, s.optional_column_enabled, s.optional_column_title
            FROM shipment_items si JOIN shipments s ON s.id = si.shipment_id
            WHERE si.shipment_id IN ($in) AND (si.product_name LIKE ? OR si.des_col LIKE ? OR si.optional_value LIKE ?)
            ORDER BY s.invoice_date DESC, si.line_no ASC");
        $st->execute(array_merge($ids, [$like, $like, $like]));
        $items = $st->fetchAll();

        if ($items) {
            $itemIds = array_column($items, 'id');
            $pin = implode(',', array_fill(0, count($itemIds), '?'));
            $pst = db()->prepare("SELECT * FROM packing_items WHERE invoice_item_id IN ($pin) ORDER BY carton_from ASC");
            $pst->execute($itemIds);
            $packByItem = [];
            foreach ($pst->fetchAll() as $p) $packByItem[(int)$p['invoice_item_id']][] = $p;
            foreach ($items as $it) { $it['packing'] = $packByItem[(int)$it['id']] ?? []; $textResults[] = $it; }
        }
    }
}

require_once __DIR__ . '/includes/customer_layout.php';
customer_page_header('Product, description, style, or carton #', 'search');
?>
<style>
.searchbar{display:flex;align-items:center;gap:8px;background:#ffffff;border:1px solid #e3e9f2;border-radius:13px;padding:11px 14px;margin-bottom:10px}
.searchbar svg{flex-shrink:0;color:#8a97ab}
.searchbar input{border:none;background:none;outline:none;font-size:13.5px;color:#152033;flex:1;font-family:inherit}
.search-hint{font-size:10.5px;color:#8a97ab;margin:0 2px 14px}
.ctn-hero{background:linear-gradient(135deg,#0ea8c9,#6d5bd0);border-radius:16px;padding:16px 18px;color:#fff;margin-bottom:12px}
.ctn-hero .badge{font-size:10px;font-weight:800;text-transform:uppercase;letter-spacing:.05em;opacity:.85}
.ctn-hero .num{font-size:26px;font-weight:800;margin-top:2px}
.ctn-hero .range{font-size:11.5px;opacity:.9;margin-top:2px}
.detail-grid{display:grid;grid-template-columns:1fr 1fr;gap:8px;margin-bottom:10px}
.detail-cell{background:#ffffff;border-radius:12px;padding:10px 12px;border:1px solid #e3e9f2}
.detail-cell .lab{font-size:9px;text-transform:uppercase;letter-spacing:.04em;color:#8a97ab;font-weight:700}
.detail-cell .v{font-size:14px;font-weight:800;margin-top:3px;font-variant-numeric:tabular-nums}
.detail-cell .v small{display:block;font-size:9.5px;font-weight:600;color:#8a97ab;margin-top:1px}
.result-card{background:#ffffff;border-radius:14px;padding:13px 14px;border:1px solid #e3e9f2;margin-bottom:10px}
.result-card .top{display:flex;justify-content:space-between;align-items:flex-start;gap:8px}
.result-card .pname{font-size:13.5px;font-weight:700}
.result-card .pi{font-size:10px;color:#0ea8c9;font-weight:700;margin-top:2px}
.result-card .rate{text-align:right;font-size:13px;font-weight:800;font-variant-numeric:tabular-nums;white-space:nowrap}
.result-card .rate small{display:block;font-size:9px;font-weight:600;color:#8a97ab}
.chips{display:flex;flex-wrap:wrap;gap:5px;margin-top:8px}
.chip{font-size:10px;font-weight:600;padding:3px 8px;border-radius:20px;background:#eef1f6;color:#5a6b82;border:1px solid #e3e9f2}
.chip.opt{background:rgba(109,91,208,.07);color:#6d5bd0;border-color:rgba(109,91,208,.22)}
.pack-rows{margin-top:10px;padding-top:10px;border-top:1px dashed #e3e9f2;display:flex;flex-direction:column;gap:6px}
.pack-row{display:flex;justify-content:space-between;font-size:11px;color:#5a6b82}
.pack-row b{color:#152033;font-variant-numeric:tabular-nums}
</style>

<form method="get" class="searchbar">
  <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="11" cy="11" r="7"/><path d="M21 21l-4.3-4.3"/></svg>
  <input type="text" name="q" value="<?= e($q) ?>" placeholder="Product, description, style, or carton #" autofocus>
</form>

<?php if ($q === ''): ?>
<div class="search-hint">Type a product name, description, style, or a plain carton number.</div>

<?php elseif (!$ids): ?>
<div class="zcard" style="text-align:center;color:#8a97ab;padding:26px 16px">No invoices to search yet.</div>

<?php elseif ($isCarton): ?>
  <?php if (!$cartonResults): ?>
  <div class="search-hint">No carton #<?= e($q) ?> found in your invoices.</div>
  <?php else: foreach ($cartonResults as $p): ?>
  <div class="ctn-hero">
    <div class="badge">Carton</div>
    <div class="num">#<?= (int)$q ?></div>
    <div class="range">Part of <?= e($p['pack_unit_title'] ?: 'Carton') ?> <?= (int)$p['carton_from'] ?> – <?= (int)$p['carton_to'] ?> · <?= e($p['invoice_no']) ?></div>
  </div>
  <div class="detail-grid">
    <div class="detail-cell"><div class="lab">Product</div><div class="v"><?= e($p['product_name']) ?><?php if($p['des_col']): ?><small><?= e($p['des_col']) ?></small><?php endif; ?></div></div>
    <div class="detail-cell"><div class="lab">Qty per <?= e($p['pack_unit_title'] ?: 'Carton') ?></div><div class="v"><?= e(trim_num($p['qty_per_carton'],2)) ?> pcs</div></div>
  </div>
  <div class="detail-grid">
    <div class="detail-cell"><div class="lab">Net Weight</div><div class="v"><?= e(trim_num($p['net_weight'],2)) ?> kg</div></div>
    <div class="detail-cell"><div class="lab">Gross Weight</div><div class="v"><?= e(trim_num($p['gross_weight'],2)) ?> kg</div></div>
  </div>
  <?php endforeach; endif; ?>

<?php else: ?>
  <?php if (!$textResults): ?>
  <div class="search-hint">No matches for "<?= e($q) ?>".</div>
  <?php else: ?>
  <div class="search-hint"><?= count($textResults) ?> result<?= count($textResults)===1?'':'s' ?> across your invoices</div>
  <?php foreach ($textResults as $it): ?>
  <div class="result-card">
    <div class="top">
      <div><div class="pname"><?= e($it['product_name']) ?></div><div class="pi"><?= e($it['invoice_no']) ?></div></div>
      <div class="rate"><?= e(money_fmt($it['rate'], $it['currency'])) ?><small>per <?= e($it['unit'] ?: 'pc') ?></small></div>
    </div>
    <?php if ($it['des_col'] || (!empty($it['optional_column_enabled']) && trim((string)$it['optional_value']) !== '')): ?>
    <div class="chips">
      <?php if ($it['des_col']): ?><span class="chip"><?= e($it['des_col']) ?></span><?php endif; ?>
      <?php if (!empty($it['optional_column_enabled']) && trim((string)$it['optional_value']) !== ''): ?><span class="chip opt"><?= e($it['optional_column_title'] ?: 'Optional') ?>: <?= e($it['optional_value']) ?></span><?php endif; ?>
    </div>
    <?php endif; ?>
    <?php if ($it['packing']): ?>
    <div class="pack-rows">
      <?php foreach ($it['packing'] as $p): ?>
      <div class="pack-row"><span><?= e($p['pack_unit_title'] ?: 'Carton') ?> <?= (int)$p['carton_from'] ?> – <?= (int)$p['carton_to'] ?></span><b><?= e(trim_num($p['total_qty'],0)) ?> pcs · <?= e(trim_num($p['gross_weight'],2)) ?> kg gross</b></div>
      <?php endforeach; ?>
    </div>
    <?php endif; ?>
  </div>
  <?php endforeach; endif; ?>
<?php endif; ?>

<?php customer_page_footer('search'); ?>
