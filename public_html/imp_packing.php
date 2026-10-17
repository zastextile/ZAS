<?php
/*
  IMPORT — PACKING TAB (the supplier's packing list).

  Rows are made from the invoice lines — the product is picked, never
  retyped. A package number is used once per kind of package on this import
  (Bale 5 and Roll 5 may both exist), the same rule as the export packing
  list. Each range can be put in a container. The comparison below shows,
  per invoice line, packages and kg on the invoice against what is packed,
  and any package not yet in a container.
*/
require_once __DIR__ . '/includes/bootstrap.php';
require_once __DIR__ . '/includes/imports.php';

$imp = imp_open('imp');
$id  = (int)$imp['id'];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verify_csrf();
    $action = (string)($_POST['action'] ?? '');
    try {
        if ($action === 'add') {
            imp_require('imp', 'c');
            $lineId = (int)($_POST['line_id'] ?? 0);
            $lines = []; foreach (imp_lines($id) as $l) $lines[(int)$l['id']] = $l;
            if (!isset($lines[$lineId])) throw new Exception('Pick the invoice line.');
            $type = (string)($_POST['pkg_type'] ?? 'Bale'); if (!in_array($type, IMP_PKG_TYPES, true)) $type = 'Other';
            $from = (int)imp_num($_POST['serial_from'] ?? 0); $to = (int)imp_num($_POST['serial_to'] ?? 0);
            if ($from < 1 || $to < $from) throw new Exception('Package numbers: from 1 upward, and "to" not before "from".');
            if ($to - $from > 100000) throw new Exception('That range is too long — split it.');
            $pk = $to - $from + 1;
            /* once per kind of package on this import */
            $c = db()->prepare("SELECT serial_from, serial_to FROM imp_packing WHERE import_id=? AND pkg_type=? AND serial_from<=? AND serial_to>=? LIMIT 1");
            $c->execute([$id, $type, $to, $from]);
            if ($hit = $c->fetch()) throw new Exception($type . ' ' . (int)$hit['serial_from'] . '–' . (int)$hit['serial_to'] . ' already has those numbers. A number is used once per kind of package — a different kind, like a Roll, may reuse it.');
            $net = imp_num($_POST['net_kg'] ?? 0); $gross = imp_num($_POST['gross_kg'] ?? 0);
            if ($net < 0 || $gross < 0) throw new Exception('Weights cannot be negative.');
            if ($gross > 0 && $net > $gross) throw new Exception('Net kg cannot be more than gross kg.');
            $cont = (int)($_POST['container_id'] ?? 0);
            $okC = array_map(fn($r) => (int)$r['id'], imp_containers($id));
            if ($cont && !in_array($cont, $okC, true)) $cont = 0;
            $roll = trim((string)($_POST['roll_len'] ?? '')) === '' ? null : imp_num($_POST['roll_len']);
            db()->prepare("INSERT INTO imp_packing (import_id, line_id, pkg_type, serial_from, serial_to, packages, net_kg, gross_kg, roll_len, container_id) VALUES (?,?,?,?,?,?,?,?,?,?)")
                ->execute([$id, $lineId, $type, $from, $to, $pk, $net, $gross, $roll, $cont ?: null]);
            imp_log($id, 'Packing row added', $lines[$lineId]['description'] . ' · ' . $type . ' ' . $from . '–' . $to . ' · ' . number_format($net, 3) . ' kg net');
            $_SESSION['flash'] = 'Packing row added: ' . $type . ' ' . $from . '–' . $to . ' (' . $pk . ' package' . ($pk === 1 ? '' : 's') . ').';
        }
        if ($action === 'container') {
            imp_require('imp', 'u');
            $pid = (int)($_POST['packing_id'] ?? 0); $cont = (int)($_POST['container_id'] ?? 0);
            $okC = array_map(fn($r) => (int)$r['id'], imp_containers($id));
            if ($cont && !in_array($cont, $okC, true)) throw new Exception('Unknown container.');
            db()->prepare("UPDATE imp_packing SET container_id=? WHERE id=? AND import_id=?")->execute([$cont ?: null, $pid, $id]);
            $_SESSION['flash'] = 'Container updated.';
        }
        if ($action === 'delete') {
            imp_require('imp', 'd');
            $pid = (int)($_POST['packing_id'] ?? 0);
            $r = db()->prepare("SELECT * FROM imp_packing WHERE id=? AND import_id=?"); $r->execute([$pid, $id]); $row = $r->fetch();
            if (!$row) throw new Exception('That row is not there.');
            db()->prepare("DELETE FROM imp_packing WHERE id=? AND import_id=?")->execute([$pid, $id]);
            imp_log($id, 'Packing row deleted', $row['pkg_type'] . ' ' . $row['serial_from'] . '–' . $row['serial_to']);
            $_SESSION['flash'] = 'Packing row deleted.';
        }
    } catch (Throwable $e) {
        $_SESSION['error'] = $e->getMessage();
    }
    redirect('imp_packing.php?id=' . $id);
}

