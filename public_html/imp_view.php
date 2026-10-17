<?php
/*
  IMPORT — OVERVIEW / CONTRACT TAB.

  The contract, the supplier invoices with their GDs (one usually, up to
  three), and the payment terms. Options that do not apply are switched off
  and hidden: what was typed in them is kept, but not checked or counted
  while they are off (the server reads the switch, not the hidden boxes).
*/
require_once __DIR__ . '/includes/bootstrap.php';
require_once __DIR__ . '/includes/imports.php';

$imp = imp_open('imp');
$id  = (int)$imp['id'];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verify_csrf();
    $action = (string)($_POST['action'] ?? '');
    try {
        if ($action === 'save') {
            imp_require('imp', 'u');
            $d = static fn(string $k) => imp_date($_POST[$k] ?? '');
            $t = static fn(string $k, int $max = 190) => mb_substr(trim((string)($_POST[$k] ?? '')), 0, $max) ?: null;
            $cur = (string)($_POST['currency'] ?? 'USD');
            if (!in_array($cur, IMP_CURRENCIES, true)) throw new Exception('Pick the currency.');
            $pay = (string)($_POST['pay_method'] ?? '');
            if ($pay !== '' && !in_array($pay, IMP_PAY_METHODS, true)) throw new Exception('Pick the payment method from the list.');
            $inc = (string)($_POST['incoterm'] ?? '');
            if ($inc !== '' && !in_array($inc, IMP_INCOTERMS, true)) throw new Exception('Pick the Incoterm from the list.');
            $adv = imp_num($_POST['advance_pct'] ?? 0);
            if ($adv < 0 || $adv > 100) throw new Exception('Advance % must be between 0 and 100.');
            $credit = !empty($_POST['credit']) ? 1 : 0;
            $days = (int)imp_num($_POST['credit_days'] ?? 0);
            $basis = (string)($_POST['maturity_basis'] ?? 'bl');
            if (!isset(IMP_BASIS[$basis])) $basis = 'bl';
            /* checked only when credit is switched on */
            if ($credit && ($days < 0 || $days > 720)) throw new Exception('Credit days must be between 0 and 720.');
            if ($credit && $basis === 'fixed' && !$d('fixed_due_date')) throw new Exception('Give the agreed fixed due date.');
            $lc = !empty($_POST['lc']) ? 1 : 0;
            $fta = !empty($_POST['fta']) ? 1 : 0;
            $com = !empty($_POST['commission']) ? 1 : 0;
            $comRate = imp_num($_POST['com_rate_pct'] ?? 0);
            if ($com && ($comRate < 0 || $comRate > 100)) throw new Exception('Commission % must be between 0 and 100.');
            $ov = $d('due_override');
            $ovReason = trim((string)($_POST['due_override_reason'] ?? ''));
            if ($ov && $ovReason === '') throw new Exception('Setting the due date by hand needs a reason.');

            $sid = (int)($_POST['supplier_party_id'] ?? 0);
            $sups = imp_suppliers();
            $supName = $sid > 0 && isset($sups[$sid]) ? $sups[$sid] : (string)$imp['supplier_name'];
            if ($sid > 0 && !isset($sups[$sid])) $sid = (int)$imp['supplier_party_id'];

            $vals = [
                'employee' => $t('employee', 120), 'supplier_party_id' => $sid ?: null, 'supplier_name' => $supName,
                'origin_country' => $t('origin_country', 80), 'shipped_from' => $t('shipped_from', 120),
                'contract_no' => $t('contract_no', 80), 'contract_date' => $d('contract_date'),
                'incoterm' => $inc ?: null, 'incoterm_place' => $t('incoterm_place', 120), 'currency' => $cur,
                'import_type' => ($_POST['import_type'] ?? '') === 'efs' ? 'efs' : 'ordinary',
                'pay_method' => $pay ?: null, 'advance_pct' => $adv, 'credit' => $credit, 'credit_days' => max(0, $days),
                'maturity_basis' => $basis, 'fixed_due_date' => $d('fixed_due_date'),
                'due_override' => $ov, 'due_override_reason' => $ov ? mb_substr($ovReason, 0, 300) : null,
                'lc' => $lc, 'lc_no' => $t('lc_no', 80), 'lc_bank' => $t('lc_bank', 120), 'lc_expiry' => $d('lc_expiry'), 'lc_last_ship' => $d('lc_last_ship'),
                'fta' => $fta, 'fta_agreement' => $t('fta_agreement', 120), 'fta_form' => $t('fta_form', 60), 'fta_cert_no' => $t('fta_cert_no', 80), 'fta_cert_date' => $d('fta_cert_date'),
                'commission' => $com, 'com_beneficiary' => $t('com_beneficiary', 160), 'com_rate_pct' => max(0, $comRate),
                'notes' => trim((string)($_POST['notes'] ?? '')) ?: null,
            ];
            if ((int)$imp['is_opening'] === 1) $vals['already_in_stock'] = !empty($_POST['already_in_stock']) ? 1 : 0;

            /* supplier invoices and GDs: one per row, up to three */
            $ivIds = (array)($_POST['iv_id'] ?? []);
            $ivRows = [];
            foreach ($ivIds as $i => $ivId) {
                $no = trim((string)($_POST['iv_no'][$i] ?? ''));
                $row = ['id' => (int)$ivId, 'no' => mb_substr($no, 0, 80), 'date' => imp_date($_POST['iv_date'][$i] ?? ''),
                        'gd' => mb_substr(trim((string)($_POST['iv_gd'][$i] ?? '')), 0, 80), 'gd_date' => imp_date($_POST['iv_gd_date'][$i] ?? '')];
                if ($row['id'] === 0 && $row['no'] === '' && $row['gd'] === '' && !$row['date']) continue;
                $ivRows[] = $row;
            }
            if (count($ivRows) > IMP_MAX_INVOICES) throw new Exception('Up to ' . IMP_MAX_INVOICES . ' supplier invoices / GDs on one import.');

            db()->beginTransaction();
            $set = implode(', ', array_map(fn($k) => "$k=?", array_keys($vals)));
            db()->prepare("UPDATE imp_imports SET $set, updated_by=?, updated_at=NOW() WHERE id=?")
                ->execute(array_merge(array_values($vals), [(int)(current_user()['id'] ?? 0), $id]));
            $keep = [];
            foreach ($ivRows as $n => $r) {
                if ($r['id'] > 0) {
                    db()->prepare("UPDATE imp_invoices SET sort_order=?, invoice_no=?, invoice_date=?, gd_no=?, gd_date=? WHERE id=? AND import_id=?")
                        ->execute([$n + 1, $r['no'] ?: null, $r['date'], $r['gd'] ?: null, $r['gd_date'], $r['id'], $id]);
                    $keep[] = $r['id'];
                } else {
                    db()->prepare("INSERT INTO imp_invoices (import_id, sort_order, invoice_no, invoice_date, gd_no, gd_date) VALUES (?,?,?,?,?,?)")
                        ->execute([$id, $n + 1, $r['no'] ?: null, $r['date'], $r['gd'] ?: null, $r['gd_date']]);
                    $keep[] = (int)db()->lastInsertId();
                }
            }
            /* an invoice taken off: its lines stay, simply no longer assigned to it */
            foreach (imp_invoices($id) as $old) {
                if (in_array((int)$old['id'], $keep, true)) continue;
                db()->prepare("UPDATE imp_lines SET invoice_id=NULL WHERE import_id=? AND invoice_id=?")->execute([$id, (int)$old['id']]);
                db()->prepare("DELETE FROM imp_invoices WHERE id=? AND import_id=?")->execute([(int)$old['id'], $id]);
            }
            $changed = [];
            foreach ($vals as $k => $v) if ((string)($imp[$k] ?? '') !== (string)($v ?? '') && !in_array($k, ['notes'], true)) $changed[] = $k;
            imp_log($id, 'Overview saved', $changed ? 'Changed: ' . implode(', ', $changed) : 'Saved', $ov ? 'Due date set by hand: ' . $ovReason : '');
            db()->commit();
            $_SESSION['flash'] = 'Saved.';
        }
        if ($action === 'status') {
            imp_require('imp', 'u');
            $to = (string)($_POST['to'] ?? '');
            $reason = trim((string)($_POST['reason'] ?? ''));
            if (!in_array($to, ['closed', 'cancelled', 'open'], true)) throw new Exception('Unknown status.');
            if ($reason === '') throw new Exception('Give a reason.');
            db()->prepare("UPDATE imp_imports SET manual_status=?, updated_by=?, updated_at=NOW() WHERE id=?")
                ->execute([$to === 'open' ? null : $to, (int)(current_user()['id'] ?? 0), $id]);
            imp_log($id, $to === 'open' ? 'Reopened' : ucfirst($to), '', $reason);
            $_SESSION['flash'] = $to === 'open' ? 'Import reopened.' : 'Import marked ' . $to . '.';
        }
        if ($action === 'delete') {
            imp_require('imp', 'd');
            if (trim((string)($_POST['confirm_ref'] ?? '')) !== $imp['ref']) throw new Exception('Type the import reference exactly to delete it.');
            /* never with money or documents on it — void / delete those first */
            foreach ([['imp_payments', 'is_void=0', 'payments'], ['imp_costs', 'is_void=0', 'costs'], ['imp_documents', '1=1', 'documents']] as [$tb, $w, $lbl]) {
                $c = db()->prepare("SELECT COUNT(*) FROM $tb WHERE import_id=? AND $w"); $c->execute([$id]);
                if ((int)$c->fetchColumn() > 0) throw new Exception('This import has ' . $lbl . ' on it. Void or remove them first.');
            }
            db()->beginTransaction();
            foreach (['imp_lines', 'imp_invoices', 'imp_packing', 'imp_containers', 'imp_logistics', 'imp_payments', 'imp_costs'] as $tb)
                db()->prepare("DELETE FROM $tb WHERE import_id=?")->execute([$id]);
            db()->prepare("DELETE FROM imp_imports WHERE id=?")->execute([$id]);
            imp_log($id, 'Import deleted', $imp['ref'] . ' · ' . $imp['supplier_name']);
            db()->commit();
            $_SESSION['flash'] = $imp['ref'] . ' deleted.';
            redirect('imp_register.php');
        }
    } catch (Throwable $e) {
        if (db()->inTransaction()) db()->rollBack();
        $_SESSION['error'] = $e->getMessage();
    }
    redirect('imp_view.php?id=' . $id);
}

