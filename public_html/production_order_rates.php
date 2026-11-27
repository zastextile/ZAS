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

/* SAVE WITHOUT A RELOAD (owner, 26 Nov 2026): the page posts every change of
   one session — order rates, line rates, skips, added operations — and gets the
   whole order back to redraw. One request; nothing else is asked for. */
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'save_json') {
    verify_csrf();
    header('Content-Type: application/json; charset=utf-8');
    if (!$canEdit) { echo json_encode(['ok' => false, 'error' => 'Only an admin may change this order.']); exit; }
    $ch = json_decode((string)($_POST['changes'] ?? ''), true);
    $out = is_array($ch) ? zp_order_sheet_save((int)($_POST['proforma_id'] ?? 0), $ch, (string)($_POST['reason'] ?? ''), $userId)
                         : ['ok' => false, 'error' => 'Nothing readable was sent.'];
    if ($out['ok'] ?? false) $out['history'] = zp_rate_log_read(60, (int)$_POST['proforma_id'], array_column($out['ops'], 'id'));
    echo json_encode($out, JSON_HEX_TAG | JSON_HEX_AMP);
    exit;
}

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

/* EVERYTHING THIS ORDER CARRIES, BUILT ONCE (zp_order_sheet). The browser does
   the rest — both views, filters, search, show/hide, live totals — so a click
   asks the server nothing. */
$SHEET   = $order ? zp_order_sheet($pfId) : null;
$allOps  = $SHEET && $SHEET['ok'] ? $SHEET['ops'] : [];
$history = $order ? zp_rate_log_read(60, $pfId, array_column($allOps, 'id')) : zp_rate_log_read(60);
$rows    = $allOps;

