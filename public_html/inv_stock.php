<?php
/* Current Stock — computed live from inv_stock_ledger. There is no cached
   balance anywhere in the module, so this can never disagree with the
   ledger it is built from.

   Owned and customer-held stock are listed separately and never added
   together: quantity always counts, value only counts when the goods are
   ours. */
require_once __DIR__ . '/includes/bootstrap.php';
require_login();
require_once __DIR__ . '/includes/inventory.php';
inv_ensure_schema();
if (!inv_can_see()) { http_response_code(403); exit('You do not have permission to view stock.'); }

$tab  = $_GET['tab'] ?? 'mat';           // mat | prod | cust
$loc  = (int)($_GET['loc'] ?? 0);
$q    = trim((string)($_GET['q'] ?? ''));
$zero = !empty($_GET['zero']);

$locations = inv_locations(true);

/* One grouped query per view. Grouping in SQL keeps this fast even once
   the ledger is large; the balance is SUM(in) - SUM(out) by definition. */
function inv_stock_rows(string $tab, int $loc, string $q, bool $zero): array {
    $own = $tab === 'cust' ? 'customer' : 'own';
    $isProd = $tab === 'prod';
    $w = ["l.ownership = ?"]; $p = [$own];
    if ($loc > 0) { $w[] = "l.location_id = ?"; $p[] = $loc; }
    $w[] = $isProd ? "l.product_id IS NOT NULL" : "l.material_id IS NOT NULL";

    if ($isProd) {
        $sel = "pr.id gid, pr.name gname, '' gcode, pr.default_unit uom, '' grp";
        $join = "JOIN products pr ON pr.id = l.product_id";
        $grp = "pr.id";
        if ($q !== '') { $w[] = "pr.name LIKE ?"; $p[] = "%$q%"; }
    } else {
        $sel = "m.id gid, m.name gname, m.code gcode, m.uom uom, m.item_group grp";
        $join = "JOIN inv_materials m ON m.id = l.material_id";
        $grp = "m.id";
        if ($q !== '') { $w[] = "(m.name LIKE ? OR m.code LIKE ?)"; $p[] = "%$q%"; $p[] = "%$q%"; }
    }
    $having = $zero ? '' : 'HAVING ABS(bal) > 0.0005';
    try {
        $sql = "SELECT $sel,
                COALESCE(SUM(l.qty_in),0) tin, COALESCE(SUM(l.qty_out),0) tout,
                COALESCE(SUM(l.qty_in),0)-COALESCE(SUM(l.qty_out),0) bal,
                COALESCE(SUM(l.value_amount),0) val,
                MAX(l.txn_date) last_move
            FROM inv_stock_ledger l $join
            WHERE " . implode(' AND ', $w) . "
            GROUP BY $grp $having
            ORDER BY gname LIMIT 500";
        $st = db()->prepare($sql); $st->execute($p);
        return $st->fetchAll();
    } catch (Throwable $e) { return []; }
}

$rows = inv_stock_rows($tab, $loc, $q, $zero);

/* Per-location split for the rows on screen, so each line can show where
   its quantity actually sits without a query per row. */
