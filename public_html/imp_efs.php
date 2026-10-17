<?php
/*
  IMPORT — EFS TAB (Phase 3 preview, working now).

  One obligation per invoice line, because valuation, wastage and value
  addition can differ by HS code. afnan's rule: export at least
  (imported kg − wastage %) of the material, in goods worth at least the full
  imported value (+ the value addition % for that HS code) — e.g.
  100 kg × USD 3.50 → 95 kg and USD 350 — within the months allowed from the
  customs release (or GD date). Customs may ask more, never less.

  Valuation starts from the invoice value per kg until it is typed (from the
  GD; in Phase 2 it comes from the assessed GD line). Matching exports
  against these lines is Phase 3; nothing here moves stock.
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
            if (($nv !== null && $nv < 0) || ($nw !== null && ($nw < 0 || $nw > 100)) || ($nva !== null && ($nva < 0 || $nva > 1000)))
                throw new Exception('"' . $l['description'] . '": valuation cannot be negative; wastage 0–100 %; value addition 0–1000 %.');
            db()->prepare("UPDATE imp_lines SET efs_valuation=?, efs_waste_pct=?, efs_va_pct=? WHERE id=? AND import_id=?")->execute([$nv, $nw, $nva, $lid, $id]);
            if ((string)$l['efs_valuation'] !== (string)$nv || (string)$l['efs_waste_pct'] !== (string)$nw || (string)$l['efs_va_pct'] !== (string)$nva)
                $ch[] = $l['description'] . ': valuation ' . ($nv ?? 'invoice') . ', wastage ' . ($nw ?? IMP_EFS_WASTE) . '%, value add ' . ($nva ?? 0) . '%';
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
$T = ['kg' => 0.0, 'imported' => 0.0, 'need_kg' => 0.0, 'need_value' => 0.0];
$rows = [];
foreach ($lines as $l) { $x = imp_efs_line($l); $rows[] = [$l, $x]; foreach ($T as $k => $v) $T[$k] += $x[$k]; }
$f = static fn($v, $d = 2) => number_format((float)$v, $d);
$raw = static fn($v) => $v === null || $v === '' ? '' : rtrim(rtrim((string)$v, '0'), '.');

page_header('EFS — ' . $imp['ref']);
flash();
echo imp_page_css();
imp_tab_strip($imp, 'efs');
?>
<?php if ($imp['import_type'] !== 'efs'): ?><div class="xwarn" style="margin-bottom:12px">This is an <b>ordinary (duty-paid)</b> import — there is no EFS obligation. The figures below are only a check.</div><?php endif; ?>
<form method="post" id="efsForm">
<?= csrf_field() ?><input type="hidden" name="import_id" value="<?= $id ?>">
<div class="xkpi">
  <div class="k"><div class="l">Imported value</div><div class="v" id="kImp"><?= e($imp['currency'] . ' ' . $f($T['imported'])) ?></div></div>
  <div class="k"><div class="l">Must export — material</div><div class="v" id="kKg"><?= e($f($T['need_kg'], 3)) ?> kg</div></div>
  <div class="k"><div class="l">Must export — value at least</div><div class="v" id="kVal"><?= e($imp['currency'] . ' ' . $f($T['need_value'])) ?></div></div>
  <div class="k"><div class="l">Deadline</div><div class="v"><?= e($dl['date'] ? imp_dmy($dl['date']) : '—') ?></div><div class="imp-small imp-muted"><?= e($dl['from']) ?></div></div>
</div>
<div class="xcard">
  <div style="display:flex;justify-content:space-between;align-items:center;flex-wrap:wrap;gap:8px">
    <h2 style="margin:0">One obligation per product line</h2>
    <label class="xlabel" style="display:flex;align-items:center;gap:8px">Export within<input class="xin num" name="efs_months" value="<?= (int)$imp['efs_months'] ?>" style="width:70px;margin:0"<?= $ro ?>> months of customs release</label>
  </div>
  <?php if (!$lines): ?><div class="xnote" style="margin-top:10px">Add the invoice lines first.</div><?php else: ?>
  <div class="xwrap" style="margin-top:10px"><table class="xtable" style="min-width:940px">
    <thead><tr><th>PCT / HS · product</th><th class="num">Net kg</th><th class="num" style="width:120px">Valuation / kg</th><th class="num" style="width:96px">Wastage %</th><th class="num" style="width:100px">Value add %</th><th class="num">Imported value</th><th class="num">Must export kg</th><th class="num">Must export value</th></tr></thead>
    <tbody>
    <?php foreach ($rows as [$l, $x]): $lid = (int)$l['id']; ?>
      <tr data-kg="<?= e($x['kg']) ?>" data-inv="<?= e($x['kg'] > 0 ? imp_line_amount($l) / $x['kg'] : 0) ?>">
        <td><b><?= e($l['hs_code'] ?: '—') ?></b><div class="imp-small imp-muted"><?= e($l['description']) ?></div></td>
        <td class="num"><?= e($f($x['kg'], 3)) ?></td>
        <td><input class="xin num v" name="val[<?= $lid ?>]" value="<?= e($raw($l['efs_valuation'])) ?>" placeholder="<?= e(number_format($x['valuation'], 4, '.', '')) ?>"<?= $ro ?>></td>
        <td><input class="xin num w" name="waste[<?= $lid ?>]" value="<?= e($raw($l['efs_waste_pct'])) ?>" placeholder="<?= e($raw(IMP_EFS_WASTE)) ?>"<?= $ro ?>></td>
        <td><input class="xin num a" name="va[<?= $lid ?>]" value="<?= e($raw($l['efs_va_pct'])) ?>" placeholder="0"<?= $ro ?>></td>
        <td class="num o-imp"><?= e($f($x['imported'])) ?></td><td class="num calc o-kg"><?= e($f($x['need_kg'], 3)) ?></td><td class="num calc o-val"><?= e($f($x['need_value'])) ?></td>
      </tr>
    <?php endforeach; ?>
    </tbody>
    <tfoot><tr><td>Total</td><td class="num"><?= e($f($T['kg'], 3)) ?></td><td></td><td></td><td></td><td class="num" id="tImp"><?= e($f($T['imported'])) ?></td><td class="num" id="tKg"><?= e($f($T['need_kg'], 3)) ?></td><td class="num" id="tVal"><?= e($f($T['need_value'])) ?></td></tr></tfoot>
  </table></div>
  <?php if ($canEdit): ?><div style="margin-top:10px"><button class="xbtn">Save EFS figures</button></div><?php endif; ?>
  <?php endif; ?>
  <div class="xnote imp-small" style="margin-top:12px">Blank valuation uses the invoice value per kg (shown greyed) until the customs valuation is typed; blank wastage is <?= e($raw(IMP_EFS_WASTE)) ?>%; blank value addition is 0%.
    The value to export is the <b>full</b> imported value (plus value addition) — it is not reduced by the wastage. Matching exports against each line, and what is still owed, comes in Phase 3.
    Raw material leaves stock once, at consumption; an export only clears the obligation.</div>
</div>
</form>
<script>
(function () {
  var CUR = <?= json_encode($imp['currency']) ?>, n = function (v) { var x = parseFloat(String(v || '').replace(/,/g, '')); return isFinite(x) ? x : 0; };
  var f = function (v, d) { return Number(v).toLocaleString('en-US', { minimumFractionDigits: d, maximumFractionDigits: d }); };
  function calc() {
    var T = { imp: 0, kg: 0, val: 0 };
    document.querySelectorAll('#efsForm tbody tr').forEach(function (tr) {
      var kg = n(tr.dataset.kg), v = tr.querySelector('.v').value.trim() === '' ? n(tr.dataset.inv) : n(tr.querySelector('.v').value);
      var w = tr.querySelector('.w').value.trim() === '' ? <?= json_encode(IMP_EFS_WASTE) ?> : n(tr.querySelector('.w').value), a = n(tr.querySelector('.a').value);
      var imp = Math.round(kg * v * 100) / 100, nk = kg * (1 - w / 100), nv = imp * (1 + a / 100);
      tr.querySelector('.o-imp').textContent = f(imp, 2); tr.querySelector('.o-kg').textContent = f(nk, 3); tr.querySelector('.o-val').textContent = f(nv, 2);
      T.imp += imp; T.kg += nk; T.val += nv;
    });
    var s = function (i, t) { var e = document.getElementById(i); if (e) e.textContent = t; };
    s('tImp', f(T.imp, 2)); s('tKg', f(T.kg, 3)); s('tVal', f(T.val, 2));
    s('kImp', CUR + ' ' + f(T.imp, 2)); s('kKg', f(T.kg, 3) + ' kg'); s('kVal', CUR + ' ' + f(T.val, 2));
  }
  document.getElementById('efsForm').addEventListener('input', calc);
})();
</script>
<?php page_footer(); ?>
