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
    $pfId = (int)($_POST['proforma_id'] ?? 0);
    if (!$canEdit) {
        $err = 'Only an admin may change a rate. You can read this page, but not move a number on it.';
    } elseif (($_POST['action'] ?? '') === 'save_many') {
        /* EVERY CHANGE IN ONE SAVE, WITH ONE REASON (owner, 22 Nov 2026: "edit
           table"). Each operation is checked against THIS order's own list on
           the server — an id the page did not offer is refused, whatever the
           browser sent. A blank / 'std' rate puts the operation back on the
           standard rate. All or nothing: one bad line and nothing is saved. */
        $reason = trim((string)($_POST['reason'] ?? ''));
        $opIn   = (array)($_POST['op'] ?? []);
        $rateIn = (array)($_POST['rate'] ?? []);
        $okIds  = [];
        foreach (zp_ops_for_order($pfId) as $o) $okIds[(int)$o['id']] = (float)$o['standard'];
        $have   = zp_order_rate_map($pfId);
        $isOrder = false;
        foreach (zp_rate_orders() as $o) if ((int)$o['id'] === $pfId) { $isOrder = true; break; }
        $todo = []; $bad = [];
        foreach ($opIn as $k => $oid) {
            $oid = (int)$oid; $raw = trim((string)($rateIn[$k] ?? ''));
            if (!isset($okIds[$oid])) { $bad[] = 'An operation that is not on this order was sent.'; continue; }
            if ($raw === '' || $raw === 'std') { if (array_key_exists($oid, $have)) $todo[] = [$oid, null]; continue; }
            $v = str_replace(',', '', $raw);
            if (!is_numeric($v) || (float)$v < 0 || (float)$v > 100000) { $bad[] = '"' . $raw . '" is not a rate.'; continue; }
            $v = round((float)$v, 2);
            if (array_key_exists($oid, $have) && abs((float)$have[$oid] - $v) < 0.001) continue;   // unchanged
            $todo[] = [$oid, $v];
        }
        if (!$isOrder)          $err = 'That order is not switched on for production.';
        elseif ($bad)           $err = implode(' ', array_unique($bad)) . ' Nothing was saved.';
        elseif (!$todo)         $err = 'Nothing changed.';
        elseif ($reason === '') $err = 'Give the reason for this order paying a different rate. Nothing was saved.';
        else {
            db()->beginTransaction();
            try {
                foreach ($todo as [$oid, $v]) {
                    if ($v === null) { zp_order_rate_clear($pfId, $oid, $userId); continue; }
                    $r = zp_order_rate_save($pfId, $oid, $v, $reason, $userId);
                    if (!$r['ok']) throw new RuntimeException($r['error']);
                }
                db()->commit();
                $n = count($todo);
                $_SESSION['zp_msg'] = $n . ' rate' . ($n === 1 ? '' : 's') . ' saved for this order only. Wages already booked are unchanged.';
                redirect('production_order_rates.php?order=' . $pfId);
            } catch (Throwable $e) {
                if (db()->inTransaction()) db()->rollBack();
                $err = $e->getMessage() . ' Nothing was saved.';
            }
        }
    }
}
if (!empty($_SESSION['zp_msg'])) { $msg = $_SESSION['zp_msg']; unset($_SESSION['zp_msg']); }

$orders = zp_rate_orders();
$order  = null;
foreach ($orders as $o) if ((int)$o['id'] === $pfId) { $order = $o; break; }

/* EVERYTHING THIS ORDER CARRIES, BUILT ONCE. The browser does the rest —
   grouping, filters, search, the edit table and its live totals — so a
   click asks the server nothing. */
$allOps  = $order ? zp_ops_for_order($pfId) : [];
$amend   = $order ? zp_order_rate_map($pfId) : [];
$history = $order ? zp_rate_log_read(60, $pfId, array_column($allOps, 'id')) : zp_rate_log_read(60);

/* WHAT IS STILL TO MAKE, AND WHAT IT WOULD COST WITHOUT THE AMENDMENT.
   Per order line: pieces the plan needs minus pieces booked, priced at the
   rate the line would pay with no amendment (its size rate, else standard).
   The browser multiplies by the amended rate to show the difference. */
