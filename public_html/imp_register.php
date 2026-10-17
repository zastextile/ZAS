<?php
/*
  IMPORT REGISTER — every import, where it stands, what is owed, what needs
  attention. The first page of the Imports menu.

  Read-only. Figures are worked out from each import's own lines, payments
  and dates (includes/imports.php), never typed here.
*/
require_once __DIR__ . '/includes/bootstrap.php';
require_once __DIR__ . '/includes/imports.php';
require_login();
imp_ensure_schema();
imp_require('imp', 'v');

$q      = trim((string)($_GET['q'] ?? ''));
$type   = (string)($_GET['type'] ?? '');
$show   = (string)($_GET['show'] ?? 'open');

$rows = [];
try { $rows = db()->query("SELECT * FROM imp_imports ORDER BY id DESC")->fetchAll(); } catch (Throwable $e) {}

$list = []; $kpi = ['open' => 0, 'transit' => 0, 'payable' => [], 'alerts' => 0, 'closed' => 0];
$nextDue = null;
foreach ($rows as $r) {
    $id = (int)$r['id'];
    $lines = imp_lines($id);
    $log = imp_logistics($id);
    $sum = imp_paid_summary($r, $lines);
    $st = imp_stage($r, $log);
    $al = imp_alerts($r, $log, $sum);
    $m = imp_maturity($r, $log);
    $t = imp_totals($lines);
    $closed = in_array($st['key'], ['closed', 'cancelled'], true);
    if ($closed) $kpi['closed']++; else $kpi['open']++;
    if (in_array($st['key'], ['sailing', 'arrived', 'customs', 'released', 'left_port'], true)) $kpi['transit']++;
    if (!$closed && $sum['balance'] > 0.0049) {
        $kpi['payable'][$sum['currency']] = ($kpi['payable'][$sum['currency']] ?? 0) + $sum['balance'];
        if ($m['date'] && ($nextDue === null || $m['date'] < $nextDue)) $nextDue = $m['date'];
    }
    $kpi['alerts'] += count($al);

    if ($show === 'open' && $closed) continue;
    if ($type !== '' && $r['import_type'] !== $type) continue;
    if ($q !== '') {
        $hay = mb_strtolower($r['ref'] . ' ' . $r['supplier_name'] . ' ' . $r['contract_no'] . ' ' . implode(' ', array_column($lines, 'description')) . ' ' . ($log['bl_no'] ?? ''));
        if (mb_strpos($hay, mb_strtolower($q)) === false) continue;
    }
    $list[] = ['r' => $r, 'lines' => $lines, 'sum' => $sum, 'st' => $st, 'al' => $al, 'm' => $m, 't' => $t, 'log' => $log];
}

page_header('Import Register');
flash();
echo imp_page_css();
?>
<div style="display:flex;justify-content:space-between;align-items:center;flex-wrap:wrap;gap:10px;margin-bottom:12px">
  <h1 style="font-size:20px;margin:0">Import Register</h1>
  <?php if (imp_can('imp', 'c')): ?><a class="xbtn" href="imp_new.php">+ New Import</a><?php endif; ?>
</div>

<div class="xkpi">
  <div class="k"><div class="l">Open imports</div><div class="v"><?= (int)$kpi['open'] ?></div><div class="imp-small imp-muted"><?= (int)$kpi['closed'] ?> closed</div></div>
  <div class="k"><div class="l">In transit / at port</div><div class="v"><?= (int)$kpi['transit'] ?></div><div class="imp-small imp-muted">not in stock yet</div></div>
  <div class="k"><div class="l">Supplier payable</div><div class="v" style="font-size:15px"><?= $kpi['payable'] ? e(implode(' · ', array_map(fn($c, $v) => money_fmt($v, $c), array_keys($kpi['payable']), $kpi['payable']))) : '—' ?></div><div class="imp-small imp-muted"><?= $nextDue ? 'next due ' . e(imp_dmy($nextDue)) : '' ?></div></div>
  <div class="k"><div class="l">Alerts</div><div class="v" style="color:<?= $kpi['alerts'] ? '#b8283f' : '#152033' ?>"><?= (int)$kpi['alerts'] ?></div><div class="imp-small imp-muted">free time · payments · LC</div></div>
