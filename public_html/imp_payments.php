<?php
/*
  IMPORT — PAYMENTS TAB (supplier side).

  Every entry is in the IMPORT's currency, and that alone moves the supplier
  balance — never PKR, because three payments at three rates must still add
  up to the invoice. The PKR side is what the bank did: the debit is split
  into the supplier settlement (amount × rate), bank charges and other, and
  it must add up before it is saved. Bank charges are counted once, as a
  cost, in landed cost — never again as purchase cost.

  Kinds: payment · supplier credit note · refund from supplier (raises the
  balance again) · paid before go-live (opening imports, one line).
  A saved entry is voided with a reason, never deleted.
*/
require_once __DIR__ . '/includes/bootstrap.php';
require_once __DIR__ . '/includes/imports.php';

$imp = imp_open('payments');
$id  = (int)$imp['id'];
$cur = (string)$imp['currency'];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verify_csrf();
    $action = (string)($_POST['action'] ?? '');
    try {
        if ($action === 'add') {
            imp_require('payments', 'c');
            $kind = (string)($_POST['kind'] ?? 'payment');
            if (!isset(IMP_PAY_KINDS[$kind])) throw new Exception('Pick what this entry is.');
            $amt = imp_num($_POST['amount'] ?? 0);
            if ($amt <= 0) throw new Exception('Enter the amount in ' . $cur . '.');
            $date = imp_date($_POST['paid_on'] ?? '');
            if (!$date && $kind !== 'before_golive') throw new Exception('Enter the date.');
            $pkr = trim((string)($_POST['pkr_debit'] ?? '')) === '' ? null : imp_num($_POST['pkr_debit']);
            $rate = trim((string)($_POST['rate'] ?? '')) === '' ? null : imp_num($_POST['rate']);
            $chg = imp_num($_POST['bank_charges_pkr'] ?? 0); $oth = imp_num($_POST['other_pkr'] ?? 0);
            if ($chg < 0 || $oth < 0 || ($pkr !== null && $pkr < 0) || ($rate !== null && $rate < 0)) throw new Exception('Amounts cannot be negative.');
            if ($cur === 'PKR') $rate = 1.0;
            /* the debit must be fully allocated before it is saved */
            if ($pkr !== null) {
                if (!$rate) throw new Exception('Enter the rate the bank used (PKR per ' . $cur . ').');
                $diff = round($pkr - $amt * $rate - $chg - $oth, 2);
                if (abs($diff) >= 1) throw new Exception('The bank debit does not add up: PKR ' . number_format(abs($diff), 2) . ($diff > 0 ? ' not allocated' : ' allocated too much')
                    . ' (supplier ' . number_format($amt * $rate, 2) . ' + charges ' . number_format($chg, 2) . ' + other ' . number_format($oth, 2) . ').');
            }
            $bank = (int)($_POST['bank_id'] ?? 0);
            db()->prepare("INSERT INTO imp_payments (import_id, kind, paid_on, amount, bank_id, pkr_debit, rate, bank_charges_pkr, other_pkr, reference, notes, created_by, created_at)
                           VALUES (?,?,?,?,?,?,?,?,?,?,?,?,NOW())")
                ->execute([$id, $kind, $date, round($amt, 2), $bank ?: null, $pkr, $rate, $chg, $oth,
                           mb_substr(trim((string)($_POST['reference'] ?? '')), 0, 120) ?: null, mb_substr(trim((string)($_POST['notes'] ?? '')), 0, 300) ?: null,
                           (int)(current_user()['id'] ?? 0)]);
            imp_log($id, IMP_PAY_KINDS[$kind][0], money_fmt($amt, $cur) . ($pkr !== null ? ' · PKR ' . number_format($pkr, 2) . ' debited at ' . $rate . ($chg ? ' incl. charges ' . number_format($chg, 2) : '') : '')
                    . (trim((string)($_POST['reference'] ?? '')) !== '' ? ' · ' . trim((string)$_POST['reference']) : ''));
            $_SESSION['flash'] = IMP_PAY_KINDS[$kind][0] . ' saved — ' . money_fmt($amt, $cur) . '.';
        }
        if ($action === 'void') {
            imp_require('payments', 'r');
            $pid = (int)($_POST['payment_id'] ?? 0);
            $reason = trim((string)($_POST['void_reason'] ?? ''));
            if ($reason === '') throw new Exception('A reason is needed to void an entry.');
            $r = db()->prepare("SELECT * FROM imp_payments WHERE id=? AND import_id=?"); $r->execute([$pid, $id]); $row = $r->fetch();
            if (!$row) throw new Exception('That entry is not there.');
            if ((int)$row['is_void'] === 1) throw new Exception('That entry is already voided.');
            db()->prepare("UPDATE imp_payments SET is_void=1, void_reason=?, voided_by=?, voided_at=NOW() WHERE id=? AND import_id=?")
                ->execute([mb_substr($reason, 0, 300), (int)(current_user()['id'] ?? 0), $pid, $id]);
            imp_log($id, 'Payment voided', (IMP_PAY_KINDS[$row['kind']][0] ?? 'Entry') . ' ' . money_fmt($row['amount'], $cur), $reason);
            $_SESSION['flash'] = 'Entry voided. It stays in the list, crossed out.';
        }
    } catch (Throwable $e) {
        $_SESSION['error'] = $e->getMessage();
    }
    redirect('imp_payments.php?id=' . $id);
}

