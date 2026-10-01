<?php
/*
  PAYMENTS TAB — what the buyer has actually sent against this invoice.

  THE ONE RULE THAT SHAPES THIS WHOLE PAGE
  ----------------------------------------
  A payment is recorded in the INVOICE's currency. Not in whatever currency
  the money arrived as, and not in rupees.

  The reason is arithmetic. A USD 50,000 invoice settled by three payments
  converted at three different rates almost never sums back to 50,000 — it
  lands on 49,987.60 and the status never reaches PAID, so somebody spends an
  afternoon chasing twelve dollars that do not exist. Keeping the ledger in
  one currency makes 10,000 + 20,000 + 15,000 exactly 45,000, for ever.

  The PKR your bank actually credited is still recorded, on the same row, for
  reconciliation and profitability. It never enters the balance.

  Nothing is deleted here. A wrong payment is voided with a reason and stays
  on the record, struck through.
*/
require_once __DIR__ . '/includes/bootstrap.php';
require_once __DIR__ . '/includes/export.php';

$shipment = exp_open_shipment('payments');
$id       = (int)$shipment['id'];
$cur      = (string)($shipment['currency'] ?? 'USD');

/* Money is the whole point of this page, so rate visibility is required to
   open it at all — not merely to see a column. */
if (!can_see_rates()) {
    http_response_code(403);
    exit('Payments are hidden for your user because rate visibility is switched off.');
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verify_csrf();
    $action = (string)($_POST['action'] ?? '');

    try {
        if ($action === 'save_payment') {
            $pid    = (int)($_POST['payment_id'] ?? 0);
            exp_require('payments', $pid > 0 ? 'u' : 'c');

            $amount = (float)($_POST['amount'] ?? 0);
            if ($amount <= 0) throw new Exception('A payment must be more than zero. To cancel one, void it.');

            $paidOn = trim((string)($_POST['paid_on'] ?? ''));
            if ($paidOn === '') throw new Exception('Payment date is needed.');

            $pkrCredited = trim((string)($_POST['pkr_credited'] ?? ''));
            $pkrRate     = trim((string)($_POST['pkr_rate'] ?? ''));

            $vals = [
                $paidOn,
                round($amount, 2),
                $cur,                                     /* copied, never chosen */
                (int)($_POST['method_id'] ?? 0) ?: null,
                (int)($_POST['bank_id'] ?? 0) ?: null,
                trim((string)($_POST['reference'] ?? '')) ?: null,
                $pkrCredited !== '' ? round((float)$pkrCredited, 2) : null,
                $pkrRate !== '' ? round((float)$pkrRate, 4) : null,
                trim((string)($_POST['notes'] ?? '')) ?: null,
            ];

            if ($pid > 0) {
                $st = db()->prepare("SELECT * FROM shipment_payments WHERE id=? AND shipment_id=?");
                $st->execute([$pid, $id]);
                $before = $st->fetch();
                if (!$before) throw new Exception('Payment not found on this shipment.');
                if ((int)$before['is_void'] === 1) throw new Exception('A voided payment cannot be edited. Add a new one instead.');

                $vals[] = current_user()['id']; $vals[] = $pid; $vals[] = $id;
                db()->prepare("UPDATE shipment_payments SET paid_on=?, amount=?, currency=?, method_id=?, bank_id=?, reference=?, pkr_credited=?, pkr_rate=?, notes=?, updated_by=?, updated_at=NOW() WHERE id=? AND shipment_id=?")
                    ->execute($vals);
                audit_log($id, 'Payment', 'amount', (string)$before['amount'], (string)round($amount, 2), 'Payment edited');
            } else {
                array_unshift($vals, $id);
                $vals[] = current_user()['id'];
                db()->prepare("INSERT INTO shipment_payments (shipment_id, paid_on, amount, currency, method_id, bank_id, reference, pkr_credited, pkr_rate, notes, created_by) VALUES (?,?,?,?,?,?,?,?,?,?,?)")
                    ->execute($vals);
                audit_log($id, 'Payment', 'receipt', '', $cur . ' ' . round($amount, 2) . ' on ' . $paidOn, 'Payment received');
            }

            $_SESSION['flash'] = 'Payment saved.';
            redirect('shipment_payments.php?id=' . $id);
        }

        /* Void, not delete. A received payment is a financial fact; if it was
           entered wrongly the record should show that it was entered and then
           withdrawn, by whom and why. */
        if ($action === 'void_payment') {
            exp_require('payments', 'r');
            $pid    = (int)($_POST['payment_id'] ?? 0);
            $reason = trim((string)($_POST['void_reason'] ?? ''));
            if ($reason === '') throw new Exception('A reason is required to void a payment.');

            $st = db()->prepare("SELECT * FROM shipment_payments WHERE id=? AND shipment_id=?");
            $st->execute([$pid, $id]); $row = $st->fetch();
            if (!$row) throw new Exception('Payment not found on this shipment.');
            if ((int)$row['is_void'] === 1) throw new Exception('That payment is already voided.');

            db()->prepare("UPDATE shipment_payments SET is_void=1, void_reason=?, voided_by=?, voided_at=NOW() WHERE id=? AND shipment_id=?")
                ->execute([$reason, current_user()['id'], $pid, $id]);
            audit_log($id, 'Payment', 'void', (string)$row['currency'] . ' ' . (string)$row['amount'], 'VOIDED', $reason);
            $_SESSION['flash'] = 'Payment voided. It stays on the record with your reason.';
            redirect('shipment_payments.php?id=' . $id);
        }
    } catch (Throwable $e) {
        $_SESSION['error'] = $e->getMessage();
        redirect('shipment_payments.php?id=' . $id);
    }
}

