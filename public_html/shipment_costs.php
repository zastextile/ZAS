<?php
/*
  COSTS TAB — what this shipment cost us, and the agreed-versus-final
  freight comparison.

  TWO THINGS WORTH KNOWING ABOUT THIS PAGE
  ----------------------------------------
  1. pkr_amount is STORED, not computed when the page opens. The rate that
     applied on the bill date is a historical fact. Recomputing it from
     today's fx_rates every time the page loaded would quietly rewrite last
     month's cost whenever the rupee moved.

  2. Commission rows are removed from the QUERY for a user without rate
     visibility, not hidden in the markup. Hiding a row but leaving it in the
     total would leak the number anyway — anyone can subtract.
*/
require_once __DIR__ . '/includes/bootstrap.php';
require_once __DIR__ . '/includes/export.php';
require_once __DIR__ . '/includes/textindex.php';

$shipment = exp_open_shipment('costs');
$id       = (int)$shipment['id'];

/* Someone may legitimately enter freight and agent bills without seeing our
   selling prices — that is the whole point of a separate permission. So this
   page does NOT require can_see_rates(); it narrows what is shown instead. */
$seeRates      = can_see_rates();
$seeCommission = $seeRates;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verify_csrf();
    /* KEEPING THE SEARCH INDEX CURRENT.
     *
     * Registered once, here, rather than bolted onto each of this page's
     * redirects — there are several and a new one would quietly skip the
     * index. A shutdown function runs after the response has gone, so this
     * cannot slow the save down, and every txt_* call swallows its own
     * errors, so it cannot break one either. Re-indexing an unchanged
     * record is harmless: the write is an upsert keyed on the record. */
    register_shutdown_function(function () use ($id) { txt_index_shipment_notes((int)$id); });

    $action = (string)($_POST['action'] ?? '');

    try {
        if ($action === 'save_cost') {
            $cid = (int)($_POST['cost_id'] ?? 0);
            exp_require('costs', $cid > 0 ? 'u' : 'c');

            $typeId = (int)($_POST['cost_type_id'] ?? 0);
            if ($typeId <= 0) throw new Exception('Choose an expense type.');

            /* A user who cannot see commissions must not be able to create one
               either — otherwise they could write a row they then cannot read. */
            if (!$seeCommission && in_array($typeId, exp_commission_type_ids(), true)) {
                throw new Exception('That expense type is not available to your user.');
            }

            $amount = (float)($_POST['amount'] ?? 0);
            if ($amount <= 0) throw new Exception('Amount must be more than zero. To cancel a bill, void it.');

            $currency = strtoupper(trim((string)($_POST['currency'] ?? 'PKR'))) ?: 'PKR';

            /* The rate is taken as typed when given, and only falls back to
               the Settings rate when the field is left empty. An agent's bill
               was converted at the bank's rate on the day, not at today's. */
            $rateIn = trim((string)($_POST['fx_rate'] ?? ''));
            if ($currency === 'PKR') {
                $rate = 1.0;
                $pkr  = round($amount, 2);
            } else {
                $rate = $rateIn !== '' ? (float)$rateIn : exp_pkr_rate($currency);
                if ($rate <= 0) throw new Exception('No exchange rate for ' . $currency . '. Type one in, or set it in Settings.');
                $pkr = round($amount * $rate, 2);
            }

            $isPaid = isset($_POST['is_paid']) ? 1 : 0;
            $paidOn = trim((string)($_POST['paid_on'] ?? ''));

            $vals = [
                $typeId,
                (int)($_POST['provider_id'] ?? 0) ?: null,
                trim((string)($_POST['bill_no'] ?? '')) ?: null,
                trim((string)($_POST['bill_date'] ?? '')) ?: null,
                $currency,
                round($amount, 2),
                round($rate, 4),
                $pkr,
                $isPaid,
                ($isPaid && $paidOn !== '') ? $paidOn : null,
                trim((string)($_POST['notes'] ?? '')) ?: null,
            ];

            if ($cid > 0) {
                $st = db()->prepare("SELECT * FROM shipment_costs WHERE id=? AND shipment_id=?");
                $st->execute([$cid, $id]); $before = $st->fetch();
                if (!$before) throw new Exception('Cost entry not found on this shipment.');
                if ((int)$before['is_void'] === 1) throw new Exception('A voided entry cannot be edited. Add a new one.');
                if (!$seeCommission && in_array((int)$before['cost_type_id'], exp_commission_type_ids(), true)) {
                    throw new Exception('That entry is not available to your user.');
                }

                $vals[] = current_user()['id']; $vals[] = $cid; $vals[] = $id;
                db()->prepare("UPDATE shipment_costs SET cost_type_id=?, provider_id=?, bill_no=?, bill_date=?, currency=?, amount=?, fx_rate=?, pkr_amount=?, is_paid=?, paid_on=?, notes=?, updated_by=?, updated_at=NOW() WHERE id=? AND shipment_id=?")
                    ->execute($vals);
                audit_log($id, 'Shipment Cost', 'amount',
                          (string)$before['currency'] . ' ' . (string)$before['amount'],
                          $currency . ' ' . round($amount, 2), 'Cost entry edited');
            } else {
                array_unshift($vals, $id);
                $vals[] = current_user()['id'];
                db()->prepare("INSERT INTO shipment_costs (shipment_id, cost_type_id, provider_id, bill_no, bill_date, currency, amount, fx_rate, pkr_amount, is_paid, paid_on, notes, created_by) VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?)")
                    ->execute($vals);
                audit_log($id, 'Shipment Cost', 'bill', '', $currency . ' ' . round($amount, 2), 'Cost entry added');
            }

            $_SESSION['flash'] = 'Cost entry saved.';
            redirect('shipment_costs.php?id=' . $id);
        }

        if ($action === 'void_cost') {
            exp_require('costs', 'r');
            $cid    = (int)($_POST['cost_id'] ?? 0);
            $reason = trim((string)($_POST['void_reason'] ?? ''));
            if ($reason === '') throw new Exception('A reason is required to void a cost entry.');

            $st = db()->prepare("SELECT * FROM shipment_costs WHERE id=? AND shipment_id=?");
            $st->execute([$cid, $id]); $row = $st->fetch();
            if (!$row) throw new Exception('Cost entry not found on this shipment.');
            if ((int)$row['is_void'] === 1) throw new Exception('That entry is already voided.');
            if (!$seeCommission && in_array((int)$row['cost_type_id'], exp_commission_type_ids(), true)) {
                throw new Exception('That entry is not available to your user.');
            }

            db()->prepare("UPDATE shipment_costs SET is_void=1, void_reason=?, voided_by=?, voided_at=NOW() WHERE id=? AND shipment_id=?")
                ->execute([$reason, current_user()['id'], $cid, $id]);
            audit_log($id, 'Shipment Cost', 'void', (string)$row['currency'] . ' ' . (string)$row['amount'], 'VOIDED', $reason);
            $_SESSION['flash'] = 'Cost entry voided. It stays on the record with your reason.';
            redirect('shipment_costs.php?id=' . $id);
        }
    } catch (Throwable $e) {
        $_SESSION['error'] = $e->getMessage();
        redirect('shipment_costs.php?id=' . $id);
    }
}

