<?php
/*
  IMPORT — COSTS TAB, and landed cost (provisional).

  Each cost has an estimate and, once the bill comes, the actual amount —
  the actual is used when there is one, the estimate until then (and landed
  cost says "provisional" while any bill is still an estimate). A cost type
  flagged "duty / tax" is duty paid through the clearing agent, kept apart
  from his service charges so duty is never counted twice.

  Landed cost shares the costs over the lines by net kg or by value; the
  last line takes the rounding so the shares add up exactly. Bank charges
  from PAYMENTS are counted here once. A late bill changes the cost, never a
  quantity. A saved cost is voided with a reason, never deleted.
*/
require_once __DIR__ . '/includes/bootstrap.php';
require_once __DIR__ . '/includes/imports.php';

$imp = imp_open('costs');
$id  = (int)$imp['id'];
$types = exp_masters('imp_cost_type');
$typeAll = []; foreach (exp_masters('imp_cost_type', false) as $t) $typeAll[(int)$t['id']] = $t;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verify_csrf();
    $action = (string)($_POST['action'] ?? '');
    try {
        $rateFor = static function (string $cur, $typed): ?float {
            if ($cur === 'PKR') return 1.0;
            $r = trim((string)$typed) === '' ? 0.0 : imp_num($typed);
            if ($r <= 0) throw new Exception('Enter the exchange rate (PKR per ' . $cur . ').');
            return $r;
        };
        if ($action === 'add') {
            imp_require('costs', 'c');
            $tid = (int)($_POST['cost_type_id'] ?? 0);
            if (!isset($typeAll[$tid])) throw new Exception('Pick the cost type.');
            $cur = (string)($_POST['currency'] ?? 'PKR'); if (!in_array($cur, IMP_CURRENCIES, true)) $cur = 'PKR';
            $est = imp_num($_POST['est_amount'] ?? 0);
            $act = trim((string)($_POST['amount'] ?? '')) === '' ? null : imp_num($_POST['amount']);
            if ($est < 0 || ($act !== null && $act < 0)) throw new Exception('Amounts cannot be negative.');
            if ($est == 0 && $act === null) throw new Exception('Enter an estimate, or the actual bill.');
            $rate = $rateFor($cur, $_POST['fx_rate'] ?? '');
            $paid = !empty($_POST['is_paid']) ? 1 : 0;
            db()->prepare("INSERT INTO imp_costs (import_id, cost_type_id, kind, provider_id, bill_no, bill_date, currency, est_amount, amount, fx_rate, is_paid, paid_on, pay_ref, notes, created_by, created_at)
                           VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,NOW())")
                ->execute([$id, $tid, exp_master_flag($typeAll[$tid], 'is_duty') ? 'duty' : 'service', (int)($_POST['provider_id'] ?? 0) ?: null,
                           mb_substr(trim((string)($_POST['bill_no'] ?? '')), 0, 80) ?: null, imp_date($_POST['bill_date'] ?? ''), $cur, $est, $act, $rate,
                           $paid, $paid ? (imp_date($_POST['paid_on'] ?? '') ?: imp_today()) : null, mb_substr(trim((string)($_POST['pay_ref'] ?? '')), 0, 120) ?: null,
                           mb_substr(trim((string)($_POST['notes'] ?? '')), 0, 300) ?: null, (int)(current_user()['id'] ?? 0)]);
            imp_log($id, 'Cost added', $typeAll[$tid]['label'] . ' · ' . $cur . ' ' . number_format($act ?? $est, 2) . ($act === null ? ' (estimate)' : ''));
            $_SESSION['flash'] = 'Cost added.';
        }
        if ($action === 'bill') {
            imp_require('costs', 'u');
            $cid = (int)($_POST['cost_id'] ?? 0);
            $r = db()->prepare("SELECT * FROM imp_costs WHERE id=? AND import_id=? AND is_void=0"); $r->execute([$cid, $id]); $row = $r->fetch();
            if (!$row) throw new Exception('That cost is not there.');
            $act = trim((string)($_POST['amount'] ?? '')) === '' ? null : imp_num($_POST['amount']);
            if ($act !== null && $act < 0) throw new Exception('The bill cannot be negative.');
            $rate = $rateFor((string)$row['currency'], $_POST['fx_rate'] ?? $row['fx_rate']);
            $paid = !empty($_POST['is_paid']) ? 1 : 0;
            db()->prepare("UPDATE imp_costs SET amount=?, fx_rate=?, bill_no=?, bill_date=?, is_paid=?, paid_on=?, pay_ref=? WHERE id=? AND import_id=?")
                ->execute([$act, $rate, mb_substr(trim((string)($_POST['bill_no'] ?? '')), 0, 80) ?: null, imp_date($_POST['bill_date'] ?? ''), $paid,
                           $paid ? (imp_date($_POST['paid_on'] ?? '') ?: imp_today()) : null, mb_substr(trim((string)($_POST['pay_ref'] ?? '')), 0, 120) ?: null, $cid, $id]);
            imp_log($id, 'Cost bill updated', ($typeAll[(int)$row['cost_type_id']]['label'] ?? 'Cost') . ' · ' . $row['currency'] . ' '
                    . ($act === null ? 'bill cleared' : number_format((float)$row['amount'], 2) . ' → ' . number_format($act, 2)) . ($paid ? ' · paid' : ''));
            $_SESSION['flash'] = 'Bill updated.';
        }
        if ($action === 'void') {
            imp_require('costs', 'r');
            $cid = (int)($_POST['cost_id'] ?? 0); $reason = trim((string)($_POST['void_reason'] ?? ''));
            if ($reason === '') throw new Exception('A reason is needed to void a cost.');
            $r = db()->prepare("SELECT * FROM imp_costs WHERE id=? AND import_id=? AND is_void=0"); $r->execute([$cid, $id]); $row = $r->fetch();
            if (!$row) throw new Exception('That cost is not there.');
            db()->prepare("UPDATE imp_costs SET is_void=1, void_reason=?, voided_by=?, voided_at=NOW() WHERE id=? AND import_id=?")->execute([mb_substr($reason, 0, 300), (int)(current_user()['id'] ?? 0), $cid, $id]);
            imp_log($id, 'Cost voided', ($typeAll[(int)$row['cost_type_id']]['label'] ?? 'Cost') . ' · ' . $row['currency'] . ' ' . number_format((float)($row['amount'] ?? $row['est_amount']), 2), $reason);
            $_SESSION['flash'] = 'Cost voided.';
        }
        if ($action === 'basis') {
            imp_require('costs', 'u');
            $b = ($_POST['alloc_basis'] ?? 'kg') === 'value' ? 'value' : 'kg';
            db()->prepare("UPDATE imp_imports SET alloc_basis=? WHERE id=?")->execute([$b, $id]);
            imp_log($id, 'Landed cost basis', $b === 'kg' ? 'by net kg' : 'by invoice value');
            $_SESSION['flash'] = 'Costs are now shared by ' . ($b === 'kg' ? 'net kg' : 'invoice value') . '.';
        }
    } catch (Throwable $e) {
        $_SESSION['error'] = $e->getMessage();
    }
    redirect('imp_costs.php?id=' . $id);
}

