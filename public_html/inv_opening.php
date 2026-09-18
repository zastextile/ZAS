<?php
/* Opening Stock.

   Where a balance comes from when no document created it — the day the
   system is switched on, an item found on a shelf that was never
   entered, a store that was counted for the first time.

   Until this existed, stock could only arrive through a posted gate
   pass. The thing people reach for instead is typing a number straight
   onto a balance, and that is how a stock figure becomes something
   nobody can explain six months later.

   So it is a DOCUMENT, with the same shape as every other document in
   this module: numbered, dated, draft until posted, written to the
   ledger through inv_post_ledger() like everything else, and reversible.
   An opening balance is therefore always traceable to a date, a
   document and a person.

   Draft changes nothing. Only Post writes stock, through
   inv_opening_post() in includes/inventory.php. */
require_once __DIR__ . '/includes/bootstrap.php';
require_login();
require_once __DIR__ . '/includes/inventory.php';
inv_ensure_schema();
if (!inv_can_see()) { http_response_code(403); exit('You do not have permission to view stock documents.'); }

/* Opening stock creates quantity out of nothing, which is the same power
   a gate receipt has, so it is the same permission. Posting is separate,
   as everywhere else in this module. */
$canEdit = inv_perm('gate') || inv_perm('master');
$canPost = inv_perm('post');
$canRev  = inv_perm('adjust');

/* What the ledger already holds for one item / store / lot / size.
   Read-only, and the single thing the "In stock now" column asks for. */
if (($_GET['ajax'] ?? '') === 'onhand') {
    header('Content-Type: application/json');
    echo json_encode([
        'ok'  => true,
        'qty' => inv_opening_onhand(
            (int)($_GET['material_id'] ?? 0), (int)($_GET['product_id'] ?? 0),
            (int)($_GET['location_id'] ?? 0), trim((string)($_GET['lot'] ?? '')),
            trim((string)($_GET['size'] ?? ''))),
    ]);
    exit;
}