$log      = exp_logistics($id);
$costs    = exp_costs($id, $seeCommission);
$summary  = exp_cost_summary($costs);
$freight  = $seeRates ? exp_freight_compare($id, $log) : null;

$types    = exp_masters('cost_type');
if (!$seeCommission) {
    $hide  = exp_commission_type_ids();
    $types = array_values(array_filter($types, fn($t) => !in_array((int)$t['id'], $hide, true)));
}
$typeMap  = exp_master_map('cost_type');
$provMap  = exp_provider_map();
$providers = exp_providers('', true);

$edit = null;
if (isset($_GET['edit'])) {
    $st = db()->prepare("SELECT * FROM shipment_costs WHERE id=? AND shipment_id=? AND is_void=0");
    $st->execute([(int)$_GET['edit'], $id]); $edit = $st->fetch() ?: null;
    if ($edit && !$seeCommission && in_array((int)$edit['cost_type_id'], exp_commission_type_ids(), true)) $edit = null;
}

$canAdd  = exp_can('costs', 'c');
$canVoid = exp_can('costs', 'r');

/* One query for every document attached to a row on this shipment. */
$attached  = exp_attached_docs($id);
$canDocs   = exp_can('documents');
$canDocAdd = exp_can('documents', 'c');

page_header('Costs — ' . $shipment['invoice_no']);
flash();
echo exp_page_css();
exp_tab_strip($shipment, 'costs');
?>

