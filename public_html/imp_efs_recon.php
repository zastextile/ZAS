<?php
/*
  IMPORTS — EFS RECONCILIATION. Phase 3.

  Every EFS obligation in one list — the EFS lines of EFS imports and the
  opening balances — oldest deadline first, with what exports have
  discharged and what is left in kg and in value. Below it, every export
  allocation. Each row's figures are the totals of the allocations' own
  detail; nothing on this page is typed.

  The 5% wastage is part of "must export", never applied to make a line
  balance: a line is Discharged only when both its kg and its value are met.
*/
require_once __DIR__ . '/includes/bootstrap.php';
require_once __DIR__ . '/includes/imports.php';
require_login();
imp_ensure_schema();
imp_require('efs', 'v');

$obl = imp_efs_obligations();
$filt = (string)($_GET['show'] ?? 'open');
if (!in_array($filt, ['open', 'soon', 'met', 'all'], true)) $filt = 'open';
$q = trim((string)($_GET['q'] ?? ''));
$shown = array_filter($obl, function ($o) use ($filt, $q) {
    if ($filt === 'open' && $o['met']) return false;
    if ($filt === 'soon' && ($o['met'] || $o['left'] === null || $o['left'] > 60)) return false;
    if ($filt === 'met' && !$o['met']) return false;
    if ($q !== '' && stripos($o['gd_no'] . ' ' . $o['hs'] . ' ' . $o['product'] . ' ' . $o['ref'], $q) === false) return false;
    return true;
});
if (($_GET['csv'] ?? '') === '1') {
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="efs-reconciliation-' . date('Y-m-d') . '.csv"');
    echo imp_efs_csv($shown);
    exit;
}
$k = ['lines' => 0, 'over' => 0, 'soon' => 0, 'kg' => [], 'val' => []];
foreach ($obl as $o) {
    if ($o['met']) continue;
    $k['lines']++;
    if ($o['left'] !== null && $o['left'] < 0) $k['over']++; elseif ($o['left'] !== null && $o['left'] <= 60) $k['soon']++;
    $k['kg'][] = $o['kg_open'];
    $k['val'][$o['currency']] = ($k['val'][$o['currency']] ?? 0) + $o['value_open'];
}
$allocs = imp_efs_allocs();
$canAdd = imp_can('efs', 'c');
$f = static fn($v, $d = 2) => number_format((float)$v, $d);

