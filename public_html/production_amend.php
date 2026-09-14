<?php
/*
  CORRECT AN ENTRY.
  =================

  Somebody booked 500 instead of 50, or credited the wrong worker. This is where
  it is put right.

  A CORRECTION IS VISIBLE, NEVER INVISIBLE.

  Nothing here deletes. Cancelling marks the row — who, when, why — and it stays
  on every list, faded, with the reason beside it. Deleting it would make
  yesterday's total change with nothing on screen to explain why, and that is
  how a wage sheet stops being trustworthy.

  To fix a wrong number: cancel the wrong entry, then book the right one on
  Daily Production Entry. Two visible facts beat one silently edited one.

  CANCELLING CUTTING THAT STITCHING STANDS ON IS REFUSED, because undoing the
  cut under finished stitching would leave pieces stitched but never cut — a
  state no later correction can make true again.
*/
require_once __DIR__ . '/includes/bootstrap.php';
require_login();
if (!is_admin()) { http_response_code(403); exit('Admin access required.'); }
require_once __DIR__ . '/includes/zprod.php';
zp_ensure_schema();

header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
header('Pragma: no-cache');
header('Expires: 0');

$me     = current_user();
$userId = (int)($me['id'] ?? 0);
[$from, $to] = zp_range($_GET['from'] ?? date('Y-m-d', strtotime('-6 days')), $_GET['to'] ?? null);
$workerId = (int)($_GET['worker'] ?? 0);
$msg = ''; $err = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verify_csrf();
    if (($_POST['action'] ?? '') === 'cancel') {
        $r = zp_cancel_entry((int)($_POST['entry_id'] ?? 0), (string)($_POST['reason'] ?? ''), $userId);
        if ($r['ok']) $_SESSION['zp_msg'] = 'Entry cancelled. It stays on the list, marked, with your reason against it.';
        else          $_SESSION['zp_err'] = $r['error'];
        redirect('production_amend.php?from=' . urlencode($from) . '&to=' . urlencode($to)
                 . ($workerId ? '&worker=' . $workerId : ''));
    }
}
if (!empty($_SESSION['zp_msg'])) { $msg = $_SESSION['zp_msg']; unset($_SESSION['zp_msg']); }
if (!empty($_SESSION['zp_err'])) { $err = $_SESSION['zp_err']; unset($_SESSION['zp_err']); }

$filter = ['from' => $from, 'to' => $to];
if ($workerId) $filter['worker_id'] = $workerId;
$rows    = zp_entries($filter, 500);
$workers = zp_workers();

$activeN = 0; $cancN = 0; $activeAmt = 0.0;
foreach ($rows as $r) {
    if ($r['status'] === 'active') { $activeN++; $activeAmt += (float)$r['amount']; }
    else $cancN++;
}