<div class="xkpi">
  <div class="k"><div class="l">Total Cost (PKR)</div><div class="v"><?= e(num_fmt($summary['pkr'], 0)) ?></div></div>
  <div class="k"><div class="l">Unpaid (PKR)</div><div class="v" style="color:<?= $summary['unpaid_pkr'] > 0 ? '#b8283f' : '#5a6b82' ?>"><?= e(num_fmt($summary['unpaid_pkr'], 0)) ?></div></div>
  <div class="k"><div class="l">Entries</div><div class="v"><?= (int)$summary['count'] ?></div></div>
</div>

<?php if ($freight !== null): ?>
<div class="xcard" style="border-color:<?= ($freight['var_amount'] !== null && $freight['var_amount'] > 0) ? 'rgba(217,119,6,.35)' : '#e3e9f2' ?>">
  <h2>Freight — Agreed versus Final Bill</h2>
  <div class="xwrap">
    <table class="xtable">
      <tbody>
        <tr>
          <td style="width:140px;font-weight:600">Agreed</td>
          <td class="num" style="width:130px">
            <?= $freight['agreed'] !== null ? e(($freight['agreed_currency'] ?: '') . ' ' . num_fmt($freight['agreed'], 2)) : '<span style="color:#8a97ab">not recorded</span>' ?>
          </td>
          <td class="num" style="width:150px"><?= $freight['agreed_pkr'] ? 'PKR ' . e(num_fmt($freight['agreed_pkr'], 0)) : '' ?></td>
          <td style="color:#8a97ab;font-size:11.5px">
            <?= $freight['quote_ref'] !== '' ? 'Quote ' . e($freight['quote_ref']) : '' ?>
            <?= $freight['quote_date'] ? ' &middot; ' . e(date('d M Y', strtotime((string)$freight['quote_date']))) : '' ?>
          </td>
        </tr>
        <tr>
          <td style="font-weight:600">Final Bill</td>
          <td class="num">
            <?php if ($freight['final'] !== null): ?>
              <?= e($freight['final_currency'] . ' ' . num_fmt($freight['final'], 2)) ?>
            <?php elseif ($freight['final_pkr'] > 0): ?>
              <span style="color:#8a97ab">mixed currencies</span>
            <?php else: ?>
              <span style="color:#8a97ab">awaited</span>
            <?php endif; ?>
          </td>
          <td class="num"><?= $freight['final_pkr'] > 0 ? 'PKR ' . e(num_fmt($freight['final_pkr'], 0)) : '' ?></td>
          <td style="color:#8a97ab;font-size:11.5px"><?= $freight['bill_no'] !== '' ? 'Bill ' . e($freight['bill_no']) : '' ?></td>
        </tr>
        <?php if ($freight['awaiting_bill']): ?>
        <tr style="border-top:2px solid #e3e9f2">
          <td colspan="4" style="color:#9a5a06;font-size:12.5px">
            No freight bill has arrived yet, so there is nothing to compare. This deliberately does not
            show a zero variance — that would read as agreement.
          </td>
        </tr>
        <?php elseif ($freight['var_amount'] !== null || $freight['var_pkr'] !== null): ?>
        <tr style="border-top:2px solid #e3e9f2;font-weight:700">
          <td>Variance</td>
          <td class="num" style="color:<?= ($freight['var_amount'] ?? 0) > 0 ? '#b8283f' : '#16a34a' ?>">
            <?php if ($freight['var_amount'] !== null): ?>
              <?= ($freight['var_amount'] > 0 ? '+' : '') . e($freight['final_currency'] . ' ' . num_fmt($freight['var_amount'], 2)) ?>
            <?php endif; ?>
          </td>
          <td class="num" style="color:<?= ($freight['var_pkr'] ?? 0) > 0 ? '#b8283f' : '#16a34a' ?>">
            <?php if ($freight['var_pkr'] !== null): ?>
              <?= ($freight['var_pkr'] > 0 ? '+' : '') . 'PKR ' . e(num_fmt($freight['var_pkr'], 0)) ?>
            <?php endif; ?>
          </td>
          <td style="font-size:12px;color:<?= ($freight['var_pct'] ?? 0) > 0 ? '#b8283f' : '#16a34a' ?>">
            <?= $freight['var_pct'] !== null ? (($freight['var_pct'] > 0 ? '+' : '') . e(num_fmt($freight['var_pct'], 1)) . '%') : '' ?>
          </td>
        </tr>
        <?php endif; ?>
      </tbody>
    </table>
  </div>
  <?php if ($freight['agreed'] === null): ?>
    <div class="xnote" style="margin-top:11px">
      Record the agreed freight on the <a href="shipment_logistics.php?id=<?= $id ?>" style="color:#0ea8c9;font-weight:600">LOGISTICS tab</a> and the comparison fills in here.
    </div>
  <?php endif; ?>
