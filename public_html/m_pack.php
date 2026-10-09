<?php
/*
  PACKING — the phone version.

  The same shipment, the same login, the same permission as the desktop
  packing list. What is different is the order of the questions, because
  the person holding the phone is standing next to the cartons.

  IT STARTS AT THE SERIAL. "Carton 1 to 100" is the first thing typed and
  the package count follows from it. The desktop asks for the count and
  the serials separately, which lets the two disagree; here they cannot.

  IT DOES NOT CHECK THE INVOICE. What is in the carton is counted, not
  derived. The desktop screen keeps its own over-pack guard for its own
  entry; this screen reports what the team found.

  IT DOES NOT ASK FOR NET AND GROSS. One package goes on the scale and the
  team lists what is inside one unit. The weights are produced from that,
  and the approver may overrule them within ten per cent.

  IT NEEDS SIGNAL, like every page here. The service worker never caches a
  .php page, so nothing on screen can be stale and no save is swallowed.
*/
require_once __DIR__ . '/includes/bootstrap.php';
require_once __DIR__ . '/includes/export.php';
require_once __DIR__ . '/includes/packing.php';
require_once __DIR__ . '/includes/mobile.php';
require_login();

if (is_production_staff()) { http_response_code(403); exit('Production Staff cannot open the packing list.'); }
exp_ensure_schema();
pack_ensure_schema();

$id  = (int)($_GET['id']  ?? $_POST['shipment_id'] ?? 0);
$tab = (string)($_GET['t'] ?? 'serial');
$gid = (int)($_GET['g']   ?? 0);
$sz  = (string)($_GET['s'] ?? '');

