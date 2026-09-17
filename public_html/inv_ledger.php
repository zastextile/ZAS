<?php
/* Stock Ledger — every movement of one item, in date order, with a
   running balance. Each row links back to the document that created it,
   which is the whole point: no figure in this module is unexplainable. */
require_once __DIR__ . '/includes/bootstrap.php';
require_login();
require_once __DIR__ . '/includes/inventory.php';
inv_ensure_schema();
if (!inv_can_see()) { http_response_code(403); exit('You do not have permission to view the stock ledger.'); }

/* Compact JSON of one item's movements, for the lot picker on the
   Consumption screen. Read-only, both ownerships together, newest last so
   a running balance can be accumulated straight down the list. */
if (!empty($_GET['json'])) {
    header('Content-Type: application/json');
    $mid = (int)($_GET['material_id'] ?? $_GET['material'] ?? 0);
    $out = [];
    if ($mid > 0) {
        try {
            $s = db()->prepare("SELECT txn_date, source_no, source_type, COALESCE(lot_no,'') lot_no,
                    location_id, ownership, qty_in, qty_out
                FROM inv_stock_ledger WHERE material_id = ?
                ORDER BY txn_date, id LIMIT 400");
            $s->execute([$mid]);
            foreach ($s->fetchAll() as $r) {
                $out[] = [
                    'txn_date' => $r['txn_date'],
                    'source_no' => $r['source_no'] ?: strtoupper($r['source_type']),
                    'lot_no' => $r['lot_no'],
                    'location' => inv_location_name((int)$r['location_id']),
                    'ownership' => $r['ownership'],
                    'qty_in' => (float)$r['qty_in'],
                    'qty_out' => (float)$r['qty_out'],
                ];
            }
        } catch (Throwable $e) {}
    }
    echo json_encode(['ok' => true, 'rows' => $out]);
    exit;
}

$materialId = (int)($_GET['material'] ?? 0);
$productId  = (int)($_GET['product'] ?? 0);
$own        = ($_GET['own'] ?? 'own') === 'customer' ? 'customer' : 'own';
$loc        = (int)($_GET['loc'] ?? 0);
$party      = (int)($_GET['party'] ?? 0);
$from       = $_GET['from'] ?? '';
$to         = $_GET['to'] ?? '';

$item = null;
if ($materialId) {
    try { $s = db()->prepare("SELECT id,code,name,uom,item_group FROM inv_materials WHERE id=?"); $s->execute([$materialId]);
        $r = $s->fetch(); if ($r) $item = ['label' => $r['code'] . ' · ' . $r['name'], 'uom' => $r['uom'], 'sub' => $r['item_group']]; }
    catch (Throwable $e) {}
} elseif ($productId) {
    try { $s = db()->prepare("SELECT id,name,default_unit FROM products WHERE id=?"); $s->execute([$productId]);
        $r = $s->fetch(); if ($r) $item = ['label' => $r['name'], 'uom' => $r['default_unit'] ?: 'PCS', 'sub' => 'Finished product']; }
    catch (Throwable $e) {}
}

/* Opening balance = everything before the "from" date, so the running
   balance on screen is still the true balance and not a partial sum. */
$opening = 0.0; $rows = [];
if ($item) {
    $base = $materialId ? 'material_id = ?' : 'product_id = ?';
    $bid  = $materialId ?: $productId;
    try {
        if ($from !== '') {
            /* The opening balance has to obey exactly the same filters as
               the rows below it, party included — otherwise the running
               balance starts from a number that belongs to a different
               question. */
            $w = ['l.' . $base, 'l.ownership = ?', 'l.txn_date < ?']; $p = [$bid, $own, $from];
            if ($loc > 0)   { $w[] = 'l.location_id = ?'; $p[] = $loc; }
            if ($party > 0) { $w[] = 'g.party_id = ?';    $p[] = $party; }
            $s = db()->prepare("SELECT COALESCE(SUM(l.qty_in),0)-COALESCE(SUM(l.qty_out),0)
                FROM inv_stock_ledger l
                LEFT JOIN inv_gate g ON g.id = l.source_id AND l.source_type IN ('gate','gate_rev')
                WHERE " . implode(' AND ', $w));
            $s->execute($p); $opening = (float)$s->fetchColumn();
        }
        /* The party on each movement, joined back from the gate pass that
           wrote it. No column on the ledger stores this — the pass has it,
           and every row a pass wrote points back at the pass. A store
           issue between your own floors has no party, and correctly comes
           back NULL rather than being guessed at. */
        $w = ['l.' . $base, 'l.ownership = ?']; $p = [$bid, $own];
        if ($loc > 0)     { $w[] = 'l.location_id = ?'; $p[] = $loc; }
        if ($from !== '') { $w[] = 'l.txn_date >= ?';   $p[] = $from; }
        if ($to !== '')   { $w[] = 'l.txn_date <= ?';   $p[] = $to; }
        if ($party > 0)   { $w[] = 'g.party_id = ?';    $p[] = $party; }
        $s = db()->prepare("SELECT l.*, u.name user_name,
                g.party_id, COALESCE(pt.name, g.party_text) party_name
            FROM inv_stock_ledger l
            LEFT JOIN users u ON u.id = l.created_by
            LEFT JOIN inv_gate g ON g.id = l.source_id AND l.source_type IN ('gate','gate_rev')
            LEFT JOIN inv_parties pt ON pt.id = g.party_id
            WHERE " . implode(' AND ', $w) . " ORDER BY l.txn_date, l.id LIMIT 1000");
        $s->execute($p); $rows = $s->fetchAll();
    } catch (Throwable $e) {}
}

/* Parties that actually appear against this item, so the filter offers
   only names that will return something rather than the whole master. */
$ledParties = [];
if ($item) {
    try {
        $s = db()->prepare("SELECT DISTINCT g.party_id, pt.name
            FROM inv_stock_ledger l
            JOIN inv_gate g ON g.id = l.source_id AND l.source_type IN ('gate','gate_rev')
            JOIN inv_parties pt ON pt.id = g.party_id
            WHERE l." . $base . " AND l.ownership = ? ORDER BY pt.name");
        $s->execute([$bid, $own]);
        $ledParties = $s->fetchAll();
    } catch (Throwable $e) {}
}

/* Pickers for when no item is chosen yet. */
$mats = []; $prods = [];
if (!$item) {
    try { $mats = db()->query("SELECT m.id,m.code,m.name,m.uom,
            COALESCE(SUM(l.qty_in),0)-COALESCE(SUM(l.qty_out),0) bal
        FROM inv_materials m JOIN inv_stock_ledger l ON l.material_id=m.id AND l.ownership='own'
        GROUP BY m.id ORDER BY m.name LIMIT 300")->fetchAll(); } catch (Throwable $e) {}
    try { $prods = db()->query("SELECT p.id,p.name,
            COALESCE(SUM(l.qty_in),0)-COALESCE(SUM(l.qty_out),0) bal
        FROM products p JOIN inv_stock_ledger l ON l.product_id=p.id AND l.ownership='own'
        GROUP BY p.id ORDER BY p.name LIMIT 300")->fetchAll(); } catch (Throwable $e) {}
}
$locations = inv_locations(true);

function inv_ledger_link(string $type, int $id): string {
    if ($type === 'gate' || $type === 'gate_rev') return 'inv_gate.php?id=' . $id;
    return '';
}
function inv_ledger_label(string $type): string {
    return [
        'gate' => 'Gate pass', 'gate_rev' => 'Reversal',
        'issue' => 'Store issue', 'return' => 'Store return',
        'consumption' => 'Consumption', 'adjust' => 'Adjustment',
    ][$type] ?? ucfirst($type);
}

page_header('Stock Ledger');
flash();
?>
<div class="topbar">
  <div><h1>Stock Ledger</h1><p class="lead"><?= $item ? e($item['label']) : 'Every movement of one item, with a running balance.' ?></p></div>
  <a class="zbtn sec" href="inv_stock.php">← Current Stock</a>
</div>

<?php $pend = inv_pending_counts(); if (array_sum($pend)): ?>
<div style="background:#fff7ed;border:1px solid #fed7aa;border-left:4px solid #d97706;border-radius:12px;padding:14px 16px;margin-bottom:16px;font-size:12.5px;color:#9a3412;line-height:1.65">
  <b>The ledger only records posted documents.</b>
  Right now <?= (int)$pend['gate'] ?> gate pass(es), <?= (int)$pend['store'] ?> store movement(s) and
  <?= (int)$pend['consumption'] ?> consumption(s) are saved but not posted, so nothing from them appears here.
  Open the document and press its Post button.
</div>
<?php endif; ?>

<style>
.il-card{background:#fff;border:1px solid #e3e9f2;border-radius:16px;padding:13px 15px;margin-bottom:11px;box-shadow:0 10px 30px rgba(15,35,65,.06)}
.il-bar{display:flex;gap:14px;flex-wrap:wrap;align-items:flex-end;background:#fff;border:1px solid #e3e9f2;border-radius:14px;padding:14px 16px;margin-bottom:16px}
.il-inp{padding:9px 11px;border-radius:9px;border:1px solid #cbd5e3;font-size:12.5px;font-family:inherit}
.il-lbl{display:block;font-size:10.5px;color:#8a97ab;font-weight:800;text-transform:uppercase;letter-spacing:.04em;margin-bottom:5px}
.il-tbl{width:100%;border-collapse:collapse;font-size:12.5px}
.il-tbl th{text-align:left;font-size:10px;text-transform:uppercase;letter-spacing:.05em;color:#8a97ab;font-weight:800;padding:0 8px 5px;white-space:nowrap}
.il-tbl td{padding:3px 8px;border-top:1px solid #eef1f7}
.il-tbl td.r,.il-tbl th.r{text-align:right;font-variant-numeric:tabular-nums}
.il-tbl tr.rev td{background:rgba(224,67,93,.05)}
.il-btn{padding:9px 16px;border-radius:10px;background:#fff;color:#152033;border:1px solid #cbd5e3;font-weight:700;font-size:12.5px;cursor:pointer;text-decoration:none;display:inline-block}
.il-pick{display:flex;flex-wrap:wrap;gap:8px}
.il-chip{display:inline-flex;align-items:center;gap:8px;background:#f7f9fc;border:1px solid #e3e9f2;border-radius:10px;
  padding:9px 13px;font-size:12.5px;font-weight:600;color:#33415c;text-decoration:none}
.il-chip:hover{border-color:#0ea8c9;color:#0b7f9b}
.il-chip b{font-variant-numeric:tabular-nums;color:#152033}
.il-note{border-radius:11px;padding:12px 15px;font-size:12.5px;line-height:1.65;background:rgba(14,168,201,.07);border:1px solid rgba(14,168,201,.2);color:#2c4a63}
</style>

<?php /* OPTING IN TO THE SKIN. Every rule in assets/css/zskin.css is
         scoped under .zskin, so this one wrapper is what makes the page
         compact, and deleting it restores the styles above with nothing
         else to undo. It wraps the markup and never the <style>. */ ?>
<div class="zskin">

<?php if (!$item): ?>
  <div class="il-card">
    <h2 style="font-size:15px;margin:0 0 4px;font-weight:800">Pick an item</h2>
    <p style="color:#8a97ab;font-size:12px;margin:0 0 14px">Only items with at least one posted movement appear here.</p>
    <?php if (!$mats && !$prods): ?>
      <div class="il-note">Nothing has been posted to stock yet. Post a Gate Inward pass and its movements will appear here.</div>
    <?php else: ?>
      <?php if ($mats): ?>
        <h3 style="font-size:11px;text-transform:uppercase;letter-spacing:.06em;color:#8a97ab;margin:14px 0 9px">Materials</h3>
        <div class="il-pick"><?php foreach ($mats as $m): ?>
          <a class="il-chip" href="?material=<?= (int)$m['id'] ?>"><?= e($m['code']) ?> · <?= e($m['name']) ?> <b><?= number_format((float)$m['bal'], 2) ?></b> <span style="color:#8a97ab"><?= e($m['uom']) ?></span></a>
        <?php endforeach; ?></div>
      <?php endif; ?>
      <?php if ($prods): ?>
        <h3 style="font-size:11px;text-transform:uppercase;letter-spacing:.06em;color:#8a97ab;margin:20px 0 9px">Finished products</h3>
        <div class="il-pick"><?php foreach ($prods as $p): ?>
          <a class="il-chip" href="?product=<?= (int)$p['id'] ?>"><?= e($p['name']) ?> <b><?= number_format((float)$p['bal'], 2) ?></b></a>
        <?php endforeach; ?></div>
      <?php endif; ?>
    <?php endif; ?>
  </div>
<?php else: ?>

<div class="il-bar">
  <form method="get" style="display:flex;gap:12px;align-items:flex-end;flex-wrap:wrap">
    <?php if ($materialId): ?><input type="hidden" name="material" value="<?= $materialId ?>"><?php else: ?><input type="hidden" name="product" value="<?= $productId ?>"><?php endif; ?>
    <input type="hidden" name="own" value="<?= e($own) ?>">
    <div><label class="il-lbl">From</label><input class="il-inp" type="date" name="from" value="<?= e($from) ?>"></div>
    <div><label class="il-lbl">To</label><input class="il-inp" type="date" name="to" value="<?= e($to) ?>"></div>
    <div><label class="il-lbl">Location</label>
      <select class="il-inp" name="loc">
        <option value="0">All</option>
        <?php foreach ($locations as $l): ?><option value="<?= (int)$l['id'] ?>" <?= $loc === (int)$l['id'] ? 'selected' : '' ?>><?= e($l['name']) ?></option><?php endforeach; ?>
      </select></div>
    <?php /* Choosing a party turns this page into that party's own
             statement for this item. Only parties that actually appear
             against it are listed — a filter that can return nothing is
             not a filter, it is a trap. */ ?>
    <div><label class="il-lbl">Party</label>
      <select class="il-inp" name="party" style="min-width:180px">
        <option value="0">— all parties —</option>
        <?php foreach ($ledParties as $lp): ?>
          <option value="<?= (int)$lp['party_id'] ?>" <?= $party === (int)$lp['party_id'] ? 'selected' : '' ?>><?= e($lp['name']) ?></option>
        <?php endforeach; ?>
      </select>
      <?php if (!$ledParties): ?><div style="font-size:10.5px;color:#8a97ab;margin-top:4px">No gate pass has named a party for this item yet.</div><?php endif; ?>
    </div>
    <button class="il-btn" type="submit">Apply</button>
    <a class="il-btn" href="?<?= $materialId ? 'material=' . $materialId : 'product=' . $productId ?>">Clear</a>
  </form>
</div>

<div class="il-card">
  <div style="display:flex;justify-content:space-between;gap:14px;flex-wrap:wrap;align-items:baseline;margin-bottom:14px">
    <div><h2 style="font-size:16px;margin:0;font-weight:800"><?= e($item['label']) ?></h2>
      <p style="color:#8a97ab;font-size:12px;margin:4px 0 0"><?= e($item['sub']) ?> · <?= e($item['uom']) ?><?= $own === 'customer' ? ' · customer-owned, not valued' : '' ?></p></div>
  </div>

  <?php if (!$rows): ?>
    <p style="color:#8a97ab;font-size:13px;padding:26px 0;text-align:center">No movements in this range.</p>
  <?php else: ?>
  <?php /* The Party column appears only when no party is chosen. Once
           you pick one every row is theirs, so a column repeating the
           same name down the page is noise. */
  $showParty = $party === 0;
  $cOpen = $showParty ? 8 : 7;     // columns left of Balance
  $cTot  = $showParty ? 6 : 5;     // columns left of In
  ?>
  <div style="overflow-x:auto"><table class="il-tbl">
    <thead><tr><th>Date</th><th>Document</th><th>Type</th><th>Linked</th><th>Location</th>
      <?php if ($showParty): ?><th>Party</th><?php endif; ?>
      <th class="r">In</th><th class="r">Out</th><th class="r">Balance</th><th>User</th><th>Remarks</th></tr></thead>
    <tbody>
      <?php if ($from !== ''): ?>
        <tr><td colspan="<?= $cOpen ?>" style="text-align:right;font-weight:700;color:#5a6b82">Opening balance</td>
          <td class="r" style="font-weight:800"><?= number_format($opening, 3) ?></td><td colspan="2"></td></tr>
      <?php endif; ?>
      <?php $run = $opening; $ti = 0; $to_ = 0;
      foreach ($rows as $r):
        $in = (float)$r['qty_in']; $out = (float)$r['qty_out'];
        $run += $in - $out; $ti += $in; $to_ += $out;
        $link = inv_ledger_link($r['source_type'], (int)$r['source_id']); ?>
        <tr class="<?= $r['source_type'] === 'gate_rev' ? 'rev' : '' ?>">
          <td><?= e((string)$r['txn_date']) ?></td>
          <td style="font-family:monospace;font-weight:700">
            <?= $link ? '<a href="' . e($link) . '">' . e($r['source_no'] ?: ('#' . $r['source_id'])) . '</a>' : e($r['source_no'] ?: ('#' . $r['source_id'])) ?></td>
          <td><?= e(inv_ledger_label($r['source_type'])) ?></td>
          <td style="font-size:11.5px;color:#5a6b82"><?= $r['proforma_id'] ? 'PI#' . (int)$r['proforma_id'] : ($r['contract_id'] ? 'CT#' . (int)$r['contract_id'] : '—') ?></td>
          <td style="font-size:11.5px"><?= e(inv_location_name((int)$r['location_id'])) ?></td>
          <?php if ($showParty): ?>
            <td style="font-size:11.5px"><?= $r['party_name']
              ? '<b>' . e($r['party_name']) . '</b>'
              : '<span style="color:#c0c8d4">—</span>' ?></td>
          <?php endif; ?>
          <td class="r" style="color:#16a34a"><?= $in > 0 ? number_format($in, 3) : '' ?></td>
          <td class="r" style="color:#c0293f"><?= $out > 0 ? number_format($out, 3) : '' ?></td>
          <td class="r" style="font-weight:800"><?= number_format($run, 3) ?></td>
          <td style="font-size:11.5px;color:#5a6b82"><?= e($r['user_name'] ?: '—') ?></td>
          <td style="font-size:11.5px;color:#5a6b82"><?= e($r['remarks'] ?: '') ?></td>
        </tr>
      <?php endforeach; ?>
    </tbody>
    <tfoot><tr style="border-top:2px solid #cbd5e3">
      <td colspan="<?= $cTot ?>" style="text-align:right;font-weight:800"><?= $party > 0
        ? e(inv_party_name($party)) . ' — totals' : 'Totals' ?></td>
      <td class="r" style="font-weight:800;color:#16a34a"><?= number_format($ti, 3) ?></td>
      <td class="r" style="font-weight:800;color:#c0293f"><?= number_format($to_, 3) ?></td>
      <td class="r" style="font-weight:800;font-size:14px"><?= number_format($run, 3) ?></td>
      <td colspan="2" style="color:#8a97ab;font-size:11.5px">closing balance</td>
    </tr></tfoot>
  </table></div>
  <div class="il-note" style="margin-top:14px">
    Every row here was written by a posted document, and the document number links straight back to it. Rows shaded red are reversals — the original entry is deliberately left in place so the history reads as what happened, then the correction.
  </div>
  <?php endif; ?>
</div>
<?php endif; ?>
</div><?php /* closes .zskin */ ?>
<?php page_footer(); ?>