$pay      = exp_payment_summary($shipment);
$rows     = exp_payments($id);
$methods  = exp_masters('payment_method');
$banks    = exp_banks();
$mMap     = exp_master_map('payment_method');
$bMap     = [];
foreach (exp_banks(false) as $b) $bMap[(int)$b['id']] = (string)$b['bank_name'];

$edit = null;
if (isset($_GET['edit'])) {
    $st = db()->prepare("SELECT * FROM shipment_payments WHERE id=? AND shipment_id=? AND is_void=0");
    $st->execute([(int)$_GET['edit'], $id]); $edit = $st->fetch() ?: null;
}

$canAdd = exp_can('payments', 'c');
$canVoid = exp_can('payments', 'r');

$pkrTotal = 0.0;
foreach ($rows as $r) if ((int)$r['is_void'] === 0) $pkrTotal += (float)($r['pkr_credited'] ?? 0);

page_header('Payments — ' . $shipment['invoice_no']);
flash();
echo exp_page_css();
exp_tab_strip($shipment, 'payments');

$statusClass = $pay['status'] === 'PAID' ? 'g' : ($pay['status'] === 'UNPAID' ? 'r' : 'o');
?>

<div class="xkpi">
  <div class="k"><div class="l">Invoice Total</div><div class="v"><?= e(money_fmt($pay['total'], $cur)) ?></div></div>
  <div class="k"><div class="l">Total Received</div><div class="v" style="color:#16a34a"><?= e(money_fmt($pay['received'], $cur)) ?></div></div>
  <div class="k"><div class="l">Balance Receivable</div><div class="v" style="color:<?= $pay['balance'] > 0.0049 ? '#b8283f' : '#5a6b82' ?>"><?= e(money_fmt($pay['balance'], $cur)) ?></div></div>
  <div class="k"><div class="l">Status</div><div class="v" style="font-size:13px;padding-top:4px"><span class="xpill <?= $statusClass ?>"><?= e($pay['status']) ?></span></div></div>
</div>

<?php if ($pay['status'] === 'OVERPAID'): ?>
  <div class="xwarn">
    This invoice shows more received than invoiced, by <?= e(money_fmt(abs($pay['balance']), $cur)) ?>.
    That is either a genuine overpayment to refund or apply elsewhere, or a payment entered twice.
  </div>
<?php endif; ?>

