<?php
/* Contracts — purchase, sales and job work in both directions.

   Balances are NEVER typed and never stored. Received / dispatched
   quantities are summed live from posted gate passes, so a contract can
   never disagree with the gate register. Until the gate module arrives
   (stage 3) those sums are simply zero, and the page says so rather than
   showing a figure it cannot yet support. */
require_once __DIR__ . '/includes/bootstrap.php';
require_login();
require_once __DIR__ . '/includes/inventory.php';
inv_ensure_schema();
if (!inv_can_see()) { http_response_code(403); exit('You do not have permission to view Contracts.'); }
$canEdit = inv_perm('master') || inv_perm('gate');

$TYPES = [
    'purchase'    => ['label' => 'Purchase',            'party' => 'supplier',  'prefix' => 'prefix_contract_pur',
                      'note'  => 'We buy material. Gate Inward reduces the balance.'],
    'sales'       => ['label' => 'Sales',               'party' => 'customer',  'prefix' => 'prefix_contract_sal',
                      'note'  => 'We sell goods. Gate Outward reduces the balance.'],
    'jobwork_out' => ['label' => 'Job Work — we send',  'party' => 'jobworker', 'prefix' => 'prefix_contract_jw',
                      'note'  => 'Our material goes out for processing and comes back. It stays our stock throughout.'],
    'jobwork_in'  => ['label' => 'Job Work — we do',    'party' => 'customer',  'prefix' => 'prefix_contract_jw',
                      'note'  => "Customer's material arrives, we process it and bill a service charge. Their material is never our stock."],
];

/* ---------------------------------------------------------------- save */
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verify_csrf();
    if (!$canEdit) { http_response_code(403); exit('You do not have permission to change contracts.'); }
    $act = $_POST['action'] ?? '';

    if ($act === 'save') {
        $id   = (int)($_POST['id'] ?? 0);
        $type = array_key_exists($_POST['contract_type'] ?? '', $TYPES) ? $_POST['contract_type'] : 'purchase';
        $no   = strtoupper(trim((string)($_POST['contract_no'] ?? '')));
        if ($no === '' && $id > 0) {
            try { $st = db()->prepare("SELECT contract_no FROM inv_contracts WHERE id=?"); $st->execute([$id]); $no = (string)$st->fetchColumn(); }
            catch (Throwable $e) {}
        }
        if ($no === '') $no = inv_next_no($TYPES[$type]['prefix'], 'inv_contracts', 'contract_no');

        $partyId = (int)($_POST['party_id'] ?? 0);
        $pfId    = (int)($_POST['proforma_id'] ?? 0);

        $f = [
            'contract_no' => $no,
            'contract_type' => $type,
            'contract_date' => ($_POST['contract_date'] ?? '') !== '' ? $_POST['contract_date'] : date('Y-m-d'),
            'party_id'    => $partyId > 0 ? $partyId : null,
            'proforma_id' => $pfId > 0 ? $pfId : null,
            'currency'    => strtoupper(trim((string)($_POST['currency'] ?? 'PKR'))) ?: 'PKR',
            'process'     => trim((string)($_POST['process'] ?? '')) ?: null,
            'wastage_pct' => inv_num($_POST['wastage_pct'] ?? 0),
            'expected_date' => ($_POST['expected_date'] ?? '') !== '' ? $_POST['expected_date'] : null,
            'terms'       => trim((string)($_POST['terms'] ?? '')) ?: null,
            'gst_applicable' => !empty($_POST['gst_applicable']) ? 1 : 0,
            'gst_pct'     => !empty($_POST['gst_applicable']) ? inv_num($_POST['gst_pct'] ?? inv_gst_default()) : null,
            'remarks'     => trim((string)($_POST['remarks'] ?? '')) ?: null,
            // falls back to active, matching the form's own default — a
            // contract that saved itself as draft could not be used anywhere
            'status'      => in_array($_POST['status'] ?? '', ['draft','active','closed','cancelled'], true) ? $_POST['status'] : 'active',
        ];

        try {
            db()->beginTransaction();
            if ($id > 0) {
                $sets = implode(',', array_map(fn($k) => "$k=?", array_keys($f)));
                $vals = array_values($f); $vals[] = $id;
                db()->prepare("UPDATE inv_contracts SET $sets, updated_at=NOW() WHERE id=?")->execute($vals);
            } else {
                $cols = implode(',', array_keys($f)) . ',created_by';
                $ph = implode(',', array_fill(0, count($f) + 1, '?'));
                $vals = array_values($f); $vals[] = (int)(current_user()['id'] ?? 0);
                db()->prepare("INSERT INTO inv_contracts ($cols) VALUES ($ph)")->execute($vals);
                $id = (int)db()->lastInsertId();
            }

            /* Lines are replaced wholesale — simplest correct way to sync a
               small editable grid without diffing row by row. */
            db()->prepare("DELETE FROM inv_contract_items WHERE contract_id=?")->execute([$id]);
            $ins = db()->prepare("INSERT INTO inv_contract_items
                (contract_id,material_id,product_id,return_material_id,description,qty,uom,rate,amount,sort_order)
                VALUES (?,?,?,?,?,?,?,?,?,?)");
            $n = 0;
            foreach ((array)($_POST['line'] ?? []) as $ln) {
                $qty  = inv_num($ln['qty'] ?? 0);
                $rate = inv_num($ln['rate'] ?? 0);
                $mid  = (int)($ln['material_id'] ?? 0);
                $pid  = (int)($ln['product_id'] ?? 0);
                $desc = trim((string)($ln['description'] ?? ''));
                if ($qty <= 0 && $mid <= 0 && $pid <= 0 && $desc === '') continue;
                $ins->execute([
                    $id, $mid > 0 ? $mid : null, $pid > 0 ? $pid : null,
                    (int)($ln['return_material_id'] ?? 0) > 0 ? (int)$ln['return_material_id'] : null,
                    $desc ?: null, $qty, trim((string)($ln['uom'] ?? '')) ?: null,
                    $rate, round($qty * $rate, 2), $n++,
                ]);
            }
            db()->commit();
            inv_audit('contract_save', $id, ['no' => $no, 'type' => $type, 'lines' => $n], 'Contract saved');
            $_SESSION['flash'] = 'Contract ' . $no . ' saved with ' . $n . ' line(s).';
        } catch (Throwable $ex) {
            if (db()->inTransaction()) db()->rollBack();
            $_SESSION['error'] = 'Could not save the contract — the contract number may already be in use.';
        }
        redirect('inv_contracts.php?edit=' . $id);
    }

    if ($act === 'status') {
        $id = (int)($_POST['id'] ?? 0);
        $to = in_array($_POST['to'] ?? '', ['draft','active','closed','cancelled'], true) ? $_POST['to'] : 'draft';
        try {
            db()->prepare("UPDATE inv_contracts SET status=?, updated_at=NOW() WHERE id=?")->execute([$to, $id]);
            inv_audit('contract_status', $id, $to, 'Contract status changed');
            $_SESSION['flash'] = 'Contract marked ' . $to . '.';
        } catch (Throwable $e) {}
        redirect('inv_contracts.php' . ($id ? '?edit=' . $id : ''));
    }
    /* A finished job stops appearing as pending anywhere. That is the
       point of closing it — even with quantity left over, by agreement. */
    if (($_POST['action'] ?? '') === 'close_contract') {
        $r = inv_contract_close((int)($_POST['id'] ?? 0), (string)($_POST['reason'] ?? ''));
        if ($r['ok']) $_SESSION['flash'] = 'Contract ' . $r['contract_no'] . ' closed. It no longer shows as pending anywhere.';
        else $_SESSION['error'] = $r['error'];
        redirect('inv_contracts.php?edit=' . (int)($_POST['id'] ?? 0));
    }
    if (($_POST['action'] ?? '') === 'reopen_contract') {
        $r = inv_contract_reopen((int)($_POST['id'] ?? 0), (string)($_POST['reason'] ?? ''));
        if ($r['ok']) $_SESSION['flash'] = 'Contract reopened.';
        else $_SESSION['error'] = $r['error'];
        redirect('inv_contracts.php?edit=' . (int)($_POST['id'] ?? 0));
    }

}

