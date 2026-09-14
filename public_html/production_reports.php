<?php
/*
  PRODUCTION REPORTS — five views of the same ledger.
  ==================================================

      Worker      what each person made and earned
      Department  the six floors, side by side          <- asked for directly
      Product     where the wage bill actually goes
      Operation   which step costs what
      Daily       day by day, for payroll

  ONE SOURCE, ONE FILTER. Every view is a grouped query over zp_entries with
  status='active'. They cannot disagree with each other or with the dashboard,
  because there is only one ledger and one definition of what counts.

  EVERY VIEW EXPORTS THE EXACT ROWS ON SCREEN. Not a second query built for the
  file — the same array, written out. A CSV that quietly differs from the screen
  is how two people end up arguing from two numbers that were both "the report".
*/
require_once __DIR__ . '/includes/bootstrap.php';
require_login();
if (!is_admin() && !is_colleague() && !is_production_staff()) {
    http_response_code(403); exit('Production access required.');
}
require_once __DIR__ . '/includes/zprod.php';
zp_ensure_schema();

$showMoney = can_see_rates();
[$from, $to] = zp_range($_GET['from'] ?? null, $_GET['to'] ?? null);
$report = (string)($_GET['r'] ?? 'worker');

$REPORTS = [
    'worker'  => 'By worker',
    'dept'    => 'By department',
    'product' => 'By product',
    'op'      => 'By operation',
    'daily'   => 'Day by day',
    'entries' => 'Every entry',
];
if (!isset($REPORTS[$report])) $report = 'worker';

/* ------------------------------------------------------------------
   BUILD THE ROWS — once. The screen and the CSV both render THIS.
   ------------------------------------------------------------------ */