/* ------------------------------------------------------------ actions */
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verify_csrf();
    $act = $_POST['action'] ?? '';

    if ($act === 'save') {
        if (!$canEdit) { http_response_code(403); exit('You do not have permission to enter opening stock.'); }
        $id   = (int)($_POST['id'] ?? 0);
        $date = ($_POST['opening_date'] ?? '') !== '' ? $_POST['opening_date'] : date('Y-m-d');

        /* An opening balance dated in the future would sit in the ledger
           ahead of movements that have already happened, and every
           as-at report before that date would be wrong. */
        if ($date > date('Y-m-d')) {
            $_SESSION['error'] = 'An opening stock document cannot be dated in the future.';
            redirect('inv_opening.php' . ($id ? '?id=' . $id . '&edit=1' : '?new=1'));
        }

        $no = strtoupper(trim((string)($_POST['opening_no'] ?? '')));
        if ($no === '' && $id > 0) {
            try { $s = db()->prepare("SELECT opening_no FROM inv_opening WHERE id=?"); $s->execute([$id]); $no = (string)$s->fetchColumn(); } catch (Throwable $e) {}
        }
        if ($no === '') $no = inv_next_no('prefix_opening', 'inv_opening', 'opening_no');

        $f = [
            'opening_no'   => $no,
            'opening_date' => $date,
            'location_id'  => (int)($_POST['location_id'] ?? 0) ?: null,
            'remarks'      => trim((string)($_POST['remarks'] ?? '')) ?: null,
            'status'       => 'draft',
        ];

        try {
            if ($id > 0) {
                $s = db()->prepare("SELECT status FROM inv_opening WHERE id=?"); $s->execute([$id]);
                $cur = $s->fetchColumn();
                if ($cur === 'posted' || $cur === 'reversed') {
                    $_SESSION['error'] = 'A posted document cannot be edited. Reverse it and raise a new one.';
                    redirect('inv_opening.php?id=' . $id);
                }
            }
            db()->beginTransaction();
            if ($id > 0) {
                $sets = implode(',', array_map(fn($k) => "$k=?", array_keys($f)));
                $vals = array_values($f); $vals[] = $id;
                db()->prepare("UPDATE inv_opening SET $sets, updated_at=NOW() WHERE id=?")->execute($vals);
            } else {
                $cols = implode(',', array_keys($f)) . ',created_by';
                $ph   = implode(',', array_fill(0, count($f) + 1, '?'));
                $vals = array_values($f); $vals[] = (int)(current_user()['id'] ?? 0);
                db()->prepare("INSERT INTO inv_opening ($cols) VALUES ($ph)")->execute($vals);
                $id = (int)db()->lastInsertId();
            }
            db()->prepare("DELETE FROM inv_opening_items WHERE opening_id=?")->execute([$id]);
            $ins = db()->prepare("INSERT INTO inv_opening_items
                (opening_id,material_id,product_id,size_label,location_id,lot_no,qty,uom,rate,amount,ownership,sort_order)
                VALUES (?,?,?,?,?,?,?,?,?,?,?,?)");
            $defLoc = (int)$f['location_id'] ?: (int)inv_setting('default_location', '1');
            $n = 0; $neg = 0;
            foreach ((array)($_POST['line'] ?? []) as $ln) {
                $qty = inv_num($ln['qty'] ?? 0);
                /* "m12" or "p7" — one box picks from two tables, so the
                   kind travels with the id rather than being guessed at
                   from which of two hidden fields happens to be filled. */
                $key = (string)($ln['item_key'] ?? '');
                $mid = str_starts_with($key, 'm') ? (int)substr($key, 1) : 0;
                $pid = str_starts_with($key, 'p') ? (int)substr($key, 1) : 0;
                if ($qty < 0) { $neg++; continue; }
                if ($qty <= 0 || ($mid <= 0 && $pid <= 0)) continue;
                $rate = inv_num($ln['rate'] ?? 0);
                $ins->execute([
                    $id, $mid ?: null, $pid ?: null,
                    trim((string)($ln['size_label'] ?? '')) ?: null,
                    (int)($ln['location_id'] ?? 0) ?: $defLoc,
                    trim((string)($ln['lot_no'] ?? '')) ?: null,
                    $qty, trim((string)($ln['uom'] ?? '')) ?: null,
                    $rate, round($qty * $rate, 2), 'own', $n++,
                ]);
            }
            db()->commit();
            inv_audit('opening_save', $id, ['no' => $no, 'lines' => $n], 'Opening stock saved');
            $msg = 'Opening stock ' . $no . ' saved with ' . $n . ' line(s). Nothing has moved in stock yet — press "Post to stock" below.';
            if ($n === 0) $msg = 'Opening stock ' . $no . ' saved, but with NO lines. A line is only kept if it has both an item picked from the list and a quantity above 0.';
            if ($neg > 0) $msg .= ' ' . $neg . ' line(s) with a negative quantity were dropped — opening stock cannot be negative.';
            $_SESSION['flash'] = $msg;
        } catch (Throwable $ex) {
            if (db()->inTransaction()) db()->rollBack();
            $_SESSION['error'] = 'Could not save the opening stock document.';
        }
        redirect('inv_opening.php?id=' . $id);
    }

    if ($act === 'post') {
        if (!$canPost) { http_response_code(403); exit('You do not have permission to post to stock.'); }
        $id = (int)($_POST['id'] ?? 0);
        $r = inv_opening_post($id);
        if ($r['ok']) $_SESSION['flash'] = 'Posted. The opening balances are now in stock and in the Stock Ledger.';
        else $_SESSION['error'] = $r['error'];
        redirect('inv_opening.php?id=' . $id);
    }

    if ($act === 'reverse') {
        if (!$canRev) { http_response_code(403); exit('You do not have permission to reverse a posted document.'); }
        $id = (int)($_POST['id'] ?? 0);
        $r = inv_opening_reverse($id, trim((string)($_POST['reason'] ?? '')));
        if ($r['ok']) $_SESSION['flash'] = 'Reversed. The opening quantities have been taken back out.';
        else $_SESSION['error'] = $r['error'];
        redirect('inv_opening.php?id=' . $id);
    }

    if ($act === 'delete') {
        if (!$canEdit) { http_response_code(403); exit('Not permitted.'); }
        $id = (int)($_POST['id'] ?? 0);
        try {
            $s = db()->prepare("SELECT status, opening_no FROM inv_opening WHERE id=?"); $s->execute([$id]);
            $row = $s->fetch();
            /* Nothing has moved, so there is nothing to undo. A posted
               document is never deleted — it is reversed, and both stay. */
            if (!$row || $row['status'] !== 'draft') {
                $_SESSION['error'] = 'Only a draft can be deleted. A posted document must be reversed.';
            } else {
                db()->beginTransaction();
                db()->prepare("DELETE FROM inv_opening_items WHERE opening_id=?")->execute([$id]);
                db()->prepare("DELETE FROM inv_opening WHERE id=?")->execute([$id]);
                db()->commit();
                inv_audit('opening_delete', $id, $row['opening_no'], 'Draft opening stock deleted');
                $_SESSION['flash'] = 'Draft ' . $row['opening_no'] . ' deleted.';
            }
        } catch (Throwable $e) {
            if (db()->inTransaction()) db()->rollBack();
            $_SESSION['error'] = 'Could not delete the draft.';
        }
        redirect('inv_opening.php');
    }
}