/* -------------------------------------------------- progress from gate */
/* Posted gate quantities against each contract. A purchase or inbound job
   work contract is progressed by inward passes; sales and outbound job
   work by outward passes. Returns [] cleanly if the gate tables are not
   populated yet. */
function inv_contract_progress(array $ids): array {
    if (!$ids) return [];
    $out = [];
    try {
        $in = implode(',', array_fill(0, count($ids), '?'));
        $st = db()->prepare("SELECT g.contract_id, g.direction, COALESCE(SUM(gi.qty),0) q
            FROM inv_gate g JOIN inv_gate_items gi ON gi.gate_id = g.id
            WHERE g.status='posted' AND g.contract_id IN ($in)
            GROUP BY g.contract_id, g.direction");
        $st->execute($ids);
        foreach ($st->fetchAll() as $r) {
            $out[(int)$r['contract_id']][$r['direction']] = (float)$r['q'];
        }
    } catch (Throwable $e) {}
    return $out;
}

/* --------------------------------------------------------------- lists */
$type = $_GET['type'] ?? '';
$q    = trim((string)($_GET['q'] ?? ''));
$stat = $_GET['status'] ?? '';

$where = []; $params = [];
if (array_key_exists($type, $TYPES)) { $where[] = 'c.contract_type = ?'; $params[] = $type; }
if ($stat !== '' && in_array($stat, ['draft','active','closed','cancelled'], true)) { $where[] = 'c.status = ?'; $params[] = $stat; }
if ($q !== '') { $where[] = '(c.contract_no LIKE ? OR p.name LIKE ?)'; $params[] = "%$q%"; $params[] = "%$q%"; }

$list = [];
try {
    $sql = "SELECT c.*, p.name party_name, pf.pi_no
        FROM inv_contracts c
        LEFT JOIN inv_parties p ON p.id = c.party_id
        LEFT JOIN proforma_invoices pf ON pf.id = c.proforma_id"
        . ($where ? ' WHERE ' . implode(' AND ', $where) : '')
        . " ORDER BY c.id DESC LIMIT 200";
    $st = db()->prepare($sql); $st->execute($params); $list = $st->fetchAll();
} catch (Throwable $e) {}

$totalsByContract = [];
try {
    if ($list) {
        $ids = array_map(fn($r) => (int)$r['id'], $list);
        $in = implode(',', array_fill(0, count($ids), '?'));
        $st = db()->prepare("SELECT contract_id, COALESCE(SUM(qty),0) q, COALESCE(SUM(amount),0) a, COUNT(*) n
            FROM inv_contract_items WHERE contract_id IN ($in) GROUP BY contract_id");
        $st->execute($ids);
        foreach ($st->fetchAll() as $r) $totalsByContract[(int)$r['contract_id']] = $r;
    }
} catch (Throwable $e) {}
$progress = inv_contract_progress(array_map(fn($r) => (int)$r['id'], $list));

$counts = [];
try { foreach (db()->query("SELECT contract_type, COUNT(*) n FROM inv_contracts GROUP BY contract_type")->fetchAll() as $r) $counts[$r['contract_type']] = (int)$r['n']; }
catch (Throwable $e) {}

/* ------------------------------------------------------------ the form */
$edit = null; $lines = [];
if (!empty($_GET['edit'])) {
    try {
        $st = db()->prepare("SELECT * FROM inv_contracts WHERE id=?"); $st->execute([(int)$_GET['edit']]);
        $edit = $st->fetch() ?: null;
        if ($edit) {
            $st2 = db()->prepare("SELECT * FROM inv_contract_items WHERE contract_id=? ORDER BY sort_order, id");
            $st2->execute([(int)$edit['id']]); $lines = $st2->fetchAll();
        }
    } catch (Throwable $e) {}
}
$newType = array_key_exists($_GET['new'] ?? '', $TYPES) ? $_GET['new'] : '';
$showForm = $canEdit && ($edit || $newType !== '');
$formType = $edit ? $edit['contract_type'] : ($newType ?: 'purchase');

$materials = $showForm ? inv_materials(true) : [];
$parties   = $showForm ? inv_parties($TYPES[$formType]['party']) : [];
$products  = [];
$proformas = [];
if ($showForm) {
    try { $products = db()->query("SELECT id,name FROM products WHERE is_active=1 ORDER BY name LIMIT 500")->fetchAll(); } catch (Throwable $e) {}
    try { $proformas = db()->query("SELECT id,pi_no,customer_name FROM proforma_invoices ORDER BY id DESC LIMIT 300")->fetchAll(); } catch (Throwable $e) {}
}
if ($showForm && !$lines) $lines = [[]];

page_header('Contracts');
flash();
?>
<div class="topbar">
  <div><h1>Contracts</h1><p class="lead">Purchase, sales and job work — both the work you send out and the work you do for others. Every one is optional: a gate pass never requires a contract.</p></div>
  <?php if ($canEdit && !$showForm): ?>
  <div style="display:flex;gap:8px;flex-wrap:wrap">
    <?php foreach ($TYPES as $k => $T): ?><a class="zbtn sec" href="?new=<?= e($k) ?>">+ <?= $T['label'] ?></a><?php endforeach; ?>
  </div>
  <?php endif; ?>
</div>

<style>
.ic-card{background:#fff;border:1px solid #e3e9f2;border-radius:16px;padding:13px 15px;margin-bottom:11px;box-shadow:0 10px 30px rgba(15,35,65,.06)}
.ic-bar{display:flex;gap:14px;flex-wrap:wrap;align-items:flex-end;background:#fff;border:1px solid #e3e9f2;border-radius:14px;padding:14px 16px;margin-bottom:16px}
.ic-tabs{display:flex;gap:4px;background:#eef1f6;padding:4px;border-radius:11px;flex-wrap:wrap}
.ic-tab{padding:7px 13px;border-radius:8px;font-size:12px;font-weight:700;text-decoration:none;color:#5a6b82}
.ic-tab.on{background:#fff;color:#152033;box-shadow:0 1px 3px rgba(20,30,50,.12)}
.ic-inp{padding:9px 11px;border-radius:9px;border:1px solid #cbd5e3;font-size:12.5px;font-family:inherit;width:100%}
.ic-lbl{display:block;font-size:10.5px;color:#8a97ab;font-weight:800;text-transform:uppercase;letter-spacing:.04em;margin-bottom:5px}
.ic-tbl{width:100%;border-collapse:collapse;font-size:12.5px}
.ic-tbl th{text-align:left;font-size:10px;text-transform:uppercase;letter-spacing:.05em;color:#8a97ab;font-weight:800;padding:0 8px 5px;white-space:nowrap}
.ic-tbl td{padding:3px 8px;border-top:1px solid #eef1f7;vertical-align:top}
.ic-tbl td.r,.ic-tbl th.r{text-align:right;font-variant-numeric:tabular-nums}
.ic-btn{padding:9px 16px;border:none;border-radius:10px;background:linear-gradient(100deg,#0ea8c9,#6d5bd0);color:#fff;font-weight:700;font-size:12.5px;cursor:pointer}
.ic-btn.sec{background:#fff;color:#152033;border:1px solid #cbd5e3;text-decoration:none;display:inline-block}
.ic-pill{display:inline-block;font-size:10px;font-weight:800;padding:3px 8px;border-radius:20px;white-space:nowrap}
.c-purchase{background:rgba(14,168,201,.10);color:#0b7f9b}
.c-sales{background:rgba(22,163,74,.10);color:#16a34a}
.c-jobwork_out{background:rgba(109,91,208,.10);color:#5a4bb8}
.c-jobwork_in{background:rgba(217,119,6,.12);color:#a8630a}
.s-draft{background:#f0f3f9;color:#5a6b82}
.s-active{background:rgba(14,168,201,.12);color:#0b7f9b}
.s-closed{background:rgba(22,163,74,.10);color:#16a34a}
.s-cancelled{background:rgba(224,67,93,.10);color:#c0293f}
.ic-grid{display:grid;gap:13px;grid-template-columns:repeat(4,1fr)}
@media(max-width:1000px){.ic-grid{grid-template-columns:repeat(2,1fr)}}
@media(max-width:640px){.ic-grid{grid-template-columns:1fr}}
.ic-note{border-radius:11px;padding:11px 14px;font-size:12.5px;line-height:1.6;margin-bottom:14px}
.ic-note.info{background:rgba(14,168,201,.07);border:1px solid rgba(14,168,201,.2);color:#2c4a63}
.ic-note.warn{background:rgba(217,119,6,.09);border:1px solid rgba(217,119,6,.25);color:#7a4d09}
.ic-bar2{height:6px;border-radius:4px;background:#eef1f7;overflow:hidden;margin-top:5px}
.ic-bar2 i{display:block;height:100%;border-radius:4px;background:linear-gradient(90deg,#0ea8c9,#6d5bd0)}
</style>
<?php /* THE SKIN, OPTED IN. Every rule in assets/css/zskin.css is scoped
         under .zskin, so this one attribute is the whole of the restyle and
         removing it puts the page back exactly as it was. The page keeps its
         own .ic-* names; the skin maps onto them. */ ?>
<div class="zskin">

<?php if ($showForm): $E = $edit ?: []; $T = $TYPES[$formType]; $isJob = strpos($formType, 'jobwork') === 0; $isSales = $formType === 'sales'; ?>
<div class="ic-card">
  <h2 style="font-size:15.5px;margin:0 0 4px;font-weight:800"><?= $edit ? 'Edit ' . $T['label'] . ' — ' . e($edit['contract_no']) : 'New ' . $T['label'] . ' contract' ?></h2>
  <p style="color:#8a97ab;font-size:12px;margin:0 0 14px"><?= $T['note'] ?></p>

  <?php if ($edit):
    /* What has actually moved against this contract, line by line. Only
       POSTED gate passes count, and only those booked against a line. */
    $CB = inv_contract_lines((int)$edit['id']);
    $CU = inv_contract_unassigned((int)$edit['id']);
    $cq = 0.0; $cd = 0.0; foreach ($CB as $b) { $cq += $b['qty']; $cd += $b['done']; }
    $cpct = $cq > 0 ? round($cd / $cq * 100) : 0;
  ?>
  <div style="border:1px solid #e3e9f2;border-radius:13px;padding:15px 17px;margin-bottom:16px;background:#f6f8fc">
    <div style="display:flex;justify-content:space-between;gap:12px;flex-wrap:wrap;align-items:center;margin-bottom:11px">
      <div><b style="font-size:13px">Contract ledger</b>
        <span style="font-size:11.5px;color:#8a97ab">· <?= (int)$cpct ?>% delivered · posted gate passes only</span></div>
      <div style="display:flex;gap:8px;flex-wrap:wrap;align-items:center">
        <a class="ic-btn sec" href="inv_contract_print.php?id=<?= (int)$edit['id'] ?>" target="_blank"
           title="Everything: balances, completion and the ledger">Full report — with status</a>
        <a class="ic-btn sec" href="inv_contract_print.php?id=<?= (int)$edit['id'] ?>&amp;view=share" target="_blank"
           title="The contract only — no balances, no ledger">Contract only — to share</a>
        <?php if ($edit['status'] === 'closed'): ?>
          <form method="post" style="display:flex;gap:6px;align-items:center"
                onsubmit="return this.reason.value.trim()!==''||(alert('Say why it is being reopened.'),false);">
            <?= csrf_field() ?><input type="hidden" name="action" value="reopen_contract">
            <input type="hidden" name="id" value="<?= (int)$edit['id'] ?>">
            <input class="ic-inp" name="reason" placeholder="reason to reopen" style="width:170px">
            <button class="ic-btn sec" type="submit">Reopen</button>
          </form>
        <?php else: ?>
          <form method="post" style="display:flex;gap:6px;align-items:center"
                onsubmit="return this.reason.value.trim()!==''||(alert('Say why the job is being closed.'),false);">
            <?= csrf_field() ?><input type="hidden" name="action" value="close_contract">
            <input type="hidden" name="id" value="<?= (int)$edit['id'] ?>">
            <input class="ic-inp" name="reason" placeholder="reason to close" style="width:170px">
            <button class="ic-btn" type="submit" title="Stops it appearing as pending anywhere, even with balance left">Close this job</button>
          </form>
        <?php endif; ?>
      </div>
    </div>
    <?php if (!$CB): ?>
      <p style="font-size:12px;color:#8a97ab;margin:0">No lines on this contract yet — add them below and save.</p>
    <?php else: ?>
    <div style="overflow-x:auto"><table class="ic-tbl">
      <thead><tr><th>Item</th><th>Description</th><th class="r">Contracted</th><th class="r">Done</th><th class="r">Balance</th><th class="r">Rate</th></tr></thead>
      <tbody>
      <?php foreach ($CB as $b): ?>
        <tr>
          <td style="font-weight:600"><?= e($b['item']) ?></td>
          <td style="font-size:11.5px;color:#5a6b82"><?= e($b['description'] !== '' ? $b['description'] : '—') ?></td>
          <td class="r"><?= number_format($b['qty'], 3) ?></td>
          <td class="r"><?= number_format($b['done'], 3) ?></td>
          <td class="r" style="font-weight:700;color:<?= $b['over'] ? '#c0293f' : ($b['balance'] <= 0.0005 ? '#16a34a' : '#b45309') ?>">
            <?= $b['over'] ? 'over by ' . number_format($b['done'] - $b['qty'], 3) : number_format($b['balance'], 3) ?></td>
          <td class="r"><?= number_format($b['rate'], 2) ?></td>
        </tr>
      <?php endforeach; ?>
      </tbody>
    </table></div>
    <?php endif; ?>
    <?php if ($CU['lines'] > 0): ?>
      <div class="ic-note warn" style="margin-top:11px"><b><?= (int)$CU['lines'] ?> posted gate line(s)</b> name this contract
        but not a particular line, so they are not counted above (<?= number_format($CU['qty'], 3) ?> qty).
        Use <b>Pull lines from contract</b> on the gate pass so each delivery matches the line it satisfies.</div>
    <?php endif; ?>
  </div>
  <?php endif; ?>
  <?php if ($formType === 'jobwork_in'): ?>
    <div class="ic-note warn"><b>Customer-owned material.</b> Anything received against this contract is counted in quantity but never valued as your stock, and your service charge is billed on what you deliver back.</div>
  <?php endif; ?>

  <form method="post"><?= csrf_field() ?>
    <input type="hidden" name="action" value="save">
    <input type="hidden" name="id" value="<?= (int)($E['id'] ?? 0) ?>">
    <input type="hidden" name="contract_type" value="<?= e($formType) ?>">

    <div class="ic-grid">
      <div><label class="ic-lbl">Contract no.</label><input class="ic-inp" name="contract_no" value="<?= e($E['contract_no'] ?? '') ?>" placeholder="auto" style="font-family:monospace"></div>
      <div><label class="ic-lbl">Date</label><input class="ic-inp" type="date" name="contract_date" value="<?= e($E['contract_date'] ?? date('Y-m-d')) ?>"></div>
      <div><label class="ic-lbl"><?= $formType === 'purchase' ? 'Supplier' : ($isJob && $formType === 'jobwork_out' ? 'Job worker' : 'Customer') ?></label>
        <select class="ic-inp" name="party_id">
          <option value="0">— none —</option>
          <?php foreach ($parties as $p): ?><option value="<?= (int)$p['id'] ?>" <?= (int)($E['party_id'] ?? 0) === (int)$p['id'] ? 'selected' : '' ?>><?= e($p['name']) ?></option><?php endforeach; ?>
        </select>
        <p style="font-size:10.5px;color:#8a97ab;margin:5px 0 0">Not listed? <a href="inv_parties.php?new=1">Add a party</a></p></div>
      <div><label class="ic-lbl">Currency</label><input class="ic-inp" name="currency" value="<?= e($E['currency'] ?? 'PKR') ?>" maxlength="8" style="font-family:monospace"></div>

      <div style="grid-column:span 2"><label class="ic-lbl">Back-link to order — optional</label>
        <select class="ic-inp" name="proforma_id">
          <option value="0">— none, general —</option>
          <?php foreach ($proformas as $pf): ?>
            <option value="<?= (int)$pf['id'] ?>" <?= (int)($E['proforma_id'] ?? 0) === (int)$pf['id'] ? 'selected' : '' ?>><?= e($pf['pi_no']) ?> · <?= e($pf['customer_name']) ?></option>
          <?php endforeach; ?>
        </select></div>
      <div><label class="ic-lbl"><?= $isJob ? 'Expected return' : 'Delivery by' ?></label><input class="ic-inp" type="date" name="expected_date" value="<?= e($E['expected_date'] ?? '') ?>"></div>
      <div><label class="ic-lbl">Status</label>
        <?php /* A NEW contract opens as Active, because writing a contract
                 is the act of agreeing one — the old default of Draft meant
                 every contract was invisible to Gate passes the moment it
                 was saved, with nothing to say why. Draft is still there
                 for a deal genuinely not agreed yet. An existing contract
                 always keeps the status it has. */ ?>
        <select class="ic-inp" name="status">
          <?php foreach (['active' => 'Active — can be used on gate passes',
                          'draft' => 'Draft — not yet agreed, cannot be used on a gate pass',
                          'closed' => 'Closed', 'cancelled' => 'Cancelled'] as $k => $lbl): ?>
            <option value="<?= e($k) ?>" <?= ($E['status'] ?? 'active') === $k ? 'selected' : '' ?>><?= e($lbl) ?></option>
          <?php endforeach; ?>
        </select></div>

      <?php if ($isJob): ?>
      <div style="grid-column:span 2"><label class="ic-lbl">Process</label><input class="ic-inp" name="process" value="<?= e($E['process'] ?? '') ?>" placeholder="Bleach &amp; dye / Cut, make &amp; pack"></div>
      <div><label class="ic-lbl">Allowed wastage %</label><input class="ic-inp" name="wastage_pct" value="<?= e((string)($E['wastage_pct'] ?? '0')) ?>" style="text-align:right"></div>
      <div></div>
      <?php endif; ?>

      <div><label class="ic-lbl">Sales tax</label>
        <div style="display:flex;gap:8px;align-items:center">
          <label style="display:inline-flex;align-items:center;gap:7px;font-size:12.5px;color:#5a6b82;cursor:pointer;white-space:nowrap">
            <input type="checkbox" name="gst_applicable" value="1" <?= !empty($E['gst_applicable']) ? 'checked' : '' ?>> GST
          </label>
          <input class="ic-inp" name="gst_pct" style="width:82px;text-align:right" value="<?= e((string)($E['gst_pct'] ?? inv_gst_default())) ?>">
          <span style="font-size:12px;color:#8a97ab">%</span>
        </div>
        <p style="font-size:10.5px;color:#8a97ab;margin:5px 0 0">Carried onto gate passes pulled from this contract.</p></div>
      <div style="grid-column:span 2"><label class="ic-lbl">Terms</label><input class="ic-inp" name="terms" value="<?= e($E['terms'] ?? '') ?>" placeholder="30 days from receipt"></div>
      <div style="grid-column:span 2"><label class="ic-lbl">Remarks</label><input class="ic-inp" name="remarks" value="<?= e($E['remarks'] ?? '') ?>"></div>
    </div>

    <h3 style="font-size:12px;text-transform:uppercase;letter-spacing:.06em;color:#8a97ab;margin:22px 0 10px">Contract lines</h3>
    <div style="overflow-x:auto"><table class="ic-tbl" id="lines">
      <thead><tr>
        <th style="min-width:210px"><?= $isSales ? 'Finished product' : 'Material' ?></th>
        <?php if ($formType === 'jobwork_out'): ?><th style="min-width:180px">Expected back as</th><?php endif; ?>
        <th style="min-width:150px">Description</th>
        <th class="r" style="width:110px">Quantity</th><th style="width:80px">UOM</th>
        <th class="r" style="width:110px">Rate</th><th class="r" style="width:120px">Amount</th><th style="width:34px"></th>
      </tr></thead>
      <tbody>
      <?php foreach ($lines as $i => $L): ?>
        <tr>
          <td>
            <?php if ($isSales): ?>
              <select class="ic-inp" name="line[<?= $i ?>][product_id]">
                <option value="0">— select product —</option>
                <?php foreach ($products as $pr): ?><option value="<?= (int)$pr['id'] ?>" <?= (int)($L['product_id'] ?? 0) === (int)$pr['id'] ? 'selected' : '' ?>><?= e($pr['name']) ?></option><?php endforeach; ?>
              </select>
            <?php else: ?>
              <select class="ic-inp" name="line[<?= $i ?>][material_id]">
                <option value="0">— select material —</option>
                <?php foreach ($materials as $m): ?><option value="<?= (int)$m['id'] ?>" data-uom="<?= e($m['uom']) ?>" data-rate="<?= e((string)$m['std_rate']) ?>" <?= (int)($L['material_id'] ?? 0) === (int)$m['id'] ? 'selected' : '' ?>><?= e($m['code']) ?> · <?= e($m['name']) ?></option><?php endforeach; ?>
              </select>
            <?php endif; ?>
          </td>
          <?php if ($formType === 'jobwork_out'): ?>
          <td><select class="ic-inp" name="line[<?= $i ?>][return_material_id]">
              <option value="0">— same material —</option>
              <?php foreach ($materials as $m): ?><option value="<?= (int)$m['id'] ?>" <?= (int)($L['return_material_id'] ?? 0) === (int)$m['id'] ? 'selected' : '' ?>><?= e($m['code']) ?> · <?= e($m['name']) ?></option><?php endforeach; ?>
            </select></td>
          <?php endif; ?>
          <td><input class="ic-inp" name="line[<?= $i ?>][description]" value="<?= e($L['description'] ?? '') ?>"></td>
          <td><input class="ic-inp qty" name="line[<?= $i ?>][qty]" value="<?= e((string)($L['qty'] ?? '')) ?>" style="text-align:right"></td>
          <td><input class="ic-inp uom" name="line[<?= $i ?>][uom]" value="<?= e($L['uom'] ?? '') ?>" style="font-family:monospace"></td>
          <td><input class="ic-inp rate" name="line[<?= $i ?>][rate]" value="<?= e((string)($L['rate'] ?? '')) ?>" style="text-align:right"></td>
          <td class="r amt" style="padding-top:16px;font-weight:700"><?= number_format((float)($L['amount'] ?? 0), 2) ?></td>
          <td style="padding-top:12px"><button type="button" class="ic-btn sec del" style="padding:5px 9px;font-size:12px;cursor:pointer">×</button></td>
        </tr>
      <?php endforeach; ?>
      </tbody>
      <tfoot><tr>
        <td colspan="<?= $formType === 'jobwork_out' ? 5 : 4 ?>" style="text-align:right;font-weight:800">Contract value</td>
        <td class="r" colspan="2" style="font-weight:800;font-size:13.5px" id="grand">0.00</td><td></td>
      </tr></tfoot>
    </table></div>

    <div style="margin-top:14px;display:flex;gap:10px;flex-wrap:wrap;align-items:center">
      <button type="button" class="ic-btn sec" id="addLine">+ Add line</button>
      <button class="ic-btn" type="submit">Save contract</button>
      <a class="ic-btn sec" href="inv_contracts.php">Back to list</a>
    </div>
  </form>
</div>

<script>
(function(){
  var tb=document.querySelector('#lines tbody');
  function recalc(){
    var g=0;
    tb.querySelectorAll('tr').forEach(function(tr){
      var q=parseFloat((tr.querySelector('.qty')||{}).value)||0;
      var r=parseFloat((tr.querySelector('.rate')||{}).value)||0;
      var a=q*r; g+=a;
      var c=tr.querySelector('.amt'); if(c) c.textContent=a.toLocaleString(undefined,{minimumFractionDigits:2,maximumFractionDigits:2});
    });
    document.getElementById('grand').textContent=g.toLocaleString(undefined,{minimumFractionDigits:2,maximumFractionDigits:2});
  }
  tb.addEventListener('input',recalc);
  /* Picking a material fills its unit and standard rate — the two fields
     people most often get wrong when typing them by hand. */
  tb.addEventListener('change',function(e){
    var s=e.target; if(s.tagName!=='SELECT'||s.name.indexOf('[material_id]')<0) return;
    var o=s.options[s.selectedIndex]; if(!o||!o.dataset.uom) return;
    var tr=s.closest('tr');
    var u=tr.querySelector('.uom'), r=tr.querySelector('.rate');
    if(u&&!u.value) u.value=o.dataset.uom;
    if(r&&!parseFloat(r.value)) r.value=o.dataset.rate;
    recalc();
  });
  tb.addEventListener('click',function(e){
    if(!e.target.classList.contains('del')) return;
    if(tb.children.length>1) e.target.closest('tr').remove(); else tb.querySelectorAll('input').forEach(function(i){i.value='';});
    recalc();
  });
  document.getElementById('addLine').addEventListener('click',function(){
    var last=tb.lastElementChild, n=tb.children.length;
    var clone=last.cloneNode(true);
    clone.querySelectorAll('input,select').forEach(function(el){
      el.name=el.name.replace(/line\[\d+\]/,'line['+n+']');
      if(el.tagName==='INPUT') el.value=''; else el.selectedIndex=0;
    });
    var a=clone.querySelector('.amt'); if(a) a.textContent='0.00';
    tb.appendChild(clone);
    var f=clone.querySelector('select,input'); if(f) f.focus();
  });
  recalc();
})();
</script>

<?php else: ?>

<div class="ic-bar">
  <div class="ic-tabs">
    <a class="ic-tab <?= $type === '' ? 'on' : '' ?>" href="?">All</a>
    <?php foreach ($TYPES as $k => $T): ?>
      <a class="ic-tab <?= $type === $k ? 'on' : '' ?>" href="?type=<?= e($k) ?>"><?= $T['label'] ?> <?= (int)($counts[$k] ?? 0) ?></a>
    <?php endforeach; ?>
  </div>
  <form method="get" style="display:flex;gap:10px;align-items:flex-end;flex-wrap:wrap">
    <?php if ($type !== ''): ?><input type="hidden" name="type" value="<?= e($type) ?>"><?php endif; ?>
    <div><label class="ic-lbl">Search</label><input class="ic-inp" name="q" value="<?= e($q) ?>" placeholder="contract no. or party" style="width:auto"></div>
    <div><label class="ic-lbl">Status</label>
      <select class="ic-inp" name="status" style="width:auto">
        <option value="">Any</option>
        <?php foreach (['draft','active','closed','cancelled'] as $s): ?><option value="<?= e($s) ?>" <?= $stat === $s ? 'selected' : '' ?>><?= e(ucfirst($s)) ?></option><?php endforeach; ?>
      </select></div>
    <button class="ic-btn sec" type="submit" style="cursor:pointer">Search</button>
  </form>
</div>

<div class="ic-card">
  <?php if (!$list): ?>
    <p style="color:#8a97ab;font-size:13px;padding:26px 0;text-align:center">No contracts yet.<?php if ($canEdit): ?><br><br>
      <?php foreach ($TYPES as $k => $T): ?><a class="ic-btn sec" style="margin:0 4px" href="?new=<?= e($k) ?>">+ <?= $T['label'] ?></a><?php endforeach; ?>
    <?php endif; ?></p>
  <?php else: ?>
  <div style="overflow-x:auto"><table class="ic-tbl">
    <thead><tr><th>Contract</th><th>Type</th><th>Party</th><th>Order</th><th class="r">Qty</th><th class="r">Done</th><th class="r">Balance</th><th class="r">Value</th><th>Status</th><th></th></tr></thead>
    <tbody>
    <?php foreach ($list as $c):
      $tot = $totalsByContract[(int)$c['id']] ?? ['q' => 0, 'a' => 0, 'n' => 0];
      $qty = (float)$tot['q'];
      $dir = in_array($c['contract_type'], ['purchase', 'jobwork_in'], true) ? 'in' : 'out';
      $done = (float)($progress[(int)$c['id']][$dir] ?? 0);
      $bal = max(0, $qty - $done);
      $pct = $qty > 0 ? min(100, round($done / $qty * 100)) : 0;
    ?>
      <tr>
        <td style="font-family:monospace;font-weight:700"><?= e($c['contract_no']) ?><br><span style="font-size:11px;color:#8a97ab;font-family:inherit"><?= e($c['contract_date']) ?></span></td>
        <td><span class="ic-pill c-<?= e($c['contract_type']) ?>"><?= $TYPES[$c['contract_type']]['label'] ?? e($c['contract_type']) ?></span>
            <?php if ($c['process']): ?><br><span style="font-size:11px;color:#8a97ab"><?= e($c['process']) ?></span><?php endif; ?></td>
        <td style="font-weight:600"><?= e($c['party_name'] ?: '—') ?></td>
        <td><?= $c['pi_no'] ? '<span class="ic-pill s-draft">' . e($c['pi_no']) . '</span>' : '<span style="color:#8a97ab;font-size:11.5px">Direct / general</span>' ?></td>
        <td class="r"><?= number_format($qty, 2) ?><br><span style="font-size:11px;color:#8a97ab"><?= (int)$tot['n'] ?> line(s)</span></td>
        <td class="r"><?= $done > 0 ? number_format($done, 2) : '<span style="color:#8a97ab">—</span>' ?></td>
        <td class="r"><?= $qty > 0 ? number_format($bal, 2) : '—' ?>
          <?php if ($qty > 0): ?><div class="ic-bar2"><i style="width:<?= $pct ?>%"></i></div><?php endif; ?></td>
        <td class="r"><?= number_format((float)$tot['a'], 2) ?><br><span style="font-size:11px;color:#8a97ab"><?= e($c['currency']) ?></span></td>
        <td><span class="ic-pill s-<?= e($c['status']) ?>"><?= e(ucfirst($c['status'])) ?></span>
          <?php if ($c['status'] === 'draft'): ?>
            <div style="font-size:10.5px;color:#9a3412;margin-top:3px;max-width:130px;line-height:1.5">Not usable on a gate pass yet</div>
          <?php endif; ?></td>
        <td style="text-align:right;white-space:nowrap">
          <?php if ($canEdit && $c['status'] === 'draft'): ?>
          <form method="post" style="display:inline"><?= csrf_field() ?>
            <input type="hidden" name="action" value="status">
            <input type="hidden" name="id" value="<?= (int)$c['id'] ?>">
            <input type="hidden" name="to" value="active">
            <button class="ic-btn" style="padding:5px 10px;font-size:11.5px" type="submit"
                    title="Make this contract usable on gate passes">Activate</button>
          </form>
          <?php endif; ?>
          <a class="ic-btn sec" style="padding:5px 10px;font-size:11.5px" href="inv_contract_print.php?id=<?= (int)$c['id'] ?>" target="_blank" title="Full report with status">Status</a>
          <a class="ic-btn sec" style="padding:5px 10px;font-size:11.5px" href="inv_contract_print.php?id=<?= (int)$c['id'] ?>&amp;view=share" target="_blank" title="Contract only, to share">Share</a>
          <?php if ($canEdit): ?><a class="ic-btn sec" style="padding:5px 10px;font-size:11.5px" href="?edit=<?= (int)$c['id'] ?>">Open</a><?php endif; ?></td>
      </tr>
    <?php endforeach; ?>
    </tbody>
  </table></div>

  <div class="ic-note info" style="margin:16px 0 0">
    <b>Where "Done" and "Balance" come from:</b> they are summed live from <b>posted</b> gate passes against each contract — never typed, never stored. Gate passes arrive in stage 3, so these columns read “—” until then. A draft gate pass will never count toward them.
  </div>
  <?php endif; ?>
</div>
<?php endif; ?>
</div><?php /* closes .zskin */ ?>
<?php page_footer(); ?>