$IMP = [];   // op id => [still, baseCost, sizeRated]
$SEQ = [];   // op id => [part seq, stage position, op seq]
$PROD = [];  // op id => first product, in the order's own line order
if ($order) {
    $prog = zp_progress_map();
    foreach (zp_open_lines() as $l) {
        if ((int)$l['proforma_id'] !== $pfId) continue;
        $pid = (int)$l['product_id'];
        $szRates = zp_op_rate_map($pid); $szId = zp_line_size_id($l);
        foreach (zp_product_parts($pid) as $pi => $p) {
            $need = zp_pieces_needed($l, (int)$p['id']);
            if ($need === ZP_NO_SIZE) $need = 0.0;
            foreach (zp_part_ops((int)$p['id'], true) as $o) {
                $oid  = (int)$o['id'];
                $done = (float)($prog['op'][(int)$l['item_id']][(int)$p['id']][$oid] ?? 0);
                $still = max(0.0, $need - $done);
                $base = zp_rate_for($oid, (float)$o['rate'], [], $szRates, $szId);
                $IMP[$oid] ??= [0.0, 0.0, false];
                $IMP[$oid][0] += $still;
                $IMP[$oid][1] += $still * $base;
                if (abs($base - (float)$o['rate']) > 0.001) $IMP[$oid][2] = true;
                $SEQ[$oid] ??= [$pi, zp_stage_pos((string)$o['stage']) ?? 99, (int)$o['seq']];
                $PROD[$oid] ??= trim((string)$l['product_name']);
            }
        }
    }
}
$rows = array_map(function ($o) use ($amend, $IMP, $SEQ, $PROD) {
    $id = (int)$o['id'];
    return [
        'id' => $id, 'prod' => $PROD[$id] ?? ($o['products'][0] ?? 'Not on this order any more'),
        'prods' => $o['products'], 'part' => (string)$o['part'], 'op' => (string)$o['operation'], 'st' => (string)$o['stage'],
        'std' => round((float)$o['standard'], 2), 'rate' => array_key_exists($id, $amend) ? round((float)$amend[$id], 2) : null,
        'bq' => round((float)$o['booked_qty'], 2), 'ba' => round((float)$o['booked_amt'], 2),
        'still' => round($IMP[$id][0] ?? 0, 2), 'base' => round($IMP[$id][1] ?? 0, 2), 'sz' => (bool)($IMP[$id][2] ?? false),
        'on' => (bool)$o['on_order'], 'seq' => $SEQ[$id] ?? [99, 99, 0],
    ];
}, $allOps);

page_header('Order Rate Amendments');
$nAll = count($rows); $nAm = count($amend);
$nUse = count(array_filter($rows, fn($r) => $r['bq'] > 0 || $r['rate'] !== null));
?>
<link rel="stylesheet" href="assets/css/zf.css?v=4">
<style>
/* ORDER RATES, the SAP Fiori way (owner, 22 Nov 2026): an object page for one
   order — header with its figures, one table grouped by product, an edit mode
   that changes many rates under one reason, the history folded away. */