</div>

<form class="xcard" method="get" style="display:flex;gap:10px;flex-wrap:wrap;align-items:flex-end">
  <label class="xlabel" style="flex:2;min-width:200px">Search<input class="xin" name="q" value="<?= e($q) ?>" placeholder="Reference, supplier, contract, product, BL…"></label>
  <?= imp_select('Type', 'type', ['' => 'All', 'efs' => 'EFS', 'ordinary' => 'Ordinary'], $type, '', true) ?>
  <?= imp_select('Show', 'show', ['open' => 'Open only', 'all' => 'All, closed too'], $show, '', true) ?>
  <button class="xbtn sec">Filter</button>
</form>

<div class="xcard">
  <?php if (!$list): ?>
    <div class="xnote"><?= $rows ? 'Nothing matches this filter.' : 'No imports yet. Start with + New Import — or, for imports already under way before go-live, tick "Opening import" on it.' ?></div>
  <?php else: ?>
  <div class="xwrap">
    <table class="xtable">
      <thead><tr><th>Import</th><th>Supplier · goods</th><th>Type</th><th class="num">Invoice value</th><th class="num">Net kg</th><th>Status</th><th>ETA</th><th class="num">Payable</th><th>Due</th></tr></thead>
      <tbody>
      <?php foreach ($list as $x): $r = $x['r']; ?>
        <tr style="cursor:pointer" onclick="location.href='imp_view.php?id=<?= (int)$r['id'] ?>'">
          <td><a href="imp_view.php?id=<?= (int)$r['id'] ?>" style="font-weight:800"><?= e($r['ref']) ?></a><?= (int)$r['is_opening'] === 1 ? '<div class="imp-small imp-muted">opening</div>' : '' ?></td>
          <td><?= e($r['supplier_name']) ?>
            <div class="imp-small imp-muted"><?= e(implode(', ', array_slice(array_filter(array_column($x['lines'], 'description')), 0, 2))) ?><?= count($x['lines']) > 2 ? ' +' . (count($x['lines']) - 2) . ' more' : '' ?></div>
            <?php foreach ($x['al'] as $a): ?><div class="imp-small" style="color:<?= $a[0] === 'r' ? '#b8283f' : '#9a5a06' ?>;font-weight:700">● <?= e($a[1]) ?></div><?php endforeach; ?></td>
          <td><span class="xpill <?= $r['import_type'] === 'efs' ? 'v' : 'n' ?>"><?= $r['import_type'] === 'efs' ? 'EFS' : 'Ordinary' ?></span></td>
          <td class="num"><?= e(money_fmt($x['sum']['total'], $x['sum']['currency'])) ?></td>
          <td class="num"><?= e(number_format($x['t']['kg'])) ?></td>
          <td><span class="xpill <?= e($x['st']['pill']) ?>"><?= e(preg_replace('/ · ETA.*/', '', $x['st']['label'])) ?></span></td>
          <td><?= e(imp_dmy(!empty($x['log']['arrival']) ? $x['log']['arrival'] : ($x['log']['eta'] ?? null))) ?></td>
          <td class="num"><?= $x['sum']['balance'] > 0.0049 ? e(money_fmt($x['sum']['balance'], $x['sum']['currency'])) : '—' ?></td>
          <td><?= $x['sum']['balance'] > 0.0049 && $x['m']['date'] ? e(imp_dmy($x['m']['date'])) : '—' ?></td>
        </tr>
      <?php endforeach; ?>
      </tbody>
    </table>
  </div>
  <?php endif; ?>
</div>
<?php page_footer(); ?>
