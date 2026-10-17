<?php
/*
  IMPORT — GATE IN TAB. Phase 2.

  What has arrived, line by line, in each item's own unit with the kg in
  brackets: "12,000 MTR (3,600 kg)". Receiving is not done here: "Receive
  into stock" opens the existing Gate Inward, filled from this import, and
  stock moves only when that pass is posted there — once, by Gate Inward's
  own rule. Several trucks are several passes; reversing a pass on Gate
  Inward brings the balance back by itself, because everything below is
  read from the passes.

  Damaged / rejected / short are recorded on the pass for the supplier claim
  and never go into stock (afnan: no quarantine).
*/
require_once __DIR__ . '/includes/bootstrap.php';
require_once __DIR__ . '/includes/imports.php';

$imp = imp_open('imp');
$id  = (int)$imp['id'];
$lines = imp_lines($id);
$items = imp_item_options(false);
$landed = imp_landed($imp, $lines);
$got = imp_receipt_totals($id);
$passes = imp_receipt_passes($id);
$blockers = imp_receive_blockers($imp, $lines, $items, $landed);
$canGate = function_exists('inv_perm') && inv_perm('gate');

$tot = ['kg' => 0.0, 'kg_got' => 0.0, 'open' => 0];
$rows = [];
foreach (array_values($lines) as $i => $l) {
    $it = $items[imp_line_key($l)] ?? null;
    $st = imp_stock_qty($l, $it);
    $g = $got[(int)$l['id']] ?? ['posted' => 0, 'draft' => 0, 'kg_posted' => 0, 'kg_draft' => 0, 'damaged' => 0, 'rejected' => 0, 'short' => 0];
    $bal = $st['ok'] ? round($st['qty'] - $g['posted'], 3) : null;
    $tot['kg'] += (float)$l['net_kg']; $tot['kg_got'] += $g['kg_posted'];
    if ($bal === null || $bal > 0.0005) $tot['open']++;
    $rows[] = ['n' => $i + 1, 'l' => $l, 'it' => $it, 'st' => $st, 'g' => $g, 'bal' => $bal,
               'per' => $st['ok'] && $st['qty'] > 0 ? ($landed['rows'][$i]['landed'] ?? 0) / $st['qty'] : null];
}
$anyPosted = array_filter($passes, fn($p) => $p['status'] === 'posted');
$status = !$anyPosted ? 'Nothing received yet' : ($tot['open'] === 0 ? 'Fully received' : 'Partly received');

