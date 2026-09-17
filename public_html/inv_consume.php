<?php
/* Consumption / Production — THE CONVERTER.

   This is the only document in the entire system that creates finished
   product stock. Materials go out and the product comes in, in one
   posting, so the two halves can never drift apart and there is exactly
   one door into finished goods.

   The production module is not consulted and not touched. Your stage
   entries record who did the work and what wage is owed; they never move
   stock, whatever they are named. */
require_once __DIR__ . '/includes/bootstrap.php';
require_login();
require_once __DIR__ . '/includes/inventory.php';
// for production_resolve_product() — the existing fuzzy matcher that turns a
// proforma line's product NAME into a Product Master id. Reused, never re-written.
require_once __DIR__ . '/includes/production.php';
inv_ensure_schema();
if (!inv_can_see()) { http_response_code(403); exit('You do not have permission to view consumption.'); }

$canEdit = inv_perm('consume');
$canPost = inv_perm('post');
$canRev  = inv_perm('adjust');

/* ------------------------------------------------- live lookups (JSON)
   The form asks for lot balances and FIFO allocations as you work, so
   the numbers on screen are the ledger as it stands now rather than as
   it stood when the page was opened. Read-only — nothing here writes. */
if (($_GET['ajax'] ?? '') !== '') {
    header('Content-Type: application/json');
    $a = $_GET['ajax'];

    if ($a === 'lots') {
        $mid = (int)($_GET['material_id'] ?? 0);
        $skip = inv_fifo_excluded_locations();
        $rows = [];
        foreach (inv_lot_balances($mid, empty($_GET['all'])) as $l) {
            $rows[] = $l + ['fifo_ok' => $l['ownership'] === 'own' && !in_array($l['location_id'], $skip, true)];
        }
        echo json_encode(['ok' => true, 'lots' => $rows, 'tolerance_pct' => inv_neg_tolerance_pct(), 'is_admin' => is_admin()]);
        exit;
    }

    if ($a === 'fifo') {
        $mid  = (int)($_GET['material_id'] ?? 0);
        $want = inv_num($_GET['qty'] ?? 0);
        echo json_encode(['ok' => true] + inv_fifo_allocate($mid, $want));
        exit;
    }

    /* Everything the cascade needs for one proforma: its own order lines,
       and for each the materials that product's costing actually uses. */
    if ($a === 'order') {
        $pfId = (int)($_GET['proforma_id'] ?? 0);
        $lines = [];
        if ($pfId > 0) {
            try {
                $s = db()->prepare("SELECT pi.id, pi.product_name, pi.product_id, pi.size, pi.qty
                    FROM proforma_items pi JOIN proforma_invoices pf ON pf.id = pi.proforma_id
                    WHERE pi.proforma_id = ? AND pf.production_enabled = 1 ORDER BY pi.id");
                $s->execute([$pfId]);
                foreach ($s->fetchAll() as $r) {
                    // the SAME resolver the production module uses, so the two
                    // can never disagree about what an order line is
                    $pid = function_exists('production_resolve_item') ? production_resolve_item($r) : 0;
                    $std = $pid ? inv_std_materials((int)$pid) : [];
                    $mats = [];
                    foreach ($std as $sm) if ((int)$sm['material_id'] > 0) $mats[(int)$sm['material_id']] = (float)$sm['per_piece'];
                    $prod = inv_line_production((int)$r['id']);
                    // only lines the floor has actually started — a line
                    // nobody has cut cannot have produced anything
                    if (!$prod['started']) continue;
                    $lines[] = [
                        'item_id' => (int)$r['id'], 'product_name' => $r['product_name'],
                        'size' => (string)$r['size'], 'ordered' => (float)$r['qty'],
                        'product_id' => (int)$pid, 'materials' => $mats,
                        'unresolved' => $pid ? false : true,
                        'prod' => $prod,
                    ];
                }
            } catch (Throwable $e) {}
        }
        echo json_encode(['ok' => true, 'lines' => $lines,
            'quoted' => inv_quoted_cost($pfId), 'tolerance_pct' => inv_cost_variance_pct()]);
        exit;
    }

    echo json_encode(['ok' => false, 'error' => 'Unknown request.']);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verify_csrf();
    $act = $_POST['action'] ?? '';

    if ($act === 'save') {
        if (!$canEdit) { http_response_code(403); exit('You do not have permission to record consumption.'); }
        $id = (int)($_POST['id'] ?? 0);
        $date = ($_POST['con_date'] ?? '') !== '' ? $_POST['con_date'] : date('Y-m-d');
        if ($date > date('Y-m-d')) { $_SESSION['error'] = 'The date cannot be in the future.'; redirect('inv_consume.php'); }
        $limit = (int)inv_setting('backdate_days', '7');
        if ($limit > 0 && !is_admin() && $date < date('Y-m-d', strtotime("-$limit days"))) {
            $_SESSION['error'] = "This date is more than $limit days back. Ask an admin to enter it."; redirect('inv_consume.php');
        }
        $no = strtoupper(trim((string)($_POST['con_no'] ?? '')));
        if ($no === '' && $id > 0) { try { $s = db()->prepare("SELECT con_no FROM inv_consumption WHERE id=?"); $s->execute([$id]); $no = (string)$s->fetchColumn(); } catch (Throwable $e) {} }
        if ($no === '') $no = inv_next_no('prefix_consumption', 'inv_consumption', 'con_no');

        $jwId = (int)($_POST['jobwork_contract_id'] ?? 0);
        /* Inbound job work means the customer owns both the material we
           consume and the goods we produce, so neither side is valued. */
        $own = 'own';
        if ($jwId > 0) {
            try { $s = db()->prepare("SELECT contract_type FROM inv_contracts WHERE id=?"); $s->execute([$jwId]);
                if ($s->fetchColumn() === 'jobwork_in') $own = 'customer'; } catch (Throwable $e) {}
        }

        $f = [
            'con_no' => $no, 'con_date' => $date,
            // the cost review, computed in the browser and re-checked at post
            'quoted_cost_per_unit' => inv_num($_POST['quoted_cpu'] ?? 0) ?: null,
            'actual_cost_per_unit' => inv_num($_POST['actual_cpu'] ?? 0) ?: null,
            'cost_variance_pct'    => ($_POST['variance_pct'] ?? '') !== '' ? inv_num($_POST['variance_pct']) : null,
            'cost_variance_reason' => mb_substr(trim((string)($_POST['variance_reason'] ?? '')), 0, 400) ?: null,
            'proforma_id' => (int)($_POST['proforma_id'] ?? 0) ?: null,
            'jobwork_contract_id' => $jwId ?: null,
            'location_id' => (int)($_POST['location_id'] ?? 0) ?: null,
            'department' => trim((string)($_POST['department'] ?? '')) ?: null,
            'batch_ref'  => trim((string)($_POST['batch_ref'] ?? '')) ?: null,
            'remarks'    => trim((string)($_POST['remarks'] ?? '')) ?: null,
        ];
        try {
            if ($id > 0) {
                $s = db()->prepare("SELECT status FROM inv_consumption WHERE id=?"); $s->execute([$id]);
                if (in_array($s->fetchColumn(), ['posted','reversed'], true)) {
                    $_SESSION['error'] = 'A posted document cannot be edited. Reverse it and raise a new one.';
                    redirect('inv_consume.php?id=' . $id);
                }
            }
            db()->beginTransaction();
            if ($id > 0) {
                $sets = implode(',', array_map(fn($k) => "$k=?", array_keys($f)));
                $vals = array_values($f); $vals[] = $id;
                db()->prepare("UPDATE inv_consumption SET $sets WHERE id=?")->execute($vals);
            } else {
                $cols = implode(',', array_keys($f)) . ',created_by';
                $ph = implode(',', array_fill(0, count($f) + 1, '?'));
                $vals = array_values($f); $vals[] = (int)(current_user()['id'] ?? 0);
                db()->prepare("INSERT INTO inv_consumption ($cols) VALUES ($ph)")->execute($vals);
                $id = (int)db()->lastInsertId();
            }
            db()->prepare("DELETE FROM inv_consumption_items WHERE con_id=?")->execute([$id]);
            $ins = db()->prepare("INSERT INTO inv_consumption_items
                (con_id,side,material_id,product_id,size_label,lot_no,std_qty,qty,waste_qty,uom,rate,amount,ownership,sort_order,
                 location_id,pick_mode,off_standard,short_reason,proforma_item_id)
                VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)");
            $nIn = 0; $nOut = 0;
            foreach ((array)($_POST['inp'] ?? []) as $ln) {
                $qty = inv_num($ln['qty'] ?? 0); $mid = (int)($ln['material_id'] ?? 0);
                if ($qty <= 0 || $mid <= 0) continue;
                $rate = inv_num($ln['rate'] ?? 0);
                /* A line's ownership follows the LOT it was taken from — a
                   customer's material stays the customer's even on an
                   otherwise ordinary document, so it is never valued. */
                $lineOwn = ($ln['ownership'] ?? '') === 'customer' ? 'customer' : $own;
                $ins->execute([$id, 'input', $mid, null, null, trim((string)($ln['lot_no'] ?? '')) ?: null,
                    inv_num($ln['std_qty'] ?? 0), $qty, inv_num($ln['waste_qty'] ?? 0),
                    trim((string)($ln['uom'] ?? '')) ?: null, $rate,
                    $lineOwn === 'customer' ? 0 : round($qty * $rate, 2), $lineOwn, $nIn++,
                    (int)($ln['location_id'] ?? 0) ?: null,
                    ($ln['pick_mode'] ?? '') === 'manual' ? 'manual' : 'fifo',
                    !empty($ln['off_standard']) ? 1 : 0,
                    mb_substr(trim((string)($ln['short_reason'] ?? '')), 0, 300) ?: null,
                    null]);
            }
            foreach ((array)($_POST['out'] ?? []) as $ln) {
                $qty = inv_num($ln['qty'] ?? 0); $pid = (int)($ln['product_id'] ?? 0);
                if ($qty <= 0 || $pid <= 0) continue;
                $rate = inv_num($ln['rate'] ?? 0);
                $ins->execute([$id, 'output', null, $pid, trim((string)($ln['size_label'] ?? '')) ?: null, null,
                    0, $qty, inv_num($ln['waste_qty'] ?? 0),
                    trim((string)($ln['uom'] ?? '')) ?: null, $rate, round($qty * $rate, 2), $own, $nOut++,
                    null, null, 0, null,
                    (int)($ln['proforma_item_id'] ?? 0) ?: null]);
            }
            db()->commit();
            inv_audit('consumption_save', $id, ['no' => $no, 'in' => $nIn, 'out' => $nOut], 'Consumption saved');
            $_SESSION['flash'] = $no . ' saved — ' . $nIn . ' material(s) in, ' . $nOut . ' product(s) out. Post it to move stock.';
        } catch (Throwable $ex) {
            if (db()->inTransaction()) db()->rollBack();
            $_SESSION['error'] = 'Could not save.';
        }
        redirect('inv_consume.php?id=' . $id);
    }

    if ($act === 'post') {
        if (!$canPost) { http_response_code(403); exit('Not permitted.'); }
        $id = (int)($_POST['id'] ?? 0);
        $r = inv_consumption_post($id);
        if ($r['ok']) $_SESSION['flash'] = 'Posted. Materials deducted and finished product added.';
        else $_SESSION['error'] = $r['error'];
        redirect('inv_consume.php?id=' . $id);
    }
    if ($act === 'reverse') {
        if (!$canRev) { http_response_code(403); exit('Not permitted.'); }
        $id = (int)($_POST['id'] ?? 0);
        $reason = (string)($_POST['reason'] ?? '');
        if (!empty($_POST['reopen'])) {
            $r = inv_consumption_reverse_to_draft($id, $reason);
            if ($r['ok'] && !empty($r['new_id'])) {
                $_SESSION['flash'] = 'Reversed. ' . $r['new_no'] . ' opened as a new draft with all '
                    . (int)$r['lines'] . ' line(s) copied — change what was wrong and post it.';
                if (!empty($r['warn'])) $_SESSION['error'] = $r['warn'];
                redirect('inv_consume.php?id=' . (int)$r['new_id'] . '&edit=1');
            }
            if ($r['ok'] && !empty($r['warn'])) $_SESSION['error'] = $r['warn'];
        } else {
            $r = inv_doc_reverse('inv_consumption', 'con_no', 'consumption', $id, $reason);
        }
        if ($r['ok']) $_SESSION['flash'] = 'Reversed.'; else $_SESSION['error'] = $r['error'];
        redirect('inv_consume.php?id=' . $id);
    }
}

/* ------------------------------------------------------------ open doc */
$doc = null; $inLines = []; $outLines = [];
if (!empty($_GET['id'])) {
    try {
        $s = db()->prepare("SELECT c.*, pf.pi_no, pf.customer_name, ct.contract_no, ct.contract_type
            FROM inv_consumption c
            LEFT JOIN proforma_invoices pf ON pf.id=c.proforma_id
            LEFT JOIN inv_contracts ct ON ct.id=c.jobwork_contract_id WHERE c.id=?");
        $s->execute([(int)$_GET['id']]); $doc = $s->fetch() ?: null;
        if ($doc) {
            $s2 = db()->prepare("SELECT i.*, m.code mcode, m.name mname, p.name pname
                FROM inv_consumption_items i
                LEFT JOIN inv_materials m ON m.id=i.material_id
                LEFT JOIN products p ON p.id=i.product_id
                WHERE i.con_id=? ORDER BY i.sort_order, i.id");
            $s2->execute([(int)$doc['id']]);
            foreach ($s2->fetchAll() as $r) { if ($r['side'] === 'input') $inLines[] = $r; else $outLines[] = $r; }
        }
    } catch (Throwable $e) {}
}
$isNew = isset($_GET['new']);
/* Opening a saved consumption shows the DOCUMENT, not the editor — that
   is where "Post consumption" lives. The editor is one click away. */
$showForm = $canEdit && ($isNew || ($doc && isset($_GET['edit']) && $doc['status'] === 'draft'));

$materials = []; $products = []; $proformas = []; $jwContracts = [];
if ($showForm || $doc) {
    $materials = inv_materials(true);
    try { $products = db()->query("SELECT id,name,default_unit FROM products WHERE is_active=1 ORDER BY name LIMIT 500")->fetchAll(); } catch (Throwable $e) {}
}
if ($showForm) {
    /* Only orders actually enabled for production. Consuming against an
       order nobody is producing is meaningless, and this is the same set
       the production module and Order Costing Control already work from. */
    try { $proformas = db()->query("SELECT id,pi_no,customer_name,production_status FROM proforma_invoices
        WHERE production_enabled = 1
        ORDER BY (production_status='completed'), id DESC LIMIT 300")->fetchAll(); } catch (Throwable $e) {}
    try { $jwContracts = db()->query("SELECT c.id,c.contract_no,c.contract_type,p.name pname FROM inv_contracts c
        LEFT JOIN inv_parties p ON p.id=c.party_id
        WHERE c.contract_type='jobwork_in' AND c.status='active' ORDER BY c.id DESC LIMIT 100")->fetchAll(); } catch (Throwable $e) {}
}
$locations = inv_locations(true);

/* Stock behind the material LOV. Consumption ALWAYS takes stock out, so
   unlike a gate pass there is no direction to think about: the list is
   what is on the floor, everywhere, and the picker narrows it further by
   the location on the document. Quantity and value together, so the rate
   on the line can be derived rather than typed. */
$CSM = $showForm ? inv_stock_maps('own') : ['qty' => [], 'val' => []];
$stockMap = $CSM['qty'];
$valueMap = $CSM['val'];

/* The standard bill of material used to be built here for EVERY active
   product on every page load — up to 500 products, several queries each.
   It is now fetched for one product at a time, when the order is chosen,
   through the `order` JSON call above. */
$stdMap = [];
if ($showForm && !$inLines)  $inLines = [[]];
if ($showForm && !$outLines) $outLines = [[]];

$reg = [];
if (!$doc && !$isNew) {
    try {
        $reg = db()->query("SELECT c.*, pf.pi_no,
                (SELECT COALESCE(SUM(qty),0) FROM inv_consumption_items WHERE con_id=c.id AND side='input') tin,
                (SELECT COALESCE(SUM(qty),0) FROM inv_consumption_items WHERE con_id=c.id AND side='output') tout,
                (SELECT COALESCE(SUM(amount),0) FROM inv_consumption_items WHERE con_id=c.id AND side='input') tval
            FROM inv_consumption c LEFT JOIN proforma_invoices pf ON pf.id=c.proforma_id
            ORDER BY c.id DESC LIMIT 200")->fetchAll();
    } catch (Throwable $e) {}
}

page_header('Consumption');
flash();
?>
<div class="topbar">
  <div><h1>Consumption / Production</h1>
    <p class="lead">Materials in, finished product out — one posting. This is the only document in the system that creates finished stock.</p></div>
  <?php if ($canEdit && !$showForm): ?><a class="zbtn sec" href="?new=1">+ New Consumption</a><?php endif; ?>
</div>

<style>
.ic2-card{background:#fff;border:1px solid #e3e9f2;border-radius:16px;padding:20px 22px;margin-bottom:16px;box-shadow:0 10px 30px rgba(15,35,65,.06)}
.ic2-inp{padding:9px 11px;border-radius:9px;border:1px solid #cbd5e3;font-size:12.5px;font-family:inherit;width:100%}
.ic2-lbl{display:block;font-size:10.5px;color:#8a97ab;font-weight:800;text-transform:uppercase;letter-spacing:.04em;margin-bottom:5px}
.ic2-grid{display:grid;gap:13px;grid-template-columns:repeat(4,1fr)}
@media(max-width:1000px){.ic2-grid{grid-template-columns:repeat(2,1fr)}}
@media(max-width:640px){.ic2-grid{grid-template-columns:1fr}}
.ic2-tbl{width:100%;border-collapse:collapse;font-size:12.5px}
.ic2-tbl th{text-align:left;font-size:10px;text-transform:uppercase;letter-spacing:.05em;color:#8a97ab;font-weight:800;padding:0 8px 5px;white-space:nowrap}
.ic2-tbl td{padding:3px 8px;border-top:1px solid #eef1f7;vertical-align:top}
.ic2-tbl td.r,.ic2-tbl th.r{text-align:right;font-variant-numeric:tabular-nums}
.ic2-btn{padding:9px 16px;border:none;border-radius:10px;background:linear-gradient(100deg,#0ea8c9,#6d5bd0);color:#fff;font-weight:700;font-size:12.5px;cursor:pointer}
.ic2-btn.sec{background:#fff;color:#152033;border:1px solid #cbd5e3;text-decoration:none;display:inline-block}
.ic2-btn.go{background:linear-gradient(100deg,#16a34a,#0e8a3d)}
.ic2-btn.warn{background:linear-gradient(100deg,#e08a06,#c0293f)}
.ic2-side{border-radius:13px;padding:15px 17px;margin-bottom:14px}
.ic2-side.in{background:rgba(224,67,93,.05);border:1px solid rgba(224,67,93,.20)}
.ic2-side.out{background:rgba(22,163,74,.05);border:1px solid rgba(22,163,74,.22)}
.ic2-side h3{font-size:13px;font-weight:800;margin:0 0 3px;display:flex;justify-content:space-between;align-items:center;gap:10px}
.ic2-side p{font-size:11.5px;color:#5a6b82;margin:0 0 12px}
.ic2-pill{display:inline-block;font-size:10px;font-weight:800;padding:3px 8px;border-radius:20px}
.p-draft{background:#f0f3f9;color:#5a6b82}.p-posted{background:rgba(22,163,74,.12);color:#16a34a}
.p-reversed{background:rgba(224,67,93,.12);color:#c0293f}
.ic2-note{border-radius:11px;padding:12px 15px;font-size:12.5px;line-height:1.65;margin-bottom:14px}
.ic2-note.info{background:rgba(14,168,201,.07);border:1px solid rgba(14,168,201,.2);color:#2c4a63}
.ic2-note.ok{background:rgba(22,163,74,.08);border:1px solid rgba(22,163,74,.22);color:#1c5334}
.ic2-note.warn{background:rgba(217,119,6,.09);border:1px solid rgba(217,119,6,.25);color:#7a4d09}
.ic2-note.bad{background:rgba(224,67,93,.08);border:1px solid rgba(224,67,93,.24);color:#8c2038}
</style>

<?php if ($showForm): $D = $doc ?: []; ?>
<div class="ic2-card">
  <h2 style="font-size:15.5px;margin:0 0 4px;font-weight:800"><?= $doc ? 'Edit ' . e($doc['con_no']) : 'New consumption' ?></h2>
  <p style="color:#8a97ab;font-size:12px;margin:0 0 14px">Pick the product and quantity, load the standard materials from its costing, then correct anything that differed.</p>

  <form method="post"><?= csrf_field() ?>
    <input type="hidden" name="action" value="save"><input type="hidden" name="id" value="<?= (int)($D['id'] ?? 0) ?>">
    <div class="ic2-grid">
      <div><label class="ic2-lbl">Document no.</label><input class="ic2-inp" name="con_no" value="<?= e($D['con_no'] ?? '') ?>" placeholder="auto" style="font-family:monospace"></div>
      <div><label class="ic2-lbl">Date</label><input class="ic2-inp" type="date" name="con_date" value="<?= e($D['con_date'] ?? date('Y-m-d')) ?>"></div>
      <div><label class="ic2-lbl">Location</label>
        <select class="ic2-inp" name="location_id">
          <?php $dl = (int)($D['location_id'] ?? inv_setting('default_location', '1'));
          foreach ($locations as $l): ?><option value="<?= (int)$l['id'] ?>" <?= $dl === (int)$l['id'] ? 'selected' : '' ?>><?= e($l['name']) ?></option><?php endforeach; ?>
        </select></div>
      <div><label class="ic2-lbl">Department</label><input class="ic2-inp" name="department" value="<?= e($D['department'] ?? '') ?>"></div>

      <div style="grid-column:span 2"><label class="ic2-lbl">Order / proforma — optional</label>
        <select class="ic2-inp" name="proforma_id">
          <option value="0">— none, general stock production —</option>
          <?php foreach ($proformas as $pf): ?><option value="<?= (int)$pf['id'] ?>" <?= (int)($D['proforma_id'] ?? 0) === (int)$pf['id'] ? 'selected' : '' ?>><?= e($pf['pi_no']) ?> · <?= e($pf['customer_name']) ?></option><?php endforeach; ?>
        </select></div>
      <div><label class="ic2-lbl">Job work contract — optional</label>
        <select class="ic2-inp" name="jobwork_contract_id">
          <option value="0">— none —</option>
          <?php foreach ($jwContracts as $c): ?><option value="<?= (int)$c['id'] ?>" <?= (int)($D['jobwork_contract_id'] ?? 0) === (int)$c['id'] ? 'selected' : '' ?>><?= e($c['contract_no']) ?> · <?= e($c['pname'] ?: '') ?></option><?php endforeach; ?>
        </select>
        <p style="font-size:10.5px;color:#d97706;margin:5px 0 0">Choosing one marks both sides customer-owned and unvalued.</p></div>
      <div><label class="ic2-lbl">Batch reference</label><input class="ic2-inp" name="batch_ref" value="<?= e($D['batch_ref'] ?? '') ?>"></div>
    </div>

    <!-- what the FLOOR says about the chosen order line -->
    <div id="floorPanel" style="display:none;border:1px solid #e3e9f2;border-radius:13px;padding:15px 17px;margin-top:20px;background:#f6f8fc">
      <h3 style="font-size:13px;font-weight:800;margin:0 0 3px">What the floor has finished</h3>
      <p style="font-size:11.5px;color:#5a6b82;margin:0 0 12px">Read from your production entries. Production still moves no stock — this only reads what it recorded.</p>
      <div style="display:flex;gap:22px;flex-wrap:wrap;margin-bottom:10px" id="floorStats"></div>
      <div id="floorStages" style="font-size:11.5px;color:#5a6b82"></div>
      <div id="floorWarn" class="ic2-note warn" style="margin-top:10px;display:none"></div>
    </div>

    <!-- OUTPUT first: you pick what you made, then the materials narrow to it -->
    <div class="ic2-side out" style="margin-top:20px">
      <h3>Finished product produced <span class="ic2-pill" style="background:rgba(22,163,74,.14);color:#16a34a">stock +</span></h3>
      <p id="outHint">Choose an order above and this list becomes that order's own product lines, with the size filled in.
         With no order chosen it stays the full Product Master.</p>
      <div style="overflow-x:auto"><table class="ic2-tbl" id="outT">
        <thead><tr><th style="min-width:250px">Product</th><th style="min-width:110px">Size</th>
          <th class="r" style="width:105px">Produced</th><th style="width:74px">UOM</th>
          <th class="r" style="width:90px">Rejected</th><th class="r" style="width:100px">Cost/unit</th><th style="width:34px"></th></tr></thead>
        <tbody>
        <?php foreach ($outLines as $i => $L): ?>
          <tr>
            <td><select class="ic2-inp prod" name="out[<?= $i ?>][product_id]">
              <option value="0">— select product —</option>
              <?php foreach ($products as $p): ?><option value="<?= (int)$p['id'] ?>" data-uom="<?= e($p['default_unit'] ?: 'PCS') ?>" <?= (int)($L['product_id'] ?? 0) === (int)$p['id'] ? 'selected' : '' ?>><?= e($p['name']) ?></option><?php endforeach; ?>
            </select></td>
            <td><input class="ic2-inp osize" name="out[<?= $i ?>][size_label]" value="<?= e($L['size_label'] ?? '') ?>">
                <input type="hidden" class="opfitem" name="out[<?= $i ?>][proforma_item_id]" value="<?= e((string)($L['proforma_item_id'] ?? '')) ?>"></td>
            <td><input class="ic2-inp oqty" name="out[<?= $i ?>][qty]" value="<?= e((string)($L['qty'] ?? '')) ?>" style="text-align:right"></td>
            <td><input class="ic2-inp ouom" name="out[<?= $i ?>][uom]" value="<?= e($L['uom'] ?? '') ?>" style="font-family:monospace"></td>
            <td><input class="ic2-inp" name="out[<?= $i ?>][waste_qty]" value="<?= e((string)($L['waste_qty'] ?? '')) ?>" style="text-align:right"></td>
            <td><input class="ic2-inp" name="out[<?= $i ?>][rate]" value="<?= e((string)($L['rate'] ?? '')) ?>" style="text-align:right"></td>
            <td style="padding-top:11px"><button type="button" class="ic2-btn sec delo" style="padding:5px 9px;cursor:pointer">×</button></td>
          </tr>
        <?php endforeach; ?>
        </tbody>
      </table></div>
      <div style="margin-top:11px;display:flex;gap:9px;flex-wrap:wrap;align-items:center">
        <button type="button" class="ic2-btn sec" id="addo">+ Add product</button>
        <button type="button" class="ic2-btn sec" id="loadstd">Load standard materials from costing</button>
      </div>
      <p id="stdmsg" style="font-size:11.5px;color:#5a6b82;margin:9px 0 0"></p>
    </div>

    <div class="ic2-side in">
      <h3>Materials consumed <span class="ic2-pill" style="background:rgba(224,67,93,.13);color:#c0293f">stock −</span></h3>
      <p>Type only how much you used — the lots are chosen for you, oldest first, from your own floor.
         Press <b>Choose lots</b> on a row to pick by hand across several lots and locations.</p>

      <div style="display:flex;gap:14px;flex-wrap:wrap;align-items:center;margin-bottom:11px">
        <label class="ic2-chk" style="display:inline-flex;align-items:center;gap:7px;font-size:12px;color:#5a6b82;cursor:pointer">
          <input type="checkbox" id="showAllMat"> Show every material, not only this product's
        </label>
        <span id="matScope" style="font-size:11.5px;color:#8a97ab"></span>
      </div>

      <div style="overflow-x:auto"><table class="ic2-tbl" id="inT">
        <thead><tr><th style="min-width:230px">Material</th>
          <th class="r" style="width:88px">Standard</th><th class="r" style="width:96px">Used</th>
          <th style="width:66px">UOM</th><th class="r" style="width:82px">Waste</th>
          <th style="min-width:260px">Taken from</th>
          <th class="r" style="width:96px">Value</th><th style="width:180px"></th></tr></thead>
        <tbody>
        <?php foreach ($inLines as $i => $L): ?>
          <tr data-i="<?= $i ?>">
            <td><div class="matbox">
              <?php /* Same List of Values as the Gate pass — click in and
                       the list is already open, showing what is actually
                       on the floor with its balance. The <select> beneath
                       is still the field that is submitted, so if the
                       script ever fails the plain dropdown comes back. */ ?>
              <input class="ic2-inp matq" type="text" autocomplete="off" spellcheck="false"
                     data-lov="cmat" placeholder="click here — the list opens" style="display:none">
              <select class="ic2-inp mat" name="inp[<?= $i ?>][material_id]">
                <option value="0">— select material —</option>
                <?php foreach ($materials as $m): ?><option value="<?= (int)$m['id'] ?>" data-uom="<?= e($m['uom']) ?>" data-rate="<?= e((string)$m['std_rate']) ?>" data-grp="<?= e($m['item_group'] ?? '') ?>" <?= (int)($L['material_id'] ?? 0) === (int)$m['id'] ? 'selected' : '' ?>><?= e($m['code']) ?> · <?= e($m['name']) ?></option><?php endforeach; ?>
              </select>
            </div>
            <div class="offstd" style="font-size:10px;font-weight:800;color:#b45309;margin-top:3px" hidden>not in this product's costing</div></td>
            <td><input class="ic2-inp std" name="inp[<?= $i ?>][std_qty]" value="<?= e((string)($L['std_qty'] ?? '')) ?>" style="text-align:right;color:#8a97ab"></td>
            <td><input class="ic2-inp iqty" name="inp[<?= $i ?>][qty]" value="<?= e((string)($L['qty'] ?? '')) ?>" style="text-align:right;font-weight:700"></td>
            <td><input class="ic2-inp iuom" name="inp[<?= $i ?>][uom]" value="<?= e($L['uom'] ?? '') ?>" style="font-family:monospace"></td>
            <td><input class="ic2-inp iw" name="inp[<?= $i ?>][waste_qty]" value="<?= e((string)($L['waste_qty'] ?? '')) ?>" style="text-align:right"></td>
            <td class="alloc" style="font-size:11.5px"></td>
            <td class="r ival" style="font-weight:700"><?= number_format((float)($L['amount'] ?? 0), 2) ?></td>
            <td>
              <div style="display:flex;gap:6px;align-items:center;flex-wrap:wrap">
                <button type="button" class="ic2-btn sec pick" style="padding:5px 9px;cursor:pointer">Choose lots</button>
                <span class="modechip ic2-pill" style="background:rgba(14,168,201,.12);color:#0b7d96">FIFO</span>
                <button type="button" class="ic2-btn sec refifo" style="padding:5px 9px;cursor:pointer;display:none" title="Back to oldest-first">↺</button>
                <button type="button" class="ic2-btn sec deli" style="padding:5px 9px;cursor:pointer">×</button>
              </div>
              <input type="hidden" class="hLot"  name="inp[<?= $i ?>][lot_no]"      value="<?= e($L['lot_no'] ?? '') ?>">
              <input type="hidden" class="hLoc"  name="inp[<?= $i ?>][location_id]" value="<?= e((string)($L['location_id'] ?? '')) ?>">
              <input type="hidden" class="hOwn"  name="inp[<?= $i ?>][ownership]"   value="<?= e($L['ownership'] ?? 'own') ?>">
              <input type="hidden" class="hRate" name="inp[<?= $i ?>][rate]"        value="<?= e((string)($L['rate'] ?? '')) ?>">
              <input type="hidden" class="hMode" name="inp[<?= $i ?>][pick_mode]"   value="<?= e($L['pick_mode'] ?? 'fifo') ?>">
              <input type="hidden" class="hOff"  name="inp[<?= $i ?>][off_standard]" value="<?= (int)($L['off_standard'] ?? 0) ?>">
              <input type="hidden" class="hRsn"  name="inp[<?= $i ?>][short_reason]" value="<?= e($L['short_reason'] ?? '') ?>">
            </td>
          </tr>
        <?php endforeach; ?>
        </tbody>
        <tfoot><tr><td colspan="6" style="text-align:right;font-weight:800">Material cost</td><td class="r" id="matcost" style="font-weight:800;font-size:13.5px">0.00</td><td></td></tr></tfoot>
      </table></div>
      <div style="margin-top:11px"><button type="button" class="ic2-btn sec" id="addi">+ Add material</button></div>
    </div>

    <!-- cost per unit vs the costing this order was priced on -->
    <div id="costPanel" style="border:1px solid #e3e9f2;border-radius:13px;padding:15px 17px;margin-top:16px;display:none">
      <h3 style="font-size:13px;font-weight:800;margin:0 0 3px">Cost per unit — against the costing</h3>
      <p style="font-size:11.5px;color:#5a6b82;margin:0 0 12px" id="costSub"></p>
      <div style="overflow-x:auto"><table class="ic2-tbl">
        <thead><tr><th>Element</th><th class="r">Quoted / unit</th><th class="r">Actual / unit</th><th class="r">Difference</th><th></th></tr></thead>
        <tbody id="costBody"></tbody>
      </table></div>
      <div id="costAlert" style="margin-top:12px"></div>
      <div id="varWrap" style="margin-top:12px;display:none">
        <label class="ic2-lbl">Why is the cost this far from the costing? — required, kept on the document</label>
        <input class="ic2-inp" name="variance_reason" id="varReason" maxlength="400" value="<?= e($D['cost_variance_reason'] ?? '') ?>"
               placeholder="e.g. greige bought at 219 against 205 in the costing — supplier increase in August">
      </div>
      <input type="hidden" name="quoted_cpu"   id="qCpu"  value="<?= e((string)($D['quoted_cost_per_unit'] ?? '')) ?>">
      <input type="hidden" name="actual_cpu"   id="aCpu"  value="<?= e((string)($D['actual_cost_per_unit'] ?? '')) ?>">
      <input type="hidden" name="variance_pct" id="vPct"  value="<?= e((string)($D['cost_variance_pct'] ?? '')) ?>">
    </div>

    <div style="margin-top:16px"><label class="ic2-lbl">Remarks</label><input class="ic2-inp" name="remarks" value="<?= e($D['remarks'] ?? '') ?>"></div>
    <div style="margin-top:16px;display:flex;gap:10px;flex-wrap:wrap;align-items:center">
      <button class="ic2-btn" type="submit">Save</button>
      <a class="ic2-btn sec" href="inv_consume.php">Back</a>
      <span style="font-size:11.5px;color:#8a97ab">Saving changes nothing. Posting deducts the materials and adds the product together.</span>
    </div>
  </form>
</div>

<!-- lot picker -->
<div id="pkScrim" style="position:fixed;inset:0;background:rgba(8,14,24,.55);display:none;align-items:flex-start;justify-content:center;padding:26px 16px;overflow:auto;z-index:60">
  <div class="ic2-card" style="width:100%;max-width:940px;margin:0">
    <div style="display:flex;justify-content:space-between;gap:12px;flex-wrap:wrap;align-items:flex-start">
      <div><h2 id="pkTitle" style="font-size:15px;margin:0;font-weight:800">Choose lots</h2>
        <p id="pkSub" style="font-size:11.5px;color:#8a97ab;margin:3px 0 0"></p></div>
      <button type="button" class="ic2-btn sec" id="pkClose">Close</button>
    </div>

    <div style="display:flex;gap:10px;flex-wrap:wrap;align-items:flex-end;padding:14px 0;border-bottom:1px solid #e3e9f2">
      <div><label class="ic2-lbl">Location</label><select class="ic2-inp" id="pkLoc" style="width:auto;min-width:150px">
        <option value="">All locations</option>
        <?php foreach ($locations as $l): ?><option value="<?= (int)$l['id'] ?>"><?= e($l['name']) ?></option><?php endforeach; ?>
      </select></div>
      <div><label class="ic2-lbl">Ownership</label><select class="ic2-inp" id="pkOwn" style="width:auto;min-width:150px">
        <option value="">Own + customer</option><option value="own">Ours only</option><option value="customer">Customer's only</option>
      </select></div>
      <div><label class="ic2-lbl">Lot contains</label><input class="ic2-inp" id="pkLot" style="width:auto;min-width:140px" placeholder="e.g. 24-"></div>
      <label style="display:inline-flex;align-items:center;gap:7px;font-size:12px;color:#5a6b82;cursor:pointer;padding-bottom:9px">
        <input type="checkbox" id="pkZero"> Show emptied lots</label>
    </div>

    <div style="display:flex;gap:7px;margin:13px 0">
      <button type="button" class="ic2-btn sec" id="pkTabLots">Lots available</button>
      <button type="button" class="ic2-btn sec" id="pkTabLed">Item ledger</button>
    </div>

    <div id="pkPaneLots" style="overflow-x:auto"><table class="ic2-tbl">
      <thead><tr><th style="width:32px"></th><th>Lot</th><th>Location</th><th>Own</th>
        <th class="r">Available</th><th class="r">Rate</th><th>In stock since</th><th class="r" style="width:130px">Take</th></tr></thead>
      <tbody id="pkBody"></tbody>
    </table></div>

    <div id="pkPaneLed" style="overflow-x:auto;display:none"><table class="ic2-tbl">
      <thead><tr><th>Date</th><th>Document</th><th>Lot</th><th>Location</th><th class="r">In</th><th class="r">Out</th><th class="r">Balance</th></tr></thead>
      <tbody id="pkLedBody"></tbody>
    </table></div>

    <div style="display:flex;gap:12px;align-items:center;flex-wrap:wrap;margin-top:14px;padding-top:14px;border-top:1px solid #e3e9f2">
      <span style="font-size:12.5px;color:#5a6b82"><b id="pkCount">0</b> lot(s) · <b id="pkQty">0.000</b> <span id="pkUom"></span></span>
      <span id="pkWarn" style="color:#c0293f;font-size:11px;font-weight:700"></span>
      <div style="margin-left:auto;display:flex;gap:8px">
        <button type="button" class="ic2-btn sec" id="pkCancel">Cancel</button>
        <button type="button" class="ic2-btn" id="pkUse">Use these lots</button>
      </div>
    </div>
  </div>
</div>

<link rel="stylesheet" href="assets/css/lov.css?v=2">
<script src="assets/js/lov.js?v=2"></script>
<script>
/* Consumption form. Three ideas, in order of importance:
     1. You type a QUANTITY; the lots are chosen for you, oldest first.
     2. Manual is one click away and starts from the FIFO answer.
     3. Every list narrows to the order you picked, unless you ask otherwise.
   Balances are fetched live, never cached from page load, and the server
   re-checks all of it when the document is posted. */
(function(){
  var inT  = document.querySelector('#inT tbody'),
      outT = document.querySelector('#outT tbody');
  var rowTpl = inT.firstElementChild ? inT.firstElementChild.outerHTML : '';
  var outTpl = outT.firstElementChild ? outT.firstElementChild.outerHTML : '';
  var pfSel  = document.querySelector('select[name="proforma_id"]');
  var jwSel  = document.querySelector('select[name="jobwork_contract_id"]');
  var showAll = document.getElementById('showAllMat');

  var ORDER = null;      // {lines:[...]} for the chosen proforma
  var QUOTED = null;     // per-unit cost the order was priced at
  var TOL = 20;          // % the cost may drift before it must be explained
  var ALLOC = {};        // row index -> [{lot_no,location_id,location,ownership,qty,rate,bal}]
  var LOTCACHE = {};     // material_id -> lots (refreshed whenever the picker opens)

  /* What the LOV lists, and what it says beside each row. Built once from
     the <select> that is already on the page, so the two can never
     disagree about which materials exist. */
  var STOCK  = <?= json_encode($stockMap ?: new stdClass()) ?>;
  var VALMAP = <?= json_encode($valueMap ?: new stdClass()) ?>;
  var ITEMS  = (function(){
    var out = [], s = inT.querySelector('select.mat');
    if(!s) return out;
    [].slice.call(s.options).forEach(function(o){
      if(!o.value || o.value === '0') return;
      var txt = o.text || '', dot = txt.indexOf('·');
      out.push({ id:o.value, text:txt,
                 code: dot > 0 ? txt.slice(0, dot).trim() : txt,
                 name: dot > 0 ? txt.slice(dot + 1).trim() : txt,
                 grp: o.dataset.grp || '', uom: o.dataset.uom || '',
                 rate: o.dataset.rate || 0 });
    });
    return out;
  })();

  function num(v){ var n = parseFloat(String(v).replace(/[^0-9.\-]/g,'')); return isNaN(n)?0:n; }
  function f3(n){ return Number(n).toLocaleString('en-US',{minimumFractionDigits:3,maximumFractionDigits:3}); }
  function money(n){ return Number(n).toLocaleString('en-US',{minimumFractionDigits:2,maximumFractionDigits:2}); }
  function rows(){ return [].slice.call(inT.children); }
  function idxOf(tr){ return rows().indexOf(tr); }
  function get(tr,sel){ return tr.querySelector(sel); }

  /* ---- the cascade, and the LOV that shows it ----------------------
     Order  ->  the products that order actually makes
     Product ->  the materials that product's costing uses
     Material ->  the lots of it that are really on the floor

     Each step narrows the next. That was already true of the DATA here;
     what was missing was showing it — the material box was a dropdown of
     every item in the business with the wrong ones merely hidden, and no
     balance anywhere. It is now the same List of Values the Gate pass
     uses, from assets/js/lov.js. */
  function currentProductId(){
    var s = outT.querySelector('select.prod');
    return s ? +s.value : 0;
  }
  function standardFor(pid){
    if(!ORDER) return null;
    for(var i=0;i<ORDER.lines.length;i++) if(ORDER.lines[i].product_id === pid) return ORDER.lines[i].materials || {};
    return null;
  }

  function itemById(id){
    for(var i=0;i<ITEMS.length;i++) if(String(ITEMS[i].id)===String(id)) return ITEMS[i];
    return null;
  }
  function docLoc(){ var s=document.querySelector('select[name="location_id"]'); return s ? +s.value : 0; }
  function locName(){
    var s=document.querySelector('select[name="location_id"]');
    return s && s.selectedIndex>=0 ? s.options[s.selectedIndex].text : 'this location';
  }
  function balOf(id){
    var m = STOCK[id]; if(!m) return 0;
    var l = docLoc(), v = l>0 ? m[l] : m[0];
    return v === undefined ? 0 : v;
  }
  /* What this material is standing at, so the line's value is the
     ledger's own number and not a typed guess. */
  function rateFor(it){
    var v = VALMAP[it.id], b = balOf(it.id), l = docLoc();
    var val = v ? (l>0 ? v[l] : v[0]) : undefined;
    if(val !== undefined && b > 0.0005) return Math.round((val/b)*10000)/10000;
    return num(it.rate);
  }

  /* Three bands of relevance, in this order:
       1. in this product's costing AND on the floor   — what you want
       2. on the floor but not in the costing          — allowed, marked
       3. neither                                       — hidden until asked
     The costing is the cascade; the floor is the stock check. Both. */
  function matRows(q, showAll){
    var std = standardFor(currentProductId());
    var narrow = !!(std && Object.keys(std).length) && !showAll.checked;
    var out = [], hidden = 0;
    ITEMS.forEach(function(it){
      var sc = LOV.score(q, it.code, it.name, it.grp);
      if(sc <= 0) return;
      var inStd = !!(std && (it.id in std));
      var bal = balOf(it.id);
      if(narrow && !inStd){ hidden++; return; }
      out.push({ it:it, bal:bal, std:inStd, per:inStd ? std[it.id] : 0,
                 rate:rateFor(it), sc:sc });
    });
    out.sort(function(a,b){
      if(a.sc !== b.sc) return b.sc - a.sc;
      if(a.std !== b.std) return a.std ? -1 : 1;      // costing first
      return a.it.code.localeCompare(b.it.code);
    });
    return [out, hidden];
  }

  LOV.register('cmat', {
    cols: [
      { label:'Code',        w:'86px',             cls:'cd', get:function(r,q){ return LOV.hl(r.it.code,q); } },
      { label:'Description', w:'minmax(130px,1fr)',cls:'nm', get:function(r,q){ return LOV.hl(r.it.name,q); } },
      { label:'In costing',  w:'74px',             cls:'gg',
        get:function(r){ return r.std ? '<b style="color:#0b7d96">yes</b>' : '<span style="color:#b45309">no</span>'; } },
      { label:'On floor',    w:'78px', align:'r',  cls:'nu',
        style:function(r){ return 'font-weight:700;color:'+(r.bal>0?'#16a34a':'#c0293f'); },
        get:function(r){ return LOV.q3(r.bal); } },
      { label:'UOM',         w:'44px',             cls:'gg', get:function(r){ return LOV.esc(r.it.uom); } },
      { label:'Rate',        w:'68px', align:'r',  cls:'nu', get:function(r){ return LOV.m2(r.rate); } }
    ],
    moreLabel:'outside this costing',
    lessLabel:"only this product's materials",
    title: function(){
      var pid = currentProductId(), std = standardFor(pid);
      if(!ORDER) return 'Select material — no order, every material listed';
      if(!pid)   return 'Select material — choose the product to narrow this';
      if(!std || !Object.keys(std).length) return 'Select material — this product has no costing links yet';
      return 'Select material — ' + Object.keys(std).length + " from this product's costing";
    },
    empty: function(f,q){
      var pid = currentProductId(), std = standardFor(pid);
      if(std && Object.keys(std).length && !showAll.checked)
        return q ? "Nothing matching is in this product's costing. Tick “Show every material” to look wider."
                 : "This product's costing lists no material matching.";
      return q ? 'No material matches that.' : 'No material to show.';
    },
    rows: function(f,q,showAllFlag,cb){ var r = matRows(q, showAll); cb(r[0], r[1]); },
    revert: function(f){ var box=f.closest('.matbox'); if(box) syncMat(box); },
    pick: function(f, r){
      var tr = f.closest('tr'), sel = get(tr,'select.mat');
      sel.value = r.it.id;
      f.value = r.it.code + ' · ' + r.it.name;
      sel.dispatchEvent(new Event('change', {bubbles:true}));
      var q = get(tr,'.iqty'); if(q) q.focus();
    }
  });

  function syncMat(box){
    var sel = box.querySelector('select.mat'), q = box.querySelector('.matq');
    if(!sel || !q) return;
    sel.style.display='none'; q.style.display='';
    q.value = (sel.value && sel.value!=='0' && sel.options[sel.selectedIndex])
            ? sel.options[sel.selectedIndex].text : '';
  }
  function syncAllMat(){ inT.querySelectorAll('.matbox').forEach(syncMat); }
  LOV.attach(inT);

  /* The line under the table that says how wide the list currently is.
     Kept because an operator who cannot find an item needs to be told
     WHY, not left guessing at an empty box. */
  function applyMaterialScope(){
    var pid = currentProductId(), std = standardFor(pid);
    var scope = document.getElementById('matScope');
    var narrow = !!(std && Object.keys(std).length) && !showAll.checked;
    rows().forEach(function(tr){ markOffStandard(tr, std); });
    syncAllMat();
    if(!ORDER){ scope.textContent = 'No order chosen — every material is listed.'; return; }
    if(!pid){ scope.textContent = 'Choose the finished product above to narrow this list.'; return; }
    if(!std || !Object.keys(std).length){
      scope.textContent = "This product's costing has no linked materials yet, so every material is listed. Link them under Costing & Products ▸ Link Costing Items.";
      return;
    }
    scope.textContent = narrow
      ? Object.keys(std).length + " material(s) from this product's costing. The list shows what is on the floor beside each."
      : "Showing all materials — anything outside the costing is marked.";
  }
  function markOffStandard(tr, std){
    var sel = get(tr,'select.mat'), mid = sel.value;
    var off = !!(std && Object.keys(std).length) && mid && mid!=='0' && !(mid in std);
    get(tr,'.offstd').hidden = !off;
    get(tr,'.hOff').value = off ? 1 : 0;
  }
  function fillStandardQty(tr){
    var pid = currentProductId(), std = standardFor(pid);
    var made = 0; var oq = outT.querySelector('.oqty'); if(oq) made = num(oq.value);
    var mid = get(tr,'select.mat').value;
    if(std && (mid in std) && made > 0) get(tr,'.std').value = (std[mid] * made).toFixed(3);
  }

  function loadOrder(){
    var id = pfSel ? +pfSel.value : 0;
    if(!id){ ORDER = null; rebuildProductOptions(); applyMaterialScope(); return; }
    fetch('inv_consume.php?ajax=order&proforma_id='+id).then(function(r){return r.json()}).then(function(d){
      ORDER = d.ok ? d : null;
      QUOTED = (d && d.quoted) || null;
      TOL = (d && d.tolerance_pct) || 20;
      rebuildProductOptions();
      applyMaterialScope();
      showFloor();
      recost();
    }).catch(function(){ ORDER = null; });
  }
  function rebuildProductOptions(){
    var hint = document.getElementById('outHint');
    [].slice.call(outT.querySelectorAll('select.prod')).forEach(function(sel){
      var keep = sel.value;
      if(!ORDER || !ORDER.lines.length){
        [].slice.call(sel.options).forEach(function(o){ o.hidden = false; });
        return;
      }
      var allowed = {}; var unresolved = 0;
      ORDER.lines.forEach(function(l){ if(l.product_id) allowed[l.product_id]=l; else unresolved++; });
      [].slice.call(sel.options).forEach(function(o){
        if(!o.value || o.value==='0'){ o.hidden = false; return; }
        o.hidden = !(o.value in allowed) && o.value !== keep;
      });
      if(!(keep in allowed)){ var first = Object.keys(allowed)[0]; if(first){ sel.value = first; onProductChange(sel); } }
      hint.textContent = Object.keys(allowed).length + " product line(s) on this order."
        + (unresolved ? ' ' + unresolved + " order line(s) do not match a Product Master product and are not listed." : '');
    });
  }
  function onProductChange(sel){
    var tr = sel.closest('tr'), pid = +sel.value;
    var o = sel.options[sel.selectedIndex];
    if(o && o.dataset.uom && !get(tr,'.ouom').value) get(tr,'.ouom').value = o.dataset.uom;
    if(ORDER){
      ORDER.lines.forEach(function(l){
        if(l.product_id === pid){
          if(!get(tr,'.osize').value) get(tr,'.osize').value = l.size || '';
          // default to what the FLOOR can still give us, not what was ordered
          if(!num(get(tr,'.oqty').value)) get(tr,'.oqty').value = (l.prod ? l.prod.available : l.ordered) || '';
          var h = tr.querySelector('.opfitem'); if(h) h.value = l.item_id;
        }
      });
    }
    applyMaterialScope();
    rows().forEach(fillStandardQty);
    rows().forEach(refifo);
    showFloor();
    recost();
  }

  /* ---- FIFO --------------------------------------------------------- */
  function refifo(tr){
    if(get(tr,'.hMode').value === 'manual') return;   // a person chose these
    var mid = +get(tr,'select.mat').value;
    var want = num(get(tr,'.iqty').value) + num(get(tr,'.iw').value);
    var i = idxOf(tr);
    if(!mid || want<=0){ ALLOC[i]=[]; paint(tr); return; }
    fetch('inv_consume.php?ajax=fifo&material_id='+mid+'&qty='+encodeURIComponent(want))
      .then(function(r){return r.json()}).then(function(d){
        if(!d.ok) return;
        ALLOC[i] = d.alloc || [];
        tr.dataset.short = d.short || 0;
        paint(tr);
      }).catch(function(){});
  }
  function paint(tr){
    var i = idxOf(tr), a = ALLOC[i] || [], short = num(tr.dataset.short||0);
    var cell = get(tr,'.alloc');
    if(!a.length){
      cell.innerHTML = '<span style="color:#8a97ab">— type a quantity —</span>';
    } else {
      cell.innerHTML = a.map(function(x){
        return '<div style="display:flex;gap:6px;align-items:center;flex-wrap:wrap;margin-bottom:2px">'
          + '<b style="font-family:monospace">'+(x.lot_no||'(no lot)')+'</b>'
          + '<span class="ic2-pill" style="background:rgba(109,91,208,.11);color:#6d5bd0">'+x.location+'</span>'
          + (x.ownership==='customer'?'<span class="ic2-pill" style="background:rgba(217,119,6,.14);color:#b45309">customer</span>':'')
          + '<span style="font-family:monospace">'+f3(x.qty)+'</span>'
          + '<span style="color:#8a97ab">@ '+(x.ownership==='customer'?'not valued':money(x.rate))+'</span>'
          + (x.over ? '<span style="color:#c0293f;font-weight:700">over by '+f3(x.over)+'</span>' : '')
          + '</div>';
      }).join('');
    }
    if(short > 0.0005){
      cell.innerHTML += '<div style="color:#b45309;font-weight:700;margin-top:3px">short by '+f3(short)
        + ' — not enough on your own floor. Press Choose lots to take from a job worker or customer stock.</div>';
    }
    // the first lot's identity is what the row stores; a multi-lot row is
    // split into one row per lot when Use these lots is pressed
    var first = a[0] || null;
    get(tr,'.hLot').value  = first ? (first.lot_no||'') : '';
    get(tr,'.hLoc').value  = first ? first.location_id : '';
    get(tr,'.hOwn').value  = first ? first.ownership : 'own';
    get(tr,'.hRate').value = first ? first.rate : '';
    var manual = get(tr,'.hMode').value === 'manual';
    var chip = get(tr,'.modechip');
    chip.textContent = manual ? 'manual' : 'FIFO';
    chip.style.background = manual ? 'rgba(217,119,6,.14)' : 'rgba(14,168,201,.12)';
    chip.style.color      = manual ? '#b45309' : '#0b7d96';
    get(tr,'.refifo').style.display = manual ? '' : 'none';
    recalc();
  }
  function recalc(){
    var total = 0;
    rows().forEach(function(tr){
      var i = idxOf(tr), a = ALLOC[i] || [];
      var v = a.reduce(function(s,x){ return s + (x.ownership==='customer' ? 0 : x.qty*x.rate); }, 0);
      get(tr,'.ival').textContent = money(v);
      total += v;
    });
    document.getElementById('matcost').textContent = money(total);
    if(typeof recost === 'function') recost();
  }

  /* ---- lot picker --------------------------------------------------- */
  var scrim = document.getElementById('pkScrim'), pkRow = null, PICK = {}, PKLOTS = [], PKTOL = 10, PKADMIN = false;
  function key(l){ return (l.lot_no||'')+'|'+l.location_id+'|'+l.ownership; }
  function openPicker(tr){
    pkRow = tr;
    var mid = +get(tr,'select.mat').value;
    if(!mid){ alert('Choose the material on this row first.'); return; }
    var sel = get(tr,'select.mat'), o = sel.options[sel.selectedIndex];
    document.getElementById('pkTitle').textContent = 'Choose lots — ' + (o?o.text:'');
    document.getElementById('pkUom').textContent = get(tr,'.iuom').value || '';
    PICK = {};
    (ALLOC[idxOf(tr)]||[]).forEach(function(x){ PICK[key(x)] = String(x.qty); });
    document.getElementById('pkLoc').value=''; document.getElementById('pkOwn').value='';
    document.getElementById('pkLot').value=''; document.getElementById('pkZero').checked=false;
    showLots();
    fetch('inv_consume.php?ajax=lots&material_id='+mid+'&all=1').then(function(r){return r.json()}).then(function(d){
      PKLOTS = d.lots||[]; PKTOL = d.tolerance_pct||10; PKADMIN = !!d.is_admin;
      document.getElementById('pkSub').textContent =
        'Oldest first · balances summed live from the stock ledger · you may go up to '
        + PKTOL + '% below a lot with a reason' + (PKADMIN ? '' : ', beyond that only an admin can post');
      renderLots();
    }).catch(function(){ PKLOTS=[]; renderLots(); });
    scrim.style.display = 'flex';
  }
  function closePicker(){ scrim.style.display='none'; pkRow=null; }
  function showLots(){ document.getElementById('pkPaneLots').style.display=''; document.getElementById('pkPaneLed').style.display='none'; }
  function visible(){
    var loc = document.getElementById('pkLoc').value,
        own = document.getElementById('pkOwn').value,
        txt = document.getElementById('pkLot').value.trim().toLowerCase(),
        zero= document.getElementById('pkZero').checked;
    return PKLOTS.filter(function(l){
      return (!loc || l.location_id==+loc) && (!own || l.ownership===own)
        && (!txt || (l.lot_no||'(no lot)').toLowerCase().indexOf(txt)>=0)
        && (zero || l.bal > 0.0005);
    });
  }
  function renderLots(){
    var tb = document.getElementById('pkBody'), list = visible();
    if(!list.length){
      tb.innerHTML = '<tr><td colspan="8" style="padding:24px;text-align:center;color:#8a97ab">No lot matches these filters.</td></tr>';
    } else {
      tb.innerHTML = list.map(function(l){
        var k = key(l), taken = PICK[k]||'';
        var over = taken!=='' && num(taken) > l.bal + 0.0005;
        var lim  = Math.max(0,l.bal) * PKTOL / 100;
        var hard = taken!=='' && (num(taken) - l.bal) > lim + 0.0005;
        return '<tr>'
          + '<td><input type="checkbox" data-k="'+k+'" data-bal="'+l.bal+'" class="pkTick" '+(taken!==''?'checked':'')+'></td>'
          + '<td style="font-family:monospace;font-weight:700">'+(l.lot_no||'(no lot)')+'</td>'
          + '<td><span class="ic2-pill" style="background:rgba(109,91,208,.11);color:#6d5bd0">'+l.location+'</span>'
          + (l.fifo_ok?'':'<div style="font-size:10px;color:#b45309;font-weight:700;margin-top:2px">never taken by FIFO</div>')+'</td>'
          + '<td><span class="ic2-pill" style="background:'+(l.ownership==='own'?'rgba(14,168,201,.12);color:#0b7d96':'rgba(217,119,6,.14);color:#b45309')+'">'+(l.ownership==='own'?'ours':'customer')+'</span></td>'
          + '<td class="r" style="font-weight:700">'+f3(l.bal)+'</td>'
          + '<td class="r">'+(l.ownership==='customer'?'<span style="color:#b45309">not valued</span>':money(l.rate))+'</td>'
          + '<td style="font-family:monospace;font-size:11.5px;color:#8a97ab">'+(l.first_in||'')+'</td>'
          + '<td class="r"><input class="ic2-inp pkQtyIn" data-k="'+k+'" data-bal="'+l.bal+'" value="'+taken+'" placeholder="0.000" style="width:104px;text-align:right'+(over?';border-color:#c0293f;color:#c0293f;font-weight:700':'')+'">'
          + (over?'<div style="color:'+(hard?'#c0293f':'#b45309')+';font-size:10px;font-weight:700;margin-top:2px">'
                 + (hard ? (PKADMIN?'beyond '+PKTOL+'% — admin only':'beyond '+PKTOL+'% — an admin must post this')
                         : 'below zero, within '+PKTOL+'% — reason required')+'</div>':'')
          + '</td></tr>';
      }).join('');
    }
    updateBar();
  }
  function updateBar(){
    var n=0,q=0,hard=0,soft=0;
    PKLOTS.forEach(function(l){
      var v = PICK[key(l)]; if(v===undefined||v==='') return;
      n++; q += num(v);
      var overBy = num(v) - l.bal, lim = Math.max(0,l.bal)*PKTOL/100;
      if(overBy > lim + 0.0005) hard++; else if(overBy > 0.0005) soft++;
    });
    document.getElementById('pkCount').textContent = n;
    document.getElementById('pkQty').textContent = f3(q);
    var w = document.getElementById('pkWarn');
    w.textContent = hard ? (hard+' lot(s) go beyond '+PKTOL+'% below zero'+(PKADMIN?' — allowed for you, reason required.':' — only an admin can post that.'))
                  : soft ? (soft+' lot(s) go below zero within '+PKTOL+'% — a reason is required.') : '';
    document.getElementById('pkUse').disabled = (!PKADMIN && hard>0);
  }
  document.getElementById('pkBody').addEventListener('input', function(e){
    if(!e.target.classList.contains('pkQtyIn')) return;
    var k = e.target.dataset.k, v = e.target.value;
    if(v.trim()==='') delete PICK[k]; else PICK[k]=v;
    var bal = num(e.target.dataset.bal), over = v.trim()!=='' && num(v) > bal + 0.0005;
    e.target.style.borderColor = over ? '#c0293f' : '';
    e.target.style.color = over ? '#c0293f' : '';
    var cb = e.target.closest('tr').querySelector('.pkTick'); if(cb) cb.checked = v.trim()!=='';
    updateBar();
  });
  document.getElementById('pkBody').addEventListener('change', function(e){
    if(!e.target.classList.contains('pkTick')) return;
    var k = e.target.dataset.k;
    if(e.target.checked) PICK[k] = String(e.target.dataset.bal); else delete PICK[k];
    renderLots();
  });
  ['pkLoc','pkOwn','pkZero'].forEach(function(id){ document.getElementById(id).addEventListener('change', renderLots); });
  document.getElementById('pkLot').addEventListener('input', renderLots);
  document.getElementById('pkClose').onclick = closePicker;
  document.getElementById('pkCancel').onclick = closePicker;
  document.getElementById('pkTabLots').onclick = showLots;
  document.getElementById('pkTabLed').onclick = function(){
    document.getElementById('pkPaneLots').style.display='none';
    document.getElementById('pkPaneLed').style.display='';
    var tb = document.getElementById('pkLedBody');
    tb.innerHTML = '<tr><td colspan="7" style="padding:20px;text-align:center;color:#8a97ab">Loading…</td></tr>';
    var mid = pkRow ? +get(pkRow,'select.mat').value : 0;
    fetch('inv_ledger.php?material_id='+mid+'&json=1').then(function(r){return r.json()}).then(function(d){
      var rs = (d && d.rows) || [];
      if(!rs.length){ tb.innerHTML = '<tr><td colspan="7" style="padding:20px;text-align:center;color:#8a97ab">No movements yet for this item.</td></tr>'; return; }
      var bal = 0;
      tb.innerHTML = rs.map(function(r){
        bal += (+r.qty_in) - (+r.qty_out);
        return '<tr><td style="font-family:monospace">'+r.txn_date+'</td><td style="font-family:monospace">'+(r.source_no||'')+'</td>'
          + '<td style="font-family:monospace">'+(r.lot_no||'(no lot)')+'</td><td>'+(r.location||'')+'</td>'
          + '<td class="r">'+(+r.qty_in?f3(r.qty_in):'—')+'</td><td class="r">'+(+r.qty_out?f3(r.qty_out):'—')+'</td>'
          + '<td class="r" style="font-weight:700">'+f3(bal)+'</td></tr>';
      }).join('');
    }).catch(function(){
      tb.innerHTML = '<tr><td colspan="7" style="padding:20px;text-align:center;color:#8a97ab">Could not load the ledger.</td></tr>';
    });
  };
  document.getElementById('pkUse').onclick = function(){
    if(!pkRow) return;
    var chosen = [];
    PKLOTS.forEach(function(l){
      var v = PICK[key(l)]; if(v===undefined||v==='') return;
      var q = num(v); if(q<=0) return;
      var o = {lot_no:l.lot_no, location_id:l.location_id, location:l.location, ownership:l.ownership, qty:q, rate:l.rate, bal:l.bal};
      if(q > l.bal + 0.0005) o.over = q - l.bal;
      chosen.push(o);
    });
    var tr = pkRow, i = idxOf(tr);
    ALLOC[i] = chosen;
    tr.dataset.short = 0;
    get(tr,'.hMode').value = 'manual';
    get(tr,'.iqty').value  = (chosen.reduce(function(s,x){return s+x.qty;},0) - num(get(tr,'.iw').value)).toFixed(3);
    if(chosen.some(function(x){return x.over;})){
      var why = prompt('One or more lots go below zero.\nWhy? (kept with the document, required)', get(tr,'.hRsn').value || '');
      if(why===null || !why.trim()){ alert('A reason is required — nothing was changed.'); return; }
      get(tr,'.hRsn').value = why.trim();
    } else {
      get(tr,'.hRsn').value = '';
    }
    paint(tr);
    closePicker();
  };

  /* ---- what the floor finished, and the cost check ------------------ */
  function currentLine(){
    if(!ORDER) return null;
    var h = outT.querySelector('.opfitem');
    var id = h ? +h.value : 0;
    if(!id) return null;
    for(var i=0;i<ORDER.lines.length;i++) if(ORDER.lines[i].item_id === id) return ORDER.lines[i];
    return null;
  }
  function showFloor(){
    var panel = document.getElementById('floorPanel'), l = currentLine();
    if(!l || !l.prod){ panel.style.display='none'; return; }
    panel.style.display='';
    var p = l.prod;
    function stat(v,lab){ return '<div><b style="display:block;font-size:18px;font-weight:800;font-variant-numeric:tabular-nums">'
      + f3(v) + '</b><span style="font-size:10.5px;text-transform:uppercase;letter-spacing:.03em;color:#8a97ab;font-weight:700">'+lab+'</span></div>'; }
    document.getElementById('floorStats').innerHTML =
      stat(p.ordered,'Ordered') + stat(p.finished,'Finished on floor')
      + stat(p.converted,'Already converted') + stat(p.available,'Available now');
    var st = [];
    ['Cutting','Stitching','Dispatch'].forEach(function(k){
      if(p.stages && p.stages[k]) st.push('<b>'+k+'</b> '+f3(p.stages[k].qty)+' pcs · wage '+money(p.stages[k].wage));
    });
    document.getElementById('floorStages').innerHTML = st.join(' &nbsp;·&nbsp; ')
      + (p.wage>0 ? '<br><b>'+money(p.wage)+'</b> of wage over <b>'+f3(p.finished)+'</b> finished pieces = <b>'
         + money(p.wage_per_unit)+'</b> per unit, which is what the cost check below uses.'
       : '<br>No wage recorded on this line yet — the costing\'s own workmanship rate is used instead, and marked as an estimate.');
  }
  function recost(){
    var panel = document.getElementById('costPanel'), l = currentLine();
    if(!QUOTED || !QUOTED.known || !l){ panel.style.display='none'; return; }
    panel.style.display='';
    var p = l.prod || {wage_per_unit:0, finished:0, available:0};
    var made = num(outT.querySelector('.oqty') ? outT.querySelector('.oqty').value : 0);

    // over-convert warning (the server blocks it too)
    var fw = document.getElementById('floorWarn');
    if(made > p.available + 0.0005){
      fw.style.display='';
      fw.innerHTML = '<b>More than the floor has finished.</b> '+f3(made)+' asked for, '+f3(p.available)
        + ' available — production finished '+f3(p.finished)+' and '+f3(p.converted)
        + ' are already converted. This cannot be posted until the production is logged or the quantity is reduced.';
    } else if(fw) fw.style.display='none';

    var matTot = rows().reduce(function(s,tr){
      var i = idxOf(tr), a = ALLOC[i]||[];
      return s + a.reduce(function(x,y){ return x + (y.ownership==='customer'?0:y.qty*y.rate); }, 0);
    }, 0);
    var estimated = !(p.finished > 0 && p.wage > 0);
    var aMat  = made>0 ? matTot/made : 0;
    var aWage = estimated ? QUOTED.wage : p.wage_per_unit;
    var aOth  = QUOTED.other;
    var aTot  = aMat + aWage + aOth, qTot = QUOTED.total;
    var pct   = qTot>0 ? (aTot-qTot)/qTot*100 : 0;

    function row(lab,qv,av,note){
      var d = av-qv, pc = qv? d/qv*100 : (av?100:0);
      var col = Math.abs(pc)>TOL ? 'rgba(224,67,93,.13);color:#c0293f' : (Math.abs(pc)>TOL/2 ? 'rgba(217,119,6,.14);color:#b45309' : 'rgba(22,163,74,.14);color:#16a34a');
      return '<tr><td>'+lab+(note?' <span style="font-size:10.5px;color:#b45309;font-weight:700">'+note+'</span>':'')+'</td>'
        + '<td class="r">'+money(qv)+'</td><td class="r" style="font-weight:700">'+money(av)+'</td>'
        + '<td class="r">'+(d>=0?'+':'')+money(d)+'</td>'
        + '<td><span class="ic2-pill" style="background:'+col+'">'+(d>=0?'+':'')+pc.toFixed(1)+'%</span></td></tr>';
    }
    document.getElementById('costBody').innerHTML =
        row('Materials', QUOTED.material, aMat)
      + row('Workmanship', QUOTED.wage, aWage, estimated?'estimate — no production wage on this line yet':'')
      + row('Other', QUOTED.other, aOth)
      + '<tr><td style="font-weight:800;border-top:2px solid #cbd5e3">Total per unit</td>'
      + '<td class="r" style="font-weight:800;border-top:2px solid #cbd5e3">'+money(qTot)+'</td>'
      + '<td class="r" style="font-weight:800;border-top:2px solid #cbd5e3">'+money(aTot)+'</td>'
      + '<td class="r" style="font-weight:800;border-top:2px solid #cbd5e3">'+(aTot-qTot>=0?'+':'')+money(aTot-qTot)+'</td>'
      + '<td style="border-top:2px solid #cbd5e3"></td></tr>';

    document.getElementById('costSub').textContent =
      'Priced on costing version #' + QUOTED.version_id + ' · anything more than ' + TOL + '% away must be explained before posting.';

    var breach = Math.abs(pct) > TOL;
    document.getElementById('costAlert').innerHTML = breach
      ? '<div class="ic2-note bad" style="margin:0"><b>⚠ '+(pct>0?'Over':'Under')+' the costing by '+Math.abs(pct).toFixed(1)+'%.</b> '
        + 'Priced at '+money(qTot)+' per unit, running at '+money(aTot)+' — '+money(Math.abs(aTot-qTot))
        + ' per unit, '+money(Math.abs(aTot-qTot)*made)+' across the '+f3(made)+' pieces on this document. '
        + 'This is not blocked, but it cannot be posted without a reason.</div>'
      : '<div class="ic2-note ok" style="margin:0">Within '+TOL+'% of the costing ('+(pct>=0?'+':'')+pct.toFixed(1)+'%). Nothing to explain.</div>';
    document.getElementById('varWrap').style.display = breach ? '' : 'none';

    document.getElementById('qCpu').value = qTot.toFixed(4);
    document.getElementById('aCpu').value = aTot.toFixed(4);
    document.getElementById('vPct').value = pct.toFixed(2);
  }

  /* ---- wiring ------------------------------------------------------- */
  function wireRow(tr){
    get(tr,'select.mat').addEventListener('change', function(){
      var o = this.options[this.selectedIndex];
      /* The unit is the item's own; both come from the master rather
         than from the operator's memory. The rate itself is settled by
         the FIFO allocation a moment later, lot by lot — which is more
         accurate than any single number could be. */
      if(o && o.dataset.uom) get(tr,'.iuom').value = o.dataset.uom;
      get(tr,'.hMode').value = 'fifo';
      applyMaterialScope(); fillStandardQty(tr); refifo(tr);
    });
    ['.iqty','.iw'].forEach(function(s){
      get(tr,s).addEventListener('input', function(){ get(tr,'.hMode').value='fifo'; refifo(tr); });
    });
    get(tr,'.pick').addEventListener('click', function(){ openPicker(tr); });
    get(tr,'.refifo').addEventListener('click', function(){ get(tr,'.hMode').value='fifo'; get(tr,'.hRsn').value=''; refifo(tr); });
    get(tr,'.deli').addEventListener('click', function(){
      if(inT.children.length>1){ tr.remove(); } else { tr.querySelectorAll('input').forEach(function(x){x.value='';}); ALLOC={}; }
      reindex(); recalc();
    });
  }
  function reindex(){
    rows().forEach(function(tr,i){
      tr.dataset.i = i;
      tr.querySelectorAll('[name]').forEach(function(el){ el.name = el.name.replace(/inp\[\d+\]/, 'inp['+i+']'); });
    });
  }
  document.getElementById('addi').addEventListener('click', function(){
    var d = document.createElement('tbody'); d.innerHTML = rowTpl;
    var tr = d.firstElementChild;
    tr.querySelectorAll('input').forEach(function(x){ x.value = x.classList.contains('hOwn') ? 'own' : (x.classList.contains('hMode') ? 'fifo' : ''); });
    tr.querySelector('select.mat').selectedIndex = 0;
    inT.appendChild(tr); reindex(); wireRow(tr); applyMaterialScope(); paint(tr);
  });
  document.getElementById('addo').addEventListener('click', function(){
    var d = document.createElement('tbody'); d.innerHTML = outTpl;
    var tr = d.firstElementChild;
    tr.querySelectorAll('input').forEach(function(x){ x.value=''; });
    var n = outT.children.length;
    tr.querySelectorAll('[name]').forEach(function(el){ el.name = el.name.replace(/out\[\d+\]/, 'out['+n+']'); });
    outT.appendChild(tr); wireOut(tr); rebuildProductOptions();
  });
  function wireOut(tr){
    tr.querySelector('select.prod').addEventListener('change', function(){ onProductChange(this); });
    tr.querySelector('.oqty').addEventListener('input', function(){ rows().forEach(fillStandardQty); });
    var del = tr.querySelector('.delo');
    if(del) del.addEventListener('click', function(){
      if(outT.children.length>1) tr.remove(); else tr.querySelectorAll('input').forEach(function(x){x.value='';});
      applyMaterialScope();
    });
  }
  document.getElementById('loadstd').addEventListener('click', function(){
    var pid = currentProductId(), std = standardFor(pid);
    var msg = document.getElementById('stdmsg');
    if(!std || !Object.keys(std).length){
      msg.textContent = "No linked materials in this product's costing — nothing to load."; return;
    }
    var made = num(outT.querySelector('.oqty').value) || 0;
    inT.innerHTML = ''; ALLOC = {};
    Object.keys(std).forEach(function(mid, n){
      var d = document.createElement('tbody'); d.innerHTML = rowTpl;
      var tr = d.firstElementChild;
      tr.querySelectorAll('input').forEach(function(x){ x.value = x.classList.contains('hOwn') ? 'own' : (x.classList.contains('hMode') ? 'fifo' : ''); });
      tr.querySelector('select.mat').value = mid;
      var o = tr.querySelector('select.mat').options[tr.querySelector('select.mat').selectedIndex];
      if(o && o.dataset.uom) tr.querySelector('.iuom').value = o.dataset.uom;
      if(made>0){ tr.querySelector('.std').value = (std[mid]*made).toFixed(3); tr.querySelector('.iqty').value = (std[mid]*made).toFixed(3); }
      inT.appendChild(tr);
    });
    reindex(); rows().forEach(function(tr){ wireRow(tr); refifo(tr); });
    applyMaterialScope();
    msg.textContent = Object.keys(std).length + ' material(s) loaded from the costing and allocated oldest-lot-first.';
  });

  if(pfSel) pfSel.addEventListener('change', loadOrder);
  if(jwSel) jwSel.addEventListener('change', function(){
    // inbound job work uses the customer's material, which is usually not
    // in your costing at all — so stop fighting the narrowed list
    if(+this.value){ showAll.checked = true; applyMaterialScope(); }
  });
  showAll.addEventListener('change', applyMaterialScope);

  var frm = document.querySelector('form');
  if(frm) frm.addEventListener('submit', function(e){
    var l = currentLine();
    if(l && l.prod){
      var made = num(outT.querySelector('.oqty') ? outT.querySelector('.oqty').value : 0);
      if(made > l.prod.available + 0.0005){
        e.preventDefault();
        alert('The floor has finished '+f3(l.prod.finished)+' and '+f3(l.prod.converted)
          +' are already converted, so only '+f3(l.prod.available)+' can be converted now.\n\n'
          +'Log the production first, or reduce the quantity.');
        return;
      }
    }
    var pct = parseFloat(document.getElementById('vPct') ? document.getElementById('vPct').value : '0') || 0;
    var rsn = document.getElementById('varReason');
    if(Math.abs(pct) > TOL && rsn && !rsn.value.trim()){
      e.preventDefault();
      alert('Cost per unit is '+Math.abs(pct).toFixed(1)+'% away from the costing.\n\n'
        +'This is allowed — a real price change must be recordable — but write why, and it stays on the document.');
      rsn.focus();
    }
  });

  rows().forEach(function(tr){ wireRow(tr); });
  [].slice.call(outT.children).forEach(wireOut);
  /* Turn the material dropdowns into LOV fields before anything else
     paints, so a saved document reads as its items rather than flashing
     an empty box. loadOrder() then fetches the cascade and calls
     applyMaterialScope() again once the costing is known. */
  applyMaterialScope();
  loadOrder();
  rows().forEach(paint);
})();
</script>

<?php elseif ($doc): $isJw = ($doc['contract_type'] ?? '') === 'jobwork_in'; ?>
<div class="ic2-card">
  <div style="display:flex;justify-content:space-between;gap:14px;flex-wrap:wrap;align-items:flex-start">
    <div><h2 style="font-size:17px;margin:0;font-weight:800;font-family:monospace"><?= e($doc['con_no']) ?></h2>
      <p style="color:#8a97ab;font-size:12.5px;margin:5px 0 0"><?= e($doc['con_date']) ?> · <?= e(inv_location_name((int)$doc['location_id'])) ?>
        <?= $doc['pi_no'] ? ' · ' . e($doc['pi_no']) : '' ?><?= $doc['contract_no'] ? ' · ' . e($doc['contract_no']) : '' ?></p></div>
    <div style="display:flex;gap:8px;align-items:center">
      <span class="ic2-pill p-<?= e($doc['status']) ?>"><?= e(ucfirst($doc['status'])) ?></span>
      <?php if ($canEdit && $doc['status'] === 'draft'): ?><a class="ic2-btn sec" href="?id=<?= (int)$doc['id'] ?>&amp;edit=1">Edit</a><?php endif; ?>
      <a class="ic2-btn sec" href="inv_consume.php">Register</a>
    </div>
  </div>

  <?php if ($isJw): ?>
    <div class="ic2-note warn" style="margin-top:14px"><b>Inbound job work.</b> The material and the finished goods both belong to the customer, so neither side is valued in your books. You are billing the service, not selling the goods.</div>
  <?php endif; ?>

  <div class="ic2-side in" style="margin-top:16px">
    <h3>Materials consumed <span class="ic2-pill" style="background:rgba(224,67,93,.13);color:#c0293f">stock −</span></h3>
    <div style="overflow-x:auto"><table class="ic2-tbl">
      <thead><tr><th>Material</th><th>Lot</th><th class="r">Standard</th><th class="r">Actual</th><th class="r">Diff</th><th>UOM</th><th class="r">Waste</th><th class="r">Rate</th><th class="r">Value</th></tr></thead>
      <tbody>
      <?php $mc = 0; foreach ($inLines as $L): $mc += (float)$L['amount']; $d = (float)$L['qty'] - (float)$L['std_qty']; ?>
        <tr><td style="font-weight:600"><?= e($L['mcode']) ?> · <?= e($L['mname']) ?></td>
          <td style="font-family:monospace"><?= e($L['lot_no'] ?: '—') ?></td>
          <td class="r" style="color:#8a97ab"><?= (float)$L['std_qty'] > 0 ? number_format((float)$L['std_qty'], 3) : '—' ?></td>
          <td class="r"><b><?= number_format((float)$L['qty'], 3) ?></b></td>
          <td class="r" style="color:<?= $d > 0.0005 ? '#c0293f' : ($d < -0.0005 ? '#16a34a' : '#8a97ab') ?>">
            <?= (float)$L['std_qty'] > 0 ? (($d > 0 ? '+' : '') . number_format($d, 3)) : '—' ?></td>
          <td style="font-family:monospace"><?= e($L['uom'] ?: '') ?></td>
          <td class="r"><?= (float)$L['waste_qty'] > 0 ? number_format((float)$L['waste_qty'], 3) : '—' ?></td>
          <td class="r"><?= number_format((float)$L['rate'], 2) ?></td>
          <td class="r"><b><?= number_format((float)$L['amount'], 2) ?></b></td></tr>
      <?php endforeach; ?>
      </tbody>
      <tfoot><tr><td colspan="8" style="text-align:right;font-weight:800">Material cost</td><td class="r" style="font-weight:800;font-size:13.5px"><?= number_format($mc, 2) ?></td></tr></tfoot>
    </table></div>
  </div>

  <div class="ic2-side out">
    <h3>Finished product produced <span class="ic2-pill" style="background:rgba(22,163,74,.14);color:#16a34a">stock +</span></h3>
    <div style="overflow-x:auto"><table class="ic2-tbl">
      <thead><tr><th>Product</th><th>Size</th><th class="r">Produced</th><th>UOM</th><th class="r">Rejected</th><th class="r">Cost/unit</th><th class="r">Stock now</th></tr></thead>
      <tbody>
      <?php $tp = 0; foreach ($outLines as $L): $tp += (float)$L['qty']; ?>
        <tr><td style="font-weight:600"><?= e($L['pname']) ?></td>
          <td><?= e($L['size_label'] ?: '—') ?></td>
          <td class="r"><b><?= number_format((float)$L['qty'], 3) ?></b></td>
          <td style="font-family:monospace"><?= e($L['uom'] ?: '') ?></td>
          <td class="r"><?= (float)$L['waste_qty'] > 0 ? number_format((float)$L['waste_qty'], 0) : '—' ?></td>
          <td class="r"><?= number_format((float)$L['rate'], 2) ?></td>
          <td class="r" style="color:#5a6b82"><?= number_format(inv_balance(null, (int)$L['product_id'], null, $L['ownership']), 2) ?></td></tr>
      <?php endforeach; ?>
      </tbody>
    </table></div>
    <?php if ($tp > 0 && $mc > 0): ?>
      <p style="font-size:12px;color:#5a6b82;margin:11px 0 0">Material cost per piece: <b><?= number_format($mc / $tp, 2) ?></b></p>
    <?php endif; ?>
  </div>

  <div style="margin-top:18px;display:flex;gap:10px;flex-wrap:wrap;align-items:center">
    <?php if ($doc['status'] === 'draft'): ?>
      <?php if ($canPost): ?>
      <form method="post" onsubmit="return confirm('Post this? Materials will be deducted and the finished product added.');"><?= csrf_field() ?>
        <input type="hidden" name="action" value="post"><input type="hidden" name="id" value="<?= (int)$doc['id'] ?>">
        <button class="ic2-btn go" type="submit">Post — deduct materials, add product</button></form>
      <?php else: ?><span class="ic2-note info" style="margin:0">Saved as draft. Someone with posting permission must post it.</span><?php endif; ?>
    <?php elseif ($doc['status'] === 'posted'): ?>
      <div class="ic2-note ok" style="margin:0;flex:1">Posted <?= e((string)$doc['posted_at']) ?>. Materials deducted, finished product added.
        <br><span style="font-weight:400">A posted document is never edited or deleted. <b>Reverse</b> writes the opposite entries against the same lots with your reason; <b>Reverse &amp; re-open as draft</b> does the same and copies every line into a new draft, so correcting one figure does not mean re-typing the document.</span></div>
      <?php if ($canRev): ?>
      <form method="post" onsubmit="return this.reason.value.trim()!==''||(alert('A reason is required.'),false);" style="display:flex;gap:8px;align-items:center">
        <?= csrf_field() ?><input type="hidden" name="action" value="reverse"><input type="hidden" name="id" value="<?= (int)$doc['id'] ?>">
        <input class="ic2-inp" name="reason" placeholder="Reason" style="width:220px">
        <button class="ic2-btn warn" type="submit">Reverse</button>
        <button class="ic2-btn" type="submit" name="reopen" value="1"
          title="Reverses this document, then opens a new draft holding every line of it">Reverse &amp; re-open as draft</button></form>
      <?php endif; ?>
    <?php else: ?><div class="ic2-note bad" style="margin:0">Reversed.</div><?php endif; ?>
  </div>
</div>

<?php else: ?>
<div class="ic2-card">
  <div class="ic2-note info">
    <b>How this works.</b> Choose the product and how many were made, press <b>Load standard materials from costing</b>, and the fabric, accessories and packing appear with the quantities your costing says they should use. Correct anything that was actually different, then post — materials out and product in, together, in one movement.
  </div>
  <?php if (!$reg): ?>
    <p style="color:#8a97ab;font-size:13px;padding:26px 0;text-align:center">No consumption documents yet.
      <?php if ($canEdit): ?><br><br><a class="ic2-btn sec" href="?new=1">+ New Consumption</a><?php endif; ?></p>
  <?php else: ?>
  <div style="overflow-x:auto"><table class="ic2-tbl">
    <thead><tr><th>No.</th><th>Date</th><th>Order</th><th class="r">Material out</th><th class="r">Product in</th><th class="r">Material cost</th><th>Status</th><th></th></tr></thead>
    <tbody>
    <?php foreach ($reg as $r): ?>
      <tr<?= $r['status'] === 'reversed' ? ' style="opacity:.6"' : '' ?>>
        <td style="font-family:monospace;font-weight:700"><?= e($r['con_no']) ?></td>
        <td><?= e($r['con_date']) ?></td>
        <td style="font-size:11.5px"><?= e($r['pi_no'] ?: 'General stock') ?></td>
        <td class="r" style="color:#c0293f"><?= number_format((float)$r['tin'], 2) ?></td>
        <td class="r" style="color:#16a34a"><?= number_format((float)$r['tout'], 2) ?></td>
        <td class="r"><?= number_format((float)$r['tval'], 2) ?></td>
        <td><span class="ic2-pill p-<?= e($r['status']) ?>"><?= e(ucfirst($r['status'])) ?></span></td>
        <td style="text-align:right"><a class="ic2-btn sec" style="padding:5px 10px;font-size:11.5px" href="?id=<?= (int)$r['id'] ?>">Open</a></td>
      </tr>
    <?php endforeach; ?>
    </tbody>
  </table></div>
  <?php endif; ?>
</div>
<?php endif; ?>
<?php page_footer(); ?>