/* ------------------------------------------------------- pick a shipment */
if ($id <= 0) {
    $ids = assigned_shipment_ids();
    $rows = [];
    try {
        if ($ids === ['ALL']) {
            $rows = db()->query("SELECT id, invoice_no, buyer_name, packing_status, status
                                 FROM shipments ORDER BY id DESC LIMIT 60")->fetchAll();
        } elseif ($ids) {
            $in = implode(',', array_fill(0, count($ids), '?'));
            $s = db()->prepare("SELECT id, invoice_no, buyer_name, packing_status, status
                                FROM shipments WHERE id IN ($in) ORDER BY id DESC");
            $s->execute($ids);
            $rows = $s->fetchAll();
        }
    } catch (Throwable $e) { $rows = []; }

    mob_header('Packing', 'm.php', 'Pick a shipment', 'manifest_pack.json');
    mob_flash();
    if (!$rows) {
        echo '<div class="empty">No shipment is assigned to you.</div>';
    } else {
        foreach ($rows as $r) {
            $done = ($r['packing_status'] ?? 'open') === 'completed';
            echo '<a class="mcard" style="display:block;text-decoration:none;color:inherit" href="m_pack.php?id=' . (int)$r['id'] . '">'
               . '<div style="display:flex;justify-content:space-between;gap:10px;align-items:center">'
               . '<b>' . e((string)$r['invoice_no']) . '</b>'
               . '<span class="pill ' . ($done ? 'p' : 'v') . '">' . ($done ? 'completed' : 'open') . '</span></div>'
               . '<div class="note" style="margin-top:4px">' . e((string)$r['buyer_name']) . '</div></a>';
        }
    }
    mob_footer();
    exit;
}

/* ------------------------------------------------------------ the shipment */
$s = db()->prepare("SELECT * FROM shipments WHERE id=?");
$s->execute([$id]);
$shipment = $s->fetch();
if (!$shipment) { http_response_code(404); exit('Shipment not found.'); }

$ids = assigned_shipment_ids();
if ($ids !== ['ALL'] && !in_array($id, $ids, true)) {
    http_response_code(403); exit('That shipment is not assigned to you.');
}
$canEdit = pack_may_edit($shipment);

$itStmt = db()->prepare("SELECT * FROM shipment_items WHERE shipment_id=? ORDER BY line_no, id");
$itStmt->execute([$id]);
$items = $itStmt->fetchAll();

$title = 'Packing — ' . (string)$shipment['invoice_no'];
$back  = 'm_pack.php';
$self  = 'm_pack.php?id=' . $id;

/* =============================================================== actions */
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verify_csrf();
    if (!$canEdit) { http_response_code(403); exit('This packing list can no longer be changed.'); }
    $action = (string)($_POST['action'] ?? '');

    /* ---- one serial range, with its sizes ---- */
    if ($action === 'group') {
        $g = (int)($_POST['group_id'] ?? 0);
        $itemId = (int)($_POST['invoice_item_id'] ?? 0);
        $item = null;
        foreach ($items as $it) if ((int)$it['id'] === $itemId) $item = $it;
        if (!$item) {
            $_SESSION['error'] = 'Pick the item this range holds.';
            redirect($self);
        }

        $assorted = !empty($_POST['assorted']);
        $sizes = [];
        if ($assorted) {
            $lbl = (array)($_POST['size_label'] ?? []);
            $qty = (array)($_POST['size_qty'] ?? []);
            foreach ($lbl as $i => $l) {
                $sizes[] = ['size_label' => (string)$l, 'qty' => (float)($qty[$i] ?? 0)];
            }
        } else {
            $sizes[] = ['size_label' => (string)($_POST['single_size'] ?? ''),
                        'qty'        => (float)($_POST['single_qty'] ?? 0)];
        }

        [$ok, $msg] = pack_group_save($id, [
            'invoice_item_id' => $itemId,
            'product_name'    => (string)$item['product_name'],
            'des_col'         => (string)($item['des_col'] ?? ''),
            'optional_value'  => (string)($item['optional_value'] ?? ''),
            'unit_title'      => (string)($_POST['unit_title'] ?? 'Carton'),
            'serial_from'     => (int)($_POST['serial_from'] ?? 0),
            'serial_to'       => (int)($_POST['serial_to'] ?? 0),
            'qty_mode'        => (string)($_POST['qty_mode'] ?? 'per'),
            'assorted'        => $assorted,
        ], $sizes, $g);

        if ($ok) $_SESSION['flash'] = $msg; else $_SESSION['error'] = $msg;
        redirect($self);
    }

    if ($action === 'group_delete') {
        pack_group_delete($id, (int)($_POST['group_id'] ?? 0));
        $_SESSION['flash'] = 'Range removed.';
        redirect($self);
    }

    /* ---- the weighed package and one size's breakdown ---- */
    if ($action === 'weight') {
        $g = (int)($_POST['group_id'] ?? 0);
        $grp = pack_group($g);
        if (!$grp || (int)$grp['shipment_id'] !== $id) { http_response_code(404); exit('Range not found.'); }

        pack_weigh_save($g, (float)($_POST['pkg_gross'] ?? 0), (float)($_POST['pkg_tare'] ?? 0));

        $size = (string)($_POST['size_label'] ?? '');
        if ($size !== '') {
            $t = (array)($_POST['w_type'] ?? []);
            $n = (array)($_POST['w_name'] ?? []);
            $gr = (array)($_POST['w_grams'] ?? []);
            $lines = [];
            foreach ($t as $i => $ty) {
                $lines[] = ['w_type' => (string)$ty,
                            'w_name' => (string)($n[$i] ?? ''),
                            'grams'  => (float)($gr[$i] ?? 0)];
            }
            pack_weight_save($g, $size, $lines);
        }
        $_SESSION['flash'] = 'Weight saved.';
        redirect($self . '&t=weight&g=' . $g . '&s=' . rawurlencode($size));
    }

    /* ---- fill this size from the last time the product was packed ---- */
    if ($action === 'recall') {
        $g = (int)($_POST['group_id'] ?? 0);
        $grp = pack_group($g);
        $size = (string)($_POST['size_label'] ?? '');
        if ($grp && (int)$grp['shipment_id'] === $id && $size !== '') {
            $std = pack_std_get((string)$grp['product_name'], $size);
            if ($std) {
                pack_weight_save($g, $size, $std);
                $_SESSION['flash'] = 'Filled from the last saved breakdown.';
            } else {
                $_SESSION['error'] = 'Nothing is remembered for that product and size yet.';
            }
        }
        redirect($self . '&t=weight&g=' . $g . '&s=' . rawurlencode($size));
    }

    if ($action === 'approve') {
        [$ok, $msg] = pack_approve($id, (float)($_POST['final_net'] ?? 0),
                                        (float)($_POST['final_gross'] ?? 0));
        if ($ok) $_SESSION['flash'] = $msg; else $_SESSION['error'] = $msg;
        redirect($self . '&t=approve');
    }
}

/* ================================================================ screens */
$groups = pack_groups($id);

/* ---------------------------------------- the separate assorted setup page */
if ($tab === 'mix' && $gid > 0) {
    $g = pack_group($gid);
    if (!$g || (int)$g['shipment_id'] !== $id) { http_response_code(404); exit('Range not found.'); }
    $sizes = pack_sizes($gid);
    $opts  = pack_size_options((string)$g['product_name']);
    $P     = pack_packages($g);
    $per   = $g['qty_mode'] === 'per';

    mob_header('Assorted — ' . $g['unit_title'] . ' ' . (int)$g['serial_from'] . '–' . (int)$g['serial_to'],
               $self, (string)$g['product_name'], 'manifest_pack.json');
    mob_flash();
    ?>
    <form method="post" id="mixform">
      <?= csrf_field() ?>
      <input type="hidden" name="action" value="group">
      <input type="hidden" name="shipment_id" value="<?= $id ?>">
      <input type="hidden" name="group_id" value="<?= $gid ?>">
      <input type="hidden" name="invoice_item_id" value="<?= (int)$g['invoice_item_id'] ?>">
      <input type="hidden" name="unit_title" value="<?= e((string)$g['unit_title']) ?>">
      <input type="hidden" name="serial_from" value="<?= (int)$g['serial_from'] ?>">
      <input type="hidden" name="serial_to" value="<?= (int)$g['serial_to'] ?>">
      <input type="hidden" name="qty_mode" value="<?= e((string)$g['qty_mode']) ?>">
      <input type="hidden" name="assorted" value="1">

      <div class="mcard">
        <h2><?= $per ? 'Sizes in one ' . e(strtolower((string)$g['unit_title']))
                     : 'Sizes across the whole range' ?></h2>
        <div class="note" style="margin-bottom:12px">
          <?= $per
            ? 'What one ' . e(strtolower((string)$g['unit_title'])) . ' holds. Every one in this serial range is packed the same.'
            : 'Direct qty — split the total between the sizes.' ?>
        </div>
        <div id="rows">
          <?php
          $show = $sizes ?: [['size_label' => '', 'qty_per_pkg' => 0, 'total_qty' => 0]];
          foreach ($show as $r):
              $q = $per ? (float)$r['qty_per_pkg'] : (float)$r['total_qty']; ?>
            <div class="mixrow">
              <select class="in" name="size_label[]">
                <option value="">— size —</option>
                <?php foreach ($opts as $o): ?>
                  <option<?= $o === (string)$r['size_label'] ? ' selected' : '' ?>><?= e($o) ?></option>
                <?php endforeach; ?>
              </select>
              <input class="in q" type="number" inputmode="decimal" step="any" name="size_qty[]"
                     value="<?= $q > 0 ? e(rtrim(rtrim(number_format($q, 3, '.', ''), '0'), '.')) : '' ?>"
                     placeholder="qty" aria-label="Quantity">
              <button type="button" class="x" aria-label="Remove">&times;</button>
            </div>
          <?php endforeach; ?>
        </div>
        <button type="button" class="btn sec" id="addsize" style="margin-top:4px">+ Add a size</button>
        <div class="tot"><span id="foot">—</span><b id="tot">—</b></div>
      </div>

      <?php if ($canEdit): ?>
        <button class="btn go" type="submit">Save this set</button>
      <?php endif; ?>
    </form>

    <?php if ($canEdit): ?>
    <form method="post" style="margin-top:10px"
          onsubmit="return confirm('Turn assorted off? The set of sizes is dropped.')">
      <?= csrf_field() ?>
      <input type="hidden" name="action" value="group">
      <input type="hidden" name="shipment_id" value="<?= $id ?>">
      <input type="hidden" name="group_id" value="<?= $gid ?>">
      <input type="hidden" name="invoice_item_id" value="<?= (int)$g['invoice_item_id'] ?>">
      <input type="hidden" name="unit_title" value="<?= e((string)$g['unit_title']) ?>">
      <input type="hidden" name="serial_from" value="<?= (int)$g['serial_from'] ?>">
      <input type="hidden" name="serial_to" value="<?= (int)$g['serial_to'] ?>">
      <input type="hidden" name="qty_mode" value="<?= e((string)$g['qty_mode']) ?>">
      <input type="hidden" name="single_size" value="<?= e((string)($sizes[0]['size_label'] ?? '')) ?>">
      <input type="hidden" name="single_qty"
             value="<?= e((string)($per ? ($sizes[0]['qty_per_pkg'] ?? 0) : ($sizes[0]['total_qty'] ?? 0))) ?>">
      <button class="btn red" type="submit">Turn assorted off — one size only</button>
    </form>
    <?php endif; ?>

    <style>
      .mixrow{display:grid;grid-template-columns:1fr 78px 38px;gap:8px;align-items:center;margin-bottom:8px}
      .mixrow .in{padding:10px}
      .mixrow .x{border:0;background:transparent;color:var(--bad);font-size:22px;min-height:44px;cursor:pointer}
      .tot{display:flex;justify-content:space-between;font-size:13.5px;font-weight:700;
           border-top:1px solid var(--line);padding-top:10px;margin-top:8px}
      .tot span{color:var(--muted);font-weight:600}
    </style>
    <script>
    (function () {
      var P = <?= (int)$P ?>, per = <?= $per ? 'true' : 'false' ?>, u = <?= json_encode(strtolower((string)$g['unit_title'])) ?>;
      var rows = document.getElementById('rows');
      function sum() {
        var t = 0;
        rows.querySelectorAll('.q').forEach(function (i) { t += parseFloat(i.value) || 0; });
        return t;
      }
      function paint() {
        var s = sum();
        document.getElementById('foot').textContent = per
          ? s + ' per ' + u + ' × ' + P + ' ' + u
          : 'split across ' + P + ' ' + u;
        document.getElementById('tot').textContent = (per ? s * P : s).toLocaleString('en-US');
      }
      rows.addEventListener('input', paint);
      rows.addEventListener('change', paint);
      rows.addEventListener('click', function (ev) {
        if (!ev.target.classList.contains('x')) return;
        if (rows.querySelectorAll('.mixrow').length > 1) ev.target.closest('.mixrow').remove();
        else { ev.target.closest('.mixrow').querySelectorAll('input,select').forEach(function (f) { f.value = ''; }); }
        paint();
      });
      document.getElementById('addsize').onclick = function () {
        var c = rows.querySelector('.mixrow').cloneNode(true);
        c.querySelectorAll('input,select').forEach(function (f) { f.value = ''; });
        rows.appendChild(c);
        paint();
      };
      paint();
    })();
    </script>
    <?php
    mob_footer();
    exit;
}

/* ------------------------------------------------------------ the tab bar */
mob_header($title, $back, (string)$shipment['buyer_name'], 'manifest_pack.json');
mob_flash();
if (!$canEdit) {
    echo '<div class="flash no">This packing list is closed to you — it is completed or the shipment is locked.</div>';
}
?>
<div class="ptabs">
  <a class="ptab<?= $tab === 'serial'  ? ' on' : '' ?>" href="<?= e($self) ?>&t=serial">Serial &amp; qty</a>
  <a class="ptab<?= $tab === 'weight'  ? ' on' : '' ?>" href="<?= e($self) ?>&t=weight">Weight</a>
  <a class="ptab<?= $tab === 'approve' ? ' on' : '' ?>" href="<?= e($self) ?>&t=approve">Approve</a>
</div>
<style>
.ptabs{display:flex;gap:7px;margin-bottom:14px}
.ptab{flex:1;padding:11px 4px;border-radius:11px;border:1px solid #cbd5e3;background:#fff;
  color:var(--muted);font-weight:700;font-size:13px;text-align:center;text-decoration:none}
.ptab.on{background:var(--navy);color:#fff;border-color:var(--navy)}
.derv{background:#f6f8fb;border:1px solid var(--line);border-radius:10px;padding:9px 11px;
  font-size:13px;font-weight:700;display:flex;justify-content:space-between;gap:8px;margin-bottom:11px}
.derv span{color:var(--muted);font-weight:600}
.ref{background:rgba(14,168,201,.08);border:1px solid rgba(14,168,201,.25);border-radius:10px;
  padding:9px 11px;font-size:12px;color:var(--muted);margin-bottom:11px}
.ref b{color:var(--ink)}
.seg{display:flex;border:1px solid #cbd5e3;border-radius:10px;overflow:hidden;margin-bottom:12px}
.seg label{flex:1;margin:0}
.seg input{position:absolute;opacity:0;pointer-events:none}
.seg span{display:block;text-align:center;padding:12px 6px;font-size:13px;font-weight:700;
  color:var(--muted);cursor:pointer;min-height:46px}
.seg input:checked + span{background:var(--cyan);color:#fff}
.row3{display:grid;grid-template-columns:1fr 84px 84px;gap:8px;margin-bottom:11px}
.row2{display:flex;gap:10px}.row2>*{flex:1;min-width:0}
.lnk{display:flex;justify-content:space-between;align-items:center;gap:8px;width:100%;
  padding:12px;border:1px solid #cbd5e3;border-radius:11px;background:#fff;color:var(--ink);
  text-decoration:none;margin-bottom:11px;min-height:48px}
.lnk small{display:block;color:var(--faint);font-size:11.5px;font-weight:600}
.lnk b{font-size:13.5px}
.chev{color:var(--cyan);font-weight:700;font-size:18px}
.chips{display:flex;gap:6px;flex-wrap:wrap;margin-bottom:4px}
.chip{border:1px solid var(--line);background:#fff;color:var(--muted);border-radius:16px;
  padding:8px 13px;font-size:12.5px;font-weight:700;text-decoration:none;min-height:40px;display:inline-block}
.chip.on{background:var(--cyan);color:#fff;border-color:transparent}
.sumrow{display:flex;justify-content:space-between;gap:10px;font-size:13px;padding:4px 0}
.sumrow b{font-variant-numeric:tabular-nums}
.formula{font-family:ui-monospace,Menlo,Consolas,monospace;font-size:11.5px;color:var(--muted);
  background:#f6f8fb;border:1px solid var(--line);border-radius:9px;padding:10px;margin-top:10px;
  white-space:pre-wrap;line-height:1.75;overflow-x:auto}
table.bk{width:100%;border-collapse:collapse;font-size:12.5px}
table.bk th{text-align:left;font-size:10.5px;text-transform:uppercase;letter-spacing:.05em;
  color:var(--faint);border-bottom:1px solid var(--line);padding:6px 4px}
table.bk td{padding:6px 4px;border-bottom:1px solid var(--line);font-variant-numeric:tabular-nums}
table.bk td.n{text-align:right}
</style>
<?php

/* ================================================== tab 1 — serial and qty */
if ($tab === 'serial') {

    /* Draws one range, new or existing. Kept as a function because the
       "add another" card at the bottom is the same form with nothing in it. */
    $card = function (?array $g) use ($items, $id, $canEdit, $self, $groups) {
        $new   = $g === null;
        $gidL  = $new ? 0 : (int)$g['id'];
        $unit  = $new ? 'Carton' : (string)$g['unit_title'];
        $from  = $new ? 0 : (int)$g['serial_from'];
        $to    = $new ? 0 : (int)$g['serial_to'];
        $mode  = $new ? 'per' : (string)$g['qty_mode'];
        $asrt  = $new ? false : !empty($g['assorted']);
        $P     = $new ? 0 : pack_packages($g);
        $sizes = $new ? [] : pack_sizes($gidL);
        $item  = null;
        foreach ($items as $it) if (!$new && (int)$it['id'] === (int)$g['invoice_item_id']) $item = $it;

        /* the next range starts where the last one ended */
        if ($new) {
            $last = 0;
            foreach ($groups as $og) $last = max($last, (int)$og['serial_to']);
            $from = $last + 1; $to = $last + 1;
        }

        $opts = pack_size_options($new ? '' : (string)$g['product_name']);
        $single = $sizes[0] ?? null;
        $singleQty = $single ? ($mode === 'per' ? (float)$single['qty_per_pkg'] : (float)$single['total_qty']) : 0;
        $num = function (float $v): string {
            return $v > 0 ? rtrim(rtrim(number_format($v, 3, '.', ''), '0'), '.') : '';
        };
        ?>
        <form method="post" class="mcard">
          <?= csrf_field() ?>
          <input type="hidden" name="action" value="group">
          <input type="hidden" name="shipment_id" value="<?= $id ?>">
          <input type="hidden" name="group_id" value="<?= $gidL ?>">
          <input type="hidden" name="assorted" value="<?= $asrt ? 1 : 0 ?>">

          <div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:10px">
            <h2 style="margin:0"><?= $new ? 'Add the next range' : 'Range ' . (int)$g['line_no'] ?></h2>
            <?php if (!$new): ?><span class="pill v"><?= number_format($P) ?> <?= e(strtolower($unit)) ?></span><?php endif; ?>
          </div>

          <?php if ($item): ?>
            <div class="ref"><b><?= e((string)$item['product_name']) ?></b><br>
              <?= e((string)($item['des_col'] ?? '')) ?>
              <?= ($item['optional_value'] ?? '') !== '' ? ' · ' . e((string)$item['optional_value']) : '' ?></div>
          <?php endif; ?>

          <label class="f"><span>Item — from the invoice</span>
            <select class="in" name="invoice_item_id" required>
              <option value="">— pick the item —</option>
              <?php foreach ($items as $it): ?>
                <option value="<?= (int)$it['id'] ?>"
                  <?= (!$new && (int)$it['id'] === (int)$g['invoice_item_id']) ? ' selected' : '' ?>>
                  <?= e((string)$it['product_name']) ?></option>
              <?php endforeach; ?>
            </select></label>

          <span class="flab" style="display:block;font-size:11.5px;text-transform:uppercase;
                letter-spacing:.06em;color:var(--faint);font-weight:700;margin-bottom:5px">
            Serial — this is where packing starts</span>
          <div class="row3">
            <input class="in" name="unit_title" value="<?= e($unit) ?>" aria-label="Unit" required>
            <input class="in" type="number" inputmode="numeric" name="serial_from"
                   value="<?= $from ?: '' ?>" placeholder="from" aria-label="From" required>
            <input class="in" type="number" inputmode="numeric" name="serial_to"
                   value="<?= $to ?: '' ?>" placeholder="to" aria-label="To" required>
          </div>
          <?php if (!$new): ?>
            <div class="derv"><span><?= e($unit) ?> <?= $from ?> to <?= $to ?></span>
              <b><?= number_format($P) ?> <?= e(strtolower($unit)) ?></b></div>
          <?php endif; ?>

          <div class="seg">
            <label><input type="radio" name="qty_mode" value="per"<?= $mode === 'per' ? ' checked' : '' ?>><span>Per package</span></label>
            <label><input type="radio" name="qty_mode" value="direct"<?= $mode === 'direct' ? ' checked' : '' ?>><span>Direct qty</span></label>
          </div>

          <?php if ($asrt): ?>
            <div class="derv"><span><?= $mode === 'per' ? 'Assorted, per ' . e(strtolower($unit)) : 'Assorted, total' ?></span>
              <b><?= e(pack_size_text($g, $sizes) ?: 'not set up yet') ?></b></div>
            <a class="lnk" href="<?= e($self) ?>&t=mix&g=<?= $gidL ?>">
              <span><b>Change the assorted set</b><small>Sizes and quantities for this serial range</small></span>
              <span class="chev">&rsaquo;</span></a>
          <?php else: ?>
            <label class="f"><span><?= $mode === 'direct' ? 'Total quantity' : 'Quantity per package' ?></span>
              <input class="in" type="number" inputmode="decimal" step="any" name="single_qty"
                     value="<?= e($num($singleQty)) ?>" required></label>
            <label class="f"><span>Size</span>
              <select class="in" name="single_size" required>
                <option value="">— pick a size —</option>
                <?php foreach ($opts as $o): ?>
                  <option<?= $single && $o === (string)$single['size_label'] ? ' selected' : '' ?>><?= e($o) ?></option>
                <?php endforeach; ?>
              </select></label>
            <?php if (!$new): ?>
              <a class="lnk" href="<?= e($self) ?>&t=mix&g=<?= $gidL ?>">
                <span><b>One size per <?= e(strtolower($unit)) ?></b><small>Tap if this range is assorted</small></span>
                <span class="chev">&rsaquo;</span></a>
            <?php else: ?>
              <div class="note" style="margin-bottom:11px">Save the range first, then it can be made assorted.</div>
            <?php endif; ?>
          <?php endif; ?>

          <?php if (!$new): ?>
            <div class="derv">
              <span><?= $mode === 'per'
                ? number_format((float)$g['qty_per_pkg'], 2) . ' per ' . e(strtolower($unit)) . ' &times; ' . number_format($P)
                : number_format((float)$g['total_qty'], 2) . ' over ' . number_format($P) . ' ' . e(strtolower($unit)) ?></span>
              <b><?= number_format((float)$g['total_qty'], 2) ?></b></div>
          <?php endif; ?>

          <?php if ($canEdit): ?>
            <button class="btn go" type="submit"><?= $new ? 'Add this range' : 'Save this range' ?></button>
          <?php endif; ?>
        </form>

        <?php if (!$new && $canEdit): ?>
          <form method="post" style="margin:-4px 0 14px"
                onsubmit="return confirm('Remove <?= e($unit) ?> <?= $from ?>–<?= $to ?> and its weights?')">
            <?= csrf_field() ?>
            <input type="hidden" name="action" value="group_delete">
            <input type="hidden" name="shipment_id" value="<?= $id ?>">
            <input type="hidden" name="group_id" value="<?= $gidL ?>">
            <button class="btn red" type="submit">Remove this range</button>
          </form>
        <?php endif; ?>
        <?php
    };

    if (!$items) {
        echo '<div class="empty">This invoice has no items yet. Add them on the desktop first.</div>';
    } else {
        foreach ($groups as $g) $card($g);
        if ($canEdit) $card(null);
        echo '<div class="mcard"><div class="note">Packing starts from the serial. '
           . '1 to 100 means 100 cartons — the count is never typed. When some packages '
           . 'differ, add another range; that is all a different serial is.</div></div>';
    }
}

/* ========================================================= tab 2 — weight */
if ($tab === 'weight') {
    if (!$groups) {
        echo '<div class="empty">Add a serial range first.</div>';
    } else {
        $g = null;
        foreach ($groups as $cand) if ((int)$cand['id'] === $gid) $g = $cand;
        if (!$g) { $g = $groups[0]; $gid = (int)$g['id']; }

        $sizes   = pack_sizes($gid);
        $perUnit = pack_per_unit($gid);
        $P       = pack_packages($g);
        $labels  = array_column($sizes, 'size_label');
        if ($sz === '' || !in_array($sz, $labels, true)) $sz = (string)($labels[0] ?? '');

        $mustKg  = (float)$g['pkg_gross'] - (float)$g['pkg_tare'];
        $qtyIn   = 0.0;
        foreach ($sizes as $srow) if ((string)$srow['size_label'] === $sz) $qtyIn = pack_size_per_pkg($g, $srow);
        $lines   = $sz !== '' ? pack_weight_lines($gid, $sz) : [];
        $contents = pack_contents_kg($g, $sizes, $perUnit);
        $hasStd  = $sz !== '' && pack_std_get((string)$g['product_name'], $sz) !== [];
        ?>
        <div class="mcard">
          <h2>Which serial range</h2>
          <select class="in" onchange="location.href=this.value">
            <?php foreach ($groups as $og): ?>
              <option value="<?= e($self) ?>&amp;t=weight&amp;g=<?= (int)$og['id'] ?>"
                <?= (int)$og['id'] === $gid ? ' selected' : '' ?>>
                <?= e((string)$og['product_name']) ?> · <?= e((string)$og['unit_title']) ?>
                <?= (int)$og['serial_from'] ?>–<?= (int)$og['serial_to'] ?></option>
            <?php endforeach; ?>
          </select>
        </div>

        <form method="post" id="wform">
          <?= csrf_field() ?>
          <input type="hidden" name="action" value="weight">
          <input type="hidden" name="shipment_id" value="<?= $id ?>">
          <input type="hidden" name="group_id" value="<?= $gid ?>">
          <input type="hidden" name="size_label" value="<?= e($sz) ?>">

          <div class="mcard">
            <h2>Weigh one package</h2>
            <div class="note" style="margin-bottom:12px">One <?= e(strtolower((string)$g['unit_title'])) ?>
              on the scale, then open it.</div>
            <div class="row2">
              <label class="f"><span>Package gross kg</span>
                <input class="in" type="number" inputmode="decimal" step="0.001" id="pg" name="pkg_gross"
                       value="<?= (float)$g['pkg_gross'] > 0 ? e(number_format((float)$g['pkg_gross'], 3, '.', '')) : '' ?>"></label>
              <label class="f"><span>The <?= e(strtolower((string)$g['unit_title'])) ?> itself kg</span>
                <input class="in" type="number" inputmode="decimal" step="0.001" id="pt" name="pkg_tare"
                       value="<?= (float)$g['pkg_tare'] > 0 ? e(number_format((float)$g['pkg_tare'], 3, '.', '')) : '' ?>"></label>
            </div>
            <div class="derv"><span>So the contents must come to</span><b id="must"><?= number_format($mustKg, 3) ?> kg</b></div>
            <div class="derv"><span>Inside one package</span><b><?php
              $bits = [];
              foreach ($sizes as $srow) {
                  $bits[] = rtrim(rtrim(number_format(pack_size_per_pkg($g, $srow), 2, '.', ''), '0'), '.')
                          . ' ' . $srow['size_label'];
              }
              echo e($bits ? implode(' + ', $bits) : 'no size yet');
            ?></b></div>
          </div>

          <?php if (count($sizes) > 1): ?>
            <div class="mcard">
              <h2>Which size</h2>
              <div class="note" style="margin-bottom:10px">An assorted package holds sizes that do not weigh the same.</div>
              <div class="chips">
                <?php foreach ($sizes as $srow): $l = (string)$srow['size_label']; ?>
                  <a class="chip<?= $l === $sz ? ' on' : '' ?>"
                     href="<?= e($self) ?>&t=weight&g=<?= $gid ?>&s=<?= e(rawurlencode($l)) ?>">
                    <?= e($l) ?><?= ($perUnit[$l] ?? 0) > 0 ? ' &#10003;' : '' ?></a>
                <?php endforeach; ?>
              </div>
            </div>
          <?php endif; ?>

          <div class="mcard">
            <h2>One unit of <?= e($sz ?: '—') ?></h2>
            <div class="note" style="margin-bottom:12px">Add a line for every material. Grams.</div>
            <div id="wlines"></div>
            <button class="btn sec" type="button" id="addline">+ Add line</button>
            <div class="derv" style="margin-top:12px"><span>This unit comes to</span><b id="perunit">0 g</b></div>
          </div>

          <?php if ($canEdit && $sz !== ''): ?>
            <button class="btn go" type="submit">Save the weight</button>
          <?php endif; ?>
        </form>

        <?php if ($canEdit && $hasStd): ?>
          <form method="post" style="margin-top:10px">
            <?= csrf_field() ?>
            <input type="hidden" name="action" value="recall">
            <input type="hidden" name="shipment_id" value="<?= $id ?>">
            <input type="hidden" name="group_id" value="<?= $gid ?>">
            <input type="hidden" name="size_label" value="<?= e($sz) ?>">
            <button class="btn sec" type="submit">Use the last saved breakdown for
              <?= e((string)$g['product_name']) ?> <?= e($sz) ?></button>
          </form>
        <?php endif; ?>

        <div class="mcard" style="margin-top:12px">
          <h2>The whole package</h2>
          <div class="sumrow"><span>The lines add up to</span><b><?= number_format($contents, 3) ?> kg</b></div>
          <div class="sumrow"><span>They must come to</span><b><?= number_format($mustKg, 3) ?> kg</b></div>
          <div class="sumrow"><span><b>Balance</b></span><b><?php
            $d = $contents - $mustKg;
            echo abs($d) < 0.0005
              ? '<span class="pill p">balanced</span>'
              : '<span class="pill w">' . ($d > 0 ? '+' : '') . number_format($d, 3) . ' kg</span>';
          ?></b></div>
          <div class="formula"><?php
            $f = '';
            foreach ($sizes as $srow) {
                $l = (string)$srow['size_label'];
                $pu = (float)($perUnit[$l] ?? 0);
                $qp = pack_size_per_pkg($g, $srow);
                $f .= str_pad($l, 14) . number_format($pu) . ' g  x ' . rtrim(rtrim(number_format($qp, 2, '.', ''), '0'), '.')
                    . '  = ' . number_format($pu * $qp / 1000, 3) . " kg\n";
            }
            $f .= str_pad('', 14) . 'contents  ' . number_format($contents, 3) . " kg\n"
                . str_pad('', 14) . '+ package ' . number_format((float)$g['pkg_tare'], 3) . " kg\n\n"
                . 'NET   = ' . number_format($contents, 3) . ' x ' . number_format($P)
                . ' = ' . number_format($contents * $P, 3) . " kg\n"
                . 'GROSS = NET + (' . number_format((float)$g['pkg_tare'], 3) . ' x ' . number_format($P) . ') = '
                . number_format($contents * $P + (float)$g['pkg_tare'] * $P, 3) . ' kg';
            echo e($f);
          ?></div>
        </div>

        <style>
        .wrow{border:1px solid var(--line);border-radius:11px;padding:10px;margin-bottom:9px;background:#fff}
        .wtop{display:grid;grid-template-columns:1fr 90px 34px;gap:8px;align-items:center}
        .wtop .in{padding:9px 10px}
        .wtop .g{text-align:right;font-variant-numeric:tabular-nums}
        .wrow .nm{margin-top:8px}
        .wrow .nm .in{padding:9px 10px;font-size:14px}
        .wbal{text-align:right;font-size:11.5px;font-weight:700;margin-top:7px;font-variant-numeric:tabular-nums}
        .wbal.ok{color:var(--good)}.wbal.left{color:var(--muted)}.wbal.over{color:var(--bad)}
        .wrow .x{border:0;background:transparent;color:var(--bad);font-size:22px;min-height:44px;cursor:pointer}
        </style>
        <script>
        (function () {
          var TYPES = <?= json_encode(PACK_WTYPES) ?>;
          var ROWS  = <?= json_encode(array_map(function ($l) {
                            return ['t' => (string)$l['w_type'], 'n' => (string)$l['w_name'],
                                    'g' => (float)$l['grams']]; }, $lines)) ?>;
          var QTY   = <?= json_encode(round($qtyIn, 4)) ?>;
          /* the line-by-line fill only means something when the whole
             package is one size — otherwise the balance is a package
             figure and is shown on the card below. */
          var SINGLE = <?= count($sizes) === 1 ? 'true' : 'false' ?>;
          var box = document.getElementById('wlines');

          function must() {
            var g = parseFloat(document.getElementById('pg').value) || 0;
            var t = parseFloat(document.getElementById('pt').value) || 0;
            return (g - t);
          }
          function esc(s) {
            return String(s == null ? '' : s).replace(/[&<>"]/g, function (c) {
              return ({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;'})[c]; });
          }
          function paint() {
            var mustG = (SINGLE && QTY > 0) ? must() * 1000 / QTY : 0, run = mustG, tot = 0;
            box.innerHTML = ROWS.map(function (r, i) {
              tot += (+r.g || 0);
              var bal = '';
              if (mustG > 0) {
                run -= (+r.g || 0);
                var cls = Math.abs(run) < 0.5 ? 'ok' : (run < 0 ? 'over' : 'left');
                var txt = Math.abs(run) < 0.5 ? '0 g — balanced ✓'
                        : (run < 0 ? Math.round(-run).toLocaleString('en-US') + ' g over'
                                   : Math.round(run).toLocaleString('en-US') + ' g still to fill');
                bal = '<div class="wbal ' + cls + '">' + txt + '</div>';
              }
              return '<div class="wrow" data-i="' + i + '"><div class="wtop">'
                + '<select class="in" name="w_type[]">' + TYPES.map(function (t) {
                    return '<option' + (t === r.t ? ' selected' : '') + '>' + esc(t) + '</option>'; }).join('')
                + '</select>'
                + '<input class="in g" type="number" inputmode="decimal" step="any" name="w_grams[]" '
                + 'value="' + (r.g || '') + '" placeholder="g" aria-label="Grams">'
                + '<button type="button" class="x" aria-label="Remove">&times;</button></div>'
                + '<div class="nm"><input class="in" name="w_name[]" value="' + esc(r.n) + '" '
                + 'placeholder="name — fleece, micro, polyester …" aria-label="Name"></div>'
                + bal + '</div>';
            }).join('') || '<div class="note">No line yet. Press <b>+ Add line</b> — pick Fabric, '
                + 'name it <i>fleece</i>, put its grams. Then add a line again for <i>micro</i>, '
                + 'then fibre, then the poly bag.</div>';
            document.getElementById('perunit').textContent = Math.round(tot).toLocaleString('en-US') + ' g';
            document.getElementById('must').textContent = must().toFixed(3) + ' kg';
          }
          /* Read the fields back into ROWS before any redraw, or typing in
             one box would be thrown away when another one changes. */
          function harvest() {
            box.querySelectorAll('.wrow').forEach(function (d) {
              var i = +d.dataset.i;
              if (!ROWS[i]) return;
              ROWS[i].t = d.querySelector('select').value;
              ROWS[i].g = parseFloat(d.querySelector('.g').value) || 0;
              ROWS[i].n = d.querySelector('.nm input').value;
            });
          }
          box.addEventListener('input', function (ev) {
            if (!ev.target.classList.contains('g')) { harvest(); return; }
            harvest(); paint();
          });
          box.addEventListener('change', function () { harvest(); paint(); });
          box.addEventListener('click', function (ev) {
            if (!ev.target.classList.contains('x')) return;
            harvest();
            ROWS.splice(+ev.target.closest('.wrow').dataset.i, 1);
            paint();
          });
          document.getElementById('addline').onclick = function () {
            harvest();
            ROWS.push({ t: TYPES[0], n: '', g: 0 });   /* Fabric — change it on the line */
            paint();
          };
          ['pg', 'pt'].forEach(function (f) {
            document.getElementById(f).addEventListener('input', function () { harvest(); paint(); });
          });
          paint();
        })();
        </script>
        <?php
    }
}

/* ======================================================== tab 3 — approve */
if ($tab === 'approve') {
    $t = pack_totals($id);
    ?>
    <div class="mcard">
      <h2>What goes to the backend</h2>
      <div class="note" style="margin-bottom:10px">Size by size, against its serial range.</div>
      <div style="overflow-x:auto"><table class="bk">
        <tr><th>Serial</th><th>Size</th><th class="n">Per pkg</th><th class="n">Qty</th><th class="n">g / unit</th></tr>
        <?php if (!$groups): ?>
          <tr><td colspan="5" style="color:var(--faint)">Nothing yet</td></tr>
        <?php else: foreach ($groups as $g):
          $sizes = pack_sizes((int)$g['id']);
          $pu    = pack_per_unit((int)$g['id']);
          $first = true;
          foreach (($sizes ?: [['size_label' => '—', 'qty_per_pkg' => 0, 'total_qty' => 0]]) as $srow): ?>
            <tr>
              <td><?= $first ? e(mb_substr((string)$g['unit_title'], 0, 3)) . ' ' . (int)$g['serial_from']
                               . '–' . (int)$g['serial_to'] : '' ?></td>
              <td><?= e((string)$srow['size_label']) ?></td>
              <td class="n"><?= rtrim(rtrim(number_format(pack_size_per_pkg($g, $srow), 2, '.', ''), '0'), '.') ?></td>
              <td class="n"><?= number_format(pack_size_per_pkg($g, $srow) * pack_packages($g)) ?></td>
              <td class="n"><?= number_format((float)($pu[(string)$srow['size_label']] ?? 0)) ?></td>
            </tr>
          <?php $first = false; endforeach; endforeach; endif; ?>
      </table></div>
    </div>

    <div class="mcard">
      <h2>Calculated from the packing team&rsquo;s figures</h2>
      <div class="sumrow"><span>Total packages</span><b><?= number_format($t['packages']) ?></b></div>
      <div class="sumrow"><span>Total quantity</span><b><?= number_format($t['qty'], 2) ?></b></div>
      <div class="sumrow"><span>Net weight</span><b><?= number_format($t['net'], 3) ?> kg</b></div>
      <div class="sumrow"><span>Gross weight</span><b><?= number_format($t['gross'], 3) ?> kg</b></div>
    </div>

    <form method="post">
      <?= csrf_field() ?>
      <input type="hidden" name="action" value="approve">
      <input type="hidden" name="shipment_id" value="<?= $id ?>">
      <div class="mcard">
        <h2>Final figures</h2>
        <div class="note" style="margin-bottom:12px">You may change these. A difference up to
          <?= (int)PACK_TOLERANCE_PCT ?>% is accepted.</div>
        <div class="row2">
          <label class="f"><span>Net kg</span>
            <input class="in" type="number" inputmode="decimal" step="0.001" name="final_net" id="fn"
                   value="<?= number_format($t['net'], 3, '.', '') ?>"></label>
          <label class="f"><span>Gross kg</span>
            <input class="in" type="number" inputmode="decimal" step="0.001" name="final_gross" id="fg"
                   value="<?= number_format($t['gross'], 3, '.', '') ?>"></label>
        </div>
        <div id="dev"></div>
      </div>

      <div class="mcard">
        <h2>Before you approve</h2>
        <?php if ($t['unfinished']): foreach ($t['unfinished'] as $u): ?>
          <div class="sumrow"><span><?= e($u) ?></span><b><span class="pill w">accepted</span></b></div>
        <?php endforeach; else: ?>
          <div class="sumrow"><span>Every package balances.</span><b><span class="pill p">ok</span></b></div>
        <?php endif; ?>
        <div class="note" style="margin-top:8px">Quantity is what the packing team counted.
          It is not compared with the invoice.</div>
      </div>

      <?php if ($canEdit): ?>
        <button class="btn go" type="submit">Approve and save the packing list</button>
      <?php endif; ?>
    </form>

    <script>
    (function () {
      var NET = <?= json_encode(round($t['net'], 3)) ?>, GROSS = <?= json_encode(round($t['gross'], 3)) ?>;
      var LIM = <?= (int)PACK_TOLERANCE_PCT ?>;
      function one(label, calc, got) {
        if (calc <= 0) return '';
        var p = Math.abs(got - calc) / calc * 100;
        return '<div class="sumrow"><span>' + label + '</span><b><span class="pill '
          + (p <= LIM ? 'p' : 'w') + '">'
          + (p < 0.05 ? 'same as calculated'
                      : p.toFixed(1) + '% from calculated' + (p <= LIM ? ' — accepted' : ' — beyond ' + LIM + '%'))
          + '</span></b></div>';
      }
      function paint() {
        document.getElementById('dev').innerHTML =
            one('Net', NET, parseFloat(document.getElementById('fn').value) || 0)
          + one('Gross', GROSS, parseFloat(document.getElementById('fg').value) || 0);
      }
      ['fn', 'fg'].forEach(function (i) { document.getElementById(i).addEventListener('input', paint); });
      paint();
    })();
    </script>
    <?php
}

mob_footer();