$lines = imp_lines($id);
$costs = imp_costs($id);
$pays = imp_payments($id);
$L = imp_landed($imp, $lines, $costs, $pays);
$prov = exp_provider_map();
$fx = function_exists('fx_get_rates') ? fx_get_rates() : [];
$fxPkr = []; foreach ($fx as $c => $r) if ((float)$r > 0) $fxPkr[$c] = round(1 / (float)$r, 4);
$svc = 0.0; $duty = 0.0; $est = 0.0;
foreach ($costs as $c) { if ((int)$c['is_void'] === 1) continue; $v = imp_cost_pkr($c); if ($c['kind'] === 'duty') $duty += $v; else $svc += $v;
    $est += $c['currency'] === 'PKR' ? (float)$c['est_amount'] : (float)$c['est_amount'] * (float)$c['fx_rate']; }
$canAdd = imp_can('costs', 'c'); $canUpd = imp_can('costs', 'u'); $canVoid = imp_can('costs', 'r');
$m2 = static fn($v) => number_format((float)$v, 2);

page_header('Costs — ' . $imp['ref']);
flash();
echo imp_page_css();
imp_tab_strip($imp, 'costs');
?>
<div class="xkpi">
  <div class="k"><div class="l">Estimated (PKR)</div><div class="v"><?= e($m2($est)) ?></div></div>
  <div class="k"><div class="l">Service charges (PKR)</div><div class="v"><?= e($m2($svc)) ?></div><div class="imp-small imp-muted">actual, else estimate</div></div>
  <div class="k"><div class="l">Duty / tax via agent (PKR)</div><div class="v"><?= e($m2($duty)) ?></div></div>
  <div class="k"><div class="l">Bank charges (PKR)</div><div class="v"><?= e($m2($L['bank_pkr'])) ?></div><div class="imp-small imp-muted">from PAYMENTS</div></div>
