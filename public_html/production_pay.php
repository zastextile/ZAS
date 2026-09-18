<?php
/*
  WORKER PAY — earned, paid, balance. Nothing else.
  ==================================================

  Asked for in exactly these words, after a much bigger proposal was turned
  down: "just deal financie for workers only against production so simple
  pay cash system very simple worker ledger etc".

  So there is no cash account, no voucher, no chart of accounts and no
  double entry, and there should never be. There are two numbers about one
  person — what production booked to them, and what was handed over — and
  the difference between them.

  TWO THINGS ON THE SCREEN.

  1. PAY TODAY. A grid of who is owed what, most owed first, with the
     amount already filled in. Change it, clear the ones you are not
     paying, save. Paste from Excel if the list came from somewhere else.

  2. ONE WORKER'S LEDGER. Every day they earned, every payment, and the
     balance after each line. A cancelled payment is shown struck through
     and counts for nothing — hiding it would make the balance look like it
     moved on its own.

  A PAYMENT IS NEVER DELETED. It is cancelled, with a reason, the same rule
  the wage entries follow: a correction has to stay readable or an argument
  about money cannot be settled.
*/
require_once __DIR__ . '/includes/bootstrap.php';
require_login();
if (!is_admin() && !is_colleague()) { http_response_code(403); exit('Production access required.'); }
require_once __DIR__ . '/includes/zprod.php';
zp_ensure_schema();

header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');

$msg = ''; $err = ''; $warn = [];
$wid = (int)($_GET['w'] ?? 0);

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verify_csrf();
    $a = $_POST['action'] ?? '';
    if ($a === 'pay') {
        /* The grid posts three parallel arrays, one per column. Zipped here
           rather than in the engine — the engine should not have to know
           what shape a form happens to be. */
        $rows = [];
        $n = count((array)($_POST['p_worker'] ?? []));
        for ($i = 0; $i < $n; $i++) {
            $rows[] = ['worker_id' => $_POST['p_worker'][$i] ?? 0,
                       'amount'    => $_POST['p_amount'][$i] ?? 0,
                       'note'      => $_POST['p_note'][$i] ?? ''];
        }
        $r = zp_pay_save((string)($_POST['pay_date'] ?? ''), $rows, (int)(current_user()['id'] ?? 0));
        if ($r['ok']) {
            $t = number_format($r['total'], 2);
            $_SESSION['zp_msg'] = $r['saved'] . ' payment' . ($r['saved'] === 1 ? '' : 's') . ' saved, ' . $t . ' in all.'
                . ($r['warn'] ? ' Paid more than owed: ' . implode('; ', array_slice($r['warn'], 0, 4))
                    . (count($r['warn']) > 4 ? ' and ' . (count($r['warn']) - 4) . ' more.' : '.') : '');
            redirect('production_pay.php');
        }
        $err = implode(' | ', array_slice($r['errors'], 0, 8))
             . (count($r['errors']) > 8 ? ' … and ' . (count($r['errors']) - 8) . ' more' : '');
    } elseif ($a === 'cancel') {
        $r = zp_pay_cancel((int)($_POST['pay_id'] ?? 0), (string)($_POST['reason'] ?? ''), (int)(current_user()['id'] ?? 0));
        $_SESSION['zp_msg'] = $r['ok'] ? 'Payment cancelled. It is still on the ledger, struck through.' : '';
        if (!$r['ok']) $_SESSION['error'] = $r['error'];
        redirect('production_pay.php?w=' . (int)($_POST['back_w'] ?? 0));
    }
}
if (!empty($_SESSION['zp_msg'])) { $msg = $_SESSION['zp_msg']; unset($_SESSION['zp_msg']); }
if (!empty($_SESSION['error']))  { $err = $_SESSION['error'];  unset($_SESSION['error']); }

$bal    = zp_worker_balances();
$owing  = array_values(array_filter($bal, fn($b) => $b['balance'] > 0.005));
$totOwed = round(array_sum(array_column($bal, 'balance')), 2);
$totEarn = round(array_sum(array_column($bal, 'earned')), 2);
$totPaid = round(array_sum(array_column($bal, 'paid')), 2);

$ledger = $wid > 0 ? zp_worker_ledger($wid) : [];
$who = null; foreach ($bal as $b) if ($b['id'] === $wid) { $who = $b; break; }

