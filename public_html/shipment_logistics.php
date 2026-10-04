<?php
/*
  LOGISTICS TAB — booking, vessel, dates, containers, BL.

  Independent of the invoice lock, deliberately. Almost everything on this
  page happens AFTER the commercial invoice is approved: the container is
  loaded, the vessel sails, the BL is issued, the ship arrives. If the
  invoice lock reached this page the module would be unusable on the day it
  shipped. The control here is the permission plus the audit trail.

  Destination Port is NOT repeated on this page. It already lives on the
  invoice header, and a second copy would be two answers to one question.
*/
require_once __DIR__ . '/includes/bootstrap.php';
require_once __DIR__ . '/includes/export.php';
require_once __DIR__ . '/includes/textindex.php';

$shipment = exp_open_shipment('logistics');
$id       = (int)$shipment['id'];
$canEdit  = exp_can('logistics', 'u');

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verify_csrf();
    /* KEEPING THE SEARCH INDEX CURRENT.
     *
     * Registered once, here, rather than bolted onto each of this page's
     * redirects — there are several and a new one would quietly skip the
     * index. A shutdown function runs after the response has gone, so this
     * cannot slow the save down, and every txt_* call swallows its own
     * errors, so it cannot break one either. Re-indexing an unchanged
     * record is harmless: the write is an upsert keyed on the record. */
    register_shutdown_function(function () use ($id) { txt_index_shipment_notes((int)$id); });

    $action = (string)($_POST['action'] ?? '');

    try {
        if ($action === 'save_logistics') {
            exp_require('logistics', 'u');
            $before = exp_logistics($id);

            $d = function (string $k) { $v = trim((string)($_POST[$k] ?? '')); return $v === '' ? null : $v; };
            $n = function (string $k) { $v = trim((string)($_POST[$k] ?? '')); return $v === '' ? null : (float)$v; };
            $i = function (string $k) { $v = (int)($_POST[$k] ?? 0); return $v > 0 ? $v : null; };

            $cols = [
                'loading_port_id'         => $i('loading_port_id'),
                'shipping_line_id'        => $i('shipping_line_id'),
                'vessel_name'             => $d('vessel_name'),
                'voyage_no'               => $d('voyage_no'),
                'expected_load_date'      => $d('expected_load_date'),
                'actual_load_date'        => $d('actual_load_date'),
                'etd_pakistan'            => $d('etd_pakistan'),
                'eta_destination'         => $d('eta_destination'),
                'actual_arrival_date'     => $d('actual_arrival_date'),
                'transit_days'            => $i('transit_days'),
                'bl_no'                   => $d('bl_no'),
                'bl_date'                 => $d('bl_date'),
                'bl_stage'                => in_array($_POST['bl_stage'] ?? '', ['draft', 'final'], true) ? $_POST['bl_stage'] : null,
                'freight_provider_id'     => $i('freight_provider_id'),
                'freight_agreed_amount'   => $n('freight_agreed_amount'),
                'freight_agreed_currency' => $d('freight_agreed_currency'),
                'freight_quote_ref'       => $d('freight_quote_ref'),
                'freight_quote_date'      => $d('freight_quote_date'),
                'last_event'              => $d('last_event'),
                'last_location'           => $d('last_location'),
                'notes'                   => $d('notes'),
            ];

            $set = implode(',', array_map(fn($c) => "$c=?", array_keys($cols)));
            $sql = "INSERT INTO shipment_logistics (shipment_id," . implode(',', array_keys($cols)) . ",updated_by,updated_at)
                    VALUES (?," . implode(',', array_fill(0, count($cols), '?')) . ",?,NOW())
                    ON DUPLICATE KEY UPDATE $set, updated_by=VALUES(updated_by), updated_at=NOW()";
            $params = array_merge([$id], array_values($cols), [current_user()['id']], array_values($cols));
            db()->prepare($sql)->execute($params);

            /* The logistics status lives on `shipments` so the list screen and
               the board can filter on it without a join. It is NOT the
               `status` column — that one is the invoice approval state. */
            $ls = trim((string)($_POST['logistics_status'] ?? ''));
            db()->prepare("UPDATE shipments SET logistics_status=? WHERE id=?")->execute([$ls !== '' ? $ls : null, $id]);

            /* Only the fields that actually moved are written to the audit log,
               so the timeline reads as a history rather than a dump. */
            foreach ($cols as $k => $v) {
                $old = $before[$k] ?? null;
                if ((string)$old !== (string)$v) {
                    audit_log($id, 'Logistics', $k, (string)$old, (string)$v, 'Logistics updated');
                }
            }
            if ((string)($shipment['logistics_status'] ?? '') !== $ls) {
                audit_log($id, 'Logistics', 'logistics_status', (string)($shipment['logistics_status'] ?? ''), $ls, 'Shipment status changed');
            }

            $_SESSION['flash'] = 'Logistics saved.';
            redirect('shipment_logistics.php?id=' . $id);
        }

        if ($action === 'save_container') {
            exp_require('logistics', 'u');
            $cid  = (int)($_POST['container_id'] ?? 0);
            $no   = trim((string)($_POST['container_no'] ?? ''));
            $from = (int)($_POST['carton_from'] ?? 0);
            $to   = (int)($_POST['carton_to'] ?? 0);

            if ($no === '') throw new Exception('Container number is needed.');
            if ($from < 0 || $to < 0) throw new Exception('Carton numbers cannot be negative.');
            if ($from > 0 && $to < $from) throw new Exception('Carton range is the wrong way round.');

            $vals = [
                $no,
                (int)($_POST['container_type_id'] ?? 0) ?: null,
                trim((string)($_POST['seal_no'] ?? '')) ?: null,
                trim((string)($_POST['vgm_kg'] ?? '')) !== '' ? (float)$_POST['vgm_kg'] : null,
                trim((string)($_POST['tare_kg'] ?? '')) !== '' ? (float)$_POST['tare_kg'] : null,
                trim((string)($_POST['cartons'] ?? '')) !== '' ? (float)$_POST['cartons'] : null,
                trim((string)($_POST['notes'] ?? '')) ?: null,
            ];

            db()->beginTransaction();
            if ($cid > 0) {
                $vals[] = $cid; $vals[] = $id;
                db()->prepare("UPDATE shipment_containers SET container_no=?, container_type_id=?, seal_no=?, vgm_kg=?, tare_kg=?, cartons=?, notes=? WHERE id=? AND shipment_id=?")
                    ->execute($vals);
            } else {
                array_unshift($vals, $id);
                $vals[] = current_user()['id'];
                db()->prepare("INSERT INTO shipment_containers (shipment_id, container_no, container_type_id, seal_no, vgm_kg, tare_kg, cartons, notes, created_by) VALUES (?,?,?,?,?,?,?,?,?)")
                    ->execute($vals);
                $cid = (int)db()->lastInsertId();
            }

            /* Carton ranges are their own rows, NOT a column on packing_items —
               shipment_save.php deletes and re-inserts every packing row, so a
               column there would be wiped the next time the invoice was saved. */
            db()->prepare("DELETE FROM shipment_container_cartons WHERE container_id=?")->execute([$cid]);
            if ($from > 0 && $to >= $from) {
                db()->prepare("INSERT INTO shipment_container_cartons (container_id, shipment_id, carton_from, carton_to) VALUES (?,?,?,?)")
                    ->execute([$cid, $id, $from, $to]);
            }

            db()->commit();
            audit_log($id, 'Container', 'container', '', $no . ($from > 0 ? " (cartons $from-$to)" : ''), 'Container saved');
            $_SESSION['flash'] = 'Container saved.';
            redirect('shipment_logistics.php?id=' . $id);
        }

        /* A container is a physical fact, not a financial record, so an
           outright delete is right here — a wrong container number typed into
           the wrong row is noise, not history. The audit log keeps the trace. */
        if ($action === 'delete_container') {
            exp_require('logistics', 'd');
            $cid = (int)($_POST['container_id'] ?? 0);
            $st = db()->prepare("SELECT * FROM shipment_containers WHERE id=? AND shipment_id=?");
            $st->execute([$cid, $id]); $row = $st->fetch();
            if (!$row) throw new Exception('Container not found on this shipment.');
            db()->prepare("DELETE FROM shipment_containers WHERE id=? AND shipment_id=?")->execute([$cid, $id]);
            audit_log($id, 'Container', 'container', (string)$row['container_no'], '', 'Container removed');
            $_SESSION['flash'] = 'Container removed.';
            redirect('shipment_logistics.php?id=' . $id);
        }
    } catch (Throwable $e) {
        if (db()->inTransaction()) db()->rollBack();
        $_SESSION['error'] = $e->getMessage();
        redirect('shipment_logistics.php?id=' . $id);
    }
}