function zr_build(string $report, string $from, string $to, bool $money): array {
    $money_ = fn(string $label) => $money ? [$label] : [];
    switch ($report) {
        case 'dept':
            $rows = [];
            foreach (zp_by_department($from, $to) as $d) {
                $r = ['Department' => $d['dept'], 'Workers' => (int)$d['workers'], 'Pieces' => (float)$d['qty']];
                if ($money) $r['Wages (PKR)'] = (float)$d['wage'];
                $rows[] = $r;
            }
            return ['cols' => array_merge(['Department', 'Workers', 'Pieces'], $money_('Wages (PKR)')),
                    'num'  => array_merge(['Workers', 'Pieces'], $money_('Wages (PKR)')),
                    'tot'  => array_merge(['Pieces'], $money_('Wages (PKR)')), 'rows' => $rows];

        case 'product':
            $rows = [];
            foreach (zp_by_product($from, $to) as $p) {
                $r = ['Product' => $p['product_name'] ?? '(removed product)',
                      'Entries' => (int)$p['entries'], 'Pieces' => (float)$p['qty']];
                if ($money) $r['Wages (PKR)'] = (float)$p['wage'];
                $rows[] = $r;
            }
            return ['cols' => array_merge(['Product', 'Entries', 'Pieces'], $money_('Wages (PKR)')),
                    'num'  => array_merge(['Entries', 'Pieces'], $money_('Wages (PKR)')),
                    'tot'  => array_merge(['Entries', 'Pieces'], $money_('Wages (PKR)')), 'rows' => $rows];

        case 'op':
            $rows = [];
            foreach (zp_by_operation($from, $to) as $o) {
                $r = ['Part' => $o['part_name'], 'Operation' => $o['operation_name'],
                      'Stage' => $o['stage'], 'Pieces' => (float)$o['qty']];
                if ($money) $r['Wages (PKR)'] = (float)$o['wage'];
                $rows[] = $r;
            }
            return ['cols' => array_merge(['Part', 'Operation', 'Stage', 'Pieces'], $money_('Wages (PKR)')),
                    'num'  => array_merge(['Pieces'], $money_('Wages (PKR)')),
                    'tot'  => array_merge(['Pieces'], $money_('Wages (PKR)')), 'rows' => $rows];

        case 'daily':
            $rows = [];
            foreach (zp_daily($from, $to) as $d) {
                $r = ['Date' => $d['date'], 'Day' => date('D', strtotime($d['date']))];
                foreach (zp_stages() as $s) $r[$s] = (float)$d[$s];
                $r['Total Pieces'] = (float)$d['qty'];
                if ($money) $r['Wages (PKR)'] = (float)$d['wage'];
                $rows[] = $r;
            }
            $stageCols = zp_stages();
            return ['cols' => array_merge(['Date', 'Day'], $stageCols, ['Total Pieces'], $money_('Wages (PKR)')),
                    'num'  => array_merge($stageCols, ['Total Pieces'], $money_('Wages (PKR)')),
                    'tot'  => array_merge($stageCols, ['Total Pieces'], $money_('Wages (PKR)')), 'rows' => $rows];

        case 'entries':
            $rows = [];
            /* EVERY ENTRY, CANCELLED ONES INCLUDED AND MARKED. This is the audit
               view: the one report where seeing what was undone is the point. */
            foreach (zp_entries(['from' => $from, 'to' => $to], 2000) as $e) {
                $r = ['Date' => $e['entry_date'],
                      'Worker' => ($e['worker_name'] ?? '(removed)') . ' (' . ($e['worker_code'] ?? '?') . ')',
                      'Order' => $e['pi_no'] ?? '—',
                      'Product' => $e['product_name'] ?? '(removed product)',
                      'Part' => $e['part_name'] ?? '—',
                      'Operation' => $e['operation_name'] ?? '(removed operation)',
                      'Stage' => $e['stage'], 'Qty' => (float)$e['qty']];
                if ($money) { $r['Rate'] = (float)$e['rate_applied']; $r['Amount (PKR)'] = (float)$e['amount']; }
                $r['Status'] = $e['status'] === 'active' ? 'Active' : 'CANCELLED';
                $r['Reason'] = (string)($e['cancel_reason'] ?? '');
                $rows[] = $r;
            }
            return ['cols' => array_merge(['Date', 'Worker', 'Order', 'Product', 'Part', 'Operation', 'Stage', 'Qty'],
                                          $money ? ['Rate', 'Amount (PKR)'] : [], ['Status', 'Reason']),
                    'num'  => array_merge(['Qty'], $money ? ['Rate', 'Amount (PKR)'] : []),
                    'tot'  => [], 'rows' => $rows];

        default:  // worker
            $rows = [];
            foreach (zp_by_worker($from, $to) as $w) {
                $r = ['Worker' => $w['worker_name'] ?? '(removed worker)',
                      'Code' => $w['worker_code'] ?? '',
                      'Department' => $w['department'] ?: '(none)',
                      'Days Worked' => (int)$w['days'], 'Entries' => (int)$w['entries'],
                      'Pieces' => (float)$w['qty']];
                if ($money) {
                    $r['Wages (PKR)'] = (float)$w['wage'];
                    /* AVERAGE PER DAY IS COMPUTED, NOT STORED — so it can never
                       be stale against the two numbers beside it. */
                    $r['Avg / Day'] = (int)$w['days'] > 0 ? round((float)$w['wage'] / (int)$w['days'], 2) : 0.0;
                }
                $rows[] = $r;
            }
            return ['cols' => array_merge(['Worker', 'Code', 'Department', 'Days Worked', 'Entries', 'Pieces'],
                                          $money ? ['Wages (PKR)', 'Avg / Day'] : []),
                    'num'  => array_merge(['Days Worked', 'Entries', 'Pieces'], $money ? ['Wages (PKR)', 'Avg / Day'] : []),
                    'tot'  => array_merge(['Days Worked', 'Entries', 'Pieces'], $money ? ['Wages (PKR)'] : []), 'rows' => $rows];
    }
}

$data = zr_build($report, $from, $to, $showMoney);

