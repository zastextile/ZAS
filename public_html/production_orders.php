<?php
/*
  ORDER STATUS — every order in production, and one order in detail
  ==================================================================
  (owner, 22 Nov 2026: "report of each PO … status of multiple orders … and
  individual order status, professional, SAP Fiori / Oracle way")

  THE LIST: one row per PO — Cut %, Stitch %, Pack % (and any other stage),
  overall %, wages, last day worked, status. Filters, search, sorting and the
  Excel file are done in the browser by the shared list engine (zflist.js).

  ONE ORDER: its header figures, a size × stage grid (each product line, each
  stage, with the slowest part named), and the order day by day.

  HOW PROGRESS IS MEASURED — the same rule as the booking limits: a stage's
  pieces are the pieces that have been through EVERY operation of it. Overall
  is the last stage the order goes through (normally Packing).

  STATUS: Not started · In progress · Stalled (nothing booked for 7 days) ·
  Done. No due dates — the owner said the proforma delivery date is not
  reliable enough to call an order late.

  READ ONLY. Nothing on this page writes.
*/
require_once __DIR__ . '/includes/bootstrap.php';
require_login();
if (!is_admin() && !is_colleague() && !is_production_staff()) {
    http_response_code(403); exit('Production access required.');
}
require_once __DIR__ . '/includes/zprod.php';
zp_ensure_schema();

$showMoney = can_see_rates();
$pfId = (int)($_GET['order'] ?? 0);

/* stage colours — the same rule as Daily Production Entry */
function zo_col(string $st): array {
    $l = mb_strtolower($st);
    if (str_contains($l, 'cut')) return ['#fff4e2', '#9a5710', '#e9a23b'];
    if (str_contains($l, 'stitch') || str_contains($l, 'sew') || str_contains($l, 'singer') || str_contains($l, 'overlock')) return ['#e8f1fc', '#0a4f96', '#3b82d6'];
    if (str_contains($l, 'pack')) return ['#e8f6ec', '#1f6b35', '#2f9e58'];
    $pal = [['#f1ecfb', '#5a3fa8', '#8b6fd6'], ['#e3f5f7', '#0e6170', '#2aa1b3'], ['#fbe9f1', '#9d174d', '#d0558a'], ['#eef2f6', '#33465f', '#7c8da3']];
    return $pal[abs(crc32($l)) % count($pal)];
}
$q0 = fn($v) => number_format((float)$v, 0);
$ST = ['new' => ['Not started', 'p-grey'], 'run' => ['In progress', 'p-draft'], 'stalled' => ['Stalled', 'p-sub'], 'done' => ['Done', 'p-ok'],
       'nolink' => ['Lines not linked', 'p-red']];

