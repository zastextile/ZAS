<?php
/* Order Costing Control — quoted against actual, per order, while the
   order is still running.

   Nobody enters cost data here. Every actual is collected from something
   already recorded:
     materials  inv_consumption_items (input side)
     wages      production_transactions.amount  — quantity x the rate the
                system itself looked up. You have always captured this;
                it has simply never been read as a cost before.
     other      inv_order_charges (freight, inspection, commission)
     selling    proforma_items
   Quoted comes from the costing version the order was quoted against. */
require_once __DIR__ . '/includes/bootstrap.php';
require_login();
require_once __DIR__ . '/includes/inventory.php';
inv_ensure_schema();
if (!inv_can_see()) { http_response_code(403); exit('You do not have permission to view order costing.'); }
$canEdit = inv_perm('master') || is_admin();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verify_csrf();
    if (!$canEdit) { http_response_code(403); exit('Not permitted.'); }
    $pf = (int)($_POST['proforma_id'] ?? 0);
    if (($_POST['action'] ?? '') === 'add_charge' && $pf > 0) {
        try {
            db()->prepare("INSERT INTO inv_order_charges (proforma_id,charge_group,description,amount,currency,charge_date,created_by)
                VALUES (?,?,?,?,?,?,?)")->execute([
                $pf, trim((string)($_POST['charge_group'] ?? 'Other')) ?: 'Other',
                trim((string)($_POST['description'] ?? '')) ?: null,
                inv_num($_POST['amount'] ?? 0),
                strtoupper(trim((string)($_POST['currency'] ?? 'PKR'))) ?: 'PKR',
                ($_POST['charge_date'] ?? '') !== '' ? $_POST['charge_date'] : date('Y-m-d'),
                (int)(current_user()['id'] ?? 0)]);
            inv_audit('order_charge', $pf, $_POST['amount'] ?? 0, 'Order charge booked');
            $_SESSION['flash'] = 'Charge booked.';
        } catch (Throwable $e) { $_SESSION['error'] = 'Could not book the charge.'; }
        redirect('inv_ordercost.php?id=' . $pf);
    }
    if (($_POST['action'] ?? '') === 'del_charge') {
        try { db()->prepare("DELETE FROM inv_order_charges WHERE id=?")->execute([(int)($_POST['cid'] ?? 0)]); } catch (Throwable $e) {}
        redirect('inv_ordercost.php?id=' . $pf);
    }
}

