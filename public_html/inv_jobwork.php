<?php
/* Job Work Charges — both directions.

   Receivable: we processed a customer's material and bill them a service
               charge. Billable quantity comes from posted "job work
               delivered" gate passes, so it is never typed.
   Payable:    a processor worked on our material and bills us. What they
               billed is checked against what actually came back through
               our gate — two independent facts, so a difference shows. */
require_once __DIR__ . '/includes/bootstrap.php';
require_login();
require_once __DIR__ . '/includes/inventory.php';
inv_ensure_schema();
if (!inv_can_see()) { http_response_code(403); exit('You do not have permission to view job work charges.'); }
$canEdit = inv_perm('gate') || inv_perm('master');

$dir = ($_GET['dir'] ?? $_POST['direction'] ?? 'receivable') === 'payable' ? 'payable' : 'receivable';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verify_csrf();
    if (!$canEdit) { http_response_code(403); exit('Not permitted.'); }
    $act = $_POST['action'] ?? '';

    if ($act === 'save') {
        $id = (int)($_POST['id'] ?? 0);
        $no = strtoupper(trim((string)($_POST['bill_no'] ?? '')));
        if ($no === '' && $id > 0) { try { $s = db()->prepare("SELECT bill_no FROM inv_jobwork_charges WHERE id=?"); $s->execute([$id]); $no = (string)$s->fetchColumn(); } catch (Throwable $e) {} }
        if ($no === '') $no = inv_next_no('prefix_jobwork_bill', 'inv_jobwork_charges', 'bill_no');
        $qty  = inv_num($_POST['billed_qty'] ?? 0);
        $rate = inv_num($_POST['rate'] ?? 0);
        $f = [
            'bill_no' => $no, 'direction' => $dir,
            'bill_date' => ($_POST['bill_date'] ?? '') !== '' ? $_POST['bill_date'] : date('Y-m-d'),
            'contract_id' => (int)($_POST['contract_id'] ?? 0) ?: null,
            'party_id'    => (int)($_POST['party_id'] ?? 0) ?: null,
            'gate_id'     => (int)($_POST['gate_id'] ?? 0) ?: null,
            'their_bill_no' => trim((string)($_POST['their_bill_no'] ?? '')) ?: null,
            'description' => trim((string)($_POST['description'] ?? '')) ?: null,
            'billed_qty'  => $qty,
            'received_qty' => inv_num($_POST['received_qty'] ?? 0),
            'uom'  => trim((string)($_POST['uom'] ?? '')) ?: null,
            'rate' => $rate, 'amount' => round($qty * $rate, 2),
            'currency' => strtoupper(trim((string)($_POST['currency'] ?? 'PKR'))) ?: 'PKR',
            'status' => in_array($_POST['status'] ?? '', ['draft','raised','settled','query','cancelled'], true) ? $_POST['status'] : 'draft',
            'remarks' => trim((string)($_POST['remarks'] ?? '')) ?: null,
            // varies by contract, so it is chosen per bill
            'charge_basis' => in_array($_POST['charge_basis'] ?? '', ['sent','returned','accepted'], true) ? $_POST['charge_basis'] : 'returned',
            // the negotiated settlement of excess loss — typed, never computed
            'adjustment_amount' => inv_num($_POST['adjustment_amount'] ?? 0),
            'adjustment_note'   => mb_substr(trim((string)($_POST['adjustment_note'] ?? '')), 0, 300) ?: null,
            'gst_applicable' => !empty($_POST['gst_applicable']) ? 1 : 0,
            'gst_pct' => !empty($_POST['gst_applicable']) ? inv_num($_POST['gst_pct'] ?? inv_gst_default()) : null,
        ];
        try {
            if ($id > 0) {
                $sets = implode(',', array_map(fn($k) => "$k=?", array_keys($f)));
                $vals = array_values($f); $vals[] = $id;
                db()->prepare("UPDATE inv_jobwork_charges SET $sets WHERE id=?")->execute($vals);
            } else {
                $cols = implode(',', array_keys($f)) . ',created_by';
                $ph = implode(',', array_fill(0, count($f) + 1, '?'));
                $vals = array_values($f); $vals[] = (int)(current_user()['id'] ?? 0);
                db()->prepare("INSERT INTO inv_jobwork_charges ($cols) VALUES ($ph)")->execute($vals);
                $id = (int)db()->lastInsertId();
            }
            /* the lines. A bill with none still reads from its own
               billed_qty/rate, so bills raised before this keep working. */
            db()->prepare("DELETE FROM inv_jobwork_items WHERE bill_id=?")->execute([$id]);
            $insL = db()->prepare("INSERT INTO inv_jobwork_items
                (bill_id,material_id,product_id,description,process,out_gate_item_id,in_gate_item_id,
                 sent_qty,returned_qty,accepted_qty,uom,rate,amount,material_rate,note,sort_order)
                VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)");
            $basis = $f['charge_basis']; $nL = 0; $sumAmt = 0.0;
            foreach ((array)($_POST['ln'] ?? []) as $ln) {
                $sent = inv_num($ln['sent_qty'] ?? 0);
                $ret  = inv_num($ln['returned_qty'] ?? 0);
                $acc  = inv_num($ln['accepted_qty'] ?? 0);
                $lrate= inv_num($ln['rate'] ?? 0);
                $mid  = (int)($ln['material_id'] ?? 0);
                $pid  = (int)($ln['product_id'] ?? 0);
                if ($sent <= 0 && $ret <= 0 && $acc <= 0) continue;
                $bq = inv_jobwork_basis_qty(['sent_qty'=>$sent,'returned_qty'=>$ret,'accepted_qty'=>$acc], $basis);
                $amt = round($bq * $lrate, 2); $sumAmt += $amt;
                $insL->execute([$id, $mid ?: null, $pid ?: null,
                    mb_substr(trim((string)($ln['description'] ?? '')), 0, 300) ?: null,
                    mb_substr(trim((string)($ln['process'] ?? '')), 0, 160) ?: null,
                    (int)($ln['out_gate_item_id'] ?? 0) ?: null,
                    (int)($ln['in_gate_item_id'] ?? 0) ?: null,
                    $sent, $ret, $acc,
                    mb_substr(trim((string)($ln['uom'] ?? '')), 0, 20) ?: null,
                    $lrate, $amt, inv_num($ln['material_rate'] ?? 0),
                    mb_substr(trim((string)($ln['note'] ?? '')), 0, 300) ?: null, $nL++]);
            }
            // the header total follows the lines whenever there are any
            if ($nL > 0) db()->prepare("UPDATE inv_jobwork_charges SET amount=? WHERE id=?")->execute([round($sumAmt, 2), $id]);

            inv_audit('jobwork_bill', $id, $f + ['lines' => $nL], 'Job work charge saved');
            $_SESSION['flash'] = 'Bill ' . $no . ' saved with ' . $nL . ' line(s).';
        } catch (Throwable $e) { $_SESSION['error'] = 'Could not save — the bill number may already be in use.'; }
        redirect('inv_jobwork.php?dir=' . $dir . '&id=' . $id);
    }

    /* Closing. A settled or cancelled bill leaves every pending figure,
       which is the whole point — a deal that is done should not sit in a
       report for ever. */
    if ($act === 'close_bill') {
        $r = inv_jobwork_close((int)($_POST['id'] ?? 0),
            ($_POST['to'] ?? '') === 'cancelled' ? 'cancelled' : 'settled',
            (string)($_POST['reason'] ?? ''));
        if ($r['ok']) $_SESSION['flash'] = 'Bill ' . $r['bill_no'] . ' closed. It no longer counts as outstanding.';
        else $_SESSION['error'] = $r['error'];
        redirect('inv_jobwork.php?dir=' . $dir);
    }

    /* Take a delivery off the "not billed yet" list without billing it. */
    if ($act === 'close_line') {
        $r = inv_jobwork_close_gate_line((int)($_POST['gate_item_id'] ?? 0), (string)($_POST['note'] ?? ''));
        if ($r['ok']) $_SESSION['flash'] = 'Closed with no charge. It has left the not-billed list.';
        else $_SESSION['error'] = $r['error'];
        redirect('inv_jobwork.php?dir=' . $dir);
    }
}

