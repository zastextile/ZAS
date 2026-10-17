<?php
/*
  IMPORT — LOGISTICS TAB.

  Booking, vessel, BL, containers, and after arrival each date on its own:
  GD filed, customs release, delivery order, port gate out, factory gate in,
  empty container returned. The shipping line's free days give the last free
  day (from arrival, or ETA until arrival is typed) and a red alert as it
  runs out. Tracking is typed by hand — nothing simulated.

  These dates also drive the import's status, the payment due date (BL or
  arrival based terms) and the EFS clock (customs release).
*/
require_once __DIR__ . '/includes/bootstrap.php';
require_once __DIR__ . '/includes/imports.php';

$imp = imp_open('imp');
$id  = (int)$imp['id'];

const IMP_LOG_DATES = ['booking_date', 'etd', 'eta', 'departed', 'arrival', 'bl_date', 'gd_filed', 'customs_release', 'do_date', 'port_gate_out', 'factory_gate_in', 'empty_return'];
const IMP_LOG_TEXT  = ['booking_no' => 80, 'vessel' => 120, 'voyage' => 40, 'pol' => 120, 'transship' => 120, 'pod' => 120, 'bl_no' => 80, 'bl_type' => 30, 'freight_currency' => 10, 'last_event' => 190, 'last_location' => 190];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verify_csrf();
    $action = (string)($_POST['action'] ?? '');
    try {
        if ($action === 'save') {
            imp_require('imp', 'u');
            $before = imp_logistics($id);
            $v = [];
            foreach (IMP_LOG_DATES as $k) {
                $raw = trim((string)($_POST[$k] ?? ''));
                $v[$k] = imp_date($raw);
                if ($raw !== '' && !$v[$k]) throw new Exception('A date is not valid: ' . str_replace('_', ' ', $k) . '.');
            }
            foreach (IMP_LOG_TEXT as $k => $max) $v[$k] = mb_substr(trim((string)($_POST[$k] ?? '')), 0, $max) ?: null;
            $v['forwarder_id'] = (int)($_POST['forwarder_id'] ?? 0) ?: null;
            $v['shipping_line_id'] = (int)($_POST['shipping_line_id'] ?? 0) ?: null;
            $v['bl_released'] = !empty($_POST['bl_released']) ? 1 : 0;
            $v['free_days'] = max(0, min(365, (int)imp_num($_POST['free_days'] ?? 0)));
            $v['terminal_free_days'] = max(0, min(365, (int)imp_num($_POST['terminal_free_days'] ?? 0)));
            $v['freight_agreed'] = trim((string)($_POST['freight_agreed'] ?? '')) === '' ? null : imp_num($_POST['freight_agreed']);
            $v['notes'] = trim((string)($_POST['notes'] ?? '')) ?: null;
            /* dates that cannot come before others */
            foreach ([['arrival', 'departed', 'Arrival cannot be before departure.'], ['customs_release', 'gd_filed', 'Customs release cannot be before the GD was filed.'],
                      ['port_gate_out', 'customs_release', 'Port gate out cannot be before customs release.'], ['factory_gate_in', 'port_gate_out', 'Factory gate in cannot be before port gate out.'],
                      ['empty_return', 'port_gate_out', 'The empty cannot be returned before the container left the port.']] as [$a, $b, $msg])
                if ($v[$a] && $v[$b] && $v[$a] < $v[$b]) throw new Exception($msg);
            $v['tracking_at'] = ((string)($before['last_event'] ?? '') !== (string)$v['last_event'] || (string)($before['last_location'] ?? '') !== (string)$v['last_location'])
                ? date('Y-m-d H:i:s') : ($before['tracking_at'] ?? null);

            $cols = array_keys($v);
            $c = db()->prepare("SELECT COUNT(*) FROM imp_logistics WHERE import_id=?"); $c->execute([$id]);
            if ((int)$c->fetchColumn() > 0) {
                db()->prepare("UPDATE imp_logistics SET " . implode(', ', array_map(fn($k) => "$k=?", $cols)) . ", updated_by=?, updated_at=NOW() WHERE import_id=?")
                    ->execute(array_merge(array_values($v), [(int)(current_user()['id'] ?? 0), $id]));
            } else {
                db()->prepare("INSERT INTO imp_logistics (import_id, " . implode(', ', $cols) . ", updated_by, updated_at) VALUES (?," . implode(',', array_fill(0, count($cols), '?')) . ",?,NOW())")
                    ->execute(array_merge([$id], array_values($v), [(int)(current_user()['id'] ?? 0)]));
            }
            $ch = [];
            foreach ($v as $k => $val) if ((string)($before[$k] ?? '') !== (string)($val ?? '') && $k !== 'tracking_at' && $k !== 'notes') $ch[] = str_replace('_', ' ', $k) . ($val !== null && $val !== '' ? ' ' . $val : ' cleared');
            if ($ch) imp_log($id, 'Logistics saved', implode(' · ', $ch));
            $_SESSION['flash'] = 'Logistics saved.';
        }
        if ($action === 'add_container') {
            imp_require('imp', 'u');
            $no = strtoupper(preg_replace('/\s+/', ' ', trim((string)($_POST['container_no'] ?? ''))));
            if ($no === '') throw new Exception('Type the container number.');
            $d = db()->prepare("SELECT COUNT(*) FROM imp_containers WHERE import_id=? AND container_no=?"); $d->execute([$id, $no]);
            if ((int)$d->fetchColumn() > 0) throw new Exception('Container ' . $no . ' is already on this import.');
            db()->prepare("INSERT INTO imp_containers (import_id, container_no, size_type, seal_no, notes) VALUES (?,?,?,?,?)")
                ->execute([$id, mb_substr($no, 0, 30), mb_substr(trim((string)($_POST['size_type'] ?? '')), 0, 30) ?: null,
                           mb_substr(trim((string)($_POST['seal_no'] ?? '')), 0, 60) ?: null, mb_substr(trim((string)($_POST['notes'] ?? '')), 0, 255) ?: null]);
            imp_log($id, 'Container added', $no);
            $_SESSION['flash'] = 'Container ' . $no . ' added.';
        }
        if ($action === 'del_container') {
            imp_require('imp', 'u');
            $cid = (int)($_POST['container_id'] ?? 0);
            db()->prepare("UPDATE imp_packing SET container_id=NULL WHERE import_id=? AND container_id=?")->execute([$id, $cid]);
            db()->prepare("DELETE FROM imp_containers WHERE id=? AND import_id=?")->execute([$cid, $id]);
            imp_log($id, 'Container removed', '#' . $cid);
            $_SESSION['flash'] = 'Container removed (its packing rows are kept, without a container).';
        }
    } catch (Throwable $e) {
        $_SESSION['error'] = $e->getMessage();
    }
    redirect('imp_logistics.php?id=' . $id);
}

