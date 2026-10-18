<?php
/*
  IMPORTS — ONE EFS OBLIGATION. Phase 3.

  One import GD line (kind=line) or one opening balance (kind=opening): what
  it must export, what each export allocation took from it, what is left,
  and the two things customs can grant on it — always with their reference,
  always highlighted, never automatic:
    - an extended deadline;
    - an approved reduction (damaged / rejected goods re-exported or
      destroyed with customs approval), which lowers the kg the obligation is
      based on. A reduction is voided with a reason, never deleted.
*/
require_once __DIR__ . '/includes/bootstrap.php';
require_once __DIR__ . '/includes/imports.php';
require_login();
imp_ensure_schema();
imp_require('efs', 'v');

$kind = ($_GET['kind'] ?? $_POST['kind'] ?? '') === 'opening' ? 'opening' : 'line';
$oid  = (int)($_GET['id'] ?? $_POST['id'] ?? 0);
$key  = ($kind === 'line' ? 'L' : 'O') . $oid;
$back = 'imp_efs_line.php?kind=' . $kind . '&id=' . $oid;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verify_csrf();
    imp_require('efs', 'u');
    $act = (string)($_POST['action'] ?? '');
    if ($act === 'extend') {
        $d = imp_date($_POST['ext_date'] ?? '');
        $r = imp_efs_set_extension($kind, $oid, $d, (string)($_POST['ext_ref'] ?? ''));
        $_SESSION[$r['ok'] ? 'flash' : 'error'] = $r['ok'] ? ($d ? 'Extended deadline saved.' : 'Extension removed — the normal deadline applies again.') : $r['error'];
    } elseif ($act === 'reduce') {
        $r = imp_efs_add_reduction($kind, $oid, imp_num($_POST['kg'] ?? 0), (string)($_POST['ref'] ?? ''), (string)($_POST['reason'] ?? ''));
        $_SESSION[$r['ok'] ? 'flash' : 'error'] = $r['ok'] ? 'Approved reduction recorded.' : $r['error'];
    } elseif ($act === 'void_reduction') {
        imp_require('efs', 'r');
        $r = imp_efs_void_reduction((int)($_POST['rid'] ?? 0), (string)($_POST['reason'] ?? ''));
        $_SESSION[$r['ok'] ? 'flash' : 'error'] = $r['ok'] ? 'Reduction voided.' : $r['error'];
    }
    redirect($back);
}

$o = imp_efs_obligations()[$key] ?? null;
if (!$o) { http_response_code(404); exit('That EFS line is not there (or it is not an EFS line of an EFS import).'); }
$allocs = imp_efs_allocs($key);
$reds = imp_efs_reductions($kind, $oid);
$canEdit = imp_can('efs', 'u');
$f = static fn($v, $d = 2) => number_format((float)$v, $d);

