<?php
/* Store Issue & Return — the middle stage.

   These move stock between LOCATIONS only: same item, same quantity,
   different place, no cost effect and no conversion. Consumption remains
   the only document that turns material into a finished product.

   The Floor Balance tab is the reason this exists: issued minus consumed
   minus returned shows what is genuinely sitting on a floor, which is
   invisible if material never leaves the store on paper. */
require_once __DIR__ . '/includes/bootstrap.php';
require_login();
require_once __DIR__ . '/includes/inventory.php';
inv_ensure_schema();
if (!inv_can_see()) { http_response_code(403); exit('You do not have permission to view store movements.'); }

$canEdit = inv_perm('store');
$canPost = inv_perm('post');
$canRev  = inv_perm('adjust');

$type = ($_GET['type'] ?? $_POST['move_type'] ?? 'issue') === 'return' ? 'return' : 'issue';
$tab  = $_GET['tab'] ?? '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verify_csrf();
    $act = $_POST['action'] ?? '';

    if ($act === 'save') {
        if (!$canEdit) { http_response_code(403); exit('You do not have permission to issue or return material.'); }
        $id = (int)($_POST['id'] ?? 0);
        $date = ($_POST['move_date'] ?? '') !== '' ? $_POST['move_date'] : date('Y-m-d');
        if ($date > date('Y-m-d')) { $_SESSION['error'] = 'The date cannot be in the future.'; redirect('inv_store.php?type=' . $type); }
        $limit = (int)inv_setting('backdate_days', '7');
        if ($limit > 0 && !is_admin() && $date < date('Y-m-d', strtotime("-$limit days"))) {
            $_SESSION['error'] = "This date is more than $limit days back. Ask an admin to enter it.";
            redirect('inv_store.php?type=' . $type);
        }
        $no = strtoupper(trim((string)($_POST['move_no'] ?? '')));
        if ($no === '' && $id > 0) {
            try { $s = db()->prepare("SELECT move_no FROM inv_store_move WHERE id=?"); $s->execute([$id]); $no = (string)$s->fetchColumn(); } catch (Throwable $e) {}
        }
        if ($no === '') $no = inv_next_no($type === 'issue' ? 'prefix_issue' : 'prefix_return', 'inv_store_move', 'move_no');

        $f = [
            'move_no' => $no, 'move_type' => $type, 'move_date' => $date,
            'from_location_id' => (int)($_POST['from_location_id'] ?? 0) ?: null,
            'to_location_id'   => (int)($_POST['to_location_id'] ?? 0) ?: null,
            'proforma_id'      => (int)($_POST['proforma_id'] ?? 0) ?: null,
            'against_move_id'  => (int)($_POST['against_move_id'] ?? 0) ?: null,
            'department'  => trim((string)($_POST['department'] ?? '')) ?: null,
            'issued_by'   => trim((string)($_POST['issued_by'] ?? '')) ?: null,
            'received_by' => trim((string)($_POST['received_by'] ?? '')) ?: null,
            'remarks'     => trim((string)($_POST['remarks'] ?? '')) ?: null,
        ];
        try {
            if ($id > 0) {
                $s = db()->prepare("SELECT status FROM inv_store_move WHERE id=?"); $s->execute([$id]);
                if (in_array($s->fetchColumn(), ['posted', 'reversed'], true)) {
                    $_SESSION['error'] = 'A posted document cannot be edited. Reverse it and raise a new one.';
                    redirect('inv_store.php?id=' . $id);
                }
            }
            db()->beginTransaction();
            if ($id > 0) {
                $sets = implode(',', array_map(fn($k) => "$k=?", array_keys($f)));
                $vals = array_values($f); $vals[] = $id;
                db()->prepare("UPDATE inv_store_move SET $sets WHERE id=?")->execute($vals);
            } else {
                $cols = implode(',', array_keys($f)) . ',created_by';
                $ph = implode(',', array_fill(0, count($f) + 1, '?'));
                $vals = array_values($f); $vals[] = (int)(current_user()['id'] ?? 0);
                db()->prepare("INSERT INTO inv_store_move ($cols) VALUES ($ph)")->execute($vals);
                $id = (int)db()->lastInsertId();
            }
            db()->prepare("DELETE FROM inv_store_move_items WHERE move_id=?")->execute([$id]);
            $ins = db()->prepare("INSERT INTO inv_store_move_items (move_id,material_id,product_id,size_label,lot_no,qty,uom,condition_note,ownership) VALUES (?,?,?,?,?,?,?,?,?)");
            $n = 0;
            foreach ((array)($_POST['line'] ?? []) as $ln) {
                $qty = inv_num($ln['qty'] ?? 0);
                /* PRODUCTS COULD NEVER BE ISSUED. This loop read
                   material_id only and passed a hard null for product_id,
                   so a finished item could not be moved between floors at
                   all — the column existed and nothing ever filled it.
                   One key now carries the kind with the id. */
                [$mid, $pid] = inv_split_key((string)($ln['item_key'] ?? ''));
                if ($mid <= 0 && $pid <= 0) $mid = (int)($ln['material_id'] ?? 0);
                if ($qty <= 0 || ($mid <= 0 && $pid <= 0)) continue;
                $ins->execute([$id, $mid ?: null, $pid ?: null,
                    $pid > 0 ? (trim((string)($ln['size_label'] ?? '')) ?: null) : null,
                    trim((string)($ln['lot_no'] ?? '')) ?: null, $qty,
                    trim((string)($ln['uom'] ?? '')) ?: null,
                    trim((string)($ln['condition_note'] ?? '')) ?: null,
                    ($ln['ownership'] ?? 'own') === 'customer' ? 'customer' : 'own']);
                $n++;
            }
            db()->commit();
            inv_audit('store_save', $id, ['no' => $no, 'type' => $type, 'lines' => $n], 'Store movement saved');
            $_SESSION['flash'] = $no . ' saved with ' . $n . ' line(s). Nothing has moved yet — post it to apply.';
        } catch (Throwable $ex) {
            if (db()->inTransaction()) db()->rollBack();
            $_SESSION['error'] = 'Could not save.';
        }
        redirect('inv_store.php?id=' . $id);
    }

    if ($act === 'post') {
        if (!$canPost) { http_response_code(403); exit('You do not have permission to post.'); }
        $id = (int)($_POST['id'] ?? 0);
        $r = inv_store_post($id);
        if ($r['ok']) $_SESSION['flash'] = 'Posted. The material has moved between locations.';
        else $_SESSION['error'] = $r['error'];
        redirect('inv_store.php?id=' . $id);
    }

    if ($act === 'reverse') {
        if (!$canRev) { http_response_code(403); exit('Not permitted.'); }
        $id = (int)($_POST['id'] ?? 0);
        $r = inv_doc_reverse('inv_store_move', 'move_no', 'store', $id, (string)($_POST['reason'] ?? ''));
        if ($r['ok']) $_SESSION['flash'] = 'Reversed.'; else $_SESSION['error'] = $r['error'];
        redirect('inv_store.php?id=' . $id);
    }
}