$lines = imp_lines($id);
$invoices = imp_invoices($id) ?: [['id' => 0, 'invoice_no' => '', 'invoice_date' => null, 'gd_no' => '', 'gd_date' => null]];
$log = imp_logistics($id);
$tot = imp_totals($lines);
$m = imp_maturity($imp, $log, $invoices);
$st = imp_stage($imp, $log);
$alerts = imp_alerts($imp, $log, imp_paid_summary($imp, $lines));
$canEdit = imp_can('imp', 'u');
$ro = $canEdit ? '' : ' disabled';
$sups = imp_suppliers();
if (!empty($imp['supplier_party_id']) && !isset($sups[(int)$imp['supplier_party_id']])) $sups[(int)$imp['supplier_party_id']] = $imp['supplier_name'];

/* the flow, from the dates already typed */
$order = ['contract', 'booked', 'sailing', 'arrived', 'customs', 'released', 'left_port', 'at_factory'];
$at = array_search($st['key'], $order, true);
$steps = [['Contract', 0], ['Booking', 1], ['Sailing', 2], ['Arrival', 3], ['Customs', 4], ['Released', 5], ['Port gate out', 6], ['Factory gate in', 7]];

page_header('Import ' . $imp['ref']);
flash();
echo imp_page_css();
imp_tab_strip($imp, 'overview');
?>
<?php foreach ($alerts as $a): ?><div class="<?= $a[0] === 'r' ? 'xbad' : 'xwarn' ?>" style="margin-bottom:10px"><b><?= e($a[1]) ?></b></div><?php endforeach; ?>