$L = imp_logistics($id);
$conts = imp_containers($id);
$pk = []; foreach (imp_packing($id) as $r) if ($r['container_id']) $pk[(int)$r['container_id']] = ($pk[(int)$r['container_id']] ?? 0) + (int)$r['packages'];
$lfd = imp_last_free_day($L);
$alerts = array_filter(imp_alerts($imp, $L), fn($a) => stripos($a[1], 'free') !== false || stripos($a[1], 'demurrage') !== false);
/* freight actually billed, from COSTS (cost types with "Freight" in the name) */
$freightActual = null;
foreach (imp_costs($id) as $c) {
    if ((int)$c['is_void'] === 1 || $c['amount'] === null) continue;
    $lbl = exp_master_map('imp_cost_type')[(int)$c['cost_type_id']] ?? '';
    if (stripos($lbl, 'freight') === false) continue;
    $freightActual = ($freightActual ?? 0) + (float)$c['amount'];
}
$canEdit = imp_can('imp', 'u'); $ro = $canEdit ? '' : ' disabled';
$fwd = ['' => '—']; foreach (exp_providers('freight_forwarder') as $p) $fwd[(int)$p['id']] = $p['name'];
$lines = ['' => '—']; foreach (exp_masters('shipping_line') as $p) $lines[(int)$p['id']] = $p['label'];
$ctypes = array_map(fn($r) => $r['label'], exp_masters('container_type'));
$ports = array_map(fn($r) => $r['label'], exp_masters('port_loading'));