$log        = exp_logistics($id);
$containers = exp_containers($id);
$cartons    = exp_container_cartons($id);
$check      = exp_carton_check($id);

$ports   = exp_masters('port_loading');
$lines   = exp_masters('shipping_line');
$ctypes  = exp_masters('container_type');
$statuses = exp_masters('logistics_status');
$ctypeMap = exp_master_map('container_type');
$fwd     = exp_providers('freight_forwarder');

$editC = null; $editCRange = null;
if (isset($_GET['edit_container'])) {
    $st = db()->prepare("SELECT * FROM shipment_containers WHERE id=? AND shipment_id=?");
    $st->execute([(int)$_GET['edit_container'], $id]);
    $editC = $st->fetch() ?: null;
    if ($editC) $editCRange = ($cartons[(int)$editC['id']] ?? [null])[0];
}

$ro  = $canEdit ? '' : 'readonly';
$dis = $canEdit ? '' : 'disabled';

page_header('Logistics — ' . $shipment['invoice_no']);
flash();
echo exp_page_css();
exp_tab_strip($shipment, 'logistics');
?>

<form method="post">
<?= csrf_field() ?>
<input type="hidden" name="action" value="save_logistics">
<input type="hidden" name="shipment_id" value="<?= $id ?>">

<div class="xcard">
  <h2>Booking &amp; Vessel</h2>
  <div class="xgrid">
    <label class="xlabel">Loading Port
      <select class="xin" name="loading_port_id" <?= $dis ?>>
        <option value="">—</option>
        <?php foreach ($ports as $p): ?>
          <option value="<?= (int)$p['id'] ?>" <?= (int)($log['loading_port_id'] ?? 0) === (int)$p['id'] ? 'selected' : '' ?>><?= e($p['label']) ?></option>
        <?php endforeach; ?>
      </select>
    </label>
    <label class="xlabel">Shipping Line
      <select class="xin" name="shipping_line_id" <?= $dis ?>>
        <option value="">—</option>
        <?php foreach ($lines as $p): ?>
          <option value="<?= (int)$p['id'] ?>" <?= (int)($log['shipping_line_id'] ?? 0) === (int)$p['id'] ? 'selected' : '' ?>><?= e($p['label']) ?></option>
        <?php endforeach; ?>
      </select>
    </label>
    <label class="xlabel">Vessel Name<input class="xin" name="vessel_name" value="<?= e($log['vessel_name'] ?? '') ?>" <?= $ro ?>></label>
    <label class="xlabel">Voyage No.<input class="xin" name="voyage_no" value="<?= e($log['voyage_no'] ?? '') ?>" <?= $ro ?>></label>

    <label class="xlabel">Expected Loading<input class="xin" type="date" name="expected_load_date" value="<?= e($log['expected_load_date'] ?? '') ?>" <?= $ro ?>></label>
    <label class="xlabel">Actual Loading<input class="xin" type="date" name="actual_load_date" value="<?= e($log['actual_load_date'] ?? '') ?>" <?= $ro ?>></label>
    <label class="xlabel">ETD Pakistan<input class="xin" type="date" name="etd_pakistan" value="<?= e($log['etd_pakistan'] ?? '') ?>" <?= $ro ?>></label>
    <label class="xlabel">Transit Days<input class="xin" type="number" name="transit_days" value="<?= e($log['transit_days'] ?? '') ?>" <?= $ro ?>></label>
    <label class="xlabel">ETA Destination<input class="xin" type="date" name="eta_destination" value="<?= e($log['eta_destination'] ?? '') ?>" <?= $ro ?>></label>
    <label class="xlabel">Actual Arrival<input class="xin" type="date" name="actual_arrival_date" value="<?= e($log['actual_arrival_date'] ?? '') ?>" <?= $ro ?>></label>

    <label class="xlabel">Shipment Status
      <select class="xin" name="logistics_status" <?= $dis ?>>
        <option value="">—</option>
        <?php foreach ($statuses as $s): ?>
          <option value="<?= e($s['label']) ?>" <?= (string)($shipment['logistics_status'] ?? '') === (string)$s['label'] ? 'selected' : '' ?>><?= e($s['label']) ?></option>
        <?php endforeach; ?>
      </select>
    </label>
    <label class="xlabel">Destination Port
      <input class="xin" value="<?= e($shipment['destination_port'] ?? '') ?>" readonly title="Set on the invoice — shown here so it is not held twice">
    </label>
  </div>