$lines = imp_lines($id);
$pays = imp_payments($id);
$sum = imp_paid_summary($imp, $lines, $pays);
$log = imp_logistics($id);
$m = imp_maturity($imp, $log);
$adv = round($sum['total'] * (float)$imp['advance_pct'] / 100, 2);
$banks = ['' => '—']; foreach (exp_banks() as $b) $banks[(int)$b['id']] = $b['bank_name'] . ($b['currency'] ? ' (' . $b['currency'] . ')' : '');
$bankName = []; foreach (exp_banks(false) as $b) $bankName[(int)$b['id']] = $b['bank_name'];
$rate = imp_pkr_rate($imp, $pays);
$canAdd = imp_can('payments', 'c'); $canVoid = imp_can('payments', 'r');

page_header('Payments — ' . $imp['ref']);
flash();
echo imp_page_css();
imp_tab_strip($imp, 'payments');
?>
<div class="xkpi">
  <div class="k"><div class="l">Invoice</div><div class="v"><?= e(money_fmt($sum['total'], $cur)) ?></div></div>
  <div class="k"><div class="l">Settled</div><div class="v"><?= e(money_fmt($sum['settled'], $cur)) ?></div></div>
  <div class="k"><div class="l">Balance payable</div><div class="v" style="color:<?= $sum['balance'] > 0.0049 ? '#d97706' : '#16a34a' ?>"><?= e(money_fmt($sum['balance'], $cur)) ?></div></div>
  <div class="k"><div class="l">Balance due</div><div class="v"><?= e($m['date'] ? imp_dmy($m['date']) : '—') ?></div><div class="imp-small imp-muted"><?= e($m['from']) ?></div></div>
</div>

<div class="xcard">
  <h2>Schedule</h2>
  <div class="xwrap"><table class="xtable"><thead><tr><th>Instalment</th><th class="num">Amount</th><th>Due</th></tr></thead><tbody>
    <?php if ((float)$imp['advance_pct'] > 0): ?><tr><td><?= e(rtrim(rtrim((string)$imp['advance_pct'], '0'), '.')) ?>% advance</td><td class="num"><?= e(money_fmt($adv, $cur)) ?></td><td><?= e(imp_dmy($imp['contract_date'])) ?> (contract)</td></tr><?php endif; ?>
    <tr><td><?= (float)$imp['advance_pct'] > 0 ? 'Balance ' . e(rtrim(rtrim((string)(100 - (float)$imp['advance_pct']), '0'), '.')) . '%' : 'Full amount' ?><?= $imp['pay_method'] ? ' · ' . e($imp['pay_method']) : '' ?></td><td class="num"><?= e(money_fmt($sum['total'] - $adv, $cur)) ?></td><td><?= e($m['date'] ? imp_dmy($m['date']) : ($m['from'] ?: 'set the terms on OVERVIEW')) ?></td></tr>
  </tbody></table></div>
</div>

<div class="xcard">
  <h2>Entries</h2>
  <div class="xwrap"><table class="xtable">
    <thead><tr><th>Date</th><th>Kind</th><th class="num">Amount (<?= e($cur) ?>)</th><th>Bank</th><th class="num">PKR debited</th><th class="num">Rate</th><th class="num">Bank charges</th><th>Reference</th><th></th></tr></thead>
    <tbody>
    <?php if (!$pays): ?><tr><td colspan="9" class="imp-muted" style="padding:14px;text-align:center">Nothing paid yet.</td></tr><?php endif; ?>
    <?php foreach ($pays as $p): $void = (int)$p['is_void'] === 1; $sign = IMP_PAY_KINDS[$p['kind']][1] ?? 1; ?>
      <tr class="<?= $void ? 'void' : '' ?>">
        <td><?= e(imp_dmy($p['paid_on'])) ?></td><td><?= e(IMP_PAY_KINDS[$p['kind']][0] ?? $p['kind']) ?><?= $void ? '<div class="imp-small" style="color:#b8283f;text-decoration:none">voided: ' . e($p['void_reason']) . '</div>' : '' ?></td>
        <td class="num"><?= $sign < 0 ? '−' : '' ?><?= e(number_format((float)$p['amount'], 2)) ?></td><td><?= e($bankName[(int)$p['bank_id']] ?? '') ?></td>
        <td class="num"><?= $p['pkr_debit'] !== null ? e(number_format((float)$p['pkr_debit'], 2)) : '' ?></td><td class="num"><?= $p['rate'] !== null ? e(rtrim(rtrim(number_format((float)$p['rate'], 4), '0'), '.')) : '' ?></td>
        <td class="num"><?= (float)$p['bank_charges_pkr'] + (float)$p['other_pkr'] > 0 ? e(number_format((float)$p['bank_charges_pkr'] + (float)$p['other_pkr'], 2)) : '' ?></td>
        <td><?= e($p['reference']) ?><?= $p['notes'] ? '<div class="imp-small imp-muted">' . e($p['notes']) . '</div>' : '' ?></td>
        <td><?php if ($canVoid && !$void): ?><form method="post" style="margin:0;display:flex;gap:4px" onsubmit="var r=prompt('Why void this entry?');if(!r)return false;this.void_reason.value=r;"><?= csrf_field() ?><input type="hidden" name="action" value="void"><input type="hidden" name="import_id" value="<?= $id ?>"><input type="hidden" name="payment_id" value="<?= (int)$p['id'] ?>"><input type="hidden" name="void_reason"><button class="xbtn red sm">Void</button></form><?php endif; ?></td>
      </tr>
    <?php endforeach; ?>
    </tbody>
  </table></div>
  <?php if ($sum['bank_charges_pkr'] > 0): ?><div class="imp-small imp-muted" style="margin-top:8px">Bank charges and other deductions, PKR <?= e(number_format($sum['bank_charges_pkr'], 2)) ?>, are counted once — in landed cost on COSTS.</div><?php endif; ?>