<div class="xcard"><div class="flow">
  <?php foreach ($steps as $i => $s): $cls = $at === false ? '' : ($s[1] < $at ? 'done' : ($s[1] === $at ? 'now' : '')); ?>
    <?= $i ? '<i>›</i>' : '' ?><span class="<?= $cls ?>"><?= e($s[0]) ?></span>
  <?php endforeach; ?>
  <i>›</i><span title="Phase 2">Stock</span><i>›</i><span title="Phase 3">EFS</span>
</div></div>

<form method="post" id="ovForm">
<?= csrf_field() ?>
<input type="hidden" name="action" value="save">
<input type="hidden" name="import_id" value="<?= $id ?>">

<div class="xcard">
  <h2>Contract</h2>
  <div class="xgrid">
    <?= imp_input('Import reference', '_ref', $imp['ref'], 'text', ' readonly') ?>
    <?= imp_input('Responsible employee', 'employee', $imp['employee'], 'text', $ro) ?>
    <label class="xlabel">Supplier<select class="xin" name="supplier_party_id"<?= $ro ?>>
      <?php foreach ($sups as $sid => $sn): ?><option value="<?= (int)$sid ?>"<?= (int)$imp['supplier_party_id'] === (int)$sid ? ' selected' : '' ?>><?= e($sn) ?></option><?php endforeach; ?>
      <?php if (!$sups): ?><option value=""><?= e($imp['supplier_name']) ?></option><?php endif; ?>
    </select></label>
    <?= imp_input('Country of origin', 'origin_country', $imp['origin_country'], 'text', $ro) ?>
    <?= imp_input('Shipped from (country / port)', 'shipped_from', $imp['shipped_from'], 'text', $ro) ?>
    <?= imp_input('Contract / PO no.', 'contract_no', $imp['contract_no'], 'text', $ro) ?>
    <?= imp_input('Contract date', 'contract_date', $imp['contract_date'], 'date', $ro) ?>
    <?= imp_select('Incoterm', 'incoterm', array_merge([''], IMP_INCOTERMS), $imp['incoterm'], $ro) ?>
    <?= imp_input('Named place', 'incoterm_place', $imp['incoterm_place'], 'text', $ro . ' placeholder="e.g. Karachi"') ?>
    <?= imp_select('Currency', 'currency', IMP_CURRENCIES, $imp['currency'], $ro) ?>
    <?= imp_select('Import type', 'import_type', ['efs' => 'EFS', 'ordinary' => 'Ordinary (duty paid)'], $imp['import_type'], $ro . ' id="typeSel"', true) ?>
  </div>
  <div class="xnote" id="efsNote" style="margin-top:12px;<?= $imp['import_type'] === 'efs' ? '' : 'display:none' ?>"><b>EFS import.</b> Each line is checked on its own — EFS here does not make every product eligible. The EFS tab shows what must be exported against each line.</div>
  <?php if ((int)$imp['is_opening'] === 1): ?>
    <label class="tog" style="margin-top:6px"><input type="checkbox" name="already_in_stock" value="1"<?= (int)$imp['already_in_stock'] === 1 ? ' checked' : '' ?><?= $ro ?>> Opening import — the goods are already in Store &amp; Inventory (Gate In will not add them again)</label>
  <?php endif; ?>
