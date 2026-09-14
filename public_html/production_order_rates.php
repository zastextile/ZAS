<?php
/*
  ORDER RATE AMENDMENTS — one order pays differently.
  ===================================================

  A tighter hem, a quality clause in the contract, a difficult fabric. One order
  pays more for one operation. Amended HERE, against that order — never on the
  part, because changing the part would move every other order using it.

  THE RULE THAT MAKES THIS SAFE: WAGES ALREADY BOOKED DO NOT MOVE.
  Every entry froze its rate into rate_applied the moment it was booked. An
  amendment applies to work booked FROM NOW ON, and the screen proves it by
  showing what is already booked beside each operation. That is also why
  removing an amendment is safe: nothing retroactively re-prices.

  A REASON IS REQUIRED. An amended rate nobody can explain six months later is
  indistinguishable from a mistake.

  READING AND CHANGING ARE DIFFERENT PERMISSIONS. A colleague may look; only an
  admin may move a rate.
*/
require_once __DIR__ . '/includes/bootstrap.php';
require_login();
if (!is_admin() && !is_colleague()) { http_response_code(403); exit('Production access required.'); }
require_once __DIR__ . '/includes/zprod.php';
zp_ensure_schema();

header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
header('Pragma: no-cache');
header('Expires: 0');

$me      = current_user();
$userId  = (int)($me['id'] ?? 0);
$canEdit = is_admin();
$pfId    = (int)($_GET['order'] ?? 0);
$msg = ''; $err = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verify_csrf();
    if (!$canEdit) {
        $err = 'Only an admin may change a rate. You can read this page, but not move a number on it.';
    } else {
        $a = $_POST['action'] ?? '';
        $pfId = (int)($_POST['proforma_id'] ?? 0);
        if ($a === 'save_rate') {
            $r = zp_order_rate_save($pfId, (int)($_POST['op_id'] ?? 0),
                                    (float)str_replace(',', '', (string)($_POST['rate'] ?? 0)),
                                    (string)($_POST['reason'] ?? ''), $userId);
            if ($r['ok']) { $_SESSION['zp_msg'] = 'Rate amended for this order only. Wages already booked are unchanged.';
                            redirect('production_order_rates.php?order=' . $pfId); }
            $err = $r['error'];
        } elseif ($a === 'clear_rate') {
            zp_order_rate_clear($pfId, (int)($_POST['op_id'] ?? 0), $userId);
            $_SESSION['zp_msg'] = 'Amendment removed — this order is back on the standard rate from now on. Wages already booked keep what they were paid.';
            redirect('production_order_rates.php?order=' . $pfId);
        }
    }
}
if (!empty($_SESSION['zp_msg'])) { $msg = $_SESSION['zp_msg']; unset($_SESSION['zp_msg']); }

$orders = zp_rate_orders();
$order  = null;
foreach ($orders as $o) if ((int)$o['id'] === $pfId) { $order = $o; break; }

/* WHAT THIS ORDER IS ACTUALLY CARRYING, NOT EVERY PART OF EVERY PRODUCT.
   $allOps is every operation this order's own products use. $ops is the part of
   that which this order has real work booked on, or already pays differently
   for. The screen shows $ops; "show the rest" opens $allOps, because a rate is
   most usefully amended BEFORE the first piece is booked. */
$allOps   = $order ? zp_ops_for_order($pfId) : [];
$relOps   = array_values(array_filter($allOps, fn($r) => $r['related']));
$showAll  = !empty($_GET['all']);
$ops      = $showAll ? $allOps : $relOps;
$amend    = $order ? zp_order_rate_map($pfId) : [];

/* The history is scoped the same way: this order's own amendments, plus
   standard-rate changes to the operations this order uses. Another customer's
   PO moving a rate is not this order's business. */
$history  = $order
          ? zp_rate_log_read(60, $pfId, array_column($allOps, 'id'))
          : zp_rate_log_read(60);