/* ---- the CSV writes THIS array, not a second query ---- */
if (($_GET['export'] ?? '') === 'csv') {
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="' . $report . '_' . $from . '_to_' . $to . '.csv"');
    header('Cache-Control: no-store');
    $out = fopen('php://output', 'w');
    fwrite($out, "\xEF\xBB\xBF");
    fputcsv($out, zp_csv_row([$REPORTS[$report] . ' — ' . $from . ' to ' . $to]));
    fputcsv($out, []);
    fputcsv($out, $data['cols']);
    /* worker, product and part names all reach this file, and all of them are
       typed by somebody. A name beginning with = is a formula to Excel. */
    foreach ($data['rows'] as $r) {
        fputcsv($out, zp_csv_row(array_map(fn($c) => $r[$c] ?? '', $data['cols'])));
    }
    if ($data['tot']) {
        $totRow = [];
        foreach ($data['cols'] as $c) {
            $totRow[] = in_array($c, $data['tot'], true)
                ? array_sum(array_map(fn($r) => (float)($r[$c] ?? 0), $data['rows']))
                : ($c === $data['cols'][0] ? 'TOTAL' : '');
        }
        /* guarded as well. Its cells are 'TOTAL' and numbers today, but an
           unguarded fputcsv in the file is an exception the next person copies. */
        fputcsv($out, zp_csv_row($totRow));
    }
    fclose($out);
    exit;
}

header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
header('Pragma: no-cache');
header('Expires: 0');