$lines = imp_lines($id);
$byLine = []; foreach ($lines as $l) $byLine[(int)$l['id']] = $l;
$rows = imp_packing($id);
$conts = imp_containers($id);
$contName = []; foreach ($conts as $c) $contName[(int)$c['id']] = $c['container_no'] ?: ('Container #' . $c['id']);
$cmp = []; $unalloc = 0;
foreach ($lines as $l) $cmp[(int)$l['id']] = ['pk' => 0, 'net' => 0.0, 'gross' => 0.0];
foreach ($rows as $r) {
    $k = (int)$r['line_id'];
    if (!isset($cmp[$k])) $cmp[$k] = ['pk' => 0, 'net' => 0.0, 'gross' => 0.0];
    $cmp[$k]['pk'] += (int)$r['packages']; $cmp[$k]['net'] += (float)$r['net_kg']; $cmp[$k]['gross'] += (float)$r['gross_kg'];
    if (empty($r['container_id'])) $unalloc += (int)$r['packages'];
}
$canAdd = imp_can('imp', 'c'); $canUpd = imp_can('imp', 'u'); $canDel = imp_can('imp', 'd');
$f3 = static fn($v) => rtrim(rtrim(number_format((float)$v, 3), '0'), '.');

page_header('Packing — ' . $imp['ref']);
flash();
echo imp_page_css();
imp_tab_strip($imp, 'packing');
?>
<?php if (!$lines): ?>
  <div class="xcard"><div class="xnote">Add the invoice lines on <a href="imp_invoice.php?id=<?= $id ?>">INVOICE</a> first — packing rows are made from them.</div></div>
<?php else: ?>
<div class="xcard">
  <h2>Packing vs invoice</h2>
  <div class="xwrap"><table class="xtable">
    <thead><tr><th>Invoice line</th><th class="num">Invoice pkgs</th><th class="num">Packed pkgs</th><th class="num">Invoice net kg</th><th class="num">Packed net kg</th><th class="num">Invoice gross kg</th><th class="num">Packed gross kg</th><th>Check</th></tr></thead>
    <tbody>
    <?php foreach ($lines as $l): $c = $cmp[(int)$l['id']]; $dp = $c['pk'] - (int)$l['packages']; $dn = $c['net'] - (float)$l['net_kg'];
          $ok = $dp === 0 && abs($dn) < 0.0005; ?>
      <tr><td><?= e($l['description']) ?></td>
        <td class="num"><?= (int)$l['packages'] ?></td><td class="num"><?= (int)$c['pk'] ?></td>
        <td class="num"><?= e($f3($l['net_kg'])) ?></td><td class="num"><?= e($f3($c['net'])) ?></td>
        <td class="num"><?= e($f3($l['gross_kg'])) ?></td><td class="num"><?= e($f3($c['gross'])) ?></td>
        <td><?php if ($ok): ?><span class="xpill g">matches</span>
            <?php elseif ($c['pk'] === 0): ?><span class="xpill n">not packed yet</span>
            <?php else: ?><span class="xpill r"><?= $dp < 0 ? (-$dp) . ' pkgs short' : ($dp > 0 ? $dp . ' pkgs extra' : '') ?><?= abs($dn) >= 0.0005 ? ($dp ? ' · ' : '') . ($dn < 0 ? e($f3(-$dn)) . ' kg short' : e($f3($dn)) . ' kg extra') : '' ?></span><?php endif; ?></td></tr>
    <?php endforeach; ?>
    </tbody>
  </table></div>
  <?php if ($unalloc > 0 && $conts): ?><div class="xwarn" style="margin-top:10px"><b><?= (int)$unalloc ?> package<?= $unalloc === 1 ? '' : 's' ?> not in a container yet.</b> Pick the container on each row below.</div><?php endif; ?>