page_header('Worker Pay');
?>
<style>
.zp-card{background:#fff;border:1px solid #e6ebf2;border-radius:13px;padding:16px 17px;margin-bottom:16px;box-shadow:0 1px 2px rgba(20,40,80,.04)}
.zp-card h2{margin:0 0 3px;font-size:15.5px;color:#152033}
.zp-card p.sub{margin:0 0 12px;font-size:11.5px;color:#8a97ab}
.zin{width:100%;padding:6px 8px;border:1px solid #d9e0ea;border-radius:8px;font-size:12.5px;background:#fff;color:#152033;font-family:inherit}
.zin:focus{outline:none;border-color:#0ea8c9;box-shadow:0 0 0 3px rgba(14,168,201,.14)}
.zin.num{text-align:right;font-variant-numeric:tabular-nums}
.lab{display:block;font-size:10.5px;text-transform:uppercase;letter-spacing:.04em;color:#8a97ab;font-weight:800;margin-bottom:4px}
table.zp-t{width:100%;border-collapse:collapse;font-size:12.5px}
table.zp-t th{text-align:left;font-size:10.5px;text-transform:uppercase;letter-spacing:.04em;color:#8a97ab;padding:6px 7px;border-bottom:1px solid #e6ebf2;background:#fbfcfe;white-space:nowrap}
table.zp-t td{padding:4px 7px;border-bottom:1px solid #f1f4f9;vertical-align:middle}
table.zp-t td.r,table.zp-t th.r{text-align:right;font-variant-numeric:tabular-nums}
table.zp-t tbody tr:hover{background:#fafcff}
.code{font-family:ui-monospace,Menlo,Consolas,monospace;font-size:11.5px;color:#5a6b82;max-width:150px;word-break:break-all}
.zp-b{display:inline-flex;align-items:center;gap:6px;padding:7px 13px;border-radius:9px;border:1px solid #d9e0ea;background:#fff;color:#33445c;font-size:12.5px;cursor:pointer;font-family:inherit;text-decoration:none}
.zp-b:hover{border-color:#0ea8c9;color:#0b7f99}
.zp-b.pri{background:#1d76e2;border-color:#1d76e2;color:#fff}
.zp-b.sm{padding:4px 10px;font-size:11.5px}
.zp-b.red{background:#e0435d;border-color:#e0435d;color:#fff}
.strip{display:flex;flex-wrap:wrap;gap:10px;margin-bottom:14px}
.tile{flex:1 1 170px;border:1px solid #e6ebf2;border-radius:11px;padding:9px 12px;background:#fbfcfe}
.tile .k{font-size:9.5px;text-transform:uppercase;letter-spacing:.05em;color:#8a97ab;font-weight:800}
.tile .v{font-size:20px;font-weight:800;font-variant-numeric:tabular-nums;margin-top:2px}
.tile.owed{background:#fff7f8;border-color:#f3ccd4}.tile.owed .v{color:#9a2740}
.note{padding:11px 13px;border-radius:10px;font-size:12.5px;line-height:1.55;background:#eef6ff;border:1px solid #cfe3fb;color:#28527d;margin-bottom:14px}
.note.ok{background:#f4fbf6;border-color:#cfe9d8;color:#1d6b46}
.note.bad{background:#fff7f8;border-color:#f3ccd4;color:#9a2740}
.gone td{color:#b6c0cf;text-decoration:line-through}
.gone td.keep{text-decoration:none;color:#8a97ab}
</style>
<div class="zskin">

<div style="display:flex;justify-content:space-between;align-items:flex-end;flex-wrap:wrap;gap:10px;margin-bottom:14px">
  <div><h1 style="margin:0;font-size:21px">Worker Pay</h1>
    <p style="margin:3px 0 0;font-size:12.5px;color:#8a97ab">What production booked to each person, what has been handed over, and the difference.</p></div>
  <div style="display:flex;gap:8px">
    <a class="zp-b" href="production_workers.php">Workers</a>
    <a class="zp-b" href="production_entry.php">Daily Entry</a>
  </div>
</div>

<?php if ($msg): ?><div class="note ok"><?= e($msg) ?></div><?php endif; ?>
<?php if ($err): ?><div class="note bad"><?= e($err) ?></div><?php endif; ?>

<div class="strip">
  <div class="tile"><div class="k">Earned, all time</div><div class="v"><?= number_format($totEarn, 2) ?></div></div>
  <div class="tile"><div class="k">Paid, all time</div><div class="v"><?= number_format($totPaid, 2) ?></div></div>
  <div class="tile owed"><div class="k">Owed right now</div><div class="v"><?= number_format($totOwed, 2) ?></div>
    <div class="k" style="text-transform:none;letter-spacing:0;font-weight:400"><?= count($owing) ?> worker(s)</div></div>
</div>

<?php /* ---------------------------------------------------------------
         PAY TODAY. The list is who is owed something, most owed first,
         with the amount already filled in — because the common case is
         "pay them what they are owed" and typing it again is work for
         nothing. Clear a box to skip that person. */ ?>
<div class="zp-card">
  <h2>Pay today</h2>
  <p class="sub">Everyone with a balance, most owed first. The amount is filled in — change it for a part payment, or clear it to skip. <b>Enter</b> moves on, <b>Ctrl+S</b> saves.</p>

  <?php if (!$owing): ?>
    <div class="note">Nobody is owed anything. Either everything is paid, or no production has been booked yet.</div>
  <?php else: ?>
  <form method="post" id="payForm"><?= csrf_field() ?><input type="hidden" name="action" value="pay">
    <div style="display:grid;grid-template-columns:150px 1fr;gap:12px;margin-bottom:12px;max-width:420px">
      <div><span class="lab">Date</span>
        <input class="zin" type="date" name="pay_date" value="<?= e(date('Y-m-d')) ?>" max="<?= e(date('Y-m-d')) ?>"></div>
      <div style="display:flex;align-items:flex-end"><span id="payState" style="font-size:11.5px;color:#8a97ab">saved</span></div>
    </div>

    <div style="overflow-x:auto;max-height:520px;overflow-y:auto">
    <table class="zp-t"><thead><tr>
      <th style="width:30px">#</th><th style="width:120px">Code</th><th>Name</th><th>Department</th>
      <th class="r" style="width:110px">Earned</th><th class="r" style="width:110px">Paid</th>
      <th class="r" style="width:110px">Owed</th><th style="width:130px">Pay now</th><th style="width:180px">Note</th>
    </tr></thead><tbody id="payTb">
    <?php foreach ($owing as $i => $b): ?>
      <tr>
        <td style="color:#8a97ab;font-size:11px"><?= $i + 1 ?></td>
        <td class="code"><?= e($b['code']) ?><input type="hidden" name="p_worker[]" value="<?= (int)$b['id'] ?>"></td>
        <td><a href="production_pay.php?w=<?= (int)$b['id'] ?>" style="font-weight:600;color:#152033"><?= e($b['name']) ?></a>
            <?= $b['active'] ? '' : ' <span style="font-size:10px;color:#9a5710">(inactive)</span>' ?></td>
        <td style="color:#5a6b82"><?= e($b['dept']) ?></td>
        <td class="r"><?= number_format($b['earned'], 2) ?></td>
        <td class="r" style="color:#8a97ab"><?= number_format($b['paid'], 2) ?></td>
        <td class="r" style="font-weight:800;color:#9a2740"><?= number_format($b['balance'], 2) ?></td>
        <td><input class="zin num amt" data-c="amount" name="p_amount[]" value="<?= e((string)round($b['balance'], 2)) ?>"
                   data-owed="<?= e((string)round($b['balance'], 2)) ?>"></td>
        <td><input class="zin" data-c="note" name="p_note[]" maxlength="255" autocomplete="off" placeholder="optional"></td>
      </tr>
    <?php endforeach; ?>
    </tbody></table></div>

    <div style="margin-top:13px;display:flex;gap:10px;align-items:center;flex-wrap:wrap">
      <button class="zp-b pri" type="submit">Pay <span id="payN">0</span> worker(s) — <span id="payT">0.00</span></button>
      <button class="zp-b" type="button" id="clearAll">Clear every amount</button>
      <button class="zp-b" type="button" id="fillAll">Fill every amount</button>
      <span style="font-size:11.5px;color:#8a97ab">Paying more than is owed is allowed — an advance is ordinary — and it is said afterwards.</span>
    </div>
  </form>
  <?php endif; ?>
</div>

<?php /* --------------------------------------------------------------- */ ?>
<div class="zp-card">
  <h2>Worker ledger<?= $who ? ' — ' . e($who['name']) : '' ?></h2>
  <p class="sub">Click a name above, or pick one here. Every day earned, every payment, and the balance after each line.</p>

  <form method="get" style="display:flex;gap:8px;align-items:flex-end;margin-bottom:12px;flex-wrap:wrap">
    <div style="min-width:280px"><span class="lab">Worker</span>
      <select class="zin" name="w" onchange="this.form.submit()">
        <option value="0">— choose —</option>
        <?php foreach ($bal as $b): ?>
          <option value="<?= (int)$b['id'] ?>" <?= $b['id'] === $wid ? 'selected' : '' ?>>
            <?= e($b['code']) ?> · <?= e($b['name']) ?><?= abs($b['balance']) > 0.005 ? ' — owed ' . number_format($b['balance'], 2) : '' ?>
          </option>
        <?php endforeach; ?>
      </select></div>
  </form>

  <?php if ($wid <= 0): ?>
    <div class="note">Pick somebody to see their ledger.</div>
  <?php elseif (!$ledger): ?>
    <div class="note">Nothing booked to them yet, and nothing paid.</div>
  <?php else: ?>
    <div class="strip">
      <div class="tile"><div class="k">Earned</div><div class="v"><?= number_format($who['earned'] ?? 0, 2) ?></div></div>
      <div class="tile"><div class="k">Paid</div><div class="v"><?= number_format($who['paid'] ?? 0, 2) ?></div></div>
      <div class="tile <?= ($who['balance'] ?? 0) > 0.005 ? 'owed' : '' ?>"><div class="k">Balance</div>
        <div class="v"><?= number_format($who['balance'] ?? 0, 2) ?></div></div>
    </div>
    <div style="overflow-x:auto">
    <table class="zp-t"><thead><tr>
      <th style="width:110px">Date</th><th>What</th>
      <th class="r" style="width:120px">Earned</th><th class="r" style="width:120px">Paid</th>
      <th class="r" style="width:130px">Balance</th><th>Note</th><th style="width:90px"></th>
    </tr></thead><tbody>
    <?php foreach ($ledger as $l): ?>
      <tr class="<?= $l['kind'] === 'cancelled' ? 'gone' : '' ?>">
        <td class="keep"><?= e($l['date']) ?></td>
        <td><?= e($l['what']) ?></td>
        <td class="r"><?= $l['in'] > 0 ? number_format($l['in'], 2) : '' ?></td>
        <td class="r"><?= $l['kind'] === 'cancelled' ? number_format($l['shown'] ?? 0, 2) : ($l['out'] > 0 ? number_format($l['out'], 2) : '') ?></td>
        <td class="r keep" style="font-weight:700"><?= number_format($l['balance'], 2) ?></td>
        <td class="keep" style="color:#8a97ab"><?= e($l['note']) ?></td>
        <td class="keep">
          <?php if ($l['kind'] === 'paid' && is_admin()): ?>
            <form method="post" style="display:inline"
                  onsubmit="var r=prompt('Why is this payment being cancelled?'); if(!r||!r.trim()) return false; this.reason.value=r; return true;">
              <?= csrf_field() ?><input type="hidden" name="action" value="cancel">
              <input type="hidden" name="pay_id" value="<?= (int)$l['id'] ?>">
              <input type="hidden" name="back_w" value="<?= (int)$wid ?>">
              <input type="hidden" name="reason" value="">
              <button class="zp-b sm red" type="submit">Cancel</button>
            </form>
          <?php endif; ?>
        </td>
      </tr>
    <?php endforeach; ?>
    </tbody></table></div>
  <?php endif; ?>
</div>

</div>
<link rel="stylesheet" href="assets/css/lov.css?v=3">
<script src="assets/js/grid.js?v=3"></script>
<script>
(function(){
  var tb = document.getElementById('payTb');
  if(!tb) return;
  function num(v){ v = parseFloat(String(v==null?'':v).replace(/,/g,'')); return isNaN(v)?0:v; }

  /* WHAT THE BUTTON IS ABOUT TO DO, on the button. A Pay button that does
     not say how many and how much is a button people press twice. */
  function tot(){
    var n = 0, t = 0;
    tb.querySelectorAll('.amt').forEach(function(el){
      var v = num(el.value);
      if(v > 0){ n++; t += v; }
      /* over the balance is allowed, and marked */
      var owed = num(el.dataset.owed);
      el.style.color = v > owed + 0.005 ? '#9a5710' : '';
      el.style.fontWeight = v > owed + 0.005 ? '700' : '';
    });
    document.getElementById('payN').textContent = n;
    document.getElementById('payT').textContent = t.toLocaleString('en-US',{minimumFractionDigits:2,maximumFractionDigits:2});
  }
  tb.addEventListener('input', tot);
  document.getElementById('clearAll').addEventListener('click', function(){
    tb.querySelectorAll('.amt').forEach(function(el){ el.value=''; }); tot(); });
  document.getElementById('fillAll').addEventListener('click', function(){
    tb.querySelectorAll('.amt').forEach(function(el){ el.value = el.dataset.owed; }); tot(); });

  /* The spreadsheet keys and Excel paste, from the shared file — the same
     ones the rest of the app uses. No addRow: the rows are the people who
     are owed money, and that list is not something you type into. */
  if(window.GRID){
    GRID.attach(tb, { cols:['amount','note'], afterChange: tot });
    GRID.keys(document.getElementById('payForm'), {
      badge: '#payState', what: 'pay sheet', escapeTo: 'production_workers.php'
    });
  }
  tot();
})();
</script>
<?php page_footer(); ?>
