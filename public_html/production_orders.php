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

        /* WORK SUMMARY (owner, 22 Nov 2026: "group by product / parts / worker,
           show rate"). ONE grouped query for the whole order; the grouping,
           drilling down, filtering and the Excel file are done in the browser.
           Rate and wages are only sent when this user may see money. */
        $work = []; $planned = [];
        try {
            $st = db()->prepare("SELECT e.entry_date d, e.worker_id wid, COALESCE(w.worker_name, CONCAT('Worker #', e.worker_id)) wn,
                                        COALESCE(p.name, CONCAT('Product #', e.product_id)) pn, COALESCE(e.op_kind,'part') k,
                                        COALESCE(pt.part_name, IF(COALESCE(e.op_kind,'part')='part', CONCAT('Part #', e.part_id), 'Set work')) ptn,
                                        e.op_id oid, COALESCE(CASE COALESCE(e.op_kind,'part') WHEN 'part' THEN o.operation_name WHEN 'line' THEN lo.operation_name ELSE so.operation_name END, CONCAT('Operation #', e.op_id)) opn,
                                        COALESCE(e.stage, '') st, e.rate_applied r, SUM(e.qty) q, SUM(e.amount) a, COUNT(*) n
                                 FROM zp_entries e
                                 LEFT JOIN zp_workers w   ON w.id  = e.worker_id
                                 LEFT JOIN products p     ON p.id  = e.product_id
                                 LEFT JOIN zp_parts pt    ON pt.id = e.part_id
                                 LEFT JOIN zp_part_ops o  ON o.id  = e.op_id AND COALESCE(e.op_kind,'part') = 'part'
                                 LEFT JOIN zp_set_ops so  ON so.id = e.op_id AND e.op_kind = 'set'
                                 LEFT JOIN zp_line_set_ops lo ON lo.id = e.op_id AND e.op_kind = 'line'
                                 WHERE e.status='active' AND e.proforma_id=?
                                 GROUP BY e.entry_date, e.worker_id, e.product_id, e.part_id, e.op_kind, e.op_id, e.stage, e.rate_applied
                                 ORDER BY e.entry_date");
            $st->execute([$pfId]);
            foreach ($st->fetchAll() as $r) {
                $row = ['d' => $r['d'], 'w' => (string)$r['wn'], 'p' => (string)$r['pn'], 't' => (string)$r['ptn'],
                        'o' => (string)$r['opn'], 's' => (string)$r['st'], 'q' => round((float)$r['q'], 2), 'n' => (int)$r['n']];
                if ($showMoney) { $row['r'] = round((float)$r['r'], 2); $row['a'] = round((float)$r['a'], 2); }
                $work[] = $row;
            }
        } catch (Throwable $e) {}
        /* what each part needs at each stage, over the whole order — an
           operation's "left" is this minus what that operation has done */
        foreach ($O['lines'] as $l)
            foreach ($l['pp'] ?? [] as $pos => $byPart)
                foreach ($byPart as $pn => [$need]) {
                    $k = mb_strtolower($pn) . '|' . mb_strtolower((string)($O['stages'][$pos]['name'] ?? ''));
                    $planned[$k] = ($planned[$k] ?? 0) + (float)$need;
                }
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
.zf.os .pp{font-size:10.5px;color:#9a5710;white-space:nowrap;line-height:1.35}
.zf.os .pp.ok{color:#256f3a}
.zf.os .ovr{color:#9a5710;font-size:11.5px;font-weight:600}
.zf.os .zf-pill.p-ovr{color:#9a5710;background:#fff7ec;border-color:#f0d3a8;margin-left:4px}
.zf.os .chart{display:flex;align-items:flex-end;gap:3px;height:110px;padding:10px 12px 0;overflow-x:auto}
.zf.os .chart .c{flex:1 0 14px;max-width:34px;display:flex;flex-direction:column-reverse;height:100%}
.zf.os .chart .c i{display:block;width:100%}
.zf.os .chart .c i:last-child{border-radius:2px 2px 0 0}
.zf.os .cx{display:flex;justify-content:space-between;padding:3px 12px 8px;font-size:11px;color:var(--mut)}
.zf.os .leg{display:flex;gap:10px;flex-wrap:wrap;font-size:11.5px;color:var(--mut)}
.zf.os .leg b{display:inline-block;width:10px;height:10px;border-radius:2px;margin-right:4px;vertical-align:-1px}
.zf.os .note{font-size:12px;color:var(--mut);padding:8px 12px}
/* show / hide (owner, 22 Nov 2026) */
.zf.os .os-chips{display:flex;gap:6px;flex-wrap:wrap;align-items:center;margin:0 0 10px}
.zf.os .os-chips>span{font-size:12px;color:var(--mut);font-weight:600;margin-right:2px}
.zf.os .os-chip{display:inline-flex;align-items:center;gap:5px;height:26px;padding:0 10px;border:1px solid var(--line);border-radius:13px;background:#fff;
  color:var(--mut);font:600 12px inherit;cursor:pointer;user-select:none}
.zf.os .os-chip[aria-pressed="true"]{background:#eaf2fc;border-color:#b9d3f1;color:var(--pri)}
.zf.os .os-chip[aria-pressed="true"]::before{content:"✓";font-size:11px}
.zf.os .sec .tg{border:0;background:none;color:var(--mut);cursor:pointer;width:22px;height:22px;border-radius:3px;font-size:11px;transition:transform .15s;padding:0;margin-right:4px}
.zf.os .sec .tg:hover{background:#eaf2fc;color:var(--pri)}
.zf.os .zf-card.shut .sec .tg{transform:rotate(-90deg)}
.zf.os .zf-card.shut>:not(.sec){display:none !important}
.zf.os .zf-card.shut .sec{border-bottom:0}
.zf.os .sec h3{display:flex;align-items:center}
.zf.os #osGrid:not(.parts) .pp{display:none}
.zf.os .sw{display:inline-flex;align-items:center;gap:6px;font-size:12px;font-weight:600;color:var(--mut);cursor:pointer;user-select:none}
.zf.os .sw input{accent-color:var(--pri)}
/* work summary */
.zf.os .ws-bar{display:flex;gap:8px;align-items:center;flex-wrap:wrap;padding:8px 12px;border-bottom:1px solid var(--line)}
.zf.os .ws-bar .lbl{font-size:12px;color:var(--mut);font-weight:600}
.zf.os #wsT{min-width:640px}
.zf.os #wsT td{vertical-align:middle}
.zf.os #wsT tr.l0>td{background:#f9fafb;font-weight:600;border-top:1px solid var(--line)}
.zf.os #wsT tr.l1>td:first-child{padding-left:28px}
.zf.os #wsT tr.l2>td:first-child{padding-left:48px}
.zf.os #wsT tr.l3>td:first-child{padding-left:68px;color:var(--mut)}
.zf.os #wsT .tgw{display:inline-block;width:16px;color:var(--mut);font-size:10px;cursor:pointer;transition:transform .15s}
.zf.os #wsT tr.open .tgw{transform:rotate(90deg);color:var(--pri)}
.zf.os #wsT tr.kid{cursor:pointer}
.zf.os #wsT tr.leaf{cursor:default}
.zf.os #wsT .lv{font-size:10.5px;color:var(--mut);font-weight:500;margin-left:6px;text-transform:uppercase;letter-spacing:.03em}
.zf.os #wsT .lft{color:#9a5710}.zf.os #wsT .lft.ok{color:#256f3a}
.zf.os #wsT .bar{display:inline-block;width:46px;height:5px;border-radius:3px;background:#e9edf2;vertical-align:middle;margin-left:6px;overflow:hidden}
.zf.os #wsT .bar b{display:block;height:100%;background:#107e3e}
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
                'first' => $o['first'], 'last' => $o['last'], 'status' => $o['status'], 'over' => (int)($o['over'] ?? 0),
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
      return '<span class="zf-pill ' + s[1] + '">' + s[0] + '</span>' + (r.over ? '<span class="zf-pill p-ovr" title="Some work is beyond what the order needs (cutting is open) — information">Extra</span>' : '') + (r.status === 'nolink' ? '<div class="s2"><a href="line_links.php" onclick="event.stopPropagation()">Link its lines to products →</a></div>' : ''); } });
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
  $anySet = (bool)array_filter($O['lines'], fn($l) => !empty($l['is_set']));
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
        <div class="zf-kpi" style="border-left-color:<?= $c[2] ?>"><span><?= e($sg['name']) ?><?= $pos === $lastPos && !$anySet ? ' · overall' : '' ?></span>
          <b style="color:<?= $c[1] ?>"><?= number_format($p, $p >= 99.95 || $p == 0 ? 0 : 1) ?>%</b>
          <small><?= $q0($sg['done']) ?> of <?= $q0($sg['need']) ?> pieces · <?= $q0(max(0, $sg['need'] - $sg['done'])) ?> left</small></div>
      <?php endforeach; ?>
      <?php if ($anySet): $ordSets = array_sum(array_map(fn($l) => !empty($l['is_set']) ? $l['ordered'] : 0, $O['lines'])); $ps = $ordSets > 0 ? min(100, ($O['sets'] ?? 0) / $ordSets * 100) : 0; ?>
        <div class="zf-kpi" style="border-left-color:#107e3e"><span>Sets made · exact</span><b style="color:#107e3e"><?= number_format($ps, $ps >= 99.95 || $ps == 0 ? 0 : 1) ?>%</b>
          <small><?= $q0($O['sets'] ?? 0) ?> of <?= $q0($ordSets) ?> sets · overall <?= number_format($O['pct'], 1) ?>%</small></div>
      <?php endif; ?>
      <?php if ($showMoney): ?>
        <div class="zf-kpi k-green"><span>Wages so far</span><b>PKR <?= $q0($O['wages']) ?></b><small><?= $q0($O['pcs']) ?> operation-pieces booked</small></div>
      <?php endif; ?>
    </div>
  </div>

  <div class="os-chips" id="osChips">
    <span>Show</span>
    <button type="button" class="os-chip" data-sec="grid" aria-pressed="true">Size × stage</button>
    <button type="button" class="os-chip" data-sec="work" aria-pressed="true">Work summary</button>
    <button type="button" class="os-chip" data-sec="days" aria-pressed="true">Day by day</button>
  </div>

  <div class="zf-card" data-sec="grid">
    <div class="sec"><h3><button type="button" class="tg" title="Fold this section">▼</button>Size × stage</h3>
      <span style="display:flex;gap:10px;align-items:center;flex-wrap:wrap">
      <?php if (array_filter($O['lines'], fn($l) => array_filter($l['pp'] ?? [], fn($x) => count($x) > 1))): ?>
        <label class="sw" title="Show each part on its own under every stage cell — PILLOW 0/600, Cover 600/600"><input type="checkbox" id="osParts"> Show parts</label>
      <?php endif; ?>
      <span class="s2">pieces through every operation / pieces needed</span>
      <button type="button" class="zf-btn sm" onclick="osCsv('osGrid','<?= e($O['pi_no']) ?>_size_stage')">Export Excel</button></span></div>
    <div class="zf-tw" style="max-height:none">
      <table class="zf-t" id="osGrid">
        <thead><tr><th>Product · size</th><th class="n">Ordered</th>
          <?php foreach ($O['stages'] as $sg): ?><th class="n"><?= e($sg['name']) ?></th><?php endforeach; ?>
          <?php if ($anySet): ?><th class="n" title="Make Sets credited to this order line — the exact finished sets of this size">Sets made</th><?php endif; ?>
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
                <?php if (count($l['pp'][$pos] ?? []) > 1): foreach ($l['pp'][$pos] as $pn => [$pneed, $pdone]): ?>
                  <div class="pp<?= $pdone + 0.0001 >= $pneed ? ' ok' : '' ?>"><?= e($pn) ?> <?= $q0($pdone) ?>/<?= $q0($pneed) ?></div>
                <?php endforeach; endif; ?>
              <?php endif; ?></td>
            <?php endforeach; ?>
            <?php if ($anySet): ?><td class="n"><?php if (!empty($l['is_set'])): $p = $l['ordered'] > 0 ? min(100, $l['sets'] / $l['ordered'] * 100) : 0; ?>
                <div class="pb"><i><b style="width:<?= $p ?>%;background:#107e3e"></b></i><span><?= number_format($p, $p >= 99.95 || $p == 0 ? 0 : 1) ?>%</span></div>
                <div class="cell"><small><?= $q0($l['sets']) ?> / <?= $q0($l['ordered']) ?> sets</small></div>
              <?php else: ?><span style="color:#9aa8b6" title="One-part product: finished at its last stage">—</span><?php endif; ?></td><?php endif; ?>
            <td><?= $l['behind'] !== '' ? '<span class="beh">' . e($l['behind']) . '</span>' : ($l['pct'] >= 99.95 ? '<span class="zf-pill p-ok">Done</span>' : '<span style="color:#9aa8b6">—</span>') ?>
              <?php foreach ($l['over'] ?? [] as $what => $extra): ?>
                <div class="ovr" title="More than this line needs — cutting is open, so this is information. Extra parts stay in the pool for Make Sets.">Extra: <?= e($what) ?> +<?= $q0($extra) ?></div>
              <?php endforeach; ?></td>
          </tr>
        <?php endforeach; ?>
        </tbody>
        <tfoot><tr><td>Total</td><td class="n"><?= $q0($O['ordered']) ?></td>
          <?php foreach ($O['stages'] as $sg): $p = $sg['need'] > 0 ? $sg['done'] / $sg['need'] * 100 : 0; ?>
            <td class="n"><?= number_format($p, $p >= 99.95 || $p == 0 ? 0 : 1) ?>%<div class="cell"><small><?= $q0($sg['done']) ?> / <?= $q0($sg['need']) ?></small></div></td>
          <?php endforeach; ?>
          <?php if ($anySet): ?><td class="n"><?= $q0($O['sets'] ?? 0) ?><div class="cell"><small>sets made</small></div></td><?php endif; ?><td></td></tr></tfoot>
      </table>
    </div>
    <div class="note">Each stage cell adds up ALL the parts of the line (a Duvet set = its pillows + its cover); the small lines under it show each part on its own — green when that part is done. "Waiting on" names the part that is furthest behind at the earliest stage still open. Pieces are counted per part: a set with a sheet and two pillow covers needs three pieces of work at each stage. Parts are booked without a size and shown here split by the ordered %; <b>Sets made</b> (Make Sets credited to the line) is the exact count per size, and a set line is done only when its sets are made. Cutting is open — anything beyond the plan shows as Extra.</div>
  </div>

  <div class="zf-card" data-sec="work">
    <div class="sec"><h3><button type="button" class="tg" title="Fold this section">▼</button>Work summary</h3>
      <span style="display:flex;gap:6px;align-items:center"><span class="s2">every booking on this order, added up<?= $showMoney ? ', with the rate paid' : '' ?></span>
      <?php if ($work): ?><button type="button" class="zf-btn sm" id="wsCsv">Export Excel</button><?php endif; ?></span></div>
    <?php if (!$work): ?>
      <div class="zf-none">Nothing booked on this order yet.</div>
    <?php else: ?>
    <div class="ws-bar">
      <span class="lbl">Group by</span>
      <div class="zf-seg" id="wsBy">
        <button type="button" data-by="p,t,o,w" class="on">Product › Part › Operation › Worker</button>
        <button type="button" data-by="w,p,t,o">Worker › Part › Operation</button>
        <button type="button" data-by="s,o,w">Stage › Operation › Worker</button>
        <button type="button" data-by="d,w,o">Date › Worker › Operation</button>
      </div>
      <select class="zf-in" id="wsStage"><option value="">Stage: all</option></select>
      <input type="search" class="zf-in q" id="wsQ" placeholder="Search worker, part, operation…">
      <button type="button" class="zf-btn sm" id="wsAll">Open all</button>
    </div>
    <div class="zf-tw" style="max-height:560px">
      <table class="zf-t" id="wsT">
        <thead><tr><th>Group</th><th class="n" title="Pieces booked. A part row adds up every operation, so it counts operation-pieces.">Qty done</th>
          <?php if ($showMoney): ?><th class="n" title="The rate paid; a range when more than one rate was paid">Rate</th><th class="n">Wages</th><?php endif; ?>
          <th class="n" title="What this operation needs on the whole order, and what is still left — on operation rows">Planned · left</th>
          <th class="n">Workers</th><th class="n">Days</th></tr></thead>
        <tbody id="wsB"></tbody>
        <tfoot id="wsF"></tfoot>
      </table>
    </div>
    <div class="note">Qty is pieces booked. On a part, worker or date row it adds up every operation, so a pillow cut and then overlocked counts twice — <b>Planned · left</b> sits on operation rows and is that operation's balance for the whole order (this order's pieces of that part at that stage, minus everything booked on that operation by anyone). Click a row to open it.</div>
    <?php endif; ?>
  </div>

  <div class="zf-card" data-sec="days">
    <div class="sec"><h3><button type="button" class="tg" title="Fold this section">▼</button>Day by day</h3>
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
  <script>
  /* SHOW / HIDE — chips hide a whole section, ▼ folds one; both remembered in
     this browser only, so every person keeps their own view */
  (function(){
    var KEY = 'zo_view_v1', v = {};
    try { v = JSON.parse(localStorage.getItem(KEY) || '{}') || {}; } catch (e) { v = {}; }
    function save(){ try { localStorage.setItem(KEY, JSON.stringify(v)); } catch (e) {} }
    function apply(){
      [].forEach.call(document.querySelectorAll('#osChips .os-chip'), function(c){
        var k = c.dataset.sec, on = v['hide_' + k] !== 1, card = document.querySelector('.zf-card[data-sec="' + k + '"]');
        c.setAttribute('aria-pressed', on ? 'true' : 'false');
        if (card) { card.hidden = !on; card.classList.toggle('shut', v['shut_' + k] === 1); }
      });
      var g = document.getElementById('osGrid'), pc = document.getElementById('osParts');
      if (g) g.classList.toggle('parts', v.parts === 1);
      if (pc) pc.checked = v.parts === 1;
    }
    document.getElementById('osChips').addEventListener('click', function(e){
      var c = e.target.closest('.os-chip'); if (!c) return;
      v['hide_' + c.dataset.sec] = c.getAttribute('aria-pressed') === 'true' ? 1 : 0; save(); apply();
    });
    [].forEach.call(document.querySelectorAll('.zf-card[data-sec] .sec .tg'), function(b){
      b.addEventListener('click', function(){ var k = b.closest('.zf-card').dataset.sec; v['shut_' + k] = v['shut_' + k] === 1 ? 0 : 1; save(); apply(); });
    });
    var pc = document.getElementById('osParts');
    if (pc) pc.addEventListener('change', function(){ v.parts = pc.checked ? 1 : 0; save(); apply(); });
    apply();
  })();

  /* WORK SUMMARY — the rows arrive once, already grouped by day, worker,
     operation and rate; every other grouping is added up here */
  (function(){
    var W = <?= json_encode($work ?? [], JSON_HEX_TAG | JSON_HEX_AMP | JSON_UNESCAPED_UNICODE) ?>;
    var PLAN = <?= json_encode((object)($planned ?? []), JSON_HEX_TAG | JSON_HEX_AMP | JSON_UNESCAPED_UNICODE) ?>;
    var MONEY = <?= $showMoney ? 'true' : 'false' ?>;
    var B = document.getElementById('wsB'); if (!B || !W.length) return;
    var LBL = { p: 'Product', t: 'Part', o: 'Operation', w: 'Worker', s: 'Stage', d: 'Date' };
    var by = ['p','t','o','w'], open = {}, q = '', stg = '';
    var esc = ZF.esc;
    function n0(x){ return (Math.round(x * 100) / 100).toLocaleString('en-US', { maximumFractionDigits: 2 }); }
    function n2(x){ return (+x || 0).toLocaleString('en-US', { minimumFractionDigits: 2, maximumFractionDigits: 2 }); }
    function nice(d){ var x = new Date(d + 'T00:00:00'); return isNaN(x) ? d : x.toLocaleDateString('en-GB', { weekday: 'short', day: 'numeric', month: 'short' }); }
    function val(r, k){ return k === 'd' ? r.d : r[k] || '—'; }
    function show(k, x){ return k === 'd' ? nice(x) : x; }
    var stages = {}; W.forEach(function(r){ if (r.s) stages[r.s] = 1; });
    var sel = document.getElementById('wsStage');
    Object.keys(stages).forEach(function(s){ var o = document.createElement('option'); o.textContent = s; sel.appendChild(o); });

    function rows(){
      var w = q.toLowerCase().split(/\s+/).filter(Boolean);
      return W.filter(function(r){
        if (stg && r.s !== stg) return false;
        if (w.length) { var h = (r.w + ' ' + r.p + ' ' + r.t + ' ' + r.o + ' ' + r.s).toLowerCase(); for (var i = 0; i < w.length; i++) if (h.indexOf(w[i]) < 0) return false; }
        return true;
      });
    }
    function agg(list){
      var a = { q: 0, a: 0, n: 0, rmin: Infinity, rmax: -Infinity, w: {}, d: {}, ops: {} };
      list.forEach(function(r){
        a.q += r.q; a.n += r.n; a.w[r.w] = 1; a.d[r.d] = 1;
        a.ops[r.t + '|' + r.o + '|' + r.s] = (a.ops[r.t + '|' + r.o + '|' + r.s] || 0) + r.q;
        if (MONEY) { a.a += r.a; a.rmin = Math.min(a.rmin, r.r); a.rmax = Math.max(a.rmax, r.r); }
      });
      return a;
    }
    /* PLANNED · LEFT IS THE OPERATION'S, FOR THE WHOLE ORDER — shown on
       operation rows only. What one worker or one day did is not the balance,
       so it is never taken from the filtered rows: every booking counts. */
    var OPDONE = {};
    W.forEach(function(r){ var k = (r.t + '|' + r.o + '|' + r.s).toLowerCase(); OPDONE[k] = (OPDONE[k] || 0) + r.q; });
    function plan(r){
      var need = PLAN[(r.t + '|' + r.s).toLowerCase()];
      if (!need) return '';
      var done = OPDONE[(r.t + '|' + r.o + '|' + r.s).toLowerCase()] || 0, left = need - done, pct = Math.min(100, done / need * 100);
      return n0(need) + ' · <span class="lft' + (left <= 0.0001 ? ' ok' : '') + '">' + (left <= 0.0001 ? (left < -0.0001 ? 'extra ' + n0(-left) : 'done') : n0(left) + ' left') + '</span><span class="bar"><b style="width:' + pct + '%"></b></span>';
    }
    function cells(list, a, isOp){
      return '<td class="n">' + n0(a.q) + '</td>'
        + (MONEY ? '<td class="n">' + (a.rmin === a.rmax ? n2(a.rmin) : n2(a.rmin) + '–' + n2(a.rmax)) + '</td><td class="n">' + n2(a.a) + '</td>' : '')
        + '<td class="n">' + (isOp ? plan(list[0]) : '') + '</td>'
        + '<td class="n">' + Object.keys(a.w).length + '</td><td class="n">' + Object.keys(a.d).length + '</td>';
    }
    function group(list, k){
      var g = {}, order = [];
      list.forEach(function(r){ var x = val(r, k); if (!g[x]) { g[x] = []; order.push(x); } g[x].push(r); });
      if (k === 'd') order.sort().reverse(); else order.sort(function(x, y){ return x.localeCompare(y); });
      return order.map(function(x){ return [x, g[x]]; });
    }
    var html = [];
    function walk(list, lvl, path){
      group(list, by[lvl]).forEach(function(e){
        var key = path + '\u0001' + e[0], a = agg(e[1]), leaf = lvl === by.length - 1, isOpen = !!open[key];
        html.push('<tr class="l' + lvl + (leaf ? ' leaf' : ' kid') + (isOpen ? ' open' : '') + '" data-k="' + esc(key) + '"><td>'
          + (leaf ? '' : '<span class="tgw">▶</span>') + esc(show(by[lvl], e[0])) + '<span class="lv">' + LBL[by[lvl]] + '</span></td>' + cells(e[1], a, by[lvl] === 'o') + '</tr>');
        if (!leaf && isOpen) walk(e[1], lvl + 1, key);
      });
    }
    function draw(){
      var list = rows(); html = []; walk(list, 0, '');
      B.innerHTML = html.join('') || '<tr><td colspan="9" class="zf-none">Nothing matches.</td></tr>';
      var a = agg(list);
      document.getElementById('wsF').innerHTML = list.length ? '<tr><td>Total · ' + list.reduce(function(s, r){ return s + r.n; }, 0) + ' booking' + (a.n === 1 ? '' : 's') + '</td>'
        + '<td class="n">' + n0(a.q) + '</td>' + (MONEY ? '<td class="n"></td><td class="n">' + n2(a.a) + '</td>' : '') + '<td></td><td class="n">' + Object.keys(a.w).length + '</td><td class="n">' + Object.keys(a.d).length + '</td></tr>' : '';
    }
    B.addEventListener('click', function(e){
      var tr = e.target.closest('tr.kid'); if (!tr) return;
      var k = tr.getAttribute('data-k'); open[k] = !open[k]; draw();
    });
    document.getElementById('wsBy').addEventListener('click', function(e){
      var b = e.target.closest('[data-by]'); if (!b) return;
      [].forEach.call(this.children, function(x){ x.classList.toggle('on', x === b); });
      by = b.dataset.by.split(','); open = {}; draw();
      try { localStorage.setItem('zo_ws_by', b.dataset.by); } catch (e2) {}
    });
    document.getElementById('wsAll').addEventListener('click', function(){
      var any = B.querySelector('tr.kid:not(.open)');
      if (!any) { open = {}; this.textContent = 'Open all'; draw(); return; }
      var list = rows();
      (function mark(l, lvl, path){ if (lvl >= by.length - 1) return; group(l, by[lvl]).forEach(function(e){ var k = path + '\u0001' + e[0]; open[k] = true; mark(e[1], lvl + 1, k); }); })(list, 0, '');
      this.textContent = 'Close all'; draw();
    });
    document.getElementById('wsQ').addEventListener('input', function(){ q = this.value; draw(); });
    sel.addEventListener('change', function(){ stg = this.value; draw(); });
    /* the file is every booking line as grouped on the server, so it opens as a
       flat sheet anyone can pivot */
    document.getElementById('wsCsv').addEventListener('click', function(){
      var head = ['Date','Worker','Product','Part','Operation','Stage','Qty','Bookings'].concat(MONEY ? ['Rate','Wages'] : []);
      var c = function(x){ x = String(x == null ? '' : x); if (/^[=+\-@]/.test(x) && isNaN(+x)) x = "'" + x; return /[",\n]/.test(x) ? '"' + x.replace(/"/g, '""') + '"' : x; };
      var txt = '﻿' + head.join(',') + '\r\n' + rows().map(function(r){
        return [r.d, r.w, r.p, r.t, r.o, r.s, r.q, r.n].concat(MONEY ? [r.r, r.a] : []).map(c).join(','); }).join('\r\n');
      var a = document.createElement('a'); a.href = URL.createObjectURL(new Blob([txt], { type: 'text/csv;charset=utf-8' }));
      a.download = <?= json_encode(preg_replace('/[^A-Za-z0-9_-]+/', '_', (string)($O['pi_no'] ?? 'order'))) ?> + '_work_summary.csv'; document.body.appendChild(a); a.click(); a.remove();
    });
    try { var sb = localStorage.getItem('zo_ws_by'); if (sb) { var bt = document.querySelector('#wsBy [data-by="' + sb + '"]'); if (bt) bt.click(); } } catch (e) {}
    draw();
  })();
  </script>
<?php endif; ?>
</div>
<?php page_footer(); ?>