</div>

<div class="xcard">
  <h2>Costs — estimate and actual</h2>
  <div class="xwrap"><table class="xtable" style="min-width:980px">
    <thead><tr><th>Cost</th><th>Provider</th><th>Kind</th><th>Bill</th><th>Cur</th><th class="num">Estimate</th><th class="num">Actual bill</th><th class="num">Rate</th><th class="num">PKR</th><th>Paid</th><th></th></tr></thead>
    <tbody>
    <?php if (!$costs): ?><tr><td colspan="11" class="imp-muted" style="padding:14px;text-align:center">No costs yet.</td></tr><?php endif; ?>
    <?php foreach ($costs as $c): $void = (int)$c['is_void'] === 1; ?>
      <tr class="<?= $void ? 'void' : '' ?>">
        <td><?= e($typeAll[(int)$c['cost_type_id']]['label'] ?? '—') ?><?= $c['notes'] ? '<div class="imp-small imp-muted">' . e($c['notes']) . '</div>' : '' ?><?= $void ? '<div class="imp-small" style="color:#b8283f">voided: ' . e($c['void_reason']) . '</div>' : '' ?></td>
        <td><?= e($prov[(int)$c['provider_id']] ?? '') ?></td>
        <td><span class="xpill <?= $c['kind'] === 'duty' ? 'v' : 'n' ?>"><?= $c['kind'] === 'duty' ? 'duty / tax' : 'service' ?></span></td>
        <td><?= e($c['bill_no']) ?><div class="imp-small imp-muted"><?= e($c['bill_date'] ? imp_dmy($c['bill_date']) : '') ?></div></td>
        <td><?= e($c['currency']) ?></td><td class="num"><?= e($m2($c['est_amount'])) ?></td>
        <td class="num"><?= $c['amount'] === null ? '<span class="imp-muted">not billed</span>' : e($m2($c['amount'])) ?></td>
        <td class="num"><?= $c['currency'] === 'PKR' ? '—' : e(rtrim(rtrim(number_format((float)$c['fx_rate'], 4), '0'), '.')) ?></td>
        <td class="num calc"><?= e($m2(imp_cost_pkr($c))) ?></td>
        <td><?= (int)$c['is_paid'] === 1 ? '<span class="xpill g">paid</span><div class="imp-small imp-muted">' . e(imp_dmy($c['paid_on'])) . '</div>' : '<span class="xpill o">open</span>' ?></td>
        <td style="white-space:nowrap">
          <?php if (!$void && $canUpd): ?><button type="button" class="xbtn sec sm" onclick="var r=this.closest('tr').nextElementSibling;r.style.display=r.style.display==='none'?'':'none'">Bill</button><?php endif; ?>
          <?php if (!$void && $canVoid): ?><form method="post" style="display:inline;margin:0" onsubmit="var r=prompt('Why void this cost?');if(!r)return false;this.void_reason.value=r;"><?= csrf_field() ?><input type="hidden" name="action" value="void"><input type="hidden" name="import_id" value="<?= $id ?>"><input type="hidden" name="cost_id" value="<?= (int)$c['id'] ?>"><input type="hidden" name="void_reason"><button class="xbtn red sm">Void</button></form><?php endif; ?>
        </td>
      </tr>
      <?php if (!$void && $canUpd): ?>
      <tr style="display:none"><td colspan="11" style="background:#fafbfd">
        <form method="post" style="display:flex;gap:9px;flex-wrap:wrap;align-items:flex-end"><?= csrf_field() ?><input type="hidden" name="action" value="bill"><input type="hidden" name="import_id" value="<?= $id ?>"><input type="hidden" name="cost_id" value="<?= (int)$c['id'] ?>">
          <?= imp_input('Actual bill (' . e($c['currency']) . ')', 'amount', $c['amount'] === null ? '' : $c['amount'], 'number', ' placeholder="blank = not billed"') ?>
          <?php if ($c['currency'] !== 'PKR'): ?><?= imp_input('Rate', 'fx_rate', $c['fx_rate'], 'number') ?><?php endif; ?>
          <?= imp_input('Bill no.', 'bill_no', $c['bill_no']) ?><?= imp_input('Bill date', 'bill_date', $c['bill_date'], 'date') ?>
          <label class="tog"><input type="checkbox" name="is_paid" value="1"<?= (int)$c['is_paid'] === 1 ? ' checked' : '' ?>> Paid</label>
          <?= imp_input('Paid on', 'paid_on', $c['paid_on'], 'date') ?><?= imp_input('Payment ref', 'pay_ref', $c['pay_ref']) ?>
          <button class="xbtn sm">Save bill</button>
        </form></td></tr>
      <?php endif; ?>
    <?php endforeach; ?>
    </tbody>
  </table></div>
  <div class="xnote imp-small" style="margin-top:10px">The clearing agent's <b>service charges</b> and the <b>duty / tax he pays for us</b> are separate rows, so duty is never counted twice. From Phase 2 the duty comes from the actual GD / challan.</div>