</div>

<div class="xcard">
  <h2>Packing rows</h2>
  <div class="xwrap"><table class="xtable">
    <thead><tr><th>Invoice line</th><th>Package</th><th>Numbers</th><th class="num">Packages</th><th class="num">Net kg</th><th class="num">Gross kg</th><th class="num">Roll length</th><th>Container</th><th></th></tr></thead>
    <tbody>
    <?php if (!$rows): ?><tr><td colspan="9" class="imp-muted" style="padding:16px;text-align:center">Nothing packed yet.</td></tr><?php endif; ?>
    <?php foreach ($rows as $r): ?>
      <tr><td><?= e($byLine[(int)$r['line_id']]['description'] ?? '(line removed)') ?></td><td><?= e($r['pkg_type']) ?></td>
        <td><?= (int)$r['serial_from'] ?><?= (int)$r['serial_to'] !== (int)$r['serial_from'] ? '–' . (int)$r['serial_to'] : '' ?></td>
        <td class="num"><?= (int)$r['packages'] ?></td><td class="num"><?= e($f3($r['net_kg'])) ?></td><td class="num"><?= e($f3($r['gross_kg'])) ?></td>
        <td class="num"><?= $r['roll_len'] !== null ? e($f3($r['roll_len'])) : '' ?></td>
        <td><?php if ($canUpd && $conts): ?>
            <form method="post" style="margin:0"><?= csrf_field() ?><input type="hidden" name="action" value="container"><input type="hidden" name="import_id" value="<?= $id ?>"><input type="hidden" name="packing_id" value="<?= (int)$r['id'] ?>">
              <select class="xin" name="container_id" onchange="this.form.submit()" style="min-width:140px"><option value="0">— not in a container —</option><?php foreach ($contName as $cid => $cn): ?><option value="<?= (int)$cid ?>"<?= (int)$r['container_id'] === $cid ? ' selected' : '' ?>><?= e($cn) ?></option><?php endforeach; ?></select></form>
          <?php else: ?><?= e($contName[(int)$r['container_id']] ?? '—') ?><?php endif; ?></td>
        <td><?php if ($canDel): ?><form method="post" style="margin:0" onsubmit="return confirm('Delete this packing row?')"><?= csrf_field() ?><input type="hidden" name="action" value="delete"><input type="hidden" name="import_id" value="<?= $id ?>"><input type="hidden" name="packing_id" value="<?= (int)$r['id'] ?>"><button class="xbtn red sm">✕</button></form><?php endif; ?></td></tr>
    <?php endforeach; ?>
    </tbody>
  </table></div>

  <?php if ($canAdd): ?>
  <form method="post" style="margin-top:14px">
    <?= csrf_field() ?><input type="hidden" name="action" value="add"><input type="hidden" name="import_id" value="<?= $id ?>">
    <div class="xgrid">
      <label class="xlabel xspan2">Invoice line<select class="xin" name="line_id" required><?php foreach ($lines as $l): ?><option value="<?= (int)$l['id'] ?>"><?= e($l['description'] . ($l['hs_code'] ? ' · ' . $l['hs_code'] : '')) ?></option><?php endforeach; ?></select></label>
      <?= imp_select('Package', 'pkg_type', IMP_PKG_TYPES, 'Bale') ?>
      <?= imp_input('From no.', 'serial_from', '', 'number', ' required') ?>
      <?= imp_input('To no.', 'serial_to', '', 'number', ' required') ?>
      <?= imp_input('Net kg (range total)', 'net_kg', '', 'number') ?>
      <?= imp_input('Gross kg (range total)', 'gross_kg', '', 'number') ?>
      <?= imp_input('Roll length (if rolls)', 'roll_len', '', 'number') ?>
      <label class="xlabel">Container<select class="xin" name="container_id"><option value="0">— later —</option><?php foreach ($contName as $cid => $cn): ?><option value="<?= (int)$cid ?>"><?= e($cn) ?></option><?php endforeach; ?></select></label>
    </div>
    <div style="margin-top:10px"><button class="xbtn">Add packing row</button> <span class="imp-small imp-muted">Containers are added on LOGISTICS.</span></div>
  </form>
  <?php endif; ?>
</div>
<?php endif; ?>
<?php page_footer(); ?>