</div>

<div class="xcard">
  <div style="display:flex;justify-content:space-between;align-items:center"><h2 style="margin:0">Supplier invoices &amp; GDs</h2><span class="imp-small imp-muted"><span id="ivCount"><?= count($invoices) ?></span> of <?= IMP_MAX_INVOICES ?></span></div>
  <div class="xwrap" style="margin-top:10px"><table class="xtable" id="ivTable">
    <thead><tr><th>#</th><th>Supplier invoice no.</th><th>Invoice date</th><th>GD no. (when filed)</th><th>GD date</th><th></th></tr></thead>
    <tbody>
    <?php foreach ($invoices as $n => $iv): ?>
      <tr><td><?= $n + 1 ?><input type="hidden" name="iv_id[]" value="<?= (int)$iv['id'] ?>"></td>
        <td><input class="xin" name="iv_no[]" value="<?= e($iv['invoice_no']) ?>"<?= $ro ?>></td>
        <td><input class="xin" type="date" name="iv_date[]" value="<?= e($iv['invoice_date']) ?>"<?= $ro ?>></td>
        <td><input class="xin" name="iv_gd[]" value="<?= e($iv['gd_no']) ?>"<?= $ro ?> placeholder="Phase 2 fills GD lines"></td>
        <td><input class="xin" type="date" name="iv_gd_date[]" value="<?= e($iv['gd_date']) ?>"<?= $ro ?>></td>
        <td><?php if ($n > 0 && $canEdit): ?><button type="button" class="xbtn red sm" onclick="this.closest('tr').remove();ivCount()">Remove</button><?php endif; ?></td></tr>
    <?php endforeach; ?>
    </tbody>
  </table></div>
  <?php if ($canEdit): ?><div style="margin-top:8px"><button type="button" class="xbtn sec sm" id="ivAdd">+ Add another supplier invoice / GD</button> <span class="imp-small imp-muted">Usually one. Up to three for the rare shipment that needs it.</span></div><?php endif; ?>