page_header('Order Rate Amendments');
?>
<style>
.zo-wrap{max-width:1280px}
.zp-b{display:inline-flex;align-items:center;gap:6px;padding:7px 13px;border-radius:9px;border:1px solid #d9e0ea;
      background:#fff;color:#33465f;font-size:12.5px;font-weight:700;cursor:pointer;text-decoration:none;line-height:1.15}
.zp-b:hover{border-color:#0ea8c9;color:#0b7f99}
.zp-b.pri{background:#1d76e2;border-color:#1d76e2;color:#fff}.zp-b.pri:hover{background:#1667c9;color:#fff}
.zp-b.red{background:#e0435d;border-color:#e0435d;color:#fff}.zp-b.red:hover{background:#c9384f;color:#fff}
.zp-b.sm{padding:4px 10px;font-size:11.5px}
.zp-b[disabled]{opacity:.45;cursor:not-allowed}
.zp-card{background:#fff;border:1px solid #e6ebf2;border-radius:13px;padding:16px 17px;margin-bottom:16px;
         box-shadow:0 1px 2px rgba(20,35,60,.04)}
.zp-card h2{margin:0 0 3px;font-size:15.5px;color:#152033}
.zp-card p.sub{margin:0 0 13px;font-size:12px;color:#8a97ab;line-height:1.5}
.zin{width:100%;padding:7px 9px;border:1px solid #d9e0ea;border-radius:8px;font-size:12.5px;
     font-family:inherit;color:#152033;background:#fff;box-sizing:border-box}
.zin:focus{outline:none;border-color:#0ea8c9;box-shadow:0 0 0 3px rgba(14,168,201,.14)}
.lab{display:block;font-size:10.5px;text-transform:uppercase;letter-spacing:.04em;color:#8a97ab;font-weight:800;margin-bottom:4px}
table.zp-t{width:100%;border-collapse:collapse;font-size:12.5px}
table.zp-t th{text-align:left;font-size:10.5px;text-transform:uppercase;letter-spacing:.04em;color:#8a97ab;
              font-weight:800;padding:8px;border-bottom:1px solid #e6ebf2;white-space:nowrap}
table.zp-t td{padding:7px 8px;border-bottom:1px solid #f1f4f9;vertical-align:middle}
.num{text-align:right;font-variant-numeric:tabular-nums;font-family:ui-monospace,Menlo,Consolas,monospace}
.code{font-family:ui-monospace,Menlo,Consolas,monospace;font-size:11.5px;color:#5a6b82}
.pill{display:inline-block;padding:2px 9px;border-radius:20px;font-size:10.5px;font-weight:800}
.pill.am{background:rgba(139,92,246,.14);color:#6d3fd4}
.pill.std{background:#eef1f6;color:#8a97ab}
.flash{padding:11px 14px;border-radius:10px;font-size:13px;font-weight:600;margin-bottom:15px}
.flash.ok{background:#effaf3;border:1px solid #c9ecd7;color:#1c6b40}
.flash.bad{background:#fdeef1;border:1px solid #f6cdd5;color:#9c2740}
.note{padding:11px 13px;border-radius:10px;font-size:12.5px;line-height:1.55}
.note.info{background:#eef6ff;border:1px solid #cfe3fb;color:#28527d}
.note.warn{background:#fff6e8;border:1px solid #f3ddb8;color:#8a5a10}
.empty{padding:24px;text-align:center;color:#8a97ab;font-size:12.5px;line-height:1.6}
</style>
<?php /* THE SKIN, OPTED IN. Every rule in assets/css/zskin.css is scoped
         under .zskin, so this one attribute is the whole of the restyle and
         removing it puts the page back exactly as it was. The page keeps its
         own .zp-card / .zp-t / .zp-b names; the skin maps onto them. */ ?>
<div class="zskin">

<div class="zo-wrap">
  <div style="display:flex;justify-content:space-between;align-items:center;gap:14px;flex-wrap:wrap;margin-bottom:14px">
    <div>
      <h1 style="margin:0;font-size:20px;color:#152033">Order Rate Amendments</h1>
      <p style="margin:2px 0 0;font-size:12.5px;color:#8a97ab">
        One order paying differently, without moving anybody else's rate.</p>
    </div>
    <a class="zp-b" href="production_dashboard.php">Dashboard</a>
  </div>

  <?php if ($msg): ?><div class="flash ok"><?= e($msg) ?></div><?php endif; ?>
  <?php if ($err): ?><div class="flash bad"><?= e($err) ?></div><?php endif; ?>

  <?php if (!$canEdit): ?>
    <div class="note warn" style="margin-bottom:16px">
      <b>You can read this page but not change it.</b> Moving a wage rate is an admin action.
    </div>
  <?php endif; ?>

  <div class="zp-card">
    <span class="lab">Which order?</span>
    <?php
      /* Orders that are actually running are listed apart from orders that are
         only switched on. Burying the two orders in production inside forty
         that have never been touched is how the wrong order gets amended. */
      $liveOrders = array_values(array_filter($orders, fn($r) => !empty($r['active'])));
      $idleOrders = array_values(array_filter($orders, fn($r) => empty($r['active'])));
      $optRow = function (array $o) use ($pfId) {
          $bits = [];
          if ($o['worked_ops'])  $bits[] = $o['worked_ops'] . ' operation' . ($o['worked_ops'] == 1 ? '' : 's') . ' worked';
          if ($o['amend_count']) $bits[] = $o['amend_count'] . ' amended';
          $tail = $bits ? '  ·  ' . implode(', ', $bits) : '';
          echo '<option value="' . (int)$o['id'] . '"' . ((int)$o['id'] === $pfId ? ' selected' : '') . '>'
             . e($o['pi_no']) . ' — ' . e($o['customer_name'] ?: 'no customer') . e($tail) . '</option>';
      };
    ?>
    <select class="zin" style="max-width:560px"
            onchange="location.href='production_order_rates.php?order='+encodeURIComponent(this.value)">
      <option value="">Choose an order…</option>
      <?php if ($liveOrders): ?>
        <optgroup label="In production — work booked or already amended">
          <?php foreach ($liveOrders as $o) $optRow($o); ?>
        </optgroup>
      <?php endif; ?>
      <?php if ($idleOrders): ?>
        <optgroup label="Switched on, but nothing made yet">
          <?php foreach ($idleOrders as $o) $optRow($o); ?>
        </optgroup>
      <?php endif; ?>
    </select>
    <?php if (!$orders): ?>
      <p style="margin:10px 0 0;font-size:12.5px;color:#8a5a10">
        No order is switched on for production yet, so there is nothing to amend.</p>
    <?php endif; ?>
  </div>

  <?php if ($order): ?>
    <div class="zp-card">
      <h2><?= e($order['pi_no']) ?> — <?= e($order['customer_name'] ?: 'no customer') ?></h2>
      <p class="sub">
        <?php if ($showAll): ?>
          Every operation this order's own products use — <?= count($allOps) ?> in all, of which
          <b><?= count($relOps) ?></b> this order has actually worked or amended.
        <?php else: ?>
          Only the work this order has actually got riding on it: operations with pieces booked
          <b>on this PO</b>, or that already pay a different rate here.
        <?php endif; ?>
        A rate set here moves <b>this order only</b> — no other customer and no other PO.
      </p>

      <div style="display:flex;gap:9px;flex-wrap:wrap;margin-bottom:12px">
        <?php if ($showAll): ?>
          <a class="zp-b" href="production_order_rates.php?order=<?= $pfId ?>">
            Show only this order's own work (<?= count($relOps) ?>)</a>
        <?php elseif (count($allOps) > count($relOps)): ?>
          <a class="zp-b" href="production_order_rates.php?order=<?= $pfId ?>&amp;all=1">
            Also show the <?= count($allOps) - count($relOps) ?> not started yet</a>
        <?php endif; ?>
      </div>

      <?php if (!$ops && !$allOps): ?>
        <div class="empty">
          This order's lines do not resolve to products with parts yet.<br>
          Check the product names on <a href="product_master.php">Master Products</a>.
        </div>
      <?php elseif (!$ops): ?>
        <div class="empty">
          Nothing has been made on this order yet, so there is no work here to amend.<br>
          <a href="production_order_rates.php?order=<?= $pfId ?>&amp;all=1">Show the
          <?= count($allOps) ?> operation<?= count($allOps) == 1 ? '' : 's' ?> its products use</a>
          if you want to set a rate before the work starts.
        </div>
      <?php else: ?>
      <div style="overflow-x:auto">
      <table class="zp-t">
        <thead><tr>
          <th>Product</th><th>Part &amp; operation</th><th style="width:110px">Stage</th>
          <th class="num" style="width:90px">Standard</th>
          <th class="num" style="width:120px">This order pays</th>
          <th class="num" style="width:150px">Already booked</th>
          <th style="width:150px"></th>
        </tr></thead>
        <tbody>
        <?php foreach ($ops as $o): $oid = (int)$o['id'];
          $has = array_key_exists($oid, $amend);
          $bk  = $o['booked_qty'] > 0; ?>
          <tr<?= $o['related'] ? '' : ' style="background:#fcfdff"' ?>>
            <td><?= $o['products'] ? e(implode(', ', $o['products'])) : '<span class="code">—</span>' ?>
              <?php if (!$o['on_order']): ?>
                <div class="code" style="font-size:10px;color:#8a5a10">no longer on this order's products</div>
              <?php endif; ?></td>
            <td><?= e($o['part']) ?><div class="code" style="font-size:10.5px"><?= e($o['operation']) ?></div></td>
            <td><?= e($o['stage']) ?></td>
            <td class="num"><?= number_format($o['standard'], 2) ?></td>
            <td class="num">
              <?php if ($has): ?>
                <b><?= number_format((float)$amend[$oid], 2) ?></b> <span class="pill am">amended</span>
              <?php else: ?>
                <span class="pill std">standard</span>
              <?php endif; ?>
            </td>
            <td class="num" style="font-size:11.5px;color:#5a6b82">
              <?php if ($bk): ?>
                <?= rtrim(rtrim(number_format($o['booked_qty'], 2), '0'), '.') ?> pcs
                <div class="code" style="font-size:10px">PKR <?= number_format($o['booked_amt'], 2) ?> paid</div>
              <?php else: ?><span style="color:#b6c0cf">not started</span><?php endif; ?>
            </td>
            <td>
              <?php if ($canEdit): ?>
                <button type="button" class="zp-b sm"
                        onclick="zoAmend(<?= $oid ?>, '<?= e(addslashes($o['part'] . ' — ' . $o['operation'])) ?>', <?= $o['standard'] ?>, <?= $has ? (float)$amend[$oid] : 'null' ?>)">
                  <?= $has ? 'Change' : 'Amend' ?></button>
                <?php if ($has): ?>
                  <form method="post" style="display:inline"
                        onsubmit="return confirm('Put this operation back on the standard rate?\n\nWork booked from now on pays <?= number_format($o['standard'], 2) ?>. Wages already paid do not change.')">
                    <?= csrf_field() ?><input type="hidden" name="action" value="clear_rate">
                    <input type="hidden" name="proforma_id" value="<?= $pfId ?>">
                    <input type="hidden" name="op_id" value="<?= $oid ?>">
                    <button class="zp-b sm red">Remove</button>
                  </form>
                <?php endif; ?>
              <?php else: ?>
                <button class="zp-b sm" disabled title="Admin only">Amend</button>
              <?php endif; ?>
            </td>
          </tr>
        <?php endforeach; ?>
        </tbody>
      </table>
      </div>
      <?php endif; ?>

      <div class="note info" style="margin-top:14px">
        <b>An amendment never re-prices work already done.</b>
        The "Already booked" column is the proof: every one of those entries froze its rate the moment it
        was booked. Change a number here and only work booked <b>after</b> it pays the new rate — which is
        also why removing an amendment is safe.
      </div>
    </div>

    <?php if ($canEdit): ?>
    <form method="post" id="amendForm" class="zp-card" style="display:none">
      <?= csrf_field() ?><input type="hidden" name="action" value="save_rate">
      <input type="hidden" name="proforma_id" value="<?= $pfId ?>">
      <input type="hidden" name="op_id" id="amOp" value="">
      <h2 id="amTitle">Amend</h2>
      <p class="sub" id="amStd"></p>
      <div style="display:grid;grid-template-columns:150px minmax(0,1fr);gap:12px;align-items:end">
        <div>
          <span class="lab">This order pays</span>
          <input class="zin num" name="rate" id="amRate" inputmode="decimal" autocomplete="off" required>
        </div>
        <div>
          <span class="lab">Why (required)</span>
          <input class="zin" name="reason" id="amReason" maxlength="255" required autocomplete="off"
                 placeholder="e.g. double-stitched hem specified in the contract">
        </div>
      </div>
      <div style="display:flex;gap:9px;margin-top:13px;flex-wrap:wrap">
        <button class="zp-b pri">Save this amendment</button>
        <button type="button" class="zp-b" onclick="document.getElementById('amendForm').style.display='none'">Cancel</button>
      </div>
      <div class="note warn" style="margin-top:12px">
        A rate nobody can explain six months later is indistinguishable from a mistake, so the reason is
        required and is kept with the change.
      </div>
    </form>
    <?php endif; ?>
  <?php endif; ?>

  <div class="zp-card">
    <?php if ($order): ?>
      <h2>Rate changes that concern <?= e($order['pi_no']) ?></h2>
      <p class="sub">This order's own amendments, plus standard-rate changes to the operations it uses.
         Another customer's PO moving a rate is not shown here — it does not affect this one.</p>
    <?php else: ?>
      <h2>Every rate that has moved</h2>
      <p class="sub">Standard rates and order amendments alike, newest first.
         Pick an order above to narrow this to that order.</p>
    <?php endif; ?>
    <?php if (!$history): ?>
      <div class="empty"><?= $order ? 'No rate touching this order has changed yet.' : 'No rate has changed yet.' ?></div>
    <?php else: ?>
      <div style="overflow-x:auto">
      <table class="zp-t">
        <thead><tr><th style="width:130px">When</th><th>Part &amp; operation</th>
          <th style="width:90px">Scope</th><th class="num" style="width:80px">From</th>
          <th class="num" style="width:80px">To</th><th>Reason</th></tr></thead>
        <tbody>
        <?php foreach ($history as $h): ?>
          <tr>
            <td class="code"><?= e(date('j M Y H:i', strtotime($h['created_at']))) ?></td>
            <td><?= e($h['part_name'] ?: '—') ?>
                <div class="code" style="font-size:10.5px"><?= e($h['operation_name'] ?: '—') ?></div></td>
            <td><?= $h['proforma_id'] ? '<span class="pill am">one order</span>' : '<span class="pill std">standard</span>' ?></td>
            <td class="num"><?= $h['old_rate'] === null ? '—' : number_format((float)$h['old_rate'], 2) ?></td>
            <td class="num"><b><?= number_format((float)$h['new_rate'], 2) ?></b></td>
            <td style="font-size:11.5px;color:#5a6b82"><?= e($h['reason'] ?: '—') ?></td>
          </tr>
        <?php endforeach; ?>
        </tbody>
      </table>
      </div>
    <?php endif; ?>
  </div>
</div>

<script>
window.zoAmend = function(opId, label, standard, current){
  var f = document.getElementById('amendForm');
  if (!f) return;
  document.getElementById('amOp').value = opId;
  document.getElementById('amTitle').textContent = 'Amend — ' + label;
  document.getElementById('amStd').textContent =
    'The part’s standard rate is ' + Number(standard).toFixed(2)
    + '. Work booked after you save pays what you type here; anything already booked keeps what it was paid.';
  var r = document.getElementById('amRate');
  r.value = current === null ? Number(standard).toFixed(2) : Number(current).toFixed(2);
  document.getElementById('amReason').value = '';
  f.style.display = '';
  f.scrollIntoView({block:'center', behavior:'smooth'});
  r.focus(); r.select();
};
</script>
</div><?php /* closes .zskin */ ?>
<?php page_footer(); ?>