</div>

<?php if ($canAdd): ?>
<form class="xcard" method="post" id="payForm" autocomplete="off">
  <?= csrf_field() ?><input type="hidden" name="action" value="add"><input type="hidden" name="import_id" value="<?= $id ?>">
  <h2>Add an entry</h2>
  <div class="xgrid">
    <?= imp_select('Kind', 'kind', array_map(fn($k) => $k[0], IMP_PAY_KINDS), (int)$imp['is_opening'] === 1 && !$pays ? 'before_golive' : 'payment', ' id="pKind"', true) ?>
    <?= imp_input('Date', 'paid_on', imp_today(), 'date') ?>
    <?= imp_input('Amount (' . e($cur) . ')', 'amount', '', 'number', ' id="pAmt" required') ?>
    <?= imp_select('Bank', 'bank_id', $banks, '', '', true) ?>
    <?php if ($cur !== 'PKR'): ?>
    <?= imp_input('PKR debited by the bank', 'pkr_debit', '', 'number', ' id="pPkr" placeholder="leave blank if not known"') ?>
    <?= imp_input('Rate (PKR per ' . e($cur) . ')', 'rate', $rate['rate'] > 0 ? round($rate['rate'], 4) : '', 'number', ' id="pRate"') ?>
    <?php else: ?>
    <?= imp_input('PKR debited by the bank', 'pkr_debit', '', 'number', ' id="pPkr"') ?><input type="hidden" id="pRate" value="1">
    <?php endif; ?>
    <?= imp_input('Bank charges (PKR)', 'bank_charges_pkr', '', 'number', ' id="pChg"') ?>
    <?= imp_input('Other deductions (PKR)', 'other_pkr', '', 'number', ' id="pOth"') ?>
    <?= imp_input('Transaction / SWIFT ref', 'reference', '') ?>
    <?= imp_input('Notes', 'notes', '') ?>
  </div>
  <div id="splitNote" style="margin-top:12px"></div>
  <div style="margin-top:10px"><button class="xbtn" id="pSave">Save entry</button></div>
  <div class="xnote imp-small" style="margin-top:10px">A supplier credit note lowers the balance like a payment; a refund from the supplier raises it again. "Paid before go-live" is for opening imports: everything paid before the system started, as one line.</div>
</form>
<script>
(function () {
  var g = function (i) { var x = parseFloat(String((document.getElementById(i) || {}).value || '').replace(/,/g, '')); return isFinite(x) ? x : 0; };
  var f = function (v) { return Number(v).toLocaleString('en-US', { minimumFractionDigits: 2, maximumFractionDigits: 2 }); };
  function check() {
    var box = document.getElementById('splitNote'), btn = document.getElementById('pSave'), pkrEl = document.getElementById('pPkr');
    if (!pkrEl || !pkrEl.value.trim()) { box.innerHTML = ''; btn.disabled = false; return; }
    /* rounded away from zero, the way the server rounds, so both say the same paisa */
    var sup = g('pAmt') * g('pRate'), raw = g('pPkr') - sup - g('pChg') - g('pOth'), diff = (raw < 0 ? -1 : 1) * Math.round(Math.abs(raw) * 100) / 100;
    if (Math.abs(diff) < 1) { box.innerHTML = '<div class="xok">Split adds up: supplier PKR ' + f(sup) + ' + charges + other = the bank debit.</div>'; btn.disabled = false; }
    else { box.innerHTML = '<div class="xbad"><b>Split does not add up — PKR ' + f(Math.abs(diff)) + (diff > 0 ? ' not yet allocated' : ' allocated too much') + '.</b> Supplier PKR ' + f(sup) + '. Saving waits until it balances.</div>'; btn.disabled = true; }
  }
  document.getElementById('payForm').addEventListener('input', check);
  check();
})();
</script>
<?php endif; ?>
<?php page_footer(); ?>