</div>
<?php endif; ?>

<div class="xcard">
  <div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:11px;flex-wrap:wrap;gap:8px">
    <h2 style="margin:0">Cost Ledger</h2>
    <?php if (!$seeCommission): ?>
      <span style="font-size:11.5px;color:#8a97ab">Commission entries are not shown for your user</span>
    <?php endif; ?>
  </div>

  <div class="xwrap">
    <table class="xtable">
      <thead><tr>
        <th>Expense Type</th><th>Provider</th><th>Bill No.</th><th>Date</th>
        <th class="num">Amount</th><th class="num">Rate</th><th class="num">PKR</th>
        <th>Paid</th><th></th>
      </tr></thead>
      <tbody>
      <?php if (!$costs): ?>
        <tr><td colspan="9" style="padding:22px;text-align:center;color:#8a97ab">No costs recorded against this shipment yet.</td></tr>
      <?php endif; ?>
      <?php foreach ($costs as $c): $void = (int)$c['is_void'] === 1; ?>
        <tr class="<?= $void ? 'void' : '' ?>">
          <td style="font-weight:600"><?= e($typeMap[(int)($c['cost_type_id'] ?? 0)] ?? '') ?></td>
          <td><?= e($provMap[(int)($c['provider_id'] ?? 0)] ?? '') ?></td>
          <td style="font-size:11.5px"><?= e($c['bill_no']) ?></td>
          <td><?= $c['bill_date'] ? e(date('d M Y', strtotime((string)$c['bill_date']))) : '' ?></td>
          <td class="num"><?= e($c['currency'] . ' ' . num_fmt($c['amount'], 2)) ?></td>
          <td class="num"><?= ((string)$c['currency'] !== 'PKR' && $c['fx_rate'] !== null) ? e(num_fmt($c['fx_rate'], 4)) : '' ?></td>
          <td class="num" style="font-weight:700"><?= e(num_fmt($c['pkr_amount'], 0)) ?></td>
          <td>
            <?php if ($void): ?>
            <?php elseif ((int)$c['is_paid'] === 1): ?>
              <span class="xpill g">Paid<?= $c['paid_on'] ? ' ' . e(date('d/m/y', strtotime((string)$c['paid_on']))) : '' ?></span>
            <?php else: ?>
              <span class="xpill o">Unpaid</span>
            <?php endif; ?>
          </td>
          <td style="white-space:nowrap">
            <?php if ($void): ?>
              <span class="xpill r" title="<?= e($c['void_reason']) ?>">Voided</span>
            <?php else: ?>
              <?php
              /* The bill for THIS cost row: download it when one is attached,
                 otherwise an Attach button carrying this row's id so the
                 upload comes back here. */
              $bd = $attached[(int)($c['doc_id'] ?? 0)] ?? null;
              if ($bd && $canDocs): ?>
                <a class="xbtn sec sm" href="shipment_doc_file.php?doc=<?= (int)$bd['id'] ?>"
                   title="<?= e($bd['original_name']) ?>">Bill</a>
              <?php elseif ($canDocAdd): ?>
                <a class="xbtn sec sm" href="shipment_documents.php?id=<?= $id ?>&for=cost:<?= (int)$c['id'] ?>">Attach</a>
              <?php endif; ?>
              <?php if (exp_can('costs', 'u')): ?>
                <a class="xbtn sec sm" href="shipment_costs.php?id=<?= $id ?>&edit=<?= (int)$c['id'] ?>#cform">Edit</a>
              <?php endif; ?>
              <?php if ($canVoid): ?>
                <button type="button" class="xbtn red sm" onclick="voidCost(<?= (int)$c['id'] ?>,'<?= e(addslashes($c['currency'] . ' ' . num_fmt($c['amount'], 2))) ?>')">Void</button>
              <?php endif; ?>
            <?php endif; ?>
          </td>
        </tr>
        <?php if ($void): ?>
          <tr><td colspan="9" style="border-top:none;padding-top:0;font-size:11.5px;color:#b8283f">Voided: <?= e($c['void_reason']) ?></td></tr>
        <?php endif; ?>
      <?php endforeach; ?>
      </tbody>
      <?php if ($costs): ?>
      <tfoot>
        <tr style="border-top:2px solid #e3e9f2;font-weight:700">
          <td colspan="6" style="text-align:right">Total PKR</td>
          <td class="num"><?= e(num_fmt($summary['pkr'], 0)) ?></td>
          <td colspan="2"></td>
        </tr>
      </tfoot>
      <?php endif; ?>
    </table>
  </div>
