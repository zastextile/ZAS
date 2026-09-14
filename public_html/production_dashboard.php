<?php
/*
  PRODUCTION DASHBOARD — what happened, and what is late.
  ======================================================

  EVERY FIGURE COMES FROM THE SAME LEDGER, filtered the same way: active rows
  only, over one date range chosen at the top. A dashboard that counted
  cancelled entries while a report did not would disagree about the wage bill,
  and nobody could say which was right.

  COMPLETION IS MEASURED ON STITCHING, the last stage that actually runs.
  Measuring on cutting would call an order finished while nothing had been sewn.

  ORDERS ARE SORTED BY HOW FAR BEHIND THEY ARE, worst first — a dashboard sorted
  by date makes you hunt for the problem it was supposed to show you.
*/
require_once __DIR__ . '/includes/bootstrap.php';
require_login();
if (!is_admin() && !is_colleague() && !is_production_staff()) {
    http_response_code(403); exit('Production access required.');
}
require_once __DIR__ . '/includes/zprod.php';
zp_ensure_schema();

header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
header('Pragma: no-cache');
header('Expires: 0');

$showMoney = can_see_rates();
[$from, $to] = zp_range($_GET['from'] ?? null, $_GET['to'] ?? null);

$tot    = zp_totals($from, $to);
$daily  = zp_daily($from, $to);
$byWk   = zp_by_worker($from, $to);
$byDept = zp_by_department($from, $to);
$byProd = zp_by_product($from, $to);
$orders = zp_order_progress();

$maxDay = 0.0;
foreach ($daily as $d) $maxDay = max($maxDay, (float)$d['qty']);