<div class="xcard">
  <div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:11px;flex-wrap:wrap;gap:8px">
    <h2 style="margin:0">Payment Ledger</h2>
    <span style="font-size:12px;color:#8a97ab">in <?= e($cur) ?> — the invoice currency</span>
  </div>

  <div class="xwrap">
    <table class="xtable">
      <thead><tr>
        <th>Date</th><th>Method</th><th>Bank</th><th>Reference</th>
        <th class="num">Amount <?= e($cur) ?></th>
        <th class="num">PKR Credited</th><th class="num">Rate</th>
        <th></th>
      </tr></thead>
      <tbody>
      <?php if (!$rows): ?>
        <tr><td colspan="8" style="padding:22px;text-align:center;color:#8a97ab">No payments recorded yet — the full invoice value is outstanding.</td></tr>
      <?php endif; ?>
      <?php foreach ($rows as $r): $void = (int)$r['is_void'] === 1; ?>
        <tr class="<?= $void ? 'void' : '' ?>">
          <td><?= $r['paid_on'] ? e(date('d M Y', strtotime((string)$r['paid_on']))) : '' ?></td>
          <td><?= e($mMap[(int)($r['method_id'] ?? 0)] ?? '') ?></td>
          <td><?= e($bMap[(int)($r['bank_id'] ?? 0)] ?? '') ?></td>
          <td style="font-size:11.5px"><?= e($r['reference']) ?></td>
          <td class="num" style="font-weight:700"><?= e(num_fmt($r['amount'], 2)) ?></td>
          <td class="num"><?= $r['pkr_credited'] !== null ? e(num_fmt($r['pkr_credited'], 2)) : '' ?></td>
          <td class="num"><?= $r['pkr_rate'] !== null ? e(num_fmt($r['pkr_rate'], 4)) : '' ?></td>
          <td style="white-space:nowrap">
            <?php if ($void): ?>
              <span class="xpill r" title="<?= e($r['void_reason']) ?>">Voided</span>
            <?php else: ?>
              <?php if (exp_can('payments', 'u')): ?>
                <a class="xbtn sec sm" href="shipment_payments.php?id=<?= $id ?>&edit=<?= (int)$r['id'] ?>#pform">Edit</a>
              <?php endif; ?>
              <?php if ($canVoid): ?>
                <button class="xbtn red sm" type="button" onclick="voidPay(<?= (int)$r['id'] ?>,'<?= e(addslashes(num_fmt($r['amount'], 2))) ?>')">Void</button>
              <?php endif; ?>
            <?php endif; ?>
          </td>
        </tr>
        <?php if ($void): ?>
          <tr><td colspan="8" style="border-top:none;padding-top:0;font-size:11.5px;color:#b8283f">
            Voided: <?= e($r['void_reason']) ?>
          </td></tr>
        <?php endif; ?>
      <?php endforeach; ?>
      </tbody>
      <?php if ($rows): ?>
      <tfoot>
        <tr style="border-top:2px solid #e3e9f2;font-weight:700">
          <td colspan="4" style="text-align:right">Received</td>
          <td class="num"><?= e(num_fmt($pay['received'], 2)) ?></td>
          <td class="num"><?= $pkrTotal > 0 ? e(num_fmt($pkrTotal, 2)) : '' ?></td>
          <td colspan="2"></td>
        </tr>
        <tr style="font-weight:700">
          <td colspan="4" style="text-align:right">Balance</td>
          <td class="num" style="color:<?= $pay['balance'] > 0.0049 ? '#b8283f' : '#16a34a' ?>"><?= e(num_fmt($pay['balance'], 2)) ?></td>
          <td colspan="3"></td>
        </tr>
      </tfoot>
      <?php endif; ?>
    </table>
  </div>

  <div class="xnote" style="margin-top:12px">
    Every amount is in <?= e($cur) ?>, so the ledger closes exactly and the status is never off by a
    rounding difference. <b>PKR Credited</b> is what your bank actually gave you — kept for
    reconciliation and profitability, and deliberately not part of the balance.
  </div>
</div>

<?php if ($canAdd || $edit): ?>
<div class="xcard" id="pform">
  <h2><?= $edit ? 'Edit Payment' : 'Record a Payment' ?></h2>
  <form method="post" id="payForm">
    <?= csrf_field() ?>
    <input type="hidden" name="action" value="save_payment">
    <input type="hidden" name="shipment_id" value="<?= $id ?>">
    <input type="hidden" name="payment_id" value="<?= (int)($edit['id'] ?? 0) ?>">

    <div class="xgrid">
      <label class="xlabel">Payment Date
        <input class="xin" type="date" name="paid_on" required value="<?= e($edit['paid_on'] ?? date('Y-m-d')) ?>">
      </label>
      <label class="xlabel">Amount (<?= e($cur) ?>)
        <input class="xin" name="amount" required value="<?= e($edit['amount'] ?? '') ?>"
               placeholder="<?= e(num_fmt(max(0, $pay['balance']), 2)) ?>" id="amtIn">
      </label>
      <label class="xlabel">Method
        <select class="xin" name="method_id">
          <option value="">—</option>
          <?php foreach ($methods as $m): ?>
            <option value="<?= (int)$m['id'] ?>" <?= (int)($edit['method_id'] ?? 0) === (int)$m['id'] ? 'selected' : '' ?>><?= e($m['label']) ?></option>
          <?php endforeach; ?>
        </select>
      </label>
      <label class="xlabel">Our Bank
        <select class="xin" name="bank_id">
          <option value="">—</option>
          <?php foreach ($banks as $b): ?>
            <option value="<?= (int)$b['id'] ?>" <?= (int)($edit['bank_id'] ?? (int)($shipment['bank_id'] ?? 0)) === (int)$b['id'] ? 'selected' : '' ?>><?= e($b['bank_name']) ?></option>
          <?php endforeach; ?>
        </select>
      </label>
      <label class="xlabel xspan2">Transaction / Reference No.
        <input class="xin" name="reference" value="<?= e($edit['reference'] ?? '') ?>" placeholder="TT-889211 / SWIFT ref / CAD no.">
      </label>
      <label class="xlabel">PKR Credited <span style="color:#8a97ab">(optional)</span>
        <input class="xin" name="pkr_credited" value="<?= e($edit['pkr_credited'] ?? '') ?>">
      </label>
      <label class="xlabel">Bank Rate <span style="color:#8a97ab">(optional)</span>
        <input class="xin" name="pkr_rate" value="<?= e($edit['pkr_rate'] ?? '') ?>" placeholder="<?= e(num_fmt(exp_pkr_rate($cur), 4)) ?>">
      </label>
      <label class="xlabel xspan2">Notes
        <input class="xin" name="notes" value="<?= e($edit['notes'] ?? '') ?>">
      </label>
    </div>

    <div id="payWarn" class="xwarn" style="display:none;margin-top:11px"></div>

    <div style="display:flex;gap:9px;margin-top:13px;flex-wrap:wrap">
      <button class="xbtn"><?= $edit ? 'Update Payment' : 'Record Payment' ?></button>
      <?php if ($edit): ?><a class="xbtn sec" href="shipment_payments.php?id=<?= $id ?>">Cancel</a><?php endif; ?>
      <?php if (exp_can('documents')): ?>
        <a class="xbtn sec" href="shipment_documents.php?id=<?= $id ?>">Attach the TT / SWIFT proof</a>
      <?php endif; ?>
    </div>
  </form>