if ($pfId) {
    $all = zp_order_status($pfId);
    $O = $all[$pfId] ?? null;
    $days = [];
    if ($O) {
        try {
            $st = db()->prepare("SELECT entry_date d, stage, SUM(qty) q, SUM(amount) w FROM zp_entries
                                 WHERE status='active' AND proforma_id=? GROUP BY entry_date, stage ORDER BY entry_date");
            $st->execute([$pfId]);
            foreach ($st->fetchAll() as $r) {
                $days[$r['d']] ??= ['st' => [], 'w' => 0.0];
                $days[$r['d']]['st'][(string)$r['stage']] = (float)$r['q'];
                $days[$r['d']]['w'] += (float)$r['w'];
            }
        } catch (Throwable $e) {}
    }
} else {
    $all = zp_order_status();
}

/* the stages that appear on any order shown, in flow order */
$STAGES = [];
foreach ($all as $o) foreach ($o['stages'] as $pos => $sg) $STAGES[$pos] = $sg['name'];
ksort($STAGES);

page_header($pfId && !empty($O) ? 'Order ' . $O['pi_no'] : 'Order Status');
?>
<link rel="stylesheet" href="assets/css/zf.css?v=4">
<script src="assets/js/zflist.js?v=3"></script>
<style>
.zf.os{min-width:0;max-width:100%}
.zf.os .zf-card,.zf.os .os-obj{min-width:0;max-width:100%}
.zf.os .zf-tw{max-width:100%;overflow-x:auto}
.zf.os .zf-t th{text-transform:none !important;letter-spacing:0 !important;font-size:12px !important}
/* the order page's tables fit their card on a laptop (app.css says 980px for every table) */
.zf.os #osGrid,.zf.os #osDays{min-width:560px}
.zf.os .pb{display:flex;align-items:center;gap:6px;min-width:110px;justify-content:flex-end}
.zf.os .pb i{display:block;flex:1;max-width:80px;height:6px;border-radius:3px;background:#e9edf2;overflow:hidden}
.zf.os .pb i b{display:block;height:100%;border-radius:3px}
.zf.os .pb span{font-variant-numeric:tabular-nums;min-width:42px;text-align:right}
.zf.os .os-obj{background:#fff;border:1px solid var(--line);border-radius:6px;padding:10px 12px;margin-bottom:10px}
.zf.os .os-obj h2{margin:0;font-size:17px;font-weight:600;color:var(--ink);display:flex;gap:8px;align-items:center;flex-wrap:wrap}
.zf.os .os-obj .zf-sub{margin:1px 0 8px}
.zf.os .zf-kpi{cursor:default}
.zf.os .zf-kpis{margin-bottom:0}
.zf.os .sec{display:flex;justify-content:space-between;align-items:center;gap:8px;padding:9px 12px;border-bottom:1px solid var(--line)}
.zf.os .sec h3{margin:0;font-size:14px;font-weight:600}
.zf.os .sec .s2{color:var(--mut);font-size:12px}
.zf.os td.g{white-space:nowrap}
.zf.os .cell{font-variant-numeric:tabular-nums;white-space:nowrap}
.zf.os .cell small{color:var(--mut)}
.zf.os .beh{color:#9a5710;font-size:11.5px}
.zf.os .chart{display:flex;align-items:flex-end;gap:3px;height:110px;padding:10px 12px 0;overflow-x:auto}
.zf.os .chart .c{flex:1 0 14px;max-width:34px;display:flex;flex-direction:column-reverse;height:100%}
.zf.os .chart .c i{display:block;width:100%}
.zf.os .chart .c i:last-child{border-radius:2px 2px 0 0}
.zf.os .cx{display:flex;justify-content:space-between;padding:3px 12px 8px;font-size:11px;color:var(--mut)}
.zf.os .leg{display:flex;gap:10px;flex-wrap:wrap;font-size:11.5px;color:var(--mut)}
.zf.os .leg b{display:inline-block;width:10px;height:10px;border-radius:2px;margin-right:4px;vertical-align:-1px}
.zf.os .note{font-size:12px;color:var(--mut);padding:8px 12px}
@media(max-width:760px){.zf.os .zf-kpis{grid-template-columns:minmax(0,1fr) minmax(0,1fr);gap:6px}.zf.os .zf-kpi b{font-size:16px}}
</style>
<div class="zf os">
<?php if (!$pfId): ?>
  <div class="zf-hd">
    <div><h1>Order status</h1>
      <div class="zf-sub">Every order in production, stage by stage. A stage counts the pieces that have been through every one of its operations; overall is the last stage. Click an order for its detail.</div></div>
    <div style="display:flex;gap:6px"><a class="zf-btn" href="production_dashboard.php">Dashboard</a><a class="zf-btn" href="production_reports.php">Reports</a></div>
  </div>
  <div id="osList"></div>
  <script>
  (function(){
    var Z = window.ZF, esc = Z.esc;
    var MONEY = <?= $showMoney ? 'true' : 'false' ?>;
    var STAGES = <?= json_encode(array_values(array_map(fn($p, $n) => ['p' => $p, 'n' => $n, 'c' => zo_col($n)], array_keys($STAGES), $STAGES)), JSON_HEX_TAG | JSON_HEX_AMP) ?>;
    var ST = <?= json_encode($ST) ?>;
    var R = <?= json_encode(array_values(array_map(function ($o) {
        $sp = [];
        foreach ($o['stages'] as $pos => $sg) $sp[$pos] = $sg['need'] > 0 ? round($sg['done'] / $sg['need'] * 100, 1) : null;
        return ['id' => $o['id'], 'no' => $o['pi_no'], 'cust' => $o['customer'], 'lines' => count($o['lines']),
                'ordered' => round($o['ordered'], 2), 'sp' => $sp, 'pct' => $o['pct'], 'w' => round($o['wages'], 2), 'pcs' => round($o['pcs'], 2),
                'first' => $o['first'], 'last' => $o['last'], 'status' => $o['status'],
                'prods' => implode(', ', array_unique(array_map(fn($l) => $l['product'], $o['lines'])))];
    }, $all)), JSON_HEX_TAG | JSON_HEX_AMP) ?>;
    function bar(p, c){
      if (p == null) return '<span style="color:#9aa8b6">—</span>';
      return '<div class="pb"><i><b style="width:' + Math.min(100, p) + '%;background:' + c + '"></b></i><span>' + p.toFixed(p >= 99.95 || p === 0 ? 0 : 1) + '%</span></div>';
    }
    var cols = [
      { k: 'no', label: 'Order', sort: function(r){ return r.id; }, html: function(r){ return '<a class="lk" href="production_orders.php?order=' + r.id + '">' + esc(r.no) + '</a><div class="s2">' + r.lines + ' line' + (r.lines === 1 ? '' : 's') + '</div>'; } },
      { k: 'cust', label: 'Customer', sort: function(r){ return r.cust.toLowerCase(); }, html: function(r){ return '<span class="nw" title="' + esc(r.cust) + '">' + esc(r.cust || '—') + '</span><div class="s2 nw" title="' + esc(r.prods) + '">' + esc(r.prods) + '</div>'; } },
      { k: 'ordered', label: 'Ordered', num: 1, sort: function(r){ return r.ordered; }, html: function(r){ return Z.n0(r.ordered); } }
    ];
    STAGES.forEach(function(s){
      cols.push({ k: 's' + s.p, label: s.n, num: 1, sort: function(r){ return r.sp[s.p] == null ? -1 : r.sp[s.p]; }, html: function(r){ return bar(r.sp[s.p], s.c[2]); } });
    });
    cols.push({ k: 'pct', label: 'Overall', num: 1, sort: function(r){ return r.pct; }, html: function(r){ return '<b>' + r.pct.toFixed(r.pct >= 99.95 || r.pct === 0 ? 0 : 1) + '%</b>'; } });
    if (MONEY) cols.push({ k: 'w', label: 'Wages', num: 1, sort: function(r){ return r.w; }, html: function(r){ return r.w ? Z.n0(r.w) : '<span style="color:#9aa8b6">—</span>'; } });
    cols.push({ k: 'last', label: 'Last work', sort: function(r){ return r.last; }, html: function(r){ return r.last ? '<span style="white-space:nowrap">' + esc(Z.nice(r.last)) + '</span>' : '<span style="color:#9aa8b6">—</span>'; } });
    cols.push({ k: 'status', label: 'Status', sort: function(r){ return r.status; }, html: function(r){ var s = ST[r.status];
      return '<span class="zf-pill ' + s[1] + '">' + s[0] + '</span>' + (r.status === 'nolink' ? '<div class="s2"><a href="line_links.php" onclick="event.stopPropagation()">Link its lines to products →</a></div>' : ''); } });
    var csv = [['Order', function(r){ return r.no; }], ['Customer', function(r){ return r.cust; }], ['Products', function(r){ return r.prods; }], ['Lines', function(r){ return r.lines; }], ['Ordered', function(r){ return r.ordered; }]];
    STAGES.forEach(function(s){ csv.push([s.n + ' %', function(r){ return r.sp[s.p] == null ? '' : r.sp[s.p]; }]); });
    csv.push(['Overall %', function(r){ return r.pct; }]);
    if (MONEY) csv.push(['Wages', function(r){ return r.w.toFixed(2); }]);
    csv.push(['First work', function(r){ return r.first; }], ['Last work', function(r){ return r.last; }], ['Status', function(r){ return ST[r.status][0]; }]);
    function cnt(s){ return function(rows){ var n = rows.filter(function(r){ return r.status === s || (s === 'new' && r.status === 'nolink'); }).length; return [String(n), { run: 'work booked in the last 7 days', stalled: 'started, nothing for 7 days', new: 'switched on, nothing booked', done: 'last stage finished' }[s]]; }; }
    ZFList({
      root: 'osList', rows: R, cols: cols, sort: 'pct', dir: 1, csv: csv, csvName: 'order_status', empty: 'No order is switched on for production yet.',
      searchHint: 'Search order, customer, product…',
      hay: function(r){ return r.no + ' ' + r.cust + ' ' + r.prods; },
      href: function(r){ return 'production_orders.php?order=' + r.id; },
      kpis: [
        { id: 'run', label: 'In progress', cls: 'k-blue', test: function(r){ return r.status === 'run'; }, value: cnt('run') },
        { id: 'stalled', label: 'Stalled', cls: 'k-amber', test: function(r){ return r.status === 'stalled'; }, value: cnt('stalled') },
        { id: 'new', label: 'Not started', cls: 'k-grey', test: function(r){ return r.status === 'new' || r.status === 'nolink'; }, value: cnt('new') },
        { id: 'done', label: 'Done', cls: 'k-green', test: function(r){ return r.status === 'done'; }, value: cnt('done') }
      ],
      segs: [['', 'All'], ['open', 'Open', function(r){ return r.status !== 'done'; }], ['done', 'Done', function(r){ return r.status === 'done'; }]],
      selects: [{ k: 'cust', label: 'Customer' }],
      foot: function(rows){
        return cols.map(function(c){
          if (c.k === 'no') return '<td>Total · ' + rows.length + '</td>';
          if (c.k === 'ordered') return '<td class="n">' + Z.n0(rows.reduce(function(t, r){ return t + r.ordered; }, 0)) + '</td>';
          if (c.k === 'w') return '<td class="n">' + Z.n0(rows.reduce(function(t, r){ return t + r.w; }, 0)) + '</td>';
          return '<td></td>';
        }).join('');
      }
    });
  })();
  </script>

<?php elseif (!empty($O['nolink'])): ?>
  <div class="zf-hd"><div><h1>Order status</h1></div><a class="zf-btn" href="production_orders.php">← All orders</a></div>
  <div class="zf-card"><div class="zf-none"><b><?= e($O['pi_no']) ?> · <?= e($O['customer'] ?: 'no customer') ?></b> is switched on for production, but none of its lines is linked to a product with parts, so the floor has nothing to book on it.<br><br>
    <a class="zf-btn pri" href="line_links.php">Link its lines to products</a> <a class="zf-btn" href="proforma.php?id=<?= $pfId ?>">Open the proforma</a></div></div>

<?php elseif (empty($O)): ?>
  <div class="zf-hd"><div><h1>Order status</h1></div><a class="zf-btn" href="production_orders.php">← All orders</a></div>
  <div class="zf-card"><div class="zf-none">That order is not switched on for production, or has no lines that resolve to products.</div></div>

<?php else:
  $s = $ST[$O['status']];
  $lastPos = $O['stages'] ? array_key_last($O['stages']) : null; ?>
  <div class="zf-hd">
    <div><h1>Order status</h1><div class="zf-sub">Stage by stage: pieces that have been through every operation of that stage.</div></div>
    <div style="display:flex;gap:6px;flex-wrap:wrap">
      <a class="zf-btn" href="production_orders.php">← All orders</a>
      <?php if (is_admin() || is_colleague()): ?><a class="zf-btn" href="production_order_rates.php?order=<?= $pfId ?>">Order rates</a><?php endif; ?>
    </div>
  </div>

  <div class="os-obj">
    <h2><?= e($O['pi_no']) ?> · <?= e($O['customer'] ?: 'no customer') ?> <span class="zf-pill <?= $s[1] ?>"><?= $s[0] ?></span></h2>
    <div class="zf-sub"><?= count($O['lines']) ?> line<?= count($O['lines']) === 1 ? '' : 's' ?> · <?= $q0($O['ordered']) ?> ordered
      <?= $O['first'] ? ' · worked ' . e(date('j M', strtotime($O['first']))) . ' – ' . e(date('j M Y', strtotime($O['last']))) : ' · nothing booked yet' ?></div>
    <div class="zf-kpis">
      <?php foreach ($O['stages'] as $pos => $sg): $c = zo_col($sg['name']); $p = $sg['need'] > 0 ? $sg['done'] / $sg['need'] * 100 : 0; ?>
        <div class="zf-kpi" style="border-left-color:<?= $c[2] ?>"><span><?= e($sg['name']) ?><?= $pos === $lastPos ? ' · overall' : '' ?></span>
          <b style="color:<?= $c[1] ?>"><?= number_format($p, $p >= 99.95 || $p == 0 ? 0 : 1) ?>%</b>
          <small><?= $q0($sg['done']) ?> of <?= $q0($sg['need']) ?> pieces · <?= $q0(max(0, $sg['need'] - $sg['done'])) ?> left</small></div>
      <?php endforeach; ?>
      <?php if ($showMoney): ?>
        <div class="zf-kpi k-green"><span>Wages so far</span><b>PKR <?= $q0($O['wages']) ?></b><small><?= $q0($O['pcs']) ?> operation-pieces booked</small></div>
      <?php endif; ?>
    </div>
  </div>

  <div class="zf-card">
    <div class="sec"><h3>Size × stage</h3>
      <span style="display:flex;gap:6px;align-items:center"><span class="s2">pieces through every operation / pieces needed</span>
      <button type="button" class="zf-btn sm" onclick="osCsv('osGrid','<?= e($O['pi_no']) ?>_size_stage')">Export Excel</button></span></div>
    <div class="zf-tw" style="max-height:none">
      <table class="zf-t" id="osGrid">
        <thead><tr><th>Product · size</th><th class="n">Ordered</th>
          <?php foreach ($O['stages'] as $sg): ?><th class="n"><?= e($sg['name']) ?></th><?php endforeach; ?>
          <th>Waiting on</th></tr></thead>
        <tbody>
        <?php foreach ($O['lines'] as $l): ?>
          <tr style="cursor:default">
            <td><?= e($l['product']) ?><?php if (trim($l['size']) !== ''): ?><div class="s2"><?= e($l['size']) ?></div><?php endif; ?></td>
            <td class="n"><?= $q0($l['ordered']) ?></td>
            <?php foreach ($O['stages'] as $pos => $osg): $sg = $l['stages'][$pos] ?? null; $c = zo_col($osg['name']); ?>
              <td class="n"><?php if (!$sg): ?><span style="color:#9aa8b6">—</span><?php else: $p = $sg['need'] > 0 ? $sg['done'] / $sg['need'] * 100 : 0; ?>
                <div class="pb"><i><b style="width:<?= min(100, $p) ?>%;background:<?= $c[2] ?>"></b></i><span><?= number_format($p, $p >= 99.95 || $p == 0 ? 0 : 1) ?>%</span></div>
                <div class="cell"><small><?= $q0($sg['done']) ?> / <?= $q0($sg['need']) ?></small></div>
              <?php endif; ?></td>
            <?php endforeach; ?>
            <td><?= $l['behind'] !== '' ? '<span class="beh">' . e($l['behind']) . '</span>' : ($l['pct'] >= 99.95 ? '<span class="zf-pill p-ok">Done</span>' : '<span style="color:#9aa8b6">—</span>') ?></td>
          </tr>
        <?php endforeach; ?>
        </tbody>
        <tfoot><tr><td>Total</td><td class="n"><?= $q0($O['ordered']) ?></td>
          <?php foreach ($O['stages'] as $sg): $p = $sg['need'] > 0 ? $sg['done'] / $sg['need'] * 100 : 0; ?>
            <td class="n"><?= number_format($p, $p >= 99.95 || $p == 0 ? 0 : 1) ?>%<div class="cell"><small><?= $q0($sg['done']) ?> / <?= $q0($sg['need']) ?></small></div></td>
          <?php endforeach; ?><td></td></tr></tfoot>
      </table>
    </div>
    <div class="note">Pieces are counted per part: a set with a sheet and two pillow covers needs three pieces of work at each stage. "Waiting on" names the slowest part at the earliest stage still open.</div>
  </div>

  <div class="zf-card">
    <div class="sec"><h3>Day by day</h3>
      <span style="display:flex;gap:8px;align-items:center;flex-wrap:wrap">
        <span class="leg"><?php foreach ($O['stages'] as $sg): $c = zo_col($sg['name']); ?><span><b style="background:<?= $c[2] ?>"></b><?= e($sg['name']) ?></span><?php endforeach; ?></span>
        <?php if ($days): ?><button type="button" class="zf-btn sm" onclick="osCsv('osDays','<?= e($O['pi_no']) ?>_day_by_day')">Export Excel</button><?php endif; ?>
      </span></div>
    <?php if (!$days): ?>
      <div class="zf-none">Nothing booked on this order yet.</div>
    <?php else:
      /* every day from the first to the last, the empty ones too — a gap means the floor stopped on it */
      $d0 = strtotime(array_key_first($days)); $d1 = strtotime(array_key_last($days));
      $span = []; for ($t = $d0; $t <= $d1 && count($span) < 120; $t = strtotime('+1 day', $t)) $span[] = date('Y-m-d', $t);
      $max = 1; foreach ($days as $d) $max = max($max, array_sum($d['st']));
      $names = array_values(array_map(fn($sg) => $sg['name'], $O['stages'])); ?>
      <div class="chart">
        <?php foreach ($span as $d): $v = $days[$d]['st'] ?? []; ?>
          <div class="c" title="<?= e(date('D j M', strtotime($d))) ?><?php foreach ($v as $k => $q) echo e(' · ' . $k . ' ' . number_format($q)); ?>">
            <?php foreach ($names as $n): if (empty($v[$n])) continue; $c = zo_col($n); ?>
              <i style="height:<?= round($v[$n] / $max * 100, 2) ?>%;background:<?= $c[2] ?>"></i>
            <?php endforeach; ?>
          </div>
        <?php endforeach; ?>
      </div>
      <div class="cx"><span><?= e(date('j M', $d0)) ?></span><span><?= count($days) ?> day<?= count($days) === 1 ? '' : 's' ?> worked of <?= count($span) ?></span><span><?= e(date('j M', $d1)) ?></span></div>
      <div class="zf-tw" style="max-height:360px">
        <table class="zf-t" id="osDays">
          <thead><tr><th>Date</th><?php foreach ($names as $n): ?><th class="n"><?= e($n) ?></th><?php endforeach; ?>
            <?php if ($showMoney): ?><th class="n">Wages</th><?php endif; ?></tr></thead>
          <tbody>
          <?php foreach (array_reverse($days, true) as $d => $v): ?>
            <tr style="cursor:default"><td style="white-space:nowrap"><?= e(date('D j M Y', strtotime($d))) ?></td>
              <?php foreach ($names as $n): ?><td class="n"><?= !empty($v['st'][$n]) ? $q0($v['st'][$n]) : '<span style="color:#c3ccd6">—</span>' ?></td><?php endforeach; ?>
              <?php if ($showMoney): ?><td class="n"><?= $q0($v['w']) ?></td><?php endif; ?></tr>
          <?php endforeach; ?>
          </tbody>
        </table>
      </div>
      <div class="note">Pieces booked each day: each operation counts its own pieces, so a stage with two operations shows both.</div>
    <?php endif; ?>
  </div>
  <script>
  /* the table on screen, as a file — built here, nothing asked of the server */
  function osCsv(id, name){
    var rows = [].map.call(document.querySelectorAll('#' + id + ' tr'), function(tr){
      return [].map.call(tr.children, function(td){
        var t = td.innerText.replace(/\s+/g, ' ').trim();
        if (/^[=+\-@]/.test(t) && isNaN(+t.replace(/[,%]/g, ''))) t = "'" + t;
        return /[",\n]/.test(t) ? '"' + t.replace(/"/g, '""') + '"' : t;
      }).join(',');
    });
    var a = document.createElement('a');
    a.href = URL.createObjectURL(new Blob(['﻿' + rows.join('\r\n')], {type: 'text/csv;charset=utf-8'}));
    a.download = name.replace(/[^A-Za-z0-9_-]+/g, '_') + '.csv'; document.body.appendChild(a); a.click(); a.remove();
  }
  </script>
<?php endif; ?>
</div>
<?php page_footer(); ?>
