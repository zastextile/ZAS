<?php
require_once __DIR__ . '/includes/bootstrap.php';
require_once __DIR__ . '/includes/export.php';
require_once __DIR__ . '/includes/parties.php';
require_login();
/* a price as an accountant writes it: at least two decimals, up to four (6.40, 1.8750) */
if (!function_exists('price_txt')) { function price_txt($v): string { $s = rtrim(number_format((float)$v, 4, '.', ''), '0'); $d = strlen(substr(strrchr($s, '.') ?: '.', 1)); return $d < 2 ? number_format((float)$v, 2, '.', '') : rtrim($s, '.'); } }

exp_ensure_schema();
pty_ensure_schema();

function zas_ensure_reopen_columns_v26(): void {
    try { db()->exec("ALTER TABLE shipments ADD COLUMN reopen_status VARCHAR(40) NULL AFTER status"); } catch (Throwable $e) {}
    try { db()->exec("ALTER TABLE shipments ADD COLUMN reopen_reason TEXT NULL AFTER reopen_status"); } catch (Throwable $e) {}
    try { db()->exec("ALTER TABLE shipments ADD COLUMN reopened_by INT NULL AFTER reopen_reason"); } catch (Throwable $e) {}
    try { db()->exec("ALTER TABLE shipments ADD COLUMN reopened_at DATETIME NULL AFTER reopened_by"); } catch (Throwable $e) {}
}
zas_ensure_reopen_columns_v26();

$id = (int)($_GET['id'] ?? 0);
$stmt = db()->prepare("SELECT * FROM shipments WHERE id=?");
$stmt->execute([$id]);
$shipment = $stmt->fetch();

if (!$shipment || !can_view_shipment($id)) { http_response_code(404); exit('Shipment not found or not assigned.'); }
if (is_staff()) { redirect('packing_list.php?id=' . $id); }

/* The Product Master the line LOV offers, with Product Costing joined on.

   The list stays ONE list — you are picking a product, not a costing. What
   the join adds is two facts per row: whether a costing exists behind this
   product, and what it says a unit costs. That is the thing you could only
   discover before by picking the product, going to Final Costing, and
   finding it empty.

   LEFT JOIN, so a product with no costing is still offered. Invoicing
   something whose costing is not finished yet is normal and the picker
   must not argue with it — it only has to say so. */