page_header('Correct an Entry');
?>
<style>
.za-wrap{max-width:1420px}
.zp-b{display:inline-flex;align-items:center;gap:6px;padding:7px 13px;border-radius:9px;border:1px solid #d9e0ea;
      background:#fff;color:#33465f;font-size:12.5px;font-weight:700;cursor:pointer;text-decoration:none;line-height:1.15}
.zp-b:hover{border-color:#0ea8c9;color:#0b7f99}
.zp-b.pri{background:#1d76e2;border-color:#1d76e2;color:#fff}.zp-b.pri:hover{background:#1667c9;color:#fff}
.zp-b.red{background:#e0435d;border-color:#e0435d;color:#fff}.zp-b.red:hover{background:#c9384f;color:#fff}
.zp-b.sm{padding:4px 10px;font-size:11.5px}
.zp-card{background:#fff;border:1px solid #e6ebf2;border-radius:13px;padding:16px 17px;margin-bottom:16px;
         box-shadow:0 1px 2px rgba(20,35,60,.04)}
.zp-card h2{margin:0 0 3px;font-size:15.5px;color:#152033}
.zin{padding:7px 9px;border:1px solid #d9e0ea;border-radius:8px;font-size:12.5px;font-family:inherit;color:#152033;background:#fff}
.lab{display:block;font-size:10.5px;text-transform:uppercase;letter-spacing:.04em;color:#8a97ab;font-weight:800;margin-bottom:4px}
table.zp-t{width:100%;border-collapse:collapse;font-size:12.5px}
table.zp-t th{text-align:left;font-size:10.5px;text-transform:uppercase;letter-spacing:.04em;color:#8a97ab;
              font-weight:800;padding:8px;border-bottom:1px solid #e6ebf2;white-space:nowrap}
table.zp-t td{padding:7px 8px;border-bottom:1px solid #f1f4f9;vertical-align:middle}
table.zp-t tbody tr:hover{background:#fafcff}
tr.canc td{opacity:.55;background:#fdf7f8}
.num{text-align:right;font-variant-numeric:tabular-nums;font-family:ui-monospace,Menlo,Consolas,monospace}
.code{font-family:ui-monospace,Menlo,Consolas,monospace;font-size:11.5px;color:#5a6b82}
.pill{display:inline-block;padding:2px 9px;border-radius:20px;font-size:10.5px;font-weight:800}
.pill.cut{background:rgba(217,119,6,.13);color:#9a5710}
.pill.man{background:rgba(139,92,246,.14);color:#6d3fd4}
.pill.st{background:rgba(14,168,201,.14);color:#0b7f99}
.pill.canc{background:#fdeef1;color:#9c2740}
.flash{padding:11px 14px;border-radius:10px;font-size:13px;font-weight:600;margin-bottom:15px}
.flash.ok{background:#effaf3;border:1px solid #c9ecd7;color:#1c6b40}
.flash.bad{background:#fdeef1;border:1px solid #f6cdd5;color:#9c2740}
.note{padding:11px 13px;border-radius:10px;font-size:12.5px;line-height:1.55}
.note.warn{background:#fff6e8;border:1px solid #f3ddb8;color:#8a5a10}
.note.info{background:#eef6ff;border:1px solid #cfe3fb;color:#28527d}
.empty{padding:26px;text-align:center;color:#8a97ab;font-size:12.5px;line-height:1.6}
.sum{display:flex;gap:18px;flex-wrap:wrap;font-size:12.5px;color:#5a6b82;margin-top:11px;
     padding:10px 13px;background:#f7f9fc;border:1px solid #e6ebf2;border-radius:10px}
.sum b{color:#152033;font-variant-numeric:tabular-nums}
</style>
<?php /* THE SKIN, OPTED IN. Every rule in assets/css/zskin.css is scoped
         under .zskin, so this one attribute is the whole of the restyle and
         removing it puts the page back exactly as it was. The page keeps its
         own .zp-card / .zp-t / .zp-b names; the skin maps onto them. */ ?>
<div class="zskin">

<div class="za-wrap">
  <div style="display:flex;justify-content:space-between;align-items:flex-end;gap:14px;flex-wrap:wrap;margin-bottom:14px">
    <div>
      <h1 style="margin:0;font-size:20px;color:#152033">Correct an Entry</h1>
      <p style="margin:2px 0 0;font-size:12.5px;color:#8a97ab">
        Cancel what was booked wrongly, then book it again properly. Nothing is ever deleted.</p>
    </div>
    <form method="get" style="display:flex;gap:8px;align-items:flex-end;flex-wrap:wrap">
      <div><span class="lab">From</span><input class="zin" type="date" name="from" value="<?= e($from) ?>"></div>
      <div><span class="lab">To</span><input class="zin" type="date" name="to" value="<?= e($to) ?>" max="<?= e(date('Y-m-d')) ?>"></div>
      <div><span class="lab">Worker</span>
        <select class="zin" name="worker">
          <option value="0">Everyone</option>
          <?php foreach ($workers as $w): ?>
            <option value="<?= (int)$w['id'] ?>" <?= (int)$w['id'] === $workerId ? 'selected' : '' ?>>
              <?= e($w['worker_code']) ?> — <?= e($w['worker_name']) ?></option>
          <?php endforeach; ?>
        </select></div>
      <button class="zp-b pri">Find</button>
      <a class="zp-b" href="production_entry.php">Daily Entry</a>
    </form>
  </div>

  <?php if ($msg): ?><div class="flash ok"><?= e($msg) ?></div><?php endif; ?>
  <?php if ($err): ?><div class="flash bad"><?= e($err) ?></div><?php endif; ?>

  <div class="note warn" style="margin-bottom:16px">
    <b>To fix a wrong number, cancel it and book the right one.</b>
    There is deliberately no "edit". Two visible facts &mdash; the wrong entry, marked and explained, and
    the right one beside it &mdash; beat one number that was quietly changed and can never be questioned.
  </div>

  <div class="zp-card">
    <h2>Entries between <?= e(date('j M Y', strtotime($from))) ?> and <?= e(date('j M Y', strtotime($to))) ?></h2>

    <?php if (!$rows): ?>
      <div class="empty">
        Nothing booked in this period<?= $workerId ? ' for that worker' : '' ?>.<br>
        Try a wider date range.
      </div>
    <?php else: ?>
      <div style="overflow-x:auto">
      <table class="zp-t">
        <thead><tr>
          <th style="width:92px">Date</th><th>Worker</th><th>Product</th><th>Part &amp; operation</th>
          <th style="width:118px">Stage</th><th class="num" style="width:76px">Qty</th>
          <th class="num" style="width:76px">Rate</th><th class="num" style="width:94px">Amount</th>
          <th style="width:120px">Action</th>
        </tr></thead>
        <tbody>
        <?php foreach ($rows as $r): $canc = $r['status'] !== 'active';
          $cls = $r['stage'] === 'Cutting' ? 'cut' : ($r['stage'] === 'Manual Cutting' ? 'man' : 'st'); ?>
          <tr class="<?= $canc ? 'canc' : '' ?>">
            <td class="code"><?= e(date('j M', strtotime($r['entry_date']))) ?></td>
            <td><?= e($r['worker_name'] ?? '(removed worker)') ?>
                <div class="code" style="font-size:10px"><?= e($r['worker_code'] ?? '') ?></div></td>
            <td><?= e($r['product_name'] ?? '(removed product)') ?>
                <?php if ($r['pi_no']): ?><div class="code" style="font-size:10px"><?= e($r['pi_no']) ?></div><?php endif; ?></td>
            <td><?= e($r['part_name'] ?? '—') ?>
                <div class="code" style="font-size:10px"><?= e($r['operation_name'] ?? '(removed operation)') ?></div></td>
            <td><span class="pill <?= $cls ?>"><?= e($r['stage']) ?></span>
                <?php if ($canc): ?><br><span class="pill canc">cancelled</span><?php endif; ?></td>
            <td class="num"><?= rtrim(rtrim(number_format((float)$r['qty'], 2), '0'), '.') ?></td>
            <td class="num"><?= number_format((float)$r['rate_applied'], 2) ?></td>
            <td class="num"><?= number_format((float)$r['amount'], 2) ?></td>
            <td>
              <?php if (!$canc): ?>
                <button type="button" class="zp-b sm red" onclick="zaCancel(<?= (int)$r['id'] ?>)">Cancel</button>
              <?php else: ?>
                <div style="font-size:10.5px;color:#9c2740"><?= e($r['cancel_reason'] ?: 'no reason recorded') ?></div>
                <div class="code" style="font-size:9.5px"><?= $r['cancelled_at'] ? e(date('j M H:i', strtotime($r['cancelled_at']))) : '' ?></div>
              <?php endif; ?>
            </td>
          </tr>
        <?php endforeach; ?>
        </tbody>
      </table>
      </div>

      <div class="sum">
        <span><b><?= number_format($activeN) ?></b> active</span>
        <span><b><?= number_format($cancN) ?></b> cancelled</span>
        <span>Active wages <b>PKR <?= number_format($activeAmt, 2) ?></b></span>
      </div>
    <?php endif; ?>
  </div>

  <div class="note info">
    <b>Cancelling cutting that stitching already stands on is refused.</b>
    Undoing the cut under finished stitching would leave pieces that were stitched but never cut, and no
    later correction could make that true again. Cancel the stitching first, then the cutting.
  </div>

  <form method="post" id="cancelForm" style="display:none">
    <?= csrf_field() ?><input type="hidden" name="action" value="cancel">
    <input type="hidden" name="entry_id" value="">
    <input type="hidden" name="reason" value="">
  </form>
</div>

<script>
window.zaCancel = function(id){
  var why = prompt('Why is this entry being cancelled?\n\nThe row stays on every list, marked, with this reason against it.');
  if (why === null) return;
  if (!why.trim()) { alert('A reason is needed — a correction nobody can explain is indistinguishable from a mistake.'); return; }
  var f = document.getElementById('cancelForm');
  f.entry_id.value = id; f.reason.value = why.trim(); f.submit();
};
</script>
</div><?php /* closes .zskin */ ?>
<?php page_footer(); ?>