$qs = 'from=' . urlencode($from) . '&to=' . urlencode($to);
page_header('Production Reports');
?>
<style>
.zr-wrap{max-width:1500px}
.zp-b{display:inline-flex;align-items:center;gap:6px;padding:7px 13px;border-radius:9px;border:1px solid #d9e0ea;
      background:#fff;color:#33465f;font-size:12.5px;font-weight:700;cursor:pointer;text-decoration:none;line-height:1.15}
.zp-b:hover{border-color:#0ea8c9;color:#0b7f99}
.zp-b.pri{background:#1d76e2;border-color:#1d76e2;color:#fff}.zp-b.pri:hover{background:#1667c9;color:#fff}
.zp-b.on{background:#0ea8c9;border-color:#0ea8c9;color:#fff}
.zp-card{background:#fff;border:1px solid #e6ebf2;border-radius:13px;padding:16px 17px;margin-bottom:16px;
         box-shadow:0 1px 2px rgba(20,35,60,.04)}
.zin{padding:7px 9px;border:1px solid #d9e0ea;border-radius:8px;font-size:12.5px;font-family:inherit;color:#152033;background:#fff}
.lab{display:block;font-size:10.5px;text-transform:uppercase;letter-spacing:.04em;color:#8a97ab;font-weight:800;margin-bottom:4px}
.tabs{display:flex;gap:7px;flex-wrap:wrap;margin-bottom:15px}
table.zp-t{width:100%;border-collapse:collapse;font-size:12.5px}
table.zp-t th{text-align:left;font-size:10.5px;text-transform:uppercase;letter-spacing:.04em;color:#8a97ab;
              font-weight:800;padding:8px;border-bottom:1px solid #e6ebf2;white-space:nowrap}
table.zp-t td{padding:7px 8px;border-bottom:1px solid #f1f4f9}
table.zp-t tbody tr:hover{background:#fafcff}
table.zp-t tr.canc td{opacity:.55}
.num{text-align:right;font-variant-numeric:tabular-nums;font-family:ui-monospace,Menlo,Consolas,monospace}
.empty{padding:28px;text-align:center;color:#8a97ab;font-size:12.5px;line-height:1.6}
.note{padding:11px 13px;border-radius:10px;font-size:12.5px;line-height:1.55;background:#eef6ff;border:1px solid #cfe3fb;color:#28527d}
</style>
<?php /* THE SKIN, OPTED IN. Every rule in assets/css/zskin.css is scoped
         under .zskin, so this one attribute is the whole of the restyle and
         removing it puts the page back exactly as it was. The page keeps its
         own .zp-card / .zp-t / .zp-b names; the skin maps onto them. */ ?>
<div class="zskin">

<div class="zr-wrap">
  <div style="display:flex;justify-content:space-between;align-items:flex-end;gap:14px;flex-wrap:wrap;margin-bottom:14px">
    <div>
      <h1 style="margin:0;font-size:20px;color:#152033">Production Reports</h1>
      <p style="margin:2px 0 0;font-size:12.5px;color:#8a97ab">
        <?= e(date('j M Y', strtotime($from))) ?> &ndash; <?= e(date('j M Y', strtotime($to))) ?>
        &middot; <?= count($data['rows']) ?> row<?= count($data['rows']) === 1 ? '' : 's' ?></p>
    </div>
    <form method="get" style="display:flex;gap:8px;align-items:flex-end;flex-wrap:wrap">
      <input type="hidden" name="r" value="<?= e($report) ?>">
      <div><span class="lab">From</span><input class="zin" type="date" name="from" value="<?= e($from) ?>"></div>
      <div><span class="lab">To</span><input class="zin" type="date" name="to" value="<?= e($to) ?>" max="<?= e(date('Y-m-d')) ?>"></div>
      <button class="zp-b pri">Show</button>
      <a class="zp-b" href="production_reports.php?r=<?= e($report) ?>&amp;<?= $qs ?>&amp;export=csv">Download CSV</a>
      <a class="zp-b" href="production_dashboard.php">Dashboard</a>
    </form>
  </div>

  <div class="tabs">
    <?php foreach ($REPORTS as $k => $label): ?>
      <a class="zp-b <?= $k === $report ? 'on' : '' ?>"
         href="production_reports.php?r=<?= e($k) ?>&amp;<?= $qs ?>"><?= e($label) ?></a>
    <?php endforeach; ?>
  </div>

  <div class="zp-card">
    <?php if (!$data['rows']): ?>
      <div class="empty">
        Nothing booked between <?= e(date('j M Y', strtotime($from))) ?>
        and <?= e(date('j M Y', strtotime($to))) ?>.<br>
        Try a wider range, or book a day on <a href="production_entry.php">Daily Production Entry</a>.
      </div>
    <?php else: ?>
      <div style="overflow-x:auto">
      <table class="zp-t">
        <thead><tr>
          <?php foreach ($data['cols'] as $c): ?>
            <th class="<?= in_array($c, $data['num'], true) ? 'num' : '' ?>"><?= e($c) ?></th>
          <?php endforeach; ?>
        </tr></thead>
        <tbody>
        <?php foreach ($data['rows'] as $r): $canc = ($r['Status'] ?? '') === 'CANCELLED'; ?>
          <tr class="<?= $canc ? 'canc' : '' ?>">
            <?php foreach ($data['cols'] as $c):
              $v = $r[$c] ?? '';
              $isNum = in_array($c, $data['num'], true);
            ?>
              <td class="<?= $isNum ? 'num' : '' ?>"><?php
                if ($isNum) echo is_float($v) ? number_format((float)$v, 2) : number_format((float)$v);
                else echo e((string)$v);
              ?></td>
            <?php endforeach; ?>
          </tr>
        <?php endforeach; ?>
        </tbody>
        <?php if ($data['tot']): ?>
        <tfoot><tr style="background:#f7f9fc">
          <?php foreach ($data['cols'] as $i => $c): ?>
            <td class="<?= in_array($c, $data['num'], true) ? 'num' : '' ?>" style="font-weight:800;font-size:12px"><?php
              if (in_array($c, $data['tot'], true)) {
                  $sum = array_sum(array_map(fn($r) => (float)($r[$c] ?? 0), $data['rows']));
                  echo number_format($sum, 2);
              } elseif ($i === 0) echo 'TOTAL';
            ?></td>
          <?php endforeach; ?>
        </tr></tfoot>
        <?php endif; ?>
      </table>
      </div>
    <?php endif; ?>
  </div>

  <div class="note">
    <?php if ($report === 'entries'): ?>
      <b>This is the audit view, and it is the only one that shows cancelled entries.</b>
      They are marked and faded, with the reason beside them. Every other report counts active rows only.
    <?php else: ?>
      <b>Cancelled entries are not counted.</b> That decision is made once, in the ledger query, so every
      report and the dashboard always agree. To see what was cancelled and why, open <b>Every entry</b>.
    <?php endif; ?>
    The CSV contains exactly the rows on this screen &mdash; the same array, written out, not a second query.
  </div>
</div>
</div><?php /* closes .zskin */ ?>
<?php page_footer(); ?>