page_header('Gate In — ' . $imp['ref']);
flash();
echo imp_page_css();
imp_tab_strip($imp, 'gatein');
?>
<style>.kgb{color:#5a49c2;font-weight:600;white-space:nowrap}.gi-q{white-space:nowrap}</style>
<div class="xkpi">
  <div class="k"><div class="l">Received (net kg)</div><div class="v"><?= e(number_format($tot['kg_got'], 3)) ?></div><div class="s">of <?= e(number_format($tot['kg'], 3)) ?> kg invoiced</div></div>
  <div class="k"><div class="l">Gate passes</div><div class="v"><?= count($passes) ?></div><div class="s"><?= count($anyPosted) ?> posted</div></div>
  <div class="k"><div class="l">Status</div><div class="v" style="font-size:14px"><?= e($status) ?></div><div class="s">updates when a pass is posted or reversed</div></div>
</div>

<div class="xcard">
  <div style="display:flex;justify-content:space-between;align-items:center;flex-wrap:wrap;gap:8px">
    <h2 style="margin:0">What has arrived, line by line</h2>
    <?php if ($blockers): ?>
      <span class="imp-small imp-muted">Receive into stock opens when the points below are fixed.</span>
    <?php elseif (!$canGate): ?>
      <span class="imp-small imp-muted">Receiving needs the Gate Inward permission (Store &amp; Inventory).</span>
    <?php else: ?>
      <a class="xbtn" href="inv_gate.php?dir=in&amp;new=1&amp;import=<?= $id ?>">Receive into stock →</a>
    <?php endif; ?>
  </div>
  <?php if ($blockers): ?><div class="<?= (int)$imp['already_in_stock'] === 1 ? 'xnote' : 'xbad' ?>" style="margin-top:10px"><?= implode('<br>', array_map('e', $blockers)) ?></div><?php endif; ?>
  <div class="xwrap" style="margin-top:10px"><table class="xtable" style="min-width:980px">
    <thead><tr><th>#</th><th>Item</th><th class="num">Invoiced</th><th class="num">Received</th><th class="num">On a draft pass</th><th class="num">Still to come</th><th class="num">Damaged · rejected · short</th><th class="num">Rate / unit</th></tr></thead>
    <tbody>
    <?php foreach ($rows as $r): $u = $r['st']['uom'] ?? ''; $okU = $r['st']['ok']; $kg = (float)$r['l']['net_kg']; $g = $r['g']; ?>
      <tr<?= $r['bal'] !== null && $r['bal'] < -0.0005 ? ' style="background:rgba(224,67,93,.06)"' : '' ?>>
        <td><?= $r['n'] ?></td>
        <td><?= $r['it'] ? '<span style="font-family:\'IBM Plex Mono\',monospace;font-size:11.5px;color:#5a6b82">' . e($r['it']['code']) . '</span> ' . e($r['it']['name']) . (!empty($r['l']['size_label']) ? ' · ' . e($r['l']['size_label']) : '') : '<span class="imp-muted">' . e($r['l']['description']) . ' — not linked</span>' ?></td>
        <td class="num gi-q"><?= $okU ? kgq($r['st']['qty'], $u, $kg) : '—' ?></td>
        <td class="num gi-q"><?= $okU ? kgq($g['posted'], $u, $g['kg_posted']) : '—' ?></td>
        <td class="num gi-q"><?= $g['draft'] > 0 ? kgq($g['draft'], $u, $g['kg_draft']) : '—' ?></td>
        <td class="num gi-q"><?php if ($r['bal'] === null) echo '—'; elseif ($r['bal'] < -0.0005) echo '<b style="color:#b8283f">' . e(rtrim(rtrim(number_format(-$r['bal'], 3), '0'), '.') . ' ' . $u) . ' over</b>'; else echo '<b>' . kgq($r['bal'], $u, max(0, $kg - $g['kg_posted'])) . '</b>'; ?></td>
        <td class="num"><?= $g['damaged'] + $g['rejected'] + $g['short'] > 0 ? e(rtrim(rtrim(number_format($g['damaged'], 3), '0'), '.') . ' · ' . rtrim(rtrim(number_format($g['rejected'], 3), '0'), '.') . ' · ' . rtrim(rtrim(number_format($g['short'], 3), '0'), '.')) : '—' ?></td>
        <td class="num"><?= $r['per'] !== null ? 'PKR ' . e(number_format($r['per'], 2)) . ' / ' . e($u) : '—' ?></td>
      </tr>
    <?php endforeach; ?>
    </tbody>
  </table></div>
  <div class="xnote" style="margin-top:12px">Quantities are in each item's own stock unit, with the <span class="kgb">kg in brackets</span> for EFS — the kg stays on the import and never enters the stock ledger.
    The rate is the landed cost per stock unit<?= $landed['provisional'] ? ' (<b>provisional</b> — some costs are still estimates)' : '' ?>; a pass keeps the rate it was posted at, and a bill that arrives later updates landed cost on COSTS without changing stock already posted.</div>
</div>

<div class="xcard">
  <h2>Gate Inward passes for this import</h2>
  <?php if (!$passes): ?>
    <div class="xnote">None yet. Each truck is one Gate Inward pass; they are listed here and in Store &amp; Inventory → Gate Inward.</div>
  <?php else: ?>
  <div class="xwrap"><table class="xtable" style="min-width:620px">
    <thead><tr><th>Pass</th><th>Date</th><th>Vehicle</th><th>Container</th><th>Status</th><th></th></tr></thead>
    <tbody><?php foreach ($passes as $p): ?>
      <tr><td style="font-family:'IBM Plex Mono',monospace"><?= e($p['gate_no']) ?></td><td><?= e(imp_dmy($p['gate_date'])) ?></td><td><?= e($p['vehicle_no'] ?: '—') ?></td><td><?= e($p['container_no'] ?: '—') ?></td>
        <td><span class="xpill <?= ['posted' => 'g', 'reversed' => 'r', 'verified' => 'b'][$p['status']] ?? 'o' ?>"><?= e(ucfirst($p['status'])) ?></span></td>
        <td><a class="xbtn sec sm" href="inv_gate.php?id=<?= (int)$p['id'] ?>">Open</a></td></tr>
    <?php endforeach; ?></tbody>
  </table></div>
  <div class="imp-small imp-muted" style="margin-top:8px">Post, edit, reverse or delete a pass on Gate Inward as usual — this tab follows it.</div>
  <?php endif; ?>
</div>
<?php
function kgq(float $q, string $u, ?float $kg): string
{
    $f = static fn($v) => rtrim(rtrim(number_format($v, 3), '0'), '.');
    return e($f($q) . ' ' . $u) . ($kg !== null && imp_uom_unit($u) !== 'kg' ? ' <span class="kgb">(' . e($f($kg)) . ' kg)</span>' : '');
}
page_footer(); ?>