$split = [];
try {
    if ($rows) {
        $col = $tab === 'prod' ? 'product_id' : 'material_id';
        $ids = array_map(fn($r) => (int)$r['gid'], $rows);
        $in = implode(',', array_fill(0, count($ids), '?'));
        $own = $tab === 'cust' ? 'customer' : 'own';
        $st = db()->prepare("SELECT $col gid, location_id,
                COALESCE(SUM(qty_in),0)-COALESCE(SUM(qty_out),0) bal
            FROM inv_stock_ledger WHERE ownership=? AND $col IN ($in)
            GROUP BY $col, location_id");
        $st->execute(array_merge([$own], $ids));
        foreach ($st->fetchAll() as $r) {
            if (abs((float)$r['bal']) < 0.0005) continue;
            $split[(int)$r['gid']][] = ['loc' => inv_location_name((int)$r['location_id']), 'bal' => (float)$r['bal']];
        }
    }
} catch (Throwable $e) {}

$totVal = 0.0; foreach ($rows as $r) $totVal += (float)$r['val'];
$anyLedger = false;
try { $anyLedger = (int)db()->query("SELECT COUNT(*) FROM inv_stock_ledger")->fetchColumn() > 0; } catch (Throwable $e) {}

page_header('Current Stock');
flash();
?>
<div class="topbar">
  <div><h1>Current Stock</h1><p class="lead">Live from the stock ledger. Only posted documents are included — drafts never affect a figure on this page.</p></div>
</div>

<?php $pend = inv_pending_counts(); if (array_sum($pend)): ?>
<div style="background:#fff7ed;border:1px solid #fed7aa;border-left:4px solid #d97706;border-radius:12px;padding:14px 16px;margin-bottom:16px;font-size:12.5px;color:#9a3412;line-height:1.65">
  <b>Saved, but not posted — so none of it is in the figures below.</b><br>
  <?php if ($pend['gate']): ?><a href="inv_gate.php?dir=in&amp;status=draft" style="color:#9a3412;font-weight:800"><?= (int)$pend['gate'] ?> gate pass(es)</a> &nbsp;<?php endif; ?>
  <?php if ($pend['store']): ?><a href="inv_store.php?type=issue" style="color:#9a3412;font-weight:800"><?= (int)$pend['store'] ?> store movement(s)</a> &nbsp;<?php endif; ?>
  <?php if ($pend['consumption']): ?><a href="inv_consume.php" style="color:#9a3412;font-weight:800"><?= (int)$pend['consumption'] ?> consumption(s)</a><?php endif; ?>
  <br>Open the document and press its Post button. Stock only moves when a document is posted.
</div>
<?php endif; ?>

<style>
.is-card{background:#fff;border:1px solid #e3e9f2;border-radius:16px;padding:13px 15px;margin-bottom:11px;box-shadow:0 10px 30px rgba(15,35,65,.06)}
.is-bar{display:flex;gap:14px;flex-wrap:wrap;align-items:flex-end;background:#fff;border:1px solid #e3e9f2;border-radius:14px;padding:14px 16px;margin-bottom:16px}
.is-tabs{display:flex;gap:4px;background:#eef1f6;padding:4px;border-radius:11px;flex-wrap:wrap}
.is-tab{padding:7px 13px;border-radius:8px;font-size:12px;font-weight:700;text-decoration:none;color:#5a6b82}
.is-tab.on{background:#fff;color:#152033;box-shadow:0 1px 3px rgba(20,30,50,.12)}
.is-inp{padding:9px 11px;border-radius:9px;border:1px solid #cbd5e3;font-size:12.5px;font-family:inherit}
.is-lbl{display:block;font-size:10.5px;color:#8a97ab;font-weight:800;text-transform:uppercase;letter-spacing:.04em;margin-bottom:5px}
.is-tbl{width:100%;border-collapse:collapse;font-size:12.5px}
.is-tbl th{text-align:left;font-size:10px;text-transform:uppercase;letter-spacing:.05em;color:#8a97ab;font-weight:800;padding:0 8px 5px;white-space:nowrap}
.is-tbl td{padding:3px 8px;border-top:1px solid #eef1f7;vertical-align:top}
.is-tbl td.r,.is-tbl th.r{text-align:right;font-variant-numeric:tabular-nums}
.is-btn{padding:9px 16px;border:none;border-radius:10px;background:#fff;color:#152033;border:1px solid #cbd5e3;font-weight:700;font-size:12.5px;cursor:pointer;text-decoration:none;display:inline-block}
.is-pill{display:inline-block;font-size:10px;font-weight:800;padding:3px 8px;border-radius:20px}
.is-loc{font-size:11px;color:#5a6b82;margin-top:3px}
.is-note{border-radius:11px;padding:12px 15px;font-size:12.5px;line-height:1.65}
.is-note.info{background:rgba(14,168,201,.07);border:1px solid rgba(14,168,201,.2);color:#2c4a63}
.is-note.warn{background:rgba(217,119,6,.09);border:1px solid rgba(217,119,6,.25);color:#7a4d09}
</style>

<?php /* OPTING IN TO THE SKIN. Every rule in assets/css/zskin.css is
         scoped under .zskin, so this one wrapper is what makes the page
         compact, and deleting it restores the styles above with nothing
         else to undo. It wraps the markup and never the <style>. */ ?>
<div class="zskin">

<div class="is-bar">
  <div class="is-tabs">
    <a class="is-tab <?= $tab === 'mat' ? 'on' : '' ?>" href="?tab=mat">Fabric &amp; materials</a>
    <a class="is-tab <?= $tab === 'prod' ? 'on' : '' ?>" href="?tab=prod">Finished products</a>
    <a class="is-tab <?= $tab === 'cust' ? 'on' : '' ?>" href="?tab=cust">Customer-owned</a>
  </div>
  <form method="get" style="display:flex;gap:10px;align-items:flex-end;flex-wrap:wrap">
    <input type="hidden" name="tab" value="<?= e($tab) ?>">
    <div><label class="is-lbl">Search</label><input class="is-inp" name="q" value="<?= e($q) ?>" placeholder="name or code"></div>
    <div><label class="is-lbl">Location</label>
      <select class="is-inp" name="loc">
        <option value="0">All locations</option>
        <?php foreach ($locations as $l): ?><option value="<?= (int)$l['id'] ?>" <?= $loc === (int)$l['id'] ? 'selected' : '' ?>><?= e($l['name']) ?></option><?php endforeach; ?>
      </select></div>
    <label style="display:flex;gap:7px;align-items:center;font-size:12px;font-weight:600;padding-bottom:9px">
      <input type="checkbox" name="zero" value="1" <?= $zero ? 'checked' : '' ?>> Include nil balances</label>
    <button class="is-btn" type="submit">Apply</button>
  </form>
</div>

<?php if (!$anyLedger): ?>
  <div class="is-card"><div class="is-note info">
    <b>Nothing has been posted to stock yet.</b> Create a Gate Inward pass and post it — this page fills in the moment the first document is posted. Saving a pass as draft deliberately changes nothing here.
  </div></div>
<?php endif; ?>

<?php if ($tab === 'cust'): ?>
  <div class="is-card" style="margin-bottom:16px"><div class="is-note warn">
    <b>Customer-owned material held by you.</b> These quantities are your responsibility, but they are <b>not your asset</b> and carry no value in your books. They are never added to the other two tabs.
  </div></div>
<?php endif; ?>

<div class="is-card">
  <?php if (!$rows): ?>
    <p style="color:#8a97ab;font-size:13px;padding:26px 0;text-align:center">
      <?= $anyLedger ? 'No stock matches these filters.' : 'No stock to show yet.' ?>
    </p>
  <?php else: ?>
  <div style="overflow-x:auto"><table class="is-tbl">
    <thead><tr>
      <?php if ($tab !== 'prod'): ?><th>Code</th><?php endif; ?>
      <th>Item</th><?php if ($tab !== 'prod'): ?><th>Group</th><?php endif; ?>
      <th class="r">Received</th><th class="r">Issued</th><th class="r">Balance</th><th>UOM</th>
      <?php if ($tab !== 'cust'): ?><th class="r">Value</th><?php endif; ?>
      <th>Last move</th><th></th>
    </tr></thead>
    <tbody>
    <?php foreach ($rows as $r): $bal = (float)$r['bal']; ?>
      <tr>
        <?php if ($tab !== 'prod'): ?><td style="font-family:monospace;font-weight:700"><?= e($r['gcode']) ?></td><?php endif; ?>
        <td style="font-weight:600"><?= e($r['gname']) ?>
          <?php if (!empty($split[(int)$r['gid']]) && $loc === 0 && count($split[(int)$r['gid']]) > 1): ?>
            <div class="is-loc"><?php $bits = [];
              foreach ($split[(int)$r['gid']] as $s) $bits[] = e($s['loc']) . ' ' . number_format($s['bal'], 2);
              echo implode(' · ', $bits); ?></div>
          <?php endif; ?></td>
        <?php if ($tab !== 'prod'): ?><td><span class="is-pill" style="background:#f0f3f9;color:#5a6b82"><?= e($r['grp']) ?></span></td><?php endif; ?>
        <td class="r" style="color:#16a34a"><?= number_format((float)$r['tin'], 2) ?></td>
        <td class="r" style="color:#c0293f"><?= number_format((float)$r['tout'], 2) ?></td>
        <td class="r" style="font-weight:800;font-size:13px;<?= $bal < 0 ? 'color:#c0293f' : '' ?>"><?= number_format($bal, 2) ?></td>
        <td style="font-family:monospace"><?= e($r['uom'] ?: '') ?></td>
        <?php if ($tab !== 'cust'): ?><td class="r"><?= number_format((float)$r['val'], 2) ?></td><?php endif; ?>
        <td style="font-size:11.5px;color:#8a97ab"><?= e((string)$r['last_move']) ?></td>
        <td style="text-align:right"><a class="is-btn" style="padding:5px 10px;font-size:11.5px"
            href="inv_ledger.php?<?= $tab === 'prod' ? 'product' : 'material' ?>=<?= (int)$r['gid'] ?>&amp;own=<?= $tab === 'cust' ? 'customer' : 'own' ?>">Ledger</a></td>
      </tr>
    <?php endforeach; ?>
    </tbody>
    <?php if ($tab !== 'cust'): ?>
    <tfoot><tr style="border-top:2px solid #cbd5e3">
      <td colspan="<?= $tab === 'prod' ? 4 : 6 ?>" style="text-align:right;font-weight:800">Stock value</td>
      <td class="r" style="font-weight:800;font-size:13.5px"><?= number_format($totVal, 2) ?></td><td colspan="2"></td>
    </tr></tfoot>
    <?php endif; ?>
  </table></div>
  <p style="font-size:11.5px;color:#8a97ab;margin:14px 0 0"><?= count($rows) ?> item(s). A negative balance means more was issued than received — open the ledger to find the document responsible.</p>
  <?php endif; ?>
</div>
</div><?php /* closes .zskin */ ?>
<?php page_footer(); ?>