page_header('EFS — ' . ($o['gd_no'] ?: $o['ref']));
flash();
echo imp_page_css();
?>
<style>.dl{display:inline-block;padding:3px 9px;border-radius:20px;font-size:11px;font-weight:800;white-space:nowrap}.dl.r{background:rgba(224,67,93,.13);color:#b8283f}.dl.o{background:rgba(217,119,6,.14);color:#b86a05}.dl.g{background:rgba(22,163,74,.12);color:#16803c}.dl.n{background:#f0f3f8;color:#5a6b82}
.xwarn{padding:11px 14px;border-radius:12px;background:rgba(217,119,6,.1);border:1px solid rgba(217,119,6,.28);font-size:12.5px;color:#9a5a06;line-height:1.6}</style>
<div class="xhead">
  <div><div class="t"><?= e($o['gd_no'] ?: 'GD not typed') ?> · <?= e($o['product']) ?></div>
    <div class="s"><?= e($o['ref']) ?> · HS <?= e($o['hs'] ?: '—') ?> · GD date <?= e(imp_dmy($o['gd_date'])) ?><?= $o['import_id'] ? ' · <a href="imp_efs.php?id=' . (int)$o['import_id'] . '">open the import →</a>' : ' · <a href="imp_opening.php">Opening Balances →</a>' ?></div></div>
  <span class="dl <?= e($o['pill'][0]) ?>"><?= e($o['pill'][1]) ?></span>
</div>
<div class="xkpi">
  <div class="k"><div class="l">Imported</div><div class="v"><?= e(imp_efs_q($o['kg'])) ?> kg</div><div class="s"><?= e($o['kg_source']) ?> · <?= e($o['currency'] . ' ' . $f($o['valuation'], 4)) ?>/kg (<?= e($o['val_source']) ?>)</div></div>
  <div class="k"><div class="l">Must export</div><div class="v"><?= e(imp_efs_q($o['need_kg'])) ?> kg</div><div class="s"><?= e($o['currency'] . ' ' . $f($o['need_value'])) ?> · wastage <?= e((string)$o['waste']) ?>% · value add <?= e((string)$o['va']) ?>%</div></div>
  <div class="k"><div class="l">Exported</div><div class="v"><?= e(imp_efs_q($o['done_kg'])) ?> kg</div><div class="s"><?= e($o['currency'] . ' ' . $f($o['done_value'])) ?><?= $o['pre_kg'] > 0 ? ' · incl. ' . e(imp_efs_q($o['pre_kg'])) . ' kg before go-live' : '' ?></div></div>
  <div class="k"><div class="l">Left</div><div class="v"><?= e(imp_efs_q($o['kg_open'])) ?> kg</div><div class="s"><?= e($o['currency'] . ' ' . $f($o['value_open'])) ?> · deadline <?= e($o['deadline'] ? imp_dmy($o['deadline']) : '—') ?></div></div>
</div>
<?php if ($o['received_kg'] !== null && $o['done_kg'] > $o['received_kg'] + 0.0005): ?><div class="xwarn" style="margin-bottom:12px"><b>More exported than received:</b> <?= e(imp_efs_q($o['done_kg'])) ?> kg allocated against <?= e(imp_efs_q($o['received_kg'])) ?> kg received at Gate In.</div><?php endif; ?>

<div class="xcard"><h2>Exports that used this line</h2>
  <?php if (!$allocs): ?><div class="xnote">None yet.</div><?php else: ?>
  <div class="xwrap"><table class="xtable" style="min-width:720px"><thead><tr><th>Shipment</th><th>Export GD</th><th class="num">kg</th><th class="num">Assessed value of this kg</th><th class="num">Value counted</th><th>Status</th><th></th></tr></thead><tbody>
  <?php foreach ($allocs as $a): $u = $a['per'][$key]; $rev = $a['status'] === 'reversed'; ?>
    <tr<?= $rev ? ' style="color:#8a97ab;text-decoration:line-through"' : '' ?>><td><b><?= e($a['invoice_no'] ?? '') ?></b><div class="imp-small imp-muted"><?= e(imp_dmy($a['invoice_date'] ?? null)) ?></div></td><td><?= e($a['export_gd_no'] ?: '—') ?></td>
      <td class="num"><?= e(imp_efs_q((float)$u['kg'])) ?></td><td class="num"><?= e($f($u['ft'])) ?></td>
      <td class="num"><?= e($f($u['vt'])) ?><?= (float)$u['vt'] < (float)$u['ft'] - 0.005 ? ' <span style="color:#b8283f;font-weight:800">' . e($f((float)$u['ft'] - (float)$u['vt'])) . ' below</span>' : '' ?></td>
      <td><span class="xpill <?= $a['status'] === 'filed' ? 'g' : ($rev ? 'n' : 'b') ?>"><?= e(($a['status'] === 'open' ? 'Not filed' : ucfirst($a['status']))) ?></span></td><td><a class="xbtn sec sm" href="imp_efs_alloc.php?id=<?= (int)$a['id'] ?>">Open</a></td></tr>
  <?php endforeach; ?>
  </tbody></table></div>
  <?php endif; ?>
</div>

<div class="xcard"><h2>Deadline extended by customs</h2>
  <?php if ($o['ext_ref'] !== ''): ?><div class="xwarn" style="margin-bottom:10px">Extended to <b><?= e(imp_dmy($o['deadline'])) ?></b> — <?= e($o['ext_ref']) ?></div><?php endif; ?>
  <?php if ($canEdit): ?>
  <form method="post" style="display:flex;gap:8px;flex-wrap:wrap;align-items:flex-end"><?= csrf_field() ?><input type="hidden" name="action" value="extend"><input type="hidden" name="kind" value="<?= e($kind) ?>"><input type="hidden" name="id" value="<?= $oid ?>">
    <label class="xlabel" style="margin:0">New deadline<input class="xin" type="date" name="ext_date" value="<?= e($o['ext_ref'] !== '' ? (string)$o['deadline'] : '') ?>"></label>
    <label class="xlabel" style="margin:0">Customs reference<input class="xin" name="ext_ref" value="<?= e($o['ext_ref']) ?>" placeholder="extension letter / no." style="min-width:240px"></label>
    <button class="xbtn sec">Save extension</button><span class="imp-small imp-muted">Leave the date blank and save to remove it.</span></form>
  <?php endif; ?>
</div>

<div class="xcard"><h2>Approved reductions</h2>
  <div class="imp-small imp-muted" style="margin:-6px 0 10px">Damaged or rejected goods re-exported or destroyed with customs approval. A reduction lowers the kg this line must export — only with its reference, never automatically.</div>
  <?php if ($reds): ?><div class="xwrap"><table class="xtable" style="min-width:640px"><thead><tr><th>Date</th><th class="num">kg</th><th>Reference</th><th>Reason</th><th></th></tr></thead><tbody>
  <?php foreach ($reds as $r): ?>
    <tr<?= (int)$r['is_void'] ? ' style="color:#8a97ab;text-decoration:line-through"' : '' ?>><td><?= e(imp_dmy(substr((string)$r['created_at'], 0, 10))) ?></td><td class="num"><?= e(imp_efs_q((float)$r['kg'])) ?></td><td><?= e($r['ref']) ?></td><td><?= e($r['reason']) ?><?= (int)$r['is_void'] ? '<div class="imp-small">voided: ' . e($r['void_reason']) . '</div>' : '' ?></td>
      <td><?php if (!(int)$r['is_void'] && imp_can('efs', 'r')): ?><form method="post" style="display:flex;gap:6px" onsubmit="return confirm('Void this reduction? The kg count again.');"><?= csrf_field() ?><input type="hidden" name="action" value="void_reduction"><input type="hidden" name="kind" value="<?= e($kind) ?>"><input type="hidden" name="id" value="<?= $oid ?>"><input type="hidden" name="rid" value="<?= (int)$r['id'] ?>"><input class="xin" name="reason" placeholder="why" style="margin:0;max-width:160px"><button class="xbtn red sm">Void</button></form><?php endif; ?></td></tr>
  <?php endforeach; ?></tbody></table></div><?php endif; ?>
  <?php if ($canEdit): ?>
  <form method="post" style="display:flex;gap:8px;flex-wrap:wrap;align-items:flex-end;margin-top:10px"><?= csrf_field() ?><input type="hidden" name="action" value="reduce"><input type="hidden" name="kind" value="<?= e($kind) ?>"><input type="hidden" name="id" value="<?= $oid ?>">
    <label class="xlabel" style="margin:0">kg approved<input class="xin num" name="kg" style="max-width:120px"></label>
    <label class="xlabel" style="margin:0">Customs reference<input class="xin" name="ref" placeholder="approval / certificate no."></label>
    <label class="xlabel" style="margin:0">Reason<input class="xin" name="reason" placeholder="e.g. 40 kg wet fibre destroyed" style="min-width:240px"></label>
    <button class="xbtn sec">Add reduction</button></form>
  <?php endif; ?>
</div>
<?php page_footer(); ?>
