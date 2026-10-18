<?php
/*
  IMPORT — EFS TAB.

  One obligation per invoice line, because valuation, wastage and value
  addition can differ by HS code. afnan's rule: export at least
  (imported kg − wastage %) of the material, in goods worth at least the full
  imported value (+ the value addition % for that HS code) — e.g.
  100 kg × USD 3.50 → 95 kg and USD 350 — within the months allowed from the
  customs release (or GD date). Customs may ask more, never less.

  PHASE 3: the kg is the GD's assessed kg (CUSTOMS tab) and the valuation its
  assessed value per kg, each until typed or assessed falling back to the
  invoice. A line can be switched off EFS (not every line of an EFS import is
  EFS). What exports have discharged comes from the export allocations on
  Imports → EFS Reconciliation; each line opens its own page with the
  allocations that used it, a customs extension and approved reductions.
  Nothing here moves stock.
*/
require_once __DIR__ . '/includes/bootstrap.php';
require_once __DIR__ . '/includes/imports.php';

$imp = imp_open('efs');
$id  = (int)$imp['id'];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verify_csrf();
    try {
        imp_require('efs', 'u');
        $months = (int)imp_num($_POST['efs_months'] ?? IMP_EFS_MONTHS);
        if ($months < 1 || $months > 60) throw new Exception('Export within: 1 to 60 months.');
        db()->beginTransaction();
        db()->prepare("UPDATE imp_imports SET efs_months=? WHERE id=?")->execute([$months, $id]);
        $ch = [];
        foreach (imp_lines($id) as $l) {
            $lid = (int)$l['id'];
            $val = trim((string)($_POST['val'][$lid] ?? '')); $w = trim((string)($_POST['waste'][$lid] ?? '')); $va = trim((string)($_POST['va'][$lid] ?? ''));
            $nv = $val === '' ? null : imp_num($val); $nw = $w === '' ? null : imp_num($w); $nva = $va === '' ? null : imp_num($va);
            $el = !empty($_POST['eligible'][$lid]) ? 1 : 0;
            if (($nv !== null && $nv < 0) || ($nw !== null && ($nw < 0 || $nw > 100)) || ($nva !== null && ($nva < 0 || $nva > 1000)))
                throw new Exception('"' . $l['description'] . '": valuation cannot be negative; wastage 0–100 %; value addition 0–1000 %.');
            /* a line already used by an export stays EFS — reverse those allocations first */
            if ($el === 0 && (int)($l['efs_eligible'] ?? 1) === 1) {
                $c = db()->prepare("SELECT COUNT(*) FROM imp_efs_alloc_uses u JOIN imp_efs_alloc a ON a.id=u.alloc_id WHERE u.target_kind='line' AND u.target_id=? AND a.status<>'reversed'");
                $c->execute([$lid]);
                if ((int)$c->fetchColumn() > 0) throw new Exception('"' . $l['description'] . '" is already used by an export allocation — it stays EFS. Reverse those allocations first.');
            }
            db()->prepare("UPDATE imp_lines SET efs_valuation=?, efs_waste_pct=?, efs_va_pct=?, efs_eligible=? WHERE id=? AND import_id=?")->execute([$nv, $nw, $nva, $el, $lid, $id]);
            if ((string)$l['efs_valuation'] !== (string)$nv || (string)$l['efs_waste_pct'] !== (string)$nw || (string)$l['efs_va_pct'] !== (string)$nva)
                $ch[] = $l['description'] . ': valuation ' . ($nv ?? 'GD / invoice') . ', wastage ' . ($nw ?? IMP_EFS_WASTE) . '%, value add ' . ($nva ?? 0) . '%';
            if ($el !== (int)($l['efs_eligible'] ?? 1)) $ch[] = $l['description'] . ($el ? ': EFS on' : ': not EFS');
        }
        imp_log($id, 'EFS figures saved', ($ch ? implode(' · ', $ch) . ' · ' : '') . 'export within ' . $months . ' months');
        db()->commit();
        $_SESSION['flash'] = 'EFS figures saved.';
    } catch (Throwable $e) {
        if (db()->inTransaction()) db()->rollBack();
        $_SESSION['error'] = $e->getMessage();
    }
    redirect('imp_efs.php?id=' . $id);
}