</div>

<div class="xcard">
  <h2>Payment terms</h2>
  <div class="xgrid">
    <?= imp_select('Method', 'pay_method', array_merge([''], IMP_PAY_METHODS), $imp['pay_method'], $ro) ?>
    <?= imp_input('Advance %', 'advance_pct', rtrim(rtrim((string)$imp['advance_pct'], '0'), '.'), 'number', $ro) ?>
  </div>
  <label class="tog"><input type="checkbox" name="credit" value="1" data-tog="credit"<?= (int)$imp['credit'] === 1 ? ' checked' : '' ?><?= $ro ?>> Credit period</label>
  <div class="reveal" data-show="credit">
    <div class="xgrid">
      <?= imp_input('Credit days', 'credit_days', $imp['credit_days'], 'number', $ro . ' id="credDays"') ?>
      <?= imp_select('Days counted from', 'maturity_basis', IMP_BASIS, $imp['maturity_basis'], $ro . ' id="basisSel"', true) ?>
      <span data-show="fixed"><?= imp_input('Agreed fixed due date', 'fixed_due_date', $imp['fixed_due_date'], 'date', $ro . ' id="fixedDate"') ?></span>
      <label class="xlabel">Balance due<div class="xin calc" id="dueOut" style="background:#f6f8fc"><?= e($m['date'] ? imp_dmy($m['date']) : '—') ?></div></label>
    </div>
    <div class="imp-small imp-muted" id="dueFrom" style="margin-top:6px"><?= e($m['from']) ?></div>
  </div>
  <details style="margin:6px 0 4px"><summary class="imp-small" style="cursor:pointer;color:#5a6b82">Set the due date by hand (needs a reason)</summary>
    <div class="xgrid" style="margin-top:8px"><?= imp_input('Due date', 'due_override', $imp['due_override'], 'date', $ro) ?><?= imp_input('Reason', 'due_override_reason', $imp['due_override_reason'], 'text', $ro) ?></div>
  </details>
  <label class="tog"><input type="checkbox" name="lc" value="1" data-tog="lc"<?= (int)$imp['lc'] === 1 ? ' checked' : '' ?><?= $ro ?>> Letter of credit (LC)</label>
  <div class="reveal" data-show="lc"><div class="xgrid">
    <?= imp_input('LC no.', 'lc_no', $imp['lc_no'], 'text', $ro) ?><?= imp_input('Opening bank', 'lc_bank', $imp['lc_bank'], 'text', $ro) ?>
    <?= imp_input('LC expiry', 'lc_expiry', $imp['lc_expiry'], 'date', $ro) ?><?= imp_input('Latest shipment date', 'lc_last_ship', $imp['lc_last_ship'], 'date', $ro) ?>
  </div></div>
  <label class="tog"><input type="checkbox" name="fta" value="1" data-tog="fta"<?= (int)$imp['fta'] === 1 ? ' checked' : '' ?><?= $ro ?>> FTA / preferential origin</label>
  <div class="reveal" data-show="fta"><div class="xgrid">
    <?= imp_input('Agreement', 'fta_agreement', $imp['fta_agreement'], 'text', $ro . ' placeholder="e.g. Pakistan–China FTA"') ?><?= imp_input('Certificate / form', 'fta_form', $imp['fta_form'], 'text', $ro) ?>
    <?= imp_input('Certificate no.', 'fta_cert_no', $imp['fta_cert_no'], 'text', $ro) ?><?= imp_input('Certificate date', 'fta_cert_date', $imp['fta_cert_date'], 'date', $ro) ?>
  </div><div class="imp-small imp-muted" style="margin-top:6px">FTA preference, product eligibility and EFS are three separate checks.</div></div>
  <label class="tog"><input type="checkbox" name="commission" value="1" data-tog="commission"<?= (int)$imp['commission'] === 1 ? ' checked' : '' ?><?= $ro ?>> Commission</label>
  <div class="reveal" data-show="commission"><div class="xgrid">
    <?= imp_input('Beneficiary', 'com_beneficiary', $imp['com_beneficiary'], 'text', $ro) ?>
    <?= imp_input('Rate % of invoice', 'com_rate_pct', rtrim(rtrim((string)$imp['com_rate_pct'], '0'), '.'), 'number', $ro . ' id="comRate"') ?>
    <label class="xlabel">Commission amount<div class="xin calc" id="comOut" style="background:#f6f8fc">—</div></label>
  </div></div>
  <label class="xlabel" style="margin-top:8px">Notes<textarea class="xin" name="notes" rows="2"<?= $ro ?>><?= e($imp['notes']) ?></textarea></label>
  <div class="xnote imp-small" style="margin-top:10px">Switching an option off only hides it — what was typed is kept, and is not checked or counted while it is off.</div>