</div>

<div class="xcard">
  <h2>Bill of Lading</h2>
  <div class="xgrid">
    <label class="xlabel">BL No.<input class="xin" name="bl_no" value="<?= e($log['bl_no'] ?? '') ?>" <?= $ro ?>></label>
    <label class="xlabel">BL Date<input class="xin" type="date" name="bl_date" value="<?= e($log['bl_date'] ?? '') ?>" <?= $ro ?>></label>
    <label class="xlabel">Stage
      <select class="xin" name="bl_stage" <?= $dis ?>>
        <option value="">—</option>
        <option value="draft" <?= ($log['bl_stage'] ?? '') === 'draft' ? 'selected' : '' ?>>Draft</option>
        <option value="final" <?= ($log['bl_stage'] ?? '') === 'final' ? 'selected' : '' ?>>Final</option>
      </select>
    </label>
    <?php if (!empty($shipment['bl_container_no'])): ?>
    <label class="xlabel">On the invoice header
      <input class="xin" value="<?= e($shipment['bl_container_no']) ?>" readonly title="The old combined BL / container field. Left exactly as it is — it still prints on the invoice.">
    </label>
    <?php endif; ?>
  </div>
  <?php if (exp_can('documents')): ?>
    <div class="xnote" style="margin-top:11px">The BL files themselves live on the <a href="shipment_documents.php?id=<?= $id ?>" style="color:#0ea8c9;font-weight:600">DOCS tab</a>, where each draft and the final keep their own version.</div>
  <?php endif; ?>
