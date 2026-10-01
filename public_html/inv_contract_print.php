<?php
/* Contract sheet and ledger — one printable page.

   Two things on it, deliberately together: the contract as agreed, and
   what has actually moved against it. A contract you cannot see the
   balance of is only a piece of paper.

   Balances count POSTED gate passes only, and only those booked against
   a specific contract line. Anything posted against the contract without
   naming a line is reported on its own, never spread across the lines by
   guesswork. Same styling as the gate pass print. */
require_once __DIR__ . '/includes/bootstrap.php';
require_login();
require_once __DIR__ . '/includes/inventory.php';
inv_ensure_schema();
if (!inv_can_see()) { http_response_code(403); exit('Not permitted.'); }

$id = (int)($_GET['id'] ?? 0);
$doc = null;
try {
    $s = db()->prepare("SELECT c.*, p.name party_name, p.address party_address, p.ntn party_ntn,
            pf.pi_no, u.name created_name
        FROM inv_contracts c
        LEFT JOIN inv_parties p ON p.id = c.party_id
        LEFT JOIN proforma_invoices pf ON pf.id = c.proforma_id
        LEFT JOIN users u ON u.id = c.created_by
        WHERE c.id = ?");
    $s->execute([$id]); $doc = $s->fetch() ?: null;
} catch (Throwable $e) {}
if (!$doc) { http_response_code(404); exit('Contract not found.'); }

/* Two reports from one page:
     (default)     the full thing — balances, completion, and the ledger
     ?view=share   the contract only, to send the supplier or customer

   The share copy deliberately carries no Received, no Balance, no
   Completion and no ledger. Those tell the other side how far behind
   they are and everything else booked against them — our business, not
   theirs. */
$share = ($_GET['view'] ?? '') === 'share';

$lines      = inv_contract_lines($id);
$unassigned = inv_contract_unassigned($id);
$moves      = inv_contract_movements($id);
$T = inv_totals(array_map(fn($l) => ['qty' => $l['qty'], 'rate' => $l['rate'], 'amount' => $l['amount']], $lines),
    !empty($doc['gst_applicable']), (float)($doc['gst_pct'] ?? inv_gst_default()));

$TYPES = [
    'purchase'    => ['t' => 'PURCHASE CONTRACT',       'party' => 'Supplier',  'move' => 'Received'],
    'sales'       => ['t' => 'SALES CONTRACT',          'party' => 'Customer',  'move' => 'Delivered'],
    'jobwork_out' => ['t' => 'JOB WORK CONTRACT — OUT', 'party' => 'Processor', 'move' => 'Sent / returned'],
    'jobwork_in'  => ['t' => 'JOB WORK CONTRACT — IN',  'party' => 'Customer',  'move' => 'Received / returned'],
];
$K = $TYPES[$doc['contract_type']] ?? ['t' => 'CONTRACT', 'party' => 'Party', 'move' => 'Moved'];
$company = $config['company_name'] ?? 'ZAS TEXTILE';
$cur = $doc['currency'] ?: 'PKR';

$totContracted = 0.0; $totDone = 0.0;
foreach ($lines as $l) { $totContracted += $l['qty']; $totDone += $l['done']; }
$pct = $totContracted > 0 ? round($totDone / $totContracted * 100) : 0;
?><!doctype html>
<html lang="en"><head><meta charset="utf-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<title><?= e($doc['contract_no']) ?> — <?= e($K['t']) ?><?= $share ? '' : ' (status)' ?></title>
<style>
:root{--navy:#0b2a4a;--line:#dbe4ee;--sub:#5a7590}
*{box-sizing:border-box}
body{margin:0;background:#f0f3f8;color:var(--navy);font-family:"Segoe UI",Arial,sans-serif;padding:26px 16px}
.sheet{background:#fff;max-width:860px;margin:0 auto;padding:30px 32px;border:1px solid var(--line);border-radius:6px}
.ph{display:flex;justify-content:space-between;align-items:flex-start;border-bottom:2.5px solid var(--navy);padding-bottom:13px;margin-bottom:15px}
.co{font-size:20px;font-weight:800;letter-spacing:.02em}
.co small{display:block;font-size:9.5px;font-weight:600;color:var(--sub);letter-spacing:.07em;margin-top:3px}
.dt{text-align:right;font-size:11px;color:#3f5f7d;line-height:1.65}
.dt b{font-size:15px;font-family:"Courier New",monospace}
.ttl{background:var(--navy);color:#fff;text-align:center;font-size:12.5px;font-weight:800;letter-spacing:.16em;padding:8px;border-radius:4px;margin-bottom:15px}
.kv{display:grid;grid-template-columns:repeat(4,1fr);gap:10px 15px;font-size:11px;margin-bottom:15px}
.kv span{display:block;font-size:8.5px;text-transform:uppercase;letter-spacing:.07em;color:#7a93ac;font-weight:800;margin-bottom:2px}
.kv b{font-weight:700}
h3{font-size:10px;text-transform:uppercase;letter-spacing:.09em;color:var(--sub);margin:22px 0 8px;font-weight:800}
table{width:100%;border-collapse:collapse;font-size:11px}
th{text-align:left;color:var(--sub);border-bottom:1.5px solid var(--navy);padding:0 9px 6px;font-size:9.5px;text-transform:uppercase;letter-spacing:.05em}
td{border-top:1px solid var(--line);padding:8px 9px;vertical-align:top}
td.r,th.r{text-align:right;font-variant-numeric:tabular-nums}
tfoot td{border-top:1.5px solid var(--navy);font-weight:800}
.note{margin-top:13px;padding:9px 12px;background:#f2f6fa;border-radius:5px;font-size:10px;color:#3f5f7d;line-height:1.6}
.warn{background:#fdf3e6;color:#7a4d09}
.bar{max-width:860px;margin:0 auto 16px;display:flex;gap:9px;justify-content:flex-end}
.bar button,.bar a{padding:9px 17px;border-radius:9px;border:1px solid #cbd5e3;background:#fff;color:var(--navy);
  font-size:12.5px;font-weight:700;cursor:pointer;text-decoration:none;font-family:inherit}
.bar button{background:var(--navy);color:#fff;border-color:var(--navy)}
.stamp{display:inline-block;font-size:9.5px;font-weight:800;padding:3px 9px;border-radius:3px;letter-spacing:.06em}
.s-active{background:#e6f5ec;color:#16733d;border:1px solid #b9e0c9}
.s-draft{background:#eef1f7;color:#5a6b82;border:1px solid #d8e0ea}
.s-closed{background:#eef1f7;color:#33475c;border:1px solid #d8e0ea}
.s-cancelled{background:#fdeaee;color:#a8283f;border:1px solid #f2c2cc}
.done{color:#16733d;font-weight:800}
.open{color:#7a4d09;font-weight:800}
.over{color:#a8283f;font-weight:800}
.prog{height:6px;background:#eef1f7;border-radius:20px;overflow:hidden;margin-top:4px;min-width:80px}
.prog i{display:block;height:100%;background:var(--navy)}
.sigs{display:grid;grid-template-columns:repeat(2,1fr);gap:18px;margin-top:44px;font-size:9px;text-align:center;color:var(--sub)}
.sigs div{border-top:1px solid var(--navy);padding-top:6px;font-weight:700;letter-spacing:.04em}
.sigs em{display:block;font-style:normal;font-weight:400;margin-top:3px;color:#33475c}
@media print{ body{background:#fff;padding:0} .bar{display:none}
  .sheet{border:none;border-radius:0;max-width:none;padding:0} @page{margin:14mm} }
@media(max-width:640px){.kv{grid-template-columns:repeat(2,1fr)}}
</style></head><body>

<div class="bar">
  <a href="inv_contracts.php?edit=<?= (int)$doc['id'] ?>">← Back</a>
  <a href="?id=<?= (int)$doc['id'] ?><?= $share ? '' : '&amp;view=share' ?>"><?= $share ? 'Full report with status' : 'Contract only — to share' ?></a>
  <button onclick="window.print()">Print</button>
</div>

<div class="sheet">
  <div class="ph">
    <div class="co"><?= e($company) ?><small>TEXTILE EXPORTS · FAISALABAD, PAKISTAN</small></div>
    <div class="dt">
      <b><?= e($doc['contract_no']) ?></b><br>
      Date: <?= e($doc['contract_date'] ? date('d M Y', strtotime((string)$doc['contract_date'])) : '—') ?><br>
      <?php if ($doc['expected_date']): ?>Expected: <?= e(date('d M Y', strtotime((string)$doc['expected_date']))) ?><br><?php endif; ?>
      <span class="stamp s-<?= e($doc['status']) ?>"><?= e(strtoupper($doc['status'])) ?></span>
    </div>
  </div>

  <div class="ttl"><?= e($K['t']) ?></div>

  <div class="kv">
    <div><span><?= e($K['party']) ?></span><b><?= e($doc['party_name'] ?: '—') ?></b></div>
    <div><span>NTN</span><b><?= e($doc['party_ntn'] ?: '—') ?></b></div>
    <div><span>Currency</span><b><?= e($cur) ?></b></div>
    <div><span>Order reference</span><b><?= e($doc['pi_no'] ?: '—') ?></b></div>
    <?php if ($doc['process']): ?><div><span>Process</span><b><?= e($doc['process']) ?></b></div><?php endif; ?>
    <?php if ((float)$doc['wastage_pct'] > 0): ?><div><span>Agreed wastage</span><b><?= e(rtrim(rtrim(number_format((float)$doc['wastage_pct'], 3), '0'), '.')) ?>%</b></div><?php endif; ?>
    <?php if (!$share): ?>
      <div><span>Completion</span><b><?= (int)$pct ?>%</b><div class="prog"><i style="width:<?= max(0, min(100, (int)$pct)) ?>%"></i></div></div>
      <div><span>Prepared by</span><b><?= e($doc['created_name'] ?: '—') ?></b></div>
    <?php endif; ?>
  </div>

  <?php if ($doc['terms']): ?><div class="note"><b>Terms:</b> <?= e($doc['terms']) ?></div><?php endif; ?>

  <h3><?= $share ? 'Contracted lines' : 'Contracted lines &amp; balance' ?></h3>
  <?php
    /* Column plan, in one place, so the header, the body and every
       colspan below cannot drift apart:
         3 fixed  (#, item, description)
         + 1 or 3 quantity columns  (contracted [, received, balance])
         + 1 UOM
         + 1 or 3 rate columns      (rate [, gst %, rate incl])
         + 1 amount                                                    */
    $gstOn  = $T['applies'];
    $gstPct = (float)$T['pct'];
    $qCols  = $share ? 1 : 3;
    $rCols  = $gstOn ? 3 : 1;
    $preAmt = 3 + $qCols + 1 + $rCols;   // everything left of Amount
  ?>
  <table>
    <thead><tr>
      <th style="width:22px">#</th><th>Item</th><th>Description on the contract</th>
      <th class="r" style="width:78px">Contracted</th>
      <?php if (!$share): ?>
        <th class="r" style="width:74px"><?= e($K['move']) ?></th>
        <th class="r" style="width:74px">Balance</th>
      <?php endif; ?>
      <th style="width:46px">UOM</th>
      <?php if ($gstOn): ?>
        <th class="r" style="width:78px">Rate excl.</th>
        <th class="r" style="width:44px;background:#f2f7fa">GST %</th>
        <th class="r" style="width:82px">Rate incl.</th>
      <?php else: ?>
        <th class="r" style="width:80px">Rate</th>
      <?php endif; ?>
      <th class="r" style="width:96px">Amount</th>
    </tr></thead>
    <tbody>
    <?php if (!$lines): ?>
      <tr><td colspan="<?= $preAmt + 1 ?>" style="padding:20px;text-align:center;color:var(--sub)">This contract has no lines.</td></tr>
    <?php else: $i = 1; foreach ($lines as $l):
        $rIncl = $l['rate'] * (1 + ($gstOn ? $gstPct / 100 : 0));
        // the line reads the way it will be invoiced
        $amt   = $l['qty'] * $rIncl;
    ?>
      <tr>
        <td><?= $i++ ?></td>
        <td><b><?= e($l['item']) ?></b></td>
        <td><?= e($l['description'] !== '' ? $l['description'] : '—') ?></td>
        <td class="r"><?= number_format($l['qty'], 2) ?></td>
        <?php if (!$share): ?>
          <td class="r"><?= number_format($l['done'], 2) ?></td>
          <td class="r"><span class="<?= $l['over'] ? 'over' : ($l['balance'] <= 0.0005 ? 'done' : 'open') ?>">
            <?= $l['over'] ? 'over ' . number_format($l['done'] - $l['qty'], 2) : number_format($l['balance'], 2) ?></span></td>
        <?php endif; ?>
        <td><?= e($l['uom']) ?></td>
        <?php if ($gstOn): ?>
          <td class="r"><?= number_format($l['rate'], 2) ?></td>
          <td class="r" style="background:#f2f7fa"><?= rtrim(rtrim(number_format($gstPct, 2), '0'), '.') ?>%</td>
          <td class="r"><?= number_format($rIncl, 2) ?></td>
        <?php else: ?>
          <td class="r"><?= number_format($l['rate'], 2) ?></td>
        <?php endif; ?>
        <td class="r"><?= number_format($amt, 2) ?></td>
      </tr>
    <?php endforeach; endif; ?>
    </tbody>
    <?php if ($lines): ?>
    <tfoot>
      <?php /* quantities only — the money is stated once, below, never twice */ ?>
      <tr><td colspan="3" style="text-align:right">TOTAL</td>
          <td class="r"><?= number_format($totContracted, 2) ?></td>
          <?php if (!$share): ?>
            <td class="r"><?= number_format($totDone, 2) ?></td>
            <td class="r"><?= number_format(max(0, $totContracted - $totDone), 2) ?></td>
          <?php endif; ?>
          <td colspan="<?= 1 + $rCols ?>"></td><td></td></tr>
      <tr><td colspan="<?= $preAmt ?>" style="text-align:right">VALUE EXCLUDING TAX</td>
          <td class="r"><?= e($cur) ?> <?= number_format($T['excl'], 2) ?></td></tr>
      <?php if ($gstOn): ?>
        <tr><td colspan="<?= $preAmt ?>" style="text-align:right">GST @ <?= rtrim(rtrim(number_format($gstPct, 2), '0'), '.') ?>%</td>
            <td class="r"><?= number_format($T['gst'], 2) ?></td></tr>
      <?php endif; ?>
      <tr><td colspan="<?= $preAmt ?>" style="text-align:right">VALUE INCLUDING TAX</td>
          <td class="r"><?= e($cur) ?> <?= number_format($T['incl'], 2) ?></td></tr>
    </tfoot>
    <?php endif; ?>
  </table>

  <?php if (!$gstOn && $T['excl'] > 0): ?>
    <div class="note">No sales tax is applied to this contract, so the two totals are the same.</div>
  <?php endif; ?>

  <?php if (!$share && $unassigned['lines'] > 0): ?>
    <div class="note warn"><b><?= (int)$unassigned['lines'] ?> posted gate line(s)</b> name this contract but not a
      particular line of it — <?= number_format($unassigned['qty'], 2) ?> quantity, value
      <?= number_format($unassigned['amount'], 2) ?>. They are <b>not</b> counted in the balances above. Raise gate
      passes with <b>Pull lines from contract</b> so each delivery is matched to the line it satisfies.</div>
  <?php endif; ?>

  <?php if (!$share): ?>
  <h3>Ledger — every gate pass against this contract</h3>
  <table>
    <thead><tr><th style="width:80px">Date</th><th style="width:100px">Pass no.</th><th>Movement</th>
      <th class="r" style="width:72px">Lines</th><th class="r" style="width:88px">Quantity</th>
      <th class="r" style="width:96px">Value</th><th style="width:76px">Status</th></tr></thead>
    <tbody>
    <?php if (!$moves): ?>
      <tr><td colspan="7" style="padding:20px;text-align:center;color:var(--sub)">Nothing has moved against this contract yet.</td></tr>
    <?php else: $rq = 0.0; $rv = 0.0; foreach ($moves as $m):
        $posted = $m['status'] === 'posted';
        if ($posted) { $rq += (float)$m['qty']; $rv += (float)$m['amount']; }
        $tl = inv_gate_types($m['direction']);
    ?>
      <tr<?= $posted ? '' : ' style="color:#8a9db1"' ?>>
        <td><?= e(date('d M Y', strtotime((string)$m['gate_date']))) ?></td>
        <td style="font-family:'Courier New',monospace"><?= e($m['gate_no']) ?></td>
        <td><?= e($tl[$m['txn_type']]['label'] ?? $m['txn_type']) ?>
            <span style="color:var(--sub)">· <?= $m['direction'] === 'in' ? 'inward' : 'outward' ?></span></td>
        <td class="r"><?= (int)$m['n'] ?></td>
        <td class="r"><?= number_format((float)$m['qty'], 2) ?></td>
        <td class="r"><?= number_format((float)$m['amount'], 2) ?></td>
        <td><?= e(ucfirst($m['status'])) ?><?= $posted ? '' : ' *' ?></td>
      </tr>
    <?php endforeach; endif; ?>
    </tbody>
    <?php if ($moves): ?>
    <tfoot><tr><td colspan="4" style="text-align:right">POSTED TOTAL</td>
      <td class="r"><?= number_format($rq, 2) ?></td>
      <td class="r"><?= number_format($rv, 2) ?></td><td></td></tr></tfoot>
    <?php endif; ?>
  </table>
  <div class="note">Rows marked <b>*</b> are not posted and change no balance. Only posted passes count.</div>
  <?php endif; ?>

  <?php if ($doc['remarks']): ?><div class="note"><b>Remarks:</b> <?= e($doc['remarks']) ?></div><?php endif; ?>

  <?php if ($share): ?>
    <div class="note">This is the contract as agreed. Delivery status is kept separately.</div>
  <?php endif; ?>

  <div class="sigs">
    <div><?= e($company) ?><em>Authorised signature</em></div>
    <div><?= e($doc['party_name'] ?: $K['party']) ?><em>Authorised signature</em></div>
  </div>
</div>
</body></html>