/* ---------------------------------------------------------- open a doc */
$doc = null; $lines = [];
if (!empty($_GET['id'])) {
    try {
        $s = db()->prepare("SELECT m.*, pf.pi_no, a.move_no against_no FROM inv_store_move m
            LEFT JOIN proforma_invoices pf ON pf.id=m.proforma_id
            LEFT JOIN inv_store_move a ON a.id=m.against_move_id WHERE m.id=?");
        $s->execute([(int)$_GET['id']]); $doc = $s->fetch() ?: null;
        if ($doc) {
            $type = $doc['move_type'];
            /* a product line would have shown a blank name: this table
               joined materials only, and nothing ever filled product_id
               before today */
            $s2 = db()->prepare("SELECT i.*, mt.code mcode, mt.name mname, pr.name pname
                FROM inv_store_move_items i
                LEFT JOIN inv_materials mt ON mt.id=i.material_id
                LEFT JOIN products pr ON pr.id=i.product_id
                WHERE i.move_id=? ORDER BY i.id");
            $s2->execute([(int)$doc['id']]); $lines = $s2->fetchAll();
        }
    } catch (Throwable $e) {}
}
$isNew = isset($_GET['new']);
/* Opening a saved movement shows the DOCUMENT, not the editor — that is
   where "Post movement" lives. The editor is one click away on Edit. */
$showForm = $canEdit && ($isNew || ($doc && isset($_GET['edit']) && $doc['status'] === 'draft'));

$materials = ($showForm || $doc) ? inv_materials(true) : [];
/* Materials AND finished products, each with its balance per location.
   The old list was materials only, which is why finished goods showed no
   stock here either. */
$stockItems = ($showForm || $doc) ? inv_stock_items('own') : [];
$locations = inv_locations(true);
$proformas = [];
$openIssues = [];
if ($showForm) {
    try { $proformas = db()->query("SELECT id,pi_no,customer_name FROM proforma_invoices ORDER BY id DESC LIMIT 300")->fetchAll(); } catch (Throwable $e) {}
    if ($type === 'return') {
        try { $openIssues = db()->query("SELECT id,move_no,move_date FROM inv_store_move WHERE move_type='issue' AND status='posted' ORDER BY id DESC LIMIT 100")->fetchAll(); } catch (Throwable $e) {}
    }
}
if ($showForm && !$lines) $lines = [[]];

/* ------------------------------------------------------- floor balance */
$floor = [];
if ($tab === 'bal') {
    try {
        // Issued to, and returned from, each non-store location, against
        // what consumption has taken out of it.
        $floor = db()->query("SELECT l.location_id, m.id mid, m.code, m.name, m.uom,
                COALESCE(SUM(CASE WHEN l.source_type='store' THEN l.qty_in ELSE 0 END),0) issued,
                COALESCE(SUM(CASE WHEN l.source_type='store' THEN l.qty_out ELSE 0 END),0) returned,
                COALESCE(SUM(CASE WHEN l.source_type='consumption' THEN l.qty_out ELSE 0 END),0) consumed,
                COALESCE(SUM(l.qty_in),0)-COALESCE(SUM(l.qty_out),0) bal,
                MAX(l.txn_date) last_move
            FROM inv_stock_ledger l
            JOIN inv_materials m ON m.id=l.material_id
            JOIN inv_locations lo ON lo.id=l.location_id AND lo.kind IN ('floor','other')
            WHERE l.ownership='own'
            GROUP BY l.location_id, m.id
            HAVING ABS(bal) > 0.0005
            ORDER BY bal DESC")->fetchAll();
    } catch (Throwable $e) { $floor = []; }
}

/* ------------------------------------------------------------ register */
$reg = [];
if (!$doc && !$isNew && $tab !== 'bal') {
    try {
        $s = db()->prepare("SELECT m.*, pf.pi_no,
                (SELECT COALESCE(SUM(qty),0) FROM inv_store_move_items WHERE move_id=m.id) tqty,
                (SELECT COUNT(*) FROM inv_store_move_items WHERE move_id=m.id) nlines
            FROM inv_store_move m LEFT JOIN proforma_invoices pf ON pf.id=m.proforma_id
            WHERE m.move_type=? ORDER BY m.id DESC LIMIT 200");
        $s->execute([$type]); $reg = $s->fetchAll();
    } catch (Throwable $e) {}
}

$label = $type === 'issue' ? 'Material Issue' : 'Material Return';
page_header($label);
flash();
?>
<div class="topbar">
  <div><h1>Store Issue &amp; Return</h1><p class="lead">Material moving between the store and a department. Same item, same quantity, different place — never a cost change.</p></div>
  <?php if ($canEdit && !$showForm): ?><a class="zbtn sec" href="?type=<?= e($type) ?>&amp;new=1">+ New <?= e($label) ?></a><?php endif; ?>
</div>

<style>
.iss-card{background:#fff;border:1px solid #e3e9f2;border-radius:16px;padding:20px 22px;margin-bottom:16px;box-shadow:0 10px 30px rgba(15,35,65,.06)}
.iss-inp{padding:9px 11px;border-radius:9px;border:1px solid #cbd5e3;font-size:12.5px;font-family:inherit;width:100%}
.iss-lbl{display:block;font-size:10.5px;color:#8a97ab;font-weight:800;text-transform:uppercase;letter-spacing:.04em;margin-bottom:5px}
.iss-grid{display:grid;gap:13px;grid-template-columns:repeat(4,1fr)}
@media(max-width:1000px){.iss-grid{grid-template-columns:repeat(2,1fr)}}
@media(max-width:640px){.iss-grid{grid-template-columns:1fr}}
.iss-tbl{width:100%;border-collapse:collapse;font-size:12.5px}
.iss-tbl th{text-align:left;font-size:10px;text-transform:uppercase;letter-spacing:.05em;color:#8a97ab;font-weight:800;padding:0 8px 5px;white-space:nowrap}
.iss-tbl td{padding:3px 8px;border-top:1px solid #eef1f7;vertical-align:top}
.iss-tbl td.r,.iss-tbl th.r{text-align:right;font-variant-numeric:tabular-nums}
.iss-btn{padding:9px 16px;border:none;border-radius:10px;background:linear-gradient(100deg,#0ea8c9,#6d5bd0);color:#fff;font-weight:700;font-size:12.5px;cursor:pointer}
.iss-btn.sec{background:#fff;color:#152033;border:1px solid #cbd5e3;text-decoration:none;display:inline-block}
.iss-btn.go{background:linear-gradient(100deg,#16a34a,#0e8a3d)}
.iss-btn.warn{background:linear-gradient(100deg,#e08a06,#c0293f)}
.iss-tabs{display:flex;gap:4px;background:#eef1f6;padding:4px;border-radius:11px;flex-wrap:wrap;margin-bottom:16px}
.iss-tab{padding:7px 13px;border-radius:8px;font-size:12px;font-weight:700;text-decoration:none;color:#5a6b82}
.iss-tab.on{background:#fff;color:#152033;box-shadow:0 1px 3px rgba(20,30,50,.12)}
.iss-pill{display:inline-block;font-size:10px;font-weight:800;padding:3px 8px;border-radius:20px}
.p-draft{background:#f0f3f9;color:#5a6b82}.p-posted{background:rgba(22,163,74,.12);color:#16a34a}
.p-reversed{background:rgba(224,67,93,.12);color:#c0293f}
.iss-note{border-radius:11px;padding:12px 15px;font-size:12.5px;line-height:1.65;margin-bottom:14px}
.iss-note.info{background:rgba(14,168,201,.07);border:1px solid rgba(14,168,201,.2);color:#2c4a63}
.iss-note.ok{background:rgba(22,163,74,.08);border:1px solid rgba(22,163,74,.22);color:#1c5334}
.iss-note.warn{background:rgba(217,119,6,.09);border:1px solid rgba(217,119,6,.25);color:#7a4d09}
.iss-note.bad{background:rgba(224,67,93,.08);border:1px solid rgba(224,67,93,.24);color:#8c2038}
/* .matbox is used by this page's markup but was only ever DEFINED in
   inv_gate.php — a different page, a different <style>, so it has never
   applied here. The item box is positioned by it, so it is stated where
   it is used rather than borrowed from a file that cannot reach it. */
.matbox{position:relative}
/* An issue that takes more than is on the floor. Coloured, not blocked —
   the posting routine is where it is enforced; this is where it is seen
   in time to fix it. */
.iss-tbl td.bal.short{color:#c0293f;font-weight:800}
</style>

<?php /* OPTING IN TO THE SKIN. Every rule in assets/css/zskin.css is scoped
         under .zskin, so this one wrapper is what makes the page compact,
         and deleting it restores the styles above with nothing else to
         undo. It wraps the markup and never the <style>. */ ?>
<div class="zskin">

<?php if (!$doc && !$isNew): ?>
<div class="iss-tabs">
  <a class="iss-tab <?= ($tab !== 'bal' && $type === 'issue') ? 'on' : '' ?>" href="?type=issue">Issues</a>
  <a class="iss-tab <?= ($tab !== 'bal' && $type === 'return') ? 'on' : '' ?>" href="?type=return">Returns</a>
  <a class="iss-tab <?= $tab === 'bal' ? 'on' : '' ?>" href="?tab=bal">Floor balance</a>
</div>
<?php endif; ?>

<?php if ($tab === 'bal' && !$doc && !$isNew): ?>
  <div class="iss-card">
    <h2 style="font-size:15.5px;margin:0 0 4px;font-weight:800">What is out with each department right now</h2>
    <p style="color:#8a97ab;font-size:12px;margin:0 0 14px">Issued, less what consumption has used, less what came back. Anything left is physically on that floor.</p>
    <?php if (!$floor): ?>
      <div class="iss-note info">Nothing is currently out with a department. Balances appear here once an issue has been posted to a floor location.</div>
    <?php else: ?>
    <div style="overflow-x:auto"><table class="iss-tbl">
      <thead><tr><th>Location</th><th>Material</th><th class="r">Issued in</th><th class="r">Consumed</th><th class="r">Returned</th><th class="r">Still on floor</th><th>UOM</th><th>Last move</th><th></th></tr></thead>
      <tbody>
      <?php foreach ($floor as $f):
        $age = $f['last_move'] ? (int)floor((time() - strtotime((string)$f['last_move'])) / 86400) : 0;
        $stale = $age > 14 && (float)$f['bal'] > 0; ?>
        <tr<?= $stale ? ' style="background:rgba(217,119,6,.06)"' : '' ?>>
          <td style="font-weight:600"><?= e(inv_location_name((int)$f['location_id'])) ?></td>
          <td><b><?= e($f['code']) ?></b> · <?= e($f['name']) ?></td>
          <td class="r" style="color:#16a34a"><?= number_format((float)$f['issued'], 2) ?></td>
          <td class="r" style="color:#c0293f"><?= number_format((float)$f['consumed'], 2) ?></td>
          <td class="r"><?= number_format((float)$f['returned'], 2) ?></td>
          <td class="r" style="font-weight:800;font-size:13px;<?= $stale ? 'color:#d97706' : '' ?>"><?= number_format((float)$f['bal'], 2) ?></td>
          <td style="font-family:monospace"><?= e($f['uom']) ?></td>
          <td style="font-size:11.5px;color:#8a97ab"><?= e((string)$f['last_move']) ?> · <?= $age ?>d</td>
          <td style="text-align:right"><?= $stale ? '<span class="iss-pill" style="background:rgba(217,119,6,.14);color:#d97706">Query</span>' : '' ?></td>
        </tr>
      <?php endforeach; ?>
      </tbody>
    </table></div>
    <div class="iss-note warn" style="margin:16px 0 0">Rows highlighted amber have sat on a floor for more than 14 days with nothing consumed or returned against them. Without an issue document that material would simply have read as "in stock" and nobody would have asked.</div>
    <?php endif; ?>
  </div>

<?php elseif ($showForm): $D = $doc ?: []; ?>
<div class="iss-card">
  <h2 style="font-size:15.5px;margin:0 0 4px;font-weight:800"><?= $doc ? 'Edit ' . e($doc['move_no']) : 'New ' . e($label) ?></h2>
  <p style="color:#8a97ab;font-size:12px;margin:0 0 14px">Total company stock does not change — only where it sits.</p>
  <form method="post"><?= csrf_field() ?>
    <input type="hidden" name="action" value="save"><input type="hidden" name="id" value="<?= (int)($D['id'] ?? 0) ?>">
    <input type="hidden" name="move_type" value="<?= e($type) ?>">
    <div class="iss-grid">
      <div><label class="iss-lbl">Document no.</label><input class="iss-inp" name="move_no" value="<?= e($D['move_no'] ?? '') ?>" placeholder="auto" style="font-family:monospace"></div>
      <div><label class="iss-lbl">Date</label><input class="iss-inp" type="date" name="move_date" value="<?= e($D['move_date'] ?? date('Y-m-d')) ?>"></div>
      <div><label class="iss-lbl">From location</label>
        <select class="iss-inp" name="from_location_id">
          <?php $fl = (int)($D['from_location_id'] ?? ($type === 'issue' ? inv_setting('default_location', '1') : 0));
          foreach ($locations as $l): ?><option value="<?= (int)$l['id'] ?>" <?= $fl === (int)$l['id'] ? 'selected' : '' ?>><?= e($l['name']) ?></option><?php endforeach; ?>
        </select></div>
      <div><label class="iss-lbl">To location</label>
        <select class="iss-inp" name="to_location_id">
          <?php $tl = (int)($D['to_location_id'] ?? 0);
          foreach ($locations as $l): ?><option value="<?= (int)$l['id'] ?>" <?= $tl === (int)$l['id'] ? 'selected' : '' ?>><?= e($l['name']) ?></option><?php endforeach; ?>
        </select></div>

      <div style="grid-column:span 2"><label class="iss-lbl">Against order — optional</label>
        <select class="iss-inp" name="proforma_id">
          <option value="0">— none —</option>
          <?php foreach ($proformas as $pf): ?><option value="<?= (int)$pf['id'] ?>" <?= (int)($D['proforma_id'] ?? 0) === (int)$pf['id'] ? 'selected' : '' ?>><?= e($pf['pi_no']) ?> · <?= e($pf['customer_name']) ?></option><?php endforeach; ?>
        </select></div>
      <?php if ($type === 'return'): ?>
      <div><label class="iss-lbl">Against issue</label>
        <select class="iss-inp" name="against_move_id">
          <option value="0">— none —</option>
          <?php foreach ($openIssues as $oi): ?><option value="<?= (int)$oi['id'] ?>" <?= (int)($D['against_move_id'] ?? 0) === (int)$oi['id'] ? 'selected' : '' ?>><?= e($oi['move_no']) ?> · <?= e($oi['move_date']) ?></option><?php endforeach; ?>
        </select></div>
      <?php else: ?>
      <div><label class="iss-lbl">Department</label><input class="iss-inp" name="department" value="<?= e($D['department'] ?? '') ?>"></div>
      <?php endif; ?>
      <div><label class="iss-lbl"><?= $type === 'issue' ? 'Issued by' : 'Returned by' ?></label><input class="iss-inp" name="issued_by" value="<?= e($D['issued_by'] ?? '') ?>"></div>
      <div style="grid-column:span 3"><label class="iss-lbl">Remarks</label><input class="iss-inp" name="remarks" value="<?= e($D['remarks'] ?? '') ?>"></div>
      <div><label class="iss-lbl">Received by</label><input class="iss-inp" name="received_by" value="<?= e($D['received_by'] ?? '') ?>"></div>
    </div>

    <h3 style="font-size:12px;text-transform:uppercase;letter-spacing:.06em;color:#8a97ab;margin:22px 0 10px">Items</h3>
    <div style="overflow-x:auto"><table class="iss-tbl" id="slines">
      <thead><tr><th style="min-width:260px">Material</th><th style="min-width:120px">Lot / roll</th>
        <th class="r" style="width:110px">Quantity</th><th style="width:80px">UOM</th>
        <?php if ($type === 'return'): ?><th style="min-width:150px">Condition</th><?php endif; ?>
        <th class="r" style="width:110px">Stock now</th><th style="width:34px"></th></tr></thead>
      <tbody>
      <?php foreach ($lines as $i => $L): ?>
        <tr>
          <td><div class="matbox">
            <?php /* The same List of Values as Gate passes and
                     Consumption. A store movement only shifts goods
                     between your own floors, so the list is what is at
                     the FROM location — you cannot move what is not
                     there. */ ?>
            <input class="iss-inp matq" type="text" autocomplete="off" spellcheck="false"
                   data-lov="smat" placeholder="click here — the list opens" style="display:none">
            <?php $curKey = (int)($L['material_id'] ?? 0) > 0 ? 'm' . (int)$L['material_id']
                          : ((int)($L['product_id'] ?? 0) > 0 ? 'p' . (int)$L['product_id'] : ''); ?>
            <select class="iss-inp mat" name="line[<?= $i ?>][item_key]">
              <option value="">— select —</option>
              <?php foreach ($stockItems as $m): ?><option value="<?= e($m['key']) ?>" data-uom="<?= e($m['uom']) ?>" data-grp="<?= e($m['grp']) ?>" data-kind="<?= e($m['kind']) ?>" <?= $curKey === $m['key'] ? 'selected' : '' ?>><?= e($m['code']) ?> · <?= e($m['name']) ?></option><?php endforeach; ?>
            </select>
          </div></td>
          <td><input class="iss-inp" name="line[<?= $i ?>][lot_no]" value="<?= e($L['lot_no'] ?? '') ?>" style="font-family:monospace"></td>
          <td><input class="iss-inp qty" name="line[<?= $i ?>][qty]" value="<?= e((string)($L['qty'] ?? '')) ?>" style="text-align:right"></td>
          <td><input class="iss-inp uom" name="line[<?= $i ?>][uom]" value="<?= e($L['uom'] ?? '') ?>" style="font-family:monospace"></td>
          <?php if ($type === 'return'): ?>
          <td><input class="iss-inp" name="line[<?= $i ?>][condition_note]" value="<?= e($L['condition_note'] ?? '') ?>" placeholder="Usable / damaged"></td>
          <?php endif; ?>
          <?php /* "Stock now" WAS RENDERED ONCE AND NEVER CHANGED AGAIN.
                   It was computed here in PHP, at page load, from the
                   location that was on the document when it opened. Pick
                   an item, change the From location, add a line — the
                   number stayed exactly as it was. On a new line it read
                   "—" for as long as the form was open, which is the
                   moment the operator most needs it.

                   The whole stock map is already in the browser for the
                   picker, so it is now filled in by the same JavaScript
                   the picker uses. No extra query, and it moves when the
                   form moves. */ ?>
          <td class="r bal">—</td>
          <td><button type="button" class="iss-btn sec del" style="padding:3px 7px;cursor:pointer">×</button></td>
        </tr>
      <?php endforeach; ?>
      </tbody>
      <?php /* Material, Lot, Quantity, UOM, [Condition], Stock now, ×
               — 6 columns on an issue, 7 on a return. The footer used a
               flat colspan of 4 after the quantity, which adds up to 7
               either way, so on every ISSUE the total row was one cell
               wider than the table and the figures sat under the wrong
               headings. It is counted from the type now, like the header
               above it. */
        $sCols = $type === 'return' ? 7 : 6; ?>
      <tfoot><tr><td colspan="2" style="text-align:right;font-weight:800">Total</td>
        <td class="r" id="sqty" style="font-weight:800">0</td>
        <td colspan="<?= $sCols - 3 ?>"></td></tr></tfoot>
    </table></div>

    <?php /* One strip for the whole table. A per-line message would push
             every row down the moment it appeared. */ ?>
    <div id="sWarn" class="iss-note warn" style="margin-top:12px;display:none"></div>

    <div style="margin-top:14px;display:flex;gap:10px;flex-wrap:wrap;align-items:center">
      <button type="button" class="iss-btn sec" id="adds">+ Add line</button>
      <button class="iss-btn" type="submit">Save</button>
      <a class="iss-btn sec" href="inv_store.php?type=<?= e($type) ?>">Back</a>
    </div>
  </form>
</div>
<link rel="stylesheet" href="assets/css/lov.css?v=2">
<script src="assets/js/lov.js?v=2"></script>
<script>
(function(){
  var tb=document.querySelector('#slines tbody');
  var IS_ISSUE = <?= $type === 'issue' ? 'true' : 'false' ?>;

  function q3(v){ return Number(v).toLocaleString('en-US',{maximumFractionDigits:3}); }

  /* ONE PASS THAT PAINTS EVERYTHING: the total, the live balance on each
     line, and the over-issue strip. They all read the same two things —
     the item on the line and the From location — so computing them apart
     is how they end up disagreeing. */
  function tot(){
    var t=0, over=[];
    [].slice.call(tb.children).forEach(function(tr,i){
      var q=parseFloat((tr.querySelector('.qty')||{}).value)||0;
      t+=q;
      var sel=tr.querySelector('select.mat'), id=sel?sel.value:'';
      var cell=tr.querySelector('.bal'); if(!cell) return;
      if(!id){ cell.textContent='—'; cell.classList.remove('short'); return; }
      var b=balOf(id);
      cell.textContent=q3(b);
      /* A return ADDS to the source floor, so it can never overdraw it.
         Only an issue can, and only then is the check meaningful. */
      var short = IS_ISSUE && q > b + 0.0005;
      cell.classList.toggle('short', short);
      if(short){
        var nm=(tr.querySelector('.matq')||{}).value||'that item';
        over.push('line '+(i+1)+' — '+nm.split(' · ')[0]+' has '+q3(b)+' at '+fromName()
                 +' and this document takes '+q3(q));
      }
    });
    document.getElementById('sqty').textContent=t.toLocaleString(undefined,{maximumFractionDigits:3});

    /* SAID HERE, NOT AT POST. The posting routine already refuses an
       over-issue — but it does so after the operator has typed fourteen
       lines and pressed a button, and it names the item rather than the
       line. Showing it while they type is the whole difference between a
       form that helps and a form that argues. */
    var w=document.getElementById('sWarn'); if(!w) return;
    if(!over.length){ w.style.display='none'; w.innerHTML=''; return; }
    w.style.display='';
    w.innerHTML='<b>'+over.length+' line(s) take more than is at '+fromName()+'.</b> '
      +'Posting will stop unless an admin allows it, so it is worth fixing now rather than after saving.<br>'
      +over.join('<br>');
  }
  tb.addEventListener('input',tot);
  tb.addEventListener('change',function(e){
    var s=e.target; if(s.tagName!=='SELECT') return;
    var o=s.options[s.selectedIndex];
    if(o&&o.dataset.uom){
      var u=s.closest('tr').querySelector('.uom'); if(u&&!u.value) u.value=o.dataset.uom;
    }
    tot();
  });
  /* Changing the From location changes what "Stock now" means on EVERY
     line, not just the one being edited. */
  var fromSel=document.querySelector('select[name="from_location_id"]');
  if(fromSel) fromSel.addEventListener('change', tot);

  /* ---- the List of Values ------------------------------------------
     Shared with Gate passes and Consumption — see assets/js/lov.js. A
     store movement never creates or destroys stock, it only changes
     where stock is, so the only question the list has to answer is
     "what is at the place it is leaving". */
  /* MATERIALS AND FINISHED PRODUCTS, each carrying its own balance per
     location. This used to be a materials-only stock map plus a list
     scraped out of the select's option text, so a finished product with a
     real balance in the ledger could not be issued or even seen. */
  var ITEMS = <?= json_encode($stockItems ?: [], JSON_UNESCAPED_UNICODE) ?>;
  var KINDN = {grey:'Grey', raw:'Raw', finished:'Finished', product:'Product', na:''};
  function itemByKey(k){
    for(var i=0;i<ITEMS.length;i++) if(ITEMS[i].key===k) return ITEMS[i];
    return null;
  }
  function fromLoc(){ var s=document.querySelector('select[name="from_location_id"]'); return s?+s.value:0; }
  function fromName(){
    var s=document.querySelector('select[name="from_location_id"]');
    return s&&s.selectedIndex>=0 ? s.options[s.selectedIndex].text : 'that location';
  }
  function balOf(k){
    var it = (typeof k === 'string' || typeof k === 'number') ? itemByKey(String(k)) : k;
    if(!it) return 0;
    var l=fromLoc(), m=it.bal||{}, v=l>0?m[l]:m[0];
    return v===undefined?0:v;
  }

  LOV.register('smat',{
    cols:[
      {label:'Code',        w:'86px',             cls:'cd', get:function(r,q){return LOV.hl(r.it.code,q);}},
      {label:'Description', w:'minmax(140px,1fr)',cls:'nm', get:function(r,q){return LOV.hl(r.it.name,q);}},
      {label:'Kind',        w:'70px',             cls:'gg',
        get:function(r){return LOV.esc(KINDN[r.it.stage]||r.it.grp);}},
      {label:'At '+'source',w:'80px', align:'r',  cls:'nu',
        style:function(r){return 'font-weight:700;color:'+(r.bal>0?'#16a34a':'#c0293f');},
        get:function(r){return LOV.q3(r.bal);}},
      {label:'UOM',         w:'46px',             cls:'gg', get:function(r){return LOV.esc(r.it.uom);}}
    ],
    moreLabel:'not at that location',
    lessLabel:'only what is there',
    title:function(){ return 'Select material — at ' + fromName(); },
    empty:function(f,q){ return q ? 'Nothing matching is at ' + fromName() + '.'
                                  : 'Nothing is at ' + fromName() + '.'; },
    rows:function(f,q,showAll,cb){
      var out=[], hidden=0;
      ITEMS.forEach(function(it){
        var sc=LOV.score(q,it.code,it.name,it.grp); if(sc<=0) return;
        var b=balOf(it);
        if(!(b>0.0005)){ if(!showAll){ hidden++; return; } }
        out.push({it:it,bal:b,sc:sc});
      });
      out.sort(function(a,b){ return a.sc!==b.sc ? b.sc-a.sc : a.it.code.localeCompare(b.it.code); });
      cb(out,hidden);
    },
    revert:function(f){ var box=f.closest('.matbox'); if(box) sync(box); },
    pick:function(f,r){
      var tr=f.closest('tr'), sel=tr.querySelector('select.mat');
      sel.value=r.it.key; f.value=r.it.code+' · '+r.it.name;
      sel.dispatchEvent(new Event('change',{bubbles:true}));
      var q=tr.querySelector('.qty'); if(q) q.focus();
    }
  });
  function sync(box){
    var sel=box.querySelector('select.mat'), q=box.querySelector('.matq');
    if(!sel||!q) return;
    sel.style.display='none'; q.style.display='';
    q.value=(sel.value&&sel.options[sel.selectedIndex])
          ? sel.options[sel.selectedIndex].text : '';
  }
  function syncAll(){ tb.querySelectorAll('.matbox').forEach(sync); }
  LOV.attach(tb);
  var fl=document.querySelector('select[name="from_location_id"]');
  if(fl) fl.addEventListener('change', LOV.close);
  syncAll();
  tb.addEventListener('click',function(e){ if(!e.target.classList.contains('del'))return;
    if(tb.children.length>1) e.target.closest('tr').remove(); else tb.querySelectorAll('input').forEach(function(i){i.value='';}); tot(); });
  function addLine(){
    var n=tb.children.length,c=tb.lastElementChild.cloneNode(true);
    c.querySelectorAll('input,select').forEach(function(el){el.name=el.name.replace(/line\[\d+\]/,'line['+n+']');
      if(el.tagName==='INPUT') el.value=''; else el.selectedIndex=0;});
    /* The balance cell and its warning colour are not form values, so the
       loop above leaves them alone and a new line would inherit the last
       line's figure. */
    var b=c.querySelector('.bal'); if(b){ b.textContent='—'; b.classList.remove('short'); }
    tb.appendChild(c); syncAll(); tot();
    return c;
  }
  document.getElementById('adds').addEventListener('click',function(){
    var c=addLine();
    var f=c.querySelector('.matq');          // the LOV field, not the hidden select
    if(!f||f.style.display==='none') f=c.querySelector('select');
    if(f) f.focus();
  });
  /* Enter on Quantity starts the next line. The picker already sends the
     cursor from the item to the quantity, so with this the whole document
     is typed without the mouse — which is the difference between a form
     somebody tolerates and one they can work in all day. */
  tb.addEventListener('keydown',function(e){
    if(e.key!=='Enter'||!e.target.classList.contains('qty')) return;
    e.preventDefault();
    var tr=e.target.closest('tr');
    if(tr===tb.lastElementChild) addLine();
    var f=tr.nextElementSibling.querySelector('.matq');
    if(!f||f.style.display==='none') f=tr.nextElementSibling.querySelector('select.mat');
    if(f) f.focus();
  });
  tot();
})();
</script>

<?php elseif ($doc): ?>
<div class="iss-card">
  <div style="display:flex;justify-content:space-between;gap:14px;flex-wrap:wrap;align-items:flex-start">
    <div><h2 style="font-size:17px;margin:0;font-weight:800;font-family:monospace"><?= e($doc['move_no']) ?></h2>
      <p style="color:#8a97ab;font-size:12.5px;margin:5px 0 0"><?= e(ucfirst($doc['move_type'])) ?> · <?= e($doc['move_date']) ?> ·
        <?= e(inv_location_name((int)$doc['from_location_id'])) ?> → <?= e(inv_location_name((int)$doc['to_location_id'])) ?></p></div>
    <div style="display:flex;gap:8px;align-items:center">
      <span class="iss-pill p-<?= e($doc['status']) ?>"><?= e(ucfirst($doc['status'])) ?></span>
      <?php if ($canEdit && $doc['status'] === 'draft'): ?><a class="iss-btn sec" href="?id=<?= (int)$doc['id'] ?>&amp;edit=1">Edit</a><?php endif; ?>
      <a class="iss-btn sec" href="inv_store.php?type=<?= e($doc['move_type']) ?>">Register</a>
    </div>
  </div>

  <div style="overflow-x:auto;margin-top:16px"><table class="iss-tbl">
    <thead><tr><th>Material</th><th>Lot</th><th class="r">Quantity</th><th>UOM</th><th>Condition</th></tr></thead>
    <tbody>
    <?php $t = 0; foreach ($lines as $L): $t += (float)$L['qty']; ?>
      <?php /* a product line has no material code, so it shows its own
               name rather than " · " with nothing either side of it */ ?>
      <tr><td style="font-weight:600"><?= e((int)($L['material_id'] ?? 0) > 0
            ? trim(($L['mcode'] ?? '') . ' · ' . ($L['mname'] ?? ''))
            : ($L['pname'] ?: '—')) ?><?= !empty($L['size_label']) ? ' · ' . e($L['size_label']) : '' ?></td>
        <td style="font-family:monospace"><?= e($L['lot_no'] ?: '—') ?></td>
        <td class="r"><b><?= number_format((float)$L['qty'], 3) ?></b></td>
        <td style="font-family:monospace"><?= e($L['uom'] ?: '') ?></td>
        <td><?= e($L['condition_note'] ?: '—') ?></td></tr>
    <?php endforeach; ?>
    </tbody>
    <tfoot><tr><td colspan="2" style="text-align:right;font-weight:800">Total</td><td class="r" style="font-weight:800"><?= number_format($t, 3) ?></td><td colspan="2"></td></tr></tfoot>
  </table></div>

  <div style="margin-top:18px;display:flex;gap:10px;flex-wrap:wrap;align-items:center">
    <?php if ($doc['status'] === 'draft'): ?>
      <?php if ($canPost): ?>
      <form method="post" onsubmit="return confirm('Post this movement?');"><?= csrf_field() ?>
        <input type="hidden" name="action" value="post"><input type="hidden" name="id" value="<?= (int)$doc['id'] ?>">
        <button class="iss-btn go" type="submit">Post movement</button></form>
      <?php else: ?><span class="iss-note info" style="margin:0">Saved as draft. Someone with posting permission must post it.</span><?php endif; ?>
    <?php elseif ($doc['status'] === 'posted'): ?>
      <div class="iss-note ok" style="margin:0;flex:1">Posted <?= e((string)$doc['posted_at']) ?>. The material has moved between locations.</div>
      <?php if ($canRev): ?>
      <form method="post" onsubmit="return this.reason.value.trim()!==''||(alert('A reason is required.'),false);" style="display:flex;gap:8px;align-items:center">
        <?= csrf_field() ?><input type="hidden" name="action" value="reverse"><input type="hidden" name="id" value="<?= (int)$doc['id'] ?>">
        <input class="iss-inp" name="reason" placeholder="Reason" style="width:220px">
        <button class="iss-btn warn" type="submit">Reverse</button></form>
      <?php endif; ?>
    <?php else: ?>
      <div class="iss-note bad" style="margin:0">Reversed.</div>
    <?php endif; ?>
  </div>
</div>

<?php else: ?>
<div class="iss-card">
  <?php if (!$reg): ?>
    <p style="color:#8a97ab;font-size:13px;padding:26px 0;text-align:center">No <?= e(strtolower($label)) ?> documents yet.
      <?php if ($canEdit): ?><br><br><a class="iss-btn sec" href="?type=<?= e($type) ?>&amp;new=1">+ New</a><?php endif; ?></p>
  <?php else: ?>
  <div style="overflow-x:auto"><table class="iss-tbl">
    <thead><tr><th>No.</th><th>Date</th><th>From → To</th><th>Order</th><th class="r">Qty</th><th>Status</th><th></th></tr></thead>
    <tbody>
    <?php foreach ($reg as $r): ?>
      <tr<?= $r['status'] === 'reversed' ? ' style="opacity:.6"' : '' ?>>
        <td style="font-family:monospace;font-weight:700"><?= e($r['move_no']) ?></td>
        <td><?= e($r['move_date']) ?></td>
        <td style="font-size:11.5px"><?= e(inv_location_name((int)$r['from_location_id'])) ?> → <?= e(inv_location_name((int)$r['to_location_id'])) ?></td>
        <td style="font-size:11.5px"><?= e($r['pi_no'] ?: '—') ?></td>
        <td class="r"><?= number_format((float)$r['tqty'], 2) ?><br><span style="font-size:11px;color:#8a97ab"><?= (int)$r['nlines'] ?> line(s)</span></td>
        <td><span class="iss-pill p-<?= e($r['status']) ?>"><?= e(ucfirst($r['status'])) ?></span></td>
        <td style="text-align:right"><a class="iss-btn sec" style="padding:5px 10px;font-size:11.5px" href="?id=<?= (int)$r['id'] ?>">Open</a></td>
      </tr>
    <?php endforeach; ?>
    </tbody>
  </table></div>
  <?php endif; ?>
</div>
<?php endif; ?>
</div><?php /* closes .zskin */ ?>
<?php page_footer(); ?>