/* --------- work delivered/returned that has no bill against it yet ----- */
/* Now counted per LINE, not per gate pass, and a line drops out for good
   once it is billed OR once someone closes it with a reason. A contract
   that has been closed disappears from here too — a finished job should
   not sit in a pending list. */
$unbilled = inv_jobwork_open_lines($dir);
$unbilled = array_values(array_filter($unbilled, function ($u) {
    return empty($u['contract_id']) || ($u['contract_status'] ?? '') !== 'closed';
}));

/* ------------------------------------------------------- bill register */
$fq = trim((string)($_GET['q'] ?? ''));
$fs = $_GET['status'] ?? '';
$from = $_GET['from'] ?? ''; $to = $_GET['to'] ?? '';
$bills = [];
try {
    $w = ['b.direction = ?']; $p = [$dir];
    if ($fq !== '')  { $w[] = '(b.bill_no LIKE ? OR b.their_bill_no LIKE ? OR pt.name LIKE ? OR c.contract_no LIKE ?)'; array_push($p, "%$fq%", "%$fq%", "%$fq%", "%$fq%"); }
    if (in_array($fs, ['draft','raised','settled','query','cancelled'], true)) { $w[] = 'b.status = ?'; $p[] = $fs; }
    if ($from !== '') { $w[] = 'b.bill_date >= ?'; $p[] = $from; }
    if ($to !== '')   { $w[] = 'b.bill_date <= ?'; $p[] = $to; }
    $s = db()->prepare("SELECT b.*, pt.name party_name, c.contract_no, c.process, g.gate_no
        FROM inv_jobwork_charges b
        LEFT JOIN inv_parties pt ON pt.id=b.party_id
        LEFT JOIN inv_contracts c ON c.id=b.contract_id
        LEFT JOIN inv_gate g ON g.id=b.gate_id
        WHERE " . implode(' AND ', $w) . " ORDER BY b.id DESC LIMIT 200");
    $s->execute($p); $bills = $s->fetchAll();
} catch (Throwable $e) {}

$tot = ['all' => 0.0, 'settled' => 0.0, 'open' => 0.0, 'query' => 0];
foreach ($bills as $b) {
    if ($b['status'] === 'cancelled') continue;
    $tot['all'] += (float)$b['amount'];
    if ($b['status'] === 'settled') $tot['settled'] += (float)$b['amount'];
    else $tot['open'] += (float)$b['amount'];
    if ($b['status'] === 'query') $tot['query']++;
}
$unbilledVal = 0.0; foreach ($unbilled as $u2) $unbilledVal += (float)$u2['qty'] * (float)$u2['rate'];

$parties = $canEdit ? inv_parties('') : [];
$contracts = [];
if ($canEdit) {
    try { $t = $dir === 'receivable' ? 'jobwork_in' : 'jobwork_out';
        $s = db()->prepare("SELECT c.id,c.contract_no,c.process,p.name pname FROM inv_contracts c LEFT JOIN inv_parties p ON p.id=c.party_id WHERE c.contract_type=? ORDER BY c.id DESC LIMIT 200");
        $s->execute([$t]); $contracts = $s->fetchAll(); } catch (Throwable $e) {}
}
$prefill = null;
$fromLine = (int)($_GET['from_line'] ?? $_GET['from_gate'] ?? 0);
if ($fromLine > 0) {
    foreach ($unbilled as $u2) if ((int)$u2['id'] === $fromLine) $prefill = $u2;
}

/* Opening an existing bill loads it and its lines. */
$edit = null; $editLines = [];
if (!empty($_GET['id'])) {
    try {
        $s = db()->prepare("SELECT b.*, pt.name party_name, c.contract_no, c.process, c.wastage_pct
            FROM inv_jobwork_charges b
            LEFT JOIN inv_parties pt ON pt.id=b.party_id
            LEFT JOIN inv_contracts c ON c.id=b.contract_id
            WHERE b.id=?");
        $s->execute([(int)$_GET['id']]);
        $edit = $s->fetch() ?: null;
        if ($edit) { $dir = $edit['direction']; $editLines = inv_jobwork_lines((int)$edit['id']); }
    } catch (Throwable $e) {}
}
$isClosed = $edit && in_array($edit['status'], ['settled','cancelled'], true);
$showForm = $canEdit && (isset($_GET['new']) || $prefill || ($edit && !$isClosed));

/* Every material the bill lines can name. */
$jwMaterials = $showForm ? inv_materials(true) : [];

page_header('Job Work Charges');
flash();
?>
<div class="topbar">
  <div><h1>Job Work Charges</h1>
    <p class="lead"><?= $dir === 'receivable'
      ? 'What you bill customers for processing their material.'
      : 'What processors bill you for working on your material — checked against what actually came back.' ?></p></div>
  <div style="display:flex;gap:8px;flex-wrap:wrap">
    <a class="zbtn sec" href="?dir=<?= $dir === 'receivable' ? 'payable' : 'receivable' ?>">Switch to <?= $dir === 'receivable' ? 'payable' : 'receivable' ?></a>
    <?php if ($canEdit && !$showForm): ?><a class="zbtn sec" href="?dir=<?= e($dir) ?>&amp;new=1">+ New bill</a><?php endif; ?>
  </div>
</div>

<style>
.jw-card{background:#fff;border:1px solid #e3e9f2;border-radius:16px;padding:13px 15px;margin-bottom:11px;box-shadow:0 10px 30px rgba(15,35,65,.06)}
.jw-inp{padding:9px 11px;border-radius:9px;border:1px solid #cbd5e3;font-size:12.5px;font-family:inherit;width:100%}
.jw-lbl{display:block;font-size:10.5px;color:#8a97ab;font-weight:800;text-transform:uppercase;letter-spacing:.04em;margin-bottom:5px}
.jw-grid{display:grid;gap:13px;grid-template-columns:repeat(4,1fr)}
@media(max-width:1000px){.jw-grid{grid-template-columns:repeat(2,1fr)}}
@media(max-width:640px){.jw-grid{grid-template-columns:1fr}}
.jw-tbl{width:100%;border-collapse:collapse;font-size:12.5px}
.jw-tbl th{text-align:left;font-size:10px;text-transform:uppercase;letter-spacing:.05em;color:#8a97ab;font-weight:800;padding:0 8px 5px;white-space:nowrap}
.jw-tbl td{padding:3px 8px;border-top:1px solid #eef1f7;vertical-align:top}
.jw-tbl td.r,.jw-tbl th.r{text-align:right;font-variant-numeric:tabular-nums}
.jw-btn{padding:9px 16px;border:none;border-radius:10px;background:linear-gradient(100deg,#0ea8c9,#6d5bd0);color:#fff;font-weight:700;font-size:12.5px;cursor:pointer}
.jw-btn.sec{background:#fff;color:#152033;border:1px solid #cbd5e3;text-decoration:none;display:inline-block}
.jw-kpi{background:#fff;border:1px solid #e3e9f2;border-radius:13px;padding:14px 16px}
.jw-kpi .l{font-size:10px;color:#8a97ab;font-weight:800;text-transform:uppercase;letter-spacing:.04em}
.jw-kpi .v{font-size:20px;font-weight:800;margin-top:5px;font-variant-numeric:tabular-nums}
.jw-pill{display:inline-block;font-size:10px;font-weight:800;padding:3px 8px;border-radius:20px}
.b-draft{background:#f0f3f9;color:#5a6b82}.b-raised{background:rgba(217,119,6,.12);color:#a8630a}
.b-settled{background:rgba(22,163,74,.12);color:#16a34a}.b-query{background:rgba(224,67,93,.12);color:#c0293f}
.b-cancelled{background:#f0f3f9;color:#a4aebd}
.jw-note{border-radius:11px;padding:12px 15px;font-size:12.5px;line-height:1.65;margin-bottom:14px}
.jw-note.info{background:rgba(14,168,201,.07);border:1px solid rgba(14,168,201,.2);color:#2c4a63}
.jw-note.bad{background:rgba(224,67,93,.08);border:1px solid rgba(224,67,93,.24);color:#8c2038}
</style>

<?php /* OPTING IN TO THE SKIN. Every rule in assets/css/zskin.css is
         scoped under .zskin, so this one wrapper is what makes the page
         compact, and deleting it restores the styles above with nothing
         else to undo. It wraps the markup and never the <style>. */ ?>
<div class="zskin">

<?php if ($showForm): $P = $prefill ?: []; ?>
<div class="jw-card">
  <h2 style="font-size:15.5px;margin:0 0 4px;font-weight:800"><?= $edit ? 'Edit bill ' . e($edit['bill_no']) : 'New ' . e($dir) . ' job work bill' ?></h2>
  <p style="color:#8a97ab;font-size:12px;margin:0 0 14px"><?= $dir === 'receivable'
    ? 'Sent and Returned come from your own gate passes. You confirm what the rate applies to, and settle any excess loss by agreement.'
    : 'What the processor billed, against what your gate actually received. Any difference is shown, never hidden.' ?></p>
  <form method="post"><?= csrf_field() ?>
    <input type="hidden" name="action" value="save"><input type="hidden" name="direction" value="<?= e($dir) ?>">
    <input type="hidden" name="id" value="<?= (int)($edit['id'] ?? 0) ?>">
    <input type="hidden" name="gate_id" value="<?= (int)($P['gate_id'] ?? 0) ?>">
    <div class="jw-grid">
      <div><label class="jw-lbl">Bill no.</label><input class="jw-inp" name="bill_no" value="<?= e($edit['bill_no'] ?? '') ?>" placeholder="auto" style="font-family:monospace"></div>
      <div><label class="jw-lbl">Date</label><input class="jw-inp" type="date" name="bill_date" value="<?= e($edit['bill_date'] ?? date('Y-m-d')) ?>"></div>
      <div><label class="jw-lbl">Contract</label>
        <select class="jw-inp" name="contract_id">
          <option value="0">— none —</option>
          <?php foreach ($contracts as $c): ?><option value="<?= (int)$c['id'] ?>" <?= (int)($edit['contract_id'] ?? $P['contract_id'] ?? 0) === (int)$c['id'] ? 'selected' : '' ?>><?= e($c['contract_no']) ?> · <?= e($c['pname'] ?: '') ?></option><?php endforeach; ?>
        </select></div>
      <div><label class="jw-lbl">Party</label>
        <select class="jw-inp" name="party_id">
          <option value="0">— none —</option>
          <?php foreach ($parties as $p): ?><option value="<?= (int)$p['id'] ?>" <?= (int)($edit['party_id'] ?? $P['party_id'] ?? 0) === (int)$p['id'] ? 'selected' : '' ?>><?= e($p['name']) ?></option><?php endforeach; ?>
        </select></div>

      <div style="grid-column:span 2"><label class="jw-lbl">Description of work</label>
        <input class="jw-inp" name="description" value="<?= e($edit['description'] ?? $P['process'] ?? '') ?>" placeholder="Cut, make &amp; pack / Bleach &amp; dye"></div>
      <?php if ($dir === 'payable'): ?>
      <div><label class="jw-lbl">Their bill no.</label><input class="jw-inp" name="their_bill_no" value="<?= e($edit['their_bill_no'] ?? '') ?>" style="font-family:monospace"></div>
      <?php else: ?><div></div><?php endif; ?>
      <div><label class="jw-lbl">Currency</label><input class="jw-inp" name="currency" value="<?= e($edit['currency'] ?? $P['currency'] ?? 'PKR') ?>" style="font-family:monospace"></div>

      <div style="grid-column:span 2"><label class="jw-lbl">The rate applies to</label>
        <select class="jw-inp" name="charge_basis" id="basis">
          <?php $cb = $edit['charge_basis'] ?? 'returned'; ?>
          <option value="returned" <?= $cb==='returned'?'selected':'' ?>>What came BACK — returned quantity</option>
          <option value="sent"     <?= $cb==='sent'?'selected':'' ?>>What was SENT — despatched quantity</option>
          <option value="accepted" <?= $cb==='accepted'?'selected':'' ?>>What was ACCEPTED — the agreed quantity</option>
        </select>
        <p style="font-size:10.5px;color:#8a97ab;margin:5px 0 0">It varies by contract, so it is set on each bill. Change it and every line re-prices.</p></div>
      <div><label class="jw-lbl">Sales tax</label>
        <div style="display:flex;gap:8px;align-items:center">
          <label style="display:inline-flex;align-items:center;gap:7px;font-size:12.5px;color:#5a6b82;cursor:pointer">
            <input type="checkbox" name="gst_applicable" id="jgst" value="1" <?= !empty($edit['gst_applicable']) ? 'checked' : '' ?>> GST</label>
          <input class="jw-inp" name="gst_pct" id="jgstp" style="width:76px;text-align:right" value="<?= e((string)($edit['gst_pct'] ?? inv_gst_default())) ?>">
        </div>
        <p style="font-size:10.5px;color:#8a97ab;margin:5px 0 0">On the service only — never on the goods.</p></div>
      <div><label class="jw-lbl">Status</label>
        <select class="jw-inp" name="status">
          <?php foreach (['draft'=>'Draft','raised'=>'Raised','query'=>'Query'] as $k => $v): ?>
            <option value="<?= e($k) ?>" <?= ($edit['status'] ?? 'raised') === $k ? 'selected' : '' ?>><?= e($v) ?></option>
          <?php endforeach; ?>
        </select>
        <p style="font-size:10.5px;color:#8a97ab;margin:5px 0 0">Settle it from the register when the money is done.</p></div>
    </div>

    <!-- the lines -->
    <div style="margin-top:18px">
      <div style="display:flex;justify-content:space-between;align-items:center;gap:10px;flex-wrap:wrap;margin-bottom:9px">
        <b style="font-size:13px">Lines</b>
        <span style="font-size:11.5px;color:#8a97ab">Sent and Returned are facts from your gate. Loss is worked out, never typed.</span>
      </div>
      <div style="max-height:300px;overflow:auto;border:1px solid #e3e9f2;border-radius:12px">
      <table class="jw-tbl" id="jwT" style="margin:0">
        <thead><tr>
          <th style="min-width:190px;position:sticky;top:0;background:#fff;z-index:1">Item</th>
          <th style="min-width:150px;position:sticky;top:0;background:#fff">Process</th>
          <th class="r" style="width:92px;position:sticky;top:0;background:#fff">Sent</th>
          <th class="r" style="width:92px;position:sticky;top:0;background:#fff">Returned</th>
          <th class="r" style="width:92px;position:sticky;top:0;background:#fff">Accepted</th>
          <th class="r" style="width:74px;position:sticky;top:0;background:#fff">Loss</th>
          <th class="r" style="width:92px;position:sticky;top:0;background:#fff">Billed on</th>
          <th class="r" style="width:88px;position:sticky;top:0;background:#fff">Rate</th>
          <th class="r" style="width:96px;position:sticky;top:0;background:#fff">Amount</th>
          <th style="width:30px;position:sticky;top:0;background:#fff"></th>
        </tr></thead>
        <tbody>
        <?php
          $rows = $editLines;
          if (!$rows) {
            $rows = [[
              'material_id' => (int)($P['material_id'] ?? 0),
              'description' => $P['description'] ?? '',
              'process'     => $P['process'] ?? '',
              'sent_qty'    => '', 'returned_qty' => $P['qty'] ?? '', 'accepted_qty' => '',
              'uom' => $P['uom'] ?? '', 'rate' => $P['rate'] ?? '', 'material_rate' => $P['rate'] ?? 0,
              'in_gate_item_id' => (int)($P['id'] ?? 0), 'out_gate_item_id' => 0, 'note' => '',
            ]];
          }
          foreach ($rows as $i => $L):
        ?>
          <tr>
            <td><select class="jw-inp mat" name="ln[<?= $i ?>][material_id]">
                <option value="0">— select —</option>
                <?php foreach ($jwMaterials as $m): ?><option value="<?= (int)$m['id'] ?>" data-uom="<?= e($m['uom']) ?>" <?= (int)($L['material_id'] ?? 0) === (int)$m['id'] ? 'selected' : '' ?>><?= e($m['code']) ?> · <?= e($m['name']) ?></option><?php endforeach; ?>
              </select>
              <input class="jw-inp" name="ln[<?= $i ?>][description]" value="<?= e($L['description'] ?? '') ?>" placeholder="description" style="margin-top:4px;font-size:11.5px">
              <input type="hidden" name="ln[<?= $i ?>][out_gate_item_id]" value="<?= (int)($L['out_gate_item_id'] ?? 0) ?>">
              <input type="hidden" name="ln[<?= $i ?>][in_gate_item_id]" value="<?= (int)($L['in_gate_item_id'] ?? 0) ?>">
              <input type="hidden" class="mrate" name="ln[<?= $i ?>][material_rate]" value="<?= e((string)($L['material_rate'] ?? 0)) ?>"></td>
            <td><input class="jw-inp" name="ln[<?= $i ?>][process]" value="<?= e($L['process'] ?? '') ?>" placeholder="Bleach &amp; dye"></td>
            <td><input class="jw-inp sent" name="ln[<?= $i ?>][sent_qty]" value="<?= e((string)($L['sent_qty'] ?? '')) ?>" style="text-align:right"></td>
            <td><input class="jw-inp ret"  name="ln[<?= $i ?>][returned_qty]" value="<?= e((string)($L['returned_qty'] ?? '')) ?>" style="text-align:right"></td>
            <td><input class="jw-inp acc"  name="ln[<?= $i ?>][accepted_qty]" value="<?= e((string)($L['accepted_qty'] ?? '')) ?>" style="text-align:right" placeholder="agreed"></td>
            <td class="r loss" style="font-weight:700">—</td>
            <td class="r bq" style="color:#5a6b82">—</td>
            <td><input class="jw-inp rate" name="ln[<?= $i ?>][rate]" value="<?= e((string)($L['rate'] ?? '')) ?>" style="text-align:right">
                <input class="jw-inp uom" name="ln[<?= $i ?>][uom]" value="<?= e($L['uom'] ?? '') ?>" placeholder="UOM" style="margin-top:4px;font-size:11.5px;font-family:monospace"></td>
            <td class="r amt" style="font-weight:700">0.00</td>
            <td><button type="button" class="jw-btn sec del" style="padding:5px 8px;cursor:pointer">×</button></td>
          </tr>
        <?php endforeach; ?>
        </tbody>
      </table>
      </div>
      <div style="margin-top:10px"><button type="button" class="jw-btn sec" id="jwAdd">+ Add line</button></div>
    </div>

    <!-- the negotiated settlement -->
    <div style="margin-top:16px;border:1px solid #e3e9f2;border-radius:12px;padding:14px 16px;background:#f6f8fc">
      <div id="lossNote" style="font-size:12px;color:#5a6b82;line-height:1.65;margin-bottom:11px"></div>
      <div style="display:flex;gap:12px;flex-wrap:wrap;align-items:flex-end">
        <div><label class="jw-lbl">Adjustment</label>
          <input class="jw-inp" name="adjustment_amount" id="adj" value="<?= e((string)($edit['adjustment_amount'] ?? '0')) ?>" style="width:130px;text-align:right"></div>
        <div style="flex:1;min-width:220px"><label class="jw-lbl">What was agreed</label>
          <input class="jw-inp" name="adjustment_note" value="<?= e($edit['adjustment_note'] ?? '') ?>"
            placeholder="e.g. 4% extra loss accepted on velour, no deduction — agreed with Rana sb"></div>
      </div>
      <p style="font-size:11px;color:#8a97ab;margin:9px 0 0">
        A minus figure reduces the bill, a plus adds to it. There is no formula here on purpose — excess loss is
        negotiated, so what you settle on is typed and kept with the bill.</p>
    </div>

    <div style="margin-top:14px;display:flex;gap:22px;flex-wrap:wrap;align-items:center;justify-content:flex-end">
      <div style="font-size:12.5px;color:#5a6b82">Lines <b id="tLines" style="font-variant-numeric:tabular-nums">0.00</b></div>
      <div style="font-size:12.5px;color:#5a6b82">Adjustment <b id="tAdj" style="font-variant-numeric:tabular-nums">0.00</b></div>
      <div style="font-size:12.5px;color:#5a6b82">GST <b id="tGst" style="font-variant-numeric:tabular-nums">0.00</b></div>
      <div style="font-size:15px;font-weight:800">Total <span id="tAll" style="font-variant-numeric:tabular-nums">0.00</span></div>
    </div>

    <div style="margin-top:14px;display:flex;gap:10px;flex-wrap:wrap"><button class="jw-btn" type="submit">Save bill</button>
      <a class="jw-btn sec" href="?dir=<?= e($dir) ?>">Cancel</a>
      <span style="font-size:11.5px;color:#8a97ab;align-self:center">Saving does not settle it. Close it from the register when the money is done.</span></div>
    <div style="margin-top:10px"><label class="jw-lbl">Remarks</label><input class="jw-inp" name="remarks" value="<?= e($edit['remarks'] ?? '') ?>"></div>
  </form>
</div>
<script>
(function(){
  var tb = document.querySelector('#jwT tbody');
  var tpl = tb.firstElementChild ? tb.firstElementChild.outerHTML : '';
  function n(v){ var x = parseFloat(String(v).replace(/[^0-9.\-]/g,'')); return isNaN(x)?0:x; }
  function money(v){ return Number(v).toLocaleString('en-US',{minimumFractionDigits:2,maximumFractionDigits:2}); }
  function q3(v){ return Number(v).toLocaleString('en-US',{maximumFractionDigits:3}); }

  function recalc(){
    var basis = document.getElementById('basis').value, lines = 0, excess = 0, worst = 0, anyGain = false;
    [].slice.call(tb.children).forEach(function(tr){
      var sent = n(tr.querySelector('.sent').value),
          ret  = n(tr.querySelector('.ret').value),
          acc  = n(tr.querySelector('.acc').value),
          rate = n(tr.querySelector('.rate').value),
          mrate= n(tr.querySelector('.mrate').value);
      var bq = basis === 'sent' ? sent : (basis === 'accepted' ? (acc || ret) : ret);
      var loss = sent - ret, lossPct = sent > 0 ? loss/sent*100 : 0;
      if (loss < -0.0005) anyGain = true;
      if (sent > 0 && lossPct > worst) worst = lossPct;
      if (sent > 0 && loss > 0) excess += loss * mrate;
      tr.querySelector('.loss').textContent = sent > 0 ? lossPct.toFixed(1) + '%' : '—';
      tr.querySelector('.loss').style.color = lossPct > 25 ? '#c0293f' : (lossPct > 10 ? '#b45309' : (loss < -0.0005 ? '#c0293f' : '#16a34a'));
      tr.querySelector('.bq').textContent = bq ? q3(bq) : '—';
      var amt = bq * rate;
      tr.querySelector('.amt').textContent = money(amt);
      lines += amt;
    });
    var adj = n(document.getElementById('adj').value);
    var net = lines + adj;
    var on = document.getElementById('jgst').checked, pct = n(document.getElementById('jgstp').value);
    var gst = on ? Math.round(net * pct) / 100 : 0;
    document.getElementById('tLines').textContent = money(lines);
    document.getElementById('tAdj').textContent   = money(adj);
    document.getElementById('tGst').textContent   = money(gst);
    document.getElementById('tAll').textContent   = money(net + gst);

    var note = document.getElementById('lossNote');
    if (anyGain) {
      note.innerHTML = '<b style="color:#c0293f">More came back than went out on at least one line.</b> That is always '
        + 'worth querying — it is usually a weighing error or another party\'s goods mixed in.';
    } else if (worst > 25) {
      note.innerHTML = '<b style="color:#c0293f">Worst line is losing ' + worst.toFixed(1) + '%.</b> '
        + 'Material value of the loss across this bill is about <b>' + money(excess) + '</b>. '
        + 'Settle whatever you agree in the Adjustment box below — nothing is deducted automatically.';
    } else if (worst > 10) {
      note.innerHTML = 'Worst line is losing <b>' + worst.toFixed(1) + '%</b>. Material value of the loss across this '
        + 'bill is about <b>' + money(excess) + '</b>. Settle whatever you agree below.';
    } else {
      note.innerHTML = 'Losses on this bill look normal' + (worst > 0 ? ' — worst line ' + worst.toFixed(1) + '%' : '')
        + '. Material value of the loss is about <b>' + money(excess) + '</b>, shown for information only.';
    }
  }
  function reindex(){
    [].slice.call(tb.children).forEach(function(tr,i){
      tr.querySelectorAll('[name]').forEach(function(el){ el.name = el.name.replace(/ln\[\d+\]/, 'ln['+i+']'); });
    });
  }
  tb.addEventListener('input', recalc);
  tb.addEventListener('change', function(e){
    if (e.target.classList.contains('mat')) {
      var o = e.target.options[e.target.selectedIndex], tr = e.target.closest('tr');
      if (o && o.dataset.uom && !tr.querySelector('.uom').value) tr.querySelector('.uom').value = o.dataset.uom;
    }
    recalc();
  });
  tb.addEventListener('click', function(e){
    if (!e.target.classList.contains('del')) return;
    if (tb.children.length > 1) e.target.closest('tr').remove();
    else tb.querySelectorAll('input').forEach(function(i){ i.value = ''; });
    reindex(); recalc();
  });
  document.getElementById('jwAdd').addEventListener('click', function(){
    var d = document.createElement('tbody'); d.innerHTML = tpl;
    var tr = d.firstElementChild;
    tr.querySelectorAll('input').forEach(function(i){ i.value = ''; });
    var sel = tr.querySelector('select'); if (sel) sel.selectedIndex = 0;
    tb.appendChild(tr); reindex(); recalc();
  });
  ['basis','adj','jgst','jgstp'].forEach(function(id){
    var el = document.getElementById(id);
    el.addEventListener(el.type === 'checkbox' ? 'change' : 'input', recalc);
    if (el.tagName === 'SELECT') el.addEventListener('change', recalc);
  });
  recalc();
})();
</script>
<?php endif; ?>

<div class="jw-grid" style="margin-bottom:16px">
  <div class="jw-kpi"><div class="l"><?= $dir === 'receivable' ? 'Billed' : 'Charged to us' ?></div><div class="v"><?= number_format($tot['all'], 0) ?></div></div>
  <div class="jw-kpi"><div class="l">Settled</div><div class="v" style="color:#16a34a"><?= number_format($tot['settled'], 0) ?></div></div>
  <div class="jw-kpi" style="border-color:rgba(217,119,6,.28);background:rgba(217,119,6,.05)"><div class="l"><?= $dir === 'receivable' ? 'Outstanding' : 'Payable' ?></div><div class="v" style="color:#a8630a"><?= number_format($tot['open'], 0) ?></div></div>
  <div class="jw-kpi" style="<?= $unbilledVal > 0 ? 'border-color:rgba(224,67,93,.28);background:rgba(224,67,93,.05)' : '' ?>">
    <div class="l"><?= $dir === 'receivable' ? 'Delivered, not billed' : 'Returned, no bill yet' ?></div>
    <div class="v" style="<?= $unbilledVal > 0 ? 'color:#c0293f' : '' ?>"><?= number_format($unbilledVal, 0) ?></div></div>
</div>

<?php if ($unbilled): ?>
<div class="jw-card" style="border-color:rgba(224,67,93,.25)">
  <h2 style="font-size:15px;margin:0 0 4px;font-weight:800"><?= $dir === 'receivable' ? 'Work delivered with no bill raised' : 'Material returned with no processor bill recorded' ?></h2>
  <p style="color:#8a97ab;font-size:12px;margin:0 0 14px">
    <?= $dir === 'receivable'
      ? 'These goods left your gate against a job work contract that has a rate on it, and no bill exists. That is work you have already paid wages for.'
      : 'Your material came back from these processors and no bill has been recorded against them yet.' ?></p>
  <div style="overflow-x:auto"><table class="jw-tbl">
    <thead><tr><th>Gate pass</th><th>Date</th><th>Item</th><th>Party</th><th>Contract</th>
      <th class="r">Qty</th><th class="r">Rate</th><th class="r">Value</th><th style="width:210px"></th></tr></thead>
    <tbody>
    <?php foreach ($unbilled as $u2): ?>
      <tr>
        <td style="font-family:monospace;font-weight:700"><a href="inv_gate.php?id=<?= (int)$u2['gate_id'] ?>"><?= e($u2['gate_no']) ?></a></td>
        <td><?= e($u2['gate_date']) ?></td>
        <td><?= e($u2['mcode'] ? $u2['mcode'] . ' · ' . $u2['mname'] : ($u2['pname'] ?: ($u2['description'] ?: '—'))) ?></td>
        <td><?= e($u2['party_name'] ?: '—') ?></td>
        <td style="font-size:11.5px"><?= e($u2['contract_no'] ?: '—') ?><?php if ($u2['process']): ?><br><span style="color:#8a97ab"><?= e($u2['process']) ?></span><?php endif; ?></td>
        <td class="r"><?= number_format((float)$u2['qty'], 2) ?></td>
        <td class="r"><?= number_format((float)$u2['rate'], 2) ?></td>
        <td class="r" style="font-weight:700;color:#c0293f"><?= number_format((float)$u2['qty'] * (float)$u2['rate'], 2) ?></td>
        <td style="text-align:right;white-space:nowrap">
          <?php if ($canEdit): ?>
            <a class="jw-btn sec" style="padding:5px 10px;font-size:11.5px" href="?dir=<?= e($dir) ?>&amp;from_line=<?= (int)$u2['id'] ?>">Raise bill</a>
            <form method="post" style="display:inline" onsubmit="return this.note.value.trim()!==''||(alert('Say why it will not be billed.'),false);">
              <?= csrf_field() ?><input type="hidden" name="action" value="close_line">
              <input type="hidden" name="direction" value="<?= e($dir) ?>">
              <input type="hidden" name="gate_item_id" value="<?= (int)$u2['id'] ?>">
              <input class="jw-inp" name="note" placeholder="reason" style="width:120px;display:inline-block;padding:5px 8px;font-size:11.5px">
              <button class="jw-btn sec" style="padding:5px 10px;font-size:11.5px" type="submit"
                title="Takes it off this list without billing it">No charge</button>
            </form>
          <?php endif; ?>
        </td>
      </tr>
    <?php endforeach; ?>
    </tbody>
  </table></div>
</div>
<?php endif; ?>

<div class="jw-card">
  <form method="get" style="display:flex;gap:10px;align-items:flex-end;flex-wrap:wrap;margin-bottom:16px">
    <input type="hidden" name="dir" value="<?= e($dir) ?>">
    <div><label class="jw-lbl">Search</label><input class="jw-inp" name="q" value="<?= e($fq) ?>" placeholder="bill no., party or contract" style="width:auto;min-width:210px"></div>
    <div><label class="jw-lbl">From</label><input class="jw-inp" type="date" name="from" value="<?= e($from) ?>" style="width:auto"></div>
    <div><label class="jw-lbl">To</label><input class="jw-inp" type="date" name="to" value="<?= e($to) ?>" style="width:auto"></div>
    <div><label class="jw-lbl">Status</label>
      <select class="jw-inp" name="status" style="width:auto">
        <option value="">Any</option>
        <?php foreach (['draft','raised','settled','query','cancelled'] as $s): ?><option value="<?= e($s) ?>" <?= $fs === $s ? 'selected' : '' ?>><?= e(ucfirst($s)) ?></option><?php endforeach; ?>
      </select></div>
    <button class="jw-btn sec" type="submit" style="cursor:pointer">Search</button>
    <button class="jw-btn sec" type="button" onclick="window.print()" style="cursor:pointer">Print</button>
  </form>

  <?php if (!$bills): ?>
    <p style="color:#8a97ab;font-size:13px;padding:26px 0;text-align:center">No <?= e($dir) ?> job work bills yet.</p>
  <?php else: ?>
  <div style="overflow-x:auto"><table class="jw-tbl">
    <thead><tr><th>Bill</th><th>Date</th><th>Party</th><th>Contract</th><th>Work</th>
      <th class="r">Billed</th><?php if ($dir === 'payable'): ?><th class="r">We received</th><?php endif; ?>
      <th class="r">Rate</th><th class="r">Amount</th><th>Status</th></tr></thead>
    <tbody>
    <?php foreach ($bills as $b):
      $diff = $dir === 'payable' ? (float)$b['billed_qty'] - (float)$b['received_qty'] : 0.0;
      $flag = $dir === 'payable' && $diff > 0.0005 && (float)$b['received_qty'] > 0; ?>
      <tr<?= $flag ? ' style="background:rgba(224,67,93,.05)"' : '' ?>>
        <td style="font-family:monospace;font-weight:700"><?= e($b['bill_no']) ?>
          <?php if ($b['their_bill_no']): ?><br><span style="font-size:11px;color:#8a97ab"><?= e($b['their_bill_no']) ?></span><?php endif; ?></td>
        <td><?= e($b['bill_date']) ?></td>
        <td><?= e($b['party_name'] ?: '—') ?></td>
        <td style="font-size:11.5px"><?= e($b['contract_no'] ?: '—') ?></td>
        <td style="font-size:11.5px"><?= e($b['description'] ?: ($b['process'] ?: '—')) ?></td>
        <td class="r"><?= number_format((float)$b['billed_qty'], 2) ?></td>
        <?php if ($dir === 'payable'): ?>
          <td class="r"><?= (float)$b['received_qty'] > 0 ? number_format((float)$b['received_qty'], 2) : '—' ?>
            <?php if ($flag): ?><br><span style="font-size:10.5px;color:#c0293f;font-weight:700">billed <?= number_format($diff, 2) ?> more</span><?php endif; ?></td>
        <?php endif; ?>
        <td class="r"><?= number_format((float)$b['rate'], 2) ?></td>
        <td class="r" style="font-weight:800"><?= number_format((float)$b['amount'], 2) ?><br><span style="font-size:10.5px;color:#8a97ab"><?= e($b['currency']) ?></span></td>
        <td><span class="jw-pill b-<?= e($b['status']) ?>"><?= e(ucfirst($b['status'])) ?></span>
          <?php if (!empty($b['closed_reason'])): ?><div style="font-size:10.5px;color:#8a97ab;max-width:170px;white-space:normal"><?= e($b['closed_reason']) ?></div><?php endif; ?>
          <?php if ($canEdit && !in_array($b['status'], ['settled','cancelled'], true)): ?>
            <div style="margin-top:5px;display:flex;gap:5px;flex-wrap:wrap">
              <a class="jw-btn sec" style="padding:4px 9px;font-size:11px" href="?dir=<?= e($dir) ?>&amp;id=<?= (int)$b['id'] ?>">Open</a>
              <form method="post" style="display:inline" onsubmit="return confirm('Mark <?= e($b['bill_no']) ?> settled?

It then stops counting as outstanding anywhere.');">
                <?= csrf_field() ?><input type="hidden" name="action" value="close_bill">
                <input type="hidden" name="direction" value="<?= e($dir) ?>">
                <input type="hidden" name="id" value="<?= (int)$b['id'] ?>">
                <input type="hidden" name="to" value="settled">
                <input type="hidden" name="reason" value="Settled">
                <button class="jw-btn sec" style="padding:4px 9px;font-size:11px" type="submit">Settle</button>
              </form>
            </div>
          <?php endif; ?></td>
      </tr>
    <?php endforeach; ?>
    </tbody>
  </table></div>
  <?php if ($dir === 'payable'): ?>
    <div class="jw-note bad" style="margin:16px 0 0">Rows shaded red were billed for more than actually came back through your gate. The gate record and the processor's bill are two independent facts, so the difference cannot hide.</div>
  <?php else: ?>
    <div class="jw-note info" style="margin:16px 0 0">A receivable job work bill is <b>service revenue with no material cost against it</b> — the customer supplied the fabric. Keep it separate from export margins or it will flatter them.</div>
  <?php endif; ?>
  <?php endif; ?>
</div>
</div><?php /* closes .zskin */ ?>
<?php page_footer(); ?>