$prodMaster = [];
try {
    $prodMaster = db()->query(
        "SELECT p.id, p.name, p.default_unit,
                COUNT(cv.id) AS ver_count,
                MAX(cv.id) AS latest_version_id
         FROM products p
         LEFT JOIN costing_versions cv ON cv.product_id = p.id
         WHERE p.is_active = 1
         GROUP BY p.id, p.name, p.default_unit
         ORDER BY p.name
         LIMIT 800")->fetchAll();

    /* Unit cost + currency for the latest costing of each product, in one
       further query rather than one per row. Kept separate from the count
       above because GROUP BY cannot pick the cost belonging to MAX(cv.id)
       in portable SQL — MySQL would allow it, and would sometimes return
       a cost from a different row than the id. */
    $vids = array_values(array_filter(array_map(
        static fn($p) => (int)($p['latest_version_id'] ?? 0), $prodMaster)));
    $costOf = [];
    if ($vids) {
        $in = implode(',', array_fill(0, count($vids), '?'));
        $cs = db()->prepare("SELECT id, total_cost, currency FROM costing_versions WHERE id IN ($in)");
        $cs->execute($vids);
        foreach ($cs->fetchAll() as $c) {
            $costOf[(int)$c['id']] = ['cost' => (float)$c['total_cost'], 'cur' => $c['currency'] ?: 'PKR'];
        }
    }
    foreach ($prodMaster as &$p) {
        $v = $costOf[(int)($p['latest_version_id'] ?? 0)] ?? null;
        $p['unit_cost'] = $v['cost'] ?? null;
        $p['cost_cur'] = $v['cur'] ?? '';
    }
    unset($p);
} catch (Throwable $e) {}

$itemsStmt = db()->prepare("SELECT * FROM shipment_items WHERE shipment_id=? ORDER BY line_no,id");
$itemsStmt->execute([$id]);
$items = $itemsStmt->fetchAll();

/* THE INVOICE AGAINST THE PACKING (phone + desktop together).
   afnan: "allow packing but once increase so ask invoice to update with
   list accurate qty and show red highlight". A line packed past its qty —
   or packed while its qty is still 0, the invoice being made later — is
   red, with the packed figure beside it and a button to take it. Nothing is
   changed until Save. Optional: without includes/packing.php the invoice
   shows as before. */
$packedBy = []; $invMismatch = [];
if (is_file(__DIR__ . '/includes/packing.php')) {
    try {
        require_once __DIR__ . '/includes/packing.php';
        if (function_exists('pack_invoice_mismatch')) {
            pack_relink_orphans($id, $items);
            foreach (pack_packed_by_item($id, $items) as $pid => $pq) $packedBy[$pid] = $pq['total'];
            $invMismatch = pack_invoice_mismatch($id, $items);
        }
    } catch (Throwable $e) { $packedBy = []; $invMismatch = []; }
}
$overIds = [];
foreach ($invMismatch as $mm) $overIds[$mm['id']] = $mm['packed'];

$chargesStmt = db()->prepare("SELECT * FROM shipment_charges WHERE shipment_id=? ORDER BY id");
$chargesStmt->execute([$id]);
$charges = $chargesStmt->fetchAll();

$filesStmt = db()->prepare("SELECT * FROM shipment_files WHERE shipment_id=? ORDER BY id DESC");
$filesStmt->execute([$id]);
$files = $filesStmt->fetchAll();

$locked = $shipment['status'] === 'approved_locked';
$reopenedForCorrection = (($shipment['reopen_status'] ?? '') === 'reopened_for_correction');
$editInvoice = can_edit_invoice($shipment);
$showRates = can_see_rates();
$optOn = !empty($shipment['optional_column_enabled']);

function sv_badge($status) {
    $map = [
        'draft'           => ['Draft','rgba(47,127,224,.16)','#2f7fe0','rgba(47,127,224,.3)'],
        'submitted'       => ['Submitted','rgba(217,119,6,.16)','#d97706','rgba(217,119,6,.3)'],
        'approved_locked' => ['Approved & Locked','rgba(22,163,74,.16)','#16a34a','rgba(22,163,74,.3)'],
    ];
    $x = $map[$status] ?? [ucfirst((string)$status),'#e3e9f2','#33415c','#cbd5e3'];
    return '<span style="padding:5px 11px;border-radius:20px;font-size:12px;font-weight:600;background:'.$x[1].';color:'.$x[2].';border:1px solid '.$x[3].'">'.htmlspecialchars($x[0]).'</span>';
}
$ro = $editInvoice ? '' : 'readonly';
$dis = $editInvoice ? '' : 'disabled';

page_header('Shipment ' . $shipment['invoice_no']);
flash();

/* THE TAB STRIP. Everything below this line is exactly as it was — the strip
   is drawn above the existing page and changes none of it. A tab the viewer
   has no permission for is not drawn at all. */
exp_tab_strip($shipment, 'invoice');
?>
<?php /* THE SKIN, OPTED IN. Every rule in assets/css/zskin.css is scoped under
         .zskin, so this one attribute is the whole of the restyle and removing
         it puts the page back exactly as it was. Nothing below is edited: the
         page keeps its own class names, and the skin maps onto them. */ ?>
<div class="zskin zfo">
<link rel="stylesheet" href="assets/css/zfo.css?v=3">
<style>
.zcard{padding:15px;border-radius:18px;background:#ffffff;border:1px solid #e3e9f2;backdrop-filter:blur(12px);margin-bottom:11px}
.zhead{display:flex;justify-content:space-between;align-items:center;flex-wrap:wrap;gap:10px;margin-bottom:16px}
.zhead h2{font-size:15px;margin:0}
.zgrid{display:grid;grid-template-columns:repeat(auto-fit,minmax(200px,1fr));gap:14px}
.zlabel{font-size:12px;color:#5a6b82;display:block}
.zin{display:block;width:100%;margin-top:6px;padding:10px 12px;border-radius:10px;border:1px solid #cbd5e3;background:#ffffff;color:#152033;font-size:13.5px;outline:none;font-family:inherit}
.zin:focus{border-color:#0ea8c9}
.zin[readonly],.zin[disabled]{opacity:.7;cursor:not-allowed}
.zspan2{grid-column:span 2}
.zbtn{padding:11px 18px;border:none;border-radius:11px;cursor:pointer;font-weight:700;font-size:13px;color:#fff;background:linear-gradient(100deg,#0ea8c9,#6d5bd0);text-decoration:none;display:inline-block}
.zbtn.sec{background:#f6f8fc;color:#152033;border:1px solid #cbd5e3}
.zbtn.org{background:linear-gradient(100deg,#d97706,#c2410c);color:#fff}
.zbtn.red{background:rgba(224,67,93,.15);color:#b8283f;border:1px solid rgba(224,67,93,.3);padding:8px 12px}
.zbtn.off{opacity:.55;cursor:not-allowed}
.zkpi{display:grid;grid-template-columns:repeat(auto-fit,minmax(180px,1fr));gap:14px;margin-bottom:18px}
.zkpi .c{padding:16px 18px;border-radius:16px;background:#ffffff;border:1px solid #e3e9f2}
.zkpi .lab{color:#5a6b82;font-size:12px}
.zkpi .val{font-family:'Space Grotesk',system-ui,sans-serif;font-size:24px;font-weight:700;margin-top:5px}
.ztable{width:100%;border-collapse:collapse;font-size:13px;min-width:720px}
.ztable thead tr{text-align:left;color:#8a97ab;font-size:11px;text-transform:uppercase;letter-spacing:.05em}
.ztable th{padding:5px 8px}.ztable td{padding:3px 8px;border-top:1px solid #f6f8fc}
.ztable .zin{margin-top:0;padding:4px 7px;font-size:13px}
.ztable .num{text-align:right}
.total-row td{border-top:2px solid #cbd5e3;font-weight:700;color:#33415c}
.zalert{padding:14px 18px;border-radius:14px;margin-bottom:18px;font-size:13px}
.zalert.err{background:rgba(224,67,93,.1);border:1px solid rgba(224,67,93,.28);color:#b8283f}
.zalert.ok{background:rgba(22,163,74,.1);border:1px solid rgba(22,163,74,.28);color:#127a3f}
.znote{padding:14px 16px;border-radius:14px;background:#f6f8fc;border:1px solid #e3e9f2;font-size:13px;color:#33415c;line-height:1.6}
/* the product link, stated under the name it prints as */
.linkchip{margin-top:3px;font-size:10px;font-family:monospace;line-height:1.4}
.linkchip .ok{color:#127a3f;font-weight:700;background:rgba(22,163,74,.1);border:1px solid rgba(22,163,74,.25);
  border-radius:4px;padding:1px 5px}
.linkchip .un{color:#9a5710;font-weight:700}
/* linked, but the printed text is the buyer's wording, not the master's */
.linkchip .rw{color:#5b6b85}
/* Costing state, shown in the product picker while you type. Green is not
   "good product" — it means a costing exists to pull from. Amber is a
   warning, never a block: invoicing ahead of a finished costing is normal. */
.cyes,.cno{font-size:9.5px;font-weight:700;padding:2px 6px;border-radius:20px;
  white-space:nowrap;font-family:monospace}
.cyes{background:rgba(22,163,74,.12);color:#127a3f}
.cno{background:rgba(217,119,6,.12);color:#9a5710}
.ztable td{vertical-align:top}
.savebadge{font-size:11.5px;font-family:monospace;color:#8a97ab;margin-left:auto}
</style>
<link rel="stylesheet" href="assets/css/lov.css?v=3">
<script src="assets/js/lov.js?v=3"></script>
<?php
/* Parts in the product picker and the last price to this buyer — see
   includes/linelinks.php. Loaded before the page registers its picker so it
   can add to it; if it fails to load, the picker works exactly as before. */
if (is_file(__DIR__ . '/includes/linelinks.php')) require_once __DIR__ . '/includes/linelinks.php';
if (function_exists('ll_script')) echo ll_script(['tbody' => '#invoiceRows', 'idField' => 'item_id[]', 'partField' => 'part_id[]', 'rateSel' => '[data-c="rate"]',
    'buyerSel' => 'input[name="buyer_name"]', 'curSel' => 'select[name="currency"]', 'lastUrl' => 'line_links.php?last=1&ship=' . (int)$shipment['id'],
    'parts' => ll_parts(), 'rowParts' => ll_row_parts('shipment_items', 'shipment_id', (int)$shipment['id'])]);
?>
<script src="assets/js/grid.js?v=2"></script>

<?php /* ONE HEADER (owner, 19 Nov 2026). The tab strip above already says
         which shipment, whose, and its status; the second title and the
         badge row that repeated it are gone. What stays is a compact strip
         of the four figures, with the revision and "reopened" beside them. */ ?>
<link rel="stylesheet" href="assets/css/zf.css?v=4">
<style>
.svk{display:flex;gap:8px;flex-wrap:wrap;align-items:stretch;margin:0 0 10px}
.svk .c{background:#fff;border:1px solid #d9dee4;border-left:4px solid #1d2d3e;border-radius:4px;padding:6px 12px;min-width:150px}
.svk .c span{display:block;font-size:11.5px;color:#556b82;font-weight:600}
.svk .c b{display:block;font-size:17px;font-weight:600;color:#1d2d3e;font-variant-numeric:tabular-nums;white-space:nowrap}
.svk .c.v{border-left-color:#0a6ed1}.svk .c.v b{color:#0a6ed1}
.svk .meta{display:flex;gap:6px;align-items:center;margin-left:auto;flex-wrap:wrap}
.svk .meta i{font-style:normal;font-size:11px;font-weight:600;padding:2px 8px;border-radius:3px;border:1px solid #c3dbf5;background:#eef5fd;color:#0a6ed1}
.svk .meta i.o{border-color:#f0d3a8;background:#fff7ec;color:#9a5710}
</style>
<div class="svk">
  <?php if($showRates): ?><div class="c v"><span>Invoice value</span><b><?= e(money_fmt($shipment['total_amount'], $shipment['currency'])) ?></b></div><?php endif; ?>
  <div class="c"><span>Total qty</span><b><?= e(num_fmt($shipment['total_qty'],0)) ?></b></div>
  <div class="c"><span>Packages</span><b><?= e(num_fmt($shipment['total_packages'],0)) ?></b></div>
  <div class="c"><span>Gross weight</span><b><?= e(num_fmt($shipment['total_gross_weight'],3)) ?> kg</b></div>
  <div class="meta"><i>Rev <?= e($shipment['revision_no']) ?></i><?php if($reopenedForCorrection): ?><i class="o">Reopened for correction</i><?php endif; ?></div>
</div>

<form method="post" action="shipment_save.php" onsubmit="return confirmLockedEdit()">
<?= csrf_field() ?>
<input type="hidden" name="shipment_id" value="<?= e($id) ?>">

<?php if ($locked && is_admin()): ?>
<div class="zcard"><div class="zhead"><h2>Admin Amendment Reason Required</h2></div><label class="zlabel">Reason for changing locked record<textarea class="zin" name="amendment_reason" style="min-height:60px" placeholder="Example: Buyer requested correction in carton quantity."></textarea></label></div>
<?php elseif($locked): ?>
<div class="zalert err">This shipment is approved and locked. View only — only Admin can amend with an audit reason.</div>
<?php endif; ?>

<?php if($reopenedForCorrection): ?>
<div class="zalert ok">This shipment is reopened for correction. Assigned colleague can edit and re-submit for approval.</div>
<?php elseif(is_colleague() && $shipment['status'] === 'submitted'): ?>
<div class="zalert err">This shipment is submitted for approval. You can edit only after Admin clicks Reopen for Correction.</div>
<?php endif; ?>

<?php if(is_admin() && $shipment['status'] !== 'draft'): /* opened from the action bar — "Reopen for correction" */ ?>
<div class="zcard" id="reopenCard" hidden>
  <div class="zhead"><h2>Reopen for Colleague Correction</h2><button type="button" class="zbtn sec" style="padding:5px 10px" onclick="document.getElementById('reopenCard').hidden=true">Close</button></div>
  <div class="znote">Opening/viewing this shipment does not allow colleague editing. Use this only when you want the assigned colleague to correct the record.</div>
  <label class="zlabel" style="margin-top:12px;display:block">Reopen Reason *<textarea class="zin" name="reopen_reason" style="min-height:60px" placeholder="Example: Buyer requested correction in Line 2 quantity."></textarea></label>
  <button class="zbtn org" style="margin-top:12px" formaction="reopen_shipment.php" formmethod="post" onclick="return confirm('Reopen this shipment for colleague correction?')">Reopen for Correction</button>
</div>
<?php endif; ?>

<?php /* TIGHT HEADER (owner, 19 Nov 2026): buyer wide on the left, the
         document facts beside it, six columns instead of loose auto-fit
         boxes. Same field names, so shipment_save.php is unchanged. */ ?>
<style>
.shh{display:grid;grid-template-columns:repeat(6,minmax(0,1fr));gap:8px 12px;align-items:start}
.shh .c3{grid-column:span 3}.shh .c2{grid-column:span 2}.shh .r2{grid-row:span 2}
.shh textarea.zin{min-height:96px}
.shh .opt{display:flex;align-items:center;gap:8px;font-size:12.5px;color:#33415c;cursor:pointer;padding-top:20px;white-space:nowrap}
@media(max-width:900px){.shh{grid-template-columns:1fr 1fr}.shh .c3,.shh .c2{grid-column:1/-1}.shh .r2{grid-row:auto}}
</style>
<div class="zcard">
  <div class="zhead"><h2>Buyer &amp; invoice</h2></div>
  <div class="shh">
    <label class="zlabel c3">Buyer name<input class="zin" name="buyer_name" value="<?= e($shipment['buyer_name']) ?>" <?= $ro ?> placeholder="Type to pick a customer"><?php if (!$ro): ?><input type="hidden" name="party_id" value="<?= (int)($shipment['party_id'] ?? 0) ?: '' ?>"><?php endif; ?></label>
    <label class="zlabel">Invoice no.<input class="zin" name="invoice_no" value="<?= e($shipment['invoice_no']) ?>" <?= $ro ?>></label>
    <label class="zlabel">Invoice date<input class="zin" type="date" name="invoice_date" value="<?= e($shipment['invoice_date']) ?>" <?= $ro ?>></label>
    <?php if($showRates): ?>
    <label class="zlabel">Currency<select class="zin" name="currency" <?= $dis ?>><?php foreach(['USD','EUR','GBP','PKR'] as $cur): ?><option <?= $shipment['currency']===$cur?'selected':'' ?>><?= $cur ?></option><?php endforeach; ?></select></label>
    <?php else: ?><span></span><?php endif; ?>
    <label class="zlabel c3 r2">Buyer address<textarea class="zin" name="buyer_address" <?= $ro ?>><?= e($shipment['buyer_address']) ?></textarea></label>
    <label class="zlabel">Country<input class="zin" name="buyer_country" value="<?= e($shipment['buyer_country']) ?>" <?= $ro ?>></label>
    <label class="zlabel">Destination port<input class="zin" name="destination_port" value="<?= e($shipment['destination_port']) ?>" <?= $ro ?>></label>
    <label class="zlabel">Incoterm<input class="zin" name="incoterm" value="<?= e($shipment['incoterm'] ?? '') ?>" placeholder="e.g. FOB Karachi" <?= $ro ?>></label>
    <?php if($showRates): ?>
    <label class="zlabel">Payment terms<input class="zin" name="payment_terms" value="<?= e($shipment['payment_terms']) ?>" <?= $ro ?>></label>
    <?php else: ?><span></span><?php endif; ?>
    <label class="zlabel">PO no.<input class="zin" name="po_no" value="<?= e($shipment['po_no']) ?>" <?= $ro ?>></label>
    <label class="zlabel">BL / container no.<input class="zin" name="bl_container_no" value="<?= e($shipment['bl_container_no']) ?>" <?= $ro ?>></label>
    <label class="opt c2"><input type="checkbox" name="optional_column_enabled" <?= $optOn?'checked':'' ?> <?= $dis ?> style="width:15px;height:15px;accent-color:#0a6ed1"> Extra column on the lines</label>
    <label class="zlabel c2" id="opt-title-wrap" style="display:<?= $optOn?'block':'none' ?>">Extra column title<input class="zin" name="optional_column_title" value="<?= e($shipment['optional_column_title']) ?>" <?= $ro ?>></label>
  </div>
</div>

<div class="zcard">
  <div class="zhead"><h2>Invoice lines</h2><?php if($editInvoice): ?><span class="savebadge" id="saveBadge">saved</span><span style="font-size:11px;color:#8a97ab;font-family:monospace">Tab across &middot; Enter down &middot; Ctrl+D fill &middot; Ctrl+S save</span><button type="button" class="zbtn sec" onclick="addInvoiceRow()">+ Add Invoice Line</button><?php endif; ?></div>
  <?php /* Excel paste: the column order below is what a pasted block must
           match, and a number this screen could not read is named here
           rather than left as an empty cell. */ ?>
  <?php if($editInvoice): ?>
  <div id="pasteHint" style="font-size:11.5px;color:#5a6b82;margin:-6px 0 10px">Paste from Excel in this column order: <b>Product &middot; Description &middot; Qty &middot; Unit<?= $showRates ? ' &middot; Rate' : '' ?></b><?php if($optOn): ?> — leave the <b><?= e($shipment['optional_column_title'] ?: 'optional') ?></b> column out of the block; it is filled by hand.<?php endif; ?></div>
  <div id="pasteWarn" style="display:none;font-size:12px;margin:0 0 10px;padding:9px 12px;border-radius:10px;background:rgba(217,119,6,.12);border:1px solid rgba(217,119,6,.3);color:#9a5710"></div>
  <?php endif; ?>
  <?php if ($invMismatch): ?>
  <div class="zalert err" id="invMismatch"><b>Update the invoice — packing is more than it says.</b> Packing is allowed past the invoice; these lines should say the packed quantity:
    <?php foreach ($invMismatch as $mm): ?>
    <div style="margin-top:4px">Line <?= e(ltrim($mm['no'], '#')) ?> · <?= e($mm['name']) ?><?= $mm['hint'] !== '' ? ' · ' . e($mm['hint']) : '' ?>: invoice <b><?= $mm['kind'] === 'no_invoice_qty' ? 'not set' : e(trim_num($mm['invoice'], 3)) ?></b> → packed <b><?= e(trim_num($mm['packed'], 3)) ?> <?= e($mm['unit']) ?></b></div>
    <?php endforeach; ?>
    <?php if ($editInvoice): ?><button type="button" class="zbtn" style="margin-top:10px" onclick="usePackedQty()">Use packed quantities</button> <span style="font-size:12px">then Save — nothing changes until you save.</span><?php endif; ?>
  </div>
  <?php endif; ?>
  <div style="overflow-x:auto">
    <table class="ztable" style="min-width:560px;table-layout:fixed">
      <thead><tr><th style="width:38px">#</th><th>Product / Article</th><th style="width:22%">Des / Col</th><?php if($optOn): ?><th style="width:12%"><?= e($shipment['optional_column_title']) ?></th><?php endif; ?><th class="num" style="width:86px">Qty</th><th style="width:78px">Unit</th><?php if($showRates): ?><th class="num" style="width:92px">Rate <span class="curtag" style="font-weight:800;color:#b45309"><?= e((string)$shipment['currency']) ?></span></th><th class="num" style="width:132px">Amount <span class="curtag" style="font-weight:800;color:#b45309"><?= e((string)$shipment['currency']) ?></span></th><?php endif; ?><th style="width:34px"></th></tr></thead>
      <tbody id="invoiceRows">
      <?php foreach($items as $i=>$it): ?>
        <tr class="item-row">
          <?php /* The Dept dropdown that used to sit under the line number
                   has been removed. It wrote to shipment_items.department,
                   and nothing in the application ever read that column —
                   the one function that would have (the packing list's
                   department filter) was written but never called.

                   The COLUMN is left in the database untouched. Values
                   already stored stay exactly where they are; nothing new
                   is written. Nothing has been dropped, so this is
                   reversible by putting the field back. */ ?>
          <td style="vertical-align:top"><div style="font-weight:700;color:#0ea8c9;font-size:12px"><?= $i+1 ?></div><input type="hidden" name="item_id[]" value="<?= e($it['id']) ?>"></td>
          <?php /* The product cell. The typed name is what prints on the
                   invoice and stays fully editable; the hidden product_id
                   beside it is what production, costing and consumption
                   follow. Pick from the list once and the two are joined
                   — reword the name as much as the buyer needs and the
                   code does not move. */
          $linkedName = '';
          $pidRow = (int)($it['product_id'] ?? 0);
          if ($pidRow > 0) {
              foreach ($prodMaster as $pm) if ((int)$pm['id'] === $pidRow) $linkedName = $pm['name'];
          }
          /* Three states, not two. $prodMaster holds only ACTIVE products,
             so an id that finds no name here points at a product that has
             been removed — a dead link. Showing it as linked would hide
             the fact that production is back to guessing at the name. */
          $linkState = $pidRow <= 0 ? 'none' : ($linkedName !== '' ? 'ok' : 'dead');
          /* Linked, but printing the buyer's wording rather than the
             master name — said plainly so nobody wonders whether the
             rewording quietly broke the link. It does not. */
          $reworded = $linkState === 'ok'
              && lov_norm((string)$it["product_name"]) !== lov_norm($linkedName); ?>
          <td><input class="zin lovf" data-lov="prod" data-c="name" name="product_name[]"
                     value="<?= e($it['product_name']) ?>" autocomplete="off" <?= $ro ?>>
              <input type="hidden" class="pid" name="product_id[]" value="<?= (int)($it['product_id'] ?? 0) ?>">
              <div class="linkchip"><?=
                    $linkState === 'ok'   ? '<span class="ok">&#10003; ' . e($linkedName) . '</span>'
                                            . ($reworded ? ' <span class="rw">printing your wording</span>' : '')
                  : ($linkState === 'dead' ? '<span class="un">&#9888; product #' . $pidRow . ' no longer in master &mdash; pick again</span>'
                                           : '<span class="un">not linked &mdash; production will have to guess</span>') ?></div></td>
          <td><textarea class="zin" data-c="desc" name="des_col[]" rows="1" <?= $ro ?>><?= e($it['des_col']) ?></textarea></td>
          <?php if($optOn): ?><td><input class="zin" name="optional_value[]" value="<?= e($it['optional_value']) ?>" <?= $ro ?>></td><?php endif; ?>
          <?php $ov = $overIds[(int)$it['id']] ?? null; ?>
          <td<?= $ov !== null ? ' class="qty-over"' : '' ?>><input class="zin num" data-c="qty" name="qty[]" value="<?= e(trim_num($it['qty'], 3)) ?>"<?= $ov !== null ? ' data-packed="' . e(trim_num($ov, 3)) . '" style="border-color:#e0435d;background:rgba(224,67,93,.08);color:#b8283f;font-weight:700"' : '' ?> oninput="recalcInvoice()" <?= $ro ?>><?php if ($ov !== null): ?><div style="font-size:11px;color:#b8283f;font-weight:700;margin-top:3px">packed <?= e(trim_num($ov, 3)) ?></div><?php endif; ?></td>
          <td><input class="zin" data-c="unit" name="unit[]" value="<?= e($it['unit']) ?>" <?= $ro ?>></td>
          <?php if($showRates): ?>
          <td><input class="zin num" data-c="rate" name="rate[]" value="<?= e(price_txt($it['rate'])) ?>" oninput="recalcInvoice()" <?= $ro ?>></td>
          <td class="num amount-cell"><?= e(number_format($it['amount'],2)) ?></td>
          <?php endif; ?>
          <td style="vertical-align:top"><?php if($editInvoice): ?><button type="button" class="zbtn red" onclick="this.closest('tr').remove();recalcInvoice()">✕</button><?php endif; ?></td>
        </tr>
      <?php endforeach; ?>
      </tbody>
      <tfoot>
        <tr class="total-row"><td colspan="<?= $optOn?'4':'3' ?>">Subtotal</td><td class="num" id="invoiceQtyTotal">0</td><td></td><?php if($showRates): ?><td></td><td class="num" id="invoiceSubtotal">0.00</td><?php endif; ?><td></td></tr>
      </tfoot>
    </table>
  </div>

  <?php if($showRates): ?>
  <?php /* CHARGES, COMPACT (owner, 19 Nov 2026): a narrow table on the right,
           under the amounts it adds to, the final value in bold. Same rows,
           same names, same recalcInvoice(). */ ?>
  <div style="display:flex;justify-content:flex-end;margin-top:12px">
  <div style="width:min(560px,100%)">
  <div class="zhead" style="margin-bottom:6px"><h2 style="font-size:13.5px">Charges / adjustments</h2><?php if($editInvoice): ?><button type="button" class="zbtn sec" style="padding:5px 10px;font-size:12px" onclick="addChargeRow()">+ Add charge</button><?php endif; ?></div>
  <div style="overflow-x:auto">
    <table class="ztable sv-ch" style="min-width:0">
      <thead><tr><th>Charge Name</th><th class="num">Amount</th><th style="width:36px"></th></tr></thead>
      <tbody id="chargeRows">
        <?php for($c=0;$c<max(1,count($charges));$c++): $ch=$charges[$c]??['charge_name'=>'','amount'=>'0']; ?>
        <tr class="charge-row"><td><input class="zin" name="charge_name[]" value="<?= e($ch['charge_name']) ?>" <?= $ro ?>></td><td><input class="zin num charge-amount" name="charge_amount[]" value="<?= e(trim_num($ch['amount'], 2)) ?>" oninput="recalcInvoice()" <?= $ro ?>></td><td><?php if($editInvoice): ?><button type="button" class="zbtn red" onclick="this.closest('tr').remove();recalcInvoice()">✕</button><?php endif; ?></td></tr>
        <?php endfor; ?>
        <tr class="total-row"><td style="font-size:13.5px">Final invoice value <span class="curtag" style="color:#556b82;font-weight:600"><?= e((string)$shipment['currency']) ?></span></td><td class="num" id="finalValue" style="color:#0a6ed1;font-size:15px">0.00</td><td></td></tr>
      </tbody>
    </table>
  </div>
  </div></div>
  <?php endif; ?>
</div>

<?php /* ONE ACTION BAR (owner, 19 Nov 2026), fixed to the bottom of the
         screen so Save is always in reach. The first three are the work;
         everything that prints sits in Print, everything else in More. The
         same buttons with the same names and targets as before — only
         grouped. The Packing List card that held one note is now a link. */
$canCustomsPrint = [];
if (expdoc_can_view()) foreach (EXPDOC_VIEWS as $vk => $vn) { $vc = expdoc_check($id, $vk); if ($vc['can_print']) $canCustomsPrint[$vk] = $vn; }
?>
<style>
.svbar{position:sticky;bottom:0;z-index:30;display:flex;gap:8px;align-items:center;flex-wrap:wrap;padding:8px 12px;margin:10px 0 0;
  background:rgba(255,255,255,.97);border:1px solid #d9dee4;border-radius:6px;box-shadow:0 -4px 16px rgba(16,32,56,.08)}
.svbar .grow{flex:1}
.svbar .st{font-size:12px;color:#556b82}
</style>
<div class="zf svbar">
  <?php if($editInvoice): ?>
    <button class="zf-btn pri" name="save_only" value="1">Save</button>
    <?php if(!$locked): ?><button class="zf-btn warn" name="save_and_submit" value="1" onclick="return confirm('Save and submit for Admin approval?')">Save &amp; submit for approval</button><?php endif; ?>
  <?php endif; ?>
  <?php if(is_admin() && !$locked): ?><button type="submit" class="zf-btn ok" formaction="approve.php" formmethod="post" onclick="return confirm('Approve and lock this record?')">Approve &amp; lock</button><?php endif; ?>
  <?php if(is_admin() && $shipment['status'] !== 'draft'): ?><button type="button" class="zf-btn" onclick="var c=document.getElementById('reopenCard');c.hidden=false;c.scrollIntoView({behavior:'smooth',block:'center'});c.querySelector('textarea').focus()">Reopen for correction</button><?php endif; ?>
  <span class="grow"></span>
  <a class="zf-btn" href="packing_list.php?id=<?= e($id) ?>" title="Packing list is controlled separately; staff can only pick invoice items already made above">Packing list →</a>
  <?php if(!is_staff()): ?>
  <details class="zf-menu"><summary class="zf-btn">Print</summary><div class="zf-pop">
    <?php if(can_see_rates()): ?><a target="_blank" href="invoice_print.php?id=<?= e($id) ?>">Commercial invoice</a><?php endif; ?>
    <a target="_blank" href="invoice_print_norate.php?id=<?= e($id) ?>">Items (no rate)</a>
    <a target="_blank" href="packing_print.php?id=<?= e($id) ?>">Packing list</a>
    <?php foreach ($canCustomsPrint as $vk => $vn): ?><a target="_blank" href="customs_print.php?id=<?= e($id) ?>&view=<?= e($vk) ?>&doc=invoice"><?= e($vn) ?> invoice</a><?php endforeach; ?>
    <?php if(is_admin()): ?><div class="sep"></div><a target="_blank" href="costing_report_print.php?id=<?= e($id) ?>">Costing report</a><?php endif; ?>
  </div></details>
  <?php endif; ?>
  <details class="zf-menu"><summary class="zf-btn">More</summary><div class="zf-pop">
    <?php if (exp_can('documents')): ?><a href="shipment_documents.php?id=<?= e($id) ?>">Documents</a><?php else: ?><a href="upload_file.php?id=<?= e($id) ?>">Files</a><?php endif; ?>
    <a href="audit.php?id=<?= e($id) ?>">Audit log</a>
    <?php if(is_admin()): ?>
      <a href="assign.php?id=<?= e($id) ?>">Assign users</a>
      <a href="final_costing.php?shipment_id=<?= e($id) ?>">Final costing</a>
      <button type="button" onclick="this.closest('details').open=false;runAICheck()">AI check</button>
      <?php if($locked): ?><button type="submit" formaction="embed.php" formmethod="post" onclick="return confirm('Create / regenerate the AI embedding for this shipment? This uses OpenAI tokens.')">Create / regenerate embedding</button>
      <?php else: ?><span class="dim" style="display:block;padding:7px 10px;font-size:12.5px" title="Approve and lock before embedding">Embedding — after lock only</span><?php endif; ?>
      <?php if($shipment['status'] === 'draft'): ?><div class="sep"></div><button type="button" class="dz" onclick="this.closest('details').open=false;showDeleteModal()">Delete shipment</button><?php endif; ?>
    <?php endif; ?>
  </div></details>
</div>
<script>
/* one menu open at a time; a click elsewhere closes it */
document.addEventListener('click', function(e){ document.querySelectorAll('details.zf-menu[open]').forEach(function(d){ if (!d.contains(e.target)) d.open = false; }); });
</script>
</form>

<?php if($files && !exp_can('documents')): /* shown in the Documents tab instead for anyone who has it */ ?>
<div class="zcard">
  <div class="zhead"><h2>Attached Files</h2></div>
  <div style="overflow-x:auto"><table class="ztable" style="min-width:480px"><thead><tr><th>File</th><th>Date</th><th></th></tr></thead><tbody>
  <?php foreach($files as $f): ?><tr><td><?= e($f['original_name']) ?></td><td style="color:#5a6b82"><?= e($f['created_at']) ?></td><td><a class="zbtn sec" href="download_file.php?id=<?= e($f['id']) ?>">Download</a></td></tr><?php endforeach; ?>
  </tbody></table></div>
</div>
<?php endif; ?>

<script>
document.addEventListener('DOMContentLoaded', function(){
  if (typeof recalcInvoice === 'function') recalcInvoice();
  /* a description box is one line until it holds more (owner, 19 Nov 2026) */
  var fit = function(t){ t.style.height = 'auto'; t.style.height = Math.max(28, t.scrollHeight + 2) + 'px'; };
  document.querySelectorAll('textarea[data-c="desc"]').forEach(fit);
  document.addEventListener('input', function(e){ if (e.target.matches && e.target.matches('textarea[data-c="desc"]')) fit(e.target); });
  var chk = document.querySelector('input[name="optional_column_enabled"]');
  if (chk) chk.addEventListener('change', function(){ document.getElementById('opt-title-wrap').style.display = this.checked ? 'block' : 'none'; });
});
</script>

<?php if ($editInvoice): ?>
<script>
/* ---- the invoice line grid ---------------------------------------
   Two shared pieces do the work: assets/js/lov.js is the same List of
   Values the stock screens use, and assets/js/grid.js is the spreadsheet
   keyboard. What is written here is only what this screen knows — which
   products may be named, and what happens when one is taken. */
(function(){
  var tb = document.getElementById('invoiceRows');
  if(!tb || !window.LOV || !window.GRID) return;

  var MASTER = <?= json_encode(array_map(function($p){
      return [
        'id'   => (int)$p['id'],
        'name' => $p['name'],
        'unit' => $p['default_unit'] ?: 'Pcs',
        // vers 0 means no Product Costing behind this product yet
        'vers' => (int)($p['ver_count'] ?? 0),
        'cost' => isset($p['unit_cost']) ? round((float)$p['unit_cost'], 4) : null,
        'ccur' => $p['cost_cur'] ?? '',
      ];
  }, $prodMaster)) ?>;

  function nz(s){ return LOV.norm(s); }
  function byName(v){
    var n = nz(v);
    for(var i=0;i<MASTER.length;i++) if(nz(MASTER[i].name) === n) return MASTER[i];
    return null;
  }
  function byId(id){
    id = +id; if(!id) return null;
    for(var i=0;i<MASTER.length;i++) if(MASTER[i].id === id) return MASTER[i];
    return null;
  }

  /* The chip under the name is the whole point made visible: is this
     line LINKED, or will production be left guessing at it?

     Four states, because "linked / not linked" cannot say the two things
     that matter most — that a REWORDED line is still linked, and that a
     stored code has gone dead because the product left the master. */
  function paintLink(tr){
    var pidEl = tr.querySelector('.pid'), chip = tr.querySelector('.linkchip');
    if(!chip || !pidEl) return;
    var pid = +pidEl.value, p = byId(pid);
    var f = tr.querySelector('[data-c="name"]');
    var typed = f ? f.value.trim() : '';
    if(!pid){ chip.innerHTML = '<span class="un">not linked &mdash; production will have to guess</span>'; return; }
    if(!p){ chip.innerHTML = '<span class="un">&#9888; product #' + pid + ' no longer in master &mdash; pick again</span>'; return; }
    chip.innerHTML = (typed && nz(typed) !== nz(p.name))
      ? '<span class="ok">&#10003; ' + LOV.esc(p.name) + '</span> <span class="rw">printing your wording</span>'
      : '<span class="ok">&#10003; ' + LOV.esc(p.name) + '</span>';
  }

  /* What typing in the name cell does to the link.

     Rewording a linked line KEEPS the link — that is the entire reason
     the code is stored separately from the printed text. Only two things
     move it: emptying the cell (unlink), or typing a name that IS another
     master product (relink to that one). */
  function relink(tr, val){
    var pidEl = tr.querySelector('.pid');
    if(!pidEl) return;
    if(!String(val).trim()) pidEl.value = 0;
    else { var hit = byName(val); if(hit) pidEl.value = hit.id; }
    paintLink(tr);
  }
  function paintAll(){ [].slice.call(tb.querySelectorAll('tr.item-row')).forEach(paintLink); }

  /* Does this product have a Product Costing behind it, and what does that
     costing say a unit costs? Shown while you type, because the alternative
     is finding out at Final Costing — after the invoice is written. */
  function costTag(p){
    if(!p.vers) return '<span class="cno">no costing</span>';
    return '<span class="cyes">costed &middot; ' + p.vers + (p.vers>1?' vers':' ver') + '</span>';
  }
  function costFig(p){
    if(p.cost === null || p.cost === undefined) return '&mdash;';
    return LOV.esc(p.ccur || '') + ' ' + Number(p.cost).toFixed(2);
  }

  LOV.register('prod', {
    cols: [
      { label:'Product', w:'minmax(150px,1fr)', cls:'nm', get:function(r,q){ return LOV.hl(r.p.name, q); } },
      { label:'Unit',    w:'56px',              cls:'gg', get:function(r){ return LOV.esc(r.p.unit); } },
      { label:'Costing', w:'104px',             cls:'gg', get:function(r){ return costTag(r.p); } },
      { label:'Cost/unit',w:'92px',             cls:'gg num', get:function(r){ return costFig(r.p); } }
    ],
    title: function(){ return 'Select product — from Product Master'; },
    empty: function(f,q){
      return q ? 'No product matches that. Type the name anyway — the line will be kept, just not linked.'
               : 'Product Master is empty.';
    },
    rows: function(f, q, showAll, cb){
      var out = [];
      MASTER.forEach(function(p){
        var sc = LOV.score(q, '', p.name, '');
        if(sc > 0) out.push({ p:p, sc:sc });
      });
      out.sort(function(a,b){ return a.sc!==b.sc ? b.sc-a.sc : a.p.name.localeCompare(b.p.name); });
      cb(out, 0);
    },
    pick: function(f, r){
      var tr = f.closest('tr');
      tr.querySelector('.pid').value = r.p.id;
      /* The printed name is only replaced when it was empty, or when it
         still held a master name we had put there. Your own wording is
         never overwritten — that is the difference between linking a line
         and renaming it. */
      var cur = f.value;
      if(!cur.trim() || byName(cur)) f.value = r.p.name;
      var u = tr.querySelector('[data-c="unit"]');
      if(u && !u.value.trim()) u.value = r.p.unit;
      paintLink(tr);
      if(SAVE) SAVE.touch();
      var q = tr.querySelector('[data-c="qty"]'); if(q){ q.focus(); q.select(); }
    }
  });
  LOV.attach(tb);

  var SAVE = null;

  /* A number cell that could not be read is named, not left blank.
     "1,200" is read as 1200; "1,5" or "approx" is not guessed at. */
  function pasteWarn(bad){
    var box = document.getElementById('pasteWarn');
    if(!box) return;
    var LBL = { qty:'Qty', rate:'Rate' };
    var list = bad.slice(0,6).map(function(b){
      return 'line ' + b.row + ' ' + (LBL[b.col] || b.col) + ' ("' + LOV.esc(b.text) + '")';
    }).join(', ');
    box.innerHTML = '<b>Check these before saving:</b> ' + list
      + (bad.length > 6 ? ' and ' + (bad.length - 6) + ' more' : '')
      + '. These cells were left exactly as pasted because the number could not be read — type the value in.';
    box.style.display = 'block';
  }

  var G = GRID.attach(tb, {
    cols: ['name','desc','qty','unit','rate'],
    addRow: function(){
      if(typeof addInvoiceRow === 'function') addInvoiceRow();
      var rows = tb.querySelectorAll('tr.item-row');
      var tr = rows[rows.length-1];
      if(tr) paintLink(tr);
      return tr;
    },
    onEdit: function(tr, col, val){
      if(col === 'name') relink(tr, val);
      if(col === 'qty' || col === 'rate') recalcInvoice();
    },
    /* A pasted block links any name that IS a master product, so a
       column out of Excel arrives already joined up. A pasted name that
       matches nothing REPLACES the line, so unlike typing it does clear
       a stale link. */
    afterRowPaste: function(tr){
      var f = tr.querySelector('[data-c="name"]');
      var hit = f ? byName(f.value) : null;
      tr.querySelector('.pid').value = hit ? hit.id : 0;
      if(hit){
        var u = tr.querySelector('[data-c="unit"]');
        if(u && !u.value.trim()) u.value = hit.unit;
      }
      paintLink(tr);
    },
    afterChange: function(){ recalcInvoice(); if(SAVE) SAVE.touch(); },
    onPasteProblem: pasteWarn
  });

  SAVE = GRID.keys(document.querySelector('form[action="shipment_save.php"]'), {
    badge: '#saveBadge',
    addRow: function(){
      if(typeof addInvoiceRow === 'function') addInvoiceRow();
      var rows = tb.querySelectorAll('tr.item-row');
      var tr = rows[rows.length-1];
      if(tr) paintLink(tr);
      return tr;
    },
    focusFirst: function(tr){ G.focusFirst(tr); }
  });

  paintAll();
})();
</script>
<?php endif; ?>
<script>
/* fills each red qty with what is packed; the invoice is saved as usual */
window.usePackedQty = function(){
  document.querySelectorAll('#invoiceRows input[data-packed]').forEach(function(el){
    el.value = el.getAttribute('data-packed'); el.style.borderColor = '#16a34a'; el.style.background = 'rgba(22,163,74,.08)'; el.style.color = '#127a3f';
    el.dispatchEvent(new Event('input', { bubbles: true })); /* totals and the unsaved badge follow, as when typed */
  });
};
window.addInvoiceRow = function(){
  var tb = document.getElementById('invoiceRows'); if(!tb) return;
  var opt = <?= $optOn?'true':'false' ?>, rates = <?= $showRates?'true':'false' ?>;
  var n = tb.querySelectorAll('tr.item-row').length + 1;
  /* The Dept dropdown was removed — nothing in the application ever read
     what it wrote. See the note on the row above. */
  var h = '<td style="vertical-align:top"><div style="font-weight:700;color:#0ea8c9;font-size:12px">'+n+'</div><input type="hidden" name="item_id[]" value=""></td>'
    +'<td><input class="zin lovf" data-lov="prod" data-c="name" name="product_name[]" autocomplete="off">'
      +'<input type="hidden" class="pid" name="product_id[]" value="0">'
      +'<div class="linkchip"><span class="un">not linked &mdash; production will have to guess</span></div></td>'
    +'<td><textarea class="zin" data-c="desc" name="des_col[]" style="min-height:34px"></textarea></td>';
  if(opt) h += '<td><input class="zin" name="optional_value[]"></td>';
  h += '<td><input class="zin num" data-c="qty" name="qty[]" value="0" oninput="recalcInvoice()"></td>'
    +'<td><input class="zin" data-c="unit" name="unit[]"></td>';
  if(rates) h += '<td><input class="zin num" data-c="rate" name="rate[]" value="0" oninput="recalcInvoice()"></td>'
    +'<td class="num amount-cell" style="color:#0ea8c9;font-weight:600">0.00</td>';
  h += '<td style="vertical-align:top"><button type="button" class="zbtn red" onclick="this.closest(\'tr\').remove();recalcInvoice()">✕</button></td>';
  var tr = document.createElement('tr'); tr.className='item-row'; tr.innerHTML = h; tb.appendChild(tr);
  if(typeof recalcInvoice==='function') recalcInvoice();
};
window.addChargeRow = function(){
  var tb = document.getElementById('chargeRows'); if(!tb) return;
  var tr = document.createElement('tr');
  tr.className = 'charge-row';
  tr.innerHTML = '<td><input class="zin" name="charge_name[]" placeholder="e.g. Freight, Discount"></td>'
    + '<td><input class="zin num charge-amount" name="charge_amount[]" value="0" oninput="recalcInvoice()"></td>'
    + '<td><button type="button" class="zbtn red" onclick="this.closest(\'tr\').remove();recalcInvoice()">✕</button></td>';
  var totalRow = tb.querySelector('.total-row');
  if (totalRow) tb.insertBefore(tr, totalRow); else tb.appendChild(tr);
  if (typeof recalcInvoice === 'function') recalcInvoice();
};
</script>
<div id="aiCheckPanel" style="position:fixed;top:0;right:0;bottom:0;width:min(440px,92vw);z-index:120;background:rgba(10,13,30,.97);backdrop-filter:blur(14px);border-left:1px solid #cbd5e3;box-shadow:-20px 0 60px rgba(0,0,0,.5);transform:translateX(100%);transition:transform .3s ease;overflow-y:auto;padding:22px">
  <div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:16px">
    <div style="display:flex;align-items:center;gap:10px"><div style="width:26px;height:26px;border-radius:8px;background:conic-gradient(from 210deg,#0ea8c9,#6d5bd0,#e0435d,#0ea8c9)"></div><h2 style="font-size:15px;margin:0">AI Invoice Check</h2></div>
    <button type="button" onclick="document.getElementById('aiCheckPanel').style.transform='translateX(100%)'" style="width:30px;height:30px;border-radius:9px;border:1px solid #cbd5e3;background:transparent;color:#5a6b82;cursor:pointer;font-size:16px">✕</button>
  </div>
  <div style="font-size:12px;color:#8a97ab;margin-bottom:14px">Suggestions only — nothing is saved or changed automatically.</div>
  <div id="aiCheckBody"><div style="color:#5a6b82;font-size:13px">Running checks…</div></div>
</div>
<script>
function runAICheck(){
  var panel=document.getElementById('aiCheckPanel'), body=document.getElementById('aiCheckBody');
  panel.style.transform='translateX(0)';
  body.innerHTML='<div style="color:#5a6b82;font-size:13px">Running checks…</div>';
  fetch('ai_check.php?id=<?= (int)$id ?>').then(function(r){return r.json();}).then(function(d){
    var lv={error:['Possible Error','#b8283f','rgba(224,67,93,.1)','rgba(224,67,93,.28)'],warning:['Warning','#d97706','rgba(217,119,6,.1)','rgba(217,119,6,.26)'],info:['Information','#0ea8c9','rgba(14,168,201,.08)','rgba(14,168,201,.22)']};
    var order={error:0,warning:1,info:2};
    var cs=(d.checks||[]).slice().sort(function(a,b){return (order[a.level]||9)-(order[b.level]||9);});
    var html='';
    if(d.ai){
      var danger=(d.errors||0)>0;
      var bg=danger?'rgba(224,67,93,.14)':((d.warnings||0)>0?'rgba(217,119,6,.12)':'rgba(22,163,74,.1)');
      var bd=danger?'rgba(224,67,93,.4)':((d.warnings||0)>0?'rgba(217,119,6,.32)':'rgba(22,163,74,.28)');
      var cl=danger?'#b8283f':((d.warnings||0)>0?'#d97706':'#127a3f');
      html+='<div dir="rtl" style="padding:15px 16px;border-radius:14px;background:'+bg+';border:1px solid '+bd+';margin-bottom:14px;font-size:15px;line-height:1.9;font-weight:700;color:'+cl+';text-align:right">'+(danger?'⚠ ':'')+d.ai.replace(/</g,'&lt;')+'</div>';
    }
    if(typeof d.score!=='undefined'){
      var sc=d.score, scol=sc>=85?'#16a34a':(sc>=60?'#d97706':'#b8283f');
      html+='<div style="display:flex;gap:8px;align-items:center;margin-bottom:14px;flex-wrap:wrap">'
        +'<div style="flex:1;min-width:120px"><div style="height:8px;border-radius:5px;background:#e3e9f2;overflow:hidden"><div style="height:100%;width:'+sc+'%;background:'+scol+'"></div></div></div>'
        +'<span style="font-family:\'Space Grotesk\',system-ui,sans-serif;font-weight:700;color:'+scol+'">'+sc+'/100</span>'
        +'<span style="font-size:11px;color:#5a6b82">'+(d.errors||0)+' error · '+(d.warnings||0)+' warn · '+(d.info||0)+' info</span>'
        +(d.lock_status==='blocked'?'<span style="font-size:11px;font-weight:700;color:#b8283f;padding:3px 9px;border-radius:12px;background:rgba(224,67,93,.14);border:1px solid rgba(224,67,93,.3)">🔒 Locking blocked</span>':'')+'</div>';
    }
    if(d.match_suggestions&&d.match_suggestions.length){
      html+='<div style="padding:13px 15px;border-radius:14px;background:rgba(109,91,208,.1);border:1px solid rgba(109,91,208,.3);margin-bottom:14px"><div style="font-size:11px;font-weight:700;text-transform:uppercase;letter-spacing:.05em;color:#6d5bd0;margin-bottom:8px">AI Match Suggestions</div>';
      d.match_suggestions.forEach(function(m){ html+='<div style="font-size:12.5px;color:#33415c;line-height:1.5;margin-bottom:6px"><b>Line '+(m.line||'?')+':</b> '+String(m.suggested_product||'').replace(/</g,'&lt;')+(m.reason?' <span style="color:#5a6b82">\u2014 '+String(m.reason).replace(/</g,'&lt;')+'</span>':'')+'</div>'; });
      html+='</div>';
    }
    var f=d.financials, ct=d.container;
    if(f||ct){
      function card(lbl,val,col){return '<div style="padding:11px 13px;border-radius:12px;background:#ffffff;border:1px solid #e3e9f2"><div style="font-size:10px;color:#5a6b82;text-transform:uppercase;letter-spacing:.04em">'+lbl+'</div><div style="font-family:\'Space Grotesk\',system-ui,sans-serif;font-size:15px;font-weight:700;margin-top:3px;color:'+(col||'#152033')+'">'+val+'</div></div>';}
      var cc=(f&&f.currency)?f.currency+' ':'';
      html+='<div style="display:grid;grid-template-columns:1fr 1fr;gap:8px;margin-bottom:14px">';
      if(f){ html+=card('Sales',cc+Number(f.sales_total).toLocaleString());
        html+=card('Est. Cost',(f.cost_complete?'':'~ ')+cc+Number(f.estimated_cost_total).toLocaleString(),'#d97706');
        html+=card('Gross Profit',cc+Number(f.gross_profit).toLocaleString(),f.gross_profit<0?'#b8283f':'#16a34a');
        html+=card('Margin',f.gross_margin_percent+'%',f.gross_margin_percent<0?'#b8283f':(f.gross_margin_percent<8?'#d97706':'#16a34a')); }
      if(ct&&ct.cbm>0){ html+=card('Volume',ct.cbm+' CBM'); html+=card('Container',(ct.type||'—')+(ct.utilisation?' · '+ct.utilisation+'%':''),'#0ea8c9'); }
      html+='</div>';
      if(f&&typeof f.estimated_cost_total_pkr!=='undefined'){
        html+='<div style="margin:-6px 0 14px;font-size:11.5px;color:#8a97ab">In PKR (local): sales ₨'+Number(f.sales_total_pkr).toLocaleString()+' · cost ₨'+Number(f.estimated_cost_total_pkr).toLocaleString()+' · profit ₨'+Number(f.gross_profit_pkr).toLocaleString()+'</div>';
      }
    }
    if(d.materials&&d.materials.length){
      html+='<details style="margin-bottom:14px;border:1px solid #e3e9f2;border-radius:12px;background:#ffffff"><summary style="cursor:pointer;padding:11px 14px;font-weight:700;font-size:12.5px;color:#33415c">Material Utilisation ('+d.materials.length+')</summary><div style="padding:0 14px 12px">';
      d.materials.forEach(function(m){ html+='<div style="display:flex;justify-content:space-between;gap:10px;font-size:12px;padding:6px 0;border-top:1px solid #f6f8fc"><span style="color:#152033">'+m.material.replace(/</g,'&lt;')+' <span style="color:#8a97ab">· '+m.category+'</span></span><span style="color:#0ea8c9;white-space:nowrap">'+Number(m.qty).toLocaleString(undefined,{maximumFractionDigits:2})+' '+m.unit+'</span></div>'; });
      html+='</div></details>';
    }
    cs.forEach(function(c){ var x=lv[c.level]||lv.info; html+='<div style="padding:12px 14px;border-radius:12px;margin-bottom:10px;background:'+x[2]+';border:1px solid '+x[3]+'"><div style="font-size:10.5px;font-weight:700;text-transform:uppercase;letter-spacing:.05em;color:'+x[1]+';margin-bottom:5px">'+x[0]+(c.line_no?' · Line '+c.line_no:'')+'</div><div style="font-size:12.8px;color:#33415c;line-height:1.5">'+c.msg.replace(/</g,'&lt;')+'</div></div>'; });
    var u=d.openai_usage;
    if(u){
      var line;
      if(u.cache_used) line='AI summary from cache · '+(u.model||'')+' · '+(u.total_tokens||0)+' tokens · $'+(u.estimated_cost||0);
      else if(u.ai_called) line='AI: '+(u.model||'')+' · in '+(u.input_tokens||0)+' / out '+(u.output_tokens||0)+' tok · $'+(u.estimated_cost||0)+' · '+(u.response_time_ms||0)+'ms';
      else line='AI not called — '+(u.skipped_reason||'local rules only')+'. No credit used.';
      html+='<div style="margin-top:6px;padding:10px 12px;border-radius:10px;background:#ffffff;border:1px dashed #cbd5e3;font-size:11px;color:#8a97ab">'+line.replace(/</g,'&lt;')+'</div>';
    }
    body.innerHTML=html||'<div style="color:#5a6b82">No results.</div>';
  }).catch(function(){ body.innerHTML='<div style="color:#b8283f;font-size:13px">Could not run the check. Please try again.</div>'; });
}
function showDeleteModal(){ document.getElementById('deleteModal').style.display='flex'; document.getElementById('deletePassword').value=''; document.getElementById('deletePassword').focus(); }
function hideDeleteModal(){ document.getElementById('deleteModal').style.display='none'; }
</script>

<?php if (is_admin() && $shipment['status'] === 'draft'): ?>
<div id="deleteModal" style="display:none;position:fixed;inset:0;background:rgba(10,15,30,.5);z-index:999;align-items:center;justify-content:center;padding:16px">
  <div style="background:#ffffff;border-radius:16px;padding:24px;max-width:400px;width:100%;border:1px solid #e3e9f2">
    <h2 style="font-size:16px;margin:0 0 8px;color:#b8283f">Delete Shipment <?= e($shipment['invoice_no']) ?>?</h2>
    <p style="font-size:13px;color:#5a6b82;line-height:1.5;margin:0 0 16px">This permanently deletes the invoice, all line items, packing entries, charges, files and any AI embedding for this shipment. This cannot be undone. Enter your admin password to confirm.</p>
    <form method="post" action="shipment_delete.php">
      <?= csrf_field() ?>
      <input type="hidden" name="shipment_id" value="<?= e($id) ?>">
      <label class="zlabel">Your Password
        <input class="zin" type="password" id="deletePassword" name="confirm_password" autocomplete="current-password" required style="margin-top:6px">
      </label>
      <div style="display:flex;gap:10px;margin-top:18px">
        <button type="button" class="zbtn sec" style="flex:1" onclick="hideDeleteModal()">Cancel</button>
        <button type="submit" class="zbtn" style="flex:1;background:linear-gradient(100deg,#e0435d,#b8283f)" onclick="return confirm('Really delete this shipment permanently?')">Delete Permanently</button>
      </div>
    </form>
  </div>
</div>
<?php endif; ?>

</div><?php /* closes .zskin */ ?>
<script>
/* The currency beside "Rate" and "Amount" follows the Currency box as it is
   changed, so the prices being typed are never in doubt. */
(function(){
  var sel = document.querySelector('select[name="currency"]');
  if (!sel) return;
  sel.addEventListener('change', function(){
    Array.prototype.forEach.call(document.querySelectorAll('.curtag'), function(t){ t.textContent = sel.value; });
  });
})();
</script>
<?php if (!$ro):
  $pCan = false; if (is_file(__DIR__ . '/includes/inventory.php')) { require_once __DIR__ . '/includes/inventory.php'; $pCan = function_exists('inv_perm') && inv_perm('master'); }
  echo pty_picker(['name' => 'input[name="buyer_name"]', 'party_id' => 'input[name="party_id"]', 'address' => 'textarea[name="buyer_address"]', 'country' => 'input[name="buyer_country"]',
                   'currency' => 'select[name="currency"]', 'payment_terms' => 'input[name="payment_terms"]', 'incoterm' => 'input[name="incoterm"]', 'destination_port' => 'input[name="destination_port"]'],
                  pty_customers(), $pCan);
endif; ?>
<?php page_footer(); ?>