page_header('Order Rate Amendments');
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
<style>
/* 26 Nov 2026: two views, show/hide, skip, add, save without reload */
.zf.or .or-views{display:inline-flex;border:1px solid var(--line);border-radius:5px;overflow:hidden}
.zf.or .or-views button{border:0;background:#fff;padding:5px 12px;font:inherit;font-size:12.5px;font-weight:600;color:var(--mut);cursor:pointer}
.zf.or .or-views button.on{background:var(--pri);color:#fff}
.zf.or tr.or-l td{background:#eef3f9;cursor:pointer;border-bottom:1px solid var(--line)}
.zf.or tr.or-l td b{color:var(--ink)}
.zf.or tr.or-l .lm{color:var(--mut);font-size:12px;margin-left:8px}
.zf.or tr.or-l .lc{float:right;font-size:12px;color:var(--ink)}
.zf.or tr.or-l .lc b{font-size:13px}
.zf.or tr.skip td{opacity:.55}.zf.or tr.skip td .sk{opacity:1}
.zf.or .pill-x{display:inline-block;font-size:10.5px;font-weight:700;border-radius:3px;padding:0 5px;margin-left:5px}
.zf.or .pill-x.new{background:#e8f1fc;color:#0a4f96}.zf.or .pill-x.ex{background:#f1ecfb;color:#5a3fa8}.zf.or .pill-x.sk{background:#fdf0ef;color:#b42318}.zf.or .pill-x.ln{background:#fff4e2;color:#9a5710}
.zf.or .or-add{display:flex;gap:6px;flex-wrap:wrap;align-items:center;padding:8px 10px;background:#f7f9fb;border-bottom:1px solid var(--line)}
.zf.or .or-add select,.zf.or .or-add input{width:auto !important;flex:0 0 auto;height:28px;border:1px solid #c9d3df;border-radius:3px;padding:0 6px;font:inherit;font-size:12.5px;display:inline-block}
.zf.or .or-add select{max-width:200px}
.zf.or .or-add input.nm{width:220px !important}.zf.or .or-add input.rt{width:80px !important;text-align:right}
/* set / pack work (26 Nov 2026) */
.zf.or tr.or-sh td{background:#f4f7f2;color:#1f6b35;font-weight:700;font-size:12.5px;padding:5px 10px;border-top:1px solid #d5e6d9}
.zf.or tr.or-sh small{font-weight:400;color:var(--mut);margin-left:8px}
.zf.or .pill-x.st{background:#e8f6ec;color:#1f6b35}
.zf.or .pill-x.lq{background:#fde8f3;color:#9d174d}
.zf.or .lqe{display:flex;gap:5px;align-items:center;margin-top:3px;font-size:11.5px;color:#6a7b8c}
.zf.or .lqe .or-in{width:72px;height:24px}
.zf.or .or-add input.qt{width:92px !important;text-align:right}
.zf.or .ld{font-weight:400;color:#4a5a6a;margin-left:8px;font-size:12.5px;font-style:italic}
.zf.or .sp-hint{font-size:12px;color:#4a5a6a;margin:2px 0 6px}
.zf.or .or-sadd .tk{display:flex;gap:4px 10px;flex-wrap:wrap;align-items:center;font-size:12px;flex-basis:100%}
.zf.or .or-sadd .tk label{display:inline-flex;gap:4px;align-items:center;white-space:nowrap}
.zf.or .or-sadd .tk input{width:auto !important;height:auto}
/* split rate (26 Nov 2026) */
.zf.or .pill-x.tr{background:#e3f5f7;color:#0e6170}
.zf.or .sp-btn{height:24px;padding:0 8px;font-size:11.5px;border:1px solid #b3e0e6;background:#f4fbfc;color:#0e6170;border-radius:3px;cursor:pointer;white-space:nowrap}
.zf.or .sp-btn.on{background:#0e6170;color:#fff;border-color:#0e6170}
.zf.or tr.or-sp>td{padding:0 !important;background:#f7fbfc;border-bottom:1px solid #b3e0e6}
.zf.or .sp{padding:8px 12px 10px 18px;font-size:12.5px;border-left:4px solid #0e6170}
.zf.or .sp-top{display:flex;gap:14px;flex-wrap:wrap;align-items:baseline;margin-bottom:6px}
.zf.or .sp-top b{font-variant-numeric:tabular-nums}
.zf.or .sp-bar{display:flex;height:12px;border-radius:3px;overflow:hidden;background:#e6ebf0;margin:4px 0 8px;max-width:620px}
.zf.or .sp-seg{position:relative;border-right:2px solid #fff;background:#d5e7ea}
.zf.or .sp-seg:last-child{border-right:0}
.zf.or .sp-seg i{position:absolute;left:0;top:0;bottom:0;background:#0e6170}
.zf.or .sp-seg:nth-child(2n) i{background:#2a9aa8}
.zf.or table.sp-t{border-collapse:collapse;max-width:620px;width:100%}
.zf.or table.sp-t td,.zf.or table.sp-t th{padding:3px 8px;border-bottom:1px solid #e3ecef;text-align:right;font-variant-numeric:tabular-nums;font-size:12px;background:transparent}
.zf.or table.sp-t th{color:var(--mut);font-weight:600}
.zf.or table.sp-t td:first-child,.zf.or table.sp-t th:first-child{text-align:left}
.zf.or table.sp-t tr.now td{font-weight:700;color:#0e6170}
.zf.or .sp input{width:80px;height:26px;text-align:right;border:1px solid #c9d3df;border-radius:3px;padding:0 6px;font:inherit}
.zf.or .sp-act{display:flex;gap:6px;margin-top:6px;flex-wrap:wrap;align-items:center}
.zf.or .sp .mut{color:var(--mut)}
.zf.or .or-toast{position:fixed;left:50%;bottom:24px;transform:translateX(-50%);background:#1d2d3e;color:#fff;padding:10px 16px;border-radius:6px;font-weight:600;font-size:13px;z-index:99;box-shadow:0 8px 24px rgba(0,0,0,.25)}
.zf.or .or-toast.bad{background:#b42318}
.zf.or label.skc{display:inline-flex;gap:4px;align-items:center;font-size:12px;color:var(--mut);cursor:pointer;white-space:nowrap}
</style>
<?php /* the module skin stays on, as on every production page; the Fiori look lives inside it in .zf */ ?>
<div class="zskin">
<div class="zf or">
  <div class="or-hd">
    <div><h1>Order rates</h1>
      <div class="zf-sub">What this order pays, by operation or by PI line. Skip what it does not need, add what it does. Wages already booked never change.</div></div>
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
  <?php elseif (!$SHEET['ok'] || !$SHEET['lines']): ?>
    <div class="zf-card"><div class="zf-none">This order's lines do not resolve to products with parts yet. Check the product names on <a href="product_master.php">Master Products</a>.</div></div>
  <?php else: ?>
    <div class="or-obj">
      <h2><?= e($order['pi_no']) ?> · <?= e($order['customer_name'] ?: 'no customer') ?></h2>
      <div class="zf-sub">Most specific wins: this line → this order → the size → the standard. Work booked after you save pays the new rate.</div>
      <div class="zf-kpis" id="orKpi"></div>
    </div>

    <div class="zf-card">
      <div class="zf-bar">
        <span class="or-views" id="orView"><button type="button" data-v="op" class="on">By operation</button><button type="button" data-v="line">By PI line</button></span>
        <input class="zf-in q" id="orS" placeholder="Search part, operation, stage, product…" autocomplete="off">
        <button type="button" class="zf-btn sm" id="orOpen">Show all</button>
        <button type="button" class="zf-btn sm" id="orShut">Hide all</button>
        <span class="zf-cnt" id="orCnt"></span>
        <?php if ($canEdit): ?><button type="button" class="zf-btn pri" id="orEditBtn">Edit</button><?php endif; ?>
      </div>
      <div class="zf-tw" style="max-height:none">
        <table class="zf-t"><thead id="orHead"></thead><tbody id="orBody"></tbody></table>
      </div>
      <div class="or-foot" id="orFoot" hidden>
        <span id="orChg"></span>
        <input class="zf-in" id="orWhy" maxlength="250" placeholder="Why does this order change? (required — kept with every change)">
        <button type="button" class="zf-btn" id="orDiscard">Discard</button>
        <button type="button" class="zf-btn ok" id="orSave">Save</button>
      </div>
      <div class="or-note">A skipped operation leaves Production Entry and the cost for that order or line; work already booked on it stays paid. An added operation is for this order only — it never changes the part or any other order.</div>
    </div>
  <?php endif; ?>

  <details class="zf-card"<?= $order ? '' : ' open' ?>>
    <summary><span><?= $order ? 'History for ' . e($order['pi_no']) : 'Every rate that has moved' ?> (<span id="orHn"><?= count($history) ?></span>)</span></summary>
    <div class="zf-tw" style="max-height:420px"><table class="zf-t">
      <thead><tr><th>When</th><th>Part · operation</th><th>Scope</th><th class="n">From</th><th class="n">To</th><th>Reason</th></tr></thead>
      <tbody id="orHist">
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
      <?php if (!$history): ?><tr><td colspan="6"><div class="zf-none">Nothing has changed yet.</div></td></tr><?php endif; ?>
      </tbody>
    </table></div>
  </details>
  <form id="orTok" hidden><?= csrf_field() ?></form>
</div>

<script>
(function(){
  var ORDERS = <?= json_encode(array_map(fn($o) => ['id' => (int)$o['id'], 'no' => (string)$o['pi_no'], 'c' => (string)($o['customer_name'] ?? ''),
                 'w' => (int)$o['worked_ops'], 'a' => (int)$o['amend_count']], $orders), JSON_HEX_TAG | JSON_HEX_AMP) ?>;
  var D = <?= json_encode($SHEET && $SHEET['ok'] ? $SHEET : null, JSON_HEX_TAG | JSON_HEX_AMP) ?>;
  var PF = <?= (int)$pfId ?>, CAN = <?= $canEdit ? 'true' : 'false' ?>;
  var $ = function(i){ return document.getElementById(i); };
  function esc(t){ return String(t == null ? '' : t).replace(/[&<>"]/g, function(c){ return {'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;'}[c]; }); }
  function n2(v){ return (Math.round(v*100)/100).toLocaleString('en-US', {minimumFractionDigits:2, maximumFractionDigits:2}); }
  function qn(v){ return (Math.round(v*100)/100).toLocaleString('en-US'); }
  function pk(v){ return 'PKR ' + Math.round(v).toLocaleString('en-US'); }
  function num(s){ s = String(s == null ? '' : s).replace(/,/g, '').trim(); if (s === '') return null; var v = parseFloat(s); return isNaN(v) || v < 0 ? NaN : Math.round(v*100)/100; }

  /* ---- THE ORDER PICKER: type, the list narrows, Enter opens ---- */
  var q = $('orQ'), list = $('orList'), cur = 0, shown = [];
  function paintOrders(){
    var t = q.value.trim().toLowerCase().split(/\s+/).filter(Boolean);
    var hit = ORDERS.filter(function(o){ var h = (o.no + ' ' + o.c).toLowerCase(); return t.every(function(w){ return h.indexOf(w) >= 0; }); });
    var live = hit.filter(function(o){ return o.w || o.a; }), idle = hit.filter(function(o){ return !o.w && !o.a; });
    shown = live.concat(idle).slice(0, 60); if (cur >= shown.length) cur = 0;
    var h = '', k = 0;
    [['In production', live], ['Switched on, nothing made yet', idle]].forEach(function(g){
      var part = g[1].filter(function(o){ return shown.indexOf(o) >= 0; }); if (!part.length) return;
      h += '<div class="gh">' + g[0] + '</div>';
      part.forEach(function(o){ var bits = []; if (o.w) bits.push(o.w + ' worked'); if (o.a) bits.push(o.a + ' amended');
        h += '<a href="production_order_rates.php?order=' + o.id + '"' + (k === cur ? ' class="cur"' : '') + '><span><b>' + esc(o.no) + '</b> · ' + esc(o.c || 'no customer') + '</span><small>' + bits.join(' · ') + '</small></a>'; k++; });
    });
    list.innerHTML = h || '<div class="gh">No order matches.</div>'; list.hidden = false;
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
  if (!D || !$('orBody')) return;

  function stStyle(st){
    var PAL = [['#f1ecfb','#5a3fa8','#d6cbf2'],['#e3f5f7','#0e6170','#b3e0e6'],['#fbe9f1','#9d174d','#f2c4d8'],['#eef2f6','#33465f','#cfd8e3'],['#fdeee8','#9a3412','#f6cdb9']];
    var l = String(st || '').toLowerCase();
    if (l.indexOf('cut') >= 0) return ['#fff4e2','#9a5710','#f1cf97'];
    if (/stitch|sew|singer|overlock/.test(l)) return ['#e8f1fc','#0a4f96','#b9d3f1'];
    if (l.indexOf('pack') >= 0) return ['#e8f6ec','#1f6b35','#bfe3ca'];
    var h = 0; for (var i = 0; i < l.length; i++) h = (h*31 + l.charCodeAt(i)) >>> 0; return PAL[h % PAL.length];
  }
  function stPill(st){ var c = stStyle(st); return '<span class="st" style="background:' + c[0] + ';color:' + c[1] + ';border-color:' + c[2] + '">' + esc(st) + '</span>'; }

  /* ---- state: the saved order (D) and what has been typed (P) ---- */
  var view = 'op', edit = false, open = {}, P, OP;
  function reset(){ P = { orate: {}, lrate: {}, oskip: {}, lskip: {}, add: [], remove: {}, tiers: {}, sets: {}, sadd: [], plim: {} }; }
  function index(){ OP = {}; D.ops.forEach(function(o){ OP[o.id] = o; }); }
  reset(); index();
  D.lines.forEach(function(l){ open['l' + l.item] = false; });

  function oRate(o){ if (P.orate[o.id] != null) { var v = num(P.orate[o.id]); return v === null ? null : (isNaN(v) ? o.orate : v); } return o.orate; }
  function oSkip(o){ return P.oskip[o.id] != null ? P.oskip[o.id] : o.oskip; }
  function key(l, r){ return l.item + ':' + r.id; }
  function lRate(l, r){ var k = key(l, r); if (P.lrate[k] != null) { var v = num(P.lrate[k]); return v === null ? null : (isNaN(v) ? r.lrate : v); } return r.lrate; }
  function lSkip(l, r){ var k = key(l, r); return P.lskip[k] != null ? P.lskip[k] : r.lskip; }
  function gone(o){ return !!P.remove[o.id]; }
  /* A LIMITED QUANTITY (27 Nov 2026): an added job for a few pieces only */
  function oLim(o){ if (P.plim[o.id] != null) { var v = num(P.plim[o.id]); return v === null ? null : (isNaN(v) || v <= 0 ? o.lim : v); } return o.lim; }
  function rNeed(r){ var L = OP[r.id] ? oLim(OP[r.id]) : null; return L != null ? Math.min(L, r.need) : r.need; }
  function sLim(w){ var c = P.sets[w.key]; if (c && c.lim != null) { var v = num(c.lim); return v === null ? null : (isNaN(v) || v <= 0 ? w.lim : v); } return w.lim; }
  function sCap(w, l){ var L = sLim(w); return L != null ? Math.min(L, l.qty) : l.qty; }
  function limPill(L, unit){ return L != null ? '<span class="pill-x lq">' + qn(L) + ' ' + unit + ' only</span>' : ''; }
  function effLine(l, r){ var o = OP[r.id], x = lRate(l, r); if (x != null) return x; var y = oRate(o); return y != null ? y : r.base; }
  function skipped(l, r){ return oSkip(OP[r.id]) || lSkip(l, r) || gone(OP[r.id]); }

  /* ---- SET / PACK WORK: per set, per line ---- */
  function sRate(w){ var c = P.sets[w.key]; if (c && c.rate != null) { var v = num(c.rate); return v === null || isNaN(v) ? w.rate : v; } return w.rate; }
  function sOn(w){ var c = P.sets[w.key]; return c && c.skip != null ? !c.skip : w.on; }
  function sChanged(w){ var c = P.sets[w.key]; if (!c) return 0; var n = 0;
    if (c.rate != null) { var v = num(c.rate); if (v === null || isNaN(v) || Math.abs(v - w.rate) > 0.001) n++; }
    if (c.skip != null && c.skip === w.on) n++;
    if (c.lim != null) { var q = num(c.lim); if (isNaN(q) || (q === null ? w.lim !== null : (w.lim === null || Math.abs(q - w.lim) > 0.001))) n++; }
    return n; }
  function allSets(fn){ D.lines.forEach(function(l){ (l.sets || []).forEach(function(w){ fn(l, w); }); }); }
  function sname(n){ return String(n).trim().toLowerCase(); }

  /* ---- A SPLIT RATE: the first N pieces at one rate, the next at another ----
     "o:op" counts the whole order, "l:item:op" one line. Same arithmetic as
     zp_tier_split() on the server, so the figures here are what will be paid. */
  function tsplit(T, done, qty){
    var out = [], from = 0, left = qty, at = done;
    for (var i = 0; i < T.length; i++) {
      var last = i === T.length - 1 || T[i][0] == null, to = last ? Infinity : from + T[i][0];
      if (left > 0.0001 && at < to) { var take = last ? left : Math.min(left, to - at); out.push([take, T[i][1]]); left -= take; at += take; }
      if (last) break; from = to;
    }
    return out;
  }
  function tcost(T, done, qty){ return tsplit(T, done, qty).reduce(function(s, p){ return s + p[0] * p[1]; }, 0); }
  function tnow(T, done){ var p = tsplit(T, done, 1); return p.length ? p[0][1] : T[T.length - 1][1]; }
  function saved(k){ var p = k.split(':'), t = null;
    if (p[0] === 'o') t = OP[p[1]] && OP[p[1]].otier;
    else D.lines.forEach(function(l){ if (l.item === +p[1]) l.parts.forEach(function(pp){ pp.ops.forEach(function(r){ if (r.id === +p[2]) t = r.ltier; }); }); });
    return t ? t.map(function(x){ return [x.qty, x.rate]; }) : null; }
  /* the steps in force for a key: typed (if they all read) else saved; null = no split */
  function tiers(k){
    if (P.tiers[k] == null) return saved(k);
    if (!P.tiers[k].length) return null;
    var T = [], ok = true;
    P.tiers[k].forEach(function(x, i){ var last = i === P.tiers[k].length - 1, q = last ? null : num(x.qty), r = num(x.rate);
      if (r === null || isNaN(r) || (!last && (q === null || isNaN(q) || q <= 0))) ok = false; T.push([q, r]); });
    return ok ? T : saved(k);
  }
  function tBad(k){ return P.tiers[k] != null && P.tiers[k].length && tiers(k) === saved(k); }
  function tDiff(k){ var a = P.tiers[k] == null ? saved(k) : (P.tiers[k].length ? (tBad(k) ? 'bad' : tiers(k)) : null); return JSON.stringify(a) !== JSON.stringify(saved(k)); }
  /* the whole order's count on one operation */
  function oNeed(id){ var n = 0, d = 0; D.lines.forEach(function(l){ l.parts.forEach(function(p){ p.ops.forEach(function(r){ if (r.id === id) { n += r.need; d += r.done; } }); }); }); return [n, d]; }
  /* the split that prices this line's operation, and how it is counted */
  function lineTier(l, r){
    var T = tiers('l:' + l.item + ':' + r.id); if (T) return { k: 'l:' + l.item + ':' + r.id, T: T, need: r.need, done: r.done };
    if (lRate(l, r) != null) return null;
    T = tiers('o:' + r.id); if (!T) return null;
    var nd = oNeed(r.id); return { k: 'o:' + r.id, T: T, need: nd[0], done: nd[1], order: true };
  }
  function sumTxt(T){ return T.map(function(x){ return n2(x[1]); }).join(' → '); }
  /* what a line costs, at the rates it will pay, and what is still to make */
  function lineCost(l){
    var t = 0, still = 0, own = 0, sk = 0;
    l.parts.forEach(function(p){ p.ops.forEach(function(r){
      if (lRate(l, r) != null) own++;
      if (skipped(l, r)) { sk++; return; }
      var tr = lineTier(l, r);
      if (tr && tr.need > 0 && OP[r.id] && oLim(OP[r.id]) == null) {   // a split: priced step by step (an order split shares out its average)
        var left = Math.max(0, r.need - r.done);
        if (!tr.order) { t += tcost(tr.T, 0, r.need); still += tcost(tr.T, r.done, left); }
        else { t += r.need * tcost(tr.T, 0, tr.need) / tr.need; var ol = Math.max(0, tr.need - tr.done); still += ol > 0 ? left * tcost(tr.T, tr.done, ol) / ol : 0; }
        return;
      }
      var e = effLine(l, r), nd = rNeed(r); t += nd * e; still += Math.max(0, nd - r.done) * e; }); });
    (l.sets || []).forEach(function(w){ if (!sOn(w)) { sk++; return; } var rt = sRate(w); if (P.sets[w.key] && P.sets[w.key].rate != null || (w.std != null && Math.abs(w.rate - w.std) > 0.001)) own++;
      var cp = sCap(w, l); t += cp * rt; still += Math.max(0, cp - w.done) * rt; });
    P.sadd.forEach(function(a){ if (a.items.indexOf(l.item) >= 0) { var cp = a.lim != null ? Math.min(a.lim, l.qty) : l.qty; t += cp * a.rate; still += cp * a.rate; } });
    P.add.forEach(function(a){ if ((a.item === 0 || a.item === l.item) && l.parts.some(function(p){ return p.part_id === a.part; })) {
      var need = 0; l.parts.forEach(function(p){ if (p.part_id === a.part && p.ops[0]) need = p.ops[0].need; }); if (a.lim != null) need = Math.min(a.lim, need); t += need * a.rate; still += need * a.rate; } });
    return { total: t, still: still, own: own, sk: sk };
  }

  function changes(){
    var n = 0;
    Object.keys(P.orate).forEach(function(k){ var o = OP[k], v = num(P.orate[k]); if (isNaN(v)) { n++; return; } if (v === null ? o.orate !== null : (o.orate === null ? !(Math.abs(v - o.std) < 0.001) : Math.abs(v - o.orate) > 0.001)) n++; });
    D.lines.forEach(function(l){ l.parts.forEach(function(p){ p.ops.forEach(function(r){ var k = key(l, r);
      if (P.lrate[k] != null) { var v = num(P.lrate[k]); if (isNaN(v) || (v === null ? r.lrate !== null : (r.lrate === null || Math.abs(v - r.lrate) > 0.001))) n++; }
      if (P.lskip[k] != null && P.lskip[k] !== r.lskip) n++; }); }); });
    Object.keys(P.oskip).forEach(function(k){ if (P.oskip[k] !== OP[k].oskip) n++; });
    Object.keys(P.tiers).forEach(function(k){ if (tDiff(k)) n++; });
    allSets(function(l, w){ n += sChanged(w); }); n += P.sadd.length;
    Object.keys(P.plim).forEach(function(k){ var o = OP[k], v = num(P.plim[k]); if (isNaN(v) || (v === null ? o.lim !== null : (o.lim === null || Math.abs(v - o.lim) > 0.001))) n++; });
    return n + P.add.length + Object.keys(P.remove).length;
  }

  function kpis(){
    var tot = 0, still = 0, own = 0, sk = 0, ba = 0;
    D.lines.forEach(function(l){ var c = lineCost(l); tot += c.total; still += c.still; own += c.own; sk += c.sk; l.parts.forEach(function(p){ p.ops.forEach(function(r){ ba += r.ba; }); }); });
    var nsp = D.ops.filter(function(o){ return tiers('o:' + o.id); }).length;
    D.lines.forEach(function(l){ l.parts.forEach(function(p){ p.ops.forEach(function(r){ if (tiers('l:' + l.item + ':' + r.id)) nsp++; }); }); });
    var am = D.ops.filter(function(o){ return oRate(o) != null; }).length, ex = D.ops.filter(function(o){ return o.extra && !gone(o); }).length + P.add.length + P.sadd.length;
    allSets(function(l, w){ if (w.added && sOn(w)) ex++; });
    var cards = [
      ['k-blue', 'PI lines', D.lines.length, D.ops.length + ' operations'],
      ['k-amber', 'Own rates', am + own, am + ' for the order · ' + own + ' for one line' + (nsp ? ' · ' + nsp + ' split' : '')],
      ['k-grey', 'Skipped / added', sk + ' / ' + ex, 'operations this order does not do / does extra'],
      ['k-green', 'Labour for the order', pk(tot), pk(ba) + ' already booked · ' + pk(still) + ' still to make' + (edit ? ' (with your changes)' : '')]
    ];
    $('orKpi').innerHTML = cards.map(function(c){ return '<div class="zf-kpi ' + c[0] + '"><span>' + c[1] + '</span><b>' + c[2] + '</b><small>' + c[3] + '</small></div>'; }).join('');
  }

  function want(txt){ var t = $('orS').value.trim().toLowerCase().split(/\s+/).filter(Boolean); txt = txt.toLowerCase(); return t.every(function(w){ return txt.indexOf(w) >= 0; }); }
  function partOpts(item){
    var seen = {}, out = [];
    D.lines.forEach(function(l){ if (item && l.item !== item) return; l.parts.forEach(function(p){ if (!seen[p.part_id]) { seen[p.part_id] = 1; out.push([p.part_id, p.part]); } }); });
    return out.map(function(p){ return '<option value="' + p[0] + '">' + esc(p[1]) + '</option>'; }).join('');
  }
  function addForm(item){
    return '<tr><td colspan="9" style="padding:0"><div class="or-add" data-add="' + item + '"><b style="font-size:12.5px">+ Add an operation for ' + (item ? 'this line' : 'the whole order') + '</b>'
      + '<select class="ap">' + partOpts(item) + '</select><select class="as">' + D.stages.map(function(s){ return '<option value="' + s.id + '">' + esc(s.name) + '</option>'; }).join('') + '</select>'
      + '<input class="nm" placeholder="Operation, e.g. Extra wash" maxlength="120"><input class="rt" placeholder="Rate" inputmode="decimal">'
      + (item ? '<input class="qt" placeholder="Qty (all)" inputmode="decimal" title="Leave empty for every piece. Type a number for a few pieces only, e.g. 50 stained pieces to wash — such a job never holds back the next stage.">' : '')
      + '<button type="button" class="zf-btn sm pri" data-addgo="' + item + '">Add</button></div></td></tr>';
  }
  function tag(o){ return (o.extra ? '<span class="pill-x ex" title="Added for this order only">extra' + (o.extra_item ? ' · one line' : '') + '</span>' : ''); }

  /* the form that adds set / pack work to the lines ticked */
  function setAddForm(item){
    var it = D.lines.map(function(l){ return '<label><input type="checkbox" value="' + l.item + '" data-prod="' + esc(l.prod) + '"' + (!item || l.item === item ? ' checked' : '') + '> Line ' + l.no + ' ' + esc(l.size || l.prod) + '</label>'; }).join('');
    var pack = D.stages.filter(function(s){ return /pack/i.test(s.name); })[0];
    return '<tr><td colspan="9" style="padding:0"><div class="or-add or-sadd"><b style="font-size:12.5px">+ Add set / pack work (per set)</b>'
      + '<input class="nm" placeholder="e.g. Poly bag, Carton, Folding" maxlength="120"><select class="as"><option value="0">no stage</option>' + D.stages.map(function(s){ return '<option value="' + s.id + '"' + (pack && s.id === pack.id ? ' selected' : '') + '>' + esc(s.name) + '</option>'; }).join('') + '</select>'
      + '<input class="rt" placeholder="Rate / set" inputmode="decimal"><input class="qt" placeholder="Sets (all)" inputmode="decimal" title="Leave empty for every set of each ticked line. Type a number for a few sets only, per line.">'
      + '<button type="button" class="zf-btn sm pri" data-saddgo="1">Add</button>'
      + '<div class="tk"><span class="mut">For:</span>' + it + '<button type="button" class="zf-btn sm" data-tick="all">All lines</button>'
      + (item ? '<button type="button" class="zf-btn sm" data-tick="same" data-prod="' + esc((D.lines.filter(function(l){ return l.item === item; })[0] || {}).prod || '') + '">Same product</button>' : '')
      + '<button type="button" class="zf-btn sm" data-tick="none">None</button></div></div></td></tr>';
  }
  function setNewRow(a, l){
    return '<tr class="or-r chg"><td>' + esc(a.name) + '<span class="pill-x new">new · per set</span>' + limPill(a.lim, l ? 'sets' : 'sets / line') + '</td><td>' + (a.sname ? stPill(a.sname) : '') + '</td><td class="n">—</td><td class="n"><b>' + n2(a.rate) + '</b></td>'
      + (l ? '<td class="n">—</td><td class="n">' + qn(a.lim != null ? Math.min(a.lim, l.qty) : l.qty) + '</td><td class="n">' + pk((a.lim != null ? Math.min(a.lim, l.qty) : l.qty) * a.rate) + '</td><td></td>'
           : '<td class="n">' + a.items.length + '/' + D.lines.length + '</td><td class="n">—</td><td class="n">' + qn(D.lines.reduce(function(t, x){ return t + (a.items.indexOf(x.item) >= 0 ? x.qty : 0); }, 0)) + '</td><td></td>')
      + '<td><button type="button" class="zf-btn sm" data-undosadd="' + P.sadd.indexOf(a) + '">Undo</button></td></tr>';
  }
  /* the line's own box */
  function setBlock(l){
    var S = (l.sets || []).filter(function(w){ return want(w.name + ' ' + w.stage + ' set pack ' + l.prod); }), adds = P.sadd.filter(function(a){ return a.items.indexOf(l.item) >= 0; });
    if (!S.length && !adds.length && !edit) return '';
    var h = '<tr class="or-sh"><td colspan="9">Set / pack work<small>per set · ' + qn(l.qty) + ' sets · ' + (l.own_set ? 'this order\'s own list' : 'as on the product') + '</small></td></tr>';
    if (!S.length && !adds.length) h += '<tr><td colspan="9"><span class="mut" style="font-size:12px">No set or pack work on this line yet.</span></td></tr>';
    S.forEach(function(w){
      var on = sOn(w), rt = sRate(w), c = P.sets[w.key] || {}, ch = edit && sChanged(w) > 0;
      h += '<tr class="or-r' + (ch ? ' chg' : '') + (on ? '' : ' skip') + '" style="box-shadow:inset 4px 0 0 #1f6b35">'
        + '<td>' + esc(w.name) + '<span class="pill-x st">per set</span>' + (w.added ? '<span class="pill-x ex">added</span>' : '') + (!on ? '<span class="pill-x sk">skipped</span>' : '') + limPill(sLim(w), 'sets')
          + (edit && on && w.added ? '<div class="lqe">Qty <input class="or-in" data-sl="' + w.key + '" inputmode="decimal" value="' + esc(c.lim != null ? c.lim : (w.lim == null ? '' : qn(w.lim))) + '" placeholder="all"> sets</div>' : '') + '</td>'
        + '<td>' + (w.stage ? stPill(w.stage) : '') + '</td><td class="n">' + (w.std == null ? '<span class="mut">—</span>' : n2(w.std) + '<span class="tag">product</span>') + '</td>'
        + '<td class="n">' + (edit && on ? '<input class="or-in" data-sr="' + w.key + '" inputmode="decimal" value="' + esc(c.rate != null ? c.rate : w.rate.toFixed(2)) + '">' : (w.std != null && Math.abs(rt - w.std) > 0.001 ? '<b>' + n2(rt) + '</b> <span class="pill-x ln">this line</span>' : n2(rt))) + '</td>'
        + '<td class="n">' + (w.bq > 0 ? qn(w.bq) + ' sets<div class="s2">PKR ' + n2(w.ba) + '</div>' : '<span class="mut">—</span>') + '</td>'
        + '<td class="n">' + (on ? qn(Math.max(0, sCap(w, l) - w.done)) : '<span class="mut">—</span>') + '</td>'
        + '<td class="n">' + (on ? pk(sCap(w, l) * rt) : '<span class="mut">0</span>') + '</td>'
        + '<td class="sk">' + (edit ? '<label class="skc"><input type="checkbox" data-ss="' + w.key + '"' + (on ? '' : ' checked') + '> this line</label>' : '') + '</td><td></td></tr>';
    });
    adds.forEach(function(a){ h += setNewRow(a, l); });
    if (edit) h += setAddForm(l.item);
    return h;
  }
  /* By operation: one row per set job NAME across the order's lines — type
     a rate here and it fills every line that has the job */
  function setGroup(){
    var by = {}, order = [];
    allSets(function(l, w){ var k = sname(w.name); if (!by[k]) { by[k] = { name: w.name, stage: w.stage, rows: [] }; order.push(k); } by[k].rows.push([l, w]); });
    var keys = order.filter(function(k){ return want(by[k].name + ' ' + by[k].stage + ' set pack'); });
    if (!keys.length && !P.sadd.length && !edit) return '';
    var g = 'pSet', shut = open[g] === false;
    var h = '<tr class="or-g' + (shut ? ' shut' : '') + '" data-g="' + g + '"><td colspan="9"><span class="car">▾</span> Set / pack work<small>per set · ' + keys.length + ' job' + (keys.length === 1 ? '' : 's') + '</small></td></tr>';
    if (shut) return h;
    if (edit) h += setAddForm(0);
    keys.forEach(function(k){
      var G = by[k], on = G.rows.filter(function(x){ return sOn(x[1]); }), rates = {}, bq = 0, ba = 0, still = 0, ch = edit && G.rows.some(function(x){ return sChanged(x[1]) > 0; });
      G.rows.forEach(function(x){ bq += x[1].bq; ba += x[1].ba; if (sOn(x[1])) { rates[n2(sRate(x[1]))] = 1; still += Math.max(0, sCap(x[1], x[0]) - x[1].done); } });
      var rl = Object.keys(rates), first = on[0] ? (P.sets[on[0][1].key] && P.sets[on[0][1].key].rate != null ? P.sets[on[0][1].key].rate : sRate(on[0][1]).toFixed(2)) : '';
      h += '<tr class="or-r' + (ch ? ' chg' : '') + (on.length ? '' : ' skip') + '" style="box-shadow:inset 4px 0 0 #1f6b35">'
        + '<td>' + esc(G.name) + '<span class="pill-x st">per set</span>' + (!on.length ? '<span class="pill-x sk">skipped</span>' : '') + '</td><td>' + (G.stage ? stPill(G.stage) : '') + '</td><td class="n"><span class="mut">—</span></td>'
        + '<td class="n">' + (edit && on.length ? '<input class="or-in" data-sn="' + esc(k) + '" inputmode="decimal" value="' + esc(rl.length === 1 ? first : '') + '" placeholder="' + (rl.length > 1 ? 'mixed' : '') + '" title="Typing here fills every line that has this job">'
            : (rl.length ? '<b>' + rl.join(' / ') + '</b>' : '<span class="mut">—</span>')) + '</td>'
        + '<td class="n">' + on.length + '/' + D.lines.length + '</td>'
        + '<td class="n">' + (bq > 0 ? qn(bq) + ' sets<div class="s2">PKR ' + n2(ba) + '</div>' : '<span class="mut">—</span>') + '</td>'
        + '<td class="n">' + (still > 0 ? qn(still) : '<span class="mut">0</span>') + '</td>'
        + '<td class="sk">' + (edit ? '<label class="skc"><input type="checkbox" data-ssn="' + esc(k) + '"' + (on.length ? '' : ' checked') + '> every line</label>' : '') + '</td><td></td></tr>';
    });
    P.sadd.forEach(function(a){ h += setNewRow(a, null).replace('new · per set', 'new · per set · ' + a.items.length + ' line' + (a.items.length === 1 ? '' : 's')); });
    return h;
  }

  /* THE SPLIT PANEL under an operation: produced, balance, each step and how
     far along it is — and, in edit, the steps themselves. */
  function spBtn(k, has){ return '<button type="button" class="sp-btn' + (open['s' + k] ? ' on' : '') + '" data-sp="' + k + '" title="Split this operation\'s quantity across rates">' + (has ? 'Split ' + has + ' ' : 'Split ') + (open['s' + k] ? '▴' : '▾') + '</button>'; }
  function spPanel(k, need, done, flat, paid, inherit){
    var T = tiers(k), h = '<div class="sp">', bal = Math.max(0, need - done);
    var IT = !T && inherit ? inherit.T : null, show = T || IT;
    h += '<div class="sp-top"><span>Produced <b>' + qn(done) + '</b> of ' + qn(need) + (inherit && IT ? ' <span class="mut">(this line)</span>' : '') + '</span><span>Balance <b>' + qn(bal) + '</b></span>'
      + '<span>Next piece pays <b>' + n2(show ? tnow(show, IT ? inherit.done : done) : flat) + '</b></span>'
      + (paid.length ? '<span class="mut">Paid so far: ' + paid.map(function(x){ return qn(x[1]) + ' @ ' + n2(x[0]); }).join(' · ') + '</span>' : '') + '</div>';
    if (IT) {
      h += '<div class="mut">The order\'s split applies, counted on the whole order (' + qn(inherit.done) + ' of ' + qn(inherit.need) + ' made): ' + IT.map(function(x, i){ return (x[0] == null ? 'rest' : qn(x[0])) + ' @ ' + n2(x[1]); }).join(', ') + '.</div>';
      if (CAN) h += '<div class="sp-act"><button type="button" class="zf-btn sm" data-ton="' + k + '">Give this line its own split</button></div>';
      return h + '</div>';
    }
    if (!T && !(edit && P.tiers[k] && P.tiers[k].length)) {
      h += '<div class="mut">No split — every piece pays ' + n2(flat) + '.</div>';
      if (CAN) h += '<div class="sp-act"><button type="button" class="zf-btn sm pri" data-ton="' + k + '">Split this operation</button><span class="mut">e.g. the first 1,000 at 4.00, the rest at 4.25</span></div>';
      return h + '</div>';
    }
    var cnt = T || [], scale = Math.max(need, done, 1), from = 0, bar = '', rows = '', nowAt = -1;
    var steps = edit && P.tiers[k] ? P.tiers[k] : cnt.map(function(x){ return { qty: x[0] == null ? '' : String(x[0]), rate: x[1].toFixed(2) }; });
    var calc = T || [];
    calc.forEach(function(x, i){
      var last = i === calc.length - 1, size = last ? Math.max(0, need - from) : x[0], to = from + size;
      var d = Math.max(0, Math.min(done, last ? Infinity : to) - from), w = Math.max(0, Math.min(size, scale - from)) / scale * 100;
      if (nowAt < 0 && (last || done < to)) nowAt = i;
      bar += '<span class="sp-seg" style="width:' + w + '%" title="' + (last ? 'rest' : qn(from + 1) + '–' + qn(to)) + ' @ ' + n2(x[1]) + '"><i style="width:' + (size > 0 ? Math.min(100, d / size * 100) : 0) + '%"></i></span>';
      calc[i].push(from, size, d);
      from = to;
    });
    rows = steps.map(function(s, i){
      var c = calc[i] || [null, null, 0, 0, 0], last = i === steps.length - 1;
      var range = last ? 'rest' + (c[3] ? ' (' + qn(c[3]) + ')' : '') : qn(c[2] + 1) + ' – ' + qn(c[2] + c[3]);
      return '<tr' + (i === nowAt && !edit ? ' class="now"' : '') + '><td>' + (i + 1) + '. ' + (edit ? (last ? 'the rest' : '<input data-tk="' + k + '" data-ti="' + i + '" data-tf="qty" inputmode="decimal" value="' + esc(s.qty) + '" placeholder="pieces"> pcs') : range) + '</td>'
        + '<td>' + (edit ? '<input data-tk="' + k + '" data-ti="' + i + '" data-tf="rate" inputmode="decimal" value="' + esc(s.rate) + '" placeholder="rate">' : n2(c[1])) + '</td>'
        + '<td>' + qn(c[4]) + '</td><td>' + qn(Math.max(0, c[3] - c[4])) + '</td><td>PKR ' + n2((c[3] || 0) * (c[1] || 0)) + '</td>'
        + '<td>' + (edit && steps.length > 2 ? '<button type="button" class="zf-btn sm" data-tdel="' + k + '|' + i + '">×</button>' : (i === nowAt && !edit ? '◀ now' : '')) + '</td></tr>';
    }).join('');
    if (edit) h += '<div class="sp-hint">Step 1: type how many pieces and their rate. The last step is <b>the rest</b> — type only its rate. Need a third rate? <b>+ Add a step</b>. Then type the reason below and <b>Save</b>.</div>';
    h += '<div class="sp-bar">' + bar + '</div><table class="sp-t"><tr><th>Pieces</th><th>Rate</th><th>Made</th><th>To make</th><th>Amount</th><th></th></tr>' + rows + '</table>';
    if (!edit && CAN) h += '<div class="sp-act"><button type="button" class="zf-btn sm" data-goedit="' + k + '">Change the split</button></div>';
    if (edit) h += '<div class="sp-act">' + (steps.length < 6 ? '<button type="button" class="zf-btn sm" data-tadd="' + k + '">+ Add a step</button>' : '')
      + '<button type="button" class="zf-btn sm" data-toff="' + k + '">Take the split off</button>' + (tBad(k) ? '<span style="color:#b42318">Every step needs pieces above 0 and a rate.</span>' : '<span class="mut">Counted on what is produced: work booked across a step is paid part at each rate.</span>') + '</div>';
    return h + '</div>';
  }

  /* ---------------- BY OPERATION: one rate for the whole order ---------------- */
  function paintOp(){
    $('orHead').innerHTML = '<tr><th>Part · operation</th><th>Stage</th><th class="n">Standard</th><th class="n">This order pays</th><th class="n">Lines</th><th class="n">Booked</th><th class="n">Still to make</th><th>Skip</th><th></th></tr>';
    var by = {}, order = [], n = 0, h = '';
    D.ops.forEach(function(o){ if (!by[o.part]) { by[o.part] = []; order.push(o.part); } by[o.part].push(o); });
    if (edit) h += addForm(0);
    order.forEach(function(pn){
      var rs = by[pn].filter(function(o){ return want(pn + ' ' + o.op + ' ' + o.stage); }), adds = P.add.filter(function(a){ return a.item === 0 && a.pname === pn; });
      if (!rs.length && !adds.length) return;
      var g = 'p' + pn, shut = open[g] === false;
      h += '<tr class="or-g' + (shut ? ' shut' : '') + '" data-g="' + esc(g) + '"><td colspan="9"><span class="car">▾</span> ' + esc(pn) + '<small>' + rs.length + ' operation' + (rs.length === 1 ? '' : 's') + '</small></td></tr>';
      if (shut) return;
      rs.forEach(function(o){
        n++;
        var bq = 0, ba = 0, still = 0, nl = 0;
        D.lines.forEach(function(l){ l.parts.forEach(function(p){ p.ops.forEach(function(r){ if (r.id !== o.id) return; nl++; bq += r.bq; ba += r.ba; if (!skipped(l, r)) still += Math.max(0, rNeed(r) - r.done); }); }); });
        var e = oRate(o), sk = oSkip(o), rm = gone(o);
        var ok_ = 'o:' + o.id, OT = tiers(ok_), paid = {};
        D.lines.forEach(function(l){ l.parts.forEach(function(p){ p.ops.forEach(function(r){ if (r.id === o.id) (r.paid || []).forEach(function(x){ paid[x[0]] = (paid[x[0]] || 0) + x[1]; }); }); }); });
        var ch = edit && ((P.orate[o.id] != null) || (P.oskip[o.id] != null && P.oskip[o.id] !== o.oskip) || rm || (P.tiers[ok_] != null && tDiff(ok_)));
        h += '<tr class="or-r' + (ch ? ' chg' : '') + (sk || rm ? ' skip' : '') + '" style="box-shadow:inset 4px 0 0 ' + stStyle(o.stage)[1] + '">'
          + '<td>' + esc(o.op) + tag(o) + (sk ? '<span class="pill-x sk">skipped</span>' : '') + (rm ? '<span class="pill-x sk">removed</span>' : '') + limPill(oLim(o), 'pcs') + '</td>'
          + '<td>' + stPill(o.stage) + '</td><td class="n">' + n2(o.std) + '</td>'
          + '<td class="n">' + (OT ? '<b>' + sumTxt(OT) + '</b><span class="pill-x tr">split</span>'
              : edit && !rm ? '<input class="or-in" data-or="' + o.id + '" inputmode="decimal" value="' + esc(P.orate[o.id] != null ? P.orate[o.id] : (o.orate === null ? '' : o.orate.toFixed(2))) + '" placeholder="' + n2(o.std) + '">'
              : (e === null ? '<span class="mut">standard</span>' : '<b>' + n2(e) + '</b>' + (e > o.std ? ' <span class="up">▲</span>' : e < o.std ? ' <span class="dn">▼</span>' : ''))) + '</td>'
          + '<td class="n">' + nl + '/' + D.lines.length + '</td>'
          + '<td class="n">' + (bq > 0 ? qn(bq) + ' pcs<div class="s2">PKR ' + n2(ba) + '</div>' : '<span class="mut">—</span>') + '</td>'
          + '<td class="n">' + (still > 0 ? qn(still) : '<span class="mut">0</span>') + '</td>'
          + '<td class="sk">' + (edit && !rm ? '<label class="skc"><input type="checkbox" data-os="' + o.id + '"' + (sk ? ' checked' : '') + '> whole order</label>' : '') + '</td>'
          + '<td style="white-space:nowrap">' + (sk || rm ? '' : spBtn(ok_, OT ? OT.length : 0) + ' ') + (edit && o.extra ? (rm ? '<button type="button" class="zf-btn sm" data-unrm="' + o.id + '">Keep</button>' : '<button type="button" class="zf-btn sm" data-rm="' + o.id + '">Remove</button>') : '') + '</td></tr>';
        if (open['s' + ok_] && !sk && !rm) { var nd = oNeed(o.id);
          h += '<tr class="or-sp"><td colspan="9">' + spPanel(ok_, nd[0], nd[1], e === null ? o.std : e, Object.keys(paid).map(function(r){ return [+r, paid[r]]; }).sort(function(a, b){ return a[0] - b[0]; }), null) + '</td></tr>'; }
      });
      adds.forEach(function(a, i){ h += newRow(a); });
    });
    P.add.filter(function(a){ return a.item === 0 && order.indexOf(a.pname) < 0; }).forEach(function(a){ h += newRow(a); });
    h += setGroup();
    $('orBody').innerHTML = h || '<tr><td colspan="9"><div class="zf-none">Nothing matches.</div></td></tr>';
    $('orCnt').textContent = n + ' of ' + D.ops.length + ' operations';
  }
  function newRow(a){
    return '<tr class="or-r chg"><td>' + esc(a.pname) + ' · ' + esc(a.name) + '<span class="pill-x new">new' + (a.item ? ' · this line' : '') + '</span>' + limPill(a.lim, 'pcs') + '</td><td>' + stPill(a.sname) + '</td><td class="n">—</td><td class="n"><b>' + n2(a.rate) + '</b></td>'
      + '<td colspan="4"></td><td><button type="button" class="zf-btn sm" data-undoadd="' + P.add.indexOf(a) + '">Undo</button></td></tr>';
  }

  /* ---------------- BY PI LINE: each line on its own ---------------- */
  function paintLine(){
    var C = D.order.currency || '';
    $('orHead').innerHTML = '<tr><th>Part · operation</th><th>Stage</th><th class="n">Order rate</th><th class="n">This line pays</th><th class="n">Booked on line</th><th class="n">Still to make</th><th class="n">Cost</th><th>Skip</th><th></th></tr>';
    var h = '', n = 0;
    D.lines.forEach(function(l){
      var hay = l.prod + ' ' + l.size + ' ' + (l.desc || ''); var any = want(hay) || (l.sets || []).some(function(w){ return want(w.name + ' set pack'); }) || l.parts.some(function(p){ return p.ops.some(function(r){ var o = OP[r.id]; return want(p.part + ' ' + o.op + ' ' + o.stage); }); });
      if (!any) return;
      n++;
      var c = lineCost(l), g = 'l' + l.item, shut = !open[g];
      h += '<tr class="or-l' + (shut ? ' shut' : '') + '" data-g="' + g + '"><td colspan="9"><span class="car">' + (shut ? '▸' : '▾') + '</span> <b>Line ' + l.no + ' · ' + esc(l.prod) + '</b>'
        + (l.size ? '<span class="lm">' + esc(l.size) + '</span>' : '') + '<span class="lm">' + qn(l.qty) + ' pcs' + (l.price ? ' · sale ' + esc(C) + ' ' + n2(l.price) : '') + '</span>'
        + (l.desc ? '<span class="ld" title="Description on the proforma line">' + esc(l.desc) + '</span>' : '')
        + (c.own ? '<span class="pill-x ln">' + c.own + ' own rate' + (c.own === 1 ? '' : 's') + '</span>' : '') + (c.sk ? '<span class="pill-x sk">' + c.sk + ' skipped</span>' : '')
        + '<span class="lc">labour <b>PKR ' + n2(l.qty ? c.total / l.qty : 0) + '</b>/pc · ' + pk(c.total) + '</span></td></tr>';
      if (shut) return;
      l.parts.forEach(function(p){
        p.ops.forEach(function(r){
          var o = OP[r.id]; if (!want(p.part + ' ' + o.op + ' ' + o.stage + ' ' + hay)) return;
          var k = key(l, r), lr = lRate(l, r), e = effLine(l, r), os = oSkip(o), ls = lSkip(l, r), sk = os || ls || gone(o), base = oRate(o) != null ? oRate(o) : r.base;
          var lk = 'l:' + k, LT = tiers(lk), TR = lineTier(l, r);
          var ch = edit && ((P.lrate[k] != null) || (P.lskip[k] != null && P.lskip[k] !== r.lskip) || (P.tiers[lk] != null && tDiff(lk)) || (P.plim[r.id] != null && o.extra_item === l.item));
          h += '<tr class="or-r' + (ch ? ' chg' : '') + (sk ? ' skip' : '') + '" style="box-shadow:inset 4px 0 0 ' + stStyle(o.stage)[1] + '">'
            + '<td>' + esc(p.part) + '<div class="s2">' + esc(o.op) + tag(o) + (os ? '<span class="pill-x sk">skipped for order</span>' : ls ? '<span class="pill-x sk">skipped</span>' : '') + limPill(oLim(o), 'pcs') + '</div>'
              + (edit && o.extra && o.extra_item === l.item && !sk ? '<div class="lqe">Qty <input class="or-in" data-pl="' + o.id + '" inputmode="decimal" value="' + esc(P.plim[o.id] != null ? P.plim[o.id] : (o.lim == null ? '' : qn(o.lim))) + '" placeholder="all"> pcs</div>' : '') + '</td>'
            + '<td>' + stPill(o.stage) + '</td><td class="n">' + n2(base) + (oRate(o) != null ? '<span class="tag">order</span>' : r.base !== o.std ? '<span class="tag">size</span>' : '') + '</td>'
            + '<td class="n">' + (LT && !sk ? '<b>' + sumTxt(LT) + '</b><span class="pill-x tr">split</span>'
                : edit && !sk ? '<input class="or-in" data-lr="' + k + '" inputmode="decimal" value="' + esc(P.lrate[k] != null ? P.lrate[k] : (r.lrate === null ? '' : r.lrate.toFixed(2))) + '" placeholder="' + n2(base) + '">'
                : TR ? '<span class="mut">' + sumTxt(TR.T) + '</span><span class="pill-x tr">order split</span>'
                : (lr == null ? '<span class="mut">same</span>' : '<b>' + n2(lr) + '</b> <span class="pill-x ln">this line</span>')) + '</td>'
            + '<td class="n">' + (r.bq > 0 ? qn(r.bq) + ' pcs<div class="s2">PKR ' + n2(r.ba) + '</div>' : '<span class="mut">—</span>') + '</td>'
            + '<td class="n">' + (sk ? '<span class="mut">—</span>' : qn(Math.max(0, rNeed(r) - r.done))) + '</td>'
            + '<td class="n">' + (sk ? '<span class="mut">0</span>' : pk(TR && TR.need > 0 && oLim(o) == null ? (TR.order ? r.need * tcost(TR.T, 0, TR.need) / TR.need : tcost(TR.T, 0, r.need)) : rNeed(r) * e)) + '</td>'
            + '<td class="sk">' + (edit && !os && !gone(o) ? '<label class="skc"><input type="checkbox" data-ls="' + k + '"' + (ls ? ' checked' : '') + '> this line</label>' : '') + '</td>'
            + '<td>' + (sk ? '' : spBtn(lk, LT ? LT.length : 0)) + '</td></tr>';
          if (open['s' + lk] && !sk) h += '<tr class="or-sp"><td colspan="9">' + spPanel(lk, r.need, r.done, e, r.paid || [], TR && TR.order ? TR : null) + '</td></tr>';
        });
      });
      P.add.filter(function(a){ return (a.item === l.item || a.item === 0) && l.parts.some(function(p){ return p.part_id === a.part; }); }).forEach(function(a){ h += newRow(a); });
      if (edit) h += addForm(l.item);
      h += setBlock(l);
    });
    $('orBody').innerHTML = h || '<tr><td colspan="9"><div class="zf-none">Nothing matches.</div></td></tr>';
    $('orCnt').textContent = n + ' of ' + D.lines.length + ' lines';
  }

  function paint(){
    [].forEach.call($('orView').children, function(b){ b.classList.toggle('on', b.dataset.v === view); });
    view === 'op' ? paintOp() : paintLine();
    kpis(); foot();
  }
  function foot(){
    var f = $('orFoot'); f.hidden = !edit; if (!edit) return;
    var n = changes();
    $('orChg').innerHTML = n ? '<b>' + n + '</b> change' + (n === 1 ? '' : 's') : 'Type a rate (blank = back to the one above it), tick Skip, or add an operation';
    $('orSave').textContent = n ? 'Save ' + n + ' change' + (n === 1 ? '' : 's') : 'Save'; $('orSave').disabled = !n;
  }
  function toast(t, bad){ var x = document.createElement('div'); x.className = 'or-toast' + (bad ? ' bad' : ''); x.textContent = t; document.querySelector('.zf.or').appendChild(x); setTimeout(function(){ x.remove(); }, bad ? 6000 : 3500); }

  $('orView').addEventListener('click', function(e){ var b = e.target.closest('button'); if (!b) return; view = b.dataset.v; paint(); });
  $('orS').addEventListener('input', paint);
  $('orOpen').addEventListener('click', function(){ D.lines.forEach(function(l){ open['l' + l.item] = true; }); Object.keys(open).forEach(function(k){ if (k[0] === 'p') open[k] = true; }); D.ops.forEach(function(o){ open['p' + o.part] = true; }); open.pSet = true; paint(); });
  $('orShut').addEventListener('click', function(){ D.lines.forEach(function(l){ open['l' + l.item] = false; }); D.ops.forEach(function(o){ open['p' + o.part] = false; }); open.pSet = false; paint(); });
  $('orBody').addEventListener('click', function(e){
    var g = e.target.closest('tr.or-g, tr.or-l');
    if (g && !e.target.closest('input,button,select,label')) { var k = g.dataset.g; open[k] = k[0] === 'l' ? !open[k] : open[k] === false; paint(); return; }
    var b = e.target.closest('button'); if (!b) return;
    if (b.dataset.tick) { var bx = b.closest('.or-sadd'); [].forEach.call(bx.querySelectorAll('.tk input'), function(c){ c.checked = b.dataset.tick === 'all' || (b.dataset.tick === 'same' && c.dataset.prod === b.dataset.prod); }); return; }
    if (b.dataset.undosadd != null) { P.sadd.splice(+b.dataset.undosadd, 1); paint(); return; }
    if (b.dataset.saddgo) {
      var sb = b.closest('.or-sadd'), snm = sb.querySelector('.nm').value.trim(), srt = num(sb.querySelector('.rt').value), sas = sb.querySelector('.as');
      var items = [].map.call(sb.querySelectorAll('.tk input:checked'), function(c){ return +c.value; });
      if (!snm) { sb.querySelector('.nm').focus(); return toast('Give the set work a name, e.g. Poly bag.', true); }
      if (srt === null || isNaN(srt)) { sb.querySelector('.rt').focus(); return toast('Give the rate per set.', true); }
      if (!items.length) return toast('Tick at least one line.', true);
      var sq = num(sb.querySelector('.qt').value);
      if (sq !== null && (isNaN(sq) || sq <= 0)) { sb.querySelector('.qt').focus(); return toast('The number of sets must be above 0 — or leave it empty for every set.', true); }
      P.sadd.push({ items: items, name: snm, stage: +sas.value, sname: +sas.value ? sas.options[sas.selectedIndex].text : '', rate: srt, lim: sq });
      items.forEach(function(i){ open['l' + i] = true; }); paint(); return;
    }
    if (b.dataset.sp) { open['s' + b.dataset.sp] = !open['s' + b.dataset.sp]; paint(); return; }
    /* starting or changing a split from the panel switches Edit on by itself — one click, not three */
    if (b.dataset.goedit) { startEdit(); paint(); var g0 = document.querySelector('[data-tk="' + b.dataset.goedit + '"][data-ti="0"]'); if (g0) { g0.focus(); g0.select(); } return; }
    if (b.dataset.ton) { startEdit(); var tk = b.dataset.ton, pp = tk.split(':'), nd, cur;
      if (pp[0] === 'o') { nd = oNeed(+pp[1])[0]; cur = oRate(OP[pp[1]]); if (cur == null) cur = OP[pp[1]].std; }
      else { var LL = null, RR = null; D.lines.forEach(function(l){ if (l.item === +pp[1]) { LL = l; l.parts.forEach(function(p){ p.ops.forEach(function(r){ if (r.id === +pp[2]) RR = r; }); }); } });
        nd = RR ? RR.need : 0; cur = LL && RR ? effLine(LL, RR) : 0; }
      /* a sensible start: two thirds at today's rate, the rest the same — then type */
      P.tiers[tk] = [{ qty: nd > 1 ? String(Math.round(nd * 2 / 3)) : '', rate: cur.toFixed(2) }, { qty: '', rate: cur.toFixed(2) }];
      open['s' + tk] = true; paint(); var f = document.querySelector('[data-tk="' + tk + '"][data-ti="0"][data-tf="qty"]'); if (f) { f.focus(); f.select(); } return; }
    if (b.dataset.tadd) { var t2 = P.tiers[b.dataset.tadd] || []; t2.splice(t2.length - 1, 0, { qty: '', rate: t2.length ? t2[t2.length - 1].rate : '' }); paint(); return; }
    if (b.dataset.tdel) { var q2 = b.dataset.tdel.split('|'); P.tiers[q2[0]].splice(+q2[1], 1); paint(); return; }
    if (b.dataset.toff) { P.tiers[b.dataset.toff] = []; if (!saved(b.dataset.toff)) delete P.tiers[b.dataset.toff]; paint(); return; }
    if (b.dataset.rm) { P.remove[b.dataset.rm] = true; paint(); }
    else if (b.dataset.unrm) { delete P.remove[b.dataset.unrm]; paint(); }
    else if (b.dataset.undoadd != null) { P.add.splice(+b.dataset.undoadd, 1); paint(); }
    else if (b.dataset.addgo != null) {
      var box = b.closest('.or-add'), it = +b.dataset.addgo, ap = box.querySelector('.ap'), as = box.querySelector('.as'), nm = box.querySelector('.nm').value.trim(), rt = num(box.querySelector('.rt').value);
      if (!nm) { box.querySelector('.nm').focus(); return toast('Give the new operation a name.', true); }
      if (rt === null || isNaN(rt)) { box.querySelector('.rt').focus(); return toast('Give the new operation a rate.', true); }
      var qe = box.querySelector('.qt'), lq = qe ? num(qe.value) : null;
      if (lq !== null && (isNaN(lq) || lq <= 0)) { qe.focus(); return toast('The quantity must be above 0 — or leave it empty for every piece.', true); }
      P.add.push({ item: it, part: +ap.value, pname: ap.options[ap.selectedIndex].text, stage: +as.value, sname: as.options[as.selectedIndex].text, name: nm, rate: rt, lim: lq });
      if (it) open['l' + it] = true; paint();
    }
  });
  $('orBody').addEventListener('change', function(e){
    var t = e.target;
    if (t.dataset.ss || t.dataset.ssn) {
      allSets(function(l, w){ if (t.dataset.ss ? w.key !== t.dataset.ss : sname(w.name) !== t.dataset.ssn) return;
        var c = P.sets[w.key] = P.sets[w.key] || {}; if (t.checked === !w.on) delete c.skip; else c.skip = t.checked; if (c.rate == null && c.skip == null) delete P.sets[w.key]; });
      paint(); return;
    }
    if (t.dataset.os) { var o = OP[t.dataset.os]; if (t.checked === o.oskip) delete P.oskip[o.id]; else P.oskip[o.id] = t.checked; paint(); }
    if (t.dataset.ls) { var parts = t.dataset.ls.split(':'), r = null; D.lines.forEach(function(l){ if (l.item === +parts[0]) l.parts.forEach(function(p){ p.ops.forEach(function(x){ if (x.id === +parts[1]) r = x; }); }); });
      if (r && t.checked === r.lskip) delete P.lskip[t.dataset.ls]; else P.lskip[t.dataset.ls] = t.checked; paint(); }
  });
  /* typing updates the figures without losing the cursor */
  $('orBody').addEventListener('input', function(e){
    var i = e.target;
    if (i.dataset.tk) { var tk = i.dataset.tk, pos0 = i.selectionStart, sel0 = '[data-tk="' + tk + '"][data-ti="' + i.dataset.ti + '"][data-tf="' + i.dataset.tf + '"]';
      if (!P.tiers[tk]) P.tiers[tk] = (saved(tk) || []).map(function(x){ return { qty: x[0] == null ? '' : String(x[0]), rate: x[1].toFixed(2) }; });
      P.tiers[tk][+i.dataset.ti][i.dataset.tf] = i.value; paint();
      var a0 = document.querySelector(sel0); if (a0) { a0.focus(); try { a0.setSelectionRange(pos0, pos0); } catch (x) {} } return; }
    if (!i.classList.contains('or-in')) return;
    var at = ['or', 'lr', 'sr', 'sn', 'pl', 'sl'].filter(function(a){ return i.dataset[a] != null; })[0], sel = '[data-' + at + '="' + (window.CSS && CSS.escape ? CSS.escape(i.dataset[at]) : i.dataset[at]) + '"]';
    if (at === 'or') P.orate[i.dataset.or] = i.value; else if (at === 'lr') P.lrate[i.dataset.lr] = i.value;
    else if (at === 'pl') P.plim[i.dataset.pl] = i.value;
    else if (at === 'sl') (P.sets[i.dataset.sl] = P.sets[i.dataset.sl] || {}).lim = i.value;
    else allSets(function(l, w){ if (at === 'sr' ? w.key === i.dataset.sr : sname(w.name) === i.dataset.sn && sOn(w)) { (P.sets[w.key] = P.sets[w.key] || {}).rate = i.value; } });
    var pos = i.selectionStart; paint();
    var again = document.querySelector('.or-in' + sel); if (again) { again.focus(); try { again.setSelectionRange(pos, pos); } catch (x) {} }
  });
  $('orBody').addEventListener('keydown', function(e){
    var i = e.target.closest('.or-in'); if (!i || e.key !== 'Enter') return; e.preventDefault();
    var all = [].slice.call(document.querySelectorAll('.or-in')), k = all.indexOf(i);
    if (all[k + 1]) { all[k + 1].focus(); all[k + 1].select(); } else $('orWhy').focus();
  });

  function startEdit(){ if (edit || !CAN) return; edit = true; reset(); if ($('orEditBtn')) $('orEditBtn').hidden = true; }
  if (CAN && $('orEditBtn')) {
    $('orEditBtn').addEventListener('click', function(){ startEdit(); paint(); });
    $('orDiscard').addEventListener('click', function(){
      if (changes() && !confirm('Throw away the changes you typed?')) return;
      edit = false; reset(); $('orEditBtn').hidden = false; $('orWhy').value = ''; paint();
    });
    $('orSave').addEventListener('click', function(){
      var n = changes(); if (!n) return;
      var why = $('orWhy').value.trim();
      if (!why) { $('orWhy').focus(); $('orWhy').style.borderColor = '#b42318'; $('orWhy').placeholder = 'A reason is required — e.g. double-stitched hem in the contract'; return; }
      var bad = Object.keys(P.orate).map(function(k){ return P.orate[k]; }).concat(Object.keys(P.lrate).map(function(k){ return P.lrate[k]; })).filter(function(v){ return isNaN(num(v)); });
      if (bad.length) return toast('"' + bad[0] + '" is not a rate.', true);
      var sbad = []; allSets(function(l, w){ var c = P.sets[w.key]; if (c && c.rate != null && (num(c.rate) === null || isNaN(num(c.rate)))) sbad.push(c.rate); });
      if (sbad.length) return toast('"' + sbad[0] + '" is not a rate.', true);
      var tb = Object.keys(P.tiers).filter(tBad); if (tb.length) { open['s' + tb[0]] = true; paint(); return toast('A split step is missing its pieces or rate.', true); }
      var tiersOut = {}; Object.keys(P.tiers).forEach(function(k){ if (tDiff(k)) { var T = tiers(k); tiersOut[k] = T ? T.map(function(x){ return { qty: x[0], rate: x[1] }; }) : []; } });
      var lbad = Object.keys(P.plim).map(function(k){ return P.plim[k]; }); allSets(function(l, w){ var c = P.sets[w.key]; if (c && c.lim != null) lbad.push(c.lim); });
      lbad = lbad.filter(function(v){ var q = num(v); return q !== null && (isNaN(q) || q <= 0); });
      if (lbad.length) return toast('"' + lbad[0] + '" is not a quantity — type a number above 0, or leave it empty for all.', true);
      var setsOut = {}; allSets(function(l, w){ if (sChanged(w)) { var c = P.sets[w.key], o = {}; if (c.rate != null) o.rate = c.rate; if (c.skip != null) o.skip = c.skip; if (c.lim != null) o.lim = c.lim; setsOut[w.key] = o; } });
      var ch = { orate: P.orate, lrate: P.lrate, oskip: P.oskip, lskip: P.lskip, remove: Object.keys(P.remove).map(Number), tiers: tiersOut,
                 sets: setsOut, sadd: P.sadd.map(function(a){ return { items: a.items, name: a.name, stage: a.stage, rate: a.rate, qty: a.lim == null ? '' : a.lim }; }), plim: P.plim,
                 add: P.add.map(function(a){ return { item: a.item, part: a.part, stage: a.stage, name: a.name, rate: a.rate, qty: a.lim == null ? '' : a.lim }; }) };
      var fd = new FormData($('orTok')); fd.append('action', 'save_json'); fd.append('proforma_id', PF); fd.append('reason', why); fd.append('changes', JSON.stringify(ch));
      var b = this; b.disabled = true; b.textContent = 'Saving…';
      fetch(location.pathname + '?order=' + PF, { method: 'POST', body: fd, credentials: 'same-origin' }).then(function(r){ return r.json(); }).then(function(r){
        if (!r.ok) { b.disabled = false; foot(); return toast(r.error || 'Not saved.', true); }
        D = r; index(); edit = false; reset(); $('orEditBtn').hidden = false; $('orWhy').value = ''; $('orWhy').style.borderColor = '';
        if (r.history) paintHist(r.history);
        paint(); toast(r.saved + ' change' + (r.saved === 1 ? '' : 's') + ' saved — work booked from now on follows them. Wages already booked are unchanged.');
      }).catch(function(){ b.disabled = false; foot(); toast('The server could not be reached. Nothing was saved; try again.', true); });
    });
    window.addEventListener('beforeunload', function(e){ if (edit && changes()) { e.preventDefault(); e.returnValue = ''; } });
  }
  function paintHist(H){
    $('orHn').textContent = H.length;
    $('orHist').innerHTML = H.length ? H.map(function(h){ var d = new Date(String(h.created_at).replace(' ', 'T'));
      return '<tr style="cursor:default"><td class="n" style="text-align:left">' + esc(d.toLocaleString('en-GB', {day:'numeric', month:'short', year:'numeric', hour:'2-digit', minute:'2-digit'})) + '</td>'
        + '<td>' + esc(h.part_name || '—') + '<div class="s2">' + esc(h.operation_name || '—') + '</div></td><td>' + (h.proforma_id ? '<span class="zf-pill p-draft">this order</span>' : '<span class="zf-pill p-grey">standard</span>') + '</td>'
        + '<td class="n">' + (h.old_rate === null ? '—' : n2(+h.old_rate)) + '</td><td class="n"><b>' + n2(+h.new_rate) + '</b></td><td style="color:var(--mut)">' + esc(h.reason || '—') + '</td></tr>'; }).join('')
      : '<tr><td colspan="6"><div class="zf-none">Nothing has changed yet.</div></td></tr>';
  }
  paint();
})();
</script>
</div><?php /* closes .zskin */ ?>
<?php page_footer(); ?>