</div>

<?php if ($canAdd || $edit): ?>
<div class="xcard" id="cform">
  <h2><?= $edit ? 'Edit Cost Entry' : 'Add a Cost' ?></h2>
  <form method="post" id="costForm">
    <?= csrf_field() ?>
    <input type="hidden" name="action" value="save_cost">
    <input type="hidden" name="shipment_id" value="<?= $id ?>">
    <input type="hidden" name="cost_id" value="<?= (int)($edit['id'] ?? 0) ?>">

    <div class="xgrid">
      <label class="xlabel">Expense Type
        <select class="xin" name="cost_type_id" required>
          <option value="">—</option>
          <?php foreach ($types as $t): ?>
            <option value="<?= (int)$t['id'] ?>" <?= (int)($edit['cost_type_id'] ?? 0) === (int)$t['id'] ? 'selected' : '' ?>><?= e($t['label']) ?></option>
          <?php endforeach; ?>
        </select>
      </label>
      <label class="xlabel">Service Provider
        <select class="xin" name="provider_id">
          <option value="">—</option>
          <?php foreach ($providers as $p): ?>
            <option value="<?= (int)$p['id'] ?>" <?= (int)($edit['provider_id'] ?? 0) === (int)$p['id'] ? 'selected' : '' ?>><?= e($p['name']) ?></option>
          <?php endforeach; ?>
        </select>
      </label>
      <label class="xlabel">Bill / Invoice No.<input class="xin" name="bill_no" value="<?= e($edit['bill_no'] ?? '') ?>"></label>
      <label class="xlabel">Bill Date<input class="xin" type="date" name="bill_date" value="<?= e($edit['bill_date'] ?? date('Y-m-d')) ?>"></label>

      <label class="xlabel">Currency
        <select class="xin" name="currency" id="curIn">
          <?php foreach (['PKR','USD','EUR','GBP'] as $c): ?>
            <option value="<?= $c ?>" <?= (string)($edit['currency'] ?? 'PKR') === $c ? 'selected' : '' ?>><?= $c ?></option>
          <?php endforeach; ?>
        </select>
      </label>
      <label class="xlabel">Amount<input class="xin" name="amount" id="amtIn" required value="<?= e($edit['amount'] ?? '') ?>"></label>
      <label class="xlabel">FX Rate to PKR
        <input class="xin" name="fx_rate" id="rateIn" value="<?= e($edit['fx_rate'] ?? '') ?>"
               placeholder="blank = today's Settings rate">
      </label>
      <label class="xlabel">PKR Equivalent
        <input class="xin" id="pkrOut" value="<?= e($edit ? num_fmt($edit['pkr_amount'], 2) : '') ?>" readonly>
      </label>

      <label class="xlabel" style="display:flex;align-items:center;gap:8px;padding-top:18px">
        <input type="checkbox" name="is_paid" id="paidIn" <?= (int)($edit['is_paid'] ?? 0) === 1 ? 'checked' : '' ?> style="width:15px;height:15px;accent-color:#0ea8c9"> Paid
      </label>
      <label class="xlabel">Payment Date<input class="xin" type="date" name="paid_on" value="<?= e($edit['paid_on'] ?? '') ?>"></label>
      <label class="xlabel xspan2">Notes<input class="xin" name="notes" value="<?= e($edit['notes'] ?? '') ?>"></label>
    </div>

    <div style="display:flex;gap:9px;margin-top:13px;flex-wrap:wrap">
      <button class="xbtn"><?= $edit ? 'Update Cost' : 'Add Cost' ?></button>
      <?php if ($edit): ?><a class="xbtn sec" href="shipment_costs.php?id=<?= $id ?>">Cancel</a><?php endif; ?>
      <?php if ($canDocs): ?>
        <a class="xbtn sec" href="shipment_documents.php?id=<?= $id ?>">Open Documents</a>
      <?php endif; ?>
    </div>
  </form>
  <div class="xnote" style="margin-top:11px">
    Leave the rate blank and today's Settings rate is used. Type one in and that is what is stored —
    an agent's bill was converted at the rate on the day, and the saved figure never changes afterwards.
  </div>