</div>

<div class="xcard">
  <h2>Agreed Freight</h2>
  <div class="xgrid">
    <label class="xlabel xspan2">Freight Forwarder
      <select class="xin" name="freight_provider_id" <?= $dis ?>>
        <option value="">—</option>
        <?php foreach ($fwd as $p): ?>
          <option value="<?= (int)$p['id'] ?>" <?= (int)($log['freight_provider_id'] ?? 0) === (int)$p['id'] ? 'selected' : '' ?>><?= e($p['name']) ?></option>
        <?php endforeach; ?>
      </select>
    </label>
    <?php if (can_see_rates()): ?>
    <label class="xlabel">Agreed Amount<input class="xin" name="freight_agreed_amount" value="<?= e($log['freight_agreed_amount'] ?? '') ?>" <?= $ro ?>></label>
    <label class="xlabel">Currency
      <select class="xin" name="freight_agreed_currency" <?= $dis ?>>
        <option value="">—</option>
        <?php foreach (['USD','EUR','GBP','PKR'] as $c): ?>
          <option value="<?= $c ?>" <?= (string)($log['freight_agreed_currency'] ?? '') === $c ? 'selected' : '' ?>><?= $c ?></option>
        <?php endforeach; ?>
      </select>
    </label>
    <label class="xlabel">Quotation Ref.<input class="xin" name="freight_quote_ref" value="<?= e($log['freight_quote_ref'] ?? '') ?>" <?= $ro ?>></label>
    <label class="xlabel">Quote Date<input class="xin" type="date" name="freight_quote_date" value="<?= e($log['freight_quote_date'] ?? '') ?>" <?= $ro ?>></label>
    <?php else: ?>
      <input type="hidden" name="freight_agreed_amount" value="<?= e($log['freight_agreed_amount'] ?? '') ?>">
      <input type="hidden" name="freight_agreed_currency" value="<?= e($log['freight_agreed_currency'] ?? '') ?>">
      <input type="hidden" name="freight_quote_ref" value="<?= e($log['freight_quote_ref'] ?? '') ?>">
      <input type="hidden" name="freight_quote_date" value="<?= e($log['freight_quote_date'] ?? '') ?>">
      <div class="xnote xspan2">Freight amounts are hidden for your user.</div>
    <?php endif; ?>
  </div>
  <?php if (can_see_rates() && exp_can('costs')): ?>
    <div class="xnote" style="margin-top:11px">The final freight bill is entered on the <a href="shipment_costs.php?id=<?= $id ?>" style="color:#0ea8c9;font-weight:600">COSTS tab</a>, and the comparison against this agreed figure appears there.</div>
  <?php endif; ?>
</div>