$lines = imp_lines($id);
$dl = imp_efs_deadline($imp);
$canEdit = imp_can('efs', 'u'); $ro = $canEdit ? '' : ' readonly';
$basis = imp_gd_basis($lines);
$obl = imp_efs_obligations($id);
$T = ['kg' => 0.0, 'imported' => 0.0, 'need_kg' => 0.0, 'need_value' => 0.0, 'done_kg' => 0.0, 'done_value' => 0.0];
$rows = [];
foreach ($lines as $n => $l) {
    $b = $basis[(int)$l['id']] ?? ['kg' => null, 'per_kg' => null];
    $x = imp_efs_line($l, $b['per_kg'], $b['kg']);
    $o = $obl['L' . (int)$l['id']] ?? null;
    $rows[] = [$n + 1, $l, $x, $o];
    if ($o) { $T['kg'] += $o['kg']; $T['imported'] += $o['imported_value']; $T['need_kg'] += $o['need_kg']; $T['need_value'] += $o['need_value']; $T['done_kg'] += $o['done_kg']; $T['done_value'] += $o['done_value']; }
}
$allocs = [];
foreach ($obl as $k => $o) foreach (imp_efs_allocs($k) as $a) $allocs[(int)$a['id']] = $a;
$f = static fn($v, $d = 2) => number_format((float)$v, $d);
$raw = static fn($v) => $v === null || $v === '' ? '' : rtrim(rtrim((string)$v, '0'), '.');