</div>

<?php if ($canAdd): ?>
<form class="xcard" method="post" autocomplete="off">
  <?= csrf_field() ?><input type="hidden" name="action" value="add"><input type="hidden" name="import_id" value="<?= $id ?>">
  <h2>Add a cost</h2>
  <div class="xgrid">
    <label class="xlabel">Cost type<select class="xin" name="cost_type_id" required><option value="">— pick —</option><?php foreach ($types as $t): ?><option value="<?= (int)$t['id'] ?>"><?= e($t['label']) ?><?= exp_master_flag($t, 'is_duty') ? ' (duty / tax)' : '' ?></option><?php endforeach; ?></select></label>
    <label class="xlabel">Provider<select class="xin" name="provider_id"><option value="">—</option><?php foreach (exp_providers() as $p): ?><option value="<?= (int)$p['id'] ?>"><?= e($p['name']) ?></option><?php endforeach; ?></select></label>
    <?= imp_select('Currency', 'currency', IMP_CURRENCIES, 'PKR', ' id="cCur"') ?>
    <?= imp_input('Estimate', 'est_amount', '', 'number') ?>
    <?= imp_input('Actual bill (if received)', 'amount', '', 'number', ' placeholder="blank = not billed yet"') ?>
    <?= imp_input('Rate (PKR per unit)', 'fx_rate', '', 'number', ' id="cRate" placeholder="for foreign currency"') ?>
    <?= imp_input('Bill no.', 'bill_no', '') ?><?= imp_input('Bill date', 'bill_date', '', 'date') ?>
    <label class="tog" style="padding-top:22px"><input type="checkbox" name="is_paid" value="1"> Paid</label>
    <?= imp_input('Paid on', 'paid_on', '', 'date') ?><?= imp_input('Payment ref', 'pay_ref', '') ?><?= imp_input('Notes', 'notes', '') ?>
  </div>
  <div style="margin-top:10px"><button class="xbtn">Add cost</button> <span class="imp-small imp-muted">New cost types: Export Settings → Lists → Import Cost Types.</span></div>
