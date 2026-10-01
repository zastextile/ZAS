<?php
/* ONE-TIME maintenance script — safe to delete after you've run it once.
   Re-applies the same Fabric/Accessories name-based rule now used on every
   Product Costing save (product_costing.php's pc_auto_group()) to every
   costing line already saved in the database, so old data catches up
   without you having to open and re-save each product one by one.
   Workmanship lines are left alone — they're a separate auto-generated
   row, not part of the Fabric/Accessories split. */
require_once __DIR__ . '/includes/bootstrap.php';
require_login();
if (!is_admin()) { http_response_code(403); exit('Admin access required.'); }

function fg_auto_group(string $itemName): string {
    return stripos(trim($itemName), 'fabric') !== false ? 'Fabric' : 'Accessories';
}

/* A line linked to an Item Master item takes its Group from that item, and
   the old name rule must never overwrite it — doing so would push fabrics
   back into Accessories, which is exactly what linking them fixed. Those
   lines are excluded here. Use "Link Costing Items" for the rest. */
try {
    $rows = db()->query("SELECT id, item_name, line_group FROM costing_lines WHERE line_group <> 'Workmanship' AND material_id IS NULL")->fetchAll();
} catch (Throwable $e) {   // column not present yet — older install
    $rows = db()->query("SELECT id, item_name, line_group FROM costing_lines WHERE line_group <> 'Workmanship'")->fetchAll();
}
$changes = [];
foreach ($rows as $r) {
    $new = fg_auto_group((string)$r['item_name']);
    if ($new !== $r['line_group']) {
        $changes[] = ['id' => (int)$r['id'], 'item_name' => $r['item_name'], 'from' => $r['line_group'], 'to' => $new];
    }
}

$done = false;
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'run') {
    verify_csrf();
    $upd = db()->prepare("UPDATE costing_lines SET line_group=? WHERE id=?");
    foreach ($changes as $c) { $upd->execute([$c['to'], $c['id']]); }
    try { audit_log(0, 'Product Costing', 'bulk_regroup', '', count($changes).' line(s) reclassified', 'One-time Fabric/Accessories auto-group fix applied to existing data'); } catch (Throwable $e) {}
    $done = true;
    $appliedCount = count($changes);
    $changes = []; // nothing left to change now
}

page_header('Fix Costing Line Groups');
?>
<div class="topbar"><div><h1>Fix Costing Line Groups</h1><p class="lead">Older cleanup: re-checks costing lines against the name-based rule (contains "fabric" → Fabric, otherwise → Accessories). Lines already linked to an Item Master item are skipped — they keep the item's own Group.</p></div></div>

<div style="padding:14px 16px;border-radius:12px;background:#f6f8fc;border:1px solid #e3e9f2;border-left:4px solid #b45309;color:#5a6b82;font-size:12.5px;line-height:1.65;margin-bottom:18px">
  <b style="color:#152033">This page cannot produce a Packing group</b> — the name rule only ever
  returns Fabric or Accessories, which is why fabrics ended up under Accessories and Packing never
  appeared. Prefer <a href="costing_items_link.php" style="color:#0ea8c9;font-weight:700">Link Costing Items</a>,
  which takes each line's Group from the Item Master item it actually is.
</div>

<?php if ($done): ?>
<div style="padding:16px 20px;border-radius:12px;background:rgba(22,163,74,.08);border:1px solid rgba(22,163,74,.25);color:#16a34a;font-weight:600;margin-bottom:18px">
  Done — <?= (int)$appliedCount ?> line(s) updated. Nothing left to fix.
</div>
<a style="padding:10px 16px;border:none;border-radius:11px;cursor:pointer;font-weight:700;font-size:13px;color:#fff;background:linear-gradient(100deg,#0ea8c9,#6d5bd0);text-decoration:none;display:inline-block" href="product_costing.php">← Back to Product Costing</a>

<?php elseif (!$changes): ?>
<div style="padding:16px 20px;border-radius:12px;background:#f6f8fc;border:1px solid #e3e9f2;color:#5a6b82">
  Nothing to fix — every existing line already matches the current rule.
</div>

<?php else: ?>
<p style="color:#5a6b82;font-size:13px;margin-bottom:14px"><?= count($changes) ?> line(s) will be reclassified:</p>
<div style="overflow-x:auto;margin-bottom:18px"><table style="width:100%;border-collapse:collapse;font-size:13px">
  <thead><tr style="text-align:left;color:#8a97ab;font-size:11px;text-transform:uppercase"><th style="padding:8px">Item</th><th style="padding:8px">From</th><th style="padding:8px">To</th></tr></thead>
  <tbody>
    <?php foreach (array_slice($changes, 0, 300) as $c): ?>
    <tr style="border-top:1px solid #e3e9f2">
      <td style="padding:8px"><?= e($c['item_name']) ?></td>
      <td style="padding:8px;color:#b8283f"><?= e($c['from']) ?></td>
      <td style="padding:8px;color:#16a34a;font-weight:600"><?= e($c['to']) ?></td>
    </tr>
    <?php endforeach; ?>
  </tbody>
</table></div>
<?php if (count($changes) > 300): ?><p style="color:#8a97ab;font-size:12px">…and <?= count($changes) - 300 ?> more (showing first 300; all of them will still be updated).</p><?php endif; ?>

<form method="post">
  <?= csrf_field() ?><input type="hidden" name="action" value="run">
  <button style="padding:10px 16px;border:none;border-radius:11px;cursor:pointer;font-weight:700;font-size:13px;color:#fff;background:linear-gradient(100deg,#0ea8c9,#6d5bd0)">Apply this fix now</button>
</form>
<?php endif; ?>

<?php page_footer(); ?>