/* -------------------------------------------------------------- read */
$doc = null; $lines = [];
if (!empty($_GET['id'])) {
    try {
        $s = db()->prepare("SELECT o.*, u.name AS posted_name, c.name AS created_name
            FROM inv_opening o
            LEFT JOIN users u ON u.id = o.posted_by
            LEFT JOIN users c ON c.id = o.created_by
            WHERE o.id=?");
        $s->execute([(int)$_GET['id']]);
        $doc = $s->fetch() ?: null;
        if ($doc) {
            $s2 = db()->prepare("SELECT oi.*, m.code mcode, m.name mname, m.stage mstage,
                       p.name pname, l.name locname
                FROM inv_opening_items oi
                LEFT JOIN inv_materials m ON m.id = oi.material_id
                LEFT JOIN products p ON p.id = oi.product_id
                LEFT JOIN inv_locations l ON l.id = oi.location_id
                WHERE oi.opening_id=? ORDER BY oi.sort_order, oi.id");
            $s2->execute([(int)$doc['id']]);
            $lines = $s2->fetchAll();
        }
    } catch (Throwable $e) {}
}
$isNew    = isset($_GET['new']);
$showForm = $canEdit && ($isNew || ($doc && isset($_GET['edit']) && $doc['status'] === 'draft'));
if ($showForm && !$lines) $lines = [[]];

$locations = inv_locations(true);
$ITEMS     = ($showForm || $doc) ? inv_opening_items() : [];

$register = [];
if (!$showForm && !$doc) {
    try {
        $register = db()->query("SELECT o.*, COUNT(oi.id) lines, COALESCE(SUM(oi.qty),0) tqty,
                   COALESCE(SUM(oi.amount),0) tval, l.name locname
            FROM inv_opening o
            LEFT JOIN inv_opening_items oi ON oi.opening_id = o.id
            LEFT JOIN inv_locations l ON l.id = o.location_id
            GROUP BY o.id, l.name
            ORDER BY o.opening_date DESC, o.id DESC LIMIT 200")->fetchAll();
    } catch (Throwable $e) {}
}

$KIND = ['grey' => 'Grey', 'raw' => 'Raw', 'finished' => 'Finished', 'product' => 'Product', 'na' => '—'];

page_header('Opening Stock');
?>
<style>
.op-card{background:#fff;border-radius:14px;padding:18px 20px;box-shadow:0 1px 3px rgba(16,30,54,.08);margin-bottom:16px}
.op-lbl{font-size:10px;font-weight:800;text-transform:uppercase;letter-spacing:.05em;color:#8a97ab;margin-bottom:5px;display:block}
.op-in{padding:9px 11px;border-radius:9px;border:1px solid #cbd5e3;font-size:12.5px;font-family:inherit;width:100%}
.op-bar{display:flex;gap:12px;flex-wrap:wrap;align-items:flex-end;margin-bottom:14px}
.op-f{display:flex;flex-direction:column}
.op-tbl{width:100%;border-collapse:collapse;font-size:12.5px;table-layout:fixed}
.op-tbl th{text-align:left;font-size:10px;text-transform:uppercase;letter-spacing:.05em;color:#8a97ab;font-weight:800;padding:0 8px 5px;white-space:nowrap}
.op-tbl th.r{text-align:right}
.op-tbl td{padding:3px 6px;border-top:1px solid #eef1f7;vertical-align:middle}
.op-tbl td.r{text-align:right;font-variant-numeric:tabular-nums}
.op-tbl td.n{text-align:right;color:#8a97ab;font-family:monospace;font-size:11px}
.op-in.num{text-align:right;font-family:monospace;font-variant-numeric:tabular-nums}
.op-in.der{color:#5a6b82}
.op-k{display:inline-block;font-size:9.5px;font-weight:800;padding:1px 5px;border-radius:3px;white-space:nowrap}
.k-grey{background:#eef1f5;color:#5a6b82}
.k-raw{background:rgba(217,119,6,.12);color:#b45309}
.k-finished{background:rgba(22,163,74,.12);color:#16a34a}
.k-product{background:rgba(14,168,201,.12);color:#0b7f9b}
.k-na{background:#eef1f5;color:#8a97ab}
.op-tbl td.has{color:#b45309;font-weight:800}
.op-btn{padding:9px 16px;border:none;border-radius:10px;background:linear-gradient(100deg,#0ea8c9,#6d5bd0);color:#fff;font-weight:700;font-size:12.5px;cursor:pointer}
.op-btn.sec{background:#fff;color:#152033;border:1px solid #cbd5e3;text-decoration:none;display:inline-block}
.op-btn.go{background:linear-gradient(100deg,#16a34a,#0e8a3d)}
.op-btn.warn{background:linear-gradient(100deg,#e08a06,#c0293f)}
.op-x{height:22px;width:22px;padding:0;border:1px solid transparent;background:transparent;color:#8a97ab;border-radius:5px;cursor:pointer;font-size:13px;line-height:1}
.op-x:hover{background:rgba(224,67,93,.1);color:#c0293f}
.op-note{border-radius:11px;padding:12px 15px;font-size:12.5px;line-height:1.65;margin-bottom:14px}
.op-note.info{background:rgba(14,168,201,.07);border:1px solid rgba(14,168,201,.2);color:#2c4a63}
.op-note.warn{background:rgba(217,119,6,.09);border:1px solid rgba(217,119,6,.25);color:#7a4d09}
.op-note.bad{background:rgba(224,67,93,.08);border:1px solid rgba(224,67,93,.24);color:#8c2038}
.op-pill{display:inline-block;font-size:10px;font-weight:800;padding:3px 8px;border-radius:20px;white-space:nowrap}
.s-draft{background:#f0f3f9;color:#5a6b82}
.s-posted{background:rgba(22,163,74,.12);color:#16a34a}
.s-reversed{background:rgba(224,67,93,.12);color:#c0293f}
</style>

<?php /* OPTING IN TO THE SKIN. Every rule in assets/css/zskin.css is scoped
         under .zskin, so this one wrapper is what makes this page compact —
         and deleting it puts the page back to the styles above with nothing
         else to undo. It wraps the markup, never the <style>. */ ?>
<div class="zskin">

<h1 style="font-size:19px;font-weight:800;margin:0 0 4px">Opening stock</h1>
<p style="color:#8a97ab;font-size:12.5px;margin:0 0 16px;max-width:900px">
  Where a balance comes from when no document created it — switching the system on, a store counted
  for the first time, an item found on a shelf that was never entered. It posts to the same stock
  ledger as everything else, so an opening balance always carries its date, its document and who
  entered it.</p>

<?php flash(); ?>

<?php /* ============================== the form ============================== */ ?>
<?php if ($showForm): $D = $doc ?: []; ?>
<div class="op-card">
  <h2 style="font-size:15.5px;margin:0 0 4px;font-weight:800"><?= $doc ? 'Edit ' . e($doc['opening_no']) : 'New opening stock' ?></h2>
  <p style="color:#8a97ab;font-size:12px;margin:0 0 14px">The number is generated on save. A draft does not touch stock.</p>

  <form method="post" id="opForm"><?= csrf_field() ?>
    <input type="hidden" name="action" value="save">
    <input type="hidden" name="id" value="<?= (int)($D['id'] ?? 0) ?>">

    <div class="op-bar">
      <div class="op-f" style="width:150px"><label class="op-lbl">Opening date</label>
        <input class="op-in" type="date" name="opening_date" value="<?= e($D['opening_date'] ?? date('Y-m-d')) ?>" max="<?= date('Y-m-d') ?>"></div>
      <div class="op-f" style="width:190px"><label class="op-lbl">Store — new lines</label>
        <select class="op-in" name="location_id" id="opLoc">
          <?php foreach ($locations as $l): ?>
            <option value="<?= (int)$l['id'] ?>" <?= (int)($D['location_id'] ?? 0) === (int)$l['id'] ? 'selected' : '' ?>><?= e($l['name']) ?></option>
          <?php endforeach; ?>
        </select></div>
      <div class="op-f" style="flex:1;min-width:240px"><label class="op-lbl">Remarks</label>
        <input class="op-in" name="remarks" value="<?= e($D['remarks'] ?? '') ?>" placeholder="e.g. physical count 30 Jun 2026, sheet 3 of 7"></div>
    </div>

    <div style="overflow-x:auto"><table class="op-tbl" id="ogrid">
      <colgroup>
        <col style="width:26px"><col><col style="width:80px"><col style="width:138px">
        <col style="width:104px"><col style="width:62px"><col style="width:96px">
        <col style="width:96px"><col style="width:108px"><col style="width:98px"><col style="width:30px">
      </colgroup>
      <thead><tr>
        <th></th><th>Item</th><th>Kind</th><th>Store</th><th>Lot / roll &middot; size</th>
        <th>UOM</th><th class="r">Quantity</th><th class="r">Rate</th><th class="r">Value</th>
        <th class="r">In stock now</th><th></th>
      </tr></thead>
      <tbody id="obody">
      <?php foreach ($lines as $i => $L):
        $key = (int)($L['material_id'] ?? 0) > 0 ? 'm' . (int)$L['material_id']
             : ((int)($L['product_id'] ?? 0) > 0 ? 'p' . (int)$L['product_id'] : '');
        $label = (int)($L['material_id'] ?? 0) > 0
               ? trim(($L['mcode'] ?? '') . ' · ' . ($L['mname'] ?? ''))
               : (string)($L['pname'] ?? '');
        $stage = (int)($L['material_id'] ?? 0) > 0 ? (string)($L['mstage'] ?? 'na') : 'product';
      ?>
        <tr>
          <td class="n"><?= $i + 1 ?></td>
          <td><input class="op-in it" data-lov="opitem" autocomplete="off" spellcheck="false"
                     value="<?= e($label) ?>" placeholder="click here — the list opens">
              <input type="hidden" class="ikey" name="line[<?= $i ?>][item_key]" value="<?= e($key) ?>"></td>
          <td class="kind"><?= $key !== '' ? '<span class="op-k k-' . e($stage) . '">' . e($KIND[$stage] ?? $stage) . '</span>' : '' ?></td>
          <td><select class="op-in st" name="line[<?= $i ?>][location_id]">
              <?php foreach ($locations as $l): ?>
                <option value="<?= (int)$l['id'] ?>" <?= (int)($L['location_id'] ?? 0) === (int)$l['id'] ? 'selected' : '' ?>><?= e($l['name']) ?></option>
              <?php endforeach; ?>
              </select></td>
          <?php /* One column, two questions. A material has a lot; a product
                   has a size, and size_label is the column the ledger keeps
                   it in. Both are posted, and the save decides which one to
                   use from the item's kind — so a product's size can never
                   land in lot_no and become invisible to every size report. */ ?>
          <td><input class="op-in lot" style="font-family:monospace"
                     name="line[<?= $i ?>][<?= $key !== '' && $key[0] === 'p' ? 'size_label' : 'lot_no' ?>]"
                     value="<?= e($key !== '' && $key[0] === 'p' ? ($L['size_label'] ?? '') : ($L['lot_no'] ?? '')) ?>"
                     placeholder="optional"></td>
          <td><input class="op-in uom der" name="line[<?= $i ?>][uom]" value="<?= e($L['uom'] ?? '') ?>" tabindex="-1"></td>
          <td><input class="op-in num qty" name="line[<?= $i ?>][qty]" value="<?= e((string)($L['qty'] ?? '')) ?>" placeholder="0.000"></td>
          <td><input class="op-in num rate der" name="line[<?= $i ?>][rate]" value="<?= e((string)($L['rate'] ?? '')) ?>" placeholder="0.0000"></td>
          <td class="r val"><?= number_format((float)($L['amount'] ?? 0), 2) ?></td>
          <td class="r hasq">—</td>
          <td><button type="button" class="op-x" title="Remove this line">&times;</button></td>
        </tr>
      <?php endforeach; ?>
      </tbody>
      <tfoot>
        <tr><td colspan="6" style="text-align:right;font-weight:800">Total</td>
            <td class="r" id="tqty" style="font-weight:800">0</td><td></td>
            <td class="r" id="tval" style="font-weight:800">0.00</td>
            <td class="r" id="thas"></td><td></td></tr>
      </tfoot>
    </table></div>

    <div id="owarn" class="op-note warn" style="margin-top:12px;display:none"></div>

    <div style="margin-top:14px;display:flex;gap:10px;flex-wrap:wrap;align-items:center">
      <button type="button" class="op-btn sec" id="oadd">+ Add line</button>
      <button class="op-btn" type="submit">Save draft</button>
      <a class="op-btn sec" href="inv_opening.php">Back to register</a>
      <span style="font-size:11.5px;color:#8a97ab">Saving does not move stock. Post it from the document afterwards.</span>
    </div>
  </form>
</div>

<link rel="stylesheet" href="assets/css/lov.css?v=3">
<script src="assets/js/lov.js?v=3"></script>
<script>
(function(){
  var ITEMS = <?= json_encode($ITEMS, JSON_UNESCAPED_UNICODE) ?>;
  var KIND  = <?= json_encode($KIND, JSON_UNESCAPED_UNICODE) ?>;
  var LOCS  = <?= json_encode(array_map(fn($l) => ['id' => (int)$l['id'], 'name' => $l['name']], $locations), JSON_UNESCAPED_UNICODE) ?>;
  var tb = document.getElementById('obody');
  var defLoc = document.getElementById('opLoc');

  function num(v){ var n = parseFloat(String(v).replace(/[^0-9.\-]/g, '')); return isNaN(n) ? 0 : n; }
  function q3(v){ return Number(v).toLocaleString('en-US', {maximumFractionDigits:3}); }
  function m2(v){ return Number(v).toLocaleString('en-US', {minimumFractionDigits:2, maximumFractionDigits:2}); }
  function rows(){ return [].slice.call(tb.children); }
  function itemByKey(k){ for (var i=0;i<ITEMS.length;i++) if (ITEMS[i].key === k) return ITEMS[i]; return null; }

  function renumber(){
    rows().forEach(function(tr, i){
      tr.firstElementChild.textContent = i + 1;
      tr.querySelectorAll('[name]').forEach(function(el){
        el.name = el.name.replace(/line\[\d+\]/, 'line[' + i + ']');
      });
    });
  }

  function blank(){
    var last = rows()[rows().length - 1];
    var c = last.cloneNode(true);
    c.querySelectorAll('input').forEach(function(el){ el.value = ''; });
    c.querySelector('.kind').innerHTML = '';
    c.querySelector('.val').textContent = '0.00';
    var h = c.querySelector('.hasq'); h.textContent = '—'; h.classList.remove('has');
    /* the lot box goes back to being a lot box until an item says otherwise */
    var lot = c.querySelector('.lot');
    lot.name = lot.name.replace('[size_label]', '[lot_no]');
    lot.placeholder = 'optional';
    if (defLoc) c.querySelector('.st').value = defLoc.value;
    return c;
  }

  /* ---- what the ledger already holds ------------------------------
     Asked for one line at a time and cached by exactly the four things
     that identify a balance. A hundred-line count sheet would otherwise
     ask the server a hundred times for the same store. */
  var ONHAND = {};
  function keyOf(tr){
    var k = tr.querySelector('.ikey').value;
    if (!k) return null;
    return k + '|' + tr.querySelector('.st').value + '|' + tr.querySelector('.lot').value.trim();
  }
  function loadOnhand(tr, done){
    var key = keyOf(tr);
    if (!key){ done(null); return; }
    if (ONHAND[key] !== undefined){ done(ONHAND[key]); return; }
    var k = tr.querySelector('.ikey').value, it = itemByKey(k);
    var p = new URLSearchParams({
      ajax: 'onhand',
      material_id: (k[0] === 'm' ? k.slice(1) : 0),
      product_id:  (k[0] === 'p' ? k.slice(1) : 0),
      location_id: tr.querySelector('.st').value,
      lot:  (it && it.kind === 'prod') ? '' : tr.querySelector('.lot').value.trim(),
      size: (it && it.kind === 'prod') ? tr.querySelector('.lot').value.trim() : ''
    });
    fetch('inv_opening.php?' + p.toString())
      .then(function(r){ return r.json(); })
      .then(function(d){ var q = (d && d.ok) ? +d.qty : 0; ONHAND[key] = q; done(q); })
      .catch(function(){ done(null); });
  }

  function paint(){
    var tq = 0, tv = 0, flagged = [];
    rows().forEach(function(tr, i){
      var qty = num(tr.querySelector('.qty').value), rate = num(tr.querySelector('.rate').value);
      tr.querySelector('.val').textContent = m2(qty * rate);
      tq += qty; tv += qty * rate;
      var cell = tr.querySelector('.hasq'), h = tr.dataset.onhand;
      if (h === undefined || h === ''){ cell.textContent = '—'; cell.classList.remove('has'); return; }
      var hv = +h;
      cell.textContent = q3(hv);
      cell.classList.toggle('has', hv > 0.0005);
      if (hv > 0.0005 && qty > 0) {
        var lot = tr.querySelector('.lot').value.trim();
        flagged.push('line ' + (i + 1) + ' — ' + tr.querySelector('.it').value.split(' · ')[0]
          + ' already has ' + q3(hv) + ' at ' + tr.querySelector('.st').selectedOptions[0].text
          + (lot ? ' (' + lot + ')' : ''));
      }
    });
    document.getElementById('tqty').textContent = q3(tq);
    document.getElementById('tval').textContent = m2(tv);
    document.getElementById('thas').textContent = flagged.length ? flagged.length + ' flagged' : '';
    var w = document.getElementById('owarn');
    if (!flagged.length){ w.style.display = 'none'; w.innerHTML = ''; return; }
    w.style.display = '';
    w.innerHTML = '<b>' + flagged.length + ' line(s) already have stock.</b> An opening entry ADDS to what is '
      + 'there, so if that balance is the same goods you are counting now, it will double. This does not stop '
      + 'the entry — a different store or a different lot is a perfectly good reason.<br>' + flagged.join('<br>');
  }
  function refresh(tr){
    loadOnhand(tr, function(q){ tr.dataset.onhand = (q === null ? '' : q); paint(); });
  }

  LOV.register('opitem', {
    cols: [
      { label:'Code', w:'96px', cls:'cd', get:function(r,q){ return LOV.hl(r.it.code, q); } },
      { label:'Description', w:'minmax(150px,1fr)', cls:'nm', get:function(r,q){ return LOV.hl(r.it.name, q); } },
      { label:'Kind', w:'74px', cls:'gg', get:function(r){ return LOV.esc(KIND[r.it.stage] || r.it.stage); } },
      { label:'Group', w:'100px', cls:'gg', get:function(r){ return LOV.esc(r.it.grp); } },
      { label:'UOM', w:'46px', cls:'gg', get:function(r){ return LOV.esc(r.it.uom); } },
      { label:'Std rate', w:'78px', align:'r', cls:'nu', get:function(r){ return LOV.m2(r.it.rate); } }
    ],
    title: function(){ return 'Select item — materials and finished products'; },
    empty: function(f, q){ return q ? 'No item matches that.' : 'No items in the master yet.'; },
    rows: function(f, q, showAll, cb){
      var out = [];
      ITEMS.forEach(function(it){
        var sc = LOV.score(q, it.code, it.name, it.grp + ' ' + it.stage);
        if (sc > 0) out.push({ it:it, sc:sc });
      });
      out.sort(function(a,b){ return a.sc !== b.sc ? b.sc - a.sc : a.it.code.localeCompare(b.it.code); });
      cb(out, 0);
    },
    revert: function(f){
      /* a half-typed search is not a choice: put back whatever is stored */
      var tr = f.closest('tr'), k = tr.querySelector('.ikey').value, it = k ? itemByKey(k) : null;
      f.value = it ? (it.kind === 'mat' ? it.code + ' · ' + it.name : it.name) : '';
    },
    pick: function(f, r){
      var tr = f.closest('tr'), it = r.it;
      f.value = it.kind === 'mat' ? it.code + ' · ' + it.name : it.name;
      tr.querySelector('.ikey').value = it.key;
      tr.querySelector('.kind').innerHTML = '<span class="op-k k-' + it.stage + '">' + (KIND[it.stage] || it.stage) + '</span>';
      var u = tr.querySelector('.uom'); if (!u.value) u.value = it.uom;
      var rt = tr.querySelector('.rate'); if (!num(rt.value) && it.rate > 0) rt.value = Number(it.rate).toFixed(4);
      /* THE COLUMN CHANGES ITS QUESTION, AND ITS NAME WITH IT.
         A product's size must post as size_label, not lot_no, or it lands
         in a column no size report reads and the stock is invisible. */
      var lot = tr.querySelector('.lot');
      if (it.kind === 'prod'){
        lot.name = lot.name.replace('[lot_no]', '[size_label]');
        lot.placeholder = it.sizes && it.sizes.length ? it.sizes.join(' / ') : 'size';
      } else {
        lot.name = lot.name.replace('[size_label]', '[lot_no]');
        lot.placeholder = 'optional';
      }
      var qty = tr.querySelector('.qty'); qty.focus(); if (qty.select) qty.select();
      refresh(tr);
    }
  });
  LOV.attach(tb);

  tb.addEventListener('input', function(e){
    paint();
    if (e.target.classList.contains('lot')) refresh(e.target.closest('tr'));
  });
  tb.addEventListener('change', function(e){
    if (e.target.classList.contains('st')) refresh(e.target.closest('tr'));
    else paint();
  });
  tb.addEventListener('click', function(e){
    if (!e.target.classList.contains('op-x')) return;
    var tr = e.target.closest('tr');
    if (rows().length > 1) tr.remove();
    else {
      tr.querySelectorAll('input').forEach(function(i){ i.value = ''; });
      tr.querySelector('.kind').innerHTML = ''; tr.dataset.onhand = '';
    }
    renumber(); paint();
  });
  /* Enter on Quantity starts the next line. This is why a data entry
     person can stay on the keyboard for a hundred lines. */
  tb.addEventListener('keydown', function(e){
    if (e.key === 'Enter' && e.target.classList.contains('qty')){
      e.preventDefault();
      var tr = e.target.closest('tr');
      if (tr === tb.lastElementChild){ tb.appendChild(blank()); renumber(); }
      tr.nextElementSibling.querySelector('.it').focus();
    }
    if ((e.ctrlKey || e.metaKey) && (e.key === 'd' || e.key === 'D') && e.target.classList.contains('op-in')){
      e.preventDefault();
      var tr2 = e.target.closest('tr'), prev = tr2.previousElementSibling;
      if (!prev) return;
      var cls = ['it','lot','uom','qty','rate','st'].filter(function(c){ return e.target.classList.contains(c); })[0];
      if (!cls) return;
      e.target.value = prev.querySelector('.' + cls).value;
      if (cls === 'it'){
        tr2.querySelector('.ikey').value = prev.querySelector('.ikey').value;
        tr2.querySelector('.kind').innerHTML = prev.querySelector('.kind').innerHTML;
      }
      paint(); refresh(tr2);
    }
  });
  document.getElementById('oadd').addEventListener('click', function(){
    tb.appendChild(blank()); renumber();
    tb.lastElementChild.querySelector('.it').focus();
  });

  rows().forEach(function(tr){ if (tr.querySelector('.ikey').value) refresh(tr); });
  paint();
})();
</script>

<?php /* ========================= the saved document ========================= */ ?>
<?php elseif ($doc): ?>
<div class="op-card">
  <div style="display:flex;justify-content:space-between;gap:14px;flex-wrap:wrap;align-items:flex-start">
    <div>
      <h2 style="font-size:17px;margin:0;font-weight:800;font-family:monospace"><?= e($doc['opening_no']) ?></h2>
      <p style="color:#8a97ab;font-size:12.5px;margin:5px 0 0">
        Opening stock &middot; <?= e($doc['opening_date']) ?>
        <?= $doc['locname'] ?? '' ? ' &middot; ' . e($doc['locname']) : '' ?>
        <?= $doc['created_name'] ? ' &middot; entered by ' . e($doc['created_name']) : '' ?>
      </p>
    </div>
    <span class="op-pill s-<?= e($doc['status']) ?>"><?= e(ucfirst($doc['status'])) ?></span>
  </div>

  <?php if ($doc['status'] === 'reversed'): ?>
    <div class="op-note bad" style="margin-top:14px"><b>Reversed.</b>
      <?= e($doc['reversal_reason'] ?: 'No reason recorded.') ?>
      The quantities below were taken back out of stock; both this document and its reversal stay in the ledger.</div>
  <?php elseif ($doc['status'] === 'draft'): ?>
    <div class="op-note info" style="margin-top:14px">Saved, but <b>not posted</b>. Nothing has moved in stock yet.</div>
  <?php endif; ?>

  <div style="overflow-x:auto;margin-top:16px"><table class="op-tbl">
    <thead><tr><th>Item</th><th>Kind</th><th>Store</th><th>Lot / size</th>
      <th class="r">Quantity</th><th>UOM</th><th class="r">Rate</th><th class="r">Value</th></tr></thead>
    <tbody>
    <?php $tq = 0; $tv = 0; foreach ($lines as $L): $tq += (float)$L['qty']; $tv += (float)$L['amount'];
      $stage = (int)($L['material_id'] ?? 0) > 0 ? (string)($L['mstage'] ?? 'na') : 'product'; ?>
      <tr>
        <td style="font-weight:600"><?= e((int)($L['material_id'] ?? 0) > 0
              ? trim(($L['mcode'] ?? '') . ' · ' . ($L['mname'] ?? ''))
              : ($L['pname'] ?: '—')) ?></td>
        <td><span class="op-k k-<?= e($stage) ?>"><?= e($KIND[$stage] ?? $stage) ?></span></td>
        <td><?= e($L['locname'] ?: '—') ?></td>
        <td style="font-family:monospace"><?= e($L['lot_no'] ?: ($L['size_label'] ?: '—')) ?></td>
        <td class="r"><b><?= number_format((float)$L['qty'], 3) ?></b></td>
        <td style="font-family:monospace"><?= e($L['uom'] ?: '') ?></td>
        <td class="r"><?= number_format((float)$L['rate'], 2) ?></td>
        <td class="r"><?= number_format((float)$L['amount'], 2) ?></td>
      </tr>
    <?php endforeach; ?>
    </tbody>
    <tfoot><tr>
      <td colspan="4" style="text-align:right;font-weight:800">Total</td>
      <td class="r" style="font-weight:800"><?= number_format($tq, 3) ?></td>
      <td colspan="2"></td>
      <td class="r" style="font-weight:800"><?= number_format($tv, 2) ?></td>
    </tr></tfoot>
  </table></div>

  <?php if ($doc['remarks']): ?><p style="font-size:12.5px;color:#5a6b82;margin:14px 0 0"><b>Remarks:</b> <?= e($doc['remarks']) ?></p><?php endif; ?>

  <div style="margin-top:20px;display:flex;gap:10px;flex-wrap:wrap;align-items:center">
    <?php if ($doc['status'] === 'draft'): ?>
      <?php if ($canEdit): ?><a class="op-btn sec" href="inv_opening.php?id=<?= (int)$doc['id'] ?>&edit=1">Edit</a><?php endif; ?>
      <?php if ($canPost): ?>
        <form method="post" onsubmit="return confirm('Post this opening stock? The quantities will be added to stock and the document locked.');">
          <?= csrf_field() ?><input type="hidden" name="action" value="post"><input type="hidden" name="id" value="<?= (int)$doc['id'] ?>">
          <button class="op-btn go" type="submit">Post to stock</button>
        </form>
      <?php else: ?>
        <span class="op-note info" style="margin:0">Someone with posting permission must post it before stock changes.</span>
      <?php endif; ?>
      <?php if ($canEdit): ?>
        <form method="post" onsubmit="return confirm('Delete this draft? Nothing has moved, so there is nothing to undo.');">
          <?= csrf_field() ?><input type="hidden" name="action" value="delete"><input type="hidden" name="id" value="<?= (int)$doc['id'] ?>">
          <button class="op-btn sec" type="submit" style="color:#c0293f">Delete draft</button>
        </form>
      <?php endif; ?>
    <?php elseif ($doc['status'] === 'posted' && $canRev): ?>
      <form method="post" onsubmit="var r=prompt('Why is this opening stock being reversed?'); if(!r){return false;} this.reason.value=r; return true;">
        <?= csrf_field() ?><input type="hidden" name="action" value="reverse">
        <input type="hidden" name="id" value="<?= (int)$doc['id'] ?>"><input type="hidden" name="reason" value="">
        <button class="op-btn warn" type="submit">Reverse</button>
      </form>
    <?php endif; ?>
    <a class="op-btn sec" href="inv_opening.php">Back to register</a>
    <?php if ($doc['status'] === 'posted'): ?>
      <a class="op-btn sec" href="inv_ledger.php">See it in the Stock Ledger</a>
    <?php endif; ?>
  </div>
</div>

<?php /* ============================ the register ============================ */ ?>
<?php else: ?>
<div class="op-card">
  <div style="display:flex;justify-content:space-between;gap:12px;flex-wrap:wrap;align-items:center;margin-bottom:14px">
    <h2 style="font-size:15.5px;margin:0;font-weight:800">Opening stock documents</h2>
    <?php if ($canEdit): ?><a class="op-btn" href="inv_opening.php?new=1">+ New opening stock</a><?php endif; ?>
  </div>

  <?php if (!$register): ?>
    <div class="zempty"><h4>No opening stock has been entered</h4>
      Until one is, every balance in the system comes from a posted gate pass.
      <?php if ($canEdit): ?><br><a href="inv_opening.php?new=1" style="color:#0b5f8a;font-weight:700">Enter the first one</a><?php endif; ?>
    </div>
  <?php else: ?>
    <div style="overflow-x:auto"><table class="op-tbl">
      <thead><tr><th>Number</th><th>Date</th><th>Store</th><th class="r">Lines</th>
        <th class="r">Quantity</th><th class="r">Value</th><th>Status</th><th></th></tr></thead>
      <tbody>
      <?php foreach ($register as $r): ?>
        <tr>
          <td style="font-family:monospace;font-weight:700"><?= e($r['opening_no']) ?></td>
          <td><?= e($r['opening_date']) ?></td>
          <td><?= e($r['locname'] ?: '—') ?></td>
          <td class="r"><?= (int)$r['lines'] ?></td>
          <td class="r"><?= number_format((float)$r['tqty'], 3) ?></td>
          <td class="r"><?= number_format((float)$r['tval'], 2) ?></td>
          <td><span class="op-pill s-<?= e($r['status']) ?>"><?= e(ucfirst($r['status'])) ?></span></td>
          <td style="text-align:right"><a class="op-btn sec" style="padding:5px 10px;font-size:11.5px" href="?id=<?= (int)$r['id'] ?>">Open</a></td>
        </tr>
      <?php endforeach; ?>
      </tbody>
    </table></div>
    <div class="op-note info" style="margin:16px 0 0">Only <b>posted</b> documents affect stock. Drafts stay here so nothing is lost, but they are excluded from every quantity in the system.</div>
  <?php endif; ?>
</div>
<?php endif; ?>

</div><?php /* closes .zskin */ ?>
<?php page_footer(); ?>