page_header('Logistics — ' . $imp['ref']);
flash();
echo imp_page_css();
imp_tab_strip($imp, 'logistics');
?>
<?php foreach ($alerts as $a): ?><div class="xbad" style="margin-bottom:10px"><b><?= e($a[1]) ?></b></div><?php endforeach; ?>
<form method="post">
<?= csrf_field() ?><input type="hidden" name="action" value="save"><input type="hidden" name="import_id" value="<?= $id ?>">
<datalist id="podList"><?php foreach ($ports as $p): ?><option value="<?= e($p) ?>"><?php endforeach; ?></datalist>
<div class="xcard">
  <h2>Booking &amp; vessel</h2>
  <div class="xgrid">
    <?= imp_input('Booking no.', 'booking_no', $L['booking_no'] ?? '', 'text', $ro) ?>
    <?= imp_input('Booking date', 'booking_date', $L['booking_date'] ?? '', 'date', $ro) ?>
    <?= imp_select('Freight forwarder', 'forwarder_id', $fwd, $L['forwarder_id'] ?? '', $ro, true) ?>
    <?= imp_select('Shipping line', 'shipping_line_id', $lines, $L['shipping_line_id'] ?? '', $ro, true) ?>
    <?= imp_input('Vessel', 'vessel', $L['vessel'] ?? '', 'text', $ro) ?>
    <?= imp_input('Voyage', 'voyage', $L['voyage'] ?? '', 'text', $ro) ?>
    <?= imp_input('Port of loading', 'pol', $L['pol'] ?? '', 'text', $ro . ' placeholder="e.g. Ningbo"') ?>
    <?= imp_input('Transshipment port', 'transship', $L['transship'] ?? '', 'text', $ro) ?>
    <?= imp_input('Port of discharge / terminal', 'pod', $L['pod'] ?? '', 'text', $ro . ' list="podList"') ?>
    <?= imp_input('ETD', 'etd', $L['etd'] ?? '', 'date', $ro) ?>
    <?= imp_input('Actual departure', 'departed', $L['departed'] ?? '', 'date', $ro) ?>
    <?= imp_input('ETA', 'eta', $L['eta'] ?? '', 'date', $ro) ?>
    <?= imp_input('Actual arrival', 'arrival', $L['arrival'] ?? '', 'date', $ro) ?>
  </div>
  <h2 style="margin-top:16px">Bill of lading</h2>
  <div class="xgrid">
    <?= imp_input('BL no.', 'bl_no', $L['bl_no'] ?? '', 'text', $ro) ?>
    <?= imp_input('BL date', 'bl_date', $L['bl_date'] ?? '', 'date', $ro) ?>
    <?= imp_select('BL type', 'bl_type', ['', 'Original', 'Telex release', 'Seaway bill', 'House BL'], $L['bl_type'] ?? '', $ro) ?>
    <label class="tog" style="padding-top:22px"><input type="checkbox" name="bl_released" value="1"<?= !empty($L['bl_released']) ? ' checked' : '' ?><?= $ro ?>> BL released</label>
  </div>
</div>

<div class="xcard">
  <h2>After arrival — each date on its own</h2>
  <div class="xgrid">
    <?= imp_input('GD filed', 'gd_filed', $L['gd_filed'] ?? '', 'date', $ro) ?>
    <?= imp_input('Customs release', 'customs_release', $L['customs_release'] ?? '', 'date', $ro) ?>
    <?= imp_input('Delivery order', 'do_date', $L['do_date'] ?? '', 'date', $ro) ?>
    <?= imp_input('Port / terminal gate out', 'port_gate_out', $L['port_gate_out'] ?? '', 'date', $ro) ?>
    <?= imp_input('Factory gate in', 'factory_gate_in', $L['factory_gate_in'] ?? '', 'date', $ro) ?>
    <?= imp_input('Empty container returned', 'empty_return', $L['empty_return'] ?? '', 'date', $ro) ?>
    <?= imp_input('Free days (shipping line)', 'free_days', $L['free_days'] ?? 0, 'number', $ro) ?>
    <?= imp_input('Free days (terminal)', 'terminal_free_days', $L['terminal_free_days'] ?? 0, 'number', $ro) ?>
    <label class="xlabel">Last free day (shipping line)<div class="xin calc" style="background:#f6f8fc"><?= e(imp_dmy($lfd)) ?></div></label>
    <?php if (!empty($L['terminal_free_days'])): $tfd = imp_add_days(!empty($L['arrival']) ? $L['arrival'] : ($L['eta'] ?? null), (int)$L['terminal_free_days']); ?>
      <label class="xlabel">Last free day (terminal)<div class="xin calc" style="background:#f6f8fc"><?= e(imp_dmy($tfd)) ?></div></label>
    <?php endif; ?>
  </div>
  <div class="imp-small imp-muted" style="margin-top:8px">Free time is counted from <?= !empty($L['arrival']) ? 'arrival' : 'ETA, until arrival is typed' ?>. The terminal and the shipping line can allow different days.</div>
