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
/* storage.php so a photo goes to R2 when R2 is configured. Without it
   exp_r2_configured() would be undefined and every photo would quietly
   land on this server's disk instead. */
require_once __DIR__ . '/includes/storage.php';
require_once __DIR__ . '/includes/mobile.php';
require_login();

if (!inv_perm('gate')) {
    http_response_code(403);
    mob_header('Gate');
    echo '<div class="flash no">You do not have permission to create gate passes. Ask an admin for Gate access.</div>';
    mob_footer();
    exit;
}

$dirForRecent = ($_GET['dir'] ?? '') === 'out' ? 'out' : 'in';

/* ------------------------------------------------------- small answers

   Two things are fetched rather than sent with the page: a contract's
   lines, and what this party has brought before. Both depend on a choice
   made after the page loaded, and preloading every contract's lines for
   every party would be a megabyte to carry a few rows. */
if (($_GET['ajax'] ?? '') !== '') {
    header('Content-Type: application/json');

    if ($_GET['ajax'] === 'contract') {
        $cid = (int)($_GET['id'] ?? 0);
        $out = ['ok' => false, 'lines' => []];
        if ($cid > 0) {
            foreach (inv_contract_lines($cid) as $l) {
                /* A line already fully delivered is no use at a gate. */
                if ((float)$l['balance'] <= 0) continue;
                $out['lines'][] = [
                    'id'   => (int)$l['id'],
                    'k'    => (int)$l['material_id'] > 0 ? 'm' . (int)$l['material_id'] : 'p' . (int)$l['product_id'],
                    'n'    => (string)$l['item'],
                    'u'    => (string)$l['uom'],
                    'r'    => (float)$l['rate'],
                    'bal'  => (float)$l['balance'],
                    'qty'  => (float)$l['qty'],
                    'done' => (float)$l['done'],
                ];
            }
            $out['ok'] = true;
        }
        echo json_encode($out);
        exit;
    }

    /* WHAT THIS PARTY HAS BROUGHT BEFORE.
       The whole point of the request: a supplier delivers the same four
       things every week, and hunting for them in a list of nine hundred on
       a phone is the slow part. Read from what was actually recorded, so
       the list is right without anybody maintaining it. */
    if ($_GET['ajax'] === 'recent') {
        $party = trim((string)($_GET['party'] ?? ''));
        $out = ['ok' => true, 'keys' => []];
        if ($party !== '') {
            try {
                $st = db()->prepare(
                    "SELECT gi.material_id, gi.product_id, COUNT(*) n, MAX(g.id) last_id
                       FROM inv_gate_items gi
                       JOIN inv_gate g ON g.id = gi.gate_id
                      WHERE g.party_text = ? AND g.direction = ?
                        AND (gi.material_id IS NOT NULL OR gi.product_id IS NOT NULL)
                   GROUP BY gi.material_id, gi.product_id
                   ORDER BY n DESC, last_id DESC
                      LIMIT 12");
                $st->execute([$party, $dirForRecent]);
                foreach ($st->fetchAll() as $r) {
                    $out['keys'][] = (int)$r['material_id'] > 0
                        ? 'm' . (int)$r['material_id'] : 'p' . (int)$r['product_id'];
                }
            } catch (Throwable $e) {}
        }
        echo json_encode($out);
        exit;
    }

    echo json_encode(['ok' => false]);
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
    $cid  = (int)($_POST['contract_id'] ?? 0);

    /* The contract must be one this type can actually be raised against,
       and it must be open. Checked on the server because the value came
       from a browser. */
    if ($cid > 0) {
        $want = (string)($TYPES[$type]['contract'] ?? '');
        try {
            $cs = db()->prepare("SELECT contract_type, status FROM inv_contracts WHERE id=?");
            $cs->execute([$cid]);
            $crow = $cs->fetch();
        } catch (Throwable $e) { $crow = null; }
        if (!$crow || $want === '' || (string)$crow['contract_type'] !== $want
            || !in_array((string)$crow['status'], ['active', 'draft'], true)) {
            $cid = 0;
        }
    }

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
            /* A LINE TAKEN FROM A CONTRACT CARRIES THE CONTRACT WITH IT.
             *
             * This is the whole reason the contract button exists.
             * inv_contract_lines() counts progress from gate lines whose
             * contract_item_id is set — a line without one counts against
             * nothing, so the contract reads as undelivered for ever and
             * you would over-deliver against it with the screen saying it
             * was fine. Carrying both ids is what makes the delivery
             * count.
             *
             * The rate comes with it too, which is why a pass built from a
             * contract needs nothing from the office before posting. */
            $cItem = (int)($ln['citem'] ?? 0);
            $cRate = inv_num($ln['crate'] ?? 0);
            $lines[] = [
                'material_id' => $mid ?: null,
                'product_id'  => $pid ?: null,
                'description' => mb_substr(trim((string)($ln['desc'] ?? '')), 0, 300),
                'qty'         => $qty,
                'uom'         => mb_substr(trim((string)($ln['uom'] ?? '')), 0, 20),
                'citem'       => $cItem > 0 ? $cItem : null,
                'rate'        => $cItem > 0 && $cRate > 0 ? $cRate : 0.0,
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
                           vehicle_no=?, remarks=?, contract_id=?, updated_at=NOW() WHERE id=?")
                ->execute([$type, $date, date('H:i:s'),
                           mb_substr(trim((string)($_POST['party_text'] ?? '')), 0, 190) ?: null,
                           mb_substr(trim((string)($_POST['vehicle_no'] ?? '')), 0, 60) ?: null,
                           mb_substr(trim((string)($_POST['remarks'] ?? '')), 0, 2000) ?: null,
                           $cid ?: null, $id]);
        } else {
            $no  = inv_next_no($dir === 'in' ? 'prefix_gate_in' : 'prefix_gate_out', 'inv_gate', 'gate_no');
            $uid = (int)(current_user()['id'] ?? 0);
            db()->prepare("INSERT INTO inv_gate
                   (gate_no, direction, txn_type, gate_date, gate_time, party_text, vehicle_no,
                    remarks, contract_id, status, prepared_by, created_by)
                   VALUES (?,?,?,?,?,?,?,?,?,'draft',?,?)")
                ->execute([$no, $dir, $type, $date, date('H:i:s'),
                           mb_substr(trim((string)($_POST['party_text'] ?? '')), 0, 190) ?: null,
                           mb_substr(trim((string)($_POST['vehicle_no'] ?? '')), 0, 60) ?: null,
                           mb_substr(trim((string)($_POST['remarks'] ?? '')), 0, 2000) ?: null,
                           $cid ?: null, $uid, $uid]);
            $id = (int)db()->lastInsertId();
        }

        /* Rewritten whole, the same way the desktop does it, so a line
           removed on the phone is really gone. Rate is left at its default
           of 0 — that is what "rate pending" means, and posting refuses it. */
        db()->prepare("DELETE FROM inv_gate_items WHERE gate_id=?")->execute([$id]);
        $ins = db()->prepare("INSERT INTO inv_gate_items
               (gate_id, material_id, product_id, description, qty, uom, rate, amount,
                ownership, sort_order, contract_id, contract_item_id)
               VALUES (?,?,?,?,?,?,?,?,?,?,?,?)");
        foreach ($lines as $k => $l) {
            /* rate stays 0 unless the contract supplied one — that is what
               "rate pending" means, and posting refuses it. */
            $ins->execute([$id, $l['material_id'], $l['product_id'], $l['description'],
                           $l['qty'], $l['uom'], $l['rate'], round($l['qty'] * $l['rate'], 2),
                           $own, $k + 1,
                           $l['citem'] ? $cid : null, $l['citem']]);
        }

        db()->commit();

        /* AFTER the commit, deliberately. A photo that will not store must
           not take the gate pass down with it — the pass is the record that
           matters, the photo is evidence attached to it. Anything that goes
           wrong is reported on the next screen, with the pass already safe. */
        $photoMsg = '';
        foreach (mob_photo_files('photo') as $one) {
            [$pok, $pmsg] = mob_photo_store($id, $one, (string)($_POST['vehicle_no'] ?? ''));
            if (!$pok && $pmsg !== '') $photoMsg = $pmsg;
        }

        try { audit_log(0, 'Gate (mobile)', $id > 0 ? 'save' : 'create', '', 'Draft ' . $dirName,
                        count($lines) . ' line(s), saved from a phone'); } catch (Throwable $e) {}
        $_SESSION['flash'] = 'Saved as a draft. The office will add rates and post it.';
        if ($photoMsg !== '') $_SESSION['error'] = 'The pass was saved, but the photo was not: ' . $photoMsg;
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
                               (SELECT COUNT(*) FROM inv_gate_items i WHERE i.gate_id=g.id AND i.rate<=0) norate,
                               (SELECT COUNT(*) FROM inv_gate_photos ph WHERE ph.gate_id=g.id) photos
                          FROM inv_gate g
                         WHERE g.direction=? AND g.status='draft'
                         ORDER BY g.id DESC LIMIT 15");
    $s->execute([$dir]);
    $drafts = $s->fetchAll();
} catch (Throwable $e) { $drafts = []; }

$items = [];
try { $items = inv_opening_items(); } catch (Throwable $e) { $items = []; }

/* Open contracts, grouped by the contract type each gate type maps to.
   Only the header — the lines are fetched when one is chosen. */
$contracts = [];
try {
    $wantTypes = [];
    foreach ($TYPES as $k => $t) if (($t['contract'] ?? '') !== '') $wantTypes[$t['contract']] = true;
    if ($wantTypes) {
        $in = implode(',', array_fill(0, count($wantTypes), '?'));
        $cs = db()->prepare("SELECT c.id, c.contract_no, c.contract_type, c.contract_date, p.name party
                               FROM inv_contracts c
                          LEFT JOIN inv_parties p ON p.id = c.party_id
                              WHERE c.contract_type IN ($in) AND c.status IN ('active','draft')
                           ORDER BY c.id DESC LIMIT 120");
        $cs->execute(array_keys($wantTypes));
        $contracts = $cs->fetchAll();
    }
} catch (Throwable $e) { $contracts = []; }

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
            <?php if ((int)$d['photos'] > 0): ?>&middot; <?= (int)$d['photos'] ?> photo<?= (int)$d['photos'] === 1 ? '' : 's' ?><?php endif; ?>
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

  <form method="post" id="gf" enctype="multipart/form-data">
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

    <?php
      /* Which contract types this screen's types map to, so the list can
         be narrowed the moment a type is chosen rather than offering a
         purchase contract on a sale. */
      $typeContract = [];
      foreach ($TYPES as $k => $t) $typeContract[$k] = (string)($t['contract'] ?? '');
    ?>
    <div class="mcard" id="ccard">
      <div style="display:flex;justify-content:space-between;align-items:center;gap:10px">
        <h2 style="margin:0">Against a contract?</h2>
        <button type="button" id="ctoggle" class="btn sec" style="width:auto;padding:9px 16px;min-height:44px;font-size:14px">Choose</button>
      </div>
      <input type="hidden" name="contract_id" id="cid" value="<?= (int)($pass['contract_id'] ?? 0) ?>">
      <div id="cpick" hidden style="margin-top:12px">
        <input class="in" id="csearch" placeholder="Search contract no. or party" autocomplete="off">
        <div id="clist" style="max-height:260px;overflow:auto;margin-top:10px"></div>
      </div>
      <div id="cchosen" hidden style="margin-top:12px"></div>
      <div class="note" style="margin-top:10px" id="cnote">
        Pick the contract and its items come with their <b>rates</b> and what is still outstanding —
        and the delivery counts against the contract. Leave it off for a one-off.
      </div>
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
      <h2>Photo of the challan</h2>
      <?php $existing = $editId > 0 ? mob_photos($editId) : []; ?>
      <?php if ($existing): ?>
        <div style="display:flex;gap:8px;flex-wrap:wrap;margin-bottom:12px">
          <?php foreach ($existing as $ph): ?>
            <a href="m_gate_photo.php?id=<?= (int)$ph['id'] ?>" target="_blank" rel="noopener">
              <img src="m_gate_photo.php?id=<?= (int)$ph['id'] ?>" alt="Gate photo" loading="lazy"
                   style="width:84px;height:84px;object-fit:cover;border-radius:10px;border:1px solid var(--line)">
            </a>
          <?php endforeach; ?>
        </div>
      <?php endif; ?>
      <label class="f" style="margin-bottom:0">
        <span>Take a photo<?= $existing ? ' — adds to the ' . count($existing) . ' already attached' : '' ?></span>
        <!-- capture="environment" opens the back camera straight away on a
             phone. On a desktop the same control is an ordinary file picker,
             so the office can attach a scan without a second screen. -->
        <input class="in" type="file" name="photo[]" accept="image/*" capture="environment" multiple>
      </label>
      <div class="note" style="margin-top:8px">
        The challan, the truck, a damaged carton. Taken now, while the vehicle is still here —
        which is the only moment it can be taken at all.
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

  <!-- The item picker. A full screen of its own, because a 900-row native
       dropdown on a phone is a scroll with no search in it. -->
  <div id="pick" hidden style="position:fixed;inset:0;z-index:60;background:var(--bg);display:flex;flex-direction:column">
    <div class="mh" style="position:static">
      <a class="bk" href="#" id="pclose" aria-label="Close">&#8249;</a>
      <h1>Choose an item</h1>
    </div>
    <div style="padding:12px 14px 0">
      <input class="in" id="psearch" placeholder="Type any part of the code or name" autocomplete="off"
             autocapitalize="off" autocorrect="off" spellcheck="false">
    </div>
    <div id="plist" style="flex:1;overflow:auto;padding:10px 14px calc(16px + var(--safe-b))"></div>
  </div>

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
        'ci' => (int)($l['contract_item_id'] ?? 0),
        'cr' => (float)($l['rate'] ?? 0),
      ], $pLines), JSON_UNESCAPED_UNICODE) ?>;
  var CONTRACTS = <?= json_encode(array_map(fn($c) => [
        'id' => (int)$c['id'], 'no' => (string)$c['contract_no'],
        't' => (string)$c['contract_type'], 'p' => (string)($c['party'] ?? ''),
        'd' => (string)($c['contract_date'] ?? ''),
      ], $contracts), JSON_UNESCAPED_UNICODE) ?>;
  var TYPEC = <?= json_encode($typeContract, JSON_UNESCAPED_UNICODE) ?>;
  var DIR = <?= json_encode($dir) ?>;

  var rows = document.getElementById('rows');
  var BYKEY = {}; ITEMS.forEach(function (i) { BYKEY[i.k] = i; });
  var n = 0;
  var recent = [];          /* item keys this party has brought before */
  var activeRow = null;     /* the row whose picker is open */

  /* ---------------------------------------------------------- searching
     Three ways to match, best first:
       1. the whole phrase appears                 "flat sheet"
       2. every word appears somewhere, any order  "sheet 300"
       3. the letters appear in order              "mnfb" -> MaiN FaBric
     The third is what makes it forgiving of how people actually type on a
     phone, without pretending to be a spell checker. */
  function norm(s) { return (s || '').toLowerCase().replace(/[^a-z0-9]+/g, ' ').trim(); }

  function subseq(hay, needle) {
    var i = 0;
    for (var j = 0; j < hay.length && i < needle.length; j++) if (hay[j] === needle[i]) i++;
    return i === needle.length;
  }

  function score(item, q) {
    if (!q) return 1;
    var h = norm(item.n);
    if (h.indexOf(q) !== -1) return 100 - h.indexOf(q);
    var words = q.split(' ').filter(Boolean);
    if (words.length && words.every(function (w) { return h.indexOf(w) !== -1; })) return 50;
    if (subseq(h.replace(/ /g, ''), q.replace(/ /g, ''))) return 10;
    return 0;
  }

  function drawList() {
    var q = norm(document.getElementById('psearch').value);
    var box = document.getElementById('plist');
    var html = '';

    if (!q && recent.length) {
      html += section('Used before with this party', recent.map(function (k) { return BYKEY[k]; }).filter(Boolean));
    }

    var hits = ITEMS.map(function (i) { return { i: i, s: score(i, q) }; })
                    .filter(function (x) { return x.s > 0; })
                    .sort(function (a, b) { return b.s - a.s; })
                    .slice(0, 80)
                    .map(function (x) { return x.i; });

    html += section(q ? (hits.length + ' match' + (hits.length === 1 ? '' : 'es')) : 'All items', hits);
    if (!hits.length) html += '<div class="empty">Nothing matches &ldquo;' + esc(document.getElementById('psearch').value) + '&rdquo;.</div>';
    box.innerHTML = html;
  }

  function esc(t) { return String(t).replace(/[&<>"]/g, function (c) {
    return ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;' })[c]; }); }

  function section(title, list) {
    if (!list.length) return '';
    var h = '<div style="font-size:10.5px;text-transform:uppercase;letter-spacing:.06em;color:var(--faint);font-weight:700;margin:12px 0 4px">' + esc(title) + '</div>';
    list.forEach(function (i) {
      h += '<button type="button" class="pitem" data-k="' + esc(i.k) + '" data-u="' + esc(i.u || '') + '"'
         + ' style="display:block;width:100%;text-align:left;padding:13px 12px;border:1px solid var(--line);'
         + 'border-radius:11px;background:#fff;margin-bottom:7px;min-height:48px;color:var(--ink)">'
         + esc(i.n) + (i.u ? '<span style="color:var(--faint);font-size:12px"> &middot; ' + esc(i.u) + '</span>' : '')
         + '</button>';
    });
    return h;
  }

  function openPicker(row) {
    activeRow = row;
    document.getElementById('psearch').value = '';
    document.getElementById('pick').hidden = false;
    drawList();
    /* Not focused on purpose: the keyboard springing up over the list is
       the thing people complain about. Tap the box to search. */
    loadRecent();
  }
  function closePicker() { document.getElementById('pick').hidden = true; activeRow = null; }

  /* What this party brought before. Asked for once per party, after the
     name has been typed — which is why it is a request and not baked into
     the page. */
  var recentFor = null;
  function loadRecent() {
    var party = (document.querySelector('[name="party_text"]').value || '').trim();
    if (party === '' || party === recentFor) { return; }
    recentFor = party;
    fetch('m_gate.php?ajax=recent&dir=' + encodeURIComponent(DIR) + '&party=' + encodeURIComponent(party))
      .then(function (r) { return r.json(); })
      .then(function (j) { recent = (j && j.keys) || []; drawList(); })
      .catch(function () { recent = []; });
  }

  document.getElementById('psearch').addEventListener('input', drawList);
  document.getElementById('pclose').addEventListener('click', function (e) { e.preventDefault(); closePicker(); });
  document.getElementById('plist').addEventListener('click', function (e) {
    var b = e.target.closest('.pitem');
    if (!b || !activeRow) return;
    setItem(activeRow, b.dataset.k, b.dataset.u);
    closePicker();
  });
  document.addEventListener('keydown', function (e) {
    if (e.key === 'Escape' && !document.getElementById('pick').hidden) closePicker();
  });

  /* ------------------------------------------------------------- rows */
  function setItem(row, key, uom) {
    var it = BYKEY[key];
    row.querySelector('.k').value = key || '';
    row.querySelector('.d').value = it ? it.n : '';
    row.querySelector('.lbl').textContent = it ? it.n : '— choose an item —';
    row.querySelector('.lbl').style.color = it ? 'var(--ink)' : 'var(--faint)';
    var u = row.querySelector('.uom');
    if (!u.dataset.touched && (uom || (it && it.u))) u.value = uom || it.u;
  }

  function addRow(v) {
    v = v || {};
    var i = n++;
    var d = document.createElement('div');
    d.className = 'grow';
    d.style.cssText = 'border-top:1px solid var(--line);padding-top:12px;margin-top:12px';
    d.innerHTML =
      '<label class="f"><span>Item <i class="req">*</i></span>'
    + '<button type="button" class="in itm" style="text-align:left;min-height:50px">'
    + '<span class="lbl" style="color:var(--faint)">— choose an item —</span></button></label>'
    + '<input type="hidden" class="k" name="line[' + i + '][item_key]" value="">'
    + '<input type="hidden" class="d" name="line[' + i + '][desc]" value="">'
    + '<input type="hidden" class="ci" name="line[' + i + '][citem]" value="' + (v.ci || '') + '">'
    + '<input type="hidden" class="cr" name="line[' + i + '][crate]" value="' + (v.cr || '') + '">'
    + '<div style="display:flex;gap:10px">'
    + '  <label class="f" style="flex:1.3"><span>Quantity <i class="req">*</i></span>'
    + '    <input class="in qty" type="number" inputmode="decimal" step="0.001" min="0" name="line[' + i + '][qty]" value="' + (v.q || '') + '"></label>'
    + '  <label class="f" style="flex:1"><span>Unit</span>'
    + '    <input class="in uom" name="line[' + i + '][uom]" value="' + (v.u || '') + '" placeholder="Pc"></label>'
    + '</div>'
    + (v.ci ? '<div class="note" style="margin:-4px 0 10px">From the contract &middot; rate ' + (v.cr || 0) + (v.bal !== undefined ? ' &middot; ' + v.bal + ' outstanding' : '') + '</div>' : '')
    + '<button type="button" class="btn red rm" style="padding:10px;min-height:44px;font-size:14px">Remove this item</button>';
    rows.appendChild(d);

    var uom = d.querySelector('.uom');
    uom.addEventListener('input', function () { uom.dataset.touched = '1'; });
    d.querySelector('.itm').addEventListener('click', function () { openPicker(d); });
    if (v.k) setItem(d, v.k, v.u);
    d.querySelector('.rm').addEventListener('click', function () {
      d.remove();
      if (!rows.children.length) addRow();
      syncRemove();
    });
    syncRemove();
    return d;
  }

  function syncRemove() {
    var all = rows.querySelectorAll('.grow');
    all.forEach(function (r) {
      r.querySelector('.rm').style.display = all.length > 1 ? '' : 'none';
    });
  }

  document.getElementById('addrow').addEventListener('click', function () { addRow(); });
  if (START.length) { START.forEach(addRow); } else { addRow(); }

  /* --------------------------------------------------------- contracts */
  var typeSel = document.querySelector('[name="txn_type"]');
  var cidIn   = document.getElementById('cid');

  function openContracts() {
    var want = TYPEC[typeSel.value] || '';
    var box  = document.getElementById('clist');
    var q    = norm(document.getElementById('csearch').value);
    if (!want) {
      box.innerHTML = '<div class="empty">A ' + esc(typeSel.options[typeSel.selectedIndex].text)
                    + ' is not raised against a contract.</div>';
      return;
    }
    var list = CONTRACTS.filter(function (c) {
      if (c.t !== want) return false;
      if (!q) return true;
      return norm(c.no + ' ' + c.p).indexOf(q) !== -1;
    });
    if (!list.length) { box.innerHTML = '<div class="empty">No open contract matches.</div>'; return; }
    box.innerHTML = list.map(function (c) {
      return '<button type="button" class="citem" data-id="' + c.id + '" data-no="' + esc(c.no) + '"'
           + ' style="display:block;width:100%;text-align:left;padding:12px;border:1px solid var(--line);'
           + 'border-radius:11px;background:#fff;margin-bottom:7px;min-height:48px;color:var(--ink)">'
           + '<b>' + esc(c.no) + '</b>'
           + (c.p ? '<span style="color:var(--muted);font-size:12.5px"> &middot; ' + esc(c.p) + '</span>' : '')
           + (c.d ? '<div style="color:var(--faint);font-size:11.5px">' + esc(c.d) + '</div>' : '')
           + '</button>';
    }).join('');
  }

  document.getElementById('ctoggle').addEventListener('click', function () {
    var pick = document.getElementById('cpick');
    pick.hidden = !pick.hidden;
    this.textContent = pick.hidden ? 'Choose' : 'Close';
    if (!pick.hidden) openContracts();
  });
  document.getElementById('csearch').addEventListener('input', openContracts);
  typeSel.addEventListener('change', function () {
    if (!document.getElementById('cpick').hidden) openContracts();
  });

  document.getElementById('clist').addEventListener('click', function (e) {
    var b = e.target.closest('.citem');
    if (!b) return;
    var id = b.dataset.id;
    cidIn.value = id;
    document.getElementById('cpick').hidden = true;
    document.getElementById('ctoggle').textContent = 'Change';
    var chosen = document.getElementById('cchosen');
    chosen.hidden = false;
    chosen.innerHTML = '<div class="note">Loading the contract&rsquo;s items…</div>';

    fetch('m_gate.php?ajax=contract&id=' + encodeURIComponent(id))
      .then(function (r) { return r.json(); })
      .then(function (j) {
        var L = (j && j.lines) || [];
        if (!L.length) {
          chosen.innerHTML = '<div class="note"><b>' + esc(b.dataset.no) + '</b> — nothing outstanding on it.</div>';
          return;
        }
        chosen.innerHTML = '<div class="note" style="margin-bottom:8px"><b>' + esc(b.dataset.no)
          + '</b> — tick what is on the vehicle. Quantity starts at what is outstanding; change it if less came.</div>'
          + L.map(function (l, ix) {
              return '<label style="display:flex;gap:10px;align-items:flex-start;padding:10px;border:1px solid var(--line);'
                   + 'border-radius:11px;background:#fff;margin-bottom:7px">'
                   + '<input type="checkbox" class="cl" data-ix="' + ix + '" style="width:20px;height:20px;margin-top:2px;accent-color:#0ea8c9">'
                   + '<span style="flex:1;min-width:0"><b style="font-size:13.5px">' + esc(l.n) + '</b>'
                   + '<div style="color:var(--muted);font-size:12px">' + l.bal + ' ' + esc(l.u || '')
                   + ' outstanding &middot; rate ' + l.r + '</div></span></label>';
            }).join('')
          + '<button type="button" class="btn sec" id="caddsel">Add the ticked items</button>';

        document.getElementById('caddsel').addEventListener('click', function () {
          var added = 0;
          chosen.querySelectorAll('.cl:checked').forEach(function (cb) {
            var l = L[+cb.dataset.ix];
            /* An empty first row is filled rather than left behind. */
            var blank = Array.prototype.find.call(rows.querySelectorAll('.grow'),
                          function (r) { return !r.querySelector('.k').value; });
            var row = blank || addRow();
            setItem(row, l.k, l.u);
            row.querySelector('.qty').value = l.bal;
            row.querySelector('.ci').value  = l.id;
            row.querySelector('.cr').value  = l.r;
            if (!row.querySelector('.cfrom')) {
              var tag = document.createElement('div');
              tag.className = 'note cfrom';
              tag.style.cssText = 'margin:-4px 0 10px';
              tag.innerHTML = 'From the contract &middot; rate ' + l.r + ' &middot; ' + l.bal + ' outstanding';
              row.querySelector('.rm').before(tag);
            }
            cb.checked = false;
            added++;
          });
          syncRemove();
          if (added) document.getElementById('rows').scrollIntoView({ behavior: 'smooth', block: 'start' });
        });
      })
      .catch(function () {
        chosen.innerHTML = '<div class="note">The contract could not be loaded. Check the signal and try again.</div>';
      });
  });

  if (cidIn.value && cidIn.value !== '0') {
    var pre = CONTRACTS.filter(function (c) { return String(c.id) === String(cidIn.value); })[0];
    if (pre) {
      document.getElementById('ctoggle').textContent = 'Change';
      var ch = document.getElementById('cchosen');
      ch.hidden = false;
      ch.innerHTML = '<div class="note">Against contract <b>' + esc(pre.no) + '</b>'
                   + (pre.p ? ' &middot; ' + esc(pre.p) : '') + '</div>';
    }
  }

  /* Caught here so the answer is instant, and caught again on the server
     because a browser check is a convenience, never a guard. */
  document.getElementById('gf').addEventListener('submit', function (e) {
    var ok = false, half = 0;
    rows.querySelectorAll('.grow').forEach(function (r) {
      var k = r.querySelector('.k').value, q = parseFloat(r.querySelector('.qty').value || '0');
      if (k && q > 0) ok = true;
      if (k && !(q > 0)) half++;
    });
    if (half) { e.preventDefault(); alert('An item has no quantity. Fill it in, or remove that item.'); return; }
    if (!ok)  { e.preventDefault(); alert('Add at least one item with a quantity.'); }
  });
  </script>

<?php endif; ?>

<?php mob_footer(); ?>
