<?php
/*
  PRODUCTION DASHBOARD — what happened, and what is late.
  ======================================================

  REBUILT 28 NOV 2026 (owner: "not proper, standard and professional … like SAP
  Fiori and Oracle Fusion … animated show / hide … cards flip on a click,
  summary on the front, detail on the back … filters that work nicely").

  ONE READ OF THE PERIOD, THE REST IN THE BROWSER. includes/zp_report.php sends
  the period's entries once, as compact rows; assets/js/zprep.js draws every
  card, chart and list from them and applies every filter (department, stage,
  worker, product, order, a day, a search) on the page. Changing a filter costs
  the server nothing; only another period asks again.

  EVERY FIGURE COMES FROM THE SAME LEDGER, filtered the same way: active rows
  only. Cancelled entries are counted on one card, as cancelled, and nowhere
  else. Wages appear only for people allowed to see rates.

  ORDERS ARE SORTED BY HOW FAR BEHIND THEY ARE, worst first, measured on the
  last stage each line goes through (zp_order_progress) — a dashboard sorted
  by date makes you hunt for the problem it was supposed to show you.
*/
require_once __DIR__ . '/includes/bootstrap.php';
require_login();
if (!is_admin() && !is_colleague() && !is_production_staff()) {
    http_response_code(403); exit('Production access required.');
}
require_once __DIR__ . '/includes/zprod.php';
require_once __DIR__ . '/includes/zp_report.php';
require_once __DIR__ . '/includes/zp_staff.php';   // a Production Staff login may be limited to its floors
zp_ensure_schema();
zps_ensure();
$FLOORS = zps_depts();

header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
header('Pragma: no-cache');
header('Expires: 0');

$showMoney = can_see_rates();
[$from, $to] = zp_range($_GET['from'] ?? null, $_GET['to'] ?? null);

/* ANOTHER PERIOD WITHOUT RELOADING THE PAGE: the page's script asks for
   ?json=1 and gets only that period's rows — no menu, no page, no order
   progress (that does not depend on the period). */
if (($_GET['json'] ?? '') === '1') {
    if (session_status() === PHP_SESSION_ACTIVE) session_write_close();
    header('Content-Type: application/json; charset=utf-8');
    $J = zpr_dataset($from, $to, $showMoney, $FLOORS);
    $J['prev'] = zpr_previous($from, $to, $FLOORS);
    if (!$showMoney) $J['prev']['amt'] = 0;
    echo zpr_json($J);
    exit;
}

csrf_token();
ob_start();
page_header('Production Dashboard');
$pageHead = ob_get_clean();
if (session_status() === PHP_SESSION_ACTIVE) session_write_close();   // nothing below writes the session

$D = zpr_dataset($from, $to, $showMoney, $FLOORS);
$D['page'] = 'dash';
$D['prev'] = zpr_previous($from, $to, $FLOORS);
if (!$showMoney) $D['prev']['amt'] = 0;
/* FINISHED PRODUCTS and the furthest-behind list come from ONE pass over the
   open order lines (zpr_finished uses zp_line_flow, as Order Status does). */
$D['fin'] = zpr_finished();
$D['orders'] = array_map(fn($o) => [
    'proforma_id' => $o['f'], 'pi_no' => $o['pi'], 'customer' => $o['cust'], 'product' => $o['prod'], 'size' => $o['size'],
    'ordered' => $o['ord'], 'pct' => round($o['pct'], 1), 'behind' => $o['behind'] !== '' ? [$o['behind']] : [],
], $D['fin']);

echo $pageHead;
?>
<link rel="stylesheet" href="assets/css/zprep.css?v=3">
<style>
/* the dashboard tables fit their cards (app.css gives every table min-width:980px) */
.zd-wrap table.zp-t{min-width:0}
</style>
<?php /* THE SKIN, OPTED IN — see assets/css/zskin.css. */ ?>
<div class="zskin">
<div class="zpr zd-wrap" id="zpr">
  <?php zpr_head('Production Dashboard', 'dash', $from, $to, [
      ['production_reports.php', 'Reports', true], ['production_orders.php', 'Order status', false]]); ?>

  <?php if ($D['err']): ?><div class="warnbox"><?= e($D['err']) ?></div><?php endif; ?>
  <?php if ($D['cut']): ?><div class="warnbox">This period has more than 120,000 entries — only the first 120,000 are shown. Choose a shorter period.</div><?php endif; ?>

  <div class="cards" id="zprK"></div>

  <div class="sec" id="secStage">
    <div class="sh"><span class="chev">▾</span><h2>Stages</h2><span class="s2">click a card to turn it — top operations on the back</span>
      <div class="tools"><button type="button" class="btn sm" id="zprFlip" title="Turn every card over">⟲ Turn all cards</button></div></div>
    <div class="fold"><div><div class="sb"><div class="cards" id="zprSC" style="margin:0"></div></div></div></div>
  </div>

  <div class="sec" id="secDay">
    <div class="sh"><span class="chev">▾</span><h2>Pieces per day</h2><span class="s2">every day is drawn, the empty ones too — a gap means the floor stopped</span></div>
    <div class="fold"><div><div class="sb"><div class="chart"></div><div class="legend"></div></div></div></div>
  </div>

  <div class="grid2">
    <div class="sec" id="secOrd">
      <div class="sh"><span class="chev">▾</span><h2>Furthest behind</h2><span class="s2">open order lines, worst first</span>
        <div class="tools"><a class="btn sm" href="production_orders.php">All orders →</a></div></div>
      <div class="fold"><div><div class="sb" id="dOrd"></div></div></div>
    </div>
    <div class="sec" id="secDept">
      <div class="sh"><span class="chev">▾</span><h2>By department</h2><span class="s2"><?= $showMoney ? 'wages' : 'pieces' ?> · click one to filter</span></div>
      <div class="fold"><div><div class="sb" id="dDept"></div></div></div>
    </div>
  </div>

  <div class="grid2">
    <div class="sec" id="secWk">
      <div class="sh"><span class="chev">▾</span><h2>Top workers</h2><span class="s2"><?= $showMoney ? 'by wages' : 'by pieces' ?> · click a name for the detail</span></div>
      <div class="fold"><div><div class="sb" id="dWk"></div></div></div>
    </div>
    <div class="sec" id="secPr">
      <div class="sh"><span class="chev">▾</span><h2>By product</h2><span class="s2">click one to filter</span></div>
      <div class="fold"><div><div class="sb" id="dPr"></div></div></div>
    </div>
  </div>

  <div class="sec" id="secOp" style="margin-top:12px">
    <div class="sh"><span class="chev">▾</span><h2>Top operations</h2><span class="s2">click one for who did it</span></div>
    <div class="fold"><div><div class="sb" id="dOp"></div></div></div>
  </div>

  <p class="note"><b>Cancelled entries are not counted</b> in any figure here except the Entries card, where they are shown as cancelled.
    Click a section's title to fold it away — the page remembers. Filters and the chosen day are kept in the address, so the link can be shared.</p>

  <div class="ov" id="zprOv"></div>
  <aside class="side" id="zprSide" aria-label="Detail"></aside>
</div>
</div><?php /* closes .zskin */ ?>
<script>window.ZPR_DATA = <?= zpr_json($D) ?>;</script>
<script src="assets/js/zprep.js?v=3"></script>
<?php page_footer(); ?>