<div class="xcard">
  <h2>Tracking <span style="font-weight:400;color:#8a97ab;font-size:12px">— typed in for now; a carrier API can fill these later</span></h2>
  <div class="xgrid">
    <label class="xlabel xspan2">Last Known Event<input class="xin" name="last_event" value="<?= e($log['last_event'] ?? '') ?>" <?= $ro ?> placeholder="e.g. Loaded on vessel"></label>
    <label class="xlabel xspan2">Last Location<input class="xin" name="last_location" value="<?= e($log['last_location'] ?? '') ?>" <?= $ro ?> placeholder="e.g. Jebel Ali"></label>
    <label class="xlabel xspan2">Notes<textarea class="xin" name="notes" style="min-height:58px;resize:vertical" <?= $ro ?>><?= e($log['notes'] ?? '') ?></textarea></label>
  </div>
</div>

<?php if ($canEdit): ?>
<div class="xcard" style="display:flex;gap:9px;flex-wrap:wrap">
  <button class="xbtn">Save Logistics</button>
  <a class="xbtn sec" href="shipment_view.php?id=<?= $id ?>">Back to Invoice</a>
</div>
<?php endif; ?>
</form>

<div class="xcard">
  <div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:11px;flex-wrap:wrap;gap:8px">
    <h2 style="margin:0">Containers</h2>
    <span style="font-size:12px;color:#8a97ab"><?= count($containers) ?> on this shipment</span>
  </div>

  <div class="xwrap">
    <table class="xtable">
      <thead><tr>
        <th>Container No.</th><th>Type</th><th>Seal</th>
        <th class="num">VGM kg</th><th class="num">Cartons</th><th>Carton Range</th>
        <?php if ($canEdit): ?><th></th><?php endif; ?>
      </tr></thead>
      <tbody>
      <?php if (!$containers): ?>
        <tr><td colspan="7" style="padding:20px;text-align:center;color:#8a97ab">No containers recorded yet.</td></tr>
      <?php endif; ?>
      <?php foreach ($containers as $c):
        $rg = ($cartons[(int)$c['id']] ?? [null])[0]; ?>
        <tr>
          <td style="font-weight:600;font-family:'Space Grotesk',system-ui,sans-serif"><?= e($c['container_no']) ?></td>
          <td><?= e($ctypeMap[(int)($c['container_type_id'] ?? 0)] ?? '') ?></td>
          <td><?= e($c['seal_no']) ?></td>
          <td class="num"><?= $c['vgm_kg'] !== null ? e(trim_num($c['vgm_kg'], 3)) : '' ?></td>
          <td class="num"><?= $c['cartons'] !== null ? e(trim_num($c['cartons'], 0)) : '' ?></td>
          <td><?= $rg ? e((int)$rg['carton_from'] . ' – ' . (int)$rg['carton_to']) : '<span style="color:#8a97ab">—</span>' ?></td>
          <?php if ($canEdit): ?>
          <td style="white-space:nowrap">
            <a class="xbtn sec sm" href="shipment_logistics.php?id=<?= $id ?>&edit_container=<?= (int)$c['id'] ?>#cform">Edit</a>
            <?php if (exp_can('logistics', 'd')): ?>
            <form method="post" style="display:inline" onsubmit="return confirm('Remove container <?= e(addslashes((string)$c['container_no'])) ?>?')">
              <?= csrf_field() ?>
              <input type="hidden" name="action" value="delete_container">
              <input type="hidden" name="shipment_id" value="<?= $id ?>">
              <input type="hidden" name="container_id" value="<?= (int)$c['id'] ?>">
              <button class="xbtn red sm">Remove</button>
            </form>
            <?php endif; ?>
          </td>
          <?php endif; ?>
        </tr>
      <?php endforeach; ?>
      </tbody>
      <?php if ($check['any']): ?>
      <tfoot>
        <tr style="border-top:2px solid #e3e9f2;font-weight:700">
          <td colspan="4" style="text-align:right">Declared in containers</td>
          <td class="num"><?= e(trim_num($check['declared'], 0)) ?></td>
          <td colspan="2"></td>
        </tr>
        <tr style="font-weight:700">
          <td colspan="4" style="text-align:right">Packed on the packing list</td>
          <td class="num"><?= e(trim_num($check['packed'], 0)) ?></td>
          <td colspan="2"></td>
        </tr>
      </tfoot>
      <?php endif; ?>
    </table>
  </div>

  <?php if ($check['any'] && !$check['ok']): ?>
    <div class="xwarn" style="margin-top:12px">
      <b>Container cartons do not match the packing list.</b>
      Containers declare <?= e(trim_num($check['declared'], 0)) ?>, packing says <?= e(trim_num($check['packed'], 0)) ?>
      — a difference of <?= e(trim_num(abs($check['diff']), 0)) ?>.
      This is far cheaper to settle now than after the BL is drafted.
    </div>
  <?php elseif ($check['any'] && $check['ok'] && $check['declared'] > 0): ?>
    <div class="xnote" style="margin-top:12px;border-color:rgba(22,163,74,.3);background:rgba(22,163,74,.08)">
      Container cartons match the packing list exactly.
    </div>
  <?php endif; ?>