</div>
<?php endif; ?>

<?php if ($canVoid): ?>
<div id="voidModal" style="display:none;position:fixed;inset:0;background:rgba(10,15,30,.5);z-index:999;align-items:center;justify-content:center;padding:16px">
  <div style="background:#fff;border-radius:16px;padding:22px;max-width:420px;width:100%;border:1px solid #e3e9f2">
    <h2 style="font-size:15px;margin:0 0 7px;color:#b8283f">Void payment of <span id="vAmt"></span> <?= e($cur) ?>?</h2>
    <p style="font-size:12.5px;color:#5a6b82;line-height:1.55;margin:0 0 14px">
      The payment is not deleted. It stays on the ledger struck through, with your reason, your name
      and the time — and it stops counting towards the received total.
    </p>
    <form method="post">
      <?= csrf_field() ?>
      <input type="hidden" name="action" value="void_payment">
      <input type="hidden" name="shipment_id" value="<?= $id ?>">
      <input type="hidden" name="payment_id" id="vId">
      <label class="xlabel">Reason <span style="color:#b8283f">*</span>
        <input class="xin" name="void_reason" id="vReason" required placeholder="e.g. entered twice — see TT-889211">
      </label>
      <div style="display:flex;gap:9px;margin-top:15px">
        <button type="button" class="xbtn sec" style="flex:1" onclick="document.getElementById('voidModal').style.display='none'">Cancel</button>
        <button class="xbtn red" style="flex:1">Void Payment</button>
      </div>
    </form>
  </div>
</div>
<script>
function voidPay(id, amt) {
  document.getElementById('vId').value = id;
  document.getElementById('vAmt').textContent = amt;
  document.getElementById('vReason').value = '';
  document.getElementById('voidModal').style.display = 'flex';
  document.getElementById('vReason').focus();
}
</script>
<?php endif; ?>

<script>
/* The balance warning is shown as you type. The server checks nothing about
   over-payment — an overpayment is a real thing that happens — but seeing it
   before you save catches the common case of a figure typed twice. */
(function () {
  var amt = document.getElementById('amtIn');
  var warn = document.getElementById('payWarn');
  var balance = <?= json_encode(round($pay['balance'], 2)) ?>;
  if (!amt || !warn) return;

  function check() {
    var v = parseFloat(amt.value || '0');
    if (!v || v <= 0) { warn.style.display = 'none'; return; }
    if (v > balance + 0.0049 && balance > 0) {
      warn.style.display = 'block';
      warn.innerHTML = 'This is more than the outstanding balance of <b>' + balance.toFixed(2) +
                       '</b>. That is allowed — but check it is not a figure entered twice.';
    } else if (balance <= 0.0049) {
      warn.style.display = 'block';
      warn.innerHTML = 'This invoice is already settled. Any further payment will show as <b>OVERPAID</b>.';
    } else {
      warn.style.display = 'none';
    }
  }
  amt.addEventListener('input', check);
  check();

  document.addEventListener('keydown', function (e) {
    if ((e.ctrlKey || e.metaKey) && (e.key === 's' || e.key === 'S')) {
      e.preventDefault();
      var f = document.getElementById('payForm');
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