/* ---------------------------------------------- one order's cost sheet */
function inv_order_cost(int $pfId): ?array {
    try {
        $s = db()->prepare("SELECT pf.*, p.name product_name FROM proforma_invoices pf
            LEFT JOIN products p ON p.id=pf.product_id WHERE pf.id=?");
        $s->execute([$pfId]); $pf = $s->fetch();
        if (!$pf) return null;

        // selling value + ordered quantity
        $s = db()->prepare("SELECT COALESCE(SUM(amount),0) val, COALESCE(SUM(qty),0) qty FROM proforma_items WHERE proforma_id=?");
        $s->execute([$pfId]); $sell = $s->fetch();
        $sellVal = (float)$sell['val']; $ordQty = (float)$sell['qty'];

        // quoted cost per piece from the costing version this order used
        $quotedPer = 0.0; $quotedLines = [];
        if (!empty($pf['costing_version_id'])) {
            $s = db()->prepare("SELECT line_group, COALESCE(SUM(amount),0) amt FROM costing_lines
                WHERE costing_version_id=? GROUP BY line_group");
            $s->execute([(int)$pf['costing_version_id']]);
            foreach ($s->fetchAll() as $r) { $quotedLines[$r['line_group']] = (float)$r['amt']; $quotedPer += (float)$r['amt']; }
        }
        $quotedMat  = ($quotedLines['Fabric'] ?? 0) + ($quotedLines['Accessories'] ?? 0) + ($quotedLines['Packing'] ?? 0);
        $quotedWage = $quotedLines['Workmanship'] ?? 0;
        $quotedOth  = $quotedPer - $quotedMat - $quotedWage;

        // actual material consumed against this order
        $s = db()->prepare("SELECT COALESCE(SUM(ci.amount),0) FROM inv_consumption_items ci
            JOIN inv_consumption c ON c.id=ci.con_id
            WHERE c.proforma_id=? AND c.status='posted' AND ci.side='input'");
        $s->execute([$pfId]); $actMat = (float)$s->fetchColumn();

        // actual finished pieces produced against this order
        $s = db()->prepare("SELECT COALESCE(SUM(ci.qty),0) FROM inv_consumption_items ci
            JOIN inv_consumption c ON c.id=ci.con_id
            WHERE c.proforma_id=? AND c.status='posted' AND ci.side='output'");
        $s->execute([$pfId]); $madeQty = (float)$s->fetchColumn();

        // actual wages — already recorded by the production module
        $actWage = 0.0; $wageQty = 0.0;
        try {
            $s = db()->prepare("SELECT COALESCE(SUM(amount),0) a, COALESCE(SUM(quantity),0) q
                FROM production_transactions WHERE proforma_id=? AND status='active'");
            $s->execute([$pfId]); $w = $s->fetch();
            $actWage = (float)$w['a']; $wageQty = (float)$w['q'];
        } catch (Throwable $e) {}

        // other charges booked
        $s = db()->prepare("SELECT COALESCE(SUM(amount),0) FROM inv_order_charges WHERE proforma_id=?");
        $s->execute([$pfId]); $actOth = (float)$s->fetchColumn();

        // committed from contracts raised against this order
        $s = db()->prepare("SELECT COALESCE(SUM(ci.amount),0) FROM inv_contract_items ci
            JOIN inv_contracts c ON c.id=ci.contract_id
            WHERE c.proforma_id=? AND c.status IN ('active','closed')");
        $s->execute([$pfId]); $committed = (float)$s->fetchColumn();

        // dispatched against this order
        $s = db()->prepare("SELECT COALESCE(SUM(gi.qty),0) FROM inv_gate g JOIN inv_gate_items gi ON gi.gate_id=g.id
            WHERE g.proforma_id=? AND g.direction='out' AND g.status='posted'");
        $s->execute([$pfId]); $dispatched = (float)$s->fetchColumn();

        $quotedTotal = $quotedPer * $ordQty;
        $actualTotal = $actMat + $actWage + $actOth;

        /* Projection: scale what has actually been achieved per piece up to
           the full order. Before anything is produced there is nothing to
           project from, so the quote stands. */
        $projMat  = $madeQty > 0 ? ($actMat / $madeQty) * $ordQty : $quotedMat * $ordQty;
        $projWage = $wageQty > 0 ? $quotedWage * $ordQty : $quotedWage * $ordQty;
        if ($madeQty > 0 && $actWage > 0) $projWage = ($actWage / $madeQty) * $ordQty;
        $projOth  = $quotedOth * $ordQty;
        if ($actOth > $projOth) $projOth = $actOth;
        $projTotal = $projMat + $projWage + $projOth;

        return [
            'pf' => $pf, 'ordered' => $ordQty, 'made' => $madeQty, 'dispatched' => $dispatched,
            'sell' => $sellVal, 'committed' => $committed,
            'quoted' => ['mat' => $quotedMat * $ordQty, 'wage' => $quotedWage * $ordQty, 'oth' => $quotedOth * $ordQty, 'total' => $quotedTotal, 'per' => $quotedPer],
            'actual' => ['mat' => $actMat, 'wage' => $actWage, 'oth' => $actOth, 'total' => $actualTotal],
            'proj'   => ['mat' => $projMat, 'wage' => $projWage, 'oth' => $projOth, 'total' => $projTotal],
            'has_costing' => !empty($pf['costing_version_id']),
        ];
    } catch (Throwable $e) { return null; }
}

$id = (int)($_GET['id'] ?? 0);
$C = $id ? inv_order_cost($id) : null;
$charges = [];
if ($C) {
    try { $s = db()->prepare("SELECT * FROM inv_order_charges WHERE proforma_id=? ORDER BY id DESC"); $s->execute([$id]); $charges = $s->fetchAll(); }
    catch (Throwable $e) {}
}

/* register of orders with any activity */
$list = [];
if (!$C) {
    try {
        $list = db()->query("SELECT pf.id, pf.pi_no, pf.customer_name, pf.currency, pf.production_status,
                (SELECT COALESCE(SUM(amount),0) FROM proforma_items WHERE proforma_id=pf.id) sell,
                (SELECT COALESCE(SUM(qty),0) FROM proforma_items WHERE proforma_id=pf.id) oqty,
                (SELECT COALESCE(SUM(ci.amount),0) FROM inv_consumption_items ci JOIN inv_consumption c ON c.id=ci.con_id
                   WHERE c.proforma_id=pf.id AND c.status='posted' AND ci.side='input') amat,
                (SELECT COALESCE(SUM(amount),0) FROM production_transactions WHERE proforma_id=pf.id AND status='active') awage,
                (SELECT COALESCE(SUM(amount),0) FROM inv_order_charges WHERE proforma_id=pf.id) aoth
            FROM proforma_invoices pf ORDER BY pf.id DESC LIMIT 120")->fetchAll();
    } catch (Throwable $e) {}
}

page_header('Order Costing Control');
flash();
?>
<div class="topbar">
  <div><h1>Order Costing Control</h1>
    <p class="lead">What you quoted against what the order is actually costing — while there is still time to act on it.</p></div>
  <?php if ($C): ?><a class="zbtn sec" href="inv_ordercost.php">← All orders</a><?php endif; ?>
</div>

<style>
.oc-card{background:#fff;border:1px solid #e3e9f2;border-radius:16px;padding:13px 15px;margin-bottom:11px;box-shadow:0 10px 30px rgba(15,35,65,.06)}
.oc-grid{display:grid;gap:13px;grid-template-columns:repeat(4,1fr)}
@media(max-width:1000px){.oc-grid{grid-template-columns:repeat(2,1fr)}}
@media(max-width:640px){.oc-grid{grid-template-columns:1fr}}
.oc-kpi{background:#f7f9fc;border-radius:12px;padding:14px 16px}
.oc-kpi .l{font-size:10px;color:#8a97ab;font-weight:800;text-transform:uppercase;letter-spacing:.04em}
.oc-kpi .v{font-size:20px;font-weight:800;margin-top:5px;font-variant-numeric:tabular-nums}
.oc-kpi .d{font-size:11px;color:#5a6b82;margin-top:4px}
.oc-tbl{width:100%;border-collapse:collapse;font-size:12.5px}
.oc-tbl th{text-align:left;font-size:10px;text-transform:uppercase;letter-spacing:.05em;color:#8a97ab;font-weight:800;padding:0 8px 5px;white-space:nowrap}
.oc-tbl td{padding:3px 8px;border-top:1px solid #eef1f7}
.oc-tbl td.r,.oc-tbl th.r{text-align:right;font-variant-numeric:tabular-nums}
.oc-tbl tfoot td{border-top:2px solid #cbd5e3;font-weight:800}
.oc-btn{padding:9px 16px;border:none;border-radius:10px;background:linear-gradient(100deg,#0ea8c9,#6d5bd0);color:#fff;font-weight:700;font-size:12.5px;cursor:pointer}
.oc-btn.sec{background:#fff;color:#152033;border:1px solid #cbd5e3;text-decoration:none;display:inline-block}
.oc-inp{padding:9px 11px;border-radius:9px;border:1px solid #cbd5e3;font-size:12.5px;font-family:inherit;width:100%}
.oc-lbl{display:block;font-size:10.5px;color:#8a97ab;font-weight:800;text-transform:uppercase;letter-spacing:.04em;margin-bottom:5px}
.oc-note{border-radius:11px;padding:12px 15px;font-size:12.5px;line-height:1.65;margin-bottom:14px}
.oc-note.info{background:rgba(14,168,201,.07);border:1px solid rgba(14,168,201,.2);color:#2c4a63}
.oc-note.warn{background:rgba(217,119,6,.09);border:1px solid rgba(217,119,6,.25);color:#7a4d09}
.oc-note.bad{background:rgba(224,67,93,.08);border:1px solid rgba(224,67,93,.24);color:#8c2038}
.oc-pill{display:inline-block;font-size:10px;font-weight:800;padding:3px 8px;border-radius:20px}
</style>

<?php /* OPTING IN TO THE SKIN. Every rule in assets/css/zskin.css is
         scoped under .zskin, so this one wrapper is what makes the page
         compact, and deleting it restores the styles above with nothing
         else to undo. It wraps the markup and never the <style>. */ ?>
<div class="zskin">

<?php if ($C): $pf = $C['pf'];
  $qMargin = $C['sell'] > 0 ? ($C['sell'] - $C['quoted']['total']) / $C['sell'] * 100 : 0;
  $pMargin = $C['sell'] > 0 ? ($C['sell'] - $C['proj']['total']) / $C['sell'] * 100 : 0;
  $drift = $pMargin - $qMargin;
?>
<div class="oc-card">
  <h2 style="font-size:16px;margin:0 0 4px;font-weight:800"><?= e($pf['pi_no']) ?> · <?= e($pf['customer_name']) ?></h2>
  <p style="color:#8a97ab;font-size:12.5px;margin:0 0 16px">
    <?= number_format($C['ordered'], 0) ?> ordered · <?= number_format($C['made'], 0) ?> produced · <?= number_format($C['dispatched'], 0) ?> dispatched
    <?= $pf['production_status'] ? ' · production ' . e(str_replace('_', ' ', $pf['production_status'])) : '' ?></p>

  <?php if (!$C['has_costing']): ?>
    <div class="oc-note warn">This order has no costing version linked, so there is nothing to compare actuals against. Quoted figures below will read zero until the proforma is tied to a costing.</div>
  <?php endif; ?>

  <div class="oc-grid" style="margin-bottom:18px">
    <div class="oc-kpi"><div class="l">Selling value</div><div class="v"><?= number_format($C['sell'], 0) ?></div><div class="d"><?= e($pf['currency'] ?: 'PKR') ?></div></div>
    <div class="oc-kpi"><div class="l">Quoted cost</div><div class="v"><?= number_format($C['quoted']['total'], 0) ?></div><div class="d"><?= number_format($C['quoted']['per'], 2) ?> per piece</div></div>
    <div class="oc-kpi" style="<?= $drift < -0.05 ? 'background:rgba(217,119,6,.08)' : '' ?>">
      <div class="l">Projected cost</div><div class="v" style="<?= $drift < -0.05 ? 'color:#a8630a' : '' ?>"><?= number_format($C['proj']['total'], 0) ?></div>
      <div class="d">actual so far <?= number_format($C['actual']['total'], 0) ?></div></div>
    <div class="oc-kpi" style="<?= $drift < -0.05 ? 'background:rgba(224,67,93,.07)' : ($drift > 0.05 ? 'background:rgba(22,163,74,.07)' : '') ?>">
      <div class="l">Margin heading for</div>
      <div class="v" style="color:<?= $drift < -0.05 ? '#c0293f' : ($drift > 0.05 ? '#16a34a' : '#152033') ?>"><?= number_format($pMargin, 1) ?>%</div>
      <div class="d">quoted <?= number_format($qMargin, 1) ?>% · <?= ($drift >= 0 ? '+' : '') . number_format($drift, 1) ?> points</div></div>
  </div>

  <div style="overflow-x:auto"><table class="oc-tbl">
    <thead><tr><th>Cost group</th><th class="r">Quoted</th><th class="r">Committed</th><th class="r">Actual to date</th><th class="r">Projected</th><th class="r">Variance</th><th>Source of the actual</th></tr></thead>
    <tbody>
      <?php
      $rows = [
        ['Fabric, accessories &amp; packing', $C['quoted']['mat'], $C['committed'], $C['actual']['mat'], $C['proj']['mat'], 'Posted consumption documents'],
        ['Workmanship / wages', $C['quoted']['wage'], 0, $C['actual']['wage'], $C['proj']['wage'], 'production_transactions — already recorded per worker'],
        ['Freight, commission &amp; other', $C['quoted']['oth'], 0, $C['actual']['oth'], $C['proj']['oth'], 'Charges booked below'],
      ];
      foreach ($rows as [$lbl, $q, $cm, $a, $pj, $src]):
        $v = $pj - $q; ?>
        <tr<?= $v > 0.5 ? ' style="background:rgba(224,67,93,.04)"' : '' ?>>
          <td style="font-weight:600"><?= $lbl ?></td>
          <td class="r"><?= number_format($q, 2) ?></td>
          <td class="r"><?= $cm > 0 ? number_format($cm, 2) : '—' ?></td>
          <td class="r"><?= number_format($a, 2) ?></td>
          <td class="r"><b><?= number_format($pj, 2) ?></b></td>
          <td class="r" style="color:<?= $v > 0.5 ? '#c0293f' : ($v < -0.5 ? '#16a34a' : '#8a97ab') ?>;font-weight:700">
            <?= ($v >= 0 ? '+' : '') . number_format($v, 2) ?></td>
          <td style="font-size:11.5px;color:#5a6b82"><?= $src ?></td>
        </tr>
      <?php endforeach; ?>
    </tbody>
    <tfoot>
      <tr><td>Total cost</td><td class="r"><?= number_format($C['quoted']['total'], 2) ?></td><td class="r"><?= $C['committed'] > 0 ? number_format($C['committed'], 2) : '—' ?></td>
        <td class="r"><?= number_format($C['actual']['total'], 2) ?></td><td class="r"><?= number_format($C['proj']['total'], 2) ?></td>
        <td class="r" style="color:<?= $C['proj']['total'] > $C['quoted']['total'] ? '#c0293f' : '#16a34a' ?>">
          <?= (($C['proj']['total'] - $C['quoted']['total']) >= 0 ? '+' : '') . number_format($C['proj']['total'] - $C['quoted']['total'], 2) ?></td><td></td></tr>
      <tr><td>Margin</td><td class="r"><?= number_format($C['sell'] - $C['quoted']['total'], 2) ?> · <?= number_format($qMargin, 1) ?>%</td>
        <td class="r"></td><td class="r"></td>
        <td class="r" style="color:<?= $drift < -0.05 ? '#c0293f' : '#16a34a' ?>"><?= number_format($C['sell'] - $C['proj']['total'], 2) ?> · <?= number_format($pMargin, 1) ?>%</td>
        <td class="r"></td><td></td></tr>
    </tfoot>
  </table></div>

  <?php if ($C['made'] <= 0): ?>
    <div class="oc-note info" style="margin-top:16px">Nothing has been produced against this order yet, so the projection is simply the quote. It becomes a real forecast as soon as the first consumption is posted.</div>
  <?php elseif ($drift < -0.5): ?>
    <div class="oc-note bad" style="margin-top:16px"><b>Margin is heading <?= number_format(abs($drift), 1) ?> points below quote.</b>
      <?= number_format($C['ordered'] - $C['made'], 0) ?> pieces of this order are still to make, so this is still actionable — the alternative is finding out in Final Costing after the container has sailed.</div>
  <?php else: ?>
    <div class="oc-note info" style="margin-top:16px">Running in line with the quote on the pieces produced so far.</div>
  <?php endif; ?>
</div>

<div class="oc-card">
  <h2 style="font-size:15px;margin:0 0 4px;font-weight:800">Other charges booked to this order</h2>
  <p style="color:#8a97ab;font-size:12px;margin:0 0 14px">Freight, inspection, commission — the costs that are neither material nor wages.</p>
  <?php if ($charges): ?>
  <div style="overflow-x:auto"><table class="oc-tbl">
    <thead><tr><th>Date</th><th>Group</th><th>Description</th><th class="r">Amount</th><th></th></tr></thead>
    <tbody>
    <?php foreach ($charges as $ch): ?>
      <tr><td><?= e((string)$ch['charge_date']) ?></td><td><?= e($ch['charge_group']) ?></td>
        <td><?= e($ch['description'] ?: '—') ?></td>
        <td class="r"><b><?= number_format((float)$ch['amount'], 2) ?></b> <span style="color:#8a97ab;font-size:11px"><?= e($ch['currency']) ?></span></td>
        <td style="text-align:right"><?php if ($canEdit): ?>
          <form method="post" style="display:inline"><?= csrf_field() ?><input type="hidden" name="action" value="del_charge">
            <input type="hidden" name="proforma_id" value="<?= (int)$id ?>"><input type="hidden" name="cid" value="<?= (int)$ch['id'] ?>">
            <button class="oc-btn sec" style="padding:4px 9px;font-size:11.5px;cursor:pointer" type="submit">Remove</button></form>
        <?php endif; ?></td></tr>
    <?php endforeach; ?>
    </tbody>
  </table></div>
  <?php else: ?><p style="color:#8a97ab;font-size:12.5px">No charges booked yet.</p><?php endif; ?>

  <?php if ($canEdit): ?>
  <form method="post" style="margin-top:16px"><?= csrf_field() ?>
    <input type="hidden" name="action" value="add_charge"><input type="hidden" name="proforma_id" value="<?= (int)$id ?>">
    <div class="oc-grid" style="align-items:end">
      <div><label class="oc-lbl">Group</label>
        <select class="oc-inp" name="charge_group">
          <?php foreach (['Freight','Inspection','Commission','Bank','Clearing','Other'] as $g): ?><option><?= e($g) ?></option><?php endforeach; ?>
        </select></div>
      <div><label class="oc-lbl">Description</label><input class="oc-inp" name="description"></div>
      <div><label class="oc-lbl">Amount</label><input class="oc-inp" name="amount" style="text-align:right"></div>
      <div style="display:flex;gap:8px;align-items:end">
        <div style="flex:1"><label class="oc-lbl">Date</label><input class="oc-inp" type="date" name="charge_date" value="<?= e(date('Y-m-d')) ?>"></div>
        <button class="oc-btn" type="submit">Book</button></div>
    </div>
  </form>
  <?php endif; ?>
</div>

<?php else: ?>
<div class="oc-card">
  <div class="oc-note info">
    <b>Nothing is typed on this page.</b> Material cost comes from posted consumption, wages from your existing production entries, other charges from what you book below each order, and the quote from the costing the order was priced on. The only figure this module adds is the comparison.
  </div>
  <?php if (!$list): ?>
    <p style="color:#8a97ab;font-size:13px;padding:26px 0;text-align:center">No proforma orders found.</p>
  <?php else: ?>
  <div style="overflow-x:auto"><table class="oc-tbl">
    <thead><tr><th>Order</th><th>Customer</th><th class="r">Ordered</th><th class="r">Selling</th>
      <th class="r">Material</th><th class="r">Wages</th><th class="r">Other</th><th class="r">Actual cost</th><th>Production</th><th></th></tr></thead>
    <tbody>
    <?php foreach ($list as $r):
      $act = (float)$r['amat'] + (float)$r['awage'] + (float)$r['aoth'];
      $has = $act > 0; ?>
      <tr<?= $has ? '' : ' style="opacity:.6"' ?>>
        <td style="font-family:monospace;font-weight:700"><?= e($r['pi_no']) ?></td>
        <td><?= e($r['customer_name']) ?></td>
        <td class="r"><?= number_format((float)$r['oqty'], 0) ?></td>
        <td class="r"><?= number_format((float)$r['sell'], 0) ?></td>
        <td class="r"><?= (float)$r['amat'] > 0 ? number_format((float)$r['amat'], 0) : '—' ?></td>
        <td class="r"><?= (float)$r['awage'] > 0 ? number_format((float)$r['awage'], 0) : '—' ?></td>
        <td class="r"><?= (float)$r['aoth'] > 0 ? number_format((float)$r['aoth'], 0) : '—' ?></td>
        <td class="r" style="font-weight:800"><?= $has ? number_format($act, 0) : '—' ?></td>
        <td><?= $r['production_status'] ? '<span class="oc-pill" style="background:#f0f3f9;color:#5a6b82">' . e(str_replace('_', ' ', $r['production_status'])) . '</span>' : '' ?></td>
        <td style="text-align:right"><a class="oc-btn sec" style="padding:5px 10px;font-size:11.5px" href="?id=<?= (int)$r['id'] ?>">Open</a></td>
      </tr>
    <?php endforeach; ?>
    </tbody>
  </table></div>
  <p style="font-size:11.5px;color:#8a97ab;margin:14px 0 0">Orders shown faded have no actual cost recorded yet — they will fill in as consumption and production are entered against them.</p>
  <?php endif; ?>
</div>
<?php endif; ?>
</div><?php /* closes .zskin */ ?>
<?php page_footer(); ?>