page_header('Production Dashboard');
?>
<style>
.zd-wrap{max-width:1500px}
.zp-b{display:inline-flex;align-items:center;gap:6px;padding:7px 13px;border-radius:9px;border:1px solid #d9e0ea;
      background:#fff;color:#33465f;font-size:12.5px;font-weight:700;cursor:pointer;text-decoration:none;line-height:1.15}
.zp-b:hover{border-color:#0ea8c9;color:#0b7f99}
.zp-b.pri{background:#1d76e2;border-color:#1d76e2;color:#fff}.zp-b.pri:hover{background:#1667c9;color:#fff}
.zp-card{background:#fff;border:1px solid #e6ebf2;border-radius:13px;padding:16px 17px;margin-bottom:16px;
         box-shadow:0 1px 2px rgba(20,35,60,.04)}
.zp-card h2{margin:0 0 3px;font-size:15.5px;color:#152033}
.zp-card p.sub{margin:0 0 13px;font-size:12px;color:#8a97ab}
.zin{padding:7px 9px;border:1px solid #d9e0ea;border-radius:8px;font-size:12.5px;font-family:inherit;color:#152033;background:#fff}
.lab{display:block;font-size:10.5px;text-transform:uppercase;letter-spacing:.04em;color:#8a97ab;font-weight:800;margin-bottom:4px}
.kpis{display:grid;grid-template-columns:repeat(auto-fit,minmax(190px,1fr));gap:13px;margin-bottom:16px}
.kpi{background:#fff;border:1px solid #e6ebf2;border-radius:13px;padding:14px 16px;box-shadow:0 1px 2px rgba(20,35,60,.04)}
.kpi .t{font-size:10.5px;text-transform:uppercase;letter-spacing:.04em;color:#8a97ab;font-weight:800}
.kpi .v{font-size:26px;font-weight:800;color:#152033;font-variant-numeric:tabular-nums;line-height:1.15;margin-top:5px}
.kpi .u{font-size:11.5px;color:#8a97ab;font-weight:600}
.kpi.accent{background:linear-gradient(135deg,#123c57,#0b7f99);border-color:transparent}
.kpi.accent .t,.kpi.accent .u{color:rgba(255,255,255,.72)}
.kpi.accent .v{color:#fff}
table.zp-t{width:100%;border-collapse:collapse;font-size:12.5px}
table.zp-t th{text-align:left;font-size:10.5px;text-transform:uppercase;letter-spacing:.04em;color:#8a97ab;
              font-weight:800;padding:8px;border-bottom:1px solid #e6ebf2;white-space:nowrap}
table.zp-t td{padding:7px 8px;border-bottom:1px solid #f1f4f9}
table.zp-t tbody tr:hover{background:#fafcff}
.num{text-align:right;font-variant-numeric:tabular-nums;font-family:ui-monospace,Menlo,Consolas,monospace}
.code{font-family:ui-monospace,Menlo,Consolas,monospace;font-size:11.5px;color:#5a6b82}
.split{display:grid;grid-template-columns:minmax(0,1fr);gap:16px;align-items:start}
@media(min-width:1180px){.split{grid-template-columns:minmax(0,1.25fr) minmax(0,1fr)}}
.spark{display:flex;align-items:flex-end;gap:2px;height:90px;padding-top:6px}
.spark i{flex:1;min-width:2px;background:linear-gradient(180deg,#3ec7e0,#0ea8c9);border-radius:2px 2px 0 0;display:block}
.spark i.zero{background:#eef1f6}
.sparkx{display:flex;justify-content:space-between;font-size:10px;color:#8a97ab;margin-top:5px;font-family:ui-monospace,monospace}
.bar{height:8px;border-radius:5px;background:#eef1f6;overflow:hidden}
.bar i{display:block;height:100%;border-radius:5px;background:linear-gradient(90deg,#0ea8c9,#3ec7e0)}
.bar i.late{background:linear-gradient(90deg,#d97706,#f0a23a)}
.prow{padding:10px 0;border-top:1px solid #f6f8fc}
.prow:first-child{border-top:none}
.ptop{display:flex;justify-content:space-between;gap:10px;font-size:12.5px;margin-bottom:5px}
.pname{font-weight:700;color:#152033}
.psub{font-size:10.5px;color:#8a97ab}
.ppct{font-weight:800;font-variant-numeric:tabular-nums;color:#152033}
.behind{font-size:10.5px;color:#9a5710;margin-top:4px}
.empty{padding:24px;text-align:center;color:#8a97ab;font-size:12.5px;line-height:1.6}
.note{padding:11px 13px;border-radius:10px;font-size:12.5px;line-height:1.55;background:#eef6ff;border:1px solid #cfe3fb;color:#28527d}
</style>
<?php /* THE SKIN, OPTED IN. Every rule in assets/css/zskin.css is scoped
         under .zskin, so this one attribute is the whole of the restyle and
         removing it puts the page back exactly as it was. The page keeps its
         own .zp-card / .zp-t / .zp-b names; the skin maps onto them. */ ?>
<div class="zskin">

<div class="zd-wrap">
  <div style="display:flex;justify-content:space-between;align-items:flex-end;gap:14px;flex-wrap:wrap;margin-bottom:15px">
    <div>
      <h1 style="margin:0;font-size:20px;color:#152033">Production Dashboard</h1>
      <p style="margin:2px 0 0;font-size:12.5px;color:#8a97ab">
        <?= e(date('j M Y', strtotime($from))) ?> &ndash; <?= e(date('j M Y', strtotime($to))) ?>
        &middot; <?= number_format($tot['entries']) ?> entries</p>
    </div>
    <form method="get" style="display:flex;gap:8px;align-items:flex-end;flex-wrap:wrap">
      <div><span class="lab">From</span><input class="zin" type="date" name="from" value="<?= e($from) ?>"></div>
      <div><span class="lab">To</span><input class="zin" type="date" name="to" value="<?= e($to) ?>" max="<?= e(date('Y-m-d')) ?>"></div>
      <button class="zp-b pri">Show</button>
      <a class="zp-b" href="production_dashboard.php">Last 30 days</a>
      <a class="zp-b" href="production_reports.php">Reports</a>
    </form>
  </div>

  <div class="kpis">
    <?php foreach (zp_stages() as $s): ?>
      <div class="kpi">
        <div class="t"><?= e($s) ?></div>
        <div class="v"><?= number_format((float)$tot[$s]) ?></div>
        <div class="u">pieces</div>
      </div>
    <?php endforeach; ?>
    <?php if ($showMoney): ?>
      <div class="kpi accent">
        <div class="t">Wages</div>
        <div class="v"><?= number_format($tot['wage'], 0) ?></div>
        <div class="u">PKR in this period</div>
      </div>
    <?php endif; ?>
  </div>

  <div class="zp-card">
    <h2>Pieces per day</h2>
    <p class="sub">Every day in the range is drawn, including the empty ones &mdash;
       a gap means the floor stopped, and smoothing over it would hide that.</p>
    <?php if (!$daily || $maxDay <= 0): ?>
      <div class="empty">Nothing booked in this period.</div>
    <?php else: ?>
      <div class="spark">
        <?php foreach ($daily as $d): $h = $maxDay > 0 ? max(2, round($d['qty'] / $maxDay * 84)) : 2; ?>
          <i class="<?= $d['qty'] <= 0 ? 'zero' : '' ?>" style="height:<?= $h ?>px"
             title="<?= e(date('D j M', strtotime($d['date']))) ?> — <?= number_format($d['qty']) ?> pieces<?= $showMoney ? ', PKR ' . number_format($d['wage'], 2) : '' ?>"></i>
        <?php endforeach; ?>
      </div>
      <div class="sparkx">
        <span><?= e(date('j M', strtotime($from))) ?></span>
        <span>peak <?= number_format($maxDay) ?></span>
        <span><?= e(date('j M', strtotime($to))) ?></span>
      </div>
    <?php endif; ?>
  </div>

  <div class="split">
    <div class="zp-card">
      <h2>Orders, furthest behind first</h2>
      <p class="sub">Percentage is measured on <b>stitching</b> &mdash; the last stage that runs.
         Measuring on cutting would call an order finished before anything was sewn.</p>
      <?php if (!$orders): ?>
        <div class="empty">No order is switched on for production yet.</div>
      <?php else: foreach ($orders as $o): ?>
        <div class="prow">
          <div class="ptop">
            <div>
              <div class="pname"><?= e($o['product']) ?><?= trim((string)$o['size']) !== '' ? ' · ' . e($o['size']) : '' ?></div>
              <div class="psub"><?= e($o['pi_no']) ?> &middot; <?= e($o['customer'] ?: '—') ?>
                &middot; <?= number_format($o['ordered']) ?> ordered</div>
            </div>
            <div class="ppct"><?= number_format($o['pct'], 1) ?>%</div>
          </div>
          <div class="bar"><i class="<?= $o['pct'] < 50 ? 'late' : '' ?>" style="width:<?= max(0, min(100, $o['pct'])) ?>%"></i></div>
          <?php if ($o['behind']): ?>
            <div class="behind">Waiting on <?= e(implode(', ', array_slice($o['behind'], 0, 3))) ?><?= count($o['behind']) > 3 ? ' and ' . (count($o['behind']) - 3) . ' more' : '' ?></div>
          <?php endif; ?>
        </div>
      <?php endforeach; endif; ?>
    </div>

    <div>
      <?php if ($showMoney): ?>
      <div class="zp-card">
        <h2>By department</h2>
        <p class="sub">The department comes from the worker. Anyone without one is shown as
           "(no department)" rather than dropped &mdash; the parts have to add up to the whole.</p>
        <?php if (!$byDept): ?>
          <div class="empty">Nothing booked in this period.</div>
        <?php else: ?>
          <table class="zp-t">
            <thead><tr><th>Department</th><th class="num" style="width:60px">People</th>
              <th class="num" style="width:80px">Pieces</th><th class="num" style="width:100px">Wages</th></tr></thead>
            <tbody>
            <?php foreach ($byDept as $d): ?>
              <tr>
                <td><?= e($d['dept']) ?></td>
                <td class="num"><?= number_format((int)$d['workers']) ?></td>
                <td class="num"><?= number_format((float)$d['qty']) ?></td>
                <td class="num"><?= number_format((float)$d['wage'], 2) ?></td>
              </tr>
            <?php endforeach; ?>
            </tbody>
            <tfoot><tr style="background:#f7f9fc">
              <td colspan="3" style="font-weight:800;font-size:12px">Total</td>
              <td class="num" style="font-weight:800"><?= number_format($tot['wage'], 2) ?></td>
            </tr></tfoot>
          </table>
        <?php endif; ?>
      </div>
      <?php endif; ?>

      <div class="zp-card">
        <h2>Busiest workers</h2>
        <p class="sub">By <?= $showMoney ? 'wages' : 'pieces' ?> in this period.</p>
        <?php if (!$byWk): ?>
          <div class="empty">Nothing booked in this period.</div>
        <?php else: ?>
          <table class="zp-t">
            <thead><tr><th>Worker</th><th class="num" style="width:56px">Days</th>
              <th class="num" style="width:80px">Pieces</th>
              <?php if ($showMoney): ?><th class="num" style="width:100px">Wages</th><?php endif; ?></tr></thead>
            <tbody>
            <?php foreach (array_slice($byWk, 0, 12) as $w): ?>
              <tr>
                <td><?= e($w['worker_name'] ?? '(removed worker)') ?>
                    <div class="code" style="font-size:10px"><?= e($w['worker_code'] ?? '') ?><?= $w['department'] ? ' · ' . e($w['department']) : '' ?></div></td>
                <td class="num"><?= number_format((int)$w['days']) ?></td>
                <td class="num"><?= number_format((float)$w['qty']) ?></td>
                <?php if ($showMoney): ?><td class="num"><?= number_format((float)$w['wage'], 2) ?></td><?php endif; ?>
              </tr>
            <?php endforeach; ?>
            </tbody>
          </table>
          <?php if (count($byWk) > 12): ?>
            <p style="margin:10px 0 0;font-size:11.5px;color:#8a97ab">
              Showing 12 of <?= count($byWk) ?>. <a href="production_reports.php?r=worker&amp;from=<?= e($from) ?>&amp;to=<?= e($to) ?>">See them all</a>.</p>
          <?php endif; ?>
        <?php endif; ?>
      </div>
    </div>
  </div>

  <div class="zp-card">
    <h2>By product</h2>
    <?php if (!$byProd): ?>
      <div class="empty">Nothing booked in this period.</div>
    <?php else: ?>
      <table class="zp-t">
        <thead><tr><th>Product</th><th class="num" style="width:90px">Entries</th>
          <th class="num" style="width:100px">Pieces</th>
          <?php if ($showMoney): ?><th class="num" style="width:120px">Wages</th><?php endif; ?></tr></thead>
        <tbody>
        <?php foreach ($byProd as $p): ?>
          <tr>
            <td><?= e($p['product_name'] ?? '(removed product)') ?></td>
            <td class="num"><?= number_format((int)$p['entries']) ?></td>
            <td class="num"><?= number_format((float)$p['qty']) ?></td>
            <?php if ($showMoney): ?><td class="num"><?= number_format((float)$p['wage'], 2) ?></td><?php endif; ?>
          </tr>
        <?php endforeach; ?>
        </tbody>
      </table>
    <?php endif; ?>
  </div>

  <div class="note">
    <b>Cancelled entries are not counted anywhere on this page.</b>
    That decision is made in one place, in the ledger query, so this dashboard and every report
    always agree about the wage bill.
  </div>
</div>
</div><?php /* closes .zskin */ ?>
<?php page_footer(); ?>
