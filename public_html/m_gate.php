<?php
/*
  GATE IN / GATE OUT — the phone version.

  The same two tables the desktop writes, the same permission, the same
  CSRF, the same gate number series. A pass raised here is an ordinary
  draft: open it on the desktop and it looks like any other.

  WHAT IS DELIBERATELY NOT HERE
  Rate, article, lot, packing, contract link, proforma link, location,
  verified by, security by, override reason. Whoever is standing at the
  gate knows the vehicle, the item and the quantity. Everything else is an
  office figure and belongs on the desktop, where the office fills it in
  before posting.

  Rate above all. Seeing rates is a permission of its own in this app, and
  a gatekeeper asked for one at eleven at night will either hold the truck
  or type something plausible. A guessed rate is worse than a blank one:
  blank is visibly unfinished, a guess is silently wrong. So the rate is
  not asked for here, and inv_gate_post() refuses to post a pass whose
  lines have no rate.

  IT CANNOT POST. There is no button, and posting needs inv_perm('post')
  which a gatekeeper would not be given. This screen only ever writes a
  draft.

  IT NEEDS SIGNAL. Like every other page in the app. The service worker
  never caches a .php page, so nothing here can show a stale item list or
  silently swallow a save. With no connection the shell says so and the
  save button is disabled.
*/
require_once __DIR__ . '/includes/bootstrap.php';
require_once __DIR__ . '/includes/inventory.php';
require_once __DIR__ . '/includes/mobile.php';
require_login();

if (!inv_perm('gate')) {
    http_response_code(403);
    mob_header('Gate');
    echo '<div class="flash no">You do not have permission to create gate passes. Ask an admin for Gate access.</div>';
    mob_footer();
    exit;
}

$dir = ($_GET['dir'] ?? $_POST['dir'] ?? '') === 'out' ? 'out' : 'in';
$TYPES = inv_gate_types($dir);
$dirName = $dir === 'in' ? 'Gate Inward' : 'Gate Outward';