</div>
<?php if ($canEdit): ?><div style="margin-bottom:14px"><button class="xbtn">Save</button></div><?php endif; ?>
</form>

<?php if ($canEdit): $isOpen = !in_array($st['key'], ['closed', 'cancelled'], true); ?>
<div class="xcard">
  <h2>Status</h2>
  <form method="post" style="display:flex;gap:9px;flex-wrap:wrap;align-items:flex-end">
    <?= csrf_field() ?><input type="hidden" name="action" value="status"><input type="hidden" name="import_id" value="<?= $id ?>">
    <label class="xlabel" style="flex:1;min-width:220px">Reason<input class="xin" name="reason" required placeholder="<?= $isOpen ? 'e.g. all received and paid' : 'why it is opened again' ?>"></label>
    <?php if ($isOpen): ?>
      <button class="xbtn sec" name="to" value="closed">Mark closed</button><button class="xbtn red" name="to" value="cancelled">Cancel import</button>
    <?php else: ?><button class="xbtn sec" name="to" value="open">Reopen</button><?php endif; ?>
  </form>
  <?php if (imp_can('imp', 'd')): ?>
  <details style="margin-top:12px"><summary class="imp-small" style="cursor:pointer;color:#b8283f">Delete this import…</summary>
    <form method="post" style="display:flex;gap:9px;flex-wrap:wrap;align-items:flex-end;margin-top:8px" onsubmit="return confirm('Delete <?= e($imp['ref']) ?> permanently?')">
      <?= csrf_field() ?><input type="hidden" name="action" value="delete"><input type="hidden" name="import_id" value="<?= $id ?>">
      <label class="xlabel">Type <b><?= e($imp['ref']) ?></b> to confirm<input class="xin" name="confirm_ref" autocomplete="off"></label>
      <button class="xbtn red">Delete permanently</button>
      <span class="imp-small imp-muted">Only when no payments, costs or documents are on it.</span>
    </form></details>
  <?php endif; ?>
</div>
<?php endif; ?>

<script>
/* toggles show and hide without a reload; the due date and the commission
   follow as they are typed (the server works them out again on save) */