/* the wide table scrolls inside its card; it never makes the page wider than the screen */
.zf.or{min-width:0;max-width:100%}
.zf.or .zf-card,.zf.or .or-obj{min-width:0;max-width:100%}
.zf.or .zf-tw{max-width:100%;overflow-x:auto}
.zf.or .or-hd{display:flex;justify-content:space-between;align-items:flex-end;gap:12px;flex-wrap:wrap;margin:0 0 10px}
.zf.or .or-pk{position:relative;width:380px;max-width:100%}
.zf.or .or-pk .zf-in{width:100%;height:32px;padding-left:30px;background:#fff url("data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' width='14' height='14' fill='none' stroke='%23556b82' stroke-width='2'%3E%3Ccircle cx='6' cy='6' r='4.5'/%3E%3Cpath d='M9.5 9.5 13 13'/%3E%3C/svg%3E") no-repeat 9px 50%}
.zf.or .or-list{position:absolute;top:calc(100% + 3px);left:0;right:0;max-height:320px;overflow:auto;background:#fff;border:1px solid var(--line);border-radius:6px;box-shadow:0 10px 28px rgba(16,32,56,.18);z-index:30}
.zf.or .or-list a{display:flex;justify-content:space-between;gap:8px;padding:7px 10px;color:var(--ink);text-decoration:none;font-size:12.5px;border-bottom:1px solid var(--soft)}
.zf.or .or-list a:hover,.zf.or .or-list a.cur{background:#eaf2fc}
.zf.or .or-list a small{color:var(--mut);white-space:nowrap}
.zf.or .or-list .gh{padding:5px 10px;font-size:11px;font-weight:600;color:var(--mut);background:#f7f9fb}
.zf.or .or-obj{background:#fff;border:1px solid var(--line);border-radius:6px;padding:10px 12px;margin-bottom:10px}
.zf.or .or-obj h2{margin:0;font-size:17px;font-weight:600;color:var(--ink)}
.zf.or .or-obj .zf-sub{margin:1px 0 8px}
.zf.or .zf-kpis{margin-bottom:0}
.zf.or .zf-kpi{cursor:default}
.zf.or tr.or-g td{background:#f2f5f8;font-weight:600;color:var(--ink);cursor:pointer;border-bottom:1px solid var(--line)}
.zf.or tr.or-g td small{font-weight:500;color:var(--mut);margin-left:6px}
.zf.or tr.or-g .car{display:inline-block;width:12px;color:var(--mut);transition:transform .15s}
.zf.or tr.or-g.shut .car{transform:rotate(-90deg)}
.zf.or .zf-t tbody tr.or-r{cursor:default}
.zf.or .zf-t th{text-transform:none !important;letter-spacing:0 !important;font-size:12px !important}
.zf.or .zf-in.q{width:210px}
.zf.or .zf-t td{vertical-align:middle}
.zf.or .st{display:inline-block;font-size:11px;font-weight:600;padding:1px 7px;border-radius:3px;border:1px solid;white-space:nowrap}
.zf.or .or-in{width:84px;height:28px;text-align:right;border:1px solid #c9d3df;border-radius:3px;padding:0 7px;font:inherit;font-variant-numeric:tabular-nums}
.zf.or .or-in:focus{outline:none;border-color:var(--pri);box-shadow:0 0 0 2px rgba(10,110,209,.15)}
.zf.or tr.chg td{background:#fff8e1 !important}
.zf.or tr.chg .or-in{border-color:#e9a23b;background:#fffdf5}
.zf.or .up{color:#b42318}.zf.or .dn{color:#107e3e}.zf.or .mut{color:#9aa8b6}
.zf.or .tag{font-size:10.5px;color:#6a7b8c;border:1px solid #d9dee4;border-radius:3px;padding:0 4px;margin-left:4px;white-space:nowrap}
.zf.or .or-foot{position:sticky;bottom:0;z-index:5;display:flex;gap:8px;align-items:center;flex-wrap:wrap;padding:8px 10px;background:#fff;border-top:2px solid var(--pri);box-shadow:0 -6px 16px rgba(16,32,56,.08)}
.zf.or .or-foot b{color:var(--pri)}
.zf.or .or-foot .zf-in{flex:1;min-width:220px}
.zf.or details.zf-card>summary{list-style:none;cursor:pointer;padding:9px 12px;font-weight:600;display:flex;justify-content:space-between}
.zf.or details.zf-card>summary::-webkit-details-marker{display:none}
.zf.or details.zf-card>summary::after{content:'▾';color:var(--mut)}
.zf.or details[open].zf-card>summary::after{content:'▴'}
.zf.or .or-note{font-size:12px;color:var(--mut);padding:8px 10px;border-top:1px solid var(--soft)}
.zf.or .flash{padding:9px 12px;border-radius:5px;font-size:13px;font-weight:600;margin-bottom:10px}
.zf.or .flash.ok{background:#eef8f0;border:1px solid #c6e6cf;color:#256f3a}
.zf.or .flash.bad{background:#fdf0ef;border:1px solid #f3c6c2;color:#b42318}
.zf.or .flash.info{background:#fff7ec;border:1px solid #f0d3a8;color:#9a5710}
@media(max-width:760px){
  .zf.or .or-hd>div:last-child{width:100%}.zf.or .or-pk{flex:1;width:auto}
  .zf.or .zf-kpis{grid-template-columns:minmax(0,1fr) minmax(0,1fr);gap:6px}
  .zf.or .zf-kpi{padding:6px 9px}.zf.or .zf-kpi b{font-size:16px}
  .zf.or .or-obj h2{font-size:15px}
  .zf.or .zf-seg{flex-wrap:wrap}
}
</style>
<?php /* the module skin stays on, as on every production page; the Fiori look
         lives inside it in .zf */ ?>
<div class="zskin">
<div class="zf or">
  <div class="or-hd">
    <div><h1>Order rates</h1>
      <div class="zf-sub">One order paying a different rate for an operation, without moving any other order. Wages already booked never change.</div></div>
    <div style="display:flex;gap:8px;align-items:center">
      <div class="or-pk">
        <input class="zf-in" id="orQ" autocomplete="off" placeholder="Find an order — PI no. or customer…"
               value="<?= $order ? e($order['pi_no'] . ' — ' . ($order['customer_name'] ?: 'no customer')) : '' ?>">
        <div class="or-list" id="orList" hidden></div>
      </div>
      <a class="zf-btn" href="production_dashboard.php">Dashboard</a>
    </div>
  </div>

  <?php if ($msg): ?><div class="flash ok"><?= e($msg) ?></div><?php endif; ?>
  <?php if ($err): ?><div class="flash bad"><?= e($err) ?></div><?php endif; ?>
  <?php if (!$canEdit): ?><div class="flash info">You can read this page but not change it — moving a wage rate is an admin action.</div><?php endif; ?>

  <?php if (!$order): ?>
    <div class="zf-card"><div class="zf-none">
      <?= $orders ? 'Find an order above — type part of the PI number or the customer.' : 'No order is switched on for production yet, so there is nothing to amend.' ?>
    </div></div>
  <?php else: ?>
    <div class="or-obj">
      <h2><?= e($order['pi_no']) ?> · <?= e($order['customer_name'] ?: 'no customer') ?></h2>
      <div class="zf-sub">A rate set here moves this order only. Work booked after you save pays it; anything already booked keeps what it was paid.</div>
      <div class="zf-kpis" id="orKpi"></div>
    </div>

    <div class="zf-card">
      <div class="zf-bar">
        <span class="zf-seg" id="orSeg">
          <button type="button" data-f="use" class="on">In use <b><?= $nUse ?></b></button>
          <button type="button" data-f="am">Amended <b><?= $nAm ?></b></button>
          <button type="button" data-f="new">Not started <b><?= $nAll - $nUse ?></b></button>
          <button type="button" data-f="all">All <b><?= $nAll ?></b></button>
        </span>
        <input class="zf-in q" id="orS" placeholder="Search part, operation, stage…" autocomplete="off">
        <span class="zf-cnt" id="orCnt"></span>
        <?php if ($canEdit && $rows): ?><button type="button" class="zf-btn pri" id="orEditBtn">Edit rates</button><?php endif; ?>
      </div>
      <?php if (!$rows): ?>
        <div class="zf-none">This order's lines do not resolve to products with parts yet. Check the product names on <a href="product_master.php">Master Products</a>.</div>
      <?php else: ?>
      <div class="zf-tw" style="max-height:none">
        <table class="zf-t">
          <thead><tr>
            <th>Part · operation</th><th>Stage</th><th class="n">Standard</th><th class="n">This order pays</th><th class="n">Difference / pc</th>
            <th class="n">Already booked</th><th class="n">Still to make</th><th class="n" title="Still to make × (this order's rate − the rate it would pay without the amendment)">Extra cost</th>
            <th class="or-x" hidden></th>
          </tr></thead>
          <tbody id="orBody"></tbody>
        </table>
      </div>
      <div class="or-foot" id="orFoot" hidden>
        <span id="orChg"></span>
        <input class="zf-in" id="orWhy" maxlength="250" placeholder="Why does this order pay differently? (required — kept with the change)">
        <button type="button" class="zf-btn" id="orDiscard">Discard</button>
        <button type="button" class="zf-btn ok" id="orSave">Save</button>
      </div>
      <div class="or-note">Extra cost = pieces still to make × (this order's rate − the rate they would pay without the amendment). Already-booked wages are never re-priced.</div>
      <?php endif; ?>
    </div>

    <form method="post" id="orForm" hidden>
      <?= csrf_field() ?><input type="hidden" name="action" value="save_many">
      <input type="hidden" name="proforma_id" value="<?= $pfId ?>"><input type="hidden" name="reason" value="">
    </form>
  <?php endif; ?>

  <details class="zf-card"<?= $order ? '' : ' open' ?>>
    <summary><?= $order ? 'Rate history for ' . e($order['pi_no']) : 'Every rate that has moved' ?> (<?= count($history) ?>)</summary>
    <?php if (!$history): ?>
      <div class="zf-none"><?= $order ? 'No rate touching this order has changed yet.' : 'No rate has changed yet.' ?></div>
    <?php else: ?>
      <div class="zf-tw" style="max-height:420px"><table class="zf-t">
        <thead><tr><th>When</th><th>Part · operation</th><th>Scope</th><th class="n">From</th><th class="n">To</th><th>Reason</th></tr></thead>
        <tbody>
        <?php foreach ($history as $h): ?>
          <tr style="cursor:default">
            <td class="n" style="text-align:left"><?= e(date('j M Y H:i', strtotime($h['created_at']))) ?></td>
            <td><?= e($h['part_name'] ?: '—') ?><div class="s2"><?= e($h['operation_name'] ?: '—') ?></div></td>
            <td><?= $h['proforma_id'] ? '<span class="zf-pill p-draft">this order</span>' : '<span class="zf-pill p-grey">standard</span>' ?></td>
            <td class="n"><?= $h['old_rate'] === null ? '—' : number_format((float)$h['old_rate'], 2) ?></td>
            <td class="n"><b><?= number_format((float)$h['new_rate'], 2) ?></b></td>
            <td style="color:var(--mut)"><?= e($h['reason'] ?: '—') ?></td>
          </tr>
        <?php endforeach; ?>
        </tbody>
      </table></div>
    <?php endif; ?>
  </details>
</div>

<script>
(function(){
  var ORDERS = <?= json_encode(array_map(fn($o) => ['id' => (int)$o['id'], 'no' => (string)$o['pi_no'], 'c' => (string)($o['customer_name'] ?? ''),
                 'w' => (int)$o['worked_ops'], 'a' => (int)$o['amend_count']], $orders), JSON_HEX_TAG | JSON_HEX_AMP) ?>;
  var ROWS = <?= json_encode($rows, JSON_HEX_TAG | JSON_HEX_AMP) ?>;
  var CAN = <?= $canEdit ? 'true' : 'false' ?>;
  var $ = function(i){ return document.getElementById(i); };
  function esc(t){ return String(t == null ? '' : t).replace(/[&<>"]/g, function(c){ return {'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;'}[c]; }); }
  function n2(v){ return (Math.round(v*100)/100).toLocaleString('en-US', {minimumFractionDigits:2, maximumFractionDigits:2}); }
  function qn(v){ return (Math.round(v*100)/100).toLocaleString('en-US'); }
  function pk(v){ var a = Math.abs(v); return (v < 0 ? '−' : '') + 'PKR ' + a.toLocaleString('en-US', {maximumFractionDigits:0}); }

  /* ---- THE ORDER PICKER: type, the list narrows, Enter opens ---- */
  var q = $('orQ'), list = $('orList'), cur = 0, shown = [];
  function paintOrders(){
    var t = q.value.trim().toLowerCase().split(/\s+/).filter(Boolean);
    var hit = ORDERS.filter(function(o){ var h = (o.no + ' ' + o.c).toLowerCase(); return t.every(function(w){ return h.indexOf(w) >= 0; }); });
    var live = hit.filter(function(o){ return o.w || o.a; }), idle = hit.filter(function(o){ return !o.w && !o.a; });
    shown = live.concat(idle).slice(0, 60);
    if (cur >= shown.length) cur = 0;
    var h = '', k = 0;
    [['In production', live], ['Switched on, nothing made yet', idle]].forEach(function(g){
      var part = g[1].filter(function(o){ return shown.indexOf(o) >= 0; });
      if (!part.length) return;
      h += '<div class="gh">' + g[0] + '</div>';
      part.forEach(function(o){
        var bits = []; if (o.w) bits.push(o.w + ' worked'); if (o.a) bits.push(o.a + ' amended');
        h += '<a href="production_order_rates.php?order=' + o.id + '"' + (k === cur ? ' class="cur"' : '') + '><span><b>' + esc(o.no) + '</b> · ' + esc(o.c || 'no customer') + '</span><small>' + bits.join(' · ') + '</small></a>';
        k++;
      });
    });
    list.innerHTML = h || '<div class="gh">No order matches.</div>';
    list.hidden = false;
  }
  q.addEventListener('focus', function(){ q.select(); paintOrders(); });
  q.addEventListener('input', function(){ cur = 0; paintOrders(); });
  q.addEventListener('keydown', function(e){
    if (e.key === 'ArrowDown') { e.preventDefault(); cur = Math.min(cur + 1, shown.length - 1); paintOrders(); }
    else if (e.key === 'ArrowUp') { e.preventDefault(); cur = Math.max(cur - 1, 0); paintOrders(); }
    else if (e.key === 'Enter') { e.preventDefault(); if (shown[cur]) location.href = 'production_order_rates.php?order=' + shown[cur].id; }
    else if (e.key === 'Escape') { list.hidden = true; q.blur(); }
  });
  q.addEventListener('blur', function(){ setTimeout(function(){ list.hidden = true; }, 150); });

  if (!$('orBody')) return;

  /* ---- STAGE COLOURS — the same rule as Daily Production Entry ---- */
  function stStyle(st){
    var PAL = [['#f1ecfb','#5a3fa8','#d6cbf2'],['#e3f5f7','#0e6170','#b3e0e6'],['#fbe9f1','#9d174d','#f2c4d8'],['#eef2f6','#33465f','#cfd8e3'],['#fdeee8','#9a3412','#f6cdb9']];
    var l = String(st || '').toLowerCase();
    if (l.indexOf('cut') >= 0) return ['#fff4e2','#9a5710','#f1cf97'];
    if (/stitch|sew|singer|overlock/.test(l)) return ['#e8f1fc','#0a4f96','#b9d3f1'];
    if (l.indexOf('pack') >= 0) return ['#e8f6ec','#1f6b35','#bfe3ca'];
    var h = 0; for (var i = 0; i < l.length; i++) h = (h*31 + l.charCodeAt(i)) >>> 0;
    return PAL[h % PAL.length];
  }

  /* ---- grouped by product, in the order's own order: part, stage, operation ---- */
  var groups = [], gi = {};
  ROWS.slice().sort(function(a, b){
    return (a.seq[0] - b.seq[0]) || (a.seq[1] - b.seq[1]) || (a.seq[2] - b.seq[2]) || (a.id - b.id);
  }).forEach(function(r){
    if (gi[r.prod] == null) { gi[r.prod] = groups.length; groups.push({name: r.prod, rows: []}); }
    groups[gi[r.prod]].rows.push(r);
  });
  /* products in the order the order lists them */
  var prodOrder = []; ROWS.forEach(function(r){ if (prodOrder.indexOf(r.prod) < 0) prodOrder.push(r.prod); });

  var filt = 'use', edit = false, shut = {}, pend = {};   // pend[id] = typed value (string)
  if (!ROWS.some(function(r){ return r.bq > 0 || r.rate !== null; })) filt = 'all';   // nothing worked yet: show everything
  [].forEach.call($('orSeg').querySelectorAll('button'), function(b){ b.classList.toggle('on', b.dataset.f === filt); });

  function want(r){
    if (filt === 'use') return r.bq > 0 || r.rate !== null || (edit && pend[r.id] != null);
    if (filt === 'am')  return r.rate !== null;
    if (filt === 'new') return r.bq <= 0;
    return true;
  }
  /* the rate this row WILL pay: typed value in edit mode, else the saved one */
  function eff(r){
    if (edit && pend[r.id] != null) { var s = String(pend[r.id]).trim(); if (s === '' || s === 'std') return null; var v = parseFloat(s.replace(/,/g, '')); return isNaN(v) ? r.rate : Math.round(v*100)/100; }
    return r.rate;
  }
  function changed(r){
    if (pend[r.id] == null) return false;
    var e = eff(r);
    if (e === null) return r.rate !== null;
    if (r.rate === null) return !(Math.abs(e - r.std) < 0.001 && !r.sz);   // typing the standard on a plain row is no change
    return Math.abs(e - r.rate) > 0.001;
  }
  function extra(r){ var e = eff(r); return e === null ? 0 : e * r.still - r.base; }

  function paint(){
    var t = $('orS').value.trim().toLowerCase().split(/\s+/).filter(Boolean), n = 0, h = '';
    prodOrder.forEach(function(pn){
      var g = groups[gi[pn]]; if (!g) return;
      var rs = g.rows.filter(function(r){ var hay = (r.prod + ' ' + r.part + ' ' + r.op + ' ' + r.st).toLowerCase(); return want(r) && t.every(function(w){ return hay.indexOf(w) >= 0; }); });
      if (!rs.length) return;
      var am = g.rows.filter(function(r){ return eff(r) !== null; }).length;
      h += '<tr class="or-g' + (shut[pn] ? ' shut' : '') + '" data-g="' + esc(pn) + '"><td colspan="9"><span class="car">▾</span> ' + esc(pn)
         + '<small>' + rs.length + ' operation' + (rs.length === 1 ? '' : 's') + (am ? ' · ' + am + ' amended' : '') + '</small></td></tr>';
      if (shut[pn]) return;
      rs.forEach(function(r){
        n++;
        var c = stStyle(r.st), e = eff(r), d = e === null ? null : e - r.std, x = extra(r), ch = edit && changed(r);
        h += '<tr class="or-r' + (ch ? ' chg' : '') + '" style="box-shadow:inset 4px 0 0 ' + c[1] + '">'
          + '<td>' + esc(r.part) + '<div class="s2">' + esc(r.op) + (r.on ? '' : ' <span class="tag">no longer on this order</span>') + '</div></td>'
          + '<td><span class="st" style="background:' + c[0] + ';color:' + c[1] + ';border-color:' + c[2] + '">' + esc(r.st) + '</span></td>'
          + '<td class="n">' + n2(r.std) + (r.sz ? '<span class="tag" title="Some sizes have their own rate">by size</span>' : '') + '</td>'
          + '<td class="n">' + (edit
              ? '<input class="or-in" data-id="' + r.id + '" inputmode="decimal" value="' + esc(pend[r.id] != null ? pend[r.id] : (r.rate === null ? '' : r.rate.toFixed(2))) + '" placeholder="' + n2(r.std) + '">'
              : (e === null ? '<span class="mut">standard</span>' : '<b>' + n2(e) + '</b> <span class="zf-pill p-draft">amended</span>')) + '</td>'
          + '<td class="n">' + (ch && e === null ? '<span class="dn">→ standard</span>' : d === null || Math.abs(d) < 0.001 ? '<span class="mut">—</span>' : '<span class="' + (d > 0 ? 'up' : 'dn') + '">' + (d > 0 ? '+' : '−') + n2(Math.abs(d)) + (d > 0 ? ' ▲' : ' ▼') + '</span>') + '</td>'
          + '<td class="n">' + (r.bq > 0 ? qn(r.bq) + ' pcs<div class="s2">PKR ' + n2(r.ba) + '</div>' : '<span class="mut">not started</span>') + '</td>'
          + '<td class="n">' + (r.still > 0 ? qn(r.still) : '<span class="mut">0</span>') + '</td>'
          + '<td class="n">' + (Math.abs(x) < 0.5 ? '<span class="mut">—</span>' : '<b class="' + (x > 0 ? 'up' : 'dn') + '">' + pk(x) + '</b>') + '</td>'
          + '<td class="or-x"' + (edit ? '' : ' hidden') + '>' + (edit && (r.rate !== null || pend[r.id] != null) ? '<button type="button" class="zf-btn sm" data-reset="' + r.id + '" title="Back to the standard rate">Reset</button>' : '') + '</td>'
          + '</tr>';
      });
    });
    $('orBody').innerHTML = h || '<tr><td colspan="9"><div class="zf-none">' + (filt === 'use' ? 'Nothing has been worked or amended on this order yet — open <b>All</b> to set a rate before the work starts.' : 'Nothing matches.') + '</div></td></tr>';
    $('orCnt').textContent = n + ' of ' + ROWS.length;
    [].forEach.call(document.querySelectorAll('th.or-x'), function(th){ th.hidden = !edit; });
    kpis(); foot();
  }

  function kpis(){
    var am = 0, bq = 0, ba = 0, x = 0, st = 0;
    ROWS.forEach(function(r){ if (eff(r) !== null) am++; bq += r.bq; ba += r.ba; x += extra(r); st += r.still; });
    var cards = [
      ['k-blue',  'Operations', ROWS.length, ROWS.filter(function(r){ return r.bq > 0; }).length + ' worked on this order'],
      ['k-amber', 'Amended', am, am ? 'this order pays its own rate' : 'all on standard'],
      ['k-green', 'Already booked', 'PKR ' + n2(ba), qn(bq) + ' pcs — never re-priced'],
      [x > 0.5 ? 'k-red' : 'k-grey', 'Extra on what is left', Math.abs(x) < 0.5 ? 'PKR 0' : (x > 0 ? '+' : '') + pk(x), qn(st) + ' pcs still to make' + (edit ? ' (with your changes)' : '')]
    ];
    $('orKpi').innerHTML = cards.map(function(c){ return '<div class="zf-kpi ' + c[0] + '"><span>' + c[1] + '</span><b>' + c[2] + '</b><small>' + c[3] + '</small></div>'; }).join('');
  }
  function nChanged(){ return ROWS.filter(changed).length; }
  function foot(){
    var f = $('orFoot'); if (!f) return;
    f.hidden = !edit;
    var n = nChanged();
    $('orChg').innerHTML = n ? '<b>' + n + '</b> change' + (n === 1 ? '' : 's') : 'Type the rate this order pays — blank or Reset = standard';
    $('orSave').textContent = n ? 'Save ' + n + ' change' + (n === 1 ? '' : 's') : 'Save';
    $('orSave').disabled = !n;
  }

  $('orSeg').addEventListener('click', function(e){
    var b = e.target.closest('button'); if (!b) return;
    filt = b.dataset.f;
    [].forEach.call(this.querySelectorAll('button'), function(x){ x.classList.toggle('on', x === b); });
    paint();
  });
  $('orS').addEventListener('input', paint);
  $('orBody').addEventListener('click', function(e){
    var g = e.target.closest('tr.or-g');
    if (g) { var k = g.dataset.g; shut[k] = !shut[k]; paint(); return; }
    var rb = e.target.closest('[data-reset]');
    if (rb) { var id = +rb.dataset.reset, r = ROWS.filter(function(x){ return x.id === id; })[0]; if (r && r.rate === null) delete pend[id]; else pend[id] = ''; paint(); }
  });
  /* typing updates the row's figures without redrawing the box being typed in */
  $('orBody').addEventListener('input', function(e){
    var i = e.target.closest('.or-in'); if (!i) return;
    pend[+i.dataset.id] = i.value;
    var pos = i.selectionStart, id = i.dataset.id;
    paint();
    var again = document.querySelector('.or-in[data-id="' + id + '"]'); if (again) { again.focus(); try { again.setSelectionRange(pos, pos); } catch (x) {} }
  });
  $('orBody').addEventListener('keydown', function(e){
    var i = e.target.closest('.or-in'); if (!i || e.key !== 'Enter') return;
    e.preventDefault();
    var all = [].slice.call(document.querySelectorAll('.or-in')), k = all.indexOf(i);
    if (all[k + 1]) { all[k + 1].focus(); all[k + 1].select(); } else $('orWhy').focus();
  });

  if (CAN && $('orEditBtn')) {
    $('orEditBtn').addEventListener('click', function(){
      if (edit) return;
      edit = true; pend = {}; this.hidden = true;
      paint();
      var f = document.querySelector('.or-in'); if (f) { f.focus(); f.select(); }
    });
    $('orDiscard').addEventListener('click', function(){
      if (nChanged() && !confirm('Throw away the changes you typed?')) return;
      edit = false; pend = {}; $('orEditBtn').hidden = false; $('orWhy').value = ''; paint();
    });
    $('orSave').addEventListener('click', function(){
      var ch = ROWS.filter(changed);
      if (!ch.length) return;
      var why = $('orWhy').value.trim();
      if (!why) { $('orWhy').focus(); $('orWhy').placeholder = 'A reason is required — e.g. double-stitched hem in the contract'; $('orWhy').style.borderColor = '#b42318'; return; }
      var bad = ch.filter(function(r){ var s = String(pend[r.id]).trim(); return s !== '' && s !== 'std' && (isNaN(parseFloat(s.replace(/,/g, ''))) || parseFloat(s.replace(/,/g, '')) < 0); });
      if (bad.length) { alert('"' + pend[bad[0].id] + '" is not a rate.'); return; }
      var f = $('orForm');
      f.reason.value = why;
      ch.forEach(function(r){
        var e = eff(r);
        [['op[]', r.id], ['rate[]', e === null ? 'std' : e.toFixed(2)]].forEach(function(kv){
          var h = document.createElement('input'); h.type = 'hidden'; h.name = kv[0]; h.value = kv[1]; f.appendChild(h);
        });
      });
      window.onbeforeunload = null;
      f.submit();
    });
    window.onbeforeunload = function(){ return edit && nChanged() ? 'You have unsaved rate changes.' : undefined; };
  }
  paint();
})();
</script>
</div><?php /* closes .zskin */ ?>
<?php page_footer(); ?>