page_header('EFS Reconciliation');
flash();
echo imp_page_css();
?>
<style>
.dl{display:inline-block;padding:3px 9px;border-radius:20px;font-size:11px;font-weight:800;white-space:nowrap}.dl.r{background:rgba(224,67,93,.13);color:#b8283f}.dl.o{background:rgba(217,119,6,.14);color:#b86a05}.dl.g{background:rgba(22,163,74,.12);color:#16803c}.dl.n{background:#f0f3f8;color:#5a6b82}
.efsbar{height:7px;border-radius:5px;background:#eef1f6;overflow:hidden;min-width:90px}.efsbar i{display:block;height:100%;background:linear-gradient(90deg,#0ea8c9,#6d5bd0)}
.efs-src{font-size:10.5px;font-weight:800;letter-spacing:.04em;text-transform:uppercase;color:#8a97ab}
.efs-seg{display:inline-flex;border:1px solid #cbd5e3;border-radius:10px;overflow:hidden}.efs-seg a{padding:8px 13px;font-size:12.5px;font-weight:700;color:#5a6b82;text-decoration:none;background:#fff}.efs-seg a.on{background:#0ea8c9;color:#fff}
</style>
<div style="display:flex;justify-content:space-between;align-items:center;flex-wrap:wrap;gap:10px;margin-bottom:12px">
  <h1 style="margin:0;font-size:20px">EFS Reconciliation</h1>
  <div style="display:flex;gap:8px;flex-wrap:wrap">
    <a class="xbtn sec" href="?<?= e(http_build_query(['show' => $filt, 'q' => $q, 'csv' => 1])) ?>">Export to Excel (CSV)</a>
    <?php if ($canAdd): ?><a class="xbtn" href="imp_efs_alloc.php">+ Export allocation</a><?php endif; ?>
  </div>
</div>
<div class="xkpi">
  <div class="k"><div class="l">Open EFS lines</div><div class="v"><?= $k['lines'] ?></div><div class="s">import GD lines + opening balances</div></div>
  <div class="k"><div class="l">Still to export</div><div class="v"><?= e($f(array_sum($k['kg']), 0)) ?> kg</div><div class="s">after the wastage allowance</div></div>
  <div class="k"><div class="l">Value still to export</div><div class="v" style="font-size:15px"><?= $k['val'] ? e(implode(' · ', array_map(fn($c, $v) => $c . ' ' . number_format($v, 0), array_keys($k['val']), $k['val']))) : '—' ?></div><div class="s">incl. value addition</div></div>
  <div class="k"><div class="l">Deadlines</div><div class="v" style="color:<?= $k['over'] ? '#b8283f' : '#152033' ?>"><?= $k['over'] ?> overdue</div><div class="s"><?= $k['soon'] ?> due within 60 days</div></div>
</div>

<div class="xcard">
  <form method="get" style="display:flex;justify-content:space-between;align-items:center;flex-wrap:wrap;gap:8px">
    <div class="efs-seg"><?php foreach (['open' => 'Open', 'soon' => 'Due ≤ 60 days', 'met' => 'Discharged', 'all' => 'All'] as $kk => $lbl): ?><a class="<?= $filt === $kk ? 'on' : '' ?>" href="?<?= e(http_build_query(['show' => $kk, 'q' => $q])) ?>"><?= e($lbl) ?></a><?php endforeach; ?></div>
    <input type="hidden" name="show" value="<?= e($filt) ?>"><input class="xin" name="q" value="<?= e($q) ?>" placeholder="Search GD, HS, product…" style="max-width:260px;margin:0">
  </form>
  <?php if (!$shown): ?><div class="xnote" style="margin-top:12px"><?= $obl ? 'Nothing matches.' : 'No EFS obligations yet: EFS imports\' lines and opening balances appear here.' ?></div><?php else: ?>
  <div class="xwrap" style="margin-top:12px"><table class="xtable" style="min-width:1220px">
    <thead><tr><th>Import GD · line</th><th>HS</th><th>Product</th><th class="num">Must export kg</th><th class="num">Exported kg</th><th class="num">kg left</th><th class="num">Must export value</th><th class="num">Exported value</th><th class="num">Value left</th><th>Progress</th><th>Deadline</th></tr></thead>
    <tbody>
    <?php foreach ($shown as $o): $pct = $o['need_kg'] > 0 ? min(100, (int)round($o['done_kg'] / $o['need_kg'] * 100)) : 0; ?>
      <tr>
        <td><a href="imp_efs_line.php?kind=<?= e($o['kind']) ?>&amp;id=<?= (int)$o['id'] ?>" style="text-decoration:none;color:inherit"><b><?= e($o['gd_no'] ?: 'GD not typed') ?></b></a><div class="efs-src"><?= e($o['ref']) ?></div></td>
        <td style="font-family:'IBM Plex Mono',monospace;font-size:12px"><?= e($o['hs'] ?: '—') ?></td>
        <td><?= e($o['product']) ?><?= $o['reduced_kg'] > 0 ? '<div class="imp-small" style="color:#9a5a06">approved reduction ' . e(imp_efs_q($o['reduced_kg'])) . ' kg</div>' : '' ?></td>
        <td class="num"><?= e($f($o['need_kg'], 3)) ?></td><td class="num"><?= e($f($o['done_kg'], 3)) ?></td><td class="num"><b><?= e($f($o['kg_open'], 3)) ?></b></td>
        <td class="num"><?= e($o['currency'] . ' ' . $f($o['need_value'])) ?></td><td class="num"><?= e($f($o['done_value'])) ?></td><td class="num"><b><?= e($f($o['value_open'])) ?></b></td>
        <td><div class="efsbar"><i style="width:<?= $pct ?>%"></i></div><div class="imp-small imp-muted"><?= $pct ?>% of kg</div></td>
        <td><span class="dl <?= e($o['pill'][0]) ?>"><?= e($o['pill'][1]) ?></span><div class="imp-small imp-muted"><?= e($o['deadline'] ? imp_dmy($o['deadline']) : '—') ?><?= $o['ext_ref'] !== '' ? ' · extended' : '' ?></div></td>
      </tr>
    <?php endforeach; ?>
    </tbody>
  </table></div>
  <?php endif; ?>
  <div class="xnote" style="margin-top:12px">Must export = imported kg (the GD's assessed kg) less the wastage % — 5% unless set per line — and in value kg × valuation + value addition %, never reduced by the wastage. A line is <b>Discharged</b> only when both kg and value are met. Opening balances count what was exported before go-live. Click a line for its allocations, an extension or an approved reduction.</div>
</div>

<div class="xcard">
  <h2>Export allocations</h2>
  <?php if (!$allocs): ?><div class="xnote">None yet<?= $canAdd ? ' — press + Export allocation and pick a shipment.' : '.' ?></div><?php else: ?>
  <div class="xwrap"><table class="xtable" style="min-width:980px">
    <thead><tr><th>Shipment</th><th>Export GD</th><th class="num">EFS value counted</th><th>Import lines used (kg · value)</th><th>Status</th><th></th></tr></thead>
    <tbody>
    <?php foreach ($allocs as $a): $rev = $a['status'] === 'reversed'; ?>
      <tr<?= $rev ? ' style="color:#8a97ab"' : '' ?>>
        <td><b<?= $rev ? ' style="text-decoration:line-through"' : '' ?>><?= e($a['invoice_no'] ?? ('#' . $a['shipment_id'])) ?></b><div class="imp-small imp-muted"><?= !empty($a['invoice_date']) ? e(imp_dmy($a['invoice_date'])) . ' · ' : '' ?><?= e($a['buyer_name'] ?? '') ?></div><div class="imp-small imp-muted"><?= (int)$a['n_lines'] ?> line<?= (int)$a['n_lines'] === 1 ? '' : 's' ?> from the <?= $a['source'] === 'customs' ? 'customs invoice' : 'sale invoice' ?></div></td>
        <td><?= $a['export_gd_no'] ? e($a['export_gd_no']) . '<div class="imp-small imp-muted">' . e(imp_dmy($a['export_gd_date'])) . '</div>' : '<span class="dl o">GD to add</span>' ?></td>
        <td class="num"><b><?= e($a['currency'] . ' ' . $f($a['counted'])) ?></b></td>
        <td><?php foreach ($a['per'] as $kk => $u) { $o = $obl[$kk] ?? null; echo '<div class="imp-small"><b>' . e($o ? ($o['gd_no'] ?: '—') : '?') . '</b> ' . e($o ? $o['ref'] : $kk) . ': ' . e(imp_efs_q((float)$u['kg'])) . ' kg · ' . e(($o['currency'] ?? '') . ' ' . number_format((float)$u['vt'], 2))
            . ((float)$u['vt'] < (float)$u['ft'] - 0.005 ? ' <span style="color:#b8283f;font-weight:800">' . e(number_format((float)$u['ft'] - (float)$u['vt'], 2)) . ' below</span>' : '') . '</div>'; }
          if ($a['below_reason']) echo '<div class="imp-small" style="color:#9a5a06">below assessment: ' . e($a['below_reason']) . '</div>';
          if ($a['warn_reason']) echo '<div class="imp-small" style="color:#9a5a06">more than received: ' . e($a['warn_reason']) . '</div>'; ?></td>
        <td><span class="xpill <?= $a['status'] === 'filed' ? 'g' : ($rev ? 'n' : 'b') ?>"><?= e($a['status'] === 'filed' ? 'Filed ' . imp_dmy($a['filed_on']) : ($a['status'] === 'open' ? 'Not filed' : ucfirst($a['status']))) ?></span><?= $a['recheck'] ? '<div><span class="xpill o" title="' . e($a['recheck']) . '">recheck</span></div>' : '' ?><?= $rev ? '<div class="imp-small">' . e($a['reverse_reason']) . '</div>' : '' ?></td>
        <td><a class="xbtn sec sm" href="imp_efs_alloc.php?id=<?= (int)$a['id'] ?>">Open</a></td>
      </tr>
    <?php endforeach; ?>
    </tbody>
  </table></div>
  <div class="imp-small imp-muted" style="margin-top:8px">Each row is the total of its own detail (customs-invoice lines → import lines). An allocation never touches any invoice, stock or weight.</div>
  <?php endif; ?>
</div>
<?php page_footer(); ?>