</div>
<?php endif; ?>

<?php if ($canVoid): ?>
<div id="voidModal" style="display:none;position:fixed;inset:0;background:rgba(10,15,30,.5);z-index:999;align-items:center;justify-content:center;padding:16px">
  <div style="background:#fff;border-radius:16px;padding:22px;max-width:420px;width:100%;border:1px solid #e3e9f2">
    <h2 style="font-size:15px;margin:0 0 7px;color:#b8283f">Void cost of <span id="vAmt"></span>?</h2>
    <p style="font-size:12.5px;color:#5a6b82;line-height:1.55;margin:0 0 14px">
      The entry stays on the ledger struck through, with your reason and your name, and stops counting
      towards the total.
    </p>
    <form method="post">
      <?= csrf_field() ?>
      <input type="hidden" name="action" value="void_cost">
      <input type="hidden" name="shipment_id" value="<?= $id ?>">
      <input type="hidden" name="cost_id" id="vId">
      <label class="xlabel">Reason <span style="color:#b8283f">*</span>
        <input class="xin" name="void_reason" id="vReason" required placeholder="e.g. agent reissued the bill">
      </label>
      <div style="display:flex;gap:9px;margin-top:15px">
        <button type="button" class="xbtn sec" style="flex:1" onclick="document.getElementById('voidModal').style.display='none'">Cancel</button>
        <button class="xbtn red" style="flex:1">Void Entry</button>
      </div>
    </form>
  </div>
</div>
<script>
function voidCost(id, amt) {
  document.getElementById('vId').value = id;
  document.getElementById('vAmt').textContent = amt;
  document.getElementById('vReason').value = '';
  document.getElementById('voidModal').style.display = 'flex';
  document.getElementById('vReason').focus();
}
</script>
<?php endif; ?>

<script>
/* The PKR figure is shown as you type so the number you are about to store is
   visible before you store it. The server recomputes it regardless — this is
   a preview, never the source of the saved value. */
(function () {
  var rates = <?= json_encode(['PKR' => 1.0, 'USD' => exp_pkr_rate('USD'), 'EUR' => exp_pkr_rate('EUR'), 'GBP' => exp_pkr_rate('GBP')]) ?>;
  var cur = document.getElementById('curIn');
  var amt = document.getElementById('amtIn');
  var rate = document.getElementById('rateIn');
  var out = document.getElementById('pkrOut');
  if (!cur || !amt || !rate || !out) return;

  function calc() {
    var c = cur.value || 'PKR';
    var a = parseFloat(amt.value || '0') || 0;
    var r = parseFloat(rate.value || '0') || 0;
    if (c === 'PKR') { r = 1; rate.placeholder = '1 (PKR)'; }
    else if (!r) { r = rates[c] || 0; rate.placeholder = r ? ("blank = " + r) : 'no rate in Settings'; }
    out.value = r > 0 ? (a * r).toFixed(2) : '';
  }
  cur.addEventListener('change', calc);
  amt.addEventListener('input', calc);
  rate.addEventListener('input', calc);
  calc();

  /* Ticking Paid fills today's date if none was given — the common case. */
  var paid = document.getElementById('paidIn');
  var pOn = document.querySelector('input[name="paid_on"]');
  if (paid && pOn) {
    paid.addEventListener('change', function () {
      if (paid.checked && !pOn.value) pOn.value = new Date().toISOString().slice(0, 10);
    });
  }

  document.addEventListener('keydown', function (e) {
    if ((e.ctrlKey || e.metaKey) && (e.key === 's' || e.key === 'S')) {
      e.preventDefault();
      var f = document.getElementById('costForm');
      if (f) f.submit();
    }
    if (e.key === 'Escape') {
      var m = document.getElementById('voidModal');
      if (m && m.style.display === 'flex') { m.style.display = 'none'; return; }
      window.location.href = 'shipment_view.php?id=<?= $id ?>';
    }
  });
})();
</script>
<?php page_footer(); ?>