page_header('EFS — ' . $imp['ref']);
flash();
echo imp_page_css();
imp_tab_strip($imp, 'efs');
?>
<style>.dl{display:inline-block;padding:3px 9px;border-radius:20px;font-size:11px;font-weight:800;white-space:nowrap}.dl.r{background:rgba(224,67,93,.13);color:#b8283f}.dl.o{background:rgba(217,119,6,.14);color:#b86a05}.dl.g{background:rgba(22,163,74,.12);color:#16803c}.dl.n{background:#f0f3f8;color:#5a6b82}.efs-off td{color:#8a97ab}</style>
<?php if ($imp['import_type'] !== 'efs'): ?><div class="xwarn" style="margin-bottom:12px">This is an <b>ordinary (duty-paid)</b> import — there is no EFS obligation and it is not on EFS Reconciliation. The figures below are only a check.</div><?php endif; ?>
<form method="post" id="efsForm">
<?= csrf_field() ?><input type="hidden" name="import_id" value="<?= $id ?>">
<div class="xkpi">
  <div class="k"><div class="l">Imported value (EFS lines)</div><div class="v"><?= e($imp['currency'] . ' ' . $f($T['imported'])) ?></div></div>
  <div class="k"><div class="l">Must export — material</div><div class="v"><?= e($f($T['need_kg'], 3)) ?> kg</div><div class="imp-small imp-muted">exported <?= e($f($T['done_kg'], 3)) ?> kg</div></div>
  <div class="k"><div class="l">Must export — value at least</div><div class="v"><?= e($imp['currency'] . ' ' . $f($T['need_value'])) ?></div><div class="imp-small imp-muted">exported <?= e($f($T['done_value'])) ?></div></div>
  <div class="k"><div class="l">Deadline</div><div class="v"><?= e($dl['date'] ? imp_dmy($dl['date']) : '—') ?></div><div class="imp-small imp-muted"><?= e($dl['from']) ?></div></div>
</div>
<div class="xcard">
  <div style="display:flex;justify-content:space-between;align-items:center;flex-wrap:wrap;gap:8px">
    <h2 style="margin:0">One obligation per product line</h2>
    <label class="xlabel" style="display:flex;align-items:center;gap:8px">Export within<input class="xin num" name="efs_months" value="<?= (int)$imp['efs_months'] ?>" style="width:70px;margin:0"<?= $ro ?>> months of customs release</label>
  </div>
  <?php if (!$lines): ?><div class="xnote" style="margin-top:10px">Add the invoice lines first.</div><?php else: ?>
  <div class="xwrap" style="margin-top:10px"><table class="xtable" style="min-width:1260px">
    <thead><tr><th style="width:56px">EFS</th><th>PCT / HS · product</th><th class="num">kg</th><th class="num" style="width:120px">Valuation / kg</th><th class="num" style="width:90px">Wastage %</th><th class="num" style="width:96px">Value add %</th>
      <th class="num">Must export kg</th><th class="num">Must export value</th><th class="num">Exported</th><th class="num">Left</th><th>Deadline</th></tr></thead>
    <tbody>
    <?php foreach ($rows as [$no, $l, $x, $o]): $lid = (int)$l['id']; $on = (int)($l['efs_eligible'] ?? 1) === 1; ?>
      <tr class="<?= $on ? '' : 'efs-off' ?>" data-kg="<?= e($x['kg']) ?>" data-def="<?= e($x['source'] === 'typed' ? (($basis[$lid]['per_kg'] ?? null) ?? ((float)$l['net_kg'] > 0 ? imp_line_amount($l) / (float)$l['net_kg'] : 0)) : $x['valuation']) ?>">
        <td><input type="checkbox" name="eligible[<?= $lid ?>]" value="1"<?= $on ? ' checked' : '' ?><?= $canEdit ? '' : ' disabled' ?> title="EFS line"></td>
        <td><b><?= e($l['hs_code'] ?: '—') ?></b> <span class="imp-small imp-muted">line <?= $no ?></span><div class="imp-small imp-muted"><?= e($l['description']) ?></div></td>
        <td class="num"><?= e($f($x['kg'], 3)) ?><div class="imp-small imp-muted"><?= e($x['kg_source']) ?></div></td>
        <td><input class="xin num v" name="val[<?= $lid ?>]" value="<?= e($raw($l['efs_valuation'])) ?>" placeholder="<?= e(number_format($x['source'] === 'typed' ? (float)(($basis[$lid]['per_kg'] ?? null) ?? ((float)$l['net_kg'] > 0 ? imp_line_amount($l) / (float)$l['net_kg'] : 0)) : $x['valuation'], 4, '.', '')) ?>"<?= $ro ?>><div class="imp-small imp-muted"><?= e($x['source']) ?></div></td>
        <td><input class="xin num w" name="waste[<?= $lid ?>]" value="<?= e($raw($l['efs_waste_pct'])) ?>" placeholder="<?= e($raw(IMP_EFS_WASTE)) ?>"<?= $ro ?>></td>
        <td><input class="xin num a" name="va[<?= $lid ?>]" value="<?= e($raw($l['efs_va_pct'])) ?>" placeholder="0"<?= $ro ?>></td>
        <td class="num calc o-kg"><?= e($f($x['need_kg'], 3)) ?></td><td class="num calc o-val"><?= e($f($x['need_value'])) ?></td>
        <?php if ($o && $on): ?>
          <td class="num"><?= e($f($o['done_kg'], 3)) ?> kg<div class="imp-small imp-muted"><?= e($o['currency'] . ' ' . $f($o['done_value'])) ?></div></td>
          <td class="num"><b><?= e($f($o['kg_open'], 3)) ?> kg</b><div class="imp-small imp-muted"><?= e($o['currency'] . ' ' . $f($o['value_open'])) ?></div></td>
          <td><a href="imp_efs_line.php?kind=line&amp;id=<?= $lid ?>" style="text-decoration:none"><span class="dl <?= e($o['pill'][0]) ?>"><?= e($o['pill'][1]) ?></span></a><div class="imp-small imp-muted"><?= e($o['deadline'] ? imp_dmy($o['deadline']) : '—') ?><?= $o['ext_ref'] !== '' ? ' · extended' : '' ?></div></td>
        <?php else: ?>
          <td class="num">—</td><td class="num">—</td><td><span class="imp-small imp-muted"><?= $on ? 'not an EFS import' : 'not EFS' ?></span></td>
        <?php endif; ?>
      </tr>
    <?php endforeach; ?>
    </tbody>
    <tfoot><tr><td colspan="2">EFS lines</td><td class="num"><?= e($f($T['kg'], 3)) ?></td><td colspan="3"></td><td class="num"><?= e($f($T['need_kg'], 3)) ?></td><td class="num"><?= e($f($T['need_value'])) ?></td><td class="num"><?= e($f($T['done_kg'], 3)) ?> kg</td><td class="num"><?= e($f(max(0, $T['need_kg'] - $T['done_kg']), 3)) ?> kg</td><td></td></tr></tfoot>
  </table></div>
  <?php if ($canEdit): ?><div style="margin-top:10px"><button class="xbtn">Save EFS figures</button></div><?php endif; ?>
  <?php endif; ?>
  <div class="xnote imp-small" style="margin-top:12px"><b>kg</b> is the GD's assessed kg (typed on CUSTOMS), else the invoice net kg. Blank <b>valuation</b> uses the customs-assessed value per kg from the GD, or the invoice value per kg until the GD is assessed (shown greyed); blank wastage is <?= e($raw(IMP_EFS_WASTE)) ?>%; blank value addition is 0%.
    The value to export is the <b>full</b> imported value (plus value addition) — not reduced by the wastage. Untick <b>EFS</b> for a line that is not under EFS. Click a deadline for that line's allocations, an extension or an approved reduction.
    Raw material leaves stock once, at consumption; an export only clears the obligation.</div>
</div>
</form>

<div class="xcard">
  <div style="display:flex;justify-content:space-between;align-items:center;flex-wrap:wrap;gap:8px"><h2 style="margin:0">Exports that used this import</h2>
    <a class="xbtn sec sm" href="imp_efs_recon.php">EFS Reconciliation →</a></div>
  <?php if (!$allocs): ?><div class="xnote" style="margin-top:10px">None yet. Export allocations are made on Imports → EFS Reconciliation.</div><?php else: ?>
  <div class="xwrap" style="margin-top:10px"><table class="xtable" style="min-width:760px"><thead><tr><th>Shipment</th><th>Export GD</th><th>This import's lines (kg · value)</th><th>Status</th><th></th></tr></thead><tbody>
  <?php foreach ($allocs as $a): ?>
    <tr<?= $a['status'] === 'reversed' ? ' style="color:#8a97ab;text-decoration:line-through"' : '' ?>><td><b><?= e($a['invoice_no'] ?? ('#' . $a['shipment_id'])) ?></b><div class="imp-small imp-muted"><?= e(imp_dmy($a['invoice_date'] ?? null)) ?></div></td>
      <td><?= e($a['export_gd_no'] ?: '—') ?></td>
      <td><?php foreach ($a['per'] as $k => $u) if (isset($obl[$k])) echo '<div class="imp-small">' . e($obl[$k]['ref']) . ': ' . e(imp_efs_q((float)$u['kg'])) . ' kg · ' . e($obl[$k]['currency'] . ' ' . number_format((float)$u['vt'], 2)) . '</div>'; ?></td>
      <td><span class="xpill <?= $a['status'] === 'filed' ? 'g' : ($a['status'] === 'reversed' ? 'n' : 'b') ?>"><?= e(($a['status'] === 'open' ? 'Not filed' : ucfirst($a['status']))) ?></span><?= $a['recheck'] ? ' <span class="xpill o">recheck</span>' : '' ?></td>
      <td><a class="xbtn sec sm" href="imp_efs_alloc.php?id=<?= (int)$a['id'] ?>">Open</a></td></tr>
  <?php endforeach; ?>
  </tbody></table></div>
  <?php endif; ?>
</div>
<script>
(function () {
  var n = function (v) { var x = parseFloat(String(v || '').replace(/,/g, '')); return isFinite(x) ? x : 0; };
  var f = function (v, d) { return Number(v).toLocaleString('en-US', { minimumFractionDigits: d, maximumFractionDigits: d }); };
  /* live: a blank valuation falls back to what the server shows greyed (GD assessed, else invoice) */
  function calc() {
    document.querySelectorAll('#efsForm tbody tr').forEach(function (tr) {
      var kg = n(tr.dataset.kg), v = tr.querySelector('.v').value.trim() === '' ? n(tr.dataset.def) : n(tr.querySelector('.v').value);
      var w = tr.querySelector('.w').value.trim() === '' ? <?= json_encode(IMP_EFS_WASTE) ?> : n(tr.querySelector('.w').value), a = n(tr.querySelector('.a').value);
      var imp = Math.round(kg * v * 100) / 100;
      tr.querySelector('.o-kg').textContent = f(kg * (1 - w / 100), 3); tr.querySelector('.o-val').textContent = f(imp * (1 + a / 100), 2);
    });
  }
  document.getElementById('efsForm').addEventListener('input', calc);
})();
</script>
<?php page_footer(); ?>