</form>
<script>
(function () {
  var FX = <?= json_encode($fxPkr) ?>, cur = document.getElementById('cCur'), rate = document.getElementById('cRate');
  function fill() { if (cur.value === 'PKR') { rate.value = ''; rate.disabled = true; } else { rate.disabled = false; if (!rate.value && FX[cur.value]) rate.value = FX[cur.value]; } }
  cur.addEventListener('change', function () { rate.value = ''; fill(); }); fill();
})();
</script>
<?php endif; ?>

<div class="xcard">
  <div style="display:flex;justify-content:space-between;align-items:center;flex-wrap:wrap;gap:8px">
    <h2 style="margin:0">Landed cost <?= $L['provisional'] ? '<span class="xpill o">provisional</span>' : '<span class="xpill g">all bills in</span>' ?></h2>
    <?php if ($canUpd): ?><form method="post" style="display:flex;gap:6px;align-items:center;margin:0"><?= csrf_field() ?><input type="hidden" name="action" value="basis"><input type="hidden" name="import_id" value="<?= $id ?>">
      <span class="imp-small imp-muted">Share costs by</span><select class="xin" name="alloc_basis" onchange="this.form.submit()" style="width:auto;margin:0"><option value="kg"<?= $L['basis'] === 'kg' ? ' selected' : '' ?>>Net kg</option><option value="value"<?= $L['basis'] === 'value' ? ' selected' : '' ?>>Invoice value</option></select></form><?php endif; ?>
  </div>
  <?php if (!$lines): ?><div class="xnote" style="margin-top:10px">Add the invoice lines first.</div><?php else: ?>
  <div class="xwrap" style="margin-top:10px"><table class="xtable">
    <thead><tr><th>Line</th><th class="num">Goods PKR</th><th class="num">Share of costs</th><th class="num">Landed PKR</th><th class="num">Per kg</th><th class="num">Per billed unit</th></tr></thead>
    <tbody>
    <?php $tg = 0; foreach ($L['rows'] as $r): $tg += $r['goods_pkr']; ?>
      <tr><td><?= e($r['line']['description']) ?></td><td class="num"><?= e($m2($r['goods_pkr'])) ?></td><td class="num"><?= e($m2($r['share'])) ?></td><td class="num calc"><?= e($m2($r['landed'])) ?></td>
        <td class="num"><?= $r['per_kg'] !== null ? e($m2($r['per_kg'])) : '—' ?></td><td class="num"><?= $r['per_unit'] !== null ? e($m2($r['per_unit'])) . ' / ' . e($r['line']['bill_unit']) : '—' ?></td></tr>
    <?php endforeach; ?>
    </tbody>
    <tfoot><tr><td>Total</td><td class="num"><?= e($m2($tg)) ?></td><td class="num"><?= e($m2($L['costs_pkr'])) ?></td><td class="num"><?= e($m2($tg + $L['costs_pkr'])) ?></td><td></td><td></td></tr></tfoot>
  </table></div>
  <div class="<?= $L['rate']['rate'] > 0 ? 'xnote' : 'xwarn' ?> imp-small" style="margin-top:10px">Goods in PKR at <?= $L['rate']['rate'] > 0 ? e(number_format($L['rate']['rate'], 4)) . ' PKR per ' . e($imp['currency']) . ' (' . e($L['rate']['source']) . ')' : 'no rate yet — add a payment with its rate' ?>. <?= $L['provisional'] ? 'Bills not received yet are counted at their estimate.' : '' ?> The shares always add up exactly; the last line takes the rounding. Recoverable taxes and deposits are separated in Phase 2, by the accountant's policy.</div>
  <?php endif; ?>
</div>
<?php page_footer(); ?>