</div>

<?php if ($canEdit): ?>
<div class="xcard" id="cform">
  <h2><?= $editC ? 'Edit Container ' . e($editC['container_no']) : 'Add a Container' ?></h2>
  <form method="post" class="xgrid" style="align-items:end">
    <?= csrf_field() ?>
    <input type="hidden" name="action" value="save_container">
    <input type="hidden" name="shipment_id" value="<?= $id ?>">
    <input type="hidden" name="container_id" value="<?= (int)($editC['id'] ?? 0) ?>">

    <label class="xlabel">Container No.<input class="xin" name="container_no" required value="<?= e($editC['container_no'] ?? '') ?>" placeholder="SEKU6489931"></label>
    <label class="xlabel">Type
      <select class="xin" name="container_type_id">
        <option value="">—</option>
        <?php foreach ($ctypes as $t): ?>
          <option value="<?= (int)$t['id'] ?>" <?= (int)($editC['container_type_id'] ?? 0) === (int)$t['id'] ? 'selected' : '' ?>><?= e($t['label']) ?></option>
        <?php endforeach; ?>
      </select>
    </label>
    <label class="xlabel">Seal No.<input class="xin" name="seal_no" value="<?= e($editC['seal_no'] ?? '') ?>"></label>
    <label class="xlabel">VGM kg<input class="xin" name="vgm_kg" value="<?= e($editC['vgm_kg'] ?? '') ?>"></label>
    <label class="xlabel">Tare kg<input class="xin" name="tare_kg" value="<?= e($editC['tare_kg'] ?? '') ?>"></label>
    <label class="xlabel">Cartons<input class="xin" name="cartons" value="<?= e($editC['cartons'] ?? '') ?>"></label>
    <label class="xlabel">Carton From<input class="xin" type="number" name="carton_from" value="<?= e($editCRange['carton_from'] ?? '') ?>"></label>
    <label class="xlabel">Carton To<input class="xin" type="number" name="carton_to" value="<?= e($editCRange['carton_to'] ?? '') ?>"></label>
    <label class="xlabel xspan2">Notes<input class="xin" name="notes" value="<?= e($editC['notes'] ?? '') ?>"></label>

    <div style="display:flex;gap:8px">
      <button class="xbtn"><?= $editC ? 'Update Container' : 'Add Container' ?></button>
      <?php if ($editC): ?><a class="xbtn sec" href="shipment_logistics.php?id=<?= $id ?>">Cancel</a><?php endif; ?>
    </div>
  </form>
  <div class="xnote" style="margin-top:11px">
    The carton range is optional. Leave it blank and the container still records its count — fill it in
    and the packing list can be told which cartons went into which box.
  </div>
</div>
<?php endif; ?>

<script>
/* Office keys, same as the production and gate screens: Ctrl+S saves,
   Escape goes back and asks first if anything was typed. */
(function () {
  var form = document.querySelector('form[action=""], form');
  var dirty = false;
  document.addEventListener('input', function (e) {
    if (e.target && e.target.closest('form')) dirty = true;
  });
  document.addEventListener('keydown', function (e) {
    if ((e.ctrlKey || e.metaKey) && (e.key === 's' || e.key === 'S')) {
      e.preventDefault();
      var f = document.querySelector('input[name="action"][value="save_logistics"]');
      if (f && f.form) { dirty = false; f.form.submit(); }
    }
    if (e.key === 'Escape') {
      if (dirty && !confirm('Leave without saving your changes?')) return;
      window.location.href = 'shipment_view.php?id=<?= $id ?>';
    }
  });
})();
</script>
<?php page_footer(); ?>