/* ------------------------------------------------------------------ save */
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'save') {
    verify_csrf();

    $id   = (int)($_POST['id'] ?? 0);
    $type = array_key_exists((string)($_POST['txn_type'] ?? ''), $TYPES)
          ? (string)$_POST['txn_type'] : array_key_first($TYPES);
    $date = ($_POST['gate_date'] ?? '') !== '' ? (string)$_POST['gate_date'] : date('Y-m-d');

    /* The same two date guards the desktop applies. A phone's clock can be
       wrong and a backdated pass is how stock history gets rewritten. */
    $err = '';
    if ($date > date('Y-m-d')) {
        $err = 'A gate pass cannot be dated in the future.';
    } else {
        $limit = (int)inv_setting('backdate_days', '7');
        if ($limit > 0 && !is_admin() && $date < date('Y-m-d', strtotime("-$limit days"))) {
            $err = "That date is more than $limit days back. Ask an admin to enter it.";
        }
    }

    /* A line with an item but no quantity is refused, exactly as on the
       desktop — that is how a delivery leaves the gate and is never written
       down. A completely blank line is just the spare row. */
    $lines = [];
    if ($err === '') {
        foreach ((array)($_POST['line'] ?? []) as $i => $ln) {
            [$mid, $pid] = inv_split_key((string)($ln['item_key'] ?? ''));
            if ($mid <= 0 && $pid <= 0) continue;
            $qty = inv_num($ln['qty'] ?? 0);
            if ($qty <= 0) { $err = 'Line ' . ((int)$i + 1) . ' has an item but no quantity.'; break; }
            $lines[] = [
                'material_id' => $mid ?: null,
                'product_id'  => $pid ?: null,
                'description' => mb_substr(trim((string)($ln['desc'] ?? '')), 0, 300),
                'qty'         => $qty,
                'uom'         => mb_substr(trim((string)($ln['uom'] ?? '')), 0, 20),
            ];
        }
        if ($err === '' && !$lines) $err = 'Add at least one item.';
    }

    if ($err !== '') {
        $_SESSION['error'] = $err;
        redirect('m_gate.php?dir=' . $dir . ($id > 0 ? '&id=' . $id : '&new=1'));
    }

    try {
        /* A pass that has moved beyond draft is not editable from a phone.
           The desktop says the same thing about posted and reversed; this
           goes further on purpose — once the office has verified a pass,
           the phone stops being the right place to change it. */
        if ($id > 0) {
            $s = db()->prepare("SELECT status FROM inv_gate WHERE id=?");
            $s->execute([$id]);
            $st = (string)($s->fetchColumn() ?: '');
            if ($st === '') { $_SESSION['error'] = 'That pass no longer exists.'; redirect('m_gate.php?dir=' . $dir); }
            if ($st !== 'draft') {
                $_SESSION['error'] = 'This pass is ' . $st . ' and can only be changed on the desktop.';
                redirect('m_gate.php?dir=' . $dir);
            }
        }

        db()->beginTransaction();

        $own = $TYPES[$type]['own'] ?? 'own';
        if ($id > 0) {
            db()->prepare("UPDATE inv_gate SET txn_type=?, gate_date=?, gate_time=?, party_text=?,
                           vehicle_no=?, remarks=?, updated_at=NOW() WHERE id=?")
                ->execute([$type, $date, date('H:i:s'),
                           mb_substr(trim((string)($_POST['party_text'] ?? '')), 0, 190) ?: null,
                           mb_substr(trim((string)($_POST['vehicle_no'] ?? '')), 0, 60) ?: null,
                           mb_substr(trim((string)($_POST['remarks'] ?? '')), 0, 2000) ?: null,
                           $id]);
        } else {
            $no  = inv_next_no($dir === 'in' ? 'prefix_gate_in' : 'prefix_gate_out', 'inv_gate', 'gate_no');
            $uid = (int)(current_user()['id'] ?? 0);
            db()->prepare("INSERT INTO inv_gate
                   (gate_no, direction, txn_type, gate_date, gate_time, party_text, vehicle_no,
                    remarks, status, prepared_by, created_by)
                   VALUES (?,?,?,?,?,?,?,?,'draft',?,?)")
                ->execute([$no, $dir, $type, $date, date('H:i:s'),
                           mb_substr(trim((string)($_POST['party_text'] ?? '')), 0, 190) ?: null,
                           mb_substr(trim((string)($_POST['vehicle_no'] ?? '')), 0, 60) ?: null,
                           mb_substr(trim((string)($_POST['remarks'] ?? '')), 0, 2000) ?: null,
                           $uid, $uid]);
            $id = (int)db()->lastInsertId();
        }

        /* Rewritten whole, the same way the desktop does it, so a line
           removed on the phone is really gone. Rate is left at its default
           of 0 — that is what "rate pending" means, and posting refuses it. */
        db()->prepare("DELETE FROM inv_gate_items WHERE gate_id=?")->execute([$id]);
        $ins = db()->prepare("INSERT INTO inv_gate_items
               (gate_id, material_id, product_id, description, qty, uom, rate, ownership, sort_order)
               VALUES (?,?,?,?,?,?,0,?,?)");
        foreach ($lines as $k => $l) {
            $ins->execute([$id, $l['material_id'], $l['product_id'], $l['description'],
                           $l['qty'], $l['uom'], $own, $k + 1]);
        }

        db()->commit();
        try { audit_log(0, 'Gate (mobile)', $id > 0 ? 'save' : 'create', '', 'Draft ' . $dirName,
                        count($lines) . ' line(s), saved from a phone'); } catch (Throwable $e) {}
        $_SESSION['flash'] = 'Saved as a draft. The office will add rates and post it.';
        redirect('m_gate.php?dir=' . $dir);

    } catch (Throwable $e) {
        try { if (db()->inTransaction()) db()->rollBack(); } catch (Throwable $e2) {}
        $_SESSION['error'] = 'Nothing was saved. Please try again.';
        redirect('m_gate.php?dir=' . $dir . '&new=1');
    }
}

/* ------------------------------------------------------------------ read */
$editId = (int)($_GET['id'] ?? 0);
$isNew  = isset($_GET['new']) || $editId > 0;
$pass   = null;
$pLines = [];

if ($editId > 0) {
    try {
        $s = db()->prepare("SELECT * FROM inv_gate WHERE id=? AND direction=?");
        $s->execute([$editId, $dir]);
        $pass = $s->fetch() ?: null;
        if ($pass && $pass['status'] !== 'draft') { $pass = null; $editId = 0; $isNew = false; }
        if ($pass) {
            $s2 = db()->prepare("SELECT * FROM inv_gate_items WHERE gate_id=? ORDER BY sort_order, id");
            $s2->execute([$editId]);
            $pLines = $s2->fetchAll();
        } else { $editId = 0; }
    } catch (Throwable $e) { $pass = null; $editId = 0; }
}

/* The drafts this screen can still work on. Capped, and recent first —
   a phone list is for finding what you raised an hour ago, not for
   browsing a year. */
$drafts = [];
try {
    $s = db()->prepare("SELECT g.id, g.gate_no, g.txn_type, g.gate_date, g.party_text, g.vehicle_no,
                               (SELECT COUNT(*) FROM inv_gate_items i WHERE i.gate_id=g.id) lines,
                               (SELECT COUNT(*) FROM inv_gate_items i WHERE i.gate_id=g.id AND i.rate<=0) norate
                          FROM inv_gate g
                         WHERE g.direction=? AND g.status='draft'
                         ORDER BY g.id DESC LIMIT 15");
    $s->execute([$dir]);
    $drafts = $s->fetchAll();
} catch (Throwable $e) { $drafts = []; }

$items = [];
try { $items = inv_opening_items(); } catch (Throwable $e) { $items = []; }

$partyNames = [];
try { foreach (inv_parties('', true) as $p) $partyNames[] = (string)$p['name']; } catch (Throwable $e) {}

/* ---------------------------------------------------------------- render */
mob_header($dirName, $isNew ? 'm_gate.php?dir=' . $dir : '',
           $isNew ? ($editId > 0 ? 'Editing ' . (string)$pass['gate_no'] : 'New draft') : 'Draft entry');
mob_flash();
?>

<?php if (!$isNew): ?>

  <div class="mcard" style="display:flex;gap:10px">
    <a class="btn <?= $dir === 'in' ? 'go' : 'sec' ?>" href="m_gate.php?dir=in">Gate In</a>
    <a class="btn <?= $dir === 'out' ? 'go' : 'sec' ?>" href="m_gate.php?dir=out">Gate Out</a>
  </div>

  <a class="btn go" href="m_gate.php?dir=<?= e($dir) ?>&new=1" style="margin-bottom:14px">+ New <?= e($dirName) ?></a>

  <div class="mcard">
    <h2>Open drafts</h2>
    <?php if (!$drafts): ?>
      <div class="empty">No draft <?= $dir === 'in' ? 'inward' : 'outward' ?> passes.<br>Tap the button above to raise one.</div>
    <?php else: ?>
      <?php foreach ($drafts as $d): ?>
        <a href="m_gate.php?dir=<?= e($dir) ?>&id=<?= (int)$d['id'] ?>"
           style="display:block;padding:12px 0;border-top:1px solid var(--line);text-decoration:none;color:inherit">
          <div style="display:flex;justify-content:space-between;gap:10px;align-items:center">
            <b style="color:var(--cyan)"><?= e((string)$d['gate_no']) ?></b>
            <span>
              <?php if ((int)$d['norate'] > 0): ?><span class="pill w">rate pending</span><?php endif; ?>
              <span class="pill d">draft</span>
            </span>
          </div>
          <div class="note" style="margin-top:3px">
            <?= e($TYPES[$d['txn_type']]['label'] ?? (string)$d['txn_type']) ?>
            &middot; <?= e(date('d M', strtotime((string)$d['gate_date']))) ?>
            &middot; <?= (int)$d['lines'] ?> item<?= (int)$d['lines'] === 1 ? '' : 's' ?>
            <?php if (trim((string)$d['party_text']) !== ''): ?><br><?= e((string)$d['party_text']) ?><?php endif; ?>
            <?php if (trim((string)$d['vehicle_no']) !== ''): ?> &middot; <?= e((string)$d['vehicle_no']) ?><?php endif; ?>
          </div>
        </a>
      <?php endforeach; ?>
    <?php endif; ?>
  </div>

  <div class="mcard">
    <div class="note">
      This screen records <b>what came in or went out</b>. It saves a draft — the office adds the
      rates and posts it to stock from the desktop. Nothing here changes stock by itself.
    </div>
  </div>

<?php else: ?>

  <form method="post" id="gf">
    <?= csrf_field() ?>
    <input type="hidden" name="action" value="save">
    <input type="hidden" name="dir" value="<?= e($dir) ?>">
    <input type="hidden" name="id" value="<?= (int)$editId ?>">

    <div class="mcard">
      <label class="f"><span>Type <i class="req">*</i></span>
        <select class="in" name="txn_type" required>
          <?php foreach ($TYPES as $k => $t): ?>
            <option value="<?= e($k) ?>" <?= ($pass['txn_type'] ?? '') === $k ? 'selected' : '' ?>><?= e($t['label']) ?></option>
          <?php endforeach; ?>
        </select>
      </label>

      <label class="f"><span>Date <i class="req">*</i></span>
        <input class="in" type="date" name="gate_date" required max="<?= date('Y-m-d') ?>"
               value="<?= e((string)($pass['gate_date'] ?? date('Y-m-d'))) ?>">
      </label>

      <label class="f"><span>Party — supplier, customer or job worker</span>
        <input class="in" name="party_text" list="partylist" autocomplete="off" placeholder="Type a name"
               value="<?= e((string)($pass['party_text'] ?? '')) ?>">
      </label>
      <datalist id="partylist">
        <?php foreach ($partyNames as $pn): ?><option value="<?= e($pn) ?>"></option><?php endforeach; ?>
      </datalist>

      <label class="f" style="margin-bottom:0"><span>Vehicle no.</span>
        <input class="in" name="vehicle_no" autocomplete="off" placeholder="e.g. LES-4471"
               value="<?= e((string)($pass['vehicle_no'] ?? '')) ?>">
      </label>
    </div>

    <div class="mcard">
      <h2>Items</h2>
      <div id="rows"></div>
      <button type="button" class="btn sec" id="addrow" style="margin-top:14px">+ Add item</button>
      <div class="note" style="margin-top:10px">
        No rate here. The office adds rates before posting — the pass shows <b>rate pending</b> until they do.
      </div>
    </div>

    <div class="mcard">
      <label class="f" style="margin-bottom:0"><span>Remarks</span>
        <textarea class="in" name="remarks" placeholder="Anything worth recording"><?= e((string)($pass['remarks'] ?? '')) ?></textarea>
      </label>
    </div>

    <button class="btn go" type="submit">Save as Draft</button>
    <a class="btn sec" href="m_gate.php?dir=<?= e($dir) ?>" style="margin-top:10px">Cancel</a>
  </form>

  <script>
  /* The item rows. Built in the browser from one list sent with the page —
     no second request, because the gate is exactly where the signal is
     worst and a picker that needs its own round trip is a picker that
     sometimes does not open. */
  var ITEMS = <?= json_encode(array_map(fn($i) => [
        'k' => $i['key'], 'n' => $i['code'] . ' — ' . $i['name'], 'u' => $i['uom'] ?? '',
      ], $items), JSON_UNESCAPED_UNICODE) ?>;
  var START = <?= json_encode(array_map(fn($l) => [
        'k' => ((int)$l['material_id'] > 0 ? 'm' . (int)$l['material_id'] : 'p' . (int)$l['product_id']),
        'q' => rtrim(rtrim(number_format((float)$l['qty'], 3, '.', ''), '0'), '.'),
        'u' => (string)($l['uom'] ?? ''),
        'd' => (string)($l['description'] ?? ''),
      ], $pLines), JSON_UNESCAPED_UNICODE) ?>;

  var rows = document.getElementById('rows');
  var n = 0;

  function optionsHtml(sel) {
    var h = '<option value="">— choose an item —</option>';
    for (var i = 0; i < ITEMS.length; i++) {
      var it = ITEMS[i];
      h += '<option value="' + it.k + '" data-u="' + (it.u || '') + '"'
         + (it.k === sel ? ' selected' : '') + '>' + it.n.replace(/</g, '&lt;') + '</option>';
    }
    return h;
  }

  function addRow(v) {
    v = v || {};
    var i = n++;
    var d = document.createElement('div');
    d.className = 'grow';
    d.style.cssText = 'border-top:1px solid var(--line);padding-top:12px;margin-top:12px';
    d.innerHTML =
      '<label class="f"><span>Item <i class="req">*</i></span>'
    + '<select class="in itm" name="line[' + i + '][item_key]">' + optionsHtml(v.k) + '</select></label>'
    + '<div style="display:flex;gap:10px">'
    + '  <label class="f" style="flex:1.3"><span>Quantity <i class="req">*</i></span>'
    + '    <input class="in qty" type="number" inputmode="decimal" step="0.001" min="0" name="line[' + i + '][qty]" value="' + (v.q || '') + '"></label>'
    + '  <label class="f" style="flex:1"><span>Unit</span>'
    + '    <input class="in uom" name="line[' + i + '][uom]" value="' + (v.u || '') + '" placeholder="Pc"></label>'
    + '</div>'
    + '<input type="hidden" name="line[' + i + '][desc]" value="">'
    + '<button type="button" class="btn red rm" style="padding:10px;min-height:44px;font-size:14px">Remove this item</button>';
    rows.appendChild(d);
    syncRemove();

    var sel = d.querySelector('.itm'), uom = d.querySelector('.uom'), desc = d.querySelector('input[name$="[desc]"]');
    function fill() {
      var o = sel.options[sel.selectedIndex];
      if (!o || !o.value) { desc.value = ''; return; }
      desc.value = o.textContent;
      /* The unit follows the item unless somebody has typed their own. */
      if (!uom.dataset.touched && o.dataset.u) uom.value = o.dataset.u;
    }
    uom.addEventListener('input', function () { uom.dataset.touched = '1'; });
    sel.addEventListener('change', fill);
    if (v.k) fill();
    d.querySelector('.rm').addEventListener('click', function () {
      d.remove();
      if (!rows.children.length) addRow();   /* never leave an unusable form */
      syncRemove();
    });
  }

  /* Remove is pointless on the only row — taking it away just puts an empty
     one back. Hidden rather than removed so the row markup stays identical. */
  function syncRemove() {
    var all = rows.querySelectorAll('.grow');
    all.forEach(function (r) {
      r.querySelector('.rm').style.display = all.length > 1 ? '' : 'none';
    });
  }

  document.getElementById('addrow').addEventListener('click', function () { addRow(); });
  if (START.length) { START.forEach(addRow); } else { addRow(); }

  /* Caught here so the answer is instant, and caught again on the server
     because a browser check is a convenience, never a guard. */
  document.getElementById('gf').addEventListener('submit', function (e) {
    var ok = false, half = 0;
    rows.querySelectorAll('.grow').forEach(function (r) {
      var k = r.querySelector('.itm').value, q = parseFloat(r.querySelector('.qty').value || '0');
      if (k && q > 0) ok = true;
      if (k && !(q > 0)) half++;
    });
    if (half) { e.preventDefault(); alert('An item has no quantity. Fill it in, or remove that item.'); return; }
    if (!ok)  { e.preventDefault(); alert('Add at least one item with a quantity.'); }
  });
  </script>

<?php endif; ?>

<?php mob_footer(); ?>
