<?php
/*
  PRODUCTION REPORTS — seven views of the same ledger.
  ===================================================

      By worker      what each person made and earned   ▸ their operations
      By department  the floors side by side            ▸ their people
      By product     where the wage bill goes           ▸ operations ▸ workers
      By order       each PI                            ▸ lines (product · size) ▸ operations
      By operation   which step costs what              ▸ who did it
      Day by day     for payroll, a column per stage    ▸ stages ▸ workers
      Every entry    the audit view, cancelled ones marked with the reason

  REBUILT 28 NOV 2026 (owner: "not proper, standard and professional … like SAP
  Fiori / Oracle Fusion … filters that work nicely … cards that flip"). The
  period is read ONCE (includes/zp_report.php); tabs, filters, sorting, the
  ▸ drill-down, the column chooser, the side panel for one worker / order /
  product and the CSV all happen on the page (assets/js/zprep.js). Only a
  different period asks the server again.

  ONE SOURCE, ONE RULE. Every view counts active entries only; "Every entry"
  is the one place cancelled rows are shown, faded, with the reason.

  THE CSV IS THE SCREEN: it is written from the table as drawn — the same
  filters, the same rows, the opened detail too. Names that start with = + - @
  get a ' in front so Excel never runs them as a formula.

  OLD LINKS STILL WORK: ?r=worker|dept|product|op|daily|entries&from=&to= opens
  that tab for that period (Daily Entry's "Full report" uses it).
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
$TABS = ['fin' => 'Finished products', 'worker' => 'By worker', 'dept' => 'By department', 'product' => 'By product', 'order' => 'By order',
         'op' => 'By operation', 'daily' => 'Day by day', 'user' => 'By data entry', 'entries' => 'Every entry'];
$tab = (string)($_GET['r'] ?? 'fin');     // whole products first (owner, 28 Nov 2026)
if (!isset($TABS[$tab])) $tab = 'fin';

csrf_token();
ob_start();
page_header('Production Reports');
$pageHead = ob_get_clean();
if (session_status() === PHP_SESSION_ACTIVE) session_write_close();   // nothing below writes the session

$D = zpr_dataset($from, $to, $showMoney, $FLOORS);
$D['page'] = 'rep';
$D['tab']  = $tab;
$D['fin']  = zpr_finished();   // whole products: the Finished products tab
$D['prev'] = zpr_previous($from, $to, $FLOORS);
if (!$showMoney) $D['prev']['amt'] = 0;

echo $pageHead;
?>
<link rel="stylesheet" href="assets/css/zprep.css?v=3">
<style>
.zr-wrap .tabs{background:#fff;border:1px solid var(--line);border-bottom:0;border-radius:10px 10px 0 0}
.zr-wrap .rbox{background:#fff;border:1px solid var(--line);border-radius:0 0 10px 10px;margin-bottom:12px;box-shadow:0 1px 2px rgba(29,45,62,.04)}
</style>
<?php /* THE SKIN, OPTED IN — see assets/css/zskin.css. */ ?>
<div class="zskin">
<div class="zpr zr-wrap" id="zpr">
  <?php zpr_head('Production Reports', 'rep', $from, $to, [['production_dashboard.php', 'Dashboard', true]]); ?>

  <?php if ($D['err']): ?><div class="warnbox"><?= e($D['err']) ?></div><?php endif; ?>
  <?php if ($D['cut']): ?><div class="warnbox">This period has more than 120,000 entries — only the first 120,000 are shown. Choose a shorter period.</div><?php endif; ?>

  <div class="cards" id="zprK"></div>

  <div class="tabs" id="zprTabs" role="tablist">
    <?php foreach ($TABS as $k => $l): ?><button type="button" role="tab" data-tab="<?= e($k) ?>"><?= e($l) ?></button><?php endforeach; ?>
  </div>
  <div class="rbox">
    <div class="tbar">
      <input class="q" id="zprTQ" type="search" placeholder="Find in this table…" aria-label="Find in this table">
      <label id="zprStat" hidden style="display:inline-flex;gap:5px;align-items:center;font-size:12px;color:#5b6f86">Show
        <select id="zprStatS" style="height:30px;border:1px solid #dde3ea;border-radius:6px;font:inherit">
          <option value="active">Active entries</option><option value="cancelled">Cancelled only</option><option value="all">All (cancelled marked)</option></select></label>
      <span class="seg" id="zprFinG" hidden><button type="button" data-fg="line" class="on">Each order line</button><button type="button" data-fg="prod">By product</button><button type="button" data-fg="ord">By order</button></span>
      <span class="cnt" id="zprCnt"></span>
      <span class="sp"></span>
      <button type="button" class="btn" id="zprExpand" title="Open or close the ▸ detail of every row">▸ Expand / collapse all</button>
      <div class="cols"><button type="button" class="btn" id="zprColsB">☰ Columns</button>
        <div class="pop" id="zprColsP" hidden><div class="lst" id="zprColsL"></div><div class="pf"><button type="button" class="btn sm ghost" id="zprColsR">Show all</button></div></div></div>
      <button type="button" class="btn" id="zprCsv" title="Exactly the rows on screen">⇩ Excel (CSV)</button>
      <button type="button" class="btn" id="zprPrint">⎙ Print</button>
    </div>
    <div class="tw" id="zprT"><div style="padding:16px"><div class="skel"></div></div></div>
  </div>

  <p class="note"><b>Cancelled entries are not counted</b> in any view except <b>Every entry</b>, where they are shown faded with the reason.
    <b>Finished products</b> counts whole products (a duvet set is one, however many operations it went through); every other tab counts the work done on operations, which is what wages are paid on.
    Click ▸ to open the detail of a row, a column heading to sort, a name for its own panel. The CSV holds exactly what is on screen.</p>

  <div class="ov" id="zprOv"></div>
  <aside class="side" id="zprSide" aria-label="Detail"></aside>
</div>
</div><?php /* closes .zskin */ ?>
<script>window.ZPR_DATA = <?= zpr_json($D) ?>;</script>
<script src="assets/js/zprep.js?v=3"></script>
<?php page_footer(); ?>