</div>

<div class="xcard">
  <h2>Tracking &amp; freight</h2>
  <div class="xgrid">
    <?= imp_input('Last event', 'last_event', $L['last_event'] ?? '', 'text', $ro . ' placeholder="e.g. Discharged at KICT"') ?>
    <?= imp_input('Where', 'last_location', $L['last_location'] ?? '', 'text', $ro) ?>
    <label class="xlabel">Updated<div class="xin" style="background:#f6f8fc"><?= !empty($L['tracking_at']) ? e(date('d M Y H:i', strtotime($L['tracking_at']))) : '—' ?></div></label>
    <?= imp_input('Freight agreed', 'freight_agreed', $L['freight_agreed'] ?? '', 'number', $ro) ?>
    <?= imp_select('Freight currency', 'freight_currency', array_merge([''], IMP_CURRENCIES), $L['freight_currency'] ?? $imp['currency'], $ro) ?>
    <label class="xlabel">Freight billed (from COSTS)<div class="xin calc" style="background:#f6f8fc;color:<?= $freightActual !== null && $L['freight_agreed'] !== null && $freightActual > (float)$L['freight_agreed'] ? '#b8283f' : '#152033' ?>"><?= $freightActual === null ? 'no bill yet' : e(number_format($freightActual, 2)) . ($L['freight_agreed'] !== null && $L['freight_agreed'] !== '' ? ' (' . ($freightActual - (float)$L['freight_agreed'] >= 0 ? '+' : '') . e(number_format($freightActual - (float)$L['freight_agreed'], 2)) . ')' : '') ?></div></label>
  </div>
  <label class="xlabel" style="margin-top:10px">Notes<textarea class="xin" name="notes" rows="2"<?= $ro ?>><?= e($L['notes'] ?? '') ?></textarea></label>
</div>
<?php if ($canEdit): ?><div style="margin-bottom:14px"><button class="xbtn">Save logistics</button></div><?php endif; ?>
</form>

<div class="xcard">
  <h2>Containers</h2>
  <div class="xwrap"><table class="xtable">
    <thead><tr><th>Container</th><th>Size / type</th><th>Seal</th><th class="num">Packages (from PACKING)</th><th>Notes</th><th></th></tr></thead>
    <tbody>
    <?php if (!$conts): ?><tr><td colspan="6" class="imp-muted" style="padding:14px;text-align:center">No containers yet.</td></tr><?php endif; ?>
    <?php foreach ($conts as $c): ?>
      <tr><td><b><?= e($c['container_no']) ?></b></td><td><?= e($c['size_type']) ?></td><td><?= e($c['seal_no']) ?></td><td class="num"><?= (int)($pk[(int)$c['id']] ?? 0) ?></td><td><?= e($c['notes']) ?></td>
        <td><?php if ($canEdit): ?><form method="post" style="margin:0" onsubmit="return confirm('Remove container <?= e($c['container_no']) ?>?')"><?= csrf_field() ?><input type="hidden" name="action" value="del_container"><input type="hidden" name="import_id" value="<?= $id ?>"><input type="hidden" name="container_id" value="<?= (int)$c['id'] ?>"><button class="xbtn red sm">✕</button></form><?php endif; ?></td></tr>
    <?php endforeach; ?>
    </tbody>
  </table></div>
  <?php if ($canEdit): ?>
  <form method="post" style="margin-top:12px"><?= csrf_field() ?><input type="hidden" name="action" value="add_container"><input type="hidden" name="import_id" value="<?= $id ?>">
    <div class="xgrid"><?= imp_input('Container no.', 'container_no', '', 'text', ' required placeholder="e.g. CSNU 718220-4"') ?><?= imp_select('Size / type', 'size_type', array_merge([''], $ctypes), '') ?><?= imp_input('Seal no.', 'seal_no', '') ?><?= imp_input('Notes', 'notes', '') ?></div>
    <div style="margin-top:10px"><button class="xbtn sec">+ Add container</button></div>
  </form>
  <?php endif; ?>
</div>
<?php page_footer(); ?>
