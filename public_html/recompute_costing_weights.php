<?php
/*
  One-time utility: recompute Net / Packing / Gross Weight on EXISTING costing
  versions using the corrected allocation formula (weight x qty for normal
  lines, weight / qty for shared lines — shared formula unchanged).

  Why: versions saved before this fix pack were stored using the old,
  incorrect formula for normal lines (it used the raw weight field only,
  ignoring quantity). This script only touches net_weight / packing_weight /
  gross_weight on costing_versions. It does not change total_cost, rates,
  amounts, or any costing_lines row.

  Usage:
    1) Upload this file into your app root (same folder as product_costing.php).
    2) Visit it in your browser while logged in as Admin. By default it only
       PREVIEWS the changes (no data is written) so you can check the numbers.
    3) When you are happy with the preview, visit it again with ?apply=1
       appended to the URL to write the corrected totals.
    4) Delete this file from the server afterwards — it is not linked from
       any menu and is not needed for normal operation.
*/
require_once __DIR__ . '/includes/bootstrap.php';
require_admin();

$apply = ($_GET['apply'] ?? '') === '1';

$versions = db()->query("SELECT id, product_id, version_name, costing_no, net_weight, packing_weight, gross_weight FROM costing_versions ORDER BY id")->fetchAll();
$linesStmt = db()->prepare("SELECT line_group, quantity, weight_kg, shared FROM costing_lines WHERE costing_version_id=?");
$updStmt = db()->prepare("UPDATE costing_versions SET net_weight=?, packing_weight=?, gross_weight=? WHERE id=?");

$rows = [];
$changed = 0;
foreach ($versions as $v) {
    $linesStmt->execute([$v['id']]);
    $net = 0; $pack = 0;
    foreach ($linesStmt->fetchAll() as $l) {
        $q = (float)$l['quantity']; $w = (float)$l['weight_kg']; $sh = !empty($l['shared']);
        $aw = $sh ? ($q > 0 ? $w / $q : $w) : ($w * $q);
        if ($l['line_group'] === 'Fabric' || $l['line_group'] === 'Accessories') $net += $aw;
        if ($l['line_group'] === 'Packing') $pack += $aw;
    }
    $net = round($net, 3); $pack = round($pack, 3); $gross = round($net + $pack, 3);
    $old = ['net' => (float)$v['net_weight'], 'pack' => (float)$v['packing_weight'], 'gross' => (float)$v['gross_weight']];
    $diff = abs($old['net'] - $net) > 0.0005 || abs($old['pack'] - $pack) > 0.0005 || abs($old['gross'] - $gross) > 0.0005;
    if ($diff) {
        $changed++;
        if ($apply) $updStmt->execute([$net, $pack, $gross, $v['id']]);
    }
    $rows[] = ['v' => $v, 'old' => $old, 'new' => ['net' => $net, 'pack' => $pack, 'gross' => $gross], 'diff' => $diff];
}

page_header('Recompute Costing Weights');
?>
<div class="zcard" style="padding:20px;border-radius:14px;background:rgba(14,18,40,.6);border:1px solid rgba(120,140,255,.16)">
  <h2 style="margin:0 0 8px">Recompute Costing Weights</h2>
  <p style="color:#9aa3cc;font-size:13px">
    <?= $apply ? 'APPLY MODE: rows marked "Changed" below have just been updated in the database.' : 'PREVIEW MODE: nothing has been changed yet. Review the numbers, then re-visit this page with <code>?apply=1</code> in the URL to save them.' ?>
    <?= $changed ?> of <?= count($rows) ?> costing version(s) differ from the corrected formula.
  </p>
  <div style="overflow-x:auto">
  <table style="width:100%;border-collapse:collapse;font-size:12.5px">
    <thead><tr style="text-align:left;color:#6b74a0;text-transform:uppercase;font-size:11px">
      <th style="padding:8px">Costing No</th><th style="padding:8px">Version</th>
      <th style="padding:8px" class="num">Old Net</th><th style="padding:8px">New Net</th>
      <th style="padding:8px">Old Pack</th><th style="padding:8px">New Pack</th>
      <th style="padding:8px">Old Gross</th><th style="padding:8px">New Gross</th>
      <th style="padding:8px">Status</th>
    </tr></thead>
    <tbody>
    <?php foreach ($rows as $r): ?>
      <tr style="border-top:1px solid rgba(120,140,255,.1)">
        <td style="padding:8px"><?= e($r['v']['costing_no'] ?: $r['v']['id']) ?></td>
        <td style="padding:8px"><?= e($r['v']['version_name']) ?></td>
        <td style="padding:8px"><?= number_format($r['old']['net'], 3) ?></td>
        <td style="padding:8px;<?= $r['diff'] ? 'color:#34e0a1;font-weight:700' : '' ?>"><?= number_format($r['new']['net'], 3) ?></td>
        <td style="padding:8px"><?= number_format($r['old']['pack'], 3) ?></td>
        <td style="padding:8px;<?= $r['diff'] ? 'color:#34e0a1;font-weight:700' : '' ?>"><?= number_format($r['new']['pack'], 3) ?></td>
        <td style="padding:8px"><?= number_format($r['old']['gross'], 3) ?></td>
        <td style="padding:8px;<?= $r['diff'] ? 'color:#34e0a1;font-weight:700' : '' ?>"><?= number_format($r['new']['gross'], 3) ?></td>
        <td style="padding:8px"><?= $r['diff'] ? ($apply ? 'Changed' : 'Will change') : 'OK' ?></td>
      </tr>
    <?php endforeach; ?>
    </tbody>
  </table>
  </div>
  <?php if (!$apply && $changed > 0): ?>
    <p style="margin-top:16px"><a class="zbtn" style="display:inline-block;padding:10px 18px;border-radius:10px;background:linear-gradient(100deg,#4fe3ff,#8b7bff);color:#04121e;font-weight:700;text-decoration:none" href="?apply=1">Apply these <?= $changed ?> correction(s)</a></p>
  <?php elseif ($apply): ?>
    <p style="margin-top:16px;color:#34e0a1;font-weight:700">Done. You can delete this file now.</p>
  <?php endif; ?>
</div>
<?php page_footer(); ?>