(function () {
  var F = document.getElementById('ovForm');
  var D = { bl: <?= json_encode($log['bl_date'] ?? null) ?>, arrival: <?= json_encode($log['arrival'] ?? null) ?>, eta: <?= json_encode($log['eta'] ?? null) ?>,
            invoice: <?= json_encode($invoices[0]['invoice_date'] ?? null) ?>, total: <?= json_encode($tot['value']) ?>, cur: <?= json_encode($imp['currency']) ?> };
  function show() {
    F.querySelectorAll('[data-tog]').forEach(function (t) { F.querySelectorAll('[data-show="' + t.dataset.tog + '"]').forEach(function (el) { el.style.display = t.checked ? '' : 'none'; }); });
    var b = document.getElementById('basisSel'); F.querySelectorAll('[data-show="fixed"]').forEach(function (el) { el.style.display = b && b.value === 'fixed' ? '' : 'none'; });
    var ts = document.getElementById('typeSel'); document.getElementById('efsNote').style.display = ts && ts.value === 'efs' ? '' : 'none';
  }
  function fmt(iso) { if (!iso) return '—'; var d = new Date(iso + 'T00:00:00Z'); return d.toLocaleDateString('en-GB', { day: '2-digit', month: 'short', year: 'numeric', timeZone: 'UTC' }); }
  function add(iso, n) { var d = new Date(iso + 'T00:00:00Z'); d.setUTCDate(d.getUTCDate() + n); return d.toISOString().slice(0, 10); }
  function due() {
    var b = (document.getElementById('basisSel') || {}).value, n = parseInt((document.getElementById('credDays') || {}).value || '0', 10) || 0, from = null, lbl = '';
    if (b === 'fixed') { var fd = (document.getElementById('fixedDate') || {}).value; document.getElementById('dueOut').textContent = fmt(fd); document.getElementById('dueFrom').textContent = 'agreed fixed date'; return; }
    if (b === 'bl') { from = D.bl; lbl = 'BL date'; } else if (b === 'arrival') { from = D.arrival || D.eta; lbl = D.arrival ? 'arrival date' : 'expected arrival (ETA)'; } else { from = D.invoice; lbl = 'invoice date'; }
    document.getElementById('dueOut').textContent = from ? fmt(add(from, n)) : '—';
    document.getElementById('dueFrom').textContent = from ? lbl + ' ' + fmt(from) + ' + ' + n + ' days' : 'waits for the ' + lbl;
  }
  function com() { var r = parseFloat((document.getElementById('comRate') || {}).value || '0') || 0; var o = document.getElementById('comOut'); if (o) o.textContent = D.cur + ' ' + (D.total * r / 100).toLocaleString('en-US', { minimumFractionDigits: 2, maximumFractionDigits: 2 }); }
  F.addEventListener('change', function () { show(); due(); com(); });
  F.addEventListener('input', function () { due(); com(); });
  window.ivCount = function () { var n = document.querySelectorAll('#ivTable tbody tr').length; document.getElementById('ivCount').textContent = n; var a = document.getElementById('ivAdd'); if (a) a.disabled = n >= <?= IMP_MAX_INVOICES ?>; };
  var add1 = document.getElementById('ivAdd');
  if (add1) add1.addEventListener('click', function () {
    var tb = document.querySelector('#ivTable tbody'); if (tb.children.length >= <?= IMP_MAX_INVOICES ?>) return;
    var tr = document.createElement('tr');
    tr.innerHTML = '<td>' + (tb.children.length + 1) + '<input type="hidden" name="iv_id[]" value="0"></td><td><input class="xin" name="iv_no[]"></td><td><input class="xin" type="date" name="iv_date[]"></td><td><input class="xin" name="iv_gd[]"></td><td><input class="xin" type="date" name="iv_gd_date[]"></td><td><button type="button" class="xbtn red sm" onclick="this.closest(\'tr\').remove();ivCount()">Remove</button></td>';
    tb.appendChild(tr); ivCount(); tr.querySelector('input[name="iv_no[]"]').focus();
  });
  show(); due(); com(); window.ivCount();
})();
</script>
<?php page_footer(); ?>
